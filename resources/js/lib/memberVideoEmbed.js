/**
 * Conversão de URLs da área de membros (YouTube, Vimeo, Wistia, Loom, Bunny Stream, Panda Video e arquivo nativo).
 * A detecção do YouTube permanece igual à original para não alterar o player legado.
 */

export const MEMBER_IFRAME_ALLOW =
    'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen; web-share';

function trimUrl(url) {
    return typeof url === 'string' ? url.trim() : '';
}

function isYoutubeUrl(u) {
    return (
        /^(https?:\/\/)?(www\.|m\.)?youtube\.com\/watch\?.*v=/i.test(u) ||
        /^(https?:\/\/)?youtu\.be\//i.test(u) ||
        /youtube\.com\/embed\//i.test(u)
    );
}

/**
 * @param {string} url
 * @returns {{ id: string, hash: string|null }|null}
 */
export function parseVimeoVideo(url) {
    const u = trimUrl(url);
    if (!u) return null;

    const vidstack = u.match(/^vimeo\/(\d+)(?:\?(?:[^#]*&)?hash=([a-zA-Z0-9]+))?/i);
    if (vidstack) {
        return { id: vidstack[1], hash: vidstack[2] || null };
    }

    if (!/vimeo\.com/i.test(u)) return null;
    if (/progressive_redirect/i.test(u) || /\.m3u8(\?|$)/i.test(u)) return null;

    let id = null;
    let hash = null;

    const nested = u.match(
        /vimeo\.com\/(?:channels\/[^/?#]+|groups\/[^/?#]+\/videos|album\/\d+\/video|showcase\/\d+\/video|ondemand\/[^/?#]+)\/(\d+)/i,
    );
    if (nested) {
        id = nested[1];
    } else {
        const simple = u.match(/vimeo\.com\/(?:video\/)?(\d+)(?:\/([a-zA-Z0-9]+))?/i);
        if (simple) {
            id = simple[1];
            if (simple[2] && !/^\d+$/.test(simple[2])) {
                hash = simple[2];
            }
        }
    }

    try {
        const parsed = new URL(u.includes('://') ? u : `https://${u}`);
        hash = parsed.searchParams.get('h') || parsed.searchParams.get('hash') || hash;
    } catch (_) {
        /* URL relativa ou inválida: hash de path já extraído */
    }

    if (!id) return null;
    return { id, hash: hash || null };
}

export function parseWistiaId(url) {
    const u = trimUrl(url);
    if (!u) return null;
    const patterns = [
        /(?:fast\.)?wistia\.(?:net|com)\/embed\/iframe\/([a-z0-9]+)/i,
        /(?:fast\.)?wistia\.(?:net|com)\/embed\/medias\/([a-z0-9]+)/i,
        /wistia\.(?:net|com)\/medias\/([a-z0-9]+)/i,
        /wi\.st\/(?:medias|embed)\/([a-z0-9]+)/i,
    ];
    for (const pattern of patterns) {
        const match = u.match(pattern);
        if (match?.[1]) return match[1];
    }
    return null;
}

export function parseLoomId(url) {
    const u = trimUrl(url);
    if (!u) return null;
    const match = u.match(/(?:www\.)?(?:use)?loom\.com\/(?:share|embed|looms\/share)\/([a-zA-Z0-9]+)/i);
    return match?.[1] ?? null;
}

/**
 * Bunny Stream: player.mediadelivery.net e iframe.mediadelivery.net (player legado).
 * @param {string} url
 * @returns {{ host: string, libraryId: string, videoId: string, search: string }|null}
 */
export function parseBunnyStream(url) {
    const u = trimUrl(url);
    if (!u) return null;

    let parsed;
    try {
        parsed = new URL(u.includes('://') ? u : `https://${u}`);
    } catch (_) {
        return null;
    }

    const host = parsed.hostname.replace(/^www\./i, '').toLowerCase();
    if (host !== 'player.mediadelivery.net' && host !== 'iframe.mediadelivery.net') {
        return null;
    }

    const path = parsed.pathname.replace(/\/+$/, '');
    const match = path.match(/^\/(embed|play)\/(\d+)\/([a-zA-Z0-9-]+)$/i);
    if (!match) return null;

    return {
        host,
        libraryId: match[2],
        videoId: match[3],
        search: parsed.search || '',
    };
}

export function bunnyEmbedUrl(url) {
    const parsed = parseBunnyStream(url);
    if (!parsed) return null;
    let embed = `https://${parsed.host}/embed/${parsed.libraryId}/${parsed.videoId}${parsed.search}`;
    try {
        const u = new URL(embed);
        // Garante inline no iPhone; autoplay off para o primeiro gesto ser do aluno.
        if (!u.searchParams.has('playsinline')) u.searchParams.set('playsinline', 'true');
        if (!u.searchParams.has('autoplay')) u.searchParams.set('autoplay', 'false');
        embed = u.toString();
    } catch (_) {
        /* mantém URL original */
    }
    return embed;
}

const PANDA_UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
/** Controles nativos do Panda (docs) — mantidos como fallback visível no mobile. */
const PANDA_EMBED_CONTROLS =
    'play-large,play,progress,current-time,volume,captions,settings,fullscreen,rewind,fast-forward';

/**
 * Extrai URL útil se o seller colar o iframe HTML completo do Panda.
 * @param {string} raw
 * @returns {string}
 */
function normalizePandaInput(raw) {
    const u = trimUrl(raw);
    if (!u) return '';
    const iframeSrc = u.match(/src\s*=\s*["']([^"']*(?:pandavideo|b-cdn\.net)[^"']*)["']/i);
    if (iframeSrc?.[1]) return iframeSrc[1].trim();
    return u;
}

/**
 * Converte hosts HLS (b-vz-…) / player genérico no host de embed player-vz-….
 * Ex.: b-vz-e6c99c70-e86.tv.pandavideo.com.br → player-vz-e6c99c70-e86.tv.pandavideo.com.br
 * @param {string} hostname
 * @returns {string|null}
 */
function pandaEmbedHostFromHostname(hostname) {
    const host = String(hostname || '')
        .replace(/^www\./i, '')
        .toLowerCase();

    if (host === 'player.pandavideo.com.br' || host === 'pandavideo.com.br') {
        return 'player.pandavideo.com.br';
    }

    const bVzTv = host.match(/^b-vz-([a-z0-9-]+)\.tv\.pandavideo\.com\.br$/i);
    if (bVzTv) return `player-vz-${bVzTv[1]}.tv.pandavideo.com.br`;

    const bVzCdn = host.match(/^b-vz-([a-z0-9-]+)\.b-cdn\.net$/i);
    if (bVzCdn) return `player-vz-${bVzCdn[1]}.tv.pandavideo.com.br`;

    const playerVz = host.match(/^player-vz-([a-z0-9-]+)\.tv\.pandavideo\.com\.br$/i);
    if (playerVz) return host;

    if (host.endsWith('.pandavideo.com.br')) return host;
    return null;
}

function extractPandaVideoId(parsed) {
    const fromQuery = parsed.searchParams.get('v');
    if (fromQuery && PANDA_UUID_RE.test(fromQuery)) return fromQuery;

    const path = parsed.pathname || '';
    const hls = path.match(
        /\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\/playlist\.m3u8$/i,
    );
    if (hls?.[1]) return hls[1];

    const embedPath = path.match(
        /\/(?:embed\/)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\/?$/i,
    );
    if (embedPath?.[1]) return embedPath[1];

    return null;
}

/**
 * Panda Video: embed, player.pandavideo.com.br e HLS
 * (b-vz-….tv.pandavideo.com.br/{uuid}/playlist.m3u8 ou *.b-cdn.net).
 * @param {string} url
 * @returns {{ host: string, videoId: string, searchParams: URLSearchParams }|null}
 */
export function parsePandaVideo(url) {
    const u = normalizePandaInput(url);
    if (!u) return null;

    let parsed;
    try {
        parsed = new URL(u.includes('://') ? u : `https://${u}`);
    } catch (_) {
        return null;
    }

    const embedHost = pandaEmbedHostFromHostname(parsed.hostname);
    if (!embedHost) return null;

    const videoId = extractPandaVideoId(parsed);
    if (!videoId) return null;

    return {
        host: embedHost,
        videoId,
        searchParams: parsed.searchParams,
    };
}

export function pandaEmbedUrl(url) {
    const parsed = parsePandaVideo(url);
    if (!parsed) return null;

    const embed = new URL(`https://${parsed.host}/embed/`);
    parsed.searchParams.forEach((value, key) => {
        const k = key.toLowerCase();
        if (k === 'controls' || k === 'v') return;
        embed.searchParams.set(key, value);
    });
    embed.searchParams.set('v', parsed.videoId);
    // Garante chrome nativo do Panda (play/pause/seek) além da barra Stacker.
    embed.searchParams.set('controls', PANDA_EMBED_CONTROLS);
    embed.searchParams.set('hideControlsOnStart', 'false');
    return embed.toString();
}

/**
 * @param {string} url
 * @returns {'youtube'|'vimeo'|'wistia'|'loom'|'bunny'|'panda'|'native'}
 */
export function getVideoProviderType(url) {
    const u = trimUrl(url);
    if (!u) return 'native';
    if (isYoutubeUrl(u)) return 'youtube';
    if (parseVimeoVideo(u)) return 'vimeo';
    if (parseWistiaId(u)) return 'wistia';
    if (parseLoomId(u)) return 'loom';
    if (parseBunnyStream(u)) return 'bunny';
    if (parsePandaVideo(u)) return 'panda';
    return 'native';
}

/**
 * Src no formato que o Vidstack 1.x espera, preservando hash de vídeo privado.
 * @param {string} url
 * @returns {string}
 */
export function vimeoVidstackSrc(url) {
    const parsed = parseVimeoVideo(url);
    if (!parsed) return trimUrl(url);
    return parsed.hash ? `vimeo/${parsed.id}?hash=${parsed.hash}` : `vimeo/${parsed.id}`;
}

/**
 * URL de iframe para Wistia/Loom/Bunny Stream/Panda. YouTube e Vimeo não passam por aqui.
 * @param {string} url
 * @returns {string|null}
 */
export function iframeVideoEmbedUrl(url) {
    const wistiaId = parseWistiaId(url);
    if (wistiaId) {
        // Minimiza chrome nativo; a barra Stacker assume play/pause/seek.
        return `https://fast.wistia.net/embed/iframe/${wistiaId}?seo=false&videoFoam=true&controlsVisibleOnLoad=false&playbar=false&fullscreenButton=false&settingsControl=false`;
    }
    const loomId = parseLoomId(url);
    if (loomId) {
        return `https://www.loom.com/embed/${loomId}?hide_share=true&hide_title=true&hide_owner=true&hide_speed=true`;
    }
    const bunny = bunnyEmbedUrl(url);
    if (bunny) return bunny;
    return pandaEmbedUrl(url);
}

/**
 * Converte URL de vídeo para formato embed (iframe) quando aplicável.
 * @param {string} url
 * @returns {string}
 */
export function videoEmbedUrl(url) {
    if (!url || typeof url !== 'string') return url || '';
    const u = url.trim();
    const ytWatch = u.match(/^(https?:\/\/)?(www\.|m\.)?youtube\.com\/watch\?.*v=([a-zA-Z0-9_-]+)/);
    if (ytWatch) return `https://www.youtube.com/embed/${ytWatch[3]}`;
    const ytShort = u.match(/^(https?:\/\/)?youtu\.be\/([a-zA-Z0-9_-]+)/);
    if (ytShort) return `https://www.youtube.com/embed/${ytShort[2]}`;
    if (/youtube\.com\/embed\//i.test(u)) return u;

    const vimeo = parseVimeoVideo(u);
    if (vimeo) {
        const hashQuery = vimeo.hash ? `?h=${vimeo.hash}` : '';
        return `https://player.vimeo.com/video/${vimeo.id}${hashQuery}`;
    }

    return iframeVideoEmbedUrl(u) || u;
}
