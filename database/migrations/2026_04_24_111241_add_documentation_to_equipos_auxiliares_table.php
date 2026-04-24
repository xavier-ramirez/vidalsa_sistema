<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipos_auxiliares', function (Blueprint $table) {
            $table->string('LINK_DOC_PROPIEDAD', 500)->nullable()->after('FOTO');
            $table->string('LINK_CERTIFICADO', 500)->nullable()->after('LINK_DOC_PROPIEDAD');
            $table->date('FECHA_VENCIMIENTO_CERT')->nullable()->after('LINK_CERTIFICADO');
        });
    }

    public function down(): void
    {
        Schema::table('equipos_auxiliares', function (Blueprint $table) {
            $table->dropColumn(['LINK_DOC_PROPIEDAD', 'LINK_CERTIFICADO', 'FECHA_VENCIMIENTO_CERT']);
        });
    }
};
