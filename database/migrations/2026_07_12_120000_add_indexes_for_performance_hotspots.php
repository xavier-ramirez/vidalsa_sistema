<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para los hotspots detectados en la auditoría de rendimiento:
 * - documentacion.FECHA_ADICIONAL: única fecha del whereHas de alertas del
 *   dashboard SIN índice (poliza/rotc/racda ya lo tienen).
 * - movilizacion_historial.created_at: orderBy desc + filtro de rango del
 *   historial de movilizaciones.
 * - fallas.FECHA_EMISION / ESTADO_REPORTE: orderByDesc + filtros del listado.
 * - equipos_auxiliares.created_at: orderByDesc del listado embebido.
 * - equipos.updated_at / productos_inventario.updated_at: MAX(updated_at) del
 *   fingerprint /offline/version, consultado cada 10 min por cada cliente PWA
 *   (sin índice = full scan por poll).
 */
return new class extends Migration
{
    /** Pares [tabla, columna] — un índice simple por entrada. */
    private const INDEXES = [
        ['documentacion',          'FECHA_ADICIONAL'],
        ['movilizacion_historial', 'created_at'],
        ['fallas',                 'FECHA_EMISION'],
        ['fallas',                 'ESTADO_REPORTE'],
        ['equipos_auxiliares',     'created_at'],
        ['equipos',                'updated_at'],
        ['productos_inventario',   'updated_at'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add indexes safely — skip if already exist (compatible with MySQL, SQLite, PostgreSQL)
        foreach (self::INDEXES as [$tabla, $columna]) {
            try {
                Schema::table($tabla, function (Blueprint $table) use ($columna) {
                    $table->index($columna);
                });
            } catch (\Throwable $e) { /* Index already exists — skip */ }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop indexes safely — skip if they don't exist (compatible with MySQL, SQLite, PostgreSQL)
        foreach (self::INDEXES as [$tabla, $columna]) {
            try {
                Schema::table($tabla, function (Blueprint $table) use ($columna) {
                    $table->dropIndex([$columna]);
                });
            } catch (\Throwable $e) { /* Index doesn't exist — skip */ }
        }
    }
};
