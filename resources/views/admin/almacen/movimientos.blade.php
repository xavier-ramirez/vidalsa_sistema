@extends('layouts.estructura_base')

@section('title', 'Bitácora de Movimientos de Inventario')

@section('content')
@php
    // `idAlmacenActivo` lo provee el controller (incluyendo el default-merge por frente del
    // usuario). Es la fuente de verdad para preseleccionar el dropdown — no usamos
    // request('id_almacen') porque el `$request->merge(...)` del controller no siempre
    // se refleja en el helper global al renderizar el Blade.
    $reqAlmacen  = $idAlmacenActivo ?? null;
    $reqTipo     = request('tipo');
    $reqFrente   = request('id_frente');
    $reqSearch   = request('search');
    $reqDesde    = request('desde');
    $reqHasta    = request('hasta');
    $reqNota     = request('nota');
    // Filtro Tipo: Entradas (grupo), Salidas (grupo), Auditoría (tipo exacto AJUSTE).
    $tipos = [
        'ENTRADAS' => ['label' => 'Entradas', 'sub' => ''],
        'SALIDAS'  => ['label' => 'Salidas', 'sub' => ''],
        'AJUSTE'   => ['label' => 'Auditoría', 'sub' => ''],
    ];
    $tipoSelLabel = ($reqTipo && isset($tipos[$reqTipo])) ? $tipos[$reqTipo]['label'] . ($tipos[$reqTipo]['sub'] ? ' ' . $tipos[$reqTipo]['sub'] : '') : null;
    // $hayAdv pinta el boton Filtros Avanzados en rojo si HAY filtros aplicados
    // dentro del panel. Tipo vive ahora ahí también — sin esto, seleccionar
    // Entrada/Salida no resaltaría visualmente el botón.
    $hayAdv      = $reqDesde || $reqHasta || $tipoSelLabel || $reqNota;
    $almSel      = $reqAlmacen ? ($almacenes ?? collect())->firstWhere('ID_ALMACEN', (int) $reqAlmacen) : null;
    $frenteSel    = ($reqFrente && $reqFrente !== 'all') ? ($frentesLista ?? collect())->firstWhere('ID_FRENTE', (int) $reqFrente) : null;
@endphp

<section class="page-title-card" style="text-align:left;margin:0 0 10px 0;">
    {{-- Layout: título a la izquierda + separador vertical + filtro de almacén.
         Mismo patrón visual que /admin/almacen para consistencia entre módulos. --}}
    <div style="display:flex;justify-content:flex-start;align-items:center;gap:20px;flex-wrap:wrap;">
        <h1 class="page-title" style="margin:0;">
            <span class="page-title-line2" style="color:#000;">Bitácora de Movimientos de Inventario</span>
        </h1>
        {{-- Separador vertical sutil entre título y filtro --}}
        <span aria-hidden="true" style="display:inline-block;width:1px;height:34px;background:#cbd5e0;flex:0 0 auto;"></span>
        {{-- Filtro de almacén junto al título (mismo patrón visual que /admin/almacen) --}}
        <div style="display:flex;align-items:center;gap:10px;flex:0 1 auto;">
            <div style="width:280px;min-width:200px;max-width:100%;">
                {{-- NO se aplica .filter-active aunque haya almacén seleccionado: el filtro
                     queda con estilo neutro (sin tinte azul) porque junto al título se ve
                     como parte del encabezado, no como un filtro avanzado por accionar.
                     El estado "activo" se lee del nombre del almacén que aparece como
                     placeholder ya seleccionado. --}}
                <div class="custom-dropdown" id="almMovFiltroAlmacen" data-filter-type="id_almacen" data-default-label="Todos los almacenes">
                    <input type="hidden" name="id_almacen" data-filter-value value="{{ $reqAlmacen ?: '' }}">
                    <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:#f8fafc;overflow:hidden;border:1px solid #cbd5e0;border-radius:10px;height:40px;transition:border-color .15s,background .15s;">
                        <span style="padding:0 10px;display:flex;align-items:center;color:#0067b1;"><i class="material-icons" style="font-size:18px;transform:none !important;">warehouse</i></span>
                        <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                               placeholder="{{ $almSel ? $almSel->NOMBRE : 'Todos los almacenes' }}"
                               style="flex:1;border:none;background:transparent;padding:8px 5px;font-size:13.5px;font-weight:600;color:#0f172a;outline:none;min-width:0;"
                               oninput="window.filterDropdownOptions(this)">
                    </div>
                    <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                        <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                            <div class="dropdown-item {{ !$almSel ? 'selected' : '' }}" data-value="all" onclick="selectOption('almMovFiltroAlmacen','all','TODOS LOS ALMACENES');">TODOS LOS ALMACENES</div>
                            @foreach(($almacenes ?? collect()) as $a)
                                <div class="dropdown-item {{ $almSel && $almSel->ID_ALMACEN == $a->ID_ALMACEN ? 'selected' : '' }}" data-value="{{ $a->ID_ALMACEN }}"
                                     onclick="selectOption('almMovFiltroAlmacen','{{ $a->ID_ALMACEN }}','{{ addslashes($a->NOMBRE) }}');">
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
    #almMovFilters { display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin-bottom:8px; }
    #almMovFilters .amf-item { flex:1 1 200px; min-width:170px; max-width:300px; }
    #almMovFilters .amf-search { flex:2 1 280px; max-width:none; }
    #almMovFilters .custom-dropdown { width:100%; }
    /* El filtro de almacén (en el título) NO se resalta en azul cuando está activo */
    #almMovFiltroAlmacen .dropdown-trigger.filter-active {
        background: #f8fafc !important;
        border-color: #cbd5e0 !important;
    }
    .amf-search-box { display:flex; align-items:center; height:45px; border:1px solid #cbd5e0; border-radius:12px; background:#fbfcfd; overflow:hidden; }
    .amf-search-box.active { border-color:var(--maquinaria-blue,#0067b1); background:#e1effa; }
    .amf-search-box i.lupa { padding:0 10px; color:#64748b; font-size:18px; }
    .amf-search-box input { flex:1; border:none; background:transparent; outline:none; padding:10px 5px; font-size:14px; min-width:0; }
    .amf-search-box i.clr { padding:0 10px; color:#64748b; font-size:18px; cursor:pointer; }
    .amf-adv-btn { height:45px; width:45px; padding:0; display:flex; align-items:center; justify-content:center; border-radius:12px; box-shadow:none; }

    /* Sugerencias del filtro de búsqueda — mismo diseño que /admin/almacen */
    .amf-search-wrap { position:relative; }
    .amf-suggest {
        position:absolute; top:calc(100% + 5px); left:0; right:0; background:#fff;
        border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,0.1);
        z-index:1000; max-height:260px; overflow-y:auto; padding:5px; display:none;
    }
    .amf-suggest.open { display:block; animation:slideDown 0.18s ease-out; }
    .amf-suggest-item { display:flex; flex-direction:column; gap:2px; padding:10px 15px; border-radius:8px; cursor:default; transition:background 0.2s; font-weight:600; color:var(--maquinaria-dark-blue,#1e3a5f); }
    .amf-suggest-item:hover, .amf-suggest-item.active { background:#f0f4f8; }
    .amf-suggest-item .nom { font-size:13.5px; color:#475569; font-weight:600; }
    /* CODIGO + descripción en una línea (mismo patrón que el filtro Buscar de
       /admin/almacen): el código va PRIMERO y la descripción ocupa el resto. */
    .amf-suggest-line { display:flex; align-items:flex-start; gap:8px; }
    .amf-suggest-line .nom { flex:1 1 auto; min-width:0; }
    .amf-suggest-cod { font-size:13.5px; font-weight:600; color:#475569; flex:0 0 auto; white-space:nowrap; }
    .amf-suggest-empty { padding:10px 15px; font-size:13px; color:#94a3b8; }
    /* Tabla limpia: thead oscuro + body con TODOS los valores CENTRADOS (verticales y horizontales).
       Sin bordes verticales entre columnas. */
    .alm-mov-table { width:100%; border-collapse:separate; border-spacing:0; font-size:14px; color:#000; }
    .alm-mov-table thead tr { background:#1e293b; color:#fff; }
    .alm-mov-table thead th {
        text-align:center; color:#fff; font-size:13px; font-weight:700;
        text-transform:uppercase; letter-spacing:1px;
        padding:10px 12px; border-bottom:2px solid #0f172a;
        white-space:nowrap;
    }
    .alm-mov-table tbody td {
        padding:12px 12px; color:#000; font-size:14px;
        text-align:center; vertical-align:middle;
        border-bottom:1px solid #e2e8f0;
    }
    .alm-mov-table tbody tr:hover td { background:#e0f2fe; }
    /* Celda "Descripción del producto": ancla del tooltip de usuario (position:relative
       para que el .tooltip-bubble interno se posicione con bottom:100% sobre ella). */
    .alm-mov-table td.col-producto { position:relative; }
    /* Activamos el .tooltip-bubble (clase global de /admin/equipos) al hover de la fila
       completa — mismo patrón que .admin-table tr:hover .tooltip-bubble en
       estilos_globales.css. Replicamos aquí porque la tabla no es .admin-table. */
    .alm-mov-table tbody tr.alm-mov-row:hover .tooltip-bubble {
        opacity:1 !important;
        visibility:visible !important;
        transform:translateY(0) !important;
    }
    /* Botón "deshacer movimiento" — SOLO super.admin (blade lo gatea con can()).
       Casi invisible en reposo; se realza al pasar el mouse por la fila y se pone
       rojo al hover directo. Anclado al borde superior derecho de la celda Ref. */
    .alm-mov-table td.mv-td-ref { position:relative; }
    .alm-mov-undo {
        position:absolute; top:3px; right:3px;
        width:20px; height:20px; padding:0; margin:0;
        display:inline-flex; align-items:center; justify-content:center;
        border:none; border-radius:5px; background:transparent;
        color:#cbd5e1; cursor:pointer; opacity:.08;
        transition:opacity .15s ease, background .15s ease, color .15s ease;
    }
    .alm-mov-table tbody tr.alm-mov-row:hover .alm-mov-undo { opacity:.5; }
    .alm-mov-undo:hover { opacity:1; background:#fee2e2; color:#dc2626; }
    .alm-mov-undo:disabled { cursor:default; opacity:.5; }
    /* Botón "eliminar SOLO del historial" — mismo patrón casi-invisible que el de
       deshacer, pero anclado a su IZQUIERDA (right:26px) y en tono ámbar al hover
       (no rojo) para distinguir la acción: borra el rastro pero NO revierte el stock.
       Ancla a la celda Ref (td.mv-td-ref ya es position:relative, declarado arriba). */
    .alm-mov-purge {
        position:absolute; top:3px; right:26px;
        width:20px; height:20px; padding:0; margin:0;
        display:inline-flex; align-items:center; justify-content:center;
        border:none; border-radius:5px; background:transparent;
        color:#cbd5e1; cursor:pointer; opacity:.08;
        transition:opacity .15s ease, background .15s ease, color .15s ease;
    }
    .alm-mov-table tbody tr.alm-mov-row:hover .alm-mov-purge { opacity:.5; }
    .alm-mov-purge:hover { opacity:1; background:#fef3c7; color:#d97706; }
    .alm-mov-purge:disabled { cursor:default; opacity:.5; }
    /* Observación del lote (NOTAS): en desktop NO va inline en la columna Ref —
       aparece en la burbuja de hover de la fila (junto al usuario que registró).
       En móvil (sin hover) se re-muestra dentro de la tarjeta Ref (media query). */
    .mv-notas-inline { display:none; }
    /* Chip de conteo: visible en todos los viewports. Antes solo aparecia en mobile
       (el desktop dependia del big-counter del sidebar) — el cliente pidio tener
       el conteo siempre a la vista en la parte superior del modulo. */

    /* ── Responsive mobile (≤768px) — patron calcado de /admin/equipos ──
       En mobile, el modulo se compacta así:
         · Titulo de pagina OCULTO (la nav ya indica donde esta el usuario).
         · Selector de almacen full-width como header efectivo.
         · Fila 1 de filtros: Buscar (full-width).
         · Fila 2 de filtros: Frente (flex-grow) + boton Filtros Avanzados (icono
           compacto a la derecha, mismo patron que /admin/equipos).
         · Fila 3: boton "Acciones" full-width.
         · Menu desplegable de Acciones limitado al viewport (no overflow). */
    @media (max-width: 768px) {
        /* Titulo de pagina oculto en mobile + separador vertical (ya no tiene sentido) */
        .page-title-card .page-title { display: none !important; }
        .page-title-card > div > span[aria-hidden="true"] { display: none !important; }
        /* Cabecera apilada para que el selector de almacen ocupe todo el ancho */
        .page-title-card > div { flex-direction: column !important; align-items: stretch !important; gap: 10px !important; }
        .page-title-card > div > div { width: 100% !important; flex: 1 1 100% !important; }
        .page-title-card > div > div > div[style*="width:280px"] { width: 100% !important; min-width: 0 !important; max-width: 100% !important; }

        /* Buscar: fila propia full-width. */
        #almMovFilters { gap: 8px; }
        #almMovFilters > .amf-search { flex: 1 1 100% !important; max-width: none !important; }
        /* Frente: ocupa el resto de su fila (basis 0 hace que comparta espacio
           con el boton Filtros Avanzados — flex:1 los hace flexibles juntos). */
        #almMovFilters > .amf-item:not(.amf-search) { flex: 1 1 0 !important; min-width: 0 !important; max-width: none !important; }
        /* Boton Filtros Avanzados: tamano natural (~45px) a la derecha del Frente,
           NO wrappea a fila propia. Selector: div hijo directo SIN clase .amf-item
           que NO sea el ultimo (el ultimo es Acciones). */
        #almMovFilters > div:not(.amf-item):not(:last-child) { flex: 0 0 auto !important; }
        /* Acciones: fila propia full-width al final (era margin-left:auto). */
        #almMovFilters > div:last-child:not(.amf-item) { width: 100% !important; flex: 1 1 100% !important; margin-left: 0 !important; }
        #btnAccionesMov { width: 100% !important; }
        #splitDropdownMenuMovInv { left: 0 !important; right: 0 !important; min-width: 0 !important; max-width: calc(100vw - 20px) !important; }
        /* Panel de Filtros Avanzados (Tipo + Desde/Hasta): su centrado en mobile
           vive ahora en estilos_globales.css (regla unica para todos los modulos). */

        /* ══════════════════════════════════════════════
           MOBILE CARD LAYOUT — Movimientos
           Cada <tr> es una TARJETA INDEPENDIENTE (como /admin/equipos):
           rounded, con sombra propia, separadas por gap. NO es una lista densa
           con divisores — el cliente lo pidio explicitamente como cards.
             ┌────────────────────────────────────────────────────────┐
             │ Nombre completo del producto (wrap, no truncate)       │
             │                                            +5 UND      │
             │ 📅 18/05/26   [NE-2026-0010]      📍 ASIGNACION UPATA  │
             └────────────────────────────────────────────────────────┘
             gap 10px
             ┌────────────────────────────────────────────────────────┐
             │ Otro producto ...                                      │
             ...

           Stock + Tipo OCULTOS en mobile (cantidad lleva color/signo que ya
           comunica entrada/salida; stock resultante se ve en desktop).
           ══════════════════════════════════════════════ */
        .alm-mov-table thead { display: none !important; }
        /* Wrapper de la tabla: en desktop tiene border+radius para encerrar el
           thead oscuro; en mobile las filas son tarjetas independientes con su
           propio borde, asi que el wrapper queda invisible (sin borde, sin
           radius, sin overflow:auto) para no dibujar una "caja gris" alrededor. */
        .alm-mov-table-wrap {
            border: none !important;
            border-radius: 0 !important;
            overflow: visible !important;
            background: transparent !important;
        }
        .alm-mov-table {
            display: block !important;
            width: 100% !important;
            background: transparent !important;
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            overflow: visible !important;
        }
        .alm-mov-table tbody {
            display: flex !important;
            flex-direction: column !important;
            gap: 8px !important;
            width: 100% !important;
        }

        /* Tarjeta: borde izquierdo coloreado por tipo (--mov-color).
           overflow:hidden: la banda del destino usa margins negativos. */
        .alm-mov-table tr.alm-mov-row {
            display: grid !important;
            grid-template-columns: 1fr auto !important;
            grid-template-rows: auto auto auto !important;
            grid-template-areas:
                "producto cantidad"
                "producto fecha"
                "destino  destino" !important;
            column-gap: 10px !important;
            row-gap: 3px !important;
            background: #fff !important;
            border: 1px solid #e2e8f0 !important;
            border-left: 3px solid var(--mov-color, #94a3b8) !important;
            border-radius: 10px !important;
            box-shadow: 0 1px 4px rgba(0,0,0,0.05) !important;
            margin: 0 !important;
            padding: 10px 12px 0 10px !important;
            overflow: hidden !important;
            position: relative !important;
            transition: box-shadow 0.2s ease, border-color 0.2s ease !important;
            cursor: pointer !important;
        }
        .alm-mov-table tr.alm-mov-row:active {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important;
        }
        /* Seleccionada: azul + muestra burbuja ref */
        .alm-mov-table tr.alm-mov-row.mv-row-selected {
            border: 1px solid var(--maquinaria-blue, #0067b1) !important;
            border-left: 3px solid var(--maquinaria-blue, #0067b1) !important;
            background: #f0f9ff !important;
            box-shadow: 0 4px 12px rgba(0,103,177,0.15) !important;
        }

        /* Cada td: reset desktop, queda como grid cell */
        .alm-mov-table tr.alm-mov-row td {
            display: flex !important;
            align-items: center !important;
            border: none !important;
            padding: 0 !important;
            margin: 0 !important;
            background: transparent !important;
            white-space: normal !important;
            box-sizing: border-box;
            min-width: 0;
        }

        /* OCULTOS en mobile — la pill de tipo (cantidad ya lleva color/signo) + stock.
           Tipo ahora vive DENTRO de la celda de fecha como .mv-tipo-inline. */
        .alm-mov-table tr.alm-mov-row .mv-tipo-inline,
        .alm-mov-table tr.alm-mov-row td.mv-td-stock { display: none !important; }

        /* Tooltip del usuario tampoco aporta en mobile (no hay hover). */
        .alm-mov-table tr.alm-mov-row td .tooltip-bubble { display: none !important; }

        /* Producto: titulo principal — bold, MULTILINE (wrap, no truncate)
           para que se vea el nombre COMPLETO. El cliente lo pidio explicito:
           "la descripcion del producto debe salir completa". */
        .alm-mov-table tr.alm-mov-row td.mv-td-producto {
            grid-area: producto !important;
            font-size: 11.5px !important;
            font-weight: 600 !important;
            color: #0b1c30 !important;
            line-height: 1.35 !important;
            white-space: normal !important;
            word-wrap: break-word !important;
            overflow-wrap: break-word !important;
            display: block !important;
            align-self: flex-start !important;
            /* El td hereda text-align:center de la tabla desktop — en la
               tarjeta movil el producto va alineado a la izquierda. */
            text-align: left !important;
        }
        /* (El código del producto ya usa el mismo tipo de letra/peso que la descripción
           directamente en el partial kardex_rows — ya no necesita override mobile.) */

        /* Cantidad: numero a la derecha, color heredado del inline
           (verde entrada / rojo salida). UM va pegada al numero. Tamano
           moderado (no acapara la tarjeta). */
        .alm-mov-table tr.alm-mov-row td.mv-td-cantidad {
            grid-area: cantidad !important;
            font-size: 12.5px !important;
            font-weight: 800 !important;
            line-height: 1.2 !important;
            justify-content: flex-end !important;
            justify-self: end !important;
            align-self: flex-start !important;
            text-align: right !important;
            gap: 3px !important;
            white-space: nowrap !important;
        }
        .alm-mov-table tr.alm-mov-row td.mv-td-cantidad span {
            font-size: 10px !important;
            font-weight: 600 !important;
        }

        /* Fecha: AHORA va DEBAJO de la cantidad (col 2 fila 2), alineada a la
           derecha. Cliente la sacó de la meta-row para que cantidad y fecha
           formen un bloque visual a la derecha (cuando + dónde), y dejar el
           bottom row solo para ref + destino. */
        .alm-mov-table tr.alm-mov-row td.mv-td-fecha {
            grid-area: fecha !important;
            font-size: 10.5px !important;
            color: #76777d !important;
            font-weight: 500 !important;
            white-space: nowrap !important;
            gap: 3px !important;
            justify-content: flex-end !important;
            justify-self: end !important;
            align-self: flex-start !important;
        }
        .alm-mov-table tr.alm-mov-row td.mv-td-fecha::before {
            content: "calendar_today";
            font-family: 'Material Icons';
            font-size: 13px;
            color: #c6c6cd;
            font-weight: normal;
        }
        /* En las tarjetas (móvil) la hora NO se muestra: la tarjeta es compacta y
           el día ya basta; la hora solo aporta en la tabla de escritorio. */
        .alm-mov-table tr.alm-mov-row td.mv-td-fecha .mv-hora {
            display: none !important;
        }

        .alm-mov-table tr.alm-mov-row td.mv-td-ref {
            display: none !important;
            grid-area: unset !important;
            position: absolute !important;
            bottom: calc(100% + 6px) !important;
            left: 50% !important;
            transform: translateX(-50%) !important;
            background: #1e293b !important;
            color: #fff !important;
            padding: 8px 14px !important;
            border-radius: 10px !important;
            font-size: 11px !important;
            font-weight: 500 !important;
            white-space: normal !important;
            max-width: 260px !important;
            min-width: 100px !important;
            box-shadow: 0 6px 16px rgba(0,0,0,0.2) !important;
            z-index: 50 !important;
            line-height: 1.4 !important;
            text-align: center !important;
            flex-direction: column !important;
            align-items: center !important;
            gap: 6px !important;
            pointer-events: auto !important;
        }
        .alm-mov-table tr.alm-mov-row td.mv-td-ref::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: #1e293b transparent transparent transparent;
        }
        .alm-mov-table tr.alm-mov-row.mv-row-selected td.mv-td-ref {
            display: none !important;
        }
        .alm-mov-table tr.alm-mov-row td.mv-td-ref a {
            background: rgba(255,255,255,0.15) !important;
            color: #fff !important;
            padding: 4px 10px !important;
            border-radius: 6px !important;
            font-family: monospace !important;
            font-size: 11px !important;
            font-weight: 700 !important;
            text-decoration: none !important;
            white-space: nowrap !important;
            border: 1px solid rgba(255,255,255,0.25) !important;
        }
        .alm-mov-table tr.alm-mov-row td.mv-td-ref div {
            font-size: 10px !important;
            color: rgba(255,255,255,0.7) !important;
            margin: 0 !important;
            white-space: normal !important;
            font-style: italic !important;
        }
        .alm-mov-table tr.alm-mov-row td.mv-td-ref .mv-notas-inline {
            display: flex !important;
            justify-content: center !important;
        }

        /* Destino: banda gris inferior full-width */
        .alm-mov-table tr.alm-mov-row td.mv-td-destino {
            grid-area: destino !important;
            justify-content: center !important;
            justify-self: stretch !important;
            font-size: 10.5px !important;
            font-weight: 700 !important;
            color: #3730a3 !important;
            background: #f8fafc !important;
            border-radius: 0 0 8px 8px !important;
            padding: 5px 12px !important;
            margin: 4px -12px 0 -10px !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            max-width: none !important;
            gap: 4px !important;
        }
        .alm-mov-table tr.alm-mov-row td.mv-td-destino::before {
            content: "location_on";
            font-family: 'Material Icons';
            font-size: 13px;
            color: #3730a3;
            font-weight: normal;
            flex-shrink: 0;
        }

        /* Empty state: el <tr><td colspan="7"> del partial — sin tarjeta */
        .alm-mov-table tbody tr:not(.alm-mov-row) {
            display: block !important;
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            padding: 0 !important;
        }
        .alm-mov-table tbody tr:not(.alm-mov-row) td {
            display: block !important;
            text-align: center !important;
            border: none !important;
            padding: 36px 16px !important;
        }
    }
    /* Tablet (768-900px): los filtros se quedan en grilla compacta sin forzar
       full-width — el viewport todavia da para 2 filtros por fila. */
    @media (min-width: 769px) and (max-width: 900px) {
        #almMovFilters .amf-item { flex: 1 1 calc(50% - 6px); max-width: none; }
    }
    /* Desktop (>1024px): SOLO en esta bitacora. Al combinar Fecha+Tipo en una
       sola columna la tabla necesita menos ancho, así que el sidebar (Total
       movimientos / Consumo de Inventario) se ensancha (270→320) para que los
       nombres largos de producto del ranking quepan mejor. El scope
       body:has(.alm-mov-table) limita el override a este modulo. */
    @media (min-width: 1025px) {
        body:has(.alm-mov-table) .page-layout-grid {
            grid-template-columns: minmax(0, 1fr) 370px;
            gap: 24px;
        }
    }
</style>

<div class="page-layout-grid">
<div class="admin-card" style="margin:0;min-height:70vh;min-width:0;width:100%;padding:14px;">

    {{-- ── Filtros ── (el filtro de almacén está junto al título, no aquí) --}}
    <div id="almMovFilters">

        {{-- Buscar producto --}}
        <div class="amf-item amf-search">
            <div class="amf-search-wrap">
                <div class="amf-search-box {{ $reqSearch ? 'active' : '' }}">
                    <i class="material-icons lupa">search</i>
                    {{-- Escribir SOLO refresca las sugerencias — NO dispara la consulta a la tabla.
                         El filtro se aplica cuando el usuario (a) elige una sugerencia, (b) pulsa Enter,
                         o (c) limpia el campo con la X. Mismo patrón que /admin/almacen (filtro Buscar). --}}
                    <input type="text" id="almMovSearch" autocomplete="off" placeholder="Buscar producto (código o descripción)…" value="{{ $reqSearch }}"
                           oninput="window.almMovSuggestFn()"
                           onfocus="window.almMovSuggestFn()"
                           onkeydown="if(event.key==='Enter'){event.preventDefault();window.almMovSuggestHide();window.loadMovimientos();} if(event.key==='Escape') window.almMovSuggestHide();">
                    <i class="material-icons clr" id="almMovSearchClear" style="display:{{ $reqSearch ? 'block' : 'none' }};" onclick="document.getElementById('almMovSearch').value=''; this.style.display='none'; window.almMovSuggestHide(); window.loadMovimientos();">close</i>
                </div>
                <div class="amf-suggest" id="almMovSuggest"></div>
            </div>
        </div>

        {{-- Frente --}}
        <div class="amf-item">
            <div class="custom-dropdown" id="almMovFiltroFrente" data-filter-type="id_frente" data-default-label="Todos los frentes">
                <input type="hidden" name="id_frente" data-filter-value value="{{ $reqFrente && $reqFrente !== 'all' ? $reqFrente : '' }}">
                <div class="dropdown-trigger {{ $frenteSel ? 'filter-active' : '' }}" style="padding:0;display:flex;align-items:center;background:#fbfcfd;overflow:hidden;border:1px solid #cbd5e0;border-radius:12px;height:45px;">
                    {{-- Icono de lupa (consistente con los tres filtros de esta vista). --}}
                    <span style="padding:0 10px;display:flex;align-items:center;color:var(--maquinaria-gray-text);"><i class="material-icons" style="font-size:18px;transform:none !important;">search</i></span>
                    <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                           placeholder="{{ $frenteSel ? $frenteSel->NOMBRE_FRENTE : 'Todos los frentes' }}"
                           style="flex:1;border:none;background:transparent;padding:10px 5px;font-size:14px;outline:none;min-width:0;"
                           oninput="window.filterDropdownOptions(this)">
                    <i class="material-icons" data-clear-btn style="padding:0 5px;color:var(--maquinaria-gray-text);font-size:18px;display:{{ $frenteSel ? 'block' : 'none' }};cursor:pointer;transform:none !important;"
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

        {{-- Filtros Avanzados (mismo estilo que /admin/equipos) — contiene Tipo + rango Desde/Hasta --}}
        <div style="position:relative;flex:0 0 auto;">
            <button type="button" id="btnAdvancedFilterMov" class="btn-primary-maquinaria amf-adv-btn" title="Filtros Avanzados"
                    style="background:{{ $hayAdv ? '#fee2e2' : '#fff' }};border:1px solid {{ $hayAdv ? '#ef4444' : '#cbd5e0' }};color:{{ $hayAdv ? '#ef4444' : '#64748b' }};box-shadow:none;"
                    onclick="window.almMovToggleFechas(event)">
                <i class="material-icons">filter_list</i>
            </button>
            <div id="almMovFechasPanel" style="display:none;position:absolute;top:100%;right:0;width:360px;max-width:calc(100vw - 20px);background:#e2e8f0;border:1px solid #cbd5e1;border-radius:12px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.15);z-index:100;margin-top:10px;padding:15px;">
                <h4 style="margin:0 0 15px 0;font-size:14px;font-weight:700;color:#334155;display:flex;justify-content:space-between;align-items:center;">
                    Filtros Avanzados
                    <span style="font-size:11px;color:#64748b;font-weight:400;text-decoration:underline;cursor:pointer;" onclick="window.almMovLimpiarFechas()">Limpiar Todo</span>
                </h4>
                {{-- Tipo de movimiento — custom-dropdown (estilo general de la app,
                     igual que los filtros de Almacen / Frente). Sin opcion "Todos":
                     la X (data-clear-btn) limpia el filtro y muestra todo. --}}
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;align-items:start;">
                  <div style="min-width:0;">{{-- min-width:0: deja que la columna se encoja al ancho 1fr (sin esto el grid no la achica por debajo del contenido y se desborda del panel). --}}
                    <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;">Tipo</span>
                    <div class="custom-dropdown" id="almMovTipoDropdown" data-filter-type="tipo" data-default-label="Tipo de movimiento">
                        <input type="hidden" name="tipo" data-filter-value value="{{ $reqTipo && $reqTipo !== 'all' ? $reqTipo : '' }}">
                        <div class="dropdown-trigger {{ $tipoSelLabel ? 'filter-active' : '' }}" style="padding:0;display:flex;align-items:center;background:{{ $tipoSelLabel ? '#e1effa' : '#fff' }};overflow:hidden;border:1px solid #cbd5e0;border-radius:8px;height:36px;">
                            <span style="padding:0 8px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:16px;transform:none !important;">search</i></span>
                            <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                                   placeholder="{{ $tipoSelLabel ?: 'Tipo de movimiento' }}"
                                   style="flex:1;border:none;background:transparent;padding:0 4px;font-size:13px;color:#0f172a;outline:none;min-width:0;"
                                   oninput="window.filterDropdownOptions(this)">
                            <i class="material-icons" data-clear-btn style="padding:0 8px;color:#64748b;font-size:18px;display:{{ $tipoSelLabel ? 'block' : 'none' }};cursor:pointer;transform:none !important;"
                               onclick="event.stopPropagation(); clearDropdownFilter('almMovTipoDropdown');">close</i>
                        </div>
                        <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                            <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                                @foreach($tipos as $k => $t)
                                    @php $label = $t['label'] . ($t['sub'] ? ' '.$t['sub'] : ''); @endphp
                                    <div class="dropdown-item {{ $reqTipo === $k ? 'selected' : '' }}" data-value="{{ $k }}"
                                         onclick="selectOption('almMovTipoDropdown','{{ $k }}','{{ addslashes($label) }}');">{{ $label }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                  </div>{{-- /col Tipo --}}
                  <div style="min-width:0;">{{-- col Nota de entrega: filtra por N° de Nota de Entrega (salidas) o por
                            la referencia del proveedor (entradas) — backend: NUMERO_NOTA / REFERENCIA.
                            min-width:0 igual que Tipo: evita el desborde del grid. --}}
                    <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;">Referencia</span>
                    <div style="display:flex;align-items:center;background:{{ $reqNota ? '#e1effa' : '#fff' }};border:1px solid #cbd5e0;border-radius:8px;height:36px;padding:0 4px;">
                        <span style="padding:0 6px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:16px;transform:none !important;">search</i></span>
                        <input type="text" id="almMovNota" autocomplete="off" placeholder="N° nota o referencia..." value="{{ $reqNota }}"
                               oninput="var c=document.getElementById('almMovNotaClear'); if(c) c.style.display=this.value?'block':'none';"
                               onkeyup="if(event.key==='Enter') window.loadMovimientos();" onchange="window.loadMovimientos()"
                               style="flex:1;border:none;background:transparent;padding:0 4px;font-size:13px;color:#0f172a;outline:none;min-width:0;">
                        <i class="material-icons" id="almMovNotaClear" style="padding:0 6px;color:#64748b;font-size:18px;display:{{ $reqNota ? 'block' : 'none' }};cursor:pointer;transform:none !important;"
                           onclick="document.getElementById('almMovNota').value=''; this.style.display='none'; window.loadMovimientos();">close</i>
                    </div>
                  </div>{{-- /col Nota --}}
                </div>
                {{-- Desde + Hasta (2 columnas, mismo grid que Marca/Modelo en /admin/equipos) --}}
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    {{-- Cajas Desde/Hasta: la caja COMPLETA dispara el date picker (no
                         solo el iconito nativo). Click en el contenedor → input.showPicker(),
                         que es la API estándar para abrir el selector de fecha de <input type="date">. --}}
                    <div style="min-width:0;">
                        <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;">Desde</span>
                        <div id="almMovDesdeBox" style="display:flex;align-items:center;background:{{ $reqDesde ? '#e1effa' : 'white' }};border:1px solid #e2e8f0;border-radius:6px;height:32px;padding:0 4px;cursor:pointer;"
                             onclick="var i=document.getElementById('almMovDesde'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                            {{-- Filtro por DÍA exacto: el calendario nativo (type=date) permite
                                 navegar mes/año y elegir el día. El backend (scopePeriodo) acepta
                                 'YYYY-MM-DD' (whereDate >=). Para un mes completo, se elige el 1° y
                                 el último día del mes en Desde/Hasta. --}}
                            <input type="date" id="almMovDesde" value="{{ $reqDesde }}" onchange="window.loadMovimientos()"
                                   style="flex:1;min-width:0;border:none;background:transparent;padding:0;font-size:12px;outline:none;color:#334155;cursor:pointer;">
                            <i class="material-icons" id="almMovDesdeClear"
                               style="display:{{ $reqDesde ? 'inline-flex' : 'none' }};font-size:14px;color:#64748b;cursor:pointer;padding:2px;border-radius:50%;"
                               onclick="event.stopPropagation(); var i=document.getElementById('almMovDesde'); if(i){ i.value=''; } this.style.display='none'; document.getElementById('almMovDesdeBox').style.background='white'; window.loadMovimientos();">close</i>
                        </div>
                    </div>
                    <div style="min-width:0;">
                        <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;">Hasta</span>
                        <div id="almMovHastaBox" style="display:flex;align-items:center;background:{{ $reqHasta ? '#e1effa' : 'white' }};border:1px solid #e2e8f0;border-radius:6px;height:32px;padding:0 4px;cursor:pointer;"
                             onclick="var i=document.getElementById('almMovHasta'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                            {{-- Filtro por DÍA exacto (type=date). El backend (scopePeriodo) acepta
                                 'YYYY-MM-DD' (whereDate <=). --}}
                            <input type="date" id="almMovHasta" value="{{ $reqHasta }}" onchange="window.loadMovimientos()"
                                   style="flex:1;min-width:0;border:none;background:transparent;padding:0;font-size:12px;outline:none;color:#334155;cursor:pointer;">
                            <i class="material-icons" id="almMovHastaClear"
                               style="display:{{ $reqHasta ? 'inline-flex' : 'none' }};font-size:14px;color:#64748b;cursor:pointer;padding:2px;border-radius:50%;"
                               onclick="event.stopPropagation(); var i=document.getElementById('almMovHasta'); if(i){ i.value=''; } this.style.display='none'; document.getElementById('almMovHastaBox').style.background='white'; window.loadMovimientos();">close</i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Botón Acciones (dropdown estilo /admin/movilizaciones) ────────────
             Reemplaza al viejo botón "Inventario" y consolida las acciones
             rápidas de la bitácora en un único menú:
               · Eliminar Nota de Entrega por código  (requiere almacen.nota.eliminar)
               · Volver al inventario
         --}}
        <div style="position:relative;flex:0 0 auto;margin-left:auto;">
            <button type="button" id="btnAccionesMov" class="btn-primary-maquinaria"
                    style="padding:0 15px;height:45px;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);"
                    onclick="window.toggleAccionesMovInv(event)">
                <i class="material-icons" style="font-size:18px;">settings</i>
                <span>Acciones</span>
                <i class="material-icons" style="font-size:18px;margin-left:2px;">expand_more</i>
            </button>

            <div id="splitDropdownMenuMovInv"
                 style="display:none;position:absolute;top:calc(100% + 5px);right:0;min-width:260px;background:#e2e8f0;border:1px solid #cbd5e1;border-radius:10px;box-shadow:0 10px 20px -5px rgba(15,23,42,0.18);z-index:50;overflow:hidden;">
                {{-- Dashboard de Consumo: abre el modal con gráficos (Chart.js) sobre las
                     salidas, respetando los filtros activos. --}}
                <div style="padding:6px;border-bottom:1px solid #cbd5e1;">
                    <button type="button"
                        onclick="document.getElementById('splitDropdownMenuMovInv').style.display='none'; window.abrirConsumoDashboard();"
                        style="width:100%;display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:6px;border:none;background:transparent;color:#475569;font-size:13px;font-weight:700;cursor:pointer;text-align:left;transition:background 0.15s;"
                        onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='transparent'">
                        <div style="background:#e0f2fe;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;line-height:1;color:#0067b1;">insights</i></div>
                        <span>Dashboard de consumo</span>
                    </button>
                </div>
                {{-- Bitácora por Nota: vista alterna agrupada por NUMERO_NOTA — una fila por
                     Nota de Entrega; clic abre el PDF oficial. Conserva los filtros activos. --}}
                <div style="padding:6px;border-bottom:1px solid #cbd5e1;">
                    <a id="lnkBitNotas" href="{{ route('almacen.notas') }}"
                        style="width:100%;display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:6px;border:none;background:transparent;color:#475569;font-size:13px;font-weight:700;cursor:pointer;text-align:left;transition:background 0.15s;text-decoration:none;"
                        onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='transparent'"
                        onclick="document.getElementById('splitDropdownMenuMovInv').style.display='none';">
                        <div style="background:#dcfce7;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;line-height:1;color:#16a34a;">description</i></div>
                        <span>Bitácora por Nota (PDF)</span>
                    </a>
                </div>
                @can('almacen.nota.eliminar')
                {{-- Eliminar Nota: gateado a la clave almacen.nota.eliminar porque reversa
                     stock y deja un par (SALIDA original + ENTRADA reversa) en el kardex. --}}
                <div style="padding:6px;border-top:1px solid #cbd5e1;">
                    <button type="button"
                        onclick="document.getElementById('splitDropdownMenuMovInv').style.display='none'; window.openEliminarNotaModal();"
                        style="width:100%;display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:6px;border:none;background:transparent;color:#475569;font-size:13px;font-weight:700;cursor:pointer;text-align:left;transition:background 0.15s;"
                        onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='transparent'">
                        <div style="background:#fee2e2;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;line-height:1;color:#dc2626;">delete</i></div>
                        <span>Eliminar Nota por código</span>
                    </button>
                </div>
                @endcan

            </div>
        </div>
    </div>

    {{-- ── Tabla ── --}}
    {{-- En mobile el wrapper pierde border + border-radius (la regla en
         @media ≤768px) — sino se veia una "caja gris" envolviendo todas las
         tarjetas que ya tienen su propio borde redondeado. --}}
    <div class="alm-mov-table-wrap" style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:12px;">
        <table class="alm-mov-table">
            <thead>
                {{-- Anchos rebalanceados: la columna "Descripción del producto" es la única flexible
                     (sin width) para que absorba el espacio restante y se vea proporcional. Las demás
                     llevan ancho fijo acorde al contenido típico. --}}
                <tr>
                    {{-- Fecha + Tipo combinados en una sola columna (la pill de tipo va
                         debajo de la fecha en cada fila). Se eliminó la columna Tipo. --}}
                    <th style="width:120px;">Fecha</th>
                    <th>Descripción del producto</th>
                    <th style="width:110px;">Cantidad</th>
                    <th style="width:55px;">Stock</th>
                    <th style="width:215px;">Destino</th>
                    <th style="width:150px;">Referencia</th>
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

{{-- Sidebar: contador + ranking de consumo (mismas dimensiones que /admin/equipos) --}}
<div class="counter-sidebar" style="position:sticky;top:20px;display:flex;flex-direction:column;gap:8px;">
    <div style="background:linear-gradient(135deg,#0c4a6e 0%,#0369a1 100%);border-radius:12px;padding:15px;color:#fff;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);position:relative;overflow:hidden;">
        <i class="material-icons" style="position:absolute;right:-12px;bottom:-12px;font-size:78px;opacity:0.12;transform:rotate(-12deg);">receipt_long</i>
        <div style="position:relative;z-index:2;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;opacity:0.9;margin-bottom:5px;">Total movimientos</div>
            <div style="display:flex;align-items:baseline;gap:6px;">
                <span id="almMovTotal" style="font-size:32px;font-weight:800;line-height:1;letter-spacing:-1px;">{{ $total }}</span>
                <span style="font-size:12px;opacity:0.85;font-weight:500;">registros (según filtros)</span>
            </div>
        </div>
    </div>

    {{-- Ranking de Consumo: mismo wrapper que el card de Distribución en /admin/equipos y /admin/almacen --}}
    <div style="background:white;border-radius:12px;padding:15px;border:1px solid #e2e8f0;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);overflow:hidden;">
        <div id="almMovConsumoContainer">
            @include('admin.almacen.partials.consumo_stats', ['consumo' => $consumo])
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
    // Busca los hidden de los filtros por name. NOTA: no restringimos a #almMovFilters porque el dropdown de
    // almacén vive ahora junto al título (fuera de ese contenedor); el atributo data-filter-value es el ancla.
    // Acepta tanto `<input type=hidden>` (custom-dropdowns: almacen, frente) como
    // `<select>` (tipo — vive ahora dentro del panel Filtros Avanzados).
    function hv(name) { var e = document.querySelector('[name="' + name + '"][data-filter-value]'); return e ? String(e.value).trim() : ''; }

    function buildParams(pageUrl) {
        var p = new URLSearchParams();
        // OJO: cuando el usuario elige "Todos los almacenes" mandamos `id_almacen=all`
        // explícito. Si no lo enviamos, el controller cree que NO vino el param y
        // aplica el default-por-frente, volviendo a filtrar (el usuario veía solo su
        // almacén aunque hubiera pedido "todos").
        var alm = hv('id_almacen'); if (alm) p.set('id_almacen', alm);
        var tipo = hv('tipo'); if (tipo && tipo !== 'all') p.set('tipo', tipo);
        var fr = hv('id_frente'); if (fr && fr !== 'all') p.set('id_frente', fr);
        var s = el('almMovSearch'); if (s && s.value.trim()) p.set('search', s.value.trim());
        var d = el('almMovDesde'); if (d && d.value) p.set('desde', d.value);
        var h = el('almMovHasta'); if (h && h.value) p.set('hasta', h.value);
        var nt = el('almMovNota'); if (nt && nt.value.trim()) p.set('nota', nt.value.trim());
        // conservar id_producto si vino en la URL (al entrar desde el detalle de un producto)
        var urlProd = new URLSearchParams(window.location.search).get('id_producto');
        if (urlProd) p.set('id_producto', urlProd);
        // pageUrl presente = paginación: el ranking de consumo no cambia entre páginas,
        // así que pedimos al backend que lo omita (skip_consumo) y no recalcule el agregado.
        if (pageUrl) {
            try { var pg = new URL(pageUrl, window.location.origin).searchParams.get('page'); if (pg) p.set('page', pg); } catch (e) {}
            p.set('skip_consumo', '1');
        }
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
                { var e = el('almMovTotal'); if (e && data.total !== undefined) e.textContent = data.total; }
                // Refresca el ranking de consumo del sidebar (mismas dimensiones que /admin/equipos).
                var cc = el('almMovConsumoContainer'); if (cc && data.consumo !== undefined) cc.innerHTML = data.consumo;
                // marca "activo" del buscador
                var sb = document.querySelector('.amf-search-box'); var si = el('almMovSearch');
                if (sb && si) sb.classList.toggle('active', !!si.value.trim());
                var sc = el('almMovSearchClear'); if (sc && si) sc.style.display = si.value.trim() ? 'block' : 'none';
                try { window.history.replaceState(null, '', url); } catch (e) {}
            })
            .catch(function () { body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:#dc2626;">No se pudieron cargar los movimientos.</td></tr>'; })
            .finally(function () { body.style.opacity = '1'; if (window.hidePreloader) window.hidePreloader(); });
    };

    // ── Lista de productos para el autocomplete del filtro de búsqueda ──
    window.almMovProductosLista = @json(($productosLista ?? collect())->map(fn($p) => ['ID_PRODUCTO' => $p->ID_PRODUCTO, 'CODIGO' => $p->CODIGO, 'NOMBRE' => $p->NOMBRE]));

    function almMovNorm(s) { return s ? String(s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase() : ''; }
    // \u2500\u2500 Buscador "estilo Google" \u2014 fuzzy + ranking por relevancia \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
    //   Mismo criterio que el filtro Descripcion de /admin/almacen: tokeniza,
    //   descarta stopwords y numeros sueltos cortos, tolera errores de tipeo
    //   (distancia de edicion) y RANKEA por score en vez de exigir el match
    //   exacto de todos los tokens.
    var ALM_MOV_STOP = { de:1, del:1, la:1, el:1, los:1, las:1, un:1, una:1,
                         unos:1, unas:1, y:1, e:1, o:1, u:1, a:1, en:1, con:1,
                         para:1, por:1 };
    function almMovLeven(a, b, max) {
        var la = a.length, lb = b.length;
        if (la === 0) return lb;
        if (lb === 0) return la;
        if (Math.abs(la - lb) > max) return max + 1;
        var prev = [], cur = [], i, j;
        for (j = 0; j <= lb; j++) prev[j] = j;
        for (i = 1; i <= la; i++) {
            cur[0] = i;
            var best = i;
            for (j = 1; j <= lb; j++) {
                var cost = a.charCodeAt(i - 1) === b.charCodeAt(j - 1) ? 0 : 1;
                cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + cost);
                if (cur[j] < best) best = cur[j];
            }
            if (best > max) return max + 1;
            for (j = 0; j <= lb; j++) prev[j] = cur[j];
        }
        return prev[lb];
    }
    function almMovTokenizar(raw) {
        var crudos = almMovNorm(raw || '').split(/\s+/).filter(Boolean);
        var sig = crudos.filter(function (t) {
            return !ALM_MOV_STOP[t] && !/^\d{1,2}$/.test(t);
        });
        return sig.length ? sig : crudos;
    }
    function almMovScoreToken(palabras, hayFull, token) {
        var idx = hayFull.indexOf(token);
        if (idx > -1) {
            var s = 12;
            if (idx === 0) s += 12;
            else if (hayFull.charAt(idx - 1) === ' ') s += 7;
            return { score: s, hit: true };
        }
        var tol = token.length <= 4 ? 1 : 2;
        var mejor = tol + 1;
        for (var i = 0; i < palabras.length; i++) {
            var w = palabras[i];
            if (!w) continue;
            var d = almMovLeven(token, w, tol);
            if (d < mejor) mejor = d;
            if (w.length > token.length) {
                var dp = almMovLeven(token, w.substr(0, token.length), tol);
                if (dp < mejor) mejor = dp;
            }
            if (mejor === 0) break;
        }
        if (mejor <= tol) return { score: 7 - mejor * 2, hit: true };
        return { score: 0, hit: false };
    }
    window.almMovSuggestHide = function () { var b = document.getElementById('almMovSuggest'); if (b) b.classList.remove('open'); };
    window.almMovSuggestFn = function () {
        var inp = document.getElementById('almMovSearch'), box = document.getElementById('almMovSuggest');
        if (!inp || !box) return;
        var rawTerm = inp.value.trim();
        var tokens = almMovTokenizar(rawTerm);
        var lista = window.almMovProductosLista || [];
        var matches = [];
        if (tokens.length === 0) {
            for (var i = 0; i < lista.length && matches.length < 12; i++) matches.push(lista[i]);
        } else {
            // Scoring: por cada producto sumamos el score de cada token (substring
            // fuerte / fuzzy debil). Candidato si matchea >= la mitad de los tokens.
            // Bonus por todos los tokens, frase completa y nombre corto.
            var rawNorm = almMovNorm(rawTerm).replace(/\s+/g, ' ');
            var minTokens = Math.ceil(tokens.length / 2);
            var scored = [];
            for (var j = 0; j < lista.length; j++) {
                var p = lista[j];
                var nom = almMovNorm(p.NOMBRE || '');
                var hayFull = almMovNorm((p.CODIGO || '') + ' ' + (p.NOMBRE || ''));
                var palabras = hayFull.split(/\s+/).filter(Boolean);
                var total = 0, matched = 0;
                for (var k = 0; k < tokens.length; k++) {
                    var r = almMovScoreToken(palabras, hayFull, tokens[k]);
                    if (r.hit) { matched++; total += r.score; }
                }
                if (matched < minTokens) continue;
                if (matched === tokens.length) total += 25;
                if (rawNorm && nom.indexOf(rawNorm) > -1) total += 30;
                total += Math.max(0, 20 - nom.length * 0.15);
                scored.push({ p: p, score: total });
            }
            scored.sort(function (a, b) {
                if (b.score !== a.score) return b.score - a.score;
                return String(a.p.NOMBRE || '').localeCompare(String(b.p.NOMBRE || ''));
            });
            for (var sx = 0; sx < scored.length && sx < 12; sx++) matches.push(scored[sx].p);
        }
        var html = '';
        if (!matches.length) {
            html = '<div class="amf-suggest-empty">Sin coincidencias.</div>';
        } else {
            html = matches.map(function (p) {
                var nom = (p.NOMBRE || '').replace(/[<>&"]/g, '');
                var cod = (p.CODIGO || '').replace(/[<>&"]/g, '');
                // CODIGO primero + descripción, igual que el filtro Buscar de /admin/almacen.
                var codBadge = cod ? '<span class="amf-suggest-cod">' + cod + '</span>' : '';
                return '<div class="amf-suggest-item" data-pick="' + nom + '" title="' + cod + '">'
                     + '<div class="amf-suggest-line">' + codBadge + '<span class="nom">' + nom + '</span></div>'
                     + '</div>';
            }).join('');
        }
        box.innerHTML = html;
        box.classList.add('open');
    };
    // Delegación de clic en sugerencias
    document.addEventListener('click', function (e) {
        var item = e.target.closest('#almMovSuggest .amf-suggest-item');
        if (item) {
            var inp = document.getElementById('almMovSearch');
            if (inp) inp.value = item.getAttribute('data-pick') || '';
            window.almMovSuggestHide();
            window.loadMovimientos();
            return;
        }
        if (!e.target.closest('#almMovSearch') && !e.target.closest('#almMovSuggest')) window.almMovSuggestHide();
    });

    // Selección en los custom-dropdown → recargar
    window.addEventListener('dropdown-selection', function (e) {
        if (!document.getElementById('almMovTableBody')) return;
        var id = e.detail && e.detail.dropdownId;
        if (id === 'almMovFiltroAlmacen' || id === 'almMovFiltroFrente' || id === 'almMovTipoDropdown') {
            window.loadMovimientos();
        }
    });

    // Click en una fila del ranking de consumo → pega el nombre del producto en el
    // buscador y recarga la tabla. El filtro backend hace LIKE %nombre%, suficiente
    // para acotar a los movimientos de ese producto sin requerir id_producto exacto.
    window.almMovFiltrarPorProducto = function (_idProducto, nombre) {
        var inp = el('almMovSearch');
        if (inp) {
            inp.value = nombre || '';
            var sb = document.querySelector('.amf-search-box'); if (sb) sb.classList.add('active');
            var sc = el('almMovSearchClear'); if (sc) sc.style.display = inp.value ? 'block' : 'none';
        }
        window.almMovSuggestHide && window.almMovSuggestHide();
        window.loadMovimientos();
    };

    // Paginación AJAX
    document.addEventListener('click', function (e) {
        var a = e.target.closest('#almMovPagination a.page-link') || e.target.closest('#almMovPagination a');
        if (a) { e.preventDefault(); e.stopImmediatePropagation(); window.loadMovimientos(a.href); }
    }, true);

    // ── Seleccion de tarjeta en mobile (toggle azul + revela burbuja NE-AAAA-NNNN) ──
    // Mismo patron de UX que /admin/equipos: tocar la tarjeta la resalta en azul y
    // muestra una burbuja flotante con la info de referencia del movimiento
    // (N° Nota NE-AAAA-NNNN + REFERENCIA + Proveedor + Observaciones, lo que aplique)
    // por encima del centro de la tarjeta.
    //
    // Early returns para que clicks en la burbuja NO togglee la seleccion:
    //   - Click dentro de .mv-td-ref (burbuja entera, incluido el <a> del PDF):
    //     deja que el onclick del enlace se ejecute (abre PDF preview); no togglear.
    document.addEventListener('click', function (e) {
        if (e.target.closest('#almMovTableBody .mv-td-ref')) return;
        var tr = e.target.closest('#almMovTableBody tr.alm-mov-row');
        if (!tr) return;
        document.querySelectorAll('#almMovTableBody tr.alm-mov-row.mv-row-selected').forEach(function (other) {
            if (other !== tr) other.classList.remove('mv-row-selected');
        });
        var wasSelected = tr.classList.contains('mv-row-selected');
        tr.classList.toggle('mv-row-selected');
        if (!wasSelected && window.innerWidth <= 768) {
            var pdfLink = tr.querySelector('.mv-nota-link[data-pdf-url]');
            if (pdfLink && typeof window.openPdfPreview === 'function') {
                window.openPdfPreview(pdfLink.dataset.pdfUrl, 'nota_entrega', pdfLink.dataset.pdfTitle || 'Nota de Entrega', 0, '', true, 'almacen');
            }
        }
    });

    // Panel de fechas
    window.almMovToggleFechas = function (ev) {
        if (ev) ev.stopPropagation();
        var p = el('almMovFechasPanel'); if (!p) return;
        var m = el('splitDropdownMenuMovInv'); if (m) m.style.display = 'none';
        
        // Forma correcta de cerrar los custom-dropdowns de uicomponents.js
        if (typeof window.closeAllDropdowns === 'function') window.closeAllDropdowns();
        document.querySelectorAll('.custom-dropdown.active').forEach(d => d.classList.remove('active'));
        // Limpiar cualquier estilo inline residual que hayamos inyectado por error
        document.querySelectorAll('.dropdown-content').forEach(d => d.style.display = '');

        p.style.display = (p.style.display === 'block') ? 'none' : 'block';
    };
    // "Limpiar Todo" del panel: limpia Desde/Hasta + el filtro Tipo (custom-dropdown).
    // clearDropdownFilter ya emite el evento dropdown-selection — el listener de arriba
    // dispara loadMovimientos, asi que no lo llamamos dos veces.
    window.almMovLimpiarFechas = function () {
        if (el('almMovDesde')) el('almMovDesde').value = '';
        if (el('almMovHasta')) el('almMovHasta').value = '';
        if (document.getElementById('almMovTipoDropdown')
            && typeof window.clearDropdownFilter === 'function') {
            window.clearDropdownFilter('almMovTipoDropdown');
        } else {
            window.loadMovimientos();
        }
    };
    document.addEventListener('click', function (e) {
        var p = el('almMovFechasPanel');
        if (p && p.style.display === 'block' && !e.target.closest('#almMovFechasPanel') && !e.target.closest('.amf-adv-btn')) p.style.display = 'none';
    });

    // ── Dropdown "Acciones" (toggle + cierre al click fuera) ────────────────
    // Mismo patrón del dropdown de /admin/movilizaciones (toggleAccionesMov):
    // sufijo "Inv" para no colisionar con el del módulo de movilizaciones si
    // ambos cargan en una SPA.
    window.toggleAccionesMovInv = function (ev) {
        if (ev) ev.stopPropagation();
        var m = el('splitDropdownMenuMovInv'); if (!m) return;
        var p = el('almMovFechasPanel'); if (p) p.style.display = 'none';

        // Forma correcta de cerrar los custom-dropdowns de uicomponents.js
        if (typeof window.closeAllDropdowns === 'function') window.closeAllDropdowns();
        document.querySelectorAll('.custom-dropdown.active').forEach(d => d.classList.remove('active'));
        // Limpiar cualquier estilo inline residual
        document.querySelectorAll('.dropdown-content').forEach(d => d.style.display = '');

        // Refrescar el href del link "Bitácora por Nota" con los filtros activos para
        // que la vista por nota se abra ya filtrada por almacén / frente / fechas / tipo.
        // Solo reenviamos el tipo si es el grupo "SALIDAS" (la vista por nota únicamente
        // lista notas de salida; bitacoraNotas ignora un tipo que no sea de salida).
        var lnk = el('lnkBitNotas');
        if (lnk) {
            var p2 = new URLSearchParams();
            var alm = hv('id_almacen'); if (alm) p2.set('id_almacen', alm);
            var fr  = hv('id_frente'); if (fr && fr !== 'all') p2.set('id_frente', fr);
            var tp  = hv('tipo'); if (tp === 'SALIDAS') p2.set('tipo', tp);
            var s   = el('almMovSearch'); if (s && s.value.trim()) p2.set('search', s.value.trim());
            var d   = el('almMovDesde'); if (d && d.value) p2.set('desde', d.value);
            var h   = el('almMovHasta'); if (h && h.value) p2.set('hasta', h.value);
            var base = @json(route('almacen.notas'));
            var qs = p2.toString();
            lnk.href = base + (qs ? ('?' + qs) : '');
        }

        m.style.display = (m.style.display === 'block') ? 'none' : 'block';
    };

    // Cerrar nuestros paneles personalizados si se abre un .custom-dropdown estándar
    // FUERA del panel. Antes este handler cerraba el panel SIEMPRE que se clickee
    // un .dropdown-trigger — bug: el filtro "Tipo" vive DENTRO del panel y su
    // trigger es un .dropdown-trigger tambien, asi que al tocarlo el panel se
    // cerraba antes de que su lista pudiera abrirse. Ahora solo cerramos si el
    // trigger esta FUERA del contenedor correspondiente.
    document.addEventListener('click', function (e) {
        var t = e.target.closest('.dropdown-trigger');
        if (!t) return;
        if (!t.closest('#almMovFechasPanel')) {
            var p = el('almMovFechasPanel'); if (p) p.style.display = 'none';
        }
        if (!t.closest('#splitDropdownMenuMovInv')) {
            var m = el('splitDropdownMenuMovInv'); if (m) m.style.display = 'none';
        }
    }, true);
    document.addEventListener('click', function (e) {
        var m = el('splitDropdownMenuMovInv');
        if (m && m.style.display === 'block' && !e.target.closest('#splitDropdownMenuMovInv') && !e.target.closest('#btnAccionesMov')) m.style.display = 'none';
    });
})();
</script>

{{-- Modal "Dashboard de Consumo" (abierto desde el menú Acciones). Compartido con
     /admin/almacen — misma vista parcial, mismo endpoint. DEBE ir FUERA de @can:
     el botón que lo abre es visible para todos, así que el modal y su <script>
     (window.abrirConsumoDashboard) tienen que existir siempre. Antes estaba anidado
     dentro de @can('almacen.nota.eliminar') y no abría para quien no tuviera ese permiso. --}}
@include('admin.almacen.partials.consumo_dashboard_modal')

@can('almacen.nota.eliminar')
{{-- ═════════════════════════════════════════════════════════════════
     MODAL: ELIMINAR NOTA DE ENTREGA POR CÓDIGO  (requiere almacen.nota.eliminar)
     Ingresa NE-YYYY-NNNN → confirma → DELETE → reversa stock.
═════════════════════════════════════════════════════════════════ --}}
{{-- Diseño alineado con el patron de alertas de la app (#standardModal de
     estructura_base.blade.php): card blanca completa, icono centrado arriba,
     titulo + mensaje centrados, botones al pie. Sin banner rojo de cabecera y
     sin icono en el boton de confirmar — el color rojo del CTA basta como
     senal de accion destructiva. --}}
<div id="eliminarNotaOverlay"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);backdrop-filter:blur(3px);z-index:10000;align-items:center;justify-content:center;padding:20px;"
     onclick="if(event.target===this) window.closeEliminarNotaModal()">
    {{-- overflow:visible: la lista de sugerencias del buscador de Notas cuelga
         por debajo del input y debe poder salirse del recuadro de la tarjeta. --}}
    <div style="background:#fff;width:100%;max-width:360px;border-radius:14px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.35);overflow:visible;animation:notaIn 0.22s cubic-bezier(0.16,1,0.3,1);position:relative;">
        <button type="button" onclick="window.closeEliminarNotaModal()" aria-label="Cerrar"
            style="background:transparent;border:none;color:#94a3b8;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;position:absolute;top:10px;right:10px;z-index:2;">
            <i class="material-icons" style="font-size:20px;">close</i>
        </button>
        <div style="padding:18px 20px 14px;display:flex;flex-direction:column;gap:10px;text-align:center;">
            <i class="material-icons" style="font-size:34px;color:#dc2626;margin:0 auto;">error</i>
            <div>
                <h2 style="margin:0 0 4px;font-size:16px;font-weight:800;color:#0f172a;">Eliminar Nota de Entrega</h2>
                <p style="margin:0;font-size:12.5px;color:#475569;line-height:1.45;">
                    El stock vuelve al almacén y la Nota queda eliminada. Acción <b>irreversible</b>.
                </p>
            </div>
            <div style="position:relative;">
                <div id="eliminarNotaBox"
                     style="display:flex;align-items:center;border:1px solid #cbd5e0;border-radius:8px;background:#fbfcfd;overflow:hidden;transition:border-color 0.2s,box-shadow 0.2s;height:34px;">
                    <i class="material-icons" style="padding:0 8px;color:#94a3b8;font-size:18px;flex-shrink:0;">search</i>
                    <input type="text" id="eliminarNotaInput" placeholder="N° de Nota a eliminar" autocomplete="off"
                        style="flex:1;border:none;outline:none;padding:0 6px;font-size:13px;background:transparent;letter-spacing:0.5px;text-transform:uppercase;height:100%;"
                        oninput="window.eliminarNotaSuggest()"
                        onblur="setTimeout(function(){ var s=document.getElementById('eliminarNotaSuggest'); if(s) s.classList.remove('open'); }, 150);"
                        onkeydown="if(event.key==='Enter'){event.preventDefault(); window.submitEliminarNota();}">
                </div>
                <div id="eliminarNotaSuggest"></div>
            </div>
            <div id="eliminarNotaFeedback" style="display:none;padding:8px 10px;border-radius:8px;font-size:12px;font-weight:600;text-align:left;"></div>
            <div style="display:flex;gap:10px;justify-content:center;margin-top:2px;">
                <button type="button" onclick="window.closeEliminarNotaModal()"
                    style="padding:8px 18px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:13px;font-weight:700;cursor:pointer;">
                    Cancelar
                </button>
                <button type="button" id="eliminarNotaSubmitBtn" onclick="window.submitEliminarNota()"
                    style="padding:8px 22px;border-radius:8px;border:none;background:#dc2626;color:#fff;font-size:13px;font-weight:800;cursor:pointer;">
                    Eliminar
                </button>
            </div>
        </div>
    </div>
</div>
@endcan

<style>
@keyframes notaIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
#eliminarNotaBox:focus-within { border-color:#b91c1c; box-shadow:0 0 0 3px rgba(185,28,28,0.15); }
/* El input lleva text-transform:uppercase para los códigos NE-…; el placeholder
   se deja en su capitalización original (no gritar el texto de ayuda). */
#eliminarNotaInput::placeholder { text-transform:none; }
/* Lista de sugerencias del buscador de Notas (modal Eliminar Nota). */
#eliminarNotaSuggest {
    position:absolute; top:calc(100% + 4px); left:0; right:0;
    background:#fff; border:1px solid #e2e8f0; border-radius:8px;
    box-shadow:0 10px 22px rgba(15,23,42,0.16);
    max-height:172px; overflow-y:auto; padding:4px; z-index:20; display:none;
}
#eliminarNotaSuggest.open { display:block; }
.eliminar-nota-sug-item {
    padding:7px 10px; border-radius:6px; cursor:pointer;
    font-family:monospace; font-size:12.5px; font-weight:700;
    color:#0f172a; letter-spacing:0.5px;
}
.eliminar-nota-sug-item:hover { background:#fee2e2; color:#b91c1c; }
.eliminar-nota-sug-empty { padding:8px 10px; font-size:12px; color:#94a3b8; font-style:italic; }
</style>

<script>
(function(){
    // Bandera por instancia: si el modal ya tiene listeners (SPA: misma vista re-montada)
    // no duplicar handlers de Escape ni redefinir funciones.
    if (window._almNotaModalsReady) return;
    window._almNotaModalsReady = true;

    var CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    var URL_DESTROY  = @json(route('almacen.nota-entrega.destroy'));

    function fb(id, type, msg) {
        var el = document.getElementById(id); if (!el) return;
        var colors = {
            info:    { bg:'#e0f2fe', border:'#bae6fd', color:'#075985' },
            error:   { bg:'#fee2e2', border:'#fecaca', color:'#b91c1c' },
            success: { bg:'#dcfce7', border:'#bbf7d0', color:'#15803d' },
        };
        var c = colors[type] || colors.info;
        el.style.cssText = 'display:block;padding:10px 12px;border-radius:8px;font-size:12.5px;font-weight:600;background:' + c.bg + ';border:1px solid ' + c.border + ';color:' + c.color + ';';
        el.textContent = msg;
    }

    // ── Eliminar Nota ───────────────────────────────────────────────────
    // Sólo se monta si el modal existe (gateado por blade {{'@'}}can a almacen.nota.eliminar).
    if (document.getElementById('eliminarNotaOverlay')) {
        // Lista de N° de Nota (SALIDA, almacenes visibles) para el autocomplete.
        // La provee el controller SOLO si el usuario tiene almacen.nota.eliminar.
        var NOTAS_LISTA = @json($numerosNotas ?? []);

        window.openEliminarNotaModal = function () {
            var ov = document.getElementById('eliminarNotaOverlay'); if (!ov) return;
            ov.style.display = 'flex';
            var i = document.getElementById('eliminarNotaInput');
            if (i) { i.value = ''; setTimeout(function(){ i.focus(); }, 80); }
            var elFb = document.getElementById('eliminarNotaFeedback'); if (elFb) elFb.style.display = 'none';
            var box = document.getElementById('eliminarNotaBox'); if (box) box.style.borderColor = '#e2e8f0';
            document.body.style.overflow = 'hidden';
        };
        window.closeEliminarNotaModal = function () {
            var ov = document.getElementById('eliminarNotaOverlay'); if (ov) ov.style.display = 'none';
            var sug = document.getElementById('eliminarNotaSuggest'); if (sug) sug.classList.remove('open');
            document.body.style.overflow = '';
        };

        // Autocomplete: la lista se despliega SOLO al escribir y muestra hasta 4
        // coincidencias por substring — basta escribir parte del número (p. ej.
        // los últimos 4 dígitos). Con el campo vacío la lista permanece cerrada.
        window.eliminarNotaSuggest = function () {
            var input = document.getElementById('eliminarNotaInput');
            var box   = document.getElementById('eliminarNotaSuggest');
            if (!input || !box) return;
            var q = String(input.value || '').trim().toUpperCase();
            if (q === '') { box.classList.remove('open'); return; }
            var matches = NOTAS_LISTA
                .filter(function (n) { return String(n).toUpperCase().indexOf(q) !== -1; })
                .slice(0, 4);
            if (matches.length === 0) {
                box.innerHTML = '<div class="eliminar-nota-sug-empty">Sin Notas que coincidan</div>';
            } else {
                box.innerHTML = matches.map(function (n) {
                    var safe = String(n).replace(/'/g, "\\'");
                    return '<div class="eliminar-nota-sug-item" onclick="window.eliminarNotaPick(\'' + safe + '\')">' + n + '</div>';
                }).join('');
            }
            box.classList.add('open');
        };
        window.eliminarNotaPick = function (numero) {
            var input = document.getElementById('eliminarNotaInput'); if (input) input.value = numero;
            var box = document.getElementById('eliminarNotaSuggest'); if (box) box.classList.remove('open');
            var bx  = document.getElementById('eliminarNotaBox'); if (bx) bx.style.borderColor = '#e2e8f0';
        };
        window.submitEliminarNota = async function () {
            var input = document.getElementById('eliminarNotaInput');
            var raw = (input && input.value || '').trim().toUpperCase();
            if (!raw) {
                var box = document.getElementById('eliminarNotaBox'); if (box) box.style.borderColor = '#ef4444';
                fb('eliminarNotaFeedback', 'error', 'Ingresa un N° de Nota para continuar.');
                if (input) input.focus(); return;
            }
            // El propio modal (botón rojo "Eliminar" + aviso "Acción irreversible")
            // ES la confirmación — sin confirm() nativo del navegador encima.
            var sug = document.getElementById('eliminarNotaSuggest'); if (sug) sug.classList.remove('open');

            var btn = document.getElementById('eliminarNotaSubmitBtn');
            var html = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="material-icons" style="font-size:17px;animation:spin 1s linear infinite;">sync</i> Eliminando...'; }
            fb('eliminarNotaFeedback', 'info', 'Eliminando la nota ' + raw + ' y reversando stock…');
            try {
                var r = await fetch(URL_DESTROY + '?numero=' + encodeURIComponent(raw), {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                });
                var data = await r.json().catch(function(){ return {}; });
                if (!r.ok) {
                    fb('eliminarNotaFeedback', 'error', data.message || ('Error del servidor (' + r.status + ').'));
                    return;
                }
                var okMsg = data.message || 'Nota eliminada y stock revertido.';
                fb('eliminarNotaFeedback', 'success', okMsg);
                // Toast global: la notificación persiste DESPUÉS de que el modal se
                // cierre. Sin esto el único aviso era el recuadro dentro del modal,
                // que desaparecía con él (800ms) y era fácil de no ver.
                if (window.showToast) window.showToast(okMsg, 'success');
                // Recargar la tabla de movimientos para que aparezcan las ENTRADAS reversa.
                if (window.loadMovimientos) window.loadMovimientos();
                setTimeout(function(){ window.closeEliminarNotaModal(); }, 800);
            } catch (err) {
                console.error('[Eliminar Nota]', err);
                fb('eliminarNotaFeedback', 'error', 'No se pudo contactar al servidor.');
            } finally {
                if (btn) { btn.disabled = false; btn.innerHTML = html; }
            }
        };
    }

    // Escape cierra el modal de Eliminar Nota si está abierto.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var x = document.getElementById('eliminarNotaOverlay');
        if (x && x.style.display === 'flex' && window.closeEliminarNotaModal) window.closeEliminarNotaModal();
    });
})();

// ── Borrar movimiento del kardex (SOLO super.admin) ─────────────────────────
// Dos botones, ambos renderizados por blade solo para super.admin y con su ruta DELETE
// gateada con can:super.admin (defensa real, no solo ocultar el botón):
//   · .alm-mov-undo  (deshacer)            → revierte el stock y recalcula los saldos.
//   · .alm-mov-purge (eliminar historial)  → borra el rastro SIN tocar el stock.
// Confirmación con el modal estilizado de la app (window.showModal), NO el confirm()
// nativo del navegador (que muestra la IP 127.0.0.1 y rompe el diseño).
    // Borrado de una fila del kardex (super.admin). Las dos variantes comparten TODA la
    // mecánica (DELETE + toast + recarga); solo cambian la URL (atributo data-*) y los
    // textos de confirmación:
    //   · almDeshacerMovimiento    → revierte el stock y recalcula (data-undo-url).
    //   · almEliminarSoloHistorial → borra el rastro SIN tocar el stock (data-purge-url).
    function almBorrarFilaKardex(btn, opts) {
        if (!btn || btn.disabled) return;
        var url = btn.getAttribute(opts.urlAttr);
        if (!url) return;

        var ejecutar = function () {
            var CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            btn.disabled = true;
            if (window.showPreloader) window.showPreloader();
            fetch(url, {
                method: 'DELETE',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
            })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, status: r.status, d: d }; });
            })
            .then(function (res) {
                if (window.hidePreloader) window.hidePreloader();
                if (!res.ok) {
                    if (window.showToast) window.showToast(res.d.message || ('Error del servidor (' + res.status + ').'), 'error');
                    btn.disabled = false;
                    return;
                }
                if (window.showToast) window.showToast(res.d.message || opts.okMsg, 'success');
                // Recargar la bitácora: la fila desaparece. En el deshacer los saldos
                // posteriores ya vienen recalculados; en el borrado solo-historial NO
                // (a propósito) — el stock no se tocó.
                if (window.loadMovimientos) window.loadMovimientos();
            })
            .catch(function () {
                if (window.hidePreloader) window.hidePreloader();
                if (window.showToast) window.showToast('No se pudo contactar al servidor.', 'error');
                btn.disabled = false;
            });
        };

        if (window.showModal) {
            window.showModal({
                type: 'danger',
                title: opts.title,
                message: opts.message,
                confirmText: opts.confirmText,
                cancelText: 'Cancelar',
                onConfirm: ejecutar
            });
        } else {
            // Fallback defensivo si showModal no estuviera cargado.
            if (window.confirm(opts.title + ' ' + opts.message)) ejecutar();
        }
    }

    window.almDeshacerMovimiento = function (btn) {
        almBorrarFilaKardex(btn, {
            urlAttr: 'data-undo-url',
            title: '¿Deshacer este movimiento?',
            message: 'Se revertirá el stock y el movimiento.',
            confirmText: 'Deshacer',
            okMsg: 'Movimiento deshecho.'
        });
    };

    window.almEliminarSoloHistorial = function (btn) {
        almBorrarFilaKardex(btn, {
            urlAttr: 'data-purge-url',
            title: '¿Eliminar del historial?',
            message: 'Se borrará el registro del kardex pero el STOCK NO se modificará: no se revierte la entrada/salida que sumó o restó. Irreversible.',
            confirmText: 'Eliminar del historial',
            okMsg: 'Registro eliminado del historial.'
        });
    };
</script>
@endsection
