@extends('layouts.estructura_base')

@section('title', 'Mapa')

@section('content')
<div class="mapa-page">
    {{-- Sin título "Mapa Satelital" (a pedido del cliente): el contenedor del mapa sube al tope. --}}
    {{-- El JS global mapa_index.js (cargado en el layout) detecta este contenedor y
         monta el mapa, tanto en carga directa como en navegación SPA. Leaflet + el
         geocoder se cargan de forma diferida desde /vendor/leaflet (servidor propio,
         ya no desde un CDN). data-geojson = límites de los estados de Venezuela;
         data-faja-* = Faja Petrolífera del Orinoco y bloques petroleros (php tools/generar_geo_faja.php);
         data-mini-* = miniaturas de los botones de capas (php tools/generar_miniaturas_mapa.php).
         Todos los geojson son LOCALES y se cargan solo cuando se enciende su capa. --}}
    @php $geo = fn ($ruta) => asset($ruta) . '?v=' . (@filemtime(public_path($ruta)) ?: 0); @endphp
    <div id="mapa-leaflet"
         data-geojson="{{ $geo('geo/venezuela-estados.geojson') }}"
         data-municipios="{{ $geo('geo/venezuela-municipios.geojson') }}"
         data-faja-poligonal="{{ $geo('geo/faja-poligonal.geojson') }}"
         data-faja-bloques="{{ $geo('geo/faja-bloques.geojson') }}"
         data-mini-muni="{{ $geo('img/mapa/mini-municipios.png') }}"
         data-mini-faja="{{ $geo('img/mapa/mini-faja.png') }}"
         data-mini-bloques="{{ $geo('img/mapa/mini-bloques.png') }}"></div>
</div>
{{-- Frentes de trabajo = proyectos. mapa_index.js los usa para el selector "Vincular a un
     proyecto" (recomendados desde la tabla frentes_trabajo; ya NO se crean a mano en el mapa). --}}
<script>
    window.mapaFrentes = @json($frentes ?? []);
    // ¿Puede GESTIONAR proyectos? (permiso super.admin). Si es false, el mapa queda en consulta:
    // sin crear/asociar puntos, sin dibujar, sin borrar. Las rutas también lo validan en el backend.
    window.mapaPuedeEditar = @json($puedeEditar ?? false);
</script>
@endsection
