<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El certificado del auxiliar se llama igual que el del equipo (EnlacesDocumentos::DOCUMENTOS):
 * en el filtro de Compresión es UN "Certificado". Las filas ya registradas con el nombre viejo
 * se renombran; cuál es de un auxiliar lo sigue diciendo TABLA.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('compresion_pdf_registro')->where('DOCUMENTO', 'Certificado (auxiliar)')
            ->update(['DOCUMENTO' => 'Certificado']);
    }

    public function down(): void
    {
        DB::table('compresion_pdf_registro')->where('DOCUMENTO', 'Certificado')->where('TABLA', 'equipos_auxiliares')
            ->update(['DOCUMENTO' => 'Certificado (auxiliar)']);
    }
};
