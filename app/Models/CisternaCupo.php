<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una cisterna surtida del cupo de un patio: fecha, litros (lo que consume el cupo) y su foto opcional. */
class CisternaCupo extends Model
{
    protected $table = 'cupos_cisternas';
    protected $primaryKey = 'ID_CISTERNA';

    protected $fillable = ['PATIO', 'FECHA', 'LITROS', 'FOTO', 'ID_USUARIO'];

    protected $casts = ['FECHA' => 'date', 'LITROS' => 'decimal:2'];
}
