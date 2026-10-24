<?php

namespace App\Models;

use App\Casts\MojibakeFix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Producto del catálogo global de inventario.
 *
 * Columnas clave del inventario actual: CODIGO, PRODUCTO (NOMBRE), UM, CATEGORIA.
 * El stock por almacén vive en `almacen_stock`; los movimientos en
 * `movimientos_inventario`.
 */
class ProductoInventario extends Model
{
    /**
     * Snapshot offline de los kits (dominio "catalogos"): cada kit lleva el nombre de sus
     * productos (KitAlmacenService::catalogo), así que editar un producto lo deja viejo.
     */
    protected static function booted(): void
    {
        $marcar = static fn () => \App\Support\OfflineVersion::invalidar('catalogos');
        static::saved($marcar);
        static::deleted($marcar);
    }

    use SoftDeletes;

    protected $table      = 'productos_inventario';
    protected $primaryKey = 'ID_PRODUCTO';

    protected $fillable = [
        'CODIGO',
        'NOMBRE',
        'UM',
        'CATEGORIA',
        'UBICACION',
        // Enlace "/storage/google/<id>" a la foto del producto (ver AlmacenController::subirFotoProducto).
        'FOTO',
        'ESTATUS',
        'NOTAS',
        'CREADO_POR',
    ];

    /**
     * Auto-decode mojibake (UTF-8 doble-encoded) en los campos de texto. Datos
     * legacy importados desde Excel/CSV mal configurado guardan tildes como
     * "Ã"" (mojibake); el cast los devuelve correctos al leer.
     */
    protected $casts = [
        'NOMBRE'    => MojibakeFix::class,
        'CATEGORIA' => MojibakeFix::class,
        'UBICACION' => MojibakeFix::class,
        'NOTAS'     => MojibakeFix::class,
    ];

    // ── Relaciones ───────────────────────────────────────────────

    public function stock()
    {
        return $this->hasMany(AlmacenStock::class, 'ID_PRODUCTO', 'ID_PRODUCTO');
    }

    public function movimientos()
    {
        return $this->hasMany(MovimientoInventario::class, 'ID_PRODUCTO', 'ID_PRODUCTO');
    }

    /** Números de parte equivalentes (cross-reference) de este filtro/producto. */
    public function equivalencias()
    {
        return $this->hasMany(ProductoEquivalencia::class, 'ID_PRODUCTO', 'ID_PRODUCTO');
    }

    /** Modelos de equipo que usan este filtro (compatibilidad/fitment). */
    public function modelosCompatibles()
    {
        return $this->belongsToMany(CaracteristicaModelo::class, 'modelo_filtro', 'ID_PRODUCTO', 'ID_ESPEC')
                    ->withPivot('CANTIDAD')
                    ->withTimestamps();
    }

    public function creadoPor()
    {
        return $this->belongsTo(Usuario::class, 'CREADO_POR', 'ID_USUARIO');
    }

    // ── Scopes ───────────────────────────────────────────────────

    public function scopeActivos(Builder $q): Builder
    {
        return $q->where('ESTATUS', 'ACTIVO');
    }

    /**
     * El producto de un código ESCANEADO, con lo que necesita la pantalla para filtrar, o
     * null si no hay uno activo con ese CODIGO. Match EXACTO y solo activos (el índice UNIQUE
     * incluye soft-deleted, por eso se filtra). Fuente única del escaneo: la usan el lector
     * de la misma pantalla (AlmacenController::resolverPorCodigo) y el teléfono que manda el
     * código a la PC (LectorRemotoController::enviar).
     */
    public static function datosDeEscaneo(string $codigo): ?array
    {
        $p = static::activos()->where('CODIGO', $codigo)
            ->first(['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM', 'CATEGORIA', 'UBICACION']);
        if (!$p) return null;

        return [
            'id'        => $p->ID_PRODUCTO,
            'codigo'    => $p->CODIGO,
            'nombre'    => $p->NOMBRE,
            'um'        => $p->UM,
            'categoria' => $p->CATEGORIA,
            'ubicacion' => $p->UBICACION,
        ];
    }

    /** Lo que se le dice a quien escaneó un código que no está en el catálogo (o ya no está activo). */
    public static function avisoCodigoInexistente(string $codigo): string
    {
        return 'No existe un producto activo con el código ' . $codigo . '.';
    }

    /**
     * Lista de productos ACTIVOS para el AUTOCOMPLETE de descripciones. Fuente ÚNICA
     * reutilizada por el inventario (AlmacenController::index) y por recepción
     * (TraspasoController::nuevaEntrada) — así el buscador encuentra los FILTROS por su
     * número de parte. Cada producto trae:
     *   · EQUIV  = nºs de parte equivalentes unidos en un string (haystack de FuzzySearch)
     *   · PARTE  = nº de parte PRINCIPAL (default en la sugerencia)
     *   · PARTES = lista completa (la sugerencia muestra la que COINCIDE con lo buscado)
     * La relación equivalencias NO se envía al front (solo los campos derivados).
     */
    public static function listaAutocomplete(): \Illuminate\Support\Collection
    {
        return static::activos()
            ->with(['equivalencias' => fn ($q) => $q->orderByDesc('ES_PRINCIPAL')])
            ->orderBy('NOMBRE')->get(['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM', 'CATEGORIA'])
            ->map(function ($p) {
                $p->EQUIV  = $p->equivalencias->pluck('NUMERO_PARTE')->implode(' ');
                $p->PARTE  = (string) ($p->equivalencias->first()->NUMERO_PARTE ?? '');
                $p->PARTES = $p->equivalencias->pluck('NUMERO_PARTE')->values()->all();
                unset($p->equivalencias);
                return $p;
            });
    }
}
