<?php

use App\Models\Consumible;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consumibles: la observación que el Excel escribe junto al vehículo («MACK VACUUM - Apoyo a
 * ECOINNOVA, C.A»), guardada aparte. Si el equipo se reconoce, la Lista muestra el del sistema y
 * esa nota se perdía de vista; ahora sale siempre debajo del equipo.
 * Rellena los ya cargados a partir de su DESC_EQUIPO (misma regla que la carga).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('consumibles', 'OBSERVACION')) {
            Schema::table('consumibles', function (Blueprint $table) {
                $table->string('OBSERVACION', 255)->nullable()->after('DESC_EQUIPO');
            });
        }

        DB::table('consumibles')->whereNull('OBSERVACION')->where(fn ($q) => $q->where('DESC_EQUIPO', 'like', '% - %')->orWhere('DESC_EQUIPO', 'like', '%(%'))
            ->select('ID_CONSUMIBLE', 'DESC_EQUIPO')->orderBy('ID_CONSUMIBLE')
            ->chunkById(500, function ($filas) {
                foreach ($filas as $f) {
                    $obs = Consumible::observacionDe($f->DESC_EQUIPO);
                    if ($obs !== null) {
                        DB::table('consumibles')->where('ID_CONSUMIBLE', $f->ID_CONSUMIBLE)->update(['OBSERVACION' => $obs]);
                    }
                }
            }, 'ID_CONSUMIBLE');
    }

    public function down(): void
    {
        if (Schema::hasColumn('consumibles', 'OBSERVACION')) {
            Schema::table('consumibles', function (Blueprint $table) {
                $table->dropColumn('OBSERVACION');
            });
        }
    }
};
