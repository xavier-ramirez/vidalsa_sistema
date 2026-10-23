<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Registro de CAMBIOS — el «libro diario» del que se alimenta la copia local de cada
 * teléfono o PC (ver App\Http\Controllers\SincronizacionController).
 *
 * Cada alta, edición o borrado en las tablas de FUENTES deja una fila en `cambios` con
 * QUÉ cambió (TABLA = el agregado que ve el cliente, ID_FILA = su id). La escriben
 * TRIGGERS de la base, no el código PHP: así no se escapa nada, venga el cambio de la
 * app, de un update masivo sin eventos, de un comando artisan o de un SQL pegado a mano en
 * phpMyAdmin. Es lo que reemplaza al juego de huellas, contadores y TTL de OfflineVersion,
 * que tenía que adivinar qué había cambiado.
 *
 * Un cambio en una tabla HIJA se anota con el id del agregado al que pertenece: la placa
 * (documentacion) o un reporte de falla cambian lo que muestra el EQUIPO, así que se
 * anotan como «equipos, ID_EQUIPO». En los UPDATE se anota también el id VIEJO si cambió
 * (un auxiliar que pasa de un equipo anfitrión a otro cambia a los dos).
 *
 * Los catálogos cuyos datos van copiados en los equipos (nombre del frente, del tipo, de
 * la aseguradora, foto de la ficha) se anotan con su propio nombre ('frentes', 'tipos',
 * 'seguros', 'fichas') y el dominio los EXPANDE a los equipos afectados al responder.
 */
class RegistroCambios
{
    public const TABLA = 'cambios';

    /**
     * Tabla de la base → agregados que anota: [agregado, columna con su id, condición].
     * La condición se escribe con {r} en lugar de NEW/OLD.
     */
    private const FUENTES = [
        'equipos'                => [['equipos', 'ID_EQUIPO']],
        'documentacion'          => [['equipos', 'ID_EQUIPO']],
        'fallas'                 => [['equipos', 'ACTIVO_ID', "{r}.ACTIVO_TIPO = 'equipo'"],
                                     ['auxiliares', 'ACTIVO_ID', "{r}.ACTIVO_TIPO = 'equipo_auxiliar'"]],
        // El equipo anfitrión muestra cuántos auxiliares lleva encima (subCount).
        'equipos_auxiliares'     => [['auxiliares', 'ID_AUXILIAR'], ['equipos', 'ID_EQUIPO_HOST']],
        'movilizacion_historial' => [['movilizaciones', 'ID_MOVILIZACION']],
        'frentes_trabajo'        => [['frentes', 'ID_FRENTE']],
        'tipo_equipos'           => [['tipos', 'id']],
        'catalogo_seguros'       => [['seguros', 'ID_SEGURO']],
        'caracteristicas_modelo' => [['fichas', 'ID_ESPEC']],
        'catalogo_colores'       => [['fichas', 'ID_ESPEC']],
    ];

    private const EVENTOS = ['INSERT' => 'ai', 'UPDATE' => 'au', 'DELETE' => 'ad'];

    /** TABLA de la fila que deja purgar(): no es un cambio, es hasta dónde se borró. */
    private const MARCA_PURGA = '_purga';

    /**
     * Un hueco en la numeración más nuevo que esto puede ser una transacción que aún no
     * confirmó (ver desde()). Pasado este tiempo se da por definitivo: InnoDB deja huecos
     * para siempre cuando una transacción se revierte.
     */
    private const ESPERA_HUECO_SEG = 300;

    /** Más filas que esto en un tramo y el cliente recarga entero: el tramo dejó de ahorrar. */
    public const MAX_FILAS = 5000;

    // ─────────────────────────────────────────────────────────────────────────
    //  Triggers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Las sentencias CREATE TRIGGER, por nombre. FUENTE ÚNICA: las usan la migración y
     * `php artisan cambios:sql` (para crearlas a mano en phpMyAdmin si el usuario de la
     * aplicación no tuviera permiso).
     *
     * Una sola sentencia por trigger, sin BEGIN…END en MySQL: un INSERT…SELECT con UNION
     * ALL. Así se puede pegar en phpMyAdmin sin cambiar el delimitador. SQLite (pruebas)
     * exige el BEGIN…END y no conoce DUAL.
     *
     * @return array<string,string>
     */
    public static function sentencias(?string $driver = null): array
    {
        $driver ??= DB::connection()->getDriverName();
        $sqlite  = $driver === 'sqlite';
        $sql     = [];

        foreach (self::FUENTES as $tabla => $agregados) {
            foreach (self::EVENTOS as $evento => $sufijo) {
                $selects = [];
                foreach ($agregados as $a) {
                    [$agregado, $col, $cond] = $a + [2 => null];
                    // INSERT ve NEW, DELETE ve OLD, UPDATE los dos (OLD solo si cambió el id).
                    $refs = ['INSERT' => ['NEW'], 'DELETE' => ['OLD'], 'UPDATE' => ['NEW', 'OLD']][$evento];
                    foreach ($refs as $r) {
                        $donde = ["{$r}.{$col} IS NOT NULL"];
                        if ($cond) $donde[] = str_replace('{r}', $r, $cond);
                        if ($evento === 'UPDATE' && $r === 'OLD') {
                            $donde[] = "(NEW.{$col} IS NULL OR NEW.{$col} <> OLD.{$col})";
                        }
                        $selects[] = "SELECT '{$agregado}', {$r}.{$col}" . ($sqlite ? '' : ' FROM DUAL')
                            . ' WHERE ' . implode(' AND ', $donde);
                    }
                }
                $insert = 'INSERT INTO ' . self::TABLA . ' (TABLA, ID_FILA) ' . implode(' UNION ALL ', $selects);
                $nombre = self::nombreTrigger($tabla, $sufijo);
                $sql[$nombre] = "CREATE TRIGGER {$nombre} AFTER {$evento} ON {$tabla} FOR EACH ROW "
                    . ($sqlite ? "BEGIN {$insert}; END" : $insert);
            }
        }

        return $sql;
    }

    private static function nombreTrigger(string $tabla, string $sufijo): string
    {
        return 'cambios_' . $tabla . '_' . $sufijo;
    }

    /**
     * Crea (o recrea) todos los triggers. NO lanza: devuelve los que fallaron.
     *
     * En MySQL 8 con el registro binario encendido, crear un trigger exige SUPER (o
     * log_bin_trust_function_creators=1) y el usuario de la aplicación puede no tenerlo.
     * Si pasa, la migración sigue y la sincronización trabaja en el modo de respaldo
     * (activo() = false) hasta que se creen con `php artisan cambios:sql` en phpMyAdmin.
     *
     * @return array<string,string> nombre → mensaje de error
     */
    public static function crearTriggers(): array
    {
        $fallos = [];
        foreach (self::sentencias() as $nombre => $sql) {
            try {
                DB::unprepared("DROP TRIGGER IF EXISTS {$nombre}");
                DB::unprepared($sql);
            } catch (\Throwable $e) {
                $fallos[$nombre] = $e->getMessage();
            }
        }
        if ($fallos) {
            Log::warning('RegistroCambios: no se pudieron crear ' . count($fallos) . ' triggers; la sincronización usa el modo de respaldo.', $fallos);
        }
        self::olvidarEstado();

        return $fallos;
    }

    public static function borrarTriggers(): void
    {
        foreach (array_keys(self::sentencias()) as $nombre) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$nombre}");
        }
        self::olvidarEstado();
    }

    /**
     * ¿Están TODOS los triggers? Si falta uno, un tipo de cambio no quedaría anotado y la
     * copia local se desviaría sin aviso: mejor el modo de respaldo entero.
     *
     * Cacheado (store del offline, en disco: 0 consultas) porque lo pregunta cada
     * sincronización; crearTriggers() y borrarTriggers() lo olvidan.
     */
    public static function activo(): bool
    {
        return Cache::store(config('offline.store', 'file'))->remember('registro_cambios_activo', 600, static function () {
            $driver = DB::connection()->getDriverName();
            $nombres = $driver === 'sqlite'
                ? DB::table('sqlite_master')->where('type', 'trigger')->pluck('name')
                : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::raw('DATABASE()'))->pluck('TRIGGER_NAME');

            return array_diff(array_keys(self::sentencias($driver)), $nombres->all()) === [];
        });
    }

    private static function olvidarEstado(): void
    {
        Cache::store(config('offline.store', 'file'))->forget('registro_cambios_activo');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Lectura
    // ─────────────────────────────────────────────────────────────────────────

    /** El último número anotado (0 si no hay ninguno). */
    public static function ultimo(): int
    {
        return (int) DB::table(self::TABLA)->max('ID_CAMBIO');
    }

    /**
     * El cursor con el que arranca una carga COMPLETA. No es ultimo(): una transacción que
     * aún no confirmó puede tener un número menor que el último visible, y su cambio no
     * entra en la carga. Se retrocede hasta antes de lo anotado en los últimos
     * ESPERA_HUECO_SEG segundos: eso se vuelve a mandar en la siguiente sincronización
     * (repetir es inofensivo) y lo que confirme tarde entra con ello.
     */
    public static function cursorDeCargaCompleta(): int
    {
        $reciente = DB::table(self::TABLA)
            ->where('created_at', '>=', self::limiteHueco())
            ->min('ID_CAMBIO');

        return $reciente === null ? self::ultimo() : (int) $reciente - 1;
    }

    /**
     * ¿Sigue disponible el tramo que empieza después de $cursor? No, si la purga ya borró
     * filas posteriores a él: el cliente tiene que recargar entero. purgar() deja anotado
     * hasta qué número borró (fila MARCA_PURGA). No vale mirar la primera fila que queda:
     * una transacción revertida también deja un hueco al principio.
     */
    public static function cubre(int $cursor): bool
    {
        return $cursor >= (int) DB::table(self::TABLA)->where('TABLA', self::MARCA_PURGA)->max('ID_FILA');
    }

    /**
     * Lo anotado después de $cursor: [nuevo cursor, agregado → ids] o null si el tramo es
     * demasiado grande (más de MAX_FILAS) y conviene recargar entero.
     *
     * ── El cursor no siempre llega hasta la última fila ─────────────────────────────
     * Los números se reservan al INSERT pero se ven al COMMIT: dos transacciones pueden
     * publicarse en orden invertido y dejar un hueco que se llenará un instante después.
     * Si el cursor saltara por encima, esa fila no se mandaría jamás. Por eso avanza solo
     * hasta el primer hueco RECIENTE; las filas de después se mandan igual y se volverán a
     * mandar la próxima vez (repetir es inofensivo: el cliente reemplaza por id). Un hueco
     * más viejo que ESPERA_HUECO_SEG es una transacción revertida y se salta.
     *
     * @return array{0:int,1:array<string,array<int>>}|null
     */
    public static function desde(int $cursor): ?array
    {
        $filas = DB::table(self::TABLA)
            ->where('ID_CAMBIO', '>', $cursor)
            ->orderBy('ID_CAMBIO')
            ->limit(self::MAX_FILAS + 1)
            ->get(['ID_CAMBIO', 'TABLA', 'ID_FILA', 'created_at']);

        if ($filas->count() > self::MAX_FILAS) {
            return null;
        }

        $limite = self::limiteHueco();

        $nuevo   = $cursor;
        $atascado = false;
        $ids     = [];
        foreach ($filas as $f) {
            $id = (int) $f->ID_CAMBIO;
            if (! $atascado) {
                if ($id === $nuevo + 1 || (string) $f->created_at < $limite) {
                    $nuevo = $id;
                } else {
                    $atascado = true;   // hueco reciente: el cursor espera aquí
                }
            }
            if ($f->TABLA !== self::MARCA_PURGA) {
                $ids[$f->TABLA][(int) $f->ID_FILA] = true;
            }
        }

        return [$nuevo, array_map(static fn ($m) => array_keys($m), $ids)];
    }

    /** Lo anotado antes de esto ya no tiene huecos pendientes. Con el reloj de la BASE, el de created_at. */
    private static function limiteHueco(): string
    {
        return date('Y-m-d H:i:s', strtotime((string) DB::scalar('SELECT CURRENT_TIMESTAMP')) - self::ESPERA_HUECO_SEG);
    }

    /**
     * Borra lo anotado hace más de $dias días (lo llama el programador de tareas) y deja una
     * fila MARCA_PURGA con el número más alto que borró, para cubre(). Las marcas viejas se
     * borran con lo demás: la nueva siempre es mayor.
     */
    public static function purgar(int $dias = 30): int
    {
        return DB::transaction(static function () use ($dias) {
            $viejas = DB::table(self::TABLA)->where('created_at', '<', now()->subDays($dias));
            $hasta  = (clone $viejas)->max('ID_CAMBIO');
            if ($hasta === null) {
                return 0;
            }
            $borradas = $viejas->where('ID_CAMBIO', '<=', $hasta)->delete();
            DB::table(self::TABLA)->insert(['TABLA' => self::MARCA_PURGA, 'ID_FILA' => $hasta]);

            return $borradas;
        });
    }
}
