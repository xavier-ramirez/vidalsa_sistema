<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MUEVE el consumo diario de `caracteristicas_modelo` a `equipos`.
 *
 * Mismo motivo que COMBUSTIBLE (migracion 2026_08_10_120000), y aqui la prueba la dan
 * los propios datos de la empresa: en la proyeccion del frente TUBERIA 12'' AGUA SALADA
 * tres unidades del MISMO chasis SINOTRUK ZZ4257 tienen consumos distintos porque hacen
 * trabajos distintos —  chuto con batea 150 L/dia, chuto con brazo 80, chuto con lowboy
 * 50. El consumo depende del USO de la unidad, no del modelo.
 *
 * Ademas, en la ficha solo alcanzaba al 4,5% de la flota (54 de 1194): 636 equipos no
 * tienen ID_ESPEC, y como la ficha va por MODELO+ANIO_ESPEC, dos LOVOL FR420F de 2026
 * quedaban fuera del valor cargado en la ficha de 2025.
 *
 * TIPO: pasa de varchar(50) a decimal. La columna existe para SUMARSE (proyeccion de
 * gasoil por frente) y sobre texto eso obligaba a CAST en cada consulta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipos', function (Blueprint $table) {
            $table->decimal('CONSUMO_PROMEDIO', 8, 2)->nullable()->after('COMBUSTIBLE');
        });

        // Arrastra lo ya cargado en las fichas. El CAST tolera basura: cualquier texto no
        // numerico cae en 0 y se descarta con el NULLIF, en vez de entrar como cero real.
        DB::statement("
            UPDATE equipos e
            INNER JOIN caracteristicas_modelo c ON c.ID_ESPEC = e.ID_ESPEC
            SET e.CONSUMO_PROMEDIO = NULLIF(CAST(c.CONSUMO_PROMEDIO AS DECIMAL(8,2)), 0)
            WHERE c.CONSUMO_PROMEDIO IS NOT NULL AND c.CONSUMO_PROMEDIO <> ''
        ");

        Schema::table('caracteristicas_modelo', function (Blueprint $table) {
            $table->dropColumn('CONSUMO_PROMEDIO');
        });
    }

    public function down(): void
    {
        Schema::table('caracteristicas_modelo', function (Blueprint $table) {
            $table->string('CONSUMO_PROMEDIO', 50)->nullable()->after('MOTOR');
        });

        // Devuelve a cada ficha el consumo mayoritario de sus equipos ligados. Es lo mas
        // fiel posible: el valor por modelo ya no existe como tal — por eso mismo se movio.
        DB::statement("
            UPDATE caracteristicas_modelo c
            INNER JOIN (
                SELECT ID_ESPEC, CONSUMO_PROMEDIO
                FROM (
                    SELECT ID_ESPEC, CONSUMO_PROMEDIO,
                           ROW_NUMBER() OVER (PARTITION BY ID_ESPEC ORDER BY COUNT(*) DESC) rn
                    FROM equipos
                    WHERE ID_ESPEC IS NOT NULL
                      AND CONSUMO_PROMEDIO IS NOT NULL
                      AND deleted_at IS NULL
                    GROUP BY ID_ESPEC, CONSUMO_PROMEDIO
                ) ranked
                WHERE rn = 1
            ) m ON m.ID_ESPEC = c.ID_ESPEC
            SET c.CONSUMO_PROMEDIO = CAST(m.CONSUMO_PROMEDIO AS UNSIGNED)
        ");

        Schema::table('equipos', function (Blueprint $table) {
            $table->dropColumn('CONSUMO_PROMEDIO');
        });
    }
};
