<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaracteristicaModelo extends Model
{
    protected $table = 'caracteristicas_modelo';
    protected $primaryKey = 'ID_ESPEC';

    protected $fillable = [
        'MODELO',
        'TIPO',
        'ANIO_ESPEC',
        'MOTOR',
        'COMBUSTIBLE',
        'CONSUMO_PROMEDIO',
        'ACEITE_MOTOR',
        'ACEITE_CAJA',
        'LIGA_FRENO',
        'REFRIGERANTE',
        'TIPO_BATERIA',
        'FOTO_REFERENCIAL'
    ];

    public function equipos()
    {
        return $this->hasMany(Equipo::class, 'ID_ESPEC', 'ID_ESPEC');
    }

    /** Filtros que usa este modelo de equipo (compatibilidad/fitment). */
    public function filtros()
    {
        return $this->belongsToMany(ProductoInventario::class, 'modelo_filtro', 'ID_ESPEC', 'ID_PRODUCTO')
                    ->withPivot('CANTIDAD')
                    ->withTimestamps();
    }
}
