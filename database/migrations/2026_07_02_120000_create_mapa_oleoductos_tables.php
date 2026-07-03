<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo MAPA — Oleoductos (proyectos de tendido).
 * Un OLEODUCTO es un proyecto con VARIOS puntos (coordenadas con nombre) que, unidos
 * en orden, dibujan la línea del oleoducto sobre el mapa satelital.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mapa_oleoductos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('color', 9)->default('#00e5ff'); // color de la línea (hex)
            $table->text('descripcion')->nullable();
            $table->unsignedBigInteger('creado_por')->nullable(); // usuario que lo creó
            $table->timestamps();
        });

        Schema::create('mapa_oleoducto_puntos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('oleoducto_id')->constrained('mapa_oleoductos')->cascadeOnDelete();
            $table->string('nombre')->nullable();
            $table->decimal('latitud', 10, 7);
            $table->decimal('longitud', 10, 7);
            $table->unsignedInteger('orden')->default(0); // orden de unión de la línea
            $table->timestamps();

            $table->index('oleoducto_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mapa_oleoducto_puntos');
        Schema::dropIfExists('mapa_oleoductos');
    }
};
