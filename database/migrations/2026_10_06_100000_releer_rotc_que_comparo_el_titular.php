<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Arreglo del 06-10-2026: la lectura de la noche comparaba el nombre del ROTC (la OPERADORA,
 * siempre la empresa) con el PROPIETARIO de la ficha, y guardaba esa "diferencia". Ya no se
 * compara (VerificarDocumentos::revisarRotc), pero las lecturas guardadas la siguen mostrando en
 * el panel. Se borran: la lectura siguiente las vuelve a hacer, ya sin el titular. Solo las de
 * la noche sin revisar (una revisada a mano no guarda diferencias).
 *
 * Una sola vez: es una migracion. down vacio: no hay nada que devolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('verificacion_documento_registro')) return;

        DB::table('verificacion_documento_registro')
            ->where('TIPO', 'rotc')
            ->where('ORIGEN', 'noche')
            ->whereNull('APLICADO_POR')
            ->whereRaw("JSON_EXTRACT(DIFERENCIAS, '$.NOMBRE_DEL_TITULAR') IS NOT NULL")
            ->delete();
    }

    public function down(): void
    {
    }
};
