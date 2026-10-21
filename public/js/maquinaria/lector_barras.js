/**
 * window.LectorBarras — Escaneo del CÓDIGO DE BARRAS de los productos (Code 128, el de las
 * etiquetas del almacén), COMPARTIDO por los módulos de inventario (/admin/almacen,
 * /admin/almacen/movimientos, /admin/almacen/recepcion). Antes leía códigos QR.
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
 *   2) window.LectorBarras.init({
 *          input:      'almFiltroBuscar',   // id del buscador de la vista
 *          icono:      'almBuscarScan',     // id del icono de escanear dentro de ese buscador
 *          activo:     function () { ... }, // "¿hay filtro puesto?" — decide la vista
 *          onProducto: function (p, label) { ...aplicar el filtro... }
 *      });
 *
 * Comportamiento:
 *   · Teléfono → abre el modal y arranca la cámara (html5-qrcode) pidiendo permiso de una.
 *                Arriba del recuadro, «Filtrar aquí | En la PC» (se recuerda por teléfono):
 *                  aquí  → el CODIGO se resuelve contra almacen.buscar-codigo, el modal se
 *                          cierra y se entrega a onProducto (filtra el teléfono).
 *                  En la PC → el código va a almacen.lector-remoto y la cámara SIGUE abierta
 *                          para leer el siguiente; lo filtra la PC (App\Services\LectorRemoto).
 *   · PC       → NO abre el modal (no hay cámara): enfoca el buscador para que el lector
 *                USB teclee ahí. Además un listener global resuelve el escaneo de un lector
 *                USB aunque no haya nada enfocado, y escucharTelefono() recoge lo que mande
 *                el teléfono del mismo usuario y lo entrega a onProducto.
 */
(function () {
    'use strict';

    // Script global: si la SPA vuelve a inyectarlo, no re-declaramos nada.
    if (window.LectorBarras) return;

    // Configuración de la VISTA ACTIVA: la fija init en cada navegación SPA.
    var cfg = null;

    // Las URLs (endpoint que resuelve el CODIGO y copia local de html5-qrcode) viajan como
    // data-* del propio modal y se leen AL USARLAS, no al cargar: así el partial no impone
    // un orden de scripts. Si el modal no está en la página, devuelven '' y quien llama corta.
    function ruta(nombre) {
        var m = el('lbrModal');
        return (m && m.getAttribute('data-' + nombre)) || '';
    }

    var scanner    = null;    // instancia Html5Qrcode (si la cámara arrancó)
    var camaraLista = false;  // ya hay video y se está leyendo
    var RESOLUCION = { width: { ideal: 1280 }, height: { ideal: 720 } };   // la que se pide a la cámara
    var busy       = false;   // evita resolver dos veces el mismo código
    var libPromise = null;    // carga de html5-qrcode cacheada

    function el(id) { return id ? document.getElementById(id) : null; }
    function esMovil() { return /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent || ''); }
    // Cualquier overlay abierto: los de inventario siguen el patrón "clase con
    // -overlay + .open" (.alm-modal-overlay, .dtm-overlay, .cdir-overlay, .lbr-overlay)
    // y los globales de estilos_globales.css usan .modal-overlay + .active.
    function hayModalAbierto() { return !!document.querySelector('[class*="overlay"].open, [class*="overlay"].active'); }
    function etiqueta(p) { return (p.codigo || '') + (p.codigo && p.nombre ? ' — ' : '') + (p.nombre || ''); }
    function cabecerasJson() { return { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }; }

    // localStorage puede no estar (modo privado, datos bloqueados): sin él vale "aquí".
    function leerLocal(clave) { try { return window.localStorage.getItem(clave); } catch (e) { return null; } }
    function guardarLocal(clave, valor) { try { window.localStorage.setItem(clave, valor); } catch (e) { /* sin almacenamiento */ } }

    // Dónde filtra lo que lee la cámara del teléfono: 'aqui' o 'pc'. Lo elige el botón del modal.
    var destino = leerLocal('lbrDestino') === 'pc' ? 'pc' : 'aqui';
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
        var reader = el('lbrReader'), hint = el('lbrHint'), btn = el('lbrActivar');
        if (reader) { reader.style.display = 'none'; reader.classList.remove('lbr-cargando'); }
        if (btn) btn.style.display = mostrarBtn ? '' : 'none';
        if (hint) hint.textContent = msg;
    }

    // El recuadro de la cámara se enseña YA al abrir, con el alto que tuvo la última vez en
    // este teléfono (lbrAltoCamara): así el modal sale de una en su tamaño final, en vez de
    // salir encogido y crecer cuando llega el video. La primera vez se reserva un cuadrado.
    // Mientras tanto, la clase lbr-cargando hace girar la espera dentro del recuadro.
    function reservarRecuadro() {
        var reader = el('lbrReader'); if (!reader) return;
        reader.style.display = '';
        reader.classList.add('lbr-cargando');
        var alto = parseInt(leerLocal('lbrAltoCamara'), 10);
        reader.style.minHeight = (alto > 100 ? alto : reader.clientWidth) + 'px';
    }

    // Ya hay video: el recuadro toma su alto real y se recuerda para la próxima vez. Se espera
    // al primer cuadro si todavía no llegó; soltar antes el alto reservado lo encogería.
    function soltarRecuadro() {
        var reader = el('lbrReader'); if (!reader) return;
        var video = reader.querySelector('video');
        var listo = function () {
            reader.classList.remove('lbr-cargando');
            reader.style.minHeight = '';
            if (reader.offsetHeight > 100) guardarLocal('lbrAltoCamara', String(reader.offsetHeight));
        };
        if (!video || video.readyState >= 2) listo();
        else video.addEventListener('loadeddata', listo, { once: true });
    }

    function pintarDestino() {
        var aqui = el('lbrDestAqui'), pc = el('lbrDestPc');
        if (aqui) aqui.setAttribute('aria-pressed', destino === 'aqui' ? 'true' : 'false');
        if (pc) pc.setAttribute('aria-pressed', destino === 'pc' ? 'true' : 'false');
    }

    function textoListo() {
        return destino === 'pc' ? 'Apunta al código: se filtra en la PC.' : 'Apunta la cámara al código de barras…';
    }

    function iniciarCamara() {
        var hint = el('lbrHint'), btn = el('lbrActivar');
        if (hint) hint.textContent = 'Cargando cámara…';
        cargarLib().then(function (cargada) {
            if (!cargada) { fallback('Cámara no disponible en este dispositivo.', false); return; }
            // La cámara requiere contexto seguro (HTTPS/localhost). Si no, lector/tecleo.
            if (!window.isSecureContext) { fallback('La cámara necesita HTTPS para funcionar.', false); return; }
            reservarRecuadro();   // tras un fallback, "Activar cámara" lo vuelve a enseñar
            if (hint) hint.textContent = 'Iniciando cámara…';
            // Recuadro ANCHO y bajo, a la forma de un código de barras (un 90 % del ancho del
            // video y un 40 % de alto). La cámara se pide en HD (RESOLUCION): con la que da por
            // defecto (640×480) las barras finas llegaban borrosas; más no sirve, porque la
            // librería reduce cada cuadro al tamaño del recuadro antes de leerlo. El enfoque y el
            // zoom se ajustan después (afinarCamara). `fuente` es la cámara: facingMode o un
            // deviceId.
            var conf   = function (fuente) {
                return {
                    fps: 15,
                    qrbox: function (w, h) { return { width: Math.floor(w * 0.9), height: Math.floor(Math.min(w, h) * 0.4) }; },
                    videoConstraints: Object.assign({}, RESOLUCION, fuente)
                };
            };
            var onOk   = function (texto) { if (destino === 'pc') enviarAPc(texto); else resolver(texto); };
            var onTick = function () { /* frames sin código: ignorar */ };
            var ok     = function () {
                camaraLista = true;
                if (hint) hint.textContent = textoListo();
                if (btn) btn.style.display = 'none';
                soltarRecuadro();
                afinarCamara();
                if (destino === 'pc') avisarPc(true);
            };
            // Si TODO falla mostramos el motivo REAL (NotAllowedError = permiso denegado,
            // NotFoundError = sin cámara, etc.) y dejamos visible "Activar cámara" para
            // reintentar con un gesto explícito (vuelve a pedir permiso).
            var fail = function (err) {
                var msg = (err && (err.name || err.message)) ? (err.name || err.message) : 'desconocido';
                fallback('No se pudo abrir la cámara (' + msg + '). Toca "Activar cámara" y permite el acceso.', true);
            };
            try {
                // Solo Code 128, el de las etiquetas: con todos los formatos activos la librería
                // llegó a leer como código el texto o las líneas de una etiqueta (un número que
                // no era el del producto). El detector del propio teléfono (Chrome en Android),
                // que lee las barras mejor que el de la librería, html5-qrcode lo usa solo si
                // existe: viene activado por defecto.
                scanner = new Html5Qrcode('lbrReader', {
                    verbose: false,
                    formatsToSupport: typeof Html5QrcodeSupportedFormats !== 'undefined' ? [Html5QrcodeSupportedFormats.CODE_128] : undefined
                });
                // 1) Intento directo con la cámara trasera (facingMode environment).
                scanner.start({ facingMode: 'environment' }, conf({ facingMode: 'environment' }), onOk, onTick)
                    .then(ok)
                    .catch(function (e1) {
                        // 2) Fallback: enumerar cámaras y usar la última (suele ser la trasera).
                        //    Cubre teléfonos donde facingMode falla pero el deviceId sí sirve.
                        return Html5Qrcode.getCameras().then(function (cams) {
                            if (!cams || !cams.length) throw e1;
                            var id = cams[cams.length - 1].id;
                            return scanner.start(id, conf({ deviceId: { exact: id } }), onOk, onTick).then(ok);
                        });
                    })
                    .catch(fail);
            } catch (e) { fail(e); }
        });
    }

    // Ya con la cámara abierta, lo que el teléfono soporte (se pregunta a la cámara, así no se
    // pide nada que no tenga): enfoque continuo y un zoom de 1,5×, para leer la etiqueta sin
    // acercar tanto el teléfono — de cerca, por debajo de la distancia mínima de enfoque de la
    // cámara, es justo cuando sale borrosa. El try cubre que se cierre el lector entre medias:
    // la librería lanza al instante si la cámara ya no está corriendo.
    function afinarCamara() {
        if (!scanner) return;
        try {
            var cap = scanner.getRunningTrackCapabilities() || {};
            var ajustes = {};
            if (Array.isArray(cap.focusMode) && cap.focusMode.indexOf('continuous') !== -1) ajustes.focusMode = 'continuous';
            if (cap.zoom && cap.zoom.max >= 1.5) ajustes.zoom = Math.max(cap.zoom.min || 1, 1.5);
            if (!Object.keys(ajustes).length) return;
            // La resolución va otra vez: applyConstraints REEMPLAZA el juego entero y, sin ella,
            // el teléfono podía volver a su tamaño por defecto.
            scanner.applyVideoConstraints(Object.assign({}, RESOLUCION, { advanced: [ajustes] }))
                .catch(function () { /* el teléfono no lo aceptó */ });
        } catch (e) { /* la cámara ya se cerró */ }
    }

    function detenerCamara() {
        camaraLista = false;
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
                var label = etiqueta(p);
                if (cfg && typeof cfg.onProducto === 'function') cfg.onProducto(p, label);
                window.toast('Producto: ' + label, 'success');
            })
            .catch(function () {
                busy = false;
                window.toast('Error al consultar el código.', 'error');
            });
    }

    // ── Teléfono → PC (App\Services\LectorRemoto) ───────────────────────────────
    // Con la cámara en «En la PC» cada código va al servidor, que comprueba que existe y lo deja
    // en el buzón del usuario; la cámara sigue abierta para el siguiente. La cámara lee unas 15
    // veces por segundo, así que mientras la MISMA etiqueta siga delante no se reenvía: cada
    // lectura repetida corre el plazo (ultimoEnvio.t). Vuelve a salir si se aparta 2,5 s y se
    // apunta otra vez, o si se lee otro código.
    var REPETIDO_MS = 2500;
    var ultimoEnvio = { codigo: '', t: 0 };
    function enviarAPc(codigo) {
        codigo = String(codigo || '').trim();
        var url = ruta('enviar');
        if (!codigo || busy || !url) return;
        if (codigo === ultimoEnvio.codigo && Date.now() - ultimoEnvio.t < REPETIDO_MS) { ultimoEnvio.t = Date.now(); return; }
        busy = true;
        window.apiFetch(url, { method: 'POST', headers: cabecerasJson(), body: JSON.stringify({ codigo: codigo }) })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                busy = false;
                ultimoEnvio = { codigo: codigo, t: Date.now() };
                var j = res.j || {};
                if (!res.ok || !j.found || !j.producto) {
                    window.toast(j.message || ('No se encontró el código ' + codigo + '.'), 'error');
                    return;
                }
                if (navigator.vibrate) navigator.vibrate(60);
                // hay_pc: alguna PC de este usuario preguntó hace poco. Si no, se avisa: el código
                // quedó enviado pero nadie lo está esperando.
                if (j.hay_pc) window.toast('Enviado a la PC: ' + etiqueta(j.producto), 'success');
                else window.toast('Enviado, pero no hay una PC con el inventario abierto: ' + etiqueta(j.producto), 'warning');
            })
            .catch(function () {
                // También cuenta como enviado: sin red, la cámara lo volvería a intentar en cada
                // cuadro y llenaría la pantalla de avisos. Se reintenta apartando y volviendo.
                busy = false;
                ultimoEnvio = { codigo: codigo, t: Date.now() };
                window.toast('No se pudo enviar el código a la PC.', 'error');
            });
    }

    // Avisa al servidor que la cámara se abrió o se cerró en «En la PC»: con la marca puesta la
    // PC pregunta rápido. La URL del aviso de apertura se guarda (marcaPc) porque el de cierre
    // puede salir cuando el modal ya no está: init() cierra la cámara al navegar con ella
    // abierta. keepalive: ese aviso tiene que salir aunque la página cambie.
    var marcaPc = '';
    function avisarPc(activo) {
        var url = activo ? ruta('estado') : marcaPc;
        if (!url) return;
        marcaPc = activo ? url : '';
        window.apiFetch(url, { method: 'POST', keepalive: true, headers: cabecerasJson(), body: JSON.stringify({ activo: activo }) })
            .catch(function () { /* sin red: la marca caduca sola */ });
    }

    // La PC pregunta por lo que mandó el teléfono SIN sesión (fetchSinInterceptor y sin
    // cookies): la llave firmada (data-canal del modal, nueva en cada visita) dice de quién es
    // el buzón. Así preguntar no renueva la sesión, un fallo no salta el aviso de "Sin
    // conexión" y un 403 (llave vencida) no manda al login: solo deja de preguntar hasta la
    // próxima visita a la pantalla. Rápido si el teléfono tiene la cámara abierta en «En la
    // PC», despacio si no. Una sola ronda viva a la vez (esc.on); se apaga sola cuando la
    // vista ya no está.
    var PREGUNTA_RAPIDA_MS = 700, PREGUNTA_LENTA_MS = 2000, PREGUNTA_ERROR_MS = 8000;
    var esc = { on: false, desde: null };
    function escucharTelefono() {
        if (esc.on || esMovil()) return;
        esc.on = true;
        esc.desde = null;   // la primera respuesta dice desde dónde seguir: nada viejo se aplica
        preguntar();
    }
    function preguntar() {
        var url = ruta('pendientes'), canal = ruta('canal');
        if (!url || !canal || !vistaViva()) { esc.on = false; return; }
        // Pestaña escondida: no se pregunta; se sigue al volver a ella.
        if (document.hidden) {
            document.addEventListener('visibilitychange', function alVolver() {
                if (document.hidden) return;
                document.removeEventListener('visibilitychange', alVolver);
                preguntar();
            });
            return;
        }
        var u = new URL(url, window.location.origin);
        if (esc.desde !== null) u.searchParams.set('desde', esc.desde);
        window.fetchSinInterceptor(u.toString(), {
            headers: { 'Accept': 'application/json', 'X-Lector-Canal': canal },
            credentials: 'omit', cache: 'no-store'
        })
            .then(function (r) {
                if (r.status === 403) { esc.on = false; return null; }
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (j) {
                if (!j) return;
                esc.desde = j.desde;
                (j.items || []).forEach(function (it) { aplicarDelTelefono(it.producto); });
                setTimeout(preguntar, j.activo ? PREGUNTA_RAPIDA_MS : PREGUNTA_LENTA_MS);
            })
            .catch(function () { setTimeout(preguntar, PREGUNTA_ERROR_MS); });
    }
    // Mismo camino que un escaneo en la PC (onProducto). Con un modal abierto NO se filtra por
    // detrás —igual que el lector USB, que tampoco intercepta ahí—: se avisa para que lo cierre.
    function aplicarDelTelefono(p) {
        if (!p || !vistaViva() || typeof cfg.onProducto !== 'function') return;
        var label = etiqueta(p);
        if (hayModalAbierto()) {
            window.toast('Llegó del teléfono ' + label + '. Cierra la ventana abierta y vuelve a escanearlo.', 'warning');
            return;
        }
        // Ese producto ya es el filtrado (la cámara lo volvió a leer tras perderlo un momento, o
        // se escaneó dos veces la misma etiqueta): no se recarga la tabla para mostrar lo mismo.
        var inp = el(cfg.input);
        if (typeof cfg.activo === 'function' && cfg.activo() && inp && (inp.value === label || inp.dataset.active === label)) {
            window.toast('Ya está en pantalla: ' + label, 'info');
            return;
        }
        cfg.onProducto(p, label);
        window.toast('Del teléfono: ' + label, 'success');
    }

    function cerrar() {
        avisarPc(false);   // si la cámara estaba en «En la PC»; si no, no hace nada
        // El modal se va PRIMERO, la camara despues. Al reves —que es como estaba— la X no
        // respondia hasta que la libreria soltaba el dispositivo: scanner.stop() es asincrono
        // y en un telefono tarda un momento, asi que el usuario pulsaba, no pasaba nada
        // visible y volvia a pulsar. Quitar la clase es inmediato; apagar la camara puede ir
        // por detras sin que nadie lo espere.
        var m = el('lbrModal'); if (m) m.classList.remove('open');
        detenerCamara();
    }

    // Muestra el icono de escaneo SOLO cuando NO hay filtro puesto (si lo hay, su lugar lo
    // ocupa la "x" de limpiar). Qué cuenta como "filtro puesto" lo decide cada vista con
    // cfg.activo, porque las tres lo guardan distinto (data-active / globals / hidden) y el
    // módulo compartido no debe conocer la convención de ninguna.
    function iconToggle() {
        if (!cfg || typeof cfg.activo !== 'function') return;
        var libre = !cfg.activo();               // el buscador esta vacio: cabe un icono
        var ic = el(cfg.icono);
        // En PC el icono de escanear NO abria nada (solo enfocaba el buscador, porque el
        // lector USB teclea ahi). Asi que ese hueco lo ocupa el atajo que la vista indique
        // en cfg.iconoPc — en el inventario, Kits. Si la vista no pone ninguno, en PC no se
        // enseña nada y punto.
        var esTelefono = esMovil();
        if (ic) ic.style.display = (libre && esTelefono) ? 'flex' : 'none';
        // El atajo de PC NO se esconde al filtrar. El de escanear sí, porque comparte sitio
        // con la "x" de limpiar y con el campo vacio no hace falta ninguna de las dos; pero el
        // atajo es un ACCESO, y si es el unico que tiene su pantalla (en Inventario, Kits),
        // esconderlo en cuanto se escribe algo dejaba la funcion sin puerta: con un filtro
        // puesto no se podia llegar a ella desde ningun lado. Caben los dos: son iconos
        // pequeños con flex: 0 0 auto, uno al lado del otro.
        var alt = cfg.iconoPc ? el(cfg.iconoPc) : null;
        if (alt) alt.style.display = esTelefono ? 'none' : 'flex';
    }

    // ── Lector USB global (escanear en PC SIN abrir el modal) ──────────────────
    // Un lector de código de barras por USB se comporta como teclado: "teclea" el
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

        // Escape cierra el modal de escaneo. Va aquí (y no en cada vista) porque LectorBarras es
        // el único que sabe DETENER la cámara: el handler genérico de Inventario solo
        // quitaba la clase .open y dejaba el MediaStream vivo.
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            var m = el('lbrModal');
            if (m && m.classList.contains('open')) { e.stopPropagation(); cerrar(); }
        }, true);
    })();

    window.LectorBarras = {
        /**
         * Engancha el escaneo al buscador de la vista actual. Se llama en CADA visita SPA
         * para que cfg apunte al DOM vivo.
         * @param {{input:string, icono:string, iconoPc?:string, onProducto:Function, activo?:Function}} opts
         */
        init: function (opts) {
            // Si se navegó con el modal abierto (botón atrás), el #lbrModal se fue del DOM
            // pero la instancia Html5Qrcode seguía viva: MediaStream abierto y decodificando
            // a 15 fps para siempre. Cortarla es lo primero.
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
            escucharTelefono();   // en PC: recoger lo que mande el teléfono (en el teléfono no hace nada)
        },

        /** Re-evalúa el icono de escaneo. Las vistas lo llaman cada vez que cambia su filtro. */
        iconToggle: iconToggle,

        /** Clic en el icono de escanear del buscador. */
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
            var m = el('lbrModal'); if (!m) return;
            busy = false;
            var btn = el('lbrActivar'); if (btn) btn.style.display = 'none';
            pintarDestino();
            m.classList.add('open');
            reservarRecuadro();   // en el mismo cuadro que el modal: sale ya de su tamaño
            // El modal es SOLO móvil: la cámara es la única vía y arranca al abrir,
            // pidiendo el permiso de una (la lib se precargó en idle).
            iniciarCamara();
        },

        cerrar: cerrar,

        /** Botón «Filtrar aquí | En la PC» del modal. Con la cámara abierta, avisa del cambio. */
        destino: function (modo) {
            destino = modo === 'pc' ? 'pc' : 'aqui';
            guardarLocal('lbrDestino', destino);
            ultimoEnvio = { codigo: '', t: 0 };
            pintarDestino();
            // Si la cámara todavía arranca, de esto se encarga su ok().
            if (camaraLista) {
                var hint = el('lbrHint'); if (hint) hint.textContent = textoListo();
                avisarPc(destino === 'pc');
            }
        },

        /**
         * Reintenta la cámara con un gesto EXPLÍCITO del usuario (botón "Activar cámara").
         * Detiene el intento previo, espera a que libere el dispositivo y vuelve a
         * arrancar — esto re-dispara el pedido de permiso cuando el arranque automático falló.
         */
        reintentar: function () {
            var btn = el('lbrActivar'); if (btn) btn.style.display = 'none';
            var hint = el('lbrHint'); if (hint) hint.textContent = 'Iniciando cámara…';
            detenerCamara();
            setTimeout(iniciarCamara, 250);
        },
    };
})();
