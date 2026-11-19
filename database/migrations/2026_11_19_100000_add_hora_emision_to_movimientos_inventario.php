<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HORA_EMISION: la hora impresa en la Nota de Entrega hecha SIN internet (formato «03:15 PM»).
 * Ese papel se firma al momento; la salida sube después, y la Nota que arma el servidor ponía
 * la hora de la subida (created_at): las dos hojas no coincidían. NULL = se hizo con internet
 * y vale created_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            if (!Schema::hasColumn('movimientos_inventario', 'HORA_EMISION')) {
                $table->string('HORA_EMISION', 8)->nullable()->after('NUMERO_NOTA');
            }
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            if (Schema::hasColumn('movimientos_inventario', 'HORA_EMISION')) {
                $table->dropColumn('HORA_EMISION');
            }
        });
    }
};
