{{-- Lupa de «Título» / «Póliza» en Documentación Cargada: abre su ventana de filtro con lista
     (partials/ventana_filtro_lista) y, debajo, mientras el filtro esté puesto, el nombre aplicado con
     una X que lo quita sin abrir la ventana. Las dos llevan data-filtro-de: _eqTextoMarcar
     (equipos_index.js) les pone .activo y el texto al aplicar o quitar.

     Variables: $ventanaId, $ddId, $nombre (parámetro de la URL), $ayuda (title de la lupa),
     $clase (clases extra de la lupa y del nombre, p. ej. adv-filter-eq-only). --}}
@php($aplicado = (string) request($nombre))
<button type="button" class="adv-lupa {{ $clase }} {{ $aplicado !== '' ? 'activo' : '' }}"
        data-filtro-de="{{ $ddId }}"
        title="{{ $ayuda }}" aria-label="{{ $ayuda }}"
        onclick="event.stopPropagation(); window.eqVentanaAbrir('{{ $ventanaId }}')"><i class="material-icons">search</i></button>
<span class="adv-filtro-chip {{ $clase }} {{ $aplicado !== '' ? 'activo' : '' }}" data-filtro-de="{{ $ddId }}">
    <span data-filtro-texto title="{{ $aplicado }}">{{ $aplicado }}</span>
    <i class="material-icons" title="Quitar filtro" onclick="event.stopPropagation(); window.eqTextoLimpiar('{{ $ddId }}', true)">close</i>
</span>
