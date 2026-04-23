<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Tabla de auditoria para EDICIONES de equipos (no la creacion, esa
     * vive en equipos.CREADO_POR). Un registro por update significativo:
     * datos generales, metadata de documento o ubicacion.
     */
    public function up(): void
    {
        if (Schema::hasTable('equipo_audit_log')) return;

        Schema::create('equipo_audit_log', function (Blueprint $table) {
            $table->bigIncrements('ID_LOG');
            $table->unsignedBigInteger('ID_EQUIPO');
            $table->unsignedBigInteger('ID_USUARIO')->nullable();
            $table->string('ACCION', 40); // edit | metadata_propiedad | metadata_poliza | metadata_rotc | metadata_racda | metadata_adicional | metadata_adicional_2 | ubicacion
            $table->text('CAMBIOS')->nullable(); // JSON con diff compacto { campo: [antes, despues] }
            $table->timestamp('created_at')->useCurrent();

            $table->index('ID_EQUIPO');
            $table->index('ID_USUARIO');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipo_audit_log');
    }
};
