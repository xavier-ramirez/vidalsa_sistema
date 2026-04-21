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
            $table->date('FECHA_ADICIONAL')->nullable()->after('ADICIONAL_FECHA_SUBIDA');
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
