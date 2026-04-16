<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Movilizacion extends Model
{
    use HasFactory;

    protected $table = 'movilizacion_historial';
    protected $primaryKey = 'ID_MOVILIZACION';

    protected $fillable = [
        'CODIGO_CONTROL',
        'ID_EQUIPO',
        'ID_FRENTE_ORIGEN',
        'ID_FRENTE_DESTINO',
        'DETALLE_UBICACION',       // Patio/Subdivisión específica de recepción
        'FECHA_DESPACHO',
        'ESTADO_MVO',              // TRANSITO, RECIBIDO
        'TIPO_MOVIMIENTO',         // DESPACHO, RECEPCION_DIRECTA
        'USUARIO_REGISTRO',
        'USUARIO_RECEPCION',       // Quién confirmó la recepción
    ];

    // Accessor for formatted CODIGO_CONTROL (MV-0000X)
    public function getFormattedCodigoControlAttribute()
    {
        if ($this->CODIGO_CONTROL === null) return 'R.D.'; // Recepción Directa, sin código
        $code = preg_replace('/[^0-9]/', '', $this->CODIGO_CONTROL);
        if (empty($code)) return $this->CODIGO_CONTROL;
        return 'MV-' . str_pad($code, 5, '0', STR_PAD_LEFT);
    }

    protected $casts = [
        'FECHA_DESPACHO' => 'datetime',
    ];

    public function equipo()
    {
        return $this->belongsTo(Equipo::class, 'ID_EQUIPO');
    }

    public function frenteOrigen()
    {
        return $this->belongsTo(FrenteTrabajo::class, 'ID_FRENTE_ORIGEN', 'ID_FRENTE');
    }

    public function frenteDestino()
    {
        return $this->belongsTo(FrenteTrabajo::class, 'ID_FRENTE_DESTINO', 'ID_FRENTE');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'USUARIO_REGISTRO', 'CORREO_ELECTRONICO');
    }
}
