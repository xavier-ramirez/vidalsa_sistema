<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caracteristicas_modelo', function (Blueprint $table) {
            if (!Schema::hasColumn('caracteristicas_modelo', 'TIPO')) {
                $table->string('TIPO', 35)->nullable()->after('MODELO');
            }
        });

        // Backfill: cada catálogo hereda el TIPO más frecuente de los equipos que ya lo
        // usan (equipos.ID_ESPEC → equipos.id_tipo_equipo → tipo_equipos.nombre). Los
        // catálogos sin equipos vinculados quedan en NULL (se asignan desde el formulario).
        $rows = DB::table('equipos')
            ->join('tipo_equipos', 'tipo_equipos.id', '=', 'equipos.id_tipo_equipo')
            ->whereNotNull('equipos.ID_ESPEC')
            ->whereNotNull('equipos.id_tipo_equipo')
            ->select('equipos.ID_ESPEC', 'tipo_equipos.nombre', DB::raw('COUNT(*) as c'))
            ->groupBy('equipos.ID_ESPEC', 'tipo_equipos.nombre')
            ->get();

        $mejorPorEspec = [];
        foreach ($rows as $r) {
            if (!isset($mejorPorEspec[$r->ID_ESPEC]) || $r->c > $mejorPorEspec[$r->ID_ESPEC]['c']) {
                $mejorPorEspec[$r->ID_ESPEC] = ['nombre' => $r->nombre, 'c' => $r->c];
            }
        }
        foreach ($mejorPorEspec as $idEspec => $info) {
            DB::table('caracteristicas_modelo')
                ->where('ID_ESPEC', $idEspec)
                ->update(['TIPO' => strtoupper(trim($info['nombre']))]);
        }
    }

    public function down(): void
    {
        Schema::table('caracteristicas_modelo', function (Blueprint $table) {
            if (Schema::hasColumn('caracteristicas_modelo', 'TIPO')) {
                $table->dropColumn('TIPO');
            }
        });
    }
};
