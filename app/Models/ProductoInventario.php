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
     * Snapshot offline: este modelo forma parte del dominio "almacen", asi que al
     * escribirlo hay que marcar esa version como obsoleta para que los clientes
     * que SI cachean ese dominio se traigan el cambio. Los que no lo cachean ni
     * se enteran, que es justo el objetivo de separar las versiones.
     */
    protected static function booted(): void
    {
        $marcar = static fn () => \App\Support\OfflineVersion::invalidar();
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
        'ES_KIT',
        'UBICACION',
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
        'ES_KIT'    => 'boolean',
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

    /**
     * Piezas (componentes) que integran este producto cuando es un KIT (BOM).
     * El stock vive en cada pieza, no en el kit. Auto-referencia kit → componentes.
     */
    public function componentes()
    {
        return $this->belongsToMany(self::class, 'producto_kit_componentes', 'ID_PRODUCTO_KIT', 'ID_PRODUCTO_COMPONENTE')
                    ->withPivot(['CANTIDAD', 'ROL', 'ORDEN'])
                    ->withTimestamps()
                    ->orderBy('producto_kit_componentes.ORDEN');
    }

    /**
     * Kits que incluyen este producto como componente (relación inversa): "¿de
     * qué juegos forma parte esta pieza?".
     */
    public function kitsQueLoIncluyen()
    {
        return $this->belongsToMany(self::class, 'producto_kit_componentes', 'ID_PRODUCTO_COMPONENTE', 'ID_PRODUCTO_KIT')
                    ->withPivot(['CANTIDAD', 'ROL', 'ORDEN'])
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

    /** Solo productos tipo KIT (juegos). */
    public function scopeKits(Builder $q): Builder
    {
        return $q->where('ES_KIT', true);
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

    // ── Accessors ────────────────────────────────────────────────

    /**
     * Contenido que se codifica en el QR de la etiqueta del producto y que
     * resuelve AlmacenController::resolverPorCodigo al escanear: el CODIGO del
     * catálogo (índice UNIQUE, identificador de negocio — el equivalente al código
     * de barras de un producto de supermercado). Centralizado aquí para que la
     * impresión (etiquetasPdf) y el escaneo usen EXACTAMENTE el mismo valor y nunca
     * se desincronicen. NO es una columna nueva: el QR es dato derivado del CODIGO.
     */
    public function getQrPayloadAttribute(): string
    {
        return (string) ($this->CODIGO ?? '');
    }
}
