<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Vínculo de compatibilidad (fitment): un MODELO de equipo usa un FILTRO, con
 * la cantidad por servicio. Fuente para "qué filtros usa un equipo" y "qué
 * equipos usan un filtro", y para sugerir filtros en la nota de entrega.
 */
class ModeloFiltro extends Model
{
    protected $table      = 'modelo_filtro';
    protected $primaryKey = 'ID_MODELO_FILTRO';

    protected $fillable = [
        'ID_ESPEC',
        'ID_PRODUCTO',
        'CANTIDAD',
        'ETAPA',
    ];

    protected $casts = [
        'CANTIDAD' => 'integer',
    ];

    public function modelo()
    {
        return $this->belongsTo(CaracteristicaModelo::class, 'ID_ESPEC', 'ID_ESPEC');
    }

    public function filtro()
    {
        return $this->belongsTo(ProductoInventario::class, 'ID_PRODUCTO', 'ID_PRODUCTO');
    }
}
