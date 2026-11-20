<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
    /**
     * Una clave «en curso» más vieja que esto es de una petición que murió sin terminar (PHP
     * pasó su tiempo máximo —Registrar equipo pone 600 s—, se quedó sin memoria o mataron el
     * proceso): ahí no llega el catch y la fila quedaba sin respuesta, con 409 para siempre y
     * la bandeja detenida detrás de ella hasta la purga de 30 días. Se suelta y se aplica.
     * Sin peligro de aplicarla dos veces: si la operación llegó a guardarse, su clave quedó
     * marcada en la MISMA transacción (marcarAplicada) y ya no está «en curso».
     */
    private const MINUTOS_EN_CURSO = 15;

    /**
     * Lo que recibe quien repite una operación que se guardó pero cuya respuesta se perdió (la
     * petición murió después de confirmar: el PDF, Drive, el tiempo máximo de PHP).
     */
    public const RESPUESTA_APLICADA = '{"success":true,"message":"La operación ya se había aplicado."}';

    /** La clave de la petición en marcha (una por proceso). */
    private static ?array $enMarcha = null;

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
        // Lo guardado sin internet por OTRO usuario (cookie de quien entró después en el mismo
        // equipo) ya no llega aquí: lo para antes UsuarioEsperado con 401, y la bandeja lo
        // guarda hasta que entre su dueño.

        $donde = ['CLAVE' => $clave, 'ID_USUARIO' => $usuario->ID_USUARIO];
        DB::table(self::TABLA)->where($donde)->whereNull('ESTADO_HTTP')
            ->where('created_at', '<', now()->subMinutes(self::MINUTOS_EN_CURSO))->delete();
        $hecha = DB::table(self::TABLA)->where($donde)->first();
        if ($hecha) {
            return $this->repetida($hecha);
        }
        if (! DB::table(self::TABLA)->insertOrIgnore($donde + [
            'RUTA' => mb_substr($request->path(), 0, 255), 'created_at' => now(), 'updated_at' => now(),
        ])) {
            return $this->repetida(DB::table(self::TABLA)->where($donde)->first());
        }

        self::escucharCommit();
        self::$enMarcha = $donde;
        try {
            $respuesta = $next($request);
        } catch (Throwable $e) {
            // Solo si no llegó a guardarse nada: entonces se puede volver a intentar.
            DB::table(self::TABLA)->where($donde)->whereNull('ESTADO_HTTP')->delete();
            throw $e;
        } finally {
            self::$enMarcha = null;
        }

        $estado = $respuesta->getStatusCode();
        if ($estado >= 500 || in_array($estado, [401, 419, 429], true)) {
            // Ídem: si la operación ya se guardó, se queda marcada como aplicada.
            DB::table(self::TABLA)->where($donde)->whereNull('ESTADO_HTTP')->delete();
        } else {
            DB::table(self::TABLA)->where($donde)->update([
                'ESTADO_HTTP' => $estado, 'RESPUESTA' => (string) $respuesta->getContent(), 'updated_at' => now(),
            ]);
        }

        return $respuesta;
    }

    /**
     * La clave queda aplicada DENTRO de la transacción que guarda la operación (justo antes de
     * su commit): o las dos cosas quedan, o ninguna. Antes se marcaba al terminar la petición, y
     * si moría entre medias (el PDF, Drive, el tiempo máximo) a los 15 min se soltaba la clave y
     * la salida se registraba otra vez: stock descontado dos veces y otra Nota.
     */
    private static function escucharCommit(): void
    {
        // Una vez por aplicación (no por proceso: en las pruebas cada una arma la suya).
        if (app()->bound('operacion-unica.escucha')) {
            return;
        }
        app()->instance('operacion-unica.escucha', true);
        Event::listen(TransactionCommitting::class, function (TransactionCommitting $e) {
            if (self::$enMarcha === null || $e->connectionName !== DB::getDefaultConnection()) {
                return;
            }
            self::marcarAplicada(self::$enMarcha);
        });
    }

    /** Justo antes del commit, dentro de la transacción. */
    private static function marcarAplicada(array $donde): void
    {
        DB::table(self::TABLA)->where($donde)->whereNull('ESTADO_HTTP')->update([
            'ESTADO_HTTP' => 200, 'RESPUESTA' => self::RESPUESTA_APLICADA, 'updated_at' => now(),
        ]);
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
