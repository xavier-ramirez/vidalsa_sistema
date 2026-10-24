<?php

namespace App\Http\Controllers;

use App\Support\NotasReservadas;
use Illuminate\Http\Request;

/**
 * POST /admin/almacen/notas-reservadas: los números de Nota apartados para ESTE dispositivo,
 * para registrar salidas sin internet (ver App\Support\NotasReservadas). Lo pide la bandeja
 * del navegador con conexión; solo quien puede registrar movimientos.
 */
class NotasReservadasController extends Controller
{
    public function __invoke(Request $request)
    {
        if ($error = $this->errorSinPermisoMovimiento($request)) {
            return $error;
        }
        $datos = $request->validate(['dispositivo' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/']]);

        return response()->json([
            'numeros' => NotasReservadas::paraDispositivo((int) $request->user()->ID_USUARIO, $datos['dispositivo']),
        ]);
    }
}
