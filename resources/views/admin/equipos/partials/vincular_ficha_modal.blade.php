{{--
    Modal "Vincular a una ficha del catálogo" de /admin/equipos. Se abre con doble clic en la
    foto de un equipo (table_rows: data-vincular) y solo para super.admin: se elige la ficha
    (caracteristicas_modelo) y el equipo queda con su ID_ESPEC, así que toma la foto de su
    color o la del modelo (Equipo::fotoParaMostrar). Sirve para las unidades que no
    encontraron solas su ficha (el modelo escrito distinto, otro año, varias fichas).

    Mismo ancho y organización que el modal "Anclaje de Equipos" (equipos_index.js):
    buscador arriba (modelo, tipo o año), lista de filas con foto a la izquierda y un solo
    botón abajo. Abre con las fichas sugeridas para el equipo y, mientras no exista la de su
    modelo + año, ofrece arriba crearla (catalogo.asegurarFicha) y vincularlo en un paso —
    también mientras se busca.

    Las fichas las busca CaracteristicaModeloController::elegir (mismas tarjetas que el
    catálogo) y el vínculo lo guarda EquipoController::vincularFicha. El comportamiento vive
    en js/maquinaria/vincular_ficha.js, que se descarga la primera vez que se abre.
--}}
@can('super.admin')
<link rel="stylesheet" href="{{ asset('css/vistas/admin_equipos_partials_vincular_ficha_modal.css') }}?v={{ @filemtime(public_path('css/vistas/admin_equipos_partials_vincular_ficha_modal.css')) }}">

<div id="vfModal" role="dialog" aria-modal="true" aria-labelledby="vfTitulo"
     data-url-elegir="{{ route('catalogo.elegir') }}"
     data-url-vincular="{{ route('equipos.vincularFicha', '__ID__') }}"
     data-url-asegurar="{{ route('catalogo.asegurarFicha') }}">
    <div class="vf-box">
        <div class="vf-head">
            <h3 id="vfTitulo"><i class="material-icons">link</i>Vincular a una ficha</h3>
            <button type="button" class="vf-x" data-vf-cerrar aria-label="Cerrar"><i class="material-icons">close</i></button>
        </div>
        <div class="vf-body">
            <label class="vf-buscar-caja">
                <i class="material-icons">search</i>
                <input type="text" id="vfBuscar" class="vf-buscar" placeholder="Buscar por modelo, tipo o año..." autocomplete="off">
            </label>
            <div class="vf-lista" id="vfResultados"></div>
            <button type="button" class="vf-vincular" id="vfVincular" disabled>
                <i class="material-icons">link</i> Vincular
            </button>
        </div>
    </div>
</div>

<script>
    // Abre el modal; el módulo (vincular_ficha.js) se descarga la primera vez. Se redefine
    // en cada montaje SPA a propósito: es un envoltorio sin estado.
    window.eqVincularFicha = function (fotoEl) {
        {{-- ?v= obligatorio: nginx sirve /js con Cache-Control immutable. --}}
        window.cargarScriptUnaVez(
            "{{ asset('js/maquinaria/vincular_ficha.js') . '?v=' . @filemtime(public_path('js/maquinaria/vincular_ficha.js')) }}",
            function () { return !!window.VincularFicha; }
        ).then(function () {
            window.VincularFicha.abrir(fotoEl);
        }).catch(function () {
            window.toast('No se pudo abrir el catálogo. Revisa tu conexión.', 'error');
        });
    };
</script>
@endcan
