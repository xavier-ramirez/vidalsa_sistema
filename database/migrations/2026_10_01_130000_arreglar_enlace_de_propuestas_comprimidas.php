<?php

use App\Models\CompresionPdf;
use App\Support\EnlacesDocumentos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Complemento de pasar_revisiones_a_documentos_comprimidos: esa migracion movio las revisiones
 * al archivo comprimido (DRIVE_ID), pero las de la carga masiva guardan ADEMAS el enlace del PDF
 * en su PROPUESTA, y la carga masiva las busca por ese enlace. Donde ya corrio, ese enlace se
 * quedo apuntando al archivo viejo. Aqui se pone el del comprimido. Solo toca las revisiones que
 * ya estan en el archivo nuevo y cuyo enlace sigue en el viejo. down vacio: no hay que deshacerlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('verificacion_documento_registro')
            || !DB::getSchemaBuilder()->hasTable('compresion_pdf_registro')) return;

        $comprimidos = DB::table('compresion_pdf_registro')
            ->where('ESTADO', CompresionPdf::COMPRIMIDO)
            ->whereNotNull('DRIVE_ID_NUEVO')
            ->get(['DRIVE_ID_VIEJO', 'DRIVE_ID_NUEVO']);

        foreach ($comprimidos as $c) {
            $filas = DB::table('verificacion_documento_registro')
                ->where('DRIVE_ID', $c->DRIVE_ID_NUEVO)->whereNotNull('PROPUESTA')
                ->get(['ID_REGISTRO', 'PROPUESTA']);
            foreach ($filas as $r) {
                $propuesta = EnlacesDocumentos::propuestaConEnlace($r->PROPUESTA, $c->DRIVE_ID_VIEJO, $c->DRIVE_ID_NUEVO);
                if ($propuesta !== null) {
                    DB::table('verificacion_documento_registro')->where('ID_REGISTRO', $r->ID_REGISTRO)
                        ->update(['PROPUESTA' => $propuesta]);
                }
            }
        }
    }

    public function down(): void
    {
    }
};
