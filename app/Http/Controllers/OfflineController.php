<?php

namespace App\Http\Controllers;

use App\Casts\MojibakeFix;
use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use App\Models\Movilizacion;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Support\OfflineVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Snapshot OFFLINE — copia de solo lectura de los datos que el teléfono necesita
 * para CONSULTAR los módulos sin internet.
 *
 * NO toca nada del flujo online: solo hace SELECT (jamás INSERT/UPDATE/DELETE), y
 * el alcance se acota a los almacenes VISIBLES del usuario (Almacen::visiblesPara),
 * igual que el resto del módulo de almacén — así no se filtra data de almacenes
 * ajenos al dispositivo. El texto se pasa por MojibakeFix::fix (la MISMA lógica
 * central del proyecto) para que los acentos lleguen correctos al snapshot.
 *
 * ── Dos modos de respuesta ─────────────────────────────────────────────────
 *   · SIN `?c=` → carga completa con la forma HISTÓRICA (claves `stock`, `equipos`…
 *     en la raíz). Es lo que sigue pidiendo un cliente con el JS viejo en caché, y
 *     por eso no se puede cambiar esa forma.
 *   · CON `?c=<cursor>` → forma NUEVA: `modos` dice qué hacer con cada dominio
 *     (`sin_cambios` | `delta` | `full`), `datos` trae las filas y `borrados` las
 *     claves que el cliente debe eliminar. Bajar 1 fila cuesta ~1 KB en vez de los
 *     1.514 KB de la copia entera, que es lo que permite sincronizar seguido.
 *
 * Endpoints:
 *   · version():  barato, 0 consultas a MySQL (las huellas van cacheadas en disco).
 *   · snapshot(): los datos.
 *
 * @see \App\Support\OfflineVersion  huellas por dominio y tokens de reseteo
 */
class OfflineController extends Controller
{
    /** Tope de movimientos históricos que viajan al teléfono (los más recientes). */
    private const MAX_MOVIMIENTOS    = 1500;
    /** Tope de movilizaciones históricas que viajan al teléfono (las más recientes). */
    private const MAX_MOVILIZACIONES = 1000;

    /**
     * Holgura de la marca de agua, en segundos.
     *
     * El cursor NUNCA es `now()`. Una fila escrita dentro de una transacción larga se
     * hace visible al COMMIT, con su updated_at ya viejo: si el cursor hubiera avanzado
     * hasta el instante de la consulta, esa fila caería en un hueco y no se enviaría
     * JAMÁS. Retrasando la marca 5 minutos, cualquier transacción más corta que eso
     * queda cubierta. El precio es reenviar unas pocas filas repetidas, y reenviar de
     * más es inofensivo: la fusión del cliente es idempotente (indexa por clave).
     */
    private const HOLGURA_SEG = 300;

    /**
     * Holgura de los cursores por ID (movimientos y movilizaciones).
     *
     * Mismo problema con otra cara: los IDs se reservan al INSERT pero se hacen
     * visibles al COMMIT, así que dos transacciones concurrentes pueden publicarse en
     * orden invertido y dejar un ID menor apareciendo DESPUÉS de uno mayor. Releer los
     * últimos 50 los recupera.
     */
    private const HOLGURA_ID = 50;

    /**
     * Si un dominio cambió más de estas filas, se manda ENTERO en vez de por partes.
     *
     * A partir de cierto volumen el delta deja de ahorrar (payload parecido, pero con
     * el coste extra de fusionar en el cliente) y encima crece la ventana en la que una
     * fusión a medias podría dejar datos incoherentes. Una carga masiva por Excel cae
     * justo aquí.
     */
    private const MAX_DELTA = 600;

    // ─────────────────────────────────────────────────────────────────────────
    //  Versiones y alcance
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Huella COMBINADA de los dominios que el usuario puede ver.
     *
     * Se conserva por COMPATIBILIDAD: un cliente con el offline-sync.js viejo en caché
     * sigue leyendo `version` y funcionando igual que antes. El cliente nuevo usa el
     * mapa por dominio de `v`, que es lo que evita que un cambio de almacén invalide la
     * copia de quien solo usa equipos.
     *
     * Ya no consulta la BD: las huellas vienen cacheadas de OfflineVersion.
     */
    private function calcularVersion(Request $request): string
    {
        return substr(md5(implode('|', $this->versionesVisibles($request->user()))), 0, 12);
    }

    /**
     * Huellas SOLO de los dominios que este usuario realmente cachea.
     *
     * Es la pieza clave del aislamiento: si un usuario no tiene permisos de almacén,
     * la clave 'almacen' NO viaja, así que un movimiento de inventario le resulta
     * literalmente invisible y no dispara ninguna descarga.
     *
     * @return array<string,string>
     */
    private function versionesVisibles($user): array
    {
        $todas = OfflineVersion::todas();
        [$puedeEquipos, $puedeAlmacen] = $this->alcanceDe($user);

        $v = [];
        if ($puedeEquipos) $v['equipos'] = $todas['equipos'];
        if ($puedeAlmacen) $v['almacen'] = $todas['almacen'];

        // `catalogos` (frentes + almacenes) SOLO si el usuario recibe alguno de los dos
        // modulos: snapshot() ya deja `frentes` vacio cuando no tiene ninguno, asi que
        // incluir su huella aqui haria que un usuario sin permisos se descargase el
        // snapshot cada vez que alguien toca un frente... para recibir listas vacias.
        if ($v !== []) $v['catalogos'] = $todas['catalogos'];

        return $v;
    }

    /**
     * Alcance por permisos — snapshot ADITIVO: cada módulo viaja al teléfono solo si el
     * usuario realmente lo usa, para no inflar el caché con datos ajenos.
     *   · Equipos (flota): requiere alguna clave equipos.* (super.admin la hereda).
     *   · Almacén: requiere alguna clave almacen.* o super.admin. NO basta con que el
     *     almacén sea "visible": un usuario GLOBAL (NIVEL_ACCESO_ALMACEN=1) ve todos los
     *     almacenes, así que la visibilidad solo ACOTA cuáles viajan, no DA acceso al
     *     módulo offline (si no, todo GLOBAL bajaría almacén aunque no lo use).
     *
     * @return array{0:bool,1:bool} [puedeEquipos, puedeAlmacen]
     */
    private function alcanceDe($user): array
    {
        return [
            $user->can('equipos.create') || $user->can('equipos.edit') || $user->can('equipos.assign'),
            $user->can('almacen.productos') || $user->can('almacen.movimiento')
                || $user->can('almacen.nota.eliminar') || $user->can('super.admin'),
        ];
    }

    /**
     * Firma del ALCANCE del usuario. Si cambia, el delta deja de valer y hay que
     * remandar todo: las filas que entran o salen de su visibilidad no llevan ninguna
     * marca de "cambiadas", así que un incremental no las vería nunca.
     *
     * Se calcula SOLO con atributos ya cargados del usuario — cero consultas, que es lo
     * que permite responder `sin_cambios` sin tocar MySQL. Basta porque todo el scope
     * del proyecto sale de columnas: los niveles de acceso, el CSV de frentes asignados
     * y el CSV de frentes bloqueados. Lo único que NO es una columna suya es qué
     * almacenes ve (depende de la pivote almacen_frentes), y eso se cubre aparte con un
     * resetear('almacen') en AlmacenController::sincronizarFrentes.
     */
    private function firmaAlcance($user, bool $puedeEquipos, bool $puedeAlmacen): string
    {
        return substr(md5(implode('|', [
            (int) $puedeEquipos,
            (int) $puedeAlmacen,
            (int) ($user->NIVEL_ACCESO_EQUIPOS ?? 0),
            (int) ($user->NIVEL_ACCESO_ALMACEN ?? 0),
            (string) ($user->getAttributes()['ID_FRENTE_ASIGNADO'] ?? ''),
            (string) ($user->getAttributes()['ID_FRENTE_BLOQUEADO'] ?? ''),
        ])), 0, 8);
    }

    public function version(Request $request)
    {
        $todas = OfflineVersion::todas();

        return response()->json([
            'version' => $this->calcularVersion($request),   // compatibilidad cliente viejo
            'e'       => OfflineVersion::ESQUEMA,
            'u'       => $request->user()?->getAuthIdentifier(),
            'v'       => $this->versionesVisibles($request->user()),
            'r'       => $todas['reset'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Snapshot
    // ─────────────────────────────────────────────────────────────────────────

    public function snapshot(Request $request)
    {
        $user  = $request->user();
        $todas = OfflineVersion::todas();
        [$puedeEquipos, $puedeAlmacen] = $this->alcanceDe($user);

        $cursor = $this->leerCursor($request);

        // Sin cursor → cliente viejo (o primera carga): forma histórica, sin novedades.
        if ($cursor === null) {
            return response()->json(array_merge(
                [
                    'version'  => $this->calcularVersion($request),
                    'generado' => now()->toIso8601String(),
                ],
                $this->cargaCompleta($user, $puedeEquipos, $puedeAlmacen)
            ));
        }

        $visibles = $this->versionesVisibles($user);
        $firma    = $this->firmaAlcance($user, $puedeEquipos, $puedeAlmacen);
        $modos    = $this->decidirModos($cursor, $visibles, $todas, $firma, $user?->getAuthIdentifier());

        // La marca de agua se toma ANTES de consultar y con holgura hacia atrás.
        $marca         = now()->subSeconds(self::HOLGURA_SEG);
        $desde         = $cursor['t'] ?? null;
        $idMovimientos = max(0, ((int) ($cursor['i']['movimientos'] ?? 0)) - self::HOLGURA_ID);
        $idMovilizac   = max(0, ((int) ($cursor['i']['movilizaciones'] ?? 0)) - self::HOLGURA_ID);

        $datos    = [];
        $borrados = [];

        // Los almacenes visibles acotan TODO el inventario. Solo se consultan si hay algo
        // que hacer con almacén: si el dominio está `sin_cambios`, esto no se ejecuta.
        $almacenes = null;
        $almIds    = null;
        $verAlm    = function () use (&$almacenes, &$almIds, $user, $puedeAlmacen) {
            if ($almacenes === null) {
                $almacenes = $this->almacenesVisibles($user, $puedeAlmacen);
                $almIds    = $almacenes->pluck('ID_ALMACEN');
            }
            return $almIds;
        };

        // ── EQUIPOS ──────────────────────────────────────────────────────────
        if ($modos['equipos'] === 'full') {
            $datos['equipos']        = $this->consultaEquipos($user, $puedeEquipos);
            $datos['movilizaciones'] = $this->consultaMovilizaciones($user, $puedeEquipos);
        } elseif ($modos['equipos'] === 'delta') {
            [$cambios, $bajas] = $this->deltaEquipos($user, $desde);

            // Degradar a completo si el delta dejó de ahorrar.
            if ($cambios->count() + count($bajas) > self::MAX_DELTA) {
                $modos['equipos']        = 'full';
                $datos['equipos']        = $this->consultaEquipos($user, $puedeEquipos);
                $datos['movilizaciones'] = $this->consultaMovilizaciones($user, $puedeEquipos);
            } else {
                $datos['equipos']        = $cambios;
                $borrados['equipos']     = $bajas;
                $datos['movilizaciones'] = $this->consultaMovilizaciones($user, $puedeEquipos, $desde, $idMovilizac);
            }
        }

        // ── ALMACÉN ──────────────────────────────────────────────────────────
        if ($modos['almacen'] === 'full') {
            $datos['stock']       = $this->consultaStock($verAlm());
            $datos['productos']   = $this->consultaProductos($puedeAlmacen);
            $datos['movimientos'] = $this->consultaMovimientos($verAlm());
        } elseif ($modos['almacen'] === 'delta') {
            [$prodCambios, $prodBajas] = $this->deltaProductos($puedeAlmacen, $desde);
            $stockCambios = $this->consultaStock($verAlm(), $desde);

            if ($stockCambios->count() + $prodCambios->count() + count($prodBajas) > self::MAX_DELTA) {
                $modos['almacen']     = 'full';
                $datos['stock']       = $this->consultaStock($verAlm());
                $datos['productos']   = $this->consultaProductos($puedeAlmacen);
                $datos['movimientos'] = $this->consultaMovimientos($verAlm());
            } else {
                $datos['stock']         = $stockCambios;
                $datos['productos']     = $prodCambios;
                $borrados['productos']  = $prodBajas;
                $datos['movimientos']   = $this->consultaMovimientos($verAlm(), $desde, $idMovimientos);
            }
        }

        // ── CATÁLOGOS ────────────────────────────────────────────────────────
        // Pesan pocos KB y los usan los dos módulos: cuando cambian viajan ENTEROS. Eso
        // los libra de tener que detectar borrados, que es la parte cara del delta.
        if ($modos['catalogos'] === 'full') {
            $verAlm();
            $datos['almacenes'] = $this->mapearAlmacenes($almacenes);
            $datos['frentes']   = $this->consultaFrentes($user, $puedeEquipos, $puedeAlmacen);
        }

        return response()->json([
            'version'  => $this->calcularVersion($request),
            'e'        => OfflineVersion::ESQUEMA,
            'u'        => $user?->getAuthIdentifier(),
            'generado' => now()->toIso8601String(),
            'v'        => $visibles,
            'r'        => $todas['reset'],
            'modos'    => $modos,
            'datos'    => $datos,
            'borrados' => $borrados,
            'c'        => [
                'e' => OfflineVersion::ESQUEMA,
                'u' => $user?->getAuthIdentifier(),
                'a' => $firma,
                'v' => $visibles,
                'r' => $todas['reset'],
                't' => $marca->format('Y-m-d H:i:s'),
                'i' => [
                    'movimientos'    => $this->maxId($datos['movimientos'] ?? null, (int) ($cursor['i']['movimientos'] ?? 0)),
                    'movilizaciones' => $this->maxId($datos['movilizaciones'] ?? null, (int) ($cursor['i']['movilizaciones'] ?? 0)),
                ],
            ],
        ]);
    }

    /**
     * Lee y valida el cursor del query string. Devuelve null (→ carga completa con la
     * forma histórica) si no viene o si no es un objeto JSON.
     */
    private function leerCursor(Request $request): ?array
    {
        $crudo = $request->query('c');
        if (! is_string($crudo) || $crudo === '') {
            return null;
        }
        $c = json_decode($crudo, true);

        return is_array($c) ? $c : null;
    }

    /**
     * Qué hacer con cada dominio: `sin_cambios`, `delta` o `full`.
     *
     * Un dominio que el usuario no puede ver queda en `sin_cambios` y así no se consulta
     * ni se envía nada de él — el aislamiento por permisos también aplica aquí.
     *
     * @return array<string,string>
     */
    private function decidirModos(array $cursor, array $visibles, array $todas, string $firma, $userId): array
    {
        // Cambió el esquema, el usuario o el alcance → el cursor no vale para nada.
        $todoFull = ((int) ($cursor['e'] ?? 0)) !== OfflineVersion::ESQUEMA
            || ($cursor['a'] ?? null) !== $firma
            || (string) ($cursor['u'] ?? '') !== (string) $userId;

        $modos = [];
        foreach (['equipos', 'almacen', 'catalogos'] as $d) {
            if (! isset($visibles[$d])) {
                $modos[$d] = 'sin_cambios';   // fuera de su alcance: ni se mira
                continue;
            }
            if ($todoFull) {
                $modos[$d] = 'full';
                continue;
            }
            // Token de reseteo distinto → hubo borrados que un delta no puede deducir.
            if (isset($todas['reset'][$d]) && (int) ($cursor['r'][$d] ?? -1) !== (int) $todas['reset'][$d]) {
                $modos[$d] = 'full';
                continue;
            }
            // Sin cursor previo de ese dominio → nunca lo bajó.
            if (! isset($cursor['v'][$d]) || ! isset($cursor['t'])) {
                $modos[$d] = 'full';
                continue;
            }
            $modos[$d] = $cursor['v'][$d] === $visibles[$d] ? 'sin_cambios' : 'delta';
        }

        // `catalogos` viaja entero o nada: no tiene modo intermedio.
        if ($modos['catalogos'] === 'delta') {
            $modos['catalogos'] = 'full';
        }

        return $modos;
    }

    /** Mayor id presente en las filas enviadas; si no hubo, conserva el cursor anterior. */
    private function maxId($filas, int $previo): int
    {
        if ($filas === null) return $previo;

        return (int) max($previo, collect($filas)->max('id') ?? 0);
    }

    /**
     * Almacenes que este usuario puede ver. Acotan TODO el inventario que viaja: stock y
     * movimientos se filtran por sus IDs, así que sin acceso al módulo la lista queda
     * vacía y esas dos tablas se vacían solas.
     *
     * Punto ÚNICO: lo usan la carga completa y el delta, y tienen que coincidir — si una
     * de las dos resolviera el alcance distinto, el cliente fusionaría filas de un
     * conjunto sobre las de otro.
     */
    private function almacenesVisibles($user, bool $puedeAlmacen)
    {
        return $puedeAlmacen
            ? Almacen::visiblesPara($user)->orderBy('NOMBRE')->get(['ID_ALMACEN', 'NOMBRE', 'TIPO'])
            : collect();
    }

    /** Carga completa con la forma HISTÓRICA (cliente sin cursor). */
    private function cargaCompleta($user, bool $puedeEquipos, bool $puedeAlmacen): array
    {
        $almacenes = $this->almacenesVisibles($user, $puedeAlmacen);
        $almIds    = $almacenes->pluck('ID_ALMACEN');

        return [
            'almacenes'      => $this->mapearAlmacenes($almacenes),
            'stock'          => $this->consultaStock($almIds),
            'productos'      => $this->consultaProductos($puedeAlmacen),
            'movimientos'    => $this->consultaMovimientos($almIds),
            'equipos'        => $this->consultaEquipos($user, $puedeEquipos),
            'movilizaciones' => $this->consultaMovilizaciones($user, $puedeEquipos),
            'frentes'        => $this->consultaFrentes($user, $puedeEquipos, $puedeAlmacen),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Consultas por tabla — la MISMA definición sirve para la carga completa y
    //  para el delta; lo único que cambia es el filtro por marca de agua.
    // ─────────────────────────────────────────────────────────────────────────

    private function mapearAlmacenes($almacenes)
    {
        return $almacenes->map(static fn ($a) => [
            'id' => (int) $a->ID_ALMACEN, 'nombre' => MojibakeFix::fix($a->NOMBRE), 'tipo' => $a->TIPO,
        ])->values();
    }

    /**
     * STOCK — autocontenido: trae nombre/código/UM del producto para mostrar sin joins
     * en el front.
     *
     * En delta, el OR sobre `p.updated_at` NO es opcional: esos campos están
     * DESNORMALIZADOS aquí, así que renombrar un producto cambia lo que el teléfono
     * debe mostrar aunque la fila de stock no se haya tocado.
     *
     * Los stock BORRADOS no se detectan (no hay tombstone y la clave es compuesta): los
     * cubre el resetear('almacen') del forceDelete de producto, que es lo único que
     * cascadea a esta tabla.
     */
    private function consultaStock($almIds, ?string $desde = null)
    {
        // El saldo se lleva POR PROYECTO (ID_FRENTE), así que un producto puede tener
        // varias filas en el mismo almacén. Aquí se SUMAN: la copia offline es de solo
        // consulta y muestra "cuánto hay en el almacén", igual que la vista "Todos" de la
        // web. Se agrega en el servidor y no en el cliente a propósito — así la clave
        // (id_almacen:id_producto) de IndexedDB sigue siendo única y no hay que migrar la
        // base local de los teléfonos que ya tienen datos bajados.
        // Contrapartida asumida: sin conexión no se ve el desglose por proyecto, solo el
        // total del almacén.
        return AlmacenStock::query()
            ->join('productos_inventario as p', 'p.ID_PRODUCTO', '=', 'almacen_stock.ID_PRODUCTO')
            ->whereIn('almacen_stock.ID_ALMACEN', $almIds)
            ->when($desde, fn ($q) => $q->where(fn ($w) => $w
                ->where('almacen_stock.updated_at', '>=', $desde)
                ->orWhere('p.updated_at', '>=', $desde)))
            ->groupBy('almacen_stock.ID_ALMACEN', 'almacen_stock.ID_PRODUCTO',
                      'p.CODIGO', 'p.NOMBRE', 'p.UM', 'p.CATEGORIA')
            ->get([
                'almacen_stock.ID_ALMACEN', 'almacen_stock.ID_PRODUCTO',
                DB::raw('SUM(almacen_stock.CANTIDAD) as CANTIDAD'),
                DB::raw('MAX(almacen_stock.CANTIDAD_MINIMA) as CANTIDAD_MINIMA'),
                'p.CODIGO', 'p.NOMBRE', 'p.UM', 'p.CATEGORIA',
            ])
            ->map(static fn ($r) => [
                'id_almacen' => (int) $r->ID_ALMACEN,
                'id_producto' => (int) $r->ID_PRODUCTO,
                'cantidad' => (float) $r->CANTIDAD,
                'minima' => (float) $r->CANTIDAD_MINIMA,
                'codigo' => MojibakeFix::fix($r->CODIGO),
                'nombre' => MojibakeFix::fix($r->NOMBRE),
                'um' => MojibakeFix::fix($r->UM),
                'categoria' => MojibakeFix::fix($r->CATEGORIA),
            ])
            ->values();
    }

    /** PRODUCTOS activos (para filtros/autocomplete offline). */
    private function consultaProductos(bool $puedeAlmacen)
    {
        if (! $puedeAlmacen) return collect();

        return ProductoInventario::activos()
            ->orderBy('NOMBRE')
            ->get(['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM', 'CATEGORIA'])
            ->map(fn ($p) => $this->filaProducto($p))
            ->values();
    }

    /**
     * Delta de PRODUCTOS, partido en cambios y bajas.
     *
     * La consulta va SIN el scope activos() y CON withTrashed() a propósito: para el
     * offline, tanto pasar a INACTIVO como el borrado suave son un BORRADO, y filtrando
     * en SQL esas filas simplemente desaparecerían de la respuesta — el teléfono las
     * seguiría mostrando para siempre. Se reparte en PHP para poder mandarlas por el
     * otro lado. (ProductoInventario usa SoftDeletes: sin withTrashed, el scope global
     * las escondería.)
     *
     * @return array{0:\Illuminate\Support\Collection,1:array<int>}
     */
    private function deltaProductos(bool $puedeAlmacen, ?string $desde): array
    {
        if (! $puedeAlmacen) return [collect(), []];

        $filas = ProductoInventario::withTrashed()
            ->where('updated_at', '>=', $desde)
            ->get(['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM', 'CATEGORIA', 'ESTATUS', 'deleted_at']);

        $vivo = static fn ($p) => $p->ESTATUS === 'ACTIVO' && $p->deleted_at === null;

        $cambios = $filas->filter($vivo)
            ->map(fn ($p) => $this->filaProducto($p))
            ->values();

        $bajas = $filas->reject($vivo)
            ->map(static fn ($p) => (int) $p->ID_PRODUCTO)
            ->values()
            ->all();

        return [$cambios, $bajas];
    }

    private function filaProducto($p): array
    {
        return [
            'id' => (int) $p->ID_PRODUCTO,
            'codigo' => MojibakeFix::fix($p->CODIGO),
            'nombre' => MojibakeFix::fix($p->NOMBRE),
            'um' => MojibakeFix::fix($p->UM),
            'categoria' => MojibakeFix::fix($p->CATEGORIA),
        ];
    }

    /**
     * MOVIMIENTOS recientes (bitácora) — los MAX_MOVIMIENTOS más nuevos por registro.
     *
     * En delta se piden por ID *y* por updated_at: el ID trae las altas y updated_at las
     * ediciones de filas viejas (recalcularSaldoProducto reescribe CANTIDAD_ANTERIOR y
     * RESULTANTE; anular una nota vacía su NUMERO_NOTA), que con el ID solo eran
     * invisibles.
     */
    private function consultaMovimientos($almIds, ?string $desde = null, int $desdeId = 0)
    {
        return MovimientoInventario::query()
            ->leftJoin('productos_inventario as p', 'p.ID_PRODUCTO', '=', 'movimientos_inventario.ID_PRODUCTO')
            ->leftJoin('frentes_trabajo as f', 'f.ID_FRENTE', '=', 'movimientos_inventario.ID_FRENTE')
            ->whereIn('movimientos_inventario.ID_ALMACEN', $almIds)
            ->when($desde, fn ($q) => $q->where(fn ($w) => $w
                ->where('movimientos_inventario.ID_MOVIMIENTO', '>', $desdeId)
                ->orWhere('movimientos_inventario.updated_at', '>=', $desde)))
            ->orderByDesc('movimientos_inventario.ID_MOVIMIENTO')
            ->limit(self::MAX_MOVIMIENTOS)
            ->get([
                'movimientos_inventario.ID_MOVIMIENTO', 'movimientos_inventario.ID_ALMACEN',
                'movimientos_inventario.ID_PRODUCTO', 'movimientos_inventario.TIPO',
                'movimientos_inventario.CANTIDAD', 'movimientos_inventario.CANTIDAD_RESULTANTE',
                'movimientos_inventario.CANTIDAD_ANTERIOR',
                'movimientos_inventario.FECHA', 'movimientos_inventario.NUMERO_NOTA',
                'movimientos_inventario.ID_FRENTE',
                'p.CODIGO as PROD_CODIGO', 'p.NOMBRE as PROD_NOMBRE', 'p.UM as PROD_UM', 'f.NOMBRE_FRENTE',
            ])
            ->map(static fn ($m) => [
                'id' => (int) $m->ID_MOVIMIENTO,
                'id_almacen' => (int) $m->ID_ALMACEN,
                'id_producto' => (int) $m->ID_PRODUCTO,
                // id_frente permite el filtro por frente del kardex offline (los nombres
                // solos no bastan: el dropdown online filtra por ID).
                'id_frente' => $m->ID_FRENTE ? (int) $m->ID_FRENTE : null,
                'tipo' => $m->TIPO,
                'cantidad' => (float) $m->CANTIDAD,
                'resultante' => (float) $m->CANTIDAD_RESULTANTE,
                // anterior: clasifica los AJUSTES como entrada/salida por su signo
                // (resultante vs anterior), igual que el filtro Entradas/Salidas online.
                'anterior' => (float) $m->CANTIDAD_ANTERIOR,
                'fecha' => optional($m->FECHA)->format('Y-m-d') ?? (string) $m->FECHA,
                'nota' => $m->NUMERO_NOTA,
                'codigo' => MojibakeFix::fix($m->PROD_CODIGO),
                'producto' => MojibakeFix::fix($m->PROD_NOMBRE),
                'um' => MojibakeFix::fix($m->PROD_UM),
                'frente' => MojibakeFix::fix($m->NOMBRE_FRENTE),
            ])
            ->values();
    }

    /**
     * EQUIPOS (no se acotan por almacén; consulta de flota).
     *
     * Trae lo que muestra la tabla de /admin/equipos: tipo, frente actual (+ si está
     * FINALIZADO), seriales, placa (de documentacion) y código de patio. La FOTO no
     * viaja: vive en Drive (/storage/google) y no sirve offline → se muestra ícono.
     * Misma barrera por frente que online (whitelist LOCAL + blacklist de bloqueados):
     * el snapshot offline no debe exponer frentes que el usuario no ve en pantalla.
     */
    private function consultaEquipos($user, bool $puedeEquipos)
    {
        if (! $puedeEquipos) return collect();

        return $this->baseEquipos()
            ->when($user, fn ($q) => $user->aplicarScopeFrentesEquipos($q, 'ID_FRENTE_ACTUAL'))
            ->orderBy('NUMERO_ETIQUETA')
            ->get($this->columnasEquipo())
            ->map(fn ($e) => $this->filaEquipo($e))
            ->values();
    }

    /**
     * Delta de EQUIPOS, partido en cambios y bajas.
     *
     * La consulta va SIN el scope por frente y CON withTrashed(), y el reparto se hace
     * en PHP. Si se filtrara en SQL, un equipo que se mueve a un frente que el usuario
     * NO ve desaparecería de la respuesta en vez de llegar como borrado, y el teléfono
     * lo mostraría para siempre en un frente donde ya no está — el caso que más fácil
     * se escapa de todo el diseño.
     *
     * @return array{0:\Illuminate\Support\Collection,1:array<int>}
     */
    private function deltaEquipos($user, ?string $desde): array
    {
        $filas = $this->baseEquipos()
            ->withTrashed()
            ->where('updated_at', '>=', $desde)
            ->get(array_merge($this->columnasEquipo(), ['deleted_at']));

        $visibles   = $user ? $user->frentesVisiblesEquiposIds() : null;   // null = ve todos
        $bloqueados = $user ? array_map('intval', $user->getFrentesBloqueadosIds()) : [];

        $puedeVer = static function ($e) use ($visibles, $bloqueados) {
            $f = $e->ID_FRENTE_ACTUAL !== null ? (int) $e->ID_FRENTE_ACTUAL : null;
            if ($f !== null && in_array($f, $bloqueados, true)) return false;
            if ($visibles === null) return true;
            // Sin frente asignado no lo ve un usuario LOCAL, igual que en aplicarScopeIds.
            return $f !== null && in_array($f, array_map('intval', $visibles), true);
        };

        $cambios = $filas->filter(static fn ($e) => $e->deleted_at === null && $puedeVer($e))
            ->map(fn ($e) => $this->filaEquipo($e))
            ->values();

        $bajas = $filas->filter(static fn ($e) => $e->deleted_at !== null || ! $puedeVer($e))
            ->map(static fn ($e) => (int) $e->ID_EQUIPO)
            ->values()
            ->all();

        return [$cambios, $bajas];
    }

    private function baseEquipos()
    {
        return Equipo::query()->with([
            'tipo:id,nombre',
            'frenteActual:ID_FRENTE,NOMBRE_FRENTE,ESTATUS_FRENTE',
            'documentacion:ID_EQUIPO,PLACA',
        ]);
    }

    private function columnasEquipo(): array
    {
        return [
            'ID_EQUIPO', 'NUMERO_ETIQUETA', 'CATEGORIA_FLOTA', 'MARCA', 'MODELO', 'ANIO',
            'ESTADO_OPERATIVO', 'DETALLE_UBICACION_ACTUAL', 'SERIAL_CHASIS', 'SERIAL_DE_MOTOR',
            'CODIGO_PATIO', 'ID_FRENTE_ACTUAL', 'id_tipo_equipo', 'ID_ESPEC',
            'CONFIRMADO_EN_SITIO',
        ];
    }

    private function filaEquipo($e): array
    {
        return [
            'id' => (int) $e->ID_EQUIPO,
            'etiqueta' => MojibakeFix::fix($e->NUMERO_ETIQUETA),
            'tipo' => MojibakeFix::fix($e->tipo?->nombre),
            'categoria' => MojibakeFix::fix($e->CATEGORIA_FLOTA),
            'marca' => MojibakeFix::fix($e->MARCA),
            'modelo' => MojibakeFix::fix($e->MODELO),
            'anio' => $e->ANIO,
            'serial_chasis' => MojibakeFix::fix($e->SERIAL_CHASIS),
            'serial_motor' => MojibakeFix::fix($e->SERIAL_DE_MOTOR),
            'placa' => MojibakeFix::fix($e->documentacion?->PLACA),
            'codigo_patio' => $e->CODIGO_PATIO,
            'estado' => MojibakeFix::fix($e->ESTADO_OPERATIVO),
            'ubicacion' => MojibakeFix::fix($e->DETALLE_UBICACION_ACTUAL),
            'frente' => MojibakeFix::fix($e->frenteActual?->NOMBRE_FRENTE),
            'frente_finalizado' => $e->frenteActual && $e->frenteActual->ESTATUS_FRENTE === 'FINALIZADO',
            'id_frente' => $e->ID_FRENTE_ACTUAL ? (int) $e->ID_FRENTE_ACTUAL : null,
            'id_tipo' => $e->id_tipo_equipo ? (int) $e->id_tipo_equipo : null,
            'confirmado' => (int) ($e->CONFIRMADO_EN_SITIO ?? 0),
        ];
    }

    /**
     * MOVILIZACIONES recientes (historial de equipos).
     *
     * Lista negra: no exponer movimientos que TOQUEN un frente bloqueado (origen o
     * destino). Los NULL (recepción inicial) se conservan. Coherente con el resto.
     *
     * En delta se piden por ID *y* por updated_at, por lo mismo que los movimientos:
     * `deshacer` y las correcciones reescriben filas ya existentes.
     */
    private function consultaMovilizaciones($user, bool $puedeEquipos, ?string $desde = null, int $desdeId = 0)
    {
        if (! $puedeEquipos) return collect();

        $bloqueados = $user ? $user->getFrentesBloqueadosIds() : [];

        return Movilizacion::query()
            ->with([
                'equipo:ID_EQUIPO,NUMERO_ETIQUETA,SERIAL_CHASIS,CODIGO_PATIO,id_tipo_equipo',
                'equipo.tipo:id,nombre',
                'equipo.documentacion:ID_EQUIPO,PLACA',
                'auxiliar:ID_AUXILIAR,SERIAL,MARCA,MODELO,TIPO',
                'frenteOrigen:ID_FRENTE,NOMBRE_FRENTE',
                'frenteDestino:ID_FRENTE,NOMBRE_FRENTE',
                'usuario:ID_USUARIO,NOMBRE_COMPLETO,CORREO_ELECTRONICO',
            ])
            ->when(!empty($bloqueados), fn ($q) => $q
                ->where(fn ($w) => $w->whereNotIn('ID_FRENTE_ORIGEN', $bloqueados)->orWhereNull('ID_FRENTE_ORIGEN'))
                ->where(fn ($w) => $w->whereNotIn('ID_FRENTE_DESTINO', $bloqueados)->orWhereNull('ID_FRENTE_DESTINO')))
            ->when($desde, fn ($q) => $q->where(fn ($w) => $w
                ->where('ID_MOVILIZACION', '>', $desdeId)
                ->orWhere('updated_at', '>=', $desde)))
            ->orderByDesc('ID_MOVILIZACION')
            ->limit(self::MAX_MOVILIZACIONES)
            ->get()
            ->map(static fn ($mv) => [
                'id' => (int) $mv->ID_MOVILIZACION,
                'codigo' => $mv->formatted_codigo_control,
                'tipo' => $mv->TIPO_MOVIMIENTO,
                'fecha' => optional($mv->FECHA_DESPACHO)->format('Y-m-d H:i')
                    ?? optional($mv->created_at)->format('Y-m-d H:i')
                    ?? (string) $mv->FECHA_DESPACHO,
                'id_equipo' => $mv->ID_EQUIPO ? (int) $mv->ID_EQUIPO : null,
                // IDs para los filtros offline del historial (frente origen/destino, tipo de
                // equipo y tipo de auxiliar) — replican los filtros del index online.
                'id_origen' => $mv->ID_FRENTE_ORIGEN ? (int) $mv->ID_FRENTE_ORIGEN : null,
                'id_destino' => $mv->ID_FRENTE_DESTINO ? (int) $mv->ID_FRENTE_DESTINO : null,
                'id_tipo' => $mv->equipo?->id_tipo_equipo ? (int) $mv->equipo->id_tipo_equipo : null,
                'aux_tipo' => MojibakeFix::fix($mv->auxiliar?->TIPO),
                'equipo_tipo' => MojibakeFix::fix($mv->equipo?->tipo?->nombre),
                'equipo_serial' => MojibakeFix::fix($mv->equipo?->SERIAL_CHASIS),
                'equipo_placa' => MojibakeFix::fix($mv->equipo?->documentacion?->PLACA),
                'equipo_codigo' => $mv->equipo?->CODIGO_PATIO,
                'auxiliar' => $mv->auxiliar ? MojibakeFix::fix(trim($mv->auxiliar->MARCA . ' ' . $mv->auxiliar->MODELO)) : null,
                'origen' => MojibakeFix::fix($mv->frenteOrigen?->NOMBRE_FRENTE),
                'destino' => MojibakeFix::fix($mv->frenteDestino?->NOMBRE_FRENTE),
                'usuario' => MojibakeFix::fix($mv->usuario?->NOMBRE_COMPLETO ?? $mv->USUARIO_REGISTRO),
                'ubicacion' => MojibakeFix::fix($mv->DETALLE_UBICACION),
            ])
            ->values();
    }

    /**
     * FRENTES activos (para etiquetas/filtros) — oculta los no visibles (whitelist LOCAL
     * + blacklist de bloqueados), igual que los dropdowns online.
     *
     * Esta lista la consumen los DOS módulos offline (equipos y almacén). Por eso se
     * scopea con la UNIÓN de ambos niveles: acotarla con uno solo escondería frentes que
     * el otro módulo necesita (un GLOBAL-equipos + LOCAL-almacén perdería frentes de
     * equipos).
     */
    private function consultaFrentes($user, bool $puedeEquipos, bool $puedeAlmacen)
    {
        if (! ($puedeEquipos || $puedeAlmacen)) return collect();

        return FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->when($user, fn ($q) => $user->aplicarScopeFrentesUnion($q, 'ID_FRENTE'))
            ->orderBy('NOMBRE_FRENTE')
            ->get(['ID_FRENTE', 'NOMBRE_FRENTE'])
            ->map(static fn ($f) => [
                'id' => (int) $f->ID_FRENTE,
                'nombre' => MojibakeFix::fix($f->NOMBRE_FRENTE),
            ])
            ->values();
    }
}
