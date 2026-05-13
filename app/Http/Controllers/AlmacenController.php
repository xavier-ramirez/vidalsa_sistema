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
 * Visibilidad (reusa Usuario::NIVEL_ACCESO, ver Almacen::visiblesPara()):
 *  - GLOBAL (NIVEL_ACCESO=1) / super.admin / 'almacen.view.all' → ve todos los almacenes.
 *  - LOCAL  (NIVEL_ACCESO=2) → SOLO los almacenes PROYECTO de sus frentes; NUNCA los GENERAL.
 *
 * Permisos (claves en la columna PERMISOS):
 *  - (consulta)          : cualquier usuario autenticado (alcance limitado por visiblesPara()).
 *  - almacen.view.all    : ver el stock de CUALQUIER almacén (override del alcance).
 *  - almacen.manage      : crear/editar almacenes y productos.
 *  - almacen.movimiento  : registrar entradas / salidas / ajustes / traspasos / mínimo.
 *  (super.admin cubre todas.)
 */
class AlmacenController extends Controller
{
    public function __construct(private InventarioService $inventario)
    {
        // La consulta queda bajo 'auth' (lo aplica el grupo de rutas padre).
        $this->middleware('can:almacen.manage')->only([
            'storeAlmacen', 'updateAlmacen', 'destroyAlmacen',
            'storeProducto', 'updateProducto', 'destroyProducto',
        ]);
        $this->middleware('can:almacen.movimiento')->only([
            'registrarMovimientoLote', 'actualizarMinimo',
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
     * - wantsJson()  → { html (filas), pagination, stats, distribucionHtml, almacen }
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

        // Almacén seleccionado: el de la request si es visible; si no, el primero visible.
        $almacenSel = null;
        if ($request->filled('id_almacen')) {
            $almacenSel = $almacenes->firstWhere('ID_ALMACEN', (int) $request->input('id_almacen'));
        }
        if (!$almacenSel) {
            $almacenSel = $almacenes->first();
        }
        $idAlmacenSel = $almacenSel?->ID_ALMACEN;
        $hayInventario = $idAlmacenSel !== null;

        // Peticiones AJAX (cambio de filtro / paginación): devuelven las filas + stats.
        if ($request->wantsJson()) {
            $productos = $hayInventario
                ? $this->productosConSaldoQuery($idAlmacenSel, $request)->orderBy('productos_inventario.NOMBRE')->paginate(50)->withQueryString()
                : null;
            return response()->json([
                'almacen'         => $almacenSel,
                'html'            => view('admin.almacen.partials.table_rows', ['productos' => $productos, 'almacen' => $almacenSel, 'inicial' => false])->render(),
                'pagination'      => $productos ? (string) $productos->links('vendor.pagination.custom-sliding') : '',
                'stats'           => $this->statsInventario($idAlmacenSel, $request),
                'distribucionHtml'=> view('admin.almacen.partials.distribucion_stats', ['distribucion' => $this->distribucionPorCategoria($idAlmacenSel, $request)])->render(),
            ]);
        }

        // Carga HTML: la tabla abre VACÍA — las filas se piden por AJAX en cuanto el usuario usa un filtro.
        $categorias    = $this->categoriasDistintas();
        $productosLista = ProductoInventario::activos()->orderBy('NOMBRE')->get(['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM']);
        $frentesLista  = \App\Models\FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE')->get(['ID_FRENTE', 'NOMBRE_FRENTE']);

        // NOTA: $traspasosPorRecibir (banner amarillo + badge del nav menu) NO se calcula aquí —
        // lo provee el View Composer registrado en AppServiceProvider para 'layouts.estructura_base',
        // así el badge aparece desde CUALQUIER página del sistema.

        return view('admin.almacen.index', [
            'almacenes'      => $almacenes,
            'almacenSel'     => $almacenSel,
            'productos'      => null,
            'categorias'     => $categorias,
            'productosLista' => $productosLista,
            'frentesLista'   => $frentesLista,
            'stats'          => $this->statsInventario($idAlmacenSel, $request),
            'distribucion'   => $this->distribucionPorCategoria($idAlmacenSel, $request),
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

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $q->where(function ($s) use ($term) {
                $s->where('productos_inventario.CODIGO', 'like', "%{$term}%")
                  ->orWhere('productos_inventario.NOMBRE', 'like', "%{$term}%");
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
        // Código opcional: si no se escribió, se genera automáticamente (PRD-####).
        // Si se escribió, se respeta tal cual (sirve para importar los códigos que la gente ya tiene en su Excel).
        if (empty($data['CODIGO'])) {
            $data['CODIGO'] = $this->generarCodigoProducto();
        }
        $data['CREADO_POR'] = optional($request->user())->ID_USUARIO;

        $producto = ProductoInventario::create($data);

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
            'cantidad_minima' => 'nullable|numeric|min:0',
        ]);
        $this->assertPuedeVerAlmacen($request, $idAlmacen);

        $stock = $this->inventario->asegurarStock($idAlmacen, $request->integer('id_producto'));
        $stock->CANTIDAD_MINIMA = $request->filled('cantidad_minima') ? (float) $request->input('cantidad_minima') : null;
        $stock->save();

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
        $q = MovimientoInventario::query()
            ->with(['producto:ID_PRODUCTO,CODIGO,NOMBRE,UM', 'almacen:ID_ALMACEN,NOMBRE,TIPO', 'almacenContraparte:ID_ALMACEN,NOMBRE', 'usuario:ID_USUARIO,NOMBRE_COMPLETO', 'frente:ID_FRENTE,NOMBRE_FRENTE']);

        if ($request->filled('id_almacen') && $request->input('id_almacen') !== 'all') {
            $idAlmacen = $request->integer('id_almacen');
            $this->assertPuedeVerAlmacen($request, $idAlmacen);
            $q->where('ID_ALMACEN', $idAlmacen);
        } else {
            // Limitar a almacenes visibles para el usuario.
            $q->whereIn('ID_ALMACEN', Almacen::visiblesPara($request->user())->pluck('ID_ALMACEN'));
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
                'total'      => $paginator->total(),
            ]);
        }

        return view('admin.almacen.movimientos', [
            'movimientos'  => $paginator,
            'total'        => $paginator->total(),
            'almacenes'    => Almacen::visiblesPara($request->user())->orderBy('TIPO')->orderBy('NOMBRE')->get(['ID_ALMACEN', 'NOMBRE', 'TIPO']),
            'frentesLista' => \App\Models\FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE')->get(['ID_FRENTE', 'NOMBRE_FRENTE']),
        ]);
    }

    /**
     * Registra un MOVIMIENTO con varias líneas — un "documento" de inventario al
     * estilo de un ERP de tienda: ENTRADA / SALIDA / AJUSTE con N productos, todo
     * en UNA transacción (si una línea falla, no se aplica nada).
     *
     * NOTA: el tipo TRASPASO se RETIRÓ de este endpoint. Los traspasos entre
     * almacenes pasan ahora SIEMPRE por el flujo de Pedido de Recepción
     * (POST /admin/almacen/recepcion con enviar_ahora=true), donde el destino
     * confirma la llegada. Ver TraspasoController y TraspasoService.
     *
     * Body:
     *  - tipo                : ENTRADA | SALIDA | AJUSTE
     *  - id_almacen          : almacén origen del movimiento
     *  - fecha, referencia, motivo, notas : opcionales
     *  - id_frente           : opcional (SALIDA: frente que CONSUME el producto;
     *                          en ENTRADA / AJUSTE se ignora).
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
            'referencia'           => 'nullable|string|max:100',
            // Campos de la Nota de Entrega de Materiales (solo se usan en SALIDA).
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

        // Resolución del frente del movimiento:
        //   - SALIDA  → el usuario elige el frente que CONSUME el producto (proyecto destino).
        //   - ENTRADA/AJUSTE en almacén PROYECTO con UN único frente asociado → autollenamos
        //     con ese frente para que el movimiento aparezca atribuido al proyecto correcto
        //     en la bitácora (de lo contrario quedaba NULL y la columna "Destino" se veía
        //     vacía aunque el almacén perteneciera a un frente claro).
        //   - ENTRADA/AJUSTE en almacén GENERAL o PROYECTO multi-frente → queda NULL (el
        //     movimiento es "del almacén" sin proyecto específico).
        if ($data['tipo'] === 'SALIDA') {
            $idFrente = $data['id_frente'] ?? null;
        } else {
            $idFrente = $data['id_frente'] ?? null;
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
        // Sólo en SALIDA devolvemos la URL del PDF de Nota de Entrega; el frontend la abre en pestaña nueva.
        if ($data['tipo'] === 'SALIDA') {
            $payload['nota_url']    = route('almacen.nota-entrega', ['numero' => $result['numero_nota']]);
            $payload['numero_nota'] = $result['numero_nota'];
        }

        return response()->json($payload, 201);
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
     *                           registrarMovimientoLote inmediatamente tras crear la nota,
     *                           y la URL que se abre en la pestaña pre-abierta del modal
     *                           de salida).
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
        $datos = [
            'numero_nota'   => $hd->NUMERO_NOTA ?? '',
            'proyecto'      => $hd->frente?->NOMBRE_FRENTE ?? '',
            'contrato'      => $hd->NUMERO_CONTRATO ?? '',
            'fecha'         => optional($hd->FECHA)->format('d/m/Y') ?: now()->format('d/m/Y'),
            'rq'            => $hd->NUMERO_RQ ?? '',
            'solicitante'   => $hd->SOLICITANTE ?? '',
            'departamento'  => $hd->DEPARTAMENTO ?? '',
            'almacen'       => $hd->almacen?->NOMBRE ?? '',
            'entregado_por' => $hd->usuario?->NOMBRE_COMPLETO ?? '',
            'motivo'        => $hd->MOTIVO ?? '',
        ];

        $pdf = new NotaEntregaPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(12, 42, 12);   // top=42 para que el contenido empiece debajo del cabezote
        $pdf->SetHeaderMargin(6);
        $pdf->SetFooterMargin(10);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->SetTitle('Nota de Entrega de Materiales');
        $pdf->SetAuthor('Constructora Vidalsa 27, C.A.');
        $pdf->SetCreator('Sistema de Gestión VIDALSA');
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 9.5);

        $html = view('admin.almacen.nota_entrega_pdf', [
            'datos' => $datos,
            'movs'  => $movs,
        ])->render();
        $pdf->writeHTML($html, true, false, true, false, '');

        $slug = $hd->NUMERO_NOTA ?: ($hd->NUMERO_RQ ?: ('LOTE-' . $hd->ID_MOVIMIENTO));
        return $pdf->Output('Nota_Entrega_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $slug) . '.pdf', 'I');
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

        $q = MovimientoInventario::with(['producto', 'almacen', 'frente', 'usuario'])
            ->where('TIPO', MovimientoInventario::TIPO_SALIDA)
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
            'CODIGO'    => ['nullable', 'string', 'max:30', Rule::unique('almacenes', 'CODIGO')->ignore($ignoreId, 'ID_ALMACEN')],
            'NOMBRE'    => ['required', 'string', 'max:150', Rule::unique('almacenes', 'NOMBRE')->ignore($ignoreId, 'ID_ALMACEN')],
            'TIPO'      => ['required', Rule::in([Almacen::TIPO_GENERAL, Almacen::TIPO_PROYECTO])],
            'UBICACION' => 'nullable|string|max:150',
            'ESTATUS'   => 'nullable|in:ACTIVO,INACTIVO',
            'NOTAS'     => 'nullable|string',
            'frentes'   => 'sometimes|array',
            'frentes.*' => 'integer|exists:frentes_trabajo,ID_FRENTE',
        ]);

        // Normalizar.
        $data['NOMBRE'] = mb_strtoupper(trim($data['NOMBRE']));
        if (!empty($data['CODIGO'])) $data['CODIGO'] = mb_strtoupper(trim($data['CODIGO']));
        if (!empty($data['UBICACION'])) $data['UBICACION'] = mb_strtoupper(trim($data['UBICACION']));
        $data['ESTATUS'] = $data['ESTATUS'] ?? 'ACTIVO';

        unset($data['frentes']); // se sincroniza aparte
        return $data;
    }

    private function validarProducto(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'CODIGO'    => ['nullable', 'string', 'max:50', Rule::unique('productos_inventario', 'CODIGO')->ignore($ignoreId, 'ID_PRODUCTO')],
            'NOMBRE'    => 'required|string|max:200',
            'UM'        => 'required|string|max:20',
            'CATEGORIA' => 'nullable|string|max:100',
            'ESTATUS'   => 'nullable|in:ACTIVO,INACTIVO',
            'NOTAS'     => 'nullable|string',
        ]);

        $data['CODIGO']    = !empty($data['CODIGO']) ? mb_strtoupper(trim($data['CODIGO'])) : null;
        $data['NOMBRE']    = mb_strtoupper(trim($data['NOMBRE']));
        $data['UM']        = mb_strtoupper(trim($data['UM']));
        $data['CATEGORIA'] = !empty($data['CATEGORIA']) ? mb_strtoupper(trim($data['CATEGORIA'])) : null;
        $data['ESTATUS']   = $data['ESTATUS'] ?? 'ACTIVO';

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
    public function Header()
    {
        // ── Logo (izquierda, alineado con el margen izquierdo del documento) ──
        $img = public_path('img/imagen_uno.jpg');
        if (file_exists($img)) {
            // x=12 (== margen izquierdo del SetMargins), y=6, w=0(auto = mantiene aspecto), h=28mm.
            // Equivale visualmente al área A1:B5 del Excel oficial.
            $this->Image($img, 12, 6, 0, 28, 'JPG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }

        // ── Título central ──
        // Franja entre el logo (≈55mm) y el sello (≈145mm). 85mm de ancho útil.
        $this->SetFont('helvetica', 'B', 15);
        $this->writeHTMLCell(
            85, 0, 55, 12,
            '<div style="text-align:center;font-weight:bold;">NOTA DE ENTREGA<br>DE MATERIALES</div>',
            0, 0, 0, true, 'C', true
        );

        // ── Sello de formato (derecha, 5 filas idénticas al Excel original) ──
        // Las fechas EMIS/REV y el CODIGO son estáticos (parte del formulario oficial), NO
        // datos del movimiento — por eso van hardcoded y no como variables del controlador.
        $this->SetFont('helvetica', '', 7);
        $stamp = '<table border="1" cellpadding="2" style="font-size:7pt;border-collapse:collapse;">'
               . '<tr><td width="100%" align="left"><b>CODIGO:</b></td></tr>'
               . '<tr><td align="center"><b>VID-FO-GEN-019</b></td></tr>'
               . '<tr><td align="left">FECHA EMIS: 01/10/19</td></tr>'
               . '<tr><td align="left">REV: 1. FECHA REV: 06/10/23</td></tr>'
               . '<tr><td align="center">PAG. ' . $this->getAliasNumPage() . ' DE ' . $this->getAliasNbPages() . '</td></tr>'
               . '</table>';
        $this->writeHTMLCell(53, 0, 145, 6, $stamp, 0, 0, 0, true, 'L', true);
    }

    public function Footer()
    {
        $this->SetY(-10);
        $this->SetFont('helvetica', 'I', 7);
        // Cell() en vez de writeHTMLCell porque el texto es ASCII puro (sin acentos) — más rápido
        // y suficiente. Mismo criterio que ReporteFallaPDF::Footer().
        $emitido = 'Sistema de Gestion VIDALSA - emitido el ' . \Carbon\Carbon::now()->format('d/m/Y H:i');
        $this->Cell(0, 6, $emitido, 0, 0, 'R');
    }
}
