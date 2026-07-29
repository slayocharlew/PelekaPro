const STATIC_CACHE = 'pelekapro-static-v1';
const OWNED_STATIC_ASSETS = [
    '/manifest.webmanifest',
    '/icons/pelekapro-mark.svg',
    '/icons/pelekapro-192.png',
    '/icons/pelekapro-512.png',
];
const PRIVATE_PATHS = [
    /^\/track(?:\/|$)/,
    /^\/tracking(?:\/|$)/,
    /^\/broadcasting\/auth$/,
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(OWNED_STATIC_ASSETS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => key !== STATIC_CACHE)
                    .map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (PRIVATE_PATHS.some((pattern) => pattern.test(url.pathname))) {
        event.respondWith(fetch(request, { cache: 'no-store' }));

        return;
    }

    const isOwnedAsset = OWNED_STATIC_ASSETS.includes(url.pathname);
    const isVersionedBuildAsset = url.pathname.startsWith('/build/assets/');

    if (!isOwnedAsset && !isVersionedBuildAsset) {
        return;
    }

    event.respondWith(
        caches.match(request).then((cached) => cached ?? fetch(request).then((response) => {
            if (response.ok && response.type === 'basic') {
                const copy = response.clone();
                caches.open(STATIC_CACHE).then((cache) => cache.put(request, copy));
            }

            return response;
        }))
    );
});
