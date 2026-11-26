<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cupos de gasoil: cada cisterna puede llevar su foto (enlace /storage/google/{id} de Drive). */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('cupos_cisternas', 'FOTO')) {
            Schema::table('cupos_cisternas', function (Blueprint $table) {
                $table->string('FOTO', 255)->nullable()->after('LITROS');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cupos_cisternas', 'FOTO')) {
            Schema::table('cupos_cisternas', function (Blueprint $table) {
                $table->dropColumn('FOTO');
            });
        }
    }
};
