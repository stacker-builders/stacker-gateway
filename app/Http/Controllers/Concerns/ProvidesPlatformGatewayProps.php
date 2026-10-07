<?php

namespace App\Http\Controllers\Concerns;

use App\Gateways\GatewayRegistry;
use App\Models\CajuPayAccount;
use App\Models\GatewayCredential;
use App\Models\Setting;
use App\Support\GatewayAdminFeeSummary;
use App\Support\GatewayInboundWebhookAuth;
use App\Support\GatewayPluginRequirement;
use App\Support\GatewayWebhookSecurityAlert;

trait ProvidesPlatformGatewayProps
{
    /**
     * @return array{pix: array<int, string>, card: array<int, string>, boleto: array<int, string>, pix_auto: array<int, string>}
     */
    protected function buildGatewayOrderForSettings(?int $tenantId): array
    {
        $gatewayOrderRaw = Setting::get('gateway_order', null, $tenantId);
        $default = config('gateways.default_order', ['pix' => [], 'card' => [], 'boleto' => [], 'pix_auto' => []]);
        $gatewayOrder = is_string($gatewayOrderRaw)
            ? (json_decode($gatewayOrderRaw, true) ?: $default)
            : (is_array($gatewayOrderRaw) ? $gatewayOrderRaw : $default);

        return [
            'pix' => GatewayRegistry::filterSlugsToAllowedAcquirers($gatewayOrder['pix'] ?? []),
            'card' => GatewayRegistry::filterSlugsToAllowedAcquirers($gatewayOrder['card'] ?? []),
            'boleto' => GatewayRegistry::filterSlugsToAllowedAcquirers($gatewayOrder['boleto'] ?? []),
            'pix_auto' => GatewayRegistry::filterSlugsToAllowedAcquirers($gatewayOrder['pix_auto'] ?? []),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildGatewaysListForSettings(?int $tenantId): array
    {
        $all = GatewayRegistry::allowedAcquirers();
        $credentialBySlug = GatewayCredential::forTenant($tenantId)->get()->keyBy('gateway_slug');

        return array_map(function ($g) use ($credentialBySlug, $tenantId) {
            $slug = $g['slug'] ?? '';
            $cred = $credentialBySlug->get($slug);
            $image = $g['image'] ?? null;
            $isCajuPayMulti = $slug === 'cajupay';
            $cajupayStatus = $isCajuPayMulti ? $this->cajupayGatewayStatus($tenantId) : null;
            $pluginUi = GatewayPluginRequirement::uiPropsForDefinition($g);

            return [
                'slug' => $slug,
                'name' => $g['name'],
                'image' => GatewayRegistry::resolveImageUrl(is_string($image) ? $image : null),
                'methods' => $g['methods'] ?? [],
                'scope' => $g['scope'] ?? 'national',
                'country' => $g['country'] ?? null,
                'country_name' => $g['country_name'] ?? null,
                'country_flag' => $g['country_flag'] ?? null,
                'countries' => $g['countries'] ?? null,
                'signup_url' => $g['signup_url'] ?? null,
                'support_contacts' => $g['support_contacts'] ?? [],
                'is_configured' => $isCajuPayMulti
                    ? $cajupayStatus['is_configured']
                    : ($cred !== null),
                'is_connected' => $isCajuPayMulti ? $cajupayStatus['is_connected'] : ($cred?->is_connected ?? false),
                'is_enabled' => $isCajuPayMulti
                    ? $cajupayStatus['is_enabled']
                    : ($cred === null ? true : ($cred->is_enabled ?? true)),
                'admin_fee_label' => GatewayAdminFeeSummary::labelForCredential($slug, $cred),
                'multi_account' => $isCajuPayMulti,
                'inbound_webhook_secret_required' => in_array($slug, ['asaas', 'pushinpay', 'spacepag', 'woovi'], true),
                'webhook_secret_configured' => $isCajuPayMulti
                    ? $cajupayStatus['webhook_secret_configured']
                    : ($cred && GatewayInboundWebhookAuth::webhookSecret($slug, $tenantId) !== null),
                'requires_plugin' => $pluginUi['requires_plugin'],
                'plugin_locked' => $pluginUi['plugin_locked'],
                'plugin_name' => $pluginUi['plugin_name'],
                'plugin_locked_title' => $pluginUi['plugin_locked_title'],
                'plugin_locked_message' => $pluginUi['plugin_locked_message'],
            ];
        }, $all);
    }

    /**
     * @return array{is_configured: bool, is_connected: bool, is_enabled: bool, webhook_secret_configured: bool}
     */
    private function cajupayGatewayStatus(?int $tenantId): array
    {
        $legacy = GatewayCredential::forTenant($tenantId)->where('gateway_slug', 'cajupay')->first();
        $accounts = CajuPayAccount::query()->get();

        $accountConnected = $accounts->contains(
            fn (CajuPayAccount $a) => $a->is_connected && $a->is_enabled
        );
        $accountEnabled = $accounts->contains(fn (CajuPayAccount $a) => $a->is_enabled);
        $accountWebhookOk = $accounts->contains(fn (CajuPayAccount $a) => $a->hasWebhookSigningSecret());

        $legacyConnected = $legacy?->is_connected ?? false;
        $legacyEnabled = $legacy === null ? false : ($legacy->is_enabled ?? true);
        $legacyWebhookOk = $legacy !== null && GatewayInboundWebhookAuth::webhookSecret('cajupay', $tenantId) !== null;

        return [
            'is_configured' => $accounts->isNotEmpty() || $legacy !== null,
            'is_connected' => $accountConnected || $legacyConnected,
            'is_enabled' => $accountEnabled || $legacyEnabled,
            'webhook_secret_configured' => $accountWebhookOk || $legacyWebhookOk,
        ];
    }

    /**
     * @return list<string>
     */
    protected function gatewayWebhookSecurityWarnings(?int $tenantId): array
    {
        return GatewayWebhookSecurityAlert::missingInboundSecretSlugs($tenantId);
    }

    /**
     * Lista para o modal «ordem por infoprodutor»: mesmo registo de adquirentes, mas
     * {@see $g['is_connected']} é true se existir credencial conectada em qualquer tenant
     * ou global — evita dropdown vazio quando só há credenciais por tenant.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildGatewaysListForMerchantPicker(): array
    {
        $all = GatewayRegistry::allowedAcquirers();
        $connectedSlugs = GatewayCredential::query()
            ->where('is_connected', true)
            ->enabledForPayments()
            ->pluck('gateway_slug')
            ->unique()
            ->all();
        $connectedSet = array_fill_keys($connectedSlugs, true);
        $configuredSlugs = GatewayCredential::query()
            ->pluck('gateway_slug')
            ->unique()
            ->all();
        $configuredSet = array_fill_keys($configuredSlugs, true);

        return array_map(function ($g) use ($connectedSet, $configuredSet) {
            $slug = $g['slug'] ?? '';
            $image = $g['image'] ?? null;

            return [
                'slug' => $slug,
                'name' => $g['name'],
                'image' => GatewayRegistry::resolveImageUrl(is_string($image) ? $image : null),
                'methods' => $g['methods'] ?? [],
                'scope' => $g['scope'] ?? 'national',
                'country' => $g['country'] ?? null,
                'country_name' => $g['country_name'] ?? null,
                'country_flag' => $g['country_flag'] ?? null,
                'countries' => $g['countries'] ?? null,
                'signup_url' => $g['signup_url'] ?? null,
                'is_configured' => isset($configuredSet[$slug]),
                'is_connected' => isset($connectedSet[$slug]),
            ];
        }, $all);
    }
}
