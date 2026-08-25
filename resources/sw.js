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
 *
 * Al CAMBIAR un asset PRECACHEADO (p. ej. /css/maquinaria/inicio_sesion.css) hay que
 * tocar ESTE archivo para bumpear CACHE_VERSION; si no, el SW sigue sirviendo la copia
 * vieja precacheada (cache-first) y el cambio no llega al usuario.
 * Última invalidación manual: 2026-07-19 (overlay "Actualizando…": precachear su script).
 */
const CACHE_VERSION = '__CACHE_VERSION__';
const STATIC_CACHE  = 'vidalsa-static-' + CACHE_VERSION;
const RUNTIME_CACHE = 'vidalsa-runtime-' + CACHE_VERSION;

// Todo lo que la pantalla de LOGIN necesita para pintarse ENTERA sin red: al tocar el
// ícono de la PWA instalada, '/' sale del caché (stale-while-revalidate, más abajo) y sus
// assets de aquí, así que no queda esperando a nadie.
//
// NO se precachea /images/maquinaria_login_new.webp (194 KB) a propósito: el CSS la pone
// en display:none por debajo de 768px, o sea que en TELÉFONO —el caso que importa para
// arrancar rápido— no se pinta nunca, y encima va con loading="lazy", así que tampoco
// bloquea el primer pintado en escritorio. Precacharla sería medio mega por dispositivo y
// por versión de caché a cambio de nada.
const PRECACHE_URLS = [
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/favicon.png',
    '/css/fonts.css',
    '/css/maquinaria/inicio_sesion.css',
    '/js/pwa-update-overlay.js',
    '/images/maquinaria/logo.webp',
    '/js/offline/offline-auth.js',
    // dom_helpers.js lo carga el login porque webauthn.js usa window.apiFetch/getCsrf de
    // ahí: faltaba, así que el primer arranque tras cada versión de caché se quedaba
    // esperándolo por red antes de poder usar el formulario. Pesa 7 KB.
    '/js/maquinaria/dom_helpers.js',
    '/js/webauthn.js',
    '/fonts/Nunito-Regular.ttf',
    '/fonts/Nunito-Bold.ttf',
    '/fonts/Nunito-SemiBold.ttf'
];

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(
        Promise.all([
            caches.open(STATIC_CACHE).then((cache) => cache.addAll(PRECACHE_URLS)).catch(() => {}),
            // '/' (el login) aparte de PRECACHE_URLS/addAll, con el MISMO guard que usa
            // el handler de fetch en runtime más abajo: si el install corre con una
            // sesión activa, '/' redirige a /menu — cachearlo tal cual pisaría el login
            // con el menú, y la próxima vez que se abra la PWA ya deslogueado saldría
            // el menú viejo en vez del login. Sin esto, el primer reinicio de la PWA
            // tras instalarla no tenía nada cacheado bajo "/": la pantalla nativa de
            // Android (icono sobre fondo blanco) se quedaba varios segundos de más
            // esperando la red antes de poder pintar el login.
            fetch('/', { credentials: 'same-origin' }).then((response) => {
                if (response && response.status === 200 && !response.redirected) {
                    return caches.open(RUNTIME_CACHE).then((cache) => cache.put('/', response));
                }
            }).catch(() => {})
        ])
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
        // Match EXACTO primero (respeta el "?v=filemtime" — cache-busting real: un
        // JS/CSS editado cambia de URL y NUNCA sirve la copia vieja desde acá). Si
        // no hay copia exacta (primer arranque, u OFFLINE) se cae a ignoreSearch
        // como fallback — sirve CUALQUIER versión cacheada (ej. la precacheada sin
        // "?v=" en el install) en vez de nada, mientras la red trae la correcta.
        // OJO: ignoreSearch como estrategia PRINCIPAL (no fallback) fue un bug real
        // acá — servía JS/CSS viejo indefinidamente hasta que sw.js mismo cambiara,
        // aunque el archivo en el servidor ya estuviera corregido.
        //
        // Poda de versiones viejas: CACHE_VERSION (el nombre del bucket completo)
        // solo cambia si se edita sw.js — NO cuando cambia equipos_index.js o
        // cualquier otro asset. Sin poda, RUNTIME_CACHE va ACUMULANDO una entrada
        // por cada "?v=" que ese archivo tuvo alguna vez (meses de historial), y el
        // fallback ignoreSearch de arriba puede resucitar CUALQUIERA de esas viejas
        // — se vio en producción: un modal eliminado hace semanas volvía a aparecer
        // tras un simple fallo de red transitorio. Al guardar la respuesta fresca,
        // borramos las demás entradas del MISMO pathname: ignoreSearch ya solo
        // puede encontrar la última versión que sí llegó a cachearse.
        event.respondWith(
            caches.match(request).then((exact) => {
                const networkFetch = fetch(request).then((response) => {
                    if (response && response.status === 200) {
                        const copy = response.clone();
                        caches.open(RUNTIME_CACHE).then((cache) => {
                            cache.put(request, copy);
                            return cache.keys().then((keys) => Promise.all(
                                keys
                                    .filter((k) => k.url !== request.url && new URL(k.url).pathname === url.pathname)
                                    .map((k) => cache.delete(k))
                            ));
                        }).catch(() => {});
                    }
                    return response;
                });
                if (exact) {
                    networkFetch.catch(() => {}); // revalida en el fondo; el catch es solo para no dejar la promesa colgada
                    return exact;
                }
                return networkFetch.catch(() => caches.match(request, { ignoreSearch: true }));
            })
        );
        return;
    }

    // Login (/): stale-while-revalidate — carga al instante desde cache, actualiza en fondo.
    // El CSRF token del HTML cacheado se refresca vía /refresh-csrf antes del submit.
    // !response.redirected: si el usuario está logueado, el servidor redirige a /menu;
    // NO debemos cachear esa respuesta bajo la clave "/" o sobreescribiríamos el login.
    if (url.pathname === '/' && (request.mode === 'navigate' || (request.headers.get('accept') || '').includes('text/html'))) {
        // Clave de caché SIEMPRE '/' (sin query). El login recibe la raíz con parámetros
        // —hoy '?aviso=' cuando la sesión se cayó o se abrió en otro dispositivo, mañana
        // cualquier '?utm='— y con la request completa como clave no había coincidencia:
        // sin red, caches.match fallaba, el fetch también, y el usuario acababa en la
        // página de error del navegador en vez de en el login cacheado. El HTML es el
        // mismo en los dos casos (el aviso lo pinta el JS leyendo la URL), así que una
        // sola entrada '/' sirve para todas las variantes y no duplica copias.
        event.respondWith(
            caches.match('/').then((cached) => {
                const networkFetch = fetch(request).then((response) => {
                    if (response && response.status === 200 && !response.redirected) {
                        const copy = response.clone();
                        caches.open(RUNTIME_CACHE).then((cache) => cache.put('/', copy)).catch(() => {});
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
