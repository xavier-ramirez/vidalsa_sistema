@extends('layouts.estructura_base')

@section('title', 'Auditoría de Documentos')

@section('content')
<style>
    .badge-doc {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: #ebf8ff;
        color: #2b6cb0;
        padding: 3px 9px;
        border-radius: 6px;
        font-size: 10px;
        font-weight: 600;
    }
    .badge-doc .material-icons { font-size: 13px; }
    .badge-autor {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #f1f5f9;
        color: #475569;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
    }
    .btn-view-pdf {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 6px;
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        color: #64748b;
        cursor: pointer;
        transition: all 0.2s;
    }
    .btn-view-pdf:hover {
        background: #e2e8f0;
        color: #0f172a;
    }
    /* Botón eliminar registro (solo super.admin). Pequeño y OCULTO por defecto:
       aparece al pasar el mouse o enfocar (teclado) la fila. En móvil (sin hover)
       se fuerza visible más abajo. */
    .btn-hd-del {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        border-radius: 6px;
        background: #fff;
        border: 1px solid #fca5a5;
        color: #dc2626;
        cursor: pointer;
        opacity: 0;
        transition: opacity .15s, background .2s, border-color .2s;
    }
    .btn-hd-del:hover {
        background: #fee2e2;
        border-color: #dc2626;
    }
    /* Revelar al hover / focus de la fila (desktop, donde hay hover). */
    #historialDocumentosTable tbody tr:hover .btn-hd-del,
    #historialDocumentosTable tbody tr:focus-within .btn-hd-del {
        opacity: 1;
    }

    .hd-filter-row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: stretch;
    }
    .hd-filter-row > .filter-item.responsive-filter-item {
        flex: 1 1 200px !important;
        max-width: none !important;
        min-width: 180px;
    }
    @media (min-width: 769px) {
        #historialDocumentosTable {
            border-spacing: 0 5px !important;
        }
        #historialDocumentosTable td {
            padding-top: 7px !important;
            padding-bottom: 7px !important;
        }
    }
    .hd-adv-filter-wrap {
        position: relative;
        flex: 0 0 auto;
    }
    .hd-acciones-wrap {
        position: relative;
        flex: 0 0 auto;
    }
    @media (max-width: 900px) {
        .hd-layout-grid {
            grid-template-columns: 1fr !important;
        }
        .historial-sidebar {
            width: 100% !important;
            max-width: 100% !important;
            margin-top: 10px;
        }
        .historial-sidebar > div { margin-top: 0 !important; }
        .historial-sidebar > div + div { margin-top: 8px !important; }
        .hd-total-card {
            display: none !important;
        }
        .hd-collapsible-header {
            cursor: pointer;
        }
        .hd-collapsible-header .hd-chevron {
            display: inline-flex !important;
            transition: transform 0.25s;
        }
        .hd-collapsible-body {
            display: none;
            overflow: hidden;
        }
        .hd-collapsible-body.hd-open {
            display: block;
        }
        .hd-chevron.hd-open {
            transform: rotate(180deg);
        }
    }

    @media (max-width: 768px) {
        /* En móvil (touch, sin hover) el botón eliminar queda SIEMPRE visible —
           si no, no habría forma de revelarlo. Además lo igualamos al botón PDF
           (32x32, icono 18px) para que no se vean desparejos lado a lado en la tarjeta. */
        .btn-hd-del { opacity: 1 !important; width: 32px !important; height: 32px !important; }
        .btn-hd-del .material-icons { font-size: 18px !important; }
        .hd-filter-row {
            flex-direction: row !important;
            flex-wrap: wrap !important;
            align-items: stretch !important;
            gap: 8px !important;
        }
        /* Los dos buscadores y la acción a lo ancho; debajo, en una fila, Filtros
           Avanzados (45px) + Acciones (el resto). */
        .hd-filter-row > .filter-item.responsive-filter-item {
            flex: 1 1 100% !important;
            min-width: 0 !important;
        }
        .hd-adv-filter-wrap {
            flex: 0 0 45px !important;
        }
        /* El botón queda a la izquierda: el panel se abre hacia la derecha para
           no salirse de la pantalla. */
        #hdAdvancedFilterPanel {
            left: 0 !important;
            right: auto !important;
        }
        .hd-acciones-wrap {
            flex: 1 1 0;
            min-width: 0;
        }
        #hdBtnAcciones {
            width: 100% !important;
        }
        #hdAccionesMenu {
            left: 0 !important;
            right: 0 !important;
            width: auto !important;
        }
        #historialDocumentosTable {
            min-width: 0 !important;
        }

        /* ── Tabla → cards en móvil ── */
        .table-historial-mobile {
            display: block;
            width: 100%;
        }
        .table-historial-mobile thead {
            display: none;
        }
        .table-historial-mobile tbody {
            display: flex;
            flex-direction: column;
            gap: 10px;
            width: 100%;
        }
        .table-historial-mobile tbody tr {
            display: grid;
            grid-template-columns: 1fr auto;
            grid-template-rows: auto auto auto;
            grid-template-areas:
                "doc    date"
                "equipo equipo"
                "author pdf";
            column-gap: 10px;
            row-gap: 8px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05);
            padding: 12px 14px !important;
            align-items: center;
            transition: border-color 0.15s, background 0.15s;
        }
        .table-historial-mobile tbody tr.hd-row-selected {
            border-color: #0067b1;
            background: #f0f9ff;
        }
        .table-historial-mobile tbody td {
            border: none !important;
            padding: 0 !important;
            text-align: left;
            border-radius: 0 !important;
            min-width: 0;
            background: transparent !important;
        }
        /* Fila 1 izq: acción (badge) */
        .table-historial-mobile tbody td:nth-child(3) {
            grid-area: doc;
        }
        .table-historial-mobile tbody td:nth-child(3) .badge-doc {
            font-size: 10px;
            padding: 3px 8px;
            border-radius: 6px;
        }
        /* Fila 1 der: fecha + hora */
        .table-historial-mobile tbody td:nth-child(1) {
            grid-area: date;
            justify-self: end;
            font-size: 11.5px;
            color: #94a3b8;
            line-height: 1.25;
        }
        .table-historial-mobile tbody td:nth-child(1) > div {
            align-items: flex-end !important;
        }
        /* Fila 2: equipo (protagonista) */
        .table-historial-mobile tbody td:nth-child(4) {
            grid-area: equipo;
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.3;
            border-top: 1px solid #f1f5f9;
            border-bottom: 1px solid #f1f5f9;
            padding: 6px 0 !important;
        }
        /* Fila 3 izq: autor */
        .table-historial-mobile tbody td:nth-child(2) {
            grid-area: author;
            font-size: 12px;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 65vw;
            align-self: center;
        }
        .table-historial-mobile tbody td:nth-child(2) .badge-autor {
            background: transparent;
            padding: 0;
            font-size: 12px;
            color: #64748b;
        }
        .table-historial-mobile tbody td:nth-child(2) .badge-autor i {
            display: none;
        }
        /* Fila 3 der: botón PDF */
        .table-historial-mobile tbody td:nth-child(5) {
            grid-area: pdf;
            justify-self: end;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* ── Overlay de cambios encima de la tarjeta ── */
        .table-historial-mobile tbody tr.hd-has-cambios {
            position: relative;
            overflow: visible;
        }
        .table-historial-mobile .hd-cambios-detail {
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            margin: 0 !important;
            z-index: 10;
            min-height: 100%;
        }
        .table-historial-mobile .hd-cambios-detail > div {
            border-radius: 10px !important;
            min-height: 100%;
        }
    }
</style>

@include('admin.partials.page_header', [
    'titulo'       => 'Auditoría de Documentos',
    'tituloEstilo' => 'margin:0;',
    'align'        => 'left',
    'margin'       => '0 auto 16px auto',
    'padding'      => '0',
    'extra'        => 'width:98%;max-width:1600px;',
    'h1Estilo'     => 'display:flex;align-items:center;gap:12px;font-size:24px;',
])

<div class="maquinaria-layout-container hd-layout-grid" style="display: grid; grid-template-columns: 1fr 280px; gap: 20px; width: 98%; max-width: 1600px; margin: 0 auto;">
    
    <!-- Left Column (Main Content) -->
    <div>
        <div class="admin-card">
            <div class="filter-toolbar-container hd-filter-row" style="margin-bottom: 5px;">
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
                            'cat_metadatos' => 'Edición de metadatos',
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

                {{-- Acciones (mismo botón que Equipos/Auxiliares). Solo super.admin: es el
                     mismo público de sus dos entradas. La papelera exige además user.delete
                     (sin él, toast y no abre — guard en abrirPapelera); Compresión de PDF
                     vive aquí y NO en el menú general (antes estaba en Configuraciones). --}}
                @can('super.admin')
                <div class="hd-acciones-wrap">
                    <button type="button" id="hdBtnAcciones" class="btn-primary-maquinaria"
                        onclick="window.hdToggleAcciones()"
                        style="height: 45px; padding: 0 15px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap;">
                        <i class="material-icons" style="font-size: 18px;">settings</i>
                        <span>Acciones</span>
                        <i class="material-icons" style="font-size: 16px;">expand_more</i>
                    </button>
                    <div id="hdAccionesMenu" style="display: none; position: absolute; top: 100%; right: 0; width: 240px; max-width: calc(100vw - 24px); background: #e2e8f0; border: 1px solid #cbd5e1; border-radius: 10px; box-shadow: 0 10px 20px -5px rgba(15,23,42,0.18); margin-top: 6px; overflow: hidden; z-index: 60;">
                        <button type="button" class="dropdown-item-custom"
                            onclick="window.hdCerrarAcciones(); window.abrirPapelera && window.abrirPapelera();"
                            style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; background: transparent; border: none; border-bottom: 1px solid #f1f5f9; width: 100%; text-align: left; cursor: pointer;">
                            <div style="background: #fef3c7; padding: 6px; border-radius: 6px; display: flex;">
                                <i class="material-icons" style="font-size: 18px; color: #d97706;">delete_sweep</i>
                            </div>
                            <span style="font-size: 14px; font-weight: 500;">Papelera</span>
                        </button>
                        {{-- Link normal SIN onclick: navegacion.js no lleva por SPA los links
                             con onclick (haría recarga completa). El menú se va con la vista. --}}
                        <a href="{{ route('compresion-pdf.index') }}" class="dropdown-item-custom"
                            style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; cursor: pointer;">
                            <div style="background: #e0f2fe; padding: 6px; border-radius: 6px; display: flex;">
                                <i class="material-icons" style="font-size: 18px; color: #0284c7;">compress</i>
                            </div>
                            <span style="font-size: 14px; font-weight: 500;">Compresión de PDF</span>
                        </a>
                    </div>
                </div>
                @endcan

            </div>

            <!-- Unified Responsive Table -->
            <div class="custom-scrollbar-container">
                <table class="admin-table table-historial-mobile" id="historialDocumentosTable" style="width: 100% !important;">
                    <thead>
                        <tr class="tabla-cabecera" style="border-bottom: 2px solid #0f172a;">
                            <th class="table-cell-bordered" style="min-width: 150px;">Fecha y Hora</th>
                            <th class="table-cell-bordered" style="min-width: 200px;">Autor</th>
                            <th class="table-cell-bordered" style="min-width: 180px;">Tipo de Acción</th>
                            <th class="table-cell-bordered" style="min-width: 200px;">Equipo Asociado</th>
                            <th style="text-align: center; width: 100px;">Ver PDF</th>
                        </tr>
                    </thead>
                    <tbody id="historialTableBody" style="font-size: 13px;">
                        @include('admin.historial_documentos.partials.table_rows', ['events' => $events])
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div id="historialPagination" style="margin-top: 25px;">
                {{ $events->links('vendor.pagination.custom-sliding') }}
            </div>

        </div>
    </div>

    <!-- Right Sidebar -->
    <div class="counter-sidebar historial-sidebar" id="historialSidebar" style="position: sticky; top: 20px; display: flex; flex-direction: column; gap: 10px; z-index: 10;">

        <!-- Total Card -->
        <div class="hd-total-card" style="background: linear-gradient(135deg, #4c1d95 0%, #6d28d9 100%); border-radius: 12px; padding: 15px; color: white; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); position: relative; overflow: hidden;">
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

        {{-- IPs Bloqueadas Card — siempre visible para super.admin (incluso con 0 IPs).
             Muestra empty state si no hay bloqueadas; con datos permite filtrar
             y desbloquear individualmente con el icono de bote. --}}
        @if(auth()->check() && auth()->user()->can('super.admin'))
        @php $bipsCount = isset($blockedIps) ? $blockedIps->count() : 0; @endphp
        @if($bipsCount === 0)
        <div style="background: white; border-radius: 12px; padding: 14px 15px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div class="hd-collapsible-header" onclick="window.hdToggleCollapse(this)" style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="material-icons" style="color: #16a34a; font-size: 18px;">verified_user</i>
                    <h3 style="margin: 0; font-size: 12px; font-weight: 700; color: #1e293b; text-transform: uppercase;">IPs Bloqueadas</h3>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="background: #dcfce7; color: #15803d; font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 700;">0</span>
                    <i class="material-icons hd-chevron" style="display: none; font-size: 20px; color: #94a3b8;">expand_more</i>
                </div>
            </div>
            <div class="hd-collapsible-body">
                <p style="margin: 0; font-size: 11px; color: #94a3b8; text-align: center; padding: 8px 0 0;">Sin IPs bloqueadas (umbral: 10 intentos fallidos).</p>
            </div>
        </div>
        @else
        <div style="background: white; border-radius: 12px; padding: 15px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: relative; z-index: 20;" id="blocked-ips-container">
            <div class="hd-collapsible-header" onclick="window.hdToggleCollapse(this)" style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="material-icons" style="color: #ef4444; font-size: 20px;">gpp_bad</i>
                    <h3 style="margin: 0; font-size: 13px; font-weight: 700; color: #1e293b; text-transform: uppercase;">IPs Bloqueadas</h3>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span class="badge" style="background: #fee2e2; color: #ef4444; font-size: 11px; padding: 2px 6px; border-radius: 10px; font-weight: 700;" id="blocked-ip-count">{{ $blockedIps->count() }}</span>
                    <i class="material-icons hd-chevron" style="display: none; font-size: 20px; color: #94a3b8;">expand_more</i>
                </div>
            </div>
            <div class="hd-collapsible-body" style="margin-top: 10px;">
                <div style="position: relative; margin-bottom: 10px;">
                    <i class="material-icons" style="position: absolute; left: 8px; top: 50%; transform: translateY(-50%); font-size: 16px; color: #94a3b8; pointer-events: none;">search</i>
                    <input
                        type="text"
                        id="ip-filter-input"
                        placeholder="Filtrar por IP..."
                        autocomplete="off"
                        oninput="window.filterBlockedIps(this.value)"
                        style="width: 100%; box-sizing: border-box; padding: 7px 10px 7px 30px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 12px; color: #334155; background: #f8fafc; outline: none; transition: border-color 0.2s;"
                        onfocus="this.style.borderColor='#ef4444'; this.style.background='#fff'"
                        onblur="this.style.borderColor='#e2e8f0'; this.style.background='#f8fafc'"
                    >
                </div>
                <div id="ip-filter-empty" style="display: none; text-align: center; font-size: 12px; color: #94a3b8; padding: 8px 0;">Sin coincidencias</div>
                <div id="blocked-ips-list" style="display: flex; flex-direction: column; gap: 8px; max-height: 280px; overflow-y: auto; padding-right: 4px;" class="custom-scrollbar-container">
                    @foreach($blockedIps as $ip)
                    <div id="blocked-ip-{{ $ip->ID_BLOQUEO }}" data-ip-text="{{ $ip->DIRECCION_IP }}" style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; padding: 8px 10px; border-radius: 6px; border: 1px solid #f1f5f9; transition: all 0.2s;">
                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <span style="font-size: 13px; font-weight: 600; color: #334155; font-family: monospace;">{{ $ip->DIRECCION_IP }}</span>
                            <span style="font-size: 11px; color: #64748b; background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-weight: 600;" title="Último intento: {{ $ip->ULTIMO_INTENTO->format('d/m/Y H:i') }}">Fallos: {{ $ip->CANTIDAD_INTENTOS }}</span>
                        </div>
                        @can('super.admin')
                        <button
                                class="btn-unlock-ip"
                                data-ip-id="{{ $ip->ID_BLOQUEO }}"
                                data-ip-address="{{ $ip->DIRECCION_IP }}"
                                style="background: transparent; border: none; padding: 4px; color: #ef4444; cursor: pointer; border-radius: 4px; transition: background 0.2s; display: flex; align-items: center; justify-content: center; pointer-events: all; position: relative; z-index: 30; margin-left: 10px;"
                                onmouseover="this.style.background='#fee2e2'"
                                onmouseout="this.style.background='transparent'"
                                title="Desbloquear IP">
                            <i class="material-icons" style="font-size: 18px; pointer-events: none;">delete_outline</i>
                        </button>
                        @endcan
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif {{-- bipsCount > 0 --}}
        @endif {{-- can super.admin --}}

        {{-- ─── Usuarios Activos (sesiones últimos 30 min) ─── --}}
        @if(isset($activeUsers) && auth()->check() && auth()->user()->can('super.admin'))
        <div style="background: white; border-radius: 12px; padding: 15px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.06);">
            <div class="hd-collapsible-header" onclick="window.hdToggleCollapse(this)" style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="material-icons" style="color: #10b981; font-size: 22px;">radio_button_checked</i>
                    <h3 style="margin: 0; font-size: 13px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px;">Usuarios Activos</h3>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="background: #dcfce7; color: #15803d; font-size: 12px; padding: 3px 10px; border-radius: 10px; font-weight: 700;">{{ $activeUsers->count() }}</span>
                    <i class="material-icons hd-chevron" style="display: none; font-size: 20px; color: #94a3b8;">expand_more</i>
                </div>
            </div>
            <div class="hd-collapsible-body" style="margin-top: 14px;">
                @if($activeUsers->count() === 0)
                    <p style="margin: 0; font-size: 12px; color: #94a3b8; text-align: center; padding: 20px 0;">Nadie conectado en los últimos 30 min.</p>
                @else
                    <div style="display: flex; flex-direction: column; gap: 8px; min-height: 280px; max-height: 400px; overflow-y: auto; padding-right: 4px;" class="custom-scrollbar-container">
                        @foreach($activeUsers as $u)
                            @php
                                $minsAgo = max(0, (int) floor((now()->timestamp - $u->last_activity) / 60));
                                $ago = $minsAgo === 0 ? 'ahora' : ($minsAgo === 1 ? 'hace 1 min' : 'hace ' . $minsAgo . ' min');
                                $nombreCorto = $u->NOMBRE_COMPLETO ?: strtok($u->CORREO_ELECTRONICO, '@');
                            @endphp
                            <div style="display: flex; justify-content: space-between; align-items: center; background: #f0fdf4; padding: 9px 12px; border-radius: 8px; border: 1px solid #dcfce7;" title="{{ $u->CORREO_ELECTRONICO }} | IP: {{ $u->ip_address ?? 'N/A' }}">
                                <div style="display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1;">
                                    <span style="width: 9px; height: 9px; border-radius: 50%; background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.25); flex-shrink: 0;"></span>
                                    <span style="font-size: 11px; font-weight: 600; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $nombreCorto }}</span>
                                </div>
                                <span style="font-size: 11px; color: #64748b; white-space: nowrap; margin-left: 8px; background: #e2e8f0; padding: 2px 7px; border-radius: 6px; font-weight: 600;">{{ $ago }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
        @endif
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
    /* Hover en filas seleccionables */
    #historialDocumentosTable .hd-selectable-row:not(.selected-row-maquinaria):hover td {
        background: #f8fafc !important;
        transition: background 0.15s;
    }
    #historialDocumentosTable .hd-has-cambios { cursor: pointer; }
    #historialDocumentosTable .hd-has-cambios.hd-detail-open td {
        background: #eff6ff !important;
        border-color: #93c5fd !important;
    }
    #historialDocumentosTable .hd-has-cambios.hd-detail-open td:first-child {
        border-left: 4px solid #0067b1 !important;
    }

    /* Desktop: selección clásica (fondo azul en td, borde izquierdo) */
    @media (min-width: 769px) {
        #historialDocumentosTable tr.selected-row-maquinaria {
            background-color: transparent !important;
            border-left: none !important;
        }
        #historialDocumentosTable tr.selected-row-maquinaria td {
            background-color: #e1effa !important;
            color: #0067b1 !important;
            border-top-color: #93c5fd !important;
            border-bottom-color: #93c5fd !important;
            transition: all 0.2s ease;
        }
        #historialDocumentosTable tr.selected-row-maquinaria td:first-child {
            border-left: 4px solid #0067b1 !important;
        }
        #historialDocumentosTable tr.selected-row-maquinaria td:last-child {
            border-right-color: #93c5fd !important;
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
            width: 340px;
            max-width: 460px;
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
            box-shadow: 0 4px 12px rgba(0, 103, 177, 0.15) !important;
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
    function ocultar(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    }
    // Abre/cierra. Al abrir se ancla al borde derecho de su botón; si así se sale por
    // la izquierda (la fila se partió y el botón quedó al inicio de la línea, p. ej.
    // entre 900 y 1023 px de ancho), se ancla al izquierdo. En el teléfono manda el
    // CSS de la vista (con !important).
    function alternar(id) {
        var el = document.getElementById(id);
        if (!el) return;
        var abrir = el.style.display === 'none' || !el.style.display;
        el.style.display = abrir ? 'block' : 'none';
        if (!abrir) return;
        el.style.left = 'auto';
        el.style.right = '0px';
        if (el.getBoundingClientRect().left < 8) {
            el.style.left = '0px';
            el.style.right = 'auto';
        }
    }
    window.hdCerrarAcciones = function () { ocultar('hdAccionesMenu'); };
    window.hdToggleAcciones = function () { alternar('hdAccionesMenu'); };
    window.hdToggleFiltrosAvanzados = function () { alternar('hdAdvancedFilterPanel'); };

    if (window.__hdDesplegablesBound) return;
    window.__hdDesplegablesBound = true;

    // Cierra lo que NO contiene al elemento que recibió el clic o el foco.
    function cerrarFuera(el) {
        if (!el.closest || !document.getElementById('historialTableBody')) return false;
        if (!el.closest('.hd-adv-filter-wrap')) ocultar('hdAdvancedFilterPanel');
        if (!el.closest('.hd-acciones-wrap')) ocultar('hdAccionesMenu');
        return true;
    }
    document.addEventListener('click', function (e) { cerrarFuera(e.target); });
    document.addEventListener('focusin', function (e) {
        if (!cerrarFuera(e.target)) return;
        var box = document.getElementById('hdCorreosSuggest');
        if (e.target.id === 'searchCorreo') {
            window.closeAllDropdowns(null);         // correo enfocado → cerrar el de acción
        } else if (box && !box.contains(e.target)) {
            box.style.display = 'none';             // foco en otro sitio → cerrar sugerencias
        }
    });
})();
</script>

{{-- ═══════════════════════════════════════════════════════════
     PAPELERA — UN solo modal con los vehículos y los auxiliares soft-deleted
     (Acciones → Papelera), mezclados en una lista. Un buscador filtra las dos a la
     vez; los contadores Vehículos / Auxiliares solo informan (no son botones).
     Cargados via AJAX; las rutas exigen user.delete por middleware.
     ═══════════════════════════════════════════════════════════ --}}
@can('super.admin')
<style>
    #hdPapeleraOverlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2500; display: flex; justify-content: center; align-items: center; }
    .hd-pap-modal { background: #fff; border-radius: 14px; width: 92%; max-width: 480px; max-height: 82vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }
    .hd-pap-head { background: #1e293b; padding: 12px 16px; color: #fff; display: flex; justify-content: center; align-items: center; gap: 8px; position: relative; }
    .hd-pap-head h2 { margin: 0; font-size: 14px; font-weight: 700; }
    .hd-pap-cerrar { position: absolute; right: 12px; background: transparent; border: none; color: #fff; cursor: pointer; opacity: 0.7; display: flex; padding: 2px; }
    .hd-pap-cerrar:hover { opacity: 1; }
    .hd-pap-tools { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 8px; flex-shrink: 0; }
    .hd-pap-buscar { display: flex; align-items: center; gap: 6px; border: 1px solid #cbd5e0; border-radius: 8px; background: #fbfcfd; padding: 0 10px; height: 36px; }
    .hd-pap-buscar:focus-within { border-color: #0067b1; background: #fff; }
    .hd-pap-buscar input { flex: 1; min-width: 0; border: none; outline: none; background: transparent; font-size: 13px; height: 100%; color: #1e293b; }
    .hd-pap-limpiar { font-size: 16px !important; color: #94a3b8; cursor: pointer; }
    .hd-pap-cuentas { display: flex; gap: 6px; }
    .hd-pap-cuenta { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 5px; height: 30px; padding: 0 6px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; color: #475569; font-size: 12px; font-weight: 700; white-space: nowrap; }
    .hd-pap-cuenta .material-icons { font-size: 15px; }
    .hd-pap-cuenta[data-kind="eq"] .material-icons { color: #1e40af; }
    .hd-pap-cuenta[data-kind="aux"] .material-icons { color: #c2410c; }
    .hd-pap-list { overflow-y: auto; background: #f8fafc; padding: 10px; flex: 1; min-height: 160px; }
    .hd-pap-row { display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: #fff; border: 1px solid #e2e8f0; border-left-width: 3px; border-radius: 8px; margin-bottom: 5px; }
    .hd-pap-row[data-kind="eq"]  { border-left-color: #1e40af; }
    .hd-pap-row[data-kind="aux"] { border-left-color: #c2410c; }
    .hd-pap-media { width: 42px; height: 42px; border-radius: 6px; flex-shrink: 0; border: 1px solid #e2e8f0; background: #fff; object-fit: contain; display: flex; align-items: center; justify-content: center; }
    .hd-pap-media .material-icons { font-size: 20px; }
    .hd-pap-row[data-kind="eq"]  .hd-pap-ico { background: #eff6ff; color: #1e40af; }
    .hd-pap-row[data-kind="aux"] .hd-pap-ico { background: #fff7ed; color: #c2410c; }
    .hd-pap-info { flex: 1; min-width: 0; }
    .hd-pap-tit { font-weight: 700; color: #1e293b; font-size: 12px; text-transform: uppercase; line-height: 1.2; }
    .hd-pap-tit span { color: #64748b; font-weight: 500; }
    .hd-pap-sub { font-size: 11px; color: #64748b; margin-top: 2px; word-break: break-word; }
    .hd-pap-sub span { color: #f97316; }
    .hd-pap-autor { font-size: 10px; color: #94a3b8; margin-top: 2px; }
    .hd-pap-btns { display: flex; flex-direction: column; gap: 4px; flex-shrink: 0; }
    .hd-pap-btns button { padding: 5px 8px; color: #fff; border: none; border-radius: 6px; display: inline-flex; align-items: center; cursor: pointer; }
    .hd-pap-btns .material-icons { font-size: 13px; }
    .hd-pap-restaurar { background: #10b981; }
    .hd-pap-borrar { background: #ef4444; }
    .hd-pap-vacio { padding: 24px; text-align: center; color: #94a3b8; font-size: 12px; }
    .hd-pap-vacio .material-icons { font-size: 24px; display: block; margin: 0 auto 6px; }
    .hd-pap-aviso { padding: 8px 10px; margin-bottom: 8px; border-radius: 8px; background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; font-size: 12px; }
</style>
<script>
(function () {
    var esc = window.escapeHtml;   // helper central (dom_helpers.js)
    // norm/tokenize salen de window.FuzzySearch (fuzzy_search.js) y se leen AL USARLOS,
    // no aquí: ese script va al final del layout, así que en una carga completa (F5)
    // este bloque corre antes de que exista. Por SPA ya estaba y no se notaba.

    // Las dos fuentes. Cada endpoint trae su propia forma de JSON; normalizar()
    // las lleva a una sola para pintarlas mezcladas.
    var FUENTES = {
        eq:  { url: @json(route('equipos.papelera')),           base: @json(url('admin/equipos')),            nombre: 'vehículo', plural: 'Vehículos',  icono: 'directions_car' },
        aux: { url: @json(route('equipos-auxiliares.papelera')), base: @json(url('admin/equipos-auxiliares')), nombre: 'auxiliar', plural: 'Auxiliares', icono: 'construction' }
    };

    // turno: descarta la respuesta de una carga vieja si ya se pidió otra.
    var estado = { items: [], fallidas: [], term: '', cargado: false, turno: 0 };

    // "dd/mm/aaaa hh:mm" → "aaaammddhhmm", para ordenar las dos listas juntas.
    function claveFecha(s) {
        var m = /^(\d{2})\/(\d{2})\/(\d{4}) (\d{2}):(\d{2})/.exec(s || '');
        return m ? m[3] + m[2] + m[1] + m[4] + m[5] : '';
    }

    function compacto(s) { return s.replace(/[\s\-.\/]/g, ''); }

    function normalizar(it, kind) {
        var aux = kind === 'aux';
        var fecha = (aux ? it.deleted_at : it.eliminado_en) || '';
        var campos = [it.placa, it.serial_chasis, it.serial_motor, it.serial, it.codigo, it.tipo, it.marca, it.modelo]
            .filter(Boolean).map(function (c) { return window.FuzzySearch.norm(c); });
        return {
            kind:   kind,
            id:     it.id,
            tipo:   it.tipo || (aux ? 'AUXILIAR' : 'EQUIPO'),
            meta:   [it.marca, it.modelo].filter(Boolean).join(' '),
            ident:  (aux ? it.serial : (it.placa || it.serial_chasis || it.codigo)) || ('#' + it.id),
            frente: it.frente || '',
            foto:   it.foto_drive_id || '',
            // El endpoint de auxiliares manda null si no se sabe quién lo borró;
            // el de vehículos ya manda 'Desconocido'. Mismo texto para los dos.
            autor:  (aux ? it.deleted_by : it.eliminado_por) || 'Desconocido',
            fecha:  fecha,
            orden:  claveFecha(fecha),
            hay:    campos.join(' '),
            // Sin espacios/guiones/puntos CAMPO POR CAMPO, no todo junto: pegados,
            // placa AB12 + chasis 3CD… harían que "AB123" encontrara este registro.
            camposC: campos.map(compacto)
        };
    }

    // Cada palabra buscada tiene que estar en la placa, los seriales, el código,
    // el tipo, la marca o el modelo (sin acentos ni mayúsculas). También se compara
    // sin espacios, guiones ni puntos: "A12-EA6G" encuentra "A12EA6G". Es por
    // subcadena exacta y no con FuzzySearch.rank a propósito: con tolerancia a
    // typos, "82BD00152" traería también "82BD00576", y aquí se restaura o se
    // borra para siempre lo que sale en la lista.
    function coincide(it, tokens) {
        return tokens.every(function (t) {
            var tc = compacto(t);
            return it.hay.indexOf(t) !== -1 || it.camposC.some(function (c) { return c.indexOf(tc) !== -1; });
        });
    }

    function vacio(icono, texto) {
        return '<div class="hd-pap-vacio"><i class="material-icons">' + icono + '</i>' + texto + '</div>';
    }

    function icono(kind) {
        return '<div class="hd-pap-media hd-pap-ico"><i class="material-icons">' + FUENTES[kind].icono + '</i></div>';
    }

    function fila(it) {
        var media = it.foto
            ? '<img class="hd-pap-media" alt="" src="https://drive.google.com/thumbnail?id=' + encodeURIComponent(it.foto) + '&sz=w120">'
            : icono(it.kind);
        var ref = ' data-kind="' + it.kind + '" data-id="' + esc(String(it.id)) + '"';
        return '<div class="hd-pap-row" data-kind="' + it.kind + '">' + media +
            '<div class="hd-pap-info">' +
                '<div class="hd-pap-tit">' + esc(it.tipo) + (it.meta ? ' · <span>' + esc(it.meta) + '</span>' : '') + '</div>' +
                '<div class="hd-pap-sub">' + esc(it.ident) + (it.frente ? ' · <span>' + esc(it.frente) + '</span>' : '') + '</div>' +
                '<div class="hd-pap-autor">' + esc(it.autor) + (it.fecha ? ' · ' + esc(it.fecha) : '') + '</div>' +
            '</div>' +
            '<div class="hd-pap-btns">' +
                '<button type="button" class="hd-pap-restaurar" data-accion="restaurar"' + ref + ' title="Restaurar"><i class="material-icons">restore</i></button>' +
                '<button type="button" class="hd-pap-borrar" data-accion="borrar"' + ref + ' title="Eliminar permanentemente"><i class="material-icons">delete_forever</i></button>' +
            '</div>' +
        '</div>';
    }

    function pintar() {
        var list = document.getElementById('hdPapeleraList');
        if (!list || !estado.cargado) return;

        var tokens = window.FuzzySearch.tokenize(estado.term);
        var halladas = tokens.length
            ? estado.items.filter(function (it) { return coincide(it, tokens); })
            : estado.items;

        // Contadores (solo informan, no filtran): respetan la búsqueda.
        var n = { eq: 0, aux: 0 };
        halladas.forEach(function (it) { n[it.kind]++; });
        document.querySelectorAll('#hdPapeleraOverlay .hd-pap-cuenta').forEach(function (c) {
            c.querySelector('.n').textContent = n[c.getAttribute('data-kind')];
        });

        var aviso = estado.fallidas.length
            ? '<div class="hd-pap-aviso">No se pudo cargar: ' + estado.fallidas.join(' y ') + '.</div>'
            : '';
        var cuerpo;
        if (halladas.length) cuerpo = halladas.map(fila).join('');
        else if (tokens.length) cuerpo = vacio('search_off', 'Sin coincidencias.');
        else if (estado.fallidas.length) cuerpo = '';   // no decir "vacía" si no se pudo leer
        else cuerpo = vacio('inbox', 'Papelera vacía');
        list.innerHTML = aviso + cuerpo;
    }

    function cargar() {
        var turno = ++estado.turno;
        var kinds = Object.keys(FUENTES);
        Promise.all(kinds.map(function (k) {
            return window.apiFetch(FUENTES[k].url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(function (d) { return (d.items || []).map(function (it) { return normalizar(it, k); }); })
                .catch(function () { return null; });
        })).then(function (listas) {
            if (turno !== estado.turno) return;
            estado.items = [];
            estado.fallidas = [];
            listas.forEach(function (l, i) {
                if (l === null) estado.fallidas.push(FUENTES[kinds[i]].plural);
                else estado.items = estado.items.concat(l);
            });
            // Lo borrado más reciente primero, sin importar de qué lista venga.
            estado.items.sort(function (a, b) { return a.orden < b.orden ? 1 : (a.orden > b.orden ? -1 : 0); });
            estado.cargado = true;
            pintar();
        });
    }

    function cerrar() {
        var o = document.getElementById('hdPapeleraOverlay');
        if (o) o.remove();
    }

    function ejecutar(it, restaurar) {
        var url = FUENTES[it.kind].base + '/' + encodeURIComponent(it.id) + (restaurar ? '/restore' : '/permanente');
        if (window.showPreloader) window.showPreloader();
        window.apiFetch(url, { method: restaurar ? 'PATCH' : 'DELETE', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (b) { return { ok: r.ok, body: b }; }); })
            .then(function (res) {
                if (res.ok && res.body.success) {
                    window.toast(res.body.message || (restaurar ? 'Restaurado.' : 'Eliminado permanentemente.'), 'success');
                    cargar();
                } else {
                    window.toast(res.body.message || (restaurar ? 'No se pudo restaurar.' : 'No se pudo eliminar.'), 'error');
                }
            })
            .catch(function () { window.toast('Error de red.', 'error'); })
            .finally(function () { if (window.hidePreloader) window.hidePreloader(); });
    }

    function confirmar(it, restaurar) {
        var cual = 'el ' + FUENTES[it.kind].nombre + ' "' + esc(it.ident) + '"';
        window.showModal(restaurar ? {
            type: 'info',
            title: 'Restaurar',
            message: '¿Restaurar ' + cual + '?<br><br>Volverá al listado activo.',
            confirmText: 'Restaurar',
            cancelText: 'Cancelar',
            onConfirm: function () { ejecutar(it, true); }
        } : {
            type: 'danger',
            title: 'Eliminar permanentemente',
            message: '¿Eliminar ' + cual + ' de forma permanente?<br>Esta acción no se puede deshacer.',
            confirmText: 'Eliminar',
            cancelText: 'Cancelar',
            onConfirm: function () { ejecutar(it, false); }
        });
    }

    function contador(kind) {
        return '<span class="hd-pap-cuenta" data-kind="' + kind + '"><i class="material-icons">' + FUENTES[kind].icono + '</i>' +
            FUENTES[kind].plural + ' <span class="n">0</span></span>';
    }

    function construir() {
        cerrar();
        var ov = document.createElement('div');
        ov.id = 'hdPapeleraOverlay';
        ov.innerHTML =
            '<div class="hd-pap-modal" role="dialog" aria-modal="true" aria-label="Papelera">' +
                '<div class="hd-pap-head">' +
                    '<i class="material-icons" style="color:#f59e0b;font-size:18px;">delete_sweep</i><h2>Papelera</h2>' +
                    '<button type="button" class="hd-pap-cerrar" data-cerrar title="Cerrar"><i class="material-icons" style="font-size:18px;">close</i></button>' +
                '</div>' +
                '<div class="hd-pap-tools">' +
                    '<div class="hd-pap-buscar">' +
                        '<i class="material-icons" style="font-size:18px;color:#94a3b8;">search</i>' +
                        '<input type="text" id="hdPapeleraBuscar" placeholder="Buscar por placa, serial, código, tipo o modelo..." autocomplete="off">' +
                        '<i class="material-icons hd-pap-limpiar" data-limpiar title="Limpiar" style="display:none;">close</i>' +
                    '</div>' +
                    '<div class="hd-pap-cuentas">' + contador('eq') + contador('aux') + '</div>' +
                '</div>' +
                '<div class="hd-pap-list" id="hdPapeleraList">' +
                    '<div class="hd-pap-vacio"><i class="material-icons" style="animation:spin 1s linear infinite;">sync</i></div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(ov);

        var input = ov.querySelector('#hdPapeleraBuscar');
        var limpiar = ov.querySelector('[data-limpiar]');
        input.addEventListener('input', function () {
            estado.term = input.value;
            limpiar.style.display = input.value ? '' : 'none';
            pintar();
        });

        // Listeners sobre el propio overlay: nace y muere con el modal, así que
        // no se acumulan aunque la vista se vuelva a montar por la SPA.
        ov.addEventListener('click', function (e) {
            if (e.target === ov || e.target.closest('[data-cerrar]')) { cerrar(); return; }
            if (e.target.closest('[data-limpiar]')) {
                input.value = ''; estado.term = ''; limpiar.style.display = 'none';
                pintar(); input.focus();
                return;
            }
            var btn = e.target.closest('[data-accion]');
            if (!btn) return;
            var it = estado.items.find(function (x) { return x.kind === btn.dataset.kind && String(x.id) === btn.dataset.id; });
            if (it) confirmar(it, btn.dataset.accion === 'restaurar');
        });
        // Foto de Drive rota → ícono del tipo. 'error' no burbujea: va en captura.
        ov.addEventListener('error', function (e) {
            var img = e.target;
            if (img.tagName !== 'IMG' || !img.classList.contains('hd-pap-media')) return;
            var row = img.closest('.hd-pap-row');
            if (row) img.outerHTML = icono(row.getAttribute('data-kind'));
        }, true);

        // En PC el cursor va directo al buscador; en el teléfono no, para no
        // tapar la lista con el teclado nada más abrir.
        if (window.matchMedia('(hover: hover)').matches) input.focus();
    }

    // El botón es visible para super.admin, pero la papelera (operación destructiva)
    // exige el permiso literal user.delete. Sin él: toast moderno y NO abre.
    var canDelete = @can('user.delete') true @else false @endcan;

    window.abrirPapelera = function () {
        if (!canDelete) {
            window.showToast('No tienes permiso para gestionar la papelera (requiere "Eliminar Equipos").', 'error');
            return;
        }
        estado.items = [];
        estado.fallidas = [];
        estado.term = '';
        estado.cargado = false;
        construir();
        cargar();
    };

    // Si se navega (SPA, p. ej. con "atrás") con el modal abierto, no dejarlo
    // flotando sobre el módulo nuevo.
    if (!window.__hdPapeleraSpaBound) {
        window.__hdPapeleraSpaBound = true;
        window.addEventListener('spa:contentLoaded', function () {
            var o = document.getElementById('hdPapeleraOverlay');
            if (o) o.remove();
        });
    }
})();
</script>
@endcan

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
window.hdToggleCollapse = function (header) {
    var body = header.parentElement.querySelector('.hd-collapsible-body');
    var chevron = header.querySelector('.hd-chevron');
    if (!body) return;
    body.classList.toggle('hd-open');
    if (chevron) chevron.classList.toggle('hd-open');
};

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

@endsection
