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
                 lo que llega + ver el historial + registrar entradas directas (compras). --}}
            @can('almacen.movimiento')
            <button type="button" class="btn-primary-maquinaria" style="height:45px;padding:0 16px;display:flex;align-items:center;gap:8px;"
                    onclick="window.entAbrirModal()">
                <i class="material-icons" style="font-size:18px;">add_box</i><span>Registrar entrada directa</span>
            </button>
            @endcan
            <a href="{{ route('almacen.movimientos', ['tipo' => 'ENTRADA']) }}" class="btn-primary-maquinaria" style="height:45px;padding:0 16px;display:flex;align-items:center;gap:8px;text-decoration:none;background:#fff;color:#0067b1;border:1px solid #0067b1;box-shadow:none;" title="Ver TODAS las entradas en la bitácora">
                <i class="material-icons" style="font-size:18px;">receipt_long</i><span class="desktop-text">Bitácora</span>
            </a>
            <a href="{{ route('almacen.index') }}" class="btn-primary-maquinaria" style="height:45px;padding:0 16px;display:flex;align-items:center;gap:8px;text-decoration:none;background:#e2e8f0;color:#475569;box-shadow:none;">
                <i class="material-icons" style="font-size:18px;">arrow_back</i><span class="desktop-text">Inventario</span>
            </a>
        </div>
    </div>
</section>

<style>
    #trFilters { display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin-bottom:10px; }
    #trFilters .tr-item { flex:1 1 220px; min-width:180px; max-width:300px; }
    #trFilters .tr-search { flex:2 1 280px; max-width:none; }
    .tr-search-box { display:flex; align-items:center; height:45px; border:1px solid #cbd5e0; border-radius:12px; background:#fbfcfd; overflow:hidden; }
    .tr-search-box.active { border-color:#0067b1; background:#e1effa; }
    .tr-search-box i.lupa { padding:0 10px; color:#64748b; font-size:18px; }
    .tr-search-box input { flex:1; border:none; background:transparent; outline:none; padding:10px 5px; font-size:14px; min-width:0; }
    /* Tabla con el mismo estilo que /admin/equipos y /admin/almacen (.table-row-header style) */
    .tr-table { width:100%; border-collapse:separate; border-spacing:0; font-size:14px; color:#000; }
    .tr-table thead tr { background:#1e293b; }
    .tr-table thead th { text-align:left; color:#fff; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:1px; padding:10px 15px; border-right:1px solid #334155; border-bottom:2px solid #0f172a; white-space:nowrap; }
    .tr-table thead th:last-child { border-right:none; }
    .tr-table tbody td { padding:12px 15px; color:#000; border-bottom:1px solid #e2e8f0; border-right:1px solid #e2e8f0; }
    .tr-table tbody td:last-child { border-right:none; }
    .tr-table tbody tr:hover td { background:#e0f2fe; cursor:pointer; }
    .estado-pill { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:999px; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.3px; }
</style>

<div class="admin-card" style="margin:0;min-height:70vh;padding:14px;">

    {{-- Encabezado de la bandeja: el módulo Recepción ahora SOLO muestra "Por recibir"
         (envíos en tránsito esperando confirmación del destino). El historial completo
         de traspasos ya recibidos/cancelados se ve en "Historial de Movimientos" del nav. --}}
    <div style="display:flex;align-items:center;gap:10px;padding:4px 0 10px 0;border-bottom:2px solid #e2e8f0;margin-bottom:14px;">
        <i class="material-icons" style="font-size:22px;color:#0067b1;">inbox</i>
        <h2 style="margin:0;font-size:15px;font-weight:800;color:#0f172a;letter-spacing:.2px;">Por recibir</h2>
        @if(($contPorRecibir ?? 0) > 0)
            <span style="background:#ef4444;color:#fff;border-radius:999px;padding:2px 9px;font-size:11px;font-weight:800;min-width:20px;text-align:center;">{{ $contPorRecibir }}</span>
        @endif
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

    function el(id) { return document.getElementById(id); }
    function v(id) { var e = el(id); return e ? String(e.value).trim() : ''; }

    function params(pageUrl) {
        // El backend filtra siempre a "por recibir" (ENVIADO en almacenes visibles).
        // Aquí solo mandamos los filtros del UI (search/estado/origen/destino/fechas).
        var p = new URLSearchParams();
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

{{-- ────────────────────────────────────────────────────────────────
     Modal "Registrar entrada directa"
     Para entradas que NO vienen de otro almacén (compras, devoluciones,
     conteo inicial, etc.). Solo permiso almacen.movimiento.
     POSTea a /almacen/movimientos-lote con tipo=ENTRADA (reusa el endpoint
     existente — no hay nuevo backend). REFERENCIA = Nº OC, MOTIVO = proveedor.
     ──────────────────────────────────────────────────────────────── --}}
@can('almacen.movimiento')
<div id="entModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:200;align-items:flex-start;justify-content:center;padding:30px 16px;overflow-y:auto;">
    <div style="background:#fff;border-radius:12px;width:100%;max-width:780px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.35);">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #e2e8f0;">
            <h3 style="margin:0;font-size:16px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:8px;">
                <i class="material-icons" style="color:#0284c7;">add_box</i> Registrar entrada directa
            </h3>
            <button type="button" onclick="window.entCerrarModal()" style="background:none;border:none;cursor:pointer;color:#64748b;padding:4px;"><i class="material-icons">close</i></button>
        </div>

        <div style="padding:18px 20px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                <div>
                    <label style="display:block;font-size:11.5px;font-weight:700;color:#64748b;margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px;">Almacén destino *</label>
                    <select id="entAlmacen" style="width:100%;height:40px;border:1px solid #cbd5e0;border-radius:8px;padding:0 10px;font-size:14px;background:#fff;outline:none;">
                        @foreach($almacenes as $a)
                            <option value="{{ $a->ID_ALMACEN }}">{{ $a->NOMBRE }} ({{ $a->TIPO === 'GENERAL' ? 'Principal' : 'Proyecto' }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:11.5px;font-weight:700;color:#64748b;margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px;">Nº Orden de Compra <span style="font-weight:400;text-transform:none;color:#94a3b8;">(opcional)</span></label>
                    <input type="text" id="entRef" maxlength="100" placeholder="Ej: OC-2026-0142" style="width:100%;height:40px;border:1px solid #cbd5e0;border-radius:8px;padding:0 10px;font-size:14px;background:#fff;outline:none;">
                </div>
                <div>
                    <label style="display:block;font-size:11.5px;font-weight:700;color:#64748b;margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px;">Proveedor <span style="font-weight:400;text-transform:none;color:#94a3b8;">(opcional)</span></label>
                    <input type="text" id="entProveedor" maxlength="200" placeholder="Ej: Ferretería La Roca, C.A." style="width:100%;height:40px;border:1px solid #cbd5e0;border-radius:8px;padding:0 10px;font-size:14px;background:#fff;outline:none;">
                </div>
                <div>
                    <label style="display:block;font-size:11.5px;font-weight:700;color:#64748b;margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px;">Fecha <span style="font-weight:400;text-transform:none;color:#94a3b8;">(opcional, default hoy)</span></label>
                    <input type="date" id="entFecha" style="width:100%;height:40px;border:1px solid #cbd5e0;border-radius:8px;padding:0 10px;font-size:14px;background:#fff;outline:none;">
                </div>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <h4 style="margin:0;font-size:13px;font-weight:800;color:#334155;text-transform:uppercase;letter-spacing:.3px;">Productos que entran</h4>
                <button type="button" style="background:none;border:1px dashed #0284c7;color:#0284c7;border-radius:8px;padding:5px 12px;font-size:12.5px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:5px;" onclick="window.entAddLinea()">
                    <i class="material-icons" style="font-size:15px;">add</i> Agregar producto
                </button>
            </div>
            <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin-bottom:12px;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead><tr style="background:#f8fafc;">
                        <th style="text-align:left;padding:7px 10px;color:#64748b;font-size:11px;font-weight:800;text-transform:uppercase;">Producto</th>
                        <th style="text-align:right;padding:7px 10px;color:#64748b;font-size:11px;font-weight:800;text-transform:uppercase;width:130px;">Cantidad</th>
                        <th style="width:42px;"></th>
                    </tr></thead>
                    <tbody id="entLineasTbody"></tbody>
                </table>
            </div>

            <label style="display:block;font-size:11.5px;font-weight:700;color:#64748b;margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px;">Notas <span style="font-weight:400;text-transform:none;color:#94a3b8;">(opcional)</span></label>
            <textarea id="entNotas" rows="2" style="width:100%;border:1px solid #cbd5e0;border-radius:8px;padding:8px 10px;font-size:14px;background:#fff;outline:none;resize:vertical;" placeholder="Detalles de la recepción…"></textarea>

            <div id="entError" style="display:none;margin-top:10px;padding:9px 12px;background:#fee2e2;border:1px solid #fecaca;border-radius:8px;color:#b91c1c;font-size:13px;font-weight:600;"></div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:8px;padding:14px 20px;border-top:1px solid #e2e8f0;background:#f8fafc;border-bottom-left-radius:12px;border-bottom-right-radius:12px;">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;height:40px;padding:0 16px;" onclick="window.entCerrarModal()">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" style="height:40px;padding:0 18px;display:flex;align-items:center;gap:6px;" onclick="window.entGuardar()">
                <i class="material-icons" style="font-size:18px;">save</i> Registrar entrada
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    var ROUTE_ENTRADA = @json(route('almacen.movimientos.lote'));
    var PRODUCTOS     = @json($productosLista ?? []);

    function el(id) { return document.getElementById(id); }
    function v(id)  { var e = el(id); return e ? String(e.value).trim() : ''; }
    function csrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; }
    function toast(m, t) { if (window.toast) window.toast(m, t); else alert(m); }
    function showErr(msg) { var e = el('entError'); if (!e) return; if (msg) { e.style.display = 'block'; e.textContent = msg; } else { e.style.display = 'none'; e.textContent = ''; } }

    var nextRid = 0;
    function lineaHtml(rid) {
        var opts = '<option value="">— elige producto —</option>';
        for (var i = 0; i < PRODUCTOS.length; i++) {
            var p = PRODUCTOS[i];
            opts += '<option value="' + p.ID_PRODUCTO + '">' + p.CODIGO + ' — ' + String(p.NOMBRE).replace(/</g, '&lt;') + ' (' + p.UM + ')</option>';
        }
        return '<tr data-rid="' + rid + '">' +
            '<td style="padding:6px 10px;"><select class="ent-prod" style="width:100%;height:34px;border:1px solid #cbd5e0;border-radius:6px;padding:0 8px;font-size:13px;outline:none;background:#fff;">' + opts + '</select></td>' +
            '<td style="padding:6px 10px;text-align:right;"><input type="number" min="0.001" step="0.001" class="ent-cant" placeholder="0" style="width:100%;height:34px;border:1px solid #cbd5e0;border-radius:6px;padding:0 8px;font-size:13px;outline:none;text-align:right;background:#fff;"></td>' +
            '<td style="padding:6px 10px;text-align:center;"><button type="button" onclick="window.entDelLinea(' + rid + ')" style="background:none;border:none;cursor:pointer;color:#dc2626;padding:4px;"><i class="material-icons">delete</i></button></td>' +
            '</tr>';
    }

    window.entAddLinea = function () {
        var tb = el('entLineasTbody'); if (!tb) return;
        tb.insertAdjacentHTML('beforeend', lineaHtml(++nextRid));
    };
    window.entDelLinea = function (rid) {
        var row = document.querySelector('#entLineasTbody tr[data-rid="' + rid + '"]');
        if (row) row.remove();
        if (el('entLineasTbody') && el('entLineasTbody').children.length === 0) window.entAddLinea();
    };

    window.entAbrirModal = function () {
        el('entLineasTbody').innerHTML = '';
        nextRid = 0;
        window.entAddLinea(); // arranca con 1 línea vacía
        ['entRef','entProveedor','entNotas'].forEach(function (id) { var e = el(id); if (e) e.value = ''; });
        var f = el('entFecha'); if (f) f.value = new Date().toISOString().slice(0, 10);
        showErr('');
        el('entModal').style.display = 'flex';
    };
    window.entCerrarModal = function () { el('entModal').style.display = 'none'; };

    // Cerrar al hacer clic en el backdrop
    document.addEventListener('click', function (e) {
        if (e.target && e.target.id === 'entModal') window.entCerrarModal();
    });

    window.entGuardar = function () {
        var idAlm = v('entAlmacen');
        if (!idAlm) { showErr('Elige un almacén destino.'); return; }

        var lineas = [], faltan = [];
        document.querySelectorAll('#entLineasTbody tr').forEach(function (tr) {
            var idProd = tr.querySelector('.ent-prod').value;
            var cant   = parseFloat(tr.querySelector('.ent-cant').value);
            if (!idProd) return; // línea vacía: ignorar
            if (!isFinite(cant) || cant <= 0) { faltan.push(tr.querySelector('.ent-prod').selectedOptions[0]?.text || '?'); return; }
            lineas.push({ id_producto: parseInt(idProd, 10), cantidad: cant });
        });
        if (faltan.length) { showErr('Cantidad inválida en: ' + faltan.slice(0, 3).join(', ') + (faltan.length > 3 ? '…' : '')); return; }
        if (lineas.length === 0) { showErr('Agrega al menos un producto con cantidad > 0.'); return; }
        showErr('');

        var payload = {
            tipo:       'ENTRADA',
            id_almacen: parseInt(idAlm, 10),
            fecha:      v('entFecha') || null,
            referencia: v('entRef') || null,         // Nº OC
            motivo:     v('entProveedor') || null,   // Proveedor (texto libre por ahora)
            notas:      v('entNotas') || null,
            lineas:     lineas,
        };

        if (window.showPreloader) window.showPreloader();
        fetch(ROUTE_ENTRADA, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload),
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            if (window.hidePreloader) window.hidePreloader();
            if (res.ok) {
                window.entCerrarModal();
                toast(res.b.message || 'Entrada registrada.', 'success');
                // No recargamos la lista porque las entradas directas NO aparecen aquí (este módulo es para
                // traspasos). Se ven en /admin/almacen/movimientos?tipo=ENTRADA.
            } else {
                showErr((res.b && res.b.message) || 'No se pudo registrar la entrada.');
            }
        })
        .catch(function () { if (window.hidePreloader) window.hidePreloader(); showErr('Error de red.'); });
    };
})();
</script>
@endcan

@endsection
