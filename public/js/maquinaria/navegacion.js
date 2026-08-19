/* spa-nav.js - Handle dynamic content loading with progress bar */
document.addEventListener('DOMContentLoaded', () => {
    const mainViewport = document.querySelector('.main-viewport');

    // Cabeceras de toda petición de navegación SPA. Fuente ÚNICA: las usan loadPage() y
    // la precarga al pasar el mouse — deben ser IDÉNTICAS o el servidor podría responder
    // distinto a la copia precargada que a la que pide el clic.
    //   · Cache-Control/Pragma + cache:'no-store' → SIEMPRE el HTML actualizado, nunca una
    //     copia vieja del navegador con código roto.
    //   · X-SPA-Navigate: marca propia para que el Service Worker trate esto como
    //     navegación → la cachea (network-first) y, SIN internet, sirve la copia cacheada
    //     para poder moverse entre módulos offline. Es una cabecera CUSTOM a propósito (no
    //     'Accept: text/html'): así NO cambia el expectsJson() de Laravel y el manejo online
    //     de errores/permisos (403) queda IDÉNTICO.
    const CABECERAS_SPA = {
        'X-Requested-With': 'XMLHttpRequest',
        'X-SPA-Navigate': '1',
        'Cache-Control': 'no-cache, no-store, must-revalidate',
        'Pragma': 'no-cache',
        // Accept: text/html es OBLIGATORIO aquí y no es decorativo. La SPA pide la
        // PÁGINA (el HTML que se inyecta en el contenedor), y varios controladores
        // —AlmacenController::index, EquipoController, EquipoAuxiliarController…—
        // hacen `if ($request->wantsJson())` para devolver solo las filas de la
        // tabla en JSON. Sin este Accept, window.apiFetch pone su
        // 'application/json' por defecto, wantsJson() da true y la navegación
        // recibe JSON en vez de la página: loadPage no puede inyectarlo y cae a
        // recarga completa (spinner, recarga, y recién ahí el módulo).
        'Accept': 'text/html, application/xhtml+xml'
    };

    // ¿Este <a> lo maneja la navegación SPA? Fuente ÚNICA de la regla: la usan el
    // handler de clic y la PRECARGA al pasar el mouse (más abajo). Si las dos listas de
    // exclusiones vivieran por separado, la precarga podría hacer un GET a una ruta que
    // el clic descarta (ej. un <a href="#" onclick="exportar()">) → 405 en el servidor
    // por un simple hover.
    function esNavegableSPA(link) {
        if (!link || !link.href) return false;

        // Skip if link has target="_blank"
        if (link.target === '_blank') return false;

        // Only internal links, ignore logout or external
        let url;
        try { url = new URL(link.href); } catch (_) { return false; }

        // Skip blob, data, and javascript URLs
        if (url.protocol === 'blob:' || url.protocol === 'data:' || url.protocol === 'javascript:') {
            return false;
        }

        if (url.origin !== window.location.origin || link.hasAttribute('data-no-spa') || link.href.includes('logout')) {
            return false;
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
            return false;
        }

        return true;
    }

    // Intercept clicks on links
    document.addEventListener('click', async (e) => {
        const link = e.target.closest('a');

        // No es un clic primario limpio (botón central, Ctrl/Cmd para abrir en pestaña
        // nueva, etc.) → que lo maneje el navegador.
        if (e.button !== 0 || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) return;

        if (!esNavegableSPA(link)) return;

        e.preventDefault();
        navigateTo(link.href);
    });

    // ── PRECARGA AL PASAR EL MOUSE ───────────────────────────────────────────────
    // Al apuntar a un link del menú se pide su HTML por adelantado. Cuando el usuario
    // hace clic (normalmente 300-600 ms después) la respuesta ya llegó y el módulo abre
    // sin espera. Es la MISMA petición que haría el clic, solo que arrancada antes.
    //
    // NO es un caché de datos: la copia se usa UNA sola vez, en el clic inmediato, y se
    // borra al usarla. Además vence a los PREFETCH_TTL_MS — si el usuario apunta y no
    // hace clic, se descarta y el clic pide de nuevo. Así nunca se pinta un módulo con
    // información que se quedó vieja esperando.
    //
    // Solo en dispositivos con puntero real: en táctil no hay "hover" previo al toque
    // (el navegador lo emula EN el toque), así que precargar ahí no adelantaría nada y
    // duplicaría peticiones.
    const PREFETCH_TTL_MS = 5000;
    const prefetchStore   = new Map(); // url -> { html, ts }
    let   prefetchEnVuelo = null;      // evita disparar dos veces por el mismo link

    const hayPunteroReal = () => !window.matchMedia || window.matchMedia('(hover: hover)').matches;

    // Devuelve el HTML precargado de `url` si sigue vigente, y lo CONSUME (un solo uso).
    // Si venció, lo borra igual: una entrada caduca no debe sobrevivir a la siguiente vuelta.
    function tomarPrefetch(url) {
        const hit = prefetchStore.get(url);
        if (!hit) return null;
        prefetchStore.delete(url);
        return (Date.now() - hit.ts) < PREFETCH_TTL_MS ? hit.html : null;
    }

    function precargar(url) {
        if (!hayPunteroReal()) return;
        if (prefetchEnVuelo === url || prefetchStore.has(url)) return;
        prefetchEnVuelo = url;
        window.apiFetch(url, { headers: CABECERAS_SPA, cache: 'no-store' })
            .then((r) => {
                // Solo se guarda una página completa, sana y DE ESTA MISMA URL. Un 403 o un
                // PDF se descartan porque tienen su propio manejo en loadPage (toast de
                // permiso, navegación normal…) y hay que dejar que corra cuando el usuario
                // clique.
                //
                // !r.redirected es imprescindible: fetch SIGUE los redirects solo, así que un
                // 302 llega aquí como un 200 del destino. Guardarlo bajo la URL de origen
                // pintaría el contenido de una página con la dirección de otra. Pasa de
                // verdad en esta app: /admin/almacen y /admin/almacen/recepcion redirigen al
                // menú cuando el usuario no tiene almacenes visibles (AlmacenController:119,
                // TraspasoController:81) — sin este guard, apuntar al módulo y entrar dejaba
                // el menú pintado con la URL del módulo y sin el toast que explica por qué.
                const ct = r.headers.get('Content-Type') || '';
                return (r.ok && !r.redirected && ct.includes('text/html')) ? r.text() : null;
            })
            .then((html) => { if (html) prefetchStore.set(url, { html: html, ts: Date.now() }); })
            .catch(() => { /* silencioso: si falla, el clic hará la petición normal */ })
            .finally(() => { if (prefetchEnVuelo === url) prefetchEnVuelo = null; });
    }

    // `mouseover` burbujea: pasar el mouse por un link con hijos (<i>, <span>…) lo dispara
    // una vez por cada hijo. Recordar el último <a> evaluado evita repetir el trabajo de
    // esNavegableSPA —que construye un `new URL()`— en uno de los eventos más frecuentes
    // del DOM. El de verdad caro (el fetch) ya estaba cubierto dentro de precargar().
    let ultimoHover = null;
    document.addEventListener('mouseover', (e) => {
        const link = e.target.closest('a');
        if (!link) { ultimoHover = null; return; }
        if (link === ultimoHover) return;
        ultimoHover = link;
        if (!esNavegableSPA(link)) return;
        if (link.href === window.location.href) return; // ya estamos ahí
        precargar(link.href);
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

    // Numero de orden de la navegacion en curso. Sube en cada loadPage(). Sirve para que
    // una navegacion vieja no toque el spinner de la nueva: si el usuario pincha dos enlaces
    // seguidos (menos de MIN_PRELOADER_MS de diferencia), el apagado diferido de la PRIMERA
    // llegaba tarde y le restaba una referencia a la SEGUNDA, que aun estaba cargando; el
    // spinner se iba antes de tiempo y la pantalla quedaba destapada a medio cargar.
    let _navSeq = 0;

    async function loadPage(url, pushHistory = true) {
        const _miNav = ++_navSeq;
        // ── Timeout de 12s: si el servidor no responde, no dejamos el spinner eternamente ──
        const controller = new AbortController();
        const timeoutId  = setTimeout(() => controller.abort(), 12000);

        // Flag para evitar que el bloque finally oculte el preloader
        // si el bloque try ya lo manejó correctamente.
        let handledCleanup = false;

        // HANDOFF de spinner desde un flujo que redirige (guardar→navegar). Esos
        // flujos (equipos_form, catalogo_create, form_logic) dejan el spinner ENCENDIDO
        // a propósito vía window.__vidalsaRedirecting y NO hacen su propio hidePreloader.
        // Como el preloader ahora está CONTADO POR REFERENCIAS (ver estructura_base),
        // ese show quedaría "huérfano" (+1 sin su -1) y colgaría el spinner en el destino.
        // Solución: si venimos de un redirect, HEREDAMOS ese show (no sumamos otro) y el
        // único hide de esta navegación lo balancea. El flag se libera en el finally.
        const _inheritSpinner = (window.__vidalsaRedirecting === true);

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
                setTimeout(() => {
                    // Si ya arranco otra navegacion, este apagado es de una pagina que ya
                    // no esta en pantalla: restaria una referencia que no es suya.
                    if (_miNav !== _navSeq) return;
                    window.hidePreloader();
                }, MIN_PRELOADER_MS - elapsed);
            } else {
                window.hidePreloader();
            }
        };

        try {
            // Si NO heredamos el spinner de un redirect, lo encendemos nosotros.
            //
            // Antes de encenderlo se BORRA lo que dejara la pantalla anterior. El preloader
            // lleva un contador de referencias y ese contador NO se reinicia al cambiar de
            // modulo (esto es una SPA: no hay recarga). Asi que si una pantalla se guardaba
            // un +1 sin su -1 --por un fetch a medias, un listener duplicado o un formulario
            // que encendia el spinner por su cuenta-- la deuda viajaba con el usuario y era
            // el modulo SIGUIENTE el que salia con el spinner girando encima, hasta que a
            // los 8s lo mataba el watchdog. De ahi el "abro equipos y tarda burda": el fallo
            // no estaba en equipos, estaba en la pantalla de la que se venia.
            //
            // Al empezar una navegacion nada de lo anterior sigue vivo: el DOM se reemplaza
            // entero. Cualquier referencia pendiente es basura, y se tira aqui. Asi cada
            // modulo arranca en 0 y una fuga se paga como mucho en su propia pantalla, nunca
            // en la de al lado. (Con _inheritSpinner NO se toca: ahi el +1 es de un
            // guardar-redirigir en curso y lo balancea el hide de esta misma navegacion.)
            if (!_inheritSpinner) {
                if (window.hidePreloader) window.hidePreloader(true);
                if (window.showPreloader) window.showPreloader();
            }

            // ¿El hover ya trajo esta página? (ver "PRECARGA AL PASAR EL MOUSE"). Se
            // CONSUME: la copia se usa una vez y desaparece. Solo se guardan respuestas
            // 200 text/html, así que por esta vía nunca llega un 403, un redirect ni un
            // PDF — sus comprobaciones siguen viviendo en la rama de red, que es la única
            // que puede producirlos.
            let html = tomarPrefetch(url);

            if (html !== null) {
                clearTimeout(timeoutId);
            } else {
                const response = await window.apiFetch(url, {
                    signal: controller.signal,
                    headers: CABECERAS_SPA,
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

                html = await response.text();
            }

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

            // HOJAS DE ESTILO (<link rel="stylesheet">): la SPA NO re-evalúa los <link>
            // al navegar, así que un cambio CSS-only (z-index del PDF, menú, etc.) no se
            // veía hasta un F5 manual. A DIFERENCIA de los <script> —que requieren recarga
            // completa para re-evaluar su lógica— una hoja de estilo nueva se aplica EN
            // CALIENTE cambiando el href del <link> existente: se toma el CSS actualizado
            // SIN recargar la página, manteniendo la navegación SPA fluida (antes esto
            // forzaba un window.location.href y se percibía como "se recargó toda la
            // página" al editar el CSS y navegar a otro módulo).
            if (!versionChanged) {
                const newLinks     = Array.from(doc.querySelectorAll('link[rel="stylesheet"][href]'));
                const currentLinks = Array.from(document.querySelectorAll('link[rel="stylesheet"][href]'));
                for (let i = 0; i < newLinks.length; i++) {
                    const nl = newLinks[i];
                    if (!nl.href.includes(window.location.origin)) continue; // CDNs externos
                    const basePath = nl.href.split('?')[0];
                    const matchingCurrent = currentLinks.find(cl => cl.href.split('?')[0] === basePath);
                    if (matchingCurrent && matchingCurrent.href !== nl.href) {
                        matchingCurrent.href = nl.href; // hot-swap: aplica el nuevo CSS sin recargar
                        console.log(`Nueva versión de CSS aplicada en caliente: ${basePath}`);
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
                // El banner "Sin conexión" ya lo sacó el interceptor global de fetch
                // (estructura_base), que ve fallar ESTA misma petición. Aquí solo queda el
                // aviso propio de la navegación: que la página no cambió y se puede reintentar.
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
            // Liberar el flag de "redirigiendo" UNA sola vez por navegación, cubriendo
            // TODOS los caminos (éxito y error). Punto único de propiedad: si quedara
            // colgado en true, la siguiente navegación heredaría un spinner inexistente
            // y se ocultaría antes de tiempo. (Antes se limpiaba en spa:contentLoaded,
            // que solo cubre el camino de éxito.)
            window.__vidalsaRedirecting = false;

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
            _origHide.apply(this, arguments);
            // El preloader base ahora está CONTADO POR REFERENCIAS: un hide()
            // intermedio (con operaciones aún en vuelo) NO oculta el spinner y NO
            // le añade la clase 'fade-out'. Solo limpiamos el timestamp del watchdog
            // cuando el spinner se ocultó de verdad; si no, el guard de 8s perdería
            // su referencia de tiempo y no podría destrabar un spinner congelado.
            const _pl = document.getElementById('preloader');
            if (!_pl || _pl.classList.contains('fade-out')) _preloaderShownAt = 0;
        };
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            // Si el spinner lleva más de 8s visible al regresar a la pestaña → forzar ocultar
            if (_preloaderShownAt > 0 && (Date.now() - _preloaderShownAt) > 8000) {
                console.warn('SPA: Spinner detectado como posiblemente congelado al volver a la pestaña. Ocultando.');
                // force=true: resetea el contador de referencias y oculta sí o sí
                // (un decremento normal no bastaría si quedó algún show() colgado).
                if (window.hidePreloader) window.hidePreloader(true);
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
                    if (window.hidePreloader) window.hidePreloader(true);
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
