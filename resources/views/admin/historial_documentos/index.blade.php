@extends('layouts.estructura_base')

@section('title', 'Auditoría de Documentos')

@section('content')
<style>
    .badge-doc {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #ebf8ff;
        color: #2b6cb0;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 600;
    }
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
        .hd-filter-row > .filter-item.responsive-filter-item:nth-child(1) {
            flex: 1.2 1 240px !important;
        }
        .hd-filter-row > .filter-item.responsive-filter-item:nth-child(2) {
            flex: 0.9 1 200px !important;
            max-width: 260px !important;
        }
        .hd-filter-row > .filter-item.responsive-filter-item:nth-child(3) {
            flex: 0.7 1 160px !important;
            max-width: 220px !important;
        }
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
    .hd-papelera-group {
        display: inline-flex;
        gap: 6px;
        flex: 0 0 auto;
        width: auto;
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
        .hd-filter-row {
            flex-direction: row !important;
            flex-wrap: wrap !important;
            align-items: stretch !important;
            gap: 8px !important;
        }
        .hd-filter-row > .filter-item.responsive-filter-item:nth-child(1),
        .hd-filter-row > .filter-item.responsive-filter-item:nth-child(2) {
            flex: 1 1 100% !important;
            min-width: 0 !important;
        }
        .hd-filter-row > .filter-item.responsive-filter-item:nth-child(3) {
            flex: 1 1 0 !important;
            min-width: 0 !important;
        }
        .hd-adv-filter-wrap {
            flex: 0 0 45px !important;
        }
        .hd-papelera-group {
            display: flex;
            flex: 1 1 100%;
            gap: 8px;
        }
        .hd-papelera-group > button {
            flex: 1 1 0 !important;
            width: 100% !important;
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
            font-size: 11px;
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
    }
</style>

<section class="page-title-card" style="text-align: left; margin: 0 auto 16px auto; padding: 0; width: 98%; max-width: 1600px;">
    <h1 class="page-title" style="display: flex; align-items: center; gap: 12px; font-size: 24px;">
        <span class="page-title-line2" style="color: #000; margin: 0;">Auditoría de Documentos</span>
    </h1>
</section>

<div class="maquinaria-layout-container hd-layout-grid" style="display: grid; grid-template-columns: 1fr 280px; gap: 20px; width: 98%; max-width: 1600px; margin: 0 auto;">
    
    <!-- Left Column (Main Content) -->
    <div>
        <div class="admin-card">
            <div class="filter-toolbar-container hd-filter-row" style="margin-bottom: 5px;">
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

                <!-- Filter Tipo de Accion -->
                @php
                    $reqTipo = request('search_tipo');
                    $tipoActivo = $reqTipo && $reqTipo !== 'all';
                    // Etiquetas amigables para las categorías agrupadas (valores 'cat_*'),
                    // así el placeholder no muestra el valor crudo al recargar la página.
                    $tipoLabels = [
                        'cat_uploads'   => 'Subida de documento',
                        'cat_borrados'  => 'Borrado de documento',
                        'cat_metadatos' => 'Edición de metadatos',
                    ];
                    $reqTipoLabel = $tipoLabels[$reqTipo] ?? $reqTipo;
                @endphp
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
                                onfocus="this.closest('.custom-dropdown').classList.add('active'); var p=document.getElementById('hdAdvancedFilterPanel'); if(p) p.style.display='none';"
                                autocomplete="off">

                            <div style="display: flex; align-items: center; padding-right: 10px;">
                                <i class="material-icons" data-clear-btn
                                   style="font-size: 18px; color: #a0aec0; margin-right: 5px; display: {{ $tipoActivo ? 'block' : 'none' }};"
                                   onclick="event.stopPropagation(); clearDropdownFilter('tipoDocFilterSelect'); window.loadHistorialDocumentos();"
                                   title="Limpiar filtro">close</i>
                            </div>
                        </div>

                        <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible;">
                            <div class="dropdown-item-list" style="max-height: 320px; overflow-y: auto;">
                                <div class="dropdown-item {{ !$tipoActivo ? 'selected' : '' }}" data-value="all" onclick="selectOption('tipoDocFilterSelect', 'all', 'TODAS LAS ACCIONES'); window.loadHistorialDocumentos();">
                                    TODAS LAS ACCIONES
                                </div>

                                {{-- Acciones sobre el equipo (audit log) --}}
                                <div style="padding:4px 8px 2px; font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; border-top:1px solid #e2e8f0; margin-top:4px;">SOBRE EL EQUIPO</div>
                                <div class="dropdown-item {{ $reqTipo === 'Registro de Vehículo' ? 'selected' : '' }}" data-value="Registro de Vehículo" onclick="selectOption('tipoDocFilterSelect', 'Registro de Vehículo', 'Registro de Vehículo'); window.loadHistorialDocumentos();">Registro de Vehículo</div>
                                <div class="dropdown-item {{ $reqTipo === 'Edición de Datos' ? 'selected' : '' }}" data-value="Edición de Datos" onclick="selectOption('tipoDocFilterSelect', 'Edición de Datos', 'Edición de Datos'); window.loadHistorialDocumentos();">Edición de Datos</div>
                                <div class="dropdown-item {{ $reqTipo === 'Detalle Masivo' ? 'selected' : '' }}" data-value="Detalle Masivo" onclick="selectOption('tipoDocFilterSelect', 'Detalle Masivo', 'Detalle Masivo'); window.loadHistorialDocumentos();">Detalle Masivo</div>
                                <div class="dropdown-item {{ $reqTipo === 'Eliminación de Equipo' ? 'selected' : '' }}" data-value="Eliminación de Equipo" onclick="selectOption('tipoDocFilterSelect', 'Eliminación de Equipo', 'Eliminación de Equipo'); window.loadHistorialDocumentos();">Eliminación de Equipo</div>

                                {{-- Acciones sobre documentos: 1 opción por acción (antes había 6
                                     por cada tipo de documento). El backend mapea estos valores
                                     'cat_*' a los doc_key correspondientes (ver HistorialDocumentosController). --}}
                                <div style="padding:4px 8px 2px; font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; border-top:1px solid #e2e8f0; margin-top:4px;">DOCUMENTOS</div>
                                <div class="dropdown-item {{ $reqTipo === 'cat_uploads' ? 'selected' : '' }}" data-value="cat_uploads" onclick="selectOption('tipoDocFilterSelect', 'cat_uploads', 'Subida de documento'); window.loadHistorialDocumentos();">Subida de documento</div>
                                <div class="dropdown-item {{ $reqTipo === 'cat_borrados' ? 'selected' : '' }}" data-value="cat_borrados" onclick="selectOption('tipoDocFilterSelect', 'cat_borrados', 'Borrado de documento'); window.loadHistorialDocumentos();">Borrado de documento</div>
                                <div class="dropdown-item {{ $reqTipo === 'cat_metadatos' ? 'selected' : '' }}" data-value="cat_metadatos" onclick="selectOption('tipoDocFilterSelect', 'cat_metadatos', 'Edición de metadatos'); window.loadHistorialDocumentos();">Edición de metadatos</div>

                                <div style="padding:4px 8px 2px; font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; border-top:1px solid #e2e8f0; margin-top:4px;">CATÁLOGO DE MODELOS</div>
                                <div class="dropdown-item {{ $reqTipo === 'Registro de Modelo' ? 'selected' : '' }}" data-value="Registro de Modelo" onclick="selectOption('tipoDocFilterSelect', 'Registro de Modelo', 'Registro de Modelo'); window.loadHistorialDocumentos();">Registro de Modelo</div>
                                <div class="dropdown-item {{ $reqTipo === 'Edición de Modelo' ? 'selected' : '' }}" data-value="Edición de Modelo" onclick="selectOption('tipoDocFilterSelect', 'Edición de Modelo', 'Edición de Modelo'); window.loadHistorialDocumentos();">Edición de Modelo</div>
                                <div class="dropdown-item {{ $reqTipo === 'Foto de Modelo' ? 'selected' : '' }}" data-value="Foto de Modelo" onclick="selectOption('tipoDocFilterSelect', 'Foto de Modelo', 'Foto de Modelo'); window.loadHistorialDocumentos();">Foto de Modelo</div>
                                <div class="dropdown-item {{ $reqTipo === 'Foto de Auxiliar' ? 'selected' : '' }}" data-value="Foto de Auxiliar" onclick="selectOption('tipoDocFilterSelect', 'Foto de Auxiliar', 'Foto de Auxiliar'); window.loadHistorialDocumentos();">Foto de Auxiliar</div>
                                <div class="dropdown-item {{ $reqTipo === 'Eliminación de Modelo' ? 'selected' : '' }}" data-value="Eliminación de Modelo" onclick="selectOption('tipoDocFilterSelect', 'Eliminación de Modelo', 'Eliminación de Modelo'); window.loadHistorialDocumentos();">Eliminación de Modelo</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Boton Filtros Avanzados (rango de fechas en popover).
                     Se resalta en rojo si hay algun filtro activo. --}}
                @php
                    $hasAdvHd = request()->filled('fecha_desde') || request()->filled('fecha_hasta');
                @endphp
                <div class="hd-adv-filter-wrap">
                    <button type="button" id="btnHdAdvancedFilter"
                        onclick="event.stopPropagation(); var dd=document.getElementById('tipoDocFilterSelect'); if(dd) dd.classList.remove('active'); var p=document.getElementById('hdAdvancedFilterPanel'); p.style.display = (p.style.display==='none'||!p.style.display) ? 'block' : 'none';"
                        title="Filtros Avanzados (fechas)"
                        style="height: 45px; width: 45px; padding: 0; border-radius: 12px; background: {{ $hasAdvHd ? '#fee2e2' : 'white' }}; border: 1px solid {{ $hasAdvHd ? '#ef4444' : '#cbd5e0' }}; color: {{ $hasAdvHd ? '#ef4444' : '#64748b' }}; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;">
                        <i class="material-icons">filter_list</i>
                    </button>
                    <div id="hdAdvancedFilterPanel" style="display:none; position:absolute; top:100%; right:0; width:280px; max-width:calc(100vw - 24px); background:#e2e8f0; border:1px solid #cbd5e1; border-radius:12px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.15); margin-top:8px; padding:14px; z-index:100;">
                        <h4 style="margin:0 0 12px 0; font-size:13px; font-weight:700; color:#334155; display:flex; justify-content:space-between; align-items:center;">
                            Filtros Avanzados
                            <span style="font-size:11px; color:#64748b; font-weight:400; text-decoration:underline; cursor:pointer;"
                                  onclick="document.getElementById('hdFechaDesde').value=''; document.getElementById('hdFechaHasta').value=''; var x1=document.getElementById('hdClrFechaDesde'); if(x1)x1.style.display='none'; var x2=document.getElementById('hdClrFechaHasta'); if(x2)x2.style.display='none'; window.loadHistorialDocumentos && window.loadHistorialDocumentos();">Limpiar</span>
                        </h4>
                        <div style="margin-bottom:10px;">
                            <span style="display:block; font-size:11px; font-weight:600; color:#64748b; margin-bottom:5px;">Fecha desde</span>
                            <div style="display:flex; align-items:center; gap:4px;">
                                <input type="date" id="hdFechaDesde" name="fecha_desde" value="{{ request('fecha_desde') }}"
                                    onchange="window.hdToggleDateClear && window.hdToggleDateClear('hdFechaDesde','hdClrFechaDesde'); window.loadHistorialDocumentos && window.loadHistorialDocumentos()"
                                    onclick="try { this.showPicker(); } catch(e) {}"
                                    style="flex:1; min-width:0; box-sizing:border-box; padding:7px 10px; border:1px solid {{ request('fecha_desde') ? '#0067b1' : '#cbd5e0' }}; border-radius:8px; font-size:13px; color:#334155; background:{{ request('fecha_desde') ? '#e1effa' : 'white' }}; cursor:pointer;">
                                <i id="hdClrFechaDesde" class="material-icons"
                                   style="display:{{ request('fecha_desde') ? 'inline-flex' : 'none' }}; cursor:pointer; color:#64748b; font-size:18px; padding:6px;"
                                   onclick="event.stopPropagation(); var d=document.getElementById('hdFechaDesde'); d.value=''; this.style.display='none'; window.loadHistorialDocumentos && window.loadHistorialDocumentos();"
                                   title="Limpiar fecha desde">close</i>
                            </div>
                        </div>
                        <div>
                            <span style="display:block; font-size:11px; font-weight:600; color:#64748b; margin-bottom:5px;">Fecha hasta</span>
                            <div style="display:flex; align-items:center; gap:4px;">
                                <input type="date" id="hdFechaHasta" name="fecha_hasta" value="{{ request('fecha_hasta') }}"
                                    onchange="window.hdToggleDateClear && window.hdToggleDateClear('hdFechaHasta','hdClrFechaHasta'); window.loadHistorialDocumentos && window.loadHistorialDocumentos()"
                                    onclick="try { this.showPicker(); } catch(e) {}"
                                    style="flex:1; min-width:0; box-sizing:border-box; padding:7px 10px; border:1px solid {{ request('fecha_hasta') ? '#0067b1' : '#cbd5e0' }}; border-radius:8px; font-size:13px; color:#334155; background:{{ request('fecha_hasta') ? '#e1effa' : 'white' }}; cursor:pointer;">
                                <i id="hdClrFechaHasta" class="material-icons"
                                   style="display:{{ request('fecha_hasta') ? 'inline-flex' : 'none' }}; cursor:pointer; color:#64748b; font-size:18px; padding:6px;"
                                   onclick="event.stopPropagation(); var d=document.getElementById('hdFechaHasta'); d.value=''; this.style.display='none'; window.loadHistorialDocumentos && window.loadHistorialDocumentos();"
                                   title="Limpiar fecha hasta">close</i>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Papelera buttons agrupados — desktop: pegados con gap 6px;
                     mobile: full width compartido 50/50 (regla en .hd-papelera-group).
                     Botones directos (sin .filter-item .aligned-filter) para evitar
                     que el width:100% del .aligned-filter los ensanche y separe. --}}
                {{-- Visible para super.admin (vea o no el permiso user.delete). La
                     papelera en sí exige user.delete: si no lo tiene, al hacer clic
                     sale un toast y no abre (ver guard en abrirPapelera*). --}}
                @can('super.admin')
                <div class="hd-papelera-group">
                    <button type="button" id="btnVerPapeleraEquipos"
                        onclick="window.abrirPapeleraEquipos && window.abrirPapeleraEquipos()"
                        title="Papelera de Vehículos"
                        style="height: 45px; padding: 0 9px; border-radius: 12px; background: white; border: 1px solid #fcd34d; color: #d97706; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 5px; font-size: 12px; font-weight: 700; white-space: nowrap;"
                        onmouseover="this.style.background='#fef3c7'"
                        onmouseout="this.style.background='white'">
                        <i class="material-icons" style="font-size:18px;">directions_car</i>
                        <span>Vehículos</span>
                    </button>
                    <button type="button" id="btnVerPapeleraAux"
                        onclick="window.abrirPapeleraAuxiliares && window.abrirPapeleraAuxiliares()"
                        title="Papelera de Auxiliares"
                        style="height: 45px; padding: 0 9px; border-radius: 12px; background: white; border: 1px solid #fed7aa; color: #c2410c; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 5px; font-size: 12px; font-weight: 700; white-space: nowrap;"
                        onmouseover="this.style.background='#fff7ed'"
                        onmouseout="this.style.background='white'">
                        <i class="material-icons" style="font-size:18px;">construction</i>
                        <span>Auxiliares</span>
                    </button>
                </div>
                @endcan

            </div>

            <!-- Unified Responsive Table -->
            <div class="custom-scrollbar-container">
                <table class="admin-table table-historial-mobile" id="historialDocumentosTable" style="width: 100% !important;">
                    <thead>
                        <tr style="background: #334155; text-align: left; color: #ffffff; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; border-bottom: 2px solid #1e293b;">
                            <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 150px;">Fecha y Hora</th>
                            <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 200px;">Autor</th>
                            <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 180px;">Tipo de Acción</th>
                            <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 200px;">Equipo Asociado</th>
                            <th style="padding: 10px 15px; text-align: center; width: 100px;">Ver PDF</th>
                        </tr>
                    </thead>
                    <tbody id="historialTableBody" style="font-size: 14px;">
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
    <div class="counter-sidebar historial-sidebar" id="historialSidebar" style="position: sticky; top: 20px; display: flex; flex-direction: column; gap: 20px; z-index: 10;">

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
        <div style="background: white; border-radius: 12px; padding: 15px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.06); margin-top: 10px;">
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


{{-- Cierre del panel "Filtros Avanzados" al click fuera. Idempotente. --}}
<script>
    (function () {
        if (window._hdAdvFilterOutsideAttached) return;
        window._hdAdvFilterOutsideAttached = true;
        document.addEventListener('click', function (ev) {
            var panel = document.getElementById('hdAdvancedFilterPanel');
            var wrap  = ev.target.closest('.hd-adv-filter-wrap');
            if (!panel || panel.style.display === 'none') return;
            if (!wrap) panel.style.display = 'none';
        });
    })();
</script>

{{-- ═══════════════════════════════════════════════════════════
     PAPELERA — modales de vehículos + auxiliares soft-deleted.
     Cargados via AJAX, respetan el permiso user.delete via middleware.
     ═══════════════════════════════════════════════════════════ --}}
@can('super.admin')
<script>
(function () {
    var csrfTok = function () { return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''; };
    var esc = function (s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); };

    // Cache de los items cargados por lista (keyed por listElId) para filtrar
    // en cliente sin volver a pedir al backend. Guarda { items, kind }.
    var papeleraCache = {};

    function buildModal(id, title) {
        var old = document.getElementById(id + 'Overlay');
        if (old) old.remove();
        var overlay = document.createElement('div');
        overlay.id = id + 'Overlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.5);z-index:2500;display:flex;justify-content:center;align-items:center;';
        overlay.innerHTML = '<div style="background:white;border-radius:14px;width:90%;max-width:440px;max-height:80vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">' +
            '<div style="background:#1e293b;padding:12px 16px;color:white;display:flex;justify-content:center;align-items:center;position:relative;">' +
                '<div style="display:flex;align-items:center;gap:8px;">' +
                    '<i class="material-icons" style="color:#f59e0b;font-size:18px;">history</i>' +
                    '<h2 style="margin:0;font-size:14px;font-weight:700;">' + title + '</h2>' +
                '</div>' +
                '<button type="button" onclick="document.getElementById(\'' + id + 'Overlay\').remove();" style="position:absolute;right:12px;background:transparent;border:none;color:white;cursor:pointer;opacity:0.7;"><i class="material-icons" style="font-size:18px;">close</i></button>' +
            '</div>' +
            '<div style="padding:8px 10px;background:white;border-bottom:1px solid #e2e8f0;flex-shrink:0;">' +
                '<div style="display:flex;align-items:center;gap:6px;border:1px solid #cbd5e0;border-radius:8px;background:#fbfcfd;padding:0 10px;height:36px;">' +
                    '<i class="material-icons" style="font-size:18px;color:#94a3b8;">search</i>' +
                    '<input type="text" id="' + id + 'Search" placeholder="Buscar por placa, serial de chasis o de motor..." autocomplete="off" oninput="window.hdPapeleraFilter(this.value, \'' + id + 'List\')" style="flex:1;border:none;outline:none;background:transparent;font-size:13px;height:100%;color:#1e293b;">' +
                '</div>' +
            '</div>' +
            '<div id="' + id + 'List" style="overflow-y:auto;background:#f8fafc;padding:10px;flex:1;min-height:160px;">' +
                '<div style="padding:24px;text-align:center;color:#94a3b8;"><i class="material-icons" style="animation:spin 1s linear infinite;font-size:22px;">sync</i></div>' +
            '</div>' +
        '</div>';
        document.body.appendChild(overlay);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
        return overlay;
    }

    function renderRow(it, kind) {
        var idStr = it.placa || it.serial_chasis || it.serial || it.codigo || ('#' + it.id);
        var iconCol = kind === 'aux' ? '#c2410c' : '#1e40af';
        var iconBg  = kind === 'aux' ? '#fff7ed' : '#eff6ff';
        var iconNm  = kind === 'aux' ? 'construction' : 'directions_car';
        var fotoHtml = it.foto_drive_id
            ? '<img src="https://drive.google.com/thumbnail?id=' + esc(it.foto_drive_id) + '&sz=w120" style="width:42px;height:42px;border-radius:6px;object-fit:contain;background:white;border:1px solid #e2e8f0;flex-shrink:0;" onerror="this.outerHTML=\'<div style=&quot;width:42px;height:42px;border-radius:6px;background:' + iconBg + ';color:' + iconCol + ';display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid #e2e8f0;&quot;><i class=&quot;material-icons&quot; style=&quot;font-size:20px;&quot;>' + iconNm + '</i></div>\'">'
            : '<div style="width:42px;height:42px;border-radius:6px;background:' + iconBg + ';color:' + iconCol + ';display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid #e2e8f0;"><i class="material-icons" style="font-size:20px;">' + iconNm + '</i></div>';
        var fn = kind === 'aux' ? 'window.recuperarAuxiliar' : 'window.recuperarEquipo';
        var meta = [esc(it.marca || ''), esc(it.modelo || '')].filter(Boolean).join(' ');
        return '<div style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:white;border-radius:8px;border:1px solid #e2e8f0;margin-bottom:5px;">' +
            fotoHtml +
            '<div style="flex:1;min-width:0;">' +
                '<div style="font-weight:700;color:#1e293b;font-size:12px;text-transform:uppercase;line-height:1.2;">' + esc(it.tipo || (kind === 'aux' ? 'AUXILIAR' : 'EQUIPO')) + (meta ? ' · <span style="color:#64748b;font-weight:500;">' + meta + '</span>' : '') + '</div>' +
                '<div style="font-size:11px;color:#64748b;margin-top:2px;">' + esc(idStr) + (it.frente ? ' · <span style="color:#f97316;">' + esc(it.frente) + '</span>' : '') + '</div>' +
                '<div style="font-size:10px;color:#94a3b8;margin-top:2px;">' + esc(it.eliminado_por || it.deleted_by || '') + (it.eliminado_en || it.deleted_at ? ' · ' + esc(it.eliminado_en || it.deleted_at) : '') + '</div>' +
            '</div>' +
            '<button type="button" onclick="' + fn + '(' + it.id + ', \'' + esc(idStr).replace(/\'/g, "\\\'") + '\')" style="padding:5px 8px;font-size:11px;background:#10b981;color:white;border:none;border-radius:6px;display:inline-flex;align-items:center;gap:3px;cursor:pointer;font-weight:700;">' +
                '<i class="material-icons" style="font-size:13px;">restore</i>' +
            '</button>' +
        '</div>';
    }

    // Coincide el término contra los identificadores del item: serial de chasis,
    // serial de motor y placa (vehículos) o serial (auxiliares), + código.
    function matchItem(it, term) {
        var hay = [it.serial_chasis, it.serial_motor, it.placa, it.serial, it.codigo]
            .filter(Boolean).join(' ').toLowerCase();
        return hay.indexOf(term) !== -1;
    }

    // Renderiza la lista (filtrada por `term` si lo hay) desde el cache.
    function renderListItems(listElId, term) {
        var list = document.getElementById(listElId);
        var c = papeleraCache[listElId];
        if (!list || !c) return;
        var items = term ? c.items.filter(function (it) { return matchItem(it, term); }) : c.items;
        if (!items.length) {
            list.innerHTML = term
                ? '<div style="padding:24px;text-align:center;color:#94a3b8;font-size:12px;"><i class="material-icons" style="font-size:24px;display:block;margin:0 auto 6px;">search_off</i>Sin coincidencias.</div>'
                : '<div style="padding:24px;text-align:center;color:#94a3b8;font-size:12px;"><i class="material-icons" style="font-size:24px;display:block;margin:0 auto 6px;">inbox</i>Papelera vacía</div>';
            return;
        }
        list.innerHTML = items.map(function (it) { return renderRow(it, c.kind); }).join('');
    }

    // Llamado desde el input del modal (oninput).
    window.hdPapeleraFilter = function (term, listElId) {
        renderListItems(listElId, (term || '').trim().toLowerCase());
    };

    function loadList(url, kind, listElId) {
        var list = document.getElementById(listElId);
        fetch(url, { headers: { 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest' }})
            .then(function (r) { return r.json(); })
            .then(function (data) {
                papeleraCache[listElId] = { items: data.items || [], kind: kind };
                renderListItems(listElId, ''); // sin filtro al cargar
            })
            .catch(function () {
                list.innerHTML = '<div style="padding:24px;text-align:center;color:#ef4444;font-size:12px;">Error al cargar la papelera.</div>';
            });
    }

    // El botón es visible para super.admin, pero la papelera (operación destructiva)
    // exige el permiso literal user.delete. Sin él: toast moderno y NO abre.
    var canDelete = @can('user.delete') true @else false @endcan;
    function papeleraSinPermiso() {
        var msg = 'No tienes permiso para gestionar la papelera (requiere "Eliminar Equipos").';
        if (window.showToast) window.showToast(msg, 'error');
        else if (window.showModal) window.showModal({ type: 'error', title: 'Acceso denegado', message: msg, confirmText: 'Entendido', hideCancel: true });
    }
    window.abrirPapeleraEquipos = function () {
        if (!canDelete) { papeleraSinPermiso(); return; }
        buildModal('papeleraEquipos', 'Papelera de Vehículos');
        loadList('{{ route("equipos.papelera") }}', 'eq', 'papeleraEquiposList');
    };
    window.abrirPapeleraAuxiliares = function () {
        if (!canDelete) { papeleraSinPermiso(); return; }
        buildModal('papeleraAux', 'Papelera de Auxiliares');
        loadList('{{ route("equipos-auxiliares.papelera") }}', 'aux', 'papeleraAuxList');
    };

    function restoreItem(url, label, refreshFn) {
        var doRestore = function () {
            if (window.showPreloader) window.showPreloader();
            fetch(url, { method: 'PATCH', headers: { 'X-CSRF-TOKEN': csrfTok(), 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }})
                .then(function (r) { return r.json().catch(function () { return {}; }).then(function (b) { return { ok: r.ok, body: b }; }); })
                .then(function (res) {
                    if (window.hidePreloader) window.hidePreloader();
                    if (res.ok && res.body.success) {
                        if (window.showToast) window.showToast(res.body.message || 'Restaurado.', 'success');
                        refreshFn();
                    } else {
                        if (window.showToast) window.showToast((res.body && res.body.message) || 'No se pudo restaurar.', 'error');
                    }
                })
                .catch(function () {
                    if (window.hidePreloader) window.hidePreloader();
                    if (window.showToast) window.showToast('Error de red.', 'error');
                });
        };
        // Modal moderno (showModal global) en lugar del confirm() nativo
        // del navegador — consistente con el resto del sistema.
        if (typeof window.showModal === 'function') {
            window.showModal({
                type: 'info',
                title: 'Restaurar',
                message: '¿Restaurar "' + label + '"?\n\nVolverá al listado activo.',
                confirmText: 'Restaurar',
                cancelText: 'Cancelar',
                onConfirm: doRestore
            });
        } else {
            doRestore();
        }
    }

    window.recuperarEquipo = function (id, label) {
        restoreItem('{{ url("admin/equipos") }}/' + id + '/restore', label, window.abrirPapeleraEquipos);
    };
    window.recuperarAuxiliar = function (id, label) {
        restoreItem('{{ url("admin/equipos-auxiliares") }}/' + id + '/restore', label, window.abrirPapeleraAuxiliares);
    };
})();
</script>
@endcan

<script>
(function () {
    if (window.__hdCorreoAutoInit) return;
    window.__hdCorreoAutoInit = true;
    var AUTORES = @json($autoresSugeridos ?? []);
    var esc = function (s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); };

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
document.addEventListener('click', function (e) {
    var tr = e.target.closest('.table-historial-mobile tbody tr');
    if (!tr) return;
    document.querySelectorAll('.table-historial-mobile tbody tr.hd-row-selected').forEach(function (o) {
        if (o !== tr) o.classList.remove('hd-row-selected');
    });
    tr.classList.toggle('hd-row-selected');
});
document.addEventListener('click', function (e) {
    var row = e.target.closest('.hd-selectable-row');
    if (!row) return;
    var detail = row.querySelector('.hd-cambios-detail');
    if (!detail) return;
    document.querySelectorAll('.hd-cambios-detail').forEach(function (d) {
        if (d !== detail) d.style.display = 'none';
    });
    detail.style.display = detail.style.display === 'block' ? 'none' : 'block';
});
</script>

@endsection
