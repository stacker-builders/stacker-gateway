<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class SellerIntegrationVisibility
{
    public const MODE_INHERIT = 'inherit';

    public const MODE_ENABLED = 'enabled';

    public const MODE_DISABLED = 'disabled';

    public const WEBHOOK = 'webhook';

    public const UTMIFY = 'utmify';

    public const SPEDY = 'spedy';

    public const CADEMI = 'cademi';

    public const UAZAPI = 'uazapi';

    public const EVOLUTION = 'evolution';

    public const MCP = 'mcp';

    public const TELEGRAM = 'telegram';

    /**
     * Catálogo das apps da aba Integrações do infoprodutor.
     * Novas integrações entram aqui; use default false para manter fora de uso até o teste.
     *
     * @return list<array{id: string, label: string, description: string, default: bool}>
     */
    public static function catalog(): array
    {
        return [
            [
                'id' => self::WEBHOOK,
                'label' => 'Webhook',
                'description' => 'Envie eventos da plataforma para uma URL externa.',
                'default' => true,
            ],
            [
                'id' => self::UTMIFY,
                'label' => 'UTMIFY',
                'description' => 'Rastreie vendas e envie eventos para a UTMIFY.',
                'default' => true,
            ],
            [
                'id' => self::SPEDY,
                'label' => 'Spedy',
                'description' => 'Emissão automática de notas fiscais (NF-e/NFS-e).',
                'default' => true,
            ],
            [
                'id' => self::CADEMI,
                'label' => 'Cademí',
                'description' => 'Área de membros externa. Sincronize alunos após a compra.',
                'default' => true,
            ],
            [
                'id' => self::UAZAPI,
                'label' => 'Uazapi',
                'description' => 'Recuperação de carrinho e PIX pendente pelo WhatsApp do infoprodutor via Uazapi.',
                'default' => true,
            ],
            [
                'id' => self::EVOLUTION,
                'label' => 'Evolution API',
                'description' => 'Recuperação de carrinho e PIX pendente pelo WhatsApp via Evolution API do infoprodutor.',
                'default' => true,
            ],
            [
                'id' => self::MCP,
                'label' => 'MCP',
                'description' => 'Conecte Claude, ChatGPT ou outro cliente MCP para montar produtos, cupons e funis. Fica oculto até o teste.',
                'default' => false,
            ],
            [
                'id' => self::TELEGRAM,
                'label' => 'Telegram',
                'description' => 'Bots, grupos e canais do infoprodutor. A entrega e as campanhas entram nas próximas etapas.',
                'default' => true,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_values(array_map(fn (array $item) => $item['id'], self::catalog()));
    }

    public static function isKnown(string $id): bool
    {
        return in_array($id, self::ids(), true);
    }

    /**
     * @return array{id: string, label: string, description: string, default: bool}
     */
    public static function definition(string $id): array
    {
        foreach (self::catalog() as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        throw new InvalidArgumentException('Integração desconhecida: '.$id);
    }

    public static function settingKey(string $id): string
    {
        self::definition($id);

        return 'integration_'.$id.'_enabled';
    }

    /**
     * @return list<string>
     */
    public static function settingKeys(): array
    {
        return array_map([self::class, 'settingKey'], self::ids());
    }

    /**
     * @return array<string, list<string>>
     */
    public static function validationRules(): array
    {
        $rules = [];
        foreach (self::ids() as $id) {
            $rules[self::settingKey($id)] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    /**
     * @return array<string, bool>
     */
    public static function forSettingsForm(): array
    {
        $out = [];
        foreach (self::ids() as $id) {
            $out[self::settingKey($id)] = self::globalEnabled($id);
        }

        return $out;
    }

    public static function globalEnabled(string $id): bool
    {
        $default = self::definition($id)['default'] ? '1' : '0';
        $raw = Setting::get(self::settingKey($id), $default, null);

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    public static function setGlobal(string $id, bool $enabled): void
    {
        Setting::set(self::settingKey($id), $enabled ? '1' : '0', null);
    }

    /**
     * @return array<string, bool>
     */
    public static function globalMap(): array
    {
        $out = [];
        foreach (self::ids() as $id) {
            $out[$id] = self::globalEnabled($id);
        }

        return $out;
    }

    public static function tenantOverride(string $id, ?int $tenantId): ?bool
    {
        self::definition($id);

        if ($tenantId === null) {
            return null;
        }

        $row = Setting::query()
            ->where('key', self::settingKey($id))
            ->where('tenant_id', $tenantId)
            ->first();

        if ($row === null) {
            return null;
        }

        return filter_var($row->value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function tenantMode(string $id, ?int $tenantId): string
    {
        $override = self::tenantOverride($id, $tenantId);
        if ($override === null) {
            return self::MODE_INHERIT;
        }

        return $override ? self::MODE_ENABLED : self::MODE_DISABLED;
    }

    /**
     * @return array<string, string>
     */
    public static function tenantModes(?int $tenantId): array
    {
        $out = [];
        foreach (self::ids() as $id) {
            $out[$id] = self::tenantMode($id, $tenantId);
        }

        return $out;
    }

    public static function setTenantMode(string $id, int $tenantId, string $mode): void
    {
        self::definition($id);

        if ($mode === self::MODE_INHERIT) {
            Setting::query()
                ->where('key', self::settingKey($id))
                ->where('tenant_id', $tenantId)
                ->delete();
            Cache::forget('setting.'.$tenantId.'.'.self::settingKey($id));

            return;
        }

        if (! in_array($mode, [self::MODE_ENABLED, self::MODE_DISABLED], true)) {
            throw new InvalidArgumentException('Modo de integração inválido: '.$mode);
        }

        Setting::set(self::settingKey($id), $mode === self::MODE_ENABLED, $tenantId);
    }

    public static function effectiveForTenant(string $id, ?int $tenantId): bool
    {
        $override = self::tenantOverride($id, $tenantId);
        if ($override !== null) {
            return $override;
        }

        return self::globalEnabled($id);
    }

    /**
     * @return array<string, bool>
     */
    public static function effectiveMap(?int $tenantId): array
    {
        $out = [];
        foreach (self::ids() as $id) {
            $out[$id] = self::effectiveForTenant($id, $tenantId);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function visibleIdsForTenant(?int $tenantId): array
    {
        return array_values(array_filter(
            self::ids(),
            fn (string $id) => self::effectiveForTenant($id, $tenantId)
        ));
    }

    public static function anyVisibleForTenant(?int $tenantId): bool
    {
        return self::visibleIdsForTenant($tenantId) !== [];
    }
}
