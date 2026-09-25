<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los indices que 2026_01_24_060000_add_indexes_to_optimization_tables_v2 nunca creo.
 *
 * Aquella migracion lo metia todo en un try/catch que se tragaba el error, y reventaba al
 * indexar `equipos.TIPO_EQUIPO` (esa columna no existe). Todo lo que venia despues se quedo
 * sin hacer y la migracion figura como ejecutada. Comprobado con SHOW INDEX.
 *
 * Aqui solo los que usa una consulta de verdad:
 *   · movilizacion_historial.ID_FRENTE_ORIGEN / ID_FRENTE_DESTINO: el filtro por frente de
 *     /admin/movilizaciones y el snapshot offline (WHERE DESTINO = ? OR ORIGEN = ?). Sin
 *     ellos EXPLAIN daba type=ALL: la tabla entera, y crece con cada movimiento.
 *   · equipos.MARCA: el filtro por marca y los DISTINCT ... ORDER BY MARCA de los combos.
 *   · equipos.ID_ANCLAJE: la relacion equiposAnclados y los whereIn de anclar/desanclar.
 * Se quedan fuera MODELO (ya lo encabeza idx_equipos_modelo_anio), CATEGORIA_FLOTA (tres
 * valores: el optimizador no lo usaria) y FECHA_DESPACHO (ya nadie ordena por ella; ver
 * 2026_08_20_150000).
 *
 * SIN try/catch: si algo falla, que falle el despliegue y se vea. Idempotente en los dos
 * sentidos, y mira la COLUMNA INICIAL de cualquier indice (no el nombre), para no duplicar
 * uno que ya exista con otro nombre.
 */
return new class extends Migration
{
    private const INDICES = [
        ['movilizacion_historial', 'ID_FRENTE_ORIGEN',  'idx_mov_hist_frente_origen'],
        ['movilizacion_historial', 'ID_FRENTE_DESTINO', 'idx_mov_hist_frente_destino'],
        ['equipos',                'MARCA',             'idx_equipos_marca'],
        ['equipos',                'ID_ANCLAJE',        'idx_equipos_id_anclaje'],
    ];

    /** ¿Algun indice de $tabla EMPIEZA por $columna? (sea cual sea su nombre) */
    private function encabezada(string $tabla, string $columna): bool
    {
        return collect(Schema::getIndexes($tabla))
            ->contains(fn ($i) => strcasecmp($i['columns'][0] ?? '', $columna) === 0);
    }

    public function up(): void
    {
        foreach (self::INDICES as [$tabla, $columna, $nombre]) {
            if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, $columna)) continue;
            if ($this->encabezada($tabla, $columna)) continue;

            Schema::table($tabla, fn (Blueprint $t) => $t->index($columna, $nombre));
        }
    }

    public function down(): void
    {
        // Solo los que creo esta migracion (por su nombre).
        foreach (self::INDICES as [$tabla, , $nombre]) {
            if (Schema::hasTable($tabla) && Schema::hasIndex($tabla, $nombre)) {
                Schema::table($tabla, fn (Blueprint $t) => $t->dropIndex($nombre));
            }
        }
    }
};
