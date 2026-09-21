{{--
    Modal "Devolución de material" — devolver lo entregado con una Nota de Entrega. Lo incluye
    el Historial de Movimientos (/admin/almacen/movimientos): el botón "Devolver" de cada
    salida con nota lo abre con esa nota y ese producto listos.

    Solo DEVUELVE: si además hay que entregar otra cosa (otra talla), es una salida normal con
    su propia Nota, desde el inventario.

    Mismo lenguaje visual que "Vincular a una ficha" (equipos/partials/vincular_ficha_modal):
    caja de 440 px, cada producto en su tarjeta con icono y un solo campo por producto.

    Solo para quien tiene almacen.movimiento: la devolución mueve stock (la ruta POST lo
    vuelve a comprobar). El comportamiento vive en js/maquinaria/devolucion_material.js y
    se descarga la primera vez que se abre; la lógica, en App\Services\DevolucionService.
--}}
@can('almacen.movimiento')
<style>
    #devMatModal { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); backdrop-filter:blur(2px); z-index:10000; align-items:center; justify-content:center; padding:16px; }
    #devMatModal.open { display:flex; }
    #devMatModal .devm-box { background:#fff; border-radius:16px; width:100%; max-width:400px; max-height:90vh; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); display:flex; flex-direction:column; overflow:hidden; color:#0f172a; }
    #devMatModal .devm-head { padding:11px 44px; background:#1e293b; display:flex; align-items:center; justify-content:center; position:relative; flex-shrink:0; }
    #devMatModal .devm-head h3 { margin:0; font-size:15px; font-weight:700; color:#fff; display:flex; align-items:center; gap:10px; }
    #devMatModal .devm-head h3 .material-icons { color:#fff; font-size:20px; }
    #devMatModal .devm-x { position:absolute; right:15px; top:50%; transform:translateY(-50%); cursor:pointer; color:#fff; opacity:.75; background:none; border:none; padding:0; display:flex; }
    #devMatModal .devm-x:hover { opacity:1; }
    #devMatModal .devm-body { padding:12px 14px 14px; display:flex; flex-direction:column; gap:10px; overflow-y:auto; min-height:0; }
    #devMatModal .devm-contenido { display:flex; flex-direction:column; gap:14px; }
    #devMatModal .devm-contenido[hidden] { display:none; }

    #devMatModal .devm-msg { padding:9px 12px; border-radius:10px; font-size:13px; font-weight:500; line-height:1.45; }
    #devMatModal .devm-msg b { font-weight:700; }
    #devMatModal .devm-msg.error { background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; }
    #devMatModal .devm-msg.info  { background:#e0f2fe; color:#075985; border:1px solid #bae6fd; }

    /* De qué nota viene: una sola línea con su icono. Si el proyecto no cabe se corta con "…"
       y el texto entero queda en el title. */
    /* Centrada: es el encabezado de la ficha (de qué nota se devuelve), no una lista. */
    #devMatModal .devm-nota { display:flex; align-items:center; justify-content:center; gap:8px; min-width:0; font-size:13px; font-weight:500; }
    #devMatModal .devm-nota .material-icons { font-size:18px; color:#0067b1; flex-shrink:0; }
    #devMatModal .devm-nota-txt { min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    #devMatModal .devm-nota-num { font-weight:700; }
    #devMatModal .devm-sep { color:#94a3b8; }

    /* Cada producto en su tarjeta: arriba qué es y cuánto se entregó; abajo, separado por una
       raya, el campo de cuánto vuelve. Sin marco extra alrededor ni resaltado de la tarjeta al
       escribir: el azul del foco va solo en el campo (tres marcos anidados se veían recargados). */
    #devMatModal .devm-lineas { display:flex; flex-direction:column; gap:8px; max-height:320px; overflow-y:auto; }
    #devMatModal .devm-linea { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:10px 12px; display:flex; flex-direction:column; gap:8px; box-shadow:0 1px 2px rgba(15,23,42,.05); }
    #devMatModal .devm-linea.cerrada { opacity:.8; }
    #devMatModal .devm-prod-cab { display:flex; align-items:center; gap:12px; min-width:0; }
    #devMatModal .devm-foto { width:52px; height:52px; flex-shrink:0; border-radius:8px; background:#eff6ff; display:flex; align-items:center; justify-content:center; }
    #devMatModal .devm-foto .material-icons { font-size:26px; color:#0067b1; }
    #devMatModal .devm-info { flex:1; min-width:0; display:flex; flex-direction:column; gap:2px; }
    #devMatModal .devm-titulo { font-size:14px; font-weight:700; line-height:1.3; overflow-wrap:anywhere; }
    #devMatModal .devm-datos { font-size:12.5px; font-weight:500; }
    #devMatModal .devm-datos b { font-weight:700; }

    /* Campos: la misma caja para la cantidad y el motivo. */
    #devMatModal label.devm-label, #devMatModal .devm-devuelve-lbl { display:block; font-size:12.5px; font-weight:600; }
    #devMatModal label.devm-label { margin-bottom:6px; }
    #devMatModal .devm-input { width:100%; box-sizing:border-box; height:34px; border:1.5px solid #cbd5e1; border-radius:8px; padding:0 12px; font:inherit; font-size:13px; color:#0f172a; background:#fff; outline:none; transition:border-color .15s; }
    #devMatModal .devm-input:focus { border-color:#0067b1; }
    #devMatModal .devm-input::placeholder { color:#94a3b8; }

    /* Cuánto vuelve: el campo a todo lo ancho de la tarjeta y la unidad pegada a la derecha. */
    #devMatModal .devm-devuelve { display:flex; flex-direction:column; gap:5px; padding-top:8px; border-top:1px solid #f1f5f9; }
    #devMatModal .devm-cant { display:flex; align-items:stretch; box-sizing:border-box; height:36px; border:1.5px solid #cbd5e1; border-radius:8px; background:#fff; overflow:hidden; transition:border-color .15s; }
    #devMatModal .devm-cant:focus-within { border-color:#0067b1; }
    #devMatModal .devm-cant .devm-input { flex:1; min-width:0; height:100%; border:none; border-radius:0; padding:0 12px; text-align:center; font-size:15px; font-weight:700; font-variant-numeric:tabular-nums; }
    #devMatModal .devm-cant .devm-input::placeholder { color:#cbd5e1; font-weight:600; }
    #devMatModal .devm-de { display:flex; align-items:center; padding:0 14px; background:#f8fafc; border-left:1px solid #e2e8f0; font-size:13px; font-weight:700; white-space:nowrap; }
    /* Producto ya devuelto entero: en vez del campo, el aviso. */
    #devMatModal .devm-cerrada { display:flex; align-items:center; gap:6px; padding-top:12px; border-top:1px solid #f1f5f9; font-size:13px; font-weight:600; }
    #devMatModal .devm-cerrada .material-icons { font-size:18px; color:#16a34a; }

    #devMatModal .devm-historial { font-size:12.5px; font-weight:500; }
    #devMatModal .devm-historial b { font-weight:700; }
    #devMatModal .devm-historial ul { margin:6px 0 0; padding-left:18px; display:flex; flex-direction:column; gap:3px; }

    /* Pie: dos botones del mismo ancho. Clases propias (no .btn-primary-maquinaria) para que
       los estilos globales de botón no les cambien el tamaño. */
    #devMatModal .devm-foot { padding:12px 14px; border-top:1px solid #e2e8f0; background:#fff; display:flex; gap:10px; flex-shrink:0; }
    #devMatModal .devm-foot button { flex:1; height:42px; border-radius:10px; font:inherit; font-size:14px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; gap:6px; cursor:pointer; transition:background .15s; }
    #devMatModal .devm-foot .material-icons { font-size:18px; }
    #devMatModal .devm-btn-cancelar { background:#fff; color:#0f172a; border:1.5px solid #cbd5e1; }
    #devMatModal .devm-btn-cancelar:hover { background:#f8fafc; }
    #devMatModal .devm-btn-registrar { background:#0067b1; color:#fff; border:1.5px solid #0067b1; }
    #devMatModal .devm-btn-registrar:hover:not(:disabled) { background:#005a9e; border-color:#005a9e; }
    #devMatModal .devm-btn-registrar:disabled { background:#94a3b8; border-color:#94a3b8; cursor:not-allowed; }
    #devMatModal .devm-foot button:focus-visible { outline:2px solid #0067b1; outline-offset:2px; }

    @media (max-width: 640px) {
        #devMatModal { padding:8px; }
        #devMatModal .devm-body { padding:10px 12px 12px; }
        #devMatModal .devm-foot { padding:10px 12px; }
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

            <div id="devMatContenido" class="devm-contenido" hidden>
                <div id="devMatNota" class="devm-nota"></div>
                <div id="devMatLineas" class="devm-lineas"></div>
                {{-- Sin fecha: la devolución queda con la de hoy (DevolucionService). --}}
                <div class="devm-motivo">
                    <label class="devm-label" for="devMatMotivo">Motivo (opcional)</label>
                    <input type="text" id="devMatMotivo" class="devm-input" maxlength="150" autocomplete="off" placeholder="Ej.: sobró en la obra">
                </div>
                <div id="devMatHistorial" class="devm-historial" hidden></div>
            </div>
        </div>
        <div class="devm-foot">
            <button type="button" class="devm-btn-cancelar" onclick="window.DevolucionMaterial.cerrar()">Cancelar</button>
            <button type="button" id="devMatGuardar" class="devm-btn-registrar" disabled><i class="material-icons">assignment_return</i>Registrar</button>
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
