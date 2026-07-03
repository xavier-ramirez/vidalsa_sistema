<?php

namespace App\Http\Controllers;

/**
 * Modulo de Mapa.
 *
 * Pagina nueva abierta desde el boton "Mapa" del tablero (/menu). Por ahora
 * muestra un mapa base (Leaflet) listo para graficar equipos cuando existan
 * coordenadas geograficas — los equipos hoy solo tienen frente + ubicacion en
 * texto, no lat/lng, asi que el mapa arranca centrado en Venezuela sin marcadores.
 */
class MapaController extends Controller
{
    public function index()
    {
        return view('mapa');
    }
}
