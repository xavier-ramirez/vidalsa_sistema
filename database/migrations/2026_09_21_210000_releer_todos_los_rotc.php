<?php

use App\Models\VerificacionDocumento;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Arreglo del 21-09-2026 (lo pidio el cliente): se vuelven a LEER TODOS los ROTC con el lector
 * de hoy. Desde el 18-09 la regla que reconoce un ROTC no coincidia nunca (llevaba dos
 * caracteres invisibles) y la tabla de flota ya da numero y fechas aunque Drive no devuelva el
 * certificado: las lecturas guardadas pueden estar mal aunque digan "Coincide".
 *
 * Se retiran sus lecturas: vuelven a la cola y se leen al pulsar "Revisar ahora" (o esa noche,
 * en su horario). Salvo las que ya REVISO UNA PERSONA (APLICADO_POR): su decision se respeta.
 *
 * Una sola vez: es una migracion. down vacio: las lecturas se rehacen solas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('verificacion_documento_registro')) return;

        DB::table('verificacion_documento_registro')
            ->where('TIPO', VerificacionDocumento::ROTC)
            ->whereNull('APLICADO_POR')
            ->delete();
    }

    public function down(): void
    {
    }
};
