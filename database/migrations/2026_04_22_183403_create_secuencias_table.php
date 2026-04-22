<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secuencias', function (Blueprint $table) {
            $table->string('nombre', 50)->primary(); // ej: 'CODIGO_CONTROL'
            $table->unsignedBigInteger('valor')->default(0);
            $table->timestamps();
        });

        // Inicializar con el MAX real que ya existe en la tabla,
        // para no reiniciar la numeración si ya hay datos.
        DB::table('secuencias')->insert([
            'nombre'     => 'CODIGO_CONTROL',
            'valor'      => (int) DB::table('movilizacion_historial')->max('CODIGO_CONTROL') ?: 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('secuencias');
    }
};
