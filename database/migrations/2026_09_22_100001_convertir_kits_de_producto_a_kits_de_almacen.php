<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pasa los "kits" viejos (julio 2026: un PRODUCTO marcado ES_KIT con su lista de piezas en
     * producto_kit_componentes) a los kits del almacén (almacen_kits), y quita la estructura
     * vieja. Ninguna pantalla la usaba; los kits que tenía (filtro de aire primario +
     * secundario) quedan como kits de verdad, con las mismas piezas y cantidades.
     *
     * El producto que ERA el kit se queda como un producto normal: tiene movimientos y, en
     * algún almacén, stock propio (se compró como juego), y eso no se borra ni se toca.
     *
     * Se decide por los DATOS, no por códigos: corre igual en local y en el servidor, y es
     * idempotente (si el kit nuevo ya existe con ese nombre, no lo duplica).
     */
    public function up(): void
    {
        if (Schema::hasTable('producto_kit_componentes') && Schema::hasTable('almacen_kits')) {
            $piezas = DB::table('producto_kit_componentes as c')
                ->join('productos_inventario as k', 'k.ID_PRODUCTO', '=', 'c.ID_PRODUCTO_KIT')
                ->orderBy('c.ID_PRODUCTO_KIT')->orderBy('c.ORDEN')
                ->get(['c.ID_PRODUCTO_KIT', 'k.NOMBRE', 'c.ID_PRODUCTO_COMPONENTE', 'c.CANTIDAD', 'c.ORDEN'])
                ->groupBy('ID_PRODUCTO_KIT');

            foreach ($piezas as $lista) {
                $nombre = mb_substr(trim((string) $lista->first()->NOMBRE), 0, 120);
                if ($nombre === '' || DB::table('almacen_kits')->where('NOMBRE', $nombre)->exists()) {
                    continue;
                }
                $idKit = DB::table('almacen_kits')->insertGetId([
                    'NOMBRE' => $nombre, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($lista->values() as $orden => $p) {
                    DB::table('almacen_kit_items')->insertOrIgnore([
                        'ID_KIT' => $idKit, 'ID_PRODUCTO' => $p->ID_PRODUCTO_COMPONENTE,
                        'CANTIDAD' => max(1, (int) $p->CANTIDAD), 'ORDEN' => $orden,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        Schema::dropIfExists('producto_kit_componentes');

        if (Schema::hasColumn('productos_inventario', 'ES_KIT')) {
            Schema::table('productos_inventario', function (Blueprint $table) {
                if (Schema::hasIndex('productos_inventario', 'idx_prod_es_kit')) {
                    $table->dropIndex('idx_prod_es_kit');
                }
                $table->dropColumn('ES_KIT');
            });
        }
    }

    /**
     * Solo devuelve la ESTRUCTURA vieja (columna y tabla vacías): los kits ya viven en
     * almacen_kits, que es lo que usa la aplicación.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('productos_inventario', 'ES_KIT')) {
            Schema::table('productos_inventario', function (Blueprint $table) {
                $table->boolean('ES_KIT')->default(false)->after('CATEGORIA');
                $table->index('ES_KIT', 'idx_prod_es_kit');
            });
        }
        if (! Schema::hasTable('producto_kit_componentes')) {
            Schema::create('producto_kit_componentes', function (Blueprint $table) {
                $table->id('ID_KIT_COMPONENTE');
                $table->unsignedBigInteger('ID_PRODUCTO_KIT');
                $table->foreign('ID_PRODUCTO_KIT')->references('ID_PRODUCTO')->on('productos_inventario')->cascadeOnDelete();
                $table->unsignedBigInteger('ID_PRODUCTO_COMPONENTE');
                $table->foreign('ID_PRODUCTO_COMPONENTE')->references('ID_PRODUCTO')->on('productos_inventario')->cascadeOnDelete();
                $table->unsignedSmallInteger('CANTIDAD')->default(1);
                $table->string('ROL', 30)->nullable();
                $table->unsignedSmallInteger('ORDEN')->default(0);
                $table->timestamps();
                $table->unique(['ID_PRODUCTO_KIT', 'ID_PRODUCTO_COMPONENTE'], 'uk_kit_componente');
                $table->index('ID_PRODUCTO_COMPONENTE', 'idx_kit_componente_prod');
            });
        }
    }
};
