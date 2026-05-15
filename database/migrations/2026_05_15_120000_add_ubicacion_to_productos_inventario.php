<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `UBICACION` a `productos_inventario` (texto libre, nullable).
 *
 * Concepto: ubicación física del producto en bodega (ej. "Estante A3", "Pasillo 2 lote izquierdo").
 * Es PRODUCTO-level (no por almacén) — igual que el pedido del usuario, edita-vía modal de producto.
 * Se muestra como tooltip al pasar el mouse sobre la fila en /admin/almacen, igual que
 * `DETALLE_UBICACION_ACTUAL` en /admin/equipos.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('productos_inventario', function (Blueprint $t) {
            $t->string('UBICACION', 150)->nullable()->after('CATEGORIA');
        });
    }

    public function down(): void
    {
        Schema::table('productos_inventario', function (Blueprint $t) {
            $t->dropColumn('UBICACION');
        });
    }
};
