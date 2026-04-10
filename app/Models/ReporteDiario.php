<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReporteDiario extends Model
{
    protected $table = 'reportes_diarios';
    protected $primaryKey = 'ID_REPORTE';

    protected $fillable = [
        'ID_FRENTE',
        'FECHA_REPORTE',
        'ESTADO_REPORTE',
        'CERRADO_POR',
        'FECHA_CIERRE',
        'OBSERVACIONES',
    ];

    protected $casts = [
        'FECHA_REPORTE' => 'date',
        'FECHA_CIERRE' => 'datetime',
    ];

    /* ── Relationships ── */

    public function frente()
    {
        return $this->belongsTo(FrenteTrabajo::class, 'ID_FRENTE', 'ID_FRENTE');
    }

    public function cerradoPor()
    {
        return $this->belongsTo(Usuario::class, 'CERRADO_POR', 'ID_USUARIO');
    }

    public function fallas()
    {
        return $this->hasMany(RegistroFalla::class, 'ID_REPORTE', 'ID_REPORTE');
    }

    /* ── Scopes ── */

    public function scopeAbiertos($query)
    {
        return $query->where('ESTADO_REPORTE', 'ABIERTO');
    }

    public function scopeCerrados($query)
    {
        return $query->where('ESTADO_REPORTE', 'CERRADO');
    }

    public function scopeFecha($query, $fecha)
    {
        return $query->whereDate('FECHA_REPORTE', $fecha);
    }

    /* ── Helpers ── */

    public function estaAbierto(): bool
    {
        return $this->ESTADO_REPORTE === 'ABIERTO';
    }

    public function totalFallasAbiertas(): int
    {
        return $this->fallas()->where('ESTADO_FALLA', 'ABIERTA')->count();
    }
}
