<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue';
import MemberAreaAppLayout from '@/Layouts/MemberAreaAppLayout.vue';
import { Link } from '@inertiajs/vue3';
import { useMemberAreaHref } from '@/composables/useMemberAreaHref';
import { certificateFontScaleRatio } from '@/lib/certificateText';
import CertificateCanvas from '@/components/member-area/CertificateCanvas.vue';

defineOptions({ layout: MemberAreaAppLayout });

const props = defineProps({
    product: { type: Object, required: true },
    config: { type: Object, default: () => ({}) },
    certificate: { type: Object, required: true },
    recipient_name: { type: String, default: '' },
    slug: { type: String, required: true },
    base_url: { type: String, default: '' },
    certificate_available: { type: Boolean, default: false },
    progress_percent: { type: Number, default: 0 },
    completion_required_percent: { type: Number, default: 100 },
    certificate_release: { type: Object, default: () => ({}) },
});

const { href } = useMemberAreaHref(props.slug, props.base_url);

const release = computed(() => props.certificate_release || {});

const certificateBlockedMessage = computed(() => {
    const mode = release.value.mode || 'completion_percent';
    const pct = props.progress_percent ?? 0;
    const req = props.completion_required_percent ?? 100;
    const daysNeed = release.value.days_after_access ?? 0;
    const daysLeft = release.value.days_remaining ?? 0;
    const daysElapsed = release.value.days_elapsed ?? 0;

    if (mode === 'days_after_access') {
        if (daysLeft > 0) {
            return `O certificado será liberado em ${daysLeft} dia(s) (${daysElapsed}/${daysNeed} dias de acesso).`;
        }
        return 'Aguarde o prazo de acesso ao curso para liberar o certificado.';
    }
    if (mode === 'both') {
        const parts = [];
        if (!release.value.percent_met) {
            parts.push(`conclusão: ${pct}% de ${req}%`);
        }
        if (!release.value.days_met && daysLeft > 0) {
            parts.push(`acesso: faltam ${daysLeft} dia(s) (${daysElapsed}/${daysNeed})`);
        }
        if (parts.length) {
            return `Complete os requisitos — ${parts.join(' · ')}.`;
        }
    }
    return `Complete ${req}% do curso para liberar. Seu progresso: ${pct}%.`;
});

const printFormatOverride = ref(null);
const certSignatureFont = computed(() => props.certificate?.signature_font_family || 'Dancing Script');
const certPrintFormat = computed(() => printFormatOverride.value || (props.certificate?.print_format === 'A3' ? 'A3' : 'A4'));

const printScale = computed(() => {
    const base = certificateFontScaleRatio(props.certificate?.font_scale ?? 100);
    return Math.max(base, base * 1.15);
});

const printStyleText = computed(() => {
    const format = certPrintFormat.value;
    const isA3 = format === 'A3';
    const pageW = isA3 ? '420mm' : '297mm';
    const pageH = isA3 ? '297mm' : '210mm';
    const scale = printScale.value;
    return `
html.printing-certificate body * {
    visibility: hidden !important;
}
html.printing-certificate .print-certificate-wrapper,
html.printing-certificate .print-certificate-wrapper * {
    visibility: visible !important;
}
html.printing-certificate .print-certificate-wrapper .certificate-no-print,
html.printing-certificate .print-certificate-wrapper .certificate-no-print *,
html.printing-certificate .fixed {
    visibility: hidden !important;
    display: none !important;
}
@media print {
    @page {
        size: ${pageW} ${pageH};
        margin: 0;
    }
    html, body {
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        overflow: hidden !important;
    }
    body, body *, .print-certificate-wrapper {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
    }
    body * {
        visibility: hidden !important;
    }
    .print-certificate-wrapper,
    .print-certificate-wrapper * {
        visibility: visible !important;
    }
    .print-certificate-wrapper .certificate-no-print,
    .print-certificate-wrapper .certificate-no-print *,
    .fixed {
        visibility: hidden !important;
        display: none !important;
    }
    .print-certificate-wrapper {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: ${pageW} !important;
        min-width: ${pageW} !important;
        max-width: ${pageW} !important;
        height: ${pageH} !important;
        min-height: ${pageH} !important;
        max-height: ${pageH} !important;
        margin: 0 !important;
        padding: 0 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        background: #fff !important;
        overflow: hidden !important;
    }
    .certificate-print-area {
        width: ${pageW} !important;
        min-width: ${pageW} !important;
        max-width: ${pageW} !important;
        height: ${pageH} !important;
        min-height: ${pageH} !important;
        max-height: ${pageH} !important;
        aspect-ratio: auto !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        page-break-after: avoid;
        page-break-inside: avoid;
        box-sizing: border-box !important;
        overflow: hidden !important;
        --cert-scale: ${scale};
        --cert-header-size: calc(12px * ${scale});
        --cert-title-size: calc(28px * ${scale});
        --cert-body-size: calc(16px * ${scale});
        --cert-footer-size: calc(13px * ${scale});
        --cert-watermark-size: calc(48px * ${scale});
    }
    .certificate-print-area .certificate-corners,
    .certificate-print-area .certificate-watermark {
        display: none !important;
    }
    .certificate-print-area .certificate-signature {
        font-family: ${certSignatureFont.value || 'cursive'}, cursive !important;
    }
    .certificate-print-area .certificate-bg-image {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
}
`;
});

function setCertificatePrinting(on) {
    if (typeof document === 'undefined') return;
    document.documentElement.classList.toggle('printing-certificate', on);
}

function downloadPdf(format) {
    printFormatOverride.value = format === 'A3' ? 'A3' : 'A4';
    setCertificatePrinting(true);
    requestAnimationFrame(() => {
        window.print();
    });
}

function onBeforePrint() {
    setCertificatePrinting(true);
}

function onAfterPrint() {
    printFormatOverride.value = null;
    setCertificatePrinting(false);
}

onMounted(() => {
    window.addEventListener('beforeprint', onBeforePrint);
    window.addEventListener('afterprint', onAfterPrint);
});
onUnmounted(() => {
    window.removeEventListener('beforeprint', onBeforePrint);
    window.removeEventListener('afterprint', onAfterPrint);
    setCertificatePrinting(false);
});
</script>

<template>
    <div class="print-certificate-wrapper space-y-8">
        <component :is="'style'" v-if="printStyleText">{{ printStyleText }}</component>
        <h1 class="certificate-no-print text-2xl font-bold print:hidden">Certificado de conclusão</h1>
        <div class="mx-auto w-full max-w-4xl print:max-w-none">
            <CertificateCanvas
                mode="student"
                :certificate="certificate"
                :recipient-name="recipient_name || 'Aluno'"
                :product-name="product?.name || ''"
                :show-blocked-overlay="!certificate_available"
                :blocked-message="certificateBlockedMessage"
            />
        </div>
        <div class="certificate-no-print flex flex-wrap justify-center gap-4 print:hidden">
            <template v-if="certificate_available">
                <button
                    type="button"
                    class="rounded-xl px-5 py-2.5 text-sm font-semibold text-white shadow transition hover:opacity-95"
                    style="background-color: var(--ma-primary)"
                    @click="downloadPdf('A4')"
                >
                    Salvar PDF A4
                </button>
                <button
                    type="button"
                    class="rounded-xl border-2 px-5 py-2.5 text-sm font-semibold transition hover:opacity-95"
                    style="border-color: var(--ma-primary); color: var(--ma-primary)"
                    @click="downloadPdf('A3')"
                >
                    Salvar PDF A3
                </button>
            </template>
            <Link :href="href('/')" class="rounded-xl border-2 px-5 py-2.5 text-sm font-medium transition hover:opacity-90" style="border-color: var(--ma-primary); color: var(--ma-primary)">
                Voltar à área de membros
            </Link>
        </div>
    </div>
</template>
