@extends('layouts.estructura_base')

@section('title', 'Bitácora por Nota de Entrega')

@section('content')
@php
    // `idAlmacenActivo` lo provee el controller (incluyendo el default-merge por frente).
    $reqAlmacen = $idAlmacenActivo ?? null;
    $reqTipo    = request('tipo');
    $reqFrente  = request('id_frente');
    $reqSearch  = request('search');
    $reqDesde   = request('desde');
    $reqHasta   = request('hasta');
    $reqCat     = request('categoria');
    $hayAdv     = $reqDesde || $reqHasta || ($reqCat && $reqCat !== 'all') || $reqTipo;
    $almSel     = $reqAlmacen ? ($almacenes ?? collect())->firstWhere('ID_ALMACEN', (int) $reqAlmacen) : null;
    $tipos = [
        'ENTRADAS'  => ['label' => 'Entradas', 'sub' => ''],
        'SALIDAS'   => ['label' => 'Salidas', 'sub' => ''],
        'AUDITORIA' => ['label' => 'Auditoría', 'sub' => ''],
    ];
    $tipoSelLabel = ($reqTipo && isset($tipos[$reqTipo])) ? $tipos[$reqTipo]['label'] . ($tipos[$reqTipo]['sub'] ? ' ' . $tipos[$reqTipo]['sub'] : '') : null;
    $frenteSel    = ($reqFrente && $reqFrente !== 'all') ? ($frentesLista ?? collect())->firstWhere('ID_FRENTE', (int) $reqFrente) : null;
    $catSel       = ($reqCat && $reqCat !== 'all') ? $reqCat : null;

    // URL para la "vista por producto" (regresa al kardex original) conservando filtros.
    $backParams = array_filter([
        'id_almacen' => $reqAlmacen,
        'id_frente'  => $reqFrente,
        'desde'      => $reqDesde,
        'hasta'      => $reqHasta,
        'categoria'  => $reqCat,
        // 'tipo' del kardex es libre; no lo trasladamos para no acotarlo a SALIDA.
    ], fn ($v) => $v !== null && $v !== '');
@endphp

<section class="page-title-card" style="text-align:left;margin:0 0 10px 0;">
    <div style="display:flex;justify-content:flex-start;align-items:center;gap:20px;flex-wrap:wrap;">
        <h1 class="page-title" style="margin:0;">
            <span class="page-title-line2" style="color:#000;">Bitácora por Nota de Entrega</span>
        </h1>
        <span aria-hidden="true" style="display:inline-block;width:1px;height:34px;background:#cbd5e0;flex:0 0 auto;"></span>
        <div style="display:flex;align-items:center;gap:10px;flex:0 1 auto;">
            <div style="width:280px;min-width:200px;max-width:100%;">
                <div class="custom-dropdown" id="almNotFiltroAlmacen" data-filter-type="id_almacen" data-default-label="Todos los almacenes">
                    <input type="hidden" name="id_almacen" data-filter-value value="{{ $reqAlmacen ?: '' }}">
                    <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:#f8fafc;overflow:hidden;border:1px solid #cbd5e0;border-radius:10px;height:40px;transition:border-color .15s,background .15s;">
                        <span style="padding:0 10px;display:flex;align-items:center;color:#0067b1;"><i class="material-icons" style="font-size:18px;transform:none !important;">warehouse</i></span>
                        <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                               placeholder="{{ $almSel ? $almSel->NOMBRE : 'Todos los almacenes' }}"
                               style="flex:1;border:none;background:transparent;padding:8px 5px;font-size:13.5px;font-weight:600;color:#0f172a;outline:none;min-width:0;"
                               oninput="window.filterDropdownOptions(this)">
                    </div>
                    <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                        <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                            <div class="dropdown-item {{ !$almSel ? 'selected' : '' }}" data-value="all" onclick="selectOption('almNotFiltroAlmacen','all','TODOS LOS ALMACENES');">TODOS LOS ALMACENES</div>
                            @foreach(($almacenes ?? collect()) as $a)
                                <div class="dropdown-item {{ $almSel && $almSel->ID_ALMACEN == $a->ID_ALMACEN ? 'selected' : '' }}" data-value="{{ $a->ID_ALMACEN }}"
                                     onclick="selectOption('almNotFiltroAlmacen','{{ $a->ID_ALMACEN }}','{{ addslashes($a->NOMBRE) }}');">
                                    {{ $a->NOMBRE }}
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
    #almNotFilters { display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin-bottom:8px; }
    #almNotFilters .anf-item { flex:1.5 1 280px; min-width:200px; max-width:420px; }
    #almNotFilters .anf-search { flex:2 1 280px; max-width:none; }
    #almNotFilters .custom-dropdown { width:100%; }
    #almNotFiltroAlmacen .dropdown-trigger.filter-active {
        background: #f8fafc !important; border-color: #cbd5e0 !important;
    }
    .anf-search-box { display:flex; align-items:center; height:45px; border:1px solid #cbd5e0; border-radius:12px; background:#fbfcfd; overflow:hidden; }
    .anf-search-box.active { border-color:var(--maquinaria-blue,#0067b1); background:#e1effa; }
    @media (min-width: 1025px) {
        body:has(.alm-not-table) .page-layout-grid { grid-template-columns: minmax(0, 1fr) 340px; gap: 28px; }
    }
    .anf-search-box i.lupa { padding:0 10px; color:#64748b; font-size:18px; }
    .anf-search-box input { flex:1; border:none; background:transparent; outline:none; padding:10px 5px; font-size:14px; min-width:0; }
    .anf-search-box i.clr { padding:0 10px; color:#64748b; font-size:18px; cursor:pointer; }
    .anf-adv-btn { height:45px; width:45px; padding:0; display:flex; align-items:center; justify-content:center; border-radius:12px; box-shadow:none; }

    .alm-not-table { width:100%; border-collapse:separate; border-spacing:0; font-size:14px; color:#000; }
    .alm-not-table thead tr { background:#1e293b; color:#fff; }
    .alm-not-table thead th {
        text-align:center; color:#fff; font-size:13px; font-weight:700;
        text-transform:uppercase; letter-spacing:1px;
        padding:10px 12px; border-bottom:2px solid #0f172a;
        white-space:nowrap;
    }
    .alm-not-table tbody td {
        padding:12px 12px; color:#334155; font-size:13px; font-weight:600;
        text-align:center; vertical-align:middle;
        border-bottom:1px solid #e2e8f0;
    }
    /* Hover sutil de filas — solo cambio de fondo, sin cursor:pointer (la fila
       no es clickeable; el unico disparador del PDF es el boton de su columna). */
    .alm-not-table tbody tr:hover td { background:#e0f2fe; }

    /* Botón "Ver PDF" — calcado del botón de documentos del modal "Detalles" de
       /admin/equipos (resources/views/admin/equipos/partials/form_fields.blade.php):
       cuadrado 30×30 con gradiente azul oscuro → azul brand, icono `description`
       blanco. Mismo box-shadow azul tenue para el efecto "elevado". */
    .anf-pdf-btn {
        display:inline-flex; align-items:center; justify-content:center;
        width:30px; height:30px; border-radius:7px;
        background:linear-gradient(135deg,#1e3a5f,#2563eb);
        box-shadow:0 2px 6px rgba(37,99,235,0.35);
        border:none; padding:0; cursor:pointer;
        text-decoration:none; transition:transform .12s, box-shadow .15s;
    }
    .anf-pdf-btn:hover {
        transform:translateY(-1px);
        box-shadow:0 4px 10px rgba(37,99,235,0.45);
    }
    .anf-pdf-btn .material-icons { font-size:17px; color:#fff; }
    /* Cuando la fila esta en hover, el fondo azul claro de la celda no debe
       contagiar al boton azul oscuro (mantiene su gradient propio). */
    .alm-not-table tbody tr:hover .anf-pdf-btn { background:linear-gradient(135deg,#1e3a5f,#2563eb); }
    .anf-empty { padding:50px 20px; text-align:center; color:#94a3b8; }
    .anf-empty i { font-size:46px; color:#cbd5e0; display:block; margin:0 auto 8px; }

    .anf-stat-pill { display:none; }
    @media (max-width: 900px) {
        #almNotFilters .anf-item { max-width:none; flex:1 1 100%; }
        #almNotFilters .anf-search { max-width:none; flex:1 1 0; min-width:0; }
        .anf-stat-pill { display:inline-flex; align-items:center; gap:6px; background:#f1f5f9; border-radius:999px; padding:6px 12px; font-size:13px; font-weight:700; color:#334155; margin-bottom:8px; }
        .anf-stat-pill i { font-size:16px; color:#0369a1; }
    }
    @media (max-width: 768px) {
        .page-title-card .page-title { display:none !important; }
        .page-title-card > div > span[aria-hidden="true"] { display:none !important; }
        .counter-sidebar { display:none !important; }
        .anf-stat-pill { display:none !important; }
        .page-title-card > div { flex-direction:column !important; align-items:stretch !important; gap:10px !important; }
        .page-title-card > div > div { width:100% !important; flex:1 1 100% !important; }
        .page-title-card > div > div > div[style*="width:280px"] { width:100% !important; min-width:0 !important; max-width:100% !important; }
        #almNotFilters { gap:8px !important; }
        #almNotFilters .anf-item { flex:1 1 100% !important; max-width:none !important; }
        #almNotFilters .anf-search { flex:1 1 0 !important; min-width:0 !important; max-width:none !important; }
        .anf-btn-ver { flex:1 1 100% !important; margin-left:0 !important; width:100% !important; }
        .anf-btn-ver > a { width:100% !important; justify-content:center !important; box-sizing:border-box !important; }
        .alm-not-table thead { display:none !important; }
        .alm-not-table { display:block !important; border:none !important; }
        .alm-not-table tbody { display:flex !important; flex-direction:column !important; gap:10px !important; }
        .alm-not-table tbody tr {
            display:grid !important;
            grid-template-columns:1fr auto auto;
            grid-template-rows:auto auto;
            grid-template-areas:
                "nota   fecha  pdf"
                "destino destino destino";
            column-gap:8px; row-gap:0;
            background:#fff !important; border:1px solid #cbd5e1 !important;
            border-radius:12px !important; padding:10px 14px 0 14px !important;
            box-shadow:0 1px 4px rgba(0,0,0,0.05);
            overflow:hidden !important;
        }
        .alm-not-table tbody td { border:none !important; padding:0 !important; text-align:left !important; }
        /* Fecha + tipo pill */
        .alm-not-table tbody td:nth-child(1) {
            grid-area:fecha; font-size:10.5px; color:#94a3b8; font-weight:500;
            display:flex !important; flex-direction:column; align-items:flex-end; justify-content:center; gap:3px;
        }
        /* N° Nota */
        .alm-not-table tbody td:nth-child(2) {
            grid-area:nota; font-size:12px; font-weight:700; color:#0f172a; align-self:center;
        }
        /* Origen (oculto en móvil — la info está en el destino) */
        .alm-not-table tbody td:nth-child(3) { display:none !important; }
        /* Destino: banda gris al pie (mismo patrón que movimientos) */
        .alm-not-table tbody td:nth-child(4) {
            grid-area:destino;
            font-size:10.5px; font-weight:700; color:#3730a3;
            background:#f1f5f9 !important;
            border-radius:0 0 10px 10px !important;
            padding:7px 14px !important;
            margin:8px -14px 0 -14px !important;
            display:flex !important; align-items:center; gap:4px;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
        }
        .alm-not-table tbody td:nth-child(4)::before {
            content:"location_on"; font-family:'Material Icons'; font-size:13px; color:#64748b; font-weight:normal; flex-shrink:0;
        }
        /* PDF */
        .alm-not-table tbody td:nth-child(5) { grid-area:pdf; justify-self:end; align-self:center; }
        .alm-not-table tbody tr:hover td { background:transparent !important; }
        .alm-not-table tbody tr:hover td:nth-child(4) { background:#f1f5f9 !important; }
        div[style*="overflow-x:auto"] { border:none !important; border-radius:0 !important; overflow:visible !important; }
    }
</style>

<div class="page-layout-grid">
<div class="admin-card" style="margin:0;min-height:70vh;min-width:0;width:100%;padding:14px;">

    <div class="anf-stat-pill"><i class="material-icons">description</i> <span>{{ $total }}</span> notas</div>

    {{-- ── Filtros ── --}}
    <div id="almNotFilters">
        {{-- 1. Frente --}}
        <div class="anf-item">
            <div class="custom-dropdown" id="almNotFiltroFrente" data-filter-type="id_frente" data-default-label="Todos los frentes">
                <input type="hidden" name="id_frente" data-filter-value value="{{ $reqFrente && $reqFrente !== 'all' ? $reqFrente : '' }}">
                <div class="dropdown-trigger {{ $frenteSel ? 'filter-active' : '' }}" style="padding:0;display:flex;align-items:center;background:#fbfcfd;overflow:hidden;border:1px solid #cbd5e0;border-radius:12px;height:45px;">
                    <span style="padding:0 10px;display:flex;align-items:center;color:var(--maquinaria-gray-text);"><i class="material-icons" style="font-size:18px;transform:none !important;">search</i></span>
                    <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                           placeholder="{{ $frenteSel ? $frenteSel->NOMBRE_FRENTE : 'Todos los frentes' }}"
                           style="flex:1;border:none;background:transparent;padding:10px 5px;font-size:14px;outline:none;min-width:0;"
                           oninput="window.filterDropdownOptions(this)">
                    <i class="material-icons" data-clear-btn style="padding:0 5px;color:var(--maquinaria-gray-text);font-size:18px;display:{{ $frenteSel ? 'block' : 'none' }};cursor:pointer;transform:none !important;"
                       onclick="event.stopPropagation(); clearDropdownFilter('almNotFiltroFrente');">close</i>
                </div>
                <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                    <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                        <div class="dropdown-item {{ !$frenteSel ? 'selected' : '' }}" data-value="all" onclick="selectOption('almNotFiltroFrente','all','TODOS LOS FRENTES');">TODOS LOS FRENTES</div>
                        @foreach(($frentesLista ?? collect()) as $f)
                            <div class="dropdown-item {{ $frenteSel && $frenteSel->ID_FRENTE == $f->ID_FRENTE ? 'selected' : '' }}" data-value="{{ $f->ID_FRENTE }}"
                                 onclick="selectOption('almNotFiltroFrente','{{ $f->ID_FRENTE }}','{{ addslashes($f->NOMBRE_FRENTE) }}');">{{ $f->NOMBRE_FRENTE }}</div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- 2. Buscar N° de Nota --}}
        <div class="anf-item anf-search">
            <div class="anf-search-box {{ $reqSearch ? 'active' : '' }}">
                <i class="material-icons lupa">search</i>
                <input type="text" id="almNotSearch" autocomplete="off" placeholder="Nota de entrega…" value="{{ $reqSearch }}"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();window.loadNotas();}">
                <i class="material-icons clr" id="almNotSearchClear" style="display:{{ $reqSearch ? 'block' : 'none' }};"
                   onclick="document.getElementById('almNotSearch').value=''; this.style.display='none'; window.loadNotas();">close</i>
            </div>
        </div>

        {{-- 3. Filtros Avanzados (al lado del buscador) --}}
        <div style="position:relative;flex:0 0 auto;">
            <button type="button" id="btnAdvancedFilterNot" class="btn-primary-maquinaria anf-adv-btn" title="Filtros Avanzados"
                    style="background:{{ $hayAdv ? '#fee2e2' : '#fff' }};border:1px solid {{ $hayAdv ? '#ef4444' : '#cbd5e0' }};color:{{ $hayAdv ? '#ef4444' : '#64748b' }};box-shadow:none;"
                    onclick="window.almNotToggleFechas(event)">
                <i class="material-icons">filter_list</i>
            </button>
            <div id="almNotFechasPanel" style="display:none;position:absolute;top:100%;right:0;width:360px;max-width:calc(100vw - 20px);background:#e2e8f0;border:1px solid #cbd5e1;border-radius:12px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.15);z-index:100;margin-top:10px;padding:15px;">
                <h4 style="margin:0 0 15px 0;font-size:14px;font-weight:700;color:#334155;display:flex;justify-content:space-between;align-items:center;">
                    Filtros Avanzados
                    <span style="font-size:11px;color:#64748b;font-weight:400;text-decoration:underline;cursor:pointer;" onclick="window.almNotLimpiarFechas(event)">Limpiar Todo</span>
                </h4>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;align-items:start;">
                  <div style="min-width:0;">
                    <span style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:4px;">Tipo</span>
                    <div class="custom-dropdown" id="almNotFiltroTipo" data-filter-type="tipo" data-default-label="Todos los tipos">
                        <input type="hidden" name="tipo" data-filter-value value="{{ $reqTipo && isset($tipos[$reqTipo]) ? $reqTipo : '' }}">
                        <div class="dropdown-trigger {{ $tipoSelLabel ? 'filter-active' : '' }}" style="padding:0;display:flex;align-items:center;background:{{ $tipoSelLabel ? '#e1effa' : '#fff' }};overflow:hidden;border:1px solid #cbd5e0;border-radius:8px;height:32px;">
                            <span style="padding:0 6px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:14px;transform:none !important;">search</i></span>
                            <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                                   placeholder="{{ $tipoSelLabel ?: 'Todos' }}"
                                   style="flex:1;border:none;background:transparent;padding:0 4px;font-size:12px;color:#0f172a;outline:none;min-width:0;"
                                   oninput="window.filterDropdownOptions(this)">
                            <i class="material-icons" data-clear-btn style="padding:0 6px;color:#64748b;font-size:16px;display:{{ $tipoSelLabel ? 'block' : 'none' }};cursor:pointer;transform:none !important;"
                               onclick="event.stopPropagation(); clearDropdownFilter('almNotFiltroTipo');">close</i>
                        </div>
                        <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                            <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                                @foreach($tipos as $k => $t)
                                    <div class="dropdown-item {{ $reqTipo === $k ? 'selected' : '' }}" data-value="{{ $k }}" onclick="selectOption('almNotFiltroTipo','{{ $k }}','{{ addslashes($t['label']) }}');">{{ $t['label'] }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                  </div>
                  <div style="min-width:0;">
                    <span style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:4px;">Categoría</span>
                    <div class="custom-dropdown" id="almNotFiltroCat" data-filter-type="categoria" data-default-label="Todas las categorías">
                        <input type="hidden" name="categoria" data-filter-value value="{{ $catSel ?: '' }}">
                        <div class="dropdown-trigger {{ $catSel ? 'filter-active' : '' }}" style="padding:0;display:flex;align-items:center;background:{{ $catSel ? '#e1effa' : 'white' }};overflow:hidden;border:1px solid #e2e8f0;border-radius:8px;height:32px;">
                            <span style="padding:0 6px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:14px;transform:none !important;">search</i></span>
                            <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                                   placeholder="{{ $catSel ?: 'Todas' }}"
                                   style="flex:1;border:none;background:transparent;padding:0 4px;font-size:12px;outline:none;min-width:0;color:#334155;"
                                   oninput="window.filterDropdownOptions(this)">
                            <i class="material-icons" data-clear-btn style="padding:0 6px;color:#64748b;font-size:14px;display:{{ $catSel ? 'inline-flex' : 'none' }};cursor:pointer;transform:none !important;"
                               onclick="event.stopPropagation(); clearDropdownFilter('almNotFiltroCat');">close</i>
                        </div>
                        <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                            <div class="dropdown-item-list" style="max-height:200px;overflow-y:auto;">
                                <div class="dropdown-item {{ !$catSel ? 'selected' : '' }}" data-value="all" onclick="selectOption('almNotFiltroCat','all','TODAS LAS CATEGORÍAS');">TODAS LAS CATEGORÍAS</div>
                                @foreach(($categorias ?? collect()) as $c)
                                    <div class="dropdown-item {{ $catSel === $c ? 'selected' : '' }}" data-value="{{ $c }}"
                                         onclick="selectOption('almNotFiltroCat','{{ addslashes($c) }}','{{ addslashes($c) }}');">{{ $c }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                  </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    <div>
                        <span style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:4px;">Desde</span>
                        <div id="almNotDesdeBox" style="display:flex;align-items:center;background:{{ $reqDesde ? '#e1effa' : 'white' }};border:1px solid #e2e8f0;border-radius:6px;height:32px;padding:0 6px;cursor:pointer;"
                             onclick="var i=document.getElementById('almNotDesde'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                            <i class="material-icons" style="font-size:16px;color:#94a3b8;margin-right:4px;pointer-events:none;">event</i>
                            <input type="date" id="almNotDesde" value="{{ $reqDesde }}" onchange="window.loadNotas()"
                                   style="flex:1;min-width:0;border:none;background:transparent;padding:6px 2px;font-size:12px;outline:none;color:#334155;cursor:pointer;">
                            <i class="material-icons" id="almNotDesdeClear"
                               style="display:{{ $reqDesde ? 'inline-flex' : 'none' }};font-size:14px;color:#64748b;cursor:pointer;padding:2px;border-radius:50%;"
                               onclick="event.stopPropagation(); var i=document.getElementById('almNotDesde'); if(i){ i.value=''; } this.style.display='none'; document.getElementById('almNotDesdeBox').style.background='white'; window.loadNotas();">close</i>
                        </div>
                    </div>
                    <div>
                        <span style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:4px;">Hasta</span>
                        <div id="almNotHastaBox" style="display:flex;align-items:center;background:{{ $reqHasta ? '#e1effa' : 'white' }};border:1px solid #e2e8f0;border-radius:6px;height:32px;padding:0 6px;cursor:pointer;"
                             onclick="var i=document.getElementById('almNotHasta'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                            <i class="material-icons" style="font-size:16px;color:#94a3b8;margin-right:4px;pointer-events:none;">event</i>
                            <input type="date" id="almNotHasta" value="{{ $reqHasta }}" onchange="window.loadNotas()"
                                   style="flex:1;min-width:0;border:none;background:transparent;padding:6px 2px;font-size:12px;outline:none;color:#334155;cursor:pointer;">
                            <i class="material-icons" id="almNotHastaClear"
                               style="display:{{ $reqHasta ? 'inline-flex' : 'none' }};font-size:14px;color:#64748b;cursor:pointer;padding:2px;border-radius:50%;"
                               onclick="event.stopPropagation(); var i=document.getElementById('almNotHasta'); if(i){ i.value=''; } this.style.display='none'; document.getElementById('almNotHastaBox').style.background='white'; window.loadNotas();">close</i>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class="anf-btn-ver" style="margin-left:auto;flex:0 0 auto;">
            <a href="{{ route('almacen.movimientos', $backParams) }}"
               class="btn-primary-maquinaria"
               style="padding:0 15px;height:45px;display:inline-flex;align-items:center;gap:8px;text-decoration:none;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);"
               onclick="event.preventDefault(); if(window.navigateTo) window.navigateTo(this.href); else window.location.href=this.href;">
                <span>Ver por producto</span>
            </a>
        </div>
    </div>

    {{-- ── Tabla ── 6 columnas: Fecha · N° de Nota · Tipo · Almacén origen ·
         Proyecto destino · PDF. Se quitaron "N° Líneas" y "Cant. total" (info
         que ya vive dentro de la propia Nota / PDF). --}}
    <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:12px;">
        <table class="alm-not-table">
            <thead>
                <tr>
                    <th style="width:130px;">Fecha</th>
                    <th style="width:170px;">N° de Nota</th>
                    <th>Almacén origen</th>
                    <th>Frente</th>
                    <th style="width:70px;">PDF</th>
                </tr>
            </thead>
            <tbody id="almNotTableBody">
                @include('admin.almacen.partials.notas_rows', ['notas' => $notas, 'almById' => $almById, 'freById' => $freById])
            </tbody>
        </table>
    </div>

    <div id="almNotPagination" style="margin-top:14px;">
        {{ $notas->links('vendor.pagination.custom-sliding') }}
    </div>

</div>

{{-- Sidebar: contador "Total Notas" + ranking "Consumo de Inventario" (mismo
     partial y mismas dimensiones que el sidebar del kardex en /admin/almacen/movimientos). --}}
<div class="counter-sidebar" style="position:sticky;top:20px;display:flex;flex-direction:column;gap:8px;">
    <div style="background:linear-gradient(135deg,#0c4a6e 0%,#0369a1 100%);border-radius:12px;padding:15px;color:#fff;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);position:relative;overflow:hidden;">
        <i class="material-icons" style="position:absolute;right:-12px;bottom:-12px;font-size:78px;opacity:0.12;transform:rotate(-12deg);">description</i>
        <div style="position:relative;z-index:2;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;opacity:0.9;margin-bottom:5px;">Total Notas</div>
            <div style="display:flex;align-items:baseline;gap:6px;">
                <span id="almNotTotalCount" style="font-size:32px;font-weight:800;line-height:1;letter-spacing:-1px;">{{ $total }}</span>
                <span style="font-size:12px;opacity:0.85;font-weight:500;">notas (según filtros)</span>
            </div>
        </div>
    </div>

    {{-- Ranking de Consumo de Inventario — mismo partial y mismas dimensiones que el
         sidebar de /admin/almacen/movimientos. Click en una fila lleva al kardex
         filtrado por ese producto (la fn almMovFiltrarPorProducto la define más
         abajo en este propio archivo para que el partial funcione cross-page). --}}
    <div style="background:white;border-radius:12px;padding:15px;border:1px solid #e2e8f0;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);overflow:hidden;">
        @include('admin.almacen.partials.consumo_stats', ['consumo' => $consumo])
    </div>
</div>

</div>{{-- /page-layout-grid --}}

<script>
(function () {
    'use strict';
    if (!document.getElementById('almNotTableBody')) return;

    function el(id) { return document.getElementById(id); }
    function hv(name) { var e = document.querySelector('input[name="' + name + '"][data-filter-value]'); return e ? String(e.value).trim() : ''; }

    window.loadNotas = async function (pageUrl) {
        var body = el('almNotTableBody');
        if (!body) return;

        if (window.showPreloader) window.showPreloader();
        body.style.opacity = '0.5';

        try {
            var p = new URLSearchParams();
            var alm = hv('id_almacen'); if (alm) p.set('id_almacen', alm);
            var tipo = hv('tipo'); if (tipo && tipo !== 'all') p.set('tipo', tipo);
            var fr = hv('id_frente'); if (fr && fr !== 'all') p.set('id_frente', fr);
            var cat = hv('categoria'); if (cat && cat !== 'all') p.set('categoria', cat);
            var s = el('almNotSearch'); if (s && s.value.trim()) p.set('search', s.value.trim());
            var d = el('almNotDesde'); if (d && d.value) p.set('desde', d.value);
            var h = el('almNotHasta'); if (h && h.value) p.set('hasta', h.value);

            if (pageUrl && typeof pageUrl === 'string') {
                try { var pg = new URL(pageUrl, window.location.origin).searchParams.get('page'); if (pg) p.set('page', pg); } catch (_) {}
            }

            var baseUrl = @json(route('almacen.notas'));
            var qs = p.toString();
            var finalUrl = baseUrl + (qs ? ('?' + qs) : '');

            var resp = await fetch(finalUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            var data = await resp.json();

            body.innerHTML = data.html || '';
            body.style.opacity = '1';

            var pag = document.getElementById('almNotPagination');
            if (pag) pag.innerHTML = data.pagination || '';

            var cnt = document.getElementById('almNotTotalCount');
            if (cnt && data.total !== undefined) cnt.textContent = data.total;

            if (window.history && window.history.pushState) {
                window.history.pushState(null, '', finalUrl);
            }

            var sb = el('almNotSearchClear');
            if (sb) sb.style.display = (s && s.value.trim()) ? 'block' : 'none';
            var box = s ? s.closest('.anf-search-box') : null;
            if (box) { if (s.value.trim()) box.classList.add('active'); else box.classList.remove('active'); }
        } catch (e) {
            console.error('[loadNotas]', e);
            if (body) body.style.opacity = '1';
        } finally {
            if (window.hidePreloader) window.hidePreloader();
        }
    };

    // Selección en cualquier custom-dropdown → recarga via AJAX.
    // uicomponents.js emite 'dropdown-selection' al seleccionar/limpiar.
    var _almNotReady = false;
    setTimeout(function () { _almNotReady = true; }, 500);
    window.addEventListener('dropdown-selection', function (e) {
        if (!_almNotReady) return;
        if (!document.getElementById('almNotTableBody')) return;
        var id = e.detail && e.detail.dropdownId;
        if (id === 'almNotFiltroAlmacen' || id === 'almNotFiltroFrente' || id === 'almNotFiltroTipo' || id === 'almNotFiltroCat') {
            window.loadNotas();
        }
    });

    // Panel "Filtros Avanzados" (Desde / Hasta / Categoría).
    window.almNotToggleFechas = function (e) {
        if (e) e.stopPropagation();
        var p = el('almNotFechasPanel'); if (!p) return;
        p.style.display = (p.style.display === 'block') ? 'none' : 'block';
    };
    // "Limpiar Todo" del panel avanzados: borra tipo, categoria, desde y hasta.
    // Usa clearDropdownFilter para resetear la UI visual de los dropdowns pero
    // desactiva el guard _almNotReady para no disparar loadNotas por cada clear.
    window.almNotLimpiarFechas = function (ev) {
        if (ev) { ev.preventDefault(); ev.stopPropagation(); }
        var d = el('almNotDesde'), h = el('almNotHasta');
        if (d) d.value = '';
        if (h) h.value = '';
        var dc = el('almNotDesdeClear'); if (dc) dc.style.display = 'none';
        var hc = el('almNotHastaClear'); if (hc) hc.style.display = 'none';
        var db = el('almNotDesdeBox'); if (db) db.style.background = 'white';
        var hb = el('almNotHastaBox'); if (hb) hb.style.background = 'white';
        _almNotReady = false;
        if (typeof clearDropdownFilter === 'function') {
            clearDropdownFilter('almNotFiltroTipo');
            clearDropdownFilter('almNotFiltroCat');
        }
        _almNotReady = true;
        window.loadNotas();
    };
    document.addEventListener('click', function (e) {
        var p = el('almNotFechasPanel'); var b = el('btnAdvancedFilterNot');
        if (!p || !b) return;
        if (p.style.display === 'block' && !p.contains(e.target) && !b.contains(e.target)) {
            p.style.display = 'none';
        }
    });

    // Shim cross-page para el partial consumo_stats (compartido con /admin/almacen/movimientos):
    // su <li> dispara window.almMovFiltrarPorProducto(id, nombre). Aquí no hay una tabla de
    // movimientos que recargar, así que redirigimos al kardex con el filtro de búsqueda
    // pre-aplicado (mismo patrón que usa el botón "Ver por producto" del header).
    if (typeof window.almMovFiltrarPorProducto !== 'function') {
        window.almMovFiltrarPorProducto = function (_idProducto, nombre) {
            var p = new URLSearchParams();
            var alm = hv('id_almacen'); if (alm) p.set('id_almacen', alm);
            var fr  = hv('id_frente'); if (fr && fr !== 'all') p.set('id_frente', fr);
            var cat = hv('categoria'); if (cat && cat !== 'all') p.set('categoria', cat);
            var d   = el('almNotDesde'); if (d && d.value) p.set('desde', d.value);
            var h   = el('almNotHasta'); if (h && h.value) p.set('hasta', h.value);
            if (nombre) p.set('search', nombre);
            window.location.href = @json(route('almacen.movimientos')) + '?' + p.toString();
        };
    }

    // Paginación AJAX
    document.addEventListener('click', function (e) {
        var link = e.target.closest('#almNotPagination a.page-link');
        if (link) { e.preventDefault(); e.stopImmediatePropagation(); window.loadNotas(link.href); }
    });
})();
</script>
@endsection
