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
    public static function bump(string $key): void
    {
        if (!Cache::add($key, 1)) {
            Cache::increment($key);
        }
    }

    public static function current(string $key): int
    {
        return (int) Cache::get($key, 0);
    }
}
