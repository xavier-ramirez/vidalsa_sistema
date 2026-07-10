<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El historial de movilizaciones mostraba SIEMPRE el nombre ACTUAL del frente
 * (JOIN en vivo con frentes_trabajo vía frenteOrigen/frenteDestino) — si alguien
 * renombraba un frente, TODO el historial pasado reescribía en silencio el nombre
 * que mostraba, como si el equipo siempre hubiera ido/venido del nombre nuevo.
 * Para un registro histórico/auditoría eso es incorrecto: debe reflejar el nombre
 * que tenía el frente EN EL MOMENTO del movimiento.
 *
 * Estas columnas congelan (snapshot) el nombre de origen/destino al crear cada
 * fila. El backfill usa el nombre ACTUAL como mejor aproximación disponible para
 * las filas ya existentes (no hay forma de recuperar el nombre histórico real de
 * movimientos pasados) — de aquí en adelante quedan congeladas y ya no cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movilizacion_historial', function (Blueprint $table) {
            if (!Schema::hasColumn('movilizacion_historial', 'NOMBRE_FRENTE_ORIGEN_SNAPSHOT')) {
                $table->string('NOMBRE_FRENTE_ORIGEN_SNAPSHOT', 150)->nullable()->after('ID_FRENTE_ORIGEN');
            }
            if (!Schema::hasColumn('movilizacion_historial', 'NOMBRE_FRENTE_DESTINO_SNAPSHOT')) {
                $table->string('NOMBRE_FRENTE_DESTINO_SNAPSHOT', 150)->nullable()->after('ID_FRENTE_DESTINO');
            }
        });

        // Backfill: nombre actual de cada frente para las filas ya existentes.
        DB::statement(
            'UPDATE movilizacion_historial mh ' .
            'LEFT JOIN frentes_trabajo fo ON fo.ID_FRENTE = mh.ID_FRENTE_ORIGEN ' .
            'LEFT JOIN frentes_trabajo fd ON fd.ID_FRENTE = mh.ID_FRENTE_DESTINO ' .
            'SET mh.NOMBRE_FRENTE_ORIGEN_SNAPSHOT = fo.NOMBRE_FRENTE, ' .
            '    mh.NOMBRE_FRENTE_DESTINO_SNAPSHOT = fd.NOMBRE_FRENTE ' .
            'WHERE mh.NOMBRE_FRENTE_ORIGEN_SNAPSHOT IS NULL OR mh.NOMBRE_FRENTE_DESTINO_SNAPSHOT IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('movilizacion_historial', function (Blueprint $table) {
            if (Schema::hasColumn('movilizacion_historial', 'NOMBRE_FRENTE_ORIGEN_SNAPSHOT')) {
                $table->dropColumn('NOMBRE_FRENTE_ORIGEN_SNAPSHOT');
            }
            if (Schema::hasColumn('movilizacion_historial', 'NOMBRE_FRENTE_DESTINO_SNAPSHOT')) {
                $table->dropColumn('NOMBRE_FRENTE_DESTINO_SNAPSHOT');
            }
        });
    }
};
