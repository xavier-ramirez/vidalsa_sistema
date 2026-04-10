<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistroFalla extends Model
{
    protected $table = 'registros_fallas';
    protected $primaryKey = 'ID_FALLA';

    protected $fillable = [
        'ID_REPORTE',
        'ID_EQUIPO',
        'ID_USUARIO_REGISTRA',
        'HORA_REGISTRO',
        'TIPO_FALLA',
        'SISTEMA_AFECTADO',
        'DESCRIPCION_FALLA',
        'PRIORIDAD',
        'ESTADO_FALLA',
        'FECHA_RESOLUCION',
        'DESCRIPCION_RESOLUCION',
        'ID_SOLICITUD',
        'FOTO_EVIDENCIA',
    ];

    protected $casts = [
        'HORA_REGISTRO' => 'datetime',
        'FECHA_RESOLUCION' => 'datetime',
    ];

    /* ── Relationships ── */

    public function reporte()
    {
        return $this->belongsTo(ReporteDiario::class, 'ID_REPORTE', 'ID_REPORTE');
    }

    public function equipo()
    {
        return $this->belongsTo(Equipo::class, 'ID_EQUIPO', 'ID_EQUIPO');
    }

    public function usuarioRegistra()
    {
        return $this->belongsTo(Usuario::class, 'ID_USUARIO_REGISTRA', 'ID_USUARIO');
    }

    public function solicitud()
    {
        return $this->belongsTo(SolicitudMantenimiento::class, 'ID_SOLICITUD', 'ID_SOLICITUD');
    }

    public function materiales()
    {
        return $this->hasMany(MaterialRecomendadoFalla::class, 'ID_FALLA', 'ID_FALLA');
    }

    /* ── Scopes ── */

    public function scopeAbiertas($query)
    {
        return $query->where('ESTADO_FALLA', 'ABIERTA');
    }

    public function scopeResueltas($query)
    {
        return $query->where('ESTADO_FALLA', 'RESUELTA');
    }

    public function scopePrioridad($query, string $prioridad)
    {
        return $query->where('PRIORIDAD', $prioridad);
    }

    public function scopeDeEquipo($query, int $equipoId)
    {
        return $query->where('ID_EQUIPO', $equipoId);
    }
}
