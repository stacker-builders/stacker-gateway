<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\UserPushPreference;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

final class UserPushPreferences
{
    public static function ready(): bool
    {
        try {
            return Schema::hasTable('user_push_preferences');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, bool|string>
     */
    public static function forUserId(int $userId): array
    {
        $defaults = UserPushPreference::defaults();
        if (! self::ready() || $userId <= 0) {
            return $defaults;
        }

        $row = UserPushPreference::query()->where('user_id', $userId)->first();
        if (! $row) {
            return $defaults;
        }

        $prefs = array_merge($defaults, $row->only(array_keys($defaults)));
        $prefs['sale_amount_mode'] = UserPushPreference::normalizeSaleAmountMode($prefs['sale_amount_mode'] ?? null);

        return $prefs;
    }

    /**
     * Preferências efetivas para um tenant (dono + team usam tenant owner prefs quando aplicável).
     *
     * @return array<string, bool|string>
     */
    public static function forTenantOwner(int $tenantId): array
    {
        return self::forUserId($tenantId);
    }

    public static function allowsEvent(int $userId, string $type): bool
    {
        $prefs = self::forUserId($userId);
        $map = [
            'sale_approved' => 'sale_approved',
            'pix_generated' => 'pix_generated',
            'boleto_generated' => 'boleto_generated',
            'withdrawal_paid' => 'withdrawal_paid',
            'affiliate_sale_approved' => 'affiliate_sale_approved',
            'coproduction_sale_approved' => 'coproduction_sale_approved',
            'affiliate_enrollment_approved' => 'affiliate_enrollment_approved',
            'daily_sales_summary' => 'daily_summary',
            'system' => 'system',
        ];

        $key = $map[$type] ?? null;
        if ($key === null) {
            return true;
        }

        return (bool) ($prefs[$key] ?? true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, bool|string>
     */
    public static function upsert(int $userId, array $input, ?Request $request = null): array
    {
        if (! self::ready()) {
            return UserPushPreference::defaults();
        }

        $defaults = UserPushPreference::defaults();
        $data = ['user_id' => $userId];
        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            if ($key === 'sale_amount_mode') {
                $data[$key] = UserPushPreference::normalizeSaleAmountMode($input[$key]);

                continue;
            }

            if ($key === 'coproduction_sale_approved' && ! Schema::hasColumn('user_push_preferences', 'coproduction_sale_approved')) {
                continue;
            }

            $data[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
        }

        $pref = UserPushPreference::query()->updateOrCreate(['user_id' => $userId], $data);
        PlatformAuditService::log('push.preferences_updated', [
            'user_id' => $userId,
            'preferences' => $pref->only(array_keys($defaults)),
        ], $request);

        $prefs = array_merge($defaults, $pref->only(array_keys($defaults)));
        $prefs['sale_amount_mode'] = UserPushPreference::normalizeSaleAmountMode($prefs['sale_amount_mode'] ?? null);

        return $prefs;
    }
}
