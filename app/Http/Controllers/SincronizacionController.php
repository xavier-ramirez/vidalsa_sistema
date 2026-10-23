<?php

namespace App\Http\Controllers;

use App\Support\RegistroCambios;
use App\Support\Sincronizacion\DominioEquipos;
use Illuminate\Http\Request;

/**
 * Sincronización de la copia LOCAL de cada dispositivo: GET /sync/{dominio}.
 *
 * El navegador guarda los datos en IndexedDB y pinta SIEMPRE desde ahí, con o sin internet.
 * Este endpoint le dice qué cambió desde su cursor:
 *
 *   ?desde=<cursor>&firma=<firma>&esquema=<n>
 *   → { dominio, esquema, firma, hasta, completo, datos, borrados, catalogos }
 *
 *   · completo = true: `datos` es la copia ENTERA; el cliente borra la suya y la reemplaza.
 *   · completo = false: `datos` se fusiona por id y `borrados` se quita.
 *   · `catalogos` (si viene) reemplaza cada catálogo entero.
 *   · `hasta` es el cursor para la próxima vez. Es opaco para el cliente.
 *
 * Cursor "c:<n>": número del registro de cambios (RegistroCambios), con los triggers activos.
 * Cursor "h:<huella>": modo de respaldo, si faltan los triggers. Se arma la copia entera y
 * solo se manda si su huella cambió. Más caro para el servidor, pero nunca da datos viejos.
 *
 * La copia entera se manda también si cambió el esquema de las filas o el alcance del
 * usuario (firma), o si el cursor es más viejo que lo que guarda el registro.
 */
class SincronizacionController extends Controller
{
    private const DOMINIOS = [
        'equipos' => DominioEquipos::class,
    ];

    public function __invoke(Request $request, string $dominio)
    {
        $clase = self::DOMINIOS[$dominio] ?? abort(404);
        $d     = new $clase($request->user());

        $base = [
            'dominio' => $dominio,
            'esquema' => $clase::ESQUEMA,
            'firma'   => $d->firma(),
        ];
        $desde    = (string) $request->query('desde', '');
        $vigente  = (int) $request->query('esquema') === $clase::ESQUEMA
            && $request->query('firma') === $base['firma'];

        if (! RegistroCambios::activo()) {
            $copia  = $d->completo();
            $huella = 'h:' . md5(json_encode($copia));
            if ($vigente && $desde === $huella) {
                return response()->json($base + ['hasta' => $huella, 'completo' => false, 'datos' => [], 'borrados' => [], 'catalogos' => []]);
            }

            return response()->json($base + ['hasta' => $huella, 'completo' => true, 'borrados' => []] + $copia);
        }

        $cursor = preg_match('/^c:(\d+)$/', $desde, $m) ? (int) $m[1] : null;
        $tramo  = ($vigente && $cursor !== null && RegistroCambios::cubre($cursor))
            ? RegistroCambios::desde($cursor)
            : null;

        if ($tramo === null) {
            $hasta = RegistroCambios::cursorDeCargaCompleta();

            return response()->json($base + ['hasta' => 'c:' . $hasta, 'completo' => true, 'borrados' => []] + $d->completo());
        }

        [$hasta, $agregados] = $tramo;

        return response()->json($base + ['hasta' => 'c:' . $hasta, 'completo' => false] + $d->cambios($agregados));
    }
}
