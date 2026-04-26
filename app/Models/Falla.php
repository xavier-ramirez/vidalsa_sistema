<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reporte de fallas. Activo polimorfico (vehiculo o auxiliar) via
 * (ACTIVO_TIPO, ACTIVO_ID) — no morphTo: PK custom de las dos tablas
 * referenciadas (ID_EQUIPO / ID_AUXILIAR) hacen explicito el lookup.
 */
class Falla extends Model
{
    use SoftDeletes;

    protected $table      = 'fallas';
    protected $primaryKey = 'ID_FALLA';

    protected $fillable = [
        'CODIGO_REPORTE', 'FECHA_EMISION', 'TIPO_REPORTE', 'ESTADO_REPORTE',
        'ACTIVO_TIPO', 'ACTIVO_ID',
        'ESTADO_PREVIO', 'ESTADO_AL_CREAR', 'HOROMETRO_ACTUAL',
        'DESCRIPCION_AVERIA', 'SISTEMA_AFECTADO', 'PRIORIDAD',
        'TIPO_INTERVENCION', 'REPUESTOS_ESTIMADOS', 'OBSERVACIONES_MECANICO',
        'ID_USUARIO_REPORTA', 'NOMBRE_REPORTA', 'CARGO_REPORTA', 'EMAIL_REPORTA',
        'ID_USUARIO_CIERRA',  'NOMBRE_CIERRA',  'CARGO_CIERRA',
        'FECHA_CIERRE', 'OBSERVACIONES_CIERRE',
    ];

    protected $casts = [
        'FECHA_EMISION' => 'datetime',
        'FECHA_CIERRE'  => 'datetime',
    ];

    /**
     * Relacion al activo (Equipo o EquipoAuxiliar) segun ACTIVO_TIPO.
     * No es una relacion Eloquent: hidratamos manualmente. Patron usado
     * tambien por MovilizacionController para los aux sintetizados.
     */
    public function activo()
    {
        if ($this->ACTIVO_TIPO === 'equipo') {
            return Equipo::find($this->ACTIVO_ID);
        }
        if ($this->ACTIVO_TIPO === 'equipo_auxiliar') {
            return EquipoAuxiliar::find($this->ACTIVO_ID);
        }
        return null;
    }

    public function reporta()
    {
        return $this->belongsTo(Usuario::class, 'ID_USUARIO_REPORTA', 'ID_USUARIO');
    }

    public function cierra()
    {
        return $this->belongsTo(Usuario::class, 'ID_USUARIO_CIERRA', 'ID_USUARIO');
    }

    public static function sistemasAfectados(): array
    {
        return [
            'MOTOR'        => 'Motor',
            'HIDRAULICO'   => 'Hidráulico',
            'ELECTRICO'    => 'Eléctrico',
            'NEUMATICO'    => 'Neumático',
            'TRANSMISION'  => 'Transmisión',
            'ESTRUCTURAL'  => 'Estructural',
            'FRENOS'       => 'Frenos',
            'OTROS'        => 'Otros',
        ];
    }

    public static function prioridades(): array
    {
        return [
            'CRITICA' => 'Crítica',
            'ALTA'    => 'Alta',
            'MEDIA'   => 'Media',
            'BAJA'    => 'Baja',
        ];
    }

    public static function tiposIntervencion(): array
    {
        return [
            'CORRECTIVO_INMEDIATO' => 'Correctivo Inmediato',
            'PROGRAMADO'           => 'Programado',
        ];
    }
}
