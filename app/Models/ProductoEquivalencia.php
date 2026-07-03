<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Número de parte equivalente (cross-reference) de un filtro/producto.
 * Varias filas por producto = las distintas marcas del MISMO repuesto.
 * El buscador matchea por NUMERO_PARTE para caer siempre en el mismo producto.
 */
class ProductoEquivalencia extends Model
{
    protected $table      = 'producto_equivalencias';
    protected $primaryKey = 'ID_EQUIVALENCIA';

    protected $fillable = [
        'ID_PRODUCTO',
        'NUMERO_PARTE',
        'ES_PRINCIPAL',
    ];

    protected $casts = [
        'ES_PRINCIPAL' => 'boolean',
    ];

    public function producto()
    {
        return $this->belongsTo(ProductoInventario::class, 'ID_PRODUCTO', 'ID_PRODUCTO');
    }
}
