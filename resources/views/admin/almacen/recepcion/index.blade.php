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
    {{-- Fila 1: Título + selector de almacén --}}
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
                 NO hay opción "Todos los almacenes" ni X para quitarla (pedido del cliente):
                 la bandeja es de UN almacén — mezclarlos no dice nada útil, y el controller
                 siempre preselecciona uno (el del frente del usuario, o el primero visible). --}}
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
    /* Los dos buscadores llevan position:relative para anclar su panel de sugerencias
       (absolute). UNA sola declaración por selector: antes .tr-search-num se declaraba dos
       veces y la segunda —agrupada con .tr-search-prod— pisaba el ancho recortado de la
       primera, así que el recorte nunca llegó a aplicarse.
       Reparto del toolbar (el Estado ya no vive aquí: se mudó al panel "Filtros avanzados",
       así que el ancho que ocupaba se reparte entre los dos buscadores). Los DOS crecen y se
       llenan la fila —no uno elástico y otro fijo, que dejaba hueco muerto a la derecha— en
       proporción 2:1, porque producto/descripción muestra texto largo y la nota de entrega
       solo aloja un código corto (NE-2026-0249):
         · producto/descripción → PRIMERO (pedido del cliente) y con el doble de peso.
         · nota de entrega      → después, con la mitad.
       Bases y mínimos RECORTADOS respecto del reparto original (300/240 y 200/190): el
       toolbar ahora también aloja el botón "Compra directa", que necesita su espacio
       propio. La proporción 2:1 se conserva — solo baja el ancho de partida. */
    #trFilters .tr-search-prod { position:relative; flex:2 1 210px; min-width:170px; }
    #trFilters .tr-search-num  { position:relative; flex:1 1 150px; min-width:140px; }
    /* Toolbar alineado al estándar de /admin/almacen/movimientos: cajas de 45px,
       radio 12px, fondo suave #fbfcfd y letra 14px (antes 40px/8px/13px se veía
       más apretado que el resto de los módulos). Azul #e1effa cuando hay filtro. */
    .tr-search-box { display:flex; align-items:center; height:45px; border:1px solid #cbd5e0; border-radius:12px; background:#fbfcfd; overflow:hidden; }
    .tr-search-box.active { border-color:#0067b1; background:#e1effa; }
    .tr-search-box i.lupa { padding:0 10px; color:#64748b; font-size:18px; }
    /* font-family:inherit — los <input> NO heredan la fuente: se quedaban en el Arial del
       navegador mientras el resto de la app (y la tabla del inventario) usa Nunito. Por eso
       los buscadores se veían con otra letra que el listado. Aplica a nota y a producto. */
    .tr-search-box input { flex:1; border:none; background:transparent; outline:none; padding:10px 5px; font-size:14px; min-width:0; color:#0f172a; font-family:inherit; }
    /* Filtro "Estado" en MAYÚSCULAS (pedido del cliente). Se hace por CSS y NO tocando
       Traspaso::ESTADOS_META: esa constante también rotula las pills de la tabla, que
       siguen en su capitalización normal ("En tránsito"). El placeholder hereda el
       text-transform, así que el estado elegido también se ve en mayúsculas.
       12px (no 14px): el filtro vive dentro del panel "Filtros avanzados" (300px) y en
       mayúsculas "CONFIRMADA PARCIAL" no entra a 14px. Misma escala que Desde/Hasta. */
    #trEstadoDropdown .dropdown-item,
    #trEstadoDropdown .dropdown-trigger input { text-transform:uppercase; letter-spacing:.3px; font-size:12px; }
    #trEstadoDropdown .dropdown-trigger input::placeholder { text-transform:uppercase; }
    /* Los tres campos del panel (Estado / Desde / Hasta) comparten caja para verse como una
       sola familia: UNA declaración de la métrica, no una copia por control. */
    #trEstadoDropdown .dropdown-trigger,
    .tr-date-box { height:40px; border-radius:8px; }
    /* Cajas de fecha (Desde/Hasta), dentro del panel "Filtros avanzados". */
    .tr-date-box { display:flex; align-items:center; gap:5px; border:1px solid #cbd5e0; padding:0 10px; cursor:default; box-sizing:border-box; }
    .tr-date-box i { font-size:16px; color:#94a3b8; pointer-events:none; }
    .tr-date-box input[type=date] { flex:1; min-width:0; border:none; background:transparent; padding:0; font-size:12px; outline:none; color:#0f172a; cursor:default; }

    /* Panel "Filtros avanzados": UNA grilla de 2 columnas para los tres campos — el Estado
       ocupa las dos (.tr-adv-full) y Desde/Hasta una cada uno, así el mismo `gap` separa las
       dos filas. El min-width:0 en las celdas deja que los inputs de fecha encojan y NO se
       desborden del panel. */
    .tr-adv-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px 8px; }
    .tr-adv-grid > div { min-width:0; }
    .tr-adv-grid > .tr-adv-full { grid-column:1 / -1; }
    .tr-adv-grid .tr-date-box { padding:0 6px; gap:4px; }
    .tr-adv-grid .tr-date-box input[type=date] { font-size:11px; }
    /* Rótulo de cada campo del panel (Estado / Desde / Hasta): un solo estilo compartido
       en vez de repetir el mismo `style=""` inline en los tres. */
    .tr-adv-label { display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:5px; }
    /* Botón que abre el panel. Rojo = hay algún filtro activo DENTRO del panel; el render lo
       marca con .activo y trUpdateChips lo alterna al filtrar por AJAX (antes los 6 hex vivían
       repetidos en el style inline y otra vez en el JS). */
    .tr-adv-btn { height:45px; width:45px; min-width:45px; padding:0; display:flex; align-items:center; justify-content:center;
                  border-radius:12px; background:#fbfcfd; border:1px solid #cbd5e0; color:#64748b; box-shadow:none; }
    .tr-adv-btn.activo { background:#fee2e2; border-color:#ef4444; color:#ef4444; }
    /* Botón "Compra directa": misma altura/radio que el resto del toolbar (45px/12px)
       pero SÓLIDO azul de marca — es la única ACCIÓN de la barra (los demás controles
       filtran), así que se distingue de ellos por relleno, no por tamaño. flex:0 0 auto
       para que no lo estire el reparto de los buscadores. */
    .tr-compra-btn { height:45px; flex:0 0 auto; padding:0 16px; display:flex; align-items:center; gap:7px;
        border-radius:12px; background:#0067b1; border:1px solid #0067b1; color:#fff;
        font-family:inherit; font-size:13px; font-weight:700; white-space:nowrap; box-shadow:none; }
    .tr-compra-btn:hover { background:#005596; border-color:#005596; }
    .tr-compra-btn .material-icons { font-size:20px; }
    /* Pantallas angostas: el botón se queda solo con el icono (cuadrado 45px, como el de
       filtros avanzados) para no comerse la fila de los buscadores. El title del botón
       sigue explicando qué hace. */
    @media (max-width: 900px) {
        .tr-compra-btn { padding:0; width:45px; min-width:45px; justify-content:center; gap:0; }
        .tr-compra-txt { display:none; }
    }

    /* Dropdown de sugerencias */
    /* Mismas medidas que .amf-suggest (/almacen/movimientos) y .alm-suggest (/almacen):
       separación 5px, radio 12, sombra 0 10px 25px y padding 5. Antes eran 4/10/18/4, una
       diferencia que no respondía a nada. z-index 1000 también como allá: el 60 anterior
       quedaba por DEBAJO del panel "Filtros avanzados" (z-index:100) que se abre al lado. */
    .tr-suggest {
        position:absolute; top:calc(100% + 5px); left:0; right:0;
        background:#fff; border:1px solid #e2e8f0; border-radius:12px;
        box-shadow:0 10px 25px rgba(0,0,0,0.1);
        max-height:260px; overflow-y:auto; padding:5px;
        z-index:1000; display:none;
        scrollbar-width:thin; scrollbar-color:#cbd5e1 transparent;
    }
    .tr-suggest::-webkit-scrollbar { width:5px; }
    .tr-suggest::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:999px; }
    .tr-suggest::-webkit-scrollbar-track { background:transparent; }
    .tr-suggest.open { display:block; }
    /* MISMA letra que las listas de sugerencias del resto de la app (.alm-suggest-item del
       inventario): la fuente de la app (inherit, no monospace) y 13.5px — antes
       era monospace 12.5px en NEGRITA (700), así que el N° de nota se veía con otro tipo de
       letra y más gordo que todo lo demás. La regla `.tr-suggest, .tr-suggest-item
       { font-family:inherit }` que había más arriba no servía de nada: esta la pisaba. */
    /* La MISMA letra en las dos listas (N° de nota y producto/descripción): el peso 500 se
       declara aquí, no por buscador. Antes la de N° de nota iba en 600 y la de producto en
       500, así que una se veía más gorda que la otra. */
    /* Geometría y hover calcados de .amf-suggest-item (/almacen/movimientos) y
       .alm-suggest-item (/almacen): 10px 15px, radio 8 y realce #f0f4f8. Recepción iba por
       su cuenta (8px 12px, radio 6, realce azul) y su lista se veía de otro módulo. */
    .tr-suggest-item {
        padding:10px 15px; border-radius:8px; cursor:default;
        font-family:inherit; font-size:13.5px; font-weight:500; color:#0f172a;
        letter-spacing:0; transition:background .2s;
    }
    .tr-suggest-item:hover, .tr-suggest-item.active { background:#f0f4f8; }
    .tr-suggest-empty { padding:10px 12px; font-size:12px; color:#94a3b8; font-style:italic; }
    /* El buscador por PRODUCTO reusa .tr-suggest, pero sus ítems muestran una DESCRIPCIÓN
       (texto largo) en vez de un código corto: solo necesita ajuste de línea, la tipografía
       ya la comparte. El nº de parte equivalente va en su propio <span> delante del nombre. */
    #trProdSuggest .tr-suggest-item { white-space:normal; display:flex; align-items:baseline; gap:2px; }

    /* ── Layout: la tabla (.admin-card) y el panel de resumen, cada uno en SU PROPIO
         contenedor, lado a lado. ── */
    .tr-layout { display:flex; gap:14px; align-items:flex-start; }
    /* Panel estilo "Consolidado de Inventario" (/admin/almacen): tarjeta con gradiente
       azul y texto blanco, un número héroe (Por revisar) + dos sub-métricas
       (Recientes / Urgentes) en cajas semitransparentes de color. */
    /* 250px (antes 300): el panel solo muestra 3 métricas cortas y le sobraba ancho,
       mientras la tabla —que sí tiene columnas largas (Origen/Destino)— se apretaba.
       El recorte va todo a la tabla, que es flex:1 en .tr-layout. El interlineado del
       título también se ajusta (letter-spacing 1.5→1px) para que "Resumen de la bandeja"
       siga entrando en una sola línea a este ancho. */
    .tr-stats { flex:0 0 250px; position:relative; overflow:hidden; align-self:flex-start;
        background:linear-gradient(135deg,#1a365d 0%,#2c5282 100%); border-radius:12px;
        padding:13px; color:#fff; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); }
    .tr-stats-bgicon { position:absolute; right:-15px; bottom:-15px; font-size:80px; opacity:0.1; transform:rotate(-15deg); }
    .tr-stats-title { display:flex; align-items:center; gap:6px; font-size:12.5px; font-weight:700; text-transform:uppercase; letter-spacing:1px; opacity:0.8; margin-bottom:10px; }
    .tr-stats-title i { font-size:14px; }
    /* Las 3 métricas, TODAS del mismo tamaño: grid de 3 columnas iguales. */
    .tr-stats-row { display:grid; grid-template-columns:repeat(3,1fr); gap:6px; }
    /* Icono AL LADO del número (en fila); el label cae a su propia línea debajo
       (flex:1 1 100% lo fuerza al siguiente renglón). */
    /* Cada métrica: contenido centrado (icono+número en una línea, label debajo),
       clicable para filtrar la bandeja. */
    .tr-stats-sub { display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:3px 6px; padding:8px 4px; border-radius:8px; text-align:center; cursor:default; user-select:none; transition:transform .12s ease, box-shadow .15s ease, filter .15s ease; }
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
    .tr-table tbody tr:hover td { background:#e0f2fe; cursor:default; }
    .tr-table tbody tr:nth-child(even) td { background:#fafbfc; }
    .tr-table tbody tr:nth-child(even):hover td { background:#e0f2fe; }
    .estado-pill { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:999px; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.3px; }
    .pill-linea { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.2px; }

    /* ── Modal detalle/recepción ── */
    /* Mismo velo que el modal "Auditoría de Inventario" (.alm-modal-overlay): rgba(15,23,42,.45)
       y SIN backdrop-filter. El blur(3px) obligaba al navegador a recomponer toda la página
       detrás del overlay en cada frame — se notaba al abrir/cerrar, sobre todo en teléfono. */
    .dtm-overlay {
        display:none; position:fixed; inset:0; z-index:99999;
        background:rgba(15,23,42,0.45);
        align-items:center; justify-content:center; padding:10px;
    }
    .dtm-overlay.open { display:flex; }
    .dtm-box {
        /* 640px (antes 760): sin el bloque de metadatos ni la columna Recibido ancha, el
           modal sobraba de ancho y la tabla quedaba desperdigada. */
        background:#fff; border-radius:16px; width:100%; max-width:640px;
        max-height:95vh; min-height:60vh; display:flex; flex-direction:column; overflow:hidden;
        box-shadow:0 25px 50px -12px rgba(0,0,0,0.35);
        animation: dtmIn .2s ease-out;
    }
    @keyframes dtmIn { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
    /* Barra superior estilo modal de Movilización: slate #1e293b, icono azul + número
       centrados, botón cerrar absoluto a la derecha. Va a sangre (las esquinas las
       redondea el overflow:hidden de .dtm-box). */
    /* Es la cabecera del modal (antes vivía dentro de .dtm-header, que se eliminó al quitar
       el bloque de metadatos): hereda su flex-shrink:0 para no encogerse cuando la tabla
       de materiales crece. El borde inferior no hace falta: la barra es oscura y a sangre. */
    .dtm-title-row { position:relative; display:flex; align-items:center; justify-content:center; gap:9px; background:#1e293b; padding:15px 48px; flex-shrink:0; }
    .dtm-title-icon { color:#0067b1; font-size:19px; }
    .dtm-numero { font-family:monospace; font-size:15px; font-weight:800; color:#fff; }
    .dtm-close {
        position:absolute; right:12px; top:50%; transform:translateY(-50%);
        background:transparent; border:none; cursor:default;
        color:#fff; opacity:.75; padding:4px; border-radius:6px; transition:opacity .15s;
    }
    .dtm-close:hover { opacity:1; }
    /* Sin bloque .dtm-meta: el encabezado Origen/Destino/Frente/Despachado se quitó del
       modal (pedido del cliente). Sus estilos y los de .dtm-sub se fueron con él. */

    .dtm-body { flex:1; overflow-y:auto; padding:14px 20px; }
    .dtm-notas { display:flex; align-items:flex-start; gap:6px; padding:8px 10px; background:#fffbeb; border:1px solid #fef3c7; border-radius:8px; font-size:12.5px; color:#92400e; margin-bottom:10px; }
    /* "Marcar todas": atajo para la nota que llegó completa. Misma altura (38px) que la caja
       del buscador, con la que comparte la fila .dtm-buscar-row, y el MISMO radio (12px) que
       el botón "Acciones" del resto de la app — no de píldora, que desentonaba. */
    .dtm-marcar-todas { display:inline-flex; align-items:center; gap:5px; flex:none; height:38px; padding:0 12px;
        border:1px solid #cbd5e0; border-radius:12px; background:#fff; color:#0067b1;
        font-family:inherit; font-size:11.5px; font-weight:800; text-transform:uppercase; letter-spacing:.3px; cursor:default; }
    .dtm-marcar-todas:hover { background:#e1effa; border-color:#0067b1; }
    .dtm-marcar-todas .material-icons { font-size:16px; }
    /* Todas tildadas → el botón pasa a ser "Quitar todas" y se ve encendido. */
    .dtm-marcar-todas.activo { background:#0067b1; border-color:#0067b1; color:#fff; }
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
        /* 10px verticales (antes 6): las filas iban muy apretadas y ahora alojan el campo
           editable de "Recibido" (30px de alto). */
        padding:10px 8px; border-bottom:1px solid #eef2f6;
        text-align:center; vertical-align:middle; border-right:1px solid #f1f5f9;
    }
    .dtm-table tbody td:last-child { border-right:none; }
    .dtm-table tbody tr:last-child td { border-bottom:none; }
    .dtm-table tbody tr:hover td { background:#f8fafc; }
    .dtm-td-prod { text-align:left !important; min-width:0; }
    .dtm-td-prod .dtm-linea-cod, .dtm-td-prod .dtm-linea-nom { display:inline; }
    /* Misma letra para TODA la tabla: 13px, misma familia (sin monospace), y todo en NEGRO
       (#0f172a) — antes el nombre iba en gris #334155 y la UM en gris claro #94a3b8. */
    .dtm-linea-cod { font-weight:700; font-size:13px; color:#0f172a; }
    .dtm-linea-nom { font-size:13px; font-weight:500; color:#0f172a; margin-left:4px; }
    /* La UM vive junto a la CANTIDAD enviada, no junto al nombre: más pequeña que el número
       para que se lea "10 UNIDAD" como una magnitud y no compita con la cifra. */
    .dtm-linea-um { font-size:10px; font-weight:600; color:#0f172a; text-transform:uppercase; margin-left:3px; }
    .dtm-col-num { font-weight:600; font-size:13px; color:#0f172a; white-space:nowrap; }
    /* Columna "#": numeración de filas, gris y discreta. */
    .dtm-col-idx { font-size:13px; font-weight:700; color:#94a3b8; width:1%; white-space:nowrap; }
    /* Recepción activa: tocar la fila la marca (azul) y rellena su campo "Recibido" con lo
       enviado. Si llegó menos, se escribe el número a mano; el campo manda sobre el toque.
       Columna estrecha: solo aloja una cifra corta. */
    .dtm-linea-rec { cursor:default; }
    .dtm-linea-rec.recibida td { background:#e1effa; }
    .dtm-linea-rec.recibida:hover td { background:#d6e9fb; }
    .dtm-rec-cant { width:1%; white-space:nowrap; padding-left:4px !important; padding-right:4px !important; }
    /* Check verde de "tildado": aparece al marcar la fila (.recibida). Reserva su hueco
       siempre (visibility, no display) para que el input no se corra al marcar/desmarcar. */
    .dtm-rec-ico { font-size:18px; color:#16a34a; vertical-align:middle; margin-right:5px; visibility:hidden; }
    .dtm-linea-rec.recibida .dtm-rec-ico { visibility:visible; }
    /* El campo es type=text (ver el modal), así que no hay flechitas de spinner que ocultar.
       52px basta para las cantidades reales (1-4 dígitos); a 64px el recuadro se veía
       desproporcionado frente al número que contiene. */
    .dtm-rec-input {
        width:52px; height:28px; padding:0 4px; text-align:center;
        border:1px solid #cbd5e0; border-radius:8px; background:#fff;
        font-family:inherit; font-size:13px; font-weight:600; color:#0f172a;
        outline:none; cursor:text;
    }
    .dtm-rec-input:focus { border-color:#0067b1; box-shadow:0 0 0 2px rgba(0,103,177,.15); }
    .dtm-linea-rec.recibida .dtm-rec-input { border-color:#0067b1; color:#0067b1; font-weight:800; }
    .dtm-diff-value { font-size:13px; font-weight:600; color:#64748b; }

    /* Buscador de materiales del modal — mismo lenguaje visual que .alm-filter-box del
       módulo Inventario (lupa, borde #cbd5e0, radio 12, fondo #fbfcfd), a menor altura
       porque vive dentro de un modal. font-family:inherit: los <input> no heredan la
       fuente y se quedarían en el Arial del navegador. */
    /* Buscador de materiales + "Marcar todas" en la MISMA línea: el buscador crece y el
       botón mide lo suyo. El margen inferior vive en la fila, no en la caja, para que los dos
       queden alineados por arriba y el hueco de abajo sea uno solo. */
    .dtm-buscar-row { display:flex; align-items:center; gap:8px; margin:0 0 10px; }
    .dtm-buscar-row .dtm-buscar-box { flex:1 1 auto; }
    /* Sin margen propio: la caja SIEMPRE va dentro de .dtm-buscar-row, que es quien pone el
       hueco de abajo. Tenía `margin:0 0 10px` y la regla de arriba se lo anulaba — dos
       declaraciones peleando la misma propiedad, y editar el 10px de aquí no hacía nada. */
    .dtm-buscar-box { display:flex; align-items:center; height:38px; border:1px solid #cbd5e0; border-radius:12px; background:#fbfcfd; overflow:hidden; }
    .dtm-buscar-box.active { border-color:#0067b1; background:#e1effa; }
    .dtm-buscar-box .lupa { padding:0 8px; color:#64748b; font-size:18px; }
    .dtm-buscar-box input { flex:1; min-width:0; border:none; background:transparent; outline:none; padding:0 4px; font-family:inherit; font-size:13.5px; color:#0f172a; }
    .dtm-buscar-clear { padding:0 8px; color:#64748b; font-size:18px; cursor:default; display:none; }
    .dtm-buscar-box.active .dtm-buscar-clear { display:block; }
    /* Fila "sin resultados" del buscador (la inyecta trFiltrarLineas). */
    .dtm-sin-resultados td { text-align:center; color:#94a3b8; font-size:13px; padding:22px 10px; }

    .dtm-footer {
        display:flex; align-items:center; justify-content:center; gap:12px; flex-wrap:wrap;
        padding:14px 20px; border-top:1px solid #e2e8f0; flex-shrink:0;
        background:#fff;
    }
    /* Botones del modal: misma paleta que los del modal "Auditoría de Inventario"
       (secundario gris plano #e2e8f0/#475569, primario en el azul global), pero MÁS BAJOS:
       44px de alto con 22px laterales quedaban desproporcionados en un pie de dos botones.
       .dt-btn-primary ya no se define aquí: su único usuario era "Confirmar todo", que se
       eliminó. La página /recepcion/{id} tiene su propia hoja y su propio .dt-btn-primary. */
    .dt-btn {
        height:36px; padding:0 16px; border-radius:10px; cursor:default;
        font-size:13px; font-weight:700; letter-spacing:.2px;
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        border:none; transition:background .15s, transform .1s, opacity .15s;
    }
    .dt-btn i { font-size:17px; }
    /* Secundario (Cancelar / Cancelar borrador / Cancelar y revertir). */
    .dt-btn-cancel { background:#e2e8f0; color:#475569; box-shadow:none; }
    .dt-btn-cancel:hover { background:#cbd5e0; }
    /* Primario: la acción principal del modal (Aceptar / Enviar). Azul SÓLIDO: ya no compite
       con un segundo botón azul, así que el perfilado que tenía "Confirmar (N)" sobra. */
    .dt-btn-blue { background:var(--maquinaria-blue,#0067b1); color:#fff; box-shadow:0 4px 8px -2px rgba(0,103,177,0.3); }
    .dt-btn-blue:hover { background:#005391; }
    .dt-btn-blue:active { transform:scale(0.98); }
    /* "Aceptar (0)": sin filas marcadas no hay nada que aceptar. El :hover se neutraliza
       sobre .dt-btn-blue (el único botón que se deshabilita), no sobre .dt-btn: si no,
       un Cancelar deshabilitado se pintaría de azul al pasar el ratón. */
    .dt-btn:disabled { opacity:.5; cursor:not-allowed; box-shadow:none; }
    .dt-btn-blue:disabled:hover { background:var(--maquinaria-blue,#0067b1); }
    .dt-btn:disabled:active { transform:none; }

    @media (max-width: 768px) {
        .dtm-overlay { padding:0; align-items:flex-end; }
        .dtm-box { max-width:100%; max-height:95vh; border-radius:16px 16px 0 0; }
        .dtm-footer { flex-direction:column; }
        .dtm-footer .dt-btn { width:100%; justify-content:center; }
        /* "Marcar todas" se queda SOLO con el icono: el ancho del teléfono lo necesita el
           buscador, que es donde se escribe. El rótulo sigue disponible en el `title` y el
           estado se ve igual (el botón se enciende cuando están todas marcadas). */
        .dtm-marcar-todas .desktop-text { display:none; }
        .dtm-marcar-todas { padding:0 10px; }
        /* Materiales (tabla): celdas y fuentes más compactas para el ancho del teléfono.
           8px verticales (no 5): la fila aloja el campo editable de "Recibido" (30px). */
        .dtm-table { font-size:12px; }
        .dtm-table thead th, .dtm-table tbody td { padding:8px 4px; }
        /* Campo "Recibido": el ancho (52px) y el alto (28px) del bloque base ya sirven en
           teléfono; aquí sólo se achica la letra para igualar la de la tabla. Antes esta regla
           repetía width:54px/height:28px — el height era idéntico al base y el width, tras
           reducir el base a 52px, dejó de "angostar" nada: lo ensanchaba 2px. */
        .dtm-rec-input { font-size:12px; }
        /* Misma letra (12px) para TODO el texto de la tabla también en móvil. La UM queda
           fuera: acompaña a la cantidad y debe seguir siendo más pequeña que la cifra. */
        .dtm-col-num, .dtm-diff-value, .dtm-linea-cod, .dtm-linea-nom, .dtm-col-idx { font-size:12px; }
        .dtm-linea-um { font-size:9.5px; }
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
        /* flex:0 0 auto — RESETEA el `flex:1 1 0` inline que la tarjeta necesita en
           escritorio. Allí .tr-layout es una FILA y ese flex reparte el ancho sobrante;
           aquí pasa a COLUMNA (regla de arriba), así que el mismo flex-basis:0 se aplica a
           la ALTURA: la tarjeta se quedaba en su min-height (70vh) sin crecer con el
           contenido, y las tarjetas de las notas se salían por debajo del recuadro blanco.
           Con `auto` la altura la manda el contenido y el min-height solo hace de suelo. */
        .admin-card { padding: 4px !important; margin: 0 !important; width: 100% !important;
            flex: 0 0 auto !important; }
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

        /* Filtros en mobile: producto a fila completa arriba y, debajo, la nota junto a los
           dos botones cuadrados —"Filtros avanzados" (que ya contiene Estado/Desde/Hasta) y
           "Compra directa", que a este ancho se queda solo con su icono. */
        #trFilters { gap: 8px !important; }
        /* Producto ocupa su propia fila (es el que más texto muestra); la nota comparte
           renglón con los botones 45x45 — de ahí `flex:1 1 0` en vez de 100%, que la dejaría
           sola en su fila y a los botones huérfanos en la siguiente. */
        #trFilters > .tr-search-prod { flex: 1 1 100% !important; max-width: none !important; min-width: 0 !important; }
        #trFilters > .tr-search-num  { flex: 1 1 0 !important;    max-width: none !important; min-width: 0 !important; }

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
            cursor: default !important;
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
            /* 13px = la escala de la tarjeta (ver el bloque de tipografía más abajo). Una sola
               declaración: antes esto ponía 14px y la regla de más abajo lo pisaba con 13px. */
            font-size: 13px !important; color: #0f172a !important; white-space: nowrap !important;
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
        /* td:4 = Fecha envío — fila "meta" propia, CENTRADA en la tarjeta (antes quedaba
           pegada a la izquierda). flex + wrap para que "hace 4 horas" baje a su renglón
           si no cabe, sin perder el centrado. */
        .tr-table tbody tr[data-id] td:nth-child(4) {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            flex-wrap: wrap !important;
            gap: 4px !important;
            font-size: 12px !important;
            font-weight: 400 !important;
            color: #475569 !important;
            padding-top: 8px !important;
            border-top: 1px dashed #e2e8f0 !important;
            grid-area: meta !important;
        }

        /* Iconito sutil antes de la fecha de envío. */
        .tr-table tbody tr[data-id] td:nth-child(4)::before { content: 'event'; font-family: 'Material Icons'; font-size: 13px; color: #94a3b8; }

        /* ── Tipografía de la tarjeta: escala CORTA y pareja ────────────────────────
           En móvil convivían 14 / 13 / 12.5 / 11.5 / 10.5px y la tarjeta se leía a
           saltos. Se reduce a dos tamaños: 13px para los datos (nº, origen, frente,
           fecha) y 11.5px para lo secundario (almacén físico, "hace X"). Los spans de
           rows.blade traen font-size inline, así que hace falta !important. El nº de nota
           (td:1) ya se fija arriba, en su propia regla; no se repite aquí. */
        /* Origen y frente: una sola declaración. Envuelven (white-space:normal) porque en el
           ancho del teléfono la columna es estrecha y el nowrap del escritorio los recortaría
           con puntos suspensivos. */
        .tr-table tbody tr[data-id] .tr-ruta-origen,
        .tr-table tbody tr[data-id] .tr-ruta-frente {
            font-size: 13px !important;
            white-space: normal !important;
            overflow: visible !important;
            text-overflow: clip !important;
        }
        /* white-space:normal como sus hermanos de arriba: el renglón del almacén pasó a
           llevar el prefijo "Almacén", así que es bastante más largo y con el nowrap del
           style inline se salía de la tarjeta en las pantallas más estrechas. */
        .tr-table tbody tr[data-id] .tr-ruta-alm    { font-size: 11.5px !important; white-space: normal !important; }
        .tr-table tbody tr[data-id] .tr-fecha-rel span { font-size: 11.5px !important; }

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
            $reqIdProd    = request('id_producto');
            $reqProdLabel = $reqIdProd ? (collect($productosLista ?? [])->firstWhere('ID_PRODUCTO', (int) $reqIdProd)?->NOMBRE ?? '') : '';
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
            {{-- Colores del botón (neutro / rojo = hay filtro dentro) en .tr-adv-btn: el JS
                 solo hace toggle de la clase al filtrar por AJAX, sin repetir los hex. --}}
            <button type="button" id="trAdvBtn" class="btn-primary-maquinaria tr-adv-btn {{ $panelActivo ? 'activo' : '' }}"
                    onclick="window.trToggleAdvanced(event)" title="Filtros avanzados">
                <i class="material-icons" style="font-size:20px;">filter_list</i>
            </button>
            <div id="trAdvPanel" style="display:none;position:absolute;top:100%;right:0;width:300px;max-width:calc(100vw - 20px);box-sizing:border-box;background:#e2e8f0;border-radius:12px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.15);border:1px solid #cbd5e1;z-index:100;margin-top:10px;padding:15px;">
                <h4 style="margin:0 0 12px 0;font-size:14px;font-weight:700;color:#334155;display:flex;justify-content:space-between;align-items:center;">
                    Filtros avanzados
                    <span style="font-size:12px;color:#64748b;font-weight:400;text-decoration:underline;cursor:default;" onclick="window.trClearAvanzados()">Limpiar</span>
                </h4>

                {{-- Estado: ocupa las 2 columnas de la grilla, arriba de las fechas. Mismo
                     custom-dropdown que el resto de la app (igual que "Almacén destino" del
                     header). selectOption actualiza el hidden input y dispara
                     'dropdown-selection' → trLoad. --}}
                <div class="tr-adv-grid">
                <div class="tr-adv-full">
                    <span class="tr-adv-label">Estado de la nota</span>
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
                        <span class="tr-adv-label">Desde</span>
                        <div id="trDesdeBox" class="tr-date-box" style="width:100%;box-sizing:border-box;background:{{ $reqDesde ? '#e1effa' : '#fff' }};"
                             onclick="var i=document.getElementById('trDesde'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                            <i class="material-icons">event</i>
                            <input type="date" id="trDesde" value="{{ $reqDesde }}" onchange="window.trResetKpi(); window.trLoad()">
                        </div>
                    </div>
                    <div>
                        <span class="tr-adv-label">Hasta</span>
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
             misma clave que confirmar una recepción — por eso va dentro del @can, igual
             que el tab "Entrada por ODC". --}}
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

     Se captura en DOS pasos, porque los datos del documento son opcionales y estorbaban
     arriba mientras se cargaban productos:
       1) Líneas de entrada  → qué llegó y cuánto.
       2) Datos del proveedor → nota / proveedor / fecha. Se puede dejar en blanco.
     Layout del paso 1: SOLO la tabla hace scroll. La barra de captura queda fija arriba
     (así el buscador está siempre a mano y ningún contenedor con overflow le recorta el
     desplegable) y los botones fijos abajo. --}}
<style>
    .cdir-overlay { display:none; position:fixed; inset:0; z-index:99999; background:rgba(15,23,42,0.45); align-items:center; justify-content:center; padding:10px; }
    .cdir-overlay.open { display:flex; }
    /* 760px de ancho y 82vh de alto. Alto FIJO —no automático— para que el modal no cambie
       de tamaño al pasar de un paso a otro: lo único que se intercambia es el bloque de
       arriba. Los tres campos del paso 2 siguen entrando en fila a este ancho (≈263px para
       nota y proveedor, 170 para la fecha). */
    .cdir-box { background:#fff; border-radius:16px; width:100%; max-width:760px; height:82vh; max-height:94vh;
        display:flex; flex-direction:column; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35);
        animation:dtmIn .2s ease-out; }

    /* Barra superior: mismo slate #1e293b + icono azul del modal de detalle, para que los
       dos modales del módulo se lean como la misma familia. */
    .cdir-title-row { position:relative; display:flex; align-items:center; justify-content:center; gap:9px; background:#1e293b; padding:15px 48px; flex-shrink:0; }
    .cdir-title-row .material-icons { color:#0067b1; font-size:20px; }
    .cdir-title { font-size:14.5px; font-weight:800; color:#fff; letter-spacing:.2px; }
    .cdir-close { position:absolute; right:12px; top:50%; transform:translateY(-50%); background:transparent; border:none;
        cursor:default; color:#fff; opacity:.75; padding:4px; border-radius:6px; transition:opacity .15s; }
    .cdir-close:hover { opacity:1; }
    /* Simétrico a la ✕ pero a la izquierda. Mismo tratamiento visual: transparente sobre la
       barra oscura, y solo se enciende al pasar por encima. */
    .cdir-atras { position:absolute; left:12px; top:50%; transform:translateY(-50%); background:transparent;
        border:none; cursor:default; color:#fff; opacity:.75; padding:4px; border-radius:6px; transition:opacity .15s; }
    .cdir-atras:hover { opacity:1; }

    /* Pasos: solo uno visible a la vez, y los dos son bloques FIJOS en la parte de arriba
       (el paso 1 trae proyecto + barra de captura; el paso 2, los datos del proveedor). El
       alto sobrante se lo queda la tabla de líneas, que vive fuera de los pasos. */
    .cdir-paso { display:none; flex-direction:column; flex-shrink:0; }
    .cdir-paso.on { display:flex; }

    /* Proyecto dueño del stock: franja propia ARRIBA DEL TODO, con fondo tenue, para que se lea
       como "contexto de toda la entrada" y no como un campo más del formulario. La etiqueta
       va ENCIMA del campo (no al lado): así el proyecto elegido queda alineado con el resto
       del modal y se lee de un vistazo al abrir.
       z-index:6 — por encima de .cdir-capt (5), para que sus sugerencias tapen la barra de
       captura que queda debajo. */
    .cdir-proyecto { position:relative; z-index:6; flex-shrink:0; display:flex; align-items:center;
        gap:12px; padding:10px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; }
    .cdir-proyecto .ayuda { flex:0 0 auto; font-size:17px; color:#94a3b8; cursor:default; }
    /* Etiqueta y campo en la MISMA línea: apilados ocupaban dos renglones de alto sin dar
       nada a cambio. nowrap para que el rótulo no se parta en dos. */
    .cdir-proyecto label { flex:0 0 auto; white-space:nowrap; font-size:11px; font-weight:800;
        text-transform:uppercase; letter-spacing:.4px; color:#64748b; }
    .cdir-proyecto .req { color:#dc2626; }
    /* Campo CON BUSCADOR (no un <select> nativo): el almacén puede tener muchos proyectos y
       teclear tres letras es más rápido que recorrer la lista. Misma caja que el resto del
       modal para que no desentone. */
    .cdir-proy-field { position:relative; flex:1 1 auto; min-width:0; }
    .cdir-proy-field .cdir-input { padding-right:34px; }
    .cdir-proy-caret { position:absolute; right:9px; top:50%; transform:translateY(-50%);
        color:#64748b; font-size:20px; pointer-events:none; }
    /* Sin elegir → borde rojo suave. No es un error todavía (nadie ha intentado seguir),
       solo la señal de que falta ese dato. Se apaga en cuanto se elige. */
    .cdir-proy-field .cdir-input.falta { border-color:#f87171; background:#fef2f2; }
    /* Elegido → verde tenue: confirma de un golpe que ese dato ya está resuelto. */
    .cdir-proy-field .cdir-input.listo { border-color:#22c55e; background:#f0fdf4; font-weight:700; }

    /* Zona de captura (fija). z-index para que el desplegable de sugerencias tape la tabla. */
    .cdir-capt { position:relative; z-index:5; flex-shrink:0; padding:14px 20px 10px; background:#fff; }
    .cdir-section-title { font-size:11.5px; font-weight:800; text-transform:uppercase; letter-spacing:.6px; color:#64748b; margin-bottom:8px; }
    .cdir-capt-bar { display:flex; gap:8px; align-items:stretch; }
    .cdir-field { position:relative; flex:1 1 auto; min-width:0; }
    .cdir-input { width:100%; box-sizing:border-box; height:42px; border:1px solid #cbd5e0; border-radius:10px;
        background:#fbfcfd; padding:0 12px; font-family:inherit; font-size:13.5px; color:#0f172a; outline:none; }
    .cdir-input:focus { border-color:#0067b1; background:#fff; }
    .cdir-um-input  { width:78px; flex:0 0 78px; text-align:center; text-transform:uppercase; font-weight:700; font-size:12.5px; }
    .cdir-cant-input{ width:92px; flex:0 0 92px; text-align:center; font-weight:700; }
    .cdir-um-wrap { position:relative; flex:0 0 78px; }
    /* Verde y con un check (pedido del cliente): el gesto es "confirmar esta línea", no
       "sumar un número", y el verde lo separa del azul de las acciones del pie. */
    .cdir-add-btn { flex:0 0 42px; width:42px; height:42px; display:flex; align-items:center; justify-content:center;
        border:1px solid #16a34a; border-radius:10px; background:#16a34a; color:#fff; cursor:default; }
    .cdir-add-btn:hover { background:#15803d; }

    /* Producto ya elegido: el input se oculta y en su lugar va este chip. */
    .cdir-badge { display:none; position:absolute; inset:0; align-items:center; gap:6px; padding:0 8px 0 10px;
        border:1px solid #0067b1; border-radius:10px; background:#e1effa; overflow:hidden; }
    .cdir-badge.show { display:flex; }
    /* Una sola tipografía y un solo color de texto en TODO el modal (pedido del cliente):
       el código ya no va en monospace azul — se distingue por el peso, no por ser otra
       letra de otro color, que hacía ver la línea como dos textos pegados. */
    .cdir-badge .cod { font-weight:800; font-size:13px; color:#0f172a; flex:0 0 auto; }
    .cdir-badge .nom { font-size:13px; font-weight:600; color:#0f172a; flex:1 1 auto; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .cdir-badge .material-icons { font-size:17px; color:#64748b; flex:0 0 auto; cursor:default; }

    /* Desplegables (producto y UM): mismas medidas que .tr-suggest del toolbar. */
    .cdir-suggest, .cdir-um-suggest { display:none; position:absolute; top:calc(100% + 5px); left:0; right:0; z-index:20;
        background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,0.1);
        max-height:230px; overflow-y:auto; padding:5px; }
    .cdir-suggest.open, .cdir-um-suggest.open { display:block; }
    /* El de UM se ancla a la DERECHA y se ensancha hacia la izquierda: el campo mide 78px y
       a ese ancho el aviso de "sin coincidencias" quedaba en una columna de una palabra por
       renglón. Anclado a la derecha crece hacia dentro del modal, nunca hacia afuera. */
    .cdir-um-suggest { left:auto; right:0; min-width:210px; }
    .cdir-suggest-item, .cdir-um-item { padding:8px 10px; border-radius:8px; font-size:13px; color:#0f172a; cursor:default;
        display:flex; align-items:baseline; gap:6px; }
    .cdir-suggest-item:hover, .cdir-um-item:hover { background:#e1effa; }
    .cdir-suggest-item .parte { font-weight:800; font-size:12.5px; color:#0f172a; flex:0 0 auto; }
    .cdir-suggest-item .nom { flex:1 1 auto; min-width:0; }
    /* La UM conserva su recuadro gris (es una etiqueta, no texto corrido) pero el color de
       la letra es el mismo del resto. */
    .cdir-suggest-item .um { flex:0 0 auto; font-size:10px; font-weight:800; text-transform:uppercase; color:#0f172a;
        background:#f1f5f9; border-radius:5px; padding:2px 5px; }
    .cdir-suggest-empty { padding:10px; font-size:12.5px; color:#64748b; line-height:1.45; }

    /* Tabla de líneas — la ÚNICA zona con scroll, y la que absorbe el alto sobrante. */
    .cdir-list-wrap { flex:1 1 auto; min-height:0; display:flex; flex-direction:column; padding:12px 20px 0; }
    .cdir-list-wrap .cdir-section-title { margin-bottom:8px; }
    .cdir-list { flex:1 1 auto; min-height:120px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:10px; }
    .cdir-table { width:100%; border-collapse:collapse; font-size:13px; }
    .cdir-table thead th { position:sticky; top:0; z-index:1; background:#1e293b; color:#fff; font-size:10.5px; font-weight:800;
        text-transform:uppercase; letter-spacing:.5px; padding:8px 10px; text-align:center; white-space:nowrap; }
    .cdir-table thead th.c-desc { text-align:left; }
    .cdir-table tbody td { padding:8px 10px; border-bottom:1px solid #f1f5f9; text-align:center; color:#0f172a; }
    .cdir-table tbody tr:last-child td { border-bottom:none; }
    .cdir-table tbody tr:hover td { background:#f8fafc; }
    .cdir-table .c-num  { width:1%; white-space:nowrap; font-weight:800; color:#94a3b8; }
    .cdir-table .c-cod  { width:1%; white-space:nowrap; font-weight:700; }
    .cdir-table .c-desc { text-align:left; font-weight:600; }
    .cdir-table .c-cant { width:1%; white-space:nowrap; font-weight:800; }
    .cdir-table .c-cant .um { font-size:10px; font-weight:700; color:#0f172a; margin-left:3px; }
    .cdir-table .c-del  { width:1%; }
    .cdir-del-btn { background:transparent; border:none; color:#cbd5e0; cursor:default; padding:2px; display:flex; }
    .cdir-del-btn:hover { color:#ef4444; }
    .cdir-empty { padding:26px 16px; text-align:center; font-size:12.5px; color:#94a3b8; }

    /* Paso 2 — datos del documento del proveedor. */
    .cdir-paso2-head { flex-shrink:0; padding:14px 20px 0; }
    /* Los tres campos SIEMPRE en fila (pedido del cliente). Con la caja en 900px sobra
       ancho: nota y proveedor se reparten lo elástico y la fecha se queda con lo justo. */
    .cdir-meta { flex-shrink:0; display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr) 170px; gap:12px; padding:8px 20px 0; }
    .cdir-meta label { display:block; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.4px; color:#64748b; margin-bottom:4px; }
    .cdir-date { display:flex; align-items:center; gap:6px; cursor:default; }
    .cdir-date input { flex:1; min-width:0; border:none; background:transparent; padding:0; font-family:inherit; font-size:13.5px; color:#0f172a; outline:none; cursor:default; }
    .cdir-error { display:none; flex-shrink:0; margin:10px 20px 0; padding:9px 12px; background:#fee2e2; border:1px solid #fecaca;
        border-radius:10px; color:#b91c1c; font-size:12.5px; font-weight:600; }

    /* Botonera CENTRADA (pedido del cliente). Solo se muestra la del paso activo. */
    .cdir-footer { flex-shrink:0; display:none; align-items:center; justify-content:center; gap:10px; padding:14px 20px; margin-top:12px; border-top:1px solid #e2e8f0; background:#f8fafc; }
    .cdir-footer.on { display:flex; }
    /* min-width igual en los dos: antes "Cancelar" salía más angosto que "Aceptar" porque
       cada uno se medía por su texto y el segundo además lleva icono. */
    .cdir-btn { height:44px; min-width:170px; padding:0 18px; display:flex; align-items:center;
        justify-content:center; gap:6px; border-radius:10px;
        font-family:inherit; font-size:13.5px; font-weight:800; cursor:default; }
    .cdir-btn .material-icons { font-size:18px; }
    .cdir-btn-cancel { background:#fff; border:1px solid #cbd5e0; color:#475569; }
    .cdir-btn-cancel:hover { background:#f1f5f9; }
    .cdir-btn-ok { background:#0067b1; border:1px solid #0067b1; color:#fff; }
    .cdir-btn-ok:hover { background:#005596; }
    .cdir-btn-ok:disabled { opacity:.6; }

    /* Teléfono: la barra de captura pasa a 2 renglones (buscador arriba; UM, cantidad y el
       check abajo) y los datos del proveedor a una sola columna. La caja va a pantalla
       completa. */
    @media (max-width: 640px) {
        .cdir-overlay { padding:0; }
        .cdir-box { max-width:none; max-height:100vh; height:100vh; border-radius:0; }
        .cdir-capt-bar { flex-wrap:wrap; }
        .cdir-field { flex:1 1 100%; }
        .cdir-um-wrap, .cdir-um-input { flex:0 0 76px; width:76px; }
        .cdir-cant-input { flex:1 1 auto; width:auto; }
        {{-- Los tres campos del paso 2 sí se apilan en teléfono: uno al lado del otro
             (como van en escritorio) dejaría cada caja en ~90px, sin ancho útil. --}}
        .cdir-meta { grid-template-columns:1fr; }
        {{-- min-width:0 obligatorio: los 170px de escritorio × 2 botones no caben en un
             teléfono de 360px y la botonera se salía de la caja. Aquí se reparten el ancho
             a partes iguales, que igual los deja del mismo tamaño. --}}
        .cdir-btn { flex:1 1 0; min-width:0; justify-content:center; }
        {{-- Franja del proyecto: el rótulo va en nowrap y ocupa ~190px, así que en un
             teléfono de 360px al buscador le quedaban ~90px — inservible para leer el
             nombre de un proyecto. Aquí el campo baja a su propio renglón. --}}
        .cdir-proyecto { flex-wrap:wrap; row-gap:6px; }
        .cdir-proy-field { flex:1 1 100%; }
    }
</style>

<div class="cdir-overlay" id="cdirOverlay">
    <div class="cdir-box" role="dialog" aria-modal="true" aria-labelledby="cdirTitulo">
        <div class="cdir-title-row">
            {{-- Volver al paso 1. Vive en el encabezado y no en el pie porque el pie lo ocupan
                 las dos acciones de la operación (Cancelar / Aceptar); "atrás" es navegación,
                 no una decisión sobre la entrada. Solo se ve en el paso 2. --}}
            <button type="button" class="cdir-atras" id="cdirAtras" onclick="window.cdirPaso(1)" title="Volver a las líneas" style="display:none;">
                <i class="material-icons" style="color:#fff;">arrow_back</i>
            </button>
            <i class="material-icons">shopping_cart</i>
            <span class="cdir-title" id="cdirTitulo">Entrada por compra directa</span>
            {{-- La ✕ CIERRA SIN PREGUNTAR y CONSERVA lo capturado (mismo criterio que la ✕
                 del modal de detalle): es el gesto de "ahorita vuelvo", no el de descartar.
                 Descartar es el botón Cancelar, que sí avisa. --}}
            <button type="button" class="cdir-close" onclick="window.cdirOcultar()" title="Cerrar sin perder lo capturado"><i class="material-icons" style="color:#fff;">close</i></button>
        </div>

        {{-- ── Paso 1: proyecto + barra de captura ── --}}
        <div class="cdir-paso on" id="cdirPaso1">
        {{-- Proyecto dueño del stock. Solo aparece en almacenes que reparten el saldo entre
             varios proyectos; en los demás no hay nada que elegir y la fila no se pinta.
             Va ARRIBA de las líneas y no en el paso 2 porque no es un dato del documento:
             define a qué bolsa entra TODO lo que se capture debajo. Es obligatorio, y el
             backend lo exige igual por si alguien entra por otra vía. --}}
        <div class="cdir-proyecto" id="cdirProyectoRow" style="display:none;">
            <label for="cdirProyecto">Proyecto dueño del stock <span class="req">*</span></label>
            {{-- El rótulo dice a quién queda amarrado el material; el signo de ayuda explica
                 la consecuencia real sin gastar un renglón en la franja. --}}
            <i class="material-icons ayuda" title="Todo lo que captures abajo se suma al saldo de ESTE proyecto dentro del almacén. Cada proyecto lleva su stock por separado.">help_outline</i>
            <div class="cdir-proy-field">
                <input type="text" id="cdirProyecto" class="cdir-input falta" autocomplete="off"
                       placeholder="Escribe para buscar el proyecto…"
                       oninput="window.cdirProySuggest()" onfocus="window.cdirProySuggest(true)"
                       onkeydown="window.cdirProyKey(event)">
                <input type="hidden" id="cdirProyectoId">
                <i class="material-icons cdir-proy-caret">expand_more</i>
                <div class="cdir-suggest" id="cdirProySuggest"></div>
            </div>
        </div>
        <div class="cdir-capt">
            <div class="cdir-capt-bar">
                <div class="cdir-field">
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
        </div>{{-- /#cdirPaso1 --}}

        {{-- ── Paso 2: datos del documento del proveedor (todos opcionales) ──
             Reemplazan arriba a la barra de captura en vez de compartir pantalla con ella:
             a veces la compra llega sin papeles y a veces con ellos, así que tenerlos a la
             vista mientras se cargan productos solo estorbaba. --}}
        <div class="cdir-paso" id="cdirPaso2">
        <div class="cdir-paso2-head">
            <div class="cdir-section-title">Datos del proveedor — opcional</div>
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
        </div>{{-- /#cdirPaso2 --}}

        {{-- Tabla de líneas: FUERA de los pasos, visible en los dos. En el paso 2 el usuario
             sigue viendo —y puede corregir— lo que está a punto de registrar, y de paso la
             caja no queda con un hueco enorme debajo de tres campos. Es la única zona que
             hace scroll; todo lo demás (encabezado, bloque del paso, pie) queda fijo. --}}
        <div class="cdir-list-wrap">
            <div class="cdir-section-title">Líneas de entrada</div>
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
    var csrf = window.getCsrf;   // helper central (dom_helpers.js)
    function toast(msg, type) { if (window.showToast) window.showToast(msg, type || 'success'); else if (type === 'error') alert(msg); }
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

    // ── Proyecto dueño del stock ──────────────────────────────────────────
    // La franja se muestra SOLO si el almacén reparte el saldo entre varios proyectos. Con
    // uno solo no hay nada que preguntar: todo entra a esa única bolsa.
    // Se rearma en cada apertura porque el almacén de la bandeja cambia en caliente, y una
    // lista pintada para el almacén anterior ofrecería proyectos que no son de éste.
    var proyectosVisibles = [];   // los del almacén abierto, para el buscador

    function montarProyectos(idAlmacen) {
        var cfg = proyectosDe(idAlmacen), row = el('cdirProyectoRow');
        proyectosVisibles = cfg.separa ? cfg.frentes : [];
        row.style.display = cfg.separa ? 'block' : 'none';
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
    // "corta" encuentra "CORTAFUEGO AYACUCHO FASE II" sin escribirlo entero. Con la lista
    // vacía o al enfocar se ofrecen TODOS los del almacén — son pocos y verlos completos
    // ahorra teclear cuando el usuario no recuerda el nombre exacto.
    window.cdirProySuggest = function (todos) {
        var inp = el('cdirProyecto'), box = el('cdirProySuggest');
        if (!inp || !box) return;
        // Sin `todos` = viene de oninput, o sea el texto CAMBIÓ: la elección anterior deja de
        // valer. Se invalida aquí y no en keydown porque allí Tab, las flechas o Ctrl+C
        // también contaban como cambio y borraban un proyecto ya elegido al salir del campo.
        if (!todos && el('cdirProyectoId').value) {
            el('cdirProyectoId').value = '';
            inp.classList.remove('listo');
            inp.classList.add('falta');
        }
        var term = inp.value.trim();
        var lista = (todos || term === '')
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
            headers: { 'Content-Type': 'application/json'},
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
    // 1 = líneas de entrada · 2 = datos del proveedor. Pasar al 2 exige al menos una línea:
    // es el único requisito real de la operación (los datos del paso 2 son todos opcionales).
    window.cdirPaso = function (n) {
        if (n === 2 && !lineas.length) {
            var m = 'Agrega al menos un producto antes de continuar.';
            showErr(m); toast(m, 'error');
            var s = el('cdirSearch'); if (s) s.focus();
            return;
        }
        // El proyecto define a qué bolsa entra TODO lo capturado, así que se exige antes de
        // avanzar — no al final, cuando ya estaría todo cargado y volver sería más molesto.
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
        el('cdirFooter1').classList.toggle('on', n === 1);
        el('cdirFooter2').classList.toggle('on', n === 2);
        // Los desplegables viven en el paso 1; al salir de él quedarían abiertos sobre nada.
        if (n !== 1) { suggestHide(); umHide(); proySuggestHide(); }
        if (n === 2) setTimeout(function () { var i = el('cdirNota'); if (i) i.focus(); }, 40);
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
        window.cdirPaso(1);
        el('cdirOverlay').classList.add('open');
        // Bloquear el scroll del fondo mientras el modal está abierto — mismo cuidado que
        // el modal de detalle. Los dos nunca coexisten: con este abierto, el overlay tapa
        // las filas de la bandeja.
        document.body.style.overflow = 'hidden';
        setTimeout(function () { var s = el('cdirSearch'); if (s) { skipSuggest = true; s.focus(); } }, 60);
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
        if (typeof window.showModal === 'function') {
            window.showModal({ type: 'warning', title: 'Cancelar entrada', message: msg, confirmText: 'Aceptar', cancelText: 'Cancelar', onConfirm: hacer });
        } else if (window.confirm(msg.replace(/<[^>]+>/g, ''))) {
            hacer();
        }
    };

    // ── Registrar ─────────────────────────────────────────────────────────
    window.cdirGuardar = function () {
        if (enviando) return;
        showErr('');
        var dest = destinoActual();
        if (!dest) { var mA = 'No se pudo determinar el almacén destino. Recarga la página.'; showErr(mA); toast(mA, 'error'); return; }
        // Última puerta antes del POST. En la práctica no se llega sin líneas (para entrar al
        // paso 2 ya hace falta al menos una); si pasara, se vuelve al paso 1 y la tabla vacía
        // se explica sola — sin repetir aquí el mensaje que ya da cdirPaso.
        if (!lineas.length) { window.cdirPaso(1); return; }
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
            headers: { 'Content-Type': 'application/json'},
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

<script>
(function () {
    'use strict';
    if (!document.getElementById('trTableBody')) return;
    var ROUTE = @json(route('almacen.recepcion.index'));
    // Valor del pseudo-estado "sin filtro de estado". Sale del modelo para que el JS no
    // repita el literal 'all' que ya define Traspaso::FILTRO_TODAS.
    var TR_FILTRO_TODAS = @json(Traspaso::FILTRO_TODAS);

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
    // Catálogo para el buscador por PRODUCTO (con equivalencias), igual que inventario/movimientos.
    // El map() se arma en un bloque PHP (no en línea dentro de la directiva json): esa
    // directiva separa sus argumentos por comas y las comas del array literal la rompían
    // ("Unclosed '["). OJO: no escribir tokens tipo arroba-php/arroba-json aquí — Blade
    // los compila aunque estén dentro de un comentario // de JS.
    @php
        // UM y CATEGORIA viajan además de lo que necesita el filtro: el modal "Entrada sin
        // nota" reusa ESTE mismo catálogo (no pide otro AJAX) y sí los usa — la UM prefija
        // el campo de la barra de captura y la CATEGORIA se hereda si hay que registrar una
        // presentación nueva.
        $trProductosLista = ($productosLista ?? collect())->map(fn($p) => [
            'ID_PRODUCTO' => $p->ID_PRODUCTO, 'CODIGO' => $p->CODIGO, 'NOMBRE' => $p->NOMBRE,
            'UM' => $p->UM ?? '', 'CATEGORIA' => $p->CATEGORIA ?? '',
            'EQUIV' => $p->EQUIV ?? '', 'PARTE' => $p->PARTE ?? '', 'PARTES' => $p->PARTES ?? [],
        ]);
    @endphp
    window.trProductosLista = @json($trProductosLista);

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
        // 12 sugerencias, el mismo tope que el buscador de nota de /almacen/movimientos
        // (antes 8 aquí: con dos notas del mismo día la lista se cortaba antes de tiempo).
        var TOPE = 12;
        var q = String(input.value || '').trim().toUpperCase();
        var matches = (q === '' ? TR_NUMEROS
            : TR_NUMEROS.filter(function (n) { return String(n).toUpperCase().indexOf(q) !== -1; })
        ).slice(0, TOPE);

        if (matches.length === 0) {
            box.innerHTML = '<div class="tr-suggest-empty">Sin coincidencias</div>';
        } else {
            // El N° de nota sale de REFERENCIA, que es TEXTO LIBRE capturado por el usuario.
            // Se interpola en dos contextos y hay que escapar en los dos, o una referencia
            // llamada `<img src=x onerror=...>` ejecuta al abrir las sugerencias (XSS
            // almacenado). El buscador hermano (trProdSuggest) ya saneaba; este no.
            //   escapeHtml   → texto visible del <div>.
            //   escapeAttrJs → además escapa la comilla que delimita la cadena JS del onclick.
            // Los dos son los helpers centrales (dom_helpers.js).
            box.innerHTML = matches.map(function (n) {
                var s = String(n);
                return '<div class="tr-suggest-item" onclick="window.trSearchPick(\''
                    + window.escapeAttrJs(s) + '\')">' + window.escapeHtml(s) + '</div>';
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

    // ── Al ABRIR la bandeja los buscadores arrancan en el valor del SERVIDOR (vacíos si la URL
    //    no trae filtro ?search / ?id_producto). El HTML ya viene vacío en ese caso, pero algunos
    //    navegadores / la restauración de formularios (bfcache) reponen lo que se había tecleado;
    //    esto revierte los campos a su defaultValue (valor del servidor) al abrir y al restaurar. ──
    window.trResetBuscadores = function () {
        var s = el('trSearch');     if (s) s.value = s.defaultValue;
        var p = el('trProdSearch'); if (p) p.value = p.defaultValue;
        var h = el('trIdProducto'); if (h) h.value = h.defaultValue;
        trSearchToggleClear();
        var pHas = !!(p && p.value.trim());
        var pBox = p ? p.closest('.tr-search-box') : null; if (pBox) pBox.classList.toggle('active', pHas);
        var pX = el('trProdClear'); if (pX) pX.style.display = pHas ? 'flex' : 'none';
    };
    window.trResetBuscadores();
    // bfcache (botón "atrás"): el <script> NO se re-ejecuta, pero el evento pageshow sí dispara.
    if (_trBindGlobal) window.addEventListener('pageshow', function (ev) {
        if (ev.persisted && typeof window.trResetBuscadores === 'function') window.trResetBuscadores();
    });

    // Escribir: sale del modo KPI, refresca la X y las sugerencias, y BUSCA SOLO con un
    // respiro (TR_MIN_BUSCA / TR_ESPERA_MS, arriba). Antes solo buscaba con
    // Enter o eligiendo una sugerencia: tras filtrar una vez, seguir escribiendo no cambiaba
    // nada y había que pulsar la X para poder buscar otra cosa.
    //   · 3+ caracteres → busca (un N° de nota se reconoce con poco: "249", "NE-2026").
    //   · campo vacío   → recarga sin filtro, sin tener que tocar la X.
    //   · 1-2 caracteres → no busca (demasiado amplio); Enter sigue forzando la búsqueda.
    var TR_MIN_BUSCA = 3, TR_ESPERA_MS = 450, _trST;
    window.trSearchInput = function () {
        window.trResetKpi();
        trSearchToggleClear();
        window.trSearchSuggest();
        var txt = (el('trSearch') ? el('trSearch').value : '').trim();
        if (txt.length >= TR_MIN_BUSCA || txt.length === 0) {
            _trST = setTimeout(function () { window.trLoad(); }, TR_ESPERA_MS);
        }
    };

    window.trSearchPick = function (numero) {
        var input = el('trSearch'); if (!input) return;
        input.value = numero;
        var box = el('trSearchSuggest'); if (box) box.classList.remove('open');
        trSearchToggleClear();
        window.trLoad();
    };

    // Enter en el buscador → filtra por el texto tal cual (similitudes vía LIKE del
    // backend), sin tener que elegir una sugerencia. Igual que el módulo Inventario.
    window.trSearchEnter = function (ev) {
        if (ev && ev.key !== 'Enter') return;
        if (ev) ev.preventDefault();
        var box = el('trSearchSuggest'); if (box) box.classList.remove('open');
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
        window.trLoad();
    };

    // ── Buscador por PRODUCTO (con equivalencias) — mismo FuzzySearch compartido que
    //    inventario/movimientos. Al ELEGIR una sugerencia se fija id_producto y se recarga
    //    la bandeja (backend filtra las notas que contienen ese producto). Editar el texto
    //    descarta el producto elegido (como en inventario). ──────────────────────────────
    function trProdToggleClear() {
        var input = el('trProdSearch'); if (!input) return;
        var has = !!v('trIdProducto');
        var x = el('trProdClear'); if (x) x.style.display = has ? 'flex' : 'none';
        var box = input.closest('.tr-search-box'); if (box) box.classList.toggle('active', has);
    }
    window.trProdSuggest = function () {
        var inp = el('trProdSearch'), box = el('trProdSuggest');
        if (!inp || !box) return;
        var rawTerm = inp.value.trim();
        var lista = window.trProductosLista || [];
        var matches = window.FuzzySearch.rank(lista, rawTerm, function (p) {
            return { haystack: (p.CODIGO || '') + ' ' + (p.NOMBRE || '') + ' ' + (p.EQUIV || ''), label: p.NOMBRE || '' };
        }).slice(0, 17);
        var html = '';
        if (!matches.length) {
            html = '<div class="tr-suggest-empty">Sin coincidencias.</div>';
        } else {
            html = matches.map(function (p) {
                var nom = (p.NOMBRE || '').replace(/[<>&"]/g, '');
                var cod = (p.CODIGO || '').replace(/[<>&"]/g, '');
                // Equivalencia que COINCIDE con lo buscado (nº de parte alterno) delante del
                // nombre — helper compartido (misma lógica que inventario/movimientos).
                var parteMostrar = window.FuzzySearch.matchedPart(rawTerm, p.PARTES, p.PARTE);
                var parteSafe = parteMostrar ? String(parteMostrar).replace(/[<>&"]/g, '') : '';
                var parteB = parteSafe ? '<span style="color:#475569;font-weight:700;margin-right:6px;white-space:nowrap;">' + parteSafe + '</span>' : '';
                // data-pick = texto que va al cuadro al elegir: "Nº de parte · descripción",
                // igual que el buscador del inventario. Así el nº de parte buscado (p.ej.
                // P164378) queda VISIBLE en el cuadro y no se pierde. data-pid = match EXACTO.
                var pickText = parteSafe ? (parteSafe + ' · ' + nom) : nom;
                return '<div class="tr-suggest-item" data-pid="' + (p.ID_PRODUCTO || '') + '" data-pick="' + pickText + '" title="' + cod + '">' + parteB + '<span>' + nom + '</span></div>';
            }).join('');
        }
        box.innerHTML = html;
        box.classList.add('open');
    };
    // Escribir en el buscador de producto: si había uno elegido, editarlo lo DESCARTA
    // (quita el filtro y recarga). Luego refresca las sugerencias.
    window.trProdInput = function () {
        var hid = el('trIdProducto');
        if (hid && hid.value) { hid.value = ''; trProdToggleClear(); window.trResetKpi(); window.trLoad(); }
        window.trProdSuggest();
    };
    // Clic en una sugerencia: fija id_producto + el nombre y recarga la bandeja.
    // SPA-safe: se registra UNA sola vez por pestaña (guard _trBindGlobal), igual que los
    // demás listeners globales; sin esto se duplicaba en cada navegación SPA → un clic
    // disparaba N recargas de la bandeja (flicker + carrera de respuestas).
    if (_trBindGlobal) document.addEventListener('click', function (e) {
        var it = e.target.closest('#trProdSuggest .tr-suggest-item');
        if (!it) return;
        var hid = el('trIdProducto'); if (hid) hid.value = it.getAttribute('data-pid') || '';
        var inp = el('trProdSearch'); if (inp) inp.value = it.getAttribute('data-pick') || '';
        var box = el('trProdSuggest'); if (box) box.classList.remove('open');
        trProdToggleClear();
        window.trResetKpi();
        window.trLoad();
    });
    window.trProdClear = function () {
        var hid = el('trIdProducto'); if (hid) hid.value = '';
        var inp = el('trProdSearch'); if (inp) inp.value = '';
        var box = el('trProdSuggest'); if (box) box.classList.remove('open');
        trProdToggleClear();
        window.trResetKpi();
        window.trLoad();
    };

    function params(pageUrl) {
        // Sin `estado` el backend aplica su default (En tránsito + Confirmada parcial). Aquí
        // mandamos los filtros del UI (search/estado/destino/fechas). El estado SIEMPRE se envía
        // —incluido 'all' (Todas/historial) y 'ENVIADO'— para que el backend sepa
        // exactamente qué se pidió y no caiga en el default cuando el usuario eligió otro.
        var p = new URLSearchParams();
        if (v('trSearch'))                                 p.set('search', v('trSearch'));
        if (v('trIdProducto'))                             p.set('id_producto', v('trIdProducto'));

        // Estado: ahora es un custom-dropdown → se lee del hidden input (data-filter-value).
        if (hv('estado'))                                  p.set('estado', hv('estado'));
        // El "Almacén destino" ahora vive en el dropdown del header (no en el panel
        // avanzado). Se lee del hidden input que el custom-dropdown mantiene.
        var dest = hv('id_almacen_destino');
        if (dest)                                          p.set('id_almacen_destino', dest);
        if (v('trDesde'))                                  p.set('desde', v('trDesde'));
        if (v('trHasta'))                                  p.set('hasta', v('trHasta'));
        // KPI del panel: las 3 métricas van al backend (fuerza ESTADO=ENVIADO en las tres;
        // recientes/urgentes añaden además su ventana de tiempo). Antes 'por_revisar' no se
        // mandaba y la tabla caía en el default de la bandeja, que hoy incluye las parciales
        // → se listaban notas que ese número no cuenta.
        if (_trKpi)                                        p.set('kpi', _trKpi);
        if (pageUrl) { try { var pg = new URL(pageUrl, window.location.origin).searchParams.get('page'); if (pg) p.set('page', pg); } catch (e) {} }
        return p;
    }

    // Refresca el tinte azul de los filtros de FECHA (Desde / Hasta) según si tienen valor,
    // y el tinte rojo del botón que abre "Filtros avanzados" si HAY algo activo ahí dentro
    // (estado concreto o alguna fecha). El trigger del Estado se pinta solo: lo maneja el
    // selectOption global del custom-dropdown. Se llama en trLoad para mantener UI = filtros.
    // Sin el repintado del botón, elegir una fecha o un estado por AJAX dejaba el botón gris
    // (el panel quedaba filtrando "en secreto") hasta recargar la página entera.
    function trUpdateChips() {
        var paint = function (id, on) { var e = el(id); if (e) e.style.background = on ? '#e1effa' : '#fff'; };
        var sel   = function (id) { var e = el(id); return e ? e.value : ''; };
        var desde = !!sel('trDesde'), hasta = !!sel('trHasta');
        paint('trDesdeBox', desde);
        paint('trHastaBox', hasta);

        // MISMO criterio que $panelActivo del render (estado concreto = distinto de vacío y
        // de "Todas"). Los colores viven en .tr-adv-btn.activo, aquí solo se alterna la clase.
        var estado = hv('estado');
        var btn = el('trAdvBtn');
        if (btn) btn.classList.toggle('activo', desde || hasta || (estado !== '' && estado !== TR_FILTRO_TODAS));
    }

    // Actualiza las 3 métricas del panel "Resumen de la bandeja" con los conteos frescos
    // que manda cada respuesta AJAX de la bandeja (ver trLoad). Sin esto el panel quedaba
    // pegado al valor calculado en el render inicial de la página.
    window.trUpdateBandejaStats = function (stats) {
        var map = { por_revisar: 'tr-sub-rev', recientes: 'tr-sub-rec', urgentes: 'tr-sub-urg' };
        Object.keys(map).forEach(function (key) {
            var box = document.querySelector('.' + map[key] + '[data-kpi]');
            var strong = box && box.querySelector('strong');
            if (strong && typeof stats[key] === 'number') strong.textContent = stats[key];
        });
    };

    // Actualiza el badge rojo "[N]" del menú "Recepción" (desktop + mobile) sin recargar
    // la página. Mismo motivo que trUpdateBandejaStats: la bandeja se refresca por AJAX
    // pero el badge vive en el layout, fuera de esta vista.
    window.trUpdateNavBadge = function (count) {
        // TODOS los .nav-badge del layout (Recepción escritorio, Recepción móvil y el del
        // menú padre Almacén, que se ve con el menú contraído) pintan el MISMO
        // $traspasosPorRecibir, así que se actualizan por clase y no por una lista de ids:
        // el día que se agregue un cuarto sitio no hay que acordarse de tocar esta vista.
        document.querySelectorAll('.nav-badge').forEach(function (span) {
            span.textContent = count;
            // data-count NO es decorativo: de él cuelga la regla .nav-badge[data-count="0"]
            // de menu.css, que es la única que decide si el badge se ve. Por eso aquí no se
            // toca style.display — sería la misma regla escrita por segunda vez.
            span.dataset.count = count;
        });
    };

    // Nº de la última carga pedida. Con la búsqueda por debounce es normal tener DOS
    // peticiones en vuelo (se teclea mientras la anterior viaja) y la que responde última
    // gana, aunque traiga la consulta vieja. Cada respuesta se descarta si ya no es la
    // última pedida — más barato que abortar y sin tocar el fetch.
    var _trSeq = 0;
    window.trLoad = function (pageUrl) {
        var body = el('trTableBody'); if (!body) return;
        // Punto ÚNICO donde se cancela el debounce del buscador: da igual por dónde se pida
        // la recarga (sugerencia, Enter, X, estado, fechas, KPI, paginación…), ninguna deja
        // atrás un temporizador que dispare una segunda carga 450 ms después.
        clearTimeout(_trST);
        var miSeq = ++_trSeq;
        trUpdateChips();
        var url = ROUTE + '?' + params(pageUrl).toString();
        body.style.opacity = '0.5';
        if (window.showPreloader) window.showPreloader();
        window.apiFetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (miSeq !== _trSeq) return;   // llegó tarde: ya se pidió otra búsqueda
                if (data.html !== undefined) body.innerHTML = data.html;
                var pg = el('trPagination'); if (pg) pg.innerHTML = data.pagination || '';
                // Refrescar las sugerencias del buscador con las del almacén/filtros actuales
                // (el backend las recalcula y las manda en cada respuesta). Sin esto, al
                // cambiar el "Almacén destino" seguían apareciendo notas del almacén anterior.
                if (Array.isArray(data.numerosNotas)) TR_NUMEROS = data.numerosNotas;
                // Panel "Resumen de la bandeja" y badge del menú: el backend ya manda los
                // conteos frescos en cada respuesta AJAX. Sin esto, al confirmar/enviar/
                // cancelar una nota la fila desaparecía de la tabla pero "Por revisar" y el
                // badge rojo del menú "Recepción" seguían con el número de la carga inicial
                // hasta recargar la página entera.
                if (data.bandejaStats) window.trUpdateBandejaStats(data.bandejaStats);
                if (typeof data.traspasosPorRecibir === 'number') window.trUpdateNavBadge(data.traspasosPorRecibir);
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

        window.apiFetch(DETALLE_URL + '/' + id)
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
        // (_trModalSubmitted), al cerrar se guarda lo marcado (las no marcadas quedan
        // PENDIENTES → el backend marca la nota "Confirmada parcial" y sigue en la
        // bandeja). Si no hay nada marcado, cerrar NO guarda nada (evita confirmaciones
        // accidentales).
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

    // Botón "Cancelar" del pie: cerrar SIN guardar nada. Desmarca todo ANTES de cerrar, porque
    // trCloseModal auto-guarda lo marcado (ver arriba) — sin este paso, "Cancelar" habría
    // confirmado la recepción parcial, exactamente lo contrario de lo que dice el botón.
    // Avisa solo si había algo marcado: salir con la nota intacta no necesita confirmación.
    window.trDescartarYCerrar = function () {
        var box = el('trDetalleBox');
        var marcadas = box ? box.querySelectorAll('.dtm-linea-rec.recibida').length : 0;

        var descartar = function () {
            if (box) {
                Array.prototype.forEach.call(box.querySelectorAll('.dtm-linea-rec'), function (r) { trMarcarFila(r, false); });
                window.trUpdateConfirmBtn();
            }
            window.trCloseModal();
        };

        // Sin nada marcado no hay nada que perder: se sale directo.
        if (!marcadas) { descartar(); return; }

        // Confirmación con el modal estándar de la app (window.showModal), igual que el
        // "Cancelar" de /almacen/recepcion/nueva — no con el confirm() del navegador, que
        // saca un cuadro del sistema encima del modal y desentona con el resto del módulo.
        window.confirmarAccion({
            type:        'warning',
            title:       'Salir sin confirmar',
            message:     'Perderás <strong>' + marcadas + ' línea' + (marcadas === 1 ? '' : 's') + '</strong> que ya marcaste. La nota queda sin confirmar.',
            confirmText: 'Salir',
            cancelText:  'Seguir revisando',
        }, descartar);
    };

    // Escape = el gesto reflejo de "salir sin hacer nada", así que va por trDescartarYCerrar
    // igual que el botón Cancelar. Con trCloseModal a secas POSTEABA la recepción parcial sin
    // avisar (ese auto-guardado es a propósito, pero solo al cerrar con la ✕).
    // El guard del overlay NO es de adorno: el listener es GLOBAL y vive mientras se esté en
    // el módulo. Sin él, cualquier Escape con el modal cerrado entraba igual a trCloseModal,
    // que suelta `body.style.overflow` sin mirar si hay OTRO overlay abierto (el showModal de
    // la app lo pone en 'hidden'): el fondo se ponía a hacer scroll por debajo de un modal
    // todavía abierto. uicomponents.js ya tiene ese cuidado; aquí faltaba.
    if (_trBindGlobal) document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var ov = el('trDetalleOverlay');
        if (ov && ov.classList.contains('open')) window.trDescartarYCerrar();
    });

    // Fila marcada (.recibida) = recibida por la cantidad enviada (data-enviada). Sin
    // marcar = no se envia esa linea: queda PENDIENTE y la nota sigue en la bandeja.
    // Punto ÚNICO de lectura del campo "Recibido": acepta coma o punto decimal (el campo es
    // type=text, ver el modal) y devuelve 0 ante cualquier cosa no numérica. Mismo criterio
    // que el campo de cantidad del inventario.
    function trParseCant(valor) {
        return parseFloat(String(valor == null ? '' : valor).replace(',', '.')) || 0;
    }

    // Envía SOLO las filas tildadas (.recibida). Las que no se tildaron NO viajan: el backend
    // las deja PENDIENTES y por eso la nota queda "Confirmada parcial" y sigue en la bandeja.
    // Antes se mandaban todas, las no marcadas con cantidad 0, así que el backend no podía
    // distinguir "no la revisé" de "revisé y no llegó nada" — y toda nota salía confirmada.
    // La cantidad sale del INPUT de cada fila, no de data-enviada: el usuario puede haber
    // escrito menos (faltante) o más (sobrante). El backend acepta cualquier valor ≥ 0 y
    // calcula la diferencia contra lo enviado.
    function trCollectLineas() {
        var box = el('trDetalleBox');
        if (!box) return [];
        var lineas = [];
        // Sin fallback a data-enviada: .dtm-linea-rec solo se pinta cuando también se pinta
        // el input (ver detalle_modal), así que aquí inp existe siempre.
        box.querySelectorAll('.dtm-linea-rec.recibida').forEach(function (card) {
            lineas.push({
                id_linea:          parseInt(card.dataset.idLinea),
                cantidad_recibida: trParseCant(card.querySelector('.dtm-rec-input').value),
            });
        });
        return lineas;
    }

    // Marca/desmarca UNA fila (clase + campo "Recibido"). Fuente única: la usan el clic en la
    // fila y "Marcar todas", así las dos rutas dejan exactamente el mismo estado.
    // trParseCant, no parseFloat: es la MISMA función con la que trCollectLineas lee este
    // data-enviada al enviar. Con dos parsers distintos para el mismo dato, cualquier
    // formato que uno acepte y el otro no (una coma decimal) los pone en desacuerdo.
    function trMarcarFila(row, marcada) {
        row.classList.toggle('recibida', marcada);
        var inp = row.querySelector('.dtm-rec-input');
        if (inp) inp.value = marcada ? trParseCant(row.dataset.enviada) : '';
    }

    // Filas de recepción que el buscador del modal NO está ocultando.
    function trFilasVisibles(box) {
        return Array.prototype.filter.call(
            box.querySelectorAll('.dtm-linea-rec'),
            function (r) { return r.style.display !== 'none'; }
        );
    }

    // Tocar una fila de la recepción activa la marca/desmarca como recibida (azul) y rellena
    // o vacía su campo "Recibido" con la cantidad enviada — el caso normal es "llegó todo".
    // Delegado en #trDetalleBox porque el contenido del modal se carga por AJAX.
    // El input lleva su propio stopPropagation: escribir dentro no debe desmarcar la fila.
    if (_trBindGlobal) document.addEventListener('click', function (e) {
        var row = e.target.closest('#trDetalleBox .dtm-linea-rec');
        if (!row) return;
        var marcar = !row.classList.contains('recibida');
        trMarcarFila(row, marcar);
        // Al MARCAR una fila suelta, el campo queda enfocado con la cantidad seleccionada:
        // el caso normal es "llegó todo" (ya viene puesta), y si llegó menos basta teclear el
        // número real encima sin tener que borrar. Solo al marcar una a una — "Marcar todas"
        // usa trMarcarFila directo y no roba el foco a 20 campos.
        if (marcar) {
            var inp = row.querySelector('.dtm-rec-input');
            if (inp) { inp.focus(); if (inp.select) inp.select(); }
        }
        window.trUpdateConfirmBtn();
    });

    // "Marcar todas" (va al lado del buscador de materiales): el atajo para la nota completa.
    // Actúa sobre las filas VISIBLES — si el buscador del modal está filtrando, marca lo que
    // el usuario tiene delante, no líneas que no puede ver. Si ya están todas marcadas,
    // el mismo botón las quita (es un interruptor, no una acción de una sola dirección).
    window.trMarcarTodas = function () {
        var box = el('trDetalleBox'); if (!box) return;
        var filas = trFilasVisibles(box);
        if (!filas.length) return;
        var faltaAlguna = filas.some(function (r) { return !r.classList.contains('recibida'); });
        filas.forEach(function (r) { trMarcarFila(r, faltaAlguna); });
        window.trUpdateConfirmBtn();
    };

    // Escribir una cantidad marca la fila; borrarla (o poner 0) la desmarca. Así el estado
    // visual y lo que se enviará al backend no pueden contradecirse. Se lee con trParseCant,
    // la misma función que usa trCollectLineas al enviar.
    window.trRecInput = function (inp) {
        var row = inp.closest('.dtm-linea-rec');
        if (!row) return;
        row.classList.toggle('recibida', trParseCant(inp.value) > 0);
        window.trUpdateConfirmBtn();
    };

    // Buscador de materiales del modal. Filtra EN EL CLIENTE contra data-buscar (código +
    // descripción + nºs de parte, ya en minúsculas desde el Blade): las líneas de una nota
    // están todas en el DOM, así que no hay motivo para ir al servidor.
    //
    // OJO: sólo OCULTA filas, no las desmarca. Una línea tildada que queda fuera del filtro
    // sigue habilitando "Aceptar" y se envía igual — si el filtro la des-tildara, buscar
    // un producto perdería en silencio lo que el usuario ya había confirmado.
    window.trFiltrarLineas = function (texto, limpiar) {
        var box = el('trDetalleBox'); if (!box) return;
        var input = box.querySelector('#dtmBuscar');
        if (limpiar && input) input.value = '';
        var q = (limpiar ? '' : String(texto || '')).trim().toLowerCase();

        var caja = box.querySelector('#dtmBuscarBox');
        if (caja) caja.classList.toggle('active', q !== '');

        var visibles = 0;
        box.querySelectorAll('.dtm-linea').forEach(function (tr) {
            var hay = !q || (tr.getAttribute('data-buscar') || '').indexOf(q) !== -1;
            tr.style.display = hay ? '' : 'none';
            if (hay) visibles++;
        });

        // Mensaje de "sin resultados": se crea una sola vez y se reutiliza. No lleva
        // .dtm-linea-rec, así que trCollectLineas no lo recoge al confirmar.
        var tabla = box.querySelector('.dtm-table');
        var tbody = tabla && tabla.querySelector('tbody'); if (!tbody) return;
        var vacio = tbody.querySelector('.dtm-sin-resultados');
        if (!visibles) {
            if (!vacio) {
                // La tabla tiene 4 columnas al recibir y 6 al consultar una nota cerrada:
                // se lee del thead en vez de fijar un número.
                var cols = tabla.querySelectorAll('thead th').length || 4;
                vacio = document.createElement('tr');
                vacio.className = 'dtm-sin-resultados';
                vacio.innerHTML = '<td colspan="' + cols + '">Ningún material coincide con la búsqueda.</td>';
                tbody.appendChild(vacio);
            }
            vacio.style.display = '';
        } else if (vacio) {
            vacio.style.display = 'none';
        }

        // Cambió el conjunto visible → "Marcar todas" puede tener que cambiar de rótulo.
        window.trUpdateConfirmBtn();

        if (limpiar && input) input.focus();
    };

    // Botón "Aceptar": única acción de confirmación. Siempre VISIBLE; se deshabilita
    // mientras no haya filas tildadas (.recibida), en vez de ocultarse — un pie con solo
    // "Cancelar" no dejaba ver que la nota se acepta marcando lo que llegó.
    // Window-function porque el listener global (bind único) la llama.
    window.trUpdateConfirmBtn = function () {
        var box = el('trDetalleBox'); if (!box) return;

        // "Marcar todas" ↔ "Quitar todas": el rótulo sigue al estado de las filas visibles,
        // se llegue como se llegue (botón, clic en una fila, escribir una cantidad o filtrar).
        // Por eso vive aquí, el único punto por el que pasan todas esas rutas.
        var btnTodas = box.querySelector('#dtmMarcarTodas');
        if (btnTodas) {
            var filas  = trFilasVisibles(box);
            var todas  = filas.length > 0 && filas.every(function (r) { return r.classList.contains('recibida'); });
            btnTodas.classList.toggle('activo', todas);
            var rotulo = todas ? 'Quitar todas' : 'Marcar todas';
            var txt = btnTodas.querySelector('.desktop-text');
            if (txt) txt.textContent = rotulo;
            btnTodas.title = rotulo; // en teléfono el texto se oculta y solo queda el title
        }

        var btnSel = box.querySelector('#trConfirmSelBtn'); if (!btnSel) return;
        // Habilitado si hay AL MENOS una marcada, incluidas las que el buscador esté ocultando:
        // son las que se van a enviar (ver trFiltrarLineas).
        btnSel.disabled = !box.querySelector('.dtm-linea-rec.recibida');
    };

    function trModalPost(url, payload, successMsg) {
        if (window.showPreloader) window.showPreloader();
        window.apiFetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
        .then(function (res) {
            if (res.ok) {
                if (window.showToast) window.showToast(successMsg || res.data.message || 'Operación exitosa', 'success');
                window.trCloseModal();
                window.trLoad();
            } else {
                // La acción NO se aplicó: se deshace la marca de "ya enviado" para que el
                // modal, que sigue abierto, vuelva a comportarse como uno sin confirmar. Sin
                // esto, tras un 403 el cierre con la ✕ dejaba de auto-guardar lo marcado.
                _trModalSubmitted = false;
                if (window.showToast) window.showToast(res.data.message || 'Error en la operación', 'error');
            }
        })
        .catch(function () {
            _trModalSubmitted = false;
            if (window.showToast) window.showToast('Error de conexión', 'error');
        })
        .finally(function () { if (window.hidePreloader) window.hidePreloader(); });
    }

    // "Aceptar": confirma SOLO las filas tildadas (.recibida); las demás quedan PENDIENTES
    // → el backend marca la nota "Confirmada parcial" y la deja en la bandeja. Si se tildan
    // todas, la nota queda cerrada (RECIBIDO) aunque alguna cantidad no cuadre.
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

    // ANULAR la nota: la deja CANCELADA y devuelve el stock al origen. Confirma con el modal
    // estándar (window.showModal, type danger = botón rojo), igual que trDescartarYCerrar: el
    // confirm() del navegador saca un cuadro del sistema ENCIMA del modal y, en la acción más
    // destructiva del módulo, era justo donde peor quedaba.
    window.trModalCancelar = function (neNumero) {
        if (!_trModalId) return;
        var anular = function () {
            _trModalSubmitted = true; // acción explícita → el cierre posterior no auto-guarda
            trModalPost(DETALLE_URL + '/' + _trModalId + '/cancelar', {}, 'Nota anulada');
        };
        // El mensaje dice lo que PASA (el stock vuelve al origen), no solo que es irreversible:
        // quien la anula tiene que saber dónde queda la mercancía.
        window.confirmarAccion({
            type:        'danger',
            title:       'Anular la nota',
            message:     'La nota <strong>' + (neNumero || _trModalId) + '</strong> quedará ANULADA y el stock volverá al almacén de origen. No se puede deshacer.',
            confirmText: 'Anular',
            cancelText:  'No, volver',
        }, anular);
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
        // trSetEstadoDefault limpia el Estado con el helper global y recarga por su cuenta:
        // sin este guard el evento del helper dispararía un segundo trLoad y, peor, un
        // trResetKpi que borraría el KPI que trKpiFilter acaba de fijar.
        if (window.__trSkipDropEvt) return;
        // Cambiar Estado/Almacén a mano sale del modo KPI.
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
    // "Limpiar" del panel: vacía TODO lo que vive dentro (Estado + Desde/Hasta) — antes solo
    // limpiaba las fechas y el Estado, ya mudado al panel, quedaba filtrando por detrás.
    // NO toca los buscadores del toolbar (producto / N° de nota): esos tienen su propia X.
    window.trClearAvanzados = function () {
        trSetEstadoDefault();
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

    // Deja el dropdown de Estado sin filtro, con la MISMA llamada que usa su X
    // (selectOption con valor y etiqueta vacíos): hidden vacío, placeholder vacío, colores
    // neutros, sin X y sin opción resaltada. Antes esto se reimplementaba a mano aquí y ya
    // divergía del helper global (no restauraba las opciones que el buscador del dropdown
    // hubiera ocultado).
    // La bandera evita la recarga DOBLE: el helper emite 'dropdown-selection' y quien llama
    // aquí (KPIs / "Limpiar") hace su propio trLoad después. Los KPIs filtran vía `kpi`, no
    // vía estado, así que el dropdown debe quedar visualmente sin filtro.
    // La bandera vive en window (no en el IIFE): el listener se registra UNA vez por pestaña
    // (_trBindGlobal) y en una navegación SPA seguiría leyendo la variable de la primera
    // corrida mientras esta función escribiría la de la nueva → el guard no dispararía.
    function trSetEstadoDefault() {
        if (!el('trEstadoDropdown') || typeof window.selectOption !== 'function') return;
        window.__trSkipDropEvt = true;
        // selectOption con etiqueta VACÍA, no clearDropdownFilter: ese helper pone
        // "Seleccionar..." cuando no hay data-default-label, y este campo debe quedar vacío.
        // finally: el evento se despacha síncrono dentro del helper, así que al salir del
        // try la bandera ya cumplió su función — y no queda encendida si el helper lanza.
        try { window.selectOption('trEstadoDropdown', '', ''); } finally { window.__trSkipDropEvt = false; }
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

    // Resaltado inicial: solo si la URL trae un KPI. La carga SIN filtros ya NO equivale a
    // "Por revisar" — el default de la bandeja incluye las confirmadas parciales — así que
    // marcar esa métrica al abrir mentía sobre lo que se está viendo.
    (function () {
        var k = new URLSearchParams(window.location.search).get('kpi');
        if (k === 'por_revisar' || k === 'recientes' || k === 'urgentes') _trKpi = k;
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
