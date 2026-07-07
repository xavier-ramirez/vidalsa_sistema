<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga cada proyecto del mapa (oleoducto) a un FRENTE DE TRABAJO. Los proyectos ya no se
 * crean a mano en el mapa: se elige un frente existente (frentes_trabajo) y sus ubicaciones
 * se cuelgan del oleoducto de ese frente (uno por frente). id_frente nullable para no romper
 * los oleoductos ya creados antes de este cambio.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mapa_oleoductos') || Schema::hasColumn('mapa_oleoductos', 'id_frente')) return; // idempotente
        Schema::table('mapa_oleoductos', function (Blueprint $table) {
            $table->unsignedBigInteger('id_frente')->nullable()->after('id');
            $table->index('id_frente');
        });
    }

    public function down(): void
    {
        Schema::table('mapa_oleoductos', function (Blueprint $table) {
            $table->dropIndex(['id_frente']);
            $table->dropColumn('id_frente');
        });
    }
};
