<?php

namespace App\Http\Controllers;

class SystemController extends Controller
{
    public function loginPage()
    {
        if (auth()->check()) {
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
