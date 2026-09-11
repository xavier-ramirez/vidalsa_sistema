<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `FORMATO_NOTA` a `movimientos_inventario`: CON QUÉ plantilla se emitió esta Nota
 * de Entrega el día que se ejecutó la operación.
 *
 * Hasta ahora el PDF del historial se armaba con el formato ACTUAL del almacén
 * (`almacenes.FORMATO_NOTA`). Eso es correcto mientras nadie lo cambie, pero el día que un
 * almacén pasa de VERTICAL a HORIZONTAL —cosa que se hace desde "Editar almacén"— TODAS
 * sus notas viejas se reimprimían en la hoja nueva, distinta a la que se firmó en físico.
 * Una nota ya emitida es un documento cerrado: se reimprime como salió.
 *
 * NULL = notas anteriores a esta columna. Para esas se sigue usando el formato actual del
 * almacén (único dato disponible), que es exactamente el comportamiento que ya tenían.
 *
 * Solo se llena en los movimientos que llevan NUMERO_NOTA (SALIDA y TRASPASO_SALIDA); en
 * entradas, ajustes y recepciones queda NULL porque esos no emiten Nota de Entrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotente, igual que 2026_08_31_170000_add_formato_nota_to_almacenes.
        if (Schema::hasColumn('movimientos_inventario', 'FORMATO_NOTA')) {
            return;
        }

        Schema::table('movimientos_inventario', function (Blueprint $t) {
            $t->string('FORMATO_NOTA', 12)->nullable()->after('NUMERO_NOTA');
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $t) {
            $t->dropColumn('FORMATO_NOTA');
        });
    }
};
