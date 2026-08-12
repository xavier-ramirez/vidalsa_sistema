<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EquipoAuxiliar extends Model
{
    use SoftDeletes;

    protected $table      = 'equipos_auxiliares';
    protected $primaryKey = 'ID_AUXILIAR';
    // deleted_at + deleted_by para papelera con auditoria de quien borro.

    protected $fillable = [
        'TIPO', 'MARCA', 'MODELO', 'SERIAL', 'CODIGO_INTERNO', 'CAPACIDAD',
        'ANIO', 'COMBUSTIBLE', 'CONSUMO_PROMEDIO',
        'ESTADO_OPERATIVO', 'ID_FRENTE_ACTUAL', 'CONFIRMADO_EN_SITIO', 'DETALLE_UBICACION_ACTUAL',
        'ID_EQUIPO_HOST',
        'FOTO', 'OBSERVACIONES', 'CREADO_POR',
        'LINK_DOC_PROPIEDAD', 'LINK_CERTIFICADO', 'FECHA_VENCIMIENTO_CERT',
        'deleted_by',
    ];

    protected $casts = [
        'ANIO' => 'integer',
        'CONFIRMADO_EN_SITIO' => 'integer',
        'FECHA_VENCIMIENTO_CERT' => 'date',
    ];

    public static function tiposLabel(): array
    {
        return [
            // MAQUINA_DE_SOLDAR (no MAQUINA_SOLDAR): es la clave que tienen los registros
            // reales y la que produce la normalización "uppercase + guiones bajos" de
            // EquipoAuxiliarController. Tenerla distinta aquí generaba DOS tipos para lo
            // mismo — el del datalist y el del texto normalizado.
            'MAQUINA_DE_SOLDAR' => 'Máquina de Soldar',
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
            'MAQUINA_DE_SOLDAR' => 'flash_on',
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
            'EN MANTENIMIENTO'=> 'En Mantenimiento',
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
