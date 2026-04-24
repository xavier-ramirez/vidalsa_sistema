<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Drop de solicitudes_mantenimiento y solicitud_materiales_items: codigo
     * muerto. No hay controladores, vistas ni rutas que las usen. Los modelos
     * Eloquent y sus relaciones en Equipo/FrenteTrabajo se eliminan tambien
     * en este commit.
     *
     * DROP TABLE en MySQL elimina los triggers asociados automaticamente
     * (tr_equipo_inoperativo_por_falla, tr_habilitar_equipo_final,
     * TR_ACTUALIZAR_ESTADO_LOGISTICO_VIDALSA).
     */
    public function up(): void
    {
        // Defensivo: quitar triggers explicitos antes de drop por si el motor
        // no los arrastra (MyISAM antiguo, configuracion atipica).
        try { DB::unprepared('DROP TRIGGER IF EXISTS `tr_equipo_inoperativo_por_falla`'); } catch (\Throwable $e) {}
        try { DB::unprepared('DROP TRIGGER IF EXISTS `tr_habilitar_equipo_final`'); } catch (\Throwable $e) {}
        try { DB::unprepared('DROP TRIGGER IF EXISTS `TR_ACTUALIZAR_ESTADO_LOGISTICO_VIDALSA`'); } catch (\Throwable $e) {}

        // Primero la hija (tiene FK), despues la padre.
        Schema::dropIfExists('solicitud_materiales_items');
        Schema::dropIfExists('solicitudes_mantenimiento');

        // Limpiar registros huerfanos en la tabla de migraciones (las originales
        // fueron eliminadas del disco en el mismo commit).
        try {
            DB::table('migrations')
                ->whereIn('migration', [
                    '2026_01_07_055013_create_solicitudes_mantenimiento_table',
                    '2026_01_07_055014_create_solicitud_materiales_items_table',
                ])
                ->delete();
        } catch (\Throwable $e) { /* silencioso */ }
    }

    /**
     * Down: no recreamos la estructura ni los triggers. Si en el futuro se
     * reintroduce el modulo de solicitudes, se escribe una migracion nueva.
     */
    public function down(): void
    {
        // Intencionalmente vacio.
    }
};
