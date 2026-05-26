<?php

use Illuminate\Database\Migrations\Migration;

/**
 * NO-OP intencional — duplicado exacto de
 * `2026_01_13_184425_make_documentation_fields_nullable.php` (mismas 5 columnas
 * de `documentacion` puestas como nullable). Se conserva el archivo en disco
 * para no romper `migrate:status` en BDs ya migradas que tienen este nombre
 * registrado en la tabla `migrations`. Borrarlo causaría:
 *   - `migrate:status` reportaría "Migration not found" para entradas viejas.
 *   - `migrate:rollback` fallaría al intentar resolver la clase.
 *
 * Sólo se vacían `up()` / `down()` — efecto neto cero. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        // intencionalmente vacío — ver cabecera
    }

    public function down(): void
    {
        // intencionalmente vacío — ver cabecera
    }
};
