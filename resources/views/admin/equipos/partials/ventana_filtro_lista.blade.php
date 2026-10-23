{{-- Ventana de filtro con lista, en el CENTRO de la pantalla: la abre una lupa de «Documentación
     Cargada» (Título → titular, Póliza → aseguradora). Mientras está abierta el panel de Filtros
     Avanzados se esconde (CSS :has en admin_equipos_index.css). Va DENTRO del panel para que
     eqFiltrosActivos lea su input oculto con los demás filtros.

     Como el filtro Frente: abre con la casilla VACÍA y lo aplicado como texto de fondo, así se
     escribe encima sin borrar nada; la X de la casilla solo borra lo escrito (no toca el filtro ni
     la tabla) y «Quitar filtro» —solo visible con uno puesto— lo suelta. Elegir un nombre de la
     lista lo aplica y cierra la ventana. JS: eqVentanaAbrir/eqVentanaCerrar y eqTexto* de
     equipos_index.js.

     Variables: $ventanaId, $ddId, $nombre (parámetro de la URL), $titulo, $icono, $placeholder,
     $opciones (lista de textos). Solo equipos: los auxiliares no guardan ni titular ni póliza. --}}
@php($aplicado = (string) request($nombre))
<div id="{{ $ventanaId }}" class="eq-ventana-filtro adv-filter-eq-only" hidden
     onclick="if (event.target === this) window.eqVentanaCerrar('{{ $ventanaId }}');">
    <div class="eq-ventana-caja" role="dialog" aria-label="{{ $titulo }}">
        <div class="eq-ventana-head">
            <i class="material-icons">{{ $icono }}</i>
            <span>{{ $titulo }}</span>
            <button type="button" class="eq-ventana-x" title="Cerrar" aria-label="Cerrar"
                    onclick="window.eqVentanaCerrar('{{ $ventanaId }}')"><i class="material-icons">close</i></button>
        </div>
        <div class="custom-dropdown" id="{{ $ddId }}" style="font-size: 12px;">
            <div class="adv-trigger {{ $aplicado !== '' ? 'filter-active' : '' }}">
                <div style="padding: 0 8px; display: flex; align-items: center; color: #94a3b8;">
                    <i class="material-icons" style="font-size: 16px;">search</i>
                </div>
                <input type="hidden" name="{{ $nombre }}" value="{{ $aplicado }}">
                <input type="text" value="" placeholder="{{ $aplicado !== '' ? $aplicado : $placeholder }}"
                    data-placeholder="{{ $placeholder }}"
                    aria-label="{{ $titulo }}"
                    autocomplete="off"
                    style="width: 100%; min-width: 0; border: none; background: transparent; padding: 6px 5px; font-size: 12px; outline: none;"
                    onfocus="window.eqTextoSugerir(this)"
                    oninput="window.eqTextoSugerir(this)"
                    onkeydown="if (event.key === 'Enter') { event.preventDefault(); window.eqTextoEnter(this); } else if (event.key === 'Escape') { window.eqVentanaCerrar('{{ $ventanaId }}'); }">
                <i class="material-icons eq-ventana-borrar" title="Borrar lo escrito"
                   onclick="event.stopPropagation(); const i = this.parentNode.querySelector('input[type=text]'); i.value = ''; window.eqTextoSugerir(i); i.focus();">close</i>
            </div>
            <div class="dropdown-content">
                <div class="dropdown-item-list" style="max-height: 260px; overflow-y: auto;">
                    @foreach($opciones as $op)
                        <div class="dropdown-item" data-value="{{ $op }}" title="{{ $op }}" onclick="window.eqTextoElegir(this)">{{ $op }}</div>
                    @endforeach
                </div>
            </div>
        </div>
        <button type="button" class="eq-ventana-quitar {{ $aplicado !== '' ? 'activo' : '' }}" data-filtro-de="{{ $ddId }}"
                onclick="window.eqTextoLimpiar('{{ $ddId }}', true); window.eqVentanaCerrar('{{ $ventanaId }}');">
            <i class="material-icons">filter_alt_off</i> Quitar filtro
        </button>
    </div>
</div>
