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
    .alm-filter input[type="text"], .alm-filter select {
        flex: 1; border: none; background: transparent; outline: none; font-size: 14px;
        color: #1e293b; padding: 10px 6px 10px 4px; min-width: 0; height: 100%; cursor: text;
    }
    .alm-filter select { cursor: pointer; -webkit-appearance: none; appearance: none; }
    .alm-filter .filter-clear { padding: 0 8px; color: #64748b; font-size: 18px; cursor: pointer; }
    /* Icono "escanear QR" DENTRO del buscador (patrón apps de súper). Mutuamente
       excluyente con la "x" de limpiar: visible solo cuando el buscador está vacío. */
    .alm-filter .alm-scan-ic { padding: 0 8px; color: #64748b; font-size: 20px; cursor: pointer; transition: color .15s; align-items: center; }
    .alm-filter .alm-scan-ic:hover { color: #7c3aed; }

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
    /* Fila con stock bajo: tono rojo claro para indicar urgencia. Al hacer hover hereda
       el azul general como cualquier otra fila (sin sobrescribir con !important — antes
       quedaba naranja en hover y se sentia inconsistente). */
    .alm-row-bajo td { background: #fee2e2; }
    /* Franja roja izquierda ("severity stripe") en filas de stock bajo. Se ve SIEMPRE,
       incluso cuando la fila está seleccionada (el fondo azul !important tapa el rojo del
       fondo, pero NO este box-shadow). Así, al auditar/mover stock y recargar, el indicador
       de "bajo" refleja el estado nuevo de inmediato sin tener que deseleccionar la fila. */
    .alm-table tbody tr.alm-row.alm-row-bajo td:first-child { box-shadow: inset 4px 0 0 0 #dc2626; }
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

    /* Filas seleccionadas a las que les falta la cantidad de "Salida" tras tocar "Registrar salida".
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
    /* Unidad (UM) junto al número en la celda de Stock — reemplaza la columna "UND". */
    .alm-stock-um { margin-left:5px; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; }

    /* Contador "inventory_2 N" clickable — toggle del filtro "Solo seleccionados". */
    .alm-bulk-counter { cursor:pointer; user-select:none; transition:transform 0.12s; }
    .alm-bulk-counter:hover { transform:scale(1.04); }
    /* "Solo seleccionados" activo: en vez del anillo/glow que rodeaba TODO el
       contador (se veia feo), resaltamos solo el NUMERO en un circulo ambar
       limpio, igual que en /admin/equipos. */
    .alm-bulk-counter.is-filtering #almBulkCount {
        background:#fbbf24; color:#1e293b; min-width:22px; height:22px; padding:0 5px;
        border-radius:999px; display:inline-flex; align-items:center; justify-content:center;
        font-weight:800; line-height:1; box-sizing:border-box;
    }

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
    .alm-det-act { display:flex; align-items:center; gap:10px; width:100%; text-align:left; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:8px 12px; font-family:inherit; font-size:14px; font-weight:600; color:#334155; cursor:default; transition:background .15s, border-color .15s; }
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
    /* Inputs del modal "Nuevo / Editar producto": todo se guarda en mayúsculas,
       se ven en mayúsculas mientras se escribe para coincidir con lo que se guarda.
       El placeholder NO se transforma. CODIGO queda fuera (solo dígitos). */
    #almProdNombre, #almProdUm, #almProdCategoria, #almDetUbicacion { text-transform: uppercase; }
    /* Multiselect de frentes dentro del modal de almacén: el panel empuja el contenido (no flota) para que el overflow del modal no lo recorte */
    #almAlmacenModal .multiselect-content { position: static; box-shadow: none; margin-top: 6px; }
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
    #almEtiquetasModal .dropdown-content { z-index: 60; max-height: 190px; overflow-y: auto; }
    #almEtiquetasModal .custom-dropdown.active .dropdown-content { animation: slideDown 0.18s ease-out; }
    /* El "Formato" muestra su valor en el placeholder del input readonly: lo pintamos como
       texto sólido (no gris) para que "Carta/A4 — grilla" se vea como la opción elegida por defecto. */
    #almEtiquetasModal .dropdown-trigger input::placeholder { font-style: normal; color: #0f172a; opacity: 1; }
    /* Filtros del modal "Movimientos del producto": Tipo y Fechas EN LA MISMA FILA.
       Iban con flex-wrap:wrap y en un modal de 540 px los dos campos de fecha no entraban
       junto al selector, así que "Fechas" caía a un renglón aparte y los filtros se veían
       desalineados (uno arriba, los otros abajo). Con nowrap el grupo de fechas cede ancho
       (flex:1 + min-width:0 hasta los inputs) en vez de romper la fila. */
    .alm-kp-filtros { display: flex; align-items: center; justify-content: center; gap: 10px; flex-wrap: nowrap; background: #fff; padding: 4px 0; }
    .alm-kp-filtros .alm-kp-grupo-tipo { display: flex; align-items: center; gap: 6px; flex: 0 0 auto; }
    .alm-kp-filtros .alm-kp-grupo-fechas { display: flex; align-items: center; gap: 6px; flex: 1 1 auto; min-width: 0; }
    .alm-kp-filtros .alm-kp-rango { display: flex; align-items: center; gap: 4px; flex: 1 1 auto; min-width: 0; }
    .alm-kp-filtros .alm-kp-fecha-box { flex: 1 1 0; min-width: 0; }
    /* Pantallas angostas (tablet en vertical): ahí sí se permite el salto de línea, pero
       centrado, antes que aplastar los campos hasta que no se lea la fecha. */
    @media (max-width: 560px) { .alm-kp-filtros { flex-wrap: wrap; } }
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
    /* Todos los modales del módulo: título y botones centrados; la X queda fija en la esquina. */
    .alm-modal-head { padding: 14px 40px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: center; position: relative; }
    .alm-modal-head h3 { margin: 0; font-size: 15px; font-weight: 800; color: #1e293b; display: flex; align-items: center; gap: 8px; text-align: center; }
    .alm-modal-head .alm-x { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); }
    /* "Detalles del producto": sin raya bajo el título y cuerpo más pegado — el cliente
       pidió quitar el separador entre el título y "Ubicación en estante..." y reducir el hueco. */
    #almDetalleModal .alm-modal-head { border-bottom: none; }
    #almDetalleModal .alm-modal-body { padding-top: 6px; }
    /* El detalle aparece SIN deslizamiento (como los detalles del módulo Equipos):
       se quita la animación de entrada almIn solo para este modal. */
    #almDetalleModal .alm-modal { animation: none; }
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
    .alm-x { cursor: pointer; color: #94a3b8; }
    .alm-x:hover { color: #475569; }

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
    .alm-suggest-float { position:fixed; margin-top:0; z-index:10001; }
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

    /* ── Consolidado de Inventario: visible + apilado en TODO el rango donde
       .page-layout-grid ya colapsa a 1 columna (≤1024px, ver estilos_globales.css)
       — antes esta regla vivía solo dentro del @media ≤768px de abajo, mientras
       que el grid colapsa desde 1024px y el JS (placeSidebarMobile) también movía
       la tarjeta recién a los 768px. Entre 769px y 1024px (tablet, pantalla ancha
       en horizontal, zoom del navegador) no coincidía NINGUNA de las tres reglas:
       ni la de estilos_globales.css:2924 que lo OCULTA en ≤900px, ni esta que lo
       muestra apilado, ni el JS que lo reubica arriba de la tabla — la tarjeta
       quedaba en su posición de grid por defecto (debajo de la tabla) en vez de
       arriba. Las tres ahora usan el MISMO corte: 1024px.
       margin-bottom evita que la tabla/tarjetas queden pegadas a la caja del
       Consolidado (cliente reporto que se veian "super pegados"). */
    @media (max-width: 1024px) {
        .page-layout-grid .counter-sidebar {
            display: flex !important;
            flex-direction: column !important;
            width: 100% !important;
            position: static !important;
            gap: 10px !important;
            margin-top: 10px !important;
            margin-bottom: 16px !important;
        }
    }

    /* ── Responsive mobile (≤768px) — patron calcado de /admin/equipos ──
       En mobile: titulo OCULTO (el espacio vertical es caro en telefono — el
       usuario ya sabe que esta en el modulo de almacen por la nav); el selector
       de almacen queda full-width como header efectivo. Filtros apilados, boton
       Acciones full-width al final, menu desplegable limitado al viewport. */
    @media (max-width: 768px) {
        /* La barra de selección en móvil muestra TEXTO en vez de iconos (regla global
           en estilos_globales.css:2109). Excepción pedida: el botón "Etiquetas" se
           muestra con su ÍCONO QR (más compacto y reconocible) y sin el texto. El #id
           gana en especificidad sobre la regla global por clase. */
        #almBulkEtqBtn i.material-icons { display: inline-flex !important; }
        #almBulkEtqBtn .desktop-text { display: none !important; }

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

        /* Espacio entre la ultima tarjeta de la tabla y el wrapper "En otros
           almacenes" que se mueve debajo en mobile — sin esto quedaban pegados. */
        #almDistWrapper { margin-top: 16px !important; }
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
        /* Panel "En otros almacenes" en mobile — compacto verticalmente.
           NO usa selector .counter-sidebar como ancestor porque el JS mueve el
           wrapper (#almDistWrapper) fuera de .counter-sidebar a "despues de la
           tabla" (separado del Consolidado). */
        .alm-otros-almacenes h4 { margin-bottom: 6px !important; padding-bottom: 5px !important; font-size: 11px !important; }
        .alm-otros-almacenes h4 .material-icons { font-size: 15px !important; }
        .alm-otros-almacenes ul { max-height: 40vh !important; gap: 2px !important; }
        .alm-otros-almacenes li { padding: 5px 7px !important; }
        .alm-otros-almacenes li > span:first-child { font-size: 11px !important; }
        .alm-otros-almacenes li > span:last-child { font-size: 11px !important; padding: 1px 7px !important; }
        /* Empty state del modo cruzado: mensaje "Este producto solo existe…"
           AHORA SE MUESTRA en mobile (cliente lo pidio explicitamente). Margenes
           comprimidos para no malgastar alto. */
        .alm-otros-almacenes .alm-otros-empty-msg,
        .alm-otros-almacenes > p { margin: 4px 0 0 !important; font-size: 11.5px !important; line-height: 1.35 !important; }
        /* "Distribucion de Inventario" (grafico por categoria, modo default): se
           oculta en mobile. Cliente lo encontro redundante con la columna
           Categoria que cada tarjeta ya muestra. */
        .alm-distribucion-cats { display: none !important; }
        /* Wrapper #almDistWrapper en mobile: hide cuando solo contiene el chart
           (default) o esta vacio. Solo se muestra cuando hay panel "En otros
           almacenes" util (modo cruzado con o sin contenido — el empty state
           ahora sale tambien). Usa :has() — soportado en chromium 105+. */
        #almDistWrapper { display: none !important; }
        #almDistWrapper:has(.alm-otros-almacenes) { display: block !important; padding: 10px 12px !important; }
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
        /* Y reducimos el max-width del modal en mobile para que pegue al viewport
           sin margenes laterales gigantes (alm-modal default era 600px en desktop). */
        #almSalidaModal .alm-modal { max-width: calc(100vw - 16px) !important; width: calc(100vw - 16px) !important; }
        /* Distribucion por categoria: comprimir el panel — h4 + lis mas chicos. */
        .counter-sidebar h4 { font-size: 11px !important; margin-bottom: 8px !important; }
        .counter-sidebar h4 .material-icons { font-size: 15px !important; }
        .counter-sidebar li span { font-size: 9.5px !important; }

        /* ═══════════════════════════════════════════════════════════
           MOBILE CARD LAYOUT — Inventario de Almacén
           Cada <tr.alm-row> es una tarjeta GRID 3-col × 2 filas:
             ┌──────────────────────────────────────────────────────┐
             │ 00042 ABRAZADERA INOXIDABLE 1/2  (banda gris)        │  ← nombre+codigo (full)
             ├──────────────────────────────────────────────────────┤
             │ STOCK 5.000 UND ⚠    [▲ 0 ▼ stepper]      [👁]       │  ← stock | cant | det
             └──────────────────────────────────────────────────────┘

           - Codigo + nombre se renderizan como UN solo texto unificado:
             mismo font, mismo color, mismo peso. El ::before agrega el codigo
             como prefijo del nombre sin separadores especiales.
           - alm-td-codigo OCULTO (su valor se reusa via attr data-codigo).
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
        .alm-table tr.alm-row td.alm-td-codigo,
        .alm-table tr.alm-row td.alm-td-cat { display: none !important; }

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
        .alm-table tr.alm-row td.alm-td-nombre::before {
            content: attr(data-codigo) "  ";
            /* Sin font-family, sin color, sin font-weight: HEREDA del padre →
               el codigo se ve identico al nombre. UN solo texto visual. */
            white-space: pre;
        }

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
        .alm-table tr.alm-row td.alm-td-det {
            grid-area: det !important;
            padding: 8px 14px 8px 4px !important;
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
            <div style="width:280px;min-width:200px;max-width:100%;">
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
                     (toggle en almScanIconToggle, llamado desde filtros()/almBuscarInput).
                     En teléfono abre el modal de cámara; en PC NO abre nada: enfoca este
                     mismo buscador para que el lector USB teclee aquí (almEscanear). --}}
                <i class="material-icons alm-scan-ic" id="almBuscarScan" title="Escanear código QR"
                   style="display:{{ $bActivo ? 'none' : 'flex' }};"
                   onclick="window.almEscanear()">&#xf206;</i>
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

        {{-- Acciones (botón desplegable estilo /admin/equipos) --}}
        <div style="display:flex;gap:8px;margin-left:auto;flex:0 0 auto;align-items:center;">
            <div style="position:relative;">
                <button type="button" id="almBtnAcciones" class="btn-primary-maquinaria"
                        style="height:45px;padding:0 16px;min-width:150px;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);"
                        onclick="window.almToggleAcciones(event)">
                    <i class="material-icons" style="font-size:18px;">settings</i><span class="desktop-text">Acciones</span><i class="material-icons" style="font-size:18px;">expand_more</i>
                </button>
                <div id="almAccionesMenu" style="display:none;position:absolute;top:100%;right:0;width:280px;background:#e2e8f0;border-radius:8px;box-shadow:0 10px 18px -3px rgba(0,0,0,0.18);border:1px solid #e2e8f0;z-index:60;margin-top:6px;overflow:hidden;animation:slideDown 0.18s ease-out;">
                    {{-- Descargar Excel: disponible para cualquier usuario que pueda ver el
                         módulo. Construye la URL de export respetando los filtros de almacén
                         y categoría activos. Como ahora todos los items siguen visibles bajo
                         el de export, el border-bottom se pinta siempre. --}}
                    {{-- Dashboard de Consumo: abre el modal con gráficos (Chart.js). Mismo
                         modal/endpoint que en /admin/almacen/movimientos. --}}
                    <button type="button" onclick="document.getElementById('almAccionesMenu').style.display='none'; window.abrirConsumoDashboard();" class="dropdown-item-custom" style="display:flex;align-items:center;gap:10px;padding:11px 14px;color:#475569;background:transparent;border:none;border-bottom:1px solid #f1f5f9;width:100%;text-align:left;cursor:pointer;">
                        <div style="background:#e0f2fe;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#0067b1;">analytics</i></div>
                        <span style="font-size:14px;font-weight:500;">Dashboard de consumo</span>
                    </button>
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
                    <button type="button" onclick="window.almAccion('producto')" class="dropdown-item-custom" style="display:flex;align-items:center;gap:10px;padding:11px 14px;color:#475569;background:transparent;border:none;border-bottom:1px solid #f1f5f9;width:100%;text-align:left;cursor:pointer;">
                        <div style="background:#e0f2fe;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#0284c7;">add_circle</i></div>
                        <span style="font-size:14px;font-weight:500;">Nuevo producto</span>
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

    {{-- ── Tabla ── --}}
    <div class="alm-table-wrap" style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:12px;">
        <table class="alm-table">
            <thead>
                <tr>
                    <th style="width:68px;padding:10px 8px;">Código</th>
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
                @include('admin.almacen.partials.table_rows', ['productos' => $productos, 'almacen' => $almacenSel, 'inicial' => true])
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

    {{-- Wrapper del panel "En otros almacenes" / "Distribución de Inventario".
         Lleva id="almDistWrapper" porque en mobile el JS lo mueve a DESPUES de
         la tabla (separado del Consolidado, que queda donde esta). El white-box
         se oculta en mobile cuando solo contiene el grafico de categorias o esta
         vacio — solo se ve cuando hay info util de "otros almacenes". --}}
    <div id="almDistWrapper" style="background:white;border-radius:12px;padding:15px;border:1px solid #e2e8f0;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);overflow:hidden;">
        <div id="almDistribucionContainer">
            @if($distribucion && $distribucion->isNotEmpty())
                @include('admin.almacen.partials.distribucion_stats', ['distribucion' => $distribucion])
            @endif
        </div>
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
        <button type="button" onclick="window.almSelAccion()" class="btn-bulk-action" style="background:#16a34a;">
            <i class="material-icons" style="font-size:18px;">north_east</i><span class="desktop-text">Salida</span>
        </button>
        {{-- Etiquetas QR de los productos seleccionados (flujo "marcar filas → imprimir
             sus etiquetas"). Reusa la misma selección (almSeleccion) que la Salida. --}}
        <button type="button" id="almBulkEtqBtn" onclick="window.almSelEtiquetas()" class="btn-bulk-action" style="background:var(--maquinaria-blue,#0067b1);">
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
            <h3><i class="material-icons" style="font-size:20px;color:#7c3aed;">&#xe00a;</i> Generar etiquetas QR</h3>
            <i class="material-icons alm-x" onclick="almCerrar('almEtiquetasModal')">close</i>
        </div>
        <div class="alm-modal-body" style="gap:10px;">
            {{-- Producto(s) a etiquetar, arriba. Con UNO solo se muestra centrado y su cantidad
                 baja a la fila de abajo (junto al formato); con VARIOS, cada uno lleva su propio
                 campo al lado. Lo pinta almAbrirEtiquetas. --}}
            <div id="almEtqModoLista" style="display:none;">
                <div id="almEtqLista" style="max-height:190px;overflow-y:auto;display:flex;flex-direction:column;gap:10px;"></div>
            </div>
            {{-- Fila inferior: cantidad de etiquetas + tamaño de hoja, uno al lado del otro.
                 El campo de cantidad se OCULTA cuando hay varios productos, porque entonces
                 cada uno lleva el suyo arriba (ver almAbrirEtiquetas). --}}
            <div style="display:flex;gap:10px;align-items:center;">
                <input type="number" id="almEtqCopias" class="alm-nota-input" value="1" min="1" max="200" step="1"
                       aria-label="Cantidad de etiquetas" title="Cantidad de etiquetas"
                       style="width:78px;flex:0 0 auto;text-align:center;">
                {{-- Desplegable "Formato": mismo componente custom-dropdown del resto de la app
                     (selectOption escribe en el hidden #almEtqFormato, que lee almEtiquetasGenerar).
                     data-filter-type no engancha filtros: el listener de dropdown-selection solo
                     recarga para almSelAlmacenDropdown.
                     Ya no lleva <label> encima: el propio campo muestra el tamaño elegido
                     ("Carta/A4 — grilla") y va rotulado por aria-label. --}}
                <div class="custom-dropdown" id="almEtqFormatoDropdown" data-filter-type="formato_etq" data-default-label="Carta/A4 — grilla" style="flex:1;min-width:0;">
                    <input type="hidden" id="almEtqFormato" data-filter-value value="carta">
                    <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:#fff;overflow:hidden;border:1px solid #cbd5e0;border-radius:7px;height:38px;">
                        <input type="text" id="almEtqFormatoSearch" data-filter-search autocomplete="off" readonly
                               placeholder="Carta/A4 — grilla" aria-label="Tamaño de hoja"
                               style="flex:1;border:none;background:transparent;padding:0 10px;font-size:13.5px;color:#0f172a;outline:none;min-width:0;cursor:pointer;">
                        <i class="material-icons" style="padding:0 8px;color:#94a3b8;font-size:20px;">expand_more</i>
                    </div>
                    <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                        <div class="dropdown-item-list">
                            <div class="dropdown-item selected" data-value="carta" onclick="selectOption('almEtqFormatoDropdown','carta','Carta/A4 — grilla');">Carta/A4 — grilla</div>
                            <div class="dropdown-item" data-value="50x30" onclick="selectOption('almEtqFormatoDropdown','50x30','Rollo 50 × 30 mm');">Rollo 50 × 30 mm</div>
                            <div class="dropdown-item" data-value="40x25" onclick="selectOption('almEtqFormatoDropdown','40x25','Rollo 40 × 25 mm');">Rollo 40 × 25 mm</div>
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
    {{-- Tarjeta con el MISMO estilo del modal "Limpieza de Roles" de /admin/usuarios:
         header oscuro (#1e293b) con icono ámbar + título centrado, X arriba-derecha,
         cuerpo gris claro. (El overlay .alm-modal-overlay aporta el fondo y el centrado.) --}}
    <div style="background:#fff; border-radius:14px; width:90%; max-width:440px; max-height:85vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); animation:almIn 0.16s ease-out;">
        {{-- Encabezado oscuro --}}
        <div style="background:#1e293b; padding:12px 16px; color:white; display:flex; justify-content:center; align-items:center; position:relative; flex-shrink:0;">
            <div style="display:flex; align-items:center; gap:8px;">
                {{-- Mismo glifo que el item "Papelera de productos" del menú Acciones que abre
                     este modal (delete_outline): si el encabezado usara otro icono parecería
                     otra pantalla. --}}
                <i class="material-icons" style="color:#f59e0b; font-size:18px;">delete_outline</i>
                <h2 style="margin:0; font-size:14px; font-weight:700;">Papelera de productos</h2>
            </div>
            <button type="button" onclick="almCerrar('almPapeleraModal')" style="position:absolute; right:12px; background:transparent; border:none; color:white; cursor:pointer; opacity:0.7; transition:opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'">
                <i class="material-icons" style="font-size:18px;">close</i>
            </button>
        </div>
        {{-- Cuerpo (fondo gris claro, como Limpieza de Roles) --}}
        <div style="display:flex; flex-direction:column; gap:10px; padding:16px; background:#f8fafc; flex:1; min-height:0; overflow:hidden;">
            <div style="display:flex;align-items:center;border:1px solid #cbd5e0;border-radius:8px;background:#fff;overflow:hidden;height:38px;flex-shrink:0;">
                <i class="material-icons" style="padding:0 8px;color:#94a3b8;font-size:18px;">search</i>
                <input type="text" id="almPapeleraSearch" placeholder="Buscar por código o descripción…" autocomplete="off"
                       style="flex:1;border:none;outline:none;padding:0 6px;font-size:13px;background:transparent;height:100%;"
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

{{-- ── Modal: Escanear QR (cámara de teléfono) ──────────────────────────────
     Read-only y SOLO móvil. La cámara (html5-qrcode) arranca en contexto seguro
     (HTTPS/localhost) pidiendo el permiso de una al abrir. Resuelve el CODIGO vía
     almacen.buscar-codigo y filtra la tabla a ese producto. (El input manual se
     quitó; en PC el lector USB sigue resolviendo por el listener global, sin modal.) --}}
<div id="almEscanearModal" class="alm-modal-overlay">
    <div class="alm-modal" style="max-width:340px;">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;color:#7c3aed;">&#xf206;</i> Escanear producto</h3>
            <i class="material-icons alm-x" onclick="window.almEscanearCerrar()">close</i>
        </div>
        <div class="alm-modal-body">
            {{-- Recuadro de cámara: oculto por defecto. almScanIniciarCamara() lo muestra
                 cuando la cámara arranca (teléfono/HTTPS). El modal es SOLO móvil; el
                 escaneo por cámara es la única vía aquí (el input manual se quitó). --}}
            <div id="almScanReader" style="display:none;width:100%;border-radius:10px;overflow:hidden;background:#0f172a;"></div>
            <div id="almScanHint" style="font-size:12px;color:#64748b;text-align:center;"></div>
            {{-- Botón de reintento: aparece SOLO si el arranque/permiso de la cámara falla.
                 En el flujo normal queda oculto (la cámara pide permiso de una al abrir).
                 Tocarlo vuelve a pedir permiso y reintenta EN LA PÁGINA (no abre apps). --}}
            <button type="button" id="almScanActivar" onclick="window.almScanReintentar()"
                    style="display:none;width:100%;padding:11px;border:none;border-radius:8px;background:#7c3aed;color:#fff;font-weight:700;font-size:14px;cursor:pointer;">
                <i class="material-icons" style="font-size:19px;vertical-align:-4px;margin-right:6px;">photo_camera</i>Activar cámara
            </button>
        </div>
    </div>
</div>
{{-- Librería de escaneo por cámara (html5-qrcode, servida LOCALMENTE desde
     public/js/vendor/). Ya NO se carga con un <script> de página: se inyecta BAJO
     DEMANDA al pulsar "Escanear" vía almCargarQrLib() (local → CDN, verificando que
     Html5Qrcode quede definida). Así se evita el caso en que el servidor devuelve el
     index.html en vez del .js (try_files de nginx) sin disparar onerror. Si ni local
     ni CDN cargan, el lector USB y el tecleo manual siguen intactos. Para (re)descargar
     el archivo en el servidor, ver public/js/vendor/README-html5-qrcode.txt. --}}

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
    <div class="alm-modal">
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
            <div style="display:flex;flex-direction:column;align-items:center;gap:2px;background:#e1effa;border:1px solid #bfdbfe;border-radius:8px;padding:8px 12px;margin-bottom:12px;text-align:center;">
                <span style="font-size:12px;color:#475569;font-weight:600;">Saldo actual (sistema)</span>
                <span id="almAjSaldoActual" style="font-size:14px;color:#0067b1;font-weight:800;">—</span>
            </div>
            {{-- El conteo es un número corto: campo angosto y centrado bajo el saldo, en vez
                 de un input a todo el ancho del modal. --}}
            <div style="text-align:center;">
                <label for="almAjNuevoSaldo">Saldo según conteo físico</label>
                <input type="number" id="almAjNuevoSaldo" min="0" step="any" placeholder="Cantidad real contada"
                       style="max-width:200px;text-align:center;">
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
    <div class="alm-modal" style="max-width:420px;">
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
                       aria-label="Stock mínimo (alerta)">
            </div>
            <div id="almMinError" style="display:none;color:#dc2626;font-size:13px;font-weight:600;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="window.almVolverADetalle('almMinimoModal')">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" onclick="window.almGuardarMinimo()">Guardar</button>
        </div>
    </div>
</div>
@endif

{{-- ═════════════════════════════════════════════════════════════════
     Modal: KARDEX por producto (Movimientos del producto)
     Se abre desde el modal de Detalles (botón "Ver movimientos").
     Reusa AlmacenController::movimientos con ?mini=1 y filtra por
     id_producto + id_almacen actual + opcional tipo / desde / hasta.
═════════════════════════════════════════════════════════════════ --}}
<div id="almKardexProductoModal" class="alm-modal-overlay">
    {{-- 540px: sin la columna Fecha la tabla ya no necesita los 680 de antes. --}}
    <div class="alm-modal" style="max-width:540px;">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">history</i> Movimientos del producto</h3>
            <i class="material-icons alm-x" onclick="almCerrar('almKardexProductoModal')">close</i>
        </div>
        <div class="alm-modal-body" style="gap:10px;">
            {{-- Cabecera con info del producto + saldo en el almacén actual.
                 Codigo + nombre se renderizan como UN solo texto (cliente lo pidio):
                 mismo color, mismo peso, mismo font — sin pill separador. El JS
                 (almAbrirKardexProducto) setea almKpCodigo SOLO con el numero
                 (sin "Cód: " prefix) para que se lea natural junto al nombre. --}}
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <strong style="font-size:11.5px;color:#1e293b;flex:1;min-width:140px;font-weight:700;line-height:1.3;">
                    <span id="almKpCodigo">—</span> <span id="almKpNombre"></span>
                </strong>
                <span style="font-size:10.5px;color:#64748b;">Stock actual: <strong id="almKpSaldo" style="color:#0f172a;font-size:11.5px;">0</strong> <span id="almKpUm" style="font-size:10px;color:#64748b;"></span></span>
            </div>

            {{-- Filtros: select Tipo + rango de fechas --}}
            <div class="alm-kp-filtros">
                <div class="alm-kp-grupo-tipo">
                    <span style="font-size:10.5px;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;">Tipo:</span>
                    <select id="almKpTipoSelect" onchange="window.almKpChipSelect(this.value)"
                            style="height:30px;padding:0 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;color:#334155;background:#fff;cursor:pointer;">
                        <option value="">Todos</option>
                        <option value="ENTRADA">Entradas</option>
                        <option value="SALIDA">Salidas</option>
                        <option value="AJUSTE">Auditorías de conteo</option>
                    </select>
                </div>

                {{-- El grupo de fechas es el que cede ancho (.alm-kp-grupo-fechas: flex:1 +
                     min-width:0, hasta las cajas de cada input) para que Tipo y Fechas quepan
                     SIEMPRE en la misma fila dentro de los 540 px del modal. --}}
                <div class="alm-kp-grupo-fechas">
                    <span style="font-size:10.5px;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;">Fechas:</span>
                    <div class="alm-kp-rango">
                        {{-- Wrapper clickable: cualquier click en la caja abre el calendario
                             (focus()+showPicker(), mismo patrón que la fecha de la Nota de
                             Entrega). Antes el onclick iba en el input sin focus() → solo abría
                             al tocar el ícono nativo. --}}
                        <div class="alm-kp-fecha-box" style="display:flex;align-items:center;background:#fff;border:1px solid #e2e8f0;border-radius:6px;height:30px;overflow:hidden;cursor:pointer;"
                             onclick="var i=document.getElementById('almKpDesde'); if(i){ i.focus(); if(i.showPicker){ try{ i.showPicker(); }catch(e){} } }">
                            <input type="date" id="almKpDesde" onchange="window.almKpCargar()"
                                   style="flex:1;width:auto;min-width:0;height:28px;padding:0 6px;border:none;background:transparent;font-size:12px;color:#334155;outline:none;cursor:pointer;">
                        </div>
                        <span style="color:#94a3b8;font-size:14px;">→</span>
                        <div class="alm-kp-fecha-box" style="display:flex;align-items:center;background:#fff;border:1px solid #e2e8f0;border-radius:6px;height:30px;overflow:hidden;cursor:pointer;"
                             onclick="var i=document.getElementById('almKpHasta'); if(i){ i.focus(); if(i.showPicker){ try{ i.showPicker(); }catch(e){} } }">
                            <input type="date" id="almKpHasta" onchange="window.almKpCargar()"
                                   style="flex:1;width:auto;min-width:0;height:28px;padding:0 6px;border:none;background:transparent;font-size:12px;color:#334155;outline:none;cursor:pointer;">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Tabla compacta: 4 columnas (sin Producto, ya conocido; sin Fecha, que el
                 cliente pidió quitar — el rango sigue filtrable arriba). El thead queda
                 sticky para que se vea al hacer scroll.

                 Anchos en PORCENTAJE que suman 100. Antes las tres primeras columnas llevaban
                 width:1% (encoger al contenido), así que quedaban apretadas contra el borde
                 izquierdo y "Destino / Ref" se quedaba con todo el ancho sobrante. Con la
                 columna Fecha fuera, ese reparto dejaba la tabla muy desbalanceada.
                 Sin table-layout:fixed a propósito: los porcentajes mandan mientras el
                 contenido quepa, pero una cantidad larga puede ensanchar su columna en vez
                 de desbordarse (las celdas de Tipo/Cantidad/Stock son white-space:nowrap). --}}
            <div style="overflow:auto;max-height:48vh;border:1px solid #e2e8f0;border-radius:8px;">
                <table style="width:100%;border-collapse:separate;border-spacing:0;">
                    <thead>
                        <tr style="background:#1e293b;color:#fff;position:sticky;top:0;z-index:1;">
                            <th style="width:16%;padding:7px 8px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center;white-space:nowrap;">Tipo</th>
                            <th style="width:24%;padding:7px 8px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center;white-space:nowrap;">Cantidad</th>
                            <th style="width:16%;padding:7px 8px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center;white-space:nowrap;">Stock</th>
                            <th style="width:44%;padding:7px 8px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:center;">Destino / Ref</th>
                        </tr>
                    </thead>
                    <tbody id="almKpBody">
                        <tr><td colspan="4" style="text-align:center;padding:30px;color:#94a3b8;font-size:12px;">Cargando…</td></tr>
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
            <div><label for="almNvNombre">Nombre</label><input type="text" id="almNvNombre" maxlength="150" placeholder="Ej: ALMACÉN CENTRAL CARACAS" autocomplete="off"></div>
            <div>
                <label for="almNvTipoDisplay">Tipo</label>
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
            <div><label for="almNvUbicacion">Ubicación</label><input type="text" id="almNvUbicacion" maxlength="150" placeholder="Opcional" autocomplete="off"></div>
            {{-- Almacenista: nombre del responsable del almacén. Aparecerá como "Entregado por:"
                 en la Nota de Entrega VID-FO-GEN-019. --}}
            <div>
                <label for="almNvAlmacenista">Almacenista</label>
                <input type="text" id="almNvAlmacenista" maxlength="200" placeholder="Ej: Juan Pérez (almacenista)" autocomplete="off">
                <div style="font-size:11.5px;color:#94a3b8;margin-top:5px;">Aparece como "Entregado por:" en la Nota de Entrega.</div>
            </div>
            {{-- Cargo del almacenista: aparece como "CARGO:" debajo del NOMBRE en la
                 seccion "ENTREGADO POR" del PDF. Sustituye al literal "COORD. DE
                 MATERIALES" que antes estaba hardcodeado en el template. --}}
            <div>
                <label for="almNvCargoAlmacenista">Cargo del almacenista</label>
                <input type="text" id="almNvCargoAlmacenista" maxlength="200" placeholder="Ej: COORD. DE MATERIALES" autocomplete="off">
                <div style="font-size:11.5px;color:#94a3b8;margin-top:5px;">Aparece como "CARGO:" debajo del nombre en la Nota de Entrega.</div>
            </div>
            <div id="almNvFrentesWrap">
                <label for="almNvFrentesInput">Frentes que usan este almacén</label>
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
                <div style="font-size:11.5px;color:#94a3b8;margin-top:5px;">Los frentes definen qué usuarios ven este almacén.</div>
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
                {{-- Sin position:relative: solo existía para anclar la lista de sugerencias,
                     que ahora es position:fixed (.alm-suggest-float). --}}
                <div style="flex:0.9;">
                    <label for="almProdUm">Unidad de Medida</label>
                    <input type="text" id="almProdUm" maxlength="20" placeholder="UND, KG, LTS..." value="UND" autocomplete="off"
                           oninput="window.almProdUmSuggest()" onfocus="window.almProdUmSuggest(true)"
                           style="width:100%;box-sizing:border-box;">
                    <div class="alm-suggest-inline alm-suggest-float" id="almProdUmSuggestBox"></div>
                </div>
            </div>
            <div><label for="almProdNombre">Descripción / producto</label><input type="text" id="almProdNombre" maxlength="200" autocomplete="off"></div>
            {{-- Categoría (ancha, con suggest) + Cantidad inicial (angosta, solo al crear).
                 Misma fila usando display:flex — igual patrón que Código + UM más arriba. --}}
            <div style="display:flex;gap:10px;align-items:flex-start;">
                <div style="flex:1.5;">
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
                {{-- Cantidad inicial: solo se muestra al CREAR (no al editar). Si está vacío o en 0
                     el producto queda registrado en el almacén actual con stock 0 (asegurarStock).
                     Si > 0, además se registra una ENTRADA en el kardex como "STOCK INICIAL". --}}
                <div id="almProdCantInicialWrap" style="flex:1;">
                    <label for="almProdCantInicial">Cantidad</label>
                    <input type="number" id="almProdCantInicial" min="0" step="any" placeholder="0 (opcional)" autocomplete="off">
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
    <div class="alm-modal" style="max-width:420px;">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">inventory_2</i> Detalles del producto</h3>
            <i class="material-icons alm-x" onclick="almDetalleCerrar()">close</i>
        </div>
        <div class="alm-modal-body">

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
                <label for="almDetUbicacion" style="font-size:12.5px;font-weight:700;color:#475569;"><i class="material-icons" style="font-size:15px;vertical-align:-3px;margin-right:3px;color:#0067b1;">place</i>Ubicación en estante, fila o nivel</label>
                {{-- max-width: deja aire a los lados sin llegar al borde del modal. Se conserva
                     width:100% para que encoja solo en pantallas angostas. Lo centra el
                     text-align del div padre (el input es inline-block: margin:auto NO lo
                     centraría). --}}
                <input type="text" id="almDetUbicacion" maxlength="150" autocomplete="off"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();window.almGuardarUbicacionDetalle();}"
                       style="width:100%;max-width:320px;min-width:0;margin-top:4px;text-align:center;">
                <div id="almDetUbicacionError" style="display:none;color:#dc2626;font-size:12px;font-weight:600;margin-top:4px;"></div>
            </div>

            {{-- Compatibilidad del filtro: nº de parte (equivalencias) + equipos que lo usan.
                 Se carga al abrir el detalle (almAbrirDetalle → fetch a productoCompatibilidad).
                 Se oculta si el producto no tiene ni equivalencias ni equipos. --}}
            <div id="almDetCompat" style="display:none;border-top:1px solid #f1f5f9;padding-top:12px;">
                <div id="almDetPartesWrap" style="display:none;margin-bottom:10px;">
                    <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;">Nº de parte / equivalencias</div>
                    <div id="almDetPartes" style="display:flex;flex-wrap:wrap;gap:5px;"></div>
                </div>
                <div id="almDetEquiposWrap" style="display:none;">
                    <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;display:flex;align-items:center;gap:6px;"><i class="material-icons" style="font-size:16px;color:#0067b1;">precision_manufacturing</i> Equipos que lo usan <span id="almDetEquiposCount" style="color:#94a3b8;font-weight:700;"></span></div>
                    <div id="almDetEquipos" style="display:flex;flex-direction:column;gap:4px;max-height:180px;overflow-y:auto;"></div>
                </div>
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

@if($puedeMover)
{{-- ── Salida: un solo formulario unificado. Siempre llena la Nota de Entrega VID-FO-GEN-019
     (proyecto + contrato + fecha + RQ + solicitante + dpto). El backend decide si la salida
     es CONSUMO (mismo almacén del origen) o TRASPASO (envío a otro almacén) según el frente
     elegido en "Proyecto destino" — ambos casos generan Nota de Entrega NE-YYYY-NNNN. ── --}}
<div id="almSalidaModal" class="alm-modal-overlay">
    {{-- max-width 660px — el cliente pidió agrandarlo un poco (venía de 600).
         El layout de 2-3 columnas (Proyecto/Contrato, Fecha/RQ/Solic) sigue
         acomodando bien — los inputs heredan width:100% del .alm-nota-input. --}}
    <div class="alm-modal alm-modal-wide" style="max-width:660px;">
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
                <div class="alm-modal-grid alm-modal-grid-2" style="display:grid;grid-template-columns:2fr 1fr;gap:10px;margin-bottom:10px;align-items:start;">
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

                {{-- FECHA DE ENTREGA | RQ N° | Solicitante (3 columnas en una sola fila — como en el Excel) --}}
                <div class="alm-modal-grid alm-modal-grid-3" style="display:grid;grid-template-columns:1fr 1fr 1.4fr;gap:10px;margin-bottom:10px;">
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
            <h3><i class="material-icons" style="font-size:20px;color:#0067b1;">visibility</i> <span>Vista previa de la Nota de Entrega</span></h3>
            <i class="material-icons alm-x" onclick="window.almPreviewCerrar()">close</i>
        </div>
        <div class="alm-modal-body" style="padding:0;gap:0;background:#475569;">
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
(function () {
    'use strict';
    // Guard: si el módulo se re-monta (navegación SPA) no re-bindear listeners
    // de documento; las funciones window.alm* del primer montaje siguen válidas.
    if (window.__almIndexInit) {
        // Re-montaje SPA: el cuerpo del IIFE NO vuelve a correr, así que las variables del
        // closure del montaje anterior siguen vivas (selección fantasma que infla el contador,
        // pick pegado, "ver todo" activo…). Reseteamos TODO el estado contra el DOM nuevo.
        if (typeof window.almResetOnRemount === 'function') window.almResetOnRemount();
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
    var HAS_NOTA_ELIMINAR = @json($puedeEliminar);
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
    // Catálogo de productos (CODIGO/NOMBRE/UM/PARTE) — lo usan el buscador FuzzySearch y los
    // selects de los modales. ANTES se embebía inline aquí (~500 KB de los 1155 productos) y el
    // módulo abría lento. AHORA arranca vacío y se carga por AJAX apenas la página queda lista
    // (no bloquea el render → abre de una). El buscador "tipear + Enter" del servidor sigue como
    // fallback mientras carga. La sincronización al crear/editar producto (más abajo) opera sobre
    // esta misma lista una vez cargada.
    window.almProductosLista = [];
    window.almProductosCargados = false;
    window.almCargarProductos = function () {
        if (window.almProductosCargados || window._almProductosCargando) return Promise.resolve();
        window._almProductosCargando = true;
        return fetch(@json(route('almacen.productos-autocomplete')), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(function (lista) {
            window.almProductosLista = Array.isArray(lista) ? lista : [];
            window.almProductosCargados = true;
        })
        .catch(function () { /* silencioso: el buscador tipear+Enter del servidor sigue como fallback */ })
        .finally(function () { window._almProductosCargando = false; });
    };
    window.almCargarProductos();
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
    // "Ver todo el stock" (acción explícita): la tabla arranca VACÍA y solo muestra
    // inventario cuando hay un filtro. "Ver todo" es la ÚNICA forma de pedir TODO sin
    // filtro, así que manda ver_todo=1. Lo enciende almVerTodo(); cualquier otra recarga
    // (sin opts.verTodo) lo apaga; la auto-carga (append) lo conserva.
    var almVerTodoActivo = false;
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
    // almBuscarPickedIds: CSV de IDs de presentaciones cuando se clickea una sugerencia
    // AGRUPADA (misma descripcion, varias UM). Manda id_producto_in → el backend devuelve
    // EXACTAMENTE esas presentaciones, no substrings (a diferencia del LIKE de `search`).
    var almBuscarPickedIds = null;
    // Auto-seleccion en el primer render: si llegamos por URL con ?id_producto=NNN,
    // marcamos la fila como si el usuario la hubiera clickeado (resaltado azul +
    // entrada en almSeleccion). Asi el usuario llega listo para escribir cantidad
    // y abrir la Nota de Entrega — sin el paso extra de "clic en la fila".
    // Solo dispara UNA VEZ: tras el primer almSelApplyToVisible se pone en false
    // para que clicks de deseleccion posteriores no se "deshagan" al recargar el tbody.
    var _almPendingAutoSelect = (almBuscarPickedId != null);

    // ID de producto cuyo input de cantidad debe recibir el foco tras la PRÓXIMA recarga del
    // tbody (lo consume almSelApplyToVisible una sola vez). Lo usa la Auditoría: al Guardar se
    // recarga la tabla, la fila sigue seleccionada, y así el teclado queda listo en el input
    // de cantidad sin tener que deseleccionar/reseleccionar. null = sin foco pendiente.
    var _almPendingFocusId = null;

    // Descarta el "pick" de producto (match EXACTO id_producto / id_producto_in de una
    // sugerencia). Punto ÚNICO de reset: lo llaman tanto los helpers del buscador (al
    // reteclear / limpiar) como los atajos del sidebar y los badges (categoría, "Con
    // stock", "Stock bajo", "Ver todo"). Sin esto el pick quedaba PEGADO y como el backend
    // prioriza id_producto(_in) sobre categoría/stock, esos atajos encendían el badge pero
    // NO cambiaban la tabla (seguía mostrando solo lo picado). Fuente única, sin duplicar.
    function almResetPick() {
        almBuscarPickedId = null;
        almBuscarPickedIds = null;
    }

    // Criterio ÚNICO "es filtro": la categoría CONTIENE 'FILTRO' (así "FILTROS DE ACEITE" o
    // "FILTRO DE AIRE" también cuentan), coherente con el backend (updateProducto). Definidos en
    // el scope del IIFE (no dentro de una función) para que los compartan el buscador, la sección
    // de equivalencias del modal y el guardado — un solo punto de verdad, sin duplicar.
    var esCatFiltro = function (cat) { return String(cat || '').toUpperCase().indexOf('FILTRO') !== -1; };
    var esFiltroCat = function (x) { return esCatFiltro(x && x.CATEGORIA); };
    // Construye una entry del autocomplete con la MISMA forma que listaAutocomplete (backend):
    // ID_PRODUCTO, CODIGO, NOMBRE, UM, CATEGORIA, EQUIV, PARTE, PARTES. Punto ÚNICO para que el
    // producto creado/editado/restaurado se cachee completo — antes solo se guardaba
    // {ID,CODIGO,NOMBRE,UM} y tras editar dejaba de reconocerse como filtro / por nº de parte
    // hasta recargar. `equivs` (opcional) = nºs de parte conocidos en el cliente (los del modal).
    function almProdEntry(p, equivs) {
        var parts = Array.isArray(equivs) ? equivs.slice()
                  : (Array.isArray(p.PARTES) ? p.PARTES.slice() : []);
        return {
            ID_PRODUCTO: p.ID_PRODUCTO,
            CODIGO:      p.CODIGO,
            NOMBRE:      p.NOMBRE,
            UM:          p.UM,
            CATEGORIA:   p.CATEGORIA || '',
            EQUIV:       parts.join(' '),
            PARTE:       parts[0] || '',
            PARTES:      parts
        };
    }

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
        // Clic en sugerencia AGRUPADA (varias presentaciones): mandamos los IDs exactos como
        // id_producto_in → el backend devuelve SOLO esas presentaciones (no substrings del LIKE).
        else if (almBuscarPickedIds) p.set('id_producto_in', almBuscarPickedIds);
        var cat = valActive('almFiltroCat'); if (cat) p.set('categoria', cat);
        if (soloBajo)                   p.set('solo_bajo', '1');
        if (soloConSaldo)               p.set('solo_con_saldo', '1');
        if (almVerTodoActivo)           p.set('ver_todo', '1'); // "Ver todo el stock" explícito
        // "Ver solo seleccionados" (bulk counter clickado): manda los IDs como CSV. El
        // backend hace whitelist por estos IDs e IGNORA search/categoria/solo_bajo —
        // asi el usuario ve TODOS sus seleccionados, incluso si los otros filtros los
        // habian excluido de la vista cuando seleccionaba. Solo se manda si hay algo
        // seleccionado (si no, el backend caeria al modo normal sin filtro).
        // (Si vino de un clic en sugerencia agrupada ya pusimos id_producto_in arriba; el
        // bulk "solo seleccionados" no debe pisarlo.)
        if (!almBuscarPickedIds && almSoloSel && typeof almSelCount === 'function' && almSelCount() > 0) {
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
        window.almScanIconToggle();   // escanear visible solo si el buscador quedó vacío
        return p;
    }

    // ── Carga AJAX de la tabla + sidebar — con SCROLL INFINITO PEREZOSO ──────────
    // almCargar(opts?) acepta { offset, append, gen }:
    //   • Sin args (o offset=0)    → reemplaza la tabla, refresca stats + distribución
    //                                y actualiza la URL para compartir.
    //   • { offset>0, append }     → trae la siguiente página y la appendea al tbody.
    // El siguiente lote NO se auto-encadena: lo dispara un IntersectionObserver sobre la
    // última fila cuando el usuario se acerca (mismo patrón que /admin/equipos). Antes se
    // encadenaban TODAS las páginas de golpe, lo que causaba el lag al llegar al final y
    // que el navegador quedara congelado al volver de otra pestaña (los lotes pendientes
    // se procesaban todos juntos). El observer no dispara con la pestaña oculta.
    //
    // Generación de carga: cada recarga completa (offset 0) la incrementa. Cada lote lleva
    // su generación; si el usuario filtra/recarga mientras baja el resto, los lotes viejos
    // se descartan (no pintan datos obsoletos ni siguen trayendo).
    var almLoadGen = 0;
    var almFiltrosVigentes = null; // filtros congelados de la carga fresca; los append los reusan
    window.almCargar = function (opts) {
        // back-compat: si llaman almCargar() sin args o almCargar('url-string') se trata
        // como recarga completa (offset=0). Si se pasa un objeto, respetamos sus opciones.
        if (typeof opts === 'string' || opts == null) opts = {};
        var offset = Math.max(0, parseInt(opts.offset || 0, 10));
        var append = !!opts.append && offset > 0;
        // ver_todo solo lo activa almVerTodo({verTodo:true}); cualquier otra recarga lo
        // apaga. En la auto-carga (append) NO se toca, para conservar "ver todo".
        if (!append) almVerTodoActivo = !!opts.verTodo;
        var body = el('almTableBody'); if (!body) return;
        var loadMore = el('almLoadingMore');

        // Generación: una recarga completa invalida la cadena de auto-carga en vuelo;
        // cada append hereda la suya y se aborta si ya cambió (ver comentario arriba).
        var gen;
        if (!append) {
            gen = ++almLoadGen;
        } else {
            gen = (typeof opts.gen === 'number') ? opts.gen : almLoadGen;
            if (gen !== almLoadGen) return; // una recarga nueva ya reemplazó esta cadena
        }

        // Construir URL preservando los filtros activos + offset.
        // En una carga fresca (!append) calculamos los filtros con filtros() —que TIENE efectos
        // secundarios (valActive vacía el input y lo promueve a data-active)— y CONGELAMOS el
        // resultado. En los append del scroll infinito NO volvemos a llamar filtros(): reusamos
        // los congelados. Antes filtros() corría en cada append y, si un lote llegaba mientras
        // el usuario tecleaba, le borraba lo escrito; además garantiza que la paginación use
        // exactamente los mismos filtros que la carga inicial.
        var f;
        if (!append) {
            f = filtros();
            almFiltrosVigentes = f.toString();
        } else {
            f = new URLSearchParams(almFiltrosVigentes || '');
        }
        f.set('offset', String(offset));
        var finalUrl = ROUTE_INDEX + '?' + f.toString();
        if (append) {
            if (loadMore) loadMore.style.display = 'block';
        } else {
            body.style.opacity = '0.5';
            pre();
        }
        fetch(finalUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                // Si una recarga nueva cambió la generación mientras volaba este fetch,
                // descartamos el resultado para no pintar datos obsoletos.
                if (gen !== almLoadGen) return;
                if (data.html !== undefined) {
                    if (append) {
                        var tmp = document.createElement('tbody');
                        tmp.innerHTML = data.html;
                        var _nuevasRows = [];
                        while (tmp.firstElementChild) {
                            var _r = tmp.firstElementChild;
                            body.appendChild(_r);
                            if (_r.nodeType === 1 && _r.classList.contains('alm-row')) _nuevasRows.push(_r);
                        }
                        // SOLO las filas nuevas (no re-itera todo el tbody en cada lote → evita el freeze).
                        almSelApplyToRows(_nuevasRows);
                    } else {
                        body.innerHTML = data.html;
                        almSelApplyToVisible();
                    }
                }
                // Stats + distribución solo en la primera página (el backend ya las omite
                // cuando offset>0; aquí evitamos rebajar a "—" lo que ya pintamos).
                if (!append && data.stats) {
                    var num = function (id, v) {
                        var e = el(id); if (!e) return;
                        // KPIs (conteos): miles con punto (formato latino). '—' cuando no hay valor.
                        var f = parseFloat(v);
                        e.textContent = (v == null) ? '—' : (isNaN(f) ? v : f.toLocaleString('es-ES'));
                    };
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
                            // id_producto_in (selección efímera) y ver_todo (acción transitoria)
                            // NO van a la URL: no se honran al recargar y solo confundirían.
                            if (k === 'id_producto_in' || k === 'ver_todo') return;
                            cleanU.searchParams.set(k, v);
                        });
                        window.history.replaceState({}, '', cleanU.toString());
                    } catch (e) {}
                }
                // Scroll infinito PEREZOSO (mismo patrón que /admin/equipos): en vez de
                // auto-encadenar TODAS las páginas de golpe (causaba el lag al llegar al final
                // y el congelamiento del navegador al volver de otra pestaña), observamos la
                // ÚLTIMA fila y traemos el siguiente lote SOLO cuando el usuario se acerca.
                // El IntersectionObserver NO dispara con la pestaña oculta → al volver no se
                // acumula un atasco de lotes pendientes.
                if (data.hasMore && typeof data.nextOffset === 'number') {
                    var _rows = body.querySelectorAll('tr.alm-row');
                    var lastRow = _rows.length ? _rows[_rows.length - 1] : null;
                    if (lastRow && !lastRow.dataset.infObserved) {
                        lastRow.dataset.infObserved = '1';
                        var _nextOffset = data.nextOffset, _gen = gen;
                        var infObs = new IntersectionObserver(function (entries, obs) {
                            if (!entries[0] || !entries[0].isIntersecting) return;
                            obs.disconnect();
                            if (_gen !== almLoadGen) return; // una recarga nueva ya reemplazó esta lista
                            window.almCargar({ offset: _nextOffset, append: true, gen: _gen });
                        }, { root: null, rootMargin: '1000px', threshold: 0 });
                        infObs.observe(lastRow);
                    }
                }
            })
            .catch(function () { toast('No se pudo cargar el inventario.', 'error'); })
            .finally(function () {
                if (append) {
                    // Carga perezosa: el spinner de "cargando más" se oculta al terminar cada lote
                    // (el siguiente lo dispara el IntersectionObserver al acercarse a la última fila).
                    if (loadMore) loadMore.style.display = 'none';
                } else {
                    body.style.opacity = '1'; unpre();
                }
            });
    };

    function formatNum(n) {
        n = parseFloat(n || 0);
        if (isNaN(n)) return '0';
        // Formato latino: miles con punto, decimal con coma, hasta 3 decimales sin ceros sobrantes.
        return n.toLocaleString('es-ES', { maximumFractionDigits: 3 });
    }

    // ── helpers desde el sidebar / distribución ──
    // Limpia buscar + categoría (mismo bloque que antes vivía inline solo en almVerTodo).
    // Punto ÚNICO: lo reutilizan almVerTodo y los badges "Con stock"/"Stock bajo" al
    // ENCENDERSE — encender un atajo global es una acción explícita para ver ESA vista,
    // igual que "Ver todo", así que no debe quedar intersectada en silencio con una
    // búsqueda/categoría que hubiera quedado activa de antes (misma causa que arregló
    // almResetBadges, pero en la dirección inversa: aquí el atajo es la acción nueva y
    // la búsqueda es la que quedaba pegada).
    function almLimpiarBusquedaYCategoria() {
        var bi = el('almFiltroBuscar');
        if (bi) { bi.value = ''; bi.dataset.active = ''; bi.placeholder = bi.dataset.placeholderEmpty || 'Buscar por código o descripción…'; }
        var ci = el('almFiltroCat');
        if (ci) { ci.value = ''; ci.dataset.active = ''; ci.placeholder = ci.dataset.placeholderEmpty || 'Filtrar por categoría…'; }
        almSuggestHide(); almCatSuggestHide();
    }
    window.almVerTodo = function () {
        almLimpiarBusquedaYCategoria();
        soloBajo = false; soloConSaldo = false;
        almPintarBadges();
        almResetPick(); // descartar match exacto (id_producto/_in) si quedó pegado de un clic previo
        almCargar({ verTodo: true }); // acción explícita: mostrar TODO el inventario del almacén
    };
    window.almFilterByCategoria = function (cat) { var s = el('almFiltroCat'); if (s) { s.value = cat || ''; } almResetPick(); almResetBadges(); almCatSuggestHide(); almCargar(); };
    // Los dos badges del header son TOGGLES: clic con el mismo filtro activo lo apaga.
    // Clic en uno mientras el otro estaba encendido los hace mutuamente exclusivos.
    // En cualquier caso, almPintarBadges() refleja el estado para que el usuario VEA
    // cual filtro esta limitando la tabla (anillo blanco + fondo saturado en .is-on).
    // `force`=true la ENCIENDE siempre (no togglea): lo usa la sugerencia "VER TODO
    // EL STOCK" del buscador, que debe mostrar SOLO los productos con existencias (>0).
    window.almFiltrarConSaldo = function (force) {
        soloConSaldo = force ? true : !soloConSaldo;
        if (soloConSaldo) { soloBajo = false; almLimpiarBusquedaYCategoria(); }
        almResetPick(); // un badge global no debe quedar anulado por un pick exacto pegado
        almPintarBadges(); almCargar();
    };
    window.almFiltrarBajo = function () {
        soloBajo = !soloBajo;
        if (soloBajo) { soloConSaldo = false; almLimpiarBusquedaYCategoria(); }
        almResetPick(); // un badge global no debe quedar anulado por un pick exacto pegado
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
    // Enfocar "Buscar" o "Categoría" SUELTA los atajos globales del Consolidado
    // ("Stock bajo" / "Con stock"). Son vistas globales excluyentes con filtrar por texto o
    // categoría: con un atajo pegado, el producto buscado no aparecía si no calificaba para
    // él (p. ej. buscar por descripción con "Stock bajo" encendido no lo encontraba).
    // Los puntos que APLICAN el filtro ya llamaban a almResetBadges() (almBuscarEnter,
    // almBuscarPick, almCatEnter, almCatPick, almFilterByCategoria); esto lo adelanta al
    // FOCO, para que el badge no siga encendido mientras se escribe.
    // Punto ÚNICO de los dos campos: la lógica no se repite en cada onfocus.
    // Recarga solo si de verdad había un atajo activo — si no, un clic en el campo
    // dispararía una petición inútil.
    function almSoltarAtajosStock() {
        if (!soloBajo && !soloConSaldo) return;
        almResetBadges();
        almCargar();
    }
    // Reset COMPLETO del estado del módulo — lo llama el guard cuando la vista se re-monta por
    // navegación SPA. Reúne las piezas que ya limpian cada cosa (badges + pick + selección) para
    // no duplicar lógica; almSelClear vacía almSeleccion/almSoloSel y refresca la barra flotante.
    window.almResetOnRemount = function () {
        window.almResetBadges();
        if (typeof almResetPick === 'function') almResetPick();
        almVerTodoActivo = false;
        if (typeof window.almSelClear === 'function') window.almSelClear();
    };
    // Pintar al inicio para reflejar el estado leido de la URL.
    almPintarBadges();

    // ── Autocompletado del filtro "Buscar" (código o descripción), con el look de los desplegables de la app ──
    // Normalizacion (sin acentos + minusculas): delega en el modulo compartido FuzzySearch.
    function almNorm(s) { return window.FuzzySearch.norm(s); }
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
        almSuggestAnclar(box);
    }
    // Coloca una lista .alm-suggest-float (position:fixed) justo debajo de su campo.
    // Solo actúa sobre esas: las sugerencias de la barra de filtros son absolute normales
    // y no necesitan anclaje. Si no cabe debajo, se abre hacia arriba.
    function almSuggestAnclar(box) {
        if (!box || !box.classList.contains('alm-suggest-float') || !box.classList.contains('open')) return;
        var campo = box.parentElement; if (!campo) return;
        var r = campo.getBoundingClientRect();
        box.style.left  = r.left + 'px';
        box.style.width = r.width + 'px';   // el ancho se fija ANTES de medir el alto
        var alto = box.offsetHeight;
        var cabeAbajo = (window.innerHeight - r.bottom - 8) >= alto;
        box.style.top = (!cabeAbajo && r.top > alto ? (r.top - alto - 2) : (r.bottom + 2)) + 'px';
    }
    // Al ser fixed, la lista no sigue sola a su campo: se reancla si la ventana cambia de
    // tamaño o si algo se desplaza (el cuerpo del modal, con scroll en captura porque el
    // evento scroll de un elemento no burbujea).
    function almSuggestReanclar() {
        document.querySelectorAll('.alm-suggest-float.open').forEach(almSuggestAnclar);
    }
    window.addEventListener('resize', almSuggestReanclar);
    document.addEventListener('scroll', almSuggestReanclar, true);
    // ── Buscador "estilo Google" — fuzzy + ranking por relevancia ─────────────
    //   El algoritmo (normaliza, tokeniza, tolera typos por Levenshtein y rankea por
    //   relevancia) vive en el módulo compartido window.FuzzySearch
    //   (public/js/maquinaria/fuzzy_search.js, cargado global en el layout base → SPA-safe),
    //   reutilizado también por Recepción. Aquí solo queda el alias del tokenizado (lo
    //   usa almBuscarSuggest para el link "VER TODO"); el ranking se hace con
    //   FuzzySearch.rank. Mismo criterio reflejado en el backend (AlmacenController::index)
    //   para el fallback de "tipear + Enter".
    function almTokenizar(raw) { return window.FuzzySearch.tokenize(raw); }

    window.almBuscarSuggest = function () {
        almCatSuggestHide();
        var inp = el('almFiltroBuscar'), box = el('almFiltroBuscarSuggest');
        if (!inp || !box) return;
        var rawTerm = inp.value.trim();
        var tokens = almTokenizar(rawTerm);
        var rawNorm = almNorm(rawTerm).replace(/\s+/g, ' ');
        var lista = window.almProductosLista || [];

        // "VER TODO EL STOCK" se comporta como una recomendación más de la
        // lista, igual que "TODOS LOS FRENTES" en el filtro de /admin/equipos:
        // sale con el campo vacío y, al escribir, solo si el texto coincide con
        // ella (substring). Al clickearla filtra a "Con stock" (solo con existencias >0).
        var verTodoLink = (tokens.length === 0 || (rawNorm && 'ver todo el stock'.indexOf(rawNorm) !== -1))
            ? '<div class="alm-suggest-item" data-action="ver-todo"><span class="nom">VER TODO EL STOCK</span></div>'
            : '';


        // Categoría ACTIVA (la que filtra la tabla). Lectura NO mutante: vive en data-active
        // tras un almCatPick; si el usuario tipeó pero no aplicó, cae al value. Sirve para
        // avisar cuando un material existe pero pertenece a otra categoría (badge + toast).
        var catActiva = (function () { var e = el('almFiltroCat'); if (!e) return ''; return String(e.dataset.active || e.value || '').trim(); })();
        var catActivaNorm = almNorm(catActiva);
        // Mismo criterio que el backend (CATEGORIA LIKE %cat%): "pertenece" = la categoría del
        // producto CONTIENE el texto filtrado (normalizado). Sin filtro → todo pertenece.
        function perteneceACat(catProd) {
            if (!catActivaNorm) return true;
            return almNorm(catProd || '').indexOf(catActivaNorm) !== -1;
        }

        // Agrupacion por DESCRIPCION: desde que Recepcion permite la misma descripcion en
        // varias presentaciones (distinta UM = producto aparte), el catalogo puede tener N
        // productos con identico NOMBRE. Para que el buscador no repita la misma descripcion,
        // contamos cuantas presentaciones tiene cada nombre y si ALGUNA esta en el almacen
        // actual. En la sugerencia se muestra UNA sola entrada por nombre; al clickearla, si
        // tiene >1 presentacion se busca por texto (LIKE) para que la tabla liste TODAS; si es
        // unica se fija id_producto (match exacto, como antes).
        var gruposNombre = {};
        for (var gI = 0; gI < lista.length; gI++) {
            var gKey = almNorm(lista[gI].NOMBRE || '');
            if (!gruposNombre[gKey]) gruposNombre[gKey] = { count: 0, ids: [], enCat: false };
            gruposNombre[gKey].count++;
            gruposNombre[gKey].ids.push(lista[gI].ID_PRODUCTO);
            // enCat = ALGUNA presentación del grupo pertenece a la categoría filtrada
            // (las presentaciones suelen compartir categoría). Filtra las sugerencias.
            if (perteneceACat(lista[gI].CATEGORIA)) gruposNombre[gKey].enCat = true;
        }

        // Recorremos la lista una vez y recogemos TODOS los matches del catalogo (esten o no
        // en este almacen). Razon (pedido del cliente 2026-05-19): si un producto existe en el
        // sistema, debe SIEMPRE aparecer en la sugerencia — sino la gente cree que no esta
        // registrado y crea duplicados. Los que no tienen fila en almacen_stock del almacen
        // actual se marcan con un badge "sin stock aquí" pero IGUAL se pueden clickear.
        // Con la invariante de storeProducto (asegurarStock para todos los almacenes activos)
        // este caso debería ser raro, pero es defensa en profundidad por si un almacén nuevo
        // se crea después de un producto o por importaciones legacy.
        var matches = [];
        // vistosNom: dedupe por nombre normalizado — una sola entrada por descripcion.
        var vistosNom = {};
        // Ranking robusto compartido (window.FuzzySearch): término vacío → catálogo en su
        // orden natural (NOMBRE); con término → mejores por relevancia (fuzzy + score).
        // Sobre el resultado aplicamos el dedupe por descripción (una entrada por nombre,
        // con badge de N presentaciones) y el filtro por categoría activa, hasta 17.
        var ranked = window.FuzzySearch.rank(lista, rawTerm, function (p) {
            // haystack incluye EQUIV (números de parte equivalentes) para que teclear un
            // alterno (p.ej. "MIS0531" / "1000FG" / "LF-3977") sugiera el filtro, aunque su
            // código/nombre no lo contenga. label sigue siendo solo el NOMBRE (lo que se ve).
            return { haystack: (p.CODIGO || '') + ' ' + (p.NOMBRE || '') + ' ' + (p.EQUIV || ''), label: p.NOMBRE || '' };
        });
        // ¿El producto es un FILTRO? Los filtros del mismo nombre son MODELOS DISTINTOS (distinto
        // nº de parte), NO "presentaciones" → no se agrupan por descripción: cada uno es su propia
        // sugerencia. Se usa esFiltroCat/esCatFiltro (definidos en el scope del IIFE, ver arriba)
        // para que el dedupe y el render compartan el MISMO criterio con el resto del módulo.
        for (var s = 0; s < ranked.length && matches.length < 17; s++) {
            var rp = ranked[s];
            var esFiltro = esFiltroCat(rp);
            var kS = esFiltro ? ('__f' + rp.ID_PRODUCTO) : almNorm(rp.NOMBRE || '');
            if (vistosNom[kS]) continue;
            var enCat = esFiltro
                ? perteneceACat(rp.CATEGORIA)
                : (gruposNombre[almNorm(rp.NOMBRE || '')] && gruposNombre[almNorm(rp.NOMBRE || '')].enCat);
            if (catActivaNorm && !enCat) continue;
            vistosNom[kS] = true;
            matches.push(rp);
        }

        if (!matches.length) {
            // Si el catálogo async aún no cargó, mostramos "Cargando…" en vez de "Sin
            // coincidencias" (que sugeriría por error que el producto no existe → riesgo de
            // crear duplicados). El fallback "teclear + Enter" contra el servidor sigue vivo.
            var vacioHtml = (!window.almProductosCargados)
                ? '<div class="alm-suggest-empty">Cargando productos…</div>'
                : '<div class="alm-suggest-empty">Sin coincidencias.</div>';
            box.innerHTML = verTodoLink + vacioHtml;
        } else {
            // Mostrar SOLO el NOMBRE; data-pick guarda el texto que va al cuadro al elegir: el
            // NOMBRE y —en filtros que matchearon por nº de parte— ese nº DELANTE del nombre, para
            // que se vea CUÁL equivalencia buscaste. Escribir encima del texto pegado sigue dando
            // coincidencias via LIKE %term% del backend (tokeniza y matchea nº de parte + nombre).
            // (El badge "sin stock aquí" se retiró a pedido del cliente.) Los filtros muestran su
            // número de parte delante del nombre y NO se agrupan (cada uno es un modelo distinto).
            var html = verTodoLink + matches.map(function (p) {
                var nom = (p.NOMBRE || '').replace(/[<>&"]/g, '');
                var cod = (p.CODIGO || '').replace(/[<>&"]/g, '');
                var esFiltroP = esFiltroCat(p);
                var grp = gruposNombre[almNorm(p.NOMBRE || '')] || { count: 1, ids: [p.ID_PRODUCTO], enCat: perteneceACat(p.CATEGORIA) };
                // Filtros NUNCA son "multi": cada uno es un modelo aparte, no una presentación.
                var multi = !esFiltroP && grp.count > 1;
                // Clic en la sugerencia:
                //  - Descripcion UNICA → data-pid = id exacto (match de 1 producto).
                //  - VARIAS presentaciones → data-pids = CSV de los IDs de ESAS presentaciones;
                //    el clic manda id_producto_in y la tabla muestra EXACTAMENTE esas (no
                //    substrings: "ABRAZADERA" no debe arrastrar "ABRAZADERA 5\" PARA MANGUERA…").
                var pid  = multi ? '' : (p.ID_PRODUCTO || '');
                var pids = multi ? (grp.ids || []).join(',') : '';
                // El codigo/serial NO se muestra en la lista (pedido cliente): la sugerencia
                // queda limpia con SOLO la descripcion. Igual se PUEDE buscar por serial (el
                // scoring del autocomplete y el backend matchean CODIGO) y el serial sigue en
                // el title de la fila (hover). Cuando una descripcion tiene varias
                // presentaciones se muestra un ICONO compacto (layers) + el numero, en
                // vez del texto "N pres." (robaba ancho a la descripcion). El detalle
                // completo queda en el tooltip.
                var rightBadge = multi
                    ? '<span class="alm-suggest-cod" style="color:#0067b1;display:inline-flex;align-items:center;gap:1px;" title="' + grp.count + ' presentaciones (distintas unidades)"><i class="material-icons" style="font-size:15px;line-height:1;">layers</i><span style="font-size:10.5px;font-weight:700;line-height:1;">' + grp.count + '</span></span>'
                    : '';
                // Filtros: el nº de parte va DELANTE del tipo. Se muestra la EQUIVALENCIA que
                // COINCIDE con lo buscado (si buscas "AL-7723" sale ese, no la principal) —
                // helper compartido de FuzzySearch (misma lógica que movimientos/recepción).
                var parteMostrar = window.FuzzySearch.matchedPart(rawTerm, p.PARTES, p.PARTE);
                // Mismo tipo de letra/color/tamaño que la descripción (.nom): 13.5px, #475569,
                // peso 600 — para que el nº de parte se lea igual que el tipo, no más apagado.
                var parteSafe   = parteMostrar ? String(parteMostrar).replace(/[<>&"]/g, '') : '';
                var partePrefix = parteSafe
                    ? '<span class="alm-suggest-parte" style="font-size:13.5px;color:#475569;font-weight:600;margin-right:7px;white-space:nowrap;">' + parteSafe + '</span>'
                    : '';
                // Texto que queda en el cuadro al elegir: si la sugerencia matcheó por nº de parte
                // (equivalencia, p.ej. "P164378"), ese nº va DELANTE de la descripción para que se
                // vea CUÁL equivalencia buscaste — no solo la descripción. El filtrado real sigue
                // usando id_producto (match exacto), así que este texto es solo lo que se muestra.
                var pickText    = parteSafe ? (parteSafe + ' · ' + nom) : nom;
                return '<div class="alm-suggest-item" data-pid="' + pid + '" data-pids="' + pids + '" data-pick="' + pickText + '" title="' + cod + '">'
                     {{-- nº de parte (si es filtro) + nom; el badge de presentaciones va a la derecha. --}}
                     + '<div class="alm-suggest-line">' + partePrefix + '<span class="nom">' + nom + '</span>' + rightBadge + '</div>'
                     + '</div>';
            }).join('');
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
        almResetPick();
        window.almScanIconToggle();   // ocultar el icono escanear mientras hay texto
        window.almBuscarSuggest();
    };
    // Buscar por código o descripción suelta los atajos "Stock bajo"/"Con stock"
    // (ver almSoltarAtajosStock). Igual que el foco del filtro de Categoría.
    window.almBuscarFocus = function () {
        // Reintenta cargar el catálogo async si la 1ª carga falló (blip de red): al hacer foco
        // en el buscador se vuelve a intentar. almCargarProductos está guardado (no re-fetchea
        // si ya cargó o está en curso), así que es seguro llamarlo aquí.
        if (!window.almProductosCargados && typeof window.almCargarProductos === 'function') window.almCargarProductos();
        almSoltarAtajosStock(); window.almBuscarSuggest();
    };
    window.almBuscarEnter = function (ev) {
        if (ev && ev.key !== 'Enter') return;
        if (ev) ev.preventDefault();
        almResetPick();
        // Buscar algo nuevo es una acción explícita del usuario para ver OTRA cosa —
        // no debe quedar recortada en silencio por un atajo "Con stock"/"Stock bajo"
        // que seguía encendido de antes (mismo bug que resolvía almVerTodo). Sin esto
        // el backend hacía search AND solo_bajo y el usuario veía resultados
        // incoherentes con lo que pidió. Ver almResetBadges().
        almResetBadges();
        almSuggestHide();
        almCargar();
    };
    window.almBuscarPick = function (texto, idProducto, idsCsv) {
        // Patron placeholder-background: el termino elegido va al value temporalmente
        // para que filtros() -> valActive() lo promueva a data-active + placeholder.
        var inp = el('almFiltroBuscar'); if (inp) inp.value = texto;
        almBuscarPickedId  = idProducto ? parseInt(idProducto, 10) : null;
        // idsCsv: presentaciones agrupadas (misma descripcion). Si viene, el filtro usa
        // id_producto_in con esos IDs exactos en vez del LIKE por texto.
        almBuscarPickedIds = (idsCsv && idsCsv.length) ? idsCsv : null;
        // Nota: las sugerencias ya se limitan a la categoría activa (ver almBuscarSuggest),
        // así que el clic nunca trae un material de otra categoría — no hay que tocar el
        // filtro de categoría aquí.
        // Mismo motivo que almBuscarEnter: un clic en sugerencia pide ver ESE producto
        // puntual — un "Stock bajo" pegado de antes podía ocultarlo si no calificaba.
        almResetBadges();
        almSuggestHide();
        almCargar();
    };
    // Ver el MISMO producto en otro almacén (clic en el sidebar "En otros almacenes").
    // Antes hacía window.location.href → recarga completa de la página. Ahora reusa el
    // flujo AJAX del módulo (preloader + almCargar, igual que cambiar el almacén en el
    // dropdown): fija el producto enfocado y cambia el almacén con selectOption, que
    // dispara 'dropdown-selection' → almCargar (refresca tabla + KPIs + el propio sidebar).
    window.almVerProductoEnAlmacen = function (idAlmacen, nombre, idProducto) {
        // Solo el producto enfocado (sin arrastrar el texto de búsqueda previo), igual
        // que el link viejo que solo llevaba id_almacen + id_producto.
        var inp = el('almFiltroBuscar');
        if (inp) { inp.value = ''; inp.dataset.active = ''; inp.placeholder = inp.dataset.placeholderEmpty || 'Buscar por código o descripción…'; }
        almBuscarPickedId  = idProducto ? parseInt(idProducto, 10) : null;
        almBuscarPickedIds = null;
        // Un "Stock bajo"/"Con stock" pegado del almacén anterior podía ocultar este
        // producto puntual en el nuevo almacén (solo_bajo/solo_con_saldo NO se
        // exceptúan para id_producto — ver inventarioBaseQuery en el backend).
        almResetBadges();
        if (window.almScanIconToggle) window.almScanIconToggle();
        almSuggestHide();
        if (typeof selectOption === 'function') {
            selectOption('almSelAlmacenDropdown', String(idAlmacen), nombre);
        } else {
            var h = el('almSelAlmacen'); if (h) h.value = idAlmacen;
            almCargar();
        }
    };
    window.almBuscarLimpiar = function () {
        var inp = el('almFiltroBuscar');
        if (inp) {
            inp.value = '';
            inp.dataset.active = '';                                  // borrar el filtro activo
            inp.placeholder = inp.dataset.placeholderEmpty || 'Buscar por código o descripción…';
        }
        almResetPick();
        window.almScanIconToggle();   // buscador vacío → reaparece el icono escanear
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
    window.almCatFocus = function () { almSoltarAtajosStock(); window.almCatSuggest(); };
    window.almCatEnter = function (ev) {
        if (ev && ev.key !== 'Enter') return;
        if (ev) ev.preventDefault();
        almResetPick(); // filtrar por categoría no debe quedar anulado por un pick exacto pegado
        almResetBadges(); // idem almBuscarEnter: no combinar en silencio con "Stock bajo"/"Con stock"
        almCatSuggestHide();
        almCargar();
    };
    window.almCatPick = function (cat) {
        var inp = el('almFiltroCat'); if (inp) inp.value = cat;
        almResetPick(); // filtrar por categoría no debe quedar anulado por un pick exacto pegado
        almResetBadges(); // idem almBuscarEnter: no combinar en silencio con "Stock bajo"/"Con stock"
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
        if (id === 'almSelAlmacenDropdown') {
            // Al cambiar de almacén, DESCARTAR la selección de productos del almacén anterior:
            // una Salida / Nota de Entrega es SIEMPRE de un único almacén, así que arrastrar
            // productos de otro permitiría un movimiento mezclado (los del otro almacén tienen
            // saldo 0 aquí). Se limpia antes de recargar para que la barra flotante y la tabla
            // reflejen solo el almacén nuevo.
            if (typeof window.almSelClear === 'function') window.almSelClear();
            almCargar();
        }
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
            // Item especial "VER TODO EL STOCK" → muestra SOLO los productos con
            // existencias (>0), encendiendo el badge "Con stock". El catálogo completo
            // (incl. stock 0) sigue disponible al pulsar el total "PRODUCTOS" del Consolidado.
            if (item.getAttribute('data-action') === 'ver-todo') {
                almSuggestHide();
                if (window.almFiltrarConSaldo) window.almFiltrarConSaldo(true);
                return;
            }
            // data-pid → match exacto en el backend; data-pick → texto visible en el input.
            window.almBuscarPick(item.getAttribute('data-pick') || '', item.getAttribute('data-pid') || '', item.getAttribute('data-pids') || '');
            return;
        }
        var catItem = e.target.closest('#almFiltroCatSuggest .alm-suggest-item');
        if (catItem) { e.preventDefault(); window.almCatPick(catItem.getAttribute('data-pick') || ''); return; }
        if (!e.target.closest('.alm-filter')) { almSuggestHide(); almCatSuggestHide(); }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { almSuggestHide(); almCatSuggestHide(); } });

    // El paginador clásico fue reemplazado por auto-carga continua por offset (ver almCargar).
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
    // Modo "Ver solo seleccionados" activado desde el contador de la barra flotante. Al
    // (re)activarlo, almToggleSoloSel recarga vía AJAX mandando id_producto_in con la
    // selección actual → el backend devuelve SOLO esos productos. Deseleccionar una fila NO
    // la oculta al instante: queda visible (sin marcar) para poder re-seleccionarla si fue un
    // clic accidental; desaparece recién al volver a pulsar el toggle (nueva recarga). Se
    // desactiva automáticamente al limpiar la selección.
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
    window.almToggleSoloSel = function (e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        if (!almSelCount()) { toast('No hay productos seleccionados todavía.', 'error'); return; }
        almSoloSel = !almSoloSel;
        // "Ver solo seleccionados" es una acción global: descartar el pick exacto para no
        // mandar id_producto e id_producto_in a la vez (query contradictoria + URL incoherente).
        almResetPick();
        // El circulo ambar en el numero (.is-filtering) refleja el estado actual.
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
        // Auto-seleccion desde URL (?id_producto=NNN): si es el primer render y la
        // fila del producto pickeado esta en el tbody, la promovemos a almSeleccion
        // antes de marcar las filas. Tras esto, _almPendingAutoSelect se apaga para
        // que clicks de deseleccion posteriores no se reviertan en cada recarga.
        if (_almPendingAutoSelect && almBuscarPickedId) {
            var trPick = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + almBuscarPickedId + '"]');
            if (trPick && !almSeleccion[almBuscarPickedId]) {
                almSeleccion[almBuscarPickedId] = {
                    codigo: trPick.getAttribute('data-codigo') || '',
                    nombre: trPick.getAttribute('data-nombre') || '',
                    um:     trPick.getAttribute('data-um') || '',
                    saldo:  parseFloat(trPick.getAttribute('data-saldo') || '0') || 0,
                    cantidad: '',
                    // Coherente con la selección por clic: nº de parte a entregar (filtros).
                    parte:  trPick.getAttribute('data-parte-sel') || '',
                };
                almSelRefreshBar();
            }
            // Apagar el flag aunque la fila no haya aparecido (ej. backend filtro vacio)
            // — sin esto, el siguiente almCargar() volveria a auto-seleccionar y se
            // perderia la intencion del usuario.
            _almPendingAutoSelect = false;
        }
        almSelApplyToRows(document.querySelectorAll('#almTableBody tr.alm-row'));
        // Foco pendiente (Auditoría): tras recargar, dejar el teclado listo en el input de
        // cantidad de esa fila (si sigue seleccionada). Se consume UNA vez.
        if (_almPendingFocusId) {
            var trF = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + _almPendingFocusId + '"]');
            _almPendingFocusId = null;
            if (trF && almSeleccion[trF.getAttribute('data-id-producto')]) {
                var inpF = trF.querySelector('.alm-row-cant');
                if (inpF) { inpF.disabled = false; setTimeout(function () { try { inpF.focus(); } catch (e) {} }, 30); }
            }
        }
    }
    // Aplica TODO el estado de una fila (selección azul + faltante/exceso de stock +
    // filtro "solo seleccionados") en UNA sola pasada por fila. En el scroll-infinito
    // (append) se llama SOLO con las filas recién agregadas — antes se re-iteraba todo
    // el tbody (×4) en cada lote, lo que era O(n²) y congelaba el navegador al cargarse
    // todo el stock.
    function almSelApplyToRows(rows) {
        rows.forEach(function (tr) {
            var id = tr.getAttribute('data-id-producto');
            // Refrescar el saldo CACHEADO de la selección con el del render fresco. Tras una
            // auditoría/movimiento el saldo del servidor cambió, y el control "excede stock" de
            // la salida compara la cantidad contra ESTE saldo cacheado. Sin esto comparaba contra
            // el saldo viejo (antes de auditar) y pintaba la fila de rojo como si la salida
            // excediera, aunque el usuario aún no realiza la salida.
            var sel = almSeleccion[id];
            if (sel) {
                var ds = parseFloat(tr.getAttribute('data-saldo'));
                if (!isNaN(ds)) {
                    sel.saldo = ds;
                    // Re-evaluar el "excede" con el saldo nuevo por si la cantidad ya tecleada
                    // dejó de exceder (o pasó a exceder) tras el cambio de stock.
                    var cant = parseFloat(String(sel.cantidad || '').replace(',', '.'));
                    if (!isNaN(cant) && cant > 0) {
                        if (cant > ds) almExceden[id] = true; else delete almExceden[id];
                    }
                }
            }
            almSelMarkRow(tr, !!almSeleccion[id]);
            tr.classList.toggle('alm-row-missing-cant', !!almFaltantes[id]);
            tr.classList.toggle('alm-row-exceeds-stock', !!almExceden[id]);
            if (almSoloSel && !almSeleccion[id]) tr.style.display = 'none';
            // Re-aplicar el nº de parte elegido (filtros) tras recargas del tbody: resalta el
            // número clickeado en la nueva fila para conservar la elección.
            var psel = almSeleccion[id] && almSeleccion[id].parte;
            if (psel) {
                tr.dataset.parteSel = psel;
                tr.querySelectorAll('.alm-parte-opt').forEach(function (o) {
                    o.classList.toggle('alm-parte-on', o.getAttribute('data-parte') === psel);
                });
            }
        });
    }
    // Selecciona una fila (idempotente): crea su entrada en almSeleccion, la marca y enfoca
    // el input de cantidad. Si ya estaba seleccionada NO hace nada (nunca deselecciona).
    // Fuente única de la lógica de "seleccionar" — la usan el clic en la fila y el clic en un
    // número de parte. Devuelve true si quedó seleccionada.
    function almSelEnsureRow(tr) {
        var id = tr.getAttribute('data-id-producto'); if (!id) return false;
        if (almSeleccion[id]) return true;
        almSeleccion[id] = {
            codigo: tr.getAttribute('data-codigo') || '',
            nombre: tr.getAttribute('data-nombre') || '',
            um:     tr.getAttribute('data-um') || '',
            saldo:  parseFloat(tr.getAttribute('data-saldo') || '0') || 0,
            cantidad: '',
            // Nº de parte a entregar (filtros): arranca en el actual del selector de la fila
            // (o el principal en data-parte-sel). Vacío en productos sin equivalencias.
            parte:  tr.getAttribute('data-parte-sel') || '',
        };
        almSelMarkRow(tr, true);
        setTimeout(function () { var inp = tr.querySelector('.alm-row-cant'); if (inp) inp.focus(); }, 30);
        return true;
    }
    // Clic en un número de parte de la descripción: lo marca como el que se ENTREGA (resalta
    // dentro de la fila), lo guarda en la fila y en almSeleccion, y SELECCIONA la fila si no
    // lo estaba. La lista de partes lleva data-no-toggle (el clic no togglea la selección por
    // el handler genérico), así que sin esto un clic aquí no seleccionaba nada y el usuario
    // tenía que atinarle al nombre — de ahí el "lo doy varias veces y de repente selecciona".
    window.almRowPartePick = function (el) {
        var tr = el.closest('tr.alm-row'); if (!tr) return;
        var id = tr.getAttribute('data-id-producto');
        var parte = el.getAttribute('data-parte') || '';
        tr.querySelectorAll('.alm-parte-opt').forEach(function (o) { o.classList.toggle('alm-parte-on', o === el); });
        tr.dataset.parteSel = parte;
        almSelEnsureRow(tr);
        if (almSeleccion[id]) almSeleccion[id].parte = parte;
        almSelRefreshBar();
    };
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
        else almSelEnsureRow(tr);
        // En modo "Ver solo seleccionados" NO re-ocultamos la fila al deseleccionar: queda
        // visible (sin marcar) para poder volver a seleccionarla si el clic fue accidental.
        // La fila desaparece recién al volver a pulsar el toggle (recarga → almSelApplyToRows).
        almSelRefreshBar();
    });
    function almSelAlmacenActual() { var s = el('almSelAlmacen'); return s ? s.value : ''; }
    // Único botón de la barra flotante: abre el modal Nota de Entrega.
    // El backend decide si es SALIDA (consumo en el mismo almacén) o TRASPASO (envío
    // a otro almacén) según el frente destino elegido en el formulario.
    window.almSelAccion = function () {
        if (!almSelCount()) { toast('Selecciona al menos un producto (clic en su fila).', 'error'); return; }
        // Guard de permiso: registrar una salida exige la clave 'almacen.movimiento'
        // (mismo patrón que el modal de Auditoría). Sin la clave no se abre el modal.
        if (!ensurePerm(HAS_MOVER, 'No tienes permiso para registrar movimientos de inventario.')) return;
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
        // Sin cantidad + sin saldo: no es que el usuario "olvidara" escribirla, es que no hay
        // nada que sacar. Se separa de `faltan` para no pedirle una cantidad que ningún valor
        // válido podría satisfacer (cualquier c > 0 caería luego en `exceden`).
        var sinSaldo = [];
        Object.keys(almSeleccion).forEach(function (id) {
            var s = almSeleccion[id] || {};
            var c = parseFloat(String(s.cantidad == null ? '' : s.cantidad).replace(',', '.').trim());
            var saldo = parseFloat(s.saldo) || 0;
            if (!isFinite(c) || c <= 0) {
                (saldo <= 0 ? sinSaldo : faltan).push({ id: id, nombre: s.nombre || ('#' + id) });
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
            toast('Stock insuficiente: ' + det + '.', 'error');
            return;
        }
        // Se avisa ANTES que `faltan`: si un producto no tiene saldo, ese es el problema real
        // y pedirle una cantidad solo lo mandaría al error de "stock insuficiente".
        if (sinSaldo.length) {
            var firstSsTr = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + sinSaldo[0].id + '"]');
            if (firstSsTr) firstSsTr.scrollIntoView({ behavior: 'smooth', block: 'center' });
            toast('Stock insuficiente de: ' + sinSaldo.map(function (f) { return f.nombre; }).join(', ') + '.', 'error');
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
    window.almProdCatPick = function (cat) {
        var inp = el('almProdCategoria'); if (inp) inp.value = cat; almProdCatHide();
        // Re-sincroniza la visibilidad del campo de Equivalencias: solo aplica a FILTROS.
        // El oninput ya lo hace al teclear; aquí lo hacemos al ELEGIR del dropdown / Enter,
        // si no, cambiar la categoría por la lista dejaba el campo mostrado/oculto de forma
        // incoherente (las equivalencias son SOLO para la lógica de los filtros).
        if (window.almProdEquivSyncVisible) window.almProdEquivSyncVisible();
    };

    // Delegación: click en una opción de la lista / click fuera del campo lo cierra.
    document.addEventListener('click', function (e) {
        var item = e.target.closest('#almProdCatSuggest .si-item');
        if (item) { e.preventDefault(); window.almProdCatPick(item.getAttribute('data-cat') || ''); return; }
        // No cerrar si el click fue dentro del propio campo (input + caret + lista,
        // que ahora vive DENTRO de .alm-cat-field — por eso un solo closest cubre todo).
        if (!e.target.closest('.alm-cat-field')) almProdCatHide();
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
    // almOpen (no `open`): `open` a secas sombreaba window.open en todo el IIFE — foot-gun
    // para cualquier uso futuro de window.open dentro del closure. Renombrado explícito.
    function almOpen(id)  { var m = el(id); if (m) m.classList.add('open'); }
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
        // Detalles del producto: cerrar con Escape también devuelve el foco al input de
        // cantidad de la fila seleccionada (mismo criterio que la "✕", vía almDetalleCerrar).
        var detalle = el('almDetalleModal');
        if (detalle && detalle.classList.contains('open') && typeof window.almDetalleCerrar === 'function') {
            window.almDetalleCerrar();
            return;
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
                // El export debe reflejar EXACTAMENTE lo que muestra la tabla. Reusamos
                // filtros() —la única fuente de verdad de los filtros activos: almacén,
                // búsqueda/producto puntual, categoría, stock bajo/con saldo— en vez de
                // armar la URL a mano. Antes solo mandaba almacén + categoría, así que
                // ignoraba el producto buscado y exportaba toda la categoría.
                var u = new URL(@json(route('almacen.export')), window.location.origin);
                filtros().forEach(function (v, k) { u.searchParams.set(k, v); });
                almDescargarExcel(u.toString());
                break;
        }
    };

    // ── Descarga genérica con preloader ───────────────────────────────────
    // Baja el archivo vía fetch + blob (no window.location.href / window.open) para
    // mostrar el spinner global MIENTRAS el servidor lo genera y forzar la DESCARGA
    // (en vez de abrir otra pestaña). El nombre sale del Content-Disposition; si no
    // viene, usa el fallback. La usan la Copia de Inventario (Excel) y las Etiquetas
    // QR (PDF) — misma UX de descarga que el resto del módulo.
    function almDescargarArchivo(url, fallbackName, okMsg, errMsg) {
        pre();
        return fetch(url, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                var cd = r.headers.get('Content-Disposition') || '';
                var m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(cd);
                var nombre = m ? decodeURIComponent(m[1]) : fallbackName;
                return r.blob().then(function (blob) { return { blob: blob, nombre: nombre }; });
            })
            .then(function (res) {
                var objUrl = URL.createObjectURL(res.blob);
                var a = document.createElement('a');
                a.href = objUrl; a.download = res.nombre;
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(function () { URL.revokeObjectURL(objUrl); }, 2000);
                unpre();
                if (okMsg && window.showToast) window.showToast(okMsg, 'success');
            })
            .catch(function () {
                unpre();
                if (window.showToast) window.showToast(errMsg, 'error');
            });
    }

    function almDescargarExcel(url) {
        almDescargarArchivo(url, 'Copia_Inventario.xlsx', 'Excel descargado.', 'No se pudo generar el Excel.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  ETIQUETAS QR + ESCANEO  (como las etiquetas de producto del supermercado)
    //   · Imprimir: almEtiquetasModal (elige formato) → PDF GET almacen.etiquetas.
    //   · Escanear: almEscanearModal (cámara html5-qrcode o lector USB/tecleo) →
    //     resuelve el CODIGO vía almacen.buscar-codigo → filtra la tabla a ese producto.
    //  Read-only: sin permiso especial (mismo criterio que el export del inventario).
    // ═══════════════════════════════════════════════════════════════════════
    var ROUTE_ETIQUETAS     = @json(route('almacen.etiquetas'));
    var ROUTE_BUSCAR_CODIGO = @json(route('almacen.buscar-codigo'));

    // Muestra el icono "escanear" SOLO cuando el buscador está vacío (si hay texto o
    // filtro activo, su lugar lo ocupa la "x" de limpiar). Mantiene ambos iconos
    // mutuamente excluyentes dentro del cuadro del buscador. En window.* para poder
    // llamarse desde filtros() sin depender del scope donde se definió.
    window.almScanIconToggle = function () {
        var sc = el('almBuscarScan'), bi = el('almFiltroBuscar');
        if (!sc || !bi) return;
        var tiene = (bi.value && bi.value.trim()) || (bi.dataset.active && bi.dataset.active.trim());
        sc.style.display = tiene ? 'none' : 'flex';
    };

    // Abre el modal de etiquetas. Según cuántos productos lleguen en `lista`
    // ([{ id, codigo, nombre }]) se arma de tres formas:
    //   · sin lista      → solo la fila de abajo: cantidad (#almEtqCopias) + formato. Se
    //     etiqueta lo que indique idsCsv, o el filtro de categoría si viene vacío.
    //   · UN producto    → se muestra su ficha arriba (centrada, sin campo propio) y la
    //     cantidad sigue siendo la de abajo → se genera igual que el caso anterior (?copias).
    //   · VARIOS         → cada ficha lleva su propio campo y la cantidad de abajo se
    //     esconde; al generar manda ?items=ID:CANT,ID:CANT.
    window.almAbrirEtiquetas = function (idsCsv, lista) {
        var m = el('almEtiquetasModal'); if (!m) return;
        m.dataset.ids = idsCsv || '';

        // El bloque de productos SOLO aparece cuando hay VARIOS: ahí cada uno lleva su propio
        // campo de cantidad al lado y el código + descripción es lo que dice cuál es cuál.
        // Con UN solo producto ese rótulo sobra —se acaba de elegir ese producto— y el
        // cliente pidió quitarlo; su cantidad la toma el campo de abajo, junto al formato.
        var porProducto = Array.isArray(lista) && lista.length > 1;
        var wrapLista = el('almEtqModoLista'), copias = el('almEtqCopias');
        if (wrapLista) wrapLista.style.display = porProducto ? '' : 'none';
        if (copias)    copias.style.display    = porProducto ? 'none' : '';

        var cont = el('almEtqLista');
        // Vaciar SIEMPRE antes de repintar: si no, al abrir el modal desde el menú Acciones
        // después de haberlo usado con varios productos, los campos .alm-etq-cant de aquella
        // selección seguían en el DOM (ocultos) y almEtiquetasGenerar los tomaba como modo
        // "por producto" → se etiquetaba lo de la vez anterior en vez de lo pedido ahora.
        if (cont) cont.innerHTML = '';

        if (porProducto) {
            if (cont) {
                // Ficha por producto CENTRADA y sin recuadro: código y descripción con el
                // MISMO color y cuerpo, uno debajo del otro. Antes cada una iba en una caja
                // gris con el código en azul y más chico que la descripción — tres estilos
                // distintos para dos datos del mismo producto.
                cont.innerHTML = lista.map(function (it) {
                    var id  = escHtml(String(it.id));
                    var cod = escHtml(String(it.codigo || ''));
                    // it.label es el formato viejo "COD — NOMBRE"; se conserva como respaldo.
                    var nom = escHtml(String(it.nombre || it.label || ('#' + it.id)));
                    var texto = '<div style="flex:1;min-width:0;text-align:center;font-size:13px;color:#1e293b;line-height:1.35;">'
                        + (cod ? '<div>' + cod + '</div>' : '')
                        + '<div title="' + nom + '">' + nom + '</div>'
                        + '</div>';
                    return '<div style="display:flex;align-items:center;gap:10px;">'
                        +   texto
                        +   '<input type="number" class="alm-etq-cant" id="almEtqCant' + id + '" name="etq_cant_' + id + '" data-id="' + id + '" value="1" min="1" max="200" step="1" '
                        +     'aria-label="Cantidad de etiquetas de ' + nom + '" '
                        +     'style="width:62px;height:32px;border:1px solid #cbd5e0;border-radius:6px;padding:0 8px;font-size:13px;text-align:center;outline:none;flex:0 0 auto;">'
                        + '</div>';
                }).join('');
            }
        }
        almOpen('almEtiquetasModal');
    };
    window.almEtiquetasGenerar = function () {
        var m = el('almEtiquetasModal'); if (!m) return;
        var fmt = (el('almEtqFormato') && el('almEtqFormato').value) || 'carta';
        var u = new URL(ROUTE_ETIQUETAS, window.location.origin);
        u.searchParams.set('formato', fmt);

        // El modo se decide por la PRESENCIA de campos por producto, no por si la lista está
        // visible: con un solo producto la ficha se muestra igual pero SIN campo propio (su
        // cantidad es la de abajo), y mirar el display mandaba items= vacío → "No hay
        // productos para etiquetar" con el producto a la vista.
        var camposPorProducto = el('almEtqLista') ? el('almEtqLista').querySelectorAll('.alm-etq-cant') : [];
        if (camposPorProducto.length) {
            // MODO POR PRODUCTO → items=ID:CANT,ID:CANT (cada uno con su cantidad).
            var pares = [];
            camposPorProducto.forEach(function (inp) {
                var id = inp.getAttribute('data-id');
                var q = parseInt(inp.value, 10);
                if (!isFinite(q) || q < 1) q = 1;
                if (q > 200) q = 200;
                if (id) pares.push(id + ':' + q);
            });
            if (!pares.length) { toast('No hay productos para etiquetar.', 'error'); return; }
            u.searchParams.set('items', pares.join(','));
        } else {
            // MODO ÚNICO → misma cantidad para todos (?copias) sobre ids o categoría.
            var ids = m.dataset.ids || '';
            var copias = parseInt((el('almEtqCopias') && el('almEtqCopias').value) || '1', 10);
            if (!isFinite(copias) || copias < 1) copias = 1;
            if (copias > 200) copias = 200;
            u.searchParams.set('copias', String(copias));
            if (ids) {
                u.searchParams.set('ids', ids);
            } else {
                // Sin selección: respeta el filtro de categoría APLICADO (data-active), mismo
                // criterio que el export. El almacén no aplica (la etiqueta es del catálogo).
                var catEl = el('almFiltroCat');
                var cat = catEl ? String(catEl.dataset.active || '').trim() : '';
                if (cat) u.searchParams.set('categoria', cat);
            }
        }
        // Cierra el modal y baja el PDF con spinner (misma UX que la Copia de
        // Inventario y la Nota de Entrega): nada de abrir otra pestaña.
        almCerrar('almEtiquetasModal');
        almDescargarArchivo(u.toString(), 'Etiquetas_QR_' + fmt + '.pdf', 'Etiquetas descargadas.', 'No se pudieron generar las etiquetas.');
    };
    // Botón "Etiquetas" de la barra de selección masiva → MODO POR PRODUCTO: cada
    // producto seleccionado con su propio campo de cantidad.
    window.almSelEtiquetas = function () {
        if (!almSelCount()) { toast('Selecciona al menos un producto (clic en su fila).', 'error'); return; }
        var ids = Object.keys(almSeleccion);
        var lista = ids.map(function (id) {
            var s = almSeleccion[id] || {};
            // codigo y nombre van SEPARADOS para que la ficha del modal los maquete en dos
            // renglones; `label` se mantiene por compatibilidad con el render de respaldo.
            var label = (s.codigo || '') + (s.codigo && s.nombre ? ' — ' : '') + (s.nombre || ('#' + id));
            return { id: id, codigo: s.codigo || '', nombre: s.nombre || '', label: label };
        });
        window.almAbrirEtiquetas(ids.join(','), lista);
    };

    // ── Escaneo ────────────────────────────────────────────────────────────
    var almScanner  = null;   // instancia Html5Qrcode (si la cámara arrancó)
    var almScanBusy = false;  // evita resolver dos veces el mismo código

    window.almEscanear = function () {
        // En PC NO se abre el modal de cámara (no hay cámara → salía el feo "No se
        // pudo abrir la cámara"). La búsqueda SIEMPRE se hace en el buscador general
        // (#almFiltroBuscar): un lector USB teclea ahí (y el lector global ya resuelve
        // los escaneos sin abrir nada). El modal de cámara solo tiene sentido en
        // teléfono. Detección por user-agent móvil (los equipos de campo son Android).
        var esMovil = /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent || '');
        if (!esMovil) {
            var bi = el('almFiltroBuscar');
            if (bi) { bi.focus(); if (bi.select) bi.select(); }
            toast('Escanea con el lector USB o escribe el código en el buscador.', 'info');
            return;
        }
        var m = el('almEscanearModal'); if (!m) return;
        almScanBusy = false;
        var actBtn = el('almScanActivar'); if (actBtn) actBtn.style.display = 'none';
        almOpen('almEscanearModal');
        // El modal es SOLO móvil (en PC se enfoca el buscador). La cámara es la única
        // vía: arranca al abrir y pide el permiso de una (la lib se precargó en idle).
        almScanIniciarCamara();
    };

    // Estado de fallback del modal de escaneo: oculta el recuadro de cámara, pone el
    // mensaje y muestra (o no) el botón "Activar cámara". Centralizado aquí para no
    // repetir el mismo bloque en los 3 puntos donde la cámara no arranca (sin librería,
    // sin HTTPS, o error al iniciar). mostrarBtn=true solo cuando reintentar tiene
    // sentido (un fallo de arranque/permiso), no cuando es imposible (sin HTTPS).
    function almScanFallback(msg, mostrarBtn) {
        var reader = el('almScanReader'), hint = el('almScanHint'), btn = el('almScanActivar');
        if (reader) reader.style.display = 'none';
        if (btn) btn.style.display = mostrarBtn ? '' : 'none';
        if (hint) hint.textContent = msg;
    }

    // Carga la librería html5-qrcode BAJO DEMANDA (al escanear), probando primero el
    // archivo local y, si no quedó definida, el CDN. Comprueba `typeof Html5Qrcode`
    // DESPUÉS de cada carga: así detecta el caso en que el servidor devuelve el
    // index.html (HTML 200, por el try_files de nginx) en vez del .js — que NO dispara
    // onerror — y cae al CDN igual. Cachea la promesa para no recargar en cada intento.
    var almQrLibPromise = null;
    function almCargarQrLib() {
        if (typeof Html5Qrcode !== 'undefined') return Promise.resolve(true);
        if (almQrLibPromise) return almQrLibPromise;
        var local = @json(asset('js/vendor/html5-qrcode.min.js') . '?v=' . @filemtime(public_path('js/vendor/html5-qrcode.min.js')));
        var cdn   = 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js';
        almQrLibPromise = new Promise(function (resolve) {
            function load(src, next) {
                var s = document.createElement('script');
                s.async = true; s.src = src;
                s.onload  = function () { (typeof Html5Qrcode !== 'undefined') ? resolve(true) : (next ? next() : resolve(false)); };
                s.onerror = function () { next ? next() : resolve(false); };
                document.head.appendChild(s);
            }
            load(local, function () { load(cdn, null); });   // local → si no, CDN
        });
        return almQrLibPromise;
    }

    function almScanIniciarCamara() {
        var hint = el('almScanHint'), reader = el('almScanReader'), btn = el('almScanActivar');
        if (hint) hint.textContent = 'Cargando cámara…';
        // Aseguramos la librería ANTES de arrancar (carga local/CDN bajo demanda).
        almCargarQrLib().then(function (cargada) {
            if (!cargada) {
                almScanFallback('Cámara no disponible en este dispositivo.', false);
                return;
            }
            // La cámara requiere contexto seguro (HTTPS/localhost). Si no, lector/tecleo.
            if (!window.isSecureContext) {
                almScanFallback('La cámara necesita HTTPS para funcionar.', false);
                return;
            }
            if (reader) reader.style.display = '';
            if (hint) hint.textContent = 'Iniciando cámara…';
            var cfg    = { fps: 10, qrbox: { width: 220, height: 220 } };
            var onOk   = function (texto) { almScanResolver(texto); };
            var onTick = function () { /* frames sin código: ignorar */ };
            var ok     = function () { if (hint) hint.textContent = 'Apunta la cámara al código QR…'; if (btn) btn.style.display = 'none'; };
            // Si TODO falla mostramos el motivo REAL (NotAllowedError = permiso denegado,
            // NotFoundError = sin cámara, etc.) y dejamos visible el botón "Activar cámara"
            // para reintentar con un gesto explícito (vuelve a pedir permiso).
            var fail = function (err) {
                var msg = (err && (err.name || err.message)) ? (err.name || err.message) : 'desconocido';
                almScanFallback('No se pudo abrir la cámara (' + msg + '). Toca "Activar cámara" y permite el acceso.', true);
            };
            try {
                almScanner = new Html5Qrcode('almScanReader', { verbose: false });
                // 1) Intento directo con la cámara trasera (facingMode environment).
                almScanner.start({ facingMode: 'environment' }, cfg, onOk, onTick)
                    .then(ok)
                    .catch(function (e1) {
                        // 2) Fallback: enumerar cámaras y usar la última (suele ser la trasera).
                        //    Cubre teléfonos donde facingMode falla pero el deviceId sí sirve.
                        return Html5Qrcode.getCameras().then(function (cams) {
                            if (!cams || !cams.length) throw e1;
                            return almScanner.start(cams[cams.length - 1].id, cfg, onOk, onTick).then(ok);
                        });
                    })
                    .catch(fail);
            } catch (e) {
                fail(e);
            }
        });
    }

    function almScanDetenerCamara() {
        if (!almScanner) return;
        try {
            almScanner.stop()
                .then(function () { try { almScanner.clear(); } catch (e) {} almScanner = null; })
                .catch(function () { almScanner = null; });
        } catch (e) { almScanner = null; }
    }

    // Reintenta la cámara con un gesto EXPLÍCITO del usuario (botón "Activar cámara").
    // Detiene cualquier intento previo, espera un instante a que libere el dispositivo
    // y vuelve a arrancar — esto re-dispara el pedido de permiso del navegador cuando
    // el arranque automático falló (gesto de usuario).
    window.almScanReintentar = function () {
        var btn = el('almScanActivar'); if (btn) btn.style.display = 'none';
        var hint = el('almScanHint'); if (hint) hint.textContent = 'Iniciando cámara…';
        almScanDetenerCamara();
        setTimeout(almScanIniciarCamara, 250);
    };

    window.almEscanearCerrar = function () {
        almScanDetenerCamara();
        almCerrar('almEscanearModal');
    };

    // Pre-carga la librería de cámara en MÓVIL (en idle, sin competir con el render).
    // Clave para que al tocar "Escanear" el .start() corra DENTRO del gesto del usuario
    // y el navegador pida el permiso de cámara DE UNA — si la lib se bajara recién al
    // tocar, el .then() caería en una tarea posterior, se perdería el gesto y saldría el
    // paso extra "Activar cámara". En PC no hace falta (el modal no se abre).
    (function () {
        if (!/Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent || '')) return;
        var pre = function () { try { almCargarQrLib(); } catch (e) {} };
        if (typeof requestIdleCallback === 'function') requestIdleCallback(pre, { timeout: 3000 });
        else setTimeout(pre, 2000);
    })();

    // Resuelve el CODIGO contra el catálogo y, si existe, filtra la tabla a ese producto
    // reusando el "pick" del buscador (almBuscarPick → almCargar), que ya muestra el
    // saldo del producto en el almacén seleccionado. Si no existe, no cierra el modal
    // (deja seguir escaneando) y avisa.
    function almScanResolver(codigo) {
        codigo = String(codigo || '').trim();
        if (!codigo || almScanBusy) return;
        almScanBusy = true;
        var u = new URL(ROUTE_BUSCAR_CODIGO, window.location.origin);
        u.searchParams.set('codigo', codigo);
        fetch(u.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                var j = res.j || {};
                if (!res.ok || !j.found || !j.producto) {
                    almScanBusy = false;
                    toast(j.message || ('No se encontró el código ' + codigo + '.'), 'error');
                    return;
                }
                var p = j.producto;
                window.almEscanearCerrar();
                var label = (p.codigo || '') + (p.codigo && p.nombre ? ' — ' : '') + (p.nombre || '');
                if (window.almBuscarPick) window.almBuscarPick(label, p.id);
                toast('Producto: ' + label, 'success');
            })
            .catch(function () {
                almScanBusy = false;
                toast('Error al consultar el código.', 'error');
            });
    }

    // ── Lector USB global (escanear en PC SIN abrir el modal) ──────────────
    // Un lector de código de barras/QR por USB se comporta como teclado: "teclea"
    // el código completo + Enter en milisegundos. Para que en PC NO haga falta
    // abrir el modal de escaneo (que sin cámara solo ofrecía tecleo manual),
    // escuchamos a nivel de documento: si los caracteres llegan en RÁFAGA (propio
    // del lector, no del tecleo humano) y NO hay un campo enfocado ni un modal
    // abierto, los acumulamos y al Enter resolvemos el código con el MISMO
    // almScanResolver del modal → lo busca en el catálogo y lo muestra en el
    // buscador general (almBuscarPick). Si hay un input/textarea enfocado o un
    // modal abierto NO interceptamos: el lector entra por el flujo normal (la
    // caja de búsqueda o el campo manual del modal) y el tecleo humano no se afecta.
    (function () {
        var buf = '', lastT = 0;
        var GAP_MS = 50, MIN_LEN = 3;   // ráfaga < 50ms entre teclas; código de >= 3 chars
        function enCampoEditable() {
            var a = document.activeElement; if (!a) return false;
            var t = (a.tagName || '').toLowerCase();
            return t === 'input' || t === 'textarea' || t === 'select' || a.isContentEditable;
        }
        function hayModalAbierto() { return !!document.querySelector('.alm-modal-overlay.open'); }
        document.addEventListener('keydown', function (e) {
            if (enCampoEditable() || hayModalAbierto()) { buf = ''; return; }
            if (e.ctrlKey || e.metaKey || e.altKey) return;   // atajos de teclado: no son del lector
            var now = (window.performance && performance.now) ? performance.now() : Date.now();
            if (now - lastT > GAP_MS) buf = '';   // pausa larga entre teclas → reinicia (descarta tecleo humano)
            lastT = now;
            if (e.key === 'Enter') {
                var cod = buf.trim(); buf = '';
                if (cod.length >= MIN_LEN) {
                    e.preventDefault();
                    almScanBusy = false;          // permite resolver aunque no se haya abierto el modal
                    almScanResolver(cod);
                }
                return;
            }
            if (e.key && e.key.length === 1) buf += e.key;   // solo caracteres imprimibles del código
        }, true);
    })();

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
    // El tooltip de equipos (.tooltip-bubble) vive dentro de la celda, pero el wrap de la tabla
    // tiene overflow (recorta) y el thead sticky lo tapaba en búsquedas de una sola fila. Al
    // hacer hover lo sacamos con position:fixed SOBRE la celda (por encima de todo). Se rastrea
    // UNA sola burbuja activa y se RESTAURA al salir de la fila o al hacer scroll/cambio de vista
    // — si no, la burbuja fija quedaba "flotando dentro de la lista" al seleccionar o desplazar.
    var _almTipActiva = null;
    function almTipReset() {
        var b = _almTipActiva; if (!b) return; _almTipActiva = null;
        // Restaurar el ancla POR DEFECTO del blade: ARRIBA de la celda (bottom:100%).
        // OJO: NO limpiar `bottom` a '' — eso borraba el `bottom:100%` inline del blade y
        // la burbuja caía DEBAJO de la fila (bug: "sale por abajo"). Se restauran los
        // valores originales para que, aunque almTipShow no alcance a re-posicionar, la
        // burbuja quede siempre arriba.
        b.style.position = 'absolute';
        b.style.left = '0';
        b.style.right = 'auto';
        b.style.bottom = '100%';
        b.style.top = 'auto';
        b.style.transform = 'translateY(5px)';
        b.style.margin = '';
        b.style.zIndex = '';
    }
    function almTipShow(cell) {
        var bub = cell.querySelector(':scope > .tooltip-bubble'); if (!bub) { almTipReset(); return; }
        if (_almTipActiva && _almTipActiva !== bub) almTipReset();
        var r = cell.getBoundingClientRect();
        bub.style.position = 'fixed';
        bub.style.left = r.left + 'px';
        bub.style.right = 'auto';
        bub.style.transform = 'none';
        bub.style.margin = '0';
        bub.style.zIndex = '10050';
        // SIEMPRE arriba de la fila: anclamos por `top` usando el alto real de la burbuja
        // (top = borde superior de la celda − alto − 6px). Clamp al tope del viewport para
        // que nunca se voltee hacia abajo ni se salga por arriba de la pantalla.
        var h = bub.offsetHeight;
        var top = r.top - h - 6;
        if (top < 6) top = 6;
        bub.style.top = top + 'px';
        bub.style.bottom = 'auto';
        _almTipActiva = bub;
    }
    document.addEventListener('mouseover', function (e) {
        var cell = e.target.closest ? e.target.closest('#almTableBody tr.alm-row .alm-td-nombre') : null;
        if (cell) almTipShow(cell); else almTipReset();
    });
    // Al desplazar la vista (o al seleccionar, que reacomoda la fila) la burbuja fija quedaría
    // desalineada/flotando: se restaura. Captura = true para atrapar también el scroll del wrap.
    window.addEventListener('scroll', almTipReset, true);
    // Al SELECCIONAR un registro (clic en la fila) la fila se reacomoda: reseteamos la burbuja
    // para que no quede "metida" en la lista; reaparece bien al mover el mouse si sigues encima.
    document.addEventListener('click', almTipReset, true);
    window.almAbrirDetalle = function (id, cod, nom, um, cat, saldo, minimo, ubicacion) {
        var m = el('almDetalleModal'); if (!m) return;
        var hasMin = (minimo !== null && minimo !== undefined && minimo !== '');
        m.dataset.id = id;
        m.dataset.cod = cod || ''; m.dataset.nom = nom || ''; m.dataset.um = um || ''; m.dataset.cat = cat || '';
        m.dataset.ubicacion = ubicacion || '';
        m.dataset.saldo = (saldo == null ? '0' : String(saldo));
        m.dataset.minimo = hasMin ? String(minimo) : '';
        var bajo = hasMin && parseFloat(saldo || 0) <= parseFloat(minimo);
        // 'flex' (no '' ni 'block') porque el badge se layoutea con icono a la izquierda
        // y texto a la derecha (display:flex en el CSS inline del div). Ver markup arriba.
        el('almDetBajoBadge').style.display = bajo ? 'flex' : 'none';
        if (el('almDetUbicacion')) { el('almDetUbicacion').value = ubicacion || ''; showErr('almDetUbicacionError', ''); }

        // Compatibilidad (nº de parte + EQUIPOS que usan el filtro): carga bajo demanda.
        window.almCargarCompat(id);

        almOpen('almDetalleModal');
    };

    // Trae equivalencias + equipos del filtro y los pinta en el detalle. Si el usuario abre
    // otro producto mientras carga, se ignora la respuesta vieja (compara el id del modal).
    window.almCargarCompat = function (id) {
        var esc = function (s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); };
        var wrap = el('almDetCompat'); if (!wrap) return;
        var partesWrap = el('almDetPartesWrap'), equiposWrap = el('almDetEquiposWrap'),
            partesBox = el('almDetPartes'), equiposBox = el('almDetEquipos'), countEl = el('almDetEquiposCount');
        wrap.style.display = 'none';
        partesWrap.style.display = 'none'; equiposWrap.style.display = 'none';
        partesBox.innerHTML = ''; equiposBox.innerHTML = ''; countEl.textContent = '';

        var url = "{{ route('almacen.productos.compatibilidad', ['id' => '__PID__']) }}".replace('__PID__', id);
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var m = el('almDetalleModal');
                if (!m || String(m.dataset.id) !== String(id)) return; // cambió de producto mientras cargaba
                var partes = d.equivalencias || [], equipos = d.equipos || [];
                if (partes.length) {
                    partesBox.innerHTML = partes.map(function (p) {
                        return '<span style="background:#eff6ff;color:#0067b1;border:1px solid #dbeafe;border-radius:6px;padding:2px 8px;font-size:12px;font-weight:700;">' + esc(p) + '</span>';
                    }).join('');
                    partesWrap.style.display = 'block';
                }
                if (equipos.length) {
                    countEl.textContent = '(' + equipos.length + ')';
                    // La ETAPA (primario/secundario) va por EQUIPO, no por producto: el mismo
                    // filtro puede ser primario en una máquina y secundario en otra. Sin
                    // confirmar se muestra "—" en gris: el hueco es deliberado, no un fallo.
                    equiposBox.innerHTML = equipos.map(function (e) {
                        var etapa = e.etapa
                            ? '<span style="font-size:11px;font-weight:700;color:#0f766e;background:#f0fdfa;border:1px solid #ccfbf1;border-radius:5px;padding:1px 7px;">' + esc(e.etapa) + '</span>'
                            : '<span style="font-size:11px;color:#cbd5e0;">&mdash;</span>';
                        var cant = e.cant > 1
                            ? '<span style="font-size:11px;font-weight:700;color:#64748b;">x' + e.cant + '</span>'
                            : '';
                        return '<div style="display:flex;align-items:center;gap:8px;background:#f8fafc;border:1px solid #eef2f7;border-radius:7px;padding:6px 9px;">' +
                               '<span style="font-size:11px;font-weight:800;color:#0067b1;text-transform:uppercase;min-width:104px;">' + esc(e.tipo) + '</span>' +
                               '<span style="font-size:12.5px;font-weight:600;color:#334155;flex:1;min-width:0;">' + esc(e.modelo) + '</span>' +
                               cant + etapa + '</div>';
                    }).join('');
                    equiposWrap.style.display = 'block';
                }
                // Sin equivalencias ni equipos el bloque entero queda oculto (no hay
                // mensaje de "vacío": el cliente lo pidió fuera).
                if (partes.length || equipos.length) wrap.style.display = 'block';
            })
            .catch(function () { /* silencioso: el detalle sigue usable sin la compatibilidad */ });
    };
    // Cierra "Detalles del producto" y, si la fila de ese producto SIGUE seleccionada,
    // devuelve el foco a su input de cantidad — así el usuario escribe la salida de una
    // (como al recién seleccionar). Sin esto el registro quedaba seleccionado pero el input
    // sin foco tras el modal, y había que deseleccionar/reseleccionar para poder escribir.
    window.almDetalleCerrar = function () {
        var m  = el('almDetalleModal');
        var id = m ? (m.dataset.id || '') : '';
        // Persistir la ubicación tecleada ANTES de cerrar (el modal ya no tiene botón
        // "Guardar"). Es no-op si el texto no cambió. Único punto: por aquí pasan tanto
        // la "✕" como el Escape del handler global.
        if (window.almGuardarUbicacionDetalle) window.almGuardarUbicacionDetalle();
        almCerrar('almDetalleModal');
        if (!id || !almSeleccion[id]) return;
        var tr = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + id + '"]');
        if (!tr) return;
        var inp = tr.querySelector('.alm-row-cant');
        if (!inp) return;
        inp.disabled = false; // la fila está seleccionada → ya debería estarlo; defensivo
        setTimeout(function () { try { inp.focus(); } catch (e) {} }, 30);
    };
    // Cancelar de un sub-modal (Auditoría / Stock mínimo / Editar): cierra ese modal y, si
    // venía de "Detalles del producto" (window.almDesdeDetalle), REABRE Detalles — sus datos
    // siguen en el dataset. La X de cada modal cierra del todo (no llama a esto). El flag se
    // pone en false al abrir "Nuevo producto" (almAbrirProducto), que comparte el modal Editar
    // pero NO viene de Detalles.
    window.almVolverADetalle = function (subId) {
        almCerrar(subId);
        var det = el('almDetalleModal');
        if (window.almDesdeDetalle && det && det.dataset.id) almOpen('almDetalleModal');
        window.almDesdeDetalle = false;
    };
    window.almDetalleAccion = function (which) {
        var m = el('almDetalleModal'); if (!m) return;
        var d = m.dataset, id = parseInt(d.id, 10);
        var minimo = (d.minimo === '' ? null : parseFloat(d.minimo));
        var saldo  = parseFloat(d.saldo || 0);
        // Ubicación TECLEADA (puede diferir de d.ubicacion, que es la última guardada).
        var ubicInput = el('almDetUbicacion');
        var ubicViva  = ubicInput ? (ubicInput.value || '').trim() : (d.ubicacion || '');

        // Salir a un sub-modal también abandona "Detalles", así que la ubicación tecleada se
        // persiste igual que al cerrar — si no, escribirla y tocar "Auditoría" la perdía en
        // silencio (ya no hay botón "Guardar" que la respalde). Dos excepciones:
        //   · 'editar'  → el modal de edición YA guarda UBICACION; le pasamos el valor vivo y
        //                 dejamos que él lo persista. Guardar aquí sería un PATCH duplicado.
        //   · 'eliminar'→ el producto se va; guardarle la ubicación antes es trabajo perdido.
        if (which !== 'editar' && which !== 'eliminar') window.almGuardarUbicacionDetalle();

        almCerrar('almDetalleModal');
        window.almDesdeDetalle = true;   // los sub-modales que siguen se abrieron desde Detalles
        switch (which) {
            // 'entrada'/'salida' removidos (esos flujos ya no van por producto individual).
            case 'ajuste':   if (window.almAbrirAjuste)         window.almAbrirAjuste(id, d.cod, d.nom, d.um, saldo); break;
            // 'minimo' → modal propio (separado de la Auditoría) para configurar el stock minimo.
            case 'minimo':   if (window.almAbrirMinimo)         window.almAbrirMinimo(id, d.nom, minimo); break;
            // 'kardex' antes navegaba a /admin/almacen/movimientos; ahora abre un
            // modal local con los movimientos solo de este producto + filtros mínimos.
            case 'kardex':   if (window.almAbrirKardexProducto) window.almAbrirKardexProducto(id, d.cod, d.nom, d.um, saldo); break;
            case 'editar':   if (window.almEditarProducto)      window.almEditarProducto(id, d.cod, d.nom, d.um, d.cat, ubicViva); break;
            case 'eliminar': if (window.almEliminarProducto)    window.almEliminarProducto(id); break;
        }
    };

    // Guarda SOLO la ubicación desde "Detalles del producto" (sin pasar por el modal
    // completo de Editar). Reusa el mismo endpoint PATCH que almGuardarProducto, mandando
    // el resto de campos (NOMBRE/UM/CATEGORIA/CODIGO) tal cual están en el dataset del
    // modal para no pisarlos — este endpoint no soporta PATCH parcial (ver validarProducto).
    //
    // Se dispara desde DOS sitios (ya no hay botón "Guardar"): Enter en el input y el cierre
    // del modal vía almDetalleCerrar. Por eso arranca comparando contra el valor con el que
    // se abrió el modal: sin ese corte, cada cierre lanzaría un PATCH y un toast aunque el
    // usuario no hubiera tocado el campo.
    window.almGuardarUbicacionDetalle = function () {
        var m = el('almDetalleModal'); if (!m || !m.dataset.id) return;
        var input = el('almDetUbicacion'); if (!input) return;
        var ubicacion = (input.value || '').trim();
        if (ubicacion === (m.dataset.ubicacion || '').trim()) return; // sin cambios → no molestar
        // El permiso se chequea DESPUÉS de detectar el cambio: si no, cerrar el modal sin
        // tocar nada le lanzaría el toast de "no tienes permiso" a cualquier usuario de solo lectura.
        if (!ensurePerm(HAS_PRODUCTOS, 'No tienes permiso para editar la ubicación.')) {
            input.value = m.dataset.ubicacion || ''; // revertir lo tecleado
            return;
        }
        var id = parseInt(m.dataset.id, 10);
        showErr('almDetUbicacionError', '');
        pre();
        fetch(ROUTE_PROD_ITEM(id), {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: JSON.stringify({
                CODIGO: m.dataset.cod || null, NOMBRE: m.dataset.nom, UM: m.dataset.um, CATEGORIA: m.dataset.cat || null,
                UBICACION: ubicacion || null
            })
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            unpre();
            if (res.ok) {
                m.dataset.ubicacion = ubicacion;
                toast('Ubicación actualizada.');
                if (window.almCargar) window.almCargar();
            } else {
                // El error va TAMBIÉN por toast: si el guardado se disparó al cerrar el modal,
                // el mensaje inline queda dentro de un modal ya oculto y nadie lo vería.
                var msg = (res.b && res.b.message) || 'No se pudo guardar la ubicación.';
                showErr('almDetUbicacionError', msg);
                toast(msg, 'error');
            }
        })
        .catch(function () {
            unpre();
            showErr('almDetUbicacionError', 'Error de red.');
            toast('Error de red al guardar la ubicación.', 'error');
        });
    };

    // ── Modal "Movimientos del producto" (kardex local de UN producto) ──
    // Reusa AlmacenController::movimientos con ?mini=1 (partial de 5 columnas)
    // y filtra por id_producto + id_almacen actual. Estado en window.__almKp.
    window.__almKp = { idProducto: null, tipo: '', desde: '', hasta: '' };

    window.almAbrirKardexProducto = function (idProducto, codigo, nombre, um, saldo) {
        window.__almKp = { idProducto: idProducto, tipo: '', desde: '', hasta: '' };
        el('almKpCodigo').textContent = codigo || '—';
        el('almKpNombre').textContent = nombre || '';
        el('almKpSaldo').textContent  = formatNum(saldo);
        el('almKpUm').textContent     = um || '';
        // Reset visual de filtros.
        if (el('almKpDesde'))      el('almKpDesde').value = '';
        if (el('almKpHasta'))      el('almKpHasta').value = '';
        if (el('almKpTipoSelect')) el('almKpTipoSelect').value = '';
        almOpen('almKardexProductoModal');
        window.almKpCargar();
    };

    // almKpChipSelect: gestiona el filtro de tipo desde el <select> del modal kardex.
    window.almKpChipSelect = function (tipo) {
        window.__almKp.tipo = tipo || '';
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
            if (body) body.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:#dc2626;font-size:12px;">No se pudieron cargar los movimientos.</td></tr>';
        })
        .finally(function () { if (body) body.style.opacity = '1'; });
    };

    // Click en links de paginación del kardex del producto.
    document.addEventListener('click', function (e) {
        var a = e.target.closest('#almKpPag a'); if (!a) return;
        e.preventDefault(); e.stopImmediatePropagation();
        window.almKpCargar(a.href);
    }, true);

    window.almAbrirAjuste = function (idProducto, codigo, nombre, um, saldo) {
        if (!ensurePerm(HAS_MOVER, 'No tienes permiso para registrar movimientos de inventario.')) return;
        var m = el('almAjusteModal');
        m.dataset.idProducto = idProducto;
        // Mostrar el saldo actual (sistema) para que el usuario sepa desde qué valor ajusta.
        var sv = el('almAjSaldoActual');
        if (sv) { var s = parseFloat(saldo); sv.textContent = (isNaN(s) ? '—' : formatNum(s)) + (um ? ' ' + um : ''); }
        el('almAjNuevoSaldo').value = '';
        showErr('almAjError', ''); almOpen('almAjusteModal');
    };

    // Reevalúa el resaltado de "stock bajo" de una fila con un saldo nuevo, SIN esperar la
    // recarga: actualiza data-saldo y togglea .alm-row-bajo comparando contra data-minimo.
    // Así el color (fondo/franja roja) se actualiza AL INSTANTE tras una auditoría (la
    // recarga posterior lo confirma). Antes el color quedaba "pegado" hasta deseleccionar
    // o recargar. Reutilizable por cualquier operación que cambie el stock de un producto visible.
    function almReevaluarStockFila(idProducto, nuevoSaldo) {
        var tr = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + idProducto + '"]');
        if (!tr) return;
        var saldo = parseFloat(nuevoSaldo);
        if (isNaN(saldo)) return;
        tr.dataset.saldo = String(saldo);
        var minStr = tr.dataset.minimo;
        var bajo = (minStr !== undefined && minStr !== '') && saldo <= parseFloat(minStr);
        tr.classList.toggle('alm-row-bajo', bajo);
        tr.dataset.bajo = bajo ? '1' : '0';
        // Sincronizar el saldo CACHEADO de la selección (si la fila está seleccionada) para que
        // el control "excede stock" use el saldo nuevo. La RE-EVALUACIÓN del "excede" no se hace
        // aquí: la auditoría siempre llama a almCargar() a continuación, y almSelApplyToRows la
        // recalcula en la recarga (evitamos duplicar esa lógica).
        if (almSeleccion[idProducto]) almSeleccion[idProducto].saldo = saldo;
    }

    window.almGuardarAjuste = function () {
        // Guard de permiso: la Auditoría registra un AJUSTE de inventario, que exige la
        // clave almacen.movimiento. (El stock mínimo se movió a su propio modal/flujo.)
        if (!ensurePerm(HAS_MOVER, 'No tienes permiso para registrar movimientos de inventario.')) return;
        var m = el('almAjusteModal');
        var idAlm = val('almSelAlmacen'); if (!idAlm) { showErr('almAjError', 'No hay almacén seleccionado.'); return; }
        var nuevoSaldoRaw = val('almAjNuevoSaldo');
        if (nuevoSaldoRaw === '') { showErr('almAjError', 'Indica el saldo según el conteo físico.'); return; }
        var ns = parseFloat(nuevoSaldoRaw);
        if (isNaN(ns) || ns < 0) { showErr('almAjError', 'El nuevo saldo debe ser un número ≥ 0.'); return; }

        pre();
        // Endpoint unificado de lote: la Auditoría se registra como un lote de 1 línea con
        // tipo=AJUSTE. El backend ignora los campos de Nota de Entrega para AJUSTE.
        fetch(ROUTE_LOTE, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: JSON.stringify({
                id_almacen: idAlm,
                tipo: 'AJUSTE',
                motivo: 'Auditoría de Inventario',
                lineas: [{ id_producto: m.dataset.idProducto, cantidad: ns }],
            })
        }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
          .then(function (res) {
              unpre();
              if (!res.ok) { showErr('almAjError', (res.b && res.b.message) || 'No se pudo registrar la auditoría.'); almCargar(); return; }
              // Feedback instantáneo del resaltado (rojo/normal) con el saldo auditado;
              // almCargar() recarga y confirma con el dato fresco del servidor.
              almReevaluarStockFila(m.dataset.idProducto, ns);
              // Tras la recarga, dejar el teclado listo en el input de cantidad de esta fila
              // (sigue seleccionada) — sin tener que deseleccionar/reseleccionar para escribir.
              _almPendingFocusId = m.dataset.idProducto;
              almCerrar('almAjusteModal'); toast('Auditoría registrada.'); almCargar();
          }).catch(function () { unpre(); showErr('almAjError', 'Error de red.'); });
    };

    // ── Modal "Stock mínimo (alerta)" — setea SOLO el mínimo de alerta del producto en el
    //    almacén actual (PATCH almacen.minimo). Separado de la Auditoría: son dos
    //    operaciones distintas con su propio botón en "Detalles del producto". ──
    window.almAbrirMinimo = function (idProducto, nombre, minimo) {
        if (!ensurePerm(HAS_MOVER, 'No tienes permiso para configurar el stock mínimo.')) return;
        var m = el('almMinimoModal'); if (!m) return;
        m.dataset.idProducto = idProducto;
        m.dataset.minimoOrig = (minimo == null ? '' : String(minimo)); // para detectar si cambió
        el('almMinValor').value = (minimo == null ? '' : minimo);
        showErr('almMinError', ''); almOpen('almMinimoModal');
    };

    window.almGuardarMinimo = function () {
        if (!ensurePerm(HAS_MOVER, 'No tienes permiso para configurar el stock mínimo.')) return;
        var m = el('almMinimoModal');
        var idAlm = val('almSelAlmacen'); if (!idAlm) { showErr('almMinError', 'No hay almacén seleccionado.'); return; }
        var minimoRaw = val('almMinValor');
        // Si no cambió respecto al valor original, no hay nada que guardar.
        if (minimoRaw === (m.dataset.minimoOrig || '')) { almCerrar('almMinimoModal'); return; }
        var nuevoMinimo = null;
        if (minimoRaw !== '') {
            // El mínimo de alerta debe ser > 0 (un mínimo de 0 no avisa de nada, equivale a
            // "sin alerta" — que ya se logra dejando el campo vacío).
            nuevoMinimo = parseFloat(minimoRaw);
            if (isNaN(nuevoMinimo) || nuevoMinimo <= 0) { showErr('almMinError', 'El mínimo debe ser un número mayor que 0 (o dejarlo vacío para quitar la alerta).'); return; }
        }
        pre();
        fetch(ROUTE_MIN(idAlm), {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: JSON.stringify({ id_producto: m.dataset.idProducto, cantidad_minima: (minimoRaw === '' ? null : nuevoMinimo) })
        }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
          .then(function (res) {
              unpre();
              if (!res.ok) { showErr('almMinError', (res.b && res.b.message) || 'No se pudo actualizar el stock mínimo.'); return; }
              almCerrar('almMinimoModal'); toast('Stock mínimo actualizado.'); almCargar();
          }).catch(function () { unpre(); showErr('almMinError', 'Error de red.'); });
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
        // El selector de frentes aplica a AMBOS tipos de almacén: la visibilidad
        // para los usuarios LOCAL se define por los frentes asociados, sea GENERAL
        // o PROYECTO (ver Almacen::visiblesPara). Antes se ocultaba para GENERAL.
        var wrap = el('almNvFrentesWrap');
        if (wrap) wrap.style.display = '';
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
        almOpen('almAlmacenModal'); setTimeout(function () { el('almNvNombre').focus(); }, 60);
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
        almNvTipoSelect(tipo, tipo === 'GENERAL' ? 'General (almacén central)' : 'Proyecto (Limitado a frentes específicos)');
        almNvSetFrentes(d.frentes || []);
        window.almToggleFrentes();
        almCerrar('almAdminAlmacenesModal');
        almOpen('almAlmacenModal'); setTimeout(function () { el('almNvNombre').focus(); }, 60);
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
        // Frentes para AMBOS tipos: la asociación define qué usuarios LOCAL ven el
        // almacén (ver Almacen::visiblesPara). Mínimo 1, sea GENERAL o PROYECTO.
        var frentes = [];
        almNvFrenteChecks().forEach(function (c) { if (c.checked) frentes.push(parseInt(c.value, 10)); });
        if (frentes.length === 0) { _fail('Selecciona al menos un frente.', 'almNvFrentesInput'); return; }
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
        almOpen('almAdminAlmacenesModal');
    };
    window.almEliminarAlmacen = function (id, nombre) {
        if (!ensurePerm(HAS_ALM_MANAGE, 'No tienes permiso para eliminar almacenes.')) return;
        almConfirm('¿Eliminar el almacén "<strong>' + nombre + '</strong>"? Si tiene movimientos registrados se desactivará en lugar de borrarse.', function () {
            pre();
            fetch(ROUTE_ALM_ITEM(id), { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
            .then(function (res) {
                unpre();
                if (!res.ok) { toast((res.b && res.b.message) || 'No se pudo eliminar.', 'error'); return; }
                toast(res.b.message || 'Almacén eliminado.');
                // Actualización EN SITIO — sin window.location. Antes se redirigía a
                // ROUTE_INDEX: eso disparaba el spinner de recarga total de la página
                // y, durante el delay de 500ms, se seguía viendo el almacén ya borrado.
                // 1) Quitar la fila del modal "Gestionar almacenes".
                var fila = document.querySelector('#almAdminAlmacenesModal .alm-admin-row[data-id="' + id + '"]');
                if (fila) fila.remove();
                var lista = document.querySelector('#almAdminAlmacenesModal .alm-admin-list');
                if (lista && !lista.querySelector('.alm-admin-row')) {
                    lista.innerHTML = '<p style="color:#94a3b8;font-size:13px;text-align:center;padding:20px 0;">No hay almacenes. Usa "Nuevo almacén" para crear el primero.</p>';
                }
                // 2) Quitar la opción del dropdown "Almacén" del header.
                var opt = document.querySelector('#almSelAlmacenDropdown .dropdown-item[data-value="' + id + '"]');
                if (opt) opt.remove();
                // 3) Si el almacén borrado era el filtro activo, limpiarlo para no
                //    pedirle al backend un id que ya no existe.
                var sel = el('almSelAlmacen');
                if (sel && String(sel.value) === String(id)) sel.value = '';
                // 4) Refrescar la tabla de inventario con el filtro vigente (AJAX).
                if (window.almCargar) window.almCargar();
            })
            .catch(function () { unpre(); toast('Error de red.', 'error'); });
        });
    };

    function almResetProductoModal() {
        delete el('almProductoModal').dataset.idProducto;
        delete el('almProductoModal').dataset.codOriginal;
        delete el('almProductoModal').dataset.ubicacion;
        el('almProdCodigo').value = ''; el('almProdNombre').value = ''; el('almProdUm').value = 'UND'; el('almProdCategoria').value = '';
        if (el('almProdCantInicial')) el('almProdCantInicial').value = '';
        var cs = el('almProdCatSuggest'); if (cs) cs.innerHTML = '';
        var us = el('almProdUmSuggestBox'); if (us) { us.innerHTML = ''; us.classList.remove('open'); }
        almProdCatHide();
        // Limpiar resaltados de error de todos los campos del modal
        almProdFieldErr('almProdCodigo',  false);
        almProdFieldErr('almProdNombre',  false);
        almProdFieldErr('almProdUm',      false);
        showErr('almProdError', '');
        // Reset de la lista de equivalencias (filtros).
        window._almProdEquivs = [];
        var _ei = el('almProdEquivInput'); if (_ei) _ei.value = '';
        almProdEquivRender();
        var _ew = el('almProdEquivWrap'); if (_ew) _ew.style.display = 'none';
    }
    // ── Equivalencias del filtro: lista editable dentro de "Editar producto" ──────────
    // Estado en memoria; se sincroniza al Guardar (updateProducto manda el conjunto completo).
    // Solo FILTROS y solo al EDITAR (para crear, primero se crea el filtro y luego se edita).
    window._almProdEquivs = [];
    function almProdEquivRender() {
        var box = el('almProdEquivList'); if (!box) return;
        if (!window._almProdEquivs.length) {
            box.innerHTML = '<span style="font-size:12px;color:#94a3b8;font-style:italic;">Sin equivalencias aún.</span>';
            return;
        }
        box.innerHTML = window._almProdEquivs.map(function (np, i) {
            var safe = String(np).replace(/&/g, '&amp;').replace(/</g, '&lt;');
            return '<span style="display:inline-flex;align-items:center;gap:6px;background:#f1f5f9;border:1px solid #e2e8f0;color:#334155;border-radius:14px;padding:3px 6px 3px 10px;font-size:12.5px;font-weight:600;">'
                 + safe
                 + '<button type="button" title="Quitar" onclick="window.almProdEquivRemove(' + i + ')" style="border:none;background:#e2e8f0;color:#475569;border-radius:50%;width:18px;height:18px;line-height:1;cursor:pointer;font-weight:700;padding:0;">&times;</button>'
                 + '</span>';
        }).join('');
    }
    window.almProdEquivAdd = function () {
        var inp = el('almProdEquivInput'); if (!inp) return;
        var np = (inp.value || '').trim();
        if (!np) { inp.focus(); return; }
        var norm = function (s) { return String(s).replace(/\s+/g, '').toUpperCase(); }; // sin distinguir may/espacios
        if (window._almProdEquivs.some(function (x) { return norm(x) === norm(np); })) {
            toast('Ese número de parte ya está en la lista.', 'error'); inp.select(); return;
        }
        window._almProdEquivs.push(np);
        almProdEquivRender();
        inp.value = ''; inp.focus();
    };
    window.almProdEquivRemove = function (i) {
        window._almProdEquivs.splice(i, 1);
        almProdEquivRender();
    };
    // Muestra la sección SOLO si se EDITA (idProducto) un producto de categoría FILTROS.
    window.almProdEquivSyncVisible = function () {
        var wrap = el('almProdEquivWrap'); if (!wrap) return;
        var editing = !!el('almProductoModal').dataset.idProducto;
        var esFiltro = esCatFiltro(val('almProdCategoria'));
        wrap.style.display = (editing && esFiltro) ? '' : 'none';
    };
    window.almAbrirProducto = function () {
        if (!ensurePerm(HAS_PRODUCTOS, 'No tienes permiso para crear productos.')) return;
        window.almDesdeDetalle = false;   // "Nuevo producto" NO viene de Detalles → Cancelar no regresa allí
        almResetProductoModal();
        el('almProdTitulo').textContent = 'Nuevo producto'; el('almProdSubmit').textContent = 'Guardar';
        el('almProdCodigo').readOnly = false; el('almProdCodigo').style.background = '';
        // Mostrar "Cantidad inicial" solo si hay un almacén seleccionado (el producto se
        // registrará en ese almacén). Si no hay, ocultamos el campo (no tiene sentido).
        var wrap = el('almProdCantInicialWrap');
        if (wrap) wrap.style.display = almSelAlmacenActual() ? '' : 'none';
        almOpen('almProductoModal'); setTimeout(function () { el('almProdCodigo').focus(); }, 60);
    };
    window.almEditarProducto = function (id, cod, nom, um, cat, ubicacion) {
        if (!ensurePerm(HAS_PRODUCTOS, 'No tienes permiso para editar productos.')) return;
        almResetProductoModal();
        el('almProductoModal').dataset.idProducto = id;
        el('almProductoModal').dataset.codOriginal = cod || ''; // para no exigir formato numérico si no cambia
        // La ubicación ya NO se edita en este modal (se movió a "Detalles del producto"),
        // pero almGuardarProducto la reenvía tal cual para no borrarla al editar otro campo.
        el('almProductoModal').dataset.ubicacion = ubicacion || '';
        el('almProdTitulo').textContent = 'Editar producto'; el('almProdSubmit').textContent = 'Guardar';
        // El código AHORA es editable también al editar: el backend valida unicidad
        // ignorando el propio producto (Rule::unique->ignore). Solo dígitos (igual que al crear).
        el('almProdCodigo').value = cod || ''; el('almProdCodigo').readOnly = false; el('almProdCodigo').style.background = '';
        el('almProdNombre').value = nom || ''; el('almProdUm').value = um || 'UND'; el('almProdCategoria').value = cat || '';
        // Cantidad inicial: solo aplica al CREAR. Al editar se oculta — el saldo se cambia
        // desde el modal de Ajuste / Entrada / Salida.
        var wrap = el('almProdCantInicialWrap'); if (wrap) wrap.style.display = 'none';
        // Equivalencias (filtros): se cargan desde la fila (data-equiv) y la sección se muestra
        // solo si el producto es FILTRO. La lista se sincroniza al Guardar.
        var trE = document.querySelector('tr.alm-row[data-id-producto="' + id + '"]');
        window._almProdEquivs = (trE && trE.dataset.equiv) ? trE.dataset.equiv.split('|').filter(Boolean) : [];
        almProdEquivRender();
        window.almProdEquivSyncVisible();
        almOpen('almProductoModal'); setTimeout(function () { el('almProdNombre').focus(); }, 60);
    };

    // ── Papelera de productos (eliminados / soft-delete): buscar + restaurar ──────
    var ROUTE_PROD_PAPELERA = ROUTE_INDEX + '/productos/papelera';
    function ROUTE_PROD_RESTAURAR(id) { return ROUTE_INDEX + '/productos/' + id + '/restaurar'; }
    function ROUTE_PROD_ELIMINAR_PERM(id) { return ROUTE_INDEX + '/productos/' + id + '/permanente'; }
    var _almPapeleraTimer = null;

    window.almAbrirPapelera = function () {
        if (!ensurePerm(HAS_NOTA_ELIMINAR, 'No tienes permiso para ver o restaurar productos eliminados.')) return;
        var inp = el('almPapeleraSearch'); if (inp) inp.value = '';
        almOpen('almPapeleraModal');
        window.almPapeleraBuscar();
        setTimeout(function () { if (inp) inp.focus(); }, 60);
    };

    window.almPapeleraBuscar = function () {
        clearTimeout(_almPapeleraTimer);
        _almPapeleraTimer = setTimeout(function () {
            var cont = el('almPapeleraLista'); if (!cont) return;
            var term = (el('almPapeleraSearch') ? el('almPapeleraSearch').value : '').trim();
            cont.innerHTML = '<div style="text-align:center;color:#94a3b8;font-size:13px;padding:24px 0;">Cargando…</div>';
            fetch(ROUTE_PROD_PAPELERA + (term ? ('?search=' + encodeURIComponent(term)) : ''), {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var rows = (data && data.productos) || [];
                if (!rows.length) {
                    cont.innerHTML = '<div style="text-align:center;color:#94a3b8;font-size:13px;padding:24px 0;">No hay productos eliminados' + (term ? ' que coincidan.' : '.') + '</div>';
                    return;
                }
                // Misma fila que "Gestionar almacenes" (.alm-admin-row): icono + bloque de
                // texto + botones SOLO icono (.alm-btn), estos apilados en columna. Código y
                // descripción van en la MISMA línea, sin negrita y en cuerpo chico, y la
                // descripción se muestra completa (envuelve en varias líneas si hace falta)
                // — antes se cortaba con puntos suspensivos.
                cont.innerHTML = rows.map(function (p) {
                    // escHtml en TODO: CODIGO/NOMBRE/UM/CATEGORIA son texto libre editable en el
                    // modal de producto. Sin escapar, una categoría tipo "<img src=x onerror=…>"
                    // ejecutaría script al verse en la papelera (XSS almacenado).
                    var cod = escHtml(p.CODIGO ? String(p.CODIGO) : '—');
                    var nom = escHtml(String(p.NOMBRE || ''));
                    var meta = escHtml((p.UM || '') + (p.CATEGORIA ? (' · ' + p.CATEGORIA) : ''));
                    // Código, descripción y unidad/categoría van con el MISMO cuerpo y el MISMO
                    // color: son datos del mismo producto y antes se veían en tres tonos y dos
                    // tamaños distintos (código gris, descripción oscura, meta aún más clara y
                    // pequeña), lo que hacía parecer que la última línea era menos fiable.
                    return '<div class="alm-admin-row">' +
                        '<i class="material-icons" style="font-size:18px;color:#94a3b8;flex:0 0 auto;">inventory_2</i>' +
                        '<div style="flex:1;min-width:0;font-size:12.5px;color:#1e293b;line-height:1.35;">' +
                            '<div>' + cod + ' ' + nom + '</div>' +
                            '<div>' + meta + '</div>' +
                        '</div>' +
                        '<div style="display:flex;flex-direction:column;gap:4px;flex:0 0 auto;">' +
                            '<button type="button" onclick="window.almRestaurarProducto(' + p.ID_PRODUCTO + ')" class="alm-btn alm-btn-restore" title="Restaurar">' +
                                '<i class="material-icons" style="font-size:16px;">restore</i></button>' +
                            // Borrado permanente: solo super.admin (HAS_ALM_MANAGE).
                            (HAS_ALM_MANAGE ? ('<button type="button" onclick="window.almEliminarPermanenteProducto(' + p.ID_PRODUCTO + ')" class="alm-btn alm-btn-del" title="Eliminar de la papelera (permanente)">' +
                                '<i class="material-icons" style="font-size:16px;">delete_forever</i></button>') : '') +
                        '</div>' +
                    '</div>';
                }).join('');
            })
            .catch(function () {
                cont.innerHTML = '<div style="text-align:center;color:#dc2626;font-size:13px;padding:24px 0;">No se pudo cargar la papelera.</div>';
            });
        }, 250);
    };

    window.almRestaurarProducto = function (id) {
        if (!ensurePerm(HAS_NOTA_ELIMINAR, 'No tienes permiso para restaurar productos.')) return;
        pre();
        fetch(ROUTE_PROD_RESTAURAR(id), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            unpre();
            if (res.ok) {
                toast(res.b.message || 'Producto restaurado.');
                window.almPapeleraBuscar();
                if (typeof window.almCargar === 'function') window.almCargar();

                if (res.b && res.b.producto && window.almProductosCargados && Array.isArray(window.almProductosLista)) {
                    var p = res.b.producto;
                    var ya = window.almProductosLista.some(function (x) { return String(x.ID_PRODUCTO) === String(p.ID_PRODUCTO); });
                    if (!ya) {
                        // Restaurar no trae los nºs de parte en la respuesta; quedan vacíos hasta el
                        // próximo F5 (la relación sigue en BD). La forma de la entry sí es completa.
                        window.almProductosLista.push(almProdEntry(p));
                    }
                }
            } else {
                toast((res.b && res.b.message) || 'No se pudo restaurar el producto.', 'error');
            }
        })
        .catch(function () { unpre(); toast('Error de red al restaurar.', 'error'); });
    };

    // Borrado PERMANENTE desde la papelera (forceDelete) — solo super.admin.
    window.almEliminarPermanenteProducto = function (id) {
        if (!ensurePerm(HAS_ALM_MANAGE, 'No tienes permiso para eliminar productos de la papelera.')) return;
        almConfirm('Vas a eliminar este producto <strong>de forma permanente</strong>. No se puede deshacer.', function () {
            pre();
            fetch(ROUTE_PROD_ELIMINAR_PERM(id), {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            })
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
            .then(function (res) {
                unpre();
                if (res.ok) {
                    toast(res.b.message || 'Producto eliminado permanentemente.');
                    window.almPapeleraBuscar();   // refresca la papelera (ya no aparece)
                } else {
                    toast((res.b && res.b.message) || 'No se pudo eliminar el producto.', 'error');
                }
            })
            .catch(function () { unpre(); toast('Error de red al eliminar.', 'error'); });
        });
    };

    window.almGuardarProducto = function () {
        if (!ensurePerm(HAS_PRODUCTOS, 'No tienes permiso para guardar productos.')) return;
        var m = el('almProductoModal'), id = m.dataset.idProducto || null;
        // Sin botón "Agregar": si quedó un nº de parte escrito (sin Enter) en la sección de
        // equivalencias visible, recógelo antes de guardar para no perderlo.
        var _eqW = el('almProdEquivWrap'), _eqI = el('almProdEquivInput');
        if (_eqW && _eqW.style.display !== 'none' && _eqI && _eqI.value.trim() && typeof window.almProdEquivAdd === 'function') {
            window.almProdEquivAdd();
        }
        var codigo = val('almProdCodigo'), nombre = val('almProdNombre'), um = val('almProdUm') || 'UND', cat = val('almProdCategoria');
        // La ubicación ya no se edita aquí (ver "Detalles del producto"): al crear no hay
        // ninguna todavía; al editar viajó en el dataset (almEditarProducto) para no perderla.
        var ubicacion = m.dataset.ubicacion || '';
        // Validaciones previas al envío.
        if (!nombre) { almProdFieldErr('almProdNombre', true); showErr('almProdError', 'La descripción es obligatoria.'); return; }
        // El código debe ser solo dígitos enteros positivos — PERO solo si es nuevo o cambió.
        // Al editar un producto con código legacy no numérico (ej. filtro "FIL-003") sin tocarlo,
        // se permite (el backend conserva el actual). codOriginal = código con que se abrió el modal.
        var codOriginal = m.dataset.codOriginal || '';
        if (codigo && codigo !== codOriginal && (!/^\d+$/.test(codigo) || parseInt(codigo, 10) < 1)) {
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
                // Al editar: ahora SÍ se incluye CODIGO (editable). Si va vacío, el backend
                // conserva el actual (updateProducto hace unset cuando llega vacío). En FILTROS
                // se manda la lista COMPLETA de equivalencias para sincronizarla en el backend.
                ? Object.assign(
                    { CODIGO: codigo || null, NOMBRE: nombre, UM: um, CATEGORIA: cat || null, UBICACION: ubicacion || null },
                    esCatFiltro(cat) ? { equivalencias: window._almProdEquivs } : {}
                  )
                // Al crear: CODIGO + opcionalmente id_almacen + cantidad_inicial para asegurar/abrir la fila en el almacén actual.
                : bodyCreate
            )
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            unpre();
            if (res.ok) {
                almCerrar('almProductoModal');
                toast(res.b.message || (id ? 'Producto actualizado.' : 'Producto creado.'));

                // FILTROS editados: refleja las equivalencias en la fila (data-equiv) para que el
                // modal de Detalles y el tooltip queden consistentes sin recargar. La descripción
                // visible de la tabla se rehace al recargar/filtrar.
                if (id && esCatFiltro(cat)) {
                    var trU = document.querySelector('tr.alm-row[data-id-producto="' + id + '"]');
                    if (trU) trU.dataset.equiv = window._almProdEquivs.join('|');
                }

                // Sincronizar window.almProductosLista (cache en memoria que usa el dropdown
                // de sugerencias) para que el producto nuevo / editado aparezca en la busqueda
                // sin tener que recargar la pestaña. Antes: el producto recien creado solo
                // aparecia tras un F5 porque la lista se cargaba 1 vez al render del server.
                if (res.b && res.b.producto && window.almProductosCargados && Array.isArray(window.almProductosLista)) {
                    var p = res.b.producto;
                    // Filtro editado → sus nºs de parte están en _almProdEquivs (el modal). Para
                    // no-filtros o creación, parts queda vacío. Así la entry cacheada conserva
                    // CATEGORIA y equivalencias y la búsqueda sigue funcionando sin recargar.
                    var equivs = esCatFiltro(p.CATEGORIA) && Array.isArray(window._almProdEquivs)
                        ? window._almProdEquivs : [];
                    var entry = almProdEntry(p, equivs);
                    if (id) {
                        // EDICION: reemplazar la entry existente
                        var idx = window.almProductosLista.findIndex(function (x) {
                            return String(x.ID_PRODUCTO) === String(p.ID_PRODUCTO);
                        });
                        if (idx !== -1) window.almProductosLista[idx] = entry;
                    } else {
                        // CREACION: agregar al final
                        window.almProductosLista.push(entry);
                    }
                }

                almCargar();
            }
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
        if (!ensurePerm(HAS_NOTA_ELIMINAR, 'No tienes permiso para eliminar productos.')) return;
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
        almOpen('almSalidaModal');
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

    // ── Vista previa del PDF en TELÉFONO con PDF.js ─────────────────────────────
    // Los navegadores móviles no renderizan PDF embebido en <iframe>. Para que el
    // usuario VEA el diseño de la Nota en el teléfono, dibujamos el PDF en <canvas>
    // con PDF.js. La librería (UMD, vendorizada local — antes jsdelivr) se carga
    // solo la 1ª vez que se usa, así no penaliza la carga normal del módulo.
    var _almPdfJsPromise = null;
    function almEnsurePdfJs() {
        if (window.pdfjsLib) return Promise.resolve();
        if (_almPdfJsPromise) return _almPdfJsPromise;
        _almPdfJsPromise = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            // Versión EN el nombre del archivo: nginx sirve /js/* con caché
            // inmutable de 1 año, así que al actualizar la librería hay que
            // renombrar ambos archivos (lib y worker SIEMPRE de la misma versión).
            s.src = '/js/vendor/pdf-3.11.174.min.js';
            s.onload = function () {
                try { window.pdfjsLib.GlobalWorkerOptions.workerSrc = '/js/vendor/pdf.worker-3.11.174.min.js'; } catch (e) {}
                resolve();
            };
            s.onerror = function () { _almPdfJsPromise = null; reject(new Error('No se pudo cargar el visor de PDF.')); };
            document.head.appendChild(s);
        });
        return _almPdfJsPromise;
    }
    // Teléfono o tablet: ahí el iframe falla, usamos canvas.
    function almEsMovil() {
        return window.innerWidth <= 768 || /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
    }
    // Dibuja el blob PDF en #almPreviewCanvas (una <canvas> por página, ajustada al ancho).
    function almRenderPdfCanvas(blob) {
        var cont = el('almPreviewCanvas');
        if (!cont) return Promise.resolve();
        cont.innerHTML = '<div style="color:#cbd5e0;text-align:center;padding:30px;font-size:13px;">Cargando vista previa…</div>';
        return almEnsurePdfJs()
            .then(function () { return blob.arrayBuffer(); })
            .then(function (buf) { return window.pdfjsLib.getDocument({ data: buf }).promise; })
            .then(function (pdf) {
                cont.innerHTML = '';
                var dpr = window.devicePixelRatio || 1;
                var ancho = cont.clientWidth - 20; // descontar el padding del contenedor
                if (ancho <= 0) ancho = Math.min(window.innerWidth - 40, 900);
                var seq = Promise.resolve();
                for (var i = 1; i <= pdf.numPages; i++) {
                    (function (n) {
                        seq = seq.then(function () {
                            return pdf.getPage(n).then(function (page) {
                                var base = page.getViewport({ scale: 1 });
                                var vp = page.getViewport({ scale: (ancho / base.width) * dpr });
                                var canvas = document.createElement('canvas');
                                canvas.width = vp.width; canvas.height = vp.height;
                                canvas.style.width = '100%'; canvas.style.height = 'auto';
                                canvas.style.display = 'block'; canvas.style.margin = '0 auto 10px';
                                canvas.style.background = '#fff'; canvas.style.boxShadow = '0 1px 6px rgba(0,0,0,0.25)';
                                cont.appendChild(canvas);
                                return page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
                            });
                        });
                    })(i);
                }
                return seq;
            })
            .catch(function (e) {
                cont.innerHTML = '<div style="color:#fecaca;text-align:center;padding:30px;font-size:13px;">No se pudo mostrar la vista previa. ' + (e && e.message ? e.message : '') + '</div>';
            });
    }

    // Construye el payload desde los campos del modal + almSeleccion. Devuelve
    // null y muestra error si falta el frente destino o no hay lineas validas.
    // Lo usan almSalidaVistaPrevia (POST a preview) y almPreviewConfirmar (POST
    // a lote real) — separado para garantizar consistencia: el PDF preview y el
    // registro final se generan a partir del MISMO payload.
    function almSalidaConstruirPayload() {
        var v = function (id) { var e = el(id); return e ? e.value.trim() : ''; };
        var idFrenteDest = v('almSalidaProyecto');
        if (!idFrenteDest) { showErr('almSalidaError', 'Elige el proyecto / frente destino.'); return null; }
        // Mismo criterio que la validación previa a abrir el modal (ver almSelAccion):
        // "sin cantidad + sin saldo" se reporta como stock insuficiente, no como un olvido.
        var lineas = [], faltan = [], sinSaldo = [];
        Object.keys(almSeleccion).forEach(function (id) {
            var s   = almSeleccion[id] || {};
            var raw = String(s.cantidad == null ? '' : s.cantidad).replace(',', '.').trim();
            var c   = parseFloat(raw);
            var nombre = s.nombre || ('#' + id);
            if (!isFinite(c) || c <= 0) ((parseFloat(s.saldo) || 0) <= 0 ? sinSaldo : faltan).push(nombre);
            else lineas.push({ id_producto: parseInt(id, 10), cantidad: c, numero_parte: s.parte || null });
        });
        var listar = function (arr) { return arr.slice(0, 4).join(', ') + (arr.length > 4 ? '…' : ''); };
        if (sinSaldo.length) { showErr('almSalidaError', 'Stock insuficiente de: ' + listar(sinSaldo) + '.'); return null; }
        if (!lineas.length) { showErr('almSalidaError', 'Indica una cantidad mayor que cero en al menos un producto (columna "Salida" de la tabla).'); return null; }
        if (faltan.length)  { showErr('almSalidaError', 'Falta indicar la cantidad de salida (debe ser mayor que cero) en: ' + listar(faltan) + '. Corrígelos en la tabla o deselecciónalos.'); return null; }
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
            var cont  = el('almPreviewCanvas');
            // Ocultar el modal de salida (sin destruir sus inputs — almCerrar solo
            // quita .open, los valores quedan listos para "Editar") y abrir el preview
            // ANTES de renderizar, para que el canvas mida bien el ancho disponible.
            almCerrar('almSalidaModal');
            almOpen('almPreviewModal');
            if (almEsMovil()) {
                // TELÉFONO: el iframe no muestra PDF → lo dibujamos con PDF.js en canvas.
                if (frame) { frame.src = 'about:blank'; frame.style.display = 'none'; }
                if (cont)  { cont.style.display = 'block'; }
                almRenderPdfCanvas(blob);
            } else {
                // ESCRITORIO: visor nativo del navegador en el iframe. Mostramos "Cargando…"
                // en el contenedor del canvas (oculto en escritorio) HASTA que el iframe dispare
                // load — antes el spinner global se apagaba al llegar el blob y el iframe quedaba
                // en blanco unos instantes mientras el navegador renderiza el PDF (mismo criterio
                // que el visor del acta). Fallback a 8s por si onload no dispara.
                if (cont) {
                    cont.style.display = 'flex';
                    cont.style.alignItems = 'center';
                    cont.style.justifyContent = 'center';
                    cont.style.color = '#cbd5e0';
                    cont.innerHTML = '<div style="text-align:center;font-size:13px;"><i class="material-icons" style="font-size:34px;animation:spin 1s linear infinite;display:block;margin:0 auto 8px;">sync</i>Cargando vista previa…</div>';
                }
                if (frame) {
                    frame.style.display = 'none';
                    frame.onload = function () {
                        if ((frame.src || '').indexOf('about:blank') !== -1) return;
                        if (cont) { cont.style.display = 'none'; cont.innerHTML = ''; }
                        frame.style.display = '';
                    };
                    frame.src = almPreviewBlobUrl + '#toolbar=0&navpanes=0&scrollbar=0&view=FitH';
                    setTimeout(function () {
                        if (cont && cont.style.display !== 'none') { cont.style.display = 'none'; cont.innerHTML = ''; }
                        frame.style.display = '';
                    }, 8000);
                }
            }
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
        almOpen('almSalidaModal');
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
        var cont = el('almPreviewCanvas'); if (cont) { cont.innerHTML = ''; cont.style.display = 'none'; }
        almOpen('almSalidaModal');
    };

    // "Registrar" del preview: POSTea el draft al endpoint real (movimientos-lote)
    // que SI guarda en BD, asigna NUMERO_NOTA y devuelve la URL del PDF final.
    // Tras el commit, descargamos el PDF al disco (fetch → blob → anchor) y
    // dejamos al usuario en el modulo de inventario — NO abrimos visor in-page
    // (el flujo ya pidio aprobacion en el modal #almPreviewModal). El payload
    // viene del draft sin reconstruir, asi el PDF final = exactamente lo aprobado.
    window.almPreviewConfirmar = function () {
        if (!almSalidaDraft) { toast('Sin datos para registrar — vuelve a "Editar" y aprieta "Vista previa".', 'error'); return; }
        // Anti doble-submit: si ya hay un registro en curso, no dispares otro (evita movimiento
        // + Nota de Entrega DUPLICADOS). Antes solo lo tapaba el preloader; ahora es explícito,
        // igual que "Registrar entrada" de recepción. Se libera en el .finally.
        if (window._almConfirmando) return;
        window._almConfirmando = true;
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
        })
        .finally(function () { window._almConfirmando = false; }); // libera la guarda anti doble-submit
    };
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

    // ── Posicion de Consolidado + "En otros almacenes" en mobile ─────────────
    // Default DOM (desktop): .counter-sidebar es sibling del .admin-card dentro
    // de .page-layout-grid. Contiene el Consolidado (totales) Y el #almDistWrapper
    // (panel "En otros almacenes" / chart de categorias).
    //
    // Mobile: el cliente quiere los DOS bloques en posiciones DISTINTAS:
    //   - Consolidado: debajo del boton Acciones (despues de #almFilters), arriba
    //     de la tabla. Movemos la .counter-sidebar entera ahi (el #almDistWrapper
    //     hijo se saca antes para no arrastrarlo).
    //   - #almDistWrapper (En otros almacenes): al FINAL de la tabla, separado.
    //     Anchor: #almLoadingMore (vive justo despues del .alm-table-wrap).
    //
    // Desktop: restaurar el #almDistWrapper DENTRO de .counter-sidebar y la
    // sidebar al .page-layout-grid — vuelve al layout original.
    (function placeSidebarMobile() {
        // 1024px: MISMO corte que el colapso a 1 columna de .page-layout-grid y que
        // la regla CSS que muestra/apila el Consolidado (ver el @media de arriba en
        // este archivo). Antes era 768 — entre 769 y 1024px el grid ya colapsaba
        // pero el JS no reubicaba nada, así que el Consolidado quedaba flotando en
        // su posición de grid por defecto (debajo de la tabla).
        var BREAKPOINT = 1024;
        function place() {
            var sidebar  = document.querySelector('.counter-sidebar');
            var distWrap = document.getElementById('almDistWrapper');
            var filters  = document.getElementById('almFilters');
            var anchor   = document.getElementById('almLoadingMore');
            var grid     = document.querySelector('.page-layout-grid');
            if (!sidebar || !filters || !grid) return;
            if (window.innerWidth <= BREAKPOINT) {
                // Sacar el #almDistWrapper de la sidebar (si aun esta dentro) y
                // anclarlo despues de la tabla.
                if (distWrap && anchor) {
                    if (distWrap.previousElementSibling !== anchor) {
                        anchor.parentNode.insertBefore(distWrap, anchor.nextSibling);
                    }
                }
                // Sidebar (ahora solo con el Consolidado) → despues de #almFilters
                if (sidebar.previousElementSibling !== filters) {
                    filters.parentNode.insertBefore(sidebar, filters.nextSibling);
                }
            } else {
                // Desktop: restaurar el #almDistWrapper dentro de la sidebar.
                if (distWrap && sidebar && distWrap.parentNode !== sidebar) {
                    sidebar.appendChild(distWrap);
                }
                // Y la sidebar como sibling del admin-card.
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
        var bar = document.getElementById('almBulkBar');
        if (!vv || !bar) return; // navegador viejo sin visualViewport → comportamiento previo
        function ajustarBarra() {
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
@endsection
