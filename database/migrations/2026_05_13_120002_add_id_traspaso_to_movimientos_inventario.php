<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega ID_TRASPASO al kardex para que cada TRASPASO_SALIDA / TRASPASO_ENTRADA
     * sepa a qué pedido pertenece. NULL cubre dos casos válidos:
     *
     *  - Movimientos viejos (anteriores a esta feature) — quedan huérfanos por diseño,
     *    se ven en el kardex como traspasos sueltos pero no tienen pedido padre.
     *  - Movimientos no-traspaso (ENTRADA, SALIDA, AJUSTE).
     */
    public function up(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->unsignedBigInteger('ID_TRASPASO')->nullable()->after('ID_MOVIMIENTO_RELACIONADO')
                  ->comment('FK opcional → traspasos: para movimientos TRASPASO_SALIDA / TRASPASO_ENTRADA creados por el flujo de Pedido de Traspaso.');
            $table->foreign('ID_TRASPASO')->references('ID_TRASPASO')->on('traspasos')->nullOnDelete();
            $table->index('ID_TRASPASO', 'idx_mov_traspaso');
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->dropForeign(['ID_TRASPASO']);
            $table->dropIndex('idx_mov_traspaso');
            $table->dropColumn('ID_TRASPASO');
        });
    }
};
