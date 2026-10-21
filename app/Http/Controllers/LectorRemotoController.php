<?php

namespace App\Http\Controllers;

use App\Models\ProductoInventario;
use App\Services\LectorRemoto;
use Illuminate\Http\Request;

/**
 * El teléfono como lector de la PC (ver App\Services\LectorRemoto).
 *   · enviar / estado — los llama el TELÉFONO, con su sesión (routes/web.php).
 *   · pendientes      — lo llama la PC SIN sesión, con la llave del canal (routes/api.php).
 */
class LectorRemotoController extends Controller
{
    /** Código leído con la cámara en modo «En la PC». */
    public function enviar(Request $request)
    {
        $codigo = trim((string) $request->input('codigo', ''));
        if ($codigo === '') {
            return response()->json(['found' => false, 'message' => 'Código vacío.'], 422);
        }

        $producto = ProductoInventario::datosDeEscaneo($codigo);
        if (!$producto) {
            return response()->json(['found' => false, 'message' => ProductoInventario::avisoCodigoInexistente($codigo)]);
        }

        $idUsuario = (int) $request->user()->ID_USUARIO;
        LectorRemoto::publicar($idUsuario, $producto);

        return response()->json(['found' => true, 'producto' => $producto, 'hay_pc' => LectorRemoto::hayPc($idUsuario)]);
    }

    /** La cámara del teléfono se abrió en modo «En la PC» (o se cambió a él con ella abierta), o se cerró. */
    public function estado(Request $request)
    {
        $idUsuario = (int) $request->user()->ID_USUARIO;
        LectorRemoto::marcarActivo($idUsuario, $request->boolean('activo'));

        return response()->json(['ok' => true]);
    }

    /**
     * La PC pregunta qué escaneó el teléfono. La llave viaja en la cabecera X-Lector-Canal
     * (no en la URL: no queda en los registros del servidor). Una llave vencida o inválida
     * responde 403 y la PC deja de preguntar hasta la próxima visita a la pantalla.
     */
    public function pendientes(Request $request)
    {
        $u = LectorRemoto::usuarioDelCanal((string) $request->header('X-Lector-Canal', ''));
        if (!$u) {
            return response()->json(['message' => 'Canal vencido.'], 403);
        }

        $desde = $request->query('desde');
        $desde = is_numeric($desde) ? (int) $desde : null;

        return response()->json(LectorRemoto::pendientes((int) $u->ID_USUARIO, $desde))
            ->header('Cache-Control', 'no-store');
    }
}
