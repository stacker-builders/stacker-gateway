<script setup>
import { ref, computed, watch, watchEffect, onMounted, nextTick } from 'vue';
import { useForm, Link, usePage } from '@inertiajs/vue3';
import { User, Building2, ChevronLeft } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import AuthTurnstileField from '@/components/auth/AuthTurnstileField.vue';
import AuthPageShell from '@/components/auth/AuthPageShell.vue';
import LegalFooterLinks from '@/components/legal/LegalFooterLinks.vue';
import { useAuthBranding } from '@/composables/useAuthBranding';
import { useLoginTemplate } from '@/composables/useLoginTemplate';

const page = usePage();
const { primary, appName } = useAuthBranding();
const { isSpotlight, isImmersive, isModernLogin } = useLoginTemplate();

const props = defineProps({
    revenue_ranges: { type: Array, default: () => [] },
    coproducer_invite: { type: String, default: '' },
    referral_ref: { type: String, default: '' },
    upgrade_from_customer: { type: Boolean, default: false },
    registration_turnstile: { type: Object, default: () => ({ enabled: false, site_key: '' }) },
});

const step = ref(1);
const totalSteps = 5;
const emailCheckMsg = ref('');
const cepLoading = ref(false);
/** Aviso informativo após consulta ViaCEP (não bloqueia; endereço pode ser manual). */
const cepLookupHint = ref('');
let cepFetchTimer = null;
let cepAbortController = null;
/** Erro de validação apenas do passo atual (avanço Continuar / Enter). */
const wizardStepError = ref('');

const errorStepByField = {
    name: 1,
    email: 1,
    phone: 1,
    birth_date: 1,
    document: 2,
    company_name: 2,
    legal_representative_cpf: 2,
    address_zip: 3,
    address_street: 3,
    address_number: 3,
    address_complement: 3,
    address_neighborhood: 3,
    address_city: 3,
    address_state: 3,
    monthly_revenue_range: 4,
    password: 5,
    password_confirmation: 5,
    accept_terms_privacy: 5,
    turnstile_token: 5,
};

function firstServerErrorMessage(errors) {
    const value = Object.values(errors || {}).find((msg) => String(msg || '').trim() !== '');
    return value ? String(value) : '';
}

function revealServerErrors(errors) {
    const keys = Object.keys(errors || {});
    const stepForError = keys.map((key) => errorStepByField[key]).find((n) => n);
    if (stepForError) {
        step.value = stepForError;
    }
    const message = firstServerErrorMessage(errors);
    if (message) {
        nextTick(() => {
            wizardStepError.value = message;
        });
    }
}

const cnpjLookupLoading = ref(false);
const cnpjSituacaoWarning = ref('');
const cnpjOfficialRazaoHint = ref('');
let cnpjLookupTimer = null;
let cnpjLookupSeq = 0;

function digitsOnly(s) {
    return String(s || '').replace(/\D/g, '');
}

/** Mesma lógica que `App\Support\BrazilianDocuments` (dígitos verificadores). */
function isValidCpfJs(d) {
    const cpf = digitsOnly(d);
    if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) {
        return false;
    }
    for (let t = 9; t < 11; t++) {
        let sum = 0;
        for (let i = 0; i < t; i++) {
            sum += parseInt(cpf[i], 10) * (t + 1 - i);
        }
        let r = (sum * 10) % 11;
        if (r === 10) {
            r = 0;
        }
        if (r !== parseInt(cpf[t], 10)) {
            return false;
        }
    }
    return true;
}

function isValidCnpjJs(d) {
    const cnpj = digitsOnly(d);
    if (cnpj.length !== 14 || /^(\d)\1{13}$/.test(cnpj)) {
        return false;
    }
    const w1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    let sum = 0;
    for (let i = 0; i < 12; i++) {
        sum += parseInt(cnpj[i], 10) * w1[i];
    }
    let r = sum % 11;
    const dv1 = r < 2 ? 0 : 11 - r;
    if (dv1 !== parseInt(cnpj[12], 10)) {
        return false;
    }
    const w2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    sum = 0;
    for (let i = 0; i < 13; i++) {
        sum += parseInt(cnpj[i], 10) * w2[i];
    }
    r = sum % 11;
    const dv2 = r < 2 ? 0 : 11 - r;
    return dv2 === parseInt(cnpj[13], 10);
}

function isAdultBirthDate(dateStr) {
    if (!dateStr) {
        return false;
    }
    const d = new Date(`${dateStr}T12:00:00`);
    if (Number.isNaN(d.getTime())) {
        return false;
    }
    const limit = new Date();
    limit.setFullYear(limit.getFullYear() - 18);
    return d <= limit;
}

function isValidEmailFormat(email) {
    const e = String(email || '').trim();
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e);
}

/**
 * Valida só o passo atual. Retorna true se pode avançar ou enviar.
 * Passo 1 consulta o e-mail no servidor (evita avançar sem blur no campo).
 */
async function validateCurrentStep() {
    wizardStepError.value = '';
    const s = step.value;

    if (s === 1) {
        if (!String(form.name || '').trim()) {
            wizardStepError.value = 'Informe seu nome completo.';
            return false;
        }
        if (!isValidEmailFormat(form.email)) {
            wizardStepError.value = 'Informe um e-mail válido.';
            return false;
        }
        const phoneDigits = digitsOnly(form.phone);
        if (phoneDigits.length < 10 || phoneDigits.length > 11) {
            wizardStepError.value = 'Informe um WhatsApp válido com DDD.';
            return false;
        }
        if (!form.birth_date) {
            wizardStepError.value = 'Informe a data de nascimento.';
            return false;
        }
        if (!isAdultBirthDate(form.birth_date)) {
            wizardStepError.value = 'É necessário ter pelo menos 18 anos.';
            return false;
        }
        const email = String(form.email || '').trim();
        try {
            const res = await window.axios.post('/cadastro/validar-email', { email });
            if (!res.data?.available) {
                emailCheckMsg.value = 'Este e-mail já está em uso.';
                wizardStepError.value = 'Este e-mail já está em uso. Escolha outro.';
                return false;
            }
            emailCheckMsg.value = '';
        } catch {
            /* não bloqueia se a checagem falhar (rede); o backend valida no POST final */
        }
        return true;
    }

    if (s === 2) {
        const doc = digitsOnly(form.document);
        if (form.person_type === 'pf') {
            if (!isValidCpfJs(doc)) {
                wizardStepError.value = 'CPF inválido. Confira os números.';
                return false;
            }
        } else {
            if (!isValidCnpjJs(doc)) {
                wizardStepError.value = 'CNPJ inválido. Confira os números.';
                return false;
            }
            if (!String(form.company_name || '').trim()) {
                wizardStepError.value = 'Informe a razão social.';
                return false;
            }
            const rep = digitsOnly(form.legal_representative_cpf);
            if (!isValidCpfJs(rep)) {
                wizardStepError.value = 'CPF do representante legal inválido.';
                return false;
            }
        }
        try {
            const payload = {
                person_type: form.person_type,
                document: doc,
                legal_representative_cpf: form.person_type === 'pj' ? digitsOnly(form.legal_representative_cpf) : null,
            };
            const res = await window.axios.post('/cadastro/validar-documento', payload);
            if (!res.data?.available) {
                wizardStepError.value = res.data?.message || 'Documento não disponível para cadastro.';
                return false;
            }
        } catch (e) {
            const msg = e.response?.data?.message;
            if (msg) {
                wizardStepError.value = msg;
                return false;
            }
            /* rede: não bloqueia; o servidor valida de novo no POST */
        }
        return true;
    }

    if (s === 3) {
        const cep = digitsOnly(form.address_zip);
        if (cep.length !== 8) {
            wizardStepError.value = 'Informe o CEP com 8 dígitos.';
            return false;
        }
        if (!String(form.address_street || '').trim()) {
            wizardStepError.value = 'Informe o logradouro.';
            return false;
        }
        if (!String(form.address_number || '').trim()) {
            wizardStepError.value = 'Informe o número.';
            return false;
        }
        if (!String(form.address_neighborhood || '').trim()) {
            wizardStepError.value = 'Informe o bairro.';
            return false;
        }
        if (!String(form.address_city || '').trim()) {
            wizardStepError.value = 'Informe a cidade.';
            return false;
        }
        const uf = String(form.address_state || '').trim().toUpperCase();
        if (uf.length !== 2) {
            wizardStepError.value = 'Informe a UF com 2 letras.';
            return false;
        }
        return true;
    }

    if (s === 4) {
        if (!form.monthly_revenue_range) {
            wizardStepError.value = 'Selecione uma faixa de faturamento mensal.';
            return false;
        }
        return true;
    }

    if (s === 5) {
        const pwd = String(form.password || '');
        const conf = String(form.password_confirmation || '');
        if (pwd.length < 8) {
            wizardStepError.value = 'A senha deve ter no mínimo 8 caracteres.';
            return false;
        }
        if (pwd !== conf) {
            wizardStepError.value = 'A confirmação da senha não confere.';
            return false;
        }
        if (!form.accept_terms_privacy) {
            wizardStepError.value = 'Você precisa aceitar os Termos de Uso e a Política de Privacidade.';
            return false;
        }
        return true;
    }

    return true;
}

watch(step, () => {
    wizardStepError.value = '';
});

const turnstileToken = ref('');

const form = useForm({
    person_type: 'pf',
    name: '',
    email: '',
    phone: '',
    coproducer_invite: props.coproducer_invite || '',
    ref: props.referral_ref || '',
    birth_date: '',
    document: '',
    company_name: '',
    legal_representative_cpf: '',
    cnpj_suggested_razao_social: '',
    address_zip: '',
    address_street: '',
    address_number: '',
    address_complement: '',
    address_neighborhood: '',
    address_city: '',
    address_state: '',
    monthly_revenue_range: '',
    password: '',
    password_confirmation: '',
    accept_terms_privacy: false,
    turnstile_token: '',
    website: '',
});

const flashError = computed(() => String(page.props.flash?.error || '').trim());

const firstFormError = computed(() => firstServerErrorMessage(form.errors));

/** Aviso sempre visível na etapa atual: validação local, erro de campo ou flash (ex.: rate limit). */
const registrationAlert = computed(
    () => wizardStepError.value || firstFormError.value || flashError.value
);

onMounted(() => {
    if (!props.upgrade_from_customer) return;
    const u = page.props.auth?.user;
    if (!u) return;
    if (!String(form.name || '').trim()) {
        form.name = u.name || '';
    }
    if (!String(form.email || '').trim()) {
        form.email = u.email || '';
    }
});

const stepTitle = computed(() => {
    const titles = {
        1: 'Dados básicos',
        2: form.person_type === 'pf' ? 'CPF' : 'CNPJ e empresa',
        3: 'Endereço',
        4: 'Faturamento mensal',
        5: 'Senha',
    };
    return titles[step.value] ?? '';
});

const progressPct = computed(() => (step.value / totalSteps) * 100);

watch(
    () => form.email,
    () => {
        emailCheckMsg.value = '';
    }
);

async function checkEmailBlur() {
    const email = (form.email || '').trim();
    if (!email || !email.includes('@')) return;
    try {
        const res = await window.axios.post('/cadastro/validar-email', { email });
        emailCheckMsg.value = res.data?.available
            ? ''
            : (res.data?.message || 'Este e-mail já está em uso.');
    } catch {
        emailCheckMsg.value = '';
    }
}

function resetCnpjLookupUi() {
    cnpjLookupLoading.value = false;
    cnpjSituacaoWarning.value = '';
    cnpjOfficialRazaoHint.value = '';
    form.cnpj_suggested_razao_social = '';
}

async function lookupCnpjFromWizard(cnpj) {
    const seq = ++cnpjLookupSeq;
    cnpjLookupLoading.value = true;
    cnpjSituacaoWarning.value = '';
    cnpjOfficialRazaoHint.value = '';
    try {
        const res = await window.axios.post('/cadastro/consultar-cnpj', { document: cnpj });
        if (seq !== cnpjLookupSeq) {
            return;
        }
        if (!res.data?.ok) {
            form.cnpj_suggested_razao_social = '';
            return;
        }
        const razao = String(res.data.razao_social || '').trim();
        form.cnpj_suggested_razao_social = razao;
        if (razao && !String(form.company_name || '').trim()) {
            form.company_name = razao;
        } else if (razao && String(form.company_name || '').trim() && String(form.company_name).trim() !== razao) {
            cnpjOfficialRazaoHint.value = `Na Receita: ${razao}`;
        }
        if (res.data.situacao_irregular && res.data.situacao_message) {
            cnpjSituacaoWarning.value = res.data.situacao_message;
        }
    } catch (e) {
        if (seq !== cnpjLookupSeq) {
            return;
        }
        if (e.response?.status === 422) {
            return;
        }
        form.cnpj_suggested_razao_social = '';
    } finally {
        if (seq === cnpjLookupSeq) {
            cnpjLookupLoading.value = false;
        }
    }
}

watch(
    () => [form.person_type, digitsOnly(form.document)],
    ([type, doc]) => {
        if (cnpjLookupTimer) {
            clearTimeout(cnpjLookupTimer);
            cnpjLookupTimer = null;
        }
        if (type !== 'pj') {
            resetCnpjLookupUi();
            return;
        }
        if (!isValidCnpjJs(doc)) {
            resetCnpjLookupUi();
            return;
        }
        cnpjLookupTimer = setTimeout(() => {
            lookupCnpjFromWizard(doc);
        }, 400);
    }
);

async function fetchCep() {
    const raw = digitsOnly(form.address_zip);
    if (raw.length !== 8) {
        cepLookupHint.value = '';
        return;
    }
    cepAbortController?.abort();
    const controller = new AbortController();
    cepAbortController = controller;
    cepLoading.value = true;
    cepLookupHint.value = '';
    try {
        const timeout = setTimeout(() => controller.abort(), 8000);
        const res = await fetch(`https://viacep.com.br/ws/${raw}/json/`, { signal: controller.signal });
        clearTimeout(timeout);
        if (!res.ok) {
            cepLookupHint.value =
                'Não foi possível consultar o CEP. Verifique o número ou preencha logradouro, bairro, cidade e UF manualmente.';
            return;
        }
        const d = await res.json().catch(() => null);
        if (!d || d.erro) {
            cepLookupHint.value =
                'CEP não encontrado na base dos Correios. Confira os dígitos ou preencha o endereço manualmente.';
            return;
        }
        form.address_street = d.logradouro != null ? String(d.logradouro) : '';
        form.address_neighborhood = d.bairro != null ? String(d.bairro) : '';
        form.address_city = d.localidade != null ? String(d.localidade) : '';
        form.address_state = d.uf ? String(d.uf).toUpperCase().slice(0, 2) : '';
        const hasStreet = !!(d.logradouro && String(d.logradouro).trim());
        const hasNbhd = !!(d.bairro && String(d.bairro).trim());
        const hasCity = !!(d.localidade && String(d.localidade).trim());
        if (!hasStreet || !hasNbhd || !hasCity) {
            cepLookupHint.value =
                'Alguns dados não vieram na consulta. Complete logradouro, bairro e cidade manualmente, se necessário.';
        } else {
            cepLookupHint.value = 'Endereço preenchido automaticamente. Ajuste se precisar.';
        }
    } catch (e) {
        if (e.name === 'AbortError') {
            return;
        }
        cepLookupHint.value =
            'Não foi possível consultar o CEP agora. Preencha o endereço manualmente ou tente de novo em instantes.';
    } finally {
        cepLoading.value = false;
    }
}

watch(
    () => digitsOnly(form.address_zip),
    (digits) => {
        if (digits.length !== 8) {
            cepLookupHint.value = '';
            clearTimeout(cepFetchTimer);
            return;
        }
        clearTimeout(cepFetchTimer);
        cepFetchTimer = setTimeout(() => {
            fetchCep();
        }, 400);
    }
);

function maskCpfCnpj(v) {
    const d = String(v).replace(/\D/g, '');
    if (form.person_type === 'pf') {
        return d
            .slice(0, 11)
            .replace(/(\d{3})(\d)/, '$1.$2')
            .replace(/(\d{3})(\d)/, '$1.$2')
            .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
    }
    return d
        .slice(0, 14)
        .replace(/^(\d{2})(\d)/, '$1.$2')
        .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d)/, '.$1/$2')
        .replace(/(\d{4})(\d)/, '$1-$2');
}

function maskCpf(v) {
    const d = String(v).replace(/\D/g, '').slice(0, 11);
    return d
        .replace(/(\d{3})(\d)/, '$1.$2')
        .replace(/(\d{3})(\d)/, '$1.$2')
        .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
}

function maskCep(v) {
    const d = String(v).replace(/\D/g, '').slice(0, 8);
    return d.length > 5 ? `${d.slice(0, 5)}-${d.slice(5)}` : d;
}

function maskPhone(v) {
    const d = digitsOnly(v).slice(0, 11);
    if (d.length <= 2) return d;
    if (d.length <= 6) return `(${d.slice(0, 2)}) ${d.slice(2)}`;
    if (d.length <= 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
    return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
}

function prevStep() {
    if (step.value > 1) step.value -= 1;
}

const tagline = 'Rápido, seguro e sem complicação.';

watchEffect(() => {
    const p = primary.value;
    if (typeof document !== 'undefined') {
        document.documentElement.style.setProperty('--color-primary', p);
    }
});

function nextStep() {
    if (step.value < totalSteps) {
        step.value += 1;
    }
}

/** HTML5 `required` em etapas ocultas bloqueava o submit antes de chegar na etapa da senha. */
async function onWizardSubmit() {
    if (step.value === totalSteps) {
        if (!(await validateCurrentStep())) {
            return;
        }
        submitRegistration();
        return;
    }
    if (!(await validateCurrentStep())) {
        return;
    }
    nextStep();
}

/** Enter nos passos 1–4 avança (com validação); no passo 5 o Enter submete o formulário. */
async function onWizardKeydownEnter(e) {
    if (step.value >= totalSteps) {
        return;
    }
    if (e.target.tagName === 'TEXTAREA') {
        return;
    }
    const tag = (e.target.tagName || '').toLowerCase();
    if (tag === 'button' || e.target.closest?.('button')) {
        return;
    }
    e.preventDefault();
    if (!(await validateCurrentStep())) {
        return;
    }
    nextStep();
}

function submitRegistration() {
    wizardStepError.value = '';
    form.turnstile_token = turnstileToken.value;
    form
        .transform((data) => ({
            ...data,
            turnstile_token: turnstileToken.value || data.turnstile_token || '',
            coproducer_invite: data.coproducer_invite || null,
            ref: data.ref || null,
            document: String(data.document || '').replace(/\D/g, ''),
            legal_representative_cpf: data.person_type === 'pj' ? String(data.legal_representative_cpf || '').replace(/\D/g, '') : null,
            cnpj_suggested_razao_social: data.person_type === 'pj' ? (data.cnpj_suggested_razao_social || null) : null,
            address_zip: String(data.address_zip || '').replace(/\D/g, ''),
            address_state: String(data.address_state || '').toUpperCase().slice(0, 2),
        }))
        .post('/cadastro', {
            preserveScroll: true,
            onError: (errors) => {
                revealServerErrors(errors);
            },
            onFinish: () => {
                const msg = String(page.props.flash?.error || '').trim();
                if (msg && !wizardStepError.value && !firstServerErrorMessage(form.errors)) {
                    wizardStepError.value = msg;
                }
            },
        });
}
</script>

<template>
    <AuthPageShell
        :title="isModernLogin ? 'Criar conta' : ''"
        :subtitle="isModernLogin ? tagline : ''"
        variant="seller"
        wide
    >
        <p v-if="!isModernLogin" class="text-center text-sm font-medium text-teal-600 dark:text-teal-400">{{ tagline }}</p>

        <div
            class="mt-8 rounded-2xl border p-6 shadow-sm"
            :class="isImmersive
                ? 'border-zinc-200/90 bg-white/80 backdrop-blur-sm dark:border-white/15 dark:bg-white/5'
                : isSpotlight
                    ? 'border-zinc-200 bg-white/90 dark:border-zinc-700/80 dark:bg-zinc-900/50'
                    : 'border-zinc-200 bg-zinc-50/80 dark:border-zinc-700 dark:bg-zinc-900/60'"
        >
                <div class="flex items-start justify-between gap-2 text-xs font-medium uppercase tracking-wide text-zinc-500">
                    <span>Etapa {{ step }} de {{ totalSteps }}</span>
                    <span class="text-[var(--color-primary)]">{{ stepTitle }}</span>
                </div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                    <div
                        class="h-full rounded-full transition-all duration-300"
                        :style="{ width: progressPct + '%', backgroundColor: primary }"
                    />
                </div>
                <div class="mt-3 flex justify-center gap-2">
                    <span
                        v-for="n in totalSteps"
                        :key="n"
                        class="h-2 rounded-full transition-all"
                        :class="n === step ? 'w-8' : 'w-2 bg-zinc-300 dark:bg-zinc-600'"
                        :style="n === step ? { backgroundColor: primary } : {}"
                    />
                </div>

                <form class="mt-8 space-y-4" novalidate @submit.prevent="onWizardSubmit" @keydown.enter="onWizardKeydownEnter">
                    <div class="absolute -left-[9999px] h-0 w-0 overflow-hidden opacity-0" aria-hidden="true">
                        <label for="registration-website">Website</label>
                        <input
                            id="registration-website"
                            v-model="form.website"
                            type="text"
                            name="website"
                            tabindex="-1"
                            autocomplete="off"
                        />
                    </div>
                    <!-- Step 1 -->
                    <div v-show="step === 1" class="space-y-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Tipo de conta</p>
                        <div class="grid grid-cols-2 gap-3">
                            <button
                                type="button"
                                class="rounded-xl border-2 p-4 text-left transition"
                                :class="form.person_type === 'pf' ? 'border-[var(--color-primary)]' : 'border-zinc-200 dark:border-zinc-600'"
                                @click="form.person_type = 'pf'"
                            >
                                <div class="flex h-10 w-10 items-center justify-center rounded-full" :style="{ backgroundColor: form.person_type === 'pf' ? primary : '#27272a' }">
                                    <User class="h-5 w-5 text-zinc-900" />
                                </div>
                                <p class="mt-2 text-sm font-semibold text-zinc-900 dark:text-white">Pessoa física</p>
                                <p class="text-xs text-zinc-500">Cadastro com CPF</p>
                            </button>
                            <button
                                type="button"
                                class="rounded-xl border-2 p-4 text-left transition"
                                :class="form.person_type === 'pj' ? 'border-[var(--color-primary)]' : 'border-zinc-200 dark:border-zinc-600'"
                                @click="form.person_type = 'pj'"
                            >
                                <div class="flex h-10 w-10 items-center justify-center rounded-full" :style="{ backgroundColor: form.person_type === 'pj' ? primary : '#27272a' }">
                                    <Building2 class="h-5 w-5 text-zinc-900" />
                                </div>
                                <p class="mt-2 text-sm font-semibold text-zinc-900 dark:text-white">Pessoa jurídica</p>
                                <p class="text-xs text-zinc-500">Cadastro com CNPJ</p>
                            </button>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase text-zinc-500">Nome completo</label>
                            <input v-model="form.name" type="text" required class="wl-input mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-950 dark:text-white" />
                            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase text-zinc-500">E-mail</label>
                            <input
                                v-model="form.email"
                                type="email"
                                required
                                autocomplete="email"
                                class="wl-input mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-950 dark:text-white"
                                @blur="checkEmailBlur"
                            />
                            <p class="mt-1 text-xs text-zinc-500">Ao continuar, verificamos se o e-mail já está em uso.</p>
                            <p v-if="emailCheckMsg" class="mt-1 text-sm text-amber-700 dark:text-amber-400">{{ emailCheckMsg }}</p>
                            <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase text-zinc-500">WhatsApp</label>
                            <input
                                :value="form.phone"
                                type="tel"
                                inputmode="tel"
                                required
                                autocomplete="tel"
                                placeholder="(11) 99999-9999"
                                class="wl-input mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-950 dark:text-white"
                                @input="form.phone = maskPhone($event.target.value)"
                            />
                            <p class="mt-1 text-xs text-zinc-500">Usado para contato da plataforma com você.</p>
                            <p v-if="form.errors.phone" class="mt-1 text-sm text-red-600">{{ form.errors.phone }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase text-zinc-500">Data de nascimento</label>
                            <input v-model="form.birth_date" type="date" required class="wl-input mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-950 dark:text-white" />
                            <p class="mt-1 text-xs text-zinc-500">Usamos apenas para validação cadastral e conformidade.</p>
                            <p v-if="form.errors.birth_date" class="mt-1 text-sm text-red-600">{{ form.errors.birth_date }}</p>
                        </div>
                    </div>

                    <!-- Step 2 -->
                    <div v-show="step === 2" class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold uppercase text-zinc-500">{{ form.person_type === 'pf' ? 'CPF' : 'CNPJ' }}</label>
                            <input
                                :value="form.document"
                                type="text"
                                required
                                class="wl-input mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-950 dark:text-white"
                                @input="form.document = maskCpfCnpj($event.target.value)"
                            />
                            <p v-if="form.errors.document" class="mt-1 text-sm text-red-600">{{ form.errors.document }}</p>
                        </div>
                        <template v-if="form.person_type === 'pj'">
                            <p v-if="cnpjLookupLoading" class="text-xs text-zinc-500">Consultando CNPJ na Receita…</p>
                            <div
                                v-if="cnpjSituacaoWarning"
                                class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                            >
                                {{ cnpjSituacaoWarning }}
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase text-zinc-500">Razão social</label>
                                <input v-model="form.company_name" type="text" class="wl-input mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-950 dark:text-white" />
                                <p v-if="cnpjOfficialRazaoHint" class="mt-1 text-xs text-zinc-500">{{ cnpjOfficialRazaoHint }}</p>
                                <p v-if="form.errors.company_name" class="mt-1 text-sm text-red-600">{{ form.errors.company_name }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase text-zinc-500">CPF do representante legal</label>
                                <input
                                    :value="form.legal_representative_cpf"
                                    type="text"
                                    class="wl-input mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-950 dark:text-white"
                                    @input="form.legal_representative_cpf = maskCpf($event.target.value)"
                                />
                                <p v-if="form.errors.legal_representative_cpf" class="mt-1 text-sm text-red-600">{{ form.errors.legal_representative_cpf }}</p>
                            </div>
                        </template>
                    </div>

                    <!-- Step 3 -->
                    <div v-show="step === 3" class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold uppercase text-zinc-500">CEP</label>
                            <input
                                :value="form.address_zip"
                                type="text"
                                class="wl-input mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-950 dark:text-white"
                                placeholder="00000-000"
                                autocomplete="postal-code"
                                @input="form.address_zip = maskCep($event.target.value)"
                                @blur="fetchCep"
                            />
                            <p v-if="cepLoading" class="mt-1 text-xs text-zinc-500">Buscando endereço…</p>
                            <p v-else-if="cepLookupHint" class="mt-1 text-xs text-zinc-600 dark:text-zinc-400">{{ cepLookupHint }}</p>
                            <p v-if="form.errors.address_zip" class="mt-1 text-sm text-red-600">{{ form.errors.address_zip }}</p>
                            <Button
                                v-if="digitsOnly(form.address_zip).length === 8"
                                type="button"
                                variant="outline"
                                class="mt-2 w-full text-xs sm:w-auto"
                                :disabled="cepLoading"
                                @click="fetchCep"
                            >
                                Buscar endereço pelo CEP
                            </Button>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase text-zinc-500">Logradouro</label>
                            <input v-model="form.address_street" type="text" class="wl-input mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-950 dark:text-white" />
                            <p v-if="form.errors.address_street" class="mt-1 text-sm text-red-600">{{ form.errors.address_street }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold uppercase text-zinc-500">Número</label>
                                <input v-model="form.address_number" type="text" class="wl-input mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-950 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase text-zinc-500">Complemento</label>
                                <input v-model="form.address_complement" type="text" class="wl-input mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-950 dark:text-white" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase text-zinc-500">Bairro</label>
                            <input v-model="form.address_neighborhood" type="text" class="wl-input mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-950 dark:text-white" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold uppercase text-zinc-500">Cidade</label>
                                <input v-model="form.address_city" type="text" class="wl-input mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-950 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase text-zinc-500">UF</label>
                                <input v-model="form.address_state" maxlength="2" type="text" class="wl-input mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 uppercase dark:border-zinc-600 dark:bg-zinc-950 dark:text-white" />
                            </div>
                        </div>
                    </div>

                    <!-- Step 4 -->
                    <div v-show="step === 4" class="space-y-4">
                        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-600">
                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">Faturamento mensal estimado</p>
                            <p class="mt-1 text-xs text-zinc-500">Ajuda a personalizar sua experiência. Escolha a faixa mais próxima da realidade do negócio.</p>
                        </div>
                        <div class="space-y-2">
                            <label
                                v-for="opt in revenue_ranges"
                                :key="opt.value"
                                class="flex cursor-pointer items-center justify-between rounded-xl border-2 px-4 py-3 transition"
                                :class="form.monthly_revenue_range === opt.value ? 'border-[var(--color-primary)]' : 'border-zinc-200 dark:border-zinc-600'"
                            >
                                <span class="text-sm text-zinc-900 dark:text-white">{{ opt.label }}</span>
                                <input v-model="form.monthly_revenue_range" type="radio" class="sr-only" :value="opt.value" />
                                <span
                                    class="flex h-5 w-5 items-center justify-center rounded-full border-2"
                                    :class="form.monthly_revenue_range === opt.value ? 'border-[var(--color-primary)] bg-[var(--color-primary)]' : 'border-zinc-400'"
                                >
                                    <span v-if="form.monthly_revenue_range === opt.value" class="h-2 w-2 rounded-full bg-zinc-900" />
                                </span>
                            </label>
                        </div>
                        <p v-if="form.errors.monthly_revenue_range" class="text-sm text-red-600">{{ form.errors.monthly_revenue_range }}</p>
                    </div>

                    <!-- Step 5 -->
                    <div v-show="step === 5" class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold uppercase text-zinc-500">Senha</label>
                            <input v-model="form.password" type="password" required autocomplete="new-password" class="wl-input mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-950 dark:text-white" />
                            <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">{{ form.errors.password }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase text-zinc-500">Confirmar senha</label>
                            <input v-model="form.password_confirmation" type="password" required autocomplete="new-password" class="wl-input mt-1 w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 dark:border-zinc-600 dark:bg-zinc-950 dark:text-white" />
                        </div>
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-600 dark:bg-zinc-950">
                            <input
                                v-model="form.accept_terms_privacy"
                                type="checkbox"
                                class="wl-checkbox mt-0.5 h-4 w-4 shrink-0 rounded border-zinc-300"
                            />
                            <span class="text-sm leading-snug text-zinc-700 dark:text-zinc-300">
                                Li e aceito os
                                <a href="/termos-de-uso" target="_blank" rel="noopener" class="font-medium text-[var(--color-primary)] underline">Termos de Uso</a>
                                e a
                                <a href="/politica-privacidade" target="_blank" rel="noopener" class="font-medium text-[var(--color-primary)] underline">Política de Privacidade</a>.
                            </span>
                        </label>
                        <p v-if="form.errors.accept_terms_privacy" class="text-sm text-red-600">
                            {{ form.errors.accept_terms_privacy }}
                        </p>
                        <div v-if="registration_turnstile?.enabled && registration_turnstile?.site_key" class="pt-2">
                            <AuthTurnstileField
                                :config="registration_turnstile"
                                v-model="turnstileToken"
                                :error="form.errors.turnstile_token"
                            />
                        </div>
                    </div>

                    <p
                        v-if="registrationAlert"
                        class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-200"
                        role="alert"
                    >
                        {{ registrationAlert }}
                    </p>

                    <div class="flex items-center justify-between gap-3 pt-2">
                        <Button v-if="step > 1" type="button" variant="outline" class="gap-1" @click="prevStep">
                            <ChevronLeft class="h-4 w-4" />
                            Voltar
                        </Button>
                        <span v-else />
                        <Button type="submit" class="min-w-[120px]" :style="{ backgroundColor: primary, color: '#0a0a0a' }" :disabled="form.processing">
                            {{ step === totalSteps ? (form.processing ? 'Criando…' : 'Criar conta') : 'Continuar' }}
                        </Button>
                    </div>
                </form>
            </div>

            <p class="mt-6 text-center text-sm text-zinc-600 dark:text-zinc-400" :class="isImmersive ? 'dark:text-white/55' : ''">
                Já tem conta?
                <Link href="/login" class="font-medium text-[var(--color-primary)] hover:underline">Entrar</Link>
            </p>

        <template #footer>
            <LegalFooterLinks class="mt-4" />
        </template>
    </AuthPageShell>
</template>
