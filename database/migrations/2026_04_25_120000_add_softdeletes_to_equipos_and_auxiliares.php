<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-delete + audit trail para equipos y equipos_auxiliares.
 *
 * - deleted_at: marca de borrado (Laravel SoftDeletes). NULL = activo.
 * - deleted_by: ID_USUARIO de quien hizo el borrado (auditoria). NULL si
 *               el registro nunca fue borrado o si fue restaurado.
 *
 * Las queries existentes NO se rompen: SoftDeletes filtra automaticamente
 * "WHERE deleted_at IS NULL" en todos los selects normales.
 *
 * Reversible: down() elimina ambas columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipos', function (Blueprint $table) {
            $table->softDeletes();
            $table->unsignedBigInteger('deleted_by')->nullable()->after('deleted_at');
        });

        Schema::table('equipos_auxiliares', function (Blueprint $table) {
            $table->softDeletes();
            $table->unsignedBigInteger('deleted_by')->nullable()->after('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('equipos', function (Blueprint $table) {
            $table->dropColumn(['deleted_by']);
            $table->dropSoftDeletes();
        });

        Schema::table('equipos_auxiliares', function (Blueprint $table) {
            $table->dropColumn(['deleted_by']);
            $table->dropSoftDeletes();
        });
    }
};
