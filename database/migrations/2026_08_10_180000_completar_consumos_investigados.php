<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Completa el consumo diario de los tipos que faltaban.
 *
 * Valores confirmados por operaciones:
 *   · EXCAVADORA gigante (LOVOL FR420F, 42 t): 220 L/dia (las de 22 t quedan en 200).
 *   · CAMION ELEVADOR 8 y 12 TON: 60 L/dia.
 *   · CAMION CON BRAZO: 50 L/dia (el de 6 TON ya lo tenia).
 *
 * Valores investigados (jornada de 8 h, la misma base del resto de la proyeccion):
 *   · PLANTA_ELECTRICA: 0,15 L/kVA/h al 75% de carga -> KVA x 1,2 L/dia. Referencia:
 *     una planta de 100 kVA consume ~15 L/h al 75%. Se calcula por unidad segun su
 *     CAPACIDAD porque el parque va de 10 a 600 kVA: un valor unico seria 60 veces
 *     erroneo en los extremos.
 *   · VIBROCOMPACTADORA: 120 L/dia. Un rodillo de 16 t / 173 HP mide 19,7 L/h; el
 *     XCMG XS122 es de 12 t, escalado da ~14,8 L/h.
 *   · TRACTOR AGRICOLA: 100 L/dia. Un tractor de 120 CV a carga media consume 13,5 L/h;
 *     el parque mezcla 90 HP (TH904) y 150 HP (TR1504), asi que se toma el punto medio.
 *   · MANLIFT: 40 L/dia. Un telehandler medio (7-12 m) va de 4 a 10 L/h, y pasa buena
 *     parte del turno con la canasta arriba en ralenti.
 *
 * Solo rellena lo vacio, salvo el FR420F que corrige un valor ya puesto.
 */
return new class extends Migration
{
    /** TIPO de equipo => litros/dia. */
    private const CONSUMO_EQUIPOS = [
        'VIBROCOMPACTADORA'      => 120,
        'TRACTOR AGRICOLA'       => 100,
        'CAMION ELEVADOR 12 TON' =>  60,
        'CAMION ELEVADOR 8 TON'  =>  60,
        'CAMION CON BRAZO 4 TON' =>  50,
        'CAMION CON BRAZO 6 TON' =>  50,
    ];

    private const CONSUMO_FR420F = 220;
    private const CONSUMO_MANLIFT = 40;

    /** L/dia por cada kVA: 0,15 L/kVA/h al 75% de carga x 8 h de jornada. */
    private const LITROS_POR_KVA_DIA = 1.2;

    public function up(): void
    {
        foreach (self::CONSUMO_EQUIPOS as $tipo => $litros) {
            DB::table('equipos')
                ->whereNull('CONSUMO_PROMEDIO')->where('COMBUSTIBLE', 'GASOIL')
                ->whereIn('id_tipo_equipo', fn ($q) => $q->select('id')->from('tipo_equipos')->where('nombre', $tipo))
                ->update(['CONSUMO_PROMEDIO' => $litros]);
        }

        // FR420F: 42 t, casi el doble que la FR220D. Corrige el valor ya cargado.
        DB::table('equipos')
            ->where('MODELO', 'LIKE', 'FR420%')->where('COMBUSTIBLE', 'GASOIL')
            ->update(['CONSUMO_PROMEDIO' => self::CONSUMO_FR420F]);

        DB::table('equipos_auxiliares')
            ->whereNull('CONSUMO_PROMEDIO')->where('COMBUSTIBLE', 'GASOIL')
            ->where('TIPO', 'MANLIFT')->update(['CONSUMO_PROMEDIO' => self::CONSUMO_MANLIFT]);

        // --- Plantas electricas: una a una, segun los kVA de su CAPACIDAD ---
        $plantas = DB::table('equipos_auxiliares')
            ->select('ID_AUXILIAR', 'CAPACIDAD')
            ->whereNull('CONSUMO_PROMEDIO')->where('COMBUSTIBLE', 'GASOIL')
            ->where('TIPO', 'PLANTA_ELECTRICA')->get();

        foreach ($plantas as $p) {
            // "50 KVA", "60KVA", "225 KVA"... Sin CAPACIDAD legible se deja en NULL:
            // inventarle un valor a una planta cuyo tamaño no consta seria peor que
            // dejarla marcada como pendiente.
            if (!preg_match('/(\d+(?:[.,]\d+)?)/', (string) $p->CAPACIDAD, $m)) {
                continue;
            }
            $kva = (float) str_replace(',', '.', $m[1]);
            if ($kva <= 0) continue;

            DB::table('equipos_auxiliares')->where('ID_AUXILIAR', $p->ID_AUXILIAR)
                ->update(['CONSUMO_PROMEDIO' => round($kva * self::LITROS_POR_KVA_DIA, 2)]);
        }
    }

    public function down(): void
    {
        foreach (self::CONSUMO_EQUIPOS as $tipo => $litros) {
            DB::table('equipos')
                ->where('CONSUMO_PROMEDIO', $litros)
                ->whereIn('id_tipo_equipo', fn ($q) => $q->select('id')->from('tipo_equipos')->where('nombre', $tipo))
                ->update(['CONSUMO_PROMEDIO' => null]);
        }

        // El FR420F vuelve a los 200 que traia de la carga por tipo, no a NULL.
        DB::table('equipos')
            ->where('MODELO', 'LIKE', 'FR420%')
            ->where('CONSUMO_PROMEDIO', self::CONSUMO_FR420F)
            ->update(['CONSUMO_PROMEDIO' => 200]);

        DB::table('equipos_auxiliares')
            ->where('TIPO', 'MANLIFT')->where('CONSUMO_PROMEDIO', self::CONSUMO_MANLIFT)
            ->update(['CONSUMO_PROMEDIO' => null]);

        DB::table('equipos_auxiliares')
            ->where('TIPO', 'PLANTA_ELECTRICA')->update(['CONSUMO_PROMEDIO' => null]);
    }
};
