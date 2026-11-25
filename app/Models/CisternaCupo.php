<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una cisterna pedida del cupo de un patio: fecha, cuál cisterna y cuántos litros. */
class CisternaCupo extends Model
{
    protected $table = 'cupos_cisternas';
    protected $primaryKey = 'ID_CISTERNA';

    protected $fillable = ['PATIO', 'FECHA', 'CISTERNA', 'LITROS', 'ID_USUARIO'];

    protected $casts = ['FECHA' => 'date', 'LITROS' => 'decimal:2'];
}
