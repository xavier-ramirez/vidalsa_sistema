{{-- Carga masiva de documentos: la abre el menú Acciones (partials/acciones).

     ESTE MODAL SOLO SIRVE PARA SOLTAR ARCHIVOS. El estado de cada PDF —de qué ficha es, si
     falta la fecha, si no se pudo leer— se ve en la tabla de "Revisión de documentos", que es
     la misma donde se ve lo que lee la tarea de la noche. Antes había aquí una segunda lista
     con su propio "Aplicar" y eran dos tablas de documentos en el mismo módulo (pedido
     23-09-2026: una sola). Aplicar y descartar se hacen desde esa tabla.

     Por qué un archivo por petición: el texto lo saca el OCR de Google Drive (~8 s por PDF) y
     treinta en una sola petición se caerían por timeout. La cola los manda de uno en uno.

     Las reglas de seguridad NO viven aquí sino en el servidor (CargaMasivaDocumentos): esta
     pantalla solo las refleja.

     Mismo permiso que la ruta y el controlador: 'docs.carga.masiva', que es EXCLUSIVO (ni
     super.admin lo hereda). Sin él no se baja ni el HTML ni el JS, y window.abrirCargaMasiva
     ni siquiera existe. --}}
@can('docs.carga.masiva')
<style>
    /* Mismo lenguaje que el aviso de cierre de sesión (partials/session_timeout): tarjeta
       blanca de 16 px de radio, borde suave y sombra larga. Sin barra oscura arriba: el
       título va dentro de la tarjeta, con su ícono en una pastilla azul clara. */
    #hdCmOverlay { position: fixed; inset: 0; background: rgba(15,23,42,0.45); z-index: 2500; display: flex; justify-content: center; align-items: center; }
    /* SIN overflow:hidden: la lista del desplegable del tipo se sale de la tarjeta y con él
       quedaba cortada por el borde de abajo (solo se veían 3 de las 7 opciones). Las esquinas
       redondas del encabezado las pone él mismo. */
    .hd-cm-modal { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; width: 94%; max-width: 520px;
                   display: flex; flex-direction: column;
                   box-shadow: 0 20px 45px -12px rgba(15,23,42,0.30); }
    /* Encabezado CON COLOR (el mismo degradado del botón principal y del aviso de cierre de
       sesión): el modal era todo blanco y no se distinguía del fondo de la página. */
    .hd-cm-head { padding: 11px 14px; display: flex; align-items: center; gap: 10px; border-radius: 15px 15px 0 0;
                  background: linear-gradient(135deg,#00004d 0%,#0067b1 100%); color: #fff; }
    .hd-cm-head-ic { flex: 0 0 auto; width: 30px; height: 30px; border-radius: 9px;
                     background: rgba(255,255,255,.18); color: #fff;
                     display: flex; align-items: center; justify-content: center; }
    .hd-cm-head-ic .material-icons { font-size: 18px; }
    .hd-cm-head-txt { flex: 1 1 auto; min-width: 0; }
    .hd-cm-head h2 { margin: 0; font-size: 14px; font-weight: 800; color: #fff; line-height: 1.25; }
    .hd-cm-head p { margin: 1px 0 0; font-size: 11.5px; color: rgba(255,255,255,.78); line-height: 1.3; }
    .hd-cm-cerrar { flex: 0 0 auto; background: transparent; border: none; color: rgba(255,255,255,.75);
                    cursor: pointer; display: flex; padding: 4px; border-radius: 8px; }
    .hd-cm-cerrar:hover { color: #fff; background: rgba(255,255,255,.16); }

    /* El cuerpo entero: tipo, zona de soltar y avance, uno debajo del otro. */
    .hd-cm-tools { padding: 12px 14px 14px; display: flex; flex-direction: column; gap: 10px; }
    /* El desplegable del tipo es el MISMO componente que los filtros (.custom-dropdown de
       uicomponents.js). Solo se le dice que ocupe todo el ancho y que su lista quede por
       encima del modal. Apagado mientras se sube una tanda. */
    .hd-cm-tools .custom-dropdown { width: 100%; }
    .hd-cm-tools .dropdown-content { z-index: 2600; }
    .hd-cm-apagado { opacity: .55; pointer-events: none; }
    .hd-cm-zona { min-width: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px;
                  height: 110px; border: 2px dashed #cbd5e1; border-radius: 10px; background: #f8fafc; color: #64748b;
                  font-size: 12.5px; font-weight: 600; cursor: pointer; text-align: center; padding: 0 12px;
                  transition: border-color .15s, background .15s; }
    .hd-cm-zona:hover, .hd-cm-zona.encima { border-color: #0067b1; background: #eff6ff; color: #0067b1; }
    .hd-cm-zona.ocupada { cursor: progress; }
    .hd-cm-zona .material-icons { font-size: 26px; }
    .hd-cm-zona small { font-size: 11px; font-weight: 600; color: #94a3b8; }

    /* Avance de la cola. Oculto mientras no haya nada que contar. */
    .hd-cm-avance { display: flex; align-items: center; gap: 8px; font-size: 11.5px; color: #64748b; font-weight: 700; }
    .hd-cm-avance[hidden] { display: none; }
    .hd-cm-barra { flex: 1 1 auto; height: 6px; border-radius: 99px; background: #e2e8f0; overflow: hidden; }
    .hd-cm-barra i { display: block; height: 100%; width: 0; background: #0067b1; transition: width .25s; }

    /* Dónde mirar después. Se pinta al terminar la cola. */
    .hd-cm-nota { font-size: 11.5px; line-height: 1.45; color: #475569; background: #f8fafc;
                  border: 1px solid #e2e8f0; border-radius: 10px; padding: 9px 11px; }
    .hd-cm-nota[hidden] { display: none; }
    .hd-cm-nota b { color: #0f172a; }
</style>

<script>
(function () {
    var RUTAS = { analizar: @json(route('historial-documentos.carga-masiva.analizar')) };

    // `turno` descarta los resultados de una tanda ya cancelada: cerrar el modal con la cola a
    // medias no debe seguir pintando ni avisar al terminar.
    var estado = { turno: 0, corriendo: false, hechos: 0, cierre: null };

    function $(id) { return document.getElementById(id); }

    /** El tipo elegido. '' = reconocerlo solo (lo normal: así se suelta un montón mezclado). */
    function tipoElegido() {
        var v = document.querySelector('#hdCmTipo [data-filter-value]');
        return v ? v.value : '';
    }

    /**
     * El desplegable del tipo, con el MISMO componente que los filtros de la pantalla
     * (.custom-dropdown de uicomponents.js: buscador dentro, aspa para limpiar y lista
     * desplegable). Sus manejadores están delegados en document, así que funcionan aunque
     * este trozo se cree a mano después de cargar la página.
     *
     * Los cuatro de siempre se reconocen por lo que dice el propio PDF; el certificado y la
     * compraventa no traen un rótulo fijo, así que esos hay que elegirlos.
     */
    function desplegableTipo() {
        // La lista sale de PHP (CargaMasivaDocumentos::NOMBRES), que es la única fuente: si allí
        // se añade un documento, aquí aparece solo. Antes estaba escrita a mano en los dos sitios.
        var TIPOS = [['', 'Reconocerlo solo']].concat(
            Object.entries(@json(\App\Services\CargaMasivaDocumentos::NOMBRES)));
        var opciones = TIPOS.map(function (t, i) {
            return '<div class="dropdown-item' + (i === 0 ? ' selected' : '') + '" data-value="' + t[0] + '" data-label="' + t[1] + '"' +
                   ' onclick="window.selectOption(\'hdCmTipo\', this.dataset.value, this.dataset.label)">' + t[1] + '</div>';
        }).join('');

        return '<div class="custom-dropdown" id="hdCmTipo" data-filter-type="tipo" data-default-label="Reconocerlo solo" style="width:100%;">' +
            '<input type="hidden" data-filter-value value="">' +
            '<div class="dropdown-trigger" style="background:#fbfcfd;border:1px solid #cbd5e0;border-radius:12px;height:45px;display:flex;align-items:center;justify-content:space-between;padding:0;width:100%;overflow:hidden;">' +
                '<div style="padding:0 10px;display:flex;align-items:center;color:var(--maquinaria-gray-text);">' +
                    '<i class="material-icons" style="font-size:18px;">search</i></div>' +
                '<input type="text" name="filter_search_dropdown" data-filter-search placeholder="Reconocerlo solo"' +
                    ' style="width:100%;border:none;background:transparent;padding:10px 5px;font-size:14px;outline:none;color:#4a5568;"' +
                    ' onkeyup="window.filterDropdownOptions(this)" autocomplete="off">' +
                '<div style="display:flex;align-items:center;padding-right:10px;">' +
                    '<i class="material-icons" data-clear-btn style="font-size:18px;color:#a0aec0;margin-right:5px;display:none;"' +
                       ' onclick="event.stopPropagation(); window.clearDropdownFilter(\'hdCmTipo\');" title="Limpiar">close</i></div>' +
            '</div>' +
            '<div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">' +
                '<div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">' + opciones + '</div>' +
            '</div>' +
        '</div>';
    }

    // ── El modal ──────────────────────────────────────────────────────────────

    function construir() {
        // Si quedaba un cierre automático pendiente del modal anterior, muere aquí: si no,
        // cerraría este.
        clearTimeout(estado.cierre);
        estado.cierre = null;
        if ($('hdCmOverlay')) $('hdCmOverlay').remove();
        var o = document.createElement('div');
        o.id = 'hdCmOverlay';
        o.innerHTML =
            '<div class="hd-cm-modal" role="dialog" aria-modal="true" aria-label="Carga masiva de documentos">' +
                '<div class="hd-cm-head">' +
                    '<div class="hd-cm-head-ic"><i class="material-icons">cloud_upload</i></div>' +
                    '<div class="hd-cm-head-txt">' +
                        '<h2>Carga masiva de documentos</h2>' +
                        '<p>Aparecen en la tabla para aplicarlos.</p>' +
                    '</div>' +
                    '<button type="button" class="hd-cm-cerrar" id="hdCmCerrar" title="Cerrar"><i class="material-icons">close</i></button>' +
                '</div>' +
                '<div class="hd-cm-tools">' +
                    desplegableTipo() +
                    '<div class="hd-cm-zona" id="hdCmZona">' +
                        '<i class="material-icons">upload_file</i>' +
                        '<span>Suelta los PDF aquí</span>' +
                        '<small>o haz clic para elegirlos</small>' +
                    '</div>' +
                    '<input type="file" id="hdCmInput" accept="application/pdf" multiple hidden>' +
                    '<div class="hd-cm-avance" id="hdCmAvance" hidden>' +
                        '<span id="hdCmAvanceTxt">Leyendo…</span>' +
                        '<div class="hd-cm-barra"><i id="hdCmBarra"></i></div>' +
                    '</div>' +
                    '<div class="hd-cm-nota" id="hdCmNota" hidden></div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(o);
        enlazar(o);
    }

    function enlazar(o) {
        var zona = $('hdCmZona'), input = $('hdCmInput');

        $('hdCmCerrar').addEventListener('click', cerrar);
        o.addEventListener('click', function (e) { if (e.target === o) cerrar(); });

        zona.addEventListener('click', function () { if (!estado.corriendo) input.click(); });
        input.addEventListener('change', function () { encolar(input.files); input.value = ''; });

        ['dragenter', 'dragover'].forEach(function (ev) {
            zona.addEventListener(ev, function (e) { e.preventDefault(); if (!estado.corriendo) zona.classList.add('encima'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            zona.addEventListener(ev, function (e) { e.preventDefault(); zona.classList.remove('encima'); });
        });
        zona.addEventListener('drop', function (e) {
            if (!estado.corriendo && e.dataTransfer) encolar(e.dataTransfer.files);
        });
    }

    function cerrar() {
        // Con la cola a medias se avisa: lo que falte NO se sube. Lo ya leído sí está en la
        // tabla, así que no se pierde nada de lo hecho.
        if (estado.corriendo && !window.confirm('Todavía se están subiendo archivos. ¿Cerrar y dejar los que faltan sin subir?')) return;
        // El temporizador del cierre automático se cancela SIEMPRE: si no, seguía vivo y podía
        // cerrar un modal reabierto, o preguntar "¿cerrar?" en medio de una tanda nueva.
        clearTimeout(estado.cierre);
        estado.cierre = null;
        estado.turno++;
        estado.corriendo = false;
        var subio = estado.hechos > 0;
        estado.hechos = 0;          // que la próxima vez no herede el conteo de esta
        var o = $('hdCmOverlay');
        if (o) o.remove();
        // Ya sin el modal delante: la tabla de esta misma pantalla se refresca para que salgan
        // las filas nuevas sin tener que recargar a mano.
        if (subio && typeof window.cpdfFiltrar === 'function') window.cpdfFiltrar();
    }

    // ── La cola de subida ─────────────────────────────────────────────────────

    function encolar(archivos) {
        var pdfs = Array.prototype.filter.call(archivos || [], function (a) { return /\.pdf$/i.test(a.name); });
        if (!pdfs.length) { window.toast('Solo se aceptan archivos PDF', 'error'); return; }

        var tipo = tipoElegido();
        // Soltar algo durante la cuenta atrás del cierre la cancela: el modal se queda.
        clearTimeout(estado.cierre);
        estado.cierre = null;
        var turno = ++estado.turno;
        estado.corriendo = true;
        bloquear(true);

        // `perdidos` son los que NO llegaron a Drive: esos no dejan fila en la tabla (la fila se
        // identifica por el archivo de Drive), así que si no se nombran aquí desaparecen sin que
        // nadie se entere. Los demás, lean o no, sí salen en la tabla con su motivo.
        var i = 0, hechos = 0, perdidos = [];
        estado.hechos = 0;
        var siguiente = function () {
            if (turno !== estado.turno) return;              // se cerró el modal: se abandona
            if (i >= pdfs.length) { terminar(hechos, perdidos); return; }

            var archivo = pdfs[i++];
            avance(i, pdfs.length, archivo.name);
            window.apiPostForm(RUTAS.analizar, { file: archivo, tipo: tipo }, 'No se pudo subir el archivo.')
                .then(function (b) {
                    // Sin enlace de Drive no hay fila: cuenta como perdido, no como hecho. Y se
                    // guarda el MOTIVO que manda el servidor ("el PDF esta incompleto: vuelve a
                    // escanearlo"), que es lo que dice QUE hacer.
                    if (b && b.propuesta && b.propuesta.link) { hechos++; estado.hechos++; }
                    else perdidos.push({ nombre: archivo.name, motivo: (b && b.propuesta && b.propuesta.aviso) || '' });
                })
                .catch(function (e) { perdidos.push({ nombre: archivo.name, motivo: (e && e.message) || '' }); })
                .then(function () { if (turno === estado.turno) siguiente(); });
        };
        siguiente();
    }

    function avance(n, total, nombre) {
        var caja = $('hdCmAvance');
        if (!caja) return;
        caja.hidden = false;
        $('hdCmAvanceTxt').textContent = 'Leyendo ' + n + ' de ' + total;
        $('hdCmBarra').style.width = Math.round(((n - 1) / total) * 100) + '%';
        // textContent, no innerHTML: el nombre del archivo lo pone el usuario.
        var zona = $('hdCmZona');
        if (zona) zona.querySelector('span').textContent = nombre;
    }

    // Mientras se sube, ni se cambia el tipo ni se sueltan más archivos: la tanda ya salió
    // con el tipo que tenía. El desplegable de los filtros no se puede "deshabilitar" como un
    // <select>, así que se apaga con opacidad y se le quitan los eventos.
    function bloquear(si) {
        var zona = $('hdCmZona'), tipo = $('hdCmTipo');
        if (zona) zona.classList.toggle('ocupada', si);
        if (tipo) tipo.classList.toggle('hd-cm-apagado', si);
    }

    function terminar(hechos, perdidos) {
        estado.corriendo = false;
        bloquear(false);
        if ($('hdCmBarra')) $('hdCmBarra').style.width = '100%';
        if ($('hdCmAvanceTxt')) $('hdCmAvanceTxt').textContent = 'Listo';
        var zona = $('hdCmZona');
        if (zona) zona.querySelector('span').textContent = 'Suelta los PDF aquí';

        var nota = $('hdCmNota');
        if (nota) {
            nota.hidden = false;
            // Los perdidos van CON NOMBRE: son los únicos que no dejan rastro en la tabla, así
            // que si no se leen aquí no hay dónde encontrarlos. Se escriben con textContent
            // (el nombre lo pone el usuario) dentro de su propio renglón.
            nota.innerHTML = '<b>' + hechos + '</b> en la tabla. Búscalos como <b>Por aplicar</b>.'
                + (perdidos.length ? '' : '<br>Cerrando…');
            if (perdidos.length) {
                var mal = document.createElement('div');
                mal.style.cssText = 'margin-top:6px;color:#b91c1c;font-weight:700;';
                mal.textContent = 'NO se subieron:';
                perdidos.forEach(function (p) {
                    var li = document.createElement('div');
                    li.style.cssText = 'margin-top:3px;font-weight:600;';
                    // textContent: el nombre del archivo lo pone el usuario.
                    li.textContent = '· ' + p.nombre + (p.motivo ? ' — ' + p.motivo : '');
                    mal.appendChild(li);
                });
                nota.appendChild(mal);
            }
        }
        window.toast(perdidos.length
            ? (perdidos.length + ' archivo(s) NO se subieron')
            : (hechos + ' en la tabla de documentos'), perdidos.length ? 'error' : 'success');

        // Si todo subió, el modal se cierra SOLO: ya no hay nada que mirar aquí, lo que hay que
        // ver está en la tabla. Si algo se perdió NO se cierra, porque esos nombres solo están
        // escritos aquí: cerrarlo sería tragarse el único aviso.
        if (!perdidos.length) estado.cierre = setTimeout(cerrar, 1200);

        // La tabla se refresca al CERRAR, no aquí: cpdfFiltrar recarga la página por la SPA y
        // el modal se quedaba encima de lo recargado hasta que alguien lo cerrara a mano.
    }

    window.abrirCargaMasiva = function () { construir(); };
})();
</script>
@endcan
