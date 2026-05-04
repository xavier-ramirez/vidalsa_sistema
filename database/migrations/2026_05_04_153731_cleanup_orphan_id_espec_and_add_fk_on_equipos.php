<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Limpia ID_ESPEC huérfanos en `equipos` y añade FK con ON DELETE SET NULL.
     *
     * Causa raíz: la migración 2026_01_07_055011_create_equipos_table.php dejó la FK
     * comentada (líneas 33-35). Sin FK + sin lógica histórica de desvinculación al
     * borrar catálogos, muchos equipos quedaron con ID_ESPEC apuntando a registros
     * inexistentes en caracteristicas_modelo. Eso impide que reciban foto cuando se
     * crea un catálogo nuevo con el mismo MODELO+ANIO.
     */
    public function up(): void
    {
        // 1) BACKFILL: poner NULL en equipos cuyo ID_ESPEC apunta a catálogos borrados.
        $cleaned = DB::table('equipos')
            ->whereNotNull('ID_ESPEC')
            ->whereNotIn('ID_ESPEC', function ($q) {
                $q->select('ID_ESPEC')->from('caracteristicas_modelo');
            })
            ->update(['ID_ESPEC' => null]);

        if ($cleaned > 0) {
            \Log::info("Migration cleanup: {$cleaned} equipos huérfanos con ID_ESPEC inexistente reseteados a NULL.");
        }

        // 2) FK con ON DELETE SET NULL para que esto NUNCA vuelva a pasar.
        // Si la FK ya existe (por algún parche manual previo), Laravel falla; envolvemos en try.
        try {
            Schema::table('equipos', function (Blueprint $table) {
                $table->foreign('ID_ESPEC', 'fk_equipos_id_espec')
                      ->references('ID_ESPEC')
                      ->on('caracteristicas_modelo')
                      ->onDelete('set null')
                      ->onUpdate('cascade');
            });
        } catch (\Throwable $e) {
            \Log::warning('FK fk_equipos_id_espec no se pudo crear (¿ya existe?): ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        try {
            Schema::table('equipos', function (Blueprint $table) {
                $table->dropForeign('fk_equipos_id_espec');
            });
        } catch (\Throwable $e) {
            // ignore si no existe
        }
        // No revertimos el backfill: poner ID_ESPEC viejos no es seguro ni reproducible.
    }
};
