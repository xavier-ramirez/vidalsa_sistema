/**
 * Vidalsa PWA Service Worker
 * Estrategia: network-first para HTML (la app es dinámica), cache-first para assets
 * estáticos (/icons, /css, /js, /fonts, /images) que ya vienen con cache-busting via
 * ?v=filemtime. Nunca se cachean rutas admin ni API para evitar data stale.
 *
 * CACHE_VERSION es inyectado por la ruta Laravel que sirve este archivo; el placeholder
 * __CACHE_VERSION__ se reemplaza con filemtime en cada response para que todo cambio
 * en el codigo invalide los caches del SW automaticamente.
 */
const CACHE_VERSION = '__CACHE_VERSION__';
const STATIC_CACHE  = 'vidalsa-static-' + CACHE_VERSION;
const RUNTIME_CACHE = 'vidalsa-runtime-' + CACHE_VERSION;

const PRECACHE_URLS = [
    '/manifest.json',
    '/icons/icon-192.png',
    '/icons/icon-512.png'
];

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(STATIC_CACHE).then((cache) => cache.addAll(PRECACHE_URLS)).catch(() => {})
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => key !== STATIC_CACHE && key !== RUNTIME_CACHE && key.startsWith('vidalsa-'))
                    .map((key) => caches.delete(key))
            )
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Rutas dinámicas que NUNCA se cachean (cambian frecuente o son sensibles).
    // Las rutas /admin/* sí se cachean en RUNTIME_CACHE para servir offline shell.
    if (
        url.pathname.startsWith('/api/') ||
        url.pathname.startsWith('/sync/') ||
        url.pathname.startsWith('/dashboard/') ||
        url.pathname.startsWith('/storage/') ||
        url.pathname.includes('/export') ||
        url.pathname.includes('/acta-traslado') ||
        url.pathname === '/logout'
    ) {
        return;
    }

    const isStaticAsset =
        url.pathname.startsWith('/icons/') ||
        url.pathname.startsWith('/css/') ||
        url.pathname.startsWith('/js/') ||
        url.pathname.startsWith('/fonts/') ||
        url.pathname.startsWith('/images/') ||
        url.pathname.startsWith('/img/') ||
        url.pathname === '/manifest.json' ||
        url.pathname === '/favicon.png' ||
        url.pathname === '/favicon.ico';

    if (isStaticAsset) {
        event.respondWith(
            caches.match(request).then((cached) => {
                const networkFetch = fetch(request).then((response) => {
                    if (response && response.status === 200) {
                        const copy = response.clone();
                        caches.open(RUNTIME_CACHE).then((cache) => cache.put(request, copy)).catch(() => {});
                    }
                    return response;
                }).catch(() => cached);
                return cached || networkFetch;
            })
        );
        return;
    }

    // HTML / rutas no-admin: network-first con fallback a cache si está offline
    if (request.mode === 'navigate' || (request.headers.get('accept') || '').includes('text/html')) {
        event.respondWith(
            fetch(request).then((response) => {
                if (response && response.status === 200) {
                    const copy = response.clone();
                    caches.open(RUNTIME_CACHE).then((cache) => cache.put(request, copy)).catch(() => {});
                }
                return response;
            }).catch(() => caches.match(request).then((cached) => cached || caches.match('/')))
        );
    }
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
