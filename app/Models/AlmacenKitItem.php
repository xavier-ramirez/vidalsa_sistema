<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Material de un kit: el producto y cuánto lleva CADA kit. */
class AlmacenKitItem extends Model
{
    protected $table      = 'almacen_kit_items';
    protected $primaryKey = 'ID_KIT_ITEM';

    protected $fillable = ['ID_KIT', 'ID_PRODUCTO', 'CANTIDAD', 'ORDEN'];

    protected $casts = [
        'CANTIDAD' => 'float',
        'ORDEN'    => 'integer',
    ];

    public function producto()
    {
        return $this->belongsTo(ProductoInventario::class, 'ID_PRODUCTO', 'ID_PRODUCTO');
    }
}
