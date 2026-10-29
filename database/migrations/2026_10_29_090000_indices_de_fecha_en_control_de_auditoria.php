<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Filtros de fecha de Control de Auditoría (App\Support\PanelDocumentos::entreFechas): la
 * pestaña Documentos filtra por updated_at y la de Compresión por created_at, y ninguna de las
 * dos columnas tenía índice: cada cuenta, página y resumen recorría la tabla entera (con el JSON
 * de cada lectura). ESTADO va detrás para que la cuenta por estado salga del índice solo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasIndex('verificacion_documento_registro', 'verif_doc_updated_estado_index')) {
            Schema::table('verificacion_documento_registro', function (Blueprint $table) {
                $table->index(['updated_at', 'ESTADO'], 'verif_doc_updated_estado_index');
            });
        }
        if (!Schema::hasIndex('compresion_pdf_registro', 'compresion_pdf_created_index')) {
            Schema::table('compresion_pdf_registro', function (Blueprint $table) {
                $table->index('created_at', 'compresion_pdf_created_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('verificacion_documento_registro', fn (Blueprint $t) => $t->dropIndex('verif_doc_updated_estado_index'));
        Schema::table('compresion_pdf_registro', fn (Blueprint $t) => $t->dropIndex('compresion_pdf_created_index'));
    }
};
