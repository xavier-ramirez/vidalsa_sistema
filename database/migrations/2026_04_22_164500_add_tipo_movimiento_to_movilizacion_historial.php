<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esta migración es una corrección de emergencia para el entorno local.
     * En el servidor, TIPO_MOVIMIENTO ya fue creada por 2026_02_19 y ESTADO_MVO
     * ya fue eliminado por 2026_04_19 — por lo tanto ambas acciones están protegidas
     * por guardas hasColumn para evitar cualquier fallo.
     */
    public function up(): void
    {
        Schema::table('movilizacion_historial', function (Blueprint $table) {
            // Solo agrega si no existe (en servidor ya existe desde 2026_02_19)
            if (!Schema::hasColumn('movilizacion_historial', 'TIPO_MOVIMIENTO')) {
                $table->string('TIPO_MOVIMIENTO', 30)->nullable()->default('DESPACHO')->after('ID_FRENTE_RECEPCION');
            }

            // Solo elimina si todavía existe (en servidor ya fue eliminado por 2026_04_19)
            if (Schema::hasColumn('movilizacion_historial', 'ESTADO_MVO')) {
                $table->dropColumn('ESTADO_MVO');
            }
        });
    }

    /**
     * Vacío a propósito: TIPO_MOVIMIENTO casi siempre la creó 2026_02_19, no esta, y ESTADO_MVO
     * lo quitó 2026_04_19. Revertir esta borraría los tipos de movimiento ya guardados.
     */
    public function down(): void
    {
    }
};
