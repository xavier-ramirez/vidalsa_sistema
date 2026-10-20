{{-- Carga masiva de documentos: la abre el menú Acciones (partials/acciones).

     ESTE MODAL SOLO SIRVE PARA SOLTAR ARCHIVOS. El estado de cada PDF —de qué ficha es, si
     falta la fecha, si no se pudo leer— se ve en la tabla de "Revisión de documentos", que es
     la misma donde se ve lo que lee la tarea de la noche. Antes había aquí una segunda lista
     con su propio "Aplicar" y eran dos tablas de documentos en el mismo módulo (pedido
     23-09-2026: una sola). Lo que coincide se enlaza solo (01-10-2026: sin botón de Aplicar);
     descartar se hace desde esa tabla.

     Al soltar los PDF el modal se CIERRA y se suben en segundo plano, uno por petición, sin
     tapar la pantalla: un aviso abajo en el centro (#hdCmProgreso) dice por cuál va, y se puede seguir
     usando cualquier parte de la aplicación (pedido del cliente, 20-10-2026: antes el spinner
     la bloqueaba minutos y, al recargar, la tanda se perdía a medias). Recargar o cerrar la
     pestaña con una tanda a medias pide confirmación. La LECTURA —el OCR de Google Drive,
     ~8 s por PDF— sigue en el servidor (ColaCargaMasiva) y cada resultado aparece en la tabla.

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
       encima del modal. */
    .hd-cm-tools .custom-dropdown { width: 100%; }
    .hd-cm-tools .dropdown-content { z-index: 2600; }
    .hd-cm-zona { min-width: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px;
                  height: 110px; border: 2px dashed #cbd5e1; border-radius: 10px; background: #f8fafc; color: #64748b;
                  font-size: 12.5px; font-weight: 600; cursor: pointer; text-align: center; padding: 0 12px;
                  transition: border-color .15s, background .15s; }
    /* El realce azul SOLO cuando la zona acepta archivos. El :not() lo deja fuera de la zona
       bloqueada en los dos casos —pasar el ratón y arrastrar algo encima—, sin una segunda regla
       que vuelva a pintar los mismos colores para deshacerlo. */
    .hd-cm-zona:not(.hd-cm-bloqueada):hover,
    .hd-cm-zona:not(.hd-cm-bloqueada).encima { border-color: #0067b1; background: #eff6ff; color: #0067b1; }
    /* Sin tipo elegido no se puede cargar: la zona se ve apagada y no responde al pasar por
       encima, para que se note que el paso que falta es el de arriba. */
    .hd-cm-zona.hd-cm-bloqueada { opacity: 0.45; cursor: not-allowed; border-style: solid; }
    .hd-cm-zona .material-icons { font-size: 26px; }
    .hd-cm-zona small { font-size: 11px; font-weight: 600; color: #94a3b8; }
</style>

{{-- Estilos del aviso de la tanda (#hdCmProgreso). Aparte, porque pintarProgreso los lleva a
     <head>: el aviso sigue a la vista en otros módulos y este partial se va al navegar. --}}
<style id="hdCmProgresoCss">
    /* Aviso de la tanda en curso: abajo en el CENTRO (pedido del cliente, 20-10-2026), por
       encima de la página pero sin taparla; si el contador de selección está a la vista, se
       sube sobre él. Va colgado de <body>, así que sigue a la vista aunque se navegue a otro
       módulo. El ancho es el de su texto, para que la nota quepa en UNA línea. */
    #hdCmProgreso { position: fixed; left: 50%; transform: translateX(-50%); bottom: 16px; z-index: 9998;
                    width: max-content; min-width: 300px; max-width: min(680px, calc(100vw - 32px));
                    background: #1e293b; color: #e2e8f0; border-radius: 12px; padding: 10px 12px;
                    box-shadow: 0 10px 25px -5px rgba(0,0,0,.35); font-size: 12.5px; line-height: 1.4; }
    #hdCmProgreso .hd-cm-p-fila { display: flex; align-items: center; gap: 8px; }
    #hdCmProgreso .hd-cm-p-fila b { flex: 1; min-width: 0; color: #fff; font-size: 13px; }
    #hdCmProgreso .material-icons { font-size: 18px; }
    #hdCmProgreso .hd-cm-p-gira { animation: hdCmGira 1s linear infinite; }
    @keyframes hdCmGira { to { transform: rotate(360deg); } }
    #hdCmProgreso .hd-cm-p-x { background: none; border: none; color: #94a3b8; cursor: pointer; display: flex; padding: 0; }
    #hdCmProgreso .hd-cm-p-x:hover { color: #fff; }
    #hdCmProgreso .hd-cm-p-barra { height: 4px; margin: 8px 0 6px; border-radius: 2px; background: #334155; overflow: hidden; }
    #hdCmProgreso .hd-cm-p-barra i { display: block; height: 100%; background: #38bdf8; transition: width .3s; }
    body:has(.selection-floating-bar.active) #hdCmProgreso { bottom: 90px; }
    #hdCmProgreso small { display: block; color: #fff; }
    #hdCmProgreso .hd-cm-p-cuentas { margin-top: 4px; color: #fff; font-weight: 600; }
    #hdCmProgreso .hd-cm-p-ya { margin-top: 6px; color: #86efac; font-weight: 600; }
    #hdCmProgreso .hd-cm-p-mal { margin-top: 6px; color: #fca5a5; font-weight: 600; }
    #hdCmProgreso .hd-cm-p-ver { margin-top: 8px; width: 100%; border: none; border-radius: 8px; padding: 7px; cursor: pointer;
                                 background: #0067b1; color: #fff; font-family: inherit; font-size: 12.5px; font-weight: 700; }
    #hdCmProgreso .hd-cm-p-ver:hover { background: #00548f; }
</style>

<script>
(function () {
    var RUTAS = { analizar: @json(route('historial-documentos.carga-masiva.analizar')) };

    var RUTA_REVISION = @json(route('historial-documentos.index', ['pestana' => 'documentos']));
    // El oyente del desplegable del tipo (ver soltarOyenteTipo).
    var estado = { oyenteTipo: null };
    // La tanda en curso vive en WINDOW y no aquí: este script vuelve a correr en cada visita
    // al módulo, y la subida sigue mientras tanto (en otro módulo o en otra pestaña de este).
    // { corriendo, total, enviados, hechos, perdidos[], yaEstaban[], tipoNombre }
    var tanda = function () { return window.__hdCargaMasiva || null; };
    if (!window.__hdCargaAvisoSalida) {
        window.__hdCargaAvisoSalida = true;
        // Recargar o cerrar la pestaña con una tanda a medias la cortaría: el navegador pregunta.
        window.addEventListener('beforeunload', function (e) {
            var t = window.__hdCargaMasiva;
            if (t && t.corriendo) { e.preventDefault(); e.returnValue = ''; }
        });
    }

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
        // Fuera el modal anterior, si quedó, Y su oyente del tipo: vive en window, así que
        // sobrevive al nodo y se quedaría colgado, uno por cada reapertura.
        cerrar();
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
                '</div>' +
            '</div>';
        document.body.appendChild(o);
        enlazar(o);
        var t = tanda();
        if (t && t.corriendo) {
            var zona = $('hdCmZona');
            zona.classList.add('hd-cm-bloqueada');
            zona.title = '';
            zona.querySelector('span').textContent = 'Hay una carga en curso (' + t.enviados + ' de ' + t.total + ')';
            zona.querySelector('small').textContent = 'Espera a que termine para soltar más';
        }
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
            var t = tanda(), hay = !!tipoElegido() && !(t && t.corriendo);
            zona.classList.toggle('hd-cm-bloqueada', !hay);
            zona.title = hay ? '' : 'Elige primero qué documento vas a cargar';
        };
        // Se guarda para poder soltarlo (ver soltarOyenteTipo): el modal se reconstruye en cada
        // apertura y si no, los oyentes se irían acumulando en window.
        estado.oyenteTipo = sincronizarZona;
        window.addEventListener('dropdown-selection', sincronizarZona);
        sincronizarZona();

        zona.addEventListener('click', function () {
            if (tanda() && tanda().corriendo) return;
            if (!tipoElegido()) { window.toast('Elige primero qué documento vas a cargar', 'error'); return; }
            input.click();
        });
        input.addEventListener('change', function () { encolar(input.files); input.value = ''; });

        ['dragenter', 'dragover'].forEach(function (ev) {
            zona.addEventListener(ev, function (e) { e.preventDefault(); if (!(tanda() && tanda().corriendo) && tipoElegido()) zona.classList.add('encima'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            zona.addEventListener(ev, function (e) { e.preventDefault(); zona.classList.remove('encima'); });
        });
        zona.addEventListener('drop', function (e) {
            if ((tanda() && tanda().corriendo) || !e.dataTransfer) return;
            // encolar() vuelve a comprobar el tipo; aquí se corta antes para no darle vueltas a
            // los archivos soltados cuando ya se sabe que no van a ir a ninguna parte.
            if (!tipoElegido()) { window.toast('Elige primero qué documento vas a cargar', 'error'); return; }
            encolar(e.dataTransfer.files);
        });
    }

    function cerrar() {
        soltarOyenteTipo();
        var o = $('hdCmOverlay');
        if (o) o.remove();
    }

    // ── La subida, en segundo plano ──────────────────────────────────────────

    function encolar(archivos) {
        var pdfs = Array.prototype.filter.call(archivos || [], function (a) { return /\.pdf$/i.test(a.name); });
        if (!pdfs.length) { window.toast('Solo se aceptan archivos PDF', 'error'); return; }
        var tipo = tipoElegido();
        if (!tipo) { window.toast('Elige primero qué documento vas a cargar', 'error'); return; }
        if (tanda() && tanda().corriendo) { window.toast('Ya hay una carga en curso: espera a que termine.', 'error'); return; }

        var nombre = (document.querySelector('#hdCmTipo .dropdown-item[data-value="' + tipo + '"]') || {}).dataset;
        // `perdidos` son los que NO llegaron a Drive: esos no dejan fila en la tabla (la fila se
        // identifica por el archivo de Drive), así que si no se nombran en el aviso desaparecen
        // sin que nadie se entere. Los demás se leen en el servidor y salen en la tabla.
        // `yaEstaban`: el mismo PDF ya está puesto en su ficha y el servidor no lo vuelve a subir
        // (ya_cargado): no es un error, se cuenta aparte.
        var t = window.__hdCargaMasiva = { corriendo: true, total: pdfs.length, enviados: 0, hechos: 0, perdidos: [], yaEstaban: [],
                                           tipoNombre: (nombre && nombre.label) || '' };
        cerrar();            // el modal se va: la subida sigue sin tapar nada
        pintarProgreso();

        var siguiente = function () {
            if (t.enviados >= t.total) { t.corriendo = false; pintarProgreso(); return; }
            var archivo = pdfs[t.enviados++];
            pintarProgreso();
            window.apiPostForm(RUTAS.analizar, { file: archivo, tipo: tipo }, 'No se pudo subir el archivo.')
                .then(function (b) {
                    // Sin enlace de Drive no hay fila: cuenta como perdido, con el MOTIVO del servidor
                    // ("el PDF esta incompleto: vuelve a escanearlo"), que dice QUE hacer.
                    if (b && b.propuesta && b.propuesta.link) t.hechos++;
                    else if (b && b.propuesta && b.propuesta.ya_cargado) t.yaEstaban.push(archivo.name);
                    else t.perdidos.push({ nombre: archivo.name, motivo: (b && b.propuesta && b.propuesta.aviso) || '' });
                })
                .catch(function (e) { t.perdidos.push({ nombre: archivo.name, motivo: (e && e.message) || '' }); })
                .then(siguiente);
        };
        siguiente();
    }

    // El aviso de abajo: por cuál va y, al terminar, cuántos subieron, cuáles no y un botón para
    // ver el resultado en la tabla de Revisión de documentos. Se reconstruye entero cada vez
    // (textContent en todo: los nombres de archivo los pone el usuario).
    function pintarProgreso() {
        var t = tanda(); if (!t) return;
        // Sus estilos, a <head> (una vez): así no se van con la vista al navegar.
        var css = document.getElementById('hdCmProgresoCss');
        if (css && css.parentNode !== document.head) document.head.appendChild(css);
        var caja = document.getElementById('hdCmProgreso');
        if (!caja) { caja = document.createElement('div'); caja.id = 'hdCmProgreso'; document.body.appendChild(caja); }
        caja.textContent = '';
        var el = function (tag, clase, texto) { var x = document.createElement(tag); if (clase) x.className = clase; if (texto != null) x.textContent = texto; return x; };
        var fila = el('div', 'hd-cm-p-fila');
        fila.appendChild(el('i', 'material-icons' + (t.corriendo ? ' hd-cm-p-gira' : ''), t.corriendo ? 'autorenew' : (t.perdidos.length ? 'warning_amber' : 'cloud_done')));
        fila.appendChild(el('b', null, (t.corriendo ? 'Subiendo ' + t.enviados + ' de ' + t.total : t.total + ' adjuntado(s)')
            + (t.tipoNombre ? ' · ' + t.tipoNombre : '')));
        if (!t.corriendo) {
            var x = el('button', 'hd-cm-p-x'); x.type = 'button'; x.title = 'Cerrar';
            x.appendChild(el('i', 'material-icons', 'close'));
            x.addEventListener('click', function () { caja.remove(); window.__hdCargaMasiva = null; });
            fila.appendChild(x);
        }
        caja.appendChild(fila);
        var barra = el('div', 'hd-cm-p-barra'), i = el('i');
        i.style.width = Math.round(100 * (t.corriendo ? t.enviados - 1 : t.total) / t.total) + '%';
        barra.appendChild(i); caja.appendChild(barra);
        if (t.corriendo || t.hechos) caja.appendChild(el('small', null, t.corriendo
            ? 'Puedes seguir usando el sistema; no recargues ni cierres esta pestaña hasta que termine.'
            : 'Se están leyendo en el servidor: cada uno sale en Revisión de documentos, enlazado o con el porqué.'));
        // Las cuentas, que suman los adjuntados: así se ve enseguida si falta alguno.
        if (!t.corriendo) {
            caja.appendChild(el('div', 'hd-cm-p-cuentas', t.hechos + ' subido(s) · ' + t.yaEstaban.length + ' ya estaban cargados · '
                + t.perdidos.length + ' no se subieron'));
        }
        if (t.yaEstaban.length) {
            var ya = el('div', 'hd-cm-p-ya', 'Ya estaban cargados, no se volvieron a subir (' + t.yaEstaban.length + '):');
            ya.appendChild(el('div', null, t.yaEstaban.join(', ')));
            caja.appendChild(ya);
        }
        if (t.perdidos.length) {
            var mal = el('div', 'hd-cm-p-mal', 'NO se subieron (' + t.perdidos.length + '):');
            t.perdidos.forEach(function (p) { mal.appendChild(el('div', null, '· ' + p.nombre + (p.motivo ? ' — ' + p.motivo : ''))); });
            caja.appendChild(mal);
        }
        if (!t.corriendo && t.hechos) {
            var ver = el('button', 'hd-cm-p-ver', 'Ver en Revisión de documentos'); ver.type = 'button';
            ver.addEventListener('click', function () {
                caja.remove(); window.__hdCargaMasiva = null;
                if (typeof window.navigateTo === 'function') window.navigateTo(RUTA_REVISION); else window.location.href = RUTA_REVISION;
            });
            caja.appendChild(ver);
        }
    }
    window.abrirCargaMasiva = function () { construir(); };
})();
</script>
@endcan
