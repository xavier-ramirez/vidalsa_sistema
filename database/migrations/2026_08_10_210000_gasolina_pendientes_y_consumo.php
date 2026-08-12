<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cierra el combustible de los vehiculos livianos que quedaban por definir y carga el
 * consumo de la flota a GASOLINA.
 *
 * PARTE 1 — Los pendientes a GASOLINA.
 * En Venezuela el parque liviano (pick-ups, SUV, sedanes) es de gasolina; el gasoil va a
 * carga pesada y maquinaria. La flota lo confirma sola: de los 132 vehiculos livianos ya
 * identificados —Hilux, Tacoma, RAV4, Corolla Cross, 4Runner, Fortuner, Roraima, Chery,
 * Silverado, Cheyenne y las F-350 Triton de chasis 8YT— los 132 son gasolina y NINGUNO
 * diesel. Los que faltaban (MAXUS T60, CHEVROLET LUV D-MAX, FORD RANGER y una F-350
 * importada) son de esa misma clase.
 *
 * NO se toca el MANLIFT: operaciones pidio expresamente dejarlo pendiente hasta ver si
 * es diesel o electrico.
 *
 * PARTE 2 — Consumo de la flota a gasolina.
 * Hasta ahora solo se habia cargado el gasoil, asi que la gasolina salia como "NO
 * CARGADO" en los reportes por frente. Los valores parten de los ya fijados para el
 * mismo tipo en gasoil y se suben ~30%: un motor de gasolina rinde menos que un diesel
 * en el mismo trabajo. Las F-350 de Venezuela son Triton V8 5.4 / V10 6.8, que a plena
 * carga hacen 4-5 km/L.
 */
return new class extends Migration
{
    /** Modelos livianos que quedaban por definir. El MANLIFT queda fuera a proposito. */
    private const MODELOS_GASOLINA = ['T60', 'LUV', 'LUD', 'RANGER', 'F-350'];

    /**
     * TIPO => litros/dia a gasolina. Referencia: el mismo tipo en gasoil, +30%.
     *   CAMIONETA 30 -> 35   ·   CAMION DE SOLDADURA 50 -> 65
     *   CUADRILLERA 60 -> 75 ·   AMBULANCIA 50 -> 65
     * AUTOMOVIL y MOTOCICLETA no existen en gasoil: van por su propio consumo.
     */
    private const CONSUMO_GASOLINA = [
        'CUADRILLERA'                  => 75,
        'CAMION DE SOLDADURA'          => 65,
        'AMBULANCIA'                   => 65,
        'CAMION PRUEBA HIDROSTATICA'   => 65,
        'CAMION'                       => 65,
        'CAMION PLATF/BARANDA'         => 65,
        'CAMION PLATF/ESTRUC/HIERRO'   => 65,
        'CAVA'                         => 65,
        'CAMIONETA'                    => 35,
        'AUTOMOVIL'                    => 25,
        'MOTOCICLETA'                  =>  4,
    ];

    public function up(): void
    {
        // --- 1) Pendientes -> GASOLINA (sin tocar el MANLIFT) ---
        DB::table('equipos')
            ->whereNull('COMBUSTIBLE')
            ->where(function ($q) {
                foreach (self::MODELOS_GASOLINA as $m) $q->orWhere('MODELO', 'LIKE', "%{$m}%");
            })
            ->whereNotIn('id_tipo_equipo', fn ($q) => $q->select('id')->from('tipo_equipos')->where('nombre', 'MANLIFT'))
            ->update(['COMBUSTIBLE' => 'GASOLINA']);

        // --- 2) Consumo de todo lo que quema gasolina ---
        foreach (self::CONSUMO_GASOLINA as $tipo => $litros) {
            DB::table('equipos')
                ->whereNull('CONSUMO_PROMEDIO')->where('COMBUSTIBLE', 'GASOLINA')
                ->whereIn('id_tipo_equipo', fn ($q) => $q->select('id')->from('tipo_equipos')->where('nombre', $tipo))
                ->update(['CONSUMO_PROMEDIO' => $litros]);
        }

        // El montacarga TOYOTA 8FGU25 es el unico auxiliar a gasolina.
        DB::table('equipos_auxiliares')
            ->whereNull('CONSUMO_PROMEDIO')->where('COMBUSTIBLE', 'GASOLINA')
            ->update(['CONSUMO_PROMEDIO' => 20]);
    }

    public function down(): void
    {
        foreach (self::CONSUMO_GASOLINA as $tipo => $litros) {
            DB::table('equipos')
                ->where('COMBUSTIBLE', 'GASOLINA')->where('CONSUMO_PROMEDIO', $litros)
                ->whereIn('id_tipo_equipo', fn ($q) => $q->select('id')->from('tipo_equipos')->where('nombre', $tipo))
                ->update(['CONSUMO_PROMEDIO' => null]);
        }

        DB::table('equipos_auxiliares')
            ->where('COMBUSTIBLE', 'GASOLINA')->where('CONSUMO_PROMEDIO', 20)
            ->update(['CONSUMO_PROMEDIO' => null]);

        // Devuelve a pendiente SOLO los modelos que esta migracion reclamo. Se excluye el
        // chasis 8YT: esas F-350 las clasifico la migracion 130000, no esta.
        DB::table('equipos')
            ->where('COMBUSTIBLE', 'GASOLINA')
            ->where(function ($q) {
                foreach (self::MODELOS_GASOLINA as $m) $q->orWhere('MODELO', 'LIKE', "%{$m}%");
            })
            ->where('SERIAL_CHASIS', 'NOT LIKE', '8YT%')
            ->update(['COMBUSTIBLE' => null]);
    }
};
