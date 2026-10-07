<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            Schema::table('equipos', function (Blueprint $table) {
                // MySQL check for indexes (robust check)
                $indexes = collect(DB::select("SHOW INDEXES FROM equipos"))->pluck('Key_name')->toArray();
    
                 if (!in_array('equipos_id_frente_actual_index', $indexes)) $table->index('ID_FRENTE_ACTUAL');
                 if (!in_array('equipos_estado_operativo_index', $indexes)) $table->index('ESTADO_OPERATIVO');
                 if (!in_array('equipos_tipo_equipo_index', $indexes)) $table->index('TIPO_EQUIPO');
                 if (!in_array('equipos_marca_index', $indexes)) $table->index('MARCA');
                 if (!in_array('equipos_modelo_index', $indexes)) $table->index('MODELO');
                 if (!in_array('equipos_categoria_flota_index', $indexes)) $table->index('CATEGORIA_FLOTA');
            });
    
            Schema::table('movilizacion_historial', function (Blueprint $table) {
                $indexes = collect(DB::select("SHOW INDEXES FROM movilizacion_historial"))->pluck('Key_name')->toArray();
    
                if (!in_array('movilizacion_historial_id_equipo_index', $indexes)) $table->index('ID_EQUIPO');
                if (!in_array('movilizacion_historial_id_frente_origen_index', $indexes)) $table->index('ID_FRENTE_ORIGEN');
                if (!in_array('movilizacion_historial_id_frente_destino_index', $indexes)) $table->index('ID_FRENTE_DESTINO');
                if (!in_array('movilizacion_historial_fecha_despacho_index', $indexes)) $table->index('FECHA_DESPACHO');
                if (!in_array('movilizacion_historial_estado_mvo_index', $indexes)) $table->index('ESTADO_MVO');
            });
        } catch (\Throwable $e) {
            // Ignore index errors to prevent blocking deployment
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Simétrico a up(): allí cada índice se crea solo si faltaba (y otras migraciones
        // pueden haberlo borrado después), así que aquí se borra solo si EXISTE. Sin la
        // guarda, migrate:reset abortaba con "Can't DROP INDEX ... check that it exists".
        $quitar = [
            'equipos' => ['ID_FRENTE_ACTUAL', 'ESTADO_OPERATIVO', 'TIPO_EQUIPO', 'MARCA', 'MODELO', 'CATEGORIA_FLOTA'],
            'movilizacion_historial' => ['ID_EQUIPO', 'ID_FRENTE_ORIGEN', 'ID_FRENTE_DESTINO', 'FECHA_DESPACHO', 'ESTADO_MVO'],
        ];
        foreach ($quitar as $tabla => $columnas) {
            $existentes = collect(DB::select("SHOW INDEXES FROM {$tabla}"))->pluck('Key_name')->toArray();
            Schema::table($tabla, function (Blueprint $table) use ($tabla, $columnas, $existentes) {
                foreach ($columnas as $col) {
                    if (in_array($tabla . '_' . strtolower($col) . '_index', $existentes)) {
                        $table->dropIndex([$col]);
                    }
                }
            });
        }
    }
};
