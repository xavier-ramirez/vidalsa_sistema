<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('equipos_auxiliares', function (Blueprint $table) {
            if (!Schema::hasColumn('equipos_auxiliares', 'CONFIRMADO_EN_SITIO')) {
                // Mismo ciclo que equipos: 0 = pendiente de confirmar en el frente
                // destino (al despachar), 1 = confirmado físicamente en sitio.
                $table->boolean('CONFIRMADO_EN_SITIO')->default(0)->after('ID_FRENTE_ACTUAL');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('equipos_auxiliares', function (Blueprint $table) {
            if (Schema::hasColumn('equipos_auxiliares', 'CONFIRMADO_EN_SITIO')) {
                $table->dropColumn('CONFIRMADO_EN_SITIO');
            }
        });
    }
};
