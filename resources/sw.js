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
    '/fonts/Nunito-Regular.woff2',
    '/fonts/Nunito-Bold.woff2',
    '/fonts/Nunito-SemiBold.woff2'
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
            }).catch(() => {}),
            // Y el MENU, si hay sesion (sin sesion redirige al login: no se guarda). Es a
            // donde lleva "Entrar sin conexion": si faltaba —recien actualizada la app y sin
            // haber vuelto a abrir el menu con red— ese boton devolvia al login una y otra
            // vez ("no me deja iniciar sesion").
            fetch('/menu', { credentials: 'same-origin' }).then((response) => {
                if (response && response.status === 200 && !response.redirected) {
                    return caches.open(RUNTIME_CACHE).then((cache) => cache.put('/menu', response));
                }
            }).catch(() => {})
        ])
    );
});

// ¿Se puede pasar esta entrada de la caché vieja a la nueva? Las PAGINAS (HTML, incluidas
// las respuestas cortas de la SPA y el panel de alertas) y los archivos con ?v= en la URL:
// esos nunca pueden servir una version equivocada (la pagina se pide primero a la red, y el
// ?v= cambia con el archivo). NO los assets sin version (los del PRECACHE): para refrescar
// esos existe justamente el cambio de CACHE_VERSION.
function sePuedeConservar(req, res) {
    const u = new URL(req.url);
    // Una respuesta CORTA de la SPA guardada con la clave normal (lo hacia el SW anterior a
    // las claves ?__spa=1): pasarla abriria la app sin menu ni scripts. Fuera.
    if (req.headers.get('X-SPA-Navigate') === '1') return false;
    if (u.search.includes('v=') && u.pathname.match(/^\/(js|css|fonts|images|img|icons)\//)) return true;
    return (res.headers.get('Content-Type') || '').includes('text/html') || u.pathname === '/dashboard/alerts-html';
}

self.addEventListener('activate', (event) => {
    // CACHE_VERSION cambia en CADA despliegue (commit + filemtime). Antes eso borraba TODO
    // lo guardado: el menu y los modulos desaparecian de la caché cada vez que se subia una
    // version, y quien se quedaba sin red justo despues no podia entrar ni abrir modulos
    // (volvia al login). Ahora lo que no puede quedar desfasado (sePuedeConservar) se pasa
    // a la caché nueva antes de borrar la vieja; con red, cada pagina se refresca sola la
    // proxima vez que se abra (network-first).
    event.waitUntil(
        caches.keys().then((keys) => {
            const viejas = keys.filter((key) => key !== STATIC_CACHE && key !== RUNTIME_CACHE && key.startsWith('vidalsa-'));
            return caches.open(RUNTIME_CACHE).then((nueva) => Promise.all(
                viejas.filter((k) => k.startsWith('vidalsa-runtime-')).map((k) => caches.open(k).then((vieja) =>
                    vieja.keys().then((reqs) => Promise.all(reqs.map((req) =>
                        vieja.match(req).then((res) => {
                            if (!res || !sePuedeConservar(req, res)) return;
                            // No pisar lo que el install ya bajo fresco (el login, el menu).
                            return nueva.match(req).then((ya) => ya ? null : nueva.put(req, res));
                        }).catch(() => {})
                    )))
                ).catch(() => {}))
            )).then(() => Promise.all(viejas.map((key) => caches.delete(key))));
        }).then(() => self.clients.claim())
    );
});

// La red, pero con un tope. Con el wifi conectado y el servidor inalcanzable (router sin
// salida, portal cautivo, servidor caido) la peticion no falla: se queda colgada minutos, y
// la pantalla en blanco esperando. Pasado el tope se usa lo guardado; la peticion sigue en
// segundo plano y, si llega, actualiza la caché para la proxima vez.
//
// Y una vez que el servidor no contesto, se RECUERDA un rato (sinServidorHasta): cada pagina,
// cada JS y cada CSS esperaba su propio tope y abrir un modulo costaba 20 s. Mientras dure,
// se va directo a lo guardado; la red se sigue intentando por detras y, en cuanto responde
// algo, se olvida.
const TOPE_RED_MS = 6000;
const RECORDAR_SIN_SERVIDOR_MS = 30000;
let sinServidorHasta = 0;
function redConTope(peticion) {
    peticion.then(() => { sinServidorHasta = 0; }, () => {});
    return new Promise((resolve, reject) => {
        const tope = Date.now() < sinServidorHasta ? 0 : TOPE_RED_MS;
        const t = setTimeout(() => {
            sinServidorHasta = Date.now() + RECORDAR_SIN_SERVIDOR_MS;
            reject(new Error('tope'));
        }, tope);
        peticion.then((r) => { clearTimeout(t); resolve(r); }, (e) => { clearTimeout(t); reject(e); });
    });
}

// Una pagina servida DESDE LA CACHÉ (sin red) lleva esta marca. navegacion.js la mira para
// no comparar versiones: una copia guardada es de la version de cuando se guardo, y tomar
// esa diferencia por "hubo un despliegue" forzaba una recarga que, sin servidor, acababa en
// el menu en vez de en el modulo pedido.
function marcarDesdeCache(res) {
    if (!res) return res;
    const h = new Headers(res.headers);
    h.set('X-Vidalsa-Desde-Cache', '1');
    return res.blob().then((b) => new Response(b, { status: res.status, statusText: res.statusText, headers: h }));
}

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

    // Panel de Alertas del menú: el menú lo pide en segundo plano (menu.js ·
    // refreshDashboardAlerts). Red primero y, sin conexión, la última lista que llegó —como
    // antes, cuando venía dentro del HTML cacheado de /menu—. Una sola entrada, sin el ?t=
    // con que se pide.
    if (url.pathname === '/dashboard/alerts-html') {
        event.respondWith(
            fetch(request).then((response) => {
                if (response && response.ok) {
                    const copy = response.clone();
                    caches.open(RUNTIME_CACHE).then((cache) => cache.put('/dashboard/alerts-html', copy)).catch(() => {});
                }
                return response;
            }).catch(() => caches.match('/dashboard/alerts-html').then((cached) => cached || Response.error()))
        );
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
                // Sin copia exacta: la red, con tope (con el servidor colgado, un JS o un CSS
                // esperando dejaba la pagina —el login incluido— sin terminar de cargar).
                return redConTope(networkFetch).catch(() => caches.match(request, { ignoreSearch: true })
                    .then((c) => c || networkFetch));
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
                return cached || redConTope(networkFetch).catch(() => networkFetch);
            })
        );
        return;
    }

    // HTML / rutas no-admin: network-first con fallback a cache si está offline.
    //
    // La navegacion SPA (X-SPA-Navigate) recibe una respuesta CORTA: solo el contenido del
    // modulo, sin menu ni scripts (layouts/estructura_base). Se guarda con su PROPIA clave
    // (?__spa=1): si pisara la pagina completa de la misma URL, abrir la app sin conexion en
    // ese modulo (F5, o reabrir la PWA) mostraria el contenido suelto, sin menu ni JS. Al
    // reves si vale: una pagina completa sirve a la SPA, que solo toma su <main>.
    const esSpa = request.headers.get('X-SPA-Navigate') === '1';
    if (request.mode === 'navigate' || esSpa || (request.headers.get('accept') || '').includes('text/html')) {
        const claveSpa = () => { const u = new URL(request.url); u.searchParams.set('__spa', '1'); return u.toString(); };
        const red = fetch(request).then((response) => {
            // Sin redirect: si la sesion se cayo, el servidor manda al login y ESE HTML
            // quedaba guardado como si fuera el modulo pedido (sin red, abrir ese modulo
            // enseñaba el login). El login tiene su propia entrada ('/', mas arriba).
            if (response && response.status === 200 && !response.redirected) {
                const copy = response.clone();
                caches.open(RUNTIME_CACHE).then((cache) => cache.put(esSpa ? claveSpa() : request, copy)).catch(() => {});
            }
            return response;
        });
        red.catch(() => {}); // si gana el tope, que esta no quede como promesa rechazada suelta
        event.respondWith(
            redConTope(red).catch(() => (esSpa ? caches.match(claveSpa()) : Promise.resolve(undefined)).then(
                // Sin red: 1) la respuesta corta de este modulo (solo la SPA); 2) la misma
                // pagina completa (en la SPA con ignoreVary: se guardo sin X-SPA-Navigate;
                // en una navegacion NO, para no servir nunca una respuesta corta).
                (corta) => corta || caches.match(request, esSpa ? { ignoreVary: true } : undefined)
            ).then((cached) => {
                if (cached) return marcarDesdeCache(cached);
                // Un modulo que nunca se abrio con red en este equipo. En la SPA, error de
                // red: navegacion.js avisa y DEJA al usuario donde estaba (antes le pintaba
                // el menu con la direccion del modulo, y parecia que el modulo no abria).
                if (esSpa) return Response.error();
                // Abriendo la app (F5, icono de la PWA) en un modulo al que se llego por la SPA
                // (solo hay su copia corta): el menu guardado, que al arrancar ve que su
                // contenido no es el de la direccion y carga la copia corta del modulo
                // (data-ruta, ver navegacion.js). Sin menu, el login como ultimo recurso.
                return caches.match('/menu').then((m) => m ? marcarDesdeCache(m) : caches.match('/'));
            }))
        );
    }
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
