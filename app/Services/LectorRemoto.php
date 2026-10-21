<?php

namespace App\Services;

use App\Models\Usuario;
use Illuminate\Support\Facades\Cache;

/**
 * El TELÉFONO como lector de la PC: lo que se escanea con la cámara se filtra en la pantalla
 * de inventario que el MISMO usuario tiene abierta en la PC (Inventario, Movimientos o la
 * bandeja de Recepción). El teléfono publica el producto aquí y la PC pregunta por él.
 *
 * Todo vive en la caché, por usuario, y dura poco: es un buzón, no un historial.
 *   · items  — los últimos escaneos, cada uno con `seq` = milisegundos del servidor. Al ser
 *              una hora y no un contador, no se reinicia si la clave caduca: la PC nunca se
 *              queda esperando un número que ya no va a llegar.
 *   · activo — el teléfono tiene la cámara abierta en modo «En la PC». Con esa marca
 *              la PC pregunta rápido; sin ella, despacio. Así las PC que solo tienen el
 *              inventario abierto no cargan al servidor (en producción php-fpm tiene 12
 *              procesos: por eso preguntas cortas y no una petición que se queda esperando).
 *   · pc     — alguna PC preguntó hace poco: el teléfono puede avisar si no hay ninguna.
 *
 * La PC pregunta SIN sesión (ruta en routes/api.php, con la llave de canal()): si pasara por
 * la sesión, cada pregunta la renovaría y nunca se cerraría por inactividad.
 */
class LectorRemoto
{
    /** Cuántos escaneos guarda el buzón y cuánto viven. */
    private const ITEMS_MAX = 20;
    private const ITEMS_TTL = 120;
    /**
     * La marca la pone el teléfono al abrir la cámara, la renueva cada código y la quita al
     * cerrar. Sin latido periódico: mantendría viva la sesión del teléfono sin que nadie lo
     * use. Si el teléfono se apaga sin cerrar, la PC vuelve a ir despacio a los 10 min.
     */
    private const ACTIVO_TTL = 600;
    private const PC_TTL = 10;
    /** Vida de la llave de la PC; la pantalla trae una nueva en cada visita. */
    private const CANAL_TTL = 12 * 3600;

    /**
     * Llave con la que la PC pregunta por el buzón de su usuario: id.caduca.firma. Va firmada
     * con la APP_KEY y lleva la huella de la clave del usuario, así que cambiar la clave la
     * anula igual que anula la sesión.
     */
    public static function canal(Usuario $u): string
    {
        $caduca = now()->getTimestamp() + self::CANAL_TTL;
        return $u->ID_USUARIO . '.' . $caduca . '.' . self::firma($u, $caduca);
    }

    /** El usuario de una llave válida y vigente, o null. */
    public static function usuarioDelCanal(string $canal): ?Usuario
    {
        $partes = explode('.', $canal);
        if (count($partes) !== 3 || !ctype_digit($partes[0]) || !ctype_digit($partes[1])) return null;
        [$id, $caduca, $firma] = $partes;
        if ((int) $caduca < now()->getTimestamp()) return null;

        $u = Usuario::find((int) $id);
        if (!$u || $u->ESTATUS === 'INACTIVO') return null;
        return hash_equals(self::firma($u, (int) $caduca), $firma) ? $u : null;
    }

    /** Deja un producto escaneado en el buzón del usuario y marca el teléfono como activo. */
    public static function publicar(int $idUsuario, array $producto): void
    {
        $clave = self::clave($idUsuario, 'items');
        $items = Cache::get($clave, []);
        // +1 sobre el último por si dos escaneos caen en el mismo milisegundo.
        $seq = max(self::ahoraMs(), $items ? end($items)['seq'] + 1 : 0);
        $items[] = ['seq' => $seq, 'producto' => $producto];
        Cache::put($clave, array_slice($items, -self::ITEMS_MAX), self::ITEMS_TTL);
        self::marcarActivo($idUsuario, true);
    }

    public static function marcarActivo(int $idUsuario, bool $activo): void
    {
        $clave = self::clave($idUsuario, 'activo');
        $activo ? Cache::put($clave, true, self::ACTIVO_TTL) : Cache::forget($clave);
    }

    /** ¿Alguna PC de este usuario preguntó en los últimos segundos? */
    public static function hayPc(int $idUsuario): bool
    {
        return (bool) Cache::get(self::clave($idUsuario, 'pc'));
    }

    /**
     * Lo que la PC no ha visto todavía. Sin `desde` (primera pregunta de la pantalla) no
     * entrega nada: solo dice desde dónde seguir, para no aplicar escaneos viejos al abrir.
     *
     * @return array{activo: bool, desde: int, items: array}
     */
    public static function pendientes(int $idUsuario, ?int $desde): array
    {
        // add y no put: escribe una vez cada PC_TTL, no en cada pregunta.
        Cache::add(self::clave($idUsuario, 'pc'), true, self::PC_TTL);

        $items = Cache::get(self::clave($idUsuario, 'items'), []);
        $ultimo = $items ? end($items)['seq'] : 0;
        $nuevos = $desde === null ? [] : array_values(array_filter($items, fn ($i) => $i['seq'] > $desde));

        return [
            'activo' => (bool) Cache::get(self::clave($idUsuario, 'activo')),
            'desde'  => $desde === null ? $ultimo : max($desde, $ultimo),
            'items'  => $nuevos,
        ];
    }

    private static function firma(Usuario $u, int $caduca): string
    {
        return hash_hmac('sha256', 'lector-remoto|' . $u->ID_USUARIO . '|' . $caduca . '|' . $u->claveVersion(), (string) config('app.key'));
    }

    private static function clave(int $idUsuario, string $que): string
    {
        return 'lector_remoto:' . $idUsuario . ':' . $que;
    }

    private static function ahoraMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
