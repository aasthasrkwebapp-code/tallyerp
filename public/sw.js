// TallyERP service worker: makes the app installable and shows an offline page.
// Accounting pages are never cached, so no business data is stored on the device.
const CACHE = 'tallyerp-v1';
const OFFLINE = '/offline.html';
const STATIC = [OFFLINE, '/icons/icon-192.png', '/icons/icon-512.png', '/manifest.webmanifest'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(STATIC)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Pages: always from the network; if that fails, the offline page.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE)));
        return;
    }

    // Only the app's own icons/manifest come from the cache.
    const url = new URL(request.url);
    if (request.method === 'GET' && url.origin === self.location.origin && STATIC.includes(url.pathname)) {
        event.respondWith(caches.match(request).then((hit) => hit || fetch(request)));
    }
});
