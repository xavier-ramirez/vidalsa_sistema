@extends('layouts.estructura_base')

@section('title', 'Historial de Movimientos de Inventario')

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
    // Filtro de PRODUCTO activo = texto libre (search) O un producto / sus presentaciones
    // elegidos por clic en una sugerencia (id_producto / id_producto_in, que llegan SIN `search`).
    $prodActivo  = $reqSearch || request('id_producto') || request('id_producto_in');
    // Filtro Tipo: Entradas (grupo), Salidas (grupo), Auditoría (tipo exacto AJUSTE) y
    // Devoluciones (tipo exacto DEVOLUCION; también cuentan dentro de Entradas).
    $tipos = [
        'ENTRADAS'   => ['label' => 'Entradas', 'sub' => ''],
        'SALIDAS'    => ['label' => 'Salidas', 'sub' => ''],
        'AJUSTE'     => ['label' => 'Auditoría', 'sub' => ''],
        'DEVOLUCION' => ['label' => 'Devoluciones', 'sub' => ''],
    ];
    $tipoSelLabel = ($reqTipo && isset($tipos[$reqTipo])) ? $tipos[$reqTipo]['label'] . ($tipos[$reqTipo]['sub'] ? ' ' . $tipos[$reqTipo]['sub'] : '') : null;
    // $hayAdv pinta el boton Filtros Avanzados en rojo si HAY filtros aplicados
    // dentro del panel. Tipo vive ahora ahí también — sin esto, seleccionar
    // Entrada/Salida no resaltaría visualmente el botón.
    $hayAdv      = $reqDesde || $reqHasta || $tipoSelLabel || $reqNota;
    $almSel      = $reqAlmacen ? ($almacenes ?? collect())->firstWhere('ID_ALMACEN', (int) $reqAlmacen) : null;
    $frenteSel    = ($reqFrente && $reqFrente !== 'all') ? ($frentesLista ?? collect())->firstWhere('ID_FRENTE', (int) $reqFrente) : null;
@endphp

@include('admin.partials.page_header', [
    'titulo'    => 'Historial de Movimientos de Inventario',
    'align'     => 'left',
    'margin'    => '0 0 10px 0',
    'separador' => true,
    'acciones'  => 'admin.almacen.partials.filtro_almacen_header',
    'filtroId'  => 'almMovFiltroAlmacen',
])

<link rel="stylesheet" href="{{ asset('css/vistas/admin_almacen_movimientos.css') }}?v={{ @filemtime(public_path('css/vistas/admin_almacen_movimientos.css')) }}">

<div class="page-layout-grid">
<div class="admin-card" style="margin:0;min-height:70vh;min-width:0;width:100%;padding:14px;">

    {{-- ── Filtros ── (el filtro de almacén está junto al título, no aquí) --}}
    <div id="almMovFilters">

        {{-- Buscar producto — MISMAS funciones que el filtro "Descripción" de /admin/almacen:
             sugerencias fuzzy (código, descripción y nº de parte equivalente), clic en una
             sugerencia = match exacto (o TODAS las presentaciones si esa descripción tiene
             varias), Enter = similitudes por LIKE, X = limpiar, e icono de escanear el código de barras.
             Escribir SOLO refresca las sugerencias — NO dispara la consulta a la tabla. --}}
        <div class="amf-item amf-search">
            <div class="amf-search-wrap">
                <div class="amf-search-box {{ $prodActivo ? 'active' : '' }}">
                    <i class="material-icons lupa">search</i>
                    <input type="text" id="almMovSearch" autocomplete="off" placeholder="Buscar producto (código o descripción)…" value="{{ $reqSearch }}"
                           oninput="window.almMovBuscarInput()"
                           onfocus="window.almMovSuggestFn()"
                           onkeydown="window.almMovBuscarKey(event)">
                    {{-- Escanear el código de barras: icono dentro del propio buscador, visible solo con el campo
                         vacío (comparte lugar con la "x" de limpiar). En teléfono abre la cámara;
                         en PC enfoca este buscador para que el lector USB teclee aquí. --}}
                    <i class="lbr-ic icono-barras" id="almMovBuscarScan" title="Escanear código de barras"
                       style="display:{{ $prodActivo ? 'none' : 'flex' }};"
                       onclick="window.LectorBarras.abrir()"></i>
                    <i class="material-icons clr" id="almMovSearchClear"
                       style="display:{{ $prodActivo ? 'block' : 'none' }};"
                       onclick="window.almMovBuscarLimpiar()">close</i>
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
            <button type="button" id="btnAdvancedFilterMov" class="btn-primary-maquinaria btn-filtro-avanzado {{ $hayAdv ? 'activo' : '' }}" title="Filtros Avanzados"
                    onclick="window.almMovToggleFechas(event)">
                <i class="material-icons">filter_list</i>
            </button>
            <div id="almMovFechasPanel" class="panel-filtro-avanzado ancho" style="display:none;">
                <h4 class="panel-filtro-avanzado-titulo">
                    Filtros Avanzados
                    <span class="panel-filtro-avanzado-limpiar" onclick="window.almMovLimpiarFechas()">Limpiar Todo</span>
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
                  <div style="min-width:0;position:relative;">{{-- col Nota de entrega: filtra por N° de Nota de Entrega (salidas) o por
                            la referencia del proveedor (entradas) — backend: NUMERO_NOTA / REFERENCIA.
                            min-width:0 igual que Tipo: evita el desborde del grid. position:relative
                            ancla la lista de sugerencias (#almMovNotaSuggest) bajo el input. --}}
                    <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;">Referencia</span>
                    <div style="display:flex;align-items:center;background:{{ $reqNota ? '#e1effa' : '#fff' }};border:1px solid #cbd5e0;border-radius:8px;height:36px;padding:0 4px;">
                        <span style="padding:0 6px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:16px;transform:none !important;">search</i></span>
                        <input type="text" id="almMovNota" autocomplete="off" placeholder="N° nota o referencia..." value="{{ $reqNota }}"
                               oninput="var c=document.getElementById('almMovNotaClear'); if(c) c.style.display=this.value?'block':'none'; window.almMovNotaSuggest();"
                               onfocus="window.almMovNotaSuggest()"
                               {{-- Sin `onchange`: al pulsar Enter ya recargamos, y el `change` que dispara el blur
                                    posterior lanzaba una SEGUNDA petición idéntica. El filtro se aplica con Enter,
                                    con el clic en una sugerencia o con la ✕ — igual que el buscador de productos. --}}
                               onkeydown="if(event.key==='Enter'){event.preventDefault(); window.almMovNotaSuggestHide(); window.loadMovimientos();} if(event.key==='Escape') window.almMovNotaSuggestHide();"
                               style="flex:1;border:none;background:transparent;padding:0 4px;font-size:13px;color:#0f172a;outline:none;min-width:0;">
                        <i class="material-icons" id="almMovNotaClear" style="padding:0 6px;color:#64748b;font-size:18px;display:{{ $reqNota ? 'block' : 'none' }};cursor:pointer;transform:none !important;"
                           onclick="document.getElementById('almMovNota').value=''; this.style.display='none'; window.almMovNotaSuggestHide(); window.loadMovimientos();">close</i>
                    </div>
                    {{-- Sugerencias en vivo: N° de Nota de Entrega (NE-…) de salida en almacenes
                         visibles. Mismo estilo (.amf-suggest) que el buscador de productos. --}}
                    <div class="amf-suggest" id="almMovNotaSuggest"></div>
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
                            <input type="date" id="almMovDesde" value="{{ $reqDesde }}"
                                   oninput="window.almMovFechaFiltro(this)" onchange="window.almMovFechaFiltro(this)"
                                   style="flex:1;min-width:0;border:none;background:transparent;padding:0;font-size:12px;outline:none;color:#334155;cursor:pointer;">
                            <i class="material-icons" id="almMovDesdeClear"
                               style="display:{{ $reqDesde ? 'inline-flex' : 'none' }};font-size:14px;color:#64748b;cursor:pointer;padding:2px;border-radius:50%;"
                               onclick="event.stopPropagation(); var i=document.getElementById('almMovDesde'); if(i){ i.value=''; } this.style.display='none'; document.getElementById('almMovDesdeBox').style.background='white'; window.almMovFechaFiltro(i);">close</i>
                        </div>
                    </div>
                    <div style="min-width:0;">
                        <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:5px;">Hasta</span>
                        <div id="almMovHastaBox" style="display:flex;align-items:center;background:{{ $reqHasta ? '#e1effa' : 'white' }};border:1px solid #e2e8f0;border-radius:6px;height:32px;padding:0 4px;cursor:pointer;"
                             onclick="var i=document.getElementById('almMovHasta'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                            {{-- Filtro por DÍA exacto (type=date). El backend (scopePeriodo) acepta
                                 'YYYY-MM-DD' (whereDate <=). --}}
                            <input type="date" id="almMovHasta" value="{{ $reqHasta }}"
                                   oninput="window.almMovFechaFiltro(this)" onchange="window.almMovFechaFiltro(this)"
                                   style="flex:1;min-width:0;border:none;background:transparent;padding:0;font-size:12px;outline:none;color:#334155;cursor:pointer;">
                            <i class="material-icons" id="almMovHastaClear"
                               style="display:{{ $reqHasta ? 'inline-flex' : 'none' }};font-size:14px;color:#64748b;cursor:pointer;padding:2px;border-radius:50%;"
                               onclick="event.stopPropagation(); var i=document.getElementById('almMovHasta'); if(i){ i.value=''; } this.style.display='none'; document.getElementById('almMovHastaBox').style.background='white'; window.almMovFechaFiltro(i);">close</i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Botón Acciones (dropdown estilo /admin/movilizaciones) ────────────
             Reemplaza al viejo botón "Inventario" y consolida las acciones
             rápidas de la bitácora en un único menú:
               · Dashboard de consumo
               · Despachos (abre la Reposición del general)
               · Historial de Notas de Entrega
               · Exportar a Excel
               · Eliminar Nota de Entrega por código  (requiere almacen.nota.eliminar)
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
                {{-- UNA sola envoltura para todos los items. Antes cada uno traia la suya
                     con border-bottom (y el ultimo con border-top), asi que entre el tercero
                     y el cuarto se apilaban DOS rayas de 1px. El menu de acciones de
                     /admin/movilizaciones no lleva separadores; este ahora tampoco. --}}
                <div style="padding:6px;">
                {{-- Dashboard de Consumo: abre el modal con gráficos (Chart.js) sobre las
                     salidas, respetando los filtros activos. --}}
                    <button type="button"
                        onclick="document.getElementById('splitDropdownMenuMovInv').style.display='none'; window.abrirConsumoDashboard();"
                        class="alm-mov-accion">
                        <div style="background:#e0f2fe;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;line-height:1;color:#0067b1;">analytics</i></div>
                        <span>Dashboard de consumo</span>
                    </button>
                {{-- Despachos: abre la Reposición del general (la bandeja donde los
                     almacenes de proyecto confirman lo que el general les despachó) y dice
                     cuántas notas faltan por recibir, en la misma pill de la columna Estado
                     de la bandeja (colores de Traspaso::ESTADOS_META). Corto a propósito
                     ("Despachos" + el número): la frase entera va en el title. --}}
                    <a href="{{ route('almacen.recepcion.index', ['force' => 1]) }}"
                        class="alm-mov-accion" title="{{ $porRecibirPry > 0 ? $porRecibirPry . ' notas por recibir' : 'Todo recibido' }} · abre la Reposición del general"
                        onclick="event.preventDefault(); document.getElementById('splitDropdownMenuMovInv').style.display='none'; if(window.navigateTo) window.navigateTo(this.href); else window.location.href=this.href;">
                        <div style="background:#e0f2fe;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;line-height:1;color:#0067b1;">local_shipping</i></div>
                        <span>Despachos</span>
                        @php
                            // Mismos colores que la pill de la columna Estado de la bandeja:
                            // pendiente = En tránsito; al día = Confirmada.
                            [, $pillBg, $pillFg] = \App\Models\Traspaso::ESTADOS_META[$porRecibirPry > 0 ? \App\Models\Traspaso::ESTADO_ENVIADO : \App\Models\Traspaso::ESTADO_RECIBIDO];
                        @endphp
                        <span class="alm-mov-accion-pill" style="background:{{ $pillBg }};color:{{ $pillFg }};">{{ $porRecibirPry > 0 ? $porRecibirPry : 'Al día' }}</span>
                    </a>
                {{-- Historial de Notas de Entrega: vista alterna agrupada por NUMERO_NOTA — una
                     fila por Nota de Entrega; clic abre el PDF oficial. Conserva los filtros
                     activos. Icono como el del PDF de cada fila (kardex_rows). --}}
                    <a id="lnkBitNotas" href="{{ route('almacen.notas') }}"
                        class="alm-mov-accion"
                        onclick="event.preventDefault(); document.getElementById('splitDropdownMenuMovInv').style.display='none'; if(window.navigateTo) window.navigateTo(this.href); else window.location.href=this.href;">
                        <div style="background:#e0f2fe;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;line-height:1;color:#0f172a;">description</i></div>
                        <span>Historial de Notas de Entrega</span>
                    </a>
                {{-- Exportar a Excel: baja la bitácora tal y como se está viendo. Los filtros
                     salen de buildParams(), el mismo que arma la petición de la tabla. --}}
                    <button type="button" onclick="window.almMovExportarExcel();"
                        class="alm-mov-accion">
                        <div style="background:#f1f5f9;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;line-height:1;color:#64748b;">download</i></div>
                        <span>Exportar a Excel</span>
                    </button>
                @can('almacen.nota.eliminar')
                {{-- Eliminar Nota: gateado a la clave almacen.nota.eliminar porque reversa
                     stock y deja un par (SALIDA original + su DEVOLUCION) en el kardex. --}}
                    <button type="button"
                        onclick="document.getElementById('splitDropdownMenuMovInv').style.display='none'; window.openEliminarNotaModal();"
                        class="alm-mov-accion">
                        <div style="background:#fee2e2;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;line-height:1;color:#dc2626;">delete_outline</i></div>
                        <span>Eliminar Nota por código</span>
                    </button>
                @endcan
                </div>

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
                    {{-- Fecha + Tipo combinados en una sola columna (el tipo va
                         debajo de la fecha en cada fila). Se eliminó la columna Tipo. --}}
                    {{-- Anchos de PC en PORCENTAJE y con table-layout:fixed (ver el CSS): en
                         reparto automático el navegador los ignoraba y repartía por contenido
                         —Destino se quedaba en 176 px aunque se le pidieran 245—.
                         Fecha, Cantidad y Stock se apretaron (la fecha ya va en 11,5 px y las
                         otras dos son números cortos) y lo que sueltan se lo llevan Descripción
                         y Destino. Referencia también suelta 3 puntos: le sobraba sitio (una
                         nota es "NE-2026-0655") y Descripción es la que parte los nombres en
                         dos renglones. Pedido del cliente. El teléfono es una rejilla aparte y
                         los ignora. Suman 100. --}}
                    <th style="width:15%;">Fecha</th>
                    <th style="width:33%;">Descripción del producto</th>
                    <th style="width:9%;">Cantidad</th>
                    <th style="width:6%;">Stock</th>
                    <th style="width:24%;">Destino</th>
                    <th style="width:13%;">Referencia</th>
                </tr>
            </thead>
            <tbody id="almMovTableBody">
                @include('admin.almacen.partials.kardex_rows', ['movimientos' => $movimientos, 'almacenesVisibles' => $almacenesVisibles])
            </tbody>
        </table>
    </div>

    <div style="margin-top:14px;" id="almMovPagination">
        {{ $movimientos->links('vendor.pagination.custom-sliding') }}
    </div>

</div>

{{-- Sidebar: contador + ranking de consumo (mismas dimensiones que /admin/equipos) --}}
<div class="counter-sidebar" style="position:sticky;top:20px;display:flex;flex-direction:column;gap:8px;">
    <div style="background:linear-gradient(135deg,#001a52 0%,#0a4a91 100%);border-radius:12px;padding:15px;color:#fff;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);position:relative;overflow:hidden;">
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

{{-- Escaneo del código de barras (modal de cámara + estilo del icono del buscador): partial COMPARTIDO con
     Inventario y Recepción. Va ANTES del script de la vista porque su <script> registra
     las rutas que usa el LectorBarras.init de más abajo. --}}
@include('admin.almacen.partials.scan_modal')

{{-- Modal "Dashboard de Consumo" (abierto desde el menú Acciones). Compartido con
     /admin/almacen — misma vista parcial, mismo endpoint. DEBE ir FUERA de @can:
     el botón que lo abre es visible para todos, así que el modal y su <script>
     (window.abrirConsumoDashboard) tienen que existir siempre. Antes estaba anidado
     dentro de @can('almacen.nota.eliminar') y no abría para quien no tuviera ese permiso. --}}
@include('admin.almacen.partials.consumo_dashboard_modal')

{{-- Modal «Modificar» de las salidas con nota (devolver o corregir; se gatea él mismo con
     esas dos claves) y la comparación original/corregida de la marca «corregida», que ve
     todo el que ve la nota. --}}
@include('admin.almacen.partials.ajuste_nota_modal')

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
    font-size:12.5px; font-weight:700;
    color:#0f172a; letter-spacing:0.5px;
}
.eliminar-nota-sug-item:hover { background:#fee2e2; color:#b91c1c; }
.eliminar-nota-sug-empty { padding:8px 10px; font-size:12px; color:#94a3b8; font-style:italic; }
</style>

@php
    // Los datos de ESTA apertura que necesita public/js/maquinaria/almacen_movimientos.js.
    $almMovCfg = [
        'rutas' => [
            'movimientos'           => route('almacen.movimientos'),
            'productosAutocomplete' => route('almacen.productos-autocomplete'),
            'exportar'              => route('almacen.movimientosExport'),
            'notas'                 => route('almacen.notas'),
            'notaDestroy'           => route('almacen.nota-entrega.destroy'),
        ],
        'notasFiltro'  => $notasFiltro ?? [],
        // Solo la trae quien tiene almacen.nota.eliminar (autocomplete del modal Eliminar Nota).
        'numerosNotas' => $numerosNotas ?? [],
    ];
@endphp
{{-- El JavaScript del modulo va en un archivo (se baja una vez y queda en caché) y aquí, al
     final, para que ya existan todos los modales que usa, incluido el de Eliminar Nota.
     El archivo corre una sola vez por pestaña: la bitácora se arranca en cada apertura. --}}
<script>window.ALM_MOV_CFG = @json($almMovCfg);</script>
<script src="{{ asset('js/maquinaria/almacen_movimientos.js') }}?v={{ @filemtime(public_path('js/maquinaria/almacen_movimientos.js')) }}"></script>
<script>window.almMovArrancar(window.ALM_MOV_CFG);</script>
@endsection
