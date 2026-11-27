<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consumibles: el frente con el que se cargó el consumo, guardado la primera vez que alguien lo
 * cambia en la Lista (ConsumiblesController::updateFrente). La Lista muestra el frente actual y,
 * debajo, «Cargado en: …» — así se sabe que se movió (de PATIO MATURIN a Veladero, por ejemplo).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('consumibles', 'ID_FRENTE_ORIGINAL')) {
            Schema::table('consumibles', function (Blueprint $table) {
                $table->unsignedBigInteger('ID_FRENTE_ORIGINAL')->nullable()->after('ID_FRENTE');
                $table->foreign('ID_FRENTE_ORIGINAL')->references('ID_FRENTE')->on('frentes_trabajo')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('consumibles', 'ID_FRENTE_ORIGINAL')) {
            Schema::table('consumibles', function (Blueprint $table) {
                $table->dropForeign(['ID_FRENTE_ORIGINAL']);
                $table->dropColumn('ID_FRENTE_ORIGINAL');
            });
        }
    }
};
