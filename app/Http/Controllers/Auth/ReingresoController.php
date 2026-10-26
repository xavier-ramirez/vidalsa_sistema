<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\BloqueoIp;
use App\Support\PaseReingreso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Volver a tener sesión sin pasar por el inicio de sesión, para quien entró SIN conexión con
 * su clave (ver App\Support\PaseReingreso y public/js/offline/reingreso.js).
 */
class ReingresoController extends Controller
{
    /** POST /sesion/pase (con sesión): un pase para este equipo; `anterior` se borra. */
    public function emitir(Request $request)
    {
        $request->validate(['anterior' => ['nullable', 'string', 'max:64']]);

        return response()
            ->json(['pase' => PaseReingreso::emitir($request->user(), $request->input('anterior'))])
            ->header('Cache-Control', 'no-store');
    }

    /** POST /sesion/reanudar (sin sesión): canjea el pase por una sesión y entrega otro. */
    public function reanudar(Request $request)
    {
        $request->validate(['pase' => ['required', 'string', 'max:64']]);

        if (BloqueoIp::motivoBloqueo($request->ip(), BloqueoIp::equipo($request))) {
            return response()->json(['ok' => false], 422);
        }
        $usuario = PaseReingreso::canjear((string) $request->input('pase'));
        if (!$usuario) {
            return response()->json(['ok' => false], 422);
        }

        Auth::login($usuario);
        LoginController::abrirSesion($request, $usuario);

        return response()
            ->json(['ok' => true, 'pase' => PaseReingreso::emitir($usuario), 'csrf' => csrf_token()])
            ->header('Cache-Control', 'no-store');
    }
}
