<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RequiresPlatformStepUp;
use App\Models\Setting;
use App\Services\LegalDocumentsService;
use App\Services\PhysicalProductAccess;
use App\Services\SellerIntegrationVisibility;
use App\Support\RemoteStorage;
use App\Services\PlatformAuditService;
use App\Support\HtmlSanitizer;
use App\Support\CheckoutTranslations;
use App\Support\CheckoutTurnstileSettings;
use App\Support\LoginTurnstileSettings;
use App\Support\PlatformConfigContext;
use App\Support\RegistrationEmailVerificationSettings;
use App\Support\RegistrationTurnstileSettings;
use App\Support\InfoproducerRegistrationSettings;
use App\Support\AccountManagerSettings;
use App\Support\ProductApprovalSettings;
use App\Support\KycRequirementSettings;
use App\Support\SellerPanelSupportSettings;
use App\Support\PlatformCompanySettings;
use App\Support\DatabaseBackupSettings;
use App\Services\Platform\DatabaseBackupService;
use App\Support\BrazilianDocuments;
use App\Services\InstallationPublicUrlService;
use App\Services\Stacker\ContainerRestartRequestService;
use App\Support\DockerSetupState;
use App\Support\PublicAppUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    use RequiresPlatformStepUp;

    public function index(Request $request): Response|RedirectResponse
    {
        $tab = $request->query('tab');
        if (in_array($tab, ['adquirentes', 'gateways'], true)) {
            return redirect()->route('plataforma.financeiro.index', ['tab' => 'adquirentes']);
        }

        $tenantId = PlatformConfigContext::settingsTenantId();
        $defaultTranslations = config('checkout_translations');
        $checkoutTranslationsRaw = Setting::get('checkout_translations', null, $tenantId);
        $savedTranslations = $checkoutTranslationsRaw
            ? (is_string($checkoutTranslationsRaw) ? json_decode($checkoutTranslationsRaw, true) : $checkoutTranslationsRaw)
            : [];
        $checkoutTranslations = CheckoutTranslations::merge($defaultTranslations, is_array($savedTranslations) ? $savedTranslations : []);

        $currenciesRaw = Setting::get('currencies', null, $tenantId);
        $currencies = $currenciesRaw
            ? (is_string($currenciesRaw) ? json_decode($currenciesRaw, true) : $currenciesRaw)
            : config('products.currencies');
        if (! is_array($currencies)) {
            $currencies = config('products.currencies');
        }

        $cloudMode = (bool) config('getfy.cloud_mode', false);
        $dockerMode = DockerSetupState::isDocker();
        $cronSecret = config('getfy.cron_secret');
        $publicUrlSnapshot = app(InstallationPublicUrlService::class)->snapshot();
        $appUrl = rtrim(PublicAppUrl::base(), '/');
        $cronUrl = $cronSecret ? $appUrl . '/cron?token=' . urlencode((string) $cronSecret) : null;
        $versionFile = base_path('VERSION');
        $currentVersion = trim((is_file($versionFile) ? (string) file_get_contents($versionFile) : '') ?: '');
        if ($currentVersion === '') {
            $currentVersion = (string) config('getfy.version');
        }

        $r2EnvKey = (string) env('R2_ACCESS_KEY_ID', '');
        $r2EnvSecret = (string) env('R2_SECRET_ACCESS_KEY', '');
        $r2EnvBucket = (string) env('R2_BUCKET', '');
        $r2EnvEndpoint = (string) env('R2_ENDPOINT', '');
        $r2EnvConfigured = $r2EnvKey !== '' && $r2EnvSecret !== '' && $r2EnvBucket !== '' && $r2EnvEndpoint !== '';

        $storageProviderSetting = Setting::get('storage_provider', null, $tenantId);
        $effectiveStorageProvider = ($storageProviderSetting === null || $storageProviderSetting === '')
            ? ($cloudMode && $r2EnvConfigured ? 'r2' : 'local')
            : $storageProviderSetting;

        $storageS3Key = (string) Setting::get('storage_s3_key', '', $tenantId);
        $storageS3Bucket = (string) Setting::get('storage_s3_bucket', '', $tenantId);
        $storageS3Region = (string) Setting::get('storage_s3_region', 'us-east-1', $tenantId);
        $storageS3Endpoint = (string) Setting::get('storage_s3_endpoint', '', $tenantId);
        $storageS3Url = (string) Setting::get('storage_s3_url', '', $tenantId);
        $storageS3SecretRaw = (string) Setting::get('storage_s3_secret', '', $tenantId);

        $cloudR2Managed = $cloudMode
            && $r2EnvConfigured
            && $effectiveStorageProvider === 'r2'
            && trim($storageS3Key) === ''
            && trim($storageS3Bucket) === ''
            && trim($storageS3Endpoint) === ''
            && trim($storageS3Url) === ''
            && trim($storageS3SecretRaw) === '';

        $legalForm = app(LegalDocumentsService::class)->forAdminForm();
        $backupService = app(DatabaseBackupService::class);

        return Inertia::render('Settings/Index', [
            'current_version' => $currentVersion,
            'cloud_mode' => $cloudMode,
            'docker_mode' => $dockerMode,
            'app_url' => $appUrl,
            'public_url' => $publicUrlSnapshot['app_url'] ?: $appUrl,
            'resolved_public_url' => $publicUrlSnapshot['resolved_public_url'],
            'webhook_public_url' => $publicUrlSnapshot['webhook_public_url'],
            'public_url_meta' => $publicUrlSnapshot,
            'container_restart' => app(ContainerRestartRequestService::class)->status(),
            'base_path' => base_path(),
            'cron_url' => $cronUrl,
            'settings' => [
                'email_provider' => $this->normalizeEmailProvider(Setting::get('email_provider', 'smtp', $tenantId)),
                'smtp_host' => Setting::get('smtp_host', '', $tenantId),
                'smtp_port' => Setting::get('smtp_port', '587', $tenantId),
                'smtp_username' => Setting::get('smtp_username', '', $tenantId),
                'smtp_encryption' => Setting::get('smtp_encryption', 'tls', $tenantId),
                // do NOT expose smtp_password to the frontend
                'mail_from_address' => Setting::get('mail_from_address', config('mail.from.address'), $tenantId),
                'mail_from_name' => Setting::get('mail_from_name', config('mail.from.name'), $tenantId),
                'reply_to' => Setting::get('reply_to', '', $tenantId),
                // Hostinger: configuração separada (host/porta/criptografia fixos no código)
                'hostinger_smtp_username' => Setting::get('hostinger_smtp_username', '', $tenantId),
                'hostinger_mail_from_address' => Setting::get('hostinger_mail_from_address', '', $tenantId),
                'hostinger_mail_from_name' => Setting::get('hostinger_mail_from_name', '', $tenantId),
                'hostinger_reply_to' => Setting::get('hostinger_reply_to', '', $tenantId),
                // SendGrid: do NOT expose sendgrid_api_key to the frontend
                'sendgrid_mail_from_address' => Setting::get('sendgrid_mail_from_address', config('mail.from.address', ''), $tenantId),
                'sendgrid_mail_from_name' => Setting::get('sendgrid_mail_from_name', config('mail.from.name', ''), $tenantId),
                'kyc_notification_emails' => Setting::get('kyc_notification_emails', '', $tenantId),
                'checkout_translations' => $checkoutTranslations,
                'currencies' => $currencies,
                'storage_provider' => $effectiveStorageProvider,
                'storage_s3_key' => $cloudR2Managed ? '' : $storageS3Key,
                'storage_s3_bucket' => $cloudR2Managed ? '' : $storageS3Bucket,
                'storage_s3_region' => $effectiveStorageProvider === 'r2' ? 'auto' : $storageS3Region,
                'storage_s3_endpoint' => $cloudR2Managed ? '' : $storageS3Endpoint,
                'storage_s3_url' => $cloudR2Managed ? '' : $storageS3Url,
                'storage_cloud_r2_managed' => $cloudR2Managed,
                'physical_products_enabled' => PhysicalProductAccess::globalEnabled(),
                ...($tenantId === null ? SellerIntegrationVisibility::forSettingsForm() : []),
                ...($tenantId === null ? CheckoutTurnstileSettings::forSettingsForm() : []),
                ...($tenantId === null ? LoginTurnstileSettings::forSettingsForm() : []),
                ...($tenantId === null ? RegistrationTurnstileSettings::forSettingsForm() : []),
                ...($tenantId === null ? RegistrationEmailVerificationSettings::forSettingsForm() : []),
                ...($tenantId === null ? InfoproducerRegistrationSettings::forSettingsForm() : []),
                ...($tenantId === null ? ProductApprovalSettings::forSettingsForm() : []),
                ...($tenantId === null ? KycRequirementSettings::forSettingsForm() : []),
                ...($tenantId === null ? AccountManagerSettings::forSettingsForm() : []),
                ...($tenantId === null ? SellerPanelSupportSettings::forSettingsForm() : []),
                ...($tenantId === null ? PlatformCompanySettings::forSettingsForm() : []),
                ...($tenantId === null ? [
                    'legal_privacy_policy_html' => $legalForm['legal_privacy_policy_html'],
                    'legal_terms_of_use_html' => $legalForm['legal_terms_of_use_html'],
                    'legal_privacy_contact_email' => $legalForm['legal_privacy_contact_email'],
                    'legal_cookie_banner_enabled' => $legalForm['legal_cookie_banner_enabled'],
                ] : []),
                ...($tenantId === null ? DatabaseBackupSettings::forSettingsForm() : []),
            ],
            'backup_files' => $tenantId === null ? $backupService->listBackups() : [],
            'backup_status' => $tenantId === null ? DatabaseBackupSettings::lastRun() : null,
            'backup_storage' => $tenantId === null ? [
                'provider' => $backupService->destinationProvider(),
                'is_remote' => $backupService->destinationIsRemote(),
                'label' => $backupService->destinationLabel(),
                'prefix' => DatabaseBackupSettings::destinationPrefix(),
                'independent_from_media' => true,
            ] : null,
            'legal_defaults' => $tenantId === null ? ($legalForm['legal_defaults'] ?? []) : [],
            'seller_integrations_catalog' => $tenantId === null ? SellerIntegrationVisibility::catalog() : [],
        ]);
    }

    public function update(Request $request)
    {
        if (PlatformConfigContext::settingsTenantId() === null && $this->requestTouchesEmailSettings($request)) {
            $this->validatePlatformStepUp($request);
        }

        $validated = $request->validate([
            'totp_code' => ['nullable', 'string', 'max:16'],
            'email_provider' => ['nullable', 'string', 'in:smtp,hostinger,sendgrid'],
            'smtp_password' => ['nullable', 'string', 'max:255'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],
            'reply_to' => ['nullable', 'email', 'max:255'],
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'string', 'max:10'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_encryption' => ['nullable', 'string', 'in:tls,ssl'],
            'hostinger_smtp_password' => ['nullable', 'string', 'max:255'],
            'hostinger_smtp_username' => ['nullable', 'string', 'max:255'],
            'hostinger_mail_from_address' => ['nullable', 'email', 'max:255'],
            'hostinger_mail_from_name' => ['nullable', 'string', 'max:255'],
            'hostinger_reply_to' => ['nullable', 'email', 'max:255'],
            'sendgrid_api_key' => ['nullable', 'string', 'max:512'],
            'sendgrid_mail_from_address' => ['nullable', 'email', 'max:255'],
            'sendgrid_mail_from_name' => ['nullable', 'string', 'max:255'],
            'kyc_notification_emails' => ['nullable', 'string', 'max:5000'],
            'checkout_translations' => ['nullable', 'array'],
            'checkout_translations.pt_BR' => ['nullable', 'array'],
            'checkout_translations.en' => ['nullable', 'array'],
            'checkout_translations.es' => ['nullable', 'array'],
            'currencies' => ['nullable', 'array'],
            'currencies.*.code' => ['required', 'string', 'max:10'],
            'currencies.*.symbol' => ['required', 'string', 'max:10'],
            'currencies.*.label' => ['required', 'string', 'max:100'],
            'currencies.*.rate_to_brl' => ['required', 'numeric', 'min:0'],
            'storage_provider' => ['nullable', 'string', 'in:local,s3,wasabi,r2'],
            'storage_s3_key' => ['nullable', 'string', 'max:255'],
            'storage_s3_secret' => ['nullable', 'string', 'max:512'],
            'storage_s3_bucket' => ['nullable', 'string', 'max:255'],
            'storage_s3_region' => ['nullable', 'string', 'max:64'],
            'storage_s3_endpoint' => ['nullable', 'string', 'max:512'],
            'storage_s3_url' => ['nullable', 'string', 'max:512'],
            'physical_products_enabled' => ['nullable', 'boolean'],
            ...SellerIntegrationVisibility::validationRules(),
            'checkout_turnstile_site_key' => ['nullable', 'string', 'max:255'],
            'checkout_turnstile_secret_key' => ['nullable', 'string', 'max:512'],
            'login_turnstile_enabled' => ['nullable', 'boolean'],
            'registration_turnstile_enabled' => ['nullable', 'boolean'],
            'registration_email_verification_enabled' => ['nullable', 'boolean'],
            'allow_new_infoproducers' => ['nullable', 'boolean'],
            'auto_approve_products' => ['nullable', 'boolean'],
            'account_manager_auto_assign_mode' => ['nullable', 'string', 'in:none,least_load'],
            'seller_panel_support_enabled' => ['nullable', 'boolean'],
            'seller_panel_support_destination' => ['nullable', 'string', 'in:whatsapp,url'],
            'seller_panel_support_whatsapp' => ['nullable', 'string', 'max:32'],
            'seller_panel_support_url' => ['nullable', 'string', 'max:2048'],
            'seller_panel_support_icon' => ['nullable', 'string', 'in:whatsapp,message,headset,help,custom'],
            'seller_panel_support_color' => ['nullable', 'string', 'max:16'],
            'legal_privacy_policy_html' => ['nullable', 'string', 'max:200000'],
            'legal_terms_of_use_html' => ['nullable', 'string', 'max:200000'],
            'legal_privacy_contact_email' => ['nullable', 'email', 'max:255'],
            'legal_cookie_banner_enabled' => ['nullable', 'boolean'],
            'kyc_allowed_identity_types' => ['nullable', 'array', 'min:1'],
            'kyc_allowed_identity_types.*' => ['string', 'in:rg,cnh,passport'],
            'kyc_require_address_proof' => ['nullable', 'boolean'],
            'kyc_require_selfie_with_document' => ['nullable', 'boolean'],
            'kyc_require_company_address_proof' => ['nullable', 'boolean'],
            'kyc_require_company_constitution' => ['nullable', 'boolean'],
            'platform_legal_name' => ['nullable', 'string', 'max:255'],
            'platform_cnpj' => [
                'nullable',
                'string',
                'max:18',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $raw = trim((string) $value);
                    if ($raw === '') {
                        return;
                    }
                    if (! BrazilianDocuments::isValidCnpj($raw)) {
                        $fail('Informe um CNPJ válido.');
                    }
                },
            ],
            'platform_checkout_notice_enabled' => ['nullable', 'boolean'],
            'platform_checkout_notice' => ['nullable', 'string', 'max:5000'],
            ...DatabaseBackupSettings::validationRules(),
        ]);

        $tenantId = PlatformConfigContext::settingsTenantId();

        $this->persistEmailProviderFromRequest($request, $tenantId);

        if ($tenantId === null) {
            if (array_key_exists('checkout_turnstile_site_key', $validated)) {
                Setting::set('checkout_turnstile_site_key', trim((string) ($validated['checkout_turnstile_site_key'] ?? '')), null);
            }
            CheckoutTurnstileSettings::storeSecret($validated['checkout_turnstile_secret_key'] ?? null);

            if (array_key_exists('login_turnstile_enabled', $validated)) {
                Setting::set(
                    'login_turnstile_enabled',
                    ($validated['login_turnstile_enabled'] ?? false) ? '1' : '0',
                    null
                );
            }

            if (array_key_exists('registration_turnstile_enabled', $validated)) {
                Setting::set(
                    'registration_turnstile_enabled',
                    ($validated['registration_turnstile_enabled'] ?? false) ? '1' : '0',
                    null
                );
            }

            if (array_key_exists('registration_email_verification_enabled', $validated)) {
                $enabling = ($validated['registration_email_verification_enabled'] ?? false) === true
                    || $validated['registration_email_verification_enabled'] === '1'
                    || $validated['registration_email_verification_enabled'] === 1;
                $wasEnabled = RegistrationEmailVerificationSettings::isEnabled();
                Setting::set(
                    'registration_email_verification_enabled',
                    $enabling ? '1' : '0',
                    null
                );
                if ($enabling && ! $wasEnabled) {
                    RegistrationEmailVerificationSettings::grandfatherExistingInfoprodutors();
                }
            }

            if (array_key_exists('allow_new_infoproducers', $validated)) {
                $allowing = ($validated['allow_new_infoproducers'] ?? false) === true
                    || $validated['allow_new_infoproducers'] === '1'
                    || $validated['allow_new_infoproducers'] === 1;
                $wasAllowed = InfoproducerRegistrationSettings::isAllowed();
                $next = $allowing ? '1' : '0';
                Setting::set(InfoproducerRegistrationSettings::KEY, $next, null);
                if ($wasAllowed !== $allowing) {
                    PlatformAuditService::log('settings.infoproducer_registration.updated', [
                        'from' => $wasAllowed ? '1' : '0',
                        'to' => $next,
                    ], $request);
                }
            }

            if (array_key_exists('auto_approve_products', $validated)) {
                $enabling = ($validated['auto_approve_products'] ?? false) === true
                    || $validated['auto_approve_products'] === '1'
                    || $validated['auto_approve_products'] === 1;
                $wasEnabled = ProductApprovalSettings::autoApproveEnabled();
                $next = $enabling ? '1' : '0';
                Setting::set(ProductApprovalSettings::KEY, $next, null);
                if ($wasEnabled !== $enabling) {
                    PlatformAuditService::log('products.auto_approval_setting_updated', [
                        'from' => $wasEnabled ? '1' : '0',
                        'to' => $next,
                    ], $request);
                }
            }

            if (array_key_exists('account_manager_auto_assign_mode', $validated)) {
                $mode = (string) ($validated['account_manager_auto_assign_mode'] ?? AccountManagerSettings::MODE_LEAST_LOAD);
                if (! in_array($mode, [AccountManagerSettings::MODE_NONE, AccountManagerSettings::MODE_LEAST_LOAD], true)) {
                    $mode = AccountManagerSettings::MODE_LEAST_LOAD;
                }
                $previous = AccountManagerSettings::mode();
                Setting::set(AccountManagerSettings::KEY_MODE, $mode, null);
                if ($previous !== $mode) {
                    PlatformAuditService::log('account_managers.auto_assign_mode_updated', [
                        'from' => $previous,
                        'to' => $mode,
                    ], $request);
                }
            }

            SellerPanelSupportSettings::persistFromValidated($validated);
            PlatformCompanySettings::persistFromValidated($validated);
            KycRequirementSettings::persistFromValidated($validated);
            DatabaseBackupSettings::persistFromValidated($validated, $request);
        }

        if ($tenantId === null && array_key_exists('physical_products_enabled', $validated)) {
            Setting::set(
                PhysicalProductAccess::SETTING_KEY,
                ($validated['physical_products_enabled'] ?? false) ? '1' : '0',
                null
            );
        }

        if ($tenantId === null) {
            foreach (SellerIntegrationVisibility::ids() as $integrationId) {
                $key = SellerIntegrationVisibility::settingKey($integrationId);
                if (! array_key_exists($key, $validated)) {
                    continue;
                }
                $enabling = filter_var($validated[$key], FILTER_VALIDATE_BOOLEAN);
                $wasEnabled = SellerIntegrationVisibility::globalEnabled($integrationId);
                SellerIntegrationVisibility::setGlobal($integrationId, $enabling);
                if ($wasEnabled !== $enabling) {
                    PlatformAuditService::log('settings.seller_integration.updated', [
                        'integration' => $integrationId,
                        'from' => $wasEnabled ? '1' : '0',
                        'to' => $enabling ? '1' : '0',
                    ], $request);
                }
            }
        }

        $emailKeys = [
            'smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption',
            'mail_from_address', 'mail_from_name', 'reply_to',
            'hostinger_smtp_username', 'hostinger_mail_from_address', 'hostinger_mail_from_name', 'hostinger_reply_to',
            'sendgrid_mail_from_address', 'sendgrid_mail_from_name',
            'kyc_notification_emails',
        ];
        $alwaysSetKeys = ['email_provider'];
        $brandingKeys = ['theme_primary', 'app_name', 'app_logo', 'app_logo_dark', 'app_logo_icon', 'app_logo_icon_dark'];
        $sellerPanelSupportKeys = [
            'seller_panel_support_enabled',
            'seller_panel_support_destination',
            'seller_panel_support_whatsapp',
            'seller_panel_support_url',
            'seller_panel_support_icon',
            'seller_panel_support_color',
        ];
        $platformCompanyKeys = [
            'platform_legal_name',
            'platform_cnpj',
            'platform_checkout_notice_enabled',
            'platform_checkout_notice',
        ];
        $securityToggleKeys = [
            'login_turnstile_enabled',
            'registration_turnstile_enabled',
            'registration_email_verification_enabled',
            'allow_new_infoproducers',
            'auto_approve_products',
            'account_manager_auto_assign_mode',
            'checkout_turnstile_site_key',
            'checkout_turnstile_secret_key',
            'physical_products_enabled',
            ...SellerIntegrationVisibility::settingKeys(),
        ];
        $kycRequirementKeys = [
            'kyc_allowed_identity_types',
            'kyc_require_address_proof',
            'kyc_require_selfie_with_document',
            'kyc_require_company_address_proof',
            'kyc_require_company_constitution',
        ];
        $backupKeys = DatabaseBackupSettings::FORM_KEYS;
        // Handle passwords separately (encrypt)
        if (array_key_exists('smtp_password', $validated) && $validated['smtp_password'] !== null && $validated['smtp_password'] !== '') {
            Setting::set('smtp_password', encrypt($validated['smtp_password']), $tenantId);
        }
        if (array_key_exists('hostinger_smtp_password', $validated) && $validated['hostinger_smtp_password'] !== null && $validated['hostinger_smtp_password'] !== '') {
            Setting::set('hostinger_smtp_password', encrypt($validated['hostinger_smtp_password']), $tenantId);
        }
        if (array_key_exists('sendgrid_api_key', $validated) && $validated['sendgrid_api_key'] !== null && $validated['sendgrid_api_key'] !== '') {
            Setting::set('sendgrid_api_key', encrypt($validated['sendgrid_api_key']), $tenantId);
        }
        if (array_key_exists('storage_s3_secret', $validated) && $validated['storage_s3_secret'] !== null && $validated['storage_s3_secret'] !== '') {
            Setting::set('storage_s3_secret', Crypt::encryptString($validated['storage_s3_secret']), $tenantId);
        }

        $storageKeys = [
            'storage_provider', 'storage_s3_key', 'storage_s3_bucket', 'storage_s3_region',
            'storage_s3_endpoint', 'storage_s3_url',
        ];

        if ($tenantId === null) {
            $this->persistLegalSettings($request, $validated);
        }

        $this->validateStorageSettings($validated, $tenantId);

        $activeProvider = $this->normalizeEmailProvider(
            $request->input('email_provider') ?? Setting::get('email_provider', 'smtp', $tenantId)
        );
        $validated = $this->syncOwnedSmtpFromAddresses($validated, $tenantId, $activeProvider);

        foreach ($validated as $key => $value) {
            if (in_array($key, ['smtp_password', 'hostinger_smtp_password', 'sendgrid_api_key', 'storage_s3_secret'], true)) {
                continue;
            }
            if (str_starts_with($key, 'legal_')) {
                continue;
            }
            if (in_array($key, $sellerPanelSupportKeys, true)) {
                continue;
            }
            if (in_array($key, $platformCompanyKeys, true)) {
                continue;
            }
            if (in_array($key, $securityToggleKeys, true)) {
                continue;
            }
            if (in_array($key, $kycRequirementKeys, true)) {
                continue;
            }
            if (in_array($key, $backupKeys, true)) {
                continue;
            }
            if (in_array($key, $brandingKeys, true)) {
                continue; // branding hardcoded in config/getfy.php - never save
            }

            if (in_array($key, $alwaysSetKeys, true) || in_array($key, $emailKeys, true)) {
                if ($key === 'email_provider' && ! in_array($value, ['smtp', 'hostinger', 'sendgrid'], true)) {
                    continue;
                }
                Setting::set($key, $value ?? '', $tenantId);
            } elseif (in_array($key, $storageKeys, true)) {
                if ($key === 'storage_s3_url' && is_string($value)) {
                    $value = RemoteStorage::normalizePublicBaseUrl($value);
                }
                Setting::set($key, $value ?? '', $tenantId);
            } elseif ($key === 'checkout_translations' || $key === 'currencies') {
                if (is_array($value) && ! empty($value)) {
                    Setting::set($key, $value, $tenantId);
                }
            } elseif ($value !== null && $value !== '') {
                Setting::set($key, $value, $tenantId);
            }
        }

        return back()->with('success', 'Configurações salvas.');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function validateStorageSettings(array $validated, ?int $tenantId): void
    {
        $provider = $validated['storage_provider'] ?? Setting::get('storage_provider', 'local', $tenantId);
        if (! in_array($provider, ['s3', 'wasabi', 'r2'], true)) {
            return;
        }

        $publicUrl = RemoteStorage::normalizePublicBaseUrl((string) ($validated['storage_s3_url'] ?? Setting::get('storage_s3_url', '', $tenantId)));

        if ($provider === 'r2' && $publicUrl === '') {
            $cloudMode = (bool) config('getfy.cloud_mode', false);
            $r2Public = RemoteStorage::normalizePublicBaseUrl((string) env('R2_PUBLIC_URL', ''));
            $managed = $cloudMode
                && $r2Public !== ''
                && trim((string) ($validated['storage_s3_key'] ?? '')) === ''
                && trim((string) ($validated['storage_s3_bucket'] ?? '')) === '';

            if (! $managed) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'storage_s3_url' => 'Para Cloudflare R2, a URL pública é obrigatória (pub-*.r2.dev ou domínio customizado com acesso público ao bucket).',
                ]);
            }
        }

        if ($publicUrl !== '' && RemoteStorage::isR2ApiEndpoint($publicUrl)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'storage_s3_url' => 'Use a URL pública do bucket, não o endpoint S3 (*.r2.cloudflarestorage.com).',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function persistLegalSettings(Request $request, array $validated): void
    {
        $now = now()->toIso8601String();

        if (array_key_exists('legal_privacy_contact_email', $validated)) {
            Setting::set(
                LegalDocumentsService::SETTING_PRIVACY_EMAIL,
                trim((string) ($validated['legal_privacy_contact_email'] ?? '')),
                null
            );
        }

        if (array_key_exists('legal_cookie_banner_enabled', $validated)) {
            Setting::set(
                LegalDocumentsService::SETTING_COOKIE_BANNER,
                ($validated['legal_cookie_banner_enabled'] ?? false) ? '1' : '0',
                null
            );
        }

        if (array_key_exists('legal_privacy_policy_html', $validated) && $validated['legal_privacy_policy_html'] !== null) {
            Setting::set(
                LegalDocumentsService::SETTING_PRIVACY_HTML,
                HtmlSanitizer::sanitize((string) $validated['legal_privacy_policy_html']),
                null
            );
            Setting::set(LegalDocumentsService::SETTING_PRIVACY_UPDATED_AT, $now, null);
        }

        if (array_key_exists('legal_terms_of_use_html', $validated) && $validated['legal_terms_of_use_html'] !== null) {
            Setting::set(
                LegalDocumentsService::SETTING_TERMS_HTML,
                HtmlSanitizer::sanitize((string) $validated['legal_terms_of_use_html']),
                null
            );
            Setting::set(LegalDocumentsService::SETTING_TERMS_UPDATED_AT, $now, null);
        }

        PlatformAuditService::log('platform.legal.updated', [
            'privacy_updated' => array_key_exists('legal_privacy_policy_html', $validated),
            'terms_updated' => array_key_exists('legal_terms_of_use_html', $validated),
        ], $request);
    }

    private function normalizeEmailProvider(mixed $value): string
    {
        $v = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($v, ['smtp', 'hostinger', 'sendgrid'], true) ? $v : 'smtp';
    }

    private function persistEmailProviderFromRequest(Request $request, ?int $tenantId): void
    {
        if (! $request->has('email_provider')) {
            return;
        }

        Setting::set('email_provider', $this->normalizeEmailProvider($request->input('email_provider')), $tenantId);
    }

    /**
     * SMTP/Hostinger: From deve ser a mailbox autenticada (evita 553 "not owned by user").
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function syncOwnedSmtpFromAddresses(array $validated, ?int $tenantId, string $provider): array
    {
        if ($provider === 'hostinger') {
            $user = trim((string) ($validated['hostinger_smtp_username'] ?? Setting::get('hostinger_smtp_username', '', $tenantId)));
            if ($user !== '' && filter_var($user, FILTER_VALIDATE_EMAIL)) {
                $validated['hostinger_mail_from_address'] = $user;
            }

            return $validated;
        }

        if ($provider === 'smtp') {
            $user = trim((string) ($validated['smtp_username'] ?? Setting::get('smtp_username', '', $tenantId)));
            if ($user !== '' && filter_var($user, FILTER_VALIDATE_EMAIL)) {
                $validated['mail_from_address'] = $user;
            }
        }

        return $validated;
    }

    private function requestTouchesEmailSettings(Request $request): bool
    {
        $keys = [
            'email_provider',
            'smtp_password',
            'smtp_host',
            'smtp_port',
            'smtp_username',
            'smtp_encryption',
            'mail_from_address',
            'mail_from_name',
            'reply_to',
            'hostinger_smtp_password',
            'hostinger_smtp_username',
            'hostinger_mail_from_address',
            'hostinger_mail_from_name',
            'hostinger_reply_to',
            'sendgrid_api_key',
            'sendgrid_mail_from_address',
            'sendgrid_mail_from_name',
            'kyc_notification_emails',
        ];

        foreach ($keys as $key) {
            if ($request->has($key) && trim((string) $request->input($key, '')) !== '') {
                return true;
            }
        }

        return $request->has('email_provider');
    }
}
