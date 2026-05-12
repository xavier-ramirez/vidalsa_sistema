<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla maestra de almacenes (depósitos de inventario).
     *
     *  - GENERAL  : almacenes centrales/globales. Su personal puede CONSULTAR el
     *               stock de cualquier almacén del sistema (no están atados a un
     *               frente). Ej: "ALMACÉN CENTRAL CARACAS".
     *  - PROYECTO : almacenes de obra/tienda. Cada frente de trabajo (proyecto)
     *               puede tener el suyo, y varios frentes pueden compartir uno
     *               mismo. El vínculo almacén⇄frente vive en `almacen_frentes`.
     */
    public function up(): void
    {
        Schema::create('almacenes', function (Blueprint $table) {
            $table->id('ID_ALMACEN');

            $table->string('CODIGO', 30)->nullable()->unique()
                  ->comment('Código corto opcional del almacén (ej: ALM-001)');

            $table->string('NOMBRE', 150)->unique()
                  ->comment('Nombre del almacén (ej: ALMACÉN CENTRAL, ALMACÉN PROYECTO BOLÍVAR)');

            $table->enum('TIPO', ['GENERAL', 'PROYECTO'])->default('PROYECTO')
                  ->comment('GENERAL = central/global (ve todo el stock); PROYECTO = de obra/tienda, ligado a uno o varios frentes');

            $table->string('UBICACION', 150)->nullable()
                  ->comment('Dirección o ubicación física del almacén');

            $table->enum('ESTATUS', ['ACTIVO', 'INACTIVO'])->default('ACTIVO');

            $table->text('NOTAS')->nullable();

            $table->unsignedBigInteger('CREADO_POR')->nullable()
                  ->comment('FK → usuarios — quién creó el almacén');
            $table->foreign('CREADO_POR')->references('ID_USUARIO')->on('usuarios')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('TIPO',    'idx_alm_tipo');
            $table->index('ESTATUS', 'idx_alm_estatus');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('almacenes');
    }
};
