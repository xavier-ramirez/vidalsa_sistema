<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una cisterna pedida del cupo de un patio: fecha, cuál cisterna y cuántos litros. */
class CisternaCupo extends Model
{
    protected $table = 'cupos_cisternas';
    protected $primaryKey = 'ID_CISTERNA';

    protected $fillable = ['ID_CUPO', 'FECHA', 'CISTERNA', 'LITROS', 'ID_USUARIO'];

    protected $casts = ['FECHA' => 'date', 'LITROS' => 'decimal:2'];

    public function cupo(): BelongsTo
    {
        return $this->belongsTo(CupoCombustible::class, 'ID_CUPO', 'ID_CUPO');
    }
}
