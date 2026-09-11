<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A un INVITADO que ya trae su cookie de sesión no se le vuelve a mandar.
 *
 * El problema que evita ("inicio sesión, entro y me saca con 'Tu sesión expiró'"):
 * mientras la pantalla de login está abierta salen peticiones en segundo plano con la
 * cookie de invitado A — el service worker renueva la copia de "/", /refresh-csrf, la
 * precarga de la huella, la revisión de /sw.js. Laravel reenvía la cookie de sesión en
 * CADA respuesta, así que si una de ellas llega DESPUÉS del login (red lenta: medido de
 * 1 a 17 s por petición en el servidor), el navegador vuelve a poner la cookie A encima
 * de la B del usuario ya autenticado, y la pantalla siguiente lo trata como invitado.
 *
 * Reenviar la misma cookie a un invitado no aporta nada (el navegador ya la tiene), así
 * que se quita de la respuesta. Se deja en todos los casos donde sí hace falta:
 *   · la petición no traía cookie (primera visita) → hay que darle una;
 *   · la sesión cambió de id durante la petición (login, logout, sesión inexistente) →
 *     el navegador tiene que recibir la nueva;
 *   · el usuario está autenticado → la cookie se renueva para que no caduque mientras
 *     trabaja.
 *
 * Global (bootstrap/app.php) y no en el grupo web: tiene que ver la respuesta DESPUÉS de
 * StartSession y VerifyCsrfToken, que son los que añaden las dos cookies.
 */
class NoReenviarCookieDeSesion
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$request->hasSession() || $request->user()) {
            return $response;
        }

        // Tras pasar por EncryptCookies, la cookie de la petición ya está descifrada: es el
        // id de sesión con el que llegó.
        $nombre = config('session.cookie');
        $traia  = $request->cookies->get($nombre);
        if (!is_string($traia) || $traia === '' || $traia !== $request->session()->getId()) {
            return $response;
        }

        $ruta    = config('session.path', '/');
        $dominio = config('session.domain');
        $response->headers->removeCookie($nombre, $ruta, $dominio);
        $response->headers->removeCookie('XSRF-TOKEN', $ruta, $dominio);

        return $response;
    }
}
