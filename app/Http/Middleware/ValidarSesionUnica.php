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
        // petición. Sin esta exclusión, quien tenía en este equipo una sesión de antes de
        // cambiar la clave, al pulsar "Entrar" con la clave NUEVA recibía el 401 de abajo: el
        // POST ni siquiera llegaba al LoginController y había que pulsar Entrar dos veces. Se
        // agrava con la PWA, donde el Service Worker sirve el login cacheado aunque la sesión
        // siga viva.
        // No se pierde seguridad: el propio login/webauthn regenera la sesión y le copia el
        // SESSION_TOKEN vigente, que es lo que este middleware protege.
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

            $motivo = null;
            if ($user->ESTATUS === 'INACTIVO') {
                // Lo desactivó un administrador con la sesión abierta: el login solo lo
                // comprueba al entrar, así que sin esto seguía trabajando.
                $motivo  = 'usuario_inactivo';
                $mensaje = 'Tu usuario fue desactivado. Contacta al administrador.';
            }
            // Si hay un token en sesión y no coincide con el de la DB
            // (Si no hay token en sesión, es probable que la sesión haya expirado naturalmente,
            // dejar que el framework maneje la expiración normal en lugar de forzar logout)
            elseif ($sessionToken && $user->SESSION_TOKEN !== $sessionToken) {
                // Varias sesiones a la vez SÍ se permiten: todas comparten el mismo token
                // (Usuario::tokenDeSesion) y entrar en otro equipo no lo cambia. Así que un
                // token que no cuadra solo puede venir de un cambio de clave: o sigue en NULL
                // (Usuario::establecerClave) o ya se entró con la clave nueva y hay otro.
                // Si alguna vez hace falta revocar por otro motivo, hay que traer el motivo
                // consigo en vez de deducirlo de aquí.
                $motivo  = 'clave_cambiada';
                $mensaje = 'Tu clave cambió. Inicia sesión con la nueva.';
            }

            if ($motivo) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                if ($request->expectsJson()) {
                    // `motivo` es el MISMO código que usa la redirección de abajo (?aviso=).
                    // Hace falta porque la app es SPA: casi todo lo que hace el usuario viaja
                    // por fetch y cae aquí, no en la redirección. El interceptor de fetch lo
                    // lee para llevar al login con el motivo real; sin él, "tu clave cambió"
                    // llegaba como "tu sesión expiró", que es falso.
                    return response()->json(['message' => $mensaje, 'motivo' => $motivo], 401);
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
