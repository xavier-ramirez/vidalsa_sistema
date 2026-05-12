<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivote almacén ⇄ frente de trabajo (proyecto).
     *
     * Modela la regla del negocio: "cada proyecto puede tener su almacén propio
     * y varios proyectos comparten un mismo almacén". Un almacén PROYECTO puede
     * servir a N frentes; un frente puede estar asociado a uno (lo normal) o a
     * varios almacenes. Los almacenes GENERAL no necesitan estar aquí (ven todo).
     */
    public function up(): void
    {
        Schema::create('almacen_frentes', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('ID_ALMACEN');
            $table->foreign('ID_ALMACEN')->references('ID_ALMACEN')->on('almacenes')->cascadeOnDelete();

            $table->unsignedBigInteger('ID_FRENTE');
            $table->foreign('ID_FRENTE')->references('ID_FRENTE')->on('frentes_trabajo')->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['ID_ALMACEN', 'ID_FRENTE'], 'uq_alm_frente');
            $table->index('ID_FRENTE', 'idx_alm_frente_frente');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('almacen_frentes');
    }
};
