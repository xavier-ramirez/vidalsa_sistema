<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SystemController extends Controller
{
    public function loginPage(Request $request)
    {
        if (auth()->check()) {
            // Llega de salir (cierre por inactividad de partials/session_timeout o el botón
            // «Salir», los dos por window.salirDelSistema) y hay una sesión abierta: o su POST
            // /logout no llegó, o se volvió a entrar desde otra pestaña mientras esta salía.
            // Aquí no se distinguen: las dos comparten cookie y un GET no trae el token de la
            // sesión que se cerró. Por eso NO se cierra nada: se da el login sin mandar al menú
            // y su JS (precalentarCsrf) cierra la de ese token, o va al menú si la abierta es otra.
            // El aviso puede traer "_pendientes" (cambios sin subir en la bandeja).
            // is_string: un ?aviso[]= llega como array y convertirlo a texto daría un 500.
            $aviso = $request->query('aviso');
            $aviso = is_string($aviso) ? preg_replace('/_pendientes$/', '', $aviso) : '';
            if (in_array($aviso, ['inactividad', 'salida'], true)) {
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
