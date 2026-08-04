<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\MemberNotification;
use App\Models\MemberPushSubscription;
use App\Models\Product;
use App\Services\InertiaSharedPropsCache;
use App\Services\StorageService;
use App\Services\TeamAccessService;
use App\Services\PlatformI18nService;
use App\Services\Platform\PlatformTotpService;
use App\Services\ApiPixAccess;
use App\Services\MinimumChargeService;
use App\Services\MemberProgressService;
use App\Services\MemberAreaResolver;
use App\Services\PhysicalProductAccess;
use App\Support\BrandingAssetUrls;
use App\Support\DemoMode;
use App\Support\LoginTemplate;
use App\Support\PanelColorScheme;
use App\Support\PublicAppUrl;
use App\Support\SellerDashboardTemplate;
use App\Support\SellerPanelSupportSettings;
use App\Support\ReferralProgramSettings;
use App\Support\InfoproducerRegistrationSettings;
use App\Support\MemberAreaAdminPreview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        if ($this->isStorageApiRequest($request)) {
            return parent::share($request);
        }

        try {
            return $this->buildSharedData($request);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('inertia.share_failed', [
                'path' => $request->path(),
                'message' => $e->getMessage(),
            ]);

            return array_merge(parent::share($request), [
                'csrf_token' => $request->hasSession() ? $request->session()->token() : '',
                'app_url' => rtrim(PublicAppUrl::base(), '/'),
                'demo_mode' => DemoMode::publicConfig(),
                'allow_new_infoproducers' => InfoproducerRegistrationSettings::isAllowed(),
                'flash' => ['success' => null, 'error' => null, 'info' => null, 'status' => null],
                'platform' => null,
            ]);
        }
    }

    private function isStorageApiRequest(Request $request): bool
    {
        $path = $request->path();

        return str_ends_with($path, 'configuracoes/storage/ping')
            || str_ends_with($path, 'configuracoes/storage/test')
            || str_ends_with($path, 'configuracoes/storage/migrate');
    }

    private function buildSharedData(Request $request): array
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id;

        $appSettings = $user ? [
            'app_name' => config('getfy.app_name'),
            'theme_primary' => config('getfy.theme_primary'),
            'app_logo' => config('getfy.app_logo'),
            'app_logo_dark' => config('getfy.app_logo_dark'),
            'app_logo_icon' => config('getfy.app_logo_icon'),
            'app_logo_icon_dark' => config('getfy.app_logo_icon_dark'),
            'pwa_nav_logo' => config('getfy.pwa_nav_logo'),
            'pwa_nav_logo_dark' => config('getfy.pwa_nav_logo_dark'),
        ] : null;

        $publicBranding = $this->buildPublicBranding();

        $pageTitle = $this->pageTitleForRoute($request->route()?->getName());

        $pluginNavItems = [];
        $plugins = [];
        $achievementsProgress = null;
        $pushEnabled = false;
        $pushProvider = null;
        $vapidPublic = null;
        $firebaseClientConfig = null;
        $settingsPluginTabs = [];
        $sharedCache = app(InertiaSharedPropsCache::class);
        if ($user && ($user->canAccessSellerPanel() || $user->canAccessPlatformPanel())) {
            $pluginData = $sharedCache->pluginPanelData();
            $settingsPluginTabs = $pluginData['settings_plugin_tabs'];
            $pluginNavItems = $pluginData['pluginNavItems'];
            // Itens de menu de plugins podem apontar para rotas da plataforma (/plataforma/*).
            // Esses links não devem aparecer no painel do infoprodutor/equipe.
            if ($user->canAccessSellerPanel() && ! $user->canAccessPlatformPanel()) {
                $pluginNavItems = array_values(array_filter($pluginNavItems, function ($item) {
                    $href = is_array($item) ? (string) ($item['href'] ?? '') : '';
                    return $href === '' || ! str_starts_with($href, '/plataforma/');
                }));
            }
            $pushClient = \App\Support\PanelPushSettings::publicClientConfig();
            $pushEnabled = \App\Support\PanelPushSettings::isPushEnabled();
            $pushProvider = $pushClient['push_provider'] ?? 'vapid';
            $vapidPublic = ($pushProvider === 'vapid' && $pushEnabled) ? ($pushClient['vapid_public'] ?? null) : null;
            $firebaseClientConfig = ($pushProvider === 'fcm' && $pushEnabled) ? [
                'firebase' => $pushClient['firebase'] ?? null,
                'firebase_web_vapid_key' => $pushClient['firebase_web_vapid_key'] ?? null,
            ] : null;
            $plugins = $pluginData['plugins'];
        }
        if ($user && $user->canAccessSellerPanel()) {
            $achievementsProgress = $sharedCache->achievementsProgress(
                $user->tenant_id !== null ? (int) $user->tenant_id : null
            );
        }

        $notificationsUnreadCount = 0;
        $medOpenCount = 0;
        $platformAdminSidebarBadges = [];
        if ($user && $user->canAccessSellerPanel()) {
            $headerCounts = $sharedCache->headerCounts(
                (int) $user->id,
                $user->tenant_id !== null ? (int) $user->tenant_id : null
            );
            $notificationsUnreadCount = $headerCounts['notifications_unread_count'];
            $medOpenCount = $headerCounts['med_open_count'];
        }
        if ($user && $user->canAccessPlatformPanel()) {
            $platformAdminSidebarBadges = $sharedCache->platformAdminSidebarBadges();
        }

        $path = $request->path();
        $isMemberArea = str_starts_with($path, 'm/') || $request->attributes->get('member_area_slug');
        $isCheckout = str_starts_with($path, 'c/') || str_starts_with($path, 'checkout') || str_starts_with($path, 'api-checkout');
        $skipPanelPwa = $isMemberArea || $isCheckout;

        $memberNotificationsUnreadCount = 0;
        $memberPushSubscribed = false;
        $memberCertificate = ['enabled' => false];
        $memberAreaAdminPreview = null;
        if ($user && $isMemberArea) {
            $product = $request->route('product') ?? $request->attributes->get('member_area_product');
            // HandleInertiaRequests roda antes dos middlewares member.area.*; resolve pelo slug/host.
            if (! $product instanceof Product) {
                try {
                    $resolved = app(MemberAreaResolver::class)->resolve($request);
                    $product = $resolved['product'] ?? null;
                } catch (\Throwable) {
                    $product = null;
                }
            }
            if ($product instanceof Product) {
                $memberAreaAdminPreview = MemberAreaAdminPreview::inertiaPayload($request, $product);
                $memberNotificationsUnreadCount = MemberNotification::forUser($user->id)
                    ->forProduct($product->id)
                    ->unread()
                    ->count();
                $memberPushSubscribed = MemberPushSubscription::where('user_id', $user->id)
                    ->where('product_id', $product->id)
                    ->exists();

                if (! MemberAreaAdminPreview::isActive($request, $product)) {
                    $eligibility = app(MemberProgressService::class)->certificateEligibility($product, $user);
                    if ($eligibility['enabled']) {
                        $memberCertificate = [
                            'enabled' => true,
                            'ready' => $eligibility['eligible'],
                            'issued' => $eligibility['already_issued'],
                            'progress_percent' => $eligibility['progress_percent'],
                            'required_percent' => $eligibility['required_percent'],
                            'release' => [
                                'mode' => $eligibility['release_mode'],
                                'required_percent' => $eligibility['required_percent'],
                                'percent_met' => $eligibility['percent_met'],
                                'days_after_access' => $eligibility['days_after_access'],
                                'days_elapsed' => $eligibility['days_elapsed'],
                                'days_remaining' => $eligibility['days_remaining'],
                                'days_met' => $eligibility['days_met'],
                                'unlocks_at' => $eligibility['unlocks_at'],
                            ],
                        ];
                    }
                }
            }
        }

        $kycSubject = null;
        if ($user && $user->canAccessSellerPanel() && Schema::hasColumn('users', 'kyc_status')) {
            $kycSubject = $user->kycSubjectUser();
        }

        // UI do “painel aluno” só nas URLs de comprador; não misturar com sessão panel_context
        // (senão /dashboard com session customer escondia o atalho “Painel do aluno” no menu).
        $customerPanel = false;
        if ($user && $user->canAccessCustomerPanel()) {
            $path = $request->path();
            $customerPanel = $path === 'painel-cliente'
                || str_starts_with($path, 'painel-cliente/')
                || $path === 'area-membros'
                || str_starts_with($path, 'area-membros/');
        }

        $shared = [
            ...parent::share($request),
            'csrf_token' => $request->session()->token(),
            'app_url' => rtrim(PublicAppUrl::base(), '/'),
            'pageTitle' => $pageTitle,
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'role' => $user->role,
                    'avatar_url' => $this->resolveAvatarUrl($user),
                    'kyc_status' => $kycSubject?->kyc_status,
                    'is_merchant_operationally_approved' => $kycSubject !== null
                        && $user->isMerchantOperationallyApproved(),
                    'needs_kyc_attention' => $kycSubject !== null
                        && ! $user->isMerchantOperationallyApproved(),
                    'kyc_onboarding_state' => $kycSubject === null
                        ? null
                        : ($user->mustCompleteKycOnboarding()
                            ? 'needs_upload'
                            : ($user->isAwaitingKycReview()
                                ? 'awaiting_review'
                                : (! $user->isMerchantOperationallyApproved() ? 'pending_account' : null))),
                    'panel_switch' => [
                        'customer' => $user->canAccessCustomerPanel(),
                        'seller' => $user->canSwitchToSellerPanel() || $user->needsOnboardingAsSeller(),
                    ],
                    'totp_enabled' => PlatformTotpService::isEnabledFor($user),
                    'show_totp_prompt' => $user->canAccessPlatformPanel() || $user->canAccessSellerPanel(),
                ] : null,
                'permissions' => ($user && $user->canAccessSellerPanel())
                    ? app(TeamAccessService::class)->permissionsFor($user)
                    : [],
                'allowed_product_ids' => ($user && $user->canAccessSellerPanel())
                    ? app(TeamAccessService::class)->allowedProductIdsFor($user)
                    : [],
                'is_platform_admin' => $user?->canAccessPlatformPanel() ?? false,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                'status' => $request->session()->get('status'),
                'zip_unavailable' => $request->session()->get('zip_unavailable'),
                'newly_unlocked_achievements' => $request->session()->get('newly_unlocked_achievements'),
            ],
            'platform' => null,
            'cloud_mode' => (bool) config('getfy.cloud_mode', false),
            'demo_mode' => DemoMode::publicConfig(),
            'allow_new_infoproducers' => InfoproducerRegistrationSettings::isAllowed(),
            'cloud_billing_renew_window_days' => (int) config('getfy.cloud.billing_renew_window_days', 7),
            'appSettings' => $appSettings,
            'public_branding' => $publicBranding,
            'settings_plugin_tabs' => $settingsPluginTabs,
            'pluginNavItems' => $pluginNavItems,
            'plugins' => $plugins,
            'achievementsProgress' => $achievementsProgress,
            'push_enabled' => $pushEnabled,
            'push_provider' => $pushEnabled ? ($pushProvider ?? 'vapid') : null,
            'vapid_public' => $vapidPublic,
            'firebase_client_config' => $firebaseClientConfig ?? null,
            'notifications_unread_count' => $notificationsUnreadCount,
            'med_open_count' => $medOpenCount,
            'platform_admin_sidebar_badges' => $platformAdminSidebarBadges,
            'member_notifications_unread_count' => $memberNotificationsUnreadCount,
            'member_push_subscribed' => $memberPushSubscribed,
            'member_certificate' => $memberCertificate,
            'member_area_admin_preview' => $memberAreaAdminPreview,
            'customer_panel' => $customerPanel,
            'seller_dashboard_template' => ($user && $user->canAccessSellerPanel() && ! $customerPanel)
                ? SellerDashboardTemplate::current()
                : SellerDashboardTemplate::DEFAULT,
            'api_pix_enabled_effective' => $user && $user->canAccessSellerPanel()
                ? ApiPixAccess::effectiveForTenant($tenantId)
                : false,
            'platform_minimum_charge_brl' => $user && $user->canAccessSellerPanel()
                ? app(MinimumChargeService::class)->platformMinimumBrlForTenant($tenantId)
                : 0,
            'physical_products_enabled_effective' => $user && $user->canAccessSellerPanel()
                ? PhysicalProductAccess::globalEnabled()
                : false,
            'seller_panel_support' => $user && $user->canAccessSellerPanel()
                ? SellerPanelSupportSettings::publicConfig()
                : null,
            'account_manager' => ($user && $user->canAccessSellerPanel() && ! $customerPanel)
                ? $sharedCache->accountManagerCard(
                    (int) ($user->isTeam() && $user->tenant_id ? $user->tenant_id : $user->id)
                )
                : null,
            'referral_program' => $user && $user->canAccessSellerPanel()
                ? ReferralProgramSettings::publicConfig()
                : ['enabled' => false],
            'pixgo_enabled_effective' => $user && $user->canAccessSellerPanel()
                ? \App\Services\PixGoAccess::globalEnabled()
                : false,
            'pixgo_sidebar_label' => $user && $user->canAccessSellerPanel()
                ? \App\Services\PixGoAccess::sidebarLabel()
                : \App\Services\PixGoAccess::DEFAULT_SIDEBAR_LABEL,
            'legal' => $sharedCache->legalPublicLinks(),
        ];

        if ($user && ($user->canAccessSellerPanel() || $user->canAccessPlatformPanel())) {
            $i18n = app(PlatformI18nService::class);
            $locale = $i18n->resolveLocale($request);
            $shared['i18n'] = [
                'locale' => $locale,
                'available_languages' => $i18n->activeLanguages(),
                'messages' => $sharedCache->i18nMessages($locale, 'seller'),
            ];
        }

        if (! $skipPanelPwa) {
            $shared['pwa_manifest_url'] = url('/manifest.json');
            $shared['pwa_sw_url'] = url('/painel-sw.js');
            $shared['pwa_sw_version'] = \App\Support\PanelPushSettings::swCacheVersion();
        }

        return $shared;
    }

    private function pageTitleForRoute(?string $name): ?string
    {
        return null;
    }

    private function resolveAvatarUrl(?User $user): ?string
    {
        if ($user === null || ! $user->avatar) {
            return null;
        }

        try {
            return app(StorageService::class)->url($user->avatar);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPublicBranding(): array
    {
        $themePrimary = (string) config('getfy.theme_primary', '#0050fc');
        if ($themePrimary === '') {
            $themePrimary = '#0050fc';
        }
        $pwaTheme = config('getfy.pwa_theme_color');
        $pwaTheme = ($pwaTheme !== null && $pwaTheme !== '') ? (string) $pwaTheme : $themePrimary;
        $favicon = config('getfy.favicon_url');
        $favicon = ($favicon !== null && $favicon !== '') ? BrandingAssetUrls::resolve((string) $favicon) : '/images/favicon.png';
        $loginHero = config('getfy.login_hero_image');
        $loginHero = ($loginHero !== null && $loginHero !== '') ? BrandingAssetUrls::resolve((string) $loginHero) : 'https://cdn.getfy.cloud/login.webp';
        $loginHeroTagline = (string) config('getfy.login_hero_tagline', 'Sua plataforma para vender mais.');
        $loginHeroSubtagline = (string) config('getfy.login_hero_subtagline', 'Feita para quem escala de verdade.');

        return BrandingAssetUrls::resolveData([
            'app_name' => (string) config('getfy.app_name', 'Stacker'),
            'theme_primary' => $themePrimary,
            'pwa_theme_color' => $pwaTheme,
            'app_logo' => (string) config('getfy.app_logo'),
            'app_logo_dark' => (string) config('getfy.app_logo_dark'),
            'app_logo_icon' => (string) config('getfy.app_logo_icon'),
            'app_logo_icon_dark' => (string) config('getfy.app_logo_icon_dark'),
            'login_hero_image' => $loginHero,
            'login_hero_tagline' => $loginHeroTagline,
            'login_hero_subtagline' => $loginHeroSubtagline,
            'favicon_url' => $favicon,
            'pwa_icon_192' => config('getfy.pwa_icon_192'),
            'pwa_icon_512' => config('getfy.pwa_icon_512'),
            'panel_color_scheme' => PanelColorScheme::current(),
            'login_template' => LoginTemplate::current(),
        ]);
    }
}
