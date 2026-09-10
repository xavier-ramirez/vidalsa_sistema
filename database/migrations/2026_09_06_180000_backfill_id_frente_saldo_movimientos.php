<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rellena `ID_FRENTE_SALDO` en las filas anteriores a esa columna.
 *
 * Hasta ahora esas filas llegaban con NULL y el recálculo de saldos las leía con
 * `COALESCE(ID_FRENTE_SALDO, ID_FRENTE, 0)`. Funcionaba, pero envolver la columna en una
 * función la vuelve NO indexable: el índice que creó la migración de la columna
 * (mov_inv_alm_prod_frsaldo_idx) NUNCA se usaba — MySQL caía a un index_merge de
 * idx_mov_producto + idx_mov_almacen y filtraba el resto a mano. Comprobado con EXPLAIN:
 * la MISMA consulta sin COALESCE sí lo usa.
 *
 * El valor que se escribe es exactamente lo que devolvía el COALESCE, así que ningún saldo
 * cambia de bolsa:
 *   - ID_FRENTE con valor  → esa es su bolsa (una salida al proyecto 5 salió de la bolsa 5).
 *   - ID_FRENTE NULL       → bolsa común (0). Es el caso del tramo que salía del material
 *                            sin asignar, que se guardaba con frente NULL a propósito, y el
 *                            de las entradas/ajustes que nunca llevan frente.
 *
 * En los almacenes que NO separan por proyecto el recálculo no filtra por bolsa, así que
 * ahí el valor es indiferente; se escribe igual para que la columna no quede a medias.
 *
 * Sin down(): volver a poner NULL no restauraría nada (la información de origen es la misma
 * ID_FRENTE de la que sale este relleno) y dejaría las filas sin explicar su bolsa.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Por lotes: la tabla crece con cada movimiento y un UPDATE único sobre todo el
        // histórico bloquea filas que el almacén podría estar usando en ese momento.
        do {
            $tocadas = DB::table('movimientos_inventario')
                ->whereNull('ID_FRENTE_SALDO')
                ->limit(2000)
                ->update(['ID_FRENTE_SALDO' => DB::raw('COALESCE(ID_FRENTE, 0)')]);
        } while ($tocadas > 0);
    }

    public function down(): void
    {
        // Intencionalmente vacío: ver el comentario de arriba.
    }
};
