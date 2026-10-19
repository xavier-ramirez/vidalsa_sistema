<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ESTADO_POLIZA no se guarda: el estado de la póliza se calcula con su vencimiento. La columna
 * la creaba create_documentacion_table, pero en el servidor ya no existe; en local seguía, vacía.
 * Con esta, las dos bases (y una instalación nueva) quedan iguales. En el servidor no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('documentacion', 'ESTADO_POLIZA')) {
            Schema::table('documentacion', function (Blueprint $table) {
                $table->dropColumn('ESTADO_POLIZA');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('documentacion', 'ESTADO_POLIZA')) {
            Schema::table('documentacion', function (Blueprint $table) {
                $table->string('ESTADO_POLIZA', 50)->nullable();
            });
        }
    }
};
