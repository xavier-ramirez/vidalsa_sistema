<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Support\RegistroCambios;
use App\Support\Sincronizacion\DominioAlmacen;
use App\Support\Sincronizacion\DominioEquipos;
use App\Support\Sincronizacion\DominioFallas;
use App\Support\Sincronizacion\DominioMovilizaciones;
use Illuminate\Http\Request;

/**
 * Sincronización de la copia LOCAL de cada dispositivo: GET /sync/{dominio}.
 *
 * El navegador guarda los datos en IndexedDB y pinta SIEMPRE desde ahí, con o sin internet.
 * Este endpoint le dice qué cambió desde su cursor:
 *
 *   ?desde=<cursor>&firma=<firma>&esquema=<n>
 *   → { dominio, esquema, firma, espera, hasta, completo, datos, borrados, catalogos }
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
 * usuario (firma), si el cursor es más viejo que lo que guarda el registro, o si el dominio
 * lo pide porque un cambio movió su alcance (cambios() devuelve null).
 */
class SincronizacionController extends Controller
{
    private const ESPERA_SEG          = 15;
    private const ESPERA_RESPALDO_SEG = 120;

    private const DOMINIOS = [
        'equipos'        => DominioEquipos::class,
        'movilizaciones' => DominioMovilizaciones::class,
        'almacen'        => DominioAlmacen::class,
        'fallas'         => DominioFallas::class,
    ];

    /**
     * Los dominios que el usuario puede copiar, separados por coma. El layout los pone en
     * <meta name="copia-dominios"> y copia-local.js los mantiene al día en segundo plano,
     * esté abierto el módulo que sea.
     */
    public static function dominiosDe(?Usuario $usuario): string
    {
        if (! $usuario) return '';

        return implode(',', array_keys(array_filter(self::DOMINIOS, fn ($clase) => $clase::puede($usuario))));
    }

    /**
     * La llave del SERVIDOR con que el navegador envuelve la de su copia cifrada (ver
     * public/js/offline/llave-local.js). Solo se entrega con sesión abierta: así la copia que
     * queda en el equipo no se puede leer sin entrar, con clave o con huella. Sale de APP_KEY
     * y del usuario, sin guardar nada: cambiar APP_KEY deja ilegibles las copias (se vuelven a
     * bajar) y lo pendiente de las bandejas, que solo se recupera entrando con la clave.
     */
    public function llave(Request $request)
    {
        $llave = hash_hmac('sha256', 'copia-local|' . $request->user()->ID_USUARIO, (string) config('app.key'), true);

        return response()->json(['llave' => base64_encode($llave)])->header('Cache-Control', 'no-store');
    }

    public function __invoke(Request $request, string $dominio)
    {
        $clase = self::DOMINIOS[$dominio] ?? abort(404);
        abort_unless($clase::puede($request->user()), 403);
        $d = new $clase($request->user());

        $activo = RegistroCambios::activo();
        $base = [
            'dominio' => $dominio,
            'esquema' => $clase::ESQUEMA,
            'firma'   => $d->firma(),
            // Segundos hasta la próxima pregunta: en el modo de respaldo cada una arma la copia entera.
            'espera'  => $activo ? self::ESPERA_SEG : self::ESPERA_RESPALDO_SEG,
        ];
        $desde    = (string) $request->query('desde', '');
        $vigente  = (int) $request->query('esquema') === $clase::ESQUEMA
            && $request->query('firma') === $base['firma'];

        if (! $activo) {
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

        $cambios = null;
        if ($tramo !== null) {
            [$hasta, $agregados] = $tramo;
            $cambios = $d->cambios($agregados);
        }

        if ($cambios === null) {
            $hasta = RegistroCambios::cursorDeCargaCompleta();

            return response()->json($base + ['hasta' => 'c:' . $hasta, 'completo' => true, 'borrados' => []] + $d->completo());
        }

        return response()->json($base + ['hasta' => 'c:' . $hasta, 'completo' => false] + $cambios);
    }
}
