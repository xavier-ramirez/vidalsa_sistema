@extends('layouts.estructura_base')

@section('title', 'Entrada por ODC')

@section('content')
{{-- ────────────────────────────────────────────────────────────────
     Pantalla "Entrada por ODC" — la recepción del almacén GENERAL: registra lo que la
     empresa compró y el proveedor le entregó (TraspasoController::index manda aquí a quien
     abre Recepción con un almacén GENERAL). Misma operacion que el modal "Entrada por
     compra directa" de la bandeja (POST a almacen.movimientos.lote con tipo=ENTRADA), con
     los mismos campos y controles, pero como página a dos columnas: a la izquierda la barra
     de captura y debajo la tabla de líneas; a la derecha el panel —resumen de la entrada y
     los botones—.

     Flujo de captura:
       1) Barra de captura (encima de la tabla): [Buscar producto por código/descripción]
          [UM] [Cantidad] [✓].
          - Si el producto EXISTE: aparece como sugerencia → Enter elige el primero →
            (la UM se prefija con la del catalogo pero queda EDITABLE) → escribir
            cantidad → Enter agrega a la tabla. Si se cambia la UM a otra presentacion
            (UND→CAJA, etc.) entra como un producto aparte con el mismo nombre y la UM
            nueva — el original queda intacto (reusa la presentacion si ya existia).
          - Si el producto NO existe: igual escribis la cantidad → Enter → el sistema
            crea el producto al vuelo (codigo auto numerico de 6 digitos, UM=UND) y lo agrega a la
            tabla. Se puede editar despues desde /admin/almacen.
       2) Registrar entrada: abre el modal "Datos del documento" —proyecto (si el almacén
          reparte el saldo), nota de entrega, proveedor y fecha— y su Registrar hace el POST
          de TODAS las lineas como un lote ENTRADA. El almacén es el del usuario (pill del
          encabezado).

     "Reposición del general" (la bandeja de los almacenes de proyecto, donde se ve lo que el
     general despachó y va llegando) se abre desde Acciones → "Despachos" del
     Historial (/admin/almacen/movimientos).
     ──────────────────────────────────────────────────────────────── --}}

{{-- max-width:none: mismo ancho que las dos columnas de abajo (el tope general es 1200px). --}}
<section class="page-title-card" style="text-align:left;margin:0 0 10px 0;max-width:none;">
    {{-- Título + pill del almacén destino --}}
    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
        <div style="flex:0 0 auto;">
            <h1 class="page-title" style="margin:0;">
                <span class="page-title-line2" style="color:#000;">Recepción de materiales</span>
            </h1>
        </div>
        <span aria-hidden="true" class="ent-header-sep" style="display:inline-block;width:1px;height:34px;background:#cbd5e0;flex:0 0 auto;"></span>
        <div class="ent-header-block" style="display:flex;align-items:center;gap:10px;flex:0 1 auto;">
            <div class="ent-dest-pill" title="Almacén destino">
                <span class="ic"><i class="material-icons">warehouse</i></span>
                <span class="name">{{ $almacenDestino->NOMBRE }}</span>
            </div>
        </div>
    </div>
</section>

<link rel="stylesheet" href="{{ asset('css/vistas/admin_almacen_recepcion_nueva.css') }}?v={{ @filemtime(public_path('css/vistas/admin_almacen_recepcion_nueva.css')) }}">

<div class="ent-layout">
<input type="hidden" id="entAlmacen" value="{{ $almacenDestino->ID_ALMACEN }}">

{{-- Columna izquierda: la barra de captura y, debajo, las líneas capturadas --}}
<div class="ent-main">
<div class="ent-card ent-capt-card">
    <div class="ent-capt">
        <div class="ent-search-field">
            <i class="material-icons ent-search-lupa">search</i>
            <input type="text" id="entSearch" class="ent-search-input" autocomplete="off"
                   placeholder="Buscar producto por código o descripción…"
                   oninput="window.entSuggest()" onfocus="window.entSuggest()" onkeydown="window.entSearchKey(event)">
            <div id="entSelectedBadge" class="ent-selected-badge">
                <span class="cod" id="entSelectedCod"></span>
                <span id="entSelectedNom"></span>
                <i class="material-icons clear" onclick="window.entClearSelected()" title="Cambiar producto">close</i>
            </div>
            <div id="entSuggest" class="ent-suggest"></div>
        </div>
        <div class="ent-capt-row">
        <div class="ent-um-wrap" title="Unidad de medida">
            <input type="text" id="entUm" class="ent-um-input" value="UND"
                   maxlength="20" autocomplete="off" aria-label="Unidad de medida" placeholder="UND"
                   oninput="window.entUmSuggest()" onfocus="window.entUmSuggest(true)" onkeydown="window.entUmKey(event)">
            <div id="entUmSuggest" class="ent-um-suggest"></div>
        </div>
        <div class="ent-cant-stepper" title="Cantidad (Enter agrega)">
            <input type="text" inputmode="decimal" enterkeyhint="done" id="entCant" class="ent-cant-input"
                   placeholder="Cant." autocomplete="off" aria-label="Cantidad" onkeydown="window.entCantKey(event)">
        </div>
        <button type="button" class="ent-add-btn" onclick="window.entAgregar()" title="Agregar línea (Enter)">
            <i class="material-icons">check_circle</i>
        </button>
        </div>{{-- /.ent-capt-row --}}
    </div>{{-- /.ent-capt --}}
    <div id="entError" class="ent-error"></div>
</div>{{-- /.ent-capt-card --}}

<div class="ent-card ent-list-wrap">
<div class="ent-list-box">
    <table class="ent-list-table">
        <thead>
            <tr>
                <th class="col-num">Nº</th>
                <th class="col-codigo">Código</th>
                <th class="col-desc">Descripción</th>
                <th class="col-cant">Cantidad</th>
                <th class="col-del"></th>
            </tr>
        </thead>
        <tbody id="entLineasTbody"></tbody>
    </table>
    <div class="ent-empty" id="entEmpty">
        <span class="ent-empty-ic" aria-hidden="true"><i class="material-icons">playlist_add</i></span>
        <span>Busca un producto, escribe la cantidad y presiona Enter para agregarlo.</span>
    </div>
</div>
</div>{{-- /.ent-list-wrap --}}
</div>{{-- /.ent-main --}}

{{-- Columna derecha: el panel, como el "Resumen del pedido" de una tienda en línea —el
     resumen de lo capturado y los botones apilados al final—. --}}
<aside class="ent-side">
<div class="ent-card">
    {{-- Resumen de la entrada, al día con la tabla (entResumen). Las cantidades van POR
         unidad de medida: sumar 3 UND + 2 CAJA no da un número que signifique nada. El total
         cuenta productos DISTINTOS (líneas de la tabla), no unidades. --}}
    <div class="ent-panel-sec">
        <div class="ent-res-title"><i class="material-icons">receipt_long</i> Resumen de la entrada</div>
        <div class="ent-res-row">
            <span class="ent-res-lbl">Cantidades por unidad</span>
            <span class="ent-res-um" id="entResUm"></span>
        </div>
        <div class="ent-res-row ent-res-total">
            <span class="ent-res-lbl">Productos distintos</span>
            <span id="entResLineas">0</span>
        </div>
    </div>

    {{-- Botones uno debajo del otro, en el panel y no junto a la fila de cantidad: mientras
         se captura, Enter agrega la línea; Registrar entrada va cuando la nota ya está
         completa (abre el modal de los datos del documento). Cancelar vacía la captura (pide
         confirmación si hay líneas). Ninguno sale de la página. --}}
    <div class="ent-foot">
        <button type="button" class="ent-btn ent-btn-ok" onclick="window.entAbrirDocumento()">
            <i class="material-icons">check_circle</i><span>Registrar entrada</span>
        </button>
        <button type="button" class="ent-btn ent-btn-cancel" onclick="window.entCancelar()">Cancelar</button>
    </div>
</div>{{-- /.ent-card (panel) --}}
</aside>{{-- /.ent-side --}}
</div>{{-- /.ent-layout --}}

{{-- Modal "Datos del documento". Es un <form>: Enter en cualquier campo registra, igual que
     el botón. La ✕ y Cancelar solo lo cierran — lo capturado y lo escrito aquí se conservan. --}}
<div class="ent-doc-overlay" id="entDocOverlay" hidden>
    <form class="ent-doc-box" role="dialog" aria-modal="true" aria-labelledby="entDocTitulo" novalidate
          onsubmit="event.preventDefault(); window.entGuardar();">
        <div class="ent-doc-bar">
            <i class="material-icons">shopping_cart</i>
            <span class="ent-doc-title" id="entDocTitulo">Registrar entrada</span>
            <button type="button" class="ent-doc-close" onclick="window.entCerrarDocumento()" title="Cerrar"><i class="material-icons">close</i></button>
        </div>
        <div class="ent-doc-body">
            {{-- Asociar material a un proyecto. Solo en almacenes que reparten el saldo entre varios
                 proyectos; en el resto no hay nada que elegir (todo va a la bolsa comun) y el campo
                 no se pinta. Va primero porque no es un dato del documento: define a que bolsa
                 entra TODO lo capturado. Obligatorio — el backend lo exige igual
                 (AlmacenController::registrarMovimientoLote). --}}
            @if($separaProyectos ?? false)
            <div class="ent-proyecto-row">
                <label class="ent-field-label" for="entProyecto">Asociar material a un proyecto <span style="color:#dc2626;">*</span></label>
                <select id="entProyecto" class="ent-input falta" onchange="window.entProyectoCambio()">
                    {{-- Sin preseleccion a proposito: elegir por el usuario es justo lo que ensuciaba
                         el saldo antes (se mandaba el primer proyecto del almacen, acertara o no). --}}
                    <option value="">Selecciona el proyecto…</option>
                    @foreach($frentesDestino as $f)
                        <option value="{{ $f['id'] }}">{{ $f['nombre'] }}</option>
                    @endforeach
                </select>
            </div>
            @endif

            {{-- Nota, proveedor y fecha son opcionales: lo dice cada rótulo. --}}
            <div class="ent-field-group">
                <label class="ent-field-label" for="entNotaEntrega">Nota de entrega <span class="ent-opc">(Opcional)</span></label>
                <input type="text" id="entNotaEntrega" class="ent-input" maxlength="100" placeholder="Número de la nota" autocomplete="off">
            </div>
            <div class="ent-field-group">
                <label class="ent-field-label" for="entProveedor">Proveedor <span class="ent-opc">(Opcional)</span></label>
                <input type="text" id="entProveedor" class="ent-input" maxlength="200" placeholder="Razón social o nombre" autocomplete="off">
            </div>
            <div class="ent-field-group">
                <label class="ent-field-label" for="entFecha">Fecha <span class="ent-opc">(Opcional)</span></label>
                <div class="ent-input" style="display:flex;align-items:center;gap:6px;cursor:default;"
                     onclick="var i=document.getElementById('entFecha'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                    <i class="material-icons" style="font-size:16px;color:#64748b;">event</i>
                    <input type="date" id="entFecha" style="flex:1;min-width:0;height:100%;border:none;background:transparent;padding:0;font-size:13px;font-family:inherit;outline:none;color:#0f172a;cursor:default;">
                </div>
            </div>
            <div id="entDocError" class="ent-error"></div>
        </div>
        <div class="ent-doc-foot">
            <button type="button" class="ent-btn ent-btn-cancel" onclick="window.entCerrarDocumento()">Cancelar</button>
            <button type="submit" class="ent-btn ent-btn-ok" id="entSubmit">
                <i class="material-icons">check_circle</i><span>Registrar</span>
            </button>
        </div>
    </form>
</div>

@php
    // Lo que el JavaScript del modulo necesita de ESTA apertura. El codigo esta en
    // public/js/maquinaria/recepcion_entrada.js, que el navegador cachea.
    $rece_cfg = [
        'rutaAlmacenMovimientosLote' => route('almacen.movimientos.lote'),
        'rutaAlmacenProductosStore' => route('almacen.productos.store'),
        'rutaAlmacenProductosAutocomplete' => route('almacen.productos-autocomplete'),
        'unidadesMedida' => $unidadesMedida ?? [],
        'separaProyectos' => $separaProyectos ?? false,
        'idFrenteDestino' => $idFrenteDestino ?? null,
    ];
@endphp
<script>
    window.RECE_CFG = @json($rece_cfg);
</script>
<script src="{{ asset('js/maquinaria/recepcion_entrada.js') }}?v={{ @filemtime(public_path('js/maquinaria/recepcion_entrada.js')) }}"></script>
<script>
    // El archivo de arriba se carga UNA vez en toda la sesion; esta llamada es la que
    // monta la pantalla, y corre en cada apertura del modulo (tambien al volver por la
    // navegacion interna, que es cuando el <script src> ya no se re-ejecuta).
    window.recepcionEntradaArrancar(window.RECE_CFG);
</script>
@endsection
