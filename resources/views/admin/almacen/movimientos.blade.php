@extends('layouts.estructura_base')

@section('title', 'Movimientos de Inventario')

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
        'ENTRADA'          => 'Entradas',
        'SALIDA'           => 'Salidas',
        'AJUSTE'           => 'Ajustes',
        'TRASPASO_ENTRADA' => 'Traspasos (entran)',
        'TRASPASO_SALIDA'  => 'Traspasos (salen)',
    ];
    $tipoSelLabel = ($reqTipo && isset($tipos[$reqTipo])) ? $tipos[$reqTipo] : null;
    $frenteSel    = ($reqFrente && $reqFrente !== 'all') ? ($frentesLista ?? collect())->firstWhere('ID_FRENTE', (int) $reqFrente) : null;
@endphp

<section class="page-title-card" style="text-align:left;margin:0 0 10px 0;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color:#000;">Bitácora de Movimientos de Inventario</span>
    </h1>
</section>

<style>
    #almMovFilters { display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin-bottom:8px; }
    #almMovFilters .amf-item { flex:1 1 200px; min-width:170px; max-width:300px; }
    #almMovFilters .amf-search { flex:2 1 280px; max-width:none; }
    #almMovFilters .custom-dropdown { width:100%; }
    .amf-search-box { display:flex; align-items:center; height:45px; border:1px solid #cbd5e0; border-radius:12px; background:#fbfcfd; overflow:hidden; }
    .amf-search-box.active { border-color:var(--maquinaria-blue,#0067b1); background:#e1effa; }
    .amf-search-box i.lupa { padding:0 10px; color:#64748b; font-size:18px; }
    .amf-search-box input { flex:1; border:none; background:transparent; outline:none; padding:10px 5px; font-size:14px; min-width:0; }
    .amf-search-box i.clr { padding:0 10px; color:#64748b; font-size:18px; cursor:pointer; }
    .amf-adv-btn { height:45px; width:45px; padding:0; display:flex; align-items:center; justify-content:center; border-radius:12px; box-shadow:none; }
    .alm-mov-table { width:100%; border-collapse:collapse; font-size:13px; }
    .alm-mov-table thead th { text-align:left; color:#64748b; font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.3px; padding:9px 10px; border-bottom:2px solid #e2e8f0; background:#f8fafc; white-space:nowrap; }
    .alm-mov-table tbody td { padding:9px 10px; }
    .alm-mov-table tbody tr:hover { background:#f8fafc; }
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

    {{-- ── Filtros ── --}}
    <div id="almMovFilters">

        {{-- Almacén --}}
        <div class="amf-item">
            <div class="custom-dropdown" id="almMovFiltroAlmacen" data-filter-type="id_almacen" data-default-label="Todos los almacenes">
                <input type="hidden" name="id_almacen" data-filter-value value="{{ $reqAlmacen && $reqAlmacen !== 'all' ? $reqAlmacen : '' }}">
                <div class="dropdown-trigger {{ $almSel ? 'filter-active' : '' }}" style="padding:0;display:flex;align-items:center;background:#fbfcfd;overflow:hidden;border:1px solid #cbd5e0;border-radius:12px;height:45px;">
                    <span style="padding:0 10px;display:flex;align-items:center;color:var(--maquinaria-gray-text);"><i class="material-icons" style="font-size:18px;">warehouse</i></span>
                    <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                           placeholder="{{ $almSel ? $almSel->NOMBRE : 'Todos los almacenes' }}"
                           style="flex:1;border:none;background:transparent;padding:10px 5px;font-size:14px;outline:none;min-width:0;"
                           oninput="window.filterDropdownOptions(this)">
                    <i class="material-icons" data-clear-btn style="padding:0 5px;color:var(--maquinaria-gray-text);font-size:18px;display:{{ $almSel ? 'block' : 'none' }};cursor:pointer;"
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

        {{-- Tipo --}}
        <div class="amf-item">
            <div class="custom-dropdown" id="almMovFiltroTipo" data-filter-type="tipo" data-default-label="Todos los tipos">
                <input type="hidden" name="tipo" data-filter-value value="{{ $reqTipo && isset($tipos[$reqTipo]) ? $reqTipo : '' }}">
                <div class="dropdown-trigger {{ $tipoSelLabel ? 'filter-active' : '' }}" style="padding:0;display:flex;align-items:center;background:#fbfcfd;overflow:hidden;border:1px solid #cbd5e0;border-radius:12px;height:45px;">
                    <span style="padding:0 10px;display:flex;align-items:center;color:var(--maquinaria-gray-text);"><i class="material-icons" style="font-size:18px;">filter_alt</i></span>
                    <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                           placeholder="{{ $tipoSelLabel ?: 'Todos los tipos' }}"
                           style="flex:1;border:none;background:transparent;padding:10px 5px;font-size:14px;outline:none;min-width:0;"
                           oninput="window.filterDropdownOptions(this)">
                    <i class="material-icons" data-clear-btn style="padding:0 5px;color:var(--maquinaria-gray-text);font-size:18px;display:{{ $tipoSelLabel ? 'block' : 'none' }};cursor:pointer;"
                       onclick="event.stopPropagation(); clearDropdownFilter('almMovFiltroTipo');">close</i>
                </div>
                <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                    <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                        <div class="dropdown-item {{ !$tipoSelLabel ? 'selected' : '' }}" data-value="all" onclick="selectOption('almMovFiltroTipo','all','TODOS LOS TIPOS');">TODOS LOS TIPOS</div>
                        @foreach($tipos as $k => $label)
                            <div class="dropdown-item {{ $reqTipo === $k ? 'selected' : '' }}" data-value="{{ $k }}" onclick="selectOption('almMovFiltroTipo','{{ $k }}','{{ addslashes($label) }}');">{{ $label }}</div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Frente --}}
        <div class="amf-item">
            <div class="custom-dropdown" id="almMovFiltroFrente" data-filter-type="id_frente" data-default-label="Todos los frentes">
                <input type="hidden" name="id_frente" data-filter-value value="{{ $reqFrente && $reqFrente !== 'all' ? $reqFrente : '' }}">
                <div class="dropdown-trigger {{ $frenteSel ? 'filter-active' : '' }}" style="padding:0;display:flex;align-items:center;background:#fbfcfd;overflow:hidden;border:1px solid #cbd5e0;border-radius:12px;height:45px;">
                    <span style="padding:0 10px;display:flex;align-items:center;color:var(--maquinaria-gray-text);"><i class="material-icons" style="font-size:18px;">apartment</i></span>
                    <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                           placeholder="{{ $frenteSel ? $frenteSel->NOMBRE_FRENTE : 'Todos los frentes' }}"
                           style="flex:1;border:none;background:transparent;padding:10px 5px;font-size:14px;outline:none;min-width:0;"
                           oninput="window.filterDropdownOptions(this)">
                    <i class="material-icons" data-clear-btn style="padding:0 5px;color:var(--maquinaria-gray-text);font-size:18px;display:{{ $frenteSel ? 'block' : 'none' }};cursor:pointer;"
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

        {{-- Buscar producto --}}
        <div class="amf-item amf-search">
            <div class="amf-search-box {{ $reqSearch ? 'active' : '' }}">
                <i class="material-icons lupa">search</i>
                <input type="text" id="almMovSearch" autocomplete="off" placeholder="Buscar producto (código o descripción)…" value="{{ $reqSearch }}"
                       oninput="clearTimeout(window._amfSearchTimer); window._amfSearchTimer = setTimeout(function(){ window.loadMovimientos(); }, 400);">
                <i class="material-icons clr" id="almMovSearchClear" style="display:{{ $reqSearch ? 'block' : 'none' }};" onclick="document.getElementById('almMovSearch').value=''; this.style.display='none'; window.loadMovimientos();">close</i>
            </div>
        </div>

        {{-- Filtro avanzado: rango de fechas --}}
        <div style="position:relative;flex:0 0 auto;">
            <button type="button" class="btn-primary-maquinaria amf-adv-btn" title="Rango de fechas"
                    style="background:{{ $hayAdv ? '#dbeafe' : '#fff' }};border:1px solid {{ $hayAdv ? '#0067b1' : '#cbd5e0' }};color:{{ $hayAdv ? '#0067b1' : '#64748b' }};"
                    onclick="window.almMovToggleFechas(event)">
                <i class="material-icons">date_range</i>
            </button>
            <div id="almMovFechasPanel" style="display:none;position:absolute;top:100%;right:0;width:260px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.18);z-index:60;margin-top:8px;padding:14px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <span style="font-size:13px;font-weight:800;color:#334155;">Rango de fechas</span>
                    <span style="font-size:11px;color:#64748b;text-decoration:underline;cursor:pointer;" onclick="window.almMovLimpiarFechas()">Limpiar</span>
                </div>
                <div style="display:flex;gap:8px;">
                    <div style="flex:1;">
                        <label style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:3px;">Desde</label>
                        <input type="date" id="almMovDesde" value="{{ $reqDesde }}" onchange="window.loadMovimientos()"
                               style="width:100%;height:34px;border:1px solid #cbd5e0;border-radius:7px;background:#fbfcfd;outline:none;padding:0 8px;font-size:12px;">
                    </div>
                    <div style="flex:1;">
                        <label style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:3px;">Hasta</label>
                        <input type="date" id="almMovHasta" value="{{ $reqHasta }}" onchange="window.loadMovimientos()"
                               style="width:100%;height:34px;border:1px solid #cbd5e0;border-radius:7px;background:#fbfcfd;outline:none;padding:0 8px;font-size:12px;">
                    </div>
                </div>
            </div>
        </div>

        {{-- Volver al inventario --}}
        <a href="{{ route('almacen.index') }}" class="btn-primary-maquinaria" style="height:45px;padding:0 16px;display:flex;align-items:center;gap:8px;text-decoration:none;background:#e2e8f0;color:#475569;box-shadow:none;margin-left:auto;flex:0 0 auto;">
            <i class="material-icons" style="font-size:18px;">arrow_back</i><span class="desktop-text">Inventario</span>
        </a>
    </div>

    {{-- ── Tabla ── --}}
    <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:12px;">
        <table class="alm-mov-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Producto</th>
                    <th style="text-align:right;">Cantidad</th>
                    <th style="text-align:right;">Saldo (antes → después)</th>
                    <th>Destino / contraparte</th>
                    <th>Ref / motivo</th>
                    <th>Usuario</th>
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

{{-- Sidebar: contador --}}
<div style="display:flex;flex-direction:column;gap:18px;">
    <div style="background:linear-gradient(135deg,#0c4a6e 0%,#0369a1 100%);border-radius:12px;padding:16px;color:#fff;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);position:relative;overflow:hidden;">
        <i class="material-icons" style="position:absolute;right:-12px;bottom:-12px;font-size:78px;opacity:0.12;transform:rotate(-12deg);">receipt_long</i>
        <div style="position:relative;z-index:2;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;opacity:0.9;margin-bottom:5px;">Total movimientos</div>
            <div style="display:flex;align-items:baseline;gap:6px;">
                <span id="almMovTotal" style="font-size:32px;font-weight:800;line-height:1;letter-spacing:-1px;">{{ $total }}</span>
                <span style="font-size:12px;opacity:0.85;font-weight:500;">registros (según filtros)</span>
            </div>
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
    function hv(name) { var e = document.querySelector('#almMovFilters input[name="' + name + '"][data-filter-value]'); return e ? String(e.value).trim() : ''; }

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
                // marca "activo" del buscador
                var sb = document.querySelector('.amf-search-box'); var si = el('almMovSearch');
                if (sb && si) sb.classList.toggle('active', !!si.value.trim());
                var sc = el('almMovSearchClear'); if (sc && si) sc.style.display = si.value.trim() ? 'block' : 'none';
                try { window.history.replaceState(null, '', url); } catch (e) {}
            })
            .catch(function () { body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;color:#dc2626;">No se pudieron cargar los movimientos.</td></tr>'; })
            .finally(function () { body.style.opacity = '1'; if (window.hidePreloader) window.hidePreloader(); });
    };

    // Selección en los custom-dropdown → recargar
    window.addEventListener('dropdown-selection', function (e) {
        if (!document.getElementById('almMovTableBody')) return;
        var id = e.detail && e.detail.dropdownId;
        if (id === 'almMovFiltroAlmacen' || id === 'almMovFiltroTipo' || id === 'almMovFiltroFrente') window.loadMovimientos();
    });

    // Paginación AJAX
    document.addEventListener('click', function (e) {
        var a = e.target.closest('#almMovPagination a.page-link') || e.target.closest('#almMovPagination a');
        if (a) { e.preventDefault(); e.stopImmediatePropagation(); window.loadMovimientos(a.href); }
    }, true);

    // Panel de fechas
    window.almMovToggleFechas = function (ev) {
        if (ev) ev.stopPropagation();
        var p = el('almMovFechasPanel'); if (!p) return;
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
})();
</script>
@endsection
