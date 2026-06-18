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

        // Saltar links que NO son navegación de página — los maneja su propio JS
        // (onclick) o el navegador. Sin esto, en un <a href="#" onclick="accion()">
        // el onclick corre pero su `return false` NO detiene la propagación, así que
        // este listener global del SPA igual se dispara y hace un GET extra a la URL
        // del href; si esa ruta no acepta GET → "405 Method Not Allowed". Cubre las
        // acciones tipo Export/Crear (<a href="#" onclick=...>) y las descargas.
        // (Los href "javascript:" ya se descartaron arriba por protocolo.)
        const rawHref = (link.getAttribute('href') || '').trim();
        if (rawHref === '' || rawHref.charAt(0) === '#'
            || link.hasAttribute('onclick')
            || link.hasAttribute('download')) {
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
                    // Marca propia para que el Service Worker trate esta navegación SPA como
                    // navegación → la cachea (network-first) y, SIN internet, le sirve la copia
                    // cacheada para navegar entre módulos offline. Se usa una cabecera CUSTOM
                    // (no 'Accept: text/html') a propósito: así NO cambia el expectsJson() de
                    // Laravel y el manejo online de errores/permisos (403) queda IDÉNTICO.
                    'X-SPA-Navigate': '1',
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

            // Igual que los scripts, pero para HOJAS DE ESTILO (<link rel="stylesheet">):
            // la SPA NO re-evalúa los <link> al navegar, así que un cambio CSS-only (z-index
            // del PDF, menú, etc.) no se veía en PCs cacheadas hasta un F5 manual. Si cambió
            // el ?v de algún CSS propio, forzamos recarga completa para tomarlo.
            if (!versionChanged) {
                const newLinks     = Array.from(doc.querySelectorAll('link[rel="stylesheet"][href]'));
                const currentLinks = Array.from(document.querySelectorAll('link[rel="stylesheet"][href]'));
                for (let i = 0; i < newLinks.length; i++) {
                    const nl = newLinks[i];
                    if (!nl.href.includes(window.location.origin)) continue; // CDNs externos
                    const basePath = nl.href.split('?')[0];
                    const matchingCurrent = currentLinks.find(cl => cl.href.split('?')[0] === basePath);
                    if (matchingCurrent && matchingCurrent.href !== nl.href) {
                        versionChanged = true;
                        console.log(`Nueva versión de CSS detectada para: ${basePath}. Requiriendo recarga completa.`);
                        break;
                    }
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

            updateActiveLinks(url);
            window.dispatchEvent(new CustomEvent('spa:contentLoaded'));

            // Marcar como manejado ANTES de ocultar, para que el bloque finally
            // no ejecute un segundo hidePreloader (race condition fix).
            handledCleanup = true;
            // hidePreloader respetando MIN_PRELOADER_MS para que no parpadee
            // en navegaciones muy rapidas (<280ms): el usuario siempre ve el spinner.
            _hidePreloaderRespectingMinTime();
            window.scrollTo({ top: 0, behavior: 'smooth' });

            // Cerrar menú mobile si está abierto. Además colapsar los grupos (Flota,
            // Almacén…): sin esto, al navegar desde un módulo de la lista el menú se
            // cerraba pero el grupo quedaba desplegado y reaparecía abierto al reabrir.
            // Reusamos _mobileNavCollapseAll (definido en el layout) para no duplicar.
            const mobileMenu = document.getElementById('mobileMenu');
            if (mobileMenu && mobileMenu.classList.contains('active')) {
                mobileMenu.classList.remove('active');
                if (typeof window._mobileNavCollapseAll === 'function') window._mobileNavCollapseAll();
            }

        } catch (error) {
            clearTimeout(timeoutId);
            handledCleanup = true;
            if (window.hidePreloader) window.hidePreloader();

            // Sin conexion (navigator.onLine === false) o TypeError ("Failed to fetch"
            // tipico cuando la red esta caida o el servidor no responde): NO hacer
            // window.location.href porque tambien fallaria — solo mostrar toast y
            // dejar al usuario en la pagina actual para que reintente cuando vuelva.
            if (!navigator.onLine || error instanceof TypeError) {
                // Mostrar el aviso AUNQUE navigator.onLine diga "online": un TypeError aquí
                // ("Failed to fetch") significa que el servidor no respondió (caído o sin
                // internet real). Antes se exigía !navigator.onLine y por eso en el
                // navegador casi nunca salía el banner.
                if (typeof window.netStatus?.showOffline === 'function') {
                    window.netStatus.showOffline();
                }
                if (typeof window.showToast === 'function') {
                    window.showToast('Sin conexión. Verificá tu internet e intentá de nuevo.', 'error');
                }
                console.warn('SPA: navegacion abortada — sin conexion o servidor inalcanzable.', error);
                return;
            }

            if (error.name === 'AbortError') {
                console.warn('SPA: tiempo de espera agotado (12s), recargando normalmente.');
            } else {
                console.error('SPA: Error cargando página:', error);
            }

            // Otros errores: intentar carga normal del navegador (puede recuperarse)
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
