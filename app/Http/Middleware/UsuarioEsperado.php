<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La pestaña dice de quién es (cabecera X-Usuario-Esperado = su <meta usuario-id>). Si la
 * cookie ya es de OTRO usuario —salió en otra pestaña y entró otra persona en el mismo
 * equipo—, no se le contesta con los datos del que entró: 401 con code 'otro_usuario'.
 *
 * Punto ÚNICO de esta comprobación. La mandan:
 *   · la bandeja sin internet (bandeja.js): lo guardado no se aplica a nombre de otro;
 *   · la copia local (copia-local.js · /sync): no guarda lo del nuevo como copia del anterior;
 *   · la navegación SPA (navegacion.js · CABECERAS_SPA): no guarda su pantalla como la del
 *     anterior ni la pinta en su pestaña.
 *
 * Sin la cabecera, o sin sesión, no hace nada: lo decide el resto (auth, ValidarSesionUnica).
 */
class UsuarioEsperado
{
    public const CABECERA = 'X-Usuario-Esperado';

    public function handle(Request $request, Closure $next): Response
    {
        $esperado = (string) $request->header(self::CABECERA, '');
        $usuario = $request->user();

        if ($esperado !== '' && $usuario && $esperado !== (string) $usuario->ID_USUARIO) {
            return response()->json([
                'message' => 'La sesión abierta es de otro usuario.',
                'code' => 'otro_usuario',
            ], 401);
        }

        return $next($request);
    }
}
