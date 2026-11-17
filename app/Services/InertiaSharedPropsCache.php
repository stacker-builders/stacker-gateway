<?php

namespace App\Services;

use App\Models\PanelNotification;
use App\Models\User;
use App\Plugins\PluginRegistry;
use App\Services\CajuPay\CajuPayMedService;
use App\Services\LegalDocumentsService;
use App\Services\Platform\PlatformAdminAlertCounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Cache de props Inertia compartilhadas que mudam pouco entre navegações no painel.
 */
class InertiaSharedPropsCache
{
    private const I18N_MESSAGES_TTL = 600;

    private const ACHIEVEMENTS_TTL = 60;

    private const PLUGINS_TTL = 300;

    private const HEADER_COUNTS_TTL = 30;

    private const LEGAL_LINKS_TTL = 600;

    private const ACCOUNT_MANAGER_TTL = 60;

    /**
     * @return array<string, string>
     */
    public function i18nMessages(string $locale, string $group = 'seller'): array
    {
        $key = 'inertia.i18n.messages.'.$group.'.'.md5($locale);

        return Cache::remember($key, self::I18N_MESSAGES_TTL, function () use ($locale, $group) {
            return app(PlatformI18nService::class)->messagesFor($locale, $group);
        });
    }

    public static function forgetI18nMessages(?string $locale = null, string $group = 'seller'): void
    {
        if ($locale !== null) {
            Cache::forget('inertia.i18n.messages.'.$group.'.'.md5($locale));

            return;
        }
        foreach (array_keys((array) config('panel_i18n.locales', [])) as $code) {
            Cache::forget('inertia.i18n.messages.'.$group.'.'.md5((string) $code));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function achievementsProgress(?int $tenantId): ?array
    {
        if ($tenantId === null || $tenantId < 1) {
            return null;
        }

        $key = 'inertia.achievements.'.$tenantId;

        return Cache::remember($key, self::ACHIEVEMENTS_TTL, function () use ($tenantId) {
            return app(SalesAchievementsService::class)->getProgressForTenant($tenantId);
        });
    }

    public static function forgetAchievementsProgress(?int $tenantId): void
    {
        if ($tenantId !== null && $tenantId > 0) {
            Cache::forget('inertia.achievements.'.$tenantId);
        }
    }

    /**
     * @return array{settings_plugin_tabs: array, pluginNavItems: array, plugins: array}
     */
    public function pluginPanelData(): array
    {
        return Cache::remember('inertia.plugins.panel_data', self::PLUGINS_TTL, function () {
            $installed = PluginRegistry::installed();

            return [
                'settings_plugin_tabs' => PluginRegistry::getSettingsTabs(),
                'pluginNavItems' => PluginRegistry::getMenuItems(),
                'plugins' => array_map(fn ($p) => [
                    'slug' => $p['slug'],
                    'name' => $p['name'],
                    'version' => $p['version'],
                    'is_enabled' => $p['is_enabled'],
                ], $installed),
            ];
        });
    }

    public static function forgetPluginPanelData(): void
    {
        Cache::forget('inertia.plugins.panel_data');
    }

    /**
     * @return array{notifications_unread_count: int, med_open_count: int}
     */
    public function headerCounts(int $userId, ?int $tenantId): array
    {
        $key = 'inertia.header_counts.'.$userId.'.'.($tenantId ?? 0);

        return Cache::remember($key, self::HEADER_COUNTS_TTL, function () use ($userId, $tenantId) {
            $med = 0;
            if ($tenantId !== null && $tenantId > 0) {
                $med = app(CajuPayMedService::class)->openCountForTenant($tenantId);
            }

            return [
                'notifications_unread_count' => PanelNotification::forUser($userId)->unread()->count(),
                'med_open_count' => $med,
            ];
        });
    }

    public static function forgetHeaderCounts(int $userId, ?int $tenantId): void
    {
        Cache::forget('inertia.header_counts.'.$userId.'.'.($tenantId ?? 0));
    }

    /**
     * @return array<string, int>
     */
    public function platformAdminSidebarBadges(User $user, Request $request): array
    {
        $counts = app(PlatformAdminAlertCounts::class);
        $routeName = (string) $request->route()?->getName();
        if (in_array($routeName, ['plataforma.kyc.index', 'plataforma.kyc.show'], true)) {
            $request->attributes->set('kyc_approved_unseen', $counts->kycApprovedUnseenCount($user));
            $counts->markViewed($user, PlatformAdminAlertCounts::KEY_KYC_APROVADOS);
        }

        $menuKey = $counts->menuKeyForRequest($request);
        if ($menuKey !== null && $counts->clearsBadgeOnVisit($menuKey)) {
            $counts->markViewed($user, $menuKey);
            self::forgetPlatformAdminSidebarBadges((int) $user->id);
        }

        return $counts->sidebarBadges($user);
    }

    public static function forgetPlatformAdminSidebarBadges(?int $userId = null): void
    {
        Cache::forget('inertia.platform_admin_sidebar_badges');
        if ($userId !== null && $userId > 0) {
            Cache::forget('inertia.platform_admin_sidebar_badges.'.$userId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function legalPublicLinks(): array
    {
        return Cache::remember('inertia.legal.public_links', self::LEGAL_LINKS_TTL, function () {
            return app(LegalDocumentsService::class)->publicLinks();
        });
    }

    public static function forgetLegalPublicLinks(): void
    {
        Cache::forget('inertia.legal.public_links');
    }

    /**
     * Card público do gerente de conta do merchant (painel seller).
     *
     * @return array<string, mixed>|null
     */
    public function accountManagerCard(int $merchantUserId): ?array
    {
        if ($merchantUserId < 1) {
            return null;
        }

        $key = 'inertia.account_manager.'.$merchantUserId;

        return Cache::remember($key, self::ACCOUNT_MANAGER_TTL, function () use ($merchantUserId) {
            $merchant = \App\Models\User::query()->find($merchantUserId);
            if (! $merchant) {
                return null;
            }

            return app(AccountManagerAssignmentService::class)->publicCardForMerchant($merchant);
        });
    }

    public static function forgetAccountManagerCard(int $merchantUserId): void
    {
        if ($merchantUserId > 0) {
            Cache::forget('inertia.account_manager.'.$merchantUserId);
        }
    }
}
