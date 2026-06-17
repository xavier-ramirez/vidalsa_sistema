<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elimina CLASE_ACTIVO de `fallas`. El acta ahora deja las casillas
 * Maquinaria/Vehículo/Otro vacías para que el usuario las marque al imprimir,
 * por lo que la clase calculada por el sistema dejó de tener consumidor
 * (era una escritura muerta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fallas', function (Blueprint $table) {
            $table->dropColumn('CLASE_ACTIVO');
        });
    }

    public function down(): void
    {
        Schema::table('fallas', function (Blueprint $table) {
            $table->enum('CLASE_ACTIVO', ['MAQUINARIA', 'VEHICULO', 'OTRO'])
                  ->nullable()->after('FRENTE_TRABAJO');
        });
    }
};
