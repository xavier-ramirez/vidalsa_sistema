<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `ALMACENISTA` a `almacenes` (texto libre, nullable).
 *
 * Concepto: nombre del almacenista responsable de este almacén. Aparece como
 * "Entregado por:" en la Nota de Entrega de Materiales (VID-FO-GEN-019).
 * Como un almacén PROYECTO esta ligado a uno o varios frentes, el almacenista
 * efectivamente "varía por proyecto" — pero el campo vive aquí porque el emisor
 * de cualquier movimiento siempre es el almacenista del almacén origen.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('almacenes', function (Blueprint $t) {
            $t->string('ALMACENISTA', 200)->nullable()->after('UBICACION');
        });
    }

    public function down(): void
    {
        Schema::table('almacenes', function (Blueprint $t) {
            $t->dropColumn('ALMACENISTA');
        });
    }
};
