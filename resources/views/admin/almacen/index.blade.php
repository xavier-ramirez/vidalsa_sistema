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
    /* El icono NO lleva padding a la derecha y el campo solo 4px a la izquierda: así el texto
       que se escribe queda pegado a la lupa (antes eran 10+6=16px de hueco). Misma separación
       que los filtros de /admin/equipos, que lo resuelven con la regla
       `.dropdown-trigger:has(> input)` de estilos_globales.css — aquí el patrón es otro
       (.alm-filter-box), por eso se ajusta en su propia regla en vez de duplicar aquella. */
    .alm-filter .alm-ic { padding: 0 0 0 10px; display: flex; align-items: center; color: #64748b; }
    .alm-filter input[type="text"] {
        flex: 1; border: none; background: transparent; outline: none; font-size: 14px;
        color: #1e293b; padding: 10px 6px 10px 4px; min-width: 0; height: 100%; cursor: text;
    }
    .alm-filter .filter-clear { padding: 0 8px; color: #64748b; font-size: 18px; cursor: pointer; }
    /* El icono "escanear QR" que va dentro del buscador (.qrs-ic) trae su propio estilo
       en el partial compartido admin.almacen.partials.scan_modal. */

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
    /* Tooltip-bubble con la UBICACION y los equipos del producto: aparece al pasar el mouse por
       cualquier parte de la fila, pero SOLO ya colocada por almTipShow (.alm-tip-on, fuera de
       la tabla y por encima de todo). Sin esa condición, al hacer clic, desplazar o pasar por
       otra celda la burbuja volvía a su sitio dentro de la tabla y salía recortada. */
    .alm-row:hover .tooltip-bubble.alm-tip-on { opacity: 1 !important; visibility: visible !important; }
    /* Elevar la celda al hover para que el tooltip quede POR ENCIMA del thead sticky
       (z-index:2) y de las demás filas — si no, el encabezado oscuro lo tapaba. */
    .alm-row:hover .alm-td-nombre { z-index: 9000; }
    /* Reposo de la burbuja FUERA de flujo. CLAVE contra la deformación: almTipShow la pone en
       position:fixed al hover y almTipReset LIMPIA el inline al salir/scrollear. Sin esta regla,
       al limpiar el inline la posición caía a `static` → la burbuja entraba EN FLUJO y, como
       visibility:hidden igual ocupa alto, la fila CRECÍA (~64→126px) tras hacer foco/scroll. Solo
       hace falta `position` (el resto del posicionado ya vive en el inline y lo re-setea almTipShow). */
    .alm-td-nombre > .tooltip-bubble { position: absolute; }
    /* Números de parte clickeables en la descripción del filtro: el elegido para la salida
       va en negrita + subrayado; los demás se subrayan al pasar el mouse. Sin azul ni caja. */
    .alm-parte-opt { cursor: pointer; }
    .alm-parte-opt:hover { text-decoration: underline; }
    .alm-parte-opt.alm-parte-on { font-weight: 800; text-decoration: underline; text-underline-offset: 2px; }
    /* Fila con stock bajo: rojo muy claro, sin franja a la izquierda (pedido del cliente); el
       aviso lo completa el ícono ⚠ junto al stock. Al hacer hover hereda el azul general como
       cualquier otra fila (sin sobrescribir con !important — antes quedaba naranja en hover y
       se sentia inconsistente). */
    .alm-row-bajo td { background: #fef2f2; }
    /* Fila seleccionable: clic en la fila la marca (estilo /admin/equipos → .selected-row-maquinaria) */
    /* Las filas son seleccionables con clic pero el cursor se mantiene como flecha (sin mano). */
    .alm-table tbody tr.alm-row-clickable { cursor: default; }
    /* Fila SELECCIONADA: azul claro (pedido del cliente: el #93c5fd de antes era muy fuerte),
       aún más oscuro que el hover y la última vista (#e0f2fe) para que se distinga cuál está
       marcada. Persiste tambien en hover (sin !important el hover gana porque viene despues
       en cascada). */
    .alm-table tbody tr.alm-row.selected-row-maquinaria,
    .alm-table tbody tr.alm-row.selected-row-maquinaria:hover,
    .alm-table tbody tr.alm-row.selected-row-maquinaria td,
    .alm-table tbody tr.alm-row.selected-row-maquinaria:hover td { background: #bfdbfe !important; }
    /* Fila de la salida POR CORREGIR (sin cantidad, sin stock o pasada del stock): rojo suave
       en vez del azul de seleccionada, el mismo idioma que el aviso de arriba y la caja de
       cantidad marcada. Va después de la seleccionada para ganarle (misma fuerza). */
    .alm-table tbody tr.alm-row.alm-row-missing-cant,
    .alm-table tbody tr.alm-row.alm-row-missing-cant:hover,
    .alm-table tbody tr.alm-row.alm-row-missing-cant td,
    .alm-table tbody tr.alm-row.alm-row-missing-cant:hover td,
    .alm-table tbody tr.alm-row.alm-row-exceeds-stock,
    .alm-table tbody tr.alm-row.alm-row-exceeds-stock:hover,
    .alm-table tbody tr.alm-row.alm-row-exceeds-stock td,
    .alm-table tbody tr.alm-row.alm-row-exceeds-stock:hover td { background: #fee2e2 !important; }
    /* Anulación local: la regla global `tr.selected-row-maquinaria td { color:#0067b1 }`
       (estilos_globales.css, dentro de su @media min-width:769px) deja TODO el texto azul. En esta tabla solo
       queremos el background azul, NO los textos: el código y el nombre tienen su propio
       color especificado por celda. `unset` + `inherit` aseguran que cada celda use su
       color inline original (font-weight + color de td) en vez del azul global. */
    .alm-table tbody tr.alm-row.selected-row-maquinaria td { color: unset !important; }

    /* Stepper compacto de la columna "Salida" (vive en cada fila de la tabla):
       el input es <type="text" inputmode="decimal"> (sin spinners nativos por construcción)
       y los botones ▲/▼ están manejados por JS — almRowCantKeyDown bloquea letras/signos.
       Los estilos inline del partial pintan el estado DESHABILITADO (gris). Cuando
       almSelMarkRow añade .is-active al wrapper estos selectores ganan con !important
       para revertir el gris a los colores "activos" (texto negro, botón azul). */
    .alm-cant-stepper.is-active { background:#fff !important; border-color:#94a3b8 !important; }
    .alm-cant-stepper.is-active .alm-cant-btn { background:#fff !important; color:#0067b1 !important; cursor:pointer !important; }
    .alm-cant-stepper.is-active .alm-cant-btn:hover { background:#e0f2fe !important; }
    .alm-cant-stepper.is-active .alm-row-cant { color:#0f172a !important; }

    /* Última fila cuyo detalle se abrió con el ojo (almMarcarVista): al cerrar el modal se sabe
       de cuál se venía. Queda en el celeste del hover, como si el mouse siguiera encima (antes
       era un marco azul, que se veía tosco). Tapa el rojo claro de stock bajo —el ⚠ del stock
       lo sigue avisando— y no al azul de seleccionada (!important). En el teléfono, abajo. */
    #almTableBody tr.alm-row.alm-row-vista > td { background: #e0f2fe; }
    /* El motivo, bajo la caja de cantidad. */
    #almTableBody tr.alm-row.alm-row-missing-cant .alm-td-cant::after,
    #almTableBody tr.alm-row.alm-row-exceeds-stock .alm-td-cant::after {
        display: block; margin-top: 3px; font-size: 10px; font-weight: 700; color: #b91c1c; text-align: center; white-space: nowrap;
    }
    /* Filtro con varias equivalencias en una fila SELECCIONADA: los números se ven como
       botones y el elegido va relleno en azul (almRowPartePick). Hasta elegir uno, la fila
       lleva .alm-row-pide-parte: la caja de cantidad espera (almPideParte / almSelMarkRow) y
       tocarla hace destellar los números (almPedirParte). */
    #almTableBody tr.alm-row.selected-row-maquinaria .alm-parte-sep { display: none; }
    #almTableBody tr.alm-row.selected-row-maquinaria .alm-parte-opt {
        display: inline-block; margin: 2px 4px 2px 0; padding: 1px 8px; border: 1px solid #0067b1;
        border-radius: 6px; background: #fff; color: #0067b1; text-decoration: none; line-height: 1.5;
    }
    #almTableBody tr.alm-row.selected-row-maquinaria .alm-parte-opt:hover { background: #e0f2fe; }
    #almTableBody tr.alm-row.selected-row-maquinaria .alm-parte-opt.alm-parte-on { background: #0067b1; color: #fff; font-weight: 800; }
    #almTableBody tr.alm-row.alm-row-pide-parte .alm-parte-list.alm-parte-pulso .alm-parte-opt { animation: almPartePulso .5s ease-in-out 2; }
    @keyframes almPartePulso { 50% { box-shadow: 0 0 0 3px rgba(0, 103, 177, .35); } }
    @media (prefers-reduced-motion: reduce) { #almTableBody .alm-parte-list.alm-parte-pulso .alm-parte-opt { animation: none; } }
    #almTableBody tr.alm-row.alm-row-pide-parte .alm-cant-stepper { pointer-events: none; }
    /* Fila SIN marcar: su caja de cantidad está deshabilitada y un input disabled se traga el
       toque. Así cae en la celda y selecciona la fila (ver el clic de la tabla). */
    #almTableBody tr.alm-row:not(.selected-row-maquinaria) .alm-cant-stepper { pointer-events: none; }
    #almTableBody tr.alm-row.alm-row-pide-parte .alm-td-cant { cursor: pointer; }
    #almTableBody tr.alm-row.alm-row-pide-parte .alm-td-cant::after {
        content: 'Elige la equivalencia'; display: block; margin-top: 3px; font-size: 10px; font-weight: 700;
        color: #0067b1; text-align: center; white-space: nowrap;
    }
    #almTableBody tr.alm-row.alm-row-missing-cant .alm-td-cant::after { content: 'Falta la cantidad'; }
    #almTableBody tr.alm-row.alm-row-missing-cant[data-saldo="0"] .alm-td-cant::after { content: 'Sin stock'; }
    #almTableBody tr.alm-row.alm-row-exceeds-stock .alm-td-cant::after { content: 'Supera el stock'; }
    /* Aviso fijo de la salida por corregir (almPintarAvisoSalida), encima de la tabla. */
    #almSalidaAviso { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; padding: 10px 14px;
                      background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px; color: #991b1b; }
    #almSalidaAviso[hidden] { display: none; }
    #almSalidaAviso > .material-icons { font-size: 20px; color: #dc2626; flex-shrink: 0; margin-top: 1px; }
    #almSalidaAviso > div { font-size: 13px; line-height: 1.4; }
    #almSalidaAviso .alm-aviso-pista { font-size: 12px; color: #b91c1c; margin-top: 2px; }
    /* Volver a pulsar "Registrar salida" con el aviso ya puesto: un meneo corto para que se note. */
    #almSalidaAviso.alm-aviso-sacude { animation: almAvisoSacude .35s ease-in-out 1; }
    @keyframes almAvisoSacude { 25% { transform: translateX(-4px); } 75% { transform: translateX(4px); } }
    @media (prefers-reduced-motion: reduce) { #almSalidaAviso.alm-aviso-sacude { animation: none; } }
    /* Filas de la salida por corregir tras "Registrar salida" (almSelAccion):
         · .alm-row-missing-cant  → sin cantidad (vacía o <= 0), o sin saldo que sacar
         · .alm-row-exceeds-stock → la cantidad tecleada supera el saldo
       Persisten hasta corregir o deseleccionar y sobreviven a las recargas del tbody
       (almSelApplyToRows). La fila va en rojo suave (reglas de la fila seleccionada, arriba) y
       la caja de cantidad en rojo con su motivo debajo. El rojo de la fila no se confunde con
       el de stock bajo: en la salida por corregir todas las filas están seleccionadas, y eso
       ya tapaba ese fondo. El aviso de arriba (#almSalidaAviso) resume cuántos productos
       tienen cada problema; los nombres ya los muestra la tabla. */
    #almTableBody tr.alm-row.alm-row-missing-cant .alm-cant-stepper,
    #almTableBody tr.alm-row.alm-row-exceeds-stock .alm-cant-stepper { border-color:#b91c1c !important; background:#fff !important; box-shadow:0 0 0 2px rgba(220,38,38,0.25); }
    #almTableBody tr.alm-row.alm-row-missing-cant .alm-row-cant,
    #almTableBody tr.alm-row.alm-row-exceeds-stock .alm-row-cant { color:#991b1b !important; font-weight:700; }

    /* ── Presentación de las filas del inventario ──────────────────────────────
       Estaban escritas como style="" DENTRO de partials/table_rows.blade.php, repetidas
       idénticas en cada una de las 120 filas del lote. Como el valor no cambia de una fila
       a otra, vive aquí una vez y la fila solo lleva la clase.

       Las reglas de estado (.is-active, .alm-row-missing-cant, .alm-row-exceeds-stock) y
       las de la tarjeta móvil llevan !important, así que ganaban a los style="" inline y
       siguen ganando a estas clases: ni los estados ni el móvil cambian.
       OJO con esa afirmación: vale para las declaraciones que SÍ llevan !important. La que
       no lo llevaba (el color del texto de las filas marcadas, arriba) sí habría cambiado
       de dueño al quitar los inline — por eso se trató aparte.

       El JS de esta pantalla solo ESCRIBE .style.* sobre campos de formulario y el canvas
       de etiquetas — nunca sobre estas celdas—, así que no hay interacción con el cambio. */
    /* "Despachos" es un <a> dentro de un menú de <button>s: sin esto saldría
       subrayado y sin la fila alineada como los demás. La pill va pegada a la derecha. */
    #almAccionesMenu .alm-acc-despacho { display: flex; align-items: center; gap: 10px; padding: 11px 14px;
                                         color: #475569; background: transparent; border: none;
                                         border-bottom: 1px solid #f1f5f9; width: 100%; text-align: left;
                                         cursor: pointer; text-decoration: none; }
    #almAccionesMenu .alm-acc-despacho:hover { background: #f8fafc; }
    #almAccionesMenu .alm-acc-pill { margin-left: auto; font-size: 11px; font-weight: 800; padding: 2px 8px;
                                     border-radius: 999px; white-space: nowrap; }
    /* Foto dentro de "Detalles del producto": un CÍRCULO centrado (lo pidió el cliente). Sin
       foto, el mismo círculo con su ícono en gris.
       Pequeña a propósito: la ficha es para consultar datos, no para mirar la foto. Quien
       tiene almacen.productos la cambia tocándola: la cámara va SIEMPRE a la vista, en el
       CENTRO de la foto (como en las tarjetas del catálogo), sola —sin círculo detrás, lo pidió
       el cliente— con una sombra para que se lea sobre una foto clara, y gira mientras sube. Sin
       permiso, tocarla la abre en grande.
       flex: 0 0 auto: el cuerpo del modal es una columna flex con alto limitado y, sin esto,
       encogía la caja a lo alto y el círculo salía como un óvalo. */
    .alm-det-foto-caja { position: relative; width: 64px; height: 64px; flex: 0 0 auto; margin: 0 auto; }
    .alm-det-foto { display: block; width: 100%; height: 100%; border-radius: 50%; border: 1px solid #e2e8f0;
                    background: #f8fafc; object-fit: cover; cursor: zoom-in; box-sizing: border-box; }
    .alm-det-foto-sin { display: flex; align-items: center; justify-content: center; color: #cbd5e0; cursor: default; }
    .alm-det-foto-sin .material-icons { font-size: 24px; }
    .alm-det-foto-caja.editable .alm-det-foto { cursor: pointer; }
    /* pointer-events:none: el clic lo recibe la caja, que abre el selector de archivo. */
    .alm-det-foto-camara { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
                           color: #fff; display: flex; pointer-events: none;
                           filter: drop-shadow(0 1px 2px rgba(15, 23, 42, .85)); transition: transform .18s ease; }
    .alm-det-foto-camara .material-icons { font-size: 18px; }
    .alm-det-foto-caja.editable:hover .alm-det-foto-camara { transform: translate(-50%, -50%) scale(1.15); }
    .alm-det-foto-caja.subiendo .alm-det-foto-camara .material-icons { animation: alm-det-foto-gira 1s linear infinite; }
    @keyframes alm-det-foto-gira { to { transform: rotate(360deg); } }
    .alm-det-foto-caja.subiendo { pointer-events: none; }
    /* Miniatura del producto. Medida fija para que la columna no baile de ancho de una fila
       a otra, y el mismo recuadro cuando no hay foto (con su ícono en gris). */
    .alm-table td.alm-td-foto { padding: 6px 4px 6px 8px; width: 72px; }
    .alm-foto { width: 58px; height: 58px; border-radius: 9px; border: 1px solid #e2e8f0;
                background: #f8fafc; object-fit: cover; display: block; }
    img.alm-foto { cursor: zoom-in; }
    .alm-foto-sin { display: flex; align-items: center; justify-content: center; color: #cbd5e0; }
    .alm-foto-sin .material-icons { font-size: 26px; }
    /* Visor de la foto del producto: al tocar la miniatura (tabla) o la foto de la ficha sin
       permiso de cambiarla. Centrado sobre todo; se cierra tocando fuera, la X o Esc. */
    .alm-visor-foto { display: none; position: fixed; inset: 0; z-index: 100000; padding: 24px;
                      background: rgba(15, 23, 42, 0.8); align-items: center; justify-content: center;
                      cursor: zoom-out; }
    .alm-visor-foto.abierto { display: flex; }
    .alm-visor-foto img { max-width: min(92vw, 900px); max-height: 86vh; border-radius: 12px;
                          background: #fff; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
                          object-fit: contain; cursor: default; }
    .alm-visor-foto .alm-visor-x { position: absolute; top: 14px; right: 18px; color: #fff;
                                   font-size: 30px; cursor: pointer; }
    /* CÓDIGO dentro de la celda de Descripción: renglón propio ENCIMA del nombre, pequeño
       y monoespaciado — misma jerarquía que la cabecera del modal de movimientos
       (.alm-kp-hero-cod), para que el producto se lea igual en los dos sitios. */
    .alm-cod-mini { display:block; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                    font-size:10px; font-weight:700; letter-spacing:.9px; color:#64748b; line-height:1.2;
                    margin-bottom:2px; }
    .alm-table td.alm-td-nombre { font-weight:600; color:#1e293b; position:relative; }
    .alm-table td.alm-td-cat    { font-weight:600; color:#1e293b; }
    .alm-table td.alm-td-stock  { text-align:center; color:#0f172a; }
    .alm-table td.alm-td-cant   { text-align:center; white-space:nowrap; width:100px; }
    .alm-table td.alm-td-det    { text-align:center; white-space:nowrap; width:38px; padding:12px 6px; }
    .alm-table .alm-nombre-txt  { font-size:12px; }
    .alm-table .alm-equiv-linea { font-size:13px; font-weight:600; color:#1e293b;
                                  margin-top:2px; line-height:1.4; word-break:break-word; }
    .alm-table .alm-parte-list  { font-size:13px; color:#1e293b; font-weight:600;
                                  margin-top:2px; line-height:1.55; word-break:break-word; }
    .alm-table .alm-parte-sep   { color:#cbd5e0; }
    /* Aviso de stock en o bajo el mínimo. */
    .alm-table .alm-ico-minimo  { font-size:14px; color:#f59e0b; vertical-align:middle; }
    .alm-table .alm-ico-detalle { font-size:18px; }
    /* Stepper de cantidad. Los botones nacen deshabilitados; .is-active (arriba, con
       !important) los enciende cuando la fila entra en modo edición. */
    .alm-table .alm-cant-stepper { display:inline-flex; align-items:stretch;
                                   border:1px solid #cbd5e0; border-radius:6px;
                                   overflow:hidden; background:#f1f5f9; height:30px; }
    .alm-table .alm-cant-stepper .alm-row-cant { width:56px; border:none; background:transparent;
                                   text-align:center; font-size:13px; font-weight:700;
                                   color:#94a3b8; outline:none; padding:0; }
    .alm-table .alm-cant-flechas { display:flex; flex-direction:column;
                                   border-left:1px solid #cbd5e0; width:18px; }
    .alm-table .alm-cant-btn     { flex:1; border:none; background:#e2e8f0; color:#94a3b8;
                                   font-weight:800; font-size:11px; line-height:1;
                                   cursor:not-allowed; padding:0; }
    .alm-table .alm-cant-btn.alm-cant-btn-sube { border-bottom:1px solid #cbd5e0; }

    /* Burbuja de hover con el detalle del producto. `.tooltip-bubble` es clase global pero
       SIN regla base en estilos_globales.css (solo los activadores de hover), así que cada
       pantalla la describía en su style="" inline. Aquí se describe una vez y SOLO para
       esta tabla, para no alterar las otras pantallas que la usan con el suyo.
       Los activadores (.alm-row:hover, arriba, con !important) siguen mandando. */
    .alm-table .tooltip-bubble {
        pointer-events:none; opacity:0; visibility:hidden;
        position:absolute; bottom:100%; left:0; transform:translateY(5px);
        background:#1e293b; color:#fff; padding:8px 12px; border-radius:6px;
        /* Letra de 12 px: con 14 px y seis equipos la burbuja era un bloque enorme sobre la tabla. */
        font-size:12px; font-weight:500; line-height:1.5; white-space:normal;
        width:max-content; max-width:380px; word-wrap:break-word; text-align:left;
        box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);
        /* Solo el fundido: con `all` la burbuja se deslizaba desde su sitio anterior al colocarse. */
        transition:opacity .15s ease-in-out, visibility .15s ease-in-out; z-index:9001; margin-bottom:5px;
    }
    .alm-table .alm-tip-sep    { border-top:1px solid rgba(255,255,255,.2); margin:6px 0; }
    /* Cierre de la lista de equipos cuando hay mas de los que se muestran: es una nota, no
       un equipo mas, asi que va mas chica y apagada. */
    .alm-table .alm-tip-mas    { font-size:11px; font-weight:500; color:#cbd5e1; font-style:italic; }
    .alm-table .alm-tip-flecha { position:absolute; top:100%; left:30px; margin-left:-4px;
                                 border-width:4px; border-style:solid;
                                 border-color:#1e293b transparent transparent transparent; }
    /* Burbuja colocada DEBAJO de la fila (almTipShow): la flecha arriba, apuntando a la fila. */
    .alm-table .tooltip-bubble.alm-tip-abajo .alm-tip-flecha { top:auto; bottom:100%; border-color:transparent transparent #1e293b transparent; }
    /* Estados vacíos de la tabla: una sola fila, no se repiten por producto. */
    .alm-table .alm-vacio      { text-align:center; padding:40px 16px; color:#94a3b8; font-size:14px; }
    .alm-table .alm-vacio-alto { padding:48px 16px; }
    .alm-table .alm-vacio .material-icons { font-size:42px; color:#cbd5e0; display:block; margin:0 auto 8px; }
    .alm-table .alm-vacio-alto .material-icons { font-size:46px; margin:0 auto 10px; }
    .alm-table .alm-vacio-pista { display:inline-block; margin-top:6px; font-size:12.5px;
                                  color:#94a3b8; max-width:420px; }
    /* Fila recién modificada (almResaltarFila): destello amarillo que se desvanece. Con
       box-shadow inset —no background— para que se vea también sobre una fila seleccionada,
       cuyo fondo lleva !important. */
    #almTableBody tr.alm-row.alm-row-recien > td { animation: almRecien 2.4s ease-out 1; }
    @keyframes almRecien {
        0%, 35% { box-shadow: inset 0 0 0 999px rgba(250, 204, 21, 0.45); }
        100%    { box-shadow: inset 0 0 0 999px rgba(250, 204, 21, 0); }
    }
    /* Número y unidad de la celda Stock en una sola línea ("6 PAR"). */
    .alm-stock-num { display:block; white-space:nowrap; }
    /* Modal «¿De qué proyecto sale?»: cada proyecto es un botón a todo lo ancho con su saldo,
       unido por la misma línea punteada del panel "En otros almacenes". Tocar uno elige. */
    .alm-bolsa-prod { margin:0; font-size:13px; font-weight:600; color:#0f172a; line-height:1.35; text-wrap:balance; }
    .alm-bolsa-prod b { font-weight:800; }
    .alm-bolsa-lista { display:flex; flex-direction:column; gap:6px; max-height:52vh; overflow-y:auto; }
    .alm-bolsa-opcion { display:flex; align-items:center; gap:8px; width:100%; box-sizing:border-box; padding:11px 12px;
        border:1.5px solid #e2e8f0; border-radius:10px; background:#fff; cursor:pointer; font:inherit; text-align:left;
        transition:border-color .15s, background .15s; }
    .alm-bolsa-opcion:hover, .alm-bolsa-opcion:focus-visible { border-color:#0067b1; background:#eff6ff; outline:none; }
    .alm-bolsa-opcion .nom { flex:0 1 auto; min-width:0; font-size:13.5px; font-weight:700; color:#1e293b;
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .alm-bolsa-opcion .nom.comun { font-style:italic; font-weight:600; color:#475569; }
    .alm-bolsa-opcion .guia { flex:1 1 12px; min-width:12px; height:0; border-bottom:1.5px dotted #94a3b8; transform:translateY(3px); }
    .alm-bolsa-opcion .qty { font-size:14px; font-weight:800; color:#0f172a; white-space:nowrap; font-variant-numeric:tabular-nums; }
    .alm-bolsa-opcion .qty small { margin-left:3px; font-size:11px; font-weight:700; color:#64748b; }
    /* Nombre del almacén en modo GENERAL: no hay proyecto que elegir, así que el campo
       se comporta como uno de texto normal — sin lista y sin el caret que la anuncia. */
    #almNvNombreDropdown.alm-dd-sin-lista .dropdown-content,
    #almNvNombreDropdown.alm-dd-sin-lista .dropdown-trigger > i { display: none !important; }

    /* Unidad (UM) junto al número en la celda de Stock — reemplaza la columna "UND". */
    .alm-stock-um { margin-left:5px; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; }

    /* Contador "inventory_2 N" clickable — toggle del filtro "Solo seleccionados". */
    .alm-bulk-counter { cursor:pointer; user-select:none; transition:transform 0.12s; }
    .alm-bulk-counter:hover { transform:scale(1.04); }
    /* "Solo seleccionados" activo: en vez del anillo/glow que rodeaba TODO el
       contador (se veia feo), resaltamos solo el NUMERO en un circulo blanco (antes
       ámbar: con el verde de "Salida" y el azul de "Etiquetas" la barra tenía demasiados
       colores). */
    .alm-bulk-counter.is-filtering #almBulkCount {
        background:#fff; color:#0f172a; min-width:22px; height:22px; padding:0 5px;
        border-radius:999px; display:inline-flex; align-items:center; justify-content:center;
        font-weight:800; line-height:1; box-sizing:border-box;
    }
    /* Barra flotante: "Salida" (la principal) en el azul de la app y "Etiquetas" como
       secundaria, en el gris pizarra de los botones secundarios de la barra de Equipos. */
    #almBulkBar .alm-bulk-sec { background:#64748b; }
    #almBulkBar .alm-bulk-sec:hover { background:#475569; }

    /* ── Panel lateral (partial distribucion_stats): "¿dónde está este producto?" y la
       distribución por categoría comparten encabezado, lista y filas. Vivían como `style=""`
       repetidos en cada bloque del partial; al unificarlos aquí, ajustar el alto es un solo sitio.
       COMPACTO a pedido del cliente: encabezado 12px con 6px de aire, filas de 5px y
       separación de 12px entre secciones (antes 13px/8px/7px/16px). */
    .alm-panel-h4 { margin:0 0 7px 0; font-size:12px; text-transform:uppercase; color:#1e293b;
        border-bottom:1px solid #f1f5f9; padding-bottom:6px; font-weight:700;
        display:flex; align-items:center; gap:7px; }
    .alm-panel-h4 .material-icons { font-size:17px; }
    .alm-panel-h4.sep { margin-top:12px; }
    .alm-panel-list { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:2px; }
    /* overscroll-behavior: al llegar al final de ESTA lista, la rueda del raton NO
       sigue moviendo la pagina de atras. Antes, al terminar las categorias, el modulo
       entero se iba hacia abajo solo (pedido del cliente, 16-09-2026). */
    .alm-panel-list.scroll { max-height:52vh; overflow-y:auto; overscroll-behavior:contain; }
    /* La de "Distribución de Inventario" más alta: se ven más categorías sin desplazar (pedido
       del cliente, 21-09-2026). La de "En otros almacenes" se queda con la de arriba. */
    .alm-panel-list.scroll.alm-cat-list { max-height:64vh; }
    /* Aviso corto bajo un encabezado sin lista ("Sin datos…", "No tienes otros almacenes…"). */
    .alm-panel-nota { color:#64748b; font-size:12px; margin:6px 0 0 0; font-style:italic; line-height:1.4; }
    /* El wrapper solo se ve con contenido: el servidor lo llena al abrir y cada recarga lo
       reemplaza; queda vacío si la petición del panel falla (almPanelOtros). */
    #almDistWrapper:has(#almDistribucionContainer:empty) { display: none !important; }
    /* Spinner del panel lateral MIENTRAS se piden las ubicaciones de un producto. Es local
       al contenedor a proposito: el preloader global tapa la pantalla entera y aqui solo
       cambia una tarjeta, asi que la tabla se sigue viendo y se puede tocar otra fila.
       .spinner-mini sale del CSS global, no se redefine aqui. */
    .alm-panel-cargando { display:flex; flex-direction:column; align-items:center; justify-content:center;
        gap:8px; padding:18px 12px; }
    .alm-panel-fallo { color:#64748b; font-size:11.5px; font-weight:600; text-align:center; line-height:1.35; }
    .alm-panel-fallo .material-icons { font-size:26px; color:#94a3b8; }
    /* Distribución por categoría: encabezado visual del fin de semana (más prominente).
       Sobreescribe .alm-panel-h4 cuando está dentro del panel de distribución de categorías. */
    .alm-distribucion-cats .alm-panel-h4 { font-size:12px; color:#64748b; border-bottom:2px solid #f1f5f9;
        padding-bottom:7px; gap:7px; }
    .alm-distribucion-cats .alm-panel-h4 .material-icons { font-size:16px; }
    /* Lista de categorías: separador dashed entre ítems (como en la versión del fin de semana),
       sin guía punteada interna — el badge de qty ya ancla el número a la derecha. */
    .alm-cat-row { padding:4px 6px; border-radius:6px; border:none; border-bottom:1px dashed #f1f5f9;
        cursor:default; transition:background .15s; }
    .alm-cat-row:last-child { border-bottom:none; }
    .alm-cat-row .alm-panel-row { padding:0 0 3px 0; border:none; background:transparent !important; }
    .alm-cat-row .alm-panel-row .nom { text-transform:uppercase; flex:1; min-width:0; font-size:11px; }
    .alm-cat-row .alm-panel-row .qty { font-size:11px; padding:1px 6px; }
    .alm-cat-row.clicable { cursor:pointer; }
    .alm-cat-row.clicable:hover { background:#f8fafc; }
    /* Cámara del panel: al final del encabezado, discreta hasta que se pasa por encima. */
    .alm-cat-cam { margin-left:auto; border:none; background:transparent; cursor:pointer; color:#94a3b8;
        display:flex; align-items:center; padding:2px; border-radius:5px; transition:background .15s, color .15s; }
    .alm-cat-cam:hover { background:#f1f5f9; color:#3b82f6; }
    .alm-cat-cam .material-icons { font-size:16px; }
    .alm-cat-bar { margin:0; height:4px; background:#e2e8f0; border-radius:2px; overflow:hidden; }
    /* min-width: una categoria con 1 producto de 1437 da una barra de 0,21 px. Ademas de no
       verse, rompia la camara: html2canvas crea un patron del tamano del elemento y
       reventaba con "createPattern ... width or height of 0". */
    .alm-cat-bar > div { height:100%; min-width:3px; background:linear-gradient(90deg,#3b82f6 0%,#2563eb 100%); }
    .alm-panel-row { padding:5px 8px; border-radius:6px; display:flex; justify-content:space-between;
        align-items:center; gap:8px; border:1px solid transparent; }
    .alm-panel-row .nom { flex:0 1 auto; min-width:0; color:#1e293b; font-size:12.5px; font-weight:600;
        line-height:1.25; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    /* Bolsa común: es saldo real, pero no es un proyecto con nombre propio. */
    .alm-panel-row .nom.comun { font-style:italic; color:#475569; }
    .alm-panel-row .qty { font-weight:700; font-size:12.5px; padding:2px 8px; border-radius:4px;
        white-space:nowrap; background:#f1f5f9; color:#1e293b; }
    .alm-panel-row .qty.proy { background:#e1effa; color:#0067b1; }
    .alm-panel-row .qty.bajo { background:#fee2e2; color:#b91c1c; }
    /* Línea punteada que une cada almacén o frente con su cantidad: con el nombre a la
       izquierda y la cifra a la derecha, el ojo se perdía en el hueco (pedido del cliente). */
    .alm-panel-row .guia { flex:1 1 12px; min-width:12px; align-self:center; height:0;
        border-bottom:1.5px dotted #94a3b8; transform:translateY(2px); }
    /* Solo las filas de otros almacenes llevan a algún lado (abren ese almacén). */
    .alm-panel-row.clicable { cursor:pointer; transition:background .15s, border-color .15s; }
    .alm-panel-row.clicable:hover { background:#f8fafc; border-color:#e2e8f0; }
    /* Reparto por proyecto de un almacén AJENO, colgando de su fila. Va indentado y en
       tipografía menor para que se lea como detalle de la línea de arriba y no como otro
       almacén más. No es clicable: el clic útil es el de la fila padre, que abre ese
       almacén — aquí solo se informa de dónde está el saldo antes de pedir el traspaso. */
    /* La fila que lleva desglose deja de ser una sola línea: el <ul> hijo se pasa al
       renglón siguiente con flex-basis:100%. Así los dos <span> conservan su sitio (y
       siguen siendo hijos directos del <li>, de los que cuelgan las reglas de teléfono). */
    .alm-panel-row.con-sub { flex-wrap:wrap; }
    .alm-panel-sub { flex-basis:100%; list-style:none; margin:3px 0 1px 0; padding:0 0 0 10px;
        display:flex; flex-direction:column; gap:1px; border-left:2px solid #cbd5e1; }
    .alm-panel-sub li { display:flex; justify-content:space-between; align-items:center; gap:8px; padding:2px 0; }
    .alm-panel-sub .nom { flex:0 1 auto; min-width:0; color:#334155; font-size:11.5px; font-weight:600;
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .alm-panel-sub .nom.comun { font-style:italic; color:#475569; }
    .alm-panel-sub .qty { font-weight:700; font-size:11.5px; color:#1e293b; white-space:nowrap; }

    .alm-panel-total { display:flex; justify-content:space-between; align-items:center; gap:8px;
        margin-top:2px; padding:6px 8px 0; border-top:1px solid #e2e8f0; }
    .alm-panel-total span { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; color:#334155; }
    .alm-panel-total strong { font-weight:800; font-size:13px; color:#0f172a; white-space:nowrap; }

    /* ── Consolidado de Inventario (tarjeta del sidebar) ──────────────────────────
       Vive en clases y no en `style=""` para poder ajustarlo desde los @media sin
       repetir cada valor. Está COMPRIMIDO verticalmente respecto de la versión
       anterior (pedido del cliente): padding 15→11px, el número grande 34→28px y —lo
       que más altura ahorra— los dos badges dejaron de apilar icono/número/etiqueta en
       tres renglones: ahora el icono va AL LADO del número y solo la etiqueta baja a su
       propia línea. Es el mismo patrón del panel "Resumen de la bandeja" de Recepción
       (.tr-stats-sub), así que los dos módulos se ven como la misma familia. */
    .alm-cons-card { position:relative; overflow:hidden; border-radius:12px; padding:11px 13px; color:#fff;
        background:linear-gradient(135deg,#1a365d 0%,#2c5282 100%); box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); }
    .alm-cons-bgicon { position:absolute; right:-15px; bottom:-15px; font-size:80px; opacity:0.1; transform:rotate(-15deg); }
    .alm-cons-inner { position:relative; z-index:2; }
    .alm-consolidado-title { display:flex; align-items:center; gap:6px; font-size:12px; font-weight:700;
        text-transform:uppercase; letter-spacing:1px; opacity:0.8; margin-bottom:6px; }
    .alm-consolidado-title .material-icons { font-size:14px; }
    .alm-cons-row { display:flex; align-items:stretch; gap:6px; }
    /* Total: clic = quitar filtros. line-height:1 en el número para que no arrastre el
       interlineado de la fuente (era la mitad del alto sobrante de la tarjeta). */
    .alm-cons-total { display:flex; flex-direction:column; align-items:center; justify-content:center;
        min-width:62px; padding:5px 7px; border-radius:10px; background:rgba(255,255,255,0.15); cursor:pointer; }
    .alm-cons-total .num { font-size:28px; font-weight:800; line-height:1; }
    .alm-cons-total .lbl { font-size:10.5px; font-weight:700; text-transform:uppercase; opacity:0.8; margin-top:3px; }
    .alm-cons-badges { flex:1; display:grid; grid-template-columns:repeat(2,1fr); gap:6px; }
    /* Icono + número en la MISMA línea; la etiqueta cae debajo (flex:1 1 100%). */
    .alm-cons-badge { display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:2px 5px;
        padding:6px 3px; border-radius:8px; cursor:pointer; text-align:center;
        transition:background .15s, border-color .15s, box-shadow .15s; }
    .alm-cons-badge .material-icons { font-size:17px; }
    .alm-cons-badge strong { font-size:16px; font-weight:800; color:#fff; line-height:1; }
    .alm-cons-badge span { flex:1 1 100%; font-size:10.5px; font-weight:700; text-transform:uppercase; opacity:0.9; line-height:1.1; }
    .alm-cons-saldo { background:rgba(34,197,94,0.15); border:1px solid rgba(34,197,94,0.25); }
    .alm-cons-saldo .material-icons { color:#22c55e; }
    .alm-cons-bajo  { background:rgba(245,158,11,0.18); border:1px solid rgba(245,158,11,0.3); }
    .alm-cons-bajo .material-icons { color:#f59e0b; }
    /* Filtro aplicado → el badge se marca (anillo blanco + fondo más saturado). Así el
       usuario VE cuál filtro está filtrando la tabla y puede apagarlo con otro clic. */
    #almBadgeConSaldo.is-on { background:rgba(34,197,94,0.45) !important; border-color:rgba(255,255,255,0.6) !important; box-shadow:0 0 0 2px rgba(255,255,255,0.5), 0 0 10px rgba(34,197,94,0.55); }
    #almBadgeBajo.is-on     { background:rgba(245,158,11,0.45) !important; border-color:rgba(255,255,255,0.6) !important; box-shadow:0 0 0 2px rgba(255,255,255,0.5), 0 0 10px rgba(245,158,11,0.55); }

    .alm-btn {
        display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px;
        border-radius: 7px; border: 1px solid #e2e8f0; background: #fff; cursor: pointer; margin: 0 1px;
        transition: transform 0.12s, background 0.12s;
    }
    .alm-btn:hover { transform: scale(1.06); }
    /* (se usan en la lista "Gestionar almacenes" — editar / eliminar almacén — y en la
       papelera de productos, que reusa .alm-btn-del para el borrado permanente) */
    .alm-btn-edit { color: #0891b2; border-color: #cffafe; } .alm-btn-edit:hover { background: #0891b2; color: #fff; }
    .alm-btn-del  { color: #ef4444; border-color: #fecaca; } .alm-btn-del:hover  { background: #ef4444; color: #fff; }
    /* Restaurar (papelera de productos) — mismo tamaño/forma que .alm-btn-edit y
       .alm-btn-del, en azul corporativo. El botón rojo de la papelera REUSA
       .alm-btn-del: es la misma acción destructiva, no hace falta otra clase. */
    .alm-btn-restore { color: #0067b1; border-color: #bfdbfe; } .alm-btn-restore:hover { background: #0067b1; color: #fff; }
    /* Botón "ojo" de detalles por fila: mismo look que el de /admin/equipos */
    .alm-table tbody td .btn-details-mini { margin: 0 auto; }
    /* Acciones dentro del modal "Detalles del producto" */
    /* font-family:inherit — los <button> NO heredan la fuente del body por defecto:
       sin esto el texto salía en la fuente del navegador (Arial) y desentonaba con
       el resto de la app (Nunito). Sin mayúsculas forzadas: se lee tal cual se escribe. */
    .alm-det-act { display:flex; align-items:center; gap:10px; width:100%; text-align:left; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:8px 12px; font-family:inherit; font-size:13px; font-weight:500; color:#0f172a; cursor:default; transition:background .15s, border-color .15s; }
    .alm-det-act:hover { background:#f8fafc; border-color:#cbd5e0; }
    .dropdown-item-custom:hover { background: #f8fafc !important; }
    .alm-det-ic { width:30px; height:30px; border-radius:8px; display:flex; align-items:center; justify-content:center; flex:0 0 auto; }

    /* Modales */
    /* z-index 10000: por ENCIMA de la barra flotante de seleccion
       (.selection-floating-bar = 9999) para que "Limpiar / Salida" no se
       transparente sobre el modal (ej: Vista previa de la Nota de Entrega).
       Sigue por debajo de los toasts globales (1000002). */
    .alm-modal-overlay {
        display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.45);
        z-index: 10000; align-items: center; justify-content: center; padding: 16px;
    }
    .alm-modal-overlay.open { display: flex; }
    .alm-modal {
        background: #fff; border-radius: 14px; width: 100%; max-width: 440px; box-shadow: 0 20px 50px rgba(0,0,0,0.25);
        overflow: hidden; animation: almIn 0.16s ease-out; display: flex; flex-direction: column; max-height: 90vh;
    }
    .alm-modal-wide { max-width: 980px; }
    .alm-modal .alm-modal-body { overflow-y: auto; min-height: 0; }
    /* Formato de la Nota de Entrega: las opciones van UNA AL LADO DE LA OTRA — son dos
       palabras cortas y en columna ocupaban dos renglones para nada.
       flex + `flex:1 1 0` en cada una (en vez de fijar 2 columnas) para que siga
       funcionando si algún día se agrega un tercer formato: el blade los recorre desde
       Almacen::FORMATOS_NOTA y aquí se reparten el ancho solos. */
    #almNvFormatoOpts { display:flex; gap:4px; border:1px solid #cbd5e0; border-radius:10px;
        background:#fbfcfd; padding:3px; }
    /* Dos opciones de una palabra no necesitan la altura de un renglón de lista: con el
       padding del componente (8px) la caja medía 52px, más alta que los propios campos de
       texto del modal. Con 5px queda en 40px, a la par del resto del formulario. */
    #almNvFormatoOpts .multiselect-item { flex:1 1 0; min-width:0; cursor:pointer; padding:5px 10px; }
    /* Firmantes de la nota: un bloque por firmante, apilados en el mismo orden en que salen
       impresos en el PDF. Apilados y no en columnas porque el modal es angosto a propósito:
       en columnas el nombre no cabía y los bloques se partían a mitad. Dentro de cada uno,
       Nombre ocupa toda la línea y Cargo + Cédula comparten la de abajo. */
    .alm-firmantes { display:flex; flex-direction:column; gap:8px; }
    /* Sin esto, el grupo de SOPORTADO seguiría VISIBLE al ocultarlo: display:flex de la regla
       de arriba le gana al display:none que el navegador aplica por el atributo [hidden]. */
    .alm-firmantes[hidden] { display:none; }
    .alm-firm-bloque { display:flex; flex-direction:column; gap:5px;
                       border:1px solid #e2e8f0; border-radius:10px; padding:7px 10px; background:#fbfcfd; }
    /* DENSIDAD DEL MODAL DE ALMACÉN. Es el formulario más largo del módulo (6 grupos más
       hasta tres bloques de firmantes) y con el alto por defecto pasaba de 690px: en un
       portátil quedaba con scroll interno y los botones fuera de vista. Se aprieta SOLO
       aquí —no en los otros modales del almacén, que son cortos— bajando el aire de los
       campos y el hueco entre grupos. Los checkboxes se excluyen: su tamaño lo fija
       .multiselect-item input[type=checkbox] y el padding no les aporta nada. */
    #almAlmacenModal .alm-modal-body { gap:10px; }
    #almAlmacenModal .alm-modal-body input:not([type="checkbox"]) { padding:7px 10px; }
    .alm-firm-rol { font-size:11px; font-weight:700; letter-spacing:.4px; color:#64748b; }
    .alm-firm-fila { display:flex; gap:6px; }
    .alm-firm-fila > * { flex:1 1 0; min-width:0; }
    .alm-firm-bloque input { width:100%; box-sizing:border-box; }
    /* Logística del almacén (modal "Editar almacén"): choferes y vehículos en el mismo bloque
       que los firmantes, un renglón editable por persona o vehículo con su × al final. */
    .alm-log-cab { display:flex; align-items:center; justify-content:space-between; }
    .alm-log-filas { display:flex; flex-direction:column; gap:5px; }
    .alm-log-fila { align-items:center; }
    /* La placa o la cédula caben en 100px: el resto es para la descripción, que en el teléfono
       se cortaba ("CAMIONETA TOYOTA H…") repartiendo el ancho por proporción. */
    .alm-log-fila > input[data-campo="documento"] { flex:0 0 100px; }
    @media (max-width: 480px) {
        #almAlmacenModal .alm-log-fila input { font-size:13px; padding-left:8px; padding-right:8px; }
        .alm-log-fila > input[data-campo="documento"] { flex-basis:90px; }
    }
    .alm-log-fila > .alm-det-quitar { flex:0 0 auto; }
    .alm-log-vacio { font-size:12px; color:#64748b; font-style:italic; }
    /* Transporte de la salida: en la lista de sugerencias, el nombre a la izquierda y la
       cédula o placa a la derecha, agrupados por de dónde salen (lista del almacén / flota). */
    .alm-log-grupo { padding:6px 12px 3px; font-size:10.5px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.4px; }
    .alm-suggest-inline .alm-log-item { justify-content:space-between; padding:8px 12px; }
    .alm-log-nom { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:13px; color:#0f172a; }
    .alm-log-doc { flex:0 0 auto; font-size:12px; font-weight:700; color:#475569; font-variant-numeric:tabular-nums; }
    /* Vehículos: placa, serial de chasis y tipo, sin marca ni modelo. Si no caben en una línea
       (teléfono, campo angosto) el tipo baja al renglón siguiente, alineado a la derecha. */
    .alm-suggest-inline .alm-log-item.veh { justify-content:flex-start; flex-wrap:wrap; column-gap:12px; row-gap:1px; }
    .alm-log-item.veh .alm-log-doc { min-width:66px; font-size:13px; color:#0f172a; }
    .alm-log-item.veh .alm-log-doc.sin-placa { font-size:10.5px; color:#94a3b8; letter-spacing:.3px; }
    .alm-log-serial { flex:0 0 auto; font-size:11px; font-weight:600; color:#475569; font-variant-numeric:tabular-nums; }
    .alm-log-tipo { margin-left:auto; min-width:0; max-width:100%; font-size:12px; font-weight:700; color:#0067b1;
        text-align:right; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    /* Se guardan en mayúsculas (LogisticaAlmacenService): se ven así mientras se escriben. El
       ejemplo del campo no: "EJ: CAMIONETA TOYOTA HILUX" parecía un valor ya escrito. */
    #almSalidaVehiculo, #almSalidaPlaca, #almSalidaChofer, .alm-log-fila input { text-transform:uppercase; }
    #almSalidaVehiculo::placeholder, #almSalidaPlaca::placeholder, #almSalidaChofer::placeholder, .alm-log-fila input::placeholder { text-transform:none; }
    /* Renglón de ayuda bajo un campo del modal de almacén. Era el mismo style="" repetido
       campo por campo; como clase, cambiarlo una vez los cambia todos. */
    .alm-hint { font-size:11.5px; color:#94a3b8; margin-top:5px; }
    /* Inputs del modal "Nuevo / Editar producto": todo se guarda en mayúsculas,
       se ven en mayúsculas mientras se escribe para coincidir con lo que se guarda.
       El placeholder NO se transforma. CODIGO queda fuera (solo dígitos). */
    #almProdNombre, #almProdUm, #almProdCategoria, #almDetUbicacion { text-transform: uppercase; }
    /* Multiselect de frentes dentro del modal de almacén: FLOTA por encima. Antes se
       forzaba a position:static para que el overflow del modal no lo recortara, pero eso lo
       metía en el flujo: al abrirlo EMPUJABA el contenido y el modal se estiraba de golpe
       (la misma lección que está anotada aquí abajo para el modal de etiquetas).
       position:fixed + anclado por JS (almFrentesAnclar) lo saca del scroll de
       .alm-modal-body sin que nada lo recorte — el MISMO patrón que ya usan los suggest de
       UM y categoría de esta pantalla (.alm-suggest-float). El z-index va por encima del
       modal; la sombra se hereda de la regla global (flotando sí hace falta). */
    #almAlmacenModal .multiselect-content { position: fixed; margin-top: 0; z-index: 10001; }
    #almAlmacenModal .custom-multiselect.active .multiselect-content { animation: slideDown 0.18s ease-out; }
    /* Desplegable "Formato" del modal de etiquetas: FLOTA por encima del modal (el
       position:absolute que ya trae .dropdown-content de serie). Antes se forzaba a
       position:static para que el overflow:hidden del modal no lo recortara, pero eso lo
       metía en el flujo: al abrirlo EMPUJABA el contenido y el modal crecía de golpe.
       Para que flote sin recortarse hay que soltar el overflow de sus dos contenedores.
       Es seguro en ESTE modal —y solo en este— porque su contenido es corto y la única
       parte que puede crecer (#almEtqLista, la lista de productos) lleva su propio
       max-height con scroll, así que no se pierde nada al quitar el del cuerpo. */
    #almEtiquetasModal .alm-modal { overflow: visible; }
    #almEtiquetasModal .alm-modal .alm-modal-body { overflow: visible; }
    /* Sin el overflow:hidden que recorta, el encabezado oscuro y el pie gris taparían las
       esquinas redondeadas del modal: se redondean ellos mismos. */
    #almEtiquetasModal .alm-modal-head { border-radius: 14px 14px 0 0; }
    #almEtiquetasModal .alm-modal-foot { border-radius: 0 0 14px 14px; }
    #almEtiquetasModal .dropdown-content { z-index: 60; max-height: 190px; overflow-y: auto; }
    #almEtiquetasModal .custom-dropdown.active .dropdown-content { animation: slideDown 0.18s ease-out; }
    /* El "Formato" muestra su valor en el placeholder del input readonly: lo pintamos como
       texto sólido (no gris) para que "Rollo 50 × 30 mm" se vea como la opción elegida por defecto. */
    #almEtiquetasModal .dropdown-trigger input::placeholder { font-style: normal; color: #0f172a; opacity: 1; }
    /* Cantidad y tamaño de la etiqueta más bajos que el campo normal del modal (38 px): es
       una fila corta y a 38 px el número se veía como un bloque. */
    #almEtiquetasModal input.alm-nota-input { height: 32px; }
    /* Lápiz que muestra el tamaño de la etiqueta (almEtqVerFormato): solo el ícono, sin
       recuadro; en azul al pasar el mouse y mientras se ve el tamaño. */
    .alm-etq-lapiz { flex: 0 0 auto; height: 32px; padding: 0 2px; border: none; border-radius: 6px; background: none; color: #64748b; cursor: pointer; display: flex; align-items: center; justify-content: center; }
    .alm-etq-lapiz:hover, .alm-etq-lapiz.activo { color: var(--maquinaria-blue, #0067b1); }
    .alm-etq-lapiz:focus-visible { outline: 2px solid var(--maquinaria-blue, #0067b1); outline-offset: 1px; }
    .alm-etq-lapiz .material-icons { font-size: 19px; }
    /* ── Modal "Movimientos del producto" ──────────────────────────────────
       Ficha del producto (cabecera), barra de filtros y tabla comparten el mismo
       lenguaje: tarjeta blanca, borde #e2e8f0 y radio 12px, para que el modal se
       lea como tres bloques y no como campos sueltos. */
    /* Cabecera: ícono, CÓDIGO en pequeño SOBRE el nombre (misma jerarquía que la
       tabla del inventario) y el saldo del almacén actual destacado a la derecha. */
    .alm-kp-hero { display: flex; align-items: center; gap: 12px; padding: 11px 14px; background: #fff;
                   border: 1px solid #e2e8f0; border-radius: 12px; }
    .alm-kp-hero-ic { flex: 0 0 auto; width: 40px; height: 40px; border-radius: 10px; background: #eff6ff;
                      color: var(--maquinaria-blue, #0067b1); display: flex; align-items: center; justify-content: center; }
    .alm-kp-hero-ic .material-icons { font-size: 22px; }
    .alm-kp-hero-txt { flex: 1 1 auto; min-width: 0; }
    .alm-kp-hero-cod { display: block; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                       font-size: 10px; font-weight: 700; letter-spacing: .9px; color: #64748b; line-height: 1.2; }
    .alm-kp-hero-nom { display: block; margin-top: 2px; font-size: 13.5px; font-weight: 700; color: #0f172a;
                       line-height: 1.3; overflow-wrap: anywhere; }
    .alm-kp-hero-stock { flex: 0 0 auto; text-align: right; padding-left: 14px; border-left: 1px solid #e2e8f0; }
    .alm-kp-hero-stock .alm-kp-rot { display: block; margin-bottom: 1px; }
    .alm-kp-hero-num { display: block; font-size: 18px; font-weight: 800; color: #0f172a; line-height: 1.2; white-space: nowrap; }
    /* Saldo en cero: rojo, igual que una cantidad que resta en el kardex. */
    .alm-kp-hero-num.cero { color: #dc2626; }
    .alm-kp-hero-um { font-size: 10px; font-weight: 700; color: #64748b; margin-left: 2px; }
    /* Rótulo común de los bloques (STOCK ACTUAL, TIPO, RANGO DE FECHAS). */
    .alm-kp-rot { font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .7px;
                  color: #94a3b8; white-space: nowrap; }
    /* Filtros: Tipo y Fechas SIEMPRE en la misma fila (nowrap) — el grupo de fechas es
       el que cede ancho (flex:1 + min-width:0 hasta las cajas). El rótulo va ARRIBA de
       cada control, no a su izquierda: gana ancho útil y alinea los dos grupos. */
    .alm-kp-filtros { display: flex; align-items: flex-end; gap: 12px; flex-wrap: nowrap;
                      background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 9px 12px; }
    .alm-kp-filtros .alm-kp-campo { display: flex; flex-direction: column; gap: 3px; }
    .alm-kp-filtros .alm-kp-grupo-tipo { flex: 0 0 auto; }
    .alm-kp-filtros .alm-kp-grupo-fechas { flex: 1 1 auto; min-width: 0; }
    .alm-kp-filtros .alm-kp-rango { display: flex; align-items: center; gap: 5px; flex: 1 1 auto; min-width: 0; }
    .alm-kp-filtros .alm-kp-fecha-box { flex: 1 1 0; min-width: 0; display: flex; align-items: center; gap: 5px;
                                        height: 32px; padding: 0 8px; background: #fff; border: 1px solid #e2e8f0;
                                        border-radius: 8px; cursor: pointer; transition: border-color .15s, box-shadow .15s; }
    .alm-kp-filtros .alm-kp-fecha-box:hover { border-color: #cbd5e1; }
    .alm-kp-filtros .alm-kp-fecha-box:focus-within { border-color: var(--maquinaria-blue, #0067b1);
                                                     box-shadow: 0 0 0 3px rgba(0,103,177,.10); }
    .alm-kp-filtros .alm-kp-fecha-box > .material-icons { flex: 0 0 auto; font-size: 15px; color: #94a3b8; }
    .alm-kp-filtros .alm-kp-fecha-box input { flex: 1 1 auto; width: auto; min-width: 0; height: 30px; padding: 0;
                                              border: none; background: transparent; font: inherit; font-size: 12px;
                                              color: #334155; outline: none; cursor: pointer; }
    /* Fecha sin elegir: el texto nativo (dd/mm/aaaa) en gris claro, para que no compita
       con un rango realmente aplicado. La clase la pone almKpCargar. NO se llama
       ".alm-kp-vacia": a una letra de .alm-kp-vacio, que es otra cosa (el guion de una
       celda sin dato) y se confundían al leer. */
    .alm-kp-filtros .alm-kp-fecha-box input.alm-kp-sinfecha { color: #b0bac6; }
    /* El ícono nativo del selector sobra: ya hay uno propio a la izquierda y la caja
       entera abre el calendario (onclick → showPicker). */
    .alm-kp-filtros .alm-kp-fecha-box input::-webkit-calendar-picker-indicator { opacity: 0; width: 0; padding: 0; margin: 0; }
    .alm-kp-filtros .alm-kp-flecha { flex: 0 0 auto; color: #94a3b8; font-size: 16px; }
    .alm-kp-select { height: 32px; padding: 0 8px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff;
                     font: inherit; font-size: 12px; color: #334155; cursor: pointer; transition: border-color .15s, box-shadow .15s; }
    .alm-kp-select:hover { border-color: #cbd5e1; }
    .alm-kp-select:focus { outline: none; border-color: var(--maquinaria-blue, #0067b1); box-shadow: 0 0 0 3px rgba(0,103,177,.10); }
    /* "Limpiar": solo aparece con algún filtro puesto (hidden lo gobierna almKpCargar). */
    .alm-kp-limpiar { flex: 0 0 auto; display: inline-flex; align-items: center; gap: 3px; height: 32px; padding: 0 10px;
                      border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; font: inherit; font-size: 11.5px;
                      font-weight: 700; color: #64748b; cursor: pointer; }
    .alm-kp-limpiar:hover { background: #fef2f2; border-color: #fecaca; color: #dc2626; }
    .alm-kp-limpiar .material-icons { font-size: 14px; }
    /* display:inline-flex le gana al display:none implícito de [hidden]; sin esta regla
       el botón se vería SIEMPRE, filtros puestos o no. */
    .alm-kp-limpiar[hidden] { display: none; }
    /* Tabla: el contenedor (radio 12px, como la cabecera y los filtros) con el thead
       pizarra sticky, igual que el de la tabla del inventario (.alm-table) y el de Equipos. */
    .alm-kp-tabla-wrap { overflow: auto; max-height: 48vh; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; }
    .alm-kp-tabla-wrap thead th { position: sticky; top: 0; z-index: 1; background: #1e293b; color: #fff;
                                  padding: 8px 10px; font-size: 10px; font-weight: 800; text-transform: uppercase;
                                  letter-spacing: .8px; }
    /* Estados de una sola celda (cargando / error). Van con el id porque #almKpBody td
       —más específico que la clase sola— les imponía el padding de una fila normal. */
    #almKpBody td.alm-kp-estado { text-align: center; padding: 26px 14px; color: #94a3b8; font-size: 12px; }
    #almKpBody td.alm-kp-estado.error { color: #dc2626; }
    /* Estado "sin movimientos" (kardex_rows_mini). Nombre propio, NO .alm-kp-vacio-*: esa
       otra clase es el guion de una celda sin dato y son dos cosas distintas. */
    .alm-kp-sinmov { display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 26px 14px; }
    .alm-kp-sinmov-ic { width: 46px; height: 46px; border-radius: 50%; background: #f1f5f9; color: #cbd5e0;
                        display: flex; align-items: center; justify-content: center; margin-bottom: 6px; }
    .alm-kp-sinmov-ic .material-icons { font-size: 24px; }
    .alm-kp-sinmov-tit { font-size: 12.5px; font-weight: 700; color: #64748b; }
    .alm-kp-sinmov-sub { font-size: 11px; color: #94a3b8; }
    /* Filas (partials/kardex_rows_mini): una línea suave entre movimientos; Destino a la
       izquierda, con lo que lo explica debajo en gris, y Documento en su propia columna. */
    #almKpBody td { padding: 7px 8px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    #almKpBody tr:last-child td { border-bottom: none; }
    #almKpBody tr.alm-kp-fila:hover td { background: #f8fafc; }
    .alm-kp-tipo { text-align: center; white-space: nowrap; font-weight: 700; font-size: 10.5px; }
    .alm-kp-tipo i { font-size: 12px; vertical-align: -2px; margin-right: 3px; }
    .alm-kp-cant { text-align: center; white-space: nowrap; font-weight: 800; font-size: 12.5px; }
    .alm-kp-um { color: #64748b; font-weight: 600; font-size: 9.5px; }
    .alm-kp-stock { text-align: center; white-space: nowrap; font-weight: 700; font-size: 12.5px; }
    /* overflow-wrap:anywhere — Destino es la única columna con texto libre (frente, proveedor,
       notas): nada la ensancha más allá de su porcentaje ni la saca del modal. */
    .alm-kp-destino { overflow-wrap: anywhere; }
    .alm-kp-nombre { font-size: 11.5px; font-weight: 700; color: #0f172a; line-height: 1.3; }
    .alm-kp-sub { font-size: 10.5px; color: #64748b; line-height: 1.35; margin-top: 1px; }
    .alm-kp-notas { display: flex; align-items: center; gap: 3px; color: #94a3b8; }
    .alm-kp-notas i { font-size: 12px; }
    .alm-kp-notas span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .alm-kp-doc { text-align: center; white-space: nowrap; }
    .alm-kp-nota, .alm-kp-ref { display: inline-block; font-size: 10.5px; font-weight: 700; font-family: monospace; padding: 2px 7px; border-radius: 6px; line-height: 1.4; }
    .alm-kp-nota { color: #0067b1; background: #eff6ff; text-decoration: none; }
    .alm-kp-nota:hover { background: #dbeafe; }
    .alm-kp-ref { color: #334155; background: #f1f5f9; }
    .alm-kp-nota + .alm-kp-ref { margin-top: 3px; }
    .alm-kp-vacio { color: #cbd5e0; }
    /* Sin @media propio a propósito: este modal (#almKardexProductoModal) SOLO se abre en
       escritorio — su botón .alm-det-act-kardex está oculto a ≤768px. La regla que había
       aquí (@media ≤560px → flex-wrap:wrap) no se podía evaluar nunca con el modal abierto,
       porque 560 < 768. Si algún día el kardex se abre en teléfono, va aquí. */
    .alm-admin-list { display: flex; flex-direction: column; gap: 6px; }
    .alm-admin-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 8px; }
    .alm-admin-row:hover { background: #f8fafc; }
    /* La papelera reusa .alm-admin-row, pero su modal tiene el cuerpo gris (#f8fafc):
       ahí las filas van en blanco para despegarse del fondo, e invierten el hover.
       Con id + clase le ganan en especificidad a las dos reglas de arriba. */
    #almPapeleraLista .alm-admin-row { background: #fff; }
    #almPapeleraLista .alm-admin-row:hover { background: #f1f5f9; }
    @keyframes almIn { from { transform: translateY(8px); opacity: 0; } to { transform: none; opacity: 1; } }
    @keyframes slideDown { from { transform: translateY(-8px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    /* El filtro de almacén (en el título) NO se resalta en azul cuando está activo:
       sobrescribimos el estilo global .filter-active sólo para ese dropdown. */
    #almSelAlmacenDropdown .dropdown-trigger.filter-active {
        background: #f8fafc !important;
        border-color: #cbd5e0 !important;
    }
    /* Todos los modales del módulo, con el encabezado de los de Equipos y Recepción: barra
       pizarra #1e293b, título blanco con su ícono azul, centrado, y la X fija en la esquina. */
    .alm-modal-head { padding: 14px 48px; background: #1e293b; display: flex; align-items: center; justify-content: center; position: relative; }
    .alm-modal-head h3 { margin: 0; font-size: 15px; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px; text-align: center; }
    .alm-modal-head h3 .material-icons { color: #fff; }
    .alm-modal-head .alm-x { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #fff; opacity: .75; }
    .alm-modal-head .alm-x:hover { color: #fff; opacity: 1; }
    /* "Detalles del producto": un poco menos de hueco que los demás (16px) antes de "Ubicación
       en estante..." — con 6px quedaba pegado al encabezado. Su ícono, en blanco. */
    #almDetalleModal .alm-modal-body { padding-top: 14px; }
    /* Compatibilidad en "Detalles del producto": números de parte y equipos que lo usan, con
       + para agregar y × para quitar (solo con almacen.productos). Todo en texto oscuro sobre
       gris claro: el azul queda para los botones. */
    #almDetCompat { border-top: 1px solid #f1f5f9; padding-top: 12px; display: flex; flex-direction: column; gap: 8px; }
    /* Nºs de parte y equipos, cada uno en un DESPLEGABLE que arranca cerrado: la cabecera dice
       cuántos hay y se abre con un toque. Abiertos a la vez ocupaban medio modal. */
    .alm-det-sec { border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
    .alm-det-sec-cab { display: flex; align-items: center; gap: 4px; padding: 3px 6px 3px 3px; }
    .alm-det-sec-tog { flex: 1; min-width: 0; display: flex; align-items: center; justify-content: space-between; gap: 6px; border: none; background: none;
                       padding: 6px 8px; border-radius: 8px; font: inherit; color: #0f172a; text-align: left; cursor: pointer; }
    .alm-det-sec-tog:hover:not(:disabled) { background: #f8fafc; }
    .alm-det-sec-tog:disabled { cursor: default; }
    .alm-det-sec-flecha { font-size: 20px; color: #0f172a; transition: transform .15s; }
    .alm-det-sec-tog:disabled .alm-det-sec-flecha { visibility: hidden; }
    .alm-det-sec-tog[aria-expanded="true"] .alm-det-sec-flecha { transform: rotate(180deg); }
    .alm-det-sec-cuerpo { padding: 8px 10px 10px; border-top: 1px solid #f1f5f9; }
    .alm-det-sec-tog .alm-det-sec-tit { display: block; min-width: 0; line-height: 1.35; }
    .alm-det-sec-tog .alm-det-sec-tit .material-icons { vertical-align: -3px; margin-right: 4px; }
    .alm-det-sec-tit { font-size: 13px; font-weight: 600; color: #0f172a; display: flex; align-items: center; gap: 6px; }
    /* Toda la ficha con la letra de la app: los <input> traen la del sistema si no se les dice. */
    #almDetalleModal, #almDetalleModal input, #almDetalleModal button { font-family: inherit; }
    #almDetalleModal label[for="almDetUbicacion"] { font-size: 13px; font-weight: 600; color: #0f172a; text-transform: none; letter-spacing: 0; }
    #almDetUbicacion { font-size: 12.5px; font-weight: 500; color: #0f172a; }
    .alm-det-sec-tit .material-icons { font-size: 16px; color: #0f172a; }
    .alm-det-mas { display: inline-flex; align-items: center; gap: 2px; border: none; background: none; padding: 2px 4px; border-radius: 6px;
                   font: inherit; font-size: 12.5px; font-weight: 500; color: #0067b1; cursor: pointer; }
    .alm-det-mas:hover { background: #e0f2fe; }
    .alm-det-mas .material-icons { font-size: 16px; }
    .alm-det-chips { display: flex; flex-wrap: wrap; gap: 5px; }
    .alm-det-chip { display: inline-flex; align-items: center; gap: 2px; background: #f1f5f9; color: #0f172a; border: 1px solid #cbd5e1;
                    border-radius: 6px; padding: 2px 8px; font-size: 12.5px; font-weight: 500; }
    /* Abierto, un producto con muchos equipos no estira el modal: la lista tiene su propio scroll. */
    .alm-det-lista { display: flex; flex-direction: column; gap: 4px; max-height: 190px; overflow-y: auto; }
    .alm-det-eq { display: flex; align-items: center; gap: 8px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 7px; padding: 6px 9px; }
    /* Ancho fijo para que los modelos queden en columna aunque el tipo sea largo (el nombre
       completo va en el title). */
    .alm-det-eq-tipo { flex: 0 0 120px; font-size: 12.5px; font-weight: 500; color: #0f172a; text-transform: uppercase;
                       overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .alm-det-eq-mod { font-size: 12.5px; font-weight: 500; color: #0f172a; flex: 1; min-width: 0; }
    .alm-det-eq-dato { font-size: 12.5px; font-weight: 500; color: #0f172a; }
    .alm-det-eq-etapa { font-size: 12.5px; font-weight: 500; color: #0f172a; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 5px; padding: 1px 7px; }
    .alm-det-quitar { display: inline-flex; border: none; background: none; padding: 0; margin-left: 2px; color: #64748b; cursor: pointer; }
    .alm-det-quitar:hover { color: #dc2626; }
    .alm-det-quitar .material-icons { font-size: 15px; }
    /* Formularios en línea del + : caja y botón, sin salir del modal. */
    .alm-det-form { position: relative; display: flex; gap: 6px; margin-top: 6px; }
    .alm-det-form input { flex: 1; min-width: 0; height: 32px; padding: 0 9px; font: inherit; font-size: 12.5px; }
    .alm-det-form .btn-primary-maquinaria { padding: 0 14px; height: 32px; border-radius: 8px; font-size: 13px; }
    .alm-det-sug { position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 5; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
                   box-shadow: 0 10px 22px rgba(15,23,42,.14); max-height: 220px; overflow-y: auto; padding: 4px; }
    /* Cada sugerencia se ve IGUAL que un equipo ya vinculado (misma rejilla tipo + modelo,
       mismos tamanos y pesos): antes el tipo y el modelo iban pegados en una sola linea con
       otra letra y la lista parecia de otra pantalla. */
    .alm-det-sug-item { display: flex; align-items: center; gap: 8px; padding: 6px 9px; border-radius: 6px; cursor: pointer; }
    .alm-det-sug-item:hover { background: #e0f2fe; }
    .alm-det-sug-item .alm-det-eq-tipo { flex: 0 0 110px; }
    /* La placa va en una segunda línea DENTRO de la celda del modelo, para que quepa también en
       el celular. Los códigos de modelo largos (ZZ4257V344JB1) pueden partirse para no salirse
       de la sugerencia. */
    .alm-det-sug-item .alm-det-eq-mod { overflow-wrap: anywhere; }
    .alm-det-sug-placa { display: block; font-size: 11.5px; color: #64748b; }
    .alm-det-sug-vacio { padding: 8px 9px; font-size: 12px; color: #64748b; font-style: italic; }
    /* `hidden` tiene que ganarle al display:flex / inline-flex de arriba: el "+ Agregar" /
       "+ Vincular" se esconde así a quien no tiene almacen.productos (y la lista sin
       sugerencias no se ve). */
    #almDetCompat[hidden], .alm-det-form[hidden], .alm-det-mas[hidden], .alm-det-sug:empty { display: none; }
    .alm-det-msg { font-size: 12px; font-weight: 600; color: #b91c1c; }
    /* El detalle aparece SIN deslizamiento (como los detalles del módulo Equipos):
       se quita la animación de entrada almIn solo para este modal. */
    #almDetalleModal .alm-modal { animation: none; }
    .alm-modal-body { padding: 16px 18px; display: flex; flex-direction: column; gap: 12px; }
    /* Pie en gris claro, como el de Recepción: separa los botones del contenido. */
    .alm-modal-foot { padding: 12px 18px; border-top: 1px solid #e2e8f0; background: #f8fafc; display: flex; justify-content: center; gap: 8px; }
    /* Botones del pie de TODOS los modales del módulo, más bajos que el 12px de padding
       general de .btn-primary-maquinaria (en un modal se veían muy grandes). */
    .alm-modal-foot .btn-primary-maquinaria { padding: 8px 20px; border-radius: 10px; }
    /* Rótulo de campo. El :not() NO es adorno: .multiselect-item también es un <label>, y
       este selector (0,1,1) le ganaba al del componente (0,1,0) — le imponía display:block
       (matando su flex), gris 700 en vez del azul 600 propio, MAYÚSCULAS y un
       margin-bottom:4px que descuadraba verticalmente las casillas dentro de su caja. */
    .alm-modal label:not(.multiselect-item) { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.3px; display: block; margin-bottom: 4px; }
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
    /* Modal "Nota de Entrega": los dropdowns de Proyecto/Contrato muestran el
       valor seleccionado como PLACEHOLDER del input (selectOption del componente
       limpia el .value y mete el label en placeholder para que el filtrado
       arranque desde vacio al teclear). Algunos navegadores renderizan
       placeholders en italic/opacidad baja — el valor seleccionado se veia "en
       cursiva como inclinado". Normalizamos el placeholder de estos inputs como
       texto normal (sin italic, color slate-900, opacidad 1) para que se lea
       identico a un value tipico. NOTA: usamos solo `::placeholder` (estandar
       desde 2017, soportado por todos los navegadores actuales). Mezclar el
       prefix `::-webkit-input-placeholder` en un selector agrupado por coma
       invalida toda la regla en browsers que no conocen el prefix. */
    #almSalidaModal .dropdown-trigger input::placeholder { font-style: normal; color: #0f172a; opacity: 1; }
    /* "(opcional)" al lado de la etiqueta del campo (Ubicación, Cantidad): más claro y sin negrita. */
    .alm-modal label .alm-opc { font-weight: 400; color: #94a3b8; }
    /* Ayuda en la misma línea del rótulo (Nombre del almacén): sin las mayúsculas del label. */
    .alm-modal label .alm-label-ayuda { font-size: 11.5px; font-weight: 400; color: #94a3b8; text-transform: none; letter-spacing: 0; margin-left: 4px; }

    /* Sugerencias de los filtros — mismo look que los desplegables (.dropdown-content / .dropdown-item) de la app */
    .alm-suggest {
        position:absolute; top:calc(100% + 5px); left:0; right:0; background:#fff;
        border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,0.1);
        z-index:1000; max-height:260px; overflow-y:auto; padding:5px; display:none;
        scrollbar-width:thin; scrollbar-color:#cbd5e1 transparent;
    }
    .alm-suggest::-webkit-scrollbar { width:5px; }
    .alm-suggest::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:999px; }
    .alm-suggest::-webkit-scrollbar-track { background:transparent; }
    .alm-suggest.open { display:block; animation:slideDown 0.18s ease-out; }
    .alm-suggest-item { display:flex; flex-direction:column; gap:2px; padding:10px 15px; border-radius:8px; cursor:default; transition:background 0.2s; font-weight:600; color:var(--maquinaria-dark-blue,#1e3a5f); }
    .alm-suggest-item:hover, .alm-suggest-item.active { background:#f0f4f8; }
    .alm-suggest-item.si-sel { background:#ebf4ff; color:var(--maquinaria-blue,#0067b1); }
    .alm-suggest-item .nom { font-size:13.5px; color:#475569; font-weight:600; }
    /* Fila código + descripción de cada sugerencia del buscador: el CÓDIGO va PRIMERO
       y la descripción después, ambos con la MISMA letra (sin negrita ni monospace). La
       descripción ocupa el resto y se envuelve, para ver la mayor parte en pantallas chicas. */
    .alm-suggest-line { display:flex; align-items:flex-start; gap:8px; }
    .alm-suggest-line .nom { flex:1 1 auto; min-width:0; }
    .alm-suggest-cod { font-size:13.5px; font-weight:600; color:#475569; flex:0 0 auto; white-space:nowrap; }
    .alm-suggest-empty { padding:10px 15px; font-size:13px; color:#94a3b8; }
    /* Variante "en línea" para los modales (no flota: empuja el contenido — así no la recorta el overflow del modal) */
    .alm-suggest-inline { margin-top:6px; border:1px solid #e2e8f0; border-radius:12px; background:#fff; box-shadow:0 6px 16px rgba(0,0,0,0.06); max-height:200px; overflow-y:auto; padding:5px; display:none; }
    /* Sugerencias que viven DENTRO de un modal (Categoría y Unidad de Medida del modal de
       producto). Van en position:fixed —no absolute— porque .alm-modal-body tiene
       overflow-y:auto: una lista absolute estiraba el contenido y le sacaba al modal una
       barra de desplazamiento vertical. Fixed la saca del flujo y del recorte, así que se
       despliega por encima del modal. left/top/width los calcula almSuggestAnclar(). */
    .alm-suggest-float { position:fixed; margin-top:0; z-index:10001; box-sizing:border-box; }
    .alm-suggest-inline.open { display:block; animation:slideDown 0.18s ease-out; }
    .alm-suggest-inline .si-item { display:flex; align-items:center; gap:10px; padding:10px 15px; border-radius:8px; cursor:default; font-size:14px; font-weight:600; color:var(--maquinaria-dark-blue,#1e3a5f); transition:background 0.2s; }
    .alm-suggest-inline .si-item:hover { background:#f0f4f8; }
    .alm-suggest-inline .si-item.si-sel { background:#ebf4ff; color:var(--maquinaria-blue,#0067b1); }
    /* Campo "Categoría" del modal de producto: input + botón desplegable (caret) */
    .alm-cat-field { position:relative; display:flex; align-items:center; }
    .alm-cat-field > input { flex:1; padding-right:36px !important; }
    /* Texto de la categoría más chico que el resto de campos (pedido del cliente). Por id
       para ganarle al font-size:14px de ".alm-modal input". */
    #almProdCategoria { font-size:12px; }
    .alm-cat-caret { position:absolute; right:3px; top:50%; transform:translateY(-50%); width:30px; height:30px; border:none; background:transparent; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#64748b; border-radius:8px; transition:background .15s,color .15s; }
    .alm-cat-caret:hover { background:#f1f5f9; color:#0f172a; }
    .alm-cat-caret .material-icons { font-size:22px; transition:transform .15s; }
    .alm-cat-caret.open .material-icons { transform:rotate(180deg); }

    /* ── Consolidado de Inventario: visible + apilado en TODO el rango donde
       .page-layout-grid ya colapsa a 1 columna (≤1024px, ver estilos_globales.css)
       — antes esta regla vivía solo dentro del @media ≤768px de abajo, mientras
       que el grid colapsa desde 1024px y el JS (placeSidebarMobile) también movía
       la tarjeta recién a los 768px. Entre 769px y 1024px (tablet, pantalla ancha
       en horizontal, zoom del navegador) no coincidía NINGUNA de las tres reglas:
       ni la de estilos_globales.css que lo OCULTA en ≤900px, ni esta que lo
       muestra apilado, ni el JS que lo reubica arriba de la tabla — la tarjeta
       quedaba en su posición de grid por defecto (debajo de la tabla) en vez de
       arriba. Las tres ahora usan el MISMO corte: 1024px.
       margin-bottom evita que la tabla/tarjetas queden pegadas a la caja del
       Consolidado (cliente reporto que se veian "super pegados"). */
    @media (max-width: 1024px) {
        /* `body` delante a propósito: estilos_globales.css oculta este MISMO selector
           (.page-layout-grid .counter-sidebar { display:none !important }) en su @media
           ≤900px. Sin el `body`, las dos reglas empatan en especificidad y en !important,
           y esta solo ganaba por ir más abajo — es decir, por estar en un <style> del
           <body>. El día que este CSS se mueva a un .css del <head> (que es hacia donde
           va el proyecto), el Consolidado desaparecería en TODOS los teléfonos. Con el
           `body` gana por especificidad, viva donde viva.
           width/position NO se repiten aquí: ya los pone estilos_globales.css en su
           propio @media ≤1024px (.counter-sidebar { width:100%; position:static }). */
        body .page-layout-grid .counter-sidebar {
            display: flex !important;
            flex-direction: column !important;
            gap: 10px !important;
            margin-top: 10px !important;
            margin-bottom: 16px !important;
        }
        /* Espacio entre la última fila de la tabla y el wrapper "En otros almacenes".
           Va en ESTE corte, no en el de 768px, porque quien lo mueve debajo de la tabla es
           el JS con BREAKPOINT = 1024 (almColocarSidebarMovil): entre 769 y 1024 el panel
           ya estaba abajo pero pegado a la tabla, sin este margen. */
        #almDistWrapper { margin-top: 16px !important; }
    }

    /* ── Cabecera apilada: MISMO corte que menu.css (≤900px) ──────────────────
       menu.css pone el <h1> a `display:block; width:100%; text-align:center` desde 900px,
       pero estas reglas vivían en el @media ≤768px de abajo. Entre 769 y 900 (tablet en
       vertical, ventana angosta, zoom) el título ocupaba un renglón entero centrado y el
       separador vertical de 1×34px quedaba huérfano al lado del selector de almacén.
       Al usar el mismo 900px, la cabecera pasa a su forma apilada de una sola vez. */
    @media (max-width: 900px) {
        /* Titulo de pagina oculto + separador vertical (ya no tiene sentido) */
        .page-title-card .page-title { display: none !important; }
        .page-title-card > div > span[aria-hidden="true"] { display: none !important; }
        /* El wrapper interno (`.page-title-card > div`) usaba flex horizontal con
           separador; aquí lo apilamos para que el selector de almacen ocupe todo el ancho. */
        .page-title-card > div { flex-direction: column !important; align-items: stretch !important; gap: 10px !important; }
        /* El bloque del selector de almacen (mini-label + dropdown) tomaba flex:0 1 auto. */
        .page-title-card > div > div { width: 100% !important; flex: 1 1 100% !important; }
        .page-title-card .alm-sel-alm-box { width: 100% !important; min-width: 0 !important; max-width: 100% !important; }
    }

    /* ── Responsive mobile (≤768px) — patron calcado de /admin/equipos ──
       En mobile: titulo OCULTO (el espacio vertical es caro en telefono — el
       usuario ya sabe que esta en el modulo de almacen por la nav); el selector
       de almacen queda full-width como header efectivo. Filtros apilados, boton
       Acciones full-width al final, menu desplegable limitado al viewport. */
    @media (max-width: 768px) {
        /* La barra de selección en móvil muestra TEXTO en vez de iconos (regla global
           en estilos_globales.css, .selection-floating-bar .desktop-text). Excepción pedida: el botón "Etiquetas" se
           muestra con su ÍCONO QR (más compacto y reconocible) y sin el texto. El #id
           gana en especificidad sobre la regla global por clase. */
        #almBulkEtqBtn i.material-icons { display: inline-flex !important; }
        #almBulkEtqBtn .desktop-text { display: none !important; }

        /* Filtros: cada caja full-width, una debajo de la otra. La fila completa
           ya hace wrap nativamente (flex-wrap:wrap en #almFilters); aqui solo
           ajustamos el flex-basis para evitar tracks anchos. */
        #almFilters { gap: 8px; }
        #almFilters .alm-filter { max-width: none !important; flex: 1 1 100% !important; }
        /* Categoría se encoge para dejarle su hueco al botón de filtros avanzados: los dos
           en la misma fila (antes el botón caía solo en una fila para él). */
        #almFilters > .alm-filter:nth-child(2) { flex: 1 1 0 !important; }

        /* Boton "Acciones" full-width — antes quedaba angosto a la derecha por
           `margin-left:auto`, raro en mobile. Y el menu desplegable se alinea
           a la izquierda para entrar en pantalla sin overflow. */
        #almFilters > div:last-child { width: 100% !important; flex: 1 1 100% !important; margin-left: 0 !important; }
        #almFilters > div:last-child > div { width: 100%; }
        #almBtnAcciones { width: 100% !important; justify-content: center; }
        {{-- max-width:none y no `calc(100vw - 20px)`: el menú ya es width:100% de su
             wrapper, que en mobile ocupa la fila entera; 100vw cuenta además la barra de
             desplazamiento y lo dejaba más ancho que su hueco. --}}
        #almAccionesMenu { left: 0 !important; right: 0 !important; width: 100% !important; max-width: none !important; }

        /* Consolidado más chico en teléfono. Antes esto se intentaba con selectores de
           atributo (`[style*="font-size: 34px"]`) que NUNCA llegaron a aplicar: los
           style inline de la tarjeta se escriben sin espacio tras los dos puntos
           (`font-size:34px`), así que ninguno de los seis casaba. Ahora la tarjeta usa
           clases (.alm-cons-*) y se ajusta directo. */
        .alm-cons-card { padding: 9px 11px !important; }
        .alm-cons-total { min-width: 56px !important; }
        .alm-cons-total .num { font-size: 24px !important; }
        .alm-cons-badge strong { font-size: 14px !important; }
        .alm-cons-badge .material-icons { font-size: 15px !important; }
        /* Ocultar el titulo "Consolidado de Inventario" en mobile — no aporta
           cuando el usuario ya esta dentro del modulo y los iconos PRODUCTOS /
           inventory_2 / warning explican por si mismos. */
        .counter-sidebar .alm-consolidado-title { display: none !important; }
        /* En el teléfono el panel lateral solo se ve en modo "En otros almacenes": la
           distribución por categoría comía pantalla y cada tarjeta ya muestra su categoría
           (pedido del cliente). El wrapper se esconde entero para no dejar una caja blanca. */
        #almDistWrapper:not(:has(.alm-otros-almacenes)) { display: none !important; }
        /* Panel "En otros almacenes" en mobile — compacto verticalmente.
           NO usa selector .counter-sidebar como ancestor porque el JS mueve el
           wrapper (#almDistWrapper) fuera de .counter-sidebar a "despues de la
           tabla" (separado del Consolidado). */
        .alm-otros-almacenes h4 { margin-bottom: 6px !important; padding-bottom: 5px !important; font-size: 11px !important; }
        .alm-otros-almacenes h4 .material-icons { font-size: 15px !important; }
        .alm-otros-almacenes ul { max-height: 40vh !important; gap: 2px !important; }
        .alm-otros-almacenes li { padding: 5px 7px !important; }
        /* Por clase y no por :first-child/:last-child: en una fila con desglose el último hijo
           es el <ul> de proyectos, y su cantidad se quedaba con la letra de PC. */
        .alm-otros-almacenes li > .nom { font-size: 11px !important; }
        .alm-otros-almacenes li > .qty { font-size: 11px !important; padding: 1px 7px !important; }
        /* El desglose por proyecto NO debe heredar lo de arriba: sus <li> y <span> son
           descendientes de .alm-otros-almacenes igual que los del almacén, así que sin
           esto quedaban con el mismo alto y la misma letra y se perdía la jerarquía —
           parecerían almacenes, no proyectos de uno. Va por .alm-panel-sub, que gana en
           especificidad. El max-height se anula porque el 40vh es para la lista de
           almacenes, no para una sublista de tres renglones. */
        .alm-otros-almacenes .alm-panel-sub { max-height: none !important; gap: 0 !important; }
        .alm-otros-almacenes .alm-panel-sub li { padding: 2px 0 !important; }
        .alm-otros-almacenes .alm-panel-sub li > .nom { font-size: 10.5px !important; }
        .alm-otros-almacenes .alm-panel-sub li > .qty { font-size: 10.5px !important; padding: 0 !important; }
        #almDistWrapper:has(.alm-otros-almacenes) { padding: 10px 12px !important; }
        /* "Ver movimientos del producto" del modal de detalles: el kardex tabular
           que abre es pesado en mobile. Cliente prefirio quitar el boton en
           telefono y mantener el modal de detalles compacto. */
        #almDetalleModal .alm-det-act-kardex { display: none !important; }

        /* Modal "Registrar salida" en mobile: los grids inline (Proyecto+Contrato a
           2 cols, Fecha+RQ+Solicitante a 3 cols) comprimian demasiado cada campo a
           ~100px y los inputs quedaban con casi nada de ancho util. Forzamos 1 col:
           cada campo full-width, apilados verticalmente. Mismo patron que el modal
           ya respira mejor en pantallas chicas. */
        #almSalidaModal .alm-modal-grid-2,
        #almSalidaModal .alm-modal-grid-3 { grid-template-columns: 1fr !important; }
        /* Tambien comprimimos el padding del wrapper de la "Nota de Entrega" para
           ganar unos px de ancho en mobile. */
        #almSalidaModal #almSalidaNotaWrap { padding: 12px !important; }
        /* Y soltamos el max-width del modal en mobile para que pegue al viewport
           sin margenes laterales gigantes (alm-modal default era 600px en desktop).
           SIN 100vw: `.alm-modal` ya es width:100% dentro del overlay, que es flex con
           padding:16px, así que 100% = exactamente el hueco disponible. Con
           `calc(100vw - 16px)` el modal salía 16px MÁS ANCHO que ese hueco (100vw
           incluye la barra de desplazamiento) y se comía el respiro lateral — el mismo
           motivo por el que estilos_globales.css prohíbe 100vw en estos anchos. */
        #almSalidaModal .alm-modal { max-width: none !important; }
        /* ═══════════════════════════════════════════════════════════
           MOBILE CARD LAYOUT — Inventario de Almacén
           Cada <tr.alm-row> es una tarjeta GRID 3-col × 2 filas:
             ┌──────────────────────────────────────────────────────┐
             │ 00042 ABRAZADERA INOXIDABLE 1/2  (banda gris)        │  ← nombre+codigo (full)
             ├──────────────────────────────────────────────────────┤
             │ STOCK 5.000 UND ⚠    [▲ 0 ▼ stepper]      [👁]       │  ← stock | cant | det
             └──────────────────────────────────────────────────────┘

           - Codigo + nombre se leen como UN solo texto unificado: el <span> del
             codigo (.alm-cod-mini) pasa a display:inline y hereda font, color y
             peso del nombre. En PC ese mismo span va en su renglon, pequeno y
             monoespaciado, ENCIMA del nombre. Ya NO hay columna de codigo.
           - alm-td-cat OCULTO (peticion previa del cliente).
           - La unidad (UM) vive inline en la celda de Stock (span .alm-stock-um),
             tanto en desktop como en mobile — ya no hay columna "UND" aparte.
           - Stock row: ::before "STOCK" label + valor + unidad (span),
             todo flex en la misma linea. A la derecha: stepper y boton ver.
           ═══════════════════════════════════════════════════════════ */

        .alm-table-wrap { overflow-x: visible !important; border: none !important; border-radius: 0 !important; background: transparent !important; }
        .alm-table { min-width: 0 !important; display: block !important; }
        .alm-table thead { display: none !important; }
        .alm-table tbody { display: flex !important; flex-direction: column !important; gap: 12px !important; }

        .alm-table tr.alm-row {
            display: grid !important;
            /* col 1 = 1fr (stock-info absorbe el ancho sobrante), col 2 = auto
               (stepper natural ~76px), col 3 = auto (boton ojo ~30px). */
            grid-template-columns: 1fr auto auto !important;
            grid-template-areas:
                "nombre nombre nombre"
                "stock  cant   det" !important;
            gap: 0 !important;
            background: #fff !important;
            /* Sombra multi-capa moderna (mismo lenguaje visual de /admin/equipos):
               una capa fina para la elevacion base + una capa difusa para profundidad. */
            border: 1px solid #cbd5e1 !important;
            border-radius: 14px !important;
            box-shadow: 0 1px 3px rgba(15,23,42,0.04), 0 4px 12px rgba(15,23,42,0.08) !important;
            padding: 0 !important;
            overflow: hidden !important;
            transition: box-shadow 0.2s ease, transform 0.15s ease !important;
        }
        /* Feedback tactil al presionar (mobile no tiene hover) — eleva la tarjeta */
        .alm-table tr.alm-row:active {
            box-shadow: 0 2px 6px rgba(15,23,42,0.06), 0 8px 20px rgba(15,23,42,0.12) !important;
            transform: translateY(-1px) !important;
        }
        .alm-table tr.alm-row.alm-row-bajo {
            border-left: 4px solid #f59e0b !important;
            background: #fffbeb !important;
            box-shadow: 0 1px 3px rgba(245,158,11,0.10), 0 4px 12px rgba(245,158,11,0.12) !important;
        }
        /* Última vista (almMarcarVista): la tarjeta en celeste. */
        .alm-table tr.alm-row.alm-row-vista { background: #e0f2fe !important; border-color: #93c5fd !important; }

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

        /* Codigo y categoria SE OCULTAN como celdas propias en mobile — el codigo se
           reusa como prefijo del nombre (::before via data-codigo) y la categoria el
           cliente pidio quitarla. OJO: este display:none debe ir en SU PROPIA regla;
           antes estaba comma-unido a .alm-td-nombre de abajo y, en vez de ocultarse,
           heredaban display:block + gradiente + grid-area:nombre (se encimaban). */
        .alm-table tr.alm-row td.alm-td-cat,
        .alm-table tr.alm-row td.alm-td-foto { display: none !important; }

        /* Fila 1: nombre + codigo como UN SOLO TEXTO unificado — banda gris.
           El ::before pone "00042 " como prefijo, heredando font/color/weight
           del td padre (sin monospace, sin separador "·", sin gris distinto).
           El cliente lo pidio: "todo en el mismo texto, sin separar". */
        .alm-table tr.alm-row td.alm-td-nombre {
            grid-area: nombre !important;
            display: block !important;
            background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%) !important;
            padding: 9px 14px !important;
            font-size: 12px !important;
            font-weight: 700 !important;
            color: #1e293b !important;
            line-height: 1.3 !important;
        }
        /* En teléfono el cliente lo quiere como UN SOLO texto ("00042 ABRAZADERA"), no en
           dos renglones: el mismo <span> del código se pone en línea y hereda el tipo, el
           color y el peso del nombre. Antes esto lo hacía un ::before con data-codigo, que
           duplicaba el dato; ahora se pinta una sola vez y solo cambia de forma. */
        .alm-table tr.alm-row td.alm-td-nombre .alm-cod-mini {
            display: inline !important;
            font-family: inherit !important;
            font-size: inherit !important;
            font-weight: inherit !important;
            letter-spacing: inherit !important;
            color: inherit !important;
            margin: 0 !important;
        }
        .alm-table tr.alm-row td.alm-td-nombre .alm-cod-mini::after { content: "  "; white-space: pre; }

        /* Fila 2: stock-info (label STOCK + valor + UM) | stepper | boton ojo.
           Todo en una sola linea — el cliente pidio ver "STOCK valor unidad
           stepper" lado a lado, no apilados. */
        .alm-table tr.alm-row td.alm-td-stock {
            grid-area: stock !important;
            padding: 10px 8px 10px 14px !important;
            font-size: 16px !important;
            font-weight: 800 !important;
            color: #0f172a !important;
            justify-content: flex-start !important;
            gap: 6px !important;
        }
        .alm-table tr.alm-row td.alm-td-stock::before {
            content: "Stock";
            font-size: 10px;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        /* Stepper de cantidad: en la misma fila que stock, alineado a la derecha
           justo antes del boton ojo. Sin border-top (ya no es una fila separada). */
        .alm-table tr.alm-row td.alm-td-cant {
            grid-area: cant !important;
            padding: 8px 4px !important;
            justify-content: flex-end !important;
        }
        /* Por corregir: el motivo va bajo la caja de cantidad, no a su lado (no cabría). Con
           width:0 + min-width:100% no aporta ancho propio a la columna —toma el de la caja y
           parte en líneas—: si no, la ensancha y le quita sitio al stock. */
        #almTableBody tr.alm-row.alm-row-missing-cant td.alm-td-cant,
        #almTableBody tr.alm-row.alm-row-exceeds-stock td.alm-td-cant,
        #almTableBody tr.alm-row.alm-row-pide-parte td.alm-td-cant { flex-direction: column; align-items: flex-end !important; justify-content: center !important; }
        #almTableBody tr.alm-row.alm-row-missing-cant .alm-td-cant::after,
        #almTableBody tr.alm-row.alm-row-exceeds-stock .alm-td-cant::after,
        #almTableBody tr.alm-row.alm-row-pide-parte .alm-td-cant::after { width: 0; min-width: 100%; text-align: right; white-space: normal; line-height: 1.2; }
        .alm-table tr.alm-row td.alm-td-det {
            grid-area: det !important;
            padding: 8px 14px 8px 4px !important;
            justify-content: flex-end !important;
        }
        /* Estado vacío / sin almacén: el <tr><td colspan> sin tarjeta. */
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
                <span class="alm-ic"><i class="material-icons" style="font-size:18px;">search</i></span>
                <input type="text" id="almFiltroBuscar" autocomplete="off"
                       placeholder="{{ $bActivo ?: 'Buscar por código o descripción…' }}"
                       value=""
                       data-active="{{ $bActivo }}"
                       data-placeholder-empty="Buscar por código o descripción…"
                       oninput="window.almBuscarInput()" onfocus="window.almBuscarFocus()"
                       onkeydown="window.almBuscarEnter(event)">
                {{-- Escanear QR: icono dentro del propio buscador. Visible cuando el campo
                     está vacío; al escribir/filtrar se oculta y aparece la "x" de limpiar
                     (toggle en QrScan.iconToggle, llamado desde filtros()/almBuscarInput).
                     En teléfono abre el modal de cámara; en PC NO abre nada: enfoca este
                     mismo buscador para que el lector USB teclee aquí. --}}
                <i class="material-icons qrs-ic" id="almBuscarScan" title="Escanear código QR"
                   style="display:{{ $bActivo ? 'none' : 'flex' }};"
                   onclick="window.QrScan.abrir()">&#xf206;</i>
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
