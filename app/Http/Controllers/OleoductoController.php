<?php

namespace App\Http\Controllers;

use App\Models\MapaOleoducto;
use App\Models\MapaOleoductoPunto;
use Illuminate\Http\Request;

/**
 * Módulo Mapa — Oleoductos (proyectos de tendido). API JSON que consume mapa_index.js:
 * cargar todos, crear un oleoducto, agregar/quitar puntos, borrar oleoducto.
 */
class OleoductoController extends Controller
{
    /** Todos los oleoductos con sus puntos (para dibujar las líneas en el mapa). */
    public function index()
    {
        $oleoductos = MapaOleoducto::with('puntos')->orderBy('nombre')->get()->map(function ($o) {
            return [
                'id'        => $o->id,
                'nombre'    => $o->nombre,
                'color'     => $o->color,
                'recorrido' => $o->recorrido, // trazo dibujado a mano ([[lat,lng],…]) o null
                'puntos' => $o->puntos->map(function ($p) {
                    return ['id' => $p->id, 'nombre' => $p->nombre, 'lat' => (float) $p->latitud, 'lng' => (float) $p->longitud, 'orden' => (int) $p->orden];
                })->values(),
            ];
        });

        return response()->json(['oleoductos' => $oleoductos]);
    }

    /** Crea un oleoducto (proyecto). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:150',
            'color'  => 'nullable|string|max:9',
        ]);

        $o = MapaOleoducto::create([
            'nombre'     => trim($data['nombre']),
            'color'      => $data['color'] ?? '#00e5ff',
            'creado_por' => auth()->id(),
        ]);

        return response()->json(['success' => true, 'oleoducto' => ['id' => $o->id, 'nombre' => $o->nombre, 'color' => $o->color, 'puntos' => []]]);
    }

    /** Agrega un punto (coordenada con nombre) a un oleoducto. */
    public function addPunto(Request $request, $id)
    {
        $o = MapaOleoducto::findOrFail($id);
        $data = $request->validate([
            'nombre' => 'nullable|string|max:150',
            'lat'    => 'required|numeric|between:-90,90',
            'lng'    => 'required|numeric|between:-180,180',
        ]);

        $orden = (int) ($o->puntos()->max('orden')) + 1;
        $p = MapaOleoductoPunto::create([
            'oleoducto_id' => $o->id,
            'nombre'       => isset($data['nombre']) ? trim($data['nombre']) : null,
            'latitud'      => $data['lat'],
            'longitud'     => $data['lng'],
            'orden'        => $orden,
        ]);

        return response()->json(['success' => true, 'punto' => ['id' => $p->id, 'nombre' => $p->nombre, 'lat' => (float) $p->latitud, 'lng' => (float) $p->longitud, 'orden' => $p->orden]]);
    }

    /** Guarda (o borra, si viene vacío) el recorrido dibujado a mano del oleoducto. */
    public function saveRecorrido(Request $request, $id)
    {
        $o = MapaOleoducto::findOrFail($id);
        $data = $request->validate([
            'recorrido'       => 'present|array|max:5000',
            'recorrido.*'     => 'array|size:2',
            'recorrido.*.*'   => 'numeric',
        ]);

        $ruta = array_map(function ($p) {
            return [(float) $p[0], (float) $p[1]];
        }, $data['recorrido']);

        $o->recorrido = count($ruta) >= 2 ? $ruta : null;
        $o->save();

        return response()->json(['success' => true, 'recorrido' => $o->recorrido]);
    }

    /** Borra un punto. */
    public function destroyPunto($id)
    {
        MapaOleoductoPunto::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    /** Borra un oleoducto completo (sus puntos caen por cascade). */
    public function destroy($id)
    {
        MapaOleoducto::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }
}
