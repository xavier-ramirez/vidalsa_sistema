<?php

namespace App\Http\Controllers;

use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use App\Services\Gps51Service;
use Illuminate\Http\Request;

/**
 * Modulo de Mapa.
 *
 * Pagina nueva abierta desde el boton "Mapa" del tablero (/menu): mapa base (Leaflet) con las
 * capas de estados, municipios, Faja/bloques petroleros, los proyectos (frentes) y los EQUIPOS
 * con GPS (posición real, vía los enlaces compartidos de GPS51 — ver Gps51Service).
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

    /**
     * Capa "Equipos" del mapa, paso 1: los equipos con enlace de GPS51 que el usuario puede ver
     * (sus frentes, menos los bloqueados) con la posición que YA está en caché, y en `pendientes`
     * los que falta consultar. Responde al instante: GPS51 es lento y las posiciones que faltan
     * las pide el mapa por tandas a equiposGpsPosiciones(). El authcode NO viaja al navegador.
     */
    public function equiposGps(Request $request)
    {
        // Un enlace de GPS51 del que no se puede sacar el authcode (otro formato) no se lista: nunca
        // tendría posición y quedaría para siempre como "cargando".
        $equipos = $this->equiposConGps($request)
            ->with(['frenteActual:ID_FRENTE,NOMBRE_FRENTE', 'documentacion:ID_EQUIPO,PLACA', 'tipo:id,nombre'])
            ->get(['ID_EQUIPO', 'id_tipo_equipo', 'CODIGO_PATIO', 'NUMERO_ETIQUETA', 'MARCA', 'MODELO',
                   'SERIAL_CHASIS', 'SERIAL_DE_MOTOR', 'LINK_GPS', 'ID_FRENTE_ACTUAL', 'ESTADO_OPERATIVO'])
            ->filter(fn ($e) => Gps51Service::authcode($e->LINK_GPS) !== null);

        $enCache = Gps51Service::enCache($equipos->map(fn ($e) => Gps51Service::authcode($e->LINK_GPS))->all());

        $pendientes = [];
        $items = $equipos->map(function ($e) use ($enCache, &$pendientes) {
            $authcode = Gps51Service::authcode($e->LINK_GPS);
            $gps = $authcode ? ($enCache[$authcode] ?? null) : null;
            if ($authcode && $gps === null) {
                $pendientes[] = $e->ID_EQUIPO;
            }
            return [
                'id'            => $e->ID_EQUIPO,
                'placa'         => optional($e->documentacion)->PLACA,
                'codigo'        => $e->CODIGO_PATIO,
                'etiqueta'      => $e->NUMERO_ETIQUETA,
                'serial_chasis' => $e->SERIAL_CHASIS,
                'serial_motor'  => $e->SERIAL_DE_MOTOR,
                'tipo'          => optional($e->tipo)->nombre,
                'marca'         => $e->MARCA,
                'modelo'        => $e->MODELO,
                'estado'        => $e->ESTADO_OPERATIVO,
                'frente'        => $e->frenteActual
                    ? ['id' => $e->frenteActual->ID_FRENTE, 'nombre' => $e->frenteActual->NOMBRE_FRENTE]
                    : null,
                'gps'           => $gps,   // ver Gps51Service::normalizar(); null = aún sin consultar
            ];
        })->values();

        return response()->json(['equipos' => $items, 'pendientes' => $pendientes, 'lote' => Gps51Service::LOTE]);
    }

    /**
     * Capa "Equipos", paso 2: la posición de una tanda de equipos (hasta Gps51Service::LOTE ids),
     * consultando a GPS51 las que no están en caché. Devuelve { id: gps } — gps null si GPS51 no
     * respondió (el mapa lo reintenta en la siguiente vuelta).
     */
    public function equiposGpsPosiciones(Request $request)
    {
        $data = $request->validate([
            'ids'   => ['required', 'array', 'max:' . Gps51Service::LOTE],
            'ids.*' => ['integer'],
        ]);

        $equipos = $this->equiposConGps($request)->whereIn('ID_EQUIPO', $data['ids'])->get(['ID_EQUIPO', 'LINK_GPS']);
        $posiciones = Gps51Service::posiciones($equipos->map(fn ($e) => Gps51Service::authcode($e->LINK_GPS))->all());

        $out = [];
        foreach ($equipos as $e) {
            $authcode = Gps51Service::authcode($e->LINK_GPS);
            $out[$e->ID_EQUIPO] = $authcode ? ($posiciones[$authcode] ?? null) : null;
        }

        return response()->json(['posiciones' => (object) $out]);
    }

    /** Dirección escrita de la posición actual de un equipo (la ficha del mapa la pide al abrirse). */
    public function equipoGpsDireccion(Request $request, int $id)
    {
        $equipo = $this->equiposConGps($request)->whereKey($id)->first(['ID_EQUIPO', 'LINK_GPS']);
        abort_unless($equipo, 404);

        $authcode = Gps51Service::authcode($equipo->LINK_GPS);
        $pos = $authcode ? (Gps51Service::posiciones([$authcode])[$authcode] ?? null) : null;
        if (!$pos || empty($pos['ok'])) {
            return response()->json(['direccion' => null]);
        }

        return response()->json(['direccion' => Gps51Service::direccion($authcode, $pos['lat'], $pos['lng'])]);
    }

    /** Equipos con enlace de GPS51 dentro de los frentes que el usuario puede ver. */
    private function equiposConGps(Request $request)
    {
        $query = Equipo::query()->where('LINK_GPS', 'like', '%gps51%');
        return $request->user()->aplicarScopeFrentesEquipos($query);
    }
}
