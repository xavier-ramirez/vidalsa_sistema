<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El índice (ID_AUXILIAR, created_at) de la migración anterior ya sirve para todo lo que busca
 * por ID_AUXILIAR: el de ID_AUXILIAR solo sobraba (espacio y una escritura más por apunte).
 * down() lo devuelve, que es el que quita la migración add_aux_to_equipo_audit_log al revertirse.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('equipo_audit_log', 'equipo_audit_log_auxiliar_fecha_index')
            && Schema::hasIndex('equipo_audit_log', 'equipo_audit_log_id_auxiliar_index')) {
            Schema::table('equipo_audit_log', fn (Blueprint $t) => $t->dropIndex('equipo_audit_log_id_auxiliar_index'));
        }
    }

    public function down(): void
    {
        if (!Schema::hasIndex('equipo_audit_log', 'equipo_audit_log_id_auxiliar_index')) {
            Schema::table('equipo_audit_log', fn (Blueprint $t) => $t->index('ID_AUXILIAR'));
        }
    }
};
