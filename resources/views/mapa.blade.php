@extends('layouts.estructura_base')

@section('title', 'Mapa')

@section('content')
<div class="mapa-page">
    {{-- Sin título "Mapa Satelital" (a pedido del cliente): el contenedor del mapa sube al tope. --}}
    {{-- El JS global mapa_index.js (cargado en el layout) detecta este contenedor y
         monta el mapa, tanto en carga directa como en navegación SPA. Leaflet + el
         geocoder se cargan de forma diferida desde /vendor/leaflet (servidor propio,
         ya no desde un CDN). data-geojson = límites de los estados de Venezuela. --}}
    <div id="mapa-leaflet"
         data-geojson="{{ asset('geo/venezuela-estados.geojson') }}?v={{ @filemtime(public_path('geo/venezuela-estados.geojson')) }}"
         data-municipios="{{ asset('geo/venezuela-municipios.geojson') }}?v={{ @filemtime(public_path('geo/venezuela-municipios.geojson')) }}"></div>
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
