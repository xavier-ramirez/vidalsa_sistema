<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Equipo extends Model
{
    use SoftDeletes;

    protected $table = 'equipos';
    protected $primaryKey = 'ID_EQUIPO';
    // SoftDeletes filtra deleted_at != NULL automaticamente. deleted_by guarda
    // el ID_USUARIO que ejecuto el borrado (auditoria + papelera).

    /**
     * Valores válidos de `equipos.COMBUSTIBLE`. FUENTE ÚNICA: la usan la validación de
     * EquipoController::store()/update() y el desplegable del formulario del equipo.
     *
     * NO existe "DIESEL": es lo mismo que GASOIL, y tenerlos como dos opciones partía
     * en dos cualquier reporte por combustible. "NO APLICA" es para los remolques
     * (bateas, lowboys, camas bajas, taras), que no tienen motor.
     *
     * El combustible es dato de la UNIDAD, no del modelo: un mismo MODELO puede traer
     * motor a gasolina o a gasoil (HILUX 2.7 vs 2.4 diésel, F-350 Triton vs Power
     * Stroke). Por eso vive aquí y no en `caracteristicas_modelo`.
     */
    public const COMBUSTIBLES = ['GASOIL', 'GASOLINA', 'GAS', 'ELECTRICO', 'HIBRIDO', 'NO APLICA'];

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
        'CAPACIDAD',
        'ANIO',
        'COLOR',
        'ID_ESPEC',
        'SERIAL_CHASIS',
        'SERIAL_DE_MOTOR',
        'COMBUSTIBLE',
        'CONSUMO_PROMEDIO',
        'LINK_GPS',
        'FOTO_EQUIPO',
        'DETALLE_UBICACION_ACTUAL',
        'CONFIRMADO_EN_SITIO',
        'ESTADO_OPERATIVO',
        'CREADO_POR',
        'deleted_by',
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
     * CONSUMO_PROMEDIO es decimal(8,2), así que Eloquent lo devuelve como "200.00".
     * Esto lo deja en "200" (y en "12.5" si tiene decimales de verdad), que es como se
     * pinta en los formularios y en los modales de detalle.
     *
     * Es estático y vive aquí, junto a COMBUSTIBLES, porque lo usan las DOS tablas:
     * `equipos` y `equipos_auxiliares` tienen la misma columna y deben mostrarla igual.
     * Antes esta misma línea estaba copiada en 6 sitios.
     */
    public static function consumoFormateado($valor): ?string
    {
        if ($valor === null || $valor === '') return null;

        return rtrim(rtrim(number_format((float) $valor, 2, '.', ''), '0'), '.');
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

    /**
     * Alias semántico usado en vistas — incluye sub-relaciones necesarias.
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

    /** Reporte de falla ABIERTO del equipo (si existe). Un equipo INOPERATIVO
     *  siempre tiene uno: su estado lo gobierna el reporte hasta cerrarlo. Se
     *  eager-loadea en el listado para que el front abra el modal de cierre al
     *  instante (sin esperar el 409 de changeStatus). */
    public function fallaAbierta()
    {
        return $this->hasOne(\App\Models\Falla::class, 'ACTIVO_ID', 'ID_EQUIPO')
            ->where('ACTIVO_TIPO', 'equipo')
            ->where('ESTADO_REPORTE', 'abierto')
            ->latest('FECHA_EMISION');
    }

    public function movilizaciones()
    {
        return $this->hasMany(Movilizacion::class, 'ID_EQUIPO', 'ID_EQUIPO');
    }

    /** Equipos auxiliares anclados a este equipo (ej: maquinas de soldar en un
     *  camion de soldadura). El tope de 2 por equipo host NO esta en la DB como
     *  constraint; se enforza en EquipoAuxiliarController::anchor via
     *  lockForUpdate + count, y en validateData al crear/actualizar. */
    public function equiposAuxiliares()
    {
        return $this->hasMany(\App\Models\EquipoAuxiliar::class, 'ID_EQUIPO_HOST', 'ID_EQUIPO');
    }

    public function creador()
    {
        return $this->belongsTo(Usuario::class, 'CREADO_POR', 'ID_USUARIO');
    }

    /**
     * Payload del modal de detalles (window.equiposData). FUENTE ÚNICA: lo usa el
     * listado de /admin/equipos Y el panel de Alertas de /menu, para que el modal
     * muestre EXACTAMENTE los mismos campos sin importar desde dónde se abra (antes el
     * mapeo vivía inline en EquipoController y /menu quedaba con campos en "N/A").
     * Relaciones esperadas (eager-load para evitar N+1): tipo, frenteActual,
     * especificaciones, documentacion.seguro, ancladoA.(documentacion,tipo,especificaciones).
     */
    public function toDetailsPayload(): array
    {
        $foto = ($this->especificaciones && $this->especificaciones->FOTO_REFERENCIAL)
                ? $this->especificaciones->FOTO_REFERENCIAL
                : $this->FOTO_EQUIPO;

        return [
            'equipoId'        => $this->ID_EQUIPO,
            'codigo'          => $this->CODIGO_PATIO,
            'marca'           => $this->MARCA,
            'modelo'          => $this->MODELO,
            'anio'            => $this->ANIO,
            'tipo'            => $this->tipo->nombre ?? 'N/A',
            'categoria'       => $this->CATEGORIA_FLOTA,
            'ubicacion'       => optional($this->frenteActual)->NOMBRE_FRENTE ?? 'Sin Asignar',
            'motorSerial'     => $this->SERIAL_DE_MOTOR,
            'chasis'          => $this->SERIAL_CHASIS,
            // COMBUSTIBLE vive en `equipos`, NO en la ficha del modelo: un mismo
            // MODELO puede traer motor a gasolina o a gasoil segun la unidad.
            'combustible'     => $this->COMBUSTIBLE ?? 'N/A',
            'consumo'         => self::consumoFormateado($this->CONSUMO_PROMEDIO) ?? 'N/A',
            'placa'           => optional($this->documentacion)->PLACA ?? 'N/A',
            'titular'         => optional($this->documentacion)->NOMBRE_DEL_TITULAR ?? 'N/A',
            'nroDoc'          => optional($this->documentacion)->NRO_DE_DOCUMENTO ?? 'N/A',
            'vencSeguro'      => optional($this->documentacion)->FECHA_VENC_POLIZA ? \Carbon\Carbon::parse($this->documentacion->FECHA_VENC_POLIZA)->format('Y-m-d') : '',
            'seguro'          => optional(optional($this->documentacion)->seguro)->NOMBRE_ASEGURADORA ?? 'N/A',
            'linkPropiedad'   => optional($this->documentacion)->LINK_DOC_PROPIEDAD ?? '',
            'propiedadAutor'  => optional($this->documentacion)->PROPIEDAD_SUBIDO_POR ?? '',
            'propiedadFecha'  => optional($this->documentacion)->PROPIEDAD_FECHA_SUBIDA ? \Carbon\Carbon::parse($this->documentacion->PROPIEDAD_FECHA_SUBIDA)->format('d/m/y') : '',
            'linkSeguro'      => optional($this->documentacion)->LINK_POLIZA_SEGURO ?? '',
            'polizaAutor'     => optional($this->documentacion)->POLIZA_SUBIDO_POR ?? '',
            'polizaFecha'     => optional($this->documentacion)->POLIZA_FECHA_SUBIDA ? \Carbon\Carbon::parse($this->documentacion->POLIZA_FECHA_SUBIDA)->format('d/m/y') : '',
            'linkRotc'        => optional($this->documentacion)->LINK_ROTC ?? '',
            'fechaRotc'       => optional($this->documentacion)->FECHA_ROTC ? \Carbon\Carbon::parse($this->documentacion->FECHA_ROTC)->format('Y-m-d') : '',
            'rotcAutor'       => optional($this->documentacion)->ROTC_SUBIDO_POR ?? '',
            'rotcFecha'       => optional($this->documentacion)->ROTC_FECHA_SUBIDA ? \Carbon\Carbon::parse($this->documentacion->ROTC_FECHA_SUBIDA)->format('d/m/y') : '',
            'linkRacda'       => optional($this->documentacion)->LINK_RACDA ?? '',
            'fechaRacda'      => optional($this->documentacion)->FECHA_RACDA ? \Carbon\Carbon::parse($this->documentacion->FECHA_RACDA)->format('Y-m-d') : '',
            'racdaAutor'      => optional($this->documentacion)->RACDA_SUBIDO_POR ?? '',
            'racdaFecha'      => optional($this->documentacion)->RACDA_FECHA_SUBIDA ? \Carbon\Carbon::parse($this->documentacion->RACDA_FECHA_SUBIDA)->format('d/m/y') : '',
            'linkAdicional'   => optional($this->documentacion)->LINK_DOC_ADICIONAL ?? '',
            'fechaAdicional'  => optional($this->documentacion)->FECHA_ADICIONAL ? \Carbon\Carbon::parse($this->documentacion->FECHA_ADICIONAL)->format('Y-m-d') : '',
            'adicionalAutor'  => optional($this->documentacion)->ADICIONAL_SUBIDO_POR ?? '',
            'adicionalFecha'  => optional($this->documentacion)->ADICIONAL_FECHA_SUBIDA ? \Carbon\Carbon::parse($this->documentacion->ADICIONAL_FECHA_SUBIDA)->format('d/m/y') : '',
            'linkAdicional2'  => optional($this->documentacion)->LINK_DOC_ADICIONAL_2 ?? '',
            'fechaAdicional2' => optional($this->documentacion)->FECHA_ADICIONAL_2 ? \Carbon\Carbon::parse($this->documentacion->FECHA_ADICIONAL_2)->format('Y-m-d') : '',
            'adicional2Autor' => optional($this->documentacion)->ADICIONAL_2_SUBIDO_POR ?? '',
            'adicional2Fecha' => optional($this->documentacion)->ADICIONAL_2_FECHA_SUBIDA ? \Carbon\Carbon::parse($this->documentacion->ADICIONAL_2_FECHA_SUBIDA)->format('d/m/y') : '',
            'linkGps'         => $this->LINK_GPS ?? '',
            'frenteId'        => $this->ID_FRENTE_ACTUAL,
            'foto'            => $foto,
            'rolAnclaje'      => optional($this->tipo)->ROL_ANCLAJE ?? 'NEUTRO',
            'anchorId'        => $this->ID_ANCLAJE ?? '',
            'anchorCode'      => optional($this->ancladoA)->CODIGO_PATIO ?? '',
            'anchorRol'       => optional(optional($this->ancladoA)->tipo)->ROL_ANCLAJE ?? '',
            'anchorTipoNombre'=> optional(optional($this->ancladoA)->tipo)->nombre ?? 'Equipo',
            'anchorPlaca'     => optional(optional($this->ancladoA)->documentacion)->PLACA ?? '',
            'anchorSerial'    => optional($this->ancladoA)->SERIAL_CHASIS ?? '',
            'anchorMarca'     => optional($this->ancladoA)->MARCA ?? '',
            'anchorFoto'      => $this->ancladoA
                ? (optional(optional($this->ancladoA)->especificaciones)->FOTO_REFERENCIAL
                    ?? $this->ancladoA->FOTO_EQUIPO
                    ?? '')
                : '',
            'subCount'        => $this->equipos_auxiliares_count ?? 0,
            'detalleUbicacion'=> $this->DETALLE_UBICACION_ACTUAL ?? '',
        ];
    }
}

