<?php

namespace App\Services\Telegram;

use App\Models\Setting;
use App\Services\SellerIntegrationVisibility;
use InvalidArgumentException;

class TelegramSellerPolicy
{
    public const KEY = 'telegram_seller_policy';

    /**
     * @return array{campaigns: string, sales: string}
     */
    public static function defaults(): array
    {
        return [
            'campaigns' => SellerIntegrationVisibility::MODE_INHERIT,
            'sales' => SellerIntegrationVisibility::MODE_INHERIT,
        ];
    }

    /**
     * @return array{campaigns: string, sales: string}
     */
    public static function forTenant(int $tenantId): array
    {
        $raw = Setting::get(self::KEY, null, $tenantId);
        $decoded = null;

        if (is_array($raw)) {
            $decoded = $raw;
        } elseif (is_string($raw) && trim($raw) !== '') {
            try {
                $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $decoded = is_array($parsed) ? $parsed : null;
            } catch (\JsonException) {
                $decoded = null;
            }
        }

        return [
            'campaigns' => self::mode($decoded['campaigns'] ?? null),
            'sales' => self::mode($decoded['sales'] ?? null),
        ];
    }

    /**
     * @return array{campaigns: string, sales: string}
     */
    public static function save(int $tenantId, string $campaigns, string $sales): array
    {
        $next = [
            'campaigns' => self::mode($campaigns),
            'sales' => self::mode($sales),
        ];

        if ($campaigns !== $next['campaigns'] || $sales !== $next['sales']) {
            throw new InvalidArgumentException('Modo de política do Telegram inválido.');
        }

        Setting::set(self::KEY, $next, $tenantId);

        return $next;
    }

    public static function campaignsAllowed(int $tenantId): bool
    {
        return self::allowed($tenantId, 'campaigns', TelegramModuleSettings::get()['campaigns_enabled']);
    }

    public static function salesAllowed(int $tenantId): bool
    {
        return self::allowed($tenantId, 'sales', TelegramModuleSettings::get()['sales_enabled']);
    }

    private static function allowed(int $tenantId, string $field, bool $global): bool
    {
        $mode = self::forTenant($tenantId)[$field];

        return match ($mode) {
            SellerIntegrationVisibility::MODE_ENABLED => true,
            SellerIntegrationVisibility::MODE_DISABLED => false,
            default => $global,
        };
    }

    private static function mode(mixed $value): string
    {
        $mode = is_string($value) ? $value : SellerIntegrationVisibility::MODE_INHERIT;

        if (! in_array($mode, [
            SellerIntegrationVisibility::MODE_INHERIT,
            SellerIntegrationVisibility::MODE_ENABLED,
            SellerIntegrationVisibility::MODE_DISABLED,
        ], true)) {
            return SellerIntegrationVisibility::MODE_INHERIT;
        }

        return $mode;
    }
}
