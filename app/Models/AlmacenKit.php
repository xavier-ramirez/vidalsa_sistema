<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Kit del almacén: una receta guardada de materiales (almacen_kit_items) para uno o varios
 * modelos de equipo (almacen_kit_modelos). Sirve para cargar una salida de un golpe,
 * multiplicada por la cantidad de kits. NO tiene stock propio: el stock vive en cada
 * producto. La lógica (guardar, lo que alcanza, el catálogo para la pantalla) está en
 * App\Services\KitAlmacenService.
 */
class AlmacenKit extends Model
{
    protected $table      = 'almacen_kits';
    protected $primaryKey = 'ID_KIT';

    protected $fillable = ['NOMBRE', 'DESCRIPCION', 'CREADO_POR'];

    public function items()
    {
        return $this->hasMany(AlmacenKitItem::class, 'ID_KIT', 'ID_KIT')->orderBy('ORDEN')->orderBy('ID_KIT_ITEM');
    }

    public function modelos()
    {
        return $this->hasMany(AlmacenKitModelo::class, 'ID_KIT', 'ID_KIT');
    }
}
