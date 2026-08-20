<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El modal "Movilizaciones" del detalle de equipo ordenaba por la columna equivocada.
 *
 * `FECHA_DESPACHO` solo se rellena cuando el movimiento genera acta: en
 * MovilizacionController y EquipoAuxiliarController la fila nace como
 * `$generarPdf ? 'DESPACHO' : 'ACT.'`, y las de tipo ACT. se guardan sin fecha. Hoy son
 * 783 de 1.265 filas (62%), mas las 3 de RECEPCION_DIRECTA: 786 sin fecha en total.
 *
 * Con `ORDER BY FECHA_DESPACHO DESC` MySQL manda los NULL AL FINAL, asi que el modal
 * pintaba primero los despachos con acta y despues, al fondo, todo lo demas — con la
 * movilizacion MAS RECIENTE apareciendo la ultima. Ademas se leia "Sin fecha" en el 62%
 * de las filas.
 *
 * La fecha buena es `created_at`: no tiene ni un nulo en las 1.265 filas y es la que ya
 * usa el listado de /admin/movilizaciones (partials/table_rows.blade.php).
 *
 * Por eso el indice se rehace sobre (ID_EQUIPO, created_at). El anterior
 * `idx_mov_hist_equipo_fecha` (ID_EQUIPO, FECHA_DESPACHO) se elimina: ninguna consulta
 * queda ordenando por esa columna, y dejarlo solo costaria escrituras.
 *
 * No sirve el `movilizacion_historial_created_at_index` que ya existia: es solo
 * (created_at), asi que no puede resolver el filtro por ID_EQUIPO.
 *
 * Idempotente en las dos direcciones.
 */
return new class extends Migration
{
    private const TABLA  = 'movilizacion_historial';
    private const NUEVO  = 'idx_mov_hist_equipo_creado';
    private const VIEJO  = 'idx_mov_hist_equipo_fecha';

    private function existe(string $indice): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', self::TABLA)
            ->where('index_name', $indice)
            ->exists();
    }

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLA)) {
            return;
        }

        Schema::table(self::TABLA, function (Blueprint $table) {
            if (! $this->existe(self::NUEVO)) {
                $table->index(['ID_EQUIPO', 'created_at'], self::NUEVO);
            }
            if ($this->existe(self::VIEJO)) {
                $table->dropIndex(self::VIEJO);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLA)) {
            return;
        }

        Schema::table(self::TABLA, function (Blueprint $table) {
            if (! $this->existe(self::VIEJO)) {
                $table->index(['ID_EQUIPO', 'FECHA_DESPACHO'], self::VIEJO);
            }
            if ($this->existe(self::NUEVO)) {
                $table->dropIndex(self::NUEVO);
            }
        });
    }
};
