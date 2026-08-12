<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El CAT 242B quedó tipificado como PAYLOADER y por eso heredó los 200 L/día del tipo.
 * Pero un 242B es un MINICARGADOR (skid steer) de ~70 HP, no un cargador frontal de
 * 200+ HP: a media carga son unos 7 L/h, o sea ~60 L/día en jornada de 8 h. Dejarlo en
 * 200 metía 140 L/día inventados.
 *
 * Cómo llegó ahí: los tipos CARGADOR DE RUEDAS y MINICARGADOR se fusionaron dentro de
 * PAYLOADER (scripts update_cargador.php / update_minicargador.php). La fusión es
 * correcta para los cargadores frontales grandes —XCMG LW800K, CAT 980G, HYUNDAI
 * HL7607A, que sí consumen como payloader— pero arrastró también al minicargador.
 *
 * NO se le cambia el TIPO ni el MODELO. El modelo está mal escrito ("MINI SHOWER 242B",
 * con un prefijo pegado por error) y reclasificarlo es decisión de operaciones, no de
 * una migración de consumo.
 */
return new class extends Migration
{
    private const CONSUMO_MINICARGADOR = 60;
    private const CONSUMO_PAYLOADER    = 200;

    public function up(): void
    {
        DB::table('equipos')
            ->where('MODELO', 'LIKE', '%242B%')
            ->where('CONSUMO_PROMEDIO', self::CONSUMO_PAYLOADER)
            ->update(['CONSUMO_PROMEDIO' => self::CONSUMO_MINICARGADOR]);
    }

    public function down(): void
    {
        DB::table('equipos')
            ->where('MODELO', 'LIKE', '%242B%')
            ->where('CONSUMO_PROMEDIO', self::CONSUMO_MINICARGADOR)
            ->update(['CONSUMO_PROMEDIO' => self::CONSUMO_PAYLOADER]);
    }
};
