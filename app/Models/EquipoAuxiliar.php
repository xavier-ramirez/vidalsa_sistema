<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipoAuxiliar extends Model
{
    protected $table      = 'equipos_auxiliares';
    protected $primaryKey = 'ID_AUXILIAR';

    protected $fillable = [
        'TIPO', 'MARCA', 'MODELO', 'SERIAL', 'CODIGO_INTERNO', 'CAPACIDAD',
        'ANIO', 'ESTADO_OPERATIVO', 'ID_FRENTE_ACTUAL', 'ID_EQUIPO_HOST',
        'FOTO', 'OBSERVACIONES', 'CREADO_POR',
        'LINK_DOC_PROPIEDAD', 'NRO_DOC_PROPIEDAD', 'LINK_CERTIFICADO', 'FECHA_VENCIMIENTO_CERT',
    ];

    protected $casts = [
        'ANIO' => 'integer',
        'FECHA_VENCIMIENTO_CERT' => 'date',
    ];

    public static function tiposLabel(): array
    {
        return [
            'MAQUINA_SOLDAR'   => 'Máquina de Soldar',
            'LUMINARIA'        => 'Luminaria / Torre',
            'COMPRESOR'        => 'Compresor',
            'PLANTA_ELECTRICA' => 'Planta Eléctrica',
            'CONTAINER'        => 'Contenedor',
            'OTRO'             => 'Otro',
        ];
    }

    public static function tiposIcono(): array
    {
        return [
            'MAQUINA_SOLDAR'   => 'flash_on',
            'LUMINARIA'        => 'lightbulb',
            'COMPRESOR'        => 'compress',
            'PLANTA_ELECTRICA' => 'bolt',
            'CONTAINER'        => 'inventory_2',
            'OTRO'             => 'build',
        ];
    }

    public static function estadosLabel(): array
    {
        return [
            'OPERATIVO'      => 'Operativo',
            'INOPERATIVO'    => 'Inoperativo',
            'EN_ALMACEN'     => 'En Almacén',
            'DESINCORPORADO' => 'Desincorporado',
        ];
    }

    public const ANCHOR_MAX_PER_HOST = 2;

    public function frente()
    {
        return $this->belongsTo(FrenteTrabajo::class, 'ID_FRENTE_ACTUAL', 'ID_FRENTE');
    }

    public function equipoHost()
    {
        return $this->belongsTo(Equipo::class, 'ID_EQUIPO_HOST', 'ID_EQUIPO');
    }

    public function creador()
    {
        return $this->belongsTo(Usuario::class, 'CREADO_POR', 'ID_USUARIO');
    }
}
