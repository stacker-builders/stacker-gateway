<?php

namespace App\Http\Controllers;

use App\Events\BoletoGenerated;
use App\Events\OrderPending;
use App\Events\PixGenerated;
use App\Gateways\GatewayRegistry;
use App\Gateways\Versell\VersellCredentials;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\EfiPixRecorrenteService;
use App\Services\MinimumChargeService;
use App\Services\PaymentService;
use App\Services\PlatformCardInstallments;
use App\Services\SubscriptionRenewalService;
use App\Services\LinaOpenx\LinaOpenxPixAutoRenewalService;
use App\Services\Versell\VersellPixAutoRenewalService;
use App\Support\CheckoutCardContract;
use App\Support\FakeConsumerData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RenewalController extends Controller
{
    public function show(string $token): Response|RedirectResponse
    {
        $subscription = Subscription::with(['user', 'product', 'subscriptionPlan'])
            ->where('renewal_token', $token)
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->first();

        if (! $subscription || $subscription->subscriptionPlan->isLifetime()) {
            return redirect()->route('login')->with('error', 'Link de renovação inválido ou expirado.');
        }

        $product = $subscription->product;
        $plan = $subscription->subscriptionPlan;
        $amount = (float) $plan->price;
        $currency = $plan->getCurrencyOrDefault();
        $rates = config('products.rates', ['brl_eur' => 0.16, 'brl_usd' => 0.18]);
        if ($currency !== 'BRL') {
            $amount = $currency === 'EUR' ? $amount / ($rates['brl_eur'] ?? 0.16) : $amount / ($rates['brl_usd'] ?? 0.18);
        }

        $tenantId = $subscription->tenant_id;
        $availablePaymentMethods = app(PaymentService::class)->availablePaymentMethodsForCheckout($product, $plan, $subscription);
        $savedPaymentMethods = $subscription->user->savedPaymentMethods()->forTenant($tenantId)->get()->map(fn ($m) => [
            'id' => $m->id,
            'type' => $m->type,
            'last_four' => $m->last_four,
            'brand' => $m->brand,
        ])->all();

        $productArray = [
            'id' => $product->id,
            'name' => $product->name,
            'checkout_slug' => $plan->checkout_slug,
        ];
        $planArray = [
            'id' => $plan->id,
            'name' => $plan->name,
            'price' => (float) $plan->price,
            'currency' => $currency,
            'interval' => $plan->interval,
        ];

        $defaults = Product::defaultCheckoutConfig();
        $productConfig = $product->checkout_config ?? [];
        $config = array_replace_recursive($defaults, $productConfig);
        if ($plan->checkout_config !== null && $plan->checkout_config !== []) {
            $config = array_replace_recursive($config, $plan->checkout_config);
        }
        $cardInstallmentsConfig = $config['card_installments'] ?? ['enabled' => false, 'max' => 1];
        $resolvedInstallments = PlatformCardInstallments::forProductConfig(
            is_array($cardInstallmentsConfig) ? $cardInstallmentsConfig : [],
            true
        );

        $payload = [
            'token' => $token,
            'subscription' => [
                'id' => $subscription->id,
                'current_period_end' => $subscription->current_period_end?->toDateString(),
            ],
            'product' => $productArray,
            'plan' => $planArray,
            'amount' => round($amount, 2),
            'amount_brl' => round($amount, 2),
            'available_payment_methods' => $availablePaymentMethods,
            'saved_payment_methods' => $savedPaymentMethods,
            'card_gateway_slug' => null,
            'card_payee_code' => '',
            'card_efi_sandbox' => false,
            'card_stripe_publishable_key' => '',
            'card_stripe_sandbox' => false,
            'card_stripe_link_enabled' => true,
            'card_mercadopago_public_key' => '',
            'card_mercadopago_sandbox' => false,
            'card_pagarme_public_key' => '',
            'card_pagarme_api_base_url' => rtrim((string) config('services.pagarme.base_url', 'https://api.pagar.me/core/v5'), '/'),
            'card_pagbank_public_key' => '',
            'card_gateway_keys' => [],
            'card_installments_enabled' => $resolvedInstallments['enabled'],
            'card_max_installments' => $resolvedInstallments['max'],
            'customer_cpf' => $subscription->user?->document ? preg_replace('/\D/', '', (string) $subscription->user->document) : '',
        ];

        $this->fillRenewalCardPayload($payload, $product, $tenantId, $availablePaymentMethods);

        return Inertia::render('Renewal/Show', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload  passed by reference for merge
     * @param  array<int, array<string, mixed>>  $availablePaymentMethods
     */
    private function fillRenewalCardPayload(array &$payload, Product $product, int $tenantId, array $availablePaymentMethods): void
    {
        foreach ($availablePaymentMethods as $m) {
            if (($m['id'] ?? '') === 'card' && ($m['gateway_slug'] ?? '') === 'efi') {
                $cred = GatewayCredential::resolveForTenantOrGlobal($tenantId, 'efi');
                if ($cred) {
                    $creds = $cred->getDecryptedCredentials();
                    $payload['card_gateway_slug'] = 'efi';
                    $payload['card_payee_code'] = (string) ($creds['payee_code'] ?? '');
                    $payload['card_efi_sandbox'] = ! empty($creds['sandbox']);
                }
                break;
            }
        }
        foreach ($availablePaymentMethods as $m) {
            if (($m['id'] ?? '') === 'card' && ($m['gateway_slug'] ?? '') === 'stripe') {
                $cred = GatewayCredential::resolveForTenantOrGlobal($tenantId, 'stripe');
                if ($cred) {
                    $creds = $cred->getDecryptedCredentials();
                    $payload['card_gateway_slug'] = 'stripe';
                    $payload['card_stripe_publishable_key'] = (string) ($creds['publishable_key'] ?? '');
                    $payload['card_stripe_sandbox'] = ! empty($creds['sandbox']);
                    $payload['card_stripe_link_enabled'] = isset($creds['link_enabled'])
                        ? (bool) $creds['link_enabled']
                        : true;
                }
                break;
            }
        }
        foreach ($availablePaymentMethods as $m) {
            if (($m['id'] ?? '') === 'card' && ($m['gateway_slug'] ?? '') === 'mercadopago') {
                $cred = GatewayCredential::resolveForTenantOrGlobal($tenantId, 'mercadopago');
                if ($cred) {
                    $creds = $cred->getDecryptedCredentials();
                    $payload['card_gateway_slug'] = 'mercadopago';
                    $payload['card_mercadopago_public_key'] = (string) ($creds['public_key'] ?? '');
                    $payload['card_mercadopago_sandbox'] = ! empty($creds['sandbox']);
                }
                break;
            }
        }
        foreach ($availablePaymentMethods as $m) {
            if (($m['id'] ?? '') === 'card' && ($m['gateway_slug'] ?? '') === 'pagarme') {
                $cred = GatewayCredential::resolveForTenantOrGlobal($tenantId, 'pagarme');
                if ($cred) {
                    $creds = $cred->getDecryptedCredentials();
                    $payload['card_gateway_slug'] = 'pagarme';
                    $payload['card_pagarme_public_key'] = (string) ($creds['public_key'] ?? '');
                }
                break;
            }
        }
        foreach ($availablePaymentMethods as $m) {
            if (($m['id'] ?? '') === 'card' && ($m['gateway_slug'] ?? '') === 'pagbank') {
                $cred = GatewayCredential::resolveForTenantOrGlobal($tenantId, 'pagbank');
                if ($cred) {
                    $creds = $cred->getDecryptedCredentials();
                    $payload['card_gateway_slug'] = 'pagbank';
                    $payload['card_pagbank_public_key'] = (string) ($creds['public_key'] ?? '');
                }
                break;
            }
        }
        foreach ($availablePaymentMethods as $m) {
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
            $cred = GatewayCredential::resolveForPayment($tenantId, $slug);
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
                    && trim((string) ($creds['sop_client_secret'] ?? '')) !== '';
            }
        }
        foreach ($availablePaymentMethods as $m) {
            if (($m['id'] ?? '') === 'card' && ($m['gateway_slug'] ?? '') === 'cielo') {
                $cred = GatewayCredential::resolveForPayment($tenantId, 'cielo');
                if ($cred) {
                    $payload['card_gateway_slug'] = 'cielo';
                }
                break;
            }
        }
    }

    public function process(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'payment_method' => ['nullable', 'string', 'in:pix,card,boleto,pix_auto,manual,apple_pay,google_pay'],
            'payment_token' => ['nullable', 'string', 'max:8192'],
            'card_mask' => ['nullable', 'string', 'max:64'],
        ]);

        $subscription = Subscription::with(['user', 'product', 'subscriptionPlan'])
            ->where('renewal_token', $request->input('token'))
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->first();

        if (! $subscription || $subscription->subscriptionPlan->isLifetime()) {
            return redirect()->route('login')->with('error', 'Link de renovação inválido ou expirado.');
        }

        $product = $subscription->product;
        $plan = $subscription->subscriptionPlan;
        $user = $subscription->user;
        $tenantId = $subscription->tenant_id;
        $amount = (float) $plan->price;
        $currency = $plan->getCurrencyOrDefault();
        $rates = config('products.rates', ['brl_eur' => 0.16, 'brl_usd' => 0.18]);
        if ($currency !== 'BRL') {
            $amount = $currency === 'EUR' ? $amount / ($rates['brl_eur'] ?? 0.16) : $amount / ($rates['brl_usd'] ?? 0.18);
        }

        app(MinimumChargeService::class)->assertPlatformCheckout(round($amount, 2), (int) $tenantId);

        [$periodStart, $periodEnd] = $plan->getCurrentPeriod();
        $paymentMethod = $request->input('payment_method', 'manual');

        if ($paymentMethod !== 'manual') {
            $allowedIds = array_column(
                app(PaymentService::class)->availablePaymentMethodsForCheckout($product, $plan, $subscription),
                'id'
            );
            if (! in_array($paymentMethod, $allowedIds, true)) {
                return back()->with('error', 'Método de pagamento não disponível para este produto.');
            }
        }

        $orderPayload = [
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_offer_id' => null,
            'subscription_plan_id' => $plan->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'is_renewal' => true,
            'amount' => $amount,
            'email' => $user->email,
            'cpf' => null,
            'phone' => null,
            'customer_ip' => $request->ip(),
            'coupon_code' => null,
        ];

        if ($paymentMethod === 'pix_auto' && $subscription->gateway_subscription_id) {
            $pixAutoGateway = Order::query()
                ->where('user_id', $subscription->user_id)
                ->where('product_id', $subscription->product_id)
                ->where('payment_method', 'pix_auto')
                ->whereIn('gateway', ['efi', 'versell', 'linaopenx'])
                ->orderByDesc('id')
                ->value('gateway');
            $pixAutoGateway = is_string($pixAutoGateway) && $pixAutoGateway !== '' ? $pixAutoGateway : 'efi';

            $credential = GatewayCredential::resolveForPayment($tenantId, $pixAutoGateway);
            if ($credential) {
                $credentials = $credential->getDecryptedCredentials();
                $ready = match ($pixAutoGateway) {
                    'versell' => VersellCredentials::isCashInReady($credentials),
                    'linaopenx' => trim((string) ($credentials['client_id'] ?? '')) !== ''
                        && trim((string) ($credentials['client_secret'] ?? '')) !== '',
                    default => ! empty($credentials['certificate_path']),
                };

                if ($ready) {
                    if ($pixAutoGateway === 'versell') {
                        $renewal = app(VersellPixAutoRenewalService::class);
                        [$nextStart, $nextEnd] = $renewal->nextPeriod($subscription, $plan);
                        $existing = $renewal->findPendingRenewal($subscription, $nextEnd->toDateString());
                        if ($existing !== null && trim((string) $existing->gateway_id) !== '') {
                            return redirect()->route('renewal.show', $request->input('token'))
                                ->with('info', 'O débito PIX automático já está agendado. Você receberá a confirmação quando o pagamento for processado.');
                        }

                        $scheduled = $renewal->ensureNextCobr($subscription);
                        if ($scheduled !== null && trim((string) $scheduled->gateway_id) !== '') {
                            return redirect()->route('renewal.show', $request->input('token'))
                                ->with('info', 'O débito PIX automático foi agendado. Você receberá a confirmação quando o pagamento for processado.');
                        }

                        return back()->with('error', 'Não foi possível agendar o PIX automático. A recorrência precisa estar aprovada pelo pagador.');
                    }

                    if ($pixAutoGateway === 'linaopenx') {
                        $renewal = app(LinaOpenxPixAutoRenewalService::class);
                        [$nextStart, $nextEnd] = $renewal->nextPeriod($subscription, $plan);
                        $existing = $renewal->findPendingRenewal($subscription, $nextEnd->toDateString());
                        if ($existing !== null && trim((string) $existing->gateway_id) !== '') {
                            return redirect()->route('renewal.show', $request->input('token'))
                                ->with('info', 'O débito PIX automático já está agendado. Você receberá a confirmação quando o pagamento for processado.');
                        }

                        $scheduled = $renewal->ensureNextPayment($subscription);
                        if ($scheduled !== null && trim((string) $scheduled->gateway_id) !== '') {
                            return redirect()->route('renewal.show', $request->input('token'))
                                ->with('info', 'O débito PIX automático foi agendado. Você receberá a confirmação quando o pagamento for processado.');
                        }

                        return back()->with('error', 'Não foi possível agendar o PIX automático. O contrato precisa estar autorizado pelo pagador.');
                    }

                    $order = Order::create(array_merge($orderPayload, [
                        'status' => 'pending',
                        'gateway' => $pixAutoGateway,
                        'gateway_id' => null,
                        'payment_method' => 'pix_auto',
                        'metadata' => [
                            'checkout_payment_method' => 'pix_auto',
                            'efi_pix_auto_id_rec' => $subscription->gateway_subscription_id,
                        ],
                    ]));
                    event(new OrderPending($order));
                    try {
                        $idRec = $subscription->gateway_subscription_id;
                        $dataDeVencimento = $periodEnd ? $periodEnd->format('Y-m-d') : now()->addMonth()->format('Y-m-d');
                        $base = 'pixautorenov'.$order->id;
                        $txid = $base.Str::random(max(26 - strlen($base), 10));
                        $txid = substr($txid, 0, 35);
                        $devedor = [
                            'name' => $user->name ?? $user->email,
                            'email' => $user->email,
                        ];
                        $service = new EfiPixRecorrenteService($credentials);
                        $service->createCobrancaRecorrente(
                            $idRec,
                            $amount,
                            $dataDeVencimento,
                            $txid,
                            $devedor,
                            'Renovação assinatura #'.$subscription->id
                        );
                        $order->update(['gateway_id' => $txid]);

                        return redirect()->route('renewal.show', $request->input('token'))
                            ->with('info', 'O débito PIX automático foi agendado. Você receberá a confirmação quando o pagamento for processado.');
                    } catch (\Throwable $e) {
                        $order->delete();

                        return back()->with('error', $e->getMessage() ?: 'Não foi possível agendar o PIX automático. Tente outro método.');
                    }
                }
            }
        }

        if ($paymentMethod === 'pix') {
            $order = Order::create(array_merge($orderPayload, [
                'status' => 'pending',
                'gateway' => null,
                'gateway_id' => null,
                'payment_method' => 'pix',
                'metadata' => ['checkout_payment_method' => 'pix'],
            ]));
            event(new OrderPending($order));
            try {
                $paymentService = app(PaymentService::class);
                $consumer = $this->renewalConsumer($user, $order->id);
                $pixResult = $paymentService->createPixPayment($order, $product, $consumer);
                event(new PixGenerated($order, [
                    'qrcode' => $pixResult['qrcode'] ?? null,
                    'copy_paste' => $pixResult['copy_paste'] ?? null,
                    'transaction_id' => $pixResult['transaction_id'] ?? null,
                ]));
                $pixToken = Str::random(32);
                session()->put('pix_display.'.$pixToken, [
                    'order_id' => $order->id,
                    'qrcode' => $pixResult['qrcode'] ?? null,
                    'copy_paste' => $pixResult['copy_paste'] ?? null,
                    'amount' => $amount,
                    'product_name' => $product->name,
                    'checkout_slug' => $plan->checkout_slug,
                    'redirect_after_purchase' => null,
                    'customer_name' => $user->name,
                    'customer_email' => $user->email,
                    'customer_phone' => null,
                    'created_at' => time(),
                ]);

                return redirect()->route('checkout.pix', ['token' => $pixToken]);
            } catch (\Throwable $e) {
                $order->delete();

                return back()->with('error', $e->getMessage() ?: 'Não foi possível gerar o PIX. Tente novamente.');
            }
        }

        if ($paymentMethod === 'boleto') {
            $order = Order::create(array_merge($orderPayload, [
                'status' => 'pending',
                'gateway' => null,
                'gateway_id' => null,
                'payment_method' => 'boleto',
                'metadata' => ['checkout_payment_method' => 'boleto'],
            ]));
            event(new OrderPending($order));
            try {
                $paymentService = app(PaymentService::class);
                $consumer = $this->renewalConsumer($user, $order->id);
                $boletoResult = $paymentService->createBoletoPayment($order, $product, $consumer);
                $boletoData = [
                    'amount' => $boletoResult['amount'] ?? $amount,
                    'expire_at' => $boletoResult['expire_at'] ?? null,
                    'barcode' => $boletoResult['barcode'] ?? null,
                    'pdf_url' => $boletoResult['pdf_url'] ?? null,
                ];
                event(new BoletoGenerated($order, $boletoData));
                $boletoToken = Str::random(32);
                $amountFormatted = 'R$ '.number_format((float) ($boletoResult['amount'] ?? $amount), 2, ',', '.');
                session()->put('boleto_display.'.$boletoToken, [
                    'order_id' => $order->id,
                    'amount' => $boletoResult['amount'] ?? $amount,
                    'amount_formatted' => $amountFormatted,
                    'expire_at' => $boletoResult['expire_at'] ?? null,
                    'barcode' => $boletoResult['barcode'] ?? null,
                    'pdf_url' => $boletoResult['pdf_url'] ?? null,
                    'product_name' => $product->name,
                    'checkout_slug' => $plan->checkout_slug,
                    'redirect_after_purchase' => null,
                    'customer_name' => $user->name,
                    'customer_email' => $user->email,
                    'customer_phone' => null,
                    'created_at' => time(),
                ]);

                return redirect()->route('checkout.boleto', ['token' => $boletoToken]);
            } catch (\Throwable $e) {
                $order->delete();

                return back()->with('error', $e->getMessage() ?: 'Não foi possível gerar o boleto. Tente novamente.');
            }
        }

        if ($paymentMethod === 'card') {
            $card = CheckoutCardContract::fromRequest($request->all());
            if (($card['payment_token'] ?? '') === '') {
                return back()->with('error', 'Informe os dados do cartão (token de pagamento ausente).');
            }
            $order = Order::create(array_merge($orderPayload, [
                'status' => 'pending',
                'gateway' => null,
                'gateway_id' => null,
                'payment_method' => 'card',
                'metadata' => ['checkout_payment_method' => 'card'],
            ]));
            event(new OrderPending($order));
            try {
                $paymentService = app(PaymentService::class);
                $consumer = $this->renewalConsumer($user, $order->id);
                $card['currency'] = strtolower($currency);
                $checkoutSlug = (string) ($plan->checkout_slug ?? $product->checkout_slug ?? '');
                if ($checkoutSlug !== '') {
                    $card['return_url'] = url()->route('checkout.show', ['slug' => $checkoutSlug]);
                }
                $cardResult = $paymentService->createCardPayment($order, $product, $consumer, $card);
                $status = $cardResult['status'] ?? null;
                if (($status ?? '') === 'requires_action' && ! empty($cardResult['client_secret'])) {
                    $order->delete();

                    return back()->with('error', 'O cartão exige confirmação adicional (3DS). Use PIX, boleto ou finalize em um checkout completo.');
                }
                if (in_array($status, ['paid', 'settled', 'approved', 'completed'], true)) {
                    app(SubscriptionRenewalService::class)->applySuccessfulRenewal($order->fresh(), $subscription->fresh(), $plan);

                    return redirect()->route('renewal.show', $request->input('token'))
                        ->with('success', 'Renovação concluída. Seu acesso foi estendido.');
                }
                $order->delete();

                return back()->with('error', 'Pagamento não aprovado. Tente outro método ou cartão.');
            } catch (\Throwable $e) {
                $order->delete();

                return back()->with('error', $e->getMessage() ?: 'Não foi possível processar o cartão. Tente novamente.');
            }
        }

        $order = Order::create(array_merge($orderPayload, [
            'status' => 'completed',
            'gateway' => 'manual',
            'payment_method' => 'pix',
        ]));
        app(SubscriptionRenewalService::class)->applySuccessfulRenewal($order, $subscription, $plan);

        return redirect()->route('renewal.show', $request->input('token'))
            ->with('success', 'Renovação concluída. Seu acesso foi estendido.');
    }

    /**
     * @return array{name: string, document: string, email: string, phone: string, address?: array<string, string>}
     */
    private function renewalConsumer(\App\Models\User $user, int|string $seed): array
    {
        $fake = FakeConsumerData::getForGateway($seed);
        $rawDoc = preg_replace('/\D/', '', (string) ($user->document ?? '')) ?? '';
        $consumer = [
            'name' => trim((string) ($user->name ?: $user->email ?: $fake['name'])),
            'document' => strlen($rawDoc) >= 11 ? $rawDoc : (string) $fake['document'],
            'email' => (string) $user->email,
            'phone' => trim((string) ($user->phone ?? '')),
        ];
        $zip = preg_replace('/\D/', '', (string) ($user->address_zip ?? '')) ?? '';
        $street = trim((string) ($user->address_street ?? ''));
        $city = trim((string) ($user->address_city ?? ''));
        $uf = strtoupper(substr(trim((string) ($user->address_state ?? '')), 0, 2));
        if (strlen($zip) >= 8 && $street !== '' && $city !== '' && strlen($uf) === 2) {
            $consumer['address'] = [
                'zip_code' => substr($zip, 0, 8),
                'street_name' => $street,
                'street_number' => trim((string) ($user->address_number ?? '')) ?: 'S/N',
                'neighborhood' => trim((string) ($user->address_neighborhood ?? '')) ?: 'Centro',
                'city' => $city,
                'federal_unit' => $uf,
                'complement' => trim((string) ($user->address_complement ?? '')),
            ];
        }

        return $consumer;
    }
}
