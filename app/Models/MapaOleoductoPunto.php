<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Punto (coordenada con nombre) de un oleoducto del módulo Mapa.
 */
class MapaOleoductoPunto extends Model
{
    protected $table = 'mapa_oleoducto_puntos';

    protected $fillable = ['oleoducto_id', 'nombre', 'latitud', 'longitud', 'orden'];

    protected $casts = [
        'latitud'  => 'float',
        'longitud' => 'float',
        'orden'    => 'integer',
    ];

    public function oleoducto()
    {
        return $this->belongsTo(MapaOleoducto::class, 'oleoducto_id');
    }
}
