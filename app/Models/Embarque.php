<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un documento de embarque (Bill of Lading) y los equipos que ampara.
 *
 * Ver la migracion create_embarques_tables para el porque de la tabla aparte. Se escribe
 * SOLO desde la carga masiva (CargaMasivaDocumentos::aplicarEmbarque).
 */
class Embarque extends Model
{
    protected $table = 'embarques';
    protected $primaryKey = 'ID_EMBARQUE';

    protected $fillable = [
        'NRO_BL', 'BUQUE', 'PUERTO_CARGA', 'PUERTO_DESCARGA', 'FECHA_EMBARQUE',
        'LINK', 'ARCHIVO', 'UNIDADES', 'SUBIDO_POR',
    ];

    protected $casts = [
        'FECHA_EMBARQUE' => 'date',
    ];

    public function equipos()
    {
        return $this->belongsToMany(Equipo::class, 'embarque_equipo', 'ID_EMBARQUE', 'ID_EQUIPO')
                    ->withPivot('VIN', 'ASOCIADO_POR', 'created_at');
    }
}
