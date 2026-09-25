@extends('layouts.estructura_base')

@section('title', 'Gestión de Equipos')

@section('content')

<link rel="stylesheet" href="{{ asset('css/vistas/admin_equipos_index.css') }}?v={{ @filemtime(public_path('css/vistas/admin_equipos_index.css')) }}">
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
        $isLocalUser     = $authUser && !$authUser->veTodosLosFrentesEquipos();
        $dashFrenteIds   = $authUser ? $authUser->getFrentesIds() : [];
        $hasMultiple     = count($dashFrenteIds) > 1;
        $userFrenteObj   = count($dashFrenteIds) === 1 ? $frentes->firstWhere('ID_FRENTE', $dashFrenteIds[0]) : null;
    @endphp

    <div class="filter-toolbar-container" style="margin-bottom: 5px;">

        {{-- =====================================================================
             FILTRO FRENTE: LOCAL = bloqueado | GLOBAL = dropdown con default real
             ===================================================================== --}}
        {{-- Frente crece (flex:1.5, sin tope de 300px) para absorber el espacio que
             antes quedaba muerto entre Filtros Avanzados y Acciones (a este ultimo se
             le quito el margin-left:auto que empujaba ese hueco a la derecha). --}}
        <div class="filter-item aligned-filter" style="flex: 1.5; max-width: none;">
            @php
                $currentFrenteId = request('id_frente');
                $currentFrente   = $currentFrenteId ? $frentes->firstWhere('ID_FRENTE', $currentFrenteId) : null;
                $frentesDropdown = $isLocalUser ? $frentes->whereIn('ID_FRENTE', $dashFrenteIds) : $frentes;
                $placeholderText = $currentFrente ? $currentFrente->NOMBRE_FRENTE : ($isLocalUser ? 'Todos Mis Frentes' : 'Filtrar Frente...');
            @endphp
            <div class="custom-dropdown" id="frenteFilterSelect" data-filter-type="id_frente" data-default-label="{{ $isLocalUser ? 'Todos Mis Frentes' : 'Filtrar Frente...' }}">
                <input type="hidden" name="id_frente" data-filter-value value="{{ $currentFrenteId }}" form="search-form">

                <div class="dropdown-trigger {{ $currentFrenteId && $currentFrenteId != 'all' ? 'filter-active' : '' }}" style="padding: 0; display: flex; align-items: center; background: #fbfcfd; overflow: hidden; border: 1px solid #cbd5e0; border-radius: 12px; height: 45px;">
                    <div style="padding: 0 10px; display: flex; align-items: center; color: var(--maquinaria-gray-text);">
                        <i class="material-icons" style="font-size: 18px;">search</i>
                    </div>
                    <input type="text" name="filter_search_dropdown" data-filter-search
                        placeholder="{{ $placeholderText }}"
                        aria-label="Filtrar Frente"
                        style="width: 100%; border: none; background: transparent; padding: 10px 5px; font-size: 14px; outline: none;"
                        oninput="window.filterDropdownOptions(this)"
                        autocomplete="off">
                    <i class="material-icons" data-clear-btn
                       style="padding: 0 5px; color: var(--maquinaria-gray-text); font-size: 18px; display: {{ $currentFrenteId && $currentFrenteId != 'all' ? 'block' : 'none' }};"
                       {{-- Quita SOLO el frente: si quedan otros filtros puestos la tabla se
                            refiltra por esos. loadEquipos() ya llama a eqSyncTiposFrente. --}}
                       onclick="event.stopPropagation(); clearDropdownFilter('frenteFilterSelect'); loadEquipos();">close</i>
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
                    // El valor del filtro de Tipo puede ser de VEHICULO (id numerico) o de
                    // AUXILIAR ('tipo_aux:CODIGO'). Resolvemos el label para el placeholder.
                    $reqTipo = (string) request('id_tipo', '');
                    $currentTipo = $allTipos->firstWhere('id', $reqTipo);
                    $tipoLabelActual = '';
                    if (str_starts_with($reqTipo, 'tipo_aux:')) {
                        $kAux = substr($reqTipo, 9);
                        $tipoLabelActual = $tiposAux[$kAux] ?? $kAux;
                    } elseif ($currentTipo) {
                        $tipoLabelActual = $currentTipo->nombre;
                    }
                @endphp

                <div class="dropdown-trigger {{ request('id_tipo') ? 'filter-active' : '' }}" style="padding: 0; display: flex; align-items: center; background: #fbfcfd; overflow: hidden; border: 1px solid #cbd5e0; border-radius: 12px; height: 45px;">
                    <div style="padding: 0 10px; display: flex; align-items: center; color: var(--maquinaria-gray-text);">
                        <i class="material-icons" style="font-size: 18px;">search</i>
                    </div>
                    <input type="text" name="filter_search_dropdown" data-filter-search
                        placeholder="{{ $tipoLabelActual ?: 'Filtrar Tipo...' }}"
                         aria-label="Filtrar Tipo"
                        style="width: 100%; border: none; background: transparent; padding: 10px 5px; font-size: 14px; outline: none;"
                        oninput="window.filterDropdownOptions(this)"
                        autocomplete="off">
                     <i class="material-icons" data-clear-btn
                       style="padding: 0 5px; color: var(--maquinaria-gray-text); font-size: 18px; display: {{ request('id_tipo') ? 'block' : 'none' }};"
                       onclick="event.preventDefault(); event.stopPropagation(); clearDropdownFilter('tipoFilterSelect'); loadEquipos();">close</i>
                </div>

                <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible; z-index: 1000;">
                    <div class="dropdown-item-list" style="max-height: 250px; overflow-y: auto;">
                        <div class="dropdown-item {{ !request('id_tipo') || request('id_tipo') === 'all' ? 'selected' : '' }}" data-value="all" onclick="selectOption('tipoFilterSelect', 'all', 'TODOS LOS TIPOS'); loadEquipos();">
                            TODOS LOS TIPOS
                        </div>
                        {{-- Seccion VEHICULOS (equipos principales). El separador solo se muestra
                             cuando ademas hay auxiliares, para no cambiar la vista si no los hay. --}}
                        @if(!empty($tiposAux))
                            <div style="padding:4px 8px 2px; font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; border-top:1px solid #e2e8f0; margin-top:4px;">Vehículos</div>
                        @endif
                        @foreach($allTipos as $tipo)
                            <div class="dropdown-item {{ request('id_tipo') == $tipo->id ? 'selected' : '' }}" data-value="{{ $tipo->id }}" onclick="selectOption('tipoFilterSelect', '{{ $tipo->id }}', '{{ addslashes(trim($tipo->nombre)) }}'); loadEquipos();">
                                {{ $tipo->nombre }}
                            </div>
                        @endforeach
                        {{-- Seccion AUXILIARES: valor prefijado 'tipo_aux:CODIGO'. Al elegir uno,
                             la tabla muestra los equipos auxiliares de ese tipo (modo aux). --}}
                        @if(!empty($tiposAux))
                            <div style="padding:4px 8px 2px; font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; border-top:1px solid #e2e8f0; margin-top:4px;">Auxiliares</div>
                            @foreach($tiposAux as $codAux => $labelAux)
                                <div class="dropdown-item eq-tipo-aux {{ $reqTipo === 'tipo_aux:'.$codAux ? 'selected' : '' }}" data-value="tipo_aux:{{ $codAux }}" onclick="selectOption('tipoFilterSelect', 'tipo_aux:{{ $codAux }}', '{{ addslashes($labelAux) }}'); loadEquipos();">
                                    {{ $labelAux }}
                                </div>
                            @endforeach
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Filtro Tipo dependiente del Frente: al elegir un frente, el dropdown de Tipo
             muestra SOLO los tipos presentes en ese frente (mapa provisto por el backend).
             Va inline porque la SPA re-ejecuta los <script> del contenido al navegar. --}}
        <script>
            window.EQ_TIPOS_POR_FRENTE = @json($tiposPorFrente ?? []);

            // Muestra/oculta las opciones del filtro Tipo según el frente seleccionado.
            // Frente = 'all' → todos los tipos. Frente concreto (o 'none') → solo sus tipos.
            // Si el tipo elegido deja de ser válido para el frente, se resetea a "TODOS".
            window.eqSyncTiposFrente = function () {
                var fInput = document.querySelector('#frenteFilterSelect input[name="id_frente"]');
                var frente = fInput && fInput.value ? fInput.value : 'all';
                var map = window.EQ_TIPOS_POR_FRENTE || {};
                // permitidos = null → sin restricción (mostrar todos los tipos).
                // hasOwnProperty y NO `map[frente] || []`: hay que distinguir un frente CONOCIDO
                // sin equipos (lista vacía legítima → no ofrecer tipos) de un frente que el mapa
                // NO conoce. Con `|| []` los dos caían en "ocultar TODOS los tipos" y el dropdown
                // quedaba vacío sin explicación. Pasa de verdad: eqRegisterNuevoFrente() inyecta
                // un frente recién creado en el filtro sin recargar, pero EQ_TIPOS_POR_FRENTE se
                // serializa una sola vez en el render, así que ese frente no está en el mapa.
                // Ante un frente desconocido se prefiere NO restringir antes que dejar al usuario
                // sin ningún tipo que elegir.
                var permitidos = null;
                if (frente && frente !== 'all') {
                    permitidos = Object.prototype.hasOwnProperty.call(map, frente) ? map[frente] : null;
                }

                var cont = document.querySelector('#tipoFilterSelect .dropdown-item-list');
                if (!cont) return;
                cont.querySelectorAll('.dropdown-item[data-value]').forEach(function (it) {
                    var v = it.getAttribute('data-value');
                    // "TODOS LOS TIPOS" y los tipos de AUXILIAR ('tipo_aux:*') SIEMPRE visibles:
                    // el filtro dependiente del frente solo aplica a los tipos de equipo.
                    if (v === 'all' || v.indexOf('tipo_aux:') === 0) { it.style.display = ''; it.classList.remove('eq-tipo-oculto'); return; }
                    var ok = (permitidos === null) || (permitidos.indexOf(parseInt(v, 10)) !== -1);
                    // La clase 'eq-tipo-oculto' marca los tipos que NO son del frente: el buscador
                    // interno del dropdown (filterDropdownOptions) la respeta y NO los re-muestra
                    // aunque coincidan con el texto buscado (antes los re-mostraba con !important).
                    // Igual que en eqSyncMarcaModeloTipo: solo se escribe si el estado cambia.
                    if (it.classList.contains('eq-tipo-oculto') === !ok) return;
                    it.classList.toggle('eq-tipo-oculto', !ok);
                    it.style.setProperty('display', ok ? '' : 'none', ok ? '' : 'important');
                });

                // Resetear el Tipo si el seleccionado ya no pertenece al frente.
                if (permitidos !== null) {
                    var tInput = document.querySelector('#tipoFilterSelect input[name="id_tipo"]');
                    var cur = tInput ? tInput.value : '';
                    if (cur && cur !== 'all' && cur.indexOf('tipo_aux:') !== 0
                        && permitidos.indexOf(parseInt(cur, 10)) === -1
                        && typeof selectOption === 'function') {
                        selectOption('tipoFilterSelect', 'all', 'TODOS LOS TIPOS');
                    }
                }
            };

            window.EQ_MARCAS_POR_TIPO  = @json($marcasPorTipo ?? []);
            window.EQ_MODELOS_POR_TIPO = @json($modelosPorTipo ?? []);

            // Filtros Marca y Modelo dependientes del TIPO: al elegir un tipo, sus dropdowns
            // ofrecen solo las marcas/modelos que existen en ese tipo. Mismo mecanismo que
            // eqSyncTiposFrente: se marca con 'eq-tipo-oculto', la clase que filterDropdownOptions
            // (uicomponents.js) ya respeta — así el buscador tampoco los re-muestra al escribir,
            // sin tener que tocar esa función ni inventar una segunda clase.
            window.eqSyncMarcaModeloTipo = function () {
                var tInput = document.querySelector('#tipoFilterSelect input[name="id_tipo"]');
                var tipo = tInput && tInput.value ? tInput.value : 'all';
                // Sin tipo, o en un tipo de AUXILIAR (esos dropdowns se alimentan de otra
                // fuente, $auxMarcas/$auxModelos), no se restringe nada.
                var libre = (!tipo || tipo === 'all' || tipo.indexOf('tipo_aux:') === 0);

                [['#marcaAdvFilter', window.EQ_MARCAS_POR_TIPO],
                 ['#modeloAdvFilter', window.EQ_MODELOS_POR_TIPO]].forEach(function (par) {
                    var cont = document.querySelector(par[0] + ' .dropdown-item-list');
                    if (!cont) return;
                    var map = par[1] || {};
                    // Mismo criterio que en eqSyncTiposFrente: un tipo que el mapa NO conoce
                    // no restringe (fail-open), en vez de dejar el dropdown vacío.
                    var permitidas = (!libre && Object.prototype.hasOwnProperty.call(map, tipo))
                        ? map[tipo].map(function (s) { return String(s).toUpperCase(); })
                        : null;
                    cont.querySelectorAll('.dropdown-item').forEach(function (it) {
                        var v = it.getAttribute('data-value');
                        // Las cabeceras de sección no llevan data-value; el item "limpiar"
                        // (value vacío) siempre debe poder elegirse.
                        var ok = (v === null || v === '') ? true
                               : (permitidas === null) || (permitidas.indexOf(v.trim().toUpperCase()) !== -1);
                        // Solo se escribe si el estado CAMBIA. Estos dos dropdowns suman ~290
                        // items y setProperty fuerza recalculo de estilos en cada uno: hacerlo
                        // a ciegas en cada loadEquipos() se notaba al pulsar la "x" de los
                        // filtros desde el telefono. Al limpiar, lo normal es que casi ninguno
                        // cambie, asi que el bucle sale sin tocar el DOM.
                        if (it.classList.contains('eq-tipo-oculto') === !ok) return;
                        it.classList.toggle('eq-tipo-oculto', !ok);
                        it.style.setProperty('display', ok ? '' : 'none', ok ? '' : 'important');
                    });
                });
            };

            // Init: aplica el filtrado según el frente/tipo activos al cargar la página (incluye
            // el caso de llegar por URL con ?id_frente=NN o ?id_tipo=NN ya seleccionados).
            window.eqSyncTiposFrente();
            // Diferido un tick a propósito: el panel de filtros avanzados (Marca/Modelo) se
            // pinta MÁS ABAJO en este mismo HTML, así que en este punto todavía no está en el
            // DOM y la función no encontraría sus dropdowns. El de Tipo sí existe ya (va
            // arriba), por eso la llamada de la línea anterior sí puede ser directa.
            setTimeout(window.eqSyncMarcaModeloTipo, 0);
        </script>

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
                       onclick="event.preventDefault(); event.stopPropagation(); if(window.searchTimeout) clearTimeout(window.searchTimeout); document.getElementById('searchInput').value=''; window.syncSearchHighlight && window.syncSearchHighlight(); loadEquipos();">close</i>
                </div>
            </form>

            <!-- Advanced Filter Trigger -->
            <div style="position: relative; flex-shrink: 0;">
                @php
                    $hasAnyAdv = request('modelo') || request('anio') || request('marca') || request('categoria') || request('estado') || request('gps') || request('color') || request('confirmado') || request('filter_propiedad') || request('filter_poliza') || request('filter_rotc') || request('filter_racda') || request('filter_adicional') || request('filter_adicional_2');
                @endphp
                <button type="button" id="btnAdvancedFilter" class="btn-primary-maquinaria" style="height: 45px; width: 45px; flex-shrink: 0; min-width: 45px; padding: 0; display: flex; align-items: center; justify-content: center; background: {{ $hasAnyAdv ? '#fee2e2' : 'white' }}; border: 1px solid {{ $hasAnyAdv ? '#ef4444' : '#cbd5e0' }}; color: {{ $hasAnyAdv ? '#ef4444' : '#64748b' }}; box-shadow: none;" onclick="const p = document.getElementById('advancedFilterPanel'); const s = document.getElementById('splitDropdownMenu'); if (s) s.style.display='none'; if (window.closeSharedStatusMenu) window.closeSharedStatusMenu(); document.querySelectorAll('.custom-dropdown.active').forEach(function(d){d.classList.remove('active');}); p.style.display = p.style.display === 'block' ? 'none' : 'block'; event.stopPropagation();">
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
                                        @if(!empty($availableModelos) && count($availableModelos))
                                        <div style="padding:4px 8px 2px; font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px;">Vehículos</div>
                                        @foreach($availableModelos ?? [] as $mod)
                                            @if(trim($mod) !== '')
                                                <div class="dropdown-item {{ request('modelo') == $mod ? 'selected' : '' }}" data-value="{{ $mod }}" onclick="selectOption('modeloAdvFilter', '{{ addslashes(trim($mod)) }}', '{{ addslashes(trim($mod)) }}'); loadEquipos();">{{ $mod }}</div>
                                            @endif
                                        @endforeach
                                        @endif
                                        @if(!empty($auxModelos) && count($auxModelos))
                                        <div style="padding:4px 8px 2px; font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; border-top:1px solid #e2e8f0; margin-top:4px;">Auxiliares</div>
                                        @foreach($auxModelos ?? [] as $mod)
                                            @if(trim($mod) !== '')
                                                <div class="dropdown-item {{ request('modelo') == $mod ? 'selected' : '' }}" data-value="{{ $mod }}" onclick="window._skipModoClear=true; selectOption('tipoFilterSelect','tipo_aux:all','Auxiliares'); selectOption('modeloAdvFilter', '{{ addslashes(trim($mod)) }}', '{{ addslashes(trim($mod)) }}'); loadEquipos();">{{ $mod }}</div>
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
                                        @if(!empty($availableMarcas) && count($availableMarcas))
                                        <div style="padding:4px 8px 2px; font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px;">Vehículos</div>
                                        @foreach($availableMarcas ?? [] as $marca)
                                            @if(trim($marca) !== '')
                                                <div class="dropdown-item {{ request('marca') == $marca ? 'selected' : '' }}" data-value="{{ $marca }}" onclick="selectOption('marcaAdvFilter', '{{ addslashes(trim($marca)) }}', '{{ addslashes(trim($marca)) }}'); loadEquipos();">{{ $marca }}</div>
                                            @endif
                                        @endforeach
                                        @endif
                                        @if(!empty($auxMarcas) && count($auxMarcas))
                                        <div style="padding:4px 8px 2px; font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; border-top:1px solid #e2e8f0; margin-top:4px;">Auxiliares</div>
                                        @foreach($auxMarcas ?? [] as $marca)
                                            @if(trim($marca) !== '')
                                                <div class="dropdown-item {{ request('marca') == $marca ? 'selected' : '' }}" data-value="{{ $marca }}" onclick="window._skipModoClear=true; selectOption('tipoFilterSelect','tipo_aux:all','Auxiliares'); selectOption('marcaAdvFilter', '{{ addslashes(trim($marca)) }}', '{{ addslashes(trim($marca)) }}'); loadEquipos();">{{ $marca }}</div>
                                            @endif
                                        @endforeach
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Filtro Detalle (Patio/Subdivisión): NO es un filtro avanzado visible.
                         Se activa desde el panel lateral "Detalles" (partials/ubicaciones_stats),
                         solo presente en frentes TIPO_FRENTE=ESPECIAL. El input queda aquí (dentro del
                         panel avanzado) para que loadEquipos()/export lo lean junto al resto de filtros. --}}
                    <input type="hidden" name="detalle_ubicacion" id="detalleUbicacionFilter" value="{{ request('detalle_ubicacion') }}">

                    {{-- Categoría Flota + Estado Operativo (2 columnas, lado a lado igual que Marca/Modelo). --}}
                    <div style="margin-top: 15px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <!-- Categoría Flota (FLOTA LIVIANA / FLOTA PESADA) -->
                        <div>
                            <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Categoría Flota</span>
                            <div class="custom-dropdown" id="categoriaAdvFilter" data-filter-type="categoria" data-default-label="Seleccionar Categoría..." style="font-size: 12px;">
                                <input type="hidden" name="categoria" data-filter-value value="{{ request('categoria') }}">

                                <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: {{ request('categoria') ? '#e1effa' : 'white' }}; border: 1px solid #e2e8f0; border-radius: 6px; height: 32px;">
                                    <div style="padding: 0 6px; display: flex; align-items: center; color: #94a3b8;">
                                        <i class="material-icons" style="font-size: 16px;">search</i>
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
                                        <div class="dropdown-item {{ request('categoria') == 'AUXILIARES' ? 'selected' : '' }}" data-value="AUXILIARES" onclick="selectOption('categoriaAdvFilter', 'AUXILIARES', 'AUXILIARES'); loadEquipos();">AUXILIARES</div>
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
                                        <i class="material-icons" style="font-size: 16px;">search</i>
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
                    {{-- Año + Confirmación (siempre visibles, 2 columnas).
                         GPS y Color se movieron a la fila de abajo (eq-only)
                         para que en aux-mode este par no quede deformado. --}}
                    <div style="margin-top: 15px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <!-- Año Filter -->
                        <div>
                            <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Año</span>
                            <div class="custom-dropdown" id="anioAdvFilter" data-filter-type="anio" data-default-label="Seleccionar Año..." style="font-size: 12px;">
                                <input type="hidden" name="anio" data-filter-value value="{{ request('anio') }}">

                                <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: {{ request('anio') ? '#e1effa' : 'white' }}; border: 1px solid #e2e8f0; border-radius: 6px; height: 32px;">
                                    <div style="padding: 0 8px; display: flex; align-items: center; color: #94a3b8;">
                                        <i class="material-icons" style="font-size: 16px;">search</i>
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
                                        @php
                                            $todosAnios = collect($availableAnios ?? [])->merge($auxAnios ?? [])->filter(fn($a) => trim($a) !== '')->unique()->sortDesc()->values();
                                        @endphp
                                        @foreach($todosAnios as $anio)
                                            <div class="dropdown-item {{ request('anio') == $anio ? 'selected' : '' }}" data-value="{{ $anio }}" onclick="selectOption('anioAdvFilter', '{{ addslashes(trim($anio)) }}', '{{ addslashes(trim($anio)) }}'); loadEquipos();">{{ $anio }}</div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Confirmación en sitio (fijo SI/NO — visible en eq y aux mode) -->
                        <div>
                            <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Confirmación</span>
                            <div class="custom-dropdown" id="confirmadoAdvFilter" data-filter-type="confirmado" data-default-label="Seleccionar..." style="font-size: 12px;">
                                <input type="hidden" name="confirmado" data-filter-value value="{{ request('confirmado') }}">
                                <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: {{ request('confirmado') ? '#e1effa' : 'white' }}; border: 1px solid #e2e8f0; border-radius: 6px; height: 32px;">
                                    <div style="padding: 0 8px; display: flex; align-items: center; color: #94a3b8;">
                                        <i class="material-icons" style="font-size: 16px;">search</i>
                                    </div>
                                    <input type="text" readonly
                                        id="filter_display_confirmado"
                                        name="filter_display_confirmado"
                                        placeholder="{{ request('confirmado') === 'SI' ? 'CONFIRMADOS' : (request('confirmado') === 'NO' ? 'SIN CONFIRMAR' : 'Estatus...') }}"
                                        aria-label="Filtrar Confirmación"
                                        style="width: 100%; min-width: 0; border: none; background: transparent; padding: 6px 5px; font-size: 12px; outline: none;"
                                        onclick="this.closest('.custom-dropdown').classList.toggle('active')">
                                    <i class="material-icons" data-clear-btn style="padding: 0 5px; color: #94a3b8; font-size: 16px; display: {{ request('confirmado') ? 'block' : 'none' }};"
                                       onclick="event.stopPropagation(); clearDropdownFilter('confirmadoAdvFilter'); loadEquipos();">close</i>
                                </div>
                                <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible; z-index: 1000;">
                                    <div class="dropdown-item-list">
                                        <div class="dropdown-item {{ request('confirmado') == 'SI' ? 'selected' : '' }}" data-value="SI" onclick="selectOption('confirmadoAdvFilter', 'SI', 'CONFIRMADOS'); loadEquipos();">CONFIRMADOS</div>
                                        <div class="dropdown-item {{ request('confirmado') == 'NO' ? 'selected' : '' }}" data-value="NO" onclick="selectOption('confirmadoAdvFilter', 'NO', 'SIN CONFIRMAR'); loadEquipos();">SIN CONFIRMAR</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- GPS + Color (solo equipos): fila completa con adv-filter-eq-only
                         para que desaparezca limpiamente al pasar a aux-mode. --}}
                    <div class="adv-filter-eq-only" style="margin-top: 15px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <!-- GPS Filter -->
                        <div>
                            <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">GPS</span>
                            <div class="custom-dropdown" id="gpsAdvFilter" data-filter-type="gps" data-default-label="Seleccionar Estatus..." style="font-size: 12px;">
                                <input type="hidden" name="gps" data-filter-value value="{{ request('gps') }}">

                                <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: {{ request('gps') ? '#e1effa' : 'white' }}; border: 1px solid #e2e8f0; border-radius: 6px; height: 32px;">
                                    <div style="padding: 0 8px; display: flex; align-items: center; color: #94a3b8;">
                                        <i class="material-icons" style="font-size: 16px;">search</i>
                                    </div>
                                    <input type="text" readonly
                                        id="filter_display_gps"
                                        name="filter_display_gps"
                                        placeholder="{{ request('gps') === 'SI' ? 'TIENEN GPS' : (request('gps') === 'NO' ? 'NO TIENEN GPS' : 'Estatus...') }}"
                                        aria-label="Filtrar Estatus GPS"
                                        style="width: 100%; min-width: 0; border: none; background: transparent; padding: 6px 5px; font-size: 12px; outline: none;"
                                        onclick="this.closest('.custom-dropdown').classList.toggle('active')">
                                    <i class="material-icons" data-clear-btn style="padding: 0 5px; color: #94a3b8; font-size: 16px; display: {{ request('gps') ? 'block' : 'none' }};"
                                       onclick="event.stopPropagation(); clearDropdownFilter('gpsAdvFilter'); loadEquipos();">close</i>
                                </div>

                                <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible; z-index: 1000;">
                                    <div class="dropdown-item-list">
                                        <div class="dropdown-item {{ request('gps') == 'SI' ? 'selected' : '' }}" data-value="SI" onclick="selectOption('gpsAdvFilter', 'SI', 'TIENEN GPS'); loadEquipos();">TIENEN GPS</div>
                                        <div class="dropdown-item {{ request('gps') == 'NO' ? 'selected' : '' }}" data-value="NO" onclick="selectOption('gpsAdvFilter', 'NO', 'NO TIENEN GPS'); loadEquipos();">NO TIENEN GPS</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Color Filter -->
                        <div>
                            <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Color</span>
                            <div class="custom-dropdown" id="colorAdvFilter" data-filter-type="color" data-default-label="Seleccionar Color..." style="font-size: 12px;">
                                <input type="hidden" name="color" data-filter-value value="{{ request('color') }}">
                                <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: {{ request('color') ? '#e1effa' : 'white' }}; border: 1px solid #e2e8f0; border-radius: 6px; height: 32px;">
                                    <div style="padding: 0 8px; display: flex; align-items: center; color: #94a3b8;">
                                        <i class="material-icons" style="font-size: 16px;">search</i>
                                    </div>
                                    <input type="text" name="filter_search_dropdown" data-filter-search
                                        placeholder="{{ request('color') ?: 'Color...' }}"
                                        aria-label="Filtrar Color"
                                        style="width: 100%; min-width: 0; border: none; background: transparent; padding: 6px 5px; font-size: 12px; outline: none;"
                                        oninput="window.filterDropdownOptions(this)"
                                        autocomplete="off">
                                    <i class="material-icons" data-clear-btn style="padding: 0 5px; color: #94a3b8; font-size: 16px; display: {{ request('color') ? 'block' : 'none' }};"
                                       onclick="event.stopPropagation(); clearDropdownFilter('colorAdvFilter'); loadEquipos();">close</i>
                                </div>
                                <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible; z-index: 1000;">
                                    <div class="dropdown-item-list" style="max-height: 120px; overflow-y: auto;">
                                        @if(isset($availableColores))
                                            @foreach($availableColores as $color)
                                                @if(trim($color) !== '')
                                                    <div class="dropdown-item {{ request('color') == $color ? 'selected' : '' }}" data-value="{{ $color }}" onclick="selectOption('colorAdvFilter', '{{ addslashes(trim($color)) }}', '{{ addslashes(mb_strtoupper(trim($color))) }}'); loadEquipos();">{{ mb_strtoupper($color) }}</div>
                                                @endif
                                            @endforeach
                                        @endif
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

                            <label for="chk_poliza" class="adv-filter-eq-only" style="display: flex; align-items: center; font-size: 13px; color: #334155; cursor: pointer;">
                                <input type="checkbox" id="chk_poliza" onchange="toggleDocFilter('poliza')" {{ request('filter_poliza') == 'true' ? 'checked' : '' }} style="margin-right: 8px; accent-color: var(--maquinaria-blue);">
                                Póliza
                            </label>

                            <label for="chk_rotc" class="adv-filter-eq-only" style="display: flex; align-items: center; font-size: 13px; color: #334155; cursor: pointer;">
                                <input type="checkbox" id="chk_rotc" onchange="toggleDocFilter('rotc')" {{ request('filter_rotc') == 'true' ? 'checked' : '' }} style="margin-right: 8px; accent-color: var(--maquinaria-blue);">
                                ROTC
                            </label>

                            <label for="chk_racda" class="adv-filter-eq-only" style="display: flex; align-items: center; font-size: 13px; color: #334155; cursor: pointer;">
                                <input type="checkbox" id="chk_racda" onchange="toggleDocFilter('racda')" {{ request('filter_racda') == 'true' ? 'checked' : '' }} style="margin-right: 8px; accent-color: var(--maquinaria-blue);">
                                RACDA
                            </label>

                            {{-- Certificado: doc COMPARTIDO (equipo: LINK_DOC_ADICIONAL / auxiliar:
                                 LINK_CERTIFICADO). Visible siempre, como Propiedad: filtra ambos. --}}
                            <label for="chk_adicional" style="display: flex; align-items: center; font-size: 13px; color: #334155; cursor: pointer;">
                                <input type="checkbox" id="chk_adicional" onchange="toggleDocFilter('adicional')" {{ request('filter_adicional') == 'true' ? 'checked' : '' }} style="margin-right: 8px; accent-color: var(--maquinaria-blue);">
                                Certificado
                            </label>

                            <label for="chk_adicional_2" class="adv-filter-eq-only" style="display: flex; align-items: center; font-size: 13px; color: #334155; cursor: pointer;">
                                <input type="checkbox" id="chk_adicional_2" onchange="toggleDocFilter('adicional_2')" {{ request('filter_adicional_2') == 'true' ? 'checked' : '' }} style="margin-right: 8px; accent-color: var(--maquinaria-blue);">
                                Compraventa
                            </label>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- New Button -->
        <!-- Menú "Acciones": Exportación, Nuevo Equipo, Catálogo, Dashboard de Flota, Configurar Anclajes y Eliminar -->
        <div class="filter-item aligned-filter" style="position: relative; width: auto; flex: 0 0 auto;">
            
            <!-- Main Trigger Button -->
            <button type="button" id="btnAcciones" onclick="const sm = document.getElementById('splitDropdownMenu'); const p = document.getElementById('advancedFilterPanel'); if (p) p.style.display='none'; if (window.closeSharedStatusMenu) window.closeSharedStatusMenu(); document.querySelectorAll('.custom-dropdown.active').forEach(function(d){d.classList.remove('active');}); sm.style.display = sm.style.display === 'block' ? 'none' : 'block'; event.stopPropagation();" class="btn-primary-maquinaria" style="padding: 0 15px; height: 45px; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                <i class="material-icons">settings</i>
                <span>Acciones</span>
                <i class="material-icons" style="font-size: 18px; margin-left: 2px;">expand_more</i>
            </button>

            <!-- Dropdown Menu -->
            <div id="splitDropdownMenu" style="display: none; position: absolute; top: 100%; right: 0; width: 220px; background: #e2e8f0; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0; z-index: 50; margin-top: 5px; overflow: hidden; animation: slideDown 0.2s ease-out;">
                
                <!-- Exportar -->
                <a href="#" onclick="exportEquipos(); return false;" class="dropdown-item-custom" style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; transition: all 0.2s; border-bottom: 1px solid #f1f5f9;">
                    <div style="background: #f1f5f9; padding: 6px; border-radius: 6px; display: flex;">
                        <i class="material-icons" style="font-size: 18px; color: #64748b;">download</i>
                    </div>
                    <span style="font-size: 14px; font-weight: 500;">Exportación de Data</span>
                </a>

                <!-- Nuevo -->
                <a href="javascript:void(0)" onclick="handleCreateCheck(event)" class="dropdown-item-custom" style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; transition: all 0.2s; border-bottom: 1px solid #f1f5f9;">
                    <div style="background: #e0f2fe; padding: 6px; border-radius: 6px; display: flex;">
                        <i class="material-icons" style="font-size: 18px; color: #0284c7;">add_circle</i>
                    </div>
                    <span style="font-size: 14px; font-weight: 500;">Nuevo Equipo</span>
                </a>

                <!-- Catálogo de Modelos -->
                <a href="{{ route('catalogo.index') }}" class="dropdown-item-custom" style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; transition: all 0.2s; border-bottom: 1px solid #f1f5f9;">
                    <div style="background: #f5f3ff; padding: 6px; border-radius: 6px; display: flex;">
                        <i class="material-icons" style="font-size: 18px; color: #7c3aed;">menu_book</i>
                    </div>
                    <span style="font-size: 14px; font-weight: 500;">Catálogo de Modelos</span>
                </a>

                {{-- Boton 'Equipos Auxiliares' del dropdown removido: ahora se accede
                     desde el dropdown 'Flota Operacional' del navbar, al lado de
                     'Equipos y Maquinarias'. --}}

                <!-- Dashboard de Flota -->
                <button type="button" onclick="openFleetDashboard()" class="dropdown-item-custom" style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; transition: all 0.2s; background: transparent; border: none; border-bottom: 1px solid #f1f5f9; width: 100%; text-align: left;">
                    <div style="background: #eff6ff; padding: 6px; border-radius: 6px; display: flex;">
                        <i class="material-icons" style="font-size: 18px; color: #3b82f6;">analytics</i>
                    </div>
                    <span style="font-size: 14px; font-weight: 500;">Dashboard de Flota</span>
                </button>

                <!-- Configurar Anclajes -->
                <button type="button" onclick="openAnclajesListModal()" class="dropdown-item-custom" style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; transition: all 0.2s; background: transparent; border: none; border-bottom: 1px solid #f1f5f9; width: 100%; text-align: left;">
                    <div style="background: #e0f2fe; padding: 6px; border-radius: 6px; display: flex;">
                        <i class="material-icons" style="font-size: 18px; color: #0284c7;">link</i>
                    </div>
                    <span style="font-size: 14px; font-weight: 500;">Configurar Anclajes</span>
                </button>

                {{-- Eliminar Seleccionados — SIEMPRE visible para todos los usuarios.
                     La validacion del permiso `user.delete` la hace el JS al click:
                     si el usuario NO tiene la clave literal (esta en PERMISOS_EXPLICITOS,
                     ni super.admin la hereda), aparece un modal "Acceso Denegado".
                     La ruta exige can:user.delete tambien — defensa en capas.
                     La eliminacion queda registrada en /admin/historial-documentos
                     via auditoria de soft-delete (deleted_by + deleted_at). --}}
                <button type="button" onclick="window.bulkDeleteEquiposSeleccionados()" class="dropdown-item-custom" style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; transition: all 0.2s; background: transparent; border: none; width: 100%; text-align: left;">
                    <div style="background: #fee2e2; padding: 6px; border-radius: 6px; display: flex;">
                        <i class="material-icons" style="font-size: 18px; color: #dc2626;">delete_outline</i>
                    </div>
                    <span style="font-size: 14px; font-weight: 500;">Eliminar Seleccionados</span>
                </button>
            </div>
        </div>

        <!-- Year filter hidden input moved inside the dropdown container -->

        <!-- Advanced Filter Logic migrated to equipos_index.js -->
    </div>

    {{-- $hasFilter llega del controlador (EquipoController::index, fuente única) para no
         duplicar la lista de filtros ni desincronizarse. Disponible en bloque móvil y sidebar. --}}
    @php
        // ── Consolidado: modo "Con / Sin documento" ──
        // La LISTA sigue filtrada por documento como siempre. Cuando hay filtros
        // de documento activos, SOLO el Consolidado cambia: los dos bloques
        // verde/rojo pasan a "Con [doc]" / "Sin [doc]" y el TOTAL pasa a ser el
        // universo del frente IGNORANDO el filtro de doc (doc_total), de modo que
        // con + sin = total. El JS replica esta lógica al refrescar vía AJAX.
        $docMode    = $stats['doc_mode'] ?? false;
        $docLabel   = $stats['doc_label'] ?? '';
        // Dirección activa de los bloques clicables (con/sin/all). En carga dura viene
        // del request; default 'con'. El JS la sincroniza al refrescar vía AJAX.
        $docPresence = in_array(request('doc_presence'), ['con', 'sin', 'all'], true) ? request('doc_presence') : 'con';
        $operLabel  = $docMode ? 'Con ' . $docLabel : 'Operativo';
        $inopLabel  = $docMode ? 'Sin ' . $docLabel : 'Inoperativo';
        $totalVal   = $hasFilter ? ($docMode ? ($stats['doc_total'] ?? 0) : $stats['total']) : '--';
        $operVal    = $hasFilter ? ($docMode ? ($stats['doc_con'] ?? 0) : $stats['activos']) : '--';
        $inopVal    = $hasFilter ? ($docMode ? ($stats['doc_sin'] ?? 0) : $stats['inactivos']) : '--';
    @endphp

    {{-- ── Stats compactas solo en móvil ── --}}
    <div id="equiposConsolidadoCardMobile" class="equipos-mobile-stats">

        <div class="mobstat-header" onclick="window.toggleMobileStatsCard && window.toggleMobileStatsCard('equiposConsolidadoCardMobile')" style="font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; opacity: 0.75; margin-bottom: 6px; display: flex; align-items: center; gap: 5px;">
            <i class="material-icons" style="font-size: 13px;">pie_chart</i>
            <span class="consolidado-scope">{{ ($auxMode ?? false) ? 'Equipos Auxiliares' : 'Equipos y Maquinaria' }}</span>
            <i class="material-icons mobstat-chevron" style="font-size: 16px;">expand_more</i>
        </div>
        <div class="mobstat-row" style="display: flex; gap: 8px; justify-content: space-between;">
            <div onclick="filterByStatus('')" class="eq-mobile-stat-block eq-block-total" style="flex:1; display:flex; flex-direction:column; align-items:center; padding:8px 4px; border-radius:10px; background:rgba(255,255,255,0.15); box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                <span style="font-size:10px; font-weight:700; opacity:0.8; margin-bottom:2px;">TOTAL</span>
                <span id="mobile_stats_total" style="font-size:22px; font-weight:800; line-height:1;">{{ $totalVal }}</span>
            </div>
            <div onclick="filterByStatus('OPERATIVO')" class="eq-mobile-stat-block eq-block-oper" style="flex:1; display:flex; flex-direction:column; align-items:center; padding:8px 4px; border-radius:10px; background:rgba(34,197,94,0.28); border:1px solid rgba(34,197,94,0.3);">
                <span style="font-size:10px; font-weight:700; color:#86efac; margin-bottom:2px;"><i class="material-icons" style="font-size:11px; vertical-align:middle;">check_circle</i> <span id="mobile_oper_label">{{ $docMode ? 'CON' : 'OPER.' }}</span></span>
                <span id="mobile_stats_activos" style="color:white; font-size:22px; font-weight:800; line-height:1;">{{ $operVal }}</span>
            </div>
            <div onclick="filterByStatus('INOPERATIVO')" class="eq-mobile-stat-block eq-block-inop" style="flex:1; display:flex; flex-direction:column; align-items:center; padding:8px 4px; border-radius:10px; background:rgba(239,68,68,0.28); border:1px solid rgba(239,68,68,0.3);">
                <span style="font-size:10px; font-weight:700; color:#fca5a5; margin-bottom:2px;"><i class="material-icons" style="font-size:11px; vertical-align:middle;">cancel</i> <span id="mobile_inop_label">{{ $docMode ? 'SIN' : 'INOP.' }}</span></span>
                <span id="mobile_stats_inactivos" style="color:white; font-size:22px; font-weight:800; line-height:1;">{{ $inopVal }}</span>
            </div>
        </div>
    </div>

    {{-- Consolidado de Auxiliares en móvil (en móvil el sidebar desktop se oculta).
         Mismo color teal que la card del sidebar para distinguirlo del de equipos. --}}
    @if(!empty($auxConsolidado))
    @php
        // Modo documento de la card AUX (espejo de equipos): con un doc compartido
        // (propiedad/certificado) los bloques verde/rojo pasan a "Con/Sin [doc]" y el conteo a
        // doc_con/doc_sin (TOTAL = doc_total). Calculado UNA vez aquí, usado por la card móvil
        // y la de escritorio. El JS reaplica esto en cada AJAX.
        $auxDocMode   = $auxConsolidado['doc_mode'] ?? false;
        $auxDocLabel  = $auxConsolidado['doc_label'] ?? '';
        $auxOperLabel = $auxDocMode ? 'Con ' . $auxDocLabel : 'Operativo';
        $auxInopLabel = $auxDocMode ? 'Sin ' . $auxDocLabel : 'Inoperativo';
        $auxTotalVal  = $hasFilter ? ($auxDocMode ? $auxConsolidado['doc_total'] : $auxConsolidado['total'])     : '--';
        $auxOperVal   = $hasFilter ? ($auxDocMode ? $auxConsolidado['doc_con']   : $auxConsolidado['activos'])   : '--';
        $auxInopVal   = $hasFilter ? ($auxDocMode ? $auxConsolidado['doc_sin']   : $auxConsolidado['inactivos']) : '--';
    @endphp
    <div id="auxConsolidadoCardMobile" class="equipos-mobile-stats mobstat-collapsed" style="background: linear-gradient(135deg, #64748b 0%, #475569 100%);{{ $showAuxConsolidado ? '' : ' display: none;' }}">
        <div class="mobstat-header" onclick="window.toggleMobileStatsCard && window.toggleMobileStatsCard('auxConsolidadoCardMobile')" style="font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; opacity: 0.85; margin-bottom: 6px; display: flex; align-items: center; gap: 5px;">
            <i class="material-icons" style="font-size: 13px;">pie_chart</i>
            Equipos Auxiliares
            <i class="material-icons mobstat-chevron" style="font-size: 16px;">expand_more</i>
        </div>
        <div class="mobstat-row" style="display: flex; gap: 8px; justify-content: space-between;">
            <div id="aux_mobile_block_total" onclick="filterAuxByStatus('')" style="cursor:pointer; flex:1; display:flex; flex-direction:column; align-items:center; padding:8px 4px; border-radius:10px; background:rgba(255,255,255,0.15);">
                <span style="font-size:10px; font-weight:700; opacity:0.8; margin-bottom:2px;">TOTAL</span>
                <span id="aux_mobile_stats_total" style="font-size:22px; font-weight:800; line-height:1;">{{ $auxTotalVal }}</span>
            </div>
            <div id="aux_mobile_block_oper" onclick="filterAuxByStatus('OPERATIVO')" style="cursor:pointer; flex:1; display:flex; flex-direction:column; align-items:center; padding:8px 4px; border-radius:10px; background:rgba(34,197,94,0.28); border:1px solid rgba(34,197,94,0.3);">
                <span style="font-size:10px; font-weight:700; color:#86efac; margin-bottom:2px;"><i class="material-icons" style="font-size:11px; vertical-align:middle;">check_circle</i> <span id="aux_mobile_oper_label">{{ $auxDocMode ? 'CON' : 'OPER.' }}</span></span>
                <span id="aux_mobile_stats_activos" style="color:white; font-size:22px; font-weight:800; line-height:1;">{{ $auxOperVal }}</span>
            </div>
            <div id="aux_mobile_block_inop" onclick="filterAuxByStatus('INOPERATIVO')" style="cursor:pointer; flex:1; display:flex; flex-direction:column; align-items:center; padding:8px 4px; border-radius:10px; background:rgba(239,68,68,0.28); border:1px solid rgba(239,68,68,0.3);">
                <span style="font-size:10px; font-weight:700; color:#fca5a5; margin-bottom:2px;"><i class="material-icons" style="font-size:11px; vertical-align:middle;">cancel</i> <span id="aux_mobile_inop_label">{{ $auxDocMode ? 'SIN' : 'INOP.' }}</span></span>
                <span id="aux_mobile_stats_inactivos" style="color:white; font-size:22px; font-weight:800; line-height:1;">{{ $auxInopVal }}</span>
            </div>
        </div>
    </div>
    @endif

    <div class="custom-scrollbar-container" style="margin-top: 5px; overflow-x: auto; max-width: 100%; -webkit-overflow-scrolling: touch;">

        <table class="admin-table table-equipos-mobile" style="width: 100%; min-width: 900px; border-collapse: separate; border-spacing: 0 8px;">
            <thead>
                <tr class="table-row-header">
                    <th class="table-header-custom" style="width: 150px;"></th> {{-- Foto + Frente --}}
                    <th class="table-header-custom" style="width: 24%;">TIPO</th>
                    <th class="table-header-custom" style="width: 18%;">MARCA / MODELO</th>
                    <th class="table-header-custom" style="width: 23%;">SERIALES / PLACA</th>
                    <th class="table-header-custom" style="width: 145px;">ESTATUS</th>
                    <th class="table-cell-center" style="width: 44px;"></th> {{-- Acciones --}}
                </tr>
            </thead>
            <tbody id="equiposTableBody" style="font-size: 15px;">
                {{-- Modo aux (tipo auxiliar elegido): la tabla muestra las filas de
                     auxiliares (mismo partial que /admin/equipos-auxiliares). En carga
                     inicial por URL ya vienen renderizadas; la navegacion AJAX las
                     intercambia via loadEquipos (data.mode === 'aux'). --}}
                @if($auxMode ?? false)
                    {!! ($auxEmbed['html'] ?? '') !!}
                @else
                    {{-- Merge: filas de equipos + (al filtrar por frente) separador + filas
                         de auxiliares. Si el frente no tiene equipos pero sí auxiliares,
                         se omite el empty-state de equipos. --}}
                    @unless($equipos->isEmpty() && !empty($mergeAuxHtml ?? ''))
                        @include('admin.equipos.partials.table_rows')
                    @endunless
                    {!! $mergeAuxHtml ?? '' !!}
                @endif
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

    <div style="background: linear-gradient(135deg, #001a52 0%, #0a4a91 100%); border-radius: 12px; padding: 8px 12px; color: white; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); position: relative; overflow: hidden;">
        <!-- Decorative Icon -->
        <i class="material-icons" style="position: absolute; right: -15px; bottom: -15px; font-size: 72px; opacity: 0.1; transform: rotate(-15deg);">agriculture</i>

        <div style="position: relative; z-index: 2;">
            <div style="font-size: 12.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; opacity: 0.8; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                <span class="consolidado-scope">{{ ($auxMode ?? false) ? 'Equipos Auxiliares' : 'Equipos y Maquinaria' }}</span>
            </div>

            {{-- 3 columnas iguales en UNA sola línea, sin iconos (el color del bloque
                 ya indica el estado). Mismo tamaño de número en los tres. --}}
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px;">
                <div id="block_total" onclick="filterByStatus('')" title="Ver todos los equipos" style="cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(255,255,255,0.15); padding: 6px 4px; border-radius: 8px;">
                    <span id="stats_total" style="font-size: 20px; font-weight: 800; line-height: 1;">{{ $totalVal }}</span>
                    <span class="consolidado-stat-label" style="margin-top: 4px; opacity: 0.8;">TOTAL</span>
                </div>
                <div id="block_oper" onclick="filterByStatus('OPERATIVO')" title="{{ $docMode ? 'Ver solo los que tienen ' . $docLabel : 'Filtrar: Operativos' }}" class="eq-block-oper" style="cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(34, 197, 94, 0.28); padding: 6px 4px; border-radius: 8px; border: 1px solid rgba(34, 197, 94, 0.25); transition: background 0.2s;">
                    <strong id="stats_activos" style="font-weight: 800; font-size: 20px; color: white; line-height: 1;">{{ $operVal }}</strong>
                    <span id="stats_oper_label" class="consolidado-stat-label{{ $docMode ? ' is-doc' : '' }}" style="margin-top: 4px;">{{ $operLabel }}</span>
                </div>
                <div id="block_inop" onclick="filterByStatus('INOPERATIVO')" title="{{ $docMode ? 'Ver solo los que NO tienen ' . $docLabel : 'Filtrar: Inoperativos' }}" class="eq-block-inop" style="cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(239, 68, 68, 0.28); padding: 6px 4px; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.25); transition: background 0.2s;">
                    <strong id="stats_inactivos" style="font-weight: 800; font-size: 20px; color: white; line-height: 1;">{{ $inopVal }}</strong>
                    <span id="stats_inop_label" class="consolidado-stat-label{{ $docMode ? ' is-doc' : '' }}" style="margin-top: 4px;">{{ $inopLabel }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Consolidado de Equipos Auxiliares — refleja el filtro de FRENTE. Se muestra
         junto al de equipos cuando NO se filtra por un tipo concreto (ver
         $showAuxConsolidado). Permanece en el DOM (toggle por JS al filtrar). --}}
    @if(!empty($auxConsolidado))
    {{-- $auxDocMode/$auxOperLabel/$auxInopLabel/$auxTotalVal/$auxOperVal/$auxInopVal se calcularon
         arriba (card móvil), reutilizados aquí para la card de escritorio. --}}
    <div id="auxConsolidadoCard" style="background: linear-gradient(135deg, #64748b 0%, #475569 100%); border-radius: 12px; padding: 8px 12px; color: white; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); position: relative; overflow: hidden;{{ $showAuxConsolidado ? '' : ' display: none;' }}">
        <i class="material-icons" style="position: absolute; right: -15px; bottom: -15px; font-size: 72px; opacity: 0.1; transform: rotate(-15deg);">construction</i>
        <div style="position: relative; z-index: 2;">
            <div style="font-size: 12.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; opacity: 0.85; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                <span>Equipos Auxiliares</span>
            </div>
            {{-- 3 columnas iguales en UNA sola línea, sin iconos (mismo patrón que el
                 consolidado de equipos para que ambos se vean idénticos). En modo documento los
                 bloques verde/rojo filtran por PRESENCIA (Con/Sin) en vez de por estado. --}}
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px;">
                <div id="aux_block_total" onclick="filterAuxByStatus('')" title="Ver todos los auxiliares" style="cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(255,255,255,0.15); padding: 6px 4px; border-radius: 8px; transition: background 0.2s;">
                    <span id="aux_stats_total" style="font-size: 20px; font-weight: 800; line-height: 1;">{{ $auxTotalVal }}</span>
                    <span class="consolidado-stat-label" style="margin-top: 4px; opacity: 0.8;">TOTAL</span>
                </div>
                <div id="aux_block_oper" onclick="filterAuxByStatus('OPERATIVO')" title="Filtrar auxiliares" style="cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(34, 197, 94, 0.28); padding: 6px 4px; border-radius: 8px; border: 1px solid rgba(34, 197, 94, 0.25); transition: background 0.2s;">
                    <strong id="aux_stats_activos" style="font-weight: 800; font-size: 20px; color: white; line-height: 1;">{{ $auxOperVal }}</strong>
                    <span id="aux_oper_label" class="consolidado-stat-label{{ $auxDocMode ? ' is-doc' : '' }}" style="margin-top: 4px;">{{ $auxOperLabel }}</span>
                </div>
                <div id="aux_block_inop" onclick="filterAuxByStatus('INOPERATIVO')" title="Filtrar auxiliares" style="cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(239, 68, 68, 0.28); padding: 6px 4px; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.25); transition: background 0.2s;">
                    <strong id="aux_stats_inactivos" style="font-weight: 800; font-size: 20px; color: white; line-height: 1;">{{ $auxInopVal }}</strong>
                    <span id="aux_inop_label" class="consolidado-stat-label{{ $auxDocMode ? ' is-doc' : '' }}" style="margin-top: 4px;">{{ $auxInopLabel }}</span>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Ubicaciones (DETALLE_UBICACION_ACTUAL) — solo frentes TIPO_FRENTE=ESPECIAL. Ya NO es
         una card aparte: su HTML se captura aquí (fuente oculta) y se apila como una SECCIÓN más
         ("Detalles") dentro de la lista unificada de la card de Distribución. --}}
    <div id="ubicacionesStatsSource" style="display: none;">
        @if(isset($frenteEspecial) && $frenteEspecial && !($auxMode ?? false))
            @include('admin.equipos.partials.ubicaciones_stats')
        @endif
    </div>

    <!-- Breakdown by Type or Front (Dynamic) -->
    {{-- La card muestra TODAS las secciones disponibles (Equipos y Maquinaria, Detalles,
         Auxiliares) apiladas en UNA sola lista. El CLIC fuera de una fila (onDistribucionCardClick)
         hace SCROLL a la siguiente sección (no intercambia contenido); los clics sobre una fila
         (li) conservan su acción de filtrar. La sección Auxiliares solo se agrega cuando hay
         distribución de auxiliares (con filtro — ver $auxDistributionHtml). --}}
    {{-- Ancla del sitio de ESCRITORIO de la card de abajo: en teléfono la card se muda al
         Dashboard de Flota y hay que saber dónde devolverla al volver a pantalla ancha.
         Se usa un ancla y no "el último hijo del sidebar" para que no se rompa si algún día
         se agrega otra card debajo. Ver colocarDistribucionMovil() en equipos_index.js. --}}
    <div id="eqDistribHome" style="display: none;"></div>
    <div id="distribucionCard" onclick="onDistribucionCardClick(event)" style="background: white; border-radius: 12px; padding: 15px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden;">
        <div id="distributionStatsContainer">
            {{-- Modo aux: usamos el HTML ya renderizado por buildEmbedPayload (mismo que
                 el AJAX via data.distribution) → así el render inicial también respeta la
                 vista por-frente cuando hay un tipo aux sin frente. En modo equipos, la
                 distribucion normal por tipo/frente. --}}
            @if($auxMode ?? false)
                {!! $auxEmbed['distribucionHtml'] ?? '' !!}
            @else
                @include('admin.equipos.partials.distribution_stats')
            @endif
        </div>
    </div>
    <script>
        // HTML de la sección "Auxiliares" de la lista unificada de Distribución (vacío si no aplica).
        window.__distribAuxHtml = @json($auxDistributionHtml ?? '');
        // HTML de la sección "Detalles" (DETALLE_UBICACION_ACTUAL) de la lista unificada,
        // capturado de la fuente oculta (vacío si el frente no es especial).
        (function () {
            var src = document.getElementById('ubicacionesStatsSource');
            window.__distribUbiHtml = (src && src.innerHTML.trim()) ? src.innerHTML : '';
        })();
        if (typeof window.eqSyncDistribToggle === 'function') window.eqSyncDistribToggle();
    </script>
</div>

</div> <!-- End Page Layout Grid -->






<!-- Floating Action Bar -->
{{-- eq-hide-in-aux: en modo aux se oculta la barra de seleccion de EQUIPOS (la
     seleccion se conserva, solo no se muestra) para que no se solape con la barra
     de seleccion de AUXILIARES (auxBulkBar). --}}
<div id="bulkFloatingBar" class="selection-floating-bar eq-hide-in-aux">
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
    <link rel="stylesheet" href="{{ asset('css/vistas/admin_equipos_index_2.css') }}?v={{ @filemtime(public_path('css/vistas/admin_equipos_index_2.css')) }}">
    
    @php
    $dashUser       = auth()->user();
    $dashIsLocal    = $dashUser && !$dashUser->veTodosLosFrentesEquipos();
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

    <div id="fleetDashboardModal" class="modal-overlay">
        {{-- CUERPO blanco (antes #f8fafc); la cabecera va azul aparte (.fleet-dashboard-header).
             Los paneles internos se distinguen por su borde #e2e8f0, no por el contraste
             del fondo. --}}
        <div class="modal-content" style="width: 94%; max-width: 880px; height: 90vh; padding: 0; display: flex; flex-direction: column; background: #fff; position: relative; border-radius: 18px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.35);">
            <!-- Header -->
            <div class="fleet-dashboard-header">
                <div class="fleet-header-wrapper">
                    <!-- Left Group -->
                    <div class="fleet-header-left">
                        <!-- Icon + Title -->
                        <div class="fleet-header-title-group">
                            <div style="background: rgba(255,255,255,0.16); padding: 7px; border-radius: 9px; display: flex;">
                                <i class="material-icons" style="font-size: 21px; color: #fff;">analytics</i>
                            </div>
                            {{-- Con clase propia: en telefono se oculta este bloque entero
                                 (ver .fleet-header-title-text en el @media de mas abajo). --}}
                            <div class="fleet-header-title-text">
                                <h2 style="margin: 0; color: #fff; font-size: 17px; font-weight: 700; white-space: nowrap;">Dashboard de Flota</h2>
                            </div>
                        </div>
                        
                        <div class="fleet-filter-container">
                            {{-- LOCAL y GLOBAL usan el mismo dropdown, la variable $frentesDropdown ya viene filtrada del Controller --}}
                            <input type="hidden" id="dashboardSelectedFrenteId" value="{{ $defaultDashboardId }}">
                            <input type="hidden" id="dashboardSelectedFrenteNombre" value="{{ $defaultDashboardNombre }}">
                            <div class="custom-dropdown" id="dashboardFrenteDropdown" style="width: 100%;">
                            {{-- Fondo blanco y BORDE: nació en la cabecera azul (donde bastaba el
                                 contraste), pasó al cuerpo blanco (donde hizo falta el borde) y ha
                                 vuelto a la cabecera. Se queda así, que funciona sobre los dos. --}}
                            <div class="dropdown-trigger" onclick="dashboardToggleFrente(event)" style="padding: 0; display: flex; align-items: center; background: #fff; overflow: hidden; border: 1px solid var(--fd-ring); border-radius: 10px; height: 38px; cursor: default;">
                                <div style="padding: 0 10px; display: flex; align-items: center; color: #64748b; flex-shrink:0;">
                                    <i class="material-icons" style="font-size: 18px;">search</i>
                                </div>
                                <input type="text" id="dashboardFrenteSearch"
                                    placeholder="Buscar frente..."
                                    onkeyup="dashboardFilterFrentes(); dashboardToggleClearBtn()"
                                    style="flex: 1; min-width: 0; border: none; background: transparent; padding: 4px 5px; font-weight: 500; outline: none; color: #1e293b; cursor: text;"
                                    autocomplete="off">
                                <i id="dashboardFrenteClearBtn" class="material-icons"
                                   onclick="event.stopPropagation(); dashboardClearFrenteSearch()"
                                   style="padding: 0 8px; color: #64748b; font-size: 20px; display: none; flex-shrink:0;">close</i>
                            </div>
                                <!-- Custom Dropdown List -->
                                <div id="dashboardFrenteList" style="display: none; position: absolute; top: 105%; left: 0; right: 0; max-height: 250px; overflow-y: auto; background: white; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); z-index: 50; padding: 5px;">
                                    <div onclick="dashboardSelectFrente('all', 'Todos los Frentes', event)" class="dashboard-frente-option dropdown-item" style="padding: 8px 12px; cursor: default; border-radius: 6px; color: #1e293b; font-size: 13px; font-weight: 700; transition: background 0.2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                                        TODOS LOS FRENTES
                                    </div>
                                    @foreach($frentesDropdown as $frente)
                                        <div onclick="dashboardSelectFrente('{{ $frente->ID_FRENTE }}', '{{ addslashes(trim($frente->NOMBRE_FRENTE)) }}', event)" class="dashboard-frente-option dropdown-item" style="padding: 8px 12px; cursor: default; border-radius: 6px; color: #1e293b; font-size: 13px; transition: background 0.2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                                            {{ $frente->NOMBRE_FRENTE }}
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Export + Close (mismo estilo, uno al lado del otro) -->
                    <div class="fleet-header-right">
                        <button onclick="exportFleetStats()" title="Descargar Reporte Excel" class="fleet-header-btn">
                            <i class="material-icons">download</i>
                        </button>
                        <button onclick="closeFleetDashboard()" title="Cerrar" class="fleet-header-btn">
                            <i class="material-icons">close</i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Loading Spinner Overlay -->
            <div id="fleetDashboardSpinner" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.95); display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 1000; border-radius: 12px;">
                <div class="spinner-circle" style="width: 60px; height: 60px; border-width: 4px;"></div>
                <p style="margin-top: 20px; color: #64748b; font-size: 14px; font-weight: 600;">Cargando estadísticas...</p>
            </div>

            <!-- Dashboard Content -->
            <div class="fleet-dashboard-body" style="flex: 1; overflow-y: auto; padding: 16px 20px 20px; background: #f6f8fb;">

                {{-- Fila superior: SOLO los contadores. El buscador de frente estuvo aquí al
                     lado, pero se subió junto al título para dejarle a las tarjetas el ancho
                     entero del modal, que es lo que permite que las cinco entren en una fila. --}}
                <div class="fleet-topline">
                <!-- Stats Cards Row -->
                {{-- Sin margin aquí: la grid vive dentro de .fleet-topline, que lo anula con
                     `margin: 0 !important`. El que separa de los gráficos es el margen
                     inferior del propio .fleet-topline. --}}
                {{-- El reparto (cuántas caben por fila y cómo se estiran) está en el CSS, en
                     .fleet-stats-grid y .fleet-kpi. Aquí NO va ningún `style=`: estuvo partido
                     entre los dos sitios y obligaba a mirar en ambos. --}}
                <div class="fleet-stats-grid">

                    {{-- Las tarjetas comparten el MISMO estilo (ver .fleet-kpi*): solo cambian
                         etiqueta e id. Etiqueta + cifra, sin ícono.
                         Σ (sumatoria) en vez de la palabra "Total", a pedido del cliente: ocupa
                         menos y deja sitio para que quepan las tres de la primera fila juntas,
                         también en teléfono. --}}
                    <div class="fleet-kpi">
                        <p class="fleet-kpi-lbl">&Sigma; Equipos</p>
                        <h3 id="stat_total" class="fleet-kpi-val">0</h3>
                    </div>

                    {{-- Total de auxiliares. No viene como campo propio del backend: se suma en
                         updateStatCards a partir de aux_new + aux_old + aux_sin_anio, que son
                         las mismas cifras que ya alimentan las claves del panel de auxiliares
                         (así no puede descuadrar respecto a ese gráfico). --}}
                    <div class="fleet-kpi">
                        <p class="fleet-kpi-lbl">&Sigma; Auxiliares</p>
                        <h3 id="stat_aux_total" class="fleet-kpi-val">0</h3>
                    </div>

                    <div class="fleet-kpi">
                        <p class="fleet-kpi-lbl">Gasoil Máx L/día</p>
                        <h3 id="stat_consumption" class="fleet-kpi-val">0</h3>
                    </div>

                    {{-- Cuántos equipos usa cada combustible. Van como hijas DIRECTAS del
                         grid, sin un div que las envuelva: el bloque de teléfono apunta a
                         `.fleet-stats-grid > div` para voltear la tarjeta (cifra arriba,
                         etiqueta debajo) y bajar el padding, así que dentro de un envoltorio
                         ese estilo se lo habría quedado el envoltorio y estas dos se habrían
                         visto con el diseño de escritorio, descuadradas respecto al resto.
                         Cuentan solo la tabla `equipos` (los auxiliares tienen su tarjeta),
                         por eso pueden no sumar el Σ Equipos: faltan los eléctricos, los
                         'NO APLICA' y los que aún no tienen combustible cargado. --}}
                    <div class="fleet-kpi">
                        <p class="fleet-kpi-lbl">Equipos a Gasoil</p>
                        <h3 id="stat_gasoil_count" class="fleet-kpi-val">0</h3>
                    </div>
                    <div class="fleet-kpi">
                        <p class="fleet-kpi-lbl">Equipos a Gasolina</p>
                        <h3 id="stat_gasolina_count" class="fleet-kpi-val">0</h3>
                    </div>
                </div>

                </div>


                {{-- Hueco donde aterriza la card de Distribución ("Ubicación por Frente" o
                     "Equipos y Maquinaria", según el filtro) SOLO en teléfono: allí el sidebar
                     entero se oculta (estilos_globales.css) y esa lista se perdía. NO se
                     duplica el HTML — se muda el mismo nodo #distribucionCard, que
                     _eqRenderDistribucion() localiza por id, así que se sigue pintando igual
                     esté colgado donde esté. En escritorio este hueco queda vacío. --}}
                <div id="fdmDistribucionSlot"></div>

                {{-- Charts Row — una sola columna (gráficos apilados uno debajo del otro) a
                     pedido del cliente: a todo el ancho del modal los VALORES de las barras
                     dejan de solaparse entre sí. Eso resolvió el solape horizontal; el de las
                     ETIQUETAS del eje Y (nombres de tipo, verticalmente) es otro problema y se
                     arregla en el cálculo de altura de createStackedBarChart(). --}}
                {{-- minmax(0, 1fr) y no 1fr: un <canvas> aporta ancho INTRÍNSECO (su atributo
                     width), así que con 1fr el track de la grilla crecía hasta ese ancho, Chart.js
                     volvía a medir el contenedor ya ensanchado y lo agrandaba otra vez. El panel
                     terminaba más ancho que el modal (1068 px dentro de 880) y aparecía scroll
                     horizontal con las barras cortadas. min-width:0 deja que el panel encoja. --}}
                {{-- gap 8px (antes 14): apilados en una sola columna, 14px separaba demasiado un
                     gráfico del siguiente. En móvil la media query lo sube a 12px. --}}
                <div id="fleetChartsGrid" style="display: grid; grid-template-columns: minmax(0, 1fr); gap: 8px;">
                    <!-- Flota Nueva vs Vieja por Tipo -->
                    <div id="fdm-panel-age" class="fdm-panel">
                        <div class="fdm-panel-head">
                            <span class="fdm-panel-title">
                                <i class="material-icons">bar_chart</i>
                                Flota Nueva vs Vieja por Tipo
                            </span>
                            {{-- Los totales de flota nueva/antigua viven AQUI (antes eran dos
                                 tarjetas sueltas arriba): son el desglose de este grafico. Los
                                 ids no cambian, asi que updateStatCards() los sigue llenando. --}}
                            <span class="fdm-keys">
                                <span class="fdm-key"><i class="fdm-dot" data-serie="0"></i>Nueva (&ge;2025) <b id="stat_fleet_new">0</b></span>
                                <span class="fdm-key"><i class="fdm-dot" data-serie="1"></i>Antigua (&lt;2025) <b id="stat_fleet_old">0</b></span>
                            </span>
                            <button onclick="window.descargarPanelHtmlFDM('fdm-panel-age', 'flota_edad_tipo')" title="Descargar imagen" class="fdm-cam">
                                <i class="material-icons">photo_camera</i>
                            </button>
                        </div>
                        <canvas id="chartAgeByType"></canvas>
                    </div>

                    <!-- Equipos Auxiliares por Tipo (mismo corte de edad que el de arriba) -->
                    <div id="fdm-panel-auxiliares" class="fdm-panel">
                        <div class="fdm-panel-head">
                            <span class="fdm-panel-title">
                                <i class="material-icons">construction</i>
                                Auxiliares Nuevos vs Viejos por Tipo
                            </span>
                            {{-- Mismas claves que el panel de equipos: este grafico mide lo
                                 mismo (edad por tipo), asi que se lee igual. La tercera,
                                 "Sin año", solo aparece si hay auxiliares sin ANIO cargado
                                 — la muestra/oculta updateStatCards. --}}
                            <span class="fdm-keys">
                                <span class="fdm-key"><i class="fdm-dot" data-serie="0"></i>Nueva (&ge;2025) <b id="stat_aux_new">0</b></span>
                                <span class="fdm-key"><i class="fdm-dot" data-serie="1"></i>Antigua (&lt;2025) <b id="stat_aux_old">0</b></span>
                                <span class="fdm-key" id="stat_aux_sin_key" style="display:none;"><i class="fdm-dot" data-serie="2"></i>Sin año <b id="stat_aux_sin">0</b></span>
                            </span>
                            <button onclick="window.descargarPanelHtmlFDM('fdm-panel-auxiliares', 'auxiliares_por_tipo')" title="Descargar imagen" class="fdm-cam">
                                <i class="material-icons">photo_camera</i>
                            </button>
                        </div>
                        <canvas id="chartAuxByType"></canvas>
                    </div>

                    <!-- Equipos Asignados por Frente (al final) — DENTRO de #fleetChartsGrid: es un
                         panel más de la misma pila, así la separación se la da el `gap` de la
                         grilla en escritorio y en móvil. Con margin-top propio había que
                         sincronizar dos números a mano y en móvil (gap 12px) ya no coincidían. -->
                    <div id="fdm-panel-assigned" class="fdm-panel">
                        <div class="fdm-panel-head">
                            <span class="fdm-panel-title">
                                <i class="material-icons">directions_bus</i>
                                Equipos Asignados por Frente
                                <span style="font-size:11px; color:var(--fd-ink-2); font-weight:400;">— flota actual en cada frente</span>
                            </span>
                            <button onclick="window.descargarPanelHtmlFDM('fdm-panel-assigned', 'equipos_asignados_por_frente')" title="Descargar imagen" class="fdm-cam" style="margin-left:auto;">
                                <i class="material-icons">photo_camera</i>
                            </button>
                        </div>
                        <div id="fleetEqAsigLoading" style="display:flex; align-items:center; justify-content:center; height:80px; color:#94a3b8; font-size:13px; gap:8px;">
                            <i class="material-icons" style="animation:fleetSpin 1s linear infinite; font-size:18px;">refresh</i> Cargando...
                        </div>
                        <div id="fleetEqAsigBody" style="display:none;"></div>
                    </div>
                </div>{{-- /#fleetChartsGrid --}}

            </div>
        </div>
    </div>


    @include('admin.equipos.partials.equipment_details_modal')
    @include('admin.equipos.partials.vincular_ficha_modal')

    <link rel="stylesheet" href="{{ asset('css/vistas/admin_equipos_index_3.css') }}?v={{ @filemtime(public_path('css/vistas/admin_equipos_index_3.css')) }}">

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

@php
    // Lo que el JavaScript del modulo necesita de ESTA apertura. El codigo esta en
    // public/js/maquinaria/equipos_anclajes.js, que el navegador cachea.
    $eqanc_cfg = [
        'rutaEquiposGetAnchors' => route("equipos.getAnchors"),
        'rutaEquiposExportAnclajes' => route("equipos.exportAnclajes"),
        'rutaEquiposCreate' => route('equipos.create'),
    ];
@endphp
<script>
    window.EQANC_CFG = @json($eqanc_cfg);
</script>
<script src="{{ asset('js/maquinaria/equipos_anclajes.js') }}?v={{ @filemtime(public_path('js/maquinaria/equipos_anclajes.js')) }}"></script>
<script>
    // El archivo de arriba se carga UNA vez en toda la sesion; esta llamada es la que
    // monta la pantalla, y corre en cada apertura del modulo (tambien al volver por la
    // navegacion interna, que es cuando el <script src> ya no se re-ejecuta).
    window.equiposAnclajesArrancar(window.EQANC_CFG);
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
    // Estado inicial del Consolidado (modo "Con / Sin documento") en carga dura.
    // En cargas AJAX, loadEquipos() lo actualiza. Sin esto, clicar los bloques
    // verde/rojo antes del primer AJAX podría filtrar por estado por error.
    window.__equiposDocMode = {{ ($stats['doc_mode'] ?? false) ? 'true' : 'false' }};
    // Dirección activa de los bloques clicables del Consolidado: 'con' | 'sin' | 'all'.
    window.__equiposDocPresence = '{{ $docPresence }}';
    // Resaltar el bloque activo en carga dura (en AJAX lo hace loadEquipos()).
    // Igual que en la ficha de usuario: por navegacion SPA este evento ya paso
    // cuando el <script> se reinyecta, asi que el resaltado del lado activo
    // (Con/Sin documento) no se pintaba al entrar.
    function pintarPresenciaDeDocumento() {
        if (window.__updateDocPresenceUI) window.__updateDocPresenceUI();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', pintarPresenciaDeDocumento);
    } else {
        pintarPresenciaDeDocumento();
    }
</script>
<script>
    window.CAN_DELETE_EQUIPOS = {{ auth()->user() && auth()->user()->can('user.delete') ? 'true' : 'false' }};
</script>
<script>
(function () {
    const csrf = window.getCsrf;   // helper central (dom_helpers.js)

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
            window.apiFetch('{{ route("equipos.bulkDelete") }}', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ ids: ids.map(x => parseInt(x, 10)) })
            })
            .then(r => r.json().catch(() => ({})).then(d => ({ ok: r.ok, body: d })))
            .then(res => {
                if (window.hidePreloader) window.hidePreloader();
                if (res.ok && res.body.success) {
                    window.toast(res.body.message || 'Equipos eliminados.', 'success');
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
                window.toast('Error de red al eliminar.', 'error');
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

    // La papelera (restaurar / borrar definitivo) vive en /admin/historial-documentos
    // → Acciones → Papelera, junto con el resto del audit trail. Aqui solo queda
    // bulkDeleteEquiposSeleccionados.
})();
</script>

{{-- ═══════════════════════════════════════════════════════════
     MODAL BULK LOOKUP — tabla estilo Excel: cada fila es una celda
     editable. Pegar una columna copiada de Excel distribuye cada
     valor en su propia fila. Backend: POST {{ route('equipos.bulkLookup') }}
     busca en SERIAL_CHASIS / SERIAL_DE_MOTOR / NUMERO_ETIQUETA /
     CODIGO_PATIO y documentacion.PLACA.
     ═══════════════════════════════════════════════════════════ --}}
<link rel="stylesheet" href="{{ asset('css/vistas/admin_equipos_index_4.css') }}?v={{ @filemtime(public_path('css/vistas/admin_equipos_index_4.css')) }}">
<div id="bulkLookupModal" class="modal-overlay" style="z-index: 2500;">
    {{-- max-width arranca angosto (fase de pegado: solo dropdown + textarea). El JS
         lo ensancha a 860px al pasar a resultados, donde la tabla necesita el ancho. --}}
    <div class="modal-content" style="width: 95%; max-width: 480px; max-height: 90vh; padding: 0; display: flex; flex-direction: column; background: white; border-radius: 12px; overflow: hidden;">
        <!-- Header estilo modal de Movilización: barra slate #1e293b, ícono azul #0067b1
             + título centrados; botón Cerrar fijo a la derecha (absolute). -->
        <div style="background: #1e293b; padding: 14px 18px; display: flex; align-items: center; justify-content: center; position: relative;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="material-icons" style="font-size: 20px; color: #0067b1;">playlist_add_check</i>
                <div style="font-size: 16px; font-weight: 700; color: white;">Búsqueda Masiva</div>
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
                    {{-- Sin label: el propio dropdown ya rotula "Selecciona un frente". --}}
                    <div style="display: flex; gap: 8px; align-items: center;">
                    <div class="custom-dropdown" id="bulkLookupFrenteDropdown" data-default-label="Selecciona un frente" style="font-size: 12px;">
                        <input type="hidden" id="bulkLookupFrenteValue" data-filter-value value="">
                        {{-- 34px, no 38: el buscador y el boton Buscar van SIEMPRE al mismo alto
                             —si se toca uno solo quedan desalineados, que se nota mas que el alto
                             de mas—. A 34 la fila pesa menos y deja mas sitio a la lista de
                             seriales, que es lo que se viene a leer. --}}
                        <div class="dropdown-trigger" style="padding: 0; display: flex; align-items: center; background: #fbfcfd; border: 1px solid #cbd5e0; border-radius: 8px; height: 34px;">
                            <div style="padding: 0 8px; display: flex; align-items: center; color: #94a3b8;">
                                <i class="material-icons" style="font-size: 18px;">search</i>
                            </div>
                            <input type="text" data-filter-search placeholder="Selecciona un frente"
                                aria-label="Filtrar frente"
                                style="width: 100%; min-width: 0; border: none; background: transparent; padding: 6px 2px; font-size: 12px; outline: none;"
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
                                style="flex-shrink:0; height:34px; padding:0 16px; background:#0067b1; color:white; border:none; border-radius:8px; display:flex; align-items:center; justify-content:center; gap:6px; cursor:pointer; font-size:13px; font-weight:700; transition:filter .2s;"
                                onmouseover="this.style.filter='brightness(1.1)'" onmouseout="this.style.filter='none'">
                            <i class="material-icons" style="font-size:18px;">search</i>
                            Buscar
                        </button>
                    </div>
                </div>

                <div id="bulkLookupTextareaWrap" style="position: relative;">
                    <textarea id="bulkLookupTextarea"
                              placeholder="Copia una columna de Excel (Ctrl+C) y pega aquí (Ctrl+V). Soporta hasta 2000 valores."
                              spellcheck="false"
                              autocomplete="off"></textarea>
                    {{-- Contador dentro del cuadro de texto (esquina inferior derecha).
                         pointer-events:none para no bloquear el handle de resize ni la selección. --}}
                    <span id="bulkLookupCountHint" style="position: absolute; right: 12px; bottom: 8px; font-size: 11px; color: #94a3b8; background: rgba(255,255,255,0.85); padding: 0 4px; border-radius: 4px; pointer-events: none;">0 valor(es) único(s)</span>
                </div>
            </div>

            <!-- Results phase -->
            <div id="bulkLookupResultsPhase" style="display: none;">
                <div id="bulkLookupSummary" style="display: flex; gap: 14px; margin-bottom: 8px; flex-wrap: wrap; justify-content: center;"></div>

                {{-- Rótulo del frente seleccionado contra el que se comparan los equipos
                     (lo llena renderResults). Solo aparece si se eligió un frente. --}}
                <div id="bulkLookupFrenteCompare" style="display: none; align-items: center; gap: 6px; margin-bottom: 8px; padding: 5px 10px; border-radius: 8px; background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; font-size: 11.5px; font-weight: 700;"></div>

                <div id="bulkLookupResultsBox" style="border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background: white;">
                    <div id="bulkLookupResultsScroll" style="max-height: 50vh; overflow-y: auto;">
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

                <div id="bulkLookupLegend" style="margin-top: 8px; font-size: 11px; color: #64748b; display: flex; flex-direction: column; gap: 3px;">
                    <div>
                        <span style="color:#b91c1c;">Términos que no se encontraron</span>
                    </div>
                    <div id="bulkLookupYellowLegend" style="display:none;">
                        <span style="color:#854d0e;">Equipos en un frente diferente al seleccionado</span>
                    </div>
                    {{-- El ámbar ya significaba "otro frente"; con la búsqueda parcial pasó a
                         significar también "fragmento ambiguo". Cada uno lleva su renglón y
                         solo aparece cuando hay filas de ese tipo, para no explicar colores
                         que no están en pantalla. --}}
                    <div id="bulkLookupAmbiguoLegend" style="display:none;">
                        <span style="color:#854d0e;">Fragmentos que coinciden con varios equipos: hacen falta más caracteres</span>
                    </div>
                    <div id="bulkLookupParcialLegend" style="display:none;">
                        <span style="color:#92400e;">≈</span> Se encontró por coincidencia parcial; al lado va el valor completo
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer: botones alineados a la izquierda -->
        <div id="bulkLookupFooter" style="padding: 8px 18px; border-top: 1px solid #e2e8f0; background: white; display: flex; justify-content: center; gap: 8px;">
            {{-- Botones uniformados con la barra flotante (.btn-bulk-action):
                 forma de píldora (radius 20px), font-size 12.5px / weight 700.
                 Mantienen su color semántico, igual que la barra (Anclar=verde,
                 Detalle=gris, Movilización=azul). --}}
            <button type="button" id="bulkLookupBackBtn" onclick="bulkLookupBack()" class="blk-btn-ghost" style="display: none;">
                <i class="material-icons">arrow_back</i>
                Modificar lista
            </button>
            <button type="button" id="bulkLookupCopyMissingBtn" onclick="bulkLookupCopyMissing()" class="blk-btn-danger" style="display: none;">
                <i class="material-icons">content_copy</i>
                Copiar no encontrados
            </button>
            {{-- Asignar Detalle a los encontrados: los pasa a la selección y abre el
                 modal "Asignar Detalle" (slate, igual que el botón "Detalle" de la barra). --}}
            <button type="button" id="bulkLookupDetalleBtn" onclick="window.detalleEncontrados()" class="blk-btn-slate" style="display: none;">
                <i class="material-icons">description</i>
                Detalle
            </button>
            {{-- Movilizar TODOS los equipos encontrados de una vez: los pasa a la
                 selección y abre el modal de Movilización (azul, acción principal). --}}
            <button type="button" id="bulkLookupMovilizarBtn" onclick="window.movilizarEncontrados()" class="blk-btn-blue" style="display: none;">
                <i class="material-icons">local_shipping</i>
                Movilizar <span id="bulkLookupMovilizarCount"></span>
            </button>
        </div>
    </div>
</div>

@php
    // Lo que el JavaScript del modulo necesita de ESTA apertura. El codigo esta en
    // public/js/maquinaria/equipos_busqueda_masiva.js, que el navegador cachea.
    $eqbm_cfg = [
        'rutaEquiposBulkLookup' => route('equipos.bulkLookup'),
    ];
@endphp
<script>
    window.EQBM_CFG = @json($eqbm_cfg);
</script>
<script src="{{ asset('js/maquinaria/equipos_busqueda_masiva.js') }}?v={{ @filemtime(public_path('js/maquinaria/equipos_busqueda_masiva.js')) }}"></script>
<script>
    // El archivo de arriba se carga UNA vez en toda la sesion; esta llamada es la que
    // monta la pantalla, y corre en cada apertura del modulo (tambien al volver por la
    // navegacion interna, que es cuando el <script src> ya no se re-ejecuta).
    window.equiposBusquedaMasivaArrancar(window.EQBM_CFG);
</script>

{{-- ═══════════════════════════════════════════════════════════
     Integración con Reportes de Falla (módulo compartido):
       · Poner un equipo en INOPERATIVO abre el modal "Nuevo Reporte de Falla"
         (al crearlo, el backend deja el equipo inoperativo).
       · Cambiar el estado de un equipo con un reporte ABIERTO abre el modal de
         cierre (el backend responde 409 con los datos del reporte).
     Lógica: public/js/maquinaria/falla_create_modal.js (compartido).
     ═══════════════════════════════════════════════════════════ --}}
@include('admin.fallas.partials.create_modal')
@include('admin.fallas.partials.close_modal')
<script>
    window.FALLA_MODAL_CFG = {
        urlSearch: '{{ route("fallas.searchActivos") }}',
        urlStore:  '{{ route("fallas.store") }}',
        urlBase:   '{{ url("admin/fallas") }}',
        onCreated: function (falla) {
            // El reporte puede venir de un EQUIPO o de un AUXILIAR (al poner inoperativo
            // una fila aux embebida). Ambos handlers son idempotentes: cada uno actua solo
            // si su contexto esta pendiente, asi que llamarlos a ambos es seguro.
            if (window.handleFallaCreatedAux)    window.handleFallaCreatedAux();
            if (window.handleFallaCreatedEquipo) window.handleFallaCreatedEquipo(falla);
        },
        onClosed:  function () { if (window.loadEquipos) window.loadEquipos(); }
    };
</script>
{{-- falla_create_modal.js se carga GLOBAL en el layout (SPA-safe). --}}

{{-- ═══════════════════════════════════════════════════════════
     MAQUINARIA DE EQUIPOS AUXILIARES (reusada de /admin/equipos-auxiliares).
     Da vida a las filas aux cuando el dropdown de Tipo esta en modo AUXILIAR:
     modal de detalles, menu de estado, seleccion masiva, anclar y movilizar.
     Recibe los tipos aux (labels) y el mapa de detalles inicial; $frentes ya
     esta en scope (lo usa el modal de movilizacion).
     ═══════════════════════════════════════════════════════════ --}}
@include('admin.equipos_auxiliares.partials._machinery', [
    'tipos'             => $tiposAux ?? [],
    'auxDetailsMap'     => ($auxInitDetailsMap ?? $auxEmbed['auxDetailsMap'] ?? []),
    // En /admin/equipos NO se ofrece el boton "Anclar" para auxiliares (solo Asignar/Detalle).
    'embeddedInEquipos' => true,
])
<script>
    // En /admin/equipos el refresco de la lista tras mutar un auxiliar (cambiar estado,
    // movilizar, anclar, borrar) NO usa cargarAuxiliares (atado a los filtros del modulo
    // aux): reusa loadEquipos, que respeta el tipo aux + frente + busqueda actuales.
    window.cargarAuxiliares = function () { if (window.loadEquipos) window.loadEquipos(); };

    // Init: marcar clases de modo aux en la carga inicial (antes del primer AJAX).
    // eq-aux-mode: SOLO cuando tipo_aux: está activo (oculta filtros de equipos).
    // aux-table-active: cuando cualquier path produce tabla de auxiliares (oculta bulkFloatingBar).
    document.body.classList.toggle('eq-aux-mode',      {{ ($auxModeByTipo ?? false) ? 'true' : 'false' }});
    document.body.classList.toggle('aux-table-active', {{ ($auxMode ?? false) ? 'true' : 'false' }});
</script>

@endsection
@section('extra_js')
    {{-- Replaced by Global Load in Layout --}}
@endsection
