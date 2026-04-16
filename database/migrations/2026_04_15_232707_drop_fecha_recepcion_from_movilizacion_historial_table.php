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
        Schema::table('movilizacion_historial', function (Blueprint $table) {
            $table->dropColumn('FECHA_RECEPCION');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movilizacion_historial', function (Blueprint $table) {
            $table->dateTime('FECHA_RECEPCION')->nullable();
        });
    }
};
