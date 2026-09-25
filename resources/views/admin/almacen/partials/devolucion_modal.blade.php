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
<link rel="stylesheet" href="{{ asset('css/vistas/admin_almacen_partials_devolucion_modal.css') }}?v={{ @filemtime(public_path('css/vistas/admin_almacen_partials_devolucion_modal.css')) }}">

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
