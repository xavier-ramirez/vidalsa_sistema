@extends('layouts.estructura_base')

@section('title', 'Movilizaciones de Equipos y Maquinarias')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color: #000; white-space: normal; word-break: break-word;">Bitácora de Movilizaciones</span>
    </h1>
</div>


<div class="movilizaciones-layout" style="align-items: start; width: 100%;">
    
    <!-- Left Column: Table & Filters -->
    <div class="admin-card movilizaciones-main-card" style="margin: 0; width: 100%;">

        <!-- Filter Toolbar -->
        <div class="movilizaciones-filter-bar filter-toolbar-container" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center; margin-bottom: 5px;">
        @php
            $authUser       = auth()->user();
            $isLocalUser    = $authUser && !$authUser->veTodosLosFrentesEquipos();
            $dashFrenteIds  = $authUser ? $authUser->getFrentesIds() : [];
        @endphp

        {{-- =====================================================================
             FILTRO FRENTE: Restringido a frentes permitidos
             ===================================================================== --}}
        <div class="mv-filter-item mv-frente-item">
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
            <div class="mv-filter-item mv-tipo-item">
                <div class="custom-dropdown" id="tipoFilterSelect" data-filter-type="id_tipo" data-default-label="Filtrar Tipo...">
                    <input type="hidden" name="id_tipo" data-filter-value value="{{ request('id_tipo') }}" form="search-form">
                    
                    @php
                        $reqTipoMov = (string) request('id_tipo', '');
                        $tipoMovLabel = '';
                        if (str_starts_with($reqTipoMov, 'tipo_eq:')) {
                            $f = $allTipos->firstWhere('id', (int) substr($reqTipoMov, 8));
                            $tipoMovLabel = $f ? $f->nombre : '';
                        } elseif (str_starts_with($reqTipoMov, 'tipo_aux:')) {
                            $tipoMovLabel = substr($reqTipoMov, 9);
                        } elseif ($reqTipoMov && $reqTipoMov !== 'all') {
                            $f = $allTipos->firstWhere('id', $reqTipoMov);
                            $tipoMovLabel = $f ? $f->nombre : '';
                        }
                    @endphp

                    <div class="dropdown-trigger {{ $tipoMovLabel ? 'filter-active' : '' }}" style="padding: 0; display: flex; align-items: center; background: #fbfcfd; overflow: hidden; border: 1px solid #cbd5e0; border-radius: 12px; height: 45px;">
                        <div style="padding: 0 10px; display: flex; align-items: center; color: var(--maquinaria-gray-text);">
                            <i class="material-icons" style="font-size: 18px;">search</i>
                        </div>
                        <input type="text" name="filter_search_dropdown" data-filter-search
                            placeholder="{{ $tipoMovLabel ?: 'Filtrar Tipo...' }}"
                            aria-label="Filtrar Tipo"
                            style="flex: 1; border: none; background: transparent; padding: 10px 5px; font-size: 14px; outline: none; min-width: 0;"
                            oninput="window.filterDropdownOptions(this)"
                            autocomplete="off">
                        <i class="material-icons" data-clear-btn style="padding: 0 5px; color: var(--maquinaria-gray-text); font-size: 18px; display: {{ $tipoMovLabel ? 'block' : 'none' }}; cursor:pointer;" onclick="event.stopPropagation(); clearDropdownFilter('tipoFilterSelect');">close</i>
                    </div>

                    <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible;">
                        <div class="dropdown-item-list" style="max-height: 300px; overflow-y: auto;">
                            <div class="dropdown-item {{ !$tipoMovLabel ? 'selected' : '' }}" data-value="all" onclick="selectOption('tipoFilterSelect', 'all', 'TODOS LOS TIPOS');">
                                TODOS LOS TIPOS
                            </div>
                            <div style="padding:4px 8px 2px; font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; border-top:1px solid #e2e8f0; margin-top:4px;">VEHÍCULOS</div>
                            @foreach($allTipos as $tipo)
                                <div class="dropdown-item {{ $reqTipoMov === 'tipo_eq:'.$tipo->id ? 'selected' : '' }}" data-value="tipo_eq:{{ $tipo->id }}" onclick="selectOption('tipoFilterSelect', 'tipo_eq:{{ $tipo->id }}', '{{ addslashes($tipo->nombre) }}');">
                                    {{ $tipo->nombre }}
                                </div>
                            @endforeach
                            @if(isset($tiposAux) && $tiposAux->count())
                            <div style="padding:4px 8px 2px; font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; border-top:1px solid #e2e8f0; margin-top:4px;">AUXILIARES</div>
                            @foreach($tiposAux as $ta)
                                <div class="dropdown-item {{ $reqTipoMov === 'tipo_aux:'.$ta ? 'selected' : '' }}" data-value="tipo_aux:{{ $ta }}" onclick="selectOption('tipoFilterSelect', 'tipo_aux:{{ addslashes($ta) }}', '{{ addslashes($ta) }}');">
                                    {{ $ta }}
                                </div>
                            @endforeach
                            @endif
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

            {{-- Botón Acciones — solo super.admin: su único ítem (Eliminar seleccionados)
                 lo es. "Reimprimir Acta" se eliminó (pedido del cliente); sin este @can,
                 el resto de usuarios vería un menú vacío. --}}
            @can('super.admin')
            <div class="filter-item aligned-filter mv-acciones-btn-container" style="position: relative; width: auto; flex: 0 0 auto; margin-left: auto;">

                <!-- Main Trigger Button -->
                <button type="button" id="btnAccionesMov" class="btn-primary-maquinaria" style="padding: 0 15px; height: 45px; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);" onclick="window.toggleAccionesMov(event);">
                    <i class="material-icons" style="font-size: 18px;">settings</i>
                    <span>Acciones</span>
                    <i class="material-icons" style="font-size: 18px; margin-left: 2px;">expand_more</i>
                </button>

                <!-- Dropdown Menu -->
                <div id="splitDropdownMenuMov" style="display: none; position: absolute; top: calc(100% + 5px); right: 0; min-width: 240px; background: #e2e8f0; border: 1px solid #cbd5e1; border-radius: 10px; box-shadow: 0 10px 20px -5px rgba(15, 23, 42, 0.18); z-index: 50; overflow: hidden; animation: slideDown 0.2s ease-out;">
                    <div style="padding: 6px;">
                        <button type="button"
                            onclick="document.getElementById('splitDropdownMenuMov').style.display='none'; window._eliminarSeleccionados();"
                            style="width: 100%; display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 6px; border: none; background: transparent; color: #475569; font-size: 13px; font-weight: 700; cursor: pointer; text-align: left; transition: background 0.15s;"
                            onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='transparent'">
                            <div style="background:#fee2e2;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;line-height:1;color:#dc2626;">delete</i></div>
                            <span>Eliminar seleccionados</span>
                        </button>
                    </div>
                </div>
            </div>
            @endcan

        </div>


        <!-- Table Container -->
        <div class="custom-scrollbar-container movilizaciones-table-wrap" style="margin-top: 5px;">
            <table class="admin-table" id="movilizacionesTable">
                <thead>
                    <tr class="table-row-header">
                        <th class="table-header-custom">Equipo</th>
                        <th class="table-header-custom" style="text-align: center !important;">Trayecto (Origen → Destino)</th>
                        <th class="table-header-custom" style="text-align: center !important;">Fechas</th>
                        <th class="table-header-custom mv-col-op" style="text-align: center !important;">N° OPERACIÓN</th>
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
        /* Sin franja azul gruesa a la izquierda: solo el mismo borde fino celeste. */
        border-left-color: #93c5fd !important;
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

// ── Deshacer una movilización (por fila) ──
// Devuelve el equipo a su frente de ORIGEN y borra el registro (como si nunca ocurrió).
window.movDeshacer = function (id) {
    if (!id) return;
    var msg = 'El equipo volverá a su frente de ORIGEN y este registro se borrará por completo (como si nunca hubiera ocurrido).';
    var doIt = function () {
        var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content;
        var preloader = document.getElementById('preloader'); if (preloader) preloader.style.display = 'flex';
        fetch('/admin/movilizaciones/' + id + '/deshacer', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            if (res.ok && res.b.success) {
                if (window.showToast) window.showToast(res.b.message || 'Movilización deshecha.', 'success');
                if (window.loadMovilizaciones) window.loadMovilizaciones(); else window.location.reload();
            } else {
                if (preloader) preloader.style.display = 'none';
                if (window.showModal) window.showModal({ type: 'warning', title: 'No se pudo deshacer', message: (res.b && res.b.message) || 'Error al deshacer.', hideCancel: true });
                else alert((res.b && res.b.message) || 'Error al deshacer.');
            }
        })
        .catch(function () {
            if (preloader) preloader.style.display = 'none';
            alert('Error de red al deshacer la movilización.');
        });
    };
    if (window.showModal) {
        window.showModal({ type: 'danger', title: '¿Deshacer movilización?', message: msg + '<br><strong>¿Continuar?</strong>', confirmText: 'Deshacer', cancelText: 'Cancelar', onConfirm: doIt });
    } else if (confirm(msg + '\n\n¿Continuar?')) { doIt(); }
};
</script>
@endcan

{{-- El modal "Reimprimir Acta" se eliminó junto con su botón del menú Acciones
     (pedido del cliente). El acta de una movilización se sigue abriendo desde
     la fila de la tabla (icono PDF). --}}

@endsection
