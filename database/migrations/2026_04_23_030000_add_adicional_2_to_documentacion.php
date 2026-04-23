<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Segundo documento adicional (mismo modelo que ADICIONAL): link, fecha vencimiento,
     * auditoria de subida. Todo nullable para no afectar registros existentes.
     */
    public function up(): void
    {
        if (!Schema::hasTable('documentacion')) {
            return;
        }

        // after() solo es seguro si la columna de referencia existe; si no, crear al final.
        $hasBaseAdicional = Schema::hasColumn('documentacion', 'LINK_DOC_ADICIONAL');

        Schema::table('documentacion', function (Blueprint $table) use ($hasBaseAdicional) {
            if (!Schema::hasColumn('documentacion', 'LINK_DOC_ADICIONAL_2')) {
                $col = $table->text('LINK_DOC_ADICIONAL_2')->nullable();
                if ($hasBaseAdicional) $col->after('LINK_DOC_ADICIONAL');
            }
            if (!Schema::hasColumn('documentacion', 'FECHA_ADICIONAL_2')) {
                $col = $table->date('FECHA_ADICIONAL_2')->nullable();
                if (Schema::hasColumn('documentacion', 'LINK_DOC_ADICIONAL_2')) {
                    $col->after('LINK_DOC_ADICIONAL_2');
                }
            }
            if (!Schema::hasColumn('documentacion', 'ADICIONAL_2_SUBIDO_POR')) {
                $col = $table->unsignedBigInteger('ADICIONAL_2_SUBIDO_POR')->nullable();
                if (Schema::hasColumn('documentacion', 'FECHA_ADICIONAL_2')) {
                    $col->after('FECHA_ADICIONAL_2');
                }
            }
            if (!Schema::hasColumn('documentacion', 'ADICIONAL_2_FECHA_SUBIDA')) {
                $col = $table->timestamp('ADICIONAL_2_FECHA_SUBIDA')->nullable();
                if (Schema::hasColumn('documentacion', 'ADICIONAL_2_SUBIDO_POR')) {
                    $col->after('ADICIONAL_2_SUBIDO_POR');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('documentacion', function (Blueprint $table) {
            foreach (['ADICIONAL_2_FECHA_SUBIDA', 'ADICIONAL_2_SUBIDO_POR', 'FECHA_ADICIONAL_2', 'LINK_DOC_ADICIONAL_2'] as $col) {
                if (Schema::hasColumn('documentacion', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
