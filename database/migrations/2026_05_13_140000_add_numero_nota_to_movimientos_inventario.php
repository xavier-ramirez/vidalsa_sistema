<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega `NUMERO_NOTA` (string indexado) a `movimientos_inventario`.
     * Solo se llena en movimientos SALIDA registrados en lote: identifica
     * la Nota de Entrega de Materiales (formato NE-YYYY-NNNN, consecutivo
     * global) y se usa para reimprimir o eliminar la nota completa por
     * código desde /admin/almacen/movimientos.
     *
     * En ENTRADA / AJUSTE / TRASPASO_* queda NULL.
     */
    public function up(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->string('NUMERO_NOTA', 30)->nullable()->after('DEPARTAMENTO')
                  ->comment('Nota de Entrega: serial NE-YYYY-NNNN (SALIDA, consecutivo global).');
            $table->index('NUMERO_NOTA', 'idx_mov_numero_nota');
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->dropIndex('idx_mov_numero_nota');
            $table->dropColumn('NUMERO_NOTA');
        });
    }
};
