<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Http\Request;

class SystemController extends Controller
{
    public function loginPage(Request $request)
    {
        if (auth()->check()) {
            // Llega del cierre por inactividad (partials/session_timeout) con la sesión todavía
            // abierta: su POST /logout no llegó (red lenta). Se cierra aquí en vez de mandarla
            // al menú ("se cerró la sesión y se abrió"). El JS del login hace lo mismo cuando
            // la pantalla sale del caché del Service Worker, pero tras cada deploy ese caché
            // está vacío, la petición llega aquí y antes volvía al menú sin que ese JS corriera.
            // Solo si la navegación es de la propia app: un enlace de otro sitio no la cierra.
            if ($request->query('aviso') === 'inactividad'
                && in_array($request->header('Sec-Fetch-Site', 'same-origin'), ['same-origin', 'none'], true)) {
                LoginController::cerrarSesion($request);
                return view('auth.inicio_sesion');
            }
            return redirect()->route('menu');
        }
        return view('auth.inicio_sesion');
    }

    public function loginRedirect()
    {
        return redirect()->route('login');
    }

    public function refreshCsrf()
    {
        // El token DEBE viajar siempre fresco: si el navegador (o un proxy) cachea
        // esta respuesta, el login inyectaría un token caducado -> 419. Forzamos
        // no-store para que cada handshake traiga el token de la sesión vigente.
        //
        // X-Auth-Status: esta ruta es PÚBLICA (la usa también el login para el
        // handshake del token), así que cuando la sesión del usuario ya expiró sigue
        // devolviendo 200 con un token de INVITADO. El monitor de sesión
        // (partials/session_timeout) lee este header para distinguir "sesión viva"
        // (authenticated) de "sesión ya caída" (guest) y no falsear la renovación.
        return response(csrf_token())
            ->header('Content-Type', 'text/plain')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('X-Auth-Status', auth()->check() ? 'authenticated' : 'guest');
    }
}
