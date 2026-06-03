<?php

namespace App\Models;

use App\Casts\MojibakeFix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class FrenteTrabajo extends Model
{
    protected $table = 'frentes_trabajo';
    protected $primaryKey = 'ID_FRENTE';

    /**
     * Invalida caches derivados al guardar/borrar un frente:
     * - `frentes_especial_ids` (leido por especialIds()).
     * - Generacion de la plantilla bulk (forzar regenerar el XLSX cacheado).
     */
    protected static function booted(): void
    {
        $bust = static function () {
            Cache::forget('frentes_especial_ids');
            // Incrementa el contador para invalidar todas las variantes de plantilla cacheadas.
            if (!Cache::has('bulk_template_gen')) {
                Cache::forever('bulk_template_gen', 1);
            } else {
                Cache::increment('bulk_template_gen');
            }
        };
        static::saved($bust);
        static::deleted($bust);
    }

    protected $fillable = [
        'ID_FRENTE',
        'NOMBRE_FRENTE',
        'UBICACION',
        'ZONA',
        'CONTRATOS',
        'TIPO_FRENTE',
        'ESTATUS_FRENTE',
        'SUBDIVISIONES',
        // Responsable 1 (Solicitado - Coord. Liviana con filtro FLOTA LIVIANA)
        'RESP_1_NOM', 'RESP_1_CAR', 'RESP_1_CED', 'RESP_1_EQU',
        // Responsable 2 (Solicitado alt - Coord. Pesada con filtro FLOTA PESADA)
        'RESP_2_NOM', 'RESP_2_CAR', 'RESP_2_CED', 'RESP_2_EQU',
        // Responsable 3 (Elaborado - Transporte y Logística, sin filtro EQU)
        'RESP_3_NOM', 'RESP_3_CAR', 'RESP_3_CED', 'RESP_3_EQU',
        // Responsable 4 (Revisado - Sub-gerente, sin filtro EQU)
        'RESP_4_NOM', 'RESP_4_CAR', 'RESP_4_CED', 'RESP_4_EQU',
        // Responsable 5 (Aprobado - Gerente, sin filtro EQU)
        'RESP_5_NOM', 'RESP_5_CAR', 'RESP_5_CED', 'RESP_5_EQU',
    ];

    /**
     * - CONTRATOS: JSON en BD → array PHP (ej: ["CTR-2026-0042", "CTR-2026-0099"]).
     * - NOMBRE_FRENTE / UBICACION: cast MojibakeFix auto-decodea UTF-8 doble-encoded
     *   ("ASIGNACIÃ"N UPATA" → "ASIGNACIÓN UPATA") al leer. Aplica solo si detecta
     *   el patron mojibake; strings limpios se devuelven intactos.
     */
    protected $casts = [
        'CONTRATOS'     => 'array',
        'NOMBRE_FRENTE' => MojibakeFix::class,
        'UBICACION'     => MojibakeFix::class,
        // ZONA: texto que sale en el renglón "Lugar, fecha" del Acta de Traslado.
        'ZONA'          => MojibakeFix::class,
    ];

    public function usuarios()
    {
        return $this->hasMany(Usuario::class, 'ID_FRENTE_ASIGNADO', 'ID_FRENTE');
    }

    public function equipos()
    {
        return $this->hasMany(Equipo::class, 'ID_FRENTE_ACTUAL', 'ID_FRENTE');
    }

    public function equiposAuxiliares()
    {
        return $this->hasMany(\App\Models\EquipoAuxiliar::class, 'ID_FRENTE_ACTUAL', 'ID_FRENTE');
    }

    public function despachoCombustible()
    {
        return $this->hasMany(DespachoCombustible::class, 'ID_FRENTE', 'ID_FRENTE');
    }

    public function movilizacionesOrigen()
    {
        return $this->hasMany(Movilizacion::class, 'ID_FRENTE_ORIGEN', 'ID_FRENTE');
    }

    public function movilizacionesDestino()
    {
        return $this->hasMany(Movilizacion::class, 'ID_FRENTE_DESTINO', 'ID_FRENTE');
    }

    /** Almacenes (de proyecto) asociados a este frente — pivote `almacen_frentes`. */
    public function almacenes()
    {
        return $this->belongsToMany(
            Almacen::class,
            'almacen_frentes',
            'ID_FRENTE',
            'ID_ALMACEN'
        )->withTimestamps();
    }

    /** Movimientos de inventario que tienen este frente como destino/consumo. */
    public function movimientosInventario()
    {
        return $this->hasMany(MovimientoInventario::class, 'ID_FRENTE', 'ID_FRENTE');
    }

    /**
     * IDs de frentes TIPO_FRENTE=ESPECIAL (asignaciones especiales, no flota propia).
     * Cache 5 min; invalidado automáticamente en booted() al guardar/borrar un frente.
     */
    public static function especialIds(): array
    {
        return Cache::remember(
            'frentes_especial_ids', 300,
            fn() => static::where('TIPO_FRENTE', 'ESPECIAL')->pluck('ID_FRENTE')->toArray()
        );
    }

    /**
     * True si el ID suministrado corresponde a un frente ESPECIAL.
     * Usado para detectar drill-down explícito y omitir la exclusión.
     */
    public static function isEspecialId($id): bool
    {
        if ($id === null || $id === '' || $id === 'all') return false;
        return in_array((int) $id, array_map('intval', static::especialIds()), true);
    }
}
