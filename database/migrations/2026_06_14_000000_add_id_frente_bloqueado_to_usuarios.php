<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frentes BLOQUEADOS por usuario (lista negra, CSV de IDs — mismo formato que
     * ID_FRENTE_ASIGNADO). Se RESTA de la visibilidad sin importar GLOBAL/LOCAL:
     * un GLOBAL ve todos los frentes MENOS estos. Permite el caso "ve casi todo
     * salvo unos pocos" tildando solo lo prohibido, en vez de hacerlo LOCAL y
     * tildar muchísimos asignados. La barrera vive en Usuario::aplicarBloqueoIds.
     */
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('ID_FRENTE_BLOQUEADO', 500)->nullable()->after('ID_FRENTE_ASIGNADO');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('ID_FRENTE_BLOQUEADO');
        });
    }
};
