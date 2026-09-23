{{-- Carga masiva de documentos: la abre el menú Acciones (partials/acciones). Se sueltan
     varios PDF y cada uno se lee y se propone a su equipo; nada se escribe en una ficha
     hasta que la fila se aplica.

     Por qué un archivo por petición: el texto lo saca el OCR de Google Drive (~8 s por
     PDF) y treinta en una sola petición se caerían por timeout. La cola los manda de uno
     en uno y cada fila se pinta en cuanto vuelve — así se ve avanzar y se puede corregir
     sobre la marcha sin esperar al final.

     Las reglas de seguridad NO viven aquí sino en el servidor (CargaMasivaDocumentos):
     esta pantalla solo las refleja. --}}
@can('super.admin')
<style>
    /* Mismo lenguaje que el aviso de cierre de sesión (partials/session_timeout): tarjeta
       blanca de 16 px de radio, borde suave y sombra larga. Sin barra oscura arriba: el
       título va dentro de la tarjeta, con su ícono en una pastilla azul clara. */
    #hdCmOverlay { position: fixed; inset: 0; background: rgba(15,23,42,0.45); z-index: 2500; display: flex; justify-content: center; align-items: center; }
    /* Más angosto (pedido 22-09-2026): con los controles en columna, 880 px dejaban una franja
       vacía enorme a la derecha. */
    .hd-cm-modal { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; width: 94%; max-width: 520px;
                   max-height: 86vh; display: flex; flex-direction: column; overflow: hidden;
                   box-shadow: 0 20px 45px -12px rgba(15,23,42,0.30); }
    /* Encabezado CON COLOR (el mismo degradado del botón principal y del aviso de cierre de
       sesión): el modal era todo blanco y no se distinguía del fondo de la página. */
    .hd-cm-head { padding: 11px 14px; display: flex; align-items: center; gap: 10px;
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

    /* Barra de arriba: tipo + zona de soltar. */
    .hd-cm-tools { padding: 10px 14px; display: flex; flex-direction: column; gap: 8px; flex-shrink: 0; }
    /* Tipo, zona para soltar y modo ensayo UNO DEBAJO DEL OTRO (pedido 22-09-2026): en fila la
       zona quedaba apretada entre los dos y el ensayo se partía a otra línea según el ancho. */
    .hd-cm-fila { display: flex; flex-direction: column; align-items: stretch; gap: 10px; }
    .hd-cm-campo { display: flex; flex-direction: column; gap: 3px; }
    .hd-cm-rot { font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .7px; color: #94a3b8; white-space: nowrap; }
    .hd-cm-select { width: 100%; height: 34px; padding: 0 8px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; font: inherit; font-size: 12.5px; color: #334155; cursor: pointer; }
    .hd-cm-select:focus { outline: none; border-color: #0067b1; box-shadow: 0 0 0 3px rgba(0,103,177,.10); }
    .hd-cm-zona { min-width: 0; display: flex; align-items: center; justify-content: center; gap: 8px; height: 64px; border: 2px dashed #cbd5e1; border-radius: 10px; background: #f8fafc; color: #64748b; font-size: 12.5px; font-weight: 600; cursor: pointer; text-align: center; padding: 0 10px; transition: border-color .15s, background .15s; }
    .hd-cm-zona:hover, .hd-cm-zona.encima { border-color: #0067b1; background: #eff6ff; color: #0067b1; }
    .hd-cm-zona .material-icons { font-size: 22px; }

    /* Avance de la cola. Oculto mientras no haya nada que contar. */
    .hd-cm-avance { display: flex; align-items: center; gap: 8px; font-size: 11.5px; color: #64748b; font-weight: 700; }
    .hd-cm-avance[hidden] { display: none; }
    .hd-cm-barra { flex: 1 1 auto; height: 6px; border-radius: 99px; background: #e2e8f0; overflow: hidden; }
    .hd-cm-barra i { display: block; height: 100%; width: 0; background: #0067b1; transition: width .25s; }

    /* La lista de archivos, con el aspecto de las tablas de la app (.tabla-cabecera y
       .tabla-lista de estilos_globales.css): cabecera oscura en mayúsculas y filas separadas
       por una línea fina, sin tarjetas. La franja de color de la izquierda se queda: dice de un
       vistazo cómo salió cada PDF. */
    .hd-cm-lista-cab { display: flex; align-items: center; justify-content: space-between; gap: 10px;
                       margin: 10px 14px 0; padding: 9px 10px; border-radius: 8px; background: #1e293b;
                       color: #fff; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
    .hd-cm-list { overflow-y: auto; background: #fff; padding: 0 14px 10px; flex: 1; min-height: 120px; font-size: 13px; }
    .hd-cm-row { background: #fff; border-bottom: 1px solid #f1f5f9; border-left: 3px solid transparent; padding: 7px 10px; }
    .hd-cm-row[data-estado="cola"]       { border-left-color: #cbd5e1; }
    .hd-cm-row[data-estado="leyendo"]    { border-left-color: #0067b1; }
    .hd-cm-row[data-estado="listo"]      { border-left-color: #10b981; }
    .hd-cm-row[data-estado="revisar"]    { border-left-color: #f59e0b; }
    .hd-cm-row[data-estado="sin_equipo"] { border-left-color: #f59e0b; }
    .hd-cm-row[data-estado="ilegible"]   { border-left-color: #dc2626; }
    .hd-cm-row[data-estado="aplicado"]   { border-left-color: #10b981; background: #f0fdf4; }
    .hd-cm-row[data-estado="error"]      { border-left-color: #dc2626; background: #fef2f2; }

    .hd-cm-cab { display: flex; align-items: center; gap: 8px; }
    .hd-cm-arch { flex: 1 1 auto; min-width: 0; font-size: 12px; font-weight: 700; color: #0f172a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .hd-cm-chip { flex: 0 0 auto; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .5px; padding: 3px 8px; border-radius: 99px; background: #f1f5f9; color: #475569; white-space: nowrap; }
    .hd-cm-chip.ok   { background: #dcfce7; color: #15803d; }
    .hd-cm-chip.avisa{ background: #fef3c7; color: #b45309; }
    .hd-cm-chip.mal  { background: #fee2e2; color: #b91c1c; }
    .hd-cm-cuerpo { margin-top: 7px; display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; }
    .hd-cm-equipo { flex: 1 1 210px; min-width: 0; font-size: 12px; color: #334155; line-height: 1.35; }
    .hd-cm-equipo b { color: #0f172a; }
    .hd-cm-equipo small { display: block; color: #64748b; font-size: 11px; }
    .hd-cm-fecha { height: 32px; padding: 0 8px; border: 1px solid #e2e8f0; border-radius: 8px; font: inherit; font-size: 12px; color: #334155; }
    .hd-cm-pisar { display: inline-flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 700; color: #b45309; white-space: nowrap; cursor: pointer; }
    .hd-cm-aviso { margin-top: 6px; font-size: 11.5px; color: #b45309; line-height: 1.35; }
    .hd-cm-row[data-estado="ilegible"] .hd-cm-aviso, .hd-cm-row[data-estado="error"] .hd-cm-aviso { color: #b91c1c; }
    /* Sin archivos el aviso se centra en el hueco: si no, quedaba pegado arriba y el
       resto del modal era una mancha blanca vacía. */
    .hd-cm-vacio { height: 100%; min-height: 110px; display: flex; flex-direction: column;
                   align-items: center; justify-content: center; text-align: center;
                   color: #94a3b8; font-size: 12px; line-height: 1.5; }

    /* Pie en dos renglones: el resumen arriba, centrado, y los botones CENTRADOS debajo.
       Antes iban a los lados con el resumen empujándolos y quedaban descolgados. */
    .hd-cm-pie { padding: 9px 14px 11px; border-top: 1px solid #e2e8f0; display: flex; flex-direction: column;
                 align-items: center; gap: 7px; flex-shrink: 0; }
    .hd-cm-resumen { font-size: 11.5px; color: #64748b; font-weight: 700; text-align: center; }
    .hd-cm-botones { display: flex; align-items: center; justify-content: center; gap: 8px; }
    /* Comprimidos: 34 px en vez de 42, que era lo que más estiraba el modal a lo alto. */
    .hd-cm-btn { flex: 0 0 auto; height: 34px; padding: 0 14px; border: none; border-radius: 8px; font: inherit;
                 font-size: 12.5px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center;
                 justify-content: center; gap: 6px; transition: transform .15s, box-shadow .15s; }
    .hd-cm-btn .material-icons { font-size: 16px; }
    .hd-cm-btn.primario { background: linear-gradient(135deg,#00004d 0%,#0067b1 100%); color: #fff;
                          box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
    .hd-cm-btn.primario:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 6px 12px rgba(0,0,0,.15); }
    .hd-cm-btn.primario:disabled { background: #cbd5e1; box-shadow: none; cursor: not-allowed; }
    .hd-cm-btn.plano { background: #fff; border: 1px solid #e2e8f0; color: #64748b; }
    .hd-cm-btn.plano:hover { background: #f8fafc; }

    /* Interruptor "Modo ensayo": comprueba TODO y dice qué haría, sin escribir nada. */
    .hd-cm-ensayo { align-self: flex-start; display: inline-flex; align-items: center; gap: 8px; height: 34px; padding: 0 12px;
                    border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; cursor: pointer;
                    font-size: 12px; font-weight: 700; color: #64748b; white-space: nowrap; }
    .hd-cm-ensayo input { width: 15px; height: 15px; accent-color: #0067b1; cursor: pointer; margin: 0; }
    .hd-cm-ensayo.activo { background: #fffbeb; border-color: #fde68a; color: #b45309; }
    .hd-cm-row[data-estado="ensayo"] { border-left-color: #f59e0b; background: #fffbeb; }

    /* Teléfono: los controles de cada fila uno debajo del otro. */
    @media (max-width: 700px) {
        .hd-cm-cuerpo { flex-direction: column; align-items: stretch; }
        /* En columna, el flex-basis manda sobre la ALTURA: sin este reset el bloque del
           equipo se estiraba a 210 px y dejaba un hueco enorme dentro de cada fila. */
        .hd-cm-equipo { flex: 0 0 auto; }
        .hd-cm-fecha { width: 100%; }
        /* El resumen a su propio renglón: si no, los dos botones se parten en dos líneas. */
        .hd-cm-pie { flex-wrap: wrap; }
        .hd-cm-resumen { flex: 1 0 100%; }
    }
</style>

<script>
(function () {
    var esc = window.escapeHtml;   // helper central (dom_helpers.js)

    var RUTAS = {
        analizar:  @json(route('historial-documentos.carga-masiva.analizar')),
        aplicar:   @json(route('historial-documentos.carga-masiva.aplicar')),
        descartar: @json(route('historial-documentos.carga-masiva.descartar'))
    };

    // Los tipos que vencen: su fila no se puede aplicar sin fecha. Es la MISMA lista que
    // App\Support\DocumentacionDeEquipo::VENCIMIENTO; si allí se toca, aquí también.
    var VENCEN = { poliza: 1, rotc: 1, racda: 1 };

    // filas: una por archivo. `turno` descarta los resultados de una tanda ya cancelada
    // (cerrar el modal con la cola a medias no debe pintar sobre la siguiente).
    var estado = { filas: [], turno: 0, corriendo: false, ensayo: false };

    function $(id) { return document.getElementById(id); }

    // ── Pintado ───────────────────────────────────────────────────────────────

    function construir() {
        if ($('hdCmOverlay')) $('hdCmOverlay').remove();
        var o = document.createElement('div');
        o.id = 'hdCmOverlay';
        o.innerHTML =
            '<div class="hd-cm-modal" role="dialog" aria-modal="true" aria-label="Carga masiva de documentos">' +
                '<div class="hd-cm-head">' +
                    '<div class="hd-cm-head-ic"><i class="material-icons">cloud_upload</i></div>' +
                    '<div class="hd-cm-head-txt">' +
                        '<h2>Carga masiva de documentos</h2>' +
                        '<p>Suelta varios PDF y cada uno se enlaza a su equipo. Nada se escribe hasta que lo apliques.</p>' +
                    '</div>' +
                    '<button type="button" class="hd-cm-cerrar" title="Cerrar"><i class="material-icons">close</i></button>' +
                '</div>' +
                '<div class="hd-cm-tools">' +
                    '<div class="hd-cm-fila">' +
                        '<div class="hd-cm-campo">' +
                            '<span class="hd-cm-rot">Tipo de documento</span>' +
                            '<select id="hdCmTipo" class="hd-cm-select">' +
                                '<option value="">Reconocerlo solo</option>' +
                                '<option value="propiedad">Título de propiedad</option>' +
                                '<option value="poliza">Póliza de seguro</option>' +
                                '<option value="rotc">ROTC</option>' +
                                '<option value="racda">RACDA</option>' +
                            '</select>' +
                        '</div>' +
                        '<div class="hd-cm-zona" id="hdCmZona">' +
                            '<i class="material-icons">upload_file</i>' +
                            '<span>Suelta aquí los PDF o haz clic para elegirlos</span>' +
                        '</div>' +
                        '<input type="file" id="hdCmInput" accept="application/pdf" multiple hidden>' +
                        '<label class="hd-cm-ensayo" id="hdCmEnsayoCaja" title="Comprueba todo y dice qué haría, sin escribir nada">' +
                            '<input type="checkbox" id="hdCmEnsayo">Modo ensayo' +
                        '</label>' +
                    '</div>' +
                    '<div class="hd-cm-avance" id="hdCmAvance" hidden>' +
                        '<span id="hdCmAvanceTxt">Leyendo…</span>' +
                        '<div class="hd-cm-barra"><i id="hdCmBarra"></i></div>' +
                    '</div>' +
                '</div>' +
                '<div class="hd-cm-lista-cab"><span>Archivo</span><span>Estado</span></div>' +
                '<div class="hd-cm-list" id="hdCmList"></div>' +
                '<div class="hd-cm-pie">' +
                    '<span class="hd-cm-resumen" id="hdCmResumen"></span>' +
                    '<div class="hd-cm-botones">' +
                        '<button type="button" class="hd-cm-btn plano" id="hdCmVaciar">Vaciar la lista</button>' +
                        '<button type="button" class="hd-cm-btn primario" id="hdCmAplicar" disabled>' +
                            '<i class="material-icons">playlist_add_check</i><span id="hdCmAplicarTxt">Aplicar</span></button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(o);
        enlazar(o);
        pintar();
    }

    /**
     * Las fichas en las que SE ESCRIBIRÍA esta fila. Normalmente una, pero un RACDA es de
     * la empresa y nombra muchas unidades: se aplica a todas las que lo necesiten. Las que
     * ya tienen ese documento quedan fuera salvo que se marque "reemplazar" — la misma
     * regla que aplicaría el servidor, para no ofrecer lo que luego va a negar.
     */
    function destinos(f) {
        var p = f.propuesta;
        if (!p || !p.tipo || !p.link || f.estado === 'aplicado') return [];   // 'ensayo' NO: no escribió nada
        if (VENCEN[p.tipo] && !f.vence) return [];
        return (p.equipos || []).filter(function (e) {
            return f.pisar || !(e.links && e.links[p.tipo]);
        });
    }

    function aplicable(f) { return destinos(f).length > 0; }

    /** El primero de la lista: es el que se nombra en la fila. */
    function equipoDe(f) {
        var eqs = (f.propuesta && f.propuesta.equipos) || [];
        return eqs[0] || { links: {}, vence_ficha: {} };
    }

    /** ¿Alguna de las fichas nombradas ya tiene ese documento? */
    function algunoLoTiene(f) {
        var p = f.propuesta;
        if (!p || !p.tipo) return false;
        return (p.equipos || []).some(function (e) { return e.links && e.links[p.tipo]; });
    }

    /**
     * Por qué esta fila no va a escribir en ninguna ficha. Importa distinguirlo: "falta la
     * fecha" se arregla aquí mismo y "ya lo tienen" se arregla marcando reemplazar; decir
     * uno por el otro manda a buscar el problema donde no está.
     */
    function motivoSinDestino(f) {
        var p = f.propuesta;
        if (!p || !p.tipo) return '';
        if (VENCEN[p.tipo] && !f.vence) return 'falta la fecha de vencimiento';
        if (algunoLoTiene(f) && !f.pisar) return 'ya lo tienen';
        return 'nada que escribir';
    }

    function pintar() {
        var list = $('hdCmList'); if (!list) return;

        if (!estado.filas.length) {
            list.innerHTML = '<div class="hd-cm-vacio">Todavía no has soltado ningún PDF.<br>' +
                             'Puedes soltar varios de golpe; se leen de uno en uno.</div>';
        } else {
            list.innerHTML = estado.filas.map(fila).join('');
        }

        // Se cuentan FICHAS, no archivos: un RACDA es UN archivo y muchas fichas, así que
        // contar archivos hacía que el botón prometiera menos de lo que iba a escribir.
        var fichas = estado.filas.reduce(function (n, f) { return n + destinos(f).length; }, 0);
        var aplicados = estado.filas.filter(function (f) { return f.estado === 'aplicado'; }).length;
        var probados  = estado.filas.filter(function (f) { return f.estado === 'ensayo'; }).length;
        var btn = $('hdCmAplicar');
        if (btn) {
            btn.disabled = !fichas || estado.corriendo;
            // Corto y centrado: el detalle ya va en el resumen de la izquierda.
            $('hdCmAplicarTxt').textContent = (estado.ensayo ? 'Probar' : 'Aplicar') + (fichas ? ' (' + fichas + ')' : '');
        }
        var res = $('hdCmResumen');
        if (res) {
            var aviso = estado.ensayo ? 'MODO ENSAYO: no se escribe en ninguna ficha ni se borra ningún PDF. · ' : '';
            res.textContent = aviso + (estado.filas.length
                ? (estado.filas.length + ' archivo' + (estado.filas.length === 1 ? '' : 's') +
                   ' · ' + fichas + ' ficha' + (fichas === 1 ? '' : 's') + ' por actualizar' +
                   ' · ' + (estado.ensayo ? (probados + ' probado' + (probados === 1 ? '' : 's'))
                                         : (aplicados + ' aplicado' + (aplicados === 1 ? '' : 's'))))
                : '');
        }
    }

    var CHIP = {
        cola:       ['', 'En cola'],
        leyendo:    ['', 'Leyendo…'],
        listo:      ['ok', 'Listo'],
        revisar:    ['avisa', 'Revisar'],
        sin_equipo: ['avisa', 'Sin equipo'],
        ilegible:   ['mal', 'No se pudo leer'],
        aplicado:   ['ok', 'Aplicado'],
        error:      ['mal', 'No se aplicó'],
        ensayo:     ['avisa', 'Ensayo']
    };

    function fila(f, i) {
        var p = f.propuesta || {};
        var chip = CHIP[f.estado] || ['', f.estado];
        var eq = equipoDe(f);
        var tieneYa = algunoLoTiene(f);
        var irA = destinos(f).length;

        var cuerpo = '';
        if (f.estado !== 'cola' && f.estado !== 'leyendo') {
            var equipoTxt = (p.equipos || []).length
                ? '<b>' + esc(eq.nombre || '') + '</b>' +
                  '<small>' + esc(eq.placa || 's/placa') + (eq.serial ? ' · ' + esc(eq.serial) : '') +
                  ((p.equipos.length > 1) ? ' · y ' + (p.equipos.length - 1) + ' unidad(es) más en la lista' : '') +
                  (irA ? ' — se escribe en ' + irA + ' ficha' + (irA === 1 ? '' : 's')
                       : (f.aviso ? '' : ' — ' + motivoSinDestino(f))) +
                  '</small>'
                : '<small>Sin equipo reconocido</small>';

            cuerpo = '<div class="hd-cm-cuerpo">' +
                '<div class="hd-cm-equipo">' + equipoTxt + '</div>' +
                (p.tipo && VENCEN[p.tipo]
                    ? '<div class="hd-cm-campo"><span class="hd-cm-rot">Vence</span>' +
                      '<input type="date" class="hd-cm-fecha" data-vence="' + i + '" value="' + esc(f.vence || '') + '"></div>'
                    : '') +
                (tieneYa
                    ? '<label class="hd-cm-pisar"><input type="checkbox" data-pisar="' + i + '"' + (f.pisar ? ' checked' : '') + '>' +
                      'Reemplazar el que ya tiene</label>'
                    : '') +
            '</div>';
        }

        return '<div class="hd-cm-row" data-estado="' + f.estado + '">' +
            '<div class="hd-cm-cab">' +
                '<span class="hd-cm-arch">' + esc(f.nombre) + '</span>' +
                (p.tipo_nombre ? '<span class="hd-cm-chip">' + esc(p.tipo_nombre) + '</span>' : '') +
                '<span class="hd-cm-chip ' + chip[0] + '">' + chip[1] + '</span>' +
            '</div>' +
            cuerpo +
            (f.aviso ? '<div class="hd-cm-aviso">' + esc(f.aviso) + '</div>' : '') +
        '</div>';
    }

    // ── La cola de lectura ────────────────────────────────────────────────────

    function encolar(archivos) {
        var tipo = ($('hdCmTipo') || {}).value || '';
        Array.prototype.forEach.call(archivos, function (a) {
            if (a.type !== 'application/pdf' && !/\.pdf$/i.test(a.name)) return;
            estado.filas.push({ nombre: a.name, archivo: a, tipo: tipo, estado: 'cola',
                                propuesta: null, vence: '', pisar: false, aviso: null });
        });
        pintar();
        if (!estado.corriendo) leerCola();
    }

    function leerCola() {
        var turno = estado.turno;
        var pendientes = estado.filas.filter(function (f) { return f.estado === 'cola'; });
        if (!pendientes.length) { estado.corriendo = false; avance(0, 0); pintar(); return; }

        estado.corriendo = true;
        var total = estado.filas.length;
        var f = pendientes[0];
        f.estado = 'leyendo';
        avance(total - pendientes.length, total);
        pintar();

        window.apiPostForm(RUTAS.analizar, { file: f.archivo, tipo: f.tipo }, 'No se pudo leer el documento.')
            .then(function (b) {
                if (turno !== estado.turno) return;
                var p = b.propuesta || {};
                f.propuesta = p;
                f.estado    = p.estado || 'ilegible';
                f.aviso     = p.aviso || null;
                f.vence     = p.vence || '';
                // El archivo ya está en Drive; no hace falta guardarlo en memoria.
                f.archivo   = null;
            })
            .catch(function (e) {
                if (turno !== estado.turno) return;
                f.estado = 'ilegible';
                f.aviso  = e.message;
                f.archivo = null;
            })
            .finally(function () {
                if (turno !== estado.turno) return;
                pintar();
                leerCola();
            });
    }

    function avance(hechos, total) {
        var caja = $('hdCmAvance'); if (!caja) return;
        caja.hidden = !total;
        if (!total) return;
        $('hdCmAvanceTxt').textContent = 'Leyendo ' + Math.min(hechos + 1, total) + ' de ' + total + '…';
        $('hdCmBarra').style.width = Math.round((hechos / total) * 100) + '%';
    }

    // ── Aplicar ───────────────────────────────────────────────────────────────

    function aplicarTodo() {
        var pendientes = estado.filas.filter(aplicable);
        if (!pendientes.length) return;

        estado.corriendo = true;
        pintar();

        // Una escritura por ficha, de una en una: el servidor puede negar alguna (documento
        // anterior, ya lo tiene) y así el motivo queda en SU fila. Un RACDA aporta tantas
        // escrituras como unidades nombra.
        var tareas = [];
        pendientes.forEach(function (f) {
            destinos(f).forEach(function (eq) { tareas.push({ f: f, eq: eq }); });
        });

        var i = 0, bien = 0;
        (function siguiente() {
            if (i >= tareas.length) {
                estado.corriendo = false;
                pintar();
                window.toast(estado.ensayo
                    ? ('Ensayo: ' + bien + ' de ' + tareas.length + ' fichas pasarían. No se escribió nada.')
                    : ('Listo: ' + bien + ' de ' + tareas.length + ' fichas actualizadas.'),
                    estado.ensayo ? 'info' : 'success');
                return;
            }
            var t = tareas[i++];
            window.apiPostForm(RUTAS.aplicar, {
                id_equipo: t.eq.id, tipo: t.f.propuesta.tipo, link: t.f.propuesta.link,
                vence: t.f.vence || '', emision: t.f.propuesta.emision || '', pisar: t.f.pisar ? 1 : '',
                ensayo: estado.ensayo ? 1 : ''
            }, 'No se pudo aplicar.')
                .then(function (b) {
                    bien++;
                    // La fila queda aplicada cuando NINGUNA de sus fichas falló. En ensayo se
                    // marca aparte: no se escribió nada y tiene que verse distinto.
                    if (t.f.estado !== 'error') {
                        t.f.estado = estado.ensayo ? 'ensayo' : 'aplicado';
                        t.f.aviso  = estado.ensayo ? (b && b.message) || null : null;
                    }
                })
                .catch(function (e) { t.f.estado = 'error'; t.f.aviso = e.message; })
                .finally(function () { pintar(); siguiente(); });
        })();
    }

    /** Lo leído y no aplicado se borra de Drive: si no, quedarían archivos huérfanos. */
    function soltarDescartados() {
        estado.filas.forEach(function (f) {
            if (f.estado !== 'aplicado' && f.propuesta && f.propuesta.link) {
                window.apiPostForm(RUTAS.descartar, { link: f.propuesta.link }, '').catch(function () {});
            }
        });
    }

    // ── Eventos ───────────────────────────────────────────────────────────────

    function enlazar(o) {
        var input = $('hdCmInput'), zona = $('hdCmZona');

        o.querySelector('.hd-cm-cerrar').addEventListener('click', cerrar);
        o.addEventListener('click', function (e) { if (e.target === o) cerrar(); });

        zona.addEventListener('click', function () { input.click(); });
        input.addEventListener('change', function () { encolar(input.files); input.value = ''; });

        ['dragenter', 'dragover'].forEach(function (ev) {
            zona.addEventListener(ev, function (e) { e.preventDefault(); zona.classList.add('encima'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            zona.addEventListener(ev, function (e) { e.preventDefault(); zona.classList.remove('encima'); });
        });
        zona.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files) encolar(e.dataTransfer.files);
        });

        // Modo ensayo: cambia lo que hace el botón, nada más. Lo de verdad lo decide el
        // servidor, que en ensayo comprueba todo y sale sin escribir.
        $('hdCmEnsayo').addEventListener('change', function () {
            estado.ensayo = this.checked;
            $('hdCmEnsayoCaja').classList.toggle('activo', this.checked);
            pintar();
        });

        $('hdCmAplicar').addEventListener('click', aplicarTodo);
        $('hdCmVaciar').addEventListener('click', function () {
            soltarDescartados();
            estado.turno++;
            estado.filas = [];
            estado.corriendo = false;
            avance(0, 0);
            pintar();
        });

        // Fecha y "reemplazar" de cada fila, por delegación: la lista se repinta entera en
        // cada cambio y un listener por fila moriría con ella.
        $('hdCmList').addEventListener('change', function (e) {
            var t = e.target;
            var iv = t.getAttribute && t.getAttribute('data-vence');
            if (iv !== null && iv !== undefined) {
                var f = estado.filas[+iv];
                f.vence = t.value;
                if (f.vence && f.estado === 'revisar') { f.estado = 'listo'; f.aviso = null; }
                pintar();
                return;
            }
            var ip = t.getAttribute && t.getAttribute('data-pisar');
            if (ip !== null && ip !== undefined) { estado.filas[+ip].pisar = t.checked; pintar(); }
        });
    }

    function cerrar() {
        soltarDescartados();
        estado.turno++;
        estado.filas = [];
        estado.corriendo = false;
        var o = $('hdCmOverlay'); if (o) o.remove();
    }

    window.abrirCargaMasiva = function () {
        estado.turno++;
        estado.filas = [];
        estado.corriendo = false;
        estado.ensayo = false;
        construir();
    };

    // Si se navega (SPA) con el modal abierto, no dejarlo flotando sobre el módulo nuevo.
    if (!window.__hdCargaMasivaSpaBound) {
        window.__hdCargaMasivaSpaBound = true;
        window.addEventListener('spa:contentLoaded', function () {
            var o = document.getElementById('hdCmOverlay');
            if (o) o.remove();
        });
    }
})();
</script>
@endcan
