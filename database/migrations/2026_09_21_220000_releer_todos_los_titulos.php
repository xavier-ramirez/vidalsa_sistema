<?php

use App\Models\VerificacionDocumento;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Arreglo del 21-09-2026 (lo pidio el cliente): se vuelven a LEER TODOS los TITULOS DE PROPIEDAD
 * con el lector de hoy, los leidos esta noche y los de dias anteriores: fecha de emision del
 * formato nuevo del INTT, letras mal leidas que ya no convierten el titulo en "de otro vehiculo".
 * Mismo criterio que la de los ROTC (2026_09_21_210000): se retiran sus lecturas y vuelven a la
 * cola, que se lee al pulsar "Revisar ahora" (o esa noche, en su horario). Salvo las que ya
 * REVISO UNA PERSONA (APLICADO_POR): su decision se respeta.
 *
 * Una sola vez: es una migracion. down vacio: las lecturas se rehacen solas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('verificacion_documento_registro')) return;

        DB::table('verificacion_documento_registro')
            ->where('TIPO', VerificacionDocumento::PROPIEDAD)
            ->whereNull('APLICADO_POR')
            ->delete();
    }

    public function down(): void
    {
    }
};
