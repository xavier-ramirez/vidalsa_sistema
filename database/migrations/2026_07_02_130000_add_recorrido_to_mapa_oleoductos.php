<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recorrido dibujado a mano del oleoducto (array de [lat, lng]). Cuando existe, la
 * línea sigue ese trazo con curvas (tubería) en vez de unir los puntos en recta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mapa_oleoductos') || Schema::hasColumn('mapa_oleoductos', 'recorrido')) return; // idempotente
        Schema::table('mapa_oleoductos', function (Blueprint $table) {
            $table->json('recorrido')->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('mapa_oleoductos', function (Blueprint $table) {
            $table->dropColumn('recorrido');
        });
    }
};
