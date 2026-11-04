/**
 * Controllers de playback externo para embeds da área de membros
 * (Player.js / Panda postMessage / Wistia E-v1).
 * Permite a barra unificada Stacker controlar Bunny/Panda/Loom/Wistia.
 */

let wistiaApiPromise = null;

function loadWistiaApiOnce() {
    if (typeof window === 'undefined') {
        return Promise.reject(new Error('no_window'));
    }
    if (window.Wistia) {
        return Promise.resolve(window.Wistia);
    }
    if (wistiaApiPromise) return wistiaApiPromise;

    wistiaApiPromise = new Promise((resolve, reject) => {
        window._wq = window._wq || [];
        if (!document.querySelector('script[data-wistia-e-v1]')) {
            const script = document.createElement('script');
            script.src = 'https://fast.wistia.net/assets/external/E-v1.js';
            script.async = true;
            script.dataset.wistiaEV1 = '1';
            script.onerror = () => {
                wistiaApiPromise = null;
                reject(new Error('wistia_api_load_failed'));
            };
            document.head.appendChild(script);
        }

        const startedAt = Date.now();
        const timer = setInterval(() => {
            if (window.Wistia) {
                clearInterval(timer);
                resolve(window.Wistia);
                return;
            }
            if (Date.now() - startedAt > 12000) {
                clearInterval(timer);
                wistiaApiPromise = null;
                reject(new Error('wistia_api_timeout'));
            }
        }, 40);
    });

    return wistiaApiPromise;
}

const PLAYER_JS_CONTEXT = 'player.js';
const PLAYER_JS_VERSION = '0.0.11';
const PLAYER_JS_EVENTS = ['play', 'pause', 'ended', 'timeupdate', 'seeked', 'error'];

/**
 * @param {string} origin
 * @returns {boolean}
 */
export function isBunnyEmbedOrigin(origin) {
    try {
        const host = new URL(origin).hostname.replace(/^www\./i, '').toLowerCase();
        return (
            host === 'player.mediadelivery.net' ||
            host === 'iframe.mediadelivery.net' ||
            host.endsWith('.mediadelivery.net')
        );
    } catch (_) {
        return false;
    }
}

/**
 * @param {string} origin
 * @returns {boolean}
 */
export function isPandaEmbedOrigin(origin) {
    try {
        const host = new URL(origin).hostname.replace(/^www\./i, '').toLowerCase();
        return host === 'pandavideo.com.br' || host.endsWith('.pandavideo.com.br');
    } catch (_) {
        return false;
    }
}

/**
 * @param {string} origin
 * @returns {boolean}
 */
export function isLoomEmbedOrigin(origin) {
    try {
        const host = new URL(origin).hostname.replace(/^www\./i, '').toLowerCase();
        return host === 'loom.com' || host.endsWith('.loom.com');
    } catch (_) {
        return false;
    }
}

function parseMessageData(raw) {
    if (raw == null) return null;
    if (typeof raw === 'object') return raw;
    if (typeof raw !== 'string') return null;
    try {
        return JSON.parse(raw);
    } catch (_) {
        return null;
    }
}

/**
 * Cliente mínimo do protocolo Player.js (Bunny / Loom).
 * @param {HTMLIFrameElement} iframe
 * @param {{ isTrustedOrigin: (origin: string) => boolean, onEvent?: (name: string, payload?: object) => void }} options
 */
export function createPlayerJsController(iframe, options) {
    const { isTrustedOrigin, onEvent } = options;
    let destroyed = false;
    let ready = false;

    function post(method, value) {
        if (destroyed || !iframe?.contentWindow) return;
        const payload = {
            context: PLAYER_JS_CONTEXT,
            version: PLAYER_JS_VERSION,
            method,
        };
        if (value !== undefined) payload.value = value;
        try {
            iframe.contentWindow.postMessage(payload, '*');
        } catch (_) {}
    }

    function subscribeEvents() {
        for (const eventName of PLAYER_JS_EVENTS) {
            post('addEventListener', eventName);
        }
    }

    function onMessage(e) {
        if (destroyed) return;
        if (!isTrustedOrigin(e.origin)) return;
        const data = parseMessageData(e.data);
        if (!data || data.context !== PLAYER_JS_CONTEXT) return;

        if (data.event === 'ready') {
            ready = true;
            subscribeEvents();
            onEvent?.('ready', data.value || {});
            return;
        }

        if (data.event === 'timeupdate') {
            const seconds = Number(data.value?.seconds);
            const duration = Number(data.value?.duration);
            onEvent?.('timeupdate', {
                seconds: Number.isFinite(seconds) ? seconds : 0,
                duration: Number.isFinite(duration) ? duration : 0,
            });
            return;
        }

        if (data.event === 'play' || data.event === 'pause' || data.event === 'ended' || data.event === 'seeked') {
            onEvent?.(data.event, data.value || {});
        }
    }

    window.addEventListener('message', onMessage);

    // Alguns players só disparam ready após o listener; re-pede estado após o load do iframe.
    const onLoad = () => {
        if (destroyed) return;
        subscribeEvents();
        post('getPaused');
    };
    iframe.addEventListener('load', onLoad);

    return {
        get ready() {
            return ready;
        },
        play() {
            post('play');
        },
        pause() {
            post('pause');
        },
        seek(seconds) {
            const t = Number(seconds);
            if (!Number.isFinite(t)) return;
            post('setCurrentTime', Math.max(0, t));
        },
        /** @param {number} deltaSeconds */
        seekBy(deltaSeconds) {
            post('getCurrentTime');
            // Fallback relativo: pede currentTime via evento timeupdate + seek no próximo tick pelo caller.
            // Mantemos seek absoluto no adapter unificado.
            void deltaSeconds;
        },
        destroy() {
            destroyed = true;
            window.removeEventListener('message', onMessage);
            iframe.removeEventListener('load', onLoad);
        },
    };
}

/**
 * Controller Panda via postMessage (sem carregar api.v2.js).
 * @param {HTMLIFrameElement} iframe
 * @param {{ onEvent?: (name: string, payload?: object) => void }} options
 */
export function createPandaController(iframe, options) {
    const { onEvent } = options;
    let destroyed = false;
    let ready = false;
    let lastKnownTime = 0;
    let lastKnownDuration = 0;

    function post(type, parameter) {
        if (destroyed || !iframe?.contentWindow) return;
        const payload = { type };
        if (parameter !== undefined) payload.parameter = parameter;
        try {
            iframe.contentWindow.postMessage(payload, '*');
        } catch (_) {}
    }

    function onMessage(e) {
        if (destroyed) return;
        if (!isPandaEmbedOrigin(e.origin)) return;
        const data = parseMessageData(e.data);
        if (!data || typeof data !== 'object') return;

        const message = data.message || data.type;
        if (!message || typeof message !== 'string') return;

        if (message === 'panda_ready' || message === 'ready') {
            ready = true;
            // Não esconde controles nativos: no mobile a barra Stacker + play-large do Panda
            // precisam coexistir se o postMessage falhar no 1º gesto.
            onEvent?.('ready', data);
            return;
        }

        if (message === 'panda_play' || message === 'play') {
            onEvent?.('play', data);
            return;
        }
        if (message === 'panda_pause' || message === 'pause') {
            onEvent?.('pause', data);
            return;
        }
        if (message === 'panda_ended' || message === 'ended') {
            onEvent?.('ended', data);
            return;
        }
        if (message === 'panda_timeupdate' || message === 'timeupdate') {
            const seconds = Number(data.currentTime ?? data.seconds ?? data.time);
            const duration = Number(data.duration ?? data.videoDuration);
            if (Number.isFinite(seconds)) lastKnownTime = seconds;
            if (Number.isFinite(duration) && duration > 0) lastKnownDuration = duration;
            onEvent?.('timeupdate', {
                seconds: lastKnownTime,
                duration: lastKnownDuration,
            });
            return;
        }
        if (message === 'panda_seeked' || message === 'seeked') {
            const seconds = Number(data.currentTime ?? data.seconds);
            if (Number.isFinite(seconds)) lastKnownTime = seconds;
            onEvent?.('seeked', { seconds: lastKnownTime });
        }
    }

    window.addEventListener('message', onMessage);

    return {
        get ready() {
            return ready;
        },
        play() {
            post('play');
        },
        pause() {
            post('pause');
        },
        seek(seconds) {
            const t = Number(seconds);
            if (!Number.isFinite(t)) return;
            post('currentTime', Math.max(0, t));
            lastKnownTime = Math.max(0, t);
        },
        seekBy(deltaSeconds) {
            const delta = Number(deltaSeconds);
            if (!Number.isFinite(delta) || delta === 0) return;
            if (delta > 0) post('forward', delta);
            else post('rewind', Math.abs(delta));
        },
        destroy() {
            destroyed = true;
            window.removeEventListener('message', onMessage);
        },
    };
}

/**
 * Wistia via E-v1.js + _wq (funciona com iframe embed).
 * @param {HTMLIFrameElement} iframe
 * @param {{ mediaId?: string, onEvent?: (name: string, payload?: object) => void }} options
 */
export function createWistiaController(iframe, options) {
    const { mediaId, onEvent } = options;
    let destroyed = false;
    let video = null;
    void iframe;

    if (!mediaId) {
        return null;
    }

    window._wq = window._wq || [];
    window._wq.push({
        id: mediaId,
        onReady(handle) {
            if (destroyed) return;
            video = handle;
            try {
                handle.bind('play', () => onEvent?.('play'));
                handle.bind('pause', () => onEvent?.('pause'));
                handle.bind('end', () => onEvent?.('ended'));
                handle.bind('secondchange', (seconds) => {
                    const duration = Number(handle.duration?.()) || 0;
                    onEvent?.('timeupdate', {
                        seconds: Number(seconds) || 0,
                        duration,
                    });
                });
                ['playbar', 'fullscreenButton', 'settingsControl', 'volumeControl'].forEach((control) => {
                    try {
                        handle.setControlEnabled?.(control, false);
                    } catch (_) {
                        /* controle pode não existir neste player */
                    }
                });
            } catch (_) {
                /* bind pode falhar se o handle for parcial */
            }
            onEvent?.('ready');
            try {
                onEvent?.('timeupdate', {
                    seconds: Number(handle.time?.()) || 0,
                    duration: Number(handle.duration?.()) || 0,
                });
            } catch (_) {}
        },
    });

    void loadWistiaApiOnce().catch(() => {
        /* falha de script: barra ainda aparece; play nativo do iframe pode funcionar */
    });

    return {
        get ready() {
            return !!video;
        },
        play() {
            try {
                video?.play?.();
            } catch (_) {}
        },
        pause() {
            try {
                video?.pause?.();
            } catch (_) {}
        },
        seek(seconds) {
            const t = Number(seconds);
            if (!Number.isFinite(t) || !video) return;
            try {
                video.time?.(Math.max(0, t));
            } catch (_) {}
        },
        seekBy(deltaSeconds) {
            if (!video) return;
            const delta = Number(deltaSeconds);
            if (!Number.isFinite(delta) || delta === 0) return;
            try {
                const current = Number(video.time?.()) || 0;
                const duration = Number(video.duration?.()) || 0;
                const next = Math.max(0, current + delta);
                video.time?.(duration > 0 ? Math.min(duration, next) : next);
            } catch (_) {}
        },
        destroy() {
            destroyed = true;
            video = null;
        },
    };
}

/**
 * @param {HTMLIFrameElement} iframe
 * @param {'bunny'|'panda'|'loom'|'wistia'|string} provider
 * @param {{ mediaId?: string, onEvent?: (name: string, payload?: object) => void }} [options]
 */
export function createEmbedPlaybackController(iframe, provider, options = {}) {
    if (!iframe || typeof window === 'undefined') return null;

    if (provider === 'panda') {
        return createPandaController(iframe, options);
    }

    if (provider === 'wistia') {
        return createWistiaController(iframe, options);
    }

    if (provider === 'bunny') {
        return createPlayerJsController(iframe, {
            isTrustedOrigin: isBunnyEmbedOrigin,
            onEvent: options.onEvent,
        });
    }

    if (provider === 'loom') {
        return createPlayerJsController(iframe, {
            isTrustedOrigin: isLoomEmbedOrigin,
            onEvent: options.onEvent,
        });
    }

    return null;
}

/** Providers que usam a barra unificada Stacker. */
export function providerUsesUnifiedEmbedControls(provider) {
    return provider === 'bunny' || provider === 'panda' || provider === 'loom' || provider === 'wistia';
}

/**
 * Não cobrir o iframe com veil: Wistia (play async no iOS) e Panda (precisa do play-large nativo).
 */
export function providerNeedsOpenEmbedClick(provider) {
    return provider === 'wistia' || provider === 'panda';
}

/** Barra Stacker sempre visível (Panda: sem auto-hide para não “sumir” no mobile). */
export function providerPinsUnifiedControls(provider) {
    return provider === 'panda';
}
