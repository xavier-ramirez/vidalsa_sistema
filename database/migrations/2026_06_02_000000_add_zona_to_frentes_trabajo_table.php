<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ZONA: texto libre con la zona/ciudad que se imprime en el renglón
     * "Lugar, fecha" del Acta de Traslado (estilo "MATURÍN 26/03/2026").
     * Se llena a mano desde el formulario de Frentes; nullable porque los
     * frentes existentes no la tienen y el acta cae a NOMBRE_FRENTE si está vacía.
     */
    public function up(): void
    {
        Schema::table('frentes_trabajo', function (Blueprint $table) {
            if (!Schema::hasColumn('frentes_trabajo', 'ZONA')) {
                $table->string('ZONA', 100)->nullable()->after('UBICACION');
            }
        });
    }

    public function down(): void
    {
        Schema::table('frentes_trabajo', function (Blueprint $table) {
            if (Schema::hasColumn('frentes_trabajo', 'ZONA')) {
                $table->dropColumn('ZONA');
            }
        });
    }
};
