@extends('layouts.estructura_base')

@section('title', 'Gestión de Equipos')

@section('content')

<style>
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
                    $hasAnyAdv = request('modelo') || request('anio') || request('marca') || request('detalle_ubicacion') || request('categoria') || request('estado') || request('gps') || request('filter_propiedad') || request('filter_poliza') || request('filter_rotc') || request('filter_racda');
                @endphp
                <button type="button" id="btnAdvancedFilter" class="btn-primary-maquinaria" style="height: 45px; width: 45px; flex-shrink: 0; min-width: 45px; padding: 0; display: flex; align-items: center; justify-content: center; background: {{ $hasAnyAdv ? '#fee2e2' : 'white' }}; border: 1px solid {{ $hasAnyAdv ? '#ef4444' : '#cbd5e0' }}; color: {{ $hasAnyAdv ? '#ef4444' : '#64748b' }}; box-shadow: none;">
                    <i class="material-icons">filter_list</i>
                </button>
                
                <!-- Dynamic Filter Panel -->
                <div id="advancedFilterPanel" style="display: none; position: absolute; top: 100%; right: 0; width: 300px; background: #e2e8f0; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15); border: 1px solid #cbd5e1; z-index: 100; margin-top: 10px; padding: 15px;">
                    <h4 style="margin: 0 0 15px 0; font-size: 14px; font-weight: 700; color: #334155; display: flex; justify-content: space-between; align-items: center;">
                        Filtros Avanzados
                        <span style="font-size: 11px; color: #64748b; font-weight: 400; text-decoration: underline;" onclick="clearAdvancedFilters()">Limpiar Todo</span>
                    </h4>

                    <!-- Modelo Filter (Rebuilt like Tipo) -->
                    <div style="margin-bottom: 15px;">
                        <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Modelo</span>
                        <div class="custom-dropdown" id="modeloAdvFilter" data-filter-type="modelo" data-default-label="Seleccionar Modelo..." style="font-size: 12px;">
                            <input type="hidden" name="modelo" data-filter-value value="{{ request('modelo') }}">
                            
                            <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: {{ request('modelo') ? '#e1effa' : 'white' }}; border: 1px solid #e2e8f0; border-radius: 6px; height: 32px;">
                                <div style="padding: 0 8px; display: flex; align-items: center; color: #94a3b8;">
                                    <i class="material-icons" style="font-size: 16px;">search</i>
                                </div>
                                <input type="text" name="filter_search_dropdown" data-filter-search 
                                    placeholder="{{ request('modelo') ?: 'Seleccionar Modelo...' }}" 
                                    aria-label="Filtrar Modelo"
                                    style="width: 100%; border: none; background: transparent; padding: 6px 5px; font-size: 12px; outline: none;"
                                    oninput="window.filterDropdownOptions(this)"
                                    autocomplete="off">
                                <i class="material-icons" data-clear-btn style="padding: 0 5px; color: #94a3b8; font-size: 16px; display: {{ request('modelo') ? 'block' : 'none' }};" 
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

                    <!-- Ubicación Filter — visible solo para frentes TIPO_FRENTE=ESPECIAL -->
                    <div id="ubicacionAdvFilterWrapper" style="margin-bottom: 15px; {{ isset($frenteEspecial) && $frenteEspecial ? '' : 'display: none;' }}">
                        <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Ubicación (Patio/Subdivisión)</span>
                        <div class="custom-dropdown" id="ubicacionAdvFilter" data-filter-type="detalle_ubicacion" data-default-label="Seleccionar Ubicación..." style="font-size: 12px;">
                            <input type="hidden" name="detalle_ubicacion" data-filter-value value="{{ request('detalle_ubicacion') }}">

                            <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: {{ request('detalle_ubicacion') ? '#e1effa' : 'white' }}; border: 1px solid #e2e8f0; border-radius: 6px; height: 32px;">
                                <div style="padding: 0 8px; display: flex; align-items: center; color: #94a3b8;">
                                    <i class="material-icons" style="font-size: 16px;">place</i>
                                </div>
                                <input type="text" name="filter_search_dropdown" data-filter-search
                                    placeholder="{{ request('detalle_ubicacion') ?: 'Seleccionar Ubicación...' }}"
                                    aria-label="Filtrar Ubicación"
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

                    <!-- Marca Filter (Rebuilt like Tipo) -->
                    <div style="margin-bottom: 15px;">
                        <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Marca</span>
                        <div class="custom-dropdown" id="marcaAdvFilter" data-filter-type="marca" data-default-label="Seleccionar Marca..." style="font-size: 12px;">
                            <input type="hidden" name="marca" data-filter-value value="{{ request('marca') }}">
                            
                            <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: {{ request('marca') ? '#e1effa' : 'white' }}; border: 1px solid #e2e8f0; border-radius: 6px; height: 32px;">
                                <div style="padding: 0 8px; display: flex; align-items: center; color: #94a3b8;">
                                    <i class="material-icons" style="font-size: 16px;">search</i>
                                </div>
                                <input type="text" name="filter_search_dropdown" data-filter-search 
                                    placeholder="{{ request('marca') ?: 'Seleccionar Marca...' }}" 
                                    aria-label="Filtrar Marca"
                                    style="width: 100%; border: none; background: transparent; padding: 6px 5px; font-size: 12px; outline: none;"
                                    oninput="window.filterDropdownOptions(this)"
                                    autocomplete="off">
                                <i class="material-icons" data-clear-btn style="padding: 0 5px; color: #94a3b8; font-size: 16px; display: {{ request('marca') ? 'block' : 'none' }};" 
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


                    <!-- Año Filter (Rebuilt like Tipo) -->
                    <div>
                        <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Año</span>
                        <div class="custom-dropdown" id="anioAdvFilter" data-filter-type="anio" data-default-label="Seleccionar Año..." style="font-size: 12px;">
                            <input type="hidden" name="anio" data-filter-value value="{{ request('anio') }}">
                            
                            <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: {{ request('anio') ? '#e1effa' : 'white' }}; border: 1px solid #e2e8f0; border-radius: 6px; height: 32px;">
                                <div style="padding: 0 8px; display: flex; align-items: center; color: #94a3b8;">
                                    <i class="material-icons" style="font-size: 16px;">search</i>
                                </div>
                                <input type="text" name="filter_search_dropdown" data-filter-search 
                                    placeholder="{{ request('anio') ?: 'Seleccionar Año...' }}" 
                                    aria-label="Filtrar Año"
                                    style="width: 100%; border: none; background: transparent; padding: 6px 5px; font-size: 12px; outline: none;"
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

                    <!-- Categoría Flota Filter -->
                    <div style="margin-top: 15px;">
                        <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Categoría Flota</span>
                        <div class="custom-dropdown" id="categoriaAdvFilter" data-filter-type="categoria" data-default-label="Seleccionar Categoría..." style="font-size: 12px;">
                            <input type="hidden" name="categoria" data-filter-value value="{{ request('categoria') }}">
                            
                            <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: {{ request('categoria') ? '#e1effa' : 'white' }}; border: 1px solid #e2e8f0; border-radius: 6px; height: 32px;">
                                <div style="padding: 0 8px; display: flex; align-items: center; color: #94a3b8;">
                                    <i class="material-icons" style="font-size: 16px;">local_shipping</i>
                                </div>
                                <input type="text" readonly
                                    id="filter_display_categoria"
                                    name="filter_display_categoria"
                                    placeholder="{{ request('categoria') ?: 'Seleccionar Categoría...' }}" 
                                    aria-label="Filtrar Categoría"
                                    style="width: 100%; border: none; background: transparent; padding: 6px 5px; font-size: 12px; outline: none;"
                                    onclick="this.closest('.custom-dropdown').classList.toggle('active')">
                                <i class="material-icons" data-clear-btn style="padding: 0 5px; color: #94a3b8; font-size: 16px; display: {{ request('categoria') ? 'block' : 'none' }};" 
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

                    <!-- Estado Operativo Filter -->
                    <div style="margin-top: 15px;">
                        <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Estado Operativo</span>
                        <div class="custom-dropdown" id="estadoAdvFilter" data-filter-type="estado" data-default-label="Seleccionar Estado..." style="font-size: 12px;">
                            <input type="hidden" name="estado" data-filter-value value="{{ request('estado') }}">
                            
                            <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: {{ request('estado') ? '#e1effa' : 'white' }}; border: 1px solid #e2e8f0; border-radius: 6px; height: 32px;">
                                <div style="padding: 0 8px; display: flex; align-items: center; color: #94a3b8;">
                                    <i class="material-icons" style="font-size: 16px;">info</i>
                                </div>
                                <input type="text" readonly
                                    id="filter_display_estado"
                                    name="filter_display_estado"
                                    placeholder="{{ request('estado') ?: 'Seleccionar Estado...' }}" 
                                    aria-label="Filtrar Estado Operativo"
                                    style="width: 100%; border: none; background: transparent; padding: 6px 5px; font-size: 12px; outline: none;"
                                    onclick="this.closest('.custom-dropdown').classList.toggle('active')">
                                <i class="material-icons" data-clear-btn style="padding: 0 5px; color: #94a3b8; font-size: 16px; display: {{ request('estado') ? 'block' : 'none' }};" 
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
                    <!-- Tiene GPS Filter -->
                    <div style="margin-top: 15px;">
                        <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Rastreo Satelital (GPS)</span>
                        <div class="custom-dropdown" id="gpsAdvFilter" data-filter-type="gps" data-default-label="Seleccionar Estatus..." style="font-size: 12px;">
                            <input type="hidden" name="gps" data-filter-value value="{{ request('gps') }}">
                            
                            <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: {{ request('gps') ? '#e1effa' : 'white' }}; border: 1px solid #e2e8f0; border-radius: 6px; height: 32px;">
                                <div style="padding: 0 8px; display: flex; align-items: center; color: #94a3b8;">
                                    <i class="material-icons" style="font-size: 16px;">gps_fixed</i>
                                </div>
                                <input type="text" readonly
                                    id="filter_display_gps"
                                    name="filter_display_gps"
                                    placeholder="{{ request('gps') === 'SI' ? 'Tienen GPS' : (request('gps') === 'NO' ? 'No Tienen GPS' : 'Seleccionar Estatus...') }}" 
                                    aria-label="Filtrar Estatus GPS"
                                    style="width: 100%; border: none; background: transparent; padding: 6px 5px; font-size: 12px; outline: none;"
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
                    <!-- Documentation Filters (New) -->
                    <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #cbd5e1;">
                        <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 8px;">Documentación Cargada</span>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                            <label style="display: flex; align-items: center; font-size: 13px; color: #334155;">
                                <input type="checkbox" id="chk_propiedad" onchange="toggleDocFilter('propiedad')" {{ request('filter_propiedad') == 'true' ? 'checked' : '' }} style="margin-right: 8px; accent-color: var(--maquinaria-blue);">
                                Propiedad
                            </label>

                            <label style="display: flex; align-items: center; font-size: 13px; color: #334155;">
                                <input type="checkbox" id="chk_poliza" onchange="toggleDocFilter('poliza')" {{ request('filter_poliza') == 'true' ? 'checked' : '' }} style="margin-right: 8px; accent-color: var(--maquinaria-blue);">
                                Póliza
                            </label>

                            <label style="display: flex; align-items: center; font-size: 13px; color: #334155;">
                                <input type="checkbox" id="chk_rotc" onchange="toggleDocFilter('rotc')" {{ request('filter_rotc') == 'true' ? 'checked' : '' }} style="margin-right: 8px; accent-color: var(--maquinaria-blue);">
                                ROTC
                            </label>

                            <label style="display: flex; align-items: center; font-size: 13px; color: #334155;">
                                <input type="checkbox" id="chk_racda" onchange="toggleDocFilter('racda')" {{ request('filter_racda') == 'true' ? 'checked' : '' }} style="margin-right: 8px; accent-color: var(--maquinaria-blue);">
                                RACDA
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
            <button type="button" id="btnAcciones" class="btn-primary-maquinaria" style="padding: 0 15px; height: 45px; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
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

                <!-- Sub-activos -->
                <button type="button" onclick="abrirModalSubActivos()" class="dropdown-item-custom" style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; transition: all 0.2s; border-bottom: 1px solid #f1f5f9; background: transparent; border: none; width: 100%; text-align: left;">
                    <div style="background: #fff7ed; padding: 6px; border-radius: 6px; display: flex;"><i class="material-icons" style="font-size: 18px; color: #f59e0b;">construction</i></div>
                    <span style="font-size: 14px; font-weight: 500;">Sub-activos</span>
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
                  || request('filter_propiedad') || request('filter_poliza')
                  || request('filter_rotc') || request('filter_racda');
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
            <div onclick="filterByStatus('INOPERATIVO')" class="eq-mobile-stat-block eq-block-inop" style="flex:1; display:flex; flex-direction:column; align-items:center; padding:8px 4px; border-radius:10px; background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.3);">
                <span style="font-size:10px; font-weight:700; color:#fca5a5; margin-bottom:2px;"><i class="material-icons" style="font-size:11px; vertical-align:middle;">cancel</i> INOP.</span>
                <span id="mobile_stats_inactivos" style="color:white; font-size:22px; font-weight:800; line-height:1;">{{ $hasFilter ? $stats['inactivos'] : '--' }}</span>
            </div>
            <div onclick="filterByStatus('EN MANTENIMIENTO')" class="eq-mobile-stat-block eq-block-mant" style="flex:1; display:flex; flex-direction:column; align-items:center; padding:8px 4px; border-radius:10px; background:rgba(245,158,11,0.15); border:1px solid rgba(245,158,11,0.3);">
                <span style="font-size:10px; font-weight:700; color:#fcd34d; margin-bottom:2px;"><i class="material-icons" style="font-size:11px; vertical-align:middle;">engineering</i> MANT.</span>
                <span id="mobile_stats_mantenimiento" style="color:white; font-size:22px; font-weight:800; line-height:1;">{{ $hasFilter ? $stats['mantenimiento'] : '--' }}</span>
            </div>
        </div>
    </div>

    <div class="custom-scrollbar-container" style="margin-top: 5px; overflow-x: auto; max-width: 100%; -webkit-overflow-scrolling: touch;">

        <table class="admin-table table-equipos-mobile" style="width: 100%; min-width: 1000px; border-collapse: separate; border-spacing: 0 10px;">
            <thead>
                <tr class="table-row-header">
                    <th class="table-header-custom" style="width: 150px;"></th> <!-- Foto Fixed -->
                    <th class="table-header-custom" style="width: 22%;">Tipo</th> <!-- Fluid (Wide) -->
                    <th class="table-header-custom" style="width: 15%;">Marca / Modelo</th> <!-- Fluid (Narrower) -->
                    <th class="table-header-custom" style="width: 25%;">Seriales / Placa / ID</th> <!-- Fluid (Wide) -->
                    <th class="table-header-custom" style="width: 110px;">Estatus</th> <!-- Fixed -->
                    <th class="table-cell-center" style="width: 50px;"></th> <!-- Fixed -->
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
                    <div onclick="filterByStatus('INOPERATIVO')" title="Filtrar: Inoperativos" style="cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(239, 68, 68, 0.15); padding: 6px 2px; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.25); transition: background 0.2s;">
                        <i class="material-icons" style="font-size: 18px; color: #ef4444; margin-bottom: 2px;">cancel</i>
                        <strong id="stats_inactivos" style="font-weight: 800; font-size: 16px; color: white;">{{ $hasFilter ? $stats['inactivos'] : '--' }}</strong>
                        <span style="font-size: 8px; letter-spacing: -0.2px; opacity: 0.9; font-weight: 700; text-transform: uppercase;">Inoperativo</span>
                    </div>
                    <div onclick="filterByStatus('EN MANTENIMIENTO')" title="Filtrar: En Mantenimiento" style="cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(245, 158, 11, 0.15); padding: 6px 2px; border-radius: 8px; border: 1px solid rgba(245, 158, 11, 0.25); transition: background 0.2s;">
                        <i class="material-icons" style="font-size: 18px; color: #f59e0b; margin-bottom: 2px;">engineering</i>
                        <strong id="stats_mantenimiento" style="font-weight: 800; font-size: 16px; color: white;">{{ $hasFilter ? $stats['mantenimiento'] : '--' }}</strong>
                        <span style="font-size: 8px; letter-spacing: -0.2px; opacity: 0.9; font-weight: 700; text-transform: uppercase;">Mantenimiento</span>
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
    <div class="selection-counter">
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
        <button type="button" onclick="openBulkModal(event)" class="btn-bulk-action">
            <i class="material-icons" style="font-size: 18px;">local_shipping</i>
            <span class="desktop-text">Asignar</span>
        </button>
    </div>
</div>

<!-- Hidden Datalist for Dynamic Modal (Autocomplete Source) -->
<datalist id="dynamicFrentesList" style="display: none;">
    @foreach($frentes as $f)
        <option value="{{ $f->NOMBRE_FRENTE }}" data-id="{{ $f->ID_FRENTE }}"></option>
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
                <button type="button" onclick="window.exportAnclajesToCsv()" title="Exportar a Excel (CSV)" style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #10b981; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 6px; border-radius: 6px; transition: all 0.2s;" onmouseover="this.style.background='rgba(16, 185, 129, 0.25)'" onmouseout="this.style.background='rgba(16, 185, 129, 0.15)'">
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

        // Fetch active front
        let fValue = '';
        const fInput = document.querySelector('input[name="id_frente"][data-filter-value]');
        if (fInput && fInput.value && fInput.value !== 'all') {
            fValue = fInput.value;
        }

        fetch('{{ route("equipos.getAnchors") }}?frente_id=' + fValue)
            .then(res => res.json())
            .then(data => {
                window.lastAnclajesData = data; // Store globally for export
                document.getElementById('anclajesLoading').style.display = 'none';
                document.getElementById('anclajesBody').style.display = 'block';
                
                const grid = document.getElementById('anclajesGrid');
                if (data.length === 0) {
                    grid.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 30px; color: #94a3b8; background: #fff; border-radius: 8px; border: 1px dashed #cbd5e1;">No hay equipos anclados en este frente.</div>';
                    return;
                }

                let html = '';
                data.forEach(pair => {
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
                grid.innerHTML = html;
            })
            .catch(err => {
                console.error('Error loading anchors:', err);
                document.getElementById('anclajesLoading').style.display = 'none';
                document.getElementById('anclajesBody').style.display = 'block';
                document.getElementById('anclajesGrid').innerHTML = '<div style="grid-column: 1/-1; text-align: center; color: #ef4444; padding: 20px;">Error al cargar anclajes.</div>';
            });
    }

    window.exportAnclajesToCsv = function() {
        const data = window.lastAnclajesData;
        if (!data || data.length === 0) {
            alert('No hay datos para exportar.');
            return;
        }

        let csvContent = 'TIPO EQUIPO 1,MARCA,IDENTIFICADOR 1,ANCLADO A (TIPO 2),IDENTIFICADOR 2\n';

        data.forEach(pair => {
            const a = pair.eq_a;
            const b = pair.eq_b;
            if (!a || !b) return;

            const aPlacaOrSerial = (a.placa && a.placa !== 'S/P') ? a.placa : (a.serial || 'N/A');
            const bPlacaOrSerial = (b.placa && b.placa !== 'S/P') ? b.placa : (b.serial || 'N/A');
            
            // Extract the core brand name (to simplify if needed, or use the full string)
            // Just handling strings to avoid CSV injection or break with commas
            const escapeCsv = (str) => '"' + (str || '').replace(/"/g, '""') + '"';

            csvContent += escapeCsv(a.tipo) + ',' +
                          escapeCsv(a.marca_modelo) + ',' +
                          escapeCsv(aPlacaOrSerial) + ',' +
                          escapeCsv(b.tipo) + ',' +
                          escapeCsv(bPlacaOrSerial) + '\n';
        });

        const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const fValueElement = document.querySelector('input[name="id_frente"][data-filter-value]');
        let fValueStr = (fValueElement && fValueElement.value && fValueElement.value !== 'all') ? ('_frente_' + fValueElement.value) : '_todos_frentes';
        
        link.href = URL.createObjectURL(blob);
        link.download = `Anclajes${fValueStr}_${new Date().toISOString().slice(0,10)}.csv`;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // Alias: CAN_CREATE_INFO → CAN_CREATE_EQUIPOS (definido globalmente en estructura_base)
    // Se mantiene por compatibilidad con equipos_index.js
    window.CAN_CREATE_INFO = window.CAN_CREATE_EQUIPOS;
    window.CAN_ASSIGN_EQUIPOS = {{ auth()->user() && (auth()->user()->can('equipos.assign') || auth()->user()->can('super.admin')) ? 'true' : 'false' }};
    window.CAN_CHANGE_STATUS = {{ auth()->user() && (auth()->user()->can('equipos.edit') || auth()->user()->can('super.admin')) ? 'true' : 'false' }};
    window.CREATE_URL = "{{ route('equipos.create') }}";
</script>

{{-- ═══════════════════════════════════════════════════════════
     MODAL SUB-ACTIVOS (Herramientas y Equipos Menores)
════════════════════════════════════════════════════════════════ --}}
<div id="modalSubActivos" class="modal-overlay" style="z-index:1100;">
    <div class="modal-content" style="max-width:850px;width:90vw;max-height:92vh;padding:0;border-radius:16px;overflow:hidden;background:#ffffff;display:flex;flex-direction:column;">
        <style>
            .sa-select-styled {
                appearance: none;
                background: #fbfcfd url('data:image/svg+xml;utf8,<svg fill="%2364748b" height="20" viewBox="0 0 24 24" width="20" xmlns="http://www.w3.org/2000/svg"><path d="M7 10l5 5 5-5z"/></svg>') no-repeat right 8px center;
                border: 1px solid #cbd5e0 !important;
                border-radius: 8px !important;
                color: #1e293b;
                transition: all 0.2s;
                outline: none;
                padding-right: 32px !important;
            }
            .sa-select-styled:focus {
                border-color: #0067b1 !important;
                background-color: #fff;
            }
        </style>

        {{-- Header --}}
        <div style="background:var(--maquinaria-dark-blue,#00004d);padding:18px 25px;color:white;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:12px;">
                <i class="material-icons" style="font-size:24px;color:#f59e0b;">construction</i>
                <div>
                    <h2 style="margin:0;font-size:18px;font-weight:700;">Sub-activos · Herramientas y Equipos Menores</h2>
                    <p style="margin:3px 0 0;font-size:12px;opacity:.75;">Máquinas de soldadura, plantas, contenedores, compresores</p>
                </div>
            </div>
            <button type="button" onclick="cerrarModalSubActivos()" style="background:rgba(255,255,255,.1);border:none;color:white;cursor:default;border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;" onmouseover="this.style.background='rgba(255,255,255,.2)'" onmouseout="this.style.background='rgba(255,255,255,.1)'">
                <i class="material-icons">close</i>
            </button>
        </div>

        {{-- Toolbar filtros --}}
        <div id="saFiltrosToolbar" style="padding:14px 20px;background:white;border-bottom:1px solid #e2e8f0;display:flex;gap:10px;flex-wrap:wrap;align-items:center;flex-shrink:0;">
            <!-- Filtro Tipo + Frente + Search en un grupo que no compite con Acciones -->
            <div style="display:flex;gap:10px;flex:1;flex-wrap:wrap;align-items:center;">
            <!-- Filtro Tipo Searchable -->
            <div class="custom-dropdown" id="saFiltroTipoDropdown" data-filter-type="tipo" data-default-label="Todos los tipos" style="font-size: 13px; width:180px;">
                <input type="hidden" id="saFiltroTipo" name="tipo" data-filter-value value="">
                
                <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: #fbfcfd; overflow: hidden; border: 1px solid #cbd5e0; border-radius: 8px; height: 38px;">
                    <div style="padding: 0 10px; display: flex; align-items: center; color: #64748b;">
                        <i class="material-icons" style="font-size: 18px;">search</i>
                    </div>
                    <input type="text" name="filter_search_dropdown" data-filter-search 
                        placeholder="Todos los tipos" 
                        aria-label="Filtrar Tipo"
                        style="width: 100%; border: none; background: transparent; padding: 6px 5px; font-size: 13px; outline: none; color: #1e293b;"
                        oninput="window.filterDropdownOptions(this)"
                        autocomplete="off">
                    <i class="material-icons" data-clear-btn style="padding: 0 8px; color: #64748b; font-size: 18px; display: none;" 
                       onclick="event.stopPropagation(); clearDropdownFilter('saFiltroTipoDropdown'); cargarSubActivos();">close</i>
                </div>

                <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible; z-index: 1000;">
                    <div class="dropdown-item-list" style="max-height: 150px; overflow-y: auto;">
                        <div class="dropdown-item selected" data-value="" onclick="selectOption('saFiltroTipoDropdown', '', 'Todos los tipos'); cargarSubActivos();">Todos los tipos</div>
                        <div class="dropdown-item" data-value="MAQUINA_SOLDADURA" onclick="selectOption('saFiltroTipoDropdown', 'MAQUINA_SOLDADURA', 'Máquinas Soldadura'); cargarSubActivos();">Máquina Soldadura</div>
                        <div class="dropdown-item" data-value="PLANTA_ELECTRICA" onclick="selectOption('saFiltroTipoDropdown', 'PLANTA_ELECTRICA', 'Plantas Eléctricas'); cargarSubActivos();">Planta Eléctrica</div>
                        <div class="dropdown-item" data-value="CONTENEDOR" onclick="selectOption('saFiltroTipoDropdown', 'CONTENEDOR', 'Contenedores'); cargarSubActivos();">Contenedor</div>
                        <div class="dropdown-item" data-value="COMPRESOR" onclick="selectOption('saFiltroTipoDropdown', 'COMPRESOR', 'Compresores'); cargarSubActivos();">Compresor</div>
                        <div class="dropdown-item" data-value="OTRO" onclick="selectOption('saFiltroTipoDropdown', 'OTRO', 'Otros'); cargarSubActivos();">Otro</div>
                    </div>
                </div>
            </div>

            <!-- Filtro Frente Searchable -->
            <div class="custom-dropdown" id="saFiltroFrenteDropdown" data-filter-type="frente" data-default-label="Todos los frentes" style="font-size: 13px; width:220px;">
                <input type="hidden" id="saFiltroFrente" name="frente" data-filter-value value="">
                
                <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: #fbfcfd; overflow: hidden; border: 1px solid #cbd5e0; border-radius: 8px; height: 38px;">
                    <div style="padding: 0 10px; display: flex; align-items: center; color: #64748b;">
                        <i class="material-icons" style="font-size: 18px;">search</i>
                    </div>
                    <input type="text" name="filter_search_dropdown" data-filter-search 
                        placeholder="Todos los frentes" 
                        aria-label="Filtrar Frente"
                        style="width: 100%; border: none; background: transparent; padding: 6px 5px; font-size: 13px; outline: none; color: #1e293b;"
                        oninput="window.filterDropdownOptions(this)"
                        autocomplete="off">
                    <i class="material-icons" data-clear-btn style="padding: 0 8px; color: #64748b; font-size: 18px; display: none;" 
                       onclick="event.stopPropagation(); clearDropdownFilter('saFiltroFrenteDropdown'); cargarSubActivos();">close</i>
                </div>

                <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible; z-index: 1000;">
                    <div class="dropdown-item-list" style="max-height: 150px; overflow-y: auto;">
                        <div class="dropdown-item selected" data-value="" onclick="selectOption('saFiltroFrenteDropdown', '', 'Todos los frentes'); cargarSubActivos();">Todos los frentes</div>
                        @foreach(\App\Models\FrenteTrabajo::orderBy('NOMBRE_FRENTE')->get() as $f)
                            <div class="dropdown-item" data-value="{{ $f->ID_FRENTE }}" onclick="selectOption('saFiltroFrenteDropdown', '{{ $f->ID_FRENTE }}', '{{ $f->NOMBRE_FRENTE }}'); cargarSubActivos();">{{ $f->NOMBRE_FRENTE }}</div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div style="position:relative; min-width:140px; max-width:200px; flex:0 1 200px;">
                <i class="material-icons" style="position:absolute;left:10px;top:10px;font-size:18px;color:#94a3b8;">search</i>
                <input id="saFiltroSearch" type="text" placeholder="Buscar serial..." oninput="cargarSubActivos()" style="height:38px;width:100%;border:1px solid #cbd5e0;border-radius:8px;padding:0 12px 0 32px;font-size:13px;color:#1e293b;outline:none;transition:all 0.2s;box-sizing:border-box;" onfocus="this.style.borderColor='#0067b1'" onblur="this.style.borderColor='#cbd5e0'">
            </div>
            </div><!-- fin grupo filtros -->
            <div style="position: relative; margin-left: auto;">
                <button type="button" id="btnAccionesSubActivos" onclick="toggleSubActivosMenu(event)" class="btn-primary-maquinaria" style="padding: 0 15px; height: 38px; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                    <i class="material-icons" style="font-size:18px;">settings</i>
                    <span style="font-size:13px; font-weight:700;">Acciones</span>
                    <i class="material-icons" style="font-size: 18px; margin-left: -2px;">expand_more</i>
                </button>

                <!-- Dropdown Menu -->
                <div id="splitDropdownMenuSubActivos" style="display: none; position: absolute; top: 100%; right: 0; width: 200px; background: #e2e8f0; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0; z-index: 50; margin-top: 5px; overflow: hidden; animation: slideDown 0.2s ease-out;">
                    <!-- Descargar Excel -->
                    <button type="button" onclick="descargarExcelSubActivos(); document.getElementById('splitDropdownMenuSubActivos').style.display='none';" class="dropdown-item-custom" style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; transition: all 0.2s; border-bottom: 1px solid #f1f5f9; background: transparent; border: none; width: 100%; text-align: left;">
                        <div style="background: #f0fdf4; padding: 6px; border-radius: 6px; display: flex;">
                            <i class="material-icons" style="font-size: 18px; color: #10b981;">download</i>
                        </div>
                        <span style="font-size: 14px; font-weight: 500;">Exportar Excel</span>
                    </button>
                    <!-- Registrar -->
                    <button type="button" onclick="mostrarFormSubActivo(); document.getElementById('splitDropdownMenuSubActivos').style.display='none';" class="dropdown-item-custom" style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; transition: all 0.2s; background: transparent; border: none; width: 100%; text-align: left;">
                        <div style="background: #eff6ff; padding: 6px; border-radius: 6px; display: flex;">
                            <i class="material-icons" style="font-size: 18px; color: #00004d;">add_circle</i>
                        </div>
                        <span style="font-size: 14px; font-weight: 500;">Registrar</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Formulario inline (oculto por defecto) --}}
        <div id="saFormPanel" style="display:none;padding:25px 40px;background:#ffffff;border-bottom:none;border-top:1px solid #e2e8f0;box-shadow:inset 0 4px 6px -4px rgba(0,0,0,0.05);flex:1;overflow-y:auto;">
            <div style="display:flex;flex-wrap:wrap;row-gap:20px;column-gap:15px;align-items:flex-start;">
                
                <div style="flex:1 1 180px;min-width:180px;">
                    <label for="saFormTipo" style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px;">TIPO *</label>
                    <select id="saFormTipo" class="sa-select-styled" style="display:block;box-sizing:border-box;width:100%;height:42px;padding:0 12px;font-size:12.5px;">
                        <option value="MAQUINA_SOLDADURA">Máquina Soldadura</option>
                        <option value="PLANTA_ELECTRICA">Planta Eléctrica</option>
                        <option value="CONTENEDOR">Contenedor</option>
                        <option value="COMPRESOR">Compresor</option>
                        <option value="OTRO">Otro</option>
                    </select>
                </div>

                <div style="flex:1 1 180px;min-width:180px;">
                    <label for="saFormMarca" style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px;">MARCA</label>
                    <input id="saFormMarca" type="text" placeholder="Ej: Lincoln" style="display:block;box-sizing:border-box;width:100%;height:42px;border:1px solid #cbd5e0 !important;border-radius:8px !important;padding:0 12px;font-size:12.5px;color:#1e293b;background:#fbfcfd;transition:all 0.2s;outline:none;" onfocus="this.style.borderColor='#0067b1';this.style.background='#fff'" onblur="this.style.borderColor='#cbd5e0';this.style.background='#fbfcfd'">
                </div>

                <div style="flex:1 1 180px;min-width:180px;">
                    <label for="saFormModelo" style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px;">MODELO</label>
                    <input id="saFormModelo" type="text" placeholder="Ej: Ranger 300D" style="display:block;box-sizing:border-box;width:100%;height:42px;border:1px solid #cbd5e0 !important;border-radius:8px !important;padding:0 12px;font-size:12.5px;color:#1e293b;background:#fbfcfd;transition:all 0.2s;outline:none;" onfocus="this.style.borderColor='#0067b1';this.style.background='#fff'" onblur="this.style.borderColor='#cbd5e0';this.style.background='#fbfcfd'">
                </div>

                <div style="flex:1 1 180px;min-width:180px;">
                    <label for="saFormCapacidad" style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px;">CAPACIDAD</label>
                    <input id="saFormCapacidad" type="text" placeholder="Ej: 300 Amp" style="display:block;box-sizing:border-box;width:100%;height:42px;border:1px solid #cbd5e0 !important;border-radius:8px !important;padding:0 12px;font-size:12.5px;color:#1e293b;background:#fbfcfd;transition:all 0.2s;outline:none;" onfocus="this.style.borderColor='#0067b1';this.style.background='#fff'" onblur="this.style.borderColor='#cbd5e0';this.style.background='#fbfcfd'">
                </div>

                <div style="flex:1 1 180px;min-width:180px;">
                    <label for="saFormAnio" style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px;">AÑO</label>
                    <input id="saFormAnio" type="number" placeholder="Ej: 2022" min="1950" max="2100" style="display:block;box-sizing:border-box;width:100%;height:42px;border:1px solid #cbd5e0 !important;border-radius:8px !important;padding:0 12px;font-size:12.5px;color:#1e293b;background:#fbfcfd;transition:all 0.2s;outline:none;" onfocus="this.style.borderColor='#0067b1';this.style.background='#fff'" onblur="this.style.borderColor='#cbd5e0';this.style.background='#fbfcfd'">
                </div>

                <div style="flex:1 1 180px;min-width:180px;">
                    <label for="saFormSerial" style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px;">SERIAL / ID *</label>
                    <input id="saFormSerial" type="text" placeholder="Ej: MS-30042-A" style="display:block;box-sizing:border-box;width:100%;height:42px;border:1px solid #cbd5e0 !important;border-radius:8px !important;padding:0 12px;font-size:12.5px;color:#1e293b;background:#fbfcfd;transition:all 0.2s;outline:none;font-family:monospace;font-weight:600;" onfocus="this.style.borderColor='#0067b1';this.style.background='#fff'" onblur="this.style.borderColor='#cbd5e0';this.style.background='#fbfcfd'">
                </div>

            </div>

            <div style="display:flex;flex-wrap:wrap;row-gap:20px;column-gap:15px;margin-top:20px;padding-top:20px;border-top:1px dashed #e2e8f0;">
                
                <div style="flex:1 1 280px;min-width:280px;">
                    <label for="saFormFrente" style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px;">FRENTE (Si está suelto)</label>
                    <select id="saFormFrente" class="sa-select-styled" style="display:block;box-sizing:border-box;width:100%;height:42px;padding:0 12px;font-size:12.5px;">
                        <option value="">— Ninguno (No asignado) —</option>
                        @foreach(\App\Models\FrenteTrabajo::orderBy('NOMBRE_FRENTE')->get() as $f)
                            <option value="{{ $f->ID_FRENTE }}">{{ $f->NOMBRE_FRENTE }}</option>
                        @endforeach
                    </select>
                </div>

                <div style="flex:1 1 280px;min-width:280px; position:relative;">
                    <label for="saFormHostSearch" style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px;">VINCULADO A (Vehículo)</label>
                    <input type="hidden" id="saFormHost" value="">
                    <input id="saFormHostSearch" type="text" placeholder="Buscar placa, motor, serial..." style="display:block;box-sizing:border-box;width:100%;height:42px;border:1px solid #cbd5e0 !important;border-radius:8px !important;padding:0 12px;font-size:12.5px;color:#1e293b;background:#fbfcfd;transition:all 0.2s;outline:none;" onfocus="this.style.borderColor='#0067b1';this.style.background='#fff'" onblur="this.style.borderColor='#cbd5e0';this.style.background='#fbfcfd'" onkeyup="if(window.saSearchTimeout) clearTimeout(window.saSearchTimeout); window.saSearchTimeout = setTimeout(() => buscarHostSA(), 500);">
                    
                    <div id="saSelectedHostCard" style="display:none; margin-top:10px; border:1px solid #10b981; border-radius:8px; padding:10px; background:#f0fdf4; align-items:center; justify-content:space-between;">
                        <div id="saSelectedHostInfo" style="font-size:12px; font-weight:700; color:#065f46; line-height: 1.3;"></div>
                        <button type="button" onclick="removerHostSA()" style="background:transparent; border:none; color:#dc2626; padding:0; height:auto;" title="Quitar vinculación"><i class="material-icons" style="font-size:18px;">close</i></button>
                    </div>

                    <div id="saHostResultados" style="display:none; position:absolute; top:100%; left:0; right:0; background:white; border:1px solid #cbd5e0; border-radius:8px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.1); z-index:500; max-height:220px; overflow-y:auto; margin-top:4px;">
                        <!-- Resultados JS -->
                    </div>
                </div>

                <div style="flex:1 1 200px;min-width:200px;">
                    <label for="saFormEstado" style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px;">ESTADO OPERATIVO *</label>
                    <select id="saFormEstado" class="sa-select-styled" style="display:block;box-sizing:border-box;width:100%;height:42px;padding:0 12px;font-size:12.5px;font-weight:700;">
                        <option value="OPERATIVO">Operativo</option>
                        <option value="INOPERATIVO">Inoperativo</option>
                        <option value="EN_ALMACEN">En Almacén</option>
                    </select>
                </div>

                <div style="flex:1 1 300px;min-width:300px;">
                    <label for="saFormObs" style="font-size:10px;font-weight:700;color:#64748b;display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.5px;">OBSERVACIONES</label>
                    <input id="saFormObs" type="text" placeholder="Escribe alguna nota adicional aquí..." style="display:block;box-sizing:border-box;width:100%;height:42px;border:1px solid #cbd5e0 !important;border-radius:8px !important;padding:0 12px;font-size:12.5px;color:#1e293b;background:#fbfcfd;transition:all 0.2s;outline:none;" onfocus="this.style.borderColor='#0067b1';this.style.background='#fff'" onblur="this.style.borderColor='#cbd5e0';this.style.background='#fbfcfd'">
                </div>
            </div>

            <div style="display:flex;gap:15px;margin-top:30px;justify-content:center;">
                <button onclick="guardarSubActivo()" style="height:44px;background:#0067b1;color:white;border:none;border-radius:8px;padding:0 30px;font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;box-shadow:0 4px 6px -1px rgba(0,103,177,0.3);"><i class="material-icons" style="font-size:20px;">save</i> Guardar</button>
                <button onclick="ocultarFormSubActivo()" style="height:44px;background:#ffffff;color:#0067b1;border:1px solid #0067b1;border-radius:8px;padding:0 25px;font-size:14px;font-weight:600;display:flex;align-items:center;gap:8px;outline:none;" tabindex="-1">Cancelar</button>
            </div>
        </div>

        {{-- Tabla --}}
        <div id="saTablaContainer" style="overflow-y:auto;flex:1;">
            <table class="admin-table" style="width:100%;">
                <thead>
                    <tr class="table-row-header">
                        <th class="table-header-custom" style="text-align: center; width:70px;"></th>
                        <th class="table-header-custom" style="text-align: center; font-size:10px;">Tipo</th>
                        <th class="table-header-custom" style="text-align: center; font-size:10px;">Marca / Modelo</th>
                        <th class="table-header-custom" style="text-align: center; font-size:10px;">Serial</th>
                        <th class="table-header-custom" style="text-align: center; font-size:10px;">Capacidad / Año</th>
                        <th class="table-header-custom" style="text-align: center; font-size:10px;">Estado</th>
                        <th class="table-header-custom" style="text-align: center; font-size:10px;">Vehículo Asociado</th>
                    </tr>
                </thead>
                <tbody id="saTableBody">
                    <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;">Cargando...</td></tr>
                </tbody>
            </table>
        </div>

        {{-- Footer contador --}}
        <div style="padding:10px 20px;background:white;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;flex-shrink:0;">
            Total: <strong id="saTotalCount">—</strong> <span id="saTotalSuffix" style="font-weight: 600; text-transform: uppercase;">sub-activos</span>
        </div>
    </div>
</div>

<script>
// ── Sub-activos Modal JS ─────────────────────────────────────────────
var SA_INDEX_URL  = "{{ route('sub-activos.index') }}";
var SA_STORE_URL  = "{{ route('sub-activos.store') }}";
var SA_COUNT_URL  = "{{ route('sub-activos.count') }}";

// Toggle JS for Acciones button
window.toggleSubActivosMenu = function(event) {
    event.stopPropagation();
    const menu = document.getElementById('splitDropdownMenuSubActivos');
    if (menu) {
        menu.style.display = menu.style.display === 'none' || menu.style.display === '' ? 'block' : 'none';
    }
};

// Guard: este listener está en un <script> INLINE que se re-ejecuta en cada SPA nav.
// Sin el guard, cada visita al módulo acumula un listener extra en document.
if (!window._subActivosClickListenerReady) {
    window._subActivosClickListenerReady = true;
    document.addEventListener('click', function(event) {
        const menu = document.getElementById('splitDropdownMenuSubActivos');
        const b = document.getElementById('btnAccionesSubActivos');
        if (menu && menu.style.display === 'block') {
            if (b && !b.contains(event.target) && !menu.contains(event.target)) {
                menu.style.display = 'none';
            }
        }
    });
}

// Iconos y colores por tipo
var SA_TIPO_CONFIG = {
    MAQUINA_SOLDADURA: { icon: 'construction',  color: '#f59e0b', bg: '#fff7ed', label: 'M. Soldadura' },
    PLANTA_ELECTRICA:  { icon: 'bolt',           color: '#eab308', bg: '#fefce8', label: 'Planta Elec.'  },
    CONTENEDOR:        { icon: 'inventory_2',    color: '#6366f1', bg: '#eef2ff', label: 'Contenedor'   },
    COMPRESOR:         { icon: 'air',            color: '#0ea5e9', bg: '#f0f9ff', label: 'Compresor'    },
    OTRO:              { icon: 'handyman',        color: '#64748b', bg: '#f1f5f9', label: 'Otro'         },
};
var SA_ESTADO_CONFIG = {
    OPERATIVO:   { color:'#16a34a', bg:'#f0fdf4', label:'Operativo'   },
    INOPERATIVO: { color:'#dc2626', bg:'#fef2f2', label:'Inoperativo' },
    EN_ALMACEN:  { color:'#64748b', bg:'#f1f5f9', label:'En Almacén'  },
};

function abrirModalSubActivos() {
    document.getElementById('splitDropdownMenu').style.display = 'none';

    // Pre-filtrar por el frente activo en la tabla principal
    const frenteInput = document.querySelector('input[name="id_frente"]');
    const frenteActivo = frenteInput ? frenteInput.value : '';
    const saFiltroFrente = document.getElementById('saFiltroFrente');
    
    if (saFiltroFrente) {
        if (frenteActivo && frenteActivo !== 'all' && frenteActivo !== '') {
            saFiltroFrente.value = frenteActivo;
        } else {
            saFiltroFrente.value = '';
        }
    }

    const m = document.getElementById('modalSubActivos');
    m.classList.add('active');
    cargarSubActivos();
}
function cerrarModalSubActivos() {
    const m = document.getElementById('modalSubActivos');
    m.classList.remove('active');
    ocultarFormSubActivo();
}
function mostrarFormSubActivo() {
    document.getElementById('saFormPanel').style.display = 'block';
    
    // Ocultar la barra de filtros y la tabla cuando el formulario está abierto
    const tb = document.getElementById('saFiltrosToolbar');
    const tc = document.getElementById('saTablaContainer');
    if(tb) tb.style.display = 'none';
    if(tc) tc.style.display = 'none';
}
function removerHostSA() {
    document.getElementById('saFormHost').value = '';
    document.getElementById('saSelectedHostCard').style.display = 'none';
    document.getElementById('saFormHostSearch').style.display = 'block';
    document.getElementById('saFormHostSearch').value = '';
    document.getElementById('saFormHostSearch').focus();
}

function buscarHostSA() {
    const search = document.getElementById('saFormHostSearch').value.trim();
    const container = document.getElementById('saHostResultados');

    if (search.length < 3) {
        container.style.display = 'none';
        container.innerHTML = '';
        return;
    }

    container.innerHTML = '<div style="padding:15px; text-align:center; color:#94a3b8; font-size:12px;">Buscando...</div>';
    container.style.display = 'block';

    fetch(`/admin/movilizaciones/buscar-equipos-recepcion?search=${encodeURIComponent(search)}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.length === 0) {
            container.innerHTML = '<div style="padding:15px; text-align:center; color:#94a3b8; font-size:12px;">No se encontraron equipos</div>';
            return;
        }

        container.innerHTML = '';
        data.forEach(eq => {
            const item = document.createElement('div');
            item.style.cssText = 'padding:10px 15px; border-bottom:1px solid #f1f5f9; cursor:pointer; display:flex; gap:12px; align-items:center; background:white;';
            item.onmouseover = () => item.style.background = '#f8fafc';
            item.onmouseout = () => item.style.background = 'white';

            const marcaMode = [eq.MARCA, eq.MODELO, eq.ANIO].filter(Boolean).join(' · ');
            const details = [eq.SERIAL_CHASIS, (eq.PLACA && eq.PLACA !== 'S/P') ? 'P: ' + eq.PLACA : null].filter(Boolean).join(' | ');

            item.innerHTML = `
                <div style="width:40px;height:40px;background:#f1f5f9;border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="material-icons" style="color:#94a3b8;">directions_car</i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:12px;font-weight:800;color:#00004d;text-transform:uppercase;">${eq.TIPO || 'EQUIPO'}</div>
                    <div style="font-size:11px;color:#475569;font-weight:600;margin-top:2px;">${marcaMode}</div>
                    <div style="font-size:10px;color:#94a3b8;margin-top:2px;">${details}</div>
                </div>
            `;

            item.onmousedown = function(e) { e.preventDefault(); }; // to prevent saFormHostSearch blur
            item.onclick = function() {
                document.getElementById('saFormHost').value = eq.ID_EQUIPO;
                document.getElementById('saFormHostSearch').value = '';
                document.getElementById('saFormHostSearch').style.display = 'none';
                document.getElementById('saSelectedHostInfo').innerHTML = `🛻 ${eq.TIPO || 'Equipo'}<br><span style="color:#64748b;font-size:10px;font-weight:400;margin-top:2px;display:inline-block;">${details}</span>`;
                document.getElementById('saSelectedHostCard').style.display = 'flex';
                container.style.display = 'none';
            };

            container.appendChild(item);
        });
    });
}

// Ocultar resultados de sugerencias si damos blur
document.getElementById('saFormHostSearch').addEventListener('blur', function() {
    setTimeout(() => {
        document.getElementById('saHostResultados').style.display = 'none';
    }, 200);
});

function ocultarFormSubActivo() {
    document.getElementById('saFormPanel').style.display = 'none';
    
    // Mostrar nuevamente la barra de filtros y la tabla al cancelar o guardar
    const tb = document.getElementById('saFiltrosToolbar');
    const tc = document.getElementById('saTablaContainer');
    if(tb) tb.style.display = 'flex';
    if(tc) tc.style.display = 'block';

    ['saFormSerial','saFormMarca','saFormModelo','saFormCapacidad','saFormAnio','saFormObs'].forEach(id => {
        const el = document.getElementById(id); if(el) el.value = '';
    });
    document.getElementById('saFormTipo').value   = 'MAQUINA_SOLDADURA';
    document.getElementById('saFormFrente').value = '';
    
    // Reset Host Selection
    removerHostSA();

    document.getElementById('saFormEstado').value = 'OPERATIVO';
}

async function cargarSubActivos() {
    const tipo   = document.getElementById('saFiltroTipo').value;
    const frente = document.getElementById('saFiltroFrente').value;
    const search = document.getElementById('saFiltroSearch').value;
    const params = new URLSearchParams();
    if (tipo)   params.append('tipo', tipo);
    if (frente) params.append('id_frente', frente);
    if (search) params.append('search', search);

    const tbody = document.getElementById('saTableBody');
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#94a3b8;">Cargando...</td></tr>';

    try {
        const res  = await fetch(SA_INDEX_URL + '?' + params.toString(), { headers:{'X-Requested-With':'XMLHttpRequest'} });
        const json = await res.json();
        if (!json.ok) throw new Error('Server error');

        window.saLastData = json.data; // Para la descarga a Excel
        document.getElementById('saTotalCount').textContent = json.total;
        
        let tipoVal = document.getElementById('saFiltroTipo').value;
        let suffix = "sub-activos";
        if(tipoVal) {
            const drp = document.getElementById('saFiltroTipoDropdown');
            const searchInput = drp ? drp.querySelector('[data-filter-search]') : null;
            if(searchInput && searchInput.value && searchInput.value !== 'Todos los tipos') {
                suffix = searchInput.value;
            }
        }
        document.getElementById('saTotalSuffix').textContent = suffix;
        
        actualizarBadge(json.total);

        if (json.data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;"><i class="material-icons" style="font-size:36px;display:block;margin-bottom:8px;">construction</i>No hay sub-activos registrados.</td></tr>';
            return;
        }

        tbody.innerHTML = json.data.map((sa, i) => {
            const tc  = SA_TIPO_CONFIG[sa.tipo]   || SA_TIPO_CONFIG.OTRO;
            const ec  = SA_ESTADO_CONFIG[sa.estado] || SA_ESTADO_CONFIG.OPERATIVO;

            const frenteBadge = sa.frente_nombre
                ? `<div style="text-align:center;font-size:11px;font-weight:700;color:#00004d;margin-bottom:4px;word-break:break-word;line-height:1.1;">${sa.frente_nombre}</div>`
                : `<div style="text-align:center;font-size:11px;font-weight:700;color:#94a3b8;margin-bottom:4px;word-break:break-word;line-height:1.1;">Sin Asignar</div>`;

            // Foto: placeholder gris estándar si no hay foto real
            const fotoCell = sa.host_foto
                ? `<div style="width:48px;height:48px;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0;margin:0 auto;flex-shrink:0;">
                       <img src="${sa.host_foto}" alt="foto" style="width:100%;height:100%;object-fit:cover;opacity:0;transition:opacity .3s" onload="this.style.opacity=1">
                   </div>`
                : `<div style="width:48px;height:48px;border-radius:10px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;border:1px solid #cbd5e0;margin:0 auto;flex-shrink:0;">
                       <i class="material-icons" style="font-size:22px;color:#94a3b8;">${tc.icon}</i>
                   </div>`;
            
            const renderFotoCell = `<div>${frenteBadge}${fotoCell}</div>`;

            const hostBadge = sa.host_id
                ? `<div style="font-size:10px;font-weight:700;color:#334155;">${sa.host_tipo || 'Vehículo'}</div>
                   <div style="font-size:10px;color:#64748b;margin-top:2px;">${(sa.host_placa && sa.host_placa !== 'S/P') ? sa.host_placa : (sa.host_serial || sa.host_codigo || '—')}</div>`
                : '<span style="color:#94a3b8;font-size:10px;font-style:italic;">Suelto</span>';

            return `<tr style="border-bottom:1px solid #f1f5f9; font-size: 11px;">
                <td style="text-align:center;padding:8px 6px;">${renderFotoCell}</td>
                <td style="text-align:center;padding:10px 8px;">${tc.label}</td>
                <td style="text-align:center;padding:10px 8px;">${sa.marca || '—'} ${sa.modelo ? '<br><span style="color:#94a3b8; font-size: 10px;">' + sa.modelo + '</span>' : ''}</td>
                <td style="text-align:center;padding:10px 8px;font-family:monospace;">${sa.serial || '—'}</td>
                <td style="text-align:center;padding:10px 8px;">${sa.capacidad || '—'} ${sa.anio ? '<br><span style="color:#94a3b8; font-size: 10px;">' + sa.anio + '</span>' : ''}</td>
                <td style="text-align:center;padding:10px 8px;">
                    <span style="background:${ec.bg};color:${ec.color};font-size:9.5px;font-weight:700;padding:3px 9px;border-radius:12px;">${ec.label}</span>
                </td>
                <td style="text-align:center;padding:10px 8px;font-size:10px;">${hostBadge}</td>
            </tr>`;
        }).join('');

    } catch(e) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#dc2626;">Error al cargar los datos.</td></tr>';

    }
}

async function guardarSubActivo() {
    // ── CSRF dinámico: siempre leer del DOM en el momento del click ──────
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // ── Validación local antes de enviar al servidor ──────────────────────
    const anioVal = document.getElementById('saFormAnio').value.trim();
    if (anioVal !== '') {
        const anioNum = parseInt(anioVal, 10);
        if (isNaN(anioNum) || anioNum < 1950 || anioNum > 2100) {
            if(window.showErrorToast) showErrorToast('El año debe estar entre 1950 y 2100');
            else alert('El año debe estar entre 1950 y 2100');
            document.getElementById('saFormAnio').focus();
            return;
        }
    }

    const tipoVal = document.getElementById('saFormTipo').value;
    if (!tipoVal) {
        if(window.showErrorToast) showErrorToast('El tipo es obligatorio');
        return;
    }
    const estadoVal = document.getElementById('saFormEstado').value;
    if (!estadoVal) {
        if(window.showErrorToast) showErrorToast('El estado es obligatorio');
        return;
    }

    const body = {
        tipo:           tipoVal,
        serial:         document.getElementById('saFormSerial').value.trim()   || null,
        marca:          document.getElementById('saFormMarca').value.trim()    || null,
        modelo:         document.getElementById('saFormModelo').value.trim()   || null,
        capacidad:      document.getElementById('saFormCapacidad').value.trim()|| null,
        anio:           anioVal !== '' ? parseInt(anioVal, 10) : null,
        ID_FRENTE:      document.getElementById('saFormFrente').value          || null,
        ID_EQUIPO_HOST: (document.getElementById('saFormHost') && document.getElementById('saFormHost').value) ? document.getElementById('saFormHost').value : null,
        estado:         estadoVal,
        observaciones:  document.getElementById('saFormObs').value.trim()      || null,
    };

    // ── Feedback visual: deshabilitar botón mientras procesa ─────────────
    const btnGuardar = document.querySelector('#saFormPanel button[onclick="guardarSubActivo()"]');
    if (btnGuardar) { btnGuardar.disabled = true; btnGuardar.innerHTML = '<i class="material-icons" style="font-size:20px;">save</i> Guardando...'; }

    try {
        const res = await fetch(SA_STORE_URL, {
            method:  'POST',
            headers: {
                'Content-Type':     'application/json',
                'X-CSRF-TOKEN':     csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept':           'application/json',
            },
            body: JSON.stringify(body),
        });

        // ── Error de validación Laravel (422) ─────────────────────────────
        if (res.status === 422) {
            const errJson = await res.json();
            let mensajeError = 'Error de validación';
            if (errJson.errors) {
                const primerCampo = Object.keys(errJson.errors)[0];
                mensajeError = errJson.errors[primerCampo][0];
            } else if (errJson.message) {
                mensajeError = errJson.message;
            }
            if(window.showErrorToast) showErrorToast(mensajeError);
            else alert('Error: ' + mensajeError);
            return;
        }

        // ── CSRF inválido (419) ───────────────────────────────────────────
        if (res.status === 419) {
            if(window.showErrorToast) showErrorToast('Sesión expirada. Recarga la página.');
            else alert('Sesión expirada. Recarga la página.');
            return;
        }

        // ── Otros errores HTTP ────────────────────────────────────────────
        if (!res.ok) {
            if(window.showErrorToast) showErrorToast('Error del servidor (' + res.status + ')');
            return;
        }

        const json = await res.json();
        if (!json.ok) {
            if(window.showErrorToast) showErrorToast('Error al guardar');
            return;
        }

        // ── Éxito ────────────────────────────────────────────────────────
        ocultarFormSubActivo();
        cargarSubActivos();
        if(window.showSuccessToast) showSuccessToast('Sub-activo registrado correctamente');

    } catch(e) {
        console.error('guardarSubActivo error:', e);
        if(window.showErrorToast) showErrorToast('Error de conexión');
    } finally {
        if (btnGuardar) { btnGuardar.disabled = false; btnGuardar.innerHTML = '<i class="material-icons" style="font-size:20px;">save</i> Guardar'; }
    }
}

async function eliminarSubActivo(id) {
    if (!confirm('¿Eliminar este sub-activo?')) return;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const res  = await fetch(`{{ url('/admin/sub-activos') }}/${id}`, {
        method:'DELETE',
        headers:{ 'X-CSRF-TOKEN':csrfToken, 'X-Requested-With':'XMLHttpRequest' },
    });
    const json = await res.json();
    if (json.ok) { cargarSubActivos(); if(window.showToast) window.showToast('Eliminado', 'success'); }
}

function actualizarBadge(total) {
    const badge = document.getElementById('badgeSubActivos');
    if (!badge) return;
    if (total > 0) { badge.textContent = total; badge.style.display = 'inline-block'; }
    else { badge.style.display = 'none'; }
}

function descargarExcelSubActivos() {
    const data = window.saLastData;
    if (!data || data.length === 0) {
        if(window.showErrorToast) window.showErrorToast('No hay datos para exportar');
        else alert('No hay datos para exportar');
        return;
    }

    let csvContent = 'TIPO,MARCA_MODELO,SERIAL,CAPACIDAD_ANIO,ESTADO,VEHICULO_O_FRENTE\n';
    
    data.forEach(sa => {
        const tcLabel = sa.tipo || 'OTRO';
        const marcaMod = `${sa.marca || ''} ${sa.modelo || ''}`.trim();
        const capAnio = `${sa.capacidad || ''} ${sa.anio || ''}`.trim();
        const hostInfo = sa.host_id ? `Anclado a ${sa.host_codigo || sa.host_placa || 'Vehi'}` : (sa.frente_nombre || 'Sin Asignar');
        
        const escapeCsv = (str) => '"' + (str || '').toString().replace(/"/g, '""') + '"';

        csvContent += escapeCsv(tcLabel) + ',' +
                      escapeCsv(marcaMod) + ',' +
                      escapeCsv(sa.serial) + ',' +
                      escapeCsv(capAnio) + ',' +
                      escapeCsv(sa.estado) + ',' +
                      escapeCsv(hostInfo) + '\n';
    });

    const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `SubActivos_${new Date().toISOString().slice(0,10)}.csv`;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Cargar el badge al iniciar: funciona en hard-refresh Y en SPA nav.
// DOMContentLoaded no dispara en SPA (ya se disparó en la carga inicial),
// por eso usamos también el evento spa:contentLoaded.
function _fetchSubActivosBadge() {
    if (!document.getElementById('badgeSubActivos')) return; // No estamos en esta página
    fetch(SA_COUNT_URL, { headers:{'X-Requested-With':'XMLHttpRequest'} })
        .then(r => r.json()).then(j => actualizarBadge(j.total)).catch(()=>{});
}
// Hard-refresh: DOMContentLoaded todavía puede disparar si el script carga antes que el DOM
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _fetchSubActivosBadge);
} else {
    _fetchSubActivosBadge();
}
// SPA nav: escuchar spa:contentLoaded (guard para no acumular listeners)
if (!window._subActivosBadgeSpaReady) {
    window._subActivosBadgeSpaReady = true;
    window.addEventListener('spa:contentLoaded', _fetchSubActivosBadge);
}

// Modal solo se cierra con botón X (no al hacer clic fuera)
</script>

{{-- Seed window.equiposData en carga inicial (hard-refresh / primera visita).
     En cargas AJAX, loadEquipos() lo rellena desde data.equiposData.
     Aquí lo hacemos para que el modal del ojo funcione sin necesitar hacer
     una búsqueda primero. --}}
@if(!empty($jsonPayload))
<script>
    window.equiposData = Object.assign(window.equiposData || {}, @json($jsonPayload));
</script>
@endif

@endsection
@section('extra_js')
    {{-- Replaced by Global Load in Layout --}}
@endsection
