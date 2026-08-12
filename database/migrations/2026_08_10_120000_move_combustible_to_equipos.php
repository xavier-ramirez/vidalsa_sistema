<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MUEVE el tipo de combustible de `caracteristicas_modelo` a `equipos`.
 *
 * POR QUE: el combustible NO es atributo del modelo. Un mismo MODELO puede traer
 * motor a gasolina o a gasoil segun la unidad (HILUX 2.7 gasolina vs 2.4 diesel,
 * F-350 Triton gasolina vs Power Stroke diesel). Guardarlo en la ficha del modelo
 * hacia imposible representar la flota real, y ademas solo alcanzaba al 47% de los
 * equipos: 636 de 1194 no tienen ID_ESPEC, asi que no tenian donde guardarlo.
 *
 * Se MUEVE, no se copia: la columna se elimina de `caracteristicas_modelo` para que
 * quede UNA sola fuente de verdad y no haya dos sitios de donde leer.
 * `caracteristicas_modelo` conserva MOTOR y CONSUMO_PROMEDIO, que si son
 * referencia del modelo.
 *
 * La auditoria sale gratis: EquipoObserver::updated() ya registra el diff de
 * cualquier columna cambiada de `equipos` en equipo_audit_log.
 */
return new class extends Migration
{
    /** Tipos de equipo remolcados: no tienen motor ni consumen combustible. */
    private const TIPOS_SIN_MOTOR = [
        'BATEA', 'LOWBOY', 'TRAILERS', 'BATEA/SILOS',
        'CAMA BAJA', 'BATEA/VOLQUETA', 'TARA',
    ];

    /**
     * Modelos que existen en version gasolina Y diesel: se dejan en NULL hasta que
     * se verifique el motor fisico en patio. Marcarlos a ciegas seria inventar data.
     */
    private const MODELOS_A_VERIFICAR = [
        'F-350', 'F-750', 'RANGER', 'FORTUNER', 'LUV', 'LUD', 'T60', 'GALLOP',
    ];

    public function up(): void
    {
        Schema::table('equipos', function (Blueprint $table) {
            $table->string('COMBUSTIBLE', 20)->nullable()->after('SERIAL_DE_MOTOR');
            $table->index('COMBUSTIBLE', 'idx_equipos_combustible');
        });

        // --- 1) Lo ya cargado en las fichas manda: se arrastra tal cual. ---
        DB::statement("
            UPDATE equipos e
            INNER JOIN caracteristicas_modelo c ON c.ID_ESPEC = e.ID_ESPEC
            SET e.COMBUSTIBLE = c.COMBUSTIBLE
            WHERE c.COMBUSTIBLE IS NOT NULL AND c.COMBUSTIBLE <> ''
        ");

        // Normaliza DIESEL -> GASOIL. El desplegable ofrecia ambos siendo lo mismo,
        // lo que partia en dos cualquier reporte por combustible.
        DB::statement("UPDATE equipos SET COMBUSTIBLE = 'GASOIL' WHERE COMBUSTIBLE = 'DIESEL'");

        // --- 2) Equipos de arrastre: NO APLICA. ---
        DB::table('equipos')
            ->whereNull('COMBUSTIBLE')
            ->whereIn('id_tipo_equipo', function ($q) {
                $q->select('id')->from('tipo_equipos')->whereIn('nombre', self::TIPOS_SIN_MOTOR);
            })
            ->update(['COMBUSTIBLE' => 'NO APLICA']);

        // --- 3) Gasolina confirmada por modelo/marca. ---
        DB::table('equipos')
            ->whereNull('COMBUSTIBLE')
            ->where(function ($q) {
                foreach (['TACOMA', 'RAV4', 'COROLLA', '4RUNNER', 'RORAIMA',
                          'SILVERADO', 'CHEYENNE', 'C3500', 'HILUX'] as $m) {
                    $q->orWhere('MODELO', 'LIKE', "%{$m}%");
                }
                // CHERY (Arauca, X1) y motos: gasolina sin excepcion.
                $q->orWhere('MARCA', 'CHERY')->orWhere('MARCA', 'HAOJUE');
            })
            ->update(['COMBUSTIBLE' => 'GASOLINA']);

        // --- 4) Resto con motor: GASOIL, salvo los modelos ambiguos. ---
        DB::table('equipos')
            ->whereNull('COMBUSTIBLE')
            ->where(function ($q) {
                foreach (self::MODELOS_A_VERIFICAR as $m) {
                    $q->where('MODELO', 'NOT LIKE', "%{$m}%");
                }
            })
            // MINI SHOWER mezcla remolques con maquinas (hay un CAT 977L ahi dentro):
            // se deja sin marcar hasta depurar ese tipo.
            ->whereNotIn('id_tipo_equipo', function ($q) {
                $q->select('id')->from('tipo_equipos')->where('nombre', 'MINI SHOWER');
            })
            ->update(['COMBUSTIBLE' => 'GASOIL']);

        // --- 5) Fuente unica: se elimina la columna del catalogo. ---
        Schema::table('caracteristicas_modelo', function (Blueprint $table) {
            $table->dropColumn('COMBUSTIBLE');
        });
    }

    public function down(): void
    {
        Schema::table('caracteristicas_modelo', function (Blueprint $table) {
            $table->string('COMBUSTIBLE', 100)->nullable()->after('MOTOR');
        });

        // Devuelve a cada ficha el combustible mayoritario de sus equipos ligados.
        // Es lo mas fiel posible: el dato original por modelo ya no existe como tal
        // (por eso mismo se movio), y un modelo puede tener unidades de ambos tipos.
        DB::statement("
            UPDATE caracteristicas_modelo c
            INNER JOIN (
                SELECT ID_ESPEC, COMBUSTIBLE
                FROM (
                    SELECT ID_ESPEC, COMBUSTIBLE,
                           ROW_NUMBER() OVER (PARTITION BY ID_ESPEC ORDER BY COUNT(*) DESC) rn
                    FROM equipos
                    WHERE ID_ESPEC IS NOT NULL
                      AND COMBUSTIBLE IS NOT NULL
                      AND deleted_at IS NULL
                    GROUP BY ID_ESPEC, COMBUSTIBLE
                ) ranked
                WHERE rn = 1
            ) m ON m.ID_ESPEC = c.ID_ESPEC
            SET c.COMBUSTIBLE = m.COMBUSTIBLE
        ");

        Schema::table('equipos', function (Blueprint $table) {
            $table->dropIndex('idx_equipos_combustible');
            $table->dropColumn('COMBUSTIBLE');
        });
    }
};
