<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separa `usuarios.NIVEL_ACCESO` en dos niveles independientes:
 *   · NIVEL_ACCESO_EQUIPOS  → visibilidad por frente en Equipos / Auxiliares /
 *     Movilizaciones / Historial / Dashboard.
 *   · NIVEL_ACCESO_ALMACEN  → visibilidad de almacenes (Almacen::visiblesPara),
 *     Traspasos / Recepción y el snapshot offline de almacén.
 *
 * En ambos: 1 = GLOBAL (ve todos los frentes), 2 = LOCAL (solo los asignados).
 *
 * Motivo: un único campo obligaba a mover los dos módulos a la vez. No se podía
 * tener un almacenista GLOBAL en equipos y LOCAL en su almacén (ni al revés).
 *
 * La lista blanca (ID_FRENTE_ASIGNADO) y la lista negra (ID_FRENTE_BLOQUEADO)
 * siguen siendo COMPARTIDAS: definen "cuáles frentes son míos"; cada nivel decide
 * si el módulo ve los de todos o solo los míos.
 *
 * Backfill: ambas columnas copian el NIVEL_ACCESO vigente, así que al desplegar
 * NINGÚN usuario cambia de visibilidad. La divergencia se hace después, a mano,
 * desde /admin/usuarios. Luego se elimina NIVEL_ACCESO para que no quede una
 * tercera fuente de verdad que pueda desincronizarse en silencio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            // default 2 (LOCAL) = el mismo default que tenía NIVEL_ACCESO: si algo
            // inserta un usuario sin nivel, queda restringido, no abierto.
            $table->integer('NIVEL_ACCESO_EQUIPOS')->default(2)->after('ID_FRENTE_BLOQUEADO');
            $table->integer('NIVEL_ACCESO_ALMACEN')->default(2)->after('NIVEL_ACCESO_EQUIPOS');
        });

        // Backfill desde el nivel único. Un NIVEL_ACCESO nulo o inválido (≠1) se
        // normaliza a 2/LOCAL, que es como lo trataba Usuario::veTodosLosFrentes()
        // ("cualquier valor distinto de 1 → restringido").
        DB::table('usuarios')->update([
            'NIVEL_ACCESO_EQUIPOS' => DB::raw('CASE WHEN NIVEL_ACCESO = 1 THEN 1 ELSE 2 END'),
            'NIVEL_ACCESO_ALMACEN' => DB::raw('CASE WHEN NIVEL_ACCESO = 1 THEN 1 ELSE 2 END'),
        ]);

        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('NIVEL_ACCESO');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->integer('NIVEL_ACCESO')->default(2)->after('ID_FRENTE_BLOQUEADO');
        });

        // Al revertir se pierde la divergencia: un usuario GLOBAL en equipos y LOCAL en
        // almacén no cabe en un solo campo. Se conserva el nivel de EQUIPOS por ser el
        // que gobierna más módulos (equipos, auxiliares, movilizaciones, historial,
        // dashboard) — degradar almacén a LOCAL es el lado seguro del error.
        DB::table('usuarios')->update([
            'NIVEL_ACCESO' => DB::raw('NIVEL_ACCESO_EQUIPOS'),
        ]);

        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn(['NIVEL_ACCESO_EQUIPOS', 'NIVEL_ACCESO_ALMACEN']);
        });
    }
};
