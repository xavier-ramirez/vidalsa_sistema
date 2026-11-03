<?php

namespace App\Models\Concerns;

use App\Models\FrenteTrabajo;

/**
 * Scope para los modelos con ID_FRENTE_ACTUAL (Equipo y EquipoAuxiliar), una sola copia para
 * los dos (los auxiliares de un frente vendido tampoco cuentan en la flota):
 *   · excludeFueraDeFlota(): quita los de frentes FUERA DE LA FLOTA (ESPECIAL, VENDIDO,
 *     TRÁMITE DE VENTA; FrenteTrabajo::TIPOS_FUERA_DE_FLOTA).
 * Deja los que no tienen frente. Columna cualificada con la tabla del modelo para evitar
 * ambigüedad en queries con JOINs.
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
