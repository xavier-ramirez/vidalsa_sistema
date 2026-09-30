@extends('layouts.estructura_base')

@section('title', 'Mapa')

@section('content')
<div class="mapa-page">
    {{-- Sin título "Mapa Satelital" (a pedido del cliente): el contenedor del mapa sube al tope. --}}
    {{-- El JS global mapa_index.js monta el mapa detectando este contenedor, tanto en
         carga directa como en navegación SPA. El layout no lo trae en todas las páginas:
         lo pide al ver este #mapa-leaflet (ModuleManager, ver estructura_base). Leaflet + el
         geocoder se cargan de forma diferida desde /vendor/leaflet (servidor propio,
         ya no desde un CDN). data-geojson = límites de los estados de Venezuela;
         data-faja-* = Faja Petrolífera del Orinoco y bloques petroleros (php tools/generar_geo_faja.php);
         data-mini-* = miniaturas de los botones de capas (php tools/generar_miniaturas_mapa.php);
         data-equipos-gps = posición GPS de los equipos (MapaController::equiposGps);
         data-equipos-gps-exportar = el Excel del panel de equipos (MapaController::exportarEquiposGps).
         Todos los geojson son LOCALES y, como los equipos, se cargan solo cuando se enciende su capa. --}}
    @php $geo = fn ($ruta) => asset($ruta) . '?v=' . (@filemtime(public_path($ruta)) ?: 0); @endphp
    {{-- Dos columnas: el mapa y, a su derecha, el sitio del panel de la capa Equipos. El panel NO
         va flotando encima del mapa (antes era un control de Leaflet en la esquina y tapaba media
         pantalla): vive en #mapa-lateral, que está vacío y sin ancho mientras ninguna capa lo
         pida. Al encenderse la capa, mapa_index.js le pone la clase "con-panel" a este contenedor
         y avisa a Leaflet del nuevo ancho (invalidateSize), que si no se queda con las piezas del
         mapa a medio dibujar. --}}
    <div class="mapa-layout" id="mapa-layout">
    <div id="mapa-leaflet"
         data-geojson="{{ $geo('geo/venezuela-estados.geojson') }}"
         data-municipios="{{ $geo('geo/venezuela-municipios.geojson') }}"
         data-faja-poligonal="{{ $geo('geo/faja-poligonal.geojson') }}"
         data-faja-bloques="{{ $geo('geo/faja-bloques.geojson') }}"
         data-mini-muni="{{ $geo('img/mapa/mini-municipios.png') }}"
         data-mini-faja="{{ $geo('img/mapa/mini-faja.png') }}"
         data-mini-bloques="{{ $geo('img/mapa/mini-bloques.png') }}"
         data-mini-equipos="{{ $geo('img/mapa/mini-equipos.png') }}"
         data-equipos-gps="{{ route('mapa.equiposGps') }}"
         data-equipos-gps-exportar="{{ route('mapa.equiposGps.exportar') }}"></div>
        <aside class="mapa-lateral" id="mapa-lateral"></aside>
    </div>
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
