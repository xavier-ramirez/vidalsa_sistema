/**
 * window.QrScan — Escaneo de códigos QR de productos, COMPARTIDO por los módulos de
 * inventario (/admin/almacen, /admin/almacen/movimientos, /admin/almacen/recepcion).
 *
 * Antes vivía inline dentro de index.blade.php (~200 líneas). Al pedir el icono de
 * escaneo en los buscadores de los otros módulos se extrajo aquí para NO triplicar la
 * misma lógica (carga de la librería, cámara, lector USB, resolución del código).
 *
 * Se carga GLOBAL en estructura_base (como fuzzy_search.js): la SPA omite los
 * <script src> que van dentro de @section('content'), así que por vista no sobreviviría
 * a la navegación.
 *
 * Cómo lo usa una vista:
 *   1) @include('admin.almacen.partials.scan_modal')  → modal + rutas (una vez por página)
 *   2) window.QrScan.init({
 *          input:      'almFiltroBuscar',   // id del buscador de la vista
 *          icono:      'almBuscarScan',     // id del icono QR dentro de ese buscador
 *          activo:     function () { ... }, // "¿hay filtro puesto?" — decide la vista
 *          onProducto: function (p, label) { ...aplicar el filtro... }
 *      });
 *
 * Comportamiento (idéntico al que tenía Inventario):
 *   · Teléfono → abre el modal y arranca la cámara (html5-qrcode) pidiendo permiso de una.
 *   · PC       → NO abre el modal (no hay cámara): enfoca el buscador para que el lector
 *                USB teclee ahí. Además un listener global resuelve el escaneo de un lector
 *                USB aunque no haya nada enfocado.
 *   · El CODIGO se resuelve contra almacen.buscar-codigo y se entrega a onProducto.
 */
(function () {
    'use strict';

    // Script global: si la SPA vuelve a inyectarlo, no re-declaramos nada.
    if (window.QrScan) return;

    // Configuración de la VISTA ACTIVA: la fija init en cada navegación SPA.
    var cfg = null;

    // Las URLs (endpoint que resuelve el CODIGO y copia local de html5-qrcode) viajan como
    // data-* del propio modal y se leen AL USARLAS, no al cargar: así el partial no impone
    // un orden de scripts. Si el modal no está en la página, devuelven '' y quien llama corta.
    function ruta(nombre) {
        var m = el('qrsModal');
        return (m && m.getAttribute('data-' + nombre)) || '';
    }

    var scanner    = null;    // instancia Html5Qrcode (si la cámara arrancó)
    var busy       = false;   // evita resolver dos veces el mismo código
    var libPromise = null;    // carga de html5-qrcode cacheada

    function el(id) { return id ? document.getElementById(id) : null; }
    function esMovil() { return /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent || ''); }
    // window.toast y window.apiFetch son los helpers centrales de dom_helpers.js, que el
    // layout carga en el <head> (antes que este script): se llaman DIRECTO, sin el guard
    // `typeof === 'function'` ni fallback a fetch que dom_helpers existe para no repetir.

    // La vista que llamó a init() puede haber sido reemplazada por la navegación SPA
    // (su buscador ya no está en el DOM). En ese caso el escaneo NO debe aplicarse a
    // una vista muerta: todo lo que dependa de cfg pasa por aquí.
    function vistaViva() {
        return !!(cfg && cfg.input && document.getElementById(cfg.input));
    }

    // ── Librería de cámara (html5-qrcode) ──────────────────────────────────────
    // Carga BAJO DEMANDA probando primero el archivo local y, si no quedó definida,
    // el CDN. Se comprueba `typeof Html5Qrcode` DESPUÉS de cada carga: así detecta el
    // caso en que el servidor devuelve el index.html (HTML 200, por el try_files de
    // nginx) en vez del .js — que NO dispara onerror — y cae al CDN igual.
    function cargarLib() {
        if (typeof Html5Qrcode !== 'undefined') return Promise.resolve(true);
        if (libPromise) return libPromise;
        var cdn = 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js';
        libPromise = new Promise(function (resolve) {
            function load(src, next) {
                if (!src) { next ? next() : resolve(false); return; }
                var s = document.createElement('script');
                s.async = true; s.src = src;
                s.onload  = function () { (typeof Html5Qrcode !== 'undefined') ? resolve(true) : (next ? next() : resolve(false)); };
                s.onerror = function () { next ? next() : resolve(false); };
                document.head.appendChild(s);
            }
            load(ruta('lib'), function () { load(cdn, null); });   // local → si no, CDN
        });
        return libPromise;
    }

    // Estado de fallback del modal: oculta el recuadro de cámara, pone el mensaje y
    // muestra (o no) el botón "Activar cámara". mostrarBtn=true solo cuando reintentar
    // tiene sentido (fallo de arranque/permiso), no cuando es imposible (sin HTTPS).
    function fallback(msg, mostrarBtn) {
        var reader = el('qrsReader'), hint = el('qrsHint'), btn = el('qrsActivar');
        if (reader) reader.style.display = 'none';
        if (btn) btn.style.display = mostrarBtn ? '' : 'none';
        if (hint) hint.textContent = msg;
    }

    function iniciarCamara() {
        var hint = el('qrsHint'), reader = el('qrsReader'), btn = el('qrsActivar');
        if (hint) hint.textContent = 'Cargando cámara…';
        cargarLib().then(function (cargada) {
            if (!cargada) { fallback('Cámara no disponible en este dispositivo.', false); return; }
            // La cámara requiere contexto seguro (HTTPS/localhost). Si no, lector/tecleo.
            if (!window.isSecureContext) { fallback('La cámara necesita HTTPS para funcionar.', false); return; }
            if (reader) reader.style.display = '';
            if (hint) hint.textContent = 'Iniciando cámara…';
            var conf   = { fps: 10, qrbox: { width: 220, height: 220 } };
            var onOk   = function (texto) { resolver(texto); };
            var onTick = function () { /* frames sin código: ignorar */ };
            var ok     = function () { if (hint) hint.textContent = 'Apunta la cámara al código QR…'; if (btn) btn.style.display = 'none'; };
            // Si TODO falla mostramos el motivo REAL (NotAllowedError = permiso denegado,
            // NotFoundError = sin cámara, etc.) y dejamos visible "Activar cámara" para
            // reintentar con un gesto explícito (vuelve a pedir permiso).
            var fail = function (err) {
                var msg = (err && (err.name || err.message)) ? (err.name || err.message) : 'desconocido';
                fallback('No se pudo abrir la cámara (' + msg + '). Toca "Activar cámara" y permite el acceso.', true);
            };
            try {
                scanner = new Html5Qrcode('qrsReader', { verbose: false });
                // 1) Intento directo con la cámara trasera (facingMode environment).
                scanner.start({ facingMode: 'environment' }, conf, onOk, onTick)
                    .then(ok)
                    .catch(function (e1) {
                        // 2) Fallback: enumerar cámaras y usar la última (suele ser la trasera).
                        //    Cubre teléfonos donde facingMode falla pero el deviceId sí sirve.
                        return Html5Qrcode.getCameras().then(function (cams) {
                            if (!cams || !cams.length) throw e1;
                            return scanner.start(cams[cams.length - 1].id, conf, onOk, onTick).then(ok);
                        });
                    })
                    .catch(fail);
            } catch (e) { fail(e); }
        });
    }

    function detenerCamara() {
        if (!scanner) return;
        try {
            scanner.stop()
                .then(function () { try { scanner.clear(); } catch (e) {} scanner = null; })
                .catch(function () { scanner = null; });
        } catch (e) { scanner = null; }
    }

    // Resuelve el CODIGO contra el catálogo y se lo entrega a la vista (onProducto).
    // Si no existe, NO cierra el modal (deja seguir escaneando) y avisa.
    function resolver(codigo) {
        codigo = String(codigo || '').trim();
        if (!codigo || busy) return;
        var url = ruta('buscar'); if (!vistaViva() || !url) return;
        busy = true;
        var u = new URL(url, window.location.origin);
        u.searchParams.set('codigo', codigo);
        window.apiFetch(u.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                var j = res.j || {};
                if (!res.ok || !j.found || !j.producto) {
                    busy = false;
                    window.toast(j.message || ('No se encontró el código ' + codigo + '.'), 'error');
                    return;
                }
                var p = j.producto;
                cerrar();
                var label = (p.codigo || '') + (p.codigo && p.nombre ? ' — ' : '') + (p.nombre || '');
                if (cfg && typeof cfg.onProducto === 'function') cfg.onProducto(p, label);
                window.toast('Producto: ' + label, 'success');
            })
            .catch(function () {
                busy = false;
                window.toast('Error al consultar el código.', 'error');
            });
    }

    function cerrar() {
        detenerCamara();
        var m = el('qrsModal'); if (m) m.classList.remove('open');
    }

    // Muestra el icono de escaneo SOLO cuando NO hay filtro puesto (si lo hay, su lugar lo
    // ocupa la "x" de limpiar). Qué cuenta como "filtro puesto" lo decide cada vista con
    // cfg.activo, porque las tres lo guardan distinto (data-active / globals / hidden) y el
    // módulo compartido no debe conocer la convención de ninguna.
    function iconToggle() {
        if (!cfg || typeof cfg.activo !== 'function') return;
        var ic = el(cfg.icono); if (!ic) return;
        ic.style.display = cfg.activo() ? 'none' : 'flex';
    }

    // ── Lector USB global (escanear en PC SIN abrir el modal) ──────────────────
    // Un lector de código de barras/QR por USB se comporta como teclado: "teclea" el
    // código completo + Enter en milisegundos. Para que en PC NO haga falta abrir el
    // modal (que sin cámara no ofrece nada), escuchamos a nivel de documento: si los
    // caracteres llegan en RÁFAGA (propio del lector, no del tecleo humano) y NO hay
    // un campo enfocado ni un modal abierto, los acumulamos y al Enter resolvemos.
    // Con un input/textarea enfocado o un modal abierto NO interceptamos: el lector
    // entra por el flujo normal y el tecleo humano no se afecta.
    (function () {
        var buf = '', lastT = 0;
        var GAP_MS = 50, MIN_LEN = 3;   // ráfaga < 50ms entre teclas; código de >= 3 chars
        function enCampoEditable() {
            var a = document.activeElement; if (!a) return false;
            var t = (a.tagName || '').toLowerCase();
            return t === 'input' || t === 'textarea' || t === 'select' || a.isContentEditable;
        }
        // Cualquier overlay abierto: los de inventario siguen el patrón "clase con
        // -overlay + .open" (.alm-modal-overlay, .dtm-overlay, .cdir-overlay, .qrs-overlay)
        // y los globales de estilos_globales.css usan .modal-overlay + .active.
        function hayModalAbierto() { return !!document.querySelector('[class*="overlay"].open, [class*="overlay"].active'); }
        document.addEventListener('keydown', function (e) {
            // Descarte BARATO primero: un lector solo manda caracteres imprimibles y Enter,
            // así que Shift/Tab/flechas/F-keys se van sin tocar el DOM. Esto deja el
            // querySelector de hayModalAbierto() fuera del camino de la navegación normal.
            if (e.key !== 'Enter' && (!e.key || e.key.length !== 1)) return;
            if (e.ctrlKey || e.metaKey || e.altKey) return;   // atajos de teclado: no son del lector
            if (!vistaViva()) { buf = ''; return; }
            if (enCampoEditable() || hayModalAbierto()) { buf = ''; return; }
            var now = (window.performance && performance.now) ? performance.now() : Date.now();
            if (now - lastT > GAP_MS) buf = '';   // pausa larga entre teclas → reinicia (tecleo humano)
            lastT = now;
            if (e.key === 'Enter') {
                var cod = buf.trim(); buf = '';
                if (cod.length >= MIN_LEN) {
                    e.preventDefault();
                    busy = false;   // permite resolver aunque no se haya abierto el modal
                    resolver(cod);
                }
                return;
            }
            buf += e.key;   // carácter imprimible del código (Enter ya salió arriba)
        }, true);

        // Escape cierra el modal de escaneo. Va aquí (y no en cada vista) porque QrScan es
        // el único que sabe DETENER la cámara: el handler genérico de Inventario solo
        // quitaba la clase .open y dejaba el MediaStream vivo.
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            var m = el('qrsModal');
            if (m && m.classList.contains('open')) { e.stopPropagation(); cerrar(); }
        }, true);
    })();

    window.QrScan = {
        /**
         * Engancha el escaneo al buscador de la vista actual. Se llama en CADA visita SPA
         * para que cfg apunte al DOM vivo.
         * @param {{input:string, icono:string, onProducto:Function, activo?:Function}} opts
         */
        init: function (opts) {
            // Si se navegó con el modal abierto (botón atrás), el #qrsModal se fue del DOM
            // pero la instancia Html5Qrcode seguía viva: MediaStream abierto y decodificando
            // a 10 fps para siempre. Cortarla es lo primero.
            if (scanner) cerrar();
            cfg = opts || null;
            iconToggle();
            // Pre-carga la librería en MÓVIL (en idle, sin competir con el render). Clave
            // para que al tocar el icono el .start() corra DENTRO del gesto del usuario y
            // el navegador pida el permiso de cámara DE UNA — si la lib se bajara recién
            // al tocar, el .then() caería en una tarea posterior, se perdería el gesto y
            // saldría el paso extra "Activar cámara". En PC no hace falta (no abre modal).
            if (esMovil()) {
                var pre = function () { try { cargarLib(); } catch (e) {} };
                if (typeof requestIdleCallback === 'function') requestIdleCallback(pre, { timeout: 3000 });
                else setTimeout(pre, 2000);
            }
        },

        /** Re-evalúa el icono de escaneo. Las vistas lo llaman cada vez que cambia su filtro. */
        iconToggle: iconToggle,

        /** Clic en el icono QR del buscador. */
        abrir: function () {
            // En PC NO se abre el modal de cámara (no hay cámara → salía el feo "No se
            // pudo abrir la cámara"). La búsqueda se hace en el propio buscador: un
            // lector USB teclea ahí (y el listener global ya resuelve los escaneos sin
            // abrir nada). El modal solo tiene sentido en teléfono. Detección por
            // user-agent móvil (los equipos de campo son Android).
            if (!esMovil()) {
                var bi = cfg ? el(cfg.input) : null;
                if (bi) { bi.focus(); if (bi.select) bi.select(); }
                window.toast('Escanea con el lector USB o escribe el código en el buscador.', 'info');
                return;
            }
            var m = el('qrsModal'); if (!m) return;
            busy = false;
            var btn = el('qrsActivar'); if (btn) btn.style.display = 'none';
            m.classList.add('open');
            // El modal es SOLO móvil: la cámara es la única vía y arranca al abrir,
            // pidiendo el permiso de una (la lib se precargó en idle).
            iniciarCamara();
        },

        cerrar: cerrar,

        /**
         * Reintenta la cámara con un gesto EXPLÍCITO del usuario (botón "Activar cámara").
         * Detiene el intento previo, espera a que libere el dispositivo y vuelve a
         * arrancar — esto re-dispara el pedido de permiso cuando el arranque automático falló.
         */
        reintentar: function () {
            var btn = el('qrsActivar'); if (btn) btn.style.display = 'none';
            var hint = el('qrsHint'); if (hint) hint.textContent = 'Iniciando cámara…';
            detenerCamara();
            setTimeout(iniciarCamara, 250);
        },
    };
})();
