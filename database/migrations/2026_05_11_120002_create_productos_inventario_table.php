<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo global de productos de inventario (compartido por todos los
     * almacenes). El stock por almacén vive en `almacen_stock`.
     *
     * Columnas clave del inventario actual: CODIGO, PRODUCTO (NOMBRE), UM, CATEGORIA.
     */
    public function up(): void
    {
        Schema::create('productos_inventario', function (Blueprint $table) {
            $table->id('ID_PRODUCTO');

            $table->string('CODIGO', 50)->unique()
                  ->comment('Código único del producto (columna CODIGO del inventario)');

            $table->string('NOMBRE', 200)
                  ->comment('Descripción del producto (columna PRODUCTO del inventario)');

            $table->string('UM', 20)->default('UND')
                  ->comment('Unidad de medida (columna UM): UND, KG, LTS, MTS, CAJA, etc.');

            $table->string('CATEGORIA', 100)->nullable()
                  ->comment('Categoría del producto (columna CATEGORIA)');

            $table->enum('ESTATUS', ['ACTIVO', 'INACTIVO'])->default('ACTIVO');

            $table->text('NOTAS')->nullable();

            $table->unsignedBigInteger('CREADO_POR')->nullable();
            $table->foreign('CREADO_POR')->references('ID_USUARIO')->on('usuarios')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('CATEGORIA', 'idx_prod_categoria');
            $table->index('ESTATUS',   'idx_prod_estatus');
            $table->index('NOMBRE',    'idx_prod_nombre');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos_inventario');
    }
};
