<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Serial del brazo (la grúa montada) de los equipos de izaje: camiones y chutos con brazo,
 * camiones grúa. El brazo se certifica aparte del camión (certificados de Exactitud por el
 * serial de la grúa), así que su serial es dato propio de la UNIDAD. Qué tipos lo llevan lo
 * dice TipoEquipo::llevaBrazo(). Sin índice único: un brazo se puede pasar de camión.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('equipos', 'SERIAL_BRAZO')) return;

        Schema::table('equipos', function (Blueprint $table) {
            $table->string('SERIAL_BRAZO', 100)->nullable()->after('SERIAL_DE_MOTOR');
        });
    }

    public function down(): void
    {
        Schema::table('equipos', fn (Blueprint $t) => $t->dropColumn('SERIAL_BRAZO'));
    }
};
