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
        Schema::table('documentacion', function (Blueprint $table) {
            if (!Schema::hasColumn('documentacion', 'FECHA_ADICIONAL')) {
                $table->date('FECHA_ADICIONAL')->nullable();
            }
            if (!Schema::hasColumn('documentacion', 'ADICIONAL_FECHA_SUBIDA')) {
                $table->timestamp('ADICIONAL_FECHA_SUBIDA')->nullable();
            }
            if (!Schema::hasColumn('documentacion', 'ADICIONAL_SUBIDO_POR')) {
                $table->unsignedBigInteger('ADICIONAL_SUBIDO_POR')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documentacion', function (Blueprint $table) {
            $table->dropColumn('FECHA_ADICIONAL');
        });
    }
};
