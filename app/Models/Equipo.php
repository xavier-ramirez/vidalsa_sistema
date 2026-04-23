<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Equipo extends Model
{
    protected $table = 'equipos';
    protected $primaryKey = 'ID_EQUIPO';

    /**
     * Mass-assignment seguro. ID_FRENTE_ACTUAL y ID_ANCLAJE fueron removidos:
     * su mutacion debe pasar por flujos controlados (bulkStore de movilizacion,
     * bulkAnchor, recepcionDirecta) que validan permisos, scope LOCAL y hacen
     * lockForUpdate. Asignarlos directamente a la propiedad ($eq->ID_FRENTE_ACTUAL = X)
     * sigue funcionando; solo se bloquea $eq->fill($request->all()).
     */
    protected $fillable = [
        'id_tipo_equipo',
        'NUMERO_ETIQUETA',
        'CATEGORIA_FLOTA',
        'CODIGO_PATIO',
        'MARCA',
        'MODELO',
        'ANIO',
        'ID_ESPEC',
        'SERIAL_CHASIS',
        'SERIAL_DE_MOTOR',
        'LINK_GPS',
        'FOTO_EQUIPO',
        'DETALLE_UBICACION_ACTUAL',
        'CONFIRMADO_EN_SITIO',
        'ESTADO_OPERATIVO',
        'CREADO_POR',
    ];

    /**
     * Get the best available photo for the equipment.
     * Prioritizes the specific unit photo, falls back to the model catalog photo.
     */
    public function getFotoAttribute()
    {
        // Prioritize model catalog photo (requested look)
        if ($this->especificaciones && $this->especificaciones->FOTO_REFERENCIAL) {
            return asset($this->especificaciones->FOTO_REFERENCIAL);
        }

        // Fallback to specific unit photo
        if ($this->FOTO_EQUIPO) return asset($this->FOTO_EQUIPO);
        
        return null;
    }

    public function tipo()
    {
        return $this->belongsTo(TipoEquipo::class, 'id_tipo_equipo');
    }

    /**
     * Excluye equipos asignados a frentes TIPO_FRENTE=ESPECIAL (no son flota propia).
     * Mantiene equipos sin frente asignado (ID_FRENTE_ACTUAL IS NULL).
     * Columna cualificada con `equipos.` para evitar ambigüedad en queries con JOINs.
     * El cache de IDs vive en FrenteTrabajo::especialIds().
     */
    public function scopeExcludeEspecial($query)
    {
        $ids = FrenteTrabajo::especialIds();
        if (empty($ids)) return $query;
        $col = $this->getTable() . '.ID_FRENTE_ACTUAL';
        return $query->where(function ($q) use ($ids, $col) {
            $q->whereNull($col)
              ->orWhereNotIn($col, $ids);
        });
    }

    public function especificaciones()
    {
        return $this->belongsTo(CaracteristicaModelo::class, 'ID_ESPEC', 'ID_ESPEC');
    }
    public function frenteActual()
    {
        return $this->belongsTo(FrenteTrabajo::class, 'ID_FRENTE_ACTUAL', 'ID_FRENTE');
    }

    public function anclaje()
    {
        return $this->belongsTo(Equipo::class, 'ID_ANCLAJE', 'ID_EQUIPO');
    }

    /**
     * Alias semántico de anclaje() usado en vistas — incluye sub-relaciones necesarias.
     */
    public function ancladoA()
    {
        return $this->belongsTo(Equipo::class, 'ID_ANCLAJE', 'ID_EQUIPO')
                    ->with(['tipo', 'documentacion']);
    }

    public function equiposAnclados()
    {
        return $this->hasMany(Equipo::class, 'ID_ANCLAJE', 'ID_EQUIPO');
    }

    public function documentacion()
    {
        return $this->hasOne(Documentacion::class, 'ID_EQUIPO', 'ID_EQUIPO');
    }

    public function responsables()
    {
        return $this->hasMany(Responsable::class, 'ID_EQUIPO', 'ID_EQUIPO');
    }

    public function despachosCombustible()
    {
        return $this->hasMany(DespachoCombustible::class, 'ID_EQUIPO', 'ID_EQUIPO');
    }

    public function movilizaciones()
    {
        return $this->hasMany(MovilizacionHistorial::class, 'ID_EQUIPO', 'ID_EQUIPO');
    }

    public function solicitudesMantenimiento()
    {
        return $this->hasMany(SolicitudMantenimiento::class, 'ID_EQUIPO', 'ID_EQUIPO');
    }

    /** Sub-activos montados en este vehículo (máquinas de soldar, plantas, etc.) */
    public function subActivos()
    {
        return $this->hasMany(\App\Models\SubActivo::class, 'ID_EQUIPO_HOST', 'ID_EQUIPO');
    }

    public function creador()
    {
        return $this->belongsTo(Usuario::class, 'CREADO_POR', 'ID_USUARIO');
    }
}

