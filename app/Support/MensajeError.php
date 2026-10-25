<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Qué texto de una excepción puede ver el usuario.
 *
 * Los servicios avisan de los errores de negocio lanzando InvalidArgumentException o
 * RuntimeException con un mensaje pensado para la pantalla ("Stock insuficiente…"), y los
 * controladores lo devuelven tal cual. Pero el mismo catch atrapa también lo inesperado: un
 * QueryException trae el SQL entero con tablas y columnas, un ModelNotFoundException dice
 * "No query results for model [App\Models\…]", Guzzle trae la URL de Drive. Eso no se le
 * enseña a nadie: va al log y el usuario lee un mensaje nuestro.
 *
 * "Nuestro" = lanzado desde app/ (no desde vendor/) como RuntimeException o LogicException,
 * o un abort()/ValidationException, que siempre llevan texto escrito a propósito. Los errores
 * de la base (PDOException, y QueryException que la extiende) nunca, aunque salten en app/.
 */
final class MensajeError
{
    /** Para seguir a un prefijo propio: "Error al subir archivo: vuelve a intentarlo…". */
    public const REINTENTAR = 'vuelve a intentarlo; si sigue fallando, avisa a soporte.';
    public const GENERICO = 'No se pudo completar la operación: ' . self::REINTENTAR;

    /** El mensaje de $e si es nuestro; si no, lo deja en el log (con su traza) y devuelve $generico. */
    public static function para(\Throwable $e, string $generico = self::GENERICO): string
    {
        if (!self::esNuestro($e)) {
            report($e);
        }
        return self::texto($e, $generico);
    }

    /** Igual que para() pero sin tocar el log: para el catch que ya tiene su propio Log::error. */
    public static function texto(\Throwable $e, string $generico = self::GENERICO): string
    {
        return self::esNuestro($e) ? $e->getMessage() : $generico;
    }

    public static function esNuestro(\Throwable $e): bool
    {
        if ($e instanceof HttpExceptionInterface || $e instanceof ValidationException) {
            return $e->getMessage() !== '';
        }
        if ($e instanceof \PDOException) {
            return false;
        }
        return ($e instanceof \RuntimeException || $e instanceof \LogicException)
            && $e->getMessage() !== ''
            && str_starts_with(realpath($e->getFile()) ?: $e->getFile(), realpath(app_path()) . DIRECTORY_SEPARATOR);
    }
}
