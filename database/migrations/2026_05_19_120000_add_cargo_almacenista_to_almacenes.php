<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `CARGO_ALMACENISTA` a `almacenes` (texto libre, nullable).
 *
 * Concepto: cargo / titulo del almacenista responsable de este almacen
 * (ej. "COORD. DE MATERIALES", "JEFE DE ALMACEN", "ALMACENISTA"). Aparece
 * como "CARGO:" debajo del NOMBRE en la Nota de Entrega de Materiales
 * (VID-FO-GEN-019), seccion "ENTREGADO POR".
 *
 * Antes era un literal hardcodeado ("COORD. DE MATERIALES") en el template
 * del PDF. Lo movemos a BD para que varie por almacen / por proyecto.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('almacenes', function (Blueprint $t) {
            $t->string('CARGO_ALMACENISTA', 200)->nullable()->after('ALMACENISTA');
        });
    }

    public function down(): void
    {
        Schema::table('almacenes', function (Blueprint $t) {
            $t->dropColumn('CARGO_ALMACENISTA');
        });
    }
};
