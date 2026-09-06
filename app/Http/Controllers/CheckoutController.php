<?php

namespace App\Http\Controllers;

use App\Events\BoletoGenerated;
use App\Events\OrderCompleted;
use App\Events\OrderPending;
use App\Events\OrderRejected;
use App\Events\PixGenerated;
use App\Events\SubscriptionCreated;
use App\Gateways\GatewayRegistry;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\CheckoutSession;
use App\Models\Coupon;
use App\Models\GatewayCredential;
use App\Support\AffiliateOrderMetadata;
use App\Support\GatewayPaymentCredentials;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\ProductOrderBump;
use App\Models\SavedPaymentMethod;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AffiliateConversionPixels;
use App\Services\BuyerAccountService;
use App\Services\EfiPixRecorrenteService;
use App\Services\GeoIp;
use App\Services\MinimumChargeService;
use App\Services\Meta\MetaTrackingService;
use App\Services\MetricsTracking\MetricsCaptureService;
use App\Models\MetricsEvent;
use App\Services\PaymentService;
use App\Services\PhysicalProductAccess;
use App\Services\PushinPayPixRecorrenteService;
use App\Services\Versell\VersellPixRecorrenteService;
use App\Gateways\Versell\VersellCredentials;
use App\Services\Shipping\CheckoutShippingHelper;
use App\Services\Shipping\ShippingQuoteService;
use App\Services\StorageService;
use App\Services\Checkout\CheckoutAbuseGuard;
use App\Services\CouponCheckoutService;
use App\Services\LinaOpenx\LinaOpenxCheckoutService;
use Illuminate\Support\Facades\Schema;
use App\Support\CajuPayBrowserSdk;
use App\Support\CardInstallments;
use App\Services\PlatformCardInstallments;
use App\Support\CheckoutCardContract;
use App\Support\CheckoutPaymentConsumer;
use App\Support\CheckoutTranslations;
use App\Support\CheckoutTurnstileSettings;
use App\Support\PlatformCompanySettings;
use App\Support\SafeUrl;
use App\Support\GatewayWebhookUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    private ?Request $idempotencyRequest = null;

    /** @var array<string, mixed>|null */
    private ?array $idempotencyValidated = null;

    private function rollbackFailedOrder(Order $order, \Throwable $originalError): void
    {
        try {
            $order->delete();

            return;
        } catch (\Throwable $deleteError) {
            Log::warning('Checkout: failed to delete order after payment failure', [
                'order_id' => $order->id,
                'delete_error' => $deleteError->getMessage(),
                'original_error' => $originalError->getMessage(),
            ]);
        }

        try {
            $order->update(['status' => 'cancelled']);
        } catch (\Throwable $updateError) {
            Log::warning('Checkout: failed to mark order cancelled after payment failure', [
                'order_id' => $order->id,
                'update_error' => $updateError->getMessage(),
                'original_error' => $originalError->getMessage(),
            ]);
        }
    }

    /**
     * Resolve checkout context by slug: offer first, then plan, then product (legacy).
     *
     * @return array{product: Product, offer: ProductOffer|null, plan: SubscriptionPlan|null, amount: float, currency: string, checkout_slug: string}
     */
    private function resolveCheckoutBySlug(string $slug): array
    {
        $offer = ProductOffer::where('checkout_slug', $slug)->with('product')->first();
        if ($offer && $offer->product && $offer->product->isAvailableForPurchase()) {
            return [
                'product' => $offer->product,
                'offer' => $offer,
                'plan' => null,
                'amount' => (float) $offer->price,
                'currency' => $offer->getCurrencyOrDefault(),
                'checkout_slug' => $offer->checkout_slug,
            ];
        }

        $plan = SubscriptionPlan::where('checkout_slug', $slug)->with('product')->first();
        if ($plan && $plan->product && $plan->product->isAvailableForPurchase()) {
            return [
                'product' => $plan->product,
                'offer' => null,
                'plan' => $plan,
                'amount' => (float) $plan->price,
                'currency' => $plan->getCurrencyOrDefault(),
                'checkout_slug' => $plan->checkout_slug,
            ];
        }

        $product = Product::where('checkout_slug', $slug)->availableForPurchase()->first();
        if ($product) {
            return [
                'product' => $product,
                'offer' => null,
                'plan' => null,
                'amount' => (float) $product->price,
                'currency' => $product->currency ?? 'BRL',
                'checkout_slug' => $product->checkout_slug,
            ];
        }

        abort(404);
    }

    public function show(Request $request, string $slug): Response
    {
        $resolved = $this->resolveCheckoutBySlug($slug);
        $product = $resolved['product'];

        // No checkout principal: offer_id ou plan_id na URL pré-seleciona oferta/plano (mesmo checkout, link diferente por oferta/plano).
        // Para assinatura sem plan_id, usa o plano base (menor position) como padrão.
        if ($resolved['offer'] === null && $resolved['plan'] === null && $product->checkout_slug === $slug) {
            $offerId = $request->integer('offer_id', 0) ?: null;
            $planId = $request->integer('plan_id', 0) ?: null;
            if ($offerId) {
                $offer = ProductOffer::where('id', $offerId)->where('product_id', $product->id)->first();
                if ($offer) {
                    $resolved = [
                        'product' => $product,
                        'offer' => $offer,
                        'plan' => null,
                        'amount' => (float) $offer->price,
                        'currency' => $offer->getCurrencyOrDefault(),
                        'checkout_slug' => $product->checkout_slug,
                    ];
                }
            } elseif ($planId) {
                $plan = SubscriptionPlan::where('id', $planId)->where('product_id', $product->id)->first();
                if ($plan) {
                    $resolved = [
                        'product' => $product,
                        'offer' => null,
                        'plan' => $plan,
                        'amount' => (float) $plan->price,
                        'currency' => $plan->getCurrencyOrDefault(),
                        'checkout_slug' => $product->checkout_slug,
                    ];
                }
            } elseif (($product->billing_type ?? Product::BILLING_ONE_TIME) === Product::BILLING_SUBSCRIPTION) {
                $basePlan = SubscriptionPlan::where('product_id', $product->id)->orderBy('position')->first();
                if ($basePlan) {
                    $resolved = [
                        'product' => $product,
                        'offer' => null,
                        'plan' => $basePlan,
                        'amount' => (float) $basePlan->price,
                        'currency' => $basePlan->getCurrencyOrDefault(),
                        'checkout_slug' => $product->checkout_slug,
                    ];
                }
            }
        }

        $product = $resolved['product'];
        $defaults = Product::defaultCheckoutConfig();
        $productConfig = $product->checkout_config ?? [];
        $config = array_replace_recursive($defaults, $productConfig);
        if ($resolved['offer'] && $resolved['offer']->checkout_config !== null && $resolved['offer']->checkout_config !== []) {
            $config = array_replace_recursive($config, $resolved['offer']->checkout_config);
        } elseif ($resolved['plan'] && $resolved['plan']->checkout_config !== null && $resolved['plan']->checkout_config !== []) {
            $config = array_replace_recursive($config, $resolved['plan']->checkout_config);
        }
        $productArray = $this->productToCheckoutArray($product, $resolved['offer'], $resolved['plan'], $resolved['amount'], $resolved['currency'], $resolved['checkout_slug']);
        $payload = [
            'product' => $productArray,
            'config' => $config,
        ];
        $payload['offer'] = $resolved['offer'] ? [
            'id' => $resolved['offer']->id,
            'name' => $resolved['offer']->name,
            'price' => (float) $resolved['offer']->price,
            'currency' => $resolved['offer']->getCurrencyOrDefault(),
            'checkout_slug' => $resolved['offer']->checkout_slug,
        ] : null;
        $payload['subscription_plan'] = $resolved['plan'] ? [
            'id' => $resolved['plan']->id,
            'name' => $resolved['plan']->name,
            'price' => (float) $resolved['plan']->price,
            'currency' => $resolved['plan']->getCurrencyOrDefault(),
            'interval' => $resolved['plan']->interval,
            'checkout_slug' => $resolved['plan']->checkout_slug,
        ] : null;

        $exitPopup = $config['exit_popup'] ?? [];
        if (! empty($exitPopup['enabled']) && ! empty($exitPopup['coupon_id'])) {
            $coupon = Coupon::where('id', $exitPopup['coupon_id'])
                ->where('tenant_id', $product->tenant_id)
                ->whereHas('products', fn ($q) => $q->where('products.id', $product->id))
                ->first();
            $payload['exit_popup_coupon'] = $coupon ? ['code' => $coupon->code, 'id' => $coupon->id] : null;
        } else {
            $payload['exit_popup_coupon'] = null;
        }

        $geo = new GeoIp;
        $suggestions = $geo->getSuggestionsForIp(request()->ip());
        $payload['suggested_locale'] = $suggestions['suggested_locale'];
        $payload['suggested_currency'] = $suggestions['suggested_currency'];
        $payload['suggested_country_code'] = $suggestions['country_code'] ?? null;

        $tenantId = $product->tenant_id;
        $defaultTranslations = config('checkout_translations');
        $checkoutTranslationsRaw = Setting::get('checkout_translations', null, null);
        $savedTranslations = $checkoutTranslationsRaw
            ? (is_string($checkoutTranslationsRaw) ? json_decode($checkoutTranslationsRaw, true) : $checkoutTranslationsRaw)
            : [];
        $payload['checkout_translations'] = CheckoutTranslations::merge($defaultTranslations, is_array($savedTranslations) ? $savedTranslations : []);

        $currenciesRaw = Setting::get('currencies', null, null);
        $currencies = $currenciesRaw
            ? (is_string($currenciesRaw) ? json_decode($currenciesRaw, true) : $currenciesRaw)
            : config('products.currencies');
        $payload['currencies'] = is_array($currencies) ? $currencies : config('products.currencies');

        $payload['product'] = $this->addPricesInCurrencies($productArray, $payload['currencies']);
        $payload['available_payment_methods'] = app(PaymentService::class)->availablePaymentMethodsForCheckout(
            $product,
            $resolved['plan'] ?? null,
            null
        );
        $payload['card_payee_code'] = '';
        $payload['card_efi_sandbox'] = false;
        $payload['card_stripe_publishable_key'] = '';
        $payload['card_stripe_sandbox'] = false;
        $payload['card_stripe_link_enabled'] = true;
        $payload['card_mercadopago_public_key'] = '';
        $payload['card_mercadopago_sandbox'] = false;
        foreach ($payload['available_payment_methods'] as $m) {
            if (($m['id'] ?? '') === 'card' && ($m['gateway_slug'] ?? '') === 'efi') {
                $cred = GatewayCredential::resolveForTenantOrGlobal($product->tenant_id, 'efi');
                if ($cred) {
                    $creds = $cred->getDecryptedCredentials();
                    $payload['card_payee_code'] = (string) ($creds['payee_code'] ?? '');
                    $payload['card_efi_sandbox'] = ! empty($creds['sandbox']);
                }
                break;
            }
        }
        foreach ($payload['available_payment_methods'] as $m) {
            if (($m['id'] ?? '') === 'card' && ($m['gateway_slug'] ?? '') === 'stripe') {
                $cred = GatewayCredential::resolveForTenantOrGlobal($product->tenant_id, 'stripe');
                if ($cred) {
                    $creds = $cred->getDecryptedCredentials();
                    $payload['card_stripe_publishable_key'] = (string) ($creds['publishable_key'] ?? '');
                    $payload['card_stripe_sandbox'] = ! empty($creds['sandbox']);
                    $payload['card_stripe_link_enabled'] = isset($creds['link_enabled'])
                        ? (bool) $creds['link_enabled']
                        : true;
                }
                break;
            }
        }
        foreach ($payload['available_payment_methods'] as $m) {
            if (($m['id'] ?? '') === 'card' && ($m['gateway_slug'] ?? '') === 'mercadopago') {
                $cred = GatewayCredential::resolveForTenantOrGlobal($product->tenant_id, 'mercadopago');
                if ($cred) {
                    $creds = $cred->getDecryptedCredentials();
                    $payload['card_mercadopago_public_key'] = (string) ($creds['public_key'] ?? '');
                    $payload['card_mercadopago_sandbox'] = ! empty($creds['sandbox']);
                }
                break;
            }
        }
        $payload['card_gateway_keys'] = [];
        foreach ($payload['available_payment_methods'] as $m) {
            if (($m['id'] ?? '') !== 'card') {
                continue;
            }
            $slug = $m['gateway_slug'] ?? '';
            if ($slug === '') {
                continue;
            }
            $gateway = GatewayRegistry::get($slug);
            $keys = $gateway['checkout_payload_keys'] ?? null;
            if (! is_array($keys) || $keys === []) {
                continue;
            }
            $cred = GatewayCredential::resolveForPayment($product->tenant_id, $slug);
            if (! $cred) {
                continue;
            }
            $creds = $cred->getDecryptedCredentials();
            $payload['card_gateway_keys'][$slug] = [];
            foreach ($keys as $key) {
                if (is_string($key) && array_key_exists($key, $creds)) {
                    $payload['card_gateway_keys'][$slug][$key] = $creds[$key];
                }
            }
            if ($slug === 'pagarme') {
                $payload['card_gateway_keys'][$slug]['api_base_url'] = rtrim((string) config('services.pagarme.base_url', 'https://api.pagar.me/core/v5'), '/');
            }
            if ($slug === 'cielo') {
                $payload['card_gateway_keys'][$slug]['sandbox'] = ! empty($creds['sandbox']);
                $payload['card_gateway_keys'][$slug]['sop_configured'] = trim((string) ($creds['sop_client_id'] ?? '')) !== ''
                    || ! empty($creds['sandbox']);
            }
        }
        $payload['paypal_client_id'] = '';
        $payload['paypal_sandbox'] = false;
        foreach ($payload['available_payment_methods'] as $m) {
            if (($m['id'] ?? '') !== 'paypal') {
                continue;
            }
            $cred = GatewayCredential::resolveForPayment($product->tenant_id, 'paypal');
            if (! $cred) {
                break;
            }
            $creds = $cred->getDecryptedCredentials();
            $payload['paypal_client_id'] = (string) ($creds['client_id'] ?? '');
            $payload['paypal_sandbox'] = ! empty($creds['sandbox']);
            break;
        }
        $cardInstallmentsConfig = $config['card_installments'] ?? ['enabled' => false, 'max' => 1];
        $isSubscriptionCheckout = ($resolved['plan'] ?? null) !== null;
        $resolvedInstallments = PlatformCardInstallments::forProductConfig(
            is_array($cardInstallmentsConfig) ? $cardInstallmentsConfig : [],
            $isSubscriptionCheckout
        );
        $installmentsEnabled = $resolvedInstallments['enabled'];
        $payload['card_installments_enabled'] = $installmentsEnabled;
        $payload['card_max_installments'] = $installmentsEnabled
            ? $resolvedInstallments['max']
            : 1;

        $orderBumps = $product->orderBumps()->with(['targetProduct', 'targetProductOffer'])->get()
            ->filter(fn (ProductOrderBump $b) => $b->targetProduct && $b->targetProduct->isAvailableForPurchase());
        $payload['order_bumps'] = $orderBumps->map(function (ProductOrderBump $b) use ($product) {
            $target = $b->targetProduct;
            $imageUrl = $target && $target->image
                ? (new StorageService($product->tenant_id))->url($target->image)
                : null;
            $effectiveBrl = $b->getEffectiveAmountBrl();
            $originalBrl = $b->getOriginalAmountBrl();

            return [
                'id' => $b->id,
                'title' => $b->title,
                'description' => $b->description,
                'cta_title' => $b->cta_title,
                'amount_brl' => $effectiveBrl,
                'original_amount_brl' => $originalBrl > $effectiveBrl ? $originalBrl : null,
                'target_product_id' => $b->target_product_id,
                'target_product_offer_id' => $b->target_product_offer_id,
                'image_url' => $imageUrl,
                'target_name' => $target?->name,
            ];
        })->values()->all();

        $affiliateRef = (string) $request->query('ref', '');
        $payload['conversion_pixels'] = AffiliateConversionPixels::forProductAndRef($product, $affiliateRef);

        $isBuilderPreview = $request->query('preview') === '1';
        $sessionToken = Str::uuid()->toString();
        $checkoutSession = null;
        if (! $isBuilderPreview) {
            $checkoutSession = CheckoutSession::create(
                CheckoutSession::filterAttributesForExistingColumns(array_merge([
                    'tenant_id' => $product->tenant_id,
                    'product_id' => $product->id,
                    'product_offer_id' => $resolved['offer']?->id,
                    'subscription_plan_id' => $resolved['plan']?->id,
                    'checkout_slug' => $resolved['checkout_slug'],
                    'session_token' => $sessionToken,
                    'step' => CheckoutSession::STEP_VISIT,
                    'customer_ip' => $request->ip(),
                    'affiliate_ref' => $affiliateRef !== '' ? $affiliateRef : null,
                ], CheckoutSession::trackingFromQuery($request), CheckoutSession::metaAttributionFromQuery($request)))
            );

            app(MetaTrackingService::class)->queueCheckoutLandingEvents(
                $checkoutSession,
                $product,
                $payload['conversion_pixels'],
                (float) $resolved['amount'],
                (string) ($resolved['currency'] ?? 'BRL'),
                $request->fullUrl(),
            );

            // Tracking interno (falha isolada — não impacta checkout / UTMify / Meta).
            try {
                $metricsKey = app(MetricsCaptureService::class)->capture($request, [
                    'event_name' => MetricsEvent::CHECKOUT_VIEW,
                    'event_id' => 'chk-view:'.$sessionToken,
                    'product_id' => $product->id,
                    'tenant_id' => $product->tenant_id,
                    'offer_id' => $resolved['offer']?->id,
                    'plan_id' => $resolved['plan']?->id,
                    'checkout_session_id' => $checkoutSession->id,
                    'affiliate_ref' => $affiliateRef !== '' ? $affiliateRef : null,
                    'destination_url' => $request->fullUrl(),
                ]);
                if (is_string($metricsKey) && $metricsKey !== '') {
                    if (\Illuminate\Support\Facades\Schema::hasColumn('checkout_sessions', 'metrics_session_key')) {
                        $checkoutSession->metrics_session_key = $metricsKey;
                        $checkoutSession->save();
                    }
                    $payload['metrics_session_key'] = $metricsKey;
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('metrics.checkout_view_failed', [
                    'message' => $e->getMessage(),
                ]);
            }
        }
        $payload['checkout_session_token'] = $sessionToken;

        /** Preview ao vivo no Builder (iframe): o front confia neste flag, não só na query (Inertia pode alterar URL). */
        $payload['checkout_builder_preview'] = $isBuilderPreview;

        $payload['affiliate_ref'] = $affiliateRef;
        $payload['meta_tracking_debug'] = config('meta_tracking.debug');
        $payload['turnstile'] = CheckoutTurnstileSettings::publicConfig();
        $payload['platform_checkout_notice'] = PlatformCompanySettings::resolvedCheckoutNoticeForTenant($product->tenant_id, $product);

        return Inertia::render('Checkout/Show', $payload);
    }

    public function validateCoupon(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'coupon_code' => ['required', 'string', 'max:64'],
            'product_offer_id' => ['nullable', 'exists:product_offers,id'],
            'subscription_plan_id' => ['nullable', 'exists:subscription_plans,id'],
        ]);
        $product = Product::findOrFail($request->input('product_id'));
        if (! $product->isAvailableForPurchase()) {
            return response()->json(['valid' => false, 'message' => 'Este produto não está disponível para compra.']);
        }
        $code = trim((string) $request->input('coupon_code'));
        if ($code === '') {
            return response()->json(['valid' => false, 'message' => 'Código do cupom é obrigatório.']);
        }
        $coupon = app(CouponCheckoutService::class)->findForProduct($product, $code);
        if (! $coupon) {
            return response()->json(['valid' => false, 'message' => 'Cupom inválido ou não disponível para este produto.']);
        }
        $price = (float) $product->price;
        $currency = $product->currency ?? 'BRL';
        if ($request->filled('product_offer_id')) {
            $offer = ProductOffer::where('id', $request->input('product_offer_id'))->where('product_id', $product->id)->first();
            if ($offer) {
                $price = (float) $offer->price;
                $currency = $offer->getCurrencyOrDefault();
            }
        } elseif ($request->filled('subscription_plan_id')) {
            $plan = SubscriptionPlan::where('id', $request->input('subscription_plan_id'))->where('product_id', $product->id)->first();
            if ($plan) {
                $price = (float) $plan->price;
                $currency = $plan->getCurrencyOrDefault();
            }
        }
        if ($currency !== 'BRL') {
            $rates = config('products.rates');
            $price = $currency === 'EUR' ? $price / ($rates['brl_eur'] ?? 0.16) : $price / ($rates['brl_usd'] ?? 0.18);
        }
        $result = $coupon->applyTo($product, $price);
        if ($result === null) {
            return response()->json(['valid' => false, 'message' => 'Este cupom não pode ser aplicado (expirado, uso esgotado ou valor mínimo não atingido).']);
        }

        return response()->json([
            'valid' => true,
            'discount_amount' => $result['discount_amount'],
            'final_price' => $result['final_price'],
        ]);
    }

    /**
     * ACK leve: browser registrou tentativa de Purchase antes do redirect (diagnóstico).
     */
    public function purchasePixelAck(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'min:1'],
            'checkout_session_token' => ['required', 'string', 'max:64'],
            'token' => ['nullable', 'string', 'max:64'],
            'trigger_type' => ['nullable', 'string', 'in:approved,pix,boleto'],
        ]);

        $order = Order::find($validated['order_id']);
        if (! $order) {
            return response()->json(['ok' => false], 404);
        }

        $session = CheckoutSession::where('session_token', $validated['checkout_session_token'])
            ->where('product_id', $order->product_id)
            ->first();
        if (! $session) {
            return response()->json(['message' => 'Sessão de checkout inválida.'], 403);
        }
        if ($session->order_id !== null && (int) $session->order_id !== (int) $order->id) {
            return response()->json(['message' => 'Pedido não pertence à sessão.'], 403);
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['browser_purchase_ack_at'] = now()->toIso8601String();
        $meta['browser_purchase_ack_trigger'] = $validated['trigger_type'] ?? 'approved';
        if (! empty($validated['token'])) {
            $meta['browser_purchase_ack_token'] = $validated['token'];
        }
        $order->update(['metadata' => $meta]);

        return response()->json(['ok' => true]);
    }

    public function shippingQuote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'product_offer_id' => ['nullable', 'integer'],
            'subscription_plan_id' => ['nullable', 'integer'],
            'cep' => ['required', 'string', 'max:9'],
            'order_bump_ids' => ['nullable', 'array'],
            'order_bump_ids.*' => ['integer'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
        ]);

        $product = Product::findOrFail($validated['product_id']);
        if (! PhysicalProductAccess::globalEnabled() || ! $product->isPhysical()) {
            return response()->json([
                'shipping_amount' => 0,
                'free_shipping' => true,
                'product_subtotal_brl' => 0,
                'total_with_shipping' => 0,
            ]);
        }

        try {
            $quote = app(ShippingQuoteService::class)->quote($product, $validated['cep']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $subtotal = $this->computeCheckoutProductSubtotalBrl($product, $validated);

        return response()->json(array_merge($quote->toArray(), [
            'product_subtotal_brl' => round($subtotal, 2),
            'total_with_shipping' => round($subtotal + $quote->shippingAmount, 2),
        ]));
    }

    public function process(Request $request): RedirectResponse|JsonResponse
    {
        $this->forgetInvalidMetricsSessionKey($request);

        $product = Product::findOrFail($request->input('product_id'));
        if (! $product->isAvailableForPurchase()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Este produto não está disponível para compra no momento.'], 422);
            }

            return redirect()->back()->with('error', 'Este produto não está disponível para compra no momento.');
        }
        $customerFields = $product->checkout_config['customer_fields'] ?? [];

        $productOfferIdForRules = $request->filled('product_offer_id') ? (int) $request->input('product_offer_id') : null;
        $subscriptionPlanIdForRules = $request->filled('subscription_plan_id') ? (int) $request->input('subscription_plan_id') : null;
        $offerForRules = $productOfferIdForRules ? ProductOffer::where('id', $productOfferIdForRules)->where('product_id', $product->id)->first() : null;
        $planForRules = $subscriptionPlanIdForRules ? SubscriptionPlan::where('id', $subscriptionPlanIdForRules)->where('product_id', $product->id)->first() : null;
        $effectiveCurrency = strtoupper((string) ($product->currency ?? 'BRL'));
        if ($offerForRules) {
            $effectiveCurrency = strtoupper((string) ($offerForRules->getCurrencyOrDefault() ?? 'BRL'));
        } elseif ($planForRules) {
            $effectiveCurrency = strtoupper((string) ($planForRules->getCurrencyOrDefault() ?? 'BRL'));
        }
        $displayCurrencyInput = $request->input('display_currency');
        $displayCurrency = is_string($displayCurrencyInput) && $displayCurrencyInput !== '' ? strtoupper($displayCurrencyInput) : $effectiveCurrency;

        $paymentService = app(PaymentService::class);
        $paymentMethodForRules = $request->input('payment_method');
        $firstPixGateway = $paymentMethodForRules === 'pix'
            ? $paymentService->getFirstAvailableGatewayForMethod($product->tenant_id, 'pix', $product)
            : null;
        $firstCardGatewayForRules = in_array($paymentMethodForRules, ['card', 'apple_pay', 'google_pay'], true)
            ? $paymentService->getFirstAvailableGatewayForMethod($product->tenant_id, 'card', $product)
            : null;
        $requireCpf = (($customerFields['cpf'] ?? false) && $displayCurrency === 'BRL')
            || ($firstCardGatewayForRules === 'pagarme' && $displayCurrency === 'BRL')
            || $paymentMethodForRules === 'open_finance';
        $phoneRequiredForCheckout = ($customerFields['phone'] ?? false)
            || ($paymentMethodForRules === 'pix' && $firstPixGateway === 'pagarme');

        $rules = [
            'product_id' => ['required', 'exists:products,id'],
            'product_offer_id' => ['nullable', 'exists:product_offers,id'],
            'subscription_plan_id' => ['nullable', 'exists:subscription_plans,id'],
            'order_bump_ids' => ['nullable', 'array'],
            'order_bump_ids.*' => ['integer', 'exists:product_order_bumps,id'],
            'payment_method' => ['required', 'string', 'in:pix,card,boleto,pix_auto,apple_pay,google_pay,open_finance'],
            'checkout_session_token' => ['required', 'string', 'max:64'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'website' => ['nullable', 'string', 'max:255'],
            '_hp' => ['nullable', 'string', 'max:255'],
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
            'display_currency' => ['nullable', 'string', 'in:BRL,USD,EUR'],
            'email' => ['required', 'email'],
            'name' => [($customerFields['name'] ?? true) ? 'required' : 'nullable', 'string', 'max:255'],
            'cpf' => [$requireCpf ? 'required' : 'nullable', 'string', 'max:11'],
            'phone' => [$phoneRequiredForCheckout ? 'required' : 'nullable', 'string', 'max:24'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
            'affiliate_ref' => ['nullable', 'string', 'max:32'],
            'metrics_session_key' => ['nullable', 'uuid'],
        ];
        foreach (CheckoutSession::TRACKING_FIELD_KEYS as $trackingKey) {
            $rules[$trackingKey] = ['nullable', 'string', 'max:2048'];
        }
        // Meta cookies/user agent para melhorar match na Conversion API (CAPI)
        $rules['fbp'] = ['nullable', 'string', 'max:512'];
        $rules['fbc'] = ['nullable', 'string', 'max:512'];
        $rules['user_agent'] = ['nullable', 'string', 'max:2048'];
        if (in_array($request->input('payment_method'), ['card', 'apple_pay', 'google_pay'], true)) {
            $firstCardGateway = $firstCardGatewayForRules ?? $paymentService->getFirstAvailableGatewayForMethod($product->tenant_id, 'card', $product);
            if ($firstCardGateway === 'asaas') {
                $rules['payment_token'] = ['nullable', 'string', 'max:10000'];
                $rules['card_holder_name'] = ['required_without:payment_token', 'string', 'max:255'];
                $rules['card_number'] = ['required_without:payment_token', 'string', 'max:19'];
                $rules['card_expiry_month'] = ['required_without:payment_token', 'string', 'size:2'];
                $rules['card_expiry_year'] = ['required_without:payment_token', 'string', 'max:4'];
                $rules['card_ccv'] = ['required_without:payment_token', 'string', 'max:4'];
                $rules['address_zipcode'] = ['required', 'string', 'max:9'];
                $rules['address_street'] = ['required', 'string', 'max:255'];
                $rules['address_number'] = ['required', 'string', 'max:20'];
                $rules['address_neighborhood'] = ['required', 'string', 'max:255'];
                $rules['address_city'] = ['required', 'string', 'max:255'];
                $rules['address_state'] = ['required', 'string', 'max:2'];
            } elseif ($firstCardGateway === 'pagarme') {
                $rules['payment_token'] = ['required', 'string', 'max:10000'];
                $rules['address_zipcode'] = ['required', 'string', 'max:9'];
                $rules['address_street'] = ['required', 'string', 'max:255'];
                $rules['address_number'] = ['required', 'string', 'max:20'];
                $rules['address_neighborhood'] = ['required', 'string', 'max:255'];
                $rules['address_city'] = ['required', 'string', 'max:255'];
                $rules['address_state'] = ['required', 'string', 'max:2'];
            } elseif ($firstCardGateway === 'cajupay') {
                $rules['payment_token'] = ['nullable', 'string', 'max:10000'];
                $rules['cajupay_wallet'] = ['nullable', 'string', 'in:card,apple_pay,google_pay'];
            } else {
                $rules['payment_token'] = ['required', 'string', 'max:10000'];
            }
            $rules['installments'] = ['nullable', 'integer', 'min:1', 'max:12'];
        }
        if ($request->input('payment_method') === 'boleto') {
            $rules['address_zipcode'] = ['required', 'string', 'max:9'];
            $rules['address_street'] = ['required', 'string', 'max:255'];
            $rules['address_number'] = ['required', 'string', 'max:20'];
            $rules['address_neighborhood'] = ['required', 'string', 'max:255'];
            $rules['address_city'] = ['required', 'string', 'max:255'];
            $rules['address_state'] = ['required', 'string', 'max:2'];
        }
        $shippingHelper = app(CheckoutShippingHelper::class);
        if ($shippingHelper->productRequiresShipping($product)) {
            $rules = $shippingHelper->appendAddressRulesIfNeeded($product, $rules, $displayCurrency);
            $rules['shipping_cep'] = ['required', 'string', 'max:9'];
            $rules['shipping_street'] = ['required', 'string', 'max:255'];
            $rules['shipping_number'] = ['required', 'string', 'max:32'];
            $rules['shipping_complement'] = ['nullable', 'string', 'max:120'];
            $rules['shipping_neighborhood'] = ['required', 'string', 'max:120'];
            $rules['shipping_city'] = ['required', 'string', 'max:120'];
            $rules['shipping_state'] = ['required', 'string', 'size:2'];
        }
        $validated = $request->validate($rules);
        $validated = \App\Support\CheckoutInputSanitizer::sanitize($validated);
        $this->idempotencyRequest = $request;
        $this->idempotencyValidated = $validated;

        $checkoutGuard = app(CheckoutAbuseGuard::class);
        $fingerprintCached = $checkoutGuard->cachedResponseForFingerprint($request, $validated);
        if ($fingerprintCached !== null) {
            return $fingerprintCached;
        }

        $checkoutGuard->assertCanProcess($request, $product, $validated, false);

        $idempotencyKey = isset($validated['idempotency_key']) && trim((string) $validated['idempotency_key']) !== ''
            ? trim((string) $validated['idempotency_key'])
            : null;

        if ($idempotencyKey !== null) {
            $cached = Cache::get('checkout_idempotency:'.$idempotencyKey);
            if ($cached !== null && is_array($cached)) {
                if (($cached['type'] ?? '') === 'redirect' && ! empty($cached['url'])) {
                    return redirect($cached['url']);
                }
                if (($cached['type'] ?? '') === 'json' && array_key_exists('data', $cached)) {
                    return response()->json($cached['data']);
                }
            }
        }

        $paymentMethod = $validated['payment_method'];

        $productOfferId = $request->filled('product_offer_id') ? (int) $request->input('product_offer_id') : null;
        $subscriptionPlanId = $request->filled('subscription_plan_id') ? (int) $request->input('subscription_plan_id') : null;
        $offer = $productOfferId ? ProductOffer::where('id', $productOfferId)->where('product_id', $product->id)->first() : null;
        $plan = $subscriptionPlanId ? SubscriptionPlan::where('id', $subscriptionPlanId)->where('product_id', $product->id)->first() : null;

        if ($paymentMethod === 'pix_auto' && ! $plan) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'PIX automático está disponível apenas para assinaturas.'], 422);
            }

            return back()->withErrors(['payment_method' => 'PIX automático está disponível apenas para assinaturas.']);
        }

        $allowedPaymentIds = array_column(
            app(PaymentService::class)->availablePaymentMethodsForCheckout($product, $plan, null),
            'id'
        );
        if (! in_array($paymentMethod, $allowedPaymentIds, true)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Método de pagamento não disponível para este produto.'], 422);
            }

            return back()->withErrors(['payment_method' => 'Método de pagamento não disponível para este produto.']);
        }

        $amount = (float) $product->price;
        if ($offer) {
            $amount = (float) $offer->price;
        } elseif ($plan) {
            $amount = (float) $plan->price;
        }
        $currency = $product->currency ?? 'BRL';
        if ($offer) {
            $currency = $offer->getCurrencyOrDefault();
        } elseif ($plan) {
            $currency = $plan->getCurrencyOrDefault();
        }
        if ($currency !== 'BRL') {
            $rates = config('products.rates');
            $amount = $currency === 'EUR' ? $amount / ($rates['brl_eur'] ?? 0.16) : $amount / ($rates['brl_usd'] ?? 0.18);
        }

        $orderBumpIds = array_values(array_filter(array_map('intval', $validated['order_bump_ids'] ?? [])));
        $selectedBumps = collect();
        if ($orderBumpIds) {
            $selectedBumps = ProductOrderBump::where('product_id', $product->id)
                ->whereIn('id', $orderBumpIds)
                ->with('targetProduct')
                ->get()
                ->filter(fn (ProductOrderBump $b) => $b->targetProduct && $b->targetProduct->isAvailableForPurchase())
                ->values();
        }
        $bumpAmountTotal = $selectedBumps->sum(fn (ProductOrderBump $b) => $b->getEffectiveAmountBrl());
        $totalAmount = $amount + $bumpAmountTotal;

        $couponCode = isset($validated['coupon_code']) && trim($validated['coupon_code'] ?? '') !== '' ? trim($validated['coupon_code']) : null;
        try {
            $couponApplied = app(CouponCheckoutService::class)->applyOptional($product, $couponCode, $amount);
            $amount = $couponApplied['amount'];
            $couponCode = $couponApplied['coupon_code'];
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => collect($e->errors())->flatten()->first() ?? 'Cupom inválido.',
                    'errors' => $e->errors(),
                ], 422);
            }

            return back()->withErrors($e->errors())->withInput();
        }
        $totalAmount = $amount + $bumpAmountTotal;

        $shippingResolved = null;
        if ($shippingHelper->productRequiresShipping($product)) {
            try {
                $shippingResolved = $shippingHelper->resolveForCheckout($product, $validated);
                $totalAmount = round($totalAmount + $shippingResolved['shipping_amount'], 2);
            } catch (\RuntimeException $e) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }

                return back()->with('error', $e->getMessage())->withInput();
            }
        }

        $minimumGuard = $this->guardPlatformMinimumCheckout($totalAmount, $request, (int) $product->tenant_id);
        if ($minimumGuard !== null) {
            return $minimumGuard;
        }

        $periodStart = null;
        $periodEnd = null;
        if ($plan) {
            [$periodStart, $periodEnd] = $plan->getCurrentPeriod();
        }

        $checkoutSlug = $product->checkout_slug ?? '';
        if ($offer && ! empty($offer->checkout_slug)) {
            $checkoutSlug = $offer->checkout_slug;
        } elseif ($plan && ! empty($plan->checkout_slug)) {
            $checkoutSlug = $plan->checkout_slug;
        }
        $checkoutSlug = (string) $checkoutSlug;
        if ($checkoutSlug === '') {
            $checkoutSlug = (string) ($product->checkout_slug ?? '');
        }

        $tenantId = $product->tenant_id;

        $plainPassword = null;
        if ($product->type === Product::TYPE_AREA_MEMBROS) {
            $loginConfig = $product->member_area_config['login'] ?? [];
            $passwordMode = $loginConfig['password_mode'] ?? 'auto';
            $defaultPassword = trim((string) ($loginConfig['default_password'] ?? ''));
            if ($passwordMode === 'default' && $defaultPassword !== '') {
                $plainPassword = $defaultPassword;
            } else {
                $plainPassword = Str::random(12);
            }
        } else {
            $plainPassword = Str::random(32);
        }
        $passwordHash = bcrypt($plainPassword);

        $buyerAccount = app(BuyerAccountService::class)->ensureBuyerFromCheckout(
            $validated['email'],
            (string) ($validated['name'] ?? $validated['email']),
            $passwordHash,
            $product->type === Product::TYPE_AREA_MEMBROS,
        );
        $user = $buyerAccount['user'];
        $orderMetadata = [];
        if ($product->type === Product::TYPE_AREA_MEMBROS && $plainPassword !== null) {
            Cache::put('access_password.'.$user->id.'.'.$product->id, $plainPassword, now()->addHours(2));
            $orderMetadata['access_password_temp'] = encrypt($plainPassword);
        }

        $fbp = isset($validated['fbp']) && is_string($validated['fbp']) ? trim($validated['fbp']) : '';
        $fbc = isset($validated['fbc']) && is_string($validated['fbc']) ? trim($validated['fbc']) : '';
        $ua = isset($validated['user_agent']) && is_string($validated['user_agent']) ? trim($validated['user_agent']) : '';
        if ($fbp !== '') {
            $orderMetadata['fbp'] = $fbp;
        }
        if ($fbc !== '') {
            $orderMetadata['fbc'] = $fbc;
        }
        if ($ua !== '') {
            $orderMetadata['user_agent'] = $ua;
        }

        $orderMetadata = $this->mergeCheckoutSessionMetaTracking($orderMetadata, $validated['checkout_session_token'] ?? null);
        $orderMetadata = $this->mergeCheckoutSessionUtmsIntoOrderMetadata($orderMetadata, $validated['checkout_session_token'] ?? null, $validated, $product);

        $metricsSessionKey = $this->resolveValidMetricsSessionKey(
            isset($validated['metrics_session_key']) && is_string($validated['metrics_session_key'])
                ? $validated['metrics_session_key']
                : null
        );
        if ($metricsSessionKey === null) {
            $metricsSessionKey = $this->resolveValidMetricsSessionKey(
                (string) ($request->cookie((string) config('metrics_tracking.cookie_session', 'gf_msid')) ?? '')
            );
        }
        if ($metricsSessionKey === null) {
            $token = $validated['checkout_session_token'] ?? null;
            if (is_string($token) && $token !== '' && \Illuminate\Support\Facades\Schema::hasColumn('checkout_sessions', 'metrics_session_key')) {
                $metricsSessionKey = $this->resolveValidMetricsSessionKey(
                    CheckoutSession::where('session_token', $token)->value('metrics_session_key')
                );
            }
        }

        // Sempre tentar CHECKOUT_STARTED: capture gera UUID novo se a key estiver ausente/inválida.
        try {
            $capturedKey = app(MetricsCaptureService::class)->capture($request, [
                'event_name' => MetricsEvent::CHECKOUT_STARTED,
                'event_id' => 'chk-start:'.$validated['checkout_session_token'],
                'session_key' => $metricsSessionKey,
                'product_id' => $product->id,
                'tenant_id' => $tenantId,
                'affiliate_ref' => $validated['affiliate_ref'] ?? null,
            ]);
            $metricsSessionKey = $this->resolveValidMetricsSessionKey($capturedKey) ?? $metricsSessionKey;
        } catch (\Throwable) {
        }

        if (is_string($metricsSessionKey) && $metricsSessionKey !== '') {
            $orderMetadata['metrics_session_key'] = $metricsSessionKey;
        }

        $orderPayload = [
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_offer_id' => $productOfferId,
            'subscription_plan_id' => $subscriptionPlanId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'is_renewal' => false,
            'amount' => $totalAmount,
            'email' => $validated['email'],
            'cpf' => $validated['cpf'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'customer_ip' => $request->ip(),
            'coupon_code' => $couponCode,
            'metadata' => $orderMetadata,
        ];

        if ($shippingResolved !== null) {
            $orderPayload['shipping_amount'] = $shippingResolved['shipping_amount'];
            $orderPayload['shipping_store_id'] = $shippingResolved['shipping_store_id'];
            $orderPayload['shipping_rule_id'] = $shippingResolved['shipping_rule_id'];
            $orderPayload['shipping_address'] = $shippingResolved['shipping_address'];
            $orderPayload['metadata'] = array_merge($orderMetadata, $shippingResolved['metadata_shipping']);
            $orderMetadata = $orderPayload['metadata'];
        }

        $createOrderAndItems = function (array $payload) use ($product, $amount, $productOfferId, $subscriptionPlanId, $selectedBumps) {
            $order = Order::create($payload);
            if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'metrics_session_key')) {
                $ms = is_array($payload['metadata'] ?? null) ? ($payload['metadata']['metrics_session_key'] ?? null) : null;
                $ms = $this->resolveValidMetricsSessionKey($ms);
                if ($ms !== null) {
                    $order->forceFill(['metrics_session_key' => $ms])->save();
                }
            }
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_offer_id' => $productOfferId,
                'subscription_plan_id' => $subscriptionPlanId,
                'amount' => $amount,
                'position' => 0,
            ]);
            $pos = 1;
            foreach ($selectedBumps as $bump) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $bump->target_product_id,
                    'product_offer_id' => $bump->target_product_offer_id,
                    'subscription_plan_id' => null,
                    'amount' => $bump->getEffectiveAmountBrl(),
                    'position' => $pos++,
                ]);
            }

            return $order;
        };

        $grantAccessForOrder = function (Order $order) {
            $order->loadMissing('product', 'orderItems.product');
            if ($order->product && $order->product->type !== Product::TYPE_PRODUTO_FISICO) {
                $order->product->users()->syncWithoutDetaching([$order->user_id]);
            }
            foreach ($order->orderItems as $item) {
                if ($item->product && $item->product->type !== Product::TYPE_PRODUTO_FISICO) {
                    $item->product->users()->syncWithoutDetaching([$order->user_id]);
                }
            }
        };

        $updateCheckoutSession = function (Order $order) use ($validated) {
            $utmFromRequest = $this->utmPayloadFromValidated($validated);
            $token = $validated['checkout_session_token'] ?? null;
            if ($token) {
                $session = CheckoutSession::where('session_token', $token)->first();
                if ($session) {
                    $mergedUtms = $this->mergeSessionUtms($session, $utmFromRequest);
                    $session->update(array_merge([
                        'step' => CheckoutSession::STEP_CONVERTED,
                        'order_id' => $order->id,
                    ], $mergedUtms));
                    $this->persistOrderUtms($order, $mergedUtms);

                    return;
                }
            }
            $this->persistOrderUtms($order, $utmFromRequest);
        };

        if ($paymentMethod === 'pix') {
            $order = $createOrderAndItems(array_merge($orderPayload, [
                'status' => 'pending',
                'gateway' => null,
                'gateway_id' => null,
                'payment_method' => 'pix',
                'metadata' => array_merge($orderMetadata, ['checkout_payment_method' => 'pix']),
            ]));
            $order->load('orderItems');
            event(new OrderPending($order));
            try {
                $paymentService = app(PaymentService::class);
                $consumer = CheckoutPaymentConsumer::build($validated, $order->id);
                $pixResult = $paymentService->createPixPayment($order, $product, $consumer);
                $updateCheckoutSession($order);
                event(new PixGenerated($order, [
                    'qrcode' => $pixResult['qrcode'] ?? null,
                    'copy_paste' => $pixResult['copy_paste'] ?? null,
                    'transaction_id' => $pixResult['transaction_id'] ?? null,
                ]));
                $redirectUrl = $product->checkout_config['redirect_after_purchase'] ?? null;
                $redirectUrl = ! empty($redirectUrl) && is_string($redirectUrl) ? $redirectUrl : null;
                $pixToken = \Illuminate\Support\Str::random(32);
                session()->put('pix_display.'.$pixToken, [
                    'order_id' => $order->id,
                    'checkout_session_token' => $validated['checkout_session_token'] ?? null,
                    'qrcode' => $pixResult['qrcode'] ?? null,
                    'copy_paste' => $pixResult['copy_paste'] ?? null,
                    'amount' => $totalAmount,
                    'product_name' => $product->name,
                    'checkout_slug' => $checkoutSlug,
                    'redirect_after_purchase' => $redirectUrl,
                    'customer_name' => $validated['name'] ?? null,
                    'customer_email' => $validated['email'] ?? null,
                    'customer_phone' => $validated['phone'] ?? null,
                    'created_at' => time(),
                ]);
                if ($request->expectsJson()) {
                    return $this->idempotencyReturn($idempotencyKey, response()->json([
                        'success' => true,
                        'payment_method' => 'pix',
                        'order_id' => $order->id,
                        'qrcode' => $pixResult['qrcode'] ?? null,
                        'copy_paste' => $pixResult['copy_paste'] ?? null,
                        'transaction_id' => $pixResult['transaction_id'] ?? null,
                        'redirect_url' => route('checkout.pix', ['token' => $pixToken]),
                    ]));
                }

                return $this->idempotencyReturn($idempotencyKey, redirect()->route('checkout.pix', ['token' => $pixToken]));
            } catch (\Throwable $e) {
                $this->rollbackFailedOrder($order, $e);
                $msg = $e->getMessage() ?: 'Não foi possível gerar o PIX. Tente novamente.';
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => $msg,
                    ], 422);
                }
                if ($request->header('X-Inertia')) {
                    return back()->withErrors(['payment_method' => $msg])->withInput();
                }

                return back()->with('error', $msg);
            }
        }

        if ($paymentMethod === 'open_finance') {
            $order = $createOrderAndItems(array_merge($orderPayload, [
                'status' => 'pending',
                'gateway' => null,
                'gateway_id' => null,
                'payment_method' => 'open_finance',
                'metadata' => array_merge($orderMetadata, ['checkout_payment_method' => 'open_finance']),
            ]));
            $order->load('orderItems');
            event(new OrderPending($order));
            try {
                $linaService = app(LinaOpenxCheckoutService::class);
                $linaResult = $linaService->startPaymentForOrder(
                    $order,
                    $validated,
                    (string) ($product->name ?? ('Pedido #'.$order->id))
                );
                $updateCheckoutSession($order->fresh() ?? $order);
                $afterPurchase = $product->checkout_config['redirect_after_purchase'] ?? null;
                $afterPurchase = ! empty($afterPurchase) && is_string($afterPurchase) ? $afterPurchase : null;
                $waitToken = Str::random(32);
                session()->put('lina_display.'.$waitToken, [
                    'order_id' => $order->id,
                    'checkout_session_token' => $validated['checkout_session_token'] ?? null,
                    'amount' => $totalAmount,
                    'product_name' => $product->name,
                    'checkout_slug' => $checkoutSlug,
                    'redirect_after_purchase' => $afterPurchase,
                    'customer_name' => $validated['name'] ?? null,
                    'customer_email' => $validated['email'] ?? null,
                    'customer_phone' => $validated['phone'] ?? null,
                    'created_at' => time(),
                    'transaction_id' => $linaResult['transaction_id'] ?? null,
                ]);
                $portalUrl = $linaResult['redirect_url'];

                if ($request->expectsJson()) {
                    return $this->idempotencyReturn($idempotencyKey, response()->json([
                        'success' => true,
                        'payment_method' => 'open_finance',
                        'order_id' => $order->id,
                        'transaction_id' => $linaResult['transaction_id'] ?? null,
                        'redirect_url' => $portalUrl,
                        'wait_token' => $waitToken,
                        'external_redirect' => true,
                    ]));
                }

                return $this->idempotencyReturn($idempotencyKey, redirect()->away($portalUrl));
            } catch (\Throwable $e) {
                $this->rollbackFailedOrder($order, $e);
                $msg = $e->getMessage() ?: 'Não foi possível iniciar o pagamento Open Finance. Tente novamente.';
                $msg = str_ireplace(['Lina OpenX', 'LinaOpenX', 'Lina'], 'Open Finance', $msg);
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => $msg,
                    ], 422);
                }
                if ($request->header('X-Inertia')) {
                    return back()->withErrors(['payment_method' => $msg])->withInput();
                }

                return back()->with('error', $msg);
            }
        }

        if ($paymentMethod === 'pix_auto') {
            $paymentService = app(PaymentService::class);
            $gatewaySlug = $paymentService->getFirstAvailableGatewayForMethod($tenantId, 'pix_auto', $product);
            if ($gatewaySlug === null) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Nenhum gateway PIX automático configurado.'], 422);
                }

                return back()->withErrors(['payment_method' => 'Nenhum gateway PIX automático configurado.']);
            }

            if ($gatewaySlug === 'pushinpay') {
                $credential = GatewayCredential::resolveForPayment($tenantId, 'pushinpay');
                if (! $credential) {
                    if ($request->expectsJson()) {
                        return response()->json(['message' => 'Pushin Pay não configurado para PIX automático.'], 422);
                    }

                    return back()->withErrors(['payment_method' => 'Pushin Pay não configurado para PIX automático.']);
                }
                $credentials = $credential->getDecryptedCredentials();
                if (empty($credentials['api_token'])) {
                    if ($request->expectsJson()) {
                        return response()->json(['message' => 'Pushin Pay: API Token não configurado.'], 422);
                    }

                    return back()->withErrors(['payment_method' => 'Pushin Pay: API Token não configurado.']);
                }

                $order = $createOrderAndItems(array_merge($orderPayload, [
                    'status' => 'pending',
                    'gateway' => null,
                    'gateway_id' => null,
                    'payment_method' => 'pix_auto',
                    'metadata' => array_merge($orderMetadata, ['checkout_payment_method' => 'pix_auto']),
                ]));
                $order->load('orderItems');
                event(new OrderPending($order));

                $consumer = CheckoutPaymentConsumer::build($validated, $order->id);

                try {
                    $webhookUrl = GatewayWebhookUrl::forGateway('pushinpay');
                    $frequency = PushinPayPixRecorrenteService::intervalToFrequency($plan->interval ?? SubscriptionPlan::INTERVAL_MONTHLY);
                    $subscriptionName = mb_substr(preg_replace('/[^\p{L}\p{N}\s\.\-]/u', '', $product->name ?? 'Assinatura'), 0, 140) ?: 'Assinatura';
                    $pushinpayService = new PushinPayPixRecorrenteService($credentials);
                    $result = $pushinpayService->createSubscription(
                        (float) $totalAmount,
                        $consumer,
                        $webhookUrl,
                        $frequency,
                        $subscriptionName,
                        'Assinatura PIX automático - Pedido #'.$order->id
                    );

                    $txid = $result['transaction_id'];
                    $qrcodeImage = $result['qrcode'] ?? null;
                    $copyPaste = $result['copy_paste'] ?? null;
                    $subscriptionId = $result['subscription_id'] ?? '';

                    $order->update([
                        'gateway' => 'pushinpay',
                        'gateway_id' => $txid,
                        'metadata' => array_merge($order->metadata ?? [], ['pushinpay_subscription_id' => $subscriptionId]),
                    ]);

                    $updateCheckoutSession($order);
                    event(new PixGenerated($order, [
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                        'transaction_id' => $txid,
                    ]));

                    if ($request->expectsJson()) {
                        return $this->idempotencyReturn($idempotencyKey, response()->json([
                            'success' => true,
                            'payment_method' => 'pix_auto',
                            'order_id' => $order->id,
                            'qrcode' => $qrcodeImage,
                            'copy_paste' => $copyPaste ?? '',
                            'transaction_id' => $txid,
                        ]));
                    }
                    $redirectUrl = $product->checkout_config['redirect_after_purchase'] ?? null;
                    $redirectUrl = ! empty($redirectUrl) && is_string($redirectUrl) ? $redirectUrl : null;
                    $pixToken = Str::random(32);
                    session()->put('pix_display.'.$pixToken, [
                        'order_id' => $order->id,
                        'checkout_session_token' => $validated['checkout_session_token'] ?? null,
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                        'amount' => $totalAmount,
                        'product_name' => $product->name,
                        'checkout_slug' => $checkoutSlug,
                        'redirect_after_purchase' => $redirectUrl,
                        'customer_name' => $validated['name'] ?? null,
                        'customer_email' => $validated['email'] ?? null,
                        'customer_phone' => $validated['phone'] ?? null,
                        'created_at' => time(),
                    ]);

                    return $this->idempotencyReturn($idempotencyKey, redirect()->route('checkout.pix', ['token' => $pixToken]));
                } catch (\Throwable $e) {
                    $this->rollbackFailedOrder($order, $e);
                    if ($request->expectsJson()) {
                        return response()->json([
                            'success' => false,
                            'message' => $e->getMessage() ?: 'Não foi possível gerar o PIX automático. Tente novamente.',
                        ], 422);
                    }

                    return back()->with('error', $e->getMessage() ?: 'Não foi possível gerar o PIX automático. Tente novamente.');
                }
            }

            if ($gatewaySlug === 'efi') {
                $credential = GatewayCredential::resolveForPayment($tenantId, 'efi');
                if (! $credential) {
                    if ($request->expectsJson()) {
                        return response()->json(['message' => 'Gateway Efí não configurado para PIX automático.'], 422);
                    }

                    return back()->withErrors(['payment_method' => 'Gateway Efí não configurado para PIX automático.']);
                }
                $credentials = $credential->getDecryptedCredentials();
                if (empty($credentials['certificate_path']) || empty($credentials['pix_key'])) {
                    if ($request->expectsJson()) {
                        return response()->json(['message' => 'Efí: certificado ou chave PIX não configurados.'], 422);
                    }

                    return back()->withErrors(['payment_method' => 'Efí: certificado ou chave PIX não configurados.']);
                }

                $order = $createOrderAndItems(array_merge($orderPayload, [
                    'status' => 'pending',
                    'gateway' => null,
                    'gateway_id' => null,
                    'payment_method' => 'pix_auto',
                    'metadata' => array_merge($orderMetadata, ['checkout_payment_method' => 'pix_auto']),
                ]));
                $order->load('orderItems');
                event(new OrderPending($order));

                $base = 'pixauto'.$order->id;
                $txid = $base.Str::random(max(26 - strlen($base), 10));
                $txid = substr($txid, 0, 35);
                $consumer = CheckoutPaymentConsumer::build($validated, $order->id);

                try {
                    $efiRecorrente = new EfiPixRecorrenteService($credentials);
                    $locRec = $efiRecorrente->createLocRec();
                    $locId = (int) $locRec['id'];

                    $cob = $efiRecorrente->createCobWithTxid(
                        $txid,
                        (float) $totalAmount,
                        $consumer,
                        $credentials['pix_key'],
                        'Assinatura PIX automático - Pedido #'.$order->id
                    );

                    $criacao = now();
                    $dataInicial = $periodEnd
                        ? $periodEnd->format('Y-m-d')
                        : $criacao->copy()->addMonth()->format('Y-m-d');
                    if ($dataInicial === $criacao->format('Y-m-d')) {
                        $dataInicial = $criacao->copy()->addDay()->format('Y-m-d');
                    }
                    $dataFinal = $periodEnd
                        ? $periodEnd->copy()->addYears(10)->format('Y-m-d')
                        : now()->addYears(10)->format('Y-m-d');

                    $contrato = str_pad((string) $order->id, 8, '0', STR_PAD_LEFT);
                    $objeto = mb_substr(preg_replace('/[^\p{L}\p{N}\s\.\-]/u', '', $product->name ?? 'Assinatura'), 0, 140) ?: 'Assinatura';
                    $rec = $efiRecorrente->createRecurrence(
                        $locId,
                        $txid,
                        $consumer,
                        (float) $totalAmount,
                        $dataInicial,
                        $dataFinal,
                        $contrato,
                        $objeto
                    );
                    $idRec = $rec['idRec'] ?? null;

                    $order->update([
                        'gateway' => 'efi',
                        'gateway_id' => $txid,
                        'metadata' => array_merge($order->metadata ?? [], ['efi_pix_auto_id_rec' => $idRec]),
                    ]);

                    $copyPaste = $cob['copy_paste'] ?? null;
                    $qrcodeImage = $cob['qrcode'] ?? null;
                    if ($idRec !== null) {
                        try {
                            $recData = $efiRecorrente->getRecurrence($idRec, $txid);
                            $dadosQR = $recData['dadosQR'] ?? [];
                            $recCopyPaste = $dadosQR['pixCopiaECola'] ?? null;
                            if ($recCopyPaste !== null && $recCopyPaste !== '') {
                                $copyPaste = $recCopyPaste;
                                $recImagem = $dadosQR['imagemQrcode'] ?? null;
                                if ($recImagem !== null && $recImagem !== '') {
                                    $qrcodeImage = $recImagem;
                                } else {
                                    $qrcodeImage = null;
                                }
                            }
                        } catch (\Throwable $e) {
                            \Log::warning('CheckoutController pix_auto: falha ao obter QR da recorrência', ['idRec' => $idRec, 'error' => $e->getMessage()]);
                        }
                    }

                    $updateCheckoutSession($order);
                    event(new PixGenerated($order, [
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                        'transaction_id' => $txid,
                    ]));

                    if ($request->expectsJson()) {
                        return $this->idempotencyReturn($idempotencyKey, response()->json([
                            'success' => true,
                            'payment_method' => 'pix_auto',
                            'order_id' => $order->id,
                            'qrcode' => $qrcodeImage,
                            'copy_paste' => $copyPaste ?? '',
                            'transaction_id' => $txid,
                        ]));
                    }
                    $redirectUrl = $product->checkout_config['redirect_after_purchase'] ?? null;
                    $redirectUrl = ! empty($redirectUrl) && is_string($redirectUrl) ? $redirectUrl : null;
                    $pixToken = Str::random(32);
                    session()->put('pix_display.'.$pixToken, [
                        'order_id' => $order->id,
                        'checkout_session_token' => $validated['checkout_session_token'] ?? null,
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                        'amount' => $totalAmount,
                        'product_name' => $product->name,
                        'checkout_slug' => $checkoutSlug,
                        'redirect_after_purchase' => $redirectUrl,
                        'customer_name' => $validated['name'] ?? null,
                        'customer_email' => $validated['email'] ?? null,
                        'customer_phone' => $validated['phone'] ?? null,
                        'created_at' => time(),
                    ]);

                    return $this->idempotencyReturn($idempotencyKey, redirect()->route('checkout.pix', ['token' => $pixToken]));
                } catch (\Throwable $e) {
                    $this->rollbackFailedOrder($order, $e);
                    if ($request->expectsJson()) {
                        return response()->json([
                            'success' => false,
                            'message' => $e->getMessage() ?: 'Não foi possível gerar o PIX automático. Tente novamente.',
                        ], 422);
                    }

                    return back()->with('error', $e->getMessage() ?: 'Não foi possível gerar o PIX automático. Tente novamente.');
                }
            }

            if ($gatewaySlug === 'versell') {
                $credential = GatewayCredential::resolveForPayment($tenantId, 'versell');
                if (! $credential) {
                    if ($request->expectsJson()) {
                        return response()->json(['message' => 'Versell não configurada para PIX automático.'], 422);
                    }

                    return back()->withErrors(['payment_method' => 'Versell não configurada para PIX automático.']);
                }
                $credentials = $credential->getDecryptedCredentials();
                if (! VersellCredentials::isCashInReady($credentials)) {
                    if ($request->expectsJson()) {
                        return response()->json(['message' => 'Versell: credenciais Cash In incompletas para PIX automático.'], 422);
                    }

                    return back()->withErrors(['payment_method' => 'Versell: credenciais Cash In incompletas para PIX automático.']);
                }

                $order = $createOrderAndItems(array_merge($orderPayload, [
                    'status' => 'pending',
                    'gateway' => null,
                    'gateway_id' => null,
                    'payment_method' => 'pix_auto',
                    'metadata' => array_merge($orderMetadata, ['checkout_payment_method' => 'pix_auto']),
                ]));
                $order->load('orderItems');
                event(new OrderPending($order));

                $base = 'pixauto'.$order->id;
                $txid = $base.Str::random(max(26 - strlen($base), 10));
                $txid = substr($txid, 0, 35);
                $consumer = CheckoutPaymentConsumer::build($validated, $order->id);
                $pixKey = (string) (VersellCredentials::apiBlock($credentials, VersellCredentials::API_CASH_IN)['pix_key'] ?? '');

                try {
                    $versellRecorrente = new VersellPixRecorrenteService($credentials);
                    $locRec = $versellRecorrente->createLocRec();
                    $locId = (int) $locRec['id'];

                    $cob = $versellRecorrente->createCobWithTxid(
                        $txid,
                        (float) $totalAmount,
                        $consumer,
                        $pixKey,
                        'Assinatura PIX automático - Pedido #'.$order->id
                    );

                    $criacao = now();
                    $dataInicial = $periodEnd
                        ? $periodEnd->format('Y-m-d')
                        : $criacao->copy()->addMonth()->format('Y-m-d');
                    if ($dataInicial === $criacao->format('Y-m-d')) {
                        $dataInicial = $criacao->copy()->addDay()->format('Y-m-d');
                    }
                    $dataFinal = $periodEnd
                        ? $periodEnd->copy()->addYears(10)->format('Y-m-d')
                        : now()->addYears(10)->format('Y-m-d');

                    $contrato = str_pad((string) $order->id, 8, '0', STR_PAD_LEFT);
                    $objeto = mb_substr(preg_replace('/[^\p{L}\p{N}\s\.\-]/u', '', $product->name ?? 'Assinatura'), 0, 140) ?: 'Assinatura';
                    $rec = $versellRecorrente->createRecurrence(
                        $locId,
                        $txid,
                        $consumer,
                        (float) $totalAmount,
                        $dataInicial,
                        $dataFinal,
                        $contrato,
                        $objeto
                    );
                    $idRec = $rec['idRec'] ?? null;

                    $order->update([
                        'gateway' => 'versell',
                        'gateway_id' => $txid,
                        'metadata' => array_merge($order->metadata ?? [], ['versell_pix_auto_id_rec' => $idRec]),
                    ]);

                    $copyPaste = $cob['copy_paste'] ?? null;
                    $qrcodeImage = $cob['qrcode'] ?? null;
                    if ($idRec !== null) {
                        try {
                            $recData = $versellRecorrente->getRecurrence($idRec, $txid);
                            $dadosQR = $recData['dadosQR'] ?? [];
                            $recCopyPaste = $dadosQR['pixCopiaECola'] ?? null;
                            if ($recCopyPaste !== null && $recCopyPaste !== '') {
                                $copyPaste = $recCopyPaste;
                                $recImagem = $dadosQR['imagemQrcode'] ?? null;
                                if ($recImagem !== null && $recImagem !== '') {
                                    $qrcodeImage = $recImagem;
                                } else {
                                    $qrcodeImage = null;
                                }
                            }
                        } catch (\Throwable $e) {
                            \Log::warning('CheckoutController pix_auto versell: falha ao obter QR da recorrência', ['idRec' => $idRec, 'error' => $e->getMessage()]);
                        }
                    }

                    $updateCheckoutSession($order);
                    event(new PixGenerated($order, [
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                        'transaction_id' => $txid,
                    ]));

                    if ($request->expectsJson()) {
                        return $this->idempotencyReturn($idempotencyKey, response()->json([
                            'success' => true,
                            'payment_method' => 'pix_auto',
                            'order_id' => $order->id,
                            'qrcode' => $qrcodeImage,
                            'copy_paste' => $copyPaste ?? '',
                            'transaction_id' => $txid,
                        ]));
                    }
                    $redirectUrl = $product->checkout_config['redirect_after_purchase'] ?? null;
                    $redirectUrl = ! empty($redirectUrl) && is_string($redirectUrl) ? $redirectUrl : null;
                    $pixToken = Str::random(32);
                    session()->put('pix_display.'.$pixToken, [
                        'order_id' => $order->id,
                        'checkout_session_token' => $validated['checkout_session_token'] ?? null,
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                        'amount' => $totalAmount,
                        'product_name' => $product->name,
                        'checkout_slug' => $checkoutSlug,
                        'redirect_after_purchase' => $redirectUrl,
                        'customer_name' => $validated['name'] ?? null,
                        'customer_email' => $validated['email'] ?? null,
                        'customer_phone' => $validated['phone'] ?? null,
                        'created_at' => time(),
                    ]);

                    return $this->idempotencyReturn($idempotencyKey, redirect()->route('checkout.pix', ['token' => $pixToken]));
                } catch (\Throwable $e) {
                    $this->rollbackFailedOrder($order, $e);
                    if ($request->expectsJson()) {
                        return response()->json([
                            'success' => false,
                            'message' => $e->getMessage() ?: 'Não foi possível gerar o PIX automático. Tente novamente.',
                        ], 422);
                    }

                    return back()->with('error', $e->getMessage() ?: 'Não foi possível gerar o PIX automático. Tente novamente.');
                }
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Gateway PIX automático não suportado.'], 422);
            }

            return back()->withErrors(['payment_method' => 'Gateway PIX automático não suportado.']);
        }

        if (in_array($paymentMethod, ['card', 'apple_pay', 'google_pay'], true)) {
            $initialCheckoutPm = match ($paymentMethod) {
                'apple_pay' => 'apple_pay',
                'google_pay' => 'google_pay',
                default => 'card',
            };
            $order = $createOrderAndItems(array_merge($orderPayload, [
                'status' => 'pending',
                'gateway' => null,
                'gateway_id' => null,
                'payment_method' => 'card',
                'metadata' => array_merge($orderMetadata, ['checkout_payment_method' => $initialCheckoutPm]),
            ]));
            $order->load('orderItems');
            event(new OrderPending($order));
            $paymentServiceCard = app(PaymentService::class);
            $firstCardGwForSdk = $paymentServiceCard->getFirstAvailableGatewayForMethod($product->tenant_id, 'card', $product);
            if ($firstCardGwForSdk === 'cajupay') {
                $nonce = Str::random(40);
                $wallet = match ($paymentMethod) {
                    'apple_pay' => 'apple_pay',
                    'google_pay' => 'google_pay',
                    default => isset($validated['cajupay_wallet']) && is_string($validated['cajupay_wallet'])
                        ? strtolower(trim($validated['cajupay_wallet']))
                        : 'card',
                };
                if (! in_array($wallet, ['card', 'apple_pay', 'google_pay'], true)) {
                    $wallet = 'card';
                }
                $pme = Product::resolvedPaymentMethodsEnabled($product, $offer, $plan);
                if ($wallet === 'apple_pay' && empty($pme['apple_pay'])) {
                    $wallet = 'card';
                }
                if ($wallet === 'google_pay' && empty($pme['google_pay'])) {
                    $wallet = 'card';
                }
                $meta = array_merge(is_array($order->metadata) ? $order->metadata : [], [
                    'checkout_payment_method' => $wallet === 'card' ? 'card' : $wallet,
                    'cajupay_sdk_nonce' => $nonce,
                    'cajupay_wallet' => $wallet,
                ]);
                $order->update(['metadata' => $meta]);
                $updateCheckoutSession($order);
                $wantsJsonCaju = $request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest';
                if ($wantsJsonCaju) {
                    $payloadCaju = [
                        'success' => true,
                        'payment_method' => 'card',
                        'order_id' => $order->id,
                        'status' => 'pending',
                        'message' => 'Conclua o pagamento para finalizar a compra.',
                        'cajupay_sdk' => true,
                        'cajupay_sdk_nonce' => $nonce,
                        'cajupay_wallet' => $wallet,
                        'sdk_base_url' => rtrim((string) config('services.cajupay.base_url', 'https://api.cajupay.com.br'), '/'),
                        'redirect_url' => null,
                    ];

                    return $this->idempotencyReturn($idempotencyKey, response()->json($payloadCaju));
                }
                if ($checkoutSlug !== '') {
                    return $this->idempotencyReturn($idempotencyKey, redirect()->route('checkout.show', ['slug' => $checkoutSlug])->with([
                        'success' => 'Pedido criado. Conclua o pagamento na próxima etapa.',
                        'cajupay_sdk_pending' => [
                            'order_id' => $order->id,
                            'cajupay_sdk_nonce' => $nonce,
                            'cajupay_wallet' => $wallet,
                            'sdk_base_url' => rtrim((string) config('services.cajupay.base_url', 'https://api.cajupay.com.br'), '/'),
                        ],
                    ]));
                }

                return $this->idempotencyReturn($idempotencyKey, back()->with('success', 'Pedido criado. Use o checkout JSON para concluir o pagamento.'));
            }
            $card = CheckoutCardContract::fromRequest($validated);
            if (isset($validated['card_holder_name'], $validated['card_number'], $validated['card_expiry_month'], $validated['card_expiry_year'], $validated['card_ccv'])) {
                $card['card_holder_name'] = trim((string) $validated['card_holder_name']);
                $card['card_number'] = trim((string) $validated['card_number']);
                $card['card_expiry_month'] = trim((string) $validated['card_expiry_month']);
                $card['card_expiry_year'] = trim((string) $validated['card_expiry_year']);
                $card['card_ccv'] = trim((string) $validated['card_ccv']);
            }
            $checkoutConfig = $offer && $offer->checkout_config !== null && $offer->checkout_config !== []
                ? array_replace_recursive(Product::defaultCheckoutConfig(), $offer->checkout_config)
                : ($plan && $plan->checkout_config !== null && $plan->checkout_config !== []
                    ? array_replace_recursive(Product::defaultCheckoutConfig(), $plan->checkout_config)
                    : ($product->checkout_config ?? []));
            $cardInstallments = $checkoutConfig['card_installments'] ?? ['enabled' => false, 'max' => 1];
            $resolvedInstallments = PlatformCardInstallments::forProductConfig(
                is_array($cardInstallments) ? $cardInstallments : [],
                $plan !== null
            );
            $installmentsEnabled = $resolvedInstallments['enabled'];
            $maxInstallments = $resolvedInstallments['max'];
            $requestedInstallments = (int) ($validated['installments'] ?? 1);
            $installments = CardInstallments::clamp(
                $requestedInstallments,
                $installmentsEnabled,
                $maxInstallments,
                (float) $order->amount,
                $plan !== null
            );
            $card = CardInstallments::applyToCardPayload($card, $installments);
            $order->update([
                'metadata' => array_merge($order->metadata ?? [], [
                    'installments' => $installments,
                ]),
            ]);
            $card['currency'] = strtolower($currency);
            if ($checkoutSlug !== '') {
                $card['return_url'] = url()->route('checkout.show', ['slug' => $checkoutSlug]);
            }
            $consumer = CheckoutPaymentConsumer::build($validated, $order->id);
            $zipCode = preg_replace('/\D/', '', $validated['address_zipcode'] ?? '');
            if (strlen($zipCode) >= 8) {
                $consumer['address'] = [
                    'zip_code' => substr($zipCode, 0, 8),
                    'street_name' => trim((string) ($validated['address_street'] ?? '')),
                    'street_number' => trim((string) ($validated['address_number'] ?? '')),
                    'neighborhood' => trim((string) ($validated['address_neighborhood'] ?? '')),
                    'city' => trim((string) ($validated['address_city'] ?? '')),
                    'federal_unit' => strtoupper(substr(trim((string) ($validated['address_state'] ?? '')), 0, 2)),
                ];
            }
            try {
                $paymentService = app(PaymentService::class);
                $cardResult = $paymentService->createCardPayment($order, $product, $consumer, $card);
                $status = is_string($cardResult['status'] ?? null)
                    ? strtolower(trim((string) $cardResult['status']))
                    : null;
                // Se o create não fechou status, tenta reconciliar na API (MP cartão).
                if ($order->gateway === 'mercadopago'
                    && ! in_array($status, ['paid', 'settled', 'approved', 'completed', 'rejected', 'refused', 'cancelled', 'canceled', 'failed'], true)
                    && ! empty($cardResult['transaction_id'])
                ) {
                    try {
                        app(\App\Services\MercadoPago\MercadoPagoCheckoutCompletionService::class)
                            ->tryCompleteFromPaymentApi($order->fresh());
                        $order->refresh();
                        if ($order->status === 'completed') {
                            $status = 'approved';
                        } elseif ($order->status === 'rejected') {
                            $status = 'rejected';
                        }
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
                $isApproved = in_array($status, ['paid', 'settled', 'approved', 'completed'], true);
                $isRejected = in_array($status, ['rejected', 'refused', 'cancelled', 'canceled', 'failed'], true);
                $alreadyCompleted = $order->fresh()->status === 'completed';

                if ($isApproved && ! $alreadyCompleted) {
                    $updateCheckoutSession($order);
                    $order->update(['status' => 'completed']);
                    $order->load('orderItems');
                    $grantAccessForOrder($order);
                    if ($plan) {
                        $subscription = Subscription::create([
                            'tenant_id' => $tenantId,
                            'user_id' => $user->id,
                            'product_id' => $product->id,
                            'subscription_plan_id' => $plan->id,
                            'status' => Subscription::STATUS_ACTIVE,
                            'current_period_start' => $periodStart,
                            'current_period_end' => $periodEnd,
                        ]);
                        event(new SubscriptionCreated($subscription));
                        $this->attachStripeSavedPaymentMethodForSubscription($subscription, $order, $card, $tenantId, $user->id);
                    }
                    event(new OrderCompleted($order));
                } elseif ($isApproved && $alreadyCompleted) {
                    $updateCheckoutSession($order->fresh());
                } elseif ($isRejected && $order->status === 'pending') {
                    $order->update(['status' => 'rejected']);
                    event(new OrderRejected($order));
                }

                $config = $this->getOrderCheckoutConfigForProcess($order, $product, $offer, $plan);
                $redirectUrl = null;
                if ($isApproved) {
                    $upsell = $config['upsell'] ?? [];
                    if (! empty($upsell['enabled']) && ! empty($upsell['products']) && is_array($upsell['products'])) {
                        $upsellToken = Str::random(64);
                        Cache::put('upsell_token.'.$upsellToken, ['order_id' => $order->id, 'gateway' => $order->gateway], now()->addMinutes(60));
                        $redirectUrl = route('checkout.upsell', ['token' => $upsellToken]);
                    } else {
                        $customRedirect = $config['redirect_after_purchase'] ?? null;
                        if (is_string($customRedirect) && trim($customRedirect) !== '') {
                            $redirectUrl = SafeUrl::normalizeCheckoutRedirect($customRedirect);
                        }
                        if ($redirectUrl === null) {
                            $next = ($order->user_id && User::find($order->user_id)) ? 'member-area' : 'login';
                            $redirectUrl = route('checkout.thank-you', ['order_id' => $order->id, 'next' => $next]);
                        }
                    }
                }
                $wantsJson = $request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest';
                if ($wantsJson) {
                    if ($isRejected) {
                        return $this->idempotencyReturn($idempotencyKey, response()->json([
                            'success' => false,
                            'payment_method' => 'card',
                            'order_id' => $order->id,
                            'status' => $status ?? 'rejected',
                            'message' => 'Pagamento recusado. Verifique os dados do cartão e tente novamente.',
                        ], 422));
                    }

                    $json = [
                        'success' => $isApproved,
                        'payment_method' => 'card',
                        'order_id' => $order->id,
                        'status' => $status,
                        'message' => $isApproved
                            ? 'Pagamento aprovado.'
                            : 'Pagamento em processamento. Aguarde a confirmação.',
                        'redirect_url' => $isApproved ? $redirectUrl : null,
                    ];
                    if ($status === 'requires_action' && ! empty($cardResult['client_secret'])) {
                        $json['success'] = true;
                        $json['requires_action'] = true;
                        $json['client_secret'] = $cardResult['client_secret'];
                    }

                    return $this->idempotencyReturn($idempotencyKey, response()->json($json, $isApproved || $status === 'requires_action' ? 200 : 202));
                }
                if ($isRejected) {
                    return $this->idempotencyReturn(
                        $idempotencyKey,
                        back()->with('error', 'Pagamento recusado. Verifique os dados do cartão e tente novamente.')
                    );
                }
                if ($redirectUrl !== null) {
                    if (str_starts_with($redirectUrl, 'http') && ! str_starts_with($redirectUrl, request()->getSchemeAndHttpHost())) {
                        return $this->idempotencyReturn($idempotencyKey, redirect()->away($redirectUrl)->with('success', 'Compra concluída.'));
                    }

                    return $this->idempotencyReturn($idempotencyKey, redirect()->to($redirectUrl)->with('success', 'Compra concluída.'));
                }
                if ($checkoutSlug !== '') {
                    return $this->idempotencyReturn($idempotencyKey, redirect()->route('checkout.show', ['slug' => $checkoutSlug])->with('success', 'Pagamento com cartão recebido. Você receberá a confirmação por e-mail.'));
                }

                return $this->idempotencyReturn($idempotencyKey, back()->with('success', 'Pagamento com cartão recebido. Você receberá a confirmação por e-mail.'));
            } catch (\Throwable $e) {
                $this->rollbackFailedOrder($order, $e);
                if ($request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage() ?: 'Não foi possível processar o pagamento. Tente novamente.',
                    ], 422);
                }

                return back()->with('error', $e->getMessage() ?: 'Não foi possível processar o pagamento. Tente novamente.');
            }
        }

        if ($paymentMethod === 'boleto') {
            $order = $createOrderAndItems(array_merge($orderPayload, [
                'status' => 'pending',
                'gateway' => null,
                'gateway_id' => null,
                'payment_method' => 'boleto',
                'metadata' => array_merge($orderMetadata, ['checkout_payment_method' => 'boleto']),
            ]));
            $order->load('orderItems');
            event(new OrderPending($order));
            try {
                $paymentService = app(PaymentService::class);
                $consumer = CheckoutPaymentConsumer::build($validated, $order->id);
                $zipCode = preg_replace('/\D/', '', $validated['address_zipcode'] ?? '');
                if (strlen($zipCode) >= 8) {
                    $consumer['address'] = [
                        'zip_code' => substr($zipCode, 0, 8),
                        'street_name' => trim((string) ($validated['address_street'] ?? '')),
                        'street_number' => trim((string) ($validated['address_number'] ?? '')),
                        'neighborhood' => trim((string) ($validated['address_neighborhood'] ?? '')),
                        'city' => trim((string) ($validated['address_city'] ?? '')),
                        'federal_unit' => strtoupper(substr(trim((string) ($validated['address_state'] ?? '')), 0, 2)),
                    ];
                }
                $boletoResult = $paymentService->createBoletoPayment($order, $product, $consumer);
                $boletoData = [
                    'amount' => $boletoResult['amount'] ?? $totalAmount,
                    'expire_at' => $boletoResult['expire_at'] ?? null,
                    'barcode' => $boletoResult['barcode'] ?? null,
                    'pdf_url' => $boletoResult['pdf_url'] ?? null,
                ];
                $updateCheckoutSession($order);
                event(new BoletoGenerated($order, $boletoData));
                $redirectUrl = $product->checkout_config['redirect_after_purchase'] ?? null;
                $redirectUrl = ! empty($redirectUrl) && is_string($redirectUrl) ? $redirectUrl : null;
                $boletoToken = Str::random(32);
                $amountFormatted = 'R$ '.number_format((float) ($boletoResult['amount'] ?? $totalAmount), 2, ',', '.');
                session()->put('boleto_display.'.$boletoToken, [
                    'order_id' => $order->id,
                    'checkout_session_token' => $validated['checkout_session_token'] ?? null,
                    'amount' => $boletoResult['amount'] ?? $totalAmount,
                    'amount_formatted' => $amountFormatted,
                    'expire_at' => $boletoResult['expire_at'] ?? null,
                    'barcode' => $boletoResult['barcode'] ?? null,
                    'pdf_url' => $boletoResult['pdf_url'] ?? null,
                    'product_name' => $product->name,
                    'checkout_slug' => $checkoutSlug,
                    'redirect_after_purchase' => $redirectUrl,
                    'customer_name' => $validated['name'] ?? null,
                    'customer_email' => $validated['email'] ?? null,
                    'customer_phone' => $validated['phone'] ?? null,
                    'created_at' => time(),
                ]);
                if ($request->expectsJson()) {
                    return $this->idempotencyReturn($idempotencyKey, response()->json([
                        'success' => true,
                        'payment_method' => 'boleto',
                        'order_id' => $order->id,
                        'redirect_url' => route('checkout.boleto', ['token' => $boletoToken]),
                    ]));
                }

                return $this->idempotencyReturn($idempotencyKey, redirect()->route('checkout.boleto', ['token' => $boletoToken]));
            } catch (\Throwable $e) {
                $this->rollbackFailedOrder($order, $e);
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => $e->getMessage() ?: 'Não foi possível gerar o boleto. Tente novamente.',
                    ], 422);
                }

                return back()->with('error', $e->getMessage() ?: 'Não foi possível gerar o boleto. Tente novamente.');
            }
        }

        if ($request->expectsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
            return response()->json([
                'message' => 'Método de pagamento inválido ou não disponível.',
                'errors' => ['payment_method' => ['Selecione um método de pagamento válido.']],
            ], 422);
        }

        return back()->withErrors(['payment_method' => 'Selecione um método de pagamento válido.']);
    }

    /**
     * Config de checkout efetiva no process (já temos product, offer, plan).
     */
    private function getOrderCheckoutConfigForProcess(Order $order, Product $product, ?ProductOffer $offer, ?SubscriptionPlan $plan): array
    {
        if ($plan && $plan->checkout_config) {
            return array_replace_recursive(Product::defaultCheckoutConfig(), $plan->checkout_config);
        }
        if ($offer && $offer->checkout_config) {
            return array_replace_recursive(Product::defaultCheckoutConfig(), $offer->checkout_config);
        }

        return $product->checkout_config;
    }

    private const PIX_EXPIRY_SECONDS = 900; // 15 min

    /**
     * Página de PIX gerado (dados vindos da sessão, identificado por token aleatório).
     *
     * @return \Illuminate\Http\RedirectResponse|Response
     */
    public function pixPage(Request $request)
    {
        $token = $request->query('token');
        if (! $token || ! is_string($token)) {
            return redirect()->route('login')->with('error', 'Link inválido ou expirado.');
        }

        $stored = session('pix_display.'.$token);
        if (! is_array($stored)) {
            return redirect()->route('login')->with('error', 'Código PIX expirado ou inválido. Gere um novo PIX.');
        }

        $orderId = (int) ($stored['order_id'] ?? 0);
        $order = Order::with('product', 'productOffer', 'subscriptionPlan')->find($orderId);
        if (! $order || $order->status !== 'pending') {
            session()->forget('pix_display.'.$token);
            $slug = $order ? $order->getCheckoutSlug() : null;
            $redirect = $slug ? redirect()->route('checkout.show', ['slug' => $slug]) : redirect()->route('login');

            return $redirect->with('error', 'Código PIX expirado ou inválido. Gere um novo PIX.');
        }

        $createdAt = (int) ($stored['created_at'] ?? 0);
        if ($createdAt + self::PIX_EXPIRY_SECONDS < time()) {
            session()->forget('pix_display.'.$token);

            return redirect()->route('checkout.show', ['slug' => $order->getCheckoutSlug()])
                ->with('error', 'Código PIX expirado. Gere um novo PIX.');
        }

        $amount = (float) ($stored['amount'] ?? 0);
        $amountFormatted = 'R$ '.number_format($amount, 2, ',', '.');

        $conversionPixels = AffiliateConversionPixels::forOrder($order);

        $checkoutSessionToken = (string) ($stored['checkout_session_token'] ?? CheckoutSession::where('order_id', $orderId)->orderByDesc('id')->value('session_token') ?? '');

        return Inertia::render('Checkout/Pix', [
            'token' => $token,
            'order_id' => $orderId,
            'checkout_session_token' => $checkoutSessionToken,
            'qrcode' => $stored['qrcode'] ?? null,
            'copy_paste' => $stored['copy_paste'] ?? null,
            'amount' => $amount,
            'amount_formatted' => $amountFormatted,
            'product_name' => $stored['product_name'] ?? $order->product->name,
            'checkout_slug' => $stored['checkout_slug'] ?? $order->getCheckoutSlug(),
            'redirect_after_purchase' => $stored['redirect_after_purchase'] ?? null,
            'customer_name' => $stored['customer_name'] ?? null,
            'customer_email' => $stored['customer_email'] ?? null,
            'customer_phone' => $stored['customer_phone'] ?? null,
            'created_at' => $createdAt,
            'expiry_seconds' => self::PIX_EXPIRY_SECONDS,
            'conversion_pixels' => $conversionPixels,
        ]);
    }

    /**
     * Retorno do portal white-label Lina (redirect assinado).
     * Query: paymentLinkId (Lina) + assinatura Laravel em order.
     */
    public function linaReturn(Request $request, Order $order): RedirectResponse|Response
    {
        if (! $request->hasValidSignatureWhileIgnoring([
            'paymentLinkId',
            'payment_link_id',
            'paymentRequestId',
            'payment_request_id',
            'paymentId',
            'payment_id',
            'id',
            'error',
            'error_description',
            'state',
        ])) {
            abort(403, 'Link de retorno inválido ou expirado.');
        }

        $paymentLinkId = $request->query('paymentLinkId')
            ?? $request->query('payment_link_id')
            ?? $request->query('paymentRequestId')
            ?? $request->query('payment_request_id');
        $paymentLinkId = is_string($paymentLinkId) ? trim($paymentLinkId) : null;

        try {
            $result = app(LinaOpenxCheckoutService::class)->handleReturn($order, $paymentLinkId);
            $order = $result['order'];
        } catch (\Throwable $e) {
            Log::warning('CheckoutController linaReturn: falha', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }

        $order->refresh();
        if ($order->status === 'completed') {
            $config = $this->getOrderCheckoutConfig($order);
            $upsell = $config['upsell'] ?? [];
            if (! empty($upsell['enabled']) && ! empty($upsell['products']) && is_array($upsell['products'])) {
                $upsellToken = Str::random(64);
                Cache::put('upsell_token.'.$upsellToken, [
                    'order_id' => $order->id,
                    'gateway' => 'open_finance',
                ], now()->addMinutes(60));

                return redirect()->route('checkout.upsell', ['token' => $upsellToken]);
            }
            $customRedirect = $config['redirect_after_purchase'] ?? null;
            if (is_string($customRedirect) && trim($customRedirect) !== '') {
                $url = SafeUrl::normalizeCheckoutRedirect($customRedirect);
                if ($url !== null) {
                    return redirect()->away($url);
                }
            }
            $next = ($order->user_id && User::find($order->user_id)) ? 'member-area' : 'login';

            return redirect()->route('checkout.thank-you', ['order_id' => $order->id, 'next' => $next]);
        }

        $waitToken = Str::random(32);
        session()->put('lina_display.'.$waitToken, [
            'order_id' => $order->id,
            'checkout_session_token' => CheckoutSession::where('order_id', $order->id)->orderByDesc('id')->value('session_token'),
            'amount' => (float) $order->amount,
            'product_name' => $order->product?->name,
            'checkout_slug' => $order->getCheckoutSlug(),
            'redirect_after_purchase' => $this->getOrderCheckoutConfig($order)['redirect_after_purchase'] ?? null,
            'customer_name' => $order->user?->name,
            'customer_email' => $order->email,
            'customer_phone' => $order->phone,
            'created_at' => time(),
            'transaction_id' => $order->gateway_id,
        ]);

        return redirect()->route('checkout.lina.wait', ['token' => $waitToken]);
    }

    /**
     * Página de espera Open Finance (poll de status após retorno ou se o portal ainda processa).
     *
     * @return RedirectResponse|Response
     */
    public function linaWaitPage(Request $request)
    {
        $token = $request->query('token');
        if (! $token || ! is_string($token)) {
            return redirect()->route('login')->with('error', 'Link inválido ou expirado.');
        }

        $stored = session('lina_display.'.$token);
        if (! is_array($stored)) {
            return redirect()->route('login')->with('error', 'Sessão Open Finance expirada. Tente novamente no checkout.');
        }

        $orderId = (int) ($stored['order_id'] ?? 0);
        $order = Order::with('product', 'productOffer', 'subscriptionPlan', 'user')->find($orderId);
        if (! $order) {
            session()->forget('lina_display.'.$token);

            return redirect()->route('login')->with('error', 'Pedido não encontrado.');
        }

        if ($order->status === 'completed') {
            return redirect()->route('checkout.thank-you', [
                'order_id' => $order->id,
                'next' => ($order->user_id && User::find($order->user_id)) ? 'member-area' : 'login',
            ]);
        }

        $amount = (float) ($stored['amount'] ?? $order->amount);
        $conversionPixels = AffiliateConversionPixels::forOrder($order);
        $checkoutSessionToken = (string) ($stored['checkout_session_token'] ?? CheckoutSession::where('order_id', $orderId)->orderByDesc('id')->value('session_token') ?? '');

        return Inertia::render('Checkout/OpenFinance', [
            'token' => $token,
            'order_id' => $orderId,
            'checkout_session_token' => $checkoutSessionToken,
            'amount' => $amount,
            'amount_formatted' => 'R$ '.number_format($amount, 2, ',', '.'),
            'product_name' => $stored['product_name'] ?? $order->product?->name,
            'checkout_slug' => $stored['checkout_slug'] ?? $order->getCheckoutSlug(),
            'redirect_after_purchase' => $stored['redirect_after_purchase'] ?? null,
            'customer_name' => $stored['customer_name'] ?? $order->user?->name,
            'customer_email' => $stored['customer_email'] ?? $order->email,
            'customer_phone' => $stored['customer_phone'] ?? $order->phone,
            'created_at' => (int) ($stored['created_at'] ?? time()),
            'expiry_seconds' => 1800,
            'status' => $order->status,
            'conversion_pixels' => $conversionPixels,
        ]);
    }

    /**
     * Página de boleto gerado (dados vindos da sessão, identificado por token).
     *
     * @return \Illuminate\Http\RedirectResponse|Response
     */
    public function boletoPage(Request $request)
    {
        $token = $request->query('token');
        if (! $token || ! is_string($token)) {
            return redirect()->route('login')->with('error', 'Link inválido ou expirado.');
        }

        $stored = session('boleto_display.'.$token);
        if (! is_array($stored)) {
            return redirect()->route('login')->with('error', 'Boleto expirado ou inválido. Gere um novo boleto.');
        }

        $orderId = (int) ($stored['order_id'] ?? 0);
        $order = Order::with('product', 'productOffer', 'subscriptionPlan')->find($orderId);
        if (! $order || $order->status !== 'pending') {
            session()->forget('boleto_display.'.$token);
            $slug = $order ? $order->getCheckoutSlug() : null;
            $redirect = $slug ? redirect()->route('checkout.show', ['slug' => $slug]) : redirect()->route('login');

            return $redirect->with('error', 'Boleto expirado ou inválido. Gere um novo boleto.');
        }

        $conversionPixels = AffiliateConversionPixels::forOrder($order);

        $amount = (float) $order->amount;

        $checkoutSessionToken = (string) ($stored['checkout_session_token'] ?? CheckoutSession::where('order_id', $orderId)->orderByDesc('id')->value('session_token') ?? '');

        return Inertia::render('Checkout/Boleto', [
            'token' => $token,
            'order_id' => $orderId,
            'checkout_session_token' => $checkoutSessionToken,
            'amount' => $amount,
            'amount_formatted' => $stored['amount_formatted'] ?? ('R$ '.number_format($amount, 2, ',', '.')),
            'expire_at' => $stored['expire_at'] ?? null,
            'barcode' => $stored['barcode'] ?? '',
            'pdf_url' => $stored['pdf_url'] ?? null,
            'product_name' => $stored['product_name'] ?? $order->product->name,
            'checkout_slug' => $stored['checkout_slug'] ?? $order->getCheckoutSlug(),
            'redirect_after_purchase' => $stored['redirect_after_purchase'] ?? null,
            'customer_name' => $stored['customer_name'] ?? null,
            'customer_email' => $stored['customer_email'] ?? null,
            'customer_phone' => $stored['customer_phone'] ?? null,
            'conversion_pixels' => $conversionPixels,
        ]);
    }

    /**
     * Cria apenas a sessão pública na CajuPay (sem Order/User). A Order é materializada em
     * {@see cajupayConfirmOrder()} quando o cliente confirma os dados — alinhado ao fluxo draft da referência.
     */
    public function cajupaySession(Request $request): JsonResponse
    {
        $product = Product::where('id', $request->input('product_id'))->availableForPurchase()->first();
        if (! $product) {
            return response()->json(['message' => 'Produto não encontrado.'], 404);
        }

        $rules = [
            'product_id' => ['required', 'exists:products,id'],
            'product_offer_id' => ['nullable', 'exists:product_offers,id'],
            'subscription_plan_id' => ['nullable', 'exists:subscription_plans,id'],
            'order_bump_ids' => ['nullable', 'array'],
            'order_bump_ids.*' => ['integer', 'exists:product_order_bumps,id'],
            'payment_method' => ['required', 'string', 'in:card,apple_pay,google_pay'],
            'checkout_session_token' => ['required', 'string', 'max:64'],
            'website' => ['nullable', 'string', 'max:255'],
            '_hp' => ['nullable', 'string', 'max:255'],
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
            'display_currency' => ['nullable', 'string', 'in:BRL,USD,EUR'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
        ];
        foreach (CheckoutSession::TRACKING_FIELD_KEYS as $trackingKey) {
            $rules[$trackingKey] = ['nullable', 'string', 'max:2048'];
        }
        $rules['fbp'] = ['nullable', 'string', 'max:512'];
        $rules['fbc'] = ['nullable', 'string', 'max:512'];
        $rules['user_agent'] = ['nullable', 'string', 'max:2048'];
        $shippingHelper = app(CheckoutShippingHelper::class);
        if ($shippingHelper->productRequiresShipping($product)) {
            $rules = $shippingHelper->appendAddressRulesIfNeeded($product, $rules);
        }
        $validated = $request->validate($rules);

        $subscriptionPlanId = $request->filled('subscription_plan_id') ? (int) $request->input('subscription_plan_id') : null;
        $plan = $subscriptionPlanId
            ? SubscriptionPlan::where('id', $subscriptionPlanId)->where('product_id', $product->id)->first()
            : null;
        $allowedPaymentIds = array_column(
            app(PaymentService::class)->availablePaymentMethodsForCheckout($product, $plan, null),
            'id'
        );
        if (! in_array($validated['payment_method'], $allowedPaymentIds, true)) {
            return response()->json(['message' => 'Método de pagamento não disponível para este produto.'], 422);
        }

        $sessionEmail = strtolower(trim((string) $request->input('email', '')));
        if ($sessionEmail === '') {
            $sessionEmail = 'cajupay.'.substr(hash('sha256', $validated['checkout_session_token']), 0, 16).'@checkout.invalid';
        }
        app(CheckoutAbuseGuard::class)->assertCanProcess($request, $product, array_merge($validated, [
            'payment_method' => $validated['payment_method'],
            'email' => $sessionEmail,
        ]), false);

        try {
            $context = $this->calculateCajuPayDraftContext($request, $product, $validated);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage() ?: 'Não foi possível calcular o pedido.'], 422);
        }

        $totalAmount = (float) $context['total_amount'];

        $account = app(\App\Services\CajuPay\CajuPayAccountResolver::class)->resolveForTenant($product->tenant_id);
        if (! $account) {
            return response()->json(['message' => 'CajuPay não está conectado.'], 422);
        }
        $credentials = $account->getDecryptedCredentials();
        if (empty($credentials['public_key']) || empty($credentials['secret_key'])) {
            return response()->json(['message' => 'CajuPay: chaves de API não configuradas.'], 422);
        }

        $method = $validated['payment_method'];
        $defaultMethodMap = [
            'card' => 'card',
            'apple_pay' => 'apple_pay',
            'google_pay' => 'google_pay',
        ];
        $allowedMethods = [$method];
        if ($method === 'apple_pay' || $method === 'google_pay') {
            $allowedMethods[] = 'card';
        }
        $allowedMethods = array_values(array_unique($allowedMethods));

        $externalRef = (string) Str::uuid();

        try {
            $driver = GatewayRegistry::driver('cajupay');
            if (! $driver) {
                throw new \RuntimeException('Driver CajuPay não disponível.');
            }
            /** @var \App\Gateways\CajuPay\CajuPayDriver $driver */
            $sessionResult = $driver->createSdkCheckoutSession(
                $credentials,
                (int) round($totalAmount * 100),
                $product->name.' (draft '.substr($externalRef, 0, 8).')',
                $externalRef,
                [],
                $allowedMethods,
                $defaultMethodMap[$method] ?? 'card'
            );
        } catch (\Throwable $e) {
            Log::warning('CajuPaySession: falha ao criar sessão SDK', [
                'product_id' => $product->id,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage() ?: 'Falha ao iniciar pagamento na CajuPay.'], 422);
        }

        $availableMethods = $driver->getSessionAvailableMethods($sessionResult['token'], $credentials);

        $pollingToken = Str::random(32);
        Cache::put('cajupay_draft.'.$pollingToken, [
            'product_id' => $product->id,
            'product_offer_id' => $context['offer']?->id,
            'subscription_plan_id' => $context['plan']?->id,
            'order_bump_ids' => $context['order_bump_ids'],
            'payment_method' => $method,
            'coupon_code' => $context['coupon_code'],
            'total_amount' => $totalAmount,
            'base_amount' => (float) $context['base_amount'],
            'checkout_session_token' => $validated['checkout_session_token'] ?? null,
            'display_currency' => $validated['display_currency'] ?? 'BRL',
            'shipping_amount' => (float) ($context['shipping_amount'] ?? 0),
            'cajupay_token' => $sessionResult['token'],
            'checkout_session_id' => $sessionResult['checkout_session_id'],
            'tenant_id' => $product->tenant_id,
            'external_id' => $externalRef,
            'methods_available' => $availableMethods,
            'created_at' => time(),
        ], now()->addMinutes(30));

        return response()->json([
            'success' => true,
            'token' => $sessionResult['token'],
            'checkout_session_id' => $sessionResult['checkout_session_id'],
            'polling_token' => $pollingToken,
            'methods_available' => $availableMethods,
            'method_supported' => $availableMethods === [] ? null : in_array($method, $availableMethods, true),
            'sdk_base_url' => CajuPayBrowserSdk::apiBaseUrlForBrowser($request),
        ]);
    }

    /**
     * Materializa User + Order pendente a partir do draft em cache (fluxo CajuPay SDK).
     */
    public function cajupayConfirmOrder(Request $request): JsonResponse
    {
        $this->forgetInvalidMetricsSessionKey($request);

        $rules = [
            'polling_token' => ['required', 'string', 'size:32'],
            'website' => ['nullable', 'string', 'max:255'],
            '_hp' => ['nullable', 'string', 'max:255'],
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
            'email' => ['required', 'email'],
            'name' => ['nullable', 'string', 'max:255'],
            'cpf' => ['nullable', 'string', 'max:11'],
            'phone' => ['nullable', 'string', 'max:24'],
            'fbp' => ['nullable', 'string', 'max:512'],
            'fbc' => ['nullable', 'string', 'max:512'],
            'user_agent' => ['nullable', 'string', 'max:2048'],
        ];
        foreach (CheckoutSession::TRACKING_FIELD_KEYS as $trackingKey) {
            $rules[$trackingKey] = ['nullable', 'string', 'max:2048'];
        }
        $rules['affiliate_ref'] = ['nullable', 'string', 'max:32'];
        $rules['metrics_session_key'] = ['nullable', 'uuid'];
        $draftKey = 'cajupay_draft.'.$request->input('polling_token');
        $draftPreview = is_string($draftKey) ? Cache::get('cajupay_draft.'.$request->input('polling_token')) : null;
        $draftProductId = is_array($draftPreview) ? ($draftPreview['product_id'] ?? null) : null;
        $draftProduct = $draftProductId
            ? Product::where('id', $draftProductId)->availableForPurchase()->first()
            : null;
        $shippingHelper = app(CheckoutShippingHelper::class);
        if ($draftProduct && $shippingHelper->productRequiresShipping($draftProduct)) {
            $rules = $shippingHelper->appendAddressRulesIfNeeded($draftProduct, $rules);
        }
        $validated = $request->validate($rules);

        $draftKey = 'cajupay_draft.'.$validated['polling_token'];
        $draft = Cache::get($draftKey);
        if (! is_array($draft)) {
            $existingDisplay = session('cajupay_display.'.$validated['polling_token']);
            if (is_array($existingDisplay) && ! empty($existingDisplay['order_id'])) {
                return response()->json([
                    'success' => true,
                    'order_id' => (int) $existingDisplay['order_id'],
                    'polling_token' => $validated['polling_token'],
                    'polling_url' => route('checkout.order-status', ['token' => $validated['polling_token']]),
                    'idempotent' => true,
                ]);
            }

            return response()->json(['message' => 'Sessão CajuPay expirada. Recarregue a página.'], 404);
        }

        $product = Product::where('id', $draft['product_id'])->availableForPurchase()->first();
        if (! $product) {
            Cache::forget($draftKey);

            return response()->json(['message' => 'Produto não encontrado.'], 404);
        }

        $defaultsConfig = Product::defaultCheckoutConfig();
        $effectiveConfigBase = array_replace_recursive($defaultsConfig, $product->checkout_config ?? []);
        $customerFields = $effectiveConfigBase['customer_fields'] ?? ($defaultsConfig['customer_fields'] ?? []);

        $errors = [];
        if (($customerFields['name'] ?? true) && trim((string) ($validated['name'] ?? '')) === '') {
            $errors['name'] = 'Informe seu nome.';
        }
        $cpfDigits = preg_replace('/\D/', '', (string) ($validated['cpf'] ?? ''));
        if (($customerFields['cpf'] ?? false) && strlen((string) $cpfDigits) !== 11) {
            $errors['cpf'] = 'CPF obrigatório.';
        }
        if (($customerFields['phone'] ?? false) && trim((string) ($validated['phone'] ?? '')) === '') {
            $errors['phone'] = 'Telefone obrigatório.';
        }
        if (! empty($errors)) {
            return response()->json(['message' => 'Dados do cliente incompletos.', 'errors' => $errors], 422);
        }

        $draftSessionToken = is_string($draft['checkout_session_token'] ?? null) ? trim($draft['checkout_session_token']) : '';
        if ($draftSessionToken !== '') {
            app(CheckoutAbuseGuard::class)->assertCanProcess($request, $product, [
                'checkout_session_token' => $draftSessionToken,
                'payment_method' => (string) ($draft['payment_method'] ?? 'card'),
                'email' => $validated['email'],
                'product_id' => $product->id,
            ], false);
        }

        try {
            $context = $this->createUserAndOrderFromCajuPayDraft($request, $product, $draft, $validated);
        } catch (\Throwable $e) {
            Log::warning('CajuPayConfirmOrder: falha ao criar Order do draft', [
                'product_id' => $product->id,
                'polling_token' => $validated['polling_token'],
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage() ?: 'Falha ao registrar o pedido.'], 422);
        }

        /** @var Order $order */
        $order = $context['order'];
        $totalAmount = (float) $context['total_amount'];

        $this->updateCheckoutSessionForCajuPayOrder($order, array_merge($validated, [
            'checkout_session_token' => $draft['checkout_session_token'] ?? null,
        ]));

        event(new OrderPending($order->fresh()));

        app(\App\Services\CajuPay\CajuPayCheckoutCompletionService::class)->applyPendingForOrder($order->fresh());

        $redirectUrl = $product->checkout_config['redirect_after_purchase'] ?? null;
        $redirectUrl = ! empty($redirectUrl) && is_string($redirectUrl) ? $redirectUrl : null;

        session()->put('cajupay_display.'.$validated['polling_token'], [
            'order_id' => $order->id,
            'checkout_session_id' => $draft['checkout_session_id'],
            'session_token' => $draft['cajupay_token'],
            'payment_method' => $draft['payment_method'],
            'amount' => $totalAmount,
            'product_name' => $product->name,
            'checkout_slug' => $context['checkout_slug'],
            'redirect_after_purchase' => $redirectUrl,
            'customer_name' => $validated['name'] ?? null,
            'customer_email' => $validated['email'],
            'customer_phone' => $validated['phone'] ?? null,
            'created_at' => time(),
        ]);

        Cache::forget($draftKey);

        return response()->json([
            'success' => true,
            'order_id' => $order->id,
            'polling_token' => $validated['polling_token'],
            'polling_url' => route('checkout.order-status', ['token' => $validated['polling_token']]),
        ]);
    }

    /**
     * Cria pedido PayPal (Orders API) para o botão da carteira. Não entra na redundância de PIX/cartão.
     */
    public function paypalCreateOrder(Request $request): JsonResponse
    {
        $product = Product::where('id', $request->input('product_id'))->availableForPurchase()->first();
        if (! $product) {
            return response()->json(['message' => 'Produto não encontrado.'], 404);
        }

        $paypalGateway = app(PaymentService::class)->getFirstAvailableGatewayForMethod($product->tenant_id, 'paypal', $product);
        if ($paypalGateway !== 'paypal') {
            return response()->json(['message' => 'PayPal não está disponível neste checkout.'], 422);
        }

        $customerFields = $product->checkout_config['customer_fields'] ?? [];
        $displayCurrencyInput = $request->input('display_currency');
        $displayCurrency = is_string($displayCurrencyInput) && $displayCurrencyInput !== ''
            ? strtoupper($displayCurrencyInput)
            : strtoupper((string) ($product->currency ?? 'BRL'));
        $requireCpf = (($customerFields['cpf'] ?? false) && $displayCurrency === 'BRL');
        $phoneRequired = ($customerFields['phone'] ?? false);

        $rules = [
            'product_id' => ['required', 'exists:products,id'],
            'product_offer_id' => ['nullable', 'exists:product_offers,id'],
            'subscription_plan_id' => ['nullable', 'exists:subscription_plans,id'],
            'order_bump_ids' => ['nullable', 'array'],
            'order_bump_ids.*' => ['integer', 'exists:product_order_bumps,id'],
            'checkout_session_token' => ['required', 'string', 'max:64'],
            'display_currency' => ['nullable', 'string', 'in:BRL,USD,EUR'],
            'email' => ['required', 'email'],
            'name' => [($customerFields['name'] ?? true) ? 'required' : 'nullable', 'string', 'max:255'],
            'cpf' => [$requireCpf ? 'required' : 'nullable', 'string', 'max:14'],
            'phone' => [$phoneRequired ? 'required' : 'nullable', 'string', 'max:24'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
            'affiliate_ref' => ['nullable', 'string', 'max:32'],
            'checkout_locale' => ['nullable', 'string', 'max:16'],
            'billing_country' => ['nullable', 'string', 'size:2'],
            'website' => ['nullable', 'string', 'max:255'],
            '_hp' => ['nullable', 'string', 'max:255'],
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
            'fbp' => ['nullable', 'string', 'max:512'],
            'fbc' => ['nullable', 'string', 'max:512'],
            'user_agent' => ['nullable', 'string', 'max:2048'],
            'metrics_session_key' => ['nullable', 'uuid'],
        ];
        foreach (CheckoutSession::TRACKING_FIELD_KEYS as $trackingKey) {
            $rules[$trackingKey] = ['nullable', 'string', 'max:2048'];
        }
        $shippingHelper = app(CheckoutShippingHelper::class);
        if ($shippingHelper->productRequiresShipping($product)) {
            $rules = $shippingHelper->appendAddressRulesIfNeeded($product, $rules, $displayCurrency);
        }
        $validated = $request->validate($rules);
        $validated['payment_method'] = 'paypal';

        $subscriptionPlanId = $request->filled('subscription_plan_id') ? (int) $request->input('subscription_plan_id') : null;
        $plan = $subscriptionPlanId
            ? SubscriptionPlan::where('id', $subscriptionPlanId)->where('product_id', $product->id)->first()
            : null;
        $allowedPaymentIds = array_column(
            app(PaymentService::class)->availablePaymentMethodsForCheckout($product, $plan, null),
            'id'
        );
        if (! in_array('paypal', $allowedPaymentIds, true)) {
            return response()->json(['message' => 'PayPal não está disponível para este produto.'], 422);
        }

        $email = strtolower(trim((string) $validated['email']));
        app(CheckoutAbuseGuard::class)->assertCanProcess($request, $product, array_merge($validated, [
            'payment_method' => 'paypal',
            'email' => $email,
        ]), false);

        try {
            $context = $this->calculateCajuPayDraftContext($request, $product, $validated);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage() ?: 'Não foi possível calcular o pedido.'], 422);
        }

        $credential = GatewayCredential::resolveForPayment($product->tenant_id, 'paypal');
        if (! $credential) {
            return response()->json(['message' => 'PayPal não está conectado.'], 422);
        }
        $credentials = $credential->getDecryptedCredentials();
        if (empty($credentials['client_id']) || empty($credentials['client_secret'])) {
            return response()->json(['message' => 'PayPal: Client ID/Secret não configurados.'], 422);
        }

        $order = Order::query()
            ->where('tenant_id', $product->tenant_id)
            ->where('product_id', $product->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('status', 'pending')
            ->where('gateway', 'paypal')
            ->where('created_at', '>=', now()->subHours(2))
            ->latest('id')
            ->first();

        if (! $order) {
            $draft = [
                'product_id' => $product->id,
                'product_offer_id' => $context['offer']?->id,
                'subscription_plan_id' => $context['plan']?->id,
                'order_bump_ids' => $context['order_bump_ids'],
                'payment_method' => 'paypal',
                'coupon_code' => $context['coupon_code'],
                'total_amount' => $context['total_amount'],
                'base_amount' => $context['base_amount'],
                'checkout_session_token' => $validated['checkout_session_token'] ?? null,
                'shipping_amount' => (float) ($context['shipping_amount'] ?? 0),
                'gateway' => 'paypal',
                'gateway_id' => null,
            ];

            try {
                $created = $this->createUserAndOrderFromCajuPayDraft($request, $product, $draft, $validated);
            } catch (\Throwable $e) {
                Log::warning('PayPalCreateOrder: falha ao criar pedido', [
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json(['message' => $e->getMessage() ?: 'Falha ao registrar o pedido.'], 422);
            }

            /** @var Order $order */
            $order = $created['order'];
            $this->updateCheckoutSessionForCajuPayOrder($order, $validated);
            event(new OrderPending($order->fresh()));
        } else {
            $this->syncPendingPaypalOrderWithCheckoutContext($order, $product, $context, $validated);
            $this->updateCheckoutSessionForCajuPayOrder($order, $validated);
        }

        $chargeCurrency = strtoupper((string) ($validated['display_currency'] ?? $order->currency ?? 'BRL'));
        if (strlen($chargeCurrency) !== 3) {
            $chargeCurrency = 'BRL';
        }
        $chargeAmount = (float) ($context['total_amount'] ?? $order->amount);

        try {
            /** @var \App\Gateways\PayPal\PayPalDriver|null $driver */
            $driver = GatewayRegistry::driver('paypal');
            if (! $driver) {
                throw new \RuntimeException('Driver PayPal não disponível.');
            }
            $paypalResult = $driver->createPayPalOrder(
                $credentials,
                $chargeAmount,
                $chargeCurrency,
                (string) $order->id,
                [
                    'name' => $validated['name'] ?? '',
                    'email' => $validated['email'],
                    'phone' => (string) ($validated['phone'] ?? ''),
                    'document' => preg_replace('/\D/', '', (string) ($validated['cpf'] ?? '')),
                    'country' => strtoupper((string) ($validated['billing_country'] ?? '')),
                    'locale' => (string) ($validated['checkout_locale'] ?? 'pt_BR'),
                ],
                (string) $product->name,
                'buttons'
            );
        } catch (\Throwable $e) {
            $this->rollbackFailedOrder($order, $e);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Não foi possível iniciar o pagamento PayPal.',
            ], 422);
        }

        $paypalOrderId = (string) ($paypalResult['transaction_id'] ?? '');
        $order->update([
            'gateway' => 'paypal',
            'gateway_id' => $paypalOrderId,
            'payment_method' => 'paypal',
            'metadata' => array_merge(is_array($order->metadata) ? $order->metadata : [], [
                'checkout_payment_method' => 'paypal',
                'paypal_order_id' => $paypalOrderId,
            ]),
        ]);

        return response()->json([
            'success' => true,
            'order_id' => $order->id,
            'paypal_order_id' => $paypalOrderId,
            'id' => $paypalOrderId,
        ]);
    }

    /**
     * Captura o pedido PayPal após aprovação na carteira.
     */
    public function paypalCapture(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'paypal_order_id' => ['required', 'string', 'max:64'],
            'order_id' => ['nullable', 'integer'],
        ]);

        $paypalOrderId = trim($validated['paypal_order_id']);
        $order = Order::where('gateway', 'paypal')
            ->where('gateway_id', $paypalOrderId)
            ->when(! empty($validated['order_id']), fn ($q) => $q->where('id', (int) $validated['order_id']))
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Pedido PayPal não encontrado.'], 404);
        }

        if ($order->status === 'completed') {
            return response()->json($this->paypalCaptureSuccessPayload($order));
        }

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Pedido não está pendente de pagamento.'], 422);
        }

        $product = Product::find($order->product_id);
        if (! $product) {
            return response()->json(['message' => 'Produto não encontrado.'], 404);
        }

        $offer = $order->product_offer_id
            ? ProductOffer::where('id', $order->product_offer_id)->where('product_id', $product->id)->first()
            : null;
        $plan = $order->subscription_plan_id
            ? SubscriptionPlan::where('id', $order->subscription_plan_id)->where('product_id', $product->id)->first()
            : null;

        $credential = GatewayCredential::resolveForPayment($order->tenant_id, 'paypal');
        if (! $credential) {
            return response()->json(['message' => 'PayPal não está conectado.'], 422);
        }

        /** @var \App\Gateways\PayPal\PayPalDriver|null $driver */
        $driver = GatewayRegistry::driver('paypal');
        if (! $driver) {
            return response()->json(['message' => 'Driver PayPal não disponível.'], 422);
        }

        $order->loadMissing('user');
        $consumer = CheckoutPaymentConsumer::build([
            'name' => $order->user?->name ?? '',
            'email' => (string) $order->email,
            'cpf' => (string) ($order->cpf ?? ''),
            'phone' => (string) ($order->phone ?? ''),
        ], $order->id);

        try {
            $cardResult = $driver->createCardPayment(
                $credential->getDecryptedCredentials(),
                (float) $order->amount,
                $consumer,
                (string) $order->id,
                ['payment_token' => $paypalOrderId]
            );
        } catch (\Throwable $e) {
            Log::warning('PayPalCapture: falha', [
                'order_id' => $order->id,
                'paypal_order_id' => $paypalOrderId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Não foi possível processar o pagamento. Tente novamente.',
            ], 422);
        }

        $status = $cardResult['status'] ?? null;
        $isApproved = in_array($status, ['paid', 'settled', 'approved', 'completed'], true);

        if (! $isApproved) {
            return response()->json([
                'success' => true,
                'payment_method' => 'paypal',
                'order_id' => $order->id,
                'status' => $status ?? 'pending',
                'message' => 'Pagamento em processamento.',
                'redirect_url' => $this->paypalRedirectUrlForOrder($order, $product, $offer, $plan, false),
            ]);
        }

        if ($order->fresh()->status !== 'completed') {
            $order->update(['status' => 'completed']);
            $order->load('orderItems');
            $order->grantPurchasedProductAccessToBuyer();

            if ($plan && $order->user_id) {
                $existing = Subscription::where('user_id', $order->user_id)
                    ->where('product_id', $product->id)
                    ->where('subscription_plan_id', $plan->id)
                    ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
                    ->first();
                if (! $existing) {
                    [$periodStart, $periodEnd] = $plan->getCurrentPeriod();
                    $subscription = Subscription::create([
                        'tenant_id' => $order->tenant_id,
                        'user_id' => $order->user_id,
                        'product_id' => $product->id,
                        'subscription_plan_id' => $plan->id,
                        'status' => Subscription::STATUS_ACTIVE,
                        'current_period_start' => $order->period_start ?? $periodStart,
                        'current_period_end' => $order->period_end ?? $periodEnd,
                    ]);
                    event(new SubscriptionCreated($subscription));
                }
            }

            event(new OrderCompleted($order->fresh()));
        }

        return response()->json($this->paypalCaptureSuccessPayload($order->fresh(), $product, $offer, $plan));
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $validated
     */
    private function syncPendingPaypalOrderWithCheckoutContext(Order $order, Product $product, array $context, array $validated): void
    {
        $totalAmount = (float) ($context['total_amount'] ?? $order->amount);
        $baseAmount = (float) ($context['base_amount'] ?? $order->amount);
        $order->update([
            'amount' => $totalAmount,
            'coupon_code' => $context['coupon_code'] ?? $order->coupon_code,
            'product_offer_id' => $context['offer']?->id ?? $order->product_offer_id,
            'subscription_plan_id' => $context['plan']?->id ?? $order->subscription_plan_id,
        ]);

        $order->orderItems()->delete();
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_offer_id' => $context['offer']?->id,
            'subscription_plan_id' => $context['plan']?->id,
            'amount' => $baseAmount,
            'position' => 0,
        ]);
        $bumpIds = is_array($context['order_bump_ids'] ?? null) ? $context['order_bump_ids'] : [];
        if ($bumpIds) {
            $bumps = ProductOrderBump::where('product_id', $product->id)->whereIn('id', $bumpIds)->get();
            $pos = 1;
            foreach ($bumps as $bump) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $bump->target_product_id,
                    'product_offer_id' => $bump->target_product_offer_id,
                    'subscription_plan_id' => null,
                    'amount' => $bump->getEffectiveAmountBrl(),
                    'position' => $pos++,
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function paypalCaptureSuccessPayload(Order $order, ?Product $product = null, ?ProductOffer $offer = null, ?SubscriptionPlan $plan = null): array
    {
        $product = $product ?? Product::find($order->product_id);
        $offer = $offer ?? ($order->product_offer_id ? ProductOffer::find($order->product_offer_id) : null);
        $plan = $plan ?? ($order->subscription_plan_id ? SubscriptionPlan::find($order->subscription_plan_id) : null);
        $redirectUrl = $product
            ? $this->paypalRedirectUrlForOrder($order, $product, $offer, $plan, true)
            : route('checkout.thank-you', ['order_id' => $order->id, 'next' => 'login']);

        return [
            'success' => true,
            'payment_method' => 'paypal',
            'order_id' => $order->id,
            'status' => 'paid',
            'message' => 'Pagamento aprovado.',
            'redirect_url' => $redirectUrl,
        ];
    }

    private function paypalRedirectUrlForOrder(
        Order $order,
        Product $product,
        ?ProductOffer $offer,
        ?SubscriptionPlan $plan,
        bool $approved
    ): string {
        if (! $approved) {
            $next = ($order->user_id && User::find($order->user_id)) ? 'member-area' : 'login';

            return route('checkout.thank-you', ['order_id' => $order->id, 'next' => $next]);
        }

        $config = $this->getOrderCheckoutConfigForProcess($order, $product, $offer, $plan);
        $upsell = $config['upsell'] ?? [];
        if (! empty($upsell['enabled']) && ! empty($upsell['products']) && is_array($upsell['products'])) {
            $upsellToken = Str::random(64);
            Cache::put('upsell_token.'.$upsellToken, ['order_id' => $order->id, 'gateway' => $order->gateway], now()->addMinutes(60));

            return route('checkout.upsell', ['token' => $upsellToken]);
        }

        $customRedirect = $config['redirect_after_purchase'] ?? null;
        if (is_string($customRedirect) && trim($customRedirect) !== '') {
            $normalized = SafeUrl::normalizeCheckoutRedirect($customRedirect);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $next = ($order->user_id && User::find($order->user_id)) ? 'member-area' : 'login';

        return route('checkout.thank-you', ['order_id' => $order->id, 'next' => $next]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function updateCheckoutSessionForCajuPayOrder(Order $order, array $validated): void
    {
        $utmFromRequest = $this->utmPayloadFromValidated($validated);
        $token = $validated['checkout_session_token'] ?? null;
        if ($token) {
            $session = CheckoutSession::where('session_token', $token)->first();
            if ($session) {
                $mergedUtms = $this->mergeSessionUtms($session, $utmFromRequest);
                $session->update(array_merge([
                    'step' => CheckoutSession::STEP_CONVERTED,
                    'order_id' => $order->id,
                ], $mergedUtms));
                $this->persistOrderUtms($order, $mergedUtms);

                return;
            }
        }
        $this->persistOrderUtms($order, $utmFromRequest);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{offer: ?ProductOffer, plan: ?SubscriptionPlan, total_amount: float, base_amount: float, order_bump_ids: array<int, int>, coupon_code: ?string, checkout_slug: string, period_start: ?\Carbon\Carbon, period_end: ?\Carbon\Carbon}
     */
    private function calculateCajuPayDraftContext(Request $request, Product $product, array $validated): array
    {
        $productOfferId = $request->filled('product_offer_id') ? (int) $request->input('product_offer_id') : null;
        $subscriptionPlanId = $request->filled('subscription_plan_id') ? (int) $request->input('subscription_plan_id') : null;
        $offer = $productOfferId ? ProductOffer::where('id', $productOfferId)->where('product_id', $product->id)->first() : null;
        $plan = $subscriptionPlanId ? SubscriptionPlan::where('id', $subscriptionPlanId)->where('product_id', $product->id)->first() : null;

        $amount = (float) $product->price;
        if ($offer) {
            $amount = (float) $offer->price;
        } elseif ($plan) {
            $amount = (float) $plan->price;
        }
        $currency = $product->currency ?? 'BRL';
        if ($offer) {
            $currency = $offer->getCurrencyOrDefault();
        } elseif ($plan) {
            $currency = $plan->getCurrencyOrDefault();
        }
        if ($currency !== 'BRL') {
            $rates = config('products.rates');
            $amount = $currency === 'EUR' ? $amount / ($rates['brl_eur'] ?? 0.16) : $amount / ($rates['brl_usd'] ?? 0.18);
        }

        $orderBumpIds = array_values(array_filter(array_map('intval', $validated['order_bump_ids'] ?? [])));
        $selectedBumps = collect();
        if ($orderBumpIds) {
            $selectedBumps = ProductOrderBump::where('product_id', $product->id)->whereIn('id', $orderBumpIds)->get();
        }
        $bumpAmountTotal = $selectedBumps->sum(fn (ProductOrderBump $b) => $b->getEffectiveAmountBrl());
        $totalAmount = $amount + $bumpAmountTotal;

        $couponCode = isset($validated['coupon_code']) && trim((string) ($validated['coupon_code'] ?? '')) !== ''
            ? trim((string) $validated['coupon_code'])
            : null;
        $couponApplied = app(CouponCheckoutService::class)->applyOptional($product, $couponCode, $amount);
        $amount = $couponApplied['amount'];
        $couponCode = $couponApplied['coupon_code'];
        $totalAmount = $amount + $bumpAmountTotal;

        $periodStart = null;
        $periodEnd = null;
        if ($plan) {
            [$periodStart, $periodEnd] = $plan->getCurrentPeriod();
        }

        $checkoutSlug = $product->checkout_slug ?? '';
        if ($offer && ! empty($offer->checkout_slug)) {
            $checkoutSlug = $offer->checkout_slug;
        } elseif ($plan && ! empty($plan->checkout_slug)) {
            $checkoutSlug = $plan->checkout_slug;
        }
        $checkoutSlug = (string) $checkoutSlug;
        if ($checkoutSlug === '') {
            $checkoutSlug = (string) ($product->checkout_slug ?? '');
        }

        $shippingHelper = app(CheckoutShippingHelper::class);
        $shippingResolved = null;
        if ($shippingHelper->productRequiresShipping($product)) {
            if (strtoupper((string) ($validated['display_currency'] ?? 'BRL')) !== 'BRL') {
                throw new \RuntimeException('Produtos físicos estão disponíveis apenas em BRL.');
            }
            $shippingResolved = $shippingHelper->resolveForCheckout($product, $validated);
            $totalAmount = round($totalAmount + $shippingResolved['shipping_amount'], 2);
        }

        app(MinimumChargeService::class)->assertPlatformCheckout($totalAmount, (int) $product->tenant_id);

        return [
            'offer' => $offer,
            'plan' => $plan,
            'total_amount' => $totalAmount,
            'base_amount' => $amount,
            'order_bump_ids' => $orderBumpIds,
            'coupon_code' => $couponCode,
            'checkout_slug' => $checkoutSlug,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'shipping_amount' => $shippingResolved['shipping_amount'] ?? 0.0,
            'shipping_resolved' => $shippingResolved,
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $validated
     * @return array{order: Order, total_amount: float, checkout_slug: string}
     */
    private function createUserAndOrderFromCajuPayDraft(Request $request, Product $product, array $draft, array $validated): array
    {
        $offer = ! empty($draft['product_offer_id'])
            ? ProductOffer::where('id', (int) $draft['product_offer_id'])->where('product_id', $product->id)->first()
            : null;
        $plan = ! empty($draft['subscription_plan_id'])
            ? SubscriptionPlan::where('id', (int) $draft['subscription_plan_id'])->where('product_id', $product->id)->first()
            : null;

        $totalAmount = (float) $draft['total_amount'];
        $baseAmount = (float) $draft['base_amount'];

        $periodStart = null;
        $periodEnd = null;
        if ($plan) {
            [$periodStart, $periodEnd] = $plan->getCurrentPeriod();
        }

        $checkoutSlug = $product->checkout_slug ?? '';
        if ($offer && ! empty($offer->checkout_slug)) {
            $checkoutSlug = $offer->checkout_slug;
        } elseif ($plan && ! empty($plan->checkout_slug)) {
            $checkoutSlug = $plan->checkout_slug;
        }
        $checkoutSlug = (string) $checkoutSlug;
        if ($checkoutSlug === '') {
            $checkoutSlug = (string) ($product->checkout_slug ?? '');
        }

        $tenantId = $product->tenant_id;

        $plainPassword = null;
        if ($product->type === Product::TYPE_AREA_MEMBROS) {
            $loginConfig = $product->member_area_config['login'] ?? [];
            $passwordMode = $loginConfig['password_mode'] ?? 'auto';
            $defaultPassword = trim((string) ($loginConfig['default_password'] ?? ''));
            if ($passwordMode === 'default' && $defaultPassword !== '') {
                $plainPassword = $defaultPassword;
            } else {
                $plainPassword = Str::random(12);
            }
        } else {
            $plainPassword = Str::random(32);
        }
        $passwordHash = bcrypt($plainPassword);

        $buyerAccount = app(BuyerAccountService::class)->ensureBuyerFromCheckout(
            $validated['email'],
            (string) ($validated['name'] ?? $validated['email']),
            $passwordHash,
            $product->type === Product::TYPE_AREA_MEMBROS,
        );
        $user = $buyerAccount['user'];
        if (! $buyerAccount['was_recently_created'] && ! empty($validated['name']) && trim((string) $user->name) !== trim((string) $validated['name'])) {
            $user->update(['name' => trim((string) $validated['name'])]);
        }

        $gatewaySlug = (string) ($draft['gateway'] ?? 'cajupay');
        if ($gatewaySlug === '') {
            $gatewaySlug = 'cajupay';
        }
        $orderPaymentMethod = $gatewaySlug === 'paypal' ? 'paypal' : 'card';
        $cajupayToken = $draft['cajupay_token'] ?? null;
        $orderMetadata = [
            'checkout_payment_method' => $draft['payment_method'] ?? $orderPaymentMethod,
        ];
        if ($gatewaySlug === 'cajupay') {
            $orderMetadata['cajupay_session_token'] = $cajupayToken;
            $orderMetadata['cajupay_sdk_token'] = $cajupayToken;
            $orderMetadata['cajupay_checkout_session_id'] = $draft['checkout_session_id'] ?? null;
        }
        if ($product->type === Product::TYPE_AREA_MEMBROS && $plainPassword !== null) {
            Cache::put('access_password.'.$user->id.'.'.$product->id, $plainPassword, now()->addHours(2));
            $orderMetadata['access_password_temp'] = encrypt($plainPassword);
        }

        $fbp = isset($validated['fbp']) && is_string($validated['fbp']) ? trim($validated['fbp']) : '';
        $fbc = isset($validated['fbc']) && is_string($validated['fbc']) ? trim($validated['fbc']) : '';
        $ua = isset($validated['user_agent']) && is_string($validated['user_agent']) ? trim($validated['user_agent']) : '';
        if ($fbp !== '') {
            $orderMetadata['fbp'] = $fbp;
        }
        if ($fbc !== '') {
            $orderMetadata['fbc'] = $fbc;
        }
        if ($ua !== '') {
            $orderMetadata['user_agent'] = $ua;
        }

        $orderMetadata = $this->mergeCheckoutSessionMetaTracking($orderMetadata, $validated['checkout_session_token'] ?? null);
        $orderMetadata = $this->mergeCheckoutSessionUtmsIntoOrderMetadata($orderMetadata, $validated['checkout_session_token'] ?? null, $validated, $product);

        $cpfDigits = preg_replace('/\D/', '', (string) ($validated['cpf'] ?? '')) ?: null;
        $phone = ($validated['phone'] ?? null) ?: null;

        $shippingHelper = app(CheckoutShippingHelper::class);
        $shippingResolved = null;
        if ($shippingHelper->productRequiresShipping($product)) {
            $shippingResolved = $shippingHelper->resolveForCheckout($product, $validated);
            $draftShipping = (float) ($draft['shipping_amount'] ?? 0);
            if (abs($shippingResolved['shipping_amount'] - $draftShipping) > 0.009) {
                throw new \RuntimeException('O frete foi atualizado. Recarregue a página e tente novamente.');
            }
        }

        $orderPayload = [
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_offer_id' => $offer?->id,
            'subscription_plan_id' => $plan?->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'is_renewal' => false,
            'amount' => $totalAmount,
            'email' => $validated['email'],
            'cpf' => $cpfDigits,
            'phone' => $phone,
            'customer_ip' => $request->ip(),
            'coupon_code' => $draft['coupon_code'] ?? null,
            'metadata' => $orderMetadata,
            'status' => 'pending',
            'gateway' => $gatewaySlug,
            'gateway_id' => $draft['gateway_id'] ?? $draft['checkout_session_id'] ?? null,
            'cajupay_account_id' => $gatewaySlug === 'cajupay'
                ? app(\App\Services\CajuPay\CajuPayAccountResolver::class)->accountIdForTenant($tenantId)
                : null,
            'payment_method' => $orderPaymentMethod,
        ];
        if ($shippingResolved !== null) {
            $orderPayload['shipping_amount'] = $shippingResolved['shipping_amount'];
            $orderPayload['shipping_store_id'] = $shippingResolved['shipping_store_id'];
            $orderPayload['shipping_rule_id'] = $shippingResolved['shipping_rule_id'];
            $orderPayload['shipping_address'] = $shippingResolved['shipping_address'];
            $orderPayload['metadata'] = array_merge($orderMetadata, $shippingResolved['metadata_shipping']);
        }

        try {
            app(MinimumChargeService::class)->assertPlatformCheckout($totalAmount, (int) $product->tenant_id);
        } catch (ValidationException $e) {
            throw new \RuntimeException(
                collect($e->errors())->flatten()->first() ?? 'Valor abaixo do mínimo da plataforma.'
            );
        }

        $order = Order::create($orderPayload);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_offer_id' => $offer?->id,
            'subscription_plan_id' => $plan?->id,
            'amount' => $baseAmount,
            'position' => 0,
        ]);
        $bumpIds = is_array($draft['order_bump_ids'] ?? null) ? $draft['order_bump_ids'] : [];
        if ($bumpIds) {
            $bumps = ProductOrderBump::where('product_id', $product->id)->whereIn('id', $bumpIds)->get();
            $pos = 1;
            foreach ($bumps as $bump) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $bump->target_product_id,
                    'product_offer_id' => $bump->target_product_offer_id,
                    'subscription_plan_id' => null,
                    'amount' => $bump->getEffectiveAmountBrl(),
                    'position' => $pos++,
                ]);
            }
        }

        $order->load('orderItems');

        return [
            'order' => $order,
            'total_amount' => $totalAmount,
            'checkout_slug' => $checkoutSlug,
        ];
    }

    /**
     * Status do pedido para polling na página PIX ou Boleto (identificado por token).
     */
    public function orderStatus(Request $request): JsonResponse
    {
        $token = $request->query('token');
        if (! $token || ! is_string($token)) {
            return response()->json(['status' => 'invalid'], 400);
        }

        $stored = session('pix_display.'.$token);
        if (! is_array($stored)) {
            $stored = session('boleto_display.'.$token);
        }
        if (! is_array($stored)) {
            $stored = session('cajupay_display.'.$token);
        }
        if (! is_array($stored)) {
            $stored = session('lina_display.'.$token);
        }
        if (! is_array($stored)) {
            return response()->json(['status' => 'not_found'], 404);
        }

        $orderId = (int) ($stored['order_id'] ?? 0);
        $order = Order::with('product', 'productOffer', 'subscriptionPlan')->find($orderId);
        if (! $order) {
            return response()->json(['status' => 'not_found'], 404);
        }

        if ($order->status === 'pending') {
            $gatewaySlug = (string) ($order->gateway ?: '');
            $meta = is_array($order->metadata) ? $order->metadata : [];
            $cajupaySessionToken = is_string($meta['cajupay_session_token'] ?? null) ? trim($meta['cajupay_session_token']) : '';
            if ($cajupaySessionToken === '' && is_string($meta['cajupay_sdk_token'] ?? null)) {
                $cajupaySessionToken = trim($meta['cajupay_sdk_token']);
            }
            if ($cajupaySessionToken === '' && is_string($stored['session_token'] ?? null)) {
                $cajupaySessionToken = trim($stored['session_token']);
            }
            $isCajuPayCheckout = $gatewaySlug === 'cajupay'
                || ($cajupaySessionToken !== '' && in_array($order->payment_method, ['card', 'apple_pay', 'google_pay'], true));

            if ($isCajuPayCheckout && $cajupaySessionToken !== '') {
                try {
                    app(\App\Services\CajuPay\CajuPayCheckoutCompletionService::class)->tryCompleteFromPublicSession($order);
                    $order->refresh();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::debug('CheckoutController orderStatus: falha poll CajuPay SDK', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } elseif ($gatewaySlug === 'linaopenx' || $order->payment_method === 'open_finance') {
                try {
                    app(LinaOpenxCheckoutService::class)->tryCompleteFromApi($order);
                    $order->refresh();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::debug('CheckoutController orderStatus: falha poll Lina OpenX', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } elseif ($gatewaySlug === 'mercadopago') {
                try {
                    app(\App\Services\MercadoPago\MercadoPagoCheckoutCompletionService::class)->tryCompleteFromPaymentApi($order);
                    $order->refresh();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::debug('CheckoutController orderStatus: falha poll Mercado Pago', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } elseif (! empty($order->gateway) && ! empty($order->gateway_id)) {
                try {
                    $credentials = GatewayPaymentCredentials::resolve($order->tenant_id, $gatewaySlug, $order);
                    if ($credentials !== null) {
                        $driver = GatewayRegistry::driver($gatewaySlug);
                        $efiNeedsCert = $gatewaySlug === 'efi' && empty($credentials['certificate_path'] ?? '');
                        if ($driver && ! $efiNeedsCert) {
                            $statusLookupId = (string) $order->gateway_id;
                            if ($gatewaySlug === 'cajupay' && $cajupaySessionToken !== '') {
                                $statusLookupId = $cajupaySessionToken;
                            }
                            $apiStatus = $driver->getTransactionStatus($statusLookupId, $credentials);
                            if ($apiStatus === 'paid') {
                                $dispatchId = (string) ($order->gateway_id ?: $statusLookupId);
                                ProcessPaymentWebhook::dispatchSync(
                                    $gatewaySlug,
                                    $dispatchId,
                                    $gatewaySlug === 'cajupay' ? 'checkout.payment.paid' : 'order.paid',
                                    'paid',
                                    [
                                        'source' => 'order_status_poll',
                                        'webhook_source' => $gatewaySlug === 'cajupay' ? 'cajupay_public_session_poll' : '',
                                    ]
                                );
                                $order->refresh();
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::debug('CheckoutController orderStatus: falha ao consultar status no gateway', [
                        'order_id' => $order->id,
                        'gateway' => $gatewaySlug,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $status = $order->status;
        $redirectUrl = null;
        if ($status === 'completed') {
            if ($order->api_application_id) {
                $redirectUrl = route('api-checkout.thank-you', ['order_id' => $order->id]);
            } else {
                $config = $this->getOrderCheckoutConfig($order);
                $upsell = $config['upsell'] ?? [];
                if (! empty($upsell['enabled']) && ! empty($upsell['products']) && is_array($upsell['products'])) {
                    $upsellToken = Str::random(64);
                    Cache::put('upsell_token.'.$upsellToken, [
                        'order_id' => $order->id,
                        'gateway' => 'pix',
                    ], now()->addMinutes(60));
                    $redirectUrl = route('checkout.upsell', ['token' => $upsellToken]);
                } else {
                    $customRedirect = $config['redirect_after_purchase'] ?? null;
                    if (is_string($customRedirect) && trim($customRedirect) !== '') {
                        $redirectUrl = SafeUrl::normalizeCheckoutRedirect($customRedirect);
                    }
                    if ($redirectUrl === null) {
                        $next = ($order->user_id && User::find($order->user_id)) ? 'member-area' : 'login';
                        $redirectUrl = route('checkout.thank-you', ['order_id' => $order->id, 'next' => $next]);
                    }
                }
            }
        }

        return response()->json([
            'status' => $status,
            'redirect_url' => $redirectUrl,
            'order_id' => $status === 'completed' ? $order->id : null,
        ]);
    }

    /**
     * Config de checkout efetiva do pedido (plano > oferta > produto).
     */
    private function getOrderCheckoutConfig(Order $order): array
    {
        if ($order->subscriptionPlan && $order->subscriptionPlan->checkout_config) {
            return array_replace_recursive(Product::defaultCheckoutConfig(), $order->subscriptionPlan->checkout_config);
        }
        if ($order->productOffer && $order->productOffer->checkout_config) {
            return array_replace_recursive(Product::defaultCheckoutConfig(), $order->productOffer->checkout_config);
        }

        return $order->product ? $order->product->checkout_config : [];
    }

    /**
     * @param  float|null  $effectiveAmount  When offer/plan present, use this as price.
     * @param  string|null  $effectiveCurrency  When offer/plan present, use this.
     * @param  string|null  $effectiveCheckoutSlug  When offer/plan present, use this for redirects.
     */
    private function productToCheckoutArray(Product $product, ?ProductOffer $offer = null, ?SubscriptionPlan $plan = null, ?float $effectiveAmount = null, ?string $effectiveCurrency = null, ?string $effectiveCheckoutSlug = null): array
    {
        $imageUrl = $product->image
            ? (new StorageService($product->tenant_id))->url($product->image)
            : null;
        $summary = $product->checkout_config['summary'] ?? [];
        $previousPrice = $summary['previous_price'] ?? null;
        if ($previousPrice !== null) {
            $previousPrice = (float) $previousPrice;
        }

        $price = $effectiveAmount !== null ? $effectiveAmount : (float) $product->price;
        $currency = $effectiveCurrency ?? $product->currency ?? 'BRL';
        $checkoutSlug = $effectiveCheckoutSlug ?? $product->checkout_slug;

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'checkout_slug' => $checkoutSlug,
            'description' => $product->description,
            'type' => $product->type,
            'billing_type' => $product->billing_type ?? Product::BILLING_ONE_TIME,
            'price' => $price,
            'currency' => $currency,
            'image_url' => $imageUrl,
            'previous_price' => $previousPrice,
            'product_offer_id' => $offer?->id,
            'subscription_plan_id' => $plan?->id,
            'requires_shipping' => $product->requiresShippingAddress(),
            'free_shipping' => $product->hasFreeShipping(),
            'shipping_store_id' => $product->shipping_store_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function computeCheckoutProductSubtotalBrl(Product $product, array $validated): float
    {
        $offer = null;
        $plan = null;
        if (! empty($validated['product_offer_id'])) {
            $offer = ProductOffer::where('id', $validated['product_offer_id'])->where('product_id', $product->id)->first();
        }
        if (! empty($validated['subscription_plan_id'])) {
            $plan = SubscriptionPlan::where('id', $validated['subscription_plan_id'])->where('product_id', $product->id)->first();
        }
        $amount = (float) $product->price;
        if ($offer) {
            $amount = (float) $offer->price;
        } elseif ($plan) {
            $amount = (float) $plan->price;
        }
        $couponCode = isset($validated['coupon_code']) && trim((string) ($validated['coupon_code'] ?? '')) !== ''
            ? trim((string) $validated['coupon_code'])
            : null;
        $amount = app(CouponCheckoutService::class)->tryApply($product, $couponCode, $amount);
        $orderBumpIds = array_values(array_filter(array_map('intval', $validated['order_bump_ids'] ?? [])));
        $bumpTotal = 0.0;
        if ($orderBumpIds !== []) {
            $bumpTotal = (float) ProductOrderBump::where('product_id', $product->id)->whereIn('id', $orderBumpIds)->get()
                ->sum(fn (ProductOrderBump $b) => $b->getEffectiveAmountBrl());
        }

        return round($amount + $bumpTotal, 2);
    }

    /**
     * Adiciona price_brl ao array do produto (preço normalizado em BRL) para conversão no front.
     *
     * @param  array<string, mixed>  $productArray
     * @param  array<int, array{code: string, rate_to_brl: float}>  $currencies
     * @return array<string, mixed>
     */
    private function addPricesInCurrencies(array $productArray, array $currencies): array
    {
        $price = (float) ($productArray['price'] ?? 0);
        $currency = $productArray['currency'] ?? 'BRL';
        $rates = [];
        foreach ($currencies as $c) {
            $code = $c['code'] ?? '';
            $rates[$code] = (float) ($c['rate_to_brl'] ?? 0);
        }
        $brlEur = $rates['EUR'] ?? config('products.rates.brl_eur', 0.16);
        $brlUsd = $rates['USD'] ?? config('products.rates.brl_usd', 0.18);
        $priceBrl = $currency === 'BRL' ? $price : ($currency === 'EUR' ? $price / $brlEur : $price / $brlUsd);
        $productArray['price_brl'] = round($priceBrl, 2);

        return $productArray;
    }

    /**
     * Armazena resposta de sucesso no cache de idempotência (24h) e retorna a resposta.
     * Evita pedidos duplicados em caso de duplo clique ou replay.
     */
    private function idempotencyReturn(?string $key, RedirectResponse|JsonResponse $response): RedirectResponse|JsonResponse
    {
        if ($key === null || $key === '' || strlen($key) > 128) {
            if ($this->idempotencyRequest !== null && $this->idempotencyValidated !== null) {
                app(CheckoutAbuseGuard::class)->rememberFingerprintResponse(
                    $this->idempotencyRequest,
                    $this->idempotencyValidated,
                    $response
                );
            }

            return $response;
        }
        if ($response instanceof RedirectResponse) {
            Cache::put('checkout_idempotency:'.$key, [
                'type' => 'redirect',
                'url' => $response->getTargetUrl(),
            ], now()->addMinutes(1440));
        }
        if ($response instanceof JsonResponse && $response->getStatusCode() === 200) {
            Cache::put('checkout_idempotency:'.$key, [
                'type' => 'json',
                'data' => json_decode($response->getContent(), true),
            ], now()->addMinutes(1440));
        }

        if ($this->idempotencyRequest !== null && $this->idempotencyValidated !== null) {
            app(CheckoutAbuseGuard::class)->rememberFingerprintResponse(
                $this->idempotencyRequest,
                $this->idempotencyValidated,
                $response
            );
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, string|null>
     */
    private function utmPayloadFromValidated(array $validated): array
    {
        $out = [];
        foreach (CheckoutSession::TRACKING_FIELD_KEYS as $k) {
            $v = isset($validated[$k]) ? trim((string) $validated[$k]) : '';
            $out[$k] = $v !== '' ? $v : null;
        }

        return $out;
    }

    /**
     * @param  array<string, string|null>  $fromRequest
     * @return array<string, string|null>
     */
    private function mergeSessionUtms(CheckoutSession $session, array $fromRequest): array
    {
        $out = [];
        foreach (CheckoutSession::TRACKING_FIELD_KEYS as $k) {
            $req = $fromRequest[$k] ?? null;
            $sess = $session->{$k} ?? null;
            $reqN = is_string($req) && trim($req) !== '' ? trim($req) : null;
            $sessN = is_string($sess) && trim($sess) !== '' ? trim($sess) : null;
            $out[$k] = $reqN ?? $sessN;
        }

        return $out;
    }

    /**
     * @param  array<string, string|null>  $tracking
     */
    private function persistOrderUtms(Order $order, array $tracking): void
    {
        $meta = $order->metadata ?? [];
        $changed = false;
        foreach (CheckoutSession::TRACKING_FIELD_KEYS as $k) {
            $v = $tracking[$k] ?? null;
            if (! is_string($v) || trim($v) === '') {
                continue;
            }
            $v = trim($v);
            if (($meta[$k] ?? null) !== $v) {
                $meta[$k] = $v;
                $changed = true;
            }
        }
        if ($changed) {
            $order->update(['metadata' => $meta]);
        }
    }

    /**
     * @param  array{payment_token?: string, card_mask?: string}  $card
     */
    private function attachStripeSavedPaymentMethodForSubscription(
        Subscription $subscription,
        Order $order,
        array $card,
        int $tenantId,
        int $userId
    ): void {
        if (($order->gateway ?? '') !== 'stripe') {
            return;
        }
        $pm = trim((string) ($card['payment_token'] ?? ''));
        if ($pm === '' || ! str_starts_with($pm, 'pm_')) {
            return;
        }
        $lastFour = null;
        if (! empty($card['card_mask']) && preg_match('/(\d{4})\s*$/', (string) $card['card_mask'], $m)) {
            $lastFour = $m[1];
        }
        $spm = SavedPaymentMethod::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'gateway' => 'stripe',
            'gateway_payment_method_id' => $pm,
            'last_four' => $lastFour,
            'brand' => 'card',
            'type' => 'card',
        ]);
        $subscription->update(['saved_payment_method_id' => $spm->id]);
    }

    /**
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse|null
     */
    private function guardPlatformMinimumCheckout(float $totalAmount, Request $request, ?int $tenantId = null)
    {
        try {
            app(MinimumChargeService::class)->assertPlatformCheckout($totalAmount, $tenantId);
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => collect($e->errors())->flatten()->first() ?? 'Valor abaixo do mínimo.',
                    'errors' => $e->errors(),
                ], 422);
            }

            return back()->withErrors($e->errors())->withInput();
        }

        return null;
    }

    /**
     * Tracking interno é opcional: valor inválido não pode bloquear o pagamento.
     */
    private function forgetInvalidMetricsSessionKey(Request $request): void
    {
        if (! $request->exists('metrics_session_key')) {
            return;
        }

        $value = $request->input('metrics_session_key');
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! Str::isUuid($value)) {
            $request->merge(['metrics_session_key' => null]);
        }
    }

    private function resolveValidMetricsSessionKey(mixed $candidate): ?string
    {
        if (! is_string($candidate) || $candidate === '') {
            return null;
        }

        return Str::isUuid($candidate) ? $candidate : null;
    }

    /**
     * @param  array<string, mixed>  $orderMetadata
     * @return array<string, mixed>
     */
    private function mergeCheckoutSessionMetaTracking(array $orderMetadata, mixed $sessionToken): array
    {
        $token = is_string($sessionToken) ? trim($sessionToken) : '';
        if ($token === '') {
            return $orderMetadata;
        }

        $session = CheckoutSession::where('session_token', $token)->first();
        if (! $session) {
            return $orderMetadata;
        }

        return app(MetaTrackingService::class)->mergeSessionAttributionIntoOrder($session, $orderMetadata);
    }

    /**
     * @param  array<string, mixed>  $orderMetadata
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function mergeCheckoutSessionUtmsIntoOrderMetadata(array $orderMetadata, mixed $sessionToken, array $validated = [], ?Product $product = null): array
    {
        $token = is_string($sessionToken) ? trim($sessionToken) : '';
        if ($token !== '') {
            $orderMetadata['checkout_session_token'] = $token;
        }

        $fromRequest = $this->utmPayloadFromValidated($validated);
        $session = $token !== '' ? CheckoutSession::where('session_token', $token)->first() : null;
        $affiliateRef = isset($validated['affiliate_ref']) ? (string) $validated['affiliate_ref'] : null;

        if ($session !== null) {
            $merged = $this->mergeSessionUtms($session, $fromRequest);
            foreach ($merged as $key => $value) {
                if (is_string($value) && trim($value) !== '') {
                    $orderMetadata[$key] = trim($value);
                }
            }

            // Se o ref veio no pay (ex.: sessionStorage) mas a sessão foi recriada sem ?ref=,
            // grava na sessão para não perder atribuição em reprocessamentos.
            $refTrim = is_string($affiliateRef) ? trim($affiliateRef) : '';
            if ($refTrim !== '' && Schema::hasColumn('checkout_sessions', 'affiliate_ref')) {
                $existingRef = trim((string) ($session->affiliate_ref ?? ''));
                if ($existingRef === '') {
                    $session->affiliate_ref = $refTrim;
                    $session->save();
                }
            }

            if ($product === null && ! empty($session->product_id)) {
                $product = Product::query()->find($session->product_id);
            }
            if ($product instanceof Product) {
                $orderMetadata = AffiliateOrderMetadata::merge($orderMetadata, $product, $affiliateRef, $session);
            }

            return $orderMetadata;
        }

        foreach ($fromRequest as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $orderMetadata[$key] = trim($value);
            }
        }

        if ($product instanceof Product) {
            $orderMetadata = AffiliateOrderMetadata::merge($orderMetadata, $product, $affiliateRef);
        }

        return $orderMetadata;
    }
}
