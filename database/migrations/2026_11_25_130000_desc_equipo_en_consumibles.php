<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consumibles: la descripción del vehículo o equipo tal como viene en el Excel de gasoil
 * («EXCAVADORA LOVOL», «CAMIONETA SINOTRUK GRIS»). La lista la muestra cuando el consumo no
 * quedó asociado a ningún equipo, para saber de qué se trata sin abrir el Excel.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('consumibles', 'DESC_EQUIPO')) {
            Schema::table('consumibles', function (Blueprint $table) {
                $table->string('DESC_EQUIPO', 150)->nullable()->after('IDENTIFICADOR');
            });
        }

        // Los ya importados: su IDENTIFICADOR es «DESCRIPCIÓN | PLACA | …» (claveAliasEquipo).
        DB::table('consumibles')->whereNotNull('ID_IMPORTACION')->whereNull('DESC_EQUIPO')
            ->where('IDENTIFICADOR', 'like', '% | %')
            ->update(['DESC_EQUIPO' => DB::raw("LEFT(SUBSTRING_INDEX(IDENTIFICADOR, ' | ', 1), 150)")]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('consumibles', 'DESC_EQUIPO')) {
            Schema::table('consumibles', function (Blueprint $table) {
                $table->dropColumn('DESC_EQUIPO');
            });
        }
    }
};
