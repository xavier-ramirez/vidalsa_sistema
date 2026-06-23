/**
 * Vidalsa PWA Service Worker
 * Estrategia: stale-while-revalidate para el login (/), network-first para el
 * resto de HTML (la app es dinámica), cache-first para assets estáticos
 * (/icons, /css, /js, /fonts, /images) que ya vienen con cache-busting via ?v=filemtime.
 *
 * MODO OFFLINE (Fase 1): las navegaciones (incluidas /admin/ y /dashboard/) son
 * network-first y se CACHEAN; offline se sirve el cascarón cacheado para que el
 * módulo cargue igual. Online no cambia: siempre se intenta la red primero, el
 * cache solo se usa cuando NO hay internet. Los datos frescos los repone el JS
 * desde IndexedDB (ver /js/offline/offline-sync.js). Las rutas de API/acciones y
 * el snapshot (/offline/) NUNCA se cachean para no servir datos viejos.
 *
 * CACHE_VERSION es inyectado por la ruta Laravel que sirve este archivo; el placeholder
 * __CACHE_VERSION__ se reemplaza con filemtime en cada response para que todo cambio
 * en el codigo invalide los caches del SW automaticamente.
 */
const CACHE_VERSION = '__CACHE_VERSION__';
const STATIC_CACHE  = 'vidalsa-static-' + CACHE_VERSION;
const RUNTIME_CACHE = 'vidalsa-runtime-' + CACHE_VERSION;

const PRECACHE_URLS = [
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/css/fonts.css',
    '/css/maquinaria/inicio_sesion.css',
    '/images/maquinaria/logo.webp',
    '/js/offline/offline-auth.js',
    '/js/webauthn.js',
    '/fonts/Nunito-Regular.ttf',
    '/fonts/Nunito-Bold.ttf',
    '/fonts/Nunito-SemiBold.ttf'
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

    // Nunca cachear API/acciones, el snapshot offline, ni el manifest (el manifest
    // debe leerse fresco siempre: si cachea uno viejo, el navegador no detecta cambios
    // en display_override / tab_strip y nunca activa el modo pestañas aunque reinstales
    // el PWA). OJO: /admin/ y /dashboard/ YA NO se excluyen — sus navegaciones se
    // cachean (network-first) para que los módulos carguen offline; los datos viejos
    // del HTML cacheado los reemplaza el JS leyendo de IndexedDB.
    if (
        url.pathname.startsWith('/webauthn/') ||
        url.pathname.startsWith('/api/') ||
        url.pathname.startsWith('/offline/') ||
        url.pathname.startsWith('/storage/') ||
        url.pathname.includes('/export') ||
        url.pathname.includes('/acta-traslado') ||
        url.pathname === '/login' ||
        url.pathname === '/logout' ||
        url.pathname === '/refresh-csrf' ||
        url.pathname === '/manifest.json'
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

    // Login (/): stale-while-revalidate — carga al instante desde cache, actualiza en fondo.
    // El CSRF token del HTML cacheado se refresca vía /refresh-csrf antes del submit.
    // !response.redirected: si el usuario está logueado, el servidor redirige a /menu;
    // NO debemos cachear esa respuesta bajo la clave "/" o sobreescribiríamos el login.
    if (url.pathname === '/' && (request.mode === 'navigate' || (request.headers.get('accept') || '').includes('text/html'))) {
        event.respondWith(
            caches.match(request).then((cached) => {
                const networkFetch = fetch(request).then((response) => {
                    if (response && response.status === 200 && !response.redirected) {
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
    if (request.mode === 'navigate' || request.headers.get('X-SPA-Navigate') === '1' || (request.headers.get('accept') || '').includes('text/html')) {
        event.respondWith(
            fetch(request).then((response) => {
                if (response && response.status === 200) {
                    const copy = response.clone();
                    caches.open(RUNTIME_CACHE).then((cache) => cache.put(request, copy)).catch(() => {});
                }
                return response;
            }).catch(() => caches.match(request).then(
                // Offline: 1) la misma página si está cacheada; 2) el menú cacheado
                // (lo más útil para reabrir la app sin señal); 3) la raíz como último recurso.
                (cached) => cached || caches.match('/menu').then((m) => m || caches.match('/'))
            ))
        );
    }
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
