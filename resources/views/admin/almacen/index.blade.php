@extends('layouts.estructura_base')

@section('title', 'Almacén')

@section('content')
<style>
    /* ── Almacén / Inventario — tabla + filtros (estilo /admin/equipos) ── */
    #almFilters {
        display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 4px 0 14px;
    }
    .alm-filter { flex: 1 1 200px; min-width: 170px; max-width: 280px; position: relative; }
    .alm-filter-box {
        display: flex; align-items: center; background: #fbfcfd; border: 1px solid #cbd5e0;
        border-radius: 12px; height: 45px; overflow: hidden;
    }
    .alm-filter.active .alm-filter-box { background: #e1effa; border-color: var(--maquinaria-blue, #0067b1); }
    .alm-filter .alm-ic { padding: 0 10px; display: flex; align-items: center; color: #64748b; }
    .alm-filter input[type="text"], .alm-filter select {
        flex: 1; border: none; background: transparent; outline: none; font-size: 14px;
        color: #1e293b; padding: 10px 6px; min-width: 0; height: 100%; cursor: text;
    }
    .alm-filter select { cursor: pointer; -webkit-appearance: none; appearance: none; }
    .alm-filter .filter-clear { padding: 0 8px; color: #64748b; font-size: 18px; cursor: pointer; }

    /* Tabla de inventario: estilo igualado a /admin/equipos (.table-row-header + .table-header-custom):
       thead oscuro con texto blanco uppercase, body con texto negro y bordes claros entre columnas. */
    .alm-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 14px; min-width: 760px; color: #000; }
    .alm-table thead tr { background: #1e293b; }
    .alm-table thead th {
        text-align: left; color: #fff; font-size: 13px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        padding: 10px 15px; border-right: 1px solid #334155; border-bottom: 2px solid #0f172a;
        position: sticky; top: 0; z-index: 2; white-space: nowrap;
    }
    .alm-table thead th:last-child { border-right: none; }
    .alm-table tbody td { padding: 12px 15px; color: #000; font-size: 14px; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; vertical-align: middle; }
    .alm-table tbody td:last-child { border-right: none; }
    .alm-table tbody tr:hover td { background: #e0f2fe; }
    /* Tooltip-bubble con la UBICACION del producto: aparece al pasar el mouse por cualquier
       parte de la fila (mismo patrón que /admin/equipos usando `.admin-table tr:hover`). */
    .alm-row:hover .tooltip-bubble { opacity: 1 !important; visibility: visible !important; }
    /* Fila con stock bajo: tono rojo claro para indicar urgencia. Al hacer hover hereda
       el azul general como cualquier otra fila (sin sobrescribir con !important — antes
       quedaba naranja en hover y se sentia inconsistente). */
    .alm-row-bajo td { background: #fee2e2; }
    /* Fila seleccionable: clic en la fila la marca (estilo /admin/equipos → .selected-row-maquinaria) */
    /* Las filas son seleccionables con clic pero el cursor se mantiene como flecha (sin mano). */
    .alm-table tbody tr.alm-row-clickable { cursor: default; }
    /* Fila SELECCIONADA: azul claramente mas oscuro que el hover (#e0f2fe) para que se
       distinga a simple vista cual esta marcada. Persiste tambien en hover (sin !important
       el hover gana porque viene despues en cascada). */
    .alm-table tbody tr.alm-row.selected-row-maquinaria,
    .alm-table tbody tr.alm-row.selected-row-maquinaria:hover,
    .alm-table tbody tr.alm-row.selected-row-maquinaria td,
    .alm-table tbody tr.alm-row.selected-row-maquinaria:hover td { background: #93c5fd !important; }
    /* Anulación local: la regla global `tr.selected-row-maquinaria td { color:#0067b1 }`
       (estilos_globales.css ~línea 1929) deja TODO el texto azul. En esta tabla solo
       queremos el background azul, NO los textos: el código y el nombre tienen su propio
       color especificado por celda. `unset` + `inherit` aseguran que cada celda use su
       color inline original (font-weight + color de td) en vez del azul global. */
    .alm-table tbody tr.alm-row.selected-row-maquinaria td { color: unset !important; }

    /* Stepper compacto de "Cant. salida" (vive en cada fila de la tabla):
       el input es <type="text" inputmode="decimal"> (sin spinners nativos por construcción)
       y los botones ▲/▼ están manejados por JS — almRowCantKeyDown bloquea letras/signos.
       Los estilos inline del partial pintan el estado DESHABILITADO (gris). Cuando
       almSelMarkRow añade .is-active al wrapper estos selectores ganan con !important
       para revertir el gris a los colores "activos" (texto negro, botón azul). */
    .alm-cant-stepper.is-active { background:#fff !important; border-color:#94a3b8 !important; }
    .alm-cant-stepper.is-active .alm-cant-btn { background:#fff !important; color:#0067b1 !important; cursor:pointer !important; }
    .alm-cant-stepper.is-active .alm-cant-btn:hover { background:#e0f2fe !important; }
    .alm-cant-stepper.is-active .alm-row-cant { color:#0f172a !important; }

    /* Filas seleccionadas a las que les falta "Cant. salida" tras tocar "Registrar salida".
       Persistente hasta que el usuario teclee una cantidad > 0 o deseleccione el producto.
       Sobrevive a recargas AJAX del tbody (vía almAplicarFaltantes en almSelApplyToVisible). */
    /* MISMO tratamiento visual para 2 condiciones distintas que bloquean la salida:
         · .alm-row-missing-cant    -> usuario no llenó la cantidad (vacia o <= 0)
         · .alm-row-exceeds-stock   -> cantidad tecleada > saldo disponible
       Ambas usan el mismo rojo + barra izquierda + outline en el stepper. */
    #almTableBody tr.alm-row.alm-row-missing-cant td,
    #almTableBody tr.alm-row.alm-row-exceeds-stock td { background:#fecaca !important; color:#991b1b; }
    #almTableBody tr.alm-row.alm-row-missing-cant td:first-child,
    #almTableBody tr.alm-row.alm-row-exceeds-stock td:first-child { box-shadow: inset 6px 0 0 #b91c1c; }
    #almTableBody tr.alm-row.alm-row-missing-cant .alm-cant-stepper,
    #almTableBody tr.alm-row.alm-row-exceeds-stock .alm-cant-stepper { border-color:#b91c1c !important; background:#fff !important; box-shadow:0 0 0 2px rgba(220,38,38,0.25); }
    #almTableBody tr.alm-row.alm-row-missing-cant .alm-row-cant,
    #almTableBody tr.alm-row.alm-row-exceeds-stock .alm-row-cant { color:#991b1b !important; font-weight:700; }
    /* Pulso intenso para llamar la atención al primer pintado. */
    #almTableBody tr.alm-row.alm-row-missing-cant,
    #almTableBody tr.alm-row.alm-row-exceeds-stock { animation: almMissingPulse 0.9s ease-out 1; }
    @keyframes almMissingPulse {
        0%   { background:#f87171; }
        100% { background:transparent; }
    }

    /* Contador "inventory_2 N" clickable — toggle del filtro "Solo seleccionados". */
    .alm-bulk-counter { cursor:pointer; user-select:none; transition:transform 0.12s, box-shadow 0.18s; }
    .alm-bulk-counter:hover { transform:scale(1.04); }
    .alm-bulk-counter.is-filtering { box-shadow:0 0 0 2px #fbbf24, 0 0 12px rgba(251,191,36,0.45); border-radius:999px; }

    /* Badges "Con stock" / "Stock bajo" en el header del almacen — cuando uno de los dos
       filtros esta aplicado, el badge correspondiente se marca como activo (anillo blanco
       + fondo mas saturado). Asi el usuario VE cual filtro esta filtrando la tabla y puede
       clickearlo otra vez para apagarlo (toggle). */
    #almBadgeConSaldo.is-on { background:rgba(34,197,94,0.45) !important; border-color:rgba(255,255,255,0.6) !important; box-shadow:0 0 0 2px rgba(255,255,255,0.5), 0 0 10px rgba(34,197,94,0.55); }
    #almBadgeBajo.is-on     { background:rgba(245,158,11,0.45) !important; border-color:rgba(255,255,255,0.6) !important; box-shadow:0 0 0 2px rgba(255,255,255,0.5), 0 0 10px rgba(245,158,11,0.55); }

    .alm-btn {
        display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px;
        border-radius: 7px; border: 1px solid #e2e8f0; background: #fff; cursor: pointer; margin: 0 1px;
        transition: transform 0.12s, background 0.12s;
    }
    .alm-btn:hover { transform: scale(1.06); }
    /* (se usan en la lista "Gestionar almacenes" — editar / eliminar almacén) */
    .alm-btn-edit { color: #0891b2; border-color: #cffafe; } .alm-btn-edit:hover { background: #0891b2; color: #fff; }
    .alm-btn-del  { color: #ef4444; border-color: #fecaca; } .alm-btn-del:hover  { background: #ef4444; color: #fff; }
    /* Botón "ojo" de detalles por fila: mismo look que el de /admin/equipos */
    .alm-table tbody td .btn-details-mini { margin: 0 auto; }
    /* Acciones dentro del modal "Detalles del producto" */
    .alm-det-act { display:flex; align-items:center; gap:10px; width:100%; text-align:left; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:8px 12px; font-size:14px; font-weight:600; color:#334155; cursor:default; transition:background .15s, border-color .15s; }
    .alm-det-act:hover { background:#f8fafc; border-color:#cbd5e0; }
    .dropdown-item-custom:hover { background: #f8fafc !important; }
    .alm-det-ic { width:30px; height:30px; border-radius:8px; display:flex; align-items:center; justify-content:center; flex:0 0 auto; }

    /* Modales */
    .alm-modal-overlay {
        display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.45);
        z-index: 9000; align-items: center; justify-content: center; padding: 16px;
    }
    .alm-modal-overlay.open { display: flex; }
    .alm-modal {
        background: #fff; border-radius: 14px; width: 100%; max-width: 440px; box-shadow: 0 20px 50px rgba(0,0,0,0.25);
        overflow: hidden; animation: almIn 0.16s ease-out; display: flex; flex-direction: column; max-height: 90vh;
    }
    .alm-modal-wide { max-width: 980px; }
    .alm-modal .alm-modal-body { overflow-y: auto; min-height: 0; }
    /* Inputs del modal "Nuevo / Editar producto": todo se guarda en mayúsculas,
       se ven en mayúsculas mientras se escribe para coincidir con lo que se guarda.
       El placeholder NO se transforma. CODIGO queda fuera (solo dígitos). */
    #almProdNombre, #almProdUm, #almProdCategoria, #almProdUbicacion { text-transform: uppercase; }
    /* Multiselect de frentes dentro del modal de almacén: el panel empuja el contenido (no flota) para que el overflow del modal no lo recorte */
    #almAlmacenModal .multiselect-content { position: static; box-shadow: none; margin-top: 6px; }
    #almAlmacenModal .custom-multiselect.active .multiselect-content { animation: slideDown 0.18s ease-out; }
    .alm-admin-list { display: flex; flex-direction: column; gap: 6px; }
    .alm-admin-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 8px; }
    .alm-admin-row:hover { background: #f8fafc; }
    @keyframes almIn { from { transform: translateY(8px); opacity: 0; } to { transform: none; opacity: 1; } }
    @keyframes slideDown { from { transform: translateY(-8px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    /* El filtro de almacén (en el título) NO se resalta en azul cuando está activo:
       sobrescribimos el estilo global .filter-active sólo para ese dropdown. */
    #almSelAlmacenDropdown .dropdown-trigger.filter-active {
        background: #f8fafc !important;
        border-color: #cbd5e0 !important;
    }
    /* Todos los modales del módulo: título y botones centrados; la X queda fija en la esquina. */
    .alm-modal-head { padding: 14px 40px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: center; position: relative; }
    .alm-modal-head h3 { margin: 0; font-size: 15px; font-weight: 800; color: #1e293b; display: flex; align-items: center; gap: 8px; text-align: center; }
    .alm-modal-head .alm-x { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); }
    .alm-modal-body { padding: 16px 18px; display: flex; flex-direction: column; gap: 12px; }
    .alm-modal-foot { padding: 12px 18px; border-top: 1px solid #f1f5f9; display: flex; justify-content: center; gap: 8px; }
    .alm-modal label,
    .alm-modal .alm-fake-label { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.3px; display: block; margin-bottom: 4px; }
    .alm-modal input, .alm-modal select, .alm-modal textarea {
        width: 100%; border: 1px solid #cbd5e0; border-radius: 8px; padding: 9px 10px; font-size: 14px; outline: none; box-sizing: border-box;
    }
    .alm-modal input:focus, .alm-modal select:focus, .alm-modal textarea:focus { border-color: var(--maquinaria-blue, #0067b1); }
    /* Campos de la Nota de Entrega (modal SALIDA) — input y label estilo VID-FO-GEN-019.
       Los selectores usan ".alm-modal input.alm-nota-input" (especificidad 0,2,1) para
       ganarle al ".alm-modal input" (0,1,1) declarado arriba: si no, sus padding/radius/
       font-size sobrescribirían a los compactos de la Nota. */
    .alm-modal .alm-nota-label { display:block; font-size:10.5px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px; }
    .alm-modal input.alm-nota-input,
    .alm-modal select.alm-nota-input { width:100%; height:38px; border:1px solid #cbd5e0; border-radius:7px; padding:0 10px; font-size:13.5px; background:#fff; outline:none; color:#0f172a; box-sizing:border-box; }
    .alm-modal input.alm-nota-input:focus,
    .alm-modal select.alm-nota-input:focus { border-color: var(--maquinaria-blue, #0067b1); }
    .alm-pill { display: inline-block; background: #f1f5f9; border-radius: 6px; padding: 2px 8px; font-size: 12px; font-weight: 700; color: #334155; }
    .alm-x { cursor: pointer; color: #94a3b8; }
    .alm-x:hover { color: #475569; }

    /* Sugerencias de los filtros — mismo look que los desplegables (.dropdown-content / .dropdown-item) de la app */
    .alm-suggest {
        position:absolute; top:calc(100% + 5px); left:0; right:0; background:#fff;
        border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,0.1);
        z-index:1000; max-height:260px; overflow-y:auto; padding:5px; display:none;
    }
    .alm-suggest.open { display:block; animation:slideDown 0.18s ease-out; }
    .alm-suggest-item { display:flex; flex-direction:column; gap:2px; padding:10px 15px; border-radius:8px; cursor:default; transition:background 0.2s; font-weight:600; color:var(--maquinaria-dark-blue,#1e3a5f); }
    .alm-suggest-item:hover, .alm-suggest-item.active { background:#f0f4f8; }
    .alm-suggest-item.si-sel { background:#ebf4ff; color:var(--maquinaria-blue,#0067b1); }
    .alm-suggest-item .nom { font-size:13.5px; color:#475569; font-weight:600; }
    .alm-suggest-empty { padding:10px 15px; font-size:13px; color:#94a3b8; }
    /* Variante "en línea" para los modales (no flota: empuja el contenido — así no la recorta el overflow del modal) */
    .alm-suggest-inline { margin-top:6px; border:1px solid #e2e8f0; border-radius:12px; background:#fff; box-shadow:0 6px 16px rgba(0,0,0,0.06); max-height:200px; overflow-y:auto; padding:5px; display:none; }
    .alm-suggest-inline.open { display:block; animation:slideDown 0.18s ease-out; }
    .alm-suggest-inline .si-item { display:flex; align-items:center; gap:10px; padding:10px 15px; border-radius:8px; cursor:default; font-size:14px; font-weight:600; color:var(--maquinaria-dark-blue,#1e3a5f); transition:background 0.2s; }
    .alm-suggest-inline .si-item:hover { background:#f0f4f8; }
    .alm-suggest-inline .si-item.si-sel { background:#ebf4ff; color:var(--maquinaria-blue,#0067b1); }
    /* Campo "Categoría" del modal de producto: input + botón desplegable (caret) */
    .alm-cat-field { position:relative; display:flex; align-items:center; }
    .alm-cat-field > input { flex:1; padding-right:36px !important; }
    .alm-cat-caret { position:absolute; right:3px; top:50%; transform:translateY(-50%); width:30px; height:30px; border:none; background:transparent; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#64748b; border-radius:8px; transition:background .15s,color .15s; }
    .alm-cat-caret:hover { background:#f1f5f9; color:#0f172a; }
    .alm-cat-caret .material-icons { font-size:22px; transition:transform .15s; }
    .alm-cat-caret.open .material-icons { transform:rotate(180deg); }

    /* ── Responsive mobile (≤768px) — patron calcado de /admin/equipos ──
       En mobile: titulo OCULTO (el espacio vertical es caro en telefono — el
       usuario ya sabe que esta en el modulo de almacen por la nav); el selector
       de almacen queda full-width como header efectivo. Filtros apilados, boton
       Acciones full-width al final, menu desplegable limitado al viewport. */
    @media (max-width: 768px) {
        /* Titulo de pagina oculto en mobile + separador vertical (ya no tiene sentido) */
        .page-title-card .page-title { display: none !important; }
        .page-title-card > div > span[aria-hidden="true"] { display: none !important; }
        /* Cabecera: el wrapper interno (`.page-title-card > div`) usaba flex
           horizontal con separador vertical — en mobile lo apilamos en columna
           para que el selector de almacen ocupe todo el ancho. */
        .page-title-card > div { flex-direction: column !important; align-items: stretch !important; gap: 10px !important; }
        /* El bloque del selector de almacen (mini-label + dropdown) tomaba flex:0 1 auto;
           en mobile lo forzamos full-width para que el dropdown ocupe la pantalla completa. */
        .page-title-card > div > div { width: 100% !important; flex: 1 1 100% !important; }
        .page-title-card > div > div > div[style*="width:280px"] { width: 100% !important; min-width: 0 !important; max-width: 100% !important; }

        /* Filtros: cada caja full-width, una debajo de la otra. La fila completa
           ya hace wrap nativamente (flex-wrap:wrap en #almFilters); aqui solo
           ajustamos el flex-basis para evitar tracks anchos. */
        #almFilters { gap: 8px; }
        #almFilters .alm-filter { max-width: none !important; flex: 1 1 100% !important; }

        /* Boton "Acciones" full-width — antes quedaba angosto a la derecha por
           `margin-left:auto`, raro en mobile. Y el menu desplegable se alinea
           a la izquierda para entrar en pantalla sin overflow. */
        #almFilters > div:last-child { width: 100% !important; flex: 1 1 100% !important; margin-left: 0 !important; }
        #almFilters > div:last-child > div { width: 100%; }
        #almBtnAcciones { width: 100% !important; justify-content: center; }
        #almAccionesMenu { left: 0 !important; right: 0 !important; width: 100% !important; max-width: calc(100vw - 20px) !important; }

        /* Consolidado de Inventario en mobile: dos reglas globales lo
           ocultan en pantallas chicas — catalogo.css:22 (≤768px) y
           estilos_globales.css:2924 (`.page-layout-grid .counter-sidebar`
           en ≤900px, mas especifica). Para ganarles la cascada usamos el
           mismo selector compuesto. Va debajo del boton Acciones. */
        .page-layout-grid .counter-sidebar {
            display: flex !important;
            flex-direction: column !important;
            width: 100% !important;
            position: static !important;
            gap: 10px !important;
            margin-top: 10px;
        }
        /* Mismo redimensionamiento que /admin/equipos para el sidebar:
           tipografias mas chicas en pantallas de telefono. */
        .counter-sidebar [style*="font-size: 13px"] { font-size: 11px !important; }
        .counter-sidebar [style*="font-size: 14px"] { font-size: 12px !important; }
        .counter-sidebar [style*="font-size: 16px"] { font-size: 14px !important; }
        .counter-sidebar [style*="font-size: 18px"] { font-size: 15px !important; }
        .counter-sidebar [style*="font-size: 34px"] { font-size: 26px !important; }
        .counter-sidebar [style*="font-size: 36px"] { font-size: 26px !important; }
        /* Ocultar el titulo "Consolidado de Inventario" en mobile — no aporta
           cuando el usuario ya esta dentro del modulo y los iconos PRODUCTOS /
           inventory_2 / warning explican por si mismos. */
        .counter-sidebar .alm-consolidado-title { display: none !important; }
        /* Panel "En otros almacenes" (modo cruzado): se muestra en mobile pero
           comprimido — items mas chicos (similar al patron de cards de
           /admin/almacen/movimientos en mobile) para no comerse pantalla. */
        .counter-sidebar .alm-otros-almacenes ul { max-height: 50vh !important; gap: 2px !important; }
        .counter-sidebar .alm-otros-almacenes li { padding: 5px 7px !important; }
        .counter-sidebar .alm-otros-almacenes li > span:first-child { font-size: 11px !important; }
        .counter-sidebar .alm-otros-almacenes li > span:last-child { font-size: 11px !important; padding: 1px 7px !important; }
        /* Cuando el producto NO existe en mas almacenes (alm-otros-empty), ocultar
           el panel entero en mobile — el mensaje "Este producto solo existe…" no
           aporta y desperdicia espacio vertical. Desktop conserva el aviso. */
        .counter-sidebar .alm-otros-almacenes.alm-otros-empty { display: none !important; }
        /* "Distribucion de Inventario" (grafico por categoria, modo default): se
           oculta en mobile. El cliente lo encontro redundante con la columna
           Categoria que cada tarjeta ya muestra inline, y comia mucho espacio
           vertical. Desktop lo conserva (es util como mapa rapido del inventario). */
        .counter-sidebar .alm-distribucion-cats { display: none !important; }
        /* "Ver movimientos del producto" del modal de detalles: el kardex tabular
           que abre es pesado en mobile. Cliente prefirio quitar el boton en
           telefono y mantener el modal de detalles compacto. */
        #almDetalleModal .alm-det-act-kardex { display: none !important; }
        /* Distribucion por categoria: comprimir el panel — h4 + lis mas chicos. */
        .counter-sidebar h4 { font-size: 11px !important; margin-bottom: 8px !important; }
        .counter-sidebar h4 .material-icons { font-size: 15px !important; }
        .counter-sidebar li span { font-size: 9.5px !important; }

        /* ═══════════════════════════════════════════════════════════
           MOBILE CARD LAYOUT — Inventario de Almacén
           Cada <tr.alm-row> es una tarjeta GRID 3-col × 3 filas:
             ┌──────────────────────────────────────────────────┐
             │ 00042 · ABRAZADERA INOXIDABLE 1/2  (banda gris)  │  ← nombre+codigo (3 cols)
             ├──────────────────────────────────────────────────┤
             │                          5.000 UND ⚠             │  ← stock (2 cols) | um
             │ [▲ 0 ▼ stepper]                          [👁]    │  ← cant (2 cols) | det
             └──────────────────────────────────────────────────┘
           Cliente pidio CODIGO en la misma casilla que el nombre como un
           solo dato — el <td> alm-td-codigo se oculta y su valor se renderiza
           via ::before de alm-td-nombre leyendo data-codigo.

           Categoria (alm-td-cat) OCULTA en mobile — el cliente la encontro
           ruido visual: la info ya esta accesible filtrando o desde el modal
           de detalles. La fila stock+um pasa a span 2-cols+auto (stock spans
           cols 1-2 con justify-content:flex-end → "5.000 UND" queda como par
           pegado al borde derecho, sin huecos).

           Colores tipograficos = los del desktop (inline style):
             nombre: #1e293b  | stock: #0f172a  | um: #475569
           El prefijo del codigo mantiene gris (es secundario).
           CADA celda lleva grid-area explicito — no auto-placement.
           ═══════════════════════════════════════════════════════════ */

        .alm-table-wrap { overflow-x: visible !important; border: none !important; border-radius: 0 !important; }
        .alm-table { min-width: 0 !important; display: block !important; }
        .alm-table thead { display: none !important; }
        .alm-table tbody { display: flex !important; flex-direction: column !important; gap: 10px !important; }

        .alm-table tr.alm-row {
            display: grid !important;
            /* col 1+2 = 1fr cada una (stepper a la izq, stock spans 1+2), col 3 = auto
               (um pegado al stock, ancho minimo). En la fila stock, justify-content
               flex-end alinea "5.000" al borde derecho de su celda de 2-cols, dejando
               UND inmediatamente al lado. */
            grid-template-columns: 1fr 1fr auto !important;
            grid-template-areas:
                "nombre nombre nombre"
                "stock  stock  um"
                "cant   cant   det" !important;
            gap: 0 !important;
            background: #fff !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 14px !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07) !important;
            padding: 0 !important;
            overflow: hidden !important;
        }
        .alm-table tr.alm-row.alm-row-bajo { border-left: 4px solid #f59e0b !important; background: #fffbeb !important; }

        .alm-table tr.alm-row td {
            display: flex !important;
            align-items: center !important;
            border: none !important;
            background: transparent !important;
            white-space: normal !important;
            min-width: 0 !important;
            width: auto !important;
            font-size: 13px !important;
        }
        .alm-table tr.alm-row td .tooltip-bubble { display: none !important; }

        /* Codigo: en mobile no tiene celda propia — se renderiza como prefijo
           monospace de la celda nombre (via ::before leyendo data-codigo). */
        .alm-table tr.alm-row td.alm-td-codigo { display: none !important; }

        /* Fila 1: nombre del producto con codigo prefijo — banda gris full width.
           El "00042 · " sale del atributo data-codigo del propio <td>. */
        .alm-table tr.alm-row td.alm-td-nombre {
            grid-area: nombre !important;
            background: #f1f5f9 !important;
            border-bottom: 1px solid #e2e8f0 !important;
            padding: 8px 12px !important;
            font-size: 13.5px !important;
            font-weight: 700 !important;
            color: #1e293b !important;
            line-height: 1.3 !important;
            gap: 6px !important;
        }
        .alm-table tr.alm-row td.alm-td-nombre::before {
            content: attr(data-codigo) " · ";
            font-family: monospace;
            font-weight: 800;
            font-size: 12px;
            color: #64748b;
            white-space: nowrap;
            letter-spacing: 0.2px;
        }

        /* Categoria oculta en mobile (peticion explicita del cliente — el dato es
           accesible al filtrar o desde el modal de detalles). */
        .alm-table tr.alm-row td.alm-td-cat { display: none !important; }

        /* Fila 2: stock spans cols 1-2 (right-aligned) | um col 3.
           Visual: "5.000 UND" como par pegado al borde derecho. */
        .alm-table tr.alm-row td.alm-td-stock {
            grid-area: stock !important;
            padding: 6px 4px 6px 12px !important;
            font-size: 17px !important;
            font-weight: 800 !important;
            color: #0f172a !important;
            justify-content: flex-end !important;
            gap: 4px !important;
            text-align: right !important;
        }
        .alm-table tr.alm-row td.alm-td-um {
            grid-area: um !important;
            padding: 6px 12px 6px 0 !important;
            font-size: 12px !important;
            font-weight: 700 !important;
            color: #475569 !important;
            justify-content: flex-start !important;
            text-transform: uppercase !important;
        }

        /* Fila 3: stepper de cantidad (izq, 2 cols) | boton ver detalle (der).
           Borde superior sutil. Si no hay permiso almacen.movimiento, alm-td-cant
           no se renderiza y det queda solo a la derecha (sin huecos raros). */
        .alm-table tr.alm-row td.alm-td-cant {
            grid-area: cant !important;
            padding: 6px 12px 8px !important;
            border-top: 1px solid #f1f5f9 !important;
            justify-content: flex-start !important;
        }
        .alm-table tr.alm-row td.alm-td-det {
            grid-area: det !important;
            padding: 6px 12px 8px !important;
            border-top: 1px solid #f1f5f9 !important;
            justify-content: flex-end !important;
        }
        /* Estado vacío / sin almacén: el <tr><td colspan> sin tarjeta */
        .alm-table tbody tr:not(.alm-row) {
            display: block !important;
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            padding: 0 !important;
        }
        .alm-table tbody tr:not(.alm-row) td {
            display: block !important;
            text-align: center !important;
            border: none !important;
            padding: 36px 16px !important;
            font-size: 14px !important;
        }
    }
</style>

@php
    $reqAlm    = $almacenSel?->ID_ALMACEN;
    $reqBuscar = request('search');
    $reqCat    = request('categoria');
    // Permisos:
    //   $puedeAlmManage  → super.admin (CRUD de almacenes / warehouses).
    //   $puedeProductos  → almacen.productos (CRUD del catalogo de productos).
    //   $puedeMover      → almacen.movimiento (entradas/salidas/ajustes/traspasos + confirmar recepciones).
    //   $puedeManage     → flag combinado (almacenes O productos) que controla la
    //                      ENTRADA al bloque JS compartido — los routes individuales
    //                      adentro hacen el check fino segun la accion.
    $puedeAlmManage = auth()->user()?->can('super.admin')        ?? false;
    $puedeProductos = auth()->user()?->can('almacen.productos')  ?? false;
    $puedeManage    = $puedeAlmManage || $puedeProductos;
    $puedeMover     = auth()->user()?->can('almacen.movimiento') ?? false;
    $st = $stats ?? ['total' => '—', 'con_saldo' => '—', 'stock_bajo' => '—', 'unidades' => 0];
    // Datos de los almacenes para el modal de edición (solo se usa si $puedeAlmManage).
    $almacenesData = ($almacenes ?? collect())->keyBy('ID_ALMACEN')->map(function ($a) {
        return [
            'NOMBRE'            => $a->NOMBRE,
            'TIPO'              => $a->TIPO,
            'UBICACION'         => $a->UBICACION,
            'ALMACENISTA'       => $a->ALMACENISTA,
            'CARGO_ALMACENISTA' => $a->CARGO_ALMACENISTA,
            'frentes'           => $a->relationLoaded('frentes') ? $a->frentes->pluck('ID_FRENTE')->values() : [],
        ];
    });
@endphp

<section class="page-title-card" style="text-align:left;margin:0 0 10px 0;">
    {{-- Layout: título a la izquierda + separador vertical + filtro de almacén con su mini-label "ALMACÉN".
         El bloque del filtro tiene un fondo gris suave para diferenciarse del título sin competir con él. --}}
    <div style="display:flex;justify-content:flex-start;align-items:center;gap:20px;flex-wrap:wrap;">
        <h1 class="page-title" style="margin:0;">
            <span class="page-title-line2" style="color:#000;">Inventario de Almacén</span>
        </h1>
        {{-- Separador vertical (oculto en mobile cuando el filtro se va abajo) --}}
        <span aria-hidden="true" style="display:inline-block;width:1px;height:34px;background:#cbd5e0;flex:0 0 auto;"></span>
        <div style="display:flex;align-items:center;gap:10px;flex:0 1 auto;">
            <span style="font-size:10.5px;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:1px;white-space:nowrap;">Almacén</span>
            <div style="width:280px;min-width:200px;max-width:100%;">
                <div class="custom-dropdown" id="almSelAlmacenDropdown" data-filter-type="id_almacen" data-default-label="Todos los almacenes">
                    <input type="hidden" name="id_almacen" data-filter-value id="almSelAlmacen" value="{{ $reqAlm ?? '' }}">
                    <div class="dropdown-trigger {{ $almacenSel ? 'filter-active' : '' }}" style="padding:0;display:flex;align-items:center;background:#f8fafc;overflow:hidden;border:1px solid #cbd5e0;border-radius:10px;height:40px;transition:border-color .15s,background .15s;">
                        <span style="padding:0 10px;display:flex;align-items:center;color:#0067b1;"><i class="material-icons" style="font-size:18px;transform:none !important;">warehouse</i></span>
                        <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                               placeholder="{{ $almacenSel ? $almacenSel->NOMBRE : 'Todos los almacenes' }}"
                               style="flex:1;border:none;background:transparent;padding:8px 5px;font-size:13.5px;font-weight:600;color:#0f172a;outline:none;min-width:0;"
                               oninput="window.filterDropdownOptions(this)">
                        <i class="material-icons" data-clear-btn style="padding:0 8px;color:#64748b;font-size:18px;display:{{ $almacenSel ? 'block' : 'none' }};cursor:pointer;transform:none !important;"
                           onclick="event.stopPropagation(); clearDropdownFilter('almSelAlmacenDropdown');">close</i>
                    </div>
                    <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                        <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                            @foreach($almacenes as $a)
                                <div class="dropdown-item {{ $almacenSel && $almacenSel->ID_ALMACEN == $a->ID_ALMACEN ? 'selected' : '' }}" data-value="{{ $a->ID_ALMACEN }}"
                                     onclick="selectOption('almSelAlmacenDropdown','{{ $a->ID_ALMACEN }}','{{ addslashes($a->NOMBRE) }}');">
                                    {{ $a->NOMBRE }}{{ $a->TIPO === 'GENERAL' ? '' : ' (Proyecto)' }}
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

    {{-- ── Banner: envíos por recibir (módulo Recepción) ── --}}
    @if(($traspasosPorRecibir ?? 0) > 0)
        <a href="{{ route('almacen.recepcion.index') }}"
           style="display:flex;align-items:center;justify-content:space-between;gap:12px;background:linear-gradient(135deg,#fef3c7 0%,#fde68a 100%);border:1px solid #f59e0b;border-radius:10px;padding:10px 14px;margin-bottom:12px;text-decoration:none;color:#92400e;">
            <span style="display:flex;align-items:center;gap:10px;">
                <i class="material-icons" style="font-size:22px;color:#b45309;">notifications_active</i>
                <span style="font-size:13.5px;font-weight:700;">
                    Tienes <strong style="font-size:15px;">{{ $traspasosPorRecibir }}</strong>
                    {{ $traspasosPorRecibir === 1 ? 'envío pendiente' : 'envíos pendientes' }} por recibir en tus almacenes
                </span>
            </span>
            <span style="display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;">
                Revisar <i class="material-icons" style="font-size:18px;">arrow_forward</i>
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
        <div class="alm-filter {{ $bActivo ? 'active' : '' }}" style="flex:3 1 340px;max-width:560px;">
            <div class="alm-filter-box">
                <span class="alm-ic"><i class="material-icons" style="font-size:18px;">search</i></span>
                <input type="text" id="almFiltroBuscar" autocomplete="off"
                       placeholder="{{ $bActivo ?: 'Buscar por código o descripción…' }}"
                       value=""
                       data-active="{{ $bActivo }}"
                       data-placeholder-empty="Buscar por código o descripción…"
                       oninput="window.almBuscarInput()" onfocus="window.almBuscarSuggest()"
                       onkeydown="window.almBuscarEnter(event)">
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
                       oninput="window.almCatInput()" onfocus="window.almCatSuggest()"
                       onkeydown="window.almCatEnter(event)">
                <i class="material-icons filter-clear" style="display:{{ $cActivo ? 'flex' : 'none' }};"
                   onclick="window.almCatLimpiar()">close</i>
            </div>
            <div class="alm-suggest" id="almFiltroCatSuggest"></div>
        </div>

        {{-- Acciones (botón desplegable estilo /admin/equipos) --}}
        <div style="display:flex;gap:8px;margin-left:auto;flex:0 0 auto;align-items:center;">
            <div style="position:relative;">
                <button type="button" id="almBtnAcciones" class="btn-primary-maquinaria"
                        style="height:45px;padding:0 16px;display:flex;align-items:center;gap:8px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);"
                        onclick="window.almToggleAcciones(event)">
                    <i class="material-icons" style="font-size:18px;">settings</i><span class="desktop-text">Acciones</span><i class="material-icons" style="font-size:18px;">expand_more</i>
                </button>
                <div id="almAccionesMenu" style="display:none;position:absolute;top:100%;right:0;width:280px;background:#e2e8f0;border-radius:8px;box-shadow:0 10px 18px -3px rgba(0,0,0,0.18);border:1px solid #e2e8f0;z-index:60;margin-top:6px;overflow:hidden;animation:slideDown 0.18s ease-out;">
                    {{-- Descargar Excel: disponible para cualquier usuario que pueda ver el
                         módulo. Construye la URL de export respetando el filtro de almacén
                         actual. Como ahora todos los items siguen visibles bajo el de export,
                         el border-bottom se pinta siempre. --}}
                    <button type="button" onclick="window.almAccion('export')" class="dropdown-item-custom" style="display:flex;align-items:center;gap:10px;padding:11px 14px;color:#475569;background:transparent;border:none;border-bottom:1px solid #f1f5f9;width:100%;text-align:left;cursor:pointer;">
                        <div style="background:#dcfce7;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#16a34a;">download</i></div>
                        <span style="font-size:14px;font-weight:500;">Descargar Excel</span>
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
                    <button type="button" onclick="window.almAccion('producto')" class="dropdown-item-custom" style="display:flex;align-items:center;gap:10px;padding:11px 14px;color:#475569;background:transparent;border:none;width:100%;text-align:left;cursor:pointer;">
                        <div style="background:#e0f2fe;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#0284c7;">add_circle</i></div>
                        <span style="font-size:14px;font-weight:500;">Nuevo producto</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Tabla ── --}}
    <div class="alm-table-wrap" style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:12px;">
        <table class="alm-table">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Descripción del producto</th>
                    <th style="text-align:center;">UND</th>
                    <th>Categoría</th>
                    <th style="text-align:center;">Stock</th>
                    @if($puedeMover ?? false)
                    {{-- Cant. salida: input habilitado solo cuando la fila está seleccionada.
                         Al confirmar la Nota de Entrega, se toman las cantidades de cada fila
                         seleccionada — sin pantalla intermedia "Productos" en el modal. --}}
                    <th style="text-align:center;width:110px;">Cant. salida</th>
                    @endif
                    <th style="text-align:center;width:60px;">Detalles</th>
                </tr>
            </thead>
            <tbody id="almTableBody">
                @include('admin.almacen.partials.table_rows', ['productos' => $productos, 'almacen' => $almacenSel, 'inicial' => true])
            </tbody>
        </table>
    </div>

    {{-- Indicador opcional cuando el scroll infinito está cargando más filas. --}}
    <div id="almLoadingMore" style="display:none;text-align:center;padding:12px;color:#64748b;font-size:12.5px;font-weight:600;">
        <i class="material-icons" style="font-size:16px;vertical-align:middle;animation:spin 1s linear infinite;">refresh</i>
        Cargando más productos…
    </div>
</div>

{{-- ── Sidebar: Consolidado de Inventario ── --}}
<div class="counter-sidebar" style="position:sticky;top:20px;display:flex;flex-direction:column;gap:8px;">

    <div style="background:linear-gradient(135deg,#1a365d 0%,#2c5282 100%);border-radius:12px;padding:15px;color:white;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);position:relative;overflow:hidden;">
        <i class="material-icons" style="position:absolute;right:-15px;bottom:-15px;font-size:80px;opacity:0.1;transform:rotate(-15deg);">inventory</i>
        <div style="position:relative;z-index:2;">
            <div class="alm-consolidado-title" style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;opacity:0.8;margin-bottom:4px;display:flex;align-items:center;gap:6px;">
                <i class="material-icons" style="font-size:14px;">pie_chart</i> Consolidado de Inventario
            </div>

            <div style="display:flex;align-items:center;gap:8px;">
                <div onclick="window.almVerTodo()" title="Quitar filtros"
                     style="display:flex;flex-direction:column;align-items:center;background:rgba(255,255,255,0.15);padding:8px 6px;border-radius:10px;min-width:65px;cursor:pointer;">
                    <span id="almStatsTotal" style="font-size:34px;font-weight:800;line-height:1;">{{ $st['total'] }}</span>
                    <span style="font-size:12px;opacity:0.8;font-weight:700;margin-top:2px;">PRODUCTOS</span>
                </div>
                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:4px;flex:1;">
                    <div id="almBadgeConSaldo" onclick="window.almFiltrarConSaldo()" title="Solo con saldo (clic para activar/desactivar)"
                         style="display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(34,197,94,0.15);padding:6px 2px;border-radius:8px;border:1px solid rgba(34,197,94,0.25);cursor:pointer;transition:background .15s,border-color .15s,box-shadow .15s;">
                        <i class="material-icons" style="font-size:18px;color:#22c55e;margin-bottom:2px;">inventory_2</i>
                        <strong id="almStatsConSaldo" style="font-weight:800;font-size:16px;color:white;">{{ $st['con_saldo'] }}</strong>
                        <span style="font-size:10.5px;opacity:0.9;font-weight:700;text-transform:uppercase;">Con stock</span>
                    </div>
                    <div id="almBadgeBajo" onclick="window.almFiltrarBajo()" title="Solo stock bajo (clic para activar/desactivar)"
                         style="display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(245,158,11,0.18);padding:6px 2px;border-radius:8px;border:1px solid rgba(245,158,11,0.3);cursor:pointer;transition:background .15s,border-color .15s,box-shadow .15s;">
                        <i class="material-icons" style="font-size:18px;color:#f59e0b;margin-bottom:2px;">warning</i>
                        <strong id="almStatsBajo" style="font-weight:800;font-size:16px;color:white;">{{ $st['stock_bajo'] }}</strong>
                        <span style="font-size:10.5px;opacity:0.9;font-weight:700;text-transform:uppercase;">Stock bajo</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div style="background:white;border-radius:12px;padding:15px;border:1px solid #e2e8f0;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);overflow:hidden;">
        <div id="almDistribucionContainer">
            @if($distribucion && $distribucion->isNotEmpty())
                @include('admin.almacen.partials.distribucion_stats', ['distribucion' => $distribucion])
            @endif
        </div>
    </div>
</div>

</div>{{-- /page-layout-grid --}}

@if($puedeMover)
{{-- ── Barra flotante de selección (igual que /admin/equipos: clic en la fila → se resalta y aparece esta barra) ── --}}
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
        <button type="button" onclick="window.almSelAccion()" class="btn-bulk-action" style="background:#dc2626;">
            <i class="material-icons" style="font-size:18px;">north_east</i><span class="desktop-text">Salida</span>
        </button>
    </div>
</div>
@endif

{{-- ════════════════════════ MODALES ════════════════════════ --}}

{{-- Modal antiguo "Registrar entrada / Registrar salida" por producto individual:
     ELIMINADO en 2026-05-13. Las entradas reales ahora se hacen desde
     /admin/almacen/recepcion (modal "Entrada directa") y las salidas desde
     este mismo módulo seleccionando filas + barra flotante (Nota de Entrega).
     Para correcciones puntuales del saldo de un producto se usa el modal
     "Auditoría de Inventario" que sigue abajo. --}}

{{-- Auditoría de Inventario (ajuste del saldo + stock mínimo) --}}
<div id="almAjusteModal" class="alm-modal-overlay">
    <div class="alm-modal">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">fact_check</i> Auditoría de Inventario</h3>
            <i class="material-icons alm-x" onclick="almCerrar('almAjusteModal')">close</i>
        </div>
        <div class="alm-modal-body">
            <div>
                {{-- "Producto" rotula un texto fijo, no un campo editable: usamos <div> en vez de <label>. --}}
                <div class="alm-fake-label">Producto</div>
                <div><strong id="almAjNombre" style="font-size:12.5px;color:#1e293b;"></strong></div>
            </div>
            <div>
                <label for="almAjNuevoSaldo">Saldo según conteo físico</label>
                <input type="number" id="almAjNuevoSaldo" min="0" step="any" placeholder="Dejar vacío si solo cambias el mínimo">
                <small style="display:block;font-size:11px;color:#64748b;margin-top:3px;line-height:1.4;">
                    La diferencia se registra en la bitácora como <b>Auditoría</b>.
                </small>
            </div>
            <div>
                <label for="almAjMinimo">Stock mínimo (alerta)</label>
                {{-- min="0.001" + step="any": cualquier valor > 0 vale (no se acepta 0). Vacio = sin alerta. --}}
                <input type="number" id="almAjMinimo" min="0.001" step="any" placeholder="Vacío = sin alerta">
            </div>
            <div><label for="almAjMotivo">Motivo / observaciones de la auditoría</label><input type="text" id="almAjMotivo" maxlength="200" placeholder="Ej: conteo trimestral, merma detectada…"></div>
            <div id="almAjError" style="display:none;color:#dc2626;font-size:13px;font-weight:600;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almAjusteModal')">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" onclick="window.almGuardarAjuste()">Guardar</button>
        </div>
    </div>
</div>

{{-- ═════════════════════════════════════════════════════════════════
     Modal: KARDEX por producto (Movimientos del producto)
     Se abre desde el modal de Detalles (botón "Ver movimientos").
     Reusa AlmacenController::movimientos con ?mini=1 y filtra por
     id_producto + id_almacen actual + opcional tipo / desde / hasta.
═════════════════════════════════════════════════════════════════ --}}
<div id="almKardexProductoModal" class="alm-modal-overlay">
    <div class="alm-modal" style="max-width:680px;">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">history</i> Movimientos del producto</h3>
            <i class="material-icons alm-x" onclick="almCerrar('almKardexProductoModal')">close</i>
        </div>
        <div class="alm-modal-body" style="gap:10px;">
            {{-- Cabecera con info del producto + saldo en el almacén actual --}}
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <span class="alm-pill" id="almKpCodigo">—</span>
                <strong id="almKpNombre" style="font-size:11.5px;color:#1e293b;flex:1;min-width:140px;"></strong>
                <span style="font-size:10.5px;color:#64748b;">Stock actual: <strong id="almKpSaldo" style="color:#0f172a;font-size:11.5px;">0</strong> <span id="almKpUm" style="font-size:10px;color:#64748b;"></span></span>
            </div>

            {{-- Filtros: select Tipo + rango de fechas --}}
            <div style="display:flex;align-items:center;gap:15px;flex-wrap:wrap;background:#fff;padding:4px 0;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:10.5px;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;">Tipo:</span>
                    <select id="almKpTipoSelect" onchange="window.almKpChipSelect(this.value)"
                            style="height:30px;padding:0 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;color:#334155;background:#fff;cursor:pointer;">
                        <option value="">Todos</option>
                        <option value="ENTRADA">Entradas</option>
                        <option value="SALIDA">Salidas</option>
                        <option value="AJUSTE">Auditorías de conteo</option>
                    </select>
                </div>

                <div style="display:flex;align-items:center;gap:6px;flex:1;min-width:280px;">
                    <span style="font-size:10.5px;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;">Fechas:</span>
                    <div style="display:flex;align-items:center;gap:4px;flex-wrap:nowrap;">
                        <input type="date" id="almKpDesde" onchange="window.almKpCargar()"
                               onclick="try{this.showPicker();}catch(e){}"
                               style="height:30px;padding:0 6px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;color:#334155;cursor:pointer;">
                        <span style="color:#94a3b8;font-size:14px;">→</span>
                        <input type="date" id="almKpHasta" onchange="window.almKpCargar()"
                               onclick="try{this.showPicker();}catch(e){}"
                               style="height:30px;padding:0 6px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;color:#334155;cursor:pointer;">
                    </div>
                    <button type="button" onclick="window.almKpLimpiar()" style="background:transparent;border:none;color:#64748b;font-size:11px;font-weight:700;text-decoration:underline;cursor:pointer;margin-left:auto;white-space:nowrap;">Limpiar</button>
                </div>
            </div>

            {{-- Tabla compacta: 5 columnas (sin Producto, ya conocido). El thead
                 queda sticky para que se vea al hacer scroll. --}}
            <div style="overflow:auto;max-height:48vh;border:1px solid #e2e8f0;border-radius:8px;">
                <table style="width:100%;border-collapse:separate;border-spacing:0;">
                    <thead>
                        <tr style="background:#1e293b;color:#fff;position:sticky;top:0;z-index:1;">
                            <th style="width:1%;padding:7px 8px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center;white-space:nowrap;">Fecha</th>
                            <th style="width:1%;padding:7px 8px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center;white-space:nowrap;">Tipo</th>
                            <th style="width:1%;padding:7px 8px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center;white-space:nowrap;">Cantidad</th>
                            <th style="width:1%;padding:7px 8px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center;white-space:nowrap;">Stock</th>
                            <th style="padding:7px 8px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:left;">Destino / Ref</th>
                        </tr>
                    </thead>
                    <tbody id="almKpBody">
                        <tr><td colspan="5" style="text-align:center;padding:30px;color:#94a3b8;font-size:12px;">Cargando…</td></tr>
                    </tbody>
                </table>
            </div>

            <div id="almKpPag" style="font-size:11px;color:#64748b;text-align:center;"></div>
        </div>

    </div>
</div>

<style>
/* La paginación del kardex se aprovecha de la del SSR estándar; aquí se renderiza
   centrada y compacta dentro de #almKpPag. */
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
            <div><label for="almNvNombre">Nombre *</label><input type="text" id="almNvNombre" maxlength="150" placeholder="Ej: ALMACÉN CENTRAL CARACAS"></div>
            <div>
                <label for="almNvTipoDisplay">Tipo *</label>
                <div class="custom-dropdown" id="almNvTipoDropdown" data-default-label="Selecciona un tipo">
                    <input type="hidden" id="almNvTipo" value="PROYECTO">
                    <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:#fbfcfd;overflow:hidden;border:1px solid #cbd5e0;border-radius:10px;height:42px;transition:border-color .15s,background .15s;">
                        <input type="text" data-filter-search autocomplete="off" readonly
                               id="almNvTipoDisplay"
                               value="Proyecto (Limitado a frentes específicos)"
                               style="flex:1;border:none;background:transparent;padding:8px 12px;font-size:13.5px;font-weight:normal;color:#0f172a;outline:none;min-width:0;cursor:pointer;"
                               onclick="this.closest('.dropdown-trigger').style.borderColor='var(--maquinaria-blue,#0067b1)'">
                        <i class="material-icons" style="padding:0 8px;color:#64748b;font-size:20px;">expand_more</i>
                    </div>
                    <div class="dropdown-content" style="padding:5px;">
                        <div class="dropdown-item" data-value="GENERAL"
                             onclick="almNvTipoSelect('GENERAL','Global (Todos los frentes)')">
                            Global (Todos los frentes)
                        </div>
                        <div class="dropdown-item selected" data-value="PROYECTO"
                             onclick="almNvTipoSelect('PROYECTO','Proyecto (Limitado a frentes específicos)')">
                            Proyecto (Limitado a frentes específicos)
                        </div>
                    </div>
                </div>
            </div>
            <div><label for="almNvUbicacion">Ubicación</label><input type="text" id="almNvUbicacion" maxlength="150" placeholder="Opcional" autocomplete="off"></div>
            {{-- Almacenista: nombre del responsable del almacén. Aparecerá como "Entregado por:"
                 en la Nota de Entrega VID-FO-GEN-019. --}}
            <div>
                <label for="almNvAlmacenista">Almacenista *</label>
                <input type="text" id="almNvAlmacenista" maxlength="200" placeholder="Ej: Juan Pérez (almacenista)" autocomplete="off">
                <div style="font-size:11.5px;color:#94a3b8;margin-top:5px;">Aparece como "Entregado por:" en la Nota de Entrega.</div>
            </div>
            {{-- Cargo del almacenista: aparece como "CARGO:" debajo del NOMBRE en la
                 seccion "ENTREGADO POR" del PDF. Sustituye al literal "COORD. DE
                 MATERIALES" que antes estaba hardcodeado en el template. --}}
            <div>
                <label for="almNvCargoAlmacenista">Cargo del almacenista *</label>
                <input type="text" id="almNvCargoAlmacenista" maxlength="200" placeholder="Ej: COORD. DE MATERIALES" autocomplete="off">
                <div style="font-size:11.5px;color:#94a3b8;margin-top:5px;">Aparece como "CARGO:" debajo del nombre en la Nota de Entrega.</div>
            </div>
            <div id="almNvFrentesWrap">
                <label for="almNvFrentesInput">Frentes que usan este almacén *</label>
                <div class="custom-multiselect" id="almNvFrentesSelect">
                    {{-- El trigger es un input directo: clic lo abre y escribir filtra la lista de abajo. --}}
                    <div class="multiselect-trigger" tabindex="-1" role="button" aria-haspopup="listbox" style="padding:0;display:flex;align-items:center;overflow:hidden;cursor:text;">
                        <input type="text" id="almNvFrentesInput" autocomplete="off"
                               placeholder="Selecciona los frentes…"
                               style="flex:1;border:none;background:transparent;padding:12px 15px;font-size:15px;outline:none;min-width:0;color:#0f172a;"
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
                <div style="font-size:11.5px;color:#94a3b8;margin-top:5px;">Varios proyectos pueden compartir un mismo almacén.</div>
            </div>
            <div id="almNvError" style="display:none;margin-top:6px;padding:9px 12px;background:#fee2e2;border:1px solid #fecaca;border-radius:8px;color:#b91c1c;font-size:13px;font-weight:600;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almAlmacenModal')">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" id="almNvSubmit" onclick="window.almGuardarAlmacen()">Guardar</button>
        </div>
    </div>
</div>

@endif

@if($puedeProductos)
{{-- Nuevo / Editar producto — usuarios con almacen.productos. --}}
<div id="almProductoModal" class="alm-modal-overlay">
    <div class="alm-modal">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">add_circle</i> <span id="almProdTitulo">Nuevo producto</span></h3>
            <i class="material-icons alm-x" onclick="almCerrar('almProductoModal')">close</i>
        </div>
        <div class="alm-modal-body">
            <div style="display:flex;gap:10px;">
                <div style="flex:1;"><label for="almProdCodigo">Código</label><input type="text" id="almProdCodigo" maxlength="20" inputmode="numeric" pattern="[0-9]*" placeholder="Número (opcional)" autocomplete="off"></div>
                <div style="flex:0.9;position:relative;">
                    <label for="almProdUm">Unidad de Medida *</label>
                    <input type="text" id="almProdUm" maxlength="20" placeholder="UND, KG, LTS..." value="UND" autocomplete="off"
                           oninput="window.almProdUmSuggest()" onfocus="window.almProdUmSuggest(true)"
                           style="width:100%;box-sizing:border-box;">
                    <div class="alm-suggest-inline" id="almProdUmSuggestBox" style="position:absolute;top:100%;left:0;right:0;z-index:9999;margin-top:2px;"></div>
                </div>
            </div>
            <div><label for="almProdNombre">Descripción / producto *</label><input type="text" id="almProdNombre" maxlength="200" autocomplete="off" placeholder="Ej: TORNILLO HEXAGONAL 1/2&quot;"></div>
            {{-- Categoría (ancha, con suggest) + Cantidad inicial (angosta, solo al crear).
                 Misma fila usando display:flex — igual patrón que Código + UM más arriba. --}}
            <div style="display:flex;gap:10px;align-items:flex-start;">
                <div style="flex:1.5;">
                    <label for="almProdCategoria">Categoría</label>
                    <div class="alm-cat-field">
                        <input type="text" id="almProdCategoria" autocomplete="off" maxlength="100"
                               placeholder="Elige una de la lista o escribe una nueva…"
                               oninput="window.almProdCatSuggest()" onfocus="window.almProdCatSuggest(true)"
                               onclick="event.stopPropagation(); window.almProdCatSuggest(true);">
                        <button type="button" class="alm-cat-caret" id="almProdCatCaret" tabindex="-1" title="Ver categorías registradas"
                                onclick="window.almProdCatToggle(event)"><i class="material-icons">arrow_drop_down</i></button>
                    </div>
                    <div class="alm-suggest-inline" id="almProdCatSuggest"></div>
                    <div style="font-size:11.5px;color:#94a3b8;margin-top:4px;">Elige de la lista o escribe una nueva categoría.</div>
                </div>
                {{-- Cantidad inicial: solo se muestra al CREAR (no al editar). Si está vacío o en 0
                     el producto queda registrado en el almacén actual con stock 0 (asegurarStock).
                     Si > 0, además se registra una ENTRADA en el kardex como "STOCK INICIAL". --}}
                <div id="almProdCantInicialWrap" style="flex:1;">
                    <label for="almProdCantInicial">Cantidad inicial</label>
                    <input type="number" id="almProdCantInicial" min="0" step="any" placeholder="0 (opcional)" autocomplete="off">
                    <div style="font-size:11.5px;color:#94a3b8;margin-top:4px;">Vacío o 0 → stock 0 en este almacén.</div>
                </div>
            </div>
            {{-- Ubicación física en bodega (texto libre). Se muestra como tooltip al pasar el
                 mouse sobre la fila en la tabla — mismo patrón que DETALLE_UBICACION en /admin/equipos. --}}
            <div>
                <label for="almProdUbicacion">Ubicación en bodega</label>
                <input type="text" id="almProdUbicacion" maxlength="150" autocomplete="off"
                       placeholder="Ej: Estante A3, Pasillo 2 lado izquierdo…">
                <div style="font-size:11.5px;color:#94a3b8;margin-top:4px;">Aparecerá como tooltip al pasar el mouse sobre la fila.</div>
            </div>
            <div id="almProdError" style="display:none;color:#dc2626;font-size:13px;font-weight:600;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almProductoModal')">Cancelar</button>
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
                            <div style="font-size:11.5px;color:#94a3b8;">{{ $a->TIPO === 'GENERAL' ? 'Principal' : 'Proyecto' }}{{ $a->CODIGO ? ' · '.$a->CODIGO : '' }}{{ $a->TIPO === 'PROYECTO' ? ' · '.$a->frentes_count.' frente(s)' : '' }}{{ $a->UBICACION ? ' · '.$a->UBICACION : '' }}</div>
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
    <div class="alm-modal" style="max-width:480px;">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">inventory_2</i> Detalles del producto</h3>
            <i class="material-icons alm-x" onclick="almCerrar('almDetalleModal')">close</i>
        </div>
        <div class="alm-modal-body">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div style="background:#f8fafc;border-radius:8px;padding:10px;">
                    <div style="font-size:10.5px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.3px;">Categoría</div>
                    <div id="almDetCat" style="font-size:14px;font-weight:700;color:#334155;margin-top:2px;"></div>
                </div>
                <div style="background:#f8fafc;border-radius:8px;padding:10px;">
                    <div style="font-size:10.5px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.3px;">Stock mínimo</div>
                    <div id="almDetMin" style="font-size:14px;font-weight:700;color:#334155;margin-top:2px;"></div>
                </div>
            </div>
            {{-- Aviso de stock bajo en este almacén. Misma paleta que .alm-row-bajo en la
                 tabla (#fee2e2 / #fecaca / #b91c1c) para que el usuario asocie ambos avisos.
                 Layout flex: icono a la izquierda con su propio ancho fijo, texto fluido a la
                 derecha en 2 líneas equilibradas — antes el texto largo + icono inline se veía
                 como un párrafo desordenado. --}}
            <div id="almDetBajoBadge" style="display:none;background:#fee2e2;border:1px solid #fecaca;color:#b91c1c;border-radius:8px;padding:10px 14px;align-items:center;gap:10px;">
                <i class="material-icons" style="font-size:20px;flex:0 0 auto;">warning</i>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;font-weight:800;line-height:1.2;">Stock bajo en este almacén</div>
                    <div style="font-size:11.5px;font-weight:500;line-height:1.35;margin-top:2px;opacity:0.85;">El saldo está en o por debajo del mínimo configurado.</div>
                </div>
            </div>
            <div style="border-top:1px solid #f1f5f9;padding-top:12px;display:flex;flex-direction:column;gap:7px;">
                {{-- Botones SIEMPRE visibles. La verificacion de permiso vive dentro de
                     almDetalleAccion / almAbrirAjuste / almEditarProducto / almEliminarProducto
                     — si el usuario no tiene la clave necesaria, salta toast moderno con la
                     razon. Antes se ocultaban; el cliente pidio "ver botones + notificacion". --}}
                <button type="button" class="alm-det-act" onclick="window.almDetalleAccion('ajuste')"><span class="alm-det-ic" style="background:#dbeafe;color:#0067b1;"><i class="material-icons" style="font-size:18px;">fact_check</i></span> Auditoría de Inventario</button>
                {{-- En mobile (≤768px) el modal "Movimientos del producto" es
                     un kardex tabular pesado; el cliente prefirio ocultarlo en
                     telefono para mantener el modal de detalles compacto. La
                     clase .alm-det-act-kardex permite el override CSS. --}}
                <button type="button" class="alm-det-act alm-det-act-kardex" onclick="window.almDetalleAccion('kardex')"><span class="alm-det-ic" style="background:#f1f5f9;color:#475569;"><i class="material-icons" style="font-size:18px;">history</i></span> Ver movimientos del producto</button>
                <button type="button" class="alm-det-act" onclick="window.almDetalleAccion('editar')"><span class="alm-det-ic" style="background:#cffafe;color:#0891b2;"><i class="material-icons" style="font-size:18px;">edit</i></span> Editar producto</button>
                <button type="button" class="alm-det-act" onclick="window.almDetalleAccion('eliminar')"><span class="alm-det-ic" style="background:#fee2e2;color:#ef4444;"><i class="material-icons" style="font-size:18px;">delete_outline</i></span> Eliminar / desactivar producto</button>
            </div>
        </div>

</div>
</div>

@if($puedeMover)
{{-- ── Salida: un solo formulario unificado. Siempre llena la Nota de Entrega VID-FO-GEN-019
     (proyecto + contrato + fecha + RQ + solicitante + dpto). El backend decide si la salida
     es CONSUMO (mismo almacén del origen) o TRASPASO (envío a otro almacén) según el frente
     elegido en "Proyecto destino" — ambos casos generan Nota de Entrega NE-YYYY-NNNN. ── --}}
<div id="almSalidaModal" class="alm-modal-overlay">
    {{-- max-width reducido a 600px (de 680) — cliente pidio comprimir un poco mas.
         El layout de 2-3 columnas (Proyecto/Contrato, Fecha/RQ/Solic) sigue
         acomodando bien — los inputs heredan width:100% del .alm-nota-input. --}}
    <div class="alm-modal alm-modal-wide" style="max-width:600px;">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">north_east</i> <span>Registrar salida</span></h3>
            <i class="material-icons alm-x" onclick="almCerrar('almSalidaModal')">close</i>
        </div>
        <div class="alm-modal-body">

            {{-- Cabecera tipo "Nota de Entrega de Materiales" ─────────────────────────────
                 Layout inspirado en el Excel VID-FO-GEN-019, optimizado para ocupar menos
                 alto vertical:
                   PROYECTO (2fr) | CONTRATO N° (1fr)                (misma fila)
                   FECHA DE ENTREGA | RQ N° | Solicitante            (3 columnas)
                   DEPARTAMENTO                                      (full)
                   OBSERVACIONES                                     (full) --}}
            <div id="almSalidaNotaWrap" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:14px;">
                {{-- NOTA: la metadata del formato (CÓDIGO VID-FO-GEN-019, FECHA EMIS, REV)
                     y el título "Nota de Entrega de Materiales" se incluyen SOLO en el PDF
                     generado por NotaEntregaPDF. En el modal son ruido visual — el título
                     del modal ("Registrar salida") ya identifica suficientemente el formulario. --}}

                {{-- PROYECTO (ancho) | CONTRATO N° (estrecho) — misma fila.
                     Contrato N° es input + caret (igual patron que "Categoria" del modal de
                     producto): al elegir proyecto destino, el dropdown se abre automaticamente
                     con los contratos registrados de ese frente — el usuario elige uno, escribe
                     uno nuevo, o deja en blanco. --}}
                <div style="display:grid;grid-template-columns:2fr 1fr;gap:10px;margin-bottom:10px;align-items:start;">
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
                                       style="flex:1;border:none;background:transparent;padding:0 10px;font-size:13.5px;font-weight:600;color:#0f172a;outline:none;min-width:0;"
                                       oninput="window.filterDropdownOptions(this)">
                                <i class="material-icons" data-clear-btn style="padding:0 8px;color:#64748b;font-size:18px;display:none;cursor:pointer;"
                                   onclick="event.stopPropagation(); clearDropdownFilter('almSalidaProyectoDropdown');">close</i>
                                <i class="material-icons" style="padding:0 8px;color:#94a3b8;font-size:20px;">expand_more</i>
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
                    <div>
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
                                       placeholder="Selecciona o escribe (opcional)"
                                       style="flex:1;border:none;background:transparent;padding:0 10px;font-size:13.5px;font-weight:600;color:#0f172a;outline:none;min-width:0;"
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

                {{-- FECHA DE ENTREGA | RQ N° | Solicitante (3 columnas en una sola fila — como en el Excel) --}}
                <div style="display:grid;grid-template-columns:1fr 1fr 1.4fr;gap:10px;margin-bottom:10px;">
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
                    <div>
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
                    <input type="text" id="almSalidaDepartamento" class="alm-nota-input" maxlength="150" placeholder="Ej: Mantenimiento" autocomplete="off">
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
                 seleccionada tiene su propio input "Cant. salida". Este modal solo
                 recoge los datos de la Nota de Entrega y los cruza con almSeleccion. --}}

            <div id="almSalidaError" style="display:none;color:#dc2626;font-size:13px;font-weight:600;margin-top:6px;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="almCerrar('almSalidaModal')">Cancelar</button>
            {{-- "Vista previa" en vez de "Registrar salida": el flujo de salida ahora pasa
                 por el modal #almPreviewModal donde el usuario revisa el PDF y aprieta
                 "Registrar" para que sea oficial. --}}
            <button type="button" class="btn-primary-maquinaria" onclick="window.almSalidaVistaPrevia()"><i class="material-icons" style="font-size:17px;vertical-align:-3px;margin-right:4px;">visibility</i>Vista previa</button>
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
            <h3><i class="material-icons" style="font-size:20px;color:#0067b1;">visibility</i> <span>Vista previa de la Nota de Entrega</span></h3>
            <i class="material-icons alm-x" onclick="window.almPreviewCerrar()">close</i>
        </div>
        <div class="alm-modal-body" style="padding:0;gap:0;background:#475569;">
            {{-- Iframe ocupa toda el area disponible del modal. min-height fija un piso
                 razonable para que el PDF no se vea aplastado en pantallas pequeñas. --}}
            <iframe id="almPreviewFrame" src="about:blank" style="width:100%;height:82vh;min-height:560px;border:none;background:#fff;" title="Vista previa Nota de Entrega"></iframe>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="window.almPreviewEditar()"><i class="material-icons" style="font-size:17px;vertical-align:-3px;margin-right:4px;">edit</i>Editar</button>
            <button type="button" class="btn-primary-maquinaria" onclick="window.almPreviewConfirmar()"><i class="material-icons" style="font-size:17px;vertical-align:-3px;margin-right:4px;">check_circle</i>Registrar</button>
        </div>
    </div>
</div>
@endif

<script>
(function () {
    'use strict';
    // Guard: si el módulo se re-monta (navegación SPA) no re-bindear listeners
    // de documento; las funciones window.alm* del primer montaje siguen válidas.
    if (window.__almIndexInit) {
        if (typeof window.almResetBadges === 'function') window.almResetBadges();
        return;
    }
    window.__almIndexInit = true;

    var ROUTE_INDEX = @json(route('almacen.index'));
    // ROUTE_LOTE cubre TODOS los movimientos: ENTRADA, SALIDA (consumo) y SALIDA hacia otro
    // proyecto (el backend crea internamente el Traspaso). El frontend solo conoce este endpoint.
    var ROUTE_LOTE  = @json(route('almacen.movimientos.lote'));
    // ── Flags de permiso del usuario actual, leidos desde Blade ──
    // Cada funcion CRUD verifica el flag relevante antes de actuar; si falta, salta
    // toast en lugar de ejecutar. Antes los botones se ocultaban; el cliente pidio
    // "ver todo + notificacion" para que ningun acceso quede silencioso.
    var HAS_ALM_MANAGE = @json($puedeAlmManage);
    var HAS_PRODUCTOS  = @json($puedeProductos);
    var HAS_MOVER      = @json($puedeMover);
    // Helper: chequea permiso y si falta, emite toast con la razon. Devuelve true
    // si el usuario PASA (puede proceder). Asi las funciones se leen como:
    //   if (!ensurePerm(HAS_ALM_MANAGE, 'No tienes permiso para crear almacenes.')) return;
    function ensurePerm(flag, msg) {
        if (flag) return true;
        if (window.showToast) window.showToast(msg, 'error'); else alert(msg);
        return false;
    }
    // Endpoint del preview PDF (sin commit a BD) — se usa antes del registro real
    // para que el usuario vea como quedaria la Nota y pueda editar/confirmar.
    var ROUTE_PREVIEW_SALIDA = @json(route('almacen.salida.preview'));
    var ROUTE_PROD  = @json(route('almacen.productos.store'));
    // Catálogo de productos (CODIGO/NOMBRE/UM) — lista global, alimenta los selects de los modales
    // (Nuevo/Editar producto, modal de salida con productos seleccionados, etc.).
    window.almProductosLista = @json($productosLista ?? collect());
    // Mapa { ID_ALMACEN: [ID_PRODUCTO, ...] } — productos que SÍ tienen fila en `almacen_stock`
    // para cada almacén. Lo usan las sugerencias del filtro "Buscar" para acotar a productos
    // que realmente aparecerán en la tabla (la tabla usa INNER JOIN con almacen_stock).
    window.almProductosEnAlmacen = @json($productosEnAlmacen ?? collect());
    // Categorías ya registradas — alimentan la lista del campo "Categoría" del modal de producto.
    window.almCategoriasLista = @json(($categorias ?? collect())->filter()->values());
    // Unidades de medida distintas ya registradas — alimentan el autocomplete del campo "UM" del modal.
    window.almUnidadesMedida = @json($unidadesMedida ?? []);
    // Mapa { ID_FRENTE: ["CTR-2026-0042", ...] } para sugerir contratos en el modal "Registrar salida".
    // Los contratos se gestionan en /admin/frentes (columna CONTRATOS JSON de frentes_trabajo).
    window.almFrenteContratos = @json(($frentesLista ?? collect())->mapWithKeys(fn ($f) => [$f->ID_FRENTE => array_values(array_filter((array) ($f->CONTRATOS ?? [])))]));
    function ROUTE_MIN(idAlm)   { return ROUTE_INDEX + '/almacenes/' + idAlm + '/minimo'; }
    function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.getAttribute('content') : ''; }
    function toast(msg, type) { if (window.showToast) window.showToast(msg, type || 'success'); else if (type === 'error') alert(msg); }
    function pre()  { if (typeof window.showPreloader === 'function') window.showPreloader(); }
    function unpre(){ if (typeof window.hidePreloader === 'function') window.hidePreloader(); }
    function el(id){ return document.getElementById(id); }
    function val(id){ var e = el(id); return e ? String(e.value).trim() : ''; }
    function escHtml(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c]; }); }

    // ── estado de los filtros que no tienen control visible propio ──
    // Estos dos atajos del header (Con stock / Stock bajo) son SIEMPRE off al entrar al
    // modulo, sin importar lo que diga la URL. Es preferencia del cliente: "cuando entro
    // al modulo no debe estar nada activo" — ver feedback Stock-bajo-no-persist. Si la
    // URL trae los parametros (link viejo en historial, etc.) los limpiamos abajo via
    // replaceState para que no queden contaminando la barra de direcciones.
    var _almInitParams = (function () { try { return new URLSearchParams(window.location.search); } catch (e) { return new URLSearchParams(); } })();
    var soloConSaldo = false; // atajo "Con stock" — el usuario lo enciende explicitamente
    var soloBajo     = false; // atajo "Stock bajo" — el usuario lo enciende explicitamente
    (function () {
        if (!_almInitParams.has('solo_bajo') && !_almInitParams.has('solo_con_saldo')) return;
        try {
            var u = new URL(window.location.href);
            u.searchParams.delete('solo_bajo');
            u.searchParams.delete('solo_con_saldo');
            window.history.replaceState({}, '', u.toString());
        } catch (e) {}
    })();



    // Cuando el usuario hace clic en una sugerencia del filtro Descripción, guardamos
    // aquí el ID del producto elegido → el backend filtra por match exacto (`id_producto`).
    // Si el usuario edita el texto, presiona Enter o limpia el campo, se borra → vuelve
    // al comportamiento LIKE %term% (búsqueda por similitudes).
    // Init desde URL: si llegamos por link directo con ?id_producto=NNN, sincronizar el
    // estado JS para que la primera llamada AJAX SI mande id_producto (sin esto la
    // pagina dropearia el parametro y el sidebar cruzado nunca apareceria).
    var almBuscarPickedId = (function () {
        var v = _almInitParams.get('id_producto');
        if (!v) return null;
        var n = parseInt(v, 10);
        return isFinite(n) && n > 0 ? n : null;
    })();

    // Resuelve el valor "real" de un filtro con patron placeholder-background:
    //   - Si el usuario tipeo algo → ese texto GANA y se promueve a data-active
    //     (clear el value y poner el typed como placeholder, asi sigue visible
    //     pero el input queda listo para reescribir sin borrar).
    //   - Si no tipeo nada → cae al data-active (filtro previo que se mantiene).
    // Mismo patron que /admin/equipos: el filtro activo se muestra como
    // background gris, no como texto editable que toca borrar.
    function valActive(id) {
        var e = el(id); if (!e) return '';
        var typed = String(e.value || '').trim();
        if (typed) {
            e.dataset.active = typed;
            e.value = '';
            e.placeholder = typed;
            return typed;
        }
        return String(e.dataset.active || '').trim();
    }

    // ── filtros → params (única fuente de verdad de los filtros activos) ──
    function filtros() {
        var p = new URLSearchParams();
        var alm = val('almSelAlmacen'); if (alm) p.set('id_almacen', alm);
        var b   = valActive('almFiltroBuscar'); if (b) p.set('search', b);
        // id_producto se manda SOLO si vino de un clic en sugerencia (match exacto).
        // Se prioriza sobre `search` en el backend (que sigue yendo para que la UI
        // muestre el texto y la URL compartible mantenga el contexto).
        if (almBuscarPickedId) p.set('id_producto', String(almBuscarPickedId));
        var cat = valActive('almFiltroCat'); if (cat) p.set('categoria', cat);
        if (soloBajo)                   p.set('solo_bajo', '1');
        if (soloConSaldo)               p.set('solo_con_saldo', '1');
        // "Ver solo seleccionados" (bulk counter clickado): manda los IDs como CSV. El
        // backend hace whitelist por estos IDs e IGNORA search/categoria/solo_bajo —
        // asi el usuario ve TODOS sus seleccionados, incluso si los otros filtros los
        // habian excluido de la vista cuando seleccionaba. Solo se manda si hay algo
        // seleccionado (si no, el backend caeria al modo normal sin filtro).
        if (almSoloSel && typeof almSelCount === 'function' && almSelCount() > 0) {
            p.set('id_producto_in', Object.keys(almSeleccion).join(','));
        }
        // reflejar estado "active" en los wrappers
        var setActive = function (sel, on) { var w = sel && sel.closest('.alm-filter'); if (w) w.classList.toggle('active', !!on); };
        setActive(el('almFiltroBuscar'), b); setActive(el('almFiltroCat'), cat && cat !== 'all');
        // toggle de la "x" de limpiar — visible si hay typed value O data-active.
        var tx = function (inputId) {
            var i = el(inputId); if (!i) return;
            var x = i.parentElement.querySelector('.filter-clear'); if (!x) return;
            var hasFilter = (i.value && i.value.trim()) || (i.dataset.active && i.dataset.active.trim());
            x.style.display = hasFilter ? 'flex' : 'none';
        };
        tx('almFiltroBuscar'); tx('almFiltroCat');
        return p;
    }

    // ── Carga AJAX de la tabla + sidebar — con scroll infinito ──────────────────
    // almCargar(opts?) acepta { offset, append }:
    //   • Sin args (o offset=0)    → reemplaza la tabla, refresca stats + distribución
    //                                y actualiza la URL para compartir.
    //   • { offset>0, append }     → trae la siguiente página y la appendea al tbody.
    // Tras cada lote, si data.hasMore=true, observamos la última fila .alm-row con
    // IntersectionObserver (rootMargin 400px) para disparar el siguiente fetch al
    // acercarse el usuario al final — mismo patrón que /admin/equipos.
    var almInfObs = null; // observer activo (se desconecta antes de crear uno nuevo)
    function almAttachInfiniteObserver(nextOffset) {
        var body = el('almTableBody'); if (!body) return;
        // Buscar la ÚLTIMA fila .alm-row (puede haber filas placeholder al final).
        var last = body.lastElementChild;
        while (last && !last.classList.contains('alm-row')) last = last.previousElementSibling;
        if (!last) return;
        if (almInfObs) { try { almInfObs.disconnect(); } catch (e) {} almInfObs = null; }
        almInfObs = new IntersectionObserver(function (entries, obs) {
            if (entries[0] && entries[0].isIntersecting) {
                obs.disconnect();
                almInfObs = null;
                window.almCargar({ offset: nextOffset, append: true });
            }
        }, { root: null, rootMargin: '400px', threshold: 0 });
        almInfObs.observe(last);
    }
    window.almCargar = function (opts) {
        // back-compat: si llaman almCargar() sin args o almCargar('url-string') se trata
        // como recarga completa (offset=0). Si se pasa un objeto, respetamos sus opciones.
        if (typeof opts === 'string' || opts == null) opts = {};
        var offset = Math.max(0, parseInt(opts.offset || 0, 10));
        var append = !!opts.append && offset > 0;
        var body = el('almTableBody'); if (!body) return;
        var loadMore = el('almLoadingMore');
        // Construir URL preservando los filtros activos + offset.
        var f = filtros(); f.set('offset', String(offset));
        var finalUrl = ROUTE_INDEX + '?' + f.toString();
        if (append) {
            if (loadMore) loadMore.style.display = 'block';
        } else {
            body.style.opacity = '0.5';
            pre();
            // Cancelar cualquier observer pendiente — los filtros cambiaron y la
            // próxima lista será diferente (evita disparar un fetch obsoleto).
            if (almInfObs) { try { almInfObs.disconnect(); } catch (e) {} almInfObs = null; }
        }
        fetch(finalUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.html !== undefined) {
                    if (append) {
                        var tmp = document.createElement('tbody');
                        tmp.innerHTML = data.html;
                        while (tmp.firstElementChild) body.appendChild(tmp.firstElementChild);
                    } else {
                        body.innerHTML = data.html;
                    }
                }
                almSelApplyToVisible();
                // Stats + distribución solo en la primera página (el backend ya las omite
                // cuando offset>0; aquí evitamos rebajar a "—" lo que ya pintamos).
                if (!append && data.stats) {
                    var num = function (id, v) { var e = el(id); if (e) e.textContent = (v == null ? '—' : v); };
                    num('almStatsTotal',    data.stats.total);
                    num('almStatsConSaldo', data.stats.con_saldo);
                    num('almStatsBajo',     data.stats.stock_bajo);
                }
                if (!append && data.distribucionHtml !== undefined) {
                    var dc = el('almDistribucionContainer'); if (dc) dc.innerHTML = data.distribucionHtml;
                }
                // URL para compartir — solo en recarga completa (offset no va a la URL).
                // id_producto_in queda EXCLUIDO porque es estado efimero del bulk counter
                // (la seleccion vive solo en memoria JS; meterla en la URL confundiria a
                // quien abra el link: veria los productos pero sin badge de seleccion).
                if (!append) {
                    try {
                        var cleanU = new URL(ROUTE_INDEX, window.location.origin);
                        filtros().forEach(function (v, k) {
                            if (k === 'id_producto_in') return;
                            cleanU.searchParams.set(k, v);
                        });
                        window.history.replaceState({}, '', cleanU.toString());
                    } catch (e) {}
                }
                // Scroll infinito: si hay más, observar la última fila para traer la siguiente página.
                if (data.hasMore && typeof data.nextOffset === 'number') {
                    almAttachInfiniteObserver(data.nextOffset);
                }
            })
            .catch(function () { toast('No se pudo cargar el inventario.', 'error'); })
            .finally(function () {
                if (append) {
                    if (loadMore) loadMore.style.display = 'none';
                } else {
                    body.style.opacity = '1'; unpre();
                }
            });
    };

    function formatNum(n) {
        n = parseFloat(n || 0);
        if (isNaN(n)) return '0';
        var s = n.toFixed(3).replace(/\.?0+$/, '');
        return s === '' ? '0' : s;
    }

    // ── helpers desde el sidebar / distribución ──
    window.almVerTodo = function () {
        var bi = el('almFiltroBuscar');
        if (bi) { bi.value = ''; bi.dataset.active = ''; bi.placeholder = bi.dataset.placeholderEmpty || 'Buscar por código o descripción…'; }
        var ci = el('almFiltroCat');
        if (ci) { ci.value = ''; ci.dataset.active = ''; ci.placeholder = ci.dataset.placeholderEmpty || 'Filtrar por categoría…'; }
        almSuggestHide(); almCatSuggestHide();
        soloBajo = false; soloConSaldo = false;
        almPintarBadges();
        almBuscarPickedId = null; // descartar match exacto si quedó pegado de un clic previo
        almCargar();
    };
    window.almFilterByCategoria = function (cat) { var s = el('almFiltroCat'); if (s) { s.value = cat || ''; } almCatSuggestHide(); almCargar(); };
    // Los dos badges del header son TOGGLES: clic con el mismo filtro activo lo apaga.
    // Clic en uno mientras el otro estaba encendido los hace mutuamente exclusivos.
    // En cualquier caso, almPintarBadges() refleja el estado para que el usuario VEA
    // cual filtro esta limitando la tabla (anillo blanco + fondo saturado en .is-on).
    window.almFiltrarConSaldo = function () {
        soloConSaldo = !soloConSaldo;
        if (soloConSaldo) soloBajo = false;
        almPintarBadges(); almCargar();
    };
    window.almFiltrarBajo = function () {
        soloBajo = !soloBajo;
        if (soloBajo) soloConSaldo = false;
        almPintarBadges(); almCargar();
    };
    function almPintarBadges() {
        var bcs = el('almBadgeConSaldo'); if (bcs) bcs.classList.toggle('is-on', !!soloConSaldo);
        var bb  = el('almBadgeBajo');     if (bb)  bb.classList.toggle('is-on',  !!soloBajo);
    }
    window.almResetBadges = function() {
        soloConSaldo = false;
        soloBajo = false;
        almPintarBadges();
    };
    // Pintar al inicio para reflejar el estado leido de la URL.
    almPintarBadges();

    // ── Autocompletado del filtro "Buscar" (código o descripción), con el look de los desplegables de la app ──
    function almNorm(s) { return s ? String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase() : ''; }
    function almSuggestHide() { var box = el('almFiltroBuscarSuggest'); if (box) box.classList.remove('open'); }
    function almCatSuggestHide() { var box = el('almFiltroCatSuggest'); if (box) box.classList.remove('open'); }

    // Helpers compartidos por todos los autocompletes del modulo (almBuscar/almCat/almProdCat/almProdUm).
    // `almSuggestFilter` aplica el patron "lista filtrada por term normalizado o todo si forceAll/term vacio".
    // `almSuggestApply` setea el HTML del box (con fallback a empty state) y lo abre.
    function almSuggestFilter(lista, term, getKey, forceAll) {
        if (forceAll || term === '') return (lista || []).slice(0);
        return (lista || []).filter(function (it) { return almNorm(getKey(it)).indexOf(term) > -1; });
    }
    function almSuggestApply(box, html, emptyHtml) {
        if (!box) return;
        // Mutex con el menu Acciones: si las sugerencias se abren mientras Acciones
        // estaba desplegado, cerramos Acciones (no deben coexistir dos overlays).
        var accMenu = document.getElementById('almAccionesMenu');
        if (accMenu && accMenu.style.display === 'block') accMenu.style.display = 'none';
        box.innerHTML = html || (emptyHtml || '<div class="alm-suggest-empty">Sin coincidencias.</div>');
        box.classList.add('open');
    }
    // Construye los "tokens de búsqueda" para igualar la lógica del backend:
    // tokeniza por espacios, normaliza (lower + sin acentos) y para cada token >3
    // letras que termine en 'S' añade su variante singular. Devuelve { tokens, hasMatch(text) }.
    function almBuildSearchMatcher(rawTerm) {
        var tokens = (rawTerm || '').split(/\s+/).filter(Boolean).map(function (t) {
            var n = almNorm(t);
            var variantes = [n];
            if (n.length > 3 && n.charAt(n.length - 1) === 's') variantes.push(n.slice(0, -1));
            return variantes;
        });
        return {
            isEmpty: tokens.length === 0,
            // hasMatch retorna true si CADA token (en alguna de sus variantes) aparece
            // como substring del texto normalizado pasado — AND entre tokens, OR entre variantes.
            hasMatch: function (text) {
                var n = almNorm(text || '');
                for (var i = 0; i < tokens.length; i++) {
                    var found = false;
                    for (var j = 0; j < tokens[i].length; j++) {
                        if (n.indexOf(tokens[i][j]) > -1) { found = true; break; }
                    }
                    if (!found) return false;
                }
                return true;
            }
        };
    }

    window.almBuscarSuggest = function () {
        almCatSuggestHide();
        var inp = el('almFiltroBuscar'), box = el('almFiltroBuscarSuggest');
        if (!inp || !box) return;
        var rawTerm = inp.value.trim();
        var matcher = almBuildSearchMatcher(rawTerm);
        var lista = window.almProductosLista || [];

        // Acceso directo "VER TODO EL STOCK" — siempre presente al inicio de la lista, sin
        // importar si el usuario está escribiendo o no. Limpia el filtro y carga todo el
        // inventario del almacén actual (alias a almVerTodo). Usa la MISMA estructura que
        // el resto de items para mantener consistencia visual.
        var verTodoLink = '<div class="alm-suggest-item" data-action="ver-todo">'
                        +     '<span class="nom">VER TODO EL STOCK</span>'
                        + '</div>';

        // IDs de productos que SI estan en el almacen seleccionado (set para lookup O(1)).
        // Si no hay almacen seleccionado o no tenemos info, se omite el filtro (mostrar todo).
        var idAlm = el('almSelAlmacen') ? el('almSelAlmacen').value : '';
        var idsArr = idAlm ? ((window.almProductosEnAlmacen || {})[idAlm] || null) : null;
        var idsSet = null;
        if (idsArr && idsArr.length !== undefined) {
            idsSet = {};
            for (var k = 0; k < idsArr.length; k++) idsSet[idsArr[k]] = true;
        }
        function enEsteAlmacen(p) { return idsSet === null || !!idsSet[p.ID_PRODUCTO]; }

        // Recorremos la lista una vez y clasificamos: matches en este almacén vs solo catálogo.
        var matches = [];        // coinciden con el término Y estan en este almacén → se muestran
        var soloCatalogo = 0;    // coinciden con el término PERO no estan en este almacén → contador
        if (matcher.isEmpty) {
            for (var i = 0; i < lista.length && matches.length < 12; i++) {
                if (enEsteAlmacen(lista[i])) matches.push(lista[i]);
            }
        } else {
            for (var j = 0; j < lista.length; j++) {
                var p = lista[j];
                // Cada token (con su variante singular si aplica) debe aparecer en CODIGO o NOMBRE
                // — concatenamos para que un token pueda matchear en cualquiera de los dos campos.
                if (!matcher.hasMatch((p.CODIGO || '') + ' ' + (p.NOMBRE || ''))) continue;
                if (enEsteAlmacen(p)) {
                    if (matches.length < 12) matches.push(p);
                } else {
                    soloCatalogo++;
                }
            }
        }

        if (!matches.length) {
            // Distinguimos los dos casos para que el usuario entienda por qué la tabla queda vacía:
            //  • "Sin coincidencias"           → el término no matchea ningún producto del sistema.
            //  • "Existen pero sin saldo aquí" → el catálogo tiene matches pero no en este almacén.
            box.innerHTML = verTodoLink + (soloCatalogo > 0
                ? '<div class="alm-suggest-empty">Existe en el catálogo, pero <strong>no tiene movimientos en este almacén</strong>.<br><span style="font-size:11.5px;color:#94a3b8;">Registra una entrada (Recepción) o un traspaso para que aparezca aquí.</span></div>'
                : '<div class="alm-suggest-empty">Sin coincidencias.</div>');
        } else {
            // Mostrar SOLO el NOMBRE; data-pick guarda el NOMBRE para que escribir encima del
            // texto pegado siga produciendo coincidencias via LIKE %term% del backend.
            var html = verTodoLink + matches.map(function (p) {
                var nom = (p.NOMBRE || '').replace(/[<>&"]/g, '');
                var cod = (p.CODIGO || '').replace(/[<>&"]/g, '');
                // data-pid (ID_PRODUCTO) = match EXACTO al hacer clic; data-pick = nombre que
                // se pega en el input para que se vea lo elegido y siga siendo editable.
                return '<div class="alm-suggest-item" data-pid="' + (p.ID_PRODUCTO || '') + '" data-pick="' + nom + '" title="' + cod + '">'
                     + '<span class="nom">' + nom + '</span></div>';
            }).join('');
            // Pie informativo: si hay matches del catálogo no listados (porque no estan en este
            // almacén), avisamos para que el usuario sepa que existen más opciones globalmente.
            if (soloCatalogo > 0) {
                html += '<div class="alm-suggest-empty" style="border-top:1px solid #f1f5f9;margin-top:4px;padding-top:8px;">'
                      + '<span style="font-size:11.5px;color:#94a3b8;">+ ' + soloCatalogo + ' producto(s) coinciden pero no están en este almacén.</span></div>';
            }
            box.innerHTML = html;
        }
        box.classList.add('open');
    };
    // Reglas del filtro "Descripción":
    //   (a) Escribir refresca solo la LISTA de sugerencias, NO la tabla. Si el usuario
    //       venía de un clic previo (id_producto fijado), se DESCARTA en cuanto edita el
    //       texto — porque ya quiere algo distinto.
    //   (b) Clic en una sugerencia [almBuscarPick] → fija id_producto = match EXACTO
    //       (solo aparece esa fila en la tabla).
    //   (c) Enter [almBuscarEnter] → similitudes via LIKE %term% del backend (sin id_producto).
    //   (d) Limpiar [almBuscarLimpiar] → quita texto + id_producto, recarga sin filtro.
    window.almBuscarInput = function () {
        // Si el texto ya no coincide con la última sugerencia elegida, el id pegado deja
        // de aplicar. Lo más simple: descartar siempre que se vuelva a teclear.
        almBuscarPickedId = null;
        window.almBuscarSuggest();
    };
    window.almBuscarEnter = function (ev) {
        if (ev && ev.key !== 'Enter') return;
        if (ev) ev.preventDefault();
        almBuscarPickedId = null;
        almSuggestHide();
        almCargar();
    };
    window.almBuscarPick = function (texto, idProducto) {
        // Patron placeholder-background: el termino elegido va al value temporalmente
        // para que filtros() -> valActive() lo promueva a data-active + placeholder.
        var inp = el('almFiltroBuscar'); if (inp) inp.value = texto;
        almBuscarPickedId = idProducto ? parseInt(idProducto, 10) : null;
        almSuggestHide();
        almCargar();
    };
    window.almBuscarLimpiar = function () {
        var inp = el('almFiltroBuscar');
        if (inp) {
            inp.value = '';
            inp.dataset.active = '';                                  // borrar el filtro activo
            inp.placeholder = inp.dataset.placeholderEmpty || 'Buscar por código o descripción…';
        }
        almBuscarPickedId = null;
        almSuggestHide();
        almCargar();
    };
    // ── Autocompletado del filtro "Categoría" (lista de categorías ya registradas), mismo look que "Buscar" ──
    window.almCatSuggest = function () {
        almSuggestHide();
        var inp = el('almFiltroCat'), box = el('almFiltroCatSuggest');
        if (!inp || !box) return;
        var lista = (window.almCategoriasLista || []);
        var matches = almSuggestFilter(lista, almNorm(inp.value.trim()), function (c) { return c; }, false);
        var html = matches.map(function (c) {
            var safe = String(c).replace(/[<>&"]/g, '');
            return '<div class="alm-suggest-item" data-pick="' + safe + '"><span class="nom">' + safe + '</span></div>';
        }).join('');
        var empty = '<div class="alm-suggest-empty">' + (lista.length ? 'Sin categorías que coincidan.' : 'No hay categorías registradas.') + '</div>';
        almSuggestApply(box, html, empty);
    };
    // Escribir SOLO refresca la lista de sugerencias — NO dispara la búsqueda en la tabla.
    // La tabla se filtra cuando el usuario (a) elige una sugerencia [almCatPick],
    // (b) pulsa Enter [almCatEnter], o (c) limpia el campo con la X [almCatLimpiar].
    window.almCatInput = function () { window.almCatSuggest(); };
    window.almCatEnter = function (ev) {
        if (ev && ev.key !== 'Enter') return;
        if (ev) ev.preventDefault();
        almCatSuggestHide();
        almCargar();
    };
    window.almCatPick = function (cat) {
        var inp = el('almFiltroCat'); if (inp) inp.value = cat;
        almCatSuggestHide();
        almCargar();
    };
    window.almCatLimpiar = function () {
        var inp = el('almFiltroCat');
        if (inp) {
            inp.value = '';
            inp.dataset.active = '';
            inp.placeholder = inp.dataset.placeholderEmpty || 'Filtrar por categoría…';
        }
        almCatSuggestHide();
        almCargar();
    };

    // ── Filtro de almacén: ahora usa el componente custom-dropdown global (selectOption / dropdown-selection).
    //    El hidden #almSelAlmacen sigue siendo la fuente de verdad que lee filtros(); el listener de abajo
    //    recarga la tabla cuando el usuario elige un almacén distinto.
    window.addEventListener('dropdown-selection', function (e) {
        var id = e.detail && e.detail.dropdownId;
        if (id === 'almSelAlmacenDropdown') almCargar();
        // Modal "Registrar salida": al elegir proyecto destino, auto-abrir el dropdown
        // de "Contrato N°" con los contratos de ese proyecto (o mensaje "sin contratos").
        if (id === 'almSalidaProyectoDropdown' && typeof window.almSalidaOnProyectoChange === 'function') {
            window.almSalidaOnProyectoChange();
        }
    });

    // Click en una sugerencia (Buscar / Categoría) / click fuera / Escape — el filtro Almacén ya no usa este sistema.
    document.addEventListener('click', function (e) {
        var item = e.target.closest('#almFiltroBuscarSuggest .alm-suggest-item');
        if (item) {
            e.preventDefault();
            // Item especial "Ver todo el inventario" → reusa almVerTodo (limpia filtros + recarga).
            if (item.getAttribute('data-action') === 'ver-todo') {
                almSuggestHide();
                if (window.almVerTodo) window.almVerTodo();
                return;
            }
            // data-pid → match exacto en el backend; data-pick → texto visible en el input.
            window.almBuscarPick(item.getAttribute('data-pick') || '', item.getAttribute('data-pid') || '');
            return;
        }
        var catItem = e.target.closest('#almFiltroCatSuggest .alm-suggest-item');
        if (catItem) { e.preventDefault(); window.almCatPick(catItem.getAttribute('data-pick') || ''); return; }
        if (!e.target.closest('.alm-filter')) { almSuggestHide(); almCatSuggestHide(); }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { almSuggestHide(); almCatSuggestHide(); } });

    // El paginador clásico fue reemplazado por scroll infinito (ver almAttachInfiniteObserver).
    // ════════════════════════════════════════════════════════════════════════
    //  Selección de productos en la tabla — IGUAL que /admin/equipos:
    //  clic en una fila → se resalta en azul (.selected-row-maquinaria) y aparece
    //  la barra flotante #almBulkBar con el conteo y las acciones.
    //  Las cantidades a sacar/enviar viven AHORA en la propia fila de la tabla:
    //  cada fila tiene un <input.alm-row-cant> que se habilita al seleccionarla. El
    //  valor se guarda en almSeleccion[id].cantidad y sobrevive a recargas del tbody
    //  (paginación/filtros) gracias a almSelApplyToVisible(). El modal #almSalidaModal
    //  ya NO muestra una tabla de productos — solo los campos de la Nota de Entrega.
    // ════════════════════════════════════════════════════════════════════════
    var almSeleccion = {}; // { id_producto: { codigo, nombre, um, saldo, cantidad } }
    // IDs de productos seleccionados que NO tienen cantidad válida en el último intento de
    // "Registrar salida". Sobrevive a recargas del tbody y se limpia cuando el usuario
    // teclea una cantidad válida, deselecciona el producto, o limpia toda la selección.
    var almFaltantes = {};
    // IDs de productos cuya cantidad tecleada EXCEDE el saldo disponible. Misma mecánica
    // que almFaltantes pero distinto motivo de bloqueo: aquí el usuario SÍ puso un número,
    // pero ese número es mayor que el stock del almacén. Mantenemos lo escrito (no recortamos)
    // para que el usuario vea el valor inválido y lo corrija — la fila se pinta de rojo y el
    // modal "Registrar salida" queda bloqueado hasta que la cantidad baje al saldo o menos.
    var almExceden = {};
    // Modo "filtro local" activado desde el contador de la barra flotante: cuando es true,
    // se ocultan vía CSS (display:none) las filas que NO están en almSeleccion. Cliente puro
    // — no recarga datos del backend, solo esconde filas en el DOM actual. Se desactiva
    // automáticamente al limpiar la selección.
    var almSoloSel = false;
    function almSelCount() { return Object.keys(almSeleccion).length; }
    function almAplicarFaltantes() {
        document.querySelectorAll('#almTableBody tr.alm-row').forEach(function (tr) {
            var id = tr.getAttribute('data-id-producto');
            tr.classList.toggle('alm-row-missing-cant', !!almFaltantes[id]);
        });
    }
    function almLimpiarFaltante(id) {
        if (!almFaltantes[id]) return;
        delete almFaltantes[id];
        var tr = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + id + '"]');
        if (tr) tr.classList.remove('alm-row-missing-cant');
    }
    function almAplicarExceden() {
        document.querySelectorAll('#almTableBody tr.alm-row').forEach(function (tr) {
            var id = tr.getAttribute('data-id-producto');
            tr.classList.toggle('alm-row-exceeds-stock', !!almExceden[id]);
        });
    }
    function almLimpiarExceden(id) {
        if (!almExceden[id]) return;
        delete almExceden[id];
        var tr = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + id + '"]');
        if (tr) tr.classList.remove('alm-row-exceeds-stock');
    }
    function almMarcarExceden(id) {
        almExceden[id] = true;
        var tr = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + id + '"]');
        if (tr) tr.classList.add('alm-row-exceeds-stock');
    }
    // Filtro local "Solo seleccionados": esconde/muestra filas según almSeleccion.
    function almAplicarSoloSel() {
        document.querySelectorAll('#almTableBody tr.alm-row').forEach(function (tr) {
            var id = tr.getAttribute('data-id-producto');
            tr.style.display = (almSoloSel && !almSeleccion[id]) ? 'none' : '';
        });
        var btn = el('almBulkCounter');
        if (btn) btn.classList.toggle('is-filtering', almSoloSel);
    }
    window.almToggleSoloSel = function (e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        if (!almSelCount()) { toast('No hay productos seleccionados todavía.', 'error'); return; }
        almSoloSel = !almSoloSel;
        // El glow amarillo del contador (.is-filtering) refleja el estado actual.
        var btn = el('almBulkCounter');
        if (btn) btn.classList.toggle('is-filtering', almSoloSel);
        // Recargar la tabla via AJAX. Cuando solo_sel esta ON, filtros() manda
        // id_producto_in y el backend hace whitelist por esos IDs (ignorando los
        // demas filtros de contenido). Cuando esta OFF, vuelve al filtrado normal.
        almCargar();
        if (almSoloSel) {
            // Llevar al usuario al inicio de la tabla para que vea inmediatamente las filas
            // filtradas (la primera seleccionada). Sin "smooth" para que sea instantáneo.
            var tbody = el('almTableBody');
            if (tbody) tbody.scrollIntoView({ block: 'start' });
        }
    };
    function almSelRefreshBar() {
        var bar = el('almBulkBar'); if (!bar) return;
        var n = almSelCount();
        bar.classList.toggle('active', n > 0);
        var c = el('almBulkCount'); if (c) c.textContent = n;
        // Si la selección quedó vacía y el filtro "solo seleccionados" estaba activo,
        // hay que apagarlo y volver a mostrar todas las filas (la barra se oculta sola).
        if (n === 0 && almSoloSel) {
            almSoloSel = false;
            document.querySelectorAll('#almTableBody tr.alm-row').forEach(function (tr) { tr.style.display = ''; });
            var btn = el('almBulkCounter'); if (btn) btn.classList.remove('is-filtering');
        }
    }
    function almSelMarkRow(tr, on) {
        if (!tr) return;
        tr.classList.toggle('selected-row-maquinaria', !!on);
        // Stepper de cantidad: el input + los dos botones +/− se habilitan en bloque.
        // El estilo "activo" lo aporta la clase .is-active sobre el wrapper (CSS arriba).
        var wrap = tr.querySelector('.alm-cant-stepper');
        var inp  = tr.querySelector('.alm-row-cant');
        if (wrap) wrap.classList.toggle('is-active', !!on);
        if (!inp) return;
        var btns = tr.querySelectorAll('.alm-cant-btn');
        if (on) {
            var id = tr.getAttribute('data-id-producto');
            var s  = id ? almSeleccion[id] : null;
            inp.disabled = false;
            inp.value = (s && s.cantidad != null && s.cantidad !== '') ? s.cantidad : '';
            btns.forEach(function (b) { b.disabled = false; });
        } else {
            inp.disabled = true;
            inp.value = '';
            btns.forEach(function (b) { b.disabled = true; });
        }
    }
    // Stepper +/−: incrementa/decrementa la cantidad del producto de esa fila. Mínimo 1
    // (no permite 0 ni negativos — el "−" se queda en 1 cuando ya está en 1). El paso es
    // entero porque en general se entregan unidades enteras; si el usuario necesita
    // decimales puede teclearlos directamente en el input. Si la cantidad supera el stock,
    // NO se recorta: se permite teclear/subir el valor y la fila se pinta de rojo
    // (.alm-row-exceeds-stock) para que el usuario vea el error y corrija. El modal de
    // "Registrar salida" queda bloqueado hasta que la cantidad vuelva a ser <= saldo.
    window.almRowCantStep = function (btn, dir) {
        var tr = btn.closest('tr.alm-row'); if (!tr) return;
        var inp = tr.querySelector('.alm-row-cant'); if (!inp || inp.disabled) return;
        var id = tr.getAttribute('data-id-producto');
        var s  = id ? almSeleccion[id] : null; if (!s) return;
        var cur = parseFloat(String(inp.value || '0').replace(',', '.')) || 0;
        var next = cur + (dir > 0 ? 1 : -1);
        if (next < 1) next = 1;
        inp.value = String(next);
        s.cantidad = String(next);
        var saldo = parseFloat(s.saldo) || 0;
        if (next > saldo) almMarcarExceden(id);
        else              almLimpiarExceden(id);
        if (next > 0)     almLimpiarFaltante(id);
    };
    // Bloquea en el teclado los caracteres prohibidos (signos, "e", letras) para que el
    // input solo acepte dígitos y un único separador decimal. Permite teclas de control
    // (Backspace, Delete, flechas, Tab, Enter, Home/End, copiar/pegar/cortar/seleccionar).
    window.almRowCantKeyDown = function (e) {
        if (e.ctrlKey || e.metaKey || e.altKey) return true;
        var k = e.key || '';
        var control = ['Backspace','Delete','ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Tab','Enter','Home','End','Escape'];
        if (control.indexOf(k) !== -1) return true;
        // Solo dígitos o un único punto/coma decimal.
        if (/^[0-9]$/.test(k)) return true;
        if ((k === '.' || k === ',') && e.target && (e.target.value || '').indexOf('.') === -1 && (e.target.value || '').indexOf(',') === -1) return true;
        e.preventDefault();
        return false;
    };
    // Sanitiza lo pegado: deja solo dígitos y a lo más un punto decimal.
    window.almRowCantPaste = function (e) {
        try {
            var raw = (e.clipboardData || window.clipboardData).getData('text');
            if (raw == null) return true;
            var clean = String(raw).replace(',', '.').replace(/[^0-9.]/g, '');
            var parts = clean.split('.');
            if (parts.length > 2) clean = parts[0] + '.' + parts.slice(1).join('');
            e.preventDefault();
            if (e.target) {
                e.target.value = clean;
                if (typeof window.almRowCantInput === 'function') window.almRowCantInput(e.target);
            }
        } catch (_) {}
        return false;
    };
    // Re-pinta el resaltado azul + estado del input cantidad tras cada recarga AJAX del tbody.
    function almSelApplyToVisible() {
        document.querySelectorAll('#almTableBody tr.alm-row').forEach(function (tr) {
            almSelMarkRow(tr, !!almSeleccion[tr.getAttribute('data-id-producto')]);
        });
        // Repintar también el highlight rojo de las filas con cantidad faltante o que
        // exceden el stock, y re-aplicar el filtro "solo seleccionados" si está activo.
        almAplicarFaltantes();
        almAplicarExceden();
        almAplicarSoloSel();
    }
    window.almSelClear = function (e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        almSeleccion = {};
        almFaltantes = {};
        almExceden = {};
        almSoloSel = false; // sin selección, el filtro local no tiene sentido
        document.querySelectorAll('#almTableBody tr.alm-row').forEach(function (tr) {
            almSelMarkRow(tr, false);
            tr.classList.remove('alm-row-missing-cant');
            tr.classList.remove('alm-row-exceeds-stock');
            tr.style.display = '';
        });
        var btn = el('almBulkCounter'); if (btn) btn.classList.remove('is-filtering');
        almSelRefreshBar();
    };
    // Handler del input de cantidad en cada fila — guarda en almSeleccion (sobrevive a
    // recargas del tbody). Sanitiza (sin letras ni negativos) PERO NO recorta al stock:
    // si el usuario teclea más que el saldo disponible, dejamos el valor tal cual y
    // pintamos la fila en rojo (.alm-row-exceeds-stock). El bloqueo del modal "Registrar
    // salida" se hace en almSelAccion(); aquí solo marcamos visualmente el error para que
    // el usuario vea exactamente qué número tecleó y pueda corregirlo sin perder dígitos.
    window.almRowCantInput = function (inp) {
        var tr = inp.closest('tr.alm-row'); if (!tr) return;
        var id = tr.getAttribute('data-id-producto'); if (!id) return;
        var s  = almSeleccion[id]; if (!s) return;

        // Sanitizar: dejar solo dígitos y un único punto decimal.
        var raw = String(inp.value == null ? '' : inp.value).replace(',', '.');
        raw = raw.replace(/[^0-9.]/g, '');
        var parts = raw.split('.');
        if (parts.length > 2) raw = parts[0] + '.' + parts.slice(1).join('');

        if (raw !== inp.value) inp.value = raw;
        s.cantidad = raw;

        var c = parseFloat(raw);
        var saldo = parseFloat(s.saldo) || 0;
        // Marcar/desmarcar exceso de stock. Solo aplica si tecleó un número finito > 0,
        // de lo contrario es "faltante" — esa otra condición la chequea almSelAccion al
        // intentar abrir el modal (no queremos pintar rojo apenas se vacía el input).
        if (isFinite(c) && c > 0 && c > saldo) almMarcarExceden(id);
        else                                   almLimpiarExceden(id);
        // Si ahora la cantidad es válida (mayor que cero), limpiar el resaltado rojo "faltante".
        if (isFinite(c) && c > 0) almLimpiarFaltante(id);
    };
    // Clic en una fila de la tabla → toggle de selección. Ignora clics sobre botones / inputs
    // (incluido el input .alm-row-cant que va dentro de un td[data-no-toggle]).
    document.addEventListener('click', function (e) {
        var tr = e.target.closest('#almTableBody tr.alm-row');
        if (!tr) return;
        if (e.target.closest('[data-no-toggle]')) return;
        if (e.target.closest('button') || e.target.closest('a') || e.target.closest('input') || e.target.closest('select') || e.target.closest('.custom-dropdown')) return;
        var id = tr.getAttribute('data-id-producto'); if (!id) return;
        if (almSeleccion[id]) { delete almSeleccion[id]; almSelMarkRow(tr, false); almLimpiarFaltante(id); almLimpiarExceden(id); }
        else {
            almSeleccion[id] = {
                codigo: tr.getAttribute('data-codigo') || '',
                nombre: tr.getAttribute('data-nombre') || '',
                um:     tr.getAttribute('data-um') || '',
                saldo:  parseFloat(tr.getAttribute('data-saldo') || '0') || 0,
                cantidad: '',
            };
            almSelMarkRow(tr, true);
            // Foco automático en el input de cantidad recién habilitado.
            setTimeout(function () { var inp = tr.querySelector('.alm-row-cant'); if (inp) inp.focus(); }, 30);
        }
        // Si el filtro "solo seleccionados" está activo, mantener consistencia visual:
        // al deseleccionar, la fila debe ocultarse (ya no es "selected"); al seleccionar
        // una nueva, la nueva ya estaba visible (era la que el usuario hizo clic).
        if (almSoloSel) almAplicarSoloSel();
        almSelRefreshBar();
    });
    function almSelAlmacenActual() { var s = el('almSelAlmacen'); return s ? s.value : ''; }
    // Único botón de la barra flotante: abre el modal Nota de Entrega.
    // El backend decide si es SALIDA (consumo en el mismo almacén) o TRASPASO (envío
    // a otro almacén) según el frente destino elegido en el formulario.
    window.almSelAccion = function () {
        if (!almSelCount()) { toast('Selecciona al menos un producto (clic en su fila).', 'error'); return; }
        if (typeof window.almAbrirSalidaModal !== 'function') { toast('No tienes permiso para registrar movimientos.', 'error'); return; }
        var idAlm = almSelAlmacenActual();
        if (!idAlm) { toast('No hay un almacén seleccionado.', 'error'); return; }
        // Bloquear apertura del modal si alguna fila seleccionada (a) excede el stock o
        // (b) no tiene cantidad válida. Las dos condiciones se chequean por separado para
        // dar mensajes específicos, pero AMBAS pintan la fila de rojo de forma persistente
        // (sobrevive a recargas/filtros) hasta que el usuario corrija — teclee una cantidad
        // <= saldo, deseleccione el producto, o limpie toda la selección.
        //
        // El orden importa: primero exceden (saldo insuficiente), luego faltan (vacío). Si
        // un producto está en ambos estados imposibles, ganaría el de exceso, pero por la
        // lógica del input ese caso no ocurre (un input vacío NO marca exceso).
        almExceden = {};
        almFaltantes = {};
        var exceden = [];
        var faltan  = [];
        Object.keys(almSeleccion).forEach(function (id) {
            var s = almSeleccion[id] || {};
            var c = parseFloat(String(s.cantidad == null ? '' : s.cantidad).replace(',', '.').trim());
            var saldo = parseFloat(s.saldo) || 0;
            if (!isFinite(c) || c <= 0) {
                faltan.push({ id: id, nombre: s.nombre || ('#' + id) });
                almFaltantes[id] = true;
            } else if (c > saldo) {
                exceden.push({ id: id, nombre: s.nombre || ('#' + id), cant: c, saldo: saldo, um: s.um || '' });
                almExceden[id] = true;
            }
        });
        // Repintar SIEMPRE: si el usuario corrigió antes de pulsar el botón, las marcas rojas
        // antiguas se borran solas; si quedan errores, se vuelven a pintar las filas afectadas.
        almAplicarFaltantes();
        almAplicarExceden();
        if (exceden.length) {
            // Llevar la primera fila con exceso a la vista + foco para corrección rápida.
            var firstExId = exceden[0].id;
            var firstExTr = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + firstExId + '"]');
            if (firstExTr) {
                firstExTr.scrollIntoView({ behavior: 'smooth', block: 'center' });
                var firstExInp = firstExTr.querySelector('.alm-row-cant');
                if (firstExInp) { firstExInp.focus(); firstExInp.select && firstExInp.select(); }
            }
            // Detalle por producto: "Aceite 10W30 (5 > 2 UND)". Ayuda a ver de un vistazo
            // cuánto sobra en cada caso sin tener que abrir cada fila.
            var det = exceden.map(function (e) {
                return e.nombre + ' (' + e.cant + ' > ' + e.saldo + (e.um ? ' ' + e.um : '') + ')';
            }).join(', ');
            toast('La cantidad de salida supera el stock disponible en: ' + det + '. Corrige antes de registrar la salida.', 'error');
            return;
        }
        if (faltan.length) {
            // Llevar la primera fila faltante a la vista + foco en su input de cantidad.
            var firstId = faltan[0].id;
            var firstTr = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + firstId + '"]');
            if (firstTr) {
                firstTr.scrollIntoView({ behavior: 'smooth', block: 'center' });
                var firstInp = firstTr.querySelector('.alm-row-cant');
                if (firstInp) firstInp.focus();
            }
            // Listar TODOS los nombres (no truncar): el usuario necesita saber cuáles son,
            // sobre todo si algunos quedaron fuera de la página/filtro actual.
            var nombres = faltan.map(function (f) { return f.nombre; }).join(', ');
            toast('Falta indicar la cantidad de salida (debe ser mayor que cero) en: ' + nombres + '.', 'error');
            return;
        }
        window.almAbrirSalidaModal(idAlm);
    };

    // ── Campo "Categoría" del modal de producto: desplegable de categorías ya registradas + "escribir una nueva" ──
    // Es un <input> normal (puedes teclear cualquier cosa) con un caret que abre la lista de
    // categorías existentes. Si lo que escribes no está en la lista, aparece "Usar nueva categoría: …"
    // y al guardar el producto esa categoría queda registrada (la lista se deriva de productos_inventario).
    function almProdCatHide() {
        var b = el('almProdCatSuggest'); if (b) b.classList.remove('open');
        var c = el('almProdCatCaret');   if (c) c.classList.remove('open');
    }
    // forceAll = true → muestra TODAS las categorías ignorando el texto actual (lo usan el caret y el focus).
    window.almProdCatSuggest = function (forceAll) {
        var inp = el('almProdCategoria'), box = el('almProdCatSuggest'), caret = el('almProdCatCaret');
        if (!inp || !box) return;
        var term = almNorm(inp.value.trim());
        var matches = almSuggestFilter(window.almCategoriasLista, term, function (c) { return c; }, !!forceAll);
        // Solo categorias existentes; el usuario puede escribir una nueva y se guardara al crear el producto.
        var html = matches.map(function (c) {
            var sel = almNorm(c) === term ? ' si-sel' : '';
            return '<div class="si-item' + sel + '" data-cat="' + escHtml(c) + '">' + escHtml(c) + '</div>';
        }).join('');
        almSuggestApply(box, html, '<div class="alm-suggest-empty">Sin coincidencias. Escribe para crear una nueva categoría.</div>');
        if (caret) caret.classList.add('open');
    };
    window.almProdCatToggle = function (e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        var box = el('almProdCatSuggest');
        if (box && box.classList.contains('open')) { almProdCatHide(); return; }
        window.almProdCatSuggest(true);
        var inp = el('almProdCategoria'); if (inp) inp.focus();
    };
    window.almProdCatPick = function (cat) { var inp = el('almProdCategoria'); if (inp) inp.value = cat; almProdCatHide(); };

    // Delegación: click en una opción de la lista / click fuera del campo lo cierra.
    document.addEventListener('click', function (e) {
        var item = e.target.closest('#almProdCatSuggest .si-item');
        if (item) { e.preventDefault(); window.almProdCatPick(item.getAttribute('data-cat') || ''); return; }
        // No cerrar si el click fue dentro del propio campo (input + caret) o de la lista.
        if (!e.target.closest('.alm-cat-field') && !e.target.closest('#almProdCatSuggest')) almProdCatHide();
    });
    // Enter dentro del input → si hay coincidencia exacta o "nueva", la fija y cierra.
    var _almProdCatInp = el('almProdCategoria');
    if (_almProdCatInp) _almProdCatInp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); window.almProdCatPick(this.value.trim()); }
        else if (e.key === 'Escape') { almProdCatHide(); }
    });

    // ── Campo "Unidad de Medida" del modal de producto: autocomplete con las UMs ya registradas ──
    // Permite seleccionar una UM existente o escribir una nueva libremente.
    function almProdUmHide() {
        var b = el('almProdUmSuggestBox'); if (b) b.classList.remove('open');
    }
    window.almProdUmSuggest = function (forceAll) {
        var inp = el('almProdUm'), box = el('almProdUmSuggestBox');
        if (!inp || !box) return;
        var term = almNorm(inp.value.trim());
        var lista = (window.almUnidadesMedida || []);
        var matches = almSuggestFilter(lista, term, function (u) { return u; }, !!forceAll);
        // Solo lista las UMs existentes; si el usuario escribe una nueva, queda en el
        // input tal cual y se guarda al crear el producto — no se ofrece como sugerencia.
        var html = matches.map(function (u) {
            var sel = almNorm(u) === term ? ' si-sel' : '';
            return '<div class="si-item' + sel + '" data-um="' + escHtml(u) + '">' + escHtml(u) + '</div>';
        }).join('');
        almSuggestApply(box, html, '<div class="alm-suggest-empty">Sin coincidencias.</div>');
    };
    // Delegación de clic para las opciones del autocomplete de UM
    document.addEventListener('click', function (e) {
        var item = e.target.closest('#almProdUmSuggestBox .si-item');
        if (item) {
            e.preventDefault();
            var inp = el('almProdUm');
            if (inp) inp.value = item.getAttribute('data-um') || '';
            almProdUmHide();
            return;
        }
        if (!e.target.closest('#almProdUm') && !e.target.closest('#almProdUmSuggestBox')) almProdUmHide();
    });
    var _almProdUmInp = el('almProdUm');
    if (_almProdUmInp) _almProdUmInp.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') almProdUmHide();
    });

    // ── modales ──
    function open(id)  { var m = el(id); if (m) m.classList.add('open'); }
    window.almCerrar = function (id) { var m = el(id); if (m) m.classList.remove('open'); };
    // El cierre por clic en el backdrop fue removido por preferencia del usuario:
    // cada modal tiene su propio botón "✕" / "Cancelar". Escape sí lo sigue cerrando.
    // Caso especial: el modal #almPreviewModal tiene cleanup propio (revoca el blob
    // URL del PDF y reabre el modal de salida) — delegamos en almPreviewCerrar para
    // no filtrar memoria ni dejar al usuario sin camino de vuelta a la edicion.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var preview = el('almPreviewModal');
        if (preview && preview.classList.contains('open') && typeof window.almPreviewCerrar === 'function') {
            window.almPreviewCerrar();
            return; // almPreviewCerrar ya manejo el cleanup + reabrir salida; no cerrar mas.
        }
        document.querySelectorAll('.alm-modal-overlay.open').forEach(function (m) { m.classList.remove('open'); });
    });

    // ── Botón "Acciones" (dropdown estilo /admin/equipos) ──
    window.almToggleAcciones = function (e) {
        if (e) e.stopPropagation();
        var m = el('almAccionesMenu'); if (!m) return;

        // Cerrar los demás filtros estándar si están abiertos
        if (typeof window.closeAllDropdowns === 'function') window.closeAllDropdowns();
        document.querySelectorAll('.custom-dropdown.active').forEach(d => d.classList.remove('active'));
        document.querySelectorAll('.dropdown-content').forEach(d => d.style.display = '');
        // Mutex con los paneles de sugerencias (Buscar / Categoría): si estaban
        // abiertos los cerramos ahora — no debe haber dos overlays a la vez.
        almSuggestHide();
        almCatSuggestHide();

        m.style.display = (m.style.display === 'block') ? 'none' : 'block';
    };
    document.addEventListener('click', function (e) {
        var m = el('almAccionesMenu');
        if (m && m.style.display === 'block') {
            // Cerrar si hace clic fuera, o si hace clic en cualquier otro botón de filtro (dropdown-trigger)
            if (!e.target.closest('#almAccionesMenu') && !e.target.closest('#almBtnAcciones') || e.target.closest('.dropdown-trigger')) {
                m.style.display = 'none';
            }
        }
    });
    window.almAccion = function (which) {
        var m = el('almAccionesMenu'); if (m) m.style.display = 'none';
        switch (which) {
            case 'admin':    if (window.almAbrirAdminAlmacenes) window.almAbrirAdminAlmacenes(); break;
            case 'almacen':  if (window.almAbrirAlmacen)        window.almAbrirAlmacen();        break;
            case 'producto': if (window.almAbrirProducto)       window.almAbrirProducto();       break;
            case 'export':
                // Construye la URL del export respetando el filtro de almacén activo.
                // Si no hay almacén seleccionado se descarga el inventario global (una col por almacén visible).
                var u = new URL(@json(route('almacen.export')), window.location.origin);
                var idAlm = el('almSelAlmacen') ? el('almSelAlmacen').value : '';
                if (idAlm) u.searchParams.set('id_almacen', idAlm);
                window.location.href = u.toString();
                break;
        }
    };

    function hoy() { var d = new Date(); var p = function (n) { return (n < 10 ? '0' : '') + n; }; return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()); }
    function showErr(id, msg) { var e = el(id); if (e) { e.textContent = msg; e.style.display = msg ? 'block' : 'none'; } }
    // Resalta un campo input con borde rojo cuando hay error, lo quita cuando msg está vacío.
    function almProdFieldErr(fieldId, hasError) {
        var f = el(fieldId);
        if (!f) return;
        if (hasError) {
            f.style.borderColor = '#dc2626';
            f.style.boxShadow  = '0 0 0 2px rgba(220,38,38,0.18)';
            f.style.background = '#fff5f5';
        } else {
            f.style.borderColor = '';
            f.style.boxShadow  = '';
            f.style.background = '';
        }
    }

    // Funciones almAbrirMovimiento / almGuardarMovimiento ELIMINADAS en 2026-05-13
    // junto con el modal #almMovModal. El flujo de entrada/salida ya no se hace
    // por producto individual: ENTRADA → /admin/almacen/recepcion · SALIDA →
    // selección de filas + barra flotante (Nota de Entrega). Para AJUSTE puntual
    // se usa el modal #almAjusteModal (Auditoría de Inventario).

    // ── Página de movimientos (módulo aparte: /admin/almacen/movimientos) ──
    var ROUTE_MOVIMIENTOS = @json(route('almacen.movimientos'));

    // ── Modal "Detalles del producto" (lo abre el ojo de cada fila; agrupa todas las acciones) ──
    window.almAbrirDetalle = function (id, cod, nom, um, cat, saldo, minimo, ubicacion) {
        var m = el('almDetalleModal'); if (!m) return;
        var hasMin = (minimo !== null && minimo !== undefined && minimo !== '');
        m.dataset.id = id;
        m.dataset.cod = cod || ''; m.dataset.nom = nom || ''; m.dataset.um = um || ''; m.dataset.cat = cat || '';
        m.dataset.ubicacion = ubicacion || '';
        m.dataset.saldo = (saldo == null ? '0' : String(saldo));
        m.dataset.minimo = hasMin ? String(minimo) : '';
        el('almDetCat').textContent = (cat && String(cat).trim()) ? cat : '—';
        el('almDetMin').textContent = hasMin ? formatNum(minimo) : 'Sin definir';
        var bajo = hasMin && parseFloat(saldo || 0) <= parseFloat(minimo);
        // 'flex' (no '' ni 'block') porque el badge se layoutea con icono a la izquierda
        // y texto a la derecha (display:flex en el CSS inline del div). Ver markup arriba.
        el('almDetBajoBadge').style.display = bajo ? 'flex' : 'none';
        open('almDetalleModal');
    };
    window.almDetalleAccion = function (which) {
        var m = el('almDetalleModal'); if (!m) return;
        var d = m.dataset, id = parseInt(d.id, 10);
        var minimo = (d.minimo === '' ? null : parseFloat(d.minimo));
        var saldo  = parseFloat(d.saldo || 0);
        var label  = (d.cod || '') + (d.cod && d.nom ? ' — ' : '') + (d.nom || '');
        almCerrar('almDetalleModal');
        switch (which) {
            // 'entrada'/'salida' removidos (esos flujos ya no van por producto individual).
            case 'ajuste':   if (window.almAbrirAjuste)         window.almAbrirAjuste(id, d.cod, d.nom, d.um, saldo, minimo); break;
            // 'kardex' antes navegaba a /admin/almacen/movimientos; ahora abre un
            // modal local con los movimientos solo de este producto + filtros mínimos.
            case 'kardex':   if (window.almAbrirKardexProducto) window.almAbrirKardexProducto(id, d.cod, d.nom, d.um, saldo); break;
            case 'editar':   if (window.almEditarProducto)      window.almEditarProducto(id, d.cod, d.nom, d.um, d.cat, d.ubicacion); break;
            case 'eliminar': if (window.almEliminarProducto)    window.almEliminarProducto(id); break;
        }
    };

    // ── Modal "Movimientos del producto" (kardex local de UN producto) ──
    // Reusa AlmacenController::movimientos con ?mini=1 (partial de 5 columnas)
    // y filtra por id_producto + id_almacen actual. Estado en window.__almKp.
    window.__almKp = { idProducto: null, tipo: '', desde: '', hasta: '' };

    window.almAbrirKardexProducto = function (idProducto, codigo, nombre, um, saldo) {
        window.__almKp = { idProducto: idProducto, tipo: '', desde: '', hasta: '' };
        el('almKpCodigo').textContent = codigo ? ('Cód: ' + codigo) : 'Sin código';
        el('almKpNombre').textContent = nombre || '';
        el('almKpSaldo').textContent  = formatNum(saldo);
        el('almKpUm').textContent     = um || '';
        // Reset visual de filtros.
        if (el('almKpDesde'))      el('almKpDesde').value = '';
        if (el('almKpHasta'))      el('almKpHasta').value = '';
        if (el('almKpTipoSelect')) el('almKpTipoSelect').value = '';
        open('almKardexProductoModal');
        window.almKpCargar();
    };

    // almKpChipSelect: gestiona el filtro de tipo desde el <select> del modal kardex.
    window.almKpChipSelect = function (tipo) {
        window.__almKp.tipo = tipo || '';
        window.almKpCargar();
    };

    window.almKpLimpiar = function () {
        window.__almKp.tipo = ''; window.__almKp.desde = ''; window.__almKp.hasta = '';
        if (el('almKpDesde'))      el('almKpDesde').value = '';
        if (el('almKpHasta'))      el('almKpHasta').value = '';
        if (el('almKpTipoSelect')) el('almKpTipoSelect').value = '';
        window.almKpCargar();
    };

    window.almKpCargar = function (pageUrl) {
        if (!window.__almKp.idProducto) return;
        window.__almKp.desde = (el('almKpDesde') && el('almKpDesde').value) || '';
        window.__almKp.hasta = (el('almKpHasta') && el('almKpHasta').value) || '';

        var p = new URLSearchParams();
        p.set('id_producto', window.__almKp.idProducto);
        p.set('mini', '1');
        if (val('almSelAlmacen')) p.set('id_almacen', val('almSelAlmacen'));
        if (window.__almKp.tipo)  p.set('tipo',  window.__almKp.tipo);
        if (window.__almKp.desde) p.set('desde', window.__almKp.desde);
        if (window.__almKp.hasta) p.set('hasta', window.__almKp.hasta);
        if (pageUrl) {
            try { var pg = new URL(pageUrl, window.location.origin).searchParams.get('page'); if (pg) p.set('page', pg); } catch (e) {}
        }

        var body = el('almKpBody'); if (body) body.style.opacity = '0.5';
        fetch(ROUTE_MOVIMIENTOS + '?' + p.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (body && data.html !== undefined) body.innerHTML = data.html;
            var pg = el('almKpPag'); if (pg) pg.innerHTML = data.pagination || '';
        })
        .catch(function () {
            if (body) body.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px;color:#dc2626;font-size:12px;">No se pudieron cargar los movimientos.</td></tr>';
        })
        .finally(function () { if (body) body.style.opacity = '1'; });
    };

    // Click en links de paginación del kardex del producto.
    document.addEventListener('click', function (e) {
        var a = e.target.closest('#almKpPag a'); if (!a) return;
        e.preventDefault(); e.stopImmediatePropagation();
        window.almKpCargar(a.href);
    }, true);

    window.almAbrirAjuste = function (idProducto, codigo, nombre, um, saldo, minimo) {
        if (!ensurePerm(HAS_MOVER, 'No tienes permiso para registrar movimientos de inventario.')) return;
        var m = el('almAjusteModal');
        m.dataset.idProducto = idProducto;
        m.dataset.minimoOrig = (minimo == null ? '' : String(minimo)); // para detectar si el usuario lo cambió
        if (el('almAjNombre')) el('almAjNombre').textContent = nombre;
        el('almAjNuevoSaldo').value = ''; el('almAjMinimo').value = (minimo == null ? '' : minimo); el('almAjMotivo').value = '';
        showErr('almAjError', ''); open('almAjusteModal');
    };

    window.almGuardarAjuste = function () {
        var m = el('almAjusteModal');
        var idAlm = val('almSelAlmacen'); if (!idAlm) { showErr('almAjError', 'No hay almacén seleccionado.'); return; }
        var nuevoSaldoRaw = val('almAjNuevoSaldo');
        var minimoRaw = val('almAjMinimo');

        // Validaciones previas (antes de disparar nada).
        var ns = null;
        if (nuevoSaldoRaw !== '') {
            ns = parseFloat(nuevoSaldoRaw);
            if (isNaN(ns) || ns < 0) { showErr('almAjError', 'El nuevo saldo debe ser un número ≥ 0.'); return; }
        }
        var cambiaMinimo = (minimoRaw !== (m.dataset.minimoOrig || ''));
        var nuevoMinimo = null;
        if (cambiaMinimo && minimoRaw !== '') {
            // El minimo de alerta debe ser > 0 (un minimo de 0 no avisa de nada, equivale
            // a "sin alerta" — que ya se logra dejando el campo vacio).
            nuevoMinimo = parseFloat(minimoRaw);
            if (isNaN(nuevoMinimo) || nuevoMinimo <= 0) { showErr('almAjError', 'El mínimo debe ser un número mayor que 0 (o dejarlo vacío para quitar la alerta).'); return; }
        }
        if (ns === null && !cambiaMinimo) { almCerrar('almAjusteModal'); return; } // nada que hacer

        var tareas = [];
        if (ns !== null) {
            // Endpoint unificado de lote: la Auditoría se registra como un lote de 1 línea
            // con tipo=AJUSTE. El backend ignora los campos de Nota de Entrega para AJUSTE.
            tareas.push(fetch(ROUTE_LOTE, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: JSON.stringify({
                    id_almacen: idAlm,
                    tipo: 'AJUSTE',
                    motivo: val('almAjMotivo') || 'Auditoría de Inventario',
                    lineas: [{ id_producto: m.dataset.idProducto, cantidad: ns }],
                })
            }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); }));
        }
        if (cambiaMinimo) {
            tareas.push(fetch(ROUTE_MIN(idAlm), {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: JSON.stringify({ id_producto: m.dataset.idProducto, cantidad_minima: (minimoRaw === '' ? null : nuevoMinimo) })
            }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); }));
        }

        pre();
        Promise.all(tareas).then(function (ress) {
            unpre();
            var fail = ress.find(function (x) { return !x.ok; });
            if (fail) { showErr('almAjError', (fail.b && fail.b.message) || 'No se pudo registrar la auditoría.'); almCargar(); return; }
            almCerrar('almAjusteModal'); toast('Auditoría registrada.'); almCargar();
        }).catch(function () { unpre(); showErr('almAjError', 'Error de red.'); });
    };

    // confirmación reutilizable (usa el modal estándar de la app si existe; si no, confirm()).
    function almConfirm(msg, onYes) {
        if (window.showModal) {
            window.showModal({ type: 'danger', title: '¿Confirmar?', message: msg, confirmText: 'Aceptar', cancelText: 'Cancelar', onConfirm: onYes });
        } else if (window.confirm(msg.replace(/<[^>]+>/g, ''))) { onYes(); }
    }

    // ── Bloque CRUD de Almacenes + Productos ──
    // Las funciones se definen SIEMPRE (sin guard de Blade alrededor del bloque).
    // Cada una llama a ensurePerm(...) antes de actuar — si el usuario no tiene
    // la clave necesaria, se muestra toast moderno y no se ejecuta nada mas. Esto
    // reemplaza al viejo patron donde el bloque entero estaba envuelto en un
    // condicional Blade con stubs en la rama alternativa, que dejaba al usuario
    // sin feedback visible (los botones se ocultaban).
    // NOTA: NUNCA escribas directivas Blade textualmente dentro de comentarios
    // JavaScript — Blade las compila aunque esten en un comentario y produce PHP
    // invalido al renderizar la vista.
    var ROUTE_ALM = @json(route('almacen.almacenes.store'));
    function ROUTE_ALM_ITEM(id) { return ROUTE_INDEX + '/almacenes/' + id; }
    function ROUTE_PROD_ITEM(id) { return ROUTE_INDEX + '/productos/' + id; }
    // Datos de los almacenes visibles (para el modal de edición): { id: {NOMBRE,TIPO,CODIGO,UBICACION,frentes:[ids]} }
    window.almAlmacenesData = @json($almacenesData);

    // Selección del custom-dropdown "Tipo" en el modal de almacén
    window.almNvTipoSelect = function (value, label) {
        var hidden = document.getElementById('almNvTipo');
        var display = document.getElementById('almNvTipoDisplay');
        var dropdown = document.getElementById('almNvTipoDropdown');
        if (hidden) hidden.value = value;
        if (display) display.value = label;
        // Marcar el item seleccionado
        dropdown.querySelectorAll('.dropdown-item').forEach(function(i) {
            i.classList.toggle('selected', i.dataset.value === value);
        });
        // Cerrar el dropdown (dejar que el CSS lo oculte al quitar .active)
        dropdown.classList.remove('active');
        var content = dropdown.querySelector('.dropdown-content');
        if (content) content.style.display = '';
        var trigger = dropdown.querySelector('.dropdown-trigger');
        if (trigger) trigger.style.borderColor = '#cbd5e0';
        // Actualizar visibilidad del panel de frentes
        window.almToggleFrentes();
    };

    window.almToggleFrentes = function () {
        var wrap = el('almNvFrentesWrap'); if (!wrap) return;
        wrap.style.display = (val('almNvTipo') === 'PROYECTO') ? '' : 'none';
    };
    // Checkboxes del multiselect de frentes del modal de almacén.
    function almNvFrenteChecks() { return Array.prototype.slice.call(document.querySelectorAll('#almNvFrentesSelect input[type="checkbox"]')); }
    // Filtra las opciones de frente al escribir en el input principal del trigger.
    window.almNvFrentesFilter = function (inp) {
        var v = (inp && inp.value || '').toLowerCase().trim();
        var visibles = 0;
        document.querySelectorAll('#almNvFrentesSelect .alm-frente-opt').forEach(function (i) {
            var match = v === '' || i.textContent.toLowerCase().indexOf(v) > -1;
            i.style.display = match ? '' : 'none';
            if (match) visibles++;
        });
        var noMatch = el('almNvFrentesNoMatch');
        if (noMatch) noMatch.style.display = (v !== '' && visibles === 0) ? '' : 'none';
        // Mientras filtra, mantener el menú abierto.
        var box = el('almNvFrentesSelect');
        if (box && v !== '' && !box.classList.contains('active')) box.classList.add('active');
    };
    // Actualiza el placeholder del trigger según cuántos frentes están marcados.
    window.almNvFrentesUpdate = function () {
        var inp = el('almNvFrentesInput'); if (!inp) return;
        var sel = almNvFrenteChecks().filter(function (c) { return c.checked; });
        if (sel.length === 0)      inp.placeholder = 'Selecciona los frentes…';
        else if (sel.length === 1) {
            var sp = sel[0].closest('.multiselect-item').querySelector('span');
            inp.placeholder = sp ? sp.textContent.trim() : '1 frente';
        }
        else inp.placeholder = sel.length + ' frentes seleccionados';
    };
    function almNvSetFrentes(ids) {
        var set = {}; (ids || []).forEach(function (x) { set[String(x)] = true; });
        almNvFrenteChecks().forEach(function (c) { c.checked = !!set[c.value]; });
        // Reset del filtro (vaciar el input principal y volver a mostrar todas las opciones).
        var inp = el('almNvFrentesInput'); if (inp) inp.value = '';
        var box = el('almNvFrentesSelect');
        if (box) {
            box.querySelectorAll('.alm-frente-opt').forEach(function (i) { i.style.display = ''; });
            var nm = el('almNvFrentesNoMatch'); if (nm) nm.style.display = 'none';
            box.classList.remove('active');
        }
        window.almNvFrentesUpdate();
    }
    function almResetAlmacenModal() {
        delete el('almAlmacenModal').dataset.idAlmacen;
        el('almNvNombre').value = ''; el('almNvUbicacion').value = '';
        if (el('almNvAlmacenista'))      el('almNvAlmacenista').value = '';
        if (el('almNvCargoAlmacenista')) el('almNvCargoAlmacenista').value = '';
        almNvTipoSelect('PROYECTO', 'Proyecto (Limitado a frentes específicos)');
        almNvSetFrentes([]);
        showErr('almNvError', '');
    }
    window.almAbrirAlmacen = function () {
        if (!ensurePerm(HAS_ALM_MANAGE, 'No tienes permiso para crear almacenes.')) return;
        almResetAlmacenModal();
        el('almNvTitulo').textContent = 'Nuevo almacén'; el('almNvSubmit').textContent = 'Guardar';
        open('almAlmacenModal'); setTimeout(function () { el('almNvNombre').focus(); }, 60);
    };
    window.almEditarAlmacen = function (id) {
        if (!ensurePerm(HAS_ALM_MANAGE, 'No tienes permiso para editar almacenes.')) return;
        var d = (window.almAlmacenesData || {})[id]; if (!d) { toast('No se encontró el almacén.', 'error'); return; }
        almResetAlmacenModal();
        el('almAlmacenModal').dataset.idAlmacen = id;
        el('almNvTitulo').textContent = 'Editar almacén'; el('almNvSubmit').textContent = 'Guardar cambios';
        el('almNvNombre').value = d.NOMBRE || ''; el('almNvUbicacion').value = d.UBICACION || '';
        if (el('almNvAlmacenista'))      el('almNvAlmacenista').value      = d.ALMACENISTA || '';
        if (el('almNvCargoAlmacenista')) el('almNvCargoAlmacenista').value = d.CARGO_ALMACENISTA || '';
        var tipo = d.TIPO || 'PROYECTO';
        almNvTipoSelect(tipo, tipo === 'GENERAL' ? 'Global (Todos los frentes)' : 'Proyecto (Limitado a frentes específicos)');
        almNvSetFrentes(d.frentes || []);
        window.almToggleFrentes();
        almCerrar('almAdminAlmacenesModal');
        open('almAlmacenModal'); setTimeout(function () { el('almNvNombre').focus(); }, 60);
    };
    window.almGuardarAlmacen = function () {
        if (!ensurePerm(HAS_ALM_MANAGE, 'No tienes permiso para guardar almacenes.')) return;
        var m = el('almAlmacenModal');
        if (!m) { toast('Modal no encontrado.', 'error'); return; } // defensa: nunca deberia pasar
        var id = m.dataset.idAlmacen || null;
        var nombre = val('almNvNombre'), tipo = val('almNvTipo') || 'PROYECTO';
        var almacenista = val('almNvAlmacenista');
        var cargo       = val('almNvCargoAlmacenista');
        // Validacion local: mostramos banner + toast + foco. Sin esto el usuario solo
        // veia una linea chiquita al pie del modal y reportaba "el boton no hace nada".
        function _fail(msg, focusId) {
            showErr('almNvError', msg);
            toast(msg, 'error');
            if (focusId) { var inp = el(focusId); if (inp) inp.focus(); }
        }
        if (!nombre)      { _fail('El nombre es obligatorio.',                'almNvNombre');          return; }
        if (!tipo)        { _fail('El tipo es obligatorio.',                  'almNvTipoDisplay');     return; }
        if (!almacenista) { _fail('El almacenista es obligatorio.',           'almNvAlmacenista');     return; }
        if (!cargo)       { _fail('El cargo del almacenista es obligatorio.', 'almNvCargoAlmacenista');return; }
        var frentes = [];
        if (tipo === 'PROYECTO') {
            almNvFrenteChecks().forEach(function (c) { if (c.checked) frentes.push(parseInt(c.value, 10)); });
            if (frentes.length === 0) { _fail('Selecciona al menos un frente.', 'almNvFrentesInput'); return; }
        }
        var url = id ? ROUTE_ALM_ITEM(id) : ROUTE_ALM;
        pre();
        fetch(url, {
            method: id ? 'PATCH' : 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: JSON.stringify({
                NOMBRE:            nombre,
                TIPO:              tipo,
                UBICACION:         val('almNvUbicacion') || null,
                ALMACENISTA:       val('almNvAlmacenista') || null,
                CARGO_ALMACENISTA: val('almNvCargoAlmacenista') || null,
                frentes:           frentes,
            })
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            unpre();
            if (res.ok) {
                almCerrar('almAlmacenModal'); toast(res.b.message || (id ? 'Almacén actualizado.' : 'Almacén creado.'));
                var newId = res.b.almacen && (res.b.almacen.ID_ALMACEN || res.b.almacen.id);
                // recargar: cambió la lista del selector / nombres
                setTimeout(function () { window.location = ROUTE_INDEX + ((id || newId) ? ('?id_almacen=' + (id || newId)) : ''); }, 500);
            } else {
                // Error del servidor (validacion 422, conflicto, etc.). Mostramos
                // banner + toast — el toast es la garantia visual de que algo paso.
                var msg = (res.b && res.b.message) || 'No se pudo guardar el almacén.';
                if (res.b && res.b.errors) { msg = Object.values(res.b.errors).map(function (a) { return a.join(' '); }).join(' '); }
                showErr('almNvError', msg);
                toast(msg, 'error');
            }
        })
        .catch(function () { unpre(); showErr('almNvError', 'Error de red.'); toast('Error de red.', 'error'); });
    };
    window.almAbrirAdminAlmacenes = function () {
        if (!ensurePerm(HAS_ALM_MANAGE, 'No tienes permiso para gestionar almacenes.')) return;
        open('almAdminAlmacenesModal');
    };
    window.almEliminarAlmacen = function (id, nombre) {
        if (!ensurePerm(HAS_ALM_MANAGE, 'No tienes permiso para eliminar almacenes.')) return;
        almConfirm('¿Eliminar el almacén "<strong>' + nombre + '</strong>"? Si tiene movimientos registrados se desactivará en lugar de borrarse.', function () {
            pre();
            fetch(ROUTE_ALM_ITEM(id), { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
            .then(function (res) { unpre(); if (res.ok) { toast(res.b.message || 'Almacén eliminado.'); setTimeout(function () { window.location = ROUTE_INDEX; }, 500); } else { toast((res.b && res.b.message) || 'No se pudo eliminar.', 'error'); } })
            .catch(function () { unpre(); toast('Error de red.', 'error'); });
        });
    };

    function almResetProductoModal() {
        delete el('almProductoModal').dataset.idProducto;
        el('almProdCodigo').value = ''; el('almProdNombre').value = ''; el('almProdUm').value = 'UND'; el('almProdCategoria').value = '';
        if (el('almProdUbicacion')) el('almProdUbicacion').value = '';
        if (el('almProdCantInicial')) el('almProdCantInicial').value = '';
        var cs = el('almProdCatSuggest'); if (cs) cs.innerHTML = '';
        var us = el('almProdUmSuggestBox'); if (us) { us.innerHTML = ''; us.classList.remove('open'); }
        almProdCatHide();
        // Limpiar resaltados de error de todos los campos del modal
        almProdFieldErr('almProdCodigo',  false);
        almProdFieldErr('almProdNombre',  false);
        almProdFieldErr('almProdUm',      false);
        showErr('almProdError', '');
    }
    window.almAbrirProducto = function () {
        if (!ensurePerm(HAS_PRODUCTOS, 'No tienes permiso para crear productos.')) return;
        almResetProductoModal();
        el('almProdTitulo').textContent = 'Nuevo producto'; el('almProdSubmit').textContent = 'Guardar';
        el('almProdCodigo').readOnly = false; el('almProdCodigo').style.background = '';
        // Mostrar "Cantidad inicial" solo si hay un almacén seleccionado (el producto se
        // registrará en ese almacén). Si no hay, ocultamos el campo (no tiene sentido).
        var wrap = el('almProdCantInicialWrap');
        if (wrap) wrap.style.display = almSelAlmacenActual() ? '' : 'none';
        open('almProductoModal'); setTimeout(function () { el('almProdCodigo').focus(); }, 60);
    };
    window.almEditarProducto = function (id, cod, nom, um, cat, ubicacion) {
        if (!ensurePerm(HAS_PRODUCTOS, 'No tienes permiso para editar productos.')) return;
        almResetProductoModal();
        el('almProductoModal').dataset.idProducto = id;
        el('almProdTitulo').textContent = 'Editar producto'; el('almProdSubmit').textContent = 'Guardar';
        // El código es de sólo lectura al editar (puede ser PRD-XXXX o numérico).
        el('almProdCodigo').value = cod || ''; el('almProdCodigo').readOnly = true; el('almProdCodigo').style.background = '#f1f5f9';
        el('almProdNombre').value = nom || ''; el('almProdUm').value = um || 'UND'; el('almProdCategoria').value = cat || '';
        if (el('almProdUbicacion')) el('almProdUbicacion').value = ubicacion || '';
        // Cantidad inicial: solo aplica al CREAR. Al editar se oculta — el saldo se cambia
        // desde el modal de Ajuste / Entrada / Salida.
        var wrap = el('almProdCantInicialWrap'); if (wrap) wrap.style.display = 'none';
        open('almProductoModal'); setTimeout(function () { el('almProdNombre').focus(); }, 60);
    };
    window.almGuardarProducto = function () {
        if (!ensurePerm(HAS_PRODUCTOS, 'No tienes permiso para guardar productos.')) return;
        var m = el('almProductoModal'), id = m.dataset.idProducto || null;
        var codigo = val('almProdCodigo'), nombre = val('almProdNombre'), um = val('almProdUm') || 'UND', cat = val('almProdCategoria');
        var ubicacion = val('almProdUbicacion');
        // Validaciones previas al envío.
        if (!nombre) { almProdFieldErr('almProdNombre', true); showErr('almProdError', 'La descripción es obligatoria.'); return; }
        // Al crear: el código manual debe ser solo dígitos enteros positivos.
        // Al editar: el código es readonly (puede ser PRD-XXXX), no se valida aquí.
        if (!id && codigo && (!/^\d+$/.test(codigo) || parseInt(codigo, 10) < 1)) {
            almProdFieldErr('almProdCodigo', true);
            showErr('almProdError', 'El código debe ser un número entero positivo.');
            return;
        }
        // Cantidad inicial (solo al CREAR y solo si hay almacén seleccionado).
        var idAlmacen = !id ? almSelAlmacenActual() : '';
        var cantInicial = 0;
        if (!id && idAlmacen) {
            var rawCant = val('almProdCantInicial');
            if (rawCant !== '' && rawCant != null) {
                var nCant = Number(rawCant);
                if (!isFinite(nCant) || nCant < 0) {
                    showErr('almProdError', 'La cantidad inicial debe ser un número ≥ 0.');
                    return;
                }
                cantInicial = nCant;
            }
        }
        // Limpiar errores visuales antes de enviar
        almProdFieldErr('almProdCodigo', false);
        almProdFieldErr('almProdNombre', false);
        pre();
        var bodyCreate = { CODIGO: codigo || null, NOMBRE: nombre, UM: um, CATEGORIA: cat || null, UBICACION: ubicacion || null };
        if (idAlmacen) {
            bodyCreate.id_almacen      = parseInt(idAlmacen, 10);
            bodyCreate.cantidad_inicial = cantInicial;
        }
        fetch(id ? ROUTE_PROD_ITEM(id) : ROUTE_PROD, {
            method: id ? 'PATCH' : 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: JSON.stringify(id
                // Al editar: CODIGO no se incluye → el backend conserva el existente (evita conflicto con regex).
                ? { NOMBRE: nombre, UM: um, CATEGORIA: cat || null, UBICACION: ubicacion || null }
                // Al crear: CODIGO + opcionalmente id_almacen + cantidad_inicial para asegurar/abrir la fila en el almacén actual.
                : bodyCreate
            )
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            unpre();
            if (res.ok) { almCerrar('almProductoModal'); toast(res.b.message || (id ? 'Producto actualizado.' : 'Producto creado.')); almCargar(); }
            else {
                var msg = (res.b && res.b.message) || 'No se pudo guardar el producto.';
                var fieldError = false;
                if (res.b && res.b.errors) {
                    msg = Object.values(res.b.errors).map(function (a) { return a.join(' '); }).join(' ');
                    // Resaltar el campo específico según la clave de error
                    if (res.b.errors.CODIGO)  { almProdFieldErr('almProdCodigo', true);  fieldError = true; }
                    if (res.b.errors.NOMBRE)  { almProdFieldErr('almProdNombre', true);  fieldError = true; }
                    if (res.b.errors.UM)      { almProdFieldErr('almProdUm',     true);  fieldError = true; }
                }
                showErr('almProdError', msg);
            }
        })
        .catch(function () { unpre(); showErr('almProdError', 'Error de red.'); });
    };
    window.almEliminarProducto = function (id) {
        if (!ensurePerm(HAS_PRODUCTOS, 'No tienes permiso para eliminar productos.')) return;
        almConfirm('¿Eliminar este producto?', function () {
            pre();
            fetch(ROUTE_PROD_ITEM(id), { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
            .then(function (res) { unpre(); if (res.ok) { toast(res.b.message || 'Producto eliminado.'); almCargar(); } else { toast((res.b && res.b.message) || 'No se pudo eliminar.', 'error'); } })
            .catch(function () { unpre(); toast('Error de red.', 'error'); });
        });
    };
    // Stubs de la rama alternativa fueron removidos: cada funcion CRUD verifica
    // permiso via ensurePerm(...) al inicio y muestra toast si falta. Sin guard
    // duplicado en el bloque.

    @if($puedeMover)
    // ── Modal "Registrar salida" unificado ─────────────────────────────────────
    //  Un solo formulario para ambos casos: salida para consumo (mismo almacén) o
    //  salida hacia otro proyecto (TRASPASO). El backend decide qué hacer según el
    //  frente destino — ambos generan Nota de Entrega NE-YYYY-NNNN.
    //  ALM_SAL.idAlmacen = almacén de origen (el que muestra la tabla).
    var ALM_SAL = { idAlmacen: '' };
    window.almAbrirSalidaModal = function (idAlmacen) {
        ALM_SAL = { idAlmacen: String(idAlmacen || '') };
        // Limpiar campos de Nota de Entrega y poner FECHA = hoy por default.
        ['almSalidaContrato','almSalidaRq','almSalidaSolicitante','almSalidaDepartamento','almSalidaMotivo'].forEach(function (id) { var e = el(id); if (e) e.value = ''; });
        // El campo Proyecto es un custom-dropdown: lo reseteamos con su helper para que
        // el placeholder vuelva al default y el hidden #almSalidaProyecto quede vacío.
        if (typeof window.clearDropdownFilter === 'function') {
            window.clearDropdownFilter('almSalidaProyectoDropdown');
        }
        var fe = el('almSalidaFecha'); if (fe) fe.value = new Date().toISOString().slice(0, 10);
        // Reset del dropdown de contratos: vaciar items, cerrar el panel, ocultar el clear-btn.
        // La lista se rellena cuando el usuario elige proyecto destino.
        var citems = el('almSalidaContratoItems'); if (citems) citems.innerHTML = '';
        var cdd    = el('almSalidaContratoDropdown'); if (cdd) cdd.classList.remove('active');
        var cbtn   = el('almSalidaContratoClearBtn'); if (cbtn) cbtn.style.display = 'none';
        showErr('almSalidaError', '');
        // Asegurar que el dropdown de Proyecto NO quede abierto si una sesion previa lo
        // dejo con .active (el helper global focusin auto-abre cuando el input del trigger
        // recibe foco — por eso evitamos hacer .focus() automatico al abrir el modal).
        var ddProy = el('almSalidaProyectoDropdown');
        if (ddProy) ddProy.classList.remove('active');
        open('almSalidaModal');
    };
    // Campo "Contrato N°" del modal Registrar salida — es un custom-dropdown (mismo
    // componente que Proyecto) con UNA particularidad: el input es libre. El usuario
    // puede (a) elegir un contrato de la lista, (b) escribir uno nuevo que no este en
    // la lista, o (c) dejarlo en blanco (es opcional). La fuente de la verdad para el
    // payload es SIEMPRE el .value del input visible #almSalidaContrato — no hay hidden.
    //
    // Lista de items: se rebuilds desde window.almFrenteContratos[idFrente] cada vez que
    // cambia el Proyecto destino. La apertura/cierre del panel la maneja el sistema global
    // de custom-dropdown (uicomponents.js) — no duplicamos esa logica aqui.
    function almSalidaContratoSync() {
        // Mostrar/ocultar el boton "x" de limpiar segun haya texto en el input.
        var inp = el('almSalidaContrato');
        var btn = el('almSalidaContratoClearBtn');
        if (!inp || !btn) return;
        btn.style.display = (inp.value || '').trim() ? 'block' : 'none';
    }
    function almSalidaContratoBuildList(idFrente) {
        var box = el('almSalidaContratoItems');
        if (!box) return;
        var list = ((window.almFrenteContratos || {})[idFrente] || []);
        if (!list.length) {
            // Mensaje informativo dentro del propio panel del dropdown — no rompe layout
            // (el panel flota absolutamente, no empuja el modal hacia abajo).
            box.innerHTML = '<div style="padding:8px 12px;font-size:12px;color:#94a3b8;font-style:italic;">Sin contratos previos — puedes escribirlo.</div>';
            return;
        }
        // dropdown-item es la misma clase que usa Proyecto — hereda el hover/selected del
        // sistema global. El click llama almSalidaContratoPick (no selectOption, porque NO
        // queremos que el sistema toque hidden/placeholder/clear-btn — eso lo manejamos aqui).
        box.innerHTML = list.map(function (c) {
            var safe = escHtml(c);
            var jsArg = safe.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
            return '<div class="dropdown-item" data-value="' + safe + '" onclick="window.almSalidaContratoPick(\'' + jsArg + '\')">' + safe + '</div>';
        }).join('');
    }
    window.almSalidaContratoFilter = function (input) {
        // Filtro local de items por lo que el usuario teclea — mismo comportamiento que
        // filterDropdownOptions del sistema global, pero contenido al item-list de contrato.
        // Reimplementamos en lugar de delegar para evitar acoplarse a data-filter-type/value
        // (este dropdown no usa hidden + label, es input libre).
        var box = el('almSalidaContratoItems');
        if (!box) return;
        var norm = function (s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
        var term = norm(input.value);
        box.querySelectorAll('.dropdown-item').forEach(function (item) {
            var show = !term || norm(item.textContent).indexOf(term) !== -1;
            item.style.setProperty('display', show ? 'block' : 'none', 'important');
        });
        almSalidaContratoSync();
    };
    window.almSalidaContratoPick = function (c) {
        var inp = el('almSalidaContrato');
        if (inp) inp.value = c || '';
        // Re-mostrar TODOS los items para la proxima apertura (el filtro previo pudo ocultar varios).
        var box = el('almSalidaContratoItems');
        if (box) box.querySelectorAll('.dropdown-item').forEach(function (item) { item.style.removeProperty('display'); });
        var dd = el('almSalidaContratoDropdown');
        if (dd) dd.classList.remove('active');
        almSalidaContratoSync();
    };
    window.almSalidaContratoClear = function () {
        var inp = el('almSalidaContrato'); if (inp) inp.value = '';
        var box = el('almSalidaContratoItems');
        if (box) box.querySelectorAll('.dropdown-item').forEach(function (item) { item.style.removeProperty('display'); });
        almSalidaContratoSync();
        if (inp) inp.focus();
    };

    window.almSalidaOnProyectoChange = function () {
        var sel  = el('almSalidaProyecto');
        if (!sel) return;
        almSalidaContratoBuildList(sel.value);
        // NO auto-abrimos el panel: el campo Contrato N° es opcional y abrirlo
        // automaticamente al elegir proyecto resultaba intrusivo. El usuario decide
        // cuando ver la lista haciendo clic en el trigger o en el input.
    };
    // Payload de la salida congelado al apretar "Vista previa". Lo reusamos en
    // "Registrar" del preview para que el PDF final corresponda EXACTAMENTE al que
    // el usuario revisó (si edita despues, se regenera al apretar "Vista previa"
    // de nuevo). Tambien guardamos el blob URL del preview para revocarlo al
    // cerrar el modal y no acumular memoria.
    var almSalidaDraft   = null;
    var almPreviewBlobUrl = null;

    // Construye el payload desde los campos del modal + almSeleccion. Devuelve
    // null y muestra error si falta el frente destino o no hay lineas validas.
    // Lo usan almSalidaVistaPrevia (POST a preview) y almPreviewConfirmar (POST
    // a lote real) — separado para garantizar consistencia: el PDF preview y el
    // registro final se generan a partir del MISMO payload.
    function almSalidaConstruirPayload() {
        var v = function (id) { var e = el(id); return e ? e.value.trim() : ''; };
        var idFrenteDest = v('almSalidaProyecto');
        if (!idFrenteDest) { showErr('almSalidaError', 'Elige el proyecto / frente destino.'); return null; }
        var lineas = [], faltan = [];
        Object.keys(almSeleccion).forEach(function (id) {
            var s   = almSeleccion[id] || {};
            var raw = String(s.cantidad == null ? '' : s.cantidad).replace(',', '.').trim();
            var c   = parseFloat(raw);
            var nombre = s.nombre || ('#' + id);
            if (!isFinite(c) || c <= 0) faltan.push(nombre);
            else lineas.push({ id_producto: parseInt(id, 10), cantidad: c });
        });
        if (!lineas.length) { showErr('almSalidaError', 'Indica una cantidad mayor que cero en al menos un producto (columna "Cant. salida" de la tabla).'); return null; }
        if (faltan.length)  { showErr('almSalidaError', 'Falta indicar la cantidad de salida (debe ser mayor que cero) en: ' + faltan.slice(0, 4).join(', ') + (faltan.length > 4 ? '…' : '') + '. Corrígelos en la tabla o deselecciónalos.'); return null; }
        showErr('almSalidaError', '');

        // Único endpoint: registrarMovimientoLote tipo=SALIDA + id_frente_destino.
        // El backend decide internamente:
        //   - Si el frente destino comparte el almacén origen → SALIDA pura (consumo).
        //   - Si el frente destino tiene OTRO almacén → crea un Traspaso + envía + asigna
        //     NUMERO_NOTA. En ambos casos se devuelve nota_url con el PDF.
        var payload = {
            tipo:               'SALIDA',
            id_almacen:         ALM_SAL.idAlmacen,
            id_frente_destino:  parseInt(idFrenteDest, 10),
            id_frente:          parseInt(idFrenteDest, 10), // back-compat: SALIDA mismo-almacén usa id_frente
            lineas:             lineas,
        };
        var fecha  = v('almSalidaFecha');         if (fecha)  payload.fecha = fecha;
        var contr  = v('almSalidaContrato');      if (contr)  payload.numero_contrato = contr;
        var rqN    = v('almSalidaRq');            if (rqN)    payload.numero_rq = rqN;
        var solic  = v('almSalidaSolicitante');   if (solic)  payload.solicitante = solic;
        var depto  = v('almSalidaDepartamento');  if (depto)  payload.departamento = depto;
        var motivo = v('almSalidaMotivo');        if (motivo) payload.motivo = motivo;
        return payload;
    }

    // Vista previa: POSTea al endpoint /salida/preview-pdf (NO commitea nada),
    // recibe el binario del PDF y lo carga en el iframe del modal #almPreviewModal.
    // Guarda el payload en almSalidaDraft para que "Registrar" del preview lo
    // reuse exactamente igual.
    window.almSalidaVistaPrevia = function () {
        var payload = almSalidaConstruirPayload();
        if (!payload) return;
        almSalidaDraft = payload;

        pre();
        fetch(ROUTE_PREVIEW_SALIDA, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/pdf, application/json' },
            body: JSON.stringify(payload),
        })
        .then(function (r) {
            // El backend devuelve PDF binario en exito, JSON con {message} en error.
            var ct = r.headers.get('Content-Type') || '';
            if (!r.ok) {
                return r.json().then(function (b) { throw new Error(b.message || 'No se pudo generar la vista previa.'); });
            }
            if (ct.indexOf('application/pdf') === -1) {
                throw new Error('Respuesta inesperada del servidor (no es PDF).');
            }
            return r.blob();
        })
        .then(function (blob) {
            unpre();
            // Revocar blob URL viejo (sesion anterior) antes de crear uno nuevo
            // para no filtrar memoria si el usuario reabre el preview varias veces.
            if (almPreviewBlobUrl) { try { URL.revokeObjectURL(almPreviewBlobUrl); } catch (e) {} }
            almPreviewBlobUrl = URL.createObjectURL(blob);
            var frame = el('almPreviewFrame');
            // Fragment hint del PDF viewer integrado de Chrome/Edge/Firefox: oculta
            // toolbar (barra de imprimir/descargar/menu), navpanes (panel lateral
            // de paginas) y la scrollbar — el preview se ve "como pdf empotrado",
            // sin chrome del navegador. Safari ignora estos params (no es soportado
            // oficialmente) pero no rompe nada.
            if (frame) frame.src = almPreviewBlobUrl + '#toolbar=0&navpanes=0&scrollbar=0&view=FitH';
            // Ocultar el modal de salida (sin destruir sus inputs — almCerrar solo
            // quita .open, los valores quedan listos para "Editar").
            almCerrar('almSalidaModal');
            open('almPreviewModal');
        })
        .catch(function (err) {
            unpre();
            showErr('almSalidaError', err.message || 'Error generando vista previa.');
        });
    };

    // "Editar" del modal preview: vuelve al modal de salida con todos los datos
    // intactos (los inputs no se destruyen, solo se ocultan via .open). El usuario
    // puede cambiar campos del formulario o salir, modificar la seleccion en la
    // tabla, y volver a apretar "Vista previa" — se regenera el PDF.
    window.almPreviewEditar = function () {
        almCerrar('almPreviewModal');
        open('almSalidaModal');
    };

    // Cerrar preview con la X: equivalente a "Editar" — vuelve al modal de salida
    // con los datos preservados, asi el usuario decide si cancela todo (boton
    // Cancelar del modal de salida) o continua. Limpia el blob URL para no
    // acumular memoria, pero NO descarta el draft (lo descarta almAbrirSalidaModal
    // cuando se reabre el modal con un id distinto).
    window.almPreviewCerrar = function () {
        almCerrar('almPreviewModal');
        if (almPreviewBlobUrl) { try { URL.revokeObjectURL(almPreviewBlobUrl); } catch (e) {} almPreviewBlobUrl = null; }
        var frame = el('almPreviewFrame'); if (frame) frame.src = 'about:blank';
        open('almSalidaModal');
    };

    // "Registrar" del preview: POSTea el draft al endpoint real (movimientos-lote)
    // que SI guarda en BD, asigna NUMERO_NOTA y devuelve la URL del PDF final.
    // Tras el commit, descargamos el PDF al disco (fetch → blob → anchor) y
    // dejamos al usuario en el modulo de inventario — NO abrimos visor in-page
    // (el flujo ya pidio aprobacion en el modal #almPreviewModal). El payload
    // viene del draft sin reconstruir, asi el PDF final = exactamente lo aprobado.
    window.almPreviewConfirmar = function () {
        if (!almSalidaDraft) { toast('Sin datos para registrar — vuelve a "Editar" y aprieta "Vista previa".', 'error'); return; }
        var payload = almSalidaDraft;

        pre();
        fetch(ROUTE_LOTE, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            unpre();
            if (res.ok) {
                // Exito: cerramos ambos modales, limpiamos seleccion y el draft, recargamos
                // la tabla y DESCARGAMOS el PDF al disco (NO abrimos el visor in-page —
                // el usuario ya aprobo la vista previa, ese segundo visor sobra).
                almCerrar('almPreviewModal');
                almCerrar('almSalidaModal');
                if (almPreviewBlobUrl) { try { URL.revokeObjectURL(almPreviewBlobUrl); } catch (e) {} almPreviewBlobUrl = null; }
                var frame0 = el('almPreviewFrame'); if (frame0) frame0.src = 'about:blank';
                almSalidaDraft = null;
                if (window.almSelClear) window.almSelClear();
                toast(res.b.message || 'Movimiento registrado.');
                almCargar();
                if (res.b && res.b.nota_url) {
                    // fetch → blob → anchor con download: garantiza que el navegador SIEMPRE
                    // guarde como archivo, sin importar Content-Disposition (el endpoint manda
                    // 'inline'). Si usaramos solo `<a href download>`, algunos navegadores
                    // navegan a la URL en la misma pestaña y el usuario "pierde" la pagina
                    // del inventario — con blob URL eso no ocurre nunca.
                    var dlName = res.b.numero_nota ? ('Nota_' + res.b.numero_nota + '.pdf') : 'nota_entrega.pdf';
                    fetch(res.b.nota_url, { headers: { 'Accept': 'application/pdf', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                        .then(function (rr) { return rr.ok ? rr.blob() : null; })
                        .then(function (blob) {
                            if (!blob) return;
                            var burl = URL.createObjectURL(blob);
                            var a = document.createElement('a');
                            a.href = burl; a.download = dlName; a.style.display = 'none';
                            document.body.appendChild(a); a.click(); document.body.removeChild(a);
                            setTimeout(function () { try { URL.revokeObjectURL(burl); } catch (e) {} }, 2000);
                        })
                        .catch(function () { /* silencioso: el movimiento ya se registro, la descarga es secundaria */ });
                }
            } else {
                // Error tardio (algo cambio entre el preview y el confirm: stock se
                // movio, validacion fallo, etc.). Cerramos preview y reabrimos salida
                // con el mensaje. Mostramos el error EN DOS LADOS:
                //   1) toast — visible incluso si el usuario cierra el modal de salida
                //      sin leer (clave para no creer que se registro cuando no fue asi).
                //   2) inline (almSalidaError) — contexto al pie del modal de salida.
                var msg = (res.b && res.b.message) || 'No se pudo registrar el movimiento.';
                if (res.b && res.b.errors) msg = Object.values(res.b.errors).map(function (a) { return a.join(' '); }).join(' ');
                window.almPreviewCerrar(); // tambien reabre salida
                showErr('almSalidaError', msg);
                toast(msg, 'error');
            }
        })
        .catch(function () {
            unpre();
            window.almPreviewCerrar();
            var netMsg = 'Error de red al confirmar la salida.';
            showErr('almSalidaError', netMsg);
            toast(netMsg, 'error');
        });
    };
    @else
    window.almAbrirSalidaModal = function () { toast('No tienes permiso para registrar movimientos.', 'error'); };
    @endif

    // La tabla abre VACÍA. Si la URL trae un filtro de contenido (search / categoria /
    // id_producto), se carga al entrar; si no, queda en blanco hasta que el usuario use
    // un filtro. Con el patron placeholder-background, value="" siempre — leemos del
    // data-active. Incluir almBuscarPickedId garantiza que un link directo del tipo
    // ?id_producto=NNN dispare la carga y pinte el sidebar cruzado "En otros almacenes".
    (function () {
        var b = el('almFiltroBuscar'), c = el('almFiltroCat');
        var bActivo = b && ((b.value && b.value.trim()) || (b.dataset.active && b.dataset.active.trim()));
        var cActivo = c && ((c.value && c.value.trim()) || (c.dataset.active && c.dataset.active.trim()));
        if (bActivo || cActivo || almBuscarPickedId) window.almCargar();
    })();

    // ── Posicion del Consolidado de Inventario en mobile ─────────────────────
    // Por default el sidebar es SIBLING del .admin-card dentro de .page-layout-grid;
    // cuando el grid colapsa a 1 columna en mobile termina abajo de TODO. El cliente
    // lo quiere ENTRE el boton "Acciones" y la tabla (debajo del header de filtros).
    //
    // Anchor mobile: #almFilters (el bloque de filtros Buscar+Categoria, que vive
    // justo bajo el header del modulo donde esta el menu de Acciones). Insertando
    // la sidebar despues de ese nodo queda inmediatamente debajo del boton Acciones
    // y arriba de la tabla — la posicion original que pidio el cliente.
    // Anchor desktop: re-anclar al .page-layout-grid para que sea sibling del
    // admin-card y aparezca a la derecha.
    (function placeSidebarMobile() {
        var BREAKPOINT = 768;
        function place() {
            var sidebar = document.querySelector('.counter-sidebar');
            var filters = document.getElementById('almFilters');
            var grid    = document.querySelector('.page-layout-grid');
            if (!sidebar || !filters || !grid) return;
            if (window.innerWidth <= BREAKPOINT) {
                // Mobile: dentro de admin-card, justo despues de #almFilters
                // (bajo el header / boton Acciones, arriba de la tabla).
                if (sidebar.previousElementSibling !== filters) {
                    filters.parentNode.insertBefore(sidebar, filters.nextSibling);
                }
            } else {
                // Desktop: re-anclar al .page-layout-grid (sibling del admin-card).
                if (sidebar.parentNode !== grid) {
                    grid.appendChild(sidebar);
                }
            }
        }
        place();
        var resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(place, 100);
        });
    })();
})();
</script>
@endsection
