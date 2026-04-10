<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registros_fallas', function (Blueprint $table): void {
            $table->bigIncrements('ID_FALLA');
            $table->unsignedBigInteger('ID_REPORTE');
            $table->unsignedBigInteger('ID_EQUIPO');
            $table->unsignedBigInteger('ID_USUARIO_REGISTRA');
            $table->timestamp('HORA_REGISTRO')->useCurrent();
            $table->string('TIPO_FALLA', 30);
            $table->string('SISTEMA_AFECTADO', 100)->nullable();
            $table->text('DESCRIPCION_FALLA');
            $table->string('PRIORIDAD', 20)->default('MEDIA');
            $table->string('ESTADO_FALLA', 20)->default('ABIERTA');
            $table->timestamp('FECHA_RESOLUCION')->nullable();
            $table->text('DESCRIPCION_RESOLUCION')->nullable();
            $table->unsignedBigInteger('ID_SOLICITUD')->nullable();
            $table->string('FOTO_EVIDENCIA', 500)->nullable();
            $table->timestamps();

            $table->foreign('ID_REPORTE')->references('ID_REPORTE')->on('reportes_diarios')->cascadeOnDelete();
            $table->foreign('ID_EQUIPO')->references('ID_EQUIPO')->on('equipos');
            $table->foreign('ID_USUARIO_REGISTRA')->references('ID_USUARIO')->on('usuarios');
            $table->foreign('ID_SOLICITUD')->references('ID_SOLICITUD')->on('solicitudes_mantenimiento')->nullOnDelete();

            $table->index('ESTADO_FALLA');
            $table->index(['ID_EQUIPO', 'ESTADO_FALLA']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registros_fallas');
    }
};
