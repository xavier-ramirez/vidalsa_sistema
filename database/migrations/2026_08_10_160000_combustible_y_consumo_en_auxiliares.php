<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * COMBUSTIBLE y CONSUMO_PROMEDIO en `equipos_auxiliares`.
 *
 * POR QUE: la proyeccion de gasoil de un frente tiene que sumar las DOS tablas. En el
 * frente TUBERIA DE 12'' AGUA SALADA las 20 maquinas de soldar y los 2 compresores son
 * 1.000 L/dia — el 15% del total — y esta tabla no tenia donde guardarlos, asi que
 * quedaban fuera del calculo sin que nada lo avisara.
 *
 * Mismas columnas, mismos valores y misma semantica que en `equipos` (migraciones
 * 2026_08_10_120000 y _140000): la lista de combustibles validos sigue siendo
 * Equipo::COMBUSTIBLES — una sola fuente para las dos tablas, sin listas paralelas.
 *
 * Clasificacion, respaldada modelo por modelo:
 *   · Con motor diesel: maquinas de soldar, compresores, luminarias (torre con planta),
 *     plantas electricas, manlifts (HANGCHA GTHZ/HZ) e hidrojets (bombas de 3000-4000 PSI,
 *     llevan motor propio).
 *   · MONTACARGA va por MODELO, no por tipo: en la nomenclatura HANGCHA "CPD" es
 *     ELECTRICO (CPCD seria diesel, CPQD gasolina/GLP), y el TOYOTA "8FG" es gasolina.
 *     Solo el CAT 950G es gasoil. Marcarlos todos GASOIL habria inventado consumo en
 *     6 equipos que no queman nada.
 *   · Sin motor: contenedores, tanques de combustible y la rastra (es de arrastre).
 */
return new class extends Migration
{
    /** TIPO de auxiliar con motor diesel. */
    private const TIPOS_GASOIL = [
        'MAQUINA_DE_SOLDAR', 'COMPRESOR', 'LUMINARIA',
        'PLANTA_ELECTRICA', 'MANLIFT', 'HIDROJET',
    ];

    /** TIPO de auxiliar sin motor: no consume nada. */
    private const TIPOS_SIN_MOTOR = ['CONTAINER', 'TANQUE_DE_COMBUSTIBLE', 'RASTRA'];

    /**
     * Consumo L/dia por TIPO. Fuente: proyeccion del frente TUBERIA 12'' AGUA SALADA.
     * PLANTA_ELECTRICA, MANLIFT e HIDROJET quedan sin valor a proposito: no estan en esa
     * proyeccion, y las plantas van de 10 a 600 KVA — un solo numero para todas seria
     * mentira. El montacarga tampoco va aqui: solo el CAT 950G consume, y se carga aparte.
     */
    private const CONSUMO_POR_TIPO = [
        'COMPRESOR'         => 200,
        'MAQUINA_DE_SOLDAR' =>  30,
        'LUMINARIA'         =>  20,
    ];

    public function up(): void
    {
        Schema::table('equipos_auxiliares', function (Blueprint $table) {
            $table->string('COMBUSTIBLE', 20)->nullable()->after('ANIO');
            $table->decimal('CONSUMO_PROMEDIO', 8, 2)->nullable()->after('COMBUSTIBLE');
            $table->index('COMBUSTIBLE', 'idx_auxiliares_combustible');
        });

        DB::table('equipos_auxiliares')->whereNull('COMBUSTIBLE')
            ->whereIn('TIPO', self::TIPOS_GASOIL)->update(['COMBUSTIBLE' => 'GASOIL']);

        DB::table('equipos_auxiliares')->whereNull('COMBUSTIBLE')
            ->whereIn('TIPO', self::TIPOS_SIN_MOTOR)->update(['COMBUSTIBLE' => 'NO APLICA']);

        // MONTACARGA: decide el MODELO. Ver el porque en la cabecera.
        DB::table('equipos_auxiliares')->whereNull('COMBUSTIBLE')->where('TIPO', 'MONTACARGA')
            ->where('MODELO', 'LIKE', 'CPD%')->update(['COMBUSTIBLE' => 'ELECTRICO']);

        DB::table('equipos_auxiliares')->whereNull('COMBUSTIBLE')->where('TIPO', 'MONTACARGA')
            ->where('MODELO', 'LIKE', '8FG%')->update(['COMBUSTIBLE' => 'GASOLINA']);

        DB::table('equipos_auxiliares')->whereNull('COMBUSTIBLE')->where('TIPO', 'MONTACARGA')
            ->update(['COMBUSTIBLE' => 'GASOIL']);

        // --- Consumo: solo a lo que quema gasoil ---
        foreach (self::CONSUMO_POR_TIPO as $tipo => $litros) {
            DB::table('equipos_auxiliares')
                ->whereNull('CONSUMO_PROMEDIO')->where('COMBUSTIBLE', 'GASOIL')
                ->where('TIPO', $tipo)->update(['CONSUMO_PROMEDIO' => $litros]);
        }

        // El CAT 950G es el "PAYLOADER MONTACARGA TIPO JAIBA" de la proyeccion: 150 L/dia.
        DB::table('equipos_auxiliares')
            ->whereNull('CONSUMO_PROMEDIO')->where('COMBUSTIBLE', 'GASOIL')
            ->where('TIPO', 'MONTACARGA')->update(['CONSUMO_PROMEDIO' => 150]);
    }

    public function down(): void
    {
        Schema::table('equipos_auxiliares', function (Blueprint $table) {
            $table->dropIndex('idx_auxiliares_combustible');
            $table->dropColumn(['COMBUSTIBLE', 'CONSUMO_PROMEDIO']);
        });
    }
};
