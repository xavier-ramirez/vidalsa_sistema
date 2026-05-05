/**
 * Offline Guard.
 *
 * PROBLEMA: cuando el usuario hace login OFFLINE y aterriza en /menu, el HTML
 * cacheado del menú ejecuta scripts que hacen fetch a endpoints Laravel
 * (notificaciones, KPIs, dashboards, etc.). Sin sesión Laravel real, esos
 * endpoints responden 401 o 302 → /login, lo que tira al usuario de vuelta
 * al login en bucle.
 *
 * Este guard se carga lo más TEMPRANO posible y, cuando detecta modo offline
 * (sessionStorage.vidalsa_offline_mode === '1' || ?offline=1 || !navigator.onLine):
 *
 *   1. Intercepta window.fetch: cualquier fetch a un endpoint Laravel que NO
 *      sea /sync/* (la PWA puede usar /sync/* incluso offline para chequear si
 *      vuelve la red) se rechaza inmediatamente con TypeError "offline-mode".
 *      Los scripts del menú tratan eso como network-error y NO redirigen.
 *
 *   2. Anula cualquier window.location.href = '/login' o reload programático
 *      durante los primeros 5s tras carga (cuando los scripts del menú podrían
 *      decidir rebotar al usuario).
 *
 *   3. Marca document.body con clase `vidalsa-offline-locked` para que CSS
 *      pueda ocultar widgets que no funcionan offline (notificaciones, etc.).
 */
(function () {
    'use strict';

    function isOfflineMode() {
        try {
            if (!navigator.onLine) return true;
            if (sessionStorage.getItem('vidalsa_offline_mode') === '1') return true;
            if (new URLSearchParams(location.search).get('offline') === '1') {
                // Persistimos en sessionStorage para que sobreviva navegaciones internas
                sessionStorage.setItem('vidalsa_offline_mode', '1');
                return true;
            }
        } catch { /* ignore */ }
        return false;
    }

    if (!isOfflineMode()) return;

    // 1) Interceptar fetch
    const _origFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
        const url = typeof input === 'string' ? input : (input && input.url) || '';
        // Permitidos en offline: rutas /sync/* (que el SW deja pasar y fallarán
        // limpiamente por red real), data:, blob:, https a CDNs externas.
        const isLaravelInternal =
            url.startsWith('/') &&
            !url.startsWith('/sync/') &&
            !url.startsWith('/manifest.json') &&
            !url.startsWith('/sw.js') &&
            !url.startsWith('/icons/') &&
            !url.startsWith('/css/') &&
            !url.startsWith('/js/') &&
            !url.startsWith('/fonts/') &&
            !url.startsWith('/images/') &&
            !url.startsWith('/img/');

        if (isLaravelInternal) {
            return Promise.reject(new TypeError('offline-mode: fetch bloqueado (' + url + ')'));
        }
        return _origFetch(input, init);
    };

    // 2) Anular redirects programáticos los primeros 8 segundos
    const _origAssign  = window.location.assign?.bind(window.location);
    const _origReplace = window.location.replace?.bind(window.location);
    let blockUntil = Date.now() + 8000;

    function shouldBlock(target) {
        if (Date.now() > blockUntil) return false;
        const t = String(target || '');
        if (t.includes('/login') || t.includes('logout')) return true;
        return false;
    }

    if (_origAssign) {
        window.location.assign = function (url) {
            if (shouldBlock(url)) {
                console.warn('[offline-guard] bloqueado redirect a', url);
                return;
            }
            _origAssign(url);
        };
    }
    if (_origReplace) {
        window.location.replace = function (url) {
            if (shouldBlock(url)) {
                console.warn('[offline-guard] bloqueado replace a', url);
                return;
            }
            _origReplace(url);
        };
    }

    // 3) Interceptar setter de location.href también
    try {
        const desc = Object.getOwnPropertyDescriptor(Window.prototype, 'location') ||
                     Object.getOwnPropertyDescriptor(window, 'location');
        // location es no-configurable en la mayoría de navegadores → no podemos
        // overridear el setter. Confiamos en assign/replace + el hecho de que
        // los redirects internos suelen pasar por assign().
    } catch { /* ignore */ }

    // 4) Marca CSS
    document.addEventListener('DOMContentLoaded', () => {
        document.body.classList.add('vidalsa-offline-locked');
    });

    console.info('[offline-guard] modo offline forzado activo — fetch/redirect a Laravel bloqueados');
})();
