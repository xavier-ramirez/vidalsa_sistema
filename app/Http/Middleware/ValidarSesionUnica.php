<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ValidarSesionUnica
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Rutas de AUTENTICACIÓN: quedan FUERA de esta comprobación a propósito.
        // En ellas el usuario está pidiendo justamente una sesión NUEVA (o cerrando la
        // que tiene), así que un SESSION_TOKEN viejo que ya no cuadra no debe cortar la
        // petición. Sin esta exclusión pasaba esto: quien dejó la sesión abierta en este
        // equipo y luego entró desde otro dispositivo, al volver aquí y pulsar "Entrar"
        // con sus credenciales CORRECTAS recibía 401 "Sesión iniciada en otro
        // dispositivo" — el POST ni siquiera llegaba al LoginController. El logout que
        // hace este middleware solo surtía efecto para el intento SIGUIENTE, o sea que
        // había que pulsar Entrar dos veces. Se agrava con la PWA, donde el Service
        // Worker sirve el login cacheado aunque la sesión siga viva.
        // No se pierde seguridad: el propio login/webauthn regenera la sesión y reescribe
        // el SESSION_TOKEN, que es lo que este middleware protege.
        if (
            $request->is('logout') ||
            $request->is('refresh-csrf') ||
            $request->is('webauthn/login') ||
            $request->is('webauthn/login-options') ||
            ($request->isMethod('post') && $request->is('/'))
        ) {
            return $next($request);
        }

        if (Auth::check()) {
            $user = Auth::user();
            $sessionToken = $request->session()->get('current_session_token');

            // Si hay un token en sesión y no coincide con el de la DB
            // (Si no hay token en sesión, es probable que la sesión haya expirado naturalmente, 
            // dejar que el framework maneje la expiración normal en lugar de forzar logout)
            if ($sessionToken && $user->SESSION_TOKEN !== $sessionToken) {
                // POR QUÉ no cuadra, antes de tocar nada: un SESSION_TOKEN en NULL no es
                // "entró en otro lado", es una sesión REVOCADA a propósito, y hoy el único
                // que revoca es un cambio de clave (Usuario::establecerClave). Distinguirlo
                // cambia un "te entraron en otro dispositivo" —que asusta— por el motivo real.
                $motivo  = $user->SESSION_TOKEN === null ? 'clave_cambiada' : 'otro_dispositivo';
                $mensaje = $motivo === 'clave_cambiada'
                    ? 'Tu clave cambió. Inicia sesión con la nueva.'
                    : 'Sesión iniciada en otro dispositivo.';

                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                if ($request->expectsJson()) {
                    return response()->json(['message' => $mensaje], 401);
                }
                
                // ?aviso= y no withErrors(): el Service Worker sirve el login desde su
                // caché, así que el flash se consumía sin pintarse nunca y el usuario
                // volvía al login sin saber por qué.
                return redirect('/?aviso=' . $motivo);
            }
        }

        return $next($request);
    }
}
