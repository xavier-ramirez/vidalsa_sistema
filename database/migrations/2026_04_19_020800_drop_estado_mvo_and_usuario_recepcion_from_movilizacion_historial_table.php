<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Con guardas, como 2026_04_22_164500: al revertir, esa migración ya vuelve a crear
        // ESTADO_MVO, y sin la guarda este down() abortaba con "Duplicate column name".
        $sobran = array_values(array_filter(['ESTADO_MVO', 'USUARIO_RECEPCION'],
            fn ($c) => Schema::hasColumn('movilizacion_historial', $c)));
        if ($sobran) {
            Schema::table('movilizacion_historial', function (Blueprint $table) use ($sobran) {
                $table->dropColumn($sobran);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movilizacion_historial', function (Blueprint $table) {
            if (!Schema::hasColumn('movilizacion_historial', 'ESTADO_MVO')) {
                $table->string('ESTADO_MVO', 20)->default('COMPLETADO');
            }
            if (!Schema::hasColumn('movilizacion_historial', 'USUARIO_RECEPCION')) {
                $table->string('USUARIO_RECEPCION', 100)->nullable();
            }
        });
    }
};
