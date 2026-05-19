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
       boton "En transito". La pill del Almacen se muestra en el page-title-card
       (al lado del titulo) — patron de /admin/almacen. La Observacion se removio
       a pedido del cliente (2026-05-20). Los 4 items comparten 40px de alto. */
    .ent-head-row { display:grid; grid-template-columns:1fr 1fr 160px auto; gap:10px; align-items:center; }
    @media (max-width: 900px) { .ent-head-row { grid-template-columns:1fr 1fr; } }
    @media (max-width: 480px) {
        /* Mobile (≤480px): Nota y Proveedor toman ancho completo (1 fila c/u);
           Fecha + En transito comparten la ultima fila lado a lado (50% c/u).
           Pedido del cliente — antes los 4 items quedaban apilados en 1fr cada uno. */
        .ent-head-row { grid-template-columns:1fr 1fr; }
        .ent-head-row > :nth-child(1),                /* Nota de entrega */
        .ent-head-row > :nth-child(2) {                /* Proveedor      */
            grid-column: 1 / -1;
        }
        /* :nth-child(3) [Fecha] y :nth-child(4) [En transito] caen automaticamente
           cada uno en una columna de la ultima fila. */
    }
    /* Boton "Envios en transito" dentro de la fila de datos — calibrado a la altura
       de los .ent-input (40px) para alinearse. En mobile comparte fila con Fecha
       (cada uno ocupa 50% via el grid de 2 columnas en el media query de 480px). */
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

    /* ── Fila de captura: [Buscar] [Cantidad] SIEMPRE lado a lado ───────────
       Flex en vez de grid: mas predecible en mobile. El buscador absorbe
       el espacio sobrante (flex:1 1 0; min-width:0) y el stepper nunca
       se va a otra fila (flex:0 0 auto; min-width:114px).
       IMPORTANTE: NO tiene position:relative — el anclaje del dropdown
       es .ent-search-field, que SÍ la tiene. Agregar position:relative
       al row creaba un stacking-context que acotaba el z-index del dropdown. */
    .ent-capt-row { display:flex; gap:10px; align-items:flex-start; flex-wrap:nowrap; }
    /* Buscador de descripcion: absorbe el espacio sobrante pero con max-width para
       que no domine cuando los campos UM y Cantidad necesitan respirar (pedido del
       cliente 2026-05-19 — antes el buscador era flex:1 sin tope y se veia
       desproporcionado al lado de un UM angosto). */
    .ent-capt-row > .ent-search-field { flex:1 1 0; min-width:0; max-width: 520px; }
    /* Selector de UM entre el buscador y la cantidad. Se autocompleta al elegir un
       producto existente (queda disabled — no se cambia la UM de un producto del
       catalogo) y se desbloquea cuando el usuario tipea un producto nuevo.
       Ancho 120px: caben UMs largas como "BARRIL" o "C/U" sin truncar. */
    .ent-capt-row > .ent-um-wrap { flex:0 0 120px; width:120px; }
    /* Ancho fijo explícito: flex:0 0 100px impide que el search input
       (que tiene width:100% + padding) lo aplaste. */
    .ent-capt-row > .ent-cant-stepper { flex:0 0 100px; width:100px; }
    /* En pantallas muy chicas (≤480px) buscador full-width arriba y UM + cantidad
       comparten una segunda fila al 50% c/u — sino los 3 controles no entran y se
       aplastan demasiado. */
    @media (max-width: 480px) {
        .ent-capt-row { flex-wrap: wrap; }
        .ent-capt-row > .ent-search-field { flex: 1 1 100%; }
        .ent-capt-row > .ent-um-wrap { flex: 1 1 0; width: auto; }
        .ent-capt-row > .ent-cant-stepper { flex: 1 1 0; width: auto; }
    }
    /* Campo de UM — text input con autocomplete (mismo patron que el modal "Nuevo
       producto" de /admin/almacen). NO es un select con lista cerrada — el usuario
       puede escribir cualquier UM nueva (KG, ROLLO, M, M2, BARRIL, etc.) y queda
       guardada al crear el producto. El autocomplete sugiere las UMs YA registradas
       en el catalogo (window.entUnidadesMedida) para favorecer reutilizacion. */
    .ent-um-wrap { position:relative; }
    .ent-um-input {
        width:100%; height:42px; border:1px solid #cbd5e0; border-radius:10px;
        padding:0 10px; font-size:13.5px; font-weight:700; color:#0f172a;
        background:#fff; outline:none; box-sizing:border-box; text-transform:uppercase;
    }
    .ent-um-input:focus { border-color:var(--maquinaria-blue,#0067b1); }
    .ent-um-input:disabled { background-color:#f1f5f9; cursor:not-allowed; color:#475569; }
    /* Dropdown de sugerencias del campo UM — mismo estilo que .ent-suggest pero mas
       compacto (solo lista UMs cortas). */
    .ent-um-suggest {
        position:absolute; top:calc(100% + 4px); left:0; right:0;
        background:#fff; border:1px solid #e2e8f0; border-radius:10px;
        box-shadow:0 12px 24px -8px rgba(15,23,42,0.20);
        max-height:240px; overflow-y:auto; padding:4px;
        z-index:9000; display:none;
    }
    .ent-um-suggest.open { display:block; }
    .ent-um-suggest-item { padding:6px 10px; border-radius:6px; cursor:pointer; font-size:12.5px; font-weight:700; color:#0f172a; }
    .ent-um-suggest-item:hover, .ent-um-suggest-item.active { background:#e1effa; }
    .ent-um-suggest-empty { padding:8px 10px; font-size:11.5px; color:#94a3b8; font-style:italic; }

    /* Wrapper del buscador: altura fija 42px (= altura del stepper) y
       position:relative para anclar tanto el badge de seleccion como las
       sugerencias en absolute. */
    .ent-search-field { position:relative; height:42px; }
    /* box-sizing:border-box: los 50px de padding (38px izq + 12px der) quedan DENTRO
       del width:100%, sin desbordar el contenedor flex ni empujar al stepper. */
    .ent-search-input { width:100%; box-sizing:border-box; height:42px; border:1px solid #cbd5e0; border-radius:10px; padding:0 12px 0 38px; font-size:13.5px; background:#fff url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="%2364748b" viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>') no-repeat 12px center; outline:none; color:#0f172a; }
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
        /* z-index alto: debe quedar ENCIMA del stepper y de cualquier otro
           elemento — el valor 60 anterior no era suficiente cuando el padre
           tenia position:relative (creaba nuevo stacking context). */
        z-index:9000; display:none;
    }
    .ent-suggest.open { display:block; }
    /* Sugerencias del dropdown: CODIGO + NOMBRE LADO A LADO (no apilados verticalmente).
       El codigo va fijo a la izquierda (no se encoge) y el nombre absorbe el resto
       truncando con ellipsis si es largo — patron de autocompletes profesionales.
       AMBOS van en el MISMO color (#0f172a / slate-900) — pedido del cliente para
       que la lista no se vea "bicolor" (codigo azul + descripcion gris). El codigo
       sigue diferenciandose visualmente por la FUENTE monospace + peso/tamano,
       sin necesidad de un tono distinto. */
    .ent-suggest-item { display:flex; flex-direction:row; align-items:baseline; gap:8px; padding:8px 12px; border-radius:6px; cursor:pointer; transition:background .12s; }
    .ent-suggest-item:hover, .ent-suggest-item.active { background:#e1effa; }
    .ent-suggest-item .cod { font-family:monospace; font-size:11.5px; font-weight:700; color:#0f172a; letter-spacing:.3px; flex:0 0 auto; white-space:nowrap; }
    .ent-suggest-item .nom { font-size:13px; font-weight:600; color:#0f172a; flex:1 1 0; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .ent-suggest-empty { padding:10px 12px; font-size:12.5px; color:#94a3b8; font-style:italic; }

    /* Stepper de cantidad — clon del .alm-cant-stepper de /admin/almacen (variante
       "is-active"). En el modulo origen tiene height:30px porque vive dentro de una
       celda de tabla apretada; aqui sube a 42px para igualar la altura del input
       de busqueda — quedan visualmente alineados. Border-radius 10px para coincidir
       con los otros campos. */
    .ent-cant-stepper { display:inline-flex; align-items:stretch; border:1px solid #cbd5e0; border-radius:10px; overflow:hidden; background:#fff; height:42px; }
    .ent-cant-stepper:focus-within { border-color:var(--maquinaria-blue,#0067b1); box-shadow:0 0 0 2px rgba(0,103,177,0.18); }
    /* El input ocupa el espacio disponible dentro del stepper (100px - 24px botones - 2px borde = ~74px) */
    .ent-cant-input { flex:1 1 0; min-width:0; width:auto; height:100%; border:none; background:transparent; text-align:center; font-size:13.5px; font-weight:400; color:#0f172a; outline:none; padding:0; }
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

    /* ── Responsive mobile (≤768px) — patron calcado de /admin/almacen ──
       Titulo OCULTO, separadores OCULTOS, bloque del Almacen full-width.
       Viewport con padding lateral chico (calcado del override que el global
       hace para .page-layout-grid en estilos_globales.css:2962). */
    @media (max-width: 768px) {
        /* Viewport global: padding lateral 8px (igual que /admin/almacen) */
        .main-viewport { padding-left: 8px !important; padding-right: 8px !important; width: 100% !important; max-width: 100vw !important; box-sizing: border-box !important; padding-top: 12px !important; }
        /* Cards internas: padding reducido en mobile para ganar ancho de contenido */
        .ent-card { padding: 8px !important; }
        /* Titulo de seccion centrado en mobile (pedido del cliente). En desktop
           queda alineado a la izquierda como en el resto de los modulos. */
        .ent-section-title { justify-content: center !important; text-align: center !important; }

        .page-title-card .page-title { display: none !important; }
        .page-title-card .ent-header-sep { display: none !important; }
        .page-title-card > div { flex-direction: column !important; align-items: stretch !important; gap: 10px !important; }
        .page-title-card .ent-header-block { width: 100% !important; flex: 1 1 100% !important; }
        /* Almacen pill: ocupa lo que sobre del mini-label "Almacén" */
        .page-title-card .ent-header-block .ent-dest-pill { flex: 1 1 0; min-width: 0; max-width: 100%; overflow: hidden; }
        .page-title-card .ent-header-block .ent-dest-pill .name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    }

    /* ── Layout 2-columnas: card principal (izq) + sidebar "Resumen de Recepción" (der) ──
       Inspirado en stitch design — la columna derecha aprovecha el espacio horizontal sobrante
       y mueve las acciones (Registrar / Cancelar) fuera del flujo de captura. En desktop el
       sidebar queda STICKY pegado debajo del nav (top:90px) para que el usuario lo vea
       siempre mientras carga lineas. */
    .ent-layout {
        display:grid;
        grid-template-columns: minmax(0, 1fr) 320px;
        gap:18px;
        align-items: flex-start;
    }
    @media (max-width: 1024px) {
        /* Bajo 1024px se apila — la tabla ya consume todo el ancho disponible y
           comprimir mas el sidebar solo dejaria los botones pequeños. */
        .ent-layout { grid-template-columns: 1fr; gap:14px; }
    }

    .ent-sidebar {
        background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px;
        box-shadow:0 4px 12px rgba(15,23,42,0.04);
        position:sticky; top:90px;
        display:flex; flex-direction:column; gap:14px;
    }
    @media (max-width: 1024px) {
        .ent-sidebar { position:static; top:auto; }
    }
    .ent-sb-title {
        margin:0; padding-bottom:10px;
        font-size:16px; font-weight:700; color:#0f172a;
        border-bottom:1px solid #e2e8f0;
    }
    .ent-sb-stats { display:flex; flex-direction:column; gap:10px; }
    .ent-sb-row { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; font-size:13.5px; }
    .ent-sb-row .label { color:#64748b; flex:0 0 auto; }
    /* `value` puede contener una sola cifra ("03") o el desglose por UM
       ("30 UND · 5 KG"). text-align:right + flex:1 dejan que la cadena se
       envuelva si fuera muy larga sin desbordar el sidebar. */
    .ent-sb-row .value {
        color:#0f172a; font-weight:800; font-size:14px;
        font-variant-numeric:tabular-nums;
        text-align:right; flex:1 1 auto; min-width:0;
        line-height:1.35; word-break:break-word;
    }
    /* Caja "Observaciones" — fondo gris para diferenciarla del resto, textarea blanca dentro */
    .ent-sb-obs-wrap { background:#f1f5f9; padding:10px 12px; border-radius:10px; }
    .ent-sb-obs-label { display:block; font-size:10.5px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px; }
    .ent-sb-obs-input {
        width:100%; box-sizing:border-box;
        border:1px solid #cbd5e0; border-radius:8px;
        padding:8px 10px; font-size:13px;
        background:#fff; outline:none; resize:vertical; min-height:60px;
        font-family:inherit; color:#0f172a;
    }
    .ent-sb-obs-input:focus { border-color:var(--maquinaria-blue,#0067b1); }

    .ent-sb-actions { display:flex; flex-direction:column; gap:8px; }
    .ent-sb-btn-primary {
        background:var(--maquinaria-blue,#0067b1); color:#fff;
        height:48px; width:100%;
        border:none; border-radius:12px;
        font-size:14.5px; font-weight:700; cursor:pointer;
        display:flex; align-items:center; justify-content:center; gap:6px;
        transition:background .15s, transform .1s;
        box-shadow:0 4px 8px -2px rgba(0,103,177,0.3);
    }
    .ent-sb-btn-primary:hover { background:#005391; }
    .ent-sb-btn-primary:active { transform:scale(0.98); }
    .ent-sb-btn-primary:disabled { opacity:0.6; cursor:not-allowed; transform:none; }
    .ent-sb-btn-secondary {
        background:#fff; color:#475569;
        height:42px; width:100%;
        border:1px solid #cbd5e0; border-radius:10px;
        font-size:13.5px; font-weight:700; cursor:pointer;
        display:flex; align-items:center; justify-content:center; gap:6px;
        transition:background .15s, color .15s, border-color .15s;
    }
    .ent-sb-btn-secondary:hover { background:#fee2e2; color:#dc2626; border-color:#fca5a5; }
</style>

{{-- Layout 2-columnas: card principal (cabecera + captura + tabla) a la izquierda y
     sidebar "Resumen de Recepción" a la derecha. En <1024px se apila. La card de la
     izquierda mantiene la estructura UNICA anterior (Datos + captura + tabla); el
     boton "Registrar entrada" se movio al sidebar (junto con "Cancelar operacion"
     y la textarea de Observaciones). --}}
<div class="ent-layout">
<div class="ent-card">
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
            <input type="date" id="entFecha" style="flex:1;min-width:0;height:100%;border:none;background:transparent;padding:0;font-size:13.5px;outline:none;color:#0f172a;cursor:pointer;">
        </div>
        {{-- Atajo a la BANDEJA de envios en transito. ?force=1 esquiva el redirect
             del controller que manda al GLOBAL a /recepcion/nueva por default. --}}
        <a href="{{ route('almacen.recepcion.index', ['force' => 1]) }}"
           class="ent-envios-btn"
           title="Ver los envíos en tránsito hacia otros almacenes pendientes de confirmación">
            <i class="material-icons">local_shipping</i>
            <span>En tránsito</span>
        </a>
    </div>

    {{-- Separador fino entre "Datos de la entrada" y el bloque de captura/tabla.
         Margin horizontal 0 (no negativos) para que no se salga del card en mobile,
         donde el padding de .ent-card pasa a 8px (ver media query mas abajo). --}}
    <div style="border-top:1px solid #e2e8f0;margin:16px 0 14px;"></div>

    {{-- Fila de captura: [Buscar serial/desc] [Cantidad] siempre lado a lado.
         Flujo: tipea codigo o descripcion → elige sugerencia (queda como
         badge azul) → escribe cantidad → Enter → la linea cae a la tabla y el
         foco vuelve al buscador. Si el producto NO existe en el catalogo, al
         apretar Enter se crea automaticamente (codigo auto, UM=UND) y la linea
         igual se agrega — sin friccion. --}}
    <div class="ent-capt-row">

        {{-- ── Columna 1: buscador de producto ── --}}
        <div class="ent-search-field">
            <input type="text" id="entSearch" class="ent-search-input" autocomplete="off"
                   placeholder="Buscar por código (serial) o descripción…"
                   oninput="window.entSuggest()" onfocus="window.entSuggest()" onkeydown="window.entSearchKey(event)">
            {{-- Badge del producto seleccionado: tapa el input con position:absolute --}}
            <div id="entSelectedBadge" class="ent-selected-badge">
                <span class="cod" id="entSelectedCod"></span>
                <span id="entSelectedNom"></span>
                <i class="material-icons clear" onclick="window.entClearSelected()" title="Cambiar producto">close</i>
            </div>
            {{-- Dropdown de sugerencias: z-index:9000, NO se superpone al stepper --}}
            <div id="entSuggest" class="ent-suggest"></div>
        </div>

        {{-- ── Columna 2: Unidad de Medida (UM) ──
             Text input con autocomplete — MISMO patron que el modal "Nuevo producto"
             de /admin/almacen. Sugiere las UMs YA registradas en el catalogo, pero
             permite tipear una UM nueva libremente (queda guardada al crear el producto).
             Cuando el usuario elige un producto del catalogo, este input se completa
             con su UM y queda READONLY (la UM de un producto del catalogo no se cambia
             desde aqui). Cuando tipea un producto NUEVO, el input queda editable. --}}
        <div class="ent-um-wrap" title="Unidad de medida (UND, KG, L, M, etc.)">
            <input type="text" id="entUm" class="ent-um-input" value="UND"
                   maxlength="20" autocomplete="off" aria-label="Unidad de medida"
                   placeholder="UND"
                   oninput="window.entUmSuggest()" onfocus="window.entUmSuggest(true)" onkeydown="window.entUmKey(event)">
            <div id="entUmSuggest" class="ent-um-suggest"></div>
        </div>

        {{-- ── Columna 3: stepper de cantidad (RECONSTRUIDO) ── --}}
        {{-- flex:0 0 auto + min-width:114px garantizan que nunca baje a una segunda
             fila ni se encoja. Se ancla al top del buscador (align-items:flex-start). --}}
        <div class="ent-cant-stepper" title="Cantidad a ingresar (Enter agrega la línea)">
            <input type="text" inputmode="decimal" id="entCant" class="ent-cant-input"
                   placeholder="" autocomplete="off"
                   onkeydown="window.entCantKey(event)">
            <div class="ent-cant-btns">
                <button type="button" class="ent-cant-btn" onclick="window.entCantStep(1)"  tabindex="-1" title="+1">▲</button>
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

    {{-- Sin contador "N productos" debajo de la tabla — el sidebar derecho ya
         muestra "Total Productos" y "Unidades Totales", duplicarlo aqui era
         ruido visual (pedido del cliente 2026-05-19). --}}
</div>

{{-- ── Sidebar "Resumen de Recepción" ────────────────────────────────────
     En desktop ≥1024px aparece a la derecha de la tabla (sticky para que las
     acciones queden siempre visibles mientras el usuario captura lineas).
     En mobile baja debajo de la card. NO incluye:
       · Botón "Escanear" (la captura es manual con autocomplete).
       · Sugerencia "Ctrl+F" (la tabla es chica, el atajo no aporta). --}}
<aside class="ent-sidebar">
    <h2 class="ent-sb-title">Resumen de Recepción</h2>

    <div class="ent-sb-stats">
        <div class="ent-sb-row">
            <span class="label">Total Productos</span>
            <span class="value" id="entSbTotalProd">0</span>
        </div>
        <div class="ent-sb-row">
            <span class="label">Unidades Totales</span>
            <span class="value" id="entSbUnidades">0</span>
        </div>
    </div>

    {{-- Observaciones: texto libre que se anexa al payload (campo `notas`) junto a
         la "Nota de entrega" externa. Quedan en MovimientoInventario.NOTAS para
         consulta posterior desde el kardex. --}}
    <div class="ent-sb-obs-wrap">
        <span class="ent-sb-obs-label">Observaciones</span>
        <textarea id="entObservaciones" class="ent-sb-obs-input" rows="3"
                  maxlength="500" placeholder="Añadir comentarios internos..."></textarea>
    </div>

    <div class="ent-sb-actions">
        <button type="button" class="ent-sb-btn-primary" id="entSubmit" onclick="window.entGuardar()">
            <i class="material-icons">check_circle</i> Registrar entrada
        </button>
        <button type="button" class="ent-sb-btn-secondary" onclick="window.entCancelar()">
            <i class="material-icons">cancel</i> Cancelar operación
        </button>
    </div>
</aside>
</div>{{-- /ent-layout --}}

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
    // UMs distintas ya registradas en el catalogo — sirven de sugerencias para el
    // autocomplete del campo UM. El usuario puede tipear una UM nueva libremente.
    var UNIDADES_MEDIDA = @json($unidadesMedida ?? []);
    // Frente implicito del almacen destino: primer frente asociado (si lo tiene).
    // Se incluye en el payload de la entrada para que el kardex muestre el frente
    // en "Destino" en vez de "—". Null cuando el almacen no tiene frentes asociados
    // (el backend cae a su logica habitual y el partial del kardex muestra el nombre
    // del almacen como fallback — fix b6c326b).
    var ID_FRENTE_DESTINO = @json($idFrenteDestino ?? null);

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
    // Tambien sincroniza el select de UM con la UM del producto y lo bloquea
    // (la UM de un producto del catalogo no se modifica desde esta pantalla —
    // ya viene definida en /admin/almacen).
    function entPick(item) {
        entSelected = {
            id_producto: parseInt(item.getAttribute('data-id'), 10),
            codigo:      item.getAttribute('data-cod') || '',
            nombre:      item.getAttribute('data-nom') || '',
            um:          item.getAttribute('data-um') || '',
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

        // Sincronizar el input de UM con la UM del producto y bloquearlo (la UM de
        // un producto del catalogo no se modifica desde Recepcion ODC — ya esta
        // definida en /admin/almacen). Usamos readOnly en vez de disabled para que
        // el valor se siga enviando en el form si fuera necesario.
        var umInp = el('entUm');
        if (umInp && entSelected.um) {
            umInp.value = entSelected.um;
            umInp.readOnly = true;
            umInp.classList.add('is-locked');
            entUmHide();
        }

        // Saltar a cantidad para captura rapida: codigo → enter → cantidad → enter.
        setTimeout(function () { var c = el('entCant'); if (c) c.focus(); }, 30);
    }
    // clearAfterAdd=true cuando se llama desde entInsertarLinea (post agregar una
    // linea): suprimimos el siguiente entSuggest para que el dropdown NO se abra
    // automaticamente al refocar el buscador. El usuario apreto Enter para AGREGAR,
    // no para volver a buscar — si quiere otra busqueda hace click o tipea.
    window.entClearSelected = function (clearAfterAdd) {
        entSelected = null;
        el('entSelectedBadge').classList.remove('show');
        var inp = el('entSearch'); inp.style.display = ''; inp.value = '';
        // Re-habilitar y resetear el input de UM para la siguiente captura
        // (queda en UND como default — el usuario lo cambia si registra producto nuevo).
        var umInp = el('entUm');
        if (umInp) {
            umInp.readOnly = false;
            umInp.classList.remove('is-locked');
            umInp.value = 'UND';
        }
        if (clearAfterAdd) entSkipNextSuggest = true;
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
        if (inp.readOnly) { entUmHide(); return; }
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
    document.addEventListener('click', function (e) {
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
        // Limpiar inputs y devolver foco al buscador — siguiente producto. El flag
        // `true` suprime la auto-apertura del dropdown (Enter en Cantidad agrega
        // la linea; no es una intencion de "ver mas sugerencias").
        window.entClearSelected(true);
        el('entCant').value = '';
    }

    // POST al endpoint almacen.productos.store con el UM elegido por el usuario.
    // La UM ya viene del select #entUm en la fila de captura (entAgregar la lee y
    // la pasa). Antes esto se hacia via un mini-modal aparte — removido por
    // redundancia ahora que UM esta in-line en la fila de captura.
    // Al volver con el id real del backend, lo insertamos a entLineas como una
    // linea mas y al catalogo en memoria (PRODUCTOS) para que aparezca en
    // busquedas posteriores sin recargar la pagina.
    function entDoCreateProducto(nombre, cant, um) {
        entCreandoProducto = true;
        if (window.showPreloader) window.showPreloader();
        // IMPORTANTE: mandamos id_almacen aunque NO haya cantidad inicial — el backend
        // (AlmacenController::storeProducto) llama a asegurarStock() que crea la fila
        // almacen_stock con CANTIDAD=0 si no existia. Sin esto, el producto creado al
        // vuelo quedaba en el catalogo pero INVISIBLE en /admin/almacen (autocomplete
        // filtra por productosEnAlmacen) y en la tabla del inventario (INNER JOIN con
        // almacen_stock). cantidad_inicial=0 explicito para que el backend no dispare
        // el check de permiso almacen.movimiento (que solo aplica cuando cant > 0).
        var idAlmacenForm = v('entAlmacen');
        var body = { NOMBRE: nombre, UM: um };
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
        // producto nuevo al vuelo. La UM se toma del input #entUm (autocompletado,
        // permite tipear UM nueva). Reemplaza al mini-modal que antes pedia la UM
        // en un dialogo aparte — ahora la UM esta in-line en la fila de captura.
        var textoBuscador = String(el('entSearch').value || '').trim();
        if (textoBuscador.length >= 2) {
            var umInp = el('entUm');
            var um = umInp ? String(umInp.value || 'UND').trim().toUpperCase() : 'UND';
            if (!um) um = 'UND';
            entDoCreateProducto(textoBuscador, cant, um);
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
        var tb = el('entLineasTbody');
        // Contadores del sidebar "Resumen de Recepción". Se calculan ANTES del
        // posible early return del tbody vacio para que reflejen 0 cuando se
        // borran todas las lineas.
        //   Total Productos: numero de lineas distintas, padded "0N" si n<10.
        //   Unidades Totales: agrupado POR UM ("30 UND · 5 KG · 2 ROLLO"), porque
        //     sumar cantidades de UMs distintas no tiene sentido (10 UND + 5 KG
        //     no son 15 de nada). Pedido del cliente 2026-05-19.
        var n = entLineas.length;
        var sbProd = el('entSbTotalProd');
        if (sbProd) sbProd.textContent = (n < 10 ? '0' : '') + n;
        var sbUnid = el('entSbUnidades');
        if (sbUnid) {
            // Map por UM preservando el orden de aparicion (Map mantiene insertion order).
            // Asi el sidebar muestra las UMs en el mismo orden en que el usuario las cargo
            // — el primer producto define la primera UM mostrada, etc.
            var porUm = new Map();
            for (var i = 0; i < entLineas.length; i++) {
                var l = entLineas[i];
                var um = String(l.um || 'UND').toUpperCase();
                porUm.set(um, (porUm.get(um) || 0) + (parseFloat(l.cantidad) || 0));
            }
            var partes = [];
            porUm.forEach(function (cant, um) { partes.push(fmtCant(cant) + ' ' + um); });
            sbUnid.textContent = partes.length ? partes.join(' · ') : '0';
        }
        if (!tb) return;
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
        // Nº de Nota de Entrega externa: lo metemos en `notas` con un prefijo claro
        // — el endpoint no tiene columna dedicada y notas es texto libre. Las
        // Observaciones del sidebar tambien viajan en el mismo campo `notas`
        // separadas con " — " para que se lean limpias en el kardex.
        var notaEntrega = v('entNotaEntrega');
        var observaciones = v('entObservaciones');
        var partes = [];
        if (notaEntrega)   partes.push('Nota de entrega: ' + notaEntrega);
        if (observaciones) partes.push(observaciones);
        var notasFinal = partes.join(' — ');

        var payload = {
            tipo:       'ENTRADA',
            id_almacen: parseInt(idAlm, 10),
            // id_frente: cuando el almacen destino tiene al menos un frente asociado,
            // lo mandamos en el payload para que la columna "Destino" del kardex
            // muestre el frente. Si es null, el backend cae a su logica
            // (frenteImplicitoDelAlmacen estricto) y el partial del kardex muestra
            // el nombre del almacen como fallback (b6c326b).
            id_frente:  ID_FRENTE_DESTINO,
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

    // ── Cancelar operacion: descarta el borrador y vuelve a la bandeja ──
    // Si hay lineas capturadas pedimos confirmacion — perder una captura larga
    // por un click accidental seria frustrante. Si no hay nada, redirige directo.
    window.entCancelar = function () {
        if (entLineas.length > 0) {
            var msg = '¿Cancelar la entrada? Se perderán ' + entLineas.length
                    + ' producto' + (entLineas.length === 1 ? '' : 's') + ' capturado'
                    + (entLineas.length === 1 ? '' : 's') + '.';
            if (!window.confirm(msg)) return;
        }
        window.location = ROUTE_BACK;
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
