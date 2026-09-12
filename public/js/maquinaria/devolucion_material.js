/**
 * window.DevolucionMaterial — modal "Devolución de material"
 * (resources/views/admin/almacen/partials/devolucion_modal.blade.php).
 *
 * Flujo: se busca la Nota de Entrega → se muestran sus productos con lo entregado y lo
 * que falta por devolver → el usuario pone cuánto vuelve de cada uno y, si se entrega
 * otro a cambio (otra talla), lo elige al lado → POST. Si hubo cambio, se abre la Nota
 * nueva para firmarla. Las reglas (no devolver de más, fecha, stock del cambio) las
 * decide el servidor (App\Services\DevolucionService); aquí solo se guía.
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
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
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
    function abrir(numero) {
        var m = modal(); if (!m) return;
        estado.nota = null;
        $('devMatNumero').value = numero || '';
        $('devMatContenido').hidden = true;
        $('devMatGuardar').disabled = true;
        cerrarSugerenciasNota();
        mensaje('');
        m.classList.add('open');
        document.body.style.overflow = 'hidden';
        cargarProductos();
        if (numero) buscar();
        else setTimeout(function () { var i = $('devMatNumero'); if (i) i.focus(); }, 60);
    }

    function cerrar() {
        var m = modal(); if (!m) return;
        m.classList.remove('open');
        document.body.style.overflow = '';
    }

    // ── Buscar la nota ───────────────────────────────────────────────────────
    function buscar() {
        var numero = String($('devMatNumero').value || '').trim().toUpperCase();
        cerrarSugerenciasNota();
        if (!numero) { mensaje('Escribe el N° de la Nota de Entrega.'); return; }
        mensaje('Buscando la nota ' + esc(numero) + '…', 'info');
        $('devMatContenido').hidden = true;
        $('devMatGuardar').disabled = true;

        w.apiFetch(modal().dataset.urlShow + '?numero=' + encodeURIComponent(numero), {
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

    function pintar(nota) {
        estado.nota = nota;
        $('devMatNumero').value = nota.numero;

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
            var sub = 'Entregado ' + num(l.entregado) + ' ' + esc(l.um || '')
                + (l.devuelto > EPS ? ' · ya devuelto ' + num(l.devuelto) : '');
            return '<div class="devm-linea' + (cerrada ? ' cerrada' : '') + '" data-i="' + i + '">'
                + '<div><div class="devm-prod">' + esc(l.nombre) + '</div>'
                +   '<div class="devm-prod-sub">' + (l.codigo ? esc(l.codigo) + ' · ' : '') + sub + '</div></div>'
                + (cerrada
                    ? '<div class="devm-prod-sub devm-linea-fin">Ya se devolvió todo.</div>'
                    : '<div><div class="devm-mini"><span>Devuelve</span><button type="button" data-dev-todo="' + i + '">Todo</button></div>'
                    +   '<div class="devm-cant"><input type="text" inputmode="decimal" class="devm-input" data-dev-cant="' + i + '" placeholder="0" autocomplete="off">'
                    +   '<span>de ' + num(l.pendiente) + '</span></div></div>'
                    + '<div class="devm-cambio" data-dev-cambio="' + i + '"><div class="devm-mini"><span>A cambio <span class="devm-opc">(opcional)</span></span></div>'
                    +   pintarCambio(i, null) + '</div>')
                + '</div>';
        }).join('');

        // Fecha: hoy por defecto; ni antes de la nota ni futura (el servidor lo vuelve a exigir).
        var f = $('devMatFecha');
        f.min = nota.fecha_min || '';
        f.max = nota.hoy || '';
        f.value = nota.hoy || '';
        $('devMatMotivo').value = '';

        var h = $('devMatHistorial');
        if (nota.historial && nota.historial.length) {
            h.innerHTML = '<b>Devoluciones anteriores de esta nota</b><ul>' + nota.historial.map(function (d) {
                return '<li>' + esc(d.fecha) + ' · ' + num(d.cantidad) + ' ' + esc(d.um || '') + ' de ' + esc(d.producto)
                    + (d.motivo ? ' — ' + esc(d.motivo) : '') + (d.usuario ? ' <span class="devm-quien">(' + esc(d.usuario) + ')</span>' : '') + '</li>';
            }).join('') + '</ul>';
            h.hidden = false;
        } else {
            h.hidden = true; h.innerHTML = '';
        }

        $('devMatContenido').hidden = false;
        if (!nota.lineas.some(function (l) { return l.pendiente > EPS; })) {
            mensaje('Todo lo entregado con esta nota ya se devolvió.', 'info');
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
        // La bitácora ya descarga el catálogo para su buscador: si está, se reutiliza.
        if (Array.isArray(w.almMovProductosLista) && w.almMovProductosLista.length) {
            estado.productos = w.almMovProductosLista;
            return Promise.resolve(estado.productos);
        }
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
        // talla, las otras tallas salen solas arriba.
        var lista = w.ProductoSuggest.rankear(estado.productos, termino || linea.nombre)
            .filter(function (p) { return Number(p.ID_PRODUCTO) !== Number(linea.id_producto); })
            .slice(0, MAX_SUGERENCIAS);

        caja.innerHTML = lista.length
            ? lista.map(function (p) {
                return '<div class="devm-sug-item" data-dev-elegir="' + i + '" data-id="' + p.ID_PRODUCTO + '">'
                    + esc(p.NOMBRE) + '<small>' + esc(p.UM || '') + (p.CODIGO ? ' · ' + esc(p.CODIGO) : '') + '</small></div>';
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
        var lineas = lineasAEnviar();
        if (!lineas.length) { mensaje('Indica cuánto se devuelve de al menos un producto.'); return; }

        // Aviso temprano de lo más común; el servidor lo vuelve a comprobar con la nota bloqueada.
        for (var k = 0; k < lineas.length; k++) {
            var l = estado.nota.lineas.find(function (x) { return x.id_producto === lineas[k].id_producto; });
            if (l && lineas[k].cantidad > l.pendiente + EPS) {
                mensaje('De «' + esc(l.nombre) + '» quedan ' + num(l.pendiente) + ' ' + esc(l.um || '') + ' por devolver.');
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
                fecha: $('devMatFecha').value || null,
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
                if ($('almMovTableBody') && typeof w.loadMovimientos === 'function') w.loadMovimientos();
                if ($('almNotTableBody') && typeof w.loadNotas === 'function') w.loadNotas();
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

    // ── Sugerencias de N° de nota (la bitácora publica la lista en almMovNotasFiltro) ──
    function sugerirNota() {
        var input = $('devMatNumero'), caja = $('devMatSug');
        var lista = w.almMovNotasFiltro || [];
        var q = String(input.value || '').trim().toUpperCase();
        if (!q || !lista.length) { cerrarSugerenciasNota(); return; }
        var hallados = lista.filter(function (n) { return String(n).toUpperCase().indexOf(q) !== -1; }).slice(0, 6);
        if (!hallados.length) { cerrarSugerenciasNota(); return; }
        caja.innerHTML = hallados.map(function (n) {
            return '<div class="devm-sug-item" data-dev-nota="' + esc(n) + '">' + esc(n) + '</div>';
        }).join('');
        caja.classList.add('open');
    }
    function cerrarSugerenciasNota() { var c = $('devMatSug'); if (c) c.classList.remove('open'); }

    // ── Eventos (delegados en el documento: sobreviven al reemplazo SPA del HTML) ──
    document.addEventListener('click', function (e) {
        if (!modal() || !modal().classList.contains('open')) return;
        var t = e.target;
        var el;
        if ((el = t.closest('#devMatBuscarBtn'))) { buscar(); return; }
        if ((el = t.closest('#devMatGuardar'))) { guardar(); return; }
        if ((el = t.closest('[data-dev-nota]'))) { $('devMatNumero').value = el.getAttribute('data-dev-nota'); buscar(); return; }
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
        // Clic fuera de un buscador: se cierran sus sugerencias.
        if (!t.closest('.devm-buscar')) cerrarSugerenciasNota();
        document.querySelectorAll('#devMatModal .devm-cambio .devm-sug.open').forEach(function (c) {
            if (!c.parentNode.contains(t)) c.classList.remove('open');
        });
    });

    document.addEventListener('input', function (e) {
        var t = e.target;
        if (!t.closest || !t.closest('#devMatModal')) return;
        if (t.id === 'devMatNumero') { sugerirNota(); return; }
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
        if (e.key === 'Enter' && e.target.id === 'devMatNumero') { e.preventDefault(); buscar(); return; }
        if (e.key === 'Enter' && e.target.hasAttribute && e.target.hasAttribute('data-dev-buscar')) {
            e.preventDefault();
            var primero = e.target.parentNode.querySelector('[data-dev-elegir]');
            if (primero) elegirCambio(primero.getAttribute('data-dev-elegir'), primero.getAttribute('data-id'));
        }
    });

    w.DevolucionMaterial = { abrir: abrir, cerrar: cerrar };
})(window);
