<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Punto (coordenada con nombre) de un oleoducto del módulo Mapa.
 */
class MapaOleoductoPunto extends Model
{
    protected $table = 'mapa_oleoducto_puntos';

    protected $fillable = ['nombre', 'latitud', 'longitud'];

    protected $casts = [
        'latitud'  => 'float',
        'longitud' => 'float',
    ];

    /**
     * Proyectos en los que está este punto. El mismo punto puede estar en varios: se guarda una
     * sola vez y se vincula a cada uno (con su propio 'orden' dentro de la línea de ese proyecto).
     */
    public function oleoductos()
    {
        return $this->belongsToMany(MapaOleoducto::class, 'mapa_oleoducto_punto_vinculos', 'punto_id', 'oleoducto_id')
            ->withPivot('orden')
            ->withTimestamps();
    }
}
