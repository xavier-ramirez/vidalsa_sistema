<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ajustes de consumo pedidos por operaciones.
 *
 *  · VACUUM -> 35 L/dia. La foto del SGZ9400GXW muestra un semirremolque cisterna con
 *    un skid propio: motor diesel acoplado por correas a la bomba de vacio. TODO lo
 *    que sea vacuum trae su motor, sin importar la marca — por eso la regla va por TIPO
 *    y alcanza tambien a los REMORCA TSCVC2E. El chuto que lo hala sigue con sus 150
 *    L/dia aparte: son dos motores distintos y se suman.
 *
 *  · MANLIFT -> se le QUITA combustible y consumo (vuelve a pendiente). No consta si
 *    son diesel o electricos, y marcarlos a ciegas metia 360 L/dia inventados. Afecta a
 *    las DOS tablas: hay 8 en `equipos_auxiliares` y 1 en `equipos`, el mismo modelo
 *    HANGCHA HZ210A cargado en ambas.
 *
 *  · CAMION  HIDROJET -> 80 L/dia, el mismo valor que el CAMION CISTERNA (es el mismo
 *    chasis JAC HFC3252 con otro implemento). Se escribe el numero, no se lee del otro
 *    tipo: si manana cambia el consumo de la cisterna, el hidrojet no debe moverse solo.
 *    OJO: el nombre del tipo lleva DOBLE ESPACIO ('CAMION  HIDROJET') — asi esta en la
 *    tabla y asi hay que buscarlo.
 *
 *  · EXCAVADORA FR420F -> vuelve a 200 L/dia (operaciones baja los 220 que se habian
 *    puesto por su tamaño).
 */
return new class extends Migration
{
    private const CONSUMO_VACUUM   = 35;
    private const CONSUMO_HIDROJET = 80;
    private const CONSUMO_FR420F   = 200;

    /** Lo que tenia el MANLIFT antes de vaciarlo, para poder revertir. */
    private const MANLIFT_ANTERIOR = ['COMBUSTIBLE' => 'GASOIL', 'CONSUMO_PROMEDIO' => 40];

    public function up(): void
    {
        DB::table('equipos')
            ->whereIn('id_tipo_equipo', fn ($q) => $q->select('id')->from('tipo_equipos')->where('nombre', 'VACUUM'))
            ->update(['CONSUMO_PROMEDIO' => self::CONSUMO_VACUUM]);

        DB::table('equipos')
            ->whereIn('id_tipo_equipo', fn ($q) => $q->select('id')->from('tipo_equipos')->where('nombre', 'CAMION  HIDROJET'))
            ->update(['CONSUMO_PROMEDIO' => self::CONSUMO_HIDROJET]);

        DB::table('equipos')
            ->where('MODELO', 'LIKE', 'FR420%')
            ->update(['CONSUMO_PROMEDIO' => self::CONSUMO_FR420F]);

        // MANLIFT: a pendiente en las dos tablas.
        DB::table('equipos_auxiliares')->where('TIPO', 'MANLIFT')
            ->update(['COMBUSTIBLE' => null, 'CONSUMO_PROMEDIO' => null]);

        DB::table('equipos')
            ->whereIn('id_tipo_equipo', fn ($q) => $q->select('id')->from('tipo_equipos')->where('nombre', 'MANLIFT'))
            ->update(['COMBUSTIBLE' => null, 'CONSUMO_PROMEDIO' => null]);
    }

    public function down(): void
    {
        foreach ([['VACUUM', self::CONSUMO_VACUUM], ['CAMION  HIDROJET', self::CONSUMO_HIDROJET]] as [$tipo, $valor]) {
            DB::table('equipos')
                ->whereIn('id_tipo_equipo', fn ($q) => $q->select('id')->from('tipo_equipos')->where('nombre', $tipo))
                ->where('CONSUMO_PROMEDIO', $valor)
                ->update(['CONSUMO_PROMEDIO' => null]);
        }

        DB::table('equipos')->where('MODELO', 'LIKE', 'FR420%')
            ->where('CONSUMO_PROMEDIO', self::CONSUMO_FR420F)
            ->update(['CONSUMO_PROMEDIO' => 220]);

        DB::table('equipos_auxiliares')->where('TIPO', 'MANLIFT')
            ->whereNull('COMBUSTIBLE')->update(self::MANLIFT_ANTERIOR);

        DB::table('equipos')
            ->whereIn('id_tipo_equipo', fn ($q) => $q->select('id')->from('tipo_equipos')->where('nombre', 'MANLIFT'))
            ->whereNull('COMBUSTIBLE')->update(self::MANLIFT_ANTERIOR);
    }
};
