<?php

namespace App\Http\Controllers;

use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\MovimientoInventario;
use App\Models\ProductoEquivalencia;
use App\Models\ProductoInventario;
use App\Models\Traspaso;
use App\Services\InventarioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Traits\ExcelLogoCorporativo;
use RuntimeException;
use Throwable;

/**
 * Módulo de Almacén / Inventario.
 *
 *  - Almacenes principales (TIPO=GENERAL) y de proyecto (TIPO=PROYECTO). AMBOS se
 *    ligan a frentes vía el pivote `almacen_frentes` — la asociación a frentes define
 *    QUÉ usuarios LOCAL ven el almacén, sin importar el TIPO.
 *  - Catálogo global de productos (CODIGO, PRODUCTO/NOMBRE, UM, CATEGORIA).
 *  - Stock por almacén + movimientos (entradas/salidas/ajustes/traspasos) vía InventarioService.
 *
 * Visibilidad (depende de `usuarios.NIVEL_ACCESO_ALMACEN` + los frentes, ver Almacen::visiblesPara):
 *  - GLOBAL (NIVEL_ACCESO_ALMACEN=1) → ve todos los almacenes. La UI abre preseleccionada en el
 *    almacén ligado a su frente (Usuario::almacenPorDefecto) pero puede filtrar a otros.
 *  - LOCAL  (NIVEL_ACCESO_ALMACEN=2) → los almacenes (GENERAL o PROYECTO) asociados a alguno de
 *    sus frentes — los que comparten frente con el usuario.
 *  - NO depende del rol ni de permisos (super.admin / almacen.view.all no influyen aquí).
 *
 * Permisos (claves en la columna PERMISOS):
 *  - (consulta)         : cualquier usuario autenticado (alcance limitado por visiblesPara()).
 *  - super.admin        : crear / editar / eliminar almacenes (warehouses). NO concede
 *                         por sí solo `almacen.productos` ni `almacen.movimiento` — ambas
 *                         son claves EXCLUSIVAS (ver Usuario::PERMISOS_EXPLICITOS).
 *  - almacen.productos  : registrar y editar productos del catálogo. Clave EXCLUSIVA: ni
 *                         siquiera super.admin pasa este gate sin la clave literal en PERMISOS.
 *  - almacen.movimiento : registrar entradas, salidas, ajustes, traspasos, mínimo y
 *                         confirmar recepción de traspasos. Clave EXCLUSIVA: ni siquiera
 *                         super.admin pasa sin la clave literal en PERMISOS.
 */
class AlmacenController extends Controller
{
    use ExcelLogoCorporativo;

    public function __construct(
        private InventarioService $inventario,
        private \App\Services\TraspasoService $traspasos,
    ) {
        // La consulta queda bajo 'auth' (lo aplica el grupo de rutas padre). Gates:
        //   super.admin        → CRUD de almacenes (warehouses).
        //   almacen.productos  → CRUD del catalogo de productos.
        //   almacen.movimiento → registrar lotes (entradas/salidas/ajustes/traspasos)
        //                        y confirmar recepciones.
        $this->middleware('can:super.admin')->only([
            'storeAlmacen', 'updateAlmacen', 'destroyAlmacen',
            // Borrado PERMANENTE de un producto desde la papelera (irreversible).
            'eliminarPermanenteProducto',
        ]);
        // storeProducto NO entra en este middleware estricto — el flujo "Recepcion
        // ODC" (/admin/almacen/recepcion/nueva) crea productos al vuelo cuando llega
        // material nuevo, y el usuario tipico tiene SOLO almacen.movimiento (no
        // almacen.productos). El chequeo se hace dentro del metodo aceptando cualquiera
        // de los dos permisos. updateProducto requiere almacen.productos.
        $this->middleware('can:almacen.productos')->only([
            'updateProducto',
        ]);
        // destroyProducto: borrar un producto del catalogo exige almacen.nota.eliminar
        // (la misma clave que elimina Notas de Entrega) — decision del cliente: una
        // unica clave gobierna los borrados del modulo Almacen.
        $this->middleware('can:almacen.nota.eliminar')->only([
            'destroyProducto', 'papeleraProductos', 'restaurarProducto',
        ]);
        // registrarMovimientoLote NO entra en este middleware: valida 'almacen.movimiento'
        // con un guard al inicio del metodo para poder devolver un mensaje que NOMBRA la
        // clave faltante (el middleware `can:` solo da el generico "no tienes permiso").
        $this->middleware('can:almacen.movimiento')->only([
            'actualizarMinimo', 'previewSalidaPdf',
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

        // Guard: usuario LOCAL (restringido) sin almacenes visibles → redirigir al menu
        // con notificacion. El LOCAL NO puede crear almacenes (esa accion es solo super.admin),
        // asi que entrar a una pantalla vacia donde no puede hacer nada era frustrante. El
        // GLOBAL sigue entrando aunque no haya almacenes (puede crearlos con "Nuevo almacén").
        // Se respeta el flujo AJAX (paginacion / cambio de filtro vuelven JSON) para no romperlo.
        // "Restringido" = criterio ÚNICO Almacen::usuarioEsGlobal (== Usuario::veTodosLosFrentes).
        if (!$request->wantsJson() && $almacenes->isEmpty() && !Almacen::usuarioEsGlobal($user)) {
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
            // Lote de 120 (antes 50): trae más filas por tanda para que el scroll fluya como
            // en /admin/equipos y el indicador "cargar más" rara vez se vea. El render de 120
            // filas de producto es liviano; el IntersectionObserver (rootMargin 1000px) precarga
            // la siguiente tanda antes de llegar al final.
            $PAGE_SIZE = 120;
            $offset = max(0, (int) $request->input('offset', 0));
            $rows = collect();
            $hasMore = false;

            // La tabla del almacén arranca VACÍA y solo muestra inventario cuando hay un
            // filtro de contenido activo (mismo criterio que la carga inicial HTML, donde
            // 'productos' => null y $inicial pinta el estado "usá los filtros"). Sin este
            // chequeo, al limpiar la búsqueda con la "x" el AJAX volcaba TODO el inventario
            // del almacén en vez de volver al estado vacío. id_almacen NO cuenta como filtro
            // de contenido (es el contexto, no un filtro).
            $hayFiltro = $request->filled('search')
                || $request->filled('id_producto')
                || $request->filled('id_producto_in')
                || $request->filled('categoria')
                || $request->boolean('solo_bajo')
                || $request->boolean('solo_con_saldo')
                || $request->boolean('ver_todo'); // acción explícita "Ver todo el stock"

            if ($hayInventario && $hayFiltro) {
                $rows = $this->productosConSaldoQuery($idAlmacenSel, $request)
                    ->orderBy('productos_inventario.NOMBRE')
                    ->skip($offset)->take($PAGE_SIZE + 1)
                    ->get();
                $hasMore = $rows->count() > $PAGE_SIZE;
                if ($hasMore) $rows = $rows->slice(0, $PAGE_SIZE)->values();
                // Equivalencias (nºs de parte alternos) + modelos de equipo compatibles: para
                // el tooltip de la fila y el modal de detalles. Solo los filtros tienen datos;
                // el resto de productos trae relaciones vacías. 2 consultas batcheadas.
                $rows->load(['equivalencias', 'modelosCompatibles']);
                // La MARCA de cada modelo NO vive en caracteristicas_modelo sino en `equipos`.
                // Batch: ID_ESPEC → MARCA, y la pegamos a cada modelo (marca_equipo) para poder
                // mostrar "Tipo · Marca · Modelo" en el tooltip y el modal.
                $especIds = $rows->flatMap->modelosCompatibles->pluck('ID_ESPEC')->unique()->filter()->all();
                if ($especIds) {
                    $marcaPorEspec = \App\Models\Equipo::whereIn('ID_ESPEC', $especIds)
                        ->get(['ID_ESPEC', 'MARCA'])->groupBy('ID_ESPEC')
                        ->map(fn ($g) => $g->pluck('MARCA')->filter()->first());
                    foreach ($rows as $r) {
                        foreach ($r->modelosCompatibles as $m) {
                            $m->marca_equipo = $marcaPorEspec[$m->ID_ESPEC] ?? null;
                        }
                    }
                }
            }
            // En las páginas siguientes del scroll infinito ($offset > 0) NO devolvemos la
            // empty-state row del partial — sería un mensaje "Sin coincidencias" appended al
            // final de las filas ya pintadas. Si el lote viene vacío, el html es ''.
            // Sin filtro → inicial=true para pintar "usá los filtros" (no "sin coincidencias").
            $html = ($offset > 0 && $rows->isEmpty())
                ? ''
                : view('admin.almacen.partials.table_rows', ['productos' => $rows, 'almacen' => $almacenSel, 'inicial' => !$hayFiltro])->render();
            $resp = [
                'almacen'    => $almacenSel,
                'html'       => $html,
                'hasMore'    => $hasMore,
                'nextOffset' => $hasMore ? $offset + $PAGE_SIZE : null,
            ];
            // Stats y distribución solo en la primera página (offset=0) — son costosos y
            // no cambian al hacer scroll, solo cuando el usuario cambia un filtro.
            if ($offset === 0) {
                if ($hayFiltro) {
                    $resp['stats'] = $this->statsInventario($idAlmacenSel, $request);
                    // El sidebar "Distribución de Inventario" tiene DOS modos:
                    //  - normal: lista por categoria (cuando el filtro NO apunta a un producto unico)
                    //  - cruzado: cuando el usuario clickeo una sugerencia (id_producto en la URL),
                    //    el panel muestra ese producto en otros almacenes visibles — util para saber
                    //    a donde pedir un traspaso si el almacen actual quedo en cero o bajo minimo.
                    $idProductoSel = $request->filled('id_producto') ? (int) $request->input('id_producto') : null;
                    $productoOtros = $idProductoSel ? $this->productoEnOtrosAlmacenes($idProductoSel, $idAlmacenSel, $user) : null;
                    $resp['distribucionHtml'] = view('admin.almacen.partials.distribucion_stats', [
                        'distribucion'  => $this->distribucionPorCategoria($idAlmacenSel, $request),
                        'productoOtros' => $productoOtros,
                    ])->render();
                } else {
                    // Sin filtro = estado inicial. Los KPIs (Consolidado) muestran el total
                    // del almacén — NO "—" — coherente con la carga inicial HTML: al limpiar
                    // la "x", el Consolidado sigue visible. La DISTRIBUCIÓN sí vuelve a vacío
                    // (solo se llena con un filtro activo).
                    $resp['stats'] = $this->statsInventario($idAlmacenSel, $request);
                    $resp['distribucionHtml'] = view('admin.almacen.partials.distribucion_stats', [
                        'distribucion'  => collect(),
                        'productoOtros' => null,
                    ])->render();
                }
            }
            return response()->json($resp);
        }

        // Carga HTML: la tabla abre VACÍA — las filas se piden por AJAX en cuanto el usuario usa un filtro.
        $categorias    = $this->categoriasDistintas();
        // Unidades de medida distintas ya registradas — alimentan el autocomplete del campo UM del modal de producto.
        $unidadesMedida = ProductoInventario::activos()
            ->select('UM')->distinct()->orderBy('UM')->pluck('UM')->filter()->values();
        // Productos para el AUTOCOMPLETE del buscador. Fuente ÚNICA (misma que recepción):
        // trae CODIGO/NOMBRE/UM/CATEGORIA + EQUIV/PARTE/PARTES (nºs de parte de los filtros) para
        // sugerir al teclear un alterno (p.ej. "MIS0531"), no solo por código/nombre. CATEGORIA
        // permite avisar cuando un material existe pero es de OTRA categoría (badge + toast).
        // El catálogo de productos (window.almProductosLista) ya NO se embebe aquí: se pide
        // por AJAX (productosAutocomplete) tras renderizar, para que el módulo abra rápido.
        // CONTRATOS se carga junto al frente para alimentar las sugerencias del campo
        // "Contrato N°" del modal "Registrar salida" (Nota de Entrega).
        $frentesLista  = \App\Models\FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->orderBy('NOMBRE_FRENTE')
            ->get(['ID_FRENTE', 'NOMBRE_FRENTE', 'CONTRATOS']);

        // CONTEO de notas de entrega pendientes de confirmar — alimenta el banner rojo
        // "N por confirmar" que enlaza a la bandeja. Recepción es PERSONAL: se cuenta SOLO
        // contra los almacenes ASOCIADOS al usuario (Almacen::asociadosIdsDe = ligados a sus
        // frentes), NO visiblesPara (= TODOS para un GLOBAL) — si no, una cuenta que solo
        // EMITE veía notas de otros almacenes que no recibe. Mismo criterio que el badge.
        $notasPendientes = Traspaso::whereIn('ESTADO', Traspaso::ESTADOS_RECIBIBLES)
            ->whereIn('ID_ALMACEN_DESTINO', Almacen::asociadosIdsDe($user))
            ->count();

        return view('admin.almacen.index', [
            'almacenes'          => $almacenes,
            'almacenSel'         => $almacenSel,
            'productos'          => null,
            'categorias'         => $categorias,
            'frentesLista'       => $frentesLista,
            // El Consolidado de Inventario (KPIs: PRODUCTOS / Con stock / Stock bajo) SÍ se
            // calcula en la carga inicial — el cliente quiere verlo apenas abre el módulo,
            // sin esperar a filtrar. Es 1 query agregada (statsInventario), barata. Sin
            // filtros activos devuelve el consolidado completo del almacén.
            // La DISTRIBUCIÓN por categoría sigue diferida a AJAX (se llena al filtrar).
            'stats'              => $this->statsInventario($idAlmacenSel, $request),
            'distribucion'       => collect(),
            'unidadesMedida'     => $unidadesMedida,
            'notasPendientes'    => $notasPendientes,
        ]);
    }

    /**
     * Catálogo de productos para el buscador FuzzySearch del cliente (window.almProductosLista).
     * Antes se embebía inline en index() (~500 KB de 1155 productos) → el módulo abría lento.
     * Ahora la página arranca vacía y pide esta lista por AJAX tras renderizar. FUENTE ÚNICA:
     * ProductoInventario::listaAutocomplete() (la misma que usa el índice y la recepción).
     * Read-only (solo lectura del catálogo activo), sin permiso extra — igual que el índice.
     */
    public function productosAutocomplete(Request $request)
    {
        return response()->json(ProductoInventario::listaAutocomplete());
    }

    /**
     * Query base del inventario: productos_inventario activos + stock del almacén
     * dado (LEFT JOIN) + filtros del listado. SIN columnas explícitas: el llamador
     * añade el select que necesita (filas / count / agregado).
     *
     * COMPORTAMIENTO HÍBRIDO (resuelve dos confusiones opuestas):
     *  - Navegación normal: se exige fila en `almacen_stock` (whereNotNull abajo),
     *    así un almacén muestra SOLO sus productos y no se inunda con el catálogo
     *    global a saldo 0.
     *  - Selección puntual (id_producto desde la sugerencia, o id_producto_in del
     *    "ver solo seleccionados"): NO se exige la fila → el LEFT JOIN deja ver ese
     *    producto aunque aún no tenga stock aquí (saldo 0). Así, tras hacer clic en
     *    una sugerencia la tabla NUNCA queda vacía (que hacía creer "no registrado"
     *    y disparaba que la persona lo registrara de nuevo).
     */

    /**
     * Tokeniza un término de búsqueda de productos: minúsculas, DESCARTA stopwords
     * (de/la/y…) y números sueltos de 1-2 dígitos. Si el término era SOLO ruido,
     * cae al término completo para no terminar con un WHERE vacío que devolvería
     * TODO el catálogo. Punto ÚNICO de verdad para que la tabla (/admin/almacen),
     * la bitácora (/almacen/movimientos) y el ranking de consumo busquen IGUAL.
     */
    private function tokenizarBusquedaProducto(string $term): array
    {
        $stop = ['de','del','la','el','los','las','un','una','unos','unas',
                 'y','e','o','u','a','en','con','para','por'];
        $tokens = array_values(array_filter(
            preg_split('/\s+/', mb_strtolower($term)),
            fn ($t) => $t !== '' && !in_array($t, $stop, true) && !preg_match('/^\d{1,2}$/', $t)
        ));
        return empty($tokens) ? [mb_strtolower($term)] : $tokens;
    }

    /**
     * Aplica la búsqueda tokenizada de productos sobre $q: AND entre tokens y, por
     * cada token >3 letras terminado en 's', prueba también el SINGULAR ("BOTAS"
     * encuentra "BOTA DE SEGURIDAD"). $cols son las columnas CODIGO/NOMBRE ya
     * calificadas según el contexto del llamador (tabla con JOIN → 'productos_inventario.X';
     * relación whereHas('producto') → 'X'). El fuzzy + ranking real vive en el
     * autocomplete del frontend; este LIKE es el fallback de "tipear + Enter".
     */
    private function aplicarBusquedaProducto($q, string $term, array $cols, bool $incluirEquivalencias = false): void
    {
        $tokens = $this->tokenizarBusquedaProducto($term);
        $q->where(function ($s) use ($tokens, $cols, $incluirEquivalencias) {
            foreach ($tokens as $tok) {
                $variantes = [$tok];
                if (mb_strlen($tok) > 3 && mb_substr($tok, -1) === 's') {
                    $variantes[] = mb_substr($tok, 0, -1);
                }
                $s->where(function ($t) use ($variantes, $cols, $incluirEquivalencias) {
                    foreach ($variantes as $v) {
                        foreach ($cols as $col) {
                            $t->orWhere($col, 'like', "%{$v}%");
                        }
                        // También por NÚMERO DE PARTE equivalente: escribir cualquier alterno
                        // (p.ej. "1000FG") encuentra el filtro aunque su código/nombre no lo
                        // contenga. Lo piden (flag=true) la tabla del inventario y las búsquedas
                        // de la pantalla de MOVIMIENTOS (bitácora y ranking "Consumo de Inventario"),
                        // así el Enter encuentra lo mismo que sugiere el autocomplete y ambos listados
                        // coinciden. El correlado usa productos_inventario.ID_PRODUCTO, que está en
                        // scope tanto en la tabla como dentro del whereHas('producto').
                        if ($incluirEquivalencias) {
                            $t->orWhereExists(function ($sub) use ($v) {
                                $sub->selectRaw('1')->from('producto_equivalencias')
                                    ->whereColumn('producto_equivalencias.ID_PRODUCTO', 'productos_inventario.ID_PRODUCTO')
                                    ->where('producto_equivalencias.NUMERO_PARTE', 'like', "%{$v}%");
                            });
                        }
                    }
                });
            }
        });
    }

    /**
     * Aplica los filtros de CONTENIDO (los que operan sobre columnas de
     * productos_inventario) a una query: id_producto_in, id_producto, search, categoria.
     * NO toca el JOIN con almacen_stock ni los filtros por stock (solo_bajo/solo_con_saldo),
     * que dependen del almacén y los maneja el llamador.
     *
     * Lo usan inventarioBaseQuery() (la tabla) y export() (la exportación), para que ambos
     * filtren IDÉNTICO y "exportar" devuelva justo lo que se ve en pantalla.
     *
     * @return bool true si `id_producto_in` hizo short-circuit (acotó a esos IDs e ignora
     *              el resto de filtros) — el llamador debe cortar ahí.
     */
    private function aplicarFiltrosContenido($q, Request $request): bool
    {
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
                return true; // short-circuit: ignoramos los demas filtros
            }
        }

        // `id_producto`: match EXACTO — lo envía el filtro "Descripción" cuando el usuario
        // hace clic en una sugerencia (quiere VER esa fila concreta, no las similares).
        // Tiene precedencia sobre `search` (que sí hace LIKE %term% con tokens y plural).
        if ($request->filled('id_producto')) {
            $q->where('productos_inventario.ID_PRODUCTO', '=', (int) $request->input('id_producto'));
        } elseif ($request->filled('search')) {
            // (cae aquí solo si NO vino id_producto — typed-and-Enter, no clic en sugerencia)
            $term = trim((string) $request->input('search'));
            $this->aplicarBusquedaProducto($q, $term, ['productos_inventario.CODIGO', 'productos_inventario.NOMBRE'], true);
        }
        if ($request->filled('categoria') && $request->input('categoria') !== 'all') {
            // Coincidencia parcial (igual que "search"): el filtro de categoría es un
            // input de texto con sugerencias, así que se va estrechando conforme se escribe.
            $cat = trim((string) $request->input('categoria'));
            $q->where('productos_inventario.CATEGORIA', 'like', "%{$cat}%");
        }

        return false;
    }

    private function inventarioBaseQuery(?int $idAlmacen, Request $request)
    {
        $q = ProductoInventario::query()->activos();

        $q->leftJoin('almacen_stock', function ($j) use ($idAlmacen) {
            $j->on('almacen_stock.ID_PRODUCTO', '=', 'productos_inventario.ID_PRODUCTO');
            if ($idAlmacen !== null) {
                $j->where('almacen_stock.ID_ALMACEN', '=', $idAlmacen);
            } else {
                $j->whereRaw('1 = 0'); // sin almacén → no devolver nada
            }
        });

        // Navegación normal → solo productos con fila de stock en este almacén
        // (replica el INNER JOIN clásico). Se EXCEPTÚA cuando el usuario pidió ver
        // un producto puntual (clic en sugerencia = id_producto, o "ver solo
        // seleccionados" = id_producto_in): ahí dejamos pasar el saldo 0 para que
        // el producto seleccionado siempre se muestre.
        //
        // TAMBIÉN se exceptúa cuando el usuario pide EXPLÍCITAMENTE ver el catálogo:
        // "Ver todo" (ver_todo), búsqueda por descripción (search) o categoría. Sin esto,
        // un almacén nuevo/sin stock (p.ej. "PRUEBA") no mostraba NADA y esos filtros
        // parecían rotos. En almacenes ya cargados NO cambia nada (todos sus productos
        // tienen fila de stock). Los productos sin stock en el almacén aparecen con saldo 0.
        // (solo_bajo / solo_con_saldo NO se exceptúan: por definición requieren stock.)
        $verProductoPuntual = $request->filled('id_producto') || $request->filled('id_producto_in');
        $verCatalogo        = $request->boolean('ver_todo')
                            || $request->filled('search')
                            || $request->filled('categoria');
        if (!$verProductoPuntual && !$verCatalogo) {
            $q->whereNotNull('almacen_stock.ID_PRODUCTO');
        }

        // Filtros de CONTENIDO (id_producto_in / id_producto / search / categoría),
        // compartidos con export() para que la exportación refleje EXACTAMENTE lo que
        // muestra la tabla. Si `id_producto_in` hizo short-circuit (modo "ver solo
        // seleccionados"), la query queda acotada a esos IDs e ignoramos el resto.
        if ($this->aplicarFiltrosContenido($q, $request)) {
            return $q;
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

    /**
     * Consolidado de Inventario del almacén seleccionado — SIEMPRE del almacén COMPLETO,
     * NO se acota por los filtros de la tabla (búsqueda/categoría). El sidebar es un
     * resumen del almacén, no del subconjunto filtrado: si dependiera del filtro, al
     * buscar algo puntual los tres KPIs caían a 0 mientras la alerta de stock bajo seguía
     * marcando 1 → incoherente. La TABLA sí se filtra; el Consolidado no.
     *
     * `$request` se mantiene en la firma (lo pasan los llamadores) pero ya no se usa:
     * el consolidado es deliberadamente independiente de los filtros.
     */
    private function statsInventario(?int $idAlmacen, Request $request): array
    {
        if ($idAlmacen === null) {
            return ['total' => 0, 'con_saldo' => 0, 'stock_bajo' => 0, 'unidades' => 0.0];
        }

        // Un SOLO query sobre los productos ACTIVOS con stock en este almacén (SIN filtros):
        //   total      → productos con fila de stock en el almacén
        //   con_saldo  → CANTIDAD > 0
        //   stock_bajo → tiene mínimo definido y CANTIDAD <= mínimo
        //   unidades   → suma física de existencias
        $row = ProductoInventario::query()->activos()
            ->join('almacen_stock', function ($j) use ($idAlmacen) {
                $j->on('almacen_stock.ID_PRODUCTO', '=', 'productos_inventario.ID_PRODUCTO')
                  ->where('almacen_stock.ID_ALMACEN', '=', $idAlmacen);
            })
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN almacen_stock.CANTIDAD > 0 THEN 1 ELSE 0 END) as con_saldo'),
                DB::raw('SUM(CASE WHEN almacen_stock.CANTIDAD_MINIMA IS NOT NULL AND almacen_stock.CANTIDAD <= almacen_stock.CANTIDAD_MINIMA THEN 1 ELSE 0 END) as stock_bajo'),
                DB::raw('COALESCE(SUM(almacen_stock.CANTIDAD), 0) as unidades')
            )
            ->first();

        return [
            'total'      => (int) ($row->total ?? 0),
            'con_saldo'  => (int) ($row->con_saldo ?? 0),
            'stock_bajo' => (int) ($row->stock_bajo ?? 0),
            'unidades'   => (float) ($row->unidades ?? 0),
        ];
    }

    /** Distribución de productos por categoría en el almacén seleccionado. */
    private function distribucionPorCategoria(?int $idAlmacen, Request $request)
    {
        if ($idAlmacen === null) {
            return collect();
        }
        // La Distribución cuenta STOCK REAL del almacén (productos con fila de stock), igual
        // que el Consolidado (statsInventario, INNER join). Forzamos whereNotNull para ANULAR
        // el bypass "verCatalogo" de inventarioBaseQuery: sin esto, al pedir "Ver todo"/buscar
        // en un almacén sin stock, la Distribución contaba TODO el catálogo (saldo 0) mientras
        // el Consolidado mostraba 0 → los dos paneles del sidebar se contradecían.
        return $this->inventarioBaseQuery($idAlmacen, $request)
            ->whereNotNull('almacen_stock.ID_PRODUCTO')
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
            ->whereIn('almacen_stock.ID_ALMACEN', $visibles)
            ->where('almacen_stock.CANTIDAD', '>', 0);
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

    /**
     * Frente al que se atribuye implícitamente un movimiento cuando el formulario
     * no trae frente destino explícito. Devuelve el ID solo si el almacén es
     * PROYECTO y tiene EXACTAMENTE UN frente asociado — en ese caso atribuir el
     * movimiento a ese frente es inequívoco y la bitácora muestra el nombre del
     * frente en la columna "Destino" en lugar de "—".
     *
     * Para almacenes GENERAL o PROYECTO multi-frente devuelve null (el movimiento
     * queda como "del almacén" sin proyecto específico, que es lo correcto:
     * no podemos adivinar a cuál de los frentes pertenece).
     *
     * Único llamador: registrarMovimientoLote (ENTRADA/AJUSTE). storeProducto NO lo usa
     * — aplica su propio criterio (primer frente asociado, sin exigir que sea el único);
     * ver el comentario de contraste en storeProducto.
     */
    private function frenteImplicitoDelAlmacen(int $idAlmacen): ?int
    {
        $alm = Almacen::with('frentes:ID_FRENTE')->find($idAlmacen);
        if ($alm && $alm->TIPO === Almacen::TIPO_PROYECTO && $alm->frentes->count() === 1) {
            return (int) $alm->frentes->first()->ID_FRENTE;
        }
        return null;
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
        // Permiso: aceptamos `almacen.productos` (admin del catalogo) O `almacen.movimiento`
        // (creacion al vuelo desde Recepcion ODC). Antes el middleware exigia estrictamente
        // almacen.productos y bloqueaba a los almacenistas que solo tienen almacen.movimiento,
        // rompiendo el flujo de /admin/almacen/recepcion/nueva cuando llega material nuevo
        // (pedido del cliente 2026-05-19).
        $user = $request->user();
        if (!$user || (!$user->can('almacen.productos') && !$user->can('almacen.movimiento'))) {
            return response()->json([
                'message' => 'No tienes permiso para crear productos.',
            ], 403);
        }

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

        // Si se pasó un almacén de contexto, DEBE ser visible para el usuario. Sin esto,
        // `exists:almacenes` dejaba que un LOCAL con almacen.movimiento inyectara stock
        // inicial + movimiento de kardex en un almacén ajeno (que ni siquiera ve) vía
        // id_almacen+cantidad_inicial. Mismo gate que registrarMovimientoLote/actualizarMinimo.
        if ($idAlmacen) {
            $this->assertPuedeVerAlmacen($request, (int) $idAlmacen);
        }

        if ($cantInicial > 0 && ! $request->user()?->can('almacen.movimiento')) {
            return response()->json([
                'message' => 'No tienes permiso para registrar movimientos de inventario (cantidad inicial > 0).',
            ], 403);
        }

        // Código opcional: si no se escribió, se genera automáticamente (numérico de
        // 6 dígitos, ver generarCodigoProducto). Si se escribió, se respeta tal cual
        // (sirve para importar los códigos que la gente ya tiene en su Excel).
        if (empty($data['CODIGO'])) {
            $data['CODIGO'] = $this->generarCodigoProducto();
        }
        $data['CREADO_POR'] = optional($request->user())->ID_USUARIO;

        $producto = DB::transaction(function () use ($data, $idAlmacen, $cantInicial, $request) {
            $producto = ProductoInventario::create($data);

            // INVARIANTE DEL SISTEMA (pedido del cliente 2026-05-19): todo producto del
            // catalogo debe aparecer en la tabla de inventario de CUALQUIER almacen activo
            // con stock=0, hasta que llegue una entrada que lo incremente. Asi el buscador
            // de cualquier modulo lo encuentra desde el momento de creacion, sin importar
            // si el producto fue registrado desde un almacen especifico o sin contexto.
            // Llamamos a asegurarStock (idempotente — usa insertOrIgnore internamente)
            // para preservar la logica del service (locking, eventos, no pisar filas
            // existentes con CANTIDAD > 0).
            $idsAlmacenes = Almacen::where('ESTATUS', 'ACTIVO')->pluck('ID_ALMACEN');
            foreach ($idsAlmacenes as $idAlm) {
                $this->inventario->asegurarStock((int) $idAlm, $producto->ID_PRODUCTO);
            }

            // STOCK INICIAL: solo si el cliente paso EXPLICITAMENTE id_almacen y una
            // cantidad > 0 (caso tipico: modal "Nuevo producto" de /admin/almacen con
            // el campo "Cantidad inicial" lleno). Otros flujos (creacion al vuelo en
            // recepcion/nueva) pasan id_almacen pero cantidad_inicial=0 — solo crean
            // las filas de stock, sin movimiento.
            if ($idAlmacen && $cantInicial > 0) {
                // STOCK INICIAL: atribuimos la entrada al PRIMER frente del almacen
                // (si tiene alguno), independiente del TIPO de almacen o de cuantos
                // frentes tenga. A diferencia de frenteImplicitoDelAlmacen (que es
                // estricto y se usa en movimientos manuales donde la ambiguedad debe
                // forzar al usuario a elegir), aca el sistema necesita SI O SI un
                // destino para que la columna "Destino" del kardex no se vea "—" en
                // cada producto nuevo — el primer frente del almacen es siempre el
                // mas representativo.
                $almForFrente   = Almacen::with('frentes:ID_FRENTE')->find($idAlmacen);
                $idFrenteInicial = optional($almForFrente?->frentes->first())->ID_FRENTE;

                $this->inventario->registrarEntrada(
                    $idAlmacen,
                    $producto->ID_PRODUCTO,
                    $cantInicial,
                    [
                        'id_frente'  => $idFrenteInicial,
                        'referencia' => 'STOCK INICIAL registro de nuevo material',
                        'motivo'     => 'Stock inicial al crear el producto',
                    ]
                );
            }

            return $producto;
        });

        return response()->json(['message' => 'Producto creado.', 'producto' => $producto], 201);
    }

    public function updateProducto(Request $request, int $id)
    {
        $producto = ProductoInventario::findOrFail($id);
        // Si el CODIGO no cambió respecto al actual, no re-validar su formato: permite editar
        // productos con códigos legacy no numéricos (ej. filtros "FIL-003"). Se quita del request
        // para que validarProducto lo trate como nullable y abajo se conserve el código actual.
        if (trim((string) $request->input('CODIGO')) === (string) $producto->CODIGO) {
            $request->request->remove('CODIGO');
        }
        $data = $this->validarProducto($request, $producto->ID_PRODUCTO);
        if (empty($data['CODIGO'])) {
            unset($data['CODIGO']); // si viene vacío al editar, se conserva el código actual
        }
        $producto->update($data);

        // Equivalencias (nº de parte) — feature EXCLUSIVA de filtros. Criterio ÚNICO de
        // "es filtro": la categoría CONTIENE 'FILTRO' (mismo criterio que el buscador
        // esFiltroCat), no la coincidencia exacta 'FILTROS' — así "FILTROS DE ACEITE" o
        // "FILTRO DE AIRE" también admiten equivalencias, sin la incoherencia previa.
        if (str_contains(mb_strtoupper((string) $producto->CATEGORIA), 'FILTRO')) {
            // Se gestionan como lista dentro del modal "Editar producto": el front manda el
            // conjunto COMPLETO y aquí se sincroniza (agrega nuevas, borra las quitadas). El
            // principal existente se preserva. Solo si el front las envió.
            if ($request->has('equivalencias')) {
                $request->validate([
                    'equivalencias'   => 'array|max:100',
                    'equivalencias.*' => 'nullable|string|max:100',
                ]);
                $this->sincronizarEquivalencias($producto, (array) $request->input('equivalencias', []));
            }
        } else {
            // El producto ya NO es filtro (típicamente se le cambió la categoría). Sus
            // equivalencias quedarían HUÉRFANAS y seguirían matcheando en la búsqueda por
            // nº de parte (que no filtra por categoría) → las borramos.
            $producto->equivalencias()->delete();
        }

        return response()->json(['message' => 'Producto actualizado.', 'producto' => $producto->fresh()]);
    }

    /**
     * Sincroniza las equivalencias de un FILTRO con la lista recibida: agrega las nuevas y
     * borra las que ya no están. Preserva ES_PRINCIPAL de las que se mantienen; las nuevas
     * entran como alternas. Normaliza (trim, sin vacíos, sin duplicar).
     */
    private function sincronizarEquivalencias(ProductoInventario $producto, array $lista): void
    {
        $partes = collect($lista)
            ->map(fn ($s) => trim((string) $s))
            ->filter(fn ($s) => $s !== '' && mb_strlen($s) <= 100)
            ->unique()
            ->values();

        $actuales = $producto->equivalencias()->get()->keyBy('NUMERO_PARTE');

        // Borra las que el usuario quitó de la lista.
        foreach ($actuales as $np => $eq) {
            if (! $partes->contains($np)) {
                $eq->delete();
            }
        }
        // Agrega las nuevas (las existentes se dejan igual, conservando su principal).
        foreach ($partes as $np) {
            if (! $actuales->has($np)) {
                ProductoEquivalencia::create([
                    'ID_PRODUCTO'  => $producto->ID_PRODUCTO,
                    'NUMERO_PARTE' => $np,
                    'ES_PRINCIPAL' => false,
                ]);
            }
        }
    }

    /**
     * Genera el siguiente código automático para un producto: cadena de SOLO
     * dígitos con padding a 6 cifras (formato del catálogo: 000001..000992).
     * Toma el mayor número usado en códigos puramente numéricos + 1. Incluye
     * soft-deleted en la verificación porque el índice UNIQUE de CODIGO también
     * los ocupa. El código autogenerado cumple la MISMA validación que un código
     * tecleado a mano (regex ^\d+$ en validarProducto) — antes generaba "PRD-####"
     * (con letras), lo que rompía esa coherencia y la convención del catálogo.
     */
    private function generarCodigoProducto(): string
    {
        $maxNum = 0;
        ProductoInventario::withTrashed()
            ->whereNotNull('CODIGO')
            ->pluck('CODIGO')
            ->each(function ($cod) use (&$maxNum) {
                $cod = (string) $cod;
                if ($cod !== '' && ctype_digit($cod)) {
                    $maxNum = max($maxNum, (int) $cod);
                }
            });

        $n = $maxNum + 1;
        do {
            $codigo = str_pad((string) $n, 6, '0', STR_PAD_LEFT);
            $n++;
        } while (ProductoInventario::withTrashed()->where('CODIGO', $codigo)->exists());

        return $codigo;
    }

    public function destroyProducto(int $id)
    {
        // Soft-delete siempre (Laravel SoftDeletes pone deleted_at). El producto
        // desaparece de las vistas pero las FKs de stock/movimientos siguen
        // validas. La rama vieja que marcaba ESTATUS=INACTIVO se elimino — no
        // habia UI para reactivar y duplicaba el efecto del soft-delete.
        $producto = ProductoInventario::findOrFail($id);
        $producto->delete();
        return response()->json(['message' => 'Producto eliminado.']);
    }

    /**
     * Papelera de productos: lista los productos SOFT-deleted (deleted_at != null) para
     * poder buscarlos y restaurarlos. Búsqueda opcional por CODIGO o NOMBRE. El producto
     * no se borró de verdad — solo está oculto, y su stock en almacen_stock sigue intacto.
     */
    public function papeleraProductos(Request $request)
    {
        $term = trim((string) $request->input('search', ''));

        $productos = ProductoInventario::onlyTrashed()
            ->when($term !== '', function ($q) use ($term) {
                $q->where(function ($w) use ($term) {
                    $w->where('CODIGO', 'like', "%{$term}%")
                      ->orWhere('NOMBRE', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('deleted_at')
            ->limit(200)
            ->get(['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM', 'CATEGORIA', 'deleted_at']);

        return response()->json(['productos' => $productos]);
    }

    /**
     * Restaura un producto de la papelera (deleted_at → null). Reaparece en el catálogo
     * con su stock intacto (almacen_stock nunca se borró al eliminarlo).
     */
    public function restaurarProducto(int $id)
    {
        $producto = ProductoInventario::onlyTrashed()->findOrFail($id);
        $producto->restore();
        return response()->json(['message' => 'Producto restaurado.', 'producto' => $producto->fresh()]);
    }

    /**
     * Borra PERMANENTEMENTE un producto de la papelera (forceDelete) — irreversible,
     * EXCLUSIVO super.admin (gate en el constructor).
     *
     * Resguardo: si el producto tiene movimientos en el kardex, NO se borra; se mantiene
     * en la papelera para auditoría. OJO: la FK movimientos_inventario.ID_PRODUCTO es
     * ON DELETE CASCADE, así que la BD SÍ lo dejaría pasar — borraría el kardex en
     * silencio, y con él las líneas de las Notas de Entrega ya emitidas. El guard de
     * abajo es la ÚNICA barrera; no hay una restricción del motor que respalde esto.
     */
    public function eliminarPermanenteProducto(int $id)
    {
        $producto = ProductoInventario::onlyTrashed()->findOrFail($id);

        if ($producto->movimientos()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar permanentemente: el producto tiene movimientos registrados en el kardex. Se mantiene en la papelera.',
            ], 422);
        }

        try {
            $producto->stock()->delete();   // limpia stock huérfano (sin movimientos, debe estar en 0)
            $producto->forceDelete();
            // forceDelete cascadea almacen_stock: filas que desaparecen sin rastro para
            // el delta. Se pide la copia completa de almacen.
            \App\Support\OfflineVersion::resetear('almacen');
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'No se puede eliminar permanentemente: el producto tiene registros asociados.',
            ], 422);
        }

        return response()->json(['message' => 'Producto eliminado permanentemente.']);
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
        // "Restringido" = criterio ÚNICO Almacen::usuarioEsGlobal (== Usuario::veTodosLosFrentes).
        if (!$request->wantsJson() && $almacenes->isEmpty() && !Almacen::usuarioEsGlobal($request->user())) {
            return redirect()->route('menu')->with('flash_toast', [
                'type'    => 'error',
                'message' => 'Tu frente no tiene un almacén registrado. Avisa al administrador para que asocie un almacén a tu frente.',
            ]);
        }

        // Default suave del filtro de almacén — TODOS los usuarios abren con UN almacén
        // preseleccionado, nunca con "Todos". El usuario que quiere ver todos elige el
        // valor explicito (X o "Todos" en el dropdown).
        //   1) Si el cliente mando id_almacen (filled), respetamos.
        //   2) Sino, intentamos el almacen ligado al frente (almacenPorDefecto).
        //   3) Fallback: el PRIMER almacen visible — cubre usuarios GLOBAL sin frente
        //      (super.admin) que sino abrian con "Todos" (no es lo que quiere el cliente).
        // Validamos que el default sea VISIBLE para evitar un filtro fantasma que oculte
        // los movimientos del usuario.
        if (!$request->filled('id_almacen')) {
            $idDef = $request->user()?->almacenPorDefecto();
            if (!$idDef && $visiblesIds->isNotEmpty()) {
                $idDef = (int) $visiblesIds->first();
            }
            if ($idDef && $visiblesIds->contains((int) $idDef)) {
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
        // id_producto = match EXACTO (viene de elegir una sugerencia del autocomplete, o de
        // entrar desde el detalle de un producto). Tiene PRECEDENCIA sobre `search`: si se
        // eligió un producto puntual, la bitácora muestra SOLO ese, no todos los que comparten
        // descripción (mismo criterio que la tabla de /admin/almacen). `search` (LIKE + tokens)
        // queda para el flujo "teclear + Enter" (similitudes), cuando NO se eligió uno exacto.
        if ($request->filled('id_producto')) {
            $q->where('ID_PRODUCTO', $request->integer('id_producto'));
        } elseif ($request->filled('search')) {
            // Misma tokenización que la tabla de /admin/almacen (ver aplicarBusquedaProducto).
            $term = trim((string) $request->input('search'));
            $q->whereHas('producto', function ($p) use ($term) {
                // incluirEquivalencias=true: el autocomplete de movimientos sugiere por nº de
                // parte equivalente (haystack con EQUIV), así que la búsqueda por Enter debe
                // encontrar por ese alterno también. Sin esto, teclear un nº de parte y pulsar
                // Enter devolvía bitácora vacía aunque el dropdown mostraba el producto.
                $this->aplicarBusquedaProducto($p, $term, ['CODIGO', 'NOMBRE'], true);
            });
        }
        if ($request->filled('tipo') && $request->input('tipo') !== 'all') {
            // Filtro Tipo SIMPLIFICADO a 2 grupos (Entradas / Salidas). El frontend manda
            // las claves de grupo ENTRADAS/SALIDAS; aquí se pliegan los traspasos y las
            // auditorías (AJUSTE) según su signo:
            //   Entradas = ENTRADA + TRASPASO_ENTRADA + ajuste que SUBIÓ el stock.
            //   Salidas  = SALIDA  + TRASPASO_SALIDA  + ajuste que BAJÓ  el stock.
            // Se mantiene compat con un TIPO exacto por si llega de un link viejo.
            $tipoReq = (string) $request->input('tipo');
            if ($tipoReq === 'ENTRADAS') {
                $q->where(function ($w) {
                    $w->whereIn('TIPO', MovimientoInventario::TIPOS_ENTRADA)
                      // Ajuste que SUBIÓ el saldo (aumento neto). Estricto (>), simétrico con
                      // SALIDAS (<): un ajuste neutro (resultante == anterior) no movió nada,
                      // así que no es ni entrada ni salida y no aparece en ninguno de los dos.
                      ->orWhere(fn ($a) => $a->where('TIPO', MovimientoInventario::TIPO_AJUSTE)
                          ->whereColumn('CANTIDAD_RESULTANTE', '>', 'CANTIDAD_ANTERIOR'));
                });
            } elseif ($tipoReq === 'SALIDAS') {
                $q->where(function ($w) {
                    $w->whereIn('TIPO', MovimientoInventario::TIPOS_SALIDA)
                      ->orWhere(fn ($a) => $a->where('TIPO', MovimientoInventario::TIPO_AJUSTE)
                          ->whereColumn('CANTIDAD_RESULTANTE', '<', 'CANTIDAD_ANTERIOR'));
                });
            } else {
                $q->where('TIPO', $tipoReq);
            }
        }
        // Filtro "Nota de entrega": matchea el N° de Nota de Entrega (NUMERO_NOTA, salidas)
        // O la referencia/nota del proveedor (REFERENCIA, entradas) — LIKE en ambos.
        if ($request->filled('nota')) {
            $nota = trim((string) $request->input('nota'));
            $q->where(function ($w) use ($nota) {
                $w->where('NUMERO_NOTA', 'like', "%{$nota}%")
                  ->orWhere('REFERENCIA', 'like', "%{$nota}%");
            });
        }
        if ($request->filled('id_frente') && $request->input('id_frente') !== 'all') {
            $q->where('ID_FRENTE', $request->integer('id_frente'));
        }
        $q->periodo($request->input('desde'), $request->input('hasta'));

        // Petición SOLO-consumo (consumo_only): el frontend carga la TABLA aparte con
        // skip_consumo para que aparezca rápido, y pide el ranking de consumo EN PARALELO.
        // Aquí saltamos la query de la tabla y la paginación (consumoRanking arma su propia
        // query desde $request, no usa $paginator) — así este request solo paga el agregado.
        if ($request->wantsJson() && $request->boolean('consumo_only')) {
            return response()->json([
                'consumo' => view('admin.almacen.partials.consumo_stats', ['consumo' => $this->consumoRanking($request)])->render(),
            ]);
        }

        // Orden por ORDEN DE REGISTRO (ID_MOVIMIENTO autoincremental), NO por FECHA: la
        // FECHA la teclea el usuario y puede ser pasada o incluso futura, así que ordenar
        // por ella hundía la última operación registrada bajo movimientos con fecha mayor.
        // El cliente quiere ver SIEMPRE primero lo más recientemente registrado.
        $paginator = $q->orderByDesc('ID_MOVIMIENTO')
            ->paginate($request->integer('per_page') ?: 50)->withQueryString();

        if ($request->wantsJson()) {
            // El modal "Movimientos del producto" pide ?mini=1 para usar el partial
            // de 4 columnas: sin la de Producto (redundante allí, ya se ve arriba) y sin
            // la de Fecha (el cliente la pidió fuera; el rango se sigue filtrando).
            $partial = $request->boolean('mini')
                ? 'admin.almacen.partials.kardex_rows_mini'
                : 'admin.almacen.partials.kardex_rows';

            $resp = [
                'html'       => view($partial, ['movimientos' => $paginator])->render(),
                'pagination' => (string) $paginator->links('vendor.pagination.custom-sliding'),
                'total'      => $paginator->total(),
            ];

            // El ranking de consumo (agregado group-by) SOLO depende de los filtros, no
            // de la página. Al paginar la tabla el frontend manda skip_consumo=1, y el
            // modal "Movimientos del producto" (mini) no muestra ese sidebar — en ambos
            // casos evitamos recalcular el agregado en vano. El front conserva el ranking
            // actual porque omitir la clave deja data.consumo === undefined (no repinta).
            if (!$request->boolean('skip_consumo') && !$request->boolean('mini')) {
                $resp['consumo'] = view('admin.almacen.partials.consumo_stats', ['consumo' => $this->consumoRanking($request)])->render();
            }

            return response()->json($resp);
        }

        // `idAlmacenActivo`: el valor REAL del filtro tras el default-merge del controller —
        // se lo pasamos a la vista para que el dropdown del header NO dependa del helper
        // request() (que en algunos entornos no refleja el merge al renderizar el blade).
        $idAlmacenActivo = ($request->filled('id_almacen') && $request->input('id_almacen') !== 'all')
            ? (int) $request->input('id_almacen')
            : null;
        // Lista de N° de Nota de Entrega (NE-…) de SALIDA en almacenes visibles. Alimenta
        // DOS autocompletes:
        //   · el filtro "Nota de entrega" de la bitácora (para TODOS los usuarios), y
        //   · el modal "Eliminar Nota" (solo quien tiene almacen.nota.eliminar).
        // SOLO TIPO=SALIDA (las entradas no llevan NE; el filtro igual matchea REFERENCIA) y
        // SOLO de almacenes visibles para el usuario (asi no se sugiere una Nota que luego
        // assertPuedeVerAlmacen rechazaria). Las 500 mas recientes bastan.
        $notasFiltro = MovimientoInventario::query()
            ->where('TIPO', MovimientoInventario::TIPO_SALIDA)
            ->whereIn('ID_ALMACEN', $almacenes->pluck('ID_ALMACEN'))
            ->whereNotNull('NUMERO_NOTA')
            ->where('NUMERO_NOTA', '!=', '')
            ->distinct()
            ->orderByDesc('NUMERO_NOTA')
            ->limit(500)
            ->pluck('NUMERO_NOTA');
        // El modal "Eliminar Nota" usa la MISMA lista, pero solo si el usuario tiene la clave.
        $numerosNotas = $request->user()?->can('almacen.nota.eliminar') ? $notasFiltro : collect();

        $frentesMovimientos = Almacen::usuarioEsGlobal($request->user())
            ? \App\Models\FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
                ->orderBy('NOMBRE_FRENTE')->get(['ID_FRENTE', 'NOMBRE_FRENTE'])
            : \App\Models\FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
                ->whereHas('almacenes', fn ($q) => $q->whereIn('almacenes.ID_ALMACEN', $visiblesIds))
                ->orderBy('NOMBRE_FRENTE')->get(['ID_FRENTE', 'NOMBRE_FRENTE']);

        return view('admin.almacen.movimientos', [
            'movimientos'     => $paginator,
            'total'           => $paginator->total(),
            'almacenes'       => $almacenes,
            'idAlmacenActivo' => $idAlmacenActivo,
            'frentesLista'    => $frentesMovimientos,
            // El catálogo del buscador (almMovProductosLista) ya NO se embebe aquí: la vista lo
            // pide por AJAX al endpoint compartido almacen.productos-autocomplete tras renderizar,
            // para que la bitácora abra rápido (antes embebía los 1155 productos inline).
            // Ranking de productos más consumidos (SALIDA + TRASPASO_SALIDA) aplicando los
            // mismos filtros visibles. Alimenta el sidebar "Consumo de Inventario".
            'consumo'         => $this->consumoRanking($request),
            // Autocomplete del modal "Eliminar Nota" (ver $numerosNotas arriba).
            'numerosNotas'    => $numerosNotas,
            // Autocomplete del filtro "Nota de entrega" de la bitácora (todos los usuarios).
            'notasFiltro'     => $notasFiltro,
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

        // Default-merge por almacén — TODOS los usuarios abren con UN almacén
        // (mismo patron que movimientos()). 1) helper (frente), 2) fallback al primer visible.
        if (!$request->filled('id_almacen')) {
            $idDef = $request->user()?->almacenPorDefecto();
            if (!$idDef && $visiblesIds->isNotEmpty()) {
                $idDef = (int) $visiblesIds->first();
            }
            if ($idDef && $visiblesIds->contains((int) $idDef)) {
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

        // La nota más recientemente REGISTRADA arriba: ordenamos por ULT_ID (mayor
        // ID_MOVIMIENTO del grupo = orden de registro), no por FECHA — la FECHA la teclea
        // el usuario y puede ser pasada/futura, lo que hundía la última nota registrada.
        $q->orderByDesc('ULT_ID');

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

        $frentesNotas = Almacen::usuarioEsGlobal($request->user())
            ? \App\Models\FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
                ->orderBy('NOMBRE_FRENTE')->get(['ID_FRENTE', 'NOMBRE_FRENTE'])
            : \App\Models\FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
                ->whereHas('almacenes', fn ($q) => $q->whereIn('almacenes.ID_ALMACEN', $visiblesIds))
                ->orderBy('NOMBRE_FRENTE')->get(['ID_FRENTE', 'NOMBRE_FRENTE']);

        if ($request->wantsJson()) {
            return response()->json([
                'html'       => view('admin.almacen.partials.notas_rows', ['notas' => $paginator, 'almById' => $almById, 'freById' => $freById])->render(),
                'pagination' => $paginator->links('vendor.pagination.custom-sliding')->toHtml(),
                'total'      => $paginator->total(),
            ]);
        }

        return view('admin.almacen.notas', [
            'notas'           => $paginator,
            'total'           => $paginator->total(),
            'almacenes'       => $almacenes,
            'idAlmacenActivo' => $idAlmacenActivo,
            'frentesLista'    => $frentesNotas,
            'categorias'      => $categorias,
            'almById'         => $almById,
            'freById'         => $freById,
            // $aplicarBusqueda=false: aquí `search` es N° Nota/RQ/Contrato/Solicitante, no un
            // producto. El sidebar "Consumo" sigue respondiendo a almacén/frente/categoría/período.
            'consumo'         => $this->consumoRanking($request, 30, false),
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
     *
     * @param bool $aplicarBusqueda  Si el `search` del request debe filtrar el ranking por
     *   producto. TRUE en la bitácora (/almacen/movimientos), donde `search` ES búsqueda de
     *   producto. FALSE en /almacen/notas, donde `search` significa N° Nota/RQ/Contrato/
     *   Solicitante: aplicarlo como código/nombre de producto vaciaría el ranking (ningún
     *   producto se llama "NE-2026-0001"). El resto de filtros (almacén/frente/categoría/
     *   período) SÍ son compatibles y se siguen aplicando en ambas páginas.
     */
    protected function consumoRanking(Request $request, int $limite = 30, bool $aplicarBusqueda = true)
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
        if ($aplicarBusqueda && $request->filled('search')) {
            // Antes era un LIKE plano que divergía del resto del módulo; ahora usa la
            // misma búsqueda tokenizada CON equivalencias que la bitácora de la misma
            // pantalla — así el ranking "Consumo de Inventario" y la bitácora coinciden
            // (buscar por una parte/equivalente devuelve las mismas filas en ambos).
            // En /almacen/notas se pasa $aplicarBusqueda=false porque ahí `search` es N° Nota.
            $term = trim((string) $request->input('search'));
            $q->whereHas('producto', function ($p) use ($term) {
                $this->aplicarBusquedaProducto($p, $term, ['CODIGO', 'NOMBRE'], true);
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
     * Dashboard de Consumo (JSON para Chart.js). Devuelve las series de los gráficos del
     * modal: por_mes, top_productos, por_almacen (+ la lista de categorías del filtro).
     * "Consumo" = movimientos TIPO 'SALIDA' de TODOS los almacenes visibles (los
     * TRASPASO_SALIDA son movimientos internos entre almacenes, NO consumo).
     *
     * IMPORTANTE: es INDEPENDIENTE de los filtros generales del módulo (almacén
     * seleccionado, frente, producto, búsqueda). Usa SOLO sus propios filtros: rango de
     * meses (desde/hasta en YYYY-MM) y categoría — es una vista global de consumo, no un
     * reflejo de la tabla filtrada. Los nombres se pasan por MojibakeFix porque las queries
     * crudas (con JOIN) NO aplican el cast del modelo.
     */
    /**
     * Compatibilidad de un filtro para el modal "Detalles del producto":
     * sus números de parte (equivalencias, el principal primero) y los EQUIPOS
     * (tipo + modelo + ETAPA + cantidad) que lo usan, tomados de modelo_filtro.
     * Ligero: se llama al abrir el detalle.
     *
     * La ETAPA viaja por equipo, no por producto: el mismo filtro es PRIMARIO en
     * una máquina y SECUNDARIO en otra. Vacía = sin confirmar.
     */
    public function productoCompatibilidad($id)
    {
        $equivalencias = ProductoEquivalencia::where('ID_PRODUCTO', $id)
            ->orderByDesc('ES_PRINCIPAL')
            ->pluck('NUMERO_PARTE')
            ->values()->all();

        // Vehículos (caracteristicas_modelo) que usan el filtro. La MARCA no vive en
        // caracteristicas_modelo, así que se toma de los `equipos` de ese modelo (la más común).
        $especIds = DB::table('modelo_filtro')->where('ID_PRODUCTO', $id)->pluck('ID_ESPEC');
        $marcaPorEspec = DB::table('equipos')
            ->whereIn('ID_ESPEC', $especIds)->whereNotNull('ID_ESPEC')->whereNull('deleted_at')
            ->select('ID_ESPEC', 'MARCA', DB::raw('COUNT(*) as n'))
            ->groupBy('ID_ESPEC', 'MARCA')->orderByDesc('n')
            ->get()->groupBy('ID_ESPEC')->map(fn ($g) => $g->first()->MARCA);

        $equipos = DB::table('modelo_filtro as mf')
            ->join('caracteristicas_modelo as cm', 'cm.ID_ESPEC', '=', 'mf.ID_ESPEC')
            ->where('mf.ID_PRODUCTO', $id)
            ->orderBy('cm.TIPO')->orderBy('cm.MODELO')
            ->get(['mf.ID_ESPEC as espec', 'cm.TIPO', 'cm.MODELO', 'mf.ETAPA', 'mf.CANTIDAD'])
            ->map(function ($x) use ($marcaPorEspec) {
                $marca = $marcaPorEspec->get($x->espec);
                return [
                    'tipo'   => (string) $x->TIPO,
                    'modelo' => trim(($marca ? $marca.' ' : '').((string) $x->MODELO)),
                    'etapa'  => $x->ETAPA ? ucfirst(mb_strtolower((string) $x->ETAPA)) : null,
                    'cant'   => (int) $x->CANTIDAD,
                ];
            });

        // Auxiliares (generador, soldadora, compresor…) que usan el filtro.
        $aux = DB::table('auxiliar_filtro')
            ->where('ID_PRODUCTO', $id)
            ->orderBy('TIPO')->orderBy('MARCA')->orderBy('MODELO')
            ->get(['TIPO', 'MARCA', 'MODELO', 'ETAPA', 'CANTIDAD'])
            ->map(fn ($x) => [
                'tipo'   => str_replace('_', ' ', (string) $x->TIPO),
                'modelo' => trim(((string) $x->MARCA).' '.((string) $x->MODELO)),
                'etapa'  => $x->ETAPA ? ucfirst(mb_strtolower((string) $x->ETAPA)) : null,
                'cant'   => (int) $x->CANTIDAD,
            ]);

        $equipos = $equipos->concat($aux)
            ->unique(fn ($x) => $x['tipo'].'|'.$x['modelo'])
            ->values();

        return response()->json([
            'equivalencias' => $equivalencias,
            'equipos'       => $equipos,
        ]);
    }

    public function consumoDashboard(Request $request)
    {
        // El dashboard es INDEPENDIENTE de los filtros generales del módulo
        // (búsqueda, frente, almacén seleccionado, producto). Usa SOLO sus propios
        // filtros: rango de meses (desde/hasta en formato YYYY-MM) y categoría.
        // Mide consumo REAL = movimientos TIPO 'SALIDA' de TODOS los almacenes visibles
        // (los TRASPASO_SALIDA son movimientos internos entre almacenes, no consumo).

        // Rango de meses → límites de fecha. Idiom centralizado (FUENTE ÚNICA) en
        // MovimientoInventario::expandirRangoMes, el mismo que usa scopePeriodo.
        [$desde, $hasta] = MovimientoInventario::expandirRangoMes(
            $request->input('desde'),
            $request->input('hasta')
        );

        $idsVisibles = Almacen::visiblesPara($request->user())->pluck('ID_ALMACEN');

        // Fábrica de query base: cada agregación necesita su propio builder (sum/count
        // ejecutan la consulta), por eso devolvemos uno nuevo en cada llamada.
        // IMPORTANTE: las columnas de movimientos van PREFIJADAS con
        // `movimientos_inventario.` — las queries top_productos/por_almacen hacen JOIN
        // con productos_inventario y almacenes, que tienen columnas homónimas (almacenes
        // también tiene TIPO e ID_ALMACEN) → sin prefijo, MySQL lanza error 1052 (ambiguo).
        $base = function () use ($request, $idsVisibles, $desde, $hasta) {
            $q = MovimientoInventario::query()
                ->where('movimientos_inventario.TIPO', 'SALIDA')
                ->whereIn('movimientos_inventario.ID_ALMACEN', $idsVisibles);
            // Descripción: coincidencia parcial sobre el NOMBRE (la descripción) del producto.
            // whereExists evita ambigüedad de columnas con los JOIN de top_productos/por_almacen
            // (mismo patrón que el filtro de categoría de abajo).
            if ($request->filled('descripcion')) {
                $desc = trim((string) $request->input('descripcion'));
                $q->whereExists(function ($sub) use ($desc) {
                    $sub->select(DB::raw(1))
                        ->from('productos_inventario as pd')
                        ->whereColumn('pd.ID_PRODUCTO', 'movimientos_inventario.ID_PRODUCTO')
                        ->where('pd.NOMBRE', 'like', "%{$desc}%");
                });
            }
            if ($request->filled('categoria') && $request->input('categoria') !== 'all') {
                $cat = trim((string) $request->input('categoria'));
                $q->whereExists(function ($sub) use ($cat) {
                    $sub->select(DB::raw(1))
                        ->from('productos_inventario as pc')
                        ->whereColumn('pc.ID_PRODUCTO', 'movimientos_inventario.ID_PRODUCTO')
                        ->where('pc.CATEGORIA', 'like', "%{$cat}%");
                });
            }
            // Filtro por FRENTE de destino del consumo (salida hacia ese proyecto).
            if ($request->filled('frente') && $request->input('frente') !== 'all') {
                $q->where('movimientos_inventario.ID_FRENTE', (int) $request->input('frente'));
            }
            $q->periodo($desde, $hasta);
            return $q;
        };

        // ── Consumo por mes ── (todos los meses del rango; sin filtro, últimos 12)
        $porMes = $base()
            ->selectRaw("DATE_FORMAT(FECHA, '%Y-%m') as mes, SUM(CANTIDAD) as total")
            ->groupBy('mes')->orderBy('mes')
            ->get()
            ->map(fn ($r) => ['mes' => $r->mes, 'total' => (float) $r->total]);
        if (!$desde && !$hasta) {
            $porMes = $porMes->slice(-12);
        }
        $porMes = $porMes->values();

        // ── Top 20 productos más consumidos ────────────────────────────────────
        // Se enriquece con el nº de parte PRINCIPAL (+ alternos) y los EQUIPOS que
        // usan el filtro, para mostrarlos en el tooltip del gráfico (al pasar el mouse).
        $topRows = $base()
            ->join('productos_inventario as p', 'p.ID_PRODUCTO', '=', 'movimientos_inventario.ID_PRODUCTO')
            ->groupBy('p.ID_PRODUCTO', 'p.NOMBRE', 'p.UM')
            ->orderByDesc(DB::raw('SUM(movimientos_inventario.CANTIDAD)'))
            ->limit(20)
            ->get(['p.ID_PRODUCTO as id', 'p.NOMBRE as nombre', 'p.UM as um', DB::raw('SUM(movimientos_inventario.CANTIDAD) as total')]);

        $idsTop = $topRows->pluck('id')->all();

        // Nº de parte por producto (principal primero).
        $partesPorProd = ProductoEquivalencia::whereIn('ID_PRODUCTO', $idsTop)
            ->orderByDesc('ES_PRINCIPAL')
            ->get(['ID_PRODUCTO', 'NUMERO_PARTE'])
            ->groupBy('ID_PRODUCTO');

        // Equipos VEHÍCULO (modelo_filtro) que usan cada filtro.
        $equiposPorProd = DB::table('modelo_filtro as mf')
            ->join('caracteristicas_modelo as cm', 'cm.ID_ESPEC', '=', 'mf.ID_ESPEC')
            ->whereIn('mf.ID_PRODUCTO', $idsTop)
            ->get(['mf.ID_PRODUCTO as id', 'mf.ID_ESPEC as espec', 'cm.TIPO', 'cm.MODELO'])
            ->groupBy('id');

        // Marca real por modelo (de los equipos de ese modelo).
        $especsTop = DB::table('modelo_filtro')->whereIn('ID_PRODUCTO', $idsTop)->pluck('ID_ESPEC');
        $marcaPorEspec = DB::table('equipos')
            ->whereIn('ID_ESPEC', $especsTop)->whereNotNull('ID_ESPEC')->whereNull('deleted_at')
            ->select('ID_ESPEC', 'MARCA', DB::raw('COUNT(*) as n'))
            ->groupBy('ID_ESPEC', 'MARCA')->orderByDesc('n')
            ->get()->groupBy('ID_ESPEC')->map(fn ($g) => $g->first()->MARCA);

        // Equipos AUXILIARES (generador/soldadora/compresor) que usan cada filtro.
        $auxPorProd = DB::table('auxiliar_filtro')
            ->whereIn('ID_PRODUCTO', $idsTop)
            ->get(['ID_PRODUCTO as id', 'TIPO', 'MARCA', 'MODELO'])
            ->groupBy('id');

        $topProductos = $topRows->map(function ($r) use ($partesPorProd, $equiposPorProd, $auxPorProd, $marcaPorEspec) {
            $pp = $partesPorProd->get($r->id);
            $partes = $pp ? $pp->pluck('NUMERO_PARTE')->take(8)->values()->all() : [];
            $eq = $equiposPorProd->get($r->id);
            $vehic = $eq ? $eq->map(function ($x) use ($marcaPorEspec) {
                $m = $marcaPorEspec->get($x->espec);
                return trim(($x->TIPO ? $x->TIPO.' ' : '').($m ? $m.' ' : '').$x->MODELO);
            }) : collect();
            $ax = $auxPorProd->get($r->id);
            $auxs = $ax ? $ax->map(fn ($x) => trim(str_replace('_', ' ', (string) $x->TIPO).' '.trim(((string) $x->MARCA).' '.((string) $x->MODELO)))) : collect();
            $equipos = collect($vehic)->concat($auxs)->filter()->unique()->take(8)->values()->all();
            return [
                'nombre'  => \App\Casts\MojibakeFix::fix($r->nombre),
                'total'   => (float) $r->total,
                'um'      => $r->um ?: 'UND',
                'parte'   => $partes[0] ?? null,
                'partes'  => $partes,
                'equipos' => $equipos,
            ];
        })->values();

        // ── Consumo por almacén ─────────────────────────────────────────────────
        $porAlmacen = $base()
            ->join('almacenes as a', 'a.ID_ALMACEN', '=', 'movimientos_inventario.ID_ALMACEN')
            ->groupBy('a.ID_ALMACEN', 'a.NOMBRE')
            ->orderByDesc(DB::raw('SUM(movimientos_inventario.CANTIDAD)'))
            ->get(['a.NOMBRE as nombre', DB::raw('SUM(movimientos_inventario.CANTIDAD) as total')])
            ->map(fn ($r) => ['nombre' => \App\Casts\MojibakeFix::fix($r->nombre), 'total' => (float) $r->total]);

        // Frentes de destino con consumo (para el filtro del panel avanzado). Independiente
        // de los filtros activos, para que el <select> siempre tenga todas las opciones.
        $frentesLista = DB::table('movimientos_inventario as m')
            ->join('frentes_trabajo as f', 'f.ID_FRENTE', '=', 'm.ID_FRENTE')
            ->where('m.TIPO', 'SALIDA')
            ->whereIn('m.ID_ALMACEN', $idsVisibles)
            ->whereNotNull('m.ID_FRENTE')
            ->distinct()->orderBy('f.NOMBRE_FRENTE')
            ->get(['f.ID_FRENTE as id', 'f.NOMBRE_FRENTE as nombre'])
            ->map(fn ($x) => ['id' => (int) $x->id, 'nombre' => \App\Casts\MojibakeFix::fix($x->nombre)]);

        return response()->json([
            'categorias'    => $this->categoriasDistintas()->values(),
            'frentes'       => $frentesLista,
            // Nombres (descripciones) DISTINTOS para las recomendaciones del filtro
            // "Descripción". Se envían SOLO cuando el modal los pide (con_productos=1, una
            // vez al abrir) para no re-transmitir el catálogo completo en cada cambio de
            // filtro; el front los cachea. MojibakeFix::fix porque pluck() no hidrata el cast.
            'productos'     => $request->boolean('con_productos')
                ? ProductoInventario::activos()->orderBy('NOMBRE')->distinct()->pluck('NOMBRE')
                    ->map(fn ($n) => \App\Casts\MojibakeFix::fix($n))->filter()->unique()->values()
                : null,
            'por_mes'       => $porMes,
            'top_productos' => $topProductos,
            'por_almacen'   => $porAlmacen,
        ]);
    }

    /**
     * Exporta el inventario a XLSX (PhpSpreadsheet).
     *
     * Estructura del archivo (sigue el patrón de /admin/equipos):
     *  Fila 1-3 : logo (A1:B3) · título "COPIA DE INVENTARIO – <ALMACÉN>" (C1:E3) · EDICION/REV/FECHA (F1:..3)
     *  Fila 4   : "Exportado por: Sistema de Gestión …"
     *  Fila 5   : headers   [N°, CÓDIGO, DESCRIPCIÓN, UND, CATEGORÍA, STOCK (por almacén visible), TOTAL]
     *  Fila 6+  : datos
     *
     * Si el usuario filtró un almacén, sólo se exporta la columna de stock de ESE almacén
     * (sin TOTAL — el saldo es el mismo). Si no hay filtro, se exporta una columna STOCK por
     * cada almacén visible al usuario más una columna TOTAL a la derecha.
     * Solo se incluyen productos con saldo total > 0 (los de stock 0 o negativo se omiten).
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
            $tituloFrente = mb_strtoupper($almacenSel->NOMBRE);
        } else {
            $almacenesEnExport = $almacenesVisibles;
            $tituloFrente = 'GLOBAL';
        }

        // Productos a exportar = EXACTAMENTE los que muestra la tabla en pantalla.
        // Reusamos la misma lógica de filtrado que el listado para que coincidan:
        //  - Con almacén seleccionado: inventarioBaseQuery() aplica TODOS los filtros
        //    (búsqueda/producto puntual, categoría, stock bajo/con saldo) sobre ese almacén.
        //  - Global (sin almacén): inventarioBaseQuery devolvería vacío (su JOIN exige un
        //    almacén), así que aplicamos solo los filtros de contenido sobre el catálogo
        //    (los de stock son por-almacén y no aplican a la vista de todos los almacenes).
        if ($idAlmacenSel !== null) {
            $ids = $this->inventarioBaseQuery($idAlmacenSel, $request)
                ->distinct()
                ->pluck('productos_inventario.ID_PRODUCTO');
            $productos = ProductoInventario::whereIn('ID_PRODUCTO', $ids)
                ->orderBy('NOMBRE')
                ->get(['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM', 'CATEGORIA']);
        } else {
            $productosQuery = ProductoInventario::activos()->orderBy('NOMBRE');
            $this->aplicarFiltrosContenido($productosQuery, $request);
            $productos = $productosQuery->get(['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM', 'CATEGORIA']);
        }

        $stocks = AlmacenStock::query()
            ->whereIn('ID_ALMACEN', $almacenesEnExport->pluck('ID_ALMACEN'))
            ->get(['ID_ALMACEN', 'ID_PRODUCTO', 'CANTIDAD', 'CANTIDAD_MINIMA']);
        $stockMap = [];
        $minimoMap = [];
        foreach ($stocks as $s) {
            $stockMap[$s->ID_PRODUCTO][$s->ID_ALMACEN] = (float) $s->CANTIDAD;
            // El mínimo se toma del primer almacén que lo tenga (cada par producto/almacén
            // tiene su mínimo). Ya NO se exporta como columna; solo se usa para resaltar en
            // amarillo las filas cuyo saldo total quede en o por debajo del mínimo.
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
            ->setSubject('Exportación de Inventario - ' . $tituloFrente)
            ->setCompany('Constructora Vidalsa 27, C.A.');
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        // Layout: las primeras 5 columnas fijas (A..E = N°, CÓDIGO, DESCRIPCIÓN, UND, CATEGORÍA),
        // luego N columnas para los stocks por almacén, y si hay >1 almacén una columna TOTAL al final.
        // Usamos Coordinate::stringFromColumnIndex (1-indexed) en vez de range('A','Z') para no
        // quedarnos cortos si algún día hay >20 almacenes visibles (a partir de la 21 vendría AA, AB…).
        $col = fn (int $idx1) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx1);
        $fixedCols = array_map($col, range(1, 5));            // A..E
        $almCount  = $almacenesEnExport->count();
        // Guard: range(6, 5) en PHP devuelve [6,5] (descendente). Si por algún motivo almCount=0,
        // stockCols debe quedar vacío en vez de ['F','E'].
        $stockCols = $almCount > 0 ? array_map($col, range(6, 5 + $almCount)) : [];
        $totalCol  = $almCount > 1 ? $col(6 + $almCount) : null;
        $lastCol   = $totalCol ?: ($stockCols ? end($stockCols) : 'E');

        // ── Encabezado: logo + título + meta ───────────────────────────────────
        foreach ([1, 2, 3] as $r) $sheet->getRowDimension($r)->setRowHeight(40);
        $sheet->mergeCells('A1:B3');
        $sheet->getStyle('A1:B3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        $this->insertarLogoCorporativo($sheet, ['A','B'], [1,2,3]);

        $sheet->mergeCells('C1:E3');
        $titleText = "COPIA DE INVENTARIO\n" . ($idAlmacenSel ? 'FRENTE DE TRABAJO: "' . $tituloFrente . '"' : 'COPIA DE BASE DE DATOS DEL INVENTARIO');
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
        $headers = ['N°', 'CÓDIGO', 'DESCRIPCIÓN DEL PRODUCTO', 'UND', 'CATEGORÍA'];
        // Cada columna de stock por almacén se titula simplemente "STOCK" (a pedido del
        // cliente). En la vista global se repite el encabezado por cada almacén; el TOTAL
        // al final consolida el saldo.
        $headers = array_merge($headers, array_fill(0, $almCount, 'STOCK'));
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

            // Solo se exportan productos con saldo positivo: si el stock total es 0 o
            // negativo, la fila se omite (a pedido del cliente). El mínimo se sigue
            // calculando para resaltar las filas bajo mínimo, aunque su columna ya no
            // se muestre en el archivo.
            if ($total <= 0) {
                continue;
            }

            $minimo = $minimoMap[$p->ID_PRODUCTO] ?? null;

            $sheet->setCellValue('A' . $rowNum, $n);
            $sheet->setCellValue('B' . $rowNum, $p->CODIGO ?? '');
            $sheet->setCellValue('C' . $rowNum, $p->NOMBRE ?? '');
            $sheet->setCellValue('D' . $rowNum, $p->UM ?? '');
            $sheet->setCellValue('E' . $rowNum, $p->CATEGORIA ?? '');

            foreach ($stocksFila as $i => $v) {
                $sheet->setCellValue($stockCols[$i] . $rowNum, $v);
            }
            if ($totalCol) {
                $sheet->setCellValue($totalCol . $rowNum, $total);
            }

            // Resaltar fila bajo mínimo (si hay mínimo y stock total ≤ mínimo) o
            // franja alterna. La alineación y la negrita del TOTAL NO van aquí —
            // se aplican por columna completa después del loop (mucho más rápido).
            if ($minimo !== null && $total <= $minimo) {
                $sheet->getStyle('A' . $rowNum . ':' . $lastCol . $rowNum)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFFEF3C7'); // amarillo suave
            } elseif ($rowNum % 2 === 0) {
                $sheet->getStyle('A' . $rowNum . ':' . $lastCol . $rowNum)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFF8FAFC'); // gris muy claro alternado
            }

            $rowNum++;
            $n++;
        }

        if ($rowNum > 6) {
            $ultimaFila = $rowNum - 1;

            // ── Estilos por COLUMNA — UNA pasada, no celda-por-celda ───────────
            // getStyle() es costoso en PhpSpreadsheet; aplicarlo por rango de
            // columna completo (en vez de ~7 llamadas por fila dentro del loop)
            // acelera mucho la exportación cuando hay cientos de productos.
            foreach (['A', 'B', 'D'] as $c) {
                $sheet->getStyle($c . '6:' . $c . $ultimaFila)->getAlignment()
                    ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            }
            foreach (array_merge($stockCols, $totalCol ? [$totalCol] : []) as $c) {
                $sheet->getStyle($c . '6:' . $c . $ultimaFila)->getAlignment()
                    ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            }
            if ($totalCol) {
                $sheet->getStyle($totalCol . '6:' . $totalCol . $ultimaFila)->getFont()->setBold(true);
            }

            // Bordes a toda la zona de datos.
            $sheet->getStyle('A5:' . $lastCol . $ultimaFila)->getBorders()->getAllBorders()
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
        // No hay fórmulas en la hoja (TOTAL se calcula en PHP) — saltar el pase de
        // pre-cálculo de fórmulas ahorra tiempo de escritura.
        $writer->setPreCalculateFormulas(false);
        $tempFile = tempnam(sys_get_temp_dir(), 'inv_');
        $writer->save($tempFile);

        $fileName = 'Copia_Inventario_' . str_replace([' ', '/'], '_', $tituloFrente) . '_' . date('Y-m-d_H-i') . '.xlsx';

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
     *  - permitir_negativo   : opcional (sólo super.admin) — se aplica SOLO a AJUSTE. En SALIDA
     *                          se ignora: una salida nunca puede dejar el saldo negativo.
     *  - lineas              : [{ id_producto, cantidad }, ...]   (>= 1)
     *                          en AJUSTE, "cantidad" es el SALDO OBJETIVO de ese producto.
     */
    public function registrarMovimientoLote(Request $request)
    {
        // Permiso: todo movimiento de inventario (ENTRADA/SALIDA/AJUSTE y la rama
        // TRASPASO) exige la clave 'almacen.movimiento'. Se valida aqui — y no por
        // middleware `can:` — para notificar al usuario CUAL clave le falta. El flujo
        // "Registrar entrada" de recepcion/nueva consume este endpoint via fetch y
        // muestra res.b.message como toast; misma forma de respuesta que el handler
        // global de AuthorizationException (success/forbidden/message).
        if (! $request->user()?->can('almacen.movimiento')) {
            return response()->json([
                'success'   => false,
                'forbidden' => true,
                'message'   => 'No tienes la clave de permiso «almacen.movimiento», necesaria para registrar movimientos de inventario. Solicítala a un administrador.',
            ], 403);
        }

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
            'lineas.*.id_producto'  => 'required|integer|exists:productos_inventario,ID_PRODUCTO',
            'lineas.*.cantidad'     => 'required|numeric',
            // Nº de parte específico entregado (filtros) — opcional y por línea.
            'lineas.*.numero_parte' => 'nullable|string|max:100',
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
            $idFrente = $idFrenteRequest ?? $this->frenteImplicitoDelAlmacen((int) $data['id_almacen']);
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
            // Una SALIDA NUNCA puede dejar el saldo en negativo — ni siquiera super.admin: no se
            // entrega material que no existe físicamente (regla del cliente, coherente con la
            // vista previa de la Nota). El bypass permitir_negativo se conserva SOLO para AJUSTE
            // (conteo físico / corrección), donde un objetivo negativo puede ser legítimo.
            'permitir_negativo' => $data['tipo'] !== 'SALIDA'
                && $request->boolean('permitir_negativo')
                && $request->user()->can('super.admin'),
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
                    // Nº de parte específico entregado — es POR LÍNEA (cada filtro puede llevar
                    // una equivalencia distinta), por eso se mezcla aquí y no en $opts global.
                    $optsLinea = $opts;
                    if ($data['tipo'] === 'SALIDA' && !empty($linea['numero_parte'])) {
                        $optsLinea['numero_parte'] = trim((string) $linea['numero_parte']);
                    }

                    $mov = match ($data['tipo']) {
                        'ENTRADA' => $this->inventario->registrarEntrada((int) $data['id_almacen'], $idProducto, $cantidad, $optsLinea),
                        'SALIDA'  => $this->inventario->registrarSalida((int) $data['id_almacen'], $idProducto, $cantidad, $optsLinea),
                        'AJUSTE'  => $this->inventario->registrarAjuste((int) $data['id_almacen'], $idProducto, $cantidad, $optsLinea),
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
                    // Un envío a otro almacén también es una SALIDA física: no se puede enviar
                    // material que no existe. No permitimos negativo, ni a super.admin.
                    'permitir_negativo' => false,
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
            // Dos líneas en el toast: showToast (uicomponents.js) inyecta el message
            // como innerHTML, así que el <br> separa el resumen del estado.
            'message'         => "Salida hacia otro proyecto enviada ({$n} producto" . ($n === 1 ? '' : 's') . ')<br>pendiente de recepción.',
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

    // ─────────────────────────────────────────────────────────────
    //  Etiquetas QR + escaneo (estilo etiqueta de producto de supermercado)
    // ─────────────────────────────────────────────────────────────

    /**
     * Genera el PDF de etiquetas QR imprimibles del catálogo.
     *
     * El QR codifica el CODIGO del producto (ProductoInventario::qr_payload) — el
     * mismo valor que resuelve resolverPorCodigo() al escanear, así impresión y
     * escaneo nunca se desincronizan. Read-only: arma un PDF desde el catálogo sin
     * tocar BD, por eso NO lleva gate de permiso (igual criterio que export()).
     *
     * Selección de productos y cantidades:
     *   ?items=ID:CANT,ID:CANT → cantidad POR producto (cada uno con su nº de copias).
     *                            Tiene prioridad sobre ids/categoria/copias.
     *   ?ids=1,2,3 (+?copias=N) → esos productos, N copias iguales de cada uno (def. 1).
     *   ?categoria=X (+?copias) → todos los activos de esa categoría, N copias c/u.
     *   (sin nada)              → todos los productos activos (con tope de seguridad).
     *
     * Formato (?formato=):
     *   carta (default) → A4 vertical, grilla de etiquetas (impresora normal + hoja
     *                     adhesiva tipo Avery).
     *   50x30 | 40x25   → una etiqueta por página al tamaño exacto del rollo, para
     *                     impresora térmica (Zebra/Brother/TSC). Mismo motor (TCPDF)
     *                     y mismo QR: solo cambia el tamaño de página.
     *
     * Solo se incluyen productos CON código: un QR sin CODIGO no sería escaneable.
     */
    public function etiquetasPdf(Request $request)
    {
        $formato = in_array($request->query('formato'), ['carta', '50x30', '40x25'], true)
            ? (string) $request->query('formato')
            : 'carta';

        // Tope total de etiquetas — red de seguridad ante combinaciones grandes (muchos
        // productos × muchas copias). ~84 páginas A4: más que suficiente para imprimir.
        $MAX  = 2000;
        $cols = ['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM', 'CATEGORIA', 'UBICACION'];
        // Base común: solo activos y con código (un QR sin CODIGO no sería escaneable).
        $base = fn () => ProductoInventario::activos()->whereNotNull('CODIGO')->where('CODIGO', '!=', '');

        $itemsRaw = trim((string) $request->query('items', ''));
        if ($itemsRaw !== '') {
            // ── Cantidad POR PRODUCTO: ?items=ID:CANT,ID:CANT ──
            $pares = [];
            foreach (explode(',', $itemsRaw) as $tok) {
                $bits = explode(':', $tok, 2);
                $id   = (int) trim($bits[0] ?? '');
                $qty  = isset($bits[1]) ? (int) trim($bits[1]) : 1;
                if ($id > 0 && $qty > 0) {
                    $pares[$id] = max(1, min(200, $qty));   // cap 200 por producto
                }
            }
            abort_if(empty($pares), 404, 'No se indicaron productos válidos para las etiquetas.');
            $productos = $base()->whereIn('ID_PRODUCTO', array_keys($pares))->orderBy('NOMBRE')->get($cols);
            abort_if($productos->isEmpty(), 404, 'No hay productos con código para generar etiquetas.');
            $copiasDe = fn ($p) => $pares[$p->ID_PRODUCTO] ?? 1;
        } else {
            // ── Cantidad UNIFORME (?copias) sobre ids o categoría ──
            $copias = max(1, min(200, (int) $request->query('copias', 1)));
            $q = $base()->orderBy('NOMBRE');
            $idsRaw = trim((string) $request->query('ids', ''));
            if ($idsRaw !== '') {
                $ids = collect(explode(',', $idsRaw))
                    ->map(fn ($v) => (int) trim($v))
                    ->filter()->unique()->values()->all();
                abort_if(empty($ids), 404, 'No se indicaron productos válidos para las etiquetas.');
                $q->whereIn('ID_PRODUCTO', $ids);
            } elseif ($request->filled('categoria') && $request->query('categoria') !== 'all') {
                // El catálogo guarda la categoría en MAYÚSCULAS (ver validarProducto).
                $q->where('CATEGORIA', mb_strtoupper(trim((string) $request->query('categoria'))));
            }
            // Tope de seguridad para el caso "todos/categoría" sobre catálogos grandes.
            $productos = $q->limit(1000)->get($cols);
            abort_if($productos->isEmpty(), 404, 'No hay productos con código para generar etiquetas.');
            $copiasDe = fn ($p) => $copias;
        }

        // Secuencia PLANA de etiquetas: cada producto repetido su nº de copias, en orden,
        // con tope total. El render solo la maqueta (grilla o una por página).
        $secuencia = [];
        foreach ($productos as $p) {
            for ($c = 0, $n = $copiasDe($p); $c < $n; $c++) {
                $secuencia[] = $p;
                if (count($secuencia) >= $MAX) {
                    break 2;
                }
            }
        }

        $binary = $this->renderEtiquetasPdfBinary($secuencia, $formato);
        return response($binary, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Etiquetas_QR_' . $formato . '.pdf"',
        ]);
    }

    /**
     * Resolver de escaneo: traduce un CODIGO (leído del QR por cámara o lector USB,
     * o tecleado) al producto del catálogo. Match EXACTO sobre CODIGO y SOLO activos
     * (el índice UNIQUE de CODIGO incluye soft-deleted; por eso se filtra a activos).
     * Read-only. El saldo NO se calcula aquí: el frontend, tras resolver, reusa el
     * filtro normal del inventario (almBuscarPick), que ya muestra el saldo del
     * producto en el almacén seleccionado.
     *
     *   ?codigo=000123 → { found:true, producto:{id,codigo,nombre,um,categoria,ubicacion} }
     *                    o { found:false, message } si no existe.
     */
    public function resolverPorCodigo(Request $request)
    {
        $codigo = trim((string) $request->query('codigo', ''));
        if ($codigo === '') {
            return response()->json(['found' => false, 'message' => 'Código vacío.'], 422);
        }

        $producto = ProductoInventario::activos()
            ->where('CODIGO', $codigo)
            ->first(['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM', 'CATEGORIA', 'UBICACION']);

        if (!$producto) {
            return response()->json([
                'found'   => false,
                'message' => 'No existe un producto activo con el código ' . $codigo . '.',
            ]);
        }

        return response()->json([
            'found'    => true,
            'producto' => [
                'id'        => $producto->ID_PRODUCTO,
                'codigo'    => $producto->CODIGO,
                'nombre'    => $producto->NOMBRE,
                'um'        => $producto->UM,
                'categoria' => $producto->CATEGORIA,
                'ubicacion' => $producto->UBICACION,
            ],
        ]);
    }

    /**
     * Construye el PDF de etiquetas QR. Un único motor (TCPDF, el mismo de la Nota de
     * Entrega) sirve para impresora normal y térmica — solo cambia el tamaño de página:
     *   - 'carta'         → A4 vertical con una grilla de etiquetas (impresora normal).
     *   - '50x30'/'40x25' → página = una etiqueta (impresora térmica de rollo).
     * El QR usa corrección de error alta ('QRCODE,H') para que se lea aunque la
     * etiqueta sea pequeña o se imprima a baja resolución (203 dpi térmico).
     *
     * Recibe $secuencia: la lista PLANA de productos ya expandida por copias (cada
     * producto repetido su nº de etiquetas, en orden) — la arma etiquetasPdf(), que
     * decide si la cantidad es uniforme (?copias) o por producto (?items). Aquí solo
     * se maqueta (grilla en carta, una etiqueta por página en rollo).
     */
    private function renderEtiquetasPdfBinary(array $secuencia, string $formato): string
    {
        // Geometría por formato (mm). En 'carta' la grilla la definen cols + el nº de
        // filas que caben por alto; en los rollos es 1 etiqueta por página.
        // 'carta': 3 columnas. Con márgenes de hoja de 6 mm quedan 198 mm útiles → celdas de
        // 66 mm. Antes eran 2 columnas de 80 mm con 25 mm de margen izquierdo, que
        // desperdiciaba casi un tercio del ancho y gastaba el doble de papel.
        $presets = [
            'carta' => ['orient' => 'P', 'page' => 'A4',      'cols' => 3, 'cellW' => 66.0, 'cellH' => 28.0, 'mLeft' => 6.0, 'mTop' => 6.0],
            '50x30' => ['orient' => 'L', 'page' => [50, 30],  'cols' => 1, 'cellW' => 50.0, 'cellH' => 30.0, 'mLeft' => 0.0, 'mTop' => 0.0],
            '40x25' => ['orient' => 'L', 'page' => [40, 25],  'cols' => 1, 'cellW' => 40.0, 'cellH' => 25.0, 'mLeft' => 0.0, 'mTop' => 0.0],
        ];
        $cfg = $presets[$formato] ?? $presets['carta'];

        $pdf = new \TCPDF($cfg['orient'], 'mm', $cfg['page'], true, 'UTF-8', false);
        $pdf->SetTitle('Etiquetas QR de productos');
        $pdf->SetAuthor('Constructora Vidalsa 27, C.A.');
        $pdf->SetCreator('Sistema de Gestión VIDALSA');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        // Fuente base: writeHTMLCell la usa como punto de partida (igual que en la Nota
        // de Entrega) y respeta el encoding UTF-8 del documento — los nombres con tildes
        // y ñ salen correctos sin tocar Cell() directamente.
        $pdf->SetFont('helvetica', '', 8);

        if ($formato === 'carta') {
            $usableH = 297.0 - 2 * $cfg['mTop'];
            $rows    = max(1, (int) floor($usableH / $cfg['cellH']));
            $perPage = $cfg['cols'] * $rows;
            foreach ($secuencia as $i => $p) {
                $pos = $i % $perPage;
                if ($pos === 0) {
                    $pdf->AddPage();
                }
                $col = $pos % $cfg['cols'];
                $row = intdiv($pos, $cfg['cols']);
                $x = $cfg['mLeft'] + $col * $cfg['cellW'];
                $y = $cfg['mTop']  + $row * $cfg['cellH'];
                $this->dibujarEtiqueta($pdf, $p, $x, $y, $cfg['cellW'], $cfg['cellH']);
            }
        } else {
            foreach ($secuencia as $p) {
                $pdf->AddPage();
                $this->dibujarEtiqueta($pdf, $p, 0.0, 0.0, $cfg['cellW'], $cfg['cellH']);
            }
        }

        return $pdf->Output('', 'S');
    }

    /**
     * Dibuja UNA etiqueta dentro del rectángulo (x,y,w,h): QR a la izquierda,
     * a su derecha "Serial: CODIGO", NOMBRE en negrita y UM. Borde de recorte
     * punteado gris. writeHTMLCell para UTF-8 (tildes/ñ).
     */
    private function dibujarEtiqueta(\TCPDF $pdf, $p, float $x, float $y, float $w, float $h): void
    {
        // Borde de recorte: línea punteada fina y gris alrededor de la etiqueta. Solo
        // contorno ('D'), sin relleno. Se restablece el estilo de línea para no afectar
        // a las siguientes etiquetas/elementos.
        $pdf->SetLineStyle(['width' => 0.1, 'cap' => 'butt', 'join' => 'miter', 'dash' => '2,2', 'color' => [150, 150, 150]]);
        $pdf->Rect($x, $y, $w, $h, 'D');
        $pdf->SetLineStyle(['width' => 0.1, 'dash' => 0, 'color' => [0, 0, 0]]);

        // QR a la izquierda, cuadrado al 72% del alto útil, centrado verticalmente.
        // Margen interno (quiet zone) para que cualquier lector lo capture: 1,2 mm sigue
        // siendo holgado para el nivel de corrección H que usa el código.
        // El pad y el QR se achicaron al pasar la hoja a 3 columnas — así entra más
        // descripción al lado sin que la etiqueta crezca.
        $pad    = 1.2;
        $qrSize = ($h - 2 * $pad) * 0.72;           // bastante más pequeño que el alto útil
        if ($qrSize < 6.0) {
            $qrSize = max(6.0, $h - 2 * $pad);
        }

        $style = [
            'border'        => false,
            'vpadding'      => 0,
            'hpadding'      => 0,
            'fgcolor'       => [0, 0, 0],
            'bgcolor'       => [255, 255, 255],
            'module_width'  => 1,
            'module_height' => 1,
        ];
        $qrX = $x + $pad;
        $qrY = $y + ($h - $qrSize) / 2;
        $pdf->write2DBarcode($p->qr_payload, 'QRCODE,H', $qrX, $qrY, $qrSize, $qrSize, $style, 'N');

        // Texto a la derecha del QR. La separación baja de 2,5 a 1,5 mm por el mismo motivo
        // que el pad: con 3 columnas cada milímetro se nota en cuánta descripción entra.
        $tx = $qrX + $qrSize + 1.5;
        $tw = $w - ($tx - $x) - $pad;
        if ($tw < 8.0) {
            return; // etiqueta muy angosta: queda solo el QR.
        }

        // Letra más pequeña que antes (era 7,5 / 5,5): la celda de 'carta' pasó de 80 a
        // 66 mm de ancho, así que con el cuerpo viejo la descripción se partía en muchas
        // líneas y se salía del alto de la etiqueta.
        $pt = $w > 55.0 ? 6.0 : 5.0;

        $codigo = htmlspecialchars((string) $p->CODIGO, ENT_QUOTES, 'UTF-8');
        $nombre = htmlspecialchars((string) $p->NOMBRE, ENT_QUOTES, 'UTF-8');
        $um     = htmlspecialchars((string) $p->UM, ENT_QUOTES, 'UTF-8');

        // Medir líneas del nombre con fuente bold (la que se usa en el HTML).
        $pdf->SetFont('helvetica', 'B', $pt);
        $lineMm  = ($pt / 72 * 25.4) * 1.3;
        $nLineas = 1 + max(1, $pdf->getNumLines((string) $p->NOMBRE, $tw))
                     + ($um !== '' ? 1 : 0);
        $textoH  = $nLineas * $lineMm;
        $ty      = $y + max($pad, ($h - $textoH) / 2.0);

        $pdf->SetFont('helvetica', '', $pt);
        $html = '<div style="font-family:helvetica;color:#0f172a;font-size:' . $pt . 'pt;line-height:1.3;">'
              . 'Serial: ' . $codigo . '<br>'
              . '<b>' . $nombre . '</b><br>'
              . $um
              . '</div>';
        $pdf->writeHTMLCell($tw, 0, $tx, $ty, $html, 0, 0, false, true, 'L', true);
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
            'lineas.*.id_producto'  => 'required|integer|exists:productos_inventario,ID_PRODUCTO',
            // La vista previa NUNCA debe dejar pasar una salida que deje el saldo en negativo:
            // el material que no existe no se puede entregar. Por eso exigimos gt:0 y validamos
            // el saldo SIEMPRE (sin excepción permitir_negativo) — la revisión de stock es el
            // paso previo obligatorio antes de generar la Nota de Entrega.
            'lineas.*.cantidad'     => 'required|numeric|gt:0',
            'lineas.*.numero_parte' => 'nullable|string|max:100',
        ]);

        $this->assertPuedeVerAlmacen($request, (int) $data['id_almacen']);

        $productos = ProductoInventario::whereIn('ID_PRODUCTO',
                collect($data['lineas'])->pluck('id_producto')->map(fn ($n) => (int) $n)->all())
            ->get(['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM'])->keyBy('ID_PRODUCTO');

        // Gate de stock: rechaza cualquier línea que supere el saldo disponible. La Nota de
        // Entrega solo se genera si TODO el material solicitado existe físicamente — NO se
        // permite negativo en este flujo, ni siquiera a super.admin.
        $stocks = AlmacenStock::where('ID_ALMACEN', (int) $data['id_almacen'])
            ->whereIn('ID_PRODUCTO', $productos->keys()->all())
            ->get(['ID_PRODUCTO', 'CANTIDAD'])->keyBy('ID_PRODUCTO');

        // Se agregan las cantidades POR PRODUCTO antes de comparar: el endpoint real
        // (registrarMovimientoLote → registrarSalida) descuenta línea a línea sobre el mismo
        // saldo bloqueado, así que dos líneas del mismo producto se suman. Comparar cada línea
        // por separado dejaría pasar un preview que el "Confirmar" rechazaría.
        $pedidoPorProducto = [];
        foreach ($data['lineas'] as $l) {
            $idp = (int) $l['id_producto'];
            $pedidoPorProducto[$idp] = ($pedidoPorProducto[$idp] ?? 0.0) + (float) $l['cantidad'];
        }

        $excesos = [];
        foreach ($pedidoPorProducto as $idp => $pedido) {
            $disp = (float) ($stocks[$idp]->CANTIDAD ?? 0);
            if ($pedido > $disp) {
                $excesos[] = ($productos[$idp]->NOMBRE ?? ('#' . $idp))
                    . ' (' . rtrim(rtrim(number_format($pedido, 3, '.', ''), '0'), '.')
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
                'CANTIDAD'     => (float) $l['cantidad'],
                // Nº de parte específico (filtros): el preview también lo muestra, igual que
                // el PDF final. El blade lo lee null-safe, así que si no vino queda vacío.
                'NUMERO_PARTE' => $l['numero_parte'] ?? null,
                'producto'     => $productos[(int) $l['id_producto']] ?? null,
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
        // El cabezote arranca en y=16 (16mm de aire desde el borde superior del papel)
        // y mide 24mm/68pt -> bottom = 40mm. top=40 = bottom del cabezote: el body
        // arranca PEGADO al cabezote, sin franja blanca entre ambos. Si subes/bajas
        // $cabY o $headerHeight en NotaEntregaPDF::Header(), recalcular este top
        // (= cabY + cabH).
        $pdf->SetMargins(10, 40, 10);
        $pdf->SetHeaderMargin(16);
        // Borde blanco SIMETRICO arriba/abajo: el cabezote deja 16mm de aire en el
        // tope (y=cabY=16), asi que el margen de quiebre de pagina —que es el blanco
        // inferior cuando el contenido llega al fondo— tambien es 16mm. FooterMargin
        // queda inerte porque setPrintFooter(false) no dibuja pie.
        $pdf->SetFooterMargin(10);
        $pdf->SetAutoPageBreak(true, 16);
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
     * Elimina TODA la Nota de Entrega identificada por ?numero=NE-YYYY-NNNN.
     * En la misma transacción reversa el stock por cada línea (suma de vuelta
     * lo que la SALIDA había restado), encadenando una ENTRADA inversa a través
     * de InventarioService::registrarEntrada — esto crea una fila de kardex
     * que documenta la reversión (auditable) y NO borra los movimientos SALIDA
     * originales (el kardex sigue siendo append-only y verificable).
     *
     * Permiso: almacen.nota.eliminar (gateado en routes/web.php). Los movimientos
     * del lote deben pertenecer a un único almacén y el usuario debe poder verlo.
     */
    public function eliminarNota(Request $request)
    {
        $numero = trim((string) $request->query('numero', $request->input('numero', '')));
        if ($numero === '') {
            return response()->json(['message' => 'Ingresa un N° de Nota.'], 422);
        }

        // Pre-chequeos FUERA de la transacción: sirven sólo para devolver un 404/422/400 barato
        // con el mensaje correcto. La lectura autoritativa se repite bajo lock más abajo — sin
        // eso, dos peticiones concurrentes (doble clic / dos pestañas) leerían ambas la misma
        // nota vigente y acreditarían el stock DOS veces.
        //
        // Buscamos por NUMERO_NOTA SIN filtrar el tipo: la misma nota puede ser una SALIDA pura
        // o una TRASPASO_SALIDA (envío a otro almacén/proyecto). Filtrar solo TIPO_SALIDA daba un
        // 404 engañoso para las notas de traspaso, que SÍ existen y se listan con su PDF.
        $previa = MovimientoInventario::where('NUMERO_NOTA', $numero)->get();

        if ($previa->isEmpty()) {
            return response()->json(['message' => 'No se encontró ninguna Nota con ese código.'], 404);
        }

        // Si la nota incluye un ENVÍO (traspaso), NO se revierte aquí: el traspaso movió stock por
        // su propio flujo (salida en origen + entrada al confirmar en destino) y tiene su propia
        // anulación. Revertirlo con una ENTRADA simple lo desincronizaría y duplicaría stock.
        if ($previa->contains(fn ($m) => $m->TIPO === MovimientoInventario::TIPO_TRASPASO_SALIDA)) {
            return response()->json([
                'message' => 'Esta Nota corresponde a un envío a otro almacén/proyecto (traspaso). Para anularla, cancela el traspaso desde Recepción, no desde aquí.',
            ], 422);
        }

        abort_if(
            $previa->pluck('ID_ALMACEN')->unique()->count() > 1,
            400,
            'Los movimientos de la nota deben pertenecer a un único almacén.'
        );
        $this->assertPuedeVerAlmacen($request, (int) $previa->first()->ID_ALMACEN);

        try {
            DB::transaction(function () use ($request, $numero) {
                // Relectura bajo lock: la primera transacción que llegue aquí gana. La segunda
                // se queda esperando el lock y, cuando entra, ya ve NUMERO_NOTA=null → colección
                // vacía → aborta sin revertir nada por segunda vez.
                $movs = MovimientoInventario::where('NUMERO_NOTA', $numero)
                    ->orderBy('ID_MOVIMIENTO')
                    ->lockForUpdate()
                    ->get();

                if ($movs->isEmpty()) {
                    throw new RuntimeException('Esta Nota ya fue eliminada por otra operación.');
                }

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

        // El update masivo de arriba es por query builder: no emite eventos de modelo, así
        // que ningún observer invalida. La huella SÍ lo vería (Eloquent pone updated_at en
        // los update masivos), pero solo al vencer el TTL de 5 min. Este aviso lo baja a
        // inmediato. No hace falta resetear: se editan filas, no se borran.
        \App\Support\OfflineVersion::invalidar('almacen');

        return response()->json([
            'message'     => "Nota {$numero} eliminada y stock revertido.",
            'numero_nota' => $numero,
            // $previa, no $movs: $movs solo existe DENTRO del closure de la transacción (se lee
            // bajo lock ahí). Fuera vale null → null->count() lanzaba un 500 tras un borrado que
            // sí había commiteado. $previa (la lectura previa al lock) tiene el mismo recuento.
            'lineas'      => $previa->count(),
        ]);
    }

    /**
     * Deshace un movimiento del kardex — EXCLUSIVO super.admin (gate `can:super.admin`
     * en la ruta). Borrado DURO sin rastro: elimina la fila, revierte el stock y recalcula
     * el saldo de los movimientos posteriores para que el kardex quede coherente. En
     * traspasos deshace ambas patas del par enlazado. Irreversible.
     */
    public function eliminarMovimiento(Request $request, int $id)
    {
        $mov = MovimientoInventario::find($id);
        if (! $mov) {
            return response()->json(['message' => 'El movimiento no existe o ya fue eliminado.'], 404);
        }
        // Coherencia con el resto del módulo: el almacén del movimiento debe ser visible
        // para el usuario (un super.admin GLOBAL los ve todos).
        $this->assertPuedeVerAlmacen($request, (int) $mov->ID_ALMACEN);

        try {
            $r = $this->inventario->eliminarMovimientoConReverso($id);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $msg = $r['eliminados'] > 1
            ? "Movimiento deshecho ({$r['eliminados']} filas del traspaso) y stock recalculado."
            : 'Movimiento deshecho y stock recalculado.';

        return response()->json(['message' => $msg, 'eliminados' => $r['eliminados']]);
    }

    /**
     * Elimina un movimiento SOLO del historial — EXCLUSIVO super.admin (gate `can:super.admin`
     * en la ruta). A diferencia de eliminarMovimiento() (que deshace y recalcula el stock),
     * este NO toca el stock: el saldo de almacen_stock queda igual y solo desaparece la fila
     * del kardex (más su contraparte si es traspaso). Irreversible.
     */
    public function eliminarMovimientoSoloHistorial(Request $request, int $id)
    {
        $mov = MovimientoInventario::find($id);
        if (! $mov) {
            return response()->json(['message' => 'El movimiento no existe o ya fue eliminado.'], 404);
        }
        // Mismo control de visibilidad que el deshacer: el almacén del movimiento debe ser
        // visible para el usuario (un super.admin GLOBAL los ve todos).
        $this->assertPuedeVerAlmacen($request, (int) $mov->ID_ALMACEN);

        try {
            $r = $this->inventario->eliminarMovimientoSinReverso($id);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $msg = $r['eliminados'] > 1
            ? "Registro eliminado del historial ({$r['eliminados']} filas del traspaso). El stock NO se modificó."
            : 'Registro eliminado del historial. El stock NO se modificó.';

        return response()->json(['message' => $msg, 'eliminados' => $r['eliminados']]);
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
            // frentes: array de IDs. Obligatorio para AMBOS tipos (GENERAL y PROYECTO) —
            // la asociacion a frentes es la que define que usuarios LOCAL ven el almacen
            // (ver Almacen::visiblesPara). Sin al menos 1 frente, ningun LOCAL lo veria.
            'frentes'           => ['required', 'array', 'min:1'],
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
            // CODIGO es VARCHAR(50). Solo se permiten dígitos, tanto el tecleado a
            // mano (el frontend lo fuerza y aquí lo validamos con regex) como el
            // autogenerado por generarCodigoProducto (numérico de 6 cifras).
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
        // Se asocian frentes a CUALQUIER tipo de almacen (GENERAL o PROYECTO): los
        // frentes definen que usuarios LOCAL ven el almacen (ver Almacen::visiblesPara).
        $almacen->frentes()->sync($ids);

        // Esta tabla pivote decide QUÉ ALMACENES ve un usuario LOCAL, y con ello qué stock
        // y qué movimientos entran en su snapshot. Al cambiarla, el alcance del cliente
        // offline cambia sin que se haya editado ni una fila de inventario: ninguna huella
        // lo notaría. El reseteo le pide la copia completa del dominio.
        \App\Support\OfflineVersion::resetear('almacen');
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
        //    [LOGO 20%]  |  NOTA DE ENTREGA DE MATERIALES (52%)  |  [SELLO 28%]
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
        //   x = 10 mm  (margen izquierdo)         width = 190 mm  (210 - 10*2)
        //   y = 16 mm  (16mm de aire desde el borde sup.) height = 68 pt = 24 mm
        //   bottom = y + height = 40 mm           ← coincide con SetMargins top=40
        //                                          para que la tabla del body
        //                                          arranque PEGADA al cabezote.
        // Celda del logo (20% de 190 = 38 mm — igual que col PROYECTO: del body):
        //   left   = 10     right = 10 + 38 = 48      center x = 29
        //   top    = 16     bottom = 40                center y = 28
        $headerHeight = 68;          // pt (= ~24 mm)
        $cabX = 10;
        $cabY = 16;
        $cabW = 190;                 // mm = 210 - 10 (izq) - 10 (der)
        $cabH = 24;                  // mm (≈ 68 pt)
        $logoCellW = $cabW * 0.20;   // 38 mm — alinea con col PROYECTO: (20%) del body

        $img = public_path('img/imagen_uno.jpg');
        if (file_exists($img)) {
            // Logo centrado HORIZONTAL + VERTICALMENTE dentro de la celda 20% × 24 mm.
            // Usamos $fitbox = 'CM' (Center-Middle) — TCPDF escala la imagen para que
            // entre dentro del bbox preservando aspect ratio y la centra en ambos ejes.
            // Padding interno de 1 mm para que el logo no toque el borde de la celda.
            $padding = 1;
            $bx = $cabX + $padding;                 // 11
            $by = $cabY + $padding;                 // 17
            $bw = $logoCellW - ($padding * 2);      // 36  (= 38 - 2)
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
              .   '<td width="20%" rowspan="5" height="' . $headerHeight . '">&nbsp;</td>'
              .   '<td width="52%" rowspan="5" height="' . $headerHeight . '" align="center" valign="middle">' . $tituloDiv . '</td>'
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
        // x=10 ($cabX), y=16 ($cabY) → margen izquierdo y top del cabezote.
        $this->writeHTMLCell($cabW, 0, $cabX, $cabY, $html, 0, 0, 0, true, 'L', true);
    }

    public function Footer()
    {
        $this->SetY(-10);
        $this->SetFont('helvetica', 'I', 7);
        // writeHTMLCell en vez de Cell(): Cell() con helvetica no procesa UTF-8
        // y rompe los acentos (la 'ó' de "Gestión" se mostraba como "Ã³").
        // writeHTMLCell respeta el encoding del documento (configurado como UTF-8).
        $this->writeHTMLCell(0, 6, '', $this->GetY(), '<div style="text-align:center;font-family:helvetica;font-weight:bold;font-size:7pt;">EMITIDO POR SISTEMA DE GESTI&Oacute;N DE FLOTA</div>', 0, 0, 0, true, 'C', true);
    }
}
