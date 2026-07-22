<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ubicaciones SUELTAS del mapa: puntos con nombre que NO pertenecen a ningún proyecto/frente.
 * Se cuelgan de un único oleoducto reservado marcado con suelto = true (id_frente null), así
 * reutilizan tal cual el guardado, el dibujo de la vela, la leyenda y la exportación, en vez
 * de duplicar toda esa cadena con una tabla aparte. Ese grupo nunca dibuja línea (no tiene
 * recorrido) y en el mapa la etiqueta muestra SOLO el nombre del punto, sin nombre de proyecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mapa_oleoductos') || Schema::hasColumn('mapa_oleoductos', 'suelto')) return; // idempotente
        Schema::table('mapa_oleoductos', function (Blueprint $table) {
            $table->boolean('suelto')->default(false)->after('id_frente');
            $table->index('suelto');
        });
    }

    public function down(): void
    {
        // Con la misma guarda que up(): revertir dos veces (o sobre una base donde la columna
        // nunca se creó) no debe reventar.
        if (!Schema::hasTable('mapa_oleoductos') || !Schema::hasColumn('mapa_oleoductos', 'suelto')) return;
        Schema::table('mapa_oleoductos', function (Blueprint $table) {
            $table->dropIndex(['suelto']);
            $table->dropColumn('suelto');
        });
    }
};
