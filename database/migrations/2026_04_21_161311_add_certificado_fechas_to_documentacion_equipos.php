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
        Schema::table('documentacion', function (Blueprint $table) {
            // ADICIONAL_FECHA_SUBIDA y ADICIONAL_SUBIDO_POR las crea (y dropea) la
            // migracion 2026_02_12_add_upload_tracking_to_documentacion; aqui solo
            // se anade FECHA_ADICIONAL (fecha del certificado del documento adicional).
            if (!Schema::hasColumn('documentacion', 'FECHA_ADICIONAL')) {
                $table->date('FECHA_ADICIONAL')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documentacion', function (Blueprint $table) {
            $table->dropColumn('FECHA_ADICIONAL');
        });
    }
};
