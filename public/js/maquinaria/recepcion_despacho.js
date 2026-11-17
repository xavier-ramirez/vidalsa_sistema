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
 *   lineas()    → [{id_producto, cantidad}] capturadas.
 *   alTerminar() → vacía la captura de la pantalla tras despachar.
 *   alCerrar()   → opcional: el usuario cerró la nota sin despachar (✕ / Cancelar / Escape).
 *                  La bandeja lo usa para volver a su captura, que queda debajo oculta.
 */
window.RecepcionDespacho = {
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
                var msg = (e && e.message) || 'Error generando la vista previa.';
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
                    cfg.alTerminar();
                    toast((res.b && res.b.message) || 'Entrada registrada y despachada.', 'success');
                    // La nota, descargada como archivo (dom_helpers.js), igual que la salida de /admin/almacen.
                    if (res.b && res.b.nota_url) window.descargarPdf(res.b.nota_url, res.b.numero_nota ? ('Nota_' + res.b.numero_nota + '.pdf') : 'nota_entrega.pdf');
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
            /**
             * Escape de la pantalla: true si lo atendió un modal del despacho. La vista previa
             * vuelve a la nota; en la nota, una lista abierta (proyecto, contrato) se cierra
             * sola antes que la ventana. Las de vehículo/chofer (campos [data-log]) las cierra
             * almacen_salida_nota.js: a esas se las deja pasar.
             */
            escape: function (e) {
                if (abierto('entDespPreviewModal')) { e.stopPropagation(); window.entDespPreviewEditar(); return true; }
                if (!abierto('almSalidaModal')) return false;
                var dd = document.querySelector('#almSalidaModal .custom-dropdown.active');
                if (dd) { e.stopPropagation(); dd.classList.remove('active'); return true; }
                if (e.target && e.target.closest && e.target.closest('#almSalidaModal [data-log]')) return true;
                e.stopPropagation();
                window.entCerrarDespacho();
                return true;
            },
        };
        return api;
    },
};
