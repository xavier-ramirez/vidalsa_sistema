<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reportes_diarios', function (Blueprint $table): void {
            $table->bigIncrements('ID_REPORTE');
            $table->unsignedBigInteger('ID_FRENTE');
            $table->date('FECHA_REPORTE');
            $table->string('ESTADO_REPORTE', 20)->default('ABIERTO');
            $table->unsignedBigInteger('CERRADO_POR')->nullable();
            $table->timestamp('FECHA_CIERRE')->nullable();
            $table->text('OBSERVACIONES')->nullable();
            $table->timestamps();

            $table->unique(['ID_FRENTE', 'FECHA_REPORTE'], 'uk_frente_fecha_reporte');

            $table->foreign('ID_FRENTE')->references('ID_FRENTE')->on('frentes_trabajo');
            $table->foreign('CERRADO_POR')->references('ID_USUARIO')->on('usuarios');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reportes_diarios');
    }
};
