<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Añade `CONTRATOS` (JSON) a `frentes_trabajo`.
 *
 * Un frente (proyecto) puede tener uno o varios números de contrato asociados. El campo
 * alimenta el autocompletado del input "Contrato N°" en el modal "Registrar salida" del
 * inventario: al elegir el proyecto/frente, se sugieren los contratos vinculados.
 *
 * Tipo de columna: JSON. MySQL 5.7+ y MariaDB 10.2+ lo soportan nativamente y permite
 * que el modelo lo lea como array PHP vía `protected $casts = ['CONTRATOS' => 'array']`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('frentes_trabajo', function (Blueprint $table) {
            $table->json('CONTRATOS')->nullable()->after('UBICACION');
        });
    }

    public function down(): void
    {
        Schema::table('frentes_trabajo', function (Blueprint $table) {
            $table->dropColumn('CONTRATOS');
        });
    }
};
