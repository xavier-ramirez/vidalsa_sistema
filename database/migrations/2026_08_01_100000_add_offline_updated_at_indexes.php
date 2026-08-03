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
 * Hermana de 2026_08_01_090000 (mismo motivo, para almacen_stock) — y con las MISMAS
 * defensas que aquella: se salta la tabla/columna que no exista y el índice que ya esté.
 * No es paranoia: el arranque del contenedor corre `php artisan migrate --force`, así que
 * una migración que reviente por un índice ya creado a mano deja la app sin levantar.
 */
return new class extends Migration
{
    /** Pares [tabla, columna] — un índice simple por entrada. */
    private const INDEXES = [
        ['movilizacion_historial', 'updated_at'],
        ['movimientos_inventario', 'updated_at'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as [$tabla, $columna]) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, $columna)) {
                continue;
            }
            try {
                Schema::table($tabla, function (Blueprint $table) use ($columna) {
                    $table->index($columna);
                });
            } catch (\Throwable $e) { /* ya existe — saltar */ }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as [$tabla, $columna]) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }
            try {
                Schema::table($tabla, function (Blueprint $table) use ($columna) {
                    $table->dropIndex([$columna]);
                });
            } catch (\Throwable $e) { /* no existe — saltar */ }
        }
    }
};
