<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Tabla de Equipos Auxiliares: maquinas de soldar, luminarias, compresores,
     * contenedores, plantas electricas, etc. Reemplaza al anterior modulo
     * "sub_activos" con:
     * - Anclaje 1:N a un equipo host (camion de soldadura hasta 2 maquinas).
     * - Sin responsables (el usuario lo pidio asi explicitamente).
     * - Campos de identificacion similares al modulo principal de equipos.
     */
    public function up(): void
    {
        Schema::create('equipos_auxiliares', function (Blueprint $table) {
            $table->bigIncrements('ID_AUXILIAR');
            $table->string('TIPO', 30);                   // MAQUINA_SOLDAR, LUMINARIA, COMPRESOR, CONTAINER, PLANTA_ELECTRICA, OTRO
            $table->string('MARCA', 80)->nullable();
            $table->string('MODELO', 80)->nullable();
            $table->string('SERIAL', 100)->nullable();
            $table->string('CODIGO_INTERNO', 50)->nullable()->comment('Etiqueta interna / inventario');
            $table->string('CAPACIDAD', 80)->nullable()->comment('ej: 300A, 50kVA, 20pies');
            $table->unsignedSmallInteger('ANIO')->nullable();
            $table->string('ESTADO_OPERATIVO', 30)->default('OPERATIVO'); // OPERATIVO / INOPERATIVO / EN_ALMACEN / DESINCORPORADO
            $table->unsignedBigInteger('ID_FRENTE_ACTUAL')->nullable();
            $table->unsignedBigInteger('ID_EQUIPO_HOST')->nullable()->comment('FK al equipo camion-host (nullable: no todos estan anclados)');
            $table->string('FOTO')->nullable();
            $table->text('OBSERVACIONES')->nullable();
            $table->unsignedBigInteger('CREADO_POR')->nullable();
            $table->timestamps();

            $table->index('ID_FRENTE_ACTUAL');
            $table->index('ID_EQUIPO_HOST');
            $table->index('TIPO');
            $table->index('ESTADO_OPERATIVO');

            $table->foreign('ID_FRENTE_ACTUAL')->references('ID_FRENTE')->on('frentes_trabajo')->onDelete('set null');
            $table->foreign('ID_EQUIPO_HOST')->references('ID_EQUIPO')->on('equipos')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipos_auxiliares');
    }
};
