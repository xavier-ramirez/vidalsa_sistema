<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de Control de Auditoría (HistorialDocumentosController::construirEventos): lee los
 * apuntes de equipos (ID_AUXILIAR NULL) y los de auxiliares por separado, los más nuevos primero
 * y con tope. Con solo el índice de ID_AUXILIAR, MySQL ordenaba a mano TODOS los apuntes para
 * quedarse con los primeros; con (ID_AUXILIAR, created_at) los lee ya en orden y para en el tope.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasIndex('equipo_audit_log', 'equipo_audit_log_auxiliar_fecha_index')) {
            Schema::table('equipo_audit_log', function (Blueprint $table) {
                $table->index(['ID_AUXILIAR', 'created_at'], 'equipo_audit_log_auxiliar_fecha_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('equipo_audit_log', fn (Blueprint $t) => $t->dropIndex('equipo_audit_log_auxiliar_fecha_index'));
    }
};
