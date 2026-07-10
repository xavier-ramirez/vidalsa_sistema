<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class CatalogoSeguro extends Model
{
    protected $table = 'catalogo_seguros';
    protected $primaryKey = 'ID_SEGURO';

    protected $fillable = ['NOMBRE_ASEGURADORA'];

    /**
     * Invalida el selector de aseguradora del formulario de equipos (cacheado 1h en
     * EquipoController::create). Una aseguradora creada por CatalogoSeguro::firstOrCreate()
     * al guardar la poliza de un equipo no salia en la lista hasta que expiraba la cache.
     */
    protected static function booted(): void
    {
        $bust = static fn () => Cache::forget('seguros_list_form');
        static::saved($bust);
        static::deleted($bust);
    }

    public function documentaciones()
    {
        return $this->hasMany(Documentacion::class, 'ID_SEGURO', 'ID_SEGURO');
    }
}
