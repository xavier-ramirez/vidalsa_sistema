@extends('layouts.estructura_base')

@section('title', 'Mapa')

@section('content')
<div class="mapa-page">
    {{-- Título con el estilo global (page-title / h1.page-title de menu.css). La separación
         vertical se iguala a la del módulo Equipos: anulamos el margin-top y el padding del
         .page-title-card (menu.css le pone padding:10px 0) para que el título NO quede más
         abajo que en equipos; solo dejamos 16px por debajo (igual que el header de equipos). --}}
    <section class="page-title-card" style="text-align:left;margin:0 0 16px 0;padding:0;">
        <h1 class="page-title" style="margin:0;">
            <span class="page-title-line2" style="color:#000;">Mapa Satelital</span>
        </h1>
    </section>

    {{-- El JS global mapa_index.js (cargado en el layout) detecta este contenedor y
         monta el mapa, tanto en carga directa como en navegación SPA. Leaflet + el
         geocoder se cargan de forma diferida desde CDN. data-geojson = límites de los
         estados de Venezuela (archivo local, mismo origen). --}}
    <div id="mapa-leaflet"
         data-geojson="{{ asset('geo/venezuela-estados.geojson') }}?v={{ @filemtime(public_path('geo/venezuela-estados.geojson')) }}"
         data-municipios="{{ asset('geo/venezuela-municipios.geojson') }}?v={{ @filemtime(public_path('geo/venezuela-municipios.geojson')) }}"></div>
</div>
{{-- Frentes de trabajo = proyectos. mapa_index.js los usa para el selector "Vincular a un
     proyecto" (recomendados desde la tabla frentes_trabajo; ya NO se crean a mano en el mapa). --}}
<script>window.mapaFrentes = @json($frentes ?? []);</script>
@endsection
