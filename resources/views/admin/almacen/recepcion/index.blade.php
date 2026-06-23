@extends('layouts.estructura_base')

@section('title', 'Recepción de materiales')

@section('content')
@php
    // Estado activo del filtro. Por defecto (sin parámetro) la bandeja muestra solo
    // "En tránsito" (ENVIADO) = pendientes de confirmar. Debe coincidir con el default
    // del TraspasoController@index para que el <select> refleje lo que realmente se ve.
    $reqEstado     = request('estado', \App\Models\Traspaso::ESTADO_ENVIADO);
    // `idAlmacenDestinoActivo` lo provee el controller incluyendo el default-merge por frente
    // del usuario. Es la fuente de verdad — no usamos request('id_almacen_destino') porque
    // el merge del controller no siempre llega al helper global al renderizar el Blade.
    $reqDestino    = $idAlmacenDestinoActivo ?? null;
    $reqSearch     = request('search');           // por NUMERO de nota de entrega

    $reqDesde      = request('desde');
    $reqHasta      = request('hasta');

    // Metadata visual de los estados — definida en \App\Models\Traspaso::ESTADOS_META.
    $badgesEstado = \App\Models\Traspaso::ESTADOS_META;
@endphp

@php
    // Selector de "Almacén destino" prominente en el header (mismo patrón que el de
    // Almacén en /admin/almacen/movimientos). Cada almacén tiene SU propia bandeja de
    // recepción; el usuario LOCAL con un único almacén destino visible no necesita
    // este selector pero igual lo dejamos para coherencia (queda preseleccionado).
    $destSel = $reqDestino
        ? ($almacenes ?? collect())->firstWhere('ID_ALMACEN', (int) $reqDestino)
        : null;
@endphp

<section class="page-title-card" style="text-align:left;margin:0 0 10px 0;">
    {{-- Fila 1: Título + selector de almacén --}}
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
        <div style="flex:0 0 auto;">
            <h1 class="page-title" style="margin:0;">
                <span class="page-title-line2" style="color:#000;">Recepción de materiales</span>
            </h1>
        </div>
        <span aria-hidden="true" style="display:inline-block;width:1px;height:34px;background:#cbd5e0;flex:0 0 auto;"></span>
        <div style="flex:1 1 260px;max-width:360px;">
            <div class="custom-dropdown" id="trDestHeaderDropdown" data-filter-type="id_almacen_destino" data-default-label="Todos">
                <input type="hidden" name="id_almacen_destino" data-filter-value value="{{ $destSel ? $destSel->ID_ALMACEN : '' }}">
                <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:#f8fafc;overflow:hidden;border:1px solid #cbd5e0;border-radius:10px;height:40px;">
                    <span style="padding:0 10px;display:flex;align-items:center;color:#0067b1;"><i class="material-icons" style="font-size:18px;transform:none !important;">warehouse</i></span>
                    <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                           placeholder="{{ $destSel ? $destSel->NOMBRE : 'Todos los almacenes' }}"
                           style="flex:1;border:none;background:transparent;padding:8px 5px;font-size:13.5px;font-weight:600;color:#0f172a;outline:none;min-width:0;"
                           oninput="window.filterDropdownOptions(this)">
                    <i class="material-icons" data-clear-btn style="padding:0 8px;color:#64748b;font-size:18px;display:{{ $destSel ? 'block' : 'none' }};cursor:pointer;transform:none !important;"
                       onclick="event.stopPropagation(); selectOption('trDestHeaderDropdown','all','TODOS LOS ALMACENES DESTINO');">close</i>
                </div>
                <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                    <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                        <div class="dropdown-item {{ !$destSel ? 'selected' : '' }}" data-value="all" onclick="selectOption('trDestHeaderDropdown','all','TODOS LOS ALMACENES DESTINO');">TODOS LOS ALMACENES</div>
                        @foreach(($almacenes ?? collect()) as $a)
                            <div class="dropdown-item {{ $destSel && $destSel->ID_ALMACEN == $a->ID_ALMACEN ? 'selected' : '' }}" data-value="{{ $a->ID_ALMACEN }}"
                                 onclick="selectOption('trDestHeaderDropdown','{{ $a->ID_ALMACEN }}','{{ addslashes($a->NOMBRE) }}');">
                                {{ $a->NOMBRE }}@if($a->TIPO !== 'GENERAL') <span class="alm-tipo-p">P</span>@endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
    {{-- Fila 2: Tabs de navegación --}}
    <div style="display:flex;gap:0;margin-top:12px;border-bottom:2px solid #e2e8f0;">
        <a href="{{ route('almacen.recepcion.index', ['force' => 1]) }}"
           style="display:flex;align-items:center;gap:6px;padding:8px 20px;font-size:13px;font-weight:700;color:#0067b1;border-bottom:2px solid #0067b1;margin-bottom:-2px;text-decoration:none;transition:all .15s;">
            <i class="material-icons" style="font-size:16px;">inbox</i> Bandeja de entrada
        </a>
        @can('almacen.movimiento')
        <a href="{{ route('almacen.recepcion.nueva') }}"
           style="display:flex;align-items:center;gap:6px;padding:8px 20px;font-size:13px;font-weight:600;color:#64748b;text-decoration:none;transition:all .15s;"
           onmouseenter="this.style.color='#0067b1'" onmouseleave="this.style.color='#64748b'">
            <i class="material-icons" style="font-size:16px;">add_circle_outline</i> Entrada directa (ODC)
        </a>
        @endcan
    </div>
</section>

<style>
    /* ── Bandeja de recepción — estilo WMS profesional ── */

    /* Toolbar de filtros */
    #trFilters {
        display:flex; gap:10px; flex-wrap:wrap; align-items:center;
        padding:10px 14px; margin-bottom:12px;
        background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px;
    }
    #trFilters .tr-item { flex:1 1 220px; min-width:180px; max-width:300px; }
    #trFilters .tr-search-num  { flex:1 1 280px; max-width:400px; min-width:200px; position:relative; }
    .tr-search-box { display:flex; align-items:center; height:40px; border:1px solid #cbd5e0; border-radius:8px; background:#fff; overflow:hidden; }
    .tr-search-box.active { border-color:#0067b1; background:#e1effa; }
    .tr-search-box i.lupa { padding:0 10px; color:#64748b; font-size:18px; }
    .tr-search-box input { flex:1; border:none; background:transparent; outline:none; padding:8px 5px; font-size:13px; min-width:0; color:#0f172a; }
    /* Filtros en línea (Estado / Desde / Hasta) — misma altura (40px) que el buscador. */
    #trFilters .tr-filter-estado { flex:0 1 180px; min-width:150px; max-width:220px; }
    #trFilters .tr-filter-fecha  { flex:0 1 170px; min-width:140px; max-width:200px; }
    .tr-filter-input { width:100%; height:40px; border:1px solid #cbd5e0; border-radius:8px; padding:0 10px; font-size:13px; color:#0f172a; outline:none; box-sizing:border-box; }
    .tr-filter-input:focus { border-color:#0067b1; }
    .tr-date-box { display:flex; align-items:center; gap:5px; height:40px; border:1px solid #cbd5e0; border-radius:8px; padding:0 10px; cursor:pointer; box-sizing:border-box; }
    .tr-date-box i { font-size:16px; color:#94a3b8; pointer-events:none; }
    .tr-date-box .tr-date-label { font-size:12px; font-weight:600; color:#64748b; pointer-events:none; white-space:nowrap; }
    .tr-date-box input[type=date] { flex:1; min-width:0; border:none; background:transparent; padding:0; font-size:12px; outline:none; color:#0f172a; cursor:pointer; }

    /* Dropdown de sugerencias */
    .tr-suggest {
        position:absolute; top:calc(100% + 4px); left:0; right:0;
        background:#fff; border:1px solid #e2e8f0; border-radius:10px;
        box-shadow:0 8px 18px rgba(15,23,42,0.10);
        max-height:260px; overflow-y:auto; padding:4px;
        z-index:60; display:none;
        scrollbar-width:thin; scrollbar-color:#cbd5e1 transparent;
    }
    .tr-suggest::-webkit-scrollbar { width:5px; }
    .tr-suggest::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:999px; }
    .tr-suggest::-webkit-scrollbar-track { background:transparent; }
    .tr-suggest.open { display:block; }
    .tr-suggest-item {
        padding:8px 12px; border-radius:6px; cursor:pointer;
        font-family:monospace; font-size:12.5px; font-weight:700; color:#0f172a;
        letter-spacing:0.3px; transition:background .15s;
    }
    .tr-suggest-item:hover, .tr-suggest-item.active { background:#e1effa; color:#0067b1; }
    .tr-suggest-empty { padding:10px 12px; font-size:12px; color:#94a3b8; font-style:italic; }

    /* ── Layout: la tabla (.admin-card) y el panel de resumen, cada uno en SU PROPIO
         contenedor, lado a lado. ── */
    .tr-layout { display:flex; gap:14px; align-items:flex-start; }
    /* Panel estilo "Consolidado de Inventario" (/admin/almacen): tarjeta con gradiente
       azul y texto blanco, un número héroe (Por revisar) + dos sub-métricas
       (Recientes / Urgentes) en cajas semitransparentes de color. */
    .tr-stats { flex:0 0 300px; position:relative; overflow:hidden; align-self:flex-start;
        background:linear-gradient(135deg,#1a365d 0%,#2c5282 100%); border-radius:12px;
        padding:15px; color:#fff; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); }
    .tr-stats-bgicon { position:absolute; right:-15px; bottom:-15px; font-size:80px; opacity:0.1; transform:rotate(-15deg); }
    .tr-stats-title { display:flex; align-items:center; gap:6px; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:1.5px; opacity:0.8; margin-bottom:10px; }
    .tr-stats-title i { font-size:14px; }
    .tr-stats-row { display:flex; align-items:center; gap:8px; }
    .tr-stats-hero { display:flex; flex-direction:column; align-items:center; background:rgba(255,255,255,0.15); padding:8px 6px; border-radius:10px; min-width:72px; }
    .tr-stats-hero-num { font-size:34px; font-weight:800; line-height:1; }
    .tr-stats-hero-lbl { font-size:11px; opacity:0.85; font-weight:700; margin-top:2px; text-transform:uppercase; letter-spacing:.3px; }
    .tr-stats-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:4px; flex:1; }
    /* Icono AL LADO del número (en fila); el label cae a su propia línea debajo
       (flex:1 1 100% lo fuerza al siguiente renglón). */
    .tr-stats-sub { display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:3px 6px; padding:8px 4px; border-radius:8px; text-align:center; }
    .tr-stats-sub i { font-size:18px; }
    .tr-stats-sub strong { font-weight:800; font-size:18px; color:#fff; }
    .tr-stats-sub span { flex:1 1 100%; font-size:10px; opacity:0.9; font-weight:700; text-transform:uppercase; line-height:1.1; }
    .tr-sub-rec { background:rgba(34,197,94,0.15); border:1px solid rgba(34,197,94,0.25); }
    .tr-sub-urg { background:rgba(245,158,11,0.18); border:1px solid rgba(245,158,11,0.3); }
    /* Mobile: el panel (tarjeta con gradiente) sube ARRIBA de la tabla, full-width. */
    @media (max-width: 768px) {
        .tr-layout { flex-direction:column; }
        .tr-stats { order:-1; flex:0 0 auto; width:100%; box-sizing:border-box; }
    }

    /* Tabla */
    .tr-table { width:100%; border-collapse:separate; border-spacing:0; font-size:14px; color:#000; }
    .tr-table thead tr { background:#1e293b; }
    .tr-table thead th { text-align:left; color:#fff; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; padding:10px 14px; border-right:1px solid #334155; border-bottom:2px solid #0f172a; white-space:nowrap; }
    .tr-table thead th:last-child { border-right:none; }
    .tr-table tbody td { padding:11px 14px; color:#000; border-bottom:1px solid #e2e8f0; border-right:1px solid #e2e8f0; }
    .tr-table tbody td:last-child { border-right:none; }
    .tr-table tbody tr:hover td { background:#e0f2fe; cursor:pointer; }
    .tr-table tbody tr:nth-child(even) td { background:#fafbfc; }
    .tr-table tbody tr:nth-child(even):hover td { background:#e0f2fe; }
    .estado-pill { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:999px; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.3px; }
    .pill-linea { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.2px; }

    /* ── Modal detalle/recepción ── */
    .dtm-overlay {
        display:none; position:fixed; inset:0; z-index:99999;
        background:rgba(0,0,0,0.55); backdrop-filter:blur(3px);
        align-items:center; justify-content:center; padding:10px;
    }
    .dtm-overlay.open { display:flex; }
    .dtm-box {
        background:#fff; border-radius:16px; width:100%; max-width:700px;
        max-height:90vh; display:flex; flex-direction:column; overflow:hidden;
        box-shadow:0 25px 50px -12px rgba(0,0,0,0.35);
        animation: dtmIn .2s ease-out;
    }
    @keyframes dtmIn { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
    .dtm-header { padding:16px 20px 12px; border-bottom:1px solid #e2e8f0; flex-shrink:0; }
    .dtm-title-row { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
    .dtm-numero { font-family:monospace; font-size:17px; font-weight:800; color:#0f172a; }
    .dtm-close {
        margin-left:auto; background:transparent; border:none; cursor:pointer;
        color:#64748b; padding:4px; border-radius:6px; transition:background .15s;
    }
    .dtm-close:hover { background:#f1f5f9; color:#0f172a; }
    .dtm-meta { display:flex; flex-wrap:wrap; gap:0; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; }
    .dtm-meta-item { flex:1 1 140px; padding:6px 10px; border-right:1px solid #e2e8f0; }
    .dtm-meta-item:last-child { border-right:none; }
    .dtm-meta-label { display:block; font-size:9.5px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.4px; }
    .dtm-meta-value { font-size:12.5px; font-weight:600; color:#1e293b; }
    .dtm-sub { font-size:11px; color:#94a3b8; font-weight:400; }

    .dtm-body { flex:1; overflow-y:auto; padding:14px 20px; }
    .dtm-notas { display:flex; align-items:flex-start; gap:6px; padding:8px 10px; background:#fffbeb; border:1px solid #fef3c7; border-radius:8px; font-size:12.5px; color:#92400e; margin-bottom:10px; }
    .dtm-banner { display:flex; align-items:center; gap:8px; padding:8px 12px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; font-size:12.5px; font-weight:600; color:#1e40af; margin-bottom:10px; }
    .dtm-banner i { font-size:18px; }
    .dtm-lineas-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
    .dtm-lineas-header span:first-child { font-size:12px; font-weight:700; color:#334155; text-transform:uppercase; letter-spacing:.5px; }
    .dtm-lineas-count { font-size:11px; font-weight:800; color:#0067b1; background:#e1effa; padding:2px 8px; border-radius:999px; }
    /* Materiales = TABLA real (<table>): encabezado + filas con columnas alineadas y
       valores centrados — se ve como una tabla, consistente con el modal. */
    .dtm-table-wrap { border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; }
    .dtm-table { width:100%; border-collapse:collapse; font-size:13px; }
    .dtm-table thead th {
        text-align:center; font-size:9px; font-weight:700; color:#94a3b8;
        text-transform:uppercase; letter-spacing:.3px; padding:7px 8px;
        background:#f8fafc; border-bottom:1px solid #e2e8f0; white-space:nowrap;
    }
    .dtm-table thead th:first-child { text-align:left; }
    .dtm-table tbody td {
        padding:6px 8px; border-bottom:1px solid #eef2f6;
        text-align:center; vertical-align:middle; border-right:1px solid #f1f5f9;
    }
    .dtm-table tbody td:last-child { border-right:none; }
    .dtm-table tbody tr:last-child td { border-bottom:none; }
    .dtm-table tbody tr:hover td { background:#f8fafc; }
    .dtm-td-prod { text-align:left !important; min-width:0; }
    .dtm-td-prod .dtm-linea-cod, .dtm-td-prod .dtm-linea-nom, .dtm-td-prod .dtm-linea-um { display:inline; }
    .dtm-linea-cod { font-family:monospace; font-weight:800; font-size:11.5px; color:#0f172a; }
    .dtm-linea-nom { font-size:12.5px; font-weight:600; color:#334155; margin-left:4px; }
    .dtm-linea-um { font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; margin-left:4px; }
    .dtm-col-num { font-family:monospace; font-weight:700; font-size:13.5px; color:#0f172a; white-space:nowrap; }
    .dtm-rec-input {
        width:72px; max-width:100%; height:30px; border:1px solid #93c5fd; border-radius:6px;
        padding:0 4px; font-size:13px; font-weight:700; background:#eff6ff;
        outline:none; color:#1e3a5f; text-align:center; font-family:monospace;
    }
    .dtm-rec-input:focus { border-color:#3b82f6; background:#fff; box-shadow:0 0 0 2px rgba(59,130,246,0.15); }
    .dtm-diff-value { font-size:13px; font-weight:700; font-family:monospace; color:#64748b; }
    .dtm-rec-danado { width:17px; height:17px; margin:0; accent-color:#b45309; cursor:pointer; }

    .dtm-footer {
        display:flex; align-items:center; gap:8px; flex-wrap:wrap;
        padding:12px 20px; border-top:1px solid #e2e8f0; flex-shrink:0;
        background:#f8fafc;
    }
    .dt-btn {
        height:40px; padding:0 16px; border-radius:10px; cursor:pointer;
        font-size:13px; font-weight:700; letter-spacing:.2px;
        display:inline-flex; align-items:center; gap:5px;
        transition:background .15s, transform .1s;
    }
    .dt-btn i { font-size:17px; }
    .dt-btn-cancel { background:#fff; color:#dc2626; border:1px solid #fca5a5; }
    .dt-btn-cancel:hover { background:#fee2e2; border-color:#dc2626; }
    .dt-btn-confirm-all { background:#065f46; color:#fff; border:none; }
    .dt-btn-confirm-all:hover { background:#064e3b; }
    .dt-btn-primary { background:#16a34a; color:#fff; border:none; box-shadow:0 4px 8px -2px rgba(22,163,74,0.3); }
    .dt-btn-primary:hover { background:#15803d; }
    .dt-btn-primary:active, .dt-btn-confirm-all:active { transform:scale(0.98); }
    .dt-btn-blue { background:var(--maquinaria-blue,#0067b1); color:#fff; border:none; box-shadow:0 4px 8px -2px rgba(0,103,177,0.3); }
    .dt-btn-blue:hover { background:#005391; }

    @media (max-width: 768px) {
        .dtm-overlay { padding:0; align-items:flex-end; }
        .dtm-box { max-width:100%; max-height:95vh; border-radius:16px 16px 0 0; }
        .dtm-meta-item { flex:1 1 45%; }
        .dtm-footer { flex-direction:column; }
        .dtm-footer .dt-btn { width:100%; justify-content:center; }
        /* Materiales (tabla): celdas y fuentes más compactas para el ancho del teléfono. */
        .dtm-table { font-size:12px; }
        .dtm-table thead th, .dtm-table tbody td { padding:5px 4px; }
        .dtm-col-num, .dtm-diff-value { font-size:12px; }
        .dtm-linea-nom { font-size:11.5px; }
        .dtm-rec-input { width:54px; height:28px; font-size:12px; }
    }

    /* ── Responsive mobile (≤768px) — patron calcado de /admin/almacen ──
       Titulo oculto, selector de almacen destino full-width como header
       efectivo, y los filtros en línea (buscador / Estado / Desde-Hasta)
       se reacomodan a lo ancho del telefono. */
    @media (max-width: 768px) {
        /* Viewport principal: por default .main-viewport tiene padding:20px y
           width:98% (estilos_globales.css:92). En /admin/almacen el global lo
           reduce a 8px porque tiene .page-layout-grid; aqui NO tenemos esa
           clase asi que replicamos el override para que el contenedor blanco
           ocupe casi todo el ancho del telefono.
           max-width:100% (no 100vw) — 100vw incluye el ancho de la scrollbar
           vertical y deja el padding-right tapado, generando margen izq > der. */
        .main-viewport { padding-left: 8px !important; padding-right: 8px !important; width: 100% !important; max-width: 100% !important; box-sizing: border-box !important; padding-top: 12px !important; }
        /* Contenedor blanco (.admin-card): padding interno chico y full-width.
           box-sizing:border-box ya viene de la clase global .admin-card. */
        .admin-card { padding: 4px !important; margin: 0 !important; width: 100% !important; }
        /* page-title-card: el global mobile le pone width:100% y menu.css le mete
           padding:8px 12px — SIN box-sizing:border-box el ancho real es 100%+24px
           y se desborda 24px a la derecha (clippeado), dejando el contenido
           corrido hacia la derecha. Forzamos border-box + padding lateral 4px
           para que su contenido alinee EXACTO con el de .admin-card (padding 4px). */
        .page-title-card { box-sizing: border-box !important; padding-left: 4px !important; padding-right: 4px !important; }
        /* Titulo + separador ocultos en mobile */
        .page-title-card .page-title { display: none !important; }
        .page-title-card > div > span[aria-hidden="true"] { display: none !important; }
        /* Cabecera apilada para que el selector de almacen destino ocupe todo el ancho */
        .page-title-card > div { flex-direction: column !important; align-items: stretch !important; gap: 10px !important; }
        .page-title-card > div > div { width: 100% !important; flex: 1 1 100% !important; }

        /* Filtros en mobile: buscador y Estado a fila completa; Desde/Hasta lado a
           lado; el botón Limpiar (40x40) cierra la fila de fechas. */
        #trFilters { gap: 8px !important; }
        #trFilters > .tr-search-num    { flex: 1 1 100% !important; max-width: none !important; min-width: 0 !important; }
        #trFilters > .tr-filter-estado { flex: 1 1 100% !important; max-width: none !important; }
        #trFilters > .tr-filter-fecha  { flex: 1 1 0 !important; min-width: 0 !important; }

        /* ══════════════════════════════════════════════
           MOBILE CARD LAYOUT — Recepción (bandeja)
           Cada <tr data-id="..."> pasa a ser una tarjeta con grid de 3 filas:
             ┌───────────────────────────────────┐
             │ TR-2026-0042         [ENVIADO]    │ ← Nº + Estado
             │ Almacén Origen                    │
             │   ↓                               │
             │ Almacén Destino                   │
             │ 📦 5 · 📅 18-May · 👤 Juan        │ ← Meta
             └───────────────────────────────────┘
           El acento izquierdo se hace con box-shadow:inset (no border-left:3px)
           para mantener simetria horizontal — mismo fix aplicado en /movimientos.
           "Fecha recibido" no se muestra en la tarjeta mobile por espacio (en el
           default "En tránsito" va vacia; si se filtra Confirmadas el dato se ve en
           el desktop). ══════════════════════════════════════════════ */
        .tr-table { display: block !important; min-width: 0 !important; background: transparent !important; border: none !important; }
        .tr-table thead { display: none !important; }
        .tr-table tbody { display: flex !important; flex-direction: column !important; gap: 10px !important; width: 100% !important; }

        /* Wrapper con overflow-x:auto deja de hacer falta en mobile — las cards son
           block y no requieren scroll horizontal. Anulamos su border/radius para que
           las cards no queden encerradas en un marco extra. */
        .admin-card > div[style*="overflow-x:auto"] {
            overflow: visible !important;
            border: none !important;
            border-radius: 0 !important;
        }

        /* Tarjeta de registro — fondo blanco puro (no gradient), borde 1px
           #cbd5e1, border-radius 14px, sombra suave 0 4px 12px. Mismo tono de
           borde que las tarjetas moviles de equipos / inventario / movimientos.
           Sin el acento `inset 3px` lateral — el cliente prefirio el look mas
           limpio y minimal. */
        .tr-table tbody tr[data-id] {
            display: grid !important;
            grid-template-columns: 1fr auto !important;
            grid-template-areas:
                "numero  estado"
                "ruta    ruta"
                "meta    meta" !important;
            row-gap: 8px !important;
            column-gap: 10px !important;
            background: #fff !important;
            /* Borde mas marcado (#cbd5e1) — mismo tono que las tarjetas de
               /admin/equipos, para consistencia entre modulos. */
            border: 1px solid #cbd5e1 !important;
            border-radius: 14px !important;
            box-shadow: 0 4px 12px rgba(15,23,42,0.04) !important;
            padding: 14px 16px !important;
            overflow: hidden !important;
            cursor: pointer !important;
            transition: box-shadow 0.2s ease, transform 0.15s ease !important;
        }
        .tr-table tbody tr[data-id]:active {
            transform: translateY(-1px) !important;
            box-shadow: 0 8px 20px rgba(15,23,42,0.08) !important;
        }
        /* Anulamos el hover de tabla (e0f2fe) en mobile — las cards usan :active. */
        .tr-table tbody tr[data-id]:hover td { background: transparent !important; }

        .tr-table tbody tr[data-id] td {
            padding: 0 !important;
            border: none !important;
            background: transparent !important;
            font-size: 12.5px !important;
        }

        /* td:1 = Nº TR-... (esquina sup-izq, monospace destacado) */
        .tr-table tbody tr[data-id] td:nth-child(1) {
            grid-area: numero !important;
            font-family: monospace !important; font-weight: 800 !important;
            font-size: 14px !important; color: #0f172a !important; white-space: nowrap !important;
            align-self: center !important;
        }
        /* td:2 = "Origen → Destino" (ya tiene su sub-layout con div+flecha interno) */
        .tr-table tbody tr[data-id] td:nth-child(2) {
            grid-area: ruta !important;
            padding-top: 8px !important;
            border-top: 1px dashed #e2e8f0 !important;
        }
        /* td:3 = Estado pill */
        .tr-table tbody tr[data-id] td:nth-child(3) {
            grid-area: estado !important;
            text-align: right !important; align-self: center !important;
        }
        /* td:4 = Fecha envío — fila "meta" propia. */
        .tr-table tbody tr[data-id] td:nth-child(4) {
            display: inline-flex !important;
            align-items: center !important;
            gap: 4px !important;
            font-size: 11.5px !important;
            font-weight: 400 !important;
            color: #475569 !important;
            padding-top: 8px !important;
            border-top: 1px dashed #e2e8f0 !important;
            grid-area: meta !important;
        }

        /* Iconito sutil antes de la fecha de envío. */
        .tr-table tbody tr[data-id] td:nth-child(4)::before { content: 'event'; font-family: 'Material Icons'; font-size: 13px; color: #94a3b8; }

        /* Empty state: el <tr> SIN data-id (rama vacia del forelse) queda como bloque centrado sin tarjeta. */
        .tr-table tbody tr:not([data-id]) {
            display: block !important; background: transparent !important;
            border: none !important; box-shadow: none !important; padding: 0 !important;
        }
        .tr-table tbody tr:not([data-id]) td {
            display: block !important; text-align: center !important;
            padding: 36px 16px !important; border: none !important;
        }
    }
</style>

{{-- Layout: la tabla y el panel de resumen, cada uno en SU PROPIO contenedor. --}}
<div class="tr-layout">
<div class="admin-card" style="margin:0;min-height:70vh;padding:14px;flex:1 1 0;min-width:0;">

    {{-- ── Filtros (search por N° de nota + filtros avanzados estilo equipos) ──
         trSearch → por NUMERO de la nota de entrega (TR-2026-…) con autocomplete
         sobre la lista pre-cargada (`numerosNotas`). --}}
    <div id="trFilters">
        <div class="tr-item tr-search-num">
            <div class="tr-search-box {{ $reqSearch ? 'active' : '' }}">
                <i class="material-icons lupa">search</i>
                <input type="text" id="trSearch" autocomplete="off" placeholder="N° de nota (NE-… o TR-…)" value="{{ $reqSearch }}"
                       oninput="window.trSearchInput()"
                       onblur="setTimeout(function(){ var s=document.getElementById('trSearchSuggest'); if(s) s.classList.remove('open'); }, 150);">
            </div>
            {{-- Sugerencias en vivo: lista los N° de nota visibles al usuario que coinciden
                 con lo que está escribiendo. Cargar la lista en el render evita un endpoint
                 extra — son strings cortos y vienen limitados a 300 desde el controller. --}}
            <div id="trSearchSuggest" class="tr-suggest"></div>
        </div>

        {{-- Filtros en línea, al lado del buscador (antes vivían en un panel "Filtros
             Avanzados" desplegable). Mismo border/radius/altura (40px) que el buscador.
             Azul = filtro activo: en Estado, "En tránsito" es el default → se ve blanco. --}}
        <div class="tr-item tr-filter-estado">
            <select id="trEstado" onchange="window.trLoad()" class="tr-filter-input"
                    title="Estado de la nota"
                    style="cursor:pointer;background:{{ $reqEstado !== \App\Models\Traspaso::ESTADO_ENVIADO ? '#e1effa' : '#fff' }};">
                @foreach($badgesEstado as $k => $b)
                    <option value="{{ $k }}" {{ $reqEstado === $k ? 'selected' : '' }}>{{ $b[0] }}</option>
                @endforeach
                <option value="all" {{ $reqEstado === 'all' ? 'selected' : '' }}>Todas (historial)</option>
            </select>
        </div>
        {{-- Las cajas de fecha envuelven el input para que el clic en CUALQUIER parte
             abra el picker nativo via showPicker() — sin apuntar al iconito chiquito. --}}
        <div class="tr-item tr-filter-fecha">
            <div id="trDesdeBox" class="tr-date-box" style="background:{{ $reqDesde ? '#e1effa' : '#fff' }};"
                 onclick="var i=document.getElementById('trDesde'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                <i class="material-icons">event</i>
                <span class="tr-date-label">Desde</span>
                <input type="date" id="trDesde" value="{{ $reqDesde }}" onchange="window.trLoad()">
            </div>
        </div>
        <div class="tr-item tr-filter-fecha">
            <div id="trHastaBox" class="tr-date-box" style="background:{{ $reqHasta ? '#e1effa' : '#fff' }};"
                 onclick="var i=document.getElementById('trHasta'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                <i class="material-icons">event</i>
                <span class="tr-date-label">Hasta</span>
                <input type="date" id="trHasta" value="{{ $reqHasta }}" onchange="window.trLoad()">
            </div>
        </div>
    </div>

    {{-- ── Tabla ── --}}
            <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:10px;">
                <table class="tr-table">
                    <thead>
                        <tr>
                            {{-- "Nº Nota" al mínimo (código fijo). "Estado" y "Enviado" con su
                                 ancho NATURAL (nowrap, sin width:1%) para que tengan algo de aire;
                                 "Origen / Destino" absorbe el resto, pero sin acaparar tanto. --}}
                            <th style="width:1%;white-space:nowrap;" title="Número de la Nota de Entrega (NE-YYYY-NNNN).">Nº Nota</th>
                            <th title="Arriba el almacén que ENVÍA; abajo el que RECIBE.">Origen / Destino</th>
                            <th style="white-space:nowrap;text-align:center;" title="Estado actual de la nota.">Estado</th>
                            <th style="white-space:nowrap;" title="Fecha de despacho. Indicador: verde &lt;24h, amarillo 1-3d, rojo &gt;3d.">Enviado</th>
                        </tr>
                    </thead>
                    <tbody id="trTableBody">
                        @include('admin.almacen.recepcion.partials.rows', ['traspasos' => $traspasos])
                    </tbody>
                </table>
            </div>
    <div style="margin-top:14px;" id="trPagination">{{ $traspasos->links('vendor.pagination.custom-sliding') }}</div>
</div>{{-- /.admin-card (contenedor de la tabla) --}}

    {{-- Panel de resumen — SU PROPIO contenedor (tarjeta con gradiente), hermano de la
         tabla. KPIs estables de la bandeja (no dependen de los filtros). --}}
    <aside class="tr-stats" aria-label="Resumen de la bandeja">
            <i class="material-icons tr-stats-bgicon">inbox</i>
            <div style="position:relative;z-index:2;">
                <div class="tr-stats-title"><i class="material-icons">inbox</i> Resumen de la bandeja</div>
                <div class="tr-stats-row">
                    <div class="tr-stats-hero" title="Notas pendientes de confirmar">
                        <span class="tr-stats-hero-num">{{ $bandejaStats['por_revisar'] ?? 0 }}</span>
                        <span class="tr-stats-hero-lbl">Por revisar</span>
                    </div>
                    <div class="tr-stats-grid">
                        <div class="tr-stats-sub tr-sub-rec" title="Llegadas en las últimas 24 h">
                            <i class="material-icons" style="color:#22c55e;">bolt</i>
                            <strong>{{ $bandejaStats['recientes'] ?? 0 }}</strong>
                            <span>Recientes 24h</span>
                        </div>
                        <div class="tr-stats-sub tr-sub-urg" title="Esperando más de 3 días">
                            <i class="material-icons" style="color:#f59e0b;">priority_high</i>
                            <strong>{{ $bandejaStats['urgentes'] ?? 0 }}</strong>
                            <span>Urgentes +3d</span>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
</div>{{-- /.tr-layout --}}

{{-- ── Modal detalle/recepción ── --}}
<div class="dtm-overlay" id="trDetalleOverlay" onclick="if(event.target===this) window.trCloseModal();">
    <div class="dtm-box" id="trDetalleBox"></div>
</div>

<script>
(function () {
    'use strict';
    if (!document.getElementById('trTableBody')) return;
    var ROUTE = @json(route('almacen.recepcion.index'));

    // Lista de N° de nota visibles para el usuario (TR-YYYY-NNNN). Cargada desde
    // el controller en cada render; 300 más recientes — suficiente para el
    // autocomplete sin pedir un endpoint extra.
    var TR_NUMEROS = @json($numerosNotas ?? []);

    function el(id) { return document.getElementById(id); }
    function v(id) { var e = el(id); return e ? String(e.value).trim() : ''; }
    // Lectura de los hidden inputs de los custom-dropdown (por atributo data-filter-value).
    // Necesario para el dropdown "Almacén destino" del header (#trDestHeaderDropdown),
    // que no tiene un <select> tradicional. Patrón calcado de /admin/almacen/movimientos.
    function hv(name) { var e = document.querySelector('input[name="' + name + '"][data-filter-value]'); return e ? String(e.value).trim() : ''; }

    // ── Autocomplete del filtro "N° de nota" ──────────────────────────────
    // Filtra la lista pre-cargada por prefijo + substring (case-insensitive) y
    // muestra hasta 8 sugerencias debajo del input. Clic en una → completa el
    // valor y dispara trLoad. Se cierra al perder foco (con un timeout pequeño
    // para que el click en la sugerencia llegue primero).
    window.trSearchInput = function () {
        var input = el('trSearch');
        var box   = el('trSearchSuggest');
        if (!input || !box) return;
        var q = String(input.value || '').trim().toUpperCase();

        // Si el campo está vacío cerramos el panel de sugerencias y recargamos la tabla (para quitar el filtro).
        if (q === '') {
            box.classList.remove('open');
            clearTimeout(window._trST);
            window._trST = setTimeout(window.trLoad, 400);
            return;
        }

        // Filtrar: substring case-insensitive (indexOf); máximo 8.
        var matches = TR_NUMEROS.filter(function (n) {
            return String(n).toUpperCase().indexOf(q) !== -1;
        }).slice(0, 8);

        if (matches.length === 0) {
            box.innerHTML = '<div class="tr-suggest-empty">Sin coincidencias</div>';
        } else {
            box.innerHTML = matches.map(function (n) {
                var safe = String(n).replace(/'/g, "\\'");
                return '<div class="tr-suggest-item" onclick="window.trSearchPick(\'' + safe + '\')">' + n + '</div>';
            }).join('');
        }
        box.classList.add('open');

        // Re-arma el debounce de trLoad solo si hay texto (>= 1 car.).
        clearTimeout(window._trST);
        window._trST = setTimeout(window.trLoad, 400);
    };

    window.trSearchPick = function (numero) {
        var input = el('trSearch'); if (!input) return;
        input.value = numero;
        var box = el('trSearchSuggest'); if (box) box.classList.remove('open');
        clearTimeout(window._trST);
        window.trLoad();
    };

    function params(pageUrl) {
        // El backend muestra por defecto solo "En tránsito" (pendientes). Aquí mandamos
        // los filtros del UI (search/estado/destino/fechas). El estado SIEMPRE se envía
        // —incluido 'all' (Todas/historial) y 'ENVIADO'— para que el backend sepa
        // exactamente qué se pidió y no caiga en el default cuando el usuario eligió otro.
        var p = new URLSearchParams();
        if (v('trSearch'))                                 p.set('search', v('trSearch'));

        if (v('trEstado'))                                 p.set('estado', v('trEstado'));
        // El "Almacén destino" ahora vive en el dropdown del header (no en el panel
        // avanzado). Se lee del hidden input que el custom-dropdown mantiene.
        // Pasar `all` explícito para que el controller NO re-aplique el default
        // por frente cuando el usuario eligió "Todos los almacenes destino".
        var dest = hv('id_almacen_destino');
        if (dest)                                          p.set('id_almacen_destino', dest);
        if (v('trDesde'))                                  p.set('desde', v('trDesde'));
        if (v('trHasta'))                                  p.set('hasta', v('trHasta'));
        if (pageUrl) { try { var pg = new URL(pageUrl, window.location.origin).searchParams.get('page'); if (pg) p.set('page', pg); } catch (e) {} }
        return p;
    }

    // Refresca el tinte azul de cada filtro en línea (Estado / Desde / Hasta) según si
    // tiene un valor activo. Se llama en trLoad para mantener UI = estado.
    function trUpdateChips() {
        var paint = function (id, on) { var e = el(id); if (e) e.style.background = on ? '#e1effa' : '#fff'; };
        var sel   = function (id) { var e = el(id); return e ? e.value : ''; };
        // "En tránsito" (ENVIADO) es el estado por defecto → NO cuenta como filtro activo.
        var hasEst = sel('trEstado')  && sel('trEstado')  !== '{{ \App\Models\Traspaso::ESTADO_ENVIADO }}';
        paint('trEstado',   hasEst);
        paint('trDesdeBox', !!sel('trDesde'));
        paint('trHastaBox', !!sel('trHasta'));
    }

    window.trLoad = function (pageUrl) {
        var body = el('trTableBody'); if (!body) return;
        trUpdateChips();
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
            .catch(function () { body.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:#dc2626;">No se pudieron cargar las notas de entrega.</td></tr>'; })
            .finally(function () { body.style.opacity = '1'; if (window.hidePreloader) window.hidePreloader(); });
    };

    // Click en fila → abrir modal de detalle
    document.addEventListener('click', function (e) {
        var row = e.target.closest('#trTableBody tr[data-id]');
        if (row) window.trOpenModal(row.dataset.id);
    });

    // ── Modal de detalle/recepción ──────────────────────────────────
    var DETALLE_URL = @json(url('/admin/almacen/recepcion'));
    var _trModalId  = null;

    window.trOpenModal = function (id) {
        _trModalId = id;
        var overlay = el('trDetalleOverlay');
        var box     = el('trDetalleBox');
        if (!overlay || !box) return;
        box.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:60px;"><i class="material-icons" style="font-size:32px;color:#94a3b8;animation:spin 1s linear infinite;">autorenew</i></div>';
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';

        fetch(DETALLE_URL + '/' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(function (data) {
                box.innerHTML = data.html || '';
                _trModalId = data.id || id;
                trInitDiffCalc();
            })
            .catch(function () {
                box.innerHTML = '<div style="padding:40px;text-align:center;color:#dc2626;font-weight:600;">No se pudo cargar el detalle.</div>';
            });
    };

    window.trCloseModal = function () {
        var overlay = el('trDetalleOverlay');
        if (overlay) overlay.classList.remove('open');
        document.body.style.overflow = '';
        _trModalId = null;
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') window.trCloseModal();
    });

    function trInitDiffCalc() {
        var box = el('trDetalleBox');
        if (!box) return;
        box.querySelectorAll('.dtm-linea').forEach(function (card) {
            var input = card.querySelector('.dtm-rec-input');
            var diffEl = card.querySelector('.dtm-diff-value');
            if (!input || !diffEl) return;
            var enviada = parseFloat(card.dataset.enviada) || 0;
            input.addEventListener('input', function () {
                var rec = parseFloat(input.value) || 0;
                var d = rec - enviada;
                diffEl.textContent = d > 0 ? '+' + d.toFixed(3).replace(/\.?0+$/, '') : d.toFixed(3).replace(/\.?0+$/, '');
                diffEl.style.color = d < 0 ? '#dc2626' : (d > 0 ? '#1d4ed8' : '#64748b');
            });
        });
    }

    function trCollectLineas() {
        var box = el('trDetalleBox');
        if (!box) return [];
        var lineas = [];
        box.querySelectorAll('.dtm-linea').forEach(function (card) {
            var inp = card.querySelector('.dtm-rec-input');
            var danado = card.querySelector('.dtm-rec-danado');
            var obj = {
                id_linea:          parseInt(card.dataset.idLinea),
                cantidad_recibida: inp ? parseFloat(inp.value) || 0 : null,
            };
            if (danado && danado.checked) obj.estado = 'DANADO';
            lineas.push(obj);
        });
        return lineas;
    }

    function trModalPost(url, payload, successMsg) {
        if (window.showPreloader) window.showPreloader();
        fetch(url, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify(payload),
        })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
        .then(function (res) {
            if (res.ok) {
                if (window.showToast) window.showToast(successMsg || res.data.message || 'Operación exitosa', 'success');
                window.trCloseModal();
                window.trLoad();
            } else {
                if (window.showToast) window.showToast(res.data.message || 'Error en la operación', 'error');
            }
        })
        .catch(function () {
            if (window.showToast) window.showToast('Error de conexión', 'error');
        })
        .finally(function () { if (window.hidePreloader) window.hidePreloader(); });
    }

    window.trModalTodoOk = function () {
        if (!_trModalId) return;
        var box = el('trDetalleBox');
        if (box) {
            box.querySelectorAll('.dtm-rec-input').forEach(function (inp) {
                var card = inp.closest('.dtm-linea');
                if (card) inp.value = card.dataset.enviada || inp.value;
                inp.dispatchEvent(new Event('input'));
            });
            box.querySelectorAll('.dtm-rec-danado').forEach(function (cb) { cb.checked = false; });
        }
    };

    window.trModalConfirmar = function () {
        if (!_trModalId) return;
        var lineas = trCollectLineas();
        if (!lineas.length) return;
        trModalPost(
            DETALLE_URL + '/' + _trModalId + '/recibir',
            { lineas: lineas },
            'Recepción confirmada'
        );
    };

    window.trModalCancelar = function (neNumero) {
        if (!_trModalId) return;
        if (!confirm('¿Cancelar la nota ' + (neNumero || _trModalId) + '? Esta acción no se puede deshacer.')) return;
        trModalPost(
            DETALLE_URL + '/' + _trModalId + '/cancelar',
            {},
            'Nota cancelada'
        );
    };

    window.trModalEnviar = function () {
        if (!_trModalId) return;
        trModalPost(
            DETALLE_URL + '/' + _trModalId + '/enviar',
            {},
            'Nota enviada'
        );
    };

    // Paginación AJAX
    document.addEventListener('click', function (e) {
        var a = e.target.closest('#trPagination a.page-link') || e.target.closest('#trPagination a');
        if (a) { e.preventDefault(); e.stopImmediatePropagation(); window.trLoad(a.href); }
    }, true);

    // Los custom-dropdowns disparan 'dropdown-selection' cuando el usuario elige una
    // opcion. Recargamos la tabla cuando cambia el almacen destino (header).
    window.addEventListener('dropdown-selection', function (e) {
        var id = e.detail && e.detail.dropdownId;
        if (id === 'trDestHeaderDropdown') window.trLoad();
    });
})();
</script>

{{-- El antiguo modal #entModal ("Registrar entrada directa") fue extraido a su
     pagina propia /admin/almacen/recepcion/nueva — esa ruta ofrece el mismo flujo
     (POST a almacen.movimientos.lote con tipo=ENTRADA) pero con autocomplete de
     producto por codigo o descripcion. El boton "Recepción ODC" del header de
     esta vista linkea directo alla. --}}

@endsection
