<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * KITS del almacén: una RECETA guardada ("KIT 250H HOWO" = 1 filtro de aceite + 1 de
     * combustible + 2 cuñetes de aceite) para cargar una salida de un golpe, multiplicada por
     * la cantidad de kits. El kit NO tiene stock: el stock vive en cada producto y la salida
     * lo descuenta producto por producto, como siempre (ver App\Services\KitAlmacenService).
     *
     *   almacen_kits          → el kit: nombre y descripción.
     *   almacen_kit_items     → sus materiales y cuánto lleva de cada uno POR KIT.
     *   almacen_kit_modelos   → los modelos de equipo a los que sirve. Mismo par que la
     *                           compatibilidad de un producto: una ficha del catálogo
     *                           (ID_ESPEC, como modelo_filtro) o un tipo de auxiliar
     *                           (TIPO/MARCA/MODELO, como auxiliar_filtro). Un kit sin modelos
     *                           es de uso general.
     */
    public function up(): void
    {
        if (! Schema::hasTable('almacen_kits')) {
            Schema::create('almacen_kits', function (Blueprint $table) {
                $table->id('ID_KIT');
                $table->string('NOMBRE', 120)->unique('uk_almacen_kit_nombre');
                $table->string('DESCRIPCION', 255)->nullable();
                $table->unsignedBigInteger('CREADO_POR')->nullable();
                $table->foreign('CREADO_POR')->references('ID_USUARIO')->on('usuarios')->nullOnDelete();
                $table->timestamps();
                // La huella offline de los catálogos lee MAX(updated_at) (OfflineVersion).
                $table->index('updated_at', 'idx_almacen_kit_upd');
            });
        }

        if (! Schema::hasTable('almacen_kit_items')) {
            Schema::create('almacen_kit_items', function (Blueprint $table) {
                $table->id('ID_KIT_ITEM');
                $table->unsignedBigInteger('ID_KIT');
                $table->foreign('ID_KIT')->references('ID_KIT')->on('almacen_kits')->cascadeOnDelete();
                $table->unsignedBigInteger('ID_PRODUCTO');
                $table->foreign('ID_PRODUCTO')->references('ID_PRODUCTO')->on('productos_inventario')->cascadeOnDelete();
                $table->decimal('CANTIDAD', 12, 3)->comment('Cuanto lleva de este producto CADA kit');
                $table->unsignedSmallInteger('ORDEN')->default(0);
                $table->timestamps();
                $table->unique(['ID_KIT', 'ID_PRODUCTO'], 'uk_almacen_kit_item');
                // "¿En qué kits está este producto?"
                $table->index('ID_PRODUCTO', 'idx_almacen_kit_item_prod');
            });
        }

        if (! Schema::hasTable('almacen_kit_modelos')) {
            Schema::create('almacen_kit_modelos', function (Blueprint $table) {
                $table->id('ID_KIT_MODELO');
                $table->unsignedBigInteger('ID_KIT');
                $table->foreign('ID_KIT')->references('ID_KIT')->on('almacen_kits')->cascadeOnDelete();
                // Ficha del catálogo (caracteristicas_modelo); NULL cuando es un auxiliar.
                $table->unsignedBigInteger('ID_ESPEC')->nullable();
                $table->foreign('ID_ESPEC')->references('ID_ESPEC')->on('caracteristicas_modelo')->cascadeOnDelete();
                // Tipo de auxiliar (equipos_auxiliares, mismos largos); NULL cuando es una ficha del catálogo.
                $table->string('AUX_TIPO', 30)->nullable();
                $table->string('AUX_MARCA', 80)->nullable();
                $table->string('AUX_MODELO', 80)->nullable();
                $table->timestamps();
                $table->unique(['ID_KIT', 'ID_ESPEC'], 'uk_almacen_kit_espec');
                $table->index(['AUX_TIPO', 'AUX_MARCA', 'AUX_MODELO'], 'idx_almacen_kit_aux');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('almacen_kit_modelos');
        Schema::dropIfExists('almacen_kit_items');
        Schema::dropIfExists('almacen_kits');
    }
};
