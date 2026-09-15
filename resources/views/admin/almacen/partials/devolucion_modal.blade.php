{{--
    Modal "Devolución de material" — devolver lo entregado con una Nota de Entrega. Lo incluye
    el Historial de Movimientos (/admin/almacen/movimientos): el botón "Devolver" de cada
    salida con nota lo abre con esa nota y ese producto listos.

    Solo DEVUELVE: si además hay que entregar otra cosa (otra talla), es una salida normal con
    su propia Nota, desde el inventario.

    Solo para quien tiene almacen.movimiento: la devolución mueve stock (la ruta POST lo
    vuelve a comprobar). El comportamiento vive en js/maquinaria/devolucion_material.js y
    se descarga la primera vez que se abre; la lógica, en App\Services\DevolucionService.
--}}
@can('almacen.movimiento')
<style>
    #devMatModal { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.45); z-index:10000; align-items:center; justify-content:center; padding:16px; }
    #devMatModal.open { display:flex; }
    #devMatModal .devm-box { background:#fff; border-radius:16px; width:100%; max-width:440px; max-height:90vh; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); display:flex; flex-direction:column; overflow:hidden; }
    /* Encabezado de los modales del módulo (ver .alm-modal-head en almacen/index). */
    #devMatModal .devm-head { padding:14px 48px; background:#1e293b; display:flex; align-items:center; justify-content:center; position:relative; flex-shrink:0; }
    #devMatModal .devm-head h3 { margin:0; font-size:15px; font-weight:800; color:#fff; display:flex; align-items:center; gap:8px; }
    #devMatModal .devm-head h3 .material-icons { color:#fff; }
    #devMatModal .devm-x { position:absolute; right:14px; top:50%; transform:translateY(-50%); cursor:pointer; color:#fff; opacity:.75; background:none; border:none; padding:0; display:flex; }
    #devMatModal .devm-x:hover { opacity:1; }
    #devMatModal .devm-body { padding:16px 20px 20px; display:flex; flex-direction:column; gap:12px; overflow-y:auto; min-height:0; }
    #devMatModal .devm-foot { padding:12px 18px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:center; gap:8px; flex-shrink:0; }
    /* Mismo tamaño que los botones del pie de los modales de /admin/almacen (.alm-modal-foot). */
    #devMatModal .devm-foot .btn-primary-maquinaria { padding:8px 20px; border-radius:10px; }
    #devMatModal .devm-foot .btn-primary-maquinaria:disabled { opacity:.5; cursor:not-allowed; }
    #devMatModal .devm-btn-cancelar { background:#e2e8f0; color:#475569; box-shadow:none; }

    #devMatModal label.devm-label { font-size:13px; font-weight:500; color:#0f172a; display:block; margin-bottom:5px; }
    #devMatModal .devm-input { width:100%; border:1px solid #cbd5e0; border-radius:8px; padding:9px 10px; font:inherit; font-size:13px; outline:none; box-sizing:border-box; background:#fff; color:#0f172a; }
    #devMatModal .devm-input:focus { border-color:var(--maquinaria-blue,#0067b1); }

    #devMatModal .devm-msg { padding:9px 12px; border-radius:8px; font-size:13px; font-weight:500; line-height:1.4; }
    #devMatModal .devm-msg.error { background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; }
    #devMatModal .devm-msg.info  { background:#e0f2fe; color:#075985; border:1px solid #bae6fd; }

    /* Cabecera en UNA línea: "NE-2026-0604 · 15/09/2026 · ASIGNACION PDVSA DAL". Si el
       proyecto es muy largo se corta con "…" y el texto completo queda en el title. */
    #devMatModal .devm-nota { padding:0 2px 12px; border-bottom:1px solid #e2e8f0; font-size:14px; font-weight:500; color:#0f172a; line-height:1.4;
        white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    #devMatModal .devm-nota-num { font-weight:700; letter-spacing:.2px; }
    /* El punto que separa datos va en gris: separa, no se lee. */
    #devMatModal .devm-sep { color:#94a3b8; }

    /* Cada producto: el nombre como título y, debajo, dos columnas: a la izquierda lo
       entregado y lo que falta, a la derecha cuánto vuelve. El campo queda al lado de las
       cifras que lo explican, no solo en una fila aparte. */
    #devMatModal .devm-lineas { display:flex; flex-direction:column; max-height:300px; overflow-y:auto; }
    #devMatModal .devm-linea { display:flex; flex-direction:column; gap:10px; padding:14px 2px; color:#0f172a; }
    #devMatModal .devm-linea + .devm-linea { border-top:1px solid #e2e8f0; }
    #devMatModal .devm-linea.cerrada { opacity:.75; }
    #devMatModal .devm-prod-cab { font-size:14.5px; font-weight:700; line-height:1.35; letter-spacing:.1px; overflow-wrap:anywhere; }
    #devMatModal .devm-cuerpo { display:grid; grid-template-columns:minmax(0, 1fr) auto; align-items:end; gap:12px; }
    #devMatModal .devm-datos { display:flex; flex-direction:column; gap:4px; font-size:13px; font-weight:500; line-height:1.35; }
    #devMatModal .devm-datos b { font-weight:700; }

    /* Cuánto vuelve: el rótulo encima y el campo de ANCHO FIJO con la unidad dentro. */
    #devMatModal .devm-devuelve { display:flex; flex-direction:column; gap:4px; }
    #devMatModal .devm-devuelve-lbl { font-size:13px; font-weight:500; }
    #devMatModal .devm-cant { display:flex; align-items:stretch; width:150px; height:38px; border:1px solid #cbd5e1; border-radius:8px;
        background:#fff; overflow:hidden; transition:border-color .15s, box-shadow .15s; }
    #devMatModal .devm-cant:focus-within { border-color:#0067b1; box-shadow:0 0 0 3px rgba(0,103,177,.12); }
    #devMatModal .devm-cant .devm-input { flex:1 1 auto; width:auto; min-width:0; height:100%; border:none; border-radius:0; padding:0 8px;
        text-align:center; font-size:15px; font-weight:700; font-variant-numeric:tabular-nums; }
    /* El "0" de ejemplo en gris claro: en el gris del navegador parecía una cantidad escrita. */
    #devMatModal .devm-cant .devm-input::placeholder { color:#cbd5e1; font-weight:500; }
    #devMatModal .devm-de { display:flex; align-items:center; padding:0 10px; background:#f8fafc; border-left:1px solid #e2e8f0;
        font-size:13px; font-weight:600; white-space:nowrap; }
    /* Producto ya devuelto entero: en la columna de la derecha, el aviso. */
    #devMatModal .devm-cerrada { display:flex; align-items:center; gap:6px; font-size:13px; font-weight:600; }
    #devMatModal .devm-cerrada .material-icons { font-size:18px; color:#16a34a; }

    #devMatModal .devm-motivo { margin-top:12px; }
    #devMatModal .devm-historial { font-size:13px; font-weight:500; color:#0f172a; border-top:1px dashed #e2e8f0; padding-top:10px; margin-top:12px; }
    #devMatModal .devm-historial .devm-quien { color:#0f172a; }
    #devMatModal .devm-historial b { color:#0f172a; }
    #devMatModal .devm-historial ul { margin:6px 0 0; padding-left:18px; display:flex; flex-direction:column; gap:3px; }

    @media (max-width: 640px) {
        #devMatModal { padding:8px; }
        #devMatModal .devm-body { padding:12px; }
    }
</style>

<div id="devMatModal"
     data-url-show="{{ route('almacen.devolucion.show') }}"
     data-url-store="{{ route('almacen.devolucion.store') }}"
     onclick="if (event.target === this) window.DevolucionMaterial.cerrar();">
    <div class="devm-box" role="dialog" aria-modal="true" aria-labelledby="devMatTitulo">
        <div class="devm-head">
            <h3 id="devMatTitulo"><i class="material-icons">assignment_return</i>Devolución de material</h3>
            <button type="button" class="devm-x" aria-label="Cerrar" onclick="window.DevolucionMaterial.cerrar()"><i class="material-icons">close</i></button>
        </div>
        <div class="devm-body">
            <div id="devMatMsg" class="devm-msg" hidden></div>

            <div id="devMatContenido" hidden>
                <div id="devMatNota" class="devm-nota"></div>
                <div id="devMatLineas" class="devm-lineas"></div>
                {{-- Sin fecha: la devolución queda con la de hoy (DevolucionService). --}}
                <div class="devm-motivo">
                    <label class="devm-label" for="devMatMotivo">Motivo (opcional)</label>
                    <input type="text" id="devMatMotivo" class="devm-input" maxlength="150" autocomplete="off">
                </div>
                <div id="devMatHistorial" class="devm-historial" hidden></div>
            </div>
        </div>
        <div class="devm-foot">
            <button type="button" class="btn-primary-maquinaria devm-btn-cancelar" onclick="window.DevolucionMaterial.cerrar()">Cancelar</button>
            <button type="button" id="devMatGuardar" class="btn-primary-maquinaria" disabled>Registrar</button>
        </div>
    </div>
</div>

<script>
    // Abre el modal; el módulo (devolucion_material.js) se descarga la primera vez. Se
    // redefine en cada montaje SPA a propósito: es un envoltorio sin estado.
    window.almAbrirDevolucion = function (numero, idProducto) {
        {{-- ?v= obligatorio: nginx sirve /js con Cache-Control immutable. --}}
        window.cargarScriptUnaVez(
            "{{ asset('js/maquinaria/devolucion_material.js') . '?v=' . @filemtime(public_path('js/maquinaria/devolucion_material.js')) }}",
            function () { return !!window.DevolucionMaterial; }
        ).then(function () {
            window.DevolucionMaterial.abrir(numero, idProducto);
        }).catch(function () {
            window.toast('No se pudo abrir la devolución. Revisa tu conexión.', 'error');
        });
    };
</script>
@endcan
