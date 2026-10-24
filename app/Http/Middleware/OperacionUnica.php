<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Una operación con clave (cabecera X-Clave-Operacion) se aplica UNA sola vez.
 *
 * La bandeja del navegador (public/js/offline/bandeja.js) guarda lo que se hace sin internet
 * y lo manda, al volver la red, a los mismos endpoints de siempre. Si la conexión se corta
 * con la respuesta en camino, el navegador no sabe si se aplicó y la vuelve a mandar: aquí se
 * reconoce la clave y se devuelve la respuesta guardada (cabecera X-Operacion-Repetida) en vez
 * de registrar la salida o la movilización dos veces.
 *
 * Mientras la primera todavía se está aplicando, la segunda recibe 409 con
 * X-Operacion-En-Curso y la bandeja lo reintenta después. Las respuestas 5xx, 401, 419 y 429
 * no se guardan: esas se pueden reintentar con la misma clave.
 *
 * Sin la cabecera, o en GET, no hace nada.
 */
class OperacionUnica
{
    public const CABECERA = 'X-Clave-Operacion';
    public const TABLA = 'operaciones_cliente';
    private const DIAS = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $clave = (string) $request->header(self::CABECERA, '');
        $usuario = $request->user();
        if ($clave === '' || $request->isMethodSafe() || ! $usuario) {
            return $next($request);
        }
        if (! preg_match('/^[A-Za-z0-9-]{8,64}$/', $clave)) {
            return response()->json(['message' => 'Clave de operación inválida.'], 422);
        }

        $donde = ['CLAVE' => $clave, 'ID_USUARIO' => $usuario->ID_USUARIO];
        $hecha = DB::table(self::TABLA)->where($donde)->first();
        if ($hecha) {
            return $this->repetida($hecha);
        }
        if (! DB::table(self::TABLA)->insertOrIgnore($donde + [
            'RUTA' => mb_substr($request->path(), 0, 255), 'created_at' => now(), 'updated_at' => now(),
        ])) {
            return $this->repetida(DB::table(self::TABLA)->where($donde)->first());
        }

        try {
            $respuesta = $next($request);
        } catch (Throwable $e) {
            DB::table(self::TABLA)->where($donde)->delete();
            throw $e;
        }

        $estado = $respuesta->getStatusCode();
        if ($estado >= 500 || in_array($estado, [401, 419, 429], true)) {
            DB::table(self::TABLA)->where($donde)->delete();
        } else {
            DB::table(self::TABLA)->where($donde)->update([
                'ESTADO_HTTP' => $estado, 'RESPUESTA' => (string) $respuesta->getContent(), 'updated_at' => now(),
            ]);
        }

        return $respuesta;
    }

    private function repetida(?object $fila): Response
    {
        if (! $fila || $fila->ESTADO_HTTP === null) {
            return response()->json(['message' => 'La operación todavía se está aplicando.'], 409, ['X-Operacion-En-Curso' => '1']);
        }

        return response($fila->RESPUESTA, (int) $fila->ESTADO_HTTP, [
            'Content-Type' => 'application/json', 'X-Operacion-Repetida' => '1',
        ]);
    }

    /** Las claves viejas ya no se van a repetir: se borran (tarea diaria, routes/console.php). */
    public static function purgar(): int
    {
        return DB::table(self::TABLA)->where('created_at', '<', now()->subDays(self::DIAS))->delete();
    }
}
