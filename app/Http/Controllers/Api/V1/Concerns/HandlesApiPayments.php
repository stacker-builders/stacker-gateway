<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\ApiApplication;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use App\Services\Api\ApiAuthContext;
use App\Services\ApiPixAccess;
use App\Services\CajuPay\CajuPayAccountResolver;
use App\Services\BuyerAccountService;
use App\Services\MerchantOperationalGuard;
use App\Services\MinimumChargeService;
use App\Services\Shipping\CheckoutShippingHelper;
use App\Services\SubscriptionRenewalService;
use App\Support\FakeConsumerData;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait HandlesApiPayments
{
    protected function resolvePaymentContext(Request $request, string $scope): ApiAuthContext
    {
        $ctx = ApiAuthContext::fromRequest($request);
        if (! $ctx->hasScope($scope)) {
            abort(403, 'Insufficient API key permissions.');
        }
        if (! ApiPixAccess::effectiveForTenant($ctx->application->tenant_id)) {
            abort(403, 'API PIX disabled for this tenant.');
        }
        MerchantOperationalGuard::assertCanAcceptPayments((int) $ctx->application->tenant_id);

        return $ctx;
    }

    /**
     * @return list<string|\Stringable>
     */
    protected function apiPaymentAmountRules(?int $tenantId = null): array
    {
        $min = app(MinimumChargeService::class)->apiPixMinimumBrlForTenant($tenantId);

        return ['required', 'numeric', 'min:'.$min];
    }

    /**
     * @return array{user: \App\Models\User, consumer: array{name: string, document: string, email: string}, cpf: ?string, phone: ?string}
     */
    protected function validateCustomerAndGetUser(Request $request, ApiApplication $app, ApiAuthContext $ctx): array
    {
        $validated = $request->validate([
            'customer' => ['required', 'array'],
            'customer.email' => ['required', 'email'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'customer.cpf' => ['nullable', 'string', 'max:14'],
            'customer.phone' => ['nullable', 'string', 'max:24'],
        ]);
        $customer = $validated['customer'];
        $email = $customer['email'];
        $name = trim((string) ($customer['name'] ?? ''));
        if ($name === '') {
            $name = $email;
        }
        $buyer = app(BuyerAccountService::class)->ensureBuyerFromCheckout(
            $email,
            $name,
            bcrypt(Str::random(32)),
            false,
        );
        $user = $buyer['user'];

        return [
            'user' => $user,
            'consumer' => [
                'name' => $name,
                'document' => preg_replace('/\D/', '', (string) ($customer['cpf'] ?? '')),
                'email' => $email,
            ],
            'cpf' => $customer['cpf'] ?? null,
            'phone' => $customer['phone'] ?? null,
        ];
    }

    /**
     * @return array{order: Order, product: Product|null, amount: float, consumer: array, gateway_config: array}
     */
    protected function createOrderForApi(
        Request $request,
        ApiApplication $app,
        float $amount,
        string $currency,
        ?string $productId,
        ?int $productOfferId,
        ?int $subscriptionPlanId,
        string $paymentMethod,
        array $metadata,
        array $userConsumer,
        ApiAuthContext $ctx,
    ): array {
        $tenantId = $app->tenant_id;
        $product = null;
        $productOfferId = $productOfferId ?: null;
        $subscriptionPlanId = $subscriptionPlanId ?: null;
        $orderAmount = $amount;
        $periodStart = null;
        $periodEnd = null;

        if ($productId !== null && $productId !== '') {
            $product = Product::where('id', $productId)->where('tenant_id', $tenantId)->first();
            if (! $product) {
                abort(422, 'Produto não encontrado.');
            }
            if (! $product->isAvailableForPurchase()) {
                abort(422, 'Produto indisponível para compra.');
            }
            $offer = $productOfferId ? ProductOffer::where('id', $productOfferId)->where('product_id', $product->id)->first() : null;
            $plan = $subscriptionPlanId ? SubscriptionPlan::where('id', $subscriptionPlanId)->where('product_id', $product->id)->first() : null;
            if ($offer) {
                $orderAmount = (float) $offer->price;
                $currency = $offer->getCurrencyOrDefault();
            } elseif ($plan) {
                $orderAmount = (float) $plan->price;
                $currency = $plan->getCurrencyOrDefault();
                [$periodStart, $periodEnd] = $plan->getCurrentPeriod();
            } else {
                $orderAmount = (float) $product->price;
                $currency = $product->currency ?? 'BRL';
            }
        }

        $rates = config('products.rates', ['brl_eur' => 0.16, 'brl_usd' => 0.18]);
        if ($currency !== 'BRL') {
            $orderAmount = $currency === 'EUR' ? $orderAmount / ($rates['brl_eur'] ?? 0.16) : $orderAmount / ($rates['brl_usd'] ?? 0.18);
        }

        $consumer = $userConsumer['consumer'];
        $useFakeData = $ctx->isLegacy() || $ctx->apiKey === null;
        if ($useFakeData) {
            $fake = FakeConsumerData::getForGateway(mt_rand(1, 999999));
            if (strlen($consumer['document'] ?? '') < 11) {
                $consumer['document'] = $fake['document'];
            }
            if (trim($consumer['name'] ?? '') === '') {
                $consumer['name'] = $fake['name'];
            }
        } elseif (strlen($consumer['document'] ?? '') < 11) {
            abort(422, 'CPF do cliente inválido ou ausente.');
        }

        $metadata['checkout_payment_method'] = $paymentMethod;
        $metadata['consumer_name'] = $consumer['name'];

        $shippingHelper = app(CheckoutShippingHelper::class);
        $shippingResolved = null;
        if ($product !== null && $shippingHelper->productRequiresShipping($product)) {
            if (strtoupper($currency) !== 'BRL') {
                abort(422, 'Produtos físicos estão disponíveis apenas em BRL.');
            }
            $addrValidated = $request->validate($shippingHelper->shippingAddressValidationRules());
            $shippingResolved = $shippingHelper->resolveForCheckout($product, $addrValidated);
            $orderAmount = round($orderAmount + $shippingResolved['shipping_amount'], 2);
            $metadata = array_merge($metadata, $shippingResolved['metadata_shipping']);
        }

        app(MinimumChargeService::class)->assertApiPayment($orderAmount, $tenantId);

        $orderPayload = [
            'tenant_id' => $tenantId,
            'user_id' => $userConsumer['user']->id,
            'product_id' => $product?->id,
            'product_offer_id' => $productOfferId,
            'subscription_plan_id' => $subscriptionPlanId,
            'api_application_id' => $app->id,
            'api_checkout_session_id' => null,
            'status' => 'pending',
            'amount' => $orderAmount,
            'email' => $consumer['email'],
            'cpf' => $userConsumer['cpf'] ?? null,
            'phone' => $userConsumer['phone'] ?? null,
            'customer_ip' => $request->ip(),
            'coupon_code' => null,
            'gateway' => null,
            'gateway_id' => null,
            'payment_method' => $paymentMethod,
            'cajupay_account_id' => app(CajuPayAccountResolver::class)->accountIdForPayment($tenantId, $paymentMethod),
            'metadata' => $metadata,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'is_renewal' => false,
        ];
        $orderPayload = app(SubscriptionRenewalService::class)->withRenewalFlag($orderPayload);
        if ($shippingResolved !== null) {
            $orderPayload['shipping_amount'] = $shippingResolved['shipping_amount'];
            $orderPayload['shipping_store_id'] = $shippingResolved['shipping_store_id'];
            $orderPayload['shipping_rule_id'] = $shippingResolved['shipping_rule_id'];
            $orderPayload['shipping_address'] = $shippingResolved['shipping_address'];
        }

        try {
            $order = Order::create($orderPayload);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'api_application_id')) {
                report($e);

                abort(503, 'API PIX indisponível: execute as migrações do banco no servidor (php artisan migrate).');
            }

            throw $e;
        }

        if ($product !== null) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_offer_id' => $productOfferId,
                'subscription_plan_id' => $subscriptionPlanId,
                'amount' => $orderAmount,
                'position' => 0,
            ]);
            app(\App\Services\ProductBundleService::class)->snapshotIncludedProducts($order);
        }

        $gatewayConfig = $app->payment_gateways ?? ApiApplication::defaultPaymentGateways();

        return [
            'order' => $order,
            'product' => $product,
            'amount' => $orderAmount,
            'consumer' => $consumer,
            'gateway_config' => $gatewayConfig,
        ];
    }
}
