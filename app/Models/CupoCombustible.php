<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** El cupo mensual de gasoil de un patio desde un mes (rige hasta que se cambie). */
class CupoCombustible extends Model
{
    public const PATIOS = ['TIGRE' => 'Patio El Tigre', 'MATURIN' => 'Patio Maturín'];

    protected $table = 'cupos_combustible';
    protected $primaryKey = 'ID_CUPO';

    protected $fillable = ['PATIO', 'MES', 'LITROS', 'ID_USUARIO'];

    protected $casts = ['MES' => 'date', 'LITROS' => 'decimal:2'];
}
