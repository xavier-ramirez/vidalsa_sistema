@extends('layouts.estructura_base')

@section('title', 'Alertas de stock')

@section('content')
<style>
    /* ── Alertas de stock — página standalone (mismo listado que el modal) ── */
    #alFilters { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:4px 0 14px; }
    .al-box {
        display:flex; align-items:center; background:#fbfcfd; border:1px solid #cbd5e0;
        border-radius:12px; height:44px; overflow:hidden; padding:0 4px;
    }
    .al-box.active { background:#e1effa; border-color:var(--maquinaria-blue,#0067b1); }
    .al-box .al-ic { padding:0 8px; display:flex; align-items:center; color:#64748b; }
    .al-box select { flex:1; border:none; background:transparent; outline:none; font-size:13.5px; color:#1e293b; padding:9px 6px; min-width:0; height:100%; cursor:pointer; -webkit-appearance:none; appearance:none; }

    .al-table { width:100%; border-collapse:collapse; font-size:12.5px; }
    .al-table thead th {
        text-align:left; color:#64748b; font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:0.4px;
        padding:9px 10px; border-bottom:2px solid #e2e8f0; background:#f8fafc; position:sticky; top:0; z-index:2;
    }
    .al-table tbody td { padding:7px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
</style>

<section class="page-title-card" style="text-align:left;margin:0 0 10px 0;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color:#000;">Alertas de stock</span>
    </h1>
</section>

<div class="admin-card" style="margin:0;min-height:80vh;padding:14px;">

    <div id="alFilters">
        <div style="flex:1.2 1 240px;">
            <div class="al-box" id="alBoxAlmacen">
                <span class="al-ic"><i class="material-icons" style="font-size:18px;">warehouse</i></span>
                <select id="alAlmacen" onchange="alCargar()">
                    <option value="">Todos mis almacenes</option>
                    @foreach($almacenes as $a)
                        <option value="{{ $a->ID_ALMACEN }}">{{ $a->NOMBRE }} {{ $a->TIPO === 'GENERAL' ? '(Principal)' : '(Proyecto)' }}</option>
                    @endforeach
                </select>
                <span class="al-ic"><i class="material-icons" style="font-size:18px;color:#94a3b8;">expand_more</i></span>
            </div>
        </div>

        <div style="display:flex;gap:8px;margin-left:auto;flex:0 0 auto;align-items:center;">
            <span id="alTotal" style="font-size:12.5px;color:#64748b;white-space:nowrap;"></span>
            <a href="{{ route('almacen.index') }}" class="btn-primary-maquinaria" style="height:44px;padding:0 14px;display:flex;align-items:center;gap:6px;text-decoration:none;">
                <i class="material-icons" style="font-size:18px;">inventory_2</i><span class="desktop-text">Ir al inventario</span>
            </a>
        </div>
    </div>

    <div style="overflow:auto;border:1px solid #e2e8f0;border-radius:12px;max-height:74vh;">
        <table class="al-table">
            <thead><tr>
                <th>Almacén</th><th>Código</th><th>Producto</th><th>Categoría</th>
                <th style="text-align:right;">Saldo</th><th style="text-align:right;">Mínimo</th><th style="text-align:right;">Falta</th>
            </tr></thead>
            <tbody id="alBody">
                <tr><td colspan="7" style="text-align:center;padding:30px;color:#94a3b8;">Cargando…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    'use strict';
    if (window.__almAlertasPageInit) return;
    window.__almAlertasPageInit = true;

    var ROUTE_ALERTAS = @json(route('almacen.alertasStockBajo'));
    function el(id){ return document.getElementById(id); }
    function val(id){ var e = el(id); return e ? String(e.value).trim() : ''; }
    function pre()  { if (typeof window.showPreloader === 'function') window.showPreloader(); }
    function unpre(){ if (typeof window.hidePreloader === 'function') window.hidePreloader(); }

    window.alCargar = function () {
        var body = el('alBody'); if (!body) return;
        var p = new URLSearchParams();
        var a = val('alAlmacen'); if (a) p.set('id_almacen', a);
        var box = el('alBoxAlmacen'); if (box) box.classList.toggle('active', !!a);
        body.style.opacity = '0.5'; pre();
        fetch(ROUTE_ALERTAS + '?' + p.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.html !== undefined) body.innerHTML = data.html;
                var tot = el('alTotal'); if (tot) tot.textContent = (data.total != null ? (data.total + ' alerta(s)') : '');
                try {
                    var clean = new URL(window.location.pathname, window.location.origin);
                    if (a) clean.searchParams.set('id_almacen', a);
                    window.history.replaceState({}, '', clean.toString());
                } catch (e) {}
            })
            .catch(function () { body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:#dc2626;">No se pudieron cargar las alertas.</td></tr>'; })
            .finally(function () { body.style.opacity = '1'; unpre(); });
    };

    try { var qp = new URLSearchParams(window.location.search); if (qp.get('id_almacen')) el('alAlmacen').value = qp.get('id_almacen'); } catch (e) {}
    window.alCargar();
})();
</script>
@endsection
