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

    /** Dominios válidos. El orden no importa; se usa para validar resetear(). */
    public const DOMINIOS = ['equipos', 'almacen', 'catalogos'];

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
     * (almacen_stock.updated_at se indexó en 2026_08_01_090000 justo para esto.)
     */
    private static function calcular(): array
    {
        $reset = [
            'equipos' => (int) (self::store()->get('offline_reset_equipos') ?? 0),
            'almacen' => (int) (self::store()->get('offline_reset_almacen') ?? 0),
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
                Equipo::max('updated_at'),
                Movilizacion::max('ID_MOVILIZACION'),
            ]),
            'almacen' => $huella([
                self::ESQUEMA,
                $reset['almacen'],
                MovimientoInventario::max('ID_MOVIMIENTO'),
                // updated_at y NO FECHA_ULT_MOVIMIENTO (que era lo que miraba la huella
                // global anterior): el snapshot lleva tambien CANTIDAD_MINIMA, y editar
                // el minimo NO es un movimiento, asi que no toca FECHA_ULT_MOVIMIENTO
                // pero si updated_at. Con la columna vieja, cambiar un minimo no llegaba
                // nunca a los telefonos y el KPI "bajo minimo" offline quedaba mal.
                AlmacenStock::max('updated_at'),
                ProductoInventario::max('updated_at'),
            ]),
            'catalogos' => $huella([
                self::ESQUEMA,
                Almacen::max('updated_at'),
                FrenteTrabajo::max('updated_at'),
            ]),
            'reset' => $reset,
        ];
    }

    /**
     * Marca las versiones como obsoletas: la próxima consulta las recalcula.
     *
     * De-duplicado por request igual que CacheVersion::bump — sin esto, una carga
     * masiva de 200 equipos haría 200 escrituras de caché para el mismo efecto.
     */
    public static function invalidar(): void
    {
        if (! self::marcarUnaVez(self::CLAVE_VERSIONES)) {
            return;
        }

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
        if (! in_array($dominio, ['equipos', 'almacen'], true)) {
            return;
        }
        $clave = 'offline_reset_' . $dominio;
        $store = self::store();
        // add-or-increment: increment() sobre una clave inexistente no la crea en
        // todos los drivers (mismo idioma que CacheVersion::bump).
        if (! $store->add($clave, 1)) {
            $store->increment($clave);
        }
        // El token vive DENTRO de la huella, así que hay que recalcularla SI o SI,
        // aunque ya se hubiera invalidado antes en este mismo request.
        self::olvidarMarcasDelRequest();
        self::invalidar();
    }

    /** Alias legible de olvidarMarcasDelRequest() (trait DeDuplicaPorRequest). */
    public static function olvidarInvalidacionDelRequest(): void
    {
        self::olvidarMarcasDelRequest();
    }
}
