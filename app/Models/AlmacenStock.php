<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * Existencia (saldo) de un producto en un almacén.
 *
 * CANTIDAD nunca debe editarse a mano: se mueve siempre vía
 * App\Services\InventarioService dentro de una transacción con bloqueo de fila.
 */
class AlmacenStock extends Model
{
    protected $table      = 'almacen_stock';
    protected $primaryKey = 'ID_STOCK';

    protected $fillable = [
        'ID_ALMACEN',
        'ID_PRODUCTO',
        'CANTIDAD',
        'CANTIDAD_MINIMA',
        'ULTIMA_ENTRADA',
        'ULTIMA_SALIDA',
        'FECHA_ULT_MOVIMIENTO',
    ];

    protected $casts = [
        'CANTIDAD'             => 'decimal:3',
        'CANTIDAD_MINIMA'      => 'decimal:3',
        'ULTIMA_ENTRADA'       => 'decimal:3',
        'ULTIMA_SALIDA'        => 'decimal:3',
        'FECHA_ULT_MOVIMIENTO' => 'datetime',
    ];

    public function almacen()
    {
        return $this->belongsTo(Almacen::class, 'ID_ALMACEN', 'ID_ALMACEN');
    }

    public function producto()
    {
        return $this->belongsTo(ProductoInventario::class, 'ID_PRODUCTO', 'ID_PRODUCTO');
    }

    /** Filas con umbral mínimo definido y saldo en o por debajo de él (alerta). */
    public function scopeStockBajo(Builder $q): Builder
    {
        return $q->whereNotNull('CANTIDAD_MINIMA')->whereColumn('CANTIDAD', '<=', 'CANTIDAD_MINIMA');
    }

    /** True si hay umbral mínimo definido y el saldo está en o por debajo de él. */
    public function getEsStockBajoAttribute(): bool
    {
        return $this->CANTIDAD_MINIMA !== null
            && (float) $this->CANTIDAD <= (float) $this->CANTIDAD_MINIMA;
    }
}
