/**
 * Layout do certificado: posições em % da folha (A4/A3 paisagem).
 * Compatível com configs antigos (sem layout → modo clássico empilhado).
 */

export const CERTIFICATE_LAYOUT_FIELD_IDS = [
    'header',
    'title',
    'body',
    'date',
    'duration',
    'signature',
    'platform',
];

export const CERTIFICATE_LAYOUT_FIELD_LABELS = {
    header: 'Cabeçalho',
    title: 'Título do curso',
    body: 'Texto / aluno',
    date: 'Data',
    duration: 'Carga horária',
    signature: 'Assinatura',
    platform: 'Plataforma',
};

/** Posições padrão aproximando o layout clássico (centro da folha). */
export const DEFAULT_CERTIFICATE_LAYOUT_FIELDS = {
    header: { visible: true, x: 50, y: 14, w: 80, align: 'center' },
    title: { visible: true, x: 50, y: 28, w: 80, align: 'center' },
    body: { visible: true, x: 50, y: 45, w: 70, align: 'center' },
    date: { visible: true, x: 50, y: 62, w: 50, align: 'center' },
    duration: { visible: true, x: 50, y: 70, w: 45, align: 'center' },
    signature: { visible: true, x: 22, y: 85, w: 36, align: 'left' },
    platform: { visible: true, x: 78, y: 85, w: 36, align: 'right' },
};

export const DEFAULT_CERTIFICATE_LAYOUT = {
    /** Esconde medalha, cantos e marca d'água — ideal com arte de fundo. */
    background_only: false,
    /** Quando true, campos usam x/y/w em %; quando false, layout clássico empilhado. */
    custom_positions: false,
    fields: { ...DEFAULT_CERTIFICATE_LAYOUT_FIELDS },
};

/**
 * Presets rápidos para modelos personalizados.
 * @type {Record<string, { label: string, description: string, apply: (layout: object) => object }>}
 */
export const CERTIFICATE_LAYOUT_PRESETS = {
    hide_art_text: {
        label: 'Arte já tem título',
        description: 'Esconde cabeçalho e nome do curso (a arte já traz isso).',
        apply(layout) {
            const next = mergeCertificateLayout(layout);
            next.background_only = true;
            next.custom_positions = true;
            next.fields.header.visible = false;
            next.fields.title.visible = false;
            return next;
        },
    },
    name_date_signature: {
        label: 'Só nome + data + assinatura',
        description: 'Ideal quando o fundo já tem moldura e textos fixos.',
        apply(layout) {
            const next = mergeCertificateLayout(layout);
            next.background_only = true;
            next.custom_positions = true;
            for (const id of CERTIFICATE_LAYOUT_FIELD_IDS) {
                next.fields[id].visible = ['body', 'date', 'signature'].includes(id);
            }
            // Posições mais típicas de modelo personalizado
            next.fields.body = { ...next.fields.body, x: 50, y: 48, w: 70, align: 'center', visible: true };
            next.fields.date = { ...next.fields.date, x: 50, y: 68, w: 40, align: 'center', visible: true };
            next.fields.signature = { ...next.fields.signature, x: 50, y: 84, w: 40, align: 'center', visible: true };
            return next;
        },
    },
    show_all: {
        label: 'Mostrar todos os campos',
        description: 'Reativa todos os textos nas posições padrão.',
        apply(layout) {
            const next = mergeCertificateLayout(layout);
            next.custom_positions = true;
            next.fields = mergeCertificateLayout({}).fields;
            return next;
        },
    },
};

/**
 * @param {unknown} n
 * @param {number} min
 * @param {number} max
 * @param {number} fallback
 */
export function clampPercent(n, min = 0, max = 100, fallback = 50) {
    const v = Number(n);
    if (!Number.isFinite(v)) return fallback;
    return Math.max(min, Math.min(max, v));
}

/**
 * @param {string} align
 * @returns {'left'|'center'|'right'}
 */
export function normalizeAlign(align) {
    if (align === 'left' || align === 'right' || align === 'center') return align;
    return 'center';
}

/**
 * @param {Record<string, unknown>|null|undefined} field
 * @param {Record<string, unknown>} defaults
 */
export function normalizeLayoutField(field, defaults) {
    const src = field && typeof field === 'object' ? field : {};
    return {
        visible: src.visible !== false,
        x: clampPercent(src.x ?? defaults.x, 0, 100, defaults.x),
        y: clampPercent(src.y ?? defaults.y, 0, 100, defaults.y),
        w: clampPercent(src.w ?? defaults.w, 10, 100, defaults.w),
        align: normalizeAlign(src.align ?? defaults.align),
    };
}

/**
 * @param {Record<string, unknown>|null|undefined} stored
 */
export function mergeCertificateLayout(stored) {
    const base = {
        background_only: false,
        custom_positions: false,
        fields: {},
    };
    const src = stored && typeof stored === 'object' ? stored : {};
    const fields = {};
    for (const id of CERTIFICATE_LAYOUT_FIELD_IDS) {
        fields[id] = normalizeLayoutField(
            src.fields?.[id] ?? src[id],
            DEFAULT_CERTIFICATE_LAYOUT_FIELDS[id]
        );
    }
    return {
        background_only: Boolean(src.background_only ?? base.background_only),
        custom_positions: Boolean(src.custom_positions ?? base.custom_positions),
        fields,
    };
}

/**
 * @param {string} presetId
 * @param {Record<string, unknown>|null|undefined} current
 */
export function applyCertificateLayoutPreset(presetId, current) {
    const preset = CERTIFICATE_LAYOUT_PRESETS[presetId];
    if (!preset) return mergeCertificateLayout(current);
    return preset.apply(current);
}

/**
 * Aspect ratio CSS da folha paisagem.
 * @param {'A4'|'A3'|string} format
 */
export function certificateAspectRatio(format) {
    return format === 'A3' ? '420 / 297' : '297 / 210';
}

/**
 * Estilo absoluto: âncora no centro do bloco (x/y = centro), largura w%.
 * @param {{ x: number, y: number, w: number, align: string }} field
 */
export function fieldBoxStyle(field) {
    const align = normalizeAlign(field.align);
    const textAlign = align;
    return {
        position: 'absolute',
        left: `${clampPercent(field.x)}%`,
        top: `${clampPercent(field.y)}%`,
        width: `${clampPercent(field.w, 10, 100, 70)}%`,
        transform: 'translate(-50%, -50%)',
        textAlign,
        boxSizing: 'border-box',
        padding: '0 4px',
        maxHeight: '40%',
        overflow: 'hidden',
        wordBreak: 'break-word',
    };
}
