<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indice para "movilizaciones de ESTE equipo" (modal de detalles en /admin/equipos).
 *
 * La tabla no tenia por donde entrar buscando por ID_EQUIPO. El unico indice que lo
 * contiene es `mov_client_uuid_equipo_unique (client_uuid, ID_EQUIPO)`, y ahi ID_EQUIPO
 * va SEGUNDO: MySQL solo puede usar un indice compuesto desde su primera columna, asi
 * que un `WHERE ID_EQUIPO = ?` no lo aprovechaba y caia en escaneo completo.
 *
 * Medido antes de este cambio, con 1.265 filas:
 *   EXPLAIN ... WHERE ID_EQUIPO = 37 ORDER BY FECHA_DESPACHO DESC
 *   -> type: ALL | key: NULL | rows: 1265 | Extra: Using where; Using filesort
 *
 * Con 1.265 filas cuesta poco, pero el costo crece con CADA movilizacion que se
 * registre, para siempre, y el modal lo dispara una vez por equipo abierto.
 *
 * Va COMPUESTO (ID_EQUIPO, FECHA_DESPACHO) y no solo (ID_EQUIPO) a proposito: la
 * consulta ordena por FECHA_DESPACHO DESC, y con la fecha dentro del mismo indice
 * MySQL lee las filas ya ordenadas (recorriendolo al reves) y se ahorra el filesort.
 *
 * Idempotente: si el indice ya existe no hace nada, para poder correrla dos veces sin
 * romper (y para BDs donde alguien lo haya creado a mano).
 *
 * SUPERADA por 2026_08_20_150000_reindex_movilizacion_historial_por_created_at, que
 * retira este indice y lo rehace sobre (ID_EQUIPO, created_at). Resulto que
 * FECHA_DESPACHO esta vacia en el 62% de las filas —solo se rellena cuando el
 * movimiento genera acta— asi que ni servia para ordenar ni para mostrar la fecha.
 * Este archivo se conserva porque ya corrio en BDs reales y su nombre esta en la
 * tabla `migrations`: borrarlo romperia migrate:status y migrate:rollback.
 */
return new class extends Migration
{
    private const TABLA  = 'movilizacion_historial';
    private const INDICE = 'idx_mov_hist_equipo_fecha';

    /** ¿Existe ya el indice? information_schema es lo unico portable entre MySQL/MariaDB. */
    private function existeIndice(): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', self::TABLA)
            ->where('index_name', self::INDICE)
            ->exists();
    }

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLA) || $this->existeIndice()) {
            return;
        }

        Schema::table(self::TABLA, function (Blueprint $table) {
            $table->index(['ID_EQUIPO', 'FECHA_DESPACHO'], self::INDICE);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLA) || ! $this->existeIndice()) {
            return;
        }

        Schema::table(self::TABLA, function (Blueprint $table) {
            $table->dropIndex(self::INDICE);
        });
    }
};
