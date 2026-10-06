<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Rastro de una corrección de cantidad en una Nota de Entrega ya emitida. La registra
 * App\Services\CorreccionNotaService junto con el cambio en el kardex.
 */
class CorreccionNota extends Model
{
    protected $table      = 'correcciones_nota';
    protected $primaryKey = 'ID_CORRECCION';

    protected $fillable = [
        'NUMERO_NOTA', 'ID_PRODUCTO', 'CANTIDAD_ANTES', 'CANTIDAD_DESPUES', 'MOTIVO', 'ID_USUARIO',
    ];

    protected $casts = [
        'CANTIDAD_ANTES'   => 'float',
        'CANTIDAD_DESPUES' => 'float',
    ];

    public function producto()
    {
        return $this->belongsTo(ProductoInventario::class, 'ID_PRODUCTO', 'ID_PRODUCTO');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'ID_USUARIO', 'ID_USUARIO');
    }

    /**
     * Lo que decía la nota ORIGINAL de cada producto corregido: el "antes" de su PRIMERA
     * corrección (las siguientes corrigen sobre la ya corregida). Recibe las correcciones de
     * UNA nota ya cargadas. [ID_PRODUCTO => cantidad].
     */
    public static function originales(Collection $correcciones): array
    {
        return $correcciones->sortBy('ID_CORRECCION')
            ->unique('ID_PRODUCTO')
            ->mapWithKeys(fn ($c) => [(int) $c->ID_PRODUCTO => (float) $c->CANTIDAD_ANTES])
            ->all();
    }

    /**
     * Qué productos de las notas de $movimientos tienen corrección, como
     * ["NUMERO_NOTA|ID_PRODUCTO" => true]. Una consulta por página del Historial, y ninguna
     * si la página no trae notas.
     */
    public static function corregidos(Collection $movimientos): array
    {
        $numeros = $movimientos->pluck('NUMERO_NOTA')->filter()->unique()->values();
        if ($numeros->isEmpty()) {
            return [];
        }
        return static::whereIn('NUMERO_NOTA', $numeros)
            ->get(['NUMERO_NOTA', 'ID_PRODUCTO'])
            ->mapWithKeys(fn ($c) => [$c->NUMERO_NOTA . '|' . $c->ID_PRODUCTO => true])
            ->all();
    }
}
