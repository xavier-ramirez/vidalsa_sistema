/**
 * Vidalsa PWA Install Handler (v2)
 * - Registra el service worker.
 * - Intercepta beforeinstallprompt en Chrome/Edge/Android.
 * - NO muestra ya un toast flotante en medio de la pantalla.
 * - En su lugar renderiza un boton fijo "Instalar App" dentro del contenedor
 *   con id="pwaInstallSlot" (colocado en el menu, debajo de la card de
 *   Alertas Documentos). Si el slot no existe en la vista actual, no se
 *   muestra nada, manteniendo el resto de la UI limpio.
 * - En iOS Safari no existe beforeinstallprompt; si el slot esta disponible,
 *   renderiza un boton "Instalar App" que al tocarlo muestra instrucciones
 *   concisas (Compartir -> Agregar a pantalla de inicio).
 * - Guard con window._pwaInstallReady para evitar duplicar en SPA re-exec.
 */
(function () {
    if (window._pwaInstallReady) return;
    window._pwaInstallReady = true;

    // 1) Registro del Service Worker
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js', { scope: '/' })
                .then(function (reg) {
                    if (reg && reg.addEventListener) {
                        reg.addEventListener('updatefound', function () {
                            var nw = reg.installing;
                            if (nw) {
                                nw.addEventListener('statechange', function () {
                                    if (nw.state === 'installed' && navigator.serviceWorker.controller) {
                                        if (reg.waiting) reg.waiting.postMessage({ type: 'SKIP_WAITING' });
                                    }
                                });
                            }
                        });
                    }
                })
                .catch(function () { /* silencioso */ });
        });
    }

    // 2) Estado compartido
    var deferredPrompt = null;

    function isStandalone() {
        return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
               window.navigator.standalone === true;
    }

    function isMobileDevice() {
        var ua = (window.navigator.userAgent || '').toLowerCase();
        var uaLooksMobile = /android|iphone|ipad|ipod|iemobile|opera mini|mobile|tablet/.test(ua);
        var coarsePointer = (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) || false;
        var narrowViewport = window.innerWidth <= 1024;
        return (uaLooksMobile && narrowViewport) || (coarsePointer && narrowViewport);
    }

    function isIOSSafari() {
        var ua = window.navigator.userAgent || '';
        var iOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
        var webkit = /WebKit/.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
        return iOS && webkit;
    }

    // 3) Render del boton dentro de #pwaInstallSlot (si existe)
    function renderSlot() {
        var slot = document.getElementById('pwaInstallSlot');
        if (!slot) return;

        // Si ya no aplica (standalone o desktop), aseguramos que quede vacio.
        if (isStandalone() || !isMobileDevice()) {
            slot.innerHTML = '';
            return;
        }

        // Solo mostramos el boton cuando el navegador REALMENTE puede instalar
        // la PWA ahora mismo: tiene deferredPrompt disponible (Chrome/Edge/Android)
        // o es iOS Safari (fallback manual Compartir→Pantalla de Inicio).
        // En otros casos ocultamos el slot — el boton debe funcionar "al primer click"
        // sin hints intermedios.
        var canPrompt = !!deferredPrompt;
        var iosFallback = isIOSSafari();
        if (!canPrompt && !iosFallback) {
            slot.innerHTML = '';
            return;
        }

        // Si ya esta renderizado, no duplicar.
        if (slot.querySelector('[data-pwa-install-btn]')) return;

        slot.innerHTML = '' +
            '<button type="button" data-pwa-install-btn ' +
                'style="display:flex;align-items:center;justify-content:center;gap:8px;' +
                'width:100%;padding:12px 16px;border-radius:14px;border:1px solid #e2e8f0;' +
                'background:#ffffff;color:#1e293b;font-weight:700;font-size:14px;cursor:pointer;' +
                'box-shadow:0 1px 2px rgba(15, 23, 42, 0.04), 0 12px 28px -16px rgba(15, 23, 42, 0.18);' +
                'transition:transform 0.2s ease, box-shadow 0.2s ease;">' +
                '<span class="material-icons" style="font-size:20px;color:#1e293b;">download</span>' +
                '<span>Instalar App</span>' +
            '</button>';

        var btn = slot.querySelector('[data-pwa-install-btn]');
        btn.addEventListener('mouseenter', function () {
            btn.style.transform = 'translateY(-1px)';
            btn.style.boxShadow = '0 2px 4px rgba(15, 23, 42, 0.05), 0 18px 36px -18px rgba(15, 23, 42, 0.25)';
        });
        btn.addEventListener('mouseleave', function () {
            btn.style.transform = '';
            btn.style.boxShadow = '0 1px 2px rgba(15, 23, 42, 0.04), 0 12px 28px -16px rgba(15, 23, 42, 0.18)';
        });
        btn.addEventListener('click', function () {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then(function () {
                    deferredPrompt = null;
                    renderSlot();
                });
            } else if (iosFallback) {
                showIOSHint();
            }
            // Si no hay prompt y no es iOS, el boton no deberia estar visible
            // (renderSlot ya lo oculta). Aqui no hacemos nada.
        });
    }

    // showGenericHint removido a proposito: el usuario pidio que el boton
    // "Instalar App" dispare el prompt nativo directo, sin mensajes intermedios.
    // Si el navegador no tiene beforeinstallprompt disponible, renderSlot()
    // oculta el boton en lugar de mostrar hints.

    function showIOSHint() {
        // Hint ligero y discreto, no modal flotante azul.
        var existing = document.getElementById('pwaIosHint');
        if (existing) { existing.remove(); return; }
        var hint = document.createElement('div');
        hint.id = 'pwaIosHint';
        hint.style.cssText =
            'margin-top:10px;padding:10px 12px;border-radius:12px;background:#f1f5f9;' +
            'color:#1e293b;font-size:13px;line-height:1.35;border:1px solid #e2e8f0;';
        hint.innerHTML =
            '<strong>Instalar en iPhone:</strong> toca ' +
            '<span style="font-weight:700;">Compartir</span> y luego ' +
            '<span style="font-weight:700;">"Agregar a pantalla de inicio"</span>.';
        var slot = document.getElementById('pwaInstallSlot');
        if (slot) slot.appendChild(hint);
    }

    // 4) Eventos
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', renderSlot);
        } else {
            renderSlot();
        }
    });

    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        var slot = document.getElementById('pwaInstallSlot');
        if (slot) slot.innerHTML = '';
    });

    // Primer render (por si la pagina ya carga con el slot y con deferredPrompt listo
    // o para pintar el boton de iOS). Re-render cuando cambia la navegacion SPA.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderSlot);
    } else {
        renderSlot();
    }
    // En navegacion SPA del proyecto, estructura_base reactiva scripts; exponer hook:
    window.renderPwaInstallSlot = renderSlot;

    // 5) API publica manual (botones custom)
    window.triggerPwaInstall = function () {
        if (deferredPrompt) {
            deferredPrompt.prompt();
        } else if (isIOSSafari()) {
            showIOSHint();
        }
    };
})();
