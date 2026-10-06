/**
 * window.AjusteNota — modal «Modificar» de una línea de Nota de Entrega y comparación de una
 * nota corregida (resources/views/admin/almacen/partials/ajuste_nota_modal.blade.php).
 *
 * Flujo: botón «Modificar» de una salida con nota del Historial → GET con el producto en la
 * nota y qué se puede hacer → el usuario elige QUÉ PASÓ:
 *   · Devolución: el material volvió. Pone cuánto vuelve → POST devolución; la nota no cambia.
 *   · Corrección: la nota se cargó mal. Pone cuánto salió de verdad y el motivo → POST
 *     corrección → se abre la comparación original | corregida.
 * La marca «corregida» del Historial abre directamente la comparación.
 *
 * Las reglas —no devolver más de lo que queda, no corregir por debajo de lo devuelto, no subir
 * sin stock, quién puede qué— las decide el servidor (DevolucionService, CorreccionNotaService,
 * AjusteNotaController); aquí solo se guía.
 *
 * Se carga bajo demanda desde window.almModificarNota / almVerCorreccion. Los listeners van
 * sobre el DOCUMENTO y buscan el modal en cada evento: la SPA reemplaza el HTML al navegar.
 */
(function (w) {
    'use strict';
    if (w.AjusteNota) return;

    var EPS = 0.0005;
    // Lo que cambia según la operación elegida. Una sola tabla: el campo, el motivo y el botón
    // se pintan desde aquí y no hay dos ramas de código que se puedan desincronizar.
    var OPS = {
        devolucion: {
            cant: 'Cantidad que vuelve', motivo: 'Motivo (opcional)', motivoEj: 'Ej.: sobró en la obra',
            boton: '<i class="material-icons">assignment_return</i>Devolver', motivoObligatorio: false,
        },
        correccion: {
            cant: 'Cantidad que de verdad salió', motivo: 'Motivo (obligatorio)', motivoEj: 'Ej.: salieron 100, se cargó 180 por error',
            boton: '<i class="material-icons">edit_note</i>Corregir', motivoObligatorio: true,
        },
    };
    var estado = { nota: null, op: null, guardando: false };

    function $(id) { return document.getElementById(id); }
    function modal() { return $('ajNotaModal'); }
    var esc = w.escapeHtml;   // helper central (dom_helpers.js)
    // 5 → "5", 2.5 → "2,5", 1234 → "1.234": la misma presentación que el kardex.
    function num(n) {
        return (Math.round((Number(n) || 0) * 1000) / 1000).toLocaleString('es-VE', { maximumFractionDigits: 3 });
    }
    function cantidad() {
        var n = parseFloat(String($('ajNotaCant').value || '').trim().replace(',', '.'));
        return isFinite(n) ? n : 0;
    }

    function mensaje(texto, tipo) {
        var m = $('ajNotaMsg'); if (!m) return;
        if (!texto) { m.hidden = true; m.innerHTML = ''; return; }
        m.className = 'ajn-msg ' + (tipo || 'error');
        m.innerHTML = texto;      // el servidor manda texto propio
        m.hidden = false;
    }

    // GET/POST JSON con la respuesta y el estado juntos.
    function pedir(url, opciones) {
        return w.apiFetch(url, Object.assign({
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' }
        }, opciones || {}))
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, status: r.status, d: d }; }); });
    }
    function errorDe(res) {
        var errores = res.d.errors ? Object.values(res.d.errors).map(function (e) { return e[0]; }) : [];
        return errores[0] || res.d.message || ('Error del servidor (' + res.status + ').');
    }

    // ── Abrir / cerrar ───────────────────────────────────────────────────────
    function abrir(numero, idProducto) {
        var m = modal(); if (!m) return;
        estado.nota = null;
        estado.op = null;
        $('ajNotaContenido').hidden = true;
        $('ajNotaGuardar').disabled = true;
        $('ajNotaGuardar').innerHTML = OPS.devolucion.boton;
        mensaje('Cargando la nota ' + esc(numero) + '…', 'info');
        m.classList.add('open');
        w.bloquearScrollFondo();

        pedir(m.dataset.urlShow + '?numero=' + encodeURIComponent(numero) + '&id_producto=' + encodeURIComponent(idProducto))
            .then(function (res) {
                if (!res.ok) { mensaje(esc(res.d.message || 'No se pudo cargar la nota.')); return; }
                mensaje('');
                pintar(res.d);
            })
            .catch(function () { mensaje('No se pudo contactar al servidor. Modificar una nota necesita conexión.'); });
    }

    function cerrar() {
        var m = modal(); if (!m) return;
        m.classList.remove('open');
        w.restaurarScrollFondo();
    }

    // ── Pintar ───────────────────────────────────────────────────────────────
    function pintar(nota) {
        estado.nota = nota;
        var l = nota.linea;
        var um = esc(l.um || '');
        var sep = ' <span class="ajn-sep">&middot;</span> ';

        // De qué nota viene: número y fecha. El proyecto y quién recibió van en el title.
        $('ajNotaNota').title = [nota.numero, nota.fecha, nota.proyecto, nota.solicitante].filter(Boolean).join(' · ');
        $('ajNotaNota').innerHTML = '<i class="material-icons">receipt_long</i>'
            + '<span class="ajn-nota-txt"><b class="ajn-nota-num">' + esc(nota.numero) + '</b>'
            + (nota.fecha ? sep + esc(nota.fecha) : '') + '</span>';

        $('ajNotaProducto').innerHTML = '<div class="ajn-prod-cab">'
            +   '<div class="ajn-foto"><i class="material-icons">inventory_2</i></div>'
            +   '<div class="ajn-info">'
            +     '<span class="ajn-titulo">' + (l.codigo ? esc(l.codigo) + sep : '') + esc(l.nombre) + '</span>'
            +     '<span class="ajn-datos">Entregado <b>' + num(l.entregado) + ' ' + um + '</b>'
            +       (l.devuelto > EPS ? sep + 'Ya devuelto <b>' + num(l.devuelto) + ' ' + um + '</b>' : '') + '</span>'
            +   '</div>'
            + '</div>';
        $('ajNotaUm').textContent = l.um || '';

        // Cada opción, encendida o apagada con su porqué.
        var primera = null;
        document.querySelectorAll('#ajNotaModal .ajn-opcion').forEach(function (b) {
            var no = nota[b.dataset.op].motivo_no;
            var aviso = b.querySelector('.ajn-opcion-no');
            b.disabled = !!no;
            aviso.textContent = no || '';
            aviso.hidden = !no;
            if (!no && !primera) primera = b.dataset.op;
        });

        pintarHistorial(nota, um);
        $('ajNotaContenido').hidden = false;
        if (primera) {
            elegir(primera);
        } else {
            elegir(null);
            mensaje('Con este producto de la nota no se puede hacer nada desde aquí: cada opción dice por qué.', 'info');
        }
    }

    function pintarHistorial(nota, um) {
        var bloques = [];
        if (nota.devolucion.historial.length) {
            bloques.push('<b>Devoluciones anteriores</b><ul>' + nota.devolucion.historial.map(function (d) {
                return '<li>' + esc(d.fecha) + ' · ' + num(d.cantidad) + ' ' + um
                    + (d.motivo ? ' — ' + esc(d.motivo) : '') + (d.usuario ? ' (' + esc(d.usuario) + ')' : '') + '</li>';
            }).join('') + '</ul>');
        }
        if (nota.correccion.historial.length) {
            bloques.push('<b>Correcciones anteriores</b><ul>' + nota.correccion.historial.map(function (c) {
                return '<li>' + esc(c.fecha) + ' · de ' + num(c.antes) + ' a ' + num(c.despues) + ' ' + um
                    + (c.motivo ? ' — ' + esc(c.motivo) : '') + (c.usuario ? ' (' + esc(c.usuario) + ')' : '') + '</li>';
            }).join('') + '</ul>');
        }
        var h = $('ajNotaHistorial');
        h.innerHTML = bloques.join('');
        h.hidden = !bloques.length;
    }

    // Elige la operación: marca su tarjeta y rotula el campo, el motivo y el botón.
    function elegir(op) {
        estado.op = op;
        document.querySelectorAll('#ajNotaModal .ajn-opcion').forEach(function (b) {
            var activa = b.dataset.op === op;
            b.classList.toggle('activa', activa);
            b.setAttribute('aria-checked', activa ? 'true' : 'false');
        });
        $('ajNotaCampos').hidden = !op;
        if (!op) { actualizarBoton(); return; }

        var cfg = OPS[op];
        $('ajNotaCantLbl').textContent = cfg.cant;
        $('ajNotaMotivoLbl').textContent = cfg.motivo;
        $('ajNotaMotivo').placeholder = cfg.motivoEj;
        // Corregir parte de lo que dice la nota; devolver, de cero.
        $('ajNotaCant').placeholder = op === 'correccion' ? num(estado.nota.linea.entregado) : '0';
        $('ajNotaCant').value = '';
        $('ajNotaGuardar').innerHTML = cfg.boton;
        mensaje('');
        actualizarBoton();
        $('ajNotaCant').focus();
    }

    // ── Guardar ──────────────────────────────────────────────────────────────
    // El botón se enciende con una cantidad válida. El motivo obligatorio NO lo apaga: un botón
    // gris sin explicación deja al usuario sin saber qué falta; al pulsar, guardar() lo dice.
    function listo() {
        if (!estado.nota || !estado.op) return false;
        var cant = cantidad();
        if (cant <= EPS) return false;
        // Corregir a la misma cantidad que ya dice la nota no cambia nada.
        return estado.op !== 'correccion' || Math.abs(cant - estado.nota.linea.entregado) > EPS;
    }

    function actualizarBoton() {
        var btn = $('ajNotaGuardar'); if (!btn) return;
        btn.disabled = estado.guardando || !listo();
    }

    // Aviso temprano de lo más común; el servidor lo vuelve a comprobar con la nota bloqueada.
    function avisoPrevio(cant) {
        var l = estado.nota.linea, um = esc(l.um || '');
        if (estado.op === 'devolucion' && cant > l.pendiente + EPS) {
            return '<b>No puedes devolver más de lo entregado.</b><br>Quedan <b>' + num(l.pendiente) + ' ' + um + '</b> por devolver.';
        }
        if (estado.op === 'correccion' && cant < l.devuelto - EPS) {
            return '<b>No puede quedar en menos de lo ya devuelto.</b><br>De esta nota ya volvieron <b>' + num(l.devuelto) + ' ' + um + '</b>.';
        }
        return null;
    }

    function guardar() {
        if (estado.guardando || !listo()) return;
        var cant = cantidad();
        var aviso = avisoPrevio(cant);
        if (aviso) { mensaje(aviso); return; }
        if (OPS[estado.op].motivoObligatorio && $('ajNotaMotivo').value.trim() === '') {
            mensaje('<b>Escribe el motivo de la corrección.</b><br>Queda anotado en la nota original.');
            $('ajNotaMotivo').focus();
            return;
        }

        var m = modal(), nota = estado.nota, op = estado.op;
        var motivo = $('ajNotaMotivo').value.trim();
        var url, cuerpo;
        if (op === 'devolucion') {
            url = m.dataset.urlDevolver;
            cuerpo = { numero: nota.numero, motivo: motivo || null, lineas: [{ id_producto: nota.linea.id_producto, cantidad: cant }] };
        } else {
            url = m.dataset.urlCorregir;
            cuerpo = { numero: nota.numero, id_producto: nota.linea.id_producto, cantidad: cant, motivo: motivo };
        }

        estado.guardando = true;
        var btn = $('ajNotaGuardar'); var html = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="material-icons" style="font-size:17px;vertical-align:-3px;animation:spin 1s linear infinite;">sync</i> Guardando…';
        mensaje('');

        pedir(url, { method: 'POST', body: JSON.stringify(cuerpo) })
            .then(function (res) {
                if (!res.ok) { mensaje(esc(errorDe(res))); return; }
                w.toast(res.d.message || 'Listo.', 'success');
                cerrar();
                if (op === 'correccion') comparar(nota.numero);
                if (typeof w.loadMovimientos === 'function') w.loadMovimientos();   // la fila cambia de cantidad o de marca
            })
            .catch(function () { mensaje('No se pudo contactar al servidor. Modificar una nota necesita conexión.'); })
            .finally(function () {
                estado.guardando = false;
                btn.innerHTML = html;
                actualizarBoton();
            });
    }

    // ── Comparación: original (corrección en rojo) | corregida ─────────────────
    // En escritorio el PDF va en un <iframe>, NUEVO en cada apertura (el visor del navegador no
    // siempre repinta al cambiarle el src). En teléfono el navegador no dibuja un PDF dentro de
    // un <iframe>: se baja y se pinta en <canvas>, como el visor general.
    function mostrarDoc(cont, url) {
        cont.innerHTML = '';
        cont.classList.toggle('lienzo', w.pdfEsMovil());
        if (!w.pdfEsMovil()) {
            var f = document.createElement('iframe');
            f.title = 'Nota de Entrega';
            f.src = url;
            cont.appendChild(f);
            return;
        }
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
            .then(function (blob) { return w.pintarPdfEnCanvas(cont, blob); })
            .catch(function () {
                cont.innerHTML = '<div style="color:#fecaca;text-align:center;padding:30px;font-size:13px;">No se pudo mostrar la nota. Usa el botón Descargar.</div>';
            });
    }

    function comparar(numero) {
        var c = $('ajNotaComparar'); if (!c) return;
        var nueva = c.dataset.urlNota + '?numero=' + encodeURIComponent(numero);
        var original = nueva + '&version=original';
        $('ajNotaCompNum').textContent = 'Nota ' + numero + ' — original y corregida';
        $('ajNotaDescOriginal').href = original + '&descargar=1';
        $('ajNotaDescNueva').href = nueva + '&descargar=1';
        c.classList.add('open');
        w.bloquearScrollFondo();
        // Después de abrir: el lienzo del teléfono mide el ancho de su caja.
        mostrarDoc($('ajNotaDocOriginal'), original);
        mostrarDoc($('ajNotaDocNueva'), nueva);
    }

    function cerrarComparar() {
        var c = $('ajNotaComparar'); if (!c) return;
        c.classList.remove('open');
        // Sin documento detrás: un PDF oculto sigue ocupando memoria.
        $('ajNotaDocOriginal').innerHTML = '';
        $('ajNotaDocNueva').innerHTML = '';
        w.restaurarScrollFondo();
    }

    // ── Eventos (delegados en el documento: sobreviven al reemplazo SPA del HTML) ──
    document.addEventListener('click', function (e) {
        if (!modal() || !modal().classList.contains('open')) return;
        var opcion = e.target.closest('#ajNotaModal .ajn-opcion');
        if (opcion && !opcion.disabled) { elegir(opcion.dataset.op); return; }
        if (e.target.closest('#ajNotaGuardar')) guardar();
    });

    document.addEventListener('input', function (e) {
        if (e.target.closest && e.target.closest('#ajNotaModal')) actualizarBoton();
    });

    document.addEventListener('keydown', function (e) {
        var c = $('ajNotaComparar');
        if (c && c.classList.contains('open')) {
            if (e.key === 'Escape') cerrarComparar();
            return;
        }
        if (!modal() || !modal().classList.contains('open')) return;
        if (e.key === 'Escape') { cerrar(); return; }
        // Enter en un campo: guarda, como el Enter de cualquier formulario corto.
        if (e.key === 'Enter' && e.target.closest && e.target.closest('#ajNotaModal input')) {
            e.preventDefault();
            guardar();
        }
    });

    w.AjusteNota = { abrir: abrir, cerrar: cerrar, comparar: comparar, cerrarComparar: cerrarComparar };
})(window);
