<?php

namespace App\Http\Controllers;

use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Services\InventarioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Módulo de Almacén / Inventario.
 *
 *  - Almacenes principales (TIPO=GENERAL) y secundarios de proyecto (TIPO=PROYECTO,
 *    ligados a frentes vía pivote `almacen_frentes`).
 *  - Catálogo global de productos (CODIGO, PRODUCTO/NOMBRE, UM, CATEGORIA).
 *  - Stock por almacén + movimientos (entradas/salidas/ajustes/traspasos) vía InventarioService.
 *
 * Visibilidad (depende ÚNICAMENTE de `usuarios.NIVEL_ACCESO`, ver Almacen::visiblesPara):
 *  - GLOBAL (NIVEL_ACCESO=1) → ve todos los almacenes. La UI abre preseleccionada en el
 *    almacén ligado a su frente (Usuario::almacenPorDefecto) pero puede filtrar a otros.
 *  - LOCAL  (NIVEL_ACCESO=2) → SOLO los almacenes PROYECTO de sus frentes; NUNCA los GENERAL.
 *  - NO depende del rol ni de permisos (super.admin / almacen.view.all no influyen aquí).
 *
 * Permisos (claves en la columna PERMISOS):
 *  - (consulta)         : cualquier usuario autenticado (alcance limitado por visiblesPara()).
 *  - super.admin        : crear / editar / eliminar almacenes (warehouses). NO da acceso
 *                         automatico a las dos operaciones de abajo — esas son explicitas.
 *  - almacen.productos  : registrar y editar productos del catálogo. Clave EXCLUSIVA: ni
 *                         siquiera super.admin pasa este gate sin la clave en sus PERMISOS.
 *  - almacen.movimiento : registrar entradas, salidas, ajustes, traspasos, mínimo
 *                         y confirmar recepción de traspasos en el destino. Clave EXCLUSIVA
 *                         igual que almacen.productos — super.admin tampoco la implica.
 */
class AlmacenController extends Controller
{
    public function __construct(
        private InventarioService $inventario,
        private \App\Services\TraspasoService $traspasos,
    ) {
        // La consulta queda bajo 'auth' (lo aplica el grupo de rutas padre). Gates:
        //   super.admin        → CRUD de almacenes (warehouses). Antes era `almacen.manage`,
        //                        consolidado bajo la clave maestra a pedido del cliente.
        //   almacen.productos  → CRUD del catalogo de productos.
        //   almacen.movimiento → registrar lotes (entradas/salidas/ajustes/traspasos)
        //                        y confirmar recepciones (merge con la antigua
        //                        `almacen.salidas_recepciones` y `traspaso.recibir`).
        $this->middleware('can:super.admin')->only([
            'storeAlmacen', 'updateAlmacen', 'destroyAlmacen',
        ]);
        $this->middleware('can:almacen.productos')->only([
            'storeProducto', 'updateProducto', 'destroyProducto',
        ]);
        $this->middleware('can:almacen.movimiento')->only([
            'registrarMovimientoLote', 'actualizarMinimo', 'previewSalidaPdf',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  Vista principal: tabla de inventario (estilo /admin/equipos)
    // ─────────────────────────────────────────────────────────────

    /**
     * Inventario de un almacén: tabla de productos (CODIGO/PRODUCTO/UM/CATEGORIA)
     * con su saldo en el almacén seleccionado + sidebar "Consolidado de Inventario".
     *
     * - HTML normal → render completo (shell + primera página de la tabla + stats).
     * - wantsJson()  → { html (filas), hasMore, nextOffset, stats, distribucionHtml, almacen }
     *   para los cambios de filtro/paginación sin recargar toda la página.
     *
     * Filtros: id_almacen, search (busca en CODIGO o NOMBRE), categoria,
     *          solo_bajo (1), solo_con_saldo (1).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $almacenes = Almacen::visiblesPara($user)
            ->orderBy('TIPO')       // GENERAL antes que PROYECTO (orden alfabético)
            ->orderBy('NOMBRE')
            ->withCount('frentes')
            ->with('frentes') // para el modal de edición de almacén (lista de frentes asociados)
            ->get();

        // Guard: usuario LOCAL (NIVEL_ACCESO=2) sin almacenes visibles → redirigir al menu
        // con notificacion. El LOCAL NO puede crear almacenes (esa accion es solo super.admin),
        // asi que entrar a una pantalla vacia donde no puede hacer nada era frustrante. El
        // GLOBAL sigue entrando aunque no haya almacenes (puede crearlos con "Nuevo almacén").
        // Se respeta el flujo AJAX (paginacion / cambio de filtro vuelven JSON) para no romperlo.
        if (!$request->wantsJson() && $almacenes->isEmpty() && (int) ($user?->NIVEL_ACCESO ?? 0) === 2) {
            return redirect()->route('menu')->with('flash_toast', [
                'type'    => 'error',
                'message' => 'Tu frente no tiene un almacén registrado. Avisa al administrador para que asocie un almacén a tu frente.',
            ]);
        }

        // Almacén seleccionado:
        //   1. El de la request si es visible (el usuario lo eligió explícitamente o llegó por link).
        //   2. El almacén PROYECTO ligado al frente del usuario (ver Usuario::almacenPorDefecto)
        //      — así un usuario de proyecto entra DIRECTO a su almacén sin tener que filtrarlo.
        //   3. El primer almacén visible (fallback histórico — útil para super.admin sin frente).
        $almacenSel = null;
        if ($request->filled('id_almacen')) {
            $almacenSel = $almacenes->firstWhere('ID_ALMACEN', (int) $request->input('id_almacen'));
        }
        if (!$almacenSel && ($idDef = $user?->almacenPorDefecto())) {
            $almacenSel = $almacenes->firstWhere('ID_ALMACEN', $idDef);
        }
        if (!$almacenSel) {
            // Fallback final: para usuarios GLOBAL sin frente o sin almacén ligado.
            $almacenSel = $almacenes->first();
        }
        $idAlmacenSel = $almacenSel?->ID_ALMACEN;
        $hayInventario = $idAlmacenSel !== null;

        // Peticiones AJAX (cambio de filtro / scroll infinito): paginación por offset.
        //   - offset=0 (o ausente) → primera carga del filtro: reemplaza la tabla y refresca
        //     stats + distribución.
        //   - offset>0 → IntersectionObserver pidió más filas: solo se devuelven html + flags
        //     (el frontend hace append y NO refresca stats).
        // Se pide $PAGE_SIZE + 1 para detectar hasMore sin un COUNT extra.
        if ($request->wantsJson()) {
            $PAGE_SIZE = 50;
            $offset = max(0, (int) $request->input('offset', 0));
            $rows = collect();
            $hasMore = false;
            if ($hayInventario) {
                $rows = $this->productosConSaldoQuery($idAlmacenSel, $request)
                    ->orderBy('productos_inventario.NOMBRE')
                    ->skip($offset)->take($PAGE_SIZE + 1)
                    ->get();
                $hasMore = $rows->count() > $PAGE_SIZE;
                if ($hasMore) $rows = $rows->slice(0, $PAGE_SIZE)->values();
            }
            // En las páginas siguientes del scroll infinito ($offset > 0) NO devolvemos la
            // empty-state row del partial — sería un mensaje "Sin coincidencias" appended al
            // final de las filas ya pintadas. Si el lote viene vacío, el html es ''.
            $html = ($offset > 0 && $rows->isEmpty())
                ? ''
                : view('admin.almacen.partials.table_rows', ['productos' => $rows, 'almacen' => $almacenSel, 'inicial' => false])->render();
            $resp = [
                'almacen'    => $almacenSel,
                'html'       => $html,
                'hasMore'    => $hasMore,
                'nextOffset' => $hasMore ? $offset + $PAGE_SIZE : null,
            ];
            // Stats y distribución solo en la primera página (offset=0) — son costosos y
            // no cambian al hacer scroll, solo cuando el usuario cambia un filtro.
            if ($offset === 0) {
                $resp['stats'] = $this->statsInventario($idAlmacenSel, $request);
                // El sidebar "Distribución de Inventario" tiene DOS modos:
                //  - normal: lista por categoria (cuando el filtro NO apunta a un producto unico)
                //  - cruzado: cuando el usuario clickeo una sugerencia (id_producto en la URL),
                //    el panel muestra ese producto en otros almacenes visibles — util para saber
                //    a donde pedir un traspaso si el almacen actual quedo en cero o bajo minimo.
                $idProductoSel = $request->filled('id_producto') ? (int) $request->input('id_producto') : null;
                $productoOtros = $idProductoSel ? $this->productoEnOtrosAlmacenes($idProductoSel, $idAlmacenSel, $user) : null;
                $productoSel   = $idProductoSel ? ProductoInventario::find($idProductoSel) : null;
                $resp['distribucionHtml'] = view('admin.almacen.partials.distribucion_stats', [
                    'distribucion'  => $this->distribucionPorCategoria($idAlmacenSel, $request),
                    'productoOtros' => $productoOtros,
                    'productoSel'   => $productoSel,
                ])->render();
            }
            return response()->json($resp);
        }

        // Carga HTML: la tabla abre VACÍA — las filas se piden por AJAX en cuanto el usuario usa un filtro.
        $categorias    = $this->categoriasDistintas();
        // Unidades de medida distintas ya registradas — alimentan el autocomplete del campo UM del modal de producto.
        $unidadesMedida = ProductoInventario::activos()
            ->select('UM')->distinct()->orderBy('UM')->pluck('UM')->filter()->values();
        $productosLista = ProductoInventario::activos()->orderBy('NOMBRE')->get(['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM']);
        // CONTRATOS se carga junto al frente para alimentar las sugerencias del campo
        // "Contrato N°" del modal "Registrar salida" (Nota de Entrega).
        $frentesLista  = \App\Models\FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->orderBy('NOMBRE_FRENTE')
            ->get(['ID_FRENTE', 'NOMBRE_FRENTE', 'CONTRATOS']);

        // Mapa { ID_ALMACEN: [ID_PRODUCTO, ...] } con los productos que TIENEN fila en
        // `almacen_stock` para cada almacén visible. Lo usa el autocompletado del filtro
        // "Buscar" en /admin/almacen para no sugerir productos que no estan en el almacen
        // actual (la tabla los filtraria via INNER JOIN y quedaria vacia, lo que confundia).
        $productosEnAlmacen = AlmacenStock::query()
            ->whereIn('ID_ALMACEN', $almacenes->pluck('ID_ALMACEN'))
            ->get(['ID_ALMACEN', 'ID_PRODUCTO'])
            ->groupBy('ID_ALMACEN')
            ->map(fn ($rows) => $rows->pluck('ID_PRODUCTO')->values());

        // NOTA: $traspasosPorRecibir (banner amarillo + badge del nav menu) NO se calcula aquí —
        // lo provee el View Composer registrado en AppServiceProvider para 'layouts.estructura_base',
        // así el badge aparece desde CUALQUIER página del sistema.

        return view('admin.almacen.index', [
            'almacenes'          => $almacenes,
            'almacenSel'         => $almacenSel,
            'productos'          => null,
            'categorias'         => $categorias,
            'productosLista'     => $productosLista,
            'productosEnAlmacen' => $productosEnAlmacen,
            'frentesLista'       => $frentesLista,
            // Stats y distribución NO se calculan en la carga inicial:
            // se obtienen por AJAX la primera vez que el usuario aplica un filtro.
            'stats'              => null,
            'distribucion'       => collect(),
            'unidadesMedida'     => $unidadesMedida,
        ]);
    }

    /**
     * Query base del inventario: productos_inventario activos + INNER JOIN del
     * stock del almacén dado + filtros del listado. SIN columnas explícitas:
     * el llamador añade el select que necesita (filas / count / agregado).
     *
     * INNER JOIN (no LEFT): un producto sólo aparece en un almacén si tiene fila
     * en `almacen_stock` para ese almacén — es decir, si alguien YA registró un
     * movimiento (entrada / traspaso entrada / ajuste) o le fijó un stock mínimo
     * ahí. Un almacén recién creado abre vacío hasta que llegue el primer envío,
     * en vez de mostrar el catálogo global con saldo=0 (que confundía).
     */
    private function inventarioBaseQuery(?int $idAlmacen, Request $request)
    {
        $q = ProductoInventario::query()->activos();

        $q->join('almacen_stock', function ($j) use ($idAlmacen) {
            $j->on('almacen_stock.ID_PRODUCTO', '=', 'productos_inventario.ID_PRODUCTO');
            if ($idAlmacen !== null) {
                $j->where('almacen_stock.ID_ALMACEN', '=', $idAlmacen);
            } else {
                $j->whereRaw('1 = 0'); // sin almacén → no devolver nada
            }
        });

        // ─── Modo "Ver solo seleccionados" del bulk counter ────────────────────────
        // El frontend manda los IDs ya seleccionados como CSV en `id_producto_in`.
        // Cuando esta presente, la query se ACOTA EXCLUSIVAMENTE a esos productos e
        // IGNORA los demas filtros de contenido (search, categoria, id_producto,
        // solo_bajo, solo_con_saldo). Razon: el usuario quiere ver SU seleccion sin
        // que se la recorten los filtros que estaban activos cuando seleccionaba
        // (ej: selecciono 5 productos navegando por 2 categorias distintas → al
        // activar "solo seleccionados" quiere ver los 5, no la interseccion con la
        // categoria activa). id_almacen NO se ignora porque la seleccion vive en
        // el contexto del almacen actual de la tabla.
        if ($request->filled('id_producto_in')) {
            $ids = collect(explode(',', (string) $request->input('id_producto_in')))
                ->map(fn ($s) => (int) trim($s))
                ->filter(fn ($n) => $n > 0)
                ->unique()
                ->values()
                ->all();
            if (!empty($ids)) {
                $q->whereIn('productos_inventario.ID_PRODUCTO', $ids);
                return $q; // short-circuit: ignoramos los demas filtros
            }
        }

        // `id_producto`: match EXACTO — lo envía el filtro "Descripción" cuando el usuario
        // hace clic en una sugerencia (quiere VER esa fila concreta, no las similares).
        // Tiene precedencia sobre `search` (que sí hace LIKE %term% con tokens y plural).
        if ($request->filled('id_producto')) {
            $q->where('productos_inventario.ID_PRODUCTO', '=', (int) $request->input('id_producto'));
        } elseif ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            // (cae aquí solo si NO vino id_producto — typed-and-Enter, no clic en sugerencia)
            // Búsqueda flexible: tokeniza por espacios, AND entre tokens, y para cada token
            // intenta también el SINGULAR (sin 'S' final si tiene >3 letras) — así "BOTAS"
            // encuentra "BOTA DE SEGURIDAD" sin que el usuario tenga que escribir el singular.
            $tokens = array_values(array_filter(preg_split('/\s+/', $term)));
            $q->where(function ($s) use ($tokens) {
                foreach ($tokens as $tok) {
                    $variantes = [$tok];
                    if (mb_strlen($tok) > 3 && mb_strtoupper(mb_substr($tok, -1)) === 'S') {
                        $variantes[] = mb_substr($tok, 0, -1);
                    }
                    $s->where(function ($t) use ($variantes) {
                        foreach ($variantes as $v) {
                            $t->orWhere('productos_inventario.CODIGO', 'like', "%{$v}%")
                              ->orWhere('productos_inventario.NOMBRE', 'like', "%{$v}%");
                        }
                    });
                }
            });
        }
        if ($request->filled('categoria') && $request->input('categoria') !== 'all') {
            // Coincidencia parcial (igual que "search"): el filtro de categoría es un
            // input de texto con sugerencias, así que se va estrechando conforme se escribe.
            $cat = trim((string) $request->input('categoria'));
            $q->where('productos_inventario.CATEGORIA', 'like', "%{$cat}%");
        }
        if ($request->boolean('solo_bajo')) {
            $q->whereNotNull('almacen_stock.CANTIDAD_MINIMA')
              ->whereColumn('almacen_stock.CANTIDAD', '<=', 'almacen_stock.CANTIDAD_MINIMA');
        }
        if ($request->boolean('solo_con_saldo')) {
            $q->where('almacen_stock.CANTIDAD', '>', 0);
        }

        return $q;
    }

    /** Query del listado (con las columnas que la tabla muestra). */
    private function productosConSaldoQuery(?int $idAlmacen, Request $request)
    {
        return $this->inventarioBaseQuery($idAlmacen, $request)->select([
            'productos_inventario.ID_PRODUCTO',
            'productos_inventario.CODIGO',
            'productos_inventario.NOMBRE',
            'productos_inventario.UM',
            'productos_inventario.CATEGORIA',
            'productos_inventario.UBICACION',
            DB::raw('COALESCE(almacen_stock.CANTIDAD, 0) as saldo'),
            'almacen_stock.CANTIDAD_MINIMA as minimo',
            'almacen_stock.FECHA_ULT_MOVIMIENTO as fecha_ult_mov',
        ]);
    }

    /** Consolidado del almacén seleccionado (respeta los filtros activos). */
    private function statsInventario(?int $idAlmacen, Request $request): array
    {
        if ($idAlmacen === null) {
            return ['total' => 0, 'con_saldo' => 0, 'stock_bajo' => 0, 'unidades' => 0.0];
        }

        $total     = $this->inventarioBaseQuery($idAlmacen, $request)->count('productos_inventario.ID_PRODUCTO');
        $conSaldo  = $this->inventarioBaseQuery($idAlmacen, $request)->where('almacen_stock.CANTIDAD', '>', 0)->count('productos_inventario.ID_PRODUCTO');
        $stockBajo = $this->inventarioBaseQuery($idAlmacen, $request)
            ->whereNotNull('almacen_stock.CANTIDAD_MINIMA')
            ->whereColumn('almacen_stock.CANTIDAD', '<=', 'almacen_stock.CANTIDAD_MINIMA')
            ->count('productos_inventario.ID_PRODUCTO');
        $unidades  = (float) AlmacenStock::where('ID_ALMACEN', $idAlmacen)->sum('CANTIDAD');

        return [
            'total'      => (int) $total,
            'con_saldo'  => (int) $conSaldo,
            'stock_bajo' => (int) $stockBajo,
            'unidades'   => $unidades,
        ];
    }

    /** Distribución de productos por categoría en el almacén seleccionado. */
    private function distribucionPorCategoria(?int $idAlmacen, Request $request)
    {
        if ($idAlmacen === null) {
            return collect();
        }
        return $this->inventarioBaseQuery($idAlmacen, $request)
            ->select(DB::raw("COALESCE(NULLIF(TRIM(productos_inventario.CATEGORIA), ''), 'SIN CATEGORÍA') as categoria"))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(almacen_stock.CANTIDAD), 0) as unidades')
            ->groupBy('categoria')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * Inventario de UN producto especifico en TODOS los demas almacenes visibles para
     * el usuario (excluye el almacen actual — que ya se ve en la tabla principal).
     *
     * Lo consume el sidebar "Distribución de Inventario" cuando el usuario clickea
     * una sugerencia del filtro "Buscar" (id_producto en la URL): asi puede ver, en
     * un solo vistazo, donde mas existe el producto y pedir un traspaso al almacen
     * que tenga saldo si el actual se quedo corto.
     *
     * INNER JOIN con `almacenes` para descartar filas huerfanas (almacen eliminado
     * via soft-delete) y obtener el nombre/tipo para pintar la lista.
     */
    private function productoEnOtrosAlmacenes(int $idProducto, ?int $idAlmacenActual, $user)
    {
        $visibles = Almacen::visiblesPara($user)->pluck('almacenes.ID_ALMACEN');
        if ($visibles->isEmpty()) {
            return collect();
        }
        $q = AlmacenStock::query()
            ->join('almacenes', 'almacenes.ID_ALMACEN', '=', 'almacen_stock.ID_ALMACEN')
            ->where('almacen_stock.ID_PRODUCTO', $idProducto)
            ->whereIn('almacen_stock.ID_ALMACEN', $visibles);
        if ($idAlmacenActual !== null) {
            $q->where('almacen_stock.ID_ALMACEN', '!=', $idAlmacenActual);
        }
        return $q->select(
                'almacenes.ID_ALMACEN',
                'almacenes.NOMBRE',
                'almacenes.TIPO',
                'almacen_stock.CANTIDAD',
                'almacen_stock.CANTIDAD_MINIMA'
            )
            ->orderByDesc('almacen_stock.CANTIDAD')
            ->orderBy('almacenes.NOMBRE')
            ->get();
    }

    // ─────────────────────────────────────────────────────────────
    //  Almacenes
    // ─────────────────────────────────────────────────────────────

    public function storeAlmacen(Request $request)
    {
        $data = $this->validarAlmacen($request);
        $data['CREADO_POR'] = optional($request->user())->ID_USUARIO;

        $almacen = DB::transaction(function () use ($data, $request) {
            $almacen = Almacen::create($data);
            $this->syncFrentes($almacen, $request->input('frentes', []));
            return $almacen;
        });

        return response()->json([
            'message' => 'Almacén creado.',
            'almacen' => $almacen->load('frentes'),
        ], 201);
    }

    public function updateAlmacen(Request $request, int $id)
    {
        $almacen = Almacen::findOrFail($id);
        $data    = $this->validarAlmacen($request, $almacen->ID_ALMACEN);

        DB::transaction(function () use ($almacen, $data, $request) {
            $almacen->update($data);
            if ($request->has('frentes')) {
                $this->syncFrentes($almacen, $request->input('frentes', []));
            }
        });

        return response()->json([
            'message' => 'Almacén actualizado.',
            'almacen' => $almacen->fresh()->load('frentes'),
        ]);
    }

    public function destroyAlmacen(int $id)
    {
        $almacen = Almacen::findOrFail($id);

        if ($almacen->movimientos()->exists()) {
            // Tiene historial: no se borra, se desactiva.
            $almacen->update(['ESTATUS' => 'INACTIVO']);
            return response()->json(['message' => 'El almacén tiene movimientos registrados; se marcó como INACTIVO en lugar de eliminarse.']);
        }

        $almacen->delete(); // soft delete
        return response()->json(['message' => 'Almacén eliminado.']);
    }

    // ─────────────────────────────────────────────────────────────
    //  Productos (catálogo global)
    // ─────────────────────────────────────────────────────────────

    /** Lista (Collection) de categorías distintas del catálogo, ordenadas. */
    private function categoriasDistintas()
    {
        return ProductoInventario::query()
            ->whereNotNull('CATEGORIA')->where('CATEGORIA', '!=', '')
            ->distinct()->orderBy('CATEGORIA')->pluck('CATEGORIA');
    }

    public function storeProducto(Request $request)
    {
        $data = $this->validarProducto($request);

        // Stock inicial opcional: si el cliente pasa `id_almacen`, el producto queda
        // registrado en ese almacén (fila en almacen_stock) — así aparece de inmediato
        // en la tabla de inventario aunque tenga saldo 0, sin esperar a un primer
        // movimiento. Si además pasa `cantidad_inicial > 0`, se registra una ENTRADA
        // en el kardex (requiere permiso almacen.movimiento).
        $extra = $request->validate([
            'id_almacen'       => 'nullable|integer|exists:almacenes,ID_ALMACEN',
            'cantidad_inicial' => 'nullable|numeric|min:0',
        ]);
        $idAlmacen   = $extra['id_almacen']       ?? null;
        $cantInicial = (float) ($extra['cantidad_inicial'] ?? 0);

        if ($cantInicial > 0 && ! $request->user()?->can('almacen.movimiento')) {
            return response()->json([
                'message' => 'No tienes permiso para registrar movimientos de inventario (cantidad inicial > 0).',
            ], 403);
        }

        // Código opcional: si no se escribió, se genera automáticamente (PRD-####).
        // Si se escribió, se respeta tal cual (sirve para importar los códigos que la gente ya tiene en su Excel).
        if (empty($data['CODIGO'])) {
            $data['CODIGO'] = $this->generarCodigoProducto();
        }
        $data['CREADO_POR'] = optional($request->user())->ID_USUARIO;

        $producto = DB::transaction(function () use ($data, $idAlmacen, $cantInicial, $request) {
            $producto = ProductoInventario::create($data);

            if ($idAlmacen) {
                // Crea la fila almacen_stock con CANTIDAD=0 si no existía (idempotente).
                $this->inventario->asegurarStock($idAlmacen, $producto->ID_PRODUCTO);

                if ($cantInicial > 0) {
                    $this->inventario->registrarEntrada(
                        $idAlmacen,
                        $producto->ID_PRODUCTO,
                        $cantInicial,
                        ['referencia' => 'STOCK INICIAL', 'motivo' => 'Stock inicial al crear el producto']
                    );
                }
            }

            return $producto;
        });

        return response()->json(['message' => 'Producto creado.', 'producto' => $producto], 201);
    }

    public function updateProducto(Request $request, int $id)
    {
        $producto = ProductoInventario::findOrFail($id);
        $data = $this->validarProducto($request, $producto->ID_PRODUCTO);
        if (empty($data['CODIGO'])) {
            unset($data['CODIGO']); // si viene vacío al editar, se conserva el código actual
        }
        $producto->update($data);

        return response()->json(['message' => 'Producto actualizado.', 'producto' => $producto->fresh()]);
    }

    /**
     * Genera el siguiente código automático para un producto: "PRD-####", tomando
     * el mayor número usado en códigos de ese formato + 1. Incluye soft-deleted en
     * la verificación porque el índice UNIQUE de CODIGO también los ocupa.
     */
    private function generarCodigoProducto(): string
    {
        $maxNum = 0;
        ProductoInventario::withTrashed()
            ->where('CODIGO', 'like', 'PRD-%')
            ->pluck('CODIGO')
            ->each(function ($cod) use (&$maxNum) {
                if (preg_match('/^PRD-(\d+)$/i', (string) $cod, $m)) {
                    $maxNum = max($maxNum, (int) $m[1]);
                }
            });

        $n = $maxNum + 1;
        do {
            $codigo = 'PRD-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while (ProductoInventario::withTrashed()->where('CODIGO', $codigo)->exists());

        return $codigo;
    }

    public function destroyProducto(int $id)
    {
        $producto = ProductoInventario::findOrFail($id);

        $tieneSaldo      = $producto->stock()->where('CANTIDAD', '>', 0)->exists();
        $tieneMovimiento = $producto->movimientos()->exists();

        if ($tieneSaldo || $tieneMovimiento) {
            $producto->update(['ESTATUS' => 'INACTIVO']);
            return response()->json(['message' => 'El producto tiene saldo o movimientos; se marcó como INACTIVO en lugar de eliminarse.']);
        }

        $producto->delete(); // soft delete
        return response()->json(['message' => 'Producto eliminado.']);
    }

    // ─────────────────────────────────────────────────────────────
    //  Stock por almacén
    // ─────────────────────────────────────────────────────────────

    /** Define / quita el stock mínimo (umbral de alerta) de un producto en un almacén. */
    public function actualizarMinimo(Request $request, int $idAlmacen)
    {
        $request->validate([
            'id_producto'     => 'required|integer|exists:productos_inventario,ID_PRODUCTO',
            // El mínimo de alerta debe ser > 0 si se especifica. Para "sin alerta" se manda null.
            'cantidad_minima' => 'nullable|numeric|gt:0',
        ]);
        $this->assertPuedeVerAlmacen($request, $idAlmacen);

        // asegurarStock() crea (si no existe) la fila y aplica el mínimo en la misma transacción.
        // Pasar null borra el mínimo; pasar un float lo fija.
        $minimo = $request->filled('cantidad_minima') ? (float) $request->input('cantidad_minima') : null;
        $stock  = $this->inventario->asegurarStock($idAlmacen, $request->integer('id_producto'), $minimo, /*forzarMinimo*/ true);

        return response()->json(['message' => 'Stock mínimo actualizado.', 'stock' => $stock->load('producto')]);
    }

    // ─────────────────────────────────────────────────────────────
    //  Movimientos (kardex) — módulo aparte: /admin/almacen/movimientos
    // ─────────────────────────────────────────────────────────────

    /**
     * Bitácora de movimientos de inventario.
     * Filtros: id_almacen, search (código/nombre del producto), id_producto (exacto),
     *          tipo (ENTRADA|SALIDA|AJUSTE|TRASPASO_ENTRADA|TRASPASO_SALIDA), id_frente, desde, hasta.
     *
     *  - HTML normal → página completa (shell + filtros + primera página de la tabla + contador).
     *  - wantsJson() → { html (filas <tr>), pagination, total } para los filtros/paginación por AJAX.
     */
    public function movimientos(Request $request)
    {
        // Lista de almacenes visibles para el usuario: la usamos (a) para validar el
        // default-por-frente, (b) para acotar la query cuando se ve "TODOS LOS ALMACENES"
        // y (c) para pintar el dropdown de filtro. Una sola consulta por request.
        $almacenes    = Almacen::visiblesPara($request->user())->orderBy('TIPO')->orderBy('NOMBRE')->get(['ID_ALMACEN', 'NOMBRE', 'TIPO']);
        $visiblesIds  = $almacenes->pluck('ID_ALMACEN');

        // Guard: LOCAL sin almacenes visibles → redirigir al menu con notificacion.
        // (Mismo razonamiento que index(): un LOCAL sin almacen asignado no puede tomar
        // ninguna accion util en la bitacora, mejor avisarle que falta configuracion.)
        if (!$request->wantsJson() && $almacenes->isEmpty() && (int) ($request->user()?->NIVEL_ACCESO ?? 0) === 2) {
            return redirect()->route('menu')->with('flash_toast', [
                'type'    => 'error',
                'message' => 'Tu frente no tiene un almacén registrado. Avisa al administrador para que asocie un almacén a tu frente.',
            ]);
        }

        // Default suave del filtro de almacén:
        //   - Si el cliente NO mandó un `id_almacen` con valor (primera vez que abre / link sin params /
        //     ?id_almacen=) y el usuario tiene un almacén ligado a su frente → lo aplicamos.
        //   - `id_almacen=all` o un valor explícito → se respetan.
        // Usamos `filled` (no `has`) para que también aplique cuando el param viene en la URL pero vacío
        // (caso edge si una navegación interna arma `?id_almacen=&search=X`).
        // Validamos además que el default sea VISIBLE para el usuario — si por alguna razón el
        // almacén ligado al frente quedó fuera de los visibles, no lo aplicamos (evita un filtro
        // fantasma que oculta los movimientos del usuario).
        if (!$request->filled('id_almacen') && ($idDef = $request->user()?->almacenPorDefecto())) {
            if ($visiblesIds->contains((int) $idDef)) {
                $request->merge(['id_almacen' => $idDef]);
            }
        }

        $q = MovimientoInventario::query()
            ->with(['producto:ID_PRODUCTO,CODIGO,NOMBRE,UM', 'almacen:ID_ALMACEN,NOMBRE,TIPO', 'almacenContraparte:ID_ALMACEN,NOMBRE', 'usuario:ID_USUARIO,NOMBRE_COMPLETO', 'frente:ID_FRENTE,NOMBRE_FRENTE']);

        if ($request->filled('id_almacen') && $request->input('id_almacen') !== 'all') {
            $idAlmacen = $request->integer('id_almacen');
            $this->assertPuedeVerAlmacen($request, $idAlmacen);
            $q->where('ID_ALMACEN', $idAlmacen);
        } else {
            // Limitar a almacenes visibles para el usuario.
            $q->whereIn('ID_ALMACEN', $visiblesIds);
        }
        if ($request->filled('id_producto')) {
            $q->where('ID_PRODUCTO', $request->integer('id_producto'));
        }
        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $q->whereHas('producto', function ($p) use ($term) {
                $p->where('CODIGO', 'like', "%{$term}%")->orWhere('NOMBRE', 'like', "%{$term}%");
            });
        }
        if ($request->filled('tipo') && $request->input('tipo') !== 'all') {
            $q->where('TIPO', $request->string('tipo'));
        }
        if ($request->filled('id_frente') && $request->input('id_frente') !== 'all') {
            $q->where('ID_FRENTE', $request->integer('id_frente'));
        }
        $q->periodo($request->input('desde'), $request->input('hasta'));

        $paginator = $q->orderByDesc('FECHA')->orderByDesc('ID_MOVIMIENTO')
            ->paginate($request->integer('per_page') ?: 50)->withQueryString();

        if ($request->wantsJson()) {
            // El modal "Movimientos del producto" pide ?mini=1 para usar el partial
            // de 5 columnas (sin la columna Producto, que sería redundante allí).
            $partial = $request->boolean('mini')
                ? 'admin.almacen.partials.kardex_rows_mini'
                : 'admin.almacen.partials.kardex_rows';

            return response()->json([
                'html'       => view($partial, ['movimientos' => $paginator])->render(),
                'pagination' => (string) $paginator->links('vendor.pagination.custom-sliding'),
                'consumo'    => view('admin.almacen.partials.consumo_stats', ['consumo' => $this->consumoRanking($request)])->render(),
                'total'      => $paginator->total(),
            ]);
        }

        // `idAlmacenActivo`: el valor REAL del filtro tras el default-merge del controller —
        // se lo pasamos a la vista para que el dropdown del header NO dependa del helper
        // request() (que en algunos entornos no refleja el merge al renderizar el blade).
        $idAlmacenActivo = ($request->filled('id_almacen') && $request->input('id_almacen') !== 'all')
            ? (int) $request->input('id_almacen')
            : null;
        return view('admin.almacen.movimientos', [
            'movimientos'     => $paginator,
            'total'           => $paginator->total(),
            'almacenes'       => $almacenes,
            'idAlmacenActivo' => $idAlmacenActivo,
            'frentesLista'    => \App\Models\FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE')->get(['ID_FRENTE', 'NOMBRE_FRENTE']),
            // Lista de productos activos para el autocomplete del filtro de búsqueda.
            'productosLista'  => ProductoInventario::activos()->orderBy('NOMBRE')->get(['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM']),
            // Ranking de productos más consumidos (SALIDA + TRASPASO_SALIDA) aplicando los
            // mismos filtros visibles. Alimenta el sidebar "Consumo de Inventario".
            'consumo'         => $this->consumoRanking($request),
        ]);
    }

    /**
     * Vista alterna de la bitácora — agrupada por NUMERO_NOTA (una fila por
     * Nota de Entrega de Materiales). Sólo lista movimientos SALIDA / TRASPASO_SALIDA
     * con N° NE-YYYY-NNNN asignado (los que tienen PDF oficial VID-FO-GEN-019).
     * El clic en una fila abre el PDF en el visor in-page (window.openPdfPreview).
     *
     * Filtros aceptados: id_almacen, id_frente, tipo (SALIDA|TRASPASO_SALIDA), search
     * (NUMERO_NOTA / NUMERO_RQ / NUMERO_CONTRATO / SOLICITANTE), desde, hasta.
     */
    public function notas(Request $request)
    {
        $almacenes   = Almacen::visiblesPara($request->user())->orderBy('TIPO')->orderBy('NOMBRE')->get(['ID_ALMACEN', 'NOMBRE', 'TIPO']);
        $visiblesIds = $almacenes->pluck('ID_ALMACEN');

        // Default-merge por almacén ligado al frente (mismo patrón que movimientos()).
        if (!$request->filled('id_almacen') && ($idDef = $request->user()?->almacenPorDefecto())) {
            if ($visiblesIds->contains((int) $idDef)) {
                $request->merge(['id_almacen' => $idDef]);
            }
        }

        // Lista de categorías para el filtro avanzado (mismo helper que /admin/almacen).
        $categorias = $this->categoriasDistintas();

        // Sólo tipos que tienen Nota de Entrega con PDF.
        $tiposNota = [
            MovimientoInventario::TIPO_SALIDA,
            MovimientoInventario::TIPO_TRASPASO_SALIDA,
        ];
        $tipoFiltro = $request->input('tipo');
        $tiposAplicar = ($tipoFiltro && in_array($tipoFiltro, $tiposNota, true)) ? [$tipoFiltro] : $tiposNota;

        // Subquery a nivel de NUMERO_NOTA. Aggregamos solo los campos que se muestran
        // en la tabla — el "tipo" se decide por MIN porque todas las líneas de una nota
        // comparten tipo (registrarMovimientoLote sólo asigna NUMERO_NOTA a un único TIPO).
        // Ya no calculamos COUNT(*) NI SUM(CANTIDAD) — esas columnas "N° Líneas" y
        // "Cant. total" se quitaron de la tabla (la info vive dentro del propio PDF).
        $q = MovimientoInventario::query()
            ->selectRaw(
                'NUMERO_NOTA,'
                . ' MIN(FECHA)                    AS FECHA,'
                . ' MIN(TIPO)                     AS TIPO,'
                . ' MIN(ID_ALMACEN)               AS ID_ALMACEN,'
                . ' MIN(ID_ALMACEN_CONTRAPARTE)   AS ID_ALMACEN_CONTRAPARTE,'
                . ' MIN(ID_FRENTE)                AS ID_FRENTE,'
                . ' MAX(ID_MOVIMIENTO)            AS ULT_ID'
            )
            ->whereIn('TIPO', $tiposAplicar)
            ->whereNotNull('NUMERO_NOTA')
            ->where('NUMERO_NOTA', '!=', '')
            ->groupBy('NUMERO_NOTA');

        if ($request->filled('id_almacen') && $request->input('id_almacen') !== 'all') {
            $idAlmacen = $request->integer('id_almacen');
            $this->assertPuedeVerAlmacen($request, $idAlmacen);
            $q->where('ID_ALMACEN', $idAlmacen);
        } else {
            $q->whereIn('ID_ALMACEN', $visiblesIds);
        }
        if ($request->filled('id_frente') && $request->input('id_frente') !== 'all') {
            $q->where('ID_FRENTE', $request->integer('id_frente'));
        }
        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $q->where(function ($w) use ($term) {
                $w->where('NUMERO_NOTA',     'like', "%{$term}%")
                  ->orWhere('NUMERO_RQ',     'like', "%{$term}%")
                  ->orWhere('NUMERO_CONTRATO','like', "%{$term}%")
                  ->orWhere('SOLICITANTE',    'like', "%{$term}%");
            });
        }
        // Filtro por categoría del producto (misma semántica que /admin/almacen): si una
        // NOTA tiene al menos una línea cuyo producto pertenece a esa categoría, se incluye.
        // Implementado con whereExists para no ensuciar el SELECT/GROUP BY del aggregate.
        if ($request->filled('categoria') && $request->input('categoria') !== 'all') {
            $cat = trim((string) $request->input('categoria'));
            $q->whereExists(function ($sub) use ($cat) {
                $sub->select(DB::raw(1))
                    ->from('productos_inventario as p')
                    ->whereColumn('p.ID_PRODUCTO', 'movimientos_inventario.ID_PRODUCTO')
                    ->where('p.CATEGORIA', 'like', "%{$cat}%");
            });
        }
        $q->periodo($request->input('desde'), $request->input('hasta'));

        // La fila más reciente arriba.
        $q->orderByDesc('FECHA')->orderByDesc('ULT_ID');

        // paginate() sobre groupBy: Laravel hace el COUNT con DISTINCT internamente.
        $paginator = $q->paginate($request->integer('per_page') ?: 50)->withQueryString();

        // Eager load de los almacenes (origen + contraparte) y frentes referenciados en
        // las filas de esta página — UN solo viaje por relación. NO cargamos usuarios
        // porque la vista no muestra "registrado por" (cabe en el PDF, no en la tabla).
        $idsAlm   = $paginator->getCollection()->pluck('ID_ALMACEN')->merge($paginator->getCollection()->pluck('ID_ALMACEN_CONTRAPARTE'))->filter()->unique()->values();
        $idsFre   = $paginator->getCollection()->pluck('ID_FRENTE')->filter()->unique()->values();
        $almById  = $idsAlm->isEmpty() ? collect() : Almacen::whereIn('ID_ALMACEN', $idsAlm)->get(['ID_ALMACEN', 'NOMBRE', 'TIPO'])->keyBy('ID_ALMACEN');
        $freById  = $idsFre->isEmpty() ? collect() : \App\Models\FrenteTrabajo::whereIn('ID_FRENTE', $idsFre)->get(['ID_FRENTE', 'NOMBRE_FRENTE'])->keyBy('ID_FRENTE');

        $idAlmacenActivo = ($request->filled('id_almacen') && $request->input('id_almacen') !== 'all')
            ? (int) $request->input('id_almacen')
            : null;

        return view('admin.almacen.notas', [
            'notas'           => $paginator,
            'total'           => $paginator->total(),
            'almacenes'       => $almacenes,
            'idAlmacenActivo' => $idAlmacenActivo,
            'frentesLista'    => \App\Models\FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE')->get(['ID_FRENTE', 'NOMBRE_FRENTE']),
            'categorias'      => $categorias,
            'almById'         => $almById,
            'freById'         => $freById,
            // Ranking de consumo (mismo cálculo y filtros que /admin/almacen/movimientos).
            // Aplica al sidebar y respeta id_almacen / id_frente / desde / hasta / search / categoria.
            'consumo'         => $this->consumoRanking($request),
        ]);
    }

    /**
     * Ranking de productos más consumidos para el sidebar de la bitácora.
     *
     * "Consumo" = suma de CANTIDAD aplicando los mismos filtros que la tabla
     * (almacén, frente, fechas, búsqueda) salvo `tipo` (el ranking SIEMPRE es de salidas).
     *
     * Reglas para evitar doble conteo:
     *  - Filtro por un almacén concreto → cuenta SALIDA y TRASPASO_SALIDA del almacén
     *    (ambos restan stock de ese almacén, ambos son egresos legítimos).
     *  - Vista global (todos los almacenes) → SOLO SALIDA. Los TRASPASO_SALIDA son
     *    movimientos internos entre nuestros propios almacenes; contarlos junto a la
     *    SALIDA que el almacén destino registra después sería sumar la misma unidad
     *    dos veces.
     *
     * Retorna colección de stdClass con: {id_producto, codigo, nombre, um, total, movimientos}.
     */
    protected function consumoRanking(Request $request, int $limite = 30)
    {
        $almacenFiltrado = $request->filled('id_almacen') && $request->input('id_almacen') !== 'all';
        $tipos = $almacenFiltrado ? ['SALIDA', 'TRASPASO_SALIDA'] : ['SALIDA'];

        $q = MovimientoInventario::query()->whereIn('TIPO', $tipos);

        if ($almacenFiltrado) {
            $q->where('ID_ALMACEN', $request->integer('id_almacen'));
        } else {
            $q->whereIn('ID_ALMACEN', Almacen::visiblesPara($request->user())->pluck('ID_ALMACEN'));
        }
        if ($request->filled('id_producto')) {
            // PREFIJAR con `movimientos_inventario.` — sin esto, el JOIN con
            // `productos_inventario as p` que hacemos al final (linea ~836) deja
            // la columna ID_PRODUCTO ambigua (existe en AMBAS tablas) y MySQL
            // dispara error 1052 → la pantalla queda en blanco.
            $q->where('movimientos_inventario.ID_PRODUCTO', $request->integer('id_producto'));
        }
        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $q->whereHas('producto', function ($p) use ($term) {
                $p->where('CODIGO', 'like', "%{$term}%")->orWhere('NOMBRE', 'like', "%{$term}%");
            });
        }
        if ($request->filled('id_frente') && $request->input('id_frente') !== 'all') {
            $q->where('ID_FRENTE', $request->integer('id_frente'));
        }
        // Filtro por categoría — aplica al ranking igual que a la tabla principal.
        if ($request->filled('categoria') && $request->input('categoria') !== 'all') {
            $cat = trim((string) $request->input('categoria'));
            $q->whereExists(function ($sub) use ($cat) {
                $sub->select(DB::raw(1))
                    ->from('productos_inventario as pc')
                    ->whereColumn('pc.ID_PRODUCTO', 'movimientos_inventario.ID_PRODUCTO')
                    ->where('pc.CATEGORIA', 'like', "%{$cat}%");
            });
        }
        $q->periodo($request->input('desde'), $request->input('hasta'));

        return $q->join('productos_inventario as p', 'p.ID_PRODUCTO', '=', 'movimientos_inventario.ID_PRODUCTO')
            ->groupBy('movimientos_inventario.ID_PRODUCTO', 'p.CODIGO', 'p.NOMBRE', 'p.UM')
            ->orderByDesc(DB::raw('SUM(movimientos_inventario.CANTIDAD)'))
            ->limit($limite)
            ->get([
                'movimientos_inventario.ID_PRODUCTO as id_producto',
                'p.CODIGO as codigo',
                'p.NOMBRE as nombre',
                'p.UM as um',
                DB::raw('SUM(movimientos_inventario.CANTIDAD) as total'),
                DB::raw('COUNT(*) as movimientos'),
            ]);
    }

    /**
     * Exporta el inventario a XLSX (PhpSpreadsheet).
     *
     * Estructura del archivo (sigue el patrón de /admin/equipos):
     *  Fila 1-3 : logo (A1:B3) · título "COPIA DE INVENTARIO – <ALMACÉN>" (C1:E3) · EDICION/REV/FECHA (F1:..3)
     *  Fila 4   : "Exportado por: Sistema de Gestión …"
     *  Fila 5   : headers   [N°, CÓDIGO, DESCRIPCIÓN, UND, CATEGORÍA, MÍNIMO, <stock por almacén visible>, TOTAL]
     *  Fila 6+  : datos
     *
     * Si el usuario filtró un almacén, sólo se exporta la columna de stock de ESE almacén
     * (sin TOTAL — el saldo es el mismo). Si no hay filtro, se exporta una columna por
     * cada almacén visible al usuario más una columna TOTAL a la derecha.
     */
    public function export(Request $request)
    {
        $almacenesVisibles = Almacen::visiblesPara($request->user())
            ->orderBy('TIPO')->orderBy('NOMBRE')
            ->get(['ID_ALMACEN', 'NOMBRE', 'TIPO']);

        if ($almacenesVisibles->isEmpty()) {
            return redirect()->back()->with('error', 'No hay almacenes visibles para exportar.');
        }

        // Determinar el almacén seleccionado (si lo hay) y su nombre para el título.
        $idAlmacenSel = $request->filled('id_almacen') && $request->input('id_almacen') !== 'all'
            ? $request->integer('id_almacen')
            : null;
        if ($idAlmacenSel !== null) {
            $this->assertPuedeVerAlmacen($request, $idAlmacenSel);
            $almacenSel = $almacenesVisibles->firstWhere('ID_ALMACEN', $idAlmacenSel);
            if (!$almacenSel) {
                return redirect()->back()->with('error', 'Almacén no accesible.');
            }
            $almacenesEnExport = collect([$almacenSel]);
            $tituloProyecto = mb_strtoupper($almacenSel->NOMBRE);
        } else {
            $almacenesEnExport = $almacenesVisibles;
            $tituloProyecto = 'GLOBAL';
        }

        // Catálogo de productos activos + sus stocks indexados por almacén.
        $productos = ProductoInventario::activos()
            ->orderBy('NOMBRE')
            ->get(['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM', 'CATEGORIA']);

        $stocks = AlmacenStock::query()
            ->whereIn('ID_ALMACEN', $almacenesEnExport->pluck('ID_ALMACEN'))
            ->get(['ID_ALMACEN', 'ID_PRODUCTO', 'CANTIDAD', 'CANTIDAD_MINIMA']);
        $stockMap = [];
        $minimoMap = [];
        foreach ($stocks as $s) {
            $stockMap[$s->ID_PRODUCTO][$s->ID_ALMACEN] = (float) $s->CANTIDAD;
            // El mínimo se toma del primer almacén que lo tenga (cada par producto/almacén
            // tiene su mínimo; en la exportación mostramos el del almacén seleccionado o
            // el primero encontrado en la vista global).
            if (!isset($minimoMap[$s->ID_PRODUCTO]) && $s->CANTIDAD_MINIMA !== null) {
                $minimoMap[$s->ID_PRODUCTO] = (float) $s->CANTIDAD_MINIMA;
            }
        }

        // Construir el archivo.
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Inventario');

        $spreadsheet->getProperties()
            ->setCreator('Sistema de Gestión de Equipos Operacionales')
            ->setLastModifiedBy('Sistema de Gestión de Equipos Operacionales')
            ->setTitle('Copia de Inventario')
            ->setSubject('Exportación de Inventario - ' . $tituloProyecto)
            ->setCompany('Constructora Vidalsa 27, C.A.');
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        // Layout: las primeras 6 columnas fijas (A..F = N°, CÓDIGO, DESCRIPCIÓN, UND, CATEGORÍA, MÍNIMO),
        // luego N columnas para los stocks por almacén, y si hay >1 almacén una columna TOTAL al final.
        // Usamos Coordinate::stringFromColumnIndex (1-indexed) en vez de range('A','Z') para no
        // quedarnos cortos si algún día hay >20 almacenes visibles (a partir de la 21 vendría AA, AB…).
        $col = fn (int $idx1) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx1);
        $fixedCols = array_map($col, range(1, 6));            // A..F
        $almCount  = $almacenesEnExport->count();
        // Guard: range(7, 6) en PHP devuelve [7,6] (descendente). Si por algún motivo almCount=0,
        // stockCols debe quedar vacío en vez de ['G','F'].
        $stockCols = $almCount > 0 ? array_map($col, range(7, 6 + $almCount)) : [];
        $totalCol  = $almCount > 1 ? $col(7 + $almCount) : null;
        $lastCol   = $totalCol ?: ($stockCols ? end($stockCols) : 'F');

        // ── Encabezado: logo + título + meta ───────────────────────────────────
        $sheet->mergeCells('A1:B3');
        $sheet->getStyle('A1:B3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        $logoPath = public_path('img/imagen_uno.jpg');
        if (file_exists($logoPath)) {
            try {
                $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                $drawing->setName('Logo CVIDALSA');
                $drawing->setDescription('Logo');
                $drawing->setPath($logoPath);
                $drawing->setCoordinates('A1');
                $drawing->setOffsetX(45);
                $drawing->setOffsetY(12);
                $drawing->setHeight(110);
                $drawing->setWorksheet($sheet);
            } catch (\Throwable $e) {
                // ignorar fallo de imagen
            }
        }

        $sheet->mergeCells('C1:E3');
        $titleText = "COPIA DE INVENTARIO\n" . ($idAlmacenSel ? 'PROYECTO: "' . $tituloProyecto . '"' : 'COPIA DE BASE DE DATOS DEL INVENTARIO');
        $sheet->setCellValue('C1', $titleText);
        $sheet->getStyle('C1')->getAlignment()->setWrapText(true);
        $sheet->getStyle('C1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('C1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('C1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $sheet->getStyle('C1:E3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        $sheet->mergeCells('F1:' . $lastCol . '1');
        $sheet->setCellValue('F1', 'EDICION: 1');
        $sheet->mergeCells('F2:' . $lastCol . '2');
        $sheet->setCellValue('F2', 'REVISION: 0');
        $sheet->mergeCells('F3:' . $lastCol . '3');
        $sheet->setCellValue('F3', 'FECHA: ' . date('d/m/Y'));
        foreach (['F1','F2','F3'] as $cell) {
            $sheet->getStyle($cell)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle($cell)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $sheet->getStyle($cell)->getFont()->setBold(true)->setSize(11);
        }
        $sheet->getStyle('F1:' . $lastCol . '3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        foreach ([1, 2, 3] as $r) $sheet->getRowDimension($r)->setRowHeight(40);

        $sheet->mergeCells('A4:' . $lastCol . '4');
        $sheet->setCellValue('A4', 'Exportado por: Sistema de Gestión de Equipos Operacionales — ' . optional($request->user())->NOMBRE_COMPLETO);
        $sheet->getStyle('A4:' . $lastCol . '4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A4:' . $lastCol . '4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('A4:' . $lastCol . '4')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A4:' . $lastCol . '4')->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF333333');
        $sheet->getRowDimension(4)->setRowHeight(20);

        $sheet->getStyle('A1:' . $lastCol . '4')->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setARGB('FF000000');

        // ── Fila 5 — encabezados de tabla ──────────────────────────────────────
        $headers = ['N°', 'CÓDIGO', 'DESCRIPCIÓN DEL PRODUCTO', 'UND', 'CATEGORÍA', 'MÍNIMO'];
        foreach ($almacenesEnExport as $a) {
            $sufijo = $a->TIPO === 'GENERAL' ? ' (Principal)' : ' (Proyecto)';
            $headers[] = mb_strtoupper($a->NOMBRE) . $sufijo;
        }
        if ($totalCol) $headers[] = 'TOTAL';

        $colMap = array_merge($fixedCols, $stockCols, $totalCol ? [$totalCol] : []);
        foreach ($headers as $i => $h) {
            $sheet->setCellValue($colMap[$i] . '5', $h);
        }
        $sheet->getStyle('A5:' . $lastCol . '5')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A5:' . $lastCol . '5')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A5:' . $lastCol . '5')->getAlignment()->setWrapText(true);
        $sheet->getStyle('A5:' . $lastCol . '5')->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A5:' . $lastCol . '5')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF1B365D');
        $sheet->getRowDimension(5)->setRowHeight(40);

        // Anchos de columna
        $sheet->getColumnDimension('A')->setWidth(7);
        $sheet->getColumnDimension('B')->setWidth(16);
        $sheet->getColumnDimension('C')->setWidth(45);
        $sheet->getColumnDimension('D')->setWidth(8);
        $sheet->getColumnDimension('E')->setWidth(22);
        $sheet->getColumnDimension('F')->setWidth(10);
        foreach ($stockCols as $col) $sheet->getColumnDimension($col)->setWidth(18);
        if ($totalCol) $sheet->getColumnDimension($totalCol)->setWidth(12);

        // ── Filas de datos ────────────────────────────────────────────────────
        $rowNum = 6;
        $n = 1;
        foreach ($productos as $p) {
            $stocksFila = [];
            $total = 0.0;
            foreach ($almacenesEnExport as $a) {
                $v = $stockMap[$p->ID_PRODUCTO][$a->ID_ALMACEN] ?? 0.0;
                $stocksFila[] = $v;
                $total += $v;
            }

            // Saltar productos con stock 0 en TODOS los almacenes del export si el usuario filtró
            // un almacén específico (es ruido); en la vista global SÍ los listamos (catálogo completo).
            if ($idAlmacenSel !== null && $total == 0.0) {
                continue;
            }

            $minimo = $minimoMap[$p->ID_PRODUCTO] ?? null;

            $sheet->setCellValue('A' . $rowNum, $n);
            $sheet->setCellValue('B' . $rowNum, $p->CODIGO ?? '');
            $sheet->setCellValue('C' . $rowNum, $p->NOMBRE ?? '');
            $sheet->setCellValue('D' . $rowNum, $p->UM ?? '');
            $sheet->setCellValue('E' . $rowNum, $p->CATEGORIA ?? '');
            $sheet->setCellValue('F' . $rowNum, $minimo);

            foreach ($stocksFila as $i => $v) {
                $sheet->setCellValue($stockCols[$i] . $rowNum, $v);
            }
            if ($totalCol) {
                $sheet->setCellValue($totalCol . $rowNum, $total);
                $sheet->getStyle($totalCol . $rowNum)->getFont()->setBold(true);
            }

            // Resaltar fila bajo mínimo (si hay mínimo y stock total ≤ mínimo).
            if ($minimo !== null && $total <= $minimo) {
                $sheet->getStyle('A' . $rowNum . ':' . $lastCol . $rowNum)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFFEF3C7'); // amarillo suave
            } elseif ($rowNum % 2 === 0) {
                $sheet->getStyle('A' . $rowNum . ':' . $lastCol . $rowNum)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFF8FAFC'); // gris muy claro alternado
            }

            $sheet->getStyle('A' . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('B' . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('D' . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('F' . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            foreach ($stockCols as $col) {
                $sheet->getStyle($col . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            }
            if ($totalCol) {
                $sheet->getStyle($totalCol . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            }

            $rowNum++;
            $n++;
        }

        // Bordes a toda la zona de datos.
        if ($rowNum > 6) {
            $sheet->getStyle('A5:' . $lastCol . ($rowNum - 1))->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
                ->getColor()->setARGB('FFCBD5E0');
        } else {
            $sheet->setCellValue('A6', 'Sin productos para exportar.');
            $sheet->mergeCells('A6:' . $lastCol . '6');
            $sheet->getStyle('A6')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        }

        // Freeze panes (header + columna N°/CÓDIGO).
        $sheet->freezePane('C6');

        // Salida.
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $tempFile = tempnam(sys_get_temp_dir(), 'inv_');
        $writer->save($tempFile);

        $fileName = 'Copia_Inventario_' . str_replace([' ', '/'], '_', $tituloProyecto) . '_' . date('Y-m-d_H-i') . '.xlsx';

        return response()->download($tempFile, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Registra un MOVIMIENTO con varias líneas — un "documento" de inventario al
     * estilo de un ERP de tienda: ENTRADA / SALIDA / AJUSTE con N productos, todo
     * en UNA transacción (si una línea falla, no se aplica nada).
     *
     * **Salida unificada**: cuando tipo=SALIDA se acepta `id_frente_destino` (alias
     * `id_frente`). El backend decide automáticamente:
     *   - si el frente destino comparte el almacén origen → SALIDA pura (consumo).
     *   - si el frente destino tiene un almacén DISTINTO → crea un Traspaso, lo envía
     *     (TraspasoService::enviar) y le estampa el mismo NUMERO_NOTA al movimiento
     *     TRASPASO_SALIDA. Así ambos casos generan PDF Nota de Entrega VID-FO-GEN-019.
     *
     * Body:
     *  - tipo                : ENTRADA | SALIDA | AJUSTE
     *  - id_almacen          : almacén origen del movimiento
     *  - fecha, referencia, motivo, notas : opcionales
     *  - id_frente / id_frente_destino : opcional (SALIDA: frente destino del producto;
     *                          en ENTRADA / AJUSTE se ignora salvo autollenado).
     *  - permitir_negativo   : opcional (sólo super.admin)
     *  - lineas              : [{ id_producto, cantidad }, ...]   (>= 1)
     *                          en AJUSTE, "cantidad" es el SALDO OBJETIVO de ese producto.
     */
    public function registrarMovimientoLote(Request $request)
    {
        $tipos = ['ENTRADA', 'SALIDA', 'AJUSTE'];

        $data = $request->validate([
            'tipo'                 => ['required', Rule::in($tipos)],
            'id_almacen'           => 'required|integer|exists:almacenes,ID_ALMACEN',
            'fecha'                => 'nullable|date',
            'id_frente'            => 'nullable|integer|exists:frentes_trabajo,ID_FRENTE',
            // Alias del frente destino para el flujo unificado de SALIDA. El frontend lo
            // manda como `id_frente_destino`; si solo viene `id_frente` lo usamos también.
            'id_frente_destino'    => 'nullable|integer|exists:frentes_trabajo,ID_FRENTE',
            'referencia'           => 'nullable|string|max:100',
            // Campos de la Nota de Entrega de Materiales (se usan en SALIDA y en su
            // variante "salida hacia otro proyecto" que internamente crea un Traspaso).
            'numero_contrato'      => 'nullable|string|max:100',
            'numero_rq'            => 'nullable|string|max:100',
            'solicitante'          => 'nullable|string|max:200',
            'departamento'         => 'nullable|string|max:150',
            'motivo'               => 'nullable|string|max:200',
            'notas'                => 'nullable|string',
            'permitir_negativo'    => 'nullable|boolean',
            'lineas'               => 'required|array|min:1',
            'lineas.*.id_producto' => 'required|integer|exists:productos_inventario,ID_PRODUCTO',
            'lineas.*.cantidad'    => 'required|numeric',
        ]);

        $this->assertPuedeVerAlmacen($request, (int) $data['id_almacen']);

        // ── Rama "Salida a otro proyecto" ───────────────────────────────────────
        // Si tipo=SALIDA + frente destino con almacén distinto al origen → delegamos
        // al TraspasoService (crea borrador + envía + asigna NUMERO_NOTA), de modo que
        // el flujo termine con PDF Nota de Entrega igual que una SALIDA pura.
        if ($data['tipo'] === 'SALIDA') {
            $idFrenteDest = $data['id_frente_destino'] ?? $data['id_frente'] ?? null;
            if ($idFrenteDest) {
                $almacenesDelFrente = Almacen::query()
                    ->where('TIPO', Almacen::TIPO_PROYECTO)
                    ->where('ESTATUS', 'ACTIVO')
                    ->where('ID_ALMACEN', '!=', (int) $data['id_almacen'])
                    ->whereHas('frentes', fn ($q) => $q->where('frentes_trabajo.ID_FRENTE', (int) $idFrenteDest))
                    ->pluck('ID_ALMACEN');

                if ($almacenesDelFrente->count() === 1) {
                    // SALIDA hacia un almacén distinto → vía traspaso.
                    return $this->registrarSalidaViaTraspaso(
                        $request,
                        $data,
                        (int) $idFrenteDest,
                        (int) $almacenesDelFrente->first(),
                    );
                }
                if ($almacenesDelFrente->count() > 1) {
                    return response()->json([
                        'message' => 'El frente destino tiene varios almacenes PROYECTO asignados; no se puede deducir el destino. Pide a un administrador que deje un único almacén por frente.',
                    ], 422);
                }
                // 0 almacenes distintos → es consumo en el almacén actual (cae al flujo normal).
            }
        }

        // Resolución del frente del movimiento:
        //   - SALIDA  → el usuario elige el frente que CONSUME el producto (proyecto destino).
        //   - ENTRADA/AJUSTE en almacén PROYECTO con UN único frente asociado → autollenamos
        //     con ese frente para que el movimiento aparezca atribuido al proyecto correcto
        //     en la bitácora (de lo contrario quedaba NULL y la columna "Destino" se veía
        //     vacía aunque el almacén perteneciera a un frente claro).
        //   - ENTRADA/AJUSTE en almacén GENERAL o PROYECTO multi-frente → queda NULL (el
        //     movimiento es "del almacén" sin proyecto específico).
        // El frente del movimiento se acepta como `id_frente` o `id_frente_destino` (el
        // formulario unificado de salida envía el segundo; clientes externos pueden mandar
        // cualquiera de los dos). Esto evita atribuir mal el movimiento cuando solo viene
        // el alias.
        $idFrenteRequest = $data['id_frente'] ?? $data['id_frente_destino'] ?? null;
        if ($data['tipo'] === 'SALIDA') {
            $idFrente = $idFrenteRequest;
        } else {
            $idFrente = $idFrenteRequest;
            if ($idFrente === null) {
                $alm = Almacen::with('frentes:ID_FRENTE')->find((int) $data['id_almacen']);
                if ($alm && $alm->TIPO === Almacen::TIPO_PROYECTO && $alm->frentes->count() === 1) {
                    $idFrente = (int) $alm->frentes->first()->ID_FRENTE;
                }
            }
        }

        // Los campos de la Nota de Entrega solo se preservan en SALIDA. Para ENTRADA/AJUSTE se ignoran
        // (quedarían NULL en BD de todas formas, pero los limpiamos para que el opts esté coherente).
        $esSalida = $data['tipo'] === 'SALIDA';

        $opts = [
            'fecha'             => $data['fecha'] ?? null,
            'id_frente'         => $idFrente,
            'referencia'        => $data['referencia'] ?? null,
            'numero_contrato'   => $esSalida ? ($data['numero_contrato'] ?? null) : null,
            'numero_rq'         => $esSalida ? ($data['numero_rq']       ?? null) : null,
            'solicitante'       => $esSalida ? ($data['solicitante']     ?? null) : null,
            'departamento'      => $esSalida ? ($data['departamento']    ?? null) : null,
            'motivo'            => $data['motivo'] ?? null,
            'notas'             => $data['notas'] ?? null,
            'id_usuario'        => optional($request->user())->ID_USUARIO,
            'permitir_negativo' => $request->boolean('permitir_negativo') && $request->user()->can('super.admin'),
        ];

        try {
            // En SALIDA generamos el NUMERO_NOTA (NE-YYYY-NNNN) DENTRO de la transacción
            // y lo stampamos en todos los movimientos del lote para identificar la Nota
            // de Entrega. Permite reimprimir/eliminar la nota completa por código desde
            // /admin/almacen/movimientos. Capturamos también los IDs para devolver la URL
            // del PDF al frontend (pre-open tab inmediata, sin segunda búsqueda).
            $result = DB::transaction(function () use ($data, $opts) {
                if ($data['tipo'] === 'SALIDA') {
                    $opts['numero_nota'] = MovimientoInventario::generarNumeroNota();
                }
                $ids = [];
                foreach ($data['lineas'] as $linea) {
                    $idProducto = (int) $linea['id_producto'];
                    $cantidad   = (float) $linea['cantidad'];

                    $mov = match ($data['tipo']) {
                        'ENTRADA' => $this->inventario->registrarEntrada((int) $data['id_almacen'], $idProducto, $cantidad, $opts),
                        'SALIDA'  => $this->inventario->registrarSalida((int) $data['id_almacen'], $idProducto, $cantidad, $opts),
                        'AJUSTE'  => $this->inventario->registrarAjuste((int) $data['id_almacen'], $idProducto, $cantidad, $opts),
                    };
                    $ids[] = $mov->ID_MOVIMIENTO;
                }
                return ['ids' => $ids, 'numero_nota' => $opts['numero_nota'] ?? null];
            });
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $n = count($result['ids']);
        // Etiqueta visible en el toast: usa el rename de UI (AJUSTE → "Auditoría")
        // para que el mensaje sea coherente con el modal "Auditoría de Inventario"
        // y la pill del kardex. El TIPO en BD se mantiene como AJUSTE.
        $etiqueta = [
            'ENTRADA' => 'Entrada registrada',
            'SALIDA'  => 'Salida registrada',
            'AJUSTE'  => 'Auditoría registrada',
        ][$data['tipo']] ?? 'Movimiento registrado';

        $payload = ['message' => "{$etiqueta} ({$n} producto" . ($n === 1 ? '' : 's') . ')'];
        // Sólo en SALIDA devolvemos la URL del PDF de Nota de Entrega; el frontend la abre
        // en el visor in-page (#pdfPreviewModal vía window.openPdfPreview).
        if ($data['tipo'] === 'SALIDA') {
            $payload['nota_url']    = route('almacen.nota-entrega', ['numero' => $result['numero_nota']]);
            $payload['numero_nota'] = $result['numero_nota'];
        }

        return response()->json($payload, 201);
    }

    /**
     * Rama de "Salida hacia otro proyecto": crea un Traspaso (BORRADOR), reemplaza
     * sus líneas y lo envía en una sola transacción. Estampa la Nota de Entrega
     * (NUMERO_NOTA + contrato/RQ/solicitante/dpto) en los movimientos TRASPASO_SALIDA
     * para que sea reimprimible desde la bitácora, idéntico a una SALIDA pura.
     *
     * Devuelve la misma forma de respuesta que SALIDA: { message, nota_url, numero_nota }.
     */
    private function registrarSalidaViaTraspaso(Request $request, array $data, int $idFrenteDestino, int $idAlmacenDestino): \Illuminate\Http\JsonResponse
    {
        $idUsuario = optional($request->user())->ID_USUARIO;
        $lineas    = array_map(
            fn ($l) => ['id_producto' => (int) $l['id_producto'], 'cantidad' => (float) $l['cantidad']],
            $data['lineas'],
        );

        try {
            $resultado = DB::transaction(function () use ($data, $idFrenteDestino, $idAlmacenDestino, $idUsuario, $lineas, $request) {
                $numeroNota = MovimientoInventario::generarNumeroNota();

                $traspaso = $this->traspasos->crearBorrador(
                    datos: [
                        'id_almacen_origen'  => (int) $data['id_almacen'],
                        'id_almacen_destino' => $idAlmacenDestino,
                        'id_frente_destino'  => $idFrenteDestino,
                        // El número de nota se usa también como REFERENCIA del traspaso
                        // — así el kardex y el documento comparten el mismo identificador
                        // visible al usuario (TR-... para auditoría, NE-... para el PDF).
                        'referencia'         => $numeroNota,
                        'motivo'             => $data['motivo'] ?? null,
                        'id_usuario'         => $idUsuario,
                    ],
                    lineas: $lineas,
                );

                $this->traspasos->enviar($traspaso, [
                    'id_usuario_envio'  => $idUsuario,
                    'fecha_envio'       => $data['fecha'] ?? null,
                    'permitir_negativo' => $request->boolean('permitir_negativo') && $request->user()->can('super.admin'),
                    'numero_nota'       => $numeroNota,
                    'numero_contrato'   => $data['numero_contrato'] ?? null,
                    'numero_rq'         => $data['numero_rq']       ?? null,
                    'solicitante'       => $data['solicitante']     ?? null,
                    'departamento'      => $data['departamento']    ?? null,
                ]);

                return ['numero_nota' => $numeroNota, 'numero_traspaso' => $traspaso->NUMERO];
            });
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $n = count($lineas);
        return response()->json([
            'message'         => "Salida hacia otro proyecto enviada ({$n} producto" . ($n === 1 ? '' : 's') . ') — pendiente de recepción.',
            'nota_url'        => route('almacen.nota-entrega', ['numero' => $resultado['numero_nota']]),
            'numero_nota'     => $resultado['numero_nota'],
            'numero_traspaso' => $resultado['numero_traspaso'],
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────
    //  Nota de Entrega de Materiales (PDF, formato VID-FO-GEN-019)
    // ─────────────────────────────────────────────────────────────

    /**
     * Genera el PDF "Nota de Entrega de Materiales" replicando el formulario oficial
     * (Constructora Vidalsa 27, C.A. — VID-FO-GEN-019).
     *
     * Acepta dos modos de búsqueda (mutuamente excluyentes):
     *   ?numero=NE-2026-0001  → recupera todos los SALIDA con ese NUMERO_NOTA (recomendado;
     *                           es el flujo que usa "Generar Nota por código" desde el
     *                           dropdown Acciones de /admin/almacen/movimientos).
     *   ?ids=10,11,12         → recupera los SALIDA con esos IDs (lo que devuelve
     *                           registrarMovimientoLote inmediatamente tras crear la nota;
     *                           el frontend abre el PDF en el visor in-page del modal de
     *                           salida vía window.openPdfPreview).
     *
     * Permisos: lectura. Sólo se valida que el usuario pueda VER el almacén involucrado;
     * no se exige 'almacen.movimiento' (eso es para crear).
     */
    public function notaEntregaPdf(Request $request)
    {
        $movs = $this->buscarMovimientosDeNota($request);
        abort_if($movs === null || $movs->isEmpty(), 404, 'No se encontró la Nota de Entrega solicitada.');

        // Cabecera del documento: todos los movimientos comparten estos campos porque
        // registrarMovimientoLote los stampa en cada línea con el mismo $opts.
        $hd = $movs->first();
        // "Entregado por" = ALMACENISTA + CARGO_ALMACENISTA del almacén origen,
        // ambos configurables en el modal "Editar almacén" (/admin/almacen →
        // Acciones → Gestionar almacenes). Si el almacén no tiene almacenista
        // asignado el campo queda VACIO en el PDF: NO caemos al usuario que
        // registró el movimiento — eso confundia al firmante (el almacenista
        // quien entrega rara vez es la persona que opera el sistema).
        // Todos los campos de texto pasan por el cast App\Casts\MojibakeFix aplicado en
        // los modelos correspondientes (FrenteTrabajo, Almacen, MovimientoInventario,
        // ProductoInventario) — el mojibake legacy ya se decodea automaticamente al
        // leer. NO necesitamos llamar a un helper aqui.
        $datos = [
            'numero_nota'   => $hd->NUMERO_NOTA ?? '',
            'proyecto'      => $hd->frente?->NOMBRE_FRENTE ?? '',
            'contrato'      => $hd->NUMERO_CONTRATO ?? '',
            'fecha'         => optional($hd->FECHA)->format('d/m/Y') ?: now()->format('d/m/Y'),
            'rq'            => $hd->NUMERO_RQ ?? '',
            'solicitante'   => $hd->SOLICITANTE ?? '',
            'departamento'  => $hd->DEPARTAMENTO ?? '',
            'almacen'       => $hd->almacen?->NOMBRE ?? '',
            'entregado_por' => trim((string) ($hd->almacen?->ALMACENISTA ?? '')),
            'cargo_entrega' => trim((string) ($hd->almacen?->CARGO_ALMACENISTA ?? '')),
            'motivo'        => $hd->MOTIVO ?? '',
        ];

        $slug   = $hd->NUMERO_NOTA ?: ($hd->NUMERO_RQ ?: ('LOTE-' . $hd->ID_MOVIMIENTO));
        $binary = $this->renderNotaEntregaPdfBinary($datos, $movs, false);
        return response($binary, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Nota_Entrega_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $slug) . '.pdf"',
        ]);
    }

    /**
     * Vista previa del PDF de la Nota de Entrega ANTES de confirmar la salida.
     *
     * Mismo payload que registrarMovimientoLote (rama SALIDA) — valida acceso al
     * almacen y stock disponible IGUAL que el endpoint real, asi un preview
     * exitoso garantiza que el "Confirmar" no va a rebotar. NO escribe en BD:
     * arma los datos y movimientos en memoria desde los lookups (almacen,
     * frente, productos) y delega en renderNotaEntregaPdfBinary().
     *
     * El "Confirmar" del frontend llama al endpoint regular movimientos-lote y
     * obtiene el PDF final por la ruta normal.
     */
    public function previewSalidaPdf(Request $request)
    {
        $data = $request->validate([
            'id_almacen'           => 'required|integer|exists:almacenes,ID_ALMACEN',
            'fecha'                => 'nullable|date',
            'id_frente_destino'    => 'nullable|integer|exists:frentes_trabajo,ID_FRENTE',
            'numero_contrato'      => 'nullable|string|max:100',
            'numero_rq'            => 'nullable|string|max:100',
            'solicitante'          => 'nullable|string|max:200',
            'departamento'         => 'nullable|string|max:150',
            'motivo'               => 'nullable|string|max:200',
            'lineas'               => 'required|array|min:1',
            'lineas.*.id_producto' => 'required|integer|exists:productos_inventario,ID_PRODUCTO',
            'lineas.*.cantidad'    => 'required|numeric|gt:0',
        ]);

        $this->assertPuedeVerAlmacen($request, (int) $data['id_almacen']);

        // Mismo gate de stock que registrarMovimientoLote — si esta funcion deja
        // pasar algo, el "Confirmar" tambien lo dejara (consistencia preview/final).
        $idsProd = collect($data['lineas'])->pluck('id_producto')->map(fn ($n) => (int) $n)->all();
        $stocks  = AlmacenStock::where('ID_ALMACEN', (int) $data['id_almacen'])
            ->whereIn('ID_PRODUCTO', $idsProd)
            ->get(['ID_PRODUCTO', 'CANTIDAD'])->keyBy('ID_PRODUCTO');
        $productos = ProductoInventario::whereIn('ID_PRODUCTO', $idsProd)
            ->get(['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM'])->keyBy('ID_PRODUCTO');

        $excesos = [];
        foreach ($data['lineas'] as $l) {
            $idp   = (int) $l['id_producto'];
            $disp  = (float) ($stocks[$idp]->CANTIDAD ?? 0);
            if ((float) $l['cantidad'] > $disp) {
                $excesos[] = ($productos[$idp]->NOMBRE ?? ('#' . $idp))
                    . ' (' . rtrim(rtrim(number_format((float) $l['cantidad'], 3, '.', ''), '0'), '.')
                    . ' > ' . rtrim(rtrim(number_format($disp, 3, '.', ''), '0'), '.') . ')';
            }
        }
        if (!empty($excesos)) {
            return response()->json([
                'message' => 'Las cantidades superan el saldo disponible en: ' . implode(', ', $excesos) . '.',
            ], 422);
        }

        // ── Armar $datos y $movs en MEMORIA (mismas claves que notaEntregaPdf) ──
        // Los atributos de modelos (frente, almacen, productos) pasan por el cast
        // App\Casts\MojibakeFix automaticamente. Para los campos que vienen del
        // request directamente (numero_contrato, numero_rq, solicitante, etc.) NO
        // hay cast, asi que llamamos MojibakeFix::fix() de defensa por si el usuario
        // pega texto con mojibake en el formulario (caso raro pero posible).
        $almacen = Almacen::find((int) $data['id_almacen']);
        $frente  = !empty($data['id_frente_destino'])
            ? \App\Models\FrenteTrabajo::find((int) $data['id_frente_destino'])
            : null;
        $fixReq = fn ($v) => \App\Casts\MojibakeFix::fix($v) ?? '';

        $datos = [
            'numero_nota'   => 'NE-VISTA-PREVIA',
            'proyecto'      => $frente?->NOMBRE_FRENTE ?? '',
            'contrato'      => $fixReq($data['numero_contrato'] ?? ''),
            'fecha'         => !empty($data['fecha'])
                ? \Carbon\Carbon::parse($data['fecha'])->format('d/m/Y')
                : now()->format('d/m/Y'),
            'rq'            => $fixReq($data['numero_rq'] ?? ''),
            'solicitante'   => $fixReq($data['solicitante'] ?? ''),
            'departamento'  => $fixReq($data['departamento'] ?? ''),
            'almacen'       => $almacen?->NOMBRE ?? '',
            'entregado_por' => $almacen?->ALMACENISTA ?? '',
            'cargo_entrega' => $almacen?->CARGO_ALMACENISTA ?? '',
            'motivo'        => $fixReq($data['motivo'] ?? ''),
        ];

        // Cada $m del blade lee: CANTIDAD, producto->{UM, NOMBRE, CODIGO}. Armamos
        // stdClass que cumple ese contrato — la coleccion mantiene el orden del
        // payload para que el preview se vea EXACTO al PDF final. NOMBRE pasa por
        // el cast de ProductoInventario, no necesita fix manual.
        $movs = collect($data['lineas'])->map(function ($l) use ($productos) {
            return (object) [
                'CANTIDAD' => (float) $l['cantidad'],
                'producto' => $productos[(int) $l['id_producto']] ?? null,
            ];
        });

        $binary = $this->renderNotaEntregaPdfBinary($datos, $movs, true);
        return response($binary, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Vista_Previa_Nota_Entrega.pdf"',
            // No cachear el preview — cada cambio del usuario debe regenerar.
            'Cache-Control'       => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'              => 'no-cache',
        ]);
    }

    /**
     * Construye y serializa el PDF de la Nota de Entrega a partir de los datos ya
     * normalizados — NO toca BD. Lo usan dos endpoints:
     *   • notaEntregaPdf()  → con datos cargados de movimientos persistidos.
     *   • previewSalidaPdf() → con datos del request, sin commit (vista previa).
     *
     * Retorna el binario del PDF (string) para que el caller decida headers y
     * disposition. Usa Output('','S') que devuelve el contenido como string sin
     * escribir a stdout/headers.
     */
    private function renderNotaEntregaPdfBinary(array $datos, $movs, bool $esPreview = false): string
    {
        $pdf = new NotaEntregaPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        // El N° de Nota va en el cabezote (esquina derecha, donde antes estaba "CODIGO:").
        // Header() lo lee de esta propiedad pública.
        $pdf->numeroNota = $datos['numero_nota'] ?? '';
        $pdf->setPrintHeader(true);
        // Footer desactivado: ya no imprimimos "Sistema de Gestión VIDALSA" al pie.
        // La Nota de Entrega es un formulario oficial impreso (VID-FO-GEN-019), no un
        // reporte interno — el footer del sistema sobraba.
        $pdf->setPrintFooter(false);
        // top=30: header arranca en y=6 con altura ~24mm (header=68pt) -> bottom = 30mm
        // EXACTOS. Sin gap entre el borde inferior del cabezote y el borde superior de la
        // tabla "PROYECTO/CONTRATO/..." -> las dos lineas se ven como una sola continua.
        // Si subes/bajas $headerHeight en NotaEntregaPDF::Header(), recalcular este top.
        $pdf->SetMargins(12, 30, 12);
        $pdf->SetHeaderMargin(6);
        $pdf->SetFooterMargin(10);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->SetTitle('Nota de Entrega de Materiales' . ($esPreview ? ' (Vista previa)' : ''));
        $pdf->SetAuthor('Constructora Vidalsa 27, C.A.');
        $pdf->SetCreator('Sistema de Gestión VIDALSA');
        $pdf->AddPage();
        // Línea fina (0.15mm) para que las tablas HTML del cuerpo usen el mismo
        // grosor que el cabezote — TCPDF usa SetLineWidth como default para los
        // bordes de tablas en writeHTML.
        $pdf->SetLineWidth(0.15);
        $pdf->SetFont('helvetica', '', 9.5);

        $html = view('admin.almacen.nota_entrega_pdf', [
            'datos' => $datos,
            'movs'  => $movs,
        ])->render();
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }

    /**
     * Devuelve los movimientos SALIDA que componen la Nota referenciada por
     * `?numero=` (NUMERO_NOTA) o `?ids=` (lista de ID_MOVIMIENTO). Aplica los
     * mismos chequeos de seguridad que el endpoint público: un único almacén
     * y que el usuario pueda verlo. Devuelve null si no se especificó nada.
     */
    private function buscarMovimientosDeNota(Request $request): ?\Illuminate\Database\Eloquent\Collection
    {
        $numero = trim((string) $request->query('numero', ''));
        $idsRaw = (string) $request->query('ids', '');

        // La Nota de Entrega cubre SALIDAS puras y también las TRASPASO_SALIDA generadas
        // por la rama "salida hacia otro proyecto" del flujo unificado (mismo PDF).
        $q = MovimientoInventario::with(['producto', 'almacen', 'frente', 'usuario'])
            ->whereIn('TIPO', [MovimientoInventario::TIPO_SALIDA, MovimientoInventario::TIPO_TRASPASO_SALIDA])
            ->orderBy('ID_MOVIMIENTO');

        if ($numero !== '') {
            $q->where('NUMERO_NOTA', $numero);
        } elseif ($idsRaw !== '') {
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsRaw)))));
            if (empty($ids)) return null;
            $q->whereIn('ID_MOVIMIENTO', $ids);
        } else {
            return null;
        }

        $movs = $q->get();
        if ($movs->isEmpty()) return $movs;

        // Defensa: la cabecera de la nota se construye con $movs->first(), así que
        // mezclar movimientos de almacenes distintos en una sola nota produciría un
        // PDF engañoso → rechazamos. registrarMovimientoLote siempre crea las líneas
        // en un único almacén, pero defendemos contra ?ids= adversariales.
        abort_if(
            $movs->pluck('ID_ALMACEN')->unique()->count() > 1,
            400,
            'Los movimientos de la nota deben pertenecer a un único almacén.'
        );
        $this->assertPuedeVerAlmacen($request, (int) $movs->first()->ID_ALMACEN);
        return $movs;
    }

    /**
     * Modal "Generar Nota por código" del dropdown Acciones de la bitácora.
     * Recibe ?numero=NE-2026-0001 y responde JSON {success, url} para que el
     * frontend dispare la descarga del PDF (mismo patrón que el endpoint
     * `find-by-codigo` de /admin/movilizaciones).
     */
    public function buscarNotaPorNumero(Request $request)
    {
        $numero = trim((string) $request->query('numero', ''));
        if ($numero === '') {
            return response()->json(['success' => false, 'message' => 'Ingresa un N° de Nota.'], 422);
        }
        $existe = MovimientoInventario::where('NUMERO_NOTA', $numero)->exists();
        if (!$existe) {
            return response()->json(['success' => false, 'message' => 'No se encontró ninguna Nota con ese código.'], 404);
        }
        return response()->json([
            'success' => true,
            'numero'  => $numero,
            'url'     => route('almacen.nota-entrega', ['numero' => $numero]),
        ]);
    }

    /**
     * Elimina TODA la Nota de Entrega identificada por ?numero=NE-YYYY-NNNN.
     * En la misma transacción reversa el stock por cada línea (suma de vuelta
     * lo que la SALIDA había restado), encadenando un AJUSTE inverso a través
     * de InventarioService::registrarEntrada — esto crea una fila de kardex
     * que documenta la reversión (auditable) y NO borra los movimientos SALIDA
     * originales (el kardex sigue siendo append-only y verificable).
     *
     * Permiso: super.admin (gateado en routes/web.php). Los movimientos del
     * lote deben pertenecer a un único almacén y el usuario debe poder verlo.
     */
    public function eliminarNota(Request $request)
    {
        $numero = trim((string) $request->query('numero', $request->input('numero', '')));
        if ($numero === '') {
            return response()->json(['message' => 'Ingresa un N° de Nota.'], 422);
        }

        $movs = MovimientoInventario::where('TIPO', MovimientoInventario::TIPO_SALIDA)
            ->where('NUMERO_NOTA', $numero)
            ->orderBy('ID_MOVIMIENTO')
            ->get();

        if ($movs->isEmpty()) {
            return response()->json(['message' => 'No se encontró ninguna Nota con ese código.'], 404);
        }

        abort_if(
            $movs->pluck('ID_ALMACEN')->unique()->count() > 1,
            400,
            'Los movimientos de la nota deben pertenecer a un único almacén.'
        );
        $this->assertPuedeVerAlmacen($request, (int) $movs->first()->ID_ALMACEN);

        try {
            DB::transaction(function () use ($movs, $request, $numero) {
                foreach ($movs as $m) {
                    // Reversión = ENTRADA por la misma cantidad al mismo almacén/producto.
                    // El kardex queda con dos filas (SALIDA original + ENTRADA reversa)
                    // → trazable. El stock vuelve a su valor previo a la nota.
                    $this->inventario->registrarEntrada(
                        (int) $m->ID_ALMACEN,
                        (int) $m->ID_PRODUCTO,
                        (float) $m->CANTIDAD,
                        [
                            'id_usuario' => optional($request->user())->ID_USUARIO,
                            'motivo'     => "Reversión de Nota {$numero}",
                            'referencia' => $numero,
                            'notas'      => "Reversión automática al eliminar la Nota de Entrega {$numero}.",
                        ]
                    );
                }
                // Marcamos los movimientos originales para que no aparezcan más como
                // parte de una nota "vigente": vaciamos NUMERO_NOTA (la fila sigue en
                // el kardex como histórico de la SALIDA, pero no se podrá reimprimir).
                MovimientoInventario::where('NUMERO_NOTA', $numero)->update([
                    'NUMERO_NOTA' => null,
                    'MOTIVO'      => DB::raw("CONCAT(COALESCE(MOTIVO, ''), ' [NOTA {$numero} ELIMINADA]')"),
                ]);
            });
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'     => "Nota {$numero} eliminada y stock revertido.",
            'numero_nota' => $numero,
            'lineas'      => $movs->count(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  Internos
    // ─────────────────────────────────────────────────────────────

    private function validarAlmacen(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'CODIGO'      => ['nullable', 'string', 'max:30', Rule::unique('almacenes', 'CODIGO')->ignore($ignoreId, 'ID_ALMACEN')],
            'NOMBRE'      => ['required', 'string', 'max:150', Rule::unique('almacenes', 'NOMBRE')->ignore($ignoreId, 'ID_ALMACEN')],
            'TIPO'        => ['required', Rule::in([Almacen::TIPO_GENERAL, Almacen::TIPO_PROYECTO])],
            'UBICACION'   => 'nullable|string|max:150',
            // ALMACENISTA: nombre del responsable del almacén (aparece como "Entregado por"
            // en la Nota de Entrega VID-FO-GEN-019). Obligatorio para no dejar el PDF sin
            // firma de quien entrega.
            'ALMACENISTA'       => 'required|string|max:200',
            // CARGO_ALMACENISTA: cargo / titulo (aparece como "CARGO:" en el PDF debajo del
            // NOMBRE del almacenista; sustituye al literal hardcodeado "COORD. DE MATERIALES").
            'CARGO_ALMACENISTA' => 'required|string|max:200',
            'ESTATUS'           => 'nullable|in:ACTIVO,INACTIVO',
            'NOTAS'             => 'nullable|string',
            // frentes: array de IDs. Requerido solo si TIPO=PROYECTO (un almacen GLOBAL
            // no se asocia a frentes especificos — sirve a todos). En PROYECTO debe
            // tener al menos 1 frente para que alguien pueda usarlo.
            'frentes'           => ['nullable', 'array', Rule::requiredIf(fn () => ($request->input('TIPO') === Almacen::TIPO_PROYECTO))],
            'frentes.*'         => 'integer|exists:frentes_trabajo,ID_FRENTE',
        ]);

        // Normalizar.
        $data['NOMBRE'] = mb_strtoupper(trim($data['NOMBRE']));
        if (!empty($data['CODIGO']))            $data['CODIGO']            = mb_strtoupper(trim($data['CODIGO']));
        if (!empty($data['UBICACION']))         $data['UBICACION']         = mb_strtoupper(trim($data['UBICACION']));
        if (!empty($data['ALMACENISTA']))       $data['ALMACENISTA']       = mb_strtoupper(trim($data['ALMACENISTA']));
        if (!empty($data['CARGO_ALMACENISTA'])) $data['CARGO_ALMACENISTA'] = mb_strtoupper(trim($data['CARGO_ALMACENISTA']));
        
        // Evitar reactivar almacenes inactivos al editarlos sin mandar el campo ESTATUS.
        if ($ignoreId === null) {
            $data['ESTATUS'] = $data['ESTATUS'] ?? 'ACTIVO';
        } else {
            if (empty($data['ESTATUS'])) {
                unset($data['ESTATUS']);
            }
        }

        unset($data['frentes']); // se sincroniza aparte
        return $data;
    }

    private function validarProducto(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            // CODIGO es VARCHAR(50). Al crearlo manualmente desde la UI sólo se permiten
            // dígitos (el frontend lo fuerza y aquí validamos con regex). El auto-generado
            // (PRD-XXXX) nunca pasa por esta validación porque llega null.
            'CODIGO'    => ['nullable', 'string', 'max:20', 'regex:/^\d+$/', Rule::unique('productos_inventario', 'CODIGO')->ignore($ignoreId, 'ID_PRODUCTO')],
            'NOMBRE'    => 'required|string|max:200',
            'UM'        => 'required|string|max:20',
            'CATEGORIA' => 'nullable|string|max:100',
            // UBICACION: ubicación física en bodega (texto libre, ej. "Estante A3").
            // Se edita en el modal "Editar producto" y se muestra como tooltip al hover de la fila.
            'UBICACION' => 'nullable|string|max:150',
            'ESTATUS'   => 'nullable|in:ACTIVO,INACTIVO',
            'NOTAS'     => 'nullable|string',
        ], [
            'CODIGO.unique'  => 'El código ingresado ya está en uso. Usa otro o déjalo vacío para autogenerar.',
            'CODIGO.regex'   => 'El código debe contener solo dígitos enteros.',
            'CODIGO.max'     => 'El código no puede superar los 20 caracteres.',
            'NOMBRE.required'=> 'La descripción del producto es obligatoria.',
            'NOMBRE.max'     => 'La descripción no puede superar los 200 caracteres.',
            'UM.required'    => 'La unidad de medida es obligatoria.',
            'UM.max'         => 'La unidad de medida no puede superar los 20 caracteres.',
            'CATEGORIA.max'  => 'La categoría no puede superar los 100 caracteres.',
            'UBICACION.max'  => 'La ubicación no puede superar los 150 caracteres.',
        ]);

        $data['CODIGO']    = !empty($data['CODIGO']) ? trim($data['CODIGO']) : null;
        $data['NOMBRE']    = mb_strtoupper(trim($data['NOMBRE']));
        $data['UM']        = mb_strtoupper(trim($data['UM']));
        $data['CATEGORIA'] = !empty($data['CATEGORIA']) ? mb_strtoupper(trim($data['CATEGORIA'])) : null;
        $data['UBICACION'] = !empty($data['UBICACION']) ? mb_strtoupper(trim($data['UBICACION'])) : null;
        
        // Evitar reactivar productos inactivos al editarlos sin mandar el campo ESTATUS.
        if ($ignoreId === null) {
            $data['ESTATUS'] = $data['ESTATUS'] ?? 'ACTIVO';
        } else {
            if (empty($data['ESTATUS'])) {
                unset($data['ESTATUS']);
            }
        }

        return $data;
    }

    private function syncFrentes(Almacen $almacen, array $frenteIds): void
    {
        $ids = collect($frenteIds)->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        // Solo tiene sentido asociar frentes a almacenes de PROYECTO.
        if ($almacen->TIPO !== Almacen::TIPO_PROYECTO) {
            $almacen->frentes()->sync([]);
            return;
        }
        $almacen->frentes()->sync($ids);
    }

    /**
     * Wrapper sobre el helper centralizado Almacen::assertVisibleOrFail.
     * Se mantiene para no tocar las 5 llamadas internas (todas pasan por aquí).
     */
    private function assertPuedeVerAlmacen(Request $request, int $idAlmacen): void
    {
        Almacen::assertVisibleOrFail($request->user(), $idAlmacen);
    }

}

/**
 * TCPDF subclass para la Nota de Entrega de Materiales (formato oficial VID-FO-GEN-019,
 * Constructora Vidalsa 27, C.A. — emitido 01/10/19, revisión 1 del 06/10/23).
 *
 * Cabezote (Header(), repetido en cada página):
 *   [LOGO]            [TÍTULO centrado]               [Sello de código 5 filas]
 *
 * Nota: este PDF es un FORMULARIO OFICIAL de uso impreso/firmable, no un reporte interno
 * del sistema. Por eso la cabecera es más alta (42mm de margen superior, logo h=28mm,
 * título 15pt) que la de FallaController/MovilizacionController, que son reportes con un
 * encabezado fino. El estilo se calca del Excel "Nueva Nota de entrega de materiales 2025.xlsx"
 * que ya usa la empresa.
 */
class NotaEntregaPDF extends \TCPDF
{
    /** N° de Nota (NE-YYYY-NNNN) — lo inyecta el controller antes de generar el PDF. */
    public string $numeroNota = '';

    public function Header()
    {
        // ── Cabezote oficial VID-FO-GEN-019 — UNA tabla HTML con bordes ────────
        //    [LOGO 22%]  |  NOTA DE ENTREGA DE MATERIALES (50%)  |  [SELLO 28%]
        //    Se hace en una sola writeHTMLCell para que el grosor de las líneas
        //    sea consistente entre cabezote y cuerpo (TCPDF renderiza todas las
        //    tablas HTML con el mismo borderWidth basado en SetLineWidth).
        //
        //    El logo es una imagen (no se puede meter via HTML facil en TCPDF),
        //    se superpone con Image() encima de la primera celda — vacía a
        //    propósito y con rowspan=5 para que tenga el alto de las 5 filas
        //    del sello.

        // Líneas finas. SetLineWidth se hereda al renderizar el cuerpo, por eso
        // tablas posteriores quedan con el MISMO grosor que el cabezote.
        $this->SetLineWidth(0.15);

        // Cabezote oficial VID-FO-GEN-019.
        // Geometria del cabezote:
        //   x = 12 mm  (margen izquierdo)         width = 186 mm  (210 - 12*2)
        //   y = 6 mm   (top del cabezote)         height = 68 pt = 24 mm
        //   bottom = y + height = 30 mm           ← coincide con SetMargins top=30
        //                                          para que la tabla del body
        //                                          arranque PEGADA al cabezote.
        // Celda del logo (22% de 186 = 40.92 mm):
        //   left   = 12     right = 12 + 40.92 = 52.92      center x = 32.46
        //   top    =  6     bottom = 30                      center y = 18
        $headerHeight = 68;          // pt (= ~24 mm)
        $cabX = 12;
        $cabY = 6;
        $cabW = 186;
        $cabH = 24;                  // mm (≈ 68 pt)
        $logoCellW = $cabW * 0.22;   // 40.92 mm

        $img = public_path('img/imagen_uno.jpg');
        if (file_exists($img)) {
            // Logo centrado HORIZONTAL + VERTICALMENTE dentro de la celda 22% × 24 mm.
            // Usamos $fitbox = 'CM' (Center-Middle) — TCPDF escala la imagen para que
            // entre dentro del bbox preservando aspect ratio y la centra en ambos ejes.
            // Padding interno de 1 mm para que el logo no toque el borde de la celda.
            $padding = 1;
            $bx = $cabX + $padding;                 // 13
            $by = $cabY + $padding;                 // 7
            $bw = $logoCellW - ($padding * 2);      // 38.92
            $bh = $cabH - ($padding * 2);           // 22
            // Image(file, x, y, w, h, type, link, align, resize, dpi, palign, ismask, imgmask, border, fitbox, hidden, fitonpage, alt, altimgs)
            $this->Image($img, $bx, $by, $bw, $bh, 'JPG', '', '', false, 300, '', false, false, 0, 'CM', false, false);
        }

        // Sello + titulo + placeholder del logo, todo dentro de una tabla con border="1".
        // rowspan="5" hace que la celda del logo y la del titulo ocupen las 5 filas
        // del sello sin tener que dibujar lineas manuales.
        //
        // Fuente: TCPDF no trae Arial nativamente — solo helvetica (visualmente
        // identica: Arial fue creada como sustituto de Helvetica). Forzamos
        // face="helvetica" explicito en cada <font> para garantizar consistencia
        // y que ningun fragmento herede una fuente distinta.
        //
        // VERTICAL-CENTER del titulo: TCPDF NO centra verticalmente el contenido
        // de una celda con rowspan aunque pongas valign="middle". El truco que
        // SI funciona es envolver el titulo en un <div> con line-height igual a
        // la altura del rowspan en puntos — el texto queda centrado en el line-box.
        //
        // Layout del sello (columna derecha, 28%):
        //   Fila 1: N° de Nota: NE-YYYY-NNNN
        //   Fila 2: CODIGO: VID-FO-GEN-019
        //   Fila 3: FECHA EMIS: 01/10/19
        //   Fila 4: REV: 1. FECHA REV: 06/10/23
        //   Fila 5: PAG. X DE Y
        // Cada fila del sello lleva valign="middle" para que su texto se vea
        // centrado vertical dentro de la celda (en especial "PAG. X DE Y", la
        // ultima fila que tendia a quedar pegada arriba).
        //
        // PAG: usamos numeros REALES (PageNo + getNumPages) en vez de los alias
        // {:pnb:}/{:ptp:}. Los alias son strings largos que TCPDF reemplaza por
        // el numero corto DESPUES de calcular el centrado -> el texto "PAG. 1
        // DE 1" quedaba shifteado a la izquierda porque el centrado se computo
        // para "PAG. {:pnb:} DE {:ptp:}" (mucho mas ancho). Las Notas son de 1
        // pagina, asi que la cuenta real es exacta.
        $page = $this->PageNo() . ' DE ' . max(1, $this->getNumPages());
        $numNota = $this->numeroNota ?? '';
        // line-height aprox = altura del rowspan en pt (68pt). El font-size del
        // titulo es 13pt, asi que line-height:68pt deja ~27pt arriba y ~27pt abajo
        // del texto -> visualmente centrado en el rowspan.
        $tituloDiv = '<div style="text-align:center;line-height:' . ($headerHeight - 4) . 'pt;font-family:helvetica;font-size:13pt;font-weight:bold;">NOTA DE ENTREGA DE MATERIALES</div>';

        $html = '<table border="1" cellpadding="2" cellspacing="0" width="100%">'
              . '<tr>'
              .   '<td width="22%" rowspan="5" height="' . $headerHeight . '">&nbsp;</td>'
              .   '<td width="50%" rowspan="5" height="' . $headerHeight . '" align="center" valign="middle">' . $tituloDiv . '</td>'
              .   '<td width="28%" align="center" valign="middle"><font face="helvetica" size="8"><b>N° de Nota:</b> ' . htmlspecialchars($numNota, ENT_QUOTES, 'UTF-8') . '</font></td>'
              . '</tr>'
              . '<tr><td width="28%" align="center" valign="middle"><font face="helvetica" size="7"><b>CODIGO:</b> VID-FO-GEN-019</font></td></tr>'
              . '<tr><td width="28%" align="center" valign="middle"><font face="helvetica" size="7">FECHA EMIS: 01/10/19</font></td></tr>'
              . '<tr><td width="28%" align="center" valign="middle"><font face="helvetica" size="7">REV: 1. FECHA REV: 06/10/23</font></td></tr>'
              . '<tr><td width="28%" align="center" valign="middle"><div style="text-align:center;font-family:helvetica;font-size:7pt;">PAG. ' . $page . '</div></td></tr>'
              . '</table>';

        // Fuente del documento = helvetica (Arial-equivalente). Tambien la setea
        // notaEntregaPdf() antes del writeHTML del cuerpo — la repetimos aqui
        // por si el Header() se invoca antes que el primer SetFont del body.
        $this->SetFont('helvetica', '', 7);
        // x=12, y=6 → margen izquierdo y top del cabezote.
        $this->writeHTMLCell($cabW, 0, $cabX, $cabY, $html, 0, 0, 0, true, 'L', true);
    }

    public function Footer()
    {
        $this->SetY(-10);
        $this->SetFont('helvetica', 'I', 7);
        // writeHTMLCell en vez de Cell(): Cell() con helvetica no procesa UTF-8
        // y rompe los acentos (la 'ó' de "Gestión" se mostraba como "Ã³").
        // writeHTMLCell respeta el encoding del documento (configurado como UTF-8).
        $this->writeHTMLCell(0, 6, '', $this->GetY(), '<div style="text-align:right;font-family:helvetica;font-style:italic;font-size:7pt;">Sistema de Gesti&oacute;n VIDALSA</div>', 0, 0, 0, true, 'R', true);
    }
}
