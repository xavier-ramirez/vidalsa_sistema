<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Afina el combustible de los equipos que la migracion anterior dejo sin clasificar
 * (56) porque su MODELO existe en version gasolina Y diesel.
 *
 * Cada regla de aqui esta respaldada, no adivinada:
 *   · SINOTRUK y TOYOTA: confirmado por operaciones sobre la flota real.
 *   · JAC GALLOP: es el HFC1134KR1, motor Cummins de 230 HP diesel.
 *   · FORD F-350 con chasis 8YT: 8YT = Ford Motor de Venezuela (planta Valencia).
 *     La F-350 ensamblada alli es la "Triton" con V8 5.4L / V10 6.8L a GASOLINA y dos
 *     tanques de 151 L de gasolina sin plomo; esa planta nunca monto Power Stroke.
 *     Las de chasis 1FD (importadas de EE.UU.) SI pueden ser diesel: quedan pendientes.
 *   · FORD F-750 (chasis 3FR, Ford Mexico medium duty): diesel.
 *   · CAT 977L: cargador de orugas. Estaba mal tipificado como MINI SHOWER.
 *
 * Todas las reglas van con whereNull('COMBUSTIBLE'): solo rellenan lo pendiente y
 * NUNCA pisan lo ya clasificado — asi los remolques SINOTRUK (lowboys) conservan su
 * 'NO APLICA' en vez de volverse GASOIL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Marcas completas, confirmadas por operaciones ---
        DB::table('equipos')->whereNull('COMBUSTIBLE')
            ->where('MARCA', 'SINOTRUK')->update(['COMBUSTIBLE' => 'GASOIL']);

        DB::table('equipos')->whereNull('COMBUSTIBLE')
            ->where('MARCA', 'TOYOTA')->update(['COMBUSTIBLE' => 'GASOLINA']);

        // --- Modelos verificados uno a uno ---
        DB::table('equipos')->whereNull('COMBUSTIBLE')
            ->where('MODELO', 'LIKE', '%GALLOP%')->update(['COMBUSTIBLE' => 'GASOIL']);

        DB::table('equipos')->whereNull('COMBUSTIBLE')
            ->where('MODELO', 'LIKE', '%977L%')->update(['COMBUSTIBLE' => 'GASOIL']);

        DB::table('equipos')->whereNull('COMBUSTIBLE')
            ->where('MODELO', 'LIKE', '%F-750%')->update(['COMBUSTIBLE' => 'GASOIL']);

        // --- F-350: decide el chasis, no el modelo ---
        DB::table('equipos')->whereNull('COMBUSTIBLE')
            ->where('MODELO', 'LIKE', '%F-350%')
            ->where('SERIAL_CHASIS', 'LIKE', '8YT%')
            ->update(['COMBUSTIBLE' => 'GASOLINA']);

        // Quedan a proposito sin clasificar, porque el modelo NO determina el motor y
        // hay que verlo en patio:
        //   · FORD F-350 chasis 1FD  — importada de EE.UU., puede ser Power Stroke
        //   · FORD RANGER chasis 8AF — Ford Brasil, existe en gasolina y en diesel
        //   · CHEVROLET LUV D-MAX    — 2.4 gasolina o 3.0 diesel
        //   · MAXUS T60              — Venezuela vende Comfort 2.4 Mitsubishi GASOLINA
        //                              y Comfort 4x4 2.0 Turbo DIESEL, mismo nombre
    }

    public function down(): void
    {
        // Devuelve a pendiente SOLO los modelos que ESTA migracion reclamo, no marcas
        // enteras. Revertir por MARCA seria destructivo: de los 121 TOYOTA solo 2
        // (FORTUNER) los clasifico esta migracion — los otros 119 y ~600 SINOTRUK son de
        // la migracion anterior, y un `MARCA=TOYOTA` los dejaria a todos en NULL.
        //
        // Por eso el criterio va por MODELO: son exactamente los que la migracion
        // 2026_08_10_120000 dejo sin clasificar por ambiguos.
        $reclamados = [
            ['FORTUNER', 'GASOLINA'],   // TOYOTA
            ['FSL100T',  'GASOIL'],     // SINOTRUK (estaba tipificado MINI SHOWER)
            ['GALLOP',   'GASOIL'],     // JAC
            ['977L',     'GASOIL'],     // CAT
            ['F-750',    'GASOIL'],     // FORD
        ];

        foreach ($reclamados as [$modelo, $combustible]) {
            DB::table('equipos')
                ->where('MODELO', 'LIKE', "%{$modelo}%")
                ->where('COMBUSTIBLE', $combustible)
                ->update(['COMBUSTIBLE' => null]);
        }

        // F-350: solo las de chasis venezolano, que son las que se marcaron aqui.
        DB::table('equipos')
            ->where('MODELO', 'LIKE', '%F-350%')
            ->where('SERIAL_CHASIS', 'LIKE', '8YT%')
            ->where('COMBUSTIBLE', 'GASOLINA')
            ->update(['COMBUSTIBLE' => null]);
    }
};
