<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import GatewayCard from '@/components/settings/GatewayCard.vue';
import GatewayConfigSidebar from '@/components/settings/GatewayConfigSidebar.vue';
import CajuPayAccountSidebar from '@/components/settings/CajuPayAccountSidebar.vue';
import {
    Banknote,
    Barcode,
    CalendarClock,
    CreditCard,
    LayoutGrid,
    Percent,
    QrCode,
    Repeat,
    SlidersHorizontal,
    Smartphone,
    Zap,
} from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';
import FeeFixedInput from '@/components/ui/FeeFixedInput.vue';
import FeePercentInput from '@/components/ui/FeePercentInput.vue';
import Toggle from '@/components/ui/Toggle.vue';
import {
    formatPercentForInput,
    normalizeMerchantFeeRulesForSubmit,
    normalizeCardInstallmentRulesForSubmit,
} from '@/lib/percentDecimal';

const feeMethodRows = [
    { key: 'pix', label: 'PIX' },
    { key: 'api_pix', label: 'API — PIX' },
    { key: 'pixgo', label: 'PixGo' },
    { key: 'open_finance', label: 'Open Finance' },
    { key: 'card', label: 'Cartão' },
    { key: 'apple_pay', label: 'Apple Pay' },
    { key: 'google_pay', label: 'Google Pay' },
    { key: 'boleto', label: 'Boleto' },
    { key: 'withdrawal', label: 'Saque' },
];

const lowTicketMethodRows = feeMethodRows.filter((row) => row.key !== 'withdrawal');

defineOptions({ layout: LayoutPlatform });

const page = usePage();

const props = defineProps({
    gateways: {
        type: Array,
        default: () => [],
    },
    gateway_order: {
        type: Object,
        default: () => ({ pix: [], card: [], boleto: [], pix_auto: [], wallet: [] }),
    },
    merchant_fee_rules: {
        type: Object,
        default: () => ({}),
    },
    low_ticket_fees: {
        type: Object,
        default: () => ({ enabled: false, max_amount: 0, max_order_amount: 0, max_order_amount_enabled: false }),
    },
    card_installment_rules: {
        type: Object,
        default: () => ({}),
    },
    platform_card_installments_enabled: { type: Boolean, default: true },
    platform_card_installments_max: { type: Number, default: 12 },
    merchant_settlement_rules: {
        type: Object,
        default: () => ({}),
    },
    api_pix_enabled: { type: Boolean, default: true },
    pixgo_enabled: { type: Boolean, default: false },
    pixgo_sidebar_label: { type: String, default: 'PixGO' },
    api_pix_minimum_charge_brl: { type: Number, default: 0.01 },
    platform_minimum_charge_brl: { type: Number, default: 0 },
    platform_minimum_withdrawal_brl: { type: Number, default: 0 },
    effective_minimum_withdrawal_brl: { type: Number, default: 0 },
    payout_gateway_min_brl: { type: Number, default: 0 },
    /** @type {'auto'|'cajupay'|'woovi'|'versell'|'xflow'|'okto'|'xpag'|'onlyup'} */
    payout_gateway_preference: { type: String, default: 'auto' },
    /** Slug efetivo usado hoje (pode diferir do preferido se este não estiver conectado). */
    payout_gateway_active: { type: String, default: null },
    gateway_webhook_security_warnings: { type: Array, default: () => [] },
    platform_payment_methods_enabled: {
        type: Object,
        default: () => ({
            pix: true,
            card: true,
            boleto: true,
            pix_auto: true,
            apple_pay: true,
            google_pay: true,
        }),
    },
    platform_payment_method_labels: { type: Array, default: () => [] },
    withdrawal_policy: {
        type: Object,
        default: () => ({
            auto_withdrawal_enabled: true,
            hours_enabled: false,
            hours_start: '06:00',
            hours_end: '21:00',
            timezone: 'America/Sao_Paulo',
            has_manual_approval_pin: false,
        }),
    },
    platform_totp_enabled: { type: Boolean, default: false },
    cajupay_accounts: { type: Array, default: () => [] },
    cajupay_method_accounts: {
        type: Object,
        default: () => ({ pix: null, card: null, boleto: null, pix_auto: null }),
    },
    cajupay_credential_keys: { type: Array, default: () => [] },
});

const cajupayGateway = computed(() => (props.gateways || []).find((g) => g.slug === 'cajupay') ?? null);

const ACQUIRER_GROUPS = [
    {
        id: 'psp',
        title: 'PSP — Provedor de Serviços de Pagamento',
        slugs: ['cajupay', 'efi', 'woovi', 'mercadopago', 'pagarme' /* , 'spacepag' */],
        dotClass: 'bg-emerald-500',
    },
    {
        id: 'acquirer',
        title: 'Adquirente',
        slugs: ['stripe', 'paypal'],
        dotClass: 'bg-blue-500',
    },
    {
        id: 'open_finance',
        title: 'Open Finance',
        slugs: ['linaopenx'],
        dotClass: 'bg-violet-500',
    },
];

const acquirerGroups = computed(() => {
    const list = props.gateways || [];
    const bySlug = Object.fromEntries(list.map((g) => [g.slug, g]));
    const used = new Set();
    const groups = ACQUIRER_GROUPS.map((group) => {
        const items = group.slugs.map((slug) => bySlug[slug]).filter(Boolean);
        items.forEach((g) => used.add(g.slug));
        return { ...group, items };
    });
    const leftover = list.filter((g) => !used.has(g.slug));
    if (leftover.length > 0 && groups[0]) {
        groups[0] = { ...groups[0], items: [...groups[0].items, ...leftover] };
    }
    return groups.filter((g) => g.items.length > 0);
});

const GATEWAYS_API_BASE = '/plataforma/financeiro/gateways';

function getCsrfToken() {
    return typeof document !== 'undefined'
        ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        : '';
}

/** Adquirente principal por método (primeiro da ordem de redundância). */
const primaryPix = ref('');
const primaryCard = ref('');
const primaryBoleto = ref('');
const primaryPixAuto = ref('');
const primaryWallet = ref('');
const savingAcquirerOrder = ref(false);
const acquirerOrderMessage = ref(null);
const acquirerOrderError = ref(false);

function gatewayActiveForPayments(g) {
    return g.is_connected && g.is_enabled !== false;
}

function connectedGatewaysForMethod(method) {
    return (props.gateways || []).filter(
        (g) =>
            gatewayActiveForPayments(g) &&
            Array.isArray(g.methods) &&
            g.methods.includes(method)
    );
}

function connectedCajuAccounts() {
    return (props.cajupay_accounts || []).filter((account) => account.is_connected && account.is_enabled);
}

/**
 * Opções do seletor. Cada conta CajuPay conectada vira uma opção própria.
 * @param {string} method
 * @returns {{ value: string, label: string }[]}
 */
function walletPriorityOptions() {
    const options = [];
    for (const slug of ['cajupay', 'stripe']) {
        const gateway = (props.gateways || []).find(
            (item) => item.slug === slug && gatewayActiveForPayments(item)
        );
        if (!gateway) continue;
        options.push({
            value: slug,
            label: slug === 'stripe' ? 'Stripe' : 'CajuPay',
        });
    }
    return options;
}

function buildWalletOrder(primary) {
    const connected = walletPriorityOptions().map((option) => option.value);
    if (connected.length === 0) return [];
    const first = connected.includes(primary) ? primary : connected[0];
    return [first, ...connected.filter((slug) => slug !== first)];
}

function priorityOptions(method) {
    if (method === 'wallet') {
        return walletPriorityOptions();
    }
    const accounts = connectedCajuAccounts();
    const options = [];
    for (const gateway of connectedGatewaysForMethod(method)) {
        if (gateway.slug !== 'cajupay') {
            options.push({ value: gateway.slug, label: gateway.name });
            continue;
        }
        if (accounts.length === 0) {
            options.push({ value: 'cajupay', label: gateway.name });
            continue;
        }
        for (const account of accounts) {
            const suffix = account.is_default ? ' (padrão)' : '';
            options.push({
                value: `cajupay:${account.id}`,
                label: `${gateway.name} — ${account.name}${suffix}`,
            });
        }
    }
    return options;
}

function slugFromSelection(value) {
    const raw = String(value || '');
    return raw.startsWith('cajupay:') ? 'cajupay' : raw;
}

function accountIdFromSelection(value) {
    const raw = String(value || '');
    if (!raw.startsWith('cajupay:')) return null;
    const id = Number(raw.slice('cajupay:'.length));
    return Number.isInteger(id) && id > 0 ? id : null;
}

function selectionForMethod(method) {
    const options = priorityOptions(method);
    const values = options.map((option) => option.value);
    const first = (props.gateway_order?.[method] || [])[0];
    const savedId = props.cajupay_method_accounts?.[method];
    if (first === 'cajupay' && savedId) {
        const token = `cajupay:${savedId}`;
        if (values.includes(token)) return token;
    }
    if (first && values.includes(first)) return first;
    if (first === 'cajupay') {
        const caju = options.find((option) => String(option.value).startsWith('cajupay'));
        if (caju) return caju.value;
    }
    return values[0] || '';
}

function syncPrimaryFromProps() {
    primaryPix.value = selectionForMethod('pix');
    primaryCard.value = selectionForMethod('card');
    primaryBoleto.value = selectionForMethod('boleto');
    primaryPixAuto.value = selectionForMethod('pix_auto');
    primaryWallet.value = selectionForMethod('wallet');
}

watch(
    () => [props.gateway_order, props.gateways, props.cajupay_accounts, props.cajupay_method_accounts],
    () => syncPrimaryFromProps(),
    { deep: true, immediate: true }
);

/**
 * Monta a lista completa: principal primeiro, depois os demais conectados (redundância).
 * @param {string} method
 * @param {string} primarySlug
 */
function buildGatewayOrderList(method, primarySlug) {
    const prev = (props.gateway_order && props.gateway_order[method]) || [];
    const connected = connectedGatewaysForMethod(method).map((g) => g.slug);
    if (connected.length === 0) {
        return Array.isArray(prev) ? [...prev] : [];
    }
    if (!primarySlug || !connected.includes(primarySlug)) {
        const filtered = prev.filter((s) => connected.includes(s));
        return filtered.length ? filtered : [...connected];
    }
    const rest = [];
    const seen = new Set([primarySlug]);
    for (const s of prev) {
        if (!seen.has(s) && connected.includes(s)) {
            rest.push(s);
            seen.add(s);
        }
    }
    for (const s of connected) {
        if (!seen.has(s)) {
            rest.push(s);
            seen.add(s);
        }
    }
    return [primarySlug, ...rest];
}

async function saveAcquirerOrder() {
    savingAcquirerOrder.value = true;
    acquirerOrderMessage.value = null;
    acquirerOrderError.value = false;
    try {
        const selections = {
            pix: primaryPix.value,
            card: primaryCard.value,
            boleto: primaryBoleto.value,
            pix_auto: primaryPixAuto.value,
            wallet: primaryWallet.value,
        };
        const gateway_order = {
            pix: buildGatewayOrderList('pix', slugFromSelection(selections.pix)),
            card: buildGatewayOrderList('card', slugFromSelection(selections.card)),
            boleto: buildGatewayOrderList('boleto', slugFromSelection(selections.boleto)),
            pix_auto: buildGatewayOrderList('pix_auto', slugFromSelection(selections.pix_auto)),
            wallet: buildWalletOrder(slugFromSelection(selections.wallet)),
        };
        const cajupay_method_accounts = {
            pix: accountIdFromSelection(selections.pix),
            card: accountIdFromSelection(selections.card),
            boleto: accountIdFromSelection(selections.boleto),
            pix_auto: accountIdFromSelection(selections.pix_auto),
        };
        await axios.put(
            `${GATEWAYS_API_BASE.replace(/\/$/, '')}/order`,
            { gateway_order, cajupay_method_accounts },
            { headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' } }
        );
        acquirerOrderMessage.value = 'Preferências de adquirente salvas. O primeiro de cada lista é tentado primeiro na cobrança; os demais servem como redundância.';
        acquirerOrderError.value = false;
        router.reload({ only: ['gateways', 'gateway_order', 'cajupay_method_accounts'] });
    } catch (err) {
        acquirerOrderError.value = true;
        acquirerOrderMessage.value =
            err.response?.data?.message || 'Não foi possível salvar a ordem dos adquirentes.';
    } finally {
        savingAcquirerOrder.value = false;
    }
}

const showPixAutoRow = computed(() =>
    (props.gateways || []).some(
        (g) =>
            gatewayActiveForPayments(g) &&
            Array.isArray(g.methods) &&
            g.methods.includes('pix_auto')
    )
);

const togglingGatewaySlug = ref(null);
const gatewayToggleStepUpOpen = ref(false);
const gatewayToggleStepUpLoading = ref(false);
/** @type {import('vue').Ref<null | { slug: string, isEnabled: boolean }>} */
const pendingGatewayToggle = ref(null);

const platformTotpEnabled = computed(
    () => Boolean(props.platform_totp_enabled) || Boolean(page.props.auth?.user?.totp_enabled)
);

function isTotpStepUpError(err) {
    return Boolean(err?.response?.data?.errors?.totp_code);
}

function totpErrorMessage(err) {
    const parts = err?.response?.data?.errors?.totp_code;
    if (Array.isArray(parts) && parts.length) return String(parts[0]);
    return err?.response?.data?.message || 'Informe o código 2FA para continuar.';
}

async function putGatewayEnabled(slug, isEnabled, totpCode = '') {
    const payload = { is_enabled: isEnabled };
    if (totpCode) payload.totp_code = totpCode;
    await axios.put(`${GATEWAYS_API_BASE}/${slug}/enabled`, payload, {
        headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' },
    });
    router.reload({
        only: [
            'gateways',
            'gateway_order',
            'payout_gateway_preference',
            'payout_gateway_active',
        ],
    });
}

async function toggleGatewayEnabled(gateway, isEnabled) {
    if (platformTotpEnabled.value) {
        pendingGatewayToggle.value = { slug: gateway.slug, isEnabled };
        gatewayToggleStepUpOpen.value = true;
        return;
    }

    togglingGatewaySlug.value = gateway.slug;
    try {
        await putGatewayEnabled(gateway.slug, isEnabled);
    } catch (err) {
        if (isTotpStepUpError(err)) {
            pendingGatewayToggle.value = { slug: gateway.slug, isEnabled };
            gatewayToggleStepUpOpen.value = true;
            return;
        }
        window.alert(
            err.response?.data?.message ||
                'Não foi possível alterar o status do adquirente.'
        );
    } finally {
        togglingGatewaySlug.value = null;
    }
}

async function onGatewayToggleStepUpConfirm({ totp_code: totpCode }) {
    const pending = pendingGatewayToggle.value;
    if (!pending) {
        gatewayToggleStepUpOpen.value = false;
        return;
    }
    gatewayToggleStepUpLoading.value = true;
    togglingGatewaySlug.value = pending.slug;
    try {
        await putGatewayEnabled(pending.slug, pending.isEnabled, totpCode || '');
        gatewayToggleStepUpOpen.value = false;
        pendingGatewayToggle.value = null;
    } catch (err) {
        window.alert(totpErrorMessage(err));
    } finally {
        gatewayToggleStepUpLoading.value = false;
        togglingGatewaySlug.value = null;
    }
}

function closeGatewayToggleStepUp() {
    gatewayToggleStepUpOpen.value = false;
    gatewayToggleStepUpLoading.value = false;
    pendingGatewayToggle.value = null;
}

const payoutPref = ref('auto');
const savingPayoutPref = ref(false);
const payoutPrefMessage = ref(null);
const payoutPrefError = ref(false);

watch(
    () => props.payout_gateway_preference,
    (v) => {
        payoutPref.value =
            v === 'cajupay' || v === 'woovi' || v === 'versell' || v === 'xflow' || v === 'okto' || v === 'xpag' || v === 'onlyup' ? v : 'auto';
    },
    { immediate: true }
);

function gatewayDisplayName(slug) {
    if (!slug) return '—';
    const g = (props.gateways || []).find((x) => x.slug === slug);
    return g?.name || slug;
}

const payoutFallbackActive = computed(() => {
    const p = payoutPref.value;
    const a = props.payout_gateway_active || null;
    if (p === 'auto' || !a) return false;
    return p !== a;
});

async function savePayoutPreference() {
    savingPayoutPref.value = true;
    payoutPrefMessage.value = null;
    payoutPrefError.value = false;
    try {
        const { data } = await axios.put(
            '/plataforma/financeiro/payout-gateway',
            { preference: payoutPref.value },
            { headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' } }
        );
        payoutPrefMessage.value = data?.message || 'Preferência salva.';
        payoutPrefError.value = false;
        router.reload({
            only: ['payout_gateway_preference', 'payout_gateway_active', 'gateways'],
        });
    } catch (err) {
        payoutPrefError.value = true;
        payoutPrefMessage.value =
            err.response?.data?.message || 'Não foi possível salvar a preferência de saque.';
    } finally {
        savingPayoutPref.value = false;
    }
}

function allAllowedTabIds() {
    return ['adquirentes', 'metodos', 'taxas', 'parcelamento', 'limites', 'saques', 'liquidacao', 'pixgo'];
}

const activeTab = ref('adquirentes');
if (typeof window !== 'undefined') {
    const t = new URLSearchParams(window.location.search).get('tab');
    if (t && allAllowedTabIds().includes(t)) activeTab.value = t;
}

const tabs = computed(() => [
    { id: 'adquirentes', label: 'Adquirentes', icon: CreditCard },
    { id: 'metodos', label: 'Formas de pagamento', icon: LayoutGrid },
    { id: 'taxas', label: 'Taxas', icon: Percent },
    { id: 'parcelamento', label: 'Parcelamento', icon: Repeat },
    { id: 'limites', label: 'Limites', icon: SlidersHorizontal },
    { id: 'saques', label: 'Saques', icon: Banknote },
    { id: 'liquidacao', label: 'Liquidação', icon: CalendarClock },
    { id: 'pixgo', label: 'Pix GO', icon: Zap },
]);

const MANUAL_APPROVAL_PIN_MAX_LENGTH = 6;
const MANUAL_APPROVAL_PIN_MIN_LENGTH = 4;

function sanitizeManualApprovalPinInput(value) {
    return String(value ?? '').replace(/\D/g, '').slice(0, MANUAL_APPROVAL_PIN_MAX_LENGTH);
}

function setManualApprovalPinField(field, event) {
    withdrawalPolicyForm[field] = sanitizeManualApprovalPinInput(event.target.value);
}

const withdrawalPolicyForm = useForm({
    auto_withdrawal_enabled: props.withdrawal_policy?.auto_withdrawal_enabled !== false,
    hours_enabled: props.withdrawal_policy?.hours_enabled === true,
    hours_start: props.withdrawal_policy?.hours_start || '06:00',
    hours_end: props.withdrawal_policy?.hours_end || '21:00',
    timezone: props.withdrawal_policy?.timezone || 'America/Sao_Paulo',
    current_manual_approval_pin: '',
    manual_approval_pin: '',
    manual_approval_pin_confirmation: '',
    totp_code: '',
});

const pinStepUpOpen = ref(false);
const pinStepUpLoading = ref(false);
const pinStepUpAction = ref(null);

watch(
    () => props.withdrawal_policy,
    (v) => {
        if (!v) return;
        withdrawalPolicyForm.auto_withdrawal_enabled = v.auto_withdrawal_enabled !== false;
        withdrawalPolicyForm.hours_enabled = v.hours_enabled === true;
        withdrawalPolicyForm.hours_start = v.hours_start || '06:00';
        withdrawalPolicyForm.hours_end = v.hours_end || '21:00';
        withdrawalPolicyForm.timezone = v.timezone || 'America/Sao_Paulo';
    },
    { deep: true }
);

function clearWithdrawalPinFields() {
    withdrawalPolicyForm.current_manual_approval_pin = '';
    withdrawalPolicyForm.manual_approval_pin = '';
    withdrawalPolicyForm.manual_approval_pin_confirmation = '';
    withdrawalPolicyForm.totp_code = '';
}

function isChangingWithdrawalPin() {
    return String(withdrawalPolicyForm.manual_approval_pin || '').trim() !== ''
        || String(withdrawalPolicyForm.manual_approval_pin_confirmation || '').trim() !== '';
}

const pinConfirmationMismatch = computed(() => {
    const pin = String(withdrawalPolicyForm.manual_approval_pin || '').trim();
    const confirm = String(withdrawalPolicyForm.manual_approval_pin_confirmation || '').trim();
    if (confirm === '') {
        return false;
    }

    return pin !== confirm;
});

const pinConfirmationMatches = computed(() => {
    const pin = String(withdrawalPolicyForm.manual_approval_pin || '').trim();
    const confirm = String(withdrawalPolicyForm.manual_approval_pin_confirmation || '').trim();

    return pin !== '' && confirm !== '' && pin === confirm;
});

function validateWithdrawalPinFields() {
    withdrawalPolicyForm.clearErrors('manual_approval_pin', 'manual_approval_pin_confirmation');

    const pin = String(withdrawalPolicyForm.manual_approval_pin || '').trim();
    const confirm = String(withdrawalPolicyForm.manual_approval_pin_confirmation || '').trim();

    if (pin === '' && confirm === '') {
        return true;
    }

    if (pin === '') {
        withdrawalPolicyForm.setError('manual_approval_pin', 'Informe o novo PIN.');
        return false;
    }

    if (pin.length < MANUAL_APPROVAL_PIN_MIN_LENGTH) {
        withdrawalPolicyForm.setError('manual_approval_pin', `O PIN deve ter entre ${MANUAL_APPROVAL_PIN_MIN_LENGTH} e ${MANUAL_APPROVAL_PIN_MAX_LENGTH} dígitos.`);
        return false;
    }

    if (pin.length > MANUAL_APPROVAL_PIN_MAX_LENGTH) {
        withdrawalPolicyForm.setError('manual_approval_pin', `O PIN pode ter no máximo ${MANUAL_APPROVAL_PIN_MAX_LENGTH} dígitos.`);
        return false;
    }

    if (confirm === '') {
        withdrawalPolicyForm.setError('manual_approval_pin_confirmation', 'Confirme o PIN.');
        return false;
    }

    if (pin !== confirm) {
        withdrawalPolicyForm.setError('manual_approval_pin_confirmation', 'A confirmação do PIN não confere.');
        return false;
    }

    return true;
}

function submitWithdrawalPolicy() {
    if (!validateWithdrawalPinFields()) {
        return;
    }

    if (isChangingWithdrawalPin()) {
        pinStepUpAction.value = 'change';
        pinStepUpOpen.value = true;
        return;
    }

    withdrawalPolicyForm.put('/plataforma/financeiro/saques-politica', {
        preserveScroll: true,
    });
}

function openPinResetStepUp() {
    pinStepUpAction.value = 'reset';
    pinStepUpOpen.value = true;
}

function closePinStepUp() {
    pinStepUpOpen.value = false;
    pinStepUpLoading.value = false;
    pinStepUpAction.value = null;
}

function onPinStepUpConfirm(payload) {
    pinStepUpLoading.value = true;

    if (pinStepUpAction.value === 'change') {
        withdrawalPolicyForm.totp_code = payload.totp_code || '';
        withdrawalPolicyForm.put('/plataforma/financeiro/saques-politica', {
            preserveScroll: true,
            onSuccess: () => {
                clearWithdrawalPinFields();
                closePinStepUp();
            },
            onError: () => {
                pinStepUpLoading.value = false;
            },
            onFinish: () => {
                withdrawalPolicyForm.totp_code = '';
            },
        });
        return;
    }

    if (pinStepUpAction.value === 'reset') {
        router.post(
            '/plataforma/financeiro/saques-politica/pin-reset',
            { totp_code: payload.totp_code || '' },
            {
                preserveScroll: true,
                onFinish: () => closePinStepUp(),
            }
        );
    }
}

const paymentMethodsForm = useForm({
    platform_payment_methods_enabled: { ...props.platform_payment_methods_enabled },
});

watch(
    () => props.platform_payment_methods_enabled,
    (v) => {
        paymentMethodsForm.platform_payment_methods_enabled = { ...v };
    },
    { deep: true }
);

function togglePlatformPaymentMethod(key) {
    const cur = paymentMethodsForm.platform_payment_methods_enabled[key];
    paymentMethodsForm.platform_payment_methods_enabled[key] = cur === false ? true : false;
}

function submitPaymentMethods() {
    paymentMethodsForm.put('/plataforma/financeiro/metodos-pagamento', {
        preserveScroll: true,
        onSuccess: () => paymentMethodsForm.clearErrors(),
    });
}

const gatewaySidebarOpen = ref(false);
const selectedGatewaySlug = ref(null);
const pluginLockedModalOpen = ref(false);
const pluginLockedGateway = ref(null);

function openGatewaySidebar(slug) {
    if (slug === 'cajupay') {
        cajupaySidebarOpen.value = true;
        return;
    }
    const g = (props.gateways || []).find((x) => x.slug === slug);
    if (g?.plugin_locked) {
        pluginLockedGateway.value = g;
        pluginLockedModalOpen.value = true;
        return;
    }
    selectedGatewaySlug.value = slug;
    gatewaySidebarOpen.value = true;
}

function closePluginLockedModal() {
    pluginLockedModalOpen.value = false;
    pluginLockedGateway.value = null;
}

const cajupaySidebarOpen = ref(false);

function closeCajuPaySidebar() {
    cajupaySidebarOpen.value = false;
}

function onCajuPayAccountSaved() {
    router.reload({
        only: ['cajupay_accounts', 'gateways', 'gateway_order', 'payout_gateway_preference', 'payout_gateway_active'],
    });
}

function closeGatewaySidebar() {
    gatewaySidebarOpen.value = false;
    selectedGatewaySlug.value = null;
}

function onGatewaySaved() {
    router.reload({
        only: ['gateways', 'gateway_order', 'payout_gateway_preference', 'payout_gateway_active'],
    });
}

function feeBlock(key) {
    const r = props.merchant_fee_rules?.[key] || {};
    const percentRaw = r.percent ?? 0;
    return {
        percent: formatPercentForInput(percentRaw) || '0',
        fixed: r.fixed ?? 0,
    };
}

function lowTicketFeeBlock(key) {
    const r = props.low_ticket_fees?.[key] || {};
    const percentRaw = r.percent ?? 0;
    return {
        percent: formatPercentForInput(percentRaw) || '0',
        fixed: r.fixed ?? 0,
        enabled: r.enabled !== false,
    };
}

function lowTicketOrderCapEnabledFromProps() {
    const fees = props.low_ticket_fees || {};
    if (Object.prototype.hasOwnProperty.call(fees, 'max_order_amount_enabled')) {
        return !!fees.max_order_amount_enabled;
    }
    return Number(fees.max_order_amount ?? 0) > 0;
}

function buildFeeRulesFromProps() {
    const out = {};
    for (const row of feeMethodRows) {
        out[row.key] = feeBlock(row.key);
    }
    return out;
}

function buildLowTicketFeeRulesFromProps() {
    const out = {};
    for (const row of lowTicketMethodRows) {
        out[row.key] = lowTicketFeeBlock(row.key);
    }
    return out;
}

const INSTALLMENT_COUNTS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

function installmentRowFromProps(n) {
    const r = props.card_installment_rules?.[n] || props.card_installment_rules?.[String(n)] || {};
    const card = feeBlock('card');
    const settlementDays = props.merchant_settlement_rules?.card?.days_to_available ?? 0;
    return {
        percent: formatPercentForInput(r.percent ?? card.percent) || '0',
        fixed: r.fixed ?? card.fixed,
        days_to_available: r.days_to_available ?? settlementDays,
    };
}

function buildInstallmentRulesFromProps() {
    const out = {};
    for (const n of INSTALLMENT_COUNTS) {
        out[n] = installmentRowFromProps(n);
    }
    return out;
}

const feePercentRefs = {};
const feeFixedRefs = {};

function setFeePercentRef(key, el) {
    if (el) {
        feePercentRefs[key] = el;
    } else {
        delete feePercentRefs[key];
    }
}

function setFeeFixedRef(key, el) {
    if (el) {
        feeFixedRefs[key] = el;
    } else {
        delete feeFixedRefs[key];
    }
}

function flushFeeInputs() {
    for (const row of feeMethodRows) {
        feePercentRefs[row.key]?.commit?.();
        feeFixedRefs[row.key]?.commit?.();
    }
    for (const row of lowTicketMethodRows) {
        feePercentRefs[`lt-${row.key}`]?.commit?.();
        feeFixedRefs[`lt-${row.key}`]?.commit?.();
    }
    for (const n of INSTALLMENT_COUNTS) {
        feePercentRefs[`inst-${n}`]?.commit?.();
        feeFixedRefs[`inst-${n}`]?.commit?.();
    }
}

function updateFeeField(key, field, value) {
    feeForm.merchant_fee_rules = {
        ...feeForm.merchant_fee_rules,
        [key]: {
            ...feeForm.merchant_fee_rules[key],
            [field]: value,
        },
    };
}

function updateLowTicketFeeField(key, field, value) {
    feeForm.low_ticket_fee_rules = {
        ...feeForm.low_ticket_fee_rules,
        [key]: {
            ...feeForm.low_ticket_fee_rules[key],
            [field]: value,
        },
    };
}

function updateInstallmentField(n, field, value) {
    installmentForm.card_installment_rules = {
        ...installmentForm.card_installment_rules,
        [n]: {
            ...installmentForm.card_installment_rules[n],
            [field]: value,
        },
    };
}

function copyCardFeeToAllInstallments() {
    flushInstallmentInputs();
    const card = feeForm.merchant_fee_rules.card || props.merchant_fee_rules?.card || {};
    const days = Number(props.merchant_settlement_rules?.card?.days_to_available ?? 0);
    const next = {};
    for (const n of INSTALLMENT_COUNTS) {
        next[n] = {
            percent: card.percent ?? 0,
            fixed: card.fixed ?? 0,
            days_to_available: Number.isFinite(days) ? Math.max(0, Math.min(365, days)) : 0,
        };
    }
    installmentForm.card_installment_rules = next;
}

function flushInstallmentInputs() {
    for (const n of INSTALLMENT_COUNTS) {
        feePercentRefs[`inst-${n}`]?.commit?.();
        feeFixedRefs[`inst-${n}`]?.commit?.();
    }
}

const feeForm = useForm({
    merchant_fee_rules: {
        pix: feeBlock('pix'),
        api_pix: feeBlock('api_pix'),
        pixgo: feeBlock('pixgo'),
        open_finance: feeBlock('open_finance'),
        card: feeBlock('card'),
        apple_pay: feeBlock('apple_pay'),
        google_pay: feeBlock('google_pay'),
        boleto: feeBlock('boleto'),
        withdrawal: feeBlock('withdrawal'),
    },
    low_ticket_enabled: !!props.low_ticket_fees?.enabled,
    low_ticket_max_amount: Number(props.low_ticket_fees?.max_amount ?? 0) || 0,
    low_ticket_max_order_amount: Number(props.low_ticket_fees?.max_order_amount ?? 0) || 0,
    low_ticket_max_order_amount_enabled: lowTicketOrderCapEnabledFromProps(),
    low_ticket_fee_rules: buildLowTicketFeeRulesFromProps(),
    api_pix_enabled: props.api_pix_enabled,
});

const PLATFORM_INSTALLMENT_MAX_OPTIONS = [2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

const installmentForm = useForm({
    platform_card_installments_enabled: props.platform_card_installments_enabled !== false,
    platform_card_installments_max: Math.min(12, Math.max(2, Number(props.platform_card_installments_max) || 12)),
    card_installment_rules: buildInstallmentRulesFromProps(),
});

function submitFees() {
    flushFeeInputs();
    feeForm
        .transform((data) => ({
            merchant_fee_rules: normalizeMerchantFeeRulesForSubmit(data.merchant_fee_rules),
            low_ticket_enabled: !!data.low_ticket_enabled,
            low_ticket_max_amount: Number(data.low_ticket_max_amount) || 0,
            low_ticket_max_order_amount: Number(data.low_ticket_max_order_amount) || 0,
            low_ticket_max_order_amount_enabled: !!data.low_ticket_max_order_amount_enabled,
            low_ticket_fee_rules: normalizeMerchantFeeRulesForSubmit(data.low_ticket_fee_rules),
            api_pix_enabled: data.api_pix_enabled,
        }))
        .put('/plataforma/financeiro/taxas', {
            preserveScroll: true,
            onSuccess: async () => {
                feeForm.clearErrors();
                await nextTick();
                feeForm.defaults({
                    merchant_fee_rules: buildFeeRulesFromProps(),
                    low_ticket_enabled: !!props.low_ticket_fees?.enabled,
                    low_ticket_max_amount: Number(props.low_ticket_fees?.max_amount ?? 0) || 0,
                    low_ticket_max_order_amount: Number(props.low_ticket_fees?.max_order_amount ?? 0) || 0,
                    low_ticket_max_order_amount_enabled: lowTicketOrderCapEnabledFromProps(),
                    low_ticket_fee_rules: buildLowTicketFeeRulesFromProps(),
                    api_pix_enabled: props.api_pix_enabled,
                });
                feeForm.reset();
            },
        });
}

function submitInstallments() {
    flushInstallmentInputs();
    installmentForm
        .transform((data) => ({
            platform_card_installments_enabled: data.platform_card_installments_enabled,
            platform_card_installments_max: Math.min(12, Math.max(2, Number(data.platform_card_installments_max) || 12)),
            card_installment_rules: normalizeCardInstallmentRulesForSubmit(data.card_installment_rules),
        }))
        .put('/plataforma/financeiro/parcelamento', {
            preserveScroll: true,
            onSuccess: async () => {
                installmentForm.clearErrors();
                await nextTick();
                installmentForm.defaults({
                    platform_card_installments_enabled: props.platform_card_installments_enabled !== false,
                    platform_card_installments_max: Math.min(12, Math.max(2, Number(props.platform_card_installments_max) || 12)),
                    card_installment_rules: buildInstallmentRulesFromProps(),
                });
                installmentForm.reset();
            },
        });
}

const pixgoForm = useForm({
    pixgo_enabled: props.pixgo_enabled,
    pixgo_sidebar_label: props.pixgo_sidebar_label || 'PixGO',
});

function submitPixGo() {
    pixgoForm.put('/plataforma/financeiro/pixgo', {
        preserveScroll: true,
        onSuccess: () => {
            pixgoForm.defaults({
                pixgo_enabled: props.pixgo_enabled,
                pixgo_sidebar_label: props.pixgo_sidebar_label,
            });
            pixgoForm.reset();
        },
    });
}

const chargeLimitsForm = useForm({
    api_pix_minimum_charge_brl: props.api_pix_minimum_charge_brl ?? 0.01,
    platform_minimum_charge_brl: props.platform_minimum_charge_brl ?? 0,
    platform_minimum_withdrawal_brl: props.platform_minimum_withdrawal_brl ?? 0,
});

function submitChargeLimits() {
    chargeLimitsForm.put('/plataforma/financeiro/limites', {
        preserveScroll: true,
        onSuccess: () => {
            chargeLimitsForm.defaults({
                api_pix_minimum_charge_brl: props.api_pix_minimum_charge_brl,
                platform_minimum_charge_brl: props.platform_minimum_charge_brl,
                platform_minimum_withdrawal_brl: props.platform_minimum_withdrawal_brl,
            });
            chargeLimitsForm.reset();
        },
    });
}

function settlementBlock(key) {
    const r = props.merchant_settlement_rules?.[key] || {};
    return {
        days_to_available: r.days_to_available ?? 0,
        reserve_percent: r.reserve_percent ?? 0,
        reserve_hold_days: r.reserve_hold_days ?? 0,
    };
}

/** Linhas da aba Liquidação (D+N / reserva por canal). */
const settlementMethodRows = [
    { key: 'pix', label: 'PIX' },
    { key: 'open_finance', label: 'Open Finance' },
    { key: 'card', label: 'Cartão' },
    { key: 'apple_pay', label: 'Apple Pay' },
    { key: 'google_pay', label: 'Google Pay' },
    { key: 'boleto', label: 'Boleto' },
];

const settlementForm = useForm({
    merchant_settlement_rules: Object.fromEntries(
        settlementMethodRows.map(({ key }) => [key, settlementBlock(key)])
    ),
});

function submitSettlement() {
    settlementForm.put('/plataforma/financeiro/liquidacao', {
        preserveScroll: true,
        onSuccess: () => settlementForm.clearErrors(),
    });
}

</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Financeiro</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Pagamentos, adquirentes e taxas padrão. Pedidos em Transações; saques em Saques.
            </p>
        </div>

        <p
            v-if="page.props.flash?.success"
            class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200"
        >
            {{ page.props.flash.success }}
        </p>
        <p
            v-if="page.props.flash?.error"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
        >
            {{ page.props.flash.error }}
        </p>
        <p
            v-if="props.gateway_webhook_security_warnings?.length"
            class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
        >
            Segurança: configure o <strong>Webhook Secret</strong> nos gateways conectados
            ({{ props.gateway_webhook_security_warnings.join(', ') }}) — sem isso, notificações de pagamento são rejeitadas.
        </p>

        <div class="w-full overflow-x-auto [-webkit-overflow-scrolling:touch]">
            <nav
                class="inline-flex w-max rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80"
                aria-label="Abas de Financeiro"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    type="button"
                    :aria-current="activeTab === tab.id ? 'page' : undefined"
                    :class="[
                        'flex items-center gap-2 whitespace-nowrap rounded-lg px-4 py-2.5 text-sm font-medium transition-all duration-200',
                        activeTab === tab.id
                            ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
                            : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
                    ]"
                    @click="activeTab = tab.id"
                >
                    <component :is="tab.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ tab.label }}
                </button>
            </nav>
        </div>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-show="activeTab === 'adquirentes'" class="space-y-6">
                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Adquirentes de pagamento
                    </h2>
                    <p class="mb-6 text-sm text-zinc-600 dark:text-zinc-400">
                        Configure os adquirentes usados no checkout e nas APIs. Na cobrança, credenciais globais
                        (definidas aqui) têm prioridade sobre credenciais antigas por tenant.
                    </p>
                    <div class="space-y-8">
                        <div v-for="group in acquirerGroups" :key="group.id">
                            <div class="mb-3 flex items-center gap-2">
                                <span
                                    class="h-2.5 w-2.5 shrink-0 rounded-full"
                                    :class="group.dotClass"
                                    aria-hidden="true"
                                />
                                <h3 class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">
                                    {{ group.title }}
                                </h3>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <GatewayCard
                                    v-for="g in group.items"
                                    :key="g.slug"
                                    :gateway="g"
                                    show-enabled-toggle
                                    :toggling-enabled="togglingGatewaySlug === g.slug"
                                    @click="openGatewaySidebar(g.slug)"
                                    @toggle-enabled="toggleGatewayEnabled(g, $event)"
                                />
                            </div>
                        </div>
                    </div>
                    <div
                        v-if="!props.gateways?.length"
                        class="rounded-xl border border-dashed border-zinc-300 py-8 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400"
                    >
                        Nenhum adquirente disponível.
                    </div>
                </section>

                <section
                    class="overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm ring-1 ring-zinc-950/5 dark:border-zinc-700/80 dark:bg-zinc-900 dark:ring-white/5"
                >
                    <div
                        class="border-b border-zinc-100 bg-gradient-to-br from-zinc-50 via-white to-[var(--color-primary)]/[0.06] px-5 py-5 sm:px-6 dark:border-zinc-700/80 dark:from-zinc-900 dark:via-zinc-900 dark:to-[var(--color-primary)]/[0.08]"
                    >
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex gap-4">
                                <div
                                    class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[var(--color-primary)]/12 text-[var(--color-primary)] shadow-inner dark:bg-[var(--color-primary)]/20"
                                >
                                    <LayoutGrid class="h-6 w-6" stroke-width="1.75" aria-hidden="true" />
                                </div>
                                <div class="min-w-0">
                                    <h2 class="text-base font-semibold tracking-tight text-zinc-900 dark:text-white">
                                        Prioridade por método
                                    </h2>
                                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                                        Defina qual adquirente entra primeiro em cada forma de pagamento. Com mais de
                                        uma conta CajuPay, escolha a conta de cada método — por exemplo, uma no PIX e
                                        outra no cartão. Os demais adquirentes conectados seguem como redundância se o
                                        principal falhar. Vendedor com conta CajuPay fixa continua nessa conta.
                                    </p>
                                </div>
                            </div>
                            <span
                                class="inline-flex shrink-0 items-center rounded-full border border-zinc-200/80 bg-white/80 px-3 py-1 text-xs font-medium text-zinc-600 backdrop-blur-sm dark:border-zinc-600 dark:bg-zinc-800/80 dark:text-zinc-300"
                            >
                                Checkout &amp; API
                            </span>
                        </div>
                    </div>

                    <div class="space-y-5 p-5 sm:p-6">
                        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            <!-- PIX -->
                            <div
                                class="group flex flex-col rounded-2xl border border-zinc-200/90 bg-gradient-to-b from-white to-zinc-50/80 p-4 shadow-sm transition hover:border-emerald-500/25 hover:shadow-md dark:border-zinc-700 dark:from-zinc-900/90 dark:to-zinc-950/50 dark:hover:border-emerald-500/20"
                            >
                                <div class="mb-4 flex items-start justify-between gap-2">
                                    <div class="flex items-center gap-3">
                                        <span
                                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500/12 text-emerald-600 ring-1 ring-emerald-500/20 dark:bg-emerald-400/10 dark:text-emerald-400 dark:ring-emerald-400/15"
                                        >
                                            <QrCode class="h-5 w-5" stroke-width="2" aria-hidden="true" />
                                        </span>
                                        <div>
                                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">PIX à vista</p>
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Principal na cobrança</p>
                                        </div>
                                    </div>
                                </div>
                                <label class="sr-only" for="acq-primary-pix">Adquirente principal PIX</label>
                                <select
                                    id="acq-primary-pix"
                                    v-model="primaryPix"
                                    class="w-full cursor-pointer rounded-xl border border-zinc-200 bg-white px-3.5 py-2.5 text-sm font-medium text-zinc-900 shadow-sm outline-none ring-zinc-950/5 transition focus:border-[var(--color-primary)] focus:ring-2 focus:ring-[var(--color-primary)]/20 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100 dark:ring-white/5"
                                    :disabled="priorityOptions('pix').length === 0"
                                >
                                    <option v-if="priorityOptions('pix').length === 0" value="" disabled>
                                        Nenhum adquirente conectado
                                    </option>
                                    <option
                                        v-for="option in priorityOptions('pix')"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                                <p
                                    v-if="priorityOptions('pix').length === 0"
                                    class="mt-2 text-xs leading-snug text-zinc-500 dark:text-zinc-400"
                                >
                                    Conecte um adquirente com PIX nos cartões acima para habilitar.
                                </p>
                            </div>

                            <!-- Cartão -->
                            <div
                                class="group flex flex-col rounded-2xl border border-zinc-200/90 bg-gradient-to-b from-white to-zinc-50/80 p-4 shadow-sm transition hover:border-indigo-500/25 hover:shadow-md dark:border-zinc-700 dark:from-zinc-900/90 dark:to-zinc-950/50 dark:hover:border-indigo-500/20"
                            >
                                <div class="mb-4 flex items-start justify-between gap-2">
                                    <div class="flex items-center gap-3">
                                        <span
                                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-500/12 text-indigo-600 ring-1 ring-indigo-500/20 dark:bg-indigo-400/10 dark:text-indigo-400 dark:ring-indigo-400/15"
                                        >
                                            <CreditCard class="h-5 w-5" stroke-width="2" aria-hidden="true" />
                                        </span>
                                        <div>
                                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">Cartão</p>
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Crédito ou débito</p>
                                        </div>
                                    </div>
                                </div>
                                <label class="sr-only" for="acq-primary-card">Adquirente principal cartão</label>
                                <select
                                    id="acq-primary-card"
                                    v-model="primaryCard"
                                    class="w-full cursor-pointer rounded-xl border border-zinc-200 bg-white px-3.5 py-2.5 text-sm font-medium text-zinc-900 shadow-sm outline-none transition focus:border-[var(--color-primary)] focus:ring-2 focus:ring-[var(--color-primary)]/20 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100"
                                    :disabled="priorityOptions('card').length === 0"
                                >
                                    <option v-if="priorityOptions('card').length === 0" value="" disabled>
                                        Nenhum adquirente conectado
                                    </option>
                                    <option
                                        v-for="option in priorityOptions('card')"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                                <p
                                    v-if="priorityOptions('card').length === 0"
                                    class="mt-2 text-xs leading-snug text-zinc-500 dark:text-zinc-400"
                                >
                                    Conecte um adquirente com cartão nos cartões acima.
                                </p>
                            </div>

                            <!-- Wallet Apple Pay e Google Pay -->
                            <div
                                class="group flex flex-col rounded-2xl border border-zinc-200/90 bg-gradient-to-b from-white to-zinc-50/80 p-4 shadow-sm transition hover:border-zinc-500/30 hover:shadow-md dark:border-zinc-700 dark:from-zinc-900/90 dark:to-zinc-950/50 dark:hover:border-zinc-400/25"
                            >
                                <div class="mb-4 flex items-start justify-between gap-2">
                                    <div class="flex items-center gap-3">
                                        <span
                                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-zinc-900/8 text-zinc-800 ring-1 ring-zinc-900/10 dark:bg-white/10 dark:text-white dark:ring-white/10"
                                        >
                                            <Smartphone class="h-5 w-5" stroke-width="2" aria-hidden="true" />
                                        </span>
                                        <div>
                                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">Wallet · Apple Pay e Google Pay</p>
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">CajuPay ou Stripe</p>
                                        </div>
                                    </div>
                                </div>
                                <label class="sr-only" for="acq-primary-wallet">Adquirente da carteira</label>
                                <select
                                    id="acq-primary-wallet"
                                    v-model="primaryWallet"
                                    class="w-full cursor-pointer rounded-xl border border-zinc-200 bg-white px-3.5 py-2.5 text-sm font-medium text-zinc-900 shadow-sm outline-none transition focus:border-[var(--color-primary)] focus:ring-2 focus:ring-[var(--color-primary)]/20 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100"
                                    :disabled="priorityOptions('wallet').length === 0"
                                >
                                    <option v-if="priorityOptions('wallet').length === 0" value="" disabled>
                                        Conecte CajuPay ou Stripe
                                    </option>
                                    <option
                                        v-for="option in priorityOptions('wallet')"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                                <p class="mt-2 text-xs leading-snug text-zinc-500 dark:text-zinc-400">
                                    O primeiro entra no checkout. O outro fica como alternativa se o principal não estiver conectado.
                                </p>
                            </div>

                            <!-- Boleto -->
                            <div
                                class="group flex flex-col rounded-2xl border border-zinc-200/90 bg-gradient-to-b from-white to-zinc-50/80 p-4 shadow-sm transition hover:border-amber-500/30 hover:shadow-md dark:border-zinc-700 dark:from-zinc-900/90 dark:to-zinc-950/50 dark:hover:border-amber-500/25 sm:col-span-2 xl:col-span-1"
                            >
                                <div class="mb-4 flex items-start justify-between gap-2">
                                    <div class="flex items-center gap-3">
                                        <span
                                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-500/12 text-amber-700 ring-1 ring-amber-500/20 dark:bg-amber-400/10 dark:text-amber-400 dark:ring-amber-400/15"
                                        >
                                            <Barcode class="h-5 w-5" stroke-width="2" aria-hidden="true" />
                                        </span>
                                        <div>
                                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">Boleto</p>
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Pagamento em banco</p>
                                        </div>
                                    </div>
                                </div>
                                <label class="sr-only" for="acq-primary-boleto">Adquirente principal boleto</label>
                                <select
                                    id="acq-primary-boleto"
                                    v-model="primaryBoleto"
                                    class="w-full cursor-pointer rounded-xl border border-zinc-200 bg-white px-3.5 py-2.5 text-sm font-medium text-zinc-900 shadow-sm outline-none transition focus:border-[var(--color-primary)] focus:ring-2 focus:ring-[var(--color-primary)]/20 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100"
                                    :disabled="priorityOptions('boleto').length === 0"
                                >
                                    <option v-if="priorityOptions('boleto').length === 0" value="" disabled>
                                        Nenhum adquirente conectado
                                    </option>
                                    <option
                                        v-for="option in priorityOptions('boleto')"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                                <p
                                    v-if="priorityOptions('boleto').length === 0"
                                    class="mt-2 text-xs leading-snug text-zinc-500 dark:text-zinc-400"
                                >
                                    Conecte um adquirente com boleto nos cartões acima.
                                </p>
                            </div>
                        </div>

                        <!-- PIX recorrente -->
                        <div
                            v-if="showPixAutoRow"
                            class="rounded-2xl border border-dashed border-violet-300/60 bg-gradient-to-r from-violet-50/80 via-white to-fuchsia-50/40 p-4 dark:border-violet-500/25 dark:from-violet-950/30 dark:via-zinc-900/50 dark:to-fuchsia-950/20 sm:p-5"
                        >
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                                <div class="flex min-w-0 flex-1 items-center gap-3">
                                    <span
                                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-violet-500/15 text-violet-700 ring-1 ring-violet-500/25 dark:bg-violet-400/10 dark:text-violet-300 dark:ring-violet-400/20"
                                    >
                                        <Repeat class="h-5 w-5" stroke-width="2" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">
                                            PIX recorrente · Assinaturas
                                        </p>
                                        <p class="text-xs text-zinc-600 dark:text-zinc-400">
                                            Cobrança automática de mensalidades (quando o adquirente suportar).
                                        </p>
                                    </div>
                                </div>
                                <div class="w-full shrink-0 sm:max-w-xs">
                                    <label class="sr-only" for="acq-primary-pix-auto">Adquirente PIX recorrente</label>
                                    <select
                                        id="acq-primary-pix-auto"
                                        v-model="primaryPixAuto"
                                        class="w-full cursor-pointer rounded-xl border border-violet-200/80 bg-white px-3.5 py-2.5 text-sm font-medium text-zinc-900 shadow-sm outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20 disabled:cursor-not-allowed disabled:opacity-60 dark:border-violet-500/30 dark:bg-zinc-900 dark:text-zinc-100"
                                        :disabled="priorityOptions('pix_auto').length === 0"
                                    >
                                        <option v-if="priorityOptions('pix_auto').length === 0" value="" disabled>
                                            Nenhum conectado
                                        </option>
                                        <option
                                            v-for="option in priorityOptions('pix_auto')"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                            <p
                                v-if="priorityOptions('pix_auto').length === 0"
                                class="mt-3 text-xs text-zinc-600 dark:text-zinc-400"
                            >
                                Conecte um adquirente com PIX automático (ex.: Efí) para assinaturas.
                            </p>
                        </div>

                        <div
                            class="flex flex-col gap-4 border-t border-zinc-100 pt-5 dark:border-zinc-700/80 sm:flex-row sm:items-start sm:gap-6"
                        >
                            <p
                                v-if="acquirerOrderMessage"
                                class="flex-1 rounded-xl px-4 py-3 text-sm leading-relaxed"
                                :class="
                                    acquirerOrderError
                                        ? 'bg-red-50 text-red-800 ring-1 ring-red-200/80 dark:bg-red-950/35 dark:text-red-200 dark:ring-red-900/50'
                                        : 'bg-emerald-50 text-emerald-900 ring-1 ring-emerald-200/80 dark:bg-emerald-950/35 dark:text-emerald-200 dark:ring-emerald-900/40'
                                "
                            >
                                {{ acquirerOrderMessage }}
                            </p>
                            <div class="flex shrink-0 justify-end sm:ml-auto">
                                <Button type="button" :disabled="savingAcquirerOrder" @click="saveAcquirerOrder">
                                    {{ savingAcquirerOrder ? 'Salvando...' : 'Salvar preferências' }}
                                </Button>
                            </div>
                        </div>
                    </div>
                </section>

                <section
                    class="overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm ring-1 ring-zinc-950/5 dark:border-zinc-700/80 dark:bg-zinc-900 dark:ring-white/5"
                >
                    <div
                        class="border-b border-zinc-100 bg-gradient-to-br from-amber-50/90 via-white to-teal-50/40 px-5 py-5 sm:px-6 dark:border-zinc-700/80 dark:from-amber-950/20 dark:via-zinc-900 dark:to-teal-950/15"
                    >
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex gap-4">
                                <div
                                    class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-amber-500/15 text-amber-700 ring-1 ring-amber-500/20 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20"
                                >
                                    <Banknote class="h-6 w-6" stroke-width="1.75" aria-hidden="true" />
                                </div>
                                <div class="min-w-0">
                                    <h2 class="text-base font-semibold tracking-tight text-zinc-900 dark:text-white">
                                        Saque automático (cashout PIX)
                                    </h2>
                                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                                        <strong class="font-medium text-zinc-800 dark:text-zinc-200">CajuPay</strong>,
                                        <strong class="font-medium text-zinc-800 dark:text-zinc-200">Woovi</strong>,
                                        <strong class="font-medium text-zinc-800 dark:text-zinc-200">Versell</strong>,
                                        <strong class="font-medium text-zinc-800 dark:text-zinc-200">Xflow</strong>,
                                        <strong class="font-medium text-zinc-800 dark:text-zinc-200">Okto</strong> e
                                        <strong class="font-medium text-zinc-800 dark:text-zinc-200">XPag</strong> podem ser
                                        usados para saque automático PIX. Em modo automático a ordem é CajuPay →
                                        Woovi → Versell → Xflow → Okto → XPag (o primeiro conectado vence).
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="space-y-4 p-5 sm:p-6">
                        <div class="flex flex-wrap items-center gap-2 text-xs text-zinc-600 dark:text-zinc-400">
                            <span
                                class="inline-flex items-center rounded-full bg-zinc-100 px-2.5 py-1 font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                            >
                                Em uso agora: {{ gatewayDisplayName(payout_gateway_active) }}
                            </span>
                            <span v-if="payoutFallbackActive" class="text-amber-800 dark:text-amber-200">
                                (preferido indisponível — usando fallback)
                            </span>
                        </div>
                        <div class="max-w-md">
                            <label class="mb-1.5 block text-sm font-medium text-zinc-800 dark:text-zinc-200" for="payout-pref-select">
                                Preferência
                            </label>
                            <select
                                id="payout-pref-select"
                                v-model="payoutPref"
                                class="w-full cursor-pointer rounded-xl border border-zinc-200 bg-white px-3.5 py-2.5 text-sm font-medium text-zinc-900 shadow-sm outline-none transition focus:border-[var(--color-primary)] focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100"
                            >
                                <option value="auto">Automático (CajuPay → Woovi → Versell → Xflow → Okto → XPag)</option>
                                <option value="cajupay">Forçar CajuPay</option>
                                <!-- <option value="spacepag">Forçar Spacepag</option> -->
                                <option value="woovi">Forçar Woovi</option>
                                <!-- <option value="bspay">Forçar BSPay</option> -->
                                <option value="versell">Forçar Versell</option>
                                <option value="xflow">Forçar Xflow</option>
                                <option value="okto">Forçar Okto</option>
                                <option value="xpag">Forçar XPag</option>
                                <option value="onlyup">Forçar OnlyUp</option>
                            </select>
                        </div>
                        <p
                            v-if="payoutPrefMessage"
                            class="rounded-xl px-4 py-3 text-sm leading-relaxed"
                            :class="
                                payoutPrefError
                                    ? 'bg-red-50 text-red-800 ring-1 ring-red-200/80 dark:bg-red-950/35 dark:text-red-200'
                                    : 'bg-emerald-50 text-emerald-900 ring-1 ring-emerald-200/80 dark:bg-emerald-950/35 dark:text-emerald-200'
                            "
                        >
                            {{ payoutPrefMessage }}
                        </p>
                        <div class="flex justify-end">
                            <Button type="button" :disabled="savingPayoutPref" @click="savePayoutPreference">
                                {{ savingPayoutPref ? 'Salvando...' : 'Salvar preferência de saque' }}
                            </Button>
                        </div>
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
            <div v-show="activeTab === 'metodos'" class="space-y-6">
                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Formas de pagamento na plataforma
                    </h2>
                    <p class="mb-6 text-sm text-zinc-600 dark:text-zinc-400">
                        Ative ou desative cada forma de pagamento para <strong class="font-medium text-zinc-800 dark:text-zinc-200">toda a plataforma</strong>.
                        Infoprodutores ainda podem restringir por produto no checkout. Apple Pay e Google Pay exigem CajuPay ou Stripe como adquirente definida.
                    </p>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <button
                            v-for="m in props.platform_payment_method_labels"
                            :key="m.key"
                            type="button"
                            class="flex flex-col rounded-xl border-2 px-4 py-4 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]"
                            :class="
                                paymentMethodsForm.platform_payment_methods_enabled[m.key] !== false
                                    ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/[0.08] dark:bg-[var(--color-primary)]/15'
                                    : 'border-zinc-200 bg-zinc-50/50 hover:border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800/40'
                            "
                            @click="togglePlatformPaymentMethod(m.key)"
                        >
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ m.label }}</span>
                            <span class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ m.hint }}</span>
                            <span
                                class="mt-3 inline-flex w-fit rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                                :class="
                                    paymentMethodsForm.platform_payment_methods_enabled[m.key] !== false
                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'
                                        : 'bg-zinc-200 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300'
                                "
                            >
                                {{
                                    paymentMethodsForm.platform_payment_methods_enabled[m.key] !== false
                                        ? 'Ativo na plataforma'
                                        : 'Desativado'
                                }}
                            </span>
                        </button>
                    </div>
                    <p
                        v-if="paymentMethodsForm.errors.platform_payment_methods_enabled"
                        class="mt-4 text-sm text-red-600 dark:text-red-400"
                    >
                        {{ paymentMethodsForm.errors.platform_payment_methods_enabled }}
                    </p>
                    <div class="mt-6 flex justify-end">
                        <Button
                            type="button"
                            :disabled="paymentMethodsForm.processing"
                            @click="submitPaymentMethods"
                        >
                            Salvar formas de pagamento
                        </Button>
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
            <div v-show="activeTab === 'taxas'" class="space-y-6">
                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Taxas padrão (plataforma)
                    </h2>
                    <p class="mb-2 text-sm text-zinc-600 dark:text-zinc-400">
                        Percentual e valor fixo por transação. <strong class="font-medium text-zinc-800 dark:text-zinc-200">PIX / cartão / Apple Pay / Google Pay / boleto</strong> valem para o checkout próprio da plataforma
                        (Apple Pay e Google Pay via CajuPay SDK usam as taxas próprias; se não configuradas, herdam a taxa de <strong class="font-medium">cartão</strong> até você definir valores distintos).
                        <strong class="font-medium text-zinc-800 dark:text-zinc-200">API — PIX</strong> aplica-se só ao PIX criado pela API REST ou pelo link de checkout hospedado gerado pela API (cartão e boleto usam sempre as taxas de checkout).
                        <strong class="font-medium text-zinc-800 dark:text-zinc-200">PixGo</strong> aplica-se às cobranças geradas pelo PixGo (se não configurada, herda a taxa de PIX do checkout).
                        Cada infoprodutor pode sobrescrever em Infoprodutores → editar.
                    </p>
                    <p class="mb-6 text-xs text-zinc-500 dark:text-zinc-400">
                        <strong>Percentual:</strong> valor de 0 a 100 (ex.: <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">2,50</code> = 2,5% sobre o bruto).
                        <strong class="ml-2">Fixo:</strong> valor em <em>reais</em>, não centavos (ex.: <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">1,50</code> = R$ 1,50 por transação).
                    </p>
                    <form class="space-y-6" @submit.prevent="submitFees">
                        <label class="flex items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900/60">
                            <input
                                v-model="feeForm.low_ticket_enabled"
                                type="checkbox"
                                class="mt-0.5 h-4 w-4 rounded border-zinc-300"
                            />
                            <span>
                                <span class="block text-sm font-medium text-zinc-800 dark:text-zinc-100">
                                    Ativar taxa diferente para low tickets
                                </span>
                                <span class="mt-1 block text-xs text-zinc-500 dark:text-zinc-400">
                                    Usa as colunas low ticket quando o produto principal estiver até o teto e o canal estiver ligado neste bloco.
                                    Se o teto de order bumps estiver ativo, a soma com bumps não pode chegar ao pedido máximo.
                                    Pedidos já creditados na carteira não mudam.
                                </span>
                            </span>
                        </label>
                        <div v-if="feeForm.low_ticket_enabled" class="space-y-4">
                            <div class="max-w-sm rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/60">
                                <label class="text-sm font-medium text-zinc-800 dark:text-zinc-200">
                                    Tickets até (R$)
                                </label>
                                <FeeFixedInput
                                    :model-value="feeForm.low_ticket_max_amount"
                                    @update:model-value="(v) => { feeForm.low_ticket_max_amount = v; }"
                                />
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    Produto principal até este valor pode usar a tabela low ticket.
                                </p>
                            </div>
                            <div class="max-w-sm rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/60">
                                <Toggle
                                    v-model="feeForm.low_ticket_max_order_amount_enabled"
                                    label="Ativar teto de order bumps"
                                />
                                <label class="mt-3 block text-sm font-medium text-zinc-800 dark:text-zinc-200">
                                    Teto da soma com order bumps (R$)
                                </label>
                                <FeeFixedInput
                                    :model-value="feeForm.low_ticket_max_order_amount"
                                    :disabled="!feeForm.low_ticket_max_order_amount_enabled"
                                    @update:model-value="(v) => { feeForm.low_ticket_max_order_amount = v; }"
                                />
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    Ligado: se produto + order bumps chegar a este valor, usa a taxa normal.
                                    Desligado: ignora o teto e a oferta low ticket continua valendo mesmo com bumps.
                                </p>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[900px] text-left text-sm">
                                <thead class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-600">
                                    <tr>
                                        <th class="pb-2 pr-4">Canal</th>
                                        <th class="pb-2 pr-4">Percentual (%)</th>
                                        <th class="pb-2 pr-4">Valor fixo</th>
                                        <th class="pb-2 pr-4">Ativar low ticket</th>
                                        <th class="pb-2 pr-4">Low ticket (%)</th>
                                        <th class="pb-2">Low ticket fixo</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                    <tr v-for="row in feeMethodRows" :key="'fee-' + row.key">
                                        <td class="py-3 font-medium text-zinc-900 dark:text-white">{{ row.label }}</td>
                                        <td class="py-3 pr-4">
                                            <FeePercentInput
                                                :ref="(el) => setFeePercentRef(row.key, el)"
                                                :model-value="feeForm.merchant_fee_rules[row.key].percent"
                                                @update:model-value="(v) => updateFeeField(row.key, 'percent', v)"
                                            />
                                        </td>
                                        <td class="py-3 pr-4">
                                            <FeeFixedInput
                                                :ref="(el) => setFeeFixedRef(row.key, el)"
                                                :model-value="feeForm.merchant_fee_rules[row.key].fixed"
                                                @update:model-value="(v) => updateFeeField(row.key, 'fixed', v)"
                                            />
                                        </td>
                                        <td class="py-3 pr-4">
                                            <Toggle
                                                v-if="row.key !== 'withdrawal'"
                                                :model-value="!!feeForm.low_ticket_fee_rules[row.key].enabled"
                                                :disabled="!feeForm.low_ticket_enabled"
                                                @update:model-value="(v) => updateLowTicketFeeField(row.key, 'enabled', v)"
                                            />
                                            <span v-else class="text-xs text-zinc-400">—</span>
                                        </td>
                                        <td class="py-3 pr-4">
                                            <FeePercentInput
                                                v-if="row.key !== 'withdrawal'"
                                                :ref="(el) => setFeePercentRef('lt-' + row.key, el)"
                                                :model-value="feeForm.low_ticket_fee_rules[row.key].percent"
                                                :disabled="!feeForm.low_ticket_enabled || !feeForm.low_ticket_fee_rules[row.key].enabled"
                                                @update:model-value="(v) => updateLowTicketFeeField(row.key, 'percent', v)"
                                            />
                                            <span v-else class="text-xs text-zinc-400">—</span>
                                        </td>
                                        <td class="py-3">
                                            <FeeFixedInput
                                                v-if="row.key !== 'withdrawal'"
                                                :ref="(el) => setFeeFixedRef('lt-' + row.key, el)"
                                                :model-value="feeForm.low_ticket_fee_rules[row.key].fixed"
                                                :disabled="!feeForm.low_ticket_enabled || !feeForm.low_ticket_fee_rules[row.key].enabled"
                                                @update:model-value="(v) => updateLowTicketFeeField(row.key, 'fixed', v)"
                                            />
                                            <span v-else class="text-xs text-zinc-400">—</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-if="feeForm.low_ticket_enabled" class="text-xs text-zinc-500 dark:text-zinc-400">
                            Desmarque o canal em Ativar low ticket para ele não entrar nesta oferta e continuar na taxa normal.
                        </p>

                        <p v-if="feeForm.errors.merchant_fee_rules" class="text-sm text-red-600">
                            {{ feeForm.errors.merchant_fee_rules }}
                        </p>
                        <div class="flex justify-end">
                            <Button type="submit" :disabled="feeForm.processing">Salvar taxas</Button>
                        </div>
                        <div class="mt-3 rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900">
                            <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                <input v-model="feeForm.api_pix_enabled" type="checkbox" class="h-4 w-4 rounded border-zinc-300" />
                                API PIX externa habilitada globalmente
                            </label>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Pode ser sobrescrita por infoprodutor na tela API Pagamentos.</p>
                        </div>
                    </form>
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
            <div v-show="activeTab === 'parcelamento'" class="space-y-6">
                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Parcelamento no cartão
                    </h2>
                    <p class="mb-6 text-sm text-zinc-600 dark:text-zinc-400">
                        Controla se os infoprodutores podem oferecer cartão parcelado e o teto de parcelas da plataforma.
                        As taxas 1x a 12x abaixo continuam valendo para o cálculo de líquido e liquidação — não alteram o checkout à vista.
                    </p>
                    <form class="space-y-6" @submit.prevent="submitInstallments">
                        <label class="flex items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-900/60">
                            <input
                                v-model="installmentForm.platform_card_installments_enabled"
                                type="checkbox"
                                class="mt-0.5 h-4 w-4 rounded border-zinc-300"
                            />
                            <span>
                                <span class="block text-sm font-medium text-zinc-800 dark:text-zinc-100">
                                    Ativar venda parcelada com cartão de crédito
                                </span>
                                <span class="mt-1 block text-xs text-zinc-500 dark:text-zinc-400">
                                    Desativado: nenhum seller oferece parcelamento e o checkout fica só à vista, mesmo se o produto já tiver parcelas configuradas.
                                </span>
                            </span>
                        </label>
                        <div>
                            <label for="platform-installments-max" class="text-sm font-medium text-zinc-800 dark:text-zinc-200">
                                Quantidade máxima de parcelas permitidas
                            </label>
                            <select
                                id="platform-installments-max"
                                v-model.number="installmentForm.platform_card_installments_max"
                                :disabled="!installmentForm.platform_card_installments_enabled"
                                class="mt-1 w-full max-w-xs rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                            >
                                <option v-for="n in PLATFORM_INSTALLMENT_MAX_OPTIONS" :key="'plat-max-' + n" :value="n">
                                    {{ n }} parcelas
                                </option>
                            </select>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                Teto que o seller pode escolher no produto (de 2x até este valor).
                            </p>
                            <p v-if="installmentForm.errors.platform_card_installments_max" class="mt-1 text-sm text-red-600">
                                {{ installmentForm.errors.platform_card_installments_max }}
                            </p>
                        </div>

                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/60">
                            <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">
                                        Cartão por parcelamento (1x a 12x)
                                    </h3>
                                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                        Percentual e valor fixo de cada linha incidem sobre o <strong class="font-medium">bruto total</strong> da venda naquela quantidade de parcelas.
                                        A disponibilidade é o intervalo entre as fatias: numa 12x, o líquido sai em 12 partes; a 1ª libera em D+X, a 2ª em D+2X, e assim por diante.
                                        Com 0 dias, o crédito segue a aba Liquidação (sem calendário por parcela). Apple Pay e Google Pay continuam nas linhas próprias da aba Taxas.
                                    </p>
                                </div>
                                <Button type="button" variant="secondary" @click="copyCardFeeToAllInstallments">
                                    Copiar taxa de Cartão p/ todas
                                </Button>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full min-w-[640px] text-left text-sm">
                                    <thead class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-600">
                                        <tr>
                                            <th class="pb-2 pr-4">Parcelas</th>
                                            <th class="pb-2 pr-4">Percentual (%)</th>
                                            <th class="pb-2 pr-4">Valor fixo</th>
                                            <th class="pb-2">Disponível em (dias)</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                        <tr v-for="n in INSTALLMENT_COUNTS" :key="'inst-fee-' + n">
                                            <td class="py-3 font-medium text-zinc-900 dark:text-white">
                                                {{ n === 1 ? '1x (à vista)' : n + 'x' }}
                                            </td>
                                            <td class="py-3 pr-4">
                                                <FeePercentInput
                                                    :ref="(el) => setFeePercentRef('inst-' + n, el)"
                                                    :model-value="installmentForm.card_installment_rules[n].percent"
                                                    @update:model-value="(v) => updateInstallmentField(n, 'percent', v)"
                                                />
                                            </td>
                                            <td class="py-3 pr-4">
                                                <FeeFixedInput
                                                    :ref="(el) => setFeeFixedRef('inst-' + n, el)"
                                                    :model-value="installmentForm.card_installment_rules[n].fixed"
                                                    @update:model-value="(v) => updateInstallmentField(n, 'fixed', v)"
                                                />
                                            </td>
                                            <td class="py-3">
                                                <input
                                                    v-model.number="installmentForm.card_installment_rules[n].days_to_available"
                                                    type="number"
                                                    min="0"
                                                    max="365"
                                                    step="1"
                                                    class="w-full max-w-[140px] rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-900"
                                                />
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p v-if="installmentForm.errors.card_installment_rules" class="mt-2 text-sm text-red-600">
                                {{ installmentForm.errors.card_installment_rules }}
                            </p>
                        </div>

                        <div class="flex justify-end">
                            <Button type="submit" :disabled="installmentForm.processing">Salvar parcelamento</Button>
                        </div>
                    </form>
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
            <div v-show="activeTab === 'limites'" class="space-y-6">
                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Ticket mínimo de cobrança
                    </h2>
                    <p class="mb-6 text-sm text-zinc-600 dark:text-zinc-400">
                        Defina valores mínimos independentes para a API PIX externa e para o checkout próprio da plataforma
                        (produtos, ofertas, planos e pagamentos no checkout infoprodutor). Valores padrão; cada infoprodutor pode
                        ter taxas, limites e API PIX personalizados em <strong>Infoprodutores → Editar</strong>.
                    </p>
                    <form class="space-y-6" @submit.prevent="submitChargeLimits">
                        <div class="grid gap-6 sm:grid-cols-2">
                            <div>
                                <label class="text-sm font-medium text-zinc-800 dark:text-zinc-200">
                                    Ticket mínimo API PIX (R$)
                                </label>
                                <input
                                    v-model.number="chargeLimitsForm.api_pix_minimum_charge_brl"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                />
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    Aplica a cobranças via API REST e checkout hospedado gerado pela API. Padrão: R$ 0,01.
                                </p>
                                <p v-if="chargeLimitsForm.errors.api_pix_minimum_charge_brl" class="mt-1 text-xs text-red-600">
                                    {{ chargeLimitsForm.errors.api_pix_minimum_charge_brl }}
                                </p>
                            </div>
                            <div>
                                <label class="text-sm font-medium text-zinc-800 dark:text-zinc-200">
                                    Ticket mínimo plataforma (R$)
                                </label>
                                <input
                                    v-model.number="chargeLimitsForm.platform_minimum_charge_brl"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                />
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    Aplica a preços de produtos/ofertas/planos e ao total pago no checkout. Use 0 para sem limite.
                                </p>
                                <p v-if="chargeLimitsForm.errors.platform_minimum_charge_brl" class="mt-1 text-xs text-red-600">
                                    {{ chargeLimitsForm.errors.platform_minimum_charge_brl }}
                                </p>
                            </div>
                        </div>

                        <div class="border-t border-zinc-200 pt-6 dark:border-zinc-700">
                            <h3 class="mb-2 text-sm font-semibold text-zinc-800 dark:text-zinc-200">
                                Saque mínimo global
                            </h3>
                            <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                                Valor líquido mínimo que o infoprodutor precisa atingir no saque.
                                O efetivo é o maior entre este valor e o mínimo do adquirente de payout
                                (campo “Mínimo líquido de payout” nas credenciais — padrão R$&nbsp;7,00).
                                Taxas admin PIX/saque do gateway não entram neste piso.
                            </p>
                            <div class="grid gap-6 sm:grid-cols-2">
                                <div>
                                    <label class="text-sm font-medium text-zinc-800 dark:text-zinc-200">
                                        Saque mínimo da plataforma (R$)
                                    </label>
                                    <input
                                        v-model.number="chargeLimitsForm.platform_minimum_withdrawal_brl"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                    />
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                        Use 0 para não forçar um piso além do adquirente.
                                    </p>
                                    <p v-if="chargeLimitsForm.errors.platform_minimum_withdrawal_brl" class="mt-1 text-xs text-red-600">
                                        {{ chargeLimitsForm.errors.platform_minimum_withdrawal_brl }}
                                    </p>
                                </div>
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-600 dark:bg-zinc-900/60">
                                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                        Mínimo líquido efetivo agora
                                    </p>
                                    <p class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
                                        R$
                                        {{
                                            Number(effective_minimum_withdrawal_brl || 0).toLocaleString('pt-BR', {
                                                minimumFractionDigits: 2,
                                                maximumFractionDigits: 2,
                                            })
                                        }}
                                    </p>
                                    <p class="mt-2 text-xs text-zinc-600 dark:text-zinc-400">
                                        max(
                                        plataforma
                                        R$
                                        {{
                                            Number(platform_minimum_withdrawal_brl || 0).toLocaleString('pt-BR', {
                                                minimumFractionDigits: 2,
                                                maximumFractionDigits: 2,
                                            })
                                        }},
                                        {{ payout_gateway_active || 'adquirente' }}
                                        R$
                                        {{
                                            Number(payout_gateway_min_brl || 0).toLocaleString('pt-BR', {
                                                minimumFractionDigits: 2,
                                                maximumFractionDigits: 2,
                                            })
                                        }}
                                        )
                                    </p>
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                        Após salvar, este valor atualiza. Taxa de saque do merchant (aba Taxas) pode elevar o valor bruto solicitado.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <Button type="submit" :disabled="chargeLimitsForm.processing">Salvar limites</Button>
                        </div>
                    </form>
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
            <div v-show="activeTab === 'saques'" class="space-y-6">
                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Política de saques
                    </h2>
                    <p class="mb-6 text-sm text-zinc-600 dark:text-zinc-400">
                        Controle saque automático, janela de horário para solicitações e PIN de operação.
                        Com 2FA ativo no perfil, o PIN não é exigido nas aprovações. Sem 2FA, o PIN é obrigatório.
                        Pagamento manual nunca é aceito sem 2FA ou PIN cadastrado.
                    </p>
                    <form class="space-y-5" @submit.prevent="submitWithdrawalPolicy">
                        <label class="flex items-center gap-3">
                            <input
                                v-model="withdrawalPolicyForm.auto_withdrawal_enabled"
                                type="checkbox"
                                class="rounded border-zinc-300"
                            />
                            <span class="text-sm text-zinc-800 dark:text-zinc-200">Permitir saque automático após solicitação</span>
                        </label>

                        <label class="flex items-center gap-3">
                            <input
                                v-model="withdrawalPolicyForm.hours_enabled"
                                type="checkbox"
                                class="rounded border-zinc-300"
                            />
                            <span class="text-sm text-zinc-800 dark:text-zinc-200">Restringir horário de solicitação de saque</span>
                        </label>

                        <div v-if="withdrawalPolicyForm.hours_enabled" class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Início</label>
                                <input
                                    v-model="withdrawalPolicyForm.hours_start"
                                    type="time"
                                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                />
                            </div>
                            <div>
                                <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Fim</label>
                                <input
                                    v-model="withdrawalPolicyForm.hours_end"
                                    type="time"
                                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                />
                            </div>
                            <div>
                                <label class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Fuso horário</label>
                                <input
                                    v-model="withdrawalPolicyForm.timezone"
                                    type="text"
                                    class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                />
                            </div>
                        </div>

                        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-600">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200">PIN de operação</p>
                                <button
                                    v-if="withdrawal_policy?.has_manual_approval_pin"
                                    type="button"
                                    class="text-xs font-medium text-zinc-600 underline hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200"
                                    @click="openPinResetStepUp"
                                >
                                    Esqueci o PIN
                                </button>
                            </div>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                Obrigatório para pagar saques quando o 2FA do perfil estiver desligado.
                                Com 2FA ativo, este PIN não é pedido. Use de {{ MANUAL_APPROVAL_PIN_MIN_LENGTH }} a {{ MANUAL_APPROVAL_PIN_MAX_LENGTH }} dígitos numéricos.
                                {{
                                    withdrawal_policy?.has_manual_approval_pin
                                        ? 'Para trocar, informe o PIN atual. Deixe os campos em branco para manter.'
                                        : 'Ainda não definido.'
                                }}
                            </p>
                            <p
                                v-if="!platformTotpEnabled && !withdrawal_policy?.has_manual_approval_pin"
                                class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
                            >
                                Seu 2FA está desligado. Cadastre um PIN aqui para conseguir pagar saques (incluindo pagamento manual).
                            </p>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <input
                                    v-if="withdrawal_policy?.has_manual_approval_pin"
                                    :value="withdrawalPolicyForm.current_manual_approval_pin"
                                    type="password"
                                    inputmode="numeric"
                                    autocomplete="off"
                                    :maxlength="MANUAL_APPROVAL_PIN_MAX_LENGTH"
                                    placeholder="PIN atual"
                                    class="rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 sm:col-span-2"
                                    @input="setManualApprovalPinField('current_manual_approval_pin', $event)"
                                />
                                <input
                                    :value="withdrawalPolicyForm.manual_approval_pin"
                                    type="password"
                                    inputmode="numeric"
                                    autocomplete="new-password"
                                    :maxlength="MANUAL_APPROVAL_PIN_MAX_LENGTH"
                                    placeholder="Novo PIN"
                                    :class="[
                                        'rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900',
                                        withdrawalPolicyForm.errors.manual_approval_pin
                                            ? 'border-red-500 dark:border-red-500'
                                            : 'border-zinc-300 dark:border-zinc-600',
                                    ]"
                                    @input="setManualApprovalPinField('manual_approval_pin', $event)"
                                />
                                <input
                                    :value="withdrawalPolicyForm.manual_approval_pin_confirmation"
                                    type="password"
                                    inputmode="numeric"
                                    autocomplete="new-password"
                                    :maxlength="MANUAL_APPROVAL_PIN_MAX_LENGTH"
                                    placeholder="Confirmar PIN"
                                    :class="[
                                        'rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900',
                                        pinConfirmationMismatch || withdrawalPolicyForm.errors.manual_approval_pin_confirmation
                                            ? 'border-red-500 dark:border-red-500'
                                            : pinConfirmationMatches
                                              ? 'border-emerald-500 dark:border-emerald-500'
                                              : 'border-zinc-300 dark:border-zinc-600',
                                    ]"
                                    @input="setManualApprovalPinField('manual_approval_pin_confirmation', $event)"
                                />
                            </div>
                            <p
                                v-if="pinConfirmationMismatch"
                                class="mt-2 text-xs text-red-600"
                            >
                                A confirmação do PIN não confere.
                            </p>
                            <p
                                v-else-if="pinConfirmationMatches"
                                class="mt-2 text-xs text-emerald-600 dark:text-emerald-400"
                            >
                                PIN e confirmação conferem.
                            </p>
                            <p
                                v-if="withdrawalPolicyForm.errors.current_manual_approval_pin"
                                class="mt-2 text-xs text-red-600"
                            >
                                {{ withdrawalPolicyForm.errors.current_manual_approval_pin }}
                            </p>
                            <p
                                v-if="withdrawalPolicyForm.errors.manual_approval_pin"
                                class="mt-2 text-xs text-red-600"
                            >
                                {{ withdrawalPolicyForm.errors.manual_approval_pin }}
                            </p>
                            <p
                                v-if="withdrawalPolicyForm.errors.manual_approval_pin_confirmation"
                                class="mt-2 text-xs text-red-600"
                            >
                                {{ withdrawalPolicyForm.errors.manual_approval_pin_confirmation }}
                            </p>
                        </div>

                        <Button
                            type="submit"
                            :disabled="withdrawalPolicyForm.processing || pinConfirmationMismatch"
                        >
                            Salvar política de saques
                        </Button>
                    </form>
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
            <div v-show="activeTab === 'liquidacao'" class="space-y-6">
                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        Liquidação e reserva
                    </h2>
                    <p class="mb-6 text-sm text-zinc-600 dark:text-zinc-400">
                        <strong>D+N</strong>: dias até o líquido principal ir para o saldo disponível.
                        <strong>Reserva (%)</strong>: parte do líquido retida no pendente.
                        <strong>Retenção extra da reserva</strong>: dias <em>adicionais</em> (somados ao D+N) só para a parcela de reserva; depois o comando
                        <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">settlement:release</code> (agendado) libera automaticamente para o saldo disponível.
                        Zero em tudo = crédito imediato na carteira disponível (sem reserva).
                    </p>
                    <form class="space-y-6" @submit.prevent="submitSettlement">
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[720px] text-left text-sm">
                                <thead class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-600">
                                    <tr>
                                        <th class="pb-2 pr-4">Canal</th>
                                        <th class="pb-2 pr-4">Dias até disponível (D+N)</th>
                                        <th class="pb-2 pr-4">Reserva (%)</th>
                                        <th class="pb-2">Retenção extra reserva (dias)</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                    <tr v-for="row in settlementMethodRows" :key="'settle-' + row.key">
                                        <td class="py-3 font-medium text-zinc-900 dark:text-white">{{ row.label }}</td>
                                        <td class="py-3 pr-4">
                                            <input
                                                v-model.number="settlementForm.merchant_settlement_rules[row.key].days_to_available"
                                                type="number"
                                                min="0"
                                                max="365"
                                                step="1"
                                                class="w-full max-w-[140px] rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-900"
                                            />
                                        </td>
                                        <td class="py-3 pr-4">
                                            <input
                                                v-model.number="settlementForm.merchant_settlement_rules[row.key].reserve_percent"
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="0.01"
                                                class="w-full max-w-[140px] rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-900"
                                            />
                                        </td>
                                        <td class="py-3">
                                            <input
                                                v-model.number="settlementForm.merchant_settlement_rules[row.key].reserve_hold_days"
                                                type="number"
                                                min="0"
                                                max="365"
                                                step="1"
                                                class="w-full max-w-[140px] rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-900"
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-if="settlementForm.errors.merchant_settlement_rules" class="text-sm text-red-600">
                            {{ settlementForm.errors.merchant_settlement_rules }}
                        </p>
                        <div class="flex justify-end">
                            <Button type="submit" :disabled="settlementForm.processing">Salvar liquidação</Button>
                        </div>
                    </form>
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
            <div v-show="activeTab === 'pixgo'" class="space-y-6">
                <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        PixGO - venda rápida PIX
                    </h2>
                    <p class="mb-6 text-sm text-zinc-600 dark:text-zinc-400">
                        Maquininha virtual no painel do vendedor: digita o valor, gera PIX e aguarda pagamento.
                        Quando desabilitado, o menu e as rotas ficam ocultos para todos os infoprodutores.
                    </p>
                    <form class="space-y-6" @submit.prevent="submitPixGo">
                        <label class="flex items-center gap-3 text-sm text-zinc-700 dark:text-zinc-300">
                            <input
                                v-model="pixgoForm.pixgo_enabled"
                                type="checkbox"
                                class="h-4 w-4 rounded border-zinc-300"
                            />
                            PixGO habilitado globalmente
                        </label>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                Nome no menu lateral
                            </label>
                            <input
                                v-model="pixgoForm.pixgo_sidebar_label"
                                type="text"
                                maxlength="32"
                                placeholder="PixGO"
                                class="w-full max-w-md rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                            />
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                Ex.: PixGO, aparece abaixo de Produtos no painel do vendedor.
                            </p>
                        </div>
                        <div class="flex justify-end">
                            <Button type="submit" :disabled="pixgoForm.processing">Salvar PixGO</Button>
                        </div>
                    </form>
                </section>
            </div>
        </Transition>

        <CajuPayAccountSidebar
            :open="cajupaySidebarOpen"
            :gateway="cajupayGateway"
            :accounts="props.cajupay_accounts"
            :credential-keys-prop="props.cajupay_credential_keys"
            :platform-totp-enabled="platformTotpEnabled"
            @close="closeCajuPaySidebar"
            @saved="onCajuPayAccountSaved"
        />

        <GatewayConfigSidebar
            :open="gatewaySidebarOpen"
            :gateway-slug="selectedGatewaySlug"
            :api-base-path="GATEWAYS_API_BASE"
            :platform-totp-enabled="platformTotpEnabled"
            @close="closeGatewaySidebar"
            @saved="onGatewaySaved"
        />

        <Teleport to="body">
            <div
                v-if="pluginLockedModalOpen"
                class="fixed inset-0 z-[80] flex items-center justify-center p-4"
                role="dialog"
                aria-modal="true"
                aria-labelledby="plugin-locked-title"
            >
                <div class="absolute inset-0 bg-zinc-950/50 backdrop-blur-[2px]" @click="closePluginLockedModal" />
                <div
                    class="relative z-10 w-full max-w-md overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xl dark:border-zinc-700 dark:bg-zinc-900"
                >
                    <div class="border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
                        <h2 id="plugin-locked-title" class="text-base font-semibold text-zinc-900 dark:text-white">
                            {{ pluginLockedGateway?.plugin_locked_title || 'Plugin necessário' }}
                        </h2>
                    </div>
                    <div class="space-y-3 px-5 py-4 text-sm text-zinc-600 dark:text-zinc-300">
                        <p>
                            {{
                                pluginLockedGateway?.plugin_locked_message ||
                                'Este adquirente exige um plugin instalado e ativo. Fale com o suporte para obter o módulo.'
                            }}
                        </p>
                        <p v-if="pluginLockedGateway?.plugin_name" class="text-xs text-zinc-500 dark:text-zinc-400">
                            Plugin: <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ pluginLockedGateway.plugin_name }}</span>
                            <template v-if="pluginLockedGateway.requires_plugin">
                                ({{ pluginLockedGateway.requires_plugin }})
                            </template>
                        </p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            Após a instalação, ative o plugin em
                            <span class="font-medium">Gerenciar plugins</span>
                            e volte aqui para configurar o adquirente.
                        </p>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">
                        <Button type="button" variant="secondary" @click="closePluginLockedModal">
                            Fechar
                        </Button>
                        <Button type="button" @click="router.visit('/plataforma/gerenciar-plugins')">
                            Ver plugins
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>

        <PlatformStepUpModal
            :open="gatewayToggleStepUpOpen"
            :loading="gatewayToggleStepUpLoading"
            :require-totp="true"
            title="Confirmar alteração do adquirente"
            description="Informe o código 2FA para ativar ou desativar este adquirente."
            confirm-label="Confirmar"
            @close="closeGatewayToggleStepUp"
            @confirm="onGatewayToggleStepUpConfirm"
        />

        <PlatformStepUpModal
            :open="pinStepUpOpen"
            :loading="pinStepUpLoading"
            :require-totp="platformTotpEnabled"
            :title="pinStepUpAction === 'reset' ? 'Recuperar PIN' : 'Confirmar troca de PIN'"
            :description="
                pinStepUpAction === 'reset'
                    ? 'Um novo PIN será gerado e enviado aos e-mails administrativos configurados em Configurações > E-mail.'
                    : platformTotpEnabled
                      ? 'Confirme com 2FA para alterar o PIN de aprovação manual.'
                      : 'Confirme para alterar o PIN de aprovação manual.'
            "
            :confirm-label="pinStepUpAction === 'reset' ? 'Enviar por e-mail' : 'Confirmar e salvar'"
            @close="closePinStepUp"
            @confirm="onPinStepUpConfirm"
        />
    </div>
</template>
