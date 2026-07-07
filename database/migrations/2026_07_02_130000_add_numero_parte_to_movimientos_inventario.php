<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Número de parte ESPECÍFICO entregado en una salida de filtro. Un filtro es UN
     * producto con varias equivalencias (marcas); al entregarlo se elige cuál se está
     * moviendo. Se guarda aquí para que salga en la Nota de Entrega (columna "N° COLADA
     * / SERIAL"), en la bitácora y —en traspasos— en el almacén destino. Nullable: el
     * resto de productos (y salidas sin elección) lo dejan vacío.
     */
    public function up(): void
    {
        if (Schema::hasColumn('movimientos_inventario', 'NUMERO_PARTE')) return; // idempotente
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->string('NUMERO_PARTE', 100)->nullable()->after('REFERENCIA')
                  ->comment('Nº de parte/equivalencia específica entregada (filtros)');
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->dropColumn('NUMERO_PARTE');
        });
    }
};
