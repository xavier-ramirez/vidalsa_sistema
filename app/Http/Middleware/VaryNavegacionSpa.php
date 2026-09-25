<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las paginas HTML salen con `Vary: X-SPA-Navigate`.
 *
 * La MISMA URL responde distinto segun esa cabecera: la navegacion SPA recibe solo el
 * contenido del modulo y el navegador la pagina completa (layouts/estructura_base). Con
 * Vary, ninguna cache —la HTTP del navegador ni la de un service worker viejo— puede servir
 * la respuesta corta a quien pidio la pagina entera.
 */
class VaryNavegacionSpa
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->setVary('X-SPA-Navigate', false);
        }

        return $response;
    }
}
