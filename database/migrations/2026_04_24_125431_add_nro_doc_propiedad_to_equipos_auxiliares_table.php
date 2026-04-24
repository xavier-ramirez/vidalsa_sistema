<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipos_auxiliares', function (Blueprint $table) {
            $table->string('NRO_DOC_PROPIEDAD', 80)->nullable()->after('LINK_DOC_PROPIEDAD');
        });
    }

    public function down(): void
    {
        Schema::table('equipos_auxiliares', function (Blueprint $table) {
            $table->dropColumn('NRO_DOC_PROPIEDAD');
        });
    }
};
