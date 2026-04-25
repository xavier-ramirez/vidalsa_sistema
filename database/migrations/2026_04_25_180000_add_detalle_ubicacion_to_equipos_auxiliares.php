<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega DETALLE_UBICACION_ACTUAL a equipos_auxiliares para soportar el
 * mismo patron de "asignaciones especiales" que ya tiene la tabla equipos:
 * cuando un auxiliar esta en un frente con TIPO_FRENTE='ESPECIAL' (patio,
 * almacen, taller, etc.), la columna almacena la sub-ubicacion concreta
 * (ej. "ZONA A", "BAHIA 3", "ALMACEN PRINCIPAL").
 *
 * Reversible: down() elimina la columna sin perder datos en otras columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipos_auxiliares', function (Blueprint $table) {
            $table->string('DETALLE_UBICACION_ACTUAL', 100)->nullable()->after('ID_FRENTE_ACTUAL');
        });
    }

    public function down(): void
    {
        Schema::table('equipos_auxiliares', function (Blueprint $table) {
            $table->dropColumn('DETALLE_UBICACION_ACTUAL');
        });
    }
};
