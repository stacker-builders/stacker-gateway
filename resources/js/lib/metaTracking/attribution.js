const FBC_STORAGE_KEY = 'getfy_meta_fbclid';

function getCookie(name) {
    if (typeof document === 'undefined') return null;
    const escaped = String(name).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const m = document.cookie ? document.cookie.match(new RegExp('(?:^|; )' + escaped + '=([^;]*)')) : null;
    if (!m) return null;
    try {
        return decodeURIComponent(m[1]);
    } catch {
        return m[1];
    }
}

function setCookie(name, value, maxAgeSeconds = 7776000) {
    if (typeof document === 'undefined') return;
    const secure = typeof window !== 'undefined' && window.location?.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=${maxAgeSeconds}; SameSite=Lax${secure}`;
}

export function getFbp() {
    return getCookie('_fbp');
}

export function getFbc() {
    return getCookie('_fbc');
}

export function getFbclidFromUrl() {
    if (typeof window === 'undefined') return null;
    const fbclid = new URLSearchParams(window.location.search).get('fbclid');
    return fbclid && String(fbclid).trim() !== '' ? String(fbclid).trim() : null;
}

function fbclidFromFbc(fbc) {
    if (!fbc || typeof fbc !== 'string') return null;
    const parts = fbc.split('.');
    const last = parts[parts.length - 1];
    return last && last.trim() !== '' ? last.trim() : null;
}

function buildFbcFromFbclid(fbclid) {
    return `fb.1.${Date.now()}.${fbclid}`;
}

/**
 * Meta standard: create or refresh _fbc from fbclid when landing from ads.
 */
export function ensureFbcFromFbclid() {
    const fbclid = getFbclidFromUrl();
    const existing = getFbc();

    if (fbclid) {
        const existingClick = fbclidFromFbc(existing);
        if (!existing || existingClick !== fbclid) {
            const fbc = buildFbcFromFbclid(fbclid);
            setCookie('_fbc', fbc);
            try {
                sessionStorage.setItem(FBC_STORAGE_KEY, fbc);
            } catch {
                /* ignore */
            }
            return fbc;
        }
        return existing;
    }

    if (existing) return existing;

    try {
        const stored = sessionStorage.getItem(FBC_STORAGE_KEY);
        if (stored) {
            setCookie('_fbc', stored);
            return stored;
        }
    } catch {
        /* ignore */
    }

    return null;
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Brief poll for _fbp after pixel init attempt (improves CAPI match rate).
 */
export async function waitForFbp(maxMs = 1500) {
    const startedAt = Date.now();
    while (Date.now() - startedAt < maxMs) {
        const fbp = getFbp();
        if (fbp) return fbp;
        await sleep(80);
    }
    return getFbp();
}

const MAX_EVENT_SOURCE_URL_LENGTH = 2048;

function truncateEventSourceUrl(url) {
    if (!url || typeof url !== 'string') return undefined;
    const trimmed = url.trim();
    if (trimmed === '') return undefined;
    return trimmed.length <= MAX_EVENT_SOURCE_URL_LENGTH
        ? trimmed
        : trimmed.slice(0, MAX_EVENT_SOURCE_URL_LENGTH);
}

export function getAttributionPayload() {
    ensureFbcFromFbclid();

    const eventSourceUrl = typeof window !== 'undefined'
        ? truncateEventSourceUrl(window.location.href)
        : undefined;

    return {
        fbp: getFbp() || undefined,
        fbc: getFbc() || undefined,
        userAgent: typeof navigator !== 'undefined' ? navigator.userAgent || undefined : undefined,
        eventSourceUrl,
    };
}
