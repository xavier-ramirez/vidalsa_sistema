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
    use SoftDeletes;

    protected $table      = 'productos_inventario';
    protected $primaryKey = 'ID_PRODUCTO';

    protected $fillable = [
        'CODIGO',
        'NOMBRE',
        'UM',
        'CATEGORIA',
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

    public function creadoPor()
    {
        return $this->belongsTo(Usuario::class, 'CREADO_POR', 'ID_USUARIO');
    }

    // ── Scopes ───────────────────────────────────────────────────

    public function scopeActivos(Builder $q): Builder
    {
        return $q->where('ESTATUS', 'ACTIVO');
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
