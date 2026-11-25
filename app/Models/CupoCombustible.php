<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** El cupo de gasoil de un patio en un mes, con las cisternas pedidas de él. */
class CupoCombustible extends Model
{
    public const PATIOS = ['TIGRE' => 'Patio El Tigre', 'MATURIN' => 'Patio Maturín'];

    protected $table = 'cupos_combustible';
    protected $primaryKey = 'ID_CUPO';

    protected $fillable = ['PATIO', 'MES', 'LITROS', 'ID_USUARIO'];

    protected $casts = ['MES' => 'date', 'LITROS' => 'decimal:2'];

    public function cisternas(): HasMany
    {
        return $this->hasMany(CisternaCupo::class, 'ID_CUPO', 'ID_CUPO')
            ->orderBy('FECHA')->orderBy('ID_CISTERNA');
    }
}
