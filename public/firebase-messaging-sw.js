/* Firebase Cloud Messaging — painel PWA (scope /painel/) */
importScripts('https://www.gstatic.com/firebasejs/11.6.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/11.6.0/firebase-messaging-compat.js');

let messaging = null;
let backgroundHandlerRegistered = false;

function showNotificationFromPayload(payload) {
    const data = payload.data || {};
    const title = data.title || 'Notificação';
    const body = data.body || '';
    const url = data.url || null;
    const icon = data.icon || data.badge || null;
    const tag = data.tag || url || 'panel-fcm-push';
    const options = {
        body: body,
        tag: tag,
        renotify: false,
        data: { url: url },
    };
    // No Android o ícone do PWA (logo da plataforma no manifesto) já fica à esquerda.
    // icon/badge desenham uma segunda logo à direita.
    const android = /Android/i.test((self.navigator && self.navigator.userAgent) || '');
    if (!android && icon) {
        options.icon = icon;
    }

    return self.registration.showNotification(title, options);
}

function initFirebase(config) {
    if (!config || !config.apiKey) return;
    if (!firebase.apps.length) {
        firebase.initializeApp(config);
    }
    messaging = firebase.messaging();
    if (backgroundHandlerRegistered) {
        return;
    }
    backgroundHandlerRegistered = true;
    messaging.onBackgroundMessage(function (payload) {
        // SDK/navegador já exibem quando há payload.notification — evita duplicata.
        if (payload.notification) {
            return;
        }
        return showNotificationFromPayload(payload);
    });
}

fetch(new URL('/painel/push/client-config.json', self.location.origin).href)
    .then(function (res) {
        return res.json();
    })
    .then(function (json) {
        if (json && json.enabled && json.firebase) {
            initFirebase(json.firebase);
        }
    })
    .catch(function () {});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    const url = event.notification.data?.url;
    if (!url) return;
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (let i = 0; i < clientList.length; i++) {
                const base = String(url).split('?')[0];
                if (clientList[i].url === url || clientList[i].url.startsWith(base)) {
                    return clientList[i].focus();
                }
            }
            if (self.clients.openWindow) return self.clients.openWindow(url);
        })
    );
});

self.addEventListener('install', function () {
    self.skipWaiting();
});
self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});
self.addEventListener('message', function (event) {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
