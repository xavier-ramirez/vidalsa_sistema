<?php

use Illuminate\Database\Migrations\Migration;

/**
 * NO-OP intencional — placeholder.
 *
 * Antes creaba una tabla de prueba `test_table` (id + string `test` + timestamps)
 * sin uso real en la app. Esa tabla la limpia `2026_05_04_010000_drop_legacy_dummy_tables.php`
 * en BDs ya migradas; aquí se convirtió a no-op puro para que las BDs nuevas
 * (migrate:fresh) NO la creen siquiera.
 *
 * No se elimina el archivo del disco porque BDs ya migradas tienen este nombre
 * registrado en la tabla `migrations` — borrarlo rompería `migrate:status` y
 * `migrate:rollback`. Idempotente, efecto neto cero.
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
