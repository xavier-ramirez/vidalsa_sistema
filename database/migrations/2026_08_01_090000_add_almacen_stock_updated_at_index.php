<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índice para la sincronización INCREMENTAL del modo offline.
 *
 * El delta pregunta "¿qué filas cambiaron desde X?" con `WHERE updated_at >= ?`.
 * Sin índice eso es un full scan en CADA sondeo de CADA cliente.
 *
 * `equipos.updated_at` y `productos_inventario.updated_at` ya se indexaron por este
 * mismo motivo en 2026_07_12_120000_add_indexes_for_performance_hotspots (entonces
 * para el MAX() de /offline/version). `almacen_stock` se quedó fuera y es la tabla
 * con más filas de las tres, así que aquí se completa.
 *
 * Mismo patrón tolerante que aquella migración: si el índice ya existe, se salta.
 */
return new class extends Migration
{
    /** Pares [tabla, columna] — un índice simple por entrada. */
    private const INDEXES = [
        ['almacen_stock', 'updated_at'],
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
