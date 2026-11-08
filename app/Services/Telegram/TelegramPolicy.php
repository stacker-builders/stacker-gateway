<?php

namespace App\Services\Telegram;

use App\Services\SellerIntegrationVisibility;

/**
 * Porta única para os módulos futuros do Telegram.
 * Desligar novas vendas não bloqueia renovação, vencimento nem revogação do que já foi vendido.
 */
class TelegramPolicy
{
    public function moduleAvailable(?int $tenantId): bool
    {
        return SellerIntegrationVisibility::effectiveForTenant(
            SellerIntegrationVisibility::TELEGRAM,
            $tenantId
        );
    }

    /**
     * @param  int  $currentCount  Quantidade de bots que o tenant já tem. O chamador informa o número real.
     */
    public function canCreateBot(?int $tenantId, int $currentCount = 0): bool
    {
        return $this->withinLimit($tenantId, $currentCount, $this->limits()['bot_limit']);
    }

    /**
     * @param  int  $currentCount  Quantidade de grupos e canais que o tenant já tem.
     */
    public function canCreateCommunity(?int $tenantId, int $currentCount = 0): bool
    {
        return $this->withinLimit($tenantId, $currentCount, $this->limits()['community_limit']);
    }

    public function canStartNewSale(?int $tenantId): bool
    {
        if (! $this->operatesAsTenant($tenantId) || ! $this->moduleAvailable($tenantId)) {
            return false;
        }

        return TelegramSellerPolicy::salesAllowed($tenantId);
    }

    public function canRunCampaign(?int $tenantId): bool
    {
        if (! $this->operatesAsTenant($tenantId) || ! $this->moduleAvailable($tenantId)) {
            return false;
        }

        return TelegramSellerPolicy::campaignsAllowed($tenantId);
    }

    /**
     * @return array{bot_limit: int|null, community_limit: int|null}
     */
    public function limits(): array
    {
        $config = TelegramModuleSettings::get();

        return [
            'bot_limit' => $config['bot_limit'],
            'community_limit' => $config['community_limit'],
        ];
    }

    /**
     * Manutenção do acesso já vendido: renovação contratada, controle de vencimento e revogação.
     * O desligamento de novas vendas, de campanhas ou da visibilidade do módulo não entra nesta decisão.
     */
    public function canMaintainExistingAccess(?int $tenantId): bool
    {
        return $this->operatesAsTenant($tenantId);
    }

    public function autoDeliveryEnabled(): bool
    {
        return TelegramModuleSettings::get()['auto_delivery_enabled'];
    }

    public function expiryControlEnabled(): bool
    {
        return TelegramModuleSettings::get()['expiry_control_enabled'];
    }

    public function expiryRemovalEnabled(): bool
    {
        return TelegramModuleSettings::get()['expiry_removal_enabled'];
    }

    /**
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        return array_merge(TelegramModuleSettings::get(), [
            'module_enabled' => SellerIntegrationVisibility::globalEnabled(SellerIntegrationVisibility::TELEGRAM),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sellerPolicy(int $tenantId): array
    {
        $modes = TelegramSellerPolicy::forTenant($tenantId);

        return [
            'tenant_id' => $tenantId,
            'visibility' => SellerIntegrationVisibility::tenantMode(SellerIntegrationVisibility::TELEGRAM, $tenantId),
            'campaigns' => $modes['campaigns'],
            'sales' => $modes['sales'],
            'module_available' => $this->moduleAvailable($tenantId),
            'can_create_bot' => $this->canCreateBot($tenantId, 0),
            'can_create_community' => $this->canCreateCommunity($tenantId, 0),
            'can_start_new_sale' => $this->canStartNewSale($tenantId),
            'can_run_campaign' => $this->canRunCampaign($tenantId),
            'can_maintain_existing_access' => $this->canMaintainExistingAccess($tenantId),
        ];
    }

    private function withinLimit(?int $tenantId, int $currentCount, ?int $limit): bool
    {
        if (! $this->operatesAsTenant($tenantId) || ! $this->moduleAvailable($tenantId)) {
            return false;
        }

        if ($currentCount < 0) {
            return false;
        }

        if ($limit === null) {
            return true;
        }

        return $currentCount < $limit;
    }

    private function operatesAsTenant(?int $tenantId): bool
    {
        return $tenantId !== null && $tenantId > 0;
    }
}
