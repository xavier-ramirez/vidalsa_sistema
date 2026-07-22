<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca un producto como KIT (juego). Un kit NO guarda stock propio: su
     * contenido son OTROS productos (las piezas sueltas, ej. filtro de aire
     * primario + secundario) definidos en `producto_kit_componentes`. El stock
     * y el consumo viven a nivel de PIEZA (Opción A / BOM "fantasma"); al
     * despachar un kit, la app descuenta sus componentes.
     */
    public function up(): void
    {
        if (Schema::hasColumn('productos_inventario', 'ES_KIT')) return; // idempotente

        Schema::table('productos_inventario', function (Blueprint $table) {
            $table->boolean('ES_KIT')->default(false)->after('CATEGORIA')
                  ->comment('true = producto tipo KIT (juego); su contenido está en producto_kit_componentes');
            $table->index('ES_KIT', 'idx_prod_es_kit');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('productos_inventario', 'ES_KIT')) return;

        Schema::table('productos_inventario', function (Blueprint $table) {
            $table->dropIndex('idx_prod_es_kit');
            $table->dropColumn('ES_KIT');
        });
    }
};
