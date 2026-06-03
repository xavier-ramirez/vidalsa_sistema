@extends('layouts.estructura_base')

@section('title', 'Gestión de Equipos')

@section('content')

<style>
    /* ── Panel de Filtros Avanzados en MOBILE: ancho comodo para ver estatus completo ── */
    @media (max-width: 768px) {
        #advancedFilterPanel {
            /* En vez de 300px fijo alineado al borde derecho, ocupar casi todo el viewport */
            width: calc(100vw - 20px) !important;
            max-width: calc(100vw - 20px) !important;
            right: 10px !important;
            left: auto !important;
            /* Evita que el padding lo empuje fuera de la pantalla */
            box-sizing: border-box !important;
        }
        /* Dropdowns internos (estado, GPS, etc.) tambien ocupan el ancho completo del panel */
        #advancedFilterPanel .custom-dropdown,
        #advancedFilterPanel .dropdown-trigger {
            width: 100% !important;
            box-sizing: border-box !important;
        }
        /* Items de la lista desplegable: un poco mas altos para facilitar tap */
        #advancedFilterPanel .dropdown-item {
            padding: 10px 12px !important;
            font-size: 13px !important;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        /* El centrado del layout en mobile (.page-layout-grid, la tarjeta
           blanca y el wrapper del titulo .main-viewport > div:has(.page-title))
           vive ahora en estilos_globales.css (@media max-width:768px) — aplica
           por igual a equipos y a los modulos de almacen, sin duplicar aqui. */
    }

    /* Ajustes para laptops pequeñas (resolución 1366x768 o menor) para que entren todas las columnas.
       Limitado a >768px para que NO se aplique en mobile (donde la tabla se transforma en cards verticales). */
    @media (min-width: 769px) and (max-width: 1400px) {
        .table-equipos-mobile td,
        .table-equipos-mobile th,
        .table-equipos-mobile td div,
        .table-equipos-mobile td span {
            font-size: 11.5px !important;
            letter-spacing: -0.2px;
        }
        .table-equipos-mobile td strong {
            font-size: 12px !important;
        }
        .table-equipos-mobile .material-icons {
            font-size: 16px !important;
        }
        .table-equipos-mobile {
            min-width: 900px !important; /* Reducir el min-width para evitar overflow */
        }
        
        /* Ajustes para el panel lateral de contadores (Consolidado y Distribución) */
        .counter-sidebar [style*="font-size: 13px"] { font-size: 11px !important; }
        .counter-sidebar [style*="font-size: 36px"] { font-size: 26px !important; }
        .counter-sidebar [style*="font-size: 18px"] { font-size: 15px !important; }
        .counter-sidebar [style*="font-size: 16px"] { font-size: 14px !important; }
        .counter-sidebar [style*="font-size: 8px"] { font-size: 7.5px !important; letter-spacing: -0.3px !important; }
        .counter-sidebar h4 { font-size: 11px !important; margin-bottom: 8px !important; }
        .counter-sidebar h4 .material-icons { font-size: 15px !important; }
        .counter-sidebar li span { font-size: 9.5px !important; }
        .counter-sidebar { gap: 10px !important; }
    }

    /* "Ver solo seleccionados" activo: en vez del anillo/glow que rodeaba TODO
       el contador (se veía feo), resaltamos solo el NÚMERO en un círculo ámbar
       limpio. El contador en sí queda sin borde raro. */
    #bulkFloatingBar .selection-counter.is-filtering #bulkCountText {
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
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h1 class="page-title">
            <span class="page-title-line2" style="color: #000;">Gestión de Equipos y Maquinaria</span>
        </h1>

    </div>

<div class="page-layout-grid">
    
    <!-- Left Column: Table & Filters -->
    <div class="admin-card" data-page="equipos" style="margin: 0; min-height: 80vh; min-width: 0; width: 100%;">
    @php
        $authUser        = auth()->user();
        $isLocalUser     = $authUser && $authUser->NIVEL_ACCESO == 2;
        $dashFrenteIds   = $authUser ? $authUser->getFrentesIds() : [];
        $hasMultiple     = count($dashFrenteIds) > 1;
        $userFrenteObj   = count($dashFrenteIds) === 1 ? $frentes->firstWhere('ID_FRENTE', $dashFrenteIds[0]) : null;
    @endphp

    <div class="filter-toolbar-container" style="margin-bottom: 5px;">

        {{-- =====================================================================
             FILTRO FRENTE: LOCAL = bloqueado | GLOBAL = dropdown con default real
             ===================================================================== --}}
        <div class="filter-item aligned-filter">
            @php
                $currentFrenteId = request('id_frente');
                $currentFrente   = $currentFrenteId ? $frentes->firstWhere('ID_FRENTE', $currentFrenteId) : null;
                $frentesDropdown = $isLocalUser ? $frentes->whereIn('ID_FRENTE', $dashFrenteIds) : $frentes;
                $placeholderText = $currentFrente ? $currentFrente->NOMBRE_FRENTE : ($isLocalUser ? 'Todos Mis Frentes' : 'Filtrar Frente...');
            @endphp
            <div class="custom-dropdown" id="frenteFilterSelect" data-filter-type="id_frente" data-default-label="{{ $isLocalUser ? 'Todos Mis Frentes' : 'Filtrar Frente...' }}">
                <input type="hidden" name="id_frente" data-filter-value value="{{ $currentFrenteId }}" form="search-form">

                <div class="dropdown-trigger {{ $currentFrenteId && $currentFrenteId != 'all' ? 'filter-active' : '' }}" style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:12px; height:45px;">
                    <div style="padding:0 10px; display:flex; align-items:center; color:var(--maquinaria-gray-text);">
                        <i class="material-icons" style="font-size:18px;">search</i>
                    </div>
                    <input type="text" name="filter_search_dropdown" data-filter-search
                        placeholder="{{ $placeholderText }}"
                        aria-label="Filtrar Frente"
                        style="width:100%; border:none; background:transparent; padding:10px 5px; font-size:14px; outline:none;"
                        oninput="window.filterDropdownOptions(this)"
                        autocomplete="off">
                    <i class="material-icons" data-clear-btn
                       style="padding:0 5px; color:var(--maquinaria-gray-text); font-size:18px; display:{{ $currentFrenteId && $currentFrenteId != 'all' ? 'block' : 'none' }};"
                       onclick="event.stopPropagation(); clearDropdownFilter('frenteFilterSelect'); window.clearAdvancedFilters();">close</i>
                </div>

                <div class="dropdown-content" style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                    <div class="dropdown-item-list" style="max-height:250px; overflow-y:auto;">
                        <div class="dropdown-item {{ !$currentFrenteId || $currentFrenteId == 'all' ? 'selected' : '' }}"
                             data-value="all"
                             onclick="selectOption('frenteFilterSelect', 'all', '{{ $isLocalUser ? 'Todos Mis Frentes' : 'TODOS LOS FRENTES' }}'); loadEquipos();">
                            {{ $isLocalUser ? 'TODOS MIS FRENTES' : 'TODOS LOS FRENTES' }}
                        </div>
                        {{-- Sentinel "none": filtra equipos sin ID_FRENTE_ACTUAL en BD --}}
                        @if(!$isLocalUser)
                        <div class="dropdown-item {{ $currentFrenteId == 'none' ? 'selected' : '' }}"
                             data-value="none"
                             onclick="selectOption('frenteFilterSelect', 'none', 'SIN ASIGNAR'); loadEquipos();"
                             style="font-style: italic; color: #94a3b8;">
                            SIN ASIGNAR
                        </div>
                        @endif
                        @foreach($frentesDropdown as $frente)
                            <div class="dropdown-item {{ $currentFrenteId == $frente->ID_FRENTE ? 'selected' : '' }}"
                                 data-value="{{ $frente->ID_FRENTE }}"
                                 onclick="selectOption('frenteFilterSelect', '{{ $frente->ID_FRENTE }}', '{{ addslashes(trim($frente->NOMBRE_FRENTE)) }}'); loadEquipos();">
                                {{ $frente->NOMBRE_FRENTE }}
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <!-- Tipo Filter -->
        <div class="filter-item aligned-filter" style="flex: 1.5;">
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
                        style="width: 100%; border: none; background: transparent; padding: 10px 5px; font-size: 14px; outline: none;"
                        oninput="window.filterDropdownOptions(this)"
                        autocomplete="off">
                     <i class="material-icons" data-clear-btn
                       style="padding: 0 5px; color: var(--maquinaria-gray-text); font-size: 18px; display: {{ request('id_tipo') ? 'block' : 'none' }};"
                       onclick="event.preventDefault(); event.stopPropagation(); clearDropdownFilter('tipoFilterSelect'); window.clearAdvancedFilters();">close</i>
                </div>

                <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible; z-index: 1000;">
                    <div class="dropdown-item-list" style="max-height: 250px; overflow-y: auto;">
                        <div class="dropdown-item {{ !request('id_tipo') || request('id_tipo') === 'all' ? 'selected' : '' }}" data-value="all" onclick="selectOption('tipoFilterSelect', 'all', 'TODOS LOS TIPOS'); loadEquipos();">
                            TODOS LOS TIPOS
                        </div>
                        @foreach($allTipos as $tipo)
                            <div class="dropdown-item {{ request('id_tipo') == $tipo->id ? 'selected' : '' }}" data-value="{{ $tipo->id }}" onclick="selectOption('tipoFilterSelect', '{{ $tipo->id }}', '{{ addslashes(trim($tipo->nombre)) }}'); loadEquipos();">
                                {{ $tipo->nombre }}
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <!-- Search Filter / Seriales -->
        <!-- Search Filter / Seriales + Advanced Filter Button -->
        <div class="filter-item aligned-filter" style="display: flex; gap: 10px;">
            <form action="{{ route('equipos.index') }}" method="GET" id="search-form" style="flex: 1; margin: 0;">
                
                <div class="search-wrapper" style="width: 100%; border-color: {{ request('search_query') ? '#0067b1' : '#cbd5e0' }}; background: {{ request('search_query') ? '#e1effa' : '#fff' }};">
                    <i class="material-icons search-icon">search</i>
                    <input type="text" id="searchInput" name="search_query" value="{{ request('search_query') }}" 
                        placeholder="Buscar Seriales..." 
                        aria-label="Buscar Seriales"
                        class="search-input-field"
                        autocomplete="off"
                        onkeyup="if(this.value.length >= 4 || this.value.length == 0) { /* Debounce handled in script */ }">
                     <i id="btn_clear_search" class="material-icons clear-icon" 
                       style="display: {{ request('search_query') ? 'block' : 'none' }};" 
                       onclick="event.preventDefault(); event.stopPropagation(); document.getElementById('searchInput').value=''; this.style.display='none'; window.clearAdvancedFilters();">close</i>
                </div>
            </form>

            <!-- Advanced Filter Trigger -->
            <div style="position: relative; flex-shrink: 0;">
                @php
                    $hasAnyAdv = request('modelo') || request('anio') || request('marca') || request('detalle_ubicacion') || request('categoria') || request('estado') || request('gps') || request('filter_propiedad') || request('filter_poliza') || request('filter_rotc') || request('filter_racda') || request('filter_adicional') || request('filter_adicional_2');
                @endphp
                <button type="button" id="btnAdvancedFilter" class="btn-primary-maquinaria" style="height: 45px; width: 45px; flex-shrink: 0; min-width: 45px; padding: 0; display: flex; align-items: center; justify-content: center; background: {{ $hasAnyAdv ? '#fee2e2' : 'white' }}; border: 1px solid {{ $hasAnyAdv ? '#ef4444' : '#cbd5e0' }}; color: {{ $hasAnyAdv ? '#ef4444' : '#64748b' }}; box-shadow: none;" onclick="const p = document.getElementById('advancedFilterPanel'); const s = document.getElementById('splitDropdownMenu'); if (s) s.style.display='none'; p.style.display = p.style.display === 'block' ? 'none' : 'block'; event.stopPropagation();">
                    <i class="material-icons">filter_list</i>
                </button>
                
                <!-- Dynamic Filter Panel -->
                <div id="advancedFilterPanel" style="display: none; position: absolute; top: 100%; right: 0; width: 360px; max-width: calc(100vw - 20px); background: #e2e8f0; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15); border: 1px solid #cbd5e1; z-index: 100; margin-top: 10px; padding: 15px;">
                    <h4 style="margin: 0 0 15px 0; font-size: 14px; font-weight: 700; color: #334155; display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                        Filtros Avanzados
                        <span style="display: flex; align-items: center; gap: 10px;">
                            {{-- Bulk lookup: abre modal para pegar varias placas/seriales y ver donde estan. --}}
                            <button type="button" id="btnBulkLookup"
                                    title="Búsqueda masiva: pegar varias placas o seriales"
                                    onclick="openBulkLookupModal(); event.stopPropagation();"
                                    style="background: white; border: 1px solid #cbd5e1; color: var(--maquinaria-blue); padding: 3px 9px; border-radius: 5px; font-size: 11.5px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; line-height: 1;">
                                <i class="material-icons" style="font-size: 14px;">playlist_add_check</i>
                                Lote
                            </button>
                            <span style="font-size: 12.5px; color: #64748b; font-weight: 400; text-decoration: underline; cursor: pointer;" onclick="clearAdvancedFilters()">Limpiar Todo</span>
                        </span>
                    </h4>

                    <!-- Modelo + Marca Filter (2 columnas, lado a lado) -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 15px;">
                        <!-- Modelo -->
                        <div>
                            <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Modelo</span>
                            <div class="custom-dropdown" id="modeloAdvFilter" data-filter-type="modelo" data-default-label="Seleccionar Modelo..." style="font-size: 12px;">
                                <input type="hidden" name="modelo" data-filter-value value="{{ request('modelo') }}">

                                <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: {{ request('modelo') ? '#e1effa' : 'white' }}; border: 1px solid #e2e8f0; border-radius: 6px; height: 32px;">
                                    <div style="padding: 0 6px; display: flex; align-items: center; color: #94a3b8;">
                                        <i class="material-icons" style="font-size: 16px;">search</i>
                                    </div>
                                    <input type="text" name="filter_search_dropdown" data-filter-search
                                        placeholder="{{ request('modelo') ?: 'Modelo...' }}"
                                        aria-label="Filtrar Modelo"
                                        style="width: 100%; min-width: 0; border: none; background: transparent; padding: 6px 2px; font-size: 12px; outline: none;"
                                        oninput="window.filterDropdownOptions(this)"
                                        autocomplete="off">
                                    <i class="material-icons" data-clear-btn style="padding: 0 4px; color: #94a3b8; font-size: 16px; display: {{ request('modelo') ? 'block' : 'none' }};"
                                       onclick="event.stopPropagation(); clearDropdownFilter('modeloAdvFilter'); loadEquipos();">close</i>
                                </div>

                                <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible; z-index: 1000;">
                                    <div class="dropdown-item-list" style="max-height: 150px; overflow-y: auto;">
                                        @if(isset($availableModelos))
                                            @foreach($availableModelos as $mod)
                                                @if(trim($mod) !== '')
                                                    <div class="dropdown-item {{ request('modelo') == $mod ? 'selected' : '' }}" data-value="{{ $mod }}" onclick="selectOption('modeloAdvFilter', '{{ addslashes(trim($mod)) }}', '{{ addslashes(trim($mod)) }}'); loadEquipos();">{{ $mod }}</div>
                                                @endif
                                            @endforeach
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Marca -->
                        <div>
                            <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Marca</span>
                            <div class="custom-dropdown" id="marcaAdvFilter" data-filter-type="marca" data-default-label="Seleccionar Marca..." style="font-size: 12px;">
                                <input type="hidden" name="marca" data-filter-value value="{{ request('marca') }}">

                                <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: {{ request('marca') ? '#e1effa' : 'white' }}; border: 1px solid #e2e8f0; border-radius: 6px; height: 32px;">
                                    <div style="padding: 0 6px; display: flex; align-items: center; color: #94a3b8;">
                                        <i class="material-icons" style="font-size: 16px;">search</i>
                                    </div>
                                    <input type="text" name="filter_search_dropdown" data-filter-search
                                        placeholder="{{ request('marca') ?: 'Marca...' }}"
                                        aria-label="Filtrar Marca"
                                        style="width: 100%; min-width: 0; border: none; background: transparent; padding: 6px 2px; font-size: 12px; outline: none;"
                                        oninput="window.filterDropdownOptions(this)"
                                        autocomplete="off">
                                    <i class="material-icons" data-clear-btn style="padding: 0 4px; color: #94a3b8; font-size: 16px; display: {{ request('marca') ? 'block' : 'none' }};"
                                       onclick="event.stopPropagation(); clearDropdownFilter('marcaAdvFilter'); loadEquipos();">close</i>
                                </div>

                                <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible; z-index: 1000;">
                                    <div class="dropdown-item-list" style="max-height: 150px; overflow-y: auto;">
                                        @if(isset($availableMarcas))
                                            @foreach($availableMarcas as $marca)
                                                @if(trim($marca) !== '')
                                                    <div class="dropdown-item {{ request('marca') == $marca ? 'selected' : '' }}" data-value="{{ $marca }}" onclick="selectOption('marcaAdvFilter', '{{ addslashes(trim($marca)) }}', '{{ addslashes(trim($marca)) }}'); loadEquipos();">{{ $marca }}</div>
                                                @endif
                                            @endforeach
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Ubicación Filter — visible solo para frentes TIPO_FRENTE=ESPECIAL -->
                    <div id="ubicacionAdvFilterWrapper" style="margin-bottom: 15px; {{ isset($frenteEspecial) && $frenteEspecial ? '' : 'display: none;' }}">
                        <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Detalle (Patio/Subdivisión)</span>
                        <div class="custom-dropdown" id="ubicacionAdvFilter" data-filter-type="detalle_ubicacion" data-default-label="Seleccionar Detalle..." style="font-size: 12px;">
                            <input type="hidden" name="detalle_ubicacion" data-filter-value value="{{ request('detalle_ubicacion') }}">

                            <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: {{ request('detalle_ubicacion') ? '#e1effa' : 'white' }}; border: 1px solid #e2e8f0; border-radius: 6px; height: 32px;">
                                <div style="padding: 0 8px; display: flex; align-items: center; color: #94a3b8;">
                                    <i class="material-icons" style="font-size: 16px;">place</i>
                                </div>
                                <input type="text" name="filter_search_dropdown" data-filter-search
                                    placeholder="{{ request('detalle_ubicacion') ?: 'Seleccionar Detalle...' }}"
                                    aria-label="Filtrar Detalle"
                                    style="width: 100%; border: none; background: transparent; padding: 6px 5px; font-size: 12px; outline: none;"
                                    oninput="window.filterDropdownOptions(this)"
                                    autocomplete="off">
                                <i class="material-icons" data-clear-btn style="padding: 0 5px; color: #94a3b8; font-size: 16px; display: {{ request('detalle_ubicacion') ? 'block' : 'none' }};"
                                   onclick="event.stopPropagation(); clearDropdownFilter('ubicacionAdvFilter'); loadEquipos();">close</i>
                            </div>

                            <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible; z-index: 1000;">
                                <div class="dropdown-item-list" style="max-height: 150px; overflow-y: auto;">
                                    @if(isset($availableUbicaciones))
                                        @foreach($availableUbicaciones as $ubi)
                                            @if(trim($ubi) !== '')
                                                <div class="dropdown-item {{ request('detalle_ubicacion') == $ubi ? 'selected' : '' }}" data-value="{{ $ubi }}" onclick="selectOption('ubicacionAdvFilter', '{{ addslashes(trim($ubi)) }}', '{{ addslashes(trim($ubi)) }}'); loadEquipos();">{{ $ubi }}</div>
                                            @endif
                                        @endforeach
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Categoría Flota + Estado Operativo (2 columnas, lado a lado igual que Marca/Modelo). --}}
                    <div style="margin-top: 15px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <!-- Categoría Flota -->
                        <div>
                            <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Categoría Flota</span>
                            <div class="custom-dropdown" id="categoriaAdvFilter" data-filter-type="categoria" data-default-label="Seleccionar Categoría..." style="font-size: 12px;">
                                <input type="hidden" name="categoria" data-filter-value value="{{ request('categoria') }}">

                                <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: {{ request('categoria') ? '#e1effa' : 'white' }}; border: 1px solid #e2e8f0; border-radius: 6px; height: 32px;">
                                    <div style="padding: 0 6px; display: flex; align-items: center; color: #94a3b8;">
                                        <i class="material-icons" style="font-size: 16px;">local_shipping</i>
                                    </div>
                                    <input type="text" readonly
                                        id="filter_display_categoria"
                                        name="filter_display_categoria"
                                        placeholder="{{ request('categoria') ?: 'Categoría...' }}"
                                        aria-label="Filtrar Categoría"
                                        style="width: 100%; min-width: 0; border: none; background: transparent; padding: 6px 2px; font-size: 12px; outline: none;"
                                        onclick="this.closest('.custom-dropdown').classList.toggle('active')">
                                    <i class="material-icons" data-clear-btn style="padding: 0 4px; color: #94a3b8; font-size: 16px; display: {{ request('categoria') ? 'block' : 'none' }};"
                                       onclick="event.stopPropagation(); clearDropdownFilter('categoriaAdvFilter'); loadEquipos();">close</i>
                                </div>

                                <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible; z-index: 1000;">
                                    <div class="dropdown-item-list">
                                        <div class="dropdown-item {{ request('categoria') == 'FLOTA LIVIANA' ? 'selected' : '' }}" data-value="FLOTA LIVIANA" onclick="selectOption('categoriaAdvFilter', 'FLOTA LIVIANA', 'FLOTA LIVIANA'); loadEquipos();">FLOTA LIVIANA</div>
                                        <div class="dropdown-item {{ request('categoria') == 'FLOTA PESADA' ? 'selected' : '' }}" data-value="FLOTA PESADA" onclick="selectOption('categoriaAdvFilter', 'FLOTA PESADA', 'FLOTA PESADA'); loadEquipos();">FLOTA PESADA</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Estado Operativo -->
                        <div>
                            <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Estado Operativo</span>
                            <div class="custom-dropdown" id="estadoAdvFilter" data-filter-type="estado" data-default-label="Seleccionar Estado..." style="font-size: 12px;">
                                <input type="hidden" name="estado" data-filter-value value="{{ request('estado') }}">

                                <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: {{ request('estado') ? '#e1effa' : 'white' }}; border: 1px solid #e2e8f0; border-radius: 6px; height: 32px;">
                                    <div style="padding: 0 6px; display: flex; align-items: center; color: #94a3b8;">
                                        <i class="material-icons" style="font-size: 16px;">info</i>
                                    </div>
                                    <input type="text" readonly
                                        id="filter_display_estado"
                                        name="filter_display_estado"
                                        placeholder="{{ request('estado') ?: 'Estado...' }}"
                                        aria-label="Filtrar Estado Operativo"
                                        style="width: 100%; min-width: 0; border: none; background: transparent; padding: 6px 2px; font-size: 12px; outline: none;"
                                        onclick="this.closest('.custom-dropdown').classList.toggle('active')">
                                    <i class="material-icons" data-clear-btn style="padding: 0 4px; color: #94a3b8; font-size: 16px; display: {{ request('estado') ? 'block' : 'none' }};"
                                       onclick="event.stopPropagation(); clearDropdownFilter('estadoAdvFilter'); loadEquipos();">close</i>
                                </div>

                                <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible; z-index: 1000;">
                                    <div class="dropdown-item-list">
                                        <div class="dropdown-item {{ request('estado') == 'OPERATIVO' ? 'selected' : '' }}" data-value="OPERATIVO" onclick="selectOption('estadoAdvFilter', 'OPERATIVO', 'OPERATIVO'); loadEquipos();">OPERATIVO</div>
                                        <div class="dropdown-item {{ request('estado') == 'INOPERATIVO' ? 'selected' : '' }}" data-value="INOPERATIVO" onclick="selectOption('estadoAdvFilter', 'INOPERATIVO', 'INOPERATIVO'); loadEquipos();">INOPERATIVO</div>
                                        <div class="dropdown-item {{ request('estado') == 'EN MANTENIMIENTO' ? 'selected' : '' }}" data-value="EN MANTENIMIENTO" onclick="selectOption('estadoAdvFilter', 'EN MANTENIMIENTO', 'EN MANTENIMIENTO'); loadEquipos();">EN MANTENIMIENTO</div>
                                        <div class="dropdown-item {{ request('estado') == 'DESINCORPORADO' ? 'selected' : '' }}" data-value="DESINCORPORADO" onclick="selectOption('estadoAdvFilter', 'DESINCORPORADO', 'DESINCORPORADO'); loadEquipos();">DESINCORPORADO</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    {{-- Año + GPS Filter (2 columnas): ambos son selectores cortos
                         (4 digitos / SI-NO), no requieren ancho completo del panel. --}}
                    <div style="margin-top: 15px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <!-- Año Filter -->
                        <div>
                            <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Año</span>
                            <div class="custom-dropdown" id="anioAdvFilter" data-filter-type="anio" data-default-label="Seleccionar Año..." style="font-size: 12px;">
                                <input type="hidden" name="anio" data-filter-value value="{{ request('anio') }}">

                                <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: {{ request('anio') ? '#e1effa' : 'white' }}; border: 1px solid #e2e8f0; border-radius: 6px; height: 32px;">
                                    <div style="padding: 0 8px; display: flex; align-items: center; color: #94a3b8;">
                                        <i class="material-icons" style="font-size: 16px;">event</i>
                                    </div>
                                    <input type="text" name="filter_search_dropdown" data-filter-search
                                        placeholder="{{ request('anio') ?: 'Año...' }}"
                                        aria-label="Filtrar Año"
                                        style="width: 100%; min-width: 0; border: none; background: transparent; padding: 6px 5px; font-size: 12px; outline: none;"
                                        oninput="window.filterDropdownOptions(this)"
                                        autocomplete="off">
                                    <i class="material-icons" data-clear-btn style="padding: 0 5px; color: #94a3b8; font-size: 16px; display: {{ request('anio') ? 'block' : 'none' }};"
                                       onclick="event.stopPropagation(); clearDropdownFilter('anioAdvFilter'); loadEquipos();">close</i>
                                </div>

                                <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible; z-index: 1000;">
                                    <div class="dropdown-item-list" style="max-height: 120px; overflow-y: auto;">
                                        @if(isset($availableAnios))
                                            @foreach($availableAnios as $anio)
                                                @if(trim($anio) !== '')
                                                    <div class="dropdown-item {{ request('anio') == $anio ? 'selected' : '' }}" data-value="{{ $anio }}" onclick="selectOption('anioAdvFilter', '{{ addslashes(trim($anio)) }}', '{{ addslashes(trim($anio)) }}'); loadEquipos();">{{ $anio }}</div>
                                                @endif
                                            @endforeach
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- GPS Filter -->
                        <div>
                            <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">GPS</span>
                            <div class="custom-dropdown" id="gpsAdvFilter" data-filter-type="gps" data-default-label="Seleccionar Estatus..." style="font-size: 12px;">
                                <input type="hidden" name="gps" data-filter-value value="{{ request('gps') }}">

                                <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: {{ request('gps') ? '#e1effa' : 'white' }}; border: 1px solid #e2e8f0; border-radius: 6px; height: 32px;">
                                    <div style="padding: 0 8px; display: flex; align-items: center; color: #94a3b8;">
                                        <i class="material-icons" style="font-size: 16px;">gps_fixed</i>
                                    </div>
                                    <input type="text" readonly
                                        id="filter_display_gps"
                                        name="filter_display_gps"
                                        placeholder="{{ request('gps') === 'SI' ? 'Tienen GPS' : (request('gps') === 'NO' ? 'No Tienen GPS' : 'Estatus...') }}"
                                        aria-label="Filtrar Estatus GPS"
                                        style="width: 100%; min-width: 0; border: none; background: transparent; padding: 6px 5px; font-size: 12px; outline: none; cursor: pointer;"
                                        onclick="this.closest('.custom-dropdown').classList.toggle('active')">
                                    <i class="material-icons" data-clear-btn style="padding: 0 5px; color: #94a3b8; font-size: 16px; display: {{ request('gps') ? 'block' : 'none' }};"
                                       onclick="event.stopPropagation(); clearDropdownFilter('gpsAdvFilter'); loadEquipos();">close</i>
                                </div>

                                <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible; z-index: 1000;">
                                    <div class="dropdown-item-list">
                                        <div class="dropdown-item {{ request('gps') == 'SI' ? 'selected' : '' }}" data-value="SI" onclick="selectOption('gpsAdvFilter', 'SI', 'Tienen GPS'); loadEquipos();">Tienen GPS</div>
                                        <div class="dropdown-item {{ request('gps') == 'NO' ? 'selected' : '' }}" data-value="NO" onclick="selectOption('gpsAdvFilter', 'NO', 'No Tienen GPS'); loadEquipos();">No Tienen GPS</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Documentation Filters (New) -->
                    <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #cbd5e1;">
                        <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 8px;">Documentación Cargada</span>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                            <label for="chk_propiedad" style="display: flex; align-items: center; font-size: 13px; color: #334155; cursor: pointer;">
                                <input type="checkbox" id="chk_propiedad" onchange="toggleDocFilter('propiedad')" {{ request('filter_propiedad') == 'true' ? 'checked' : '' }} style="margin-right: 8px; accent-color: var(--maquinaria-blue);">
                                Propiedad
                            </label>

                            <label for="chk_poliza" style="display: flex; align-items: center; font-size: 13px; color: #334155; cursor: pointer;">
                                <input type="checkbox" id="chk_poliza" onchange="toggleDocFilter('poliza')" {{ request('filter_poliza') == 'true' ? 'checked' : '' }} style="margin-right: 8px; accent-color: var(--maquinaria-blue);">
                                Póliza
                            </label>

                            <label for="chk_rotc" style="display: flex; align-items: center; font-size: 13px; color: #334155; cursor: pointer;">
                                <input type="checkbox" id="chk_rotc" onchange="toggleDocFilter('rotc')" {{ request('filter_rotc') == 'true' ? 'checked' : '' }} style="margin-right: 8px; accent-color: var(--maquinaria-blue);">
                                ROTC
                            </label>

                            <label for="chk_racda" style="display: flex; align-items: center; font-size: 13px; color: #334155; cursor: pointer;">
                                <input type="checkbox" id="chk_racda" onchange="toggleDocFilter('racda')" {{ request('filter_racda') == 'true' ? 'checked' : '' }} style="margin-right: 8px; accent-color: var(--maquinaria-blue);">
                                RACDA
                            </label>

                            <label for="chk_adicional" style="display: flex; align-items: center; font-size: 13px; color: #334155; cursor: pointer;">
                                <input type="checkbox" id="chk_adicional" onchange="toggleDocFilter('adicional')" {{ request('filter_adicional') == 'true' ? 'checked' : '' }} style="margin-right: 8px; accent-color: var(--maquinaria-blue);">
                                Certificado
                            </label>

                            <label for="chk_adicional_2" style="display: flex; align-items: center; font-size: 13px; color: #334155; cursor: pointer;">
                                <input type="checkbox" id="chk_adicional_2" onchange="toggleDocFilter('adicional_2')" {{ request('filter_adicional_2') == 'true' ? 'checked' : '' }} style="margin-right: 8px; accent-color: var(--maquinaria-blue);">
                                Compraventa
                            </label>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- New Button -->
        <!-- Dropdown Menu Button (Acciones: Nuevo, Exportar, Movilización) -->
        <div class="filter-item aligned-filter" style="position: relative; width: auto; flex: 0 0 auto; margin-left: auto;">
            
            <!-- Main Trigger Button -->
            <button type="button" id="btnAcciones" onclick="const sm = document.getElementById('splitDropdownMenu'); const p = document.getElementById('advancedFilterPanel'); if (p) p.style.display='none'; sm.style.display = sm.style.display === 'block' ? 'none' : 'block'; event.stopPropagation();" class="btn-primary-maquinaria" style="padding: 0 15px; height: 45px; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                <i class="material-icons">settings</i>
                <span>Acciones</span>
                <i class="material-icons" style="font-size: 18px; margin-left: 2px;">expand_more</i>
            </button>

            <!-- Dropdown Menu -->
            <div id="splitDropdownMenu" style="display: none; position: absolute; top: 100%; right: 0; width: 220px; background: #e2e8f0; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0; z-index: 50; margin-top: 5px; overflow: hidden; animation: slideDown 0.2s ease-out;">
                
                <!-- Dashboard de Flota -->
                <button type="button" onclick="openFleetDashboard()" class="dropdown-item-custom" style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; transition: all 0.2s; border-bottom: 1px solid #f1f5f9; background: transparent; border: none; width: 100%; text-align: left;">
                    <div style="background: #eff6ff; padding: 6px; border-radius: 6px; display: flex;">
                        <i class="material-icons" style="font-size: 18px; color: #3b82f6;">analytics</i>
                    </div>
                    <span style="font-size: 14px; font-weight: 500;">Dashboard de Flota</span>
                </button>

                <!-- Configurar Anclajes -->
                <button type="button" onclick="openAnclajesListModal()" class="dropdown-item-custom" style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; transition: all 0.2s; border-bottom: 1px solid #f1f5f9; background: transparent; border: none; width: 100%; text-align: left;">
                    <div style="background: #e0f2fe; padding: 6px; border-radius: 6px; display: flex;">
                        <i class="material-icons" style="font-size: 18px; color: #0284c7;">link</i>
                    </div>
                    <span style="font-size: 14px; font-weight: 500;">Configurar Anclajes</span>
                </button>

                <!-- Exportar -->
                <a href="#" onclick="exportEquipos(); return false;" class="dropdown-item-custom" style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; transition: all 0.2s; border-bottom: 1px solid #f1f5f9;">
                    <div style="background: #f1f5f9; padding: 6px; border-radius: 6px; display: flex;">
                        <i class="material-icons" style="font-size: 18px; color: #64748b;">download</i>
                    </div>
                    <span style="font-size: 14px; font-weight: 500;">Exportación de Data</span>
                </a>

                {{-- Boton 'Equipos Auxiliares' del dropdown removido: ahora se accede
                     desde el dropdown 'Flota Operacional' del navbar, al lado de
                     'Equipos y Maquinarias'. --}}

                <!-- Catálogo de Modelos -->
                <a href="{{ route('catalogo.index') }}" class="dropdown-item-custom" style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; transition: all 0.2s; border-bottom: 1px solid #f1f5f9;">
                    <div style="background: #f5f3ff; padding: 6px; border-radius: 6px; display: flex;">
                        <i class="material-icons" style="font-size: 18px; color: #7c3aed;">menu_book</i>
                    </div>
                    <span style="font-size: 14px; font-weight: 500;">Catálogo de Modelos</span>
                </a>

                {{-- Eliminar Seleccionados — SIEMPRE visible para todos los usuarios.
                     La validacion del permiso `user.delete` la hace el JS al click:
                     si el usuario NO tiene la clave literal (esta en PERMISOS_EXPLICITOS,
                     ni super.admin la hereda), aparece un modal "Acceso Denegado".
                     La ruta exige can:user.delete tambien — defensa en capas.
                     La eliminacion queda registrada en /admin/historial-documentos
                     via auditoria de soft-delete (deleted_by + deleted_at). --}}
                <button type="button" onclick="window.bulkDeleteEquiposSeleccionados()" class="dropdown-item-custom" style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; transition: all 0.2s; border-bottom: 1px solid #f1f5f9; background: transparent; border: none; width: 100%; text-align: left;">
                    <div style="background: #fee2e2; padding: 6px; border-radius: 6px; display: flex;">
                        <i class="material-icons" style="font-size: 18px; color: #dc2626;">delete_outline</i>
                    </div>
                    <span style="font-size: 14px; font-weight: 500;">Eliminar Seleccionados</span>
                </button>

                <!-- Nuevo -->
                <a href="javascript:void(0)" onclick="handleCreateCheck(event)" class="dropdown-item-custom" style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; transition: all 0.2s;">
                    <div style="background: #e0f2fe; padding: 6px; border-radius: 6px; display: flex;">
                        <i class="material-icons" style="font-size: 18px; color: #0284c7;">add_circle</i>
                    </div>
                    <span style="font-size: 14px; font-weight: 500;">Nuevo Equipo</span>
                </a>
            </div>
        </div>

        <!-- Year filter hidden input moved inside the dropdown container -->

        <!-- Advanced Filter Logic migrated to equipos_index.js -->
    </div>

    {{-- ── $hasFilter: definido aquí para estar disponible tanto en el bloque móvil como en el sidebar ── --}}
    @php
        $hasFilter = request('search_query') || request('id_frente') || request('id_tipo')
                  || request('modelo') || request('marca') || request('anio')
                  || request('categoria') || request('estado')
                  || request('gps') || request('detalle_ubicacion')
                  || request('filter_propiedad') || request('filter_poliza')
                  || request('filter_rotc') || request('filter_racda')
                  || request('filter_adicional') || request('filter_adicional_2');
    @endphp

    {{-- ── Stats compactas solo en móvil ── --}}
    <div class="equipos-mobile-stats">

        <div style="font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; opacity: 0.75; margin-bottom: 6px; display: flex; align-items: center; gap: 5px;">
            <i class="material-icons" style="font-size: 13px;">pie_chart</i>
            Consolidado de Equipos
        </div>
        <div style="display: flex; gap: 8px; justify-content: space-between;">
            <div onclick="filterByStatus('')" class="eq-mobile-stat-block eq-block-total" style="flex:1; display:flex; flex-direction:column; align-items:center; padding:8px 4px; border-radius:10px; background:rgba(255,255,255,0.15); box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                <span style="font-size:10px; font-weight:700; opacity:0.8; margin-bottom:2px;">TOTAL</span>
                <span id="mobile_stats_total" style="font-size:22px; font-weight:800; line-height:1;">{{ $hasFilter ? $stats['total'] : '--' }}</span>
            </div>
            <div onclick="filterByStatus('OPERATIVO')" class="eq-mobile-stat-block eq-block-oper" style="flex:1; display:flex; flex-direction:column; align-items:center; padding:8px 4px; border-radius:10px; background:rgba(34,197,94,0.15); border:1px solid rgba(34,197,94,0.3);">
                <span style="font-size:10px; font-weight:700; color:#86efac; margin-bottom:2px;"><i class="material-icons" style="font-size:11px; vertical-align:middle;">check_circle</i> OPER.</span>
                <span id="mobile_stats_activos" style="color:white; font-size:22px; font-weight:800; line-height:1;">{{ $hasFilter ? $stats['activos'] : '--' }}</span>
            </div>
            <div onclick="filterByStatus('INOPERATIVO')" class="eq-mobile-stat-block eq-block-inop" style="flex:1; display:flex; flex-direction:column; align-items:center; padding:8px 4px; border-radius:10px; background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.3);">
                <span style="font-size:10px; font-weight:700; color:#fca5a5; margin-bottom:2px;"><i class="material-icons" style="font-size:11px; vertical-align:middle;">cancel</i> INOP.</span>
                <span id="mobile_stats_inactivos" style="color:white; font-size:22px; font-weight:800; line-height:1;">{{ $hasFilter ? $stats['inactivos'] : '--' }}</span>
            </div>
        </div>
    </div>

    <div class="custom-scrollbar-container" style="margin-top: 5px; overflow-x: auto; max-width: 100%; -webkit-overflow-scrolling: touch;">

        <table class="admin-table table-equipos-mobile" style="width: 100%; min-width: 1000px; border-collapse: separate; border-spacing: 0 8px;">
            <thead>
                <tr class="table-row-header">
                    <th class="table-header-custom" style="width: 150px;"></th> {{-- Foto + Frente --}}
                    <th class="table-header-custom" style="width: 24%;">TIPO</th>
                    <th class="table-header-custom" style="width: 15%;">MARCA / MODELO</th>
                    <th class="table-header-custom" style="width: 23%;">SERIALES / PLACA / ID</th>
                    <th class="table-header-custom" style="width: 145px;">ESTATUS</th>
                    <th class="table-cell-center" style="width: 72px;"></th> {{-- Acciones --}}
                </tr>
            </thead>
            <tbody id="equiposTableBody" style="font-size: 15px;">
                @include('admin.equipos.partials.table_rows')

            </tbody>
        </table>
        
        <form id="delete-form-global" action="" method="POST" style="display: none;">
            @csrf
            @method('DELETE')
        </form>
    </div>



    {{-- Sin paginación server-side: el virtual scroll (IntersectionObserver) gestiona
         el renderizado progresivo de todas las filas en el cliente. --}}
    <div id="equiposPagination"></div>
</div> <!-- End admin-card -->

<!-- Right Column: Simple Counter -->
<div class="counter-sidebar" style="position: sticky; top: 20px; display: flex; flex-direction: column; gap: 8px;">

    <!-- Main Total Card -->

    <div style="background: linear-gradient(135deg, #1a365d 0%, #2c5282 100%); border-radius: 12px; padding: 15px; color: white; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); position: relative; overflow: hidden;">
        <!-- Decorative Icon -->
        <i class="material-icons" style="position: absolute; right: -15px; bottom: -15px; font-size: 80px; opacity: 0.1; transform: rotate(-15deg);">agriculture</i>
        
        <div style="position: relative; z-index: 2;">
            <div style="font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; opacity: 0.8; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                <i class="material-icons" style="font-size: 14px;">pie_chart</i>
                Consolidado de Equipos
            </div>
            
            <div style="display: flex; align-items: center; gap: 8px;">
                <!-- Main Total -->
                <div onclick="filterByStatus('')" title="Ver todos los equipos" style="display: flex; flex-direction: column; align-items: center; background: rgba(255,255,255,0.15); padding: 8px 6px; border-radius: 10px; min-width: 65px;">
                    <span id="stats_total" style="font-size: 36px; font-weight: 800; line-height: 1;">
                        {{ $hasFilter ? $stats['total'] : '--' }}
                    </span>
                    <span style="font-size: 13px; opacity: 0.8; font-weight: 700; margin-top: 2px;">TOTAL</span>
                </div>

                <!-- Detailed Stats Row -->
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px; flex: 1;">
                    <div onclick="filterByStatus('OPERATIVO')" title="Filtrar: Operativos" style="cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(34, 197, 94, 0.15); padding: 6px 2px; border-radius: 8px; border: 1px solid rgba(34, 197, 94, 0.25); transition: background 0.2s;">
                        <i class="material-icons" style="font-size: 18px; color: #22c55e; margin-bottom: 2px;">check_circle</i>
                        <strong id="stats_activos" style="font-weight: 800; font-size: 16px; color: white;">{{ $hasFilter ? $stats['activos'] : '--' }}</strong>
                        <span style="font-size: 11px; letter-spacing: -0.2px; opacity: 0.9; font-weight: 700; text-transform: uppercase;">Operativo</span>
                    </div>
                    <div onclick="filterByStatus('INOPERATIVO')" title="Filtrar: Inoperativos" style="cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(239, 68, 68, 0.15); padding: 6px 2px; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.25); transition: background 0.2s;">
                        <i class="material-icons" style="font-size: 18px; color: #ef4444; margin-bottom: 2px;">cancel</i>
                        <strong id="stats_inactivos" style="font-weight: 800; font-size: 16px; color: white;">{{ $hasFilter ? $stats['inactivos'] : '--' }}</strong>
                        <span style="font-size: 11px; letter-spacing: -0.2px; opacity: 0.9; font-weight: 700; text-transform: uppercase;">Inoperativo</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Ubicaciones (DETALLE_UBICACION_ACTUAL) — visible solo para frentes TIPO_FRENTE=ESPECIAL -->
    <div id="ubicacionesStatsCard"
         style="background: white; border-radius: 12px; padding: 15px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden; {{ isset($frenteEspecial) && $frenteEspecial ? '' : 'display: none;' }}">
        <div id="ubicacionesStatsContainer">
            @include('admin.equipos.partials.ubicaciones_stats')
        </div>
    </div>

    <!-- Breakdown by Type or Front (Dynamic) -->
    <div style="background: white; border-radius: 12px; padding: 15px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden;">
        <div id="distributionStatsContainer">
            @include('admin.equipos.partials.distribution_stats')
        </div>
    </div>
</div>

</div> <!-- End Page Layout Grid -->






<!-- Image Overlay Modal -->
<div id="imageOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 9999; justify-content: center; align-items: center; cursor: default;" onclick="this.style.display='none'">
    <img id="enlargedImg" style="max-width: 90%; max-height: 90%; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); transition: transform 0.3s ease;">
</div>

<!-- Floating Action Bar -->
<div id="bulkFloatingBar" class="selection-floating-bar">
    <div class="selection-counter" onclick="window.toggleEquiposSoloSel(event)" title="Ver solo los seleccionados (toca de nuevo para ver todos)" style="cursor: pointer;">
        <div style="background: rgba(255,255,255,0.1); padding: 5px; border-radius: 50%; display: flex;">
            <i class="material-icons" style="font-size: 18px; color: white;">functions</i>
        </div>
        <span id="bulkCountText">0</span>
    </div>
    <div style="width: 1px; height: 24px; background: rgba(255,255,255,0.2);"></div>
    <div style="display: flex; gap: 10px;">
        <button type="button" onclick="clearSelection(event)" class="btn-bulk-clear" onmouseover="this.style.color='white'" onmouseout="this.style.color='#94a3b8'">
            <span class="desktop-text">Limpiar</span>
        </button>
        <button type="button" id="btnAnclar" onclick="openAnchorModal(event)" class="btn-bulk-action" style="background: #10b981;">
            <i class="material-icons" style="font-size: 18px;">anchor</i>
            <span class="desktop-text">Anclar</span>
        </button>
        <button type="button" id="btnUnanchor" onclick="unanchorEquipos(event)" class="btn-bulk-action" style="background: #ef4444; display: none;">
            <i class="material-icons" style="font-size: 18px;">link_off</i>
            <span class="desktop-text">Desanclar</span>
        </button>
        <button type="button" id="btnUbicacion" onclick="openUbicacionBulkModal(event)" class="btn-bulk-action" style="background: #64748b;">
            <i class="material-icons" style="font-size: 18px;">description</i>
            <span class="desktop-text">Detalle</span>
        </button>
        <button type="button" onclick="openBulkModal(event)" class="btn-bulk-action">
            <i class="material-icons" style="font-size: 18px;">local_shipping</i>
            <span class="desktop-text">Movilización</span>
        </button>
    </div>
</div>

<!-- Hidden Datalist for Dynamic Modal (Autocomplete Source) -->
<datalist id="dynamicFrentesList" style="display: none;">
    @foreach($frentes as $f)
        {{-- data-ubicacion permite al modal de movilizacion saber si el frente
             registrado ya tiene ubicacion en BD; si esta vacia (frente nuevo O
             frente viejo sin ubicacion), el modal la solicita antes de confirmar
             para no perder la trazabilidad en el PDF. --}}
        <option value="{{ $f->NOMBRE_FRENTE }}" data-id="{{ $f->ID_FRENTE }}" data-ubicacion="{{ $f->UBICACION }}"></option>
    @endforeach
</datalist>


    <!-- Fleet Dashboard Modal -->
    <style>
        @keyframes fleetSpin { from{transform:rotate(0deg)} to{transform:rotate(360deg)} }

        .fleet-dashboard-header {
            background: linear-gradient(135deg, #00004d 0%, #000033 100%);
            padding: 15px 25px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .fleet-header-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }
        
        .fleet-header-left {
            display: flex;
            align-items: center;
            gap: 20px;
            flex: 1;
            min-width: 0;
        }
        
        .fleet-header-title-group {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }
        
        .fleet-header-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }
        
        .fleet-filter-container {
            position: relative;
            width: 300px;
        }

        .fleet-filter-container .dropdown-trigger {
            height: 40px !important;
        }

        .fleet-filter-container input[type="text"] {
            font-size: 14px !important;
        }
        
    </style>
    
    <div id="fleetDashboardModal" class="modal-overlay">
        <div class="modal-content" style="width: 95%; max-width: 1400px; height: 90vh; padding: 0; display: flex; flex-direction: column; background: #f8fafc; position: relative;">
            <!-- Header -->
            <div class="fleet-dashboard-header">
                <div class="fleet-header-wrapper">
                    <!-- Left Group -->
                    <div class="fleet-header-left">
                        <!-- Icon + Title -->
                        <div class="fleet-header-title-group">
                            <div style="background: rgba(255,255,255,0.2); padding: 8px; border-radius: 10px;">
                                <i class="material-icons" style="font-size: 24px; color: white;">analytics</i>
                            </div>
                            <div>
                                <h2 style="margin: 0; color: white; font-size: 18px; font-weight: 700; white-space: nowrap;">Dashboard de Flota</h2>
                            </div>
                        </div>
                        
                        <!-- Controls Group (Export + Filter) -->
                        @php
                            $dashUser       = auth()->user();
                            $dashIsLocal    = $dashUser && $dashUser->NIVEL_ACCESO == 2;
                            $dashFrenteIds  = $dashUser ? $dashUser->getFrentesIds() : [];

                            // Prioridad 1: frente activo en el filtro de URL (id_frente=16)
                            $activeFrenteId   = request('id_frente');
                            $activeFrenteObj  = ($activeFrenteId && $activeFrenteId !== 'all')
                                ? $frentes->firstWhere('ID_FRENTE', $activeFrenteId)
                                : null;

                            // Prioridad 2: primer frente asignado del usuario local
                            $firstAsigFrenteObj = count($dashFrenteIds) > 0
                                ? $frentes->firstWhere('ID_FRENTE', $dashFrenteIds[0])
                                : null;

                            // Prioridad 3: primer frente de la lista global
                            $fallbackFrenteObj = $frentes->first();

                            // Escoger el mejor frente default
                            if ($activeFrenteObj) {
                                $defaultDashboardId     = $activeFrenteObj->ID_FRENTE;
                                $defaultDashboardNombre = $activeFrenteObj->NOMBRE_FRENTE;
                            } elseif ($firstAsigFrenteObj) {
                                $defaultDashboardId     = $firstAsigFrenteObj->ID_FRENTE;
                                $defaultDashboardNombre = $firstAsigFrenteObj->NOMBRE_FRENTE;
                            } else {
                                $defaultDashboardId     = $fallbackFrenteObj->ID_FRENTE ?? '';
                                $defaultDashboardNombre = $fallbackFrenteObj->NOMBRE_FRENTE ?? '';
                            }
                        @endphp
                        <div class="fleet-header-controls">
                            <!-- Export Button -->
                            <button onclick="exportFleetStats()" title="Descargar Reporte Excel" style="background: #10b981; border: none; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; transition: all 0.2s; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); flex-shrink: 0;" onmouseover="this.style.background='#059669'" onmouseout="this.style.background='#10b981'">
                                <i class="material-icons" style="color: white; font-size: 22px;">download</i>
                            </button>

                            <!-- Filter: LOCAL = locked | GLOBAL = dropdown -->
                             <div class="fleet-filter-container">
                                 {{-- LOCAL y GLOBAL usan el mismo dropdown, la variable $frentesDropdown ya viene filtrada del Controller --}}
                                 <input type="hidden" id="dashboardSelectedFrenteId" value="{{ $defaultDashboardId }}">
                                 <input type="hidden" id="dashboardSelectedFrenteNombre" value="{{ $defaultDashboardNombre }}">
                                 <div class="custom-dropdown" id="dashboardFrenteDropdown" style="width: 100%;">
                                 <div class="dropdown-trigger" onclick="dashboardToggleFrente(event)" style="padding: 0; display: flex; align-items: center; background: rgba(255,255,255,0.95); overflow: hidden; border: none; border-radius: 8px; height: 38px; cursor: default;">
                                     <div style="padding: 0 10px; display: flex; align-items: center; color: #64748b; flex-shrink:0;">
                                         <i class="material-icons" style="font-size: 18px;">search</i>
                                     </div>
                                     <input type="text" id="dashboardFrenteSearch"
                                         placeholder="Buscar frente..."
                                         onkeyup="dashboardFilterFrentes(); dashboardToggleClearBtn()"
                                         style="flex: 1; min-width: 0; border: none; background: transparent; padding: 8px 5px; font-size: 13px; font-weight: 500; outline: none; color: #1e293b; cursor: text;"
                                         autocomplete="off">
                                     <i id="dashboardFrenteClearBtn" class="material-icons"
                                        onclick="event.stopPropagation(); dashboardClearFrenteSearch()"
                                        style="padding: 0 8px; color: #64748b; font-size: 20px; display: none; flex-shrink:0;">close</i>
                                 </div>
                                     <!-- Custom Dropdown List -->
                                     <div id="dashboardFrenteList" style="display: none; position: absolute; top: 105%; left: 0; right: 0; max-height: 250px; overflow-y: auto; background: white; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); z-index: 50; padding: 5px;">
                                         {{-- La opcion "Todos los Frentes" fue removida del dashboard:
                                              solo se permite ver estadisticas de un frente especifico a la vez. --}}
                                         @foreach($frentesDropdown as $frente)
                                             <div onclick="dashboardSelectFrente('{{ $frente->ID_FRENTE }}', '{{ addslashes(trim($frente->NOMBRE_FRENTE)) }}', event)" class="dashboard-frente-option dropdown-item" style="padding: 8px 12px; cursor: default; border-radius: 6px; color: #1e293b; font-size: 13px; transition: background 0.2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                                                 {{ $frente->NOMBRE_FRENTE }}
                                             </div>
                                         @endforeach
                                     </div>
                                 </div>
                             </div>
                        </div>
                    </div>

                    <!-- Right: Close Button -->
                    <button onclick="closeFleetDashboard()" style="background: rgba(255,255,255,0.2); border: none; width: 38px; height: 38px; border-radius: 8px; display: flex; align-items: center; justify-content: center; transition: all 0.2s; flex-shrink: 0;" onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                        <i class="material-icons" style="color: white; font-size: 22px;">close</i>
                    </button>
                </div>
            </div>

            <!-- Loading Spinner Overlay -->
            <div id="fleetDashboardSpinner" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.95); display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 1000; border-radius: 12px;">
                <div class="spinner-circle" style="width: 60px; height: 60px; border-width: 4px;"></div>
                <p style="margin-top: 20px; color: #64748b; font-size: 14px; font-weight: 600;">Cargando estadísticas...</p>
            </div>

            <!-- Dashboard Content -->
            <div style="flex: 1; overflow-y: auto; padding: 25px;">
                <!-- Stats Cards Row -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 0 0 25px 0;">

                    <!-- Total Equipment -->
                    <div style="background: white; border-radius: 12px; padding: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-left: 4px solid #08234dff;">
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <div>
                                <p style="margin: 0; font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Total Equipos</p>
                                <h3 id="stat_total" style="margin: 5px 0 0 0; font-size: 24px; color: #0d3370ff; font-weight: 800;">0</h3>
                            </div>
                            <div style="background: #eff6ff; padding: 8px; border-radius: 8px;">
                                <i class="material-icons" style="font-size: 20px; color: #0d3370ff;">inventory_2</i>
                            </div>
                        </div>
                    </div>

                    <!-- Fleet New -->
                    <div style="background: white; border-radius: 12px; padding: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-left: 4px solid #10b981;">
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <div>
                                <p style="margin: 0; font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Flota Nueva (≥2025)</p>
                                <h3 id="stat_fleet_new" style="margin: 5px 0 0 0; font-size: 24px; color: #1e293b; font-weight: 800;">0</h3>
                            </div>
                            <div style="background: #f0fdf4; padding: 8px; border-radius: 8px;">
                                <i class="material-icons" style="font-size: 20px; color: #10b981;">new_releases</i>
                            </div>
                        </div>
                    </div>

                    <!-- Fleet Old -->
                    <div style="background: white; border-radius: 12px; padding: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-left: 4px solid #f59e0b;">
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <div>
                                <p style="margin: 0; font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Flota Antigua (<2025)</p>
                                <h3 id="stat_fleet_old" style="margin: 5px 0 0 0; font-size: 24px; color: #1e293b; font-weight: 800;">0</h3>
                            </div>
                            <div style="background: #fffbeb; padding: 8px; border-radius: 8px;">
                                <i class="material-icons" style="font-size: 20px; color: #f59e0b;">history</i>
                            </div>
                        </div>
                    </div>

                    <!-- Estimated Consumption -->
                    <div style="background: white; border-radius: 12px; padding: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-left: 4px solid #7e1010ff;">
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <div>
                                <p style="margin: 0; font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Consumo Est. (L/Día)</p>
                                <h3 id="stat_consumption" style="margin: 5px 0 0 0; font-size: 24px; color: #1e293b; font-weight: 800;">0</h3>
                            </div>
                            <div style="background: #fef2f2; padding: 8px; border-radius: 8px;">
                                <i class="material-icons" style="font-size: 20px; color: #8f0b0bff;">local_gas_station</i>
                            </div>
                        </div>
                    </div>
                </div>


                <!-- Charts Row -->
                <div id="fleetChartsGrid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 20px;">
                    <!-- Estado Operativo -->
                    <div id="fdm-panel-status" style="background: white; border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                        <h4 style="margin: 0 0 20px 0; font-size: 16px; color: #1e293b; font-weight: 700; text-transform: uppercase; display: flex; align-items: center; justify-content: space-between;">
                            <span style="display: flex; align-items: center; gap: 10px;">
                                <i class="material-icons" style="font-size: 20px; color: #10b981;">donut_small</i>
                                Estado Operativo de Equipos
                            </span>
                            <button onclick="window.descargarPanelHtmlFDM('fdm-panel-status', 'estado_operativo')" title="Descargar imagen" style="border:none;background:transparent;cursor:pointer;color:#94a3b8;display:flex;align-items:center;padding:4px 8px;border-radius:8px;transition:background .2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                                <i class="material-icons" style="font-size:17px;">photo_camera</i>
                            </button>
                        </h4>
                        <canvas id="chartStatusByFront" style="max-height: 350px;"></canvas>
                    </div>

                    <!-- Flota Nueva vs Vieja por Tipo -->
                    <div id="fdm-panel-age" style="background: white; border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                        <h4 style="margin: 0 0 20px 0; font-size: 16px; color: #1e293b; font-weight: 700; text-transform: uppercase; display: flex; align-items: center; justify-content: space-between;">
                            <span style="display: flex; align-items: center; gap: 10px;">
                                <i class="material-icons" style="font-size: 20px; color: #3b82f6;">bar_chart</i>
                                Flota Nueva vs Vieja por Tipo de Equipo
                            </span>
                            <button onclick="window.descargarPanelHtmlFDM('fdm-panel-age', 'flota_edad_tipo')" title="Descargar imagen" style="border:none;background:transparent;cursor:pointer;color:#94a3b8;display:flex;align-items:center;padding:4px 8px;border-radius:8px;transition:background .2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                                <i class="material-icons" style="font-size:17px;">photo_camera</i>
                            </button>
                        </h4>
                        <canvas id="chartAgeByType"></canvas>
                    </div>

                    <!-- Flota Pesada vs Liviana por Tipo -->
                    <div id="fdm-panel-category" style="background: white; border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                        <h4 style="margin: 0 0 20px 0; font-size: 16px; color: #1e293b; font-weight: 700; text-transform: uppercase; display: flex; align-items: center; justify-content: space-between;">
                            <span style="display: flex; align-items: center; gap: 10px;">
                                <i class="material-icons" style="font-size: 20px; color: #f59e0b;">category</i>
                                Flota Pesada vs Liviana por Tipo
                            </span>
                            <button onclick="window.descargarPanelHtmlFDM('fdm-panel-category', 'flota_pesada_liviana')" title="Descargar imagen" style="border:none;background:transparent;cursor:pointer;color:#94a3b8;display:flex;align-items:center;padding:4px 8px;border-radius:8px;transition:background .2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                                <i class="material-icons" style="font-size:17px;">photo_camera</i>
                            </button>
                        </h4>
                        <canvas id="chartCategoryByType"></canvas>
                    </div>

                    <!-- Inoperatividad por Tipo de Equipo -->
                    <div id="fdm-panel-inoperative" style="background: white; border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                        <h4 style="margin: 0 0 20px 0; font-size: 16px; color: #1e293b; font-weight: 700; text-transform: uppercase; display: flex; align-items: center; justify-content: space-between;">
                            <span style="display: flex; align-items: center; gap: 10px;">
                                <i class="material-icons" style="font-size: 20px; color: #ef4444;">warning_amber</i>
                                Inoperatividad por Tipo de Equipo
                            </span>
                            <button onclick="window.descargarPanelHtmlFDM('fdm-panel-inoperative', 'inoperatividad')" title="Descargar imagen" style="border:none;background:transparent;cursor:pointer;color:#94a3b8;display:flex;align-items:center;padding:4px 8px;border-radius:8px;transition:background .2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                                <i class="material-icons" style="font-size:17px;">photo_camera</i>
                            </button>
                        </h4>
                        <canvas id="chartInoperativeByType"></canvas>
                    </div>
                </div>

                <!-- Equipos Asignados por Frente (al final) -->
                <div id="fdm-panel-assigned" style="background: white; border-radius: 12px; padding: 20px 25px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-top: 20px;">
                    <div style="display:flex; align-items:center; justify-content: space-between; margin-bottom: 16px;">
                        <span style="font-size:14px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:8px;">
                            <i class="material-icons" style="font-size:18px; color:#475569;">directions_bus</i>
                            Equipos Asignados por Frente
                            <span style="font-size:11px; color:#94a3b8; font-weight:400; margin-left:4px;">— flota actual en cada frente</span>
                        </span>
                        <button onclick="window.descargarPanelHtmlFDM('fdm-panel-assigned', 'equipos_asignados_por_frente')" title="Descargar imagen" style="border:none;background:transparent;cursor:pointer;color:#94a3b8;display:flex;align-items:center;padding:4px 8px;border-radius:8px;transition:background .2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                            <i class="material-icons" style="font-size:17px;">photo_camera</i>
                        </button>
                    </div>
                    <div id="fleetEqAsigLoading" style="display:flex; align-items:center; justify-content:center; height:80px; color:#94a3b8; font-size:13px; gap:8px;">
                        <i class="material-icons" style="animation:fleetSpin 1s linear infinite; font-size:18px;">refresh</i> Cargando...
                    </div>
                    <div id="fleetEqAsigBody" style="display:none;"></div>
                </div>

            </div>
        </div>
    </div>


    @include('admin.equipos.partials.equipment_details_modal')

    <style>
        /* Fleet Dashboard Mobile Responsive */
        @media (max-width: 768px) {
            #fleetDashboardModal .modal-content {
                width: 100% !important;
                height: 100dvh !important;   /* usa dvh para evitar barra de browser en iOS */
                max-width: 100% !important;
                border-radius: 0 !important;
            }

            /* Header compacto en mobile */
            .fleet-dashboard-header {
                padding: 10px 14px !important;
            }

            .fleet-header-wrapper {
                flex-direction: column !important;
                align-items: flex-start !important;
                position: relative !important;
                padding-right: 42px !important;
                gap: 10px !important;
            }

            .fleet-header-left {
                width: 100% !important;
                flex-direction: column !important;
                gap: 10px !important;
            }

            /* Título más pequeño en mobile */
            .fleet-header-title-group h2 {
                font-size: 14px !important;
            }

            .fleet-header-title-group p {
                font-size: 10px !important;
            }

            /* Icono del dashboard más pequeño */
            .fleet-header-title-group > div:first-child {
                padding: 6px !important;
            }

            .fleet-header-title-group > div:first-child .material-icons {
                font-size: 18px !important;
            }

            /* Controls: Export + Filter Row */
            .fleet-header-controls {
                width: 100% !important;
                justify-content: flex-start !important;
                gap: 8px !important;
            }

            /* Filter Container crece para llenar espacio */
            .fleet-filter-container {
                width: auto !important;
                flex: 1 !important;
                min-width: 0 !important;
            }

            .fleet-filter-container .custom-dropdown {
                width: 100% !important;
            }

            /* Botón cerrar posicionado top-right absoluto */
            .fleet-header-wrapper > button:last-child {
                position: absolute !important;
                top: 0 !important;
                right: 0 !important;
                width: 32px !important;
                height: 32px !important;
                background: rgba(255,255,255,0.15) !important;
            }

            /* Dashboard content: menos padding y prevención de overflow */
            #fleetDashboardModal .modal-content > div[style*="overflow-y: auto"] {
                padding: 14px !important;
                overflow-x: hidden !important;
                box-sizing: border-box !important;
                width: 100% !important;
            }

            /* Stat cards: 2 columnas en mobile */
            #fleetDashboardModal [style*="grid-template-columns: repeat(auto-fit, minmax(180px"] {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 8px !important;
                margin-bottom: 14px !important;
                width: 100% !important;
            }

            /* Stat cards: menos padding y fuente más pequeña */
            #fleetDashboardModal [style*="grid-template-columns: repeat(auto-fit, minmax(180px"] > div {
                padding: 10px !important;
                min-width: 0 !important;
                box-sizing: border-box !important;
                word-wrap: break-word !important;
            }

            #fleetDashboardModal [style*="grid-template-columns: repeat(auto-fit, minmax(180px"] h3 {
                font-size: 18px !important;
            }

            #fleetDashboardModal [style*="grid-template-columns: repeat(auto-fit, minmax(180px"] p {
                font-size: 9px !important; /* Ligeramente más pequeño para no desbordar */
                white-space: normal !important;
            }


            /* Charts: 1 columna y sin overflow */
            #fleetChartsGrid {
                grid-template-columns: 1fr !important;
                gap: 12px !important;
                max-width: 100% !important;
                overflow: hidden !important;
            }

            /* Container for each chart allowed to shrink */
            #fleetChartsGrid > div {
                min-width: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
                box-sizing: border-box !important;
                overflow: hidden !important;
            }

            /* Panels de gráficos: menos padding */
            #fdm-panel-status,
            #fdm-panel-age,
            #fdm-panel-category,
            #fdm-panel-inoperative,
            #fdm-panel-assigned {
                padding: 14px !important;
                width: 100% !important;
                box-sizing: border-box !important;
            }

            /* Asegurar que el canvas respete el contenedor */
            #fleetChartsGrid canvas {
                max-width: 100% !important;
                height: auto !important;
            }

            /* Título de paneles */
            #fdm-panel-status h4,
            #fdm-panel-age h4,
            #fdm-panel-category h4,
            #fdm-panel-inoperative h4 {
                font-size: 13px !important;
                margin-bottom: 12px !important;
            }
        }

        /* Tablet (769-1024px): 2 columnas de gráficos */
        @media (min-width: 769px) and (max-width: 1024px) {
            #fleetChartsGrid {
                grid-template-columns: repeat(2, 1fr) !important;
            }
        }
    </style>

<!-- Anclajes Dashboard Modal -->
<div id="anclajesListModal" class="modal-overlay" style="z-index: 10000;">
    <div class="modal-content" style="width: 90%; max-width: 800px; max-height: 90vh; background: #fff; border-radius: 12px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <!-- Header -->
        <div style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); padding: 15px 20px; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="background: rgba(255,255,255,0.1); padding: 8px; border-radius: 8px;">
                    <i class="material-icons" style="color: #fff; font-size: 20px;">link</i>
                </div>
                <h3 style="margin: 0; color: #fff; font-size: 16px; font-weight: 600;">Anclaje de Equipos</h3>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <button type="button" onclick="window.exportAnclajesToExcel()" title="Exportar a Excel (.xlsx)" style="background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.28); color: #ffffff; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 6px; border-radius: 6px; transition: all 0.2s;" onmouseover="this.style.background='rgba(255, 255, 255, 0.22)'" onmouseout="this.style.background='rgba(255, 255, 255, 0.12)'">
                    <i class="material-icons" style="font-size: 18px;">download</i>
                </button>
                <button type="button" onclick="document.getElementById('anclajesListModal').classList.remove('active')" style="background: transparent; border: none; color: #94a3b8; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 4px; transition: color 0.2s;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='#94a3b8'">
                    <i class="material-icons">close</i>
                </button>
            </div>
        </div>
        
        <!-- Loading -->
        <div id="anclajesLoading" style="padding: 40px; text-align: center; color: #64748b;">
            <i class="material-icons" style="font-size: 32px; animation: fleetSpin 1s linear infinite;">refresh</i>
            <p style="margin-top: 10px; font-size: 14px;">Cargando equipos anclados...</p>
        </div>

        <!-- Body -->
        <div id="anclajesBody" style="display: none; padding: 14px 16px; overflow-y: auto; flex: 1; background: #f8fafc;">
        <div id="anclajesGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px;">
                <!-- Dynamically populated -->
            </div>
        </div>
    </div>
</div>

<script>
    function openAnclajesListModal() {
        document.getElementById('splitDropdownMenu').style.display = 'none';
        const modal = document.getElementById('anclajesListModal');
        modal.classList.add('active');
        document.getElementById('anclajesLoading').style.display = 'block';
        document.getElementById('anclajesBody').style.display = 'none';

        // Hereda los filtros activos del listado principal (id_frente, id_tipo).
        let fValue = '', tValue = '';
        const fInput = document.querySelector('input[name="id_frente"][data-filter-value]');
        const tInput = document.querySelector('input[name="id_tipo"][data-filter-value]');
        if (fInput && fInput.value && fInput.value !== 'all') fValue = fInput.value;
        if (tInput && tInput.value && tInput.value !== 'all') tValue = tInput.value;
        const _qsAnch = new URLSearchParams();
        if (fValue) _qsAnch.set('frente_id', fValue);
        if (tValue) _qsAnch.set('id_tipo', tValue);

        fetch('{{ route("equipos.getAnchors") }}' + (_qsAnch.toString() ? ('?' + _qsAnch.toString()) : ''))
            .then(res => res.json())
            .then(data => {
                window.lastAnclajesData = data; // Store globally for export
                document.getElementById('anclajesLoading').style.display = 'none';
                document.getElementById('anclajesBody').style.display = 'block';

                // Backend ahora retorna { pairs, aux }: pairs = anclajes equipo↔equipo,
                // aux = grupos equipo→auxiliares (1 host con N aux). Antes era array
                // plano de pares — defensivo: si el backend devuelve array (legacy),
                // lo tratamos como pairs sin aux.
                const pairs = Array.isArray(data) ? data : (Array.isArray(data.pairs) ? data.pairs : []);
                const auxGroups = (data && Array.isArray(data.aux)) ? data.aux : [];

                const grid = document.getElementById('anclajesGrid');
                if (pairs.length === 0 && auxGroups.length === 0) {
                    grid.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 30px; color: #94a3b8; background: #fff; border-radius: 8px; border: 1px dashed #cbd5e1;">No hay equipos anclados en este frente.</div>';
                    return;
                }

                const esc = (s) => String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

                let html = '';
                pairs.forEach(pair => {
                    const a = pair.eq_a;
                    const b = pair.eq_b;
                    if(!a || !b) return;

                    // Compute primary identification (Placa or Serial)
                    const aPlacaOrSerial = (a.placa && a.placa !== 'S/P') ? a.placa : (a.serial || 'N/A');
                    const bPlacaOrSerial = (b.placa && b.placa !== 'S/P') ? b.placa : (b.serial || 'N/A');

                    // Compute Tags (Type + Label)
                    const aEtiquetaHtml = a.etiqueta ? `<span style="background: rgba(0,0,0,0.05); padding: 2px 6px; border-radius: 4px; font-weight: 800; color: #475569; margin-left: 5px; font-size: 10px;">#${a.etiqueta}</span>` : '';
                    const bEtiquetaHtml = b.etiqueta ? `<span style="background: rgba(0,0,0,0.05); padding: 2px 6px; border-radius: 4px; font-weight: 800; color: #475569; margin-left: 5px; font-size: 10px;">#${b.etiqueta}</span>` : '';

                    const aFotoHtml = a.foto ? `<img src="${a.foto}" onerror="this.outerHTML='<div style=&quot;width: 32px; height: 26px; border-radius: 5px; background: #fff; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0; flex-shrink: 0;&quot;><i class=&quot;material-icons&quot; style=&quot;color: #cbd5e1; font-size: 14px;&quot;>directions_car</i></div>'" style="width: 32px; height: 26px; object-fit: contain; border-radius: 5px; background: #fff; border: 1px solid #e2e8f0; flex-shrink: 0;">` : `<div style="width: 32px; height: 26px; border-radius: 5px; background: #fff; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0; flex-shrink: 0;"><i class="material-icons" style="color: #cbd5e1; font-size: 14px;">directions_car</i></div>`;
                    const bFotoHtml = b.foto ? `<img src="${b.foto}" onerror="this.outerHTML='<div style=&quot;width: 32px; height: 26px; border-radius: 5px; background: #fff; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0; flex-shrink: 0;&quot;><i class=&quot;material-icons&quot; style=&quot;color: #cbd5e1; font-size: 14px;&quot;>directions_car</i></div>'" style="width: 32px; height: 26px; object-fit: contain; border-radius: 5px; background: #fff; border: 1px solid #e2e8f0; flex-shrink: 0;">` : `<div style="width: 32px; height: 26px; border-radius: 5px; background: #fff; display: flex; align-items: center; justify-content: center; border: 1px solid #e2e8f0; flex-shrink: 0;"><i class="material-icons" style="color: #cbd5e1; font-size: 14px;">directions_car</i></div>`;

                    html += `
                    <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px; display: flex; flex-direction: column; align-items: stretch; gap: 0; box-shadow: 0 1px 4px rgba(0,0,0,0.06); transition: box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.12)'" onmouseout="this.style.boxShadow='0 1px 4px rgba(0,0,0,0.06)'">
                        
                        <!-- Equipo A -->
                        <div style="display: flex; align-items: center; gap: 8px; background: #f8fafc; padding: 5px 8px; border-radius: 6px; border: 1px solid #f1f5f9;">
                            ${aFotoHtml}
                            <div style="display: flex; flex-direction: column; flex: 1; overflow: hidden;">
                                <span style="font-size: 9px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.4px;">${a.tipo || 'Sin Tipo'}${aEtiquetaHtml}</span>
                                <span style="font-size: 12px; font-weight: 800; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.3;">${aPlacaOrSerial}</span>
                            </div>
                        </div>
                        
                        <!-- Icono Link Central -->
                        <div style="display: flex; justify-content: center; align-items: center; height: 14px; position: relative;">
                            <div style="position: absolute; inset: 0 calc(50% - 1px); background: #e2e8f0; width: 1px; margin: 0 auto;"></div>
                            <div style="background: #dbeafe; width: 18px; height: 18px; border-radius: 50%; color: #2563eb; z-index: 2; border: 2px solid #fff; display: flex; align-items: center; justify-content: center; position: relative;">
                                <i class="material-icons" style="font-size: 10px; transform: rotate(90deg);">link</i>
                            </div>
                        </div>

                        <!-- Equipo B -->
                        <div style="display: flex; align-items: center; gap: 8px; background: #f8fafc; padding: 5px 8px; border-radius: 6px; border: 1px solid #f1f5f9;">
                            ${bFotoHtml}
                            <div style="display: flex; flex-direction: column; flex: 1; overflow: hidden;">
                                <span style="font-size: 9px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.4px;">${b.tipo || 'Sin Tipo'}${bEtiquetaHtml}</span>
                                <span style="font-size: 12px; font-weight: 800; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.3;">${bPlacaOrSerial}</span>
                            </div>
                        </div>

                    </div>`;
                });

                // ─── Anclajes equipo→auxiliares (1 tarjeta por host con sus aux) ───
                // Mismo patron visual que el modal de /admin/equipos-auxiliares:
                // host arriba (azul), lista de auxiliares abajo (ambar).
                auxGroups.forEach(g => {
                    const h = g.host || {};
                    const auxes = Array.isArray(g.auxes) ? g.auxes : [];
                    if (!h.id || auxes.length === 0) return;
                    const hostLabel = h.placa || h.serial || h.codigo || ('#' + h.id);
                    const hostType  = (h.tipo || 'Equipo').toString();
                    const hostMarca = h.marca ? esc(h.marca) : '';
                    const hostFotoHtml = h.foto
                        ? `<img src="${esc(h.foto)}" alt="" style="width:100%;height:100%;object-fit:contain;background:white;" onerror="this.outerHTML='<i class=&quot;material-icons&quot; style=&quot;font-size:22px;color:#1e40af;&quot;>directions_car</i>'">`
                        : '<i class="material-icons" style="font-size:22px;color:#1e40af;">directions_car</i>';

                    const auxRowsHtml = auxes.map(a => {
                        const auxLabel = a.serial || ((a.marca || '') + ' ' + (a.modelo || '')).trim() || '—';
                        const auxFotoHtml = a.foto
                            ? `<img src="${esc(a.foto)}" alt="" style="width:100%;height:100%;object-fit:contain;background:white;" onerror="this.outerHTML='<i class=&quot;material-icons&quot; style=&quot;font-size:16px;color:#f59e0b;&quot;>construction</i>'">`
                            : '<i class="material-icons" style="font-size:16px;color:#f59e0b;">construction</i>';
                        return `<div style="display:flex; align-items:center; gap:8px; padding:6px 8px; background:#fff7ed; border-radius:6px; border:1px solid #fed7aa;">
                            <div style="background:#fff;padding:0;border-radius:5px;width:30px;height:30px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;border:1px solid #fed7aa;">${auxFotoHtml}</div>
                            <div style="flex:1; min-width:0;">
                                <div style="font-size:9px; font-weight:700; color:#92400e; text-transform:uppercase; letter-spacing:0.3px;">${esc(a.tipo_label || a.tipo || 'AUXILIAR')}</div>
                                <div style="font-size:12px; font-weight:800; color:#7c2d12; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${esc(auxLabel)}</div>
                                ${a.marca || a.modelo ? `<div style="font-size:10px; color:#9a3412;">${esc(a.marca||'')} ${esc(a.modelo||'')}</div>` : ''}
                            </div>
                        </div>`;
                    }).join('');

                    html += `<div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:10px; display:flex; flex-direction:column; gap:8px; box-shadow:0 1px 4px rgba(0,0,0,0.06);">
                        <div style="display:flex; align-items:center; gap:10px; padding:8px 10px; background:#eff6ff; border-radius:8px; border:1px solid #bfdbfe;">
                            <div style="background:#fff;padding:0;border-radius:6px;width:42px;height:42px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;border:1px solid #bfdbfe;">${hostFotoHtml}</div>
                            <div style="flex:1; min-width:0;">
                                <div style="font-size:9.5px; font-weight:700; color:#1e3a8a; text-transform:uppercase; letter-spacing:0.4px;">${esc(hostType)}</div>
                                <div style="font-size:14px; font-weight:800; color:#1e3a8a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${esc(hostLabel)}</div>
                                ${hostMarca ? `<div style="font-size:10.5px; color:#1d4ed8; margin-top:1px;">${hostMarca} ${esc(h.modelo||'')}</div>` : ''}
                            </div>
                            <span style="background:#10b981;color:white;font-size:10px;font-weight:800;padding:2px 8px;border-radius:10px;flex-shrink:0;">${auxes.length}</span>
                        </div>
                        <div style="display:flex; flex-direction:column; gap:5px;">${auxRowsHtml}</div>
                    </div>`;
                });

                grid.innerHTML = html;
            })
            .catch(err => {
                console.error('Error loading anchors:', err);
                document.getElementById('anclajesLoading').style.display = 'none';
                document.getElementById('anclajesBody').style.display = 'block';
                document.getElementById('anclajesGrid').innerHTML = '<div style="grid-column: 1/-1; text-align: center; color: #ef4444; padding: 20px;">Error al cargar anclajes.</div>';
            });
    }

    // Exporta los anclajes a XLSX generado por PhpSpreadsheet (backend) con el
    // mismo encabezado corporativo de los demas reportes del sistema.
    window.exportAnclajesToExcel = function() {
        const data = window.lastAnclajesData || {};
        const _pairs = Array.isArray(data) ? data : (Array.isArray(data.pairs) ? data.pairs : []);
        const _aux   = (data && Array.isArray(data.aux)) ? data.aux : [];
        if (_pairs.length === 0 && _aux.length === 0) {
            if (typeof window.showToast === 'function') {
                window.showToast('No hay equipos anclados para exportar.', 'warning');
            } else {
                alert('No hay datos para exportar.');
            }
            return;
        }
        // Hereda los filtros activos (frente + tipo) del listado principal —
        // si el modal mostro N pares filtrados, el Excel descarga esos N
        // pares (no toda la flota). Mismo comportamiento del modulo de aux.
        const fValueElement = document.querySelector('input[name="id_frente"][data-filter-value]');
        const tValueElement = document.querySelector('input[name="id_tipo"][data-filter-value]');
        const fValue = (fValueElement && fValueElement.value && fValueElement.value !== 'all') ? fValueElement.value : '';
        const tValue = (tValueElement && tValueElement.value && tValueElement.value !== 'all') ? tValueElement.value : '';
        const _qsExp = new URLSearchParams();
        if (fValue) _qsExp.set('frente_id', fValue);
        if (tValue) _qsExp.set('id_tipo', tValue);
        const url = '{{ route("equipos.exportAnclajes") }}' + (_qsExp.toString() ? ('?' + _qsExp.toString()) : '');

        // Fetch + blob en lugar de <a href>.click(): evita el spinner nativo
        // de la pestaña del navegador. Mostramos el preloader global propio
        // de la app mientras se genera el XLSX en el servidor.
        if (typeof window.showPreloader === 'function') window.showPreloader();

        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const cd = r.headers.get('content-disposition') || '';
                const m  = cd.match(/filename="?([^";]+)"?/i);
                const fname = m ? m[1] : ('Anclajes_' + new Date().toISOString().slice(0,10) + '.xlsx');
                return r.blob().then(blob => ({ blob, fname }));
            })
            .then(({ blob, fname }) => {
                const blobUrl = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = blobUrl;
                link.download = fname;
                link.style.display = 'none';
                document.body.appendChild(link);
                link.click();
                setTimeout(() => {
                    document.body.removeChild(link);
                    URL.revokeObjectURL(blobUrl);
                }, 300);
                if (typeof window.showToast === 'function') {
                    window.showToast('Descarga lista: ' + fname, 'success');
                }
            })
            .catch(err => {
                console.error('[exportAnclajes]', err);
                if (typeof window.showToast === 'function') {
                    window.showToast('Error al descargar el Excel de anclajes.', 'error');
                } else {
                    alert('Error al descargar el Excel.');
                }
            })
            .finally(() => {
                if (typeof window.hidePreloader === 'function') window.hidePreloader();
            });
    };

    // CAN_CREATE_EQUIPOS, CAN_ASSIGN_EQUIPOS, CAN_CHANGE_STATUS ya estan
    // definidos globalmente en layouts/estructura_base.blade.php — no se
    // redefinen aqui para evitar duplicidad.
    // CAN_CREATE_INFO es un alias historico requerido por equipos_index.js.
    window.CAN_CREATE_INFO = window.CAN_CREATE_EQUIPOS;
    window.CREATE_URL = "{{ route('equipos.create') }}";
</script>

{{-- ═══════════════════════════════════════════════════════════
     MODAL SUB-ACTIVOS (Herramientas y Equipos Menores)
{{-- Modal Sub-Activos removido: el modulo vivio en este blade hasta abril-2026.
     Ahora existe como modulo propio en /admin/equipos-auxiliares con anclaje 1:N.
     Ver resources/views/admin/equipos-auxiliares/. --}}

{{-- Seed window.equiposData en carga inicial (hard-refresh / primera visita).
     En cargas AJAX, loadEquipos() lo rellena desde data.equiposData.
     Aquí lo hacemos para que el modal del ojo funcione sin necesitar hacer
     una búsqueda primero. --}}
@if(!empty($jsonPayload))
<script>
    window.equiposData = Object.assign(window.equiposData || {}, @json($jsonPayload));
</script>
@endif

{{-- ═══════════════════════════════════════════════════════════
     PAPELERA DE EQUIPOS — soft-delete con auditoria de quien borro.
     El boton "Eliminar Seleccionados" del dropdown es siempre visible:
     la validacion del permiso (user.delete) la hace JS al click. La
     ruta tambien valida via middleware can:user.delete (defensa en capas).

     IMPORTANTE — esta accion NO depende del rol del usuario, NI siquiera
     del super.admin. Exige la clave LITERAL `user.delete` en la columna
     PERMISOS porque esta listada en `Usuario::PERMISOS_EXPLICITOS` (junto
     con las claves de almacen). El Gate::before global respeta esa lista
     y NO concede `user.delete` a super.admin automaticamente — un
     super.admin que deba eliminar equipos necesita la clave literal
     en su PERMISOS. Por eso aqui basta con preguntar `can('user.delete')`.
     ═══════════════════════════════════════════════════════════ --}}
<script>
    window.CAN_DELETE_EQUIPOS = {{ auth()->user() && auth()->user()->can('user.delete') ? 'true' : 'false' }};
</script>
<script>
(function () {
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    function getSelectedIds() {
        // Reusa el selectedEquipos global (selection bar de equipos_index.js).
        return Object.keys(window.selectedEquipos || {});
    }

    window.bulkDeleteEquiposSeleccionados = function () {
        document.getElementById('splitDropdownMenu').style.display = 'none';
        // Permiso: el boton es siempre visible para mostrar la accion en el
        // menu, pero solo se ejecuta si el usuario tiene la clave literal
        // user.delete (en PERMISOS_EXPLICITOS — ni super.admin la hereda).
        // Sin el permiso → toast moderno (no modal bloqueante).
        if (window.CAN_DELETE_EQUIPOS === false || window.CAN_DELETE_EQUIPOS === 'false') {
            if (typeof window.showToast === 'function') {
                window.showToast('No tienes permiso para eliminar equipos.', 'error');
            } else {
                alert('No tienes permiso para eliminar equipos.');
            }
            return;
        }
        const ids = getSelectedIds();
        if (ids.length === 0) {
            if (window.showToast) window.showToast('Por favor, selecciona al menos un equipo en la tabla antes de eliminar.', 'warning');
            else alert('Por favor, selecciona al menos un equipo en la tabla antes de eliminar.');
            return;
        }
        const proceed = function () {
            if (window.showPreloader) window.showPreloader();
            fetch('{{ route("equipos.bulkDelete") }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf(),
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ ids: ids.map(x => parseInt(x, 10)) })
            })
            .then(r => r.json().catch(() => ({})).then(d => ({ ok: r.ok, body: d })))
            .then(res => {
                if (window.hidePreloader) window.hidePreloader();
                if (res.ok && res.body.success) {
                    if (window.showToast) window.showToast(res.body.message || 'Equipos eliminados.', 'success');
                    if (typeof window.clearSelection === 'function') window.clearSelection();
                    if (typeof window.loadEquipos === 'function') window.loadEquipos();
                } else {
                    const msg = (res.body && res.body.message) || 'No se pudo eliminar.';
                    if (window.showToast) window.showToast(msg, 'error');
                    else alert(msg);
                }
            })
            .catch(() => {
                if (window.hidePreloader) window.hidePreloader();
                if (window.showToast) window.showToast('Error de red al eliminar.', 'error');
            });
        };
        if (typeof window.showModal === 'function') {
            window.showModal({
                type: 'warning',
                title: 'Eliminar Equipos',
                message: '¿Eliminar ' + ids.length + ' equipo(s) seleccionado(s)?',
                confirmText: 'Eliminar',
                cancelText: 'Cancelar',
                onConfirm: proceed
            });
        } else if (confirm('¿Eliminar ' + ids.length + ' equipo(s)?')) {
            proceed();
        }
    };

    // abrirPapeleraEquipos / cargarPapelera / recuperarEquipo: movidos a
    // /admin/historial-documentos donde el usuario los necesita junto con
    // el resto del audit trail. Aqui solo queda bulkDeleteEquiposSeleccionados.
})();
</script>

{{-- ═══════════════════════════════════════════════════════════
     MODAL BULK LOOKUP — tabla estilo Excel: cada fila es una celda
     editable. Pegar una columna copiada de Excel distribuye cada
     valor en su propia fila. Backend: POST {{ route('equipos.bulkLookup') }}
     busca en SERIAL_CHASIS / SERIAL_DE_MOTOR / NUMERO_ETIQUETA /
     CODIGO_PATIO y documentacion.PLACA.
     ═══════════════════════════════════════════════════════════ --}}
<style>
    /* Textarea masiva: el usuario pega/escribe seriales separados por
       cualquier whitespace (espacio, tab, newline). Antes era una tabla
       con un <input> por fila — paste con 300+ items fallaba silenciosamente
       en algunos escenarios. El textarea elimina ese riesgo de raiz. */
    #bulkLookupTextarea {
        width: 100%; min-height: 220px; max-height: 50vh;
        padding: 8px 10px; box-sizing: border-box;
        border: 1px solid #cbd5e0; border-radius: 8px;
        font-family: 'Courier New', monospace; font-size: 12px;
        line-height: 1.4; text-transform: uppercase;
        resize: vertical; outline: none;
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    #bulkLookupTextarea:focus {
        border-color: #0067b1;
        box-shadow: 0 0 0 3px rgba(0,103,177,0.12);
    }
    /* El frente usa el componente global .custom-dropdown (mismo diseño que el resto
       de la app); su estilo viene de las reglas globales. Ocupa todo el ancho
       disponible junto al botón Buscar (sin tope). */
    #bulkLookupFrenteDropdown { flex: 1 1 auto; }

    /* ── Teléfono ─────────────────────────────────────────────────────────────
       El modal pasa a pantalla completa y el footer reparte los botones de forma
       uniforme; en pantallas chicas los botones van SIN icono (solo texto) para
       que quepan y se vean parejos. !important porque el markup usa estilos inline. */
    @media (max-width: 600px) {
        #bulkLookupModal .modal-content {
            width: 100% !important;
            max-width: 100% !important;
            height: 100dvh !important;
            max-height: 100dvh !important;
            border-radius: 0 !important;
        }
        #bulkLookupFooter { flex-wrap: wrap; }
        #bulkLookupFooter button {
            flex: 1 1 auto;
            justify-content: center;
            white-space: nowrap;
            padding-left: 10px;
            padding-right: 10px;
        }
        #bulkLookupFooter button i.material-icons { display: none; }
    }
</style>
<div id="bulkLookupModal" class="modal-overlay" style="z-index: 2500;">
    <div class="modal-content" style="width: 95%; max-width: 720px; max-height: 90vh; padding: 0; display: flex; flex-direction: column; background: white; border-radius: 12px; overflow: hidden;">
        <!-- Header: título centrado; el botón Cerrar queda fijo a la derecha (absolute). -->
        <div style="background: var(--maquinaria-dark-blue); padding: 10px 18px; display: flex; align-items: center; justify-content: center; position: relative;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="material-icons" style="font-size: 22px; color: white;">playlist_add_check</i>
                <div style="font-size: 15px; font-weight: 700; color: white;">Búsqueda Masiva</div>
            </div>
            <button type="button" onclick="closeBulkLookupModal()"
                    title="Cerrar"
                    style="position:absolute; right:14px; background:rgba(255,255,255,0.1); border:none; color:white; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.2)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                <i class="material-icons" style="font-size:18px;">close</i>
            </button>
        </div>

        <!-- Body -->
        <div style="padding: 12px 18px; overflow-y: auto; overflow-x: hidden; flex: 1;">

            <!-- Input phase -->
            <div id="bulkLookupInputPhase">
                {{-- Dropdown de Frente: cuando se elige uno, los equipos
                     que pertenezcan a OTRO frente se resaltan en amarillo
                     (siguen mostrandose, no se ocultan). $frentesDropdown
                     ya viene filtrado por permisos del usuario en el controller. --}}
                <div style="margin-bottom: 10px;">
                    {{-- Sin label: el propio dropdown ya rotula "Todos los frentes (sin filtro)". --}}
                    <div style="display: flex; gap: 8px; align-items: center;">
                    <div class="custom-dropdown" id="bulkLookupFrenteDropdown" data-default-label="Todos los frentes (sin filtro)" style="font-size: 12px;">
                        <input type="hidden" id="bulkLookupFrenteValue" data-filter-value value="">
                        <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: #fbfcfd; border: 1px solid #cbd5e0; border-radius: 8px; height: 38px;">
                            <div style="padding: 0 8px; display: flex; align-items: center; color: #94a3b8;">
                                <i class="material-icons" style="font-size: 18px;">search</i>
                            </div>
                            <input type="text" data-filter-search placeholder="Todos los frentes (sin filtro)"
                                aria-label="Filtrar frente"
                                style="width: 100%; min-width: 0; border: none; background: transparent; padding: 8px 2px; font-size: 12px; outline: none;"
                                oninput="window.filterDropdownOptions(this)" autocomplete="off">
                            <i class="material-icons" data-clear-btn style="padding: 0 6px; color: #94a3b8; font-size: 16px; display: none; cursor: pointer;"
                               onclick="event.stopPropagation(); clearDropdownFilter('bulkLookupFrenteDropdown');">close</i>
                        </div>
                        <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible; z-index: 1000;">
                            <div class="dropdown-item-list" style="max-height: 170px; overflow-y: auto;">
                                <div class="dropdown-item selected" data-value="" onclick="selectOption('bulkLookupFrenteDropdown', '', 'Todos los frentes (sin filtro)');">Todos los frentes (sin filtro)</div>
                                @foreach($frentesDropdown as $frente)
                                    <div class="dropdown-item" data-value="{{ $frente->ID_FRENTE }}" onclick="selectOption('bulkLookupFrenteDropdown', '{{ $frente->ID_FRENTE }}', '{{ addslashes($frente->NOMBRE_FRENTE) }}');">{{ $frente->NOMBRE_FRENTE }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                        <button type="button" id="bulkLookupSearchBtn" onclick="runBulkLookup()" title="Buscar"
                                style="flex-shrink:0; height:38px; padding:0 16px; background:#0067b1; color:white; border:none; border-radius:8px; display:flex; align-items:center; justify-content:center; gap:6px; cursor:pointer; font-size:13px; font-weight:700; transition:filter .2s;"
                                onmouseover="this.style.filter='brightness(1.1)'" onmouseout="this.style.filter='none'">
                            <i class="material-icons" style="font-size:18px;">search</i>
                            Buscar
                        </button>
                    </div>
                </div>

                {{-- Sin label: el placeholder del textarea ya indica qué pegar. Solo
                     queda el contador de valores únicos, alineado a la derecha. --}}
                <div style="display: flex; justify-content: flex-end; align-items: center; margin-bottom: 6px;">
                    <span id="bulkLookupCountHint" style="font-size: 11px; color: #64748b;">0 valor(es) único(s)</span>
                </div>
                <textarea id="bulkLookupTextarea"
                          placeholder="Tip: copia una columna de Excel (Ctrl+C) y pega aquí (Ctrl+V). Soporta hasta 2000 valores."
                          spellcheck="false"
                          autocomplete="off"></textarea>
            </div>

            <!-- Results phase -->
            <div id="bulkLookupResultsPhase" style="display: none;">
                <div id="bulkLookupSummary" style="display: flex; gap: 14px; margin-bottom: 8px; flex-wrap: wrap; justify-content: flex-start;"></div>

                {{-- Rótulo del frente seleccionado contra el que se comparan los equipos
                     (lo llena renderResults). Solo aparece si se eligió un frente. --}}
                <div id="bulkLookupFrenteCompare" style="display: none; align-items: center; gap: 6px; margin-bottom: 8px; padding: 5px 10px; border-radius: 8px; background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; font-size: 11.5px; font-weight: 700;"></div>

                <div style="border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background: white;">
                    <div style="max-height: 50vh; overflow-y: auto;">
                        @php
                            // Encabezados con anchos explicitos: Buscado 22%, Equipo 28%, Estado 16%, Frente 34%.
                            $thStyle = 'text-align: left; padding: 6px 10px; font-size: 11px; font-weight: 700; color: #fff; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #0f172a; border-right: 1px solid #334155;';
                            $thStyleLast = 'text-align: left; padding: 6px 10px; font-size: 11px; font-weight: 700; color: #fff; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #0f172a;';
                        @endphp
                        <table style="width: 100%; border-collapse: collapse; font-size: 12px; table-layout: fixed;">
                            <thead style="position: sticky; top: 0; background: #1e293b; z-index: 1;">
                                <tr>
                                    <th style="{{ $thStyle }} width: 22%;">Buscado</th>
                                    <th style="{{ $thStyle }} width: 28%;">Equipo</th>
                                    <th style="{{ $thStyle }} width: 16%;">Estado</th>
                                    <th style="{{ $thStyleLast }} width: 34%; text-align: center;">Frente Actual</th>
                                </tr>
                            </thead>
                            <tbody id="bulkLookupResultsBody"></tbody>
                        </table>
                    </div>
                </div>

                <div style="margin-top: 8px; font-size: 11px; color: #64748b; display: flex; flex-direction: column; gap: 3px;">
                    <div>
                        <span style="display:inline-block; width: 12px; height: 12px; background:#fef2f2; border:1px solid #fca5a5; vertical-align: middle; border-radius:2px; margin-right: 4px;"></span>
                        <span style="color:#b91c1c;">Rojo</span>: términos que no se encontraron en la base de datos.
                    </div>
                    <div id="bulkLookupYellowLegend" style="display:none;">
                        <span style="display:inline-block; width: 12px; height: 12px; background:#fef9c3; border:1px solid #fde047; vertical-align: middle; border-radius:2px; margin-right: 4px;"></span>
                        <span style="color:#854d0e;">Amarillo</span>: equipos que existen pero están en un frente diferente al seleccionado.
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer: botones alineados a la izquierda -->
        <div id="bulkLookupFooter" style="padding: 8px 18px; border-top: 1px solid #e2e8f0; background: white; display: flex; justify-content: flex-start; gap: 8px;">
            {{-- Botones uniformados con la barra flotante (.btn-bulk-action):
                 forma de píldora (radius 20px), font-size 12.5px / weight 700.
                 Mantienen su color semántico, igual que la barra (Anclar=verde,
                 Detalle=gris, Movilización=azul). --}}
            <button type="button" id="bulkLookupBackBtn" onclick="bulkLookupBack()" style="display: none; padding: 6px 14px; background: white; color: #475569; border: 1px solid #cbd5e0; border-radius: 20px; cursor: pointer; font-size: 12.5px; font-weight: 700; align-items: center; gap: 5px;">
                <i class="material-icons" style="font-size: 17px;">arrow_back</i>
                Modificar lista
            </button>
            <button type="button" id="bulkLookupCopyMissingBtn" onclick="bulkLookupCopyMissing()" style="display: none; padding: 6px 14px; background: white; color: #b91c1c; border: 1px solid #fca5a5; border-radius: 20px; cursor: pointer; font-size: 12.5px; font-weight: 700; align-items: center; gap: 5px;">
                <i class="material-icons" style="font-size: 17px;">content_copy</i>
                Copiar faltantes
            </button>
            {{-- Asignar Detalle a los encontrados: los pasa a la selección y abre el
                 modal "Asignar Detalle". Mismo gris (#64748b) que el botón "Detalle"
                 de la barra flotante. --}}
            <button type="button" id="bulkLookupDetalleBtn" onclick="window.detalleEncontrados()" style="display: none; padding: 6px 14px; background: #64748b; color: white; border: none; border-radius: 20px; cursor: pointer; font-size: 12.5px; font-weight: 700; align-items: center; gap: 5px; transition: 0.2s;" onmouseover="this.style.background='#475569'" onmouseout="this.style.background='#64748b'">
                <i class="material-icons" style="font-size: 17px;">description</i>
                Detalle
            </button>
            {{-- Movilizar TODOS los equipos encontrados de una vez: los pasa a la
                 selección y abre el modal de Movilización. Mismo azul (#0067b1) que
                 el botón "Movilización" de la barra flotante. --}}
            <button type="button" id="bulkLookupMovilizarBtn" onclick="window.movilizarEncontrados()" style="display: none; padding: 6px 14px; background: #0067b1; color: white; border: none; border-radius: 20px; cursor: pointer; font-size: 12.5px; font-weight: 700; align-items: center; gap: 5px; transition: 0.2s;" onmouseover="this.style.background='#005a9c'" onmouseout="this.style.background='#0067b1'">
                <i class="material-icons" style="font-size: 17px;">local_shipping</i>
                Movilizar <span id="bulkLookupMovilizarCount"></span>
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    const URL_BULK_LOOKUP = '{{ route('equipos.bulkLookup') }}';
    const MAX_TERMS = 2000;
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    let lastMissingTerms = [];

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getTextarea() { return document.getElementById('bulkLookupTextarea'); }
    // Devuelve el input oculto del custom-dropdown de frente (tiene .value con el ID).
    function getFrenteSelect() { return document.getElementById('bulkLookupFrenteValue'); }

    // Unica fuente de verdad de "que vamos a buscar". Splittea por CUALQUIER
    // whitespace (espacio/tab/newline) — sirve para datos pegados desde Excel
    // (que vienen con \t y \r\n), CSV (con saltos de linea), o tipeado manual
    // (separado por espacios o cada uno en su linea). El textarea elimina el
    // bug del paste anterior donde >X filas no se distribuian correctamente.
    function collectTerms() {
        const raw = (getTextarea().value || '');
        return raw.split(/\s+/)
                  .map(s => s.trim().toUpperCase())
                  .filter(v => v !== '');
    }

    function updateCountHint() {
        const hint = document.getElementById('bulkLookupCountHint');
        if (!hint) return;
        const values = collectTerms();
        const unique = new Set(values);
        const dupes = values.length - unique.size;
        let html = unique.size + ' valor(es) único(s)';
        // Avisar duplicados en rojo: el backend deduplica antes de buscar.
        if (dupes > 0) {
            html += ' <span style="color:#dc2626;font-weight:700;">(' + dupes + ' duplicado(s) — se ignoran)</span>';
        }
        hint.innerHTML = html;
    }

    function clearInputs() {
        getTextarea().value = '';
        // Resetear el custom-dropdown de frente (valor + etiqueta + estilo) vía la
        // función global, no solo el hidden input, para dejar la UI consistente.
        if (typeof window.clearDropdownFilter === 'function') {
            window.clearDropdownFilter('bulkLookupFrenteDropdown');
        } else {
            const sel = getFrenteSelect();
            if (sel) sel.value = '';
        }
        updateCountHint();
    }

    // ── ABRIR / CERRAR / VOLVER ─────────────────────────────────────────────
    function showInputPhase() {
        document.getElementById('bulkLookupInputPhase').style.display = 'block';
        document.getElementById('bulkLookupResultsPhase').style.display = 'none';
        document.getElementById('bulkLookupBackBtn').style.display = 'none';
        document.getElementById('bulkLookupCopyMissingBtn').style.display = 'none';
        var movBtn = document.getElementById('bulkLookupMovilizarBtn');
        if (movBtn) movBtn.style.display = 'none';
        var detBtn = document.getElementById('bulkLookupDetalleBtn');
        if (detBtn) detBtn.style.display = 'none';
        document.getElementById('bulkLookupSearchBtn').style.display = 'flex';
    }

    // Vuelca los equipos ENCONTRADOS en la selección global (reemplazándola: la
    // acción siempre opera sobre "éstos"). Lo reusan los botones Movilizar y Detalle
    // de Búsqueda Masiva para no duplicar el armado de la selección.
    function seleccionarEncontrados(found) {
        window.selectedEquipos = {};
        found.forEach(function (r) {
            window.selectedEquipos[r.id] = {
                id: r.id,
                code: r.codigo || '',
                placa: r.placa || '',
                chasis: r.chasis || '',
                tipo: r.tipo_nombre || '',
                frenteId: r.id_frente_actual || '',
                rolAnclaje: r.rol_anclaje || '',
                anchorId: r.anchor_id || null
            };
        });
        if (typeof window.updateSelectionUI === 'function') window.updateSelectionUI();
    }

    // Movilizar TODOS los equipos encontrados de una vez: los pasa a la selección
    // global y abre el modal de Movilización (openBulkModal) con ellos.
    window.movilizarEncontrados = function () {
        var found = window._bulkLookupFound || [];
        if (!found.length) {
            if (window.showToast) window.showToast('No hay equipos encontrados para movilizar.', 'error');
            return;
        }
        if (window.CAN_ASSIGN_EQUIPOS === false || window.CAN_ASSIGN_EQUIPOS === 'false') {
            if (window.showToast) window.showToast('No tienes permiso para movilizar equipos.', 'error');
            return;
        }
        seleccionarEncontrados(found);
        if (typeof window.closeBulkLookupModal === 'function') window.closeBulkLookupModal();
        if (typeof window.openBulkModal === 'function') window.openBulkModal();
    };

    // Asignar Detalle a los equipos encontrados: los pasa a la selección y abre el
    // modal "Asignar Detalle" (openUbicacionBulkModal), que valida permiso y "mismo
    // frente" por su cuenta.
    window.detalleEncontrados = function () {
        var found = window._bulkLookupFound || [];
        if (!found.length) {
            if (window.showToast) window.showToast('No hay equipos encontrados para asignar detalle.', 'error');
            return;
        }
        if (window.CAN_ASSIGN_EQUIPOS === false || window.CAN_ASSIGN_EQUIPOS === 'false') {
            if (window.showToast) window.showToast('No tienes permiso para actualizar detalles.', 'error');
            return;
        }
        seleccionarEncontrados(found);
        if (typeof window.closeBulkLookupModal === 'function') window.closeBulkLookupModal();
        if (typeof window.openUbicacionBulkModal === 'function') window.openUbicacionBulkModal();
    };

    window.openBulkLookupModal = function () {
        // Cierra otros popovers para no superponerlos.
        const adv = document.getElementById('advancedFilterPanel');
        if (adv) adv.style.display = 'none';
        const sm = document.getElementById('splitDropdownMenu');
        if (sm) sm.style.display = 'none';

        // Ocultar la barra flotante de selección mientras el modal esté abierto: su
        // z-index (9999) es mayor que el del modal (2500), así que se vería encima.
        const fbar = document.getElementById('bulkFloatingBar');
        if (fbar) fbar.style.display = 'none';

        showInputPhase();
        lastMissingTerms = [];
        clearInputs();

        const modal = document.getElementById('bulkLookupModal');
        modal.classList.add('active');
        setTimeout(() => { getTextarea().focus(); }, 50);
    };

    window.closeBulkLookupModal = function () {
        document.getElementById('bulkLookupModal').classList.remove('active');
        // Restaurar la barra flotante: el CSS (.active) decide si se ve según haya
        // o no selección. Si se cerró para movilizar/asignar detalle, el siguiente
        // modal queda por encima igual.
        const fbar = document.getElementById('bulkFloatingBar');
        if (fbar) fbar.style.display = '';
    };

    window.bulkLookupBack = showInputPhase;

    window.bulkLookupCopyMissing = function () {
        if (!lastMissingTerms.length) return;
        navigator.clipboard.writeText(lastMissingTerms.join('\n')).then(() => {
            if (window.showToast) window.showToast(lastMissingTerms.length + ' término(s) faltante(s) copiado(s) al portapapeles.', 'success');
        }).catch(() => {
            if (window.showToast) window.showToast('No se pudo copiar al portapapeles.', 'error');
        });
    };

    // ── RESULTADOS ──────────────────────────────────────────────────────────
    function renderResults(payload, frenteNombre) {
        const tbody = document.getElementById('bulkLookupResultsBody');
        const summary = document.getElementById('bulkLookupSummary');
        const yellowLegend = document.getElementById('bulkLookupYellowLegend');
        if (!tbody || !summary) return;

        // Rótulo "Comparando con: <frente>": indica contra qué frente se evalúa
        // el amarillo (equipos en otro frente). Solo si se filtró por un frente.
        const compareEl = document.getElementById('bulkLookupFrenteCompare');
        if (compareEl) {
            if (frenteNombre) {
                compareEl.innerHTML = '<i class="material-icons" style="font-size: 15px;">flag</i> Comparando con: ' + escapeHtml(frenteNombre);
                compareEl.style.display = 'inline-flex';
            } else {
                compareEl.style.display = 'none';
            }
        }

        const results = payload.results || [];
        const found = payload.found || 0;
        const missing = payload.missing || 0;
        const total = payload.total || 0;
        const inOther = payload.in_other_frente || 0;

        // Resumen sin contenedores de color: solo texto con el icono coloreado.
        let summaryHtml = `
            <span style="font-size: 12px; font-weight: 700; color: #334155;">Total: ${total}</span>
            <span style="font-size: 12px; font-weight: 700; color: #166534;">
                <i class="material-icons" style="font-size: 13px; vertical-align: -2px; color: #16a34a;">check_circle</i> Encontrados: ${found}
            </span>
        `;
        if (inOther > 0) {
            summaryHtml += `
                <span style="font-size: 12px; font-weight: 700; color: #854d0e;">
                    <i class="material-icons" style="font-size: 13px; vertical-align: -2px; color: #ca8a04;">warning</i> En otro frente: ${inOther}
                </span>
            `;
        }
        summaryHtml += `
            <span style="font-size: 12px; font-weight: 700; color: ${missing > 0 ? '#991b1b' : '#475569'};">
                <i class="material-icons" style="font-size: 13px; vertical-align: -2px; color: ${missing > 0 ? '#dc2626' : '#94a3b8'};">cancel</i> No encontrados: ${missing}
            </span>
        `;
        summary.innerHTML = summaryHtml;

        // La leyenda del amarillo solo aparece si hubo equipos en otro frente.
        if (yellowLegend) yellowLegend.style.display = inOther > 0 ? 'block' : 'none';

        lastMissingTerms = [];
        // 3 estilos de fila segun resultado:
        //   - rojo: no encontrado (NF)
        //   - amarillo: encontrado pero in_selected_frente === false
        //   - blanco: encontrado y en el frente (o sin filtro)
        const cellBase    = "padding: 6px 10px; border-bottom: 1px solid #f1f5f9; color: #334155; word-break: break-word;";
        const cellMissing = "padding: 6px 10px; border-bottom: 1px solid #fee2e2; color: #b91c1c; word-break: break-word;";
        const cellOther   = "padding: 6px 10px; border-bottom: 1px solid #fde68a; color: #854d0e; word-break: break-word;";

        // Badge de estado operativo (mismo lenguaje de color que el resto de la app).
        const estadoBadge = function (estado) {
            const e = (estado || 'N/A').toUpperCase();
            let bg = '#f1f5f9', col = '#475569', bd = '#cbd5e0';
            if (e === 'OPERATIVO') { bg = '#dcfce7'; col = '#166534'; bd = '#86efac'; }
            else if (e === 'INOPERATIVO') { bg = '#fee2e2'; col = '#991b1b'; bd = '#fca5a5'; }
            else if (e === 'EN MANTENIMIENTO') { bg = '#fef9c3'; col = '#854d0e'; bd = '#fde047'; }
            else if (e === 'DESINCORPORADO') { bg = '#e2e8f0'; col = '#334155'; bd = '#cbd5e0'; }
            return '<span style="display:inline-block; padding:2px 8px; border-radius:999px; font-size:10px; font-weight:700; background:' + bg + '; color:' + col + '; border:1px solid ' + bd + '; white-space:nowrap;">' + escapeHtml(e) + '</span>';
        };

        const rowsHtml = results.map(r => {
            if (!r.found) {
                lastMissingTerms.push(r.term);
                return `
                    <tr style="background: #fef2f2;">
                        <td style="${cellMissing} font-family: 'Courier New', monospace; font-weight: 700;">${escapeHtml(r.term)}</td>
                        <td colspan="3" style="${cellMissing} font-style: italic;">
                            <i class="material-icons" style="font-size: 13px; vertical-align: -2px;">error_outline</i>
                            No encontrado en la base de datos
                        </td>
                    </tr>
                `;
            }
            const equipoInfo = [r.tipo_nombre, r.marca].filter(Boolean).join(' · ') || '—';
            const frente = r.frente_nombre === 'SIN ASIGNAR'
                ? '<span style="font-style: italic;">SIN ASIGNAR</span>'
                : escapeHtml(r.frente_nombre);
            // r.in_selected_frente === false → otro frente → amarillo.
            // r.in_selected_frente === true  → mismo frente o sin filtro → blanco.
            if (r.in_selected_frente === false) {
                return `
                    <tr style="background: #fef9c3;">
                        <td style="${cellOther} font-family: 'Courier New', monospace; font-weight: 700;">${escapeHtml(r.term)}</td>
                        <td style="${cellOther}">${escapeHtml(equipoInfo)}</td>
                        <td style="${cellOther}">${estadoBadge(r.estado)}</td>
                        <td style="${cellOther} text-align: center;">${frente}</td>
                    </tr>
                `;
            }
            return `
                <tr style="background: white;">
                    <td style="${cellBase} font-family: 'Courier New', monospace; font-weight: 700;">${escapeHtml(r.term)}</td>
                    <td style="${cellBase}">${escapeHtml(equipoInfo)}</td>
                    <td style="${cellBase}">${estadoBadge(r.estado)}</td>
                    <td style="${cellBase} text-align: center;">${frente}</td>
                </tr>
            `;
        }).join('');

        tbody.innerHTML = rowsHtml || '<tr><td colspan="4" style="padding: 14px; text-align: center; color: #94a3b8;">Sin resultados</td></tr>';

        document.getElementById('bulkLookupInputPhase').style.display = 'none';
        document.getElementById('bulkLookupResultsPhase').style.display = 'block';
        document.getElementById('bulkLookupBackBtn').style.display = 'flex';
        document.getElementById('bulkLookupSearchBtn').style.display = 'none';
        document.getElementById('bulkLookupCopyMissingBtn').style.display = lastMissingTerms.length > 0 ? 'flex' : 'none';

        // Equipos ENCONTRADOS (con id) → para movilizarlos/asignarles detalle en bloque.
        window._bulkLookupFound = results.filter(function (r) { return r.found && r.id; });
        var hayEncontrados = window._bulkLookupFound.length > 0;
        var movBtn = document.getElementById('bulkLookupMovilizarBtn');
        var movCnt = document.getElementById('bulkLookupMovilizarCount');
        var detBtn = document.getElementById('bulkLookupDetalleBtn');
        if (movBtn) {
            if (hayEncontrados && movCnt) movCnt.textContent = '(' + window._bulkLookupFound.length + ')';
            movBtn.style.display = hayEncontrados ? 'flex' : 'none';
        }
        if (detBtn) detBtn.style.display = hayEncontrados ? 'flex' : 'none';
    }

    window.runBulkLookup = function () {
        const terms = collectTerms();

        if (terms.length === 0) {
            if (window.showToast) window.showToast('Agrega al menos una placa o serial.', 'warning');
            else alert('Agrega al menos una placa o serial.');
            getTextarea().focus();
            return;
        }
        if (terms.length > MAX_TERMS) {
            if (window.showToast) window.showToast('Máximo ' + MAX_TERMS + ' términos por búsqueda. Cargados: ' + terms.length, 'error');
            else alert('Máximo ' + MAX_TERMS + ' términos por búsqueda.');
            return;
        }

        const frenteIdRaw = (getFrenteSelect() && getFrenteSelect().value) || '';
        const body = { terms: terms };
        // Nombre del frente seleccionado (para el rótulo "Comparando con: ..."):
        // lo leemos del item elegido en el dropdown. '' si no se filtró por frente.
        let frenteNombre = '';
        if (frenteIdRaw) {
            body.frente_id = parseInt(frenteIdRaw, 10);
            const selItem = document.querySelector('#bulkLookupFrenteDropdown .dropdown-item[data-value="' + frenteIdRaw + '"]');
            if (selItem) frenteNombre = selItem.textContent.trim();
        }

        if (window.showPreloader) window.showPreloader();
        fetch(URL_BULK_LOOKUP, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf(),
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(body)
        })
        .then(r => r.json().then(d => ({ ok: r.ok, body: d })))
        .then(res => {
            if (window.hidePreloader) window.hidePreloader();
            if (!res.ok) {
                const msg = (res.body && res.body.message) || 'Error en la búsqueda.';
                if (window.showToast) window.showToast(msg, 'error');
                else alert(msg);
                return;
            }
            renderResults(res.body, frenteNombre);
        })
        .catch(err => {
            if (window.hidePreloader) window.hidePreloader();
            console.error('[bulkLookup]', err);
            if (window.showToast) window.showToast('Error de red en la búsqueda masiva.', 'error');
            else alert('Error de red.');
        });
    };

    // ── BIND ────────────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        const ta = getTextarea();
        if (ta) {
            // Forzar mayusculas al teclear/pegar — backend tambien hace upper.
            ta.addEventListener('input', function () {
                const pos = ta.selectionStart;
                const upper = ta.value.toUpperCase();
                if (upper !== ta.value) {
                    ta.value = upper;
                    try { ta.setSelectionRange(pos, pos); } catch (_) {}
                }
                updateCountHint();
            });
        }

        const modal = document.getElementById('bulkLookupModal');
        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === modal) closeBulkLookupModal();
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal && modal.classList.contains('active')) {
                closeBulkLookupModal();
            }
        });
    });
})();
</script>

@endsection
@section('extra_js')
    {{-- Replaced by Global Load in Layout --}}
@endsection
