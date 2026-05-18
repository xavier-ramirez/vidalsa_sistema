@extends('layouts.estructura_base')

@section('title', 'Recepción de Materiales')

@section('content')
@php
    $reqEstado     = request('estado');
    $reqOrigen     = request('id_almacen_origen');
    // `idAlmacenDestinoActivo` lo provee el controller incluyendo el default-merge por frente
    // del usuario. Es la fuente de verdad — no usamos request('id_almacen_destino') porque
    // el merge del controller no siempre llega al helper global al renderizar el Blade.
    $reqDestino    = $idAlmacenDestinoActivo ?? null;
    $reqSearch     = request('search');           // por NUMERO de nota de entrega

    $reqDesde      = request('desde');
    $reqHasta      = request('hasta');
    // $hayAdv = ¿hay algún filtro activo del PANEL Avanzado? El "Almacén destino" vive en el
    // header (no en el panel) — su estado se refleja en el dropdown del header, no aquí.
    $hayAdv        = $reqDesde || $reqHasta || ($reqEstado && $reqEstado !== 'all') || ($reqOrigen && $reqOrigen !== 'all');

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
    <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
        <h1 class="page-title" style="margin:0;">
            <span class="page-title-line2" style="color:#000;">Recepción de Materiales</span>
        </h1>
        {{-- Separador vertical sutil + selector de almacén destino. Mismo idioma visual
             que /admin/almacen y /admin/almacen/movimientos para que el usuario reconozca
             inmediatamente que "esta es la recepción DE este almacén". --}}
        <span aria-hidden="true" style="display:inline-block;width:1px;height:34px;background:#cbd5e0;flex:0 0 auto;"></span>
        <div style="display:flex;align-items:center;gap:10px;flex:0 1 auto;">
            <span style="font-size:10.5px;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:1px;white-space:nowrap;">Almacén</span>
            <div style="width:260px;min-width:200px;max-width:100%;">
                <div class="custom-dropdown" id="trDestHeaderDropdown" data-filter-type="id_almacen_destino" data-default-label="Todos">
                    <input type="hidden" name="id_almacen_destino" data-filter-value value="{{ $destSel ? $destSel->ID_ALMACEN : '' }}">
                    <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:#f8fafc;overflow:hidden;border:1px solid #cbd5e0;border-radius:10px;height:40px;">
                        <span style="padding:0 10px;display:flex;align-items:center;color:#0067b1;"><i class="material-icons" style="font-size:18px;transform:none !important;">warehouse</i></span>
                        <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                               placeholder="{{ $destSel ? $destSel->NOMBRE : 'Todos los almacenes destino' }}"
                               style="flex:1;border:none;background:transparent;padding:8px 5px;font-size:13.5px;font-weight:600;color:#0f172a;outline:none;min-width:0;"
                               oninput="window.filterDropdownOptions(this)">
                        {{-- X = "ver todos los almacenes destino". Mandamos 'all' explícito (NO
                             clearDropdownFilter que pondría '') para evitar que el controller
                             re-aplique el default por frente. --}}
                        <i class="material-icons" data-clear-btn style="padding:0 8px;color:#64748b;font-size:18px;display:{{ $destSel ? 'block' : 'none' }};cursor:pointer;transform:none !important;"
                           onclick="event.stopPropagation(); selectOption('trDestHeaderDropdown','all','TODOS LOS ALMACENES DESTINO');">close</i>
                    </div>
                    <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                        <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                            <div class="dropdown-item {{ !$destSel ? 'selected' : '' }}" data-value="all" onclick="selectOption('trDestHeaderDropdown','all','TODOS LOS ALMACENES DESTINO');">TODOS LOS ALMACENES DESTINO</div>
                            @foreach(($almacenes ?? collect()) as $a)
                                <div class="dropdown-item {{ $destSel && $destSel->ID_ALMACEN == $a->ID_ALMACEN ? 'selected' : '' }}" data-value="{{ $a->ID_ALMACEN }}"
                                     onclick="selectOption('trDestHeaderDropdown','{{ $a->ID_ALMACEN }}','{{ addslashes($a->NOMBRE) }}');">
                                    {{ $a->NOMBRE }}{{ $a->TIPO === 'GENERAL' ? '' : ' (Proyecto)' }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
    #trFilters { display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin-bottom:10px; }
    #trFilters .tr-item { flex:1 1 220px; min-width:180px; max-width:300px; }
    /* Filtro de N° de nota: ancho fijo y compacto (es un código corto tipo TR-2026-0001,
       no necesita una caja larga). Tiene su propia lista de sugerencias debajo. */
    #trFilters .tr-search-num  { flex:0 0 240px; max-width:240px; min-width:180px; position:relative; }
    .tr-search-box { display:flex; align-items:center; height:45px; border:1px solid #cbd5e0; border-radius:12px; background:#fbfcfd; overflow:hidden; }
    .tr-search-box.active { border-color:#0067b1; background:#e1effa; }
    .tr-search-box i.lupa { padding:0 10px; color:#64748b; font-size:18px; }
    .tr-search-box input { flex:1; border:none; background:transparent; outline:none; padding:10px 5px; font-size:14px; min-width:0; }
    /* Dropdown de sugerencias del N° de nota — calcado del patrón .alm-suggest
       del inventario para consistencia visual entre los dos buscadores. */
    .tr-suggest {
        position:absolute; top:calc(100% + 4px); left:0; right:0;
        background:#fff; border:1px solid #e2e8f0; border-radius:10px;
        box-shadow:0 8px 18px rgba(15,23,42,0.10);
        max-height:240px; overflow-y:auto; padding:4px;
        z-index:60; display:none;
    }
    .tr-suggest.open { display:block; }
    .tr-suggest-item {
        padding:8px 12px; border-radius:6px; cursor:pointer;
        font-family:monospace; font-size:12.5px; font-weight:700; color:#0f172a;
        letter-spacing:0.3px; transition:background .15s;
    }
    .tr-suggest-item:hover, .tr-suggest-item.active { background:#e1effa; color:#0067b1; }
    .tr-suggest-empty { padding:10px 12px; font-size:12px; color:#94a3b8; font-style:italic; }
    /* Tabla con el mismo estilo que /admin/equipos y /admin/almacen (.table-row-header style) */
    .tr-table { width:100%; border-collapse:separate; border-spacing:0; font-size:14px; color:#000; }
    .tr-table thead tr { background:#1e293b; }
    .tr-table thead th { text-align:left; color:#fff; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:1px; padding:10px 15px; border-right:1px solid #334155; border-bottom:2px solid #0f172a; white-space:nowrap; }
    .tr-table thead th:last-child { border-right:none; }
    .tr-table tbody td { padding:12px 15px; color:#000; border-bottom:1px solid #e2e8f0; border-right:1px solid #e2e8f0; }
    .tr-table tbody td:last-child { border-right:none; }
    .tr-table tbody tr:hover td { background:#e0f2fe; cursor:pointer; }
    .estado-pill { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:999px; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.3px; }

    /* ── Responsive mobile (≤768px) — patron calcado de /admin/almacen ──
       Cada hijo de #trFilters toma su propia fila full-width (mismo flujo que
       el modulo Inventario). Titulo oculto, selector de almacen destino full-
       width como header efectivo, boton Filtros Avanzados full-width (no
       icono chiquito perdido), boton "Recepcion ODC" full-width al final. */
    @media (max-width: 768px) {
        /* Viewport principal: por default .main-viewport tiene padding:20px y
           width:98% (estilos_globales.css:92). En /admin/almacen el global lo
           reduce a 8px porque tiene .page-layout-grid; aqui NO tenemos esa
           clase asi que replicamos el override para que el contenedor blanco
           ocupe casi todo el ancho del telefono. */
        .main-viewport { padding-left: 8px !important; padding-right: 8px !important; width: 100% !important; max-width: 100vw !important; box-sizing: border-box !important; padding-top: 12px !important; }
        /* Contenedor blanco (.admin-card): padding interno chico y full-width */
        .admin-card { padding: 4px !important; margin: 0 !important; width: 100% !important; box-sizing: border-box !important; }
        /* Titulo + separador ocultos en mobile */
        .page-title-card .page-title { display: none !important; }
        .page-title-card > div > span[aria-hidden="true"] { display: none !important; }
        /* Cabecera apilada para que el selector de almacen destino ocupe todo el ancho */
        .page-title-card > div { flex-direction: column !important; align-items: stretch !important; gap: 10px !important; }
        .page-title-card > div > div { width: 100% !important; flex: 1 1 100% !important; }
        .page-title-card > div > div > div[style*="width:260px"] { width: 100% !important; min-width: 0 !important; max-width: 100% !important; }

        /* Filtros: cada hijo full-width en su propia fila — igual que /admin/almacen */
        #trFilters { gap: 8px !important; }
        /* Buscar N° de nota */
        #trFilters > .tr-search-num { flex: 1 1 100% !important; max-width: none !important; min-width: 0 !important; }
        /* Wrapper del boton Filtros Avanzados (div con position:relative;flex:0 0 auto inline) */
        #trFilters > div:not(.tr-search-num) { flex: 1 1 100% !important; width: 100% !important; }
        /* Boton dentro del wrapper: pasa de icono 45x45 a fila completa con icono centrado */
        #trFilters > div:not(.tr-search-num) > button { width: 100% !important; height: 45px !important; }
        /* Boton azul "Recepcion ODC" (la <a>): fila propia full-width, centrado.
           Si el usuario no tiene `almacen.movimiento` el directive Blade oculta
           la `<a>` y no pasa nada. */
        #trFilters > a { flex: 1 1 100% !important; width: 100% !important; margin-left: 0 !important; justify-content: center !important; }
        /* Panel Filtros Avanzados desplegado: ancho del viewport (10px de margen lateral) */
        #trAdvPanel { width: calc(100vw - 20px) !important; max-width: calc(100vw - 20px) !important; right: 10px !important; left: auto !important; box-sizing: border-box !important; }
    }
</style>

<div class="admin-card" style="margin:0;min-height:70vh;padding:14px;">

    {{-- ── Filtros (search por N° de nota + filtros avanzados estilo equipos) ──
         trSearch → por NUMERO de la nota de entrega (TR-2026-…) con autocomplete
         sobre la lista pre-cargada (`numerosNotas`). --}}
    <div id="trFilters">
        <div class="tr-item tr-search-num">
            <div class="tr-search-box {{ $reqSearch ? 'active' : '' }}">
                <i class="material-icons lupa">search</i>
                <input type="text" id="trSearch" autocomplete="off" placeholder="N° de nota (TR-…)" value="{{ $reqSearch }}"
                       oninput="window.trSearchInput()"
                       onblur="setTimeout(function(){ var s=document.getElementById('trSearchSuggest'); if(s) s.classList.remove('open'); }, 150);">
            </div>
            {{-- Sugerencias en vivo: lista los N° de nota visibles al usuario que coinciden
                 con lo que está escribiendo. Cargar la lista en el render evita un endpoint
                 extra — son strings cortos y vienen limitados a 300 desde el controller. --}}
            <div id="trSearchSuggest" class="tr-suggest"></div>
        </div>


        <div style="position:relative;flex:0 0 auto;">
            <button type="button" class="btn-primary-maquinaria" title="Filtros Avanzados"
                    style="height:45px;width:45px;padding:0;display:flex;align-items:center;justify-content:center;border-radius:12px;
                           background:{{ $hayAdv ? '#fee2e2' : '#fff' }};border:1px solid {{ $hayAdv ? '#ef4444' : '#cbd5e0' }};color:{{ $hayAdv ? '#ef4444' : '#64748b' }};box-shadow:none;"
                    onclick="window.trToggleAdv(event)">
                <i class="material-icons">filter_list</i>
            </button>
            <div id="trAdvPanel" style="display:none;position:absolute;top:100%;right:0;width:280px;max-width:calc(100vw - 20px);background:#e2e8f0;border:1px solid #cbd5e1;border-radius:12px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.15);z-index:100;margin-top:10px;padding:14px;">
                <h4 style="margin:0 0 12px 0;font-size:14px;font-weight:700;color:#334155;display:flex;justify-content:space-between;align-items:center;">
                    Filtros Avanzados
                    <span style="font-size:11px;color:#64748b;font-weight:400;text-decoration:underline;cursor:pointer;" onclick="window.trClearAdv()">Limpiar Todo</span>
                </h4>
                {{-- Estilo unificado con el resto de filtros de la app (mismo border/radius/altura
                     que los <select> del panel de /admin/almacen/movimientos). Las cajas de
                     fecha envuelven el input para que el clic en CUALQUIER parte abra el picker
                     nativo via showPicker() — sin tener que apuntar al iconito chiquito. --}}
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <div>
                        <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;">Estado</span>
                        <select id="trEstado" onchange="window.trLoad()" style="width:100%;height:36px;border:1px solid #cbd5e0;border-radius:8px;padding:0 10px;background:{{ ($reqEstado && $reqEstado !== 'all') ? '#e1effa' : '#fff' }};font-size:13px;color:#0f172a;outline:none;cursor:pointer;">
                            <option value="all">Todos los estados</option>
                            @foreach($badgesEstado as $k => $b)
                                <option value="{{ $k }}" {{ $reqEstado === $k ? 'selected' : '' }}>{{ $b[0] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;">Almacén origen</span>
                        <select id="trOrigen" onchange="window.trLoad()" style="width:100%;height:36px;border:1px solid #cbd5e0;border-radius:8px;padding:0 10px;background:{{ ($reqOrigen && $reqOrigen !== 'all') ? '#e1effa' : '#fff' }};font-size:13px;color:#0f172a;outline:none;cursor:pointer;">
                            <option value="all">Todos</option>
                            @foreach($almacenes as $a)
                                <option value="{{ $a->ID_ALMACEN }}" {{ (string) $reqOrigen === (string) $a->ID_ALMACEN ? 'selected' : '' }}>{{ $a->NOMBRE }}</option>
                            @endforeach
                        </select>
                    </div>
                    {{-- "Almacén destino" se controla desde el dropdown del header (#trDestHeaderDropdown).
                         Aquí ya no aparece para no tener dos selectores fuera de sincronía. --}}
                    {{-- Desde / Hasta apilados (no en 2 columnas): el panel mide 280px y el
                         input de fecha tiene un ancho minimo nativo del navegador (~110px
                         para mm/dd/yyyy + boton calendario); a 2 columnas el segundo se
                         desbordaba a la derecha. Apilados van full-width y coinciden con
                         el resto de los filtros (Estado / Origen). --}}
                    <div>
                        <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;">Desde</span>
                        <div id="trDesdeBox" style="display:flex;align-items:center;background:{{ $reqDesde ? '#e1effa' : '#fff' }};border:1px solid #cbd5e0;border-radius:8px;height:36px;padding:0 10px;cursor:pointer;"
                             onclick="var i=document.getElementById('trDesde'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                            <i class="material-icons" style="font-size:16px;color:#94a3b8;margin-right:6px;pointer-events:none;">event</i>
                            <input type="date" id="trDesde" value="{{ $reqDesde }}" onchange="window.trLoad()" style="flex:1;min-width:0;border:none;background:transparent;padding:6px 2px;font-size:13px;outline:none;color:#0f172a;cursor:pointer;">
                        </div>
                    </div>
                    <div>
                        <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;">Hasta</span>
                        <div id="trHastaBox" style="display:flex;align-items:center;background:{{ $reqHasta ? '#e1effa' : '#fff' }};border:1px solid #cbd5e0;border-radius:8px;height:36px;padding:0 10px;cursor:pointer;"
                             onclick="var i=document.getElementById('trHasta'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                            <i class="material-icons" style="font-size:16px;color:#94a3b8;margin-right:6px;pointer-events:none;">event</i>
                            <input type="date" id="trHasta" value="{{ $reqHasta }}" onchange="window.trLoad()" style="flex:1;min-width:0;border:none;background:transparent;padding:6px 2px;font-size:13px;outline:none;color:#0f172a;cursor:pointer;">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @can('almacen.movimiento')
        {{-- "Recepción ODC" abre la pagina dedicada de Registrar entrada directa
             (antes era un modal #entModal en esta misma vista — ahora es pantalla
             propia con autocomplete de producto por codigo o descripcion). --}}
        <a href="{{ route('almacen.recepcion.nueva') }}" class="btn-primary-maquinaria"
           style="height:45px;padding:0 16px;display:flex;align-items:center;gap:8px;border-radius:12px;box-shadow:none;margin-left:auto;text-decoration:none;"
           title="Ingresar productos directamente mediante una Orden de Compra">
            <i class="material-icons" style="font-size:18px;">local_shipping</i><span style="font-weight:700;">Recepción ODC</span>
        </a>
        @endcan
    </div>

    {{-- ── Tabla ── --}}
    <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:12px;">
        <table class="tr-table">
            <thead>
                {{-- Tooltips en cada <th>: hover sobre el header explica al usuario que
                     muestra cada columna. Acompañan el lenguaje visual de la app sin
                     ocupar espacio extra de filas guía. --}}
                <tr>
                    <th title="Número correlativo del traspaso (TR-YYYY-NNNN) — único por año. Es la “factura interna” del envío entre almacenes.">Nº</th>
                    <th title="Arriba (negrita) el almacén que ENVÍA; abajo con flecha ↓ el almacén que RECIBE.">Origen → Destino</th>
                    <th style="text-align:center;" title="Borrador · Enviado (esperando confirmación) · Recibido · Parcial (incompleto) · Cancelado.">Estado</th>
                    <th style="text-align:right;" title="Cantidad de productos distintos en el traspaso (no la suma de cantidades — eso lo ves al abrir el detalle).">Líneas</th>
                    <th title="Fecha y hora en que el origen despachó el traspaso. “—” si todavía es borrador.">Enviado</th>
                    <th title="Fecha y hora en que el destino confirmó la recepción. “—” si todavía no llegó o no se confirmó.">Recibido</th>
                    <th title="Usuario que armó el borrador del traspaso (no necesariamente quien lo envió).">Creado por</th>
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

        // Filtrar: prefijo primero, luego substring; máximo 8.
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
        // El backend filtra siempre a "por recibir" (ENVIADO en almacenes visibles).
        // Aquí solo mandamos los filtros del UI (search/estado/origen/destino/fechas).
        var p = new URLSearchParams();
        if (v('trSearch'))                                 p.set('search', v('trSearch'));

        if (v('trEstado')  && v('trEstado')  !== 'all')    p.set('estado', v('trEstado'));
        if (v('trOrigen')  && v('trOrigen')  !== 'all')    p.set('id_almacen_origen', v('trOrigen'));
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

    // Refresca el tinte azul de cada filtro segun si tiene valor activo, y el rojo
    // del boton "Filtros Avanzados" si alguno del panel esta activo. Se llama en
    // trLoad y trClearAdv para mantener UI = estado tras cualquier cambio.
    function trUpdateChips() {
        var paint = function (id, on) { var e = el(id); if (e) e.style.background = on ? '#e1effa' : '#fff'; };
        var sel   = function (id) { var e = el(id); return e ? e.value : ''; };
        var hasEst = sel('trEstado')  && sel('trEstado')  !== 'all';
        var hasOri = sel('trOrigen')  && sel('trOrigen')  !== 'all';
        var hasDes = !!sel('trDesde');
        var hasHas = !!sel('trHasta');
        paint('trEstado',   hasEst);
        paint('trOrigen',   hasOri);
        paint('trDesdeBox', hasDes);
        paint('trHastaBox', hasHas);
        // Boton de embudo: rojo si HAY filtros avanzados aplicados.
        var btn = document.querySelector('[onclick*="trToggleAdv"]');
        if (btn) {
            var any = hasEst || hasOri || hasDes || hasHas;
            btn.style.background  = any ? '#fee2e2' : '#fff';
            btn.style.borderColor = any ? '#ef4444' : '#cbd5e0';
            btn.style.color       = any ? '#ef4444' : '#64748b';
        }
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
    // Limpia SOLO los filtros del panel avanzado (Estado / Origen / Desde / Hasta).
    // El "Almacén destino" vive en el dropdown del header y se limpia con su propia X
    // (clearDropdownFilter) — no se toca aquí para no sorprender al usuario.
    window.trClearAdv = function () {
        ['trEstado','trOrigen'].forEach(function (id) { var e = el(id); if (e) e.value = 'all'; });
        ['trDesde','trHasta'].forEach(function (id) { var e = el(id); if (e) e.value = ''; });
        window.trLoad();
    };

    // El dropdown del header dispara este evento cuando el usuario cambia el almacén
    // destino. Re-carga la tabla con el nuevo filtro.
    window.addEventListener('dropdown-selection', function (e) {
        var id = e.detail && e.detail.dropdownId;
        if (id === 'trDestHeaderDropdown') window.trLoad();
    });

    document.addEventListener('click', function (e) {
        var p = el('trAdvPanel');
        if (p && p.style.display === 'block' && !e.target.closest('#trAdvPanel') && !e.target.closest('[onclick*="trToggleAdv"]')) p.style.display = 'none';
    });
})();
</script>

{{-- El antiguo modal #entModal ("Registrar entrada directa") fue extraido a su
     pagina propia /admin/almacen/recepcion/nueva — esa ruta ofrece el mismo flujo
     (POST a almacen.movimientos.lote con tipo=ENTRADA) pero con autocomplete de
     producto por codigo o descripcion. El boton "Recepción ODC" del header de
     esta vista linkea directo alla. --}}

@endsection
