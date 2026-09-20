/**
 * window.VincularFicha — modal "Vincular a una ficha del catálogo" de /admin/equipos
 * (resources/views/admin/equipos/partials/vincular_ficha_modal.blade.php).
 *
 * Flujo: doble clic en la foto de un equipo (solo super.admin) → el modal abre con las
 * fichas SUGERIDAS para ese equipo (CaracteristicaModeloController::elegir); escribir
 * busca en todo el catálogo por modelo, tipo o año → se elige una → POST
 * (EquipoController::vincularFicha; antes catalogo.asegurarFicha si es la nueva) → la foto
 * de la fila cambia sin recargar la tabla. Si no existe la ficha del modelo + año de ESE
 * equipo, la fila "Crear su ficha" va arriba SIEMPRE, se esté buscando o no.
 * La foto que muestra cada fila es la que tomaría ESTE equipo: la de su color en esa
 * ficha, si no la del modelo y, si la ficha no tiene ninguna, la suya propia (la misma
 * regla de Equipo::fotoParaMostrar).
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
    // crear: {modelo, anio, tipo} de la ficha que falta (lo manda elegir) o null.
    var estado = { fotoEl: null, idEquipo: null, espec: '', color: '', elegida: null, crear: null, fotoPropia: null, pedido: 0, timer: null, guardando: null };
    var NUEVA = 'nueva';   // data-vf-id de la fila "Crear su ficha"

    function $(id) { return document.getElementById(id); }
    function modal() { return $('vfModal'); }
    function abierto() { var m = modal(); return !!(m && m.classList.contains('open')); }
    var esc = w.escapeHtml;   // helper central (dom_helpers.js)
    function aviso(texto) { return '<div class="vf-aviso">' + esc(texto) + '</div>'; }

    // ── Abrir / cerrar ───────────────────────────────────────────────────────
    function abrir(fotoEl) {
        var m = modal(); if (!m || !fotoEl) return;
        estado.fotoEl = fotoEl;
        estado.idEquipo = fotoEl.getAttribute('data-vincular');
        estado.espec = fotoEl.getAttribute('data-espec') || '';
        estado.color = (fotoEl.getAttribute('data-color') || '').toUpperCase();
        estado.elegida = null;
        // Vacío: abre con las sugeridas para este equipo; escribir busca en todo el catálogo
        // (modelo, tipo o año).
        $('vfBuscar').value = '';
        actualizarBoton();
        m.classList.add('open');
        document.body.style.overflow = 'hidden';
        buscar();
        setTimeout(function () { var b = $('vfBuscar'); if (b && abierto()) b.focus(); }, 50);
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
        var params = new URLSearchParams({ q: $('vfBuscar').value.trim(), equipo: estado.idEquipo });
        $('vfResultados').innerHTML = aviso('Buscando…');
        w.apiFetch(m.getAttribute('data-url-elegir') + '?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); })
            .then(function (d) {
                if (n !== estado.pedido) return;
                actualizarFichaActual(d.actual);
                pintar(d);
            })
            .catch(function () {
                if (n !== estado.pedido) return;
                $('vfResultados').innerHTML = aviso('No se pudo cargar el catálogo. Revisa tu conexión.');
            });
    }

    // La ficha que el equipo tiene HOY según el servidor (la lista la marca ACTUAL). La de la
    // fila (data-espec) puede estar vieja: crear la ficha de otra unidad enlaza de paso a las
    // de su modelo y año.
    function actualizarFichaActual(actual) {
        var id = actual ? String(actual) : '';
        if (id === estado.espec) return;
        estado.espec = id;
        if (estado.fotoEl && estado.fotoEl.isConnected) estado.fotoEl.setAttribute('data-espec', id);
        actualizarBoton();
    }

    // La foto que tomaría este equipo con esa ficha, en el orden de Equipo::fotoParaMostrar:
    // la de su color, la del modelo y, si la ficha no tiene ninguna, la suya propia.
    function fotoPara(item) {
        var suyo = (item.colores || []).find(function (c) { return c.color === estado.color && c.foto_url; });
        return (suyo && suyo.foto_url) || item.foto_url || estado.fotoPropia;
    }

    // Respuesta de elegir: las sugeridas (d.sugeridas) o el resultado de la búsqueda, y la
    // ficha que le falta a este equipo (d.crear), que va SIEMPRE primero como "Crear su
    // ficha" — también mientras se busca, que es cuando se comprueba que no existe.
    function pintar(d) {
        var cont = $('vfResultados');
        estado.crear = d.crear || null;
        estado.fotoPropia = d.foto_propia || null;
        var crear = estado.crear ? filaCrear(estado.crear) : '';
        var fichas = filasFichas(d.items || []);
        if (!crear && !fichas) {
            cont.innerHTML = aviso(d.sugeridas
                ? 'No encontramos fichas parecidas a este equipo. Búscala por modelo, tipo o año.'
                : 'No hay fichas con esa búsqueda.');
            return;
        }
        cont.innerHTML =
            titulo(d.sugeridas ? 'Sugeridas para este equipo' : (crear ? 'Para este equipo' : '')) +
            crear +
            titulo(!d.sugeridas && crear && fichas ? 'Resultados de la búsqueda' : '') +
            fichas +
            (!fichas && !d.sugeridas ? aviso('No hay más fichas con esa búsqueda.') : '') +
            (d.hay_mas ? aviso('Hay más fichas: escribe más del modelo o el año para acotar.') : '');
    }

    function titulo(texto) {
        return texto ? '<div class="vf-titulo-lista">' + esc(texto) + '</div>' : '';
    }

    // Tipo, marca y modelo como la columna de la tabla de Equipos (la marca se omite si no hay).
    function textoFicha(tipo, marca, modelo) {
        return '<span class="vf-tipo">' + esc(tipo) + '</span>' +
            (marca ? '<span class="vf-marca">' + esc(marca) + '</span>' : '') +
            '<span class="' + (marca ? 'vf-modelo' : 'vf-marca') + '">' + esc(modelo) + '</span>';
    }

    function filaCrear(c) {
        return '<button type="button" class="vf-item vf-crear" data-vf-id="' + NUEVA + '">' +
            '<div class="vf-foto"><i class="material-icons">note_add</i></div>' +
            '<div class="vf-info">' +
                textoFicha('Crear su ficha', c.marca, c.modelo) +
                '<div class="vf-meta"><span>Año: ' + esc(c.anio) + '</span><span>Todavía no existe: se crea y se vincula</span></div>' +
            '</div>' +
            '<i class="material-icons vf-check">check_circle</i>' +
        '</button>';
    }

    // Una fila por ficha, como los equipos del modal de Anclaje: foto a la izquierda y a la
    // derecha tipo / marca / modelo y debajo año · equipos · colores.
    function filasFichas(items) {
        return items.map(function (it) {
            var foto = fotoPara(it);
            var total = it.total || 0;
            var colores = (it.colores || []).map(function (c) {
                return '<span class="vf-color' + (c.color === estado.color ? ' suyo' : '') + '" style="background:' + esc(c.muestra) + ';" title="' +
                    esc(c.color + ': ' + c.total + (c.foto_url ? '' : ' · sin foto propia')) + '"></span>';
            }).join('');
            return '<button type="button" class="vf-item" data-vf-id="' + esc(it.id) + '">' +
                '<div class="vf-foto">' +
                    (foto ? '<img src="' + esc(foto) + '" alt="" loading="lazy">' : '<i class="material-icons">' + esc(it.placeholder || 'image_not_supported') + '</i>') +
                '</div>' +
                '<div class="vf-info">' +
                    textoFicha(it.tipo || 'S/TIPO', it.marca, it.modelo) +
                    '<div class="vf-meta">' +
                        (it.anio ? '<span>Año: ' + esc(it.anio) + '</span>' : '') +
                        '<span>' + esc(total) + (total === 1 ? ' equipo' : ' equipos') + '</span>' +
                        (colores ? '<span class="vf-colores">' + colores + '</span>' : '') +
                    '</div>' +
                '</div>' +
                (String(it.id) === String(estado.espec) ? '<span class="vf-actual">ACTUAL</span>' : '') +
                '<i class="material-icons vf-check">check_circle</i>' +
            '</button>';
        }).join('');
    }

    function elegir(card) {
        estado.elegida = card.getAttribute('data-vf-id');
        document.querySelectorAll('#vfModal .vf-item.sel').forEach(function (c) { c.classList.remove('sel'); });
        card.classList.add('sel');
        actualizarBoton();
    }

    function actualizarBoton() {
        var b = $('vfVincular'); if (!b) return;
        var yaEsLaSuya = estado.elegida && String(estado.elegida) === String(estado.espec);
        b.disabled = !estado.elegida || yaEsLaSuya || estado.guardando === estado.idEquipo;
        b.title = yaEsLaSuya ? 'El equipo ya está vinculado a esta ficha' : '';
        b.innerHTML = estado.elegida === NUEVA
            ? '<i class="material-icons">note_add</i> Crear ficha y vincular'
            : '<i class="material-icons">link</i> Vincular';
    }

    // ── Vincular ─────────────────────────────────────────────────────────────
    function vincular() {
        var m = modal();
        if (!m || !estado.elegida || estado.guardando === estado.idEquipo || String(estado.elegida) === String(estado.espec)) return;
        var fotoEl = estado.fotoEl, idEquipo = estado.idEquipo, crear = estado.elegida === NUEVA ? estado.crear : null;
        var terminar = function () { if (estado.guardando === idEquipo) estado.guardando = null; actualizarBoton(); };
        estado.guardando = idEquipo;
        actualizarBoton();

        // La ficha nueva primero se crea (o se encuentra, si alguien la creó mientras tanto) y
        // después se vincula igual que una elegida. asegurarFicha también enlaza las OTRAS
        // unidades de ese modelo y año que no tenían ficha (enlazados: sus ID, quizá con este).
        var nueva = null;   // respuesta de asegurarFicha: { creada, enlazados }
        var ficha = crear
            ? w.apiPostForm(m.getAttribute('data-url-asegurar'), { modelo: crear.modelo, anio: crear.anio, tipo: crear.tipo }, 'No se pudo crear la ficha.')
                .then(function (b) { nueva = b; return b.id; })
            : Promise.resolve(estado.elegida);
        ficha
            .then(function (idEspec) {
                return w.apiPostForm(m.getAttribute('data-url-vincular').replace('__ID__', encodeURIComponent(idEquipo)), { ID_ESPEC: idEspec }, 'No se pudo vincular el equipo.');
            })
            .then(function (body) {
                terminar();
                pintarFotoFila(fotoEl, body);
                w.toast(nueva ? mensajeFichaNueva(crear, nueva, idEquipo) : body.message, 'success');
                // Solo si el modal sigue siendo de ESTE equipo (pudo cerrarse y abrirse otro).
                if (abierto() && estado.idEquipo === idEquipo) cerrar();
            })
            .catch(function (e) {
                terminar();
                w.toast(e.message, 'error');
            });
    }

    function mensajeFichaNueva(crear, b, idEquipo) {
        var otros = (b.enlazados || []).filter(function (id) { return String(id) !== String(idEquipo); }).length;
        return 'Ficha ' + crear.modelo + ' ' + crear.anio + (b.creada ? ' creada' : ' encontrada') + ' y equipo vinculado' +
            (otros ? ' (también ' + otros + (otros === 1 ? ' unidad más' : ' unidades más') + ' de ese modelo y año)' : '') +
            (b.creada ? '. Complétala en el Catálogo.' : '.');
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
        if ((card = t.closest('#vfModal .vf-item'))) elegir(card);
    });
    document.addEventListener('dblclick', function (e) {
        // Doble clic en una fila: elegirla y vincular de una vez.
        var card = abierto() && e.target.closest('#vfModal .vf-item');
        if (card) { elegir(card); vincular(); }
    });
    document.addEventListener('input', function (e) {
        if (e.target.id !== 'vfBuscar' || !abierto()) return;
        clearTimeout(estado.timer);
        estado.timer = setTimeout(buscar, ESPERA_BUSQUEDA);
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
