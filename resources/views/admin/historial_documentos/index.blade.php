@extends('layouts.estructura_base')

@section('title', 'Auditoría de Documentos')

@section('content')
<link rel="stylesheet" href="{{ asset('css/vistas/admin_historial_documentos_index.css') }}?v={{ @filemtime(public_path('css/vistas/admin_historial_documentos_index.css')) }}">

@include('admin.partials.page_header', [
    'titulo'       => 'Auditoría de Documentos',
    'tituloEstilo' => 'margin:0;',
    'align'        => 'left',
    'margin'       => '0 auto 16px auto',
    'padding'      => '0',
    'extra'        => 'width:98%;max-width:1600px;',
    'h1Estilo'     => 'display:flex;align-items:center;gap:12px;font-size:24px;',
])

<div class="hd-pest-fila">
    <div class="hd-pest">
    <button type="button" class="{{ $pestana === 'historial' ? 'on' : '' }}" onclick="window.hdPestana('historial')">Historial de cambios</button>
    <button type="button" class="{{ $pestana === 'documentos' ? 'on' : '' }}" onclick="window.hdPestana('documentos')">
        Revisión de documentos
        @if ($docsParaRevisar) <span class="hd-pend" title="Documentos que hay que revisar a mano">{{ $docsParaRevisar }}</span> @endif
    </button>
    <button type="button" class="{{ $pestana === 'compresion' ? 'on' : '' }}" onclick="window.hdPestana('compresion')">Compresión de PDF</button>
    </div>
</div>
<script>
    // Cambiar de pestaña = pedir la misma pantalla con ?pestana=..., por la SPA.
    window.hdPestana = function (cual) {
        var url = @json(route('historial-documentos.index')) + (cual === 'historial' ? '' : '?pestana=' + cual);
        if (typeof window.navigateTo === 'function') window.navigateTo(url);
        else window.location.href = url;
    };
</script>

@include('admin.historial_documentos.partials.desplegables')
@include('admin.historial_documentos.partials.papelera')
{{-- Sin el permiso el modal NI SE CARGA: así no existe ni su HTML ni su
     window.abrirCargaMasiva, y no basta con esconder el botón del menú Acciones. --}}
@can('docs.carga.masiva')
    @include('admin.historial_documentos.partials.carga_masiva')
@endcan

@if ($pestana !== 'historial')
    @include('admin.compresion_pdf.panel')
@else
<div class="maquinaria-layout-container hd-layout-grid" style="display: grid; grid-template-columns: 1fr 280px; gap: 20px; width: 98%; max-width: 1600px; margin: 0 auto;">
    
    <!-- Left Column (Main Content) -->
    <div>
        <div class="admin-card">
            <div class="filter-toolbar-container hd-filter-row" style="margin-bottom: 8px;">
                <!-- Search Equipo (Placa/Serial) -->
                <div class="filter-item aligned-filter responsive-filter-item">
                    <form style="width: 100%;" onsubmit="event.preventDefault(); window.loadHistorialDocumentos();">
                        <div class="search-wrapper" style="width: 100%; border-color: #cbd5e0; background: #fbfcfd; height: 45px;">
                            <i class="material-icons search-icon">search</i>
                            <input type="text" id="searchEquipo" name="search_equipo"
                                value="{{ request('search_equipo') }}"
                                placeholder="Buscar placa o serial..."
                                class="search-input-field"
                                style="height: 100%;"
                                autocomplete="off"
                                onkeyup="window.checkHistorialClearBtn('searchEquipo', 'btn_clear_searchEquipo')">
                            <i id="btn_clear_searchEquipo" class="material-icons clear-icon" style="display: {{ request('search_equipo') ? 'block' : 'none' }};" onclick="clearHistorialFilter('btn_clear_searchEquipo', 'searchEquipo');">close</i>
                        </div>
                    </form>
                </div>

                <!-- Search Correo -->
                <div class="filter-item aligned-filter responsive-filter-item" style="position: relative;">
                    <form style="width: 100%;" onsubmit="event.preventDefault(); window.loadHistorialDocumentos();">
                        <div class="search-wrapper" style="width: 100%; border-color: {{ request('search_correo') ? '#0067b1' : '#cbd5e0' }}; background: {{ request('search_correo') ? '#e1effa' : '#fbfcfd' }}; height: 45px;">
                            <i class="material-icons search-icon">search</i>
                            <input type="text" id="searchCorreo" name="search_correo"
                                value="{{ request('search_correo') }}"
                                placeholder="Buscar nombre o correo..."
                                class="search-input-field"
                                style="height: 100%;"
                                autocomplete="off"
                                oninput="window.hdCorreoSuggest && window.hdCorreoSuggest()"
                                onfocus="window.hdCorreoSuggest && window.hdCorreoSuggest()"
                                onkeyup="window.checkHistorialClearBtn('searchCorreo', 'btn_clear_searchCorreo')">
                            <i id="btn_clear_searchCorreo" class="material-icons clear-icon" style="display: {{ request('search_correo') ? 'block' : 'none' }};" onclick="clearHistorialFilter('btn_clear_searchCorreo', 'searchCorreo');">close</i>
                        </div>
                        <div id="hdCorreosSuggest" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:1000; margin-top:6px; max-height:280px; overflow-y:auto; background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 6px 16px rgba(0,0,0,0.08); padding:5px;"></div>
                    </form>
                </div>

                @php
                    // Opciones del filtro de acción: valor => etiqueta. Los 'cat_*' agrupan
                    // documentos por acción (1 opción por acción; el backend los mapea a sus
                    // doc_key, ver HistorialDocumentosController).
                    $tipoGrupos = [
                        'Sobre el equipo' => [
                            'Registro de Vehículo'  => 'Registro de Vehículo',
                            'Edición de Datos'      => 'Edición de Datos',
                            // Los cambios de ESTATUS los separa el controller a partir del
                            // diff; antes caían en "Edición de Datos" y no había forma de
                            // pedirle al historial "muéstrame qué equipos se pararon".
                            'Cambio de Estado'      => 'Cambio de Estado',
                            'Desincorporación'      => 'Desincorporación',
                            'Reincorporación'       => 'Reincorporación',
                            'Detalle Masivo'        => 'Detalle Masivo',
                            'Eliminación de Equipo' => 'Eliminación de Equipo',
                        ],
                        'Documentos' => [
                            'cat_uploads'   => 'Subida de documento',
                            'cat_borrados'  => 'Borrado de documento',
                            'cat_metadatos' => 'Edición de datos del documento',
                            'cat_anexos'    => 'Corrección anexa',
                        ],
                        'Catálogo de modelos' => [
                            'Registro de Modelo'    => 'Registro de Modelo',
                            'Edición de Modelo'     => 'Edición de Modelo',
                            'Foto de Modelo'        => 'Foto de Modelo',
                            'Registro de Auxiliar'  => 'Registro de Auxiliar',
                            'Foto de Auxiliar'      => 'Foto de Auxiliar',
                            'Eliminación de Modelo' => 'Eliminación de Modelo',
                        ],
                    ];
                    $reqTipo = request('search_tipo');
                    $tipoActivo = $reqTipo && $reqTipo !== 'all';
                    // Etiqueta del valor pedido, para que el placeholder no muestre el
                    // valor crudo ('cat_*') al recargar la página.
                    $reqTipoLabel = collect($tipoGrupos)->collapse()->get($reqTipo, $reqTipo);
                    $hasAdvHd = request()->filled('fecha_desde') || request()->filled('fecha_hasta');
                @endphp
                {{-- Acción, al lado del correo. Elegir una opción la aplica: selectOption lanza
                     'dropdown-selection' y ese evento recarga la tabla
                     (historial_documentos_index.js) — por eso los onclick NO llaman además a
                     loadHistorialDocumentos. --}}
                <div class="filter-item aligned-filter responsive-filter-item">
                    <div class="custom-dropdown" id="tipoDocFilterSelect" data-filter-type="tipo_filter" data-default-label="Filtrar Acción..." style="width: 100%;">
                        <input type="hidden" name="search_tipo" data-filter-value value="{{ $reqTipo ?: '' }}">

                        <div class="dropdown-trigger {{ $tipoActivo ? 'filter-active' : '' }}" style="background: {{ $tipoActivo ? '#e1effa' : '#fbfcfd' }}; border: 1px solid {{ $tipoActivo ? '#0067b1' : '#cbd5e0' }}; border-radius: 12px; height: 45px; display: flex; align-items: center; justify-content: space-between; padding: 0; width: 100%; overflow: hidden;">
                            <div style="padding: 0 10px; display: flex; align-items: center; color: var(--maquinaria-gray-text);">
                                <i class="material-icons" style="font-size: 18px;">search</i>
                            </div>
                            <input type="text" name="filter_search_dropdown" data-filter-search
                                placeholder="{{ $tipoActivo ? $reqTipoLabel : 'Filtrar Acción...' }}"
                                style="width: 100%; border: none; background: transparent; padding: 10px 5px; font-size: 14px; outline: none; color: #4a5568;"
                                onkeyup="window.filterDropdownOptions(this)"
                                autocomplete="off">
                            <div style="display: flex; align-items: center; padding-right: 10px;">
                                <i class="material-icons" data-clear-btn
                                   style="font-size: 18px; color: #a0aec0; margin-right: 5px; display: {{ $tipoActivo ? 'block' : 'none' }};"
                                   onclick="event.stopPropagation(); clearDropdownFilter('tipoDocFilterSelect');"
                                   title="Limpiar filtro">close</i>
                            </div>
                        </div>

                        <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible;">
                            <div class="dropdown-item-list" style="max-height: 320px; overflow-y: auto;">
                                <div class="dropdown-item {{ !$tipoActivo ? 'selected' : '' }}" data-value="all" data-label="TODAS LAS ACCIONES"
                                     onclick="selectOption('tipoDocFilterSelect', this.dataset.value, this.dataset.label)">
                                    TODAS LAS ACCIONES
                                </div>
                                @foreach($tipoGrupos as $grupo => $opciones)
                                    <div style="padding:4px 8px 2px; font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; border-top:1px solid #e2e8f0; margin-top:4px;">{{ $grupo }}</div>
                                    @foreach($opciones as $valor => $etiqueta)
                                        <div class="dropdown-item {{ $reqTipo === $valor ? 'selected' : '' }}" data-value="{{ $valor }}" data-label="{{ $etiqueta }}"
                                             onclick="selectOption('tipoDocFilterSelect', this.dataset.value, this.dataset.label)">{{ $etiqueta }}</div>
                                    @endforeach
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Filtros Avanzados: el rango de fechas, en un popover. El botón se pinta en
                     rojo si hay alguna fecha puesta — aquí al cargar y, tras cada filtrado por
                     AJAX, en hdMarcarFiltrosAvanzados (historial_documentos_index.js). --}}
                <div class="hd-adv-filter-wrap">
                    <button type="button" id="btnHdAdvancedFilter"
                        onclick="window.hdToggleFiltrosAvanzados()"
                        title="Filtros Avanzados (fechas)"
                        style="height: 45px; width: 45px; padding: 0; border-radius: 12px; background: {{ $hasAdvHd ? '#fee2e2' : 'white' }}; border: 1px solid {{ $hasAdvHd ? '#ef4444' : '#cbd5e0' }}; color: {{ $hasAdvHd ? '#ef4444' : '#64748b' }}; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;">
                        <i class="material-icons">filter_list</i>
                    </button>
                    <div id="hdAdvancedFilterPanel" style="display:none; position:absolute; top:100%; right:0; width:340px; max-width:calc(100vw - 24px); background:#e2e8f0; border:1px solid #cbd5e1; border-radius:12px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.15); margin-top:8px; padding:14px; z-index:100;">
                        <h4 style="margin:0 0 12px 0; font-size:13px; font-weight:700; color:#334155; display:flex; justify-content:space-between; align-items:center;">
                            Filtros Avanzados
                            <span style="font-size:11px; color:#64748b; font-weight:400; text-decoration:underline; cursor:pointer;"
                                  onclick="window.hdLimpiarFiltrosAvanzados()">Limpiar</span>
                        </h4>

                        {{-- Fecha desde / hasta EN FILA (uno al lado del otro). Cada columna
                             flex:1 + min-width:0 para repartir el ancho sin desbordar el panel;
                             los iconos de limpiar van compactos para que quepan los dos inputs. --}}
                        <div style="display:flex; gap:10px;">
                            <div style="flex:1; min-width:0;">
                                <span style="display:block; font-size:11px; font-weight:600; color:#64748b; margin-bottom:5px;">Fecha desde</span>
                                <div style="display:flex; align-items:center; gap:2px;">
                                    <input type="date" id="hdFechaDesde" name="fecha_desde" value="{{ request('fecha_desde') }}"
                                        onchange="window.hdToggleDateClear && window.hdToggleDateClear('hdFechaDesde','hdClrFechaDesde'); window.loadHistorialDocumentos && window.loadHistorialDocumentos()"
                                        onclick="try { this.showPicker(); } catch(e) {}"
                                        style="flex:1; min-width:0; box-sizing:border-box; padding:7px 8px; border:1px solid {{ request('fecha_desde') ? '#0067b1' : '#cbd5e0' }}; border-radius:8px; font-size:13px; color:#334155; background:{{ request('fecha_desde') ? '#e1effa' : 'white' }}; cursor:pointer;">
                                    <i id="hdClrFechaDesde" class="material-icons"
                                       style="display:{{ request('fecha_desde') ? 'inline-flex' : 'none' }}; cursor:pointer; color:#64748b; font-size:16px; padding:2px;"
                                       onclick="event.stopPropagation(); var d=document.getElementById('hdFechaDesde'); d.value=''; this.style.display='none'; window.loadHistorialDocumentos && window.loadHistorialDocumentos();"
                                       title="Limpiar fecha desde">close</i>
                                </div>
                            </div>
                            <div style="flex:1; min-width:0;">
                                <span style="display:block; font-size:11px; font-weight:600; color:#64748b; margin-bottom:5px;">Fecha hasta</span>
                                <div style="display:flex; align-items:center; gap:2px;">
                                    <input type="date" id="hdFechaHasta" name="fecha_hasta" value="{{ request('fecha_hasta') }}"
                                        onchange="window.hdToggleDateClear && window.hdToggleDateClear('hdFechaHasta','hdClrFechaHasta'); window.loadHistorialDocumentos && window.loadHistorialDocumentos()"
                                        onclick="try { this.showPicker(); } catch(e) {}"
                                        style="flex:1; min-width:0; box-sizing:border-box; padding:7px 8px; border:1px solid {{ request('fecha_hasta') ? '#0067b1' : '#cbd5e0' }}; border-radius:8px; font-size:13px; color:#334155; background:{{ request('fecha_hasta') ? '#e1effa' : 'white' }}; cursor:pointer;">
                                    <i id="hdClrFechaHasta" class="material-icons"
                                       style="display:{{ request('fecha_hasta') ? 'inline-flex' : 'none' }}; cursor:pointer; color:#64748b; font-size:16px; padding:2px;"
                                       onclick="event.stopPropagation(); var d=document.getElementById('hdFechaHasta'); d.value=''; this.style.display='none'; window.loadHistorialDocumentos && window.loadHistorialDocumentos();"
                                       title="Limpiar fecha hasta">close</i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                @include('admin.historial_documentos.partials.acciones')
            </div>

            <!-- Unified Responsive Table -->
            <div class="custom-scrollbar-container">
                <table class="admin-table table-historial-mobile" id="historialDocumentosTable">
                    <thead>
                        <tr class="tabla-cabecera">
                            <th class="table-cell-bordered" style="width: 130px;">Fecha y Hora</th>
                            <th class="table-cell-bordered" style="width: 24%;">Autor</th>
                            <th class="table-cell-bordered" style="width: 22%;">Tipo de Acción</th>
                            <th class="table-cell-bordered">Equipo Asociado</th>
                            <th style="text-align: center; width: 90px;">Ver PDF</th>
                        </tr>
                    </thead>
                    <tbody id="historialTableBody" style="font-size: 13px;">
                        @include('admin.historial_documentos.partials.table_rows', ['events' => $events])
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div id="historialPagination" style="margin-top: 15px;">
                {{ $events->links('vendor.pagination.custom-sliding') }}
            </div>

        </div>
    </div>

    <!-- Right Sidebar -->
    <div class="counter-sidebar historial-sidebar" id="historialSidebar" style="position: sticky; top: 20px; display: flex; flex-direction: column; gap: 10px; z-index: 10;">

        <!-- Total Card -->
        <div class="hd-total-card" style="background: linear-gradient(135deg, #001a52 0%, #0a4a91 100%); border-radius: 12px; padding: 15px; color: white; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); position: relative; overflow: hidden;">
            <i class="material-icons" style="position: absolute; right: -15px; bottom: -15px; font-size: 80px; opacity: 0.1; transform: rotate(-15deg);">history</i>
            <div style="position: relative; z-index: 2;">
                <div style="font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1.5px; opacity: 0.9; margin-bottom: 5px;">Total Auditoría</div>
                <div style="display: flex; align-items: baseline; gap: 5px;">
                    <span id="historial-count-text" style="font-size: 32px; font-weight: 800; line-height: 1; letter-spacing: -1px;">
                        {{ $total }}
                    </span>
                    <span style="font-size: 12px; opacity: 0.8; font-weight: 500;">registros</span>
                </div>
            </div>
        </div>

    </div>
</div>

{{-- ─── CONTADOR FLOTANTE DE SELECCIÓN ───────────────────────────────────── --}}
<div id="hd-selection-chip" class="selection-floating-bar">
    <div class="selection-counter" onclick="window.hdToggleSoloSel(event)" style="cursor: pointer;" title="Ver solo los eventos seleccionados">
        <div style="background: rgba(255,255,255,0.1); padding: 5px; border-radius: 50%; display: flex;">
            <i class="material-icons" style="font-size: 18px; color: white;">functions</i>
        </div>
        <span id="hd-selection-count">0</span>
    </div>
    <div style="width: 1px; height: 24px; background: rgba(255,255,255,0.2);"></div>
    <div style="display: flex; gap: 10px;">
        <button type="button" onclick="window.hdClearSelection()" style="background: transparent; border: none; color: #94a3b8; font-size: 13px; font-weight: 600;" onmouseover="this.style.color='white'" onmouseout="this.style.color='#94a3b8'">
            Limpiar
        </button>
    </div>
</div>

<style>
    /* Hover en filas seleccionables. Los fondos llevan !important porque .admin-table
       (estilos_globales.css) fija el de las celdas con !important. */
    #historialDocumentosTable .hd-selectable-row:not(.selected-row-maquinaria):hover td {
        background: #f8fafc !important;
        transition: background 0.15s;
    }
    #historialDocumentosTable .hd-has-cambios { cursor: pointer; }

    /* Desktop: fila abierta (burbuja de cambios) o seleccionada = fondo azul claro, borde
       azul y una barra a la izquierda. La barra es una sombra interior y no un borde de 4 px,
       que corría el contenido de la fila. */
    @media (min-width: 769px) {
        /* .hd-selectable-row va en el selector para igualar la especificidad del hover de
           arriba y, por estar después, ganarle: la fila se abre justo al pasar el ratón. */
        #historialDocumentosTable .hd-selectable-row.hd-has-cambios.hd-detail-open td {
            background: #eff6ff !important;
            border-color: #93c5fd !important;
        }
        #historialDocumentosTable tr.selected-row-maquinaria td {
            background: #e1effa !important;
            color: #0067b1;
            border-color: #93c5fd !important;
            transition: background 0.2s ease;
        }
        #historialDocumentosTable .hd-has-cambios.hd-detail-open td:first-child,
        #historialDocumentosTable tr.selected-row-maquinaria td:first-child {
            box-shadow: inset 7px 0 0 #0067b1;
        }

        /* ── Cambios editados: BURBUJA FLOTANTE en PC ──
           Antes el detalle se expandía inline y empujaba/agrandaba el registro.
           Ahora flota como popover anclado a la celda del equipo (4ª col): no
           altera la estructura de la fila — flota sobre el contenido. */
        /* El contenedor usa overflow-x:auto (que segun el spec recorta tambien en
           vertical) y cliparia la burbuja en las ultimas filas. En desktop la tabla
           cabe sin scroll horizontal, asi que liberamos el overflow para que flote. */
        .custom-scrollbar-container { overflow: visible; }
        #historialDocumentosTable .hd-has-cambios td:nth-child(4) { position: relative; }
        #historialDocumentosTable .hd-cambios-detail {
            position: absolute;
            top: calc(100% - 2px);
            left: 0;
            z-index: 200;
            width: max-content;
            min-width: 240px;
            max-width: 380px;
            margin-top: 0 !important;
        }
        #historialDocumentosTable .hd-cambios-detail > div {
            max-height: 320px;
            overflow-y: auto;
        }
    }
    /* Móvil: card completa celeste (mismo patrón que equipos) */
    @media (max-width: 768px) {
        #historialDocumentosTable tr.selected-row-maquinaria {
            border: 2px solid var(--maquinaria-blue, #0067b1) !important;
            background-color: #f0f9ff !important;
            /* La franja de la izquierda, bien marcada: es lo que se ve de un vistazo. */
            box-shadow: inset 7px 0 0 var(--maquinaria-blue, #0067b1), 0 4px 12px rgba(0, 103, 177, 0.15) !important;
        }
        #historialDocumentosTable tr.selected-row-maquinaria td {
            background-color: transparent !important;
            border-color: transparent !important;
            color: inherit !important;
        }
        #historialDocumentosTable tr.selected-row-maquinaria td:nth-child(4) {
            border-top: 1px solid #bfdbfe !important;
            border-bottom: 1px solid #bfdbfe !important;
        }
    }

    /* "Ver solo seleccionados" activo: resalta solo el NÚMERO del contador en un
       círculo ámbar limpio (mismo patrón que el módulo Equipos). */
    #hd-selection-chip .selection-counter.is-filtering #hd-selection-count {
        background: #fbbf24;
        color: #1e293b;
        min-width: 22px;
        height: 22px;
        padding: 0 5px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        line-height: 1;
        box-sizing: border-box;
    }
</style>


{{-- ── Desplegables del módulo: solo uno abierto a la vez ──
     Sugerencias de correo, desplegable de acción, Filtros Avanzados (fechas) y
     Acciones. Los botones NO hacen stopPropagation: cada cierre vive en un listener de
     document que ignora los clics dentro de su propio bloque (closest). El de acción lo
     cierra uicomponents.js al hacer clic fuera; las sugerencias, el mousedown de
     hdCorreoSuggest. Con FOCO sin clic (Tab, "siguiente" del teclado del teléfono)
     focusin hace el mismo cierre. Listeners una sola vez (SPA-safe). --}}




<script>
(function () {
    if (window.__hdCorreoAutoInit) return;
    window.__hdCorreoAutoInit = true;
    var AUTORES = @json($autoresSugeridos ?? []);
    var esc = window.escapeHtml;   // helper central (dom_helpers.js)

    window.hdCorreoSuggest = function () {
        var inp = document.getElementById('searchCorreo');
        var box = document.getElementById('hdCorreosSuggest');
        if (!inp || !box) return;
        var term = (inp.value || '').trim().toLowerCase();
        // Casa por nombre O por correo (substring).
        var m = AUTORES.filter(function (a) {
            return String(a.nombre || '').toLowerCase().indexOf(term) !== -1
                || String(a.correo || '').toLowerCase().indexOf(term) !== -1;
        }).slice(0, 50);
        if (!m.length) { box.style.display = 'none'; return; }
        box.innerHTML = m.map(function (a) {
            var nombre = a.nombre || '';
            var inner = nombre
                ? '<span style="font-size:14px;font-weight:600;color:#1e3a5f;">' + esc(nombre) + '</span>'
                  + '<span style="font-size:12px;color:#64748b;">' + esc(a.correo) + '</span>'
                : '<span style="font-size:14px;font-weight:600;color:#1e3a5f;">' + esc(a.correo) + '</span>';
            return '<div class="hd-correo-item" data-val="' + esc(a.correo) + '"'
                 + ' onmouseover="this.style.background=\'#f0f4f8\'" onmouseout="this.style.background=\'transparent\'"'
                 + ' style="padding:9px 14px;border-radius:8px;cursor:pointer;display:flex;flex-direction:column;gap:2px;background:transparent;">'
                 + inner + '</div>';
        }).join('');
        box.style.display = 'block';
    };

    // Un solo listener: selecciona si se clickea un item, cierra si el clic es fuera.
    document.addEventListener('mousedown', function (e) {
        var it = e.target.closest ? e.target.closest('.hd-correo-item') : null;
        var box = document.getElementById('hdCorreosSuggest');
        var inp = document.getElementById('searchCorreo');
        if (it) {
            e.preventDefault();
            if (inp) inp.value = it.getAttribute('data-val');
            if (box) box.style.display = 'none';
            if (typeof window.checkHistorialClearBtn === 'function') window.checkHistorialClearBtn('searchCorreo', 'btn_clear_searchCorreo');
            if (typeof window.loadHistorialDocumentos === 'function') window.loadHistorialDocumentos();
            return;
        }
        if (box && e.target !== inp && !box.contains(e.target)) box.style.display = 'none';
    });
})();
</script>

<script>
// ── Eliminar registro del historial (solo super.admin) ──
// Solo las filas de AUDITORÍA (equipo_audit / catalogo_audit) se borran de verdad.
// 'doc' (subida de documento) y 'equipo_creacion' (creación de vehículo) se avisan
// SIN pegarle al servidor: borrarlos afectaría datos reales. El backend también los
// bloquea por si acaso (defensa en profundidad).
window.hdDeleteRegistro = function (source, id, btn) {
    if (!source) return;

    if (source === 'doc' || source === 'equipo_creacion') {
        var aviso = source === 'doc'
            ? 'Esta fila es una subida de documento real. Para quitarlo, bórralo desde el documento del equipo, no desde el historial.'
            : 'Esta fila es la creación de un vehículo. Para eliminarlo usa el módulo de Equipos.';
        if (window.showToast) window.showToast(aviso, 'error'); else alert(aviso);
        return;
    }

    if (!confirm('¿Eliminar este registro del historial? Esta acción no se puede deshacer.')) return;

    var tr = btn.closest('tr');
    if (window.showPreloader) window.showPreloader();
    window.apiFetch(@json(route('historial-documentos.deleteRegistro')), {
        method: 'DELETE',
        headers: { 'Accept': 'application/json', 
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ source: source, id: id })
    })
    .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
    .then(function (res) {
        if (res.ok && res.data.success) {
            if (tr) { tr.style.transition = 'opacity .2s'; tr.style.opacity = '0'; setTimeout(function () { tr.remove(); }, 200); }
            window.toast(res.data.message || 'Registro eliminado.', 'success');
        } else {
            window.toast(res.data.message || 'No se pudo eliminar.', 'error');
        }
    })
    .catch(function () { window.toast('Error de conexión.', 'error'); })
    .finally(function () { if (window.hidePreloader) window.hidePreloader(); });
};
// Los listeners van DELEGADOS en `document`, así funcionan con las filas cargadas por
// AJAX (filtros/paginación) sin re-bindear. Guard anti-doble-registro: este bloque
// inline se re-ejecuta en cada navegación SPA; sin el guard se acumulaban N copias del
// handler y, como el de la burbuja hace toggle (abrir/cerrar), al dispararse 2+ veces
// en un solo clic la burbuja se abría y se cerraba al instante → parecía no responder
// hasta recargar la página (F5). Con el guard se registra UNA sola vez.
if (!window._hdInlineClickRegistered) {
    window._hdInlineClickRegistered = true;

    document.addEventListener('click', function (e) {
        var tr = e.target.closest('.table-historial-mobile tbody tr');
        if (!tr || tr.classList.contains('hd-has-cambios')) return;
        document.querySelectorAll('.table-historial-mobile tbody tr.hd-row-selected').forEach(function (o) {
            if (o !== tr) o.classList.remove('hd-row-selected');
        });
        tr.classList.toggle('hd-row-selected');
    });
    // Abrir y cerrar viven en un punto unico: los usan el raton, el toque y la X.
    // Global porque la X de dentro de la burbuja se pinta en table_rows con un
    // onclick inline: sin exponerla, ese onclick no alcanza a una funcion
    // declarada aqui dentro del guard.
    window.hdCerrarCambios = hdCerrarTodo;

    function hdCerrarTodo() {
        document.querySelectorAll('.hd-cambios-detail').forEach(function (d) { d.style.display = 'none'; });
        document.querySelectorAll('.hd-detail-open').forEach(function (r) { r.classList.remove('hd-detail-open'); });
    }

    function hdAbrir(row) {
        var detail = row.querySelector('.hd-cambios-detail');
        if (!detail) return;
        detail.style.display = 'block';
        row.classList.add('hd-detail-open');
    }

    // Pantallas sin raton (telefono, tablet): el toque hace de "pasar por encima".
    // Tocar una tarjeta con la burbuja cerrada muestra sus cambios (y cierra la de
    // otra); tocarla con la burbuja abierta la cierra. La burbuja NO depende de la
    // seleccion —que es multiple y la alterna historial_documentos_index.js con el
    // mismo toque—: atada a ella, tras tocar otra tarjeta o cerrar con la X habia
    // que tocar dos veces para volver a ver los cambios. La X cierra sin tocar la
    // seleccion (para la propagacion).
    var hdSinHover = window.matchMedia('(hover: none)');

    document.addEventListener('click', function (e) {
        if (!hdSinHover.matches) return;
        var row = e.target.closest('.hd-has-cambios');
        if (!row || e.target.closest('button, a')) return;
        var abierta = row.classList.contains('hd-detail-open');
        hdCerrarTodo();
        if (!abierta) hdAbrir(row);
    });

    // ── Vista previa al pasar el raton ──────────────────────────────────────
    // Basta con pasar por encima de la fila para ver qué cambió; el clic queda
    // libre para SELECCIONAR (contador flotante / "ver solo seleccionados").
    //
    // Con un retardo corto: sin el, al recorrer la tabla se abrian y cerraban
    // todas las burbujas de golpe y era imposible leer nada.
    //
    // Solo con raton: el navegador del telefono simula un mouseover en cada toque,
    // y ese timer reabriría la burbuja de una tarjeta que el toque acaba de cerrar.
    var hdPreviaTimer = null;

    document.addEventListener('mouseover', function (e) {
        if (hdSinHover.matches) return;
        var row = e.target.closest('.hd-has-cambios');
        if (!row) return;
        if (row.classList.contains('hd-detail-open')) return;
        clearTimeout(hdPreviaTimer);
        hdPreviaTimer = setTimeout(function () {
            hdCerrarTodo();
            hdAbrir(row);
        }, 180);
    });

    document.addEventListener('mouseout', function (e) {
        if (hdSinHover.matches) return;
        var row = e.target.closest('.hd-has-cambios');
        if (!row) return;
        // Moverse DENTRO de la misma fila (o hacia la propia burbuja) no cuenta
        // como salir: si no, leerla era imposible porque se cerraba sola.
        if (e.relatedTarget && row.contains(e.relatedTarget)) return;
        clearTimeout(hdPreviaTimer);
        hdPreviaTimer = setTimeout(function () {
            if (!row.matches(':hover')) hdCerrarTodo();
        }, 180);
    });
}
</script>

@endif
@endsection
