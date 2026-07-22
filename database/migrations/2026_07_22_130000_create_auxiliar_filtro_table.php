<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Compatibilidad "fitment" filtro ↔ AUXILIAR (generador, máquina de soldar,
     * compresor, etc.). Hermana de `modelo_filtro`, pero para los equipos que
     * viven en `equipos_auxiliares` (no en caracteristicas_modelo). Como los
     * auxiliares no tienen catálogo de "modelos", el fitment se guarda por
     * IDENTIDAD del auxiliar: TIPO + MARCA + MODELO (denormalizado). Así un filtro
     * se asocia a "GENERADOR_DIESEL / ACPOW / APW-60" y se ve tanto desde el filtro
     * como en el dashboard, igual que los vehículos.
     */
    public function up(): void
    {
        if (Schema::hasTable('auxiliar_filtro')) return; // idempotente

        Schema::create('auxiliar_filtro', function (Blueprint $table) {
            $table->id('ID_AUX_FILTRO');

            $table->unsignedBigInteger('ID_PRODUCTO')
                  ->comment('Filtro (productos_inventario) que usa ese auxiliar');
            $table->foreign('ID_PRODUCTO')
                  ->references('ID_PRODUCTO')->on('productos_inventario')->cascadeOnDelete();

            $table->string('TIPO', 30)->comment('Tipo de auxiliar: GENERADOR_DIESEL, MAQUINA_DE_SOLDAR, COMPRESOR, etc.');
            $table->string('MARCA', 80)->default('');
            $table->string('MODELO', 80)->default('');

            $table->unsignedSmallInteger('CANTIDAD')->default(1)
                  ->comment('Cantidad de este filtro por servicio del auxiliar');

            $table->timestamps();

            // Un filtro no se vincula dos veces al mismo modelo de auxiliar.
            $table->unique(['ID_PRODUCTO', 'TIPO', 'MARCA', 'MODELO'], 'uk_aux_filtro');
            // "¿qué filtros usa este auxiliar?"
            $table->index(['TIPO', 'MARCA', 'MODELO'], 'idx_aux_filtro_modelo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auxiliar_filtro');
    }
};
