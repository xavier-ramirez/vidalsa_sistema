<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto de cada COLOR de un modelo del catálogo.
 *
 * Un modelo (caracteristicas_modelo) tiene UNA ficha técnica —motor, aceites, batería— que
 * vale para todas sus unidades, pero las unidades vienen en colores distintos y cada color
 * necesita su foto. Antes la única forma de tener otra foto era copiar la ficha entera (así
 * nacieron 5 fichas iguales de la pick-up SINOTRUK), y la unidad no decía de qué color era.
 *
 * Ahora el color vive en la unidad (equipos.COLOR) y la foto de cada color aquí. La foto
 * que se muestra de un equipo la decide Equipo::fotoParaMostrar().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogo_colores', function (Blueprint $table) {
            $table->id('ID_COLOR');
            $table->unsignedBigInteger('ID_ESPEC');
            $table->foreign('ID_ESPEC')->references('ID_ESPEC')->on('caracteristicas_modelo')->cascadeOnDelete();
            // Normalizado con CatalogoColor::normalizar (mayúsculas, sin espacios de sobra).
            $table->string('COLOR', 50);
            // /storage/google/{id}: mismo formato que caracteristicas_modelo.FOTO_REFERENCIAL.
            $table->string('FOTO', 255)->nullable();
            $table->timestamps();

            $table->unique(['ID_ESPEC', 'COLOR'], 'catalogo_colores_espec_color_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogo_colores');
    }
};
