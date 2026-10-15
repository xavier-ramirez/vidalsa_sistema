<?php

namespace App\Models;

use App\Casts\MojibakeFix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class FrenteTrabajo extends Model
{
    /*
     * Borrado LÓGICO (deleted_at): "Eliminar" en /admin/frentes oculta el frente de todas las
     * listas y selectores (Eloquent lo filtra solo), pero la fila se queda para que el historial
     * (movilizaciones, movimientos de inventario, consumibles, traspasos) siga mostrando su
     * nombre. Las consultas con join a frentes_trabajo son de historial y lo siguen viendo.
     * NOMBRE_FRENTE es UNIQUE en la tabla: para volver a crear un frente con el nombre de uno
     * eliminado se REVIVE el eliminado (revivirPorNombre), con su historial.
     */
    use SoftDeletes;

    /** Activos ya vendidos. */
    public const TIPO_VENDIDO = 'VENDIDO';
    /** Activos en trámite de venta. */
    public const TIPO_TRAMITE_VENTA = 'TRAMITE_VENTA';

    /**
     * Tipos de frente (valor guardado => nombre en pantalla). Fuente única: la validación
     * (FrenteRequest) y las opciones del formulario de frentes salen de aquí.
     */
    public const TIPOS = [
        'OPERACION'              => 'OPERACION',
        'RESGUARDO'              => 'RESGUARDO',
        'ESPECIAL'               => 'ESPECIAL',
        self::TIPO_VENDIDO       => 'VENDIDO',
        self::TIPO_TRAMITE_VENTA => 'TRÁMITE DE VENTA',
    ];

    /**
     * Tipos de frente FUERA DE LA FLOTA: sus equipos no entran en los totales, las alertas
     * ni el listado general (solo se ven entrando al frente o buscándolos). ESPECIAL =
     * asignaciones especiales, no flota propia; VENDIDO y TRÁMITE DE VENTA = activos con los
     * que la empresa ya no cuenta. Punto ÚNICO: un tipo agregado aquí sale de todas las cuentas.
     */
    public const TIPOS_FUERA_DE_FLOTA = ['ESPECIAL', self::TIPO_VENDIDO, self::TIPO_TRAMITE_VENTA];

    protected $table = 'frentes_trabajo';
    protected $primaryKey = 'ID_FRENTE';

    /**
     * Invalida caches derivados al guardar/borrar un frente:
     * - `frentes_fuera_de_flota_ids` (leido por idsFueraDeFlota()).
     * - `frentes_activos_form`: selector de frente del formulario de alta de equipos
     *   (EquipoController::create lo cachea 1h). Un frente creado por una movilización
     *   a frente nuevo no aparecia ahi hasta que expiraba la cache.
     * - Generacion de la plantilla bulk (forzar regenerar el XLSX cacheado).
     * - Dashboard /menu (el modal de Recepción Directa lista los frentes activos).
     */
    protected static function booted(): void
    {
        $bust = static function () {
            Cache::forget('frentes_fuera_de_flota_ids');
            Cache::forget('frentes_activos_form');
            // Sube el contador que invalida todas las variantes de plantilla cacheadas.
            // Por CacheVersion (punto único del idiom add-or-increment): la primera vez
            // deja el contador en 1, igual que el `Cache::get(..., 1)` que lo lee, así
            // que el generador de la plantilla no nota ningún cambio.
            \App\Support\CacheVersion::bump('bulk_template_gen');
            \App\Http\Controllers\DashboardController::bumpDataVersion();
            // Los frentes viajan en el snapshot offline (dominio "catalogos"): al
            // cambiarlos hay que marcar esa version como obsoleta.
            \App\Support\OfflineVersion::invalidar('catalogos');
        };
        static::saved($bust);
        static::deleted($bust);
        static::restored($bust);
    }

    /**
     * Si hay un frente ELIMINADO con ese nombre (sin distinguir mayúsculas), lo revive y lo
     * devuelve; si no, null. Va antes de crear un frente por nombre: el índice UNIQUE de
     * NOMBRE_FRENTE también cuenta a los eliminados, así que crear otro con el mismo nombre
     * fallaría.
     */
    public static function revivirPorNombre(string $nombre): ?self
    {
        $frente = static::onlyTrashed()->whereRaw('UPPER(NOMBRE_FRENTE) = ?', [mb_strtoupper(trim($nombre))])->first();
        if ($frente) {
            $frente->restore();
        }

        return $frente;
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
     * Lo que Equipos muestra de un frente de venta: la etiqueta junto al nombre del frente
     * (filtro y columna Frente) y el aviso al entrar al frente. null si el frente no es de venta.
     */
    public function avisoVenta(): ?array
    {
        return match ($this->TIPO_FRENTE) {
            self::TIPO_VENDIDO => [
                'clase'    => 'vendido',
                'etiqueta' => 'VENDIDO',
                'titulo'   => 'Frente de activos vendidos',
                'texto'    => 'Estos equipos ya fueron vendidos: la empresa ya no cuenta con ellos y no entran en los totales de la flota.',
            ],
            self::TIPO_TRAMITE_VENTA => [
                'clase'    => 'tramite',
                'etiqueta' => 'EN TRÁMITE DE VENTA',
                'titulo'   => 'Frente en trámite de venta',
                'texto'    => 'Estos equipos están en trámite de venta: no entran en los totales de la flota.',
            ],
            default => null,
        };
    }

    /**
     * IDs de los frentes FUERA DE LA FLOTA (TIPOS_FUERA_DE_FLOTA: ESPECIAL y VENTA).
     * Cache 5 min; invalidado automáticamente en booted() al guardar/borrar un frente.
     */
    public static function idsFueraDeFlota(): array
    {
        return Cache::remember(
            'frentes_fuera_de_flota_ids', 300,
            fn() => static::whereIn('TIPO_FRENTE', self::TIPOS_FUERA_DE_FLOTA)->pluck('ID_FRENTE')->toArray()
        );
    }

    /**
     * True si el ID es de un frente fuera de la flota. Lo usan los listados para saber que
     * el usuario entró a ESE frente a propósito y no hay que excluirlo.
     */
    public static function esFueraDeFlota($id): bool
    {
        if ($id === null || $id === '' || $id === 'all') return false;
        return in_array((int) $id, array_map('intval', static::idsFueraDeFlota()), true);
    }
}
