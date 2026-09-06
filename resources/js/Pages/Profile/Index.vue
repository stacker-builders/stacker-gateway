<script setup>
import { ref, computed, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import ProfileTotpSection from '@/components/profile/ProfileTotpSection.vue';
import KycDocumentsForm from '@/components/kyc/KycDocumentsForm.vue';
import { Camera, IdCard, Lock, Loader2, Building2, X } from 'lucide-vue-next';
import axios from 'axios';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    user: {
        type: Object,
        required: true,
    },
    registration: {
        type: Object,
        default: () => ({}),
    },
    totp_enabled: { type: Boolean, default: false },
    pj_conversion: { type: Object, default: null },
    pj_conversion_eligible: { type: Boolean, default: false },
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
    push_preferences: {
        type: Object,
        default: () => ({
            sale_approved: true,
            pix_generated: true,
            boleto_generated: true,
            withdrawal_paid: true,
            affiliate_sale_approved: true,
            coproduction_sale_approved: true,
            affiliate_enrollment_approved: true,
            daily_summary: true,
            system: true,
            show_product_name: true,
            show_sale_amount: true,
            sale_amount_mode: 'gross',
            show_payment_method: true,
        }),
    },
});

const avatarInputRef = ref(null);
const avatarPreview = ref(null);

const profileForm = useForm({
    name: props.user.name,
    trade_name: props.user.trade_name ?? '',
    username: props.user.username ?? '',
    avatar: null,
});

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const pushForm = useForm({
    sale_approved: !!props.push_preferences.sale_approved,
    pix_generated: !!props.push_preferences.pix_generated,
    boleto_generated: !!props.push_preferences.boleto_generated,
    withdrawal_paid: !!props.push_preferences.withdrawal_paid,
    affiliate_sale_approved: !!props.push_preferences.affiliate_sale_approved,
    coproduction_sale_approved: props.push_preferences.coproduction_sale_approved !== false,
    affiliate_enrollment_approved: !!props.push_preferences.affiliate_enrollment_approved,
    daily_summary: !!props.push_preferences.daily_summary,
    system: !!props.push_preferences.system,
    show_product_name: !!props.push_preferences.show_product_name,
    show_sale_amount: !!props.push_preferences.show_sale_amount,
    sale_amount_mode: props.push_preferences.sale_amount_mode === 'net' ? 'net' : 'gross',
    show_payment_method: !!props.push_preferences.show_payment_method,
});

function submitPushPreferences() {
    pushForm.put('/meu-perfil/preferencias-push', { preserveScroll: true });
}

function setSaleAmountMode(mode) {
    pushForm.sale_amount_mode = mode === 'net' ? 'net' : 'gross';
    pushForm.show_sale_amount = true;
}

const avatarUrl = computed(() => {
    if (avatarPreview.value) return avatarPreview.value;
    return props.user.avatar_url || null;
});

function triggerAvatarClick() {
    avatarInputRef.value?.click();
}

function onAvatarChange(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    profileForm.avatar = file;
    const reader = new FileReader();
    reader.onload = (e) => { avatarPreview.value = e.target?.result; };
    reader.readAsDataURL(file);
}

function submitProfile() {
    profileForm.post('/meu-perfil', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            profileForm.avatar = null;
            avatarPreview.value = null;
        },
    });
}

function submitPassword() {
    passwordForm.put('/meu-perfil/senha', {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
}

function snap(value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    return value;
}

function formatDateTime(iso) {
    if (!iso) {
        return '—';
    }
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return '—';
    }
    return date.toLocaleString('pt-BR');
}

const documentLabel = computed(() => (props.registration?.person_type === 'pj' ? 'CNPJ' : 'CPF'));

const conversionCollecting = computed(() => props.pj_conversion?.status === 'collecting_docs');
const conversionPending = computed(() => props.pj_conversion?.status === 'pending_review');
const conversionRejected = computed(() => props.pj_conversion?.status === 'rejected');
const showMigrateButton = computed(
    () =>
        props.pj_conversion_eligible &&
        props.registration?.person_type === 'pf' &&
        !conversionCollecting.value &&
        !conversionPending.value
);

const showConversionModal = ref(false);
const cnpjLookupLoading = ref(false);
const cnpjSituacaoWarning = ref('');
let cnpjLookupTimer = null;
let cnpjLookupSeq = 0;

const conversionForm = useForm({
    cnpj: '',
    company_name: '',
    company_legal_nature: '',
    cnpj_suggested_razao_social: '',
});

function digitsOnly(value) {
    return String(value || '').replace(/\D/g, '');
}

function formatCnpjInput(value) {
    const d = digitsOnly(value).slice(0, 14);
    if (d.length <= 2) return d;
    if (d.length <= 5) return `${d.slice(0, 2)}.${d.slice(2)}`;
    if (d.length <= 8) return `${d.slice(0, 2)}.${d.slice(2, 5)}.${d.slice(5)}`;
    if (d.length <= 12) return `${d.slice(0, 2)}.${d.slice(2, 5)}.${d.slice(5, 8)}/${d.slice(8)}`;
    return `${d.slice(0, 2)}.${d.slice(2, 5)}.${d.slice(5, 8)}/${d.slice(8, 12)}-${d.slice(12)}`;
}

function onCnpjInput(event) {
    conversionForm.cnpj = formatCnpjInput(event.target.value);
}

async function lookupConversionCnpj(cnpj) {
    const seq = ++cnpjLookupSeq;
    cnpjLookupLoading.value = true;
    cnpjSituacaoWarning.value = '';
    try {
        const res = await axios.post('/meu-perfil/migrar-para-cnpj/consultar', { document: cnpj });
        if (seq !== cnpjLookupSeq) {
            return;
        }
        if (!res.data?.ok) {
            conversionForm.cnpj_suggested_razao_social = '';
            return;
        }
        const razao = String(res.data.razao_social || '').trim();
        conversionForm.cnpj_suggested_razao_social = razao;
        if (razao && !String(conversionForm.company_name || '').trim()) {
            conversionForm.company_name = razao;
        }
        if (res.data.company_nature_suggestion && !conversionForm.company_legal_nature) {
            conversionForm.company_legal_nature = res.data.company_nature_suggestion;
        }
        if (res.data.situacao_irregular && res.data.situacao_message) {
            cnpjSituacaoWarning.value = res.data.situacao_message;
        }
    } catch (e) {
        if (seq !== cnpjLookupSeq) {
            return;
        }
        if (e.response?.status === 422) {
            conversionForm.errors.cnpj = e.response.data?.message || 'CNPJ inválido.';
        }
    } finally {
        if (seq === cnpjLookupSeq) {
            cnpjLookupLoading.value = false;
        }
    }
}

watch(
    () => digitsOnly(conversionForm.cnpj),
    (doc) => {
        if (cnpjLookupTimer) {
            clearTimeout(cnpjLookupTimer);
            cnpjLookupTimer = null;
        }
        conversionForm.errors.cnpj = '';
        if (doc.length !== 14) {
            return;
        }
        cnpjLookupTimer = setTimeout(() => lookupConversionCnpj(doc), 400);
    }
);

function openConversionModal() {
    conversionForm.cnpj = '';
    conversionForm.company_name = '';
    conversionForm.company_legal_nature = '';
    conversionForm.cnpj_suggested_razao_social = '';
    conversionForm.clearErrors();
    cnpjSituacaoWarning.value = '';
    showConversionModal.value = true;
}

function closeConversionModal() {
    showConversionModal.value = false;
}

function submitConversionStart() {
    conversionForm.post('/meu-perfil/migrar-para-cnpj', {
        preserveScroll: true,
        onSuccess: () => {
            showConversionModal.value = false;
        },
    });
}

function cancelConversion() {
    if (!confirm('Cancelar a migração para CNPJ? Sua conta continua como pessoa física.')) {
        return;
    }
    router.post('/meu-perfil/migrar-para-cnpj/cancelar', {}, { preserveScroll: true });
}
</script>

<template>
    <div class="mx-auto max-w-2xl space-y-8">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                Meu perfil
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Atualize sua foto, nome, empresa e senha. E-mail e CPF/CNPJ não podem ser alterados.
            </p>
        </div>

        <!-- Card: Foto e dados -->
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
            <div class="p-6 sm:p-8">
                <div class="flex flex-col items-center gap-6 sm:flex-row sm:items-start">
                    <div class="relative shrink-0">
                        <button
                            type="button"
                            class="group relative flex h-28 w-28 overflow-hidden rounded-2xl bg-zinc-100 ring-2 ring-zinc-200 transition-all hover:ring-[var(--color-primary)] dark:bg-zinc-700 dark:ring-zinc-600 dark:hover:ring-[var(--color-primary)]"
                            @click="triggerAvatarClick"
                        >
                            <img
                                v-if="avatarUrl"
                                :src="avatarUrl"
                                alt="Foto de perfil"
                                class="h-full w-full object-cover"
                            />
                            <span
                                v-else
                                class="flex h-full w-full items-center justify-center text-3xl font-semibold text-zinc-400 dark:text-zinc-500"
                            >
                                {{ (user.name || '?').charAt(0).toUpperCase() }}
                            </span>
                            <span
                                class="absolute inset-0 flex items-center justify-center bg-zinc-900/50 opacity-0 transition-opacity group-hover:opacity-100"
                            >
                                <Camera class="h-8 w-8 text-white" />
                            </span>
                        </button>
                        <input
                            ref="avatarInputRef"
                            type="file"
                            accept="image/*"
                            class="sr-only"
                            @change="onAvatarChange"
                        />
                        <p v-if="profileForm.errors.avatar" class="mt-2 max-w-xs text-center text-sm text-red-600 dark:text-red-400">
                            {{ profileForm.errors.avatar }}
                        </p>
                    </div>
                    <form
                        class="min-w-0 flex-1 space-y-4"
                        @submit.prevent="submitProfile"
                    >
                        <div>
                            <label
                                for="profile-name"
                                class="block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                            >
                                Nome
                            </label>
                            <input
                                id="profile-name"
                                v-model="profileForm.name"
                                type="text"
                                required
                                maxlength="255"
                                class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                                placeholder="Seu nome"
                            />
                            <p
                                v-if="profileForm.errors.name"
                                class="mt-1 text-sm text-red-600 dark:text-red-400"
                            >
                                {{ profileForm.errors.name }}
                            </p>
                        </div>
                        <div>
                            <label
                                for="profile-trade-name"
                                class="block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                            >
                                Empresa
                            </label>
                            <input
                                id="profile-trade-name"
                                v-model="profileForm.trade_name"
                                type="text"
                                maxlength="255"
                                autocomplete="organization"
                                class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                                placeholder="Nome comercial"
                            />
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                Nome comercial usado no aviso do checkout (variável {empresa}), quando o aviso estiver ativo.
                            </p>
                            <p
                                v-if="profileForm.errors.trade_name"
                                class="mt-1 text-sm text-red-600 dark:text-red-400"
                            >
                                {{ profileForm.errors.trade_name }}
                            </p>
                        </div>
                        <div>
                            <label
                                for="profile-email"
                                class="block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                            >
                                E-mail
                            </label>
                            <input
                                id="profile-email"
                                :value="user.email"
                                type="email"
                                disabled
                                readonly
                                autocomplete="email"
                                class="mt-1.5 block w-full cursor-not-allowed rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-2.5 text-zinc-500 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/40 dark:text-zinc-400"
                            />
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                O e-mail de cadastro não pode ser alterado.
                            </p>
                        </div>
                        <div>
                            <label
                                for="profile-username"
                                class="block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                            >
                                @username (para conquistas compartilhadas)
                            </label>
                            <input
                                id="profile-username"
                                v-model="profileForm.username"
                                type="text"
                                maxlength="64"
                                class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                                placeholder="meunome"
                            />
                            <p
                                v-if="profileForm.errors.username"
                                class="mt-1 text-sm text-red-600 dark:text-red-400"
                            >
                                {{ profileForm.errors.username }}
                            </p>
                        </div>
                        <Button
                            type="submit"
                            class="w-full sm:w-auto"
                            :disabled="profileForm.processing"
                        >
                            <Loader2
                                v-if="profileForm.processing"
                                class="mr-2 h-4 w-4 animate-spin"
                            />
                            Salvar alterações
                        </Button>
                    </form>
                </div>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
            <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700 sm:px-8">
                <div class="flex items-center gap-2">
                    <span
                        class="flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--color-primary)]/10 text-[var(--color-primary)]"
                    >
                        <IdCard class="h-4 w-4" />
                    </span>
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                            Dados de cadastro
                        </h2>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            E-mail e CPF/CNPJ não são editáveis.
                        </p>
                    </div>
                </div>
            </div>
            <dl class="grid gap-4 p-6 text-sm sm:grid-cols-2 sm:p-8">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Nome</dt>
                    <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ snap(registration.name) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">E-mail</dt>
                    <dd class="mt-0.5 break-all text-zinc-900 dark:text-white">{{ snap(registration.email) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">WhatsApp</dt>
                    <dd class="mt-0.5 flex flex-wrap items-center gap-2 text-zinc-900 dark:text-white">
                        <span>{{ snap(registration.whatsapp || registration.phone) }}</span>
                        <a
                            v-if="registration.whatsapp_url"
                            :href="registration.whatsapp_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center rounded-lg bg-emerald-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-emerald-500"
                        >
                            Abrir WhatsApp
                        </a>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ documentLabel }}</dt>
                    <dd class="mt-0.5 font-mono text-zinc-900 dark:text-white">{{ snap(registration.document) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Nascimento</dt>
                    <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ snap(registration.birth_date) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Cadastrado em</dt>
                    <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ formatDateTime(registration.created_at) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">KYC revisado em</dt>
                    <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ formatDateTime(registration.kyc_reviewed_at) }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">Endereço</dt>
                    <dd class="mt-0.5 text-zinc-900 dark:text-white">{{ snap(registration.address_line) }}</dd>
                </div>
                <div
                    v-if="registration.person_type === 'pj' && registration.legal_representative_cpf"
                    class="sm:col-span-2"
                >
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">CPF do responsável</dt>
                    <dd class="mt-0.5 font-mono text-zinc-900 dark:text-white">{{ snap(registration.legal_representative_cpf) }}</dd>
                </div>
            </dl>

            <div
                v-if="conversionPending"
                class="border-t border-amber-200 bg-amber-50 px-6 py-4 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100 sm:px-8"
            >
                <p class="font-medium">Migração para CNPJ em análise</p>
                <p class="mt-1 text-xs leading-relaxed">
                    CNPJ {{ pj_conversion?.cnpj_formatted || pj_conversion?.cnpj }}.
                    Sua conta CPF continua operando normalmente até a aprovação.
                </p>
            </div>
            <div
                v-else-if="conversionRejected"
                class="border-t border-red-200 bg-red-50 px-6 py-4 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200 sm:px-8"
            >
                <p class="font-medium">Migração para CNPJ rejeitada</p>
                <p v-if="pj_conversion?.rejection_reason" class="mt-1 text-xs">{{ pj_conversion.rejection_reason }}</p>
                <Button type="button" size="sm" class="mt-3" @click="openConversionModal">Tentar novamente</Button>
            </div>
            <div
                v-else-if="showMigrateButton"
                class="border-t border-zinc-200 px-6 py-4 dark:border-zinc-700 sm:px-8"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-medium text-zinc-900 dark:text-white">Migrar operação para CNPJ</p>
                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                            Seu CPF permanece como responsável legal. A conta continua operando enquanto a nova verificação é analisada.
                        </p>
                    </div>
                    <Button type="button" variant="outline" class="inline-flex shrink-0 items-center gap-2" @click="openConversionModal">
                        <Building2 class="h-4 w-4" />
                        Migrar para CNPJ
                    </Button>
                </div>
            </div>
            <div
                v-else-if="conversionCollecting"
                class="space-y-4 border-t border-zinc-200 px-6 py-5 dark:border-zinc-700 sm:px-8"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm font-medium text-zinc-900 dark:text-white">Envie os documentos da empresa</p>
                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                            CNPJ {{ pj_conversion?.cnpj_formatted || pj_conversion?.cnpj }}
                            <span v-if="pj_conversion?.company_name"> — {{ pj_conversion.company_name }}</span>.
                            A chave PIX não muda automaticamente; atualize em Financeiro depois da aprovação, se precisar.
                        </p>
                    </div>
                    <Button type="button" variant="ghost" size="sm" @click="cancelConversion">Cancelar migração</Button>
                </div>
                <KycDocumentsForm
                    embedded
                    conversion_mode
                    :conversion_status="pj_conversion?.status"
                    :person_type="'pj'"
                    :kyc_status="registration.kyc_status || 'approved'"
                    :identity_document_type="kyc_identity_document_type"
                    :company_legal_nature="pj_conversion?.company_legal_nature || kyc_company_legal_nature"
                    :company_nature_suggestion="kyc_company_nature_suggestion"
                    :uploaded_kinds="kyc_uploaded_kinds"
                    :requirements="kyc_requirements"
                />
            </div>
        </div>

        <!-- Card: Alterar senha -->
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
            <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700 sm:px-8">
                <div class="flex items-center gap-2">
                    <span
                        class="flex h-9 w-9 items-center justify-center rounded-lg bg-[var(--color-primary)]/10 text-[var(--color-primary)]"
                    >
                        <Lock class="h-4 w-4" />
                    </span>
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        Alterar senha
                    </h2>
                </div>
            </div>
            <form
                class="space-y-4 p-6 sm:p-8"
                @submit.prevent="submitPassword"
            >
                <div>
                    <label
                        for="current-password"
                        class="block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Senha atual
                    </label>
                    <input
                        id="current-password"
                        v-model="passwordForm.current_password"
                        type="password"
                        required
                        autocomplete="current-password"
                        class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                        placeholder="Digite sua senha atual"
                    />
                    <p
                        v-if="passwordForm.errors.current_password"
                        class="mt-1 text-sm text-red-600 dark:text-red-400"
                    >
                        {{ passwordForm.errors.current_password }}
                    </p>
                </div>
                <div>
                    <label
                        for="new-password"
                        class="block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Nova senha
                    </label>
                    <input
                        id="new-password"
                        v-model="passwordForm.password"
                        type="password"
                        required
                        autocomplete="new-password"
                        class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                        placeholder="Mínimo 8 caracteres"
                    />
                    <p
                        v-if="passwordForm.errors.password"
                        class="mt-1 text-sm text-red-600 dark:text-red-400"
                    >
                        {{ passwordForm.errors.password }}
                    </p>
                </div>
                <div>
                    <label
                        for="confirm-password"
                        class="block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Confirmar nova senha
                    </label>
                    <input
                        id="confirm-password"
                        v-model="passwordForm.password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                        class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                        placeholder="Repita a nova senha"
                    />
                </div>
                <Button
                    type="submit"
                    variant="outline"
                    class="w-full sm:w-auto"
                    :disabled="passwordForm.processing"
                >
                    <Loader2
                        v-if="passwordForm.processing"
                        class="mr-2 h-4 w-4 animate-spin"
                    />
                    Alterar senha
                </Button>
            </form>
        </div>

        <ProfileTotpSection
            :totp_enabled="totp_enabled"
            begin-url="/seguranca/totp/iniciar"
            confirm-url="/seguranca/totp/confirmar"
            disable-url="/seguranca/totp/desativar"
            context="seller"
        />

        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Preferências de notificações push</h2>
            <p class="mt-1 text-sm text-zinc-500">
                Vale para todos os dispositivos inscritos. Dados pessoais do comprador nunca são enviados no push.
            </p>
            <form class="mt-4 space-y-4" @submit.prevent="submitPushPreferences">
                <div>
                    <p class="mb-2 text-sm font-medium">Avisos de venda</p>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.sale_approved" type="checkbox" class="rounded" /> Venda aprovada</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.pix_generated" type="checkbox" class="rounded" /> PIX gerado</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.boleto_generated" type="checkbox" class="rounded" /> Boleto gerado</label>
                    </div>
                </div>
                <div class="rounded-xl border border-zinc-100 bg-zinc-50/70 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                    <p class="mb-1 text-sm font-medium">Dados na notificação</p>
                    <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">
                        A mesma escolha vale para Venda aprovada, PIX gerado e Boleto gerado.
                    </p>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.show_product_name" type="checkbox" class="rounded" /> Nome do produto</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.show_sale_amount" type="checkbox" class="rounded" /> Valor</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.show_payment_method" type="checkbox" class="rounded" /> Forma de pagamento</label>
                    </div>
                    <div
                        v-if="pushForm.show_sale_amount"
                        class="mt-3 rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-600 dark:bg-zinc-800"
                    >
                        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Qual valor exibir
                        </p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label
                                class="flex cursor-pointer items-start gap-2 rounded-lg border px-3 py-2 text-sm transition"
                                :class="
                                    pushForm.sale_amount_mode === 'gross'
                                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/5 text-zinc-900 dark:text-white'
                                        : 'border-zinc-200 bg-white text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200'
                                "
                            >
                                <input
                                    type="radio"
                                    name="sale_amount_mode"
                                    class="mt-0.5"
                                    :checked="pushForm.sale_amount_mode === 'gross'"
                                    @change="setSaleAmountMode('gross')"
                                />
                                <span>
                                    <span class="font-medium">Valor bruto</span>
                                    <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">Total pago pelo cliente</span>
                                </span>
                            </label>
                            <label
                                class="flex cursor-pointer items-start gap-2 rounded-lg border px-3 py-2 text-sm transition"
                                :class="
                                    pushForm.sale_amount_mode === 'net'
                                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/5 text-zinc-900 dark:text-white'
                                        : 'border-zinc-200 bg-white text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200'
                                "
                            >
                                <input
                                    type="radio"
                                    name="sale_amount_mode"
                                    class="mt-0.5"
                                    :checked="pushForm.sale_amount_mode === 'net'"
                                    @change="setSaleAmountMode('net')"
                                />
                                <span>
                                    <span class="font-medium">Valor líquido</span>
                                    <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">Após taxas da plataforma</span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
                <div>
                    <p class="mb-2 text-sm font-medium">Outros avisos</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.withdrawal_paid" type="checkbox" class="rounded" /> Saque pago</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.affiliate_sale_approved" type="checkbox" class="rounded" /> Comissão de afiliado</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.coproduction_sale_approved" type="checkbox" class="rounded" /> Comissão de co-produção</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.affiliate_enrollment_approved" type="checkbox" class="rounded" /> Afiliação aprovada</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.daily_summary" type="checkbox" class="rounded" /> Resumo diário de vendas</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="pushForm.system" type="checkbox" class="rounded" /> Comunicados administrativos</label>
                    </div>
                </div>
                <Button type="submit" :disabled="pushForm.processing">
                    {{ pushForm.processing ? 'Salvando…' : 'Salvar preferências' }}
                </Button>
            </form>
        </div>

        <Teleport to="body">
            <div
                v-if="showConversionModal"
                class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4"
                @click.self="closeConversionModal"
            >
                <div class="max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-t-2xl border border-zinc-200 bg-white p-5 shadow-xl dark:border-zinc-700 dark:bg-zinc-900 sm:rounded-2xl">
                    <div class="mb-4 flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Migrar para CNPJ</h3>
                            <p class="mt-1 text-xs text-zinc-500">
                                Informe o CNPJ da empresa. Seu CPF atual continua como responsável legal. A operação não é interrompida.
                            </p>
                        </div>
                        <button type="button" class="rounded-lg p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Fechar" @click="closeConversionModal">
                            <X class="h-5 w-5" />
                        </button>
                    </div>
                    <form class="space-y-4" @submit.prevent="submitConversionStart">
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">CNPJ</label>
                            <input
                                :value="conversionForm.cnpj"
                                type="text"
                                inputmode="numeric"
                                autocomplete="off"
                                maxlength="18"
                                class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 font-mono text-zinc-900 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                placeholder="00.000.000/0000-00"
                                @input="onCnpjInput"
                            />
                            <p v-if="cnpjLookupLoading" class="mt-1 text-xs text-zinc-500">Consultando Receita…</p>
                            <p v-if="conversionForm.errors.cnpj" class="mt-1 text-sm text-red-600">{{ conversionForm.errors.cnpj }}</p>
                            <p v-if="cnpjSituacaoWarning" class="mt-1 text-xs text-amber-800 dark:text-amber-200">{{ cnpjSituacaoWarning }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Razão social</label>
                            <input
                                v-model="conversionForm.company_name"
                                type="text"
                                maxlength="255"
                                class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-zinc-900 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                            />
                            <p v-if="conversionForm.errors.company_name" class="mt-1 text-sm text-red-600">{{ conversionForm.errors.company_name }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Natureza jurídica</label>
                            <select
                                v-model="conversionForm.company_legal_nature"
                                class="mt-1.5 block w-full rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-zinc-900 shadow-sm focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                            >
                                <option value="" disabled>Selecione…</option>
                                <option value="mei">MEI — Certificado CCMEI</option>
                                <option value="other">Demais empresas — Contrato social</option>
                            </select>
                            <p v-if="conversionForm.errors.company_legal_nature" class="mt-1 text-sm text-red-600">{{ conversionForm.errors.company_legal_nature }}</p>
                        </div>
                        <p v-if="conversionForm.errors.pj_conversion" class="text-sm text-red-600">{{ conversionForm.errors.pj_conversion }}</p>
                        <p class="text-xs text-zinc-500">
                            Depois você envia comprovante de endereço da empresa e CCMEI ou contrato social. A chave PIX cadastrada não muda sozinha.
                        </p>
                        <div class="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="outline" @click="closeConversionModal">Cancelar</Button>
                            <Button type="submit" :disabled="conversionForm.processing">
                                {{ conversionForm.processing ? 'Salvando…' : 'Continuar' }}
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </div>
</template>
