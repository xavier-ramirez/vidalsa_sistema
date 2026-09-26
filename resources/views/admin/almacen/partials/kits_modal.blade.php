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
<link rel="stylesheet" href="{{ asset('css/vistas/admin_almacen_partials_kits_modal.css') }}?v={{ @filemtime(public_path('css/vistas/admin_almacen_partials_kits_modal.css')) }}">

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
                <i class="material-icons" style="font-size:18px;vertical-align:middle;">save</i> Guardar
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
