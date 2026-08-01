<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ETAPA del filtro DENTRO de cada equipo (primario / secundario / …).
     *
     * La etapa NO es una propiedad del filtro sino de dónde va montado: el mismo
     * 1R-0762 es PRIMARIO en la mototrailla 631G y SECUNDARIO en la excavadora
     * 324D. Por eso vive en la relación equipo↔filtro y no en el producto — igual
     * que `producto_kit_componentes` guarda el ROL en la relación.
     *
     * Vacío = no se sabe. Es un valor válido y deliberado: preferimos el hueco a
     * inventar una etapa que haga entregar la pieza equivocada.
     */
    public function up(): void
    {
        foreach (['modelo_filtro', 'auxiliar_filtro'] as $tabla) {
            if (! Schema::hasTable($tabla) || Schema::hasColumn($tabla, 'ETAPA')) continue;

            Schema::table($tabla, function (Blueprint $table) {
                $table->string('ETAPA', 20)->nullable()->after('CANTIDAD')
                      ->comment('PRIMARIO | SECUNDARIO | SEGURIDAD | UNICO | NULL = sin confirmar');
            });
        }
    }

    public function down(): void
    {
        foreach (['modelo_filtro', 'auxiliar_filtro'] as $tabla) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'ETAPA')) continue;
            Schema::table($tabla, fn (Blueprint $table) => $table->dropColumn('ETAPA'));
        }
    }
};
