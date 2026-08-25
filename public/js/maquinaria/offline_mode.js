/**
 * Modo OFFLINE y estado de red: banner #netStatusBanner, window.netStatus y el
 * controlador window.OfflineMode (transiciones online<->offline, renders, lotes).
 *
 * Extraido del <script> inline de estructura_base.blade.php (2026-08-24). Iba dentro
 * del mismo bloque que el preloader — de ahi que se cargue justo despues de
 * preloader.js y justo antes de global_handlers.js: ese es el orden original y hay
 * que respetarlo. Sincrono, sin defer.
 *
 * DEPENDE DE dom_helpers.js Y TIENE QUE CARGAR DESPUES: el IIFE corre al evaluarse el
 * archivo y llama a comprobarConexion(), que usa window.apiFetch —definido en
 * dom_helpers.js—. dom_helpers va en el <head> y esto al final del body, asi que se
 * cumple; pero si alguien moviera cualquiera de los dos, esto reventaria con
 * "window.apiFetch is not a function" al cargar CADA pagina.
 *
 * No confundir con los cuatro *-offline.js (almacen/equipos/movilizaciones/
 * movimientos), que son los motores de datos por modulo; esto es el interruptor y el
 * aviso al usuario.
 */
// ── BANNER DE ESTADO DE RED ──────────────────────────────────────
// Se engancha a los eventos online/offline del browser para mostrar/ocultar
// el #netStatusBanner. Tambien chequea estado inicial al cargar la pagina,
// por si la app se abrio ya sin conexion. Expuesto en window.netStatus para
// que navegacion.js pueda forzar el aviso cuando un fetch falla aunque
// navigator.onLine diga lo contrario (red marca pero servidor caido).
(function () {
    const banner = document.getElementById('netStatusBanner');
    if (!banner) return;
    const icon = document.getElementById('netStatusIcon');
    const text = document.getElementById('netStatusText');
    let hideTimer = null;

    function showBanner(message, iconName, bgColor, autoHideMs) {
        if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
        banner.style.background = bgColor;
        icon.textContent = iconName;
        text.textContent = message;
        banner.style.display = 'flex';
        // Empujar header + contenido hacia abajo la ALTURA REAL del banner para
        // que no lo tape (se re-mide en cada showBanner: el texto cambia entre
        // estados y en móvil puede ocupar 2 líneas). offsetHeight ya es válido
        // con display:flex; el transform del slide no lo afecta.
        document.documentElement.style.setProperty('--net-banner-h', banner.offsetHeight + 'px');
        document.body.classList.add('net-banner-active');
        requestAnimationFrame(() => {
            banner.style.transform = 'translateY(0)';
        });
        if (autoHideMs && autoHideMs > 0) {
            hideTimer = setTimeout(hideBanner, autoHideMs);
        }
    }
    function hideBanner() {
        banner.style.transform = 'translateY(-100%)';
        // Restaurar el layout: el header vuelve a top:5px (con su transición) y
        // el body a su padding normal — en sync con el slide-up del banner.
        document.body.classList.remove('net-banner-active');
        document.documentElement.style.removeProperty('--net-banner-h');
        setTimeout(() => { banner.style.display = 'none'; }, 300);
    }

    // ── Modo OFFLINE: se OFRECE, no se cambia solo ──────────────────
    // Los módulos con vista offline registran su render aquí (OfflineMode).
    // Sin conexión, el banner rojo muestra el botón "Trabajar sin conexión";
    // al tocarlo se pinta la versión local y el banner pasa a ámbar.
    //
    // Registro POR CLAVE (no array): la navegación SPA re-ejecuta el script del
    // módulo en cada visita; con clave se SOBREESCRIBE en vez de acumular (sin
    // fuga). Cada render se guarda con su propio guard (pinta solo si su tabla
    // sigue en el DOM), así los módulos que ya no están en pantalla no hacen nada.
    const action = document.getElementById('netStatusAction');
    const renders = {};
    // Observador de scroll infinito vivo por tabla (ver OfflineMode.porLotes).
    const observadoresLote = new WeakMap();
    function detenerLotes(tbody) {
        const obs = observadoresLote.get(tbody);
        if (obs) { obs.disconnect(); observadoresLote.delete(tbody); }
    }

    // Devuelve el apagador del spinner emparejado con SU showPreloader: se ejecuta
    // UNA sola vez, así en el par "terminó / watchdog" el que llegue primero apaga
    // y el otro ya no vuelve a restar del contador de referencias.
    // force SOLO desde el watchdog, que es la excepción documentada del contador.
    function quitarSpinner() {
        let hecho = false;
        return function (forzar) {
            if (hecho) return;
            hecho = true;
            if (window.hidePreloader) window.hidePreloader(forzar === true);
        };
    }
    let offlineActivo = false;
    let sinConexion   = false; // true mientras el banner muestra estado offline
                               // (NO usar navigator.onLine: miente con el server caído)
    let ultimoAvisoOffline = 0; // throttle del toast "activá el modo offline" (evita spam al teclear)

    // Devuelve una promesa que resuelve cuando TODOS los módulos terminaron de
    // pintar. Los renders leen IndexedDB (asíncrono), así que sin esperarlos el
    // spinner se iría antes de que la tabla tenga datos.
    function correrRenders() {
        const ps = Object.keys(renders).map(function (k) {
            try { return Promise.resolve(renders[k]()); } catch (e) { return Promise.resolve(); }
        });
        return Promise.all(ps).catch(function () {});
    }
    // El banner tiene UN botón (#netStatusAction) reutilizado según el estado:
    // sin conexión → "Trabajar sin conexión" (activarOffline); reconectado mientras
    // se trabajaba offline → "Activar uso con internet" (volverOnline).
    var accionBoton = null;
    function configurarBoton(texto, handler) {
        accionBoton = handler;
        if (action) { action.textContent = texto; action.style.display = 'inline-flex'; }
    }
    // Ofrecer (NO activar) el modo offline. El modo es OPT-IN: NINGÚN dato local
    // se carga hasta que el usuario pulse el botón.
    //
    // El botón se ofrece SIEMPRE, tenga o no vista offline el módulo actual. Antes
    // se escondía si no había render registrado, y eso dejaba sin salida el caso
    // normal: entras sin internet, aterrizas en /menu —que no registra vista— y no
    // tienes botón; para llegar a uno de los cuatro módulos que sí lo registran
    // (almacen, equipos, movilizaciones, movimientos) dependes de que esa página ya
    // esté en la caché del service worker. En un equipo nuevo no lo está, así que el
    // modo offline era sencillamente inalcanzable.
    //
    // Ofrecerlo aquí es ademas lo correcto de significado: "trabajar sin conexión"
    // es un estado de TODA la app; los renders solo deciden qué se repinta. Desde
    // /menu no hay tabla que pintar, correrRenders() resuelve al instante y el
    // usuario queda en modo offline con el banner ámbar y la fecha de su copia,
    // listo para navegar a los módulos que sí tienen vista.
    function ofrecerOffline() {
        configurarBoton('Trabajar sin conexión', activarOffline);
    }
    // Pinta el banner ámbar "Trabajando sin conexión · <fecha de la copia local>".
    function pintarBannerOffline() {
        var pintar = function (cuando) {
            showBanner('Trabajando sin conexión' + (cuando || ''), 'cloud_off', '#b45309', 0);
        };
        if (window.OfflineDB) {
            window.OfflineDB.meta().then(function (m) {
                var c = '';
                if (m && m.generado) { var d = new Date(m.generado); if (!isNaN(d)) c = ' · ' + d.toLocaleString('es-VE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }); }
                pintar(c);
            }).catch(function () { pintar(''); });
        } else { pintar(''); }
    }
    function mostrarOffline() {
        sinConexion = true;
        // Ya trabajando offline: mantener el banner ámbar (no volver al rojo) y
        // ocultar el botón (no hay nada que ofrecer estando ya en modo local).
        if (offlineActivo) { if (action) action.style.display = 'none'; pintarBannerOffline(); return; }
        // MANUAL (opt-in): aviso rojo + OFRECEMOS el botón; NO se activa solo.
        // Sin conexión, nada de copia local hasta que el usuario pulse "Trabajar
        // sin conexión". Si no lo pulsa, la vista queda con los datos del servidor
        // y los filtros quedan bloqueados (ver pendienteActivar/avisarActivar).
        showBanner('Sin conexión a internet', 'wifi_off', '#dc2626', 0);
        ofrecerOffline();
    }
    // ── Transición ONLINE → OFFLINE (pulsar "Trabajar sin conexión") ──
    // Spinner mientras se PREPARA todo para trabajar sin señal: abrir IndexedDB,
    // leer la copia local y pintar el módulo. Se quita cuando la tabla YA está
    // pintada, no antes: los renders son asíncronos y con un tope fijo el spinner
    // se iba mientras la pantalla seguía vacía.
    function activarOffline() {
        if (offlineActivo) return;
        offlineActivo = true;
        if (action) action.style.display = 'none';
        if (window.showPreloader) window.showPreloader();
        pintarBannerOffline();  // banner ámbar con la fecha de la copia

        const quitar = quitarSpinner();
        // Watchdog: si un render se cuelga, el spinner NUNCA queda pegado.
        const perro = setTimeout(function () { quitar(true); }, 8000);
        correrRenders().then(function () { clearTimeout(perro); quitar(); });
    }
    // ── Transición OFFLINE → ONLINE (pulsar "Activar uso con internet") ──
    // Spinner mientras se SINCRONIZA de verdad, en este orden:
    //   1) confirmar que el servidor responde,
    //   2) SUBIR la cola de lo hecho sin internet — antes de recargar, porque si
    //      no la página recargada mostraría los datos del servidor sin esos
    //      cambios y el usuario vería su trabajo desaparecer unos segundos,
    //   3) BAJAR la copia fresca, para quedar listo si la señal se vuelve a ir,
    //   4) recargar, que restaura la vista online normal.
    // Si el servidor aún no responde, seguimos offline con botón de reintento.
    function volverOnline() {
        if (window.showPreloader) window.showPreloader();
        showBanner('Volviendo al modo con internet · sincronizando…', 'sync', '#16a34a', 0);

        const quitar = quitarSpinner();
        // Watchdog: pase lo que pase se recarga. La cola vive en IndexedDB, así que
        // lo que no alcanzó a subir se sube solo después de la recarga.
        const perro = setTimeout(function () { quitar(true); window.location.reload(); }, 20000);

        window.apiFetch('/offline/version', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, method: 'GET', cache: 'no-store'})
            .then(function () {
                const subir = window.OfflineOutbox ? Promise.resolve(window.OfflineOutbox.drain()) : Promise.resolve();
                return subir
                    .catch(function () {})
                    .then(function () { return window.OfflineDB ? window.OfflineDB.sync(true) : null; })
                    .catch(function () {})   // bajar la copia es deseable, no imprescindible: la recarga trae la verdad
                    .then(function () { clearTimeout(perro); quitar(); window.location.reload(); });
            })
            .catch(function () {
                clearTimeout(perro);
                quitar();   // resta del contador (no forzar: puede haber otra operación con spinner)
                // El servidor aún no responde: seguimos en modo offline (offlineActivo
                // sigue true) pero dejamos el botón para REINTENTAR — NO usamos
                // mostrarOffline() aquí porque ocultaría el botón y el usuario quedaría
                // sin forma de reintentar hasta el próximo evento 'online'.
                showBanner('El servidor no responde aún · seguí trabajando sin conexión', 'cloud_off', '#b45309', 0);
                configurarBoton('Reintentar conexión', volverOnline);
            });
    }
    if (action) action.addEventListener('click', function () { if (typeof accionBoton === 'function') accionBoton(); });

    window.addEventListener('offline', mostrarOffline);
    window.addEventListener('online', function () {
        sinConexion = false;
        // Si se estaba TRABAJANDO en modo offline, la vista quedó pintada con la
        // copia local y sus handlers en modo offline. NO recargamos de golpe (eso
        // interrumpía el trabajo): mostramos el aviso verde y OFRECEMOS el botón
        // "Activar uso con internet". Al pulsarlo → volverOnline (spinner + confirma
        // servidor + recarga a la vista online). Así el usuario decide cuándo cambiar
        // y nunca queda "congelado" (el botón está a la vista).
        if (offlineActivo) {
            showBanner('Conexión restaurada', 'wifi', '#16a34a', 0);
            configurarBoton('Activar uso con internet', volverOnline);
            return;
        }
        offlineActivo = false;
        if (action) action.style.display = 'none';
        showBanner('Conexión restaurada', 'wifi', '#16a34a', 2500);
    });
    // Si baja una copia nueva mientras se trabaja offline, repintar el módulo
    // visible. (La re-pintada al navegar por SPA la hace cada módulo en su
    // propio init sobre 'spa:contentLoaded', no aquí, para no duplicar.)
    // El segundo evento no sobra: los datos del servidor llegan primero y
    // encima de ellos outbox-sync repone (de forma asíncrona) los cambios que
    // todavía no han subido. Repintando solo con el primero, el usuario vería
    // su propio cambio desaparecer un instante y volver.
    ['offline-datos-actualizados', 'offline-optimistas-reaplicados'].forEach(function (ev) {
        window.addEventListener(ev, function () { if (offlineActivo) correrRenders(); });
    });

    // ── Detección de conexión REAL ──────────────────────────────────
    // navigator.onLine NO es confiable: en el navegador suele decir "online"
    // aunque el SERVIDOR esté caído (solo refleja la interfaz de red). Por eso
    // sondeamos /offline/version (que el SW NUNCA cachea — ver sw.js): si el
    // fetch falla, no hay servidor → mostramos el aviso. Esto hace que el
    // banner salga también en /menu y en el navegador (no solo en la PWA ni
    // solo al desconectar el wifi). En /menu solo sale el aviso informativo
    // (sin botón) porque ese módulo no tiene vista offline registrada.
    function comprobarConexion() {
        if (offlineActivo) return;                 // ya en modo offline manual: no repintar
        if (!navigator.onLine) { mostrarOffline(); return; }
        // Cualquier RESPUESTA (aunque sea 401/500) significa que el servidor
        // responde → estamos online. Solo el fallo de red (catch) = sin conexión.
        window.apiFetch('/offline/version', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, method: 'GET', cache: 'no-store'})
            .then(function () { if (window.OfflineOutbox) window.OfflineOutbox.drain(); }) // servidor OK → subir outbox
            // El aviso NO se saca aquí: esta petición va por window.apiFetch, o sea
            // por el interceptor global de fetch, que es el único que decide que se
            // fue la red. Sacarlo también aquí serían dos dueños del mismo banner.
            // El catch existe solo para atender el rechazo (si no, queda una promesa
            // rechazada sin manejar en cada carga sin conexión).
            .catch(function () {});
    }

    // Se publica ANTES de la primera comprobación: así el interceptor ya lo
    // encuentra si esa misma petición falla, sin depender de que el rechazo llegue
    // después de esta línea (que llega, pero por un detalle de orden asíncrono).
    window.netStatus = { showOffline: mostrarOffline, hide: hideBanner, comprobar: comprobarConexion };

    // Estado inicial al cargar: comprobar conexión real (no solo navigator.onLine).
    comprobarConexion();

    // API para los módulos. registrar(clave, fn): clave única por módulo; `fn`
    // debe DEVOLVER la promesa de su render (por eso los 4 módulos hacen
    // `return OM.conOfflineDB(render)`) — es lo que deja esperar el pintado antes
    // de apagar el spinner del cambio de modo.
    // Helpers compartidos (esc, norm, fmt, porLotes/detenerLotes, conOfflineDB)
    // para no duplicarlos en cada módulo.
    window.OfflineMode = {
        registrar: function (clave, fn) {
            renders[clave] = fn;
            // Si YA estamos sin conexión cuando el módulo se registra (p.ej. la
            // app se abrió offline), OFRECEMOS el botón (opt-in) en vez de activar
            // solo — el usuario decide pasar a la copia local.
            if (sinConexion && !offlineActivo) ofrecerOffline();
        },
        activar: activarOffline,
        estaActivo: function () { return offlineActivo; },
        // Sin conexión detectada pero el usuario AÚN no activó el modo offline
        // (opt-in pendiente). Los patches de carga de cada módulo consultan esto
        // para BLOQUEAR sus filtros/búsqueda en vez de pegarle al servidor caído.
        pendienteActivar: function () { return sinConexion && !offlineActivo; },
        // Aviso (con throttle) + resalte del botón cuando el usuario intenta
        // filtrar sin haber activado el modo offline. Lo llaman esos patches.
        avisarActivar: function () {
            var ahora = Date.now();
            if (ahora - ultimoAvisoOffline > 2500) {
                ultimoAvisoOffline = ahora;
                window.toast("Sin conexión — presioná 'Trabajar sin conexión' para usar la copia local.", 'warning');
            }
            if (action && action.style.display !== 'none') {
                action.style.transition = 'transform .15s ease';
                action.style.transform  = 'scale(1.12)';
                setTimeout(function () { action.style.transform = 'scale(1)'; }, 180);
            }
        },
        // Delega en el helper central (dom_helpers.js). Se llama en tiempo de
        // USO, no aquí: este bloque se evalúa ANTES del <script> de
        // dom_helpers, así que un alias directo guardaría undefined.
        // La copia que había aquí no escapaba ' — la usan los 4 módulos
        // offline vía OM.esc.
        esc: function (s) { return window.escapeHtml(s); },
        // Normalización para buscar (sin acentos + minúsculas, vía FuzzySearch si
        // cargó) con separadores colapsados a espacio: el texto que deja una
        // sugerencia clickeada es "PARTE · NOMBRE" y sin esto nunca coincidiría
        // con el haystack "codigo nombre". La usan los motores de filtro offline.
        norm: function (s) {
            var base = (window.FuzzySearch && window.FuzzySearch.norm)
                ? window.FuzzySearch.norm(s)
                : String(s == null ? '' : s).toLowerCase();
            return base.replace(/[^a-z0-9ñ]+/g, ' ').trim();
        },
        // Números en formato latino (1.234,5 — hasta 3 decimales sin ceros
        // sobrantes), réplica EXACTA de number_format(n,3,',','.') de las tablas
        // online. No usa toLocaleString('es-ES'): ese locale NO agrupa miles en
        // números de 4 dígitos (1234,5) y desentonaría con las filas online.
        fmt: function (n) {
            var v = Number(n) || 0;
            var neg = v < 0 ? '-' : '';
            var s = Math.abs(v).toFixed(3).replace(/0+$/, '').replace(/\.$/, '');
            var p = s.split('.');
            p[0] = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return neg + (p[1] ? p[0] + ',' + p[1] : p[0]);
        },
        // ── Pintado POR LOTES (scroll infinito) ────────────────────────
        // Las tablas online paginan (150 equipos / 120 productos por lote) y las
        // offline volcaban TODAS las filas de una sola vez: con ~1.200 equipos el
        // teléfono construía megas de HTML y se quedaba trabado, además de verse
        // distinto de la web. Aquí se inyecta el primer lote y se observa la
        // última fila para traer el siguiente, igual que el IntersectionObserver
        // de las tablas online (mismo rootMargin de 400px).
        // UN solo observador por tabla: repintar (otro filtro) cancela el anterior.
        porLotes: function (tbody, filas, hacerFila, tam) {
            detenerLotes(tbody);

            var lote = function (desde) {
                var trozo = filas.slice(desde, desde + tam);
                var html  = trozo.map(hacerFila).join('');
                if (desde === 0) tbody.innerHTML = html;
                else tbody.insertAdjacentHTML('beforeend', html);

                var sig    = desde + trozo.length;
                var ultima = tbody.lastElementChild;
                if (sig >= filas.length || !ultima) return;

                var obs = new IntersectionObserver(function (entradas) {
                    if (!entradas[0] || !entradas[0].isIntersecting) return;
                    obs.disconnect();
                    if (observadoresLote.get(tbody) === obs) observadoresLote.delete(tbody);
                    if (!document.contains(tbody)) return;   // navegó por SPA: no seguir
                    lote(sig);
                }, { root: null, rootMargin: '400px', threshold: 0 });
                observadoresLote.set(tbody, obs);
                obs.observe(ultima);
            };
            lote(0);
        },
        // Cancela el scroll infinito de una tabla. Lo llaman los módulos al empezar
        // a repintar: si el pintado termina en un MENSAJE (sin filtro, sin
        // resultados) no pasa por porLotes, y el observador del pintado anterior
        // se quedaría vivo hasta que el navegador recogiera el <tbody>.
        detenerLotes: detenerLotes,
        // Espera a que OfflineDB (offline-sync.js, al final del body) esté listo.
        // Devuelve una PROMESA que resuelve cuando `cb` terminó (adopta la suya si
        // devuelve una): es lo que permite que el spinner del cambio de modo se
        // apague recién cuando la tabla ya está pintada.
        conOfflineDB: function (cb) {
            return new Promise(function (resolve) {
                if (window.OfflineDB) return resolve(cb());
                var n = 0;
                var t = setInterval(function () {
                    if (window.OfflineDB) { clearInterval(t); resolve(cb()); }
                    else if (++n > 50) { clearInterval(t); resolve(); }
                }, 100);
            });
        }
    };
})();
