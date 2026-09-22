<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import FeeFixedInput from '@/components/ui/FeeFixedInput.vue';
import FeePercentInput from '@/components/ui/FeePercentInput.vue';
import MerchantAdminNotesPanel from '@/components/platform/MerchantAdminNotesPanel.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';
import { ArrowLeft, List } from 'lucide-vue-next';
import { formatPercentForInput, normalizeMerchantFeeOverridesForSubmit, normalizeMerchantSettlementOverridesForSubmit } from '@/lib/percentDecimal';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    merchant: { type: Object, required: true },
    gateways: { type: Array, default: () => [] },
    platform_gateway_order: { type: Object, default: () => ({ pix: [], card: [], boleto: [], pix_auto: [] }) },
    platform_merchant_fees: { type: Array, default: () => [] },
    platform_referral_commission_percent: { type: Number, default: 20 },
    platform_charge_limits: { type: Object, default: () => ({ api_pix_minimum_charge_brl: 0.01, platform_minimum_charge_brl: 0 }) },
    platform_api_pix_enabled: { type: Boolean, default: true },
    platform_integrations_enabled: { type: Object, default: () => ({}) },
    platform_integrations: { type: Array, default: () => [] },
    cajupay_accounts: { type: Array, default: () => [] },
    has_manual_approval_pin: { type: Boolean, default: false },
});

const page = usePage();
const platformTotpEnabled = computed(() => Boolean(page.props.auth?.user?.totp_enabled));
const barrierMissing = computed(() => !platformTotpEnabled.value && !props.has_manual_approval_pin);
const requirePin = computed(() => !platformTotpEnabled.value && props.has_manual_approval_pin);
const resetTotpOpen = ref(false);
const resetTotpLoading = ref(false);

const resetTotpDescription = computed(() => {
    if (barrierMissing.value) {
        return 'Cadastre o 2FA em Meu perfil ou o PIN de operação em Financeiro > Saques para resetar o 2FA deste infoprodutor.';
    }
    if (platformTotpEnabled.value) {
        return 'Informe o código 2FA para desativar o autenticador deste infoprodutor. Após o reset, ele entra só com a senha e pode cadastrar um novo 2FA.';
    }
    return 'Informe o PIN de operação para desativar o autenticador deste infoprodutor. Após o reset, ele entra só com a senha e pode cadastrar um novo 2FA.';
});

function openResetTotp() {
    resetTotpOpen.value = true;
}

function closeResetTotp() {
    if (resetTotpLoading.value) return;
    resetTotpOpen.value = false;
}

function submitResetTotp({ totp_code: totpCode, manual_approval_pin: manualPin }) {
    if (barrierMissing.value || resetTotpLoading.value) return;
    resetTotpLoading.value = true;
    router.post(
        `/plataforma/usuarios/${props.merchant.id}/resetar-2fa`,
        {
            totp_code: totpCode || undefined,
            manual_approval_pin: manualPin || undefined,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                resetTotpLoading.value = false;
                resetTotpOpen.value = false;
            },
        }
    );
}

const feeRows = [
    { key: 'pix', label: 'PIX (checkout)' },
    { key: 'api_pix', label: 'PIX (API)', hint: 'Se não personalizado, acompanha PIX checkout' },
    { key: 'pixgo', label: 'PixGo', hint: 'Se não personalizado, acompanha PIX checkout' },
    { key: 'open_finance', label: 'Open Finance', hint: 'Se não personalizado, acompanha PIX checkout' },
    { key: 'card', label: 'Cartão' },
    { key: 'apple_pay', label: 'Apple Pay', hint: 'Se não personalizado, acompanha Cartão' },
    { key: 'google_pay', label: 'Google Pay', hint: 'Se não personalizado, acompanha Cartão' },
    { key: 'boleto', label: 'Boleto' },
    { key: 'withdrawal', label: 'Saque' },
];
const feeKeys = feeRows.map((row) => row.key);
const settlementRows = [
    { key: 'pix', label: 'PIX' }, { key: 'open_finance', label: 'Open Finance' },
    { key: 'card', label: 'Cartão' }, { key: 'apple_pay', label: 'Apple Pay' },
    { key: 'google_pay', label: 'Google Pay' }, { key: 'boleto', label: 'Boleto' },
];
const gatewayRows = [
    { key: 'pix', label: 'PIX' }, { key: 'card', label: 'Cartão' },
    { key: 'boleto', label: 'Boleto' }, { key: 'pix_auto', label: 'PIX automático' },
];

const savedFees = ref(null);
const feesDirty = ref(false);
const touchedFees = ref({});
const clearAllFees = ref(false);
const settlementDirty = ref(false);
const gatewayDirty = ref(false);
const cajupayDirty = ref(false);
const limitsDirty = ref(false);
const apiPixDirty = ref(false);
const integrationsDirty = ref(false);
const initialGatewayPrimary = ref({});
const feePercentRefs = {};
const feeFixedRefs = {};

function defaultIntegrationModes() {
    const modes = {};
    for (const item of props.platform_integrations) modes[item.id] = 'inherit';
    if (!Object.keys(modes).length) {
        for (const id of ['webhook', 'utmify', 'spedy', 'cademi']) modes[id] = 'inherit';
    }
    return modes;
}
function defaultFees() {
    return Object.fromEntries(feeKeys.map((key) => [key, { percent: '', fixed: '' }]));
}
function defaultSettlement() {
    return Object.fromEntries(settlementRows.map((row) => [row.key, { days_to_available: '', reserve_percent: '', reserve_hold_days: '' }]));
}
function platformFeesMap() {
    const map = Object.fromEntries(feeKeys.map((key) => [key, { percent: 0, fixed: 0 }]));
    for (const row of props.platform_merchant_fees) map[row.key] = { percent: Number(row.percent) || 0, fixed: Number(row.fixed) || 0 };
    return map;
}
function explicitOverride(overrides, key) {
    const block = overrides?.[key];
    return Boolean(block && typeof block === 'object' && ((block.percent !== '' && block.percent != null) || (block.fixed !== '' && block.fixed != null)));
}
function effectiveFees(overrides) {
    const result = platformFeesMap();
    for (const key of feeKeys) {
        const block = overrides?.[key];
        if (!block) continue;
        if (block.percent != null) result[key].percent = Number(block.percent) || 0;
        if (block.fixed != null) result[key].fixed = Number(block.fixed) || 0;
    }
    if (explicitOverride(overrides, 'pix')) {
        for (const key of ['api_pix', 'pixgo', 'open_finance']) if (!explicitOverride(overrides, key)) result[key] = { ...result.pix };
    }
    if (explicitOverride(overrides, 'card')) {
        for (const key of ['apple_pay', 'google_pay']) if (!explicitOverride(overrides, key)) result[key] = { ...result.card };
    }
    return result;
}
function feesFormFromEffective(overrides) {
    const effective = effectiveFees(overrides);
    return Object.fromEntries(feeKeys.map((key) => [key, {
        percent: formatPercentForInput(effective[key].percent) || '0',
        fixed: String(effective[key].fixed ?? 0),
    }]));
}
function mergeSettlement(raw) {
    const result = defaultSettlement();
    for (const row of settlementRows) {
        const block = raw?.[row.key];
        if (!block) continue;
        for (const field of ['days_to_available', 'reserve_percent', 'reserve_hold_days']) {
            if (block[field] != null && block[field] !== '') result[row.key][field] = block[field];
        }
    }
    return result;
}
function formatPhone(value) {
    let digits = String(value ?? '').replace(/\D/g, '');
    if (digits.startsWith('55') && digits.length >= 12) digits = digits.slice(2);
    digits = digits.slice(0, 11);
    if (digits.length <= 2) return digits;
    if (digits.length <= 6) return `(${digits.slice(0, 2)}) ${digits.slice(2)}`;
    if (digits.length <= 10) return `(${digits.slice(0, 2)}) ${digits.slice(2, 6)}-${digits.slice(6)}`;
    return `(${digits.slice(0, 2)}) ${digits.slice(2, 7)}-${digits.slice(7)}`;
}
function formatBlockUntil(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const pad = (number) => String(number).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

const form = useForm({
    name: '', email: '', phone: '', password: '', password_confirmation: '', account_status: 'approved',
    admin_withdrawal_blocked: false, admin_blocked_amount: '', admin_block_until: '', admin_block_note: '',
    merchant_fees: defaultFees(), merchant_settlement_overrides: defaultSettlement(),
    referral_commission_percent: '', api_pix_mode: 'inherit', integration_modes: defaultIntegrationModes(), med_zero_enabled: false,
    api_pix_minimum_charge_brl: '', platform_minimum_charge_brl: '',
    use_platform_api_pix_minimum: true, use_platform_platform_minimum: true, cajupay_account_id: '',
});
const gatewayPrimary = reactive({ pix: '', card: '', boleto: '', pix_auto: '' });

function gatewaysFor(method) {
    return props.gateways.filter((gateway) => Array.isArray(gateway.methods) && gateway.methods.includes(method));
}
function syncGatewayPrimary() {
    for (const method of Object.keys(gatewayPrimary)) {
        const available = gatewaysFor(method).map((gateway) => gateway.slug);
        const current = props.merchant.merchant_gateway_order?.[method];
        gatewayPrimary[method] = Array.isArray(current) ? (current.find((slug) => available.includes(slug)) || '') : '';
    }
    initialGatewayPrimary.value = { ...gatewayPrimary };
}
watch(gatewayPrimary, (current) => {
    gatewayDirty.value = Object.keys(current).some((method) => (current[method] || '') !== (initialGatewayPrimary.value[method] || ''));
}, { deep: true });

function initialize() {
    const merchant = props.merchant;
    const wallet = merchant.wallet_admin;
    const limits = merchant.charge_limits || {};
    savedFees.value = merchant.merchant_fees ?? null;
    form.defaults({
        name: merchant.name, email: merchant.email, phone: formatPhone(merchant.phone), password: '', password_confirmation: '',
        account_status: merchant.account_status || 'approved',
        admin_withdrawal_blocked: Boolean(wallet?.admin_withdrawal_blocked),
        admin_blocked_amount: wallet?.admin_blocked_amount != null ? String(wallet.admin_blocked_amount) : '',
        admin_block_until: formatBlockUntil(wallet?.admin_block_until), admin_block_note: wallet?.admin_block_note || '',
        merchant_fees: feesFormFromEffective(merchant.merchant_fees),
        merchant_settlement_overrides: mergeSettlement(merchant.merchant_settlement_overrides),
        referral_commission_percent: merchant.referral_commission_percent != null ? String(merchant.referral_commission_percent) : '',
        api_pix_mode: merchant.api_pix_mode || 'inherit',
        integration_modes: { ...defaultIntegrationModes(), ...(merchant.integration_modes || {}) },
        med_zero_enabled: Boolean(merchant.med_zero_enabled),
        api_pix_minimum_charge_brl: limits.api_pix_minimum_charge_brl != null ? String(limits.api_pix_minimum_charge_brl) : '',
        platform_minimum_charge_brl: limits.platform_minimum_charge_brl != null ? String(limits.platform_minimum_charge_brl) : '',
        use_platform_api_pix_minimum: limits.api_pix_minimum_charge_brl == null,
        use_platform_platform_minimum: limits.platform_minimum_charge_brl == null,
        cajupay_account_id: merchant.cajupay_account_id != null ? String(merchant.cajupay_account_id) : '',
    });
    form.reset();
    syncGatewayPrimary();
}
onMounted(initialize);

function feeFieldUnchanged(current, next) {
    const empty = (value) => value === '' || value === null || value === undefined;
    if (empty(current) && empty(next)) return true;
    if (empty(current) || empty(next)) return false;
    const a = parseFloat(String(current).replace(',', '.'));
    const b = parseFloat(String(next).replace(',', '.'));
    if (Number.isFinite(a) && Number.isFinite(b)) return a === b;
    return String(current) === String(next);
}

function buildFeesPayload(fees) {
    if (clearAllFees.value) return null;
    const result = savedFees.value && typeof savedFees.value === 'object' ? { ...savedFees.value } : {};
    for (const key of feeKeys) {
        if (!touchedFees.value[key]) continue;
        const normalized = normalizeMerchantFeeOverridesForSubmit({ [key]: fees[key] });
        if (normalized?.[key]) result[key] = normalized[key]; else delete result[key];
    }
    return Object.keys(result).length ? result : null;
}
function updateFee(key, field, value) {
    if (feeFieldUnchanged(form.merchant_fees[key]?.[field], value)) return;
    feesDirty.value = true;
    clearAllFees.value = false;
    touchedFees.value = { ...touchedFees.value, [key]: true };
    const block = { ...form.merchant_fees[key], [field]: value };
    form.merchant_fees = { ...form.merchant_fees, [key]: block };
    const children = key === 'pix' ? ['api_pix', 'pixgo', 'open_finance'] : key === 'card' ? ['apple_pay', 'google_pay'] : [];
    for (const child of children) {
        if (!touchedFees.value[child] && !explicitOverride(savedFees.value, child)) form.merchant_fees = { ...form.merchant_fees, [child]: { ...block } };
    }
}
function setFeeRef(collection, key, element) {
    if (element) collection[key] = element;
    else delete collection[key];
}
function flushFeeInputs() {
    for (const row of feeRows) {
        feePercentRefs[row.key]?.commit?.();
        feeFixedRefs[row.key]?.commit?.();
    }
}
function restoreFees() {
    feesDirty.value = true;
    clearAllFees.value = true;
    touchedFees.value = {};
    savedFees.value = null;
    form.merchant_fees = feesFormFromEffective(null);
}
function restoreSettlement() {
    settlementDirty.value = true; form.merchant_settlement_overrides = defaultSettlement();
}
function buildGatewayOrder(method, primary) {
    if (!primary) return null;
    const available = gatewaysFor(method).map((gateway) => gateway.slug);
    const previous = props.merchant.merchant_gateway_order?.[method]?.length
        ? props.merchant.merchant_gateway_order[method] : (props.platform_gateway_order?.[method] || []);
    return [primary, ...previous, ...available].filter((slug, index, all) => available.includes(slug) && all.indexOf(slug) === index);
}

const feeComparison = computed(() => {
    const global = effectiveFees(null);
    const draft = feesDirty.value ? buildFeesPayload(form.merchant_fees) : savedFees.value;
    const effective = effectiveFees(draft);
    return feeRows.map((row) => ({ ...row, global: global[row.key], effective: effective[row.key] }));
});
const showCajuPay = computed(() => ['pix', 'card'].some((method) => gatewaysFor(method).some((gateway) => gateway.slug === 'cajupay')));
const showPixAuto = computed(() => gatewaysFor('pix_auto').length > 0);
const effectiveApiMinimum = computed(() => form.use_platform_api_pix_minimum ? Number(props.platform_charge_limits.api_pix_minimum_charge_brl) || 0 : Number(form.api_pix_minimum_charge_brl) || 0);
const effectivePlatformMinimum = computed(() => form.use_platform_platform_minimum ? Number(props.platform_charge_limits.platform_minimum_charge_brl) || 0 : Number(form.platform_minimum_charge_brl) || 0);
function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
}
function formatFee(block) {
    const percent = Number(block?.percent) || 0;
    const fixed = Number(block?.fixed) || 0;
    return `${percent.toLocaleString('pt-BR', { maximumFractionDigits: 4 })}%${fixed ? ` + ${formatBRL(fixed)}` : ''}`;
}
function submit() {
    flushFeeInputs();
    const order = {};
    for (const method of Object.keys(gatewayPrimary)) {
        const built = buildGatewayOrder(method, gatewayPrimary[method]);
        if (built?.length) order[method] = built;
    }
    form.transform((data) => {
        const payload = { ...data };
        const referral = String(data.referral_commission_percent ?? '').trim();
        payload.referral_commission_percent = referral === '' ? null : Number(referral.replace(',', '.'));
        if (feesDirty.value) payload.merchant_fees = buildFeesPayload(data.merchant_fees); else delete payload.merchant_fees;
        if (settlementDirty.value) payload.merchant_settlement_overrides = normalizeMerchantSettlementOverridesForSubmit(data.merchant_settlement_overrides); else delete payload.merchant_settlement_overrides;
        if (gatewayDirty.value) payload.merchant_gateway_order = Object.keys(order).length ? order : null; else delete payload.merchant_gateway_order;
        if (!apiPixDirty.value) { delete payload.api_pix_mode; delete payload.med_zero_enabled; }
        if (!integrationsDirty.value) { delete payload.integration_modes; }
        if (limitsDirty.value) {
            if (data.use_platform_api_pix_minimum) payload.api_pix_minimum_charge_brl = '';
            if (data.use_platform_platform_minimum) payload.platform_minimum_charge_brl = '';
        } else {
            delete payload.api_pix_minimum_charge_brl; delete payload.platform_minimum_charge_brl;
            delete payload.use_platform_api_pix_minimum; delete payload.use_platform_platform_minimum;
        }
        if (cajupayDirty.value) payload.cajupay_account_id = data.cajupay_account_id ? Number(data.cajupay_account_id) : null;
        else delete payload.cajupay_account_id;
        return payload;
    }).put(`/plataforma/usuarios/${props.merchant.id}`, { preserveScroll: true });
}
</script>

<template>
    <div class="mx-auto max-w-5xl space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="mb-2 flex flex-wrap gap-3 text-sm">
                    <Link :href="`/plataforma/usuarios/${merchant.id}`" class="inline-flex items-center gap-1 text-zinc-600 hover:text-[var(--color-primary)]"><ArrowLeft class="h-4 w-4" /> Voltar aos detalhes</Link>
                    <Link href="/plataforma/usuarios" class="inline-flex items-center gap-1 text-zinc-600 hover:text-[var(--color-primary)]"><List class="h-4 w-4" /> Lista de infoprodutores</Link>
                </div>
                <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">Editar infoprodutor</h1>
                <p class="mt-1 text-sm text-zinc-500">{{ merchant.name }} · {{ merchant.email }}</p>
            </div>
        </div>

        <p
            v-if="page.props.flash?.success"
            class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200"
        >
            {{ page.props.flash.success }}
        </p>
        <p
            v-if="page.props.flash?.error || page.props.errors?.totp_code"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-200"
        >
            {{ page.props.flash?.error || page.props.errors?.totp_code }}
        </p>

        <form class="space-y-6" @submit.prevent="submit">
            <section class="grid gap-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:grid-cols-2 dark:border-zinc-700 dark:bg-zinc-900">
                <div><label class="text-sm font-medium">Nome</label><input v-model="form.name" required class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800" /><p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p></div>
                <div><label class="text-sm font-medium">E-mail</label><input v-model="form.email" type="email" required class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800" /><p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p></div>
                <div><label class="text-sm font-medium">WhatsApp / telefone</label><input :value="form.phone" type="tel" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800" @input="form.phone = formatPhone($event.target.value)" /><p v-if="form.errors.phone" class="mt-1 text-sm text-red-600">{{ form.errors.phone }}</p></div>
                <div><label class="text-sm font-medium">Status da conta</label><select v-model="form.account_status" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800"><option value="approved">Aprovado</option><option value="pending">Pendente</option><option value="rejected">Rejeitado</option><option value="suspended">Suspenso</option><option value="blocked">Bloqueado</option></select><p v-if="form.errors.account_status" class="mt-1 text-sm text-red-600">{{ form.errors.account_status }}</p></div>
                <div><label class="text-sm font-medium">Nova senha (opcional)</label><input v-model="form.password" type="password" minlength="8" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800" /><p v-if="form.errors.password" class="mt-1 text-sm text-red-600">{{ form.errors.password }}</p></div>
                <div><label class="text-sm font-medium">Confirmar senha</label><input v-model="form.password_confirmation" type="password" minlength="8" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800" /></div>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="font-semibold">Autenticação em dois fatores (2FA)</h2>
                <p class="mt-1 text-sm text-zinc-500">
                    Se o infoprodutor perdeu o app ou o token, você pode resetar o 2FA para ele voltar a entrar só com a senha e cadastrar um novo.
                </p>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2 text-sm">
                        <span
                            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium"
                            :class="merchant.totp_enabled
                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200'
                                : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300'"
                        >
                            {{ merchant.totp_enabled ? '2FA ativo' : '2FA inativo' }}
                        </span>
                    </div>
                    <Button
                        v-if="merchant.totp_enabled"
                        type="button"
                        variant="secondary"
                        @click="openResetTotp"
                    >
                        Resetar 2FA
                    </Button>
                </div>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="font-semibold">Saldo e saques</h2>
                <p class="mt-2 text-xs text-zinc-500">Disponível: {{ formatBRL(merchant.saldo_disponivel) }} · PIX pendente: {{ formatBRL(merchant.saldo_pix) }} · MED: {{ formatBRL(merchant.med_total) }}</p>
                <label class="mt-4 flex items-center gap-2 text-sm"><input v-model="form.admin_withdrawal_blocked" type="checkbox" /> Bloquear todos os saques</label>
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div><label class="text-sm font-medium">Valor adicional bloqueado</label><input v-model="form.admin_blocked_amount" type="number" min="0" step="0.01" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800" /></div>
                    <div><label class="text-sm font-medium">Bloqueio automático até</label><input v-model="form.admin_block_until" type="datetime-local" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800" /></div>
                    <div><label class="text-sm font-medium">Observação do bloqueio</label><input v-model="form.admin_block_note" maxlength="500" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800" /></div>
                </div>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="font-semibold">Indique e Ganhe</h2>
                <p class="mt-1 text-xs text-zinc-500">Deixe vazio para herdar {{ platform_referral_commission_percent }}% da plataforma.</p>
                <input v-model="form.referral_commission_percent" inputmode="decimal" placeholder="Herdar padrão" class="mt-3 w-full max-w-xs rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800" />
                <p v-if="form.errors.referral_commission_percent" class="mt-1 text-sm text-red-600">{{ form.errors.referral_commission_percent }}</p>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between gap-3"><h2 class="font-semibold">Taxas</h2><button type="button" class="text-xs text-[var(--color-primary)] hover:underline" @click="restoreFees">Restaurar padrões</button></div>
                <p class="mt-1 text-xs text-zinc-500">Edite somente os canais que devem deixar de herdar as taxas da plataforma.</p>
                <div class="mt-4 space-y-2">
                    <div v-for="row in feeRows" :key="row.key" class="grid gap-2 rounded-lg p-2 sm:grid-cols-[minmax(0,1.2fr)_1fr_1fr]" :class="explicitOverride(savedFees, row.key) || touchedFees[row.key] ? 'bg-violet-50 dark:bg-violet-950/20' : ''">
                        <div><span class="text-sm font-medium">{{ row.label }}</span><p v-if="row.hint" class="text-[11px] text-zinc-500">{{ row.hint }}</p></div>
                        <FeePercentInput :ref="(element) => setFeeRef(feePercentRefs, row.key, element)" :model-value="form.merchant_fees[row.key].percent" allow-empty @update:model-value="(value) => updateFee(row.key, 'percent', value)" />
                        <FeeFixedInput :ref="(element) => setFeeRef(feeFixedRefs, row.key, element)" :model-value="form.merchant_fees[row.key].fixed" allow-empty @update:model-value="(value) => updateFee(row.key, 'fixed', value)" />
                    </div>
                </div>
                <div class="mt-4 rounded-lg bg-zinc-50 p-3 text-xs dark:bg-zinc-800/50">
                    <div v-for="row in feeComparison" :key="`preview-${row.key}`" class="grid grid-cols-[1fr_auto_auto] gap-4 py-1"><span>{{ row.label }}</span><span class="text-zinc-500">Global: {{ formatFee(row.global) }}</span><span>Efetiva: {{ formatFee(row.effective) }}</span></div>
                </div>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between"><h2 class="font-semibold">Liquidação</h2><button type="button" class="text-xs text-[var(--color-primary)]" @click="restoreSettlement">Restaurar padrões</button></div>
                <p class="mt-1 text-xs text-zinc-500">Campos vazios herdam a configuração da plataforma.</p>
                <div class="mt-4 space-y-3">
                    <div v-for="row in settlementRows" :key="row.key" class="grid gap-2 sm:grid-cols-[110px_1fr_1fr_1fr]">
                        <span class="text-sm font-medium">{{ row.label }}</span>
                        <input v-model="form.merchant_settlement_overrides[row.key].days_to_available" type="number" min="0" max="365" placeholder="Dias D+N" class="rounded-lg border border-zinc-300 px-2 py-1.5 dark:border-zinc-600 dark:bg-zinc-800" @input="settlementDirty = true" />
                        <input v-model="form.merchant_settlement_overrides[row.key].reserve_percent" type="number" min="0" max="100" step="0.01" placeholder="Reserva %" class="rounded-lg border border-zinc-300 px-2 py-1.5 dark:border-zinc-600 dark:bg-zinc-800" @input="settlementDirty = true" />
                        <input v-model="form.merchant_settlement_overrides[row.key].reserve_hold_days" type="number" min="0" max="365" placeholder="Extra reserva" class="rounded-lg border border-zinc-300 px-2 py-1.5 dark:border-zinc-600 dark:bg-zinc-800" @input="settlementDirty = true" />
                    </div>
                </div>
            </section>

            <section class="grid gap-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm md:grid-cols-2 dark:border-zinc-700 dark:bg-zinc-900">
                <div><h2 class="font-semibold">API PIX</h2><p class="mt-1 text-xs text-zinc-500">Padrão global: {{ platform_api_pix_enabled ? 'habilitada' : 'desabilitada' }}.</p><select v-model="form.api_pix_mode" class="mt-3 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800" @change="apiPixDirty = true"><option value="inherit">Herdar plataforma</option><option value="enabled">Habilitada</option><option value="disabled">Desabilitada</option></select><label class="mt-4 flex items-center gap-2 text-sm"><input v-model="form.med_zero_enabled" type="checkbox" @change="apiPixDirty = true" /> MED Zero</label></div>
                <div><h2 class="font-semibold">Limites de cobrança</h2><div class="mt-3 space-y-4">
                    <div><label class="text-sm">Ticket mínimo API PIX</label><input v-model="form.api_pix_minimum_charge_brl" type="number" min="0" step="0.01" :disabled="form.use_platform_api_pix_minimum" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-800" @input="limitsDirty = true" /><label class="mt-1 flex gap-2 text-xs"><input v-model="form.use_platform_api_pix_minimum" type="checkbox" @change="limitsDirty = true" /> Usar padrão (efetivo: {{ formatBRL(effectiveApiMinimum) }})</label></div>
                    <div><label class="text-sm">Ticket mínimo plataforma</label><input v-model="form.platform_minimum_charge_brl" type="number" min="0" step="0.01" :disabled="form.use_platform_platform_minimum" class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-800" @input="limitsDirty = true" /><label class="mt-1 flex gap-2 text-xs"><input v-model="form.use_platform_platform_minimum" type="checkbox" @change="limitsDirty = true" /> Usar padrão (efetivo: {{ formatBRL(effectivePlatformMinimum) }})</label></div>
                </div></div>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="font-semibold">Integrações</h2>
                <p class="mt-1 text-xs text-zinc-500">Herdar o padrão da plataforma, ou forçar habilitada/desabilitada para este infoprodutor — inclusive se a integração estiver oculta globalmente.</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div v-for="item in platform_integrations" :key="item.id">
                        <label class="text-sm font-medium">{{ item.label }}</label>
                        <p class="mt-0.5 text-[11px] text-zinc-500">Padrão global: {{ platform_integrations_enabled[item.id] ? 'visível' : 'oculta' }}.</p>
                        <select v-model="form.integration_modes[item.id]" class="mt-2 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800" @change="integrationsDirty = true">
                            <option value="inherit">Herdar plataforma</option>
                            <option value="enabled">Habilitada</option>
                            <option value="disabled">Desabilitada</option>
                        </select>
                    </div>
                </div>
                <p v-if="form.errors.integration_modes" class="mt-2 text-sm text-red-600">{{ form.errors.integration_modes }}</p>
            </section>

            <section v-if="showCajuPay" class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="font-semibold">Conta CajuPay</h2><select v-model="form.cajupay_account_id" class="mt-3 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800" @change="cajupayDirty = true"><option value="">Padrão da plataforma</option><option v-for="account in cajupay_accounts" :key="account.id" :value="String(account.id)">{{ account.name }}{{ account.is_default ? ' (padrão global)' : '' }}</option></select>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="font-semibold">Ordem de adquirentes</h2><p class="mt-1 text-xs text-zinc-500">Selecione o principal ou herde a ordem da plataforma.</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-2"><template v-for="row in gatewayRows" :key="row.key"><label v-if="row.key !== 'pix_auto' || showPixAuto" class="text-sm"><span class="font-medium">{{ row.label }}</span><select v-model="gatewayPrimary[row.key]" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800"><option value="">Padrão da plataforma</option><option v-for="gateway in gatewaysFor(row.key)" :key="gateway.slug" :value="gateway.slug">{{ gateway.name }}{{ gateway.is_connected ? '' : ' (não conectado)' }}</option></select></label></template></div>
            </section>

            <section class="rounded-2xl border border-amber-200 bg-amber-50/40 p-6 dark:border-amber-900/50 dark:bg-amber-950/20">
                <h2 class="mb-3 font-semibold">Observações internas</h2><MerchantAdminNotesPanel :merchant-user-id="merchant.id" compact :initial-count="merchant.admin_notes_count || 0" />
            </section>

            <div class="sticky bottom-4 flex justify-end gap-2 rounded-xl border border-zinc-200 bg-white/95 p-3 shadow-lg backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95">
                <Button type="button" variant="secondary" @click="$inertia.visit(`/plataforma/usuarios/${merchant.id}`)">Cancelar</Button>
                <Button type="submit" :disabled="form.processing">Salvar alterações</Button>
            </div>
        </form>

        <PlatformStepUpModal
            :open="resetTotpOpen"
            title="Resetar 2FA do infoprodutor"
            :description="resetTotpDescription"
            confirm-label="Desativar 2FA"
            :loading="resetTotpLoading"
            :require-totp="platformTotpEnabled"
            :require-pin="requirePin"
            :confirm-disabled="barrierMissing"
            @close="closeResetTotp"
            @confirm="submitResetTotp"
        />
    </div>
</template>
