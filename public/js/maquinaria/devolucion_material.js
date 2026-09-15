/**
 * window.DevolucionMaterial — modal "Devolución de material"
 * (resources/views/admin/almacen/partials/devolucion_modal.blade.php).
 *
 * Flujo: se abre desde una salida del Historial de Movimientos, con su Nota de Entrega y ese
 * producto (solo ese: el servidor filtra por id_producto) → se muestra lo entregado → el
 * usuario pone cuánto vuelve → POST, con la fecha de hoy.
 *
 * Solo DEVUELVE. Si además hay que entregar otra cosa (otra talla), eso es una salida normal
 * con su propia Nota y se hace desde el inventario (decisión del cliente, 14-09-2026). Las
 * reglas —no devolver más de lo que queda, a qué bolsa vuelve— las decide el servidor
 * (App\Services\DevolucionService); aquí solo se guía.
 *
 * Se carga bajo demanda (cargarScriptUnaVez) desde window.almAbrirDevolucion, así que
 * las pantallas que nunca devuelven nada no lo descargan. Los listeners van sobre el
 * DOCUMENTO y buscan el modal en cada evento: la SPA reemplaza el HTML al navegar y un
 * listener pegado al nodo viejo dejaría de funcionar.
 */
(function (w) {
    'use strict';
    if (w.DevolucionMaterial) return;

    var EPS = 0.0005;
    var estado = { nota: null, guardando: false };

    function $(id) { return document.getElementById(id); }
    function modal() { return $('devMatModal'); }
    var esc = w.escapeHtml;   // helper central (dom_helpers.js)
    // 5 → "5", 2.5 → "2,5", 1234 → "1.234": la misma presentación que el kardex.
    function num(n) {
        return (Math.round((Number(n) || 0) * 1000) / 1000).toLocaleString('es-VE', { maximumFractionDigits: 3 });
    }
    function leerNum(input) {
        var v = String(input && input.value || '').trim().replace(',', '.');
        var n = parseFloat(v);
        return isFinite(n) ? n : 0;
    }
    function campoCant(i) { return document.querySelector('[data-dev-cant="' + i + '"]'); }

    function mensaje(texto, tipo) {
        var m = $('devMatMsg'); if (!m) return;
        if (!texto) { m.hidden = true; m.innerHTML = ''; return; }
        m.className = 'devm-msg ' + (tipo || 'error');
        m.innerHTML = texto;      // el servidor manda texto propio (a veces con <br>)
        m.hidden = false;
    }

    // ── Abrir / cerrar ───────────────────────────────────────────────────────
    // idProducto: el del movimiento desde el que se abrió; la devolución es solo de ese producto.
    function abrir(numero, idProducto) {
        var m = modal(); if (!m) return;
        estado.nota = null;
        $('devMatContenido').hidden = true;
        $('devMatGuardar').disabled = true;
        mensaje('');
        m.classList.add('open');
        w.bloquearScrollFondo();
        cargarNota(numero, idProducto);
    }

    function cerrar() {
        var m = modal(); if (!m) return;
        m.classList.remove('open');
        w.restaurarScrollFondo();
    }

    // ── Cargar la nota ───────────────────────────────────────────────────────
    function cargarNota(numero, idProducto) {
        mensaje('Cargando la nota ' + esc(numero) + '…', 'info');

        w.apiFetch(modal().dataset.urlShow + '?numero=' + encodeURIComponent(numero) + (idProducto ? '&id_producto=' + encodeURIComponent(idProducto) : ''), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, d: d }; }); })
            .then(function (res) {
                if (!res.ok) { mensaje(esc(res.d.message || 'No se pudo cargar la nota.')); return; }
                mensaje('');
                pintar(res.d);
            })
            .catch(function () { mensaje('No se pudo contactar al servidor. La devolución necesita conexión.'); });
    }

    // ── Pintar ───────────────────────────────────────────────────────────────
    function pintar(nota) {
        estado.nota = nota;

        // De qué nota viene, en una línea: número · fecha · proyecto (· quién recibió). El title
        // lleva el texto entero por si el proyecto no cabe y se corta con "…".
        var sep = ' <span class="devm-sep">&middot;</span> ';
        var partes = [nota.fecha, nota.proyecto, nota.solicitante].filter(Boolean);
        $('devMatNota').title = [nota.numero].concat(partes).join(' · ');
        $('devMatNota').innerHTML = '<i class="material-icons">receipt_long</i>'
            + '<span class="devm-nota-txt"><b class="devm-nota-num">' + esc(nota.numero) + '</b>'
            + (partes.length ? sep + '<span class="devm-nota-sub">' + partes.map(esc).join(sep) + '</span>' : '')
            + '</span>';

        // Cada producto en su tarjeta: qué es y cuánto se entregó; debajo, cuánto vuelve. Lo que
        // falta no se muestra: si se escribe de más, el aviso al registrar dice cuánto queda.
        $('devMatLineas').innerHTML = nota.lineas.map(function (l, i) {
            var cerrada = l.pendiente <= EPS;
            var um = esc(l.um || '');
            var datos = 'Entregado <b>' + num(l.entregado) + ' ' + um + '</b>'
                + (l.devuelto > EPS ? sep + 'Ya devuelto <b>' + num(l.devuelto) + ' ' + um + '</b>' : '');
            return '<div class="devm-linea' + (cerrada ? ' cerrada' : '') + '" data-i="' + i + '">'
                + '<div class="devm-prod-cab">'
                +   '<div class="devm-foto"><i class="material-icons">inventory_2</i></div>'
                +   '<div class="devm-info">'
                +     '<span class="devm-titulo">' + (l.codigo ? '<span class="devm-codigo">' + esc(l.codigo) + '</span>' + sep : '')
                +       '<span class="devm-prod">' + esc(l.nombre) + '</span></span>'
                +     '<span class="devm-datos">' + datos + '</span>'
                +   '</div>'
                + '</div>'
                + (cerrada
                    ? '<div class="devm-cerrada"><i class="material-icons">check_circle</i>Ya se devolvió todo.</div>'
                    : '<div class="devm-devuelve">'
                    +   '<label class="devm-devuelve-lbl" for="devMatCant' + i + '">Cantidad a devolver</label>'
                    +   '<div class="devm-cant">'
                    +     '<input type="text" inputmode="decimal" class="devm-input" id="devMatCant' + i + '" data-dev-cant="' + i + '" placeholder="0" autocomplete="off"'
                    +       ' aria-label="Cantidad que se devuelve de ' + esc(l.nombre) + '">'
                    +     '<span class="devm-de">' + um + '</span>'
                    +   '</div>'
                    + '</div>')
                + '</div>';
        }).join('');

        $('devMatMotivo').value = '';

        var h = $('devMatHistorial');
        var unoSolo = nota.lineas.length === 1;   // el producto ya está arriba: no se repite
        if (nota.historial && nota.historial.length) {
            h.innerHTML = '<b>Devoluciones anteriores</b><ul>' + nota.historial.map(function (d) {
                return '<li>' + esc(d.fecha) + ' · ' + num(d.cantidad) + ' ' + esc(d.um || '') + (unoSolo ? '' : ' de ' + esc(d.producto))
                    + (d.motivo ? ' — ' + esc(d.motivo) : '') + (d.usuario ? ' (' + esc(d.usuario) + ')' : '') + '</li>';
            }).join('') + '</ul>';
            h.hidden = false;
        } else {
            h.hidden = true; h.innerHTML = '';
        }

        $('devMatContenido').hidden = false;
        if (!nota.lineas.some(function (l) { return l.pendiente > EPS; })) {
            mensaje(unoSolo ? 'Ya se devolvió todo lo que se entregó de este producto.' : 'Ya se devolvió todo lo que se entregó con esta nota.', 'info');
        }
        actualizarBoton();
        var primero = document.querySelector('#devMatLineas [data-dev-cant]');
        if (primero) primero.focus();
    }

    // ── Guardar ──────────────────────────────────────────────────────────────
    function lineasAEnviar() {
        if (!estado.nota) return [];
        var out = [];
        estado.nota.lineas.forEach(function (l, i) {
            var cant = leerNum(campoCant(i));
            if (cant > 0) out.push({ id_producto: l.id_producto, cantidad: cant });
        });
        return out;
    }

    function actualizarBoton() {
        var btn = $('devMatGuardar'); if (!btn || !estado.nota) return;
        btn.disabled = estado.guardando || lineasAEnviar().length === 0;
    }

    function guardar() {
        if (estado.guardando || !estado.nota) return;
        var lineas = lineasAEnviar();   // el botón solo se activa con alguna cantidad

        // Aviso temprano de lo más común; el servidor lo vuelve a comprobar con la nota bloqueada.
        for (var k = 0; k < lineas.length; k++) {
            var l = estado.nota.lineas.find(function (x) { return x.id_producto === lineas[k].id_producto; });
            if (l && lineas[k].cantidad > l.pendiente + EPS) {
                mensaje('<b>No puedes devolver más de lo entregado.</b><br>'
                    + 'Quedan <b>' + num(l.pendiente) + ' ' + esc(l.um || '') + '</b> por devolver de «' + esc(l.nombre) + '».');
                return;
            }
        }

        estado.guardando = true;
        var btn = $('devMatGuardar'); var html = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="material-icons" style="font-size:17px;vertical-align:-3px;animation:spin 1s linear infinite;">sync</i> Registrando…';
        mensaje('');

        w.apiFetch(modal().dataset.urlStore, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
            body: JSON.stringify({
                numero: estado.nota.numero,
                motivo: $('devMatMotivo').value.trim() || null,
                lineas: lineas,
            })
        })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, status: r.status, d: d }; }); })
            .then(function (res) {
                if (!res.ok) {
                    var errores = res.d.errors ? Object.values(res.d.errors).map(function (e) { return e[0]; }) : [];
                    mensaje(esc(errores[0] || res.d.message || ('Error del servidor (' + res.status + ').')));
                    return;
                }
                w.toast(res.d.message || 'Devolución registrada.', 'success');
                cerrar();
                if (typeof w.loadMovimientos === 'function') w.loadMovimientos();   // la fila deja de ofrecer "Devolver" si ya volvió todo
            })
            .catch(function () { mensaje('No se pudo contactar al servidor. La devolución necesita conexión.'); })
            .finally(function () {
                estado.guardando = false;
                btn.innerHTML = html;
                actualizarBoton();
            });
    }

    // ── Eventos (delegados en el documento: sobreviven al reemplazo SPA del HTML) ──
    document.addEventListener('click', function (e) {
        if (!modal() || !modal().classList.contains('open')) return;
        if (e.target.closest('#devMatGuardar')) guardar();
    });

    document.addEventListener('input', function (e) {
        if (e.target.hasAttribute && e.target.hasAttribute('data-dev-cant') && e.target.closest('#devMatModal')) actualizarBoton();
    });

    document.addEventListener('keydown', function (e) {
        if (!modal() || !modal().classList.contains('open')) return;
        if (e.key === 'Escape') { cerrar(); return; }
        // Enter en la cantidad: registra, como el Enter de cualquier formulario corto.
        if (e.key === 'Enter' && e.target.hasAttribute && e.target.hasAttribute('data-dev-cant')) {
            e.preventDefault();
            if (!$('devMatGuardar').disabled) guardar();
        }
    });

    w.DevolucionMaterial = { abrir: abrir, cerrar: cerrar };
})(window);
