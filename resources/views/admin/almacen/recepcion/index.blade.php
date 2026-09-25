@extends('layouts.estructura_base')

@section('title', 'Recepción de materiales')

@section('content')
@use('App\Models\Traspaso')
@php
    // Filtro de Estado. Sin parámetro la bandeja muestra el default que resuelve
    // TraspasoController@index server-side (Traspaso::ESTADOS_BANDEJA_DEFAULT = En tránsito +
    // Confirmada parcial). El DROPDOWN, en cambio, arranca SIN tinte azul ni X: pedido del
    // cliente — no debe verse "filtrado" al abrir. El azul y la X aparecen solo cuando el
    // usuario elige un estado concreto de la lista.
    $reqEstado     = request('estado', '');
    // `idAlmacenDestinoActivo` lo provee el controller incluyendo el default-merge por frente
    // del usuario. Es la fuente de verdad — no usamos request('id_almacen_destino') porque
    // el merge del controller no siempre llega al helper global al renderizar el Blade.
    $reqDestino    = $idAlmacenDestinoActivo ?? null;
    $reqSearch     = request('search');           // por NUMERO de nota de entrega

    $reqDesde      = request('desde');
    $reqHasta      = request('hasta');

    // Metadata visual de los estados — definida en Traspaso::ESTADOS_META.
    $badgesEstado = Traspaso::ESTADOS_META;
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
    {{-- Título + selector de almacén --}}
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
        <div style="flex:0 0 auto;">
            <h1 class="page-title" style="margin:0;">
                <span class="page-title-line2" style="color:#000;">Recepción de materiales</span>
            </h1>
        </div>
        <span aria-hidden="true" style="display:inline-block;width:1px;height:34px;background:#cbd5e0;flex:0 0 auto;"></span>
        <div style="flex:1 1 260px;max-width:360px;">
            {{-- Almacén de la bandeja. La lista YA viene acotada por Almacen::visiblesPara():
                 un usuario LOCAL solo ve los almacenes ligados a SUS frentes; un GLOBAL, todos.
                 Y solo los de PROYECTO: un GENERAL no recibe notas de entrega (el controller
                 pasa esa lista ya filtrada; si el usuario no ve ninguno de proyecto, los suyos).
                 NO hay opción "Todos los almacenes" ni X para quitarla (pedido del cliente):
                 la bandeja es de UN almacén — mezclarlos no dice nada útil, y el controller
                 siempre preselecciona uno (el del frente del usuario si es de esta lista, o el
                 primero de ella). --}}
            <div class="custom-dropdown" id="trDestHeaderDropdown" data-filter-type="id_almacen_destino">
                <input type="hidden" name="id_almacen_destino" data-filter-value value="{{ $destSel ? $destSel->ID_ALMACEN : '' }}">
                <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:#f8fafc;overflow:hidden;border:1px solid #cbd5e0;border-radius:10px;height:40px;">
                    <span style="padding:0 10px;display:flex;align-items:center;color:#0067b1;"><i class="material-icons" style="font-size:18px;transform:none !important;">warehouse</i></span>
                    <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                           placeholder="{{ $destSel ? $destSel->NOMBRE : 'Selecciona un almacén' }}"
                           style="flex:1;border:none;background:transparent;padding:8px 5px;font-size:13.5px;font-weight:600;color:#0f172a;outline:none;min-width:0;"
                           oninput="window.filterDropdownOptions(this)">
                </div>
                <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                    <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                        @foreach(($almacenes ?? collect()) as $a)
                            <div class="dropdown-item {{ $destSel && $destSel->ID_ALMACEN == $a->ID_ALMACEN ? 'selected' : '' }}" data-value="{{ $a->ID_ALMACEN }}"
                                 onclick="selectOption('trDestHeaderDropdown','{{ $a->ID_ALMACEN }}','{{ addslashes($a->NOMBRE) }}');">
                                {{ $a->NOMBRE }}
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<link rel="stylesheet" href="{{ asset('css/vistas/admin_almacen_recepcion_index.css') }}?v={{ @filemtime(public_path('css/vistas/admin_almacen_recepcion_index.css')) }}">

{{-- Layout: la tabla y el panel de resumen, cada uno en SU PROPIO contenedor. --}}
<div class="tr-layout">
<div class="admin-card" style="margin:0;min-height:70vh;padding:14px;flex:1 1 0;min-width:0;">

    {{-- ── Filtros ──────────────────────────────────────────────────────────────
         Orden del toolbar (pedido del cliente): PRIMERO el buscador por producto /
         descripción (el que más se usa y más texto muestra), DESPUÉS el de N° de nota.
         El Estado y las fechas viven en el panel "Filtros avanzados" (botón filter_list),
         igual que en /admin/equipos. --}}
    <div id="trFilters">
        {{-- Buscador por PRODUCTO (con equivalencias, igual que inventario): sugiere productos
             del catálogo (FuzzySearch + nºs de parte equivalentes). Al elegir uno se fija
             id_producto y la bandeja muestra SOLO las notas que CONTIENEN ese producto. --}}
        @php
            // El nombre del producto filtrado lo manda el controlador ($productoFiltrado):
            // antes se buscaba dentro del catálogo completo embebido, que ya no viaja en el
            // HTML. Es UNA fila, no las 1439.
            $reqIdProd    = request('id_producto');
            $reqProdLabel = $productoFiltrado ?? '';
        @endphp
        <div class="tr-search-prod">
            <div class="tr-search-box {{ $reqIdProd ? 'active' : '' }}">
                <i class="material-icons lupa">inventory_2</i>
                <input type="text" id="trProdSearch" autocomplete="off" placeholder="Buscar por producto o nº de parte"
                       value="{{ $reqProdLabel }}"
                       oninput="window.trProdInput()"
                       onfocus="window.trProdSuggest()"
                       onblur="setTimeout(function(){ var s=document.getElementById('trProdSuggest'); if(s) s.classList.remove('open'); }, 150);">
                <input type="hidden" id="trIdProducto" value="{{ $reqIdProd }}">
                {{-- Escanear QR: icono dentro del propio buscador, visible solo mientras no
                     haya producto elegido (comparte lugar con la "x" de limpiar). En teléfono
                     abre la cámara; en PC enfoca este buscador para el lector USB. --}}
                <i class="material-icons qrs-ic" id="trProdScan" title="Escanear código QR"
                   style="display:{{ $reqIdProd ? 'none' : 'flex' }};"
                   onclick="window.QrScan.abrir()">&#xf206;</i>
                <i class="material-icons" id="trProdClear" title="Limpiar filtro por producto"
                   style="display:{{ $reqIdProd ? 'flex' : 'none' }};align-items:center;padding:0 10px;color:#64748b;font-size:18px;cursor:default;"
                   onclick="window.trProdClear()">close</i>
            </div>
            <div id="trProdSuggest" class="tr-suggest"></div>
        </div>

        {{-- Buscador por N° de nota de entrega (NE-2026-…) con autocomplete sobre la lista
             pre-cargada (`numerosNotas`). --}}
        <div class="tr-search-num">
            <div class="tr-search-box {{ $reqSearch ? 'active' : '' }}">
                <i class="material-icons lupa">search</i>
                <input type="text" id="trSearch" autocomplete="off" placeholder="Buscar por N° de nota" value="{{ $reqSearch }}"
                       oninput="window.trSearchInput()"
                       onfocus="window.trSearchSuggest()"
                       onkeydown="window.trSearchEnter(event)"
                       onblur="setTimeout(function(){ var s=document.getElementById('trSearchSuggest'); if(s) s.classList.remove('open'); }, 150);">
                {{-- X = vaciar el filtro (mismo patrón que el buscador del módulo Inventario).
                     Visible solo cuando hay texto. --}}
                <i class="material-icons" id="trSearchClear" title="Limpiar filtro"
                   style="display:{{ $reqSearch ? 'flex' : 'none' }};align-items:center;padding:0 10px;color:#64748b;font-size:18px;cursor:default;"
                   onclick="window.trSearchClear()">close</i>
            </div>
            {{-- Sugerencias en vivo: lista los N° de nota visibles al usuario que coinciden
                 con lo que está escribiendo. Cargar la lista en el render evita un endpoint
                 extra — son strings cortos y vienen limitados a 300 desde el controller. --}}
            <div id="trSearchSuggest" class="tr-suggest"></div>
        </div>

        {{-- Filtros avanzados (Estado + Desde/Hasta) dentro de un panel — mismo patrón que
             /admin/equipos: un botón filter_list que abre el panel. Rojo si hay algún filtro
             del panel activo. Cierra al hacer clic fuera (ver listener abajo). --}}
        @php
            // SIN filtro el campo va VACÍO (pedido del cliente): antes mostraba
            // "En tránsito + parciales" —el default real de la bandeja— y parecía que ya
            // había algo elegido. La bandeja SIGUE mostrando ese default; lo que cambia es
            // que el campo ya no lo anuncia. Los dos sitios que limpian el filtro (la X y
            // trSetEstadoDefault) pasan '' a selectOption, que es lo mismo que sale aquí.
            $estadoLabelDefault = '';
            // Activo (azul + X) = el usuario eligió un estado concreto. Neutro/sin X para el
            // default (vacío) y para "all" (que selectOption global trata como neutro).
            // El rótulo sale de ESTADOS_META (estados reales) o de FILTROS_META (pseudo-
            // estados: Todas / Con discrepancias) — misma fuente que las opciones.
            $estadoActivo   = $reqEstado !== '' && $reqEstado !== Traspaso::FILTRO_TODAS;
            $reqEstadoLabel = $badgesEstado[$reqEstado][0]
                ?? (Traspaso::FILTROS_META[$reqEstado] ?? $estadoLabelDefault);
            $panelActivo    = $estadoActivo || $reqDesde || $reqHasta;
            // Opciones del dropdown: los estados accionables de la recepción + el pseudo-estado.
            // Se omiten de ESTADOS_META:
            //   • BORRADOR  → estado del almacén que EMITE; nunca llega al que recibe.
            //   • CANCELADO → la nota cancelada deshace todo (reversa el stock), no es algo
            //                 que se filtre en la bandeja.
            //   • RECIBIDO ("Confirmada") → quitado a pedido del cliente: las notas cerradas
            //                 sin novedad no se listan en la bandeja.
            // La X del trigger NO es una opción: limpia el filtro y vuelve al default.
            $opcionesEstado = collect($badgesEstado)
                ->except([Traspaso::ESTADO_BORRADOR, Traspaso::ESTADO_CANCELADO, Traspaso::ESTADO_RECIBIDO])
                ->map(fn ($b) => $b[0])
                ->put(Traspaso::FILTRO_CON_FALTANTES, Traspaso::FILTROS_META[Traspaso::FILTRO_CON_FALTANTES]);
            // Ayuda solo donde el rótulo no se explica solo (el pseudo-estado).
            $titulosEstado = [
                Traspaso::FILTRO_CON_FALTANTES => 'Notas ya confirmadas con alguna diferencia contra lo despachado: faltantes, sobrantes o dañados',
            ];
        @endphp
        <div style="position:relative;flex:0 0 auto;">
            {{-- Colores del botón (neutro / rojo = hay filtro dentro) en .btn-filtro-avanzado
                 (estilos_globales): el JS solo alterna .activo al filtrar por AJAX. --}}
            <button type="button" id="trAdvBtn" class="btn-primary-maquinaria btn-filtro-avanzado {{ $panelActivo ? 'activo' : '' }}"
                    onclick="window.trToggleAdvanced(event)" title="Filtros avanzados">
                <i class="material-icons">filter_list</i>
            </button>
            <div id="trAdvPanel" class="panel-filtro-avanzado" style="display:none;">
                <h4 class="panel-filtro-avanzado-titulo">
                    Filtros avanzados
                    <span style="font-size:12px;color:#64748b;font-weight:400;text-decoration:underline;cursor:default;" onclick="window.trClearAvanzados()">Limpiar</span>
                </h4>

                {{-- Estado: ocupa las 2 columnas de la grilla, arriba de las fechas. Mismo
                     custom-dropdown que el resto de la app (igual que "Almacén destino" del
                     header). selectOption actualiza el hidden input y dispara
                     'dropdown-selection' → trLoad. --}}
                <div class="tr-adv-grid">
                <div class="tr-adv-full">
                    <span class="panel-filtro-avanzado-label">Estado de la nota</span>
                    <div class="custom-dropdown" id="trEstadoDropdown" data-filter-type="estado">
                        <input type="hidden" name="estado" data-filter-value value="{{ $reqEstado }}">
                        {{-- Neutro #fbfcfd (no #fff): es el color que el selectOption global
                             repinta al limpiar el filtro; usar otro dejaría el trigger de un
                             tono al cargar y de otro tras limpiar. --}}
                        <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:{{ $estadoActivo ? '#e1effa' : '#fbfcfd' }};overflow:hidden;border:1px solid {{ $estadoActivo ? '#0067b1' : '#cbd5e0' }};">
                            <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                                   placeholder="{{ $reqEstadoLabel }}"
                                   style="flex:1;border:none;background:transparent;padding:8px 10px;font-weight:400;color:#0f172a;outline:none;min-width:0;cursor:default;"
                                   oninput="window.filterDropdownOptions(this)">
                            {{-- X = quitar el filtro de estado → el campo vuelve a quedar VACÍO y la
                                 bandeja a su default. No se usa clearDropdownFilter: ese helper
                                 rellena con "Seleccionar..." cuando la etiqueta va vacía, que es
                                 justo lo que el cliente pidió no ver. El selectOption global
                                 muestra la X solo cuando hay un estado concreto elegido. --}}
                            <i class="material-icons" data-clear-btn title="Quitar filtro de estado"
                               style="padding:0 4px;color:#64748b;font-size:16px;cursor:default;transform:none !important;display:{{ $estadoActivo ? 'block' : 'none' }};"
                               onclick="event.stopPropagation(); selectOption('trEstadoDropdown','','');">close</i>
                            <i class="material-icons" style="padding:0 6px;color:#64748b;font-size:16px;pointer-events:none;transform:none !important;">expand_more</i>
                        </div>
                        <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                            <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                                {{-- $opcionesEstado (arriba) ya trae los estados reales que se
                                     ofrecen + el pseudo-estado, así que aquí hay UN solo item. --}}
                                @foreach($opcionesEstado as $k => $label)
                                    <div class="dropdown-item {{ $reqEstado === $k ? 'selected' : '' }}" data-value="{{ $k }}"
                                         @if(isset($titulosEstado[$k])) title="{{ $titulosEstado[$k] }}" @endif
                                         onclick="selectOption('trEstadoDropdown','{{ $k }}','{{ addslashes($label) }}');">{{ $label }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                    {{-- Desde / Hasta: una columna cada uno, debajo del Estado. --}}
                    <div>
                        <span class="panel-filtro-avanzado-label">Desde</span>
                        <div id="trDesdeBox" class="tr-date-box" style="width:100%;box-sizing:border-box;background:{{ $reqDesde ? '#e1effa' : '#fff' }};"
                             onclick="var i=document.getElementById('trDesde'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                            <i class="material-icons">event</i>
                            <input type="date" id="trDesde" value="{{ $reqDesde }}" onchange="window.trResetKpi(); window.trLoad()">
                        </div>
                    </div>
                    <div>
                        <span class="panel-filtro-avanzado-label">Hasta</span>
                        <div id="trHastaBox" class="tr-date-box" style="width:100%;box-sizing:border-box;background:{{ $reqHasta ? '#e1effa' : '#fff' }};"
                             onclick="var i=document.getElementById('trHasta'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                            <i class="material-icons">event</i>
                            <input type="date" id="trHasta" value="{{ $reqHasta }}" onchange="window.trResetKpi(); window.trLoad()">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Botón de acción del toolbar: NO despliega menú — abre directo el modal de
             COMPRA DIRECTA. La bandeja de al lado lista la otra vía de entrada, y la
             diferencia entre las dos es de dónde viene el material, no si trae papeles
             (casi siempre los trae):
               · Bandeja  → REPOSICIÓN: el almacén general despachó y emitió su nota; aquí
                            solo se confirma lo que llegó.
               · Este botón → COMPRA DIRECTA: la empresa le compró a un proveedor y el
                            vendedor despachó el material directo a ESTE almacén, sin pasar
                            por el general. No hay nada que confirmar porque no hay nota
                            interna: la entrada se captura completa aquí. La nota del
                            proveedor se anota si la hubo (es opcional).
             El registro es una ENTRADA normal al almacén de la bandeja, así que exige la
             misma clave que confirmar una recepción — por eso va dentro del @can. --}}
        @can('almacen.movimiento')
        <button type="button" id="trCompraDirectaBtn" class="btn-primary-maquinaria tr-compra-btn"
                onclick="window.cdirAbrir()" title="Registrar una compra que el proveedor despachó directo a este almacén (no vino del almacén general)">
            <i class="material-icons">shopping_cart</i><span class="tr-compra-txt">Compra directa</span>
        </button>
        @endcan
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
                            <th title="A la izquierda el almacén que ENVÍA; a la derecha el FRENTE al que va el material (debajo, el almacén que lo recibe).">Origen / Destino</th>
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
{{-- SIN cierre por clic en el backdrop (pedido del cliente): el modal de recepción tiene
     acciones destructivas (confirmar / cancelar la nota) y un clic fuera despistado las
     abortaba a medio revisar. Se cierra con la ✕ del encabezado (guarda lo marcado) o con Cancelar/Escape
     (descartan lo marcado). --}}
<div class="dtm-overlay" id="trDetalleOverlay">
    <div class="dtm-box" id="trDetalleBox"></div>
</div>

@can('almacen.movimiento')
{{-- ── Modal "Entrada por compra directa" ────────────────────────────────────────
     Registra el material que la empresa COMPRÓ a un proveedor y que el vendedor despachó
     directo a este almacén, sin pasar por el almacén general. Es la otra vía de entrada
     del módulo: la bandeja de al lado es la REPOSICIÓN (el general despacha con su nota y
     acá solo se confirma). Aquí no hay nada que confirmar —no existe nota interna— así
     que la entrada se captura completa. La nota del proveedor se anota si la hubo.

     Es una ENTRADA normal: POSTea al MISMO endpoint que la pantalla "Entrada por ODC"
     (almacen.movimientos-lote, tipo=ENTRADA) con el mismo mapeo de columnas — nota de
     entrega → REFERENCIA, proveedor → MOTIVO. No hay backend nuevo.

     Por qué es un modal aparte y no un enlace a esa pantalla: la bandeja es el sitio donde
     el almacenista está parado cuando le llega el material; sacarlo a otra pantalla le
     hacía perder el filtro y la posición de la bandeja.

     Se captura en DOS pasos, en el mismo orden que trae el papel que el almacenista
     tiene en la mano:
       1) Cabecera del documento → proyecto + nota de entrega / proveedor / fecha.
          Solo el proyecto es obligatorio; el resto se puede dejar en blanco.
       2) Líneas de entrada → qué llegó y cuánto.
     Primero de quién viene y con qué papel, y después el detalle. Al revés obligaba a
     volver arriba al final, cuando ya había una lista larga por medio.
     Layout del paso 2: SOLO la tabla hace scroll. La barra de captura queda fija arriba
     (así el buscador está siempre a mano y ningún contenedor con overflow le recorta el
     desplegable) y los botones fijos abajo. --}}
<link rel="stylesheet" href="{{ asset('css/vistas/admin_almacen_recepcion_index_2.css') }}?v={{ @filemtime(public_path('css/vistas/admin_almacen_recepcion_index_2.css')) }}">

<div class="cdir-overlay" id="cdirOverlay">
    <div class="cdir-box" role="dialog" aria-modal="true" aria-labelledby="cdirTitulo">
        <div class="cdir-title-row">
            {{-- Volver al paso 1. Vive en el encabezado y no en el pie porque el pie lo ocupan
                 las dos acciones de la operación (Cancelar / Aceptar); "atrás" es navegación,
                 no una decisión sobre la entrada. Solo se ve en el paso 2. --}}
            <button type="button" class="cdir-atras" id="cdirAtras" onclick="window.cdirPaso(1)" title="Volver a los datos del documento" style="display:none;">
                <i class="material-icons" style="color:#fff;">arrow_back</i>
            </button>
            <i class="material-icons">shopping_cart</i>
            <span class="cdir-title" id="cdirTitulo">Entrada por compra directa</span>
            {{-- La ✕ CIERRA SIN PREGUNTAR y CONSERVA lo capturado (mismo criterio que la ✕
                 del modal de detalle): es el gesto de "ahorita vuelvo", no el de descartar.
                 Descartar es el botón Cancelar, que sí avisa. --}}
            <button type="button" class="cdir-close" onclick="window.cdirOcultar()" title="Cerrar sin perder lo capturado"><i class="material-icons" style="color:#fff;">close</i></button>
        </div>

        {{-- ── Paso 1: proyecto + datos del documento ── --}}
        <div class="cdir-paso on" id="cdirPaso1">
        {{-- PASO 1: la cabecera del documento (proyecto + nota/proveedor/fecha).
             Va antes que las lineas por el mismo motivo que en una nota de entrega en papel:
             primero de quien viene y con que papel, y despues el detalle de lo que trae. Asi
             el almacenista copia la cabecera del documento que tiene en la mano y solo entonces
             empieza a contar bultos, en vez de tener que volver arriba al final. --}}
        {{-- Asociar material a un proyecto. Solo aparece en almacenes que reparten el saldo entre
             varios proyectos; en los demas no hay nada que elegir y la fila no se pinta.
             Encabeza el paso 1 porque define a que bolsa entra TODO lo que se capture despues.
             Es obligatorio, y el backend lo exige igual por si alguien entra por otra via. --}}
        <div class="cdir-proyecto" id="cdirProyectoRow" style="display:none;">
            {{-- Rótulo a secas. El asterisco y el icono de ayuda se quitaron por pedido del
                 cliente: la señal de "falta este dato" ya la da el borde rojo del campo
                 (.falta), que es más visible que un asterisco, y la explicación del proyecto
                 vive en el title del propio campo. --}}
            <label for="cdirProyecto">Asociar material a un proyecto</label>
            <div class="cdir-proy-field">
                <input type="text" id="cdirProyecto" class="cdir-input falta" autocomplete="off"
                       title="Todo lo que captures abajo se suma al saldo de ESTE proyecto dentro del almacén. Cada proyecto lleva su stock por separado."
                       placeholder="Escribe para buscar el proyecto…"
                       oninput="window.cdirProySuggest()" onfocus="window.cdirProySuggest(true)" onclick="window.cdirProySuggest(true)"
                       onkeydown="window.cdirProyKey(event)">
                <input type="hidden" id="cdirProyectoId">
                <i class="material-icons cdir-proy-caret">expand_more</i>
                <div class="cdir-suggest" id="cdirProySuggest"></div>
            </div>
        </div>
        <div class="cdir-paso2-head">
            <div class="cdir-section-title">Datos del documento — opcional</div>
        </div>
        <div class="cdir-meta">
            <div>
                <label for="cdirNota">Nota de entrega</label>
                <input type="text" id="cdirNota" class="cdir-input" maxlength="100" placeholder="Opcional" autocomplete="off">
            </div>
            <div>
                <label for="cdirProveedor">Proveedor</label>
                <input type="text" id="cdirProveedor" class="cdir-input" maxlength="200" placeholder="Razón social o nombre" autocomplete="off">
            </div>
            <div>
                <label for="cdirFecha">Fecha</label>
                <div class="cdir-input cdir-date" onclick="var i=document.getElementById('cdirFecha'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                    <i class="material-icons" style="font-size:16px;color:#64748b;">event</i>
                    <input type="date" id="cdirFecha">
                </div>
            </div>
        </div>
        </div>{{-- /#cdirPaso1 --}}

        {{-- PASO 2: que llego y cuanto. La barra de captura queda fija arriba (asi el buscador
             esta siempre a mano y ningun contenedor con overflow le recorta el desplegable) y
             debajo la tabla, que es la unica zona que hace scroll. --}}
        <div class="cdir-paso" id="cdirPaso2">
        <div class="cdir-capt">
            {{-- El rótulo encabeza TODA la sección (buscador + tabla), no solo la tabla: por
                 eso va aquí arriba y no pegado a la lista. Así se lee "esto es la captura de
                 líneas" antes de empezar a escribir. --}}
            <div class="cdir-section-title">Líneas de entrada</div>
            <div class="cdir-capt-bar">
                <div class="cdir-field">
                    {{-- Lupa como en el resto de los buscadores del módulo (.tr-search-box).
                         Va ANTES de .cdir-badge en el DOM a propósito: la insignia del
                         producto elegido se pinta encima (inset:0) y debe taparla. --}}
                    <i class="material-icons lupa cdir-lupa">search</i>
                    <input type="text" id="cdirSearch" class="cdir-input" autocomplete="off"
                           placeholder="Buscar por código o descripción…"
                           oninput="window.cdirSuggest()" onfocus="window.cdirSuggest()" onkeydown="window.cdirSearchKey(event)">
                    <div class="cdir-badge" id="cdirBadge">
                        <span class="cod" id="cdirBadgeCod"></span>
                        <span class="nom" id="cdirBadgeNom"></span>
                        <i class="material-icons" onclick="window.cdirQuitarSeleccion()" title="Cambiar producto">close</i>
                    </div>
                    <div class="cdir-suggest" id="cdirSuggest"></div>
                </div>
                <div class="cdir-um-wrap" title="Unidad de medida">
                    <input type="text" id="cdirUm" class="cdir-input cdir-um-input" value="UND" maxlength="20" autocomplete="off"
                           aria-label="Unidad de medida" placeholder="UND"
                           oninput="window.cdirUmSuggest()" onfocus="window.cdirUmSuggest(true)" onkeydown="window.cdirUmKey(event)">
                    <div class="cdir-um-suggest" id="cdirUmSuggest"></div>
                </div>
                <input type="text" inputmode="decimal" enterkeyhint="done" id="cdirCant" class="cdir-input cdir-cant-input"
                       placeholder="Cant." autocomplete="off" aria-label="Cantidad" onkeydown="window.cdirCantKey(event)">
                <button type="button" class="cdir-add-btn" onclick="window.cdirAgregar()" title="Agregar línea (Enter)">
                    <i class="material-icons">check_circle</i>
                </button>
            </div>
        </div>
        </div>{{-- /#cdirPaso2 --}}

        {{-- Tabla de líneas. Vive FUERA de los dos pasos —no dentro del 2— porque es la única
             zona que hace scroll y necesita crecer contra la caja del modal; metida dentro de
             un .cdir-paso (flex-shrink:0) no lo haría. Se oculta en el paso 1 con la clase
             .cdir-en-paso1 del contenedor: ahí todavía no hay nada capturado y sería una
             tabla vacía debajo de la cabecera del documento. --}}
        {{-- Sin rótulo propio: "Líneas de entrada" ahora encabeza la sección completa desde
             arriba del buscador (ver .cdir-capt). Repetirlo aquí era decir dos veces lo mismo
             a cuatro renglones de distancia. --}}
        <div class="cdir-list-wrap">
            <div class="cdir-list">
                <table class="cdir-table">
                    <thead>
                        <tr>
                            <th class="c-num">Nº</th>
                            <th class="c-cod">Código</th>
                            <th class="c-desc">Descripción</th>
                            <th class="c-cant">Cantidad</th>
                            <th class="c-del"></th>
                        </tr>
                    </thead>
                    <tbody id="cdirTbody"></tbody>
                </table>
                <div class="cdir-empty" id="cdirEmpty">Busca un producto, escribe la cantidad y presiona Enter para agregarlo.</div>
            </div>
        </div>

        <div class="cdir-error" id="cdirError"></div>

        {{-- Sin bloque de resumen (Líneas / Unidades): el cliente ya lo mandó quitar de la
             pantalla "Entrada por ODC" y además sumar cantidades de distintas UM (3 UND + 2
             CAJA) no da un número que signifique nada. La tabla de arriba ya numera las líneas. --}}
        <div class="cdir-footer on" id="cdirFooter1">
            <button type="button" class="cdir-btn cdir-btn-cancel" onclick="window.cdirCancelar()">Cancelar</button>
            <button type="button" class="cdir-btn cdir-btn-ok" onclick="window.cdirPaso(2)">
                <i class="material-icons">check_circle</i> Aceptar
            </button>
        </div>
        {{-- Mismos rótulos que el paso 1 (pedido del cliente): Cancelar descarta la entrada
             completa —avisando— y Aceptar la registra. Para volver a las líneas sin perder
             nada está la flecha del encabezado. --}}
        <div class="cdir-footer" id="cdirFooter2">
            <button type="button" class="cdir-btn cdir-btn-cancel" onclick="window.cdirCancelar()">Cancelar</button>
            <button type="button" class="cdir-btn cdir-btn-ok" id="cdirRegistrar" onclick="window.cdirGuardar()">
                <i class="material-icons">check_circle</i> Aceptar
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    if (!document.getElementById('cdirTbody')) return;

    // Endpoints. La ENTRADA usa el mismo de la pantalla "Entrada por ODC" — un lote
    // tipo=ENTRADA — así que una compra directa deja en el kardex EXACTAMENTE el mismo
    // rastro que una entrada por orden de compra. El de productos solo se usa cuando el
    // material que llegó todavía no existe en el catálogo (pasa seguido comprando por fuera).
    var ROUTE_ENTRADA = @json(route('almacen.movimientos.lote'));
    var ROUTE_PROD    = @json(route('almacen.productos.store'));
    // Proyectos por almacén: { idAlmacen: { separa, implicito, frentes:[{id,nombre}] } }.
    //   separa=true  → el almacén reparte el saldo entre proyectos: el usuario elige uno
    //                  (obligatorio) y ese va en `id_frente`.
    //   separa=false → no hay nada que elegir; se manda `implicito` solo para que el kardex
    //                  muestre el frente en "Destino" en vez de "—".
    var CDIR_PROYECTOS = @json($proyectosPorAlmacen ?? []);

    function el(id) { return document.getElementById(id); }
    function v(id) { var e = el(id); return e ? String(e.value).trim() : ''; }
    // Delega en window.toast (dom_helpers.js); aqui solo el default de esta pantalla.
    function toast(msg, type) { if (!window.toast(msg, type || 'success') && type === 'error') alert(msg); }
    var esc = window.escapeHtml;   // helper central (dom_helpers.js)
    function norm(s) { return window.FuzzySearch.norm(s); }
    function showErr(msg) {
        var e = el('cdirError'); if (!e) return;
        if (msg) { e.style.display = 'block'; e.textContent = msg; }
        else     { e.style.display = 'none';  e.textContent = ''; }
    }
    // Catálogo: el MISMO que ya usa el filtro por producto del toolbar (window.trProductosLista,
    // embebido una sola vez en el render). Se lee por llamada —no se copia a una variable del
    // módulo— para que las altas al vuelo se vean desde los dos buscadores sin sincronizar nada.
    // Si todavía no existiera se crea el array en window (no uno local): devolver `[]` suelto
    // haría que el push de un producto nuevo se perdiera en silencio.
    function catalogo() {
        if (!window.trProductosLista) window.trProductosLista = [];
        return window.trProductosLista;
    }

    // ── Estado ────────────────────────────────────────────────────────────
    var lineas   = [];     // {id_producto, codigo, nombre, um, cantidad}
    var elegido  = null;   // producto tomado del autocomplete, esperando cantidad
    var creando  = false;  // guard anti doble-POST mientras se registra un producto nuevo
    var enviando = false;  // guard anti doble-POST del submit
    var skipSuggest = false; // suprime UNA apertura del desplegable al refocar el buscador
    var skipProySuggest = false; // lo mismo con la lista de proyectos cuando el foco lo pone el modal

    // ── Almacén destino: se lee del dropdown del header en cada uso ──
    // El almacén de la bandeja se cambia en caliente (el dropdown recarga la tabla por AJAX
    // sin recargar la página), así que fijarlo al renderizar registraría la entrada en el
    // almacén equivocado tras cambiar de bandeja. Devuelve el id o null.
    function destinoActual() {
        var h = document.querySelector('#trDestHeaderDropdown input[data-filter-value]');
        var id = h ? parseInt(h.value, 10) : NaN;
        return (isFinite(id) && id > 0) ? id : null;
    }
    /** Config de proyectos del almacén destino; forma estable aunque el mapa no lo traiga. */
    function proyectosDe(idAlmacen) {
        var c = CDIR_PROYECTOS[idAlmacen];
        return c || { separa: false, implicito: null, frentes: [] };
    }

    // ── Asociar material a un proyecto ───────────────────────────────────
    // La franja se muestra SOLO si el almacén reparte el saldo entre varios proyectos. Con
    // uno solo no hay nada que preguntar: todo entra a esa única bolsa.
    // Se rearma en cada apertura porque el almacén de la bandeja cambia en caliente, y una
    // lista pintada para el almacén anterior ofrecería proyectos que no son de éste.
    var proyectosVisibles = [];   // los del almacén abierto, para el buscador

    function montarProyectos(idAlmacen) {
        var cfg = proyectosDe(idAlmacen), row = el('cdirProyectoRow');
        proyectosVisibles = cfg.separa ? cfg.frentes : [];
        // display:'' (no 'block'): devuelve la fila al display que manda la hoja de
        // estilos. Con 'block' el inline pisaba al CSS y la fila nunca era la caja que
        // .cdir-proyecto describe, asi que su gap no se aplicaba.
        row.style.display = cfg.separa ? '' : 'none';
        // Se limpia SIEMPRE, también cuando el almacén nuevo no separa: si no, el id del
        // proyecto del almacén anterior se quedaba pegado en el campo oculto.
        // Y nunca se preselecciona: elegir por el usuario es justo lo que ensuciaba el saldo
        // antes (se mandaba el primer proyecto del almacén, acertara o no).
        limpiarProyecto();
    }
    function limpiarProyecto() {
        var inp = el('cdirProyecto');
        inp.value = '';
        el('cdirProyectoId').value = '';
        inp.classList.add('falta');
        inp.classList.remove('listo');
        proySuggestHide();
    }
    function proySuggestHide() { var b = el('cdirProySuggest'); if (b) b.classList.remove('open'); }

    // Buscador del proyecto: mismo ranking que el resto del módulo (FuzzySearch), así
    // "corta" encuentra "CORTAFUEGO AYACUCHO FASE II" sin escribirlo entero. Con el campo
    // vacío (o con un proyecto ya elegido) se ofrecen TODOS los del almacén — son pocos y
    // verlos completos ahorra teclear cuando el usuario no recuerda el nombre exacto.
    window.cdirProySuggest = function (todos) {
        var inp = el('cdirProyecto'), box = el('cdirProySuggest');
        if (!inp || !box) return;
        // Al abrir el modal el foco cae aquí solo (cdirPaso): el campo queda listo para
        // escribir, pero sin soltar la lista encima. Se despliega al tocar el campo (onclick)
        // o al escribir.
        if (todos && skipProySuggest) { skipProySuggest = false; return; }
        // Sin `todos` = viene de oninput, o sea el texto CAMBIÓ: la elección anterior deja de
        // valer. Se invalida aquí y no en keydown porque allí Tab, las flechas o Ctrl+C
        // también contaban como cambio y borraban un proyecto ya elegido al salir del campo.
        if (!todos && el('cdirProyectoId').value) {
            el('cdirProyectoId').value = '';
            inp.classList.remove('listo');
            inp.classList.add('falta');
        }
        var term = inp.value.trim();
        // Todos si el campo está vacío o ya muestra un proyecto elegido (para cambiarlo); con
        // texto a medias, solo lo que coincide — también al tocar el campo, que antes soltaba
        // la lista entera con "corta" escrito.
        var lista = ((todos && el('cdirProyectoId').value) || term === '')
            ? proyectosVisibles
            : window.FuzzySearch.rank(proyectosVisibles, term, function (f) {
                return { haystack: f.nombre, label: f.nombre };
              });
        box.innerHTML = lista.length
            ? lista.map(function (f) {
                return '<div class="cdir-suggest-item" data-id="' + f.id + '" data-nom="' + esc(f.nombre) + '">'
                     + '<span class="nom">' + esc(f.nombre) + '</span></div>';
              }).join('')
            : '<div class="cdir-suggest-empty">Ningún proyecto de este almacén coincide con «' + esc(term) + '».</div>';
        box.classList.add('open');
    };
    function elegirProyecto(item) {
        var inp = el('cdirProyecto');
        inp.value = item.getAttribute('data-nom') || '';
        el('cdirProyectoId').value = item.getAttribute('data-id') || '';
        inp.classList.remove('falta');
        inp.classList.add('listo');
        proySuggestHide();
        showErr('');
    }
    window.cdirProyKey = function (ev) {
        if (ev.key === 'Escape') { ev.preventDefault(); proySuggestHide(); return; }
        if (ev.key === 'Enter') {
            ev.preventDefault();
            // Solo de la lista A LA VISTA: cerrada, guarda los proyectos de la vez anterior y
            // Enter elegía uno que nadie escogió. Con la lista cerrada, Enter la abre.
            if (!el('cdirProySuggest').classList.contains('open')) { window.cdirProySuggest(true); return; }
            var first = document.querySelector('#cdirProySuggest .cdir-suggest-item');
            if (first) elegirProyecto(first);
        }
    };
    /** Proyecto a mandar en el payload, o undefined si falta elegirlo. */
    function frenteElegido(idAlmacen) {
        var cfg = proyectosDe(idAlmacen);
        if (!cfg.separa) return cfg.implicito || null;
        var v = parseInt(el('cdirProyectoId').value, 10);
        return isFinite(v) && v > 0 ? v : undefined;
    }

    // ── Autocomplete de producto ──────────────────────────────────────────
    function suggestHide() { var b = el('cdirSuggest'); if (b) b.classList.remove('open'); }
    window.cdirSuggest = function () {
        var box = el('cdirSuggest'), inp = el('cdirSearch');
        if (!box || !inp) return;
        if (skipSuggest) { skipSuggest = false; box.classList.remove('open'); return; }
        if (elegido) { box.classList.remove('open'); return; }   // ya hay uno elegido
        var term = inp.value.trim();
        // Mismo ranking y mismo haystack (código + nombre + nºs de parte equivalentes) que el
        // resto de buscadores del módulo — window.FuzzySearch es la fuente única.
        var matches = window.FuzzySearch.rank(catalogo(), term, function (p) {
            return { haystack: (p.CODIGO || '') + ' ' + (p.NOMBRE || '') + ' ' + (p.EQUIV || ''), label: p.NOMBRE || '' };
        }).slice(0, 12);
        if (!matches.length) {
            box.innerHTML = term
                ? '<div class="cdir-suggest-empty">Sin coincidencias. Escribe la cantidad y presiona Enter para registrar <strong>"' + esc(term) + '"</strong> como producto nuevo.</div>'
                : '<div class="cdir-suggest-empty">Empieza a escribir para buscar.</div>';
        } else {
            box.innerHTML = matches.map(function (p) {
                var parte = window.FuzzySearch.matchedPart(term, p.PARTES, p.PARTE);
                return '<div class="cdir-suggest-item" data-id="' + p.ID_PRODUCTO + '" data-cod="' + esc(p.CODIGO) + '" data-nom="' + esc(p.NOMBRE) + '" data-um="' + esc(p.UM) + '" data-cat="' + esc(p.CATEGORIA || '') + '">'
                    + (parte ? '<span class="parte">' + esc(parte) + '</span>' : '')
                    + '<span class="nom">' + esc(p.NOMBRE) + '</span>'
                    + (p.UM ? '<span class="um">' + esc(p.UM) + '</span>' : '')
                    + '</div>';
            }).join('');
        }
        box.classList.add('open');
    };
    // Elegir sugerencia: chip encima del input, UM prefijada (editable) y salto a Cantidad.
    function elegir(item) {
        elegido = {
            id_producto: parseInt(item.getAttribute('data-id'), 10),
            codigo:      item.getAttribute('data-cod') || '',
            nombre:      item.getAttribute('data-nom') || '',
            um:          item.getAttribute('data-um')  || '',
            categoria:   item.getAttribute('data-cat') || '',
        };
        el('cdirBadgeCod').textContent = elegido.codigo;
        el('cdirBadgeNom').textContent = elegido.nombre;
        el('cdirBadge').classList.add('show');
        var inp = el('cdirSearch'); inp.value = ''; inp.style.visibility = 'hidden';
        suggestHide();
        var um = el('cdirUm'); if (um && elegido.um) { um.value = elegido.um; umHide(); }
        setTimeout(function () { var c = el('cdirCant'); if (c) c.focus(); }, 30);
    }
    // Punto ÚNICO que devuelve la barra de captura (buscador + chip + UM + cantidad) a su
    // estado inicial. `foco` decide qué pasa con el cursor:
    //   'buscar'    → al buscador CON sugerencias — la X del chip: el usuario quiere otro producto.
    //   'siguiente' → al buscador SIN sugerencias — acaba de agregar una línea; el refoco es
    //                 automático, no una intención de ver la lista.
    //   false       → sin foco — se está vaciando para CERRAR el modal; enfocar ahí dejaría el
    //                 cursor en un campo que desaparece (y en el teléfono asomaría el teclado).
    function resetCaptura(foco) {
        elegido = null;
        el('cdirBadge').classList.remove('show');
        var inp = el('cdirSearch'); inp.style.visibility = ''; inp.value = '';
        var um = el('cdirUm'); if (um) um.value = 'UND';
        el('cdirCant').value = '';
        if (!foco) return;
        if (foco === 'siguiente') skipSuggest = true;
        inp.focus();
    }
    window.cdirQuitarSeleccion = function () { resetCaptura('buscar'); };
    window.cdirSearchKey = function (ev) {
        if (ev.key === 'Escape') { ev.preventDefault(); suggestHide(); return; }
        if (ev.key === 'Enter')  {
            ev.preventDefault();
            var first = document.querySelector('#cdirSuggest .cdir-suggest-item');
            if (first) elegir(first);
            else { var c = el('cdirCant'); if (c) c.focus(); }   // producto nuevo → a cantidad
        }
    };

    // ── Autocomplete de UM ────────────────────────────────────────────────
    // Las UMs se derivan del catálogo ya embebido (no hay consulta aparte). El usuario
    // puede escribir una UM que no esté en la lista: se guarda tal cual.
    function umHide() { var b = el('cdirUmSuggest'); if (b) b.classList.remove('open'); }
    window.cdirUmSuggest = function (todas) {
        var inp = el('cdirUm'), box = el('cdirUmSuggest');
        if (!inp || !box) return;
        // Object.create(null) y no {}: una UM llamada "constructor"/"toString" daría positivo
        // contra el prototipo de Object y desaparecería de la lista.
        var term = norm(inp.value.trim()), vistas = Object.create(null), lista = [];
        catalogo().forEach(function (p) {
            var u = String(p.UM || '').trim();
            if (!u || vistas[u]) return;
            if (todas || term === '' || norm(u).indexOf(term) !== -1) { vistas[u] = 1; lista.push(u); }
        });
        lista.sort();
        box.innerHTML = lista.length
            ? lista.slice(0, 20).map(function (u) { return '<div class="cdir-um-item" data-um="' + esc(u) + '">' + esc(u) + '</div>'; }).join('')
            : '<div class="cdir-suggest-empty">Sin coincidencias. La UM se guardará tal cual la escribiste.</div>';
        box.classList.add('open');
    };
    window.cdirUmKey = function (ev) {
        if (ev.key === 'Escape') { umHide(); return; }
        if (ev.key === 'Enter') {
            ev.preventDefault();
            var first = document.querySelector('#cdirUmSuggest .cdir-um-item'), inp = el('cdirUm');
            if (first && inp) inp.value = first.getAttribute('data-um') || inp.value;
            umHide();
            var c = el('cdirCant'); if (c) c.focus();
        }
    };

    // ── Cantidad ──────────────────────────────────────────────────────────
    // type=text (no number) para conservar el estilo del resto del módulo: las teclas no
    // numéricas se bloquean aquí y Enter equivale al botón del check.
    window.cdirCantKey = function (ev) {
        if (['Backspace','Delete','Tab','ArrowLeft','ArrowRight','Home','End'].indexOf(ev.key) !== -1) return;
        if (ev.key === 'Enter') { ev.preventDefault(); window.cdirAgregar(); return; }
        if (/^[0-9]$/.test(ev.key)) return;
        if ((ev.key === '.' || ev.key === ',') && ev.target.value.indexOf('.') === -1 && ev.target.value.indexOf(',') === -1) return;
        if (!ev.ctrlKey && !ev.metaKey) ev.preventDefault();
    };

    // ── Líneas ────────────────────────────────────────────────────────────
    // Producto repetido → SUMA la cantidad en vez de duplicar la fila (captura tipo POS).
    function insertar(prod, cant) {
        var ya = lineas.find(function (l) { return l.id_producto === prod.id_producto; });
        if (ya) ya.cantidad = +(ya.cantidad + cant).toFixed(3);
        else lineas.push({ id_producto: prod.id_producto, codigo: prod.codigo, nombre: prod.nombre, um: prod.um, cantidad: cant });
        render();
        resetCaptura('siguiente');
    }
    window.cdirQuitarLinea = function (idx) {
        if (idx < 0 || idx >= lineas.length) return;
        lineas.splice(idx, 1);
        render();
    };
    function fmt(n) {
        var x = parseFloat(Number(n).toFixed(3));
        return isNaN(x) ? '0' : x.toLocaleString('es-ES', { maximumFractionDigits: 3 });
    }
    function render() {
        var tb = el('cdirTbody'), vacio = el('cdirEmpty');
        if (!tb) return;
        tb.innerHTML = lineas.map(function (l, i) {
            return '<tr>'
                + '<td class="c-num">' + (i + 1) + '</td>'
                + '<td class="c-cod">' + esc(l.codigo) + '</td>'
                + '<td class="c-desc">' + esc(l.nombre) + '</td>'
                + '<td class="c-cant">' + esc(fmt(l.cantidad)) + '<span class="um">' + esc(l.um) + '</span></td>'
                + '<td class="c-del"><button type="button" class="cdir-del-btn" onclick="window.cdirQuitarLinea(' + i + ')" title="Quitar"><i class="material-icons" style="font-size:19px;">delete</i></button></td>'
                + '</tr>';
        }).join('');
        if (vacio) vacio.style.display = lineas.length ? 'none' : '';
    }

    // ── Alta de producto al vuelo ─────────────────────────────────────────
    // El backend normaliza NOMBRE/UM a mayúsculas; lo hacemos aquí también para que el
    // payload, la fila de la tabla y el catálogo en memoria queden iguales.
    // cantidad_inicial=0 (con id_almacen) crea la fila de stock en CERO sin disparar el
    // check de permiso de movimiento: la cantidad real entra después, en el lote.
    function crearProducto(nombre, cant, um, categoria) {
        nombre = String(nombre || '').trim().toUpperCase();
        um     = String(um || 'UND').trim().toUpperCase() || 'UND';
        var dest = destinoActual();
        var body = { NOMBRE: nombre, UM: um };
        if (String(categoria || '').trim()) body.CATEGORIA = String(categoria).trim();
        if (dest) { body.id_almacen = dest; body.cantidad_inicial = 0; }
        creando = true;
        window.apiFetch(ROUTE_PROD, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',  'Content-Type': 'application/json'},
            body: JSON.stringify(body)
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            creando = false;
            if (!res.ok) {
                var msg = (res.b && res.b.message) || 'No se pudo registrar el producto nuevo.';
                if (res.b && res.b.errors) msg = Object.values(res.b.errors).map(function (a) { return a.join(' '); }).join(' ');
                showErr(msg); toast(msg, 'error');
                var i = el('cdirSearch'); if (i) i.focus();
                return;
            }
            var p = res.b.producto || {};
            catalogo().push({
                ID_PRODUCTO: p.ID_PRODUCTO, CODIGO: p.CODIGO || '', NOMBRE: p.NOMBRE || nombre,
                UM: p.UM || um, CATEGORIA: p.CATEGORIA || categoria || '', EQUIV: '', PARTE: '', PARTES: [],
            });
            insertar({ id_producto: p.ID_PRODUCTO, codigo: p.CODIGO || '', nombre: p.NOMBRE || nombre, um: p.UM || um }, cant);
            toast('Producto nuevo registrado: ' + (p.CODIGO || '') + ' · ' + (p.NOMBRE || nombre));
        })
        .catch(function () {
            creando = false;
            var m = 'Error de red al registrar el producto.';
            showErr(m); toast(m, 'error');
        });
    }

    window.cdirAgregar = function () {
        if (creando) return;
        showErr('');
        var cant = parseFloat(String(el('cdirCant').value || '').replace(',', '.').trim());
        if (!isFinite(cant) || cant <= 0) {
            var m = 'Indica una cantidad mayor que cero.';
            showErr(m); toast(m, 'error'); el('cdirCant').focus(); return;
        }
        var umEscrita = String(el('cdirUm').value || '').trim().toUpperCase();

        // Caso 1 — producto del catálogo.
        if (elegido) {
            // Misma UM (o el producto no tiene una registrada) → es el mismo producto.
            if (!elegido.um || !umEscrita || norm(umEscrita) === norm(elegido.um)) { insertar(elegido, cant); return; }
            // UM distinta → es OTRA presentación del mismo material (UND→CAJA…). Se reusa la
            // presentación si ya existe en el catálogo; si no, se registra. El producto
            // original NUNCA se toca: la conversión de unidades es manual por diseño.
            var variante = catalogo().find(function (p) { return norm(p.NOMBRE) === norm(elegido.nombre) && norm(p.UM) === norm(umEscrita); });
            if (variante) {
                insertar({ id_producto: variante.ID_PRODUCTO, codigo: variante.CODIGO || '', nombre: variante.NOMBRE, um: variante.UM || umEscrita }, cant);
                return;
            }
            // Categoría heredada: la del elegido, o la de cualquier presentación con el mismo
            // nombre que sí la tenga (así una presentación nueva no nace sin categoría).
            var cat = (elegido.categoria || '').trim();
            if (!cat) {
                var conCat = catalogo().find(function (p) { return norm(p.NOMBRE) === norm(elegido.nombre) && p.CATEGORIA && String(p.CATEGORIA).trim(); });
                if (conCat) cat = String(conCat.CATEGORIA).trim();
            }
            crearProducto(elegido.nombre, cant, umEscrita, cat);
            return;
        }

        // Caso 2 — texto que no está en el catálogo → producto nuevo al vuelo.
        var texto = String(el('cdirSearch').value || '').trim();
        if (texto.length >= 2) { crearProducto(texto, cant, umEscrita || 'UND', ''); return; }

        // Caso 3 — no hay ni selección ni texto útil.
        var m3 = 'Escribe la descripción del producto o elige uno de la lista.';
        showErr(m3); toast(m3, 'error');
        var i = el('cdirSearch'); if (i) i.focus();
    };

    // ── Pasos ─────────────────────────────────────────────────────────────
    // 1 = cabecera del documento (proyecto + nota/proveedor/fecha) · 2 = líneas de entrada.
    //
    // Aquí NO se exige que haya líneas: en el paso 2 es donde se capturan, así que pedirlas
    // para entrar sería pedir el resultado antes de dar la herramienta. Ese requisito vive
    // en cdirGuardar, que es el momento en que de verdad hace falta.
    window.cdirPaso = function (n) {
        // El proyecto define a qué bolsa entra TODO lo capturado, así que se exige al salir
        // del paso 1 — no al final, cuando ya estaría todo cargado y volver sería más molesto.
        if (n === 2 && frenteElegido(destinoActual()) === undefined) {
            var mp = 'Indica el proyecto que recibe el material.';
            showErr(mp); toast(mp, 'error');
            var sp = el('cdirProyecto'); if (sp) { sp.classList.add('falta'); sp.focus(); }
            return;
        }
        el('cdirAtras').style.display = (n === 2) ? 'block' : 'none';
        showErr('');
        el('cdirPaso1').classList.toggle('on', n === 1);
        el('cdirPaso2').classList.toggle('on', n === 2);
        // Oculta la tabla mientras se llena la cabecera (ver .cdir-en-paso1).
        var caja = document.querySelector('.cdir-box');
        if (caja) caja.classList.toggle('cdir-en-paso1', n === 1);
        el('cdirFooter1').classList.toggle('on', n === 1);
        el('cdirFooter2').classList.toggle('on', n === 2);
        // Los desplegables del buscador de producto y de UM viven en el paso 2, y el del
        // proyecto en el paso 1: al cambiar de paso quedarían abiertos sobre nada.
        suggestHide(); umHide(); proySuggestHide();
        // El foco cae en el primer campo que toca escribir en cada paso. Al entrar al 2 se
        // suprime la primera apertura del desplegable (skipSuggest): el buscador queda listo
        // para escribir, pero sin soltar la lista entera de productos en la cara.
        if (n === 2) skipSuggest = true;
        setTimeout(function () {
            var fila = el('cdirProyectoRow');
            var i = (n === 2)
                ? el('cdirSearch')
                : ((fila && fila.style.display !== 'none') ? el('cdirProyecto') : el('cdirNota'));
            if (i && i.id === 'cdirProyecto' && document.activeElement !== i) skipProySuggest = true;
            if (i) i.focus();
        }, 40);
    };

    // ── Abrir / cerrar ────────────────────────────────────────────────────
    window.cdirAbrir = function () {
        if (!destinoActual()) {
            toast('Selecciona primero el almacén de la bandeja para registrar la entrada.', 'error');
            return;
        }
        // El desplegable de proyectos se arma en CADA apertura: el almacén de la bandeja se
        // cambia en caliente y uno pintado para el anterior ofrecería proyectos que no son.
        montarProyectos(destinoActual());
        var f = el('cdirFecha'); if (f && !f.value) f.value = new Date().toISOString().slice(0, 10);
        // Sin render() aquí: la tabla ya está pintada — al montar el módulo por el render
        // inicial del final de este script, y después de cada cambio por insertar/quitar/limpiar.
        // Siempre se entra por el paso 1, aunque se haya salido con la ✕ desde el 2.
        // cdirPaso() deja el foco en el primer campo del paso; no se toca aquí.
        window.cdirPaso(1);
        el('cdirOverlay').classList.add('open');
        // Bloquear el scroll del fondo mientras el modal está abierto — mismo cuidado que
        // el modal de detalle. Los dos nunca coexisten: con este abierto, el overlay tapa
        // las filas de la bandeja.
        document.body.style.overflow = 'hidden';
    };
    function cerrar() {
        el('cdirOverlay').classList.remove('open');
        document.body.style.overflow = '';
        suggestHide(); umHide();
    }
    // ✕ del encabezado: cierra y CONSERVA la captura, sin preguntar nada. Al volver a abrir,
    // las líneas siguen ahí. Preguntar aquí era ruido: la ✕ no destruye nada.
    window.cdirOcultar = function () { cerrar(); };
    // Vacía la captura completa. Lo reusan Cancelar y el ÉXITO del registro; no notifica
    // (cada quien muestra su propio mensaje).
    function limpiar() {
        lineas = [];
        render();
        resetCaptura(false);
        ['cdirNota', 'cdirProveedor'].forEach(function (id) { var e = el(id); if (e) e.value = ''; });
        // El proyecto también: la siguiente entrada puede ser de otro frente y dejarlo pegado
        // del anterior es justo el error que este campo vino a evitar.
        if (proyectosVisibles.length) limpiarProyecto();
        el('cdirFecha').value = new Date().toISOString().slice(0, 10);
        showErr('');
    }
    // Cancelar cierra SIEMPRE, pero si hay líneas capturadas pide confirmación antes:
    // cerrar sin avisar se llevaría por delante toda la captura.
    window.cdirCancelar = function () {
        var hacer = function () { limpiar(); cerrar(); };
        if (!lineas.length) { hacer(); return; }
        var n = lineas.length;
        var msg = 'Perderás <strong>' + n + (n === 1 ? ' producto</strong> capturado.' : ' productos</strong> capturados.');
        // confirmarAccion (layout_ui.js) ya trae dentro el respaldo al confirm() del
        // navegador: no hay que repetir aquí ese if/else.
        window.confirmarAccion({ type: 'warning', title: 'Cancelar entrada', message: msg,
            confirmText: 'Aceptar', cancelText: 'Cancelar' }, hacer);
    };

    // ── Registrar ─────────────────────────────────────────────────────────
    window.cdirGuardar = function () {
        if (enviando) return;
        showErr('');
        var dest = destinoActual();
        if (!dest) { var mA = 'No se pudo determinar el almacén destino. Recarga la página.'; showErr(mA); toast(mA, 'error'); return; }
        // Única puerta que exige líneas, y ahora sí se llega aquí sin ellas: con el orden
        // nuevo el paso 2 ES la captura, así que entrar en él no puede pedir lo que todavía
        // no existe. Antes esto era un por-si-acaso que no saltaba nunca.
        //
        // El aviso va DESPUÉS de cambiar de paso, no antes: cdirPaso() arranca con
        // showErr(''), así que pintarlo primero lo borraba y el usuario se quedaba sin
        // saber por qué no se registraba.
        if (!lineas.length) {
            window.cdirPaso(2);
            var mL = 'Agrega al menos un producto antes de registrar.';
            showErr(mL); toast(mL, 'error');
            var sL = el('cdirSearch'); if (sL) sL.focus();
            return;
        }
        // Cada dato en SU columna del kardex — MISMO mapeo que la pantalla "Entrada por ODC",
        // para que las dos vías de entrada se lean igual en la bitácora:
        //   · Nota de entrega → referencia (REFERENCIA)
        //   · Proveedor       → motivo     (MOTIVO)
        var payload = {
            tipo:       'ENTRADA',
            id_almacen: dest,
            id_frente:  frenteElegido(dest),
            fecha:      v('cdirFecha') || null,
            referencia: v('cdirNota') || null,
            motivo:     v('cdirProveedor') || null,
            lineas:     lineas.map(function (l) { return { id_producto: l.id_producto, cantidad: l.cantidad }; }),
        };

        enviando = true;
        var btn = el('cdirRegistrar'); if (btn) btn.disabled = true;
        if (window.showPreloader) window.showPreloader();
        window.apiFetch(ROUTE_ENTRADA, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',  'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, status: r.status, b: b }; }); })
        .then(function (res) {
            enviando = false;
            if (window.hidePreloader) window.hidePreloader();
            if (btn) btn.disabled = false;
            if (res.ok) {
                // La bandeja NO se recarga a propósito: una compra directa no genera nota
                // interna (esa la emite el almacén general cuando repone), así que ni la
                // tabla ni los KPIs —que cuentan notas por confirmar— cambian. Recargar solo
                // costaría un viaje al servidor y un parpadeo. El movimiento sí queda
                // visible en el kardex de /admin/almacen.
                limpiar(); cerrar();
                toast((res.b && res.b.message) || 'Entrada registrada correctamente.', 'success');
                return;
            }
            // Error: el modal se queda abierto con la captura intacta.
            var msg = (res.b && res.b.message) || 'No se pudo registrar la entrada.';
            if (res.b && res.b.errors) msg = Object.values(res.b.errors).map(function (a) { return a.join(' '); }).join(' ');
            // 403 = falta la clave 'almacen.movimiento' → solo notificación (no es un error
            // de un campo del formulario).
            if (res.status === 403 || (res.b && res.b.forbidden)) { toast(msg, 'error'); return; }
            showErr(msg); toast(msg, 'error');
        })
        .catch(function () {
            enviando = false;
            if (window.hidePreloader) window.hidePreloader();
            if (btn) btn.disabled = false;
            var m = 'Error de red al registrar la entrada.';
            showErr(m); toast(m, 'error');
        });
    };

    // ── Listeners globales ────────────────────────────────────────────────
    // La navegación SPA (navegacion.js) RE-EJECUTA este <script> en cada visita al módulo.
    // Los listeners de `document` se REEMPLAZAN (no basta con registrarlos "una sola vez"):
    // llaman a funciones locales que mutan el estado de SU corrida, así que el listener de
    // la visita anterior seguiría escribiendo en el array de líneas viejo.
    function modalAbierto() {
        var ov = el('cdirOverlay');
        return !!(ov && ov.classList.contains('open'));
    }
    if (window.__cdirDocClick) document.removeEventListener('click', window.__cdirDocClick);
    window.__cdirDocClick = function (e) {
        if (!modalAbierto()) return;
        var item = e.target.closest('#cdirSuggest .cdir-suggest-item');
        if (item) { e.preventDefault(); elegir(item); return; }
        var proy = e.target.closest('#cdirProySuggest .cdir-suggest-item');
        if (proy) { e.preventDefault(); elegirProyecto(proy); return; }
        var um = e.target.closest('#cdirUmSuggest .cdir-um-item');
        if (um) {
            e.preventDefault();
            var i = el('cdirUm'); if (i) i.value = um.getAttribute('data-um') || '';
            umHide();
            return;
        }
        if (!e.target.closest('.cdir-field'))      suggestHide();
        if (!e.target.closest('.cdir-um-wrap'))    umHide();
        if (!e.target.closest('.cdir-proy-field')) proySuggestHide();
    };
    document.addEventListener('click', window.__cdirDocClick);

    if (window.__cdirDocKeydown) document.removeEventListener('keydown', window.__cdirDocKeydown);
    // Escape: cierra primero el desplegable abierto; si no hay ninguno, hace lo MISMO que la
    // ✕ — cerrar conservando la captura. Es el gesto reflejo de "salir", no el de descartar:
    // por eso no dispara la confirmación (esa vive en el botón Cancelar).
    window.__cdirDocKeydown = function (e) {
        if (e.key !== 'Escape') return;
        if (!modalAbierto()) return;
        // Con la confirmación de "Cancelar entrada" encima (#standardModal del layout, que no
        // tiene su propio Escape), este handler cerraría el modal por debajo del diálogo.
        // Mientras esté activa, Escape no es asunto nuestro.
        var std = document.getElementById('standardModal');
        if (std && std.classList.contains('active')) return;
        var sug = el('cdirSuggest'), umS = el('cdirUmSuggest'), proS = el('cdirProySuggest');
        if (sug  && sug.classList.contains('open'))  { suggestHide();    return; }
        if (umS  && umS.classList.contains('open'))  { umHide();         return; }
        if (proS && proS.classList.contains('open')) { proySuggestHide(); return; }
        window.cdirOcultar();
    };
    document.addEventListener('keydown', window.__cdirDocKeydown);

    render();
})();
</script>
@endcan

{{-- Escaneo QR (modal de cámara + estilo del icono del buscador): partial COMPARTIDO con
     Inventario y Movimientos. Va ANTES de los scripts de la vista porque su <script>
     registra las rutas que usa el QrScan.init del buscador de producto.

     FUERA del @can('almacen.movimiento') a propósito: el icono de escanear vive en el
     buscador por producto, que ve CUALQUIERA que entre a la bandeja. Estando dentro del
     @can, a un usuario sin esa clave se le pintaba el icono pero el modal no existía, y
     en teléfono el botón no hacía nada (qr_scan.js sale si no encuentra #qrsModal). En
     PC no se notaba porque ahí no abre cámara: enfoca el buscador para el lector USB. --}}
@include('admin.almacen.partials.scan_modal')

@php
    // Lo que el JavaScript del modulo necesita de ESTA apertura. El codigo esta en
    // public/js/maquinaria/recepcion_bandeja.js, que el navegador cachea.
    //
    // El CATALOGO de productos NO va aqui: se pide por AJAX a urlProductos apenas la
    // pantalla queda montada. Embebido eran 214 KB de HTML y ~48 ms de servidor en cada
    // apertura. Lo consumen el buscador por producto del toolbar y el modal de compra
    // directa ("Entrada sin nota"), los dos AL TECLEAR, asi que llega a tiempo de sobra.
    $recb_cfg = [
        'rutaAlmacenRecepcionIndex' => route('almacen.recepcion.index'),
        'traspasoFiltroTodas' => Traspaso::FILTRO_TODAS,
        'numerosNotas' => $numerosNotas ?? [],
        'urlProductos' => route('almacen.productos-autocomplete'),
        'urlDetalle' => url('/admin/almacen/recepcion'),
    ];
@endphp
<script>
    window.RECB_CFG = @json($recb_cfg);
</script>
<script src="{{ asset('js/maquinaria/recepcion_bandeja.js') }}?v={{ @filemtime(public_path('js/maquinaria/recepcion_bandeja.js')) }}"></script>
<script>
    // El archivo de arriba se carga UNA vez en toda la sesion; esta llamada es la que
    // monta la pantalla, y corre en cada apertura del modulo (tambien al volver por la
    // navegacion interna, que es cuando el <script src> ya no se re-ejecuta).
    window.recepcionBandejaArrancar(window.RECB_CFG);
</script>

{{-- El antiguo modal #entModal ("Registrar entrada directa") fue extraido a su
     pagina propia /admin/almacen/recepcion/nueva — esa ruta ofrece el mismo flujo
     (POST a almacen.movimientos.lote con tipo=ENTRADA) pero con autocomplete de
     producto por codigo o descripcion. El boton "Recepción ODC" del header de
     esta vista linkea directo alla. --}}

@endsection
