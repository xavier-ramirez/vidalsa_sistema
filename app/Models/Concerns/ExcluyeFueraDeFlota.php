<?php

namespace App\Models\Concerns;

use App\Models\FrenteTrabajo;

/**
 * Scope excludeFueraDeFlota() para los modelos con ID_FRENTE_ACTUAL (Equipo y EquipoAuxiliar):
 * quita los que están en frentes FUERA DE LA FLOTA (ESPECIAL, VENDIDO, TRÁMITE DE VENTA; ver
 * FrenteTrabajo::TIPOS_FUERA_DE_FLOTA) y deja los que no tienen frente. Una sola copia para los
 * dos: los auxiliares de un frente vendido tampoco cuentan en la flota.
 * Columna cualificada con la tabla del modelo para evitar ambigüedad en queries con JOINs.
 * El caché de IDs vive en FrenteTrabajo::idsFueraDeFlota().
 */
trait ExcluyeFueraDeFlota
{
    public function scopeExcludeFueraDeFlota($query)
    {
        $ids = FrenteTrabajo::idsFueraDeFlota();
        if (empty($ids)) return $query;
        $col = $this->getTable() . '.ID_FRENTE_ACTUAL';
        return $query->where(function ($q) use ($ids, $col) {
            $q->whereNull($col)
              ->orWhereNotIn($col, $ids);
        });
    }
}
