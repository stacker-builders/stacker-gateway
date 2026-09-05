<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useForm, usePage, Link } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import { useI18n } from '@/composables/useI18n';
import {
    Wallet,
    ArrowDownCircle,
    Clock,
    Shield,
    Zap,
    X,
    Landmark,
    FileText,
    UserCircle,
} from 'lucide-vue-next';
import KycDocumentsForm from '@/components/kyc/KycDocumentsForm.vue';

defineOptions({ layout: LayoutInfoprodutor });

const page = usePage();
const { t } = useI18n();

const props = defineProps({
    wallet: { type: Object, default: null },
    withdrawals: { type: Array, default: () => [] },
    fee_preview: { type: Object, default: () => ({}) },
    payout_settings: { type: Object, default: () => ({}) },
    payout_pix_setup: { type: String, default: null },
    /** Dígitos do documento do titular (KYC) para pré-preencher CajuPay */
    caju_pix_owner_document_hint: { type: String, default: '' },
    settlement_preview: { type: Object, default: () => ({}) },
    pending_receive_by_date: { type: Array, default: () => [] },
    /** Líquido mínimo efetivo (plataforma × gateway). */
    withdrawal_minimum_net_brl: { type: Number, default: 0 },
    /** Valor bruto mínimo a solicitar para atingir o líquido (após taxa de saque). */
    withdrawal_minimum_gross_brl: { type: Number, default: null },
    seller_profile: {
        type: Object,
        default: () => ({ name: '', email: '', document: null }),
    },
    kyc_finance_locked: { type: Boolean, default: false },
    kyc_status: { type: String, default: null },
    kyc_person_type: { type: String, default: 'pf' },
    kyc_rejection_reason: { type: String, default: null },
    kyc_identity_document_type: { type: String, default: null },
    kyc_company_legal_nature: { type: String, default: null },
    kyc_company_nature_suggestion: { type: String, default: null },
    kyc_uploaded_kinds: { type: Array, default: () => [] },
    kyc_requirements: {
        type: Object,
        default: () => ({
            allowed_identity_types: ['rg', 'cnh', 'passport'],
            require_address_proof: true,
            require_selfie_with_document: true,
            require_company_address_proof: true,
            require_company_constitution: true,
        }),
    },
    /** Dados do cadastro inicial (somente leitura). */
    registration_snapshot: {
        type: Object,
        default: () => ({}),
    },
    pj_conversion: { type: Object, default: null },
});

function snap(v) {
    if (v === null || v === undefined || v === '') {
        return '—';
    }
    return v;
}

function readTabFromUrl() {
    if (typeof window === 'undefined') {
        return 'extrato';
    }
    const params = new URLSearchParams(window.location.search);
    const t = params.get('tab');
    if (t === 'seus-dados' || t === 'dados' || t === 'extrato') {
        return t;
    }
    return 'extrato';
}

const activeTab = ref('extrato');
const showKycModal = ref(false);
const showWithdrawModal = ref(false);
/** Só após cadastro: mostra dados em leitura; Editar habilita o formulário. */
const editingPayoutPix = ref(false);

function setFinanceTab(tab) {
    activeTab.value = tab;
    if (typeof window === 'undefined') {
        return;
    }
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.replaceState({}, '', url);
}

/** Precisa enviar/reenviar documentos (ainda não está em análise nem aprovado). */
const needsKycDocuments = computed(() => {
    const s = props.kyc_status || 'not_submitted';
    return s === 'not_submitted' || s === 'rejected';
});

const pjConversionMode = computed(() => {
    const status = props.pj_conversion?.status;
    return status === 'collecting_docs' || status === 'pending_review';
});

function openKycModal() {
    showKycModal.value = true;
    if (activeTab.value !== 'seus-dados') {
        setFinanceTab('seus-dados');
    }
}

function closeKycModal() {
    showKycModal.value = false;
}

onMounted(() => {
    activeTab.value = readTabFromUrl();
    if (needsKycDocuments.value) {
        showKycModal.value = true;
        if (activeTab.value !== 'seus-dados') {
            setFinanceTab('seus-dados');
        }
    }
    if (typeof sessionStorage !== 'undefined' && sessionStorage.getItem('financeiro_open_withdraw') === '1') {
        sessionStorage.removeItem('financeiro_open_withdraw');
        if (hasPayoutPixRegistered.value && !kycFinanceLocked.value) {
            openWithdrawModalCore();
        }
    }
});

watch(
    () => props.kyc_status,
    (status) => {
        const s = status || 'not_submitted';
        if (s === 'pending_review' || s === 'approved') {
            showKycModal.value = false;
            return;
        }
        if (s === 'not_submitted' || s === 'rejected') {
            showKycModal.value = true;
        }
    }
);

const withdrawForm = useForm({
    amount: '',
    bucket: 'pix',
    notes: '',
});

const BUCKET_KEYS = ['pix', 'card', 'boleto'];

function walletBucketAvailable(bucket) {
    const key = `available_${bucket}`;
    return Number(props.wallet?.[key]) || 0;
}

/** Converte máscara pt-BR (1.334,46) ou dígitos em reais. */
function parseBrlMaskedToNumber(raw) {
    const s = String(raw ?? '').trim();
    if (!s) {
        return 0;
    }
    const normalized = s.includes(',')
        ? s.replace(/\./g, '').replace(',', '.')
        : s.replace(/[^\d.]/g, '');
    const n = Number.parseFloat(normalized);
    return Number.isFinite(n) ? n : 0;
}

/** Máscara de digitação: só dígitos → 1.234,56 */
function maskBrlAmountInput(raw) {
    const digits = String(raw ?? '').replace(/\D/g, '');
    if (!digits) {
        return '';
    }
    const cents = Number.parseInt(digits, 10);
    if (!Number.isFinite(cents)) {
        return '';
    }
    return (cents / 100).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function formatBrlPlain(value) {
    return (Number(value) || 0).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function pickBestBucket(amount = 0) {
    const balances = BUCKET_KEYS.map((key) => ({
        key,
        available: walletBucketAvailable(key),
    }));
    if (amount > 0) {
        const covering = balances
            .filter((b) => b.available + 0.0001 >= amount)
            .sort((a, b) => b.available - a.available);
        if (covering.length) {
            return covering[0].key;
        }
    }
    return [...balances].sort((a, b) => b.available - a.available)[0]?.key || 'pix';
}

function onWithdrawAmountInput(event) {
    withdrawForm.amount = maskBrlAmountInput(event.target.value);
    withdrawForm.clearErrors('amount');
}

function useMaxWithdrawAmount() {
    const available = walletBucketAvailable(withdrawForm.bucket);
    withdrawForm.amount = formatBrlPlain(available);
    withdrawForm.clearErrors('amount');
}

function openWithdrawModal() {
    if (kycFinanceLocked.value) {
        openKycModal();
        return;
    }
    if (!props.payout_pix_setup) {
        setFinanceTab('dados');
        return;
    }
    if (!hasPayoutPixRegistered.value) {
        openPayoutPixModal({ thenWithdraw: true });
        return;
    }
    openWithdrawModalCore();
}

function openWithdrawModalCore() {
    withdrawForm.clearErrors();
    withdrawForm.amount = '';
    withdrawForm.notes = '';
    withdrawForm.bucket = pickBestBucket();
    showWithdrawModal.value = true;
}

function closeWithdrawModal() {
    showWithdrawModal.value = false;
}

const showPayoutPixModal = ref(false);
/** Após salvar a chave PIX, abrir o modal de saque. */
const openWithdrawAfterPixSave = ref(false);

function openPayoutPixModal({ thenWithdraw = false } = {}) {
    openWithdrawAfterPixSave.value = thenWithdraw;
    syncPayoutPixFormFromProps();
    editingPayoutPix.value = true;
    showPayoutPixModal.value = true;
}

function closePayoutPixModal() {
    showPayoutPixModal.value = false;
    openWithdrawAfterPixSave.value = false;
    if (hasPayoutPixRegistered.value) {
        cancelEditPayoutPix();
    } else {
        editingPayoutPix.value = false;
        payoutPixForm.clearErrors();
    }
}

const payoutPixForm = useForm({
    label: '',
    pix_key_type: 'cpf',
    pix_key: '',
    /** CPF ou CNPJ do titular da chave — obrigatório CajuPay. Apenas dígitos ao enviar. */
    key_owner_document: '',
    receiver_name: '',
    receiver_document: '',
    receiver_email: '',
});

function submitPayoutPix() {
    payoutPixForm.clearErrors();
    const onSuccess = () => {
        payoutPixForm.clearErrors();
        editingPayoutPix.value = false;
        if (props.payout_pix_setup === 'label_and_key') {
            payoutPixForm.reset('pix_key');
            payoutPixForm.reset('key_owner_document');
        }
        const thenWithdraw = openWithdrawAfterPixSave.value;
        showPayoutPixModal.value = false;
        openWithdrawAfterPixSave.value = false;
        // Inertia remonta a página após o POST — preserva a intenção de abrir o saque.
        if (thenWithdraw && typeof sessionStorage !== 'undefined') {
            sessionStorage.setItem('financeiro_open_withdraw', '1');
        }
    };
    if (props.payout_pix_setup === 'label_and_key') {
        payoutPixForm
            .transform((data) => {
                const type = data.pix_key_type;
                const pixKey = normalizePixKeyForSubmit(type, data.pix_key);
                const ownerDoc =
                    type === 'cpf' || type === 'cnpj'
                        ? pixKey
                        : digitsOnly(data.key_owner_document);
                return {
                    label: data.label,
                    pix_key_type: type,
                    pix_key: pixKey,
                    key_owner_document: ownerDoc,
                };
            })
            .post('/financeiro/pix-saque', {
                preserveScroll: true,
                onSuccess,
            });
        return;
    }
    if (props.payout_pix_setup === 'key_and_receiver') {
        payoutPixForm
            .transform((data) => ({
                pix_key: normalizePixKeyForSubmit(data.pix_key_type, data.pix_key),
                pix_key_type: data.pix_key_type,
                receiver_name: data.receiver_name,
                receiver_document: digitsOnly(data.receiver_document) || String(data.receiver_document || '').trim(),
                receiver_email: data.receiver_email,
            }))
            .post('/financeiro/pix-saque', {
                preserveScroll: true,
                onSuccess,
            });
        return;
    }
    if (props.payout_pix_setup === 'pix_key_only') {
        payoutPixForm
            .transform((data) => ({
                pix_key: normalizePixKeyForSubmit(data.pix_key_type, data.pix_key),
                pix_key_type: data.pix_key_type,
            }))
            .post('/financeiro/pix-saque', {
                preserveScroll: true,
                onSuccess,
            });
    }
}

function syncPayoutPixFormFromProps() {
    const s = props.payout_settings || {};
    if (props.payout_pix_setup === 'label_and_key') {
        payoutPixForm.label = (s.payout_pix_label || s.cajupay_pix_label || '').trim();
        payoutPixForm.pix_key_type = s.cajupay_pix_key_type || s.payout_pix_key_type || 'cpf';
        const rawKey = (s.cajupay_pix_key || s.payout_pix_key || '').trim();
        payoutPixForm.pix_key =
            payoutPixForm.pix_key_type === 'cpf' || payoutPixForm.pix_key_type === 'cnpj'
                ? formatCpfCnpjMask(rawKey, payoutPixForm.pix_key_type)
                : rawKey;
        const savedDoc = (s.cajupay_pix_key_owner_document || s.payout_pix_key_owner_document || '').replace(/\D/g, '');
        const hint = (props.caju_pix_owner_document_hint || '').replace(/\D/g, '');
        const doc = savedDoc || hint || '';
        payoutPixForm.key_owner_document = doc
            ? doc.length <= 11
                ? formatCpfCnpjMask(doc, 'cpf')
                : formatCpfCnpjMask(doc, 'cnpj')
            : '';
    } else if (props.payout_pix_setup === 'key_and_receiver') {
        payoutPixForm.pix_key_type = s.payout_pix_key_type || s.spacepag_pix_key_type || 'cpf';
        const rawKey = (s.payout_pix_key || s.spacepag_pix_key || '').trim();
        payoutPixForm.pix_key =
            payoutPixForm.pix_key_type === 'cpf' || payoutPixForm.pix_key_type === 'cnpj'
                ? formatCpfCnpjMask(rawKey, payoutPixForm.pix_key_type)
                : rawKey;
        payoutPixForm.receiver_name = (s.receiver_name || '').trim();
        const rd = digitsOnly(s.receiver_document || '');
        payoutPixForm.receiver_document = rd
            ? rd.length <= 11
                ? formatCpfCnpjMask(rd, 'cpf')
                : formatCpfCnpjMask(rd, 'cnpj')
            : (s.receiver_document || '').trim();
        payoutPixForm.receiver_email = (s.receiver_email || '').trim();
    } else if (props.payout_pix_setup === 'pix_key_only') {
        payoutPixForm.pix_key_type = s.payout_pix_key_type || s.woovi_pix_key_type || 'cpf';
        const rawKey = (s.payout_pix_key || s.woovi_pix_key || '').trim();
        payoutPixForm.pix_key =
            payoutPixForm.pix_key_type === 'cpf' || payoutPixForm.pix_key_type === 'cnpj'
                ? formatCpfCnpjMask(rawKey, payoutPixForm.pix_key_type)
                : rawKey;
    }
}

function startEditPayoutPix() {
    editingPayoutPix.value = true;
    syncPayoutPixFormFromProps();
}

function cancelEditPayoutPix() {
    editingPayoutPix.value = false;
    syncPayoutPixFormFromProps();
    payoutPixForm.clearErrors();
}

function submitWithdraw() {
    if (withdrawAmountExceedsBucket.value || parsedWithdrawAmount.value <= 0) {
        return;
    }
    withdrawForm.post('/financeiro/saque', {
        preserveScroll: true,
        onSuccess: () => {
            withdrawForm.reset('amount', 'notes');
            withdrawForm.clearErrors();
            showWithdrawModal.value = false;
        },
    });
}

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
}

function formatPercent(value) {
    const n = Number(value) || 0;
    return `${n.toLocaleString('pt-BR', {
        minimumFractionDigits: Number.isInteger(n) ? 0 : 2,
        maximumFractionDigits: 2,
    })}%`;
}

function bucketLabel(b) {
    const map = { pix: 'PIX', card: 'Cartão', boleto: 'Boleto' };
    return map[b] || b || '—';
}

function statusLabel(s) {
    const map = {
        pending: 'Pendente',
        paid: 'Pago',
        rejected: 'Rejeitado',
    };
    return map[s] || s || '—';
}

const role = computed(() => page.props.auth?.user?.role);
const canRequestWithdrawal = computed(() => role.value === 'infoprodutor');

const kycFinanceLocked = computed(() => props.kyc_finance_locked === true);

const selectedBucketAvailable = computed(() => walletBucketAvailable(withdrawForm.bucket));

const parsedWithdrawAmount = computed(() => parseBrlMaskedToNumber(withdrawForm.amount));

const withdrawAmountExceedsBucket = computed(() => {
    const amt = parsedWithdrawAmount.value;
    return amt > 0 && amt > selectedBucketAvailable.value + 0.0001;
});

const withdrawCrossBucketHint = computed(() => {
    if (!withdrawAmountExceedsBucket.value) {
        return '';
    }
    const amt = parsedWithdrawAmount.value;
    const current = bucketLabel(withdrawForm.bucket);
    const currentAvail = formatBRL(selectedBucketAvailable.value);
    const better = BUCKET_KEYS
        .filter((key) => key !== withdrawForm.bucket && walletBucketAvailable(key) + 0.0001 >= amt)
        .sort((a, b) => walletBucketAvailable(b) - walletBucketAvailable(a));
    if (better.length) {
        const alt = better[0];
        return `Nesta carteira (${current}) há ${currentAvail}. Você tem ${formatBRL(walletBucketAvailable(alt))} em ${bucketLabel(alt)} — troque a carteira.`;
    }
    const richest = [...BUCKET_KEYS].sort((a, b) => walletBucketAvailable(b) - walletBucketAvailable(a))[0];
    if (richest && richest !== withdrawForm.bucket && walletBucketAvailable(richest) > 0) {
        return `Nesta carteira (${current}) há ${currentAvail}. Maior saldo: ${bucketLabel(richest)} com ${formatBRL(walletBucketAvailable(richest))}.`;
    }
    return `Nesta carteira (${current}) há apenas ${currentAvail}. Informe um valor menor.`;
});

const withdrawBucketOptions = computed(() =>
    BUCKET_KEYS.map((key) => ({
        key,
        label: bucketLabel(key),
        available: walletBucketAvailable(key),
    }))
);

watch(
    () => withdrawForm.bucket,
    () => {
        withdrawForm.clearErrors('amount');
    }
);

const withdrawalFeeHint = computed(() => {
    const w = props.fee_preview?.withdrawal;
    if (!w) return '';
    return `Taxa de saque efetiva: ${w.percent ?? 0}% + ${formatBRL(w.fixed ?? 0)} sobre o valor solicitado.`;
});

const payoutPixLabelDisplay = computed(() => {
    const s = props.payout_settings || {};
    return (s.payout_pix_label || s.cajupay_pix_label || '').trim() || '—';
});

const payoutPixKeyDisplay = computed(() => {
    const s = props.payout_settings || {};
    return (s.payout_pix_key || s.spacepag_pix_key || s.woovi_pix_key || '').trim() || '';
});

function pixKeyTypeLabel(t) {
    const m = { cpf: 'CPF', cnpj: 'CNPJ', email: 'E-mail', phone: 'Telefone', evp: 'Chave aleatória' };
    return m[t] || t || '—';
}

const payoutPixTypeDisplay = computed(() => {
    const s = props.payout_settings || {};
    const t = s.payout_pix_key_type || s.spacepag_pix_key_type || s.woovi_pix_key_type || 'cpf';
    return pixKeyTypeLabel(t);
});

const hasReservePending = computed(() => (Number(props.wallet?.reserve_pending_total) || 0) > 0.0001);

const pendingReceiveByDate = computed(() =>
    (props.pending_receive_by_date ?? []).filter((row) => (Number(row.amount) || 0) > 0)
);

function formatPendingReleaseDate(isoDate) {
    if (!isoDate) {
        return t('finance.pending_date_unknown', 'Data a confirmar');
    }
    const [y, m, d] = String(isoDate).split('-');
    if (!y || !m || !d) {
        return isoDate;
    }
    return `${d}/${m}/${y}`;
}

const settlementCards = computed(() => {
    const fees = props.fee_preview || {};
    const sp = props.settlement_preview || {};
    const rows = [
        { key: 'pix', label: 'PIX', accent: 'from-sky-500/20 to-cyan-500/10 text-sky-700 dark:text-sky-300' },
        { key: 'open_finance', label: 'Open Finance', accent: 'from-teal-500/20 to-emerald-500/10 text-teal-800 dark:text-teal-200' },
        { key: 'card', label: 'Cartão', accent: 'from-violet-500/20 to-purple-500/10 text-violet-700 dark:text-violet-300' },
        { key: 'apple_pay', label: 'Apple Pay', accent: 'from-zinc-400/25 to-zinc-500/15 text-zinc-800 dark:text-zinc-200' },
        { key: 'google_pay', label: 'Google Pay', accent: 'from-blue-500/15 to-indigo-500/10 text-indigo-800 dark:text-indigo-200' },
        { key: 'boleto', label: 'Boleto', accent: 'from-emerald-500/20 to-teal-500/10 text-emerald-700 dark:text-emerald-300' },
    ];
    return rows
        .map(({ key, label, accent }) => {
            const r = sp[key];
            const f = fees[key];
            if (!r || typeof r !== 'object') return null;
            const days = Number(r.days_to_available) || 0;
            const percent = Number(f?.percent) || 0;
            const fixed = Number(f?.fixed) || 0;
            return {
                label,
                accent,
                percent,
                fixed,
                days,
                payoutText: `D+${days}`,
            };
        })
        .filter(Boolean);
});

const hasPayoutPixRegistered = computed(() => {
    const s = props.payout_settings || {};
    if (props.payout_pix_setup === 'label_and_key') {
        return !!(s.cajupay_pix_key_id || s.cajupay_pix_key || s.payout_pix_key);
    }
    if (props.payout_pix_setup === 'key_and_receiver' || props.payout_pix_setup === 'pix_key_only') {
        const k = (s.payout_pix_key || s.spacepag_pix_key || s.woovi_pix_key || '').trim();
        return k !== '';
    }
    return false;
});

const hasExtratoContent = computed(() => (props.withdrawals?.length || 0) > 0);

watch(
    () => [props.payout_pix_setup, props.payout_settings, props.caju_pix_owner_document_hint],
    () => {
        syncPayoutPixFormFromProps();
    },
    { immediate: true }
);

function maskCpfCnpjDigits(digits) {
    const d = String(digits || '').replace(/\D/g, '');
    if (d.length === 11) {
        return d.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
    }
    if (d.length === 14) {
        return d.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
    }
    return digits || '—';
}

function digitsOnly(value) {
    return String(value || '').replace(/\D/g, '');
}

/** Máscara progressiva CPF (11) ou CNPJ (14). Aceita colar com pontos/traços. */
function formatCpfCnpjMask(raw, type = 'cpf') {
    const max = type === 'cnpj' ? 14 : 11;
    const d = digitsOnly(raw).slice(0, max);
    if (type === 'cnpj') {
        return d
            .replace(/^(\d{2})(\d)/, '$1.$2')
            .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
            .replace(/\.(\d{3})(\d)/, '.$1/$2')
            .replace(/(\d{4})(\d)/, '$1-$2');
    }
    return d
        .replace(/(\d{3})(\d)/, '$1.$2')
        .replace(/(\d{3})(\d)/, '$1.$2')
        .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
}

function normalizePixKeyForSubmit(type, key) {
    if (type === 'cpf' || type === 'cnpj' || type === 'phone') {
        return digitsOnly(key);
    }
    return String(key || '').trim();
}

const isPixKeyDocumentType = computed(
    () => payoutPixForm.pix_key_type === 'cpf' || payoutPixForm.pix_key_type === 'cnpj'
);

function onPixKeyInput(event) {
    const value = event.target.value;
    if (isPixKeyDocumentType.value) {
        payoutPixForm.pix_key = formatCpfCnpjMask(value, payoutPixForm.pix_key_type);
        return;
    }
    payoutPixForm.pix_key = value;
}

function onPixKeyTypeChange() {
    if (isPixKeyDocumentType.value && payoutPixForm.pix_key) {
        payoutPixForm.pix_key = formatCpfCnpjMask(payoutPixForm.pix_key, payoutPixForm.pix_key_type);
    }
}

function onReceiverDocumentInput(event) {
    const d = digitsOnly(event.target.value);
    payoutPixForm.receiver_document = d.length <= 11 ? formatCpfCnpjMask(d, 'cpf') : formatCpfCnpjMask(d, 'cnpj');
}

function onKeyOwnerDocumentInput(event) {
    const d = digitsOnly(event.target.value);
    payoutPixForm.key_owner_document = d.length <= 11 ? formatCpfCnpjMask(d, 'cpf') : formatCpfCnpjMask(d, 'cnpj');
}

const inputClass =
    'mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white';
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ t('sidebar.finance', 'Financeiro') }}</h1>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    {{ t('finance.subtitle', 'Saldos, extrato e dados para recebimento.') }}
                </p>
            </div>
            <div v-if="canRequestWithdrawal && !kycFinanceLocked" class="shrink-0">
                <Button type="button" class="inline-flex items-center gap-2" @click="openWithdrawModal">
                    <ArrowDownCircle class="h-4 w-4" aria-hidden="true" />
                    {{ t('finance.request_withdrawal', 'Solicitar saque') }}
                </Button>
            </div>
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

        <!-- Grelha de saldos -->
        <section v-if="wallet" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    class="relative overflow-hidden rounded-2xl border-2 border-[var(--color-primary)]/35 bg-white p-5 shadow-sm dark:border-[var(--color-primary)]/40 dark:bg-zinc-900/80"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[var(--color-primary)]/10 text-[var(--color-primary)]">
                            <Zap class="h-5 w-5" aria-hidden="true" />
                        </div>
                    </div>
                    <p class="mt-3 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ t('finance.available_balance', 'Saldo disponível') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ formatBRL(wallet.available_total) }}</p>
                    <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">{{ t('finance.ready_for_withdrawal', 'Pronto para saque') }}</p>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/80">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/10 text-amber-700 dark:text-amber-400">
                        <Clock class="h-5 w-5" aria-hidden="true" />
                    </div>
                    <p class="mt-3 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ t('finance.pending_receive', 'A receber') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ formatBRL(wallet.pending_total) }}</p>
                    <p
                        v-if="!pendingReceiveByDate.length"
                        class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400"
                    >
                        {{ t('finance.settling', 'Em liquidação') }}
                    </p>
                    <details
                        v-else
                        class="group mt-2 rounded-lg border border-amber-200/60 bg-amber-50/40 dark:border-amber-900/50 dark:bg-amber-950/20"
                    >
                        <summary
                            class="flex cursor-pointer list-none items-center justify-between gap-2 px-2.5 py-1.5 text-[11px] font-medium text-amber-900 dark:text-amber-100 [&::-webkit-details-marker]:hidden"
                        >
                            {{ t('finance.pending_release_dates', 'Datas de liberação') }}
                            <span class="text-[10px] font-normal text-amber-800/80 group-open:hidden dark:text-amber-200/80">{{ t('common.view', 'Ver') }}</span>
                            <span class="hidden text-[10px] font-normal text-amber-800/80 group-open:inline dark:text-amber-200/80">{{ t('common.hide', 'Ocultar') }}</span>
                        </summary>
                        <ul class="space-y-1 border-t border-amber-200/60 px-2.5 py-2 dark:border-amber-900/50">
                            <li
                                v-for="(row, idx) in pendingReceiveByDate"
                                :key="row.date ?? `unknown-${idx}`"
                                class="flex items-center justify-between gap-2 text-[11px] tabular-nums text-amber-950 dark:text-amber-50"
                            >
                                <span>{{ formatPendingReleaseDate(row.date) }}</span>
                                <span class="font-semibold">{{ formatBRL(row.amount) }}</span>
                            </li>
                        </ul>
                    </details>
                </div>

                <div
                    v-if="hasReservePending"
                    class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/80"
                >
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-500/10 text-violet-700 dark:text-violet-300">
                        <Shield class="h-5 w-5" aria-hidden="true" />
                    </div>
                    <p class="mt-3 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Reserva financeira</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">
                        {{ formatBRL(wallet.reserve_pending_total) }}
                    </p>
                    <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">Retida até liberar</p>
                </div>

                <div
                    class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/80 sm:col-span-2 xl:col-span-1"
                >
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                        <Wallet class="h-5 w-5" aria-hidden="true" />
                    </div>
                    <p class="mt-3 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ t('finance.by_method', 'Por método') }}</p>
                    <p class="mt-1 text-[11px] leading-snug text-zinc-500 dark:text-zinc-400">
                        {{ t('finance.withdraw_by_method_hint', 'O saque é por método de venda (PIX, cartão ou boleto).') }}
                    </p>
                    <div class="mt-2 space-y-1.5 text-sm">
                        <div class="flex justify-between gap-2 tabular-nums">
                            <span class="text-zinc-500">PIX</span>
                            <span class="font-medium text-zinc-900 dark:text-white">{{ formatBRL(wallet.available_pix) }}</span>
                        </div>
                        <div class="flex justify-between gap-2 tabular-nums">
                            <span class="text-zinc-500">Cartão</span>
                            <span class="font-medium text-zinc-900 dark:text-white">{{ formatBRL(wallet.available_card) }}</span>
                        </div>
                        <div class="flex justify-between gap-2 tabular-nums">
                            <span class="text-zinc-500">Boleto</span>
                            <span class="font-medium text-zinc-900 dark:text-white">{{ formatBRL(wallet.available_boleto) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <details
                v-if="settlementCards.length"
                class="group rounded-xl border border-zinc-200/80 bg-zinc-50/50 px-4 py-3 dark:border-zinc-700/80 dark:bg-zinc-800/30"
            >
                <summary
                    class="flex cursor-pointer list-none items-center gap-2 text-sm font-medium text-zinc-700 dark:text-zinc-300 [&::-webkit-details-marker]:hidden"
                >
                    {{ t('finance.my_fees_and_payout', 'Minhas taxas e prazo') }}
                    <span class="ml-auto text-xs font-normal text-zinc-500 group-open:hidden">{{ t('common.view', 'Ver') }}</span>
                    <span class="ml-auto hidden text-xs font-normal text-zinc-500 group-open:inline">{{ t('common.hide', 'Ocultar') }}</span>
                </summary>
                <p class="mt-3 border-t border-zinc-200/80 pt-3 text-xs text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    {{ t('finance.by_payment_method', 'Por método de pagamento') }}
                </p>
                <div class="mt-3 grid gap-3 md:grid-cols-3">
                    <article
                        v-for="card in settlementCards"
                        :key="card.label"
                        class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/80"
                    >
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ card.label }}</p>
                            <span
                                class="rounded-full bg-gradient-to-r px-2.5 py-1 text-[10px] font-semibold"
                                :class="card.accent"
                            >
                                {{ t('finance.fee', 'Taxa') }}
                            </span>
                        </div>
                        <p class="mt-4 text-center text-3xl font-bold tabular-nums text-zinc-900 dark:text-white">
                            {{ formatPercent(card.percent) }}
                        </p>
                        <p class="mt-2 text-center text-xs text-zinc-500 dark:text-zinc-400">
                            + {{ formatBRL(card.fixed) }} fixo
                        </p>
                        <div class="mt-4 border-t border-zinc-100 pt-3 text-center dark:border-zinc-700">
                            <p class="text-[11px] uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ t('finance.payout_deadline', 'Prazo de saque') }}</p>
                            <p class="mt-1 text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ card.payoutText }}</p>
                        </div>
                    </article>
                </div>
            </details>
        </section>

        <!-- Abas -->
        <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900/60">
            <div class="flex border-b border-zinc-200 dark:border-zinc-700">
                <button
                    type="button"
                    class="flex items-center gap-2 border-b-2 px-5 py-3.5 text-sm font-medium transition-colors"
                    :class="
                        activeTab === 'extrato'
                            ? 'border-[var(--color-primary)] text-[var(--color-primary)]'
                            : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200'
                    "
                    @click="setFinanceTab('extrato')"
                >
                    <FileText class="h-4 w-4" aria-hidden="true" />
                    {{ t('finance.statement', 'Extrato') }}
                </button>
                <button
                    type="button"
                    class="flex items-center gap-2 border-b-2 px-5 py-3.5 text-sm font-medium transition-colors"
                    :class="
                        activeTab === 'seus-dados'
                            ? 'border-[var(--color-primary)] text-[var(--color-primary)]'
                            : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200'
                    "
                    @click="setFinanceTab('seus-dados')"
                >
                    <UserCircle class="h-4 w-4" aria-hidden="true" />
                    Seus dados
                </button>
                <button
                    type="button"
                    class="flex items-center gap-2 border-b-2 px-5 py-3.5 text-sm font-medium transition-colors"
                    :class="
                        activeTab === 'dados'
                            ? 'border-[var(--color-primary)] text-[var(--color-primary)]'
                            : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200'
                    "
                    @click="setFinanceTab('dados')"
                >
                    <Landmark class="h-4 w-4" aria-hidden="true" />
                    {{ t('finance.bank_details', 'Dados bancários') }}
                </button>
            </div>

            <div class="p-5 sm:p-6">
                <!-- Extrato -->
                <div v-show="activeTab === 'extrato'" class="space-y-8">
                    <div v-if="!hasExtratoContent" class="rounded-xl border border-dashed border-zinc-200 py-16 text-center dark:border-zinc-700">
                        <FileText class="mx-auto h-10 w-10 text-zinc-300 dark:text-zinc-600" aria-hidden="true" />
                        <p class="mt-3 text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ t('finance.no_withdrawals', 'Nenhum saque ainda') }}</p>
                        <p class="mt-1 text-xs text-zinc-500">{{ t('finance.no_withdrawals_hint', 'Seus saques solicitados aparecerão aqui.') }}</p>
                    </div>

                    <div v-else>
                        <div>
                            <h3 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">{{ t('finance.withdrawals', 'Saques') }}</h3>
                            <div class="overflow-x-auto rounded-xl border border-zinc-100 dark:border-zinc-700/80">
                                <table class="w-full min-w-[640px] text-left text-sm">
                                    <thead class="border-b border-zinc-200 bg-zinc-50/80 text-xs uppercase text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/50">
                                        <tr>
                                            <th class="px-4 py-3">Data</th>
                                            <th class="px-4 py-3">Carteira</th>
                                            <th class="px-4 py-3 text-right">Bruto</th>
                                            <th class="px-4 py-3 text-right">Taxa</th>
                                            <th class="px-4 py-3 text-right">Líquido</th>
                                            <th class="px-4 py-3">Status</th>
                                            <th class="px-4 py-3 text-right">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                        <tr v-for="w in withdrawals" :key="w.id" class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                                            <td class="px-4 py-2.5 whitespace-nowrap text-zinc-600 dark:text-zinc-300">
                                                {{ w.created_at ? new Date(w.created_at).toLocaleString('pt-BR') : '—' }}
                                            </td>
                                            <td class="px-4 py-2.5">{{ bucketLabel(w.bucket) }}</td>
                                            <td class="px-4 py-2.5 text-right tabular-nums">{{ formatBRL(w.amount) }}</td>
                                            <td class="px-4 py-2.5 text-right tabular-nums text-zinc-500">{{ formatBRL(w.fee_amount) }}</td>
                                            <td class="px-4 py-2.5 text-right tabular-nums">{{ formatBRL(w.net_amount) }}</td>
                                            <td class="px-4 py-2.5">
                                                <span
                                                    class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium"
                                                    :class="
                                                        w.status === 'paid'
                                                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200'
                                                            : w.status === 'rejected'
                                                              ? 'bg-red-100 text-red-800 dark:bg-red-950/50 dark:text-red-200'
                                                              : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'
                                                    "
                                                >
                                                    {{ statusLabel(w.status) }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-2.5 text-right">
                                                <a
                                                    v-if="w.can_download_receipt"
                                                    :href="`/financeiro/saques/${w.id}/comprovante`"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="inline-flex items-center gap-1 text-xs font-medium text-[var(--color-primary)] hover:underline"
                                                >
                                                    <FileText class="h-3.5 w-3.5" />
                                                    Comprovante
                                                </a>
                                                <span v-else class="text-xs text-zinc-400">—</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Seus dados (cadastro + KYC) -->
                <div v-show="activeTab === 'seus-dados'" class="space-y-6">
                    <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-5 dark:border-zinc-700 dark:bg-zinc-800/40">
                        <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Dados do cadastro</h3>
                        <p class="mt-1 text-xs text-zinc-500">
                            Informações preenchidas no cadastro. Para alterar, entre em contato com o suporte da plataforma.
                        </p>
                        <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <dt class="text-xs font-medium uppercase text-zinc-500">Tipo de conta</dt>
                                <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ snap(registration_snapshot.person_type_label) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase text-zinc-500">Nome completo</dt>
                                <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ snap(registration_snapshot.name) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase text-zinc-500">E-mail</dt>
                                <dd class="mt-0.5 break-all text-zinc-900 dark:text-white">{{ snap(registration_snapshot.email) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase text-zinc-500">WhatsApp</dt>
                                <dd class="mt-0.5 flex flex-wrap items-center gap-2 text-zinc-900 dark:text-white">
                                    <span>{{ snap(registration_snapshot.whatsapp || registration_snapshot.phone) }}</span>
                                    <a
                                        v-if="registration_snapshot.whatsapp_url"
                                        :href="registration_snapshot.whatsapp_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center rounded-lg bg-emerald-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-emerald-500"
                                    >
                                        Abrir WhatsApp
                                    </a>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase text-zinc-500">Data de nascimento</dt>
                                <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ snap(registration_snapshot.birth_date) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase text-zinc-500">
                                    {{ registration_snapshot.person_type === 'pj' ? 'CNPJ' : 'CPF' }}
                                </dt>
                                <dd class="mt-0.5 font-mono text-zinc-900 dark:text-white">{{ snap(registration_snapshot.document) }}</dd>
                            </div>
                            <template v-if="registration_snapshot.person_type === 'pj'">
                                <div class="sm:col-span-2">
                                    <dt class="text-xs font-medium uppercase text-zinc-500">Razão social</dt>
                                    <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ snap(registration_snapshot.company_name) }}</dd>
                                </div>
                                <div class="sm:col-span-2">
                                    <dt class="text-xs font-medium uppercase text-zinc-500">CPF do representante legal</dt>
                                    <dd class="mt-0.5 font-mono text-zinc-900 dark:text-white">{{ snap(registration_snapshot.legal_representative_cpf) }}</dd>
                                </div>
                            </template>
                            <div class="sm:col-span-2 border-t border-zinc-200 pt-4 dark:border-zinc-600">
                                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Endereço</p>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase text-zinc-500">CEP</dt>
                                <dd class="mt-0.5 font-mono text-zinc-900 dark:text-white">{{ snap(registration_snapshot.address_zip) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase text-zinc-500">UF</dt>
                                <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ snap(registration_snapshot.address_state) }}</dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="text-xs font-medium uppercase text-zinc-500">Logradouro</dt>
                                <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ snap(registration_snapshot.address_street) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase text-zinc-500">Número</dt>
                                <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ snap(registration_snapshot.address_number) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase text-zinc-500">Complemento</dt>
                                <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ snap(registration_snapshot.address_complement) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase text-zinc-500">Bairro</dt>
                                <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ snap(registration_snapshot.address_neighborhood) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase text-zinc-500">Cidade</dt>
                                <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ snap(registration_snapshot.address_city) }}</dd>
                            </div>
                            <div class="sm:col-span-2 border-t border-zinc-200 pt-4 dark:border-zinc-600">
                                <dt class="text-xs font-medium uppercase text-zinc-500">Faturamento mensal estimado (cadastro)</dt>
                                <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ snap(registration_snapshot.monthly_revenue_label) }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="border-t border-zinc-200 pt-6 dark:border-zinc-700">
                        <template v-if="needsKycDocuments">
                            <div
                                class="rounded-xl border border-amber-200 bg-amber-50/80 p-5 dark:border-amber-900/50 dark:bg-amber-950/30"
                            >
                                <h3 class="text-sm font-semibold text-amber-950 dark:text-amber-100">
                                    Verificação de identidade pendente
                                </h3>
                                <p class="mt-1 text-xs leading-relaxed text-amber-900/90 dark:text-amber-200/90">
                                    Envie seus documentos para liberar saques e o uso completo do painel. Em celulares, use o
                                    formulário em tela cheia para não perder o envio no fim da página.
                                </p>
                                <Button type="button" class="mt-4 inline-flex items-center gap-2" @click="openKycModal">
                                    <Shield class="h-4 w-4" aria-hidden="true" />
                                    Enviar documentos KYC
                                </Button>
                            </div>
                        </template>
                        <KycDocumentsForm
                            v-else
                            embedded
                            :person_type="pjConversionMode ? 'pj' : kyc_person_type"
                            :kyc_status="kyc_status || 'not_submitted'"
                            :rejection_reason="kyc_rejection_reason"
                            :identity_document_type="kyc_identity_document_type"
                            :company_legal_nature="pj_conversion?.company_legal_nature || kyc_company_legal_nature"
                            :company_nature_suggestion="kyc_company_nature_suggestion"
                            :uploaded_kinds="kyc_uploaded_kinds"
                            :requirements="kyc_requirements"
                            :conversion_mode="pjConversionMode"
                            :conversion_status="pj_conversion?.status"
                            :conversion_rejection_reason="pj_conversion?.rejection_reason"
                        />
                    </div>
                </div>

                <!-- Dados bancários -->
                <div v-show="activeTab === 'dados'" class="space-y-6">
                    <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-5 dark:border-zinc-700 dark:bg-zinc-800/40">
                        <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Titular da conta</h3>
                        <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-xs font-medium uppercase text-zinc-500">Nome</dt>
                                <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ seller_profile.name || page.props.auth?.user?.name || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase text-zinc-500">E-mail</dt>
                                <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ seller_profile.email || page.props.auth?.user?.email || '—' }}</dd>
                            </div>
                            <div v-if="seller_profile.document" class="sm:col-span-2">
                                <dt class="text-xs font-medium uppercase text-zinc-500">Documento</dt>
                                <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ seller_profile.document }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div v-if="canRequestWithdrawal && payout_pix_setup && !kycFinanceLocked" class="space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Chave PIX e recebimento</h3>
                            <div v-if="hasPayoutPixRegistered" class="flex flex-wrap gap-2">
                                <Button
                                    v-if="!editingPayoutPix"
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    @click="startEditPayoutPix"
                                >
                                    Editar
                                </Button>
                                <Button
                                    v-if="editingPayoutPix"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    @click="cancelEditPayoutPix"
                                >
                                    Cancelar
                                </Button>
                            </div>
                        </div>

                        <!-- Somente leitura (já cadastrado) -->
                        <div
                            v-if="hasPayoutPixRegistered && !editingPayoutPix"
                            class="max-w-lg space-y-4 rounded-xl border border-zinc-200 bg-zinc-50/50 p-5 dark:border-zinc-700 dark:bg-zinc-800/40"
                        >
                            <template v-if="payout_pix_setup === 'label_and_key'">
                                <dl class="space-y-3 text-sm">
                                    <div>
                                        <dt class="text-xs font-medium uppercase text-zinc-500">Identificação</dt>
                                        <dd class="mt-1 font-medium text-zinc-900 dark:text-white">{{ payoutPixLabelDisplay }}</dd>
                                    </div>
                                    <div v-if="(payout_settings.cajupay_pix_key_owner_document || '').replace(/\D/g, '').length >= 11">
                                        <dt class="text-xs font-medium uppercase text-zinc-500">CPF/CNPJ do titular</dt>
                                        <dd class="mt-1 text-zinc-700 dark:text-zinc-200">
                                            {{ maskCpfCnpjDigits(payout_settings.cajupay_pix_key_owner_document) }}
                                            <span class="block text-xs text-zinc-500 dark:text-zinc-400">Deve ser o mesmo CPF/CNPJ do titular da chave PIX cadastrada.</span>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-medium uppercase text-zinc-500">Tipo da chave</dt>
                                        <dd class="mt-1 font-medium text-zinc-900 dark:text-white">
                                            {{ (payout_settings.cajupay_pix_key_type || payout_settings.payout_pix_key_type || '—').toUpperCase() }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-medium uppercase text-zinc-500">Chave PIX</dt>
                                        <dd class="mt-1 break-all font-medium text-zinc-900 dark:text-white">
                                            {{ (payout_settings.cajupay_pix_key || '').trim() || '—' }}
                                        </dd>
                                    </div>
                                </dl>
                            </template>
                            <template v-else-if="payout_pix_setup === 'pix_key_only'">
                                <dl class="space-y-3 text-sm">
                                    <div>
                                        <dt class="text-xs font-medium uppercase text-zinc-500">Tipo da chave</dt>
                                        <dd class="mt-1 font-medium text-zinc-900 dark:text-white">{{ payoutPixTypeDisplay }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-medium uppercase text-zinc-500">Chave PIX</dt>
                                        <dd class="mt-1 break-all font-medium text-zinc-900 dark:text-white">{{ payoutPixKeyDisplay || '—' }}</dd>
                                    </div>
                                </dl>
                            </template>
                            <template v-else-if="payout_pix_setup === 'key_and_receiver'">
                                <dl class="space-y-3 text-sm">
                                    <div>
                                        <dt class="text-xs font-medium uppercase text-zinc-500">Tipo da chave</dt>
                                        <dd class="mt-1 font-medium text-zinc-900 dark:text-white">{{ payoutPixTypeDisplay }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-medium uppercase text-zinc-500">Chave PIX</dt>
                                        <dd class="mt-1 break-all font-medium text-zinc-900 dark:text-white">{{ payoutPixKeyDisplay || '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-medium uppercase text-zinc-500">Nome do recebedor</dt>
                                        <dd class="mt-1 text-zinc-900 dark:text-white">{{ (payout_settings.receiver_name || '').trim() || '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-medium uppercase text-zinc-500">CPF/CNPJ do recebedor</dt>
                                        <dd class="mt-1 text-zinc-900 dark:text-white">{{ (payout_settings.receiver_document || '').trim() || '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-medium uppercase text-zinc-500">E-mail do recebedor</dt>
                                        <dd class="mt-1 break-all text-zinc-900 dark:text-white">{{ (payout_settings.receiver_email || '').trim() || '—' }}</dd>
                                    </div>
                                </dl>
                            </template>
                        </div>

                        <form v-if="!hasPayoutPixRegistered || editingPayoutPix" class="max-w-lg space-y-4" @submit.prevent="submitPayoutPix">
                            <template v-if="payout_pix_setup === 'label_and_key'">
                                <p
                                    v-if="!isPixKeyDocumentType"
                                    class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-950 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100"
                                >
                                    Para chave e-mail, telefone ou aleatória (EVP), informe também o CPF/CNPJ do titular da chave.
                                </p>
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Identificação</label>
                                    <input v-model="payoutPixForm.label" type="text" required maxlength="120" :class="inputClass" placeholder="Ex.: conta principal" />
                                    <p v-if="payoutPixForm.errors.label" class="mt-1 text-sm text-red-600">{{ payoutPixForm.errors.label }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Tipo da chave</label>
                                    <select v-model="payoutPixForm.pix_key_type" :class="inputClass" @change="onPixKeyTypeChange">
                                        <option value="cpf">CPF</option>
                                        <option value="cnpj">CNPJ</option>
                                        <option value="email">E-mail</option>
                                        <option value="phone">Telefone</option>
                                        <option value="evp">Chave aleatória</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Chave PIX</label>
                                    <input
                                        :value="payoutPixForm.pix_key"
                                        type="text"
                                        required
                                        :maxlength="isPixKeyDocumentType ? (payoutPixForm.pix_key_type === 'cnpj' ? 18 : 14) : 120"
                                        :inputmode="isPixKeyDocumentType ? 'numeric' : 'text'"
                                        :class="inputClass"
                                        autocomplete="off"
                                        :placeholder="
                                            payoutPixForm.pix_key_type === 'cpf'
                                                ? '000.000.000-00'
                                                : payoutPixForm.pix_key_type === 'cnpj'
                                                  ? '00.000.000/0000-00'
                                                  : ''
                                        "
                                        @input="onPixKeyInput"
                                    />
                                    <p v-if="payoutPixForm.errors.pix_key" class="mt-1 text-sm text-red-600">{{ payoutPixForm.errors.pix_key }}</p>
                                </div>
                                <div v-if="!isPixKeyDocumentType">
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">CPF ou CNPJ do titular</label>
                                    <input
                                        :value="payoutPixForm.key_owner_document"
                                        type="text"
                                        required
                                        maxlength="18"
                                        inputmode="numeric"
                                        :class="inputClass"
                                        autocomplete="off"
                                        placeholder="Documento do titular da chave PIX"
                                        @input="onKeyOwnerDocumentInput"
                                    />
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                        Obrigatório quando a chave não é CPF/CNPJ.
                                    </p>
                                    <p v-if="payoutPixForm.errors.key_owner_document" class="mt-1 text-sm text-red-600">{{ payoutPixForm.errors.key_owner_document }}</p>
                                </div>
                            </template>
                            <template v-else-if="payout_pix_setup === 'pix_key_only'">
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Tipo da chave</label>
                                    <select v-model="payoutPixForm.pix_key_type" :class="inputClass" @change="onPixKeyTypeChange">
                                        <option value="cpf">CPF</option>
                                        <option value="cnpj">CNPJ</option>
                                        <option value="email">E-mail</option>
                                        <option value="phone">Telefone</option>
                                        <option value="evp">Chave aleatória</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Chave PIX</label>
                                    <input
                                        :value="payoutPixForm.pix_key"
                                        type="text"
                                        required
                                        :maxlength="isPixKeyDocumentType ? (payoutPixForm.pix_key_type === 'cnpj' ? 18 : 14) : 120"
                                        :inputmode="isPixKeyDocumentType ? 'numeric' : 'text'"
                                        :class="inputClass"
                                        autocomplete="off"
                                        @input="onPixKeyInput"
                                    />
                                    <p v-if="payoutPixForm.errors.pix_key" class="mt-1 text-sm text-red-600">{{ payoutPixForm.errors.pix_key }}</p>
                                </div>
                            </template>
                            <template v-else-if="payout_pix_setup === 'key_and_receiver'">
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Tipo da chave</label>
                                    <select v-model="payoutPixForm.pix_key_type" :class="inputClass" @change="onPixKeyTypeChange">
                                        <option value="cpf">CPF</option>
                                        <option value="cnpj">CNPJ</option>
                                        <option value="email">E-mail</option>
                                        <option value="phone">Telefone</option>
                                        <option value="evp">Chave aleatória</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Chave PIX</label>
                                    <input
                                        :value="payoutPixForm.pix_key"
                                        type="text"
                                        required
                                        :maxlength="isPixKeyDocumentType ? (payoutPixForm.pix_key_type === 'cnpj' ? 18 : 14) : 120"
                                        :inputmode="isPixKeyDocumentType ? 'numeric' : 'text'"
                                        :class="inputClass"
                                        autocomplete="off"
                                        @input="onPixKeyInput"
                                    />
                                    <p v-if="payoutPixForm.errors.pix_key" class="mt-1 text-sm text-red-600">{{ payoutPixForm.errors.pix_key }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome do recebedor</label>
                                    <input v-model="payoutPixForm.receiver_name" type="text" required maxlength="120" :class="inputClass" />
                                    <p v-if="payoutPixForm.errors.receiver_name" class="mt-1 text-sm text-red-600">{{ payoutPixForm.errors.receiver_name }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">CPF/CNPJ do recebedor</label>
                                    <input
                                        :value="payoutPixForm.receiver_document"
                                        type="text"
                                        required
                                        maxlength="18"
                                        inputmode="numeric"
                                        :class="inputClass"
                                        @input="onReceiverDocumentInput"
                                    />
                                    <p v-if="payoutPixForm.errors.receiver_document" class="mt-1 text-sm text-red-600">{{ payoutPixForm.errors.receiver_document }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">E-mail do recebedor</label>
                                    <input v-model="payoutPixForm.receiver_email" type="email" required maxlength="255" :class="inputClass" />
                                    <p v-if="payoutPixForm.errors.receiver_email" class="mt-1 text-sm text-red-600">{{ payoutPixForm.errors.receiver_email }}</p>
                                </div>
                            </template>
                            <div class="flex flex-wrap justify-end gap-2 pt-2">
                                <Button v-if="editingPayoutPix" type="button" variant="outline" @click="cancelEditPayoutPix">Cancelar</Button>
                                <Button type="submit" :disabled="payoutPixForm.processing">{{
                                    payout_pix_setup === 'label_and_key'
                                        ? payout_settings?.cajupay_pix_key_id
                                            ? 'Salvar alterações'
                                            : 'Cadastrar dados'
                                        : payout_pix_setup === 'pix_key_only'
                                          ? hasPayoutPixRegistered
                                              ? 'Salvar chave PIX'
                                              : 'Cadastrar chave PIX'
                                          : hasPayoutPixRegistered
                                            ? 'Salvar dados'
                                            : 'Cadastrar dados'
                                }}</Button>
                            </div>
                        </form>
                    </div>

                    <div
                        v-else-if="canRequestWithdrawal && !payout_pix_setup"
                        class="rounded-xl border border-amber-200/80 bg-amber-50/50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900/40 dark:bg-amber-950/25 dark:text-amber-100"
                    >
                        A plataforma ainda não configurou o recebimento automático de saques. Entre em contato com o suporte se precisar de ajuda.
                    </div>

                    <p v-else class="text-sm text-zinc-600 dark:text-zinc-400">
                        Apenas o titular (infoprodutor) pode alterar dados bancários e chave PIX.
                    </p>
                </div>
            </div>
        </div>

        <section
            v-if="!canRequestWithdrawal"
            class="rounded-xl border border-dashed border-zinc-300 p-5 text-sm text-zinc-600 dark:border-zinc-600 dark:text-zinc-400"
        >
            Apenas o titular da conta (infoprodutor) pode solicitar saques e editar dados de recebimento. Você pode visualizar o extrato acima.
        </section>

        <!-- Modal saque -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="showWithdrawModal"
                    class="fixed inset-0 z-50 flex items-center justify-center p-4"
                    aria-modal="true"
                    role="dialog"
                    aria-labelledby="withdraw-modal-title"
                    @keydown.escape="closeWithdrawModal"
                >
                    <div class="absolute inset-0 bg-zinc-900/60 dark:bg-zinc-950/70" aria-hidden="true" @click="closeWithdrawModal" />
                    <div
                        class="relative max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex items-center gap-2">
                                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[var(--color-primary)]/10 text-[var(--color-primary)]">
                                    <ArrowDownCircle class="h-5 w-5" aria-hidden="true" />
                                </div>
                                <h3 id="withdraw-modal-title" class="text-lg font-semibold text-zinc-900 dark:text-white">Solicitar saque</h3>
                            </div>
                            <button
                                type="button"
                                class="rounded-lg p-1.5 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                                aria-label="Fechar"
                                @click="closeWithdrawModal"
                            >
                                <X class="h-5 w-5" />
                            </button>
                        </div>
                        <p v-if="withdrawalFeeHint" class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">{{ withdrawalFeeHint }}</p>
                        <p
                            v-if="withdrawal_minimum_gross_brl != null && Number(withdrawal_minimum_gross_brl) > 0"
                            class="mt-2 text-xs leading-snug text-zinc-500 dark:text-zinc-400"
                        >
                            Valor mínimo do saque: {{ formatBRL(withdrawal_minimum_gross_brl) }}
                            <span v-if="Number(withdrawal_minimum_net_brl) > 0">
                                (líquido mínimo {{ formatBRL(withdrawal_minimum_net_brl) }})
                            </span>
                        </p>
                        <p class="mt-2 text-xs leading-snug text-zinc-500 dark:text-zinc-400">
                            {{ t('finance.withdraw_modal_bucket_hint', 'Escolha a carteira do método em que as vendas entraram (PIX, cartão ou boleto). O saldo total da página soma as três.') }}
                        </p>
                        <form class="mt-5 space-y-4" @submit.prevent="submitWithdraw">
                            <div>
                                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Carteira</label>
                                <select v-model="withdrawForm.bucket" :class="inputClass">
                                    <option v-for="opt in withdrawBucketOptions" :key="opt.key" :value="opt.key">
                                        {{ opt.label }} — {{ formatBRL(opt.available) }}
                                    </option>
                                </select>
                                <div
                                    class="mt-2 rounded-xl border border-[var(--color-primary)]/25 bg-[var(--color-primary)]/5 px-3 py-2 dark:border-[var(--color-primary)]/30 dark:bg-[var(--color-primary)]/10"
                                >
                                    <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                        Saldo nesta carteira
                                    </p>
                                    <p class="mt-0.5 text-lg font-semibold tabular-nums text-zinc-900 dark:text-white">
                                        {{ formatBRL(selectedBucketAvailable) }}
                                    </p>
                                </div>
                                <ul class="mt-2 space-y-1 text-xs tabular-nums text-zinc-500 dark:text-zinc-400">
                                    <li
                                        v-for="opt in withdrawBucketOptions"
                                        :key="`sum-${opt.key}`"
                                        class="flex justify-between gap-2"
                                        :class="opt.key === withdrawForm.bucket ? 'font-medium text-zinc-800 dark:text-zinc-200' : ''"
                                    >
                                        <span>{{ opt.label }}</span>
                                        <span>{{ formatBRL(opt.available) }}</span>
                                    </li>
                                </ul>
                            </div>
                            <div>
                                <div class="flex items-center justify-between gap-2">
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Valor (R$)</label>
                                    <button
                                        type="button"
                                        class="text-xs font-medium text-[var(--color-primary)] hover:underline disabled:cursor-not-allowed disabled:opacity-40"
                                        :disabled="selectedBucketAvailable <= 0"
                                        @click="useMaxWithdrawAmount"
                                    >
                                        Usar saldo máximo
                                    </button>
                                </div>
                                <input
                                    :value="withdrawForm.amount"
                                    type="text"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    placeholder="0,00"
                                    required
                                    :class="inputClass"
                                    @input="onWithdrawAmountInput"
                                />
                                <p v-if="withdrawCrossBucketHint" class="mt-1 text-sm text-amber-700 dark:text-amber-300">
                                    {{ withdrawCrossBucketHint }}
                                </p>
                                <p v-if="withdrawForm.errors.amount" class="mt-1 text-sm text-red-600">{{ withdrawForm.errors.amount }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Observações (opcional)</label>
                                <textarea
                                    v-model="withdrawForm.notes"
                                    rows="2"
                                    :class="inputClass"
                                    placeholder="Referência ou observação"
                                />
                            </div>
                            <div class="flex flex-wrap justify-end gap-2 pt-2">
                                <Button type="button" variant="outline" @click="closeWithdrawModal">Cancelar</Button>
                                <Button
                                    type="submit"
                                    :disabled="
                                        withdrawForm.processing
                                            || withdrawAmountExceedsBucket
                                            || parsedWithdrawAmount <= 0
                                            || selectedBucketAvailable <= 0
                                    "
                                >
                                    Enviar solicitação
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Modal cadastro PIX (abre ao sacar sem chave) -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="showPayoutPixModal && payout_pix_setup"
                    class="fixed inset-0 z-[55] flex items-end justify-center sm:items-center sm:p-4"
                    aria-modal="true"
                    role="dialog"
                    aria-labelledby="payout-pix-modal-title"
                    @keydown.escape="closePayoutPixModal"
                >
                    <div class="absolute inset-0 bg-zinc-900/60 dark:bg-zinc-950/70" aria-hidden="true" @click="closePayoutPixModal" />
                    <div
                        class="relative flex max-h-[92vh] w-full max-w-lg flex-col overflow-hidden rounded-t-2xl border border-zinc-200 bg-white shadow-xl dark:border-zinc-700 dark:bg-zinc-800 sm:max-h-[90vh] sm:rounded-2xl"
                    >
                        <div class="flex shrink-0 items-start justify-between gap-4 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
                            <div class="flex items-start gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--color-primary)]/10 text-[var(--color-primary)]">
                                    <Landmark class="h-5 w-5" aria-hidden="true" />
                                </div>
                                <div>
                                    <h3 id="payout-pix-modal-title" class="text-lg font-semibold text-zinc-900 dark:text-white">
                                        Cadastre sua chave PIX
                                    </h3>
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                        {{
                                            openWithdrawAfterPixSave
                                                ? 'Para solicitar o saque, informe a chave PIX de recebimento.'
                                                : 'Dados usados para receber os saques por PIX.'
                                        }}
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="rounded-lg p-1.5 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                                aria-label="Fechar"
                                @click="closePayoutPixModal"
                            >
                                <X class="h-5 w-5" />
                            </button>
                        </div>
                        <form class="min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain px-5 py-4" @submit.prevent="submitPayoutPix">
                            <template v-if="payout_pix_setup === 'label_and_key'">
                                <p
                                    v-if="!isPixKeyDocumentType"
                                    class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-950 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100"
                                >
                                    Para e-mail, telefone ou chave aleatória, informe o CPF/CNPJ do titular abaixo.
                                </p>
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Identificação</label>
                                    <input v-model="payoutPixForm.label" type="text" required maxlength="120" :class="inputClass" placeholder="Ex.: conta principal" />
                                    <p v-if="payoutPixForm.errors.label" class="mt-1 text-sm text-red-600">{{ payoutPixForm.errors.label }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Tipo da chave</label>
                                    <select v-model="payoutPixForm.pix_key_type" :class="inputClass" @change="onPixKeyTypeChange">
                                        <option value="cpf">CPF</option>
                                        <option value="cnpj">CNPJ</option>
                                        <option value="email">E-mail</option>
                                        <option value="phone">Telefone</option>
                                        <option value="evp">Chave aleatória</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Chave PIX</label>
                                    <input
                                        :value="payoutPixForm.pix_key"
                                        type="text"
                                        required
                                        :maxlength="isPixKeyDocumentType ? (payoutPixForm.pix_key_type === 'cnpj' ? 18 : 14) : 120"
                                        :inputmode="isPixKeyDocumentType ? 'numeric' : 'text'"
                                        :class="inputClass"
                                        autocomplete="off"
                                        :placeholder="
                                            payoutPixForm.pix_key_type === 'cpf'
                                                ? '000.000.000-00'
                                                : payoutPixForm.pix_key_type === 'cnpj'
                                                  ? '00.000.000/0000-00'
                                                  : ''
                                        "
                                        @input="onPixKeyInput"
                                    />
                                    <p v-if="payoutPixForm.errors.pix_key" class="mt-1 text-sm text-red-600">{{ payoutPixForm.errors.pix_key }}</p>
                                </div>
                                <div v-if="!isPixKeyDocumentType">
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">CPF ou CNPJ do titular</label>
                                    <input
                                        :value="payoutPixForm.key_owner_document"
                                        type="text"
                                        required
                                        maxlength="18"
                                        inputmode="numeric"
                                        :class="inputClass"
                                        autocomplete="off"
                                        placeholder="Documento do titular da chave PIX"
                                        @input="onKeyOwnerDocumentInput"
                                    />
                                    <p v-if="payoutPixForm.errors.key_owner_document" class="mt-1 text-sm text-red-600">{{ payoutPixForm.errors.key_owner_document }}</p>
                                </div>
                            </template>
                            <template v-else-if="payout_pix_setup === 'pix_key_only'">
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Tipo da chave</label>
                                    <select v-model="payoutPixForm.pix_key_type" :class="inputClass" @change="onPixKeyTypeChange">
                                        <option value="cpf">CPF</option>
                                        <option value="cnpj">CNPJ</option>
                                        <option value="email">E-mail</option>
                                        <option value="phone">Telefone</option>
                                        <option value="evp">Chave aleatória</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Chave PIX</label>
                                    <input
                                        :value="payoutPixForm.pix_key"
                                        type="text"
                                        required
                                        :maxlength="isPixKeyDocumentType ? (payoutPixForm.pix_key_type === 'cnpj' ? 18 : 14) : 120"
                                        :inputmode="isPixKeyDocumentType ? 'numeric' : 'text'"
                                        :class="inputClass"
                                        autocomplete="off"
                                        @input="onPixKeyInput"
                                    />
                                    <p v-if="payoutPixForm.errors.pix_key" class="mt-1 text-sm text-red-600">{{ payoutPixForm.errors.pix_key }}</p>
                                </div>
                            </template>
                            <template v-else-if="payout_pix_setup === 'key_and_receiver'">
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Tipo da chave</label>
                                    <select v-model="payoutPixForm.pix_key_type" :class="inputClass" @change="onPixKeyTypeChange">
                                        <option value="cpf">CPF</option>
                                        <option value="cnpj">CNPJ</option>
                                        <option value="email">E-mail</option>
                                        <option value="phone">Telefone</option>
                                        <option value="evp">Chave aleatória</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Chave PIX</label>
                                    <input
                                        :value="payoutPixForm.pix_key"
                                        type="text"
                                        required
                                        :maxlength="isPixKeyDocumentType ? (payoutPixForm.pix_key_type === 'cnpj' ? 18 : 14) : 120"
                                        :inputmode="isPixKeyDocumentType ? 'numeric' : 'text'"
                                        :class="inputClass"
                                        autocomplete="off"
                                        @input="onPixKeyInput"
                                    />
                                    <p v-if="payoutPixForm.errors.pix_key" class="mt-1 text-sm text-red-600">{{ payoutPixForm.errors.pix_key }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome do recebedor</label>
                                    <input v-model="payoutPixForm.receiver_name" type="text" required maxlength="120" :class="inputClass" />
                                    <p v-if="payoutPixForm.errors.receiver_name" class="mt-1 text-sm text-red-600">{{ payoutPixForm.errors.receiver_name }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">CPF/CNPJ do recebedor</label>
                                    <input
                                        :value="payoutPixForm.receiver_document"
                                        type="text"
                                        required
                                        maxlength="18"
                                        inputmode="numeric"
                                        :class="inputClass"
                                        @input="onReceiverDocumentInput"
                                    />
                                    <p v-if="payoutPixForm.errors.receiver_document" class="mt-1 text-sm text-red-600">{{ payoutPixForm.errors.receiver_document }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">E-mail do recebedor</label>
                                    <input v-model="payoutPixForm.receiver_email" type="email" required maxlength="255" :class="inputClass" />
                                    <p v-if="payoutPixForm.errors.receiver_email" class="mt-1 text-sm text-red-600">{{ payoutPixForm.errors.receiver_email }}</p>
                                </div>
                            </template>
                            <div class="flex flex-wrap justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                                <Button type="button" variant="outline" @click="closePayoutPixModal">Cancelar</Button>
                                <Button type="submit" :disabled="payoutPixForm.processing">
                                    {{
                                        openWithdrawAfterPixSave
                                            ? payoutPixForm.processing
                                                ? 'Salvando…'
                                                : 'Salvar e continuar saque'
                                            : payoutPixForm.processing
                                              ? 'Salvando…'
                                              : 'Cadastrar chave PIX'
                                    }}
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Modal KYC (auto-abre quando falta enviar documentos) -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="showKycModal && needsKycDocuments"
                    class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center sm:p-4"
                    aria-modal="true"
                    role="dialog"
                    aria-labelledby="kyc-modal-title"
                    @keydown.escape="closeKycModal"
                >
                    <div class="absolute inset-0 bg-zinc-900/60 dark:bg-zinc-950/70" aria-hidden="true" @click="closeKycModal" />
                    <div
                        class="relative flex max-h-[92vh] w-full max-w-lg flex-col overflow-hidden rounded-t-2xl border border-zinc-200 bg-white shadow-xl dark:border-zinc-700 dark:bg-zinc-800 sm:max-h-[90vh] sm:rounded-2xl"
                    >
                        <div class="flex shrink-0 items-start justify-between gap-4 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
                            <div>
                                <h3 id="kyc-modal-title" class="text-lg font-semibold text-zinc-900 dark:text-white">
                                    Verificação de identidade
                                </h3>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    Envie um arquivo por vez e depois conclua o envio para análise.
                                </p>
                            </div>
                            <button
                                type="button"
                                class="rounded-lg p-1.5 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                                aria-label="Fechar"
                                @click="closeKycModal"
                            >
                                <X class="h-5 w-5" />
                            </button>
                        </div>
                        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-4">
                            <KycDocumentsForm
                                embedded
                                :person_type="kyc_person_type"
                                :kyc_status="kyc_status || 'not_submitted'"
                                :rejection_reason="kyc_rejection_reason"
                                :identity_document_type="kyc_identity_document_type"
                                :company_legal_nature="kyc_company_legal_nature"
                                :company_nature_suggestion="kyc_company_nature_suggestion"
                                :uploaded_kinds="kyc_uploaded_kinds"
                                :requirements="kyc_requirements"
                            />
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <!-- Barra fixa no mobile se o modal KYC foi fechado sem enviar -->
        <Teleport to="body">
            <div
                v-if="needsKycDocuments && !showKycModal"
                class="fixed inset-x-0 bottom-0 z-50 border-t border-amber-200 bg-amber-50 p-3 shadow-[0_-8px_24px_rgba(0,0,0,0.12)] dark:border-amber-900/60 dark:bg-amber-950/95 sm:hidden"
            >
                <Button type="button" class="w-full" @click="openKycModal">Continuar verificação KYC</Button>
            </div>
        </Teleport>
    </div>
</template>
