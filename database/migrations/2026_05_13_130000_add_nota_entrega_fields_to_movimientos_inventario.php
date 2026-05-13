<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega los campos de la "Nota de Entrega de Materiales" (formato VID-FO-GEN-019)
     * a `movimientos_inventario`. Solo se llenan en movimientos de SALIDA (consumo a
     * frente / proyecto); en ENTRADA / AJUSTE / TRASPASO_* quedan NULL.
     *
     *  - NUMERO_CONTRATO : Nº del contrato del proyecto solicitante.
     *  - NUMERO_RQ       : Nº de Requisición que originó la salida.
     *  - SOLICITANTE     : nombre del solicitante (texto libre — puede no ser un usuario del sistema).
     *  - DEPARTAMENTO    : departamento al que se entrega.
     *
     * `ID_FRENTE` (ya existente) sigue siendo el PROYECTO al que se consume.
     */
    public function up(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->string('NUMERO_CONTRATO', 100)->nullable()->after('REFERENCIA')
                  ->comment('Nota de Entrega: Nº de contrato del proyecto (SALIDA).');
            $table->string('NUMERO_RQ', 100)->nullable()->after('NUMERO_CONTRATO')
                  ->comment('Nota de Entrega: Nº de Requisición (SALIDA).');
            $table->string('SOLICITANTE', 200)->nullable()->after('NUMERO_RQ')
                  ->comment('Nota de Entrega: nombre del solicitante (SALIDA).');
            $table->string('DEPARTAMENTO', 150)->nullable()->after('SOLICITANTE')
                  ->comment('Nota de Entrega: departamento que recibe (SALIDA).');
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->dropColumn(['NUMERO_CONTRATO', 'NUMERO_RQ', 'SOLICITANTE', 'DEPARTAMENTO']);
        });
    }
};
