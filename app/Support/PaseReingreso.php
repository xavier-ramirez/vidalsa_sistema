<?php

namespace App\Support;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * Pase de reingreso: lo que deja volver a tener sesión a quien entró SIN conexión.
 *
 * Al entrar sin internet la clave se comprueba en el equipo (offline-auth.js) y el servidor
 * no se entera: no hay sesión allá. Para que al volver la conexión los cambios suban sin
 * pedir la clave otra vez, el equipo guarda un pase que le dio el servidor cuando sí había
 * sesión, CIFRADO con la llave local (reingreso.js): sin la clave, en el equipo no se puede
 * leer. Al volver el servidor se canjea por una sesión (ReingresoController::reanudar).
 *
 * Un solo uso (cada canje entrega uno nuevo), vence a los DIAS días y deja de servir si la
 * clave cambia, el usuario se desactiva o tiene que cambiar la clave. Aquí solo se guarda
 * el hash.
 */
final class PaseReingreso
{
    public const TABLA = 'pases_reingreso';

    public const DIAS = 30;

    /** Equipos con pase por usuario: al emitir uno más se borran los más viejos. */
    public const MAX_POR_USUARIO = 10;

    /** Emite un pase nuevo. `$anterior` (el que el equipo tenía) se borra. */
    public static function emitir(Usuario $usuario, ?string $anterior = null): string
    {
        $pase = bin2hex(random_bytes(32));

        DB::transaction(static function () use ($usuario, $anterior, $pase) {
            $suyos = static fn () => DB::table(self::TABLA)->where('ID_USUARIO', $usuario->ID_USUARIO);

            $suyos()->where(static function ($q) use ($anterior) {
                $q->where('VENCE', '<', now());
                if ($anterior !== null && $anterior !== '') {
                    $q->orWhere('HASH', self::hash($anterior));
                }
            })->delete();

            DB::table(self::TABLA)->insert([
                'ID_USUARIO' => $usuario->ID_USUARIO,
                'HASH'       => self::hash($pase),
                'CLAVE_V'    => $usuario->claveVersion(),
                'VENCE'      => now()->addDays(self::DIAS),
                'CREADO'     => now(),
            ]);

            $sobran = $suyos()->orderByDesc('ID_PASE')->skip(self::MAX_POR_USUARIO)->take(PHP_INT_MAX)->pluck('ID_PASE');
            if ($sobran->isNotEmpty()) {
                DB::table(self::TABLA)->whereIn('ID_PASE', $sobran)->delete();
            }
        });

        return $pase;
    }

    /**
     * El usuario del pase, o null si no sirve. Se gasta igual sirva o no: uno robado y
     * probado no queda para después.
     */
    public static function canjear(string $pase): ?Usuario
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $pase)) {
            return null;
        }

        return DB::transaction(static function () use ($pase) {
            $fila = DB::table(self::TABLA)->where('HASH', self::hash($pase))->lockForUpdate()->first();
            if (!$fila) {
                return null;
            }
            DB::table(self::TABLA)->where('ID_PASE', $fila->ID_PASE)->delete();

            $usuario = Usuario::find($fila->ID_USUARIO);
            $sirve = $usuario
                && now()->lt($fila->VENCE)
                && $usuario->ESTATUS !== 'INACTIVO'
                && !$usuario->REQUIERE_CAMBIO_CLAVE
                && hash_equals($fila->CLAVE_V, $usuario->claveVersion());

            return $sirve ? $usuario : null;
        });
    }

    private static function hash(string $pase): string
    {
        return hash('sha256', $pase);
    }
}
