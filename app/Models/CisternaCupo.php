<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una cisterna surtida del cupo de un patio: fecha y litros (lo que consume el cupo). */
class CisternaCupo extends Model
{
    protected $table = 'cupos_cisternas';
    protected $primaryKey = 'ID_CISTERNA';

    protected $fillable = ['PATIO', 'FECHA', 'LITROS', 'ID_USUARIO'];

    protected $casts = ['FECHA' => 'date', 'LITROS' => 'decimal:2'];
}
