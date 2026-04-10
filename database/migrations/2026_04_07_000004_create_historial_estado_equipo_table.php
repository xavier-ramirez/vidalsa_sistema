<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historial_estado_equipo', function (Blueprint $table): void {
            $table->bigIncrements('ID_HISTORIAL');
            $table->unsignedBigInteger('ID_EQUIPO');
            $table->string('ESTADO_ANTERIOR', 20);
            $table->string('ESTADO_NUEVO', 20);
            $table->unsignedBigInteger('ID_USUARIO')->nullable();
            $table->unsignedBigInteger('ID_FALLA')->nullable();
            $table->string('MOTIVO', 255)->nullable();
            $table->timestamps();

            $table->foreign('ID_EQUIPO')->references('ID_EQUIPO')->on('equipos');
            $table->foreign('ID_USUARIO')->references('ID_USUARIO')->on('usuarios');
            $table->foreign('ID_FALLA')->references('ID_FALLA')->on('registros_fallas')->nullOnDelete();

            $table->index(['ID_EQUIPO', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_estado_equipo');
    }
};
