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
        Schema::table('equipos', function (Blueprint $table) {
            if (!Schema::hasColumn('equipos', 'CAPACIDAD')) {
                // Misma semántica que equipos_auxiliares.CAPACIDAD (string libre:
                // "20 TON", "300A", "50kVA"...). Nullable; no todos los equipos la usan.
                $table->string('CAPACIDAD', 80)->nullable()->after('MODELO');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('equipos', function (Blueprint $table) {
            $table->dropColumn('CAPACIDAD');
        });
    }
};
