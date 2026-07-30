<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Versiones monotónicas para cachés "versionadas": la versión viaja EN la clave
 * (p.ej. "dashboard_user_data_{id}_v{n}"), así un bump() invalida de golpe las
 * entradas de TODOS los usuarios sin conocer sus claves. Fuente única del idioma
 * add-or-increment (Cache::increment sobre una clave inexistente no la crea en
 * todos los drivers — por eso el Cache::add previo).
 *
 * Usos: dashboard /menu (DashboardController::DATA_VER_KEY) y badge de
 * traspasos (Traspaso::booted / View composer en AppServiceProvider).
 */
class CacheVersion
{
    /** Claves ya bumpeadas en este request. Ver bump(). */
    private static array $bumpeadasEnEsteRequest = [];

    /**
     * Sube la versión UNA sola vez por request y por clave.
     *
     * El de-duplicado no es cosmético: una operación masiva llama a bump() una vez POR
     * FILA (bulkDelete registra un audit log y borra cada equipo; la importación crea
     * cientos de equipos; bulk_ubicacion escribe un log por id). Cada bump cuesta 3
     * queries con el driver `database` — incluido un `select ... for update` sobre la
     * MISMA fila de `cache`, y esos bulks corren dentro de una transacción, así que
     * además serializan por ese lock. Medido: 200 equipos = 1200 queries y ~2,1 s solo
     * en bumps. La versión únicamente necesita CAMBIAR, no contar, así que con uno basta.
     *
     * No agrava la ventana de staleness: en esos bulks TODOS los bumps ocurrían ya
     * dentro de la transacción, así que un lector concurrente podía cachear el estado
     * pre-commit igual, con o sin de-duplicado.
     */
    public static function bump(string $key): void
    {
        if (isset(self::$bumpeadasEnEsteRequest[$key])) return;
        self::$bumpeadasEnEsteRequest[$key] = true;

        // El guard vive lo que vive el proceso; en web eso es un request. Se limpia al
        // terminar para no bloquear el bump de un contexto posterior (worker, comando
        // artisan largo) que reutilice el mismo proceso.
        if (function_exists('app')) {
            app()->terminating(fn () => self::olvidarBumpsDelRequest());
        }

        if (!Cache::add($key, 1)) {
            Cache::increment($key);
        }
    }

    /**
     * Reabre la ventana de bump. La llama el terminating() de bump(); en CLI/tests, que
     * no tienen ciclo de request, sirve para marcar a mano el límite entre operaciones.
     */
    public static function olvidarBumpsDelRequest(): void
    {
        self::$bumpeadasEnEsteRequest = [];
    }

    public static function current(string $key): int
    {
        return (int) Cache::get($key, 0);
    }
}
