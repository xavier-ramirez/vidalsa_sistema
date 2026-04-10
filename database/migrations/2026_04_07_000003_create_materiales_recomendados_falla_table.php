<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materiales_recomendados_falla', function (Blueprint $table): void {
            $table->bigIncrements('ID_MATERIAL_REC');
            $table->unsignedBigInteger('ID_FALLA');
            $table->string('DESCRIPCION_MATERIAL', 255);
            $table->string('ESPECIFICACION', 150)->nullable();
            $table->decimal('CANTIDAD', 10, 2)->default(1);
            $table->string('UNIDAD', 50)->default('UNIDADES');
            $table->string('FUENTE', 20)->default('MANUAL');
            $table->unsignedBigInteger('ID_ESPEC_ORIGEN')->nullable();
            $table->string('CAMPO_ORIGEN', 50)->nullable();
            $table->timestamps();

            $table->foreign('ID_FALLA')->references('ID_FALLA')->on('registros_fallas')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materiales_recomendados_falla');
    }
};
