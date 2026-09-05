<script setup>
import { ref, computed, defineAsyncComponent, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import {
    Mail,
    Languages,
    Banknote,
    HardDrive,
    Clock,
    AlertCircle,
    Trash2,
    RefreshCw,
    Upload,
    Tag,
    Palette,
    Images,
    LayoutGrid,
    Truck,
    Shield,
    Scale,
    PlayCircle,
    Headset,
    DatabaseBackup,
    Puzzle,
    Globe,
    Building2,
    BadgeCheck,
    Cable,
} from 'lucide-vue-next';
import IntegrationCard from '@/components/IntegrationCard.vue';
import EmailProviderSidebar from '@/components/EmailProviderSidebar.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';
import BrandingTab from '@/Pages/Settings/Tabs/BrandingTab.vue';
import DashboardBannersTab from '@/Pages/Settings/Tabs/DashboardBannersTab.vue';
import DashboardTemplateTab from '@/Pages/Settings/Tabs/DashboardTemplateTab.vue';
import LanguagesTab from '@/Pages/Settings/Tabs/LanguagesTab.vue';
import SecurityTab from '@/Pages/Settings/Tabs/SecurityTab.vue';
import KycTab from '@/Pages/Settings/Tabs/KycTab.vue';
import DemoTab from '@/Pages/Settings/Tabs/DemoTab.vue';
import LegalTab from '@/Pages/Settings/Tabs/LegalTab.vue';
import SellerPanelSupportTab from '@/Pages/Settings/Tabs/SellerPanelSupportTab.vue';
import BackupTab from '@/Pages/Settings/Tabs/BackupTab.vue';
import PublicUrlTab from '@/Pages/Settings/Tabs/PublicUrlTab.vue';
import PlatformDataTab from '@/Pages/Settings/Tabs/PlatformDataTab.vue';
import SellerIntegrationsTab from '@/Pages/Settings/Tabs/SellerIntegrationsTab.vue';
defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    settings: {
        type: Object,
        required: true,
    },
    current_version: {
        type: String,
        default: '1.0.0',
    },
    cloud_mode: {
        type: Boolean,
        default: false,
    },
    docker_mode: {
        type: Boolean,
        default: false,
    },
    app_url: {
        type: String,
        default: '',
    },
    public_url: {
        type: String,
        default: '',
    },
    resolved_public_url: {
        type: String,
        default: '',
    },
    webhook_public_url: {
        type: String,
        default: '',
    },
    public_url_meta: {
        type: Object,
        default: () => ({}),
    },
    container_restart: {
        type: Object,
        default: () => ({}),
    },
    base_path: {
        type: String,
        default: '',
    },
    cron_url: {
        type: String,
        default: null,
    },
    settings_plugin_tabs: {
        type: Array,
        default: () => [],
    },
    legal_defaults: {
        type: Object,
        default: () => ({}),
    },
    seller_integrations_catalog: {
        type: Array,
        default: () => [],
    },
    backup_files: {
        type: Array,
        default: () => [],
    },
    backup_status: {
        type: Object,
        default: null,
    },
    backup_storage: {
        type: Object,
        default: () => ({}),
    },
});

function allAllowedTabIds() {
    const core = ['email', 'storage', 'backup', 'personalizacao', 'banners_dashboard', 'template_dashboard', 'idiomas', 'traducoes', 'moedas', 'recursos', 'suporte_painel', 'seguranca', 'kyc', 'lgpd', 'integracoes', 'dados_plataforma', 'url_publica', 'cron', 'update', 'demo'];
    const extra = (props.settings_plugin_tabs || []).map((t) => t.id).filter(Boolean);
    return [...core, ...extra];
}

const activeTab = ref('email');
if (typeof window !== 'undefined') {
    const t = new URLSearchParams(window.location.search).get('tab');
    const legacyTabMap = { gateways: 'email' };
    const resolved = t && legacyTabMap[t] ? legacyTabMap[t] : t;
    if (resolved && allAllowedTabIds().includes(resolved)) activeTab.value = resolved;
    const isMobile = window.matchMedia && window.matchMedia('(max-width: 639px)').matches;
    if (isMobile && activeTab.value === 'traducoes') activeTab.value = 'email';
}

const pluginTabIds = computed(() => (props.settings_plugin_tabs || []).map((t) => t.id).filter(Boolean));

function isPluginTab(tabId) {
    return pluginTabIds.value.includes(tabId);
}

const pluginPagesGlob = import.meta.glob('../../PluginPages/**/*.vue');
const pluginTabComponentCache = new Map();

function getPluginTabComponent(componentName) {
    if (!componentName || typeof componentName !== 'string') {
        return null;
    }
    if (pluginTabComponentCache.has(componentName)) {
        return pluginTabComponentCache.get(componentName);
    }
    const rel = componentName.startsWith('Plugin/') ? componentName.slice(7) : componentName;
    const path = `../../PluginPages/${rel}.vue`;
    const loader = pluginPagesGlob[path];
    if (!loader) {
        pluginTabComponentCache.set(componentName, null);
        return null;
    }
    const asyncComp = defineAsyncComponent(loader);
    pluginTabComponentCache.set(componentName, asyncComp);
    return asyncComp;
}

const defaultTranslations = () => ({
    pt_BR: {},
    en: {},
    es: {},
    ...(props.settings.checkout_translations ?? {}),
});
const defaultCurrencies = () => [...(props.settings.currencies ?? [])];

const form = useForm({
    smtp_host: props.settings.smtp_host ?? '',
    smtp_port: props.settings.smtp_port ?? '587',
    smtp_username: props.settings.smtp_username ?? '',
    smtp_encryption: props.settings.smtp_encryption ?? 'tls',
    smtp_password: '', // never pre-fill password
    mail_from_address: props.settings.mail_from_address ?? '',
    mail_from_name: props.settings.mail_from_name ?? '',
    reply_to: props.settings.reply_to ?? '',
    email_provider: props.settings.email_provider ?? 'smtp',
    hostinger_smtp_username: props.settings.hostinger_smtp_username ?? '',
    hostinger_smtp_password: '', // never pre-fill password
    hostinger_mail_from_address: props.settings.hostinger_mail_from_address ?? '',
    hostinger_mail_from_name: props.settings.hostinger_mail_from_name ?? '',
    hostinger_reply_to: props.settings.hostinger_reply_to ?? '',
    sendgrid_api_key: '', // never pre-fill API key
    sendgrid_mail_from_address: props.settings.sendgrid_mail_from_address ?? '',
    sendgrid_mail_from_name: props.settings.sendgrid_mail_from_name ?? '',
    kyc_notification_emails: props.settings.kyc_notification_emails ?? '',
    checkout_translations: defaultTranslations(),
    currencies: defaultCurrencies(),
    storage_provider: props.settings.storage_provider ?? 'local',
    storage_s3_key: props.settings.storage_s3_key ?? '',
    storage_s3_secret: '', // never pre-fill
    storage_s3_bucket: props.settings.storage_s3_bucket ?? '',
    storage_s3_region: props.settings.storage_provider === 'r2' ? 'auto' : (props.settings.storage_s3_region ?? 'us-east-1'),
    storage_s3_endpoint: props.settings.storage_s3_endpoint ?? '',
    storage_s3_url: props.settings.storage_s3_url ?? '',
    backup_enabled: props.settings.backup_enabled ?? '0',
    backup_daily_at: props.settings.backup_daily_at ?? '03:00',
    backup_retention_days: Number(props.settings.backup_retention_days ?? 7) || 7,
    backup_destination_provider: props.settings.backup_destination_provider ?? 'local',
    backup_destination_s3_key: props.settings.backup_destination_s3_key ?? '',
    backup_destination_s3_secret: '',
    backup_destination_s3_bucket: props.settings.backup_destination_s3_bucket ?? '',
    backup_destination_s3_region: props.settings.backup_destination_provider === 'r2'
        ? 'auto'
        : (props.settings.backup_destination_s3_region ?? 'us-east-1'),
    backup_destination_s3_endpoint: props.settings.backup_destination_s3_endpoint ?? '',
    backup_destination_prefix: props.settings.backup_destination_prefix ?? 'backups/db',
    backup_destination_secret_configured: Boolean(props.settings.backup_destination_secret_configured),
    physical_products_enabled: Boolean(props.settings.physical_products_enabled),
    integration_webhook_enabled: props.settings.integration_webhook_enabled !== false,
    integration_utmify_enabled: props.settings.integration_utmify_enabled !== false,
    integration_spedy_enabled: props.settings.integration_spedy_enabled !== false,
    integration_cademi_enabled: props.settings.integration_cademi_enabled !== false,
    checkout_turnstile_site_key: props.settings.checkout_turnstile_site_key ?? '',
    checkout_turnstile_secret_key: '',
    checkout_turnstile_secret_configured: Boolean(props.settings.checkout_turnstile_secret_configured),
    turnstile_keys_configured: Boolean(props.settings.turnstile_keys_configured),
    login_turnstile_enabled: props.settings.login_turnstile_enabled ?? '0',
    registration_turnstile_enabled: props.settings.registration_turnstile_enabled ?? '0',
    registration_email_verification_enabled: props.settings.registration_email_verification_enabled ?? '0',
    allow_new_infoproducers: props.settings.allow_new_infoproducers ?? '1',
    auto_approve_products: props.settings.auto_approve_products ?? '1',
    account_manager_auto_assign_mode: props.settings.account_manager_auto_assign_mode ?? 'least_load',
    legal_privacy_policy_html: props.settings.legal_privacy_policy_html ?? '',
    legal_terms_of_use_html: props.settings.legal_terms_of_use_html ?? '',
    legal_privacy_contact_email: props.settings.legal_privacy_contact_email ?? '',
    legal_cookie_banner_enabled: props.settings.legal_cookie_banner_enabled !== false,
    seller_panel_support_enabled: props.settings.seller_panel_support_enabled ?? '0',
    seller_panel_support_destination: props.settings.seller_panel_support_destination ?? 'whatsapp',
    seller_panel_support_whatsapp: props.settings.seller_panel_support_whatsapp ?? '',
    seller_panel_support_url: props.settings.seller_panel_support_url ?? '',
    seller_panel_support_icon: props.settings.seller_panel_support_icon ?? 'whatsapp',
    seller_panel_support_icon_image: props.settings.seller_panel_support_icon_image ?? '',
    seller_panel_support_color: props.settings.seller_panel_support_color ?? '#25D366',
    platform_legal_name: props.settings.platform_legal_name ?? '',
    platform_cnpj: props.settings.platform_cnpj ?? '',
    platform_checkout_notice_enabled: props.settings.platform_checkout_notice_enabled ?? '0',
    platform_checkout_notice: props.settings.platform_checkout_notice ?? '',
    kyc_allowed_identity_types: Array.isArray(props.settings.kyc_allowed_identity_types)
        ? [...props.settings.kyc_allowed_identity_types]
        : ['rg', 'cnh', 'passport'],
    kyc_require_address_proof: props.settings.kyc_require_address_proof ?? '1',
    kyc_require_selfie_with_document: props.settings.kyc_require_selfie_with_document ?? '1',
    kyc_require_company_address_proof: props.settings.kyc_require_company_address_proof ?? '1',
    kyc_require_company_constitution: props.settings.kyc_require_company_constitution ?? '1',
});

const showCloudR2Override = ref(false);

const testForm = useForm({
    test_to: '',
});

import { ref as vueRef } from 'vue';
const connectionResult = vueRef({ status: null, message: '' });
const sendResult = vueRef({ status: null, message: '' });
const connectionTesting = vueRef(false);
const sendTestSending = vueRef(false);

const coreTabsStatic = [
    { id: 'email', label: 'E-mail', icon: Mail, group: 'comunicacao' },
    { id: 'storage', label: 'Storage', icon: HardDrive, group: 'operacao' },
    { id: 'backup', label: 'Backup', icon: DatabaseBackup, group: 'operacao' },
    { id: 'personalizacao', label: 'Personalização', icon: Palette, group: 'aparencia' },
    { id: 'banners_dashboard', label: 'Banners do dashboard', icon: Images, group: 'aparencia' },
    { id: 'template_dashboard', label: 'Template do dashboard', icon: LayoutGrid, group: 'aparencia' },
    { id: 'idiomas', label: 'Idiomas', icon: Languages, group: 'internacional' },
    { id: 'traducoes', label: 'Traduções', icon: Languages, group: 'internacional', hideOnMobile: true },
    { id: 'moedas', label: 'Moedas', icon: Banknote, group: 'internacional' },
    { id: 'recursos', label: 'Recursos', icon: Truck, group: 'operacao' },
    { id: 'suporte_painel', label: 'Suporte do painel', icon: Headset, group: 'operacao' },
    { id: 'seguranca', label: 'Segurança', icon: Shield, group: 'seguranca' },
    { id: 'kyc', label: 'KYC', icon: BadgeCheck, group: 'seguranca' },
    { id: 'lgpd', label: 'LGPD', icon: Scale, group: 'seguranca' },
    { id: 'integracoes', label: 'Integrações', icon: Cable, group: 'sistema' },
    { id: 'dados_plataforma', label: 'Dados da plataforma', icon: Building2, group: 'sistema' },
    { id: 'url_publica', label: 'URL pública', icon: Globe, group: 'sistema' },
    { id: 'cron', label: 'Cron', icon: Clock, group: 'sistema' },
    { id: 'update', label: 'Versão', icon: Tag, group: 'sistema' },
    { id: 'demo', label: 'Demo', icon: PlayCircle, group: 'sistema' },
];

const tabGroups = [
    { id: 'comunicacao', label: 'Comunicação' },
    { id: 'aparencia', label: 'Aparência' },
    { id: 'internacional', label: 'Idiomas e moedas' },
    { id: 'operacao', label: 'Operação' },
    { id: 'seguranca', label: 'Segurança e legal' },
    { id: 'sistema', label: 'Sistema' },
    { id: 'plugins', label: 'Plugins' },
];

const tabs = computed(() => {
    const plug = (props.settings_plugin_tabs || []).map((t) => ({
        id: t.id,
        label: t.label,
        icon: Puzzle,
        group: 'plugins',
    }));
    return [...coreTabsStatic, ...plug];
});

const tabsByGroup = computed(() => {
    return tabGroups
        .map((group) => ({
            ...group,
            items: tabs.value.filter((tab) => tab.group === group.id),
        }))
        .filter((group) => group.items.length > 0);
});

const mobileTabsByGroup = computed(() => {
    return tabsByGroup.value
        .map((group) => ({
            ...group,
            items: group.items.filter((tab) => !tab.hideOnMobile),
        }))
        .filter((group) => group.items.length > 0);
});

const activeTabMeta = computed(() => tabs.value.find((t) => t.id === activeTab.value) || null);

function setActiveTab(tabId) {
    if (!allAllowedTabIds().includes(tabId)) return;
    const isMobile = typeof window !== 'undefined'
        && window.matchMedia
        && window.matchMedia('(max-width: 639px)').matches;
    if (isMobile && tabId === 'traducoes') {
        activeTab.value = 'email';
        return;
    }
    activeTab.value = tabId;
}

watch(activeTab, (id) => {
    if (typeof window === 'undefined') return;
    const url = new URL(window.location.href);
    if (url.searchParams.get('tab') === id) return;
    url.searchParams.set('tab', id);
    window.history.replaceState({}, '', url.toString());
});

const translationKeys = computed(() => {
    const t = form.checkout_translations ?? {};
    const keys = new Set([
        ...Object.keys(t.pt_BR ?? {}),
        ...Object.keys(t.en ?? {}),
        ...Object.keys(t.es ?? {}),
    ]);
    return [...keys].sort();
});

const localeLabels = { pt_BR: 'Português (BR)', en: 'English', es: 'Español' };

function ensureTranslationKey(key) {
    if (!form.checkout_translations.pt_BR) form.checkout_translations.pt_BR = {};
    if (!form.checkout_translations.en) form.checkout_translations.en = {};
    if (!form.checkout_translations.es) form.checkout_translations.es = {};
    if (form.checkout_translations.pt_BR[key] === undefined) form.checkout_translations.pt_BR[key] = '';
    if (form.checkout_translations.en[key] === undefined) form.checkout_translations.en[key] = '';
    if (form.checkout_translations.es[key] === undefined) form.checkout_translations.es[key] = '';
}

const CURRENCY_PRESETS = {
    BRL: { symbol: 'R$', label: 'Real brasileiro' },
    USD: { symbol: 'US$', label: 'Dólar americano' },
    EUR: { symbol: '€', label: 'Euro' },
    GBP: { symbol: '£', label: 'Libra esterlina' },
    ARS: { symbol: '$', label: 'Peso argentino' },
    CAD: { symbol: 'C$', label: 'Dólar canadense' },
    CLP: { symbol: '$', label: 'Peso chileno' },
    COP: { symbol: '$', label: 'Peso colombiano' },
    MXN: { symbol: '$', label: 'Peso mexicano' },
    PYG: { symbol: '₲', label: 'Guarani paraguaio' },
    UYU: { symbol: '$', label: 'Peso uruguaio' },
};

const rateModeByIndex = ref({});
const refreshLoadingByIndex = ref({});
const rateFetchError = ref(null);

function getRateMode(index) {
    return rateModeByIndex.value[index] ?? 'brl_to';
}

function setRateMode(index, mode) {
    rateModeByIndex.value = { ...rateModeByIndex.value, [index]: mode };
}

function inverseRate(rate) {
    const r = Number(rate);
    return r > 0 ? (1 / r) : '';
}

function setRateFromInverse(curr, value) {
    const v = parseFloat(String(value).replace(',', '.'));
    curr.rate_to_brl = v > 0 ? 1 / v : 0;
}

function applyPreset(curr) {
    const code = String(curr.code || '').trim().toUpperCase();
    const preset = CURRENCY_PRESETS[code];
    if (preset) {
        if (!curr.symbol) curr.symbol = preset.symbol;
        if (!curr.label) curr.label = preset.label;
    }
}

function onCurrencyCodeChange(curr, index) {
    curr.code = String(curr.code || '').toUpperCase();
    applyPreset(curr);
}

async function fetchRate(curr, index) {
    const code = String(curr.code || '').trim().toUpperCase();
    if (!code || code === 'BRL') return;
    refreshLoadingByIndex.value = { ...refreshLoadingByIndex.value, [index]: true };
    rateFetchError.value = null;
    try {
        const res = await fetch(`https://api.frankfurter.app/latest?from=BRL&to=${code}`);
        const data = await res.json();
        if (data.rates && typeof data.rates[code] === 'number') {
            curr.rate_to_brl = data.rates[code];
        } else {
            rateFetchError.value = 'Moeda não suportada pela API.';
        }
    } catch (e) {
        rateFetchError.value = 'Erro ao buscar taxa. Verifique a conexão.';
    } finally {
        refreshLoadingByIndex.value = { ...refreshLoadingByIndex.value, [index]: false };
    }
}

function canFetchRate(curr) {
    const code = String(curr.code || '').trim().toUpperCase();
    return code && code !== 'BRL';
}

function addCurrency() {
    form.currencies.push({ code: '', symbol: '', label: '', rate_to_brl: 1 });
}

function removeCurrency(index) {
    form.currencies.splice(index, 1);
    const next = { ...rateModeByIndex.value };
    delete next[index];
    rateModeByIndex.value = next;
}

async function testConnection() {
    testForm.clearErrors();
    connectionResult.value.status = null;
    connectionResult.value.message = '';
    connectionTesting.value = true;
    const payload = buildEmailSettingsPayload();
    delete payload.kyc_notification_emails;
    try {
        await window.axios.post('/plataforma/configuracoes/email/connection-test', payload);
        connectionResult.value.status = 'success';
        connectionResult.value.message = 'Conexão estabelecida com sucesso.';
    } catch (e) {
        connectionResult.value.status = 'error';
        let msg = 'Erro ao testar conexão.';
        if (testForm.errors && Object.keys(testForm.errors).length) {
            msg = Object.values(testForm.errors).flat().join(' ');
        } else if (e && e.response && e.response.data && e.response.data.error) {
            msg = e.response.data.error;
        }
        connectionResult.value.message = msg;
    } finally {
        connectionTesting.value = false;
    }
}

async function sendTestEmail() {
    testForm.clearErrors();
    sendTestSending.value = true;
    const payload = { test_to: testForm.test_to, ...buildEmailSettingsPayload() };
    delete payload.kyc_notification_emails;
    try {
        await window.axios.post('/plataforma/configuracoes/email/send-test', payload);
        sendResult.value.status = 'success';
        sendResult.value.message = 'E‑mail de teste enviado com sucesso.';
        setTimeout(() => {
            sendResult.value.status = null;
            sendResult.value.message = '';
        }, 4000);
    } catch (e) {
        sendResult.value.status = 'error';
        let msg = 'Erro ao enviar e‑mail de teste.';
        if (e && e.response && e.response.data && e.response.data.error) {
            msg = e.response.data.error;
        }
        sendResult.value.message = msg;
        setTimeout(() => {
            sendResult.value.status = null;
            sendResult.value.message = '';
        }, 6000);
    } finally {
        sendTestSending.value = false;
    }
}

const storageProviders = [
    { id: 'local', label: 'Local', description: 'Arquivos em storage/app/public (padrão)' },
    { id: 's3', label: 'AWS S3', description: 'Amazon Simple Storage Service', endpoint: '' },
    { id: 'wasabi', label: 'Wasabi', description: 'S3-compatível', endpoint: 'https://s3.wasabisys.com' },
    { id: 'r2', label: 'Cloudflare R2', description: 'S3-compatível sem egress', endpoint: 'https://ACCOUNT_ID.r2.cloudflarestorage.com' },
];

const storageTestResult = vueRef({ status: null, message: '' });
const storageTestLoading = vueRef(false);
const storageMigrateLoading = vueRef(false);

async function testStorageConnection() {
    storageTestResult.value = { status: null, message: '' };
    const provider = form.storage_provider;
    if (provider !== 'local' && !isCloudManagedR2.value) {
        const key = (form.storage_s3_key ?? '').trim();
        const bucket = (form.storage_s3_bucket ?? '').trim();
        if (!key || !bucket) {
            storageTestResult.value = {
                status: 'error',
                message: 'Preencha Access Key e Bucket para testar a conexão. O Secret Key pode ficar em branco se já tiver sido salvo antes.',
            };
            return;
        }
    }
    storageTestLoading.value = true;
    const region =
        provider === 'r2' ? 'auto' : (form.storage_s3_region && form.storage_s3_region.trim()) || 'us-east-1';
    const payload = isCloudManagedR2.value
        ? { storage_provider: 'r2' }
        : {
            storage_provider: provider,
            storage_s3_key: form.storage_s3_key ?? '',
            storage_s3_secret: form.storage_s3_secret ?? '',
            storage_s3_bucket: form.storage_s3_bucket ?? '',
            storage_s3_region: region,
            storage_s3_endpoint: form.storage_s3_endpoint ?? '',
            storage_s3_url: (form.storage_s3_url ?? '').trim(),
        };
    try {
        const res = await window.axios.post('/plataforma/configuracoes/storage/test', payload);
        storageTestResult.value = { status: 'success', message: res.data.message || 'Conexão estabelecida com sucesso.' };
    } catch (e) {
        const data = e?.response?.data;
        const status = e?.response?.status;
        let message = data?.message || data?.error || 'Erro ao testar conexão.';
        if (status === 500) {
            const hint = data?.message || data?.error;
            message = hint
                ? `Erro interno (${status}): ${hint}`
                : `Erro interno (${status}). Rode update.sh, abra /up/storage-check e /plataforma/configuracoes/storage/ping (version deve ser storage-v5-inline-test).`;
        }
        if (data?.errors && typeof data.errors === 'object') {
            const firstError = Object.values(data.errors).flat().find(Boolean);
            if (firstError) message = firstError;
        }
        storageTestResult.value = { status: 'error', message };
    } finally {
        storageTestLoading.value = false;
    }
}

function onStorageProviderChange(providerId) {
    form.storage_provider = providerId;
    showCloudR2Override.value = false;
    const prov = storageProviders.find((p) => p.id === providerId);
    if (prov?.endpoint && !form.storage_s3_endpoint) {
        form.storage_s3_endpoint = prov.endpoint;
    }
    if (providerId === 'r2') {
        form.storage_s3_region = 'auto';
    }
}

const isStorageRemote = computed(
    () =>
        form.storage_provider === 's3' ||
        form.storage_provider === 'wasabi' ||
        form.storage_provider === 'r2',
);

const isCloudManagedR2 = computed(
    () =>
        !!props.cloud_mode &&
        !!props.settings.storage_cloud_r2_managed &&
        form.storage_provider === 'r2' &&
        showCloudR2Override.value === false,
);
const canMigrateStorage = computed(
    () =>
        isStorageRemote.value &&
        (isCloudManagedR2.value ||
            ((form.storage_s3_key ?? '').trim() !== '' &&
                (form.storage_s3_bucket ?? '').trim() !== '')),
);

async function migrateStorageToRemote() {
    storageTestResult.value = { status: null, message: '' };
    storageMigrateLoading.value = true;
    try {
        const res = await window.axios.post('/plataforma/configuracoes/storage/migrate');
        const d = res.data;
        storageTestResult.value = {
            status: 'success',
            message: d.message || `${d.transferred ?? 0} arquivo(s) transferido(s) com sucesso.`,
        };
    } catch (e) {
        const data = e?.response?.data;
        let message = data?.message || data?.error || 'Erro ao transferir arquivos.';
        if (data?.errors && Array.isArray(data.errors) && data.errors[0]?.message) {
            message += ' ' + data.errors[0].message;
        }
        storageTestResult.value = { status: 'error', message };
    } finally {
        storageMigrateLoading.value = false;
    }
}

const providers = [
    {
        id: 'smtp',
        title: 'SMTP',
        logo: '/images/integrations/smtp.svg',
        description: 'Configuração SMTP',
    },
    {
        id: 'hostinger',
        title: 'Hostinger Mail',
        logo: '/images/integrations/hostinger.webp',
        description: 'Configuração Hostinger',
        defaults: { smtp_host: 'smtp.hostinger.com', smtp_port: '465', smtp_encryption: 'ssl' },
    },
    {
        id: 'sendgrid',
        title: 'SendGrid',
        logo: '/images/integrations/twillio-sendgrid.jpg',
        description: 'Envio via API Key SendGrid',
    },
];

const page = usePage();
const sidebarOpen = ref(false);
const selectedProvider = ref(null);

/** Provedor ativo = única fonte de verdade (cartão + envio ao servidor). */
const activeEmailProvider = computed({
    get: () => {
        const v = form.email_provider;
        return v === 'hostinger' || v === 'sendgrid' || v === 'smtp' ? v : 'smtp';
    },
    set: (id) => {
        form.email_provider = id;
    },
});

function applyEmailPublicFieldsFromSettings(s) {
    if (!s || typeof s !== 'object') {
        return;
    }
    const provider = s.email_provider;
    form.email_provider =
        provider === 'hostinger' || provider === 'sendgrid' || provider === 'smtp' ? provider : 'smtp';
    form.smtp_host = s.smtp_host ?? '';
    form.smtp_port = s.smtp_port ?? '587';
    form.smtp_username = s.smtp_username ?? '';
    form.smtp_encryption = s.smtp_encryption ?? 'tls';
    form.mail_from_address = s.mail_from_address ?? '';
    form.mail_from_name = s.mail_from_name ?? '';
    form.reply_to = s.reply_to ?? '';
    form.hostinger_smtp_username = s.hostinger_smtp_username ?? '';
    form.hostinger_mail_from_address = s.hostinger_mail_from_address ?? '';
    form.hostinger_mail_from_name = s.hostinger_mail_from_name ?? '';
    form.hostinger_reply_to = s.hostinger_reply_to ?? '';
    form.sendgrid_mail_from_address = s.sendgrid_mail_from_address ?? '';
    form.sendgrid_mail_from_name = s.sendgrid_mail_from_name ?? '';
    form.kyc_notification_emails = s.kyc_notification_emails ?? '';
}

function syncEmailSettingsFromProps() {
    applyEmailPublicFieldsFromSettings(page.props.settings);
}

function applyLegalSettingsFromSettings(s) {
    if (!s) return;
    form.legal_privacy_policy_html = s.legal_privacy_policy_html ?? '';
    form.legal_terms_of_use_html = s.legal_terms_of_use_html ?? '';
    form.legal_privacy_contact_email = s.legal_privacy_contact_email ?? '';
    form.legal_cookie_banner_enabled = s.legal_cookie_banner_enabled !== false;
}

function syncLegalSettingsFromProps() {
    applyLegalSettingsFromSettings(page.props.settings);
}

function applySecuritySettingsFromSettings(s) {
    if (!s) return;
    form.checkout_turnstile_site_key = s.checkout_turnstile_site_key ?? '';
    form.checkout_turnstile_secret_configured = Boolean(s.checkout_turnstile_secret_configured);
    form.turnstile_keys_configured = Boolean(s.turnstile_keys_configured);
    form.login_turnstile_enabled = s.login_turnstile_enabled ?? '0';
    form.registration_turnstile_enabled = s.registration_turnstile_enabled ?? '0';
    form.registration_email_verification_enabled = s.registration_email_verification_enabled ?? '0';
    form.allow_new_infoproducers = s.allow_new_infoproducers ?? '1';
    form.auto_approve_products = s.auto_approve_products ?? '1';
    form.account_manager_auto_assign_mode = s.account_manager_auto_assign_mode ?? 'least_load';
}

function syncSecuritySettingsFromProps() {
    applySecuritySettingsFromSettings(page.props.settings);
}

function applyKycSettingsFromSettings(s) {
    if (!s) return;
    form.kyc_allowed_identity_types = Array.isArray(s.kyc_allowed_identity_types)
        ? [...s.kyc_allowed_identity_types]
        : ['rg', 'cnh', 'passport'];
    form.kyc_require_address_proof = s.kyc_require_address_proof ?? '1';
    form.kyc_require_selfie_with_document = s.kyc_require_selfie_with_document ?? '1';
    form.kyc_require_company_address_proof = s.kyc_require_company_address_proof ?? '1';
    form.kyc_require_company_constitution = s.kyc_require_company_constitution ?? '1';
}

function syncKycSettingsFromProps() {
    applyKycSettingsFromSettings(page.props.settings);
}

function applySupportSettingsFromSettings(s) {
    if (!s) return;
    form.seller_panel_support_enabled = s.seller_panel_support_enabled ?? '0';
    form.seller_panel_support_destination = s.seller_panel_support_destination ?? 'whatsapp';
    form.seller_panel_support_whatsapp = s.seller_panel_support_whatsapp ?? '';
    form.seller_panel_support_url = s.seller_panel_support_url ?? '';
    form.seller_panel_support_icon = s.seller_panel_support_icon ?? 'whatsapp';
    form.seller_panel_support_icon_image = s.seller_panel_support_icon_image ?? '';
    form.seller_panel_support_color = s.seller_panel_support_color ?? '#25D366';
}

function syncSupportSettingsFromProps() {
    applySupportSettingsFromSettings(page.props.settings);
}

function applyPlatformCompanySettingsFromSettings(s) {
    if (!s) return;
    form.platform_legal_name = s.platform_legal_name ?? '';
    form.platform_cnpj = s.platform_cnpj ?? '';
    form.platform_checkout_notice_enabled = s.platform_checkout_notice_enabled ?? '0';
    form.platform_checkout_notice = s.platform_checkout_notice ?? '';
}

function syncPlatformCompanySettingsFromProps() {
    applyPlatformCompanySettingsFromSettings(page.props.settings);
}

function buildSettingsPayload() {
    const data = form.data();
    if (activeTab.value === 'lgpd') {
        return {
            legal_privacy_policy_html: data.legal_privacy_policy_html,
            legal_terms_of_use_html: data.legal_terms_of_use_html,
            legal_privacy_contact_email: data.legal_privacy_contact_email,
            legal_cookie_banner_enabled: data.legal_cookie_banner_enabled,
        };
    }
    if (activeTab.value === 'backup') {
        return {
            backup_enabled: data.backup_enabled,
            backup_daily_at: data.backup_daily_at,
            backup_retention_days: data.backup_retention_days,
            backup_destination_provider: data.backup_destination_provider,
            backup_destination_s3_key: data.backup_destination_s3_key,
            backup_destination_s3_secret: data.backup_destination_s3_secret,
            backup_destination_s3_bucket: data.backup_destination_s3_bucket,
            backup_destination_s3_region: data.backup_destination_s3_region,
            backup_destination_s3_endpoint: data.backup_destination_s3_endpoint,
            backup_destination_prefix: data.backup_destination_prefix,
        };
    }
    if (activeTab.value === 'recursos') {
        return {
            physical_products_enabled: data.physical_products_enabled,
            auto_approve_products: data.auto_approve_products,
        };
    }
    if (activeTab.value === 'integracoes') {
        return {
            integration_webhook_enabled: data.integration_webhook_enabled,
            integration_utmify_enabled: data.integration_utmify_enabled,
            integration_spedy_enabled: data.integration_spedy_enabled,
            integration_cademi_enabled: data.integration_cademi_enabled,
        };
    }
    if (activeTab.value === 'seguranca') {
        return {
            checkout_turnstile_site_key: data.checkout_turnstile_site_key,
            checkout_turnstile_secret_key: data.checkout_turnstile_secret_key,
            login_turnstile_enabled: data.login_turnstile_enabled,
            registration_turnstile_enabled: data.registration_turnstile_enabled,
            registration_email_verification_enabled: data.registration_email_verification_enabled,
            allow_new_infoproducers: data.allow_new_infoproducers,
            account_manager_auto_assign_mode: data.account_manager_auto_assign_mode,
        };
    }
    if (activeTab.value === 'kyc') {
        return {
            kyc_allowed_identity_types: data.kyc_allowed_identity_types,
            kyc_require_address_proof: data.kyc_require_address_proof,
            kyc_require_selfie_with_document: data.kyc_require_selfie_with_document,
            kyc_require_company_address_proof: data.kyc_require_company_address_proof,
            kyc_require_company_constitution: data.kyc_require_company_constitution,
        };
    }
    if (activeTab.value === 'suporte_painel') {
        return {
            seller_panel_support_enabled: data.seller_panel_support_enabled,
            seller_panel_support_destination: data.seller_panel_support_destination,
            seller_panel_support_whatsapp: data.seller_panel_support_whatsapp,
            seller_panel_support_url: data.seller_panel_support_url,
            seller_panel_support_icon: data.seller_panel_support_icon,
            seller_panel_support_color: data.seller_panel_support_color,
        };
    }
    if (activeTab.value === 'dados_plataforma') {
        return {
            platform_legal_name: data.platform_legal_name,
            platform_cnpj: data.platform_cnpj,
            platform_checkout_notice_enabled: data.platform_checkout_notice_enabled,
            platform_checkout_notice: data.platform_checkout_notice,
        };
    }
    return {
        ...data,
        email_provider: activeEmailProvider.value,
    };
}

function buildEmailSettingsPayload() {
    const provider = activeEmailProvider.value;
    const payload = {
        email_provider: provider,
        kyc_notification_emails: form.kyc_notification_emails ?? '',
    };
    if (provider === 'hostinger') {
        payload.hostinger_smtp_username = form.hostinger_smtp_username ?? '';
        payload.hostinger_smtp_password = form.hostinger_smtp_password ?? '';
        payload.hostinger_mail_from_address = form.hostinger_mail_from_address ?? '';
        payload.hostinger_mail_from_name = form.hostinger_mail_from_name ?? '';
        payload.hostinger_reply_to = form.hostinger_reply_to ?? '';
    } else if (provider === 'sendgrid') {
        payload.sendgrid_api_key = form.sendgrid_api_key ?? '';
        payload.sendgrid_mail_from_address = form.sendgrid_mail_from_address ?? '';
        payload.sendgrid_mail_from_name = form.sendgrid_mail_from_name ?? '';
    } else {
        payload.smtp_host = form.smtp_host ?? '';
        payload.smtp_port = form.smtp_port ?? '587';
        payload.smtp_username = form.smtp_username ?? '';
        payload.smtp_password = form.smtp_password ?? '';
        payload.smtp_encryption = form.smtp_encryption ?? 'tls';
        payload.mail_from_address = form.mail_from_address ?? '';
        payload.mail_from_name = form.mail_from_name ?? '';
        payload.reply_to = form.reply_to ?? '';
    }
    return payload;
}

function payloadTouchesEmailSettings(payload) {
    if (!payload || typeof payload !== 'object') return false;
    const keys = [
        'email_provider',
        'smtp_password', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption',
        'mail_from_address', 'mail_from_name', 'reply_to',
        'hostinger_smtp_password', 'hostinger_smtp_username', 'hostinger_mail_from_address',
        'hostinger_mail_from_name', 'hostinger_reply_to',
        'sendgrid_api_key', 'sendgrid_mail_from_address', 'sendgrid_mail_from_name',
        'kyc_notification_emails',
    ];
    return keys.some((k) => Object.prototype.hasOwnProperty.call(payload, k));
}

const platformTotpEnabled = computed(() => Boolean(page.props.auth?.user?.totp_enabled));
const stepUpOpen = ref(false);
const stepUpLoading = ref(false);
/** @type {import('vue').Ref<null | { kind: 'sidebar' | 'settings' | 'provider', payload: Record<string, unknown> }>} */
const pendingEmailSave = ref(null);

function selectProvider(provider) {
    activeEmailProvider.value = provider.id;
}

function openProviderConfig(provider) {
    selectProvider(provider);
    selectedProvider.value = provider;
    sidebarOpen.value = true;
}

function closeSidebar() {
    sidebarOpen.value = false;
}

function putSettings(payload, { onSuccess } = {}) {
    form
        .transform(() => payload)
        .post('/plataforma/configuracoes', {
            preserveScroll: true,
            onSuccess: () => {
                onSuccess?.();
                syncEmailSettingsFromProps();
                syncLegalSettingsFromProps();
                syncSecuritySettingsFromProps();
                syncKycSettingsFromProps();
                syncSupportSettingsFromProps();
                syncPlatformCompanySettingsFromProps();
            },
            onFinish: () => {
                form.transform((data) => data);
                stepUpLoading.value = false;
                stepUpOpen.value = false;
                pendingEmailSave.value = null;
            },
        });
}

function requestSettingsSave(payload, kind, { onSuccess } = {}) {
    if (platformTotpEnabled.value && payloadTouchesEmailSettings(payload)) {
        pendingEmailSave.value = { kind, payload, onSuccess };
        stepUpOpen.value = true;
        return;
    }
    putSettings(payload, { onSuccess });
}

function saveFromSidebar() {
    requestSettingsSave(buildEmailSettingsPayload(), 'sidebar', {
        onSuccess: () => closeSidebar(),
    });
}

function submitSettings() {
    form.email_provider = activeEmailProvider.value;
    requestSettingsSave(buildSettingsPayload(), 'settings');
}

function persistSelectedProvider() {
    requestSettingsSave(
        {
            email_provider: activeEmailProvider.value,
            kyc_notification_emails: form.kyc_notification_emails ?? '',
        },
        'provider'
    );
}

function onStepUpConfirm({ totp_code: totpCode }) {
    const pending = pendingEmailSave.value;
    if (!pending) {
        stepUpOpen.value = false;
        return;
    }
    stepUpLoading.value = true;
    putSettings(
        { ...pending.payload, totp_code: totpCode || undefined },
        { onSuccess: pending.onSuccess }
    );
}

function closeStepUp() {
    stepUpOpen.value = false;
    stepUpLoading.value = false;
    pendingEmailSave.value = null;
}

function isProviderConfigured(providerId) {
    if (providerId === 'smtp') {
        return !!(form.smtp_host && form.smtp_username);
    }
    if (providerId === 'hostinger') {
        return !!form.hostinger_smtp_username;
    }
    if (providerId === 'sendgrid') {
        return !!form.sendgrid_mail_from_address;
    }
    return false;
}

function copyToClipboard(text) {
    try {
        navigator.clipboard?.writeText(text);
    } catch (_) {}
}

const cronLinuxLine = computed(() => {
    const path = props.base_path && typeof props.base_path === 'string' ? props.base_path : '/caminho/do/projeto';
    return `* * * * * cd ${path} && php artisan schedule:run >> /dev/null 2>&1`;
});

const cronCurlLine = computed(() => {
    if (!props.cron_url) return '';
    return `* * * * * curl -fsS "${props.cron_url}" > /dev/null 2>&1`;
});

const inputClass =
    'block w-full rounded-xl border-2 border-zinc-200 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500';
const selectClass =
    'block w-full rounded-xl border-2 border-zinc-200 bg-white px-4 py-2.5 text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white';
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Configurações</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Gerencie e-mail, aparência, segurança e demais opções da plataforma.
            </p>
        </div>

        <!-- Mobile: seletor de seção -->
        <div class="lg:hidden">
            <label class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                Seção
            </label>
            <select
                :value="activeTab"
                class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                @change="setActiveTab($event.target.value)"
            >
                <optgroup v-for="group in mobileTabsByGroup" :key="group.id" :label="group.label">
                    <option v-for="tab in group.items" :key="tab.id" :value="tab.id">
                        {{ tab.label }}
                    </option>
                </optgroup>
            </select>
        </div>

        <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:gap-8">
            <!-- Sidebar interno (desktop) -->
            <aside
                class="hidden w-56 shrink-0 lg:block xl:w-60"
                aria-label="Navegação das configurações"
            >
                <nav class="sticky top-20 space-y-5">
                    <div v-for="group in tabsByGroup" :key="group.id">
                        <p class="mb-1.5 px-2.5 text-[11px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                            {{ group.label }}
                        </p>
                        <ul class="space-y-0.5">
                            <li v-for="tab in group.items" :key="tab.id">
                                <button
                                    type="button"
                                    :aria-current="activeTab === tab.id ? 'page' : undefined"
                                    class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm font-medium transition-colors"
                                    :class="
                                        activeTab === tab.id
                                            ? 'bg-[var(--color-primary)]/15 text-zinc-900 dark:text-white'
                                            : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800/80'
                                    "
                                    @click="setActiveTab(tab.id)"
                                >
                                    <component
                                        :is="tab.icon"
                                        class="h-4 w-4 shrink-0"
                                        :class="activeTab === tab.id ? 'text-[var(--color-primary)]' : 'text-zinc-400'"
                                        aria-hidden="true"
                                    />
                                    <span class="truncate">{{ tab.label }}</span>
                                </button>
                            </li>
                        </ul>
                    </div>
                </nav>
            </aside>

            <!-- Conteúdo -->
            <div class="min-w-0 flex-1">
                <div v-if="activeTabMeta" class="mb-4 lg:hidden">
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white">{{ activeTabMeta.label }}</h2>
                </div>

        <form
            v-show="activeTab !== 'update' && activeTab !== 'cron' && activeTab !== 'banners_dashboard' && activeTab !== 'template_dashboard' && activeTab !== 'idiomas' && activeTab !== 'demo' && !isPluginTab(activeTab)"
            class="w-full max-w-full space-y-6"
            novalidate
            @submit.prevent="submitSettings"
        >
            <!-- Aba E-MAIL -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="activeTab === 'email'" class="space-y-6">
                    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                        <h2 class="mb-2 text-base font-semibold text-zinc-900 dark:text-white">Provedores de e-mail</h2>
                        <p class="mb-5 text-sm text-zinc-600 dark:text-zinc-400">
                            Escolha o provedor de e-mail para envio de acessos, notificações e recuperação de senha.
                        </p>
                        <div class="grid gap-4 sm:grid-cols-2 md:grid-cols-3">
                            <IntegrationCard
                                v-for="prov in providers"
                                :key="prov.id"
                                :title="prov.title"
                                :logo="prov.logo"
                                :description="prov.description"
                                :selected="prov.id === activeEmailProvider"
                                :configured="isProviderConfigured(prov.id)"
                                @select="selectProvider(prov)"
                                @configure="openProviderConfig(prov)"
                            />
                        </div>
                        <p
                            v-if="form.errors.totp_code || form.errors.email_provider"
                            class="mt-4 text-sm text-red-600 dark:text-red-400"
                        >
                            {{ form.errors.totp_code || form.errors.email_provider }}
                        </p>
                        <div
                            v-if="activeEmailProvider && !isProviderConfigured(activeEmailProvider)"
                            class="mt-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800/50 dark:bg-amber-900/20"
                        >
                            <AlertCircle class="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
                            <p class="text-sm text-amber-800 dark:text-amber-200">
                                Clique no ícone de engrenagem para configurar o provedor selecionado e depois em Salvar.
                            </p>
                        </div>
                        <div class="mt-5 flex flex-wrap items-center gap-3">
                            <Button type="button" size="sm" :disabled="form.processing" @click="persistSelectedProvider">
                                Salvar provedor selecionado
                            </Button>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                A seleção só vale após salvar. Com 2FA ativo, será pedido o código do autenticador.
                            </p>
                        </div>
                    </section>

                    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                        <h2 class="mb-2 text-base font-semibold text-zinc-900 dark:text-white">Alertas por e-mail do operador</h2>
                        <p class="mb-3 text-sm text-zinc-600 dark:text-zinc-400">
                            E-mails que recebem avisos automáticos da plataforma: KYC pendente, falha em saque, erro ao processar payout, nova solicitação de reembolso, etc. Um endereço por linha ou separados por vírgula. Deixe em branco para não enviar alertas.
                        </p>
                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Destinatários</label>
                        <textarea
                            v-model="form.kyc_notification_emails"
                            rows="4"
                            class="block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 font-mono text-sm text-zinc-900 placeholder:text-zinc-400 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                            placeholder="admin@empresa.com&#10;operacoes@empresa.com"
                        />
                    </section>
                </div>
            </Transition>

            <!-- Aba Storage -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="activeTab === 'storage'" class="space-y-6">
                    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                        <div class="border-b border-zinc-200 bg-zinc-50 px-6 py-5 dark:border-zinc-700 dark:bg-zinc-800">
                            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Storage de arquivos</h2>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                Configure onde as imagens da plataforma serão armazenadas (produtos, checkout, área de membros, avatares).
                            </p>
                        </div>
                        <div class="space-y-6 p-6">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Provedor</label>
                                <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-4">
                                    <button
                                        v-for="prov in storageProviders"
                                        :key="prov.id"
                                        type="button"
                                        :class="[
                                            'rounded-xl border-2 p-4 text-left transition',
                                            form.storage_provider === prov.id
                                                ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/5 dark:bg-[var(--color-primary)]/10'
                                                : 'border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 dark:hover:border-zinc-500',
                                        ]"
                                        @click="onStorageProviderChange(prov.id)"
                                    >
                                        <p class="font-medium text-zinc-900 dark:text-white">{{ prov.label }}</p>
                                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ prov.description }}</p>
                                    </button>
                                </div>
                            </div>

                            <div v-if="form.storage_provider !== 'local'" class="space-y-4 rounded-xl border border-zinc-200 bg-zinc-50/50 p-5 dark:border-zinc-600 dark:bg-zinc-800/50">
                                <div
                                    v-if="isCloudManagedR2"
                                    class="flex items-start justify-between gap-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800/50 dark:bg-emerald-900/20"
                                >
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-emerald-900 dark:text-emerald-100">
                                            Parabéns, você está usando o Getfy Cloud com Cloudflare R2.
                                        </p>
                                        <p class="mt-1 text-sm text-emerald-800 dark:text-emerald-200">
                                            As credenciais foram provisionadas automaticamente.
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        class="shrink-0 inline-flex items-center gap-2 rounded-xl border border-emerald-300 bg-white px-4 py-2.5 text-sm font-medium text-emerald-700 transition hover:border-emerald-400 hover:text-emerald-800 dark:border-emerald-700 dark:bg-zinc-800 dark:text-emerald-200 dark:hover:border-emerald-600"
                                        @click="showCloudR2Override = true"
                                    >
                                        Usar minhas credenciais
                                    </button>
                                </div>

                                <template v-else>
                                    <h3 class="text-sm font-medium text-zinc-900 dark:text-white">Credenciais S3</h3>
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Access Key</label>
                                            <input
                                                v-model="form.storage_s3_key"
                                                type="text"
                                                :class="inputClass"
                                                placeholder="AKIA..."
                                                autocomplete="off"
                                            />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Secret Key</label>
                                            <input
                                                v-model="form.storage_s3_secret"
                                                type="password"
                                                :class="inputClass"
                                                placeholder="••••••••"
                                                autocomplete="new-password"
                                            />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Bucket</label>
                                            <input
                                                v-model="form.storage_s3_bucket"
                                                type="text"
                                                :class="inputClass"
                                                placeholder="meu-bucket"
                                            />
                                        </div>
                                        <div v-if="form.storage_provider !== 'r2'">
                                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Region</label>
                                            <input
                                                v-model="form.storage_s3_region"
                                                type="text"
                                                :class="inputClass"
                                                placeholder="us-east-1"
                                            />
                                        </div>
                                        <div class="sm:col-span-2">
                                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Endpoint (R2: https://ACCOUNT_ID.r2.cloudflarestorage.com)</label>
                                            <input
                                                v-model="form.storage_s3_endpoint"
                                                type="text"
                                                :class="inputClass"
                                                placeholder="https://s3.wasabisys.com ou vazio para AWS"
                                            />
                                        </div>
                                        <div class="sm:col-span-2">
                                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                                URL pública
                                                <span v-if="form.storage_provider === 'r2'" class="text-red-600 dark:text-red-400">*</span>
                                                <span v-else class="text-zinc-400">(opcional)</span>
                                            </label>
                                            <input
                                                v-model="form.storage_s3_url"
                                                type="text"
                                                inputmode="url"
                                                :class="inputClass"
                                                :placeholder="form.storage_provider === 'r2'
                                                    ? 'https://pub-xxxx.r2.dev (R2 → bucket → Public access)'
                                                    : 'https://cdn.exemplo.com'"
                                            />
                                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                                <template v-if="form.storage_provider === 'r2'">
                                                    Obrigatório no R2: use a URL <strong>pub-….r2.dev</strong> ou domínio customizado com acesso público.
                                                    Não use o endpoint <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">*.r2.cloudflarestorage.com</code> — ele não abre imagens no site.
                                                </template>
                                                <template v-else>
                                                    CDN ou domínio público do bucket (recomendado para exibir arquivos no navegador).
                                                </template>
                                                Sempre com <strong>https://</strong> (ex.: <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">https://media.seudominio.com</code>).
                                            </p>
                                        </div>
                                    </div>
                                </template>

                                <div class="flex flex-col items-stretch gap-3 pt-2 sm:flex-row sm:items-center">
                                    <button
                                        type="button"
                                        :disabled="storageTestLoading"
                                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm font-medium text-zinc-600 transition hover:border-[var(--color-primary)] hover:text-[var(--color-primary)] disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:border-[var(--color-primary)] sm:w-auto"
                                        @click="testStorageConnection"
                                    >
                                        <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': storageTestLoading }" />
                                        {{ storageTestLoading ? 'Testando...' : 'Testar conexão' }}
                                    </button>
                                    <button
                                        v-if="isStorageRemote"
                                        type="button"
                                        :disabled="storageMigrateLoading || !canMigrateStorage"
                                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm font-medium text-zinc-600 transition hover:border-[var(--color-primary)] hover:text-[var(--color-primary)] disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:border-[var(--color-primary)] sm:w-auto"
                                        title="Salve as configurações antes de transferir."
                                        @click="migrateStorageToRemote"
                                    >
                                        <Upload class="h-4 w-4" :class="{ 'animate-pulse': storageMigrateLoading }" />
                                        {{ storageMigrateLoading ? 'Transferindo...' : 'Transferir arquivos do storage local para o S3/R2' }}
                                    </button>
                                    <p
                                        v-if="storageTestResult.status"
                                        :class="[
                                            'text-sm sm:ml-2',
                                            storageTestResult.status === 'success'
                                                ? 'text-emerald-600 dark:text-emerald-400'
                                                : 'text-red-600 dark:text-red-400',
                                        ]"
                                    >
                                        {{ storageTestResult.message }}
                                    </p>
                                </div>
                            </div>
                            <div v-else class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-600 dark:bg-zinc-800/50">
                                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                                    Os arquivos serão salvos em <code class="rounded bg-zinc-200 px-1 py-0.5 text-xs dark:bg-zinc-700">storage/app/public</code> e servidos via <code class="rounded bg-zinc-200 px-1 py-0.5 text-xs dark:bg-zinc-700">/storage</code>.
                                </p>
                            </div>
                        </div>
                    </section>
                </div>
            </Transition>

            <!-- Aba Backup -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="activeTab === 'backup'" class="space-y-6">
                    <BackupTab
                        :form="form"
                        :files="backup_files"
                        :status="backup_status"
                        :storage="backup_storage"
                    />
                </div>
            </Transition>

            <!-- Aba Traduções -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="activeTab === 'personalizacao'" class="space-y-6">
                    <BrandingTab />
                </div>
            </Transition>

            <!-- Aba Traduções -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="activeTab === 'traducoes'" class="hidden space-y-6 sm:block">
                    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                        <div class="border-b border-zinc-200 bg-zinc-50 px-6 py-5 dark:border-zinc-700 dark:bg-zinc-800">
                            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Checkout – textos por idioma</h2>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                Edite os textos exibidos no checkout. Português (BR), English, Español.
                            </p>
                        </div>
                        <div class="overflow-x-auto p-6 pt-0">
                            <div
                                v-if="translationKeys.length === 0"
                                class="rounded-xl border border-zinc-200 border-dashed bg-zinc-50 px-8 py-12 text-center dark:border-zinc-600 dark:bg-zinc-800/50"
                            >
                                <Languages class="mx-auto h-12 w-12 text-zinc-400 dark:text-zinc-500" />
                                <p class="mt-3 text-sm font-medium text-zinc-600 dark:text-zinc-400">Nenhuma chave de tradução</p>
                                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-500">
                                    As chaves padrão são carregadas automaticamente ao acessar o checkout.
                                </p>
                            </div>
                            <div v-else class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Chave</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Português (BR)</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">English</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Español</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                        <tr
                                            v-for="key in translationKeys"
                                            :key="key"
                                            class="bg-white transition hover:bg-zinc-50 dark:bg-zinc-800/60 dark:hover:bg-zinc-700/80"
                                        >
                                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-zinc-500 dark:text-zinc-400 align-top">{{ key }}</td>
                                            <td class="px-4 py-3 align-top">
                                                <input
                                                    v-model="form.checkout_translations.pt_BR[key]"
                                                    type="text"
                                                    :class="inputClass + ' text-sm py-2'"
                                                    @focus="ensureTranslationKey(key)"
                                                />
                                            </td>
                                            <td class="px-4 py-3 align-top">
                                                <input
                                                    v-model="form.checkout_translations.en[key]"
                                                    type="text"
                                                    :class="inputClass + ' text-sm py-2'"
                                                    @focus="ensureTranslationKey(key)"
                                                />
                                            </td>
                                            <td class="px-4 py-3 align-top">
                                                <input
                                                    v-model="form.checkout_translations.es[key]"
                                                    type="text"
                                                    :class="inputClass + ' text-sm py-2'"
                                                    @focus="ensureTranslationKey(key)"
                                                />
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>
                </div>
            </Transition>

            <!-- Aba Moedas -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="activeTab === 'moedas'" class="space-y-6">
                    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                        <div class="border-b border-zinc-200 bg-zinc-50 px-6 py-5 dark:border-zinc-700 dark:bg-zinc-800">
                            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Moedas disponíveis no checkout</h2>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                Configure as moedas e suas taxas de conversão. Use o botão "Buscar taxa" para atualizar automaticamente.
                            </p>
                        </div>
                        <div v-if="rateFetchError" class="mx-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:border-amber-800/50 dark:bg-amber-900/20 dark:text-amber-200">
                            {{ rateFetchError }}
                        </div>
                        <div class="space-y-4 p-6">
                            <div class="grid gap-4 sm:grid-cols-1 md:grid-cols-2">
                                <div
                                    v-for="(curr, index) in form.currencies"
                                    :key="index"
                                    class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-600 dark:bg-zinc-800/50"
                                >
                                    <div class="flex flex-wrap items-end gap-3">
                                        <div class="w-24 shrink-0">
                                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Código</label>
                                            <input
                                                v-model="curr.code"
                                                type="text"
                                                :class="inputClass"
                                                placeholder="BRL"
                                                maxlength="10"
                                                @blur="onCurrencyCodeChange(curr, index)"
                                            />
                                        </div>
                                        <div class="w-20 shrink-0">
                                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Símbolo</label>
                                            <input
                                                v-model="curr.symbol"
                                                type="text"
                                                :class="inputClass"
                                                placeholder="R$"
                                            />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Nome</label>
                                            <input
                                                v-model="curr.label"
                                                type="text"
                                                :class="inputClass"
                                                placeholder="Real brasileiro"
                                            />
                                        </div>
                                        <button
                                            type="button"
                                            class="ml-auto shrink-0 rounded-lg p-2 text-zinc-500 transition hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-500/20 dark:hover:bg-red-900/20 dark:hover:text-red-400"
                                            :aria-label="'Remover moeda ' + (curr.code || 'sem código')"
                                            @click="removeCurrency(index)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </div>
                                    <div class="space-y-2 border-t border-zinc-200 pt-3 dark:border-zinc-600">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Formato:</span>
                                            <button
                                                type="button"
                                                :class="[
                                                    'rounded-lg px-2.5 py-1 text-xs font-medium transition',
                                                    getRateMode(index) === 'brl_to'
                                                        ? 'bg-[var(--color-primary)] text-white'
                                                        : 'bg-zinc-200 text-zinc-600 hover:bg-zinc-300 dark:bg-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-500',
                                                ]"
                                                @click="setRateMode(index, 'brl_to')"
                                            >
                                                1 BRL = X {{ curr.code || 'moeda' }}
                                            </button>
                                            <button
                                                type="button"
                                                :class="[
                                                    'rounded-lg px-2.5 py-1 text-xs font-medium transition',
                                                    getRateMode(index) === 'foreign_to_brl'
                                                        ? 'bg-[var(--color-primary)] text-white'
                                                        : 'bg-zinc-200 text-zinc-600 hover:bg-zinc-300 dark:bg-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-500',
                                                ]"
                                                @click="setRateMode(index, 'foreign_to_brl')"
                                            >
                                                1 {{ curr.code || 'moeda' }} = X BRL
                                            </button>
                                        </div>
                                        <div class="flex items-end gap-2">
                                            <div class="min-w-0 flex-1">
                                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                                    {{ getRateMode(index) === 'brl_to' ? `1 BRL = X ${curr.code || 'moeda'}` : `1 ${curr.code || 'moeda'} = X BRL` }}
                                                </label>
                                                <input
                                                    v-if="getRateMode(index) === 'brl_to'"
                                                    v-model.number="curr.rate_to_brl"
                                                    type="number"
                                                    step="0.0001"
                                                    min="0"
                                                    :class="inputClass"
                                                    :placeholder="curr.code === 'BRL' ? '1' : '0,18'"
                                                />
                                                <input
                                                    v-else
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    :class="inputClass"
                                                    :placeholder="curr.code === 'BRL' ? '1' : '5,55'"
                                                    :value="inverseRate(curr.rate_to_brl)"
                                                    @input="(e) => setRateFromInverse(curr, e.target.value)"
                                                />
                                            </div>
                                            <button
                                                v-if="canFetchRate(curr)"
                                                type="button"
                                                :disabled="refreshLoadingByIndex[index]"
                                                class="shrink-0 rounded-xl border border-zinc-200 bg-white px-3 py-2.5 text-sm font-medium text-zinc-600 transition hover:border-[var(--color-primary)] hover:text-[var(--color-primary)] disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:border-[var(--color-primary)]"
                                                title="Buscar taxa atual da API Frankfurter"
                                                @click="fetchRate(curr, index)"
                                            >
                                                <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': refreshLoadingByIndex[index] }" />
                                            </button>
                                        </div>
                                        <p v-if="curr.code !== 'BRL' && curr.rate_to_brl > 0" class="text-xs text-zinc-500 dark:text-zinc-450">
                                            Ex.: 1 {{ curr.code }} ≈ {{ (1 / curr.rate_to_brl).toFixed(2) }} BRL
                                        </p>
                                        <p v-else class="text-xs text-zinc-500 dark:text-zinc-450">
                                            Ex: 0,18 = 1 BRL equivale a 0,18 USD (ou 1 USD ≈ 5,55 BRL)
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-dashed border-zinc-300 bg-white px-4 py-3 text-sm font-medium text-zinc-600 transition hover:border-[var(--color-primary)] hover:text-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:hover:border-[var(--color-primary)]"
                                @click="addCurrency"
                            >
                                <Banknote class="h-4 w-4" />
                                + Adicionar moeda
                            </button>
                        </div>
                    </section>
                </div>
            </Transition>

            <!-- Aba Recursos -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="activeTab === 'recursos'" class="space-y-6">
                    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Produto físico e frete</h2>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                            Quando desativado, infoprodutores não veem o tipo produto físico, o menu Taxas e frete nem campos de entrega no checkout.
                        </p>
                        <label class="mt-5 flex cursor-pointer items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-600 dark:bg-zinc-800/80">
                            <input
                                v-model="form.physical_products_enabled"
                                type="checkbox"
                                class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                            />
                            <span>
                                <span class="block text-sm font-medium text-zinc-900 dark:text-white">Habilitar produto físico na plataforma</span>
                                <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                                    Inclui cadastro de lojas/regras de frete, tipo de produto físico e cálculo de frete no checkout.
                                </span>
                            </span>
                        </label>
                    </section>

                    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Aprovação de produtos</h2>
                                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                    Controle se novos produtos de infoprodutores precisam de análise da plataforma antes do checkout ir ao ar.
                                </p>
                            </div>
                            <span
                                class="inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
                                :class="
                                    form.auto_approve_products === '0'
                                        ? 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200'
                                        : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200'
                                "
                            >
                                {{
                                    form.auto_approve_products === '0'
                                        ? 'Análise manual'
                                        : 'Liberação automática'
                                }}
                            </span>
                        </div>
                        <label class="mt-5 flex cursor-pointer items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-600 dark:bg-zinc-800/80">
                            <input
                                type="checkbox"
                                class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                :checked="form.auto_approve_products === '0'"
                                @change="form.auto_approve_products = $event.target.checked ? '0' : '1'"
                            />
                            <span>
                                <span class="block text-sm font-medium text-zinc-900 dark:text-white">Exigir aprovação de novos produtos</span>
                                <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                                    Com esta opção ativa, cada produto novo fica em análise: o infoprodutor pode editar à vontade, mas o link de checkout
                                    <code class="text-[11px]">/c/…</code> só fica online depois da aprovação do admin (e é ativado no mesmo momento).
                                    Se a plataforma rejeitar, o seller vê o motivo e pode reenviar.
                                </span>
                            </span>
                        </label>
                        <ul class="mt-4 space-y-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                            <li>• Fila de análise em <strong class="font-medium text-zinc-700 dark:text-zinc-300">Plataforma → Produtos</strong></li>
                            <li>• Desativada: produtos novos são liberados automaticamente (comportamento padrão)</li>
                        </ul>
                    </section>
                </div>
            </Transition>

            <!-- Aba LGPD -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="activeTab === 'lgpd'" class="space-y-6">
                    <LegalTab :form="form" :legal-defaults="legal_defaults" />
                </div>
            </Transition>

            <!-- Aba Segurança -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="activeTab === 'suporte_painel'" class="space-y-6">
                    <SellerPanelSupportTab :form="form" />
                </div>
            </Transition>

            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="activeTab === 'dados_plataforma'" class="space-y-6">
                    <PlatformDataTab
                        :form="form"
                        :notice-default="settings.platform_checkout_notice_default || ''"
                        :placeholders="settings.platform_checkout_notice_placeholders || []"
                    />
                </div>
            </Transition>

            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="activeTab === 'integracoes'" class="space-y-6">
                    <SellerIntegrationsTab :form="form" :catalog="seller_integrations_catalog" />
                </div>
            </Transition>

            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="activeTab === 'seguranca'" class="space-y-6">
                    <SecurityTab :form="form" />
                </div>
            </Transition>

            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="activeTab === 'kyc'" class="space-y-6">
                    <KycTab :form="form" />
                </div>
            </Transition>

            <div
                class="flex flex-col gap-2 pt-4 sm:pt-2 md:pt-4 sticky bottom-4 z-10 -mx-2 rounded-xl border border-zinc-200 bg-white/95 px-4 py-3 shadow-lg backdrop-blur sm:static sm:mx-0 sm:rounded-none sm:border-0 sm:bg-transparent sm:px-0 sm:py-0 sm:shadow-none dark:border-zinc-700 dark:bg-zinc-800/95 sm:dark:bg-transparent sm:dark:border-0"
            >
                <p
                    v-if="form.errors.totp_code"
                    class="text-sm text-red-600 dark:text-red-400"
                >
                    {{ form.errors.totp_code }}
                </p>
                <p
                    v-else-if="Object.keys(form.errors || {}).length"
                    class="text-sm text-red-600 dark:text-red-400"
                >
                    Não foi possível salvar. Verifique os campos e tente novamente.
                </p>
                <div class="flex items-center gap-3">
                    <Button type="button" :disabled="form.processing" @click="submitSettings">Salvar alterações</Button>
                </div>
            </div>
        </form>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-show="activeTab === 'banners_dashboard'" class="w-full max-w-full space-y-6">
                <DashboardBannersTab />
            </div>
        </Transition>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-show="activeTab === 'template_dashboard'" class="w-full max-w-full space-y-6">
                <DashboardTemplateTab />
            </div>
        </Transition>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-show="activeTab === 'idiomas'" class="w-full max-w-full space-y-6">
                <LanguagesTab />
            </div>
        </Transition>

        <template v-for="pt in settings_plugin_tabs" :key="pt.id">
            <div v-show="activeTab === pt.id" class="w-full max-w-full space-y-6">
                <component :is="getPluginTabComponent(pt.component)" v-if="getPluginTabComponent(pt.component)" />
                <p v-else class="text-sm text-red-600 dark:text-red-400">
                    Componente do plugin não encontrado: {{ pt.component }}
                </p>
            </div>
        </template>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-show="activeTab === 'url_publica'" class="w-full max-w-full space-y-6">
                <PublicUrlTab
                    :public_url="public_url || app_url"
                    :resolved_public_url="resolved_public_url || app_url"
                    :webhook_public_url="webhook_public_url"
                    :public_url_meta="public_url_meta"
                    :container_restart="container_restart"
                    :docker_mode="docker_mode"
                />
            </div>
        </Transition>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-show="activeTab === 'cron'" class="w-full max-w-full space-y-6">
                <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                    <div class="border-b border-zinc-200 bg-zinc-50 px-6 py-5 dark:border-zinc-700 dark:bg-zinc-800">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Cron (agendador)</h2>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                            Importante para o funcionamento geral da plataforma (envios em lote, tarefas automáticas, reconciliação de pagamentos, carrinho abandonado e outros).
                        </p>
                    </div>
                    <div class="space-y-6 p-6">
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                            <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                Aviso importante
                            </p>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                Se você estiver usando o modo Cloud ou instalou via Docker, você não precisa configurar o cron manualmente. Só é necessário configurar em hospedagem compartilhada.
                            </p>
                        </div>
                        <div
                            v-if="cloud_mode || docker_mode"
                            class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800/50 dark:bg-emerald-950/30"
                        >
                            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-200">
                                Modo Cloud / Docker
                            </p>
                            <p class="mt-1 text-sm text-emerald-700 dark:text-emerald-300">
                                Se você estiver usando o modo Cloud ou instalou via Docker, o agendador normalmente já fica configurado automaticamente. Só configure manualmente se estiver em hospedagem compartilhada.
                            </p>
                        </div>
                        <div
                            v-else
                            class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800/50 dark:bg-amber-950/30"
                        >
                            <p class="text-sm font-medium text-amber-800 dark:text-amber-200">
                                Hospedagem compartilhada
                            </p>
                            <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">
                                Se você instalou em hospedagem compartilhada, configure um cron job chamando o agendador a cada minuto para manter as rotinas automáticas funcionando.
                            </p>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/40">
                                <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Cron por URL</h3>
                                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                                    Use em serviços externos (cron-job.org, EasyCron etc.) quando você não tem acesso a SSH/Terminal.
                                </p>

                                <template v-if="cron_url">
                                    <div class="mt-4 flex flex-wrap items-center gap-2">
                                        <code class="break-all rounded-lg bg-zinc-100 px-3 py-2 font-mono text-sm text-zinc-800 dark:bg-zinc-950/60 dark:text-zinc-200">
                                            {{ cron_url }}
                                        </code>
                                        <button
                                            type="button"
                                            class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                            @click="copyToClipboard(cron_url)"
                                        >
                                            Copiar
                                        </button>
                                    </div>
                                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                                        Configure a URL para ser chamada a cada minuto.
                                    </p>
                                </template>
                                <p v-else class="mt-4 text-sm text-zinc-600 dark:text-zinc-400">
                                    Para gerar a URL, defina <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">CRON_SECRET</code> no arquivo .env.
                                </p>
                            </div>

                            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/40">
                                <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Cron no Linux (crontab)</h3>
                                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                                    Se você tem acesso ao servidor, adicione uma linha no <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">crontab -e</code>.
                                </p>
                                <pre class="mt-3 overflow-x-auto rounded-lg bg-zinc-100 p-4 text-left font-mono text-sm text-zinc-800 dark:bg-zinc-950/60 dark:text-zinc-200">{{ cronLinuxLine }}</pre>
                                <template v-if="cron_url">
                                    <p class="mt-4 text-xs font-medium text-zinc-500 dark:text-zinc-400">Alternativa (chamando a URL):</p>
                                    <pre class="mt-2 overflow-x-auto rounded-lg bg-zinc-100 p-4 text-left font-mono text-sm text-zinc-800 dark:bg-zinc-950/60 dark:text-zinc-200">{{ cronCurlLine }}</pre>
                                </template>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </Transition>

        <!-- Aba Versão (somente leitura do arquivo VERSION) -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-show="activeTab === 'update'" class="w-full max-w-full space-y-6">
                <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                    <div class="border-b border-zinc-200 bg-zinc-50 px-6 py-5 dark:border-zinc-700 dark:bg-zinc-800">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Versão instalada</h2>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                            O número exibido é o conteúdo do arquivo <code class="rounded bg-zinc-200 px-1 font-mono text-xs dark:bg-zinc-700">VERSION</code> na raiz do projeto (fallback: configuração interna).
                        </p>
                    </div>
                    <div class="space-y-4 p-6">
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50/80 p-6 dark:border-zinc-600 dark:bg-zinc-900/40">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Versão atual</p>
                            <p class="mt-2 font-mono text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ current_version }}</p>
                        </div>
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">
                            Atualizações de código não são feitas por esta tela. Use o seu fluxo habitual (deploy no servidor, Git, imagem Docker, etc.) e mantenha o arquivo
                            <code class="rounded bg-zinc-200 px-1 font-mono text-xs dark:bg-zinc-700">VERSION</code> alinhado à release instalada.
                        </p>
                    </div>
                </section>
            </div>
        </Transition>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-show="activeTab === 'demo'" class="w-full max-w-full space-y-6">
                <DemoTab />
            </div>
        </Transition>

            </div>
        </div>

        <Teleport to="body">
            <EmailProviderSidebar
                :open="sidebarOpen"
                :provider="selectedProvider"
                :form="form"
                :connection-result="connectionResult"
                :send-result="sendResult"
                :connection-testing="connectionTesting"
                :send-test-sending="sendTestSending"
                @close="closeSidebar"
                @test-connection="testConnection"
                @send-test="(email) => { testForm.test_to = email; sendTestEmail(); }"
                @save="saveFromSidebar"
            />
        </Teleport>

        <PlatformStepUpModal
            :open="stepUpOpen"
            title="Confirmar alteração de e-mail"
            description="Informe o código 2FA para salvar o provedor ou as credenciais de e-mail da plataforma."
            confirm-label="Salvar"
            :loading="stepUpLoading"
            @close="closeStepUp"
            @confirm="onStepUpConfirm"
        />
    </div>
</template>
