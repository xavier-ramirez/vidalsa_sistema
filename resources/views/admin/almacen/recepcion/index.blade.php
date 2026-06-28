@extends('layouts.estructura_base')

@section('title', 'Recepción de materiales')

@section('content')
@php
    // Filtro de Estado. Por defecto (sin parámetro) la bandeja muestra solo "En tránsito"
    // (ENVIADO) = pendientes de confirmar; ese default lo resuelve el TraspasoController@index
    // server-side. El DROPDOWN, en cambio, arranca en BLANCO ("Estado", sin tinte azul ni X):
    // pedido del cliente — no debe verse "filtrado" al abrir. El azul y la X aparecen solo
    // cuando el usuario elige un estado concreto de la lista.
    $reqEstado     = request('estado', '');
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
    <div class="tr-tabs" style="display:flex;gap:0;margin-top:12px;border-bottom:2px solid #e2e8f0;">
        <a href="{{ route('almacen.recepcion.index', ['force' => 1]) }}"
           style="display:flex;align-items:center;gap:6px;padding:8px 20px;font-size:13px;font-weight:700;color:#0067b1;border-bottom:2px solid #0067b1;margin-bottom:-2px;text-decoration:none;transition:all .15s;">
            <i class="material-icons" style="font-size:16px;">inbox</i> Bandeja de entrada
        </a>
        @can('almacen.movimiento')
        <a href="{{ route('almacen.recepcion.nueva') }}"
           style="display:flex;align-items:center;gap:6px;padding:8px 20px;font-size:13px;font-weight:600;color:#64748b;text-decoration:none;transition:all .15s;"
           onmouseenter="this.style.color='#0067b1'" onmouseleave="this.style.color='#64748b'">
            <i class="material-icons" style="font-size:16px;">add_circle_outline</i> Entrada por ODC
        </a>
        @endcan
    </div>
</section>

<style>
    /* ── Bandeja de recepción — estilo WMS profesional ── */

    /* Toolbar de filtros */
    #trFilters {
        display:flex; gap:10px; flex-wrap:wrap; align-items:center;
        margin-bottom:12px;
    }
    #trFilters .tr-item { flex:1 1 220px; min-width:180px; max-width:300px; }
    #trFilters .tr-search-num  { flex:1 1 280px; max-width:400px; min-width:200px; position:relative; }
    /* Toolbar alineado al estándar de /admin/almacen/movimientos: cajas de 45px,
       radio 12px, fondo suave #fbfcfd y letra 14px (antes 40px/8px/13px se veía
       más apretado que el resto de los módulos). Azul #e1effa cuando hay filtro. */
    .tr-search-box { display:flex; align-items:center; height:45px; border:1px solid #cbd5e0; border-radius:12px; background:#fbfcfd; overflow:hidden; }
    .tr-search-box.active { border-color:#0067b1; background:#e1effa; }
    .tr-search-box i.lupa { padding:0 10px; color:#64748b; font-size:18px; }
    .tr-search-box input { flex:1; border:none; background:transparent; outline:none; padding:10px 5px; font-size:14px; min-width:0; color:#0f172a; }
    /* Filtro Estado (custom-dropdown) — misma altura (45px) y letra (14px global) que
       el buscador y el resto de filtros de la app. */
    #trFilters .tr-filter-estado { flex:0 1 180px; min-width:150px; max-width:220px; }
    /* Cajas de fecha (Desde/Hasta), ahora dentro del panel "Filtros avanzados". */
    .tr-date-box { display:flex; align-items:center; gap:5px; height:40px; border:1px solid #cbd5e0; border-radius:8px; padding:0 10px; cursor:pointer; box-sizing:border-box; }
    .tr-date-box i { font-size:16px; color:#94a3b8; pointer-events:none; }
    .tr-date-box input[type=date] { flex:1; min-width:0; border:none; background:transparent; padding:0; font-size:12px; outline:none; color:#0f172a; cursor:pointer; }

    /* Panel "Filtros avanzados": Desde/Hasta en 2 columnas (lado a lado). El min-width:0
       en las celdas deja que los inputs de fecha encojan y NO se desborden del panel. */
    .tr-adv-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
    .tr-adv-grid > div { min-width:0; }
    .tr-adv-grid .tr-date-box { padding:0 6px; gap:4px; }
    .tr-adv-grid .tr-date-box input[type=date] { font-size:11px; }

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
    /* Las 3 métricas, TODAS del mismo tamaño: grid de 3 columnas iguales. */
    .tr-stats-row { display:grid; grid-template-columns:repeat(3,1fr); gap:6px; }
    /* Icono AL LADO del número (en fila); el label cae a su propia línea debajo
       (flex:1 1 100% lo fuerza al siguiente renglón). */
    /* Cada métrica: contenido centrado (icono+número en una línea, label debajo),
       clicable para filtrar la bandeja. */
    .tr-stats-sub { display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:3px 6px; padding:8px 4px; border-radius:8px; text-align:center; cursor:pointer; user-select:none; transition:transform .12s ease, box-shadow .15s ease, filter .15s ease; }
    .tr-stats-sub i { font-size:18px; }
    .tr-stats-sub strong { flex:0 0 auto; font-weight:800; font-size:18px; color:#fff; }
    .tr-stats-sub span { flex:1 1 100%; font-size:10px; opacity:0.9; font-weight:700; text-transform:uppercase; line-height:1.1; }
    .tr-stats-sub:hover { filter:brightness(1.18); }
    .tr-stats-sub:active { transform:scale(0.96); }
    /* Métrica activa: anillo blanco que resalta sobre el gradiente azul. */
    .tr-stats-sub.active { box-shadow:0 0 0 2px rgba(255,255,255,0.95), 0 4px 10px rgba(0,0,0,0.18); }
    .tr-sub-rev { background:rgba(255,255,255,0.15); border:1px solid rgba(255,255,255,0.25); }
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
    /* Divisores verticales entre columnas (mismo patrón que el módulo de equipos):
       encabezado #334155, cuerpo claro #e2e8f0. */
    .tr-table thead th { text-align:center; color:#fff; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:1px; padding:10px 14px; border-right:1px solid #334155; border-bottom:2px solid #0f172a; white-space:nowrap; }
    .tr-table thead th:last-child { border-right:none; }
    .tr-table tbody td { padding:12px 14px; color:#000; border-bottom:1px solid #e2e8f0; border-right:1px solid #e2e8f0; text-align:center; vertical-align:middle; }
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
        background:#fff; border-radius:16px; width:100%; max-width:760px;
        max-height:95vh; min-height:60vh; display:flex; flex-direction:column; overflow:hidden;
        box-shadow:0 25px 50px -12px rgba(0,0,0,0.35);
        animation: dtmIn .2s ease-out;
    }
    @keyframes dtmIn { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
    .dtm-header { padding:0; border-bottom:1px solid #e2e8f0; flex-shrink:0; }
    /* Barra superior estilo modal de Movilización: slate #1e293b, icono azul + número
       centrados, botón cerrar absoluto a la derecha. Va a sangre (las esquinas las
       redondea el overflow:hidden de .dtm-box). */
    .dtm-title-row { position:relative; display:flex; align-items:center; justify-content:center; gap:9px; background:#1e293b; padding:15px 48px; }
    .dtm-title-icon { color:#0067b1; font-size:19px; }
    .dtm-numero { font-family:monospace; font-size:15px; font-weight:800; color:#fff; }
    .dtm-close {
        position:absolute; right:12px; top:50%; transform:translateY(-50%);
        background:transparent; border:none; cursor:pointer;
        color:#fff; opacity:.75; padding:4px; border-radius:6px; transition:opacity .15s;
    }
    .dtm-close:hover { opacity:1; }
    .dtm-meta { display:flex; flex-wrap:wrap; gap:0; margin:13px 18px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; }
    .dtm-meta-item { flex:1 1 130px; padding:5px 9px; border-right:1px solid #e2e8f0; }
    .dtm-meta-item:last-child { border-right:none; }
    .dtm-meta-label { display:block; font-size:8.5px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.4px; }
    .dtm-meta-value { font-size:11px; font-weight:600; color:#1e293b; }
    .dtm-sub { font-size:10px; color:#94a3b8; font-weight:400; }

    .dtm-body { flex:1; overflow-y:auto; padding:14px 20px; }
    .dtm-notas { display:flex; align-items:flex-start; gap:6px; padding:8px 10px; background:#fffbeb; border:1px solid #fef3c7; border-radius:8px; font-size:12.5px; color:#92400e; margin-bottom:10px; }
    .dtm-lineas-header { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:8px; }
    .dtm-lineas-header span:first-child { font-size:12px; font-weight:700; color:#334155; text-transform:uppercase; letter-spacing:.5px; }
    /* Hint "Toca los que llegaron" (solo en recepción activa): gris, discreto, a la derecha. */
    .dtm-rec-hint { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:600; color:#94a3b8; font-style:italic; }
    .dtm-rec-hint .material-icons { font-size:14px; }
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
    /* Misma letra para TODA la tabla: 13px, misma familia (sin monospace), para que
       código, nombre, UM y cantidades se vean uniformes (pedido del cliente). */
    .dtm-linea-cod { font-weight:700; font-size:13px; color:#0f172a; }
    .dtm-linea-nom { font-size:13px; font-weight:500; color:#334155; margin-left:4px; }
    .dtm-linea-um { font-size:13px; font-weight:500; color:#94a3b8; text-transform:uppercase; margin-left:4px; }
    .dtm-col-num { font-weight:600; font-size:13px; color:#0f172a; white-space:nowrap; }
    /* Columna "#": numeración de filas, gris y discreta. */
    .dtm-col-idx { font-size:13px; font-weight:700; color:#94a3b8; width:1%; white-space:nowrap; }
    /* Recibido = check (llegó) + input de cantidad. El check marca recibido; la cantidad
       sale del input contiguo, que arranca deshabilitado con lo enviado y se habilita al
       marcar para registrar que llegó OTRA cantidad (parcial). */
    /* Recepción activa: la fila se marca "recibida" tocándola (sin checkbox). Cursor
       de mano + hover suave; al marcar, toda la fila se resalta en azul y la cantidad
       recibida se resalta también. Sin marcar = cantidad en gris (no recibido aún). */
    .dtm-linea-rec { cursor:pointer; }
    .dtm-linea-rec .dtm-rec-cant { color:#94a3b8; }
    .dtm-linea-rec.recibida td { background:#e1effa; }
    .dtm-linea-rec.recibida:hover td { background:#d6e9fb; }
    .dtm-linea-rec.recibida .dtm-rec-cant { color:#0067b1; font-weight:800; }
    /* Check verde de "tildado": oculto por defecto, aparece al marcar la fila (.recibida). */
    .dtm-rec-cant .dtm-rec-ico { display:none; font-size:16px; vertical-align:middle; margin-right:3px; color:#16a34a; }
    .dtm-linea-rec.recibida .dtm-rec-ico { display:inline; }
    .dtm-diff-value { font-size:13px; font-weight:600; color:#64748b; }

    .dtm-footer {
        display:flex; align-items:center; justify-content:center; gap:12px; flex-wrap:wrap;
        padding:14px 20px; border-top:1px solid #e2e8f0; flex-shrink:0;
        background:#fff;
    }
    .dt-btn {
        height:44px; padding:0 22px; border-radius:10px; cursor:pointer;
        font-size:13.5px; font-weight:700; letter-spacing:.2px;
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        transition:background .15s, transform .1s;
    }
    .dt-btn i { font-size:18px; }
    /* Cancelar = botón blanco neutro (secundario); Aceptar = azul global (primario). */
    .dt-btn-cancel { background:#fff; color:#334155; border:1px solid #cbd5e0; }
    .dt-btn-cancel:hover { background:#f1f5f9; border-color:#94a3b8; }
    .dt-btn-blue { background:var(--maquinaria-blue,#0067b1); color:#fff; border:none; box-shadow:0 4px 8px -2px rgba(0,103,177,0.3); }
    .dt-btn-blue:hover { background:#005391; }
    .dt-btn-blue:active { transform:scale(0.98); }

    @media (max-width: 768px) {
        .dtm-overlay { padding:0; align-items:flex-end; }
        .dtm-box { max-width:100%; max-height:95vh; border-radius:16px 16px 0 0; }
        .dtm-meta-item { flex:1 1 45%; }
        .dtm-footer { flex-direction:column; }
        .dtm-footer .dt-btn { width:100%; justify-content:center; }
        /* Materiales (tabla): celdas y fuentes más compactas para el ancho del teléfono. */
        .dtm-table { font-size:12px; }
        .dtm-table thead th, .dtm-table tbody td { padding:5px 4px; }
        /* Misma letra (12px) para TODO el texto de la tabla también en móvil. */
        .dtm-col-num, .dtm-diff-value, .dtm-linea-cod, .dtm-linea-nom, .dtm-linea-um, .dtm-col-idx { font-size:12px; }
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
        /* …pero las 2 pestañas (Bandeja de entrada / Entrada por ODC) van LADO A LADO,
           no apiladas: revertimos la columna solo para la fila de tabs y repartimos el
           ancho 50/50 con el texto centrado. */
        .page-title-card > div.tr-tabs { flex-direction: row !important; gap: 0 !important; }
        .tr-tabs a { flex: 1 1 0 !important; justify-content: center !important; padding-left: 8px !important; padding-right: 8px !important; }

        /* Filtros en mobile: buscador y Estado a fila completa; Desde/Hasta lado a
           lado; el botón Limpiar (40x40) cierra la fila de fechas. */
        #trFilters { gap: 8px !important; }
        #trFilters > .tr-search-num    { flex: 1 1 100% !important; max-width: none !important; min-width: 0 !important; }
        #trFilters > .tr-filter-estado { flex: 1 1 0 !important; max-width: none !important; }

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
            /* En la TARJETA móvil el contenido va a la izquierda (el centrado es solo
               para la tabla de escritorio). El Estado (td:3) se realínea a la derecha
               más abajo; el trayecto Origen → Destino se mantiene centrado. */
            text-align: left !important;
        }
        /* Trayecto Origen → Destino: centrado dentro de la tarjeta móvil (su contenedor
           interno ya usa justify-content:center; lo reforzamos sobre el <td>). */
        .tr-table tbody tr[data-id] .tr-ruta-dest { text-align: center !important; }

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
                <input type="text" id="trSearch" autocomplete="off" placeholder="Buscar por número de nota de entrega" value="{{ $reqSearch }}"
                       oninput="window.trSearchInput()"
                       onfocus="window.trSearchSuggest()"
                       onkeydown="window.trSearchEnter(event)"
                       onblur="setTimeout(function(){ var s=document.getElementById('trSearchSuggest'); if(s) s.classList.remove('open'); }, 150);">
                {{-- X = vaciar el filtro (mismo patrón que el buscador del módulo Inventario).
                     Visible solo cuando hay texto. --}}
                <i class="material-icons" id="trSearchClear" title="Limpiar filtro"
                   style="display:{{ $reqSearch ? 'flex' : 'none' }};align-items:center;padding:0 10px;color:#64748b;font-size:18px;cursor:pointer;"
                   onclick="window.trSearchClear()">close</i>
            </div>
            {{-- Sugerencias en vivo: lista los N° de nota visibles al usuario que coinciden
                 con lo que está escribiendo. Cargar la lista en el render evita un endpoint
                 extra — son strings cortos y vienen limitados a 300 desde el controller. --}}
            <div id="trSearchSuggest" class="tr-suggest"></div>
        </div>

        {{-- Filtros en línea, al lado del buscador (antes vivían en un panel "Filtros
             Avanzados" desplegable). Mismo border/radius/altura (45px) que el buscador.
             Azul = filtro activo: en Estado, "En tránsito" es el default → se ve neutro. --}}
        {{-- Filtro Estado con el mismo custom-dropdown que el resto de la app (igual que
             "Almacén destino" del header). El <select> nativo se reemplazó para unificar
             el estilo. Azul = filtro activo (cualquier estado distinto del default "En
             tránsito"). selectOption actualiza el hidden input y dispara 'dropdown-selection'
             → trLoad (ver listener abajo). --}}
        @php
            // Activo (azul + X) = el usuario eligió un estado concreto. Blanco/sin X para el
            // default (vacío) y para "all" (que selectOption global trata como neutro).
            $reqEstadoLabel = $badgesEstado[$reqEstado][0] ?? ($reqEstado === 'all' ? 'Todas' : 'Estado');
            $estadoActivo   = $reqEstado !== '' && $reqEstado !== 'all';
        @endphp
        <div class="tr-item tr-filter-estado">
            <div class="custom-dropdown" id="trEstadoDropdown" data-filter-type="estado" data-default-label="Estado">
                <input type="hidden" name="estado" data-filter-value value="{{ $reqEstado }}">
                <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:{{ $estadoActivo ? '#e1effa' : '#fbfcfd' }};overflow:hidden;border:1px solid {{ $estadoActivo ? '#0067b1' : '#cbd5e0' }};border-radius:12px;height:45px;">
                    {{-- Letra normal (14px / peso 400), igual que el filtro "Nota de entrega"
                         (.tr-search-box input) y que los filtros del módulo Inventario
                         (.alm-filter input). Antes era peso 600 → el estado elegido se veía
                         en negrita y desentonaba con el resto (pedido del cliente). --}}
                    <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                           placeholder="{{ $reqEstadoLabel }}"
                           style="flex:1;border:none;background:transparent;padding:8px 10px;font-size:14px;font-weight:400;color:#0f172a;outline:none;min-width:0;cursor:pointer;"
                           oninput="window.filterDropdownOptions(this)">
                    {{-- X = quitar el filtro de estado → vuelve al default (dropdown en blanco;
                         la bandeja muestra "En tránsito"). El selectOption global la muestra
                         solo cuando hay un estado concreto elegido. --}}
                    <i class="material-icons" data-clear-btn title="Quitar filtro de estado"
                       style="padding:0 6px;color:#64748b;font-size:18px;cursor:pointer;transform:none !important;display:{{ $estadoActivo ? 'block' : 'none' }};"
                       onclick="event.stopPropagation(); selectOption('trEstadoDropdown','','Estado');">close</i>
                    <i class="material-icons" style="padding:0 8px;color:#64748b;font-size:18px;pointer-events:none;transform:none !important;">expand_more</i>
                </div>
                <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                    <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                        {{-- Solo los estados accionables de la recepción: En tránsito (pendiente),
                             Confirmada y Confirmada parcial. Se omiten:
                               • BORRADOR  → estado del almacén que EMITE; nunca llega al que recibe.
                               • CANCELADO → la nota cancelada deshace todo (reversa el stock), no
                                             es algo que se filtre en la bandeja.
                             La X de arriba NO es un estado: limpia el filtro y vuelve al default
                             (dropdown en blanco → bandeja "En tránsito"). --}}
                        @foreach($badgesEstado as $k => $b)
                            @continue($k === \App\Models\Traspaso::ESTADO_BORRADOR || $k === \App\Models\Traspaso::ESTADO_CANCELADO)
                            <div class="dropdown-item {{ $reqEstado === $k ? 'selected' : '' }}" data-value="{{ $k }}"
                                 onclick="selectOption('trEstadoDropdown','{{ $k }}','{{ addslashes($b[0]) }}');">{{ $b[0] }}</div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        {{-- Filtros avanzados (Desde/Hasta + atajos de rango) dentro de un panel — mismo
             patrón que /admin/equipos: un botón filter_list que abre el panel. Rojo si hay
             alguna fecha activa. Cierra al hacer clic fuera (ver listener abajo). --}}
        @php $fechasActivas = $reqDesde || $reqHasta; @endphp
        <div style="position:relative;flex:0 0 auto;">
            <button type="button" id="trAdvBtn" class="btn-primary-maquinaria"
                    style="height:45px;width:45px;min-width:45px;padding:0;display:flex;align-items:center;justify-content:center;border-radius:12px;background:{{ $fechasActivas ? '#fee2e2' : '#fbfcfd' }};border:1px solid {{ $fechasActivas ? '#ef4444' : '#cbd5e0' }};color:{{ $fechasActivas ? '#ef4444' : '#64748b' }};box-shadow:none;"
                    onclick="window.trToggleAdvanced(event)" title="Filtros avanzados">
                <i class="material-icons" style="font-size:20px;">filter_list</i>
            </button>
            <div id="trAdvPanel" style="display:none;position:absolute;top:100%;right:0;width:300px;max-width:calc(100vw - 20px);box-sizing:border-box;background:#e2e8f0;border-radius:12px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.15);border:1px solid #cbd5e1;z-index:100;margin-top:10px;padding:15px;">
                <h4 style="margin:0 0 12px 0;font-size:14px;font-weight:700;color:#334155;display:flex;justify-content:space-between;align-items:center;">
                    Filtros avanzados
                    <span style="font-size:12px;color:#64748b;font-weight:400;text-decoration:underline;cursor:pointer;" onclick="window.trClearFechas()">Limpiar</span>
                </h4>
                {{-- Desde / Hasta lado a lado (2 columnas). --}}
                <div class="tr-adv-grid">
                    <div>
                        <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;">Desde</span>
                        <div id="trDesdeBox" class="tr-date-box" style="width:100%;box-sizing:border-box;background:{{ $reqDesde ? '#e1effa' : '#fff' }};"
                             onclick="var i=document.getElementById('trDesde'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                            <i class="material-icons">event</i>
                            <input type="date" id="trDesde" value="{{ $reqDesde }}" onchange="window.trResetKpi(); window.trLoad()">
                        </div>
                    </div>
                    <div>
                        <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;">Hasta</span>
                        <div id="trHastaBox" class="tr-date-box" style="width:100%;box-sizing:border-box;background:{{ $reqHasta ? '#e1effa' : '#fff' }};"
                             onclick="var i=document.getElementById('trHasta'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                            <i class="material-icons">event</i>
                            <input type="date" id="trHasta" value="{{ $reqHasta }}" onchange="window.trResetKpi(); window.trLoad()">
                        </div>
                    </div>
                </div>
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
                            <th title="A la izquierda el almacén que ENVÍA; a la derecha el que RECIBE.">Origen / Destino</th>
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
                {{-- 3 métricas del MISMO tamaño (grid de 3 columnas): icono al lado del
                     número, label debajo. --}}
                <div class="tr-stats-row">
                    <div class="tr-stats-sub tr-sub-rev" data-kpi="por_revisar" role="button" tabindex="0"
                         onclick="window.trKpiFilter('por_revisar')" title="Notas pendientes de confirmar — clic para ver todas">
                        <i class="material-icons" style="color:#fff;">pending_actions</i>
                        <strong>{{ $bandejaStats['por_revisar'] ?? 0 }}</strong>
                        <span>Por revisar</span>
                    </div>
                    <div class="tr-stats-sub tr-sub-rec" data-kpi="recientes" role="button" tabindex="0"
                         onclick="window.trKpiFilter('recientes')" title="Llegadas en las últimas 24 h — clic para filtrar">
                        <i class="material-icons" style="color:#22c55e;">bolt</i>
                        <strong>{{ $bandejaStats['recientes'] ?? 0 }}</strong>
                        <span>Recientes 24h</span>
                    </div>
                    <div class="tr-stats-sub tr-sub-urg" data-kpi="urgentes" role="button" tabindex="0"
                         onclick="window.trKpiFilter('urgentes')" title="Esperando más de 3 días — clic para filtrar">
                        <i class="material-icons" style="color:#f59e0b;">priority_high</i>
                        <strong>{{ $bandejaStats['urgentes'] ?? 0 }}</strong>
                        <span>Urgentes +3d</span>
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

    // SPA-safe: la navegación SPA (navegacion.js) RE-EJECUTA este <script> inline en cada
    // visita a la página. Las funciones window.* se redefinen sin problema, PERO los
    // listeners en document/window se DUPLICARÍAN en cada navegación → acciones como
    // classList.toggle correrían 2 veces (la fila se marca y desmarca = "no pasa nada hasta
    // recargar"). Por eso registramos los listeners GLOBALES una sola vez por pestaña
    // (guardia _trBindGlobal); siguen llamando a las window.* más recientes, así no quedan
    // obsoletos. Las funciones siguen redefiniéndose en cada corrida (operan sobre el DOM vivo).
    var _trBindGlobal = !window.__trRecepcionGlobalBound;
    window.__trRecepcionGlobalBound = true;

    // Lista de N° de nota visibles para el usuario (TR-YYYY-NNNN). Cargada desde
    // el controller en cada render; 300 más recientes — suficiente para el
    // autocomplete sin pedir un endpoint extra.
    var TR_NUMEROS = @json($numerosNotas ?? []);

    // KPI activo del panel "Resumen de la bandeja": '' | 'por_revisar' | 'recientes' |
    // 'urgentes'. Solo 'recientes'/'urgentes' se mandan al backend (filtro datetime);
    // 'por_revisar' = vista default de pendientes (sin parámetro extra).
    var _trKpi = '';

    function el(id) { return document.getElementById(id); }
    function v(id) { var e = el(id); return e ? String(e.value).trim() : ''; }
    // Lectura de los hidden inputs de los custom-dropdown (por atributo data-filter-value).
    // Necesario para el dropdown "Almacén destino" del header (#trDestHeaderDropdown),
    // que no tiene un <select> tradicional. Patrón calcado de /admin/almacen/movimientos.
    function hv(name) { var e = document.querySelector('input[name="' + name + '"][data-filter-value]'); return e ? String(e.value).trim() : ''; }

    // ── Autocomplete del filtro "N° de nota" ──────────────────────────────
    // Mismo comportamiento que los buscadores de Inventario / Equipos: las sugerencias
    // se calculan en el cliente (lista TR_NUMEROS ya cargada) → instantáneas. La tabla
    // NO se filtra al escribir; se filtra cuando el usuario:
    //   (a) elige una sugerencia de la lista [trSearchPick],
    //   (b) pulsa Enter [trSearchEnter], o
    //   (c) limpia con la X [trSearchClear].

    // Muestra/actualiza la lista de sugerencias al instante. Al hacer FOCO con el campo
    // vacío muestra las más recientes (como las listas rápidas de Equipos); con texto,
    // filtra por substring. No toca la tabla.
    window.trSearchSuggest = function () {
        var input = el('trSearch');
        var box   = el('trSearchSuggest');
        if (!input || !box) return;
        var q = String(input.value || '').trim().toUpperCase();
        var matches = (q === '')
            ? TR_NUMEROS.slice(0, 8)
            : TR_NUMEROS.filter(function (n) { return String(n).toUpperCase().indexOf(q) !== -1; }).slice(0, 8);

        if (matches.length === 0) {
            box.innerHTML = '<div class="tr-suggest-empty">Sin coincidencias</div>';
        } else {
            box.innerHTML = matches.map(function (n) {
                var safe = String(n).replace(/'/g, "\\'");
                return '<div class="tr-suggest-item" onclick="window.trSearchPick(\'' + safe + '\')">' + n + '</div>';
            }).join('');
        }
        box.classList.add('open');
    };

    // Sincroniza la X y el tinte azul (.active) del buscador según haya texto.
    function trSearchToggleClear() {
        var input = el('trSearch'); if (!input) return;
        var has = !!input.value.trim();
        var x = el('trSearchClear'); if (x) x.style.display = has ? 'flex' : 'none';
        var box = input.closest('.tr-search-box'); if (box) box.classList.toggle('active', has);
    }

    // Escribir: sale del modo KPI, refresca la X y las sugerencias. NO recarga la tabla.
    window.trSearchInput = function () {
        window.trResetKpi();
        trSearchToggleClear();
        window.trSearchSuggest();
    };

    window.trSearchPick = function (numero) {
        var input = el('trSearch'); if (!input) return;
        input.value = numero;
        var box = el('trSearchSuggest'); if (box) box.classList.remove('open');
        trSearchToggleClear();
        clearTimeout(window._trST);
        window.trLoad();
    };

    // Enter en el buscador → filtra por el texto tal cual (similitudes vía LIKE del
    // backend), sin tener que elegir una sugerencia. Igual que el módulo Inventario.
    window.trSearchEnter = function (ev) {
        if (ev && ev.key !== 'Enter') return;
        if (ev) ev.preventDefault();
        var box = el('trSearchSuggest'); if (box) box.classList.remove('open');
        clearTimeout(window._trST);
        window.trLoad();
    };

    // X = vaciar el filtro y recargar sin filtro (mismo patrón que almBuscarLimpiar del
    // módulo Inventario).
    window.trSearchClear = function () {
        var input = el('trSearch'); if (!input) return;
        input.value = '';
        var box = el('trSearchSuggest'); if (box) box.classList.remove('open');
        trSearchToggleClear();
        window.trResetKpi();
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

        // Estado: ahora es un custom-dropdown → se lee del hidden input (data-filter-value).
        if (hv('estado'))                                  p.set('estado', hv('estado'));
        // El "Almacén destino" ahora vive en el dropdown del header (no en el panel
        // avanzado). Se lee del hidden input que el custom-dropdown mantiene.
        // Pasar `all` explícito para que el controller NO re-aplique el default
        // por frente cuando el usuario eligió "Todos los almacenes destino".
        var dest = hv('id_almacen_destino');
        if (dest)                                          p.set('id_almacen_destino', dest);
        if (v('trDesde'))                                  p.set('desde', v('trDesde'));
        if (v('trHasta'))                                  p.set('hasta', v('trHasta'));
        // KPI del panel (solo recientes/urgentes llevan su ventana de tiempo al backend).
        if (_trKpi === 'recientes' || _trKpi === 'urgentes') p.set('kpi', _trKpi);
        if (pageUrl) { try { var pg = new URL(pageUrl, window.location.origin).searchParams.get('page'); if (pg) p.set('page', pg); } catch (e) {} }
        return p;
    }

    // Refresca el tinte azul de los filtros de FECHA (Desde / Hasta) según si tienen valor.
    // El Estado ahora es un custom-dropdown que maneja su propio estado activo (azul en el
    // trigger desde el render). Se llama en trLoad para mantener UI = estado.
    function trUpdateChips() {
        var paint = function (id, on) { var e = el(id); if (e) e.style.background = on ? '#e1effa' : '#fff'; };
        var sel   = function (id) { var e = el(id); return e ? e.value : ''; };
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
                // Refrescar las sugerencias del buscador con las del almacén/filtros actuales
                // (el backend las recalcula y las manda en cada respuesta). Sin esto, al
                // cambiar el "Almacén destino" seguían apareciendo notas del almacén anterior.
                if (Array.isArray(data.numerosNotas)) TR_NUMEROS = data.numerosNotas;
                try { window.history.replaceState(null, '', url); } catch (e) {}
            })
            .catch(function () { body.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:#dc2626;">No se pudieron cargar las notas de entrega.</td></tr>'; })
            .finally(function () { body.style.opacity = '1'; if (window.hidePreloader) window.hidePreloader(); });
    };

    // Click en fila → abrir modal de detalle
    if (_trBindGlobal) document.addEventListener('click', function (e) {
        var row = e.target.closest('#trTableBody tr[data-id]');
        if (row) window.trOpenModal(row.dataset.id);
    });

    // ── Modal de detalle/recepción ──────────────────────────────────
    var DETALLE_URL = @json(url('/admin/almacen/recepcion'));
    var _trModalId  = null;
    // Evita doble guardado: lo ponen en true las acciones explícitas (confirmar/
    // cancelar/enviar) ANTES de postear, para que el cierre del modal que disparan
    // al terminar no vuelva a auto-guardar. Se resetea al abrir un modal nuevo.
    var _trModalSubmitted = false;

    window.trOpenModal = function (id) {
        _trModalId = id;
        _trModalSubmitted = false;
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
            })
            .catch(function () {
                box.innerHTML = '<div style="padding:40px;text-align:center;color:#dc2626;font-weight:600;">No se pudo cargar el detalle.</div>';
            });
    };

    window.trCloseModal = function () {
        // Auto-guardado PARCIAL al cerrar: si el modal es una recepción activa, hay AL
        // MENOS una fila marcada (.recibida) y no se confirmó/canceló ya con un botón
        // (_trModalSubmitted), al cerrar se guarda lo marcado (las no marcadas quedan como
        // faltante → el backend la marca "Confirmada parcial"). Si no hay nada marcado,
        // cerrar NO guarda nada (evita confirmaciones accidentales).
        var box = el('trDetalleBox');
        if (!_trModalSubmitted && box && box.querySelector('.dtm-linea-rec.recibida')) {
            window.trModalConfirmar(); // postea (marca _trModalSubmitted) y al terminar reentra aquí para cerrar
            return;
        }
        var overlay = el('trDetalleOverlay');
        if (overlay) overlay.classList.remove('open');
        document.body.style.overflow = '';
        _trModalId = null;
        _trModalSubmitted = false;
    };

    if (_trBindGlobal) document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') window.trCloseModal();
    });

    // Fila marcada (.recibida) = recibida por la cantidad enviada (data-enviada). Sin
    // marcar = no recibida (0 → el backend lo registra como faltante).
    function trCollectLineas() {
        var box = el('trDetalleBox');
        if (!box) return [];
        var lineas = [];
        box.querySelectorAll('.dtm-linea-rec').forEach(function (card) {
            var enviada = parseFloat(card.dataset.enviada) || 0;
            lineas.push({
                id_linea:          parseInt(card.dataset.idLinea),
                cantidad_recibida: card.classList.contains('recibida') ? enviada : 0,
            });
        });
        return lineas;
    }

    // Tocar una fila de la recepción activa la marca/desmarca como recibida (azul).
    // Delegado en #trDetalleBox porque el contenido del modal se carga por AJAX.
    if (_trBindGlobal) document.addEventListener('click', function (e) {
        var row = e.target.closest('#trDetalleBox .dtm-linea-rec');
        if (row) { row.classList.toggle('recibida'); window.trUpdateConfirmBtn(); }
    });

    // Botón "Confirmar (N)": aparece SOLO cuando hay filas tildadas (.recibida) y muestra
    // el conteo. El botón "Confirmar todo" está siempre visible (un toque). Window-function
    // porque el listener global (bind único) la llama.
    window.trUpdateConfirmBtn = function () {
        var box = el('trDetalleBox'); if (!box) return;
        var btnSel = box.querySelector('#trConfirmSelBtn'); if (!btnSel) return;
        var n = box.querySelectorAll('.dtm-linea-rec.recibida').length;
        btnSel.style.display = n > 0 ? '' : 'none';
        var c = btnSel.querySelector('.tr-confirm-sel-count');
        if (c) c.textContent = n;
    };

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

    // "Confirmar todo" (un solo toque): marca TODAS las filas y confirma — caso común
    // "llegó todo", sin tener que tildar una por una.
    window.trModalConfirmarTodo = function () {
        if (!_trModalId) return;
        var box = el('trDetalleBox');
        if (box) box.querySelectorAll('.dtm-linea-rec').forEach(function (r) { r.classList.add('recibida'); });
        window.trModalConfirmar();
    };

    // "Confirmar (N)": confirma SOLO las filas tildadas (.recibida); el resto queda como
    // faltante → el backend marca la nota "Confirmada parcial".
    window.trModalConfirmarSeleccionados = function () {
        if (!_trModalId) return;
        var box = el('trDetalleBox');
        if (!box || box.querySelectorAll('.dtm-linea-rec.recibida').length === 0) return;
        window.trModalConfirmar();
    };

    window.trModalConfirmar = function () {
        if (!_trModalId) return;
        var lineas = trCollectLineas();
        if (!lineas.length) return;
        _trModalSubmitted = true;
        trModalPost(
            DETALLE_URL + '/' + _trModalId + '/recibir',
            { lineas: lineas },
            'Recepción confirmada'
        );
    };

    window.trModalCancelar = function (neNumero) {
        if (!_trModalId) return;
        if (!confirm('¿Cancelar la nota ' + (neNumero || _trModalId) + '? Esta acción no se puede deshacer.')) return;
        _trModalSubmitted = true; // acción explícita → el cierre posterior no auto-guarda
        trModalPost(
            DETALLE_URL + '/' + _trModalId + '/cancelar',
            {},
            'Nota cancelada'
        );
    };

    window.trModalEnviar = function () {
        if (!_trModalId) return;
        _trModalSubmitted = true; // acción explícita → el cierre posterior no auto-guarda
        trModalPost(
            DETALLE_URL + '/' + _trModalId + '/enviar',
            {},
            'Nota enviada'
        );
    };

    // Paginación AJAX
    if (_trBindGlobal) document.addEventListener('click', function (e) {
        var a = e.target.closest('#trPagination a.page-link') || e.target.closest('#trPagination a');
        if (a) { e.preventDefault(); e.stopImmediatePropagation(); window.trLoad(a.href); }
    }, true);

    // Los custom-dropdowns disparan 'dropdown-selection' cuando el usuario elige una
    // opcion. Recargamos la tabla al cambiar el almacen destino (header) o el Estado.
    if (_trBindGlobal) window.addEventListener('dropdown-selection', function (e) {
        var id = e.detail && e.detail.dropdownId;
        // Cambiar Estado/Almacén a mano sale del modo KPI (trKpiFilter fija el Estado
        // sin emitir este evento, así que no se auto-resetea solo).
        if (id === 'trDestHeaderDropdown' || id === 'trEstadoDropdown') { window.trResetKpi(); window.trLoad(); }
    });

    // ── Panel "Filtros avanzados" (botón filter_list, patrón /admin/equipos) ──
    window.trToggleAdvanced = function (ev) {
        if (ev) ev.stopPropagation();
        var p = el('trAdvPanel'); if (!p) return;
        var opening = p.style.display !== 'block';
        // Al abrir el panel cerramos cualquier custom-dropdown que esté activo.
        if (opening && window.closeAllDropdowns) window.closeAllDropdowns(null);
        p.style.display = opening ? 'block' : 'none';
    };
    window.trClearFechas = function () {
        ['trDesde', 'trHasta'].forEach(function (id) { var e = el(id); if (e) e.value = ''; });
        window.trResetKpi();
        window.trLoad();
    };

    // ── KPIs del panel "Resumen de la bandeja" (clic → filtra la bandeja) ──
    // Resalta la métrica activa (anillo blanco) en el panel.
    function trPaintKpi() {
        document.querySelectorAll('.tr-stats-sub[data-kpi]').forEach(function (c) {
            c.classList.toggle('active', _trKpi !== '' && c.dataset.kpi === _trKpi);
        });
    }
    // Sale del modo KPI (lo llaman búsqueda/fechas/estado al cambiar a mano).
    window.trResetKpi = function () { _trKpi = ''; trPaintKpi(); };

    // Deja el dropdown de Estado en BLANCO ("Estado") SOLO visualmente (hidden vacío +
    // placeholder + color neutro + sin X), sin emitir 'dropdown-selection' para no recargar
    // dos veces. Lo usan los KPIs: filtran pendientes vía `kpi`, no vía estado, así que el
    // dropdown debe verse sin filtro de estado activo.
    function trSetEstadoDefault() {
        var hidden = document.querySelector('#trEstadoDropdown input[name="estado"][data-filter-value]');
        if (hidden) hidden.value = '';
        var trigger = document.querySelector('#trEstadoDropdown .dropdown-trigger');
        if (trigger) { trigger.style.background = '#fbfcfd'; trigger.style.borderColor = '#cbd5e0'; }
        var search = document.querySelector('#trEstadoDropdown input[data-filter-search]');
        if (search) { search.value = ''; search.placeholder = 'Estado'; }
        var clearX = document.querySelector('#trEstadoDropdown [data-clear-btn]');
        if (clearX) clearX.style.display = 'none';
    }

    // Clic en una métrica: filtra por ese criterio. Las 3 son de pendientes (ENVIADO);
    // 'recientes'/'urgentes' añaden su ventana de tiempo (la calcula el backend con el
    // mismo criterio que el conteo). 'por_revisar' = todas las pendientes.
    window.trKpiFilter = function (kpi) {
        _trKpi = kpi;
        trSetEstadoDefault();
        ['trDesde', 'trHasta'].forEach(function (id) { var e = el(id); if (e) e.value = ''; });
        trPaintKpi();
        window.trLoad();
    };

    // Resaltado inicial según la URL (o 'por_revisar' = vista default de pendientes).
    (function () {
        var u = new URLSearchParams(window.location.search);
        var k = u.get('kpi');
        if (k === 'recientes' || k === 'urgentes') _trKpi = k;
        else if (!u.get('desde') && !u.get('hasta') && (!u.get('estado') || u.get('estado') === 'ENVIADO')) _trKpi = 'por_revisar';
        trPaintKpi();
    })();
    // Cerrar el panel al hacer clic fuera (ni en el panel ni en su botón).
    // Capture phase (true) para que dispare ANTES de que uicomponents.js llame
    // stopPropagation al manejar un custom-dropdown — de lo contrario el clic en
    // el trigger del Estado nunca llega aquí y el panel queda abierto.
    if (_trBindGlobal) document.addEventListener('click', function (e) {
        var p = el('trAdvPanel');
        if (p && p.style.display === 'block' && !e.target.closest('#trAdvPanel') && !e.target.closest('#trAdvBtn')) {
            p.style.display = 'none';
        }
    }, true);
})();
</script>

{{-- El antiguo modal #entModal ("Registrar entrada directa") fue extraido a su
     pagina propia /admin/almacen/recepcion/nueva — esa ruta ofrece el mismo flujo
     (POST a almacen.movimientos.lote con tipo=ENTRADA) pero con autocomplete de
     producto por codigo o descripcion. El boton "Recepción ODC" del header de
     esta vista linkea directo alla. --}}

@endsection
