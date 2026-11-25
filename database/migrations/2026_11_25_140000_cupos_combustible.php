<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cupos de gasoil: cada patio (El Tigre, Maturín) tiene un cupo fijo de litros por mes. Si alguna vez
 * cambia, se guarda desde qué mes rige. Lo que consume el cupo son las cisternas surtidas (patio,
 * fecha y litros). Disponible del mes = cupo − litros surtidos.
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
