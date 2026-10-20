<?php

namespace App\Services;

use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Services\CajuPay\CajuPayAccountResolver;
use App\Models\Setting;
use App\Models\User;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Support\GatewayApiCredentials;
use App\Support\GatewayPluginRequirement;
use App\Support\GatewayWebhookUrl;
use App\Support\SaleOrigin;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    private const PAYMENT_TOTAL_TIMEOUT_SECONDS = 25;

    /**
     * Create a PIX payment for the order. Tries gateways in redundancy order until one succeeds.
     *
     * @param  array{name: string, document: string, email: string}  $consumer
     * @param  array<string, mixed>|null  $gatewayConfigOverride  When set (e.g. from API application), used instead of product's payment_gateways.
     * @return array{transaction_id: string, gateway: string, qrcode?: string, copy_paste?: string}
     */
    public function createPixPayment(Order $order, ?Product $product, array $consumer, ?array $gatewayConfigOverride = null): array
    {
        $tenantId = $order->tenant_id;
        $orderSlugs = $this->getGatewayOrderForMethod($tenantId, 'pix', $product, $gatewayConfigOverride);
        $lastException = null;
        $deadline = microtime(true) + self::PAYMENT_TOTAL_TIMEOUT_SECONDS;

        foreach ($orderSlugs as $gatewaySlug) {
            if (microtime(true) > $deadline) {
                break;
            }
            if (! $this->isPixAcquirerAllowedForOrder($gatewaySlug, $order)) {
                if ($gatewaySlug === 'okto') {
                    Log::error('PaymentService: Okto skipped (channel not allowed for this order).', [
                        'order_id' => $order->id,
                    ]);
                }
                continue;
            }
            $resolved = $this->resolveGatewayPaymentContext($tenantId, $gatewaySlug, 'pix');
            if ($resolved === null) {
                if ($gatewaySlug === 'okto') {
                    Log::error('PaymentService: Okto skipped (no connected credential).', [
                        'order_id' => $order->id,
                    ]);
                }
                continue;
            }
            $credentials = $resolved['credentials'];
            if (! GatewayApiCredentials::isReadyForGateway($gatewaySlug, $credentials)) {
                if ($gatewaySlug === 'okto') {
                    Log::error('PaymentService: Okto skipped (access token missing).', [
                        'order_id' => $order->id,
                    ]);
                }
                continue;
            }
            $driver = GatewayRegistry::driver($gatewaySlug);
            if (! $driver) {
                continue;
            }
            try {
                $startedAt = microtime(true);
                $postbackUrl = $this->webhookUrlForGateway($gatewaySlug);
                $pixOptions = [];
                $meta = is_array($order->metadata) ? $order->metadata : [];
                $partnerUrl = trim((string) ($meta['partner_checkout_url'] ?? ''));
                if ($partnerUrl !== '') {
                    $pixOptions['partner_checkout_url'] = $partnerUrl;
                }
                $pixExternalId = (string) $order->id;
                if ($gatewaySlug === 'okto') {
                    $pixExternalId = trim((string) ($meta['okto_external_id'] ?? ''));
                    if ($pixExternalId === '' || ! Str::isUuid($pixExternalId)) {
                        $pixExternalId = (string) Str::uuid();
                    }
                    $meta['okto_external_id'] = $pixExternalId;
                    $pixOptions['okto_external_id'] = $pixExternalId;
                    $order->update(['metadata' => $meta]);
                }
                $result = $driver->createPixPayment(
                    $credentials,
                    (float) $order->amount,
                    $consumer,
                    $pixExternalId,
                    $postbackUrl,
                    $pixOptions
                );
                $copyPaste = $result['copy_paste'] ?? null;
                $qrcode = $result['qrcode'] ?? null;
                if ((! is_string($copyPaste) || $copyPaste === '') && (! is_string($qrcode) || $qrcode === '')) {
                    throw new \RuntimeException('PIX gerado sem código de pagamento. Tente novamente.');
                }
                $meta = is_array($order->metadata) ? $order->metadata : [];
                if (! empty($resolved['gateway_credential_id'])) {
                    $meta['gateway_credential_id'] = (int) $resolved['gateway_credential_id'];
                }
                if (isset($result['metadata']) && is_array($result['metadata'])) {
                    $meta = array_merge($meta, $result['metadata']);
                }
                $order->update([
                    'gateway' => $gatewaySlug,
                    'gateway_id' => $result['transaction_id'] ?? null,
                    'cajupay_account_id' => $gatewaySlug === 'cajupay' ? ($resolved['cajupay_account_id'] ?? null) : $order->cajupay_account_id,
                    'metadata' => $meta,
                ]);
                if ($gatewaySlug === 'mercadopago' && ! empty($result['transaction_id'])) {
                    $meta['mercadopago_payment_id'] = (string) $result['transaction_id'];
                    $order->update(['metadata' => $meta]);
                    app(\App\Services\MercadoPago\MercadoPagoCheckoutCompletionService::class)
                        ->applyPendingForOrder($order->fresh());
                }

                return [
                    'transaction_id' => $result['transaction_id'] ?? '',
                    'gateway' => $gatewaySlug,
                    'qrcode' => $qrcode,
                    'copy_paste' => $copyPaste,
                ];
            } catch (\Throwable $e) {
                $log = $gatewaySlug === 'okto' ? 'error' : 'warning';
                Log::{$log}('PaymentService: PIX gateway failed.', [
                    'gateway' => $gatewaySlug,
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                    'duration_ms' => isset($startedAt) ? (int) round((microtime(true) - $startedAt) * 1000) : null,
                ]);
                if ($gatewaySlug === 'okto') {
                    $this->rememberOktoPixError(
                        isset($resolved['gateway_credential_id']) ? (int) $resolved['gateway_credential_id'] : null,
                        $e->getMessage(),
                        $order
                    );
                }
                $lastException = $e;
            }
        }

        if ($lastException) {
            throw $lastException;
        }
        throw new \RuntimeException('Nenhum gateway PIX configurado ou disponível.');
    }

    /**
     * Cria pagamento com cartão. Tenta gateways na ordem de redundância até um suceder.
     *
     * @param  array{name: string, document: string, email: string}  $consumer
     * @param  array{payment_token: string, card_mask?: string}  $card
     * @param  array<string, mixed>|null  $gatewayConfigOverride  When set (e.g. from API application), used instead of product's payment_gateways.
     * @return array{transaction_id: string, gateway: string, status?: string}
     */
    public function createCardPayment(Order $order, ?Product $product, array $consumer, array $card, ?array $gatewayConfigOverride = null): array
    {
        $tenantId = $order->tenant_id;
        $orderSlugs = $this->getGatewayOrderForMethod($tenantId, 'card', $product, $gatewayConfigOverride);
        $lastException = null;
        $deadline = microtime(true) + self::PAYMENT_TOTAL_TIMEOUT_SECONDS;

        foreach ($orderSlugs as $gatewaySlug) {
            if (microtime(true) > $deadline) {
                break;
            }
            $resolved = $this->resolveGatewayPaymentContext($tenantId, $gatewaySlug, 'card');
            if ($resolved === null) {
                continue;
            }
            $credentials = $resolved['credentials'];
            if (empty($credentials)) {
                continue;
            }
            $driver = GatewayRegistry::driver($gatewaySlug);
            if (! $driver) {
                continue;
            }
            try {
                $startedAt = microtime(true);
                $result = $driver->createCardPayment(
                    $credentials,
                    (float) $order->amount,
                    $consumer,
                    (string) $order->id,
                    $card
                );
                $cardPatch = [
                    'gateway' => $gatewaySlug,
                    'gateway_id' => $result['transaction_id'] ?? null,
                    'cajupay_account_id' => $gatewaySlug === 'cajupay' ? ($resolved['cajupay_account_id'] ?? null) : $order->cajupay_account_id,
                ];
                if (isset($result['metadata']) && is_array($result['metadata']) && $result['metadata'] !== []) {
                    $cardPatch['metadata'] = array_merge(
                        is_array($order->metadata) ? $order->metadata : [],
                        $result['metadata']
                    );
                }
                $order->update($cardPatch);
                $return = [
                    'transaction_id' => $result['transaction_id'] ?? '',
                    'gateway' => $gatewaySlug,
                    'status' => $result['status'] ?? null,
                ];
                if (isset($result['client_secret'])) {
                    $return['client_secret'] = $result['client_secret'];
                }
                if (isset($result['redirect_url']) && is_string($result['redirect_url']) && $result['redirect_url'] !== '') {
                    $return['redirect_url'] = $result['redirect_url'];
                }
                if (isset($result['status_detail'])) {
                    $return['status_detail'] = $result['status_detail'];
                }
                if (isset($result['charged_amount'])) {
                    $return['charged_amount'] = $result['charged_amount'];
                }
                return $return;
            } catch (\Throwable $e) {
                Log::warning('PaymentService: cartão gateway falhou.', [
                    'gateway' => $gatewaySlug,
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                    'duration_ms' => isset($startedAt) ? (int) round((microtime(true) - $startedAt) * 1000) : null,
                ]);
                $lastException = $e;
            }
        }

        if ($lastException) {
            throw $lastException;
        }
        throw new \RuntimeException('Nenhum gateway de cartão configurado ou disponível.');
    }

    /**
     * Apple Pay / Google Pay na Stripe: cobra só nesse adquirente, sem cair na redundância do cartão.
     *
     * @param  array{name: string, document: string, email: string}  $consumer
     * @param  array<string, mixed>  $card
     * @return array{transaction_id: string, gateway: string, status?: string, client_secret?: string}
     */
    public function createStripeWalletPayment(Order $order, array $consumer, array $card): array
    {
        $resolved = $this->resolveGatewayPaymentContext($order->tenant_id, 'stripe', 'card');
        if ($resolved === null || empty($resolved['credentials'])) {
            throw new \RuntimeException('Stripe não está disponível para Apple Pay ou Google Pay.');
        }

        $driver = GatewayRegistry::driver('stripe');
        if (! $driver) {
            throw new \RuntimeException('Stripe não está disponível para Apple Pay ou Google Pay.');
        }

        $result = $driver->createCardPayment(
            $resolved['credentials'],
            (float) $order->amount,
            $consumer,
            (string) $order->id,
            $card
        );
        $order->update([
            'gateway' => 'stripe',
            'gateway_id' => $result['transaction_id'] ?? null,
        ]);

        $return = [
            'transaction_id' => $result['transaction_id'] ?? '',
            'gateway' => 'stripe',
            'status' => $result['status'] ?? null,
        ];
        if (isset($result['client_secret'])) {
            $return['client_secret'] = $result['client_secret'];
        }

        return $return;
    }

    /**
     * Cria pagamento por boleto. Tenta gateways na ordem de redundância até um suceder.
     *
     * @param  array{name: string, document: string, email: string}  $consumer
     * @param  array<string, mixed>|null  $gatewayConfigOverride  When set (e.g. from API application), used instead of product's payment_gateways.
     * @return array{transaction_id: string, gateway: string, amount: float, expire_at: string, barcode: string, pdf_url: string}
     */
    public function createBoletoPayment(Order $order, ?Product $product, array $consumer, ?array $gatewayConfigOverride = null): array
    {
        $tenantId = $order->tenant_id;
        $orderSlugs = $this->getGatewayOrderForMethod($tenantId, 'boleto', $product, $gatewayConfigOverride);
        $lastException = null;
        $deadline = microtime(true) + self::PAYMENT_TOTAL_TIMEOUT_SECONDS;

        foreach ($orderSlugs as $gatewaySlug) {
            if (microtime(true) > $deadline) {
                break;
            }
            $resolved = $this->resolveGatewayPaymentContext($tenantId, $gatewaySlug, 'boleto');
            if ($resolved === null) {
                continue;
            }
            $credentials = $resolved['credentials'];
            if (empty($credentials)) {
                continue;
            }
            $driver = GatewayRegistry::driver($gatewaySlug);
            if (! $driver) {
                continue;
            }
            try {
                $startedAt = microtime(true);
                $notificationUrl = $gatewaySlug === 'efi'
                    ? GatewayWebhookUrl::forGateway('efi.notification')
                    : $this->webhookUrlForGateway($gatewaySlug);
                $result = $driver->createBoletoPayment(
                    $credentials,
                    (float) $order->amount,
                    $consumer,
                    (string) $order->id,
                    $notificationUrl
                );
                $order->update([
                    'gateway' => $gatewaySlug,
                    'gateway_id' => $result['transaction_id'] ?? null,
                    'cajupay_account_id' => $gatewaySlug === 'cajupay' ? ($resolved['cajupay_account_id'] ?? null) : $order->cajupay_account_id,
                ]);
                return [
                    'transaction_id' => $result['transaction_id'] ?? '',
                    'gateway' => $gatewaySlug,
                    'amount' => (float) ($result['amount'] ?? $order->amount),
                    'expire_at' => $result['expire_at'] ?? '',
                    'barcode' => $result['barcode'] ?? '',
                    'pdf_url' => $result['pdf_url'] ?? '',
                ];
            } catch (\Throwable $e) {
                Log::warning('PaymentService: boleto gateway falhou.', [
                    'gateway' => $gatewaySlug,
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                    'duration_ms' => isset($startedAt) ? (int) round((microtime(true) - $startedAt) * 1000) : null,
                ]);
                $lastException = $e;
            }
        }

        if ($lastException) {
            throw $lastException;
        }
        throw new \RuntimeException('Nenhum gateway de boleto configurado ou disponível.');
    }

    /**
     * Retorna o primeiro gateway disponível para o método (com credencial conectada e driver).
     * Usado pelo checkout para pix_auto quando há múltiplos gateways (Efí, Pushin Pay, etc.).
     *
     * @return string|null
     */
    /**
     * @param  array<string, mixed>|null  $gatewayConfigOverride
     */
    public function getFirstAvailableGatewayForMethod(?int $tenantId, string $method, ?Product $product = null, ?array $gatewayConfigOverride = null): ?string
    {
        $orderSlugs = $this->getGatewayOrderForMethod($tenantId, $method, $product, $gatewayConfigOverride);
        foreach ($orderSlugs as $gatewaySlug) {
            $resolved = $this->resolveGatewayPaymentContext($tenantId, $gatewaySlug, $method);
            if ($resolved === null) {
                continue;
            }
            $credentials = $resolved['credentials'];
            if (empty($credentials)) {
                continue;
            }
            $driver = GatewayRegistry::driver($gatewaySlug);
            if (! $driver) {
                continue;
            }
            $gateway = GatewayRegistry::get($gatewaySlug);
            if (! $gateway || ! in_array($method, $gateway['methods'] ?? [], true)) {
                continue;
            }
            return $gatewaySlug;
        }
        return null;
    }

    /**
     * Ordem de gateways para o método (produto ou gatewayConfigOverride pode fixar gateway + redundância; senão usa ordem global).
     *
     * @param  array<string, mixed>|null  $gatewayConfigOverride  When set (e.g. API application payment_gateways), used like product's config.
     * @return array<int, string>
     */
    /**
     * Ordem de gateways por método: tenant (se existir), senão plataforma (admin), senão default do config.
     *
     * @return array{pix: list<string>, card: list<string>, boleto: list<string>, pix_auto: list<string>}
     */
    public function normalizedGatewayOrder(?int $tenantId): array
    {
        $defaultOrder = config('gateways.default_order', ['pix' => [], 'card' => [], 'boleto' => [], 'pix_auto' => []]);
        $decode = function (mixed $raw): ?array {
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);

                return is_array($decoded) ? $decoded : null;
            }

            return is_array($raw) ? $raw : null;
        };

        $tenantRaw = $tenantId !== null ? $decode(Setting::get('gateway_order', null, $tenantId)) : null;
        $platformRaw = $decode(Setting::get('gateway_order', null, null));

        $pick = function (string $method) use ($tenantRaw, $platformRaw, $defaultOrder): array {
            $from = function (?array $source) use ($method): ?array {
                if (! is_array($source) || ! array_key_exists($method, $source)) {
                    return null;
                }
                if (! is_array($source[$method])) {
                    return null;
                }
                if ($source[$method] === []) {
                    return [];
                }

                return $source[$method];
            };

            $slugs = $from($tenantRaw) ?? $from($platformRaw);
            if ($slugs === null) {
                $slugs = is_array($defaultOrder[$method] ?? null) ? $defaultOrder[$method] : [];
            }

            return GatewayRegistry::filterSlugsToAllowedAcquirers($slugs);
        };

        return [
            'pix' => $pick('pix'),
            'card' => $pick('card'),
            'boleto' => $pick('boleto'),
            'pix_auto' => $pick('pix_auto'),
            'open_finance' => $pick('open_finance'),
            'paypal' => $pick('paypal'),
            'wallet' => $pick('wallet'),
        ];
    }

    /**
     * Adquirente da carteira Apple Pay / Google Pay (CajuPay ou Stripe), na ordem salva em Adquirentes.
     */
    public function getFirstAvailableWalletGateway(?int $tenantId, ?Product $product = null): ?string
    {
        $slugs = $this->getGatewayOrderForMethod($tenantId, 'wallet', $product);
        $allowed = ['cajupay', 'stripe'];
        $slugs = array_values(array_filter($slugs, fn ($slug) => in_array($slug, $allowed, true)));
        if ($slugs === []) {
            $slugs = $allowed;
        }

        foreach ($slugs as $slug) {
            $resolved = $this->resolveGatewayPaymentContext($tenantId, $slug, 'card');
            if ($resolved === null || empty($resolved['credentials'])) {
                continue;
            }
            if ($slug === 'stripe') {
                $secret = trim((string) ($resolved['credentials']['secret_key'] ?? ''));
                $publishable = trim((string) ($resolved['credentials']['publishable_key'] ?? ''));
                if ($secret === '' || $publishable === '') {
                    continue;
                }
            }

            return $slug;
        }

        return null;
    }

    /**
     * @return array{label: string}|null
     */
    public function walletAcquirerLabel(?int $tenantId, ?Product $product = null): ?string
    {
        return match ($this->getFirstAvailableWalletGateway($tenantId, $product)) {
            'stripe' => 'Stripe',
            'cajupay' => 'CajuPay',
            default => null,
        };
    }

    public function getGatewayOrderForMethod(?int $tenantId, string $method, ?Product $product = null, ?array $gatewayConfigOverride = null): array
    {
        $order = $this->normalizedGatewayOrder($tenantId);
        $globalOrder = $order[$method] ?? [];

        // Lista vazia explícita na ordem do tenant/plataforma = método desabilitado (não preencher com defaults).
        if ($globalOrder !== []) {
            $existingSet = array_flip($globalOrder);
            foreach (GatewayRegistry::allowedAcquirers() as $g) {
                $slug = $g['slug'] ?? '';
                if ($slug === '' || isset($existingSet[$slug])) {
                    continue;
                }
                if (in_array($method, $g['methods'] ?? [], true)) {
                    $globalOrder[] = $slug;
                    $existingSet[$slug] = true;
                }
            }
        }

        $pg = $gatewayConfigOverride ?? $this->paymentGatewaysFromProduct($product);
        if (is_array($pg) && $pg !== []) {
            $slug = isset($pg[$method]) ? trim((string) $pg[$method]) : null;
            if ($slug !== null && $slug !== '' && $slug !== '__default__') {
                $redundancy = $pg[$method . '_redundancy'] ?? [];
                $redundancy = is_array($redundancy) ? $redundancy : [];

                return GatewayRegistry::filterSlugsToAllowedAcquirers(array_merge([$slug], $redundancy));
            }
        }

        if ($tenantId !== null && $tenantId > 0) {
            $owner = User::query()
                ->where('tenant_id', $tenantId)
                ->where('role', User::ROLE_INFOPRODUTOR)
                ->first();
            if ($owner === null) {
                $owner = User::query()->where('id', $tenantId)->where('role', User::ROLE_INFOPRODUTOR)->first();
            }
            $mo = is_array($owner?->merchant_gateway_order) ? $owner->merchant_gateway_order : null;
            if ($mo !== null && isset($mo[$method]) && is_array($mo[$method]) && $mo[$method] !== []) {
                $preferred = [];
                foreach ($mo[$method] as $slug) {
                    if (! is_string($slug) || trim($slug) === '') {
                        continue;
                    }
                    $preferred[] = trim($slug);
                }
                $preferred = GatewayRegistry::filterSlugsToAllowedAcquirers($preferred);
                if ($preferred !== []) {
                    $seen = array_flip($preferred);
                    foreach ($globalOrder as $slug) {
                        if (! isset($seen[$slug])) {
                            $preferred[] = $slug;
                            $seen[$slug] = true;
                        }
                    }

                    return GatewayRegistry::filterSlugsToAllowedAcquirers($preferred);
                }
            }
        }

        return $globalOrder;
    }

    /**
     * Métodos disponíveis no checkout ou renovação: ordem global + credenciais, filtrado por
     * {@see Product::checkout_config} `payment_methods_enabled` (somente produto; oferta/plano não sobrescreve).
     *
     * - Checkout: passe `$plan` quando o contexto for assinatura (permite `pix_auto`).
     * - Renovação: passe `$subscription`; `pix_auto` só entra se houver `gateway_subscription_id`.
     *
     * @return array<int, array{id: string, label: string, gateway_name?: string, gateway_slug?: string}>
     */
    public function availablePaymentMethodsForCheckout(
        Product $product,
        ?SubscriptionPlan $plan = null,
        ?Subscription $subscription = null
    ): array {
        $defaults = Product::defaultCheckoutConfig();
        $productOnly = array_replace_recursive($defaults, is_array($product->checkout_config) ? $product->checkout_config : []);
        $enabled = $productOnly['payment_methods_enabled'] ?? $defaults['payment_methods_enabled'] ?? [];
        $enabled = is_array($enabled) ? $enabled : [];
        $platformEnabled = PlatformPaymentMethods::platformEnabled();
        foreach (PlatformPaymentMethods::METHOD_KEYS as $methodKey) {
            if (($platformEnabled[$methodKey] ?? true) === false) {
                $enabled[$methodKey] = false;
            }
        }

        $tenantId = $product->tenant_id;

        $credentialBySlug = GatewayCredential::connectedMapForPayment($tenantId);

        $methods = [];
        $methodConfig = [
            'pix' => ['id' => 'pix', 'label' => 'PIX'],
            'card' => ['id' => 'card', 'label' => 'Cartão'],
            'boleto' => ['id' => 'boleto', 'label' => 'Boleto'],
            'pix_auto' => ['id' => 'pix_auto', 'label' => 'PIX automático'],
            'open_finance' => ['id' => 'open_finance', 'label' => 'Open Finance'],
            'paypal' => ['id' => 'paypal', 'label' => 'PayPal'],
        ];

        foreach ($methodConfig as $methodKey => $meta) {
            $includeCardRow = true;
            $includeWalletRows = false;
            if ($methodKey === 'card') {
                $includeCardRow = ($enabled['card'] ?? true) !== false;
                $includeWalletRows = (($enabled['apple_pay'] ?? true) !== false) || (($enabled['google_pay'] ?? true) !== false);
                if (! $includeCardRow && ! $includeWalletRows) {
                    continue;
                }
            } elseif (($enabled[$methodKey] ?? true) === false) {
                continue;
            }
            if ($methodKey === 'paypal' && $subscription !== null) {
                continue;
            }
            if ($methodKey === 'pix_auto') {
                if ($subscription !== null) {
                    $idRec = $subscription->gateway_subscription_id;
                    if ($idRec === null || $idRec === '') {
                        continue;
                    }
                } elseif ($plan === null) {
                    continue;
                }
            }
            $slugsToCheck = $this->getGatewayOrderForMethod($tenantId, $methodKey, $product);

            foreach ($slugsToCheck as $slug) {
                if ($slug === 'cajupay') {
                    if (app(CajuPayAccountResolver::class)->resolveForPayment($tenantId, $methodKey) === null) {
                        continue;
                    }
                } else {
                    $cred = $credentialBySlug->get($slug);
                    if (! $cred) {
                        continue;
                    }
                }
                $gateway = GatewayRegistry::get($slug);
                if (! $gateway || ! in_array($methodKey, $gateway['methods'] ?? [], true)) {
                    continue;
                }
                if (! GatewayPluginRequirement::isUnlocked($slug)) {
                    continue;
                }
                if ($methodKey === 'card') {
                    if ($includeCardRow) {
                        $methods[] = [
                            'id' => $meta['id'],
                            'label' => $meta['label'],
                            'gateway_slug' => $slug,
                            'gateway_name' => $gateway['name'] ?? $slug,
                        ];
                    }
                } else {
                    $methods[] = [
                        'id' => $meta['id'],
                        'label' => $meta['label'],
                        'gateway_slug' => $slug,
                        'gateway_name' => $gateway['name'] ?? $slug,
                    ];
                }
                break;
            }
        }

        $walletSlug = $this->getFirstAvailableWalletGateway($tenantId, $product);
        if ($walletSlug !== null) {
            $walletName = $walletSlug === 'stripe' ? 'Stripe' : 'CajuPay';
            if (($enabled['apple_pay'] ?? true) !== false) {
                $methods[] = [
                    'id' => 'apple_pay',
                    'label' => 'Apple Pay',
                    'gateway_slug' => $walletSlug,
                    'gateway_name' => 'Apple Pay',
                    'wallet_via' => $walletName,
                ];
            }
            if (($enabled['google_pay'] ?? true) !== false) {
                $methods[] = [
                    'id' => 'google_pay',
                    'label' => 'Google Pay',
                    'gateway_slug' => $walletSlug,
                    'gateway_name' => 'Google Pay',
                    'wallet_via' => $walletName,
                ];
            }
        }

        return $methods;
    }

    /**
     * Quais métodos têm pelo menos um gateway global conectado (sem filtro do produto).
     *
     * @return array<string, bool>
     */
    public function globallyAvailablePaymentMethodKeys(Product $product, ?SubscriptionPlan $plan = null): array
    {
        $tenantId = $product->tenant_id;
        $credentialBySlug = GatewayCredential::connectedMapForPayment($tenantId);
        $out = ['pix' => false, 'card' => false, 'boleto' => false, 'pix_auto' => false, 'apple_pay' => false, 'google_pay' => false, 'open_finance' => false, 'paypal' => false];
        foreach (['pix', 'card', 'boleto', 'pix_auto', 'open_finance', 'paypal'] as $methodKey) {
            if ($methodKey === 'pix_auto' && $plan === null) {
                continue;
            }
            $slugsToCheck = $this->getGatewayOrderForMethod($tenantId, $methodKey, $product);
            foreach ($slugsToCheck as $slug) {
                if ($slug === 'cajupay') {
                    if (app(CajuPayAccountResolver::class)->resolveForPayment($tenantId, $methodKey) === null) {
                        continue;
                    }
                } else {
                    $cred = $credentialBySlug->get($slug);
                    if (! $cred) {
                        continue;
                    }
                }
                $gateway = GatewayRegistry::get($slug);
                if ($gateway && in_array($methodKey, $gateway['methods'] ?? [], true)) {
                    if (! GatewayPluginRequirement::isUnlocked($slug)) {
                        continue;
                    }
                    $out[$methodKey] = true;
                    break;
                }
            }
        }
        $firstCardSlug = null;
        foreach ($this->getGatewayOrderForMethod($tenantId, 'card', $product) as $slug) {
            if ($slug === 'cajupay') {
                if (app(CajuPayAccountResolver::class)->resolveForPayment($tenantId, 'card') !== null) {
                    $firstCardSlug = $slug;
                    break;
                }
                continue;
            }
            if (! $credentialBySlug->get($slug)) {
                continue;
            }
            $gateway = GatewayRegistry::get($slug);
            if ($gateway && in_array('card', $gateway['methods'] ?? [], true)) {
                $firstCardSlug = $slug;
                break;
            }
        }
        if ($this->getFirstAvailableWalletGateway($tenantId, $product) !== null) {
            if (PlatformPaymentMethods::isEnabled('apple_pay')) {
                $out['apple_pay'] = true;
            }
            if (PlatformPaymentMethods::isEnabled('google_pay')) {
                $out['google_pay'] = true;
            }
        }

        foreach ($out as $key => $value) {
            if ($value && ! PlatformPaymentMethods::isEnabled($key)) {
                $out[$key] = false;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function paymentGatewaysFromProduct(?Product $product): ?array
    {
        if ($product === null) {
            return null;
        }
        $config = is_array($product->checkout_config) ? $product->checkout_config : [];
        $pg = $config['payment_gateways'] ?? null;

        return is_array($pg) && $pg !== [] ? $pg : null;
    }

    /**
     * ApiPix e PixGO não podem usar certas adquirentes (ex. BSPay).
     * Checkout da plataforma e demais canais não são afetados.
     */
    public function isPixAcquirerAllowedForOrder(string $gatewaySlug, Order $order): bool
    {
        $excluded = config('gateways.api_pix_excluded_slugs', []);
        if (! is_array($excluded) || ! in_array($gatewaySlug, $excluded, true)) {
            return true;
        }

        if ($order->isPixGoSale()) {
            return false;
        }

        $origin = SaleOrigin::resolveForOrder($order);
        if (in_array($origin, [SaleOrigin::API, SaleOrigin::PIXGO], true)) {
            return false;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $source = is_string($meta['source'] ?? null) ? $meta['source'] : '';

        return ! in_array($source, ['api', 'pixgo'], true);
    }

    private function webhookUrlForGateway(string $gatewaySlug): string
    {
        return GatewayWebhookUrl::forGateway($gatewaySlug);
    }

    /**
     * @return array{credentials: array<string, mixed>, cajupay_account_id: ?int, gateway_credential_id: ?int}|null
     */
    private function resolveGatewayPaymentContext(?int $tenantId, string $gatewaySlug, ?string $method = null): ?array
    {
        if (! GatewayPluginRequirement::isUnlocked($gatewaySlug)) {
            return null;
        }

        if ($gatewaySlug === 'cajupay') {
            $resolver = app(CajuPayAccountResolver::class);
            $account = $method !== null && $method !== ''
                ? $resolver->resolveForPayment($tenantId, $method)
                : $resolver->resolveForTenant($tenantId);
            if ($account === null) {
                return null;
            }
            $credentials = $account->getDecryptedCredentials();

            return [
                'credentials' => $credentials,
                'cajupay_account_id' => $account->id > 0 ? (int) $account->id : null,
                'gateway_credential_id' => null,
            ];
        }

        $credential = GatewayCredential::resolveForPayment($tenantId, $gatewaySlug);
        if ($credential === null) {
            return null;
        }

        return [
            'credentials' => $credential->getDecryptedCredentials(),
            'cajupay_account_id' => null,
            'gateway_credential_id' => (int) $credential->id,
        ];
    }

    private function rememberOktoPixError(?int $credentialId, string $message, Order $order): void
    {
        $message = mb_substr(trim($message), 0, 500);
        if ($message === '') {
            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $errors = is_array($meta['pix_gateway_errors'] ?? null) ? $meta['pix_gateway_errors'] : [];
        $errors[] = [
            'gateway' => 'okto',
            'message' => $message,
            'at' => now()->toIso8601String(),
        ];
        $meta['pix_gateway_errors'] = array_slice($errors, -5);
        $order->update(['metadata' => $meta]);

        if ($credentialId === null || $credentialId < 1) {
            return;
        }

        $credential = GatewayCredential::query()->find($credentialId);
        if ($credential === null) {
            return;
        }

        $creds = $credential->getDecryptedCredentials();
        $creds['last_pix_error'] = $message;
        $creds['last_pix_error_at'] = now()->toIso8601String();
        $credential->setEncryptedCredentials($creds);
        $credential->save();
    }
}
