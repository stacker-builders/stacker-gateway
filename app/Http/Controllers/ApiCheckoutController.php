<?php

namespace App\Http\Controllers;

use App\Events\BoletoGenerated;
use App\Events\OrderCompleted;
use App\Events\OrderPending;
use App\Events\PixGenerated;
use App\Gateways\Versell\VersellCredentials;
use App\Models\ApiApplication;
use App\Models\ApiCheckoutSession;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Services\Checkout\CheckoutAbuseGuard;
use App\Services\EfiPixRecorrenteService;
use App\Services\MinimumChargeService;
use App\Services\PaymentService;
use App\Services\SubscriptionRenewalService;
use App\Services\PushinPayPixRecorrenteService;
use App\Services\Shipping\CheckoutShippingHelper;
use App\Services\StorageService;
use App\Services\Versell\VersellPixRecorrenteService;
use App\Support\CheckoutTurnstileSettings;
use App\Support\FakeConsumerData;
use App\Support\GatewayWebhookUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ApiCheckoutController extends Controller
{
    /**
     * Show hosted checkout page (payment method selection only; customer data from session).
     */
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $session = ApiCheckoutSession::where('session_token', $token)->with('apiApplication')->first();
        if (! $session || $session->isExpired()) {
            abort(404, 'Sessão inválida ou expirada.');
        }
        $app = $session->apiApplication;
        if (! $app || ! $app->is_active) {
            abort(404, 'Aplicação indisponível.');
        }

        $pg = $app->payment_gateways ?? ApiApplication::defaultPaymentGateways();
        $pg = is_array($pg) ? $pg : ApiApplication::defaultPaymentGateways();

        $productModel = null;
        $productName = null;
        $productImageUrl = null;
        if ($session->product_id) {
            $productModel = Product::where('id', $session->product_id)->where('tenant_id', $app->tenant_id)->first();
        } elseif ($session->subscription_plan_id) {
            $plan = SubscriptionPlan::with('product')->find($session->subscription_plan_id);
            if ($plan && $plan->product && (int) $plan->product->tenant_id === (int) $app->tenant_id) {
                $productModel = $plan->product;
            }
        } elseif ($session->product_offer_id) {
            $offer = ProductOffer::with('product')->find($session->product_offer_id);
            if ($offer && $offer->product && (int) $offer->product->tenant_id === (int) $app->tenant_id) {
                $productModel = $offer->product;
            }
        }
        if ($productModel && ! $productModel->isAvailableForPurchase()) {
            abort(403, 'Este produto não está disponível para compra.');
        }
        if ($productModel) {
            $productName = $productModel->name;
            if ($productModel->image) {
                $productImageUrl = (new StorageService($productModel->tenant_id))->url($productModel->image);
            }
        }

        $paymentService = app(PaymentService::class);
        $tenantId = $app->tenant_id;
        $pixEnabled = ! empty($pg['pix']);
        $pixAutoEnabled = ! empty($pg['pix_auto']);
        $cardEnabled = ! empty($pg['card']);
        $boletoEnabled = ! empty($pg['boleto']);

        $firstPixGateway = $pixEnabled ? $paymentService->getFirstAvailableGatewayForMethod($tenantId, 'pix', $productModel, $pg) : null;
        $firstPixAutoGateway = $pixAutoEnabled ? $paymentService->getFirstAvailableGatewayForMethod($tenantId, 'pix_auto', $productModel, $pg) : null;
        $firstCardGateway = $cardEnabled ? $paymentService->getFirstAvailableGatewayForMethod($tenantId, 'card', $productModel, $pg) : null;
        $firstBoletoGateway = $boletoEnabled ? $paymentService->getFirstAvailableGatewayForMethod($tenantId, 'boleto', $productModel, $pg) : null;

        $cardGatewaySlug = $cardEnabled ? (string) $pg['card'] : null;
        $cardStripePublishableKey = '';
        $cardStripeSandbox = false;
        $cardStripeLinkEnabled = true;
        $cardEfiPayeeCode = '';
        $cardEfiSandbox = false;
        $cardPagarmePublicKey = '';
        $efiHasCertificate = false;

        if ($cardGatewaySlug === 'stripe') {
            $cred = GatewayCredential::resolveForPayment($tenantId, 'stripe');
            if ($cred) {
                $creds = $cred->getDecryptedCredentials();
                $cardStripePublishableKey = (string) ($creds['publishable_key'] ?? '');
                $cardStripeSandbox = ! empty($creds['sandbox']);
                $cardStripeLinkEnabled = isset($creds['link_enabled']) ? (bool) $creds['link_enabled'] : true;
            }
            if (trim($cardStripePublishableKey) === '') {
                $firstCardGateway = null;
            }
        } elseif ($cardGatewaySlug === 'efi') {
            $cred = GatewayCredential::resolveForPayment($tenantId, 'efi');
            if ($cred) {
                $creds = $cred->getDecryptedCredentials();
                $cardEfiPayeeCode = (string) ($creds['payee_code'] ?? '');
                $cardEfiSandbox = ! empty($creds['sandbox']);
                $certPath = (string) ($creds['certificate_path'] ?? '');
                $efiHasCertificate = $certPath !== '' && is_file($certPath);
            }
            if (trim($cardEfiPayeeCode) === '' || ! $efiHasCertificate) {
                $firstCardGateway = null;
            }
        } elseif ($cardGatewaySlug === 'pagarme') {
            $cred = GatewayCredential::resolveForPayment($tenantId, 'pagarme');
            if ($cred) {
                $creds = $cred->getDecryptedCredentials();
                $cardPagarmePublicKey = (string) ($creds['public_key'] ?? '');
            }
            if (trim($cardPagarmePublicKey) === '') {
                $firstCardGateway = null;
            }
        } elseif ($cardGatewaySlug === 'cielo') {
            $cred = GatewayCredential::resolveForPayment($tenantId, 'cielo');
            $merchantId = '';
            $merchantKey = '';
            if ($cred) {
                $creds = $cred->getDecryptedCredentials();
                $merchantId = trim((string) ($creds['merchant_id'] ?? ''));
                $merchantKey = trim((string) ($creds['merchant_key'] ?? ''));
            }
            if ($merchantId === '' || $merchantKey === '' || $productModel === null) {
                $firstCardGateway = null;
            }
        }

        if ($boletoEnabled && ($pg['boleto'] ?? null) === 'efi') {
            if (! $efiHasCertificate) {
                $cred = GatewayCredential::resolveForPayment($tenantId, 'efi');
                if ($cred) {
                    $creds = $cred->getDecryptedCredentials();
                    $certPath = (string) ($creds['certificate_path'] ?? '');
                    $efiHasCertificate = $certPath !== '' && is_file($certPath);
                }
            }
            if (! $efiHasCertificate) {
                $firstBoletoGateway = null;
            }
        }

        $availableMethods = [];
        if ($firstPixGateway !== null) {
            $availableMethods[] = 'pix';
        }
        if ($firstPixAutoGateway !== null) {
            $availableMethods[] = 'pix_auto';
        }
        if ($firstCardGateway !== null) {
            $availableMethods[] = 'card';
        }
        if ($firstBoletoGateway !== null) {
            $availableMethods[] = 'boleto';
        }
        if (empty($availableMethods)) {
            abort(422, 'Nenhum método de pagamento configurado para esta aplicação.');
        }

        $customer = $session->customer ?? [];
        $appLogoUrl = $app->logo
            ? (new StorageService($app->tenant_id))->url($app->logo)
            : null;

        $currenciesRaw = Setting::get('currencies', null, null);
        $currencies = $currenciesRaw
            ? (is_string($currenciesRaw) ? json_decode($currenciesRaw, true) : $currenciesRaw)
            : config('products.currencies');
        $currencies = is_array($currencies) ? $currencies : config('products.currencies');

        return Inertia::render('ApiCheckout/Show', [
            'session_token' => $token,
            'app_name' => $app->name,
            'app_logo_url' => $appLogoUrl,
            'app_sidebar_bg_color' => $app->checkout_sidebar_bg ?? '#18181b',
            'customer_email' => $customer['email'] ?? null,
            'customer_name' => $customer['name'] ?? null,
            'customer_cpf' => $customer['cpf'] ?? null,
            'amount' => (float) $session->amount,
            'currency' => $session->currency ?? 'BRL',
            'currencies' => $currencies,
            'product_id' => $productModel?->id,
            'product_name' => $productName,
            'product_image_url' => $productImageUrl,
            'available_methods' => $availableMethods,
            'return_url' => $session->return_url,
            'card_gateway_slug' => $cardGatewaySlug,
            'card_stripe_publishable_key' => $cardStripePublishableKey,
            'card_stripe_sandbox' => $cardStripeSandbox,
            'card_stripe_link_enabled' => $cardStripeLinkEnabled,
            'card_efi_payee_code' => $cardEfiPayeeCode,
            'card_efi_sandbox' => $cardEfiSandbox,
            'card_pagarme_public_key' => $cardPagarmePublicKey,
            'card_pagarme_api_base_url' => rtrim((string) config('services.pagarme.base_url', 'https://api.pagar.me/core/v5'), '/'),
            'turnstile' => CheckoutTurnstileSettings::publicConfig(),
        ]);
    }

    /**
     * Process payment for the session (PIX, boleto, card with token).
     */
    public function process(Request $request): RedirectResponse
    {
        $rules = [
            'session_token' => ['required', 'string', 'max:64'],
            'payment_method' => ['required', 'string', 'in:pix,pix_auto,boleto,card'],
            'website' => ['nullable', 'string', 'max:255'],
            '_hp' => ['nullable', 'string', 'max:255'],
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
        ];
        if ($request->input('payment_method') === 'pix_auto') {
            $rules['cpf'] = ['required', 'string', 'max:14'];
        }
        if ($request->input('payment_method') === 'card') {
            $rules['payment_token'] = ['required', 'string', 'max:10000'];
            $rules['card_mask'] = ['nullable', 'string', 'max:32'];
        }
        $validated = $request->validate($rules);

        $session = ApiCheckoutSession::where('session_token', $validated['session_token'])->with('apiApplication')->first();
        if (! $session || $session->isExpired()) {
            return redirect()->back()->with('error', 'Sessão inválida ou expirada.');
        }
        $app = $session->apiApplication;
        if (! $app || ! $app->is_active) {
            return redirect()->back()->with('error', 'Aplicação indisponível.');
        }

        $tenantId = $app->tenant_id;
        $gatewayConfig = $app->payment_gateways ?? ApiApplication::defaultPaymentGateways();
        $gatewayConfig = is_array($gatewayConfig) ? $gatewayConfig : ApiApplication::defaultPaymentGateways();
        $pg = $gatewayConfig;
        $method = $validated['payment_method'];
        if ($method === 'pix' && empty($pg['pix'])) {
            return redirect()->back()->with('error', 'Método de pagamento não disponível.');
        }
        if ($method === 'pix_auto' && empty($pg['pix_auto'])) {
            return redirect()->back()->with('error', 'Método de pagamento não disponível.');
        }
        if ($method === 'card' && empty($pg['card'])) {
            return redirect()->back()->with('error', 'Método de pagamento não disponível.');
        }
        if ($method === 'boleto' && empty($pg['boleto'])) {
            return redirect()->back()->with('error', 'Método de pagamento não disponível.');
        }

        $productModel = null;
        if ($session->product_id) {
            $productModel = Product::where('id', $session->product_id)->where('tenant_id', $tenantId)->first();
        } elseif ($session->subscription_plan_id) {
            $plan = SubscriptionPlan::with('product')->find($session->subscription_plan_id);
            if ($plan && $plan->product && (int) $plan->product->tenant_id === (int) $tenantId) {
                $productModel = $plan->product;
            }
        } elseif ($session->product_offer_id) {
            $offer = ProductOffer::with('product')->find($session->product_offer_id);
            if ($offer && $offer->product && (int) $offer->product->tenant_id === (int) $tenantId) {
                $productModel = $offer->product;
            }
        }

        $checkoutGuard = app(CheckoutAbuseGuard::class);
        if ($productModel) {
            $fpPayload = [
                'email' => strtolower(trim((string) (is_array($session->customer) ? ($session->customer['email'] ?? '') : ''))),
                'product_id' => $productModel->id,
                'payment_method' => $method,
            ];
            $fingerprintCached = $checkoutGuard->cachedResponseForFingerprint($request, $fpPayload);
            if ($fingerprintCached instanceof RedirectResponse) {
                return $fingerprintCached;
            }
            $checkoutGuard->assertCanProcessApiHosted($request, $productModel, $validated, $session);
        } else {
            $checkoutGuard->assertCanProcessApiAmountOnly($request, $validated, $session);
        }

        $customer = is_array($session->customer) ? $session->customer : [];
        if ($method === 'card') {
            $cardGw = strtolower((string) ($pg['card'] ?? ''));
            if ($cardGw === 'pagarme' && strtoupper((string) ($session->currency ?? 'BRL')) === 'BRL') {
                $cpfDigits = preg_replace('/\D/', '', (string) ($customer['cpf'] ?? ''));
                if (strlen($cpfDigits) < 11) {
                    return redirect()->back()->with('error', 'CPF do comprador é obrigatório para cartão Pagar.me em BRL. Inclua `cpf` no customer ao criar a sessão de checkout.');
                }
            }
        }
        if ($method === 'pix_auto') {
            $cpf = (string) ($validated['cpf'] ?? '');
            $customer['cpf'] = $cpf;
            $session->update([
                'customer' => array_merge(is_array($session->customer) ? $session->customer : [], ['cpf' => $cpf]),
            ]);
        }
        $email = $customer['email'] ?? '';
        $name = trim((string) ($customer['name'] ?? ''));
        if ($name === '') {
            $name = $email;
        }
        $buyer = app(\App\Services\BuyerAccountService::class)->ensureBuyerFromCheckout(
            $email,
            $name,
            bcrypt(Str::random(32)),
            false,
        );
        $user = $buyer['user'];

        $rawDoc = preg_replace('/\D/', '', (string) ($customer['cpf'] ?? ''));
        $fake = FakeConsumerData::getForGateway($session->id);
        $consumer = [
            'name' => $name ?: $fake['name'],
            'document' => strlen($rawDoc) >= 11 ? $rawDoc : $fake['document'],
            'email' => $email,
            'phone' => trim((string) ($customer['phone'] ?? '')),
        ];

        $product = null;
        if ($session->product_id) {
            $product = Product::where('id', $session->product_id)->where('tenant_id', $tenantId)->first();
        }
        $amount = (float) $session->amount;
        $productOfferId = $session->product_offer_id;
        $subscriptionPlanId = $session->subscription_plan_id;
        $plan = null;
        $periodStart = null;
        $periodEnd = null;
        if (! $product && $subscriptionPlanId) {
            $plan = SubscriptionPlan::with('product')->find($subscriptionPlanId);
            if ($plan && $plan->product && (int) $plan->product->tenant_id === (int) $tenantId) {
                $product = $plan->product;
            } else {
                $plan = null;
            }
        }
        if (! $product && $productOfferId) {
            $offer = ProductOffer::with('product')->find($productOfferId);
            if ($offer && $offer->product && (int) $offer->product->tenant_id === (int) $tenantId) {
                $product = $offer->product;
            }
        }
        if ($product && ! $product->isAvailableForPurchase()) {
            return redirect()->back()->with('error', 'Este produto não está disponível para compra no momento.');
        }
        if ($method === 'pix_auto' && $product && ! $subscriptionPlanId) {
            $plan = SubscriptionPlan::where('product_id', $product->id)->orderBy('position')->first();
            if ($plan) {
                $subscriptionPlanId = $plan->id;
            }
        }
        if ($product && $subscriptionPlanId) {
            if (! $plan) {
                $plan = SubscriptionPlan::find($subscriptionPlanId);
            }
            if ($plan && $plan->product_id === $product->id) {
                [$periodStart, $periodEnd] = $plan->getCurrentPeriod();
            } else {
                $plan = null;
            }
        }
        if ($method === 'pix_auto' && strlen($rawDoc) < 11) {
            return redirect()->back()->with('error', 'CPF do comprador é obrigatório para PIX automático.');
        }

        $paymentService = app(PaymentService::class);
        $availableGateway = $paymentService->getFirstAvailableGatewayForMethod($tenantId, $method, $product, $gatewayConfig);
        if ($availableGateway === null) {
            return redirect()->back()->with('error', 'Método de pagamento não disponível.');
        }

        if ($method === 'pix') {
            $pixGw = $paymentService->getFirstAvailableGatewayForMethod($tenantId, 'pix', $product, $gatewayConfig);
            if ($pixGw === 'pagarme' && trim((string) ($customer['phone'] ?? '')) === '') {
                return redirect()->back()->with('error', 'Telefone do comprador é obrigatório para PIX (Pagar.me). Inclua phone ao criar a sessão de checkout.');
            }
        }

        $shippingHelper = app(CheckoutShippingHelper::class);
        $shippingResolved = null;
        $orderMetadata = array_merge($session->metadata ?? [], [
            'source' => 'api_checkout_pro',
            'checkout_payment_method' => $method,
        ]);
        if ($product && $shippingHelper->productRequiresShipping($product)) {
            if (strtoupper((string) ($session->currency ?? 'BRL')) !== 'BRL') {
                return redirect()->back()->with('error', 'Produtos físicos estão disponíveis apenas em BRL.');
            }
            $addrValidated = $request->validate($shippingHelper->shippingAddressValidationRules());
            try {
                $shippingResolved = $shippingHelper->resolveForCheckout($product, $addrValidated);
                $amount = round($amount + $shippingResolved['shipping_amount'], 2);
                $orderMetadata = array_merge($orderMetadata, $shippingResolved['metadata_shipping']);
            } catch (\RuntimeException $e) {
                return redirect()->back()->with('error', $e->getMessage());
            }
        }

        try {
            app(MinimumChargeService::class)->assertApiPayment($amount, (int) $tenantId);
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?? 'Valor abaixo do mínimo para cobrança via API.';

            return redirect()->back()->with('error', $msg);
        }

        $orderPayload = [
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'product_id' => $product?->id,
            'product_offer_id' => $productOfferId,
            'subscription_plan_id' => $subscriptionPlanId,
            'api_application_id' => $app->id,
            'api_checkout_session_id' => $session->id,
            'status' => 'pending',
            'amount' => $amount,
            'email' => $email,
            'cpf' => $customer['cpf'] ?? null,
            'phone' => $customer['phone'] ?? null,
            'customer_ip' => $request->ip(),
            'coupon_code' => null,
            'gateway' => null,
            'gateway_id' => null,
            'payment_method' => $method,
            'metadata' => $orderMetadata,
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

        $order = Order::create($orderPayload);

        if ($product) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_offer_id' => $productOfferId,
                'subscription_plan_id' => $subscriptionPlanId,
                'amount' => $amount,
                'position' => 0,
            ]);
            app(\App\Services\ProductBundleService::class)->snapshotIncludedProducts($order);
        }

        $session->update(['order_id' => $order->id]);

        if ($method === 'pix_auto') {
            try {
                event(new OrderPending($order));
                $gatewaySlug = (string) ($pg['pix_auto'] ?? '');

                if ($gatewaySlug === 'pushinpay') {
                    $credential = GatewayCredential::resolveForPayment($tenantId, 'pushinpay');
                    if (! $credential) {
                        throw new \RuntimeException('Pushin Pay não configurado para PIX automático.');
                    }
                    $credentials = $credential->getDecryptedCredentials();
                    if (empty($credentials['api_token'])) {
                        throw new \RuntimeException('Pushin Pay: API Token não configurado.');
                    }

                    $webhookUrl = GatewayWebhookUrl::forGateway('pushinpay');
                    $frequency = PushinPayPixRecorrenteService::intervalToFrequency($plan?->interval ?? SubscriptionPlan::INTERVAL_MONTHLY);
                    $subscriptionName = mb_substr(preg_replace('/[^\p{L}\p{N}\s\.\-]/u', '', $product?->name ?? 'Assinatura'), 0, 140) ?: 'Assinatura';
                    $pushinpayService = new PushinPayPixRecorrenteService($credentials);
                    $result = $pushinpayService->createSubscription(
                        (float) $amount,
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

                    event(new PixGenerated($order, [
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                        'transaction_id' => $txid,
                    ]));

                    $pixToken = Str::random(32);
                    session()->put('pix_display.'.$pixToken, [
                        'order_id' => $order->id,
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                        'amount' => $amount,
                        'product_name' => $product?->name ?? 'Pagamento',
                        'redirect_after_purchase' => route('api-checkout.thank-you', ['order_id' => $order->id]),
                        'created_at' => time(),
                    ]);

                    return redirect()->route('checkout.pix', ['token' => $pixToken]);
                }

                if ($gatewaySlug === 'efi') {
                    $credential = GatewayCredential::resolveForPayment($tenantId, 'efi');
                    if (! $credential) {
                        throw new \RuntimeException('Gateway Efí não configurado para PIX automático.');
                    }
                    $credentials = $credential->getDecryptedCredentials();
                    if (empty($credentials['certificate_path']) || empty($credentials['pix_key'])) {
                        throw new \RuntimeException('Efí: certificado ou chave PIX não configurados.');
                    }

                    $base = 'pixauto'.$order->id;
                    $txid = $base.Str::random(max(26 - strlen($base), 10));
                    $txid = substr($txid, 0, 35);

                    $efiRecorrente = new EfiPixRecorrenteService($credentials);
                    $locRec = $efiRecorrente->createLocRec();
                    $locId = (int) $locRec['id'];

                    $cob = $efiRecorrente->createCobWithTxid(
                        $txid,
                        (float) $amount,
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
                    $objeto = mb_substr(preg_replace('/[^\p{L}\p{N}\s\.\-]/u', '', $product?->name ?? 'Assinatura'), 0, 140) ?: 'Assinatura';
                    $rec = $efiRecorrente->createRecurrence(
                        $locId,
                        $txid,
                        $consumer,
                        (float) $amount,
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
                        } catch (\Throwable) {
                        }
                    }

                    event(new PixGenerated($order, [
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                        'transaction_id' => $txid,
                    ]));

                    $pixToken = Str::random(32);
                    session()->put('pix_display.'.$pixToken, [
                        'order_id' => $order->id,
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                        'amount' => $amount,
                        'product_name' => $product?->name ?? 'Pagamento',
                        'redirect_after_purchase' => route('api-checkout.thank-you', ['order_id' => $order->id]),
                        'created_at' => time(),
                    ]);

                    return redirect()->route('checkout.pix', ['token' => $pixToken]);
                }

                if ($gatewaySlug === 'versell') {
                    $credential = GatewayCredential::resolveForPayment($tenantId, 'versell');
                    if (! $credential) {
                        throw new \RuntimeException('Versell não configurada para PIX automático.');
                    }
                    $credentials = $credential->getDecryptedCredentials();
                    if (! VersellCredentials::isCashInReady($credentials)) {
                        throw new \RuntimeException('Versell: credenciais Cash In incompletas para PIX automático.');
                    }

                    $base = 'pixauto'.$order->id;
                    $txid = $base.Str::random(max(26 - strlen($base), 10));
                    $txid = substr($txid, 0, 35);
                    $pixKey = (string) (VersellCredentials::apiBlock($credentials, VersellCredentials::API_CASH_IN)['pix_key'] ?? '');

                    $versellRecorrente = new VersellPixRecorrenteService($credentials);
                    $locRec = $versellRecorrente->createLocRec();
                    $locId = (int) $locRec['id'];

                    $cob = $versellRecorrente->createCobWithTxid(
                        $txid,
                        (float) $amount,
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
                    $objeto = mb_substr(preg_replace('/[^\p{L}\p{N}\s\.\-]/u', '', $product?->name ?? 'Assinatura'), 0, 140) ?: 'Assinatura';
                    $rec = $versellRecorrente->createRecurrence(
                        $locId,
                        $txid,
                        $consumer,
                        (float) $amount,
                        $dataInicial,
                        $dataFinal,
                        $contrato,
                        $objeto,
                        VersellPixRecorrenteService::periodicidadeFromInterval($plan?->interval ?? null)
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
                        } catch (\Throwable) {
                        }
                    }

                    event(new PixGenerated($order, [
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                        'transaction_id' => $txid,
                    ]));

                    $pixToken = Str::random(32);
                    session()->put('pix_display.'.$pixToken, [
                        'order_id' => $order->id,
                        'qrcode' => $qrcodeImage,
                        'copy_paste' => $copyPaste ?? '',
                        'amount' => $amount,
                        'product_name' => $product?->name ?? 'Pagamento',
                        'redirect_after_purchase' => route('api-checkout.thank-you', ['order_id' => $order->id]),
                        'created_at' => time(),
                    ]);

                    return redirect()->route('checkout.pix', ['token' => $pixToken]);
                }

                throw new \RuntimeException('Gateway PIX automático não suportado.');
            } catch (\Throwable $e) {
                $order->delete();

                return redirect()->back()->with('error', $e->getMessage() ?: 'Não foi possível gerar o PIX automático.');
            }
        }

        if ($method === 'pix') {
            $pixGatewayConfig = $gatewayConfig;
            try {
                event(new OrderPending($order));
                $result = $paymentService->createPixPayment($order, $product, $consumer, $pixGatewayConfig);
                event(new PixGenerated($order, [
                    'qrcode' => $result['qrcode'] ?? null,
                    'copy_paste' => $result['copy_paste'] ?? null,
                    'transaction_id' => $result['transaction_id'] ?? null,
                ]));
                $pixToken = Str::random(32);
                session()->put('pix_display.'.$pixToken, [
                    'order_id' => $order->id,
                    'qrcode' => $result['qrcode'] ?? null,
                    'copy_paste' => $result['copy_paste'] ?? null,
                    'amount' => $amount,
                    'product_name' => $product?->name ?? 'Pagamento',
                    'redirect_after_purchase' => route('api-checkout.thank-you', ['order_id' => $order->id]),
                    'created_at' => time(),
                ]);

                return redirect()->route('checkout.pix', ['token' => $pixToken]);
            } catch (\Throwable $e) {
                $order->delete();

                return redirect()->back()->with('error', $e->getMessage() ?: 'Não foi possível gerar o PIX.');
            }
        }

        if ($method === 'boleto') {
            try {
                event(new OrderPending($order));
                $result = $paymentService->createBoletoPayment($order, $product, $consumer, $gatewayConfig);
                $boletoData = [
                    'amount' => $result['amount'] ?? $amount,
                    'expire_at' => $result['expire_at'] ?? null,
                    'barcode' => $result['barcode'] ?? null,
                    'pdf_url' => $result['pdf_url'] ?? null,
                ];
                event(new BoletoGenerated($order, $boletoData));
                $boletoToken = Str::random(32);
                $amountFormatted = 'R$ '.number_format($result['amount'] ?? $amount, 2, ',', '.');
                session()->put('boleto_display.'.$boletoToken, [
                    'order_id' => $order->id,
                    'amount_formatted' => $amountFormatted,
                    'expire_at' => $result['expire_at'] ?? null,
                    'barcode' => $result['barcode'] ?? '',
                    'pdf_url' => $result['pdf_url'] ?? null,
                    'product_name' => $product?->name ?? 'Pagamento',
                    'redirect_after_purchase' => route('api-checkout.thank-you', ['order_id' => $order->id]),
                    'customer_name' => $customer['name'] ?? null,
                    'customer_email' => $email,
                    'customer_phone' => $customer['phone'] ?? null,
                ]);

                return redirect()->route('checkout.boleto', ['token' => $boletoToken]);
            } catch (\Throwable $e) {
                $order->delete();

                return redirect()->back()->with('error', $e->getMessage() ?: 'Não foi possível gerar o boleto.');
            }
        }

        if ($method === 'card') {
            $card = [
                'payment_token' => $validated['payment_token'],
                'card_mask' => $validated['card_mask'] ?? null,
            ];
            try {
                event(new OrderPending($order));
                $cardGatewayConfig = $gatewayConfig;
                $cardGatewayConfig['card_redundancy'] = [];
                $result = $paymentService->createCardPayment($order, $product, $consumer, $card, $cardGatewayConfig);
                $status = is_string($result['status'] ?? null) ? strtolower(trim((string) $result['status'])) : 'pending';
                if (in_array($status, ['paid', 'settled', 'approved', 'completed'], true)) {
                    $order->update(['status' => 'completed']);
                    $order->grantPurchasedProductAccessToBuyer();
                    app(\App\Services\SubscriptionRenewalService::class)->syncFromPaidOrder($order->fresh());
                    event(new OrderCompleted($order));
                } elseif (in_array($status, ['rejected', 'refused', 'cancelled', 'canceled', 'failed'], true)) {
                    $order->update(['status' => 'rejected']);
                    event(new \App\Events\OrderRejected($order));

                    return redirect()->back()->with('error', 'Pagamento recusado. Verifique os dados do cartão e tente novamente.');
                }
                if (isset($result['client_secret']) && ($result['client_secret'] ?? '') !== '') {
                    $stripeKey = '';
                    $cardSlug = $pg['card'] ?? '';
                    if ($cardSlug === 'stripe') {
                        $cred = GatewayCredential::resolveForPayment($tenantId, 'stripe');
                        if ($cred) {
                            $creds = $cred->getDecryptedCredentials();
                            $stripeKey = (string) ($creds['publishable_key'] ?? '');
                        }
                    }
                    session()->put('api_checkout_card_confirm', [
                        'client_secret' => $result['client_secret'],
                        'return_url' => route('api-checkout.thank-you', ['order_id' => $order->id]),
                        'stripe_publishable_key' => $stripeKey,
                    ]);

                    return redirect()->route('api-checkout.card-confirm');
                }
                $asaasChallenge = $result['redirect_url'] ?? null;
                if (($result['status'] ?? null) === 'requires_action' && is_string($asaasChallenge) && $asaasChallenge !== '') {
                    return redirect()->away($asaasChallenge);
                }
                if ($order->fresh()->status === 'completed') {
                    return redirect()
                        ->route('api-checkout.thank-you', ['order_id' => $order->id])
                        ->with('success', 'Pagamento aprovado.');
                }

                return redirect()->back()->with('success', 'Pagamento em processamento. Você receberá a confirmação por e-mail.');
            } catch (\Throwable $e) {
                $order->delete();

                return redirect()->back()->with('error', $e->getMessage() ?: 'Não foi possível processar o cartão.');
            }
        }

        return redirect()->back()->with('error', 'Método não implementado.');
    }

    public function thankYou(Request $request): Response|RedirectResponse
    {
        $orderId = $request->integer('order_id', 0);
        if ($orderId <= 0) {
            return redirect('/')->with('error', 'Pedido inválido.');
        }

        $order = Order::with('apiApplication')->find($orderId);
        if (! $order || ! $order->api_application_id) {
            return redirect('/')->with('error', 'Pedido inválido.');
        }

        $returnUrl = null;
        if ($order->api_checkout_session_id) {
            $session = ApiCheckoutSession::find($order->api_checkout_session_id);
            $returnUrl = $session?->return_url;
        }
        if (! is_string($returnUrl) || trim($returnUrl) === '') {
            $returnUrl = $order->apiApplication?->default_return_url;
        }
        $returnUrl = is_string($returnUrl) ? trim($returnUrl) : '';
        if ($returnUrl === '' || ! filter_var($returnUrl, FILTER_VALIDATE_URL)) {
            $returnUrl = url('/');
        }

        return Inertia::render('ApiCheckout/ThankYou', [
            'order_id' => $order->id,
            'return_url' => $returnUrl,
            'seconds' => 5,
        ]);
    }

    /**
     * Page to complete 3DS / SCA for card payment (Stripe). Reads session and runs confirmCardPayment then redirects.
     */
    public function cardConfirm(Request $request): Response|RedirectResponse
    {
        $data = session()->get('api_checkout_card_confirm');
        if (! is_array($data) || empty($data['client_secret'])) {
            return redirect()->to('/')->with('error', 'Sessão de confirmação inválida.');
        }
        session()->forget('api_checkout_card_confirm');

        return Inertia::render('ApiCheckout/CardConfirm', [
            'client_secret' => $data['client_secret'],
            'return_url' => $data['return_url'] ?? url('/'),
            'stripe_publishable_key' => $data['stripe_publishable_key'] ?? '',
        ]);
    }
}
