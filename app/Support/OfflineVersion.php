<?php

namespace App\Support;

use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use App\Models\Movilizacion;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use Illuminate\Support\Facades\Cache;

/**
 * Versiones del snapshot offline, SEPARADAS POR DOMINIO.
 *
 * ── El problema que resuelve ────────────────────────────────────────────────
 * Antes existía UNA sola huella que mezclaba almacén y equipos
 * (OfflineController::calcularVersion). Cualquier movimiento de almacén la
 * cambiaba, así que TODOS los clientes se re-descargaban el snapshot completo
 * (~1,5 MB, 397 ms y 22 consultas de servidor por cliente) — incluidos los 37 de
 * 48 usuarios que solo tienen permisos de equipos y que ni siquiera reciben datos
 * de almacén. Con versiones por dominio, a ese usuario un cambio de almacén le es
 * literalmente invisible.
 *
 * ── Tres dominios ──────────────────────────────────────────────────────────
 *   equipos    → equipos + movilizaciones
 *   almacen    → stock + productos + movimientos
 *   catalogos  → almacenes + frentes (los usan LOS DOS módulos y pesan pocos KB,
 *                por eso viajan enteros cuando cambian: así no hace falta detectar
 *                borrados en ellos)
 *
 * ── Por qué el caché va en el store 'file' y no en el de por defecto ────────
 * CACHE_STORE=database: cada lectura de caché sería UNA CONSULTA a MySQL, que es
 * justo lo que se quiere evitar en un endpoint que los clientes sondean seguido.
 * El store 'file' lee de disco: 0 consultas. Es coherente porque el despliegue es
 * de un solo host (supervisord con php-fpm + nginx). Si algún día se escala a
 * varios contenedores, hay que mover esto a redis: el store se lee de
 * config('offline.store') para poder cambiarlo sin tocar el código.
 *
 * ── Por qué hay TTL además de invalidación explícita ───────────────────────
 * Los bumps se disparan desde observers, pero hay rutas que escriben SIN eventos
 * de modelo y por tanto no los disparan:
 *   · EquipoController: Equipo::whereIn(...)->update(...) (bulk de ubicación,
 *     estado, etc.) — sí toca updated_at, pero no emite eventos.
 *   · MovilizacionController: Movilizacion::insert(...)
 *   · Comandos artisan que borran/cargan por query builder.
 * El TTL corto es el PISO que garantiza que un cambio así se detecte en ≤5 min
 * aunque nadie llame a invalidar(). No lo quites pensando que los bumps bastan.
 *
 * ── Las tres piezas de una huella y qué cubre cada una ──────────────────────
 *   · pulso    → un contador que suben los observers. Es lo que hace que la huella
 *                cambie SIEMPRE ante un evento de modelo, sin depender de que el
 *                reloj tenga resolución suficiente (ver invalidar()).
 *   · MAX(...) → cubre las escrituras SIN eventos descritas arriba, que solo se
 *                detectan al recalcular por TTL.
 *   · reset    → borrados que un incremental no puede deducir (ver resetear()).
 * Quitar cualquiera de las tres deja un agujero distinto.
 *
 * @see \App\Support\CacheVersion  patrón hermano (dashboard e historial)
 */
class OfflineVersion
{
    /**
     * Versión del ESQUEMA del payload. SUBIRLA FUERZA UNA RECARGA COMPLETA en todos
     * los clientes, porque entra en cada huella y además invalida sus cursores.
     * Subir cuando se agreguen o cambien campos del snapshot.
     *   v2 → ids para filtros offline (heredada del literal 'schema-v2' anterior)
     *   v3 → versiones por dominio + sincronización incremental
     */
    public const ESQUEMA = 3;

    /** Dominios válidos. El orden no importa; valida el argumento de invalidar(). */
    public const DOMINIOS = ['equipos', 'almacen', 'catalogos'];

    /**
     * Dominios que admiten reseteo (recarga completa).
     *
     * `catalogos` queda fuera porque ya viaja ENTERO cada vez que cambia: no hay nada
     * que "recargar completo" en él, sería un modo que no significa nada.
     */
    private const DOMINIOS_RESETEABLES = ['equipos', 'almacen'];

    private const CLAVE_VERSIONES = 'offline_versiones';
    private const TTL_SEGUNDOS    = 300;

    use DeDuplicaPorRequest;

    private static function store()
    {
        return Cache::store(config('offline.store', 'file'));
    }

    /**
     * Huellas de los tres dominios + los tokens de reseteo.
     *
     * Cacheado: la comprobación de frescura NO debe tocar MySQL. El recálculo solo
     * ocurre cuando alguien invalida o al vencer el TTL.
     *
     * @return array{equipos:string, almacen:string, catalogos:string, reset:array<string,int>}
     */
    public static function todas(): array
    {
        return self::store()->remember(
            self::CLAVE_VERSIONES,
            self::TTL_SEGUNDOS,
            static fn () => self::calcular()
        );
    }

    /**
     * Recalcula desde la BD. Solo MAX() sobre columnas indexadas: no trae filas.
     * (los índices de updated_at se crearon en 2026_08_01_090000 y _100000 para esto.)
     *
     * OJO con withTrashed(): Equipo, ProductoInventario y Almacen usan SoftDeletes, así
     * que un MAX() normal EXCLUYE las filas borradas. Al borrar la fila que tenía el
     * updated_at más alto, el MAX volvía al valor anterior y la huella quedaba IGUAL que
     * antes del borrado: el teléfono no se enteraba nunca de la baja. Con withTrashed la
     * fila borrada sigue contando y su updated_at (que el borrado suave actualiza) mueve
     * la huella hacia adelante.
     */
    private static function calcular(): array
    {
        $reset = [
            'equipos' => (int) (self::store()->get('offline_reset_equipos') ?? 0),
            'almacen' => (int) (self::store()->get('offline_reset_almacen') ?? 0),
        ];
        $pulso = [
            'equipos'   => (int) (self::store()->get('offline_pulso_equipos') ?? 0),
            'almacen'   => (int) (self::store()->get('offline_pulso_almacen') ?? 0),
            'catalogos' => (int) (self::store()->get('offline_pulso_catalogos') ?? 0),
        ];

        $huella = static fn (array $partes) => substr(
            md5(implode('|', array_map(static fn ($v) => (string) $v, $partes))),
            0,
            12
        );

        return [
            'equipos' => $huella([
                self::ESQUEMA,
                $reset['equipos'],
                $pulso['equipos'],
                Equipo::withTrashed()->max('updated_at'),
                Movilizacion::max('ID_MOVILIZACION'),
                // MAX(ID) detecta ALTAS; updated_at detecta EDICIONES. Hacen falta los
                // dos: `deshacer` y `anular` reescriben filas ya existentes (115 de 1198
                // filas están editadas), y con solo el ID esos cambios eran invisibles
                // hasta que vencía el TTL. Al revés tampoco basta: Movilizacion::insert()
                // (MovilizacionController:434) escribe en crudo y deja updated_at nulo.
                Movilizacion::max('updated_at'),
            ]),
            'almacen' => $huella([
                self::ESQUEMA,
                $reset['almacen'],
                $pulso['almacen'],
                MovimientoInventario::max('ID_MOVIMIENTO'),
                // Mismo motivo que en movilizaciones: recalcularSaldoProducto reescribe
                // CANTIDAD_ANTERIOR/RESULTANTE de filas viejas y anular una nota vacía su
                // NUMERO_NOTA, todo sin tocar el ID.
                MovimientoInventario::max('updated_at'),
                // updated_at y NO FECHA_ULT_MOVIMIENTO (que era lo que miraba la huella
                // global anterior): el snapshot lleva tambien CANTIDAD_MINIMA, y editar
                // el minimo NO es un movimiento, asi que no toca FECHA_ULT_MOVIMIENTO
                // pero si updated_at. Con la columna vieja, cambiar un minimo no llegaba
                // nunca a los telefonos y el KPI "bajo minimo" offline quedaba mal.
                AlmacenStock::max('updated_at'),
                ProductoInventario::withTrashed()->max('updated_at'),
            ]),
            'catalogos' => $huella([
                self::ESQUEMA,
                $pulso['catalogos'],
                Almacen::withTrashed()->max('updated_at'),
                FrenteTrabajo::max('updated_at'),
            ]),
            'reset' => $reset,
        ];
    }

    /**
     * Marca un dominio como cambiado: la próxima consulta recalcula su huella, y el
     * PULSO garantiza que además salga distinta.
     *
     * ── Por qué hace falta el pulso y no basta con recalcular ───────────────────
     * Las columnas updated_at son DATETIME, con resolución de UN SEGUNDO. Si dos filas
     * se editan dentro del mismo segundo, el MAX(updated_at) sale IDÉNTICO en las dos
     * ocasiones: la huella no cambia y el segundo cambio se vuelve invisible. Peor aún,
     * no se arregla solo — al recalcular más tarde da el mismo valor otra vez, así que
     * esa fila podía no llegar NUNCA al teléfono. Con un contador que sube en cada
     * evento, la huella cambia sí o sí, sin depender de la precisión del reloj.
     *
     * Los MAX() siguen siendo necesarios: cubren las escrituras que NO emiten eventos de
     * modelo (updates masivos por query builder, comandos artisan), donde nadie llama
     * aquí y el único aviso es el recálculo al vencer el TTL.
     *
     * El pulso es POR DOMINIO a propósito: uno global reintroduciría justo el problema
     * que todo esto viene a resolver — que un movimiento de almacén invalide la copia de
     * quien solo usa equipos.
     *
     * De-duplicado por request igual que CacheVersion::bump — sin esto, una carga masiva
     * de 200 equipos haría 200 escrituras de caché para el mismo efecto.
     *
     * @param string $dominio uno de self::DOMINIOS
     */
    public static function invalidar(string $dominio): void
    {
        if (! in_array($dominio, self::DOMINIOS, true)) {
            return;
        }
        if (! self::marcarUnaVez('pulso_' . $dominio)) {
            return;
        }

        self::subirContador('offline_pulso_' . $dominio);
        self::store()->forget(self::CLAVE_VERSIONES);
    }

    /**
     * Fuerza que un dominio se re-baje ENTERO en todos los clientes.
     *
     * Para los borrados que un delta no puede detectar por sí solo: borrado duro de
     * movimientos, borrado masivo de movilizaciones, o forceDelete de un producto
     * (que cascadea almacen_stock). En esos casos el cliente no tiene forma de saber
     * qué filas desaparecieron, así que se le pide la copia completa de ese dominio.
     */
    public static function resetear(string $dominio): void
    {
        if (! in_array($dominio, self::DOMINIOS_RESETEABLES, true)) {
            return;
        }
        self::subirContador('offline_reset_' . $dominio);
        // El token vive DENTRO de la huella, así que hay que recalcularla SI o SI,
        // aunque ya se hubiera invalidado antes en este mismo request.
        self::olvidarMarcasDelRequest();
        self::invalidar($dominio);
    }

    /**
     * Sube un contador en el store del offline (add-or-increment).
     *
     * increment() sobre una clave que NO existe devuelve false y NO la crea — con el
     * driver `database` de este proyecto está comprobado. Sin el add previo, un contador
     * recién creado (o tras un cache:clear) se quedaría clavado en su valor por defecto y
     * las huellas no cambiarían nunca. Mismo idiom que CacheVersion::bump; aquí va aparte
     * porque este store no es el de por defecto.
     */
    private static function subirContador(string $clave): void
    {
        $store = self::store();
        if (! $store->add($clave, 1)) {
            $store->increment($clave);
        }
    }

    /** Alias legible de olvidarMarcasDelRequest() (trait DeDuplicaPorRequest). */
    public static function olvidarInvalidacionDelRequest(): void
    {
        self::olvidarMarcasDelRequest();
    }
}
