<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import { Upload, FileText, CheckCircle2, BadgeCheck, Loader2, Camera, Home } from 'lucide-vue-next';

const props = defineProps({
    person_type: { type: String, default: 'pf' },
    kyc_status: { type: String, default: 'not_submitted' },
    rejection_reason: { type: String, default: null },
    identity_document_type: { type: String, default: null },
    company_legal_nature: { type: String, default: null },
    company_nature_suggestion: { type: String, default: null },
    uploaded_kinds: { type: Array, default: () => [] },
    requirements: {
        type: Object,
        default: () => ({
            allowed_identity_types: ['rg', 'cnh', 'passport'],
            require_address_proof: true,
            require_selfie_with_document: true,
            require_company_address_proof: true,
            require_company_constitution: true,
        }),
    },
    /** Quando true, omite título principal (uso na aba Financeiro). */
    embedded: { type: Boolean, default: false },
    /** Migração PF→PJ: mostra só docs da empresa, mesmo com KYC já aprovado. */
    conversion_mode: { type: Boolean, default: false },
    /** Conta antiga aprovada na regra anterior: reenvia o conjunto atual. */
    document_upgrade: { type: Boolean, default: false },
    conversion_status: { type: String, default: null },
    conversion_rejection_reason: { type: String, default: null },
});

const conversionMode = computed(() => !!props.conversion_mode);
const documentUpgrade = computed(() => !!props.document_upgrade);
const isPj = computed(() => props.person_type === 'pj' || conversionMode.value);

const req = computed(() => {
    const r = props.requirements || {};
    const types = Array.isArray(r.allowed_identity_types) && r.allowed_identity_types.length
        ? r.allowed_identity_types
        : ['rg', 'cnh', 'passport'];
    return {
        allowed_identity_types: types,
        require_address_proof: r.require_address_proof !== false,
        require_selfie_with_document: r.require_selfie_with_document !== false,
        require_company_address_proof: r.require_company_address_proof !== false,
        require_company_constitution: r.require_company_constitution !== false,
    };
});

const identityTypeOptions = computed(() => {
    const all = [
        { value: 'rg', label: 'RG / CIN' },
        { value: 'cnh', label: 'CNH' },
        { value: 'passport', label: 'Passaporte válido' },
    ];
    return all.filter((o) => req.value.allowed_identity_types.includes(o.value));
});

const isPendingReview = computed(
    () => props.kyc_status === 'pending_review' || props.conversion_status === 'pending_review'
);
const isApproved = computed(
    () =>
        props.kyc_status === 'approved' &&
        !conversionMode.value &&
        !documentUpgrade.value &&
        props.conversion_status !== 'pending_review' &&
        props.conversion_status !== 'collecting_docs'
);
const isReadOnlyKyc = computed(() => isPendingReview.value || isApproved.value);

const identityType = ref(props.identity_document_type || '');
const companyNature = ref(
    props.company_legal_nature || props.company_nature_suggestion || ''
);

watch(
    () => props.identity_document_type,
    (v) => {
        if (v) identityType.value = v;
    }
);
watch(
    () => [props.company_legal_nature, props.company_nature_suggestion],
    ([nature, suggestion]) => {
        if (nature) {
            companyNature.value = nature;
        } else if (!companyNature.value && suggestion) {
            companyNature.value = suggestion;
        }
    }
);

const uploading = reactive({
    rg_front: false,
    rg_back: false,
    address_proof: false,
    selfie_with_document: false,
    company_address_proof: false,
    ccmei: false,
    social_contract: false,
});

const uploaded = reactive({
    rg_front: false,
    rg_back: false,
    address_proof: false,
    selfie_with_document: false,
    company_address_proof: false,
    ccmei: false,
    social_contract: false,
});

function hydrateUploaded() {
    const kinds = Array.isArray(props.uploaded_kinds) ? props.uploaded_kinds : [];
    for (const key of Object.keys(uploaded)) {
        uploaded[key] = kinds.includes(key);
    }
}
hydrateUploaded();
watch(
    () => props.uploaded_kinds,
    () => hydrateUploaded(),
    { deep: true }
);

const fieldErrors = reactive({
    rg_front: '',
    rg_back: '',
    address_proof: '',
    selfie_with_document: '',
    company_address_proof: '',
    ccmei: '',
    social_contract: '',
});

const finalizeProcessing = ref(false);
const prefsSaving = ref(false);
const uploadError = ref('');

const MAX_BYTES = 20 * 1024 * 1024;

const needsIdentityBack = computed(() => identityType.value === 'rg');

watch(
    identityTypeOptions,
    (opts) => {
        if (!opts.length) return;
        if (identityType.value && !opts.some((o) => o.value === identityType.value)) {
            identityType.value = opts[0].value;
        }
    },
    { immediate: true }
);

const identityFrontLabel = computed(() => {
    if (identityType.value === 'cnh') return 'CNH (arquivo único)';
    if (identityType.value === 'passport') return 'Página de identificação';
    return 'Frente';
});

function parseAxiosError(err, field) {
    const data = err?.response?.data;
    if (data?.errors?.[field]?.[0]) {
        return data.errors[field][0];
    }
    if (data?.errors?.upload?.[0]) {
        return data.errors.upload[0];
    }
    if (data?.message) {
        return data.message;
    }
    if (err?.response?.status === 413) {
        return 'Arquivo grande demais para o servidor. Use até 20 MB por arquivo.';
    }

    return 'Não foi possível enviar o arquivo. Tente novamente.';
}

async function persistPreferences() {
    if (!conversionMode.value && !identityType.value) {
        return false;
    }
    if (isPj.value && req.value.require_company_constitution && !companyNature.value) {
        return false;
    }

    prefsSaving.value = true;
    try {
        const payload = {};
        if (identityType.value) {
            payload.identity_document_type = identityType.value;
        }
        if (isPj.value && companyNature.value) {
            payload.company_legal_nature = companyNature.value;
        }
        await axios.post('/kyc/preferences', payload);
        return true;
    } catch (err) {
        uploadError.value = parseAxiosError(err, 'identity_document_type');
        return false;
    } finally {
        prefsSaving.value = false;
    }
}

async function onIdentityTypeChange() {
    uploadError.value = '';
    if (identityType.value) {
        await persistPreferences();
    }
}

async function onCompanyNatureChange() {
    uploadError.value = '';
    if (companyNature.value) {
        await persistPreferences();
    }
}

async function onFile(field, event) {
    const f = event.target.files?.[0];
    event.target.value = '';
    fieldErrors[field] = '';
    uploadError.value = '';

    if (!f) {
        return;
    }

    if (f.size > MAX_BYTES) {
        fieldErrors[field] = 'O arquivo não pode ser maior que 20 MB.';
        return;
    }

    if (!conversionMode.value && !identityType.value) {
        fieldErrors[field] = 'Selecione o tipo de documento de identificação antes de enviar.';
        return;
    }
    if (isPj.value && !companyNature.value && ['ccmei', 'social_contract'].includes(field)) {
        fieldErrors[field] = 'Informe se a empresa é MEI ou demais naturezas.';
        return;
    }

    const prefsOk = await persistPreferences();
    if (!prefsOk) {
        return;
    }

    uploading[field] = true;
    uploaded[field] = false;

    const fd = new FormData();
    fd.append('field', field);
    fd.append(field, f);
    if (identityType.value) {
        fd.append('identity_document_type', identityType.value);
    }
    if (isPj.value && companyNature.value) {
        fd.append('company_legal_nature', companyNature.value);
    }

    try {
        await axios.post('/kyc/document', fd, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        uploaded[field] = true;
    } catch (err) {
        fieldErrors[field] = parseAxiosError(err, field);
    } finally {
        uploading[field] = false;
    }
}

const canFinalize = computed(() => {
    if (isReadOnlyKyc.value) {
        return false;
    }
    if (conversionMode.value) {
        if (req.value.require_company_address_proof && !uploaded.company_address_proof) {
            return false;
        }
        if (req.value.require_company_constitution) {
            if (!companyNature.value) {
                return false;
            }
            if (companyNature.value === 'mei' && !uploaded.ccmei) {
                return false;
            }
            if (companyNature.value === 'other' && !uploaded.social_contract) {
                return false;
            }
        }
        return true;
    }
    if (!identityType.value) {
        return false;
    }
    if (!uploaded.rg_front) {
        return false;
    }
    if (needsIdentityBack.value && !uploaded.rg_back) {
        return false;
    }
    if (isPj.value) {
        if (req.value.require_company_address_proof && !uploaded.company_address_proof) {
            return false;
        }
        if (req.value.require_company_constitution) {
            if (!companyNature.value) {
                return false;
            }
            if (companyNature.value === 'mei' && !uploaded.ccmei) {
                return false;
            }
            if (companyNature.value === 'other' && !uploaded.social_contract) {
                return false;
            }
        }
        return true;
    }
    if (req.value.require_address_proof && !uploaded.address_proof) {
        return false;
    }
    if (req.value.require_selfie_with_document && !uploaded.selfie_with_document) {
        return false;
    }
    return true;
});

function statusFor(field, requiredWhen) {
    if (!requiredWhen) {
        return null;
    }
    if (uploaded[field]) {
        return 'sent';
    }
    return 'pending';
}

function submitForReview() {
    uploadError.value = '';
    if (!canFinalize.value) {
        uploadError.value = 'Envie todos os documentos obrigatórios antes de concluir.';
        return;
    }

    finalizeProcessing.value = true;
    router.post(
        '/kyc/finalize',
        {
            identity_document_type: identityType.value,
            company_legal_nature: isPj.value ? companyNature.value : null,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                uploadError.value =
                    errors?.upload ||
                    errors?.finalize ||
                    Object.values(errors || {})[0] ||
                    'Não foi possível enviar para análise.';
            },
            onFinish: () => {
                finalizeProcessing.value = false;
            },
        }
    );
}

const inputFileClass =
    'block w-full cursor-pointer rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 file:mr-3 file:rounded file:border-0 file:bg-zinc-100 file:px-3 file:py-1 file:text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:file:bg-zinc-700';

const selectClass =
    'mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white';

const fileAccept =
    'image/jpeg,image/jpg,image/png,image/webp,image/heic,image/heif,application/pdf,.pdf,.jpg,.jpeg,.png,.webp,.heic,.heif';
</script>

<template>
    <div class="space-y-6" :class="embedded ? '' : 'mx-auto max-w-2xl'">
        <div v-if="!embedded && !isReadOnlyKyc">
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Verificação de identidade (KYC)</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Envie <strong>um arquivo por vez</strong> (imagem ou PDF, até 20 MB). Depois clique em
                <strong>Enviar para análise</strong>.
            </p>
        </div>
        <div v-else-if="!embedded && isReadOnlyKyc">
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Verificação de identidade (KYC)</h1>
        </div>
        <div v-else-if="embedded && !isReadOnlyKyc">
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">
                {{ conversionMode ? 'Documentos da empresa para migrar para CNPJ' : 'Documentos para verificação' }}
            </h3>
            <p class="mt-1 text-xs text-zinc-500">
                <template v-if="conversionMode">
                    A identidade PF já foi verificada. Envie só os documentos da empresa. A conta continua operando como CPF até a aprovação.
                </template>
                <template v-else>
                    Selecione cada arquivo separadamente (até 20 MB). Formatos: JPG, PNG, WebP, HEIC/HEIF ou PDF.
                </template>
            </p>
        </div>

        <div
            v-if="(conversion_rejection_reason || rejection_reason) && !isReadOnlyKyc"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
        >
            <p class="font-medium">Última análise foi rejeitada:</p>
            <p class="mt-1">{{ conversion_rejection_reason || rejection_reason }}</p>
        </div>

        <div
            v-if="isPendingReview"
            class="rounded-2xl border border-emerald-200/90 bg-emerald-50/90 px-5 py-6 text-center shadow-sm dark:border-emerald-900/50 dark:bg-emerald-950/35"
        >
            <div class="flex justify-center">
                <CheckCircle2 class="h-12 w-12 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
            </div>
            <h3 class="mt-4 text-base font-semibold text-emerald-950 dark:text-emerald-100">
                {{ conversionMode ? 'Migração enviada' : 'Documentos enviados' }}
            </h3>
            <p class="mt-2 text-sm text-emerald-900/90 dark:text-emerald-200/95">
                <template v-if="conversionMode">
                    Recebemos os documentos da empresa. A migração para CNPJ está <strong>em análise</strong>. Sua conta CPF continua operando normalmente.
                </template>
                <template v-else>
                    Recebemos seus arquivos. Eles estão <strong>em análise</strong> pela equipe da plataforma. Você será avisado quando a verificação for concluída.
                </template>
            </p>
        </div>

        <div
            v-else-if="isApproved"
            class="rounded-2xl border border-[var(--color-primary)]/40 bg-[var(--color-primary)]/10 px-5 py-6 text-center shadow-sm dark:border-[var(--color-primary)]/35 dark:bg-[var(--color-primary)]/15"
        >
            <div class="flex justify-center">
                <BadgeCheck class="h-12 w-12 text-[var(--color-primary)]" aria-hidden="true" />
            </div>
            <h3 class="mt-4 text-base font-semibold text-zinc-900 dark:text-white">Verificação aprovada</h3>
            <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-300">
                Sua identidade foi <strong>confirmada</strong> pela plataforma. Não é necessário enviar novos documentos.
            </p>
        </div>

        <form
            v-else
            class="space-y-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/40"
            @submit.prevent="submitForReview"
        >
            <p v-if="uploadError" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
                {{ uploadError }}
            </p>

            <div v-if="!conversionMode">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <Upload class="h-4 w-4 text-[var(--color-primary)]" />
                    {{ isPj ? 'Documento do responsável legal' : 'Documento de identificação' }}
                </h2>
                <p class="mt-1 text-xs text-zinc-500">
                    Escolha o tipo e envie o documento. Aceitos: RG/CIN, CNH ou passaporte válido.
                </p>
                <label class="mt-3 block text-xs font-medium uppercase text-zinc-500">Tipo de documento</label>
                <select
                    v-model="identityType"
                    :class="selectClass"
                    :disabled="prefsSaving"
                    @change="onIdentityTypeChange"
                >
                    <option value="" disabled>Selecione…</option>
                    <option v-for="opt in identityTypeOptions" :key="opt.value" :value="opt.value">
                        {{ opt.label }}
                    </option>
                </select>
            </div>

            <div v-if="identityType && !conversionMode" class="space-y-4">
                <p v-if="identityType === 'passport'" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                    O passaporte deve estar <strong>dentro da validade</strong> na data do envio.
                </p>
                <p v-if="identityType === 'cnh'" class="text-xs text-zinc-500">
                    Envie a CNH completa em um único arquivo (foto ou PDF da CNH digital).
                </p>
                <div class="grid gap-4" :class="needsIdentityBack ? 'sm:grid-cols-2' : ''">
                    <div>
                        <div class="mb-1 flex items-center justify-between gap-2">
                            <label class="block text-xs font-medium uppercase text-zinc-500">{{ identityFrontLabel }}</label>
                            <span
                                v-if="statusFor('rg_front', true) === 'sent'"
                                class="text-[10px] font-semibold uppercase text-emerald-600"
                            >Enviado</span>
                            <span v-else class="text-[10px] font-semibold uppercase text-amber-600">Pendente</span>
                        </div>
                        <input
                            type="file"
                            :accept="fileAccept"
                            :class="inputFileClass"
                            :disabled="uploading.rg_front"
                            @change="onFile('rg_front', $event)"
                        />
                        <p v-if="uploading.rg_front" class="mt-1 flex items-center gap-1 text-xs text-zinc-500">
                            <Loader2 class="h-3 w-3 animate-spin" /> Enviando…
                        </p>
                        <p v-else-if="uploaded.rg_front" class="mt-1 text-xs text-emerald-600">Arquivo recebido</p>
                        <p v-if="fieldErrors.rg_front" class="mt-1 text-sm text-red-600">{{ fieldErrors.rg_front }}</p>
                    </div>
                    <div v-if="needsIdentityBack">
                        <div class="mb-1 flex items-center justify-between gap-2">
                            <label class="block text-xs font-medium uppercase text-zinc-500">Verso</label>
                            <span
                                v-if="statusFor('rg_back', true) === 'sent'"
                                class="text-[10px] font-semibold uppercase text-emerald-600"
                            >Enviado</span>
                            <span v-else class="text-[10px] font-semibold uppercase text-amber-600">Pendente</span>
                        </div>
                        <input
                            type="file"
                            :accept="fileAccept"
                            :class="inputFileClass"
                            :disabled="uploading.rg_back"
                            @change="onFile('rg_back', $event)"
                        />
                        <p v-if="uploading.rg_back" class="mt-1 flex items-center gap-1 text-xs text-zinc-500">
                            <Loader2 class="h-3 w-3 animate-spin" /> Enviando…
                        </p>
                        <p v-else-if="uploaded.rg_back" class="mt-1 text-xs text-emerald-600">Arquivo recebido</p>
                        <p v-if="fieldErrors.rg_back" class="mt-1 text-sm text-red-600">{{ fieldErrors.rg_back }}</p>
                    </div>
                </div>
            </div>

            <!-- PF extras -->
            <div
                v-if="!isPj && identityType && (req.require_address_proof || req.require_selfie_with_document)"
                class="space-y-6 border-t border-zinc-200 pt-6 dark:border-zinc-700"
            >
                <div v-if="req.require_address_proof">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                        <Home class="h-4 w-4 text-[var(--color-primary)]" />
                        Comprovante de residência
                    </h2>
                    <p class="mt-1 text-xs text-zinc-500">
                        Documento recente que permita verificar seu nome e endereço (conta de serviço, extrato etc.).
                    </p>
                    <div class="mt-3 max-w-xl">
                        <div class="mb-1 flex items-center justify-between gap-2">
                            <label class="block text-xs font-medium uppercase text-zinc-500">Arquivo</label>
                            <span
                                v-if="uploaded.address_proof"
                                class="text-[10px] font-semibold uppercase text-emerald-600"
                            >Enviado</span>
                            <span v-else class="text-[10px] font-semibold uppercase text-amber-600">Pendente</span>
                        </div>
                        <input
                            type="file"
                            :accept="fileAccept"
                            :class="inputFileClass"
                            :disabled="uploading.address_proof"
                            @change="onFile('address_proof', $event)"
                        />
                        <p v-if="uploading.address_proof" class="mt-1 flex items-center gap-1 text-xs text-zinc-500">
                            <Loader2 class="h-3 w-3 animate-spin" /> Enviando…
                        </p>
                        <p v-else-if="uploaded.address_proof" class="mt-1 text-xs text-emerald-600">Arquivo recebido</p>
                        <p v-if="fieldErrors.address_proof" class="mt-1 text-sm text-red-600">{{ fieldErrors.address_proof }}</p>
                    </div>
                </div>

                <div v-if="req.require_selfie_with_document">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                        <Camera class="h-4 w-4 text-[var(--color-primary)]" />
                        Selfie com documento
                    </h2>
                    <p class="mt-1 text-xs text-zinc-500">
                        Foto sua segurando o mesmo documento de identificação, com rosto e documento visíveis.
                    </p>
                    <div class="mt-3 max-w-xl">
                        <div class="mb-1 flex items-center justify-between gap-2">
                            <label class="block text-xs font-medium uppercase text-zinc-500">Arquivo</label>
                            <span
                                v-if="uploaded.selfie_with_document"
                                class="text-[10px] font-semibold uppercase text-emerald-600"
                            >Enviado</span>
                            <span v-else class="text-[10px] font-semibold uppercase text-amber-600">Pendente</span>
                        </div>
                        <input
                            type="file"
                            :accept="fileAccept"
                            :class="inputFileClass"
                            :disabled="uploading.selfie_with_document"
                            @change="onFile('selfie_with_document', $event)"
                        />
                        <p v-if="uploading.selfie_with_document" class="mt-1 flex items-center gap-1 text-xs text-zinc-500">
                            <Loader2 class="h-3 w-3 animate-spin" /> Enviando…
                        </p>
                        <p v-else-if="uploaded.selfie_with_document" class="mt-1 text-xs text-emerald-600">Arquivo recebido</p>
                        <p v-if="fieldErrors.selfie_with_document" class="mt-1 text-sm text-red-600">{{ fieldErrors.selfie_with_document }}</p>
                    </div>
                </div>
            </div>

            <!-- PJ extras -->
            <div
                v-if="isPj && (identityType || conversionMode) && (req.require_company_address_proof || req.require_company_constitution)"
                class="space-y-6"
                :class="conversionMode ? '' : 'border-t border-zinc-200 pt-6 dark:border-zinc-700'"
            >
                <div>
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                        <FileText class="h-4 w-4 text-[var(--color-primary)]" />
                        Empresa
                    </h2>
                    <template v-if="req.require_company_constitution">
                        <label class="mt-3 block text-xs font-medium uppercase text-zinc-500">Natureza jurídica</label>
                        <select
                            v-model="companyNature"
                            :class="selectClass"
                            :disabled="prefsSaving"
                            @change="onCompanyNatureChange"
                        >
                            <option value="" disabled>Selecione…</option>
                            <option value="mei">MEI — Certificado CCMEI</option>
                            <option value="other">Demais empresas — Contrato social / ato constitutivo</option>
                        </select>
                        <p v-if="company_nature_suggestion && !company_legal_nature" class="mt-1 text-xs text-zinc-500">
                            Sugestão com base na consulta CNPJ: {{ company_nature_suggestion === 'mei' ? 'MEI' : 'demais empresas' }}.
                        </p>
                    </template>
                </div>

                <div v-if="req.require_company_address_proof">
                    <div class="mb-1 flex items-center justify-between gap-2">
                        <label class="block text-xs font-medium uppercase text-zinc-500">Comprovante de endereço da empresa</label>
                        <span
                            v-if="uploaded.company_address_proof"
                            class="text-[10px] font-semibold uppercase text-emerald-600"
                        >Enviado</span>
                        <span v-else class="text-[10px] font-semibold uppercase text-amber-600">Pendente</span>
                    </div>
                    <input
                        type="file"
                        :accept="fileAccept"
                        :class="inputFileClass"
                        :disabled="uploading.company_address_proof"
                        @change="onFile('company_address_proof', $event)"
                    />
                    <p v-if="uploading.company_address_proof" class="mt-1 flex items-center gap-1 text-xs text-zinc-500">
                        <Loader2 class="h-3 w-3 animate-spin" /> Enviando…
                    </p>
                    <p v-else-if="uploaded.company_address_proof" class="mt-1 text-xs text-emerald-600">Arquivo recebido</p>
                    <p v-if="fieldErrors.company_address_proof" class="mt-1 text-sm text-red-600">{{ fieldErrors.company_address_proof }}</p>
                </div>

                <div v-if="req.require_company_constitution && companyNature === 'mei'">
                    <div class="mb-1 flex items-center justify-between gap-2">
                        <label class="block text-xs font-medium uppercase text-zinc-500">CCMEI</label>
                        <span
                            v-if="uploaded.ccmei"
                            class="text-[10px] font-semibold uppercase text-emerald-600"
                        >Enviado</span>
                        <span v-else class="text-[10px] font-semibold uppercase text-amber-600">Pendente</span>
                    </div>
                    <p class="mb-2 text-xs text-zinc-500">Certificado da Condição de Microempreendedor Individual.</p>
                    <input
                        type="file"
                        :accept="fileAccept"
                        :class="inputFileClass"
                        :disabled="uploading.ccmei"
                        @change="onFile('ccmei', $event)"
                    />
                    <p v-if="uploading.ccmei" class="mt-1 flex items-center gap-1 text-xs text-zinc-500">
                        <Loader2 class="h-3 w-3 animate-spin" /> Enviando…
                    </p>
                    <p v-else-if="uploaded.ccmei" class="mt-1 text-xs text-emerald-600">Arquivo recebido</p>
                    <p v-if="fieldErrors.ccmei" class="mt-1 text-sm text-red-600">{{ fieldErrors.ccmei }}</p>
                </div>

                <div v-if="req.require_company_constitution && companyNature === 'other'">
                    <div class="mb-1 flex items-center justify-between gap-2">
                        <label class="block text-xs font-medium uppercase text-zinc-500">Contrato social / ato constitutivo</label>
                        <span
                            v-if="uploaded.social_contract"
                            class="text-[10px] font-semibold uppercase text-emerald-600"
                        >Enviado</span>
                        <span v-else class="text-[10px] font-semibold uppercase text-amber-600">Pendente</span>
                    </div>
                    <p class="mb-2 text-xs text-zinc-500">Contrato social atualizado ou ato constitutivo equivalente.</p>
                    <input
                        type="file"
                        :accept="fileAccept"
                        :class="inputFileClass"
                        :disabled="uploading.social_contract"
                        @change="onFile('social_contract', $event)"
                    />
                    <p v-if="uploading.social_contract" class="mt-1 flex items-center gap-1 text-xs text-zinc-500">
                        <Loader2 class="h-3 w-3 animate-spin" /> Enviando…
                    </p>
                    <p v-else-if="uploaded.social_contract" class="mt-1 text-xs text-emerald-600">Arquivo recebido</p>
                    <p v-if="fieldErrors.social_contract" class="mt-1 text-sm text-red-600">{{ fieldErrors.social_contract }}</p>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <Button type="submit" :disabled="finalizeProcessing || !canFinalize">
                    {{
                        finalizeProcessing
                            ? 'Enviando…'
                            : conversionMode
                              ? 'Enviar migração para análise'
                              : 'Enviar para análise'
                    }}
                </Button>
            </div>
        </form>
    </div>
</template>
