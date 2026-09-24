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

<style>
    /* ── Entrada por ODC — dos columnas: a la izquierda la barra de captura y debajo
       la tabla de líneas; a la derecha el panel, como el "Resumen del pedido" de una tienda
       en línea (resumen de la entrada y los botones). Mismos campos y controles que el modal
       "Entrada por compra directa" de la bandeja (.cdir-*). Hasta 900px va todo en una
       columna (la regla base de .ent-layout): captura, lista y el panel al final; lo fino del
       teléfono (≤768px) vive en estilos_globales.css, scopeado con body:has(.ent-layout). ── */
    .ent-layout { display:flex; flex-direction:column; gap:14px; max-width:100%; }
    .ent-main { display:flex; flex-direction:column; gap:14px; min-width:0; }
    .ent-side { min-width:0; }
    /* Tarjeta blanca: la misma .admin-card de la tabla de la bandeja. */
    .ent-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 4px 30px rgba(0,0,0,0.05); }

    /* Barra de captura, encima de la tabla. Sin overflow:hidden y por encima de la
       tarjeta de la tabla (z-index), para que el desplegable del buscador se abra sobre ella. */
    .ent-capt-card { position:relative; z-index:7; padding:16px 18px; }
    /* Panel: el resumen de la entrada y, separada por una línea, la botonera. */
    .ent-panel-sec { padding:16px 20px 8px; }

    /* Resumen de la entrada: título grande y centrado (letra oscura, ícono azul de marca),
       filas etiqueta / valor separadas por una línea, y el total de productos distintos más
       grande al final (como el "Total" de un resumen de pedido). */
    .ent-res-title { display:flex; align-items:center; justify-content:center; gap:8px; margin-bottom:10px;
        font-size:17px; font-weight:800; color:#0f172a; }
    .ent-res-title .material-icons { font-size:22px; color:#0067b1; }
    .ent-res-row { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:10px 0;
        border-bottom:1px solid #f1f5f9; font-size:14px; color:#0f172a; }
    .ent-res-row:last-child { border-bottom:none; }
    .ent-res-lbl { flex:0 0 auto; font-weight:600; color:#1e293b; }
    .ent-res-um { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:5px; min-width:0; }
    .ent-res-chip { display:inline-flex; align-items:baseline; gap:3px; padding:2px 8px; border-radius:999px;
        background:#f1f5f9; font-weight:800; font-size:12.5px; }
    .ent-res-chip small { font-size:10px; font-weight:700; color:#475569; text-transform:uppercase; }
    .ent-res-total { font-size:16px; font-weight:800; }
    .ent-res-total .ent-res-lbl { font-weight:800; color:#0f172a; }

    .ent-field-group { display:flex; flex-direction:column; gap:4px; }
    .ent-field-label { font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.4px; }
    /* "(Opcional)" junto al rótulo: sin mayúsculas sostenidas y más claro, para que no
       compita con él. */
    .ent-opc { font-weight:600; text-transform:none; letter-spacing:0; color:#94a3b8; }
    /* Asociar material a un proyecto: primer campo del modal, con la etiqueta encima. */
    .ent-proyecto-row { display:grid; gap:4px; }
    .ent-proyecto-row select { width:100%; font-family:inherit; }
    /* Sin elegir → borde rojo suave. No es un error todavia (nadie intento registrar aun),
       solo la senal de que falta ese dato. Se apaga en cuanto se elige. */
    .ent-proyecto-row select.falta { border-color:#f87171; background:#fef2f2; }

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


    .ent-input { width:100%; min-width:0; height:38px; border:1px solid #cbd5e0; border-radius:10px; padding:0 12px; font-size:13.5px; background:#fbfcfd; outline:none; box-sizing:border-box; color:#0f172a; font-family:inherit; }
    .ent-input:focus { border-color:var(--maquinaria-blue,#0067b1); background:#fff; }
    .ent-input::placeholder { color:#94a3b8; opacity:1; }
    select.ent-input { cursor:default; }

    /* ── Barra de captura: en una fila [buscador] [UM] [cantidad] [✓], como la barra de
       búsqueda de la bandeja. En teléfono, dos filas: el buscador arriba y debajo
       [UM] [cantidad] [✓] repartidos. ── */
    .ent-capt { position:relative; z-index:5; display:flex; flex-direction:column; gap:8px; }
    .ent-capt-row { display:flex; align-items:center; gap:8px; }
    .ent-capt-row .ent-um-wrap      { flex:1 1 0; min-width:0; }
    .ent-capt-row .ent-cant-stepper { flex:1 1 0; min-width:0; }
    /* El buscador no baja de 300px: si la columna es angosta (laptops chicas), [UM]
       [cantidad] [✓] pasan al renglón de abajo, a la derecha. */
    @media (min-width: 769px) {
        .ent-capt { flex-direction:row; flex-wrap:wrap; align-items:center; }
        .ent-capt .ent-search-field     { flex:1 1 300px; min-width:0; }
        .ent-capt-row                   { flex:0 0 auto; margin-left:auto; }
        .ent-capt-row .ent-um-wrap      { flex:0 0 110px; }
        .ent-capt-row .ent-cant-stepper { flex:0 0 110px; }
    }

    /* Buscador de producto */
    .ent-search-field { position:relative; height:38px; }
    .ent-search-lupa { position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#64748b; font-size:18px; pointer-events:none; }
    .ent-search-input { width:100%; box-sizing:border-box; height:38px; border:1px solid #cbd5e0; border-radius:10px; padding:0 12px 0 34px; font-size:13.5px; font-family:inherit; background:#fbfcfd; outline:none; color:#0f172a; }
    .ent-search-input:focus { border-color:var(--maquinaria-blue,#0067b1); background:#fff; }
    .ent-search-input:disabled { background-color:#f1f5f9; cursor:not-allowed; }
    /* Producto ya elegido: el chip tapa el buscador (y su lupa). Mismo aspecto que el del
       modal: azul claro con el código en negrita. */
    .ent-selected-badge { display:none; position:absolute; inset:0; z-index:2; align-items:center; gap:6px; padding:0 10px; background:#e1effa; border:1px solid #0067b1; border-radius:10px; color:#0f172a; font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; box-sizing:border-box; }
    .ent-selected-badge.show { display:flex; }
    .ent-selected-badge .cod { font-weight:800; }
    .ent-selected-badge .clear { cursor:default; color:#64748b; margin-left:auto; font-size:17px; }
    .ent-selected-badge .clear:hover { color:#dc2626; }

    .ent-suggest {
        position:absolute; top:calc(100% + 5px); left:0; right:0;
        background:#fff; border:1px solid #e2e8f0; border-radius:12px;
        box-shadow:0 10px 25px rgba(0,0,0,0.1);
        max-height:300px; overflow-y:auto; padding:5px;
        z-index:9000; display:none;
    }
    .ent-suggest.open { display:block; }
    /* Escritorio: al menos 560px, abriéndose también por encima de UND y cantidad, para que
       las descripciones largas se lean enteras. */
    @media (min-width: 769px) { .ent-suggest { right:auto; width:max(100%, 560px); box-sizing:border-box; } }
    .ent-suggest-item { display:flex; flex-direction:row; align-items:baseline; gap:8px; padding:8px 10px; border-radius:8px; cursor:default; transition:background .12s; }
    .ent-suggest-item:hover, .ent-suggest-item.active { background:#e1effa; }
    /* Nº de parte del filtro que coincidió con lo buscado — gris, delante del nombre (como /admin/almacen). */
    .ent-suggest-item .parte { flex:0 0 auto; font-size:12.5px; font-weight:600; color:#475569; white-space:nowrap; }
    .ent-suggest-item .nom { font-size:13px; font-weight:600; color:#0f172a; flex:1 1 0; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .ent-suggest-item .um { flex:0 0 auto; font-size:11px; font-weight:800; color:var(--maquinaria-blue,#0067b1); text-transform:uppercase; letter-spacing:.3px; }
    .ent-suggest-item .um::before { content:'·'; margin-right:6px; color:#cbd5e0; font-weight:400; }
    .ent-suggest-empty { padding:10px; font-size:12.5px; color:#64748b; line-height:1.45; }

    /* UM autocomplete. Peso normal, a pedido del cliente: la "UND" en negrita se veía
       resaltada respecto al resto del panel de captura. */
    .ent-um-wrap { position:relative; }
    .ent-um-input {
        width:100%; height:38px; border:1px solid #cbd5e0; border-radius:10px;
        padding:0 8px; font-size:13px; font-weight:400; color:#0f172a; font-family:inherit;
        background:#fbfcfd; outline:none; box-sizing:border-box; text-transform:uppercase; text-align:center;
    }
    .ent-um-input:focus { border-color:var(--maquinaria-blue,#0067b1); background:#fff; }
    /* Anclado a la DERECHA y más ancho que el campo: a 90px el aviso de "sin coincidencias"
       quedaba en una columna de una palabra por renglón. */
    .ent-um-suggest {
        position:absolute; top:calc(100% + 5px); right:0; min-width:210px;
        background:#fff; border:1px solid #e2e8f0; border-radius:12px;
        box-shadow:0 10px 25px rgba(0,0,0,0.1);
        max-height:240px; overflow-y:auto; padding:5px;
        z-index:9000; display:none;
    }
    .ent-um-suggest.open { display:block; }
    .ent-um-suggest-item { padding:7px 10px; border-radius:8px; cursor:default; font-size:12.5px; font-weight:600; color:#0f172a; }
    .ent-um-suggest-item:hover, .ent-um-suggest-item.active { background:#e1effa; }
    .ent-um-suggest-empty { padding:8px 10px; font-size:11.5px; color:#64748b; }

    /* Cantidad */
    .ent-cant-stepper { display:flex; align-items:stretch; border:1px solid #cbd5e0; border-radius:10px; overflow:hidden; background:#fbfcfd; height:38px; box-sizing:border-box; }
    .ent-cant-stepper:focus-within { border-color:var(--maquinaria-blue,#0067b1); background:#fff; }
    .ent-cant-input { flex:1 1 0; min-width:0; width:auto; height:100%; border:none; background:transparent; text-align:center; font-size:13.5px; font-weight:700; font-family:inherit; color:#0f172a; outline:none; padding:0; }

    /* Agregar línea: el botón verde y REDONDO del modal —"confirmar esta línea"—; el verde
       lo separa del azul de los botones del panel. Enter hace lo mismo. */
    .ent-add-btn { flex:0 0 38px; width:38px; height:38px; display:flex; align-items:center; justify-content:center;
        border:1px solid #16a34a; border-radius:50%; background:#16a34a; color:#fff; cursor:default; padding:0;
        box-shadow:0 3px 8px rgba(22,163,74,0.3); transition:transform .12s ease, background .15s ease; }
    .ent-add-btn:hover { background:#15803d; }
    .ent-add-btn:active { transform:scale(0.94); }
    .ent-add-btn .material-icons { font-size:20px; }

    /* Tabla de líneas: tarjeta blanca con la tabla de la bandeja (.tr-table): encabezado
       oscuro con divisores entre columnas, filas cebra y el mismo realce al pasar. El fondo
       del recuadro es gris azulado muy suave y las filas blancas: vacío no es un bloque
       blanco de pantalla completa. overflow:auto (no hidden): recorta las esquinas igual y, si
       la tabla no cabe, se desplaza de lado en vez de perder la papelera. */
    .ent-list-wrap { padding:14px; }
    .ent-list-box { border:1px solid #e2e8f0; border-radius:10px; overflow:auto; background:#f8fafc; }
    .ent-list-table { width:100%; border-collapse:separate; border-spacing:0; font-size:14px; color:#0f172a; }
    .ent-list-table thead th { background:#1e293b; color:#fff; font-size:12.5px; font-weight:700; text-transform:uppercase; letter-spacing:1px;
        padding:10px 12px; text-align:center; white-space:nowrap; border-right:1px solid #334155; border-bottom:2px solid #0f172a; }
    .ent-list-table thead th:last-child { border-right:none; }
    /* Anchos de contenido (el padding de 12px por lado va aparte): lo que sobra es para la
       descripción. */
    .ent-list-table thead th.col-num    { width:36px; }
    .ent-list-table thead th.col-codigo { width:84px; }
    .ent-list-table thead th.col-desc   { text-align:left; }
    .ent-list-table thead th.col-cant   { width:110px; }
    .ent-list-table thead th.col-del    { width:28px; }
    .ent-list-table tbody td { padding:11px 12px; background:#fff; border-bottom:1px solid #e2e8f0; border-right:1px solid #e2e8f0; vertical-align:middle; }
    .ent-list-table tbody td:last-child { border-right:none; }
    .ent-list-table tbody tr:nth-child(even) td { background:#fafbfc; }
    .ent-list-table tbody tr:hover td { background:#e0f2fe; }
    .ent-list-table tbody .col-num      { text-align:center; font-weight:800; color:#94a3b8; font-size:13px; }
    .ent-list-table tbody .col-codigo   { text-align:center; font-weight:700; white-space:nowrap; }
    .ent-list-table tbody .col-cant     { text-align:center; font-weight:800; }
    .ent-list-table tbody .col-del      { text-align:center; }
    .ent-list-nom { font-weight:600; display:block; }
    .ent-list-meta { font-size:10.5px; font-weight:700; color:#0f172a; }
    .ent-row-del-btn { background:none; border:none; cursor:default; color:#cbd5e0; padding:2px; display:inline-flex; }
    .ent-row-del-btn:hover { color:#ef4444; }
    /* Sin líneas todavía: el aviso del modal, con un ícono que le da peso al hueco. */
    .ent-empty { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px;
        padding:30px 16px; text-align:center; font-size:13px; color:#64748b; }
    /* El display:flex de arriba le ganaría al atributo hidden (entRender lo pone en cuanto
       hay una línea) y el aviso seguiría debajo de la lista. */
    .ent-empty[hidden] { display:none; }
    .ent-empty-ic { display:flex; align-items:center; justify-content:center; width:64px; height:64px; border-radius:50%;
        background:#e1effa; color:#0067b1; box-shadow:0 0 0 8px #f1f7fd; }
    .ent-empty-ic .material-icons { font-size:30px; }

    .ent-error { display:none; margin-top:12px; padding:10px 14px; background:#fee2e2; border:1px solid #fecaca; border-radius:10px; color:#b91c1c; font-size:13px; font-weight:600; }

    /* Botonera del panel: Registrar entrada y, debajo, Cancelar, a todo el ancho (como el
       "Ir a pagar" de una tienda en línea). */
    .ent-foot { display:flex; flex-direction:column; gap:8px; padding:14px 20px 18px; border-top:1px solid #e2e8f0; }
    /* Los dos del MISMO alto: lo que distingue a Registrar es el color, no el tamaño. Letra
       algo más grande que la de la barra de captura: son los botones que cierran la entrada. */
    .ent-foot .ent-btn { min-width:0; height:46px; font-size:16px; }
    .ent-foot .ent-btn .material-icons { font-size:20px; }
    .ent-foot .ent-btn-ok { box-shadow:0 4px 10px rgba(0,103,177,0.25); }
    .ent-btn { height:38px; min-width:130px; padding:0 16px; display:flex; align-items:center; justify-content:center; gap:6px;
        border-radius:10px; font-family:inherit; font-size:13px; font-weight:800; cursor:default; white-space:nowrap; }
    .ent-btn .material-icons { font-size:17px; }
    .ent-btn-cancel { background:#fff; border:1px solid #cbd5e0; color:#475569; }
    .ent-btn-cancel:hover { background:#f1f5f9; }
    .ent-btn-ok { background:#0067b1; border:1px solid #0067b1; color:#fff; }
    .ent-btn-ok:hover { background:#005596; }
    .ent-btn-ok:disabled { opacity:.6; }

    /* ── Modal "Datos del documento": se abre con Registrar entrada. Mismo aspecto que el
       paso 1 del modal de compra directa: barra oscura, campos en una columna y la botonera
       centrada abajo. ── */
    .ent-doc-overlay { position:fixed; inset:0; z-index:99999; display:flex; align-items:center; justify-content:center;
        padding:10px; background:rgba(15,23,42,0.45); }
    .ent-doc-overlay[hidden] { display:none; }
    .ent-doc-box { background:#fff; border-radius:16px; width:100%; max-width:460px; max-height:94vh;
        display:flex; flex-direction:column; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35);
        animation:entDocIn .2s ease-out; }
    @keyframes entDocIn { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
    .ent-doc-bar { position:relative; display:flex; align-items:center; justify-content:center; gap:9px;
        background:#1e293b; padding:15px 48px; flex-shrink:0; }
    .ent-doc-bar .material-icons { color:#0067b1; font-size:20px; }
    .ent-doc-title { font-size:14.5px; font-weight:800; color:#fff; letter-spacing:.2px; }
    .ent-doc-close { position:absolute; right:12px; top:50%; transform:translateY(-50%); display:flex; padding:4px;
        background:transparent; border:none; border-radius:6px; cursor:default; opacity:.75; }
    .ent-doc-close:hover { opacity:1; }
    .ent-doc-close .material-icons { color:#fff; }
    .ent-doc-body { display:grid; gap:12px; padding:16px 20px 4px; overflow-y:auto; }
    .ent-doc-body .ent-error { margin-top:0; }
    .ent-doc-foot { display:flex; align-items:center; justify-content:center; gap:10px; margin-top:12px; padding:14px 20px;
        border-top:1px solid #e2e8f0; background:#f8fafc; flex-shrink:0; }

    /* Escritorio: la captura y la tabla con el ancho restante —la columna llega hasta el
       final de la pantalla y la tabla crece en ese espacio aunque esté vacía— y el panel a la
       derecha, fijo a la vista mientras la lista crece. El panel mide 430px y en pantallas
       angostas baja hasta 360px, para que a la tabla le quede espacio; entre las dos columnas,
       20px. (Hasta 900px, una columna: la regla base de .ent-layout.) */
    @media (min-width: 901px) {
        .ent-layout { display:grid; grid-template-columns:minmax(0, 1fr) clamp(360px, 34vw, 430px); gap:20px; align-items:start; }
        /* top:88px — debajo del menú superior, que es fijo y termina a 75px. */
        .ent-side { position:sticky; top:88px; z-index:6; }
        .ent-main { min-height:calc(100dvh - 185px); }
        .ent-list-wrap { flex:1 1 auto; display:flex; flex-direction:column; box-sizing:border-box; }
        .ent-list-box { flex:1 1 auto; display:flex; flex-direction:column; }
        .ent-empty { flex:1 1 auto; }
    }
</style>

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
