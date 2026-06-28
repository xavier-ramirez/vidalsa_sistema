<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Permite auditar equipos AUXILIARES en la misma tabla equipo_audit_log.
     * Un log de auxiliar lleva ID_AUXILIAR != null e ID_EQUIPO = null (el auxiliar
     * puede no estar anclado a ningún vehículo host, por eso ID_EQUIPO se hace
     * nullable). El scope/visibilidad del auxiliar se resuelve por su PROPIO frente,
     * no por el del equipo host.
     */
    public function up(): void
    {
        if (!Schema::hasTable('equipo_audit_log')) return;

        Schema::table('equipo_audit_log', function (Blueprint $table) {
            if (!Schema::hasColumn('equipo_audit_log', 'ID_AUXILIAR')) {
                $table->unsignedBigInteger('ID_AUXILIAR')->nullable()->after('ID_EQUIPO');
                $table->index('ID_AUXILIAR');
            }
        });

        // ID_EQUIPO pasa a nullable: los logs de auxiliar no tienen equipo host.
        Schema::table('equipo_audit_log', function (Blueprint $table) {
            $table->unsignedBigInteger('ID_EQUIPO')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('equipo_audit_log')) return;

        Schema::table('equipo_audit_log', function (Blueprint $table) {
            if (Schema::hasColumn('equipo_audit_log', 'ID_AUXILIAR')) {
                $table->dropIndex(['ID_AUXILIAR']);
                $table->dropColumn('ID_AUXILIAR');
            }
        });
    }
};
