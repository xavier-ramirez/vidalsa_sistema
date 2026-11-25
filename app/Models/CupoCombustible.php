<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** El cupo fijo mensual de gasoil de un patio; si cambia, rige desde el mes en que se guardó. */
class CupoCombustible extends Model
{
    public const PATIOS = ['TIGRE' => 'Patio El Tigre', 'MATURIN' => 'Patio Maturín'];

    protected $table = 'cupos_combustible';
    protected $primaryKey = 'ID_CUPO';

    protected $fillable = ['PATIO', 'MES', 'LITROS', 'ID_USUARIO'];

    protected $casts = ['MES' => 'date', 'LITROS' => 'decimal:2'];
}
