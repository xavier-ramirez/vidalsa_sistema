<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Compatibilidad "fitment": qué filtros usa cada MODELO de equipo. Relación
     * muchos-a-muchos entre caracteristicas_modelo (el modelo) y
     * productos_inventario (el filtro). Se define UNA vez por modelo y todos los
     * equipos de ese modelo la heredan (cada equipo apunta a su modelo vía
     * ID_ESPEC). Permite ver, desde el filtro, los equipos que lo usan — y al
     * revés — y sugerir filtros + cantidades en la nota de entrega.
     */
    public function up(): void
    {
        if (Schema::hasTable('modelo_filtro')) return; // idempotente: ya existe, no re-crear
        Schema::create('modelo_filtro', function (Blueprint $table) {
            $table->id('ID_MODELO_FILTRO');

            $table->unsignedBigInteger('ID_ESPEC')
                  ->comment('Modelo de equipo (caracteristicas_modelo)');
            $table->foreign('ID_ESPEC')
                  ->references('ID_ESPEC')->on('caracteristicas_modelo')->cascadeOnDelete();

            $table->unsignedBigInteger('ID_PRODUCTO')
                  ->comment('Filtro (productos_inventario) que usa ese modelo');
            $table->foreign('ID_PRODUCTO')
                  ->references('ID_PRODUCTO')->on('productos_inventario')->cascadeOnDelete();

            $table->unsignedSmallInteger('CANTIDAD')->default(1)
                  ->comment('Cantidad de este filtro por servicio del modelo');

            $table->timestamps();

            // Un filtro no se vincula dos veces al mismo modelo.
            $table->unique(['ID_ESPEC', 'ID_PRODUCTO'], 'uk_modelo_filtro');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modelo_filtro');
    }
};
