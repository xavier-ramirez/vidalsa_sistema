<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Oleoducto (proyecto de tendido) del módulo Mapa. Tiene VARIOS puntos que, unidos en
 * orden, dibujan la línea del oleoducto sobre el mapa.
 */
class MapaOleoducto extends Model
{
    protected $table = 'mapa_oleoductos';

    protected $fillable = ['id_frente', 'nombre', 'color', 'descripcion', 'creado_por', 'recorrido'];

    // Recorrido dibujado a mano (array de [lat, lng]) que le da forma de tubería a la línea.
    protected $casts = [
        'recorrido' => 'array',
    ];

    public function puntos()
    {
        return $this->hasMany(MapaOleoductoPunto::class, 'oleoducto_id')->orderBy('orden');
    }
}
