<?php

namespace App\Support;

use App\Models\AlmacenKit;
use App\Models\Equipo;
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
 * ── Un dominio ─────────────────────────────────────────────────────────────
 *   catalogos  → los kits del almacén (pesan pocos KB, por eso viajan enteros cuando
 *                cambian: así no hace falta detectar borrados en ellos)
 * Lo demás ya no viaja aquí: equipos, auxiliares y movilizaciones los lleva la copia
 * local (copia-local.js, /sync/equipos) y el inventario, la bitácora y las notas, la del
 * almacén (/sync/almacen).
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
 * de modelo y por tanto no los disparan: updates masivos y comandos artisan que
 * borran o cargan por query builder.
 * El TTL corto es el PISO que garantiza que un cambio así se detecte en ≤5 min
 * aunque nadie llame a invalidar(). No lo quites pensando que los bumps bastan.
 *
 * ── Las dos piezas de una huella y qué cubre cada una ───────────────────────
 *   · pulso    → un contador que suben los observers. Es lo que hace que la huella
 *                cambie SIEMPRE ante un evento de modelo, sin depender de que el
 *                reloj tenga resolución suficiente (ver invalidar()).
 *   · MAX(...) → cubre las escrituras SIN eventos descritas arriba, que solo se
 *                detectan al recalcular por TTL.
 * Quitar cualquiera de las dos deja un agujero distinto.
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
     *   v4 → color de la unidad (equipos) y referencia de los movimientos (almacén)
     *   v5 → reporte de falla abierto de cada equipo (aviso sobre su estado)
     *   v6 → kits del almacén (tabla `kits`, dominio catalogos)
     *   v7 → combustible de cada equipo (filtro Combustible sin conexión)
     *   v8 → sin equipos ni frentes (los lleva la copia local de /sync/equipos)
     *   v9 → solo los kits (el inventario lo lleva la copia local de /sync/almacen)
     */
    public const ESQUEMA = 9;

    /** Dominios válidos; valida el argumento de invalidar(). */
    public const DOMINIOS = ['catalogos'];

    private const CLAVE_VERSIONES = 'offline_versiones';
    private const TTL_SEGUNDOS    = 300;

    use DeDuplicaPorRequest;

    private static function store()
    {
        return Cache::store(config('offline.store', 'file'));
    }

    /**
     * Huella de cada dominio.
     *
     * Cacheado: la comprobación de frescura NO debe tocar MySQL. El recálculo solo
     * ocurre cuando alguien invalida o al vencer el TTL.
     *
     * @return array{catalogos:string}
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
     *
     * OJO con withTrashed(): Equipo y ProductoInventario usan SoftDeletes, así que un MAX()
     * normal EXCLUYE las filas borradas. Al borrar la fila que tenía el updated_at más alto,
     * el MAX volvía al valor anterior y la huella quedaba IGUAL que antes del borrado. Con
     * withTrashed la fila borrada sigue contando y su updated_at (que el borrado suave
     * actualiza) mueve la huella hacia adelante.
     */
    private static function calcular(): array
    {
        $partes = [
            self::ESQUEMA,
            (int) (self::store()->get('offline_pulso_catalogos') ?? 0),
            // Kits: altas y ediciones. El BORRADO de un kit no mueve este MAX: lo avisa el
            // pulso (AlmacenKit::booted llama a invalidar en deleted). Cada kit lleva además
            // el nombre de sus productos y las placas de sus equipos (KitAlmacenService::
            // catalogo), así que un producto o un equipo editado también lo refresca.
            AlmacenKit::max('updated_at'),
            ProductoInventario::withTrashed()->max('updated_at'),
            Equipo::withTrashed()->max('updated_at'),
        ];

        return [
            'catalogos' => substr(md5(implode('|', array_map(static fn ($v) => (string) $v, $partes))), 0, 12),
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
     * De-duplicado por request igual que CacheVersion::bump — sin esto, una carga masiva
     * de 200 productos haría 200 escrituras de caché para el mismo efecto.
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
}
