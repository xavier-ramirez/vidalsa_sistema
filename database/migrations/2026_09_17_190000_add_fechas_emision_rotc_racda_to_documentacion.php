<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Fecha en que se EMITIO el ROTC y el RACDA, que hasta ahora no se guardaba: la ficha solo
     * tenia la de vencimiento (FECHA_ROTC / FECHA_RACDA). Las dos las lee del propio PDF
     * docs:verificar-documentos ("Fecha de Emision" en el ROTC; "CARACAS, 14 DE JULIO DE 2025"
     * en la providencia del RACDA), igual que ya se hacia con el titulo y la poliza.
     */
    public function up(): void
    {
        foreach (['FECHA_EMISION_ROTC' => 'FECHA_ROTC', 'FECHA_EMISION_RACDA' => 'FECHA_RACDA'] as $columna => $despuesDe) {
            if (Schema::hasColumn('documentacion', $columna)) continue;
            Schema::table('documentacion', function (Blueprint $table) use ($columna, $despuesDe) {
                $table->date($columna)->nullable()->after($despuesDe);
            });
        }
    }

    public function down(): void
    {
        foreach (['FECHA_EMISION_ROTC', 'FECHA_EMISION_RACDA'] as $columna) {
            if (Schema::hasColumn('documentacion', $columna)) {
                Schema::table('documentacion', fn (Blueprint $t) => $t->dropColumn($columna));
            }
        }
    }
};
