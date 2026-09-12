{{--
    Modal "Devolución de material" — devolver lo entregado con una Nota de Entrega y, si
    hace falta, entregar otro producto a cambio (sacaron BRAGA 45 y la regresan porque era
    la 42). Lo incluyen la bitácora (/admin/almacen/movimientos, menú Acciones) y la vista
    por nota (/admin/almacen/notas, botón de cada nota).

    Solo para quien tiene almacen.movimiento: la devolución mueve stock (la ruta POST lo
    vuelve a comprobar). El comportamiento vive en js/maquinaria/devolucion_material.js y
    se descarga la primera vez que se abre; la lógica, en App\Services\DevolucionService.
--}}
@can('almacen.movimiento')
<style>
    #devMatModal { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.45); z-index:10000; align-items:center; justify-content:center; padding:16px; }
    #devMatModal.open { display:flex; }
    #devMatModal .devm-box { background:#fff; border-radius:14px; width:100%; max-width:760px; max-height:90vh; box-shadow:0 20px 50px rgba(0,0,0,0.25); display:flex; flex-direction:column; overflow:hidden; }
    /* Encabezado de los modales del módulo (ver .alm-modal-head en almacen/index). */
    #devMatModal .devm-head { padding:14px 48px; background:#1e293b; display:flex; align-items:center; justify-content:center; position:relative; flex-shrink:0; }
    #devMatModal .devm-head h3 { margin:0; font-size:15px; font-weight:800; color:#fff; display:flex; align-items:center; gap:8px; }
    #devMatModal .devm-head h3 .material-icons { color:#0067b1; }
    #devMatModal .devm-x { position:absolute; right:14px; top:50%; transform:translateY(-50%); cursor:pointer; color:#fff; opacity:.75; background:none; border:none; padding:0; display:flex; }
    #devMatModal .devm-x:hover { opacity:1; }
    #devMatModal .devm-body { padding:16px 18px; display:flex; flex-direction:column; gap:12px; overflow-y:auto; min-height:0; }
    #devMatModal .devm-foot { padding:12px 18px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:center; gap:8px; flex-shrink:0; }
    #devMatModal .devm-foot .btn-primary-maquinaria:disabled { opacity:.5; cursor:not-allowed; }
    #devMatModal .devm-btn-cancelar { background:#e2e8f0; color:#475569; box-shadow:none; }

    #devMatModal label.devm-label { font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px; display:block; margin-bottom:4px; }
    #devMatModal label.devm-label .devm-opc { font-weight:400; color:#94a3b8; text-transform:none; letter-spacing:0; }
    #devMatModal .devm-input { width:100%; border:1px solid #cbd5e0; border-radius:8px; padding:9px 10px; font-size:14px; outline:none; box-sizing:border-box; background:#fff; color:#0f172a; }
    #devMatModal .devm-input:focus { border-color:var(--maquinaria-blue,#0067b1); }

    /* Buscar la nota */
    #devMatModal .devm-buscar { position:relative; }
    #devMatModal .devm-buscar-fila { display:flex; gap:8px; }
    #devMatModal .devm-buscar-fila .devm-input { flex:1; min-width:0; text-transform:uppercase; letter-spacing:.5px; }
    #devMatModal .devm-buscar-fila .devm-input::placeholder { text-transform:none; letter-spacing:0; }
    #devMatModal .devm-buscar-fila .btn-primary-maquinaria { flex-shrink:0; }
    #devMatModal .devm-sug { position:absolute; top:calc(100% + 4px); left:0; right:0; background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 22px rgba(15,23,42,0.16); max-height:200px; overflow-y:auto; padding:4px; z-index:5; display:none; }
    #devMatModal .devm-sug.open { display:block; }
    #devMatModal .devm-sug-item { padding:7px 10px; border-radius:6px; cursor:pointer; font-size:13px; color:#0f172a; }
    #devMatModal .devm-sug-item:hover { background:#e0f2fe; }
    #devMatModal .devm-sug-item small { color:#64748b; margin-left:6px; }
    #devMatModal .devm-sug-vacio { padding:8px 10px; font-size:12px; color:#94a3b8; font-style:italic; }

    #devMatModal .devm-msg { padding:9px 12px; border-radius:8px; font-size:12.5px; font-weight:600; line-height:1.4; }
    #devMatModal .devm-msg.error { background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; }
    #devMatModal .devm-msg.info  { background:#e0f2fe; color:#075985; border:1px solid #bae6fd; }

    /* Cabecera de la nota encontrada */
    #devMatModal .devm-nota { display:flex; flex-wrap:wrap; align-items:center; gap:4px 16px; padding:10px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; font-size:12.5px; color:#475569; }
    #devMatModal .devm-nota b { color:#0f172a; }
    #devMatModal .devm-nota a { margin-left:auto; color:#0067b1; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:3px; }
    #devMatModal .devm-nota a .material-icons { font-size:16px; }

    /* Una tarjeta por producto de la nota */
    #devMatModal .devm-lineas { display:flex; flex-direction:column; gap:8px; }
    #devMatModal .devm-linea { border:1px solid #e2e8f0; border-radius:10px; padding:10px 12px; display:grid; grid-template-columns:minmax(0,1fr) 150px minmax(0,1.1fr); gap:6px 12px; align-items:start; }
    #devMatModal .devm-linea.activa { border-color:#99f6e4; background:#f0fdfa; }
    #devMatModal .devm-linea.cerrada { opacity:.6; }
    #devMatModal .devm-prod { font-size:13px; font-weight:700; color:#0f172a; line-height:1.3; }
    #devMatModal .devm-prod-sub { font-size:11.5px; color:#64748b; margin-top:3px; font-weight:500; }
    #devMatModal .devm-mini { font-size:10.5px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.4px; margin-bottom:3px; display:flex; justify-content:space-between; gap:6px; }
    #devMatModal .devm-mini button { background:none; border:none; padding:0; color:#0067b1; font-size:10.5px; font-weight:800; cursor:pointer; text-transform:uppercase; }
    #devMatModal .devm-mini .devm-opc { font-weight:600; text-transform:none; letter-spacing:0; }
    /* Producto ya devuelto entero: el aviso ocupa las dos columnas de los campos. */
    #devMatModal .devm-linea-fin { grid-column:2 / -1; align-self:center; }
    #devMatModal .devm-cant { display:flex; align-items:center; gap:6px; }
    #devMatModal .devm-cant .devm-input { padding:7px 8px; text-align:right; }
    #devMatModal .devm-cant span { font-size:11.5px; color:#64748b; font-weight:600; white-space:nowrap; }
    #devMatModal .devm-cambio { position:relative; }
    #devMatModal .devm-cambio .devm-input { padding:7px 8px; font-size:13px; }
    #devMatModal .devm-elegido { display:flex; align-items:center; gap:6px; }
    #devMatModal .devm-elegido .devm-chip { flex:1; min-width:0; background:#e0f2fe; color:#075985; border-radius:8px; padding:6px 8px; font-size:12px; font-weight:700; display:flex; align-items:center; gap:4px; }
    #devMatModal .devm-elegido .devm-chip span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    #devMatModal .devm-elegido .devm-chip button { margin-left:auto; background:none; border:none; padding:0; color:#075985; cursor:pointer; display:flex; }
    #devMatModal .devm-elegido .devm-chip button .material-icons { font-size:16px; }
    #devMatModal .devm-elegido .devm-input { width:74px; flex-shrink:0; text-align:right; }

    #devMatModal .devm-ayuda { font-size:12.5px; color:#475569; margin:10px 0 6px; }
    #devMatModal .devm-campos { display:grid; grid-template-columns:170px minmax(0,1fr); gap:10px; margin-top:12px; }
    #devMatModal .devm-historial { font-size:12px; color:#475569; border-top:1px dashed #e2e8f0; padding-top:10px; margin-top:12px; }
    #devMatModal .devm-historial .devm-quien { color:#94a3b8; }
    #devMatModal .devm-historial b { color:#0f172a; }
    #devMatModal .devm-historial ul { margin:6px 0 0; padding-left:18px; display:flex; flex-direction:column; gap:3px; }

    @media (max-width: 640px) {
        #devMatModal { padding:8px; }
        #devMatModal .devm-body { padding:12px; }
        #devMatModal .devm-linea { grid-template-columns:minmax(0,1fr); }
        #devMatModal .devm-linea-fin { grid-column:auto; }
        #devMatModal .devm-campos { grid-template-columns:minmax(0,1fr); }
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
            <div class="devm-buscar">
                <label class="devm-label" for="devMatNumero">Nota de Entrega</label>
                <div class="devm-buscar-fila">
                    <input type="text" id="devMatNumero" class="devm-input" placeholder="N° de la nota, p. ej. NE-2026-0123" autocomplete="off">
                    <button type="button" id="devMatBuscarBtn" class="btn-primary-maquinaria">Buscar</button>
                </div>
                <div id="devMatSug" class="devm-sug"></div>
            </div>

            <div id="devMatMsg" class="devm-msg" hidden></div>

            <div id="devMatContenido" hidden>
                <div id="devMatNota" class="devm-nota"></div>
                <div class="devm-ayuda">
                    Indica cuánto vuelve de cada producto. Si se entrega otro a cambio (p. ej. otra talla), elígelo al lado: sale con una Nota de Entrega nueva.
                </div>
                <div id="devMatLineas" class="devm-lineas"></div>
                <div class="devm-campos">
                    <div>
                        <label class="devm-label" for="devMatFecha">Fecha</label>
                        <input type="date" id="devMatFecha" class="devm-input">
                    </div>
                    <div>
                        <label class="devm-label" for="devMatMotivo">Motivo <span class="devm-opc">(opcional)</span></label>
                        <input type="text" id="devMatMotivo" class="devm-input" maxlength="150" list="devMatMotivos" placeholder="P. ej. talla o medida equivocada" autocomplete="off">
                        <datalist id="devMatMotivos">
                            <option value="Talla o medida equivocada">
                            <option value="Material equivocado">
                            <option value="Sobrante, no se usó">
                        </datalist>
                    </div>
                </div>
                <div id="devMatHistorial" class="devm-historial" hidden></div>
            </div>
        </div>
        <div class="devm-foot">
            <button type="button" class="btn-primary-maquinaria devm-btn-cancelar" onclick="window.DevolucionMaterial.cerrar()">Cancelar</button>
            <button type="button" id="devMatGuardar" class="btn-primary-maquinaria" disabled>Registrar devolución</button>
        </div>
    </div>
</div>

<script>
    // Abre el modal; el módulo (devolucion_material.js) se descarga la primera vez. Se
    // redefine en cada montaje SPA a propósito: es un envoltorio sin estado.
    window.almAbrirDevolucion = function (numero) {
        {{-- ?v= obligatorio: nginx sirve /js con Cache-Control immutable. --}}
        window.cargarScriptUnaVez(
            "{{ asset('js/maquinaria/devolucion_material.js') . '?v=' . @filemtime(public_path('js/maquinaria/devolucion_material.js')) }}",
            function () { return !!window.DevolucionMaterial; }
        ).then(function () {
            window.DevolucionMaterial.abrir(numero || '');
        }).catch(function () {
            window.toast('No se pudo abrir la devolución. Revisa tu conexión.', 'error');
        });
    };
</script>
@endcan
