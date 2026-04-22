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

        // Inicializar con el MAX numérico real (CAST evita el orden lexicográfico del varchar).
        // Ejemplo sin CAST: MAX('99','100') = '99' (alfabético). Con CAST: MAX = 100 (correcto).
        DB::table('secuencias')->insert([
            'nombre'     => 'CODIGO_CONTROL',
            'valor'      => (int) DB::selectOne("SELECT MAX(CAST(CODIGO_CONTROL AS UNSIGNED)) as m FROM movilizacion_historial")->m ?: 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('secuencias');
    }
};
