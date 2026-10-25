<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad en TODA respuesta de la app: páginas, JSON y los PDF del proxy.
 *
 * Van aquí y no en docker/nginx.conf a propósito: en nginx, un add_header dentro de un
 * `location` anula los del `server`, y ese archivo ya tiene varios (la caché de los
 * assets), así que habría que repetirlas en cada bloque. Aquí están en un solo sitio y
 * no dependen del servidor web (en local las pone igual el Apache de XAMPP).
 */
class CabecerasSeguridad
{
    public const CSP = "base-uri 'self'; object-src 'none'; form-action 'self'; frame-ancestors 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $cabeceras = $response->headers;

        // Ningún OTRO sitio puede meter la app dentro de un <iframe> (clickjacking: una
        // página ajena invisible encima que roba los clics). SAMEORIGIN y no DENY: el visor
        // de PDF y la impresión usan iframes de la propia app.
        $cabeceras->set('X-Frame-Options', 'SAMEORIGIN');

        // El navegador obedece el Content-Type y no "adivina": un archivo subido no puede
        // acabar ejecutándose como página o script.
        $cabeceras->set('X-Content-Type-Options', 'nosniff');

        // A otros sitios solo viaja el dominio, nunca la ruta con sus IDs o búsquedas. Es lo
        // que ya hacen los navegadores modernos por defecto; se fija para los viejos.
        $cabeceras->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // CSP: lo que un código inyectado usaría para salir de la página. Un <base> ajeno
        // desviaría todos los enlaces y scripts relativos; <object>/<embed> cargan plugins;
        // un <form> podría mandar los datos (o la clave del login) a otro sitio; y
        // frame-ancestors es el X-Frame-Options de arriba para navegadores nuevos.
        // NO limita script-src: las páginas tienen mucho JS en línea (onclick, <script>), y
        // cerrarlo exige sacar ese código a archivos antes o se rompen pantallas.
        // Solo en páginas: en un PDF, object-src 'none' hace que Chrome no lo muestre.
        if (str_starts_with((string) $cabeceras->get('Content-Type'), 'text/html')) {
            $cabeceras->set('Content-Security-Policy', self::CSP);
        }

        // HSTS: durante un año el navegador ya no intenta entrar por http. Solo sobre https
        // (por http el navegador la ignora). Detrás de EasyPanel isSecure() sale del
        // X-Forwarded-Proto del proxy: si algún día se restringe trustProxies, el proxy de
        // EasyPanel tiene que seguir en la lista o esto deja de enviarse.
        if ($request->isSecure()) {
            $cabeceras->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
