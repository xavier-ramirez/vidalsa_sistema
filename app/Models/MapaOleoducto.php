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

    protected $fillable = ['id_frente', 'suelto', 'nombre', 'color', 'descripcion', 'creado_por', 'recorrido'];

    // Recorrido dibujado a mano (array de [lat, lng]) que le da forma de tubería a la línea.
    // suelto: grupo reservado de las ubicaciones SIN proyecto (uno solo, sin frente ni línea).
    protected $casts = [
        'recorrido' => 'array',
        'suelto'    => 'boolean',
    ];

    /**
     * Puntos del proyecto. Es N:N: un mismo punto puede estar en VARIOS proyectos (un sitio
     * compartido por dos frentes se guarda UNA vez y se vincula a los dos). El ORDEN con el que
     * se une la línea vive en el vínculo, porque el mismo punto puede ir en distinta posición
     * en cada proyecto.
     */
    public function puntos()
    {
        return $this->belongsToMany(MapaOleoductoPunto::class, 'mapa_oleoducto_punto_vinculos', 'oleoducto_id', 'punto_id')
            ->withPivot('orden')
            ->withTimestamps()
            ->orderBy('mapa_oleoducto_punto_vinculos.orden');
    }
}
