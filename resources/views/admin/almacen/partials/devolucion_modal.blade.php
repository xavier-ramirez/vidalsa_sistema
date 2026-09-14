{{--
    Modal "Devolución de material" — devolver lo entregado con una Nota de Entrega y, si
    hace falta, entregar otro producto a cambio (sacaron BRAGA 45 y la regresan porque era
    la 42). Lo incluye el Historial de Movimientos (/admin/almacen/movimientos): el botón
    "Devolver" de cada salida con nota lo abre con esa nota y ese producto listos.

    Solo para quien tiene almacen.movimiento: la devolución mueve stock (la ruta POST lo
    vuelve a comprobar). El comportamiento vive en js/maquinaria/devolucion_material.js y
    se descarga la primera vez que se abre; la lógica, en App\Services\DevolucionService.
--}}
@can('almacen.movimiento')
<style>
    #devMatModal { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.45); z-index:10000; align-items:center; justify-content:center; padding:16px; }
    #devMatModal.open { display:flex; }
    #devMatModal .devm-box { background:#fff; border-radius:14px; width:100%; max-width:600px; max-height:90vh; box-shadow:0 20px 50px rgba(0,0,0,0.25); display:flex; flex-direction:column; overflow:hidden; }
    /* Encabezado de los modales del módulo (ver .alm-modal-head en almacen/index). */
    #devMatModal .devm-head { padding:14px 48px; background:#1e293b; display:flex; align-items:center; justify-content:center; position:relative; flex-shrink:0; }
    #devMatModal .devm-head h3 { margin:0; font-size:15px; font-weight:800; color:#fff; display:flex; align-items:center; gap:8px; }
    #devMatModal .devm-head h3 .material-icons { color:#0067b1; }
    #devMatModal .devm-x { position:absolute; right:14px; top:50%; transform:translateY(-50%); cursor:pointer; color:#fff; opacity:.75; background:none; border:none; padding:0; display:flex; }
    #devMatModal .devm-x:hover { opacity:1; }
    #devMatModal .devm-body { padding:16px 18px; display:flex; flex-direction:column; gap:12px; overflow-y:auto; min-height:0; }
    #devMatModal .devm-foot { padding:12px 18px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:center; gap:8px; flex-shrink:0; }
    /* Mismo tamaño que los botones del pie de los modales de /admin/almacen (.alm-modal-foot). */
    #devMatModal .devm-foot .btn-primary-maquinaria { padding:8px 20px; border-radius:10px; }
    #devMatModal .devm-foot .btn-primary-maquinaria:disabled { opacity:.5; cursor:not-allowed; }
    #devMatModal .devm-btn-cancelar { background:#e2e8f0; color:#475569; box-shadow:none; }

    #devMatModal label.devm-label { font-size:12px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.3px; display:block; margin-bottom:4px; }
    #devMatModal label.devm-label .devm-opc { font-weight:400; color:#64748b; text-transform:none; letter-spacing:0; }
    #devMatModal .devm-input { width:100%; border:1px solid #cbd5e0; border-radius:8px; padding:9px 10px; font-size:14px; outline:none; box-sizing:border-box; background:#fff; color:#0f172a; }
    #devMatModal .devm-input:focus { border-color:var(--maquinaria-blue,#0067b1); }

    /* Sugerencias del producto a cambio */
    #devMatModal .devm-sug { position:absolute; top:calc(100% + 4px); left:0; right:0; background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 22px rgba(15,23,42,0.16); max-height:200px; overflow-y:auto; padding:4px; z-index:5; display:none; }
    #devMatModal .devm-sug.open { display:block; }
    #devMatModal .devm-sug-item { padding:7px 10px; border-radius:6px; cursor:pointer; font-size:13px; color:#0f172a; }
    #devMatModal .devm-sug-item:hover { background:#e0f2fe; }
    #devMatModal .devm-sug-item small { color:#475569; margin-left:6px; }
    /* Producto a cambio: el stock de cada opción en el almacén de la nota; sin stock no se
       puede elegir (va al final de la lista). */
    #devMatModal .devm-sug-stock { display:block; margin:1px 0 0; font-size:11px; color:#475569; }
    #devMatModal .devm-sug-item.sin-stock { cursor:not-allowed; color:#64748b; }
    #devMatModal .devm-sug-item.sin-stock:hover { background:transparent; }
    #devMatModal .devm-sug-item.sin-stock .devm-sug-stock { color:#b91c1c; }
    #devMatModal .devm-sug-vacio { padding:8px 10px; font-size:12px; color:#64748b; font-style:italic; }

    #devMatModal .devm-msg { padding:9px 12px; border-radius:8px; font-size:12.5px; font-weight:600; line-height:1.4; }
    #devMatModal .devm-msg.error { background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; }
    #devMatModal .devm-msg.info  { background:#e0f2fe; color:#075985; border:1px solid #bae6fd; }

    /* Cabecera de la nota encontrada */
    #devMatModal .devm-nota { display:flex; flex-wrap:wrap; align-items:center; gap:4px 16px; padding:10px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; font-size:12.5px; color:#475569; }
    #devMatModal .devm-nota b { color:#0f172a; }
    #devMatModal .devm-nota a { margin-left:auto; color:#0067b1; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:3px; }
    #devMatModal .devm-nota a .material-icons { font-size:16px; }

    /* La tarjeta del producto: arriba qué es y cuánto se entregó; debajo, cuánto vuelve y
       qué se entrega a cambio. */
    #devMatModal .devm-lineas { display:flex; flex-direction:column; gap:8px; margin-top:10px; }
    #devMatModal .devm-linea { border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; display:grid; grid-template-columns:170px minmax(0,1fr); gap:10px 14px; align-items:start; }
    #devMatModal .devm-linea > :first-child { grid-column:1 / -1; }
    #devMatModal .devm-linea.activa { border-color:#93c5fd; background:#eff6ff; }
    #devMatModal .devm-linea.cerrada { opacity:.6; }
    /* Encabezado del producto: código en pastilla + nombre, y debajo lo entregado y lo ya
       devuelto como datos; una raya lo separa de los campos. */
    #devMatModal .devm-prod-bloque { display:flex; flex-direction:column; gap:7px; padding-bottom:11px; border-bottom:1px solid #e2e8f0; }
    #devMatModal .devm-prod-cab { display:flex; align-items:center; gap:8px; min-width:0; }
    #devMatModal .devm-cod { flex-shrink:0; font-family:monospace; font-size:12px; font-weight:700; color:#0f172a; background:#f1f5f9; border:1px solid #cbd5e1; border-radius:6px; padding:2px 7px; }
    #devMatModal .devm-prod { font-size:14.5px; font-weight:800; color:#0f172a; line-height:1.3; min-width:0; }
    #devMatModal .devm-datos { display:flex; flex-wrap:wrap; gap:6px; }
    #devMatModal .devm-datos span { font-size:12px; color:#334155; background:#f8fafc; border:1px solid #e2e8f0; border-radius:999px; padding:2px 10px; }
    #devMatModal .devm-datos b { color:#0f172a; }
    #devMatModal .devm-prod-sub { font-size:12px; color:#475569; font-weight:600; }
    #devMatModal .devm-mini { font-size:10.5px; font-weight:800; color:#475569; text-transform:uppercase; letter-spacing:.4px; margin-bottom:3px; display:flex; justify-content:space-between; gap:6px; }
    #devMatModal .devm-mini button { background:none; border:none; padding:0; color:#0067b1; font-size:10.5px; font-weight:800; cursor:pointer; text-transform:uppercase; }
    #devMatModal .devm-mini .devm-opc { font-weight:600; text-transform:none; letter-spacing:0; }
    /* Producto ya devuelto entero: el aviso ocupa el lugar de los dos campos. */
    #devMatModal .devm-linea-fin { grid-column:1 / -1; }
    #devMatModal .devm-cant { display:flex; align-items:center; gap:6px; }
    #devMatModal .devm-cant .devm-input { padding:7px 8px; text-align:right; }
    #devMatModal .devm-cant span { font-size:12px; color:#334155; font-weight:700; white-space:nowrap; }
    #devMatModal .devm-cambio { position:relative; }
    #devMatModal .devm-cambio .devm-input { padding:7px 8px; font-size:13px; }
    #devMatModal .devm-elegido { display:flex; align-items:center; gap:6px; }
    #devMatModal .devm-elegido .devm-chip { flex:1; min-width:0; background:#e0f2fe; color:#075985; border-radius:8px; padding:6px 8px; font-size:12px; font-weight:700; display:flex; align-items:center; gap:4px; }
    #devMatModal .devm-elegido .devm-chip span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    #devMatModal .devm-elegido .devm-chip button { margin-left:auto; background:none; border:none; padding:0; color:#075985; cursor:pointer; display:flex; }
    #devMatModal .devm-elegido .devm-chip button .material-icons { font-size:16px; }
    #devMatModal .devm-elegido .devm-input { width:74px; flex-shrink:0; text-align:right; }

    #devMatModal .devm-motivo { margin-top:12px; }
    #devMatModal .devm-historial { font-size:12px; color:#475569; border-top:1px dashed #e2e8f0; padding-top:10px; margin-top:12px; }
    #devMatModal .devm-historial .devm-quien { color:#64748b; }
    #devMatModal .devm-historial b { color:#0f172a; }
    #devMatModal .devm-historial ul { margin:6px 0 0; padding-left:18px; display:flex; flex-direction:column; gap:3px; }

    @media (max-width: 640px) {
        #devMatModal { padding:8px; }
        #devMatModal .devm-body { padding:12px; }
        #devMatModal .devm-linea { grid-template-columns:minmax(0,1fr); }
        #devMatModal .devm-linea-fin { grid-column:auto; }
        #devMatModal .devm-nota a { margin-left:0; }
    }
</style>

<div id="devMatModal"
     data-url-show="{{ route('almacen.devolucion.show') }}"
     data-url-store="{{ route('almacen.devolucion.store') }}"
     data-url-productos="{{ route('almacen.productos-autocomplete') }}"
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
                    <label class="devm-label" for="devMatMotivo">Motivo <span class="devm-opc">(opcional)</span></label>
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
