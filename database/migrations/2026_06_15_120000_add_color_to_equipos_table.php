<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipos', function (Blueprint $table) {
            if (!Schema::hasColumn('equipos', 'COLOR')) {
                $table->string('COLOR', 50)->nullable()->after('ANIO');
            }
        });
    }

    public function down(): void
    {
        Schema::table('equipos', function (Blueprint $table) {
            if (Schema::hasColumn('equipos', 'COLOR')) {
                $table->dropColumn('COLOR');
            }
        });
    }
};
