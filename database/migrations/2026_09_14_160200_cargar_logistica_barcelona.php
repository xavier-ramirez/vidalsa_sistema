<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Logística con la que despacha el almacén BARCELONA (lista que mandó el cliente el
 * 14-09-2026). La placa A60EO9P va como está en la flota —con la letra O—: el mensaje la
 * traía con cero, pero es la misma Hilux del frente BARCELONA.
 *
 * Idempotente: si el almacén no existe no hace nada, y lo que ya esté cargado no se duplica.
 */
return new class extends Migration
{
    private const LOGISTICA = [
        ['CHOFER',   'LEOSEL POITO',           '17.902.185'],
        ['CHOFER',   'LUIS GONZÁLEZ',          '14.729.676'],
        ['CHOFER',   'EDUARDO ORTIZ',          '16.068.340'],
        ['VEHICULO', 'CAMIONETA TOYOTA HILUX', 'A05EC1G'],
        ['VEHICULO', 'CAMIONETA TOYOTA HILUX', 'A64BJ6P'],
        ['VEHICULO', 'CAMIONETA TOYOTA HILUX', 'A60EO9P'],
        ['VEHICULO', 'CAMIÓN FORD F-350',      'A11AT9F'],
    ];

    public function up(): void
    {
        $idAlmacen = DB::table('almacenes')->where('NOMBRE', 'BARCELONA')->whereNull('deleted_at')->value('ID_ALMACEN');
        if (!$idAlmacen) {
            return;
        }
        foreach (self::LOGISTICA as [$tipo, $nombre, $documento]) {
            // Misma normalización que AlmacenLogistica::clave (aquí escrita a mano: una
            // migración no debe depender de código de la app que puede cambiar después).
            $clave = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $documento));
            if ($tipo === 'CHOFER') {
                $clave = preg_replace('/\D/', '', $clave);
            }
            DB::table('almacen_logistica')->insertOrIgnore([
                'ID_ALMACEN' => $idAlmacen, 'TIPO' => $tipo, 'NOMBRE' => $nombre,
                'DOCUMENTO' => $documento, 'CLAVE' => $clave,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // La tabla la borra el down() de su propia migración; aquí no hay nada que deshacer
        // sin arriesgar filas que el almacén haya agregado después.
    }
};
