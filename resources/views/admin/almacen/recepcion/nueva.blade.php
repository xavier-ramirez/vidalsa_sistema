@extends('layouts.estructura_base')

@section('title', 'Entrada por ODC')

@section('content')
{{-- ────────────────────────────────────────────────────────────────
     Pantalla "Registrar entrada directa" — reemplaza al viejo modal
     #entModal de /admin/almacen/recepcion. Misma operacion (POST a
     almacen.movimientos.lote con tipo=ENTRADA) pero como pagina propia
     con autocomplete de producto por codigo o descripcion.

     Flujo de captura:
       1) Cabecera con datos del lote (almacen derivado + nota de entrega + proveedor
          + fecha). El panel lateral contiene las acciones del lote (Registrar /
          Cancelar).
       2) Fila de captura: [Buscar serial/descripcion] [Cantidad] (stepper ▲▼).
          - Si el producto EXISTE: aparece como sugerencia → Enter elige el primero →
            (la UM se prefija con la del catalogo pero queda EDITABLE) → escribir
            cantidad → Enter agrega a la tabla. Si se cambia la UM a otra presentacion
            (UND→CAJA, etc.) entra como un producto aparte con el mismo nombre y la UM
            nueva — el original queda intacto (reusa la presentacion si ya existia).
          - Si el producto NO existe: igual escribis la cantidad → Enter → el sistema
            crea el producto al vuelo (codigo auto numerico de 6 digitos, UM=UND) y lo agrega a la
            tabla. Se puede editar despues desde /admin/almacen.
       3) Submit: POST de TODAS las lineas como un lote ENTRADA.
     ──────────────────────────────────────────────────────────────── --}}

<section class="page-title-card" style="text-align:left;margin:0 0 10px 0;">
    {{-- Fila 1: Título + pill del almacén destino --}}
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
    {{-- Fila 2: Tabs de navegación (coherente con la bandeja) --}}
    <div class="ent-tabs" style="display:flex;gap:0;margin-top:12px;border-bottom:2px solid #e2e8f0;">
        <a href="{{ route('almacen.recepcion.index', ['force' => 1]) }}"
           style="display:flex;align-items:center;gap:6px;padding:8px 20px;font-size:13px;font-weight:600;color:#64748b;text-decoration:none;transition:all .15s;"
           onmouseenter="this.style.color='#0067b1'" onmouseleave="this.style.color='#64748b'">
            <i class="material-icons" style="font-size:16px;">inbox</i> Bandeja de entrada
        </a>
        <span style="display:flex;align-items:center;gap:6px;padding:8px 20px;font-size:13px;font-weight:700;color:#0067b1;border-bottom:2px solid #0067b1;margin-bottom:-2px;">
            <i class="material-icons" style="font-size:16px;">add_circle_outline</i> Entrada<span class="ent-txt-full"> por ODC</span>
        </span>
    </div>
</section>

<style>
    /* ── Entrada por ODC — layout 2 columnas (form + tabla | resumen) estilo WMS ── */
    .ent-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px 20px; box-shadow:0 4px 12px rgba(15,23,42,0.04); }

    /* Encabezado de sección ("Líneas de entrada"): solo texto (sin icono). */
    .ent-section-title { display:flex; align-items:center; gap:7px; font-size:13px; font-weight:700; color:#334155; text-transform:uppercase; letter-spacing:.5px; }

    /* Cabecera del lote: N° Doc | Proveedor | Fecha. Las acciones (Cancelar / Registrar)
       viven en el panel lateral de la derecha (estilo checkout). */
    .ent-form-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 160px;
        gap: 12px;
        align-items: end;
        min-width: 0;
    }
    .ent-field-group { display:flex; flex-direction:column; gap:4px; }
    /* Proyecto dueño del stock: franja propia arriba de la cabecera, con fondo tenue para que se
       lea como contexto de TODA la entrada y no como un campo mas del formulario. */
    .ent-proyecto-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap;
        margin-bottom:14px; padding:11px 13px; background:#f8fafc;
        border:1px solid #e2e8f0; border-radius:10px; }
    .ent-proyecto-row select { flex:1 1 220px; min-width:0; height:38px; font-family:inherit; }
    /* Sin elegir → borde rojo suave. No es un error todavia (nadie intento registrar aun),
       solo la senal de que falta ese dato. Se apaga en cuanto se elige. */
    .ent-proyecto-row select.falta { border-color:#f87171; background:#fef2f2; }
    .ent-field-label { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.4px; }
    /* Mobile responsive (≤900px y ≤480px) en estilos_globales.css scopeado con body:has(.ent-layout). */

    /* Pill del almacen destino en el page-title-card */
    .ent-dest-pill {
        display:inline-flex; align-items:center;
        height:40px; min-width:260px; padding:0;
        background:#f8fafc; border:1px solid #cbd5e0; border-radius:10px;
        white-space:nowrap; overflow:hidden;
    }
    .ent-dest-pill .ic { padding:0 10px; display:flex; align-items:center; color:#0067b1; }
    .ent-dest-pill .ic .material-icons { font-size:18px; transform:none !important; }
    .ent-dest-pill .name { padding:0 12px 0 4px; font-size:13.5px; color:#0f172a; font-weight:700; overflow:hidden; text-overflow:ellipsis; }

    .ent-input { width:100%; min-width:0; height:38px; border:1px solid #cbd5e0; border-radius:8px; padding:0 10px; font-size:13px; background:#fff; outline:none; box-sizing:border-box; color:#0f172a; }
    .ent-input:focus { border-color:var(--maquinaria-blue,#0067b1); box-shadow:0 0 0 2px rgba(0,103,177,0.10); }
    .ent-input::placeholder { color:#94a3b8; opacity:1; }
    select.ent-input { cursor:default; }

    /* UM autocomplete */
    .ent-um-wrap { position:relative; }
    /* Campo Unidad SIN negrita (peso normal 400), a pedido del cliente: la "UND"
       se veía resaltada respecto al resto de la barra de captura. */
    .ent-um-input {
        width:100%; height:40px; border:1px solid #cbd5e0; border-radius:10px;
        padding:0 10px; font-size:13.5px; font-weight:400; color:#0f172a;
        background:#fff; outline:none; box-sizing:border-box; text-transform:uppercase;
    }
    .ent-um-input:focus { border-color:var(--maquinaria-blue,#0067b1); }
    .ent-um-suggest {
        position:absolute; top:calc(100% + 4px); left:0; right:0;
        background:#fff; border:1px solid #e2e8f0; border-radius:10px;
        box-shadow:0 12px 24px -8px rgba(15,23,42,0.20);
        max-height:240px; overflow-y:auto; padding:4px;
        z-index:9000; display:none;
    }
    .ent-um-suggest.open { display:block; }
    .ent-um-suggest-item { padding:6px 10px; border-radius:6px; cursor:default; font-size:12.5px; font-weight:600; color:#0f172a; }
    .ent-um-suggest-item:hover, .ent-um-suggest-item.active { background:#e1effa; }
    .ent-um-suggest-empty { padding:8px 10px; font-size:11.5px; color:#94a3b8; font-style:italic; }

    /* Buscador de producto */
    .ent-search-field { position:relative; height:40px; }
    .ent-search-input { width:100%; box-sizing:border-box; height:40px; border:1px solid #cbd5e0; border-radius:10px; padding:0 12px 0 38px; font-size:13.5px; background:#fff url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="%2364748b" viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>') no-repeat 12px center; outline:none; color:#0f172a; }
    .ent-search-input:focus { border-color:var(--maquinaria-blue,#0067b1); }
    .ent-search-input:disabled { background-color:#f1f5f9; cursor:not-allowed; }
    /* Mismo estilo de letra que el nombre de la tabla de líneas (.ent-list-nom):
       13.5px / 600 / #0f172a, sin negritas. El código (.cod) hereda este estilo
       — sin override propio — para que todo el badge se vea uniforme. */
    .ent-selected-badge { display:none; position:absolute; inset:0; z-index:2; align-items:center; gap:6px; padding:0 12px; background:#fff; border:1px solid #cbd5e0; border-radius:10px; color:#0f172a; font-size:13.5px; font-weight:600; white-space:nowrap; overflow:hidden; box-sizing:border-box; }
    .ent-selected-badge.show { display:flex; }
    .ent-selected-badge .clear { cursor:default; color:#475569; margin-left:auto; font-size:18px; }
    .ent-selected-badge .clear:hover { color:#dc2626; }

    .ent-suggest {
        position:absolute; top:calc(100% + 4px); left:0; right:0;
        background:#fff; border:1px solid #e2e8f0; border-radius:10px;
        box-shadow:0 12px 24px -8px rgba(15,23,42,0.20);
        max-height:300px; overflow-y:auto; padding:4px;
        z-index:9000; display:none;
    }
    .ent-suggest.open { display:block; }
    .ent-suggest-item { display:flex; flex-direction:row; align-items:baseline; gap:8px; padding:8px 12px; border-radius:6px; cursor:default; transition:background .12s; }
    .ent-suggest-item:hover, .ent-suggest-item.active { background:#e1effa; }
    /* Nº de parte del filtro que coincidió con lo buscado — gris, delante del nombre (como /admin/almacen). */
    .ent-suggest-item .parte { flex:0 0 auto; font-size:12.5px; font-weight:600; color:#475569; white-space:nowrap; }
    .ent-suggest-item .nom { font-size:13px; font-weight:600; color:#0f172a; flex:1 1 0; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .ent-suggest-item .um { flex:0 0 auto; font-size:11px; font-weight:800; color:var(--maquinaria-blue,#0067b1); text-transform:uppercase; letter-spacing:.3px; }
    .ent-suggest-item .um::before { content:'·'; margin-right:6px; color:#cbd5e0; font-weight:400; }
    .ent-suggest-empty { padding:10px 12px; font-size:12.5px; color:#94a3b8; font-style:italic; }

    /* Stepper de cantidad */
    .ent-cant-stepper { display:inline-flex; align-items:stretch; border:1px solid #cbd5e0; border-radius:10px; overflow:hidden; background:#fff; height:40px; }
    .ent-cant-stepper:focus-within { border-color:var(--maquinaria-blue,#0067b1); box-shadow:0 0 0 2px rgba(0,103,177,0.18); }
    .ent-cant-input { flex:1 1 0; min-width:0; width:auto; height:100%; border:none; background:transparent; text-align:center; font-size:13.5px; font-weight:400; color:#0f172a; outline:none; padding:0; }

    /* Barra de captura + tabla como pieza unificada */
    .ent-list-wrap { border:1px solid #e2e8f0; border-top:none; border-radius:0 0 12px 12px; overflow:hidden; }
    .ent-capt-bar {
        display:flex; align-items:center; gap:10px; flex-wrap:wrap;
        padding:10px 14px; background:#f1f5f9;
        border:1px solid #e2e8f0; border-radius:12px 12px 0 0;
    }
    .ent-capt-bar .ent-search-field { flex:1 1 200px; }
    .ent-capt-bar .ent-um-wrap      { flex:0 1 150px; }
    .ent-capt-bar .ent-cant-stepper { flex:0 1 130px; }
    .ent-capt-add-btn {
        flex:0 0 auto; width:40px; height:40px; border-radius:10px; border:none; cursor:default;
        background:var(--maquinaria-blue,#0067b1); color:#fff;
        display:flex; align-items:center; justify-content:center;
        transition:background .15s, transform .1s;
    }
    .ent-capt-add-btn:hover { background:#005391; }
    .ent-capt-add-btn:active { transform:scale(0.96); }
    .ent-capt-add-btn .material-icons { font-size:20px; }

    /* Tabla de líneas */
    .ent-list-table { width:100%; border-collapse:separate; border-spacing:0; font-size:14px; color:#000; }
    .ent-list-table thead tr { background:#1e293b; }
    .ent-list-table thead th { text-align:left; color:#fff; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:1px; padding:10px 15px; border-right:1px solid #334155; border-bottom:2px solid #0f172a; white-space:nowrap; }
    .ent-list-table thead th:last-child { border-right:none; }
    .ent-list-table thead th.col-num    { width:48px; text-align:center; }
    .ent-list-table thead th.col-codigo { width:140px; }
    .ent-list-table thead th.col-cant   { text-align:center; width:170px; }
    .ent-list-table thead th.col-del    { width:60px; text-align:center; }
    .ent-list-table tbody .col-num      { text-align:center; font-weight:700; color:#64748b; font-size:13px; }
    .ent-list-table tbody .col-codigo   { font-size:12.5px; font-weight:800; color:#0f172a; letter-spacing:.3px; white-space:nowrap; }
    .ent-list-table tbody td { padding:11px 15px; color:#000; border-bottom:1px solid #e2e8f0; border-right:1px solid #e2e8f0; vertical-align:middle; }
    .ent-list-table tbody td:last-child { border-right:none; }
    .ent-list-table tbody tr:hover td { background:#e0f2fe; }
    .ent-list-table tbody .col-cant { text-align:center; font-weight:700; font-size:13.5px; }
    .ent-list-table tbody .col-del  { text-align:center; }
    .ent-list-nom { font-size:13.5px; font-weight:600; color:#0f172a; display:block; }
    .ent-list-meta { font-size:11px; color:#94a3b8; }
    .ent-row-del-btn { background:none; border:none; cursor:default; color:#dc2626; padding:4px; border-radius:6px; transition:background .12s; }
    .ent-row-del-btn:hover { background:#fee2e2; }

    /* Responsive mobile — en estilos_globales.css scopeado con body:has(.ent-layout). */

    /* ── Layout 2 columnas: formulario + tabla (izq) · resumen (der) ──
       Mismo patrón que la bandeja de recepción (.tr-layout + aside.tr-stats). */
    .ent-layout { display:flex; gap:14px; align-items:flex-start; max-width:100%; }
    .ent-main { flex:1 1 0; min-width:0; }

    /* Panel lateral del lote — tarjeta BLANCA (mismo look que .ent-card y que el
       resto de la app). Sticky para seguir visible al capturar muchas líneas.
       Ya solo contiene las acciones (el bloque de métricas se eliminó a pedido). */
    .ent-summary {
        flex:0 0 300px; align-self:flex-start; position:sticky; top:14px;
        background:#fff; border:1px solid #e2e8f0;
        border-radius:14px; padding:18px; color:#0f172a;
        box-shadow:0 4px 12px rgba(15,23,42,0.04);
        display:flex; flex-direction:column; gap:14px;
    }
    /* Acciones del lote: usan los botones globales del formulario (btn-primary-maquinaria
       azul + btn-secondary blanco con borde azul, igual que /admin/usuarios/edit), pero a
       todo el ancho del panel y apilados. */
    .ent-summary-actions { display:flex; flex-direction:column; gap:10px; }
    /* Botones más bajos que el global (12px 24px): aquí van apilados y a todo el ancho. */
    .ent-summary-actions .btn-primary-maquinaria { width:100%; justify-content:center; cursor:default; padding:8px 18px; }

</style>

<div class="ent-layout">
<div class="ent-main">
<div class="ent-card">
    <input type="hidden" id="entAlmacen" value="{{ $almacenDestino->ID_ALMACEN }}">

    {{-- Cabecera del lote: N° Doc | Proveedor | Fecha. Los 3 bloques son hijos
         DIRECTOS del grid. Las acciones (Cancelar / Registrar) viven ahora en el
         panel lateral de la derecha. --}}
    {{-- Proyecto dueño del stock. Solo en almacenes que reparten el saldo entre varios proyectos;
         en el resto no hay nada que elegir (todo va a la bolsa comun) y la franja no se pinta.
         Va ARRIBA de la cabecera del lote porque no es un dato del documento: define a que
         bolsa entra TODO lo que se capture debajo. Obligatorio — el backend lo exige igual
         (AlmacenController::registrarMovimientoLote). --}}
    @if($separaProyectos ?? false)
    <div class="ent-proyecto-row">
        <label class="ent-field-label" for="entProyecto">Proyecto dueño del stock <span style="color:#dc2626;">*</span></label>
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

    <div class="ent-form-grid">
        <div class="ent-field-group">
            <label class="ent-field-label" for="entNotaEntrega">Nota de entrega</label>
            <input type="text" id="entNotaEntrega" class="ent-input" maxlength="100" placeholder="Opcional" autocomplete="off">
        </div>
        <div class="ent-field-group">
            <label class="ent-field-label" for="entProveedor">Proveedor</label>
            <input type="text" id="entProveedor" class="ent-input" maxlength="200" placeholder="Razón social o nombre" autocomplete="off">
        </div>
        <div class="ent-field-group">
            <label class="ent-field-label" for="entFecha">Fecha</label>
            <div class="ent-input" style="display:flex;align-items:center;cursor:default;"
                 onclick="var i=document.getElementById('entFecha'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                <input type="date" id="entFecha" style="flex:1;min-width:0;height:100%;border:none;background:transparent;padding:0;font-size:13px;outline:none;color:#0f172a;cursor:default;">
            </div>
        </div>
    </div>

    {{-- Sección de captura: título + barra + tabla --}}
    <div class="ent-section-title" style="margin-top:20px;margin-bottom:0;">
        Líneas de entrada
    </div>
    <div class="ent-capt-bar">
        <div class="ent-search-field">
            <input type="text" id="entSearch" class="ent-search-input" autocomplete="off"
                   placeholder="Buscar por código o descripción…"
                   oninput="window.entSuggest()" onfocus="window.entSuggest()" onkeydown="window.entSearchKey(event)">
            <div id="entSelectedBadge" class="ent-selected-badge">
                <span class="cod" id="entSelectedCod"></span>
                <span id="entSelectedNom"></span>
                <i class="material-icons clear" onclick="window.entClearSelected()" title="Cambiar producto">close</i>
            </div>
            <div id="entSuggest" class="ent-suggest"></div>
        </div>
        <div class="ent-um-wrap" title="Unidad de medida">
            <input type="text" id="entUm" class="ent-um-input" value="UND"
                   maxlength="20" autocomplete="off" aria-label="Unidad de medida" placeholder="UND"
                   oninput="window.entUmSuggest()" onfocus="window.entUmSuggest(true)" onkeydown="window.entUmKey(event)">
            <div id="entUmSuggest" class="ent-um-suggest"></div>
        </div>
        <div class="ent-cant-stepper" title="Cantidad (Enter agrega)">
            <input type="text" inputmode="decimal" enterkeyhint="done" id="entCant" class="ent-cant-input"
                   placeholder="Cant." autocomplete="off" onkeydown="window.entCantKey(event)">
        </div>
        <button type="button" class="ent-capt-add-btn" onclick="window.entAgregar()" title="Agregar línea">
            <i class="material-icons">add</i>
        </button>
    </div>

    <div class="ent-list-wrap">
        <table class="ent-list-table">
            <thead>
                <tr>
                    <th class="col-num">Nº</th>
                    <th class="col-codigo">Código</th>
                    <th>Descripción</th>
                    <th class="col-cant">Cantidad</th>
                    <th class="col-del"></th>
                </tr>
            </thead>
            <tbody id="entLineasTbody"></tbody>
        </table>
    </div>

    <div id="entError" style="display:none;margin-top:12px;padding:10px 14px;background:#fee2e2;border:1px solid #fecaca;border-radius:10px;color:#b91c1c;font-size:13.5px;font-weight:600;"></div>
</div>{{-- /.ent-card --}}
</div>{{-- /.ent-main --}}

{{-- Panel lateral del lote: solo las acciones (Registrar / Cancelar), estilo checkout.
     El cliente pidió quitar el bloque "Resumen de la entrada" (título + métricas
     Líneas/Unidades) tanto en móvil como en PC. --}}
<aside class="ent-summary" aria-label="Acciones de la entrada">
    <div class="ent-summary-actions">
        <button type="button" class="btn-primary-maquinaria" id="entSubmit" onclick="window.entGuardar()">
            <i class="material-icons">check_circle</i> Registrar entrada
        </button>
        <button type="button" class="btn-primary-maquinaria btn-secondary" onclick="window.entCancelar()">
            Cancelar
        </button>
    </div>
</aside>
</div>{{-- /.ent-layout --}}

<script>
(function () {
    'use strict';
    if (!document.getElementById('entLineasTbody')) return;

    var ROUTE_ENTRADA = @json(route('almacen.movimientos.lote'));
    var ROUTE_PROD    = @json(route('almacen.productos.store'));
    // Catálogo de productos: antes se embebía inline (los 1155 productos) y la recepción abría
    // pesada. Ahora arranca vacío y se carga por AJAX (endpoint compartido, misma fuente
    // listaAutocomplete) al renderizar, sin bloquear. El buscador lo usa por EVENTO, así que es
    // seguro. PRODUCTOS sigue siendo `var` porque la creación de producto le agrega entradas al
    // vuelo; esa sync solo escribe si el catálogo ya cargó (PRODUCTOS_CARGADOS) para no perderlas.
    var PRODUCTOS = [];
    var PRODUCTOS_CARGADOS = false;
    (function () {
        fetch(@json(route('almacen.productos-autocomplete')), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(function (lista) { if (Array.isArray(lista)) { PRODUCTOS = lista; PRODUCTOS_CARGADOS = true; } })
        .catch(function () { /* silencioso: el buscador tipear+servidor no depende de esta lista */ });
    })();
    // UMs distintas ya registradas en el catalogo — sirven de sugerencias para el
    // autocomplete del campo UM. El usuario puede tipear una UM nueva libremente.
    var UNIDADES_MEDIDA = @json($unidadesMedida ?? []);
    // Proyecto al que se atribuye la entrada.
    //   SEPARA_PROYECTOS=true  → el almacen reparte el saldo entre varios proyectos: el
    //     usuario elige cual recibe en el desplegable #entProyecto (obligatorio).
    //   SEPARA_PROYECTOS=false → no hay nada que elegir; ID_FRENTE_DESTINO lleva el frente
    //     implicito solo para que el kardex muestre "Destino" con nombre en vez de "—".
    //     Null cuando el almacen no tiene frentes: el backend cae a su logica habitual y el
    //     partial del kardex usa el nombre del almacen como fallback (fix b6c326b).
    var SEPARA_PROYECTOS  = @json($separaProyectos ?? false);
    var ID_FRENTE_DESTINO = @json($idFrenteDestino ?? null);

    // Proyecto elegido, o undefined si el almacen separa y todavia no se eligio.
    function entFrenteElegido() {
        if (!SEPARA_PROYECTOS) return ID_FRENTE_DESTINO;
        var s = el('entProyecto');
        var n = s ? parseInt(s.value, 10) : NaN;
        return (isFinite(n) && n > 0) ? n : undefined;
    }
    window.entProyectoCambio = function () {
        var s = el('entProyecto'); if (!s) return;
        s.classList.toggle('falta', !s.value);
        if (s.value) showErr('');
    };

    function el(id) { return document.getElementById(id); }
    function v(id)  { var e = el(id); return e ? String(e.value).trim() : ''; }
    function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.getAttribute('content') : ''; }
    function toast(msg, type) { if (window.showToast) window.showToast(msg, type || 'success'); else if (type === 'error') alert(msg); }
    function escHtml(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
    function showErr(msg) {
        var e = el('entError'); if (!e) return;
        if (msg) { e.style.display = 'block'; e.textContent = msg; }
        else     { e.style.display = 'none';  e.textContent = ''; }
    }
    // Normalizacion (tildes + mayusculas): delega en el modulo compartido FuzzySearch
    // (public/js/maquinaria/fuzzy_search.js, cargado global en el layout base).
    function norm(s) { return window.FuzzySearch.norm(s); }

    // ── Estado ────────────────────────────────────────────────────────────
    // entLineas: array de {id_producto, codigo, nombre, um, cantidad}. Lo que
    // el usuario ya "agrego" arriba — se renderiza en la tabla #entLineasTbody
    // y se envia tal cual en el submit. No tocamos el DOM mas que para pintar.
    var entLineas = [];
    // entSelected: producto actualmente elegido del autocomplete (esperando
    // cantidad). null si no hay nada elegido.
    var entSelected = null;
    // Flag para evitar dobles POST al storeProducto endpoint mientras una
    // creacion al vuelo esta en curso.
    var entCreandoProducto = false;

    // ── Autocomplete de producto ─────────────────────────────────────────
    // Filtra PRODUCTOS por match de CODIGO o NOMBRE contra el termino tipeado.
    // Sin endpoint AJAX — todo en cliente.
    // Flag para suprimir UNA sola llamada a entSuggest — usado cuando devolvemos
    // foco al buscador programaticamente despues de agregar una linea (Enter en
    // Cantidad). Sin esto el `onfocus="entSuggest()"` reabriria la lista de
    // sugerencias inmediatamente, confundiendo el flujo del usuario.
    var entSkipNextSuggest = false;

    window.entSuggest = function () {
        if (entSkipNextSuggest) { entSkipNextSuggest = false; var b0 = el('entSuggest'); if (b0) b0.classList.remove('open'); return; }
        var inp = el('entSearch'); if (!inp) return;
        var box = el('entSuggest'); if (!box) return;
        // Si ya hay un producto seleccionado, no mostrar sugerencias — el usuario
        // primero debe quitar la seleccion (X del badge) para volver a buscar.
        if (entSelected) { box.classList.remove('open'); return; }
        var rawTerm = inp.value.trim();
        // Ranking robusto compartido (window.FuzzySearch, layout base). NO se deduplica
        // por nombre: cada presentación (misma descripción, distinta UM) es un producto
        // válido aparte que el usuario debe poder elegir. Límite 12.
        // haystack incluye EQUIV (números de parte equivalentes) para que teclear un nº de
        // parte alterno de un FILTRO lo encuentre, igual que el buscador de /admin/almacen.
        var matches = window.FuzzySearch.rank(PRODUCTOS, rawTerm, function (p) {
            return { haystack: (p.CODIGO || '') + ' ' + (p.NOMBRE || '') + ' ' + (p.EQUIV || ''), label: p.NOMBRE || '' };
        }).slice(0, 12);
        if (matches.length === 0) {
            // Sin coincidencias → invitar a registrar el producto nuevo. El usuario
            // puede igual presionar Enter en Cantidad y se crea al vuelo (ver
            // entAgregar). Este hint visual evita el mensaje viejo "no existe" que
            // bloqueaba el flujo.
            var txt = inp.value.trim();
            box.innerHTML = txt
                ? '<div class="ent-suggest-empty">Sin coincidencias. Escribe la cantidad y presiona Enter para registrar <strong>"' + escHtml(txt) + '"</strong> como producto nuevo.</div>'
                : '<div class="ent-suggest-empty">Empieza a escribir para buscar.</div>';
        } else {
            box.innerHTML = matches.map(function (p) {
                var cod  = escHtml(p.CODIGO);
                var nom  = escHtml(p.NOMBRE);
                var um   = escHtml(p.UM);
                var cat  = escHtml(p.CATEGORIA || '');
                // Filtros: mostrar el nº de PARTE que COINCIDE con lo tecleado (o el principal),
                // como en /admin/almacen — helper compartido de FuzzySearch (una sola fuente).
                var parteMostrar = window.FuzzySearch.matchedPart(rawTerm, p.PARTES, p.PARTE);
                var partePrefix = parteMostrar ? '<span class="parte">' + escHtml(parteMostrar) + '</span>' : '';
                // Sugerencia: nº de parte (si es filtro) + NOMBRE + UM (sin codigo — pedido cliente).
                // La UM se pinta como tag al final porque un mismo material puede existir en varias
                // presentaciones (mismo nombre, distinta UM) — sin la UM serian indistinguibles.
                // data-cod queda en el elemento (no visible) porque entPick lo usa para el badge
                // del producto seleccionado; data-um prefija el campo UM; data-cat para que una
                // presentacion nueva herede la categoria del original.
                return '<div class="ent-suggest-item" data-id="' + p.ID_PRODUCTO + '" data-cod="' + cod + '" data-nom="' + nom + '" data-um="' + um + '" data-cat="' + cat + '">'
                    +    partePrefix + '<span class="nom">' + nom + '</span>'
                    +    (um ? '<span class="um">' + um + '</span>' : '')
                    +  '</div>';
            }).join('');
        }
        box.classList.add('open');
    };
    function entSuggestHide() { var b = el('entSuggest'); if (b) b.classList.remove('open'); }

    // Elegir sugerencia: pinta el badge, oculta el dropdown, salta a Cantidad.
    // Prefija el input de UM con la UM del producto pero lo deja EDITABLE — si el
    // usuario la cambia, entAgregar registra una presentacion aparte (ver caso 1).
    function entPick(item) {
        entSelected = {
            id_producto: parseInt(item.getAttribute('data-id'), 10),
            codigo:      item.getAttribute('data-cod') || '',
            nombre:      item.getAttribute('data-nom') || '',
            um:          item.getAttribute('data-um') || '',
            categoria:   item.getAttribute('data-cat') || '',
        };
        var badge = el('entSelectedBadge');
        el('entSelectedCod').textContent = entSelected.codigo;
        // Solo CODIGO · NOMBRE en el badge. La UM se muestra (y eventualmente se edita)
        // en el input `#entUm` que tenemos al lado — duplicarla aqui era ruido visual.
        el('entSelectedNom').textContent = ' · ' + entSelected.nombre;
        var inp = el('entSearch');
        // Ocultamos el input y mostramos el badge encima — UX clara de "ya elegiste".
        inp.value = '';
        inp.style.display = 'none';
        badge.classList.add('show');
        entSuggestHide();

        // Prefijar el input de UM con la UM del producto, pero DEJARLO EDITABLE: un
        // mismo material puede entrar en distintas presentaciones (p.ej. UND vs CAJA
        // vs BARRIL). Si el usuario cambia la UM a una distinta de la del catalogo,
        // entAgregar lo trata como una presentacion NUEVA — registra/reusa un producto
        // con el MISMO nombre y la nueva UM, sin tocar el original (ver entAgregar caso 1).
        var umInp = el('entUm');
        if (umInp && entSelected.um) {
            umInp.value = entSelected.um;
            entUmHide();
        }

        // Saltar a cantidad para captura rapida: codigo → enter → cantidad → enter.
        setTimeout(function () { var c = el('entCant'); if (c) c.focus(); }, 30);
    }
    // El parametro (true) suprime el siguiente entSuggest para que el dropdown NO
    // se abra solo al refocar el buscador. Lo usan entInsertarLinea (tras agregar
    // una linea) y entLimpiarTodo (al vaciar el borrador en cancelar o tras un
    // registro exitoso): el refoco del buscador es automatico, no una intencion
    // de "ver sugerencias".
    window.entClearSelected = function (suppressSuggest) {
        entSelected = null;
        el('entSelectedBadge').classList.remove('show');
        var inp = el('entSearch'); inp.style.display = ''; inp.value = '';
        // Resetear el input de UM para la siguiente captura (queda en UND como default
        // — el usuario lo cambia si registra un producto nuevo o una presentacion distinta).
        var umInp = el('entUm');
        if (umInp) umInp.value = 'UND';
        if (suppressSuggest) entSkipNextSuggest = true;
        inp.focus();
    };

    // ── Autocomplete del campo UM (Unidad de Medida) ──────────────────────
    // Sugiere las UMs distintas YA presentes en el catalogo de productos. Permite
    // tipear una UM nueva libremente (queda guardada al crear el producto). Mismo
    // patron que el modal "Nuevo producto" de /admin/almacen.
    function entUmHide() {
        var b = el('entUmSuggest'); if (b) b.classList.remove('open');
    }
    window.entUmSuggest = function (forceAll) {
        var inp = el('entUm'), box = el('entUmSuggest');
        if (!inp || !box) return;
        var term = norm(inp.value.trim());
        var matches = [];
        for (var i = 0; i < UNIDADES_MEDIDA.length; i++) {
            var u = UNIDADES_MEDIDA[i];
            if (forceAll || term === '' || norm(u).indexOf(term) !== -1) {
                matches.push(u);
                if (matches.length >= 20) break;
            }
        }
        if (matches.length === 0) {
            box.innerHTML = '<div class="ent-um-suggest-empty">Sin coincidencias. La UM se guardará tal cual la escribiste.</div>';
        } else {
            box.innerHTML = matches.map(function (u) {
                return '<div class="ent-um-suggest-item" data-um="' + escHtml(u) + '">' + escHtml(u) + '</div>';
            }).join('');
        }
        box.classList.add('open');
    };
    window.entUmKey = function (ev) {
        if (ev.key === 'Escape') { entUmHide(); return; }
        if (ev.key === 'Enter') {
            ev.preventDefault();
            // Si hay una sugerencia ACTIVA, la toma; sino conserva lo tipeado.
            var first = document.querySelector('#entUmSuggest .ent-um-suggest-item');
            var inp = el('entUm');
            if (first && inp) inp.value = first.getAttribute('data-um') || inp.value;
            entUmHide();
            // Saltar a cantidad — pipeline rapido para producto nuevo.
            var c = el('entCant'); if (c) c.focus();
        }
    };

    // Click en sugerencia → pick. Click fuera → cerrar dropdown.
    // SPA: navegacion.js re-ejecuta los <script> inline en cada visita, así que este listener
    // de `document` se registraría otra vez por cada navegación a esta página.
    //
    // No basta con una bandera "registrar sólo la primera vez": estos handlers llaman a
    // entPick/entSuggestHide, que son funciones LOCALES y mutan el estado de SU corrida
    // (entLineas / entSelected). El listener de la primera visita seguiría escribiendo en el
    // array viejo mientras window.entAgregar lee el nuevo. Por eso REEMPLAZAMOS el listener:
    // se quita el de la corrida anterior y se registra el de ésta. Siempre hay exactamente
    // uno, y apunta al closure vivo.
    if (window.__entDocClick) document.removeEventListener('click', window.__entDocClick);
    window.__entDocClick = function (e) {
        var item = e.target.closest('#entSuggest .ent-suggest-item');
        if (item) { e.preventDefault(); entPick(item); return; }
        if (!e.target.closest('.ent-search-field')) entSuggestHide();
        // Autocomplete UM
        var umItem = e.target.closest('#entUmSuggest .ent-um-suggest-item');
        if (umItem) {
            e.preventDefault();
            var inp = el('entUm');
            if (inp) inp.value = umItem.getAttribute('data-um') || '';
            entUmHide();
            return;
        }
        if (!e.target.closest('.ent-um-wrap')) entUmHide();
    };
    document.addEventListener('click', window.__entDocClick);
    // Teclas en el input search: Esc cierra; Enter elige la PRIMERA sugerencia.
    window.entSearchKey = function (ev) {
        if (ev.key === 'Escape') { ev.preventDefault(); entSuggestHide(); return; }
        if (ev.key === 'Enter') {
            ev.preventDefault();
            var first = document.querySelector('#entSuggest .ent-suggest-item');
            if (first) entPick(first);
        }
    };
    // ── Agregar / quitar lineas ──────────────────────────────────────────
    // Enter en cantidad = mismo gesto que click en Agregar — pipeline rapido.
    // El input es type=text (no number) para mantener compatibilidad de estilo con
    // el stepper de /admin/almacen — bloqueamos teclas no numericas en el handler.
    window.entCantKey = function (ev) {
        // Bloquear letras/signos: solo digitos, punto, coma, backspace, navegacion.
        var allowed = ['Backspace','Delete','Tab','ArrowLeft','ArrowRight','Home','End'];
        if (allowed.indexOf(ev.key) !== -1) return;
        if (ev.key === 'Enter') { ev.preventDefault(); window.entAgregar(); return; }
        // ArrowUp/Down = stepper ±1
        if (ev.key === 'ArrowUp')   { ev.preventDefault(); window.entCantStep(1);  return; }
        if (ev.key === 'ArrowDown') { ev.preventDefault(); window.entCantStep(-1); return; }
        // Acepta digito o un solo separador decimal.
        if (/^[0-9]$/.test(ev.key)) return;
        if ((ev.key === '.' || ev.key === ',') && ev.target.value.indexOf('.') === -1 && ev.target.value.indexOf(',') === -1) return;
        // Cualquier otra tecla → bloquear (sin Ctrl/Cmd combos para copy/paste — esos pasan).
        if (!ev.ctrlKey && !ev.metaKey) ev.preventDefault();
    };
    // Botones ▲▼ del stepper: suman/restan 1 al valor. Si el campo esta vacio o
    // no es numerico, arrancan en 1. El minimo es 0 (el campo queda en blanco).
    window.entCantStep = function (dir) {
        var inp = el('entCant'); if (!inp) return;
        var raw = String(inp.value || '').replace(',', '.').trim();
        var n = parseFloat(raw);
        if (!isFinite(n)) n = 0;
        n = Math.max(0, n + (dir > 0 ? 1 : -1));
        inp.value = n > 0 ? String(n) : '';
        inp.focus();
    };
    // Inserta una linea ya resuelta (con id_producto valido) en entLineas.
    // Si el producto ya estaba, suma la cantidad en vez de duplicar la fila
    // — UX clasica de capturas tipo POS.
    function entInsertarLinea(prod, cant) {
        var existing = entLineas.find(function (l) { return l.id_producto === prod.id_producto; });
        if (existing) {
            existing.cantidad = +(existing.cantidad + cant).toFixed(3);
        } else {
            entLineas.push({
                id_producto: prod.id_producto,
                codigo:      prod.codigo,
                nombre:      prod.nombre,
                um:          prod.um,
                cantidad:    cant,
            });
        }
        entRender();
        // Limpiar inputs y devolver foco al buscador — siguiente producto. El flag
        // `true` suprime la auto-apertura del dropdown (Enter en Cantidad agrega
        // la linea; no es una intencion de "ver mas sugerencias").
        window.entClearSelected(true);
        el('entCant').value = '';
    }

    // POST al endpoint almacen.productos.store con el UM elegido por el usuario.
    // La UM ya viene del select #entUm en la barra de captura (entAgregar la lee y
    // la pasa). Antes esto se hacia via un mini-modal aparte — removido por
    // redundancia ahora que UM esta in-line en la barra de captura.
    // Al volver con el id real del backend, lo insertamos a entLineas como una
    // linea mas y al catalogo en memoria (PRODUCTOS) para que aparezca en
    // busquedas posteriores sin recargar la pagina.
    function entDoCreateProducto(nombre, cant, um, categoria) {
        // El catalogo guarda NOMBRE y UM en MAYUSCULAS (backend: validarProducto hace
        // mb_strtoupper). Normalizamos aca tambien para que el payload, el catalogo en
        // memoria, la linea de la tabla y el toast queden en mayusculas — incluido el
        // camino de respaldo `|| nombre` si la respuesta no trajera el valor.
        nombre = String(nombre || '').trim().toUpperCase();
        um     = String(um || 'UND').trim().toUpperCase() || 'UND';
        // categoria: solo se usa cuando la presentacion nueva nace de un producto del
        // catalogo (cambiar la UM) — hereda la categoria del original. Para un producto
        // tecleado desde cero (caso 2) llega vacio y el producto queda sin categoria.
        categoria = String(categoria || '').trim();
        // NO usamos el preloader de pantalla completa aca: crear el producto al vuelo es
        // una operacion inline rapida (agregar una linea), y el overlay full-screen se
        // veia como una "recarga" de pagina. El preloader queda reservado para el submit
        // final (entGuardar / boton "Entrada"). El guard entCreandoProducto ya
        // evita el doble-POST mientras la creacion esta en curso.
        entCreandoProducto = true;
        // IMPORTANTE: mandamos id_almacen aunque NO haya cantidad inicial — el backend
        // (AlmacenController::storeProducto) llama a asegurarStock() que crea la fila
        // almacen_stock con CANTIDAD=0 si no existia. Sin esto, el producto creado al
        // vuelo quedaba en el catalogo pero INVISIBLE en /admin/almacen (autocomplete
        // filtra por productosEnAlmacen) y en la tabla del inventario (INNER JOIN con
        // almacen_stock). cantidad_inicial=0 explicito para que el backend no dispare
        // el check de permiso almacen.movimiento (que solo aplica cuando cant > 0).
        var idAlmacenForm = v('entAlmacen');
        var body = { NOMBRE: nombre, UM: um };
        if (categoria) body.CATEGORIA = categoria;
        if (idAlmacenForm) {
            body.id_almacen      = parseInt(idAlmacenForm, 10);
            body.cantidad_inicial = 0;
        }
        fetch(ROUTE_PROD, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body),
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            entCreandoProducto = false;
            if (!res.ok) {
                var msg = (res.b && res.b.message) || 'No se pudo registrar el producto nuevo.';
                if (res.b && res.b.errors) msg = Object.values(res.b.errors).map(function (a) { return a.join(' '); }).join(' ');
                showErr(msg); toast(msg, 'error');
                var i = el('entSearch'); if (i) i.focus();
                return;
            }
            var p = res.b.producto || {};
            // Agregar al catalogo en memoria para que la proxima busqueda lo encuentre como
            // sugerencia normal sin recargar la pagina. Solo si el catalogo async ya cargo: si
            // no, la carga pendiente traera el producto fresco de la BD (ya se guardo) y este
            // push se perderia al reemplazar la lista.
            if (PRODUCTOS_CARGADOS) PRODUCTOS.push({
                ID_PRODUCTO: p.ID_PRODUCTO,
                CODIGO:      p.CODIGO || '',
                NOMBRE:      p.NOMBRE || nombre,
                UM:          p.UM || 'UND',
                CATEGORIA:   p.CATEGORIA || categoria || '',
            });
            entInsertarLinea({
                id_producto: p.ID_PRODUCTO,
                codigo:      p.CODIGO || '',
                nombre:      p.NOMBRE || nombre,
                um:          p.UM || 'UND',
            }, cant);
            toast('Producto nuevo registrado: ' + (p.CODIGO || '') + ' · ' + (p.NOMBRE || nombre));
        })
        .catch(function () {
            entCreandoProducto = false;
            var m = 'Error de red al registrar el producto.';
            showErr(m); toast(m, 'error');
        });
    }

    window.entAgregar = function () {
        // Guard anti doble-POST: si hay una creacion de producto al vuelo en curso
        // (entDoCreateProducto), ignoramos el trigger — sin esto, pulsar Enter dos
        // veces seguidas disparaba dos POST a almacen.productos.store. La bandera
        // entCreandoProducto se prende/apaga dentro de entDoCreateProducto.
        if (entCreandoProducto) return;
        showErr('');
        // Input ahora es type=text (stepper estilo /admin/almacen) — normaliza coma → punto.
        var raw = String(el('entCant').value || '').replace(',', '.').trim();
        var cant = parseFloat(raw);
        if (!isFinite(cant) || cant <= 0) {
            var m2 = 'Indica una cantidad mayor que cero.';
            showErr(m2); toast(m2, 'error');
            el('entCant').focus();
            return;
        }
        // Caso 1: el usuario eligio una sugerencia del catalogo.
        if (entSelected) {
            // La UM puede haberse cambiado a una presentacion distinta (UND→CAJA, etc.).
            // Comparamos la UM tipeada contra la del producto elegido:
            var umInp1 = el('entUm');
            var umNueva = umInp1 ? String(umInp1.value || '').trim().toUpperCase() : '';
            if (!umNueva) umNueva = entSelected.um;   // campo vacio → presentacion original
            // Misma UM (o equivalente sin tildes/case) → es el mismo producto, fluye normal.
            // Si el producto base no trae UM registrada (entSelected.um vacio) tampoco
            // ramificamos: no hay una UM "original" contra la cual comparar.
            if (!entSelected.um || norm(umNueva) === norm(entSelected.um)) {
                entInsertarLinea(entSelected, cant);
                return;
            }
            // UM distinta → es OTRA presentacion del mismo material. El producto original
            // queda intacto con su UM; esta entra como un producto aparte (mismo nombre,
            // nueva UM). Reusamos una presentacion ya existente en el catalogo si la hay
            // (mismo nombre + misma UM) para no duplicar; si no, se crea al vuelo.
            var existenteVariante = PRODUCTOS.find(function (p) {
                return norm(p.NOMBRE) === norm(entSelected.nombre) && norm(p.UM) === norm(umNueva);
            });
            if (existenteVariante) {
                entInsertarLinea({
                    id_producto: existenteVariante.ID_PRODUCTO,
                    codigo:      existenteVariante.CODIGO || '',
                    nombre:      existenteVariante.NOMBRE || entSelected.nombre,
                    um:          existenteVariante.UM || umNueva,
                }, cant);
                return;
            }
            // Categoria heredada: la del producto seleccionado SI la tiene; si no, la de
            // CUALQUIER presentacion con el MISMO nombre que tenga categoria (el producto
            // canonico/original). Asi la presentacion nueva no queda sin categoria por haber
            // elegido una presentacion que estaba en NULL.
            var catHeredada = (entSelected.categoria || '').trim();
            if (!catHeredada) {
                var conCat = PRODUCTOS.find(function (p) {
                    return norm(p.NOMBRE) === norm(entSelected.nombre) && p.CATEGORIA && String(p.CATEGORIA).trim();
                });
                if (conCat) catHeredada = String(conCat.CATEGORIA).trim();
            }
            entDoCreateProducto(entSelected.nombre, cant, umNueva, catHeredada);
            return;
        }
        // Caso 2: el usuario tipeo algo que no esta en el catalogo → registrar
        // producto nuevo al vuelo. La UM se toma del input #entUm (autocompletado,
        // permite tipear UM nueva). Reemplaza al mini-modal que antes pedia la UM
        // en un dialogo aparte — ahora la UM esta in-line en la barra de captura.
        var textoBuscador = String(el('entSearch').value || '').trim();
        if (textoBuscador.length >= 2) {
            // entDoCreateProducto normaliza nombre y UM a mayusculas (y UM vacia → UND).
            var umInp = el('entUm');
            entDoCreateProducto(textoBuscador, cant, umInp ? umInp.value : 'UND');
            return;
        }
        // Caso 3: ni hay seleccion ni texto util → pedir descripcion.
        var m1 = 'Escribe la descripción del producto o elige uno de la lista.';
        showErr(m1); toast(m1, 'error');
        var inp = el('entSearch'); if (inp) inp.focus();
    };
    window.entRemoverLinea = function (idx) {
        if (idx < 0 || idx >= entLineas.length) return;
        entLineas.splice(idx, 1);
        entRender();
    };
    function fmtCant(n) {
        // 3 decimales max, sin ceros redundantes. Formato latino: miles con punto, decimal con coma.
        var v = parseFloat(Number(n).toFixed(3));
        if (isNaN(v)) return '0';
        return v.toLocaleString('es-ES', { maximumFractionDigits: 3 });
    }
    function entRender() {
        var tb = el('entLineasTbody');
        if (!tb) return;
        // Tbody vacio cuando no hay lineas — sin mensaje "vacio". El thead da
        // contexto suficiente y el usuario sabe que tiene que capturar arriba.
        if (entLineas.length === 0) { tb.innerHTML = ''; return; }
        // Columnas: [Código] [Descripcion] [Cantidad + UM] [delete]. El codigo sale en
        // su propia columna (en negro, monospace); la columna "Descripcion" muestra
        // unicamente el nombre del producto para que se lea limpio sin el codigo encima.
        tb.innerHTML = entLineas.map(function (l, idx) {
            var num = (idx + 1);
            var numPad = (num < 10 ? '0' : '') + num;
            // data-num + data-codigo en el td de descripcion: el CSS mobile los
            // inyecta como prefijo via ::before para unificar "01 · 000042 NOMBRE"
            // en un solo banner gris (mismo patron que .alm-td-nombre en /admin/almacen).
            return '<tr data-idx="' + idx + '">'
                +   '<td class="col-num">' + num + '</td>'
                +   '<td class="col-codigo">' + escHtml(l.codigo) + '</td>'
                +   '<td class="col-desc" data-num="' + numPad + '" data-codigo="' + escHtml(l.codigo) + '"><span class="ent-list-nom">' + escHtml(l.nombre) + '</span></td>'
                +   '<td class="col-cant">' + escHtml(fmtCant(l.cantidad)) + ' <span class="ent-list-meta">' + escHtml(l.um) + '</span></td>'
                +   '<td class="col-del"><button type="button" class="ent-row-del-btn" onclick="window.entRemoverLinea(' + idx + ')" title="Quitar"><i class="material-icons" style="font-size:20px;">delete</i></button></td>'
                + '</tr>';
        }).join('');
    }

    // ── Submit ───────────────────────────────────────────────────────────
    window.entGuardar = function () {
        showErr('');
        var idAlm = v('entAlmacen');
        if (!idAlm) { var mAlm = 'No se pudo determinar el almacén destino. Recarga la página o avisa al administrador.'; showErr(mAlm); toast(mAlm, 'error'); return; }
        if (entLineas.length === 0) {
            var mLin = 'Agrega al menos un producto antes de registrar.';
            showErr(mLin); toast(mLin, 'error');
            var inp = el('entSearch'); if (inp) inp.focus();
            return;
        }
        // El proyecto define a que bolsa entra el material: sin el, el saldo terminaria en
        // un proyecto cualquiera. El backend lo rechaza igual; esto solo evita el viaje.
        if (entFrenteElegido() === undefined) {
            var mPro = 'Indica el proyecto que recibe el material.';
            showErr(mPro); toast(mPro, 'error');
            var sp = el('entProyecto'); if (sp) { sp.classList.add('falta'); sp.focus(); }
            return;
        }

        // Trazabilidad del lote — cada dato en SU columna del kardex (no apilados en
        // texto libre, así se ven limpios y se pueden filtrar/devolver):
        //   · Nota de entrega → `referencia` (REFERENCIA) — documento del proveedor.
        //   · Proveedor       → `motivo`     (MOTIVO)     — a quién devolver si hace falta.
        var payload = {
            tipo:       'ENTRADA',
            id_almacen: parseInt(idAlm, 10),
            // id_frente: el proyecto que recibe (si el almacen separa) o el frente implicito
            // del almacen (si no). Ver entFrenteElegido arriba.
            id_frente:  entFrenteElegido(),
            fecha:      v('entFecha') || null,
            referencia: v('entNotaEntrega')   || null,   // Nota de entrega (doc. del proveedor)
            motivo:     v('entProveedor')     || null,   // Proveedor (a quién devolver)
            lineas:     entLineas.map(function (l) {
                return { id_producto: l.id_producto, cantidad: l.cantidad };
            }),
        };

        if (window.showPreloader) window.showPreloader();
        var btn = el('entSubmit'); if (btn) btn.disabled = true;
        fetch(ROUTE_ENTRADA, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload),
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, status: r.status, b: b }; }); })
        .then(function (res) {
            // El boton NUNCA navega ni recarga la pagina: ocultamos el preloader,
            // reactivamos el boton y mostramos SOLO una notificacion.
            if (window.hidePreloader) window.hidePreloader();
            if (btn) btn.disabled = false;

            if (res.ok) {
                // Exito: vaciamos el borrador y nos quedamos en el modulo, listos
                // para la siguiente entrada (flujo tipo POS). Antes redirigia a la
                // bandeja; el cliente pidio que el boton solo notifique, sin navegar.
                entLimpiarTodo();
                toast((res.b && res.b.message) || 'Entrada registrada correctamente.', 'success');
                return;
            }

            // Error: nos quedamos en la pagina con la captura intacta.
            var msg = (res.b && res.b.message) || 'No se pudo registrar la entrada.';
            if (res.b && res.b.errors) {
                msg = Object.values(res.b.errors).map(function (a) { return a.join(' '); }).join(' ');
            }
            // 403 = falta la clave 'almacen.movimiento'. Se notifica con un toast
            // (la notificacion pequeña), NO un modal — el usuario se queda en el
            // modulo con su captura intacta. Sin caja de error inline porque no es
            // un error de un campo del formulario.
            if (res.status === 403 || (res.b && res.b.forbidden)) {
                showErr('');
                toast(msg, 'error');
                return;
            }
            showErr(msg); toast(msg, 'error');
        })
        .catch(function () {
            if (window.hidePreloader) window.hidePreloader();
            if (btn) btn.disabled = false;
            var m = 'Error de red al registrar la entrada.';
            showErr(m); toast(m, 'error');
        });
    };

    // ── Limpiar el borrador completo (sin navegar ni notificar) ──
    //
    // Vacia la tabla de lineas, la barra de captura (buscador/UM/cantidad) y la
    // cabecera (nota/proveedor/fecha). Lo reusan "Cancelar operacion" y el EXITO
    // de "Entrada": en ambos casos el modulo NO
    // navega ni recarga, solo deja el formulario en blanco para la siguiente
    // captura. No muestra notificacion — cada quien muestra la suya.
    function entLimpiarTodo() {
        entLineas = [];
        entRender();
        // Reset de la barra de captura (buscador + badge + UM). El `true` suprime
        // la auto-apertura del dropdown de sugerencias al refocar el buscador.
        window.entClearSelected(true);
        var c = el('entCant'); if (c) c.value = '';
        // Reset de la cabecera del lote. El proyecto tambien vuelve a vacio: la siguiente
        // entrada puede ser de otro frente y dejarlo pegado del anterior es justo el error
        // que este campo vino a evitar.
        ['entNotaEntrega', 'entProveedor'].forEach(function (id) {
            var e = el(id); if (e) e.value = '';
        });
        var pro = el('entProyecto'); if (pro) { pro.value = ''; pro.classList.add('falta'); }
        var fch = el('entFecha'); if (fch) fch.value = new Date().toISOString().slice(0, 10);
        showErr('');
    }

    // ── Cancelar operacion: vacia el borrador SIN salir del modulo ──
    window.entCancelar = function () {
        var hacer = function () {
            entLimpiarTodo();
            toast('Operación cancelada. La tabla quedó vacía.', 'success');
        };
        // Sin lineas → no hay captura que confirmar; limpiamos directo.
        if (entLineas.length === 0) { hacer(); return; }

        // Hay lineas capturadas → confirmar antes de vaciar (se pierde lo cargado).
        // Modal del sistema (window.showModal), con confirm() nativo de fallback
        // por si el helper global no estuviera cargado (carga parcial / SPA bug).
        var n = entLineas.length;
        var prodWord = (n === 1 ? 'producto' : 'productos');
        var mensaje  = 'Perderás <strong>' + n + ' ' + prodWord + '</strong> capturado' + (n === 1 ? '' : 's') + '. No se puede deshacer.';

        if (typeof window.showModal === 'function') {
            window.showModal({
                type:        'warning',
                title:       'Cancelar operación',
                message:     mensaje,
                confirmText: 'Aceptar',
                cancelText:  'Cancelar',
                onConfirm:   hacer,
            });
        } else {
            if (window.confirm(mensaje.replace(/<[^>]+>/g, ''))) hacer();
        }
    };

    // ── Init ─────────────────────────────────────────────────────────────
    var f = el('entFecha'); if (f && !f.value) f.value = new Date().toISOString().slice(0, 10);
    entRender();
    // Sin foco automatico al cargar — antes le daba foco al buscador y eso
    // disparaba `onfocus="window.entSuggest()"` haciendo que la lista de
    // sugerencias apareciera desplegada al entrar al modulo. El usuario hace
    // click cuando quiere empezar a buscar.

    // Esc global: cierra sugerencias antes de cualquier otra cosa.
    // Mismo motivo que el listener de click: se reemplaza en cada montaje SPA (llama a
    // entSuggestHide, que es local de esta corrida).
    if (window.__entDocKeydown) document.removeEventListener('keydown', window.__entDocKeydown);
    window.__entDocKeydown = function (e) {
        if (e.key === 'Escape') {
            var box = el('entSuggest');
            if (box && box.classList.contains('open')) { entSuggestHide(); return; }
        }
    };
    document.addEventListener('keydown', window.__entDocKeydown);
})();

// Móvil: el tamaño del teclado lo decide el teléfono (no se achica por web sin perder
// el punto decimal). Para que el teclado no tape lo que se edita, al enfocar el campo
// de cantidad lo subimos a la zona visible. Mismo criterio que /admin/almacen.
(function () {
    // Este handler NO captura estado de la corrida (solo mira e.target y el ancho de la
    // ventana), así que aquí sí basta registrarlo UNA vez por pestaña: si se apilara, un
    // solo focus dispararía N scrollIntoView.
    if (window.__entFocusinBound) return;
    window.__entFocusinBound = true;

    document.addEventListener('focusin', function (e) {
        var inp = e.target;
        if (!inp || !inp.classList || !inp.classList.contains('ent-cant-input')) return;
        // Solo en móvil: en PC no hay teclado que tape nada y centrar provocaría un
        // salto de scroll innecesario al enfocar el campo (block:'center' siempre centra).
        if (window.innerWidth > 768) return;
        setTimeout(function () {
            try { inp.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
            catch (_) { try { inp.scrollIntoView(); } catch (e2) {} }
        }, 300);
    });
})();
</script>
@endsection
