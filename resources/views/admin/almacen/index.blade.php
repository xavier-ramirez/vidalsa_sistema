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

    /* Tabla de inventario: estilo igualado a /admin/equipos (.table-row-header + .table-header-custom):
       thead oscuro con texto blanco uppercase, body con texto negro y bordes claros entre columnas. */
    .alm-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 14px; min-width: 760px; color: #000; }
    .alm-table thead tr { background: #1e293b; }
    .alm-table thead th {
        text-align: left; color: #fff; font-size: 13px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 10px 15px; border-right: 1px solid #334155; border-bottom: 2px solid #0f172a;
        position: sticky; top: 0; z-index: 2; white-space: nowrap;
    }
    .alm-table thead th:last-child { border-right: none; }
    .alm-table tbody td { padding: 12px 15px; color: #000; font-size: 14px; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; vertical-align: middle; }
    .alm-table tbody td:last-child { border-right: none; }
    .alm-table tbody tr:hover td { background: #e0f2fe; }
    /* Tooltip-bubble con la UBICACION del producto: aparece al pasar el mouse por cualquier
       parte de la fila (mismo patrón que /admin/equipos usando `.admin-table tr:hover`). */
    .alm-row:hover .tooltip-bubble { opacity: 1 !important; visibility: visible !important; }
    /* Fila con stock bajo: tono rojo claro para indicar urgencia. Al hacer hover hereda
       el azul general como cualquier otra fila (sin sobrescribir con !important — antes
       quedaba naranja en hover y se sentia inconsistente). */
    .alm-row-bajo td { background: #fee2e2; }
    /* Fila seleccionable: clic en la fila la marca (estilo /admin/equipos → .selected-row-maquinaria) */
    /* Las filas son seleccionables con clic pero el cursor se mantiene como flecha (sin mano). */
    .alm-table tbody tr.alm-row-clickable { cursor: default; }
    /* En móvil .selected-row-maquinaria es desktop-only, así que damos un realce propio */
    .alm-table tbody tr.alm-row.selected-row-maquinaria { background: #e1effa !important; }
    /* Anulación local: la regla global `tr.selected-row-maquinaria td { color:#0067b1 }`
       (estilos_globales.css ~línea 1929) deja TODO el texto azul. En esta tabla solo
       queremos el background azul, NO los textos: el código y el nombre tienen su propio
       color especificado por celda. `unset` + `inherit` aseguran que cada celda use su
       color inline original (font-weight + color de td) en vez del azul global. */
    .alm-table tbody tr.alm-row.selected-row-maquinaria td { color: unset !important; }

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
    .dropdown-item-custom:hover { background: #f8fafc !important; }
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
    /* El filtro de almacén (en el título) NO se resalta en azul cuando está activo:
       sobrescribimos el estilo global .filter-active sólo para ese dropdown. */
    #almSelAlmacenDropdown .dropdown-trigger.filter-active {
        background: #f8fafc !important;
        border-color: #cbd5e0 !important;
    }
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
    /* Campos de la Nota de Entrega (modal SALIDA) — input y label estilo VID-FO-GEN-019.
       Los selectores usan ".alm-modal input.alm-nota-input" (especificidad 0,2,1) para
       ganarle al ".alm-modal input" (0,1,1) declarado arriba: si no, sus padding/radius/
       font-size sobrescribirían a los compactos de la Nota. */
    .alm-modal .alm-nota-label { display:block; font-size:10.5px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px; }
    .alm-modal input.alm-nota-input,
    .alm-modal select.alm-nota-input { width:100%; height:38px; border:1px solid #cbd5e0; border-radius:7px; padding:0 10px; font-size:13.5px; background:#fff; outline:none; color:#0f172a; box-sizing:border-box; }
    .alm-modal input.alm-nota-input:focus,
    .alm-modal select.alm-nota-input:focus { border-color: var(--maquinaria-blue, #0067b1); }
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
    .alm-suggest-item .nom { font-size:13.5px; color:#475569; font-weight:600; }
    .alm-suggest-empty { padding:10px 15px; font-size:13px; color:#94a3b8; }
    /* Variante "en línea" para los modales (no flota: empuja el contenido — así no la recorta el overflow del modal) */
    .alm-suggest-inline { margin-top:6px; border:1px solid #e2e8f0; border-radius:12px; background:#fff; box-shadow:0 6px 16px rgba(0,0,0,0.06); max-height:200px; overflow-y:auto; padding:5px; display:none; }
    .alm-suggest-inline.open { display:block; animation:slideDown 0.18s ease-out; }
    .alm-suggest-inline .si-item { display:flex; align-items:center; gap:10px; padding:10px 15px; border-radius:8px; cursor:default; font-size:14px; font-weight:600; color:var(--maquinaria-dark-blue,#1e3a5f); transition:background 0.2s; }
    .alm-suggest-inline .si-item:hover { background:#f0f4f8; }
    .alm-suggest-inline .si-item.si-sel { background:#ebf4ff; color:var(--maquinaria-blue,#0067b1); }
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
            'NOMBRE'      => $a->NOMBRE,
            'TIPO'        => $a->TIPO,
            'UBICACION'   => $a->UBICACION,
            'ALMACENISTA' => $a->ALMACENISTA,
            'frentes'     => $a->relationLoaded('frentes') ? $a->frentes->pluck('ID_FRENTE')->values() : [],
        ];
    });
@endphp

<section class="page-title-card" style="text-align:left;margin:0 0 10px 0;">
    {{-- Layout: título a la izquierda + separador vertical + filtro de almacén con su mini-label "ALMACÉN".
         El bloque del filtro tiene un fondo gris suave para diferenciarse del título sin competir con él. --}}
    <div style="display:flex;justify-content:flex-start;align-items:center;gap:20px;flex-wrap:wrap;">
        <h1 class="page-title" style="margin:0;">
            <span class="page-title-line2" style="color:#000;">Inventario de Almacén</span>
        </h1>
        {{-- Separador vertical (oculto en mobile cuando el filtro se va abajo) --}}
        <span aria-hidden="true" style="display:inline-block;width:1px;height:34px;background:#cbd5e0;flex:0 0 auto;"></span>
        <div style="display:flex;align-items:center;gap:10px;flex:0 1 auto;">
            <span style="font-size:10.5px;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:1px;white-space:nowrap;">Almacén</span>
            <div style="width:240px;min-width:180px;max-width:100%;">
                <div class="custom-dropdown" id="almSelAlmacenDropdown" data-filter-type="id_almacen" data-default-label="Todos los almacenes">
                    <input type="hidden" name="id_almacen" data-filter-value id="almSelAlmacen" value="{{ $reqAlm ?? '' }}">
                    <div class="dropdown-trigger {{ $almacenSel ? 'filter-active' : '' }}" style="padding:0;display:flex;align-items:center;background:#f8fafc;overflow:hidden;border:1px solid #cbd5e0;border-radius:10px;height:40px;transition:border-color .15s,background .15s;">
                        <span style="padding:0 10px;display:flex;align-items:center;color:#0067b1;"><i class="material-icons" style="font-size:18px;transform:none !important;">warehouse</i></span>
                        <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                               placeholder="{{ $almacenSel ? $almacenSel->NOMBRE : 'Todos los almacenes' }}"
                               style="flex:1;border:none;background:transparent;padding:8px 5px;font-size:13.5px;font-weight:600;color:#0f172a;outline:none;min-width:0;"
                               oninput="window.filterDropdownOptions(this)">
                        <i class="material-icons" data-clear-btn style="padding:0 8px;color:#64748b;font-size:18px;display:{{ $almacenSel ? 'block' : 'none' }};cursor:pointer;transform:none !important;"
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
    </div>
</section>

<div class="page-layout-grid">
<div class="admin-card" style="margin:0;min-height:80vh;min-width:0;width:100%;padding:14px;">

    {{-- ── Banner: envíos por recibir (módulo Recepción) ── --}}
    @if(($traspasosPorRecibir ?? 0) > 0)
        <a href="{{ route('almacen.recepcion.index') }}"
           style="display:flex;align-items:center;justify-content:space-between;gap:12px;background:linear-gradient(135deg,#fef3c7 0%,#fde68a 100%);border:1px solid #f59e0b;border-radius:10px;padding:10px 14px;margin-bottom:12px;text-decoration:none;color:#92400e;">
            <span style="display:flex;align-items:center;gap:10px;">
                <i class="material-icons" style="font-size:22px;color:#b45309;">notifications_active</i>
                <span style="font-size:13.5px;font-weight:700;">
                    Tienes <strong style="font-size:15px;">{{ $traspasosPorRecibir }}</strong>
                    {{ $traspasosPorRecibir === 1 ? 'envío pendiente' : 'envíos pendientes' }} por recibir en tus almacenes
                </span>
            </span>
            <span style="display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;">
                Revisar <i class="material-icons" style="font-size:18px;">arrow_forward</i>
            </span>
        </a>
    @endif

    {{-- ── Filtros ── (el filtro de almacén está junto al título, no aquí) --}}
    <div id="almFilters">
        {{-- Buscar (código o descripción) — con sugerencias estilo app. Ancho amplio: es el filtro principal. --}}
        <div class="alm-filter {{ $reqBuscar ? 'active' : '' }}" style="flex:3 1 340px;max-width:560px;">
            <div class="alm-filter-box">
                <span class="alm-ic"><i class="material-icons" style="font-size:18px;">search</i></span>
                <input type="text" id="almFiltroBuscar" autocomplete="off"
                       placeholder="Buscar por código o descripción…" value="{{ $reqBuscar }}"
                       oninput="window.almBuscarInput()" onfocus="window.almBuscarSuggest()"
                       onkeydown="window.almBuscarEnter(event)">
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
                       oninput="window.almCatInput()" onfocus="window.almCatSuggest()"
                       onkeydown="window.almCatEnter(event)">
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
                <div id="almAccionesMenu" style="display:none;position:absolute;top:100%;right:0;width:280px;background:#e2e8f0;border-radius:8px;box-shadow:0 10px 18px -3px rgba(0,0,0,0.18);border:1px solid #e2e8f0;z-index:60;margin-top:6px;overflow:hidden;animation:slideDown 0.18s ease-out;">
                    {{-- Descargar Excel: disponible para cualquier usuario que pueda ver el módulo.
                         Construye la URL de export respetando el filtro de almacén actual.
                         El border-bottom solo se pinta cuando hay items abajo (puedeManage) para evitar
                         un separador huérfano si "Descargar Excel" es el único item del menú. --}}
                    <button type="button" onclick="window.almAccion('export')" class="dropdown-item-custom" style="display:flex;align-items:center;gap:10px;padding:11px 14px;color:#475569;background:transparent;border:none;{{ $puedeManage ? 'border-bottom:1px solid #f1f5f9;' : '' }}width:100%;text-align:left;cursor:pointer;">
                        <div style="background:#dcfce7;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#16a34a;">download</i></div>
                        <span style="font-size:14px;font-weight:500;">Descargar Excel</span>
                    </button>
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
                    <th>Descripción del producto</th>
                    <th style="text-align:center;">UND</th>
                    <th>Categoría</th>
                    <th style="text-align:center;">Stock</th>
                    @if($puedeMover ?? false)
                    {{-- Cant. salida: input habilitado solo cuando la fila está seleccionada.
                         Al confirmar la Nota de Entrega, se toman las cantidades de cada fila
                         seleccionada — sin pantalla intermedia "Productos" en el modal. --}}
                    <th style="text-align:center;width:110px;">Cant. salida</th>
                    @endif
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
        </div>
    </div>

    <div style="background:white;border-radius:12px;padding:15px;border:1px solid #e2e8f0;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);overflow:hidden;">
        <div id="almDistribucionContainer">
            @if($distribucion && $distribucion->isNotEmpty())
                @include('admin.almacen.partials.distribucion_stats', ['distribucion' => $distribucion])
            @endif
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
        {{-- Botón único "Salida". Abre el modal Nota de Entrega; el backend decide si es
             consumo (mismo almacén) o envío a otro proyecto (TRASPASO) según el frente destino. --}}
        <button type="button" onclick="window.almSelAccion()" class="btn-bulk-action" style="background:#dc2626;">
            <i class="material-icons" style="font-size:18px;">north_east</i><span class="desktop-text">Salida</span>
        </button>
    </div>
</div>
@endif

{{-- ════════════════════════ MODALES ════════════════════════ --}}

{{-- Modal antiguo "Registrar entrada / Registrar salida" por producto individual:
     ELIMINADO en 2026-05-13. Las entradas reales ahora se hacen desde
     /admin/almacen/recepcion (modal "Entrada directa") y las salidas desde
     este mismo módulo seleccionando filas + barra flotante (Nota de Entrega).
     Para correcciones puntuales del saldo de un producto se usa el modal
     "Auditoría de Inventario" que sigue abajo. --}}

{{-- Auditoría de Inventario (ajuste del saldo + stock mínimo) --}}
<div id="almAjusteModal" class="alm-modal-overlay">
    <div class="alm-modal">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">fact_check</i> Auditoría de Inventario</h3>
            <i class="material-icons alm-x" onclick="almCerrar('almAjusteModal')">close</i>
        </div>
        <div class="alm-modal-body">
            <div>
                <label>Producto</label>
                <div><strong id="almAjNombre" style="font-size:12.5px;color:#1e293b;"></strong></div>
            </div>
            <div>
                <label>Saldo según conteo físico</label>
                <input type="number" id="almAjNuevoSaldo" min="0" step="any" placeholder="Dejar vacío si solo cambias el mínimo">
                <small style="display:block;font-size:11px;color:#64748b;margin-top:3px;line-height:1.4;">
                    La diferencia se registra en la bitácora como <b>Auditoría</b>.
                </small>
            </div>
            <div>
                <label>Stock mínimo (alerta)</label>
                {{-- min="0.001" + step="any": cualquier valor > 0 vale (no se acepta 0). Vacio = sin alerta. --}}
                <input type="number" id="almAjMinimo" min="0.001" step="any" placeholder="Vacío = sin alerta">
            </div>
            <div><label>Motivo / observaciones de la auditoría</label><input type="text" id="almAjMotivo" maxlength="200" placeholder="Ej: conteo trimestral, merma detectada…"></div>
            <div id="almAjError" style="display:none;color:#dc2626;font-size:13px;font-weight:600;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almAjusteModal')">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" onclick="window.almGuardarAjuste()">Guardar</button>
        </div>
    </div>
</div>

{{-- ═════════════════════════════════════════════════════════════════
     Modal: KARDEX por producto (Movimientos del producto)
     Se abre desde el modal de Detalles (botón "Ver movimientos").
     Reusa AlmacenController::movimientos con ?mini=1 y filtra por
     id_producto + id_almacen actual + opcional tipo / desde / hasta.
═════════════════════════════════════════════════════════════════ --}}
<div id="almKardexProductoModal" class="alm-modal-overlay">
    <div class="alm-modal" style="max-width:680px;">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">history</i> Movimientos del producto</h3>
            <i class="material-icons alm-x" onclick="almCerrar('almKardexProductoModal')">close</i>
        </div>
        <div class="alm-modal-body" style="gap:10px;">
            {{-- Cabecera con info del producto + saldo en el almacén actual --}}
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <span class="alm-pill" id="almKpCodigo">—</span>
                <strong id="almKpNombre" style="font-size:11.5px;color:#1e293b;flex:1;min-width:140px;"></strong>
                <span style="font-size:10.5px;color:#64748b;">Stock actual: <strong id="almKpSaldo" style="color:#0f172a;font-size:11.5px;">0</strong> <span id="almKpUm" style="font-size:10px;color:#64748b;"></span></span>
            </div>

            {{-- Filtros: select Tipo + rango de fechas --}}
            <div style="display:flex;align-items:center;gap:15px;flex-wrap:wrap;background:#fff;padding:4px 0;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:10.5px;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;">Tipo:</span>
                    <select id="almKpTipoSelect" onchange="window.almKpChipSelect(this.value)"
                            style="height:30px;padding:0 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;color:#334155;background:#fff;cursor:pointer;">
                        <option value="">Todos</option>
                        <option value="ENTRADA">Entradas</option>
                        <option value="SALIDA">Salidas</option>
                        <option value="AJUSTE">Auditorías de conteo</option>
                    </select>
                </div>

                <div style="display:flex;align-items:center;gap:6px;flex:1;min-width:280px;">
                    <span style="font-size:10.5px;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;">Fechas:</span>
                    <div style="display:flex;align-items:center;gap:4px;flex-wrap:nowrap;">
                        <input type="date" id="almKpDesde" onchange="window.almKpCargar()"
                               onclick="try{this.showPicker();}catch(e){}"
                               style="height:30px;padding:0 6px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;color:#334155;cursor:pointer;">
                        <span style="color:#94a3b8;font-size:14px;">→</span>
                        <input type="date" id="almKpHasta" onchange="window.almKpCargar()"
                               onclick="try{this.showPicker();}catch(e){}"
                               style="height:30px;padding:0 6px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;color:#334155;cursor:pointer;">
                    </div>
                    <button type="button" onclick="window.almKpLimpiar()" style="background:transparent;border:none;color:#64748b;font-size:11px;font-weight:700;text-decoration:underline;cursor:pointer;margin-left:auto;white-space:nowrap;">Limpiar</button>
                </div>
            </div>

            {{-- Tabla compacta: 5 columnas (sin Producto, ya conocido). El thead
                 queda sticky para que se vea al hacer scroll. --}}
            <div style="overflow:auto;max-height:48vh;border:1px solid #e2e8f0;border-radius:8px;">
                <table style="width:100%;border-collapse:separate;border-spacing:0;">
                    <thead>
                        <tr style="background:#1e293b;color:#fff;position:sticky;top:0;z-index:1;">
                            <th style="width:1%;padding:7px 8px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center;white-space:nowrap;">Fecha</th>
                            <th style="width:1%;padding:7px 8px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center;white-space:nowrap;">Tipo</th>
                            <th style="width:1%;padding:7px 8px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center;white-space:nowrap;">Cantidad</th>
                            <th style="width:1%;padding:7px 8px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center;white-space:nowrap;">Stock</th>
                            <th style="padding:7px 8px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:left;">Destino / Ref</th>
                        </tr>
                    </thead>
                    <tbody id="almKpBody">
                        <tr><td colspan="5" style="text-align:center;padding:30px;color:#94a3b8;font-size:12px;">Cargando…</td></tr>
                    </tbody>
                </table>
            </div>

            <div id="almKpPag" style="font-size:11px;color:#64748b;text-align:center;"></div>
        </div>

    </div>
</div>

<style>
/* La paginación del kardex se aprovecha de la del SSR estándar; aquí se renderiza
   centrada y compacta dentro de #almKpPag. */
#almKpPag .pagination, #almKpPag ul { display:inline-flex; gap:3px; flex-wrap:wrap; justify-content:center; margin:0; padding:0; }
#almKpPag .pagination li, #almKpPag ul li { list-style:none; }
#almKpPag a, #almKpPag span { padding:3px 8px; font-size:11px; border-radius:5px; }
</style>

@if($puedeManage)
{{-- Nuevo almacén --}}
<div id="almAlmacenModal" class="alm-modal-overlay">
    <div class="alm-modal">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">add_business</i> <span id="almNvTitulo">Nuevo almacén</span></h3>
            <i class="material-icons alm-x" onclick="almCerrar('almAlmacenModal')">close</i>
        </div>
        <div class="alm-modal-body">
            <div><label>Nombre</label><input type="text" id="almNvNombre" maxlength="150" placeholder="Ej: ALMACÉN CENTRAL CARACAS"></div>
            <div>
                <label>Tipo</label>
                <div class="custom-dropdown" id="almNvTipoDropdown" data-default-label="Selecciona un tipo">
                    <input type="hidden" id="almNvTipo" value="PROYECTO">
                    <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:#fbfcfd;overflow:hidden;border:1px solid #cbd5e0;border-radius:10px;height:42px;transition:border-color .15s,background .15s;">
                        <input type="text" data-filter-search autocomplete="off" readonly
                               id="almNvTipoDisplay"
                               value="Proyecto (Limitado a frentes específicos)"
                               style="flex:1;border:none;background:transparent;padding:8px 12px;font-size:13.5px;font-weight:normal;color:#0f172a;outline:none;min-width:0;cursor:pointer;"
                               onclick="this.closest('.dropdown-trigger').style.borderColor='var(--maquinaria-blue,#0067b1)'">
                        <i class="material-icons" style="padding:0 8px;color:#64748b;font-size:20px;">expand_more</i>
                    </div>
                    <div class="dropdown-content" style="padding:5px;">
                        <div class="dropdown-item" data-value="GENERAL"
                             onclick="almNvTipoSelect('GENERAL','Global (Todos los frentes)')">
                            Global (Todos los frentes)
                        </div>
                        <div class="dropdown-item selected" data-value="PROYECTO"
                             onclick="almNvTipoSelect('PROYECTO','Proyecto (Limitado a frentes específicos)')">
                            Proyecto (Limitado a frentes específicos)
                        </div>
                    </div>
                </div>
            </div>
            <div><label>Ubicación</label><input type="text" id="almNvUbicacion" maxlength="150" placeholder="Opcional" autocomplete="off"></div>
            {{-- Almacenista: nombre del responsable del almacén. Aparecerá como "Entregado por:"
                 en la Nota de Entrega VID-FO-GEN-019. --}}
            <div>
                <label>Almacenista</label>
                <input type="text" id="almNvAlmacenista" maxlength="200" placeholder="Ej: Juan Pérez (almacenista)" autocomplete="off">
                <div style="font-size:11.5px;color:#94a3b8;margin-top:5px;">Aparece como "Entregado por:" en la Nota de Entrega.</div>
            </div>
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
            <button type="button" class="btn-primary-maquinaria" id="almNvSubmit" onclick="window.almGuardarAlmacen()">Guardar</button>
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
                <div style="flex:1;"><label>Código</label><input type="text" id="almProdCodigo" maxlength="20" inputmode="numeric" pattern="[0-9]*" placeholder="Número (opcional)" autocomplete="off"></div>
                <div style="flex:0.9;position:relative;">
                    <label>Unidad de Medida *</label>
                    <input type="text" id="almProdUm" maxlength="20" placeholder="UND, KG, LTS..." value="UND" autocomplete="off"
                           oninput="window.almProdUmSuggest()" onfocus="window.almProdUmSuggest(true)"
                           style="width:100%;box-sizing:border-box;">
                    <div class="alm-suggest-inline" id="almProdUmSuggestBox" style="position:absolute;top:100%;left:0;right:0;z-index:9999;margin-top:2px;"></div>
                </div>
            </div>
            <div><label>Descripción / producto *</label><input type="text" id="almProdNombre" maxlength="200" autocomplete="off" placeholder="Ej: TORNILLO HEXAGONAL 1/2&quot;"></div>
            <div>
                <label>Categoría</label>
                <div class="alm-cat-field">
                    <input type="text" id="almProdCategoria" autocomplete="off" maxlength="100"
                           placeholder="Elige una de la lista o escribe una nueva…"
                           oninput="window.almProdCatSuggest()" onfocus="window.almProdCatSuggest(true)"
                           onclick="event.stopPropagation(); window.almProdCatSuggest(true);">
                    <button type="button" class="alm-cat-caret" id="almProdCatCaret" tabindex="-1" title="Ver categorías registradas"
                            onclick="window.almProdCatToggle(event)"><i class="material-icons">arrow_drop_down</i></button>
                </div>
                <div class="alm-suggest-inline" id="almProdCatSuggest"></div>
                <div style="font-size:11.5px;color:#94a3b8;margin-top:4px;">Elige de la lista o escribe una nueva categoría.</div>
            </div>
            {{-- Ubicación física en bodega (texto libre). Se muestra como tooltip al pasar el
                 mouse sobre la fila en la tabla — mismo patrón que DETALLE_UBICACION en /admin/equipos. --}}
            <div>
                <label>Ubicación en bodega</label>
                <input type="text" id="almProdUbicacion" maxlength="150" autocomplete="off"
                       placeholder="Ej: Estante A3, Pasillo 2 lado izquierdo…">
                <div style="font-size:11.5px;color:#94a3b8;margin-top:4px;">Aparecerá como tooltip al pasar el mouse sobre la fila.</div>
            </div>
            <div id="almProdError" style="display:none;color:#dc2626;font-size:13px;font-weight:600;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almProductoModal')">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" id="almProdSubmit" onclick="window.almGuardarProducto()">Guardar</button>
        </div>
    </div>
</div>

{{-- Gestionar almacenes (editar / eliminar) --}}
<div id="almAdminAlmacenesModal" class="alm-modal-overlay">
    <div class="alm-modal" style="max-width:440px;">
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

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div style="background:#f8fafc;border-radius:8px;padding:10px;">
                    <div style="font-size:10.5px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.3px;">Categoría</div>
                    <div id="almDetCat" style="font-size:14px;font-weight:700;color:#334155;margin-top:2px;"></div>
                </div>
                <div style="background:#f8fafc;border-radius:8px;padding:10px;">
                    <div style="font-size:10.5px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.3px;">Stock mínimo</div>
                    <div id="almDetMin" style="font-size:14px;font-weight:700;color:#334155;margin-top:2px;"></div>
                </div>
            </div>
            {{-- Aviso de stock bajo en este almacén. Misma paleta que .alm-row-bajo en la
                 tabla (#fee2e2 / #fecaca / #b91c1c) para que el usuario asocie ambos avisos.
                 Layout flex: icono a la izquierda con su propio ancho fijo, texto fluido a la
                 derecha en 2 líneas equilibradas — antes el texto largo + icono inline se veía
                 como un párrafo desordenado. --}}
            <div id="almDetBajoBadge" style="display:none;background:#fee2e2;border:1px solid #fecaca;color:#b91c1c;border-radius:8px;padding:10px 14px;align-items:center;gap:10px;">
                <i class="material-icons" style="font-size:20px;flex:0 0 auto;">warning</i>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;font-weight:800;line-height:1.2;">Stock bajo en este almacén</div>
                    <div style="font-size:11.5px;font-weight:500;line-height:1.35;margin-top:2px;opacity:0.85;">El saldo está en o por debajo del mínimo configurado.</div>
                </div>
            </div>
            <div style="border-top:1px solid #f1f5f9;padding-top:12px;display:flex;flex-direction:column;gap:7px;">
                @if($puedeMover ?? false)
                {{-- Únicamente Auditoría: las entradas reales van por /admin/almacen/recepcion
                     y las salidas por la selección de filas (Nota de Entrega). Aquí solo se
                     corrige el saldo cuando un conteo físico no coincide con el sistema. --}}
                <button type="button" class="alm-det-act" onclick="window.almDetalleAccion('ajuste')"><span class="alm-det-ic" style="background:#dbeafe;color:#0067b1;"><i class="material-icons" style="font-size:18px;">fact_check</i></span> Auditoría de Inventario</button>
                @endif
                <button type="button" class="alm-det-act" onclick="window.almDetalleAccion('kardex')"><span class="alm-det-ic" style="background:#f1f5f9;color:#475569;"><i class="material-icons" style="font-size:18px;">history</i></span> Ver movimientos del producto</button>
                @if($puedeManage ?? false)
                <button type="button" class="alm-det-act" onclick="window.almDetalleAccion('editar')"><span class="alm-det-ic" style="background:#cffafe;color:#0891b2;"><i class="material-icons" style="font-size:18px;">edit</i></span> Editar producto</button>
                <button type="button" class="alm-det-act" onclick="window.almDetalleAccion('eliminar')"><span class="alm-det-ic" style="background:#fee2e2;color:#ef4444;"><i class="material-icons" style="font-size:18px;">delete_outline</i></span> Eliminar / desactivar producto</button>
                @endif
            </div>
        </div>

</div>
</div>

@if($puedeMover)
{{-- ── Salida: un solo formulario unificado. Siempre llena la Nota de Entrega VID-FO-GEN-019
     (proyecto + contrato + fecha + RQ + solicitante + dpto). El backend decide si la salida
     es CONSUMO (mismo almacén del origen) o TRASPASO (envío a otro almacén) según el frente
     elegido en "Proyecto destino" — ambos casos generan Nota de Entrega NE-YYYY-NNNN. ── --}}
<div id="almSalidaModal" class="alm-modal-overlay">
    <div class="alm-modal alm-modal-wide" style="max-width:960px;">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">north_east</i> <span>Registrar salida</span></h3>
            <i class="material-icons alm-x" onclick="almCerrar('almSalidaModal')">close</i>
        </div>
        <div class="alm-modal-body">

            {{-- Cabecera tipo "Nota de Entrega de Materiales" ─────────────────────────────
                 Layout COPIA EXACTA del Excel VID-FO-GEN-019:
                   PROYECTO                                          (full)
                   CONTRATO N°                                       (full)
                   FECHA DE ENTREGA | RQ N° | Solicitante            (3 columnas)
                   DEPARTAMENTO                                      (full)              --}}
            <div id="almSalidaNotaWrap" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:14px;">
                {{-- Encabezado del documento: título centrado + bloque de datos del formato a la derecha. --}}
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;padding-bottom:8px;border-bottom:2px solid #1e293b;">
                    <div style="flex:1;text-align:center;">
                        <div style="font-size:14px;font-weight:800;color:#0f172a;text-transform:uppercase;letter-spacing:1px;line-height:1.2;">Nota de Entrega de Materiales</div>
                    </div>
                    <div style="font-size:10px;color:#64748b;font-family:monospace;text-align:right;line-height:1.5;flex:0 0 auto;margin-left:10px;">
                        <div><strong style="color:#0f172a;">CÓDIGO:</strong> VID-FO-GEN-019</div>
                        <div><strong style="color:#0f172a;">FECHA EMIS:</strong> 01/10/19</div>
                        <div><strong style="color:#0f172a;">REV:</strong> 1 — 06/10/23</div>
                    </div>
                </div>

                {{-- PROYECTO (ancho) | CONTRATO N° (estrecho) — misma fila.
                     Contrato N° con sugerencias derivadas del proyecto: si el frente elegido
                     tiene 1 solo contrato registrado se autocompleta; si tiene varios, se
                     muestran como botones bajo el input. --}}
                <div style="display:grid;grid-template-columns:2fr 1fr;gap:10px;margin-bottom:10px;align-items:start;">
                    <div>
                        <label class="alm-nota-label">Proyecto *</label>
                        {{-- Custom-dropdown estándar de la app: hidden #almSalidaProyecto guarda el ID
                             (lo que lee el JS de envío); el trigger tiene un input data-filter-search
                             que filtra los items mientras el usuario escribe (autocomplete nativo del
                             componente). Cuando se elige una opción, dispatchea el evento
                             `dropdown-selection` que el listener de almSalida usa para refrescar las
                             sugerencias de Contrato N°. --}}
                        <div class="custom-dropdown" id="almSalidaProyectoDropdown" data-default-label="— elige el proyecto / frente —">
                            <input type="hidden" id="almSalidaProyecto" data-filter-value value="">
                            <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:#fff;overflow:hidden;border:1px solid #cbd5e0;border-radius:7px;height:38px;">
                                <input type="text" data-filter-search autocomplete="off"
                                       placeholder="— elige el proyecto / frente —"
                                       style="flex:1;border:none;background:transparent;padding:0 10px;font-size:13.5px;font-weight:600;color:#0f172a;outline:none;min-width:0;"
                                       oninput="window.filterDropdownOptions(this)">
                                <i class="material-icons" data-clear-btn style="padding:0 8px;color:#64748b;font-size:18px;display:none;cursor:pointer;"
                                   onclick="event.stopPropagation(); clearDropdownFilter('almSalidaProyectoDropdown');">close</i>
                                <i class="material-icons" style="padding:0 8px;color:#94a3b8;font-size:20px;">expand_more</i>
                            </div>
                            <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                                <div class="dropdown-item-list" style="max-height:240px;overflow-y:auto;">
                                    @foreach(($frentesLista ?? collect()) as $f)
                                        <div class="dropdown-item" data-value="{{ $f->ID_FRENTE }}"
                                             onclick="selectOption('almSalidaProyectoDropdown','{{ $f->ID_FRENTE }}','{{ addslashes($f->NOMBRE_FRENTE) }}');">
                                            {{ $f->NOMBRE_FRENTE }}
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                    <div style="position:relative;">
                        <label class="alm-nota-label">Contrato N°</label>
                        <input type="text" id="almSalidaContrato" class="alm-nota-input" maxlength="100" placeholder="Ej: CTR-2026-0042">
                        <div id="almSalidaContratoSug" style="display:none;margin-top:5px;flex-wrap:wrap;gap:5px;"></div>
                    </div>
                </div>

                {{-- FECHA DE ENTREGA | RQ N° | Solicitante (3 columnas en una sola fila — como en el Excel) --}}
                <div style="display:grid;grid-template-columns:1fr 1fr 1.4fr;gap:10px;margin-bottom:10px;">
                    <div>
                        <label class="alm-nota-label">Fecha de entrega</label>
                        {{-- Wrapper clickable: cualquier click en el campo abre el calendario
                             (en navegadores que soportan showPicker). El ícono va con pointer-events:none
                             para que el click pase al wrapper y no se "coma" el evento. --}}
                        <div id="almSalidaFechaBox" style="display:flex;align-items:center;background:#fff;border:1px solid #cbd5e0;border-radius:7px;height:38px;overflow:hidden;cursor:pointer;"
                             onclick="var i=document.getElementById('almSalidaFecha'); if(i){ i.focus(); if(i.showPicker){ try{ i.showPicker(); }catch(e){} } }">
                            <i class="material-icons" style="padding:0 8px;color:#94a3b8;font-size:18px;pointer-events:none;">event</i>
                            <input type="date" id="almSalidaFecha" class="alm-nota-input" style="flex:1;width:auto;min-width:0;border:none;background:transparent;height:36px;padding:0 8px 0 0;border-radius:0;">
                        </div>
                    </div>
                    <div>
                        <label class="alm-nota-label">RQ N°</label>
                        <input type="text" id="almSalidaRq" class="alm-nota-input" maxlength="100" placeholder="Ej: RQ-001">
                    </div>
                    <div>
                        <label class="alm-nota-label">Solicitante</label>
                        <input type="text" id="almSalidaSolicitante" class="alm-nota-input" maxlength="200" placeholder="Nombre y apellido">
                    </div>
                </div>

                {{-- DEPARTAMENTO (full width) --}}
                <div style="margin-bottom:10px;">
                    <label class="alm-nota-label">Departamento</label>
                    <input type="text" id="almSalidaDepartamento" class="alm-nota-input" maxlength="150" placeholder="Ej: Mantenimiento">
                </div>

                {{-- OBSERVACIONES (full width) — campo libre de la Nota de Entrega
                     (mapea a MOTIVO en BD). Se envía siempre en el flujo unificado: tanto
                     en SALIDA pura (consumo) como en SALIDA vía traspaso a otro proyecto. --}}
                <div>
                    <label class="alm-nota-label">Observaciones</label>
                    <input type="text" id="almSalidaMotivo" class="alm-nota-input" maxlength="200" placeholder="Ej: entrega parcial, urgente, etc.">
                </div>
            </div>

            {{-- La lista de productos a entregar VIVE en la tabla principal: cada fila
                 seleccionada tiene su propio input "Cant. salida". Por eso este modal
                 ya no muestra una tabla de productos — solo recoge los datos de la
                 Nota de Entrega y los cruza con las cantidades de almSeleccion. --}}
            <div id="almSalidaResumen" style="margin-top:4px;font-size:12px;color:#64748b;background:#f8fafc;border:1px dashed #cbd5e0;border-radius:8px;padding:8px 12px;">
                Se incluirán <strong id="almSalidaResumenN" style="color:#0f172a;">0</strong> producto(s) seleccionado(s) en la tabla. Las cantidades se toman de la columna <em>Cant. salida</em>.
            </div>

            <div id="almSalidaError" style="display:none;color:#dc2626;font-size:13px;font-weight:600;margin-top:6px;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almSalidaModal')">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" onclick="window.almSalidaConfirmar()">Registrar salida</button>
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

    var ROUTE_INDEX = @json(route('almacen.index'));
    // ROUTE_LOTE cubre TODOS los movimientos: ENTRADA, SALIDA (consumo) y SALIDA hacia otro
    // proyecto (el backend crea internamente el Traspaso). El frontend solo conoce este endpoint.
    var ROUTE_LOTE  = @json(route('almacen.movimientos.lote'));
    var ROUTE_PROD  = @json(route('almacen.productos.store'));
    // Catálogo de productos (CODIGO/NOMBRE/UM) — lista global, alimenta los selects de los modales
    // (Nuevo/Editar producto, modal de salida con productos seleccionados, etc.).
    window.almProductosLista = @json($productosLista ?? collect());
    // Mapa { ID_ALMACEN: [ID_PRODUCTO, ...] } — productos que SÍ tienen fila en `almacen_stock`
    // para cada almacén. Lo usan las sugerencias del filtro "Buscar" para acotar a productos
    // que realmente aparecerán en la tabla (la tabla usa INNER JOIN con almacen_stock).
    window.almProductosEnAlmacen = @json($productosEnAlmacen ?? collect());
    // Categorías ya registradas — alimentan la lista del campo "Categoría" del modal de producto.
    window.almCategoriasLista = @json(($categorias ?? collect())->filter()->values());
    // Unidades de medida distintas ya registradas — alimentan el autocomplete del campo "UM" del modal.
    window.almUnidadesMedida = @json($unidadesMedida ?? []);
    // Mapa { ID_FRENTE: ["CTR-2026-0042", ...] } para sugerir contratos en el modal "Registrar salida".
    // Los contratos se gestionan en /admin/frentes (columna CONTRATOS JSON de frentes_trabajo).
    window.almFrenteContratos = @json(($frentesLista ?? collect())->mapWithKeys(fn ($f) => [$f->ID_FRENTE => array_values(array_filter((array) ($f->CONTRATOS ?? [])))]));
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
                    var num = function (id, v) { var e = el(id); if (e) e.textContent = (v == null ? '—' : v); };
                    num('almStatsTotal',    data.stats.total);
                    num('almStatsConSaldo', data.stats.con_saldo);
                    num('almStatsBajo',     data.stats.stock_bajo);
                }
                if (data.distribucionHtml !== undefined) { var dc = el('almDistribucionContainer'); if (dc) dc.innerHTML = data.distribucionHtml; }
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

    // Helpers compartidos por todos los autocompletes del modulo (almBuscar/almCat/almProdCat/almProdUm).
    // `almSuggestFilter` aplica el patron "lista filtrada por term normalizado o todo si forceAll/term vacio".
    // `almSuggestApply` setea el HTML del box (con fallback a empty state) y lo abre.
    function almSuggestFilter(lista, term, getKey, forceAll) {
        if (forceAll || term === '') return (lista || []).slice(0);
        return (lista || []).filter(function (it) { return almNorm(getKey(it)).indexOf(term) > -1; });
    }
    function almSuggestApply(box, html, emptyHtml) {
        if (!box) return;
        box.innerHTML = html || (emptyHtml || '<div class="alm-suggest-empty">Sin coincidencias.</div>');
        box.classList.add('open');
    }
    // Construye los "tokens de búsqueda" para igualar la lógica del backend:
    // tokeniza por espacios, normaliza (lower + sin acentos) y para cada token >3
    // letras que termine en 'S' añade su variante singular. Devuelve { tokens, hasMatch(text) }.
    function almBuildSearchMatcher(rawTerm) {
        var tokens = (rawTerm || '').split(/\s+/).filter(Boolean).map(function (t) {
            var n = almNorm(t);
            var variantes = [n];
            if (n.length > 3 && n.charAt(n.length - 1) === 's') variantes.push(n.slice(0, -1));
            return variantes;
        });
        return {
            isEmpty: tokens.length === 0,
            // hasMatch retorna true si CADA token (en alguna de sus variantes) aparece
            // como substring del texto normalizado pasado — AND entre tokens, OR entre variantes.
            hasMatch: function (text) {
                var n = almNorm(text || '');
                for (var i = 0; i < tokens.length; i++) {
                    var found = false;
                    for (var j = 0; j < tokens[i].length; j++) {
                        if (n.indexOf(tokens[i][j]) > -1) { found = true; break; }
                    }
                    if (!found) return false;
                }
                return true;
            }
        };
    }

    window.almBuscarSuggest = function () {
        almCatSuggestHide();
        var inp = el('almFiltroBuscar'), box = el('almFiltroBuscarSuggest');
        if (!inp || !box) return;
        var rawTerm = inp.value.trim();
        var matcher = almBuildSearchMatcher(rawTerm);
        var lista = window.almProductosLista || [];

        // Cuando el usuario abre el autocomplete sin texto, ofrecemos arriba un acceso directo
        // para cargar TODO el inventario (alias a almVerTodo). Útil si la tabla está vacía
        // por el estado inicial "Usa los filtros…" y el usuario quiere ver todo de una vez.
        // Usa la MISMA estructura que el resto de items (.alm-suggest-item > .nom) para no
        // romper la consistencia visual — solo se distingue por el icono inline pequeño.
        var verTodoLink = matcher.isEmpty
            ? '<div class="alm-suggest-item" data-action="ver-todo">'
            +     '<span class="nom">Ver todo el stock</span>'
            + '</div>'
            : '';

        // IDs de productos que SI estan en el almacen seleccionado (set para lookup O(1)).
        // Si no hay almacen seleccionado o no tenemos info, se omite el filtro (mostrar todo).
        var idAlm = el('almSelAlmacen') ? el('almSelAlmacen').value : '';
        var idsArr = idAlm ? ((window.almProductosEnAlmacen || {})[idAlm] || null) : null;
        var idsSet = null;
        if (idsArr && idsArr.length !== undefined) {
            idsSet = {};
            for (var k = 0; k < idsArr.length; k++) idsSet[idsArr[k]] = true;
        }
        function enEsteAlmacen(p) { return idsSet === null || !!idsSet[p.ID_PRODUCTO]; }

        // Recorremos la lista una vez y clasificamos: matches en este almacén vs solo catálogo.
        var matches = [];        // coinciden con el término Y estan en este almacén → se muestran
        var soloCatalogo = 0;    // coinciden con el término PERO no estan en este almacén → contador
        if (matcher.isEmpty) {
            for (var i = 0; i < lista.length && matches.length < 12; i++) {
                if (enEsteAlmacen(lista[i])) matches.push(lista[i]);
            }
        } else {
            for (var j = 0; j < lista.length; j++) {
                var p = lista[j];
                // Cada token (con su variante singular si aplica) debe aparecer en CODIGO o NOMBRE
                // — concatenamos para que un token pueda matchear en cualquiera de los dos campos.
                if (!matcher.hasMatch((p.CODIGO || '') + ' ' + (p.NOMBRE || ''))) continue;
                if (enEsteAlmacen(p)) {
                    if (matches.length < 12) matches.push(p);
                } else {
                    soloCatalogo++;
                }
            }
        }

        if (!matches.length) {
            // Distinguimos los dos casos para que el usuario entienda por qué la tabla queda vacía:
            //  • "Sin coincidencias"           → el término no matchea ningún producto del sistema.
            //  • "Existen pero sin saldo aquí" → el catálogo tiene matches pero no en este almacén.
            box.innerHTML = verTodoLink + (soloCatalogo > 0
                ? '<div class="alm-suggest-empty">Existe en el catálogo, pero <strong>no tiene movimientos en este almacén</strong>.<br><span style="font-size:11.5px;color:#94a3b8;">Registra una entrada (Recepción) o un traspaso para que aparezca aquí.</span></div>'
                : '<div class="alm-suggest-empty">Sin coincidencias.</div>');
        } else {
            // Mostrar SOLO el NOMBRE; data-pick guarda el NOMBRE para que escribir encima del
            // texto pegado siga produciendo coincidencias via LIKE %term% del backend.
            var html = verTodoLink + matches.map(function (p) {
                var nom = (p.NOMBRE || '').replace(/[<>&"]/g, '');
                var cod = (p.CODIGO || '').replace(/[<>&"]/g, '');
                return '<div class="alm-suggest-item" data-pick="' + nom + '" title="' + cod + '">'
                     + '<span class="nom">' + nom + '</span></div>';
            }).join('');
            // Pie informativo: si hay matches del catálogo no listados (porque no estan en este
            // almacén), avisamos para que el usuario sepa que existen más opciones globalmente.
            if (soloCatalogo > 0) {
                html += '<div class="alm-suggest-empty" style="border-top:1px solid #f1f5f9;margin-top:4px;padding-top:8px;">'
                      + '<span style="font-size:11.5px;color:#94a3b8;">+ ' + soloCatalogo + ' producto(s) coinciden pero no están en este almacén.</span></div>';
            }
            box.innerHTML = html;
        }
        box.classList.add('open');
    };
    // Escribir SOLO refresca la lista de sugerencias — NO dispara la búsqueda en la tabla.
    // La tabla se filtra cuando el usuario (a) elige una sugerencia [almBuscarPick],
    // (b) pulsa Enter [almBuscarEnter], o (c) limpia el campo con la X [almBuscarLimpiar].
    // El cambio respecto a la versión anterior es que almBuscarPick pega el NOMBRE del
    // producto en el input (no el código PRD-XXXX), así si el usuario escribe encima del
    // texto pegado, las sugerencias siguen apareciendo con coincidencias relevantes.
    window.almBuscarInput = function () {
        window.almBuscarSuggest();
    };
    window.almBuscarEnter = function (ev) {
        if (ev && ev.key !== 'Enter') return;
        if (ev) ev.preventDefault();
        almSuggestHide();
        almCargar();
    };
    window.almBuscarPick = function (texto) {
        var inp = el('almFiltroBuscar'); if (inp) inp.value = texto;
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
        var lista = (window.almCategoriasLista || []);
        var matches = almSuggestFilter(lista, almNorm(inp.value.trim()), function (c) { return c; }, false);
        var html = matches.map(function (c) {
            var safe = String(c).replace(/[<>&"]/g, '');
            return '<div class="alm-suggest-item" data-pick="' + safe + '"><span class="nom">' + safe + '</span></div>';
        }).join('');
        var empty = '<div class="alm-suggest-empty">' + (lista.length ? 'Sin categorías que coincidan.' : 'No hay categorías registradas.') + '</div>';
        almSuggestApply(box, html, empty);
    };
    // Escribir SOLO refresca la lista de sugerencias — NO dispara la búsqueda en la tabla.
    // La tabla se filtra cuando el usuario (a) elige una sugerencia [almCatPick],
    // (b) pulsa Enter [almCatEnter], o (c) limpia el campo con la X [almCatLimpiar].
    window.almCatInput = function () { window.almCatSuggest(); };
    window.almCatEnter = function (ev) {
        if (ev && ev.key !== 'Enter') return;
        if (ev) ev.preventDefault();
        almCatSuggestHide();
        almCargar();
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
        var id = e.detail && e.detail.dropdownId;
        if (id === 'almSelAlmacenDropdown') almCargar();
        // Modal "Registrar salida": al elegir proyecto destino, refrescar sugerencias
        // de "Contrato N°" (autollena si hay 1, muestra chips si hay varios).
        if (id === 'almSalidaProyectoDropdown' && typeof window.almSalidaOnProyectoChange === 'function') {
            window.almSalidaOnProyectoChange();
        }
    });

    // Click en una sugerencia (Buscar / Categoría) / click fuera / Escape — el filtro Almacén ya no usa este sistema.
    document.addEventListener('click', function (e) {
        var item = e.target.closest('#almFiltroBuscarSuggest .alm-suggest-item');
        if (item) {
            e.preventDefault();
            // Item especial "Ver todo el inventario" → reusa almVerTodo (limpia filtros + recarga).
            if (item.getAttribute('data-action') === 'ver-todo') {
                almSuggestHide();
                if (window.almVerTodo) window.almVerTodo();
                return;
            }
            window.almBuscarPick(item.getAttribute('data-pick') || '');
            return;
        }
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
    //  Las cantidades a sacar/enviar viven AHORA en la propia fila de la tabla:
    //  cada fila tiene un <input.alm-row-cant> que se habilita al seleccionarla. El
    //  valor se guarda en almSeleccion[id].cantidad y sobrevive a recargas del tbody
    //  (paginación/filtros) gracias a almSelApplyToVisible(). El modal #almSalidaModal
    //  ya NO muestra una tabla de productos — solo los campos de la Nota de Entrega.
    // ════════════════════════════════════════════════════════════════════════
    var almSeleccion = {}; // { id_producto: { codigo, nombre, um, saldo, cantidad } }
    function almSelCount() { return Object.keys(almSeleccion).length; }
    function almSelRefreshBar() {
        var bar = el('almBulkBar'); if (!bar) return;
        var n = almSelCount();
        bar.classList.toggle('active', n > 0);
        var c = el('almBulkCount'); if (c) c.textContent = n;
    }
    function almSelMarkRow(tr, on) {
        if (!tr) return;
        tr.classList.toggle('selected-row-maquinaria', !!on);
        // Habilitar / deshabilitar el input de cantidad de la propia fila, y restaurar
        // el valor guardado en memoria (almSeleccion[id].cantidad) si lo hay.
        var inp = tr.querySelector('.alm-row-cant');
        if (!inp) return;
        if (on) {
            var id = tr.getAttribute('data-id-producto');
            var s = id ? almSeleccion[id] : null;
            inp.disabled = false;
            inp.style.background = '#fff';
            inp.style.color = '#0f172a';
            inp.value = (s && s.cantidad != null && s.cantidad !== '') ? s.cantidad : '';
        } else {
            inp.disabled = true;
            inp.style.background = '#f1f5f9';
            inp.style.color = '#94a3b8';
            inp.value = '';
        }
    }
    // Re-pinta el resaltado azul + estado del input cantidad tras cada recarga AJAX del tbody.
    function almSelApplyToVisible() {
        document.querySelectorAll('#almTableBody tr.alm-row').forEach(function (tr) {
            almSelMarkRow(tr, !!almSeleccion[tr.getAttribute('data-id-producto')]);
        });
    }
    window.almSelClear = function (e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        almSeleccion = {};
        document.querySelectorAll('#almTableBody tr.alm-row').forEach(function (tr) { almSelMarkRow(tr, false); });
        almSelRefreshBar();
    };
    // Handler del input de cantidad en cada fila — guarda en almSeleccion (sobrevive a
    // recargas del tbody). Validación final (> 0, ≤ stock) se hace al confirmar la Nota.
    window.almRowCantInput = function (inp) {
        var tr = inp.closest('tr.alm-row'); if (!tr) return;
        var id = tr.getAttribute('data-id-producto'); if (!id) return;
        var s  = almSeleccion[id]; if (!s) return;
        s.cantidad = String(inp.value).replace(',', '.').trim();
    };
    // Clic en una fila de la tabla → toggle de selección. Ignora clics sobre botones / inputs
    // (incluido el input .alm-row-cant que va dentro de un td[data-no-toggle]).
    document.addEventListener('click', function (e) {
        var tr = e.target.closest('#almTableBody tr.alm-row');
        if (!tr) return;
        if (e.target.closest('[data-no-toggle]')) return;
        if (e.target.closest('button') || e.target.closest('a') || e.target.closest('input') || e.target.closest('select') || e.target.closest('.custom-dropdown')) return;
        var id = tr.getAttribute('data-id-producto'); if (!id) return;
        if (almSeleccion[id]) { delete almSeleccion[id]; almSelMarkRow(tr, false); }
        else {
            almSeleccion[id] = {
                codigo: tr.getAttribute('data-codigo') || '',
                nombre: tr.getAttribute('data-nombre') || '',
                um:     tr.getAttribute('data-um') || '',
                saldo:  parseFloat(tr.getAttribute('data-saldo') || '0') || 0,
                cantidad: '',
            };
            almSelMarkRow(tr, true);
            // Foco automático en el input de cantidad recién habilitado.
            setTimeout(function () { var inp = tr.querySelector('.alm-row-cant'); if (inp) inp.focus(); }, 30);
        }
        almSelRefreshBar();
    });
    function almSelAlmacenActual() { var s = el('almSelAlmacen'); return s ? s.value : ''; }
    // Único botón de la barra flotante: abre el modal Nota de Entrega.
    // El backend decide si es SALIDA (consumo en el mismo almacén) o TRASPASO (envío
    // a otro almacén) según el frente destino elegido en el formulario.
    window.almSelAccion = function () {
        if (!almSelCount()) { toast('Selecciona al menos un producto (clic en su fila).', 'error'); return; }
        if (typeof window.almAbrirSalidaModal !== 'function') { toast('No tienes permiso para registrar movimientos.', 'error'); return; }
        var idAlm = almSelAlmacenActual();
        if (!idAlm) { toast('No hay un almacén seleccionado.', 'error'); return; }
        // Bloquear apertura del modal si alguna fila seleccionada no tiene cantidad válida.
        // El usuario debe llenar la columna "Cant. salida" en la tabla antes de pasar al
        // formulario de Nota de Entrega. Resaltamos las filas faltantes para guiarlo.
        var faltan = [];
        Object.keys(almSeleccion).forEach(function (id) {
            var s = almSeleccion[id] || {};
            var c = parseFloat(String(s.cantidad == null ? '' : s.cantidad).replace(',', '.').trim());
            if (!isFinite(c) || c <= 0) faltan.push({ id: id, nombre: s.nombre || ('#' + id) });
        });
        if (faltan.length) {
            // Foco al primer input faltante visible.
            var firstId = faltan[0].id;
            var firstTr = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + firstId + '"]');
            var firstInp = firstTr && firstTr.querySelector('.alm-row-cant');
            if (firstInp) { firstInp.focus(); }
            var nombres = faltan.slice(0, 3).map(function (f) { return f.nombre; }).join(', ');
            toast('Indica la cantidad de salida (> 0) en: ' + nombres + (faltan.length > 3 ? '…' : '') + '.', 'error');
            return;
        }
        window.almAbrirSalidaModal(idAlm);
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
        var term = almNorm(inp.value.trim());
        var matches = almSuggestFilter(window.almCategoriasLista, term, function (c) { return c; }, !!forceAll);
        // Solo categorias existentes; el usuario puede escribir una nueva y se guardara al crear el producto.
        var html = matches.map(function (c) {
            var sel = almNorm(c) === term ? ' si-sel' : '';
            return '<div class="si-item' + sel + '" data-cat="' + escHtml(c) + '">' + escHtml(c) + '</div>';
        }).join('');
        almSuggestApply(box, html, '<div class="alm-suggest-empty">Sin coincidencias. Escribe para crear una nueva categoría.</div>');
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

    // ── Campo "Unidad de Medida" del modal de producto: autocomplete con las UMs ya registradas ──
    // Permite seleccionar una UM existente o escribir una nueva libremente.
    function almProdUmHide() {
        var b = el('almProdUmSuggestBox'); if (b) b.classList.remove('open');
    }
    window.almProdUmSuggest = function (forceAll) {
        var inp = el('almProdUm'), box = el('almProdUmSuggestBox');
        if (!inp || !box) return;
        var term = almNorm(inp.value.trim());
        var lista = (window.almUnidadesMedida || []);
        var matches = almSuggestFilter(lista, term, function (u) { return u; }, !!forceAll);
        // Solo lista las UMs existentes; si el usuario escribe una nueva, queda en el
        // input tal cual y se guarda al crear el producto — no se ofrece como sugerencia.
        var html = matches.map(function (u) {
            var sel = almNorm(u) === term ? ' si-sel' : '';
            return '<div class="si-item' + sel + '" data-um="' + escHtml(u) + '">' + escHtml(u) + '</div>';
        }).join('');
        almSuggestApply(box, html, '<div class="alm-suggest-empty">Sin coincidencias.</div>');
    };
    // Delegación de clic para las opciones del autocomplete de UM
    document.addEventListener('click', function (e) {
        var item = e.target.closest('#almProdUmSuggestBox .si-item');
        if (item) {
            e.preventDefault();
            var inp = el('almProdUm');
            if (inp) inp.value = item.getAttribute('data-um') || '';
            almProdUmHide();
            return;
        }
        if (!e.target.closest('#almProdUm') && !e.target.closest('#almProdUmSuggestBox')) almProdUmHide();
    });
    var _almProdUmInp = el('almProdUm');
    if (_almProdUmInp) _almProdUmInp.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') almProdUmHide();
    });

    // ── modales ──
    function open(id)  { var m = el(id); if (m) m.classList.add('open'); }
    window.almCerrar = function (id) { var m = el(id); if (m) m.classList.remove('open'); };
    // El cierre por clic en el backdrop fue removido por preferencia del usuario:
    // cada modal tiene su propio botón "✕" / "Cancelar". Escape sí lo sigue cerrando.
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') document.querySelectorAll('.alm-modal-overlay.open').forEach(function (m) { m.classList.remove('open'); }); });

    // ── Botón "Acciones" (dropdown estilo /admin/equipos) ──
    window.almToggleAcciones = function (e) {
        if (e) e.stopPropagation();
        var m = el('almAccionesMenu'); if (!m) return;
        
        // Cerrar los demás filtros estándar si están abiertos
        if (typeof window.closeAllDropdowns === 'function') window.closeAllDropdowns();
        document.querySelectorAll('.custom-dropdown.active').forEach(d => d.classList.remove('active'));
        document.querySelectorAll('.dropdown-content').forEach(d => d.style.display = '');

        m.style.display = (m.style.display === 'block') ? 'none' : 'block';
    };
    document.addEventListener('click', function (e) {
        var m = el('almAccionesMenu');
        if (m && m.style.display === 'block') {
            // Cerrar si hace clic fuera, o si hace clic en cualquier otro botón de filtro (dropdown-trigger)
            if (!e.target.closest('#almAccionesMenu') && !e.target.closest('#almBtnAcciones') || e.target.closest('.dropdown-trigger')) {
                m.style.display = 'none';
            }
        }
    });
    window.almAccion = function (which) {
        var m = el('almAccionesMenu'); if (m) m.style.display = 'none';
        switch (which) {
            case 'admin':    if (window.almAbrirAdminAlmacenes) window.almAbrirAdminAlmacenes(); break;
            case 'almacen':  if (window.almAbrirAlmacen)        window.almAbrirAlmacen();        break;
            case 'producto': if (window.almAbrirProducto)       window.almAbrirProducto();       break;
            case 'export':
                // Construye la URL del export respetando el filtro de almacén activo.
                // Si no hay almacén seleccionado se descarga el inventario global (una col por almacén visible).
                var u = new URL(@json(route('almacen.export')), window.location.origin);
                var idAlm = el('almSelAlmacen') ? el('almSelAlmacen').value : '';
                if (idAlm) u.searchParams.set('id_almacen', idAlm);
                window.location.href = u.toString();
                break;
        }
    };

    function hoy() { var d = new Date(); var p = function (n) { return (n < 10 ? '0' : '') + n; }; return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()); }
    function showErr(id, msg) { var e = el(id); if (e) { e.textContent = msg; e.style.display = msg ? 'block' : 'none'; } }
    // Resalta un campo input con borde rojo cuando hay error, lo quita cuando msg está vacío.
    function almProdFieldErr(fieldId, hasError) {
        var f = el(fieldId);
        if (!f) return;
        if (hasError) {
            f.style.borderColor = '#dc2626';
            f.style.boxShadow  = '0 0 0 2px rgba(220,38,38,0.18)';
            f.style.background = '#fff5f5';
        } else {
            f.style.borderColor = '';
            f.style.boxShadow  = '';
            f.style.background = '';
        }
    }

    // Funciones almAbrirMovimiento / almGuardarMovimiento ELIMINADAS en 2026-05-13
    // junto con el modal #almMovModal. El flujo de entrada/salida ya no se hace
    // por producto individual: ENTRADA → /admin/almacen/recepcion · SALIDA →
    // selección de filas + barra flotante (Nota de Entrega). Para AJUSTE puntual
    // se usa el modal #almAjusteModal (Auditoría de Inventario).

    // ── Página de movimientos (módulo aparte: /admin/almacen/movimientos) ──
    var ROUTE_MOVIMIENTOS = @json(route('almacen.movimientos'));

    // ── Modal "Detalles del producto" (lo abre el ojo de cada fila; agrupa todas las acciones) ──
    window.almAbrirDetalle = function (id, cod, nom, um, cat, saldo, minimo, ubicacion) {
        var m = el('almDetalleModal'); if (!m) return;
        var hasMin = (minimo !== null && minimo !== undefined && minimo !== '');
        m.dataset.id = id;
        m.dataset.cod = cod || ''; m.dataset.nom = nom || ''; m.dataset.um = um || ''; m.dataset.cat = cat || '';
        m.dataset.ubicacion = ubicacion || '';
        m.dataset.saldo = (saldo == null ? '0' : String(saldo));
        m.dataset.minimo = hasMin ? String(minimo) : '';
        el('almDetCat').textContent = (cat && String(cat).trim()) ? cat : '—';
        el('almDetMin').textContent = hasMin ? formatNum(minimo) : 'Sin definir';
        var bajo = hasMin && parseFloat(saldo || 0) <= parseFloat(minimo);
        // 'flex' (no '' ni 'block') porque el badge se layoutea con icono a la izquierda
        // y texto a la derecha (display:flex en el CSS inline del div). Ver markup arriba.
        el('almDetBajoBadge').style.display = bajo ? 'flex' : 'none';
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
            // 'entrada'/'salida' removidos (esos flujos ya no van por producto individual).
            case 'ajuste':   if (window.almAbrirAjuste)         window.almAbrirAjuste(id, d.cod, d.nom, d.um, saldo, minimo); break;
            // 'kardex' antes navegaba a /admin/almacen/movimientos; ahora abre un
            // modal local con los movimientos solo de este producto + filtros mínimos.
            case 'kardex':   if (window.almAbrirKardexProducto) window.almAbrirKardexProducto(id, d.cod, d.nom, d.um, saldo); break;
            case 'editar':   if (window.almEditarProducto)      window.almEditarProducto(id, d.cod, d.nom, d.um, d.cat, d.ubicacion); break;
            case 'eliminar': if (window.almEliminarProducto)    window.almEliminarProducto(id); break;
        }
    };

    // ── Modal "Movimientos del producto" (kardex local de UN producto) ──
    // Reusa AlmacenController::movimientos con ?mini=1 (partial de 5 columnas)
    // y filtra por id_producto + id_almacen actual. Estado en window.__almKp.
    window.__almKp = { idProducto: null, tipo: '', desde: '', hasta: '' };

    window.almAbrirKardexProducto = function (idProducto, codigo, nombre, um, saldo) {
        window.__almKp = { idProducto: idProducto, tipo: '', desde: '', hasta: '' };
        el('almKpCodigo').textContent = codigo ? ('Cód: ' + codigo) : 'Sin código';
        el('almKpNombre').textContent = nombre || '';
        el('almKpSaldo').textContent  = formatNum(saldo);
        el('almKpUm').textContent     = um || '';
        // Reset visual de filtros.
        if (el('almKpDesde'))      el('almKpDesde').value = '';
        if (el('almKpHasta'))      el('almKpHasta').value = '';
        if (el('almKpTipoSelect')) el('almKpTipoSelect').value = '';
        open('almKardexProductoModal');
        window.almKpCargar();
    };

    // almKpChipSelect: gestiona el filtro de tipo desde el <select> del modal kardex.
    window.almKpChipSelect = function (tipo) {
        window.__almKp.tipo = tipo || '';
        window.almKpCargar();
    };

    window.almKpLimpiar = function () {
        window.__almKp.tipo = ''; window.__almKp.desde = ''; window.__almKp.hasta = '';
        if (el('almKpDesde'))      el('almKpDesde').value = '';
        if (el('almKpHasta'))      el('almKpHasta').value = '';
        if (el('almKpTipoSelect')) el('almKpTipoSelect').value = '';
        window.almKpCargar();
    };

    window.almKpCargar = function (pageUrl) {
        if (!window.__almKp.idProducto) return;
        window.__almKp.desde = (el('almKpDesde') && el('almKpDesde').value) || '';
        window.__almKp.hasta = (el('almKpHasta') && el('almKpHasta').value) || '';

        var p = new URLSearchParams();
        p.set('id_producto', window.__almKp.idProducto);
        p.set('mini', '1');
        if (val('almSelAlmacen')) p.set('id_almacen', val('almSelAlmacen'));
        if (window.__almKp.tipo)  p.set('tipo',  window.__almKp.tipo);
        if (window.__almKp.desde) p.set('desde', window.__almKp.desde);
        if (window.__almKp.hasta) p.set('hasta', window.__almKp.hasta);
        if (pageUrl) {
            try { var pg = new URL(pageUrl, window.location.origin).searchParams.get('page'); if (pg) p.set('page', pg); } catch (e) {}
        }

        var body = el('almKpBody'); if (body) body.style.opacity = '0.5';
        fetch(ROUTE_MOVIMIENTOS + '?' + p.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (body && data.html !== undefined) body.innerHTML = data.html;
            var pg = el('almKpPag'); if (pg) pg.innerHTML = data.pagination || '';
        })
        .catch(function () {
            if (body) body.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px;color:#dc2626;font-size:12px;">No se pudieron cargar los movimientos.</td></tr>';
        })
        .finally(function () { if (body) body.style.opacity = '1'; });
    };

    // Click en links de paginación del kardex del producto.
    document.addEventListener('click', function (e) {
        var a = e.target.closest('#almKpPag a'); if (!a) return;
        e.preventDefault(); e.stopImmediatePropagation();
        window.almKpCargar(a.href);
    }, true);

    window.almAbrirAjuste = function (idProducto, codigo, nombre, um, saldo, minimo) {
        var m = el('almAjusteModal');
        m.dataset.idProducto = idProducto;
        m.dataset.minimoOrig = (minimo == null ? '' : String(minimo)); // para detectar si el usuario lo cambió
        if (el('almAjNombre')) el('almAjNombre').textContent = nombre;
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
            // El minimo de alerta debe ser > 0 (un minimo de 0 no avisa de nada, equivale
            // a "sin alerta" — que ya se logra dejando el campo vacio).
            nuevoMinimo = parseFloat(minimoRaw);
            if (isNaN(nuevoMinimo) || nuevoMinimo <= 0) { showErr('almAjError', 'El mínimo debe ser un número mayor que 0 (o dejarlo vacío para quitar la alerta).'); return; }
        }
        if (ns === null && !cambiaMinimo) { almCerrar('almAjusteModal'); return; } // nada que hacer

        var tareas = [];
        if (ns !== null) {
            // Endpoint unificado de lote: la Auditoría se registra como un lote de 1 línea
            // con tipo=AJUSTE. El backend ignora los campos de Nota de Entrega para AJUSTE.
            tareas.push(fetch(ROUTE_LOTE, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: JSON.stringify({
                    id_almacen: idAlm,
                    tipo: 'AJUSTE',
                    motivo: val('almAjMotivo') || 'Auditoría de Inventario',
                    lineas: [{ id_producto: m.dataset.idProducto, cantidad: ns }],
                })
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
            if (fail) { showErr('almAjError', (fail.b && fail.b.message) || 'No se pudo registrar la auditoría.'); almCargar(); return; }
            almCerrar('almAjusteModal'); toast('Auditoría registrada.'); almCargar();
        }).catch(function () { unpre(); showErr('almAjError', 'Error de red.'); });
    };

    // confirmación reutilizable (usa el modal estándar de la app si existe; si no, confirm()).
    function almConfirm(msg, onYes) {
        if (window.showModal) {
            window.showModal({ type: 'danger', title: '¿Confirmar?', message: msg, confirmText: 'Aceptar', cancelText: 'Cancelar', onConfirm: onYes });
        } else if (window.confirm(msg.replace(/<[^>]+>/g, ''))) { onYes(); }
    }

    @if($puedeManage)
    var ROUTE_ALM = @json(route('almacen.almacenes.store'));
    function ROUTE_ALM_ITEM(id) { return ROUTE_INDEX + '/almacenes/' + id; }
    function ROUTE_PROD_ITEM(id) { return ROUTE_INDEX + '/productos/' + id; }
    // Datos de los almacenes visibles (para el modal de edición): { id: {NOMBRE,TIPO,CODIGO,UBICACION,frentes:[ids]} }
    window.almAlmacenesData = @json($almacenesData);

    // Selección del custom-dropdown "Tipo" en el modal de almacén
    window.almNvTipoSelect = function (value, label) {
        var hidden = document.getElementById('almNvTipo');
        var display = document.getElementById('almNvTipoDisplay');
        var dropdown = document.getElementById('almNvTipoDropdown');
        if (hidden) hidden.value = value;
        if (display) display.value = label;
        // Marcar el item seleccionado
        dropdown.querySelectorAll('.dropdown-item').forEach(function(i) {
            i.classList.toggle('selected', i.dataset.value === value);
        });
        // Cerrar el dropdown (dejar que el CSS lo oculte al quitar .active)
        dropdown.classList.remove('active');
        var content = dropdown.querySelector('.dropdown-content');
        if (content) content.style.display = '';
        var trigger = dropdown.querySelector('.dropdown-trigger');
        if (trigger) trigger.style.borderColor = '#cbd5e0';
        // Actualizar visibilidad del panel de frentes
        window.almToggleFrentes();
    };

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
        if (el('almNvAlmacenista')) el('almNvAlmacenista').value = '';
        almNvTipoSelect('PROYECTO', 'Proyecto (Limitado a frentes específicos)');
        almNvSetFrentes([]);
        showErr('almNvError', '');
    }
    window.almAbrirAlmacen = function () {
        almResetAlmacenModal();
        el('almNvTitulo').textContent = 'Nuevo almacén'; el('almNvSubmit').textContent = 'Guardar';
        open('almAlmacenModal'); setTimeout(function () { el('almNvNombre').focus(); }, 60);
    };
    window.almEditarAlmacen = function (id) {
        var d = (window.almAlmacenesData || {})[id]; if (!d) { toast('No se encontró el almacén.', 'error'); return; }
        almResetAlmacenModal();
        el('almAlmacenModal').dataset.idAlmacen = id;
        el('almNvTitulo').textContent = 'Editar almacén'; el('almNvSubmit').textContent = 'Guardar cambios';
        el('almNvNombre').value = d.NOMBRE || ''; el('almNvUbicacion').value = d.UBICACION || '';
        if (el('almNvAlmacenista')) el('almNvAlmacenista').value = d.ALMACENISTA || '';
        var tipo = d.TIPO || 'PROYECTO';
        almNvTipoSelect(tipo, tipo === 'GENERAL' ? 'Global (Todos los frentes)' : 'Proyecto (Limitado a frentes específicos)');
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
            body: JSON.stringify({ NOMBRE: nombre, TIPO: tipo, UBICACION: val('almNvUbicacion') || null, ALMACENISTA: val('almNvAlmacenista') || null, frentes: frentes })
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
        if (el('almProdUbicacion')) el('almProdUbicacion').value = '';
        var cs = el('almProdCatSuggest'); if (cs) cs.innerHTML = '';
        var us = el('almProdUmSuggestBox'); if (us) { us.innerHTML = ''; us.classList.remove('open'); }
        almProdCatHide();
        // Limpiar resaltados de error de todos los campos del modal
        almProdFieldErr('almProdCodigo',  false);
        almProdFieldErr('almProdNombre',  false);
        almProdFieldErr('almProdUm',      false);
        showErr('almProdError', '');
    }
    window.almAbrirProducto = function () {
        almResetProductoModal();
        el('almProdTitulo').textContent = 'Nuevo producto'; el('almProdSubmit').textContent = 'Guardar';
        el('almProdCodigo').readOnly = false; el('almProdCodigo').style.background = '';
        open('almProductoModal'); setTimeout(function () { el('almProdCodigo').focus(); }, 60);
    };
    window.almEditarProducto = function (id, cod, nom, um, cat, ubicacion) {
        almResetProductoModal();
        el('almProductoModal').dataset.idProducto = id;
        el('almProdTitulo').textContent = 'Editar producto'; el('almProdSubmit').textContent = 'Guardar';
        // El código es de sólo lectura al editar (puede ser PRD-XXXX o numérico).
        el('almProdCodigo').value = cod || ''; el('almProdCodigo').readOnly = true; el('almProdCodigo').style.background = '#f1f5f9';
        el('almProdNombre').value = nom || ''; el('almProdUm').value = um || 'UND'; el('almProdCategoria').value = cat || '';
        if (el('almProdUbicacion')) el('almProdUbicacion').value = ubicacion || '';
        open('almProductoModal'); setTimeout(function () { el('almProdNombre').focus(); }, 60);
    };
    window.almGuardarProducto = function () {
        var m = el('almProductoModal'), id = m.dataset.idProducto || null;
        var codigo = val('almProdCodigo'), nombre = val('almProdNombre'), um = val('almProdUm') || 'UND', cat = val('almProdCategoria');
        var ubicacion = val('almProdUbicacion');
        // Validaciones previas al envío.
        if (!nombre) { almProdFieldErr('almProdNombre', true); showErr('almProdError', 'La descripción es obligatoria.'); return; }
        // Al crear: el código manual debe ser solo dígitos enteros positivos.
        // Al editar: el código es readonly (puede ser PRD-XXXX), no se valida aquí.
        if (!id && codigo && (!/^\d+$/.test(codigo) || parseInt(codigo, 10) < 1)) {
            almProdFieldErr('almProdCodigo', true);
            showErr('almProdError', 'El código debe ser un número entero positivo.');
            return;
        }
        // Limpiar errores visuales antes de enviar
        almProdFieldErr('almProdCodigo', false);
        almProdFieldErr('almProdNombre', false);
        pre();
        fetch(id ? ROUTE_PROD_ITEM(id) : ROUTE_PROD, {
            method: id ? 'PATCH' : 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: JSON.stringify(id
                // Al editar: CODIGO no se incluye → el backend conserva el existente (evita conflicto con regex).
                ? { NOMBRE: nombre, UM: um, CATEGORIA: cat || null, UBICACION: ubicacion || null }
                // Al crear: CODIGO se incluye (número o null para auto-generar PRD-XXXX).
                : { CODIGO: codigo || null, NOMBRE: nombre, UM: um, CATEGORIA: cat || null, UBICACION: ubicacion || null }
            )
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            unpre();
            if (res.ok) { almCerrar('almProductoModal'); toast(res.b.message || (id ? 'Producto actualizado.' : 'Producto creado.')); almCargar(); }
            else {
                var msg = (res.b && res.b.message) || 'No se pudo guardar el producto.';
                var fieldError = false;
                if (res.b && res.b.errors) {
                    msg = Object.values(res.b.errors).map(function (a) { return a.join(' '); }).join(' ');
                    // Resaltar el campo específico según la clave de error
                    if (res.b.errors.CODIGO)  { almProdFieldErr('almProdCodigo', true);  fieldError = true; }
                    if (res.b.errors.NOMBRE)  { almProdFieldErr('almProdNombre', true);  fieldError = true; }
                    if (res.b.errors.UM)      { almProdFieldErr('almProdUm',     true);  fieldError = true; }
                }
                showErr('almProdError', msg);
            }
        })
        .catch(function () { unpre(); showErr('almProdError', 'Error de red.'); });
    };
    window.almEliminarProducto = function (id) {
        almConfirm('¿Eliminar este producto? Si tiene saldo o movimientos se desactivará.', function () {
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
    // ── Modal "Registrar salida" unificado ─────────────────────────────────────
    //  Un solo formulario para ambos casos: salida para consumo (mismo almacén) o
    //  salida hacia otro proyecto (TRASPASO). El backend decide qué hacer según el
    //  frente destino — ambos generan Nota de Entrega NE-YYYY-NNNN.
    //  ALM_SAL.idAlmacen = almacén de origen (el que muestra la tabla).
    var ALM_SAL = { idAlmacen: '' };
    window.almAbrirSalidaModal = function (idAlmacen) {
        ALM_SAL = { idAlmacen: String(idAlmacen || '') };
        // Limpiar campos de Nota de Entrega y poner FECHA = hoy por default.
        ['almSalidaContrato','almSalidaRq','almSalidaSolicitante','almSalidaDepartamento','almSalidaMotivo'].forEach(function (id) { var e = el(id); if (e) e.value = ''; });
        // El campo Proyecto es un custom-dropdown: lo reseteamos con su helper para que
        // el placeholder vuelva al default y el hidden #almSalidaProyecto quede vacío.
        if (typeof window.clearDropdownFilter === 'function') {
            window.clearDropdownFilter('almSalidaProyectoDropdown');
        }
        var fe = el('almSalidaFecha'); if (fe) fe.value = new Date().toISOString().slice(0, 10);
        // Reset de la lista de sugerencias de contrato (se llena al elegir proyecto).
        var cs = el('almSalidaContratoSug'); if (cs) { cs.style.display = 'none'; cs.innerHTML = ''; }
        showErr('almSalidaError', '');
        // Resumen: contar productos seleccionados en la tabla principal (es la fuente
        // de cantidades — el modal ya no tiene tabla de Productos propia).
        var rn = el('almSalidaResumenN'); if (rn) rn.textContent = almSelCount();
        open('almSalidaModal');
        // Foco en el primer campo útil de la Nota (el dropdown de proyecto).
        setTimeout(function () {
            var dd = document.querySelector('#almSalidaProyectoDropdown [data-filter-search]');
            if (dd) dd.focus();
        }, 60);
    };
    // Sugerencias de N° de Contrato segun el frente/proyecto elegido en el modal de salida.
    //   • 0 contratos asociados → la lista de sugerencias se oculta (el usuario lo teclea libre).
    //   • 1 contrato            → se autocompleta el input.
    //   • 2+ contratos          → aparecen como botones; clic en uno lo pega en el input.
    window.almSalidaOnProyectoChange = function () {
        var sel  = el('almSalidaProyecto');
        var inp  = el('almSalidaContrato');
        var box  = el('almSalidaContratoSug');
        if (!sel || !inp || !box) return;
        var idF  = sel.value;
        var list = (window.almFrenteContratos || {})[idF] || [];
        box.innerHTML = '';
        if (list.length === 0) { box.style.display = 'none'; return; }
        if (list.length === 1) {
            inp.value = list[0];
            box.style.display = 'none';
            return;
        }
        // Varios contratos: pintar como chips clicables. NO autocompletar para no
        // elegir uno arbitrario por el usuario.
        list.forEach(function (c) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = c;
            btn.style.cssText = 'background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:700;cursor:pointer;font-family:monospace;';
            btn.addEventListener('click', function () { inp.value = c; });
            box.appendChild(btn);
        });
        box.style.display = 'flex';
    };
    window.almSalidaConfirmar = function () {
        var v = function (id) { var e = el(id); return e ? e.value.trim() : ''; };
        var idFrenteDest = v('almSalidaProyecto');
        if (!idFrenteDest) { showErr('almSalidaError', 'Elige el proyecto / frente destino.'); return; }

        // Las cantidades viven en almSeleccion (las edita el usuario en la columna
        // "Cant. salida" de la tabla principal). Aquí solo validamos y armamos el payload.
        var lineas = [], faltan = [];
        Object.keys(almSeleccion).forEach(function (id) {
            var s   = almSeleccion[id] || {};
            var raw = String(s.cantidad == null ? '' : s.cantidad).replace(',', '.').trim();
            var c   = parseFloat(raw);
            var nombre = s.nombre || ('#' + id);
            if (!isFinite(c) || c <= 0) faltan.push(nombre);
            else lineas.push({ id_producto: parseInt(id, 10), cantidad: c });
        });
        if (!lineas.length) { showErr('almSalidaError', 'Indica una cantidad mayor que 0 en al menos un producto (columna "Cant. salida" de la tabla).'); return; }
        if (faltan.length)  { showErr('almSalidaError', 'Falta la cantidad (o es 0) en: ' + faltan.slice(0, 4).join(', ') + (faltan.length > 4 ? '…' : '') + '. Corrígelos en la tabla o deselecciónalos.'); return; }
        showErr('almSalidaError', '');

        // Único endpoint: registrarMovimientoLote tipo=SALIDA + id_frente_destino.
        // El backend decide internamente:
        //   - Si el frente destino comparte el almacén origen → SALIDA pura (consumo).
        //   - Si el frente destino tiene OTRO almacén → crea un Traspaso + envía + asigna
        //     NUMERO_NOTA. En ambos casos se devuelve nota_url con el PDF.
        var payload = {
            tipo:               'SALIDA',
            id_almacen:         ALM_SAL.idAlmacen,
            id_frente_destino:  parseInt(idFrenteDest, 10),
            id_frente:          parseInt(idFrenteDest, 10), // back-compat: SALIDA mismo-almacén usa id_frente
            lineas:             lineas,
        };
        var fecha  = v('almSalidaFecha');         if (fecha)  payload.fecha = fecha;
        var contr  = v('almSalidaContrato');      if (contr)  payload.numero_contrato = contr;
        var rqN    = v('almSalidaRq');            if (rqN)    payload.numero_rq = rqN;
        var solic  = v('almSalidaSolicitante');   if (solic)  payload.solicitante = solic;
        var depto  = v('almSalidaDepartamento');  if (depto)  payload.departamento = depto;
        var motivo = v('almSalidaMotivo');        if (motivo) payload.motivo = motivo;

        // Pre-abrimos pestaña vacía DENTRO del gesto del usuario para que el pop-up blocker
        // no la rechace. La redirigimos a nota_url cuando llega la respuesta (o la cerramos
        // si hubo error). Ambos flujos (consumo / traspaso) generan PDF.
        var pdfTab = window.open('about:blank', '_blank');
        var url = ROUTE_LOTE;

        pre();
        fetch(url, {
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
                // SALIDA: el backend devuelve nota_url con el PDF de la Nota de Entrega (VID-FO-GEN-019).
                if (res.b && res.b.nota_url && pdfTab) {
                    try { pdfTab.location.href = res.b.nota_url; } catch (e) { try { pdfTab.close(); } catch (_) {} }
                } else if (pdfTab) {
                    try { pdfTab.close(); } catch (e) {}
                }
            } else {
                if (pdfTab) { try { pdfTab.close(); } catch (e) {} }
                var msg = (res.b && res.b.message) || 'No se pudo registrar el movimiento.';
                if (res.b && res.b.errors) msg = Object.values(res.b.errors).map(function (a) { return a.join(' '); }).join(' ');
                showErr('almSalidaError', msg);
            }
        })
        .catch(function () {
            unpre();
            if (pdfTab) { try { pdfTab.close(); } catch (e) {} }
            showErr('almSalidaError', 'Error de red.');
        });
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
