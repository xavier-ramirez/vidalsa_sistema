@extends('layouts.estructura_base')

@section('title', 'Almacén')

@section('content')
<style>
    /* ── Almacén / Inventario — tabla + filtros (estilo /admin/equipos) ── */
    #almFilters {
        display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 4px 0 14px;
    }
    .alm-filter { flex: 1 1 200px; min-width: 170px; max-width: 280px; position: relative; }
    .alm-filter-box {
        display: flex; align-items: center; background: #fbfcfd; border: 1px solid #cbd5e0;
        border-radius: 12px; height: 45px; overflow: hidden;
    }
    .alm-filter.active .alm-filter-box { background: #e1effa; border-color: var(--maquinaria-blue, #0067b1); }
    .alm-filter .alm-ic { padding: 0 10px; display: flex; align-items: center; color: #64748b; }
    .alm-filter input[type="text"], .alm-filter select {
        flex: 1; border: none; background: transparent; outline: none; font-size: 14px;
        color: #1e293b; padding: 10px 6px; min-width: 0; height: 100%; cursor: text;
    }
    .alm-filter select { cursor: pointer; -webkit-appearance: none; appearance: none; }
    .alm-filter .filter-clear { padding: 0 8px; color: #64748b; font-size: 18px; cursor: pointer; }

    .alm-table { width: 100%; border-collapse: collapse; font-size: 13.5px; min-width: 760px; }
    .alm-table thead th {
        text-align: left; color: #64748b; font-size: 11.5px; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.4px; padding: 10px 12px; border-bottom: 2px solid #e2e8f0; background: #f8fafc;
        position: sticky; top: 0; z-index: 2;
    }
    .alm-table tbody td { padding: 9px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .alm-table tbody tr:hover { background: #f8fafc; }
    .alm-row-bajo { background: #fff7ed; }
    .alm-row-bajo:hover { background: #ffedd5; }
    /* Fila seleccionable: clic en la fila la marca (estilo /admin/equipos → .selected-row-maquinaria) */
    .alm-table tbody tr.alm-row-clickable { cursor: pointer; }
    /* En móvil .selected-row-maquinaria es desktop-only, así que damos un realce propio */
    .alm-table tbody tr.alm-row.selected-row-maquinaria { background: #e1effa !important; }

    .alm-btn {
        display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px;
        border-radius: 7px; border: 1px solid #e2e8f0; background: #fff; cursor: pointer; margin: 0 1px;
        transition: transform 0.12s, background 0.12s;
    }
    .alm-btn:hover { transform: scale(1.06); }
    /* (sólo se usan en la lista "Gestionar almacenes" y en el botón "quitar" del modal de salida) */
    .alm-btn-edit { color: #0891b2; border-color: #cffafe; } .alm-btn-edit:hover { background: #0891b2; color: #fff; }
    .alm-btn-del  { color: #ef4444; border-color: #fecaca; } .alm-btn-del:hover  { background: #ef4444; color: #fff; }
    /* Botón "ojo" de detalles por fila: mismo look que el de /admin/equipos */
    .alm-table tbody td .btn-details-mini { margin: 0 auto; }
    /* Acciones dentro del modal "Detalles del producto" */
    .alm-det-act { display:flex; align-items:center; gap:10px; width:100%; text-align:left; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:8px 12px; font-size:14px; font-weight:600; color:#334155; cursor:default; transition:background .15s, border-color .15s; }
    .alm-det-act:hover { background:#f8fafc; border-color:#cbd5e0; }
    .alm-det-ic { width:30px; height:30px; border-radius:8px; display:flex; align-items:center; justify-content:center; flex:0 0 auto; }

    /* Modales */
    .alm-modal-overlay {
        display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.45);
        z-index: 9000; align-items: center; justify-content: center; padding: 16px;
    }
    .alm-modal-overlay.open { display: flex; }
    .alm-modal {
        background: #fff; border-radius: 14px; width: 100%; max-width: 440px; box-shadow: 0 20px 50px rgba(0,0,0,0.25);
        overflow: hidden; animation: almIn 0.16s ease-out; display: flex; flex-direction: column; max-height: 90vh;
    }
    .alm-modal-wide { max-width: 980px; }
    .alm-modal .alm-modal-body { overflow-y: auto; min-height: 0; }
    /* Multiselect de frentes dentro del modal de almacén: el panel empuja el contenido (no flota) para que el overflow del modal no lo recorte */
    #almAlmacenModal .multiselect-content { position: static; box-shadow: none; margin-top: 6px; }
    #almAlmacenModal .custom-multiselect.active .multiselect-content { animation: slideDown 0.18s ease-out; }
    .alm-kardex-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    .alm-kardex-table th { text-align: left; color: #64748b; font-size: 10.5px; font-weight: 800; text-transform: uppercase; padding: 7px 9px; border-bottom: 2px solid #e2e8f0; background: #f8fafc; position: sticky; top: 0; }
    .alm-kardex-table td { padding: 7px 9px; }
    .alm-admin-list { display: flex; flex-direction: column; gap: 6px; }
    .alm-admin-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 8px; }
    .alm-admin-row:hover { background: #f8fafc; }
    @keyframes almIn { from { transform: translateY(8px); opacity: 0; } to { transform: none; opacity: 1; } }
    @keyframes slideDown { from { transform: translateY(-8px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    /* Todos los modales del módulo: título y botones centrados; la X queda fija en la esquina. */
    .alm-modal-head { padding: 14px 40px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: center; position: relative; }
    .alm-modal-head h3 { margin: 0; font-size: 15px; font-weight: 800; color: #1e293b; display: flex; align-items: center; gap: 8px; text-align: center; }
    .alm-modal-head .alm-x { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); }
    .alm-modal-body { padding: 16px 18px; display: flex; flex-direction: column; gap: 12px; }
    .alm-modal-foot { padding: 12px 18px; border-top: 1px solid #f1f5f9; display: flex; justify-content: center; gap: 8px; }
    .alm-modal label { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.3px; display: block; margin-bottom: 4px; }
    .alm-modal input, .alm-modal select, .alm-modal textarea {
        width: 100%; border: 1px solid #cbd5e0; border-radius: 8px; padding: 9px 10px; font-size: 14px; outline: none; box-sizing: border-box;
    }
    .alm-modal input:focus, .alm-modal select:focus, .alm-modal textarea:focus { border-color: var(--maquinaria-blue, #0067b1); }
    .alm-pill { display: inline-block; background: #f1f5f9; border-radius: 6px; padding: 2px 8px; font-size: 12px; font-weight: 700; color: #334155; }
    .alm-x { cursor: pointer; color: #94a3b8; }
    .alm-x:hover { color: #475569; }

    /* Sugerencias de los filtros — mismo look que los desplegables (.dropdown-content / .dropdown-item) de la app */
    .alm-suggest {
        position:absolute; top:calc(100% + 5px); left:0; right:0; background:#fff;
        border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,0.1);
        z-index:1000; max-height:260px; overflow-y:auto; padding:5px; display:none;
    }
    .alm-suggest.open { display:block; animation:slideDown 0.18s ease-out; }
    .alm-suggest-item { display:flex; flex-direction:column; gap:2px; padding:10px 15px; border-radius:8px; cursor:default; transition:background 0.2s; font-weight:600; color:var(--maquinaria-dark-blue,#1e3a5f); }
    .alm-suggest-item:hover, .alm-suggest-item.active { background:#f0f4f8; }
    .alm-suggest-item.si-sel { background:#ebf4ff; color:var(--maquinaria-blue,#0067b1); }
    .alm-suggest-item .cod { font-family:monospace; font-weight:800; font-size:12px; color:#0f172a; }
    .alm-suggest-item .nom { font-size:13.5px; color:#475569; font-weight:600; }
    .alm-suggest-empty { padding:10px 15px; font-size:13px; color:#94a3b8; }
    /* Variante "en línea" para los modales (no flota: empuja el contenido — así no la recorta el overflow del modal) */
    .alm-suggest-inline { margin-top:6px; border:1px solid #e2e8f0; border-radius:12px; background:#fff; box-shadow:0 6px 16px rgba(0,0,0,0.06); max-height:200px; overflow-y:auto; padding:5px; display:none; }
    .alm-suggest-inline.open { display:block; animation:slideDown 0.18s ease-out; }
    .alm-suggest-inline .si-item { display:flex; align-items:center; gap:10px; padding:10px 15px; border-radius:8px; cursor:default; font-size:14px; font-weight:600; color:var(--maquinaria-dark-blue,#1e3a5f); transition:background 0.2s; }
    .alm-suggest-inline .si-item:hover { background:#f0f4f8; }
    .alm-suggest-inline .si-item .material-icons { font-size:18px; color:#94a3b8; }
    .alm-suggest-inline .si-item.si-sel { background:#ebf4ff; color:var(--maquinaria-blue,#0067b1); }
    .alm-suggest-inline .si-item.si-sel .material-icons { color:var(--maquinaria-blue,#0067b1); }
    .alm-suggest-inline .si-new { color:var(--maquinaria-blue,#0067b1); font-weight:700; }
    .alm-suggest-inline .si-new .material-icons { color:var(--maquinaria-blue,#0067b1); }
    /* Campo "Categoría" del modal de producto: input + botón desplegable (caret) */
    .alm-cat-field { position:relative; display:flex; align-items:center; }
    .alm-cat-field > input { flex:1; padding-right:36px !important; }
    .alm-cat-caret { position:absolute; right:3px; top:50%; transform:translateY(-50%); width:30px; height:30px; border:none; background:transparent; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#64748b; border-radius:8px; transition:background .15s,color .15s; }
    .alm-cat-caret:hover { background:#f1f5f9; color:#0f172a; }
    .alm-cat-caret .material-icons { font-size:22px; transition:transform .15s; }
    .alm-cat-caret.open .material-icons { transform:rotate(180deg); }

    @media (max-width: 768px) {
        #almFilters .alm-filter { max-width: none; flex: 1 1 100%; }
        .counter-sidebar { gap: 10px !important; }
    }
</style>

@php
    $reqAlm    = $almacenSel?->ID_ALMACEN;
    $reqBuscar = request('search');
    $reqCat    = request('categoria');
    $puedeManage = auth()->user()?->can('almacen.manage') ?? false;
    $puedeMover  = auth()->user()?->can('almacen.movimiento') ?? false;
    $st = $stats ?? ['total' => '—', 'con_saldo' => '—', 'stock_bajo' => '—', 'unidades' => 0];
    // Datos de los almacenes para el modal de edición (solo se usa si $puedeManage).
    $almacenesData = ($almacenes ?? collect())->keyBy('ID_ALMACEN')->map(function ($a) {
        return [
            'NOMBRE'    => $a->NOMBRE,
            'TIPO'      => $a->TIPO,
            'UBICACION' => $a->UBICACION,
            'frentes'   => $a->relationLoaded('frentes') ? $a->frentes->pluck('ID_FRENTE')->values() : [],
        ];
    });
@endphp

<section class="page-title-card" style="text-align:left;margin:0 0 10px 0;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
        <h1 class="page-title" style="margin:0;">
            <span class="page-title-line2" style="color:#000;">Inventario de Almacén</span>
        </h1>
        {{-- Filtro de almacén junto al título — mismo componente custom-dropdown que /admin/equipos y /admin/almacen/movimientos. --}}
        <div style="width:260px;min-width:200px;max-width:100%;flex:0 1 auto;">
            <div class="custom-dropdown" id="almSelAlmacenDropdown" data-filter-type="id_almacen" data-default-label="Todos los almacenes">
                <input type="hidden" name="id_almacen" data-filter-value id="almSelAlmacen" value="{{ $reqAlm ?? '' }}">
                <div class="dropdown-trigger {{ $almacenSel ? 'filter-active' : '' }}" style="padding:0;display:flex;align-items:center;background:#fff;overflow:hidden;border:1px solid #cbd5e0;border-radius:12px;height:45px;">
                    <span style="padding:0 10px;display:flex;align-items:center;color:var(--maquinaria-gray-text);"><i class="material-icons" style="font-size:18px;">warehouse</i></span>
                    <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                           placeholder="{{ $almacenSel ? $almacenSel->NOMBRE : 'Todos los almacenes' }}"
                           style="flex:1;border:none;background:transparent;padding:10px 5px;font-size:14px;outline:none;min-width:0;"
                           oninput="window.filterDropdownOptions(this)">
                    <i class="material-icons" data-clear-btn style="padding:0 5px;color:var(--maquinaria-gray-text);font-size:18px;display:{{ $almacenSel ? 'block' : 'none' }};cursor:pointer;"
                       onclick="event.stopPropagation(); clearDropdownFilter('almSelAlmacenDropdown');">close</i>
                </div>
                <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                    <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                        @foreach($almacenes as $a)
                            <div class="dropdown-item {{ $almacenSel && $almacenSel->ID_ALMACEN == $a->ID_ALMACEN ? 'selected' : '' }}" data-value="{{ $a->ID_ALMACEN }}"
                                 onclick="selectOption('almSelAlmacenDropdown','{{ $a->ID_ALMACEN }}','{{ addslashes($a->NOMBRE) }}');">
                                {{ $a->NOMBRE }} {{ $a->TIPO === 'GENERAL' ? '(Principal)' : '(Proyecto)' }}
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="page-layout-grid">
<div class="admin-card" style="margin:0;min-height:80vh;min-width:0;width:100%;padding:14px;">

    {{-- ── Filtros ── (el filtro de almacén está junto al título, no aquí) --}}
    <div id="almFilters">
        {{-- Buscar (código o descripción) — con sugerencias estilo app. Ancho amplio: es el filtro principal. --}}
        <div class="alm-filter {{ $reqBuscar ? 'active' : '' }}" style="flex:3 1 340px;max-width:560px;">
            <div class="alm-filter-box">
                <span class="alm-ic"><i class="material-icons" style="font-size:18px;">search</i></span>
                <input type="text" id="almFiltroBuscar" autocomplete="off"
                       placeholder="Buscar por código o descripción…" value="{{ $reqBuscar }}"
                       oninput="window.almBuscarInput()" onfocus="window.almBuscarSuggest()">
                <i class="material-icons filter-clear" style="display:{{ $reqBuscar ? 'flex' : 'none' }};"
                   onclick="window.almBuscarLimpiar()">close</i>
            </div>
            <div class="alm-suggest" id="almFiltroBuscarSuggest"></div>
        </div>

        {{-- Categoría — input con lupa + sugerencias (como el filtro "Buscar") --}}
        <div class="alm-filter {{ $reqCat && $reqCat !== 'all' ? 'active' : '' }}" style="flex:1 1 190px;">
            <div class="alm-filter-box">
                <span class="alm-ic"><i class="material-icons" style="font-size:18px;">search</i></span>
                <input type="text" id="almFiltroCat" autocomplete="off"
                       placeholder="Filtrar por categoría…" value="{{ $reqCat && $reqCat !== 'all' ? $reqCat : '' }}"
                       oninput="window.almCatInput()" onfocus="window.almCatSuggest()">
                <i class="material-icons filter-clear" style="display:{{ $reqCat && $reqCat !== 'all' ? 'flex' : 'none' }};"
                   onclick="window.almCatLimpiar()">close</i>
            </div>
            <div class="alm-suggest" id="almFiltroCatSuggest"></div>
        </div>

        {{-- Acciones (botón desplegable estilo /admin/equipos) --}}
        <div style="display:flex;gap:8px;margin-left:auto;flex:0 0 auto;align-items:center;">
            <div style="position:relative;">
                <button type="button" id="almBtnAcciones" class="btn-primary-maquinaria"
                        style="height:45px;padding:0 16px;display:flex;align-items:center;gap:8px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);"
                        onclick="window.almToggleAcciones(event)">
                    <i class="material-icons" style="font-size:18px;">settings</i><span class="desktop-text">Acciones</span><i class="material-icons" style="font-size:18px;">expand_more</i>
                </button>
                <div id="almAccionesMenu" style="display:none;position:absolute;top:100%;right:0;width:250px;background:#fff;border-radius:8px;box-shadow:0 10px 18px -3px rgba(0,0,0,0.18);border:1px solid #e2e8f0;z-index:60;margin-top:6px;overflow:hidden;animation:slideDown 0.18s ease-out;">
                    <a href="{{ route('almacen.movimientos') }}" class="dropdown-item-custom" style="display:flex;align-items:center;gap:10px;padding:11px 14px;color:#475569;background:transparent;border:none;border-bottom:1px solid #f1f5f9;width:100%;text-align:left;text-decoration:none;cursor:pointer;">
                        <div style="background:#f1f5f9;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#475569;">receipt_long</i></div>
                        <span style="font-size:14px;font-weight:500;">Movimientos de inventario</span>
                    </a>
                    @if($puedeManage)
                    <button type="button" onclick="window.almAccion('admin')" class="dropdown-item-custom" style="display:flex;align-items:center;gap:10px;padding:11px 14px;color:#475569;background:transparent;border:none;border-bottom:1px solid #f1f5f9;width:100%;text-align:left;cursor:pointer;">
                        <div style="background:#f1f5f9;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#475569;">warehouse</i></div>
                        <span style="font-size:14px;font-weight:500;">Gestionar almacenes</span>
                    </button>
                    <button type="button" onclick="window.almAccion('almacen')" class="dropdown-item-custom" style="display:flex;align-items:center;gap:10px;padding:11px 14px;color:#475569;background:transparent;border:none;border-bottom:1px solid #f1f5f9;width:100%;text-align:left;cursor:pointer;">
                        <div style="background:#e0f2fe;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#0284c7;">add_business</i></div>
                        <span style="font-size:14px;font-weight:500;">Nuevo almacén</span>
                    </button>
                    <button type="button" onclick="window.almAccion('producto')" class="dropdown-item-custom" style="display:flex;align-items:center;gap:10px;padding:11px 14px;color:#475569;background:transparent;border:none;width:100%;text-align:left;cursor:pointer;">
                        <div style="background:#e0f2fe;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#0284c7;">add_circle</i></div>
                        <span style="font-size:14px;font-weight:500;">Nuevo producto</span>
                    </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ── Tabla ── --}}
    <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:12px;">
        <table class="alm-table">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Producto</th>
                    <th style="text-align:center;">UM</th>
                    <th>Categoría</th>
                    <th style="text-align:right;">Stock</th>
                    <th style="text-align:center;width:60px;">Detalles</th>
                </tr>
            </thead>
            <tbody id="almTableBody">
                @include('admin.almacen.partials.table_rows', ['productos' => $productos, 'almacen' => $almacenSel, 'inicial' => true])
            </tbody>
        </table>
    </div>

    <div style="margin-top:16px;" id="almPagination">
        {!! $productos ? $productos->links('vendor.pagination.custom-sliding') : '' !!}
    </div>
</div>

{{-- ── Sidebar: Consolidado de Inventario ── --}}
<div class="counter-sidebar" style="position:sticky;top:20px;display:flex;flex-direction:column;gap:8px;">

    <div style="background:linear-gradient(135deg,#1a365d 0%,#2c5282 100%);border-radius:12px;padding:15px;color:white;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);position:relative;overflow:hidden;">
        <i class="material-icons" style="position:absolute;right:-15px;bottom:-15px;font-size:80px;opacity:0.1;transform:rotate(-15deg);">inventory</i>
        <div style="position:relative;z-index:2;">
            <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;opacity:0.8;margin-bottom:4px;display:flex;align-items:center;gap:6px;">
                <i class="material-icons" style="font-size:14px;">pie_chart</i> Consolidado de Inventario
            </div>
            <div id="almAlmacenNombre" style="font-size:11px;opacity:0.75;margin-bottom:12px;">{{ $almacenSel?->NOMBRE ?? '—' }}</div>

            <div style="display:flex;align-items:center;gap:8px;">
                <div onclick="window.almVerTodo()" title="Quitar filtros"
                     style="display:flex;flex-direction:column;align-items:center;background:rgba(255,255,255,0.15);padding:8px 6px;border-radius:10px;min-width:65px;cursor:pointer;">
                    <span id="almStatsTotal" style="font-size:34px;font-weight:800;line-height:1;">{{ $st['total'] }}</span>
                    <span style="font-size:12px;opacity:0.8;font-weight:700;margin-top:2px;">PRODUCTOS</span>
                </div>
                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:4px;flex:1;">
                    <div onclick="window.almFiltrarConSaldo()" title="Solo con saldo"
                         style="display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(34,197,94,0.15);padding:6px 2px;border-radius:8px;border:1px solid rgba(34,197,94,0.25);cursor:pointer;">
                        <i class="material-icons" style="font-size:18px;color:#22c55e;margin-bottom:2px;">inventory_2</i>
                        <strong id="almStatsConSaldo" style="font-weight:800;font-size:16px;color:white;">{{ $st['con_saldo'] }}</strong>
                        <span style="font-size:10.5px;opacity:0.9;font-weight:700;text-transform:uppercase;">Con stock</span>
                    </div>
                    <div onclick="window.almFiltrarBajo()" title="Solo stock bajo"
                         style="display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(245,158,11,0.18);padding:6px 2px;border-radius:8px;border:1px solid rgba(245,158,11,0.3);cursor:pointer;">
                        <i class="material-icons" style="font-size:18px;color:#f59e0b;margin-bottom:2px;">warning</i>
                        <strong id="almStatsBajo" style="font-weight:800;font-size:16px;color:white;">{{ $st['stock_bajo'] }}</strong>
                        <span style="font-size:10.5px;opacity:0.9;font-weight:700;text-transform:uppercase;">Stock bajo</span>
                    </div>
                </div>
            </div>
            <div style="margin-top:10px;font-size:11.5px;opacity:0.85;display:flex;align-items:center;gap:6px;">
                <i class="material-icons" style="font-size:14px;">functions</i>
                Unidades en almacén: <strong id="almStatsUnidades">{{ rtrim(rtrim(number_format((float)($st['unidades'] ?? 0), 3, '.', ','), '0'), '.') ?: '0' }}</strong>
            </div>
        </div>
    </div>

    <div style="background:white;border-radius:12px;padding:15px;border:1px solid #e2e8f0;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);overflow:hidden;">
        <div id="almDistribucionContainer">
            @include('admin.almacen.partials.distribucion_stats', ['distribucion' => $distribucion ?? collect()])
        </div>
    </div>
</div>

</div>{{-- /page-layout-grid --}}

@if($puedeMover)
{{-- ── Barra flotante de selección (igual que /admin/equipos: clic en la fila → se resalta y aparece esta barra) ── --}}
<div id="almBulkBar" class="selection-floating-bar">
    <div class="selection-counter">
        <div style="background:rgba(255,255,255,0.1);padding:5px;border-radius:50%;display:flex;"><i class="material-icons" style="font-size:18px;color:white;">inventory_2</i></div>
        <span id="almBulkCount">0</span>
    </div>
    <div style="width:1px;height:24px;background:rgba(255,255,255,0.2);"></div>
    <div style="display:flex;gap:10px;">
        <button type="button" onclick="window.almSelClear(event)" class="btn-bulk-clear" onmouseover="this.style.color='white'" onmouseout="this.style.color='#94a3b8'">
            <span class="desktop-text">Limpiar</span>
        </button>
        <button type="button" onclick="window.almSelAccion('SALIDA')" class="btn-bulk-action" style="background:#dc2626;">
            <i class="material-icons" style="font-size:18px;">north_east</i><span class="desktop-text">Salida</span>
        </button>
        <button type="button" onclick="window.almSelAccion('TRASPASO')" class="btn-bulk-action" style="background:#0067b1;">
            <i class="material-icons" style="font-size:18px;">swap_horiz</i><span class="desktop-text">Enviar a otro almacén</span>
        </button>
    </div>
</div>
@endif

{{-- ════════════════════════ MODALES ════════════════════════ --}}

{{-- Movimiento: ENTRADA / SALIDA --}}
<div id="almMovModal" class="alm-modal-overlay">
    <div class="alm-modal">
        <div class="alm-modal-head">
            <h3><i class="material-icons" id="almMovIcon" style="font-size:20px;">add</i> <span id="almMovTitulo">Registrar entrada</span></h3>
            <i class="material-icons alm-x" onclick="almCerrar('almMovModal')">close</i>
        </div>
        <div class="alm-modal-body">
            <div>
                <label>Producto</label>
                <div><span class="alm-pill" id="almMovCodigo"></span> <strong id="almMovNombre" style="font-size:14px;color:#1e293b;"></strong></div>
                <div style="font-size:12px;color:#64748b;margin-top:4px;">Saldo actual: <strong id="almMovSaldo">0</strong> <span id="almMovUm"></span></div>
            </div>
            <div>
                <label id="almMovCantLabel">Cantidad que entra</label>
                <input type="number" id="almMovCantidad" min="0.001" step="any" placeholder="0">
            </div>
            <div style="display:flex;gap:10px;">
                <div style="flex:1;"><label>Fecha</label><input type="date" id="almMovFecha"></div>
                <div style="flex:1;"><label>Referencia (guía/factura)</label><input type="text" id="almMovReferencia" maxlength="100" placeholder="Opcional"></div>
            </div>
            <div id="almMovFrenteWrap">
                <label>Frente destino (opcional — p. ej. a qué proyecto se consume)</label>
                <select id="almMovFrente">
                    <option value="">— ninguno —</option>
                    @foreach(($frentesLista ?? collect()) as $f)<option value="{{ $f->ID_FRENTE }}">{{ $f->NOMBRE_FRENTE }}</option>@endforeach
                </select>
            </div>
            <div><label>Motivo / notas</label><input type="text" id="almMovMotivo" maxlength="200" placeholder="Opcional (compra, consumo, devolución...)"></div>
            <div id="almMovError" style="display:none;color:#dc2626;font-size:13px;font-weight:600;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almMovModal')">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" id="almMovSubmit" onclick="window.almGuardarMovimiento()">Registrar</button>
        </div>
    </div>
</div>

{{-- Ajuste de saldo + mínimo --}}
<div id="almAjusteModal" class="alm-modal-overlay">
    <div class="alm-modal">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">tune</i> Ajustar saldo / mínimo</h3>
            <i class="material-icons alm-x" onclick="almCerrar('almAjusteModal')">close</i>
        </div>
        <div class="alm-modal-body">
            <div>
                <label>Producto</label>
                <div><span class="alm-pill" id="almAjCodigo"></span> <strong id="almAjNombre" style="font-size:14px;color:#1e293b;"></strong></div>
                <div style="font-size:12px;color:#64748b;margin-top:4px;">Saldo actual: <strong id="almAjSaldo">0</strong> <span id="almAjUm"></span></div>
            </div>
            <div>
                <label>Nuevo saldo (conteo físico / corrección)</label>
                <input type="number" id="almAjNuevoSaldo" min="0" step="any" placeholder="Dejar vacío para no cambiar el saldo">
            </div>
            <div>
                <label>Stock mínimo (alerta)</label>
                <input type="number" id="almAjMinimo" min="0" step="any" placeholder="Vacío = sin alerta">
            </div>
            <div><label>Motivo / notas</label><input type="text" id="almAjMotivo" maxlength="200" placeholder="Opcional"></div>
            <div id="almAjError" style="display:none;color:#dc2626;font-size:13px;font-weight:600;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almAjusteModal')">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" onclick="window.almGuardarAjuste()">Guardar</button>
        </div>
    </div>
</div>

@if($puedeManage)
{{-- Nuevo almacén --}}
<div id="almAlmacenModal" class="alm-modal-overlay">
    <div class="alm-modal">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">add_business</i> <span id="almNvTitulo">Nuevo almacén</span></h3>
            <i class="material-icons alm-x" onclick="almCerrar('almAlmacenModal')">close</i>
        </div>
        <div class="alm-modal-body">
            <div><label>Nombre *</label><input type="text" id="almNvNombre" maxlength="150" placeholder="Ej: ALMACÉN CENTRAL CARACAS"></div>
            <div>
                <label>Tipo *</label>
                <select id="almNvTipo" onchange="window.almToggleFrentes()">
                    <option value="GENERAL">Principal (GENERAL — ve todo)</option>
                    <option value="PROYECTO" selected>Secundario (PROYECTO — ligado a frentes)</option>
                </select>
            </div>
            <div><label>Ubicación</label><input type="text" id="almNvUbicacion" maxlength="150" placeholder="Opcional"></div>
            <div id="almNvFrentesWrap">
                <label>Frentes que usan este almacén</label>
                <div class="custom-multiselect" id="almNvFrentesSelect">
                    {{-- El trigger es un input directo: clic lo abre y escribir filtra la lista de abajo. --}}
                    <div class="multiselect-trigger" tabindex="-1" role="button" aria-haspopup="listbox" style="padding:0;display:flex;align-items:center;overflow:hidden;cursor:text;">
                        <input type="text" id="almNvFrentesInput" autocomplete="off"
                               placeholder="Selecciona los frentes…"
                               style="flex:1;border:none;background:transparent;padding:12px 15px;font-size:15px;outline:none;min-width:0;color:#0f172a;"
                               oninput="window.almNvFrentesFilter(this)">
                        <i class="material-icons" style="padding:0 12px;color:var(--maquinaria-gray-text);transition:transform 0.3s;">expand_more</i>
                    </div>
                    <div class="multiselect-content">
                        @forelse(($frentesLista ?? collect()) as $f)
                            <label class="multiselect-item alm-frente-opt" for="almNvFrente_{{ $f->ID_FRENTE }}">
                                <input type="checkbox" id="almNvFrente_{{ $f->ID_FRENTE }}" value="{{ $f->ID_FRENTE }}" onchange="window.almNvFrentesUpdate()">
                                <span>{{ $f->NOMBRE_FRENTE }}</span>
                            </label>
                        @empty
                            <div style="padding:10px 15px;font-size:13px;color:#94a3b8;" id="almNvFrentesVacio">No hay frentes activos.</div>
                        @endforelse
                        <div id="almNvFrentesNoMatch" style="display:none;padding:10px 15px;font-size:13px;color:#94a3b8;">Sin coincidencias.</div>
                    </div>
                </div>
                <div style="font-size:11.5px;color:#94a3b8;margin-top:5px;">Varios proyectos pueden compartir un mismo almacén.</div>
            </div>
            <div id="almNvError" style="display:none;color:#dc2626;font-size:13px;font-weight:600;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almAlmacenModal')">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" id="almNvSubmit" onclick="window.almGuardarAlmacen()">Crear almacén</button>
        </div>
    </div>
</div>

{{-- Nuevo / Editar producto --}}
<div id="almProductoModal" class="alm-modal-overlay">
    <div class="alm-modal">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">add_circle</i> <span id="almProdTitulo">Nuevo producto</span></h3>
            <i class="material-icons alm-x" onclick="almCerrar('almProductoModal')">close</i>
        </div>
        <div class="alm-modal-body">
            <div style="display:flex;gap:10px;">
                <div style="flex:1;"><label>Código</label><input type="text" id="almProdCodigo" maxlength="50" placeholder="Opcional — se genera solo si lo dejas vacío"></div>
                <div style="flex:0.7;"><label>UM *</label><input type="text" id="almProdUm" maxlength="20" placeholder="UND, KG, LTS..." value="UND"></div>
            </div>
            <div><label>Descripción / producto *</label><input type="text" id="almProdNombre" maxlength="200" placeholder="Ej: TORNILLO HEXAGONAL 1/2&quot;"></div>
            <div>
                <label>Categoría</label>
                <div class="alm-cat-field">
                    <input type="text" id="almProdCategoria" autocomplete="off" maxlength="100"
                           placeholder="Elige una de la lista o escribe una nueva…"
                           oninput="window.almProdCatSuggest()" onfocus="window.almProdCatSuggest(true)">
                    <button type="button" class="alm-cat-caret" id="almProdCatCaret" tabindex="-1" title="Ver categorías registradas"
                            onclick="window.almProdCatToggle(event)"><i class="material-icons">arrow_drop_down</i></button>
                </div>
                <div class="alm-suggest-inline" id="almProdCatSuggest"></div>
                <div style="font-size:11.5px;color:#94a3b8;margin-top:4px;">Si la que necesitas no está en la lista, escríbela y se registrará al guardar el producto.</div>
            </div>
            <div id="almProdError" style="display:none;color:#dc2626;font-size:13px;font-weight:600;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almProductoModal')">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" id="almProdSubmit" onclick="window.almGuardarProducto()">Crear</button>
        </div>
    </div>
</div>

{{-- Gestionar almacenes (editar / eliminar) --}}
<div id="almAdminAlmacenesModal" class="alm-modal-overlay">
    <div class="alm-modal" style="max-width:560px;">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">warehouse</i> Gestionar almacenes</h3>
            <i class="material-icons alm-x" onclick="almCerrar('almAdminAlmacenesModal')">close</i>
        </div>
        <div class="alm-modal-body">
            <div class="alm-admin-list">
                @forelse($almacenes as $a)
                    <div class="alm-admin-row" data-id="{{ $a->ID_ALMACEN }}">
                        <i class="material-icons" style="font-size:18px;color:{{ $a->TIPO === 'GENERAL' ? '#0067b1' : '#64748b' }};">{{ $a->TIPO === 'GENERAL' ? 'business' : 'store' }}</i>
                        <div style="flex:1;min-width:0;">
                            <div style="font-weight:700;color:#1e293b;font-size:13.5px;">{{ $a->NOMBRE }}</div>
                            <div style="font-size:11.5px;color:#94a3b8;">{{ $a->TIPO === 'GENERAL' ? 'Principal' : 'Proyecto' }}{{ $a->CODIGO ? ' · '.$a->CODIGO : '' }}{{ $a->TIPO === 'PROYECTO' ? ' · '.$a->frentes_count.' frente(s)' : '' }}{{ $a->UBICACION ? ' · '.$a->UBICACION : '' }}</div>
                        </div>
                        <button type="button" class="alm-btn alm-btn-edit" title="Editar"
                                onclick="window.almEditarAlmacen({{ $a->ID_ALMACEN }})"><i class="material-icons" style="font-size:16px;">edit</i></button>
                        <button type="button" class="alm-btn alm-btn-del" title="Eliminar / desactivar"
                                onclick="window.almEliminarAlmacen({{ $a->ID_ALMACEN }}, '{{ addslashes($a->NOMBRE) }}')"><i class="material-icons" style="font-size:16px;">delete_outline</i></button>
                    </div>
                @empty
                    <p style="color:#94a3b8;font-size:13px;text-align:center;padding:20px 0;">No hay almacenes. Usa "Nuevo almacén" para crear el primero.</p>
                @endforelse
            </div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almAdminAlmacenesModal')">Cerrar</button>
        </div>
    </div>
</div>
@endif

{{-- Detalles del producto (se abre con el "ojo" de cada fila — agrupa todas las acciones del producto) --}}
<div id="almDetalleModal" class="alm-modal-overlay">
    <div class="alm-modal" style="max-width:480px;">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">inventory_2</i> Detalles del producto</h3>
            <i class="material-icons alm-x" onclick="almCerrar('almDetalleModal')">close</i>
        </div>
        <div class="alm-modal-body">
            <div style="text-align:center;">
                <span id="almDetCodigo" style="font-family:monospace;font-weight:800;font-size:13px;color:#0067b1;background:#e1effa;display:inline-block;padding:3px 10px;border-radius:6px;"></span>
                <div id="almDetNombre" style="font-size:16px;font-weight:800;color:#1e293b;margin-top:6px;"></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div style="background:#f8fafc;border-radius:8px;padding:10px;">
                    <div style="font-size:10.5px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.3px;">Unidad</div>
                    <div id="almDetUm" style="font-size:14px;font-weight:700;color:#334155;margin-top:2px;"></div>
                </div>
                <div style="background:#f8fafc;border-radius:8px;padding:10px;">
                    <div style="font-size:10.5px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.3px;">Categoría</div>
                    <div id="almDetCat" style="font-size:14px;font-weight:700;color:#334155;margin-top:2px;"></div>
                </div>
                <div style="background:#f8fafc;border-radius:8px;padding:10px;">
                    <div style="font-size:10.5px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.3px;">Stock en <span id="almDetAlmNombre" style="text-transform:none;color:#64748b;font-weight:700;"></span></div>
                    <div id="almDetSaldo" style="font-size:18px;font-weight:900;color:#0f172a;margin-top:2px;"></div>
                </div>
                <div style="background:#f8fafc;border-radius:8px;padding:10px;">
                    <div style="font-size:10.5px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.3px;">Stock mínimo</div>
                    <div id="almDetMin" style="font-size:14px;font-weight:700;color:#334155;margin-top:2px;"></div>
                </div>
            </div>
            <div id="almDetBajoBadge" style="display:none;background:#fff7ed;border:1px solid #fed7aa;color:#b45309;border-radius:8px;padding:8px 10px;font-size:12.5px;font-weight:700;text-align:center;">
                <i class="material-icons" style="font-size:15px;vertical-align:middle;">warning</i> Este producto está en o por debajo de su stock mínimo en este almacén.
            </div>
            <div style="border-top:1px solid #f1f5f9;padding-top:12px;display:flex;flex-direction:column;gap:7px;">
                @if($puedeMover ?? false)
                <button type="button" class="alm-det-act" onclick="window.almDetalleAccion('entrada')"><span class="alm-det-ic" style="background:#dcfce7;color:#16a34a;"><i class="material-icons" style="font-size:18px;">add</i></span> Registrar entrada</button>
                <button type="button" class="alm-det-act" onclick="window.almDetalleAccion('salida')"><span class="alm-det-ic" style="background:#fee2e2;color:#dc2626;"><i class="material-icons" style="font-size:18px;">remove</i></span> Registrar salida</button>
                <button type="button" class="alm-det-act" onclick="window.almDetalleAccion('ajuste')"><span class="alm-det-ic" style="background:#dbeafe;color:#0067b1;"><i class="material-icons" style="font-size:18px;">tune</i></span> Ajustar saldo / fijar mínimo</button>
                @endif
                <button type="button" class="alm-det-act" onclick="window.almDetalleAccion('kardex')"><span class="alm-det-ic" style="background:#f1f5f9;color:#475569;"><i class="material-icons" style="font-size:18px;">history</i></span> Ver movimientos del producto</button>
                @if($puedeManage ?? false)
                <button type="button" class="alm-det-act" onclick="window.almDetalleAccion('editar')"><span class="alm-det-ic" style="background:#cffafe;color:#0891b2;"><i class="material-icons" style="font-size:18px;">edit</i></span> Editar producto</button>
                <button type="button" class="alm-det-act" onclick="window.almDetalleAccion('eliminar')"><span class="alm-det-ic" style="background:#fee2e2;color:#ef4444;"><i class="material-icons" style="font-size:18px;">delete_outline</i></span> Eliminar / desactivar producto</button>
                @endif
            </div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almDetalleModal')">Cerrar</button>
        </div>
    </div>
</div>

@if($puedeMover)
{{-- ── Salida / Enviar a otro almacén: aquí se indican las cantidades de los productos seleccionados en la tabla ── --}}
<div id="almSalidaModal" class="alm-modal-overlay">
    <div class="alm-modal alm-modal-wide" style="max-width:820px;">
        <div class="alm-modal-head">
            <h3><i class="material-icons" id="almSalidaIcon" style="font-size:20px;">north_east</i> <span id="almSalidaTitulo">Registrar salida</span></h3>
            <i class="material-icons alm-x" onclick="almCerrar('almSalidaModal')">close</i>
        </div>
        <div class="alm-modal-body">
            <div id="almSalidaDestinoWrap" style="display:none;margin-bottom:12px;">
                <label>Almacén destino *</label>
                <select id="almSalidaDestino" onchange="window.almSalidaOnDestinoChange()">
                    <option value="">— elige un almacén —</option>
                    @foreach(($almacenes ?? collect()) as $a)
                        <option value="{{ $a->ID_ALMACEN }}">{{ $a->NOMBRE }} {{ $a->TIPO === 'GENERAL' ? '(Principal)' : '(Proyecto)' }}</option>
                    @endforeach
                </select>
            </div>
            <div id="almSalidaFrenteWrap" style="display:none;margin-bottom:12px;">
                <label>Frente que recibe el envío *</label>
                <select id="almSalidaFrente">
                    <option value="">— elige el frente —</option>
                </select>
                <div style="font-size:11px;color:#94a3b8;margin-top:4px;">Un mismo almacén puede surtir a varios frentes; aquí se registra cuál de ellos recibe este envío.</div>
            </div>
            <div style="overflow:auto;border:1px solid #e2e8f0;border-radius:10px;max-height:52vh;">
                <table class="alm-kardex-table">
                    <thead><tr>
                        <th>Código</th><th>Producto</th><th style="text-align:right;">Stock actual</th><th style="text-align:right;width:120px;">Cantidad *</th><th style="width:36px;"></th>
                    </tr></thead>
                    <tbody id="almSalidaLineas"></tbody>
                </table>
            </div>
            <div style="font-size:11.5px;color:#94a3b8;margin-top:6px;">Escribe cuánto sacar de cada producto. Usa la papelera para quitar alguno de la lista.</div>
            <div style="margin-top:8px;"><label>Motivo / referencia (opcional)</label><input type="text" id="almSalidaMotivo" maxlength="200" placeholder="Ej: consumo de obra"></div>
            <div id="almSalidaError" style="display:none;color:#dc2626;font-size:13px;font-weight:600;margin-top:6px;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almSalidaModal')">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" id="almSalidaSubmit" onclick="window.almSalidaConfirmar()">Registrar salida</button>
        </div>
    </div>
</div>
@endif

<script>
(function () {
    'use strict';
    // Guard: si el módulo se re-monta (navegación SPA) no re-bindear listeners
    // de documento; las funciones window.alm* del primer montaje siguen válidas.
    if (window.__almIndexInit) return;
    window.__almIndexInit = true;

    var ROUTE_INDEX   = @json(route('almacen.index'));
    var ROUTE_MOV     = @json(route('almacen.movimientos.store'));
    var ROUTE_LOTE    = @json(route('almacen.movimientos.lote'));
    var ROUTE_PROD    = @json(route('almacen.productos.store'));
    // Catálogo de productos (CODIGO/NOMBRE/UM) — lo usan las sugerencias del filtro "Buscar" y los selects del modal de movimientos.
    window.almProductosLista = @json($productosLista ?? collect());
    // Categorías ya registradas — alimentan la lista del campo "Categoría" del modal de producto.
    window.almCategoriasLista = @json(($categorias ?? collect())->filter()->values());
    // Mapa almacén → IDs de frentes asociados (pivot almacen_frentes). Lo usa el modal de
    // envío/traspaso para pedir QUÉ frente recibe el envío cuando el almacén destino surte
    // a varios proyectos (p.ej. un almacén local de "DIVISION CARABOBO" que surte a 3 frentes).
    window.almAlmacenesFrentes = @json(($almacenes ?? collect())->mapWithKeys(fn ($a) => [$a->ID_ALMACEN => ($a->relationLoaded('frentes') ? $a->frentes->pluck('ID_FRENTE')->values() : [])]));
    // Mapa { ID_FRENTE: 'NOMBRE_FRENTE' } para pintar el <select> de frente destino.
    window.almFrentesNombres = @json(($frentesLista ?? collect())->mapWithKeys(fn ($f) => [$f->ID_FRENTE => $f->NOMBRE_FRENTE]));
    function ROUTE_MIN(idAlm)   { return ROUTE_INDEX + '/almacenes/' + idAlm + '/minimo'; }
    function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.getAttribute('content') : ''; }
    function toast(msg, type) { if (window.showToast) window.showToast(msg, type || 'success'); else if (type === 'error') alert(msg); }
    function pre()  { if (typeof window.showPreloader === 'function') window.showPreloader(); }
    function unpre(){ if (typeof window.hidePreloader === 'function') window.hidePreloader(); }
    function el(id){ return document.getElementById(id); }
    function val(id){ var e = el(id); return e ? String(e.value).trim() : ''; }
    function escHtml(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; }); }

    // ── estado de los filtros que no tienen control visible propio ──
    var soloConSaldo = false; // alternado desde el atajo "Con stock" del sidebar
    var soloBajo     = false; // alternado desde el atajo "Stock bajo" del sidebar

    // ── debounce para los inputs de texto ──
    var _t = null;
    window.almDebounce = function (fn) { clearTimeout(_t); _t = setTimeout(fn, 350); };

    // ── filtros → params (única fuente de verdad de los filtros activos) ──
    function filtros() {
        var p = new URLSearchParams();
        var alm = val('almSelAlmacen'); if (alm) p.set('id_almacen', alm);
        var b   = val('almFiltroBuscar'); if (b) p.set('search', b);
        var cat = val('almFiltroCat');  if (cat) p.set('categoria', cat);
        if (soloBajo)                   p.set('solo_bajo', '1');
        if (soloConSaldo)               p.set('solo_con_saldo', '1');
        // reflejar estado "active" en los wrappers
        var setActive = function (sel, on) { var w = sel && sel.closest('.alm-filter'); if (w) w.classList.toggle('active', !!on); };
        setActive(el('almFiltroBuscar'), b); setActive(el('almFiltroCat'), cat && cat !== 'all');
        // toggle de la "x" de limpiar
        var tx = function (inputId) { var i = el(inputId); if (!i) return; var x = i.parentElement.querySelector('.filter-clear'); if (x) x.style.display = i.value ? 'flex' : 'none'; };
        tx('almFiltroBuscar'); tx('almFiltroCat');
        return p;
    }

    // ── carga AJAX de la tabla + sidebar ──
    window.almCargar = function (url) {
        var body = el('almTableBody'); if (!body) return;
        var finalUrl;
        if (url) {
            var u = new URL(url, window.location.origin);
            // fusionar filtros actuales en la URL de paginación (y limpiar los obsoletos)
            var f = filtros(); f.forEach(function (v, k) { u.searchParams.set(k, v); });
            ['id_almacen','search','categoria','solo_bajo','solo_con_saldo'].forEach(function (k) { if (!f.has(k)) u.searchParams.delete(k); });
            finalUrl = u.toString();
        } else {
            finalUrl = ROUTE_INDEX + '?' + filtros().toString();
        }
        body.style.opacity = '0.5';
        pre();
        fetch(finalUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.html !== undefined) body.innerHTML = data.html;
                almSelApplyToVisible(); // re-pintar el azul de las filas que sigan seleccionadas
                var pg = el('almPagination'); if (pg) pg.innerHTML = data.pagination || '';
                if (data.stats) {
                    var num = function (id, v) { var e = el(id); if (e) e.textContent = (v == null ? 0 : v); };
                    num('almStatsTotal',    data.stats.total);
                    num('almStatsConSaldo', data.stats.con_saldo);
                    num('almStatsBajo',     data.stats.stock_bajo);
                    var u2 = el('almStatsUnidades'); if (u2) u2.textContent = formatNum(data.stats.unidades);
                }
                if (data.distribucionHtml !== undefined) { var dc = el('almDistribucionContainer'); if (dc) dc.innerHTML = data.distribucionHtml; }
                if (data.almacen) { var an = el('almAlmacenNombre'); if (an) an.textContent = data.almacen.NOMBRE || '—'; }
                // URL para compartir
                try {
                    var cleanU = new URL(ROUTE_INDEX, window.location.origin);
                    filtros().forEach(function (v, k) { cleanU.searchParams.set(k, v); });
                    window.history.replaceState({}, '', cleanU.toString());
                } catch (e) {}
            })
            .catch(function () { toast('No se pudo cargar el inventario.', 'error'); })
            .finally(function () { body.style.opacity = '1'; unpre(); });
    };

    function formatNum(n) {
        n = parseFloat(n || 0);
        if (isNaN(n)) return '0';
        var s = n.toFixed(3).replace(/\.?0+$/, '');
        return s === '' ? '0' : s;
    }

    // ── helpers desde el sidebar / distribución ──
    window.almVerTodo = function () {
        if (el('almFiltroBuscar')) el('almFiltroBuscar').value = '';
        if (el('almFiltroCat')) el('almFiltroCat').value = '';
        almSuggestHide(); almCatSuggestHide();
        soloBajo = false; soloConSaldo = false;
        almCargar();
    };
    window.almFilterByCategoria = function (cat) { var s = el('almFiltroCat'); if (s) { s.value = cat || ''; } almCatSuggestHide(); almCargar(); };
    window.almFiltrarConSaldo = function () { soloConSaldo = true; soloBajo = false; almCargar(); };
    window.almFiltrarBajo = function () { soloBajo = true; soloConSaldo = false; almCargar(); };

    // ── Autocompletado del filtro "Buscar" (código o descripción), con el look de los desplegables de la app ──
    function almNorm(s) { return s ? String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase() : ''; }
    function almSuggestHide() { var box = el('almFiltroBuscarSuggest'); if (box) box.classList.remove('open'); }
    function almCatSuggestHide() { var box = el('almFiltroCatSuggest'); if (box) box.classList.remove('open'); }
    window.almBuscarSuggest = function () {
        almCatSuggestHide();
        var inp = el('almFiltroBuscar'), box = el('almFiltroBuscarSuggest');
        if (!inp || !box) return;
        var term = almNorm(inp.value.trim());
        var lista = window.almProductosLista || [];
        var matches;
        if (term === '') {
            matches = lista.slice(0, 12); // foco sin texto → primeros productos
        } else {
            matches = [];
            for (var i = 0; i < lista.length && matches.length < 12; i++) {
                var p = lista[i];
                if (almNorm(p.CODIGO).indexOf(term) > -1 || almNorm(p.NOMBRE).indexOf(term) > -1) matches.push(p);
            }
        }
        if (!matches.length) {
            box.innerHTML = '<div class="alm-suggest-empty">Sin coincidencias.</div>';
        } else {
            box.innerHTML = matches.map(function (p) {
                var cod = (p.CODIGO || '').replace(/[<>&"]/g, '');
                var nom = (p.NOMBRE || '').replace(/[<>&"]/g, '');
                return '<div class="alm-suggest-item" data-pick="' + cod + '">'
                     + (cod ? '<span class="cod">' + cod + '</span>' : '')
                     + '<span class="nom">' + nom + '</span></div>';
            }).join('');
        }
        box.classList.add('open');
    };
    window.almBuscarInput = function () {
        window.almBuscarSuggest();
        almDebounce(almCargar);
    };
    window.almBuscarPick = function (codigo) {
        var inp = el('almFiltroBuscar'); if (inp) inp.value = codigo;
        almSuggestHide();
        almCargar();
    };
    window.almBuscarLimpiar = function () {
        var inp = el('almFiltroBuscar'); if (inp) inp.value = '';
        almSuggestHide();
        almCargar();
    };
    // ── Autocompletado del filtro "Categoría" (lista de categorías ya registradas), mismo look que "Buscar" ──
    window.almCatSuggest = function () {
        almSuggestHide();
        var inp = el('almFiltroCat'), box = el('almFiltroCatSuggest');
        if (!inp || !box) return;
        var term  = almNorm(inp.value.trim());
        var lista = (window.almCategoriasLista || []);
        var matches = term === '' ? lista.slice(0) : lista.filter(function (c) { return almNorm(c).indexOf(term) > -1; });
        if (!matches.length) {
            box.innerHTML = '<div class="alm-suggest-empty">' + (lista.length ? 'Sin categorías que coincidan.' : 'No hay categorías registradas.') + '</div>';
        } else {
            box.innerHTML = matches.map(function (c) {
                var safe = String(c).replace(/[<>&"]/g, '');
                return '<div class="alm-suggest-item" data-pick="' + safe + '"><span class="nom">' + safe + '</span></div>';
            }).join('');
        }
        box.classList.add('open');
    };
    window.almCatInput = function () {
        window.almCatSuggest();
        almDebounce(almCargar);
    };
    window.almCatPick = function (cat) {
        var inp = el('almFiltroCat'); if (inp) inp.value = cat;
        almCatSuggestHide();
        almCargar();
    };
    window.almCatLimpiar = function () {
        var inp = el('almFiltroCat'); if (inp) inp.value = '';
        almCatSuggestHide();
        almCargar();
    };

    // ── Filtro de almacén: ahora usa el componente custom-dropdown global (selectOption / dropdown-selection).
    //    El hidden #almSelAlmacen sigue siendo la fuente de verdad que lee filtros(); el listener de abajo
    //    recarga la tabla cuando el usuario elige un almacén distinto.
    window.addEventListener('dropdown-selection', function (e) {
        if (e.detail && e.detail.dropdownId === 'almSelAlmacenDropdown') almCargar();
    });

    // Click en una sugerencia (Buscar / Categoría) / click fuera / Escape — el filtro Almacén ya no usa este sistema.
    document.addEventListener('click', function (e) {
        var item = e.target.closest('#almFiltroBuscarSuggest .alm-suggest-item');
        if (item) { e.preventDefault(); window.almBuscarPick(item.getAttribute('data-pick') || ''); return; }
        var catItem = e.target.closest('#almFiltroCatSuggest .alm-suggest-item');
        if (catItem) { e.preventDefault(); window.almCatPick(catItem.getAttribute('data-pick') || ''); return; }
        if (!e.target.closest('.alm-filter')) { almSuggestHide(); almCatSuggestHide(); }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { almSuggestHide(); almCatSuggestHide(); } });

    // ── paginación (event delegation) ──
    document.addEventListener('click', function (e) {
        var a = e.target.closest('#almPagination a');
        if (a) { e.preventDefault(); e.stopPropagation(); window.almCargar(a.href); }
    }, true);

    // ════════════════════════════════════════════════════════════════════════
    //  Selección de productos en la tabla — IGUAL que /admin/equipos:
    //  clic en una fila → se resalta en azul (.selected-row-maquinaria) y aparece
    //  la barra flotante #almBulkBar con el conteo y las acciones.
    //  Las cantidades a sacar/enviar se piden DESPUÉS, en el modal #almSalidaModal
    //  (una fila por producto seleccionado), y se mandan a `almacen.movimientos.lote`.
    //  La selección vive en memoria (id → {codigo,nombre,um,saldo}); sobrevive a
    //  filtros/paginación (que recargan sólo el <tbody>) y se re-pinta con
    //  almSelApplyToVisible() tras cada recarga.
    // ════════════════════════════════════════════════════════════════════════
    var almSeleccion = {}; // { id_producto: { codigo, nombre, um, saldo } }
    function almSelCount() { return Object.keys(almSeleccion).length; }
    function almSelRefreshBar() {
        var bar = el('almBulkBar'); if (!bar) return;
        var n = almSelCount();
        bar.classList.toggle('active', n > 0);
        var c = el('almBulkCount'); if (c) c.textContent = n;
    }
    function almSelMarkRow(tr, on) { if (tr) tr.classList.toggle('selected-row-maquinaria', !!on); }
    // Re-pinta el resaltado azul de las filas visibles que estén en la selección (tras cada recarga AJAX del tbody).
    function almSelApplyToVisible() {
        document.querySelectorAll('#almTableBody tr.alm-row').forEach(function (tr) {
            almSelMarkRow(tr, !!almSeleccion[tr.getAttribute('data-id-producto')]);
        });
    }
    window.almSelClear = function (e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        almSeleccion = {};
        document.querySelectorAll('#almTableBody tr.selected-row-maquinaria').forEach(function (tr) { tr.classList.remove('selected-row-maquinaria'); });
        almSelRefreshBar();
    };
    // Clic en una fila de la tabla → toggle de selección (ignora botones / enlaces / inputs, como en /admin/equipos)
    document.addEventListener('click', function (e) {
        var tr = e.target.closest('#almTableBody tr.alm-row');
        if (!tr) return;
        if (e.target.closest('button') || e.target.closest('a') || e.target.closest('input') || e.target.closest('select') || e.target.closest('.custom-dropdown')) return;
        var id = tr.getAttribute('data-id-producto'); if (!id) return;
        if (almSeleccion[id]) { delete almSeleccion[id]; almSelMarkRow(tr, false); }
        else {
            almSeleccion[id] = {
                codigo: tr.getAttribute('data-codigo') || '',
                nombre: tr.getAttribute('data-nombre') || '',
                um:     tr.getAttribute('data-um') || '',
                saldo:  parseFloat(tr.getAttribute('data-saldo') || '0') || 0,
            };
            almSelMarkRow(tr, true);
        }
        almSelRefreshBar();
    });
    function almSelAlmacenActual() { var s = el('almSelAlmacen'); return s ? s.value : ''; }
    // Botones de la barra flotante: abren el modal de cantidades (SALIDA directa / TRASPASO con destino).
    window.almSelAccion = function (tipo) {
        if (!almSelCount()) { toast('Selecciona al menos un producto (clic en su fila).', 'error'); return; }
        if (typeof window.almAbrirSalidaModal !== 'function') { toast('No tienes permiso para registrar movimientos.', 'error'); return; }
        var idAlm = almSelAlmacenActual();
        if (!idAlm) { toast('No hay un almacén seleccionado.', 'error'); return; }
        window.almAbrirSalidaModal(tipo === 'TRASPASO' ? 'TRASPASO' : 'SALIDA', idAlm);
    };

    // ── Campo "Categoría" del modal de producto: desplegable de categorías ya registradas + "escribir una nueva" ──
    // Es un <input> normal (puedes teclear cualquier cosa) con un caret que abre la lista de
    // categorías existentes. Si lo que escribes no está en la lista, aparece "Usar nueva categoría: …"
    // y al guardar el producto esa categoría queda registrada (la lista se deriva de productos_inventario).
    function almProdCatHide() {
        var b = el('almProdCatSuggest'); if (b) b.classList.remove('open');
        var c = el('almProdCatCaret');   if (c) c.classList.remove('open');
    }
    // forceAll = true → muestra TODAS las categorías ignorando el texto actual (lo usan el caret y el focus).
    window.almProdCatSuggest = function (forceAll) {
        var inp = el('almProdCategoria'), box = el('almProdCatSuggest'), caret = el('almProdCatCaret');
        if (!inp || !box) return;
        var raw = inp.value.trim(), term = almNorm(raw);
        var lista = (window.almCategoriasLista || []);
        var matches = (forceAll || term === '') ? lista.slice(0) : lista.filter(function (c) { return almNorm(c).indexOf(term) > -1; });
        var existeExacta = lista.some(function (c) { return almNorm(c) === term; });
        var html = '';
        if (raw !== '' && !existeExacta) {
            html += '<div class="si-item si-new" data-cat="' + escHtml(raw) + '"><i class="material-icons">add_circle</i>Usar nueva categoría: “' + escHtml(raw.toUpperCase()) + '”</div>';
        }
        html += matches.map(function (c) {
            var sel = almNorm(c) === term ? ' si-sel' : '';
            var ic  = sel ? 'check_circle' : 'category';
            return '<div class="si-item' + sel + '" data-cat="' + escHtml(c) + '"><i class="material-icons">' + ic + '</i>' + escHtml(c) + '</div>';
        }).join('');
        if (!html) html = '<div class="alm-suggest-empty">No hay categorías registradas todavía. Escribe una para crearla.</div>';
        box.innerHTML = html;
        box.classList.add('open');
        if (caret) caret.classList.add('open');
    };
    window.almProdCatToggle = function (e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        var box = el('almProdCatSuggest');
        if (box && box.classList.contains('open')) { almProdCatHide(); return; }
        window.almProdCatSuggest(true);
        var inp = el('almProdCategoria'); if (inp) inp.focus();
    };
    window.almProdCatPick = function (cat) { var inp = el('almProdCategoria'); if (inp) inp.value = cat; almProdCatHide(); };

    // Delegación: click en una opción de la lista / click fuera del campo lo cierra.
    document.addEventListener('click', function (e) {
        var item = e.target.closest('#almProdCatSuggest .si-item');
        if (item) { e.preventDefault(); window.almProdCatPick(item.getAttribute('data-cat') || ''); return; }
        // No cerrar si el click fue dentro del propio campo (input + caret) o de la lista.
        if (!e.target.closest('.alm-cat-field') && !e.target.closest('#almProdCatSuggest')) almProdCatHide();
    });
    // Enter dentro del input → si hay coincidencia exacta o "nueva", la fija y cierra.
    var _almProdCatInp = el('almProdCategoria');
    if (_almProdCatInp) _almProdCatInp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); window.almProdCatPick(this.value.trim()); }
        else if (e.key === 'Escape') { almProdCatHide(); }
    });

    // ── modales ──
    function open(id)  { var m = el(id); if (m) m.classList.add('open'); }
    window.almCerrar = function (id) { var m = el(id); if (m) m.classList.remove('open'); };
    document.addEventListener('click', function (e) { if (e.target && e.target.classList && e.target.classList.contains('alm-modal-overlay')) e.target.classList.remove('open'); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') document.querySelectorAll('.alm-modal-overlay.open').forEach(function (m) { m.classList.remove('open'); }); });

    // ── Botón "Acciones" (dropdown estilo /admin/equipos) ──
    window.almToggleAcciones = function (e) {
        if (e) e.stopPropagation();
        var m = el('almAccionesMenu'); if (!m) return;
        m.style.display = (m.style.display === 'block') ? 'none' : 'block';
    };
    document.addEventListener('click', function (e) {
        var m = el('almAccionesMenu');
        if (m && m.style.display === 'block' && !e.target.closest('#almAccionesMenu') && !e.target.closest('#almBtnAcciones')) m.style.display = 'none';
    });
    window.almAccion = function (which) {
        var m = el('almAccionesMenu'); if (m) m.style.display = 'none';
        switch (which) {
            case 'admin':    if (window.almAbrirAdminAlmacenes) window.almAbrirAdminAlmacenes(); break;
            case 'almacen':  if (window.almAbrirAlmacen)        window.almAbrirAlmacen();        break;
            case 'producto': if (window.almAbrirProducto)       window.almAbrirProducto();       break;
        }
    };

    function hoy() { var d = new Date(); var p = function (n) { return (n < 10 ? '0' : '') + n; }; return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()); }
    function showErr(id, msg) { var e = el(id); if (e) { e.textContent = msg; e.style.display = msg ? 'block' : 'none'; } }

    window.almAbrirMovimiento = function (tipo, idProducto, codigo, nombre, um, saldo) {
        el('almMovModal').dataset.tipo = tipo;
        el('almMovModal').dataset.idProducto = idProducto;
        el('almMovTitulo').textContent = tipo === 'ENTRADA' ? 'Registrar entrada' : 'Registrar salida';
        el('almMovIcon').textContent = tipo === 'ENTRADA' ? 'add' : 'remove';
        el('almMovCantLabel').textContent = tipo === 'ENTRADA' ? 'Cantidad que entra' : 'Cantidad que sale';
        el('almMovSubmit').textContent = tipo === 'ENTRADA' ? 'Registrar entrada' : 'Registrar salida';
        el('almMovCodigo').textContent = codigo; el('almMovNombre').textContent = nombre;
        el('almMovSaldo').textContent = formatNum(saldo); el('almMovUm').textContent = um || '';
        el('almMovCantidad').value = ''; el('almMovReferencia').value = ''; el('almMovMotivo').value = ''; el('almMovFecha').value = hoy();
        if (el('almMovFrente')) el('almMovFrente').value = '';
        // El "frente destino" tiene sentido sobre todo en salidas (consumo a un proyecto).
        if (el('almMovFrenteWrap')) el('almMovFrenteWrap').style.display = (tipo === 'SALIDA') ? '' : 'none';
        showErr('almMovError', '');
        open('almMovModal'); setTimeout(function () { el('almMovCantidad').focus(); }, 60);
    };

    window.almGuardarMovimiento = function () {
        var m = el('almMovModal');
        var cant = parseFloat(el('almMovCantidad').value);
        if (!cant || cant <= 0) { showErr('almMovError', 'Indica una cantidad mayor que cero.'); return; }
        var idAlm = val('almSelAlmacen');
        if (!idAlm) { showErr('almMovError', 'No hay almacén seleccionado.'); return; }
        var idFrente = (m.dataset.tipo === 'SALIDA' && el('almMovFrente')) ? (el('almMovFrente').value || null) : null;
        pre();
        fetch(ROUTE_MOV, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: JSON.stringify({
                id_almacen: idAlm, id_producto: m.dataset.idProducto, tipo: m.dataset.tipo, cantidad: cant,
                fecha: val('almMovFecha') || null, referencia: val('almMovReferencia') || null, motivo: val('almMovMotivo') || null,
                id_frente: idFrente
            })
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            unpre();
            if (res.ok) { almCerrar('almMovModal'); toast(res.b.message || 'Movimiento registrado.'); almCargar(); }
            else { showErr('almMovError', res.b.message || 'No se pudo registrar el movimiento.'); }
        })
        .catch(function () { unpre(); showErr('almMovError', 'Error de red.'); });
    };

    // ── Página de movimientos (módulo aparte: /admin/almacen/movimientos) ──
    var ROUTE_MOVIMIENTOS = @json(route('almacen.movimientos'));

    // ── Modal "Detalles del producto" (lo abre el ojo de cada fila; agrupa todas las acciones) ──
    window.almAbrirDetalle = function (id, cod, nom, um, cat, saldo, minimo) {
        var m = el('almDetalleModal'); if (!m) return;
        var hasMin = (minimo !== null && minimo !== undefined && minimo !== '');
        m.dataset.id = id;
        m.dataset.cod = cod || ''; m.dataset.nom = nom || ''; m.dataset.um = um || ''; m.dataset.cat = cat || '';
        m.dataset.saldo = (saldo == null ? '0' : String(saldo));
        m.dataset.minimo = hasMin ? String(minimo) : '';
        el('almDetCodigo').textContent = cod || '—';
        el('almDetNombre').textContent = nom || '';
        el('almDetUm').textContent = um || '—';
        el('almDetCat').textContent = (cat && String(cat).trim()) ? cat : '—';
        el('almDetSaldo').textContent = formatNum(saldo);
        el('almDetMin').textContent = hasMin ? formatNum(minimo) : 'Sin definir';
        var bajo = hasMin && parseFloat(saldo || 0) <= parseFloat(minimo);
        el('almDetBajoBadge').style.display = bajo ? '' : 'none';
        el('almDetSaldo').style.color = bajo ? '#dc2626' : '#0f172a';
        var an = el('almDetAlmNombre'); if (an) an.textContent = (el('almAlmacenNombre') && el('almAlmacenNombre').textContent.trim()) || 'este almacén';
        open('almDetalleModal');
    };
    window.almDetalleAccion = function (which) {
        var m = el('almDetalleModal'); if (!m) return;
        var d = m.dataset, id = parseInt(d.id, 10);
        var minimo = (d.minimo === '' ? null : parseFloat(d.minimo));
        var saldo  = parseFloat(d.saldo || 0);
        var label  = (d.cod || '') + (d.cod && d.nom ? ' — ' : '') + (d.nom || '');
        almCerrar('almDetalleModal');
        switch (which) {
            case 'entrada':  if (window.almAbrirMovimiento) window.almAbrirMovimiento('ENTRADA', id, d.cod, d.nom, d.um, saldo); break;
            case 'salida':   if (window.almAbrirMovimiento) window.almAbrirMovimiento('SALIDA',  id, d.cod, d.nom, d.um, saldo); break;
            case 'ajuste':   if (window.almAbrirAjuste)     window.almAbrirAjuste(id, d.cod, d.nom, d.um, saldo, minimo); break;
            case 'kardex':   window.location = ROUTE_MOVIMIENTOS + '?id_producto=' + id + (val('almSelAlmacen') ? '&id_almacen=' + encodeURIComponent(val('almSelAlmacen')) : ''); break;
            case 'editar':   if (window.almEditarProducto)  window.almEditarProducto(id, d.cod, d.nom, d.um, d.cat); break;
            case 'eliminar': if (window.almEliminarProducto) window.almEliminarProducto(id, label); break;
        }
    };

    window.almAbrirAjuste = function (idProducto, codigo, nombre, um, saldo, minimo) {
        var m = el('almAjusteModal');
        m.dataset.idProducto = idProducto;
        m.dataset.minimoOrig = (minimo == null ? '' : String(minimo)); // para detectar si el usuario lo cambió
        el('almAjCodigo').textContent = codigo; el('almAjNombre').textContent = nombre;
        el('almAjSaldo').textContent = formatNum(saldo); el('almAjUm').textContent = um || '';
        el('almAjNuevoSaldo').value = ''; el('almAjMinimo').value = (minimo == null ? '' : minimo); el('almAjMotivo').value = '';
        showErr('almAjError', ''); open('almAjusteModal');
    };

    window.almGuardarAjuste = function () {
        var m = el('almAjusteModal');
        var idAlm = val('almSelAlmacen'); if (!idAlm) { showErr('almAjError', 'No hay almacén seleccionado.'); return; }
        var nuevoSaldoRaw = val('almAjNuevoSaldo');
        var minimoRaw = val('almAjMinimo');

        // Validaciones previas (antes de disparar nada).
        var ns = null;
        if (nuevoSaldoRaw !== '') {
            ns = parseFloat(nuevoSaldoRaw);
            if (isNaN(ns) || ns < 0) { showErr('almAjError', 'El nuevo saldo debe ser un número ≥ 0.'); return; }
        }
        var cambiaMinimo = (minimoRaw !== (m.dataset.minimoOrig || ''));
        var nuevoMinimo = null;
        if (cambiaMinimo && minimoRaw !== '') {
            nuevoMinimo = parseFloat(minimoRaw);
            if (isNaN(nuevoMinimo) || nuevoMinimo < 0) { showErr('almAjError', 'El mínimo debe ser un número ≥ 0 (o vacío).'); return; }
        }
        if (ns === null && !cambiaMinimo) { almCerrar('almAjusteModal'); return; } // nada que hacer

        var tareas = [];
        if (ns !== null) {
            tareas.push(fetch(ROUTE_MOV, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: JSON.stringify({ id_almacen: idAlm, id_producto: m.dataset.idProducto, tipo: 'AJUSTE', cantidad: ns, motivo: val('almAjMotivo') || 'Ajuste de inventario' })
            }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); }));
        }
        if (cambiaMinimo) {
            tareas.push(fetch(ROUTE_MIN(idAlm), {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: JSON.stringify({ id_producto: m.dataset.idProducto, cantidad_minima: (minimoRaw === '' ? null : nuevoMinimo) })
            }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); }));
        }

        pre();
        Promise.all(tareas).then(function (ress) {
            unpre();
            var fail = ress.find(function (x) { return !x.ok; });
            if (fail) { showErr('almAjError', (fail.b && fail.b.message) || 'No se pudo aplicar el ajuste.'); almCargar(); return; }
            almCerrar('almAjusteModal'); toast('Ajuste aplicado.'); almCargar();
        }).catch(function () { unpre(); showErr('almAjError', 'Error de red.'); });
    };

    // confirmación reutilizable (usa el modal estándar de la app si existe; si no, confirm()).
    function almConfirm(msg, onYes) {
        if (window.showModal) {
            window.showModal({ type: 'danger', title: '¿Confirmar?', message: msg, confirmText: 'Sí', cancelText: 'Cancelar', onConfirm: onYes });
        } else if (window.confirm(msg.replace(/<[^>]+>/g, ''))) { onYes(); }
    }

    @if($puedeManage)
    var ROUTE_ALM = @json(route('almacen.almacenes.store'));
    function ROUTE_ALM_ITEM(id) { return ROUTE_INDEX + '/almacenes/' + id; }
    function ROUTE_PROD_ITEM(id) { return ROUTE_INDEX + '/productos/' + id; }
    // Datos de los almacenes visibles (para el modal de edición): { id: {NOMBRE,TIPO,CODIGO,UBICACION,frentes:[ids]} }
    window.almAlmacenesData = @json($almacenesData);

    window.almToggleFrentes = function () {
        var wrap = el('almNvFrentesWrap'); if (!wrap) return;
        wrap.style.display = (val('almNvTipo') === 'PROYECTO') ? '' : 'none';
    };
    // Checkboxes del multiselect de frentes del modal de almacén.
    function almNvFrenteChecks() { return Array.prototype.slice.call(document.querySelectorAll('#almNvFrentesSelect input[type="checkbox"]')); }
    // Filtra las opciones de frente al escribir en el input principal del trigger.
    window.almNvFrentesFilter = function (inp) {
        var v = (inp && inp.value || '').toLowerCase().trim();
        var visibles = 0;
        document.querySelectorAll('#almNvFrentesSelect .alm-frente-opt').forEach(function (i) {
            var match = v === '' || i.textContent.toLowerCase().indexOf(v) > -1;
            i.style.display = match ? '' : 'none';
            if (match) visibles++;
        });
        var noMatch = el('almNvFrentesNoMatch');
        if (noMatch) noMatch.style.display = (v !== '' && visibles === 0) ? '' : 'none';
        // Mientras filtra, mantener el menú abierto.
        var box = el('almNvFrentesSelect');
        if (box && v !== '' && !box.classList.contains('active')) box.classList.add('active');
    };
    // Actualiza el placeholder del trigger según cuántos frentes están marcados.
    window.almNvFrentesUpdate = function () {
        var inp = el('almNvFrentesInput'); if (!inp) return;
        var sel = almNvFrenteChecks().filter(function (c) { return c.checked; });
        if (sel.length === 0)      inp.placeholder = 'Selecciona los frentes…';
        else if (sel.length === 1) {
            var sp = sel[0].closest('.multiselect-item').querySelector('span');
            inp.placeholder = sp ? sp.textContent.trim() : '1 frente';
        }
        else inp.placeholder = sel.length + ' frentes seleccionados';
    };
    function almNvSetFrentes(ids) {
        var set = {}; (ids || []).forEach(function (x) { set[String(x)] = true; });
        almNvFrenteChecks().forEach(function (c) { c.checked = !!set[c.value]; });
        // Reset del filtro (vaciar el input principal y volver a mostrar todas las opciones).
        var inp = el('almNvFrentesInput'); if (inp) inp.value = '';
        var box = el('almNvFrentesSelect');
        if (box) {
            box.querySelectorAll('.alm-frente-opt').forEach(function (i) { i.style.display = ''; });
            var nm = el('almNvFrentesNoMatch'); if (nm) nm.style.display = 'none';
            box.classList.remove('active');
        }
        window.almNvFrentesUpdate();
    }
    function almResetAlmacenModal() {
        delete el('almAlmacenModal').dataset.idAlmacen;
        el('almNvNombre').value = ''; el('almNvUbicacion').value = '';
        el('almNvTipo').value = 'PROYECTO';
        almNvSetFrentes([]);
        window.almToggleFrentes(); showErr('almNvError', '');
    }
    window.almAbrirAlmacen = function () {
        almResetAlmacenModal();
        el('almNvTitulo').textContent = 'Nuevo almacén'; el('almNvSubmit').textContent = 'Crear almacén';
        open('almAlmacenModal'); setTimeout(function () { el('almNvNombre').focus(); }, 60);
    };
    window.almEditarAlmacen = function (id) {
        var d = (window.almAlmacenesData || {})[id]; if (!d) { toast('No se encontró el almacén.', 'error'); return; }
        almResetAlmacenModal();
        el('almAlmacenModal').dataset.idAlmacen = id;
        el('almNvTitulo').textContent = 'Editar almacén'; el('almNvSubmit').textContent = 'Guardar cambios';
        el('almNvNombre').value = d.NOMBRE || ''; el('almNvUbicacion').value = d.UBICACION || '';
        el('almNvTipo').value = d.TIPO || 'PROYECTO';
        almNvSetFrentes(d.frentes || []);
        window.almToggleFrentes();
        almCerrar('almAdminAlmacenesModal');
        open('almAlmacenModal'); setTimeout(function () { el('almNvNombre').focus(); }, 60);
    };
    window.almGuardarAlmacen = function () {
        var m = el('almAlmacenModal'), id = m.dataset.idAlmacen || null;
        var nombre = val('almNvNombre'), tipo = val('almNvTipo') || 'PROYECTO';
        if (!nombre) { showErr('almNvError', 'El nombre es obligatorio.'); return; }
        var frentes = [];
        if (tipo === 'PROYECTO') {
            almNvFrenteChecks().forEach(function (c) { if (c.checked) frentes.push(parseInt(c.value, 10)); });
        }
        var url = id ? ROUTE_ALM_ITEM(id) : ROUTE_ALM;
        pre();
        fetch(url, {
            method: id ? 'PATCH' : 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: JSON.stringify({ NOMBRE: nombre, TIPO: tipo, UBICACION: val('almNvUbicacion') || null, frentes: frentes })
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            unpre();
            if (res.ok) {
                almCerrar('almAlmacenModal'); toast(res.b.message || (id ? 'Almacén actualizado.' : 'Almacén creado.'));
                var newId = res.b.almacen && (res.b.almacen.ID_ALMACEN || res.b.almacen.id);
                // recargar: cambió la lista del selector / nombres
                setTimeout(function () { window.location = ROUTE_INDEX + ((id || newId) ? ('?id_almacen=' + (id || newId)) : ''); }, 500);
            } else {
                var msg = (res.b && res.b.message) || 'No se pudo guardar el almacén.';
                if (res.b && res.b.errors) { msg = Object.values(res.b.errors).map(function (a) { return a.join(' '); }).join(' '); }
                showErr('almNvError', msg);
            }
        })
        .catch(function () { unpre(); showErr('almNvError', 'Error de red.'); });
    };
    window.almAbrirAdminAlmacenes = function () { open('almAdminAlmacenesModal'); };
    window.almEliminarAlmacen = function (id, nombre) {
        almConfirm('¿Eliminar el almacén "<strong>' + nombre + '</strong>"? Si tiene movimientos registrados se desactivará en lugar de borrarse.', function () {
            pre();
            fetch(ROUTE_ALM_ITEM(id), { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
            .then(function (res) { unpre(); if (res.ok) { toast(res.b.message || 'Almacén eliminado.'); setTimeout(function () { window.location = ROUTE_INDEX; }, 500); } else { toast((res.b && res.b.message) || 'No se pudo eliminar.', 'error'); } })
            .catch(function () { unpre(); toast('Error de red.', 'error'); });
        });
    };

    function almResetProductoModal() {
        delete el('almProductoModal').dataset.idProducto;
        el('almProdCodigo').value = ''; el('almProdNombre').value = ''; el('almProdUm').value = 'UND'; el('almProdCategoria').value = '';
        var cs = el('almProdCatSuggest'); if (cs) cs.innerHTML = '';
        almProdCatHide();
        showErr('almProdError', '');
    }
    window.almAbrirProducto = function () {
        almResetProductoModal();
        el('almProdTitulo').textContent = 'Nuevo producto'; el('almProdSubmit').textContent = 'Crear';
        open('almProductoModal'); setTimeout(function () { el('almProdCodigo').focus(); }, 60);
    };
    window.almEditarProducto = function (id, cod, nom, um, cat) {
        almResetProductoModal();
        el('almProductoModal').dataset.idProducto = id;
        el('almProdTitulo').textContent = 'Editar producto'; el('almProdSubmit').textContent = 'Guardar cambios';
        el('almProdCodigo').value = cod || ''; el('almProdNombre').value = nom || ''; el('almProdUm').value = um || 'UND'; el('almProdCategoria').value = cat || '';
        open('almProductoModal'); setTimeout(function () { el('almProdNombre').focus(); }, 60);
    };
    window.almGuardarProducto = function () {
        var m = el('almProductoModal'), id = m.dataset.idProducto || null;
        var codigo = val('almProdCodigo'), nombre = val('almProdNombre'), um = val('almProdUm') || 'UND', cat = val('almProdCategoria');
        // El código es opcional al crear: si va vacío, el backend genera uno automáticamente.
        if (!nombre) { showErr('almProdError', 'La descripción es obligatoria.'); return; }
        pre();
        fetch(id ? ROUTE_PROD_ITEM(id) : ROUTE_PROD, {
            method: id ? 'PATCH' : 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: JSON.stringify({ CODIGO: codigo || null, NOMBRE: nombre, UM: um, CATEGORIA: cat || null })
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            unpre();
            if (res.ok) { almCerrar('almProductoModal'); toast(res.b.message || (id ? 'Producto actualizado.' : 'Producto creado.')); almCargar(); }
            else {
                var msg = (res.b && res.b.message) || 'No se pudo guardar el producto.';
                if (res.b && res.b.errors) { msg = Object.values(res.b.errors).map(function (a) { return a.join(' '); }).join(' '); }
                showErr('almProdError', msg);
            }
        })
        .catch(function () { unpre(); showErr('almProdError', 'Error de red.'); });
    };
    window.almEliminarProducto = function (id, label) {
        almConfirm('¿Eliminar el producto "<strong>' + label + '</strong>"? Si tiene saldo o movimientos se desactivará en lugar de borrarse.', function () {
            pre();
            fetch(ROUTE_PROD_ITEM(id), { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
            .then(function (res) { unpre(); if (res.ok) { toast(res.b.message || 'Producto eliminado.'); almCargar(); } else { toast((res.b && res.b.message) || 'No se pudo eliminar.', 'error'); } })
            .catch(function () { unpre(); toast('Error de red.', 'error'); });
        });
    };
    @else
    window.almAbrirAlmacen        = function () { toast('No tienes permiso para crear almacenes.', 'error'); };
    window.almEditarAlmacen       = function () { toast('No tienes permiso para editar almacenes.', 'error'); };
    window.almEliminarAlmacen     = function () { toast('No tienes permiso para eliminar almacenes.', 'error'); };
    window.almAbrirAdminAlmacenes = function () { toast('No tienes permiso para gestionar almacenes.', 'error'); };
    window.almAbrirProducto       = function () { toast('No tienes permiso para crear productos.', 'error'); };
    window.almEditarProducto      = function () { toast('No tienes permiso para editar productos.', 'error'); };
    window.almEliminarProducto    = function () { toast('No tienes permiso para eliminar productos.', 'error'); };
    @endif

    @if($puedeMover)
    // ── Modal de cantidades para la selección de la tabla: SALIDA directa / TRASPASO (pide almacén destino) ──
    //  ALM_SAL.tipo = 'SALIDA' | 'TRASPASO' ; ALM_SAL.idAlmacen = almacén de origen (el que muestra la tabla).
    var ALM_SAL = { tipo: 'SALIDA', idAlmacen: '' };
    function almSalNum(n) { n = parseFloat(n || 0); if (isNaN(n)) return '0'; var s = n.toFixed(3).replace(/\.?0+$/, ''); return s === '' ? '0' : s; }
    window.almAbrirSalidaModal = function (tipo, idAlmacen) {
        ALM_SAL = { tipo: tipo === 'TRASPASO' ? 'TRASPASO' : 'SALIDA', idAlmacen: String(idAlmacen || '') };
        var esTraspaso = ALM_SAL.tipo === 'TRASPASO';
        if (el('almSalidaTitulo')) el('almSalidaTitulo').textContent = esTraspaso ? 'Enviar a otro almacén' : 'Registrar salida';
        if (el('almSalidaIcon'))   el('almSalidaIcon').textContent   = esTraspaso ? 'swap_horiz' : 'north_east';
        if (el('almSalidaSubmit')) el('almSalidaSubmit').textContent = esTraspaso ? 'Enviar' : 'Registrar salida';
        var dw = el('almSalidaDestinoWrap'); if (dw) dw.style.display = esTraspaso ? 'block' : 'none';
        var ds = el('almSalidaDestino');
        if (ds) { ds.value = ''; Array.prototype.forEach.call(ds.options, function (o) { o.disabled = (!!o.value && o.value === ALM_SAL.idAlmacen); }); }
        // Reset del picker de frente destino (sólo se muestra en TRASPASO cuando el almacén destino tiene frentes asociados)
        var fw = el('almSalidaFrenteWrap'); if (fw) fw.style.display = 'none';
        var fs = el('almSalidaFrente'); if (fs) fs.value = '';
        if (el('almSalidaMotivo')) el('almSalidaMotivo').value = '';
        showErr('almSalidaError', '');
        var tb = el('almSalidaLineas');
        if (tb) {
            tb.innerHTML = '';
            Object.keys(almSeleccion).forEach(function (id) {
                var s = almSeleccion[id];
                var tr = document.createElement('tr');
                tr.setAttribute('data-id', id);
                tr.innerHTML =
                    '<td style="font-family:monospace;font-weight:700;white-space:nowrap;">' + escHtml(s.codigo) + '</td>' +
                    '<td style="font-weight:600;">' + escHtml(s.nombre) + '</td>' +
                    '<td style="text-align:right;color:#64748b;white-space:nowrap;">' + almSalNum(s.saldo) + ' ' + escHtml(s.um || '') + '</td>' +
                    '<td style="text-align:right;"><input type="number" class="alm-sal-cant" min="0" step="any" placeholder="0" style="width:100px;padding:5px 8px;border:1px solid #cbd5e0;border-radius:7px;text-align:right;font-size:13px;font-weight:700;"></td>' +
                    '<td style="text-align:center;"><button type="button" class="alm-btn alm-btn-del" title="Quitar de la lista" onclick="window.almSalidaQuitar(this,\'' + id + '\')"><i class="material-icons" style="font-size:16px;">close</i></button></td>';
                tb.appendChild(tr);
            });
        }
        open('almSalidaModal');
        setTimeout(function () { var f = el('almSalidaLineas') ? el('almSalidaLineas').querySelector('.alm-sal-cant') : null; if (f) f.focus(); }, 60);
    };
    // Cuando cambias el almacén destino, repoblamos el dropdown de "Frente destino" con
    // los frentes asociados a ese almacén (pivot `almacen_frentes`):
    //  • 0 frentes (almacén GENERAL principal) → el picker queda oculto, no se pide.
    //  • 1 frente  → se preselecciona automáticamente y el picker se queda visible (informativo).
    //  • 2+        → el operario tiene que elegir cuál de los proyectos recibe el envío.
    window.almSalidaOnDestinoChange = function () {
        var fw = el('almSalidaFrenteWrap'), fs = el('almSalidaFrente'), ds = el('almSalidaDestino');
        if (!fw || !fs || !ds) return;
        var idDest  = ds.value;
        var frentes = (window.almAlmacenesFrentes || {})[idDest] || [];
        var nombres = window.almFrentesNombres || {};
        // Reconstruir las opciones.
        fs.innerHTML = '<option value="">— elige el frente —</option>';
        frentes.forEach(function (fid) {
            var opt = document.createElement('option');
            opt.value = fid;
            opt.textContent = nombres[fid] || ('Frente #' + fid);
            fs.appendChild(opt);
        });
        if (frentes.length === 0) { fw.style.display = 'none'; fs.value = ''; }
        else if (frentes.length === 1) { fw.style.display = 'block'; fs.value = String(frentes[0]); }
        else { fw.style.display = 'block'; fs.value = ''; }
        showErr('almSalidaError', '');
    };
    window.almSalidaQuitar = function (btn, id) {
        var tr = btn.closest('tr'); if (tr) tr.remove();
        delete almSeleccion[id];
        almSelApplyToVisible();
        almSelRefreshBar();
        var tb = el('almSalidaLineas');
        if (!tb || !tb.children.length) almCerrar('almSalidaModal');
    };
    window.almSalidaConfirmar = function () {
        var esTraspaso = ALM_SAL.tipo === 'TRASPASO';
        var idDest = el('almSalidaDestino') ? el('almSalidaDestino').value : '';
        var idFrenteDest = (esTraspaso && el('almSalidaFrente')) ? el('almSalidaFrente').value : '';
        if (esTraspaso) {
            if (!idDest) { showErr('almSalidaError', 'Elige el almacén destino.'); return; }
            if (idDest === ALM_SAL.idAlmacen) { showErr('almSalidaError', 'El destino debe ser distinto del almacén de origen.'); return; }
            // Si el almacén destino tiene frentes asociados, el operario DEBE indicar a cuál se envía.
            var frentesDest = (window.almAlmacenesFrentes || {})[idDest] || [];
            if (frentesDest.length > 0 && !idFrenteDest) {
                showErr('almSalidaError', 'Elige a qué frente del almacén destino se envía.');
                return;
            }
        }
        var lineas = [], faltan = [];
        (el('almSalidaLineas') ? el('almSalidaLineas').querySelectorAll('tr') : []).forEach(function (tr) {
            var id = tr.getAttribute('data-id');
            var inp = tr.querySelector('.alm-sal-cant');
            var raw = inp ? String(inp.value).replace(',', '.').trim() : '';
            var c = parseFloat(raw);
            var nombre = (almSeleccion[id] && almSeleccion[id].nombre) || ('#' + id);
            if (!isFinite(c) || c <= 0) faltan.push(nombre);
            else lineas.push({ id_producto: parseInt(id, 10), cantidad: c });
        });
        if (!lineas.length) { showErr('almSalidaError', 'Indica una cantidad mayor que 0 en al menos un producto.'); return; }
        if (faltan.length) { showErr('almSalidaError', 'Falta la cantidad (o es 0) en: ' + faltan.slice(0, 4).join(', ') + (faltan.length > 4 ? '…' : '') + '. Corrígelos o quítalos de la lista.'); return; }
        showErr('almSalidaError', '');
        var motivo = el('almSalidaMotivo') ? el('almSalidaMotivo').value.trim() : '';
        var payload = esTraspaso
            ? { tipo: 'TRASPASO', id_almacen: ALM_SAL.idAlmacen, id_almacen_destino: idDest, lineas: lineas }
            : { tipo: 'SALIDA',   id_almacen: ALM_SAL.idAlmacen, lineas: lineas };
        if (motivo) payload.motivo = motivo;
        if (idFrenteDest) payload.id_frente = idFrenteDest;
        pre();
        fetch(ROUTE_LOTE, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            unpre();
            if (res.ok) {
                almCerrar('almSalidaModal');
                if (window.almSelClear) window.almSelClear();
                toast(res.b.message || 'Movimiento registrado.');
                almCargar();
            } else {
                var msg = (res.b && res.b.message) || 'No se pudo registrar el movimiento.';
                if (res.b && res.b.errors) msg = Object.values(res.b.errors).map(function (a) { return a.join(' '); }).join(' ');
                showErr('almSalidaError', msg);
            }
        })
        .catch(function () { unpre(); showErr('almSalidaError', 'Error de red.'); });
    };
    @else
    window.almAbrirSalidaModal = function () { toast('No tienes permiso para registrar movimientos.', 'error'); };
    @endif

    // La tabla abre VACÍA. Si la URL trae un filtro de contenido (search / categoria), se carga al entrar;
    // si no, queda en blanco hasta que el usuario use un filtro.
    (function () {
        var b = el('almFiltroBuscar'), c = el('almFiltroCat');
        if ((b && b.value.trim() !== '') || (c && c.value.trim() !== '')) window.almCargar();
    })();
})();
</script>
@endsection
