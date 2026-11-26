@extends('layouts.estructura_base')
@section('title', 'Consumibles')

@section('content')
    <div id="consumiblesAppRoot"
        data-frentes='@json($frentes->map(fn($f) => ['id' => $f->ID_FRENTE, 'nombre' => $f->NOMBRE_FRENTE]))'>
        <style>
            /* Estado y tipo: solo la letra de color, sin caja alrededor. */
            .badge-pen { color: #92400e; font-weight: 700; }
            .badge-ok { color: #065f46; font-weight: 700; }
            .badge-err { color: #991b1b; font-weight: 700; }
            .tipo-chip { font-weight: 700; }
            .tipo-gasoil { color: #92400e; }
            .tipo-aceite { color: #0369a1; }
            .tipo-caucho { color: #6b21a8; }
            .tipo-otro { color: #475569; }

            /* Tabla de la lista con el mismo estilo que la de Cargar Consumibles:
               rejilla compacta, encabezado oscuro de 11 px, letra de 13 px y filas alternas. */
            .cons-tbl-wrap {
                overflow-x: auto;
                border-radius: 10px;
                border: 1px solid #e2e8f0;
            }

            .cons-tbl-wrap .admin-table {
                border-collapse: collapse;
                border-spacing: 0;
                font-size: 13px;
                min-width: 700px;
            }

            .cons-tbl-wrap .admin-table thead th {
                background: #1e293b;
                color: #e2e8f0;
                font-weight: 700;
                padding: 9px 8px;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: .4px;
                border-right: 1px solid #334155;
                white-space: nowrap;
            }

            .cons-tbl-wrap .admin-table thead th:last-child {
                border-right: none;
            }

            .cons-tbl-wrap .admin-table thead th.th-center {
                text-align: center !important;
            }

            .cons-tbl-wrap .admin-table thead th.th-right {
                text-align: right !important;
            }

            .cons-tbl-wrap .admin-table tbody tr td {
                padding: 7px 8px;
                border-top: none;
                border-left: none;
                border-radius: 0;
                border-bottom: 1px solid #e2e8f0;
                border-right: 1px solid #f1f5f9;
                vertical-align: middle;
                color: #1e293b;
            }

            .cons-tbl-wrap .admin-table tbody tr:nth-child(even) td {
                background: #f8fafc !important;
            }

            .cons-tbl-wrap .admin-table tbody tr:hover td {
                background: #eff6ff !important;
            }

            .cons-tbl-wrap .admin-table tbody tr td:first-child {
                text-align: center;
                color: #94a3b8;
                /* El número de la fila, más chico que los datos. */
                font-size: 10px !important;
                font-weight: 700;
                padding: 6px 2px;
                background: #f8fafc !important;
                border-right: 2px solid #e2e8f0;
            }

            .cons-tbl-wrap .admin-table tbody tr td:last-child {
                border-right: none;
                text-align: center;
                background: #fafafa !important;
            }

            .cons-tbl-wrap .admin-table tbody tr:last-child td {
                border-bottom: none;
            }

            .cons-tbl-wrap .badge-ok,
            .cons-tbl-wrap .badge-pen,
            .cons-tbl-wrap .badge-err {
                white-space: nowrap;
            }

            /* Todos los datos de la tabla con la misma letra y el mismo tamaño (los iconos aparte). */
            .cons-tbl-wrap tbody td,
            .cons-tbl-wrap tbody td *:not(.material-icons) {
                font-size: 12px !important;
                font-family: inherit !important;
            }

            /* Arriba el tipo del equipo (o lo que dice el Excel) y debajo su placa o serial, en la misma celda. */
            .cons-tbl-wrap .cons-equipo {
                margin-bottom: 3px;
                padding-bottom: 3px;
                border-bottom: 1px dashed #e2e8f0;
                font-weight: 700;
            }
            /* Buscador de placa/serial: más angosto que Frente y Tipo (a pedido); en el teléfono, lo que deje el botón de filtros. */
            @media (max-width: 900px) { #consIdentWrap { flex: 1 1 150px !important; } }
            /* Columna Equipo angosta (160 px): el tipo y el título pasan a dos líneas si no caben. */
            .cons-tbl-wrap .admin-table .cons-col-eq { width: 160px; max-width: 160px; }
            /* la placa o serial en 12 px: un serial de 17 caracteres cabe en una línea */
            .cons-tbl-wrap .cons-col-eq .cons-id-fila { font-size: 12px; }
            .cons-tbl-wrap .admin-table thead th.cons-col-eq { white-space: normal; }
            .cons-tbl-wrap td.cons-col-eq { overflow-wrap: anywhere; }
            /* Columna de la X que borra la fila: solo lo que mide el botón. */
            .cons-tbl-wrap .admin-table .cons-col-del { width: 22px; padding: 0 3px !important; text-align: center; }
            .cons-tbl-wrap .cons-col-del .cons-btn-del { padding: 2px; }
            .cons-tbl-wrap .cons-equipo span { display: block; }
            .cons-tbl-wrap .cons-equipo .cons-nota { color: #94a3b8; font-style: italic; font-weight: 400; }

            /* Botones de lápiz y papelera como los de la tabla de Cargar. */
            .cons-tbl-wrap .cons-btn-edit,
            .cons-tbl-wrap .cons-btn-del {
                background: none;
                border: none;
                color: #cbd5e0;
                padding: 4px;
                cursor: pointer;
                border-radius: 6px;
                line-height: 0;
                transition: all .2s;
            }

            .cons-tbl-wrap .cons-btn-edit:hover {
                background: #eff6ff;
                color: #0067b1;
            }

            .cons-tbl-wrap .cons-btn-del:hover {
                background: #fee2e2;
                color: #ef4444;
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
                    width: 100% !important;
                    min-width: 100%;
                }
            }

            /* Lista con la columna de contadores a la derecha, como Equipos, pero angosta (250 px y
               no 320): los 3 contadores caben y la tabla gana el ancho. */
            .cons-layout { grid-template-columns: minmax(0, 1fr) 250px; }
            .cons-layout > .admin-card { margin: 0; min-width: 0; padding: 14px !important; }
            .cons-sidebar { position: sticky; top: 20px; }
            .cons-consolidado { position: relative; overflow: hidden; background: linear-gradient(135deg, #001a52 0%, #0a4a91 100%); border-radius: 12px; padding: 8px 12px; color: #fff; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
            .cons-consolidado-icono { position: absolute; right: -15px; bottom: -15px; font-size: 72px; opacity: .1; transform: rotate(-15deg); }
            .cons-consolidado-titulo { position: relative; font-size: 12.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; margin-bottom: 3px; }
            .cons-consolidado-grid { position: relative; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }
            .cons-blk { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 6px 4px; border-radius: 8px; text-decoration: none; color: #fff; transition: filter .2s; }
            .cons-blk:hover { filter: brightness(1.2); }
            .cons-blk strong { font-size: 20px; font-weight: 800; line-height: 1; color: #fff; }
            .cons-blk span { margin-top: 4px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; text-align: center; line-height: 1.1; }
            .cons-blk-pend { background: rgba(245, 158, 11, 0.32); border: 1px solid rgba(245, 158, 11, 0.3); }
            .cons-blk-conf { background: rgba(34, 197, 94, 0.28); border: 1px solid rgba(34, 197, 94, 0.25); }
            .cons-blk-sin { background: rgba(239, 68, 68, 0.28); border: 1px solid rgba(239, 68, 68, 0.25); }
            /* Gasoil por frente, debajo de los contadores: nombre y litros, y una barra contra el mayor. */
            .cons-frentes { margin-top: 12px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
            .cons-frentes-cab { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; padding-bottom: 7px; margin-bottom: 4px; border-bottom: 1px solid #e2e8f0; }
            .cons-frentes-cab span { font-size: 12.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; color: #001a52; }
            .cons-frentes-cab strong { font-size: 14px; font-weight: 800; color: #001a52; white-space: nowrap; }
            .cons-frente-fila { padding: 5px 0; }
            .cons-frente-dato { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; font-size: 11.5px; }
            .cons-frente-dato span { min-width: 0; line-height: 1.25; font-weight: 600; color: #1e293b; }
            .cons-frente-dato b { flex-shrink: 0; font-weight: 800; color: #000; }
            .cons-frente-fila i { display: block; height: 5px; margin-top: 3px; border-radius: 3px; background: #e2e8f0; }
            .cons-frente-fila s { display: block; height: 100%; border-radius: 3px; background: #0a4a91; }
            .cons-frente-sin .cons-frente-dato span { font-style: italic; color: #64748b; }
            .cons-frente-sin s { background: #94a3b8; }
            .cons-frentes-vacio { padding: 8px 0 2px; font-size: 12px; color: #94a3b8; text-align: center; }
            @media (max-width: 1024px) {
                .cons-sidebar { position: static; order: -1; }
            }
</style>

        <div
            style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
            <div>
                <h1 class="page-title" style="margin-bottom:4px;">
                    <span class="page-title-line2" style="color:#000;">Gestión de Consumibles</span>
                </h1>
            </div>
        </div>

        <div class="page-layout-grid cons-layout">
        <div class="admin-card" style="box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); padding: 25px;">
            {{-- Filtros --}}
            {{-- SIN onsubmit que encienda el spinner: el listener de más abajo hace
                 preventDefault() y llama a submitConsumiblesFilters() → cargarConsumibles(),
                 que ya lo enciende. Con los dos, el contador de referencias quedaba en 1 y el
                 spinner no se iba. Mismo fallo que tenía el formulario de frentes. --}}
            <form method="GET" action="{{ route('consumibles.index') }}" id="filtrosForm">
                <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin-bottom: 20px;">

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
                                <input type="text" data-filter-search id="search_frentes"
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
                                            onclick="window.selectOption('frenteFilterSelect', '{{ $f->ID_FRENTE }}', '{{ addslashes($f->NOMBRE_FRENTE) }}'); setTimeout(()=>window.submitConsumiblesFilters(), 50);">
                                            {{ $f->NOMBRE_FRENTE }}
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="flex: 0 1 160px; min-width: 120px;">
                        @php
                            $currentTipo = request('tipo');
                            $tiposList = \App\Models\Consumible::tiposLabel();
                        @endphp
                        <div class="custom-dropdown" id="tipoFilterSelect" data-filter-type="tipo"
                            data-default-label="Tipos">
                            <input type="hidden" name="tipo" data-filter-value value="{{ $currentTipo }}"
                                form="filtrosForm">
                            <div class="dropdown-trigger {{ $currentTipo ? 'filter-active' : '' }}"
                                style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:10px; height:42px;">
                                <div
                                    style="padding:0 10px; display:flex; align-items:center; color:var(--maquinaria-gray-text, #94a3b8);">
                                    <i class="material-icons" style="font-size:18px;">search</i>
                                </div>
                                <input type="text" data-filter-search id="search_tipos"
                                    placeholder="{{ $currentTipo ? $tiposList[$currentTipo] : 'Tipos' }}"
                                    style="width:100%; min-width:40px; border:none; background:transparent; padding:0 5px; font-size:13px; outline:none; height:100%; font-family:inherit;"
                                    oninput="window.filterDropdownOptions(this)" autocomplete="off">
                            </div>
                            <div class="dropdown-content"
                                style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                                <div class="dropdown-item-list" style="max-height:250px; overflow-y:auto;">
                                    @foreach($tiposList as $val => $label)
                                        <div class="dropdown-item {{ $currentTipo == $val ? 'selected' : '' }}"
                                            data-value="{{ $val }}"
                                            onclick="window.selectOption('tipoFilterSelect', '{{ $val }}', '{{ addslashes($label) }}'); setTimeout(()=>window.submitConsumiblesFilters(), 50);">
                                            {{ $label }}</div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="consIdentWrap" data-cons-sync
                        style="flex: 0 1 210px; min-width: 150px; height: 42px; border-radius: 10px; display: flex; align-items: center; border: 1px solid {{ request('identificador') ? '#0067b1' : '#cbd5e0' }}; background: {{ request('identificador') ? '#e1effa' : '#fbfcfd' }}; overflow: hidden;">
                        <i class="material-icons"
                            style="padding: 0 8px; color: #94a3b8; font-size: 18px; flex-shrink: 0;">search</i>
                        <input type="text" name="identificador" value="{{ request('identificador') }}"
                            placeholder="Buscar placa/serial..." autocomplete="off"
                            style="flex: 1; border: none; background: transparent; padding: 0; outline: none; font-size: 13px; color:#1e293b; min-width: 0; font-family:inherit;"
                            onkeydown="if(event.key==='Enter'){event.preventDefault(); window.submitConsumiblesFilters();}"
                            oninput="clearTimeout(window._idTimer); window._idTimer=setTimeout(()=>window.submitConsumiblesFilters(),600)">
                        <i class="material-icons" id="consIdentLimpiar" data-cons-sync title="Quitar"
                            style="padding: 0 8px; color: #94a3b8; font-size: 18px; flex-shrink: 0; cursor: pointer; display: {{ request('identificador') ? 'block' : 'none' }};"
                            onclick="var i=document.querySelector('#filtrosForm [name=identificador]'); i.value=''; window.submitConsumiblesFilters();">close</i>
                    </div>

                    <!-- Advanced Filter Trigger -->
                    <div style="position: relative;">
                        <button type="button" id="btnAdvancedFilter" data-cons-sync class="btn-primary-maquinaria"
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
                                <a id="consLimpiarAvanzado" data-cons-sync href="{{ route('consumibles.index', request()->except(['estado', 'fecha_desde', 'fecha_hasta', 'page'])) }}" onclick="return window.consumiblesLimpiarAvanzado();" class="panel-filtro-avanzado-limpiar" title="Limpiar todo" aria-label="Limpiar todo"></a>
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
                                    $estadosList = ['CONFIRMADO' => 'Confirmado', 'PENDIENTE' => 'Pendiente', 'SIN_MATCH' => 'Por identificar'];
                                @endphp
                                <div class="custom-dropdown" id="estadoFilterSelect" data-filter-type="estado"
                                    data-default-label="Todos los estados" style="font-size: 12px;">
                                    <input type="hidden" name="estado" data-filter-value value="{{ $currentEstado }}"
                                        form="filtrosForm">
                                    <div class="dropdown-trigger {{ $currentEstado ? 'filter-active' : '' }}"
                                        style="padding:0; display:flex; align-items:center; background:{{ $currentEstado ? '#e1effa' : 'white' }}; border:1px solid {{ $currentEstado ? '#0067b1' : '#e2e8f0' }}; border-radius:6px; height:36px; cursor: default;">
                                        <div
                                            style="padding:0 8px; display:flex; align-items:center; color:{{ $currentEstado ? '#0067b1' : '#94a3b8' }};">
                                            <i class="material-icons" style="font-size:16px;">search</i>
                                        </div>
                                        <input type="text" data-filter-search id="search_estados"
                                            readonly
                                            placeholder="{{ $estadosList[$currentEstado] ?? 'Todos los estados' }}"
                                            value="{{ $estadosList[$currentEstado] ?? 'Todos los estados' }}"
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
                                                    onclick="window.selectOption('estadoFilterSelect', '{{ $val }}', '{{ addslashes($label) }}'); setTimeout(()=>window.submitConsumiblesFilters(), 50);">
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
                            style="display: none; position: absolute; top: 100%; right: 0; width: 220px; background: #e2e8f0; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0; z-index: 1050; margin-top: 5px; overflow: hidden; animation: slideDown 0.2s ease-out;">
                            @include('admin.consumibles.partials.acciones_navegacion', ['actual' => 'lista'])
                            <a id="consumiblesExportar" href="{{ route('consumibles.exportarCsv', request()->all()) }}"
                                onclick="document.getElementById('splitDropdownMenu').style.display='none';"
                                class="dropdown-item-custom"
                                style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; transition: all 0.2s; border-bottom: 1px solid #f1f5f9; background: transparent;"
>
                                <div style="background: #f1f5f9; padding: 6px; border-radius: 6px; display: flex;">
                                    <i class="material-icons" style="font-size: 18px; color: #64748b;">download</i>
                                </div>
                                <span style="font-size: 14px; font-weight: 500;">Exportación de Data</span>
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

                // Contadores de arriba: filtran por estado SIN perder los demás filtros puestos.
                window.consumiblesFiltrarEstado = function (estado, etiqueta) {
                    window.selectOption('estadoFilterSelect', estado, etiqueta);
                    window.submitConsumiblesFilters();
                    return false;
                };
                // «Limpiar todo» del panel: quita estado y fechas, deja frente, tipo y placa.
                window.consumiblesLimpiarAvanzado = function () {
                    window.clearDropdownFilter('estadoFilterSelect');
                    document.querySelectorAll('#filtrosForm [name=fecha_desde], #filtrosForm [name=fecha_hasta]').forEach(function (i) { i.value = ''; });
                    window.submitConsumiblesFilters();
                    return false;
                };

                // conservarPagina: recargar tras editar o borrar. Se queda en la página en que
                // estaba SOLO si los filtros siguen siendo los de la URL; si mientras tanto se
                // cambió un filtro, gana el filtro nuevo (desde la página 1).
                window.submitConsumiblesFilters = function (conservarPagina) {
                    var form = document.getElementById('filtrosForm');
                    var url = new URL(form.action);
                    var formData = new FormData(form);
                    var params = new URLSearchParams();
                    for (var pair of formData.entries()) {
                        if (pair[1]) {
                            params.append(pair[0], pair[1]);
                        }
                    }
                    var actual = new URLSearchParams(window.location.search);
                    var pagina = actual.get('page');
                    actual.delete('page');
                    actual.sort();
                    var mismos = new URLSearchParams(params); mismos.sort();
                    if (conservarPagina && pagina && actual.toString() === mismos.toString()) params.append('page', pagina);
                    url.search = params.toString();
                    window.cargarConsumibles(url.pathname + url.search);
                };

                /*
                 * Filtrar o paginar SIN volver a montar la pantalla: se pide la misma pagina y se
                 * cambian solo los resultados (tabla y paginador) y los contadores. Lo
                 * demas se queda como esta —el filtro que se esta escribiendo no pierde el foco,
                 * los paneles abiertos siguen abiertos— y la URL se actualiza para que Atras y F5
                 * den lo mismo. Si la respuesta no trae los resultados (sesion caducada, error),
                 * se navega como antes.
                 */
                // silencioso: lo trae un aviso del servidor (otro usuario o el match de fondo):
                // sin spinner y, si falla, se queda como está.
                window._consumiblesUltimaUrl = null;   // cada visita empieza sin pedido en vuelo
                window.cargarConsumibles = function (destino, silencioso) {
                    var spin = !silencioso && window.showPreloader;
                    var url = new URL(destino, window.location.origin);
                    var seguir = function () {
                        if (typeof window.navigateTo === 'function') window.navigateTo(url.pathname + url.search);
                        else window.location.href = url.pathname + url.search;
                    };
                    if (spin) window.showPreloader();
                    // Lo que pide el usuario manda: un repintado por aviso pide ESTA URL, no la de la
                    // barra (que hasta que llega la respuesta sigue siendo la del filtro anterior).
                    if (!silencioso) window._consumiblesUltimaUrl = url.pathname + url.search;
                    var pedido = (window._consumiblesPedido = (window._consumiblesPedido || 0) + 1);
                    // X-SPA-Navigate: la respuesta corta (sin menu ni scripts), que trae todo esto.
                    fetch(url.pathname + url.search, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-SPA-Navigate': '1', 'Accept': 'text/html' },
                        credentials: 'same-origin'
                    })
                    .then(function (r) { if (!r.ok || r.redirected) throw new Error('HTTP ' + r.status); return r.text(); })
                    .then(function (html) {
                        // Llego otro pedido despues: este no pinta nada, pero SI suelta su
                        // referencia del spinner (es un contador; sin esto quedaba puesto).
                        if (pedido !== window._consumiblesPedido) { if (spin) window.hidePreloader(); return; }
                        var doc = new DOMParser().parseFromString(html, 'text/html');
                        var nuevo = doc.getElementById('consumiblesResultados');
                        var actual = document.getElementById('consumiblesResultados');
                        if (!nuevo || !actual) { if (!silencioso) seguir(); return; }
                        actual.innerHTML = nuevo.innerHTML;
                        ['cnt-pendientes', 'cnt-confirmados', 'cnt-sinmatch'].forEach(function (id) {
                            var a = document.getElementById(id), n = doc.getElementById(id);
                            if (a && n) a.textContent = n.textContent;
                        });
                        var gpf = document.getElementById('consPorFrente'), nGpf = doc.getElementById('consPorFrente');
                        if (gpf && nGpf) gpf.innerHTML = nGpf.innerHTML;
                        // Lo de fuera de los resultados que depende de los filtros: el Excel.
                        var exp = document.getElementById('consumiblesExportar'), nExp = doc.getElementById('consumiblesExportar');
                        if (exp && nExp) exp.href = nExp.getAttribute('href');
                        // Lo que se marca según los filtros (botón de filtros, caja de placa y su X,
                        // enlaces de los contadores y de «Limpiar todo»): como lo pinta el servidor.
                        doc.querySelectorAll('[data-cons-sync]').forEach(function (n) {
                            var a = document.getElementById(n.id); if (!a) return;
                            a.setAttribute('style', n.getAttribute('style') || '');
                            if (n.hasAttribute('href')) a.setAttribute('href', n.getAttribute('href'));
                        });
                        if (window.location.pathname + window.location.search !== url.pathname + url.search) {
                            history.pushState({}, '', url.pathname + url.search);
                        }
                        window._consumiblesUltimaUrl = null;   // ya está en la barra
                        if (spin) window.hidePreloader();
                    })
                    .catch(function () {
                        if (spin) window.hidePreloader();
                        if (pedido !== window._consumiblesPedido) return;
                        window._consumiblesUltimaUrl = null;
                        if (silencioso) return;
                        seguir();
                    });
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

            {{-- Lo que cambia al filtrar o paginar: cargarConsumibles() reemplaza SOLO esto (y
                 los contadores de arriba) sin volver a pintar la pantalla entera. --}}
            <div id="consumiblesResultados">

            {{-- Tabla --}}
            <div class="cons-tbl-wrap">

                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="th-center" style="width:28px; padding:9px 2px;">#</th>
                            <th class="th-left">Fecha</th>
                            <th class="th-left">Tipo</th>
                            <th class="th-left cons-col-eq">Equipo <span style="font-weight:400;opacity:.7;white-space:nowrap;">(placa / serial)</span></th>
                            <th class="th-left">Responsable</th>
                            <th class="th-right">Cantidad</th>
                            <th class="th-left">Frente <span style="font-weight:400;opacity:.7;">(proyecto)</span></th>
                            <th class="th-left">Estado</th>
                            <th class="cons-col-del"></th>
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
                                <td>
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
                                        <span style="display:block; color:#64748b; font-weight:600; margin-top:2px;">{{ $c->ESPECIFICACION }}</span>
                                    @endif
                                </td>
                                {{-- Arriba el tipo; debajo un solo identificador: el del equipo emparejado (la placa, y si
                                     no tiene, el serial o el código); sin equipo, lo escrito. Lo escrito tal cual se ve
                                     al presionar el lápiz (data-original). --}}
                                @php
                                    if ($c->equipo) {
                                        $ids = [$c->equipo->documentacion?->PLACA, $c->equipo->SERIAL_CHASIS, $c->equipo->CODIGO_PATIO];
                                    } elseif ($c->auxiliar) {
                                        $ids = [$c->auxiliar->CODIGO_INTERNO, $c->auxiliar->SERIAL];
                                    } else {
                                        $ids = [];
                                    }
                                    // Sin las de relleno («,,,», «_», «....»): no son una placa ni un serial.
                                    $ids = array_values(array_filter(array_map(fn($v) => trim((string) $v), $ids),
                                        fn($v) => preg_match('/[\p{L}\p{N}]/u', $v) === 1));
                                    $mostrado = $ids[0] ?? ($c->IDENTIFICADOR ?: '—');
                                    // Sin equipo, la descripción del Excel sale abajo: arriba, lo escrito sin ella.
                                    if (!$c->equipo && !$c->auxiliar && $c->DESC_EQUIPO) {
                                        $mostrado = \App\Models\Consumible::escritoSinDescripcion($c->IDENTIFICADOR, $c->DESC_EQUIPO) ?: '—';
                                    }
                                @endphp
                                <td class="cons-col-eq" style="line-height:1.5;"
                                    id="id-cell-{{ $c->ID_CONSUMIBLE }}" data-original="{{ $c->IDENTIFICADOR }}" data-mostrado="{{ $mostrado }}">
                                    <div class="cons-equipo">
                                    @if($c->equipo)
                                        {{-- Tipo --}}
                                        {{-- Tipo y marca en una sola línea --}}
                                        <span>{{ trim(($c->equipo->tipo?->nombre ?? 'S/T') . ' ' . $c->equipo->MARCA) }}</span>
                                    @elseif($c->auxiliar)
                                        {{-- Auxiliar (máquina de soldar, planta, compresor…) --}}
                                        <span>{{ trim(str_replace('_', ' ', $c->auxiliar->TIPO) . ' ' . $c->auxiliar->MARCA . ' ' . $c->auxiliar->MODELO) }}</span>
                                    @elseif($c->ESTADO_EQUIPO === 'CONFIRMADO')
                                        {{-- Confirmado sin equipo: se enseñó que no es de ningún equipo. --}}
                                        @if($c->DESC_EQUIPO)
                                            <span title="Así lo describe el Excel">{{ $c->DESC_EQUIPO }}</span>
                                        @endif
                                        <span class="cons-nota">Sin equipo</span>
                                    @elseif($c->DESC_EQUIPO)
                                        {{-- Sin equipo asociado: lo que dice el Excel del vehículo (tipo y marca). --}}
                                        <span title="Así lo describe el Excel; todavía sin equipo asociado">{{ $c->DESC_EQUIPO }}</span>
                                        <span class="cons-nota">Sin asociar</span>
                                    @else
                                        <span class="cons-nota">Sin resolver</span>
                                    @endif
                                    </div>
                                    <div class="cons-id-fila" style="display:flex; align-items:center; gap:5px;">
                                        <span id="id-txt-{{ $c->ID_CONSUMIBLE }}"
                                            style="flex:1; font-weight:600;">{{ $mostrado }}</span>
                                        @can('equipos.edit')
                                        <button
                                            onclick="editarId({{ $c->ID_CONSUMIBLE }})"
                                            class="cons-btn-edit"
                                            title="Ver y editar lo escrito">
                                            <i class="material-icons" style="font-size:14px;">edit</i>
                                        </button>
                                        @endcan
                                    </div>
                                </td>
                                <td>
                                    {{ $c->RESP_NOMBRE ?? '—' }}
                                    @if($c->RESP_CI)
                                        <span style="color:#94a3b8;"> · {{ $c->RESP_CI }}</span>
                                    @endif
                                </td>
                                <td style="font-weight:700; text-align:right; white-space:nowrap;">
                                    {{-- Formato español (1.250 / 12,5), sin decimales de relleno. --}}
                                    {{ rtrim(rtrim(number_format($c->CANTIDAD, 2, ',', '.'), '0'), ',') }}
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
                                    <span style="color:#94a3b8;">{{ $unidadAbrev }}</span>
                                </td>
                                <td id="frente-cell-{{ $c->ID_CONSUMIBLE }}">
                                    <div style="display:flex; align-items:center; gap:4px;">
                                        <span id="frente-txt-{{ $c->ID_CONSUMIBLE }}" style="flex:1;">
                                            {{ $c->frente?->NOMBRE_FRENTE ?? '—' }}
                                        </span>
                                        @can('equipos.edit')
                                        <button onclick="editarFrente({{ $c->ID_CONSUMIBLE }}, {{ $c->ID_FRENTE ?? 'null' }})"
                                            class="cons-btn-edit"
                                            title="Cambiar frente">
                                            <i class="material-icons" style="font-size:14px;">edit</i>
                                        </button>
                                        @endcan
                                    </div>
                                    @php
                                        $frenteConsumible = $c->ID_FRENTE;
                                        $frenteEquipo = $c->equipo?->ID_FRENTE_ACTUAL;
                                        $discrepancia = $c->equipo && $frenteEquipo && $frenteConsumible != $frenteEquipo;
                                    @endphp
                                    @if($discrepancia)
                                        <span
                                            title="El equipo está registrado en otro frente: {{ $c->equipo->frenteActual?->NOMBRE_FRENTE ?? 'N/A' }}"
                                            style="display:block; color:#92400e; font-weight:700; cursor:help; white-space:nowrap;">
                                            ⚠ {{ $c->equipo->frenteActual?->NOMBRE_FRENTE ?? '?' }}
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    @if($c->ESTADO_EQUIPO === 'CONFIRMADO')
                                        <span class="badge-ok">✔ Confirmado</span>
                                    @elseif($c->ESTADO_EQUIPO === 'PENDIENTE')
                                        <span class="badge-pen">⏳ Pendiente</span>
                                    @else
                                        <span class="badge-err">✖ Por identificar</span>
                                    @endif
                                </td>
                                <td class="cons-col-del">
                                    @can('equipos.edit')
                                    <button type="button" data-consumible-id="{{ $c->ID_CONSUMIBLE }}"
                                        onclick="window.borrarDirecto({{ $c->ID_CONSUMIBLE }}, '{{ route('consumibles.destroy', $c->ID_CONSUMIBLE) }}', this)"
                                        class="cons-btn-del"
                                        title="Eliminar">
                                        <i class="material-icons" style="font-size:16px;">close</i>
                                    </button>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" style="text-align:center; padding:40px; color:#94a3b8; font-weight:400;">
                                    <i class="material-icons"
                                        style="font-size:40px; display:block; margin-bottom:8px;">inbox</i>
                                    @if(request()->hasAny(['id_frente', 'estado', 'fecha_desde', 'fecha_hasta', 'identificador']))
                                        Ningún consumible cumple con los filtros.
                                        <a href="{{ route('consumibles.index', ['tipo' => request('tipo')]) }}" style="color:#0067b1; font-weight:600;">Quitar filtros</a>
                                    @else
                                        No hay registros de {{ strtolower($tiposList[$currentTipo] ?? 'consumibles') }} todavía.
                                        <a href="{{ route('consumibles.cargar') }}" style="color:#0067b1; font-weight:600;">Cargar
                                            el primer lote →</a>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div style="margin-top:20px;" id="consumiblesPagination">
                {{ $consumibles->links('vendor.pagination.custom-sliding') }}
            </div>
            </div>{{-- #consumiblesResultados --}}
        </div>

        {{-- Columna derecha: contadores en una tarjeta como la de Equipos (a pedido del usuario). --}}
        <div class="cons-sidebar">
            <div id="consContadores" class="cons-consolidado">
                <i class="material-icons cons-consolidado-icono">local_gas_station</i>
                <div class="cons-consolidado-titulo">Consumibles</div>
                <div class="cons-consolidado-grid">
                    <a id="consCntPend" data-cons-sync href="{{ route('consumibles.index', array_merge(request()->except(['estado', 'page']), ['estado' => 'PENDIENTE'])) }}"
                        onclick="return window.consumiblesFiltrarEstado('PENDIENTE', 'Pendiente');" class="cons-blk cons-blk-pend" title="Filtrar: Pendientes">
                        <strong id="cnt-pendientes">{{ $pendientes }}</strong>
                        <span>Pendientes</span>
                    </a>
                    <a id="consCntConf" data-cons-sync href="{{ route('consumibles.index', array_merge(request()->except(['estado', 'page']), ['estado' => 'CONFIRMADO'])) }}"
                        onclick="return window.consumiblesFiltrarEstado('CONFIRMADO', 'Confirmado');" class="cons-blk cons-blk-conf" title="Filtrar: Confirmados">
                        <strong id="cnt-confirmados">{{ $confirmados }}</strong>
                        <span>Confirmados</span>
                    </a>
                    <a id="consCntSin" data-cons-sync href="{{ route('consumibles.index', array_merge(request()->except(['estado', 'page']), ['estado' => 'SIN_MATCH'])) }}"
                        onclick="return window.consumiblesFiltrarEstado('SIN_MATCH', 'Por identificar');" class="cons-blk cons-blk-sin" title="Filtrar: Por identificar">
                        <strong id="cnt-sinmatch">{{ $sinMatch }}</strong>
                        <span>Por identificar</span>
                    </a>
                </div>
            </div>

            {{-- Gasoil por frente: los filtros de la lista menos el estado (ConsumiblesController::index).
                 Se repinta al filtrar, junto con los contadores. --}}
            @php
                $gpfTotal = $gasoilPorFrente->sum('litros');
                $gpfMayor = $gasoilPorFrente->whereNotNull('nombre')->max('litros') ?: 1;   // la barra se mide contra el mayor frente
                $gpfLitros = fn ($l) => number_format($l, $l == floor($l) ? 0 : 1, ',', '.');
            @endphp
            <div id="consPorFrente" class="cons-frentes">
                <div class="cons-frentes-cab">
                    <span>Gasoil por frente</span>
                    <strong>{{ $gpfLitros($gpfTotal) }} L</strong>
                </div>
                @forelse($gasoilPorFrente as $g)
                    <div class="cons-frente-fila{{ $g['nombre'] === null ? ' cons-frente-sin' : '' }}">
                        <div class="cons-frente-dato">
                            <span title="{{ $g['nombre'] ?? 'Sin frente' }}">{{ $g['nombre'] ?? 'Sin frente' }}</span>
                            <b>{{ $gpfLitros($g['litros']) }} L</b>
                        </div>
                        <i><s style="width:{{ min(100, round($g['litros'] / $gpfMayor * 100, 1)) }}%"></s></i>
                    </div>
                @empty
                    <div class="cons-frentes-vacio">Sin gasoil con estos filtros.</div>
                @endforelse
            </div>
        </div>
        </div>{{-- .cons-layout --}}

        <script>
            (function () {
                // Delegado en el envoltorio de resultados, que NO se reemplaza al filtrar: el
                // paginador de dentro si, y un listener puesto en el se perderia.
                var resultados = document.getElementById('consumiblesResultados');
                if (resultados) {
                    resultados.addEventListener('click', function (e) {
                        var link = e.target.closest('#consumiblesPagination a');
                        if (link && link.href) {
                            e.preventDefault();
                            window.cargarConsumibles(link.href);
                        }
                    });
                }
            })();
        </script>

    </div>
{{-- JS de esta pantalla (solo lo usa ella; antes iba en el layout y lo bajaban todas). La SPA lo ejecuta una vez por pestaña. --}}
<script src="{{ asset('js/maquinaria/consumibles_index.js') }}?v={{ @filemtime(public_path('js/maquinaria/consumibles_index.js')) }}"></script>
@endsection