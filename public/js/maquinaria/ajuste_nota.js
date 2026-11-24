/**
 * window.AjusteNota — modal «Modificar» de una línea de Nota de Entrega
 * (resources/views/admin/almacen/partials/ajuste_nota_modal.blade.php).
 *
 * Flujo: botón «Modificar» de una salida con nota del Historial → GET con el producto en la
 * nota y qué se puede hacer → el usuario elige QUÉ PASÓ:
 *   · Devolución: el material volvió. Pone cuánto vuelve → POST devolución; la nota no cambia.
 *   · Corrección: la nota se cargó mal. Pone cuánto salió de verdad y el motivo → POST
 *     corrección → se abre la comparación original | corregida (window.almVerCorreccion, en
 *     esa vista: la usa también la marca «corregida», que ve quien no tiene este modal).
 *
 * Las reglas —no devolver más de lo que queda, no corregir por debajo de lo devuelto, no subir
 * sin stock, quién puede qué— las decide el servidor (DevolucionService, CorreccionNotaService,
 * AjusteNotaController); aquí solo se guía.
 *
 * Se carga bajo demanda desde window.almModificarNota. Los listeners van
 * sobre el DOCUMENTO y buscan el modal en cada evento: la SPA reemplaza el HTML al navegar.
 */
(function (w) {
    'use strict';
    if (w.AjusteNota) return;

    var EPS = 0.0005;
    var SEP = ' <span class="ajn-sep">&middot;</span> ';
    // Lo que cambia según la operación elegida. Una sola tabla: el campo, el motivo y el botón
    // se pintan desde aquí y no hay dos ramas de código que se puedan desincronizar.
    var OPS = {
        devolucion: {
            cant: 'Cantidad que vuelve', motivo: 'Motivo (opcional)', motivoEj: 'Ej.: sobró en la obra',
            boton: 'Aceptar', motivoObligatorio: false,
        },
        correccion: {
            cant: 'Cantidad que de verdad salió', motivo: 'Motivo (obligatorio)', motivoEj: 'Ej.: salieron 100, se cargó 180 por error',
            boton: 'Aceptar', motivoObligatorio: true,
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
    // Se lee como la muestra num() (es-VE): "1.500" son mil quinientos, no 1,5 —antes
    // parseFloat se quedaba en 1.5 y la nota se corregía mil veces más chica—. Con un solo
    // separador que no sea de miles ("2.5", "2,5") es el decimal. Cualquier otra cosa: 0.
    function cantidad() {
        var t = String($('ajNotaCant').value || '').trim().replace(/\s/g, '');
        if (/^\d{1,3}(\.\d{3})+(,\d+)?$/.test(t)) t = t.replace(/\./g, '').replace(',', '.');
        else if (/^\d*[.,]?\d+$/.test(t)) t = t.replace(',', '.');
        else return 0;
        var n = parseFloat(t);
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
    // Cada apertura lleva su número: si el modal se cierra y se abre con OTRO producto antes de
    // que llegue la respuesta del primero, esa respuesta tardía se descarta en vez de pintar
    // el producto equivocado (y que la corrección se mande para él).
    var aperturas = 0;
    function abrir(numero, idProducto) {
        var m = modal(); if (!m) return;
        var esta = ++aperturas;
        estado.nota = null;
        estado.op = null;
        $('ajNotaContenido').hidden = true;
        $('ajNotaGuardar').disabled = true;
        $('ajNotaGuardar').innerHTML = OPS.devolucion.boton;
        cabecera(numero, null, '');   // la fecha llega con los datos
        mensaje('Cargando la nota ' + esc(numero) + '…', 'info');
        m.classList.add('open');
        w.bloquearScrollFondo();

        pedir(m.dataset.urlShow + '?numero=' + encodeURIComponent(numero) + '&id_producto=' + encodeURIComponent(idProducto))
            .then(function (res) {
                if (esta !== aperturas) return;
                if (!res.ok) { mensaje(esc(res.d.message || 'No se pudo cargar la nota.')); return; }
                mensaje('');
                pintar(res.d);
            })
            .catch(function () { if (esta === aperturas) mensaje('No se pudo contactar al servidor. Modificar una nota necesita conexión.'); });
    }

    function cerrar() {
        var m = modal(); if (!m) return;
        aperturas++;   // lo que llegue tarde de esta apertura ya no se pinta
        m.classList.remove('open');
        w.restaurarScrollFondo();
    }

    // ── Pintar ───────────────────────────────────────────────────────────────
    // La cabecera del modal es la nota: número · fecha. El proyecto y quién recibió, en el title.
    function cabecera(numero, fecha, title) {
        var h = $('ajNotaTitulo');
        h.title = title;
        h.innerHTML = '<i class="material-icons">receipt_long</i><span class="ajn-nota-txt"><b>' + esc(numero) + '</b>'
            + (fecha ? SEP + esc(fecha) : '') + '</span>';
    }

    function pintar(nota) {
        estado.nota = nota;
        var l = nota.linea;
        var um = esc(l.um || '');

        cabecera(nota.numero, nota.fecha, [nota.numero, nota.fecha, nota.proyecto, nota.solicitante].filter(Boolean).join(' · '));

        // Foto, código y nombre como la fila del Inventario (almacen_local.js): la miniatura
        // (?sz=w120) o el recuadro gris con el ícono, y el código chico encima del nombre.
        var foto = l.foto
            ? '<img src="' + esc(l.foto + (l.foto.indexOf('?') >= 0 ? '&' : '?') + 'sz=w120') + '" alt="" class="ajn-foto">'
            : '<span class="ajn-foto ajn-foto-sin" title="Sin foto"><i class="material-icons">inventory_2</i></span>';
        $('ajNotaProducto').innerHTML = '<div class="ajn-prod-cab">'
            +   foto
            +   '<div class="ajn-info">'
            +     (l.codigo ? '<span class="ajn-codigo">' + esc(l.codigo) + '</span>' : '')
            +     '<span class="ajn-titulo">' + esc(l.nombre) + '</span>'
            // Lo entregado ya no se repite aquí (pedido del cliente, 23-11-2026): en Corrección la
            // casilla de la cantidad ya lo muestra. Solo se avisa lo que ya volvió.
            +     (l.devuelto > EPS ? '<span class="ajn-datos">Ya devuelto <b>' + num(l.devuelto) + ' ' + um + '</b></span>' : '')
            +   '</div>'
            + '</div>';
        $('ajNotaUm').textContent = l.um || '';

        // Cada opción, encendida o apagada. El porqué de una apagada va en el title, no dentro
        // de la tarjeta (pedido del cliente, 23-11-2026).
        var primera = null;
        document.querySelectorAll('#ajNotaModal .ajn-opcion').forEach(function (b) {
            var no = nota[b.dataset.op].motivo_no;
            b.disabled = !!no;
            b.title = no || '';
            if (!no && !primera) primera = b.dataset.op;
        });

        pintarHistorial(nota, l.um || '');   // sin escapar: pintarHistorial escapa la línea entera
        $('ajNotaContenido').hidden = false;
        if (primera) {
            elegir(primera);
        } else {
            elegir(null);
            mensaje(esc(nota.devolucion.motivo_no || nota.correccion.motivo_no || 'Con este producto de la nota no se puede hacer nada desde aquí.'), 'info');
        }
    }

    // um llega SIN escapar (cada línea se escapa entera una sola vez).
    function pintarHistorial(nota, um) {
        var bloques = [];
        // Una operación = una línea: si no cabe se corta con «…» y el texto entero queda en
        // el title (al pasar el mouse). El CSS lo hace con .ajn-historial li.
        var linea = function (cuanto, motivo, usuario, fecha) {
            var txt = fecha + ' · ' + cuanto + (motivo ? ' — ' + motivo : '') + (usuario ? ' (' + usuario + ')' : '');
            return '<li title="' + esc(txt) + '">' + esc(txt) + '</li>';
        };
        if (nota.devolucion.historial.length) {
            bloques.push('<b>Devoluciones anteriores</b><ul>' + nota.devolucion.historial.map(function (d) {
                return linea(num(d.cantidad) + ' ' + um, d.motivo, d.usuario, d.fecha);
            }).join('') + '</ul>');
        }
        if (nota.correccion.historial.length) {
            bloques.push('<b>Correcciones anteriores</b><ul>' + nota.correccion.historial.map(function (c) {
                return linea('de ' + num(c.antes) + ' a ' + num(c.despues) + ' ' + um, c.motivo, c.usuario, c.fecha);
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

        // Si mientras se guarda el modal se cierra (o se abre con otro producto), la respuesta
        // ya no le habla a ESE modal: el resultado sale como toast y no se toca lo que hay abierto.
        var esta = aperturas;
        var vigente = function () { return esta === aperturas; };
        pedir(url, { method: 'POST', body: JSON.stringify(cuerpo) })
            .then(function (res) {
                if (!res.ok) {
                    if (vigente()) mensaje(esc(errorDe(res))); else w.toast(errorDe(res), 'error');
                    return;
                }
                w.toast(res.d.message || 'Listo.', 'success');
                // La tabla no se recarga aquí: el guardado pone al día la copia local y su aviso
                // ('copia-local') repinta la MISMA página sin spinner. Recargar desde aquí la
                // mandaba a la página 1.
                if (!vigente()) {
                    // Se reabrió con la MISMA línea mientras se guardaba: lo que muestra es de
                    // antes de guardar. Se vuelve a pedir para que no se modifique sobre cifras viejas.
                    var abierta = estado.nota;
                    if (modal().classList.contains('open') && abierta && abierta.numero === nota.numero
                        && abierta.linea.id_producto === nota.linea.id_producto) {
                        abrir(nota.numero, nota.linea.id_producto);
                    }
                    return;
                }
                btn.innerHTML = html;
                cerrar();
                // Original | corregida, con el mismo envoltorio que la marca «corregida».
                if (op === 'correccion') w.almVerCorreccion(nota.numero);
            })
            .catch(function () {
                var txt = 'No se pudo contactar al servidor. Modificar una nota necesita conexión.';
                if (vigente()) mensaje(txt); else w.toast(txt, 'error');
            })
            .finally(function () {
                estado.guardando = false;
                if (vigente()) btn.innerHTML = html;
                actualizarBoton();
            });
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
        if (!modal() || !modal().classList.contains('open')) return;
        if (e.key === 'Escape') { cerrar(); return; }
        // Enter en un campo: guarda, como el Enter de cualquier formulario corto.
        if (e.key === 'Enter' && e.target.closest && e.target.closest('#ajNotaModal input')) {
            e.preventDefault();
            guardar();
        }
    });

    w.AjusteNota = { abrir: abrir, cerrar: cerrar };
})(window);
