<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una cisterna del cupo de gasoil de un patio. Empieza como solicitud (NUMERO y FECHA_PLAN, el plan de
 * despacho) y, cuando llega, lleva la fecha real de llegada (FECHA), los LITROS y su FOTO opcional.
 * Solo las que llegaron consumen el cupo.
 */
class CisternaCupo extends Model
{
    protected $table = 'cupos_cisternas';
    protected $primaryKey = 'ID_CISTERNA';

    protected $fillable = ['PATIO', 'NUMERO', 'FECHA_PLAN', 'FECHA', 'LITROS', 'FOTO', 'ID_USUARIO'];

    protected $casts = ['FECHA_PLAN' => 'date', 'FECHA' => 'date', 'LITROS' => 'decimal:2', 'NUMERO' => 'integer'];

    /** Llegó: tiene fecha de llegada y litros. */
    public function llego(): bool
    {
        return $this->FECHA !== null && $this->LITROS !== null;
    }

    /** El día que la ubica en un mes: la llegada; si no ha llegado, el plan. */
    public function dia(): ?\Illuminate\Support\Carbon
    {
        return $this->FECHA ?? $this->FECHA_PLAN;
    }
}
