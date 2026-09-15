<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un chofer o un vehículo con el que despacha un almacén (ver la migración
 * create_almacen_logistica_table y LogisticaAlmacenService).
 */
class AlmacenLogistica extends Model
{
    public const TIPO_CHOFER   = 'CHOFER';
    public const TIPO_VEHICULO = 'VEHICULO';

    protected $table      = 'almacen_logistica';
    protected $primaryKey = 'ID_LOGISTICA';

    protected $fillable = ['ID_ALMACEN', 'TIPO', 'NOMBRE', 'DOCUMENTO', 'CLAVE', 'ULTIMO_USO'];

    protected $casts = ['ULTIMO_USO' => 'datetime'];

    /**
     * El documento sin puntos, guiones ni espacios, en mayúsculas: lo que hace iguales a dos
     * choferes o dos vehículos. En la cédula también se quita la letra ("V-17.902.185" =
     * "17902185"). La migración de BARCELONA repite esta regla a mano.
     */
    public static function clave(string $tipo, string $documento): string
    {
        $clave = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $documento));
        return $tipo === self::TIPO_CHOFER ? preg_replace('/\D/', '', $clave) : $clave;
    }
}
