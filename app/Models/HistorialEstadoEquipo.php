<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistorialEstadoEquipo extends Model
{
    protected $table = 'historial_estado_equipo';
    protected $primaryKey = 'ID_HISTORIAL';

    protected $fillable = [
        'ID_EQUIPO',
        'ESTADO_ANTERIOR',
        'ESTADO_NUEVO',
        'ID_USUARIO',
        'ID_FALLA',
        'MOTIVO',
    ];

    public function equipo()
    {
        return $this->belongsTo(Equipo::class, 'ID_EQUIPO', 'ID_EQUIPO');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'ID_USUARIO', 'ID_USUARIO');
    }

    public function falla()
    {
        return $this->belongsTo(RegistroFalla::class, 'ID_FALLA', 'ID_FALLA');
    }
}
