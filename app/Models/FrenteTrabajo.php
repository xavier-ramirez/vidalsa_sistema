<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FrenteTrabajo extends Model
{
    protected $table = 'frentes_trabajo';
    protected $primaryKey = 'ID_FRENTE';

    protected $fillable = [
        'ID_FRENTE',
        'NOMBRE_FRENTE',
        'UBICACION',
        'TIPO_FRENTE',
        'ESTATUS_FRENTE',
        'SUBDIVISIONES',
        'RESP_1_NOM',
        'RESP_1_CAR',
        'RESP_1_CED',
        'RESP_1_EQU',
        'RESP_2_NOM',
        'RESP_2_CAR',
        'RESP_2_CED',
        'RESP_2_EQU',
        'RESP_3_NOM',
        'RESP_3_CAR',
        'RESP_3_CED',
        'RESP_3_EQU',
        'RESP_4_NOM',
        'RESP_4_CAR',
        'RESP_4_CED',
        'RESP_4_EQU',
    ];

    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'ID_FRENTE_ASIGNADO', 'ID_FRENTE');
    }

    public function equipos()
    {
        return $this->hasMany(Equipo::class, 'ID_FRENTE_ACTUAL', 'ID_FRENTE');
    }

    public function despachoCombustible()
    {
        return $this->hasMany(DespachoCombustible::class, 'ID_FRENTE', 'ID_FRENTE');
    }

    public function movilizacionesOrigen()
    {
        return $this->hasMany(MovilizacionHistorial::class, 'ID_FRENTE_ORIGEN', 'ID_FRENTE');
    }

    public function movilizacionesDestino()
    {
        return $this->hasMany(MovilizacionHistorial::class, 'ID_FRENTE_DESTINO', 'ID_FRENTE');
    }

    public function solicitudesMantenimiento()
    {
        return $this->hasMany(SolicitudMantenimiento::class, 'ID_FRENTE_ORIGEN', 'ID_FRENTE');
    }

    /** Reportes diarios de inoperatividad */
    public function reportesDiarios()
    {
        return $this->hasMany(ReporteDiario::class, 'ID_FRENTE', 'ID_FRENTE');
    }
}
