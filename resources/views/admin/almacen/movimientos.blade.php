@extends('layouts.estructura_base')

@section('title', 'Movimientos de Almacén')

@section('content')
<style>
    /* ── Movimientos de Almacén — página standalone (mismo kardex que el modal) ── */
    #mvFilters { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:4px 0 14px; }
    .mv-filter { position:relative; }
    .mv-box {
        display:flex; align-items:center; background:#fbfcfd; border:1px solid #cbd5e0;
        border-radius:12px; height:44px; overflow:hidden; padding:0 4px;
    }
    .mv-box.active { background:#e1effa; border-color:var(--maquinaria-blue,#0067b1); }
    .mv-box .mv-ic { padding:0 8px; display:flex; align-items:center; color:#64748b; }
    .mv-box select, .mv-box input { flex:1; border:none; background:transparent; outline:none; font-size:13.5px; color:#1e293b; padding:9px 6px; min-width:0; height:100%; }
    .mv-box select { cursor:pointer; -webkit-appearance:none; appearance:none; }

    .mv-table { width:100%; border-collapse:collapse; font-size:12.5px; }
    .mv-table thead th {
        text-align:left; color:#64748b; font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:0.4px;
        padding:9px 10px; border-bottom:2px solid #e2e8f0; background:#f8fafc; position:sticky; top:0; z-index:2;
    }
    .mv-table tbody td { padding:7px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
    .mv-table tbody tr:hover { background:#f8fafc; }
    @media (max-width:768px){ #mvFilters .mv-filter{ flex:1 1 100%; } }
</style>

<section class="page-title-card" style="text-align:left;margin:0 0 10px 0;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color:#000;">Movimientos de Almacén</span>
    </h1>
</section>

<div class="admin-card" style="margin:0;min-height:80vh;padding:14px;">

    {{-- ── Filtros ── --}}
    <div id="mvFilters">
        {{-- Almacén --}}
        <div class="mv-filter" style="flex:1.2 1 230px;">
            <div class="mv-box active" id="mvBoxAlmacen">
                <span class="mv-ic"><i class="material-icons" style="font-size:18px;">warehouse</i></span>
                <select id="mvAlmacen" onchange="mvCargar()">
                    <option value="">Todos mis almacenes</option>
                    @foreach($almacenes as $a)
                        <option value="{{ $a->ID_ALMACEN }}">{{ $a->NOMBRE }} {{ $a->TIPO === 'GENERAL' ? '(Principal)' : '(Proyecto)' }}</option>
                    @endforeach
                </select>
                <span class="mv-ic"><i class="material-icons" style="font-size:18px;color:#94a3b8;">expand_more</i></span>
            </div>
        </div>
        {{-- Producto --}}
        <div class="mv-filter" style="flex:1.4 1 260px;">
            <div class="mv-box" id="mvBoxProducto">
                <span class="mv-ic"><i class="material-icons" style="font-size:18px;">inventory_2</i></span>
                <select id="mvProducto" onchange="mvCargar()">
                    <option value="">Todos los productos</option>
                    @foreach(($productosLista ?? collect()) as $p)
                        <option value="{{ $p->ID_PRODUCTO }}">{{ $p->CODIGO }} — {{ $p->NOMBRE }}</option>
                    @endforeach
                </select>
                <span class="mv-ic"><i class="material-icons" style="font-size:18px;color:#94a3b8;">expand_more</i></span>
            </div>
        </div>
        {{-- Tipo --}}
        <div class="mv-filter" style="flex:0.9 1 170px;">
            <div class="mv-box" id="mvBoxTipo">
                <span class="mv-ic"><i class="material-icons" style="font-size:18px;">swap_vert</i></span>
                <select id="mvTipo" onchange="mvCargar()">
                    <option value="all">Todos los tipos</option>
                    <option value="ENTRADA">Entradas</option>
                    <option value="SALIDA">Salidas</option>
                    <option value="AJUSTE">Ajustes</option>
                    <option value="TRASPASO_ENTRADA">Traspasos (entran)</option>
                    <option value="TRASPASO_SALIDA">Traspasos (salen)</option>
                </select>
                <span class="mv-ic"><i class="material-icons" style="font-size:18px;color:#94a3b8;">expand_more</i></span>
            </div>
        </div>
        {{-- Frente --}}
        <div class="mv-filter" style="flex:1 1 200px;">
            <div class="mv-box" id="mvBoxFrente">
                <span class="mv-ic"><i class="material-icons" style="font-size:18px;">flag</i></span>
                <select id="mvFrente" onchange="mvCargar()">
                    <option value="">Todos los frentes</option>
                    @foreach(($frentesLista ?? collect()) as $f)
                        <option value="{{ $f->ID_FRENTE }}">{{ $f->NOMBRE_FRENTE }}</option>
                    @endforeach
                </select>
                <span class="mv-ic"><i class="material-icons" style="font-size:18px;color:#94a3b8;">expand_more</i></span>
            </div>
        </div>
        {{-- Fechas --}}
        <div class="mv-filter">
            <div class="mv-box" id="mvBoxDesde">
                <span class="mv-ic"><i class="material-icons" style="font-size:18px;">event</i></span>
                <input type="date" id="mvDesde" onchange="mvCargar()" title="Desde" style="min-width:130px;">
            </div>
        </div>
        <div class="mv-filter">
            <div class="mv-box" id="mvBoxHasta">
                <span class="mv-ic"><i class="material-icons" style="font-size:18px;">event</i></span>
                <input type="date" id="mvHasta" onchange="mvCargar()" title="Hasta" style="min-width:130px;">
            </div>
        </div>

        <div style="display:flex;gap:8px;margin-left:auto;flex:0 0 auto;align-items:center;">
            <span id="mvTotal" style="font-size:12.5px;color:#64748b;white-space:nowrap;"></span>
            <button type="button" class="btn-primary-maquinaria" style="height:44px;padding:0 12px;display:flex;align-items:center;gap:6px;background:#fff;color:#475569;border:1px solid #cbd5e0;box-shadow:none;"
                    onclick="mvLimpiar()" title="Quitar filtros">
                <i class="material-icons" style="font-size:18px;">filter_alt_off</i><span class="desktop-text">Limpiar</span>
            </button>
            <a href="{{ route('almacen.index') }}" class="btn-primary-maquinaria" style="height:44px;padding:0 14px;display:flex;align-items:center;gap:6px;text-decoration:none;">
                <i class="material-icons" style="font-size:18px;">inventory_2</i><span class="desktop-text">Ir al inventario</span>
            </a>
        </div>
    </div>

    {{-- ── Tabla ── --}}
    <div style="overflow:auto;border:1px solid #e2e8f0;border-radius:12px;max-height:70vh;">
        <table class="mv-table">
            <thead><tr>
                <th>Fecha</th><th>Tipo</th><th>Producto</th><th style="text-align:right;">Cant.</th>
                <th style="text-align:right;">Saldo</th><th>Destino / contraparte</th><th>Ref / motivo</th><th>Usuario</th>
            </tr></thead>
            <tbody id="mvBody">
                <tr><td colspan="8" style="text-align:center;padding:30px;color:#94a3b8;">Cargando…</td></tr>
            </tbody>
        </table>
    </div>
    <div style="margin-top:14px;" id="mvPagination"></div>
</div>

<script>
(function () {
    'use strict';
    if (window.__almMovPageInit) return;
    window.__almMovPageInit = true;

    var ROUTE_MOVIMIENTOS = @json(route('almacen.movimientos'));
    function el(id){ return document.getElementById(id); }
    function val(id){ var e = el(id); return e ? String(e.value).trim() : ''; }
    function pre()  { if (typeof window.showPreloader === 'function') window.showPreloader(); }
    function unpre(){ if (typeof window.hidePreloader === 'function') window.hidePreloader(); }

    function setActive(boxId, on) { var b = el(boxId); if (b) b.classList.toggle('active', !!on); }

    function params() {
        var p = new URLSearchParams();
        var a = val('mvAlmacen'); if (a) p.set('id_almacen', a);
        var pr = val('mvProducto'); if (pr) p.set('id_producto', pr);
        var t = val('mvTipo'); if (t && t !== 'all') p.set('tipo', t);
        var fr = val('mvFrente'); if (fr) p.set('id_frente', fr);
        var d = val('mvDesde'); if (d) p.set('desde', d);
        var h = val('mvHasta'); if (h) p.set('hasta', h);
        setActive('mvBoxAlmacen', true); // siempre "activo" visualmente (es el filtro principal)
        setActive('mvBoxProducto', pr); setActive('mvBoxTipo', t && t !== 'all'); setActive('mvBoxFrente', fr);
        setActive('mvBoxDesde', d); setActive('mvBoxHasta', h);
        return p;
    }

    window.mvCargar = function (url) {
        var body = el('mvBody'); if (!body) return;
        var finalUrl;
        if (url) {
            var u = new URL(url, window.location.origin);
            var f = params(); f.forEach(function (v, k) { u.searchParams.set(k, v); });
            ['id_almacen','id_producto','tipo','id_frente','desde','hasta'].forEach(function (k) { if (!f.has(k)) u.searchParams.delete(k); });
            finalUrl = u.toString();
        } else {
            finalUrl = ROUTE_MOVIMIENTOS + '?' + params().toString();
        }
        body.style.opacity = '0.5'; pre();
        fetch(finalUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.html !== undefined) body.innerHTML = data.html;
                var pg = el('mvPagination'); if (pg) pg.innerHTML = data.pagination || '';
                var tot = el('mvTotal'); if (tot) tot.textContent = (data.total != null ? (data.total + ' movimiento(s)') : '');
                try {
                    var clean = new URL(window.location.pathname, window.location.origin);
                    params().forEach(function (v, k) { clean.searchParams.set(k, v); });
                    window.history.replaceState({}, '', clean.toString());
                } catch (e) {}
            })
            .catch(function () { body.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:24px;color:#dc2626;">No se pudo cargar el kardex.</td></tr>'; })
            .finally(function () { body.style.opacity = '1'; unpre(); });
    };

    window.mvLimpiar = function () {
        el('mvAlmacen').value = ''; el('mvProducto').value = ''; el('mvTipo').value = 'all';
        el('mvFrente').value = ''; el('mvDesde').value = ''; el('mvHasta').value = '';
        window.mvCargar();
    };

    document.addEventListener('click', function (e) {
        var a = e.target.closest('#mvPagination a');
        if (a) { e.preventDefault(); e.stopPropagation(); window.mvCargar(a.href); }
    }, true);

    // Pre-cargar filtros desde la URL (deep links) y disparar la primera carga.
    try {
        var qp = new URLSearchParams(window.location.search);
        if (qp.get('id_almacen'))  el('mvAlmacen').value  = qp.get('id_almacen');
        if (qp.get('id_producto')) el('mvProducto').value = qp.get('id_producto');
        if (qp.get('tipo'))        el('mvTipo').value     = qp.get('tipo');
        if (qp.get('id_frente'))   el('mvFrente').value   = qp.get('id_frente');
        if (qp.get('desde'))       el('mvDesde').value    = qp.get('desde');
        if (qp.get('hasta'))       el('mvHasta').value    = qp.get('hasta');
    } catch (e) {}
    window.mvCargar();
})();
</script>
@endsection
