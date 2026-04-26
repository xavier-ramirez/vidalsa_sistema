/* spa-nav.js - Handle dynamic content loading with progress bar */
document.addEventListener('DOMContentLoaded', () => {
    const mainViewport = document.querySelector('.main-viewport');

    // Intercept clicks on links
    document.addEventListener('click', async (e) => {
        const link = e.target.closest('a');

        if (!link || !link.href) return;

        // Skip if link has target="_blank", or it's not a primary click (left click), or has alt/ctrl/meta keys
        if (link.target === '_blank' || e.button !== 0 || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) {
            return;
        }

        // Only internal links, ignore logout or external
        const url = new URL(link.href);

        // Skip blob, data, and javascript URLs
        if (url.protocol === 'blob:' || url.protocol === 'data:' || url.protocol === 'javascript:') {
            return;
        }

        if (url.origin !== window.location.origin || link.hasAttribute('data-no-spa') || link.href.includes('logout')) {
            return;
        }

        e.preventDefault();
        navigateTo(link.href);
    });

    // Handle back/forward buttons
    window.addEventListener('popstate', () => {
        loadPage(window.location.href, false);
    });

    async function navigateTo(url) {
        await loadPage(url, true);
    }
    window.navigateTo = navigateTo;

    // Re-ejecuta los scripts del contenido inyectado via innerHTML, EN ORDEN.
    // El browser NO ejecuta scripts insertados por innerHTML (seguridad).
    // Scripts externos (src) se cargan secuencialmente esperando el evento load
    // para garantizar que las dependencias (ej: Chart.js) estén disponibles
    // antes de ejecutar los scripts inline de inicialización.
    async function executeScripts(container) {
        const scripts = Array.from(container.querySelectorAll('script'));

        // Detecta si el contenido parece HTML y no JavaScript
        function looksLikeHTML(text) {
            if (!text) return false;
            const t = text.trim();
            // Empieza con etiqueta HTML o comentario HTML
            if (/^<[a-z!\/]/i.test(t)) return true;
            // Solo espacios y comentarios HTML
            if (/^<!--[\s\S]*-->$/.test(t)) return true;
            return false;
        }

        for (const oldScript of scripts) {
            await new Promise(resolve => {
                try {
                    const newScript = document.createElement('script');

                    // Copiar atributos (src, type, etc.)
                    Array.from(oldScript.attributes).forEach(attr => {
                        newScript.setAttribute(attr.name, attr.value);
                    });

                    if (newScript.src) {
                        // Script externo (CDN / asset):
                        // Si ya está cargado en el documento, no lo duplicamos
                        const alreadyLoaded = document.querySelector(`script[src="${newScript.src}"]`);
                        if (alreadyLoaded) {
                            resolve();
                            return;
                        }
                        // Esperar a que cargue o falle antes de continuar con el siguiente
                        newScript.onload  = () => resolve();
                        newScript.onerror = () => resolve(); // Continuar aunque falle
                        document.head.appendChild(newScript);
                    } else {
                        // Script inline: se ejecuta de forma síncrona al añadirse
                        const content = oldScript.textContent ? oldScript.textContent.trim() : '';

                        // Guard: saltar scripts que contengan markup HTML (artefactos de Blade)
                        if (!content || looksLikeHTML(content)) {
                            resolve();
                            return;
                        }

                        newScript.textContent = content;

                        // Resolver ANTES de append: si el script falla, no bloquea el loop
                        resolve();

                        try {
                            document.head.appendChild(newScript);
                        } catch (appendErr) {
                            // Script con contenido inválido — se descarta sin romper el flujo
                            console.warn('SPA: inline script descartado (contenido inválido):', appendErr.message.substring(0, 120));
                        }
                    }
                } catch (outerErr) {
                    // Salvaguarda global: ningún script individual puede romper el loop
                    console.warn('SPA: error procesando script, se continúa:', outerErr.message);
                    resolve();
                }
            });
        }
    }

    async function loadPage(url, pushHistory = true) {
        // ── Timeout de 12s: si el servidor no responde, no dejamos el spinner eternamente ──
        const controller = new AbortController();
        const timeoutId  = setTimeout(() => controller.abort(), 12000);

        // Flag para evitar que el bloque finally oculte el preloader
        // si el bloque try ya lo manejó correctamente.
        let handledCleanup = false;

        // Tiempo minimo que el preloader queda visible: evita el parpadeo
        // cuando la navegacion es rapida (<250ms) y el usuario no alcanza
        // a percibir el spinner. En redes rapidas consumibles/graficos
        // respondia sin mostrar claramente el preloader.
        //
        // NOTA: esta variable es LOCAL al scope de loadPage(). Antes se llamaba
        // `_preloaderShownAt` pero colisionaba con la variable del outer
        // closure (linea ~272) que usa el watchdog de 8s. Renombrada a
        // `_navShownAt` para eliminar el shadow y que el watchdog sea eficaz.
        const MIN_PRELOADER_MS = 280;
        const _navShownAt = performance.now();

        const _hidePreloaderRespectingMinTime = () => {
            if (!window.hidePreloader) return;
            const elapsed = performance.now() - _navShownAt;
            if (elapsed < MIN_PRELOADER_MS) {
                setTimeout(() => window.hidePreloader(), MIN_PRELOADER_MS - elapsed);
            } else {
                window.hidePreloader();
            }
        };

        try {
            if (window.showPreloader) window.showPreloader();

            // Deshabilitar caché para garantizar que SIEMPRE se obtenga el HTML
            // actualizado y nunca el código viejo roto en la navegación SPA.
            const response = await fetch(url, {
                signal: controller.signal,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Cache-Control': 'no-cache, no-store, must-revalidate',
                    'Pragma': 'no-cache'
                },
                cache: 'no-store'
            });

            clearTimeout(timeoutId);

            // 403 de AuthorizationException: servidor devuelve JSON con
            // {success:false, message, forbidden:true}. Mostrar toast y
            // ABORTAR la navegacion (no reload, o caeriamos en bucle: el
            // destino seguira devolviendo 403 al no tener el permiso).
            if (response.status === 403) {
                handledCleanup = true;
                if (window.hidePreloader) window.hidePreloader();
                let msg = 'No tienes permiso para acceder a esa sección.';
                try {
                    const body = await response.json();
                    if (body && body.message) msg = body.message;
                } catch (_) { /* sin body JSON, usar default */ }
                if (typeof window.showToast === 'function') {
                    window.showToast(msg, 'error');
                } else if (typeof window.showModal === 'function') {
                    window.showModal({ type: 'error', title: 'Acceso Denegado', message: msg, confirmText: 'Entendido', hideCancel: true });
                }
                return;
            }

            // Respuesta HTTP con error → navegación normal
            if (!response.ok) {
                handledCleanup = true;
                window.location.href = url;
                return;
            }

            // Si la respuesta no es HTML (PDF, JSON, archivo) → navegación normal
            const contentType = response.headers.get('Content-Type') || '';
            if (!contentType.includes('text/html')) {
                handledCleanup = true;
                if (window.hidePreloader) window.hidePreloader();
                window.location.href = url;
                return;
            }

            const html = await response.text();

            // Extraer contenido del viewport
            const parser = new DOMParser();
            const doc    = parser.parseFromString(html, 'text/html');

            // Auto Cache-Busting: detectar si el servidor sirvió versiones mas nuevas
            // de nuestros scripts. Si hay cambio REAL -> hard reload para evitar bugs
            // por codigo desactualizado. Excluimos scripts no-criticos (pwa-install,
            // service worker loader, etc.) cuya nueva version no afecta la logica
            // de la app — evitamos reloads innecesarios que se perciben como "se
            // recargo toda la pagina" al navegar entre modulos.
            const newScripts     = Array.from(doc.querySelectorAll('script[src]'));
            const currentScripts = Array.from(document.querySelectorAll('script[src]'));
            let versionChanged   = false;

            // Paths que NO disparan hard reload aunque cambie su version:
            // - pwa-install.js: solo registra SW, no afecta paginas abiertas.
            // - sw.js: el service worker se actualiza en su propio canal.
            const NON_CRITICAL_SCRIPTS = ['/js/pwa-install.js', '/sw.js'];

            for (let i = 0; i < newScripts.length; i++) {
                const ns = newScripts[i];
                if (!ns.src.includes(window.location.origin)) continue; // externos

                const basePath = ns.src.split('?')[0];
                if (NON_CRITICAL_SCRIPTS.some(p => basePath.endsWith(p))) continue;

                const matchingCurrent = currentScripts.find(cs => cs.src.split('?')[0] === basePath);
                if (matchingCurrent && matchingCurrent.src !== ns.src) {
                    versionChanged = true;
                    console.log(`Nueva versión detectada para: ${basePath}. Requiriendo recarga completa.`);
                    break;
                }
            }

            if (versionChanged) {
                handledCleanup = true;
                window.location.href = url;
                return;
            }

            const newContent = doc.querySelector('.main-viewport');

            if (!newContent) {
                handledCleanup = true;
                window.location.href = url;
                return;
            }

            // Solo modificar historial después de confirmar que es contenido válido
            if (pushHistory) {
                history.pushState(null, '', url);
            }

            const titleEl = doc.querySelector('title');
            document.title = titleEl ? titleEl.innerText : document.title;
            mainViewport.innerHTML = newContent.innerHTML;

            // Re-ejecutar scripts del contenido inyectado EN ORDEN y esperando
            // cada externo (CDN) antes de continuar — crítico para Chart.js, etc.
            await executeScripts(mainViewport);

            // También ejecutar scripts fuera de .main-viewport del HTML descargado
            // (ej: @section('extra_js') de Laravel). Sin esto, módulos que definen
            // sus funciones en extra_js (fuera del viewport) nunca las registran en
            // window cuando se llega por navegación SPA desde otro módulo.
            // Los guards tipo _fallasReady en cada IIFE evitan la doble ejecución.
            const extraScriptContainer = document.createElement('div');
            const fetchedMain = doc.querySelector('.main-viewport');
            Array.from(doc.body.querySelectorAll('script')).forEach(s => {
                if (s.src) return;                             // externos: ya manejados arriba
                if (fetchedMain && fetchedMain.contains(s)) return; // inline del viewport: ya ejecutados
                extraScriptContainer.appendChild(s.cloneNode(true));
            });
            if (extraScriptContainer.childElementCount > 0) {
                await executeScripts(extraScriptContainer);
            }


            updateActiveLinks(url);
            window.dispatchEvent(new CustomEvent('spa:contentLoaded'));

            // Marcar como manejado ANTES de ocultar, para que el bloque finally
            // no ejecute un segundo hidePreloader (race condition fix).
            handledCleanup = true;
            // hidePreloader respetando MIN_PRELOADER_MS para que no parpadee
            // en navegaciones muy rapidas (<280ms): el usuario siempre ve el spinner.
            _hidePreloaderRespectingMinTime();
            window.scrollTo({ top: 0, behavior: 'smooth' });

            // Cerrar menú mobile si está abierto
            const mobileMenu = document.getElementById('mobileMenu');
            if (mobileMenu && mobileMenu.classList.contains('active')) {
                mobileMenu.classList.remove('active');
            }

        } catch (error) {
            clearTimeout(timeoutId);

            if (error.name === 'AbortError') {
                console.warn('SPA: tiempo de espera agotado (12s), recargando normalmente.');
            } else {
                console.error('SPA: Error cargando página:', error);
            }

            // En cualquier caso, intentar carga normal del navegador
            handledCleanup = true;
            if (window.hidePreloader) window.hidePreloader();
            window.location.href = url;

        } finally {
            // Solo ocultar el spinner aquí si el try/catch NO lo manejó.
            // Previene el race condition donde finally ejecuta antes
            // de que el bloque try termine su limpieza.
            if (!handledCleanup) {
                if (window.hidePreloader) window.hidePreloader();
            }
        }
    }

    // ── GUARD ANTI-SPINNER-CONGELADO ─────────────────────────────────────────
    // Cuando el usuario regresa a la pestaña después de tenerla en segundo plano,
    // el browser puede haber "pausado" las animaciones y el spinner puede quedar
    // visualmente atascado. Este handler lo limpia automáticamente si el preloader
    // lleva más de 8 segundos visible al momento de regresar a la pestaña.
    let _preloaderShownAt = 0;
    const _origShow = window.showPreloader;
    const _origHide = window.hidePreloader;

    if (_origShow) {
        window.showPreloader = function () {
            _preloaderShownAt = Date.now();
            _origShow.apply(this, arguments);
        };
    }
    if (_origHide) {
        window.hidePreloader = function () {
            _preloaderShownAt = 0;
            _origHide.apply(this, arguments);
        };
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            // Si el spinner lleva más de 8s visible al regresar a la pestaña → forzar ocultar
            if (_preloaderShownAt > 0 && (Date.now() - _preloaderShownAt) > 8000) {
                console.warn('SPA: Spinner detectado como posiblemente congelado al volver a la pestaña. Ocultando.');
                if (window.hidePreloader) window.hidePreloader();
                _preloaderShownAt = 0;
            }

            // Safety net adicional: si el preloader tiene display:flex pero NO hay
            // una navegación activa (no hay flag de loadPage en progreso), forzar ocultar.
            // Cubre el caso donde el fetch quedó cancelado pero el spinner no se limpió.
            setTimeout(function () {
                const preloader = document.getElementById('preloader');
                if (preloader &&
                    preloader.style.display === 'flex' &&
                    _preloaderShownAt === 0) {
                    console.warn('SPA: Safety net — preloader visible sin navegación activa. Ocultando.');
                    if (window.hidePreloader) window.hidePreloader();
                }
            }, 500);
        }
    });


    function updateActiveLinks(url) {
        document.querySelectorAll('.nav-link, .mobile-nav-link').forEach(link => {
            if (link.href === url) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });

        // Add dynamic visibility for the "Inicio" (Menu) button in SPA transitions
        const navInicioBtn = document.getElementById('nav-inicio-btn');
        if (navInicioBtn) {
            try {
                const urlObj = new URL(url, window.location.origin);
                if (urlObj.pathname === '/menu' || urlObj.pathname.endsWith('/menu')) {
                    navInicioBtn.style.setProperty('display', 'none', 'important');
                } else {
                    navInicioBtn.style.setProperty('display', 'flex', 'important');
                }
            } catch (e) {
                console.error("Error updating nav-inicio button", e);
            }
        }
    }
});
