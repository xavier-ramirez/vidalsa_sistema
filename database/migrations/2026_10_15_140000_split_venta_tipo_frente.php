<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * TIPO_FRENTE 'VENTA' se parte en DOS: 'VENDIDO' (activos ya vendidos) y 'TRAMITE_VENTA'
     * (activos en trámite de venta). Los dos siguen FUERA de la flota
     * (FrenteTrabajo::TIPOS_FUERA_DE_FLOTA). Un frente que ya fuera VENTA pasa a TRAMITE_VENTA
     * (lo más prudente: que alguien confirme si ya se vendió). Va en tres pasos porque MySQL no
     * deja dejar un valor en uso fuera del enum: agregar los nuevos, mover, quitar VENTA.
     */
    public function up(): void
    {
        if (!Schema::hasTable('frentes_trabajo') || !Schema::hasColumn('frentes_trabajo', 'TIPO_FRENTE')) {
            return;
        }

        DB::statement("ALTER TABLE frentes_trabajo MODIFY TIPO_FRENTE ENUM('RESGUARDO','OPERACION','ESPECIAL','VENTA','VENDIDO','TRAMITE_VENTA') NOT NULL DEFAULT 'OPERACION'");
        DB::table('frentes_trabajo')->where('TIPO_FRENTE', 'VENTA')->update(['TIPO_FRENTE' => 'TRAMITE_VENTA']);
        DB::statement("ALTER TABLE frentes_trabajo MODIFY TIPO_FRENTE ENUM('RESGUARDO','OPERACION','ESPECIAL','VENDIDO','TRAMITE_VENTA') NOT NULL DEFAULT 'OPERACION'");
        Cache::forget('frentes_fuera_de_flota_ids');
        \App\Support\OfflineVersion::invalidar('catalogos');
    }

    public function down(): void
    {
        if (!Schema::hasTable('frentes_trabajo') || !Schema::hasColumn('frentes_trabajo', 'TIPO_FRENTE')) {
            return;
        }

        DB::statement("ALTER TABLE frentes_trabajo MODIFY TIPO_FRENTE ENUM('RESGUARDO','OPERACION','ESPECIAL','VENTA','VENDIDO','TRAMITE_VENTA') NOT NULL DEFAULT 'OPERACION'");
        DB::table('frentes_trabajo')->whereIn('TIPO_FRENTE', ['VENDIDO', 'TRAMITE_VENTA'])->update(['TIPO_FRENTE' => 'VENTA']);
        DB::statement("ALTER TABLE frentes_trabajo MODIFY TIPO_FRENTE ENUM('RESGUARDO','OPERACION','ESPECIAL','VENTA') NOT NULL DEFAULT 'OPERACION'");
        Cache::forget('frentes_fuera_de_flota_ids');
        \App\Support\OfflineVersion::invalidar('catalogos');
    }
};
