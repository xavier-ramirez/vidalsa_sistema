<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FUERA_PAPELERA_AT: «Eliminar permanentemente» de un producto CON historial (kardex, Notas de
 * Entrega ya emitidas). Borrarlo de verdad se llevaría ese historial (las FK son ON DELETE
 * CASCADE), así que se queda borrado (deleted_at) y además sale de la papelera para siempre.
 * NULL = sigue en la papelera (o no está borrado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos_inventario', function (Blueprint $table) {
            if (!Schema::hasColumn('productos_inventario', 'FUERA_PAPELERA_AT')) {
                $table->timestamp('FUERA_PAPELERA_AT')->nullable()->after('deleted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('productos_inventario', function (Blueprint $table) {
            if (Schema::hasColumn('productos_inventario', 'FUERA_PAPELERA_AT')) {
                $table->dropColumn('FUERA_PAPELERA_AT');
            }
        });
    }
};
