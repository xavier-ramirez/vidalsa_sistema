<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `FORMATO_NOTA` a `almacenes`: QUÉ plantilla de Nota de Entrega emite este almacén
 * cuando se registra una SALIDA.
 *
 *   VERTICAL   → el formato de siempre (oficial VID-FO-GEN-019), hoja A4 de pie.
 *                Es el único que existía hasta ahora.
 *   HORIZONTAL → el segundo formato, hoja A4 acostada.
 *
 * NOT NULL con default VERTICAL: cualquier almacén ya existente sigue emitiendo EXACTAMENTE
 * el mismo PDF que antes de esta columna. Cambiar de formato es una decisión explícita por
 * almacén desde "Gestionar almacenes" → Editar almacén.
 *
 * Es una columna de la propia fila del almacén (no una tabla aparte) justamente para que
 * leerla no cueste NADA: el PDF ya carga el almacén del movimiento (`$mov->almacen`), así
 * que el formato viaja en esa misma fila — cero consultas extra, cero joins, cero caché
 * que invalidar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('almacenes', function (Blueprint $t) {
            $t->string('FORMATO_NOTA', 12)->default('VERTICAL')->after('CARGO_ALMACENISTA');
        });
    }

    public function down(): void
    {
        Schema::table('almacenes', function (Blueprint $t) {
            $t->dropColumn('FORMATO_NOTA');
        });
    }
};
