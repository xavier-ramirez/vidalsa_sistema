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
 * phpMyAdmin. No hay que adivinar qué cambió: queda escrito.
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
        // El reporte es fila propia (DominioFallas) y cambia lo que muestra su activo.
        'fallas'                 => [['fallas', 'ID_FALLA'],
                                     ['equipos', 'ACTIVO_ID', "{r}.ACTIVO_TIPO = 'equipo'"],
                                     ['auxiliares', 'ACTIVO_ID', "{r}.ACTIVO_TIPO = 'equipo_auxiliar'"]],
        // El equipo anfitrión muestra cuántos auxiliares lleva encima (subCount).
        'equipos_auxiliares'     => [['auxiliares', 'ID_AUXILIAR'], ['equipos', 'ID_EQUIPO_HOST']],
        'movilizacion_historial' => [['movilizaciones', 'ID_MOVILIZACION']],
        'frentes_trabajo'        => [['frentes', 'ID_FRENTE']],
        'tipo_equipos'           => [['tipos', 'id']],
        'catalogo_seguros'       => [['seguros', 'ID_SEGURO']],
        'caracteristicas_modelo' => [['fichas', 'ID_ESPEC']],
        'catalogo_colores'       => [['fichas', 'ID_ESPEC']],
        // Almacén (DominioAlmacen). Los nºs de parte y los modelos que usan un producto son
        // parte de su fila.
        'productos_inventario'   => [['productos', 'ID_PRODUCTO']],
        'producto_equivalencias' => [['productos', 'ID_PRODUCTO']],
        'modelo_filtro'          => [['productos', 'ID_PRODUCTO']],
        'almacen_stock'          => [['stock', 'ID_STOCK']],
        'movimientos_inventario' => [['movimientos', 'ID_MOVIMIENTO']],
        'correcciones_nota'      => [['correcciones', 'ID_CORRECCION']],
        'almacenes'              => [['almacenes', 'ID_ALMACEN']],
        'almacen_frentes'        => [['almacenes', 'ID_ALMACEN']],
        'almacen_kits'           => [['kits', 'ID_KIT']],
        'almacen_kit_items'      => [['kits', 'ID_KIT']],
        'almacen_kit_modelos'    => [['kits', 'ID_KIT']],
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
     * (activo() = false) hasta que los cree el arranque (asegurar(), con DB_ADMIN_PASSWORD)
     * o se peguen en phpMyAdmin con `php artisan cambios:sql`.
     *
     * @return array<string,string> nombre → mensaje de error
     */
    public static function crearTriggers(): array
    {
        $fallos = self::recrear(DB::connection(), self::sentencias());
        if ($fallos) {
            Log::warning('RegistroCambios: no se pudieron crear ' . count($fallos) . ' triggers; la sincronización usa el modo de respaldo.', $fallos);
        }
        self::olvidarEstado();

        return $fallos;
    }

    /**
     * Nombres de los triggers que FALTAN o cuyo cuerpo ya no es el de sentencias() (cambió
     * FUENTES y la migración que los recreaba no tuvo permiso). Vacío = todo al día.
     *
     * @return list<string>
     */
    public static function pendientes(?string $conexion = null): array
    {
        $db     = DB::connection($conexion);
        $driver = $db->getDriverName();

        if ($driver === 'sqlite') {
            $hay = $db->table('sqlite_master')->where('type', 'trigger')->pluck('name')->all();

            return array_values(array_diff(array_keys(self::sentencias($driver)), $hay));
        }

        $hay = $db->table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::raw('DATABASE()'))
            ->where('TRIGGER_NAME', 'like', 'cambios\_%')
            ->get(['TRIGGER_NAME', 'EVENT_MANIPULATION', 'EVENT_OBJECT_TABLE', 'ACTION_TIMING', 'ACTION_STATEMENT'])
            ->keyBy('TRIGGER_NAME');

        // Sin espacios, comillas invertidas ni mayúsculas: el motor puede devolver el cuerpo
        // con otro formato que el que se le dio, y eso no es un cambio.
        $norm = static fn (string $s) => strtolower(preg_replace('/[\s`]+/', '', $s));

        $pend = [];
        foreach (self::sentencias($driver) as $nombre => $sql) {
            $t = $hay->get($nombre);
            $esperado = "AFTER {$t?->EVENT_MANIPULATION} ON {$t?->EVENT_OBJECT_TABLE} FOR EACH ROW {$t?->ACTION_STATEMENT}";
            if (! $t || $t->ACTION_TIMING !== 'AFTER'
                || $norm("CREATE TRIGGER {$nombre} {$esperado}") !== $norm($sql)) {
                $pend[] = $nombre;
            }
        }

        return $pend;
    }

    /**
     * Deja los triggers al día SIN romper nada; lo corre el arranque del contenedor
     * (`php artisan cambios:sql --asegurar`, docker/start.sh). Solo toca los pendientes().
     *
     * 1. Prueba ANTES si la conexión puede crear triggers (crea y borra uno de prueba sobre
     *    `cambios`). Así nunca se borra un trigger bueno que luego no se pueda volver a crear.
     * 2. Si el usuario de la app no puede (MySQL 8 con registro binario exige SUPER), usa la
     *    conexión de administrador (config offline.triggers_admin) si está configurada, y crea
     *    los triggers A NOMBRE del usuario de la app (DEFINER): si dependieran de root, el día
     *    que root cambie todo INSERT/UPDATE de esas tablas fallaría.
     * 3. lock_wait_timeout de 3 s: CREATE/DROP TRIGGER espera a que ninguna transacción use la
     *    tabla y por defecto esperaría hasta un año; mientras espera, TODA consulta a esa tabla
     *    hace fila detrás (visto en local: un DROP esperando congeló las lecturas de equipos).
     *    Al primer bloqueo se para y lo reintenta el próximo arranque.
     *
     * NO lanza. @return array{estado:string, detalle:array<string,string>}
     *   estado: 'al_dia' | 'creados' | 'sin_permiso' | 'invisible' | 'fallos'
     */
    public static function asegurar(): array
    {
        $pend = self::pendientes();
        if ($pend === []) {
            return ['estado' => 'al_dia', 'detalle' => []];
        }

        $app     = DB::connection();
        $definer = null;
        $errApp  = self::probarPermiso($app);
        $db      = $app;

        if ($errApp !== null) {
            $admin = self::conexionAdmin();
            $errAdmin = match (true) {
                $admin !== null                                  => self::probarPermiso($admin),
                empty(config('offline.triggers_admin.password')) => 'sin credenciales de administrador (falta DB_ADMIN_PASSWORD)',
                default                                          => 'no se pudo conectar con el administrador (ver storage/logs)',
            };
            if ($errAdmin !== null) {
                return ['estado' => 'sin_permiso', 'detalle' => ['usuario de la app' => $errApp, 'administrador' => $errAdmin]];
            }
            // Al EJECUTARSE, un trigger usa los permisos de su DEFINER (el usuario de la app) y
            // exige que tenga el privilegio TRIGGER sobre la tabla. Si no lo tiene, crearlos a su
            // nombre haría fallar TODO insert/update de esas tablas: entonces no se toca nada.
            if (! self::tienePrivilegioTrigger($app)) {
                return ['estado' => 'sin_permiso', 'detalle' => ['usuario de la app' =>
                    'no tiene el privilegio TRIGGER sobre la base (GRANT TRIGGER ON <base>.* TO <usuario>); no se creó nada']];
            }
            $db = $admin;
            // information_schema solo enseña los triggers de las tablas sobre las que el
            // usuario tiene el privilegio TRIGGER: lo que falta se mira con los ojos del
            // administrador, o se recrearían todos en cada arranque.
            $pend = self::pendientes($admin->getName());
            if ($pend === []) {
                return self::vistoPorLaApp('al_dia', []);
            }
            // CURRENT_USER() del usuario de la app: 'mysql@%' → 'mysql'@'%'.
            [$u, $h] = explode('@', (string) $app->scalar('SELECT CURRENT_USER()'), 2) + [1 => '%'];
            $definer = $db->getPdo()->quote($u) . '@' . $db->getPdo()->quote($h);
        }

        $sentencias = array_intersect_key(self::sentencias($db->getDriverName()), array_flip($pend));
        if ($definer !== null) {
            $sentencias = preg_replace('/^CREATE TRIGGER /', "CREATE DEFINER={$definer} TRIGGER ", $sentencias);
        }
        $fallos = self::recrear($db, $sentencias);
        self::olvidarEstado();

        if ($fallos) {
            Log::warning('RegistroCambios::asegurar: ' . count($fallos) . ' triggers sin crear.', $fallos);

            return ['estado' => 'fallos', 'detalle' => $fallos];
        }

        return self::vistoPorLaApp('creados', array_fill_keys($pend, $definer ? 'con administrador' : 'con el usuario de la app'));
    }

    /**
     * DROP + CREATE de cada sentencia (nombre → CREATE TRIGGER). Devuelve los que fallaron.
     * Al primer bloqueo de otra transacción (1205) se para: mientras un DDL espera, TODA
     * consulta a esa tabla hace fila detrás. Lo pendiente se reintenta en el próximo arranque.
     *
     * @param  array<string,string>  $sentencias
     * @return array<string,string>
     */
    private static function recrear(\Illuminate\Database\Connection $db, array $sentencias): array
    {
        $fallos = [];
        foreach ($sentencias as $nombre => $sql) {
            try {
                $db->unprepared("DROP TRIGGER IF EXISTS {$nombre}");
                $db->unprepared($sql);
            } catch (\Throwable $e) {
                $fallos[$nombre] = $e->getMessage();
                if (str_contains($e->getMessage(), '1205') || stripos($e->getMessage(), 'lock wait timeout') !== false) {
                    break;
                }
            }
        }

        return $fallos;
    }

    /**
     * activo() mira con el usuario de la APP. Si este no tiene el privilegio TRIGGER no ve los
     * triggers aunque existan, y el sistema seguiría en el modo de respaldo: se avisa claro.
     */
    private static function vistoPorLaApp(string $estado, array $detalle): array
    {
        if (self::pendientes() !== []) {
            return ['estado' => 'invisible', 'detalle' => ['usuario de la app' =>
                'los triggers existen pero no los ve (le falta el privilegio TRIGGER sobre la base)']];
        }

        return ['estado' => $estado, 'detalle' => $detalle];
    }

    /**
     * ¿El usuario de la conexión tiene TRIGGER (o ALL) sobre esta base? Se lee de SHOW GRANTS,
     * que siempre se puede consultar del propio usuario y no toca ninguna tabla. Ante la duda
     * (un permiso que llega por un rol, un formato raro) responde false: no crear es seguro.
     */
    private static function tienePrivilegioTrigger(\Illuminate\Database\Connection $db): bool
    {
        $base = (string) $db->getDatabaseName();
        try {
            $grants = array_map(static fn ($r) => (string) array_values((array) $r)[0], $db->select('SHOW GRANTS'));
        } catch (\Throwable) {
            return false;
        }
        foreach ($grants as $g) {
            if (! preg_match('/^GRANT (.+?) ON (\S+?)\.\S+ TO /i', $g, $m)) {
                continue;
            }
            $privs = strtoupper($m[1]);
            if (! str_contains($privs, 'ALL PRIVILEGES') && ! preg_match('/(^|,\s*)TRIGGER(\s*,|$)/', $privs)) {
                continue;
            }
            // Patrón de base de MySQL: `*`, `mibase` o con comodines % y _ (\_ = guion bajo literal).
            $patron = trim($m[2], '`');
            if ($patron === '*') {
                return true;
            }
            $regex = '/^' . preg_replace_callback('/\\\\[_%]|[%_]|[^%_\\\\]+/', static fn ($t) => match ($t[0]) {
                '%' => '.*', '_' => '.', '\\_' => '_', '\\%' => '%',
                default => preg_quote($t[0], '/'),
            }, $patron) . '$/';
            if (preg_match($regex, $base)) {
                return true;
            }
        }

        return false;
    }

    /** null si la conexión puede crear y borrar triggers; si no, el error. */
    private static function probarPermiso(\Illuminate\Database\Connection $db): ?string
    {
        $nombre = 'cambios__prueba_permiso';
        try {
            $sqlite = $db->getDriverName() === 'sqlite';
            if (! $sqlite) {
                $db->unprepared('SET SESSION lock_wait_timeout = 3');
            }
            $db->unprepared("DROP TRIGGER IF EXISTS {$nombre}");
            $db->unprepared("CREATE TRIGGER {$nombre} AFTER INSERT ON " . self::TABLA . ' FOR EACH ROW '
                . ($sqlite ? 'BEGIN SELECT 1; END' : 'SET @cambios_prueba = 1'));
            $db->unprepared("DROP TRIGGER IF EXISTS {$nombre}");

            return null;
        } catch (\Throwable $e) {
            try { $db->unprepared("DROP TRIGGER IF EXISTS {$nombre}"); } catch (\Throwable) {}

            return $e->getMessage();
        }
    }

    /** Conexión con el usuario administrador (misma base y host que la app), o null. */
    private static function conexionAdmin(): ?\Illuminate\Database\Connection
    {
        $cred = config('offline.triggers_admin');
        if (empty($cred['password'])) {
            return null;
        }
        $base = config('database.connections.' . config('database.default'));
        config(['database.connections.triggers_admin' => array_merge($base, [
            'username' => $cred['username'] ?: 'root',
            'password' => $cred['password'],
            'options'  => ($base['options'] ?? []) + [\PDO::ATTR_TIMEOUT => 5],
        ])]);

        try {
            $db = DB::connection('triggers_admin');
            $db->getPdo();

            return $db;
        } catch (\Throwable $e) {
            Log::warning('RegistroCambios: no conecta el administrador de triggers: ' . $e->getMessage());

            return null;
        }
    }

    /** @param list<string>|null $tablas Solo los de estas tablas; null = todos. */
    public static function borrarTriggers(?array $tablas = null): void
    {
        foreach ($tablas ?? array_keys(self::FUENTES) as $tabla) {
            foreach (self::EVENTOS as $sufijo) {
                DB::unprepared('DROP TRIGGER IF EXISTS ' . self::nombreTrigger($tabla, $sufijo));
            }
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
     * entra en la carga. Se llega hasta el primer HUECO reciente (la misma regla que desde()):
     * lo que está antes ya confirmó y entra en la carga; lo que confirme tarde viene después.
     *
     * Antes se retrocedía hasta antes de TODO lo de los últimos ESPERA_HUECO_SEG segundos, y
     * eso volvía a llegar en la siguiente sincronización. Repetir una fila es inofensivo, pero
     * un cambio que pide la carga completa (un almacén editado, DominioAlmacen::cambios) la
     * volvía a pedir: cada equipo bajaba la copia entera en cada vuelta durante 5 minutos.
     */
    public static function cursorDeCargaCompleta(): int
    {
        $recientes = DB::table(self::TABLA)
            ->where('created_at', '>=', self::haceSegundos(self::ESPERA_HUECO_SEG))
            ->orderBy('ID_CAMBIO')
            ->limit(self::MAX_FILAS + 1)
            ->pluck('ID_CAMBIO');

        if ($recientes->isEmpty()) {
            return self::ultimo();
        }
        // Se cuenta desde la última fila VISIBLE anterior (un hueco justo antes de la primera
        // reciente también puede ser una transacción sin confirmar) o, si no hay ninguna (tabla
        // recién vaciada), desde antes de la primera reciente.
        $antes = DB::table(self::TABLA)->where('ID_CAMBIO', '<', $recientes->first())->max('ID_CAMBIO');
        $hasta = $antes === null ? (int) $recientes->first() - 1 : (int) $antes;
        if ($recientes->count() > self::MAX_FILAS) {
            return $hasta;   // demasiadas para revisarlas: como antes, desde antes de todas
        }
        foreach ($recientes as $id) {
            if ((int) $id !== $hasta + 1) {
                break;
            }
            $hasta = (int) $id;
        }

        return $hasta;
    }

    /**
     * ¿Sigue disponible el tramo que empieza después de $cursor? No, si la purga ya borró
     * filas posteriores a él: el cliente tiene que recargar entero. purgar() deja anotado
     * hasta qué número borró (fila MARCA_PURGA). No vale mirar la primera fila que queda:
     * una transacción revertida también deja un hueco al principio.
     */
    public static function cubre(int $cursor): bool
    {
        // Un cursor por ENCIMA del último anotado viene de otra base (se restauró o se importó
        // la del servidor, o se vació `cambios`): sin esto no le llegaba nada más, sin aviso,
        // hasta que la numeración nueva lo alcanzara. Recarga entera.
        return $cursor >= (int) DB::table(self::TABLA)->where('TABLA', self::MARCA_PURGA)->max('ID_FILA')
            && $cursor <= self::ultimo();
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

        $limite = self::haceSegundos(self::ESPERA_HUECO_SEG);

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

    /**
     * Fecha de hace $seg segundos con el reloj de la BASE, el mismo con el que se escribe
     * created_at: el de PHP puede ir en otra zona horaria.
     */
    private static function haceSegundos(int $seg): string
    {
        return date('Y-m-d H:i:s', strtotime((string) DB::scalar('SELECT CURRENT_TIMESTAMP')) - $seg);
    }

    /**
     * Borra lo anotado hace más de $dias días (lo llama el programador de tareas) y deja una
     * fila MARCA_PURGA con el número más alto que borró, para cubre(). Las marcas viejas se
     * borran con lo demás: la nueva siempre es mayor.
     */
    public static function purgar(int $dias = 30): int
    {
        return DB::transaction(static function () use ($dias) {
            $viejas = DB::table(self::TABLA)->where('created_at', '<', self::haceSegundos($dias * 86400));
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
