<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firmante de SEGURIDAD de la Nota de Entrega (formato horizontal).
 *
 * Hasta ahora ese bloque salía en blanco a propósito: se asumió que lo firmaba "el vigilante
 * de turno", que cambia en cada nota. En el formato que usa el cliente (FORMATO DE SALIDA.xlsm)
 * NO es así — de 90 notas revisadas, las 90 llevan la MISMA persona en ese bloque. Es un cargo
 * fijo del patio, igual que el almacenista, así que se configura una vez por almacén.
 *
 * RECIBIDO se queda sin columnas y sigue firmándose a mano: ese sí cambia con cada entrega
 * (es quien recibe en el frente destino).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotente, igual que 2026_08_31_170000_add_formato_nota_to_almacenes.
        if (Schema::hasColumn('almacenes', 'SEGURIDAD_NOM')) {
            return;
        }

        Schema::table('almacenes', function (Blueprint $t) {
            // Mismos largos que los SOPORTE_*, que ocupan el mismo bloque en el PDF.
            $t->string('SEGURIDAD_NOM', 120)->nullable()->after('SOPORTE_2_CED');
            $t->string('SEGURIDAD_CAR', 120)->nullable()->after('SEGURIDAD_NOM');
            $t->string('SEGURIDAD_CED', 20)->nullable()->after('SEGURIDAD_CAR');
        });
    }

    public function down(): void
    {
        Schema::table('almacenes', function (Blueprint $t) {
            $t->dropColumn(['SEGURIDAD_NOM', 'SEGURIDAD_CAR', 'SEGURIDAD_CED']);
        });
    }
};
