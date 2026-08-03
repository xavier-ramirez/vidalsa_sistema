<?php

namespace App\Http\Controllers;

use App\Models\FrenteTrabajo;
use App\Models\MapaOleoducto;
use App\Models\MapaOleoductoPunto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Módulo Mapa — Oleoductos (proyectos de tendido). API JSON que consume mapa_index.js.
 * Los proyectos SON los frentes de trabajo: no se crean a mano; al vincular una ubicación se
 * elige un frente y sus puntos se cuelgan del oleoducto de ese frente (uno por frente,
 * find-or-create). Endpoints: cargar todos, agregar punto a un frente, guardar recorrido,
 * borrar punto, borrar oleoducto.
 */
class OleoductoController extends Controller
{
    /** Todos los oleoductos con sus puntos (para dibujar las líneas en el mapa). */
    public function index()
    {
        // Este orden NO decide cómo se listan los grupos en pantalla: el mapa los indexa por id
        // y lo reordena él mismo (gruposOrdenados en mapa_index.js). Lo que sí determina es el
        // orden en que se DIBUJAN, o sea qué tubería queda encima si dos se superponen; por eso
        // sigue el mismo criterio que la presentación (alfabético, sueltas al final).
        $oleoductos = MapaOleoducto::with(['puntos' => function ($q) { $q->withCount('oleoductos'); }])
            ->orderBy('suelto')->orderBy('nombre')->get()->map(function ($o) {
            return [
                'id'        => $o->id,
                'id_frente' => $o->id_frente, // frente al que pertenece este proyecto
                'suelto'    => (bool) $o->suelto, // grupo de ubicaciones SIN proyecto
                'nombre'    => $o->nombre,
                'color'     => $o->color,
                'recorrido' => $o->recorrido, // trazo dibujado a mano ([[lat,lng],…]) o null
                'puntos' => $o->puntos->map(function ($p) {
                    return [
                        'id'     => $p->id,
                        'nombre' => $p->nombre,
                        'lat'    => (float) $p->latitud,
                        'lng'    => (float) $p->longitud,
                        'orden'  => (int) $p->pivot->orden, // el orden es POR proyecto
                        // En cuántos proyectos está: el mapa lo usa para avisar al desvincular.
                        'proyectos' => $p->oleoductos_count,
                    ];
                })->values(),
            ];
        });

        return response()->json(['oleoductos' => $oleoductos]);
    }

    /**
     * Agrega un punto (coordenada con nombre) al proyecto de un FRENTE. El oleoducto del
     * frente se crea la primera vez (find-or-create por id_frente) usando el nombre del frente.
     */
    public function addPuntoFrente(Request $request, $idFrente)
    {
        $frente = FrenteTrabajo::findOrFail($idFrente);
        $data = $request->validate([
            'nombre' => 'nullable|string|max:150',
            'lat'    => 'required|numeric|between:-90,90',
            'lng'    => 'required|numeric|between:-180,180',
            'color'  => 'nullable|string|max:9',
        ]);

        $o = $this->grupoUnico(
            ['id_frente' => $frente->ID_FRENTE],
            ['nombre' => $frente->NOMBRE_FRENTE, 'color' => $data['color'] ?? '#00e5ff']
        );

        return $this->agregarPunto($o, $data);
    }

    /**
     * Agrega una ubicación SUELTA: punto con nombre que NO pertenece a ningún proyecto. Todas
     * cuelgan de un único oleoducto reservado (suelto = true, sin frente y sin línea), así
     * comparten el mismo guardado/dibujo/leyenda/exportación que los puntos de proyecto.
     */
    public function addPuntoSuelto(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'nullable|string|max:150',
            'lat'    => 'required|numeric|between:-90,90',
            'lng'    => 'required|numeric|between:-180,180',
            'color'  => 'nullable|string|max:9',
        ]);

        // Se guarda EN MAYÚSCULAS (pedido del cliente: que el dato quede así en la BD). No es
        // el texto que se ve: el mapa rotula SIEMPRE este grupo con su constante SUELTO_LABEL,
        // así que si un día divergen, manda la del JS. Se mantienen iguales por coherencia del
        // dato guardado, no porque el mapa dependa de ello.
        $o = $this->grupoUnico(
            ['suelto' => true],
            ['nombre' => 'CONVERGENCIA ADMINISTRATIVA', 'color' => $data['color'] ?? '#00e5ff']
        );

        return $this->agregarPunto($o, $data);
    }

    /**
     * Busca el grupo que cumple $clave y, si no existe, lo crea. Hay UNO por frente y UNO solo
     * para las ubicaciones sueltas, pero la base no lo puede imponer con un índice único
     * ('id_frente' es nulo en el grupo suelto y 'suelto' es un booleano con muchas filas en
     * false). Por eso se resuelve con transacción + bloqueo: sin él, dos guardados simultáneos
     * sobre el mismo frente (o dos sueltos) creaban DOS grupos y los puntos quedaban repartidos.
     */
    private function grupoUnico(array $clave, array $valores): MapaOleoducto
    {
        return DB::transaction(function () use ($clave, $valores) {
            $existente = MapaOleoducto::where($clave)->lockForUpdate()->first();
            if ($existente) return $existente;

            return MapaOleoducto::create($clave + $valores + ['creado_por' => auth()->id()]);
        });
    }

    /**
     * Cuelga el punto del oleoducto dado y arma la respuesta JSON que espera mapa_index.js.
     * Común a los puntos de proyecto y a las ubicaciones sueltas: lo único que cambia entre
     * los dos endpoints es CÓMO se resuelve el oleoducto.
     */
    private function agregarPunto(MapaOleoducto $o, array $data)
    {
        $creado = $o->wasRecentlyCreated;

        $p = MapaOleoductoPunto::create([
            'nombre'   => isset($data['nombre']) ? trim($data['nombre']) : null,
            'latitud'  => $data['lat'],
            'longitud' => $data['lng'],
        ]);
        $orden = $this->vincular($o, $p);

        return $this->respuestaPunto($o, $p, $orden, $creado);
    }

    /**
     * Forma ÚNICA de la respuesta de "punto dentro de un proyecto". La comparten los tres
     * caminos que dejan un punto colgando de un grupo (crear en un frente, crear suelto y
     * vincular uno existente), porque el front los procesa con el mismo código: si el JSON
     * de uno se desviaba del de los otros, el mapa se dibujaba a medias solo por ese camino.
     *
     * `oleoducto_nuevo` va relleno SOLO cuando el grupo nació en esta misma llamada: es lo
     * que el front necesita para dibujar un proyecto que hasta ahora no existía.
     */
    private function respuestaPunto(MapaOleoducto $o, MapaOleoductoPunto $p, int $orden, bool $creado)
    {
        return response()->json([
            'success'         => true,
            'oleoducto_id'    => $o->id,
            'oleoducto_nuevo' => $creado ? [
                'id' => $o->id, 'id_frente' => $o->id_frente, 'suelto' => (bool) $o->suelto,
                'nombre' => $o->nombre, 'color' => $o->color, 'recorrido' => $o->recorrido, 'puntos' => [],
            ] : null,
            'punto' => ['id' => $p->id, 'nombre' => $p->nombre, 'lat' => (float) $p->latitud, 'lng' => (float) $p->longitud, 'orden' => $orden, 'proyectos' => $p->oleoductos()->count()],
        ]);
    }

    /** Guarda (o borra, si viene vacío) el recorrido dibujado a mano del oleoducto. */
    public function saveRecorrido(Request $request, $id)
    {
        $o = MapaOleoducto::findOrFail($id);
        // El grupo de ubicaciones sin frente NO es un tendido: nunca lleva línea.
        abort_if($o->suelto, 422, 'Los puntos sin frente no llevan línea.');
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

    /**
     * Coloca el punto al final de la línea de ese proyecto (si ya estaba, respeta su sitio).
     * Devuelve el orden que le quedó.
     */
    private function vincular(MapaOleoducto $o, MapaOleoductoPunto $p): int
    {
        $ya = $o->puntos()->where('mapa_oleoducto_puntos.id', $p->id)->first();
        if ($ya) return (int) $ya->pivot->orden;

        $orden = (int) $o->puntos()->max('mapa_oleoducto_punto_vinculos.orden') + 1;
        $o->puntos()->attach($p->id, ['orden' => $orden]);
        return $orden;
    }

    /**
     * Vincula un punto QUE YA EXISTE a otro proyecto: el mismo sitio pasa a estar en los dos sin
     * duplicar la coordenada. Editarlo o moverlo después vale para todos los proyectos a la vez.
     */
    /**
     * Agrega un punto YA EXISTENTE al proyecto de un FRENTE, creando ese proyecto si el
     * frente todavía no tenía ninguno.
     *
     * Sustituye a un vincularPunto($idOleoducto, $idPunto) que exigía el id de un proyecto:
     * como un proyecto solo nace al guardar su primer punto, aquello solo dejaba compartir
     * el punto con frentes que YA tuvieran puntos en el mapa; el resto ni salía en la lista.
     * Reusa grupoUnico() —el mismo "uno por frente" de addPuntoFrente— así que los dos
     * caminos no pueden crear proyectos duplicados para el mismo frente.
     */
    public function vincularPuntoFrente(Request $request, $idFrente, $idPunto)
    {
        $frente = FrenteTrabajo::findOrFail($idFrente);
        $p      = MapaOleoductoPunto::findOrFail($idPunto);
        $data   = $request->validate(['color' => 'nullable|string|max:9']);

        $o = $this->grupoUnico(
            ['id_frente' => $frente->ID_FRENTE],
            ['nombre' => $frente->NOMBRE_FRENTE, 'color' => $data['color'] ?? '#00e5ff']
        );
        $creado = $o->wasRecentlyCreated;

        return $this->respuestaPunto($o, $p, $this->vincular($o, $p), $creado);
    }

    /**
     * Quita el punto DE ESE PROYECTO. Si el punto estaba compartido sigue existiendo en los
     * demás; si ese era su último proyecto, ya no pinta nada en ningún sitio y se borra.
     */
    public function destroyPunto($idOleoducto, $idPunto)
    {
        $o = MapaOleoducto::findOrFail($idOleoducto);
        $p = MapaOleoductoPunto::findOrFail($idPunto);

        $o->puntos()->detach($p->id);
        $quedan = $p->oleoductos()->count();
        if ($quedan === 0) $p->delete();

        return response()->json(['success' => true, 'borrado' => $quedan === 0, 'quedan' => $quedan]);
    }

    /**
     * Borra un oleoducto completo. Sus vínculos caen por cascade; los puntos que solo estaban en
     * él quedan sin proyecto y se borran. Los COMPARTIDOS sobreviven en los otros proyectos.
     * El frente NO se toca.
     */
    public function destroy($id)
    {
        $o = MapaOleoducto::findOrFail($id);
        $ids = $o->puntos()->pluck('mapa_oleoducto_puntos.id');
        $o->delete();

        $huerfanos = MapaOleoductoPunto::whereIn('id', $ids)->doesntHave('oleoductos')->pluck('id');
        if ($huerfanos->isNotEmpty()) MapaOleoductoPunto::whereIn('id', $huerfanos)->delete();

        return response()->json(['success' => true]);
    }
}
