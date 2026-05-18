<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renombra las claves viejas de PERMISOS a sus equivalentes consolidados.
 *
 * Antes de esta migration: Usuario::can() tenia un alias forward y dos reverse
 * que convertian silenciosamente las claves viejas en las nuevas (ver historial
 * en Usuario.php). Esto creaba un agujero: un admin que destildaba la clave
 * nueva en el picker no se enteraba de que el usuario tenia la vieja guardada
 * desde antes, y el alias seguia otorgando acceso.
 *
 * Esta migration mueve TODOS los usuarios al modelo nuevo (clave en PERMISOS
 * = unica fuente de verdad). Despues los aliases se borran de Usuario::can().
 *
 *   almacen.manage              → almacen.productos
 *   traspaso.recibir            → almacen.movimiento
 *   almacen.salidas_recepciones → almacen.movimiento
 *
 * Dedup automatico: si el usuario ya tenia la clave nueva ademas de la vieja,
 * queda solo una.
 *
 * down(): no se puede revertir con certeza (no sabemos cual clave vino de cual
 * historico). Lo dejamos no-op — si necesitan rollback, restauran backup.
 */
return new class extends Migration {
    public function up(): void
    {
        $mapping = [
            'almacen.manage'              => 'almacen.productos',
            'traspaso.recibir'            => 'almacen.movimiento',
            'almacen.salidas_recepciones' => 'almacen.movimiento',
        ];

        DB::table('usuarios')
            ->select('ID_USUARIO', 'PERMISOS')
            ->orderBy('ID_USUARIO')
            ->chunkById(200, function ($users) use ($mapping) {
                foreach ($users as $u) {
                    $orig = (string) ($u->PERMISOS ?? '');
                    if ($orig === '') {
                        continue;
                    }
                    $keys = array_map('trim', explode(',', $orig));
                    $renamed = array_map(fn ($k) => $mapping[$k] ?? $k, $keys);
                    $cleaned = array_values(array_unique(array_filter($renamed, fn ($k) => $k !== '')));
                    $new = implode(',', $cleaned);
                    if ($new !== $orig) {
                        DB::table('usuarios')
                            ->where('ID_USUARIO', $u->ID_USUARIO)
                            ->update(['PERMISOS' => $new]);
                    }
                }
            }, 'ID_USUARIO');
    }

    public function down(): void
    {
        // No-op. El renombrado pierde la informacion historica de cual usuario
        // tenia originalmente cada clave vieja, asi que un rollback automatico
        // seria una adivinanza. Para revertir: restaurar backup de la columna.
    }
};
