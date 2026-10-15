<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frentes con borrado LÓGICO: "Eliminar" en /admin/frentes deja de borrar la fila. Al borrarla
 * de verdad, el historial perdía el frente: movilizaciones viejas sin nombre guardado, y las FK
 * de movimientos_inventario / consumibles / suministros_origen / traspasos lo dejaban en NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('frentes_trabajo', 'deleted_at')) {
            Schema::table('frentes_trabajo', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('frentes_trabajo', 'deleted_at')) {
            Schema::table('frentes_trabajo', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
