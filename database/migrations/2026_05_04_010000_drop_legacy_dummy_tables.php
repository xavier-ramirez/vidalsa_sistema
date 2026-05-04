<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Limpia tablas dummy heredadas de migraciones de prueba antiguas:
     *   - test_table   (creada por 2026_01_07_000000_create_test_table.php)
     *   - ignored      (creada por 2026_02_16_002517_adjust_frentes_and_movilizaciones_for_simple_subdivisions.php)
     *
     * Los archivos originales de migracion se conservan en disco para no romper
     * migrate:status / migrate:rollback (Laravel los necesita referenciados desde
     * la tabla `migrations`).
     */
    public function up(): void
    {
        Schema::dropIfExists('test_table');
        Schema::dropIfExists('ignored');
    }

    /**
     * No re-creamos las tablas dummy en down(): eran basura sin uso real,
     * y recrearlas vacias no aporta valor ni reversibilidad util.
     */
    public function down(): void
    {
        // intencionalmente vacio
    }
};
