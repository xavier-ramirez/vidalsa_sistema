<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lista de materiales (BOM) de un KIT: qué piezas lo componen y cuántas.
     * Auto-referencia sobre `productos_inventario` (kit → componentes). El kit
     * es un producto con ES_KIT=true; cada componente es un producto normal con
     * su propio stock. Ejemplo: KIT FILTRO DE AIRE = 1x PRIMARIO + 1x SECUNDARIO.
     *
     * REGLA DE ORO (evita doble conteo): el stock se cuenta a nivel de PIEZA
     * (componente), nunca sumando kit + piezas. El kit solo relaciona el juego.
     */
    public function up(): void
    {
        if (Schema::hasTable('producto_kit_componentes')) return; // idempotente

        Schema::create('producto_kit_componentes', function (Blueprint $table) {
            $table->id('ID_KIT_COMPONENTE');

            $table->unsignedBigInteger('ID_PRODUCTO_KIT')
                  ->comment('Producto que ES el kit (juego)');
            $table->foreign('ID_PRODUCTO_KIT')
                  ->references('ID_PRODUCTO')->on('productos_inventario')->cascadeOnDelete();

            $table->unsignedBigInteger('ID_PRODUCTO_COMPONENTE')
                  ->comment('Producto componente (pieza suelta) que integra el kit');
            $table->foreign('ID_PRODUCTO_COMPONENTE')
                  ->references('ID_PRODUCTO')->on('productos_inventario')->cascadeOnDelete();

            $table->unsignedSmallInteger('CANTIDAD')->default(1)
                  ->comment('Unidades de este componente por cada kit');

            $table->string('ROL', 30)->nullable()
                  ->comment('Rol dentro del kit: PRIMARIO, SECUNDARIO, ELEMENTO, etc.');

            $table->unsignedSmallInteger('ORDEN')->default(0)
                  ->comment('Orden de presentación del componente dentro del kit');

            $table->timestamps();

            // Un componente no se repite dentro del mismo kit.
            $table->unique(['ID_PRODUCTO_KIT', 'ID_PRODUCTO_COMPONENTE'], 'uk_kit_componente');
            // Búsqueda inversa: "¿de qué kits forma parte esta pieza?"
            $table->index('ID_PRODUCTO_COMPONENTE', 'idx_kit_componente_prod');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_kit_componentes');
    }
};
