<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enlaza a su ficha del catálogo las unidades que no tienen (o apuntan a una borrada) cuando
 * hay UNA sola ficha de su MODELO + año: la misma regla del enganche automático
 * (CaracteristicaModeloController::autoLinkEquiposToCatalogo), que solo corría al guardar
 * una ficha, así que las unidades registradas después se quedaban sueltas — sin foto del
 * modelo en la tabla de Equipos y fuera de la cuenta de su tarjeta en el catálogo.
 *
 * Con varias fichas del mismo modelo+año no se toca nada: no se puede saber cuál es la suya.
 * Idempotente: una unidad ya enlazada no cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        $unicas = DB::table('caracteristicas_modelo')
            ->select('MODELO', 'ANIO_ESPEC', DB::raw('MIN(ID_ESPEC) AS ID_ESPEC'))
            ->groupBy('MODELO', 'ANIO_ESPEC')
            ->havingRaw('COUNT(*) = 1')
            ->get();

        foreach ($unicas as $f) {
            DB::table('equipos')
                ->where('MODELO', $f->MODELO)->where('ANIO', $f->ANIO_ESPEC)
                ->where(fn ($q) => $q->whereNull('ID_ESPEC')->orWhereNotIn('ID_ESPEC', DB::table('caracteristicas_modelo')->select('ID_ESPEC')))
                ->update(['ID_ESPEC' => $f->ID_ESPEC]);
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío: no queda registro de qué unidades estaban sueltas, y
        // desenlazarlas solo volvería a esconderles la foto del modelo.
    }
};
