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
             varias), Enter = similitudes por LIKE, X = limpiar, e icono de escaneo QR.
             Escribir SOLO refresca las sugerencias — NO dispara la consulta a la tabla. --}}
        <div class="amf-item amf-search">
            <div class="amf-search-wrap">
                <div class="amf-search-box {{ $prodActivo ? 'active' : '' }}">
                    <i class="material-icons lupa">search</i>
                    <input type="text" id="almMovSearch" autocomplete="off" placeholder="Buscar producto (código o descripción)…" value="{{ $reqSearch }}"
                           oninput="window.almMovBuscarInput()"
                           onfocus="window.almMovSuggestFn()"
                           onkeydown="window.almMovBuscarKey(event)">
                    {{-- Escanear QR: icono dentro del propio buscador, visible solo con el campo
                         vacío (comparte lugar con la "x" de limpiar). En teléfono abre la cámara;
                         en PC enfoca este buscador para que el lector USB teclee aquí. --}}
                    <i class="material-icons qrs-ic" id="almMovBuscarScan" title="Escanear código QR"
                       style="display:{{ $prodActivo ? 'none' : 'flex' }};"
                       onclick="window.QrScan.abrir()">&#xf206;</i>
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
                    {{-- Fecha + Tipo combinados en una sola columna (la pill de tipo va
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

{{-- Escaneo QR (modal de cámara + estilo del icono del buscador): partial COMPARTIDO con
     Inventario y Recepción. Va ANTES del script de la vista porque su <script> registra
     las rutas que usa el QrScan.init de más abajo. --}}
@include('admin.almacen.partials.scan_modal')

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

    // SPA: al re-montar esta vista el <script> se vuelve a ejecutar. Redefinir las funciones de
    // window y releer el estado de la URL es DESEABLE (así el filtro por producto y la lista de
    // notas se re-siembran con los datos frescos), pero volver a registrar los listeners de
    // `document`/`window` los APILA: un clic de paginación dispararía loadMovimientos() una vez
    // por cada visita a la página. Por eso el cuerpo de la IIFE sí se re-ejecuta y sólo el
    // registro de listeners globales queda protegido por bandera.
    //
    // Los handlers cierran sobre ROUTE/el/hv (estables entre montajes) y llaman a las funciones
    // por `window.*`, así que los del primer montaje siguen siendo válidos para los siguientes.
    var _globalsBound = window._almMovGlobalsBound === true;
    window._almMovGlobalsBound = true;
    function docOn(type, fn, capture) { if (!_globalsBound) document.addEventListener(type, fn, capture); }
    function winOn(type, fn) { if (!_globalsBound) window.addEventListener(type, fn); }

    // Producto(s) elegido(s) en el buscador, como CSV de IDs — ESTADO ÚNICO del filtro de
    // producto. Un clic en una sugerencia manda todos los IDs de esa descripción (uno solo si
    // no tiene varias presentaciones), así la bitácora filtra por ID y no por substring.
    // Se siembra desde la URL aceptando las DOS formas: id_producto_in (sugerencia agrupada) e
    // id_producto (link puntual, p.ej. desde el detalle de un producto). Al teclear / Enter /
    // limpiar vuelve a null → manda la búsqueda por texto (LIKE).
    // Lo lee también el modo offline (movimientos-offline.js), que replica estos filtros.
    window.almMovPickedIds = (function () {
        var q = new URLSearchParams(window.location.search);
        return q.get('id_producto_in') || q.get('id_producto') || null;
    })();

    function buildParams(pageUrl) {
        var p = new URLSearchParams();
        // OJO: cuando el usuario elige "Todos los almacenes" mandamos `id_almacen=all`
        // explícito. Si no lo enviamos, el controller cree que NO vino el param y
        // aplica el default-por-frente, volviendo a filtrar (el usuario veía solo su
        // almacén aunque hubiera pedido "todos").
        var alm = hv('id_almacen'); if (alm) p.set('id_almacen', alm);
        var tipo = hv('tipo'); if (tipo && tipo !== 'all') p.set('tipo', tipo);
        var fr = hv('id_frente'); if (fr && fr !== 'all') p.set('id_frente', fr);
        var d = el('almMovDesde'); if (d && d.value) p.set('desde', d.value);
        var h = el('almMovHasta'); if (h && h.value) p.set('hasta', h.value);
        var nt = el('almMovNota'); if (nt && nt.value.trim()) p.set('nota', nt.value.trim());
        // Producto(s) elegido(s): trae SOLO esos y sus equivalencias en el consumo. Tiene
        // precedencia sobre `search` (LIKE), así que cuando hay pick NO mandamos `search`
        // (el cuadro muestra "Nº · descripción", que como texto no encontraría nada).
        var s = el('almMovSearch');
        if (window.almMovPickedIds) {
            p.set('id_producto_in', window.almMovPickedIds);
        } else if (s && s.value.trim()) {
            p.set('search', s.value.trim());
        }
        // pageUrl presente = paginación: el ranking de consumo no cambia entre páginas,
        // así que pedimos al backend que lo omita (skip_consumo) y no recalcule el agregado.
        if (pageUrl) {
            try { var pg = new URL(pageUrl, window.location.origin).searchParams.get('page'); if (pg) p.set('page', pg); } catch (e) {}
            p.set('skip_consumo', '1');
        }
        return p;
    }

    // Trae SOLO el ranking de consumo (agregado ~90ms) en una petición aparte, para que NO
    // bloquee la aparición de la tabla. Se llama en paralelo al cambiar filtros/búsqueda
    // (no en paginación: el ranking no cambia entre páginas). Si falla, se conserva el previo.
    function cargarConsumoMov() {
        var cc = el('almMovConsumoContainer'); if (!cc) return;
        var p = buildParams();            // filtros actuales (sin pageUrl)
        p.set('consumo_only', '1');
        window.apiFetch(ROUTE + '?' + p.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.consumo !== undefined) cc.innerHTML = d.consumo; })
            .catch(function () {});
    }

    window.loadMovimientos = function (pageUrl) {
        var body = el('almMovTableBody'); if (!body) return;
        var p = buildParams(pageUrl);
        // URL "limpia" para el historial (sin flags internos de rendimiento).
        var pHist = new URLSearchParams(p.toString()); pHist.delete('skip_consumo');
        // La TABLA nunca espera el agregado de consumo (~90ms): siempre skip_consumo → aparece rápido.
        p.set('skip_consumo', '1');
        var url = ROUTE + '?' + p.toString();
        body.style.opacity = '0.5';
        if (window.showPreloader) window.showPreloader();
        window.apiFetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.html !== undefined) body.innerHTML = data.html;
                var pg = el('almMovPagination'); if (pg) pg.innerHTML = data.pagination || '';
                { var e = el('almMovTotal'); if (e && data.total !== undefined) e.textContent = data.total; }
                almMovBuscarUI();   // marca "activo" del buscador + iconos (x / escanear)
                try { window.history.replaceState(null, '', ROUTE + '?' + pHist.toString()); } catch (e) {}
            })
            .catch(function () {
                body.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:#dc2626;">No se pudieron cargar los movimientos.</td></tr>';
                // El aviso "Sin conexión" con su botón lo saca el interceptor global de
                // fetch (estructura_base) para CUALQUIER petición de la app.
            })
            .finally(function () { body.style.opacity = '1'; if (window.hidePreloader) window.hidePreloader(); });

        // El ranking de consumo se refresca SOLO al cambiar filtros/búsqueda (no en paginación),
        // en paralelo — la tabla ya no lo espera.
        if (!pageUrl) cargarConsumoMov();
    };

    // Filtro de fecha ROBUSTO (Desde/Hasta). Un <input type="date"> devuelve '' mientras
    // la fecha esté INCOMPLETA (falta día, mes o año) y su valor completo solo cuando los
    // tres segmentos están puestos. Antes solo se escuchaba 'change', que al TECLEAR exige
    // salir del campo (blur) para dispararse → parecía "no funcionar" si el usuario no
    // salía. Ahora escuchamos también 'input': recarga EN CUANTO la fecha queda completa
    // (o al limpiarla), sin blur. El dedup por dataset.lastApplied evita recargas repetidas
    // mientras se teclea (valor '' incompleto no cambia) y la doble recarga input+change.
    window.almMovFechaFiltro = function (el) {
        if (!el) return;
        var v = el.value || '';
        // Sincronizar el icono "X" (limpiar) y el fondo azul de la caja con si hay fecha,
        // también cuando se elige en vivo por el calendario/tecleo (no solo en la carga).
        var clr = document.getElementById(el.id + 'Clear'); if (clr) clr.style.display = v ? 'inline-flex' : 'none';
        var box = document.getElementById(el.id + 'Box');   if (box) box.style.background = v ? '#e1effa' : 'white';
        if (el.dataset.lastApplied === v) return; // sin cambio real → no recargar
        el.dataset.lastApplied = v;
        window.loadMovimientos();
    };

    // ── Lista de productos para el autocomplete del filtro de búsqueda ──
    // El map() se arma en un bloque PHP (no en línea dentro de la directiva json): esa
    // directiva separa sus argumentos por comas y las comas del array literal la rompían
    // ("Unclosed '["). OJO: no escribir tokens tipo arroba-php/arroba-json aquí — Blade
    // los compila aunque estén dentro de un comentario // de JS.
    // Catálogo para el buscador FuzzySearch de la bitácora. ANTES se embebía inline aquí
    // (~300 KB de los 1155 productos) y el módulo abría pesado. AHORA arranca vacío y se carga
    // por AJAX apenas la página queda lista, REUSANDO el mismo endpoint compartido del índice
    // (almacen.productos-autocomplete, misma fuente listaAutocomplete) → no bloquea el render y
    // no se duplica la lista. El buscador lo usa por evento, así que carga async es seguro.
    window.almMovProductosLista = [];
    (function () {
        if (window.almMovProductosCargando) return;
        window.almMovProductosCargando = true;
        window.apiFetch(@json(route('almacen.productos-autocomplete')), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(function (lista) { window.almMovProductosLista = Array.isArray(lista) ? lista : []; window.almMovProductosCargados = true; })
        .catch(function () { /* silencioso: el buscador queda vacío hasta que reintente */ })
        .finally(function () { window.almMovProductosCargando = false; });
    })();

    window.almMovSuggestHide = function () { var b = document.getElementById('almMovSuggest'); if (b) b.classList.remove('open'); };

    window.almMovSuggestFn = function () {
        var inp = document.getElementById('almMovSearch'), box = document.getElementById('almMovSuggest');
        if (!inp || !box) return;
        var rawTerm = inp.value.trim();
        var lista = window.almMovProductosLista || [];

        // Agrupación por descripción + ranking + dedupe: reglas COMPARTIDAS con el filtro
        // "Descripción" del inventario (window.ProductoSuggest). Una descripción con varias
        // presentaciones (distinta UM = producto aparte) sale UNA vez con un badge de cuántas
        // tiene; al elegirla se filtran TODAS (id_producto_in). Los filtros no se agrupan:
        // los del mismo nombre son modelos distintos.
        var grupos  = window.ProductoSuggest.agrupar(lista);
        var matches = window.ProductoSuggest.dedupe(window.ProductoSuggest.rankear(lista, rawTerm), grupos, 17);

        var html = '';
        if (!matches.length) {
            // Mientras el catálogo async no cargó, "Cargando…" en vez de "Sin coincidencias"
            // (que sugeriría por error que el producto no existe). El Enter→servidor sigue vivo.
            html = (!window.almMovProductosCargados)
                ? '<div class="amf-suggest-empty">Cargando productos…</div>'
                : '<div class="amf-suggest-empty">Sin coincidencias.</div>';
        } else {
            html = matches.map(function (p) {
                var nom = (p.NOMBRE || '').replace(/[<>&"]/g, '');
                var cod = (p.CODIGO || '').replace(/[<>&"]/g, '');
                // El código/serial NO se muestra en la lista (igual que el buscador del
                // inventario): la sugerencia queda limpia con SOLO la descripción. Igual se
                // PUEDE filtrar por serial/código — el ranking lo incluye en el haystack
                // (CODIGO + NOMBRE + EQUIV) — y el código sigue en el title de la fila (hover).
                var grp = grupos[window.ProductoSuggest.claveGrupo(p)] || { count: 1, ids: [p.ID_PRODUCTO] };
                // data-pids = los IDs de ESTA descripción cuando tiene varias presentaciones;
                // si es única, es su solo id. En ambos casos el clic filtra por id (nunca por
                // substring, a diferencia del LIKE de `search`).
                var pids  = (grp.ids || [p.ID_PRODUCTO]).join(',');
                var badge = window.ProductoSuggest.badgePresentaciones(grp, 'amf-suggest-cod');
                var parteMostrar = window.FuzzySearch.matchedPart(rawTerm, p.PARTES, p.PARTE);
                // Nº de parte / equivalencia que COINCIDE con lo buscado, DELANTE de la
                // descripción (misma lógica que inventario/recepción). Mismo tamaño/tipo que la
                // descripción (.nom): 13.5px, #475569, peso 600 — así toda la letra se ve igual.
                var parteSafe   = parteMostrar ? String(parteMostrar).replace(/[<>&"]/g, '') : '';
                var partePrefix = parteSafe
                    ? '<span class="amf-suggest-parte" style="font-size:13.5px;color:#475569;font-weight:600;margin-right:6px;white-space:nowrap;">' + parteSafe + '</span>'
                    : '';
                // data-pick = texto que va al cuadro (el nº de parte que coincidió DELANTE de la
                // descripción, si matcheó por equivalencia).
                var pickText = parteSafe ? (parteSafe + ' · ' + nom) : nom;
                return '<div class="amf-suggest-item" data-pids="' + pids + '" data-pick="' + pickText + '" title="' + cod + '">'
                     + '<div class="amf-suggest-line">' + partePrefix + '<span class="nom">' + nom + '</span>' + badge + '</div>'
                     + '</div>';
            }).join('');
        }
        box.innerHTML = html;
        box.classList.add('open');
    };

    // ── Reglas del filtro de producto (las MISMAS que el filtro "Descripción" de
    //    /admin/almacen) ─────────────────────────────────────────────────────────
    //   (a) Escribir refresca solo la LISTA, NO la tabla; y descarta el producto elegido antes
    //       (si el usuario vuelve a teclear, ya quiere otra cosa).
    //   (b) Clic en una sugerencia → los IDs de esa descripción (uno, o todos si tiene varias
    //       presentaciones) → id_producto_in: filtra por ID, nunca por substring.
    //   (c) Enter → similitudes vía LIKE %term% del backend (sin IDs).
    //   (d) X → limpia texto + producto elegido y recarga sin filtro.

    // ¿Hay filtro de producto puesto? Texto tecleado O IDs elegidos por clic (que pueden venir
    // de la URL con el cuadro vacío). Criterio ÚNICO: lo consultan el estado visual de abajo,
    // el icono de escaneo (cfg.activo de QrScan) y el modo offline.
    function almMovProdActivo() {
        var i = el('almMovSearch');
        return !!((i && i.value.trim()) || window.almMovPickedIds);
    }
    // Estado visual del buscador: fondo azul si hay filtro de producto activo y los iconos
    // "x" / "escanear" alternándose (solo uno a la vez). Punto ÚNICO para los cuatro flujos.
    function almMovBuscarUI() {
        var inp = el('almMovSearch'); if (!inp) return;
        var activo = almMovProdActivo();
        var caja = inp.closest('.amf-search-box'); if (caja) caja.classList.toggle('active', activo);
        var x = el('almMovSearchClear'); if (x) x.style.display = activo ? 'block' : 'none';
        window.QrScan.iconToggle();   // escanear visible solo si NO hay filtro de producto
    }

    /**
     * ÚNICO punto de escritura del filtro de producto.
     *   texto  → lo que queda en el cuadro ('' lo vacía)
     *   idsCsv → IDs elegidos ('' / null = sin pick, vuelve la búsqueda por texto)
     * Los tres flujos que lo cambian (clic en sugerencia, Enter, X) pasan por aquí, así que
     * el estado, la UI y la recarga no se pueden desincronizar.
     */
    window.almMovBuscarPick = function (texto, idsCsv) {
        var inp = el('almMovSearch'); if (inp) inp.value = texto || '';
        window.almMovPickedIds = idsCsv ? String(idsCsv) : null;
        almMovBuscarUI();
        window.almMovSuggestHide();
        window.loadMovimientos();
    };
    window.almMovBuscarLimpiar = function () { window.almMovBuscarPick('', null); };

    window.almMovBuscarInput = function () {
        // Editar el texto descarta el producto elegido, pero NO recarga la tabla todavía
        // (eso lo hacen Enter / clic en sugerencia / X).
        window.almMovPickedIds = null;
        almMovBuscarUI();
        window.almMovSuggestFn();
    };
    window.almMovBuscarKey = function (ev) {
        if (!ev) return;
        if (ev.key === 'Escape') { window.almMovSuggestHide(); return; }
        if (ev.key !== 'Enter') return;
        ev.preventDefault();
        var inp = el('almMovSearch');
        // Enter = buscar por TEXTO (LIKE), descartando el pick anterior.
        window.almMovBuscarPick(inp ? inp.value : '', null);
    };

    // Escaneo QR sobre ESTE buscador (icono + cámara en teléfono + lector USB en PC). El
    // código escaneado se resuelve contra el catálogo y entra por el MISMO pick que un clic
    // en la sugerencia → filtra por el ID de ese producto.
    window.QrScan.init({
        input:      'almMovSearch',
        icono:      'almMovBuscarScan',
        activo:     almMovProdActivo,
        onProducto: function (p, label) { window.almMovBuscarPick(label, String(p.id)); },
    });

    // Delegación de clic en sugerencias
    docOn('click', function (e) {
        var item = e.target.closest('#almMovSuggest .amf-suggest-item');
        if (item) {
            window.almMovBuscarPick(item.getAttribute('data-pick') || '', item.getAttribute('data-pids') || null);
            return;
        }
        if (!e.target.closest('#almMovSearch') && !e.target.closest('#almMovSuggest')) window.almMovSuggestHide();
    });

    // ── Autocomplete del filtro "Nota de entrega" ──────────────────────────────
    // Lista de N° de Nota de Entrega (NE-…) de salida en almacenes visibles — la pasa el
    // controller a TODOS los usuarios (a diferencia de la del modal "Eliminar Nota", que
    // va gateada). Sugiere por substring al escribir; clic en una → completa el filtro y
    // recarga. Con el campo vacío (foco) muestra las más recientes.
    window.almMovNotasFiltro = @json($notasFiltro ?? []);
    window.almMovNotaSuggestHide = function () { var b = document.getElementById('almMovNotaSuggest'); if (b) b.classList.remove('open'); };
    window.almMovNotaSuggest = function () {
        var inp = document.getElementById('almMovNota'), box = document.getElementById('almMovNotaSuggest');
        if (!inp || !box) return;
        var q = String(inp.value || '').trim().toUpperCase();
        var lista = window.almMovNotasFiltro || [];
        var matches = q
            ? lista.filter(function (n) { return String(n).toUpperCase().indexOf(q) !== -1; }).slice(0, 12)
            : lista.slice(0, 12);
        if (!matches.length) {
            box.innerHTML = '<div class="amf-suggest-empty">' + (q ? 'Sin Notas que coincidan.' : 'No hay Notas de Entrega.') + '</div>';
        } else {
            box.innerHTML = matches.map(function (n) {
                var safe = String(n).replace(/[<>&"]/g, '');
                return '<div class="amf-suggest-item" data-pick-nota="' + safe + '"><div class="amf-suggest-line"><span class="amf-suggest-cod">' + safe + '</span></div></div>';
            }).join('');
        }
        box.classList.add('open');
    };
    // Delegación de clic en las sugerencias del filtro de Nota (mismo patrón que el buscador).
    docOn('click', function (e) {
        var item = e.target.closest('#almMovNotaSuggest .amf-suggest-item');
        if (item) {
            var inp = document.getElementById('almMovNota');
            if (inp) inp.value = item.getAttribute('data-pick-nota') || '';
            var clr = document.getElementById('almMovNotaClear'); if (clr) clr.style.display = (inp && inp.value) ? 'block' : 'none';
            window.almMovNotaSuggestHide();
            window.loadMovimientos();
            return;
        }
        if (!e.target.closest('#almMovNota') && !e.target.closest('#almMovNotaSuggest')) window.almMovNotaSuggestHide();
    });

    // Selección en los custom-dropdown → recargar
    winOn('dropdown-selection', function (e) {
        if (!document.getElementById('almMovTableBody')) return;
        var id = e.detail && e.detail.dropdownId;
        if (id === 'almMovFiltroAlmacen' || id === 'almMovFiltroFrente' || id === 'almMovTipoDropdown') {
            window.loadMovimientos();
        }
    });

    // Click en una fila del ranking de consumo → filtra la bitácora a ESE producto. Reusa el
    // mismo "pick" del buscador (filtra por ID): antes solo pegaba el nombre y recargaba, así
    // que un producto elegido antes en el buscador seguía pegado y ganaba.
    window.almMovFiltrarPorProducto = function (idProducto, nombre) {
        window.almMovBuscarPick(nombre || '', idProducto ? String(idProducto) : null);
    };

    // Paginación AJAX
    docOn('click', function (e) {
        var a = e.target.closest('#almMovPagination a.page-link') || e.target.closest('#almMovPagination a');
        if (a) { e.preventDefault(); e.stopImmediatePropagation(); window.loadMovimientos(a.href); }
    }, true);

    // ── Seleccion de tarjeta en mobile (toggle azul) ──
    // Mismo patron de UX que /admin/equipos: tocar la tarjeta la resalta en azul. Nada mas:
    // la tarjeta ya NO abre el PDF (lo hace su boton con el icono de documento) ni revela
    // burbuja alguna — la de referencia estaba declarada display:none en sus dos estados y
    // nunca llegaba a verse.
    //
    // Early return: un click dentro de .mv-td-ref (la celda que en movil ES el boton del PDF)
    // deja correr el onclick del enlace y no toca la seleccion.
    docOn('click', function (e) {
        if (e.target.closest('#almMovTableBody .mv-td-ref')) return;
        var tr = e.target.closest('#almMovTableBody tr.alm-mov-row');
        if (!tr) return;
        document.querySelectorAll('#almMovTableBody tr.alm-mov-row.mv-row-selected').forEach(function (other) {
            if (other !== tr) other.classList.remove('mv-row-selected');
        });
        tr.classList.toggle('mv-row-selected');
        // Antes, en teléfono, seleccionar la tarjeta ABRÍA el PDF de la nota. Tocar en
        // cualquier sitio para ver la fila resaltada te lanzaba el visor encima, sin aviso
        // previo y sin poder seleccionar sin abrirlo. Ahora el PDF lo abre SOLO el botón con
        // el icono de documento (.mv-nota-link, arriba a la derecha de la tarjeta), que además
        // solo existe cuando el movimiento tiene nota. Ese botón entra por el early-return de
        // .mv-td-ref de arriba, así que su onclick corre sin togglear la selección.
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
    docOn('click', function (e) {
        var p = el('almMovFechasPanel');
        if (p && p.style.display === 'block' && !e.target.closest('#almMovFechasPanel') && !e.target.closest('#btnAdvancedFilterMov')) p.style.display = 'none';
    });

    // ── Dropdown "Acciones" (toggle + cierre al click fuera) ────────────────
    // Mismo patrón del dropdown de /admin/movilizaciones (toggleAccionesMov):
    // sufijo "Inv" para no colisionar con el del módulo de movilizaciones si
    // ambos cargan en una SPA.
    // Descarga la bitácora en XLSX con los filtros que se están viendo. Reutiliza
    // buildParams() —el mismo que arma la petición de la tabla— para que el archivo no pueda
    // traer un recorte distinto del que hay en pantalla. Va por window.location y no por
    // navigateTo: es una descarga, no una navegación SPA.
    window.almMovExportarExcel = function () {
        var m = document.getElementById('splitDropdownMenuMovInv');
        if (m) m.style.display = 'none';
        var qs = buildParams().toString();
        window.location.href = @json(route('almacen.movimientosExport')) + (qs ? ('?' + qs) : '');
    };

    window.toggleAccionesMovInv = function (ev) {
        if (ev) ev.stopPropagation();
        var m = el('splitDropdownMenuMovInv'); if (!m) return;
        var p = el('almMovFechasPanel'); if (p) p.style.display = 'none';

        // Forma correcta de cerrar los custom-dropdowns de uicomponents.js
        if (typeof window.closeAllDropdowns === 'function') window.closeAllDropdowns();
        document.querySelectorAll('.custom-dropdown.active').forEach(d => d.classList.remove('active'));
        // Limpiar cualquier estilo inline residual
        document.querySelectorAll('.dropdown-content').forEach(d => d.style.display = '');

        // Refrescar el href del link "Historial de Notas de Entrega" con los filtros activos para
        // que la vista por nota se abra ya filtrada por almacén / frente / fechas.
        //
        // Solo se reenvían los filtros que la vista por nota interpreta igual que la bitácora:
        //   · El `search` de la bitácora es el PRODUCTO; en notas() matchea NUMERO_NOTA / RQ /
        //     CONTRATO / SOLICITANTE. Reenviarlo filtraba números de nota por el nombre del
        //     material y devolvía la lista vacía → no se manda.
        //   · El filtro `nota` SÍ equivale al `search` de notas() (ambos son el N° de nota).
        //   · El `tipo` de la bitácora son las claves de grupo ENTRADAS/SALIDAS; notas() espera
        //     los TIPO exactos (SALIDA / TRASPASO_SALIDA) y ya lista ambos por defecto, que es
        //     justo lo que significa el grupo SALIDAS → no hay nada que reenviar.
        var lnk = el('lnkBitNotas');
        if (lnk) {
            var p2 = new URLSearchParams();
            var alm = hv('id_almacen'); if (alm) p2.set('id_almacen', alm);
            var fr  = hv('id_frente'); if (fr && fr !== 'all') p2.set('id_frente', fr);
            var nt  = el('almMovNota'); if (nt && nt.value.trim()) p2.set('search', nt.value.trim());
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
    docOn('click', function (e) {
        var t = e.target.closest('.dropdown-trigger');
        if (!t) return;
        if (!t.closest('#almMovFechasPanel')) {
            var p = el('almMovFechasPanel'); if (p) p.style.display = 'none';
        }
        if (!t.closest('#splitDropdownMenuMovInv')) {
            var m = el('splitDropdownMenuMovInv'); if (m) m.style.display = 'none';
        }
    }, true);
    docOn('click', function (e) {
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

{{-- Modal "Devolución de material" (botón Devolver de las salidas con nota). Se gatea él
     mismo con almacen.movimiento. --}}
@include('admin.almacen.partials.devolucion_modal')

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

<script>
(function(){
    // Bandera por instancia: si el modal ya tiene listeners (SPA: misma vista re-montada)
    // no duplicar handlers de Escape ni redefinir funciones.
    if (window._almNotaModalsReady) return;
    window._almNotaModalsReady = true;

    var CSRF = window.getCsrf();
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
                var r = await window.apiFetch(URL_DESTROY + '?numero=' + encodeURIComponent(raw), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    method: 'DELETE'
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
                window.toast(okMsg, 'success');
                // Recargar la tabla de movimientos para que aparezcan las devoluciones de la reversión.
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
            var CSRF = window.getCsrf();
            btn.disabled = true;
            if (window.showPreloader) window.showPreloader();
            window.apiFetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                method: 'DELETE'
            })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, status: r.status, d: d }; });
            })
            .then(function (res) {
                if (window.hidePreloader) window.hidePreloader();
                if (!res.ok) {
                    window.toast(res.d.message || ('Error del servidor (' + res.status + ').'), 'error');
                    btn.disabled = false;
                    return;
                }
                window.toast(res.d.message || opts.okMsg, 'success');
                // Recargar la bitácora: la fila desaparece. En el deshacer los saldos
                // posteriores ya vienen recalculados; en el borrado solo-historial NO
                // (a propósito) — el stock no se tocó.
                if (window.loadMovimientos) window.loadMovimientos();
            })
            .catch(function () {
                if (window.hidePreloader) window.hidePreloader();
                window.toast('No se pudo contactar al servidor.', 'error');
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

    // El texto de la confirmación lo da el servidor (data-impacto-url →
    // AlmacenController::impactoDeshacerMovimiento): dice qué más se lleva el deshacer —el
    // resto de la Nota de Entrega, las devoluciones, el envío en Recepción— con las mismas
    // reglas que el deshacer de verdad. Si no responde, queda el aviso genérico.
    window.almDeshacerMovimiento = function (btn) {
        if (!btn || btn.disabled) return;
        var confirmar = function (avisos) {
            almBorrarFilaKardex(btn, {
                urlAttr: 'data-undo-url',
                title: '¿Deshacer este movimiento?',
                message: avisos.map(window.escapeHtml).join('<br>'),
                confirmText: 'Deshacer',
                okMsg: 'Movimiento deshecho.'
            });
        };
        var generico = ['Se revierte el stock y se borra sin dejar rastro.'];
        var url = btn.getAttribute('data-impacto-url');
        if (!url) { confirmar(generico); return; }

        if (window.showPreloader) window.showPreloader();
        window.apiFetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .catch(function () { return null; })
            .then(function (d) {
                if (window.hidePreloader) window.hidePreloader();
                confirmar(d && Array.isArray(d.avisos) && d.avisos.length ? d.avisos : generico);
            });
    };

    window.almEliminarSoloHistorial = function (btn) {
        almBorrarFilaKardex(btn, {
            urlAttr: 'data-purge-url',
            title: '¿Eliminar del historial?',
            message: 'Se borra del historial; el stock no cambia. Irreversible.',
            confirmText: 'Eliminar del historial',
            okMsg: 'Registro eliminado del historial.'
        });
    };
</script>
@endsection
