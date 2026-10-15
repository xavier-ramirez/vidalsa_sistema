<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Agrega 'VENTA' al enum TIPO_FRENTE: frentes con activos vendidos o por vender. Igual
     * que ESPECIAL, quedan FUERA de la flota (FrenteTrabajo::TIPOS_FUERA_DE_FLOTA): no entran
     * en los totales ni en las alertas, porque la empresa ya no cuenta con esos equipos.
     * Idempotente: si el enum ya trae VENTA, el ALTER no cambia nada.
     */
    public function up(): void
    {
        if (!Schema::hasTable('frentes_trabajo') || !Schema::hasColumn('frentes_trabajo', 'TIPO_FRENTE')) {
            return;
        }

        DB::statement("ALTER TABLE frentes_trabajo MODIFY TIPO_FRENTE ENUM('RESGUARDO','OPERACION','ESPECIAL','VENTA') NOT NULL DEFAULT 'OPERACION'");
        Cache::forget('frentes_fuera_de_flota_ids');
        // El listado sin conexión guarda qué frentes quedan fuera de la flota: se rehace.
        \App\Support\OfflineVersion::invalidar('catalogos');
    }

    public function down(): void
    {
        if (!Schema::hasTable('frentes_trabajo') || !Schema::hasColumn('frentes_trabajo', 'TIPO_FRENTE')) {
            return;
        }

        // VENTA -> ESPECIAL: el más parecido (también fuera de la flota), para poder bajar el enum.
        DB::table('frentes_trabajo')->where('TIPO_FRENTE', 'VENTA')->update(['TIPO_FRENTE' => 'ESPECIAL']);
        DB::statement("ALTER TABLE frentes_trabajo MODIFY TIPO_FRENTE ENUM('RESGUARDO','OPERACION','ESPECIAL') NOT NULL DEFAULT 'OPERACION'");
        Cache::forget('frentes_fuera_de_flota_ids');
        \App\Support\OfflineVersion::invalidar('catalogos');
    }
};
