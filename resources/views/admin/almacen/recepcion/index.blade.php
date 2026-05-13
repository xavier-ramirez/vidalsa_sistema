@extends('layouts.estructura_base')

@section('title', 'Pedidos de Traspaso')

@section('content')
@php
    $reqEstado  = request('estado');
    $reqOrigen  = request('id_almacen_origen');
    $reqDestino = request('id_almacen_destino');
    $reqSearch  = request('search');
    $reqDesde   = request('desde');
    $reqHasta   = request('hasta');
    $hayAdv     = $reqDesde || $reqHasta || ($reqEstado && $reqEstado !== 'all') || ($reqOrigen && $reqOrigen !== 'all') || ($reqDestino && $reqDestino !== 'all');

    $badgesEstado = [
        'BORRADOR'         => ['Borrador',         '#f1f5f9', '#64748b'],
        'ENVIADO'          => ['Enviado',          '#fef3c7', '#b45309'],
        'RECIBIDO'         => ['Recibido',         '#dcfce7', '#15803d'],
        'RECIBIDO_PARCIAL' => ['Parcial',          '#fee2e2', '#b91c1c'],
        'CANCELADO'        => ['Cancelado',        '#e2e8f0', '#475569'],
    ];
@endphp

<section class="page-title-card" style="text-align:left;margin:0 0 10px 0;">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
        <h1 class="page-title" style="margin:0;">
            <span class="page-title-line2" style="color:#000;">Recepción de Materiales</span>
        </h1>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            {{-- No hay botón "Nuevo envío" aquí: los envíos se inician desde el inventario
                 (/admin/almacen → "Enviar a otro almacén"). Esta pantalla es para CONFIRMAR
                 lo que llega + ver el historial. --}}
            <a href="{{ route('almacen.index') }}" class="btn-primary-maquinaria" style="height:45px;padding:0 16px;display:flex;align-items:center;gap:8px;text-decoration:none;background:#e2e8f0;color:#475569;box-shadow:none;">
                <i class="material-icons" style="font-size:18px;">arrow_back</i><span class="desktop-text">Inventario</span>
            </a>
        </div>
    </div>
</section>

<style>
    .tr-tabs { display:flex; gap:4px; border-bottom:2px solid #e2e8f0; margin-bottom:14px; flex-wrap:wrap; }
    .tr-tab { padding:9px 16px; cursor:pointer; font-size:13px; font-weight:700; color:#64748b; border-bottom:3px solid transparent; margin-bottom:-2px; display:flex; align-items:center; gap:8px; text-decoration:none; }
    .tr-tab.active { color:#0067b1; border-bottom-color:#0067b1; }
    .tr-tab .badge { background:#ef4444; color:#fff; border-radius:999px; padding:1px 7px; font-size:11px; font-weight:800; min-width:18px; text-align:center; }
    .tr-tab .badge.muted { background:#94a3b8; }
    #trFilters { display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin-bottom:10px; }
    #trFilters .tr-item { flex:1 1 220px; min-width:180px; max-width:300px; }
    #trFilters .tr-search { flex:2 1 280px; max-width:none; }
    .tr-search-box { display:flex; align-items:center; height:45px; border:1px solid #cbd5e0; border-radius:12px; background:#fbfcfd; overflow:hidden; }
    .tr-search-box.active { border-color:#0067b1; background:#e1effa; }
    .tr-search-box i.lupa { padding:0 10px; color:#64748b; font-size:18px; }
    .tr-search-box input { flex:1; border:none; background:transparent; outline:none; padding:10px 5px; font-size:14px; min-width:0; }
    .tr-table { width:100%; border-collapse:collapse; font-size:13px; }
    .tr-table thead th { text-align:left; color:#64748b; font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.3px; padding:9px 10px; border-bottom:2px solid #e2e8f0; background:#f8fafc; white-space:nowrap; }
    .tr-table tbody td { padding:10px; border-bottom:1px solid #f1f5f9; }
    .tr-table tbody tr:hover { background:#f8fafc; cursor:pointer; }
    .estado-pill { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:999px; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.3px; }
</style>

<div class="admin-card" style="margin:0;min-height:70vh;padding:14px;">

    {{-- ── Tabs ── (sin "Borradores" porque ya no se crean desde aquí; los envíos van directo) --}}
    <div class="tr-tabs">
        <a href="{{ route('almacen.recepcion.index', ['tab' => 'por_recibir']) }}" class="tr-tab {{ $tab === 'por_recibir' ? 'active' : '' }}">
            <i class="material-icons" style="font-size:18px;">inbox</i> Por recibir
            @if($contPorRecibir > 0)<span class="badge">{{ $contPorRecibir }}</span>@endif
        </a>
        <a href="{{ route('almacen.recepcion.index', ['tab' => 'todos']) }}" class="tr-tab {{ $tab === 'todos' ? 'active' : '' }}">
            <i class="material-icons" style="font-size:18px;">list_alt</i> Historial
        </a>
    </div>

    {{-- ── Filtros (search + filtros avanzados estilo equipos) ── --}}
    <div id="trFilters">
        <div class="tr-item tr-search">
            <div class="tr-search-box {{ $reqSearch ? 'active' : '' }}">
                <i class="material-icons lupa">search</i>
                <input type="text" id="trSearch" autocomplete="off" placeholder="Buscar por número (TR-2026-…)…" value="{{ $reqSearch }}"
                       oninput="clearTimeout(window._trST); window._trST = setTimeout(window.trLoad, 400);">
            </div>
        </div>

        <div style="position:relative;flex:0 0 auto;">
            <button type="button" class="btn-primary-maquinaria" title="Filtros Avanzados"
                    style="height:45px;width:45px;padding:0;display:flex;align-items:center;justify-content:center;border-radius:12px;
                           background:{{ $hayAdv ? '#fee2e2' : '#fff' }};border:1px solid {{ $hayAdv ? '#ef4444' : '#cbd5e0' }};color:{{ $hayAdv ? '#ef4444' : '#64748b' }};box-shadow:none;"
                    onclick="window.trToggleAdv(event)">
                <i class="material-icons">filter_list</i>
            </button>
            <div id="trAdvPanel" style="display:none;position:absolute;top:100%;right:0;width:340px;max-width:calc(100vw - 20px);background:#e2e8f0;border:1px solid #cbd5e1;border-radius:12px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.15);z-index:100;margin-top:10px;padding:15px;">
                <h4 style="margin:0 0 14px 0;font-size:14px;font-weight:700;color:#334155;display:flex;justify-content:space-between;align-items:center;">
                    Filtros Avanzados
                    <span style="font-size:11px;color:#64748b;font-weight:400;text-decoration:underline;cursor:pointer;" onclick="window.trClearAdv()">Limpiar Todo</span>
                </h4>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <div>
                        <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;">Estado</span>
                        <select id="trEstado" onchange="window.trLoad()" style="width:100%;height:34px;border:1px solid #e2e8f0;border-radius:6px;padding:0 8px;background:white;font-size:12px;outline:none;">
                            <option value="all">Todos los estados</option>
                            @foreach($badgesEstado as $k => $b)
                                <option value="{{ $k }}" {{ $reqEstado === $k ? 'selected' : '' }}>{{ $b[0] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;">Almacén origen</span>
                        <select id="trOrigen" onchange="window.trLoad()" style="width:100%;height:34px;border:1px solid #e2e8f0;border-radius:6px;padding:0 8px;background:white;font-size:12px;outline:none;">
                            <option value="all">Todos</option>
                            @foreach($almacenes as $a)
                                <option value="{{ $a->ID_ALMACEN }}" {{ (string) $reqOrigen === (string) $a->ID_ALMACEN ? 'selected' : '' }}>{{ $a->NOMBRE }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;">Almacén destino</span>
                        <select id="trDestino" onchange="window.trLoad()" style="width:100%;height:34px;border:1px solid #e2e8f0;border-radius:6px;padding:0 8px;background:white;font-size:12px;outline:none;">
                            <option value="all">Todos</option>
                            @foreach($almacenes as $a)
                                <option value="{{ $a->ID_ALMACEN }}" {{ (string) $reqDestino === (string) $a->ID_ALMACEN ? 'selected' : '' }}>{{ $a->NOMBRE }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div>
                            <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;">Desde</span>
                            <input type="date" id="trDesde" value="{{ $reqDesde }}" onchange="window.trLoad()" style="width:100%;height:32px;border:1px solid #e2e8f0;border-radius:6px;padding:0 8px;background:white;font-size:12px;outline:none;">
                        </div>
                        <div>
                            <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;">Hasta</span>
                            <input type="date" id="trHasta" value="{{ $reqHasta }}" onchange="window.trLoad()" style="width:100%;height:32px;border:1px solid #e2e8f0;border-radius:6px;padding:0 8px;background:white;font-size:12px;outline:none;">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Tabla ── --}}
    <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:12px;">
        <table class="tr-table">
            <thead>
                <tr>
                    <th>Nº</th>
                    <th>Origen → Destino</th>
                    <th style="text-align:center;">Estado</th>
                    <th style="text-align:right;">Líneas</th>
                    <th>Enviado</th>
                    <th>Recibido</th>
                    <th>Creado por</th>
                </tr>
            </thead>
            <tbody id="trTableBody">
                @include('admin.almacen.recepcion.partials.rows', ['traspasos' => $traspasos])
            </tbody>
        </table>
    </div>

    <div style="margin-top:14px;" id="trPagination">{{ $traspasos->links('vendor.pagination.custom-sliding') }}</div>
</div>

<script>
(function () {
    'use strict';
    if (!document.getElementById('trTableBody')) return;
    var ROUTE = @json(route('almacen.recepcion.index'));
    var TAB   = @json($tab);

    function el(id) { return document.getElementById(id); }
    function v(id) { var e = el(id); return e ? String(e.value).trim() : ''; }

    function params(pageUrl) {
        var p = new URLSearchParams();
        p.set('tab', TAB);
        if (v('trSearch'))                                 p.set('search', v('trSearch'));
        if (v('trEstado')  && v('trEstado')  !== 'all')    p.set('estado', v('trEstado'));
        if (v('trOrigen')  && v('trOrigen')  !== 'all')    p.set('id_almacen_origen', v('trOrigen'));
        if (v('trDestino') && v('trDestino') !== 'all')    p.set('id_almacen_destino', v('trDestino'));
        if (v('trDesde'))                                  p.set('desde', v('trDesde'));
        if (v('trHasta'))                                  p.set('hasta', v('trHasta'));
        if (pageUrl) { try { var pg = new URL(pageUrl, window.location.origin).searchParams.get('page'); if (pg) p.set('page', pg); } catch (e) {} }
        return p;
    }

    window.trLoad = function (pageUrl) {
        var body = el('trTableBody'); if (!body) return;
        var url = ROUTE + '?' + params(pageUrl).toString();
        body.style.opacity = '0.5';
        if (window.showPreloader) window.showPreloader();
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.html !== undefined) body.innerHTML = data.html;
                var pg = el('trPagination'); if (pg) pg.innerHTML = data.pagination || '';
                try { window.history.replaceState(null, '', url); } catch (e) {}
            })
            .catch(function () { body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:#dc2626;">No se pudieron cargar los traspasos.</td></tr>'; })
            .finally(function () { body.style.opacity = '1'; if (window.hidePreloader) window.hidePreloader(); });
    };

    // Click en fila → ir al detalle
    document.addEventListener('click', function (e) {
        var row = e.target.closest('#trTableBody tr[data-id]');
        if (row) window.location = @json(url('/admin/almacen/recepcion')) + '/' + row.dataset.id;
    });

    // Paginación AJAX
    document.addEventListener('click', function (e) {
        var a = e.target.closest('#trPagination a.page-link') || e.target.closest('#trPagination a');
        if (a) { e.preventDefault(); e.stopImmediatePropagation(); window.trLoad(a.href); }
    }, true);

    // Panel de filtros avanzados
    window.trToggleAdv = function (ev) {
        if (ev) ev.stopPropagation();
        var p = el('trAdvPanel'); if (!p) return;
        p.style.display = (p.style.display === 'block') ? 'none' : 'block';
    };
    window.trClearAdv = function () {
        ['trEstado','trOrigen','trDestino'].forEach(function (id) { var e = el(id); if (e) e.value = 'all'; });
        ['trDesde','trHasta'].forEach(function (id) { var e = el(id); if (e) e.value = ''; });
        window.trLoad();
    };
    document.addEventListener('click', function (e) {
        var p = el('trAdvPanel');
        if (p && p.style.display === 'block' && !e.target.closest('#trAdvPanel') && !e.target.closest('[onclick*="trToggleAdv"]')) p.style.display = 'none';
    });
})();
</script>
@endsection
