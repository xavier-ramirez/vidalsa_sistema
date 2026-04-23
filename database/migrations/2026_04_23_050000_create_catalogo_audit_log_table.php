<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Auditoria de creaciones y ediciones del catalogo de modelos
     * (caracteristicas_modelo). Permite llevar el control de cambios
     * desde la vista /admin/consumibles/graficos.
     */
    public function up(): void
    {
        if (Schema::hasTable('catalogo_audit_log')) return;

        Schema::create('catalogo_audit_log', function (Blueprint $table) {
            $table->bigIncrements('ID_LOG');
            $table->unsignedBigInteger('ID_ESPEC')->nullable(); // nullable por si se borra el modelo
            $table->unsignedBigInteger('ID_USUARIO')->nullable();
            $table->string('ACCION', 20); // create | edit | delete
            $table->string('MODELO', 80)->nullable(); // snapshot al momento del evento
            $table->unsignedSmallInteger('ANIO_ESPEC')->nullable();
            $table->text('CAMBIOS')->nullable(); // JSON { campo: [antes, despues] }
            $table->timestamp('created_at')->useCurrent();

            $table->index('ID_ESPEC');
            $table->index('ID_USUARIO');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogo_audit_log');
    }
};
