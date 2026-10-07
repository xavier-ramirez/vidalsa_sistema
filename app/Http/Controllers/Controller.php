<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * 403 con el aviso de la clave 'almacen.movimiento' que le falta al usuario, o null si la
     * tiene. La exige todo lo que mueve stock (movimientos-lote, la recepción con despacho, la
     * devolución): se valida en el método —y no por middleware `can:`— para decirle al usuario
     * CUÁL clave le falta. Misma forma (success/forbidden/message) que el handler global de
     * AuthorizationException; las pantallas la muestran como toast.
     */
    protected function errorSinPermisoMovimiento(\Illuminate\Http\Request $request): ?\Illuminate\Http\JsonResponse
    {
        if ($request->user()?->can('almacen.movimiento')) {
            return null;
        }
        return response()->json([
            'success'   => false,
            'forbidden' => true,
            'message'   => 'No tienes la clave de permiso «almacen.movimiento», necesaria para registrar movimientos de inventario. Solicítala a un administrador.',
        ], 403);
    }
}
