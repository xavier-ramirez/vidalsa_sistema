<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Carga inicial del consumo diario (L/dia) por TIPO de equipo.
 *
 * Los valores salen de la proyeccion del frente TUBERIA DE 12'' AGUA SALADA, que es el
 * unico dato de consumo que la empresa tiene hoy. Son un PUNTO DE PARTIDA, no una
 * medicion: el consumo real por unidad se sabra cuando se registren los despachos con
 * lectura de horometro (tabla `despacho_combustible`, hoy en cero).
 *
 * Solo rellena lo que este vacio (whereNull), asi que no pisa ningun ajuste manual y se
 * puede correr mas de una vez sin dano.
 *
 * NO se cargan aqui, a proposito:
 *   · Los remolques (bateas, lowboys, camas bajas): no tienen motor. Su COMBUSTIBLE ya
 *     quedo en 'NO APLICA' y su consumo debe seguir en NULL, no en 0.
 *   · Los vehiculos a GASOLINA: la proyeccion que se esta armando es de GASOIL. Sus
 *     litros son de otro combustible y mezclarlos falsearia el total.
 *   · MINI EXCAVADORAS y TRACTOR AGRICOLA: son clases mucho mas chicas que sus tipos
 *     hermanos; ponerles el valor del grande seria inflar la proyeccion.
 */
return new class extends Migration
{
    /** TIPO de equipo => litros/dia. Fuente: proyeccion frente TUBERIA 12'' AGUA SALADA. */
    private const CONSUMO_POR_TIPO = [
        'EXCAVADORA'             => 200,  // incluye las que trabajan con martillo percutor
        'TRACTOR DE ORUGA'       => 200,  // clase SD22 / D7
        'PAYLOADER'              => 200,
        'SIDEBOOM'               => 150,
        'CHUTO'                  => 150,
        'MOTONIVELADORA'         => 150,
        'RETROEXCAVADORA'        => 100,
        'VOLTEO'                 => 100,
        'CAMION GRUA'            => 100,
        'CAMION CISTERNA'        =>  80,
        'CHUTO CON BRAZO 16 TON' =>  80,
        'AUTOBUS'                =>  80,
        'CAMION PLATAFORMA'      =>  60,
        'CUADRILLERA'            =>  60,
        'CAMION DE SOLDADURA'    =>  50,
        'AMBULANCIA'             =>  50,
        'CAMION CON BRAZO 6 TON' =>  50,
        'LOWBOY'                 =>  50,  // el chuto que lo hala; el remolque queda en NULL
        'CAMIONETA'              =>  30,
    ];

    public function up(): void
    {
        foreach (self::CONSUMO_POR_TIPO as $tipo => $litros) {
            DB::table('equipos')
                ->whereNull('CONSUMO_PROMEDIO')
                // Solo los que queman gasoil: la proyeccion es de gasoil, y los remolques
                // ('NO APLICA') y los de gasolina no deben sumar litros a ese total.
                ->where('COMBUSTIBLE', 'GASOIL')
                ->whereIn('id_tipo_equipo', function ($q) use ($tipo) {
                    $q->select('id')->from('tipo_equipos')->where('nombre', $tipo);
                })
                ->update(['CONSUMO_PROMEDIO' => $litros]);
        }
    }

    public function down(): void
    {
        // Vacia solo los tipos que esta migracion cargo, y solo si conservan el valor
        // exacto que se les puso: si alguien lo ajusto a mano, ese ajuste se respeta.
        foreach (self::CONSUMO_POR_TIPO as $tipo => $litros) {
            DB::table('equipos')
                ->where('CONSUMO_PROMEDIO', $litros)
                ->whereIn('id_tipo_equipo', function ($q) use ($tipo) {
                    $q->select('id')->from('tipo_equipos')->where('nombre', $tipo);
                })
                ->update(['CONSUMO_PROMEDIO' => null]);
        }
    }
};
