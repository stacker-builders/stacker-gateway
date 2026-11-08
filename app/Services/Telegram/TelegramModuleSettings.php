<?php

namespace App\Services\Telegram;

use App\Models\Setting;
use App\Services\SellerIntegrationVisibility;
use InvalidArgumentException;

class TelegramModuleSettings
{
    public const KEY = 'telegram_module';

    public const COMMERCIAL_FREE = 'free';

    /**
     * Configuração global inicial. Limite nulo significa ilimitado.
     *
     * @return array{
     *     commercial_model: string,
     *     bot_limit: null,
     *     community_limit: null,
     *     campaigns_enabled: bool,
     *     auto_delivery_enabled: bool,
     *     expiry_control_enabled: bool,
     *     expiry_removal_enabled: bool,
     *     sales_enabled: bool,
     *     auto_compensation_enabled: bool
     * }
     */
    public static function defaults(): array
    {
        return [
            'commercial_model' => self::COMMERCIAL_FREE,
            'bot_limit' => null,
            'community_limit' => null,
            'campaigns_enabled' => true,
            'auto_delivery_enabled' => true,
            'expiry_control_enabled' => true,
            'expiry_removal_enabled' => true,
            'sales_enabled' => true,
            // Compensação automática desabilitada até validação administrativa.
            'auto_compensation_enabled' => false,
        ];
    }

    /**
     * Grava o padrão uma vez. Não reescreve uma configuração já salva.
     */
    public static function ensureStored(): void
    {
        $exists = Setting::query()
            ->whereNull('tenant_id')
            ->where('key', self::KEY)
            ->exists();

        if (! $exists) {
            Setting::set(self::KEY, self::defaults(), null);
        }

        $visibilityKey = SellerIntegrationVisibility::settingKey(SellerIntegrationVisibility::TELEGRAM);
        $visibilityExists = Setting::query()
            ->whereNull('tenant_id')
            ->where('key', $visibilityKey)
            ->exists();

        if (! $visibilityExists) {
            SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::TELEGRAM, true);
        }
    }

    /**
     * @return array{
     *     commercial_model: string,
     *     bot_limit: int|null,
     *     community_limit: int|null,
     *     campaigns_enabled: bool,
     *     auto_delivery_enabled: bool,
     *     expiry_control_enabled: bool,
     *     expiry_removal_enabled: bool,
     *     sales_enabled: bool,
     *     auto_compensation_enabled: bool
     * }
     */
    public static function get(): array
    {
        $raw = Setting::get(self::KEY, null, null);
        $decoded = self::decode($raw);

        return self::normalize(is_array($decoded) ? $decoded : self::defaults());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{
     *     commercial_model: string,
     *     bot_limit: int|null,
     *     community_limit: int|null,
     *     campaigns_enabled: bool,
     *     auto_delivery_enabled: bool,
     *     expiry_control_enabled: bool,
     *     expiry_removal_enabled: bool,
     *     sales_enabled: bool,
     *     auto_compensation_enabled: bool
     * }
     */
    public static function save(array $attributes): array
    {
        $next = self::normalize(array_merge(self::get(), $attributes));
        Setting::set(self::KEY, $next, null);

        return $next;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     commercial_model: string,
     *     bot_limit: int|null,
     *     community_limit: int|null,
     *     campaigns_enabled: bool,
     *     auto_delivery_enabled: bool,
     *     expiry_control_enabled: bool,
     *     expiry_removal_enabled: bool,
     *     sales_enabled: bool,
     *     auto_compensation_enabled: bool
     * }
     */
    private static function normalize(array $input): array
    {
        $defaults = self::defaults();

        return [
            'commercial_model' => self::COMMERCIAL_FREE,
            'bot_limit' => self::limit($input['bot_limit'] ?? $defaults['bot_limit']),
            'community_limit' => self::limit($input['community_limit'] ?? $defaults['community_limit']),
            'campaigns_enabled' => self::bool($input['campaigns_enabled'] ?? $defaults['campaigns_enabled']),
            'auto_delivery_enabled' => self::bool($input['auto_delivery_enabled'] ?? $defaults['auto_delivery_enabled']),
            'expiry_control_enabled' => self::bool($input['expiry_control_enabled'] ?? $defaults['expiry_control_enabled']),
            'expiry_removal_enabled' => self::bool($input['expiry_removal_enabled'] ?? $defaults['expiry_removal_enabled']),
            'sales_enabled' => self::bool($input['sales_enabled'] ?? $defaults['sales_enabled']),
            'auto_compensation_enabled' => self::bool($input['auto_compensation_enabled'] ?? $defaults['auto_compensation_enabled']),
        ];
    }

    private static function limit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && ! preg_match('/^\d+$/', $value)) {
            throw new InvalidArgumentException('Limite do Telegram inválido.');
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Limite do Telegram inválido.');
        }

        $limit = (int) $value;
        if ($limit < 1) {
            throw new InvalidArgumentException('Limite do Telegram deve ser nulo (ilimitado) ou maior que zero.');
        }

        return $limit;
    }

    private static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
