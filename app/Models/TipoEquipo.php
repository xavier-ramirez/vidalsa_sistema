<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class TipoEquipo extends Model
{
    protected $fillable = ['nombre', 'ROL_ANCLAJE'];

    /**
     * Invalida la plantilla bulk cuando se agregan/renombran tipos.
     */
    protected static function booted(): void
    {
        $bust = static function () {
            if (!Cache::has('bulk_template_gen')) {
                Cache::forever('bulk_template_gen', 1);
            } else {
                Cache::increment('bulk_template_gen');
            }
        };
        static::saved($bust);
        static::deleted($bust);
    }

    public function equipos()
    {
        return $this->hasMany(Equipo::class, 'id_tipo_equipo');
    }
}
