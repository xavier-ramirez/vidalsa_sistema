<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESTINO de cada renglón de la Nota de Entrega HORIZONTAL (equipo, frente o sitio al que va
 * ese producto). Lo escribe quien despacha en el modal de salida cuando el almacén emite la
 * nota acostada, y lo imprime la columna DESTINO de esa hoja. Vacío = la columna cae al
 * criterio de antes (el frente, solo si la nota reparte a varios).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            if (!Schema::hasColumn('movimientos_inventario', 'DESTINO_ITEM')) {
                $table->string('DESTINO_ITEM', 150)->nullable()->after('NUMERO_PARTE');
            }
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            if (Schema::hasColumn('movimientos_inventario', 'DESTINO_ITEM')) {
                $table->dropColumn('DESTINO_ITEM');
            }
        });
    }
};
