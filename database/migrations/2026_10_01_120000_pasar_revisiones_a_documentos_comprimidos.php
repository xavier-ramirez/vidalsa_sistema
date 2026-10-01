<?php

use App\Models\CompresionPdf;
use App\Support\EnlacesDocumentos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Arreglo del 01-10-2026: hasta hoy la compresion nocturna (docs:comprimir) cambiaba el PDF por
 * su version comprimida sin llevarse la REVISION del documento, que va atada al DRIVE_ID. Para
 * la lectura de la noche quedaba como un documento nuevo: lo volvia a leer y, sin encontrar lo
 * "revisado a mano" (tambien se busca por DRIVE_ID), podia poner lo del PDF encima de lo que una
 * persona ya habia decidido. Desde hoy EnlacesDocumentos::cambiar se la lleva; esto lo hace con
 * lo ya comprimido, en el orden en que se comprimio. Las revisiones que ya tiene el archivo
 * nuevo no se tocan.
 *
 * Una sola vez: es una migracion. down vacio: deshacerlo volveria a mandar a releer lo revisado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('verificacion_documento_registro')
            || !DB::getSchemaBuilder()->hasTable('compresion_pdf_registro')) return;

        DB::table('compresion_pdf_registro')
            ->where('ESTADO', CompresionPdf::COMPRIMIDO)
            ->whereNotNull('DRIVE_ID_NUEVO')
            ->orderBy('created_at')->orderBy('ID_REGISTRO')
            ->get(['DRIVE_ID_VIEJO', 'DRIVE_ID_NUEVO'])
            ->each(fn ($c) => EnlacesDocumentos::pasarRevisiones($c->DRIVE_ID_VIEJO, $c->DRIVE_ID_NUEVO));
    }

    public function down(): void
    {
    }
};
