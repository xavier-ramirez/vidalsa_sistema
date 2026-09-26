@extends('layouts.estructura_base')

@section('title', 'Almacén')

@section('content')
<link rel="stylesheet" href="{{ asset('css/vistas/admin_almacen_index.css') }}?v={{ @filemtime(public_path('css/vistas/admin_almacen_index.css')) }}">

@php
    $reqAlm    = $almacenSel?->ID_ALMACEN;
    $reqBuscar = request('search');
    $reqCat    = request('categoria');
    // Permisos:
    //   $puedeAlmManage  → super.admin (CRUD de almacenes / warehouses).
    //   $puedeProductos  → almacen.productos (CRUD del catalogo de productos).
    //   $puedeMover      → almacen.movimiento (entradas/salidas/ajustes/traspasos + confirmar recepciones).
    //   $puedeEliminar   → almacen.nota.eliminar (borrar un producto del catalogo).
    //   $puedeManage     → flag combinado (almacenes O productos) que controla la
    //                      ENTRADA al bloque JS compartido — los routes individuales
    //                      adentro hacen el check fino segun la accion.
    $puedeAlmManage = auth()->user()?->can('super.admin')        ?? false;
    $puedeProductos = auth()->user()?->can('almacen.productos')  ?? false;
    $puedeManage    = $puedeAlmManage || $puedeProductos;
    $puedeMover     = auth()->user()?->can('almacen.movimiento') ?? false;
    $puedeEliminar  = auth()->user()?->can('almacen.nota.eliminar') ?? false;
    $st = $stats ?? ['total' => '—', 'con_saldo' => '—', 'stock_bajo' => '—', 'unidades' => 0];
    // Formatos de Nota de Entrega [valor => etiqueta]. Salen del modelo para que las opciones
    // del selector, sus textos y lo que valida el backend NO se puedan desincronizar: un
    // formato nuevo aparece aquí solo (ver Almacen::FORMATOS_NOTA).
    $formatosNota   = \App\Models\Almacen::FORMATOS_NOTA;
    $formatoNotaDef = \App\Models\Almacen::FORMATO_NOTA_VERTICAL;
    // Datos de los almacenes para el modal de edición (solo se usa si $puedeAlmManage).
    $almacenesData = ($almacenes ?? collect())->keyBy('ID_ALMACEN')->map(function ($a) {
        return [
            'NOMBRE'            => $a->NOMBRE,
            'TIPO'              => $a->TIPO,
            'UBICACION'         => $a->UBICACION,
            'ALMACENISTA'       => $a->ALMACENISTA,
            'CARGO_ALMACENISTA' => $a->CARGO_ALMACENISTA,
            // Normalizado en el modelo: si la fila trae null o basura, llega VERTICAL.
            'FORMATO_NOTA'      => $a->formatoNota(),
            // Firmantes fijos de la nota horizontal — el modal los recarga al editar (ver
            // ALM_FIRMANTES_CAMPOS). Son 10 strings cortos por almacén y hay un puñado de
            // almacenes: no engorda la página de forma apreciable.
            'CEDULA_ALMACENISTA' => $a->CEDULA_ALMACENISTA,
            'SOPORTE_1_NOM'      => $a->SOPORTE_1_NOM,
            'SOPORTE_1_CAR'      => $a->SOPORTE_1_CAR,
            'SOPORTE_1_CED'      => $a->SOPORTE_1_CED,
            'SOPORTE_2_NOM'      => $a->SOPORTE_2_NOM,
            'SOPORTE_2_CAR'      => $a->SOPORTE_2_CAR,
            'SOPORTE_2_CED'      => $a->SOPORTE_2_CED,
            'SEGURIDAD_NOM'      => $a->SEGURIDAD_NOM,
            'SEGURIDAD_CAR'      => $a->SEGURIDAD_CAR,
            'SEGURIDAD_CED'      => $a->SEGURIDAD_CED,
            'frentes'           => $a->relationLoaded('frentes') ? $a->frentes->pluck('ID_FRENTE')->values() : [],
        ];
    });
@endphp

<section class="page-title-card" style="text-align:left;margin:16px 0 10px 0;">
    {{-- Layout: título a la izquierda + separador vertical + filtro de almacén.
         El bloque del filtro tiene un fondo gris suave para diferenciarse del título sin competir con él. --}}
    <div style="display:flex;justify-content:flex-start;align-items:center;gap:20px;flex-wrap:wrap;">
        <h1 class="page-title" style="margin:0;">
            <span class="page-title-line2" style="color:#000;">Inventario de Almacén</span>
        </h1>
        {{-- Separador vertical (oculto en mobile cuando el filtro se va abajo) --}}
        <span aria-hidden="true" style="display:inline-block;width:1px;height:34px;background:#cbd5e0;flex:0 0 auto;"></span>
        <div style="display:flex;align-items:center;gap:10px;flex:0 1 auto;">
            {{-- .alm-sel-alm-box: la regla de mobile que lo estira a todo el ancho engancha
                 por ESTA clase. Antes lo hacía con `div[style*="width:280px"]`, que se rompe
                 en silencio con solo escribir "width: 280px" con un espacio. --}}
            <div class="alm-sel-alm-box" style="width:280px;min-width:200px;max-width:100%;">
                <div class="custom-dropdown" id="almSelAlmacenDropdown" data-filter-type="id_almacen" data-default-label="Todos los almacenes">
                    <input type="hidden" name="id_almacen" data-filter-value id="almSelAlmacen" value="{{ $reqAlm ?? '' }}">
                    <div class="dropdown-trigger {{ $almacenSel ? 'filter-active' : '' }}" style="padding:0;display:flex;align-items:center;background:#f8fafc;overflow:hidden;border:1px solid #cbd5e0;border-radius:10px;height:40px;transition:border-color .15s,background .15s;">
                        <span style="padding:0 10px;display:flex;align-items:center;color:#0067b1;"><i class="material-icons" style="font-size:18px;transform:none !important;">warehouse</i></span>
                        <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                               placeholder="{{ $almacenSel ? $almacenSel->NOMBRE : 'Todos los almacenes' }}"
                               style="flex:1;border:none;background:transparent;padding:8px 5px;font-size:13.5px;font-weight:600;color:#0f172a;outline:none;min-width:0;"
                               oninput="window.filterDropdownOptions(this)">
                    </div>
                    <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                        <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                            @foreach($almacenes as $a)
                                <div class="dropdown-item {{ $almacenSel && $almacenSel->ID_ALMACEN == $a->ID_ALMACEN ? 'selected' : '' }}" data-value="{{ $a->ID_ALMACEN }}"
                                     onclick="selectOption('almSelAlmacenDropdown','{{ $a->ID_ALMACEN }}','{{ addslashes($a->NOMBRE) }}');">
                                    {{ $a->NOMBRE }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="page-layout-grid">
<div class="admin-card" style="margin:0;min-height:80vh;min-width:0;width:100%;padding:14px;">

    {{-- ── Banner: total de notas de entrega pendientes por confirmar ──
         Una sola línea con el conteo (sin listar cada nota); toda la tarjeta es un link
         que lleva a la bandeja de recepción. --}}
    @if(($notasPendientes ?? 0) > 0)
        @php $nPend = $notasPendientes; @endphp
        <a href="{{ route('almacen.recepcion.index', ['force' => 1]) }}"
           style="display:flex;align-items:center;gap:10px;background:linear-gradient(135deg,#fef2f2 0%,#fee2e2 100%);border:1px solid #ef4444;border-radius:10px;padding:9px 14px;margin-bottom:10px;color:#991b1b;text-decoration:none;transition:box-shadow .15s;"
           onmouseenter="this.style.boxShadow='0 2px 8px rgba(239,68,68,0.25)'" onmouseleave="this.style.boxShadow='none'">
            <i class="material-icons" style="font-size:20px;color:#dc2626;flex:0 0 auto;">notifications_active</i>
            <span style="flex:1;font-size:13px;font-weight:700;min-width:0;">
                <strong style="font-size:15px;">{{ $nPend }}</strong>
                {{ $nPend === 1 ? 'nota de entrega pendiente' : 'notas de entrega pendientes' }} por confirmar en la bandeja
            </span>
            <span style="display:flex;align-items:center;gap:4px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;flex:0 0 auto;">
                Ver todas <i class="material-icons" style="font-size:18px;">arrow_forward</i>
            </span>
        </a>
    @endif

    {{-- ── Filtros ── (el filtro de almacén está junto al título, no aquí) --}}
    <div id="almFilters">
        {{-- Buscar (código o descripción) — con sugerencias estilo app. Ancho amplio.
             Patron "placeholder de fondo" (mismo que dropdowns de /admin/equipos):
               · value=""                    → el input arranca vacio para escribir directo
               · placeholder="<term activo>" → se ve el filtro activo en gris (como background)
               · data-active="<term>"        → el JS lee de aqui para reconstruir la URL al
                                                cambiar otro filtro (asi no se pierde el activo
                                                mientras el usuario no escribe nada nuevo). --}}
        @php $bActivo = trim((string) ($reqBuscar ?? '')); @endphp
        <div class="alm-filter {{ $bActivo ? 'active' : '' }}" style="flex:3 1 380px;max-width:660px;">
            <div class="alm-filter-box">
                {{-- La lupa BUSCA al tocarla: en el teléfono es la salida visible cuando el
                     teclado no trae tecla de buscar. En PC no estorba (hace lo mismo que
                     Enter). Antes era decorativa.
                     Sin role="button" a propósito: con teclado ya se busca con Enter en el
                     propio campo, así que anunciarla como botón sin poder enfocarla solo
                     estorbaría. Es un atajo para el dedo, no un control aparte. --}}
                <span class="alm-ic alm-ic-buscar" aria-hidden="true"
                      title="Buscar" onclick="window.almBuscarEnter()"><i class="material-icons" style="font-size:18px;">search</i></span>
                {{-- enterkeyhint="search": sin esto el teclado del teléfono muestra
                     "siguiente", que solo mueve el foco y NO dispara ningún Enter — se
                     escribía, se pulsaba y no pasaba nada. Con esto la tecla pasa a ser
                     "Buscar" y sí manda el Enter que espera almBuscarEnter. --}}
                <input type="text" id="almFiltroBuscar" autocomplete="off"
                       enterkeyhint="search"
                       placeholder="{{ $bActivo ?: 'Buscar por código o descripción…' }}"
                       value=""
                       data-active="{{ $bActivo }}"
                       data-placeholder-empty="Buscar por código o descripción…"
                       oninput="window.almBuscarInput()" onfocus="window.almBuscarFocus()"
                       onkeydown="window.almBuscarEnter(event)">
                {{-- Escanear QR: icono dentro del propio buscador. Visible cuando el campo
                     está vacío; al escribir/filtrar se oculta y aparece la "x" de limpiar
                     (toggle en QrScan.iconToggle, llamado desde filtros()/almBuscarInput).
                     En TELÉFONO se ve el icono de escanear (abre la cámara). En PC se ve en
                     su lugar el de KITS: allí el QR no abría nada, porque un lector USB
                     teclea directamente en este buscador. --}}
                <i class="material-icons qrs-ic" id="almBuscarScan" title="Escanear código QR"
                   style="display:none;"
                   onclick="window.QrScan.abrir()">&#xf206;</i>
                {{-- En PC, donde no hay cámara, ese hueco lo ocupa el acceso a KITS: el QR
                     allí no abría nada (solo enfocaba este mismo buscador). Cuál de los dos
                     se ve lo decide QrScan.iconToggle, que es quien sabe si es teléfono. --}}
                <i class="material-icons qrs-ic" id="almBuscarKits" title="Kits por equipo"
                   style="display:none;"
                   onclick="window.almAbrirKits && window.almAbrirKits()">inventory_2</i>
                <i class="material-icons filter-clear" style="display:{{ $bActivo ? 'flex' : 'none' }};"
                   onclick="window.almBuscarLimpiar()">close</i>
            </div>
            <div class="alm-suggest" id="almFiltroBuscarSuggest"></div>
        </div>

        {{-- Categoría — mismo patron placeholder-background. --}}
        @php $cActivo = ($reqCat && $reqCat !== 'all') ? trim((string) $reqCat) : ''; @endphp
        <div class="alm-filter {{ $cActivo ? 'active' : '' }}" style="flex:1 1 190px;">
            <div class="alm-filter-box">
                <span class="alm-ic"><i class="material-icons" style="font-size:18px;">search</i></span>
                <input type="text" id="almFiltroCat" autocomplete="off"
                       placeholder="{{ $cActivo ?: 'Filtrar por categoría…' }}"
                       value=""
                       data-active="{{ $cActivo }}"
                       data-placeholder-empty="Filtrar por categoría…"
                       oninput="window.almCatInput()" onfocus="window.almCatFocus()"
                       onkeydown="window.almCatEnter(event)">
                <i class="material-icons filter-clear" style="display:{{ $cActivo ? 'flex' : 'none' }};"
                   onclick="window.almCatLimpiar()">close</i>
            </div>
            <div class="alm-suggest" id="almFiltroCatSuggest"></div>
        </div>

        {{-- Filtros avanzados (el mismo botón de Movimientos, Notas y Recepción): la unidad
             de medida, que viaja como `um`. "Con stock" / "Stock bajo" son las tarjetas. --}}
        <div style="position:relative;flex:0 0 auto;">
            <button type="button" id="almAdvBtn" class="btn-primary-maquinaria btn-filtro-avanzado" title="Filtros avanzados"
                    onclick="window.almToggleAvanzado()">
                <i class="material-icons">filter_list</i>
            </button>
            <div id="almAdvPanel" class="panel-filtro-avanzado" style="display:none;">
                <h4 class="panel-filtro-avanzado-titulo">
                    Filtros Avanzados
                    <span class="panel-filtro-avanzado-limpiar" onclick="window.almAvanzadoLimpiar()">Limpiar Todo</span>
                </h4>
                <span class="panel-filtro-avanzado-label">Unidad de medida</span>
                {{-- Mismo custom-dropdown que el resto de la app (Estado en Recepción): se abre
                     hacia abajo y con buscador. Antes era un <select> nativo, que el navegador
                     abría hacia arriba. selectOption escribe en el hidden #almFiltroUm (lo lee
                     filtros()) y emite 'dropdown-selection' → almAvanzadoUm. --}}
                @php $umSel = (string) request('um', ''); @endphp
                <div class="custom-dropdown" id="almFiltroUmDropdown" data-filter-type="um">
                    <input type="hidden" id="almFiltroUm" data-filter-value value="{{ $umSel }}">
                    <div class="dropdown-trigger {{ $umSel !== '' ? 'filter-active' : '' }}" style="padding:0;display:flex;align-items:center;background:{{ $umSel !== '' ? '#e1effa' : '#fbfcfd' }};overflow:hidden;border:1px solid {{ $umSel !== '' ? '#0067b1' : '#cbd5e0' }};border-radius:8px;height:38px;">
                        <input type="text" data-filter-search autocomplete="off" aria-label="Unidad de medida"
                               placeholder="{{ $umSel !== '' ? $umSel : 'Todas' }}"
                               style="flex:1;border:none;background:transparent;padding:8px 10px;font-weight:400;color:#0f172a;outline:none;min-width:0;"
                               oninput="window.filterDropdownOptions(this)">
                        <i class="material-icons" data-clear-btn title="Quitar la unidad de medida"
                           style="padding:0 4px;color:#64748b;font-size:16px;cursor:pointer;transform:none !important;display:{{ $umSel !== '' ? 'block' : 'none' }};"
                           onclick="event.stopPropagation(); selectOption('almFiltroUmDropdown','','Todas');">close</i>
                        <i class="material-icons" style="padding:0 6px;color:#64748b;font-size:16px;pointer-events:none;transform:none !important;">expand_more</i>
                    </div>
                    <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                        <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                            <div class="dropdown-item {{ $umSel === '' ? 'selected' : '' }}" data-value="" onclick="selectOption('almFiltroUmDropdown','','Todas');">Todas</div>
                            @foreach(($unidadesMedida ?? collect()) as $u)
                                <div class="dropdown-item {{ $umSel === $u ? 'selected' : '' }}" data-value="{{ $u }}"
                                     onclick="selectOption('almFiltroUmDropdown', this.dataset.value, this.dataset.value);">{{ $u }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Acciones (botón desplegable estilo /admin/equipos) --}}
        <div style="display:flex;gap:8px;margin-left:auto;flex:0 0 auto;align-items:center;">
            <div style="position:relative;">
                <button type="button" id="almBtnAcciones" class="btn-primary-maquinaria"
                        style="height:45px;padding:0 16px;min-width:150px;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);"
                        onclick="window.almToggleAcciones(event)">
                    <i class="material-icons" style="font-size:18px;">settings</i><span class="desktop-text">Acciones</span><i class="material-icons" style="font-size:18px;">expand_more</i>
                </button>
                <div id="almAccionesMenu" style="display:none;position:absolute;top:100%;right:0;width:280px;background:#e2e8f0;border-radius:8px;box-shadow:0 10px 18px -3px rgba(0,0,0,0.18);border:1px solid #e2e8f0;z-index:60;margin-top:6px;overflow:hidden;animation:slideDown 0.18s ease-out;">
                    {{-- Dashboard de Consumo: abre el modal con gráficos (Chart.js). Mismo
                         modal/endpoint que en /admin/almacen/movimientos. --}}
                    <button type="button" onclick="document.getElementById('almAccionesMenu').style.display='none'; window.abrirConsumoDashboard();" class="dropdown-item-custom" style="display:flex;align-items:center;gap:10px;padding:11px 14px;color:#475569;background:transparent;border:none;border-bottom:1px solid #f1f5f9;width:100%;text-align:left;cursor:pointer;">
                        <div style="background:#e0f2fe;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#0067b1;">analytics</i></div>
                        <span style="font-size:14px;font-weight:500;">Dashboard de consumo</span>
                    </button>
                    {{-- Kits por equipo: recetas de materiales que cargan la salida de un golpe
                         (partials/kits_modal). Visible para todos, como el resto del menú: el
                         permiso se pide dentro, al armar un kit o al cargarlo en la salida. --}}
                    <button type="button" onclick="document.getElementById('almAccionesMenu').style.display='none'; window.almAbrirKits();" class="dropdown-item-custom" style="display:flex;align-items:center;gap:10px;padding:11px 14px;color:#475569;background:transparent;border:none;border-bottom:1px solid #f1f5f9;width:100%;text-align:left;cursor:pointer;">
                        <div style="background:#e0f2fe;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#0067b1;">inventory_2</i></div>
                        <span style="font-size:14px;font-weight:500;">Kits por equipo</span>
                    </button>
                    {{-- Despachos: abre la Reposición del general (la bandeja donde
                         los almacenes de proyecto confirman lo que el general les despachó) y
                         dice cuántas notas faltan por recibir. El MISMO item que en la
                         bitácora, con la misma cuenta (AlmacenController::porRecibirDeProyectos)
                         y la misma pill de la columna Estado (Traspaso::ESTADOS_META). Corto a
                         propósito ("Despachos" + el número): la frase entera va en el title. --}}
                    <a href="{{ route('almacen.recepcion.index', ['force' => 1]) }}" class="dropdown-item-custom alm-acc-despacho"
                       title="{{ ($porRecibirPry ?? 0) > 0 ? $porRecibirPry . ' notas por recibir' : 'Todo recibido' }} · abre la Reposición del general"
                       onclick="event.preventDefault(); document.getElementById('almAccionesMenu').style.display='none'; if(window.navigateTo) window.navigateTo(this.href); else window.location.href=this.href;">
                        <div style="background:#e0f2fe;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#0067b1;">local_shipping</i></div>
                        <span style="font-size:14px;font-weight:500;">Despachos</span>
                        @php
                            [, $pillBg, $pillFg] = \App\Models\Traspaso::ESTADOS_META[($porRecibirPry ?? 0) > 0
                                ? \App\Models\Traspaso::ESTADO_ENVIADO
                                : \App\Models\Traspaso::ESTADO_RECIBIDO];
                        @endphp
                        <span class="alm-acc-pill" style="background:{{ $pillBg }};color:{{ $pillFg }};">{{ ($porRecibirPry ?? 0) > 0 ? $porRecibirPry : 'Al día' }}</span>
                    </a>
                    {{-- Descargar Excel: disponible para cualquier usuario que pueda ver el
                         módulo. Construye la URL de export respetando los filtros de almacén
                         y categoría activos. --}}
                    <button type="button" onclick="window.almAccion('export')" class="dropdown-item-custom" style="display:flex;align-items:center;gap:10px;padding:11px 14px;color:#475569;background:transparent;border:none;border-bottom:1px solid #f1f5f9;width:100%;text-align:left;cursor:pointer;">
                        <div style="background:#f1f5f9;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#64748b;">download</i></div>
                        <span style="font-size:14px;font-weight:500;">Descargar Excel</span>
                    </button>
                    <button type="button" onclick="window.almAccion('producto')" class="dropdown-item-custom" style="display:flex;align-items:center;gap:10px;padding:11px 14px;color:#475569;background:transparent;border:none;border-bottom:1px solid #f1f5f9;width:100%;text-align:left;cursor:pointer;">
                        <div style="background:#e0f2fe;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#0284c7;">add_circle</i></div>
                        <span style="font-size:14px;font-weight:500;">Nuevo producto</span>
                    </button>
                    {{-- Todos los items SIEMPRE visibles — la verificacion de permiso vive
                         dentro del handler JS de cada funcion (ver almAbrirAlmacen, etc.).
                         Si el usuario no tiene el permiso, aparece toast moderno; antes los
                         botones se ocultaban — el cliente pidio cambio: ver lo que existe +
                         notificacion de denegacion, no ocultar nada. --}}
                    <button type="button" onclick="window.almAccion('admin')" class="dropdown-item-custom" style="display:flex;align-items:center;gap:10px;padding:11px 14px;color:#475569;background:transparent;border:none;border-bottom:1px solid #f1f5f9;width:100%;text-align:left;cursor:pointer;">
                        <div style="background:#f1f5f9;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#475569;">warehouse</i></div>
                        <span style="font-size:14px;font-weight:500;">Gestionar almacenes</span>
                    </button>
                    <button type="button" onclick="window.almAccion('almacen')" class="dropdown-item-custom" style="display:flex;align-items:center;gap:10px;padding:11px 14px;color:#475569;background:transparent;border:none;border-bottom:1px solid #f1f5f9;width:100%;text-align:left;cursor:pointer;">
                        <div style="background:#e0f2fe;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#0284c7;">add_business</i></div>
                        <span style="font-size:14px;font-weight:500;">Nuevo almacén</span>
                    </button>
                    {{-- Papelera: productos eliminados (soft-delete) — buscar y restaurar. --}}
                    <button type="button" onclick="document.getElementById('almAccionesMenu').style.display='none'; window.almAbrirPapelera();" class="dropdown-item-custom" style="display:flex;align-items:center;gap:10px;padding:11px 14px;color:#475569;background:transparent;border:none;width:100%;text-align:left;cursor:pointer;">
                        {{-- Papelera: MISMO glifo que usa /admin/equipos en su menú Acciones
                             (delete_outline). El color se queda en ámbar, no en el rojo de
                             Equipos: allá la acción borra al instante, aquí solo ABRE la
                             papelera para restaurar — pintarla de rojo prometería un borrado. --}}
                        <div style="background:#fef3c7;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#d97706;">delete_outline</i></div>
                        <span style="font-size:14px;font-weight:500;">Papelera de productos</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Aviso de la salida por corregir: lo pinta almPintarAvisoSalida. --}}
    <div id="almSalidaAviso" role="alert" hidden></div>

    {{-- "Sin coincidencias exactas, mostrando parecidos": lo pinta almPintarAvisoBusqueda
         cuando el servidor responde aproximada=true (ver AlmacenController::index). --}}
    <div id="almBuscarAviso" role="status" hidden></div>

    {{-- ── Tabla ── --}}
    <div class="alm-table-wrap" style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:12px;">
        <table class="alm-table">
            <thead>
                <tr>
                    {{-- Foto del producto (sin título: se explica sola y así no roba ancho).
                         El CÓDIGO no tiene columna: va dentro de Descripción, pequeño y
                         encima del nombre (ver .alm-cod-mini y partials/table_rows). --}}
                    <th style="width:54px;padding:10px 6px;"></th>
                    <th>Descripción del producto</th>
                    <th>Categoría</th>
                    <th style="text-align:center;">Stock</th>
                    {{-- Salida: SIEMPRE visible — el cuerpo (partials/table_rows)
                         renderiza esta columna para todos (6 columnas fijas); el permiso
                         almacen.movimiento solo bloquea ABRIR la salida, no la captura.
                         Gatearla aquí desajustaba el thead respecto al tbody. El input se
                         habilita solo cuando la fila está seleccionada. --}}
                    <th style="text-align:center;width:84px;">Salida</th>
                    {{-- Columna de Detalles (botón "ojo"): sin título para ahorrar ancho. --}}
                    <th style="text-align:center;width:38px;padding:10px 6px;"></th>
                </tr>
            </thead>
            <tbody id="almTableBody">
                @include('admin.almacen.partials.table_rows', ['productos' => $productos, 'almacen' => $almacenSel, 'inicial' => true, 'reparto' => $repartoInicial])
            </tbody>
        </table>
    </div>

    {{-- Indicador inferior mientras la auto-carga trae los lotes siguientes. --}}
    <div id="almLoadingMore" style="display:none;text-align:center;padding:12px;color:#64748b;font-size:12.5px;font-weight:600;">
        <i class="material-icons" style="font-size:16px;vertical-align:middle;animation:spin 1s linear infinite;">refresh</i>
        Cargando más productos…
    </div>
</div>

{{-- ── Sidebar: Consolidado de Inventario ── --}}
{{-- id: la camara del panel de distribucion fotografia esta columna ENTERA, para que
     la imagen salga con el Consolidado arriba y la distribucion debajo. --}}
<div id="almLateral" class="counter-sidebar" style="position:sticky;top:20px;display:flex;flex-direction:column;gap:8px;">

    <div class="alm-cons-card">
        <i class="material-icons alm-cons-bgicon">inventory</i>
        <div class="alm-cons-inner">
            <div class="alm-consolidado-title">
                <i class="material-icons">pie_chart</i> Consolidado de Inventario
            </div>

            <div class="alm-cons-row">
                <div class="alm-cons-total" onclick="window.almVerTodo()" title="Quitar filtros">
                    <span id="almStatsTotal" class="num">{{ $st['total'] }}</span>
                    <span class="lbl">Productos</span>
                </div>
                <div class="alm-cons-badges">
                    <div id="almBadgeConSaldo" class="alm-cons-badge alm-cons-saldo" onclick="window.almFiltrarConSaldo()" title="Solo con saldo (clic para activar/desactivar)">
                        <i class="material-icons">inventory_2</i>
                        <strong id="almStatsConSaldo">{{ $st['con_saldo'] }}</strong>
                        <span>Con stock</span>
                    </div>
                    <div id="almBadgeBajo" class="alm-cons-badge alm-cons-bajo" onclick="window.almFiltrarBajo()" title="Solo stock bajo (clic para activar/desactivar)">
                        <i class="material-icons">warning</i>
                        <strong id="almStatsBajo">{{ $st['stock_bajo'] }}</strong>
                        <span>Stock bajo</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Wrapper del panel lateral (distribución por categoría / "En otros almacenes"). Lleva
         id="almDistWrapper" porque en mobile el JS lo mueve a DESPUES de la tabla (separado del
         Consolidado, que queda donde esta). Abre con la distribución del almacén ya pintada
         (AlmacenController::panelLateral); cada recarga de la tabla lo reemplaza (almCargar →
         distribucionHtml) y el clic en una fila lo cambia al producto tocado (almPanelOtros).
         El contenedor va SIN espacios alrededor del HTML: el CSS lo esconde con :empty. --}}
    <div id="almDistWrapper" style="background:white;border-radius:12px;padding:15px;border:1px solid #e2e8f0;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);overflow:hidden;">
        <div id="almDistribucionContainer">{!! $distribucionHtml ?? '' !!}</div>
    </div>
</div>

</div>{{-- /page-layout-grid --}}

{{-- ── Barra flotante de selección (clic en la fila → se resalta y aparece esta
     barra). Visible para TODOS; el botón "Salida" valida el permiso al pulsarse
     (almSelAccion → ensurePerm). Capturar la cantidad NO exige permiso — solo
     ABRIR el modal de salida y ejecutarla. --}}
<div id="almBulkBar" class="selection-floating-bar">
    <div id="almBulkCounter" class="selection-counter alm-bulk-counter"
         onclick="window.almToggleSoloSel(event)"
         title="Clic para filtrar la tabla y ver SOLO los productos seleccionados (clic de nuevo para volver a ver todos).">
        <div style="background:rgba(255,255,255,0.1);padding:5px;border-radius:50%;display:flex;"><i class="material-icons" style="font-size:18px;color:white;">inventory_2</i></div>
        <span id="almBulkCount">0</span>
    </div>
    <div style="width:1px;height:24px;background:rgba(255,255,255,0.2);"></div>
    <div style="display:flex;gap:10px;">
        <button type="button" onclick="window.almSelClear(event)" class="btn-bulk-clear" onmouseover="this.style.color='white'" onmouseout="this.style.color='#94a3b8'">
            <span class="desktop-text">Limpiar</span>
        </button>
        {{-- Botón único "Salida". Abre el modal Nota de Entrega; el backend decide si es
             consumo (mismo almacén) o envío a otro proyecto (TRASPASO) según el frente destino. --}}
        <button type="button" onclick="window.almSelAccion()" class="btn-bulk-action">
            <i class="material-icons" style="font-size:18px;">shopping_cart</i><span class="desktop-text">Salida</span>
        </button>
        {{-- Etiquetas QR de los productos seleccionados (flujo "marcar filas → imprimir
             sus etiquetas"). Reusa la misma selección (almSeleccion) que la Salida. --}}
        <button type="button" id="almBulkEtqBtn" onclick="window.almSelEtiquetas()" class="btn-bulk-action alm-bulk-sec">
            <i class="material-icons" style="font-size:18px;">&#xe00a;</i><span class="desktop-text">Etiquetas</span>
        </button>
    </div>
</div>

{{-- ════════════════════════ MODALES ════════════════════════ --}}

{{-- ── Modal: Generar etiquetas QR (elige formato y produce el PDF) ──────────
     Sin gate de permiso (read-only, igual que el export). idsCsv lo fija quien lo
     abre: dropdown Acciones (vacío = filtro de categoría actual), barra de selección
     (los seleccionados) o el modal de detalle (un producto). --}}
<div id="almEtiquetasModal" class="alm-modal-overlay">
    <div class="alm-modal" style="max-width:360px;">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">&#xe00a;</i> Generar etiquetas QR</h3>
            <i class="material-icons alm-x" onclick="almCerrar('almEtiquetasModal')">close</i>
        </div>
        <div class="alm-modal-body" style="gap:10px;">
            {{-- Productos a etiquetar, arriba: SOLO con VARIOS, cada uno con su propio campo de
                 cantidad al lado. Con uno (o ninguno) la cantidad es la de abajo. Lo pinta
                 almAbrirEtiquetas. --}}
            <div id="almEtqModoLista" style="display:none;">
                <div id="almEtqLista" style="max-height:190px;overflow-y:auto;display:flex;flex-direction:column;gap:10px;"></div>
            </div>
            {{-- Fila inferior: cantidad de etiquetas y el lápiz del tamaño. El tamaño de la tira
                 NO se ve de entrada —casi siempre solo interesa cuántas—: el lápiz lo muestra
                 (almEtqVerFormato) y al abrir el modal vuelve a esconderse en 50 × 30.
                 El campo de cantidad se OCULTA cuando hay varios productos, porque entonces
                 cada uno lleva el suyo arriba (ver almAbrirEtiquetas). --}}
            <div style="display:flex;gap:10px;align-items:center;justify-content:center;">
                <input type="number" id="almEtqCopias" class="alm-nota-input" value="1" min="1" max="200" step="1"
                       aria-label="Cantidad de etiquetas" title="Cantidad de etiquetas"
                       style="width:78px;flex:0 0 auto;text-align:center;">
                <button type="button" id="almEtqFormatoBtn" class="alm-etq-lapiz" onclick="window.almEtqVerFormato()"
                        title="Cambiar el tamaño de la etiqueta" aria-label="Cambiar el tamaño de la etiqueta" aria-expanded="false">
                    <i class="material-icons">edit</i>
                </button>
                {{-- Desplegable "Formato": las dos tiras de la etiquetadora o la hoja carta
                     (30 por hoja, impresora normal). Mismo componente custom-dropdown del resto de la app
                     (selectOption escribe en el hidden #almEtqFormato, que lee almEtiquetasGenerar).
                     data-filter-type no engancha filtros: el listener de dropdown-selection no
                     hace nada con este desplegable.
                     Ya no lleva <label> encima: el propio campo muestra el tamaño elegido
                     ("Rollo 50 × 30 mm") y va rotulado por aria-label. --}}
                <div class="custom-dropdown" id="almEtqFormatoDropdown" data-filter-type="formato_etq" data-default-label="Rollo 50 × 30 mm" style="flex:1;min-width:0;" hidden>
                    <input type="hidden" id="almEtqFormato" data-filter-value value="50x30">
                    <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:#fff;overflow:hidden;border:1px solid #cbd5e0;border-radius:7px;height:32px;">
                        <input type="text" id="almEtqFormatoSearch" data-filter-search autocomplete="off" readonly
                               placeholder="Rollo 50 × 30 mm" aria-label="Tamaño de la etiqueta"
                               style="flex:1;border:none;background:transparent;padding:0 10px;font-size:13.5px;color:#0f172a;outline:none;min-width:0;cursor:pointer;">
                        <i class="material-icons" style="padding:0 8px;color:#94a3b8;font-size:20px;">expand_more</i>
                    </div>
                    <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                        <div class="dropdown-item-list">
                            <div class="dropdown-item selected" data-value="50x30" onclick="selectOption('almEtqFormatoDropdown','50x30','Rollo 50 × 30 mm');">Rollo 50 × 30 mm</div>
                            <div class="dropdown-item" data-value="40x25" onclick="selectOption('almEtqFormatoDropdown','40x25','Rollo 40 × 25 mm');">Rollo 40 × 25 mm</div>
                            <div class="dropdown-item" data-value="carta" onclick="selectOption('almEtqFormatoDropdown','carta','Hoja carta (30 por hoja)');">Hoja carta (30 por hoja)</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almEtiquetasModal')">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" onclick="window.almEtiquetasGenerar()"><i class="material-icons" style="font-size:17px;vertical-align:-3px;margin-right:4px;">&#xe00a;</i>Aceptar</button>
        </div>
    </div>
</div>

{{-- ── Modal: Papelera de productos (eliminados/soft-delete) ─────────────────
     Lista los productos borrados para buscarlos y restaurarlos. Restaurar deja el
     producto activo de nuevo con su stock intacto (almacen_stock no se borra). --}}
<div id="almPapeleraModal" class="alm-modal-overlay">
    {{-- Mismo modal que el resto de Almacén (.alm-modal): encabezado oscuro con el título
         centrado y la X a la derecha. El glifo es el del item "Papelera de productos" del
         menú Acciones que lo abre (delete_outline), para que se reconozca. --}}
    <div class="alm-modal">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">delete_outline</i> Papelera de productos</h3>
            <i class="material-icons alm-x" onclick="almCerrar('almPapeleraModal')">close</i>
        </div>
        <div class="alm-modal-body" style="gap:10px;overflow:hidden;">
            <div style="display:flex;align-items:center;border:1px solid #cbd5e0;border-radius:8px;background:#fff;overflow:hidden;height:38px;flex-shrink:0;">
                <i class="material-icons" style="padding:0 8px;color:#94a3b8;font-size:18px;">search</i>
                <input type="text" id="almPapeleraSearch" placeholder="Buscar por código o descripción…" autocomplete="off"
                       style="flex:1;border:none;outline:none;padding:0 6px;font-size:14px;background:transparent;height:100%;"
                       oninput="window.almPapeleraBuscar()">
            </div>
            {{-- Reusa .alm-admin-list (columna + gap): las filas son las mismas de
                 "Gestionar almacenes". Aquí solo se agrega el alto máximo con scroll. --}}
            <div id="almPapeleraLista" class="alm-admin-list" style="max-height:360px;overflow-y:auto;">
                <div style="text-align:center;color:#94a3b8;font-size:13px;padding:24px 0;">Cargando…</div>
            </div>
        </div>
    </div>
</div>

{{-- Escaneo QR (modal de cámara + estilo del icono del buscador): partial COMPARTIDO
     con Movimientos y Recepción. La lógica vive en window.QrScan
     (public/js/maquinaria/qr_scan.js, global → SPA-safe); aquí solo se engancha al
     buscador más abajo con QrScan.init. --}}
@include('admin.almacen.partials.scan_modal')

{{-- Modal antiguo "Registrar entrada / Registrar salida" por producto individual:
     ELIMINADO en 2026-05-13. Las entradas reales ahora se hacen desde
     /admin/almacen/recepcion (modal "Entrada directa") y las salidas desde
     este mismo módulo seleccionando filas + barra flotante (Nota de Entrega).
     Para correcciones puntuales del saldo de un producto se usa el modal
     "Auditoría de Inventario" que sigue abajo. --}}

{{-- Auditoría de Inventario (ajuste del saldo por conteo físico).
     El "Stock mínimo (alerta)" YA NO vive aquí: tiene su propio modal #almMinimoModal
     (botón propio en "Detalles del producto"), para no mezclar dos operaciones distintas.
     Gateado server-side por $puedeMover (almacen.movimiento) — mismo patrón que
     #almSalidaModal y #almAdminAlmacenesModal. Sin la clave el modal ni siquiera
     se renderiza en el DOM; almAbrirAjuste/almGuardarAjuste validan además en
     cliente con ensurePerm(HAS_MOVER). --}}
@if($puedeMover)
<div id="almAjusteModal" class="alm-modal-overlay">
    {{-- 340px en vez de los 440 por defecto: aquí solo hay el saldo actual y UN campo corto
         (el conteo, que ya va limitado a 200px). Con el ancho normal quedaba medio modal
         vacío a los lados. Es el mínimo que no aprieta el título del encabezado. --}}
    <div class="alm-modal" style="max-width:340px;">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">fact_check</i> Auditoría de Inventario</h3>
            <i class="material-icons alm-x" onclick="almCerrar('almAjusteModal')">close</i>
        </div>
        <div class="alm-modal-body">
            {{-- Saldo actual (según el sistema): sin esto el usuario no sabía desde qué valor
                 estaba ajustando y, si tecleaba justo el mismo número, el backend respondía
                 "no cambia el saldo" y parecía un error. Mostrarlo hace obvia la diferencia.
                 Fondo azul (#e1effa / #bfdbfe): el mismo tinte que la app usa para marcar un
                 campo activo (.dropdown-trigger.filter-active, .cdash-inp-box.active). Antes
                 era gris y se perdía contra el blanco del modal. --}}
            {{-- El recuadro azul y el campo de abajo comparten ANCHO: son los dos lados de la
                 misma comparación —lo que dice el sistema contra lo que se contó— y con
                 anchos distintos se leían como dos bloques sin relación.
                 Ocupan TODO el ancho del cuerpo del modal (antes 240px centrados, que dejaba
                 aire muerto a los lados). Si se cambia uno, cambiar el otro. --}}
            <div style="width:100%;margin:0 0 12px;box-sizing:border-box;display:flex;flex-direction:column;align-items:center;gap:2px;background:#e1effa;border:1px solid #bfdbfe;border-radius:8px;padding:8px 12px;text-align:center;">
                <span style="font-size:12px;color:#475569;font-weight:600;">Saldo actual (sistema)</span>
                <span id="almAjSaldoActual" style="font-size:14px;color:#0067b1;font-weight:800;">—</span>
            </div>
            {{-- Mismo ancho que el recuadro azul de arriba (ver su comentario): el número se
                 sigue escribiendo centrado, pero la caja acompaña al bloque con el que se
                 compara en vez de quedarse más angosta. --}}
            <div style="text-align:center;">
                <label for="almAjNuevoSaldo">Saldo según conteo físico</label>
                <input type="number" id="almAjNuevoSaldo" min="0" step="any" placeholder="Cantidad real contada"
                       style="width:100%;box-sizing:border-box;text-align:center;">
            </div>
            <div id="almAjError" style="display:none;color:#dc2626;font-size:13px;font-weight:600;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="window.almVolverADetalle('almAjusteModal')">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" onclick="window.almGuardarAjuste()">Guardar</button>
        </div>
    </div>
</div>

{{-- Stock mínimo (alerta) — modal propio, separado de la Auditoría. Setea SOLO el mínimo
     de alerta del producto en el almacén actual (PATCH almacen.minimo). Se abre desde su
     botón en "Detalles del producto". Mismo gate que Auditoría ($puedeMover) para no
     cambiar quién podía configurarlo cuando vivía dentro de la Auditoría. --}}
<div id="almMinimoModal" class="alm-modal-overlay">
    {{-- Angosto: solo tiene un número. --}}
    <div class="alm-modal" style="max-width:300px;">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">production_quantity_limits</i> Stock mínimo (alerta)</h3>
            <i class="material-icons alm-x" onclick="almCerrar('almMinimoModal')">close</i>
        </div>
        <div class="alm-modal-body">
            <div>
                {{-- Sin <label>: el título del modal ya dice "Stock mínimo (alerta)" y repetirlo
                     encima del input era redundante. El placeholder explica qué hace el campo.
                     min="0.001" + step="any": cualquier valor > 0 vale (no se acepta 0).
                     Vacio = sin alerta. --}}
                <input type="number" id="almMinValor" min="0.001" step="any" placeholder="Vacío = sin alerta"
                       aria-label="Stock mínimo (alerta)" style="text-align:center;">
            </div>
            <div id="almMinError" style="display:none;color:#dc2626;font-size:13px;font-weight:600;text-align:center;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="window.almVolverADetalle('almMinimoModal')">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" onclick="window.almGuardarMinimo()">Guardar</button>
        </div>
    </div>
</div>
@endif

{{-- ═════════════════════════════════════════════════════════════════
     Modal: ¿De qué proyecto sale?
     En un almacén que separa el saldo por proyecto (PATIO EL TIGRE), seleccionar un producto
     con saldo —en uno o en varios proyectos— no habilita la cantidad de una vez: aquí se ve cuánto tiene
     cada proyecto y se elige de cuál se descuenta. Lo elegido viaja por línea (id_frente_saldo) y
     el despacho empieza por esa bolsa; si no alcanza sigue con la común y el resto, y la vista
     previa de la nota lo avisa. Lo llena almPedirBolsa; elegir es almBolsaElegir. Fuera de los
     @if de permisos: seleccionar filas lo puede cualquiera que vea el almacén.
═════════════════════════════════════════════════════════════════ --}}
<div id="almBolsaModal" class="alm-modal-overlay">
    <div class="alm-modal" style="max-width:440px;">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">call_split</i> ¿De qué proyecto sale?</h3>
            <i class="material-icons alm-x" onclick="almCerrar('almBolsaModal')">close</i>
        </div>
        <div class="alm-modal-body">
            <p class="alm-bolsa-prod" id="almBolsaProducto"></p>
            <div class="alm-bolsa-lista" id="almBolsaLista"></div>
        </div>
        {{-- Sin pie: se cierra con la X (un "Cancelar" hacía lo mismo; lo quitó el cliente). --}}
    </div>
</div>

{{-- ═════════════════════════════════════════════════════════════════
     Modal: KARDEX por producto (Movimientos del producto)
     Se abre desde el modal de Detalles (botón "Ver movimientos").
     Reusa AlmacenController::movimientos con ?mini=1 y filtra por
     id_producto + id_almacen actual + opcional tipo / desde / hasta.
═════════════════════════════════════════════════════════════════ --}}
<div id="almKardexProductoModal" class="alm-modal-overlay">
    {{-- 820px (pedido del cliente: más ancho). Pasó por 680 → 540 (al quitar la columna
         Fecha) → 640, pero el destino se quedaba corto para los frentes de nombre largo, del
         tipo "TUBERÍA DE 30'' VELADERO TRAMO I". Solo afecta a escritorio: en móvil este modal no se abre (el botón .alm-det-act-kardex está
         oculto ≤768px) y la .alm-modal es width:100% por debajo de ese ancho. --}}
    <div class="alm-modal" style="max-width:820px;">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">history</i> Movimientos del producto</h3>
            <i class="material-icons alm-x" onclick="almCerrar('almKardexProductoModal')">close</i>
        </div>
        <div class="alm-modal-body" style="gap:10px;">
            {{-- Cabecera: ficha del producto. El CÓDIGO va en pequeño ENCIMA del nombre
                 (misma jerarquía que la tabla del inventario) y el saldo del almacén
                 actual se lee como dato destacado a la derecha. El JS
                 (almAbrirKardexProducto) setea almKpCodigo SOLO con el número. --}}
            <div class="alm-kp-hero">
                <div class="alm-kp-hero-ic"><i class="material-icons">inventory_2</i></div>
                <div class="alm-kp-hero-txt">
                    <span class="alm-kp-hero-cod" id="almKpCodigo">—</span>
                    <span class="alm-kp-hero-nom" id="almKpNombre"></span>
                </div>
                <div class="alm-kp-hero-stock">
                    <span class="alm-kp-rot">Stock actual</span>
                    <span class="alm-kp-hero-num" id="almKpSaldoBox"><span id="almKpSaldo">0</span><span class="alm-kp-hero-um" id="almKpUm"></span></span>
                </div>
            </div>

            {{-- Filtros: select Tipo + rango de fechas (+ Limpiar, solo si hay alguno puesto) --}}
            <div class="alm-kp-filtros">
                <div class="alm-kp-campo alm-kp-grupo-tipo">
                    <span class="alm-kp-rot">Tipo</span>
                    <select id="almKpTipoSelect" class="alm-kp-select" onchange="window.almKpChipSelect(this.value)">
                        {{-- Mismos valores que el filtro Tipo de la bitácora: ENTRADAS/SALIDAS son
                             grupos (traspasos, devoluciones y auditorías por su signo). --}}
                        <option value="">Todos</option>
                        <option value="ENTRADAS">Entradas</option>
                        <option value="SALIDAS">Salidas</option>
                        <option value="AJUSTE">Auditorías de conteo</option>
                        <option value="DEVOLUCION">Devoluciones</option>
                    </select>
                </div>

                {{-- Por qué este grupo es el que cede ancho: ver .alm-kp-grupo-fechas en el CSS. --}}
                <div class="alm-kp-campo alm-kp-grupo-fechas">
                    <span class="alm-kp-rot">Rango de fechas</span>
                    <div class="alm-kp-rango">
                        {{-- Wrapper clickable: cualquier click en la caja abre el calendario
                             (focus()+showPicker(), mismo patrón que la fecha de la Nota de
                             Entrega). Antes el onclick iba en el input sin focus() → solo abría
                             al tocar el ícono nativo. --}}
                        <div class="alm-kp-fecha-box"
                             onclick="var i=document.getElementById('almKpDesde'); if(i){ i.focus(); if(i.showPicker){ try{ i.showPicker(); }catch(e){} } }">
                            <i class="material-icons">event</i>
                            <input type="date" id="almKpDesde" title="Desde" onchange="window.almKpCargar()">
                        </div>
                        <span class="alm-kp-flecha material-icons">arrow_right_alt</span>
                        <div class="alm-kp-fecha-box"
                             onclick="var i=document.getElementById('almKpHasta'); if(i){ i.focus(); if(i.showPicker){ try{ i.showPicker(); }catch(e){} } }">
                            <i class="material-icons">event</i>
                            <input type="date" id="almKpHasta" title="Hasta" onchange="window.almKpCargar()">
                        </div>
                    </div>
                </div>

                {{-- El id NO puede ser "almKpLimpiar": los elementos con id se exponen como
                     window.<id> y chocaría con la función global del mismo nombre. --}}
                <button type="button" id="almKpBtnLimpiar" class="alm-kp-limpiar" hidden
                        onclick="window.almKpLimpiar()" title="Quitar los filtros">
                    <i class="material-icons">close</i>Limpiar
                </button>
            </div>

            {{-- Tabla compacta: 5 columnas (sin Producto, ya conocido; sin Fecha, que el
                 cliente pidió quitar — el rango sigue filtrable arriba). El thead queda
                 sticky para que se vea al hacer scroll.

                 Anchos en PORCENTAJE que suman 100: Destino se lleva lo que le sobra a las
                 columnas cortas. Sin table-layout:fixed a propósito: los porcentajes mandan
                 mientras el contenido quepa, pero una cantidad larga puede ensanchar su
                 columna en vez de desbordarse (Tipo/Cantidad/Stock/Documento son nowrap). --}}
            <div class="alm-kp-tabla-wrap">
                <table style="width:100%;border-collapse:separate;border-spacing:0;">
                    <thead>
                        <tr>
                            <th style="width:13%;text-align:center;white-space:nowrap;">Tipo</th>
                            <th style="width:16%;text-align:center;white-space:nowrap;">Cantidad</th>
                            <th style="width:11%;text-align:center;white-space:nowrap;">Stock</th>
                            <th style="width:40%;text-align:left;">Destino</th>
                            <th style="width:20%;text-align:center;white-space:nowrap;">Documento</th>
                        </tr>
                    </thead>
                    <tbody id="almKpBody">
                        <tr><td colspan="5" class="alm-kp-estado">Cargando…</td></tr>
                    </tbody>
                </table>
            </div>

            <div id="almKpPag"></div>
        </div>

    </div>
</div>

<style>
/* La paginación del kardex se aprovecha de la del SSR estándar; aquí se renderiza
   centrada y compacta dentro de #almKpPag. Con una sola página el contenido llega
   vacío (''), y display:none saca el div de la fila flex del cuerpo del modal: sin
   él seguiría cobrando su gap de 10px por un bloque que no se ve. */
#almKpPag { font-size:11px; color:#64748b; text-align:center; }
#almKpPag:empty { display:none; }
#almKpPag .pagination, #almKpPag ul { display:inline-flex; gap:3px; flex-wrap:wrap; justify-content:center; margin:0; padding:0; }
#almKpPag .pagination li, #almKpPag ul li { list-style:none; }
#almKpPag a, #almKpPag span { padding:3px 8px; font-size:11px; border-radius:5px; }
</style>

@if($puedeAlmManage)
{{-- Nuevo almacén — solo super.admin. --}}
<div id="almAlmacenModal" class="alm-modal-overlay">
    <div class="alm-modal">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">add_business</i> <span id="almNvTitulo">Nuevo almacén</span></h3>
            <i class="material-icons alm-x" onclick="almCerrar('almAlmacenModal')">close</i>
        </div>
        <div class="alm-modal-body">
            {{-- NOMBRE — combo, no campo libre: en un almacén de PROYECTO el nombre es el del
                 proyecto, y escribirlo a mano dejaba almacenes que no casaban con ningún frente.
                 Mismo componente que "Contrato N°" del modal de salida: la lista manda, pero el
                 input acepta texto porque (a) un almacén GENERAL no es un proyecto —BARCELONA,
                 ALMACÉN CENTRAL CARACAS— y (b) los almacenes que ya existen pueden llamarse
                 distinto que sus frentes (PATIO EL TIGRE sirve a PATIO I EL TIGRE y a otros 5),
                 y un selector estricto los dejaría sin nombre al editarlos.
                 Elegir un proyecto de la lista TAMBIÉN lo tilda abajo en "Frentes que usan este
                 almacén": es el mismo dato dicho dos veces y pedirlo dos veces sobraba. --}}
            <div>
                <label for="almNvNombre">Nombre <span class="alm-label-ayuda" id="almNvNombreHint">Elige el proyecto al que pertenece este almacén.</span></label>
                <div class="custom-dropdown" id="almNvNombreDropdown">
                    <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:#fbfcfd;overflow:hidden;border:1px solid #cbd5e0;border-radius:10px;height:42px;">
                        <input type="text" id="almNvNombre" maxlength="150" autocomplete="off"
                               placeholder="Elige el proyecto…"
                               style="flex:1;border:none;background:transparent;padding:0 12px;font-size:14px;color:#0f172a;outline:none;min-width:0;"
                               oninput="window.almNvNombreFilter(this)">
                        <i class="material-icons" style="padding:0 8px;color:#94a3b8;font-size:20px;">expand_more</i>
                    </div>
                    <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                        <div class="dropdown-item-list" id="almNvNombreItems" style="max-height:240px;overflow-y:auto;">
                            @forelse(($frentesLista ?? collect()) as $f)
                                <div class="dropdown-item" data-nombre="{{ $f->NOMBRE_FRENTE }}" data-frente="{{ $f->ID_FRENTE }}"
                                     onclick="window.almNvNombrePick({{ $f->ID_FRENTE }}, this.dataset.nombre)">{{ $f->NOMBRE_FRENTE }}</div>
                            @empty
                                <div style="padding:10px 15px;font-size:13px;color:#94a3b8;">No hay frentes activos.</div>
                            @endforelse
                        </div>
                        <div id="almNvNombreNoMatch" style="display:none;padding:10px 15px;font-size:13px;color:#94a3b8;">Sin coincidencias.</div>
                    </div>
                </div>
            </div>
            <div>
                <label for="almNvTipoDisplay">Tipo</label>
                <div class="custom-dropdown" id="almNvTipoDropdown" data-default-label="Selecciona un tipo">
                    <input type="hidden" id="almNvTipo" value="PROYECTO">
                    <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:#fbfcfd;overflow:hidden;border:1px solid #cbd5e0;border-radius:10px;height:42px;transition:border-color .15s,background .15s;">
                        <input type="text" data-filter-search autocomplete="off" readonly
                               id="almNvTipoDisplay"
                               value="Proyecto (Limitado a frentes específicos)"
                               style="flex:1;border:none;background:transparent;padding:8px 12px;font-size:14px;font-weight:normal;color:#0f172a;outline:none;min-width:0;cursor:pointer;"
                               onclick="this.closest('.dropdown-trigger').style.borderColor='var(--maquinaria-blue,#0067b1)'">
                        <i class="material-icons" style="padding:0 8px;color:#64748b;font-size:20px;">expand_more</i>
                    </div>
                    <div class="dropdown-content" style="padding:5px;">
                        <div class="dropdown-item" data-value="GENERAL"
                             onclick="almNvTipoSelect('GENERAL','General (almacén central)')">
                            General (almacén central)
                        </div>
                        <div class="dropdown-item selected" data-value="PROYECTO"
                             onclick="almNvTipoSelect('PROYECTO','Proyecto (Limitado a frentes específicos)')">
                            Proyecto (Limitado a frentes específicos)
                        </div>
                    </div>
                </div>
            </div>
            <div><label for="almNvUbicacion">Ubicación <span class="alm-opc">(opcional)</span></label><input type="text" id="almNvUbicacion" maxlength="150" autocomplete="off"></div>
            {{-- Formato de la Nota de Entrega. Lo que se tilde aquí es lo que sale IMPRESO en
                 cada salida de este almacén —y en su vista previa— hasta que se cambie: no hay
                 forma de elegirlo salida por salida, a propósito, para que un mismo almacén no
                 emita notas con dos caras distintas.

                 Va ANTES de los firmantes porque es quien decide cuáles se ven (ver
                 almNvFormatoSelect): primero se elige la hoja, después quién la firma.

                 Se ve como los frentes de aquí abajo (.multiselect-item, el mismo check del CSS
                 global) pero SOLO puede haber uno tildado: tildar uno destilda el otro y nunca
                 quedan los dos —ni ninguno— marcados (ver almNvFormatoSelect). El valor que se
                 manda al backend viaja en el hidden #almNvFormato; los checks son la cara
                 visible de ese único valor. --}}
            <div>
                <label>Formato de la Nota de Entrega</label>
                <input type="hidden" id="almNvFormato" value="{{ $formatoNotaDef }}">
                <div id="almNvFormatoOpts">
                    @foreach($formatosNota as $valFmt => $lblFmt)
                        <label class="multiselect-item">
                            <input type="checkbox" value="{{ $valFmt }}" @checked($valFmt === $formatoNotaDef)
                                   onchange="almNvFormatoSelect('{{ $valFmt }}')">
                            <span>{{ $lblFmt }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            {{-- Firmantes de la nota, en el MISMO orden en que salen impresos.

                 ENTREGADO es el almacenista: sus tres campos son ALMACENISTA /
                 CARGO_ALMACENISTA / CEDULA_ALMACENISTA (ver Almacen::firmantesNota) — un
                 almacén tiene UN almacenista y aquí se lee de un tirón, como los SOPORTADO.

                 ENTREGADO va SIEMPRE visible y los SOPORTADO solo en HORIZONTAL: nombre y
                 cargo los imprimen LOS DOS formatos (el vertical, como "ENTREGADO POR"), así
                 que esconderlos en Vertical dejaría fuera de la vista un campo OBLIGATORIO.
                 La cédula solo la imprime el horizontal, pero se queda con su bloque: guardarla
                 en Vertical no estorba y sirve el día que ese almacén cambie de formato.

                 Los SOPORTADO se OCULTAN, no se destruyen: los valores siguen en el DOM (y en
                 la BD) al pasar a Vertical, así que volver a Horizontal los recupera intactos.

                 SEGURIDAD se configura como los SOPORTADO —y se oculta igual en Vertical—:
                 en el formato del cliente es una persona fija del patio, no "el vigilante de
                 turno" (las 90 notas revisadas de su Excel llevan la misma).

                 RECIBIDO es el único que NO se configura: lo firma quien recibe en el frente
                 destino, y ese sí cambia en cada entrega. --}}
            <div>
                <label>Firmantes de la Nota de Entrega</label>
                <div class="alm-firmantes">
                    <div class="alm-firm-bloque">
                        <div class="alm-firm-rol">ENTREGADO</div>
                        <input type="text" id="almNvAlmacenista" maxlength="200" placeholder="Nombre" autocomplete="off">
                        <div class="alm-firm-fila">
                            <input type="text" id="almNvCargoAlmacenista" maxlength="200" placeholder="Cargo" autocomplete="off">
                            <input type="text" id="almNvCedulaAlmacenista" maxlength="20" placeholder="Cédula" autocomplete="off">
                        </div>
                    </div>
                    <div id="almNvFirmantesWrap" class="alm-firmantes" hidden>
                        <div class="alm-firm-bloque">
                            <div class="alm-firm-rol">SOPORTADO</div>
                            <input type="text" id="almNvSop1Nom" maxlength="120" placeholder="Nombre" autocomplete="off">
                            <div class="alm-firm-fila">
                                <input type="text" id="almNvSop1Car" maxlength="120" placeholder="Cargo" autocomplete="off">
                                <input type="text" id="almNvSop1Ced" maxlength="20" placeholder="Cédula" autocomplete="off">
                            </div>
                        </div>
                        <div class="alm-firm-bloque">
                            <div class="alm-firm-rol">SOPORTADO</div>
                            <input type="text" id="almNvSop2Nom" maxlength="120" placeholder="Nombre" autocomplete="off">
                            <div class="alm-firm-fila">
                                <input type="text" id="almNvSop2Car" maxlength="120" placeholder="Cargo" autocomplete="off">
                                <input type="text" id="almNvSop2Ced" maxlength="20" placeholder="Cédula" autocomplete="off">
                            </div>
                        </div>
                        <div class="alm-firm-bloque">
                            <div class="alm-firm-rol">SEGURIDAD</div>
                            <input type="text" id="almNvSegNom" maxlength="120" placeholder="Nombre" autocomplete="off">
                            <div class="alm-firm-fila">
                                <input type="text" id="almNvSegCar" maxlength="120" placeholder="Cargo" autocomplete="off">
                                <input type="text" id="almNvSegCed" maxlength="20" placeholder="Cédula" autocomplete="off">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            {{-- Logística: los choferes y vehículos con que despacha este almacén. La salida los
                 sugiere en su bloque Transporte (LogisticaAlmacenService) y se imprimen en "Datos
                 del vehículo / Datos del chofer" de la nota. Se guardan con el resto del modal;
                 además, una salida con un chofer o vehículo nuevo lo agrega sola. --}}
            <div>
                <label>Logística de la Nota de Entrega <span class="alm-opc">(opcional)</span></label>
                <div class="alm-firmantes">
                    <div class="alm-firm-bloque">
                        <div class="alm-log-cab">
                            <span class="alm-firm-rol">CHOFERES</span>
                            <button type="button" class="alm-det-mas" onclick="window.almNvLogAgregar('choferes')"><i class="material-icons">add</i>Agregar</button>
                        </div>
                        <div id="almNvLogChoferes" class="alm-log-filas"></div>
                    </div>
                    <div class="alm-firm-bloque">
                        <div class="alm-log-cab">
                            <span class="alm-firm-rol">VEHÍCULOS</span>
                            <button type="button" class="alm-det-mas" onclick="window.almNvLogAgregar('vehiculos')"><i class="material-icons">add</i>Agregar</button>
                        </div>
                        <div id="almNvLogVehiculos" class="alm-log-filas"></div>
                    </div>
                </div>
                <div class="alm-hint">La salida los sugiere junto con los vehículos con placa de los frentes del almacén.</div>
            </div>
            <div id="almNvFrentesWrap">
                <label for="almNvFrentesInput">Frentes que usan este almacén</label>
                <div class="custom-multiselect" id="almNvFrentesSelect">
                    {{-- El trigger es un input directo: clic lo abre y escribir filtra la lista de abajo. --}}
                    <div class="multiselect-trigger" tabindex="-1" role="button" aria-haspopup="listbox" style="padding:0;display:flex;align-items:center;overflow:hidden;cursor:text;">
                        <input type="text" id="almNvFrentesInput" autocomplete="off"
                               placeholder="Selecciona los frentes…"
                               style="flex:1;border:none;background:transparent;padding:10px 12px;font-size:14px;outline:none;min-width:0;color:#0f172a;"
                               oninput="window.almNvFrentesFilter(this)">
                        <i class="material-icons" style="padding:0 12px;color:var(--maquinaria-gray-text);transition:transform 0.3s;">expand_more</i>
                    </div>
                    <div class="multiselect-content">
                        @forelse(($frentesLista ?? collect()) as $f)
                            <label class="multiselect-item alm-frente-opt" for="almNvFrente_{{ $f->ID_FRENTE }}">
                                <input type="checkbox" id="almNvFrente_{{ $f->ID_FRENTE }}" value="{{ $f->ID_FRENTE }}" onchange="window.almNvFrentesUpdate()">
                                <span>{{ $f->NOMBRE_FRENTE }}</span>
                            </label>
                        @empty
                            <div style="padding:10px 15px;font-size:13px;color:#94a3b8;" id="almNvFrentesVacio">No hay frentes activos.</div>
                        @endforelse
                        <div id="almNvFrentesNoMatch" style="display:none;padding:10px 15px;font-size:13px;color:#94a3b8;">Sin coincidencias.</div>
                    </div>
                </div>
            </div>
            <div id="almNvError" style="display:none;margin-top:6px;padding:9px 12px;background:#fee2e2;border:1px solid #fecaca;border-radius:8px;color:#b91c1c;font-size:13px;font-weight:600;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almAlmacenModal')">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" onclick="window.almGuardarAlmacen()">Guardar</button>
        </div>
    </div>
</div>

@endif

@if($puedeProductos)
{{-- Nuevo / Editar producto — usuarios con almacen.productos. --}}
<div id="almProductoModal" class="alm-modal-overlay">
    <div class="alm-modal">
        <div class="alm-modal-head">
            <h3><i class="material-icons" id="almProdIcono" style="font-size:20px;">add_circle</i> <span id="almProdTitulo">Nuevo producto</span></h3>
            <i class="material-icons alm-x" onclick="almCerrar('almProductoModal')">close</i>
        </div>
        <div class="alm-modal-body">
            {{-- Sin campo Código: lo pone SIEMPRE el sistema (AlmacenController::
                 generarCodigoProducto), al crear, y no se cambia al editar. --}}
            <div><label for="almProdNombre">Descripción / producto</label><input type="text" id="almProdNombre" maxlength="200" autocomplete="off"></div>
            {{-- UM + Cantidad inicial en una fila. Cantidad solo se ve al CREAR con un almacén
                 elegido; si no, UM ocupa la fila. --}}
            <div style="display:flex;gap:10px;align-items:flex-start;">
                {{-- Sin position:relative: solo existía para anclar la lista de sugerencias,
                     que ahora es position:fixed (.alm-suggest-float). --}}
                <div style="flex:1;">
                    <label for="almProdUm">Unidad de Medida</label>
                    <input type="text" id="almProdUm" maxlength="20" placeholder="UND, KG, LTS..." value="UND" autocomplete="off"
                           oninput="window.almProdUmSuggest()" onfocus="window.almProdUmSuggest(true)"
                           style="width:100%;box-sizing:border-box;">
                    <div class="alm-suggest-inline alm-suggest-float" id="almProdUmSuggestBox"></div>
                </div>
                {{-- Si está vacío o en 0 el producto queda registrado en el almacén actual con
                     stock 0 (asegurarStock). Si > 0, además se registra una ENTRADA en el kardex
                     como "STOCK INICIAL". --}}
                <div id="almProdCantInicialWrap" style="flex:1;">
                    <label for="almProdCantInicial">Cantidad <span class="alm-opc">(opcional)</span></label>
                    <input type="number" id="almProdCantInicial" min="0" step="any" placeholder="0" autocomplete="off">
                </div>
            </div>
            <div>
                <label for="almProdCategoria">Categoría</label>
                <div class="alm-cat-field">
                    <input type="text" id="almProdCategoria" autocomplete="off" maxlength="100"
                           placeholder="Elige una de la lista o escribe una nueva…"
                           oninput="window.almProdCatSuggest(); window.almProdEquivSyncVisible && window.almProdEquivSyncVisible();" onfocus="window.almProdCatSuggest(true)"
                           onclick="event.stopPropagation(); window.almProdCatSuggest(true);">
                    <button type="button" class="alm-cat-caret" id="almProdCatCaret" tabindex="-1" title="Ver categorías registradas"
                            onclick="window.almProdCatToggle(event)"><i class="material-icons">arrow_drop_down</i></button>
                    {{-- Suggest FLOTANTE (position:fixed, ver .alm-suggest-float): se monta
                         ENCIMA del contenido y FUERA del modal, así no le saca barra de
                         desplazamiento. Mismo patrón que el suggest de UM. --}}
                    <div class="alm-suggest-inline alm-suggest-float" id="almProdCatSuggest"></div>
                </div>
            </div>
            {{-- Equivalencias (nº de parte) — SOLO al EDITAR un FILTRO. Lista editable
                 (agregar/quitar como chips); se sincroniza al Guardar. Oculta para no-filtros
                 y al crear (se agregan editando el filtro ya creado). --}}
            <div id="almProdEquivWrap" style="display:none;">
                <label>Equivalencias (números de parte)</label>
                <div id="almProdEquivList" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
                <input type="text" id="almProdEquivInput" maxlength="100" autocomplete="off"
                       placeholder="Escribe un nº de parte y presiona Enter"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();window.almProdEquivAdd();}"
                       style="margin-top:6px;">
            </div>
            <div id="almProdError" style="display:none;color:#dc2626;font-size:13px;font-weight:600;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="window.almVolverADetalle('almProductoModal')">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" id="almProdSubmit" onclick="window.almGuardarProducto()">Guardar</button>
        </div>
    </div>
</div>

@endif

@if($puedeAlmManage)
{{-- Gestionar almacenes (editar / eliminar) — solo super.admin. --}}
<div id="almAdminAlmacenesModal" class="alm-modal-overlay">
    <div class="alm-modal" style="max-width:440px;">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">warehouse</i> Gestionar almacenes</h3>
            <i class="material-icons alm-x" onclick="almCerrar('almAdminAlmacenesModal')">close</i>
        </div>
        <div class="alm-modal-body">
            <div class="alm-admin-list">
                @forelse($almacenes as $a)
                    <div class="alm-admin-row" data-id="{{ $a->ID_ALMACEN }}">
                        <i class="material-icons" style="font-size:18px;color:{{ $a->TIPO === 'GENERAL' ? '#0067b1' : '#64748b' }};">{{ $a->TIPO === 'GENERAL' ? 'business' : 'store' }}</i>
                        <div style="flex:1;min-width:0;">
                            <div style="font-weight:700;color:#1e293b;font-size:13.5px;">{{ $a->NOMBRE }}</div>
                            <div style="font-size:11.5px;color:#94a3b8;">{{ $a->TIPO === 'GENERAL' ? 'Principal' : 'Proyecto' }}{{ $a->CODIGO ? ' · '.$a->CODIGO : '' }}{{ $a->TIPO === 'PROYECTO' ? ' · '.$a->frentes_count.' frente(s)' : '' }}{{ $a->UBICACION ? ' · '.$a->UBICACION : '' }} · Nota {{ mb_strtolower(\App\Models\Almacen::etiquetaFormatoNota($a->FORMATO_NOTA)) }}</div>
                        </div>
                        <button type="button" class="alm-btn alm-btn-edit" title="Editar"
                                onclick="window.almEditarAlmacen({{ $a->ID_ALMACEN }})"><i class="material-icons" style="font-size:16px;">edit</i></button>
                        <button type="button" class="alm-btn alm-btn-del" title="Eliminar / desactivar"
                                onclick="window.almEliminarAlmacen({{ $a->ID_ALMACEN }}, '{{ addslashes($a->NOMBRE) }}')"><i class="material-icons" style="font-size:16px;">delete_outline</i></button>
                    </div>
                @empty
                    <p style="color:#94a3b8;font-size:13px;text-align:center;padding:20px 0;">No hay almacenes. Usa "Nuevo almacén" para crear el primero.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endif

{{-- Detalles del producto (se abre con el "ojo" de cada fila — agrupa todas las acciones del producto) --}}
<div id="almDetalleModal" class="alm-modal-overlay">
    <div class="alm-modal" style="max-width:420px;">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">inventory_2</i> Detalles del producto</h3>
            <i class="material-icons alm-x" onclick="almDetalleCerrar()">close</i>
        </div>
        <div class="alm-modal-body">

            {{-- Foto del producto. Es la misma que se ve como miniatura en la tabla. Se
                 cambia aquí mismo: la imagen se convierte a WebP y se sube a Drive
                 (AlmacenController::subirFotoProducto); en la ficha solo vive el enlace.
                 Con almacen.productos la caja entera abre el selector de archivo y lleva la cámara
                 (antes de subir se encuadra en el recorte, partials.recorte_foto);
                 sin el permiso, tocar la foto la abre en grande (almVerFoto). --}}
            @php $almEditaFoto = auth()->user()?->can('almacen.productos'); @endphp
            <div class="alm-det-foto-caja{{ $almEditaFoto ? ' editable' : '' }}" id="almDetFotoCaja"
                 @if($almEditaFoto) title="Subir foto" onclick="document.getElementById('almDetFotoInput').click()" @endif>
                <img id="almDetFotoImg" class="alm-det-foto" alt="Foto del producto" style="display:none;"
                     @unless($almEditaFoto) title="Ver la foto en grande" onclick="window.almVerFoto(this.src)" @endunless>
                <div id="almDetFotoSin" class="alm-det-foto alm-det-foto-sin"><i class="material-icons">inventory_2</i></div>
                @if($almEditaFoto)
                <div class="alm-det-foto-camara"><i class="material-icons">photo_camera</i></div>
                <input type="file" id="almDetFotoInput" accept="image/jpeg,image/png,image/webp" hidden
                       onclick="event.stopPropagation()" onchange="window.almDetFotoElegir(this)">
                @endif
            </div>

            {{-- Aviso de stock bajo en este almacén. En rojo, como las filas de stock bajo
                 de la tabla (.alm-row-bajo), para que el usuario asocie ambos avisos. En UNA
                 sola línea, centrada. --}}
            <div id="almDetBajoBadge" style="display:none;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;border-radius:999px;padding:6px 14px;align-items:center;justify-content:center;gap:6px;font-size:12.5px;line-height:1.2;text-align:center;">
                <i class="material-icons" style="font-size:16px;">warning_amber</i>
                <strong style="font-weight:800;">Stock bajo</strong>
                <span style="font-weight:500;color:#dc2626;">· saldo en el mínimo o por debajo</span>
            </div>
            {{-- Ubicación física del producto dentro de la bodega — estante, fila o nivel
                 (texto libre): se muestra como tooltip al pasar el mouse sobre la fila en la
                 tabla. No confundir con la UBICACION del ALMACÉN (#almNvUbicacion), que es
                 otro campo. Vive AQUÍ (no en "Editar producto") para poder consultarla y
                 actualizarla en un solo clic, sin entrar al modal completo de edición
                 — a pedido del cliente.

                 SIN botón "Guardar" (pedido del cliente): se guarda con Enter y al abandonar el
                 modal — sea cerrándolo (✕ / Escape, vía almDetalleCerrar) o saltando a un
                 sub-modal (vía almDetalleAccion). almGuardarUbicacionDetalle compara contra el
                 valor cargado, así que salir sin tocar el campo no dispara ningún PATCH. --}}
            <div style="padding-top:2px;text-align:center;">
                <label for="almDetUbicacion"><i class="material-icons" style="font-size:15px;vertical-align:-3px;margin-right:3px;color:#0067b1;">place</i>Ubicación en estante, fila o nivel</label>
                {{-- max-width: deja aire a los lados sin llegar al borde del modal. Se conserva
                     width:100% para que encoja solo en pantallas angostas. Lo centra el
                     text-align del div padre (el input es inline-block: margin:auto NO lo
                     centraría). --}}
                <input type="text" id="almDetUbicacion" maxlength="150" autocomplete="off"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();window.almGuardarUbicacionDetalle();}"
                       style="width:100%;max-width:320px;min-width:0;height:30px;padding-top:4px;padding-bottom:4px;margin-top:4px;text-align:center;">
                <div id="almDetUbicacionError" style="display:none;color:#dc2626;font-size:12px;font-weight:600;margin-top:4px;"></div>
            </div>

            {{-- Reparto del saldo por proyecto DENTRO de este almacén. Solo aparece en
                 almacenes que separan por proyecto (los que sirven a varios frentes): en el
                 resto todo el saldo es de la bolsa común y esta lista repetiría el total.
                 Llega en la MISMA respuesta que la compatibilidad (un solo fetch al abrir la
                 ficha, ver almCargarCompat). Responde "¿de quién es este material?": el saldo
                 tiene dueño aunque cualquier proyecto pueda consumirlo. --}}
            <div id="almDetProyectosWrap" style="display:none;border-top:1px solid #f1f5f9;padding-top:12px;margin-bottom:12px;">
                <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;display:flex;align-items:center;gap:6px;">
                    <i class="material-icons" style="font-size:16px;color:#0067b1;">account_tree</i> En este almacén, por proyecto
                </div>
                <div id="almDetProyectos" style="display:flex;flex-direction:column;gap:4px;max-height:180px;overflow-y:auto;"></div>
                <div class="alm-hint" style="margin-top:6px;">Al despachar puedes elegir de qué proyecto sale, tocándolo en este mismo desglose dentro de la tabla. Sin elegir, la salida toma primero el saldo del proyecto destino y lo que esté sin proyecto; si no alcanza, sigue con el de los demás y queda anotado en la bitácora.</div>
            </div>

            {{-- Compatibilidad: nº de parte (equivalencias) + equipos que lo usan. Se carga antes
                 de mostrar el detalle (almAbrirDetalle → almCargarCompat) y la pinta almDetCompatPintar.
                 Con almacen.productos cada sección lleva su + (agregar) y cada dato su ×
                 (quitar); sin ese permiso solo se ve lo que hay, y nada si no hay nada. --}}
            <div id="almDetCompat" hidden>
                {{-- Cada sección es un desplegable (arranca cerrado): la cabecera abre y cierra y
                     dice cuántos hay; el "+" queda a su lado y abre la sección con su formulario. --}}
                <div id="almDetPartesWrap" class="alm-det-sec" hidden>
                    <div class="alm-det-sec-cab">
                        <button type="button" class="alm-det-sec-tog" aria-expanded="false" aria-controls="almDetPartesCuerpo" onclick="window.almDetSeccion('almDetPartesWrap')">
                            <span class="alm-det-sec-tit">Nº de parte / equivalencias <span id="almDetPartesCount"></span></span>
                            <i class="material-icons alm-det-sec-flecha">expand_more</i>
                        </button>
                        <button type="button" class="alm-det-mas alm-det-solo-edita" onclick="window.almDetParteAbrir()"><i class="material-icons">add</i>Agregar</button>
                    </div>
                    <div id="almDetPartesCuerpo" class="alm-det-sec-cuerpo" hidden>
                        <div id="almDetPartes" class="alm-det-chips"></div>
                        <form id="almDetParteForm" class="alm-det-form" hidden onsubmit="event.preventDefault(); window.almDetParteGuardar();">
                            <input type="text" id="almDetParteInput" maxlength="100" autocomplete="off" placeholder="Número de parte" aria-label="Número de parte"
                                   onkeydown="if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); window.almDetFormCerrar(); }">
                            <button type="submit" class="btn-primary-maquinaria">Agregar</button>
                        </form>
                    </div>
                </div>
                <div id="almDetEquiposWrap" class="alm-det-sec" hidden>
                    <div class="alm-det-sec-cab">
                        <button type="button" class="alm-det-sec-tog" aria-expanded="false" aria-controls="almDetEquiposCuerpo" onclick="window.almDetSeccion('almDetEquiposWrap')">
                            <span class="alm-det-sec-tit"><i class="material-icons">precision_manufacturing</i> Equipos que lo usan <span id="almDetEquiposCount"></span></span>
                            <i class="material-icons alm-det-sec-flecha">expand_more</i>
                        </button>
                        <button type="button" class="alm-det-mas alm-det-solo-edita" onclick="window.almDetEquipoAbrir()"><i class="material-icons">add</i>Vincular</button>
                    </div>
                    <div id="almDetEquiposCuerpo" class="alm-det-sec-cuerpo" hidden>
                        <div id="almDetEquipos" class="alm-det-lista"></div>
                        <div id="almDetEquipoForm" class="alm-det-form" hidden>
                            <input type="text" id="almDetEquipoInput" autocomplete="off" placeholder="Buscar tipo, marca, modelo o placa…" aria-label="Buscar equipo"
                                   oninput="window.almDetEquipoBuscar()"
                                   onkeydown="if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); window.almDetFormCerrar(); }">
                            <div id="almDetEquipoSug" class="alm-det-sug"></div>
                        </div>
                    </div>
                </div>
                <div id="almDetCompatMsg" class="alm-det-msg" hidden></div>
            </div>

            <div style="border-top:1px solid #f1f5f9;padding-top:12px;display:flex;flex-direction:column;gap:7px;">
                {{-- Botones SIEMPRE visibles. La verificacion de permiso vive dentro de
                     almDetalleAccion / almAbrirAjuste / almEditarProducto / almEliminarProducto
                     — si el usuario no tiene la clave necesaria, salta toast moderno con la
                     razon. Antes se ocultaban; el cliente pidio "ver botones + notificacion". --}}
                <button type="button" class="alm-det-act" onclick="window.almDetalleAccion('ajuste')"><span class="alm-det-ic" style="background:#dbeafe;color:#0067b1;"><i class="material-icons" style="font-size:18px;">fact_check</i></span> Auditoría de Inventario</button>
                <button type="button" class="alm-det-act" onclick="window.almDetalleAccion('minimo')"><span class="alm-det-ic" style="background:#fef3c7;color:#d97706;"><i class="material-icons" style="font-size:18px;">production_quantity_limits</i></span> Stock mínimo (alerta)</button>
                <button type="button" class="alm-det-act" onclick="window.almDetalleAccion('editar')"><span class="alm-det-ic" style="background:#cffafe;color:#0891b2;"><i class="material-icons" style="font-size:18px;">edit</i></span> Editar producto</button>
                {{-- En mobile (≤768px) el modal "Movimientos del producto" es
                     un kardex tabular pesado; el cliente prefirio ocultarlo en
                     telefono para mantener el modal de detalles compacto. La
                     clase .alm-det-act-kardex permite el override CSS. --}}
                <button type="button" class="alm-det-act alm-det-act-kardex" onclick="window.almDetalleAccion('kardex')"><span class="alm-det-ic" style="background:#f1f5f9;color:#475569;"><i class="material-icons" style="font-size:18px;">history</i></span> Ver movimientos del producto</button>
                <button type="button" class="alm-det-act" onclick="window.almDetalleAccion('eliminar')"><span class="alm-det-ic" style="background:#fee2e2;color:#ef4444;"><i class="material-icons" style="font-size:18px;">delete_outline</i></span> Eliminar</button>
            </div>
        </div>

</div>
</div>

{{-- Visor de la foto del producto (ver .alm-visor-foto y almVerFoto). --}}
<div id="almVisorFoto" class="alm-visor-foto" onclick="window.almCerrarFoto()">
    <i class="material-icons alm-visor-x" title="Cerrar">close</i>
    <img id="almVisorFotoImg" alt="Foto del producto" onclick="event.stopPropagation()">
</div>
@can('almacen.productos')
@include('partials.recorte_foto')
@endcan

@if($puedeMover)
{{-- ── Salida: un solo formulario unificado. Siempre llena la Nota de Entrega
     (proyecto + contrato + fecha + RQ + solicitante + dpto; el formato HORIZONTAL del almacén
     de origen oculta contrato y RQ — ver almSalidaAplicarFormatoNota). El backend decide si la salida
     es CONSUMO (mismo almacén del origen) o TRASPASO (envío a otro almacén) según el frente
     elegido en "Proyecto destino" — ambos casos generan Nota de Entrega NE-YYYY-NNNN. ── --}}
<div id="almSalidaModal" class="alm-modal-overlay">
    {{-- max-width 660px — el cliente pidió agrandarlo un poco (venía de 600).
         El layout de 2-3 columnas (Proyecto/Contrato, Fecha/RQ/Solic) sigue
         acomodando bien — los inputs heredan width:100% del .alm-nota-input. --}}
    <div class="alm-modal alm-modal-wide" style="max-width:660px;">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">shopping_cart</i> <span>Registrar salida</span></h3>
            <i class="material-icons alm-x" onclick="almCerrar('almSalidaModal')">close</i>
        </div>
        <div class="alm-modal-body">

            {{-- Cabecera tipo "Nota de Entrega de Materiales" ─────────────────────────────
                 Layout inspirado en el Excel VID-FO-GEN-019, optimizado para ocupar menos
                 alto vertical:
                   PROYECTO (2fr) | CONTRATO N° (1fr)                (misma fila)
                   FECHA DE ENTREGA | RQ N° | Solicitante            (3 columnas)
                   DEPARTAMENTO                                      (full)
                   OBSERVACIONES                                     (full)
                 Entre la 1ª y la 2ª fila se intercala ALMACÉN DESTINO, que solo aparece cuando
                 hace falta (ver su bloque): no es parte de la hoja del Excel, sino de la
                 decisión de a dónde va el material.
                 CONTRATO N° y RQ N° se ocultan cuando el almacén de origen emite la nota en
                 formato HORIZONTAL (esa hoja no los imprime): las dos filas quedan en
                 PROYECTO (full) y FECHA | Solicitante. Lo hace almSalidaAplicarFormatoNota()
                 al abrir el modal, que es el único sitio que conoce esa diferencia. --}}
            <div id="almSalidaNotaWrap" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:14px;">
                {{-- NOTA: el título "Nota de Entrega de Materiales" se incluye SOLO en el PDF
                     generado por NotaEntregaPDF. En el modal es ruido visual — el título
                     del modal ("Registrar salida") ya identifica suficientemente el formulario.
                     La metadata del formulario (CÓDIGO, FECHA EMIS, REV) ya no sale en ningún
                     lado: describía la plantilla, no la entrega. Ver NotaEntregaPDF::Header. --}}

                {{-- PROYECTO (ancho) | CONTRATO N° (estrecho) — misma fila.
                     Contrato N° es input + caret (igual patron que "Categoria" del modal de
                     producto): al elegir proyecto destino, la lista del dropdown se rellena
                     con los contratos registrados de ese frente (NO se abre sola, ver
                     almSalidaOnProyectoChange) — el usuario elige uno, escribe
                     uno nuevo, o deja en blanco. --}}
                <div id="almSalidaGridProyecto" class="alm-modal-grid alm-modal-grid-2" style="display:grid;grid-template-columns:2fr 1fr;gap:10px;margin-bottom:10px;align-items:start;">
                    <div>
                        {{-- El for= apunta al input VISIBLE (data-filter-search) en vez de al
                             hidden #almSalidaProyecto: Chrome marca como inválido un <label for=>
                             que rotula un <input type="hidden"> (no es focuseable ni autofillable),
                             y rompe la asociación a11y. El hidden mantiene su id porque el JS lo
                             lee con el('almSalidaProyecto').value para enviar el ID del frente. --}}
                        <label class="alm-nota-label" for="almSalidaProyectoSearch">Proyecto *</label>
                        {{-- Custom-dropdown estándar de la app: hidden #almSalidaProyecto guarda el ID
                             (lo que lee el JS de envío); el trigger tiene un input data-filter-search
                             que filtra los items mientras el usuario escribe (autocomplete nativo del
                             componente). Cuando se elige una opción, dispatchea el evento
                             `dropdown-selection` que el listener de almSalida usa para refrescar las
                             sugerencias de Contrato N°. --}}
                        <div class="custom-dropdown" id="almSalidaProyectoDropdown" data-default-label="Selecciona uno">
                            <input type="hidden" id="almSalidaProyecto" data-filter-value value="">
                            <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:#fff;overflow:hidden;border:1px solid #cbd5e0;border-radius:7px;height:38px;">
                                <input type="text" id="almSalidaProyectoSearch" data-filter-search autocomplete="off"
                                       placeholder="Selecciona uno"
                                       style="flex:1;border:none;background:transparent;padding:0 10px;font-size:13.5px;color:#0f172a;outline:none;min-width:0;"
                                       oninput="window.filterDropdownOptions(this)">
                                {{-- Este trigger NO lleva caret: la ✕ es su único icono. La lista se abre
                                     igual, porque el handler global de uicomponents.js delega el clic en
                                     .dropdown-trigger, no en el icono. --}}
                                <i class="material-icons" data-clear-btn style="padding:0 12px;color:#64748b;font-size:18px;display:none;cursor:pointer;"
                                   onclick="event.stopPropagation(); clearDropdownFilter('almSalidaProyectoDropdown');">close</i>
                            </div>
                            <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                                <div class="dropdown-item-list" style="max-height:240px;overflow-y:auto;">
                                    @foreach(($frentesLista ?? collect()) as $f)
                                        <div class="dropdown-item" data-value="{{ $f->ID_FRENTE }}"
                                             onclick="selectOption('almSalidaProyectoDropdown','{{ $f->ID_FRENTE }}','{{ addslashes($f->NOMBRE_FRENTE) }}');">
                                            {{ $f->NOMBRE_FRENTE }}
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="almSalidaContratoWrap">
                        <label class="alm-nota-label" for="almSalidaContrato">Contrato N°</label>
                        {{-- Custom-dropdown (mismo componente que Proyecto): el panel flota
                             absolutamente (NO empuja el modal hacia abajo) y se abre con clic en
                             el trigger / foco en el input. El input SI es libre — el usuario
                             puede escribir un contrato que no este en la lista, dejarlo en blanco
                             (es opcional), o elegir uno de los contratos registrados del proyecto.
                             La lista de items se rellena al elegir proyecto destino. --}}
                        <div class="custom-dropdown" id="almSalidaContratoDropdown">
                            <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:#fff;overflow:hidden;border:1px solid #cbd5e0;border-radius:7px;height:38px;">
                                <input type="text" id="almSalidaContrato" autocomplete="off" maxlength="100"
                                       placeholder="Selecciona"
                                       style="flex:1;border:none;background:transparent;padding:0 10px;font-size:13.5px;color:#0f172a;outline:none;min-width:0;"
                                       oninput="window.almSalidaContratoFilter(this)">
                                <i class="material-icons" id="almSalidaContratoClearBtn" style="padding:0 8px;color:#64748b;font-size:18px;display:none;cursor:pointer;"
                                   onclick="event.stopPropagation(); window.almSalidaContratoClear();">close</i>
                                <i class="material-icons" style="padding:0 8px;color:#94a3b8;font-size:20px;">expand_more</i>
                            </div>
                            <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                                <div class="dropdown-item-list" id="almSalidaContratoItems" style="max-height:240px;overflow-y:auto;"></div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ALMACÉN DESTINO — solo aparece cuando el proyecto elegido está asignado a
                     MÁS DE UN almacén, que es cuando el destino no se puede deducir. Con uno
                     solo queda oculto y lo deduce el backend: preguntar lo obvio sería un clic
                     de más en casi todas las salidas.
                     Lo llena almSalidaSyncAlmacenDestino() al elegir proyecto. --}}
                <div id="almSalidaDestinoWrap" style="display:none;margin-bottom:10px;">
                    <label class="alm-nota-label" for="almSalidaAlmacenDestinoSearch">Almacén destino *</label>
                    <div class="custom-dropdown" id="almSalidaAlmacenDestinoDropdown" data-default-label="Selecciona uno">
                        <input type="hidden" id="almSalidaAlmacenDestino" data-filter-value value="">
                        <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:#fff;overflow:hidden;border:1px solid #cbd5e0;border-radius:7px;height:38px;">
                            <input type="text" id="almSalidaAlmacenDestinoSearch" data-filter-search autocomplete="off"
                                   placeholder="Selecciona uno"
                                   style="flex:1;border:none;background:transparent;padding:0 10px;font-size:13.5px;color:#0f172a;outline:none;min-width:0;"
                                   oninput="window.filterDropdownOptions(this)">
                            <i class="material-icons" style="padding:0 8px;color:#94a3b8;font-size:20px;">expand_more</i>
                        </div>
                        <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                            <div class="dropdown-item-list" id="almSalidaAlmacenDestinoItems" style="max-height:240px;overflow-y:auto;"></div>
                        </div>
                    </div>
                    <div class="alm-hint">
                        Este proyecto se maneja en varios almacenes: indica a cuál se envía el material.
                    </div>
                </div>

                {{-- FECHA DE ENTREGA | RQ N° | Solicitante (3 columnas en una sola fila — como en el Excel) --}}
                <div id="almSalidaGridDatos" class="alm-modal-grid alm-modal-grid-3" style="display:grid;grid-template-columns:1fr 1fr 1.4fr;gap:10px;margin-bottom:10px;">
                    <div>
                        <label class="alm-nota-label" for="almSalidaFecha">Fecha de entrega</label>
                        {{-- Wrapper clickable: cualquier click en el campo abre el calendario
                             (en navegadores que soportan showPicker). YA NO incluye un icono
                             custom (event) porque el <input type="date"> nativo de Chrome/Edge
                             pinta su propio indicador de calendario a la derecha — antes se veian
                             DOS calendarios (custom izq + nativo der). Dejamos solo el nativo. --}}
                        <div id="almSalidaFechaBox" style="display:flex;align-items:center;background:#fff;border:1px solid #cbd5e0;border-radius:7px;height:38px;overflow:hidden;cursor:pointer;"
                             onclick="var i=document.getElementById('almSalidaFecha'); if(i){ i.focus(); if(i.showPicker){ try{ i.showPicker(); }catch(e){} } }">
                            <input type="date" id="almSalidaFecha" class="alm-nota-input" style="flex:1;width:auto;min-width:0;border:none;background:transparent;height:36px;padding:0 10px;border-radius:0;">
                        </div>
                    </div>
                    <div id="almSalidaRqWrap">
                        <label class="alm-nota-label" for="almSalidaRq">RQ N°</label>
                        <input type="text" id="almSalidaRq" class="alm-nota-input" maxlength="100" placeholder="Ej: RQ-001" autocomplete="off">
                    </div>
                    <div>
                        <label class="alm-nota-label" for="almSalidaSolicitante">Solicitante</label>
                        <input type="text" id="almSalidaSolicitante" class="alm-nota-input" maxlength="200" placeholder="Nombre y apellido" autocomplete="off">
                    </div>
                </div>

                {{-- DEPARTAMENTO (full width) --}}
                <div style="margin-bottom:10px;">
                    <label class="alm-nota-label" for="almSalidaDepartamento">Departamento</label>
                    {{-- list: sugiere los departamentos que ESTE usuario ya usó (ver
                         almDeptoRecordar). autocomplete="off" sigue puesto para que el
                         navegador no meta además su propio historial. --}}
                    <input type="text" id="almSalidaDepartamento" class="alm-nota-input" maxlength="150"
                           placeholder="Ej: Mantenimiento" autocomplete="off" list="almSalidaDeptoLista">
                    <datalist id="almSalidaDeptoLista"></datalist>
                </div>

                {{-- TRANSPORTE — lo imprime el bloque "Datos del vehículo / Datos del chofer" de la
                     nota, que antes salía en blanco. Opcional: lo que quede vacío sale en blanco
                     para llenarlo a mano. Sugiere la logística del almacén y la flota de sus frentes
                     (almLogCargar): el vehículo se busca ESCRIBIENDO su placa o su serial de chasis
                     y elegir uno llena los dos campos de su fila. Los dos comparten la lista, que
                     sale debajo del campo que se está escribiendo (almLogSugerir). --}}
                <div class="alm-modal-grid alm-modal-grid-2" style="display:grid;grid-template-columns:1.6fr 1fr;gap:10px;margin-bottom:10px;">
                    <div>
                        <label class="alm-nota-label" for="almSalidaVehiculo">Vehículo</label>
                        <input type="text" id="almSalidaVehiculo" class="alm-nota-input" maxlength="150" placeholder="Ej: Camioneta Toyota Hilux" autocomplete="off"
                               data-log="vehiculos" oninput="window.almLogSugerir(this)" onfocus="window.almLogSugerir(this, true)">
                    </div>
                    <div>
                        <label class="alm-nota-label" for="almSalidaPlaca">Placa</label>
                        <input type="text" id="almSalidaPlaca" class="alm-nota-input" maxlength="30" placeholder="Placa o serial de chasis" autocomplete="off"
                               data-log="vehiculos" oninput="window.almLogSugerir(this)" onfocus="window.almLogSugerir(this, true)">
                    </div>
                    <div class="alm-suggest-inline alm-suggest-float" id="almSalidaVehiculosSug"></div>
                </div>
                <div class="alm-modal-grid alm-modal-grid-2" style="display:grid;grid-template-columns:1.6fr 1fr;gap:10px;margin-bottom:10px;">
                    <div>
                        <label class="alm-nota-label" for="almSalidaChofer">Chofer</label>
                        <input type="text" id="almSalidaChofer" class="alm-nota-input" maxlength="150" placeholder="Nombre y apellido" autocomplete="off"
                               data-log="choferes" oninput="window.almLogSugerir(this)" onfocus="window.almLogSugerir(this, true)">
                    </div>
                    <div>
                        <label class="alm-nota-label" for="almSalidaCedula">Cédula del chofer</label>
                        <input type="text" id="almSalidaCedula" class="alm-nota-input" maxlength="30" placeholder="Ej: 17.902.185" autocomplete="off"
                               data-log="choferes" oninput="window.almLogSugerir(this)" onfocus="window.almLogSugerir(this, true)">
                    </div>
                    <div class="alm-suggest-inline alm-suggest-float" id="almSalidaChoferesSug"></div>
                </div>

                {{-- OBSERVACIONES (full width) — campo libre de la Nota de Entrega
                     (mapea a MOTIVO en BD). Se envía siempre en el flujo unificado: tanto
                     en SALIDA pura (consumo) como en SALIDA vía traspaso a otro proyecto. --}}
                <div>
                    <label class="alm-nota-label" for="almSalidaMotivo">Observaciones</label>
                    <input type="text" id="almSalidaMotivo" class="alm-nota-input" maxlength="200" placeholder="Ej: entrega parcial, urgente, etc." autocomplete="off">
                </div>
            </div>

            {{-- La lista de productos a entregar VIVE en la tabla principal: cada fila
                 seleccionada tiene su propio input "Salida". Este modal solo
                 recoge los datos de la Nota de Entrega y los cruza con almSeleccion. --}}

            <div id="almSalidaError" style="display:none;color:#dc2626;font-size:13px;font-weight:600;margin-top:6px;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almSalidaModal')">Cancelar</button>
            {{-- "Previsualizar" en vez de "Registrar salida": el flujo de salida ahora pasa
                 por el modal #almPreviewModal donde el usuario revisa el PDF y aprieta
                 "Registrar" para que sea oficial. Sin icono — pedido del cliente para
                 que el boton se vea mas formal. --}}
            <button type="button" class="btn-primary-maquinaria" onclick="window.almSalidaVistaPrevia()">Previsualizar</button>
        </div>
    </div>
</div>

{{-- ── Modal "Vista previa de la Nota de Entrega" ────────────────────────────────
     Aparece después de "Vista previa" del modal de salida. Carga el PDF preview
     en un iframe y ofrece dos acciones:
       · Editar    → vuelve al modal de salida con todos los datos preservados
                     (almCerrar solo oculta el modal, no destruye los inputs).
       · Registrar → POST a /almacen/movimientos-lote (endpoint real) → guarda
                     en BD, descarga el PDF final al disco y devuelve al usuario
                     al modulo de inventario (NO abre ningun visor in-page).
     ── --}}
<div id="almPreviewModal" class="alm-modal-overlay">
    <div class="alm-modal alm-modal-wide" style="max-width:1180px;max-height:98vh;">
        <div class="alm-modal-head" style="padding:8px 40px;">
            <h3><i class="material-icons" style="font-size:20px;">visibility</i> <span>Vista previa de la Nota de Entrega</span></h3>
            <i class="material-icons alm-x" onclick="window.almPreviewCerrar()">close</i>
        </div>
        <div class="alm-modal-body" style="padding:0;gap:0;background:#475569;">
            {{-- Aviso "esto sale del saldo de otro proyecto". Lo manda previewSalidaPdf en la
                 cabecera X-Salida-Aviso (el cuerpo de esa respuesta es el PDF). Va AQUÍ, en el
                 paso donde se revisa antes de registrar: la salida se permite —el material
                 está en la bodega y prestarlo entre frentes es un ajuste normal— pero quien
                 firma tiene que enterarse. Se llena/vacía en cada vista previa. --}}
            {{-- Texto oscuro con el ícono en ámbar: con letra ámbar sobre fondo ámbar se leía
                 como amarillo sobre amarillo. Lo pinta almSalidaVistaPrevia. --}}
            <div id="almPreviewAviso" style="display:none;align-items:flex-start;gap:8px;background:#fffbeb;color:#0f172a;border-bottom:1px solid #fde68a;padding:9px 16px;font-size:12.5px;font-weight:500;line-height:1.45;"></div>
            {{-- ESCRITORIO: el visor de PDF nativo del navegador en un iframe (zoom/imprimir). --}}
            <iframe id="almPreviewFrame" src="about:blank" style="width:100%;height:82vh;min-height:560px;border:none;background:#fff;" title="Vista previa Nota de Entrega"></iframe>
            {{-- TELÉFONO: los navegadores móviles no renderizan PDF en iframe, así que el PDF
                 se dibuja aquí con PDF.js (una <canvas> por página, desplazable). --}}
            <div id="almPreviewCanvas" style="display:none;width:100%;height:82vh;min-height:560px;overflow:auto;background:#475569;padding:10px;box-sizing:border-box;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="window.almPreviewEditar()"><i class="material-icons" style="font-size:17px;vertical-align:-3px;margin-right:4px;">edit</i>Editar</button>
            <button type="button" class="btn-primary-maquinaria" onclick="window.almPreviewConfirmar()"><i class="material-icons" style="font-size:17px;vertical-align:-3px;margin-right:4px;">check_circle</i>Registrar</button>
        </div>
    </div>
</div>
@endif

<script>
    // Lo unico que sigue viajando en el HTML del modulo: los datos de ESTA apertura. El
    // codigo esta en public/js/maquinaria/almacen_index.js, que el navegador cachea.
@php
    // Los datos de ESTA apertura que necesita el JavaScript del modulo: rutas con nombre,
    // permisos del usuario y catalogos. Se arma aqui, en un bloque @php, y no dentro del
    // propio @json(): el array es largo y Blade no lo lee bien de una sola expresion.
    $almCfg = [
        'rutas' => [
            'index'                 => route('almacen.index'),
            'lote'                  => route('almacen.movimientos.lote'),
            'salidaPreview'         => route('almacen.salida.preview'),
            'productosStore'        => route('almacen.productos.store'),
            'productosAutocomplete' => route('almacen.productos-autocomplete'),
            // Las que llevan __PID__ las completa el JavaScript con el id del producto.
            'productosOtros'        => route('almacen.productos.otros', ['id' => '__PID__']),
            'productosBase'         => url('admin/almacen/productos'),
            'compatibilidad'        => route('almacen.productos.compatibilidad', ['id' => '__PID__']),
            'equivalenciasStore'    => route('almacen.productos.equivalencias.store', ['id' => '__PID__']),
            'equiposStore'          => route('almacen.productos.equipos.store', ['id' => '__PID__']),
            'equiposOpciones'       => route('almacen.productos.equipos.opciones', ['id' => '__PID__']),
            'export'                => route('almacen.export'),
            'etiquetas'             => route('almacen.etiquetas'),
            'movimientos'           => route('almacen.movimientos'),
            'almacenesStore'        => route('almacen.almacenes.store'),
        ],
        // Permisos: cada funcion del modulo los consulta antes de actuar y, si faltan,
        // avisa con un toast en lugar de esconder el boton.
        'puedeAlmManage'        => $puedeAlmManage,
        'puedeProductos'        => $puedeProductos,
        'puedeMover'            => $puedeMover,
        'puedeEliminar'         => $puedeEliminar,
        'categorias'            => ($categorias ?? collect())->filter()->values(),
        'unidadesMedida'        => $unidadesMedida ?? [],
        'frenteContratos'       => ($frentesLista ?? collect())->mapWithKeys(fn ($f) => [
            $f->ID_FRENTE => array_values(array_filter((array) ($f->CONTRATOS ?? []))),
        ]),
        'almacenesData'         => $almacenesData,
        'almacenesPorFrente'    => $almacenesPorFrente ?? new stdClass(),
        'formatoNotaDef'        => $formatoNotaDef,
        'formatoNotaHorizontal' => \App\Models\Almacen::FORMATO_NOTA_HORIZONTAL,
        'idUsuario'             => auth()->user()?->ID_USUARIO ?? 0,
    ];
@endphp
    window.ALM_CFG = @json($almCfg);
</script>
<script src="{{ asset('js/maquinaria/almacen_index.js') }}?v={{ @filemtime(public_path('js/maquinaria/almacen_index.js')) }}"></script>
<script>
    // El archivo de arriba se ejecuta UNA sola vez en toda la sesion: la SPA no re-ejecuta
    // un <script src> que ya esta cargado. Al REABRIR el modulo hay que poner su estado al
    // dia con el DOM nuevo (seleccion, "ver todo", filtros), que es justo lo que hacia el
    // guard del IIFE cuando el codigo venia dentro del HTML.
    if (!window.__almIndexArrancoAhora && window.almResetOnRemount) window.almResetOnRemount();
    delete window.__almIndexArrancoAhora;
</script>

{{-- ── Teclado móvil vs barra flotante de selección ──────────────────────────
     En algunos teléfonos, al escribir la cantidad de salida en una fila, el
     teclado numérico TAPA la barra flotante (#almBulkBar: Limpiar / Salida /
     Etiquetas). En otros (como el del cliente) el navegador empuja el layout y
     se ve bien. Para que sea CONSISTENTE en todos, usamos la visualViewport API:
     cuando el teclado abre (la altura visible se achica), elevamos la barra
     justo por encima del teclado. Sin teclado, vuelve a su posición del CSS.
     Solo afecta a esta barra fija; no toca el resto del layout. --}}
<script>
(function () {
    // Guard idéntico al del IIFE principal (window.__almIndexInit): estos listeners viven
    // en document / visualViewport, así que en un re-montaje SPA los <script> se re-ejecutan
    // y se DUPLICARÍAN. El más grave es el keydown de abajo: dispararía window.almGuardarProducto()
    // dos veces (tres tras la 2ª revisita…) → producto CREADO por duplicado (el AJUSTE es
    // idempotente, pero CREATE no). Con el guard se bindean UNA sola vez, aunque se re-monte.
    if (window.__almDocListenersInit) return;
    window.__almDocListenersInit = true;

    // ── Barra de bulk-select por encima del teclado móvil (visualViewport) ──
    (function () {
        var vv = window.visualViewport;
        if (!vv) return; // navegador viejo sin visualViewport → comportamiento previo
        function ajustarBarra() {
            // La barra se busca EN CADA AJUSTE, no una sola vez: este IIFE está dentro del
            // guard window.__almDocListenersInit, así que en una re-entrada por SPA no se
            // vuelve a ejecutar, pero el #almBulkBar del DOM nuevo es OTRO nodo. Guardando
            // la referencia, el listener seguía escribiendo sobre el nodo viejo (ya
            // desmontado) y el teclado volvía a tapar Limpiar/Salida/Etiquetas al llegar
            // por el menú — funcionaba solo al entrar por URL o tras recargar. Mismo
            // problema que se resolvió para el Consolidado con almColocarSidebarMovil.
            var bar = document.getElementById('almBulkBar');
            if (!bar) return;
            // Píxeles del layout tapados por el teclado (0 si está cerrado).
            var tapado = Math.max(0, window.innerHeight - (vv.height + vv.offsetTop));
            // +12px de respiro sobre el teclado. Sin teclado, '' → vuelve al CSS.
            bar.style.bottom = tapado > 0 ? (tapado + 12) + 'px' : '';
        }
        vv.addEventListener('resize', ajustarBarra);
        vv.addEventListener('scroll', ajustarBarra);
        ajustarBarra();
    })();

    // El tamaño del teclado numérico lo decide el SO/teclado del teléfono (no se puede
    // achicar por web sin perder el punto decimal que la cantidad necesita). Lo que SÍ
    // hacemos: al tocar el campo de cantidad, subir esa fila a la zona visible por encima
    // del teclado, así SIEMPRE se ve el producto + lo que se escribe, sea grande o chico
    // el teclado. Delegado en document para que aplique a las filas cargadas por AJAX.
    document.addEventListener('focusin', function (e) {
        var inp = e.target;
        if (!inp || !inp.classList || !inp.classList.contains('alm-row-cant')) return;
        // Solo en móvil: en PC no hay teclado que tape nada y centrar provocaría un
        // salto de scroll innecesario al enfocar el campo (block:'center' siempre centra).
        if (window.innerWidth > 768) return;
        // Esperamos ~300ms a que el teclado abra y el viewport se reajuste, y centramos.
        setTimeout(function () {
            try { inp.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
            catch (_) { try { inp.scrollIntoView(); } catch (e2) {} }
        }, 300);
    });

    // Enter dentro de un modal abierto = confirmar ese modal (sin submit nativo).
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.defaultPrevented) return;
        var tag = e.target.tagName;
        if (tag === 'TEXTAREA' || tag === 'SELECT') return;
        var modal = e.target.closest('.alm-modal-overlay');
        if (!modal || !modal.classList.contains('open')) return;
        var id = modal.id;
        if (id === 'almAjusteModal' && typeof window.almGuardarAjuste === 'function') {
            e.preventDefault(); window.almGuardarAjuste();
        } else if (id === 'almMinimoModal' && typeof window.almGuardarMinimo === 'function') {
            e.preventDefault(); window.almGuardarMinimo();
        } else if (id === 'almProductoModal' && typeof window.almGuardarProducto === 'function') {
            e.preventDefault(); window.almGuardarProducto();
        }
    });
})();
</script>

{{-- Modal "Dashboard de Consumo" (menú Acciones). Vista parcial compartida con
     /admin/almacen/movimientos — mismo modal y mismo endpoint. --}}
@include('admin.almacen.partials.consumo_dashboard_modal')
@include('admin.almacen.partials.kits_modal')
@endsection
