<?php

namespace App\Models;

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
        'ESTATUS',
        'NOTAS',
        'CREADO_POR',
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

    public function scopeCategoria(Builder $q, string $categoria): Builder
    {
        return $q->where('CATEGORIA', $categoria);
    }

    /** Búsqueda libre por código o nombre (usado por el endpoint JSON de productos). */
    public function scopeBuscar(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        return $q->where(function (Builder $sub) use ($term) {
            $sub->where('CODIGO', 'like', "%{$term}%")
                ->orWhere('NOMBRE', 'like', "%{$term}%");
        });
    }
}
