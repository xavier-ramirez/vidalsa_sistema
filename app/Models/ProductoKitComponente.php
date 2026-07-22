<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Renglón del BOM de un kit: una pieza (componente) que integra un producto
 * tipo KIT, con su cantidad y rol (primario/secundario/etc.). El stock vive en
 * la pieza (ID_PRODUCTO_COMPONENTE), no en el kit.
 */
class ProductoKitComponente extends Model
{
    protected $table      = 'producto_kit_componentes';
    protected $primaryKey = 'ID_KIT_COMPONENTE';

    protected $fillable = [
        'ID_PRODUCTO_KIT',
        'ID_PRODUCTO_COMPONENTE',
        'CANTIDAD',
        'ROL',
        'ORDEN',
    ];

    protected $casts = [
        'CANTIDAD' => 'integer',
        'ORDEN'    => 'integer',
    ];

    /** El producto que ES el kit. */
    public function kit()
    {
        return $this->belongsTo(ProductoInventario::class, 'ID_PRODUCTO_KIT', 'ID_PRODUCTO');
    }

    /** La pieza componente. */
    public function componente()
    {
        return $this->belongsTo(ProductoInventario::class, 'ID_PRODUCTO_COMPONENTE', 'ID_PRODUCTO');
    }
}
