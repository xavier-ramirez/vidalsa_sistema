<?php

namespace App\Support;

/**
 * De-duplica una acción para que ocurra UNA sola vez por request y por clave.
 *
 * Lo usan las dos clases de versionado de caché del proyecto (CacheVersion y
 * OfflineVersion). El motivo es el mismo en ambas: una operación masiva llama a
 * "marcar como obsoleto" una vez POR FILA — un bulk de 200 equipos, una carga
 * masiva por Excel, un borrado en lote— y cada llamada escribe en el caché. Como
 * la versión solo necesita CAMBIAR, no contar, con una vez basta: medido en
 * CacheVersion, 200 filas pasaron de 1200 consultas y ~2,1 s a 4 consultas.
 *
 * El registro se limpia en app()->terminating() para que el siguiente request (o
 * el siguiente trabajo de un worker que reutilice el proceso) vuelva a empezar.
 * En CLI y en tests, donde no hay ciclo de request, hay que llamar a
 * olvidarMarcasDelRequest() a mano para simular ese límite.
 */
trait DeDuplicaPorRequest
{
    /** Claves ya marcadas en este request. */
    private static array $marcadasEnEsteRequest = [];

    /**
     * ¿Es la primera vez que se marca esta clave en el request?
     *
     * Devuelve true la primera vez (y hay que seguir adelante con la acción) y
     * false las siguientes (hay que salir sin hacer nada).
     */
    private static function marcarUnaVez(string $clave): bool
    {
        if (isset(self::$marcadasEnEsteRequest[$clave])) {
            return false;
        }
        self::$marcadasEnEsteRequest[$clave] = true;

        // El registro vive lo que vive el proceso; en web eso es un request. Se limpia
        // al terminar para no bloquear la marca de un contexto posterior (worker,
        // comando artisan largo) que reutilice el mismo proceso.
        if (function_exists('app')) {
            app()->terminating(fn () => self::olvidarMarcasDelRequest());
        }

        return true;
    }

    /** Reabre la ventana: la siguiente llamada volverá a actuar. */
    public static function olvidarMarcasDelRequest(): void
    {
        self::$marcadasEnEsteRequest = [];
    }
}
