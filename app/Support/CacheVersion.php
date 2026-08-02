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
    use DeDuplicaPorRequest;

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
        if (! self::marcarUnaVez($key)) return;

        if (!Cache::add($key, 1)) {
            Cache::increment($key);
        }
    }

    /**
     * Alias histórico de olvidarMarcasDelRequest() (trait DeDuplicaPorRequest). Se
     * conserva porque ya hay llamadas a este nombre en scripts y pruebas.
     */
    public static function olvidarBumpsDelRequest(): void
    {
        self::olvidarMarcasDelRequest();
    }

    public static function current(string $key): int
    {
        return (int) Cache::get($key, 0);
    }
}
