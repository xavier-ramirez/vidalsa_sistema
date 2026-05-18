@extends('layouts.estructura_base')

@section('title', 'Registrar entrada directa')

@section('content')
{{-- ────────────────────────────────────────────────────────────────
     Pantalla "Registrar entrada directa" — reemplaza al viejo modal
     #entModal de /admin/almacen/recepcion. Misma operacion (POST a
     almacen.movimientos.lote con tipo=ENTRADA) pero como pagina propia
     con autocomplete de producto por codigo o descripcion.

     Flujo de captura:
       1) Cabecera con datos del lote (almacen derivado + OC + proveedor + fecha + nota).
       2) Fila de captura: [Buscar serial/descripcion] [Cantidad] (stepper ▲▼).
          - Si el producto EXISTE: aparece como sugerencia → Enter elige el primero →
            escribir cantidad → Enter agrega a la tabla.
          - Si el producto NO existe: igual escribis la cantidad → Enter → el sistema
            crea el producto al vuelo (codigo auto PRD-####, UM=UND) y lo agrega a la
            tabla. Se puede editar despues desde /admin/almacen.
       3) Submit: POST de TODAS las lineas como un lote ENTRADA.
     ──────────────────────────────────────────────────────────────── --}}

<section class="page-title-card" style="text-align:left;margin:0 0 10px 0;">
    {{-- Layout calcado de /admin/almacen: titulo + separador vertical + pill del
         almacen destino (derivado del frente del usuario). El campo "Nota de
         entrega" vive en la fila de datos (.ent-head-row) al lado del Proveedor
         para mantener el header limpio. --}}
    <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
        <h1 class="page-title" style="margin:0;">
            <span class="page-title-line2" style="color:#000;">Registrar entrada directa</span>
        </h1>
        {{-- Separador vertical (se oculta en mobile). --}}
        <span aria-hidden="true" class="ent-header-sep" style="display:inline-block;width:1px;height:34px;background:#cbd5e0;flex:0 0 auto;"></span>
        {{-- Bloque "Almacén": mini-label + nombre del almacen destino (read-only;
             se deriva del frente del usuario en TraspasoController@nuevaEntrada). --}}
        <div class="ent-header-block" style="display:flex;align-items:center;gap:10px;flex:0 1 auto;">
            <span style="font-size:10.5px;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:1px;white-space:nowrap;">Almacén</span>
            <div class="ent-dest-pill" title="Almacén destino del usuario (derivado del frente asignado)">
                <span class="name">{{ $almacenDestino->NOMBRE }}{{ $almacenDestino->TIPO === 'GENERAL' ? '' : ' (Proyecto)' }}</span>
            </div>
        </div>
    </div>
</section>

<style>
    /* Cards y secciones */
    .ent-card     { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:14px 16px; box-shadow:0 4px 12px rgba(15,23,42,0.04); }
    .ent-section-title { margin:0 0 8px 0; font-size:13px; font-weight:800; color:#334155; text-transform:uppercase; letter-spacing:.4px; display:flex; align-items:center; gap:8px; }
    .ent-section-title i { font-size:16px; color:#0284c7; }

    /* Cabecera de "Datos de la entrada": Nota de entrega + Proveedor + Fecha +
       Observación + boton "En transito". La pill del Almacen se muestra en el
       page-title-card (al lado del titulo) — patron de /admin/almacen. Los 5
       items comparten 40px de alto. Sin <label> arriba (placeholder = titulo). */
    .ent-head-row { display:grid; grid-template-columns:1fr 1fr 160px 1fr auto; gap:10px; align-items:center; }
    @media (max-width: 1100px) { .ent-head-row { grid-template-columns:1fr 1fr 1fr; } }
    @media (max-width: 700px)  { .ent-head-row { grid-template-columns:1fr 1fr; } }
    @media (max-width: 480px)  { .ent-head-row { grid-template-columns:1fr; } }
    /* Boton "Envios en transito" dentro de la fila de datos — calibrado a la altura
       de los .ent-input (40px) para alinearse. En mobile (cuando el grid colapsa
       a 1fr) ocupa todo el ancho como cualquier otro item. */
    .ent-envios-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; height:40px; padding:0 16px; border-radius:10px; text-decoration:none; background:var(--maquinaria-blue,#0067b1); color:#fff; font-weight:700; font-size:13.5px; white-space:nowrap; box-shadow:0 4px 6px -1px rgba(0,103,177,0.18); transition:background .15s; }
    .ent-envios-btn:hover { background:#005391; }
    .ent-envios-btn i { font-size:20px; }
    /* Pill del almacen destino (read-only): vive en el page-title-card al lado
       del mini-label "Almacén". Solo muestra el NOMBRE — la etiqueta uppercase
       se renderiza por fuera (mismo patron que el dropdown de /admin/almacen). */
    .ent-dest-pill { display:inline-flex; align-items:center; height:40px; padding:0 14px; background:#f8fafc; border:1px solid #cbd5e0; border-radius:10px; white-space:nowrap; }
    .ent-dest-pill .name  { font-size:13.5px; color:#0f172a; font-weight:700; }
    .ent-input    { width:100%; height:40px; border:1px solid #cbd5e0; border-radius:10px; padding:0 12px; font-size:13.5px; background:#fbfcfd; outline:none; box-sizing:border-box; color:#0f172a; }
    .ent-input:focus { border-color:var(--maquinaria-blue,#0067b1); background:#fff; }
    .ent-input::placeholder { color:#64748b; opacity:1; }
    select.ent-input { cursor:pointer; }

    /* ── Fila de captura: [Buscar] [Cantidad] siempre LADO A LADO ─────────────
       El buscador es el campo dominante PERO con tope de 480px — antes era
       flex:1 1 0 (absorbia TODO el ancho restante) y dejaba a la Cantidad
       visualmente apretada / oculta atras del buscador a partir de cierto
       viewport. Ahora flex:0 1 480px lo deja crecer hasta 480px max y la
       Cantidad queda claramente visible a su derecha en todas las anchuras.
       En mobile (≤560px) el search se contrae naturalmente con el viewport. */
    .ent-capt-row { display:flex; flex-wrap:nowrap; align-items:flex-start; position:relative; }
    .ent-capt-row > .ent-search-field { flex:1 1 480px; min-width:0; max-width:480px; margin-right:12px; }
    .ent-capt-row > .ent-cant-stepper { flex:0 0 auto; }

    /* Wrapper del buscador: altura fija 42px (= altura del stepper) y
       position:relative para anclar tanto el badge de seleccion como las
       sugerencias en absolute. */
    .ent-search-field { position:relative; height:42px; }
    .ent-search-input { width:100%; height:42px; border:1px solid #cbd5e0; border-radius:10px; padding:0 12px 0 38px; font-size:13.5px; background:#fff url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="%2364748b" viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>') no-repeat 12px center; outline:none; color:#0f172a; }
    .ent-search-input:focus { border-color:var(--maquinaria-blue,#0067b1); }
    .ent-search-input:disabled { background-color:#f1f5f9; cursor:not-allowed; }
    /* Badge de producto seleccionado: SE SUPERPONE al input via position:absolute
       (inset:0 = cubre todo el .ent-search-field). Antes era inline-flex normal y
       el input quedaba visible "atras" del badge en algunos browsers — con absolute
       el badge tapa por completo el area del buscador. z-index>input para garantizarlo. */
    .ent-selected-badge { display:none; position:absolute; inset:0; z-index:2; align-items:center; gap:6px; padding:0 12px; background:#e1effa; border:1px solid #93c5fd; border-radius:10px; color:#0067b1; font-size:13px; font-weight:700; white-space:nowrap; overflow:hidden; box-sizing:border-box; }
    .ent-selected-badge.show { display:flex; }
    .ent-selected-badge .cod { font-family:monospace; font-size:11.5px; font-weight:800; }
    .ent-selected-badge .clear { cursor:pointer; color:#475569; margin-left:auto; font-size:18px; }
    .ent-selected-badge .clear:hover { color:#dc2626; }

    .ent-suggest {
        position:absolute; top:calc(100% + 4px); left:0; right:0;
        background:#fff; border:1px solid #e2e8f0; border-radius:10px;
        box-shadow:0 12px 24px -8px rgba(15,23,42,0.20);
        max-height:300px; overflow-y:auto; padding:4px;
        z-index:60; display:none;
    }
    .ent-suggest.open { display:block; }
    .ent-suggest-item { display:flex; flex-direction:column; gap:1px; padding:8px 12px; border-radius:6px; cursor:pointer; transition:background .12s; }
    .ent-suggest-item:hover, .ent-suggest-item.active { background:#e1effa; }
    .ent-suggest-item .cod { font-family:monospace; font-size:11.5px; font-weight:700; color:#0067b1; letter-spacing:.3px; }
    .ent-suggest-item .nom { font-size:13px; font-weight:600; color:#0f172a; }
    .ent-suggest-empty { padding:10px 12px; font-size:12.5px; color:#94a3b8; font-style:italic; }

    /* Stepper de cantidad — clon del .alm-cant-stepper de /admin/almacen (variante
       "is-active"). En el modulo origen tiene height:30px porque vive dentro de una
       celda de tabla apretada; aqui sube a 42px para igualar la altura del input
       de busqueda — quedan visualmente alineados. Border-radius 10px para coincidir
       con los otros campos. */
    .ent-cant-stepper { display:inline-flex; align-items:stretch; border:1px solid #cbd5e0; border-radius:10px; overflow:hidden; background:#fff; height:42px; }
    .ent-cant-stepper:focus-within { border-color:var(--maquinaria-blue,#0067b1); box-shadow:0 0 0 2px rgba(0,103,177,0.18); }
    .ent-cant-input { width:90px; height:100%; border:none; background:transparent; text-align:center; font-size:14px; font-weight:700; color:#0f172a; outline:none; padding:0; }
    .ent-cant-btns { display:flex; flex-direction:column; border-left:1px solid #cbd5e0; width:24px; }
    .ent-cant-btn  { flex:1; border:none; background:#fff; color:#0067b1; font-weight:800; font-size:12px; line-height:1; cursor:pointer; padding:0; }
    .ent-cant-btn:first-child { border-bottom:1px solid #cbd5e0; }
    .ent-cant-btn:hover { background:#e0f2fe; }
    /* ── Tabla de productos agregados — estilo clon de .alm-table de /admin/almacen ── */
    .ent-list-wrap { margin-top:14px; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; }
    .ent-list-table { width:100%; border-collapse:separate; border-spacing:0; font-size:14px; color:#000; }
    .ent-list-table thead tr { background:#1e293b; }
    .ent-list-table thead th { text-align:left; color:#fff; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:1px; padding:10px 15px; border-right:1px solid #334155; border-bottom:2px solid #0f172a; white-space:nowrap; }
    .ent-list-table thead th:last-child { border-right:none; }
    .ent-list-table thead th.col-num    { width:48px; text-align:center; }
    .ent-list-table thead th.col-codigo { width:140px; }
    .ent-list-table thead th.col-cant   { text-align:right; width:170px; }
    .ent-list-table thead th.col-del    { width:60px; text-align:center; }
    .ent-list-table tbody .col-num      { text-align:center; font-weight:700; color:#64748b; font-size:13px; }
    /* Codigo del producto en la tabla: negro (no azul). Se mantiene monospace +
       letter-spacing para que los codigos auto-generados (PRD-0042) se lean alineados. */
    .ent-list-table tbody .col-codigo   { font-family:monospace; font-size:12.5px; font-weight:800; color:#0f172a; letter-spacing:.3px; white-space:nowrap; }
    .ent-list-table tbody td { padding:11px 15px; color:#000; border-bottom:1px solid #e2e8f0; border-right:1px solid #e2e8f0; vertical-align:middle; }
    .ent-list-table tbody td:last-child { border-right:none; }
    .ent-list-table tbody tr:hover td { background:#e0f2fe; }
    .ent-list-table tbody .col-cant { text-align:right; font-weight:700; font-family:monospace; font-size:13.5px; }
    .ent-list-table tbody .col-del  { text-align:center; }
    .ent-list-nom { font-size:13.5px; font-weight:600; color:#0f172a; display:block; }
    .ent-list-meta { font-size:11px; color:#94a3b8; }
    .ent-row-del-btn { background:none; border:none; cursor:pointer; color:#dc2626; padding:4px; border-radius:6px; transition:background .12s; }
    .ent-row-del-btn:hover { background:#fee2e2; }

    /* Botones del footer */
    .ent-footer-bar { display:flex; justify-content:flex-end; gap:10px; margin-top:18px; padding-top:14px; border-top:1px solid #e2e8f0; }

    /* ── Responsive mobile (≤768px) — patron calcado de /admin/almacen ──
       Titulo OCULTO, separadores OCULTOS, bloques de header (Almacen + Nota
       de entrega) apilados full-width. La pill del almacen y el input de la
       nota se expanden al 100% para ocupar todo el ancho del telefono. */
    @media (max-width: 768px) {
        .page-title-card .page-title { display: none !important; }
        .page-title-card .ent-header-sep { display: none !important; }
        .page-title-card > div { flex-direction: column !important; align-items: stretch !important; gap: 10px !important; }
        .page-title-card .ent-header-block { width: 100% !important; flex: 1 1 100% !important; }
        /* Almacen pill: ocupa lo que sobre del mini-label "Almacén" */
        .page-title-card .ent-header-block .ent-dest-pill { flex: 1 1 0; min-width: 0; max-width: 100%; overflow: hidden; }
        .page-title-card .ent-header-block .ent-dest-pill .name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    }
</style>

<div class="ent-card" style="margin-bottom:14px;">
    <h3 class="ent-section-title"><i class="material-icons">tune</i>Datos de la entrada</h3>
    {{-- Almacén destino: derivado del frente del usuario (TraspasoController@nuevaEntrada);
         se muestra como pill en el page-title-card (arriba), aqui solo guardamos el id
         en un <input hidden> para que el submit lo envie al backend. Los campos
         de abajo llevan su titulo DENTRO del placeholder — sin <label> arriba — para
         comprimir vertical. --}}
    <input type="hidden" id="entAlmacen" value="{{ $almacenDestino->ID_ALMACEN }}">
    <div class="ent-head-row">
        <input type="text" id="entNotaEntrega" class="ent-input" maxlength="100" placeholder="Nota de entrega (opcional)">
        <input type="text" id="entProveedor" class="ent-input" maxlength="200" placeholder="Proveedor (opcional)">
        {{-- Wrapper de fecha: clic en CUALQUIER parte abre el picker via showPicker()
             (antes solo respondia el iconito nativo al extremo derecho). Mismo patron
             que /admin/almacen/movimientos y /admin/almacen/recepcion (Filtros Avanzados). --}}
        <div class="ent-input" style="display:flex;align-items:center;cursor:pointer;"
             onclick="var i=document.getElementById('entFecha'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }"
             title="Fecha (default hoy)">
            <i class="material-icons" style="font-size:16px;color:#94a3b8;margin-right:6px;pointer-events:none;">event</i>
            <input type="date" id="entFecha" style="flex:1;min-width:0;height:100%;border:none;background:transparent;padding:0;font-size:13.5px;outline:none;color:#0f172a;cursor:pointer;">
        </div>
        <input type="text" id="entNotas" class="ent-input" maxlength="500" placeholder="Observación">
        {{-- Atajo a la BANDEJA de envios en transito. ?force=1 esquiva el redirect
             del controller que manda al GLOBAL a /recepcion/nueva por default. --}}
        <a href="{{ route('almacen.recepcion.index', ['force' => 1]) }}"
           class="ent-envios-btn"
           title="Ver los envíos en tránsito hacia otros almacenes pendientes de confirmación">
            <i class="material-icons">local_shipping</i>
            <span>En tránsito</span>
        </a>
    </div>
</div>

<div class="ent-card">
    {{-- Fila de captura: [Buscar serial/desc] [Cantidad] siempre lado a lado.
         Flujo: tipea codigo o descripcion → elige sugerencia (queda como
         badge azul) → escribe cantidad → Enter → la linea cae a la tabla y el
         foco vuelve al buscador. Si el producto NO existe en el catalogo, al
         apretar Enter se crea automaticamente (codigo auto, UM=UND) y la linea
         igual se agrega — sin friccion. --}}
    <div class="ent-capt-row">
        <div class="ent-search-field">
            <input type="text" id="entSearch" class="ent-search-input" autocomplete="off"
                   placeholder="Buscar por código (serial) o descripción…"
                   oninput="window.entSuggest()" onfocus="window.entSuggest()" onkeydown="window.entSearchKey(event)">
            <div id="entSelectedBadge" class="ent-selected-badge">
                <span class="cod" id="entSelectedCod"></span>
                <span id="entSelectedNom"></span>
                <i class="material-icons clear" onclick="window.entClearSelected()" title="Cambiar producto">close</i>
            </div>
            <div id="entSuggest" class="ent-suggest"></div>
        </div>
        <div class="ent-cant-stepper" title="Cantidad (Enter agrega)">
            <input type="text" inputmode="decimal" id="entCant" class="ent-cant-input"
                   placeholder="0" autocomplete="off"
                   onkeydown="window.entCantKey(event)">
            <div class="ent-cant-btns">
                <button type="button" class="ent-cant-btn" onclick="window.entCantStep(1)" tabindex="-1" title="+1">▲</button>
                <button type="button" class="ent-cant-btn" onclick="window.entCantStep(-1)" tabindex="-1" title="−1">▼</button>
            </div>
        </div>
    </div>

    {{-- Tabla de productos ya agregados — estilo clon de .alm-table del modulo
         /admin/almacen para que el usuario reconozca el lenguaje visual. --}}
    <div class="ent-list-wrap">
        <table class="ent-list-table">
            <thead>
                <tr>
                    <th class="col-num">Nº</th>
                    <th class="col-codigo">Código</th>
                    <th>Descripción del producto</th>
                    <th class="col-cant">Cantidad</th>
                    <th class="col-del"></th>
                </tr>
            </thead>
            <tbody id="entLineasTbody"></tbody>
        </table>
    </div>

    <div id="entError" style="display:none;margin-top:12px;padding:10px 14px;background:#fee2e2;border:1px solid #fecaca;border-radius:10px;color:#b91c1c;font-size:13.5px;font-weight:600;"></div>

    <div class="ent-footer-bar">
        <button type="button" class="btn-primary-maquinaria" id="entSubmit" onclick="window.entGuardar()" style="height:42px;padding:0 22px;display:inline-flex;align-items:center;gap:6px;">
            <i class="material-icons" style="font-size:18px;">save</i> Registrar entrada
        </button>
    </div>
</div>

<script>
(function () {
    'use strict';
    if (!document.getElementById('entLineasTbody')) return;

    var ROUTE_ENTRADA = @json(route('almacen.movimientos.lote'));
    var ROUTE_PROD    = @json(route('almacen.productos.store'));
    var ROUTE_BACK    = @json(route('almacen.recepcion.index'));
    // PRODUCTOS no es `const` porque se agrega al vuelo cuando el usuario crea
    // un producto que no estaba en el catalogo — asi la proxima busqueda lo
    // encuentra como una sugerencia normal sin recargar la pagina.
    var PRODUCTOS     = @json($productosLista ?? []);

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
    // Normalizacion para que la busqueda sea tolerante a tildes y mayusculas.
    var _DIACRITICS_RE = new RegExp('[\\u0300-\\u036f]', 'g');
    function norm(s) { return String(s || '').normalize('NFD').replace(_DIACRITICS_RE, '').toLowerCase(); }

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
    window.entSuggest = function () {
        var inp = el('entSearch'); if (!inp) return;
        var box = el('entSuggest'); if (!box) return;
        // Si ya hay un producto seleccionado, no mostrar sugerencias — el usuario
        // primero debe quitar la seleccion (X del badge) para volver a buscar.
        if (entSelected) { box.classList.remove('open'); return; }
        var term = norm(inp.value.trim());
        var matches = [];
        for (var i = 0; i < PRODUCTOS.length; i++) {
            var p = PRODUCTOS[i];
            var ok = term === '' ||
                     norm(p.CODIGO).indexOf(term) !== -1 ||
                     norm(p.NOMBRE).indexOf(term) !== -1;
            if (!ok) continue;
            matches.push(p);
            if (matches.length >= 12) break;
        }
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
                // Sugerencia: solo CODIGO + NOMBRE. La UM no se pinta (aparece
                // en la tabla de productos agregados). `data-um` queda en el
                // elemento porque entPick lo necesita para armar la fila.
                return '<div class="ent-suggest-item" data-id="' + p.ID_PRODUCTO + '" data-cod="' + cod + '" data-nom="' + nom + '" data-um="' + um + '">'
                    +    '<span class="cod">' + cod + '</span>'
                    +    '<span class="nom">' + nom + '</span>'
                    +  '</div>';
            }).join('');
        }
        box.classList.add('open');
    };
    function entSuggestHide() { var b = el('entSuggest'); if (b) b.classList.remove('open'); }

    // Elegir sugerencia: pinta el badge, oculta el dropdown, salta a Cantidad.
    function entPick(item) {
        entSelected = {
            id_producto: parseInt(item.getAttribute('data-id'), 10),
            codigo:      item.getAttribute('data-cod') || '',
            nombre:      item.getAttribute('data-nom') || '',
            um:          item.getAttribute('data-um') || '',
        };
        var badge = el('entSelectedBadge');
        el('entSelectedCod').textContent = entSelected.codigo;
        el('entSelectedNom').textContent = ' · ' + entSelected.nombre + ' (' + entSelected.um + ')';
        var inp = el('entSearch');
        // Ocultamos el input y mostramos el badge encima — UX clara de "ya elegiste".
        inp.value = '';
        inp.style.display = 'none';
        badge.classList.add('show');
        entSuggestHide();
        // Saltar a cantidad para captura rapida: codigo → enter → cantidad → enter.
        setTimeout(function () { var c = el('entCant'); if (c) c.focus(); }, 30);
    }
    window.entClearSelected = function () {
        entSelected = null;
        el('entSelectedBadge').classList.remove('show');
        var inp = el('entSearch'); inp.style.display = ''; inp.value = ''; inp.focus();
    };

    // Click en sugerencia → pick. Click fuera → cerrar dropdown.
    document.addEventListener('click', function (e) {
        var item = e.target.closest('#entSuggest .ent-suggest-item');
        if (item) { e.preventDefault(); entPick(item); return; }
        if (!e.target.closest('.ent-search-field')) entSuggestHide();
    });
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
        if (allowed.indexOf(ev.key) !== -1) {
            if (ev.key === 'Enter') { ev.preventDefault(); window.entAgregar(); }
            return;
        }
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
    // no es numerico, arrancan en 1. No bajan de 0.001 (igual minimo que antes).
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
        // Limpiar inputs y devolver foco al buscador — siguiente producto.
        window.entClearSelected();
        el('entCant').value = '';
    }

    // Crea un producto nuevo al vuelo via almacen.productos.store (codigo
    // auto-generado tipo PRD-####, UM=UND por defecto). Al volver con el id
    // real del backend, lo insertamos a entLineas como una linea mas y al
    // catalogo en memoria (PRODUCTOS) para que aparezca en busquedas
    // posteriores sin recargar.
    function entCrearProductoYAgregar(nombre, cant) {
        if (entCreandoProducto) return;
        entCreandoProducto = true;
        if (window.showPreloader) window.showPreloader();
        fetch(ROUTE_PROD, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ NOMBRE: nombre, UM: 'UND' }),
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            if (window.hidePreloader) window.hidePreloader();
            entCreandoProducto = false;
            if (!res.ok) {
                var msg = (res.b && res.b.message) || 'No se pudo registrar el producto nuevo.';
                if (res.b && res.b.errors) msg = Object.values(res.b.errors).map(function (a) { return a.join(' '); }).join(' ');
                showErr(msg); toast(msg, 'error');
                var i = el('entSearch'); if (i) i.focus();
                return;
            }
            var p = res.b.producto || {};
            // Agregar al catalogo en memoria para que la proxima busqueda lo encuentre
            // como sugerencia normal sin recargar la pagina.
            PRODUCTOS.push({
                ID_PRODUCTO: p.ID_PRODUCTO,
                CODIGO:      p.CODIGO || '',
                NOMBRE:      p.NOMBRE || nombre,
                UM:          p.UM || 'UND',
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
            if (window.hidePreloader) window.hidePreloader();
            entCreandoProducto = false;
            var m = 'Error de red al registrar el producto.';
            showErr(m); toast(m, 'error');
        });
    }

    window.entAgregar = function () {
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
        // Caso 1: el usuario eligio una sugerencia → producto del catalogo, fluye normal.
        if (entSelected) {
            entInsertarLinea(entSelected, cant);
            return;
        }
        // Caso 2: el usuario tipeo algo que no esta en el catalogo → registrar
        // producto nuevo al vuelo (codigo auto, UM=UND) y agregar la linea.
        var textoBuscador = String(el('entSearch').value || '').trim();
        if (textoBuscador.length >= 2) {
            entCrearProductoYAgregar(textoBuscador, cant);
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
        // 3 decimales max, sin ceros redundantes.
        return String(parseFloat(Number(n).toFixed(3)));
    }
    function entRender() {
        var tb = el('entLineasTbody'); if (!tb) return;
        // Tbody vacio cuando no hay lineas — sin mensaje "vacio". El thead da
        // contexto suficiente y el usuario sabe que tiene que capturar arriba.
        if (entLineas.length === 0) { tb.innerHTML = ''; return; }
        // Columnas: [Código] [Descripcion] [Cantidad + UM] [delete]. El codigo sale en
        // su propia columna (en negro, monospace); la columna "Descripcion" muestra
        // unicamente el nombre del producto para que se lea limpio sin el codigo encima.
        tb.innerHTML = entLineas.map(function (l, idx) {
            return '<tr data-idx="' + idx + '">'
                +   '<td class="col-num">' + (idx + 1) + '</td>'
                +   '<td class="col-codigo">' + escHtml(l.codigo) + '</td>'
                +   '<td><span class="ent-list-nom">' + escHtml(l.nombre) + '</span></td>'
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

        // El backend acepta `referencia` (Nº OC), `motivo` (proveedor) y `notas`.
        // Nº de Nota de Entrega externa: lo concatenamos a `notas` con un prefijo
        // claro — el endpoint no tiene columna dedicada y notas es texto libre.
        var notasBase = v('entNotas');
        var notaEntrega = v('entNotaEntrega');
        var notasFinal = '';
        if (notaEntrega) notasFinal += 'Nota de entrega: ' + notaEntrega;
        if (notasBase)   notasFinal += (notasFinal ? '\n' : '') + notasBase;

        var payload = {
            tipo:       'ENTRADA',
            id_almacen: parseInt(idAlm, 10),
            fecha:      v('entFecha') || null,
            motivo:     v('entProveedor') || null,   // Proveedor
            notas:      notasFinal || null,
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
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            if (window.hidePreloader) window.hidePreloader();
            if (res.ok) {
                toast(res.b.message || 'Entrada registrada.', 'success');
                setTimeout(function () { window.location = ROUTE_BACK; }, 600);
            } else {
                if (btn) btn.disabled = false;
                var msg = (res.b && res.b.message) || 'No se pudo registrar la entrada.';
                if (res.b && res.b.errors) msg = Object.values(res.b.errors).map(function (a) { return a.join(' '); }).join(' ');
                showErr(msg); toast(msg, 'error');
            }
        })
        .catch(function () {
            if (window.hidePreloader) window.hidePreloader();
            if (btn) btn.disabled = false;
            var m = 'Error de red al registrar la entrada.';
            showErr(m); toast(m, 'error');
        });
    };

    // ── Init ─────────────────────────────────────────────────────────────
    var f = el('entFecha'); if (f && !f.value) f.value = new Date().toISOString().slice(0, 10);
    entRender();
    // Sin foco automatico al cargar — antes le daba foco al buscador y eso
    // disparaba `onfocus="window.entSuggest()"` haciendo que la lista de
    // sugerencias apareciera desplegada al entrar al modulo. El usuario hace
    // click cuando quiere empezar a buscar.

    // Esc global: cierra sugerencias antes de cualquier otra cosa.
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var box = el('entSuggest');
            if (box && box.classList.contains('open')) { entSuggestHide(); return; }
        }
    });
})();
</script>
@endsection
