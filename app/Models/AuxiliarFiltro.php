<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Vínculo de compatibilidad (fitment) entre un FILTRO y un modelo de AUXILIAR
 * (generador, soldadora, compresor…). El auxiliar se identifica por
 * TIPO + MARCA + MODELO porque `equipos_auxiliares` no tiene catálogo de modelos.
 */
class AuxiliarFiltro extends Model
{
    protected $table      = 'auxiliar_filtro';
    protected $primaryKey = 'ID_AUX_FILTRO';

    protected $fillable = [
        'ID_PRODUCTO',
        'TIPO',
        'MARCA',
        'MODELO',
        'CANTIDAD',
        'ETAPA',
    ];

    protected $casts = [
        'CANTIDAD' => 'integer',
    ];

    public function filtro()
    {
        return $this->belongsTo(ProductoInventario::class, 'ID_PRODUCTO', 'ID_PRODUCTO');
    }
}
