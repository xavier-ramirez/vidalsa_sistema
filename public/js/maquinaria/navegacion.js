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
        // ── Timeout de 60s (1 minuto): evita el spinner eterno si el internet falla ──
        const controller = new AbortController();
        const timeoutId  = setTimeout(() => controller.abort(), 60000);

        try {
            if (window.showPreloader) window.showPreloader();

            // Deshabilitar caché para garantizar que SIEMPRE se obtenga el HTML
            // actualizado y nunca el código viejo roto en la navegación SPA.
            const response = await fetch(url, {
                signal: controller.signal,          // ← conectado al timeout
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Cache-Control': 'no-cache, no-store, must-revalidate',
                    'Pragma': 'no-cache'
                },
                cache: 'no-store'
            });

            clearTimeout(timeoutId); // cargó bien → cancelar el timeout

            // Respuesta HTTP con error → navegación normal
            if (!response.ok) {
                window.location.href = url;
                return;
            }

            // Si la respuesta no es HTML (PDF, JSON, archivo) → navegación normal
            const contentType = response.headers.get('Content-Type') || '';
            if (!contentType.includes('text/html')) {
                window.location.href = url;
                return;
            }

            const html = await response.text();

            // Extraer contenido del viewport
            const parser = new DOMParser();
            const doc    = parser.parseFromString(html, 'text/html');
            
            // Auto Cache-Busting: Detectar si el servidor sirvió versiones más nuevas de nuestros scripts
            const newScripts = Array.from(doc.querySelectorAll('script[src]'));
            const currentScripts = Array.from(document.querySelectorAll('script[src]'));
            let versionChanged = false;
            
            for (let i = 0; i < newScripts.length; i++) {
                const ns = newScripts[i];
                // Ignorar librerías externas o CDN por si cambian de formato sin ser una nueva versión de la app
                if (!ns.src.includes(window.location.origin)) continue;
                
                const basePath = ns.src.split('?')[0];
                const matchingCurrent = currentScripts.find(cs => cs.src.split('?')[0] === basePath);
                
                if (matchingCurrent && matchingCurrent.src !== ns.src) {
                    versionChanged = true;
                    console.log(`Nueva versión detectada para: ${basePath}. Requiriendo recarga completa.`);
                    break;
                }
            }

            if (versionChanged) {
                window.location.href = url; // Hard reload
                return;
            }

            const newContent = doc.querySelector('.main-viewport');

            if (!newContent) {
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

            setTimeout(() => {
                if (window.hidePreloader) window.hidePreloader();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }, 100);

            // Cerrar menú mobile si está abierto
            const mobileMenu = document.getElementById('mobileMenu');
            if (mobileMenu && mobileMenu.classList.contains('active')) {
                mobileMenu.classList.remove('active');
            }

        } catch (error) {
            clearTimeout(timeoutId);

            if (error.name === 'AbortError') {
                // Timeout de 60s agotado o conexión caída
                console.warn('SPA: tiempo de espera agotado (1 min), recargando normalmente.');
            } else {
                console.error('Error loading page:', error);
            }

            // En cualquier caso, intentar carga normal del navegador
            window.location.href = url;

        } finally {
            // SIEMPRE ocultar el spinner, pase lo que pase
            if (window.hidePreloader) window.hidePreloader();
        }
    }

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
