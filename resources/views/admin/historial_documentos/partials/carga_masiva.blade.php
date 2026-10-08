{{-- Carga masiva de documentos: la abre el menú Acciones (partials/acciones).

     ESTE MODAL SOLO SIRVE PARA SOLTAR ARCHIVOS. El estado de cada PDF —de qué ficha es, si
     falta la fecha, si no se pudo leer— se ve en la tabla de "Revisión de documentos", que es
     la misma donde se ve lo que lee la tarea de la noche. Antes había aquí una segunda lista
     con su propio "Aplicar" y eran dos tablas de documentos en el mismo módulo (pedido
     23-09-2026: una sola). Lo que coincide se enlaza solo (01-10-2026: sin botón de Aplicar);
     descartar se hace desde esa tabla.

     Aquí solo se SUBEN (con el spinner de la aplicación, un archivo por petición) y el modal se
     cierra. La LECTURA —el OCR de Google Drive, ~8 s por PDF— sigue en segundo plano en el
     servidor (ColaCargaMasiva) y cada resultado aparece en la tabla.

     Las reglas de seguridad NO viven aquí sino en el servidor (CargaMasivaDocumentos): esta
     pantalla solo las refleja.

     Mismo permiso que la ruta y el controlador: 'docs.carga.masiva', que es EXCLUSIVO (ni
     super.admin lo hereda). Sin él no se baja ni el HTML ni el JS, y window.abrirCargaMasiva
     ni siquiera existe. --}}
@can('docs.carga.masiva')
<style>
    /* Tarjeta blanca con el MISMO encabezado que la Papelera (partials/papelera, .hd-pap-head):
       los dos modales salen del mismo menú Acciones y se veían de dos familias distintas. */
    #hdCmOverlay { position: fixed; inset: 0; background: rgba(15,23,42,0.45); z-index: 2500; display: flex; justify-content: center; align-items: center; }
    /* SIN overflow:hidden: la lista del desplegable del tipo se sale de la tarjeta y con él
       quedaba cortada por el borde de abajo (solo se veían 3 de las 7 opciones). Las esquinas
       redondas del encabezado las pone él mismo. */
    .hd-cm-modal { background: #fff; border-radius: 14px; width: 94%; max-width: 520px;
                   display: flex; flex-direction: column;
                   box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }
    /* Ícono y título van CENTRADOS juntos, como una sola pieza: los centra el
       justify-content del padre. La X se saca del flujo (position:absolute) para que no
       desplace ese centro. El padding lateral de 44 px deja sitio para la X sin que el
       título se le monte encima en pantallas estrechas. */
    .hd-cm-head { position: relative; padding: 12px 44px; display: flex; align-items: center;
                  justify-content: center; gap: 8px; border-radius: 14px 14px 0 0;
                  background: #1e293b; color: #fff; }
    .hd-cm-head .material-icons { font-size: 18px; color: #fff; }
    .hd-cm-head h2 { margin: 0; font-size: 14px; font-weight: 700; color: #fff; }
    .hd-cm-cerrar { position: absolute; right: 12px; background: transparent; border: none; color: #fff;
                    cursor: pointer; opacity: 0.7; display: flex; padding: 2px; }
    .hd-cm-cerrar:hover { opacity: 1; }

    /* El cuerpo entero: tipo, zona de soltar y lo que no se subió, uno debajo del otro. */
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
    /* El realce azul SOLO cuando la zona acepta archivos. El :not() lo deja fuera de la zona
       bloqueada en los dos casos —pasar el ratón y arrastrar algo encima—, sin una segunda regla
       que vuelva a pintar los mismos colores para deshacerlo. */
    .hd-cm-zona:not(.hd-cm-bloqueada):hover,
    .hd-cm-zona:not(.hd-cm-bloqueada).encima { border-color: #0067b1; background: #eff6ff; color: #0067b1; }
    .hd-cm-zona.ocupada { cursor: progress; }
    /* Sin tipo elegido no se puede cargar: la zona se ve apagada y no responde al pasar por
       encima, para que se note que el paso que falta es el de arriba. */
    .hd-cm-zona.hd-cm-bloqueada { opacity: 0.45; cursor: not-allowed; border-style: solid; }
    .hd-cm-zona .material-icons { font-size: 26px; }
    .hd-cm-zona small { font-size: 11px; font-weight: 600; color: #94a3b8; }

    /* Los que NO se subieron. Solo se pinta si hubo alguno (si no, el modal ya se cerró). */
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
    var estado = { turno: 0, corriendo: false, hechos: 0, oyenteTipo: null };

    function $(id) { return document.getElementById(id); }

    /**
     * Suelta el oyente que sigue al desplegable del tipo. Vive en window —el desplegable avisa
     * por ahí— así que no se va solo con el modal: hay que quitarlo tanto al cerrar como al
     * reconstruirlo. Fuente única para los dos sitios.
     */
    function soltarOyenteTipo() {
        if (!estado.oyenteTipo) return;
        window.removeEventListener('dropdown-selection', estado.oyenteTipo);
        estado.oyenteTipo = null;
    }

    /**
     * El tipo elegido. Es OBLIGATORIO: se sueltan PDF de un solo tipo por tanda ("estos son
     * títulos") y el servidor comprueba que cada uno lo sea; el que resulte ser otro documento
     * queda en la tabla como "Otro documento" y no se asocia a nada. '' = todavía no se eligió.
     */
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
     * Sin opción de "reconocerlo solo" (pedido 30-09-2026): se elige qué documento se carga.
     */
    function desplegableTipo() {
        // La lista sale de PHP (CargaMasivaDocumentos::NOMBRES), que es la única fuente: si allí
        // se añade un documento, aquí aparece solo. Antes estaba escrita a mano en los dos sitios.
        var TIPOS = Object.entries(@json(\App\Services\CargaMasivaDocumentos::NOMBRES));
        var opciones = TIPOS.map(function (t) {
            return '<div class="dropdown-item" data-value="' + t[0] + '" data-label="' + t[1] + '"' +
                   ' onclick="window.selectOption(\'hdCmTipo\', this.dataset.value, this.dataset.label)">' + t[1] + '</div>';
        }).join('');

        return '<div class="custom-dropdown" id="hdCmTipo" data-filter-type="tipo" data-default-label="¿Qué documento vas a cargar?" style="width:100%;">' +
            '<input type="hidden" data-filter-value value="">' +
            '<div class="dropdown-trigger" style="background:#fbfcfd;border:1px solid #cbd5e0;border-radius:12px;height:45px;display:flex;align-items:center;justify-content:space-between;padding:0;width:100%;overflow:hidden;">' +
                '<div style="padding:0 10px;display:flex;align-items:center;color:var(--maquinaria-gray-text);">' +
                    '<i class="material-icons" style="font-size:18px;">search</i></div>' +
                '<input type="text" name="filter_search_dropdown" data-filter-search placeholder="¿Qué documento vas a cargar?"' +
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
        // Al arrancarle el DOM al modal anterior hay que soltar SU oyente: vive en window, así
        // que sobrevive al nodo y se quedaría colgado apuntando a una zona que ya no está en la
        // página, uno por cada reapertura. (Aquí no vale llamar a cerrar(): con una tanda a medias
        // pregunta, y si el usuario dice que no, se queda sin cerrar y acabaríamos con dos.)
        soltarOyenteTipo();
        if ($('hdCmOverlay')) $('hdCmOverlay').remove();
        var o = document.createElement('div');
        o.id = 'hdCmOverlay';
        o.innerHTML =
            '<div class="hd-cm-modal" role="dialog" aria-modal="true" aria-label="Carga masiva de documentos">' +
                '<div class="hd-cm-head">' +
                    '<i class="material-icons">cloud_upload</i>' +
                    '<h2>Carga masiva de documentos</h2>' +
                    '<button type="button" class="hd-cm-cerrar" id="hdCmCerrar" title="Cerrar"><i class="material-icons">close</i></button>' +
                '</div>' +
                '<div class="hd-cm-tools">' +
                    desplegableTipo() +
                    // Nace BLOQUEADA: primero se elige qué documento se carga. Antes dejaba abrir
                    // el explorador y soltar archivos, y solo entonces avisaba de que faltaba el
                    // tipo — con la tanda ya elegida (pedido del cliente, 30-09-2026).
                    '<div class="hd-cm-zona hd-cm-bloqueada" id="hdCmZona">' +
                        '<i class="material-icons">upload_file</i>' +
                        '<span>Suelta los PDF aquí</span>' +
                        '<small>o haz clic para elegirlos</small>' +
                    '</div>' +
                    '<input type="file" id="hdCmInput" accept="application/pdf" multiple hidden>' +
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

        // La zona sigue al desplegable: mientras no haya tipo, ni abre el explorador ni acepta
        // nada soltado. Se engancha al evento 'dropdown-selection' que dispara selectOption(),
        // por donde pasan las DOS formas de cambiar el tipo: elegir una opción y limpiarla con
        // el aspa. (Escuchar el clic del desplegable no valía: el aspa hace stopPropagation, así
        // que al limpiar la zona se quedaba con pinta de habilitada sin tipo elegido.)
        var sincronizarZona = function (e) {
            if (e && e.detail && e.detail.dropdownId !== 'hdCmTipo') return;
            var hay = !!tipoElegido();
            zona.classList.toggle('hd-cm-bloqueada', !hay);
            zona.title = hay ? '' : 'Elige primero qué documento vas a cargar';
        };
        // Se guarda para poder soltarlo (ver soltarOyenteTipo): el modal se reconstruye en cada
        // apertura y si no, los oyentes se irían acumulando en window.
        estado.oyenteTipo = sincronizarZona;
        window.addEventListener('dropdown-selection', sincronizarZona);
        sincronizarZona();

        zona.addEventListener('click', function () {
            if (estado.corriendo) return;
            if (!tipoElegido()) { window.toast('Elige primero qué documento vas a cargar', 'error'); return; }
            input.click();
        });
        input.addEventListener('change', function () { encolar(input.files); input.value = ''; });

        ['dragenter', 'dragover'].forEach(function (ev) {
            zona.addEventListener(ev, function (e) { e.preventDefault(); if (!estado.corriendo && tipoElegido()) zona.classList.add('encima'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            zona.addEventListener(ev, function (e) { e.preventDefault(); zona.classList.remove('encima'); });
        });
        zona.addEventListener('drop', function (e) {
            if (estado.corriendo || !e.dataTransfer) return;
            // encolar() vuelve a comprobar el tipo; aquí se corta antes para no darle vueltas a
            // los archivos soltados cuando ya se sabe que no van a ir a ninguna parte.
            if (!tipoElegido()) { window.toast('Elige primero qué documento vas a cargar', 'error'); return; }
            encolar(e.dataTransfer.files);
        });
    }

    function cerrar() {
        // Mientras se sube, el spinner de la aplicación tapa la pantalla: no se puede cerrar.
        if (estado.corriendo) return;
        estado.turno++;
        estado.corriendo = false;
        var subio = estado.hechos > 0;
        estado.hechos = 0;          // que la próxima vez no herede el conteo de esta
        soltarOyenteTipo();
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
        if (!tipo) { window.toast('Elige primero qué documento vas a cargar', 'error'); return; }
        var turno = ++estado.turno;
        estado.corriendo = true;
        bloquear(true);
        // El spinner de siempre de la aplicación mientras se suben (tapa también el modal).
        if (window.showPreloader) window.showPreloader();

        // `perdidos` son los que NO llegaron a Drive: esos no dejan fila en la tabla (la fila se
        // identifica por el archivo de Drive), así que si no se nombran aquí desaparecen sin que
        // nadie se entere. Los demás se leen en segundo plano y salen en la tabla con su motivo.
        var i = 0, hechos = 0, perdidos = [];
        estado.hechos = 0;
        var siguiente = function () {
            if (turno !== estado.turno) return;              // se cerró el modal: se abandona
            if (i >= pdfs.length) { terminar(hechos, perdidos); return; }

            var archivo = pdfs[i++];
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
        if (window.hidePreloader) window.hidePreloader();

        if (hechos) {
            window.toast(hechos + ' subido(s). Se están leyendo en el servidor: aparecerán en la tabla como '
                + 'enlazados (o, si algo no cuadra, con el porqué) en unos segundos; actualiza la tabla para verlos.', 'success');
        }
        // Todo subió: el modal se cierra ya. Lo que queda (leerlos) es del servidor.
        if (!perdidos.length) { cerrar(); return; }

        // Si algo NO se subió, el modal se queda: esos nombres solo están escritos aquí y
        // cerrarlo sería tragarse el único aviso.
        window.toast(perdidos.length + ' archivo(s) NO se subieron', 'error');
        var zona = $('hdCmZona');
        if (zona) zona.querySelector('span').textContent = 'Suelta los PDF aquí';
        var nota = $('hdCmNota');
        if (!nota) return;
        nota.hidden = false;
        nota.textContent = '';
        var mal = document.createElement('div');
        mal.style.cssText = 'color:#b91c1c;font-weight:700;';
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

    window.abrirCargaMasiva = function () { construir(); };
})();
</script>
@endcan
