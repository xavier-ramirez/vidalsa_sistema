<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega DEVOLUCION al enum TIPO del kardex.
 *
 * Una devolución es material que vuelve al almacén desde el proyecto al que se le entregó
 * (sacaron BRAGA TALLA 45 y a los días la regresan porque era la 42). Suma al stock como
 * una entrada, pero NO es una compra: va enlazada a la SALIDA que devuelve
 * (ID_MOVIMIENTO_RELACIONADO) y resta del consumo de esa salida. Ver
 * App\Services\DevolucionService.
 *
 * Con un tipo propio —y no una ENTRADA con una marca— la bitácora la puede filtrar y
 * rotular sin adivinar, y ninguna entrada de proveedor se confunde con ella.
 */
return new class extends Migration
{
    private const TIPOS_ANTES = "'ENTRADA','SALIDA','AJUSTE','TRASPASO_ENTRADA','TRASPASO_SALIDA'";

    public function up(): void
    {
        // El ENUM es de MySQL; las pruebas que corren en sqlite no llegan a este tipo.
        if (DB::getDriverName() !== 'mysql' || !Schema::hasColumn('movimientos_inventario', 'TIPO')) {
            return;
        }

        DB::statement('ALTER TABLE movimientos_inventario MODIFY TIPO ENUM(' . self::TIPOS_ANTES . ",'DEVOLUCION') NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql' || !Schema::hasColumn('movimientos_inventario', 'TIPO')) {
            return;
        }

        // Quitar el valor con filas que lo usan las dejaría con TIPO vacío y el stock sin
        // explicación en el kardex. Se aborta: primero hay que deshacer esas devoluciones.
        $n = DB::table('movimientos_inventario')->where('TIPO', 'DEVOLUCION')->count();
        if ($n > 0) {
            throw new RuntimeException("Hay {$n} devoluciones en el kardex: deshazlas antes de revertir esta migración.");
        }

        DB::statement('ALTER TABLE movimientos_inventario MODIFY TIPO ENUM(' . self::TIPOS_ANTES . ') NOT NULL');
    }
};
