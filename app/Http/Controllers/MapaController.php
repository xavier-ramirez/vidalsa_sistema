<?php

namespace App\Http\Controllers;

use App\Models\FrenteTrabajo;

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
        // Los "proyectos" del mapa SON los frentes de trabajo: al vincular una ubicación se
        // elige uno de estos (no se crean proyectos a mano). Se pasan todos, con el nombre ya
        // limpio (el modelo aplica MojibakeFix), ordenados alfabéticamente para el selector.
        $frentes = FrenteTrabajo::orderBy('NOMBRE_FRENTE')
            ->get(['ID_FRENTE', 'NOMBRE_FRENTE'])
            ->map(fn ($f) => ['id' => $f->ID_FRENTE, 'nombre' => $f->NOMBRE_FRENTE])
            ->values();

        // La GESTIÓN de proyectos en el mapa (crear/asociar puntos, dibujar la línea, borrar
        // puntos/proyectos) depende del PERMISO 'super.admin' (no del rol). El resto ve el mapa
        // en modo consulta. Las rutas de escritura también están gateadas en routes/web.php.
        $puedeEditar = (bool) optional(auth()->user())->can('super.admin');

        return view('mapa', ['frentes' => $frentes, 'puedeEditar' => $puedeEditar]);
    }
}
