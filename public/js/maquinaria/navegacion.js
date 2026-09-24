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
    // El hover solo sirve donde hay puntero de verdad: en táctil el navegador lo emula EN el
    // toque, así que por ahí no se adelanta nada. El teléfono tiene su propio disparador, el
    // 'touchstart' (más abajo), que aprovecha el tiempo del gesto.
    // Cuanto vale lo precargado. Eran 5 s, y se quedaban cortos: entre que el puntero pasa
    // por el menu y el usuario decide, pasa mas tiempo que eso, y el trabajo se tiraba para
    // volver a pedir lo mismo. 30 s es lo que tarda un modulo en quedarse viejo de verdad
    // —los datos frescos los pide cada modulo por su cuenta al montarse—.
    const PREFETCH_TTL_MS = 30000;
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

    /** Precarga por hover: solo donde hay puntero de verdad (en táctil el hover no existe). */
    function precargar(url) {
        if (!hayPunteroReal()) return;
        precargarEnTactil(url);
    }

    /** La precarga en sí. La llaman el hover (arriba) y el primer toque en el teléfono. */
    function precargarEnTactil(url) {
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

    // En el TELEFONO no hay hover, pero sí hay un hueco aprovechable: entre que el dedo toca
    // y el navegador dispara el clic pasan del orden de 100-300 ms (el tiempo del gesto), y
    // ahí ya se sabe a dónde va. Se empieza a traer el módulo en el 'touchstart' y para
    // cuando llega el clic suele estar listo. Si el usuario arrastra en vez de pulsar, lo
    // único que se pierde es una petición que caduca sola.
    document.addEventListener('touchstart', (e) => {
        const link = e.target.closest('a');
        if (!link || !esNavegableSPA(link)) return;
        if (link.href === window.location.href) return;
        precargarEnTactil(link.href);
    }, { passive: true });

    // ── Atrás cierra el visor de PDF ──
    // En el teléfono (y más en la app instalada, sin barra del navegador) el gesto Atrás es
    // la forma de cerrar una ventana. Con el visor abierto cambiaba la página de DETRÁS sin
    // que se viera —o salía de la app si no había página anterior— y el visor seguía encima.
    // Al abrirse se añade un paso al historial (misma URL, estado {visorPdf}): Atrás lo quita
    // y cierra el visor. Cerrarlo con la X consume ese paso, para no dejar un Atrás que no
    // hace nada. Se vigila la clase 'active' del visor (layout_ui.js la pone y la quita), así
    // no hace falta tocar openPdfPreview/closePdfPreview.
    const visorPdf = document.getElementById('pdfPreviewModal');
    let pasoDelVisor = false;     // el paso {visorPdf} está en el historial y es el actual
    let atrasPropio = false;      // un history.back() nuestro en camino: su popstate no navega
    const visorAbierto = () => !!visorPdf && visorPdf.classList.contains('active');
    function ponerPasoDelVisor() {
        if (atrasPropio || pasoDelVisor) return;   // si hay un Atrás en camino, se pone al llegar
        history.pushState({ visorPdf: true }, '', window.location.href);
        pasoDelVisor = true;
    }
    if (visorPdf && 'MutationObserver' in window) {
        new MutationObserver(() => {
            if (visorAbierto()) { ponerPasoDelVisor(); return; }
            if (!pasoDelVisor) return;
            pasoDelVisor = false;
            // Cerrado con la X: si su paso sigue siendo el actual, se quita. (Si se navegó con
            // el visor abierto ya hay otra página encima y ese paso se queda atrás.)
            if (history.state && history.state.visorPdf) { atrasPropio = true; history.back(); }
        }).observe(visorPdf, { attributes: true, attributeFilter: ['class'] });
    }

    // Una capa del módulo anterior que bloqueaba el scroll del fondo (el detalle de un equipo,
    // las alertas del menú, un modal) se va con él sin cerrarse cuando se navega con ella
    // abierta —o con el botón Atrás— y dejaba html/body en overflow:hidden: el módulo nuevo no
    // se podía desplazar. Se libera al cambiar de módulo, antes de montar el nuevo. El visor de
    // PDF vive en el layout y sigue abierto: si guardó cómo estaba el fondo al abrirse
    // (_pdfOverflowPrev, layout_ui.js) conserva su bloqueo, pero lo de detrás ya no está, así
    // que al cerrarlo el fondo tiene que quedar libre.
    function liberarScrollDelFondo() {
        if (visorAbierto() && window._pdfOverflowPrev) {
            window._pdfOverflowPrev = { html: '', body: '' };
            return;
        }
        document.documentElement.style.overflow = '';
        document.body.style.overflow = '';
    }

    // Handle back/forward buttons
    window.addEventListener('popstate', () => {
        if (atrasPropio) {
            atrasPropio = false;
            if (visorAbierto()) ponerPasoDelVisor();   // se abrió otro documento mientras tanto
            return;
        }
        if (pasoDelVisor && visorAbierto()) {
            pasoDelVisor = false;
            if (typeof window.closePdfPreview === 'function') window.closePdfPreview();
            // El visor puede seguir abierto después de este cierre: con un documento nuevo
            // esperando su fecha, cerrar cancela esa carga y VUELVE al documento anterior
            // (layout_ui.js), así que la ventana no se va. Como su paso del historial ya se
            // gastó, hay que devolvérselo; si no, el Atrás siguiente se llevaría la página
            // entera con el visor todavía encima.
            if (visorAbierto()) ponerPasoDelVisor();
            return;
        }
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
                        // Script externo (CDN / asset): si YA está cargado no se duplica.
                        //
                        // "Ya cargado" = hay un <script> con ese mismo src FUERA del contenido
                        // que se acaba de montar. Los <script src> que vienen dentro del HTML
                        // del módulo están en el DOM pero NO se han ejecutado —el navegador no
                        // ejecuta lo insertado por innerHTML, que es justo la razón de ser de
                        // esta función—, así que mirarlos era darse por satisfecho con un
                        // script muerto: un módulo que trajera su JavaScript en un archivo
                        // propio no arrancaba nunca al entrar por la SPA (sí al recargar la
                        // página, y de ahí lo despistante del caso).
                        const yaCargado = Array.from(document.querySelectorAll(`script[src="${newScript.src}"]`))
                            .some((s) => !container.contains(s));
                        if (yaCargado) {
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
                            // Y fuera del DOM: el script ya CORRIO (los inline son clasicos y
                            // se ejecutan sincronos al insertarse), asi que quitar el nodo no
                            // deshace nada — lo que definio en window y los listeners que
                            // registro siguen ahi. Sin esto el <head> crecia sin limite: cada
                            // navegacion a Inventario dejaba ~191 KB de texto de script muerto,
                            // y ~113 KB la de auxiliares. Ir y volver entre modulos unas cuantas
                            // veces acumulaba megas de nodos inertes (se notaba en el telefono).
                            //
                            // OJO: SOLO los inline. Los <script src> de arriba NO se remueven:
                            // su presencia en el DOM es lo que consulta el guard alreadyLoaded
                            // de esta misma funcion, la deteccion de version de loadPage
                            // (document.querySelectorAll('script[src]')) y el check de
                            // cropper.min.js del modulo de catalogo.
                            newScript.remove();
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
    // seguidos, el apagado diferido de la PRIMERA (espera a que su modulo se dibuje) podia
    // llegar tarde y restarle una referencia a la SEGUNDA, que aun estaba cargando; el
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
        // Como el preloader ahora está CONTADO POR REFERENCIAS (ver preloader.js),
        // ese show quedaría "huérfano" (+1 sin su -1) y colgaría el spinner en el destino.
        // Solución: si venimos de un redirect, HEREDAMOS ese show (no sumamos otro) y el
        // único hide de esta navegación lo balancea. El flag se libera en el finally.
        const _inheritSpinner = (window.__vidalsaRedirecting === true);

        // El spinner sale SIEMPRE al empezar y se va cuando el modulo ya esta dibujado,
        // sin tiempo minimo: antes quedaba 280 ms aunque el modulo estuviera listo mucho
        // antes (medido 13-09-2026: 13 de 15 modulos listos a 65-160 ms, esperando al reloj).

        // PUNTO UNICO de apagado del spinner para esta navegacion. Solo apaga si la
        // navegacion sigue siendo la actual: si el usuario ya pincho otro enlace, el spinner
        // que se ve pertenece a la nueva y restarle una referencia la destaparia a medio
        // cargar. Todos los caminos de salida de loadPage (exito, 403, error, finally) pasan
        // por aqui para que la regla viva en un solo sitio.
        const _apagarSiSigueSiendoMia = () => {
            if (_miNav !== _navSeq) return;
            if (window.hidePreloader) window.hidePreloader();
        };
        // ¿El usuario ya pidió OTRA página mientras llegaba esta? Entonces esta se abandona
        // sin tocar nada: ni pinta, ni cambia la dirección, ni redirige, ni avisa errores.
        // Sin esto, una respuesta lenta llegaba DESPUÉS de la nueva y la tapaba (medido:
        // tocar Catálogo y a los 40 ms Usuarios terminaba mostrando Catálogo).
        const _yaNoEsLaActual = () => _miNav !== _navSeq;

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
            // A DONDE LLEVO DE VERDAD la respuesta. fetch sigue los redirects sin avisar, asi
            // que si el servidor mando a otro sitio, `url` ya no es donde estamos: lo dice
            // response.url. Se usa abajo cuando lo que llego no es una pagina de la app.
            let urlFinal = url;

            if (html !== null) {
                clearTimeout(timeoutId);
            } else {
                const response = await window.apiFetch(url, {
                    signal: controller.signal,
                    headers: CABECERAS_SPA,
                    cache: 'no-store'
                });
                clearTimeout(timeoutId);
                if (_yaNoEsLaActual()) { handledCleanup = true; return; }
                if (response.redirected && response.url) urlFinal = response.url;

                // 403 de AuthorizationException: servidor devuelve JSON con
                // {success:false, message, forbidden:true}. Mostrar toast y
                // ABORTAR la navegacion (no reload, o caeriamos en bucle: el
                // destino seguira devolviendo 403 al no tener el permiso).
                if (response.status === 403) {
                    handledCleanup = true;
                    _apagarSiSigueSiendoMia();
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
                    _apagarSiSigueSiendoMia();
                    window.location.href = url;
                    return;
                }

                html = await response.text();
                if (_yaNoEsLaActual()) { handledCleanup = true; return; }
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

            // Código DENTRO de las vistas: los módulos lo inician una sola vez por pestaña y, al
            // volver, reusan el de la primera carga. Si el servidor ya sirve otras vistas (hubo
            // una actualización), recarga completa en vez de mezclar el HTML nuevo con ese JS
            // viejo. El <head> no lo cambia la SPA, así que el meta actual es el de la carga
            // completa. Sin conexión no se compara: las páginas guardadas pueden ser de otra
            // versión y cada navegación recargaría.
            if (!versionChanged && navigator.onLine !== false) {
                const vNueva  = (doc.querySelector('meta[name="version-vistas"]') || {}).content;
                const vActual = (document.querySelector('meta[name="version-vistas"]') || {}).content;
                if (vNueva && vActual && vNueva !== vActual) {
                    versionChanged = true;
                    console.log('Vistas actualizadas en el servidor. Requiriendo recarga completa.');
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
                // urlFinal y NO url. Lo que llega sin .main-viewport es casi siempre el LOGIN:
                // el servidor cerro la sesion y redirigio a /?aviso=<motivo>. Navegar a la url
                // PEDIDA tiraba ese motivo: la sesion ya estaba muerta, esa peticion daba 401 y
                // el interceptor la traducia a "Tu sesion expiro por seguridad". Asi, a quien
                // lo habian sacado porque entro en OTRO equipo se le decia que su sesion caduco.
                // Comprobado con dos sesiones: el servidor respondia bien (302 a
                // /?aviso=otro_dispositivo) y aqui se perdia.
                window.location.href = urlFinal;
                return;
            }

            // Solo modificar historial después de confirmar que es contenido válido
            if (pushHistory) {
                history.pushState(null, '', url);
                pasoDelVisor = false;   // el paso del visor, si lo había, queda debajo de esta página
            }

            const titleEl = doc.querySelector('title');
            document.title = titleEl ? titleEl.innerText : document.title;
            mainViewport.innerHTML = newContent.innerHTML;
            liberarScrollDelFondo();

            // Re-ejecutar scripts del contenido inyectado EN ORDEN y esperando
            // cada externo (CDN) antes de continuar — crítico para Chart.js, etc.
            await executeScripts(mainViewport);

            updateActiveLinks(url);
            window.dispatchEvent(new CustomEvent('spa:contentLoaded'));

            // Marcar como manejado ANTES de ocultar, para que el bloque finally
            // no ejecute un segundo hidePreloader (race condition fix).
            handledCleanup = true;
            // El spinner se va cuando el modulo YA ESTA DIBUJADO, no al terminar de
            // montarlo. `innerHTML =` y executeScripts solo tocan el DOM: el navegador
            // pinta en un frame POSTERIOR. Apagar aqui a secas destapaba la pantalla con
            // el modulo a medio dibujar.
            //
            // Doble rAF: el primer callback corre ANTES del paint pendiente, el segundo ya
            // DESPUES de commitearlo. Mismo patron, y por el mismo motivo, que el .finally
            // de loadEquipos ("fila visible -> spinner se va"). Si el modulo pidio sus
            // propios datos al montar, el contador del preloader lo mantiene hasta que
            // esos datos tambien esten pintados.
            requestAnimationFrame(() => requestAnimationFrame(_apagarSiSigueSiendoMia));
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
            if (_yaNoEsLaActual()) return;   // falló una página que ya nadie espera: silencio
            _apagarSiSigueSiendoMia();

            // Sin conexion (navigator.onLine === false) o TypeError ("Failed to fetch"
            // tipico cuando la red esta caida o el servidor no responde): NO hacer
            // window.location.href porque tambien fallaria — solo mostrar toast y
            // dejar al usuario en la pagina actual para que reintente cuando vuelva.
            if (!navigator.onLine || error instanceof TypeError) {
                // El banner "Sin conexión" ya lo sacó el interceptor global de fetch
                // (fetch_interceptor.js), que ve fallar ESTA misma petición. Aquí solo queda el
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
                _apagarSiSigueSiendoMia();
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


/**
 * AVISO DE VERSIÓN NUEVA (pestaña que se queda abierta).
 *
 * La comparación de `version-vistas` de arriba solo corre al NAVEGAR por SPA: quien se queda
 * mirando la misma pantalla mientras se despliega un cambio seguía viendo la página vieja y
 * parecía que el cambio no se había hecho. Aquí se le pregunta al servidor cada tanto —solo
 * con la pestaña a la vista— y, si las vistas cambiaron, aparece un aviso para recargar.
 *
 * NO recarga solo: puede haber un formulario a medio llenar. El usuario decide cuándo.
 */
(function () {
    if (window.__avisoVersionInit) return;      // una sola vez por pestaña (la SPA no recarga el <head>)
    window.__avisoVersionInit = true;

    var CADA_MS = 60000;                        // cada minuto con la pestaña visible
    var meta = document.querySelector('meta[name="version-vistas"]');
    var versionInicial = meta ? meta.content : '';
    if (!versionInicial) return;                // sin huella no hay nada que comparar

    var avisando = false, pidiendo = false;

    function mostrarAviso() {
        if (avisando || document.getElementById('avisoVersionNueva')) return;
        avisando = true;
        var caja = document.createElement('div');
        caja.id = 'avisoVersionNueva';
        caja.setAttribute('role', 'status');
        caja.style.cssText = 'position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:2147483000;' +
            'display:flex;align-items:center;gap:10px;background:#1e293b;color:#fff;border-radius:12px;' +
            'padding:10px 12px 10px 14px;box-shadow:0 12px 28px rgba(15,23,42,.35);' +
            'font-family:Nunito,"Segoe UI",system-ui,sans-serif;font-size:13px;max-width:calc(100vw - 24px);';
        caja.innerHTML =
            '<span style="display:flex;align-items:center;gap:8px;">' +
                '<i class="material-icons" style="font-size:19px;color:#60a5fa;">system_update_alt</i>' +
                'Hay cambios nuevos en el sistema' +
            '</span>' +
            '<button type="button" id="avisoVersionRecargar" style="font:inherit;font-weight:700;cursor:pointer;' +
                'background:#0067b1;color:#fff;border:none;border-radius:8px;padding:6px 12px;">Actualizar</button>' +
            '<button type="button" id="avisoVersionCerrar" aria-label="Ahora no" style="font:inherit;cursor:pointer;' +
                'background:none;border:none;color:#cbd5e1;display:flex;padding:2px;">' +
                '<i class="material-icons" style="font-size:18px;">close</i></button>';
        document.body.appendChild(caja);
        document.getElementById('avisoVersionRecargar').onclick = function () { window.location.reload(); };
        document.getElementById('avisoVersionCerrar').onclick = function () { caja.remove(); };
    }

    function revisar() {
        if (pidiendo || avisando || document.hidden || navigator.onLine === false) return;
        pidiendo = true;
        fetch('/version-vistas', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .catch(function () { return null; })      // sin red o sesión vencida: se reintenta luego
            .then(function (j) {
                pidiendo = false;
                if (j && j.v && j.v !== versionInicial) mostrarAviso();
            });
    }

    setInterval(revisar, CADA_MS);
    // Al volver a la pestaña (el caso típico: se edita, se vuelve y se mira) no hay que esperar.
    document.addEventListener('visibilitychange', function () { if (!document.hidden) revisar(); });
})();
