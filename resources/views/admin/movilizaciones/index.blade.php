@extends('layouts.estructura_base')

@section('title', 'Movilizaciones de Equipos y Maquinarias')

@section('content')
<section class="page-title-card" style="text-align: left; margin: 0 0 10px 0;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color: #000; white-space: normal; word-break: break-word;">Bitácora de Movilizaciones y Actualizaciones</span>
    </h1>
</section>


<div class="movilizaciones-layout" style="align-items: start; width: 100%;">
    
    <!-- Left Column: Table & Filters -->
    <div class="admin-card movilizaciones-main-card" style="margin: 0; width: 100%;">

        <!-- Stats compactas visibles solo en mobile -->
        <div class="movilizaciones-mobile-stats">
            <div class="stat-pill">
                <i class="material-icons">local_shipping</i>
                <span id="mobileTransitoCount">{{ $totalTransito }}</span> Operaciones
            </div>
        </div>

        <!-- Filter Toolbar -->
        <div class="movilizaciones-filter-bar filter-toolbar-container" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center; margin-bottom: 5px;">
        @php
            $authUser       = auth()->user();
            $isLocalUser    = $authUser && $authUser->NIVEL_ACCESO == 2;
            $dashFrenteIds  = $authUser ? $authUser->getFrentesIds() : [];
        @endphp

        {{-- =====================================================================
             FILTRO FRENTE: Restringido a frentes permitidos
             ===================================================================== --}}
        <div class="mv-filter-item">
            @php
                $currentFrenteId = request('id_frente');
                $currentFrente = $currentFrenteId && $currentFrenteId !== 'all' ? $frentes->firstWhere('ID_FRENTE', $currentFrenteId) : null;
                $frentesDropdown = $isLocalUser ? $frentes->whereIn('ID_FRENTE', $dashFrenteIds) : $frentes;
                $placeholderText = $currentFrente ? $currentFrente->NOMBRE_FRENTE : ($isLocalUser ? 'Mis Frentes' : 'Todos los Frentes');
            @endphp
            <div class="custom-dropdown" id="frenteFilterSelect" data-filter-type="id_frente" data-default-label="{{ $isLocalUser ? 'Mis Frentes' : 'Todos los Frentes' }}">
                <input type="hidden" name="id_frente" data-filter-value value="{{ $currentFrenteId }}" form="search-form">

                <div class="dropdown-trigger {{ $currentFrenteId && $currentFrenteId !== 'all' ? 'filter-active' : '' }}" style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:12px; height:45px;">
                    <div style="padding:0 10px; display:flex; align-items:center; color:var(--maquinaria-gray-text);">
                        <i class="material-icons" style="font-size:18px;">search</i>
                    </div>
                    <input type="text" name="filter_search_dropdown" data-filter-search
                        placeholder="{{ $placeholderText }}"
                        aria-label="Filtrar Frente"
                        style="flex:1; border:none; background:transparent; padding:10px 5px; font-size:14px; outline:none; min-width:0;"
                        oninput="window.filterDropdownOptions(this)"
                        autocomplete="off">
                    <i class="material-icons" data-clear-btn
                        style="padding:0 5px; color:var(--maquinaria-gray-text); font-size:18px; display:{{ $currentFrenteId && $currentFrenteId != 'all' ? 'block' : 'none' }}; cursor:pointer;"
                        onclick="event.stopPropagation(); clearDropdownFilter('frenteFilterSelect');">close</i>
                </div>

                <div class="dropdown-content" style="padding:5px; max-height:none; overflow:visible;">
                    <div class="dropdown-item-list" style="max-height:250px; overflow-y:auto;">
                        <div class="dropdown-item {{ !$currentFrenteId || $currentFrenteId == 'all' ? 'selected' : '' }}"
                             data-value="all"
                             onclick="selectOption('frenteFilterSelect', 'all', '{{ $isLocalUser ? 'MIS FRENTES' : 'TODOS LOS FRENTES' }}');">
                            {{ $isLocalUser ? 'MIS FRENTES' : 'TODOS LOS FRENTES' }}
                        </div>
                        @foreach($frentesDropdown as $frente)
                            <div class="dropdown-item {{ $currentFrenteId == $frente->ID_FRENTE ? 'selected' : '' }}"
                                 data-value="{{ $frente->ID_FRENTE }}"
                                 onclick="selectOption('frenteFilterSelect', '{{ $frente->ID_FRENTE }}', '{{ addslashes($frente->NOMBRE_FRENTE) }}');">
                                {{ $frente->NOMBRE_FRENTE }}
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

            <!-- Tipo Filter -->
            <div class="mv-filter-item">
                <div class="custom-dropdown" id="tipoFilterSelect" data-filter-type="id_tipo" data-default-label="Filtrar Tipo...">
                    <input type="hidden" name="id_tipo" data-filter-value value="{{ request('id_tipo') }}" form="search-form">
                    
                    @php 
                        $currentTipo = $allTipos->firstWhere('id', request('id_tipo'));
                    @endphp

                    <div class="dropdown-trigger {{ request('id_tipo') ? 'filter-active' : '' }}" style="padding: 0; display: flex; align-items: center; background: #fbfcfd; overflow: hidden; border: 1px solid #cbd5e0; border-radius: 12px; height: 45px;">
                        <div style="padding: 0 10px; display: flex; align-items: center; color: var(--maquinaria-gray-text);">
                            <i class="material-icons" style="font-size: 18px;">search</i>
                        </div>
                        <input type="text" name="filter_search_dropdown" data-filter-search
                            placeholder="{{ $currentTipo ? $currentTipo->nombre : 'Filtrar Tipo...' }}" 
                             aria-label="Filtrar Tipo"
                            style="flex: 1; border: none; background: transparent; padding: 10px 5px; font-size: 14px; outline: none; min-width: 0;"
                            oninput="window.filterDropdownOptions(this)"
                            autocomplete="off">
                        <i class="material-icons" data-clear-btn style="padding: 0 5px; color: var(--maquinaria-gray-text); font-size: 18px; display: {{ request('id_tipo') ? 'block' : 'none' }}; cursor:pointer;" onclick="event.stopPropagation(); clearDropdownFilter('tipoFilterSelect');">close</i>
                    </div>

                    <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible;">
                        <div class="dropdown-item-list" style="max-height: 250px; overflow-y: auto;">
                            <div class="dropdown-item {{ !request('id_tipo') || request('id_tipo') == 'all' ? 'selected' : '' }}" data-value="all" onclick="selectOption('tipoFilterSelect', 'all', 'TODOS LOS TIPOS');">
                                TODOS LOS TIPOS
                            </div>
                            @foreach($allTipos as $tipo)
                                <div class="dropdown-item {{ request('id_tipo') == $tipo->id ? 'selected' : '' }}" data-value="{{ $tipo->id }}" onclick="selectOption('tipoFilterSelect', '{{ $tipo->id }}', '{{ addslashes($tipo->nombre) }}');">
                                    {{ $tipo->nombre }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <!-- Fila: Búsqueda + Botón Filtro Avanzado (siempre juntos) -->
            <div class="mv-search-adv-row">

                <!-- Search Filter -->
                <div class="mv-filter-item mv-search-item">
                    <form action="{{ route('movilizaciones.index') }}" method="GET" id="search-form" onsubmit="event.preventDefault(); window.loadMovilizaciones();" style="margin: 0;">
                        <div class="search-wrapper" style="width: 100%; border-color: {{ request('search') ? '#0067b1' : '#cbd5e0' }}; background: {{ request('search') ? '#e1effa' : '#fff' }};">
                            <i class="material-icons search-icon">search</i>
                            <input type="text" id="searchInput" name="search" value="{{ request('search') }}"
                                placeholder="Buscar Control o Equipo"
                                class="search-input-field"
                                autocomplete="off"
                                oninput="clearTimeout(window._mvSearchTimer); window._mvSearchTimer = setTimeout(() => { const btn = document.getElementById('btn_clear_search'); if (btn) btn.style.display = this.value.length > 0 ? 'block' : 'none'; window.loadMovilizaciones(); }, 400);">
                            <i id="btn_clear_search" class="material-icons clear-icon" style="display: {{ request('search') ? 'block' : 'none' }};" onclick="document.getElementById('searchInput').value = ''; this.style.display = 'none'; window.loadMovilizaciones();">close</i>
                        </div>
                    </form>
                </div>

                <!-- Botón Filtro Avanzado (Fechas) -->
                <div class="mv-adv-filter-wrap" style="position: relative; flex-shrink: 0;">
                    @php
                        $hasAnyAdv = request('fecha_desde') || request('fecha_hasta') || request('direccion_frente');
                    @endphp
                    <button type="button" id="btnAdvancedFilter" class="btn-primary-maquinaria"
                        style="height: 45px; width: 45px; padding: 0; display: flex; align-items: center; justify-content: center; background: {{ $hasAnyAdv ? '#fee2e2' : 'white' }}; border: 1px solid {{ $hasAnyAdv ? '#ef4444' : '#cbd5e0' }}; color: {{ $hasAnyAdv ? '#ef4444' : '#64748b' }}; box-shadow: none; border-radius: 12px; cursor: default; transition: all 0.2s;"
                        onclick="toggleAdvancedFilter(event)">
                        <i class="material-icons">filter_list</i>
                    </button>

                    <!-- Panel Flotante de Filtros -->
                    <div id="advancedFilterPanel" style="display: none; position: absolute; top: 100%; right: 0; width: 280px; background: #e2e8f0; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15); border: 1px solid #cbd5e1; z-index: 100; margin-top: 10px; padding: 15px;">
                        <h4 style="margin: 0 0 15px 0; font-size: 14px; font-weight: 700; color: #334155; display: flex; justify-content: space-between; align-items: center;">
                            Filtros Avanzados
                            <span style="font-size: 11px; color: #64748b; font-weight: 400; text-decoration: underline; cursor: default;" onclick="clearDateFilters()">Limpiar Todo</span>
                        </h4>

                        <!-- Rango de Fechas (Estilo unificado) -->
                        <div style="margin-bottom: 15px;">
                            <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Rango de Fechas</span>
                            <div style="display: flex; gap: 8px;">
                                <input type="date" id="filterFechaDesde" class="native-date" 
                                    onchange="loadMovilizaciones()" title="Desde" 
                                    value="{{ request('fecha_desde') }}"
                                    style="width: 100%; height: 36px; border-radius: 6px; border: 1px solid #cbd5e0; background: #fbfcfd; outline: none; padding: 0 12px; font-size:12px; color: #1e293b; cursor: pointer;" 
                                    onclick="try{this.showPicker()}catch(e){}">
                                <input type="date" id="filterFechaHasta" class="native-date" 
                                    onchange="loadMovilizaciones()" title="Hasta" 
                                    value="{{ request('fecha_hasta') }}"
                                    style="width: 100%; height: 36px; border-radius: 6px; border: 1px solid #cbd5e0; background: #fbfcfd; outline: none; padding: 0 12px; font-size:12px; color: #1e293b; cursor: pointer;" 
                                    onclick="try{this.showPicker()}catch(e){}">
                            </div>
                        </div>

                        <!-- Dirección del Frente (Entrada / Salida) -->
                        <div>
                            <span style="display:flex; align-items:center; gap:5px; font-size:12px; font-weight:600; color:#64748b; margin-bottom:6px;">
                                <i class="material-icons" style="font-size:13px;">swap_horiz</i>
                                Dirección del Frente
                            </span>
                            <div style="display: flex; gap: 6px;">
                                <button type="button" id="filterDireccionTodas"
                                    onclick="setDireccionFilter('')"
                                    style="flex:1; height:32px; border-radius:8px; border:1px solid {{ !request('direccion_frente') ? '#0067b1' : '#e2e8f0' }}; background:{{ !request('direccion_frente') ? '#e1effa' : 'white' }}; color:{{ !request('direccion_frente') ? '#0067b1' : '#64748b' }}; font-size:11px; font-weight:600; cursor:default; transition:all 0.2s;">
                                    Todas
                                </button>
                                <button type="button" id="filterDireccionEntrada"
                                    onclick="setDireccionFilter('entrada')"
                                    style="flex:1; height:32px; border-radius:8px; border:1px solid {{ request('direccion_frente') == 'entrada' ? '#16a34a' : '#e2e8f0' }}; background:{{ request('direccion_frente') == 'entrada' ? '#dcfce7' : 'white' }}; color:{{ request('direccion_frente') == 'entrada' ? '#16a34a' : '#64748b' }}; font-size:11px; font-weight:600; cursor:default; transition:all 0.2s;">
                                    <i class="material-icons" style="font-size:13px; vertical-align:middle;">arrow_downward</i>
                                    Entrada
                                </button>
                                <button type="button" id="filterDireccionSalida"
                                    onclick="setDireccionFilter('salida')"
                                    style="flex:1; height:32px; border-radius:8px; border:1px solid {{ request('direccion_frente') == 'salida' ? '#dc2626' : '#e2e8f0' }}; background:{{ request('direccion_frente') == 'salida' ? '#fee2e2' : 'white' }}; color:{{ request('direccion_frente') == 'salida' ? '#dc2626' : '#64748b' }}; font-size:11px; font-weight:600; cursor:default; transition:all 0.2s;">
                                    <i class="material-icons" style="font-size:13px; vertical-align:middle;">arrow_upward</i>
                                    Salida
                                </button>
                            </div>
                            <input type="hidden" id="filterDireccionFrente" value="{{ request('direccion_frente') }}">
                        </div>
                    </div>
                </div>

            </div>{{-- /mv-search-adv-row --}}

            <!-- Botón Acciones -->
            <div class="filter-item aligned-filter mv-acciones-btn-container" style="position: relative; width: auto; flex: 0 0 auto; margin-left: auto;">
                
                <!-- Main Trigger Button -->
                <button type="button" id="btnAccionesMov" class="btn-primary-maquinaria" style="padding: 0 15px; height: 45px; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);" onclick="window.toggleAccionesMov(event);">
                    <i class="material-icons" style="font-size: 18px;">settings</i>
                    <span>Acciones</span>
                    <i class="material-icons" style="font-size: 18px; margin-left: 2px;">expand_more</i>
                </button>

                <!-- Dropdown Menu -->
                <div id="splitDropdownMenuMov" style="display: none; position: absolute; top: calc(100% + 5px); right: 0; min-width: 240px; background: #e2e8f0; border: 1px solid #cbd5e1; border-radius: 10px; box-shadow: 0 10px 20px -5px rgba(15, 23, 42, 0.18); z-index: 50; overflow: hidden; animation: slideDown 0.2s ease-out;">
                    {{-- Reimprimir Acta: disponible para cualquier usuario autenticado --}}
                    <div style="padding: 6px;">
                        <button type="button"
                            onclick="document.getElementById('splitDropdownMenuMov').style.display='none'; window.openReimprimirActaModal();"
                            style="width: 100%; display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 6px; border: none; background: transparent; color: #475569; font-size: 13px; font-weight: 700; cursor: pointer; text-align: left; transition: background 0.15s;"
                            onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='transparent'">
                            <div style="background:#e0f2fe;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;line-height:1;color:#0284c7;">print</i></div>
                            <span>Reimprimir Acta por Código</span>
                        </button>
                    </div>

                    @can('super.admin')
                    <div style="padding: 6px; border-top: 1px solid #cbd5e1;">
                        <button type="button"
                            onclick="document.getElementById('splitDropdownMenuMov').style.display='none'; window._eliminarSeleccionados();"
                            style="width: 100%; display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 6px; border: none; background: transparent; color: #475569; font-size: 13px; font-weight: 700; cursor: pointer; text-align: left; transition: background 0.15s;"
                            onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='transparent'">
                            <div style="background:#fee2e2;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;line-height:1;color:#dc2626;">delete</i></div>
                            <span>Eliminar seleccionados</span>
                        </button>
                    </div>

                    @endcan
                </div>
            </div>

        </div>


        <!-- Table Container -->
        <div class="custom-scrollbar-container movilizaciones-table-wrap" style="margin-top: 5px;">
            <table class="admin-table" id="movilizacionesTable">
                <thead>
                    <tr class="table-row-header">
                        <th class="table-header-custom">Equipo</th>
                        <th class="table-header-custom" style="text-align: center !important;">Trayecto (Origen → Destino)</th>
                        <th class="table-header-custom mv-mobile-hidden" style="text-align: center !important;">Fechas</th>
                        <th class="table-header-custom mv-col-op mv-mobile-hidden" style="text-align: center !important;">N° OPERACIÓN</th>
                        <th class="table-header-custom" style="text-align: center !important;">Estado</th>
                    </tr>
                </thead>
                <tbody id="movilizacionesTableBody">
                    @include('admin.movilizaciones.partials.table_rows')
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <div id="movilizacionesPagination" style="margin-top: 5px; overflow-x: auto; max-width: 100%;">
            {{ $movilizaciones->links('vendor.pagination.custom-sliding') }}
        </div>

    </div>

    <!-- Right Sidebar -->
    <div class="counter-sidebar movilizaciones-sidebar" style="position: sticky; top: 20px; display: flex; flex-direction: column; gap: 20px;">
        
        <!-- Total Card -->
        <div style="background: linear-gradient(135deg, #4c1d95 0%, #6d28d9 100%); border-radius: 12px; padding: 15px; color: white; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); position: relative; overflow: hidden;">
            <i class="material-icons" style="position: absolute; right: -15px; bottom: -15px; font-size: 80px; opacity: 0.1; transform: rotate(-15deg);">history</i>
            <div style="position: relative; z-index: 2;">
                <div style="font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1.5px; opacity: 0.9; margin-bottom: 5px;">Total Operaciones</div>
                <div style="display: flex; align-items: baseline; gap: 5px;">
                    <span id="totalTransitoCount" style="font-size: 32px; font-weight: 800; line-height: 1; letter-spacing: -1px;">
                        {{ $totalTransito }}
                    </span>
                    <span style="font-size: 12px; opacity: 0.8; font-weight: 500;">registros</span>
                </div>
            </div>
        </div>

    </div>


</div>

<!-- Image Overlay Modal -->
<div id="imageOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 9999; justify-content: center; align-items: center; cursor: default;" onclick="this.style.display='none'">
    <img id="enlargedImg" style="max-width: 90%; max-height: 90%; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); transition: transform 0.3s ease;">
</div>

{{-- ─── CONTADOR FLOTANTE DE SELECCIÓN ───────────────────────────────────── --}}
<div id="mv-selection-chip" class="selection-floating-bar">
    <div class="selection-counter">
        <div style="background: rgba(255,255,255,0.1); padding: 5px; border-radius: 50%; display: flex;">
            <i class="material-icons" style="font-size: 18px; color: white;">functions</i>
        </div>
        <span id="mv-selection-count">0</span>
    </div>
    <div style="width: 1px; height: 24px; background: rgba(255,255,255,0.2);"></div>
    <div style="display: flex; gap: 10px; align-items: center;">
        <button type="button" onclick="window.mvClearSelection()" style="background: transparent; border: none; color: #94a3b8; font-size: 13px; font-weight: 600; cursor: pointer;" onmouseover="this.style.color='white'" onmouseout="this.style.color='#94a3b8'">
            Limpiar
        </button>
        @can('super.admin')
        <button type="button" id="btnEliminarSeleccionados"
            onclick="window._eliminarSeleccionados()"
            style="background: #ef4444; border: none; color: white; font-size: 13px; font-weight: 700; padding: 6px 14px; border-radius: 8px; display: flex; align-items: center; gap: 5px; cursor: pointer; transition: background 0.2s;"
            onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">
            <i class="material-icons" style="font-size: 16px;">delete</i>
            Eliminar
        </button>
        @endcan
    </div>
</div>

<style>
    /* Hover en filas seleccionables */
    #movilizacionesTable .mv-selectable-row:not(.selected-row-maquinaria):hover td {
        background: #f8fafc !important;
        transition: background 0.15s;
    }

    /* Corrección para que la selección mantenga el borde redondeado de los TDs */
    #movilizacionesTable tr.selected-row-maquinaria {
        background-color: transparent !important;
        border-left: none !important;
    }
    #movilizacionesTable tr.selected-row-maquinaria td {
        background-color: #e1effa !important;
        color: #0067b1 !important;
        border-top-color: #93c5fd !important;
        border-bottom-color: #93c5fd !important;
        transition: all 0.2s ease;
    }
    #movilizacionesTable tr.selected-row-maquinaria td:first-child {
        border-left: 4px solid #0067b1 !important;
        border-top-color: #93c5fd !important;
        border-bottom-color: #93c5fd !important;
    }
    #movilizacionesTable tr.selected-row-maquinaria td:last-child {
        border-right-color: #93c5fd !important;
    }
</style>

@can('super.admin')
<script>
// ── Eliminar Seleccionados (Batch Delete) ──
// Se define directamente en la vista para evadir caché del .js externo
window._eliminarSeleccionados = function () {
    const ids = Array.from(window._mvSelectedIds || []);
    if (!ids.length) {
        if (window.showModal) window.showModal({ type: 'warning', title: 'Sin selección', message: 'Selecciona al menos un registro resaltado en azul antes de eliminar.', hideCancel: true });
        else alert('Selecciona al menos un registro.');
        return;
    }

    const msg = ids.length === 1
        ? 'Se eliminará 1 registro de movilización.'
        : 'Se eliminarán ' + ids.length + ' registros de movilización.';

        const doDelete = function () {
            const csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content;
            
            fetch('/admin/movilizaciones/bulk-delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ ids: ids })
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    window._mvSelectedIds.clear();
                    if (window.showToast) window.showToast(res.message || 'Registros eliminados correctamente.', 'success');
                    else alert(res.message || 'Todos los registros fueron eliminados correctamente.');
                    
                    if (window.loadMovilizaciones) window.loadMovilizaciones();
                    else window.location.reload();
                } else {
                    if (window.hidePreloader) window.hidePreloader();
                    alert('Error: ' + (res.message || 'Hubo un problema al eliminar los registros.'));
                }
            })
            .catch(function (err) {
                if (window.hidePreloader) window.hidePreloader();
                console.error('[Movilizaciones] Error batch delete:', err);
                alert('Error de red al intentar eliminar los registros.');
            });
        };

    // Usar el sistema de modales global (standardModal)
    if (window.showModal) {
        window.showModal({
            type: 'danger',
            title: '¿Eliminar registros?',
            message: `${msg}<br><strong>¿Continuar? Esta acción no se puede deshacer.</strong>`,
            confirmText: 'Eliminar',
            cancelText: 'Cancelar',
            onConfirm: function () {
                const preloader = document.getElementById('preloader');
                if (preloader) preloader.style.display = 'flex';
                doDelete();
            }
        });
    } else {
        if (confirm(`${msg}\n\n¿Estás seguro? Esta acción no se puede deshacer.`)) {
            const preloader = document.getElementById('preloader');
            if (preloader) preloader.style.display = 'flex';
            doDelete();
        }
    }
};
</script>
@endcan

{{-- ═════════════════════════════════════════════════════════════════
     MODAL: REIMPRIMIR ACTA POR CODIGO
     Ingresa el N° de Operación (CODIGO_CONTROL) y descarga el PDF.
═════════════════════════════════════════════════════════════════ --}}
<div id="reimprimirActaOverlay"
     style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); backdrop-filter: blur(3px); z-index:10000; align-items:center; justify-content:center; padding:20px;"
     onclick="if(event.target===this) window.closeReimprimirActaModal()">
    <div style="background:white; width:100%; max-width:440px; border-radius:16px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35); overflow:hidden; animation:reimprimirIn 0.22s cubic-bezier(0.16,1,0.3,1);">
        <!-- Header (misma paleta que el modal de Anclaje/Ubicacion: #1e293b solido) -->
        <div style="background:#1e293b; padding:10px 16px; color:white; position:relative;">
            <div style="display:flex; flex-direction:column; align-items:center; gap:2px; text-align:center;">
                <div style="display:flex; align-items:center; gap:6px;">
                    <i class="material-icons" style="font-size:20px;">print</i>
                    <h2 style="margin:0; font-size:15px; font-weight:800;">Reimprimir Acta de Traslado</h2>
                </div>
                <p style="margin:0; font-size:12px; opacity:0.85;">Busca por N° de Operación del informe</p>
            </div>
            <button type="button" onclick="window.closeReimprimirActaModal()" aria-label="Cerrar"
                style="background:rgba(255,255,255,0.15); border:none; color:white; width:28px; height:28px; border-radius:8px; display:flex; align-items:center; justify-content:center; cursor:pointer; position:absolute; top:10px; right:12px;">
                <i class="material-icons" style="font-size:16px;">close</i>
            </button>
        </div>
        <!-- Body -->
        <div style="padding:16px 20px; display:flex; flex-direction:column; gap:12px;">
            <div>
                <label for="reimprimirCodigoInput" style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">
                    <i class="material-icons" style="font-size:14px; vertical-align:middle; margin-right:2px; color:#1e293b;">tag</i>
                    N° de Operación
                </label>
                <div id="reimprimirInputBox"
                     style="display:flex; align-items:center; border:2px solid #e2e8f0; border-radius:8px; background:white; overflow:hidden; transition:border-color 0.2s, box-shadow 0.2s;">
                    <i class="material-icons" style="padding:0 8px; color:#94a3b8; font-size:18px; flex-shrink:0;">search</i>
                    <input type="text" id="reimprimirCodigoInput"
                        placeholder="Ej: 000125"
                        autocomplete="off"
                        style="flex:1; border:none; outline:none; padding:8px 6px; font-size:13px; background:transparent; letter-spacing:0.5px;"
                        onkeydown="if(event.key==='Enter'){event.preventDefault(); window.submitReimprimirActa();}">
                </div>
            </div>

            <div id="reimprimirFeedback" style="display:none; padding:8px 10px; border-radius:8px; font-size:12px; font-weight:600;"></div>

            <div style="display:flex; gap:10px; justify-content:center; margin-top:2px;">
                <button type="button" onclick="window.closeReimprimirActaModal()"
                    style="padding:8px 16px; border-radius:8px; border:1px solid #e2e8f0; background:white; color:#475569; font-size:13px; font-weight:700; cursor:pointer;">
                    Cancelar
                </button>
                <button type="button" id="reimprimirSubmitBtn" onclick="window.submitReimprimirActa()"
                    style="padding:8px 16px; border-radius:8px; border:none; background:#1e293b; color:white; font-size:13px; font-weight:800; cursor:pointer; display:flex; align-items:center; gap:6px;">
                    <i class="material-icons" style="font-size:16px;">file_download</i> Generar
                </button>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes reimprimirIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
#reimprimirInputBox:focus-within { border-color:#1e293b; box-shadow:0 0 0 3px rgba(30,41,59,0.15); }
</style>

<script>
(function(){
    if (window._reimprimirActaReady) return;
    window._reimprimirActaReady = true;

    const overlay = () => document.getElementById('reimprimirActaOverlay');
    const input   = () => document.getElementById('reimprimirCodigoInput');
    const fb      = () => document.getElementById('reimprimirFeedback');
    const btn     = () => document.getElementById('reimprimirSubmitBtn');
    const box     = () => document.getElementById('reimprimirInputBox');

    function showFb(type, msg) {
        const el = fb(); if (!el) return;
        const colors = {
            info:    { bg:'#e0f2fe', border:'#bae6fd', color:'#075985' },
            error:   { bg:'#fee2e2', border:'#fecaca', color:'#b91c1c' },
            success: { bg:'#dcfce7', border:'#bbf7d0', color:'#15803d' },
        };
        const c = colors[type] || colors.info;
        el.style.cssText = 'display:block; padding:10px 12px; border-radius:8px; font-size:12.5px; font-weight:600; background:' + c.bg + '; border:1px solid ' + c.border + '; color:' + c.color + ';';
        el.textContent = msg;
    }

    window.openReimprimirActaModal = function () {
        const ov = overlay(); if (!ov) return;
        ov.style.display = 'flex';
        const i = input(); if (i) { i.value = ''; setTimeout(() => i.focus(), 80); }
        const el = fb(); if (el) el.style.display = 'none';
        const b = box(); if (b) b.style.borderColor = '#e2e8f0';
        document.body.style.overflow = 'hidden';
    };

    window.closeReimprimirActaModal = function () {
        const ov = overlay(); if (ov) ov.style.display = 'none';
        document.body.style.overflow = '';
    };

    window.submitReimprimirActa = async function () {
        const raw = (input() && input().value || '').trim();
        if (!raw) {
            const b = box(); if (b) b.style.borderColor = '#ef4444';
            showFb('error', 'Ingresa un N° de Operación para continuar.');
            if (input()) input().focus();
            return;
        }
        const submitBtn = btn();
        const originalHtml = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="material-icons" style="font-size:17px;animation:spin 1s linear infinite;">sync</i> Buscando...'; }
        showFb('info', 'Buscando el acta con N° ' + raw + '…');
        try {
            const res = await fetch('/admin/movilizaciones/find-by-codigo?codigo=' + encodeURIComponent(raw), {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '' },
            });
            if (res.status === 404) {
                showFb('error', 'No se encontró ninguna movilización con ese N° de Operación.');
                const b = box(); if (b) b.style.borderColor = '#ef4444';
                return;
            }
            if (!res.ok) {
                const errData = await res.json().catch(() => ({}));
                showFb('error', errData.message || ('Error del servidor (' + res.status + ').'));
                return;
            }
            const data = await res.json();
            if (!data.success || !data.id) {
                showFb('error', data.message || 'Respuesta inválida del servidor.');
                return;
            }
            showFb('success', 'Acta encontrada. Descargando PDF…');
            // Disparar descarga via link oculto (deja la SPA tranquila).
            const a = document.createElement('a');
            a.href = '/admin/movilizaciones/' + data.id + '/acta-traslado';
            a.setAttribute('data-no-spa', 'true');
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            setTimeout(() => { document.body.removeChild(a); window.closeReimprimirActaModal(); }, 500);
        } catch (err) {
            console.error('[Reimprimir Acta]', err);
            showFb('error', 'No se pudo contactar al servidor. Intenta de nuevo.');
        } finally {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = originalHtml; }
        }
    };

    // Escape cierra el modal
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay() && overlay().style.display === 'flex') {
            window.closeReimprimirActaModal();
        }
    });
})();
</script>

@endsection
