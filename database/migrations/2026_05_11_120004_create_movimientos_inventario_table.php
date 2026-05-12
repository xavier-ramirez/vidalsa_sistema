<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kardex: cada entrada / salida / ajuste / traspaso de inventario.
     *
     * Es la fuente de verdad del stock — `almacen_stock.CANTIDAD` es solo el
     * acumulado. CANTIDAD se guarda SIEMPRE positiva; el signo lo da TIPO:
     *   ENTRADA, TRASPASO_ENTRADA          → suman
     *   SALIDA,  TRASPASO_SALIDA           → restan
     *   AJUSTE                              → fija el saldo a un valor objetivo
     *                                         (CANTIDAD = |delta aplicado|, ver
     *                                          CANTIDAD_ANTERIOR/RESULTANTE)
     * Para traspasos se generan 2 filas enlazadas por ID_MOVIMIENTO_RELACIONADO.
     */
    public function up(): void
    {
        Schema::create('movimientos_inventario', function (Blueprint $table) {
            $table->id('ID_MOVIMIENTO');

            $table->unsignedBigInteger('ID_ALMACEN');
            $table->foreign('ID_ALMACEN')->references('ID_ALMACEN')->on('almacenes')->cascadeOnDelete();

            $table->unsignedBigInteger('ID_PRODUCTO');
            $table->foreign('ID_PRODUCTO')->references('ID_PRODUCTO')->on('productos_inventario')->cascadeOnDelete();

            $table->enum('TIPO', [
                'ENTRADA',
                'SALIDA',
                'AJUSTE',
                'TRASPASO_ENTRADA',
                'TRASPASO_SALIDA',
            ]);

            $table->decimal('CANTIDAD', 16, 3)
                  ->comment('Magnitud del movimiento, siempre > 0; el signo lo determina TIPO');

            $table->decimal('CANTIDAD_ANTERIOR', 16, 3)
                  ->comment('Saldo del producto en el almacén ANTES de aplicar el movimiento');

            $table->decimal('CANTIDAD_RESULTANTE', 16, 3)
                  ->comment('Saldo del producto en el almacén DESPUÉS de aplicar el movimiento');

            $table->date('FECHA')->comment('Fecha del movimiento (puede ser distinta a created_at)');

            // Traspasos: almacén contraparte + enlace al movimiento espejo.
            $table->unsignedBigInteger('ID_ALMACEN_CONTRAPARTE')->nullable()
                  ->comment('En traspasos: el otro almacén (origen/destino según TIPO)');
            $table->foreign('ID_ALMACEN_CONTRAPARTE')->references('ID_ALMACEN')->on('almacenes')->nullOnDelete();

            $table->unsignedBigInteger('ID_MOVIMIENTO_RELACIONADO')->nullable()
                  ->comment('En traspasos: ID_MOVIMIENTO del registro espejo en el otro almacén');
            $table->foreign('ID_MOVIMIENTO_RELACIONADO')->references('ID_MOVIMIENTO')->on('movimientos_inventario')->nullOnDelete();

            // Destino/consumo opcional: frente de trabajo al que se asigna la salida.
            $table->unsignedBigInteger('ID_FRENTE')->nullable()
                  ->comment('Frente de trabajo asociado (p.ej. destino de una salida de consumo)');
            $table->foreign('ID_FRENTE')->references('ID_FRENTE')->on('frentes_trabajo')->nullOnDelete();

            $table->unsignedBigInteger('ID_USUARIO')->nullable()
                  ->comment('Usuario que registró el movimiento');
            $table->foreign('ID_USUARIO')->references('ID_USUARIO')->on('usuarios')->nullOnDelete();

            $table->string('REFERENCIA', 100)->nullable()
                  ->comment('Nº de guía / factura / orden — documento de respaldo');
            $table->string('MOTIVO', 200)->nullable()
                  ->comment('Motivo corto (compra, consumo, devolución, conteo físico, etc.)');
            $table->text('NOTAS')->nullable();

            $table->timestamps();

            $table->index('ID_ALMACEN',  'idx_mov_almacen');
            $table->index('ID_PRODUCTO', 'idx_mov_producto');
            $table->index('TIPO',        'idx_mov_tipo');
            $table->index('FECHA',       'idx_mov_fecha');
            $table->index('ID_FRENTE',   'idx_mov_frente');
            $table->index(['ID_ALMACEN', 'ID_PRODUCTO', 'FECHA'], 'idx_mov_alm_prod_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
    }
};
