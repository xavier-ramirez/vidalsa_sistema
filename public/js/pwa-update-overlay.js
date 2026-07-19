/**
 * Vidalsa PWA — Overlay "Actualizando…"
 *
 * Problema: al desplegar muchos cambios, el service worker nuevo tiene que descargar
 * los assets frescos. Con internet flojo eso compite con el login → tarda o falla, y el
 * usuario no sabe si está roto o cargando.
 *
 * Este script muestra una pantalla "Actualizando aplicación…" SOLO cuando hay un SW
 * NUEVO instalándose (es decir, una actualización: ya había un SW controlando la página).
 * Al activarse el SW nuevo, recarga UNA vez para servir los assets frescos y seguir normal.
 * En primera instalación (sin controller) NO se muestra ni se recarga.
 *
 * No re-registra el SW (eso ya lo hacen el login y pwa-install.js): solo se engancha a la
 * registración existente. Timeout de seguridad para no dejar al usuario atascado si el
 * internet no permite terminar la descarga (el SW viejo sigue sirviendo lo cacheado).
 */
(function () {
    if (!('serviceWorker' in navigator)) return;
    if (window._pwaUpdateOverlayReady) return; // guard anti-doble-ejecución (SPA / doble include)
    window._pwaUpdateOverlayReady = true;

    var OVERLAY_ID = 'pwaUpdateOverlay';
    var MAX_MS = 25000;         // tope: si tarda demasiado (mal internet) se quita el overlay
    var timer = null;
    var refreshing = false;     // recargar una sola vez
    var hadController = !!navigator.serviceWorker.controller; // ¿ya había SW controlando?

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
        timer = setTimeout(function () {
            // Mal internet: no dejar al usuario atrapado. Quitamos el overlay y NO recargamos
            // por esta actualización tardía (que se aplique en la próxima apertura, sin
            // interrumpir el login en curso). El SW viejo sigue sirviendo lo cacheado.
            hideOverlay();
            refreshing = true;
        }, MAX_MS);
    }

    function hideOverlay() {
        clearTimeout(timer);
        var d = document.getElementById(OVERLAY_ID);
        if (d) d.remove();
    }

    // Vigila un SW que está instalando. Solo es "actualización" si YA había un controller.
    function watchInstalling(sw) {
        if (!sw || !navigator.serviceWorker.controller) return;
        showOverlay();
        sw.addEventListener('statechange', function () {
            // 'activated' → el controllerchange de abajo recargará (mantenemos overlay hasta el reload).
            // 'redundant' → la instalación falló: quitar overlay para no dejar atascado.
            if (sw.state === 'redundant') hideOverlay();
        });
    }

    // Al tomar el control un SW nuevo, recargar UNA vez (assets frescos). La primera toma de
    // control (primer install, sin controller previo) NO recarga.
    navigator.serviceWorker.addEventListener('controllerchange', function () {
        if (!hadController) { hadController = true; return; }
        if (refreshing) return;
        refreshing = true;
        window.location.reload();
    });

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
