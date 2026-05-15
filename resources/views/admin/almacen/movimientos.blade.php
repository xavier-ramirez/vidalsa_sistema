@extends('layouts.estructura_base')

@section('title', 'Bitácora de Movimientos de Inventario')

@section('content')
@php
    $reqAlmacen  = request('id_almacen');
    $reqTipo     = request('tipo');
    $reqFrente   = request('id_frente');
    $reqSearch   = request('search');
    $reqDesde    = request('desde');
    $reqHasta    = request('hasta');
    $hayAdv      = $reqDesde || $reqHasta;
    $almSel      = ($reqAlmacen && $reqAlmacen !== 'all') ? ($almacenes ?? collect())->firstWhere('ID_ALMACEN', (int) $reqAlmacen) : null;
    $tipos = [
        'ENTRADA'          => ['label' => 'Entradas', 'sub' => ''],
        'SALIDA'           => ['label' => 'Salidas', 'sub' => ''],
        'AJUSTE'           => ['label' => 'Auditoría', 'sub' => 'de conteo'],
        'TRASPASO_ENTRADA' => ['label' => 'Traspasos (entran)', 'sub' => ''],
        'TRASPASO_SALIDA'  => ['label' => 'Traspasos (salen)', 'sub' => ''],
    ];
    $tipoSelLabel = ($reqTipo && isset($tipos[$reqTipo])) ? $tipos[$reqTipo]['label'] . ($tipos[$reqTipo]['sub'] ? ' ' . $tipos[$reqTipo]['sub'] : '') : null;
    $frenteSel    = ($reqFrente && $reqFrente !== 'all') ? ($frentesLista ?? collect())->firstWhere('ID_FRENTE', (int) $reqFrente) : null;
@endphp

<section class="page-title-card" style="text-align:left;margin:0 0 10px 0;">
    {{-- Layout: título a la izquierda + separador vertical + filtro de almacén con su mini-label.
         Mismo patrón visual que /admin/almacen para consistencia entre módulos. --}}
    <div style="display:flex;justify-content:flex-start;align-items:center;gap:20px;flex-wrap:wrap;">
        <h1 class="page-title" style="margin:0;">
            <span class="page-title-line2" style="color:#000;">Bitácora de Movimientos de Inventario</span>
        </h1>
        {{-- Separador vertical sutil entre título y filtro --}}
        <span aria-hidden="true" style="display:inline-block;width:1px;height:34px;background:#cbd5e0;flex:0 0 auto;"></span>
        {{-- Filtro de almacén junto al título (mismo patrón visual que /admin/almacen) --}}
        <div style="display:flex;align-items:center;gap:10px;flex:0 1 auto;">
            <span style="font-size:10.5px;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:1px;white-space:nowrap;">Almacén</span>
            <div style="width:240px;min-width:180px;max-width:100%;">
                {{-- NO se aplica .filter-active aunque haya almacén seleccionado: el filtro
                     queda con estilo neutro (sin tinte azul) porque junto al título se ve
                     como parte del encabezado, no como un filtro avanzado por accionar.
                     El estado "activo" se lee del nombre del almacén que aparece como
                     placeholder ya seleccionado. --}}
                <div class="custom-dropdown" id="almMovFiltroAlmacen" data-filter-type="id_almacen" data-default-label="Todos los almacenes">
                    <input type="hidden" name="id_almacen" data-filter-value value="{{ $reqAlmacen && $reqAlmacen !== 'all' ? $reqAlmacen : '' }}">
                    <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:#f8fafc;overflow:hidden;border:1px solid #cbd5e0;border-radius:10px;height:40px;transition:border-color .15s,background .15s;">
                        <span style="padding:0 10px;display:flex;align-items:center;color:#0067b1;"><i class="material-icons" style="font-size:18px;transform:none !important;">warehouse</i></span>
                        <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                               placeholder="{{ $almSel ? $almSel->NOMBRE : 'Todos los almacenes' }}"
                               style="flex:1;border:none;background:transparent;padding:8px 5px;font-size:13.5px;font-weight:600;color:#0f172a;outline:none;min-width:0;"
                               oninput="window.filterDropdownOptions(this)">
                        <i class="material-icons" data-clear-btn style="padding:0 8px;color:#64748b;font-size:18px;display:{{ $almSel ? 'block' : 'none' }};cursor:pointer;transform:none !important;"
                           onclick="event.stopPropagation(); clearDropdownFilter('almMovFiltroAlmacen');">close</i>
                    </div>
                    <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                        <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                            <div class="dropdown-item {{ !$almSel ? 'selected' : '' }}" data-value="all" onclick="selectOption('almMovFiltroAlmacen','all','TODOS LOS ALMACENES');">TODOS LOS ALMACENES</div>
                            @foreach(($almacenes ?? collect()) as $a)
                                <div class="dropdown-item {{ $almSel && $almSel->ID_ALMACEN == $a->ID_ALMACEN ? 'selected' : '' }}" data-value="{{ $a->ID_ALMACEN }}"
                                     onclick="selectOption('almMovFiltroAlmacen','{{ $a->ID_ALMACEN }}','{{ addslashes($a->NOMBRE) }}');">
                                    {{ $a->NOMBRE }} {{ $a->TIPO === 'GENERAL' ? '(Principal)' : '(Proyecto)' }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
    #almMovFilters { display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin-bottom:8px; }
    #almMovFilters .amf-item { flex:1 1 200px; min-width:170px; max-width:300px; }
    #almMovFilters .amf-search { flex:2 1 280px; max-width:none; }
    #almMovFilters .custom-dropdown { width:100%; }
    /* El filtro de almacén (en el título) NO se resalta en azul cuando está activo */
    #almMovFiltroAlmacen .dropdown-trigger.filter-active {
        background: #f8fafc !important;
        border-color: #cbd5e0 !important;
    }
    .amf-search-box { display:flex; align-items:center; height:45px; border:1px solid #cbd5e0; border-radius:12px; background:#fbfcfd; overflow:hidden; }
    .amf-search-box.active { border-color:var(--maquinaria-blue,#0067b1); background:#e1effa; }
    .amf-search-box i.lupa { padding:0 10px; color:#64748b; font-size:18px; }
    .amf-search-box input { flex:1; border:none; background:transparent; outline:none; padding:10px 5px; font-size:14px; min-width:0; }
    .amf-search-box i.clr { padding:0 10px; color:#64748b; font-size:18px; cursor:pointer; }
    .amf-adv-btn { height:45px; width:45px; padding:0; display:flex; align-items:center; justify-content:center; border-radius:12px; box-shadow:none; }

    /* Sugerencias del filtro de búsqueda — mismo diseño que /admin/almacen */
    .amf-search-wrap { position:relative; }
    .amf-suggest {
        position:absolute; top:calc(100% + 5px); left:0; right:0; background:#fff;
        border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,0.1);
        z-index:1000; max-height:260px; overflow-y:auto; padding:5px; display:none;
    }
    .amf-suggest.open { display:block; animation:slideDown 0.18s ease-out; }
    .amf-suggest-item { display:flex; flex-direction:column; gap:2px; padding:10px 15px; border-radius:8px; cursor:default; transition:background 0.2s; font-weight:600; color:var(--maquinaria-dark-blue,#1e3a5f); }
    .amf-suggest-item:hover, .amf-suggest-item.active { background:#f0f4f8; }
    .amf-suggest-item .nom { font-size:13.5px; color:#475569; font-weight:600; }
    .amf-suggest-empty { padding:10px 15px; font-size:13px; color:#94a3b8; }
    /* Tabla limpia: thead oscuro + body con TODOS los valores CENTRADOS (verticales y horizontales).
       Sin bordes verticales entre columnas. */
    .alm-mov-table { width:100%; border-collapse:separate; border-spacing:0; font-size:14px; color:#000; }
    .alm-mov-table thead tr { background:#1e293b; color:#fff; }
    .alm-mov-table thead th {
        text-align:center; color:#fff; font-size:13px; font-weight:700;
        text-transform:uppercase; letter-spacing:1px;
        padding:10px 12px; border-bottom:2px solid #0f172a;
        white-space:nowrap;
    }
    .alm-mov-table tbody td {
        padding:12px 12px; color:#000; font-size:14px;
        text-align:center; vertical-align:middle;
        border-bottom:1px solid #e2e8f0;
    }
    .alm-mov-table tbody tr:hover td { background:#e0f2fe; }
    /* Celda "Descripción del producto": ancla del tooltip de usuario (position:relative
       para que el .tooltip-bubble interno se posicione con bottom:100% sobre ella). */
    .alm-mov-table td.col-producto { position:relative; }
    /* Activamos el .tooltip-bubble (clase global de /admin/equipos) al hover de la fila
       completa — mismo patrón que .admin-table tr:hover .tooltip-bubble en
       estilos_globales.css. Replicamos aquí porque la tabla no es .admin-table. */
    .alm-mov-table tbody tr.alm-mov-row:hover .tooltip-bubble {
        opacity:1 !important;
        visibility:visible !important;
        transform:translateY(0) !important;
    }
    .amf-stat-pill { display:none; }
    @media (max-width: 900px) {
        #almMovFilters .amf-item, #almMovFilters .amf-search { max-width:none; flex:1 1 100%; }
        .amf-stat-pill { display:inline-flex; align-items:center; gap:6px; background:#f1f5f9; border-radius:999px; padding:6px 12px; font-size:13px; font-weight:700; color:#334155; margin-bottom:8px; }
        .amf-stat-pill i { font-size:16px; color:#0369a1; }
    }
</style>

<div class="page-layout-grid">
<div class="admin-card" style="margin:0;min-height:70vh;min-width:0;width:100%;padding:14px;">

    <div class="amf-stat-pill"><i class="material-icons">receipt_long</i> <span id="almMovTotalMobile">{{ $total }}</span> movimientos</div>

    {{-- ── Filtros ── (el filtro de almacén está junto al título, no aquí) --}}
    <div id="almMovFilters">

        {{-- Buscar producto --}}
        <div class="amf-item amf-search">
            <div class="amf-search-wrap">
                <div class="amf-search-box {{ $reqSearch ? 'active' : '' }}">
                    <i class="material-icons lupa">search</i>
                    {{-- Escribir SOLO refresca las sugerencias — NO dispara la consulta a la tabla.
                         El filtro se aplica cuando el usuario (a) elige una sugerencia, (b) pulsa Enter,
                         o (c) limpia el campo con la X. Mismo patrón que /admin/almacen (filtro Buscar). --}}
                    <input type="text" id="almMovSearch" autocomplete="off" placeholder="Buscar producto (código o descripción)…" value="{{ $reqSearch }}"
                           oninput="window.almMovSuggestFn()"
                           onfocus="window.almMovSuggestFn()"
                           onkeydown="if(event.key==='Enter'){event.preventDefault();window.almMovSuggestHide();window.loadMovimientos();} if(event.key==='Escape') window.almMovSuggestHide();">
                    <i class="material-icons clr" id="almMovSearchClear" style="display:{{ $reqSearch ? 'block' : 'none' }};" onclick="document.getElementById('almMovSearch').value=''; this.style.display='none'; window.almMovSuggestHide(); window.loadMovimientos();">close</i>
                </div>
                <div class="amf-suggest" id="almMovSuggest"></div>
            </div>
        </div>

        {{-- Frente --}}
        <div class="amf-item">
            <div class="custom-dropdown" id="almMovFiltroFrente" data-filter-type="id_frente" data-default-label="Todos los frentes">
                <input type="hidden" name="id_frente" data-filter-value value="{{ $reqFrente && $reqFrente !== 'all' ? $reqFrente : '' }}">
                <div class="dropdown-trigger {{ $frenteSel ? 'filter-active' : '' }}" style="padding:0;display:flex;align-items:center;background:#fbfcfd;overflow:hidden;border:1px solid #cbd5e0;border-radius:12px;height:45px;">
                    {{-- Icono de lupa (consistente con los tres filtros de esta vista). --}}
                    <span style="padding:0 10px;display:flex;align-items:center;color:var(--maquinaria-gray-text);"><i class="material-icons" style="font-size:18px;transform:none !important;">search</i></span>
                    <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                           placeholder="{{ $frenteSel ? $frenteSel->NOMBRE_FRENTE : 'Todos los frentes' }}"
                           style="flex:1;border:none;background:transparent;padding:10px 5px;font-size:14px;outline:none;min-width:0;"
                           oninput="window.filterDropdownOptions(this)">
                    <i class="material-icons" data-clear-btn style="padding:0 5px;color:var(--maquinaria-gray-text);font-size:18px;display:{{ $frenteSel ? 'block' : 'none' }};cursor:pointer;transform:none !important;"
                       onclick="event.stopPropagation(); clearDropdownFilter('almMovFiltroFrente');">close</i>
                </div>
                <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                    <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                        <div class="dropdown-item {{ !$frenteSel ? 'selected' : '' }}" data-value="all" onclick="selectOption('almMovFiltroFrente','all','TODOS LOS FRENTES');">TODOS LOS FRENTES</div>
                        @foreach(($frentesLista ?? collect()) as $f)
                            <div class="dropdown-item {{ $frenteSel && $frenteSel->ID_FRENTE == $f->ID_FRENTE ? 'selected' : '' }}" data-value="{{ $f->ID_FRENTE }}"
                                 onclick="selectOption('almMovFiltroFrente','{{ $f->ID_FRENTE }}','{{ addslashes($f->NOMBRE_FRENTE) }}');">{{ $f->NOMBRE_FRENTE }}</div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Tipo --}}
        <div class="amf-item">
            <div class="custom-dropdown" id="almMovFiltroTipo" data-filter-type="tipo" data-default-label="Todos los tipos">
                <input type="hidden" name="tipo" data-filter-value value="{{ $reqTipo && isset($tipos[$reqTipo]) ? $reqTipo : '' }}">
                <div class="dropdown-trigger {{ $tipoSelLabel ? 'filter-active' : '' }}" style="padding:0;display:flex;align-items:center;background:#fbfcfd;overflow:hidden;border:1px solid #cbd5e0;border-radius:12px;height:45px;">
                    {{-- Icono de lupa (consistente con los tres filtros de esta vista). --}}
                    <span style="padding:0 10px;display:flex;align-items:center;color:var(--maquinaria-gray-text);"><i class="material-icons" style="font-size:18px;transform:none !important;">search</i></span>
                    <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                           placeholder="{{ $tipoSelLabel ?: 'Todos los tipos' }}"
                           style="flex:1;border:none;background:transparent;padding:10px 5px;font-size:14px;outline:none;min-width:0;"
                           oninput="window.filterDropdownOptions(this)">
                    <i class="material-icons" data-clear-btn style="padding:0 5px;color:var(--maquinaria-gray-text);font-size:18px;display:{{ $tipoSelLabel ? 'block' : 'none' }};cursor:pointer;transform:none !important;"
                       onclick="event.stopPropagation(); clearDropdownFilter('almMovFiltroTipo');">close</i>
                </div>
                <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                    <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                        <div class="dropdown-item {{ !$tipoSelLabel ? 'selected' : '' }}" data-value="all" onclick="selectOption('almMovFiltroTipo','all','TODOS LOS TIPOS');">TODOS LOS TIPOS</div>
                        @foreach($tipos as $k => $t)
                            <div class="dropdown-item {{ $reqTipo === $k ? 'selected' : '' }}" data-value="{{ $k }}" onclick="selectOption('almMovFiltroTipo','{{ $k }}','{{ addslashes($t['label'] . ($t['sub'] ? ' ' . $t['sub'] : '')) }}');">
                                <div style="line-height:1.2;">
                                    <div>{{ $t['label'] }}</div>
                                    @if($t['sub'])
                                        <div style="font-size:10.5px;color:#64748b;margin-top:2px;">{{ $t['sub'] }}</div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Filtros Avanzados (mismo estilo que /admin/equipos) — contiene rango de fechas Desde/Hasta --}}
        <div style="position:relative;flex:0 0 auto;">
            <button type="button" id="btnAdvancedFilterMov" class="btn-primary-maquinaria amf-adv-btn" title="Filtros Avanzados"
                    style="background:{{ $hayAdv ? '#fee2e2' : '#fff' }};border:1px solid {{ $hayAdv ? '#ef4444' : '#cbd5e0' }};color:{{ $hayAdv ? '#ef4444' : '#64748b' }};box-shadow:none;"
                    onclick="window.almMovToggleFechas(event)">
                <i class="material-icons">filter_list</i>
            </button>
            <div id="almMovFechasPanel" style="display:none;position:absolute;top:100%;right:0;width:360px;max-width:calc(100vw - 20px);background:#e2e8f0;border:1px solid #cbd5e1;border-radius:12px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.15);z-index:100;margin-top:10px;padding:15px;">
                <h4 style="margin:0 0 15px 0;font-size:14px;font-weight:700;color:#334155;display:flex;justify-content:space-between;align-items:center;">
                    Filtros Avanzados
                    <span style="font-size:11px;color:#64748b;font-weight:400;text-decoration:underline;cursor:pointer;" onclick="window.almMovLimpiarFechas()">Limpiar Todo</span>
                </h4>
                {{-- Desde + Hasta (2 columnas, mismo grid que Marca/Modelo en /admin/equipos) --}}
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    {{-- Cajas Desde/Hasta: la caja COMPLETA dispara el date picker (no
                         solo el iconito nativo). Click en el contenedor → input.showPicker(),
                         que es la API estándar para abrir el selector de fecha de <input type="date">. --}}
                    <div>
                        <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;">Desde</span>
                        <div id="almMovDesdeBox" style="display:flex;align-items:center;background:{{ $reqDesde ? '#e1effa' : 'white' }};border:1px solid #e2e8f0;border-radius:6px;height:32px;padding:0 6px;cursor:pointer;"
                             onclick="var i=document.getElementById('almMovDesde'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                            <i class="material-icons" style="font-size:16px;color:#94a3b8;margin-right:4px;pointer-events:none;">event</i>
                            <input type="date" id="almMovDesde" value="{{ $reqDesde }}" onchange="window.loadMovimientos()"
                                   style="flex:1;min-width:0;border:none;background:transparent;padding:6px 2px;font-size:12px;outline:none;color:#334155;cursor:pointer;">
                            <i class="material-icons" id="almMovDesdeClear"
                               style="display:{{ $reqDesde ? 'inline-flex' : 'none' }};font-size:14px;color:#64748b;cursor:pointer;padding:2px;border-radius:50%;"
                               onclick="event.stopPropagation(); var i=document.getElementById('almMovDesde'); if(i){ i.value=''; } this.style.display='none'; document.getElementById('almMovDesdeBox').style.background='white'; window.loadMovimientos();">close</i>
                        </div>
                    </div>
                    <div>
                        <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;">Hasta</span>
                        <div id="almMovHastaBox" style="display:flex;align-items:center;background:{{ $reqHasta ? '#e1effa' : 'white' }};border:1px solid #e2e8f0;border-radius:6px;height:32px;padding:0 6px;cursor:pointer;"
                             onclick="var i=document.getElementById('almMovHasta'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                            <i class="material-icons" style="font-size:16px;color:#94a3b8;margin-right:4px;pointer-events:none;">event</i>
                            <input type="date" id="almMovHasta" value="{{ $reqHasta }}" onchange="window.loadMovimientos()"
                                   style="flex:1;min-width:0;border:none;background:transparent;padding:6px 2px;font-size:12px;outline:none;color:#334155;cursor:pointer;">
                            <i class="material-icons" id="almMovHastaClear"
                               style="display:{{ $reqHasta ? 'inline-flex' : 'none' }};font-size:14px;color:#64748b;cursor:pointer;padding:2px;border-radius:50%;"
                               onclick="event.stopPropagation(); var i=document.getElementById('almMovHasta'); if(i){ i.value=''; } this.style.display='none'; document.getElementById('almMovHastaBox').style.background='white'; window.loadMovimientos();">close</i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Botón Acciones (dropdown estilo /admin/movilizaciones) ────────────
             Reemplaza al viejo botón "Inventario" y consolida las acciones
             rápidas de la bitácora en un único menú:
               · Generar Nota de Entrega por código (NE-YYYY-NNNN)
               · Eliminar Nota de Entrega por código  (sólo super.admin)
               · Volver al inventario
         --}}
        <div style="position:relative;flex:0 0 auto;margin-left:auto;">
            <button type="button" id="btnAccionesMov" class="btn-primary-maquinaria"
                    style="padding:0 15px;height:45px;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);"
                    onclick="window.toggleAccionesMovInv(event)">
                <i class="material-icons" style="font-size:18px;">settings</i>
                <span>Acciones</span>
                <i class="material-icons" style="font-size:18px;margin-left:2px;">expand_more</i>
            </button>

            <div id="splitDropdownMenuMovInv"
                 style="display:none;position:absolute;top:calc(100% + 5px);right:0;min-width:260px;background:#e2e8f0;border:1px solid #cbd5e1;border-radius:10px;box-shadow:0 10px 20px -5px rgba(15,23,42,0.18);z-index:50;overflow:hidden;">
                {{-- Generar Nota: cualquier usuario que pueda ver la bitácora puede reimprimir. --}}
                <div style="padding:6px;">
                    <button type="button"
                        onclick="document.getElementById('splitDropdownMenuMovInv').style.display='none'; window.openGenerarNotaModal();"
                        style="width:100%;display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:6px;border:none;background:transparent;color:#475569;font-size:13px;font-weight:700;cursor:pointer;text-align:left;transition:background 0.15s;"
                        onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='transparent'">
                        <div style="background:#e0f2fe;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;line-height:1;color:#0284c7;">print</i></div>
                        <span>Generar Nota por código</span>
                    </button>
                </div>

                @can('super.admin')
                {{-- Eliminar Nota: gateado a super.admin porque reversa stock y deja un par
                     (SALIDA original + ENTRADA reversa) en el kardex de auditoría. --}}
                <div style="padding:6px;border-top:1px solid #cbd5e1;">
                    <button type="button"
                        onclick="document.getElementById('splitDropdownMenuMovInv').style.display='none'; window.openEliminarNotaModal();"
                        style="width:100%;display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:6px;border:none;background:transparent;color:#475569;font-size:13px;font-weight:700;cursor:pointer;text-align:left;transition:background 0.15s;"
                        onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='transparent'">
                        <div style="background:#fee2e2;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;line-height:1;color:#dc2626;">delete</i></div>
                        <span>Eliminar Nota por código</span>
                    </button>
                </div>
                @endcan

            </div>
        </div>
    </div>

    {{-- ── Tabla ── --}}
    <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:12px;">
        <table class="alm-mov-table">
            <thead>
                {{-- Anchos rebalanceados: la columna "Descripción del producto" es la única flexible
                     (sin width) para que absorba el espacio restante y se vea proporcional. Las demás
                     llevan ancho fijo acorde al contenido típico. --}}
                <tr>
                    <th style="width:100px;">Fecha</th>
                    <th style="width:140px;">Tipo</th>
                    <th>Descripción del producto</th>
                    <th style="width:130px;">Cantidad</th>
                    <th style="width:75px;">Stock</th>
                    <th style="width:170px;">Destino / contraparte</th>
                    <th style="width:90px;">Ref</th>
                </tr>
            </thead>
            <tbody id="almMovTableBody">
                @include('admin.almacen.partials.kardex_rows', ['movimientos' => $movimientos])
            </tbody>
        </table>
    </div>

    <div style="margin-top:14px;" id="almMovPagination">
        {{ $movimientos->links('vendor.pagination.custom-sliding') }}
    </div>

</div>

{{-- Sidebar: contador + ranking de consumo (mismas dimensiones que /admin/equipos) --}}
<div class="counter-sidebar" style="position:sticky;top:20px;display:flex;flex-direction:column;gap:8px;">
    <div style="background:linear-gradient(135deg,#0c4a6e 0%,#0369a1 100%);border-radius:12px;padding:15px;color:#fff;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);position:relative;overflow:hidden;">
        <i class="material-icons" style="position:absolute;right:-12px;bottom:-12px;font-size:78px;opacity:0.12;transform:rotate(-12deg);">receipt_long</i>
        <div style="position:relative;z-index:2;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;opacity:0.9;margin-bottom:5px;">Total movimientos</div>
            <div style="display:flex;align-items:baseline;gap:6px;">
                <span id="almMovTotal" style="font-size:32px;font-weight:800;line-height:1;letter-spacing:-1px;">{{ $total }}</span>
                <span style="font-size:12px;opacity:0.85;font-weight:500;">registros (según filtros)</span>
            </div>
        </div>
    </div>

    {{-- Ranking de Consumo: mismo wrapper que el card de Distribución en /admin/equipos y /admin/almacen --}}
    <div style="background:white;border-radius:12px;padding:15px;border:1px solid #e2e8f0;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);overflow:hidden;">
        <div id="almMovConsumoContainer">
            @include('admin.almacen.partials.consumo_stats', ['consumo' => $consumo])
        </div>
    </div>
</div>

</div>{{-- /page-layout-grid --}}

<script>
(function () {
    'use strict';
    if (!document.getElementById('almMovTableBody')) return;

    var ROUTE = @json(route('almacen.movimientos'));

    function el(id) { return document.getElementById(id); }
    // Busca los hidden de los filtros por name. NOTA: no restringimos a #almMovFilters porque el dropdown de
    // almacén vive ahora junto al título (fuera de ese contenedor); el atributo data-filter-value es el ancla.
    function hv(name) { var e = document.querySelector('input[name="' + name + '"][data-filter-value]'); return e ? String(e.value).trim() : ''; }

    function buildParams(pageUrl) {
        var p = new URLSearchParams();
        var alm = hv('id_almacen'); if (alm && alm !== 'all') p.set('id_almacen', alm);
        var tipo = hv('tipo'); if (tipo && tipo !== 'all') p.set('tipo', tipo);
        var fr = hv('id_frente'); if (fr && fr !== 'all') p.set('id_frente', fr);
        var s = el('almMovSearch'); if (s && s.value.trim()) p.set('search', s.value.trim());
        var d = el('almMovDesde'); if (d && d.value) p.set('desde', d.value);
        var h = el('almMovHasta'); if (h && h.value) p.set('hasta', h.value);
        // conservar id_producto si vino en la URL (al entrar desde el detalle de un producto)
        var urlProd = new URLSearchParams(window.location.search).get('id_producto');
        if (urlProd) p.set('id_producto', urlProd);
        if (pageUrl) { try { var pg = new URL(pageUrl, window.location.origin).searchParams.get('page'); if (pg) p.set('page', pg); } catch (e) {} }
        return p;
    }

    window.loadMovimientos = function (pageUrl) {
        var body = el('almMovTableBody'); if (!body) return;
        var p = buildParams(pageUrl);
        var url = ROUTE + '?' + p.toString();
        body.style.opacity = '0.5';
        if (window.showPreloader) window.showPreloader();
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.html !== undefined) body.innerHTML = data.html;
                var pg = el('almMovPagination'); if (pg) pg.innerHTML = data.pagination || '';
                ['almMovTotal', 'almMovTotalMobile'].forEach(function (id) { var e = el(id); if (e && data.total !== undefined) e.textContent = data.total; });
                // Refresca el ranking de consumo del sidebar (mismas dimensiones que /admin/equipos).
                var cc = el('almMovConsumoContainer'); if (cc && data.consumo !== undefined) cc.innerHTML = data.consumo;
                // marca "activo" del buscador
                var sb = document.querySelector('.amf-search-box'); var si = el('almMovSearch');
                if (sb && si) sb.classList.toggle('active', !!si.value.trim());
                var sc = el('almMovSearchClear'); if (sc && si) sc.style.display = si.value.trim() ? 'block' : 'none';
                try { window.history.replaceState(null, '', url); } catch (e) {}
            })
            .catch(function () { body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:#dc2626;">No se pudieron cargar los movimientos.</td></tr>'; })
            .finally(function () { body.style.opacity = '1'; if (window.hidePreloader) window.hidePreloader(); });
    };

    // ── Lista de productos para el autocomplete del filtro de búsqueda ──
    window.almMovProductosLista = @json(($productosLista ?? collect())->map(fn($p) => ['ID_PRODUCTO' => $p->ID_PRODUCTO, 'CODIGO' => $p->CODIGO, 'NOMBRE' => $p->NOMBRE]));

    function almMovNorm(s) { return s ? String(s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase() : ''; }
    window.almMovSuggestHide = function () { var b = document.getElementById('almMovSuggest'); if (b) b.classList.remove('open'); };
    window.almMovSuggestFn = function () {
        var inp = document.getElementById('almMovSearch'), box = document.getElementById('almMovSuggest');
        if (!inp || !box) return;
        var term = almMovNorm(inp.value.trim());
        var lista = window.almMovProductosLista || [];
        var matches = [];
        if (term === '') {
            for (var i = 0; i < lista.length && matches.length < 12; i++) matches.push(lista[i]);
        } else {
            for (var j = 0; j < lista.length; j++) {
                var p = lista[j];
                if (almMovNorm(p.CODIGO).indexOf(term) > -1 || almMovNorm(p.NOMBRE).indexOf(term) > -1) {
                    if (matches.length < 12) matches.push(p);
                }
            }
        }
        var html = '';
        if (!matches.length) {
            html = '<div class="amf-suggest-empty">Sin coincidencias.</div>';
        } else {
            html = matches.map(function (p) {
                var nom = (p.NOMBRE || '').replace(/[<>&"]/g, '');
                return '<div class="amf-suggest-item" data-pick="' + nom + '"><span class="nom">' + nom + '</span></div>';
            }).join('');
        }
        box.innerHTML = html;
        box.classList.add('open');
    };
    // Delegación de clic en sugerencias
    document.addEventListener('click', function (e) {
        var item = e.target.closest('#almMovSuggest .amf-suggest-item');
        if (item) {
            var inp = document.getElementById('almMovSearch');
            if (inp) inp.value = item.getAttribute('data-pick') || '';
            window.almMovSuggestHide();
            window.loadMovimientos();
            return;
        }
        if (!e.target.closest('#almMovSearch') && !e.target.closest('#almMovSuggest')) window.almMovSuggestHide();
    });

    // Selección en los custom-dropdown → recargar
    window.addEventListener('dropdown-selection', function (e) {
        if (!document.getElementById('almMovTableBody')) return;
        var id = e.detail && e.detail.dropdownId;
        if (id === 'almMovFiltroAlmacen' || id === 'almMovFiltroTipo' || id === 'almMovFiltroFrente') window.loadMovimientos();
    });

    // Click en una fila del ranking de consumo → pega el nombre del producto en el
    // buscador y recarga la tabla. El filtro backend hace LIKE %nombre%, suficiente
    // para acotar a los movimientos de ese producto sin requerir id_producto exacto.
    window.almMovFiltrarPorProducto = function (_idProducto, nombre) {
        var inp = el('almMovSearch');
        if (inp) {
            inp.value = nombre || '';
            var sb = document.querySelector('.amf-search-box'); if (sb) sb.classList.add('active');
            var sc = el('almMovSearchClear'); if (sc) sc.style.display = inp.value ? 'block' : 'none';
        }
        window.almMovSuggestHide && window.almMovSuggestHide();
        window.loadMovimientos();
    };

    // Paginación AJAX
    document.addEventListener('click', function (e) {
        var a = e.target.closest('#almMovPagination a.page-link') || e.target.closest('#almMovPagination a');
        if (a) { e.preventDefault(); e.stopImmediatePropagation(); window.loadMovimientos(a.href); }
    }, true);

    // Panel de fechas
    window.almMovToggleFechas = function (ev) {
        if (ev) ev.stopPropagation();
        var p = el('almMovFechasPanel'); if (!p) return;
        var m = el('splitDropdownMenuMovInv'); if (m) m.style.display = 'none';
        
        // Forma correcta de cerrar los custom-dropdowns de uicomponents.js
        if (typeof window.closeAllDropdowns === 'function') window.closeAllDropdowns();
        document.querySelectorAll('.custom-dropdown.active').forEach(d => d.classList.remove('active'));
        // Limpiar cualquier estilo inline residual que hayamos inyectado por error
        document.querySelectorAll('.dropdown-content').forEach(d => d.style.display = '');

        p.style.display = (p.style.display === 'block') ? 'none' : 'block';
    };
    window.almMovLimpiarFechas = function () {
        if (el('almMovDesde')) el('almMovDesde').value = '';
        if (el('almMovHasta')) el('almMovHasta').value = '';
        window.loadMovimientos();
    };
    document.addEventListener('click', function (e) {
        var p = el('almMovFechasPanel');
        if (p && p.style.display === 'block' && !e.target.closest('#almMovFechasPanel') && !e.target.closest('.amf-adv-btn')) p.style.display = 'none';
    });

    // ── Dropdown "Acciones" (toggle + cierre al click fuera) ────────────────
    // Mismo patrón del dropdown de /admin/movilizaciones (toggleAccionesMov):
    // sufijo "Inv" para no colisionar con el del módulo de movilizaciones si
    // ambos cargan en una SPA.
    window.toggleAccionesMovInv = function (ev) {
        if (ev) ev.stopPropagation();
        var m = el('splitDropdownMenuMovInv'); if (!m) return;
        var p = el('almMovFechasPanel'); if (p) p.style.display = 'none';
        
        // Forma correcta de cerrar los custom-dropdowns de uicomponents.js
        if (typeof window.closeAllDropdowns === 'function') window.closeAllDropdowns();
        document.querySelectorAll('.custom-dropdown.active').forEach(d => d.classList.remove('active'));
        // Limpiar cualquier estilo inline residual
        document.querySelectorAll('.dropdown-content').forEach(d => d.style.display = '');

        m.style.display = (m.style.display === 'block') ? 'none' : 'block';
    };

    // Cerrar nuestros paneles personalizados si se abre un .custom-dropdown estándar
    document.addEventListener('click', function (e) {
        if (e.target.closest('.dropdown-trigger')) {
            var p = el('almMovFechasPanel'); if (p) p.style.display = 'none';
            var m = el('splitDropdownMenuMovInv'); if (m) m.style.display = 'none';
        }
    }, true);
    document.addEventListener('click', function (e) {
        var m = el('splitDropdownMenuMovInv');
        if (m && m.style.display === 'block' && !e.target.closest('#splitDropdownMenuMovInv') && !e.target.closest('#btnAccionesMov')) m.style.display = 'none';
    });
})();
</script>

{{-- ═════════════════════════════════════════════════════════════════
     MODAL: GENERAR NOTA DE ENTREGA POR CÓDIGO
     Ingresa NE-YYYY-NNNN y abre el PDF en una pestaña nueva.
     Patrón calcado del modal "Reimprimir Acta" de /admin/movilizaciones.
═════════════════════════════════════════════════════════════════ --}}
<div id="generarNotaOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);backdrop-filter:blur(3px);z-index:10000;align-items:center;justify-content:center;padding:20px;"
     onclick="if(event.target===this) window.closeGenerarNotaModal()">
    <div style="background:white;width:100%;max-width:440px;border-radius:16px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.35);overflow:hidden;animation:notaIn 0.22s cubic-bezier(0.16,1,0.3,1);">
        <div style="background:#1e293b;padding:10px 16px;color:white;position:relative;">
            <div style="display:flex;flex-direction:column;align-items:center;gap:2px;text-align:center;">
                <div style="display:flex;align-items:center;gap:6px;">
                    <i class="material-icons" style="font-size:20px;">print</i>
                    <h2 style="margin:0;font-size:15px;font-weight:800;">Generar Nota de Entrega</h2>
                </div>
                <p style="margin:0;font-size:12px;opacity:0.85;">Busca por N° de Nota (NE-YYYY-NNNN)</p>
            </div>
            <button type="button" onclick="window.closeGenerarNotaModal()" aria-label="Cerrar"
                style="background:rgba(255,255,255,0.15);border:none;color:white;width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;position:absolute;top:10px;right:12px;">
                <i class="material-icons" style="font-size:16px;">close</i>
            </button>
        </div>
        <div style="padding:16px 20px;display:flex;flex-direction:column;gap:12px;">
            <div>
                <label for="generarNotaInput" style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;">
                    <i class="material-icons" style="font-size:14px;vertical-align:middle;margin-right:2px;color:#1e293b;">tag</i>
                    N° de Nota
                </label>
                <div id="generarNotaBox"
                     style="display:flex;align-items:center;border:2px solid #e2e8f0;border-radius:8px;background:white;overflow:hidden;transition:border-color 0.2s,box-shadow 0.2s;">
                    <i class="material-icons" style="padding:0 8px;color:#94a3b8;font-size:18px;flex-shrink:0;">search</i>
                    <input type="text" id="generarNotaInput" placeholder="Ej: NE-{{ date('Y') }}-0001" autocomplete="off"
                        style="flex:1;border:none;outline:none;padding:8px 6px;font-size:13px;background:transparent;letter-spacing:0.5px;text-transform:uppercase;"
                        onkeydown="if(event.key==='Enter'){event.preventDefault(); window.submitGenerarNota();}">
                </div>
            </div>
            <div id="generarNotaFeedback" style="display:none;padding:8px 10px;border-radius:8px;font-size:12px;font-weight:600;"></div>
            <div style="display:flex;gap:10px;justify-content:center;margin-top:2px;">
                <button type="button" onclick="window.closeGenerarNotaModal()"
                    style="padding:8px 16px;border-radius:8px;border:1px solid #e2e8f0;background:white;color:#475569;font-size:13px;font-weight:700;cursor:pointer;">
                    Cancelar
                </button>
                <button type="button" id="generarNotaSubmitBtn" onclick="window.submitGenerarNota()"
                    style="padding:8px 16px;border-radius:8px;border:none;background:#1e293b;color:white;font-size:13px;font-weight:800;cursor:pointer;display:flex;align-items:center;gap:6px;">
                    <i class="material-icons" style="font-size:16px;">file_download</i> Generar
                </button>
            </div>
        </div>
    </div>
</div>

@can('super.admin')
{{-- ═════════════════════════════════════════════════════════════════
     MODAL: ELIMINAR NOTA DE ENTREGA POR CÓDIGO  (super.admin only)
     Ingresa NE-YYYY-NNNN → confirma → DELETE → reversa stock.
═════════════════════════════════════════════════════════════════ --}}
<div id="eliminarNotaOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);backdrop-filter:blur(3px);z-index:10000;align-items:center;justify-content:center;padding:20px;"
     onclick="if(event.target===this) window.closeEliminarNotaModal()">
    <div style="background:white;width:100%;max-width:460px;border-radius:16px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.35);overflow:hidden;animation:notaIn 0.22s cubic-bezier(0.16,1,0.3,1);">
        <div style="background:#b91c1c;padding:10px 16px;color:white;position:relative;">
            <div style="display:flex;flex-direction:column;align-items:center;gap:2px;text-align:center;">
                <div style="display:flex;align-items:center;gap:6px;">
                    <i class="material-icons" style="font-size:20px;">delete_forever</i>
                    <h2 style="margin:0;font-size:15px;font-weight:800;">Eliminar Nota de Entrega</h2>
                </div>
                <p style="margin:0;font-size:12px;opacity:0.9;">El stock se devuelve a su almacén — irreversible</p>
            </div>
            <button type="button" onclick="window.closeEliminarNotaModal()" aria-label="Cerrar"
                style="background:rgba(255,255,255,0.15);border:none;color:white;width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;position:absolute;top:10px;right:12px;">
                <i class="material-icons" style="font-size:16px;">close</i>
            </button>
        </div>
        <div style="padding:16px 20px;display:flex;flex-direction:column;gap:12px;">
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;color:#7f1d1d;font-size:12px;font-weight:600;line-height:1.45;">
                Esta acción <b>reversa el stock</b> de cada línea de la Nota (registra una
                <b>ENTRADA</b> inversa en el kardex) y deja la Nota como eliminada — los
                movimientos originales se conservan para auditoría pero ya no se podrán
                reimprimir.
            </div>
            <div>
                <label for="eliminarNotaInput" style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:4px;">
                    <i class="material-icons" style="font-size:14px;vertical-align:middle;margin-right:2px;color:#b91c1c;">tag</i>
                    N° de Nota a eliminar
                </label>
                <div id="eliminarNotaBox"
                     style="display:flex;align-items:center;border:2px solid #e2e8f0;border-radius:8px;background:white;overflow:hidden;transition:border-color 0.2s,box-shadow 0.2s;">
                    <i class="material-icons" style="padding:0 8px;color:#94a3b8;font-size:18px;flex-shrink:0;">search</i>
                    <input type="text" id="eliminarNotaInput" placeholder="Ej: NE-{{ date('Y') }}-0001" autocomplete="off"
                        style="flex:1;border:none;outline:none;padding:8px 6px;font-size:13px;background:transparent;letter-spacing:0.5px;text-transform:uppercase;"
                        onkeydown="if(event.key==='Enter'){event.preventDefault(); window.submitEliminarNota();}">
                </div>
            </div>
            <div id="eliminarNotaFeedback" style="display:none;padding:8px 10px;border-radius:8px;font-size:12px;font-weight:600;"></div>
            <div style="display:flex;gap:10px;justify-content:center;margin-top:2px;">
                <button type="button" onclick="window.closeEliminarNotaModal()"
                    style="padding:8px 16px;border-radius:8px;border:1px solid #e2e8f0;background:white;color:#475569;font-size:13px;font-weight:700;cursor:pointer;">
                    Cancelar
                </button>
                <button type="button" id="eliminarNotaSubmitBtn" onclick="window.submitEliminarNota()"
                    style="padding:8px 16px;border-radius:8px;border:none;background:#b91c1c;color:white;font-size:13px;font-weight:800;cursor:pointer;display:flex;align-items:center;gap:6px;">
                    <i class="material-icons" style="font-size:16px;">delete_forever</i> Eliminar Nota
                </button>
            </div>
        </div>
    </div>
</div>
@endcan

<style>
@keyframes notaIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
#generarNotaBox:focus-within { border-color:#1e293b; box-shadow:0 0 0 3px rgba(30,41,59,0.15); }
#eliminarNotaBox:focus-within { border-color:#b91c1c; box-shadow:0 0 0 3px rgba(185,28,28,0.15); }
</style>

<script>
(function(){
    // Bandera por instancia: si el modal ya tiene listeners (SPA: misma vista re-montada)
    // no duplicar handlers de Escape ni redefinir funciones.
    if (window._almNotaModalsReady) return;
    window._almNotaModalsReady = true;

    var CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    var URL_BUSCAR   = @json(route('almacen.nota-entrega.buscar'));
    var URL_DESTROY  = @json(route('almacen.nota-entrega.destroy'));

    function fb(id, type, msg) {
        var el = document.getElementById(id); if (!el) return;
        var colors = {
            info:    { bg:'#e0f2fe', border:'#bae6fd', color:'#075985' },
            error:   { bg:'#fee2e2', border:'#fecaca', color:'#b91c1c' },
            success: { bg:'#dcfce7', border:'#bbf7d0', color:'#15803d' },
        };
        var c = colors[type] || colors.info;
        el.style.cssText = 'display:block;padding:10px 12px;border-radius:8px;font-size:12.5px;font-weight:600;background:' + c.bg + ';border:1px solid ' + c.border + ';color:' + c.color + ';';
        el.textContent = msg;
    }

    // ── Generar Nota ────────────────────────────────────────────────────
    window.openGenerarNotaModal = function () {
        var ov = document.getElementById('generarNotaOverlay'); if (!ov) return;
        ov.style.display = 'flex';
        var i = document.getElementById('generarNotaInput');
        if (i) { i.value = ''; setTimeout(function(){ i.focus(); }, 80); }
        var elFb = document.getElementById('generarNotaFeedback'); if (elFb) elFb.style.display = 'none';
        var box = document.getElementById('generarNotaBox'); if (box) box.style.borderColor = '#e2e8f0';
        document.body.style.overflow = 'hidden';
    };
    window.closeGenerarNotaModal = function () {
        var ov = document.getElementById('generarNotaOverlay'); if (ov) ov.style.display = 'none';
        document.body.style.overflow = '';
    };
    window.submitGenerarNota = async function () {
        var input = document.getElementById('generarNotaInput');
        var raw = (input && input.value || '').trim().toUpperCase();
        if (!raw) {
            var box = document.getElementById('generarNotaBox'); if (box) box.style.borderColor = '#ef4444';
            fb('generarNotaFeedback', 'error', 'Ingresa un N° de Nota para continuar.');
            if (input) input.focus(); return;
        }
        // Pre-abrimos pestaña dentro del gesto del usuario (mismo truco que en el modal de
        // salida del inventario) — si esperamos al fetch, los pop-up blockers la rechazan.
        var pdfTab = window.open('about:blank', '_blank');

        var btn = document.getElementById('generarNotaSubmitBtn');
        var html = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="material-icons" style="font-size:17px;animation:spin 1s linear infinite;">sync</i> Buscando...'; }
        fb('generarNotaFeedback', 'info', 'Buscando la nota ' + raw + '…');
        try {
            var r = await fetch(URL_BUSCAR + '?numero=' + encodeURIComponent(raw), {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (r.status === 404) {
                if (pdfTab) { try { pdfTab.close(); } catch (e) {} }
                fb('generarNotaFeedback', 'error', 'No se encontró ninguna Nota con ese N°.');
                var box2 = document.getElementById('generarNotaBox'); if (box2) box2.style.borderColor = '#ef4444';
                return;
            }
            if (!r.ok) {
                if (pdfTab) { try { pdfTab.close(); } catch (e) {} }
                var err = await r.json().catch(function(){ return {}; });
                fb('generarNotaFeedback', 'error', err.message || ('Error del servidor (' + r.status + ').'));
                return;
            }
            var data = await r.json();
            if (!data.success || !data.url) {
                if (pdfTab) { try { pdfTab.close(); } catch (e) {} }
                fb('generarNotaFeedback', 'error', data.message || 'Respuesta inválida del servidor.');
                return;
            }
            fb('generarNotaFeedback', 'success', 'Nota encontrada. Abriendo PDF…');
            if (pdfTab) { try { pdfTab.location.href = data.url; } catch (e) {} }
            setTimeout(function(){ window.closeGenerarNotaModal(); }, 600);
        } catch (err) {
            if (pdfTab) { try { pdfTab.close(); } catch (e) {} }
            console.error('[Generar Nota]', err);
            fb('generarNotaFeedback', 'error', 'No se pudo contactar al servidor.');
        } finally {
            if (btn) { btn.disabled = false; btn.innerHTML = html; }
        }
    };

    // ── Eliminar Nota ───────────────────────────────────────────────────
    // Sólo se monta si el modal existe (super.admin gateado por blade {{'@'}}can).
    if (document.getElementById('eliminarNotaOverlay')) {
        window.openEliminarNotaModal = function () {
            var ov = document.getElementById('eliminarNotaOverlay'); if (!ov) return;
            ov.style.display = 'flex';
            var i = document.getElementById('eliminarNotaInput');
            if (i) { i.value = ''; setTimeout(function(){ i.focus(); }, 80); }
            var elFb = document.getElementById('eliminarNotaFeedback'); if (elFb) elFb.style.display = 'none';
            var box = document.getElementById('eliminarNotaBox'); if (box) box.style.borderColor = '#e2e8f0';
            document.body.style.overflow = 'hidden';
        };
        window.closeEliminarNotaModal = function () {
            var ov = document.getElementById('eliminarNotaOverlay'); if (ov) ov.style.display = 'none';
            document.body.style.overflow = '';
        };
        window.submitEliminarNota = async function () {
            var input = document.getElementById('eliminarNotaInput');
            var raw = (input && input.value || '').trim().toUpperCase();
            if (!raw) {
                var box = document.getElementById('eliminarNotaBox'); if (box) box.style.borderColor = '#ef4444';
                fb('eliminarNotaFeedback', 'error', 'Ingresa un N° de Nota para continuar.');
                if (input) input.focus(); return;
            }
            // Confirmación explícita: la acción es destructiva, no se puede deshacer.
            if (!window.confirm('¿Eliminar la Nota ' + raw + ' y devolver su stock al almacén?\n\nEsta acción no se puede deshacer.')) return;

            var btn = document.getElementById('eliminarNotaSubmitBtn');
            var html = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="material-icons" style="font-size:17px;animation:spin 1s linear infinite;">sync</i> Eliminando...'; }
            fb('eliminarNotaFeedback', 'info', 'Eliminando la nota ' + raw + ' y reversando stock…');
            try {
                var r = await fetch(URL_DESTROY + '?numero=' + encodeURIComponent(raw), {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                });
                var data = await r.json().catch(function(){ return {}; });
                if (!r.ok) {
                    fb('eliminarNotaFeedback', 'error', data.message || ('Error del servidor (' + r.status + ').'));
                    return;
                }
                fb('eliminarNotaFeedback', 'success', data.message || 'Nota eliminada y stock revertido.');
                // Recargar la tabla de movimientos para que aparezcan las ENTRADAS reversa.
                if (window.loadMovimientos) window.loadMovimientos();
                setTimeout(function(){ window.closeEliminarNotaModal(); }, 800);
            } catch (err) {
                console.error('[Eliminar Nota]', err);
                fb('eliminarNotaFeedback', 'error', 'No se pudo contactar al servidor.');
            } finally {
                if (btn) { btn.disabled = false; btn.innerHTML = html; }
            }
        };
    }

    // Escape cierra cualquiera de los dos modales abierto.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var g = document.getElementById('generarNotaOverlay');
        var x = document.getElementById('eliminarNotaOverlay');
        if (g && g.style.display === 'flex') window.closeGenerarNotaModal();
        if (x && x.style.display === 'flex' && window.closeEliminarNotaModal) window.closeEliminarNotaModal();
    });
})();
</script>
@endsection
