<?php

use Illuminate\Database\Migrations\Migration;

/**
 * NO-OP intencional — placeholder.
 *
 * Comparte nombre con `2026_02_15_235517_adjust_frentes_and_movilizaciones_for_simple_subdivisions.php`
 * (que es la migración REAL: agrega SUBDIVISIONES a frentes_trabajo, DETALLE_UBICACION
 * a movilizacion_historial y DETALLE_UBICACION_ACTUAL a equipos). Cuando se quiso
 * re-correr el ajuste se creó este segundo archivo con timestamp posterior, pero
 * el cambio real ya estaba aplicado — quedó como dummy.
 *
 * Antes creaba/borraba una tabla `ignored` (limpiada por
 * `2026_05_04_010000_drop_legacy_dummy_tables.php`). Convertido a no-op puro
 * para evitar el `CREATE TABLE` redundante en BDs nuevas.
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
