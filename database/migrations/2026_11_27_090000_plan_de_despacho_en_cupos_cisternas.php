<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cupos de gasoil: cada cisterna empieza como una SOLICITUD (número y fecha del plan de despacho) y,
 * cuando llega, se le pone la fecha real de llegada (FECHA), los litros y la foto. Por eso FECHA y
 * LITROS pasan a ser opcionales. Las que ya estaban cargadas toman su fecha de llegada como plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cupos_cisternas', function (Blueprint $table) {
            if (!Schema::hasColumn('cupos_cisternas', 'NUMERO')) {
                $table->unsignedInteger('NUMERO')->nullable()->after('PATIO');
            }
            if (!Schema::hasColumn('cupos_cisternas', 'FECHA_PLAN')) {
                $table->date('FECHA_PLAN')->nullable()->after('NUMERO');
            }
        });
        Schema::table('cupos_cisternas', function (Blueprint $table) {
            $table->date('FECHA')->nullable()->change();
            $table->decimal('LITROS', 12, 2)->nullable()->change();
        });
        DB::table('cupos_cisternas')->whereNull('FECHA_PLAN')->update(['FECHA_PLAN' => DB::raw('FECHA')]);
    }

    public function down(): void
    {
        // Las solicitudes que no llegaron no caben en las columnas obligatorias de antes.
        DB::table('cupos_cisternas')->whereNull('FECHA')->orWhereNull('LITROS')->delete();
        Schema::table('cupos_cisternas', function (Blueprint $table) {
            $table->date('FECHA')->nullable(false)->change();
            $table->decimal('LITROS', 12, 2)->nullable(false)->change();
            $table->dropColumn(['NUMERO', 'FECHA_PLAN']);
        });
    }
};
