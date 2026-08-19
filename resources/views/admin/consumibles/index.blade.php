@extends('layouts.estructura_base')
@section('title', 'Consumibles')

@section('content')
    <div id="consumiblesAppRoot" data-route-match="{{ route('consumibles.matchAutomatico') }}"
        data-frentes='@json($frentes->map(fn($f) => ['id' => $f->ID_FRENTE, 'nombre' => $f->NOMBRE_FRENTE]))'>
        <style>
            .badge-pen {
                background: #fef3c7;
                color: #92400e;
                border-radius: 20px;
                padding: 2px 10px;
                font-size: 12px;
                font-weight: 700;
                display: inline-flex;
                align-items: center;
                gap: 4px;
            }

            .badge-ok {
                background: #d1fae5;
                color: #065f46;
                border-radius: 20px;
                padding: 2px 10px;
                font-size: 12px;
                font-weight: 700;
            }

            .badge-err {
                background: #fee2e2;
                color: #991b1b;
                border-radius: 20px;
                padding: 2px 10px;
                font-size: 12px;
                font-weight: 700;
            }

            .badge-none {
                background: #f1f5f9;
                color: #475569;
                border-radius: 20px;
                padding: 2px 10px;
                font-size: 12px;
                font-weight: 700;
            }

            /* Chips para Tipos de consumible */
            .tipo-chip {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                border-radius: 20px;
                padding: 2px 10px;
                font-size: 11px;
                font-weight: 700;
            }

            .tipo-gasoil {
                background: #fef3c7;
                color: #92400e;
            }

            .tipo-aceite {
                background: #e0f2fe;
                color: #0369a1;
            }

            .tipo-caucho {
                background: #f3e8ff;
                color: #6b21a8;
            }

            .tipo-otro {
                background: #f1f5f9;
                color: #475569;
            }



            /* Encabezados tabla consumibles — mismo tono que tabla Equipos */
            .admin-table thead th {
                background: #1e293b !important;
                color: #ffffff !important;
                font-size: 13px !important;
                font-weight: 700 !important;
                text-transform: uppercase !important;
                letter-spacing: 1px !important;
                padding: 10px 20px !important;
                border-right: 1px solid #334155 !important;
                border-bottom: 2px solid #0f172a !important;
            }

            .admin-table thead th:last-child {
                border-right: none !important;
            }

            .admin-table thead th.th-center {
                text-align: center !important;
            }

            .admin-table thead th.th-right {
                text-align: right !important;
            }

            .admin-table thead th.th-left {
                text-align: left !important;
            }

            @media (max-width: 900px) {

                /* Acciones boton full width */
                .btn-acciones-wrapper {
                    flex: 1 1 100% !important;
                    min-width: 100%;
                }

                #btnAcciones {
                    width: 100%;
                    justify-content: center;
                }

                #splitDropdownMenu {
                    width: 100%;
                    min-width: 100%;
                }

                .hide-on-mobile {
                    display: none !important;
                }
            }
        </style>

        <div
            style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
            <div>
                <h1 class="page-title" style="margin-bottom:4px;">
                    <span class="page-title-line2" style="color:#000;">Gestión de Consumibles</span>
                </h1>
                <p style="margin:0; color:#64748b; font-size:13px;">Gasoil · Aceite · Cauchos por frente y equipo</p>
            </div>
        </div>



        <div class="admin-card" style="box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); padding: 25px;">
            {{-- Filtros --}}
            {{-- SIN onsubmit que encienda el spinner: el listener de más abajo hace
                 preventDefault() y llama a submitConsumiblesFilters(), que ya lo enciende.
                 Con los dos, el contador de referencias quedaba en 1 y el spinner no se iba.
                 Aquí no saltaba a la vista porque navigateTo() parece recargar, pero es
                 navegación SPA: la página NO se recarga y el contador tampoco se reinicia.
                 Mismo fallo que tenía el formulario de frentes. --}}
            <form method="GET" action="{{ route('consumibles.index') }}" id="filtrosForm">
                <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin-bottom: 20px;">

                    {{-- Contadores Comprimidos --}}
                    <div
                        style="display:flex; gap:8px; align-items: center; border-right: 1px solid #e2e8f0; padding-right: 12px; flex-shrink: 0;">
                        <a href="{{ route('consumibles.index', array_merge(request()->except('estado'), ['estado' => 'PENDIENTE'])) }}"
                            style="text-decoration:none; background:#fffbeb; border: 1px solid #fde68a; border-radius:10px; padding:5px 10px; text-align:center; min-width:60px; transition:all 0.2s; cursor: default;"
                            onmouseover="this.style.background='#fef3c7'" onmouseout="this.style.background='#fffbeb'">
                            <strong style="display:block; font-size:18px; font-weight:800; color:#d97706; line-height: 1;"
                                id="cnt-pendientes">{{ $pendientes }}</strong>
                            <span
                                style="font-size:9px; color:#b45309; text-transform:uppercase; font-weight: 700; letter-spacing:0.5px;">Pendientes</span>
                        </a>
                        <div
                            style="background:#f8fafc; border: 1px solid #e2e8f0; border-radius:10px; padding:5px 10px; text-align:center; min-width:60px; cursor: default;">
                            <strong style="display:block; font-size:18px; font-weight:800; color:#10b981; line-height: 1;"
                                id="cnt-confirmados">{{ $confirmados }}</strong>
                            <span
                                style="font-size:9px; color:#64748b; text-transform:uppercase; font-weight: 700; letter-spacing:0.5px;">Confirmados</span>
                        </div>
                        <a href="{{ route('consumibles.index', array_merge(request()->except('estado'), ['estado' => 'SIN_MATCH'])) }}"
                            style="text-decoration:none; background:#fef2f2; border: 1px solid #fecaca; border-radius:10px; padding:5px 10px; text-align:center; min-width:60px; transition:all 0.2s; cursor: default;"
                            onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'">
                            <strong style="display:block; font-size:18px; font-weight:800; color:#ef4444; line-height: 1;"
                                id="cnt-sinmatch">{{ $sinMatch }}</strong>
                            <span
                                style="font-size:9px; color:#b91c1c; text-transform:uppercase; font-weight: 700; letter-spacing:0.5px;">Sin
                                Match</span>
                        </a>
                    </div>
                    <div style="flex: 1; min-width: 150px;">
                        @php
                            $currentFrenteId = request('id_frente');
                            $currentFrente = $currentFrenteId ? $frentes->firstWhere('ID_FRENTE', $currentFrenteId) : null;
                        @endphp
                        <div class="custom-dropdown" id="frenteFilterSelect" data-filter-type="id_frente"
                            data-default-label="Todos los frentes">
                            <input type="hidden" name="id_frente" data-filter-value value="{{ $currentFrenteId }}"
                                form="filtrosForm">

                            <div class="dropdown-trigger {{ $currentFrenteId ? 'filter-active' : '' }}"
                                style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:10px; height:42px;">
                                <div
                                    style="padding:0 10px; display:flex; align-items:center; color:var(--maquinaria-gray-text, #94a3b8);">
                                    <i class="material-icons" style="font-size:18px;">search</i>
                                </div>
                                <input type="text" data-filter-search id="search_frentes" name="search_frentes"
                                    placeholder="{{ $currentFrente ? $currentFrente->NOMBRE_FRENTE : 'Todos los frentes' }}"
                                    style="width:100%; border:none; background:transparent; padding:0 5px; font-size:13px; outline:none; height:100%; font-family:inherit;"
                                    oninput="window.filterDropdownOptions(this)" autocomplete="off">
                                <i class="material-icons" data-clear-btn
                                    style="padding:0 5px; color:var(--maquinaria-gray-text, #94a3b8); font-size:18px; display:{{ $currentFrenteId ? 'block' : 'none' }}; cursor:pointer;"
                                    onclick="event.stopPropagation(); window.clearDropdownFilter('frenteFilterSelect'); window.submitConsumiblesFilters();">close</i>
                            </div>

                            <div class="dropdown-content"
                                style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                                <div class="dropdown-item-list" style="max-height:250px; overflow-y:auto;">
                                    <div class="dropdown-item {{ !$currentFrenteId ? 'selected' : '' }}" data-value=""
                                        onclick="window.selectOption('frenteFilterSelect', '', 'Todos los frentes'); setTimeout(()=>window.submitConsumiblesFilters(), 50);">
                                        Todos los frentes
                                    </div>
                                    @foreach($frentes as $f)
                                        <div class="dropdown-item {{ $currentFrenteId == $f->ID_FRENTE ? 'selected' : '' }}"
                                            data-value="{{ $f->ID_FRENTE }}"
                                            onclick="window.selectOption('frenteFilterSelect', '{{ $f->ID_FRENTE }}', '{{ $f->NOMBRE_FRENTE }}'); setTimeout(()=>window.submitConsumiblesFilters(), 50);">
                                            {{ $f->NOMBRE_FRENTE }}
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="flex: 1; min-width: 150px;">
                        @php
                            $currentTipo = request('tipo');
                            $tiposList = \App\Models\Consumible::tiposLabel();
                        @endphp
                        <div class="custom-dropdown" id="tipoFilterSelect" data-filter-type="tipo"
                            data-default-label="Todos los tipos">
                            <input type="hidden" name="tipo" data-filter-value value="{{ $currentTipo }}"
                                form="filtrosForm">
                            <div class="dropdown-trigger {{ $currentTipo ? 'filter-active' : '' }}"
                                style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:10px; height:42px;">
                                <div
                                    style="padding:0 10px; display:flex; align-items:center; color:var(--maquinaria-gray-text, #94a3b8);">
                                    <i class="material-icons" style="font-size:18px;">category</i>
                                </div>
                                <input type="text" data-filter-search id="search_tipos" name="search_tipos"
                                    placeholder="{{ $currentTipo ? $tiposList[$currentTipo] : 'Tipos' }}"
                                    style="width:100%; min-width:40px; border:none; background:transparent; padding:0 5px; font-size:13px; outline:none; height:100%; font-family:inherit;"
                                    oninput="window.filterDropdownOptions(this)" autocomplete="off">
                                <i class="material-icons" data-clear-btn
                                    style="padding:0 5px; color:var(--maquinaria-gray-text, #94a3b8); font-size:18px; display:{{ $currentTipo ? 'block' : 'none' }}; cursor:pointer;"
                                    onclick="event.stopPropagation(); window.clearDropdownFilter('tipoFilterSelect'); window.submitConsumiblesFilters();">close</i>
                            </div>
                            <div class="dropdown-content"
                                style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                                <div class="dropdown-item-list" style="max-height:250px; overflow-y:auto;">
                                    <div class="dropdown-item {{ !$currentTipo ? 'selected' : '' }}" data-value=""
                                        onclick="window.selectOption('tipoFilterSelect', '', 'Todos los tipos'); setTimeout(()=>window.submitConsumiblesFilters(), 50);">
                                        Todos los tipos</div>
                                    @foreach($tiposList as $val => $label)
                                        <div class="dropdown-item {{ $currentTipo == $val ? 'selected' : '' }}"
                                            data-value="{{ $val }}"
                                            onclick="window.selectOption('tipoFilterSelect', '{{ $val }}', '{{ $label }}'); setTimeout(()=>window.submitConsumiblesFilters(), 50);">
                                            {{ $label }}</div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        style="flex: 1; min-width: 150px; height: 42px; border-radius: 10px; display: flex; align-items: center; border: 1px solid {{ request('identificador') ? '#0067b1' : '#cbd5e0' }}; background: {{ request('identificador') ? '#e1effa' : '#fbfcfd' }}; overflow: hidden;">
                        <i class="material-icons"
                            style="padding: 0 8px; color: #94a3b8; font-size: 18px; flex-shrink: 0;">search</i>
                        <input type="text" name="identificador" value="{{ request('identificador') }}"
                            placeholder="Buscar placa/serial..." autocomplete="off"
                            style="flex: 1; border: none; background: transparent; padding: 0; outline: none; font-size: 13px; color:#1e293b; min-width: 0; font-family:inherit;"
                            onkeydown="if(event.key==='Enter'){event.preventDefault(); window.submitConsumiblesFilters();}"
                            oninput="clearTimeout(window._idTimer); window._idTimer=setTimeout(()=>window.submitConsumiblesFilters(),600)">
                        @if(request('identificador'))
                            <a href="{{ route('consumibles.index', array_merge(request()->except('identificador'))) }}"
                                style="padding: 0 8px; color: #94a3b8; display: flex; align-items: center; flex-shrink: 0;"
                                onclick="if(window.showPreloader) window.showPreloader();"><i class="material-icons"
                                    style="font-size: 18px;">close</i></a>
                        @endif
                    </div>

                    <!-- Advanced Filter Trigger -->
                    <div style="position: relative;">
                        <button type="button" id="btnAdvancedFilter" class="btn-primary-maquinaria"
                            style="height: 42px; width: 42px; padding: 0; display: flex; align-items: center; justify-content: center; background: {{ request('estado') || request('fecha_desde') || request('fecha_hasta') ? '#e1effa' : 'white' }}; border: 1px solid {{ request('estado') || request('fecha_desde') || request('fecha_hasta') ? '#0067b1' : '#cbd5e0' }}; color: {{ request('estado') || request('fecha_desde') || request('fecha_hasta') ? '#0067b1' : '#64748b' }}; box-shadow: none;"
                            onclick="window.toggleAdvancedFilterConsumibles(event)">
                            <i class="material-icons">filter_list</i>
                        </button>

                        <!-- Dynamic Filter Panel -->
                        <div id="advancedFilterPanel" class="no-close-on-click"
                            style="display: none; position: absolute; top: 100%; right: 0; width: 300px; background: #e2e8f0; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15); border: 1px solid #cbd5e1; z-index: 1000; margin-top: 10px; padding: 15px;">
                            <h4
                                style="margin: 0 0 15px 0; font-size: 14px; font-weight: 700; color: #334155; display: flex; justify-content: space-between; align-items: center;">
                                Filtros Avanzados
                                <a href="{{ route('consumibles.index', array_merge(request()->except(['estado', 'fecha_desde', 'fecha_hasta']))) }}"
                                    style="font-size: 11px; color: #64748b; font-weight: 400; text-decoration: underline; cursor: pointer; border: none; background: transparent;">Limpiar
                                    Todo</a>
                            </h4>

                            <!-- Fecha -->
                            <div style="margin-bottom: 15px;">
                                <span
                                    style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Rango
                                    de Fechas</span>
                                <div style="display: flex; gap: 8px;">
                                    <input type="date" name="fecha_desde" class="native-date"
                                        value="{{ request('fecha_desde') }}" onchange="window.submitConsumiblesFilters()"
                                        title="Desde"
                                        style="width: 100%; height: 36px; border-radius: 6px; border: 1px solid #cbd5e0; background: #fbfcfd; outline: none; padding: 0 12px; font-size:12px; color: #1e293b; cursor: pointer;"
                                        onclick="try{this.showPicker()}catch(e){}">
                                    <input type="date" name="fecha_hasta" class="native-date"
                                        value="{{ request('fecha_hasta') }}" onchange="window.submitConsumiblesFilters()"
                                        title="Hasta"
                                        style="width: 100%; height: 36px; border-radius: 6px; border: 1px solid #cbd5e0; background: #fbfcfd; outline: none; padding: 0 12px; font-size:12px; color: #1e293b; cursor: pointer;"
                                        onclick="try{this.showPicker()}catch(e){}">
                                </div>
                            </div>

                            <!-- Estado -->
                            <div style="margin-bottom: 5px;">
                                <span
                                    style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Estado
                                    de Movimiento</span>
                                @php
                                    $currentEstado = request('estado');
                                    $estadosList = ['CONFIRMADO' => 'Confirmado', 'PENDIENTE' => 'Pendiente', 'SIN_MATCH' => 'Sin Match'];
                                @endphp
                                <div class="custom-dropdown" id="estadoFilterSelect" data-filter-type="estado"
                                    data-default-label="Todos los estados" style="font-size: 12px;">
                                    <input type="hidden" name="estado" data-filter-value value="{{ $currentEstado }}"
                                        form="filtrosForm">
                                    <div class="dropdown-trigger {{ $currentEstado ? 'filter-active' : '' }}"
                                        style="padding:0; display:flex; align-items:center; background:{{ $currentEstado ? '#e1effa' : 'white' }}; border:1px solid {{ $currentEstado ? '#0067b1' : '#e2e8f0' }}; border-radius:6px; height:36px; cursor: default;">
                                        <div
                                            style="padding:0 8px; display:flex; align-items:center; color:{{ $currentEstado ? '#0067b1' : '#94a3b8' }};">
                                            <i class="material-icons" style="font-size:16px;">flag</i>
                                        </div>
                                        <input type="text" data-filter-search id="search_estados" name="search_estados"
                                            readonly
                                            placeholder="{{ $currentEstado ? $estadosList[$currentEstado] : 'Todos los estados' }}"
                                            value="{{ $currentEstado ? $estadosList[$currentEstado] : 'Todos los estados' }}"
                                            style="width:100%; border:none; background:transparent; padding:6px 5px; font-size:12px; outline:none; cursor:default; font-family:inherit;"
                                            autocomplete="off">
                                        <i class="material-icons" data-clear-btn
                                            style="padding:0 5px; color:#94a3b8; font-size:16px; display:{{ $currentEstado ? 'block' : 'none' }}; cursor:pointer;"
                                            onclick="event.stopPropagation(); window.clearDropdownFilter('estadoFilterSelect'); window.submitConsumiblesFilters();">close</i>
                                        <i class="material-icons"
                                            style="padding:0 5px; color:#94a3b8; font-size:16px; display:{{ $currentEstado ? 'none' : 'block' }}; pointer-events:none;">expand_more</i>
                                    </div>
                                    <div class="dropdown-content"
                                        style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                                        <div class="dropdown-item-list" style="max-height:250px; overflow-y:auto;">
                                            <div class="dropdown-item {{ !$currentEstado ? 'selected' : '' }}" data-value=""
                                                onclick="window.selectOption('estadoFilterSelect', '', 'Todos los estados'); setTimeout(()=>window.submitConsumiblesFilters(), 50);">
                                                Todos los estados</div>
                                            @foreach($estadosList as $val => $label)
                                                <div class="dropdown-item {{ $currentEstado == $val ? 'selected' : '' }}"
                                                    data-value="{{ $val }}"
                                                    onclick="window.selectOption('estadoFilterSelect', '{{ $val }}', '{{ $label }}'); setTimeout(()=>window.submitConsumiblesFilters(), 50);">
                                                    {{ $label }}</div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Botón Acciones -->
                    <div class="btn-acciones-wrapper" style="position: relative; flex-shrink: 0;">
                        <button type="button" id="btnAcciones" onclick="window.toggleAccionesConsumibles(event)"
                            class="btn-primary-maquinaria"
                            style="padding: 0 15px; height: 42px; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                            <i class="material-icons">settings</i>
                            <span>Acciones</span>
                            <i class="material-icons" style="font-size: 18px; margin-left: 2px;">expand_more</i>
                        </button>
                        <div id="splitDropdownMenu"
                            style="display: none; position: absolute; top: 100%; right: 0; min-width: 260px; background: #e2e8f0; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; z-index: 1050; margin-top: 10px; overflow: hidden;">

                            {{-- Navegación Estándar --}}


                            <a href="{{ route('consumibles.graficos') }}" class="dropdown-item-custom"
                                style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; border-bottom: 1px solid #f1f5f9; background: transparent; transition: all 0.2s;"
                                onmouseover="this.style.background='#f8fafc'"
                                onmouseout="this.style.background='transparent'">
                                <div style="background: #eff6ff; padding: 6px; border-radius: 6px; display: flex;">
                                    <i class="material-icons" style="font-size: 18px; color: #3b82f6;">analytics</i>
                                </div>
                                <span style="font-size:14px; font-weight:500;">Gráficos y Reportes</span>
                            </a>

                            <a href="{{ route('consumibles.cargar') }}" class="dropdown-item-custom hide-on-mobile"
                                style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; border-bottom: 1px solid #cbd5e1; background: transparent; transition: all 0.2s;"
                                onmouseover="this.style.background='#f8fafc'"
                                onmouseout="this.style.background='transparent'">
                                <div style="background: #fff7ed; padding: 6px; border-radius: 6px; display: flex;">
                                    <i class="material-icons" style="font-size: 18px; color: #ea580c;">note_add</i>
                                </div>
                                <span style="font-size:14px; font-weight:500;">Cargar Lote (Masivo)</span>
                            </a>

                            {{-- Acciones Locales --}}
                            <button type="button" id="btnMatch"
                                onclick="document.getElementById('splitDropdownMenu').style.display='none'; ejecutarMatch()"
                                class="dropdown-item-custom"
                                style="width:100%; display:flex; align-items:center; gap:10px; padding:12px 15px; color:#ef4444; border-bottom: 1px solid #f1f5f9; border-top:none; border-left:none; border-right:none; background:transparent; text-align:left; cursor:pointer; transition:all 0.2s;"
                                onmouseover="this.style.background='#fef2f2'"
                                onmouseout="this.style.background='transparent'" {{ ($pendientes == 0 && $sinMatch == 0) ? 'disabled' : '' }}>
                                <div style="background: #fee2e2; padding: 6px; border-radius: 6px; display: flex;">
                                    <i class="material-icons" style="font-size: 18px; color: #ef4444;">bolt</i>
                                </div>
                                <span style="font-size:14px; font-weight:500;">Ejecutar Match ({{ $pendientes }} /
                                    {{ $sinMatch }})</span>
                            </button>

                            <a href="{{ route('consumibles.exportarCsv', request()->all()) }}"
                                onclick="document.getElementById('splitDropdownMenu').style.display='none';"
                                class="dropdown-item-custom"
                                style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; transition: all 0.2s; border-bottom: 1px solid #f1f5f9; background: transparent;"
                                onmouseover="this.style.background='#f8fafc'"
                                onmouseout="this.style.background='transparent'">
                                <div style="background: #f1f5f9; padding: 6px; border-radius: 6px; display: flex;">
                                    <i class="material-icons" style="font-size: 18px; color: #64748b;">download</i>
                                </div>
                                <span style="font-size: 14px; font-weight: 500;">Exportación de Excel</span>
                            </a>
                        </div>
                    </div>

                </div>
            </form>



            <script>
                window.toggleAdvancedFilterConsumibles = function (event) {
                    if(event) { event.preventDefault(); event.stopPropagation(); }
                    var panel = document.getElementById('advancedFilterPanel');
                    var menuAcciones = document.getElementById('splitDropdownMenu');
                    
                    if (menuAcciones && menuAcciones.style.display === 'block') {
                        menuAcciones.style.display = 'none';
                    }

                    panel.style.display = (panel.style.display === 'none' || panel.style.display === '') ? 'block' : 'none';
                };

                window.toggleAccionesConsumibles = function (event) {
                    if(event) { event.preventDefault(); event.stopPropagation(); }
                    var menu = document.getElementById('splitDropdownMenu');
                    var panelFiltros = document.getElementById('advancedFilterPanel');

                    if (panelFiltros && panelFiltros.style.display === 'block') {
                        panelFiltros.style.display = 'none';
                    }

                    menu.style.display = (menu.style.display === 'none' || menu.style.display === '') ? 'block' : 'none';
                };

                window.submitConsumiblesFilters = function () {
                    if (window.showPreloader) window.showPreloader();
                    var form = document.getElementById('filtrosForm');
                    var url = new URL(form.action);
                    var formData = new FormData(form);
                    var params = new URLSearchParams();
                    for (var pair of formData.entries()) {
                        if (pair[1]) {
                            params.append(pair[0], pair[1]);
                        }
                    }
                    url.search = params.toString();
                    if (typeof window.navigateTo === 'function') {
                        window.navigateTo(url.pathname + url.search);
                    } else {
                        window.location.href = url.pathname + url.search;
                    }
                };

                document.getElementById('filtrosForm').addEventListener('submit', function (e) {
                    e.preventDefault();
                    window.submitConsumiblesFilters();
                });

                if (!window._consumiblesGlobalClickBound) {
                    window._consumiblesGlobalClickBound = true;
                    document.addEventListener('click', function (e) {
                        var btn = document.getElementById('btnAdvancedFilter');
                        var panel = document.getElementById('advancedFilterPanel');
                        if (btn && panel && !btn.contains(e.target) && !panel.contains(e.target)) {
                            panel.style.display = 'none';
                        }

                        var btnAcciones = document.getElementById('btnAcciones');
                        var menuAcciones = document.getElementById('splitDropdownMenu');
                        if (btnAcciones && menuAcciones && !btnAcciones.contains(e.target) && !menuAcciones.contains(e.target)) {
                            menuAcciones.style.display = 'none';
                        }
                    });
                }

            </script>

            {{-- Resumen surtido por frente --}}
            @if($resumenFrente->isNotEmpty() && request('tipo') === 'GASOIL')
                @php $maxFrente = $resumenFrente->max('total'); @endphp
                <div style="margin: 18px 0 20px 0;">
                    <p
                        style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin:0 0 10px 0; display:flex; align-items:center; gap:6px;">
                        <i class="material-icons" style="font-size:15px; color:#0067b1;">bar_chart</i>
                        Surtido por frente
                        <span style="font-weight:400; color:#94a3b8; text-transform:none; letter-spacing:0;">— con los filtros
                            aplicados</span>
                    </p>
                    <div style="display:flex; flex-direction:column; gap:6px;">
                        @foreach($resumenFrente as $rf)
                            @php
                                $pct = $maxFrente > 0 ? round($rf->total / $maxFrente * 100, 1) : 0;
                                $isTop = $loop->first;
                                $barColor = $isTop
                                    ? 'linear-gradient(90deg,#7f1d1d,#b91c1c)'
                                    : 'linear-gradient(90deg,#003a70,#0067b1)';
                            @endphp
                            <div style="display:grid; grid-template-columns:180px 1fr 130px; align-items:center; gap:10px;">
                                <span
                                    style="font-size:12px; font-weight:700; color:#1e293b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"
                                    title="{{ $rf->NOMBRE_FRENTE }}">
                                    {{ $rf->NOMBRE_FRENTE }}
                                </span>
                                <div style="background:#f1f5f9; border-radius:20px; height:9px; overflow:hidden;">
                                    <div
                                        style="width:{{ $pct }}%; background:{{ $barColor }}; height:100%; border-radius:20px; transition:width .5s;">
                                    </div>
                                </div>
                                <span style="font-size:12px; font-weight:800; color:#003a70; white-space:nowrap; text-align:right;">
                                    {{ number_format($rf->total, 0, ',', '.') }} {{ $rf->unidad }}
                                    <span style="display:block; font-size:10px; font-weight:600; color:#64748b;">⛽
                                        {{ $rf->despachos }} despacho{{ $rf->despachos != 1 ? 's' : '' }}</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Tabla --}}
            <div style="overflow-x:auto;">

                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="th-center" style="width:38px;">#</th>
                            <th class="th-left">Fecha</th>
                            <th class="th-left">Tipo</th>
                            <th class="th-right">Cantidad</th>
                            <th class="th-left">Frente</th>
                            <th class="th-left">Identificador</th>
                            <th class="th-left">Equipo Resuelto</th>
                            <th class="th-left">Responsable</th>
                            <th class="th-left">Estado</th>
                            <th style="width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($consumibles as $c)
                            @php
                                $tipoClass = match (true) {
                                    str_starts_with($c->TIPO_CONSUMIBLE, 'GASOIL') || str_starts_with($c->TIPO_CONSUMIBLE, 'GASOLINA') => 'tipo-gasoil',
                                    str_starts_with($c->TIPO_CONSUMIBLE, 'ACEITE') => 'tipo-aceite',
                                    $c->TIPO_CONSUMIBLE === 'CAUCHO' => 'tipo-caucho',
                                    default => 'tipo-otro'
                                };
                            @endphp
                            <tr>
                                <td
                                    style="text-align:center; font-size:11px; font-weight:700; color:#cbd5e0; background:#f8fafc; border-right:1px solid #e2e8f0; padding:6px 4px;">
                                    {{ ($consumibles->currentPage() - 1) * $consumibles->perPage() + $loop->iteration }}
                                </td>
                                <td style="white-space:nowrap; font-weight:600;">
                                    {{ \Carbon\Carbon::parse($c->FECHA)->format('d/m/Y') }}
                                </td>
                                <td>
                                    <span class="tipo-chip {{ $tipoClass }}">
                                        {{ $c->tipo_label }}
                                    </span>
                                    @if($c->ESPECIFICACION)
                                        <span style="display:block; font-size:10px; color:#64748b; font-weight:600;
                                                 margin-top:2px; font-family:monospace;">{{ $c->ESPECIFICACION }}</span>
                                    @endif
                                </td>
                                <td style="font-weight:700; text-align:right; white-space:nowrap;">
                                    {{ number_format($c->CANTIDAD, 1) }}
                                    @php
                                        $unidadAbrev = match (strtoupper(trim($c->UNIDAD ?? ''))) {
                                            'LITROS', 'LITRO' => 'L',
                                            'GALONES', 'GALON' => 'Gal',
                                            'UNIDADES', 'UNIDAD' => 'Un',
                                            'KILOGRAMOS', 'KILOGRAMO' => 'Kg',
                                            'GRAMOS', 'GRAMO' => 'g',
                                            default => $c->UNIDAD,
                                        };
                                    @endphp
                                    <span style="color:#94a3b8; font-size:11px;">{{ $unidadAbrev }}</span>
                                </td>
                                <td style="font-size:12px;" id="frente-cell-{{ $c->ID_CONSUMIBLE }}">
                                    <div style="display:flex; align-items:center; gap:4px;">
                                        <span id="frente-txt-{{ $c->ID_CONSUMIBLE }}" style="flex:1;">
                                            {{ $c->frente?->NOMBRE_FRENTE ?? '—' }}
                                        </span>
                                        <button onclick="editarFrente({{ $c->ID_CONSUMIBLE }}, {{ $c->ID_FRENTE ?? 'null' }})"
                                            style="background:none;border:none;cursor:pointer;padding:2px;color:#94a3b8;"
                                            title="Cambiar frente">
                                            <i class="material-icons" style="font-size:14px;">edit</i>
                                        </button>
                                    </div>
                                    @php
                                        $frenteConsumible = $c->ID_FRENTE;
                                        $frenteEquipo = $c->equipo?->ID_FRENTE_ACTUAL;
                                        $discrepancia = $c->equipo && $frenteEquipo && $frenteConsumible != $frenteEquipo;
                                    @endphp
                                    @if($discrepancia)
                                        <span
                                            title="El equipo estí registrado en otro frente: {{ $c->equipo->frenteActual?->NOMBRE_FRENTE ?? 'N/A' }}"
                                            style="display:inline-flex; align-items:center; gap:3px; background:#fef3c7; color:#92400e;
                                                 border-radius:6px; padding:1px 6px; font-size:10px; font-weight:700;
                                                 cursor:help; margin-left:4px; white-space:nowrap;">
                                            ⚠ {{ $c->equipo->frenteActual?->NOMBRE_FRENTE ?? '?' }}
                                        </span>
                                    @endif
                                </td>
                                <td style="font-family:monospace; font-size:12px; color:#1e293b;"
                                    id="id-cell-{{ $c->ID_CONSUMIBLE }}">
                                    <div style="display:flex; align-items:center; gap:5px;">
                                        <span id="id-txt-{{ $c->ID_CONSUMIBLE }}"
                                            style="flex:1;">{{ $c->IDENTIFICADOR ?? '—' }}</span>
                                        <button
                                            onclick="editarId({{ $c->ID_CONSUMIBLE }}, '{{ addslashes($c->IDENTIFICADOR ?? '') }}')"
                                            style="background:none;border:none;cursor:pointer;padding:2px;color:#94a3b8;"
                                            title="Editar identificador">
                                            <i class="material-icons" style="font-size:14px;">edit</i>
                                        </button>
                                    </div>
                                </td>
                                <td style="font-size:12px; line-height:1.5;">
                                    @if($c->equipo)
                                        @php
                                            // ¿El identificador buscado es exactamente la placa o el serial?
                                            $idBuscado = strtoupper(trim($c->IDENTIFICADOR ?? ''));
                                            $placa = strtoupper(trim($c->equipo->CODIGO_PATIO ?? ''));
                                            $serial = strtoupper(trim($c->equipo->SERIAL_CHASIS ?? ''));
                                            $esExacto = ($idBuscado === $placa || $idBuscado === $serial);
                                            // Coincidencia real: mostrar serial si el buscado no es exacto
                                            $coincidencia = (!$esExacto && $idBuscado)
                                                ? ($serial ?: $placa)
                                                : null;
                                        @endphp

                                        {{-- Tipo --}}
                                        <span
                                            style="font-size:10px; font-weight:700; color:#0067b1; text-transform:uppercase; letter-spacing:.3px;">
                                            {{ $c->equipo->tipo?->nombre ?? 'S/T' }}
                                        </span>

                                        {{-- Marca --}}
                                        <span style="display:block; color:#1e293b; font-weight:700; font-size:13px;">
                                            {{ $c->equipo->MARCA }}
                                        </span>

                                        {{-- Frente actual del equipo en la BD --}}
                                        <span style="display:block; font-size:11px; color:#475569; margin-top:1px;">
                                            <i class="material-icons" style="font-size:11px; vertical-align:middle;">location_on</i>
                                            {{ $c->equipo->frenteActual?->NOMBRE_FRENTE ?? 'Sin frente asignado' }}
                                        </span>

                                        {{-- Identificador buscado --}}
                                        <span
                                            style="display:block; font-family:monospace; font-size:11px; color:#64748b; margin-top:2px;">
                                            {{ $c->IDENTIFICADOR ?? '—' }}
                                        </span>

                                        {{-- Coincidencia completa (si el buscado era parcial) --}}
                                        @if($coincidencia)
                                            <span style="display:block; font-family:monospace; font-size:10px;
                                                         color:#059669; margin-top:1px; font-weight:700;"
                                                title="Serial/placa completo encontrado en la BD">
                                                → {{ $coincidencia }}
                                            </span>
                                        @endif

                                    @else
                                        <span style="color:#94a3b8; font-style:italic; font-size:12px;">Sin resolver</span>
                                        @if($c->IDENTIFICADOR)
                                            <span
                                                style="display:block; font-family:monospace; font-size:11px; color:#f87171; margin-top:2px;">
                                                {{ $c->IDENTIFICADOR }}
                                            </span>
                                        @endif
                                    @endif
                                </td>
                                <td style="font-size:12px;">
                                    {{ $c->RESP_NOMBRE ?? '—' }}
                                    @if($c->RESP_CI)
                                        <span style="color:#94a3b8;"> · {{ $c->RESP_CI }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if($c->ESTADO_EQUIPO === 'CONFIRMADO')
                                        <span class="badge-ok">✔ Confirmado</span>
                                    @elseif($c->ESTADO_EQUIPO === 'PENDIENTE')
                                        <span class="badge-pen">⏳ Pendiente</span>
                                    @else
                                        <span class="badge-err">✖ Sin match</span>
                                    @endif
                                </td>
                                <td>
                                    <button type="button" data-consumible-id="{{ $c->ID_CONSUMIBLE }}"
                                        onclick="window.borrarDirecto({{ $c->ID_CONSUMIBLE }}, '{{ route('consumibles.destroy', $c->ID_CONSUMIBLE) }}', this)"
                                        style="background:none; border:none; color:#ef4444; cursor:pointer; padding:4px;"
                                        title="Eliminar al instante">
                                        <i class="material-icons" style="font-size:17px;">delete</i>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" style="text-align:center; padding:40px; color:#94a3b8;">
                                    <i class="material-icons"
                                        style="font-size:40px; display:block; margin-bottom:8px;">inbox</i>
                                    No hay registros de consumibles todavía.
                                    <a href="{{ route('consumibles.cargar') }}" style="color:#0067b1; font-weight:600;">Cargar
                                        el primer lote →</a>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div style="margin-top:20px;" id="consumiblesPagination">
                {{ $consumibles->links('pagination::bootstrap-4') }}
            </div>
        </div>

        <script>
            (function () {
                var pagContainer = document.getElementById('consumiblesPagination');
                if (pagContainer) {
                    // Remove previous listeners if needed (not strictly necessary since element is new, but safe)
                    pagContainer.addEventListener('click', function (e) {
                        var link = e.target.closest('a');
                        if (link && link.href) {
                            e.preventDefault();
                            if (window.showPreloader) window.showPreloader();
                            if (typeof window.navigateTo === 'function') {
                                var url = new URL(link.href);
                                window.navigateTo(url.pathname + url.search);
                            } else {
                                window.location.href = link.href;
                            }
                        }
                    });
                }
            })();
        </script>

    </div>
@endsection