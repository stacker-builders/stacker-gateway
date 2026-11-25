<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class PixGoAccess
{
    public const SETTING_ENABLED = 'pixgo_enabled';

    public const SETTING_SIDEBAR_LABEL = 'pixgo_sidebar_label';

    public const DEFAULT_SIDEBAR_LABEL = 'PixGO';

    public const MODE_INHERIT = 'inherit';

    public const MODE_ENABLED = 'enabled';

    public const MODE_DISABLED = 'disabled';

    public static function globalEnabled(): bool
    {
        $raw = Setting::get(self::SETTING_ENABLED, '0', null);

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    public static function sidebarLabel(): string
    {
        $label = trim((string) Setting::get(self::SETTING_SIDEBAR_LABEL, self::DEFAULT_SIDEBAR_LABEL, null));

        return $label !== '' ? $label : self::DEFAULT_SIDEBAR_LABEL;
    }

    public static function setEnabled(bool $enabled): void
    {
        Setting::set(self::SETTING_ENABLED, $enabled ? '1' : '0', null);
    }

    public static function setSidebarLabel(string $label): void
    {
        $trimmed = trim($label);
        Setting::set(
            self::SETTING_SIDEBAR_LABEL,
            $trimmed !== '' ? $trimmed : self::DEFAULT_SIDEBAR_LABEL,
            null
        );
    }

    public static function tenantOverride(?int $tenantId): ?bool
    {
        if ($tenantId === null || $tenantId < 1) {
            return null;
        }

        $row = Setting::query()
            ->where('key', self::SETTING_ENABLED)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($row === null) {
            return null;
        }

        return filter_var($row->value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function tenantMode(?int $tenantId): string
    {
        $override = self::tenantOverride($tenantId);
        if ($override === null) {
            return self::MODE_INHERIT;
        }

        return $override ? self::MODE_ENABLED : self::MODE_DISABLED;
    }

    public static function setTenantMode(int $tenantId, string $mode): void
    {
        if ($mode === self::MODE_INHERIT) {
            Setting::query()
                ->where('key', self::SETTING_ENABLED)
                ->where('tenant_id', $tenantId)
                ->delete();
            Cache::forget('setting.'.$tenantId.'.'.self::SETTING_ENABLED);

            return;
        }

        Setting::set(
            self::SETTING_ENABLED,
            $mode === self::MODE_ENABLED ? '1' : '0',
            $tenantId
        );
    }

    public static function effectiveForTenant(?int $tenantId): bool
    {
        $override = self::tenantOverride($tenantId);
        if ($override !== null) {
            return $override;
        }

        return self::globalEnabled();
    }
}
