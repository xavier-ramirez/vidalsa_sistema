<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuministroOrigen extends Model
{
    protected $table      = 'suministros_origen';
    protected $primaryKey = 'ID_SUMINISTRO';

    protected $fillable = [
        'TIPO_COMBUSTIBLE',
        'CANTIDAD_TOTAL',
        'UNIDAD',
        'FECHA_LLEGADA',
        'ID_FRENTE',
        'PROVEEDOR',
        'NRO_GUIA',
        'NRO_CISTERNA',
        'NOTAS',
    ];

    protected $casts = [
        'FECHA_LLEGADA' => 'date',
        'CANTIDAD_TOTAL' => 'decimal:2',
    ];

    // ── Relaciones ───────────────────────────────────────────────

    public function frente()
    {
        return $this->belongsTo(FrenteTrabajo::class, 'ID_FRENTE', 'ID_FRENTE');
    }

    public function consumibles()
    {
        return $this->hasMany(Consumible::class, 'ID_SUMINISTRO', 'ID_SUMINISTRO');
    }

}
