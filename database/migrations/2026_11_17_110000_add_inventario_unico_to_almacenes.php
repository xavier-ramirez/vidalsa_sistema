<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INVENTARIO_UNICO: un almacén de PROYECTO con varios frentes lleva UN solo saldo por producto,
 * como si fuera de un solo proyecto (ver Almacen::separaPorProyecto). Se marca en «Editar
 * almacén». 0 = el comportamiento de siempre: separa el saldo por proyecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('almacenes', function (Blueprint $table) {
            if (!Schema::hasColumn('almacenes', 'INVENTARIO_UNICO')) {
                $table->boolean('INVENTARIO_UNICO')->default(false)->after('TIPO');
            }
        });
    }

    public function down(): void
    {
        Schema::table('almacenes', function (Blueprint $table) {
            if (Schema::hasColumn('almacenes', 'INVENTARIO_UNICO')) {
                $table->dropColumn('INVENTARIO_UNICO');
            }
        });
    }
};
