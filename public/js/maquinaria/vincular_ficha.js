/**
 * window.VincularFicha — modal "Vincular a una ficha del catálogo" de /admin/equipos
 * (resources/views/admin/equipos/partials/vincular_ficha_modal.blade.php).
 *
 * Flujo: doble clic en la foto de un equipo (solo super.admin) → se buscan fichas por
 * modelo/tipo y año (CaracteristicaModeloController::elegir) → se elige una → POST
 * (EquipoController::vincularFicha) → la foto de la fila cambia sin recargar la tabla.
 * La foto que muestra cada tarjeta es la que tomaría ESTE equipo: la de su color en esa
 * ficha si la tiene, si no la del modelo (la misma regla de Equipo::fotoParaMostrar).
 *
 * Se carga bajo demanda (cargarScriptUnaVez) desde window.eqVincularFicha. Los listeners
 * van sobre el DOCUMENTO y buscan el modal en cada evento: la SPA reemplaza el HTML al
 * navegar y un listener pegado al nodo viejo dejaría de funcionar.
 */
(function (w) {
    'use strict';
    if (w.VincularFicha) return;

    var ESPERA_BUSQUEDA = 300;   // ms tras la última tecla antes de buscar
    // guardando: id del equipo cuyo vínculo se está guardando (null si ninguno). Es por equipo:
    // si se cierra con Escape a mitad y se abre otro, la respuesta del primero no toca el modal.
    var estado = { fotoEl: null, idEquipo: null, espec: '', color: '', elegida: null, pedido: 0, timer: null, guardando: null };

    function $(id) { return document.getElementById(id); }
    function modal() { return $('vfModal'); }
    function abierto() { var m = modal(); return !!(m && m.classList.contains('open')); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function aviso(texto) { return '<div class="vf-aviso">' + esc(texto) + '</div>'; }

    // ── Abrir / cerrar ───────────────────────────────────────────────────────
    function abrir(fotoEl) {
        var m = modal(); if (!m || !fotoEl) return;
        estado.fotoEl = fotoEl;
        estado.idEquipo = fotoEl.getAttribute('data-vincular');
        estado.espec = fotoEl.getAttribute('data-espec') || '';
        estado.color = (fotoEl.getAttribute('data-color') || '').toUpperCase();
        estado.elegida = null;
        $('vfEquipo').innerHTML =
            '<span><b>' + esc(fotoEl.getAttribute('data-titulo') || '') + '</b></span>' +
            (estado.color ? '<span>Color: <b>' + esc(estado.color) + '</b></span>' : '') +
            '<span>' + (estado.espec ? 'Ya tiene ficha (marcada como ACTUAL): elige otra para cambiarla.' : 'Todavía no tiene ficha del catálogo.') + '</span>';
        $('vfBuscar').value = fotoEl.getAttribute('data-modelo') || '';
        $('vfAnio').value = '';
        actualizarBoton();
        m.classList.add('open');
        document.body.style.overflow = 'hidden';
        buscar();
        setTimeout(function () { var b = $('vfBuscar'); if (b && abierto()) { b.focus(); b.select(); } }, 50);
    }

    function cerrar() {
        var m = modal(); if (m) m.classList.remove('open');
        document.body.style.overflow = '';
        clearTimeout(estado.timer);
        estado.fotoEl = null;
        estado.pedido++;   // una respuesta que llegue tarde ya no pinta nada
    }

    // ── Buscar fichas ────────────────────────────────────────────────────────
    function buscar() {
        var m = modal(); if (!m) return;
        var n = ++estado.pedido;
        estado.elegida = null;
        actualizarBoton();
        var params = new URLSearchParams({ q: $('vfBuscar').value.trim(), anio: $('vfAnio').value });
        if ($('vfAnio').options.length <= 1) params.set('con_anios', '1');   // la lista se pide una vez
        $('vfResultados').innerHTML = aviso('Buscando…');
        w.apiFetch(m.getAttribute('data-url-elegir') + '?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
            .then(function (d) {
                if (n !== estado.pedido) return;
                llenarAnios(d.anios || []);
                pintar(d.items || [], !!d.hay_mas);
            })
            .catch(function () {
                if (n !== estado.pedido) return;
                $('vfResultados').innerHTML = aviso('No se pudo cargar el catálogo. Revisa tu conexión.');
            });
    }

    // El <select> se llena la primera vez que llega la lista (el HTML trae solo "Todos").
    function llenarAnios(anios) {
        var sel = $('vfAnio');
        if (!sel || sel.options.length > 1) return;
        anios.forEach(function (a) { sel.insertAdjacentHTML('beforeend', '<option value="' + esc(a) + '">' + esc(a) + '</option>'); });
    }

    // La foto que tomaría este equipo con esa ficha: la de su color, o la del modelo.
    function fotoPara(item) {
        var suyo = (item.colores || []).find(function (c) { return c.color === estado.color && c.foto_url; });
        return suyo ? suyo.foto_url : item.foto_url;
    }

    function pintar(items, hayMas) {
        var cont = $('vfResultados');
        if (!items.length) { cont.innerHTML = aviso('No hay fichas con esa búsqueda.'); return; }
        var html = items.map(function (it) {
            var foto = fotoPara(it);
            var nombre = it.tipo && String(it.modelo || '').toUpperCase().indexOf(String(it.tipo).toUpperCase()) !== 0
                ? it.tipo + ' · ' + it.modelo : it.modelo;
            var colores = (it.colores || []).map(function (c) {
                return '<span class="vf-color' + (c.color === estado.color ? ' suyo' : '') + '" style="background:' + esc(c.muestra) + ';" title="' +
                    esc(c.color + ': ' + c.total + (c.foto_url ? '' : ' · sin foto propia')) + '"></span>';
            }).join('');
            return '<button type="button" class="vf-card" data-vf-id="' + esc(it.id) + '">' +
                '<div class="vf-foto">' +
                    (foto ? '<img src="' + esc(foto) + '" alt="" loading="lazy">' : '<i class="material-icons">' + esc(it.placeholder || 'image_not_supported') + '</i>') +
                    (String(it.id) === String(estado.espec) ? '<span class="vf-badge actual">ACTUAL</span>' : '') +
                    (it.anio ? '<span class="vf-badge anio"><i class="material-icons">event</i>' + esc(it.anio) + '</span>' : '') +
                    (it.total ? '<span class="vf-badge total" title="Equipos vinculados"><i class="material-icons">local_shipping</i>' + esc(it.total) + '</span>' : '') +
                '</div>' +
                '<div class="vf-info"><span class="vf-nombre">' + esc(nombre) + '</span>' +
                    (colores ? '<div class="vf-colores">' + colores + '</div>' : '') +
                '</div>' +
            '</button>';
        }).join('');
        cont.innerHTML = '<div class="vf-grid">' + html + '</div>' +
            (hayMas ? aviso('Hay más fichas: escribe más del modelo o elige el año para acotar.') : '');
    }

    function elegir(card) {
        estado.elegida = card.getAttribute('data-vf-id');
        document.querySelectorAll('#vfModal .vf-card.sel').forEach(function (c) { c.classList.remove('sel'); });
        card.classList.add('sel');
        actualizarBoton();
    }

    function actualizarBoton() {
        var b = $('vfVincular'); if (!b) return;
        var yaEsLaSuya = estado.elegida && String(estado.elegida) === String(estado.espec);
        b.disabled = !estado.elegida || yaEsLaSuya || estado.guardando === estado.idEquipo;
        b.title = yaEsLaSuya ? 'El equipo ya está vinculado a esta ficha' : '';
    }

    // ── Vincular ─────────────────────────────────────────────────────────────
    function vincular() {
        var m = modal();
        if (!m || !estado.elegida || estado.guardando === estado.idEquipo || String(estado.elegida) === String(estado.espec)) return;
        var fotoEl = estado.fotoEl, idEquipo = estado.idEquipo;
        var terminar = function () { if (estado.guardando === idEquipo) estado.guardando = null; actualizarBoton(); };
        estado.guardando = idEquipo;
        actualizarBoton();
        var fd = new FormData();
        fd.append('ID_ESPEC', estado.elegida);
        w.apiFetch(m.getAttribute('data-url-vincular').replace('__ID__', encodeURIComponent(idEquipo)), {
            method: 'POST', body: fd,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (b) { return { ok: r.ok, body: b }; }); })
            .then(function (res) {
                terminar();
                if (!res.ok || !res.body.success) {
                    var err = res.body && res.body.errors ? Object.values(res.body.errors)[0][0] : null;
                    w.toast(err || (res.body && res.body.message) || 'No se pudo vincular el equipo.', 'error');
                    return;
                }
                pintarFotoFila(fotoEl, res.body);
                w.toast(res.body.message, 'success');
                // Solo si el modal sigue siendo de ESTE equipo (pudo cerrarse y abrirse otro).
                if (abierto() && estado.idEquipo === idEquipo) cerrar();
            })
            .catch(function () {
                terminar();
                w.toast('Error de red al vincular el equipo.', 'error');
            });
    }

    // La foto de la fila se cambia en el sitio (sin recargar la tabla ni perder el scroll).
    // Mismo marcado que partials/table_rows: .eq-foto-wrap con <img class="eq-foto"> o
    // .placeholder con el ícono.
    function pintarFotoFila(el, body) {
        if (!el || !el.isConnected) return;   // la tabla se repintó mientras tanto
        el.setAttribute('data-espec', body.id_espec);
        el.innerHTML = '';
        el.classList.toggle('eq-foto-wrap', !!body.foto);
        el.classList.toggle('placeholder', !body.foto);
        if (body.foto) {
            var img = document.createElement('img');
            img.alt = 'Foto';
            img.className = 'eq-foto';
            img.onload = function () { img.style.opacity = '1'; };   // .eq-foto nace en opacity:0
            img.onerror = function () { sinFoto(el); };               // como el cargador de la tabla
            img.src = body.foto;
            el.appendChild(img);
        } else {
            sinFoto(el);
        }
    }
    function sinFoto(el) {
        el.classList.remove('eq-foto-wrap');
        el.classList.add('placeholder');
        el.innerHTML = '<span class="material-icons">image_not_supported</span>';
    }

    // ── Eventos (delegados en el documento: sobreviven al reemplazo SPA del HTML) ──
    document.addEventListener('click', function (e) {
        if (!abierto()) return;
        var t = e.target, card;
        if (t === modal() || t.closest('[data-vf-cerrar]')) { cerrar(); return; }
        if (t.closest('#vfVincular')) { vincular(); return; }
        if ((card = t.closest('#vfModal .vf-card'))) elegir(card);
    });
    document.addEventListener('dblclick', function (e) {
        // Doble clic en una tarjeta: elegirla y vincular de una vez.
        var card = abierto() && e.target.closest('#vfModal .vf-card');
        if (card) { elegir(card); vincular(); }
    });
    document.addEventListener('input', function (e) {
        if (e.target.id !== 'vfBuscar' || !abierto()) return;
        clearTimeout(estado.timer);
        estado.timer = setTimeout(buscar, ESPERA_BUSQUEDA);
    });
    document.addEventListener('change', function (e) {
        if (e.target.id === 'vfAnio' && abierto()) buscar();
    });
    document.addEventListener('keydown', function (e) {
        if (!abierto()) return;
        if (e.key === 'Escape') { cerrar(); return; }
        if (e.key === 'Enter' && e.target.id === 'vfBuscar') { e.preventDefault(); clearTimeout(estado.timer); buscar(); }
    });
    // Si se navega (SPA) con el modal abierto, el HTML nuevo ya no lo trae: devolver el scroll.
    w.addEventListener('spa:contentLoaded', function () {
        if (estado.fotoEl && !modal()) { estado.fotoEl = null; document.body.style.overflow = ''; }
    });

    w.VincularFicha = { abrir: abrir, cerrar: cerrar };
})(window);
