<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Equivalencias (cross-reference) de un producto: los DEMÁS números de parte
     * que son EL MISMO repuesto vendido por distintas marcas. Un filtro sigue
     * siendo UN solo producto (productos_inventario); aquí cuelgan sus alternos
     * para poder buscar por cualquiera y verlos en la ficha — sin crear un
     * producto por cada número de parte. Patrón "interchange" (ver ACES/PIES).
     */
    public function up(): void
    {
        Schema::create('producto_equivalencias', function (Blueprint $table) {
            $table->id('ID_EQUIVALENCIA');

            $table->unsignedBigInteger('ID_PRODUCTO')
                  ->comment('Producto (filtro) al que pertenece esta equivalencia');
            $table->foreign('ID_PRODUCTO')
                  ->references('ID_PRODUCTO')->on('productos_inventario')->cascadeOnDelete();

            $table->string('NUMERO_PARTE', 100)
                  ->comment('Número de parte equivalente / cross-reference');

            $table->boolean('ES_PRINCIPAL')->default(false)
                  ->comment('true = número de parte principal del filtro');

            $table->timestamps();

            // Un mismo número de parte no se repite dentro del mismo producto.
            $table->unique(['ID_PRODUCTO', 'NUMERO_PARTE'], 'uk_prod_numparte');
            // Búsqueda por cualquier equivalencia (el buscador matchea por aquí).
            $table->index('NUMERO_PARTE', 'idx_equiv_numparte');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_equivalencias');
    }
};
