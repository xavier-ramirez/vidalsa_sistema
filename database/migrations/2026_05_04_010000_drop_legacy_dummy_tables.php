<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Limpia tablas dummy heredadas de migraciones de prueba antiguas. Ambos
     * archivos de origen ya estan convertidos a NO-OP (no crean nada en BDs
     * nuevas), pero en BDs migradas ANTES de esa conversion las tablas siguen
     * existiendo hasta que esta migracion las dropee:
     *   - test_table   (la creaba 2026_01_07_000000_create_test_table.php).
     *   - ignored      (la creaba 2026_02_16_002517_adjust_frentes_and_movilizaciones_for_simple_subdivisions.php
     *                  en su version anterior).
     *
     * Los archivos originales de migracion se conservan en disco para no romper
     * migrate:status / migrate:rollback (Laravel los necesita referenciados desde
     * la tabla `migrations`).
     *
     * dropIfExists es seguro: en BDs nuevas (donde 002517 ya es no-op y nunca creo
     * la tabla `ignored`) no falla ni rompe nada.
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
