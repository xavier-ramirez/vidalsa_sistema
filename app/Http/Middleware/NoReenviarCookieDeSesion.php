<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un INVITADO solo recibe cookie de sesión cuando la pide de verdad.
 *
 * El problema que evita ("inicio sesión, entro y me saca con 'Tu sesión expiró'"):
 * mientras la pantalla de login está abierta salen peticiones en segundo plano que
 * pueden llegar DESPUÉS del login (red lenta: medido de 1 a 17 s por petición en el
 * servidor): el service worker renueva la copia de "/", descarga las páginas guardadas
 * y revisa /sw.js. Su Set-Cookie de invitado pisaba la cookie del usuario ya
 * autenticado y la petición siguiente lo trataba como invitado.
 *
 * Pasa sobre todo al volver a abrir el sistema: la cookie vence a la vez que la sesión
 * (SESSION_LIFETIME), así que el navegador llega SIN cookie y Laravel le crea una distinta
 * en cada respuesta. Por eso no basta con no reenviar la misma cookie.
 *
 * Se manda solo en los dos sitios que la necesitan para iniciar sesión, y solo si el
 * navegador aún no tiene esa sesión (RUTAS_QUE_LA_ENTREGAN). El login los pide en orden y
 * corta los que sigan en vuelo al pulsar Entrar (cortarPrecalentar). El resto de
 * respuestas a un invitado, incluido el cierre de sesión, no tocan la cookie. El usuario
 * autenticado sí la recibe siempre: se renueva para que no caduque mientras trabaja.
 *
 * Global (bootstrap/app.php) y no en el grupo web: tiene que ver la respuesta DESPUÉS de
 * StartSession y VerifyCsrfToken, que son los que añaden las dos cookies.
 */
class NoReenviarCookieDeSesion
{
    /** /refresh-csrf: el token del login. login-options: el reto de la huella queda en sesión. */
    private const RUTAS_QUE_LA_ENTREGAN = ['refresh-csrf', 'webauthn/login-options'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$request->hasSession() || $request->user()) {
            return $response;
        }

        // Tras pasar por EncryptCookies, la cookie de la petición ya está descifrada: es el
        // id de sesión con el que llegó.
        $nombre = config('session.cookie');
        if ($request->is(...self::RUTAS_QUE_LA_ENTREGAN)
            && $request->cookies->get($nombre) !== $request->session()->getId()) {
            return $response;
        }

        $ruta    = config('session.path', '/');
        $dominio = config('session.domain');
        $response->headers->removeCookie($nombre, $ruta, $dominio);
        $response->headers->removeCookie('XSRF-TOKEN', $ruta, $dominio);

        return $response;
    }
}
