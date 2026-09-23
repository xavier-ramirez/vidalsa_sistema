<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de equipo al que sirve un kit: una ficha del catálogo (ID_ESPEC) o un tipo de
 * auxiliar (AUX_TIPO/AUX_MARCA/AUX_MODELO). Mismo par que la compatibilidad de un producto
 * (modelo_filtro / auxiliar_filtro), para que un kit y sus productos hablen del mismo equipo.
 */
class AlmacenKitModelo extends Model
{
    protected $table      = 'almacen_kit_modelos';
    protected $primaryKey = 'ID_KIT_MODELO';

    protected $fillable = ['ID_KIT', 'ID_ESPEC', 'AUX_TIPO', 'AUX_MARCA', 'AUX_MODELO'];
}
