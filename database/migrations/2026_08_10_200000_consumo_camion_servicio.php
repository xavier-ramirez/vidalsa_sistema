<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CAMION DE SERVICIO -> 50 L/dia, el mismo valor que el CAMION DE SOLDADURA.
 *
 * Tres de los cinco son SINOTRUK ZZ1168K621NC1: el MISMO chasis ZZ1168 del camion de
 * soldadura y de la ambulancia, con otro implemento. Los otros dos (FORD F-750 y
 * FREIGHTLINER) son mas grandes, pero operaciones fija un solo valor por tipo.
 *
 * El numero se escribe, no se lee del otro tipo: si manana cambia el consumo del camion
 * de soldadura, el de servicio no debe moverse solo detras.
 *
 * NOTA: estos 3 SINOTRUK son los que en su momento NO se pudieron cargar porque no
 * tenian ficha de catalogo (ID_ESPEC NULL). Ahora el consumo vive en `equipos`, asi que
 * ya no hace falta ficha para guardarlo.
 */
return new class extends Migration
{
    private const CONSUMO = 50;

    public function up(): void
    {
        DB::table('equipos')
            ->whereNull('CONSUMO_PROMEDIO')
            ->where('COMBUSTIBLE', 'GASOIL')
            ->whereIn('id_tipo_equipo', fn ($q) => $q->select('id')->from('tipo_equipos')->where('nombre', 'CAMION DE SERVICIO'))
            ->update(['CONSUMO_PROMEDIO' => self::CONSUMO]);
    }

    public function down(): void
    {
        DB::table('equipos')
            ->where('CONSUMO_PROMEDIO', self::CONSUMO)
            ->whereIn('id_tipo_equipo', fn ($q) => $q->select('id')->from('tipo_equipos')->where('nombre', 'CAMION DE SERVICIO'))
            ->update(['CONSUMO_PROMEDIO' => null]);
    }
};
