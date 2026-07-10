<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class TipoEquipo extends Model
{
    protected $fillable = ['nombre', 'ROL_ANCLAJE'];

    /**
     * Invalida la plantilla bulk y el selector "Tipo" del formulario de equipos
     * cuando se agregan/renombran tipos. Sin el forget de `tipos_equipo_list_form`,
     * un tipo creado por TipoEquipo::firstOrCreate() al registrar un equipo no salía
     * en el datalist hasta 1h después (EquipoController::create lo cachea a 3600s).
     */
    protected static function booted(): void
    {
        $bust = static function () {
            Cache::forget('tipos_equipo_list_form');
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
