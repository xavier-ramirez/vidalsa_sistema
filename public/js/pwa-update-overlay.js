/**
 * Vidalsa PWA — Overlay "Actualizando…" (SOLO en el login)
 *
 * Problema: al desplegar muchos cambios, el service worker nuevo descarga los assets
 * frescos. Con internet flojo eso compite con el login → tarda o falla, y el usuario no
 * sabe si está roto o cargando.
 *
 * Este script muestra "Actualizando aplicación…" mientras un SW NUEVO se está instalando
 * (una actualización: ya había un SW controlando). Al terminar (activarse) se oculta y el
 * usuario inicia sesión normal — la navegación natural del login a /menu ya sirve los assets
 * frescos del SW nuevo, así que NO hace falta recargar a la fuerza.
 *
 * A PROPÓSITO no recarga la página ni se carga fuera del login:
 *  - Recargar en una página interna (formulario a medias) perdería el trabajo del usuario.
 *  - En el login no hay nada que perder, por eso el overlay vive solo aquí.
 * No re-registra el SW (el login ya lo registra): solo se engancha a la registración
 * existente para vigilar la instalación. Timeout de seguridad para no dejar al usuario
 * atrapado bajo el overlay si el internet no permite terminar (el SW viejo sigue sirviendo
 * lo cacheado y puede iniciar sesión, incluso offline).
 */
(function () {
    if (!('serviceWorker' in navigator)) return;
    if (window._pwaUpdateOverlayReady) return; // guard anti-doble-ejecución
    window._pwaUpdateOverlayReady = true;

    var OVERLAY_ID = 'pwaUpdateOverlay';
    var MAX_MS = 15000; // tope: si tarda demasiado (mal internet) se quita el overlay
    var timer = null;

    function showOverlay() {
        if (document.getElementById(OVERLAY_ID)) return;
        var d = document.createElement('div');
        d.id = OVERLAY_ID;
        d.setAttribute('role', 'status');
        d.setAttribute('aria-live', 'polite');
        d.style.cssText = 'position:fixed;inset:0;z-index:2147483647;display:flex;' +
            'flex-direction:column;align-items:center;justify-content:center;gap:16px;' +
            'background:linear-gradient(135deg,#00004d 0%,#0067b1 100%);color:#fff;' +
            'font-family:Nunito,"Segoe UI",system-ui,sans-serif;text-align:center;padding:24px;';
        d.innerHTML =
            '<div style="width:52px;height:52px;border:5px solid rgba(255,255,255,0.25);' +
                'border-top-color:#fff;border-radius:50%;animation:pwaUpdSpin 0.9s linear infinite;"></div>' +
            '<div style="font-size:18px;font-weight:800;">Actualizando aplicación…</div>' +
            '<div style="font-size:13px;opacity:0.85;max-width:280px;line-height:1.4;">' +
                'Descargando los últimos cambios. Esto pasa una sola vez tras una actualización.</div>';
        (document.body || document.documentElement).appendChild(d);
        if (!document.getElementById('pwaUpdSpinKeyframes')) {
            var st = document.createElement('style');
            st.id = 'pwaUpdSpinKeyframes';
            st.textContent = '@keyframes pwaUpdSpin{to{transform:rotate(360deg)}}';
            document.head.appendChild(st);
        }
        clearTimeout(timer);
        // Mal internet: no dejar al usuario atrapado bajo el overlay. Se quita y puede iniciar
        // sesión (el SW viejo sigue sirviendo lo cacheado / login offline). El SW nuevo termina
        // en segundo plano y se aplica en la próxima apertura, sin interrumpir nada.
        timer = setTimeout(hideOverlay, MAX_MS);
    }

    function hideOverlay() {
        clearTimeout(timer);
        var d = document.getElementById(OVERLAY_ID);
        if (d) d.remove();
    }

    // Vigila un SW que está instalando. Solo es "actualización" si YA había un controller
    // (en primera instalación no molestamos). Overlay mientras instala; se quita al activarse
    // o si la instalación falla. No se recarga (ver cabecera).
    function watchInstalling(sw) {
        if (!sw || !navigator.serviceWorker.controller) return;
        showOverlay();
        sw.addEventListener('statechange', function () {
            if (sw.state === 'activated' || sw.state === 'redundant') hideOverlay();
        });
    }

    // Engancharse a la registración YA existente (no re-registrar). Dos vías por si una corre
    // antes de que register() resuelva; el flag __pwaOverlayBound evita doble-enganche.
    function bind(reg) {
        if (!reg || reg.__pwaOverlayBound) return;
        reg.__pwaOverlayBound = true;
        if (reg.installing) watchInstalling(reg.installing);
        reg.addEventListener('updatefound', function () { watchInstalling(reg.installing); });
    }
    navigator.serviceWorker.getRegistration().then(function (reg) { if (reg) bind(reg); }).catch(function () {});
    navigator.serviceWorker.ready.then(bind).catch(function () {});
})();
