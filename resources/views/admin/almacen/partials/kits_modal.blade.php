{{--
    Modal "Kits por equipo" (Acciones → Kits por equipo en /admin/almacen).

    Un kit es una RECETA de materiales para uno o varios modelos de equipo ("KIT 250H HOWO" =
    1 filtro de aceite + 1 de combustible + 2 cuñetes). Aquí se busca el kit del equipo (por
    placa, modelo o nombre, con filtros de tipo y modelo en cascada), se ve cuántos alcanzan con
    lo que hay en el almacén y se cargan en la salida: los materiales quedan marcados en la
    tabla con la cantidad multiplicada y se sigue con el "Registrar salida" de siempre (su
    control de stock, proyectos, nº de parte y Nota de Entrega).

    Mismo esqueleto que los demás modales del módulo (.alm-modal-overlay / -head / -body /
    -foot, CSS en index.blade.php). El comportamiento vive en js/maquinaria/almacen_kits.js
    (window.AlmKits), que se descarga al montar la página para que también esté sin conexión;
    la lógica del servidor, en App\Services\KitAlmacenService.

    Permisos: verlos, cualquiera con el módulo; armarlos, almacen.productos; cargarlos en la
    salida, almacen.movimiento (lo vuelve a pedir la salida). Los botones se ven siempre y
    avisan si falta el permiso, como el resto del menú Acciones.
--}}
<style>
    {{-- 900px (antes 1040): el contenido son dos columnas —la lista de kits y el detalle—
         y a 1040 el detalle quedaba con demasiado aire a los lados. La lista sigue con su
         minmax(260-340px), así que lo que se recorta sale del sobrante del detalle. --}}
    #almKitsModal .alm-modal { max-width: 900px; height: min(760px, 92vh); max-height: 92vh; }
    #almKitsModal .alm-modal-body { flex: 1 1 auto; padding: 14px 16px; gap: 10px; }
    .akit-vista { display: flex; flex-direction: column; gap: 10px; min-height: 0; flex: 1 1 auto; }
    .akit-vista[hidden] { display: none; }

    /* Barra superior: buscador + almacén + nuevo */
    .akit-barra { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .akit-buscar { flex: 1 1 280px; display: flex; align-items: center; gap: 6px; height: 40px; padding: 0 10px;
        border: 1px solid #cbd5e0; border-radius: 10px; background: #f8fafc; }
    .akit-buscar:focus-within { border-color: #0067b1; background: #fff; }
    .akit-buscar .material-icons { font-size: 19px; color: #0067b1; }
    .akit-buscar input { flex: 1; min-width: 0; border: none; background: transparent; outline: none; font-size: 13.5px; font-weight: 600; color: #0f172a; }
    .akit-buscar .akit-x { cursor: pointer; color: #94a3b8; font-size: 18px; }
    .akit-alm { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 700; color: #334155;
        background: #eef2f7; border-radius: 10px; padding: 0 12px; height: 40px; white-space: nowrap; max-width: 100%; overflow: hidden; text-overflow: ellipsis; }
    .akit-alm .material-icons { font-size: 17px; color: #0067b1; }
    /* "Existencias en" va en gris y más fino: el dato es el NOMBRE del almacén, esto
       solo explica de dónde salen los números de la columna "En almacén". */
    .akit-alm .akit-alm-rot { font-weight: 600; color: #64748b; margin-right: 1px; }
    .akit-alm.sin { color: #b45309; background: #fef3c7; }
    .akit-alm.sin .material-icons { color: #b45309; }
    .akit-btn-nuevo { height: 40px; padding: 0 14px; border-radius: 10px; display: flex; align-items: center; gap: 6px; white-space: nowrap; }

    /* Filtros en cascada: tipo → modelo, con su cuenta */
    .akit-chips { display: flex; gap: 6px; flex-wrap: wrap; }
    .akit-chips[hidden] { display: none; }
    .akit-chip { border: 1px solid #cbd5e0; background: #fff; color: #334155; border-radius: 999px; padding: 5px 11px;
        font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; line-height: 1.2; }
    .akit-chip:hover { border-color: #0067b1; color: #0067b1; }
    .akit-chip .n { background: #e2e8f0; color: #475569; border-radius: 999px; padding: 1px 7px; font-size: 11px; }
    .akit-chip.on { background: #0067b1; border-color: #0067b1; color: #fff; }
    .akit-chip.on .n { background: rgba(255,255,255,0.25); color: #fff; }
    .akit-chips-sub .akit-chip { font-weight: 600; background: #f8fafc; }
    .akit-chips-sub .akit-chip.on { background: #1e293b; border-color: #1e293b; color: #fff; }

    /* Cuerpo: tarjetas a la izquierda, detalle a la derecha */
    .akit-cuerpo { display: grid; grid-template-columns: minmax(260px, 340px) 1fr; gap: 12px; min-height: 0; flex: 1 1 auto; }
    .akit-cards { overflow-y: auto; display: flex; flex-direction: column; gap: 8px; padding-right: 2px; min-height: 0; }
    .akit-card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 12px; background: #fff; cursor: pointer;
        display: flex; flex-direction: column; gap: 5px; transition: border-color .12s, box-shadow .12s; text-align: left; font: inherit; width: 100%; }
    .akit-card:hover { border-color: #93c5fd; }
    .akit-card.on { border-color: #0067b1; box-shadow: 0 0 0 2px rgba(0,103,177,0.18); }
    .akit-card-top { display: flex; align-items: flex-start; gap: 8px; justify-content: space-between; }
    .akit-card-nombre { font-size: 13.5px; font-weight: 800; color: #0f172a; line-height: 1.25; }
    .akit-card-mod { font-size: 11.5px; color: #475569; font-weight: 600; line-height: 1.35; }
    .akit-card-mod .gen { color: #94a3b8; font-style: italic; font-weight: 500; }
    .akit-card-pie { font-size: 11.5px; color: #64748b; }
    .akit-badge { flex: 0 0 auto; border-radius: 999px; padding: 3px 9px; font-size: 11px; font-weight: 800; white-space: nowrap; }
    .akit-badge.ok  { background: #dcfce7; color: #166534; }
    .akit-badge.no  { background: #fee2e2; color: #b91c1c; }
    .akit-badge.gris { background: #f1f5f9; color: #64748b; }
    .akit-vacio { color: #64748b; font-size: 13px; text-align: center; padding: 28px 12px; line-height: 1.5; }
    .akit-vacio .material-icons { display: block; font-size: 34px; color: #cbd5e0; margin-bottom: 6px; }

    .akit-detalle { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; display: flex; flex-direction: column; min-height: 0; overflow: hidden; }
    .akit-det-cab { padding: 12px 14px 8px; border-bottom: 1px solid #f1f5f9; display: flex; gap: 10px; align-items: flex-start; justify-content: space-between; }
    .akit-det-nombre { font-size: 15px; font-weight: 800; color: #0f172a; margin: 0; line-height: 1.25; }
    .akit-det-desc { font-size: 12.5px; color: #475569; margin: 3px 0 0; }
    .akit-det-mods { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 7px; }
    .akit-tag { background: #eef2f7; color: #334155; border-radius: 6px; padding: 3px 8px; font-size: 11.5px; font-weight: 700; }
    .akit-tag.gen { background: #f8fafc; color: #94a3b8; font-style: italic; font-weight: 600; }
    .akit-det-acc { display: flex; gap: 4px; flex: 0 0 auto; }
    .akit-ico { border: none; background: #f1f5f9; color: #475569; border-radius: 8px; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; cursor: pointer; }
    .akit-ico:hover { background: #e2e8f0; color: #0f172a; }
    .akit-ico.rojo:hover { background: #fee2e2; color: #b91c1c; }
    .akit-ico .material-icons { font-size: 18px; }
    .akit-tabla-caja { overflow-y: auto; min-height: 0; flex: 1 1 auto; }
    .akit-tabla { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    .akit-tabla th { position: sticky; top: 0; background: #f8fafc; color: #64748b; font-size: 10.5px; text-transform: uppercase; letter-spacing: .03em;
        font-weight: 800; text-align: left; padding: 7px 10px; border-bottom: 1px solid #e2e8f0; }
    .akit-tabla td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; color: #0f172a; vertical-align: middle; }
    .akit-tabla .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .akit-tabla .cod { color: #64748b; font-size: 11px; font-weight: 700; display: block; }
    .akit-tabla tr.falta td { background: #fff7f7; }
    .akit-tabla tr.falta .hay { color: #b91c1c; font-weight: 800; }
    .akit-det-pie { border-top: 1px solid #e2e8f0; background: #f8fafc; padding: 10px 14px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: space-between; }
    .akit-cuantos { display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 700; color: #334155; }
    .akit-stepper { display: flex; align-items: center; border: 1px solid #cbd5e0; border-radius: 10px; overflow: hidden; background: #fff; height: 36px; }
    .akit-stepper button { border: none; background: #f1f5f9; width: 34px; height: 100%; cursor: pointer; color: #0f172a; display: flex; align-items: center; justify-content: center; }
    .akit-stepper button:disabled { color: #cbd5e0; cursor: default; }
    .akit-stepper input { width: 54px; border: none; text-align: center; font-size: 14px; font-weight: 800; outline: none; color: #0f172a; }
    .akit-aviso { font-size: 12px; font-weight: 600; flex: 1 1 200px; line-height: 1.35; }
    .akit-aviso.ok { color: #166534; }
    .akit-aviso.no { color: #b91c1c; }
    .akit-aviso.gris { color: #64748b; }
    .akit-btn-cargar { height: 38px; padding: 0 16px; border-radius: 10px; display: flex; align-items: center; gap: 6px; white-space: nowrap; }
    .akit-btn-cargar:disabled { opacity: .5; cursor: not-allowed; }

    /* Editor */
    .akit-ed { overflow-y: auto; min-height: 0; display: flex; flex-direction: column; gap: 12px; padding-right: 2px; }
    .akit-ed-fila { display: grid; grid-template-columns: 1fr 1.4fr; gap: 10px; }
    .akit-campo { display: flex; flex-direction: column; gap: 4px; }
    .akit-campo > label { font-size: 11.5px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: .03em; }
    .akit-campo input[type=text] { height: 40px; border: 1px solid #cbd5e0; border-radius: 10px; padding: 0 11px;
        font-size: 13.5px; font-weight: 600; color: #0f172a; background: #fff; outline: none; }
    .akit-campo input:focus { border-color: #0067b1; }
    .akit-seccion { border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 12px; display: flex; flex-direction: column; gap: 8px; }
    .akit-seccion h4 { margin: 0; font-size: 12.5px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 6px; }
    .akit-seccion h4 .material-icons { font-size: 17px; color: #0067b1; }
    .akit-seccion h4 small { font-weight: 600; color: #64748b; font-size: 11.5px; }
    .akit-pick { position: relative; }
    .akit-sug { position: absolute; left: 0; right: 0; top: calc(100% + 4px); background: #fff; border: 1px solid #cbd5e0; border-radius: 10px;
        box-shadow: 0 10px 18px -3px rgba(0,0,0,0.18); max-height: 260px; overflow-y: auto; z-index: 5; }
    .akit-sug[hidden] { display: none; }
    .akit-sug button { display: flex; width: 100%; gap: 8px; align-items: baseline; text-align: left; border: none; background: #fff; padding: 8px 11px;
        font-size: 12.5px; color: #0f172a; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
    .akit-sug button:hover { background: #eff6ff; }
    .akit-sug .t { color: #64748b; font-size: 11px; font-weight: 800; flex: 0 0 auto; }
    .akit-sug .vacio { padding: 10px 11px; color: #64748b; font-size: 12.5px; }
    .akit-sel-mods { display: flex; flex-wrap: wrap; gap: 6px; }
    .akit-sel-mods .akit-tag { display: inline-flex; align-items: center; gap: 4px; padding-right: 4px; }
    .akit-sel-mods .akit-tag i { font-size: 15px; cursor: pointer; color: #64748b; }
    .akit-sel-mods .akit-tag i:hover { color: #b91c1c; }
    .akit-sugeridos { display: flex; flex-wrap: wrap; gap: 6px; }
    .akit-sugerido { border: 1px dashed #93c5fd; background: #eff6ff; color: #0f3d6e; border-radius: 8px; padding: 5px 9px; font-size: 12px; font-weight: 600;
        cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }
    .akit-sugerido .material-icons { font-size: 15px; color: #0067b1; }
    .akit-sugerido:disabled { border-style: solid; border-color: #e2e8f0; background: #f8fafc; color: #94a3b8; cursor: default; }
    .akit-sugerido:disabled .material-icons { color: #94a3b8; }
    .akit-items .akit-cant { width: 80px; height: 32px; border: 1px solid #cbd5e0; border-radius: 8px; text-align: right; padding: 0 8px; font-weight: 700; font-size: 13px; }
    .akit-items .akit-cant:focus { border-color: #0067b1; outline: none; }
    .akit-error { color: #b91c1c; font-size: 12.5px; font-weight: 600; }
    .akit-error:empty { display: none; }
    #almKitsModal .alm-modal-foot[hidden] { display: none; }

    @media (max-width: 768px) {
        #almKitsModal { padding: 0; }
        #almKitsModal .alm-modal { max-width: none; height: 100vh; max-height: 100vh; border-radius: 0; }
        #almKitsModal .alm-modal-body { padding: 10px; }
        /* Lista y detalle uno debajo del otro, cada uno con su alto natural, y desliza el
           conjunto: en rejilla, el alto mínimo 0 de la tabla dejaba el detalle recortado. */
        .akit-cuerpo { display: flex; flex-direction: column; overflow-y: auto; }
        .akit-cards, .akit-detalle, .akit-tabla-caja { flex: none; overflow: visible; }
        .akit-ed-fila { grid-template-columns: 1fr; }
        .akit-alm { flex: 1 1 100%; order: 3; }
    }
</style>

@php
    $akitProductos = auth()->user()?->can('almacen.productos') ? '1' : '0';
    $akitMover     = auth()->user()?->can('almacen.movimiento') ? '1' : '0';
@endphp
<div id="almKitsModal" class="alm-modal-overlay"
     data-url-index="{{ route('almacen.kits.index') }}"
     data-url-modelos="{{ route('almacen.kits.modelos') }}"
     data-url-sugeridos="{{ route('almacen.kits.sugeridos') }}"
     data-url-store="{{ route('almacen.kits.store') }}"
     data-puede-productos="{{ $akitProductos }}"
     data-puede-mover="{{ $akitMover }}">
    <div class="alm-modal" role="dialog" aria-modal="true" aria-labelledby="almKitsTitulo">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">inventory_2</i><span id="almKitsTitulo">Kits por equipo</span></h3>
            <i class="material-icons alm-x" onclick="window.AlmKits.cerrar()">close</i>
        </div>
        <div class="alm-modal-body">
            {{-- ── Lista: buscar el kit del equipo y cargarlo ── --}}
            <div id="almKitsLista" class="akit-vista">
                <div class="akit-barra">
                    <div class="akit-buscar">
                        <i class="material-icons">search</i>
                        <input type="text" id="almKitsBuscar" autocomplete="off" placeholder="Placa, modelo o nombre del kit"
                               oninput="window.AlmKits.buscar(this.value)">
                        <i class="material-icons akit-x" id="almKitsBuscarX" hidden onclick="window.AlmKits.buscar('', true)">close</i>
                    </div>
                    <div class="akit-alm" id="almKitsAlmacen"></div>
                    <button type="button" class="btn-primary-maquinaria akit-btn-nuevo" onclick="window.AlmKits.nuevo()">
                        <i class="material-icons" style="font-size:18px;">add</i><span>Nuevo kit</span>
                    </button>
                </div>
                <div class="akit-chips" id="almKitsTipos"></div>
                <div class="akit-chips akit-chips-sub" id="almKitsModelos" hidden></div>
                <div class="akit-cuerpo">
                    <div class="akit-cards" id="almKitsCards"></div>
                    <div class="akit-detalle" id="almKitsDetalle"></div>
                </div>
            </div>

            {{-- ── Editor: nombre, equipos y materiales ── --}}
            <div id="almKitsEditor" class="akit-vista" hidden>
                <div class="akit-ed">
                    <div class="akit-ed-fila">
                        <div class="akit-campo">
                            <label for="almKitNombre">Nombre del kit</label>
                            <input type="text" id="almKitNombre" maxlength="120" autocomplete="off" placeholder="Ej.: KIT 250 HORAS HOWO">
                        </div>
                        <div class="akit-campo">
                            <label for="almKitDesc">Descripción (opcional)</label>
                            <input type="text" id="almKitDesc" maxlength="255" autocomplete="off" placeholder="Ej.: servicio de 250 horas, cambio de aceite y filtros">
                        </div>
                    </div>

                    <div class="akit-seccion">
                        <h4><i class="material-icons">local_shipping</i>Equipos <small>· modelos para los que sirve (sin ninguno, es de uso general)</small></h4>
                        <div class="akit-pick">
                            <div class="akit-campo">
                                <input type="text" id="almKitModeloBuscar" autocomplete="off" placeholder="Buscar modelo: HOWO, D8N, excavadora…"
                                       oninput="window.AlmKits.buscarModelo(this.value)" onfocus="window.AlmKits.buscarModelo(this.value)">
                            </div>
                            <div class="akit-sug" id="almKitModeloSug" hidden></div>
                        </div>
                        <div class="akit-sel-mods" id="almKitModelosSel"></div>
                        <div id="almKitSugeridosCaja" hidden>
                            <h4 style="margin:4px 0 6px;"><i class="material-icons">auto_awesome</i>Compatibles con estos equipos <small>· tócalos para agregarlos</small></h4>
                            <div class="akit-sugeridos" id="almKitSugeridos"></div>
                        </div>
                    </div>

                    <div class="akit-seccion">
                        <h4><i class="material-icons">inventory</i>Materiales <small>· cuánto lleva CADA kit</small></h4>
                        <div class="akit-pick">
                            <div class="akit-campo">
                                <input type="text" id="almKitProdBuscar" autocomplete="off" placeholder="Buscar material por nombre, código o nº de parte"
                                       oninput="window.AlmKits.buscarProducto(this.value)">
                            </div>
                            <div class="akit-sug" id="almKitProdSug" hidden></div>
                        </div>
                        <table class="akit-tabla akit-items">
                            <thead><tr><th>Material</th><th class="num">Por kit</th><th style="width:40px;"></th></tr></thead>
                            <tbody id="almKitItems"></tbody>
                        </table>
                    </div>
                    <div class="akit-error" id="almKitError"></div>
                </div>
            </div>
        </div>
        <div class="alm-modal-foot" id="almKitsPieEditor" hidden>
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;" onclick="window.AlmKits.cancelarEdicion()">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" id="almKitGuardar" onclick="window.AlmKits.guardar()">
                <i class="material-icons" style="font-size:18px;vertical-align:middle;">save</i> Guardar kit
            </button>
        </div>
    </div>
</div>

<script>
    // El módulo se descarga al montar la página (no al abrir el modal) para que el service
    // worker lo guarde y también abra sin conexión. Envoltorio sin estado: se redefine en cada
    // montaje SPA a propósito.
    (function () {
        {{-- ?v= obligatorio: nginx sirve /js con Cache-Control immutable. --}}
        var src = "{{ asset('js/maquinaria/almacen_kits.js') . '?v=' . @filemtime(public_path('js/maquinaria/almacen_kits.js')) }}";
        var listo = function () { return !!window.AlmKits; };
        var cargar = function () { return window.cargarScriptUnaVez(src, listo); };
        cargar().catch(function () {});
        window.almAbrirKits = function () {
            cargar().then(function () { window.AlmKits.abrir(); })
                .catch(function () { window.toast('No se pudieron abrir los kits. Revisa tu conexión.', 'error'); });
        };
    })();
</script>
