<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Permite registrar movilizaciones de equipos_auxiliares en la misma tabla
 * movilizacion_historial que ya usan los equipos. Antes la tabla solo tenia
 * ID_EQUIPO (FK a equipos), por lo que las movilizaciones de aux no podian
 * registrarse aqui.
 *
 * Cambios:
 *  - ID_EQUIPO ahora es nullable (un registro pertenece a un EQUIPO o a un AUX)
 *  - Nuevo ID_AUXILIAR nullable (FK a equipos_auxiliares.ID_AUXILIAR)
 *  - Index en ID_AUXILIAR para queries del modulo aux
 *
 * Reglas:
 *  - Una fila DEBE tener ID_EQUIPO o ID_AUXILIAR (validacion en aplicacion)
 *  - Las queries existentes de equipos siguen funcionando porque ID_EQUIPO
 *    se mantiene; solo cambia su nullability
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movilizacion_historial', function (Blueprint $table) {
            // ID_EQUIPO nullable: requerido si la movilizacion es de un equipo,
            // null si es de un auxiliar.
            $table->unsignedBigInteger('ID_EQUIPO')->nullable()->change();

            // Nuevo: referencia al auxiliar movilizado (si aplica).
            $table->unsignedBigInteger('ID_AUXILIAR')->nullable()->after('ID_EQUIPO');
            $table->index('ID_AUXILIAR', 'idx_mov_hist_id_auxiliar');
        });
    }

    public function down(): void
    {
        Schema::table('movilizacion_historial', function (Blueprint $table) {
            $table->dropIndex('idx_mov_hist_id_auxiliar');
            $table->dropColumn('ID_AUXILIAR');
            // Restaurar ID_EQUIPO a NOT NULL solo si no hay filas con NULL
            // (defensivo: si ya hay registros de aux, dejar nullable para no romper)
            $hasNulls = DB::table('movilizacion_historial')->whereNull('ID_EQUIPO')->exists();
            if (!$hasNulls) {
                $table->unsignedBigInteger('ID_EQUIPO')->nullable(false)->change();
            }
        });
    }
};
