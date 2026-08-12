<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El CAT 950G esta registrado como auxiliar TIPO=MONTACARGA, pero es un PAYLOADER
 * (cargador frontal) con implemento de jaiba — por eso en la proyeccion del frente
 * TUBERIA DE 12'' AGUA SALADA aparece como "PAYLOADER MONTACARGA TIPO JAIBA".
 *
 * Consume como payloader (200 L/dia), no como montacarga. La migracion anterior le
 * habia puesto los 150 L/dia que traia esa linea del Excel; operaciones confirma que
 * el valor correcto es el de payloader.
 *
 * NO se le cambia el TIPO: reclasificarlo moveria el equipo de tabla (los payloader
 * viven en `equipos`, no en `equipos_auxiliares`) y eso es una migracion de datos
 * aparte, con su documentacion y su historial. Aqui solo se corrige el consumo.
 */
return new class extends Migration
{
    private const CONSUMO_PAYLOADER = 200;
    private const CONSUMO_ANTERIOR  = 150;

    public function up(): void
    {
        DB::table('equipos_auxiliares')
            ->where('TIPO', 'MONTACARGA')
            ->where('MARCA', 'CAT')
            ->where('MODELO', '950G')
            ->whereNull('deleted_at')
            ->update(['CONSUMO_PROMEDIO' => self::CONSUMO_PAYLOADER]);
    }

    public function down(): void
    {
        // Solo revierte si conserva el valor exacto que puso up(): si alguien lo ajusto
        // a mano despues, ese ajuste manda.
        DB::table('equipos_auxiliares')
            ->where('TIPO', 'MONTACARGA')
            ->where('MARCA', 'CAT')
            ->where('MODELO', '950G')
            ->where('CONSUMO_PROMEDIO', self::CONSUMO_PAYLOADER)
            ->update(['CONSUMO_PROMEDIO' => self::CONSUMO_ANTERIOR]);
    }
};
