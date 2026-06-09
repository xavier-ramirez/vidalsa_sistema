<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columnas de "gestión" para el documento ADICIONAL (en la UI = "Certificado Asociado").
     * Espejan el patrón de poliza/rotc/racda para que el panel "Alertas de Documentos" pueda
     * iniciar gestión de un certificado vencido igual que los otros documentos.
     */
    public function up(): void
    {
        Schema::table('documentacion', function (Blueprint $table) {
            if (!Schema::hasColumn('documentacion', 'adicional_gestion_frente_id')) {
                $table->unsignedBigInteger('adicional_gestion_frente_id')->nullable()->after('FECHA_ADICIONAL');
            }
            if (!Schema::hasColumn('documentacion', 'adicional_gestion_fecha')) {
                $table->timestamp('adicional_gestion_fecha')->nullable()->after('adicional_gestion_frente_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('documentacion', function (Blueprint $table) {
            if (Schema::hasColumn('documentacion', 'adicional_gestion_fecha')) {
                $table->dropColumn('adicional_gestion_fecha');
            }
            if (Schema::hasColumn('documentacion', 'adicional_gestion_frente_id')) {
                $table->dropColumn('adicional_gestion_frente_id');
            }
        });
    }
};
