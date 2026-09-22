<script setup>
import { computed, ref, onUnmounted } from 'vue';
import {
    resolveCertificateBody,
    certificateFontCssVars,
} from '@/lib/certificateText';
import {
    mergeCertificateLayout,
    fieldBoxStyle,
    certificateAspectRatio,
} from '@/lib/certificateLayout';

const props = defineProps({
    certificate: { type: Object, required: true },
    recipientName: { type: String, default: 'Aluno' },
    productName: { type: String, default: '' },
    /** 'preview' | 'student' */
    mode: { type: String, default: 'student' },
    /** Permite arrastar campos (builder). */
    editable: { type: Boolean, default: false },
    selectedField: { type: String, default: null },
    showBlockedOverlay: { type: Boolean, default: false },
    blockedMessage: { type: String, default: '' },
    /** Força formato da folha no preview (A4/A3). Senão usa certificate.print_format. */
    printFormat: { type: String, default: null },
});

const emit = defineEmits(['update:layout', 'select-field']);

const layout = computed(() => mergeCertificateLayout(props.certificate?.layout));
const customPositions = computed(() => layout.value.custom_positions === true);
const backgroundOnly = computed(() => layout.value.background_only === true);
const sheetFormat = computed(() => {
    const raw = props.printFormat || props.certificate?.print_format || 'A4';
    return raw === 'A3' ? 'A3' : 'A4';
});
const sheetAspectRatio = computed(() => certificateAspectRatio(sheetFormat.value));

const courseTitle = computed(() => props.certificate?.title || props.productName || '');
const platformName = computed(() => props.certificate?.platform_name || '');
const issuedAtLabel = computed(
    () => props.certificate?.issued_at_full || props.certificate?.issued_at || (props.mode === 'preview' ? '24/02/2025 14:30' : '')
);
const certPrimary = computed(() => props.certificate?.primary_color || 'var(--ma-primary)');
const certBgUrl = computed(() => props.certificate?.background_image_url || null);
const certTextColor = computed(() => props.certificate?.text_color || '#262626');
const certSignatureFont = computed(() => props.certificate?.signature_font_family || 'Dancing Script');
const certSignatureFontUrl = computed(() => {
    const name = certSignatureFont.value;
    if (!name) return null;
    return `https://fonts.googleapis.com/css2?family=${encodeURIComponent(name).replace(/%20/g, '+')}&display=swap`;
});
const certOverlayEnabled = computed(() => certBgUrl.value && props.certificate?.background_overlay_enabled);
const certOverlayColor = computed(() => props.certificate?.background_overlay_color || '#000000');
const certOverlayOpacity = computed(() => {
    const raw = props.certificate?.background_overlay_opacity ?? 50;
    return (raw <= 1 ? raw * 100 : raw) / 100;
});

const headerText = computed(() => props.certificate?.header_text || 'Certificado de conclusão');
const recipientIntroText = computed(() => props.certificate?.recipient_intro_text || 'Certificamos que');
const completionText = computed(() => props.certificate?.completion_text || 'completou com sucesso o curso em');
const issuedOnText = computed(() => props.certificate?.issued_on_text || 'em');
const instructorLabelText = computed(() => props.certificate?.instructor_label_text || 'Assinatura do Instrutor');
const platformLabelText = computed(() => props.certificate?.platform_label_text || 'Plataforma de Cursos');
const durationLabelText = computed(() => props.certificate?.duration_label_text || 'Duração');
const durationEnabled = computed(() => props.certificate?.duration_enabled !== false);
const hasBodyTemplate = computed(() => String(props.certificate?.body_template || '').trim() !== '');
const durationText = computed(() => {
    if (!durationEnabled.value) return '';
    return props.certificate?.duration_text || (props.mode === 'preview' ? '40 horas' : '');
});

const certificateTextVars = computed(() => ({
    aluno: props.recipientName || 'Aluno',
    curso: courseTitle.value,
    data: issuedAtLabel.value || '',
    plataforma: platformName.value,
    carga_horaria: durationText.value,
}));

const resolvedBodyText = computed(() =>
    resolveCertificateBody(props.certificate?.body_template, certificateTextVars.value)
);

const certAreaStyle = computed(() => ({
    fontFamily: props.certificate?.font_family || 'sans-serif',
    backgroundColor: certBgUrl.value ? 'transparent' : '#fff',
    ...certificateFontCssVars(props.certificate?.font_scale ?? 100, false),
    '--cert-primary': certPrimary.value,
    '--cert-text': certBgUrl.value ? (props.certificate?.text_color || '#171717') : certTextColor.value,
    '--cert-title':
        certBgUrl.value && props.certificate?.title_color
            ? props.certificate.title_color
            : certPrimary.value,
}));

const canvasRef = ref(null);
const dragging = ref(null);

function fieldVisible(id) {
    return layout.value.fields?.[id]?.visible !== false;
}

function selectField(id) {
    if (!props.editable || !customPositions.value) return;
    emit('select-field', id);
}

function patchField(id, partial) {
    const next = mergeCertificateLayout(layout.value);
    next.fields[id] = { ...next.fields[id], ...partial };
    emit('update:layout', next);
}

function onPointerDown(e, id) {
    if (!props.editable || !customPositions.value) return;
    e.preventDefault();
    e.stopPropagation();
    emit('select-field', id);
    const el = canvasRef.value;
    if (!el) return;
    const rect = el.getBoundingClientRect();
    dragging.value = { id, rect };
    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
}

function onPointerMove(e) {
    if (!dragging.value) return;
    const { id, rect } = dragging.value;
    if (!rect.width || !rect.height) return;
    const x = ((e.clientX - rect.left) / rect.width) * 100;
    const y = ((e.clientY - rect.top) / rect.height) * 100;
    patchField(id, {
        x: Math.max(0, Math.min(100, Math.round(x * 10) / 10)),
        y: Math.max(0, Math.min(100, Math.round(y * 10) / 10)),
    });
}

function onPointerUp() {
    dragging.value = null;
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
}

onUnmounted(() => {
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
});

function fieldClass(id) {
    if (!props.editable || !customPositions.value) return '';
    const selected = props.selectedField === id;
    return [
        'cursor-move outline outline-1 outline-dashed',
        selected ? 'outline-sky-500 bg-sky-500/10' : 'outline-transparent hover:outline-sky-400/60 hover:bg-sky-500/5',
    ].join(' ');
}
</script>

<template>
    <link v-if="certSignatureFontUrl" rel="stylesheet" :href="certSignatureFontUrl" />
    <div
        ref="canvasRef"
        class="certificate-print-area relative w-full overflow-hidden rounded-2xl border border-zinc-200 shadow-md dark:border-zinc-500 print:rounded-none print:border-0 print:shadow-none"
        :style="[{ aspectRatio: sheetAspectRatio }, certAreaStyle]"
    >
        <img
            v-if="certBgUrl"
            :src="certBgUrl"
            alt=""
            class="certificate-bg-image pointer-events-none absolute inset-0 h-full w-full object-cover"
            style="z-index: 0"
        />

        <div
            v-if="showBlockedOverlay"
            class="certificate-no-print pointer-events-none absolute inset-0 z-30 flex flex-col items-center justify-center bg-black/60 p-6 print:hidden"
            aria-hidden="true"
        >
            <div class="flex flex-col items-center gap-3 rounded-xl bg-zinc-900/95 px-6 py-5 text-center shadow-xl">
                <svg class="h-12 w-12 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
                <p class="text-lg font-semibold text-white">Certificado bloqueado</p>
                <p class="max-w-xs text-sm text-zinc-300">{{ blockedMessage }}</p>
            </div>
        </div>

        <template v-if="!backgroundOnly">
            <div class="certificate-corners pointer-events-none absolute left-0 top-0 h-16 w-16 rounded-tl-lg border-l-4 border-t-4 print:hidden" style="border-color: var(--cert-primary); z-index: 1" aria-hidden="true" />
            <div class="certificate-corners pointer-events-none absolute right-0 top-0 h-16 w-16 rounded-tr-lg border-r-4 border-t-4 print:hidden" style="border-color: var(--cert-primary); z-index: 1" aria-hidden="true" />
            <div class="certificate-corners pointer-events-none absolute bottom-0 left-0 h-16 w-16 rounded-bl-lg border-b-4 border-l-4 print:hidden" style="border-color: var(--cert-primary); z-index: 1" aria-hidden="true" />
            <div class="certificate-corners pointer-events-none absolute bottom-0 right-0 h-16 w-16 rounded-br-lg border-b-4 border-r-4 print:hidden" style="border-color: var(--cert-primary); z-index: 1" aria-hidden="true" />
            <div
                class="certificate-watermark pointer-events-none absolute inset-0 flex items-center justify-center opacity-[0.06] print:hidden"
                style="z-index: 1"
            >
                <span
                    class="whitespace-nowrap font-bold"
                    style="color: var(--cert-primary); transform: rotate(-35deg); font-size: var(--cert-watermark-size)"
                >
                    {{ platformName }}
                </span>
            </div>
        </template>

        <div
            v-if="certOverlayEnabled"
            class="pointer-events-none absolute inset-0"
            style="z-index: 1"
            :style="{ backgroundColor: certOverlayColor, opacity: certOverlayOpacity }"
            aria-hidden="true"
        />

        <!-- Modo clássico: empilhado -->
        <div
            v-if="!customPositions"
            class="certificate-inner relative flex h-full flex-col px-[4%] py-[5%]"
            style="z-index: 2; box-sizing: border-box"
        >
            <div class="certificate-body flex flex-col items-center text-center">
                <div
                    v-if="!backgroundOnly"
                    class="relative flex h-14 w-14 items-center justify-center rounded-full text-[var(--cert-primary)]"
                >
                    <div class="absolute inset-0 rounded-full" style="background-color: var(--cert-primary); opacity: 0.15" aria-hidden="true" />
                    <svg class="relative z-10 h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
                    </svg>
                </div>
                <p
                    class="font-semibold uppercase tracking-[0.2em]"
                    :class="backgroundOnly ? '' : 'mt-3'"
                    style="color: var(--cert-text); font-size: var(--cert-header-size)"
                >
                    {{ headerText }}
                </p>
            </div>

            <h2 class="certificate-body mt-4 text-center font-bold sm:mt-6" style="color: var(--cert-title); font-size: var(--cert-title-size)">
                {{ courseTitle }}
            </h2>

            <div class="certificate-body mt-4 text-center sm:mt-6" style="color: var(--cert-text); font-size: var(--cert-body-size)">
                <template v-if="hasBodyTemplate">
                    <p class="whitespace-pre-wrap leading-relaxed">{{ resolvedBodyText }}</p>
                </template>
                <template v-else>
                    <p>{{ recipientIntroText }}</p>
                    <p class="mt-2">
                        <span class="inline-block border-b-2 px-1 font-bold" style="border-color: var(--cert-primary); color: var(--cert-text)">{{ recipientName || 'Aluno' }}</span>
                    </p>
                    <p class="mt-3">
                        {{ completionText }} <strong>{{ platformName }}</strong>
                    </p>
                    <p v-if="issuedAtLabel" class="mt-2" style="color: var(--cert-text); opacity: 0.9">
                        {{ issuedOnText }} {{ issuedAtLabel }}
                    </p>
                    <p v-if="durationText" class="mt-2" style="opacity: 0.9">
                        {{ durationLabelText }}: <strong>{{ durationText }}</strong>
                    </p>
                </template>
            </div>

            <div
                class="certificate-footer mt-auto grid grid-cols-2 gap-8 border-t pt-6"
                :class="backgroundOnly ? 'border-transparent' : ''"
                style="border-color: rgba(0,0,0,0.12); color: var(--cert-text); font-size: var(--cert-footer-size)"
            >
                <div>
                    <p class="font-medium uppercase tracking-wide" style="opacity: 0.85">{{ instructorLabelText }}</p>
                    <p class="certificate-signature mt-1 font-medium" :style="{ fontFamily: certSignatureFont, color: 'var(--cert-text)' }">{{ certificate.signature_text || 'Instrutor' }}</p>
                </div>
                <div class="text-right">
                    <p class="font-semibold">{{ platformName }}</p>
                    <p style="opacity: 0.85">{{ platformLabelText }}</p>
                </div>
            </div>
        </div>

        <!-- Modo custom: campos posicionáveis -->
        <div v-else class="absolute inset-0" style="z-index: 2">
            <div
                v-if="fieldVisible('header')"
                :class="fieldClass('header')"
                :style="fieldBoxStyle(layout.fields.header)"
                @pointerdown="onPointerDown($event, 'header')"
                @click="selectField('header')"
            >
                <p class="font-semibold uppercase tracking-[0.2em]" style="color: var(--cert-text); font-size: var(--cert-header-size)">
                    {{ headerText }}
                </p>
            </div>

            <div
                v-if="fieldVisible('title')"
                :class="fieldClass('title')"
                :style="fieldBoxStyle(layout.fields.title)"
                @pointerdown="onPointerDown($event, 'title')"
                @click="selectField('title')"
            >
                <h2 class="font-bold leading-tight" style="color: var(--cert-title); font-size: var(--cert-title-size)">
                    {{ courseTitle }}
                </h2>
            </div>

            <div
                v-if="fieldVisible('body')"
                :class="fieldClass('body')"
                :style="fieldBoxStyle(layout.fields.body)"
                @pointerdown="onPointerDown($event, 'body')"
                @click="selectField('body')"
            >
                <div style="color: var(--cert-text); font-size: var(--cert-body-size)">
                    <template v-if="hasBodyTemplate">
                        <p class="whitespace-pre-wrap leading-relaxed">{{ resolvedBodyText }}</p>
                    </template>
                    <template v-else>
                        <p>{{ recipientIntroText }}</p>
                        <p class="mt-1">
                            <span class="inline-block border-b-2 px-1 font-bold" style="border-color: var(--cert-primary); color: var(--cert-text)">{{ recipientName || 'Aluno' }}</span>
                        </p>
                        <p class="mt-2">
                            {{ completionText }} <strong>{{ platformName }}</strong>
                        </p>
                    </template>
                </div>
            </div>

            <div
                v-if="fieldVisible('date') && (issuedAtLabel || editable)"
                :class="fieldClass('date')"
                :style="fieldBoxStyle(layout.fields.date)"
                @pointerdown="onPointerDown($event, 'date')"
                @click="selectField('date')"
            >
                <p style="color: var(--cert-text); font-size: var(--cert-body-size); opacity: 0.95">
                    {{ issuedOnText }} {{ issuedAtLabel || '24/02/2025 14:30' }}
                </p>
            </div>

            <div
                v-if="fieldVisible('duration') && (durationText || editable)"
                :class="fieldClass('duration')"
                :style="fieldBoxStyle(layout.fields.duration)"
                @pointerdown="onPointerDown($event, 'duration')"
                @click="selectField('duration')"
            >
                <p style="color: var(--cert-text); font-size: var(--cert-footer-size); opacity: 0.95">
                    {{ durationLabelText }}: <strong>{{ durationText || '40 horas' }}</strong>
                </p>
            </div>

            <div
                v-if="fieldVisible('signature')"
                :class="fieldClass('signature')"
                :style="fieldBoxStyle(layout.fields.signature)"
                @pointerdown="onPointerDown($event, 'signature')"
                @click="selectField('signature')"
            >
                <div style="color: var(--cert-text); font-size: var(--cert-footer-size)">
                    <p class="font-medium uppercase tracking-wide" style="opacity: 0.85">{{ instructorLabelText }}</p>
                    <p class="certificate-signature mt-1 font-medium" :style="{ fontFamily: certSignatureFont, color: 'var(--cert-text)' }">{{ certificate.signature_text || 'Instrutor' }}</p>
                </div>
            </div>

            <div
                v-if="fieldVisible('platform')"
                :class="fieldClass('platform')"
                :style="fieldBoxStyle(layout.fields.platform)"
                @pointerdown="onPointerDown($event, 'platform')"
                @click="selectField('platform')"
            >
                <div style="color: var(--cert-text); font-size: var(--cert-footer-size)">
                    <p class="font-semibold">{{ platformName }}</p>
                    <p style="opacity: 0.85">{{ platformLabelText }}</p>
                </div>
            </div>
        </div>
    </div>
</template>
