{{--
    «Modificar» una línea de Nota de Entrega — lo incluye el Historial de Movimientos
    (/admin/almacen/movimientos): el botón «Modificar» de cada salida con nota lo abre con esa
    nota y ese producto listos. Un solo modal para las dos cosas que se le pueden hacer, y
    antes del campo la pregunta que las separa:

      · DEVOLUCIÓN — el material salió y VOLVIÓ. La nota firmada no cambia; se anota lo
        devuelto con su fecha (App\Services\DevolucionService). Clave almacen.movimiento.
      · CORRECCIÓN — la nota se CARGÓ MAL (salieron 100 y se tecleó 180). Pasa a decir lo que
        salió y queda la original para comparar (App\Services\CorreccionNotaService). Clave
        almacen.nota.corregir.

    window.almVerCorreccion enseña la nota ORIGINAL (corrección en rojo) y la CORREGIDA lado a
    lado en el visor de documentos de siempre, igual que una póliza con su corrección
    (window.openPdfComparado). Sale sola al corregir y desde la marca «corregida» del
    Historial, que ve cualquiera que pueda ver la nota: por eso va fuera del @canany.

    El comportamiento del modal vive en js/maquinaria/ajuste_nota.js y se descarga la primera vez.
--}}
@canany(['almacen.movimiento', 'almacen.nota.corregir'])
<link rel="stylesheet" href="{{ asset('css/vistas/admin_almacen_partials_ajuste_nota_modal.css') }}?v={{ @filemtime(public_path('css/vistas/admin_almacen_partials_ajuste_nota_modal.css')) }}">

<div id="ajNotaModal"
     data-url-show="{{ route('almacen.ajuste-nota.show') }}"
     data-url-devolver="{{ route('almacen.devolucion.store') }}"
     data-url-corregir="{{ route('almacen.correccion-nota.store') }}"
     onclick="if (event.target === this) window.AjusteNota.cerrar();">
    <div class="ajn-box" role="dialog" aria-modal="true" aria-labelledby="ajNotaTitulo">
        <div class="ajn-head">
            <h3 id="ajNotaTitulo"><i class="material-icons">edit_note</i>Modificar nota</h3>
            <button type="button" class="ajn-x" aria-label="Cerrar" onclick="window.AjusteNota.cerrar()"><i class="material-icons">close</i></button>
        </div>
        <div class="ajn-body">
            <div id="ajNotaMsg" class="ajn-msg" hidden></div>

            <div id="ajNotaContenido" class="ajn-contenido" hidden>
                <div id="ajNotaNota" class="ajn-nota"></div>
                <div id="ajNotaProducto" class="ajn-linea"></div>

                <div class="ajn-opciones" role="radiogroup" aria-label="¿Qué pasó?">
                    <button type="button" class="ajn-opcion" data-op="devolucion" role="radio" aria-checked="false">
                        <span class="ajn-opcion-tit"><i class="material-icons">assignment_return</i>Devolución</span>
                        <span class="ajn-opcion-txt">Regresó al almacén</span>
                        <span class="ajn-opcion-no" hidden></span>
                    </button>
                    <button type="button" class="ajn-opcion" data-op="correccion" role="radio" aria-checked="false">
                        <span class="ajn-opcion-tit"><i class="material-icons">edit_note</i>Corrección</span>
                        <span class="ajn-opcion-txt">Cantidad mal anotada</span>
                        <span class="ajn-opcion-no" hidden></span>
                    </button>
                </div>

                <div id="ajNotaCampos" class="ajn-contenido" hidden>
                    <div>
                        <label id="ajNotaCantLbl" class="ajn-label" for="ajNotaCant"></label>
                        <div class="ajn-cant">
                            <input type="text" inputmode="decimal" class="ajn-input" id="ajNotaCant" autocomplete="off">
                            <span id="ajNotaUm" class="ajn-de"></span>
                        </div>
                    </div>
                    <div>
                        <label id="ajNotaMotivoLbl" class="ajn-label" for="ajNotaMotivo"></label>
                        <input type="text" id="ajNotaMotivo" class="ajn-input" maxlength="150" autocomplete="off">
                    </div>
                </div>
                <div id="ajNotaHistorial" class="ajn-historial" hidden></div>
            </div>
        </div>
        <div class="ajn-foot">
            <button type="button" class="ajn-btn-cancelar" onclick="window.AjusteNota.cerrar()">Cancelar</button>
            <button type="button" id="ajNotaGuardar" class="ajn-btn-registrar" disabled></button>
        </div>
    </div>
</div>
@endcanany

<script>
    // Se redefinen en cada montaje SPA a propósito: son envoltorios sin estado.
    window.almModificarNota = function (numero, idProducto) {
        {{-- ?v= obligatorio: nginx sirve /js con Cache-Control immutable. --}}
        window.cargarScriptUnaVez(
            "{{ asset('js/maquinaria/ajuste_nota.js') . '?v=' . @filemtime(public_path('js/maquinaria/ajuste_nota.js')) }}",
            function () { return !!window.AjusteNota; }
        ).then(function () {
            window.AjusteNota.abrir(numero, idProducto);
        }).catch(function () {
            window.toast('No se pudo abrir la nota. Revisa tu conexión.', 'error');
        });
    };
    window.almVerCorreccion = function (numero) {
        var base = "{{ route('almacen.nota-entrega') }}?numero=" + encodeURIComponent(numero);
        window.openPdfComparado(base + '&version=original', { link: base, etiqueta: 'Corregida' },
            'Nota ' + numero, 'nota_entrega', 'almacen');
    };
</script>
