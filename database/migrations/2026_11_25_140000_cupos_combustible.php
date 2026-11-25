<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cupos de gasoil: cada patio (El Tigre, Maturín) tiene un cupo de litros por mes que casi nunca
 * cambia. Se guarda desde qué mes rige (sigue igual hasta que se cambie). De ese cupo se piden
 * cisternas (patio, fecha, cisterna y litros). Disponible del mes = cupo − litros pedidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('cupos_combustible')) {
            Schema::create('cupos_combustible', function (Blueprint $table) {
                $table->id('ID_CUPO');
                $table->string('PATIO', 20);
                $table->date('MES');                       // rige desde el día 1 de este mes
                $table->decimal('LITROS', 12, 2)->default(0);
                $table->unsignedBigInteger('ID_USUARIO')->nullable();
                $table->timestamps();
                $table->unique(['PATIO', 'MES']);
            });
        }

        if (!Schema::hasTable('cupos_cisternas')) {
            Schema::create('cupos_cisternas', function (Blueprint $table) {
                $table->id('ID_CISTERNA');
                $table->string('PATIO', 20);
                $table->date('FECHA');
                $table->string('CISTERNA', 60)->nullable();   // placa o número de la cisterna
                $table->decimal('LITROS', 12, 2);
                $table->unsignedBigInteger('ID_USUARIO')->nullable();
                $table->timestamps();
                $table->index(['PATIO', 'FECHA']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cupos_cisternas');
        Schema::dropIfExists('cupos_combustible');
    }
};
