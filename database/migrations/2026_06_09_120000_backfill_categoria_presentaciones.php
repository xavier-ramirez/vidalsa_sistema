<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill de CATEGORIA para presentaciones (mismo NOMBRE, distinta UM) que quedaron
     * sin categoria. Causa: antes de que Recepcion heredara la categoria del producto
     * original al crear una presentacion al vuelo, esas presentaciones nacian en NULL.
     *
     * Regla: cada producto sin CATEGORIA hereda la de un HERMANO con el mismo NOMBRE
     * (case/trim-insensible) que SI tenga categoria — el producto canonico/original.
     * Solo actualiza si encuentra una categoria hermana; si no hay, lo deja como esta.
     */
    public function up(): void
    {
        $sinCat = DB::table('productos_inventario')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('CATEGORIA')->orWhere('CATEGORIA', '');
            })
            ->get(['ID_PRODUCTO', 'NOMBRE']);

        foreach ($sinCat as $p) {
            $cat = DB::table('productos_inventario')
                ->whereNull('deleted_at')
                ->whereRaw('UPPER(TRIM(NOMBRE)) = UPPER(TRIM(?))', [$p->NOMBRE])
                ->whereNotNull('CATEGORIA')
                ->where('CATEGORIA', '!=', '')
                ->value('CATEGORIA');

            if ($cat) {
                DB::table('productos_inventario')
                    ->where('ID_PRODUCTO', $p->ID_PRODUCTO)
                    ->update(['CATEGORIA' => $cat]);
            }
        }
    }

    public function down(): void
    {
        // No reversible: no se registra cuales estaban en NULL antes del backfill.
    }
};
