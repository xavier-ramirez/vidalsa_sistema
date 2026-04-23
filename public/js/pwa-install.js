/**
 * Vidalsa PWA Install Handler
 * - Registra el service worker.
 * - Intercepta beforeinstallprompt en Chrome/Edge/Android y muestra un toast
 *   con boton "Instalar aplicacion".
 * - En iOS Safari no existe beforeinstallprompt; mostramos instrucciones
 *   manuales (Compartir -> Agregar a pantalla de inicio) la primera vez.
 * - Guard con window.* para no duplicar en SPA re-exec.
 */
(function () {
    if (window._pwaInstallReady) return;
    window._pwaInstallReady = true;

    // 1) Registro del Service Worker
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js', { scope: '/' })
                .then(function (reg) {
                    // Detectar nueva version del SW y ofrecer recarga
                    if (reg && reg.addEventListener) {
                        reg.addEventListener('updatefound', function () {
                            var nw = reg.installing;
                            if (nw) {
                                nw.addEventListener('statechange', function () {
                                    if (nw.state === 'installed' && navigator.serviceWorker.controller) {
                                        // Hay un SW nuevo esperando; tomar control al siguiente reload
                                        if (reg.waiting) reg.waiting.postMessage({ type: 'SKIP_WAITING' });
                                    }
                                });
                            }
                        });
                    }
                })
                .catch(function () { /* silencioso: no bloquear la app */ });
        });
    }

    // 2) Beforeinstallprompt (Chrome/Edge/Android)
    var deferredPrompt = null;
    var installBanner = null;

    function isStandalone() {
        return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
               window.navigator.standalone === true;
    }

    // Solo mostrar el banner en dispositivos tipo movil/tablet.
    // Combinamos tres senales para robustez: user-agent, pointer:coarse (touch) y
    // viewport angosto. Si cualquiera dice "no es movil", no mostramos el banner.
    function isMobileDevice() {
        var ua = (window.navigator.userAgent || '').toLowerCase();
        var uaLooksMobile = /android|iphone|ipad|ipod|iemobile|opera mini|mobile|tablet/.test(ua);
        var coarsePointer = (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) || false;
        var narrowViewport = window.innerWidth <= 1024;
        return (uaLooksMobile && narrowViewport) || (coarsePointer && narrowViewport);
    }

    function dismissKey() { return 'pwa_install_dismissed_until'; }

    function dismissedRecently() {
        try {
            var until = parseInt(localStorage.getItem(dismissKey()) || '0', 10);
            return until && Date.now() < until;
        } catch (e) { return false; }
    }

    function remember(days) {
        try {
            localStorage.setItem(dismissKey(), String(Date.now() + days * 86400000));
        } catch (e) {}
    }

    function buildBanner(opts) {
        var wrap = document.createElement('div');
        wrap.id = 'pwaInstallBanner';
        wrap.setAttribute('role', 'dialog');
        wrap.setAttribute('aria-label', 'Instalar aplicacion');
        wrap.style.cssText =
            'position:fixed; left:50%; bottom:20px; transform:translateX(-50%);' +
            'background:#0067b1; color:#fff; padding:12px 16px; border-radius:14px;' +
            'box-shadow:0 18px 40px -12px rgba(0,0,0,0.45); display:flex; align-items:center;' +
            'gap:12px; z-index:99990; max-width:92%; width:auto; font-size:14px;' +
            'animation: pwaBannerIn 0.28s ease-out;';
        wrap.innerHTML =
            '<img src="/icons/icon-96.png" alt="" style="width:40px;height:40px;border-radius:10px;flex-shrink:0;">' +
            '<div style="flex:1; line-height:1.25; min-width:0;">' +
                '<div style="font-weight:700;">' + (opts.title || 'Instalar Vidalsa') + '</div>' +
                '<div style="opacity:0.9; font-size:12px;">' + (opts.subtitle || 'Accede mas rapido desde tu pantalla de inicio.') + '</div>' +
            '</div>' +
            '<button type="button" id="pwaInstallBtn" ' +
                'style="background:#fff;color:#0067b1;border:none;border-radius:8px;padding:8px 14px;' +
                'font-weight:700;cursor:pointer;flex-shrink:0;">' + (opts.ctaLabel || 'Instalar') + '</button>' +
            '<button type="button" id="pwaInstallClose" aria-label="Cerrar" ' +
                'style="background:rgba(255,255,255,0.15);border:none;color:#fff;width:28px;height:28px;' +
                'border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;">' +
                '<span style="font-size:18px;line-height:1;">&times;</span>' +
            '</button>';
        document.body.appendChild(wrap);

        if (!document.getElementById('pwaBannerKeyframes')) {
            var style = document.createElement('style');
            style.id = 'pwaBannerKeyframes';
            style.textContent =
                '@keyframes pwaBannerIn { from { transform: translateX(-50%) translateY(14px); opacity: 0; } ' +
                'to { transform: translateX(-50%) translateY(0); opacity: 1; } }' +
                '@media (max-width:600px){ #pwaInstallBanner{left:10px;right:10px;transform:none;bottom:12px;max-width:none;width:auto;} ' +
                '@keyframes pwaBannerIn { from { transform: translateY(14px); opacity: 0; } to { transform: translateY(0); opacity: 1; } } }';
            document.head.appendChild(style);
        }

        var closeBtn = wrap.querySelector('#pwaInstallClose');
        closeBtn.addEventListener('click', function () {
            wrap.remove();
            remember(7); // no volver a mostrar en 7 dias
        });

        return wrap;
    }

    function showInstallBanner() {
        if (installBanner || isStandalone() || dismissedRecently() || !isMobileDevice()) return;
        installBanner = buildBanner({});
        var btn = installBanner.querySelector('#pwaInstallBtn');
        btn.addEventListener('click', function () {
            if (!deferredPrompt) { installBanner.remove(); return; }
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function (choice) {
                if (choice.outcome !== 'accepted') remember(3);
                deferredPrompt = null;
                if (installBanner) { installBanner.remove(); installBanner = null; }
            });
        });
    }

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        // Esperar a que el DOM este listo
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', showInstallBanner);
        } else {
            showInstallBanner();
        }
    });

    window.addEventListener('appinstalled', function () {
        if (installBanner) { installBanner.remove(); installBanner = null; }
        deferredPrompt = null;
    });

    // 3) iOS Safari: no soporta beforeinstallprompt. Mostrar instrucciones la primera vez.
    function isIOSSafari() {
        var ua = window.navigator.userAgent || '';
        var iOS = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
        var webkit = /WebKit/.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
        return iOS && webkit;
    }

    function maybeShowIOSHint() {
        if (!isIOSSafari() || isStandalone() || dismissedRecently() || !isMobileDevice()) return;
        var banner = buildBanner({
            title: 'Instalar Vidalsa',
            subtitle: 'Toca Compartir y luego "Agregar a pantalla de inicio".',
            ctaLabel: 'Entendido'
        });
        var btn = banner.querySelector('#pwaInstallBtn');
        btn.addEventListener('click', function () {
            banner.remove();
            remember(7);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', maybeShowIOSHint);
    } else {
        maybeShowIOSHint();
    }

    // 4) API publica por si quieres disparar manualmente el prompt desde un boton del UI
    window.triggerPwaInstall = function () {
        if (deferredPrompt) {
            deferredPrompt.prompt();
        } else if (isIOSSafari()) {
            maybeShowIOSHint();
        }
    };
})();
