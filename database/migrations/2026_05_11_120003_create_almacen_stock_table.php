<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existencia (stock) de cada producto en cada almacén.
     *
     * Una fila por (ALMACÉN, PRODUCTO). CANTIDAD es el saldo vigente; se ajusta
     * SIEMPRE a través de `movimientos_inventario` (nunca a mano), dentro de una
     * transacción con SELECT ... FOR UPDATE para evitar carreras.
     */
    public function up(): void
    {
        Schema::create('almacen_stock', function (Blueprint $table) {
            $table->id('ID_STOCK');

            $table->unsignedBigInteger('ID_ALMACEN');
            $table->foreign('ID_ALMACEN')->references('ID_ALMACEN')->on('almacenes')->cascadeOnDelete();

            $table->unsignedBigInteger('ID_PRODUCTO');
            $table->foreign('ID_PRODUCTO')->references('ID_PRODUCTO')->on('productos_inventario')->cascadeOnDelete();

            $table->decimal('CANTIDAD', 16, 3)->default(0)
                  ->comment('Saldo vigente del producto en este almacén');

            $table->decimal('CANTIDAD_MINIMA', 16, 3)->nullable()
                  ->comment('Umbral de reposición (opcional) para alertas de stock bajo');

            $table->decimal('ULTIMA_ENTRADA', 16, 3)->nullable()
                  ->comment('Cantidad del último ingreso registrado (referencia rápida)');
            $table->decimal('ULTIMA_SALIDA', 16, 3)->nullable()
                  ->comment('Cantidad de la última salida registrada (referencia rápida)');
            $table->timestamp('FECHA_ULT_MOVIMIENTO')->nullable();

            $table->timestamps();

            $table->unique(['ID_ALMACEN', 'ID_PRODUCTO'], 'uq_stock_alm_prod');
            $table->index('ID_PRODUCTO', 'idx_stock_prod');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('almacen_stock');
    }
};
