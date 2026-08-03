<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para las huellas del snapshot offline (\App\Support\OfflineVersion).
 *
 * Esas huellas hacen MAX(updated_at) sobre estas dos tablas para detectar
 * EDICIONES (el MAX(ID) solo ve altas). Sin índice, cada recálculo es un escaneo
 * completo: 1.198 y 4.025 filas hoy, y `movimientos_inventario` es un kardex que
 * solo crece.
 *
 * Hermana de 2026_08_01_090000 (mismo motivo, para almacen_stock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movilizacion_historial', function (Blueprint $table) {
            $table->index('updated_at', 'movilizacion_historial_updated_at_index');
        });

        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->index('updated_at', 'movimientos_inventario_updated_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('movilizacion_historial', function (Blueprint $table) {
            $table->dropIndex('movilizacion_historial_updated_at_index');
        });

        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->dropIndex('movimientos_inventario_updated_at_index');
        });
    }
};
