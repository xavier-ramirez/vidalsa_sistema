<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Documentacion extends Model
{
    protected $table = 'documentacion';
    protected $primaryKey = 'ID_EQUIPO';
    public $incrementing = false;

    protected $fillable = [
        'ID_EQUIPO',
        'NRO_DE_DOCUMENTO',
        'PLACA',
        'NOMBRE_DEL_TITULAR',
        'LINK_DOC_PROPIEDAD',
        'PROPIEDAD_SUBIDO_POR',
        'PROPIEDAD_FECHA_SUBIDA',
        'ID_SEGURO',
        'ESTADO_POLIZA',
        'FECHA_VENC_POLIZA',
        'LINK_POLIZA_SEGURO',
        'POLIZA_SUBIDO_POR',
        'POLIZA_FECHA_SUBIDA',
        'FECHA_ROTC',
        'LINK_ROTC',
        'ROTC_SUBIDO_POR',
        'ROTC_FECHA_SUBIDA',
        'FECHA_RACDA',
        'LINK_RACDA',
        'RACDA_SUBIDO_POR',
        'RACDA_FECHA_SUBIDA',
        'LINK_DOC_ADICIONAL',
        'ADICIONAL_SUBIDO_POR',
        'ADICIONAL_FECHA_SUBIDA',
        'FECHA_ADICIONAL',
        'LINK_DOC_ADICIONAL_2',
        'ADICIONAL_2_SUBIDO_POR',
        'ADICIONAL_2_FECHA_SUBIDA',
        'FECHA_ADICIONAL_2',

        // Management Tracking
        'poliza_gestion_frente_id',
        'poliza_gestion_fecha',
        'rotc_gestion_frente_id',
        'rotc_gestion_fecha',
        'racda_gestion_frente_id',
        'racda_gestion_fecha',
        

    ];

    protected $casts = [

        'POLIZA_FECHA_SUBIDA' => 'datetime',
        'ROTC_FECHA_SUBIDA' => 'datetime',
        'RACDA_FECHA_SUBIDA' => 'datetime',
        'PROPIEDAD_FECHA_SUBIDA' => 'datetime',
        'ADICIONAL_FECHA_SUBIDA' => 'datetime',
        'FECHA_ADICIONAL' => 'date',
        'ADICIONAL_2_FECHA_SUBIDA' => 'datetime',
        'FECHA_ADICIONAL_2' => 'date',
        'poliza_gestion_fecha' => 'datetime',
        'rotc_gestion_fecha' => 'datetime',
        'racda_gestion_fecha' => 'datetime',
    ];

    public function equipo()
    {
        return $this->belongsTo(Equipo::class, 'ID_EQUIPO', 'ID_EQUIPO');
    }

    public function seguro()
    {
        return $this->belongsTo(CatalogoSeguro::class, 'ID_SEGURO', 'ID_SEGURO');
    }

    // Relaciones con Usuarios que subieron documentos
    public function usuarioPropiedad()
    {
        return $this->belongsTo(Usuario::class, 'PROPIEDAD_SUBIDO_POR', 'ID_USUARIO');
    }

    public function usuarioPoliza()
    {
        return $this->belongsTo(Usuario::class, 'POLIZA_SUBIDO_POR', 'ID_USUARIO');
    }

    public function usuarioRotc()
    {
        return $this->belongsTo(Usuario::class, 'ROTC_SUBIDO_POR', 'ID_USUARIO');
    }

    public function usuarioRacda()
    {
        return $this->belongsTo(Usuario::class, 'RACDA_SUBIDO_POR', 'ID_USUARIO');
    }

    public function usuarioAdicional()
    {
        return $this->belongsTo(Usuario::class, 'ADICIONAL_SUBIDO_POR', 'ID_USUARIO');
    }

    public function usuarioAdicional2()
    {
        return $this->belongsTo(Usuario::class, 'ADICIONAL_2_SUBIDO_POR', 'ID_USUARIO');
    }

    // Management Relationships
    public function frenteGestionPoliza()
    {
        return $this->belongsTo(FrenteTrabajo::class, 'poliza_gestion_frente_id', 'ID_FRENTE');
    }

    public function frenteGestionRotc()
    {
        return $this->belongsTo(FrenteTrabajo::class, 'rotc_gestion_frente_id', 'ID_FRENTE');
    }

    public function frenteGestionRacda()
    {
        return $this->belongsTo(FrenteTrabajo::class, 'racda_gestion_frente_id', 'ID_FRENTE');
    }
    

}
