<?php

use App\Models\VerificacionDocumento;
use App\Services\CorrectorFichaDocumento;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Arreglo del 21-09-2026: las lecturas guardadas ANTES de la regla de las fechas vacias
 * (CorrectorFichaDocumento: con lectura no segura se ponen las fechas que la ficha tiene
 * VACIAS) se quedaron en "Datos distintos" con la fecha ya leida y sin poner —p. ej. pólizas con
 * "Fecha de emisión: (vacío) → 2026-08-06"—. Se pasan una vez por el corrector, con lo ya leido
 * (sin volver a Drive): pone esas fechas y deja el resto como estaba, para quien decida.
 * Mismas puertas que la tarea: nada si el PDF es de otro vehiculo o el anterior, y solo si la
 * ficha SIGUE vacia.
 *
 * Una sola vez: es una migracion. down vacio: cada cambio queda en el historial del equipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('verificacion_documento_registro')) return;

        $corrector = app(CorrectorFichaDocumento::class);
        VerificacionDocumento::where('ESTADO', VerificacionDocumento::DIFIERE)
            ->orderBy('ID_REGISTRO')
            ->chunkById(100, function ($filas) use ($corrector) {
                foreach ($filas as $reg) $corrector->aplicar($reg);
            }, 'ID_REGISTRO');
    }

    public function down(): void
    {
    }
};
