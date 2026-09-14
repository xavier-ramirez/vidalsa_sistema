/**
 * window.DevolucionMaterial — modal "Devolución de material"
 * (resources/views/admin/almacen/partials/devolucion_modal.blade.php).
 *
 * Flujo: se abre desde una salida del Historial de Movimientos, con su Nota de Entrega y ese
 * producto (solo ese: el servidor filtra por id_producto) → se muestra lo entregado y lo que
 * falta por devolver → el usuario pone cuánto vuelve y, si se entrega otro a cambio (otra
 * talla), lo elige al lado —con el stock que hay de cada opción— → POST, con la fecha de hoy.
 * Si hubo cambio, se abre la Nota nueva para firmarla. Las reglas (no devolver de más, stock
 * del cambio) las decide el servidor (App\Services\DevolucionService); aquí solo se guía.
 *
 * Se carga bajo demanda (cargarScriptUnaVez) desde window.almAbrirDevolucion, así que
 * las pantallas que nunca devuelven nada no lo descargan. Los listeners van sobre el
 * DOCUMENTO y buscan el modal en cada evento: la SPA reemplaza el HTML al navegar y un
 * listener pegado al nodo viejo dejaría de funcionar.
 */
(function (w) {
    'use strict';
    if (w.DevolucionMaterial) return;

    var MAX_SUGERENCIAS = 8;
    var EPS = 0.0005;
    var estado = { nota: null, productos: null, productosCargando: null, guardando: false };

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
        cargarProductos();
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

    // Stock del producto en el almacén de la nota (el servidor manda solo los que tienen).
    function stockDe(idProducto) {
        return Number((estado.nota.saldos || {})[idProducto]) || 0;
    }

    function pintar(nota) {
        estado.nota = nota;

        var info = [
            '<span>Nota <b>' + esc(nota.numero) + '</b></span>',
            '<span>' + esc(nota.fecha || '') + '</span>',
            nota.proyecto ? '<span>Proyecto <b>' + esc(nota.proyecto) + '</b></span>' : '',
            nota.solicitante ? '<span>Recibió <b>' + esc(nota.solicitante) + '</b></span>' : '',
            '<a href="' + esc(nota.pdf_url) + '" data-dev-pdf="1"><i class="material-icons">description</i>Ver nota</a>',
        ];
        $('devMatNota').innerHTML = info.join('');

        $('devMatLineas').innerHTML = nota.lineas.map(function (l, i) {
            var cerrada = l.pendiente <= EPS;
            var um = esc(l.um || '');
            return '<div class="devm-linea' + (cerrada ? ' cerrada' : '') + '" data-i="' + i + '">'
                + '<div class="devm-prod-bloque">'
                +   '<div class="devm-prod-cab">' + (l.codigo ? '<span class="devm-cod">' + esc(l.codigo) + '</span>' : '')
                +     '<span class="devm-prod">' + esc(l.nombre) + '</span></div>'
                +   '<div class="devm-datos"><span>Entregado <b>' + num(l.entregado) + ' ' + um + '</b></span>'
                +     (l.devuelto > EPS ? '<span>Ya devuelto <b>' + num(l.devuelto) + ' ' + um + '</b></span>' : '') + '</div>'
                + '</div>'
                + (cerrada
                    ? '<div class="devm-prod-sub devm-linea-fin">Ya se devolvió todo.</div>'
                    : '<div><div class="devm-mini"><span>Devuelve</span><button type="button" data-dev-todo="' + i + '">Todo</button></div>'
                    +   '<div class="devm-cant"><input type="text" inputmode="decimal" class="devm-input" data-dev-cant="' + i + '" placeholder="0" autocomplete="off">'
                    +   '<span>de ' + num(l.pendiente) + '</span></div></div>'
                    + '<div class="devm-cambio" data-dev-cambio="' + i + '"><div class="devm-mini"><span>A cambio <span class="devm-opc">(opcional)</span></span></div>'
                    +   pintarCambio(i, null) + '</div>')
                + '</div>';
        }).join('');

        $('devMatMotivo').value = '';

        var h = $('devMatHistorial');
        var unoSolo = nota.lineas.length === 1;   // el producto ya está arriba: no se repite
        if (nota.historial && nota.historial.length) {
            h.innerHTML = '<b>Devoluciones anteriores</b><ul>' + nota.historial.map(function (d) {
                return '<li>' + esc(d.fecha) + ' · ' + num(d.cantidad) + ' ' + esc(d.um || '') + (unoSolo ? '' : ' de ' + esc(d.producto))
                    + (d.motivo ? ' — ' + esc(d.motivo) : '') + (d.usuario ? ' <span class="devm-quien">(' + esc(d.usuario) + ')</span>' : '') + '</li>';
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

    // ── Producto a cambio ────────────────────────────────────────────────────
    // Sin elegir: un buscador. Elegido: el nombre con una X y la cantidad a entregar.
    function pintarCambio(i, prod, cantidad) {
        if (!prod) {
            return '<input type="text" class="devm-input" data-dev-buscar="' + i + '" placeholder="Buscar producto…" autocomplete="off">'
                + '<div class="devm-sug" data-dev-sug="' + i + '"></div>';
        }
        return '<div class="devm-elegido">'
            + '<div class="devm-chip" title="' + esc(prod.NOMBRE) + '"><span>' + esc(prod.NOMBRE) + (prod.UM ? ' (' + esc(prod.UM) + ')' : '') + '</span>'
            +   '<button type="button" data-dev-quitar="' + i + '" aria-label="Quitar el producto a cambio"><i class="material-icons">close</i></button></div>'
            + '<input type="text" inputmode="decimal" class="devm-input" data-dev-cantcambio="' + i + '" value="' + esc(cantidad || '') + '" placeholder="Cant." title="Cantidad a entregar a cambio" autocomplete="off">'
            + '</div>';
    }

    function cargarProductos() {
        if (estado.productos) return Promise.resolve(estado.productos);
        // Un fallo NO se guarda: resuelve null y la próxima búsqueda vuelve a pedirlo. Guardar
        // una lista vacía la dejaba así hasta recargar la página (el módulo sobrevive a la SPA).
        if (!estado.productosCargando) {
            estado.productosCargando = w.apiFetch(modal().dataset.urlProductos, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(function (lista) { estado.productos = Array.isArray(lista) ? lista : []; return estado.productos; })
                .catch(function () { return null; })
                .finally(function () { estado.productosCargando = null; });
        }
        return estado.productosCargando;
    }

    function sugerirCambio(i) {
        var input = document.querySelector('[data-dev-buscar="' + i + '"]');
        var caja = document.querySelector('[data-dev-sug="' + i + '"]');
        if (!input || !caja || !estado.nota) return;
        var linea = estado.nota.lineas[i];
        var termino = input.value.trim();

        if (!estado.productos) {
            caja.innerHTML = '<div class="devm-sug-vacio">Cargando productos…</div>';
            caja.classList.add('open');
            cargarProductos().then(function (lista) {
                if (document.activeElement !== input) return;
                if (lista) { sugerirCambio(i); return; }
                // Sin reintentar aquí (sería un bucle con el servidor caído): al volver a
                // escribir o a entrar al campo se pide otra vez.
                caja.innerHTML = '<div class="devm-sug-vacio">No se pudieron cargar los productos. Escribe de nuevo para reintentar.</div>';
            });
            return;
        }
        // Sin escribir nada se sugieren los PARECIDOS al que se devuelve: para cambiar la
        // talla, las otras tallas salen solas arriba. Los que no tienen stock en el almacén
        // de la nota van al final y no se pueden elegir: no habría qué entregar.
        var lista = w.ProductoSuggest.rankear(estado.productos, termino || linea.nombre)
            .filter(function (p) { return Number(p.ID_PRODUCTO) !== Number(linea.id_producto); })
            .slice(0, MAX_SUGERENCIAS);
        lista = lista.filter(function (p) { return stockDe(p.ID_PRODUCTO) > EPS; })
            .concat(lista.filter(function (p) { return stockDe(p.ID_PRODUCTO) <= EPS; }));

        caja.innerHTML = lista.length
            ? lista.map(function (p) {
                var stock = stockDe(p.ID_PRODUCTO);
                var hay = stock > EPS;
                return '<div class="devm-sug-item' + (hay ? '' : ' sin-stock') + '"' + (hay ? ' data-dev-elegir="' + i + '" data-id="' + p.ID_PRODUCTO + '"' : '') + '>'
                    + esc(p.NOMBRE) + '<small>' + esc(p.UM || '') + (p.CODIGO ? ' · ' + esc(p.CODIGO) : '') + '</small>'
                    + '<span class="devm-sug-stock">' + (hay ? 'Stock: ' + num(stock) + ' ' + esc(p.UM || '') : 'Sin stock en ' + esc(estado.nota.almacen || 'este almacén')) + '</span></div>';
            }).join('')
            : '<div class="devm-sug-vacio">Sin coincidencias.</div>';
        caja.classList.add('open');
    }

    function elegirCambio(i, idProducto) {
        var prod = (estado.productos || []).find(function (p) { return Number(p.ID_PRODUCTO) === Number(idProducto); });
        var cont = document.querySelector('[data-dev-cambio="' + i + '"]');
        if (!prod || !cont) return;
        estado.nota.lineas[i].cambio = prod;
        var cant = leerNum(document.querySelector('[data-dev-cant="' + i + '"]'));
        cont.innerHTML = cont.querySelector('.devm-mini').outerHTML + pintarCambio(i, prod, cant > 0 ? String(cant).replace('.', ',') : '');
        var c = cont.querySelector('[data-dev-cantcambio]'); if (c) c.focus();
        actualizarBoton();
    }

    function quitarCambio(i) {
        var cont = document.querySelector('[data-dev-cambio="' + i + '"]');
        if (!cont) return;
        delete estado.nota.lineas[i].cambio;
        cont.innerHTML = cont.querySelector('.devm-mini').outerHTML + pintarCambio(i, null);
        actualizarBoton();
    }

    // ── Guardar ──────────────────────────────────────────────────────────────
    function lineasAEnviar() {
        if (!estado.nota) return [];
        var out = [];
        estado.nota.lineas.forEach(function (l, i) {
            var cant = leerNum(document.querySelector('[data-dev-cant="' + i + '"]'));
            if (cant <= 0) return;
            var linea = { id_producto: l.id_producto, cantidad: cant };
            if (l.cambio) {
                linea.id_producto_cambio = l.cambio.ID_PRODUCTO;
                var cc = leerNum(document.querySelector('[data-dev-cantcambio="' + i + '"]'));
                if (cc > 0) linea.cantidad_cambio = cc;
            }
            out.push(linea);
        });
        return out;
    }

    function actualizarBoton() {
        var btn = $('devMatGuardar'); if (!btn || !estado.nota) return;
        document.querySelectorAll('#devMatLineas .devm-linea').forEach(function (fila) {
            var i = fila.getAttribute('data-i');
            fila.classList.toggle('activa', leerNum(fila.querySelector('[data-dev-cant="' + i + '"]')) > 0);
        });
        btn.disabled = estado.guardando || lineasAEnviar().length === 0;
    }

    function guardar() {
        if (estado.guardando || !estado.nota) return;
        var lineas = lineasAEnviar();   // el botón solo se activa con alguna cantidad

        // Aviso temprano de lo más común; el servidor lo vuelve a comprobar con la nota bloqueada.
        for (var k = 0; k < lineas.length; k++) {
            var l = estado.nota.lineas.find(function (x) { return x.id_producto === lineas[k].id_producto; });
            if (l && lineas[k].cantidad > l.pendiente + EPS) {
                mensaje('De «' + esc(l.nombre) + '» quedan ' + num(l.pendiente) + ' ' + esc(l.um || '') + ' por devolver.');
                return;
            }
            var cambio = l && l.cambio;
            var aEntregar = lineas[k].cantidad_cambio || lineas[k].cantidad;
            if (cambio && aEntregar > stockDe(cambio.ID_PRODUCTO) + EPS) {
                mensaje('De «' + esc(cambio.NOMBRE) + '» hay ' + num(stockDe(cambio.ID_PRODUCTO)) + ' ' + esc(cambio.UM || '')
                    + ' en ' + esc(estado.nota.almacen || 'este almacén') + ': no alcanza para entregar ' + num(aEntregar) + ' a cambio.');
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
                w.loadMovimientos();   // la fila deja de ofrecer "Devolver" si ya volvió todo
                // Lo entregado a cambio salió con una nota nueva: se abre para firmarla.
                if (res.d.nota_url && typeof w.openPdfPreview === 'function') {
                    w.openPdfPreview(res.d.nota_url, 'nota_entrega', 'Nota ' + res.d.numero_nota, 0, '', true, 'almacen');
                }
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
        var t = e.target;
        var el;
        if ((el = t.closest('#devMatGuardar'))) { guardar(); return; }
        if ((el = t.closest('[data-dev-todo]'))) {
            var i = el.getAttribute('data-dev-todo');
            var inp = document.querySelector('[data-dev-cant="' + i + '"]');
            if (inp) { inp.value = String(estado.nota.lineas[i].pendiente).replace('.', ','); actualizarBoton(); }
            return;
        }
        if ((el = t.closest('[data-dev-elegir]'))) { elegirCambio(el.getAttribute('data-dev-elegir'), el.getAttribute('data-id')); return; }
        if ((el = t.closest('[data-dev-quitar]'))) { quitarCambio(el.getAttribute('data-dev-quitar')); return; }
        if ((el = t.closest('[data-dev-pdf]'))) {
            if (typeof w.openPdfPreview === 'function') {
                e.preventDefault();
                w.openPdfPreview(el.getAttribute('href'), 'nota_entrega', 'Nota ' + estado.nota.numero, 0, '', true, 'almacen');
            }
            return;
        }
        // Clic fuera de un buscador de producto: se cierran sus sugerencias.
        document.querySelectorAll('#devMatModal .devm-cambio .devm-sug.open').forEach(function (c) {
            if (!c.parentNode.contains(t)) c.classList.remove('open');
        });
    });

    document.addEventListener('input', function (e) {
        var t = e.target;
        if (!t.closest || !t.closest('#devMatModal')) return;
        if (t.hasAttribute('data-dev-buscar')) { sugerirCambio(t.getAttribute('data-dev-buscar')); return; }
        if (t.hasAttribute('data-dev-cant') || t.hasAttribute('data-dev-cantcambio')) actualizarBoton();
    });

    document.addEventListener('focusin', function (e) {
        var t = e.target;
        if (t.hasAttribute && t.hasAttribute('data-dev-buscar') && t.closest('#devMatModal')) {
            // Un desplegable a la vez: abrir este cierra el de las otras líneas.
            document.querySelectorAll('#devMatModal .devm-sug.open').forEach(function (c) { c.classList.remove('open'); });
            sugerirCambio(t.getAttribute('data-dev-buscar'));
        }
    });

    document.addEventListener('keydown', function (e) {
        if (!modal() || !modal().classList.contains('open')) return;
        if (e.key === 'Escape') {
            var abierta = document.querySelector('#devMatModal .devm-sug.open');
            if (abierta) abierta.classList.remove('open'); else cerrar();
            return;
        }
        if (e.key === 'Enter' && e.target.hasAttribute && e.target.hasAttribute('data-dev-buscar')) {
            e.preventDefault();
            var primero = e.target.parentNode.querySelector('[data-dev-elegir]');
            if (primero) elegirCambio(primero.getAttribute('data-dev-elegir'), primero.getAttribute('data-id'));
        }
    });

    w.DevolucionMaterial = { abrir: abrir, cerrar: cerrar };
})(window);
