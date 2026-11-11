<script setup>
import { computed, ref } from 'vue';
import { useForm, Link, router, usePage } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';
import { Download, Eye, MessageCircle, RefreshCw, X } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    merchant: { type: Object, required: true },
    documents: { type: Array, default: () => [] },
    cnpj_lookup: { type: Object, default: null },
    platform_totp_enabled: { type: Boolean, default: false },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success ?? null);
const flashError = computed(() => page.props.flash?.error ?? null);
const flashInfo = computed(() => page.props.flash?.info ?? null);

const refreshingCnpj = ref(false);

const rejectForm = useForm({
    reason: '',
});

const conversionRejectForm = useForm({
    reason: '',
});

const conversionPending = computed(() => props.merchant?.pj_conversion?.status === 'pending_review');
const conversionActive = computed(() => {
    const s = props.merchant?.pj_conversion?.status;
    return s === 'collecting_docs' || s === 'pending_review' || s === 'rejected';
});

const stepUpOpen = ref(false);
const stepUpLoading = ref(false);
const pendingAction = ref(null);

const previewOpen = ref(false);
const previewDoc = ref(null);

const previewMime = computed(() => String(previewDoc.value?.mime || '').toLowerCase());
const previewIsImage = computed(() => previewMime.value.startsWith('image/'));
const previewIsPdf = computed(() => previewMime.value === 'application/pdf' || previewMime.value.includes('pdf'));

function documentViewUrl(d) {
    return d.view_url || String(d.download_url || '').replace(/\?download=1$/, '') || d.download_url;
}

function documentDownloadUrl(d) {
    if (d.download_url && String(d.download_url).includes('download=1')) {
        return d.download_url;
    }
    const base = documentViewUrl(d);
    return base ? `${base}${base.includes('?') ? '&' : '?'}download=1` : '#';
}

function openPreview(d) {
    previewDoc.value = d;
    previewOpen.value = true;
}

function closePreview() {
    previewOpen.value = false;
    previewDoc.value = null;
}

function identityTypeLabel(t) {
    const m = {
        rg: 'RG / CIN',
        cnh: 'CNH',
        passport: 'Passaporte',
    };
    return m[t] || t || '—';
}

function companyNatureLabel(t) {
    const m = {
        mei: 'MEI',
        other: 'Demais empresas',
    };
    return m[t] || t || '—';
}

function kindLabel(k) {
    const type = props.merchant?.identity_document_type;
    const m = {
        rg_front:
            type === 'cnh'
                ? 'CNH'
                : type === 'passport'
                  ? 'Passaporte — página de identificação'
                  : 'Documento de identificação — frente',
        rg_back: 'Documento de identificação — verso',
        address_proof: 'Comprovante de residência',
        selfie_with_document: 'Selfie com documento',
        company_address_proof: 'Comprovante de endereço da empresa',
        ccmei: 'CCMEI',
        social_contract: 'Contrato social / ato constitutivo',
        company_document: 'Documento da empresa (legado)',
        cnpj_card: 'Cartão CNPJ (legado)',
    };
    return m[k] || k;
}

const activeDocuments = computed(() =>
    (props.documents || []).filter((d) => d.is_active !== false && !d.superseded_at)
);
const supersededDocuments = computed(() =>
    (props.documents || []).filter((d) => d.is_active === false || d.superseded_at)
);

function docsOfKinds(kinds) {
    return activeDocuments.value.filter((d) => kinds.includes(d.kind));
}

const identityDocs = computed(() => docsOfKinds(['rg_front', 'rg_back']));
const pfExtraDocs = computed(() => docsOfKinds(['address_proof', 'selfie_with_document']));
const pjExtraDocs = computed(() =>
    docsOfKinds(['company_address_proof', 'ccmei', 'social_contract', 'company_document', 'cnpj_card'])
);
const otherActiveDocs = computed(() => {
    const known = new Set([
        'rg_front',
        'rg_back',
        'address_proof',
        'selfie_with_document',
        'company_address_proof',
        'ccmei',
        'social_contract',
        'company_document',
        'cnpj_card',
    ]);
    return activeDocuments.value.filter((d) => !known.has(d.kind));
});

function revenueLabel(v) {
    const m = {
        up_to_10k: 'Até R$ 10 mil',
        '10k_50k': 'R$ 10 mil a R$ 50 mil',
        '50k_100k': 'R$ 50 mil a R$ 100 mil',
        '100k_500k': 'R$ 100 mil a R$ 500 mil',
        over_500k: 'Acima de R$ 500 mil',
    };
    return m[v] || v || '—';
}

function closeStepUp() {
    stepUpOpen.value = false;
    stepUpLoading.value = false;
    pendingAction.value = null;
}

function kycActionBase() {
    return `/plataforma/verificacoes-kyc/usuario/${props.merchant.id}`;
}

function refreshCnpj() {
    refreshingCnpj.value = true;
    router.post(
        `${kycActionBase()}/consultar-cnpj`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                refreshingCnpj.value = false;
            },
        },
    );
}

function alertClass(tone) {
    if (tone === 'danger') {
        return 'border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200';
    }
    return 'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100';
}

function submitApprove() {
    stepUpLoading.value = true;
    router.post(`${kycActionBase()}/aprovar`, {}, {
        preserveScroll: true,
        onFinish: () => {
            stepUpLoading.value = false;
        },
    });
}

function submitApprovePjConversion() {
    stepUpLoading.value = true;
    router.post(`${kycActionBase()}/aprovar-migracao-pj`, {}, {
        preserveScroll: true,
        onFinish: () => {
            stepUpLoading.value = false;
        },
    });
}

function submitRejectDirect() {
    rejectForm.post(`${kycActionBase()}/rejeitar`, {
        preserveScroll: true,
        onSuccess: () => rejectForm.reset('reason'),
    });
}

function submitRejectAndBlockDirect() {
    rejectForm.post(`${kycActionBase()}/rejeitar-bloquear`, {
        preserveScroll: true,
        onSuccess: () => rejectForm.reset('reason'),
    });
}

function submitRejectPjDirect() {
    conversionRejectForm.post(`${kycActionBase()}/rejeitar-migracao-pj`, {
        preserveScroll: true,
        onSuccess: () => conversionRejectForm.reset('reason'),
    });
}

function approve() {
    if (!confirm('Aprovar a verificação deste infoprodutor?')) return;
    if (props.platform_totp_enabled) {
        pendingAction.value = 'approve';
        stepUpOpen.value = true;
        return;
    }
    submitApprove();
}

function approvePjConversion() {
    if (!confirm('Aprovar a migração desta conta de CPF para CNPJ? O CPF atual passará a ser o do responsável legal.')) return;
    if (props.platform_totp_enabled) {
        pendingAction.value = 'approve-pj';
        stepUpOpen.value = true;
        return;
    }
    submitApprovePjConversion();
}

function submitReject() {
    if (!rejectForm.reason?.trim()) return;
    if (props.platform_totp_enabled) {
        pendingAction.value = 'reject';
        stepUpOpen.value = true;
        return;
    }
    submitRejectDirect();
}

function submitRejectAndBlock() {
    if (!rejectForm.reason?.trim()) {
        rejectForm.setError('reason', 'Informe o motivo para recusar e bloquear.');
        return;
    }
    if (!confirm('Recusar esta verificação e bloquear o CPF/CNPJ? Essa pessoa não poderá cadastrar de novo com o mesmo documento.')) return;
    if (props.platform_totp_enabled) {
        pendingAction.value = 'reject-block';
        stepUpOpen.value = true;
        return;
    }
    submitRejectAndBlockDirect();
}

function submitRejectPjConversion() {
    if (!conversionRejectForm.reason?.trim()) return;
    if (props.platform_totp_enabled) {
        pendingAction.value = 'reject-pj';
        stepUpOpen.value = true;
        return;
    }
    submitRejectPjDirect();
}

function onStepUpConfirm(payload) {
    stepUpLoading.value = true;

    if (pendingAction.value === 'approve') {
        router.post(
            `${kycActionBase()}/aprovar`,
            { totp_code: payload.totp_code },
            {
                preserveScroll: true,
                onFinish: closeStepUp,
            },
        );
        return;
    }

    if (pendingAction.value === 'approve-pj') {
        router.post(
            `${kycActionBase()}/aprovar-migracao-pj`,
            { totp_code: payload.totp_code },
            {
                preserveScroll: true,
                onFinish: closeStepUp,
            },
        );
        return;
    }

    if (pendingAction.value === 'reject-pj') {
        conversionRejectForm
            .transform((data) => ({
                ...data,
                totp_code: payload.totp_code,
            }))
            .post(`${kycActionBase()}/rejeitar-migracao-pj`, {
                preserveScroll: true,
                onSuccess: () => conversionRejectForm.reset('reason'),
                onFinish: closeStepUp,
            });
        return;
    }

    const rejectUrl = pendingAction.value === 'reject-block'
        ? `${kycActionBase()}/rejeitar-bloquear`
        : `${kycActionBase()}/rejeitar`;

    rejectForm
        .transform((data) => ({
            ...data,
            totp_code: payload.totp_code,
        }))
        .post(rejectUrl, {
            preserveScroll: true,
            onSuccess: () => rejectForm.reset('reason'),
            onFinish: closeStepUp,
        });
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-center gap-4">
            <Link href="/plataforma/verificacoes-kyc" class="text-sm text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">← Voltar à lista</Link>
        </div>

        <div>
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ merchant.name }}</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ merchant.email }}</p>
            <div
                v-if="merchant.whatsapp || merchant.phone || merchant.whatsapp_url"
                class="mt-2 flex flex-wrap items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300"
            >
                <span>{{ merchant.whatsapp || merchant.phone || '—' }}</span>
                <a
                    v-if="merchant.whatsapp_url"
                    :href="merchant.whatsapp_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-500"
                    title="Abrir conversa no WhatsApp"
                >
                    <MessageCircle class="h-3.5 w-3.5" />
                    WhatsApp
                </a>
            </div>
        </div>

        <div
            v-if="flashSuccess"
            class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200"
        >
            {{ flashSuccess }}
        </div>
        <div
            v-if="flashError"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200"
        >
            {{ flashError }}
        </div>
        <div
            v-if="flashInfo"
            class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-200"
        >
            {{ flashInfo }}
        </div>

        <div
            v-if="conversionActive"
            class="rounded-2xl border border-violet-200 bg-violet-50 px-4 py-4 text-sm text-violet-950 dark:border-violet-900 dark:bg-violet-950/40 dark:text-violet-100"
        >
            <p class="font-semibold">Migração PF → PJ</p>
            <p class="mt-1 text-xs leading-relaxed">
                Status:
                {{
                    merchant.pj_conversion?.status === 'pending_review'
                        ? 'aguardando análise'
                        : merchant.pj_conversion?.status === 'collecting_docs'
                          ? 'coletando documentos'
                          : 'rejeitada'
                }}.
                CNPJ informado: {{ merchant.pj_conversion?.cnpj_formatted || merchant.pj_conversion?.cnpj || '—' }}.
                CPF atual permanece o do responsável após a aprovação. A conta segue operacional como pessoa física até você decidir.
            </p>
            <p v-if="merchant.pj_conversion?.company_name" class="mt-1 text-xs">Razão social: {{ merchant.pj_conversion.company_name }}</p>
        </div>

        <div v-if="cnpj_lookup" class="space-y-3">
            <div
                v-for="alert in cnpj_lookup.alerts || []"
                :key="alert.code"
                class="rounded-xl border px-4 py-3 text-sm"
                :class="alertClass(alert.tone)"
            >
                <p class="font-medium">Atenção na análise</p>
                <p class="mt-1">{{ alert.message }}</p>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/40">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Consulta CNPJ (BrasilAPI)</h2>
                        <p class="mt-1 text-xs text-zinc-500">
                            Compare o que o infoprodutor preencheu com o retorno da Receita.
                            <span v-if="cnpj_lookup.checked_at"> Última consulta: {{ cnpj_lookup.checked_at }}</span>
                        </p>
                    </div>
                    <Button type="button" variant="outline" size="sm" class="inline-flex items-center gap-1.5" :disabled="refreshingCnpj" @click="refreshCnpj">
                        <RefreshCw class="h-3.5 w-3.5" :class="refreshingCnpj ? 'animate-spin' : ''" />
                        {{ refreshingCnpj ? 'Consultando…' : 'Consultar novamente' }}
                    </Button>
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div class="rounded-xl border border-zinc-100 bg-zinc-50/80 p-4 dark:border-zinc-800 dark:bg-zinc-800/40">
                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Preenchido no cadastro</p>
                        <dl class="mt-3 space-y-2 text-sm">
                            <div>
                                <dt class="text-xs text-zinc-500">Razão social</dt>
                                <dd class="font-medium text-zinc-900 dark:text-white">{{ cnpj_lookup.submitted?.company_name || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-zinc-500">Endereço</dt>
                                <dd class="text-zinc-700 dark:text-zinc-300">{{ cnpj_lookup.submitted?.address_line || '—' }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="rounded-xl border border-zinc-100 bg-zinc-50/80 p-4 dark:border-zinc-800 dark:bg-zinc-800/40">
                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Retorno da API</p>
                        <dl class="mt-3 space-y-2 text-sm">
                            <div>
                                <dt class="text-xs text-zinc-500">Razão social</dt>
                                <dd class="font-medium text-zinc-900 dark:text-white">{{ cnpj_lookup.official?.razao_social || '—' }}</dd>
                            </div>
                            <div v-if="cnpj_lookup.official?.nome_fantasia">
                                <dt class="text-xs text-zinc-500">Nome fantasia</dt>
                                <dd>{{ cnpj_lookup.official.nome_fantasia }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-zinc-500">Situação</dt>
                                <dd
                                    class="font-medium"
                                    :class="cnpj_lookup.official?.situacao_irregular ? 'text-red-700 dark:text-red-300' : 'text-zinc-900 dark:text-white'"
                                >
                                    {{ cnpj_lookup.official?.situacao || '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-zinc-500">Endereço na Receita</dt>
                                <dd class="text-zinc-700 dark:text-zinc-300">{{ cnpj_lookup.official?.address_line || '—' }}</dd>
                            </div>
                            <div v-if="cnpj_lookup.official?.cnae">
                                <dt class="text-xs text-zinc-500">CNAE</dt>
                                <dd>{{ cnpj_lookup.official.cnae }}</dd>
                            </div>
                            <div v-if="cnpj_lookup.official?.qsa?.length">
                                <dt class="text-xs text-zinc-500">Quadro societário</dt>
                                <dd>
                                    <ul class="mt-1 list-disc pl-4 text-zinc-700 dark:text-zinc-300">
                                        <li v-for="(socio, idx) in cnpj_lookup.official.qsa" :key="idx">{{ socio }}</li>
                                    </ul>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/40">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Cadastro</h2>
                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Tipo</dt>
                        <dd>{{ merchant.person_type === 'pj' ? 'Pessoa jurídica' : 'Pessoa física' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">E-mail</dt>
                        <dd class="break-all text-right">{{ merchant.email }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-2">
                        <dt class="shrink-0 text-zinc-500">Telefone</dt>
                        <dd class="flex flex-wrap items-center justify-end gap-2 text-right">
                            <span>{{ merchant.whatsapp || merchant.phone || '—' }}</span>
                            <a
                                v-if="merchant.whatsapp_url"
                                :href="merchant.whatsapp_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-500"
                                title="Abrir conversa no WhatsApp"
                            >
                                <MessageCircle class="h-3.5 w-3.5" />
                                WhatsApp
                            </a>
                        </dd>
                    </div>
                    <div v-if="merchant.company_name" class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Razão social</dt>
                        <dd class="text-right">{{ merchant.company_name }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Documento</dt>
                        <dd class="font-mono text-xs">{{ merchant.document }}</dd>
                    </div>
                    <div v-if="merchant.legal_representative_cpf" class="flex justify-between gap-2">
                        <dt class="text-zinc-500">CPF representante</dt>
                        <dd class="font-mono text-xs">{{ merchant.legal_representative_cpf }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Tipo de documento</dt>
                        <dd>{{ identityTypeLabel(merchant.identity_document_type) }}</dd>
                    </div>
                    <div v-if="merchant.person_type === 'pj' || conversionActive" class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Natureza jurídica (KYC)</dt>
                        <dd>{{ companyNatureLabel(merchant.pj_conversion?.company_legal_nature || merchant.company_legal_nature) }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Versão dos requisitos</dt>
                        <dd>v{{ merchant.kyc_requirements_version || 1 }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Nascimento</dt>
                        <dd>{{ merchant.birth_date || '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500">Faturamento mensal (faixa)</dt>
                        <dd>{{ revenueLabel(merchant.monthly_revenue_range) }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/40">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Endereço</h2>
                <p class="mt-3 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
                    {{ merchant.address_street }}, {{ merchant.address_number }}
                    <span v-if="merchant.address_complement"> — {{ merchant.address_complement }}</span>
                    <br />
                    {{ merchant.address_neighborhood }} — {{ merchant.address_city }}/{{ merchant.address_state }}
                    <br />
                    CEP {{ merchant.address_zip }}
                </p>
            </div>
        </div>

        <div class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/40">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Documentos</h2>

            <section>
                <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                    {{ merchant.person_type === 'pj' ? 'Identificação do responsável' : 'Documento de identificação' }}
                </h3>
                <ul class="mt-2 space-y-2">
                    <li
                        v-for="d in identityDocs"
                        :key="d.public_token || d.id"
                        class="flex flex-col gap-2 rounded-xl border border-zinc-100 bg-zinc-50/80 p-3 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800 dark:bg-zinc-800/40"
                    >
                        <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ kindLabel(d.kind) }}</span>
                        <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                            <button
                                type="button"
                                class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-[var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:opacity-90"
                                @click="openPreview(d)"
                            >
                                <Eye class="h-4 w-4" />
                                Abrir
                            </button>
                            <a
                                :href="documentDownloadUrl(d)"
                                class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
                            >
                                <Download class="h-4 w-4" />
                                Baixar
                            </a>
                        </div>
                    </li>
                </ul>
                <p v-if="!identityDocs.length" class="mt-2 text-sm text-zinc-500">Nenhum arquivo nesta seção.</p>
            </section>

            <section v-if="merchant.person_type !== 'pj'">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Residência e selfie</h3>
                <ul class="mt-2 space-y-2">
                    <li
                        v-for="d in pfExtraDocs"
                        :key="d.public_token || d.id"
                        class="flex flex-col gap-2 rounded-xl border border-zinc-100 bg-zinc-50/80 p-3 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800 dark:bg-zinc-800/40"
                    >
                        <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ kindLabel(d.kind) }}</span>
                        <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                            <button
                                type="button"
                                class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-[var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:opacity-90"
                                @click="openPreview(d)"
                            >
                                <Eye class="h-4 w-4" />
                                Abrir
                            </button>
                            <a
                                :href="documentDownloadUrl(d)"
                                class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
                            >
                                <Download class="h-4 w-4" />
                                Baixar
                            </a>
                        </div>
                    </li>
                </ul>
                <p v-if="!pfExtraDocs.length" class="mt-2 text-sm text-zinc-500">Nenhum arquivo nesta seção.</p>
            </section>

            <section v-if="merchant.person_type === 'pj' || conversionActive">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Documentação da empresa</h3>
                <ul class="mt-2 space-y-2">
                    <li
                        v-for="d in pjExtraDocs"
                        :key="d.public_token || d.id"
                        class="flex flex-col gap-2 rounded-xl border border-zinc-100 bg-zinc-50/80 p-3 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800 dark:bg-zinc-800/40"
                    >
                        <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ kindLabel(d.kind) }}</span>
                        <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                            <button
                                type="button"
                                class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-[var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:opacity-90"
                                @click="openPreview(d)"
                            >
                                <Eye class="h-4 w-4" />
                                Abrir
                            </button>
                            <a
                                :href="documentDownloadUrl(d)"
                                class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
                            >
                                <Download class="h-4 w-4" />
                                Baixar
                            </a>
                        </div>
                    </li>
                </ul>
                <p v-if="!pjExtraDocs.length" class="mt-2 text-sm text-zinc-500">Nenhum arquivo nesta seção.</p>
            </section>

            <section v-if="otherActiveDocs.length">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Outros</h3>
                <ul class="mt-2 space-y-2">
                    <li
                        v-for="d in otherActiveDocs"
                        :key="d.public_token || d.id"
                        class="flex flex-col gap-2 rounded-xl border border-zinc-100 bg-zinc-50/80 p-3 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800 dark:bg-zinc-800/40"
                    >
                        <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ kindLabel(d.kind) }}</span>
                        <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                            <button
                                type="button"
                                class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-[var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:opacity-90"
                                @click="openPreview(d)"
                            >
                                <Eye class="h-4 w-4" />
                                Abrir
                            </button>
                            <a
                                :href="documentDownloadUrl(d)"
                                class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
                            >
                                <Download class="h-4 w-4" />
                                Baixar
                            </a>
                        </div>
                    </li>
                </ul>
            </section>

            <section v-if="supersededDocuments.length" class="border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Documentos anteriores (histórico)</h3>
                <ul class="mt-2 space-y-2">
                    <li
                        v-for="d in supersededDocuments"
                        :key="'old-' + (d.public_token || d.id)"
                        class="flex flex-col gap-2 rounded-xl border border-dashed border-zinc-200 bg-zinc-50/50 p-3 text-sm opacity-80 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-700 dark:bg-zinc-900/30"
                    >
                        <span class="font-medium text-zinc-700 dark:text-zinc-300">
                            {{ kindLabel(d.kind) }}
                            <span class="ml-2 text-[10px] uppercase text-zinc-400">substituído</span>
                        </span>
                        <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                            <button
                                type="button"
                                class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                @click="openPreview(d)"
                            >
                                <Eye class="h-4 w-4" />
                                Abrir
                            </button>
                            <a
                                :href="documentDownloadUrl(d)"
                                class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
                            >
                                <Download class="h-4 w-4" />
                                Baixar
                            </a>
                        </div>
                    </li>
                </ul>
            </section>

            <p v-if="!documents.length" class="mt-2 text-sm text-zinc-500">Nenhum arquivo enviado.</p>
        </div>

        <div v-if="merchant.kyc_status === 'blocked'" class="rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-950 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
            <strong>CPF/CNPJ bloqueados.</strong>
            Novo cadastro com estes documentos será recusado com a mensagem de que já existem na plataforma e estão bloqueados.
        </div>

        <div v-if="merchant.kyc_rejection_reason" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
            <strong>Motivo da última rejeição:</strong>
            {{ merchant.kyc_rejection_reason }}
        </div>

        <div v-if="conversionPending" class="flex flex-col gap-4 rounded-2xl border border-violet-200 bg-violet-50/50 p-5 dark:border-violet-900 dark:bg-violet-950/30">
            <p class="text-sm text-violet-950 dark:text-violet-100">Esta migração de CPF para CNPJ está aguardando sua decisão. A conta permanece operacional como pessoa física.</p>
            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                <Button type="button" class="w-full bg-emerald-600 text-white hover:bg-emerald-700 sm:w-auto" @click="approvePjConversion">
                    Aprovar migração para CNPJ
                </Button>
            </div>
            <form class="space-y-2 border-t border-violet-200 pt-4 dark:border-violet-800" @submit.prevent="submitRejectPjConversion">
                <label class="block text-sm font-medium text-zinc-800 dark:text-zinc-200">Rejeitar migração (informe o motivo)</label>
                <textarea
                    v-model="conversionRejectForm.reason"
                    required
                    rows="3"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                />
                <p v-if="conversionRejectForm.errors.reason" class="text-sm text-red-600">{{ conversionRejectForm.errors.reason }}</p>
                <p v-if="conversionRejectForm.errors.totp_code" class="text-sm text-red-600">{{ conversionRejectForm.errors.totp_code }}</p>
                <p v-if="conversionRejectForm.errors.pj_conversion" class="text-sm text-red-600">{{ conversionRejectForm.errors.pj_conversion }}</p>
                <Button
                    type="submit"
                    variant="outline"
                    class="w-full border-red-300 text-red-800 hover:bg-red-50 sm:w-auto dark:border-red-800 dark:text-red-200"
                    :disabled="conversionRejectForm.processing"
                >
                    Rejeitar migração
                </Button>
            </form>
        </div>

        <div v-if="merchant.kyc_status === 'pending_review'" class="flex flex-col gap-4 rounded-2xl border border-amber-200 bg-amber-50/50 p-5 dark:border-amber-900 dark:bg-amber-950/30">
            <p class="text-sm text-amber-950 dark:text-amber-100">Esta conta está aguardando sua decisão.</p>
            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                <Button type="button" class="w-full bg-emerald-600 text-white hover:bg-emerald-700 sm:w-auto" @click="approve">
                    Aprovar
                </Button>
            </div>
            <form class="space-y-2 border-t border-amber-200 pt-4 dark:border-amber-800" @submit.prevent="submitReject">
                <label class="block text-sm font-medium text-zinc-800 dark:text-zinc-200">Motivo (obrigatório para rejeitar ou bloquear)</label>
                <textarea
                    v-model="rejectForm.reason"
                    required
                    rows="3"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                />
                <p v-if="rejectForm.errors.reason" class="text-sm text-red-600">{{ rejectForm.errors.reason }}</p>
                <p v-if="rejectForm.errors.totp_code" class="text-sm text-red-600">{{ rejectForm.errors.totp_code }}</p>
                <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <Button
                        type="submit"
                        variant="outline"
                        class="w-full border-red-300 text-red-800 hover:bg-red-50 sm:w-auto dark:border-red-800 dark:text-red-200"
                        :disabled="rejectForm.processing"
                    >
                        Rejeitar
                    </Button>
                    <Button
                        type="button"
                        class="w-full bg-red-700 text-white hover:bg-red-800 sm:w-auto"
                        :disabled="rejectForm.processing"
                        @click="submitRejectAndBlock"
                    >
                        Recusar e Bloquear
                    </Button>
                </div>
                <p class="text-xs text-zinc-600 dark:text-zinc-400">
                    Recusar e bloquear impede novo cadastro com o mesmo CPF ou CNPJ. Se tentarem de novo, a mensagem será que o documento já existe na plataforma e está bloqueado. Rejeitar apenas permite reenviar os documentos.
                </p>
            </form>
        </div>

        <PlatformStepUpModal
            v-if="platform_totp_enabled"
            :open="stepUpOpen"
            :loading="stepUpLoading"
            :title="
                pendingAction === 'reject-block'
                    ? 'Recusar e bloquear CPF/CNPJ'
                    : pendingAction === 'reject' || pendingAction === 'reject-pj'
                      ? pendingAction === 'reject-pj'
                          ? 'Rejeitar migração para CNPJ'
                          : 'Rejeitar verificação KYC'
                      : pendingAction === 'approve-pj'
                        ? 'Aprovar migração para CNPJ'
                        : 'Aprovar verificação KYC'
            "
            description="Informe o código 2FA do seu perfil de operador."
            :confirm-label="pendingAction === 'reject-block' ? 'Recusar e bloquear' : pendingAction === 'reject' || pendingAction === 'reject-pj' ? 'Rejeitar' : 'Aprovar'"
            @close="closeStepUp"
            @confirm="onStepUpConfirm"
        />

        <div
            v-if="previewOpen && previewDoc"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            @click.self="closePreview"
        >
            <div
                class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xl dark:border-zinc-700 dark:bg-zinc-900"
                role="dialog"
                aria-modal="true"
            >
                <div class="flex items-center justify-between gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <div class="min-w-0">
                        <h3 class="truncate text-sm font-semibold text-zinc-900 dark:text-white">
                            {{ kindLabel(previewDoc.kind) }}
                        </h3>
                        <p class="truncate text-xs text-zinc-500">{{ previewDoc.mime || 'arquivo' }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <a
                            :href="documentViewUrl(previewDoc)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="hidden rounded-lg border border-zinc-300 px-2.5 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50 sm:inline-flex dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        >
                            Nova aba
                        </a>
                        <a
                            :href="documentDownloadUrl(previewDoc)"
                            class="inline-flex items-center gap-1 rounded-lg border border-zinc-300 px-2.5 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        >
                            <Download class="h-3.5 w-3.5" />
                            Baixar
                        </a>
                        <button
                            type="button"
                            class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                            aria-label="Fechar"
                            @click="closePreview"
                        >
                            <X class="h-5 w-5" />
                        </button>
                    </div>
                </div>

                <div class="flex min-h-[50vh] flex-1 items-center justify-center overflow-auto bg-zinc-100 p-3 dark:bg-zinc-950">
                    <img
                        v-if="previewIsImage"
                        :src="documentViewUrl(previewDoc)"
                        :alt="kindLabel(previewDoc.kind)"
                        class="max-h-[75vh] max-w-full rounded-lg object-contain shadow"
                    />
                    <iframe
                        v-else-if="previewIsPdf"
                        :src="documentViewUrl(previewDoc)"
                        title="Pré-visualização do documento"
                        class="h-[75vh] w-full rounded-lg border-0 bg-white"
                    />
                    <div v-else class="max-w-md space-y-3 text-center text-sm text-zinc-600 dark:text-zinc-300">
                        <p>Este tipo de arquivo não tem pré-visualização neste navegador.</p>
                        <div class="flex flex-wrap justify-center gap-2">
                            <a
                                :href="documentViewUrl(previewDoc)"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="rounded-lg bg-[var(--color-primary)] px-3 py-2 font-medium text-white"
                            >
                                Abrir em nova aba
                            </a>
                            <a
                                :href="documentDownloadUrl(previewDoc)"
                                class="rounded-lg border border-zinc-300 px-3 py-2 font-medium dark:border-zinc-600"
                            >
                                Baixar arquivo
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
