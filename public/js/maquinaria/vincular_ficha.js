/**
 * window.VincularFicha — modal "Vincular a una ficha del catálogo" de /admin/equipos
 * (resources/views/admin/equipos/partials/vincular_ficha_modal.blade.php).
 *
 * Flujo: doble clic en la foto de un equipo (todos, sin permiso) → el modal abre con las
 * fichas SUGERIDAS para ese equipo (CaracteristicaModeloController::elegir); escribir
 * busca en todo el catálogo por modelo, tipo o año → se elige una → POST
 * (EquipoController::vincularFicha; antes catalogo.asegurarFicha si es la nueva) → la foto
 * de la fila cambia sin recargar la tabla. Si no existe la ficha del modelo + año de ESE
 * equipo, la fila "Crear su ficha" va arriba SIEMPRE, se esté buscando o no.
 * La foto que muestra cada fila es la que tomaría ESTE equipo: la de su color en esa
 * ficha, si no la del modelo y, si la ficha no tiene ninguna, la suya propia (la misma
 * regla de Equipo::fotoParaMostrar).
 * Foto: tocar el recuadro de la foto de una ficha (con la cámara, como en el Catálogo) sube
 * la foto del MODELO a ESA ficha (CaracteristicaModeloController::uploadFoto) y vincula a
 * ella ESTE equipo, nada más. En "Crear su ficha", antes la crea.
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
    function puedeFoto() { var m = modal(); return !!(m && m.getAttribute('data-puede-foto') === '1'); }
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
        // Bloqueo central (html Y body): solo en body la tabla seguía desplazándose detrás.
        w.bloquearScrollFondo();
        buscar();
        setTimeout(function () { var b = $('vfBuscar'); if (b && abierto()) b.focus(); }, 50);
    }

    function cerrar() {
        var m = modal(); if (m) m.classList.remove('open');
        // Libera solo si no queda otra capa abierta detrás (detalle, visor de PDF…).
        w.restaurarScrollFondo();
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
            cajaFoto('', 'note_add', false) +
            '<div class="vf-info">' +
                textoFicha('Crear su ficha', c.marca, c.modelo) +
                '<div class="vf-meta">' + (c.tipo ? '<span>' + esc(c.tipo) + '</span>' : '') + '<span>Año: ' + esc(c.anio) + '</span>' +
                    '<span>Todavía no existe la de su tipo: se crea y se vincula</span></div>' +
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
                cajaFoto(foto, it.placeholder || 'precision_manufacturing', !!it.foto_url) +
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

    // Recuadro de la foto, como .cat-photo del Catálogo: la foto (o el ícono) y la cámara;
    // tocarlo sube la foto a esa ficha (data-vf-foto, ver los eventos). «Cambiar» solo si la
    // FICHA ya tiene foto (la que se ve puede ser la del propio equipo).
    function cajaFoto(foto, iconoFicha, fichaConFoto) {
        var img = foto ? '<img src="' + esc(foto) + '" alt="" loading="lazy">' : '<i class="material-icons">' + esc(iconoFicha) + '</i>';
        // Sin el permiso catalogo.foto: solo la foto, sin cámara (data-puede-foto del modal).
        if (!puedeFoto()) return '<span class="vf-foto">' + img + '</span>';
        var txt = fichaConFoto ? 'Cambiar foto' : 'Subir foto';
        return '<span class="vf-foto" data-vf-foto title="' + txt + '">' +
            img +
            '<span class="vf-foto-overlay"><i class="material-icons">photo_camera</i>' + txt + '</span>' +
            '<i class="material-icons vf-foto-cam">photo_camera</i>' +
        '</span>';
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
        var ocupado = estado.guardando === estado.idEquipo;
        b.disabled = !estado.elegida || yaEsLaSuya || ocupado;
        b.title = yaEsLaSuya ? 'El equipo ya está vinculado a esta ficha' : '';
        b.innerHTML = estado.elegida === NUEVA
            ? '<i class="material-icons">note_add</i> Crear ficha y vincular'
            : '<i class="material-icons">link</i> Vincular';
    }

    // ── Vincular / subir foto ────────────────────────────────────────────────
    function vincular() {
        if (!estado.elegida || String(estado.elegida) === String(estado.espec)) return;
        guardar(null);
    }

    // Foto elegida en el <input type=file>: va a la ficha cuyo recuadro se tocó (la elegida).
    function subirFoto(archivo) {
        if (!archivo || !estado.elegida) return;
        guardar(archivo);
    }

    // Vincula el equipo a la ficha elegida y, con `archivo`, antes le sube la foto.
    function guardar(archivo) {
        var m = modal();
        if (!m || !estado.elegida || estado.guardando === estado.idEquipo) return;
        var fotoEl = estado.fotoEl, idEquipo = estado.idEquipo, elegida = estado.elegida;
        var crear = elegida === NUEVA ? estado.crear : null;
        var terminar = function () { if (estado.guardando === idEquipo) estado.guardando = null; actualizarBoton(); };
        estado.guardando = idEquipo;
        actualizarBoton();

        // La ficha nueva primero se crea (o se encuentra, si alguien la creó mientras tanto) y
        // después se vincula igual que una elegida. asegurarFicha también enlaza las OTRAS
        // unidades de ese modelo, año y tipo que no tenían ficha (enlazados: sus ID, quizá con este).
        var nueva = null;   // respuesta de asegurarFicha: { creada, enlazados }
        var idEspec = null;
        var ficha = crear
            ? w.apiPostForm(m.getAttribute('data-url-asegurar'), { modelo: crear.modelo, anio: crear.anio, tipo: crear.tipo }, 'No se pudo crear la ficha.')
                .then(function (b) { nueva = b; return b.id; })
            : Promise.resolve(elegida);
        ficha
            .then(function (id) {
                idEspec = id;
                // La foto del MODELO (sin color), como "Cambiar foto" en el Catálogo.
                return archivo
                    ? w.apiPostForm(m.getAttribute('data-url-foto').replace('__ID__', encodeURIComponent(id)), { foto: archivo }, 'No se pudo subir la foto.')
                    : null;
            })
            .then(function () {
                // Vincular DESPUÉS de la foto: la respuesta trae la foto que el equipo muestra ya.
                return w.apiPostForm(m.getAttribute('data-url-vincular').replace('__ID__', encodeURIComponent(idEquipo)), { ID_ESPEC: idEspec }, 'No se pudo vincular el equipo.');
            })
            .then(function (body) {
                terminar();
                pintarFotoFila(fotoEl, body);
                w.toast(archivo ? 'Foto subida a la ficha y equipo vinculado.'
                    : (nueva ? mensajeFichaNueva(crear, nueva, idEquipo) : body.message), 'success');
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
            (otros ? ' (también ' + otros + (otros === 1 ? ' unidad más' : ' unidades más') + ' de ese modelo, año y tipo)' : '') +
            (b.creada ? '. Complétala en el Catálogo.' : '.');
    }

    // La foto de la fila se cambia en el sitio (sin recargar la tabla ni perder el scroll).
    // Mismo marcado que la fila (equipos_local.js · filaEquipo): .eq-foto-wrap con
    // <img class="eq-foto"> o .placeholder con el ícono. Solo se cambia la imagen: el recuadro
    // también lleva la etiqueta VENDIDO / EN TRÁMITE DE VENTA del frente, que se queda.
    function pintarFotoFila(el, body) {
        if (!el || !el.isConnected) return;   // la tabla se repintó mientras tanto
        el.setAttribute('data-espec', body.id_espec);
        var nodo;
        if (body.foto) {
            nodo = document.createElement('img');
            nodo.alt = 'Foto';
            nodo.className = 'eq-foto';
            nodo.onload = function () { nodo.style.opacity = '1'; };   // .eq-foto nace en opacity:0
            nodo.onerror = function () { ponerImagen(el, icono(), false); };   // como el cargador de la tabla
            nodo.src = body.foto;
        } else {
            nodo = icono();
        }
        ponerImagen(el, nodo, !!body.foto);
    }
    function icono() {
        var s = document.createElement('span');
        s.className = 'material-icons';
        s.textContent = 'image_not_supported';
        return s;
    }
    function ponerImagen(el, nodo, conFoto) {
        var actual = el.querySelector(':scope > img, :scope > .material-icons');
        if (actual) actual.replaceWith(nodo); else el.insertBefore(nodo, el.firstChild);
        el.classList.toggle('eq-foto-wrap', conFoto);
        el.classList.toggle('placeholder', !conFoto);
    }

    // ── Eventos (delegados en el documento: sobreviven al reemplazo SPA del HTML) ──
    document.addEventListener('click', function (e) {
        if (!abierto()) return;
        var t = e.target, card;
        // Solo la X (data-vf-cerrar) lo cierra; un clic en el fondo no (pedido 01-10-2026).
        if (t.closest('[data-vf-cerrar]')) { cerrar(); return; }
        if (t.closest('#vfVincular')) { vincular(); return; }
        if ((card = t.closest('#vfModal .vf-item'))) {
            elegir(card);
            // El recuadro de la foto: elige ESA ficha y pide la foto (como en el Catálogo). Una vez:
            // el 2.º clic de un doble clic (e.detail 2) no vuelve a pedirla.
            if (t.closest('[data-vf-foto]') && e.detail < 2 && estado.guardando !== estado.idEquipo) {
                var inp = $('vfFoto'); if (inp) { inp.value = ''; inp.click(); }
            }
        }
    });
    document.addEventListener('dblclick', function (e) {
        // Doble clic en una fila: elegirla y vincular de una vez.
        var card = abierto() && e.target.closest('#vfModal .vf-item');
        if (card && !e.target.closest('[data-vf-foto]')) { elegir(card); vincular(); }
    });
    document.addEventListener('change', function (e) {
        if (e.target.id !== 'vfFoto' || !abierto()) return;
        subirFoto(e.target.files && e.target.files[0]);
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
        if (estado.fotoEl && !modal()) { estado.fotoEl = null; w.restaurarScrollFondo(); }
    });

    w.VincularFicha = { abrir: abrir, cerrar: cerrar };
})(window);
