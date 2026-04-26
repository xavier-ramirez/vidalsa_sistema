<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el quinto responsable (RESP_5) a frentes_trabajo.
 *
 * Contexto del sistema de firmas (Acta de Traslado):
 * ─────────────────────────────────────────────────────────────
 *  RESP_1  Coord. Mecánica Liviana   EQU = 'FLOTA LIVIANA'  → SOLICITADO
 *  RESP_2  Coord. Mecánica Pesada    EQU = 'FLOTA PESADA'   → SOLICITADO (alt)
 *  RESP_3  Transporte y Logística    EQU = ''               → ELABORADO
 *  RESP_4  Sub-gerente               EQU = ''               → REVISADO
 *  RESP_5  Gerente                   EQU = ''               → APROBADO   ← NUEVO
 *
 * Nota de diseño:
 *   - Todos los campos son nullable → sin impacto en registros existentes.
 *   - CED se agrega en la misma migración para mantener consistencia
 *     con RESP_1–4 y evitar tener dos migraciones separadas que puedan
 *     generar conflictos de orden al desplegar en producción.
 * ─────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('frentes_trabajo', function (Blueprint $table) {
            // Se insertan después de RESP_4_EQU para mantener la agrupación lógica
            // RESP_N_NOM → RESP_N_CAR → RESP_N_CED → RESP_N_EQU
            $table->string('RESP_5_NOM', 80)->nullable()->after('RESP_4_EQU');
            $table->string('RESP_5_CAR', 60)->nullable()->after('RESP_5_NOM');
            $table->string('RESP_5_CED', 20)->nullable()->after('RESP_5_CAR');
            $table->string('RESP_5_EQU', 40)->nullable()->after('RESP_5_CED');
        });
    }

    public function down(): void
    {
        Schema::table('frentes_trabajo', function (Blueprint $table) {
            $table->dropColumn(['RESP_5_NOM', 'RESP_5_CAR', 'RESP_5_CED', 'RESP_5_EQU']);
        });
    }
};
