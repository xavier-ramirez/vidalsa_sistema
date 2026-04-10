<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaterialRecomendadoFalla extends Model
{
    protected $table = 'materiales_recomendados_falla';
    protected $primaryKey = 'ID_MATERIAL_REC';

    protected $fillable = [
        'ID_FALLA',
        'DESCRIPCION_MATERIAL',
        'ESPECIFICACION',
        'CANTIDAD',
        'UNIDAD',
        'FUENTE',
        'ID_ESPEC_ORIGEN',
        'CAMPO_ORIGEN',
    ];

    protected $casts = [
        'CANTIDAD' => 'decimal:2',
    ];

    public function falla()
    {
        return $this->belongsTo(RegistroFalla::class, 'ID_FALLA', 'ID_FALLA');
    }

    public function esAutoCatalogo(): bool
    {
        return $this->FUENTE === 'AUTO_CATALOGO';
    }
}
