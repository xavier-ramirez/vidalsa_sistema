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

    .alm-btn {
        display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px;
        border-radius: 7px; border: 1px solid #e2e8f0; background: #fff; cursor: pointer; margin: 0 1px;
        transition: transform 0.12s, background 0.12s;
    }
    .alm-btn:hover { transform: scale(1.06); }
    .alm-btn-in   { color: #16a34a; border-color: #bbf7d0; } .alm-btn-in:hover   { background: #16a34a; color: #fff; }
    .alm-btn-out  { color: #dc2626; border-color: #fecaca; } .alm-btn-out:hover  { background: #dc2626; color: #fff; }
    .alm-btn-adj  { color: #0067b1; border-color: #bfdbfe; } .alm-btn-adj:hover  { background: #0067b1; color: #fff; }
    .alm-btn-hist { color: #64748b; } .alm-btn-hist:hover { background: #475569; color: #fff; }
    .alm-btn-edit { color: #0891b2; border-color: #cffafe; } .alm-btn-edit:hover { background: #0891b2; color: #fff; }
    .alm-btn-del  { color: #ef4444; border-color: #fecaca; } .alm-btn-del:hover  { background: #ef4444; color: #fff; }

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
    .alm-modal .alm-modal-body { overflow-y: auto; }
    .alm-kardex-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    .alm-kardex-table th { text-align: left; color: #64748b; font-size: 10.5px; font-weight: 800; text-transform: uppercase; padding: 7px 9px; border-bottom: 2px solid #e2e8f0; background: #f8fafc; position: sticky; top: 0; }
    .alm-kardex-table td { padding: 7px 9px; }
    .alm-admin-list { display: flex; flex-direction: column; gap: 6px; }
    .alm-admin-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 8px; }
    .alm-admin-row:hover { background: #f8fafc; }
    @keyframes almIn { from { transform: translateY(8px); opacity: 0; } to { transform: none; opacity: 1; } }
    @keyframes slideDown { from { transform: translateY(-8px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    .alm-modal-head { padding: 14px 18px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; }
    .alm-modal-head h3 { margin: 0; font-size: 15px; font-weight: 800; color: #1e293b; display: flex; align-items: center; gap: 8px; }
    .alm-modal-body { padding: 16px 18px; display: flex; flex-direction: column; gap: 12px; }
    .alm-modal-foot { padding: 12px 18px; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end; gap: 8px; }
    .alm-modal label { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.3px; display: block; margin-bottom: 4px; }
    .alm-modal input, .alm-modal select, .alm-modal textarea {
        width: 100%; border: 1px solid #cbd5e0; border-radius: 8px; padding: 9px 10px; font-size: 14px; outline: none; box-sizing: border-box;
    }
    .alm-modal input:focus, .alm-modal select:focus, .alm-modal textarea:focus { border-color: var(--maquinaria-blue, #0067b1); }
    .alm-pill { display: inline-block; background: #f1f5f9; border-radius: 6px; padding: 2px 8px; font-size: 12px; font-weight: 700; color: #334155; }
    .alm-x { cursor: pointer; color: #94a3b8; }
    .alm-x:hover { color: #475569; }

    @media (max-width: 768px) {
        #almFilters .alm-filter { max-width: none; flex: 1 1 100%; }
        .counter-sidebar { gap: 10px !important; }
    }
</style>

@php
    $reqAlm   = $almacenSel?->ID_ALMACEN;
    $reqDesc  = request('search_desc');
    $reqCod   = request('search_codigo');
    $reqCat   = request('categoria');
    $puedeManage = auth()->user()?->can('almacen.manage') ?? false;
    $puedeMover  = auth()->user()?->can('almacen.movimiento') ?? false;
    $st = $stats ?? ['total' => '—', 'con_saldo' => '—', 'stock_bajo' => '—', 'unidades' => 0];
    // Datos de los almacenes para el modal de edición (solo se usa si $puedeManage).
    $almacenesData = ($almacenes ?? collect())->keyBy('ID_ALMACEN')->map(function ($a) {
        return [
            'NOMBRE'    => $a->NOMBRE,
            'TIPO'      => $a->TIPO,
            'CODIGO'    => $a->CODIGO,
            'UBICACION' => $a->UBICACION,
            'frentes'   => $a->relationLoaded('frentes') ? $a->frentes->pluck('ID_FRENTE')->values() : [],
        ];
    });
@endphp

<section class="page-title-card" style="text-align:left;margin:0 0 10px 0;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color:#000;">Inventario de Almacén</span>
    </h1>
</section>

<div class="page-layout-grid">
<div class="admin-card" style="margin:0;min-height:80vh;min-width:0;width:100%;padding:14px;">

    {{-- ── Filtros ── --}}
    <div id="almFilters">
        {{-- Almacén --}}
        <div class="alm-filter active" style="flex:1.2 1 220px;">
            <div class="alm-filter-box">
                <span class="alm-ic"><i class="material-icons" style="font-size:18px;">warehouse</i></span>
                <select id="almSelAlmacen" onchange="almCargar()" aria-label="Almacén">
                    @forelse($almacenes as $a)
                        <option value="{{ $a->ID_ALMACEN }}" {{ $reqAlm == $a->ID_ALMACEN ? 'selected' : '' }}>
                            {{ $a->NOMBRE }} {{ $a->TIPO === 'GENERAL' ? '(Principal)' : '(Proyecto)' }}
                        </option>
                    @empty
                        <option value="">— sin almacenes —</option>
                    @endforelse
                </select>
                <span class="alm-ic"><i class="material-icons" style="font-size:18px;color:#94a3b8;">expand_more</i></span>
            </div>
        </div>

        {{-- Descripción (autocomplete con lista de recomendación) --}}
        <div class="alm-filter {{ $reqDesc ? 'active' : '' }}">
            <div class="alm-filter-box">
                <span class="alm-ic"><i class="material-icons" style="font-size:18px;">search</i></span>
                <input type="text" id="almFiltroDesc" list="almDescList" autocomplete="off"
                       placeholder="Buscar por descripción..." value="{{ $reqDesc }}"
                       oninput="almDebounce(almCargar)">
                <i class="material-icons filter-clear" style="display:{{ $reqDesc ? 'flex' : 'none' }};"
                   onclick="document.getElementById('almFiltroDesc').value='';this.style.display='none';almCargar();">close</i>
            </div>
            <datalist id="almDescList">
                @foreach(($recomDescripciones ?? collect()) as $d)<option value="{{ $d }}">@endforeach
            </datalist>
        </div>

        {{-- Categoría --}}
        <div class="alm-filter {{ $reqCat && $reqCat !== 'all' ? 'active' : '' }}">
            <div class="alm-filter-box">
                <span class="alm-ic"><i class="material-icons" style="font-size:18px;">category</i></span>
                <select id="almFiltroCat" onchange="almCargar()" aria-label="Categoría">
                    <option value="">TODAS LAS CATEGORÍAS</option>
                    @foreach(($categorias ?? collect()) as $c)
                        <option value="{{ $c }}" {{ $reqCat == $c ? 'selected' : '' }}>{{ $c }}</option>
                    @endforeach
                </select>
                <span class="alm-ic"><i class="material-icons" style="font-size:18px;color:#94a3b8;">expand_more</i></span>
            </div>
        </div>

        {{-- Código (autocomplete) --}}
        <div class="alm-filter {{ $reqCod ? 'active' : '' }}" style="flex:0.8 1 160px;">
            <div class="alm-filter-box">
                <span class="alm-ic"><i class="material-icons" style="font-size:18px;">tag</i></span>
                <input type="text" id="almFiltroCod" list="almCodList" autocomplete="off"
                       placeholder="Código..." value="{{ $reqCod }}"
                       oninput="almDebounce(almCargar)">
                <i class="material-icons filter-clear" style="display:{{ $reqCod ? 'flex' : 'none' }};"
                   onclick="document.getElementById('almFiltroCod').value='';this.style.display='none';almCargar();">close</i>
            </div>
            <datalist id="almCodList">
                @foreach(($recomCodigos ?? collect()) as $c)<option value="{{ $c }}">@endforeach
            </datalist>
        </div>

        {{-- Acciones (botón desplegable estilo /admin/equipos) --}}
        <div style="display:flex;gap:8px;margin-left:auto;flex:0 0 auto;align-items:center;">
            <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;color:#475569;font-weight:600;cursor:pointer;white-space:nowrap;">
                <input type="checkbox" id="almSoloBajo" onchange="almCargar()" style="accent-color:#f59e0b;"> Solo stock bajo
            </label>
            <div style="position:relative;">
                <button type="button" id="almBtnAcciones" class="btn-primary-maquinaria"
                        style="height:45px;padding:0 16px;display:flex;align-items:center;gap:8px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);"
                        onclick="window.almToggleAcciones(event)">
                    <i class="material-icons" style="font-size:18px;">settings</i><span class="desktop-text">Acciones</span><i class="material-icons" style="font-size:18px;">expand_more</i>
                </button>
                <div id="almAccionesMenu" style="display:none;position:absolute;top:100%;right:0;width:240px;background:#fff;border-radius:8px;box-shadow:0 10px 18px -3px rgba(0,0,0,0.18);border:1px solid #e2e8f0;z-index:60;margin-top:6px;overflow:hidden;animation:slideDown 0.18s ease-out;">
                    <button type="button" onclick="window.almAccion('kardex')" class="dropdown-item-custom" style="display:flex;align-items:center;gap:10px;padding:11px 14px;color:#475569;background:transparent;border:none;border-bottom:1px solid #f1f5f9;width:100%;text-align:left;cursor:pointer;">
                        <div style="background:#f1f5f9;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#475569;">receipt_long</i></div>
                        <span style="font-size:14px;font-weight:500;">Movimientos</span>
                    </button>
                    <button type="button" onclick="window.almAccion('alertas')" class="dropdown-item-custom" style="display:flex;align-items:center;gap:10px;padding:11px 14px;color:#475569;background:transparent;border:none;border-bottom:1px solid #f1f5f9;width:100%;text-align:left;cursor:pointer;">
                        <div style="background:#fef3c7;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#b45309;">warning</i></div>
                        <span style="font-size:14px;font-weight:500;">Alertas de stock</span>
                    </button>
                    @if($puedeMover)
                    <button type="button" onclick="window.almAccion('traspaso')" class="dropdown-item-custom" style="display:flex;align-items:center;gap:10px;padding:11px 14px;color:#475569;background:transparent;border:none;border-bottom:1px solid #f1f5f9;width:100%;text-align:left;cursor:pointer;">
                        <div style="background:#dbeafe;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#0067b1;">swap_horiz</i></div>
                        <span style="font-size:14px;font-weight:500;">Traspaso entre almacenes</span>
                    </button>
                    @endif
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
                    <th style="text-align:right;">Mínimo</th>
                    <th style="text-align:center;">Acciones</th>
                </tr>
            </thead>
            <tbody id="almTableBody">
                @include('admin.almacen.partials.table_rows', ['productos' => $productos, 'almacen' => $almacenSel])
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
            <div style="display:flex;gap:10px;">
                <div style="flex:1;">
                    <label>Tipo *</label>
                    <select id="almNvTipo" onchange="window.almToggleFrentes()">
                        <option value="GENERAL">Principal (GENERAL — ve todo)</option>
                        <option value="PROYECTO" selected>Secundario (PROYECTO — ligado a frentes)</option>
                    </select>
                </div>
                <div style="flex:0.8;"><label>Código</label><input type="text" id="almNvCodigo" maxlength="30" placeholder="Opcional"></div>
            </div>
            <div><label>Ubicación</label><input type="text" id="almNvUbicacion" maxlength="150" placeholder="Opcional"></div>
            <div id="almNvFrentesWrap">
                <label>Frentes que usan este almacén</label>
                <select id="almNvFrentes" multiple size="5" style="height:auto;">
                    @foreach(($frentesLista ?? collect()) as $f)
                        <option value="{{ $f->ID_FRENTE }}">{{ $f->NOMBRE_FRENTE }}</option>
                    @endforeach
                </select>
                <div style="font-size:11px;color:#94a3b8;margin-top:4px;">Ctrl/⌘ + clic para seleccionar varios. Varios proyectos pueden compartir un mismo almacén.</div>
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
                <div style="flex:1;"><label>Código *</label><input type="text" id="almProdCodigo" maxlength="50" placeholder="Ej: TOR-001"></div>
                <div style="flex:0.7;"><label>UM *</label><input type="text" id="almProdUm" maxlength="20" placeholder="UND, KG, LTS..." value="UND"></div>
            </div>
            <div><label>Descripción / producto *</label><input type="text" id="almProdNombre" maxlength="200" placeholder="Ej: TORNILLO HEXAGONAL 1/2&quot;"></div>
            <div><label>Categoría</label><input type="text" id="almProdCategoria" list="almCatList" maxlength="100" placeholder="Opcional"></div>
            <datalist id="almCatList">@foreach(($categorias ?? collect()) as $c)<option value="{{ $c }}">@endforeach</datalist>
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

{{-- Kardex / movimientos (solo lectura) --}}
<div id="almKardexModal" class="alm-modal-overlay">
    <div class="alm-modal alm-modal-wide">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">receipt_long</i> Movimientos <span id="almKxScope" style="font-weight:600;color:#64748b;font-size:13px;"></span></h3>
            <i class="material-icons alm-x" onclick="almCerrar('almKardexModal')">close</i>
        </div>
        <div class="alm-modal-body">
            <div style="display:flex;gap:10px;align-items:center;margin-bottom:10px;flex-wrap:wrap;">
                <select id="almKxTipo" onchange="window.almCargarKardex()" style="width:auto;min-width:160px;border:1px solid #cbd5e0;border-radius:8px;padding:7px 10px;font-size:13px;">
                    <option value="all">Todos los tipos</option>
                    <option value="ENTRADA">Entradas</option>
                    <option value="SALIDA">Salidas</option>
                    <option value="AJUSTE">Ajustes</option>
                    <option value="TRASPASO_ENTRADA">Traspasos (entran)</option>
                    <option value="TRASPASO_SALIDA">Traspasos (salen)</option>
                </select>
                <span id="almKxTotal" style="font-size:12.5px;color:#64748b;"></span>
            </div>
            <div style="overflow:auto;border:1px solid #e2e8f0;border-radius:10px;max-height:60vh;">
                <table class="alm-kardex-table">
                    <thead><tr>
                        <th>Fecha</th><th>Tipo</th><th>Producto</th><th style="text-align:right;">Cant.</th><th style="text-align:right;">Saldo</th><th>Destino / contraparte</th><th>Ref / motivo</th><th>Usuario</th>
                    </tr></thead>
                    <tbody id="almKxBody">
                        <tr><td colspan="8" style="text-align:center;padding:30px;color:#94a3b8;">Cargando…</td></tr>
                    </tbody>
                </table>
            </div>
            <div style="margin-top:10px;" id="almKxPagination"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almKardexModal')">Cerrar</button>
        </div>
    </div>
</div>

{{-- Alertas de stock bajo (todos los almacenes visibles, solo lectura) --}}
<div id="almAlertasModal" class="alm-modal-overlay">
    <div class="alm-modal alm-modal-wide">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;color:#b45309;">warning</i> Alertas de stock bajo</h3>
            <i class="material-icons alm-x" onclick="almCerrar('almAlertasModal')">close</i>
        </div>
        <div class="alm-modal-body">
            <div style="display:flex;gap:10px;align-items:center;margin-bottom:10px;flex-wrap:wrap;">
                <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;color:#475569;font-weight:600;cursor:pointer;">
                    <input type="checkbox" id="almAlSoloEste" onchange="window.almCargarAlertas()"> Solo el almacén seleccionado
                </label>
                <span id="almAlTotal" style="font-size:12.5px;color:#64748b;"></span>
            </div>
            <div style="overflow:auto;border:1px solid #e2e8f0;border-radius:10px;max-height:62vh;">
                <table class="alm-kardex-table">
                    <thead><tr>
                        <th>Almacén</th><th>Código</th><th>Producto</th><th>Categoría</th><th style="text-align:right;">Saldo</th><th style="text-align:right;">Mínimo</th><th style="text-align:right;">Falta</th>
                    </tr></thead>
                    <tbody id="almAlBody">
                        <tr><td colspan="7" style="text-align:center;padding:30px;color:#94a3b8;">Cargando…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almAlertasModal')">Cerrar</button>
        </div>
    </div>
</div>

@if($puedeMover)
{{-- Traspaso entre almacenes --}}
<div id="almTraspasoModal" class="alm-modal-overlay">
    <div class="alm-modal">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">swap_horiz</i> Traspaso entre almacenes</h3>
            <i class="material-icons alm-x" onclick="almCerrar('almTraspasoModal')">close</i>
        </div>
        <div class="alm-modal-body">
            <div style="display:flex;gap:10px;">
                <div style="flex:1;">
                    <label>Desde (origen)</label>
                    <select id="almTrOrigen">
                        @foreach($almacenes as $a)<option value="{{ $a->ID_ALMACEN }}">{{ $a->NOMBRE }}</option>@endforeach
                    </select>
                </div>
                <div style="flex:1;">
                    <label>Hacia (destino)</label>
                    <select id="almTrDestino">
                        @foreach($almacenes as $a)<option value="{{ $a->ID_ALMACEN }}">{{ $a->NOMBRE }}</option>@endforeach
                    </select>
                </div>
            </div>
            <div>
                <label>Producto</label>
                <select id="almTrProducto">
                    <option value="">— seleccionar —</option>
                    @foreach(($productosLista ?? collect()) as $p)
                        <option value="{{ $p->ID_PRODUCTO }}">{{ $p->CODIGO }} — {{ $p->NOMBRE }}</option>
                    @endforeach
                </select>
            </div>
            <div style="display:flex;gap:10px;">
                <div style="flex:1;"><label>Cantidad *</label><input type="number" id="almTrCantidad" min="0.001" step="any" placeholder="0"></div>
                <div style="flex:1;"><label>Referencia</label><input type="text" id="almTrReferencia" maxlength="100" placeholder="Opcional"></div>
            </div>
            <div><label>Notas</label><input type="text" id="almTrNotas" maxlength="200" placeholder="Opcional"></div>
            <div id="almTrError" style="display:none;color:#dc2626;font-size:13px;font-weight:600;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almTraspasoModal')">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" onclick="window.almGuardarTraspaso()">Traspasar</button>
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
    var ROUTE_TRASP   = @json(route('almacen.traspasos.store'));
    var ROUTE_PROD    = @json(route('almacen.productos.store'));
    function ROUTE_MIN(idAlm)   { return ROUTE_INDEX + '/almacenes/' + idAlm + '/minimo'; }
    function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.getAttribute('content') : ''; }
    function toast(msg, type) { if (window.showToast) window.showToast(msg, type || 'success'); else if (type === 'error') alert(msg); }
    function pre()  { if (typeof window.showPreloader === 'function') window.showPreloader(); }
    function unpre(){ if (typeof window.hidePreloader === 'function') window.hidePreloader(); }
    function el(id){ return document.getElementById(id); }
    function val(id){ var e = el(id); return e ? String(e.value).trim() : ''; }

    // ── estado de los filtros que no tienen control visible propio ──
    var soloConSaldo = false; // alternado desde el atajo "Con stock" del sidebar

    // ── debounce para los inputs de texto ──
    var _t = null;
    window.almDebounce = function (fn) { clearTimeout(_t); _t = setTimeout(fn, 350); };

    // ── filtros → params (única fuente de verdad de los filtros activos) ──
    function filtros() {
        var p = new URLSearchParams();
        var alm = val('almSelAlmacen'); if (alm) p.set('id_almacen', alm);
        var d = val('almFiltroDesc');   if (d)   p.set('search_desc', d);
        var c = val('almFiltroCod');    if (c)   p.set('search_codigo', c);
        var cat = val('almFiltroCat');  if (cat) p.set('categoria', cat);
        var sb = el('almSoloBajo');     if (sb && sb.checked) p.set('solo_bajo', '1');
        if (soloConSaldo)               p.set('solo_con_saldo', '1');
        // reflejar estado "active" en los wrappers
        var setActive = function (sel, on) { var w = sel && sel.closest('.alm-filter'); if (w) w.classList.toggle('active', !!on); };
        setActive(el('almFiltroDesc'), d); setActive(el('almFiltroCod'), c); setActive(el('almFiltroCat'), cat && cat !== 'all');
        // toggle de las "x" de limpiar
        var tx = function (inputId) { var i = el(inputId); if (!i) return; var x = i.parentElement.querySelector('.filter-clear'); if (x) x.style.display = i.value ? 'flex' : 'none'; };
        tx('almFiltroDesc'); tx('almFiltroCod');
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
            ['id_almacen','search_desc','search_codigo','categoria','solo_bajo','solo_con_saldo'].forEach(function (k) { if (!f.has(k)) u.searchParams.delete(k); });
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
        el('almFiltroDesc').value = ''; el('almFiltroCod').value = ''; el('almFiltroCat').value = '';
        if (el('almSoloBajo')) el('almSoloBajo').checked = false;
        soloConSaldo = false;
        almCargar();
    };
    window.almFilterByCategoria = function (cat) { var s = el('almFiltroCat'); if (s) { s.value = cat || ''; } almCargar(); };
    window.almFiltrarConSaldo = function () { soloConSaldo = true; if (el('almSoloBajo')) el('almSoloBajo').checked = false; almCargar(); };
    window.almFiltrarBajo = function () { if (el('almSoloBajo')) el('almSoloBajo').checked = true; soloConSaldo = false; almCargar(); };

    // ── paginación (event delegation) ──
    document.addEventListener('click', function (e) {
        var a = e.target.closest('#almPagination a');
        if (a) { e.preventDefault(); e.stopPropagation(); window.almCargar(a.href); }
    }, true);

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
            case 'kardex':   if (window.almAbrirKardex)         window.almAbrirKardex();         break;
            case 'alertas':  if (window.almAbrirAlertas)        window.almAbrirAlertas();        break;
            case 'traspaso': if (window.almAbrirTraspaso)       window.almAbrirTraspaso();       break;
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

    // ── Kardex / movimientos (modal, solo lectura) ──
    var ROUTE_MOVIMIENTOS = @json(route('almacen.movimientos'));
    window.almAbrirKardex = function (idProducto, scopeLabel) {
        var m = el('almKardexModal');
        if (idProducto) m.dataset.idProducto = idProducto; else delete m.dataset.idProducto;
        el('almKxScope').textContent = scopeLabel ? ('· ' + scopeLabel) : ('· ' + (function () { var s = el('almSelAlmacen'); return s && s.selectedOptions[0] ? s.selectedOptions[0].textContent.trim() : 'almacén'; })());
        if (el('almKxTipo')) el('almKxTipo').value = 'all';
        open('almKardexModal');
        window.almCargarKardex();
    };
    window.almCargarKardex = function (url) {
        var body = el('almKxBody'); if (!body) return;
        var m = el('almKardexModal');
        var p = new URLSearchParams();
        var idAlm = val('almSelAlmacen'); if (idAlm) p.set('id_almacen', idAlm);
        if (m.dataset.idProducto) p.set('id_producto', m.dataset.idProducto);
        var t = el('almKxTipo') ? el('almKxTipo').value : 'all'; if (t && t !== 'all') p.set('tipo', t);
        var finalUrl;
        if (url) { var u = new URL(url, window.location.origin); p.forEach(function (v, k) { u.searchParams.set(k, v); }); finalUrl = u.toString(); }
        else { finalUrl = ROUTE_MOVIMIENTOS + '?' + p.toString(); }
        body.style.opacity = '0.5';
        fetch(finalUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.html !== undefined) body.innerHTML = data.html;
                var pg = el('almKxPagination'); if (pg) pg.innerHTML = data.pagination || '';
                var tot = el('almKxTotal'); if (tot) tot.textContent = (data.total != null ? (data.total + ' movimiento(s)') : '');
            })
            .catch(function () { body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;color:#dc2626;">No se pudo cargar el kardex.</td></tr>'; })
            .finally(function () { body.style.opacity = '1'; });
    };
    document.addEventListener('click', function (e) {
        var a = e.target.closest('#almKxPagination a');
        if (a) { e.preventDefault(); e.stopPropagation(); window.almCargarKardex(a.href); }
    }, true);

    // ── Alertas de stock bajo (modal, solo lectura) ──
    var ROUTE_ALERTAS = @json(route('almacen.alertasStockBajo'));
    window.almAbrirAlertas = function () {
        if (el('almAlSoloEste')) el('almAlSoloEste').checked = false;
        open('almAlertasModal');
        window.almCargarAlertas();
    };
    window.almCargarAlertas = function () {
        var body = el('almAlBody'); if (!body) return;
        var p = new URLSearchParams();
        if (el('almAlSoloEste') && el('almAlSoloEste').checked) { var idAlm = val('almSelAlmacen'); if (idAlm) p.set('id_almacen', idAlm); }
        body.style.opacity = '0.5';
        fetch(ROUTE_ALERTAS + '?' + p.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.html !== undefined) body.innerHTML = data.html;
                var tot = el('almAlTotal'); if (tot) tot.textContent = (data.total != null ? (data.total + ' alerta(s)') : '');
            })
            .catch(function () { body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:#dc2626;">No se pudieron cargar las alertas.</td></tr>'; })
            .finally(function () { body.style.opacity = '1'; });
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
    function almResetAlmacenModal() {
        delete el('almAlmacenModal').dataset.idAlmacen;
        el('almNvNombre').value = ''; el('almNvCodigo').value = ''; el('almNvUbicacion').value = '';
        el('almNvTipo').value = 'PROYECTO';
        var fs = el('almNvFrentes'); if (fs) Array.prototype.forEach.call(fs.options, function (o) { o.selected = false; });
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
        el('almNvNombre').value = d.NOMBRE || ''; el('almNvCodigo').value = d.CODIGO || ''; el('almNvUbicacion').value = d.UBICACION || '';
        el('almNvTipo').value = d.TIPO || 'PROYECTO';
        var fs = el('almNvFrentes'), set = {}; (d.frentes || []).forEach(function (x) { set[String(x)] = true; });
        if (fs) Array.prototype.forEach.call(fs.options, function (o) { o.selected = !!set[o.value]; });
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
            var fs = el('almNvFrentes');
            if (fs) Array.prototype.forEach.call(fs.selectedOptions, function (o) { frentes.push(parseInt(o.value, 10)); });
        }
        var url = id ? ROUTE_ALM_ITEM(id) : ROUTE_ALM;
        pre();
        fetch(url, {
            method: id ? 'PATCH' : 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: JSON.stringify({ NOMBRE: nombre, TIPO: tipo, CODIGO: val('almNvCodigo') || null, UBICACION: val('almNvUbicacion') || null, frentes: frentes })
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
        el('almProdCodigo').value = ''; el('almProdNombre').value = ''; el('almProdUm').value = 'UND'; el('almProdCategoria').value = ''; showErr('almProdError', '');
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
        if (!codigo) { showErr('almProdError', 'El código es obligatorio.'); return; }
        if (!nombre) { showErr('almProdError', 'La descripción es obligatoria.'); return; }
        pre();
        fetch(id ? ROUTE_PROD_ITEM(id) : ROUTE_PROD, {
            method: id ? 'PATCH' : 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: JSON.stringify({ CODIGO: codigo, NOMBRE: nombre, UM: um, CATEGORIA: cat || null })
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
    window.almAbrirTraspaso = function () {
        var sel = val('almSelAlmacen'); if (sel) el('almTrOrigen').value = sel;
        el('almTrProducto').value = ''; el('almTrCantidad').value = ''; el('almTrReferencia').value = ''; el('almTrNotas').value = '';
        showErr('almTrError', ''); open('almTraspasoModal');
    };
    window.almGuardarTraspaso = function () {
        var origen = val('almTrOrigen'), destino = val('almTrDestino'), prod = val('almTrProducto'), cant = parseFloat(el('almTrCantidad').value);
        if (!origen || !destino) { showErr('almTrError', 'Selecciona origen y destino.'); return; }
        if (origen === destino) { showErr('almTrError', 'El origen y el destino no pueden ser el mismo almacén.'); return; }
        if (!prod) { showErr('almTrError', 'Selecciona un producto.'); return; }
        if (!cant || cant <= 0) { showErr('almTrError', 'Indica una cantidad mayor que cero.'); return; }
        pre();
        fetch(ROUTE_TRASP, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: JSON.stringify({ id_almacen_origen: origen, id_almacen_destino: destino, id_producto: prod, cantidad: cant, referencia: val('almTrReferencia') || null, notas: val('almTrNotas') || null })
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            unpre();
            if (res.ok) { almCerrar('almTraspasoModal'); toast(res.b.message || 'Traspaso registrado.'); almCargar(); }
            else { showErr('almTrError', (res.b && res.b.message) || 'No se pudo registrar el traspaso.'); }
        })
        .catch(function () { unpre(); showErr('almTrError', 'Error de red.'); });
    };
    @else
    window.almAbrirTraspaso = function () { toast('No tienes permiso para registrar traspasos.', 'error'); };
    @endif
})();

// ── Deep-link desde el navbar: /admin/almacen?modal=kardex|alertas|traspaso|admin|almacen|producto ──
// Fuera del IIFE con guard para que también funcione en re-montajes (navegación SPA).
(function () {
    try {
        var mdl = new URLSearchParams(window.location.search).get('modal');
        if (mdl) setTimeout(function () { if (window.almAccion) window.almAccion(mdl); }, 300);
    } catch (e) {}
})();
</script>
@endsection
