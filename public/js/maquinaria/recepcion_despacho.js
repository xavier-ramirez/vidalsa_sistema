/*
 * recepcion_despacho.js — "Registrar y despachar" de Recepción de materiales.
 *
 * Lo que llega de la compra y sale de una vez al proyecto. Lo usan las dos pantallas que
 * capturan una entrada: la Entrada por ODC (recepcion_entrada.js) y el menú Acciones de la
 * bandeja (modal de captura de recepcion/index). Cada una pone su captura; esto pone lo demás.
 *
 * Mismo camino que "Registrar salida" de /admin/almacen: el modal #almSalidaModal lleva la
 * Nota de Entrega con el MISMO formulario (AlmSalidaNota, almacen_salida_nota.js) →
 * Previsualizar abre el PDF en #entDespPreviewModal → Editar vuelve a la nota con lo escrito;
 * Registrar hace en el servidor la entrada de todas las líneas y su salida con la nota, en una
 * transacción (almacen.recepcion.despacho), y descarga el PDF. Los modales están en
 * recepcion/partials/despacho_modales.
 *
 * window.RecepcionDespacho.montar(cfg) se llama en cada apertura de la pantalla (la SPA no
 * re-ejecuta un <script src> ya cargado). cfg:
 *   rutaDespacho, rutaPreview, notaSalida  — del Blade.
 *   almacen()   → id del almacén donde entra y del que sale el material.
 *   lineas()    → [{id_producto, cantidad, destino?}] capturadas (destino: el de cada
 *                  línea en una nota horizontal; null si no aplica).
 *   alTerminar(resp) → vacía la captura de la pantalla tras despachar; resp es la respuesta
 *                  del servidor (numero_nota, numero_traspaso…).
 *   alCerrar()   → opcional: el usuario cerró la nota sin despachar (✕ / Cancelar / Escape).
 *                  La bandeja lo usa para volver a su captura, que queda debajo oculta.
 *
 * Escape lo atiende UN solo oyente (abajo), no uno por pantalla: la SPA no los quita al
 * salir, y con dos registrados un Escape se aplicaba dos veces (de la vista previa saltaba
 * directo a la captura) o actuaba sobre el #almSalidaModal de /admin/almacen, que tiene el
 * mismo id. El oyente solo obedece al último montaje y solo si sus modales siguen en la página.
 */
window.RecepcionDespacho = {
    actual: null,   // el último montaje (ver el oyente de Escape)
    montar: function (cfg) {
        'use strict';
        cfg = cfg || {};

        function el(id) { return document.getElementById(id); }
        // Delega en window.toast (dom_helpers.js); aquí solo el respaldo de esta pantalla.
        function toast(msg, type) { if (!window.toast(msg, type || 'success') && type === 'error') alert(msg); }
        function abierto(id) { var o = el(id); return !!o && o.classList.contains('open'); }
        function err(msg) { var e = el('entDespError'); if (e) { e.textContent = msg || ''; e.style.display = msg ? 'block' : 'none'; } }

        // La nota se deja en blanco (AlmSalidaNota.abrir) solo la PRIMERA vez y después de
        // despachar o vaciar la captura: cerrar la ventana para corregir una línea y volver a
        // abrirla conserva lo escrito.
        var porLimpiar = true;
        // Lo que se previsualizó: Registrar manda EXACTAMENTE eso, así lo registrado es lo que
        // se vio. Se arma de nuevo en cada Previsualizar.
        var draft = null;
        var blobUrl = null;

        // Cierra la vista previa y suelta su PDF (blob) para no acumular memoria.
        function previewCerrar() {
            var m = el('entDespPreviewModal'); if (m) m.classList.remove('open');
            if (blobUrl) { try { URL.revokeObjectURL(blobUrl); } catch (e) {} blobUrl = null; }
            var frame = el('entDespPreviewFrame'); if (frame) frame.src = 'about:blank';
            var cont = el('entDespPreviewCanvas'); if (cont) { cont.innerHTML = ''; cont.style.display = 'none'; }
        }

        function ocultarNota() { var o = el('almSalidaModal'); if (o) o.classList.remove('open'); }
        // ✕ / Cancelar / Escape: solo cierra; lo escrito y lo capturado se conservan.
        window.entCerrarDespacho = function () {
            ocultarNota();
            if (cfg.alCerrar) cfg.alCerrar();
        };
        // Editar / ✕ / Escape de la vista previa: vuelve a la nota con todo lo escrito.
        window.entDespPreviewEditar = function () {
            previewCerrar();
            var o = el('almSalidaModal'); if (o) o.classList.add('open');
        };

        // Previsualizar: el PDF de la nota tal como va a salir, sin registrar nada.
        window.entDespVistaPrevia = function () {
            err('');
            var nota = window.AlmSalidaNota.datos();
            if (nota.error) { err(nota.error); toast(nota.error, 'error'); return; }
            var payload = Object.assign({
                id_almacen: parseInt(cfg.almacen(), 10),
                lineas:     cfg.lineas(),
            }, nota.datos);

            if (window.showPreloader) window.showPreloader();
            window.apiFetch(cfg.rutaPreview, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json', 'Accept': 'application/pdf, application/json' },
                body: JSON.stringify(payload)
            })
            .then(function (r) {
                // PDF en éxito; JSON con {message, errors} en error (o algo que no es JSON: un 500
                // o la sesión vencida → el mensaje genérico, no el error del parser).
                if (!r.ok) {
                    return r.json().catch(function () { return {}; }).then(function (b) {
                        var msg = (b && b.message) || 'No se pudo generar la vista previa.';
                        if (b && b.errors) msg = Object.values(b.errors).map(function (a) { return a.join(' '); }).join(' ');
                        var e = new Error(msg);
                        // 403 = falta la clave 'almacen.movimiento': solo el toast, como al registrar.
                        e.soloToast = r.status === 403 || !!(b && b.forbidden);
                        throw e;
                    });
                }
                if ((r.headers.get('Content-Type') || '').indexOf('application/pdf') === -1) {
                    throw new Error('Respuesta inesperada del servidor (no es PDF).');
                }
                return r.blob();
            })
            .then(function (blob) {
                if (window.hidePreloader) window.hidePreloader();
                draft = payload;
                previewCerrar();   // suelta el PDF de una vista previa anterior
                blobUrl = URL.createObjectURL(blob);
                // La nota se oculta (sin perder lo escrito) y la vista previa se abre ANTES de
                // pintar, para que el canvas del teléfono mida bien el ancho.
                ocultarNota();
                var m = el('entDespPreviewModal'); if (m) m.classList.add('open');
                window.mostrarPdfPrevia(el('entDespPreviewFrame'), el('entDespPreviewCanvas'), blob, blobUrl);   // dom_helpers.js
            })
            .catch(function (e) {
                if (window.hidePreloader) window.hidePreloader();
                // Sin red el fetch falla con TypeError («Failed to fetch»): la Recepción y el
                // despacho necesitan conexión (no hay bandeja para ellos), y se dice así.
                // Solo el TypeError de la RED (Chrome «Failed to fetch», Firefox «NetworkError», Safari «Load failed»), no
                // cualquiera: un fallo del código también es TypeError.
                var deRed = e instanceof TypeError && /fetch|network|load failed/i.test(String(e.message || ''));
                var msg = (deRed || (window.Bandeja && window.Bandeja.sinConexion()))
                    ? 'Registrar y despachar necesita internet: vuelve a intentarlo con conexión.'
                    : ((e && e.message) || 'Error generando la vista previa.');
                if (!(e && e.soloToast)) err(msg);
                toast(msg, 'error');
            });
        };

        // Registrar (de la vista previa): la entrada y la salida con la nota previsualizada.
        window.entDespachar = function () {
            if (!draft) { window.entDespPreviewEditar(); return; }
            var btn = el('entDespSubmit');
            if (btn && btn.disabled) return;   // anti doble envío: duplicaría la entrada y la nota
            var payload = draft;

            if (window.showPreloader) window.showPreloader();
            if (btn) btn.disabled = true;
            window.apiFetch(cfg.rutaDespacho, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, status: r.status, b: b }; }); })
            .then(function (res) {
                if (window.hidePreloader) window.hidePreloader();
                if (btn) btn.disabled = false;
                if (res.ok) {
                    // El departamento se recuerda AQUÍ, con la nota ya registrada (igual que en la
                    // salida de /admin/almacen).
                    window.AlmSalidaNota.recordarDepto(payload.departamento);
                    previewCerrar();
                    api.reiniciar();
                    cfg.alTerminar(res.b || {});
                    toast((res.b && res.b.message) || 'Entrada registrada y despachada.', 'success');
                    // Las notas, descargadas como archivo (dom_helpers.js), igual que la salida de /admin/almacen.
                    window.descargarNotas(res.b);
                    return;
                }
                // No se registró (algo cambió desde la vista previa): de vuelta a la nota con el motivo.
                var msg = (res.b && res.b.message) || 'No se pudo registrar y despachar.';
                if (res.b && res.b.errors) msg = Object.values(res.b.errors).map(function (a) { return a.join(' '); }).join(' ');
                window.entDespPreviewEditar();
                // 403 = falta la clave 'almacen.movimiento': solo el toast, como en la entrada.
                if (!(res.status === 403 || (res.b && res.b.forbidden))) err(msg);
                toast(msg, 'error');
            })
            .catch(function () {
                if (window.hidePreloader) window.hidePreloader();
                if (btn) btn.disabled = false;
                var m = 'Error de red al registrar y despachar.';
                window.entDespPreviewEditar();
                err(m); toast(m, 'error');
            });
        };

        var api = {
            /** Abre la Nota de Entrega. Quien llama ya comprobó que hay almacén y líneas. */
            abrir: function () {
                window.AlmSalidaNota.configurar(cfg.notaSalida || {});
                if (porLimpiar) {
                    window.AlmSalidaNota.abrir(cfg.almacen());
                    porLimpiar = false;
                }
                err('');
                var o = el('almSalidaModal'); if (o) o.classList.add('open');
            },
            /** La próxima apertura arranca con la nota en blanco (tras despachar o vaciar la captura). */
            reiniciar: function () {
                porLimpiar = true;
                draft = null;
            },
        };

        // Escape: la vista previa vuelve a la nota; en la nota, una lista abierta (proyecto,
        // contrato, vehículo o chofer) se cierra sola antes que la ventana. Todo sale con
        // preventDefault + stopPropagation: el Escape de /admin/almacen, si ese módulo se abrió
        // antes en la pestaña, cerraría todos los modales de golpe sin devolver la captura.
        var preview = el('entDespPreviewModal');
        window.RecepcionDespacho.actual = {
            vigente: function () { return !!preview && preview.isConnected; },
            escape: function (e) {
                if (abierto('entDespPreviewModal')) { e.preventDefault(); e.stopPropagation(); window.entDespPreviewEditar(); return; }
                if (!abierto('almSalidaModal')) return;
                e.preventDefault(); e.stopPropagation();
                var log = document.querySelector('#almSalidaVehiculosSug.open, #almSalidaChoferesSug.open');
                if (log) { log.classList.remove('open'); return; }
                var dd = document.querySelector('#almSalidaModal .custom-dropdown.active');
                if (dd) { dd.classList.remove('active'); return; }
                window.entCerrarDespacho();
            },
        };
        return api;
    },
};

// En fase de CAPTURA y cortando el evento: el Escape de /admin/almacen (almacen_index.js)
// sigue escuchando tras pasar por ese módulo y cerraría TODOS los .alm-modal-overlay de golpe.
document.addEventListener('keydown', function (e) {
    var a = window.RecepcionDespacho.actual;
    if (e.key === 'Escape' && a && a.vigente()) a.escape(e);
}, true);
