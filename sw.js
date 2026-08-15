/**
 * sw.js — ArenaReserve Service Worker for Web Push & Mobile Browser Notifications
 */
const CACHE_NAME = 'arena-reserve-v1';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

// ── Notification Click Handler ──────────────────────────────────
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const targetUrl = event.notification.data && event.notification.data.link
        ? event.notification.data.link
        : '/explore.php';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
            // If already open, focus it and navigate
            for (let i = 0; i < windowClients.length; i++) {
                const client = windowClients[i];
                if ('focus' in client) {
                    client.navigate(targetUrl);
                    return client.focus();
                }
            }
            // Otherwise open a new window
            if (self.clients.openWindow) {
                return self.clients.openWindow(targetUrl);
            }
        })
    );
});

// ── Background Push Event (For future backend Web Push API payload) ──
self.addEventListener('push', (event) => {
    let data = {
        title: 'ArenaReserve Alert',
        message: 'You have a new update on ArenaReserve!',
        link: 'explore.php'
    };

    if (event.data) {
        try {
            data = event.data.json();
        } catch (e) {
            data.message = event.data.text();
        }
    }

    const options = {
        body: data.message,
        icon: '/assets/logo.png',
        badge: '/assets/logo.png',
        vibrate: [200, 100, 200],
        data: { link: data.link },
        actions: [
            { action: 'open', title: 'View Details' }
        ]
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});
