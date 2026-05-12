<?php

namespace App\Http\Controllers;

use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use App\Models\CatalogoSeguro;
use App\Models\CaracteristicaModelo;
use App\Models\Documentacion;
use App\Models\Responsable;
use Illuminate\Http\Request;
use App\Models\TipoEquipo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class EquipoController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth')->except(['mobileIndex']);
        // Registro uno a uno + carga masiva via Excel: requieren 'equipos.create'.
        // Gate::before resuelve super.admin.
        $this->middleware('can:equipos.create')->only(['store', 'bulkTemplate', 'bulkPreview', 'bulkStoreBatch']);
        // edit/update: permiso 'user.edit' (boton lapiz del modal detalles
        // + formulario de edicion de ficha). changeStatus: 'equipos.edit'
        // (cambio de estatus inline, desacoplado de la edicion general).
        $this->middleware('can:user.edit')->only(['edit', 'update']);
        $this->middleware('can:equipos.edit')->only(['changeStatus']);
        // Borrar un equipo es destructivo irreversible: solo super.admin.
        $this->middleware('can:super.admin')->only(['destroy']);
        // uploadDoc/updateMetadata: permission 'user.edit' (chequeo dentro de cada metodo).
        // deleteDoc (borrado destructivo de PDF + Drive): solo super.admin, gateado en routes/web.php.
    }

    /**
     * Centralized lookup. NO aplica barrera por NIVEL_ACCESO / jurisdiccion:
     * la filosofia del sistema es "solo la clave PERMISOS decide el acceso".
     * El control granular ya esta en el middleware/@can de cada operacion.
     */
    private function findAndAuthorizeEquipo($id, $with = [])
    {
        $query = \App\Models\Equipo::query();
        if (!empty($with)) {
            $query->with($with);
        }
        return $query->findOrFail($id);
    }

    /**
     * True si el request trae un filtro de BÚSQUEDA o de ATRIBUTO concreto
     * (serial/placa/etiqueta, modelo, marca, año, categoría, estado, ubicación,
     * GPS o documentación). NO cuenta `id_frente` ni `id_tipo` (son ejes de
     * navegación). Cuando esto es true, el listado NO oculta los frentes
     * ESPECIAL: si el usuario busca algo concreto, debe ver todo lo que coincide
     * (incluida la flota de asignaciones especiales).
     */
    private function tieneFiltroEspecifico(Request $request): bool
    {
        if ($request->filled('search_query')) {
            return true;
        }
        foreach (['modelo', 'marca', 'detalle_ubicacion', 'anio', 'categoria', 'estado'] as $p) {
            if ($request->filled($p)) {
                return true;
            }
        }
        if (in_array(strtoupper(trim((string) $request->input('gps', ''))), ['SI', 'NO'], true)) {
            return true;
        }
        foreach (['filter_propiedad', 'filter_poliza', 'filter_rotc', 'filter_racda', 'filter_adicional', 'filter_adicional_2'] as $p) {
            if ($request->input($p) === 'true') {
                return true;
            }
        }
        return false;
    }

    /**
     * Aplica al query los filtros activos del request. `$exclude` permite omitir ejes
     * específicos para que los stats de una dimensión no queden limitados por su propio filtro.
     */
    private function applyEquipoFilters($query, Request $request, array $exclude = []): void
    {
        $user = auth()->user();
        $isLocalUser = $user && $user->NIVEL_ACCESO == 2;
        $frentesPermitidos = $user ? $user->getFrentesIds() : [];
        $search = $request->input('search_query');

        if (empty($search)) {
            if ($isLocalUser && count($frentesPermitidos) > 0) {
                $query->whereIn('ID_FRENTE_ACTUAL', $frentesPermitidos);
            } elseif ($isLocalUser) {
                $query->whereRaw('1 = 0');
            }
        }

        if (!in_array('id_frente', $exclude)) {
            $raw = trim((string) $request->input('id_frente', ''));
            if ($raw === 'none') {
                // Sentinel "SIN ASIGNAR": equipos sin ID_FRENTE_ACTUAL en BD.
                $query->whereNull('ID_FRENTE_ACTUAL');
            } elseif ($raw !== '' && $raw !== 'all') {
                // Frente específico seleccionado: respeta el filtro exacto (aunque sea ESPECIAL).
                $query->where('ID_FRENTE_ACTUAL', $raw);
            } elseif (!$this->tieneFiltroEspecifico($request)) {
                // "TODOS LOS FRENTES" y SIN búsqueda/filtro de atributo: ocultar los frentes
                // ESPECIAL (asignaciones especiales, no flota propia). Si el usuario busca por
                // serial/placa/etc. o filtra por modelo/marca/año/..., los ESPECIAL SÍ se incluyen.
                $query->excludeEspecial();
            }
        }

        if (!in_array('id_tipo', $exclude) && $request->filled('id_tipo') && trim($request->id_tipo) !== '' && $request->id_tipo !== 'all') {
            $query->where('id_tipo_equipo', $request->id_tipo);
        }

        if (!in_array('modelo', $exclude) && $request->filled('modelo') && trim($request->modelo) !== '') {
            $query->where('MODELO', $request->modelo);
        }

        if (!in_array('marca', $exclude) && $request->filled('marca') && trim($request->marca) !== '') {
            $query->where('MARCA', $request->marca);
        }

        if (!in_array('detalle_ubicacion', $exclude) && $request->filled('detalle_ubicacion') && trim($request->detalle_ubicacion) !== '') {
            $query->where('DETALLE_UBICACION_ACTUAL', $request->detalle_ubicacion);
        }

        if (!in_array('anio', $exclude) && $request->filled('anio') && trim($request->anio) !== '') {
            $query->where('ANIO', $request->anio);
        }

        if (!in_array('categoria', $exclude) && $request->filled('categoria') && trim($request->categoria) !== '') {
            $query->where('CATEGORIA_FLOTA', $request->categoria);
        }

        if (!in_array('estado', $exclude) && $request->filled('estado') && trim($request->estado) !== '') {
            $query->where('ESTADO_OPERATIVO', $request->estado);
        }

        if (!in_array('gps', $exclude) && $request->filled('gps') && trim($request->gps) !== '') {
            $val = strtoupper(trim($request->gps));
            if ($val === 'SI') {
                $query->whereNotNull('LINK_GPS')->where('LINK_GPS', '!=', '');
            } elseif ($val === 'NO') {
                $query->where(function($q) {
                    $q->whereNull('LINK_GPS')->orWhere('LINK_GPS', '=', '');
                });
            }
        }

        // Filtros de documentacion (Propiedad/Poliza/ROTC/RACDA). Antes vivian
        // SOLO en index() aplicandose al query principal, asi que las stats
        // ($statsBase, $tiposQuery, $frentesQuery, $ubicQuery) NO los reflejaban
        // y el card "Ubicación por Frente" mostraba el conteo sin tener en
        // cuenta estos filtros. Usamos whereHas para evitar conflicto con los
        // leftJoin existentes (doc_search, doc_filter) en el query principal.
        // IMPORTANTE: !=null Y !='': el LINK_* puede quedar como string vacio
        // tras un borrado y un whereNotNull solo no lo descarta. Sin esto el
        // filtro "Propiedad" devolvia equipos sin PDF cargado realmente.
        $docFlags = [
            'filter_propiedad'   => 'LINK_DOC_PROPIEDAD',
            'filter_poliza'      => 'LINK_POLIZA_SEGURO',
            'filter_rotc'        => 'LINK_ROTC',
            'filter_racda'       => 'LINK_RACDA',
            'filter_adicional'   => 'LINK_DOC_ADICIONAL',
            'filter_adicional_2' => 'LINK_DOC_ADICIONAL_2',
        ];
        foreach ($docFlags as $param => $col) {
            if (!in_array($param, $exclude) && $request->filled($param) && $request->input($param) === 'true') {
                $query->whereHas('documentacion', function ($q) use ($col) {
                    $q->whereNotNull($col)->where($col, '!=', '');
                });
            }
        }
    }

    public function index(Request $request)
    {
        $search = $request->input('search_query');
        $equipos = Equipo::query();

        $user = auth()->user();
        $isLocalUser = $user && $user->NIVEL_ACCESO == 2;
        $frentesPermitidos = $user ? $user->getFrentesIds() : [];

        // Filtros principales (todos los ejes activos)
        $this->applyEquipoFilters($equipos, $request);

        if ($search) {
            $searchUpper = strtoupper(trim($search));

            // Smart Search by prefix
            if (strpos($searchUpper, '#') !== false) {
                // Mode: Tag Number Search
                $tagSearch = str_replace('#', '', $searchUpper);
                $equipos->where('NUMERO_ETIQUETA', 'like', "%{$tagSearch}%");

            } elseif (strpos($searchUpper, '-') !== false) {
                // Mode: Yard Code Search
                $equipos->where('CODIGO_PATIO', 'like', "%{$searchUpper}%");

            } else {
                // Standard search — O/0 ambiguity applied ONLY to PLACA
                // (plates are the only field where O and 0 are visually confused)
                $placaVariants = collect([
                    $searchUpper,
                    str_replace('O', '0', $searchUpper),
                    str_replace('0', 'O', $searchUpper),
                    str_replace(['O', '0'], ['0', 'O'], $searchUpper),
                ])->unique()->values()->all();

                // To optimize PLACA search without full scanning documentacion table, we join it here
                $equipos->leftJoin('documentacion AS doc_search', 'equipos.ID_EQUIPO', '=', 'doc_search.ID_EQUIPO');

                $equipos->where(function ($q) use ($searchUpper, $placaVariants) {
                    // Exact search for non-plate fields
                    $q->where('equipos.SERIAL_CHASIS', 'like', "%{$searchUpper}%")
                      ->orWhere('equipos.SERIAL_DE_MOTOR', 'like', "%{$searchUpper}%")
                      ->orWhere('equipos.CODIGO_PATIO', 'like', "%{$searchUpper}%")
                      ->orWhere('equipos.NUMERO_ETIQUETA', 'like', "%{$searchUpper}%")
                      // O/0-aware search only for PLACA via Left Join
                      ->orWhere(function ($pq) use ($placaVariants) {
                          foreach ($placaVariants as $variant) {
                              $pq->orWhere('doc_search.PLACA', 'like', "%{$variant}%");
                          }
                      });
                });
            }
        }




        // Filtros de documentacion (filter_propiedad/poliza/rotc/racda) ya
        // estan unificados dentro de applyEquipoFilters() — se aplican via
        // whereHas a $equipos Y a todos los stats queries automaticamente.

        $equipos->select('equipos.*')
            ->leftJoin('tipo_equipos', 'equipos.id_tipo_equipo', '=', 'tipo_equipos.id')
            ->with([
                'documentacion.seguro',
                'especificaciones:ID_ESPEC,COMBUSTIBLE,CONSUMO_PROMEDIO,FOTO_REFERENCIAL',
                'tipo',
                'frenteActual',
                'ancladoA.tipo',
                'ancladoA.documentacion',
                'ancladoA.frenteActual',
                // Especificaciones del equipo anclado para obtener FOTO_REFERENCIAL
                // que se muestra en la seccion "Equipo Anclado" del modal de detalles.
                'ancladoA.especificaciones:ID_ESPEC,FOTO_REFERENCIAL',
            ])
            ->withCount('equiposAuxiliares')
            ->orderBy('tipo_equipos.nombre', 'asc')
            ->orderBy('equipos.CODIGO_PATIO', 'asc');

        // Check if any filter is applied (with non-empty values)
        $hasFilter = $request->filled('id_frente') || $request->filled('id_tipo') || $request->filled('search_query') || $request->filled('modelo') || $request->filled('marca') || $request->filled('detalle_ubicacion') || $request->filled('anio') || $request->filled('categoria') || $request->filled('estado') || $request->filled('gps') || $request->filled('filter_propiedad') || $request->filled('filter_poliza') || $request->filled('filter_rotc') || $request->filled('filter_racda') || $request->filled('filter_adicional') || $request->filled('filter_adicional_2');

        // Paginación server-side con cap por página.
        // La tabla carga 150 filas por request; al final el frontend pide el siguiente lote
        // (offset += 150) con IntersectionObserver para scroll infinito.
        $PAGE_SIZE = 150;
        $offset    = max(0, (int) $request->input('offset', 0));
        $totalFound = 0;
        $truncated  = false;
        $nextOffset = 0;
        $hasMore    = false;
        if ($hasFilter) {
            $totalFound = (clone $equipos)->count();
            $equipos->offset($offset)->limit($PAGE_SIZE);
            $allResults = $equipos->get();
            $equipos    = $allResults;
            $nextOffset = $offset + $allResults->count();
            $hasMore    = $nextOffset < $totalFound;
            $truncated  = $totalFound > $PAGE_SIZE; // legacy flag para compatibilidad
        } else {
            $allResults = collect([]);
            $equipos    = collect([]);
        }

        $stats = ['total' => 0, 'activos' => 0, 'inactivos' => 0, 'mantenimiento' => 0];
        $tiposStats  = collect([]);
        $frentesStats = [];
        $ubicacionesStats = collect([]);
        $frenteEspecial = null;

        // Stats, tiposStats, frentesStats, ubicacionesStats usan queries independientes del
        // cap de la tabla: siempre muestran los TOTALES completos según los filtros activos.
        if ($hasFilter) {
            // Stats: count directo con los mismos filtros, sin offset/limit
            $statsBase = Equipo::query();
            $this->applyEquipoFilters($statsBase, $request);
            // El "total" excluye DESINCORPORADO por defecto, PERO si el usuario filtra
            // explícitamente por estado=DESINCORPORADO el total refleja esos equipos.
            $filtroEstado = strtoupper(trim((string) $request->input('estado', '')));
            $stats['total']           = $filtroEstado === 'DESINCORPORADO'
                ? (clone $statsBase)->count()
                : (clone $statsBase)->where('ESTADO_OPERATIVO', '!=', 'DESINCORPORADO')->count();
            $stats['activos']         = (clone $statsBase)->where('ESTADO_OPERATIVO', 'OPERATIVO')->count();
            $stats['inactivos']       = (clone $statsBase)->where('ESTADO_OPERATIVO', 'INOPERATIVO')->count();
            $stats['mantenimiento']   = (clone $statsBase)->where('ESTADO_OPERATIVO', 'EN MANTENIMIENTO')->count();
            $stats['desincorporados'] = (clone $statsBase)->where('ESTADO_OPERATIVO', 'DESINCORPORADO')->count();

            // Tipos Stats — siempre muestra todos los tipos (sin filtro por id_tipo) para no autolimitarse
            $tiposQuery = Equipo::query()->leftJoin('tipo_equipos', 'equipos.id_tipo_equipo', '=', 'tipo_equipos.id');
            $this->applyEquipoFilters($tiposQuery, $request, ['id_tipo']);
            $tiposStats = $tiposQuery
                ->select('equipos.id_tipo_equipo', 'tipo_equipos.nombre', DB::raw('COUNT(*) as total'))
                ->groupBy('equipos.id_tipo_equipo', 'tipo_equipos.nombre')
                ->orderBy('tipo_equipos.nombre', 'asc')
                ->get();

            // Frentes Stats — se muestra cuando hay un tipo filtrado; listamos TODOS los frentes que coinciden (sin filtro id_frente)
            if ($request->filled('id_tipo')) {
                $frentesQuery = Equipo::query()->leftJoin('frentes_trabajo', 'equipos.ID_FRENTE_ACTUAL', '=', 'frentes_trabajo.ID_FRENTE');
                $this->applyEquipoFilters($frentesQuery, $request, ['id_frente']);
                $frentesStats = $frentesQuery
                    ->whereNotNull('equipos.ID_FRENTE_ACTUAL')
                    ->select('equipos.ID_FRENTE_ACTUAL', 'frentes_trabajo.NOMBRE_FRENTE', DB::raw('COUNT(*) as total'))
                    ->groupBy('equipos.ID_FRENTE_ACTUAL', 'frentes_trabajo.NOMBRE_FRENTE')
                    ->orderBy('frentes_trabajo.NOMBRE_FRENTE', 'asc')
                    ->get();
            }

            // Ubicaciones (DETALLE_UBICACION_ACTUAL) — solo si el frente filtrado es ESPECIAL;
            // se listan TODAS las ubicaciones del frente (excluyendo el filtro detalle_ubicacion)
            if ($request->filled('id_frente') && $request->id_frente !== 'all') {
                $frenteEspecial = FrenteTrabajo::where('ID_FRENTE', $request->id_frente)
                    ->where('TIPO_FRENTE', 'ESPECIAL')
                    ->first();
                if ($frenteEspecial) {
                    $ubicQuery = Equipo::query();
                    $this->applyEquipoFilters($ubicQuery, $request, ['detalle_ubicacion']);
                    $rawUbicaciones = $ubicQuery
                        ->select(
                            DB::raw("COALESCE(NULLIF(TRIM(DETALLE_UBICACION_ACTUAL), ''), '__SIN_ASIGNAR__') as ubi_key"),
                            DB::raw('COUNT(*) as total')
                        )
                        ->groupBy('ubi_key')
                        ->orderBy('total', 'desc')
                        ->get();
                    $ubicacionesStats = $rawUbicaciones->map(fn($r) => (object) [
                        'detalle' => $r->ubi_key === '__SIN_ASIGNAR__' ? 'Sin Asignar' : $r->ubi_key,
                        'total'   => $r->total,
                    ]);
                }
            }
        }
        // else: $stats queda en ceros => la vista muestra '--' (comportamiento original)

        // Build JSON payload (needed for AJAX response AND initial page load script tag)
        $jsonPayload = [];
        if ($hasFilter) {
            foreach ($equipos as $eq) {
                $foto = ($eq->especificaciones && $eq->especificaciones->FOTO_REFERENCIAL)
                        ? $eq->especificaciones->FOTO_REFERENCIAL
                        : $eq->FOTO_EQUIPO;
                $jsonPayload[$eq->ID_EQUIPO] = [
                    'equipoId'        => $eq->ID_EQUIPO,
                    'codigo'          => $eq->CODIGO_PATIO,
                    'marca'           => $eq->MARCA,
                    'modelo'          => $eq->MODELO,
                    'anio'            => $eq->ANIO,
                    'tipo'            => $eq->tipo->nombre ?? 'N/A',
                    'categoria'       => $eq->CATEGORIA_FLOTA,
                    'ubicacion'       => optional($eq->frenteActual)->NOMBRE_FRENTE ?? 'Sin Asignar',
                    'motorSerial'     => $eq->SERIAL_DE_MOTOR,
                    'chasis'          => $eq->SERIAL_CHASIS,
                    'combustible'     => optional($eq->especificaciones)->COMBUSTIBLE ?? 'N/A',
                    'consumo'         => optional($eq->especificaciones)->CONSUMO_PROMEDIO ?? 'N/A',
                    'placa'           => optional($eq->documentacion)->PLACA ?? 'N/A',
                    'titular'         => optional($eq->documentacion)->NOMBRE_DEL_TITULAR ?? 'N/A',
                    'nroDoc'          => optional($eq->documentacion)->NRO_DE_DOCUMENTO ?? 'N/A',
                    // Fechas de vencimiento: todas en formato Y-m-d (consistente con <input type=date>).
                    // Se parsean via Carbon para que sea defensivo frente a casts datetime/string en el model.
                    'vencSeguro'      => optional($eq->documentacion)->FECHA_VENC_POLIZA ? \Carbon\Carbon::parse($eq->documentacion->FECHA_VENC_POLIZA)->format('Y-m-d') : '',
                    'seguro'          => optional(optional($eq->documentacion)->seguro)->NOMBRE_ASEGURADORA ?? 'N/A',
                    'linkPropiedad'   => optional($eq->documentacion)->LINK_DOC_PROPIEDAD ?? '',
                    'propiedadAutor'  => optional($eq->documentacion)->PROPIEDAD_SUBIDO_POR ?? '',
                    'propiedadFecha'  => optional($eq->documentacion)->PROPIEDAD_FECHA_SUBIDA ? \Carbon\Carbon::parse($eq->documentacion->PROPIEDAD_FECHA_SUBIDA)->format('d/m/y') : '',
                    'linkSeguro'      => optional($eq->documentacion)->LINK_POLIZA_SEGURO ?? '',
                    'polizaAutor'     => optional($eq->documentacion)->POLIZA_SUBIDO_POR ?? '',
                    'polizaFecha'     => optional($eq->documentacion)->POLIZA_FECHA_SUBIDA ? \Carbon\Carbon::parse($eq->documentacion->POLIZA_FECHA_SUBIDA)->format('d/m/y') : '',
                    'linkRotc'        => optional($eq->documentacion)->LINK_ROTC ?? '',
                    'fechaRotc'       => optional($eq->documentacion)->FECHA_ROTC ? \Carbon\Carbon::parse($eq->documentacion->FECHA_ROTC)->format('Y-m-d') : '',
                    'rotcAutor'       => optional($eq->documentacion)->ROTC_SUBIDO_POR ?? '',
                    'rotcFecha'       => optional($eq->documentacion)->ROTC_FECHA_SUBIDA ? \Carbon\Carbon::parse($eq->documentacion->ROTC_FECHA_SUBIDA)->format('d/m/y') : '',
                    'linkRacda'       => optional($eq->documentacion)->LINK_RACDA ?? '',
                    'fechaRacda'      => optional($eq->documentacion)->FECHA_RACDA ? \Carbon\Carbon::parse($eq->documentacion->FECHA_RACDA)->format('Y-m-d') : '',
                    'racdaAutor'      => optional($eq->documentacion)->RACDA_SUBIDO_POR ?? '',
                    'racdaFecha'      => optional($eq->documentacion)->RACDA_FECHA_SUBIDA ? \Carbon\Carbon::parse($eq->documentacion->RACDA_FECHA_SUBIDA)->format('d/m/y') : '',
                    'linkAdicional'   => optional($eq->documentacion)->LINK_DOC_ADICIONAL ?? '',
                    'fechaAdicional'  => optional($eq->documentacion)->FECHA_ADICIONAL ? \Carbon\Carbon::parse($eq->documentacion->FECHA_ADICIONAL)->format('Y-m-d') : '',
                    'adicionalAutor'  => optional($eq->documentacion)->ADICIONAL_SUBIDO_POR ?? '',
                    'adicionalFecha'  => optional($eq->documentacion)->ADICIONAL_FECHA_SUBIDA ? \Carbon\Carbon::parse($eq->documentacion->ADICIONAL_FECHA_SUBIDA)->format('d/m/y') : '',
                    'linkAdicional2'  => optional($eq->documentacion)->LINK_DOC_ADICIONAL_2 ?? '',
                    'fechaAdicional2' => optional($eq->documentacion)->FECHA_ADICIONAL_2 ? \Carbon\Carbon::parse($eq->documentacion->FECHA_ADICIONAL_2)->format('Y-m-d') : '',
                    'adicional2Autor' => optional($eq->documentacion)->ADICIONAL_2_SUBIDO_POR ?? '',
                    'adicional2Fecha' => optional($eq->documentacion)->ADICIONAL_2_FECHA_SUBIDA ? \Carbon\Carbon::parse($eq->documentacion->ADICIONAL_2_FECHA_SUBIDA)->format('d/m/y') : '',
                    'linkGps'         => $eq->LINK_GPS ?? '',
                    'frenteId'        => $eq->ID_FRENTE_ACTUAL,
                    'foto'            => $foto,
                    'rolAnclaje'      => optional($eq->tipo)->ROL_ANCLAJE ?? 'NEUTRO',
                    'anchorId'        => $eq->ID_ANCLAJE ?? '',
                    'anchorCode'      => optional($eq->ancladoA)->CODIGO_PATIO ?? '',
                    'anchorRol'       => optional(optional($eq->ancladoA)->tipo)->ROL_ANCLAJE ?? '',
                    'anchorTipoNombre'=> optional(optional($eq->ancladoA)->tipo)->nombre ?? 'Equipo',
                    'anchorPlaca'     => optional(optional($eq->ancladoA)->documentacion)->PLACA ?? '',
                    'anchorSerial'    => optional($eq->ancladoA)->SERIAL_CHASIS ?? '',
                    'anchorMarca'     => optional($eq->ancladoA)->MARCA ?? '',
                    // Foto del equipo anclado: prioriza FOTO_REFERENCIAL del catalogo,
                    // cae a FOTO_EQUIPO propia. La seccion "Equipo Anclado" del modal
                    // de detalles la muestra si existe; si no, placeholder con icono.
                    'anchorFoto'      => $eq->ancladoA
                        ? (optional(optional($eq->ancladoA)->especificaciones)->FOTO_REFERENCIAL
                            ?? $eq->ancladoA->FOTO_EQUIPO
                            ?? '')
                        : '',
                    'subCount'        => $eq->equipos_auxiliares_count ?? 0,
                    'detalleUbicacion'=> $eq->DETALLE_UBICACION_ACTUAL ?? '',
                ];
            }
        }

        if ($request->wantsJson()) {
            return response()->json([
                'html'         => view('admin.equipos.partials.table_rows', compact('equipos'))->render(),
                'equiposData'  => $jsonPayload,
                'pagination'   => '',
                'stats'        => $stats,
                'truncated'         => $truncated,
                'totalFound'        => $totalFound,
                'shownCount'        => $allResults->count(),
                'hardCap'           => $PAGE_SIZE,
                'pageSize'          => $PAGE_SIZE,
                'offset'            => $offset,
                'nextOffset'        => $nextOffset,
                'hasMore'           => $hasMore,
                'distribution'      => view('admin.equipos.partials.distribution_stats', [
                    'frentesStats' => $frentesStats,
                    'tiposStats'   => $tiposStats,
                    'hasFilter'    => $hasFilter,
                    'showFrentes'  => ($request->filled('id_tipo') && $request->id_tipo !== 'all')
                                      && !($request->filled('id_frente') && $request->id_frente !== 'all'),
                ])->render(),
                'ubicaciones'       => view('admin.equipos.partials.ubicaciones_stats', compact('ubicacionesStats', 'hasFilter', 'frenteEspecial'))->render(),
                'showUbicaciones'   => $frenteEspecial !== null,
            ])->withHeaders([
                // Evita que el browser sirva respuestas JSON cacheadas con stats obsoletas
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma'        => 'no-cache',
                'Expires'       => '0',
            ]);
        }

        $frentesQuery = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE', 'asc');
        if ($isLocalUser && count($frentesPermitidos) > 0) {
            $frentesQuery->whereIn('ID_FRENTE', $frentesPermitidos);
        } elseif ($isLocalUser) {
            $frentesQuery->whereRaw('1 = 0');
        }
        $frentes = $frentesQuery->get();

        $allTipos = TipoEquipo::orderBy('nombre', 'asc')->get();

        // Advanced Filter Lists (Optimized with cache: Only needed for initial page load, not AJAX)
        $availableModelos = \Illuminate\Support\Facades\Cache::remember('equipos_modelos_dropdown', 3600, function () {
            return Equipo::distinct()->whereNotNull('MODELO')->where('MODELO', '!=', '')->orderBy('MODELO', 'asc')->pluck('MODELO');
        });

        $availableMarcas = \Illuminate\Support\Facades\Cache::remember('equipos_marcas_dropdown', 3600, function () {
            return Equipo::distinct()->whereNotNull('MARCA')->where('MARCA', '!=', '')->orderBy('MARCA', 'asc')->pluck('MARCA');
        });

        $availableAnios = \Illuminate\Support\Facades\Cache::remember('equipos_anios_dropdown', 3600, function () {
            return Equipo::distinct()->whereNotNull('ANIO')->orderBy('ANIO', 'desc')->pluck('ANIO');
        });

        // Ubicaciones disponibles para el filtro avanzado — solo las del frente ESPECIAL seleccionado
        $availableUbicaciones = collect([]);
        if ($frenteEspecial) {
            $availableUbicaciones = Equipo::where('ID_FRENTE_ACTUAL', $frenteEspecial->ID_FRENTE)
                ->whereNotNull('DETALLE_UBICACION_ACTUAL')
                ->where('DETALLE_UBICACION_ACTUAL', '!=', '')
                ->distinct()
                ->orderBy('DETALLE_UBICACION_ACTUAL', 'asc')
                ->pluck('DETALLE_UBICACION_ACTUAL');
        }

        $showFrentes = ($request->filled('id_tipo') && $request->id_tipo !== 'all')
                       && !($request->filled('id_frente') && $request->id_frente !== 'all');

        return view('admin.equipos.index', compact('equipos', 'stats', 'frentes', 'allTipos', 'tiposStats', 'frentesStats', 'ubicacionesStats', 'frenteEspecial', 'availableModelos', 'availableMarcas', 'availableAnios', 'availableUbicaciones', 'jsonPayload', 'showFrentes'));
    }

    public function export(Request $request)
    {
        $user = auth()->user();
        $isLocalUser = $user && $user->NIVEL_ACCESO == 2;
        $frentesPermitidos = $user ? $user->getFrentesIds() : [];

        // CRITICAL: Prevent exporting entire database without filters.
        // 'id_frente=all' es un filtro explícito válido (el usuario seleccionó "Todos los Frentes").
        $hasFilter = $request->filled('id_frente')   // incluye 'all' como filtro válido
            || $request->filled('id_tipo')
            || $request->filled('search_query')
            || $request->filled('modelo')
            || $request->filled('marca')
            || $request->filled('anio')
            || $request->filled('categoria')
            || $request->filled('estado')
            || $request->filled('gps')
            || $request->filled('filter_propiedad') && $request->filter_propiedad === 'true'
            || $request->filled('filter_poliza') && $request->filter_poliza === 'true'
            || $request->filled('filter_rotc') && $request->filter_rotc === 'true'
            || $request->filled('filter_racda') && $request->filter_racda === 'true'
            || $request->filled('filter_adicional') && $request->filter_adicional === 'true'
            || $request->filled('filter_adicional_2') && $request->filter_adicional_2 === 'true';

        if (!$hasFilter) {
            return redirect()->back()->with('error', 'Debe aplicar al menos un filtro antes de exportar los datos.');
        }

        $fileName = 'Listado_Maquinarias_Equipos_' . date('Y-m-d_H-i') . '.xlsx';

        // SELECT solo las columnas necesarias para el export (la tabla equipos es muy ancha: fotos, links GPS, etc.).
        $equipos = Equipo::query()->select([
            'ID_EQUIPO', 'ID_FRENTE_ACTUAL', 'id_tipo_equipo', 'ID_ANCLAJE',
            'MARCA', 'MODELO', 'ANIO', 'ESTADO_OPERATIVO', 'CATEGORIA_FLOTA',
            'SERIAL_CHASIS', 'SERIAL_DE_MOTOR',
            'CODIGO_PATIO', 'NUMERO_ETIQUETA', 'LINK_GPS',
        ]);

        // Apply Local User Scope EXCEPT when doing a global text search
        // FIX: Single $search variable — do not re-declare below to avoid losing strtoupper/trim normalization.
        $search = strtoupper(trim((string) $request->input('search_query', '')));
        if (empty($search)) {
            if ($isLocalUser && count($frentesPermitidos) > 0) {
                $equipos->whereIn('ID_FRENTE_ACTUAL', $frentesPermitidos);
            } elseif ($isLocalUser) {
                $equipos->whereRaw('1 = 0');
            }
        }

        // Apply same filters (mismo criterio que el listado, ver applyEquipoFilters)
        if ($request->filled('id_frente') && $request->id_frente === 'none') {
            // Sentinel "SIN ASIGNAR": equipos sin ID_FRENTE_ACTUAL en BD.
            $equipos->whereNull('ID_FRENTE_ACTUAL');
        } elseif ($request->filled('id_frente') && $request->id_frente != 'all') {
            $equipos->where('ID_FRENTE_ACTUAL', $request->id_frente);
        } elseif (!$this->tieneFiltroEspecifico($request)) {
            // Sin frente específico y sin búsqueda/filtro de atributo → ocultar los ESPECIAL.
            $equipos->excludeEspecial();
        }
        if ($request->filled('id_tipo')) {
            $equipos->where('id_tipo_equipo', $request->id_tipo);
        }
        if ($request->filled('modelo')) {
            $equipos->where('MODELO', $request->modelo);
        }
        if ($request->filled('marca')) {
            $equipos->where('MARCA', $request->marca);
        }
        if ($request->filled('anio')) {
            $equipos->where('ANIO', $request->anio);
        }
        if ($request->filled('categoria')) {
            $equipos->where('CATEGORIA_FLOTA', $request->categoria);
        }
        if ($request->filled('estado')) {
            $equipos->where('ESTADO_OPERATIVO', $request->estado);
        }
        if ($request->filled('gps') && trim($request->gps) !== '') {
            $val = strtoupper(trim($request->gps));
            if ($val === 'SI') {
                $equipos->whereNotNull('LINK_GPS')->where('LINK_GPS', '!=', '');
            } elseif ($val === 'NO') {
                $equipos->where(function($q) {
                    $q->whereNull('LINK_GPS')->orWhere('LINK_GPS', '=', '');
                });
            }
        }

        // --- Documentation Filters ---
        $hasDocFilter = ($request->filled('filter_propiedad') && $request->filter_propiedad === 'true') ||
                        ($request->filled('filter_poliza') && $request->filter_poliza === 'true') ||
                        ($request->filled('filter_rotc') && $request->filter_rotc === 'true') ||
                        ($request->filled('filter_racda') && $request->filter_racda === 'true') ||
                        ($request->filled('filter_adicional') && $request->filter_adicional === 'true') ||
                        ($request->filled('filter_adicional_2') && $request->filter_adicional_2 === 'true');

        if ($hasDocFilter) {
            // Mismo patron que applyEquipoFilters/index: !=null Y !=''
            // (los LINK_* pueden quedar como string vacio tras un borrado).
            $equipos->leftJoin('documentacion AS doc_filter', 'equipos.ID_EQUIPO', '=', 'doc_filter.ID_EQUIPO')
                     ->where(function ($q) use ($request) {
                         if ($request->filled('filter_propiedad') && $request->filter_propiedad === 'true') {
                             $q->whereNotNull('doc_filter.LINK_DOC_PROPIEDAD')->where('doc_filter.LINK_DOC_PROPIEDAD', '!=', '');
                         }
                         if ($request->filled('filter_poliza') && $request->filter_poliza === 'true') {
                             $q->whereNotNull('doc_filter.LINK_POLIZA_SEGURO')->where('doc_filter.LINK_POLIZA_SEGURO', '!=', '');
                         }
                         if ($request->filled('filter_rotc') && $request->filter_rotc === 'true') {
                             $q->whereNotNull('doc_filter.LINK_ROTC')->where('doc_filter.LINK_ROTC', '!=', '');
                         }
                         if ($request->filled('filter_racda') && $request->filter_racda === 'true') {
                             $q->whereNotNull('doc_filter.LINK_RACDA')->where('doc_filter.LINK_RACDA', '!=', '');
                         }
                         if ($request->filled('filter_adicional') && $request->filter_adicional === 'true') {
                             $q->whereNotNull('doc_filter.LINK_DOC_ADICIONAL')->where('doc_filter.LINK_DOC_ADICIONAL', '!=', '');
                         }
                         if ($request->filled('filter_adicional_2') && $request->filter_adicional_2 === 'true') {
                             $q->whereNotNull('doc_filter.LINK_DOC_ADICIONAL_2')->where('doc_filter.LINK_DOC_ADICIONAL_2', '!=', '');
                         }
                     });
        }

        // $search already normalized above — no re-declaration needed.
        if ($search) {
            if (strpos($search, '#') !== false) {
                $tagSearch = str_replace('#', '', $search);
                $equipos->where('NUMERO_ETIQUETA', 'like', "%{$tagSearch}%");
            } else {
                // Optimize PLACA search with leftJoin instead of whereHas
                $equipos->leftJoin('documentacion AS doc_search', 'equipos.ID_EQUIPO', '=', 'doc_search.ID_EQUIPO');
                // Ensure we only select from equipos explicitly, to prevent joined tables overwriting keys
                $equipos->select('equipos.*');
                
                $equipos->where(function ($q) use ($search) {
                    $q->where('equipos.SERIAL_CHASIS', 'like', "%{$search}%")
                        ->orWhere('doc_search.PLACA', 'like', "%{$search}%")
                        ->orWhere('equipos.SERIAL_DE_MOTOR', 'like', "%{$search}%")
                        ->orWhere('equipos.CODIGO_PATIO', 'like', "%{$search}%")
                        ->orWhere('equipos.NUMERO_ETIQUETA', 'like', "%{$search}%");
                });
            }
        }

        // Eager load solo los campos necesarios para el export (evitar SELECT * en relaciones pesadas).
        $equipos->with([
            'frenteActual:ID_FRENTE,NOMBRE_FRENTE',
            'tipo:id,nombre',
            'documentacion:ID_EQUIPO,PLACA,LINK_DOC_PROPIEDAD,LINK_POLIZA_SEGURO,LINK_RACDA,LINK_ROTC',
            'equiposAnclados:ID_EQUIPO,id_tipo_equipo,ID_FRENTE_ACTUAL,MARCA,MODELO,SERIAL_CHASIS,SERIAL_DE_MOTOR,ANIO,ESTADO_OPERATIVO,CATEGORIA_FLOTA,ID_ANCLAJE',
            'equiposAnclados.tipo:id,nombre',
            'equiposAnclados.documentacion:ID_EQUIPO,PLACA,LINK_DOC_PROPIEDAD,LINK_POLIZA_SEGURO,LINK_RACDA,LINK_ROTC',
            'equiposAnclados.frenteActual:ID_FRENTE,NOMBRE_FRENTE',
            'ancladoA:ID_EQUIPO,ID_FRENTE_ACTUAL',
            'ancladoA.frenteActual:ID_FRENTE,NOMBRE_FRENTE',
        ]);
        $equiposList = $equipos->get();

        // Determinar nombre del frente para el encabezado
        $nombreFrente = 'TODOS LOS FRENTES';
        if ($request->filled('id_frente') && $request->id_frente === 'none') {
            $nombreFrente = 'SIN ASIGNAR';
        } elseif ($request->filled('id_frente') && $request->id_frente !== 'all') {
            $frente = FrenteTrabajo::find($request->id_frente);
            if ($frente) $nombreFrente = mb_strtoupper($frente->NOMBRE_FRENTE);
        }

        $currentDate = date('d/m/Y');

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Equipos');

        // Document properties
        $spreadsheet->getProperties()
            ->setCreator('Sistema de Gestión de Equipos Operacionales')
            ->setLastModifiedBy('Sistema de Gestión de Equipos Operacionales')
            ->setTitle('Listado de Maquinarias y Equipos')
            ->setSubject('Exportación - Sistema de Gestión de Equipos Operacionales')
            ->setDescription('Generado automáticamente por el Sistema de Gestión de Equipos Operacionales - C.VIDALSA 27, C.A.')
            ->setCompany('Constructora Vidalsa 27, C.A.');

        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        // Logo
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
                $drawing->setHeight(135); // LOGO GIGANTE Y CENTRADO
                $drawing->setWorksheet($sheet);
            } catch (\Exception $e) {
                // Silently ignore if image failed
            }
        }

        // Fila 1 a 3 - Título Empresa
        $sheet->mergeCells('A1:B3');
        $sheet->getStyle('A1:B3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF'); // Fondo Blanco Puro

        $sheet->mergeCells('C1:E3'); // EXTENDIDO HASTA LA 'E' PARA MÁS ANCHURA (C + D + E)
        if ($nombreFrente !== 'TODOS LOS FRENTES') {
            $subTitle = 'PROYECTO: "' . mb_strtoupper($nombreFrente) . '"';
        } else {
            $subTitle = 'COPIA DE BASE DE DATOS DEL SISTEMA DE GESTION DE EQUIPOS OPERACIONALES';
        }
        $titleText = "LISTADO DE MAQUINARIAS Y EQUIPOS\n" . $subTitle;
        $sheet->setCellValue('C1', $titleText);
        $sheet->getStyle('C1')->getAlignment()->setWrapText(true);
        $sheet->getStyle('C1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('C1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('C1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $sheet->getStyle('C1:E3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF'); // Blanco

        $showFrenteCol = ($nombreFrente === 'TODOS LOS FRENTES');
        // +4 columnas de documentación (Título de propiedad / Póliza / Reg. RACDA / Reg. ROTC = SÍ/NO):
        // con FRENTE → A..O ; sin FRENTE → A..N
        $lastCol = $showFrenteCol ? 'O' : 'N';

        $sheet->mergeCells('F1:'.$lastCol.'1');
        $sheet->setCellValue('F1', 'EDICION: 1');
        $sheet->getStyle('F1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('F1')->getFont()->setBold(true)->setSize(11)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $sheet->getStyle('F1:'.$lastCol.'1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        $sheet->mergeCells('F2:'.$lastCol.'2');
        $sheet->setCellValue('F2', 'REVISION: 0');
        $sheet->getStyle('F2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F2')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('F2')->getFont()->setBold(true)->setSize(11)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $sheet->getStyle('F2:'.$lastCol.'2')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        $sheet->mergeCells('F3:'.$lastCol.'3');
        $sheet->setCellValue('F3', 'FECHA: ' . $currentDate);
        $sheet->getStyle('F3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F3')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('F3')->getFont()->setBold(true)->setSize(11)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $sheet->getStyle('F3:'.$lastCol.'3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        $sheet->getRowDimension(1)->setRowHeight(40);
        $sheet->getRowDimension(2)->setRowHeight(40);
        $sheet->getRowDimension(3)->setRowHeight(40);

        // Fila 4 - Texto Exportado por
        $sheet->mergeCells('A4:'.$lastCol.'4');
        $sheet->setCellValue('A4', 'Exportado por: Sistema de Gestión de Equipos Operacionales');
        $sheet->getStyle('A4:'.$lastCol.'4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A4:'.$lastCol.'4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('A4:'.$lastCol.'4')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A4:'.$lastCol.'4')->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF333333');
        $sheet->getRowDimension(4)->setRowHeight(20);

        // Bordes a toda la cuadricula de encabezado
        $headerBorders = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'],
                ],
            ],
        ];
        $sheet->getStyle('A1:'.$lastCol.'4')->applyFromArray($headerBorders);

        // Fila 5 - Encabezados de tabla (las 4 últimas: documentación cargada SÍ/NO)
        // "TÍTULO DE PROPIEDAD" se parte en 2 líneas (la fila 5 tiene wrap text).
        $docHeaders = ["TÍTULO DE\nPROPIEDAD", 'PÓLIZA', 'RACDA', 'ROTC'];
        if ($showFrenteCol) {
            $headers = array_merge(['N°', 'FRENTE', 'TIPO', 'MARCA', 'MODELO', 'CATEGORÍA DE FLOTA', 'SERIAL DE CHASIS', 'SERIAL DE MOTOR', 'PLACA', 'AÑO', 'ESTADO'], $docHeaders);
            $colMap  = ['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O'];
        } else {
            $headers = array_merge(['N°', 'TIPO', 'MARCA', 'MODELO', 'CATEGORÍA DE FLOTA', 'SERIAL DE CHASIS', 'SERIAL DE MOTOR', 'PLACA', 'AÑO', 'ESTADO'], $docHeaders);
            $colMap  = ['A','B','C','D','E','F','G','H','I','J','K','L','M','N'];
        }

        foreach($headers as $index => $hdr) {
            $sheet->setCellValue($colMap[$index] . '5', $hdr);
        }
        $sheet->getStyle('A5:'.$lastCol.'5')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A5:'.$lastCol.'5')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A5:'.$lastCol.'5')->getAlignment()->setWrapText(true); // encabezados largos en 2 líneas
        $sheet->getStyle('A5:'.$lastCol.'5')->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A5:'.$lastCol.'5')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF1B365D');
        $sheet->getRowDimension(5)->setRowHeight(40);

        // Anchos de columna dinámicos
        if ($showFrenteCol) {
            $sheet->getColumnDimension('A')->setWidth(8);
            $sheet->getColumnDimension('B')->setWidth(25);
            $sheet->getColumnDimension('C')->setWidth(30);
            $sheet->getColumnDimension('D')->setWidth(18);
            $sheet->getColumnDimension('E')->setWidth(22);
            $sheet->getColumnDimension('F')->setWidth(22);
            $sheet->getColumnDimension('G')->setWidth(28);
            $sheet->getColumnDimension('H')->setWidth(28);
            $sheet->getColumnDimension('I')->setWidth(18);
            $sheet->getColumnDimension('J')->setWidth(10);
            $sheet->getColumnDimension('K')->setWidth(20);
            $sheet->getColumnDimension('L')->setWidth(13); // Título de propiedad (SÍ/NO; encabezado en 2 líneas)
            $sheet->getColumnDimension('M')->setWidth(12); // Póliza (SÍ/NO)
            $sheet->getColumnDimension('N')->setWidth(11); // RACDA (SÍ/NO)
            $sheet->getColumnDimension('O')->setWidth(11); // ROTC (SÍ/NO)
        } else {
            $sheet->getColumnDimension('A')->setWidth(8);
            $sheet->getColumnDimension('B')->setWidth(32);
            $sheet->getColumnDimension('C')->setWidth(18);
            $sheet->getColumnDimension('D')->setWidth(22);
            $sheet->getColumnDimension('E')->setWidth(22);
            $sheet->getColumnDimension('F')->setWidth(28);
            $sheet->getColumnDimension('G')->setWidth(28);
            $sheet->getColumnDimension('H')->setWidth(18);
            $sheet->getColumnDimension('I')->setWidth(10);
            $sheet->getColumnDimension('J')->setWidth(20);
            $sheet->getColumnDimension('K')->setWidth(13); // Título de propiedad (SÍ/NO; encabezado en 2 líneas)
            $sheet->getColumnDimension('L')->setWidth(12); // Póliza (SÍ/NO)
            $sheet->getColumnDimension('M')->setWidth(11); // RACDA (SÍ/NO)
            $sheet->getColumnDimension('N')->setWidth(11); // ROTC (SÍ/NO)
        }

        $printedIds = [];
        $rowNum = 6;
        $counter = 1;

        $printEquipoRow = function($equipo, $isAnclado = false) use (&$sheet, &$rowNum, &$counter, &$printedIds, $showFrenteCol, $colMap, $lastCol, &$printEquipoRow) {
            if (isset($printedIds[$equipo->ID_EQUIPO])) {
                return;
            }
            $printedIds[$equipo->ID_EQUIPO] = true;

            $frenteVal = 'S/A';
            if ($equipo->frenteActual) {
                $frenteVal = mb_strtoupper($equipo->frenteActual->NOMBRE_FRENTE);
            } elseif ($equipo->ancladoA && $equipo->ancladoA->frenteActual) {
                $frenteVal = mb_strtoupper($equipo->ancladoA->frenteActual->NOMBRE_FRENTE);
            }

            $tipoVal = $equipo->tipo ? mb_strtoupper($equipo->tipo->nombre) : '—';
            if ($isAnclado) {
                $tipoVal = "  ↳ " . $tipoVal;
            }

            $marcaVal = mb_strtoupper($equipo->MARCA ?? '—');
            $modeloVal = mb_strtoupper($equipo->MODELO ?? '—');
            $categoriaVal = mb_strtoupper($equipo->CATEGORIA_FLOTA ?? '—');
            
            $chasis = trim($equipo->SERIAL_CHASIS ?? '');
            $chasisVal = $chasis !== '' ? mb_strtoupper($chasis) : '—';
            
            $motor = trim($equipo->SERIAL_DE_MOTOR ?? '');
            $motorVal = $motor !== '' ? mb_strtoupper($motor) : '—';

            $placa = $equipo->documentacion ? trim($equipo->documentacion->PLACA ?? '') : '';
            $placaVal = $placa !== '' ? mb_strtoupper($placa) : '—';

            $anioVal = mb_strtoupper($equipo->ANIO ?? '—');
            $estadoVal = mb_strtoupper($equipo->ESTADO_OPERATIVO ?? '—');

            $numeroItem = str_pad($counter, 2, '0', STR_PAD_LEFT);

            $sheet->setCellValue('A'.$rowNum, $numeroItem);
            
            if ($showFrenteCol) {
                $sheet->setCellValue('B'.$rowNum, $frenteVal);
                $sheet->setCellValue('C'.$rowNum, $tipoVal);
                $sheet->setCellValue('D'.$rowNum, $marcaVal);
                $sheet->setCellValue('E'.$rowNum, $modeloVal);
                $sheet->setCellValue('F'.$rowNum, $categoriaVal);
                $sheet->setCellValue('G'.$rowNum, $chasisVal);
                $sheet->setCellValue('H'.$rowNum, $motorVal);
                $sheet->setCellValue('I'.$rowNum, $placaVal);
                $sheet->setCellValue('J'.$rowNum, $anioVal);
                $sheet->setCellValue('K'.$rowNum, $estadoVal);

                $sheet->getStyle('B'.$rowNum)->getAlignment()->setWrapText(true);
            } else {
                $sheet->setCellValue('B'.$rowNum, $tipoVal);
                $sheet->setCellValue('C'.$rowNum, $marcaVal);
                $sheet->setCellValue('D'.$rowNum, $modeloVal);
                $sheet->setCellValue('E'.$rowNum, $categoriaVal);
                $sheet->setCellValue('F'.$rowNum, $chasisVal);
                $sheet->setCellValue('G'.$rowNum, $motorVal);
                $sheet->setCellValue('H'.$rowNum, $placaVal);
                $sheet->setCellValue('I'.$rowNum, $anioVal);
                $sheet->setCellValue('J'.$rowNum, $estadoVal);
            }

            // Documentación cargada → SÍ / NO (las 4 últimas columnas, según haya o no link guardado)
            $docSiNo = fn ($link) => (trim((string) $link) !== '') ? 'SÍ' : 'NO';
            $doc     = $equipo->documentacion;
            $docCols = $showFrenteCol ? ['L', 'M', 'N', 'O'] : ['K', 'L', 'M', 'N'];
            $sheet->setCellValue($docCols[0].$rowNum, $doc ? $docSiNo($doc->LINK_DOC_PROPIEDAD) : 'NO'); // Título de propiedad
            $sheet->setCellValue($docCols[1].$rowNum, $doc ? $docSiNo($doc->LINK_POLIZA_SEGURO) : 'NO'); // Póliza de seguro
            $sheet->setCellValue($docCols[2].$rowNum, $doc ? $docSiNo($doc->LINK_RACDA)         : 'NO'); // Registro RACDA
            $sheet->setCellValue($docCols[3].$rowNum, $doc ? $docSiNo($doc->LINK_ROTC)          : 'NO'); // Registro ROTC
            foreach ($docCols as $dc) {
                $sheet->getStyle($dc.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            }

            // Alternancia de colores en las filas (Zebra Striping)
            if ($counter % 2 === 0) {
                $sheet->getStyle('A'.$rowNum.':'.$lastCol.$rowNum)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
            } else {
                $sheet->getStyle('A'.$rowNum.':'.$lastCol.$rowNum)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
            }

            if ($isAnclado) {
                // Formatting for anchored items (like a subtle italic for type)
                if ($showFrenteCol) {
                    $sheet->getStyle('C'.$rowNum)->getFont()->setItalic(true)->getColor()->setARGB('FF475569');
                } else {
                    $sheet->getStyle('B'.$rowNum)->getFont()->setItalic(true)->getColor()->setARGB('FF475569');
                }
            }

            // WrapText
            foreach ($colMap as $col) {
                $sheet->getStyle($col.$rowNum)->getAlignment()->setWrapText(true);
            }

            $sheet->getStyle('A'.$rowNum.':'.$lastCol.$rowNum)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $sheet->getStyle('A'.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            
            // Centrados
            if ($showFrenteCol) {
                $sheet->getStyle('B'.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('D'.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('F'.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('G'.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('H'.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('I'.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            } else {
                $sheet->getStyle('C'.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('E'.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('F'.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('G'.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('H'.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            }

            // AÑO y ESTADO: siempre centrados (independiente del layout con/sin FRENTE)
            $colAnio   = $showFrenteCol ? 'J' : 'I';
            $colEstado = $showFrenteCol ? 'K' : 'J';
            $sheet->getStyle($colAnio.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle($colEstado.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            // Altura fija
            $sheet->getRowDimension($rowNum)->setRowHeight(30);

            $rowNum++;
            $counter++;

            // Anclados recursivos (solamente imprimimos debajo del padre si NO somos ya un anclado)
            if (!$isAnclado && isset($equipo->equiposAnclados) && $equipo->equiposAnclados->count() > 0) {
                foreach($equipo->equiposAnclados as $anclado) {
                    $printEquipoRow($anclado, true);
                }
            }
        };

        foreach($equiposList as $equipo) {
            $printEquipoRow($equipo, false);
        }

        // Fila Total
        $sheet->setCellValue('A'.$rowNum, 'TOTAL');
        $sheet->mergeCells('B'.$rowNum.':C'.$rowNum);
        $sheet->setCellValue('B'.$rowNum, ($counter - 1) . " EQUIPOS LISTADOS");
        $sheet->mergeCells('D'.$rowNum.':'.$lastCol.$rowNum);

        $sheet->getStyle('A'.$rowNum.':'.$lastCol.$rowNum)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A'.$rowNum.':B'.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A'.$rowNum.':'.$lastCol.$rowNum)->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FF1E293B');
        $sheet->getStyle('A'.$rowNum.':'.$lastCol.$rowNum)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');
        $sheet->getRowDimension($rowNum)->setRowHeight(28);

        // Bordes a toda la tabla de datos
        $sheet->getStyle('A5:'.$lastCol.$rowNum)->applyFromArray($headerBorders);

        // Limpiar TODOS los buffers de salida activos de forma segura.
        // ob_end_clean() simple puede fallar en php-fpm de producción (nginx) si hay
        // más de un nivel de buffer activo, corrompiendo los headers HTTP del archivo.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Escribir en archivo temporal y devolver como descarga
        $writer   = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $tempFile = tempnam(sys_get_temp_dir(), 'export_cvidalsa_');
        $writer->save($tempFile);

        return response()->download($tempFile, $fileName, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ])->deleteFileAfterSend(true);
    }


    public function searchSpecs(Request $request)
    {
        $query = $request->input('query');
        if (!$query)
            return response()->json([]);

        $results = CaracteristicaModelo::select('ID_ESPEC', 'MODELO')
            ->where('MODELO', 'LIKE', "%{$query}%")
            ->orderBy('MODELO', 'asc')
            ->limit(20)
            ->get();

        return response()->json($results);
    }

    public function searchField(Request $request)
    {
        $field = $request->input('field');
        $query = $request->input('query');

        if (!$field || !$query) {
            return response()->json([]);
        }

        // Map frontend field names to database columns
        $fieldMap = [
            'MARCA' => 'MARCA',
            'MODELO' => 'MODELO'
        ];

        if (!isset($fieldMap[$field])) {
            return response()->json([]);
        }

        $column = $fieldMap[$field];

        $results = Equipo::select($column)
            ->distinct()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->where($column, 'LIKE', "%{$query}%")
            ->orderBy($column, 'asc')
            ->limit(15)
            ->pluck($column);

        return response()->json($results);
    }

    public function create()
    {
        // Cache dropdown lists for 1 hour to avoid repeated DB queries
        $frentes = \Illuminate\Support\Facades\Cache::remember('frentes_activos_form', 3600, function () {
            return FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
                ->orderBy('NOMBRE_FRENTE', 'asc')
                ->pluck('NOMBRE_FRENTE', 'ID_FRENTE');
        });

        $seguros = \Illuminate\Support\Facades\Cache::remember('seguros_list_form', 3600, function () {
            return CatalogoSeguro::orderBy('NOMBRE_ASEGURADORA', 'asc')
                ->pluck('NOMBRE_ASEGURADORA');
        });

        $tipos_equipo = \Illuminate\Support\Facades\Cache::remember('tipos_equipo_list_form', 3600, function () {
            return TipoEquipo::orderBy('nombre', 'asc')
                ->pluck('nombre');
        });

        // Performance Optimization: Don't pre-load models list
        // Models will be loaded dynamically via AJAX autocomplete (same as years)
        // This eliminates DOM bloat when there are thousands of models
        $modelosList = [];

        $aniosList = \Illuminate\Support\Facades\Cache::remember('anios_list_form_v3', 60, function () {
            return Equipo::distinct()->whereNotNull('ANIO')->orderBy('ANIO', 'desc')->pluck('ANIO');
        });

        $marcas = \Illuminate\Support\Facades\Cache::remember('marcas_list_form_v3', 60, function () {
            return Equipo::distinct()->whereNotNull('MARCA')->orderBy('MARCA', 'asc')->limit(1000)->pluck('MARCA');
        });

        $modelos = \Illuminate\Support\Facades\Cache::remember('modelos_list_form_v3', 60, function () {
            return Equipo::distinct()->whereNotNull('MODELO')->orderBy('MODELO', 'asc')->limit(1000)->pluck('MODELO');
        });

        $categorias = ['FLOTA LIVIANA', 'FLOTA PESADA'];

        $equipo = new Equipo(); // Empty instance for form partial
        return view('admin.equipos.create', compact('frentes', 'seguros', 'tipos_equipo', 'marcas', 'modelos', 'categorias', 'equipo', 'modelosList', 'aniosList'));
    }

    public function store(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        // Normalize inputs to uppercase before validation to avoid case-sensitivity issues with unique constraints
        $request->merge([
            'CODIGO_PATIO' => (trim($request->CODIGO_PATIO ?? '') === '') ? null : strtoupper($request->CODIGO_PATIO),
            'SERIAL_CHASIS' => strtoupper($request->SERIAL_CHASIS),
            'SERIAL_DE_MOTOR' => (trim($request->SERIAL_DE_MOTOR ?? '') === '') ? null : strtoupper(trim($request->SERIAL_DE_MOTOR)),
            'DETALLE_UBICACION_ACTUAL' => (trim($request->DETALLE_UBICACION_ACTUAL ?? '') === '') ? null : strtoupper(trim($request->DETALLE_UBICACION_ACTUAL)),
        ]);

        if ($request->has('documentacion.PLACA')) {
            $doc = $request->documentacion;
            $placa = trim($doc['PLACA'] ?? '');
            $doc['PLACA'] = ($placa === '') ? null : strtoupper($placa);
            $request->merge(['documentacion' => $doc]);
        }




        try {
            $validated = $request->validate([
                'CODIGO_PATIO' => 'nullable|unique:equipos,CODIGO_PATIO',
                'TIPO_EQUIPO' => 'required|max:35',
                'CATEGORIA_FLOTA' => 'required|in:FLOTA LIVIANA,FLOTA PESADA',
                'MARCA' => 'required',
                'MODELO' => 'required',
                'ANIO' => 'required|integer',
                'SERIAL_CHASIS' => 'required|unique:equipos,SERIAL_CHASIS',
                'SERIAL_DE_MOTOR' => 'nullable|unique:equipos,SERIAL_DE_MOTOR',
                'documentacion.PLACA' => 'nullable|unique:documentacion,PLACA',
                'ESTADO_OPERATIVO' => 'required',
                'ID_ESPEC' => 'nullable|exists:caracteristicas_modelo,ID_ESPEC', // Security: Validate catalog link exists
                'doc_propiedad' => 'nullable|file|mimes:pdf|max:5120|required_with:documentacion.NRO_DE_DOCUMENTO',
                'documentacion.NRO_DE_DOCUMENTO' => 'nullable|required_with:doc_propiedad',
                'poliza_seguro' => 'nullable|file|mimes:pdf|max:5120|required_with:documentacion.FECHA_VENC_POLIZA',
                'documentacion.FECHA_VENC_POLIZA' => 'nullable|required_with:poliza_seguro',
                'doc_rotc' => 'nullable|file|mimes:pdf|max:5120|required_with:documentacion.FECHA_ROTC',
                'documentacion.FECHA_ROTC' => 'nullable|required_with:doc_rotc',
                'doc_racda' => 'nullable|file|mimes:pdf|max:5120|required_with:documentacion.FECHA_RACDA',
                'documentacion.FECHA_RACDA' => 'nullable|required_with:doc_racda',
                'foto_equipo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
                'foto_referencial' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            ], $this->validationMessages(), $this->validationAttributes());
        } catch (\Illuminate\Validation\ValidationException $e) {

            throw $e;
        }

        // PERFORMANCE & ROBUSTNESS: Process files BEFORE opening DB transaction
        // This prevents DB locks while waiting for slow disk I/O operations
        $filesToProcess = [];

        // Handle catalog reference photo if linked
        if ($request->filled('ID_ESPEC') && $request->hasFile('foto_referencial')) {
            $file = $request->file('foto_referencial');
            $filename = 'catalog_ref_' . time() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('temp_staging', $filename, 'local');
            $filesToProcess[] = [
                'type' => 'foto_referencial',
                'path' => $path,
                'mime' => $file->getMimeType(),
                'originalName' => $filename
            ];
        }

        // Handle equipment photo
        if ($request->hasFile('foto_equipo')) {
            $file = $request->file('foto_equipo');
            $filename = 'foto_unidad_' . time() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('temp_staging', $filename, 'local');
            $filesToProcess[] = [
                'type' => 'foto_equipo',
                'path' => $path,
                'mime' => $file->getMimeType(),
                'originalName' => $filename
            ];
        }

        // Handle document files
        $docFields = [
            'doc_propiedad' => 'propiedad',
            'poliza_seguro' => 'poliza',
            'doc_rotc' => 'rotc',
            'doc_racda' => 'racda'
        ];

        foreach ($docFields as $inputName => $docType) {
            if ($request->hasFile($inputName)) {
                $file = $request->file($inputName);
                $filename = $docType . '_' . time() . '.pdf';
                $path = $file->storeAs('temp_staging', $filename, 'local');
                $filesToProcess[] = [
                    'type' => $inputName,
                    'path' => $path,
                    'mime' => 'application/pdf',
                    'originalName' => $filename
                ];
            }
        }

        // NOW start DB transaction
        DB::transaction(function () use ($request, $filesToProcess) {
            $tipoName = strtoupper($request->input('TIPO_EQUIPO'));
            $tipo = TipoEquipo::firstOrCreate(['nombre' => $tipoName]);
            $data = $request->except(['specs', 'responsable', 'documentacion', 'TIPO_EQUIPO', 'doc_propiedad', 'poliza_seguro', 'doc_rotc', 'doc_racda', 'foto_equipo', 'foto_referencial']);
            $data['id_tipo_equipo'] = $tipo->id;
            $data['TIPO_EQUIPO'] = $tipoName;
            $data['CODIGO_PATIO'] = (trim($data['CODIGO_PATIO'] ?? '') === '') ? null : strtoupper($data['CODIGO_PATIO']);
            $data['MARCA'] = strtoupper($data['MARCA'] ?? '');
            $data['MODELO'] = strtoupper($data['MODELO'] ?? '');
            $data['SERIAL_CHASIS'] = strtoupper($data['SERIAL_CHASIS'] ?? '');
            $data['SERIAL_DE_MOTOR'] = (trim($data['SERIAL_DE_MOTOR'] ?? '') === '') ? null : strtoupper(trim($data['SERIAL_DE_MOTOR']));
            
            $data['CREADO_POR'] = auth()->id();

            $equipo = Equipo::create($data);

            // Link to catalog if specified (validation already done)
            if ($request->filled('ID_ESPEC')) {
                $equipo->ID_ESPEC = $request->input('ID_ESPEC');
                $equipo->save();
            }

            // --- DOCUMENTATION & PHOTOS UPLOAD (SYNCHRONOUS DIRECT TO DRIVE) ---
            $driveService = \App\Services\GoogleDriveService::getInstance();
            $folderId = $driveService->getRootFolderId();
            $docDataUpdates = []; // FIX: Initialize variable to avoid 500 Error if no files are uploaded

            if (count($filesToProcess) > 0) {
                // Folders Configuration (Same as Job)
                $folders = [
                    'foto_equipo' => '1Pmm9WI6YSi6Wb6-2_L0D5wk5whHs-mCf',
                    'foto_referencial' => '1KWEYWqnPjmJxz1XpR8U-Jto8KQT9RSsy',
                    'default' => $folderId
                ];

                foreach ($filesToProcess as $fileData) {
                    try {
                        $type = $fileData['type'];
                        $localPath = $fileData['path'];

                        if (!Storage::disk('local')->exists($localPath)) {
                            Log::warning("Store Upload: File missing from LOCAL storage: {$localPath}");
                            continue;
                        }
                        $fullLocalPath = Storage::disk('local')->path($localPath);
                        $targetFolderId = $folders[$type] ?? $folders['default'];

                        // Prepare Upload Object
                        $fileObject = new \Illuminate\Http\File($fullLocalPath);

                        // Upload to Drive
                        $driveFile = $driveService->uploadFile(
                            $targetFolderId,
                            $fileObject,
                            $fileData['originalName'],
                            $fileData['mime']
                        );

                        if ($driveFile && isset($driveFile->id)) {
                            // Cache Busting: Add version timestamp to URL
                            $timestamp = time();
                            $publicUrl = '/storage/google/' . $driveFile->id . '?v=' . $timestamp;

                            // Apply updates based on type
                            if ($type === 'foto_equipo') {
                                $equipo->update(['FOTO_EQUIPO' => $publicUrl]);
                            } elseif ($type === 'foto_referencial' && $equipo->ID_ESPEC) {
                                $espec = CaracteristicaModelo::find($equipo->ID_ESPEC);
                                if ($espec)
                                    $espec->update(['FOTO_REFERENCIAL' => $publicUrl]);
                            } elseif (in_array($type, ['doc_propiedad', 'poliza_seguro', 'doc_rotc', 'doc_racda'])) {
                                $colMap = [
                                    'doc_propiedad' => 'LINK_DOC_PROPIEDAD',
                                    'poliza_seguro' => 'LINK_POLIZA_SEGURO',
                                    'doc_rotc' => 'LINK_ROTC',
                                    'doc_racda' => 'LINK_RACDA'
                                ];
                                if (isset($colMap[$type])) {
                                    $docDataUpdates[$colMap[$type]] = $publicUrl;
                                }
                            }
                        }

                        // Cleanup Local
                        Storage::disk('local')->delete($localPath);

                    } catch (\Exception $e) {
                        Log::error("Store Upload Error ({$type}): " . $e->getMessage());
                        // Rethrow exception to trigger DB Loopback. 
                        // We want "All or Nothing": If file fails, don't create the Equipment.
                        throw new \Exception("Error subiendo el archivo {$type}: " . $e->getMessage());
                    }
                }

                // Save accumulated doc link updates
                if (!empty($docDataUpdates)) {
                    // We handle this below along with documentacion input data
                }
            }

            // Documentación Record
            if ($request->has('documentacion') || !empty($docDataUpdates)) {
                $reqDoc = $request->input('documentacion', []);
                $reqDoc['ID_EQUIPO'] = $equipo->ID_EQUIPO;
                $reqDoc['PLACA'] = strtoupper($reqDoc['PLACA'] ?? '');
                $reqDoc['NOMBRE_DEL_TITULAR'] = strtoupper($reqDoc['NOMBRE_DEL_TITULAR'] ?? '');
                $reqDoc['NRO_DE_DOCUMENTO'] = strtoupper($reqDoc['NRO_DE_DOCUMENTO'] ?? '');

                if (!empty($reqDoc['NOMBRE_SEGURO'])) {
                    $seguro = CatalogoSeguro::firstOrCreate(['NOMBRE_ASEGURADORA' => strtoupper($reqDoc['NOMBRE_SEGURO'])]);
                    $reqDoc['ID_SEGURO'] = $seguro->ID_SEGURO;
                }
                unset($reqDoc['NOMBRE_SEGURO']);

                // FIX: Remove ESTADO_POLIZA if present (calculated field, not in DB)
                if (isset($reqDoc['ESTADO_POLIZA'])) {
                    unset($reqDoc['ESTADO_POLIZA']);
                }

                // Merge Uploaded Links
                $reqDoc = array_merge($reqDoc, $docDataUpdates);

                $reqDoc = array_filter($reqDoc, function ($value) {
                    return !is_null($value) && $value !== '';
                });
                Documentacion::create($reqDoc);
            }

            // Responsables Record
            if ($request->has('responsable')) {
                $reqResp = $request->input('responsable');
                if (!empty($reqResp['NOMBRE_RESPONSABLE'])) {
                    $reqResp['ID_EQUIPO'] = $equipo->ID_EQUIPO;
                    $reqResp['FECHA_ASIGNACION'] = now();
                    Responsable::create($reqResp);
                }
            }
        });

        // Invalidar caché del índice y del formulario al crear un equipo nuevo
        // (clave marcas/modelos del formulario = _v3; del índice = _dropdown)
        foreach ([
            'equipos_modelos_list',    // autocomplete del catálogo
            'equipos_modelos_dropdown', // filtro del índice
            'equipos_marcas_dropdown',
            'equipos_anios_dropdown',
            'marcas_list_form_v3',     // formulario create/edit
            'modelos_list_form_v3',
            'anios_list_form_v3',
        ] as $key) {
            \Illuminate\Support\Facades\Cache::forget($key);
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Equipo registrado correctamente.', 'redirect' => route('equipos.index')]);
        }

        return redirect()->route('equipos.index')->with('success', 'Equipo registrado correctamente.');
    }

    public function show($id)
    {
        $equipo = $this->findAndAuthorizeEquipo($id, ['frenteActual', 'especificaciones', 'documentacion.seguro', 'responsables']);
        return view('admin.equipos.show', compact('equipo'));
    }

    public function edit($id)
    {
        $equipo = $this->findAndAuthorizeEquipo($id, ['frenteActual', 'especificaciones', 'documentacion', 'responsables', 'tipo']);

        // Reutilizar las mismas claves de caché que create() para evitar duplicidad de datos
        $frentes = \Illuminate\Support\Facades\Cache::remember('frentes_activos_form', 3600, function () {
            return FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
                ->orderBy('NOMBRE_FRENTE', 'asc')
                ->pluck('NOMBRE_FRENTE', 'ID_FRENTE');
        });
        $seguros = \Illuminate\Support\Facades\Cache::remember('seguros_list_form', 3600, function () {
            return CatalogoSeguro::orderBy('NOMBRE_ASEGURADORA', 'asc')->pluck('NOMBRE_ASEGURADORA');
        });
        $tipos_equipo = \Illuminate\Support\Facades\Cache::remember('tipos_equipo_list_form', 3600, function () {
            return TipoEquipo::orderBy('nombre', 'asc')->pluck('nombre');
        });
        $marcas = \Illuminate\Support\Facades\Cache::remember('marcas_list_form_v3', 60, function () {
            return Equipo::distinct()->whereNotNull('MARCA')->orderBy('MARCA', 'asc')->limit(1000)->pluck('MARCA');
        });
        $modelos = \Illuminate\Support\Facades\Cache::remember('modelos_list_form_v3', 60, function () {
            return Equipo::distinct()->whereNotNull('MODELO')->orderBy('MODELO', 'asc')->limit(1000)->pluck('MODELO');
        });
        // Igual que create(): aniosList precargado para el dropdown; modelosList queda
        // vacio porque el partial carga modelos via AJAX para evitar DOM bloat.
        $aniosList = \Illuminate\Support\Facades\Cache::remember('anios_list_form_v3', 60, function () {
            return Equipo::distinct()->whereNotNull('ANIO')->orderBy('ANIO', 'desc')->pluck('ANIO');
        });
        $modelosList = [];

        $categorias = ['FLOTA LIVIANA', 'FLOTA PESADA'];
        return view('admin.equipos.edit', compact('equipo', 'frentes', 'seguros', 'categorias', 'tipos_equipo', 'marcas', 'modelos', 'aniosList', 'modelosList'));
    }

    public function update(Request $request, $id)
    {
        set_time_limit(300);
        $equipo = $this->findAndAuthorizeEquipo($id);

        // Normalize inputs to uppercase before validation to avoid case-sensitivity issues with unique constraints
        $request->merge([
            'CODIGO_PATIO' => (trim($request->CODIGO_PATIO ?? '') === '') ? null : strtoupper(trim($request->CODIGO_PATIO)),
            'SERIAL_CHASIS' => strtoupper($request->SERIAL_CHASIS),
            'SERIAL_DE_MOTOR' => (trim($request->SERIAL_DE_MOTOR ?? '') === '') ? null : strtoupper(trim($request->SERIAL_DE_MOTOR)),
            'DETALLE_UBICACION_ACTUAL' => (trim($request->DETALLE_UBICACION_ACTUAL ?? '') === '') ? null : strtoupper(trim($request->DETALLE_UBICACION_ACTUAL)),
        ]);

        if ($request->has('documentacion.PLACA')) {
            $doc = $request->documentacion;
            $placa = trim($doc['PLACA'] ?? '');
            $doc['PLACA'] = ($placa === '') ? null : strtoupper($placa);
            $request->merge(['documentacion' => $doc]);
        }

        $validated = $request->validate([
            'CODIGO_PATIO' => 'nullable|unique:equipos,CODIGO_PATIO,' . $id . ',ID_EQUIPO',
            'TIPO_EQUIPO' => 'required|max:35',
            'CATEGORIA_FLOTA' => 'required|in:FLOTA LIVIANA,FLOTA PESADA',
            'MARCA' => 'required',
            'MODELO' => 'required',
            'ANIO' => 'required|integer',
            // ID_ESPEC se gestiona desde el widget del catálogo, no desde
            // el formulario de edición general. Se acepta cualquier valor
            // (o null) sin validar existencia para evitar errores con vínculos huérfanos.
            'ID_ESPEC' => 'nullable',
            'SERIAL_CHASIS' => 'required|unique:equipos,SERIAL_CHASIS,' . $id . ',ID_EQUIPO',
            'SERIAL_DE_MOTOR' => 'nullable|unique:equipos,SERIAL_DE_MOTOR,' . $id . ',ID_EQUIPO',
            'documentacion.PLACA' => 'nullable|unique:documentacion,PLACA,' . ($equipo->documentacion ? $equipo->documentacion->ID_EQUIPO : 'NULL') . ',ID_EQUIPO',
            'ESTADO_OPERATIVO' => 'required',
            'foto_equipo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'foto_referencial' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'doc_propiedad' => 'nullable|file|mimes:pdf|max:5120',
            'poliza_seguro' => 'nullable|file|mimes:pdf|max:5120',
            'doc_rotc' => 'nullable|file|mimes:pdf|max:5120',
            'doc_racda' => 'nullable|file|mimes:pdf|max:5120',
        ], $this->validationMessages(), $this->validationAttributes());

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), []);
        // Custom validation for update: Check if file exists or is being uploaded if meta is present
        $validator->after(function ($validator) use ($request, $equipo) {
            // Propiedad
            if ($request->filled('documentacion.NRO_DE_DOCUMENTO')) {
                $hasFile = $request->hasFile('doc_propiedad');
                $hasExisting = $equipo->documentacion && $equipo->documentacion->LINK_DOC_PROPIEDAD;
                if (!$hasFile && !$hasExisting) {
                    $validator->errors()->add('doc_propiedad', 'El documento de propiedad es obligatorio si se indica el número.');
                }
            }
            if ($request->hasFile('doc_propiedad') && !$request->filled('documentacion.NRO_DE_DOCUMENTO')) {
                if (!($equipo->documentacion && $equipo->documentacion->NRO_DE_DOCUMENTO)) {
                    $validator->errors()->add('documentacion.NRO_DE_DOCUMENTO', 'El número de documento es obligatorio al cargar el archivo.');
                }
            }

            // Poliza
            if ($request->filled('documentacion.FECHA_VENC_POLIZA')) {
                $hasFile = $request->hasFile('poliza_seguro');
                $hasExisting = $equipo->documentacion && $equipo->documentacion->LINK_POLIZA_SEGURO;
                if (!$hasFile && !$hasExisting) {
                    $validator->errors()->add('poliza_seguro', 'La póliza es obligatoria si se indica el vencimiento.');
                }
            }
            if ($request->hasFile('poliza_seguro') && !$request->filled('documentacion.FECHA_VENC_POLIZA')) {
                if (!($equipo->documentacion && $equipo->documentacion->FECHA_VENC_POLIZA)) {
                    $validator->errors()->add('documentacion.FECHA_VENC_POLIZA', 'La fecha de vencimiento es obligatoria al cargar la póliza.');
                }
            }

            // ROTC
            if ($request->filled('documentacion.FECHA_ROTC')) {
                $hasFile = $request->hasFile('doc_rotc');
                $hasExisting = $equipo->documentacion && $equipo->documentacion->LINK_ROTC;
                if (!$hasFile && !$hasExisting) {
                    $validator->errors()->add('doc_rotc', 'El documento ROTC es obligatorio si se indica la fecha.');
                }
            }
            if ($request->hasFile('doc_rotc') && !$request->filled('documentacion.FECHA_ROTC')) {
                if (!($equipo->documentacion && $equipo->documentacion->FECHA_ROTC)) {
                    $validator->errors()->add('documentacion.FECHA_ROTC', 'La fecha ROTC es obligatoria al cargar el archivo.');
                }
            }

            // RACDA
            if ($request->filled('documentacion.FECHA_RACDA')) {
                $hasFile = $request->hasFile('doc_racda');
                $hasExisting = $equipo->documentacion && $equipo->documentacion->LINK_RACDA;
                if (!$hasFile && !$hasExisting) {
                    $validator->errors()->add('doc_racda', 'El documento RACDA es obligatorio si se indica la fecha.');
                }
            }
            if ($request->hasFile('doc_racda') && !$request->filled('documentacion.FECHA_RACDA')) {
                if (!($equipo->documentacion && $equipo->documentacion->FECHA_RACDA)) {
                    $validator->errors()->add('documentacion.FECHA_RACDA', 'La fecha RACDA es obligatoria al cargar el archivo.');
                }
            }
        });
        $validator->validate();

        DB::transaction(function () use ($request, $equipo) {
            $tipoName = strtoupper($request->input('TIPO_EQUIPO'));
            $tipo = TipoEquipo::firstOrCreate(['nombre' => $tipoName]);
            $data = $request->except(['specs', 'responsable', 'documentacion', 'TIPO_EQUIPO', 'doc_propiedad', 'poliza_seguro', 'doc_rotc', 'doc_racda', 'foto_equipo', 'foto_referencial']);
            $data['id_tipo_equipo'] = $tipo->id;
            $data['TIPO_EQUIPO'] = $tipoName;
            $data['CODIGO_PATIO'] = (trim($data['CODIGO_PATIO'] ?? '') === '') ? null : strtoupper($data['CODIGO_PATIO']);
            $data['MARCA'] = strtoupper(trim($data['MARCA'] ?? ''));
            $data['MODELO'] = strtoupper(trim($data['MODELO'] ?? ''));
            $data['SERIAL_CHASIS'] = strtoupper(trim($data['SERIAL_CHASIS'] ?? ''));
            $data['SERIAL_DE_MOTOR'] = (trim($data['SERIAL_DE_MOTOR'] ?? '') === '') ? null : strtoupper(trim($data['SERIAL_DE_MOTOR']));
            $equipo->update($data);

            $driveService = \App\Services\GoogleDriveService::getInstance();
            $folderId = $driveService->getRootFolderId();

            if ($request->filled('ID_ESPEC')) {
                $equipo->ID_ESPEC = $request->input('ID_ESPEC');
                $equipo->save();
                if ($request->hasFile('foto_referencial')) {
                    $espec = CaracteristicaModelo::find($equipo->ID_ESPEC);
                    if ($espec) {
                        $catalogFolderId = config('filesystems.disks.google.catalog_folder'); // Specific folder for model photos
                        $file = $request->file('foto_referencial');
                        $filename = 'catalog_ref_' . time() . '.' . $file->getClientOriginalExtension();
                        $driveFile = $driveService->uploadFile($catalogFolderId, $file, $filename, $file->getMimeType());
                        if ($driveFile && isset($driveFile->id)) {
                            $espec->update(['FOTO_REFERENCIAL' => '/storage/google/' . $driveFile->id]);
                        }
                    }
                }
            }

            if ($request->hasFile('foto_equipo')) {
                $file = $request->file('foto_equipo');
                $photoFolderId = config('filesystems.disks.google.equipment_folder'); // Specific folder for equipment photos
                $driveFile = $driveService->uploadFile($photoFolderId, $file, 'foto_unidad_' . time() . '.' . $file->getClientOriginalExtension(), $file->getMimeType());
                if ($driveFile && isset($driveFile->id)) {
                    $timestamp = time();
                    $equipo->update(['FOTO_EQUIPO' => '/storage/google/' . $driveFile->id . '?v=' . $timestamp]);
                }
            }

            if ($request->has('documentacion')) {
                $docData = $request->input('documentacion');
                if (!empty($docData['NOMBRE_SEGURO'])) {
                    $seguro = CatalogoSeguro::firstOrCreate(['NOMBRE_ASEGURADORA' => strtoupper($docData['NOMBRE_SEGURO'])]);
                    $docData['ID_SEGURO'] = $seguro->ID_SEGURO;
                }
                unset($docData['NOMBRE_SEGURO']);

                // FIX: Remove ESTADO_POLIZA if present
                if (isset($docData['ESTADO_POLIZA'])) {
                    unset($docData['ESTADO_POLIZA']);
                }

                // Normalize PLACA BEFORE array_filter so that an intentionally
                // cleared plate (empty string → null) is preserved and saved.
                // array_key_exists is used instead of isset to detect the key even
                // when its value is null (field was present in the request).
                $placaWasSubmitted = array_key_exists('PLACA', $docData);
                if ($placaWasSubmitted) {
                    $placaVal = trim($docData['PLACA'] ?? '');
                    $docData['PLACA'] = ($placaVal === '') ? null : strtoupper($placaVal);
                }

                if (isset($docData['NOMBRE_DEL_TITULAR']))
                    $docData['NOMBRE_DEL_TITULAR'] = strtoupper($docData['NOMBRE_DEL_TITULAR']);
                if (isset($docData['NRO_DE_DOCUMENTO']))
                    $docData['NRO_DE_DOCUMENTO'] = strtoupper($docData['NRO_DE_DOCUMENTO']);

                // Strip empty/null values from OTHER fields, but keep PLACA
                // even when null so that clearing the field actually saves null.
                $docData = array_filter($docData, function ($value, $key) use ($placaWasSubmitted) {
                    if ($key === 'PLACA' && $placaWasSubmitted) {
                        return true; // Always keep PLACA when it was explicitly submitted
                    }
                    return !is_null($value) && $value !== '';
                }, ARRAY_FILTER_USE_BOTH);

                $docTypes = ['doc_propiedad' => 'LINK_DOC_PROPIEDAD', 'poliza_seguro' => 'LINK_POLIZA_SEGURO', 'doc_rotc' => 'LINK_ROTC', 'doc_racda' => 'LINK_RACDA'];
                foreach ($docTypes as $fileKey => $dbCol) {
                    if ($request->hasFile($fileKey)) {
                        $file = $request->file($fileKey);

                        // Check for old file and delete it (Correctly using DB relation)
                        if ($equipo->documentacion && $equipo->documentacion->$dbCol && str_starts_with($equipo->documentacion->$dbCol, '/storage/google/')) {
                            // Extract file ID (remove query params for cache busting)
                            $oldUrl = $equipo->documentacion->$dbCol;
                            $oldFileId = str_replace('/storage/google/', '', parse_url($oldUrl, PHP_URL_PATH));
                            try {
                                $driveService->deleteFile($oldFileId);
                                // Invalidate local cache
                                \Illuminate\Support\Facades\Storage::disk('local')->delete('google_cache/' . $oldFileId);
                                \Illuminate\Support\Facades\Cache::forget('gdrive_meta_' . $oldFileId);
                            } catch (\Exception $e) {
                                Log::error("Failed to delete old Drive file: $oldFileId");
                            }
                        }

                        $driveFile = $driveService->uploadFile($folderId, $file, $fileKey . '_' . time() . '.pdf', 'application/pdf');
                        if ($driveFile && isset($driveFile->id)) {
                            $timestamp = time();
                            $docData[$dbCol] = '/storage/google/' . $driveFile->id . '?v=' . $timestamp;
                        }
                    }
                }

                if ($equipo->documentacion)
                    $equipo->documentacion->update($docData);
                else {
                    $docData['ID_EQUIPO'] = $equipo->ID_EQUIPO;
                    Documentacion::create($docData);
                }
            }
        });

        // Invalidar caché SIEMPRE (antes del return, aplique a JSON o redirect)
        foreach ([
            'dashboard_total_alerts',
            'dashboard_expired_list_v3',
            'marcas_list_form_v3',
            'modelos_list_form_v3',
            'anios_list_form_v3',
            'equipos_marcas_dropdown',
            'equipos_anios_dropdown',
        ] as $key) {
            \Illuminate\Support\Facades\Cache::forget($key);
        }
        if (auth()->check()) {
            \Illuminate\Support\Facades\Cache::forget('dashboard_user_data_' . auth()->id());
        }

        // NOTA: NO registramos audit 'edit' aqui. El EquipoObserver::updated
        // (AppServiceProvider::boot) ya lo hace automaticamente cuando el
        // modelo detecta cambios (getChanges), y ademas guarda el diff
        // antes/despues — mas rico que un registro plano. Agregar un
        // registrar() aqui generaba duplicados en equipo_audit_log.

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Equipo actualizado correctamente.', 'redirect' => route('equipos.index')]);
        }

        return redirect()->route('equipos.index')->with('success', 'Equipo actualizado.');
    }

    public function destroy($id)
    {
        $equipo = $this->findAndAuthorizeEquipo($id);
        // Registrar ANTES del delete (el Observer solo cubre 'updated',
        // no 'deleted', asi que este registro manual NO duplica nada).
        \App\Models\EquipoAuditLog::registrar($equipo->ID_EQUIPO, 'delete', [
            'codigo_patio'  => $equipo->CODIGO_PATIO,
            'serial_chasis' => $equipo->SERIAL_CHASIS,
        ]);
        // Soft-delete con auditoria: deleted_by guarda quien lo borro, lo cual
        // alimenta la papelera (vista "Equipos Eliminados") con quien y cuando.
        $equipo->deleted_by = auth()->id();
        $equipo->save();
        $equipo->delete(); // SoftDeletes: setea deleted_at, NO borra fisicamente.
        return redirect()->route('equipos.index')->with('success', 'Equipo eliminado.');
    }

    /**
     * Borrado masivo (soft-delete) por IDs. Usado desde el menu Acciones del
     * index cuando hay equipos seleccionados via checkbox. Cada equipo queda
     * con deleted_at + deleted_by (auth user) y desaparece de listas normales.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|exists:equipos,ID_EQUIPO',
        ]);

        $userId = auth()->id();
        $borrados = 0;
        DB::transaction(function () use ($request, $userId, &$borrados) {
            $equipos = Equipo::whereIn('ID_EQUIPO', $request->ids)->lockForUpdate()->get();
            foreach ($equipos as $eq) {
                \App\Models\EquipoAuditLog::registrar($eq->ID_EQUIPO, 'delete', [
                    'codigo_patio'  => $eq->CODIGO_PATIO,
                    'serial_chasis' => $eq->SERIAL_CHASIS,
                    'bulk'          => true,
                ]);
                $eq->deleted_by = $userId;
                $eq->save();
                $eq->delete();
                $borrados++;
            }
        });

        return response()->json([
            'success' => true,
            'message' => "Se eliminaron {$borrados} equipo(s). Puede recuperarlos desde la Papelera.",
            'count'   => $borrados,
        ]);
    }

    /**
     * Papelera: lista los equipos soft-deleted con info de auditoria
     * (quien borro y cuando). Endpoint JSON para el modal "Ver Papelera".
     */
    public function papelera(Request $request)
    {
        $items = Equipo::onlyTrashed()
            ->with([
                'tipo:id,nombre',
                'documentacion:ID_EQUIPO,PLACA',
                'frenteActual:ID_FRENTE,NOMBRE_FRENTE',
                'especificaciones:ID_ESPEC,FOTO_REFERENCIAL',
            ])
            ->orderByDesc('deleted_at')
            ->get();

        // Resolver nombres de los usuarios que borraron (en una sola query).
        $userIds = $items->pluck('deleted_by')->filter()->unique()->values()->all();
        $usuarios = !empty($userIds)
            ? \App\Models\Usuario::whereIn('ID_USUARIO', $userIds)
                ->pluck('NOMBRE_COMPLETO', 'ID_USUARIO')->toArray()
            : [];

        $rows = $items->map(function ($e) use ($usuarios) {
            // Foto: prioriza FOTO_REFERENCIAL del catalogo, cae a FOTO_EQUIPO.
            // Devolvemos el drive ID extraido para que el front use el
            // thumbnail publico (https://drive.google.com/thumbnail?id=...) en
            // lugar del proxy local — mismo patron que el listado principal.
            $fotoSrc = ($e->especificaciones && $e->especificaciones->FOTO_REFERENCIAL)
                ? $e->especificaciones->FOTO_REFERENCIAL
                : $e->FOTO_EQUIPO;
            $fotoDriveId = $fotoSrc ? basename(str_replace('/storage/google/', '', explode('?', $fotoSrc)[0])) : null;

            return [
                'id'             => $e->ID_EQUIPO,
                'tipo'           => optional($e->tipo)->nombre,
                'codigo'         => $e->CODIGO_PATIO,
                'placa'          => optional($e->documentacion)->PLACA,
                'serial_chasis'  => $e->SERIAL_CHASIS,
                'marca'          => $e->MARCA,
                'modelo'         => $e->MODELO,
                'frente'         => optional($e->frenteActual)->NOMBRE_FRENTE,
                'foto_drive_id'  => $fotoDriveId,
                'eliminado_por'  => $e->deleted_by ? ($usuarios[$e->deleted_by] ?? ('Usuario #' . $e->deleted_by)) : 'Desconocido',
                'eliminado_en'   => optional($e->deleted_at)->format('d/m/Y H:i'),
            ];
        });

        return response()->json([
            'success' => true,
            'count'   => $rows->count(),
            'items'   => $rows,
        ]);
    }

    /**
     * Recupera (restaura) un equipo soft-deleted: limpia deleted_at y
     * deleted_by, regresandolo al listado activo.
     */
    public function restoreEquipo($id)
    {
        $equipo = Equipo::onlyTrashed()->where('ID_EQUIPO', $id)->first();
        if (!$equipo) {
            return response()->json([
                'success' => false,
                'message' => 'Equipo no encontrado en la papelera.',
            ], 404);
        }

        $equipo->restore();
        $equipo->deleted_by = null;
        $equipo->save();

        \App\Models\EquipoAuditLog::registrar($equipo->ID_EQUIPO, 'edit', [
            'accion'        => 'restore',
            'codigo_patio'  => $equipo->CODIGO_PATIO,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Equipo recuperado correctamente.',
            'equipo'  => ['id' => $equipo->ID_EQUIPO, 'codigo' => $equipo->CODIGO_PATIO],
        ]);
    }

    public function changeStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:OPERATIVO,INOPERATIVO,EN MANTENIMIENTO,DESINCORPORADO',
        ]);
        $equipo = $this->findAndAuthorizeEquipo($id);
        $equipo->ESTADO_OPERATIVO = $request->input('status');
        $equipo->save();

        // NOTA: El EquipoObserver::updated registra automaticamente un audit
        // 'edit' con el diff de ESTADO_OPERATIVO (antes/despues). Agregar un
        // registrar() manual aqui generaba eventos duplicados en el historial.

        return response()->json(['success' => true, 'message' => 'Estatus actualizado.']);
    }


    public function uploadDoc(Request $request, $id)
    {
        if (!auth()->user()->can('user.edit')) {
            return response()->json(['success' => false, 'message' => 'No tiene permiso para realizar esta acción.'], 403);
        }
        set_time_limit(600);
        ini_set('memory_limit', '512M');
        $request->validate([
            'file' => 'required|file|mimes:pdf|max:51200',
            'doc_type' => 'required|in:propiedad,poliza,rotc,racda,adicional,adicional_2',
            'expiration_date' => 'nullable|date'
        ]);

        $equipo = $this->findAndAuthorizeEquipo($id);
        $type = $request->input('doc_type');
        $file = $request->file('file');

        $dbColumn = '';
        $dateColumn = '';
        $filenamePrefix = '';
        switch ($type) {
            case 'propiedad':
                $dbColumn = 'LINK_DOC_PROPIEDAD';
                $filenamePrefix = 'doc_propiedad_';
                break;
            case 'poliza':
                $dbColumn = 'LINK_POLIZA_SEGURO';
                $dateColumn = 'FECHA_VENC_POLIZA';
                $filenamePrefix = 'poliza_seguro_';
                break;
            case 'rotc':
                $dbColumn = 'LINK_ROTC';
                $dateColumn = 'FECHA_ROTC';
                $filenamePrefix = 'rotc_';
                break;
            case 'racda':
                $dbColumn = 'LINK_RACDA';
                $dateColumn = 'FECHA_RACDA';
                $filenamePrefix = 'racda_';
                break;
            case 'adicional':
                $dbColumn = 'LINK_DOC_ADICIONAL';
                $filenamePrefix = 'doc_adicional_';
                break;
            case 'adicional_2':
                $dbColumn = 'LINK_DOC_ADICIONAL_2';
                $filenamePrefix = 'doc_adicional_2_';
                break;
        }

        try {
            $driveService = \App\Services\GoogleDriveService::getInstance();

            // 1. CAPTURE OLD FILE ID (Don't delete yet - Safety First)
            $oldFileIdToDelete = null;
            if ($equipo->documentacion && $equipo->documentacion->$dbColumn && str_starts_with($equipo->documentacion->$dbColumn, '/storage/google/')) {
                // Extract file ID (remove query params for cache busting)
                $oldUrl = $equipo->documentacion->$dbColumn;
                $oldFileIdToDelete = str_replace('/storage/google/', '', parse_url($oldUrl, PHP_URL_PATH));
            }

            // 2. UPLOAD NEW FILE
            $folderId = $driveService->getRootFolderId();
            $filename = $filenamePrefix . time() . '.pdf';
            $driveFile = $driveService->uploadFile($folderId, $file, $filename, $file->getMimeType());

            if (!$driveFile || !isset($driveFile->id))
                throw new \Exception("La subida a Google Drive no retornó un ID válido");

            // Cache Busting: Add version timestamp
            $timestamp = time();
            $fullUrl = '/storage/google/' . $driveFile->id . '?v=' . $timestamp;

            // 3. UPDATE DATABASE (Including user tracking)
            $updateData = [$dbColumn => $fullUrl];

            // Add expiration date if applicable
            if ($dateColumn && $request->filled('expiration_date')) {
                $updateData[$dateColumn] = $request->input('expiration_date');
            }

            // COMPATIBILITY FIX: Save ID (Int) to match Server DB structure
            $uploadedBy = auth()->user()->ID_USUARIO;
            $uploadedAt = now();

            switch ($type) {
                case 'propiedad':
                    $updateData['PROPIEDAD_SUBIDO_POR'] = $uploadedBy;
                    $updateData['PROPIEDAD_FECHA_SUBIDA'] = $uploadedAt;
                    break;
                case 'poliza':
                    $updateData['POLIZA_SUBIDO_POR'] = $uploadedBy;
                    $updateData['POLIZA_FECHA_SUBIDA'] = $uploadedAt;
                    break;
                case 'rotc':
                    $updateData['ROTC_SUBIDO_POR'] = $uploadedBy;
                    $updateData['ROTC_FECHA_SUBIDA'] = $uploadedAt;
                    break;
                case 'racda':
                    $updateData['RACDA_SUBIDO_POR'] = $uploadedBy;
                    $updateData['RACDA_FECHA_SUBIDA'] = $uploadedAt;
                    break;
                case 'adicional':
                    $updateData['ADICIONAL_SUBIDO_POR'] = $uploadedBy;
                    $updateData['ADICIONAL_FECHA_SUBIDA'] = $uploadedAt;
                    break;
                case 'adicional_2':
                    $updateData['ADICIONAL_2_SUBIDO_POR'] = $uploadedBy;
                    $updateData['ADICIONAL_2_FECHA_SUBIDA'] = $uploadedAt;
                    break;
            }

            Log::info('UploadDoc - Update Data', ['data' => $updateData]);

            if ($equipo->documentacion) {
                $equipo->documentacion->update($updateData);
                Log::info('UploadDoc - Updated existing documentacion');
            } else {
                $updateData['ID_EQUIPO'] = $equipo->ID_EQUIPO;
                Documentacion::create($updateData);
                Log::info('UploadDoc - Created new documentacion');
            }

            // 4. DELETE OLD FILE (Only after success)
            if ($oldFileIdToDelete) {
                \App\Jobs\DeleteGoogleDriveFile::dispatch($oldFileIdToDelete);
                \Illuminate\Support\Facades\Storage::disk('local')->delete('google_cache/' . $oldFileIdToDelete);
                \Illuminate\Support\Facades\Cache::forget('gdrive_meta_' . $oldFileIdToDelete);
            }

            // Clear Dashboard Caches to update alerts immediately
            \Illuminate\Support\Facades\Cache::forget('dashboard_total_alerts');
            \Illuminate\Support\Facades\Cache::forget('dashboard_expired_list_v3');
            if (auth()->check()) {
                \Illuminate\Support\Facades\Cache::forget('dashboard_user_data_' . auth()->id());
            }

            if (ob_get_length())
                ob_end_clean();

            // Devolvemos el autor como string (email de preferencia) para que el frontend
            // pueda hacer `.includes('@')` sin TypeError. Si no hay email ni nombre,
            // caemos a "Usuario #<id>" legible.
            $u = auth()->user();
            $autorStr = $u->CORREO_ELECTRONICO
                ?? $u->NOMBRE_COMPLETO
                ?? ('Usuario #' . $u->ID_USUARIO);

            // Auditoria: registra la subida de documento.
            \App\Models\EquipoAuditLog::registrar(
                $equipo->ID_EQUIPO,
                'upload_' . $type,
                ['archivo' => basename($fullUrl)]
            );

            return response()->json([
                'success' => true,
                'link'    => $fullUrl,
                'autor'   => $autorStr,
                'fecha'   => \Carbon\Carbon::parse($uploadedAt)->format('d/m/y'),
                'message' => 'Documento actualizado correctamente'
            ]);

        } catch (\Exception $e) {
            Log::error('Error subiendo archivo a Google Drive: ' . $e->getMessage());
            if (ob_get_length())
                ob_end_clean();
            return response()->json(['success' => false, 'message' => 'Error al subir archivo: ' . $e->getMessage()], 500);
        }
    }

    private function validationMessages()
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'unique' => 'El :attribute ya ha sido registrado.',
            'integer' => 'El campo :attribute debe ser un número entero.',
            'mimes' => 'El campo :attribute debe ser un archivo de tipo: :values.',
            'max' => 'El campo :attribute no debe pesar más de :max kilobytes.',
            'image' => 'El campo :attribute debe ser una imagen.',
            'in' => 'El valor seleccionado para :attribute es inválido.',
            'required_with' => 'El campo :attribute es obligatorio cuando :values está presente.',
        ];
    }

    private function validationAttributes()
    {
        return [
            'CODIGO_PATIO' => 'Código de Patio',
            'TIPO_EQUIPO' => 'Tipo de Equipo',
            'CATEGORIA_FLOTA' => 'Categoría de Flota',
            'MARCA' => 'Marca',
            'MODELO' => 'Modelo',
            'ANIO' => 'Año',
            'SERIAL_CHASIS' => 'Serial de Chasis',
            'SERIAL_DE_MOTOR' => 'Serial de Motor',
            'documentacion.PLACA' => 'Placa',
            'ESTADO_OPERATIVO' => 'Estatus',
            'doc_propiedad' => 'Documento de Propiedad',
            'documentacion.NRO_DE_DOCUMENTO' => 'Nro. de Documento',
            'poliza_seguro' => 'Póliza de Seguro',
            'documentacion.FECHA_VENC_POLIZA' => 'Fecha de Vencimiento de Póliza',
            'doc_rotc' => 'Documento ROTC',
            'documentacion.FECHA_ROTC' => 'Fecha ROTC',
            'doc_racda' => 'Documento RACDA',
            'documentacion.FECHA_RACDA' => 'Fecha RACDA',
            'foto_equipo' => 'Foto del Equipo',
            'foto_referencial' => 'Foto Referencial',
        ];
    }
    public function checkUniqueness(Request $request)
    {
        $field = $request->input('field');
        $value = $request->input('value');
        $id = $request->input('id'); // For update exclusion

        $allowedFields = ['SERIAL_CHASIS', 'SERIAL_DE_MOTOR', 'CODIGO_PATIO', 'PLACA'];
        if (!in_array($field, $allowedFields)) {
            return response()->json(['error' => 'Invalid field'], 400);
        }

        if ($field === 'PLACA') {
            $query = Documentacion::where('PLACA', strtoupper($value));
            if ($id) {
                // If updating, we need to exclude the documentation belonging to this equipment
                $query->where('ID_EQUIPO', '!=', $id);
            }
            return response()->json(['exists' => $query->exists()]);
        }

        $query = Equipo::where($field, strtoupper($value));
        if ($id) {
            $query->where('ID_EQUIPO', '!=', $id);
        }

        return response()->json(['exists' => $query->exists()]);
    }

    /**
     * Get metadata for a specific document type
     */
    public function metadata(Request $request, $id)
    {
        $equipo = $this->findAndAuthorizeEquipo($id, ['documentacion.seguro']);

        $type = $request->input('type');
        $doc = $equipo->documentacion;
        $data = [];

        if ($doc) {
            switch ($type) {
                case 'propiedad':
                    $data = [
                        'nro_documento' => $doc->NRO_DE_DOCUMENTO ?? '',
                        'titular' => $doc->NOMBRE_DEL_TITULAR ?? '',
                        'placa' => $doc->PLACA ?? '',
                        'marca' => $equipo->MARCA ?? '',
                        'modelo' => $equipo->MODELO ?? '',
                        'serial_chasis' => $equipo->SERIAL_CHASIS ?? '',
                        'serial_motor' => $equipo->SERIAL_DE_MOTOR ?? ''
                    ];
                    break;

                case 'poliza':
                    $data = [
                        'fecha_vencimiento' => $doc->FECHA_VENC_POLIZA ?? '',
                        'id_seguro' => $doc->ID_SEGURO ?? null,
                        'insurers' => CatalogoSeguro::orderBy('NOMBRE_ASEGURADORA', 'asc')->get()
                    ];
                    break;

                case 'rotc':
                    $data = [
                        'fecha_vencimiento' => $doc->FECHA_ROTC ?? ''
                    ];
                    break;

                case 'racda':
                    $data = [
                        'fecha_vencimiento' => $doc->FECHA_RACDA ?? ''
                    ];
                    break;

                case 'adicional':
                    $data = [
                        'fecha_vencimiento' => $doc->FECHA_ADICIONAL ?? '',
                        'categoria' => $equipo->CATEGORIA_FLOTA
                    ];
                    break;

                case 'adicional_2':
                    // Compraventa: NO tiene fecha de vencimiento.
                    $data = [
                        'categoria' => $equipo->CATEGORIA_FLOTA,
                    ];
                    break;
            }
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Delete a specific document from an equipo:
     *  - Borra el archivo del Google Drive (si la URL apunta a /storage/google/...).
     *  - Limpia link + fecha de subida + autor + cache asociado en BD.
     *  - Registra audit log 'delete_X' para que aparezca en /admin/historial-documentos.
     *
     * Permiso: SOLO super.admin (gateado en routes/web.php). Operacion destructiva
     * irreversible — el archivo desaparece tanto del Drive como del registro.
     */
    public function deleteDoc(Request $request, $id)
    {
        $request->validate([
            'doc_type' => 'required|in:propiedad,poliza,rotc,racda,adicional,adicional_2',
        ]);

        $equipo = $this->findAndAuthorizeEquipo($id, ['documentacion']);
        $doc = $equipo->documentacion;

        if (!$doc) {
            return response()->json(['success' => false, 'message' => 'No existe documentación para este equipo.'], 404);
        }

        $type = $request->input('doc_type');

        // Mapping completo de los 6 tipos: campo del LINK + fecha de subida + autor.
        // Mantenemos los 3 campos sincronizados al borrar.
        $fieldMap = [
            'propiedad'   => ['link' => 'LINK_DOC_PROPIEDAD',     'fecha' => 'PROPIEDAD_FECHA_SUBIDA',     'autor' => 'PROPIEDAD_SUBIDO_POR'],
            'poliza'      => ['link' => 'LINK_POLIZA_SEGURO',     'fecha' => 'POLIZA_FECHA_SUBIDA',        'autor' => 'POLIZA_SUBIDO_POR'],
            'rotc'        => ['link' => 'LINK_ROTC',              'fecha' => 'ROTC_FECHA_SUBIDA',          'autor' => 'ROTC_SUBIDO_POR'],
            'racda'       => ['link' => 'LINK_RACDA',             'fecha' => 'RACDA_FECHA_SUBIDA',         'autor' => 'RACDA_SUBIDO_POR'],
            'adicional'   => ['link' => 'LINK_DOC_ADICIONAL',     'fecha' => 'ADICIONAL_FECHA_SUBIDA',     'autor' => 'ADICIONAL_SUBIDO_POR'],
            'adicional_2' => ['link' => 'LINK_DOC_ADICIONAL_2',   'fecha' => 'ADICIONAL_2_FECHA_SUBIDA',   'autor' => 'ADICIONAL_2_SUBIDO_POR'],
        ];

        $cfg = $fieldMap[$type] ?? null;
        if (!$cfg) {
            return response()->json(['success' => false, 'message' => 'Tipo de documento no válido.'], 400);
        }

        $linkField  = $cfg['link'];
        $oldUrl     = $doc->{$linkField};

        // ── 1. Borrar el archivo del Google Drive ──────────────────────────
        // Solo intentamos borrar si la URL guardada apunta a /storage/google/<id>.
        // Mismo patron que uploadDoc (linea ~1511) cuando reemplaza un archivo
        // existente. Errores del Drive NO bloquean el borrado en BD — los
        // registramos para revisión posterior pero la fila local se limpia.
        if ($oldUrl && str_starts_with($oldUrl, '/storage/google/')) {
            try {
                $oldFileId = str_replace('/storage/google/', '', parse_url($oldUrl, PHP_URL_PATH));
                if ($oldFileId) {
                    $driveService = \App\Services\GoogleDriveService::getInstance();
                    $driveService->deleteFile($oldFileId);
                    \Illuminate\Support\Facades\Storage::disk('local')->delete('google_cache/' . $oldFileId);
                    \Illuminate\Support\Facades\Cache::forget('gdrive_meta_' . $oldFileId);
                }
            } catch (\Throwable $e) {
                Log::warning("deleteDoc: fallo al borrar archivo del Drive para equipo {$id} type {$type}: " . $e->getMessage());
            }
        }

        // ── 2. Limpiar link + fecha + autor en la BD ───────────────────────
        $doc->update([
            $cfg['link']  => null,
            $cfg['fecha'] => null,
            $cfg['autor'] => null,
        ]);

        // ── 3. Invalidar caches del dashboard ──────────────────────────────
        \Illuminate\Support\Facades\Cache::forget('dashboard_total_alerts');
        \Illuminate\Support\Facades\Cache::forget('dashboard_expired_list_v3');

        // ── 4. Audit log ───────────────────────────────────────────────────
        \App\Models\EquipoAuditLog::registrar($equipo->ID_EQUIPO, 'delete_' . $type, [
            'archivo_url' => $oldUrl,
        ]);

        Log::info("Documento '{$type}' eliminado del equipo ID {$id} por usuario " . auth()->id());

        return response()->json(['success' => true, 'message' => 'Documento eliminado correctamente.']);
    }


    /**
     * Update metadata for a specific document type
     */
    public function updateMetadata(Request $request, $id)
    {
        if (!auth()->user()->can('user.edit')) {
            return response()->json(['success' => false, 'message' => 'No tiene permiso para realizar esta acción.'], 403);
        }
        $equipo = $this->findAndAuthorizeEquipo($id, ['documentacion']);
        $type = $request->input('doc_type');

        if (!$equipo->documentacion) {
            return response()->json(['success' => false, 'message' => 'No existe documentación para este equipo'], 400);
        }

        $updateData = [];

        switch ($type) {
            case 'propiedad':
                // FIX: Normalize PLACA to null when empty so clearing the field actually saves null,
                // consistent with the update() method fix. strtoupper('') = '' which array_filter
                // below (strips '') would remove — but callers may not reach array_filter for PLACA
                // if the value is already a non-empty string that was then cleared.
                $placaRaw = trim((string) $request->input('placa', ''));
                $updateData = [
                    'NRO_DE_DOCUMENTO'   => strtoupper($request->input('nro_documento', '')) ?: null,
                    'NOMBRE_DEL_TITULAR' => strtoupper($request->input('titular', '')) ?: null,
                    'PLACA'              => $placaRaw !== '' ? strtoupper($placaRaw) : null,
                ];

                // Update Equipment basic info directamente. Usamos saveQuietly()
                // para NO disparar EquipoObserver::updated (que registraria un audit
                // 'edit'). De lo contrario, una sola edicion de propiedad genera DOS
                // eventos en el historial: "Edición de Datos" + "Edición Metadata
                // Propiedad". Solo dejamos el segundo, que ya captura el diff completo.
                $equipo->fill([
                    'MARCA'           => strtoupper($request->input('marca', '')),
                    'MODELO'          => strtoupper($request->input('modelo', '')),
                    'SERIAL_CHASIS'   => strtoupper($request->input('serial_chasis', '')),
                    'SERIAL_DE_MOTOR' => (trim($request->input('serial_motor', '') ?? '') === '') ? null : strtoupper(trim($request->input('serial_motor', ''))),
                ]);
                // Captura el diff del equipo ANTES de saveQuietly. Se guarda en una
                // variable separada (NO se mergea a $updateData) porque $updateData
                // se pasa a $equipo->documentacion->update() y mezclar campos del
                // equipo (MARCA, MODELO, SERIAL_*) en una update de Documentacion
                // los ignoraria silenciosamente (no estan en su fillable), pero
                // ensucia la intencion. El merge se hace al final, solo para el log.
                $equipoDiff = $equipo->getDirty();
                $equipo->saveQuietly();
                break;

            case 'poliza':
                $updateData = [
                    'FECHA_VENC_POLIZA' => $request->input('fecha_vencimiento'),
                ];

                // Clear management if new date is in future
                if ($request->filled('fecha_vencimiento')) {
                    $newDate = \Carbon\Carbon::parse($request->input('fecha_vencimiento'));
                    if ($newDate->isFuture()) {
                        $updateData['poliza_gestion_frente_id'] = null;
                        $updateData['poliza_gestion_fecha'] = null;
                    }
                }

                // Handle insurance name (create if new)
                if ($request->filled('nombre_aseguradora')) {
                    $seguro = CatalogoSeguro::firstOrCreate([
                        'NOMBRE_ASEGURADORA' => strtoupper($request->input('nombre_aseguradora'))
                    ]);
                    $updateData['ID_SEGURO'] = $seguro->ID_SEGURO;
                }
                break;

            case 'rotc':
            case 'racda':
                $fechaKey = $type === 'rotc' ? 'FECHA_ROTC' : 'FECHA_RACDA';
                $frenteKey = $type === 'rotc' ? 'rotc_gestion_frente_id' : 'racda_gestion_frente_id';
                $fechaMgtKey = $type === 'rotc' ? 'rotc_gestion_fecha' : 'racda_gestion_fecha';

                $updateData = [
                    $fechaKey => $request->input('fecha_vencimiento'),
                ];
                if ($request->filled('fecha_vencimiento')) {
                    $newDate = \Carbon\Carbon::parse($request->input('fecha_vencimiento'));
                    if ($newDate->isFuture()) {
                        $updateData[$frenteKey] = null;
                        $updateData[$fechaMgtKey] = null;
                    }
                }
                break;

            case 'adicional':
                $updateData = [
                    'FECHA_ADICIONAL' => $request->input('fecha_vencimiento'),
                ];
                break;

            case 'adicional_2':
                // Compraventa: no guarda fecha de vencimiento.
                $updateData = [];
                break;
        }

        // Filter only empty strings (NOT nulls, because we need to save nulls to clear management)
        $updateData = array_filter($updateData, function ($value) {
            return $value !== '';
        });

        if (!empty($updateData)) {
            $equipo->documentacion->update($updateData);
        }

        // Auditoria: registra la edicion de metadata por tipo de documento.
        // En el caso 'propiedad' tambien se modifican campos del Equipo (MARCA,
        // MODELO, SERIAL_*); $equipoDiff fue capturado antes del saveQuietly().
        // Lo mergeamos solo aqui para que el log refleje el cambio completo
        // (Documentacion + Equipo) sin contaminar $updateData.
        $logPayload = $updateData;
        if (!empty($equipoDiff ?? [])) {
            $logPayload = array_merge($logPayload, $equipoDiff);
        }
        \App\Models\EquipoAuditLog::registrar(
            $equipo->ID_EQUIPO,
            'metadata_' . $type,
            $logPayload
        );

        // Clear Dashboard Cache to update alerts immediately
        \Illuminate\Support\Facades\Cache::forget('dashboard_total_alerts');
        \Illuminate\Support\Facades\Cache::forget('dashboard_expired_list_v3');
        if (auth()->check()) {
            \Illuminate\Support\Facades\Cache::forget('dashboard_user_data_' . auth()->id());
        }

        return response()->json(['success' => true, 'message' => 'Metadatos actualizados']);
    }

    /**
     * Search for catalog match by model and year for equipment linking widget
     */
    public function searchCatalogMatch(Request $request)
    {
        // Sanitize
        $model = strtoupper(trim($request->input('model', '')));
        $year = trim($request->input('year', ''));

        Log::info("SEARCH CATALOG MATCH: Model='$model', Year='$year'");

        if (!$model || !$year) {
            Log::info("SEARCH CATALOG: Missing params");
            return response()->json(['found' => false]);
        }

        // Use strict match but trim-safe
        // OPTIMIZED: Select only necessary columns (not SELECT *)
        $catalogEntries = CaracteristicaModelo::where('MODELO', $model)
            ->where('ANIO_ESPEC', $year)
            ->select([
                'ID_ESPEC',
                'MODELO',
                'ANIO_ESPEC',
                'MOTOR',
                'COMBUSTIBLE',
                'CONSUMO_PROMEDIO',
                'ACEITE_MOTOR',
                'ACEITE_CAJA',
                'LIGA_FRENO',
                'REFRIGERANTE',
                'TIPO_BATERIA',
                'FOTO_REFERENCIAL'
            ])
            ->get();

        if ($catalogEntries->isEmpty()) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'data' => $catalogEntries->map(function ($entry) {
                return [
                    'ID_ESPEC' => $entry->ID_ESPEC,
                    'MODELO' => $entry->MODELO,
                    'ANIO_ESPEC' => $entry->ANIO_ESPEC,
                    'MOTOR' => $entry->MOTOR,
                    'COMBUSTIBLE' => $entry->COMBUSTIBLE,
                    'CONSUMO_PROMEDIO' => $entry->CONSUMO_PROMEDIO,
                    'ACEITE_MOTOR' => $entry->ACEITE_MOTOR,
                    'ACEITE_CAJA' => $entry->ACEITE_CAJA,
                    'LIGA_FRENO' => $entry->LIGA_FRENO,
                    'REFRIGERANTE' => $entry->REFRIGERANTE,
                    'TIPO_BATERIA' => $entry->TIPO_BATERIA,
                    'FOTO_REFERENCIAL' => $entry->FOTO_REFERENCIAL ? asset($entry->FOTO_REFERENCIAL) : null,
                ];
            })->toArray()
        ]);
    }

    /**
     * Get all unique models from both equipos and catalog for autocomplete
     */
    public function getAllModels(Request $request)
    {
        $query = strtoupper(trim($request->input('query', '')));

        // Get models from equipos
        $equiposModels = \App\Models\Equipo::select('MODELO')
            ->distinct()
            ->whereNotNull('MODELO')
            ->where('MODELO', 'LIKE', "%{$query}%")
            ->pluck('MODELO');

        // Get models from catalog
        $catalogModels = CaracteristicaModelo::select('MODELO')
            ->distinct()
            ->whereNotNull('MODELO')
            ->where('MODELO', 'LIKE', "%{$query}%")
            ->pluck('MODELO');

        // Merge and get unique values
        $allModels = $equiposModels->merge($catalogModels)->unique()->sort()->values();

        return response()->json($allModels);
    }

    /**
     * Get Fleet Statistics for Dashboard (Cross-Analysis with Frente Filter)
     * OPTIMIZADO: caché por frente+usuario, queries consolidadas, bug fix acceso local
     */
    public function fleetStats(Request $request)
    {
        try {
            $user              = auth()->user();
            $isLocal           = $user && $user->NIVEL_ACCESO == 2;
            $frentesPermitidos = $user ? $user->getFrentesIds() : [];
            $requestedFrenteId = $request->input('frente_id');

            // No excluir ESPECIAL si el usuario está filtrando explícitamente por uno (drill-down).
            $applyEspecialExclusion = !FrenteTrabajo::isEspecialId($requestedFrenteId);

            // Cache key — versión v2 para invalidar datos previos sin exclusión ESPECIAL.
            $cacheKey = 'fleet_stats_v2_u' . ($user?->id ?? 'guest')
                      . '_f' . ($requestedFrenteId ?: 'all');

            return \Illuminate\Support\Facades\Cache::remember($cacheKey, 120, function () use (
                $isLocal, $frentesPermitidos, $requestedFrenteId, $applyEspecialExclusion
            ) {
                // ── Construir la query base una sola vez ──────────────────────────
                $baseQuery = Equipo::query();

                if ($isLocal && count($frentesPermitidos) > 0) {
                    // Bug fix: NO aplicar dos where consecutivos — usar solo whereIn con un frente si está solicitado
                    if ($requestedFrenteId && $requestedFrenteId !== 'all'
                        && in_array($requestedFrenteId, $frentesPermitidos)
                    ) {
                        $baseQuery->where('ID_FRENTE_ACTUAL', $requestedFrenteId);
                    } else {
                        $baseQuery->whereIn('ID_FRENTE_ACTUAL', $frentesPermitidos);
                    }
                } elseif ($isLocal) {
                    // Usuario local sin frentes permitidos: sin datos
                    $baseQuery->whereRaw('1 = 0');
                } elseif ($requestedFrenteId && $requestedFrenteId !== 'all') {
                    $baseQuery->where('ID_FRENTE_ACTUAL', $requestedFrenteId);
                }

                // Excluir frentes ESPECIAL del dashboard de flota (salvo drill-down explícito).
                if ($applyEspecialExclusion) {
                    $baseQuery->excludeEspecial();
                }

                // ── Stats básicas: 3 counts en una sola query usando SUM condicional ──
                // NOTA: usamos una query fresca con los mismos wheres para evitar conflicto de SELECT en MySQL
                $basicStats = (clone $baseQuery)
                    ->selectRaw('
                        SUM(CASE WHEN ESTADO_OPERATIVO != \'DESINCORPORADO\' THEN 1 ELSE 0 END) as total,
                        SUM(CASE WHEN ANIO >= 2025 AND ESTADO_OPERATIVO != \'DESINCORPORADO\' THEN 1 ELSE 0 END) as fleet_new,
                        SUM(CASE WHEN ANIO <  2025 AND ESTADO_OPERATIVO != \'DESINCORPORADO\' THEN 1 ELSE 0 END) as fleet_old
                    ')
                    ->first();

                $total    = (int) ($basicStats->total    ?? 0);
                $fleetNew = (int) ($basicStats->fleet_new ?? 0);
                $fleetOld = (int) ($basicStats->fleet_old ?? 0);

                // ── Consumo total: JOIN con especificaciones ──────────────────────
                $totalConsumption = (clone $baseQuery)
                    ->join('caracteristicas_modelo', 'equipos.ID_ESPEC', '=', 'caracteristicas_modelo.ID_ESPEC')
                    ->sum(DB::raw('CAST(caracteristicas_modelo.CONSUMO_PROMEDIO AS DECIMAL(10,2))'));

                // ── 1. Estado Operativo ───────────────────────────────────────────
                $byStatusRaw = (clone $baseQuery)
                    ->select('ESTADO_OPERATIVO', DB::raw('COUNT(*) as total'))
                    ->whereNotNull('ESTADO_OPERATIVO')
                    ->groupBy('ESTADO_OPERATIVO')
                    ->orderByDesc('total')
                    ->get();

                // ── 2, 3, 4. Queries agrupadas por tipo (un solo JOIN compartido) ─
                $byTypeRaw = (clone $baseQuery)
                    ->select(
                        'tipo_equipos.nombre as tipo_nombre',
                        DB::raw('SUM(CASE WHEN equipos.ANIO >= 2025 THEN 1 ELSE 0 END) as new_count'),
                        DB::raw('SUM(CASE WHEN equipos.ANIO <  2025 THEN 1 ELSE 0 END) as old_count'),
                        DB::raw("SUM(CASE WHEN equipos.CATEGORIA_FLOTA = 'FLOTA PESADA'  THEN 1 ELSE 0 END) as pesada_count"),
                        DB::raw("SUM(CASE WHEN equipos.CATEGORIA_FLOTA = 'FLOTA LIVIANA' THEN 1 ELSE 0 END) as liviana_count"),
                        DB::raw("SUM(CASE WHEN equipos.ESTADO_OPERATIVO = 'INOPERATIVO'      THEN 1 ELSE 0 END) as inoperativo_count"),
                        DB::raw("SUM(CASE WHEN equipos.ESTADO_OPERATIVO = 'EN MANTENIMIENTO' THEN 1 ELSE 0 END) as mantenimiento_count")
                    )
                    ->leftJoin('tipo_equipos', 'equipos.id_tipo_equipo', '=', 'tipo_equipos.id')
                    ->whereNotNull('equipos.id_tipo_equipo')
                    ->whereNotNull('tipo_equipos.nombre')
                    ->groupBy('tipo_equipos.nombre')
                    ->orderBy('tipo_equipos.nombre')
                    ->get();

                // ── 5. Equipos por Frente (siempre global, sin filtro, sin ESPECIAL) ──
                $eqByFrenteRaw = Equipo::query()
                    ->select(
                        'frentes_trabajo.NOMBRE_FRENTE as frente_nombre',
                        DB::raw('COUNT(equipos.ID_EQUIPO) as total')
                    )
                    ->leftJoin('frentes_trabajo', 'equipos.ID_FRENTE_ACTUAL', '=', 'frentes_trabajo.ID_FRENTE')
                    ->whereNotNull('equipos.ID_FRENTE_ACTUAL')
                    ->whereNotNull('frentes_trabajo.NOMBRE_FRENTE')
                    ->where('frentes_trabajo.TIPO_FRENTE', '!=', 'ESPECIAL')
                    ->groupBy('frentes_trabajo.NOMBRE_FRENTE')
                    ->orderByDesc('total')
                    ->get();

                // ── Transformar byTypeRaw a las 3 secciones ───────────────────────
                // Age (flota nueva vs vieja)
                $ageLabels    = $byTypeRaw->pluck('tipo_nombre')->toArray();
                $newFleetData = $byTypeRaw->pluck('new_count')->map(fn($v) => (int)$v)->toArray();
                $oldFleetData = $byTypeRaw->pluck('old_count')->map(fn($v) => (int)$v)->toArray();

                // Category (pesada vs liviana) — filtrar tipos sin ningún dato
                $catFiltered = $byTypeRaw->filter(fn($r) => ((int)$r->pesada_count + (int)$r->liviana_count) > 0);
                $catLabels   = $catFiltered->pluck('tipo_nombre')->toArray();
                $pesadaData  = $catFiltered->pluck('pesada_count')->map(fn($v) => (int)$v)->values()->toArray();
                $livianaData = $catFiltered->pluck('liviana_count')->map(fn($v) => (int)$v)->values()->toArray();

                // Inoperative (inoperativo + mantenimiento) — filtrar tipos sin datos
                $inopFiltered    = $byTypeRaw->filter(fn($r) => ((int)$r->inoperativo_count + (int)$r->mantenimiento_count) > 0);
                $inopLabels      = $inopFiltered->pluck('tipo_nombre')->toArray();
                $inoperativoData = $inopFiltered->pluck('inoperativo_count')->map(fn($v) => (int)$v)->values()->toArray();
                $mantenimientoData = $inopFiltered->pluck('mantenimiento_count')->map(fn($v) => (int)$v)->values()->toArray();

                return response()->json([
                    'success' => true,
                    'stats' => [
                        'total'             => $total,
                        'fleet_new'         => $fleetNew,
                        'fleet_old'         => $fleetOld,
                        'total_consumption' => number_format((float)$totalConsumption, 2)
                    ],
                    'byStatus' => [
                        'labels' => $byStatusRaw->pluck('ESTADO_OPERATIVO')->toArray(),
                        'values' => $byStatusRaw->pluck('total')->map(fn($v) => (int)$v)->toArray()
                    ],
                    'ageByType' => [
                        'labels'   => $ageLabels,
                        'datasets' => [
                            ['label' => 'Flota Nueva (≥2025)', 'data' => $newFleetData],
                            ['label' => 'Flota Vieja (<2025)',  'data' => $oldFleetData]
                        ]
                    ],
                    'categoryByType' => [
                        'labels'   => $catLabels,
                        'datasets' => [
                            ['label' => 'Flota Pesada',  'data' => $pesadaData],
                            ['label' => 'Flota Liviana', 'data' => $livianaData]
                        ]
                    ],
                    'inoperativeByType' => [
                        'labels'   => $inopLabels,
                        'datasets' => [
                            ['label' => 'Inoperativo',      'data' => $inoperativoData],
                            ['label' => 'En Mantenimiento', 'data' => $mantenimientoData]
                        ]
                    ],
                    'equiposPorFrente' => $eqByFrenteRaw->map(fn($r) => [
                        'frente' => $r->frente_nombre,
                        'total'  => (int) $r->total,
                    ])->values()->toArray(),
                ]);
            });

        } catch (\Exception $e) {
            Log::error('Fleet Stats Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export Fleet Stats to CSV (Excel compatible)
     */
    public function fleetExport(Request $request)
    {
        try {
            $user = auth()->user();
            $isLocal = $user && $user->NIVEL_ACCESO == 2;
            $frentesPermitidos = $user ? $user->getFrentesIds() : [];
            $requestedFrenteId = $request->input('frente_id');

            $frenteNombre = 'TODOS LOS FRENTES';

            // Base query builder for filtering
            $baseQuery = Equipo::query();

            if ($isLocal && count($frentesPermitidos) > 0) {
                if ($requestedFrenteId && $requestedFrenteId !== 'all'
                    && in_array($requestedFrenteId, $frentesPermitidos)) {
                    // FIX: use only where() — adding whereIn() on the same column was redundant (logical AND = same result but wastes an IN clause).
                    $baseQuery->where('ID_FRENTE_ACTUAL', $requestedFrenteId);
                    $frenteObj = FrenteTrabajo::find($requestedFrenteId);
                    $frenteNombre = $frenteObj ? mb_strtoupper($frenteObj->NOMBRE_FRENTE) : 'FRENTE VARIANTE';
                } else {
                    $baseQuery->whereIn('ID_FRENTE_ACTUAL', $frentesPermitidos);
                    $frenteNombre = 'MIS FRENTES ASIGNADOS';
                }
            } elseif ($isLocal) {
                $baseQuery->whereRaw('1 = 0');
            } elseif ($requestedFrenteId && $requestedFrenteId !== 'all') {
                $baseQuery->where('ID_FRENTE_ACTUAL', $requestedFrenteId);
                $frenteObj = FrenteTrabajo::find($requestedFrenteId);
                $frenteNombre = $frenteObj ? mb_strtoupper($frenteObj->NOMBRE_FRENTE) : 'FRENTE ESPECÍFICO';
            }

            // Excluir frentes ESPECIAL salvo cuando se filtra explícitamente por uno.
            if (!FrenteTrabajo::isEspecialId($requestedFrenteId)) {
                $baseQuery->excludeEspecial();
            }

            // --- 1. DATA FOR "FLOTA NUEVA VS VIEJA" ---
            $ageData = (clone $baseQuery)
                ->select(
                    'id_tipo_equipo',
                    DB::raw('SUM(CASE WHEN ANIO >= 2025 THEN 1 ELSE 0 END) as new_count'),
                    DB::raw('SUM(CASE WHEN ANIO < 2025 THEN 1 ELSE 0 END) as old_count')
                )
                ->with('tipo:id,nombre')
                ->groupBy('id_tipo_equipo')
                ->get();

            // --- 2. DATA FOR "PESADA VS LIVIANA" ---
            $categoryData = (clone $baseQuery)
                ->select(
                    'id_tipo_equipo',
                    DB::raw("SUM(CASE WHEN CATEGORIA_FLOTA = 'FLOTA PESADA' THEN 1 ELSE 0 END) as pesada_count"),
                    DB::raw("SUM(CASE WHEN CATEGORIA_FLOTA = 'FLOTA LIVIANA' THEN 1 ELSE 0 END) as liviana_count"),
                    DB::raw("SUM(CASE WHEN CATEGORIA_FLOTA IS NULL OR CATEGORIA_FLOTA = '' THEN 1 ELSE 0 END) as sin_asignar_count")
                )
                ->with('tipo:id,nombre')
                ->groupBy('id_tipo_equipo')
                ->get();

            // --- 3. DATA FOR "ESTADO OPERATIVO" ---
            $statusData = (clone $baseQuery)
                ->select(
                    'ESTADO_OPERATIVO',
                    DB::raw('COUNT(*) as total_count')
                )
                ->groupBy('ESTADO_OPERATIVO')
                ->get();

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Análisis de Flota');

            // Document properties
            $spreadsheet->getProperties()
                ->setCreator('Sistema de Gestión de Equipos')
                ->setLastModifiedBy('Sistema de Gestión de Equipos')
                ->setTitle('Análisis de Flota Operacional')
                ->setSubject('Reporte de Análisis de Flota')
                ->setDescription('Generado automáticamente por el Sistema de Gestión de Equipos - C.VIDALSA 27, C.A.')
                ->setCompany('Constructora Vidalsa 27, C.A.');

            $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

            // LOGO
            $logoPath = public_path('img/imagen_uno.jpg');
            if (file_exists($logoPath)) {
                try {
                    $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                    $drawing->setName('Logo CVIDALSA');
                    $drawing->setDescription('Logo');
                    $drawing->setPath($logoPath);
                    $drawing->setCoordinates('A1');
                    $drawing->setOffsetX(5);
                    $drawing->setOffsetY(8);
                    $drawing->setHeight(90);
                    $drawing->setWorksheet($sheet);
                } catch (\Exception $e) { }
            }

            // Headers & Metadata
            $sheet->mergeCells('A1:A3');
            $sheet->getStyle('A1:A3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
            
            // Ajustar altura de cabecera para atrapar el logo
            $sheet->getRowDimension(1)->setRowHeight(35);
            $sheet->getRowDimension(2)->setRowHeight(35);
            $sheet->getRowDimension(3)->setRowHeight(35);

            $sheet->mergeCells('B1:E3');
            $titleText = "ANÁLISIS ESTADÍSTICO DE FLOTA OPERACIONAL\nPROYECTO: \"{$frenteNombre}\"";
            $sheet->setCellValue('B1', $titleText);
            $sheet->getStyle('B1')->getAlignment()->setWrapText(true);
            $sheet->getStyle('B1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('B1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(13)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
            $sheet->getStyle('B1:E3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
            
            // Borde enmarcando toda la cabecera
            $sheet->getStyle('A1:E3')->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
            ]);
            
            $sheet->mergeCells('A4:E4');
            $fechaEmision = 'Reporte emitido por el sistema en fecha: ' . date('d/m/Y h:i A');
            $sheet->setCellValue('A4', $fechaEmision);
            $sheet->getStyle('A4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('A4')->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF555555');

            // --- ESTILOS COMPARTIDOS PARA TABLAS ---
            $headerStyle = [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF004E8A']
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
                ],
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
            ];
            $rowStyle = [
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
            ];

            $currentRow = 7;

            // --- TABLA 1: ESTADO OPERATIVO ---
            $sheet->mergeCells("A{$currentRow}:E{$currentRow}");
            $sheet->setCellValue("A{$currentRow}", 'RESUMEN: ESTADO OPERATIVO DE EQUIPOS');
            $sheet->getStyle("A{$currentRow}")->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle("A{$currentRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("A{$currentRow}:E{$currentRow}")->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
            ]);
            $currentRow++;

            $sheet->mergeCells("A{$currentRow}:D{$currentRow}");
            $sheet->setCellValue("A{$currentRow}", 'ESTADO OPERATIVO');
            $sheet->setCellValue("E{$currentRow}", 'CANTIDAD');
            $sheet->getStyle("A{$currentRow}:E{$currentRow}")->applyFromArray($headerStyle);
            $sheet->getRowDimension($currentRow)->setRowHeight(25);
            
            foreach ($statusData as $row) {
                $currentRow++;
                $estadoName = $row->ESTADO_OPERATIVO ?: 'DESCONOCIDO';
                
                $sheet->mergeCells("A{$currentRow}:D{$currentRow}");
                $sheet->setCellValue("A{$currentRow}", mb_strtoupper($estadoName));
                $sheet->setCellValue("E{$currentRow}", $row->total_count);
                
                $sheet->getStyle("A{$currentRow}:E{$currentRow}")->applyFromArray($rowStyle);
                $sheet->getStyle("E{$currentRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            }

            // --- TABLA 2: FLOTA NUEVA VS VIEJA ---
            $currentRow += 4; // Espacio entre tablas

            $sheet->mergeCells("A{$currentRow}:E{$currentRow}");
            $sheet->setCellValue("A{$currentRow}", 'RESUMEN: FLOTA NUEVA VS FLOTA VIEJA POR TIPO');
            $sheet->getStyle("A{$currentRow}")->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle("A{$currentRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("A{$currentRow}:E{$currentRow}")->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
            ]);
            $currentRow++;

            $sheet->mergeCells("A{$currentRow}:B{$currentRow}");
            $sheet->setCellValue("A{$currentRow}", 'TIPO DE EQUIPO');
            $sheet->setCellValue("C{$currentRow}", "NUEVO\n(≥ 2025)");
            $sheet->setCellValue("D{$currentRow}", "VIEJO\n(< 2025)");
            $sheet->setCellValue("E{$currentRow}", 'TOTAL');
            $sheet->getStyle("A{$currentRow}:E{$currentRow}")->applyFromArray($headerStyle);
            $sheet->getStyle("A{$currentRow}:E{$currentRow}")->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($currentRow)->setRowHeight(30);
            
            foreach ($ageData as $row) {
                $currentRow++;
                $tipoName = $row->tipo ? mb_strtoupper($row->tipo->nombre) : 'SIN TIPO';
                $total = $row->new_count + $row->old_count;
                
                $sheet->mergeCells("A{$currentRow}:B{$currentRow}");
                $sheet->setCellValue("A{$currentRow}", $tipoName);
                $sheet->setCellValue("C{$currentRow}", $row->new_count);
                $sheet->setCellValue("D{$currentRow}", $row->old_count);
                $sheet->setCellValue("E{$currentRow}", $total);
                
                $sheet->getStyle("A{$currentRow}:E{$currentRow}")->applyFromArray($rowStyle);
                $sheet->getStyle("C{$currentRow}:E{$currentRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            }

            // --- TABLA 2: PESADA VS LIVIANA ---
            $currentRow += 4; // Espacio entre tablas

            $sheet->mergeCells("A{$currentRow}:E{$currentRow}");
            $sheet->setCellValue("A{$currentRow}", 'RESUMEN: CLASIFICACIÓN POR CATEGORÍA DE FLOTA (PESADA VS LIVIANA)');
            $sheet->getStyle("A{$currentRow}")->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle("A{$currentRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("A{$currentRow}:E{$currentRow}")->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
            ]);
            $currentRow++;

            $sheet->setCellValue("A{$currentRow}", 'TIPO DE EQUIPO');
            $sheet->setCellValue("B{$currentRow}", 'PESADA');
            $sheet->setCellValue("C{$currentRow}", 'LIVIANA');
            $sheet->setCellValue("D{$currentRow}", 'SIN ASIGNAR');
            $sheet->setCellValue("E{$currentRow}", 'TOTAL');
            $sheet->getStyle("A{$currentRow}:E{$currentRow}")->applyFromArray($headerStyle);
            $sheet->getRowDimension($currentRow)->setRowHeight(20);

            foreach ($categoryData as $row) {
                $currentRow++;
                $tipoName = $row->tipo ? mb_strtoupper($row->tipo->nombre) : 'SIN TIPO';
                $total = $row->pesada_count + $row->liviana_count + $row->sin_asignar_count;
                
                $sheet->setCellValue("A{$currentRow}", $tipoName);
                $sheet->setCellValue("B{$currentRow}", $row->pesada_count);
                $sheet->setCellValue("C{$currentRow}", $row->liviana_count);
                $sheet->setCellValue("D{$currentRow}", $row->sin_asignar_count);
                $sheet->setCellValue("E{$currentRow}", $total);
                
                $sheet->getStyle("A{$currentRow}:E{$currentRow}")->applyFromArray($rowStyle);
                $sheet->getStyle("B{$currentRow}:E{$currentRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            }

            // Auto-size columns for optimal mapping 
            $sheet->getColumnDimension('A')->setWidth(35);
            $sheet->getColumnDimension('B')->setWidth(20);
            $sheet->getColumnDimension('C')->setWidth(15);
            $sheet->getColumnDimension('D')->setWidth(15);
            $sheet->getColumnDimension('E')->setWidth(15);

            $fileName = 'analisis_flota_' . date('Y-m-d_H-i') . '.xlsx';
            $tempFile = tempnam(sys_get_temp_dir(), 'export_cvidalsa_');

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($tempFile);

            return response()->download($tempFile, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            Log::error('Fleet Export Excel Error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Ocurrió un error al generar el archivo Excel de análisis.');
        }
    }

    /**
     * Get equipos in a given frente filtered by anchor role (REMOLCADOR/REMOLCABLE)
     */
    public function getEquiposByFrente(Request $request)
    {
        $request->validate([
            'id_frente'   => 'required',
            'exclude_ids' => 'nullable|array',
            'search'      => 'nullable|string|max:100',
        ]);

        $search      = trim($request->input('search', ''));
        $sourceRole  = $request->source_role;
        $currentFrenteId = $request->id_frente;

        // Determinar rol opuesto requerido
        $rolOpuesto = null;
        if ($sourceRole === 'REMOLCADOR') {
            $rolOpuesto = 'REMOLCABLE';
        } elseif ($sourceRole === 'REMOLCABLE') {
            $rolOpuesto = 'REMOLCADOR';
        }

        $query = Equipo::whereHas('tipo', function ($q) use ($rolOpuesto) {
                if ($rolOpuesto) {
                    $q->where('ROL_ANCLAJE', $rolOpuesto);
                } else {
                    $q->where('ROL_ANCLAJE', 'NONE');
                }
            })
            ->when($request->exclude_ids, fn($q) => $q->whereNotIn('ID_EQUIPO', $request->exclude_ids))
            ->with(['especificaciones', 'documentacion', 'tipo', 'frenteActual:ID_FRENTE,NOMBRE_FRENTE'])
            ->select('ID_EQUIPO', 'CODIGO_PATIO', 'MARCA', 'MODELO', 'ID_ESPEC', 'FOTO_EQUIPO', 'SERIAL_CHASIS', 'id_tipo_equipo', 'ID_FRENTE_ACTUAL');

        if ($search !== '') {
            // Modo búsqueda global: busca en TODA la flota (excluye ESPECIAL: no son flota propia).
            $s = '%' . $search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('MARCA', 'LIKE', $s)
                  ->orWhere('MODELO', 'LIKE', $s)
                  ->orWhere('SERIAL_CHASIS', 'LIKE', $s)
                  ->orWhere('CODIGO_PATIO', 'LIKE', $s)
                  ->orWhereHas('documentacion', fn($dq) => $dq->where('PLACA', 'LIKE', $s))
                  ->orWhereHas('tipo', fn($tq) => $tq->where('nombre', 'LIKE', $s));
            });
            if (!FrenteTrabajo::isEspecialId($currentFrenteId)) {
                $query->excludeEspecial();
            }
        } else {
            // Modo normal: se listan equipos de TODOS los frentes (no solo el
            // del origen). El anclaje ya no se restringe al mismo frente —
            // permite enlazar REMOLCADOR del frente A con REMOLCABLE del frente B.
            // Excluimos ESPECIAL (patio/almacen) para no contaminar la lista con
            // unidades que no son flota productiva.
            if (!FrenteTrabajo::isEspecialId($currentFrenteId)) {
                $query->excludeEspecial();
            }
        }

        $equipos = $query->orderBy('CODIGO_PATIO')->get()->map(function ($eq) use ($currentFrenteId) {
            $frenteNombre = optional($eq->frenteActual)->NOMBRE_FRENTE;
            $esDeFrenteDistinto = $eq->ID_FRENTE_ACTUAL && (string)$eq->ID_FRENTE_ACTUAL !== (string)$currentFrenteId;

            return [
                'ID_EQUIPO'           => $eq->ID_EQUIPO,
                'CODIGO_PATIO'        => $eq->CODIGO_PATIO,
                'TIPO_NOMBRE'         => $eq->tipo->nombre ?? $eq->CODIGO_PATIO,
                'SERIAL_CHASIS'       => $eq->SERIAL_CHASIS,
                'PLACA'               => $eq->documentacion->PLACA ?? null,
                'MARCA'               => $eq->MARCA,
                'MODELO'              => $eq->MODELO,
                'FOTO'                => $eq->especificaciones->FOTO_REFERENCIAL ?? $eq->FOTO_EQUIPO,
                'FRENTE_NOMBRE'       => $frenteNombre,
                'ES_FRENTE_DISTINTO'  => $esDeFrenteDistinto,
            ];
        });

        return response()->json($equipos);
    }



    /**
     * Get anchored equipment pairs for a specific frente (or all if not specified)
     */
    public function getAnchoredEquipos(Request $request)
    {
        $frenteId = $request->input('frente_id');
        $tipoId   = $request->input('id_tipo');
        $query = Equipo::with(['ancladoA', 'tipo', 'especificaciones', 'documentacion'])->whereNotNull('ID_ANCLAJE');

        if ($frenteId && $frenteId !== 'all') {
            $query->where('ID_FRENTE_ACTUAL', $frenteId);
        } else {
            // Listado global: excluir frentes ESPECIAL (no son flota propia).
            $query->excludeEspecial();
        }

        // Filtro por tipo del listado principal: si esta activo, restringe los
        // pares a aquellos cuyo "remolcador" (eq_a) sea de ese tipo. La pareja
        // mutua se conserva intacta — la deduplicacion por ID minimo se hace
        // mas abajo y respeta el resultado filtrado.
        if ($tipoId && $tipoId !== 'all') {
            // Columna real en `equipos` es id_tipo_equipo (FK a tipo_equipos.id)
            $query->where('id_tipo_equipo', $tipoId);
        }

        $anchored = $query->get()->map(function ($eq) {
            // Get mutual pair to avoid duplicates, we can just return all since we'll group them in JS, or we can format it here.
            // A mutual pair means Eq A is anchored to Eq B. In this system Eq A has ID_ANCLAJE = B.ID, and Eq B has ID_ANCLAJE = A.ID
            // Let's standardise so we only return one pair, where master is the one with smaller ID_EQUIPO, just for uniqueness if mutual.
            $mainImg = $eq->especificaciones->FOTO_REFERENCIAL ?? $eq->FOTO_EQUIPO;
            $anchImg = $eq->ancladoA ? ($eq->ancladoA->especificaciones->FOTO_REFERENCIAL ?? $eq->ancladoA->FOTO_EQUIPO) : null;

            return [
                'ID_A' => $eq->ID_EQUIPO,
                'ID_B' => $eq->ID_ANCLAJE,
                'eq_a' => [
                    'id' => $eq->ID_EQUIPO,
                    'codigo' => $eq->CODIGO_PATIO ?? 'N/A',
                    'etiqueta' => $eq->NUMERO_ETIQUETA ?? null,
                    'placa' => $eq->documentacion->PLACA ?? null,
                    'serial' => $eq->SERIAL_CHASIS ?? null,
                    'marca_modelo' => ($eq->MARCA ?? '') . ' ' . ($eq->MODELO ?? ''),
                    'foto' => $mainImg ? asset($mainImg) : null,
                    'tipo' => $eq->tipo->nombre ?? 'N/A',
                    'estado' => $eq->ESTADO_OPERATIVO ?? 'N/A'
                ],
                'eq_b' => $eq->ancladoA ? [
                    'id' => $eq->ancladoA->ID_EQUIPO,
                    'codigo' => $eq->ancladoA->CODIGO_PATIO ?? 'N/A',
                    'etiqueta' => $eq->ancladoA->NUMERO_ETIQUETA ?? null,
                    'placa' => $eq->ancladoA->documentacion->PLACA ?? null,
                    'serial' => $eq->ancladoA->SERIAL_CHASIS ?? null,
                    'marca_modelo' => ($eq->ancladoA->MARCA ?? '') . ' ' . ($eq->ancladoA->MODELO ?? ''),
                    'foto' => $anchImg ? asset($anchImg) : null,
                    'tipo' => $eq->ancladoA->tipo->nombre ?? 'N/A',
                    'estado' => $eq->ancladoA->ESTADO_OPERATIVO ?? 'N/A'
                ] : null
            ];
        });

        // Filter out the duplicates based on mutual anchorage (A->B and B->A)
        $uniquePairs = [];
        $seen = [];
        foreach ($anchored as $item) {
            if (!$item['eq_b']) continue;

            $id1 = $item['ID_A'];
            $id2 = $item['ID_B'];
            $key = $id1 < $id2 ? "{$id1}_{$id2}" : "{$id2}_{$id1}";

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $uniquePairs[] = $item;
            }
        }

        // ─── Anclajes equipo→auxiliar ──────────────────────────────────
        // El modal del modulo equipos tambien debe mostrar los equipos que
        // tienen auxiliares anclados (anchorage 1:N). Una sola tarjeta por
        // equipo host con todos sus aux — mismo formato visual que el modal
        // de /admin/equipos-auxiliares.
        $auxQuery = \App\Models\EquipoAuxiliar::with([
            'equipoHost.documentacion',
            'equipoHost.tipo',
            'equipoHost.especificaciones',
            'equipoHost.frenteActual',
        ])->whereNotNull('ID_EQUIPO_HOST');

        if ($frenteId && $frenteId !== 'all') {
            $auxQuery->where('ID_FRENTE_ACTUAL', $frenteId);
        }
        if ($tipoId && $tipoId !== 'all') {
            // El filtro de tipo del listado de equipos aplica al HOST: solo
            // pares cuyo equipo host sea del tipo seleccionado.
            $auxQuery->whereHas('equipoHost', function ($q) use ($tipoId) {
                $q->where('id_tipo_equipo', $tipoId);
            });
        }

        $tiposAuxMap = $this->auxTiposLabelMap();
        $byHost = $auxQuery->get()->groupBy('ID_EQUIPO_HOST');
        $auxAnchorages = [];
        foreach ($byHost as $hostId => $auxes) {
            $host = $auxes->first()->equipoHost;
            if (!$host) continue;
            $hostFoto = null;
            if ($host->especificaciones && $host->especificaciones->FOTO_REFERENCIAL) {
                $hostFoto = asset($host->especificaciones->FOTO_REFERENCIAL);
            } elseif ($host->FOTO_EQUIPO) {
                $hostFoto = asset($host->FOTO_EQUIPO);
            }
            $auxAnchorages[] = [
                'host' => [
                    'id'          => $host->ID_EQUIPO,
                    'codigo'      => $host->CODIGO_PATIO ?? null,
                    'placa'       => optional($host->documentacion)->PLACA ?? null,
                    'serial'      => $host->SERIAL_CHASIS ?? null,
                    'tipo'        => optional($host->tipo)->nombre ?? 'EQUIPO',
                    'marca'       => $host->MARCA ?? '',
                    'modelo'      => $host->MODELO ?? '',
                    'foto'        => $hostFoto,
                ],
                'auxes' => $auxes->map(function ($a) use ($tiposAuxMap) {
                    return [
                        'id'         => $a->ID_AUXILIAR,
                        'tipo'       => $a->TIPO,
                        'tipo_label' => $tiposAuxMap[$a->TIPO] ?? $a->TIPO,
                        'marca'      => $a->MARCA,
                        'modelo'     => $a->MODELO,
                        'serial'     => $a->SERIAL,
                        'foto'       => $a->FOTO ? asset($a->FOTO) : null,
                    ];
                })->values(),
            ];
        }

        return response()->json([
            'pairs' => $uniquePairs,
            'aux'   => $auxAnchorages,
        ]);
    }

    /**
     * Mapa TIPO=>label de auxiliares (enum + tipos custom). Replicado del
     * EquipoAuxiliarController::getTiposDinamicos para evitar duplicar la
     * dependencia. Solo se usa para etiquetar tipos en la respuesta del
     * modal de anclajes.
     */
    private function auxTiposLabelMap(): array
    {
        $base = \App\Models\EquipoAuxiliar::tiposLabel();
        try {
            $custom = \App\Models\EquipoAuxiliar::query()
                ->select('TIPO')->whereNotNull('TIPO')->where('TIPO', '!=', '')
                ->distinct()->pluck('TIPO');
            foreach ($custom as $t) {
                if (!isset($base[$t])) $base[$t] = mb_strtoupper((string) $t);
            }
        } catch (\Throwable $e) { /* silencioso: si la tabla no existe, usa solo enum */ }
        return $base;
    }

    /**
     * Exporta la lista de equipos anclados a XLSX usando PhpSpreadsheet, con el
     * mismo encabezado corporativo de los otros reportes (Consumibles): logo +
     * titulo + edicion/revision/fecha. Cada fila: Equipo Padre (tipo, placa/serial)
     * + Equipo Hijo Anclado (tipo, placa/serial) + frente comun.
     */
    public function exportAnclajes(Request $request)
    {
        // XLSX de anclajes puede tardar >30s cuando hay muchos pares y el
        // servidor tiene que cargar relaciones completas. Subimos el limite
        // para evitar 60s fatal en generaciones legitimas.
        set_time_limit(180);

        $frenteId = $request->input('frente_id');
        $tipoId   = $request->input('id_tipo');

        // Reutilizar la lógica de getAnchoredEquipos: obtener pares únicos
        $query = Equipo::with(['ancladoA', 'tipo', 'ancladoA.tipo', 'documentacion', 'ancladoA.documentacion', 'frenteActual'])
            ->whereNotNull('ID_ANCLAJE');

        if ($frenteId && $frenteId !== 'all') {
            $query->where('ID_FRENTE_ACTUAL', $frenteId);
        } else {
            $query->excludeEspecial();
        }

        // Filtro por tipo: hereda el filtro del listado principal cuando esta
        // activo. Mismo comportamiento que getAnchoredEquipos.
        if ($tipoId && $tipoId !== 'all') {
            // Columna real en `equipos` es id_tipo_equipo (FK a tipo_equipos.id)
            $query->where('id_tipo_equipo', $tipoId);
        }

        $anchored = $query->get();

        // Deduplicar pares mutuos (A→B y B→A)
        $pairs = [];
        $seen = [];
        foreach ($anchored as $eq) {
            if (!$eq->ancladoA) continue;
            $id1 = $eq->ID_EQUIPO;
            $id2 = $eq->ID_ANCLAJE;
            $key = $id1 < $id2 ? "{$id1}_{$id2}" : "{$id2}_{$id1}";
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $pairs[] = $eq;
        }

        $nombreFrente = 'TODOS LOS FRENTES';
        if ($frenteId && $frenteId !== 'all') {
            $f = \App\Models\FrenteTrabajo::find($frenteId);
            if ($f) $nombreFrente = mb_strtoupper($f->NOMBRE_FRENTE);
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Anclajes');

        $spreadsheet->getProperties()
            ->setCreator('Sistema de Gestión de Equipos Operacionales')
            ->setLastModifiedBy('Administrador')
            ->setTitle('Reporte de Anclajes')
            ->setSubject('Listado de equipos anclados')
            ->setDescription('Generado automáticamente por el Sistema - C.VIDALSA 27, C.A.');

        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        // ── Encabezado corporativo (mismo patron que ConsumiblesController::exportarCsv) ──
        $logoPath = public_path('img/imagen_uno.jpg');
        if (file_exists($logoPath)) {
            try {
                $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                $drawing->setName('Logo CVIDALSA');
                $drawing->setDescription('Logo');
                $drawing->setPath($logoPath);
                $drawing->setCoordinates('A1');
                $drawing->setOffsetX(45);
                $drawing->setOffsetY(10);
                $drawing->setHeight(100);
                $drawing->setWorksheet($sheet);
            } catch (\Exception $e) { /* silencioso si no puede cargarse */ }
        }

        $sheet->getRowDimension('1')->setRowHeight(35);
        $sheet->getRowDimension('2')->setRowHeight(35);
        $sheet->getRowDimension('3')->setRowHeight(35);

        $sheet->mergeCells('A1:B3');
        $sheet->getStyle('A1:B3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        $subTitle = $nombreFrente !== 'TODOS LOS FRENTES' ? 'FRENTE: "' . $nombreFrente . '"' : 'TODOS LOS FRENTES';
        $titleText = "CONTROL DE EQUIPOS ANCLADOS\n" . $subTitle;

        $sheet->mergeCells('C1:E3');
        $sheet->setCellValue('C1', $titleText);
        $sheet->getStyle('C1')->getAlignment()->setWrapText(true);
        $sheet->getStyle('C1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('C1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('C1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $sheet->getStyle('C1:E3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        $sheet->mergeCells('F1:G1');
        $sheet->setCellValue('F1', 'EDICIÓN: 1');
        $sheet->getStyle('F1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('F1')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('F1:G1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        $sheet->mergeCells('F2:G2');
        $sheet->setCellValue('F2', 'REVISIÓN: 0');
        $sheet->getStyle('F2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F2')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('F2')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('F2:G2')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        $sheet->mergeCells('F3:G3');
        $sheet->setCellValue('F3', 'FECHA DE IMPRESIÓN: ' . date('d/m/Y'));
        $sheet->getStyle('F3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F3')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('F3')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('F3:G3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        $sheet->getStyle('A1:G3')->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        $rowNum = 6;
        $sheet->setCellValue('A' . ($rowNum - 1), 'EMITIDO POR EL SISTEMA DE GESTIÓN DE EQUIPOS OPERACIONALES | Total de pares: ' . count($pairs));
        $sheet->getStyle('A' . ($rowNum - 1))->getFont()->setBold(true)->getColor()->setARGB('FF475569');

        // ── Cabecera de tabla ── (terminologia de dominio: REMOLCADOR / REMOLCADO) ──
        $headers = [
            'A' => '#',
            'B' => 'TIPO REMOLCADOR',
            'C' => 'PLACA / SERIAL (REMOLCADOR)',
            'D' => 'MARCA / MODELO',
            'E' => 'TIPO REMOLCADO',
            'F' => 'PLACA / SERIAL (REMOLCADO)',
            'G' => 'FRENTE',
        ];
        foreach ($headers as $col => $title) {
            $sheet->setCellValue($col . $rowNum, $title);
        }
        $sheet->getStyle('A' . $rowNum . ':G' . $rowNum)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A' . $rowNum . ':G' . $rowNum)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF1E293B');
        $sheet->getStyle('A' . $rowNum . ':G' . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $rowNum++;
        $startDataRow = $rowNum;

        // Función auxiliar: elige placa si existe y no es S/P, sino serial, sino N/A
        $placaOrSerial = function ($eq) {
            $placa = optional($eq->documentacion)->PLACA;
            if ($placa && strtoupper(trim($placa)) !== 'S/P') return $placa;
            return $eq->SERIAL_CHASIS ?: 'N/A';
        };

        $counter = 1;
        foreach ($pairs as $par) {
            $a = $par;
            $b = $par->ancladoA;

            // Determinar REMOLCADOR y REMOLCADO por ROL_ANCLAJE del tipo:
            // - Si alguno es REMOLCADOR, va en las columnas izquierdas (B/C/D).
            // - Si ninguno lo es (pareja neutra/remolcable-remolcable), se deja
            //   el orden original A->B para no perder informacion.
            $rolA = strtoupper(optional($a->tipo)->ROL_ANCLAJE ?? '');
            $rolB = strtoupper(optional($b->tipo)->ROL_ANCLAJE ?? '');

            if ($rolB === 'REMOLCADOR' && $rolA !== 'REMOLCADOR') {
                // Swap: el remolcador real es $b
                $remolcador = $b;
                $remolcado  = $a;
            } else {
                $remolcador = $a;
                $remolcado  = $b;
            }

            $tipoRemolcador  = $remolcador->tipo->nombre ?? 'S/T';
            $tipoRemolcado   = $remolcado->tipo->nombre ?? 'S/T';
            $marcaModelo     = trim(($remolcador->MARCA ?? '') . ' ' . ($remolcador->MODELO ?? '')) ?: 'S/M';
            $idRemolcado     = $placaOrSerial($remolcado);
            // El frente suele ser el mismo en ambos; usamos el del remolcador.
            $frente = optional($remolcador->frenteActual)->NOMBRE_FRENTE ?? '—';

            $sheet->setCellValue('A' . $rowNum, $counter++);
            $sheet->setCellValue('B' . $rowNum, mb_strtoupper($tipoRemolcador));
            $sheet->setCellValue('C' . $rowNum, mb_strtoupper($placaOrSerial($remolcador)));
            $sheet->setCellValue('D' . $rowNum, mb_strtoupper($marcaModelo));
            $sheet->setCellValue('E' . $rowNum, mb_strtoupper($tipoRemolcado));
            $sheet->setCellValue('F' . $rowNum, mb_strtoupper($idRemolcado));
            $sheet->setCellValue('G' . $rowNum, mb_strtoupper($frente));

            $sheet->getStyle('A' . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $rowNum++;
        }

        if ($startDataRow < $rowNum) {
            $sheet->getStyle('A' . $startDataRow . ':G' . ($rowNum - 1))
                  ->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        }

        // ─── Sub-bloque: Anclajes Equipo→Auxiliar ─────────────────────
        // Mismo formato pero con merge vertical en columnas del host (1 host
        // se ve como 1 sola fila visual con N filas de aux). Solo se imprime
        // si hay anclajes equipo→aux que respeten los filtros activos.
        $auxQuery = \App\Models\EquipoAuxiliar::with(['equipoHost.documentacion','equipoHost.tipo','equipoHost.frenteActual'])
            ->whereNotNull('ID_EQUIPO_HOST');
        if ($frenteId && $frenteId !== 'all') {
            $auxQuery->where('ID_FRENTE_ACTUAL', $frenteId);
        }
        if ($tipoId && $tipoId !== 'all') {
            $auxQuery->whereHas('equipoHost', function ($q) use ($tipoId) {
                $q->where('id_tipo_equipo', $tipoId);
            });
        }
        $byHost = $auxQuery->orderBy('ID_EQUIPO_HOST')->orderBy('TIPO')->get()->groupBy('ID_EQUIPO_HOST');

        if ($byHost->count() > 0) {
            // Subtitulo entre secciones
            $rowNum++; // espacio
            $sheet->setCellValue('A' . $rowNum, 'EQUIPOS CON AUXILIARES ANCLADOS');
            $sheet->mergeCells('A' . $rowNum . ':G' . $rowNum);
            $sheet->getStyle('A' . $rowNum)->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle('A' . $rowNum)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF1E40AF');
            $sheet->getStyle('A' . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $rowNum++;

            // Cabecera del sub-bloque (sin "HOST" — la jerarquia se ve por
            // el merge vertical y el subtitulo "EQUIPOS CON AUXILIARES ANCLADOS").
            $auxHeaders = [
                'A' => '#',
                'B' => 'TIPO EQUIPO',
                'C' => 'PLACA / SERIAL',
                'D' => 'MARCA / MODELO',
                'E' => 'TIPO AUXILIAR',
                'F' => 'SERIAL AUX.',
                'G' => 'FRENTE',
            ];
            foreach ($auxHeaders as $col => $title) $sheet->setCellValue($col . $rowNum, $title);
            $sheet->getStyle('A' . $rowNum . ':G' . $rowNum)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle('A' . $rowNum . ':G' . $rowNum)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF1E293B');
            $sheet->getStyle('A' . $rowNum . ':G' . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $rowNum++;

            $auxStart = $rowNum;
            $hostCounter = 1;
            $tiposAuxMap = $this->auxTiposLabelMap();
            foreach ($byHost as $hostId => $auxes) {
                $host = $auxes->first()->equipoHost;
                if (!$host) continue;
                $hostFirst = $rowNum;
                $hostLabel = optional($host->documentacion)->PLACA;
                if (!$hostLabel || strtoupper(trim($hostLabel)) === 'S/P') {
                    $hostLabel = $host->SERIAL_CHASIS ?: ('#' . $host->ID_EQUIPO);
                }
                $hostMM = trim(($host->MARCA ?? '') . ' ' . ($host->MODELO ?? '')) ?: 'S/M';
                $hostFrente = optional($host->frenteActual)->NOMBRE_FRENTE ?? '—';
                $hostTipo = optional($host->tipo)->nombre ?? 'EQUIPO';

                foreach ($auxes as $a) {
                    $sheet->setCellValue('A' . $rowNum, $hostCounter);
                    $sheet->setCellValue('B' . $rowNum, mb_strtoupper($hostTipo));
                    $sheet->setCellValue('C' . $rowNum, mb_strtoupper((string) $hostLabel));
                    $sheet->setCellValue('D' . $rowNum, mb_strtoupper($hostMM));
                    $sheet->setCellValue('E' . $rowNum, mb_strtoupper($tiposAuxMap[$a->TIPO] ?? $a->TIPO));
                    $sheet->setCellValue('F' . $rowNum, mb_strtoupper((string) ($a->SERIAL ?? '—')));
                    $sheet->setCellValue('G' . $rowNum, mb_strtoupper($hostFrente));
                    $sheet->getStyle('A' . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                    $rowNum++;
                }
                $hostLast = $rowNum - 1;
                if ($hostLast > $hostFirst) {
                    foreach (['A','B','C','D','G'] as $col) {
                        $sheet->mergeCells($col . $hostFirst . ':' . $col . $hostLast);
                    }
                    $sheet->getStyle('A' . $hostFirst . ':G' . $hostLast)->getAlignment()
                        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
                        ->setWrapText(true);
                }
                $hostCounter++;
            }
            if ($auxStart < $rowNum) {
                $sheet->getStyle('A' . $auxStart . ':G' . ($rowNum - 1))
                      ->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
            }
        }

        // Columnas: A (numero) fija, el resto auto-size
        $sheet->getColumnDimension('A')->setWidth(6);
        foreach (['B','C','D','E','F','G'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'export_anclajes_');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($tempFile);

        $slug = $frenteId && $frenteId !== 'all' ? '_frente_' . $frenteId : '_todos_frentes';
        $name = 'Anclajes' . $slug . '_' . date('Y-m-d_H-i') . '.xlsx';

        return response()->download($tempFile, $name)->deleteFileAfterSend(true);
    }

    /**
     * Perform bulk anchoring of equipment (mutual link between two equipos)
     */
    public function bulkAnchor(Request $request)
    {
        $request->validate([
            'ids'       => 'required|array',
            'ids.*'     => 'exists:equipos,ID_EQUIPO',
            'master_id' => 'required|exists:equipos,ID_EQUIPO',
        ]);

        try {
            DB::beginTransaction();

            $sourceId = $request->ids[0];
            $targetId = $request->master_id;

            // Create mutual anchor link
            Equipo::where('ID_EQUIPO', $sourceId)->update(['ID_ANCLAJE' => $targetId]);
            Equipo::where('ID_EQUIPO', $targetId)->update(['ID_ANCLAJE' => $sourceId]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Equipos anclados mutuamente con éxito.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('bulkAnchor error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Reconstrucción total de la lógica de Desanclaje desde cero.
     * Desvincula equipos asegurando integridad bidireccional.
     */
    public function clearAnchor(Request $request)
    {
        // 1. Validación estricta
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'exists:equipos,ID_EQUIPO',
        ]);

        try {
            DB::beginTransaction();

            // 2. Obtener los equipos iniciales
            $equiposSeleccionados = Equipo::whereIn('ID_EQUIPO', $request->ids)->get();
            $idsAfectados = [];

            // 3. Recopilar explícitamente a compañeros de anclaje para limpiar a todos
            foreach ($equiposSeleccionados as $equipo) {
                if (!empty($equipo->ID_ANCLAJE)) {
                    $idsAfectados[] = $equipo->ID_EQUIPO; // Yo
                    $idsAfectados[] = $equipo->ID_ANCLAJE; // Mi compañero
                }
            }

            $idsAfectados = array_unique(array_filter($idsAfectados));

            if (empty($idsAfectados)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Los equipos seleccionados ya se encontraban desanclados.',
                ], 400);
            }

            // 4. Limpieza exhaustiva a nivel de Base de Datos para los IDs afectados
            Equipo::whereIn('ID_EQUIPO', $idsAfectados)
                  ->update(['ID_ANCLAJE' => null]);
            
            // 5. Garantía de integridad: eliminar huérfanos que apunten a los IDs afectados
            Equipo::whereIn('ID_ANCLAJE', $idsAfectados)
                  ->update(['ID_ANCLAJE' => null]);

            DB::commit();

            // 6. Reportar éxito limpio
            return response()->json([
                'success' => true,
                'message' => 'Desanclaje completado con éxito de forma definitiva.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[clearAnchor] Fallo crítico: ' . $e->getMessage());
            return response()->json([
                'success' => false, 
                'error' => 'Fallo interno al intentar desanclar. ' . $e->getMessage()
            ], 500);
        }
    }

    // ─── QUICK EDIT: UBICACIÓN ───────────────────────────────────────────────────
    // Permiso: equipos.assign (mismo que movilizar). Setear una ubicacion
    // especifica dentro del frente es operacionalmente afin a reasignar/mover,
    // no a editar la ficha del equipo. Filosofia del sistema: solo PERMISOS
    // decide — NIVEL_ACCESO del usuario no restringe la operacion.
    public function updateUbicacion(Request $request, $id)
    {
        if (! auth()->user()?->can('equipos.assign')) {
            return response()->json(['success' => false, 'error' => 'Sin permisos'], 403);
        }

        $equipo = $this->findAndAuthorizeEquipo($id);

        $request->validate([
            'DETALLE_UBICACION_ACTUAL' => 'nullable|string|max:150',
        ]);

        $valor = $request->filled('DETALLE_UBICACION_ACTUAL')
            ? strtoupper(trim($request->DETALLE_UBICACION_ACTUAL))
            : null;

        $equipo->DETALLE_UBICACION_ACTUAL = $valor;
        $equipo->save();

        // EquipoObserver::updated registra automaticamente el audit 'edit'
        // con diff de DETALLE_UBICACION_ACTUAL. No duplicamos con registrar() manual.

        return response()->json([
            'success'                 => true,
            'DETALLE_UBICACION_ACTUAL' => $valor,
        ]);
    }

    /**
     * BULK update del DETALLE_UBICACION_ACTUAL sobre varios equipos del MISMO frente.
     * Permiso: equipos.assign (mismo que movilizar). Frontend valida mismo
     * frente; aqui hacemos la misma validacion como guard defensivo.
     * NIVEL_ACCESO del usuario NO restringe la operacion — filosofia del
     * sistema: solo PERMISOS decide (ver AppServiceProvider::boot).
     */
    public function bulkUbicacion(Request $request)
    {
        if (! auth()->user()?->can('equipos.assign')) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        $request->validate([
            'ids'               => 'required|array|min:1',
            'ids.*'             => 'exists:equipos,ID_EQUIPO',
            // nullable: permite cadena vacía para borrar la ubicación existente.
            'detalle_ubicacion' => 'nullable|string|max:150',
        ]);

        $rawValor = $request->input('detalle_ubicacion', '');
        // Guardar NULL cuando el valor llega vacío (borra la ubicación en BD)
        $valor = ($rawValor !== null && trim($rawValor) !== '')
            ? strtoupper(trim($rawValor))
            : null;

        // Transaccion requerida para que lockForUpdate tenga efecto real hasta el UPDATE
        // final. Evita race entre validacion "mismo frente" y el write posterior.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $valor) {
            $equipos = Equipo::whereIn('ID_EQUIPO', $request->ids)
                ->lockForUpdate()
                ->get(['ID_EQUIPO', 'ID_FRENTE_ACTUAL']);

            if ($equipos->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'No se encontraron los equipos.'], 404);
            }

            $frentesUnicos = $equipos->pluck('ID_FRENTE_ACTUAL')->unique();
            if ($frentesUnicos->count() > 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Todos los equipos deben estar en el mismo frente.',
                ], 422);
            }
            $frenteUnico = $frentesUnicos->first();
            if ($frenteUnico === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Los equipos seleccionados no tienen un frente asignado.',
                ], 422);
            }

            $count = Equipo::whereIn('ID_EQUIPO', $request->ids)->update([
                'DETALLE_UBICACION_ACTUAL' => $valor,
            ]);

            // IMPORTANTE: el ->update() masivo de QueryBuilder NO dispara
            // EquipoObserver::updated (no hidrata modelos), asi que este
            // registrar() manual NO duplica eventos — es la UNICA forma de
            // que estas acciones queden en equipo_audit_log.
            foreach ($request->ids as $equipoId) {
                \App\Models\EquipoAuditLog::registrar($equipoId, 'bulk_ubicacion', [
                    'valor' => $valor,
                ]);
            }

            return response()->json([
                'success'                  => true,
                'count'                    => $count,
                'DETALLE_UBICACION_ACTUAL' => $valor,
            ]);
        });
    }

    // ─── MOBILE API ───────────────────────────────────────────────────────────────
    public function mobileIndex(Request $request)
    {
        $search = $request->input('search');

        $query = Equipo::with(['tipo', 'frenteActual', 'documentacion'])
            ->excludeEspecial();

        if ($search) {
            $searchUpper = strtoupper(trim($search));
            $query->where(function ($q) use ($searchUpper) {
                $q->where('CODIGO_PATIO', 'like', "%{$searchUpper}%")
                  ->orWhere('SERIAL_CHASIS', 'like', "%{$searchUpper}%")
                  ->orWhere('MARCA', 'like', "%{$searchUpper}%")
                  ->orWhere('MODELO', 'like', "%{$searchUpper}%")
                  ->orWhere('NUMERO_ETIQUETA', 'like', "%{$searchUpper}%")
                  ->orWhereHas('documentacion', function ($d) use ($searchUpper) {
                      $d->where('PLACA', 'like', "%{$searchUpper}%");
                  })
                  ->orWhereHas('frenteActual', function ($f) use ($searchUpper) {
                      $f->where('NOMBRE_FRENTE', 'like', "%{$searchUpper}%");
                  });
            });
        }

        $equipos = $query->orderBy('CODIGO_PATIO')->get();

        return response()->json($equipos->map(function ($eq) {
            return [
                'ID_EQUIPO'       => $eq->ID_EQUIPO,
                'CODIGO_PATIO'    => $eq->CODIGO_PATIO,
                'TIPO'            => $eq->tipo->nombre ?? 'N/A',
                'MARCA'           => $eq->MARCA,
                'MODELO'          => $eq->MODELO,
                'ANIO'            => $eq->ANIO,
                'CATEGORIA_FLOTA' => $eq->CATEGORIA_FLOTA,
                'SERIAL_CHASIS'   => $eq->SERIAL_CHASIS,
                'SERIAL_MOTOR'    => $eq->SERIAL_DE_MOTOR,
                'NUMERO_ETIQUETA' => $eq->NUMERO_ETIQUETA,
                'ESTADO_OPERATIVO'=> $eq->ESTADO_OPERATIVO,
                'PLACA'           => $eq->documentacion->PLACA ?? 'S/P',
                'FRENTE_ACTUAL'   => $eq->frenteActual->NOMBRE_FRENTE ?? 'Sin Asignar',
                'DETALLE_UBICACION' => $eq->DETALLE_UBICACION_ACTUAL,
                'CONFIRMADO'      => $eq->CONFIRMADO_EN_SITIO,
            ];
        }));
    }
    // ──────────────────────────────────────────────────────────────────────────────
    // ── GESTIÓN DE RESPONSABLES DE EQUIPOS ─────────────────────────────────────────

    /**
     * Maximo de responsables historicos conservados por equipo. La lista se
     * muestra en el detalle del equipo (UI limita lo visible, pero si no se
     * trunca en DB crece sin bound). Con HISTORIAL_MAX=2 siempre quedan el
     * actual y el anterior.
     */
    private const RESPONSABLES_HISTORIAL_MAX = 2;

    public function getResponsables($id)
    {
        // Desempate por ID_ASIGNACION DESC: si dos registros tienen la misma
        // FECHA_ASIGNACION (mismo segundo), el que se inserto despues gana.
        $responsables = \App\Models\Responsable::where('ID_EQUIPO', $id)
            ->orderBy('FECHA_ASIGNACION', 'desc')
            ->orderBy('ID_ASIGNACION', 'desc')
            ->limit(self::RESPONSABLES_HISTORIAL_MAX)
            ->get();
        return response()->json(['success' => true, 'data' => $responsables]);
    }

    public function storeResponsable(Request $request, $id)
    {
        // Registrar responsable = escritura de ficha, requiere user.edit.
        // Gate::before del AppServiceProvider resuelve super.admin automaticamente.
        if (! auth()->user()?->can('user.edit')) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        $request->validate([
            'CEDULA_RESPONSABLE' => 'required|string|max:20',
            'PERSONA_ASIGNADA'   => 'required|string|max:150',
        ]);

        $responsable = DB::transaction(function () use ($request, $id) {
            $nuevo = \App\Models\Responsable::create([
                'ID_EQUIPO'          => $id,
                'CEDULA_RESPONSABLE' => strtoupper(trim($request->CEDULA_RESPONSABLE)),
                'PERSONA_ASIGNADA'   => strtoupper(trim($request->PERSONA_ASIGNADA)),
                'FECHA_ASIGNACION'   => now(),
            ]);

            // Mantener solo los N mas recientes. Desempate por ID_ASIGNACION DESC
            // es critico: FECHA_ASIGNACION tiene precision de segundos, dos inserts
            // muy rapidos pueden coincidir y MySQL no garantiza orden estable ahi.
            // Sin este desempate, el nuevo registro podia ser borrado por el
            // whereNotIn si MySQL lo listaba despues del viejo con la misma fecha.
            $keepIds = \App\Models\Responsable::where('ID_EQUIPO', $id)
                ->orderBy('FECHA_ASIGNACION', 'desc')
                ->orderBy('ID_ASIGNACION', 'desc')
                ->limit(self::RESPONSABLES_HISTORIAL_MAX)
                ->pluck('ID_ASIGNACION');
            \App\Models\Responsable::where('ID_EQUIPO', $id)
                ->whereNotIn('ID_ASIGNACION', $keepIds)
                ->delete();

            return $nuevo;
        });

        return response()->json(['success' => true, 'data' => $responsable]);
    }

    // ──────────────────────────────────────────────────────────────────────────────
    // ── CARGA MASIVA DESDE EXCEL ──────────────────────────────────────────────────

    public function bulkTemplate(Request $request)
    {
        $user    = auth()->user();
        $isLocal = $user && $user->NIVEL_ACCESO == 2;

        // Cache el binario XLSX en disco por usuario-scope (solo cambia si agregan frentes/tipos).
        // Se invalida automaticamente al guardar/borrar TipoEquipo o FrenteTrabajo: el
        // counter `bulk_template_gen` incrementa y los archivos viejos quedan obsoletos.
        // Usamos disco en vez de Cache::remember porque algunos drivers (database) no manejan binario.
        $scopeKey = $isLocal
            ? 'local_' . md5(implode(',', $user->getFrentesIds()))
            : 'global';
        $gen     = \Illuminate\Support\Facades\Cache::get('bulk_template_gen', 1);
        $relPath = 'cache/bulk_template/g' . $gen . '_' . $scopeKey . '.xlsx';

        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (!$disk->exists($relPath)) {
            // Limpiar versiones anteriores del mismo scope (evita que storage crezca indefinido).
            foreach ($disk->files('cache/bulk_template') as $old) {
                if (str_ends_with($old, '_' . $scopeKey . '.xlsx') && $old !== $relPath) {
                    $disk->delete($old);
                }
            }
            $binary = $this->buildBulkTemplateBinary($isLocal, $user);
            $disk->put($relPath, $binary);
        }

        $absPath  = $disk->path($relPath);
        $filename = 'plantilla_equipos_' . now()->format('Y-m-d') . '.xlsx';
        return response()->download($absPath, $filename, [
            'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
        ]);
    }

    /**
     * Genera el binario XLSX de la plantilla de bulk upload. Optimizado para velocidad:
     * - fromArray() en vez de setCellValue() en loops.
     * - Un unico applyFromArray al rango de headers (no celda por celda).
     * - Escritura en memoria (php://memory) evitando I/O a disco.
     */
    private function buildBulkTemplateBinary(bool $isLocal, $user): string
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // Metadata minima
        $spreadsheet->getProperties()->setCreator('Vidalsa')->setTitle('Plantilla Bulk Equipos');

        // ── Hoja principal "Equipos" ──────────────────────────────────────────
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Equipos');

        $headersRow = [[
            'Tipo de Equipo', 'Categoria de Flota', 'Marca', 'Modelo', 'Año',
            'N° Etiqueta', 'Serial de Chasis', 'Serial de Motor', 'Frente de Trabajo', 'Status',
        ]];
        $sheet->fromArray($headersRow, null, 'A1');

        // Estilo header: UN SOLO applyFromArray al rango completo en lugar de celda por celda
        $sheet->getStyle('A1:J1')->applyFromArray([
            'font'    => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'    => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0067B1']],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color'       => ['argb' => 'FF000000'],
                ],
            ],
        ]);

        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:J1');

        $colWidths = ['A' => 22, 'B' => 20, 'C' => 16, 'D' => 16, 'E' => 10, 'F' => 15, 'G' => 22, 'H' => 22, 'I' => 25, 'J' => 18];
        foreach ($colWidths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        // ── Hoja oculta "_listas" con fromArray (mucho mas rapido que loops) ──
        $listSheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, '_listas');
        $spreadsheet->addSheet($listSheet);

        $tipos = TipoEquipo::orderBy('nombre')->pluck('nombre')->toArray();
        $frentesQuery = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE');
        if ($isLocal) {
            $frentesQuery->whereIn('ID_FRENTE', $user->getFrentesIds());
        }
        $frentes    = $frentesQuery->pluck('NOMBRE_FRENTE')->toArray();
        $categorias = ['FLOTA LIVIANA', 'FLOTA PESADA'];
        $statuses   = ['OPERATIVO', 'INOPERATIVO', 'MANTENIMIENTO', 'DESINCORPORADO'];

        // fromArray llena listas en columnas de golpe (orders of magnitude mas rapido)
        $listSheet->fromArray([['TipoEquipo']], null, 'A1');
        $listSheet->fromArray(array_map(fn($n) => [$n], $tipos), null, 'A2');

        $listSheet->fromArray([['FrenteTrabajo']], null, 'B1');
        $listSheet->fromArray(array_map(fn($n) => [$n], $frentes), null, 'B2');

        $listSheet->fromArray([['Categoria']], null, 'C1');
        $listSheet->fromArray(array_map(fn($n) => [$n], $categorias), null, 'C2');

        $listSheet->fromArray([['Status']], null, 'D1');
        $listSheet->fromArray(array_map(fn($n) => [$n], $statuses), null, 'D2');

        $listSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);

        // ── Data Validation ───────────────────────────────────────────────────
        $tiposCount   = count($tipos);
        $frentesCount = count($frentes);

        $addListValidation = function (string $column, string $formula, bool $soft = false, string $prompt = '') use ($sheet) {
            $anchor     = $column . '2';
            $validation = $sheet->getCell($anchor)->getDataValidation();
            $validation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
            $validation->setErrorStyle($soft
                ? \PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_INFORMATION
                : \PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
            $validation->setAllowBlank(true);
            $validation->setShowInputMessage(true);
            $validation->setShowErrorMessage(!$soft);
            $validation->setShowDropDown(true);
            if ($soft && $prompt) {
                $validation->setPromptTitle($column === 'A' ? 'Tipo de Equipo' : '');
                $validation->setPrompt($prompt);
            } else {
                $validation->setErrorTitle('Valor no permitido');
                $validation->setError('Selecciona un valor de la lista.');
            }
            $validation->setFormula1($formula);
            $validation->setSqref($column . '2:' . $column . '1001');
        };

        if ($tiposCount > 0) {
            $addListValidation('A', '_listas!$A$2:$A$' . ($tiposCount + 1), true, 'Selecciona de la lista o escribe uno nuevo (se creará al guardar).');
        }
        $addListValidation('B', '_listas!$C$2:$C$3');
        if ($frentesCount > 0) {
            $addListValidation('I', '_listas!$B$2:$B$' . ($frentesCount + 1));
        }
        $addListValidation('J', '_listas!$D$2:$D$5');

        $spreadsheet->setActiveSheetIndex(0);

        // Escritura a memoria (sin I/O a disco) — mas rapido y elimina cleanup
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false); // no hay formulas en la plantilla
        ob_start();
        $writer->save('php://output');
        $binary = ob_get_clean();

        // Liberar memoria explicitamente
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $writer);

        return $binary;
    }

    public function bulkPreview(Request $request)
    {
        $request->validate([
            'archivo_excel' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        $user    = auth()->user();
        $isLocal = $user && $user->NIVEL_ACCESO == 2;
        $frentesPermitidos = $isLocal ? $user->getFrentesIds() : [];

        // Cargar el archivo
        $path        = $request->file('archivo_excel')->getRealPath();
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);

        // Activar hoja "Equipos" o la primera disponible
        $sheet = $spreadsheet->getSheetByName('Equipos') ?? $spreadsheet->getActiveSheet();

        // Validar headers
        $expectedHeaders = [
            'tipo de equipo', 'categoria de flota', 'marca', 'modelo', 'año',
            'n° etiqueta', 'serial de chasis', 'serial de motor', 'frente de trabajo', 'status',
        ];
        $actualHeaders = [];
        foreach (range('A', 'J') as $col) {
            $val = $sheet->getCell($col . '1')->getValue();
            $actualHeaders[] = strtolower(trim((string)$val));
        }

        if ($actualHeaders !== $expectedHeaders) {
            return response()->json([
                'success' => false,
                'message' => 'Headers inválidos: se esperaba [' . implode(', ', $expectedHeaders) . '], se recibió [' . implode(', ', $actualHeaders) . '].',
            ], 422);
        }

        $highestRow = $sheet->getHighestDataRow();
        $dataRows   = $highestRow - 1; // descontando fila 1 (header)

        if ($dataRows > 500) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo supera el máximo permitido de 500 filas de datos (tiene ' . $dataRows . ').',
            ], 422);
        }

        // Constantes de validación en memoria
        $validCategorias = ['FLOTA LIVIANA', 'FLOTA PESADA'];
        $validStatuses   = ['OPERATIVO', 'INOPERATIVO', 'MANTENIMIENTO', 'DESINCORPORADO'];
        $requiredFields  = ['tipo_equipo', 'categoria_flota', 'marca', 'modelo', 'anio', 'serial_chasis', 'frente_trabajo', 'status'];

        // Pre-cargar todos los SERIAL_CHASIS y SERIAL_DE_MOTOR del archivo para detectar duplicados cross-file
        $allChasis  = [];
        $allMotores = [];

        for ($n = 2; $n <= $highestRow; $n++) {
            $chasis  = strtoupper(trim((string)$sheet->getCell('G' . $n)->getValue()));
            $motor   = strtoupper(trim((string)$sheet->getCell('H' . $n)->getValue()));
            if ($chasis !== '')  $allChasis[]  = $chasis;
            if ($motor  !== '')  $allMotores[]  = $motor;
        }

        $duplicateChasis  = array_keys(array_filter(array_count_values($allChasis),  fn($c) => $c > 1));
        $duplicateMotores = array_keys(array_filter(array_count_values($allMotores), fn($c) => $c > 1));

        // Obtener seriales ya existentes en BD (case-insensitive via UPPER)
        $existingChasisBD  = DB::table('equipos')
            ->whereIn(DB::raw('UPPER(SERIAL_CHASIS)'), $allChasis)
            ->pluck('SERIAL_CHASIS')
            ->map(fn($v) => strtoupper($v))
            ->toArray();
        $existingMotoresBD = !empty($allMotores)
            ? DB::table('equipos')
                ->whereIn(DB::raw('UPPER(SERIAL_DE_MOTOR)'), $allMotores)
                ->pluck('SERIAL_DE_MOTOR')
                ->map(fn($v) => strtoupper($v))
                ->toArray()
            : [];

        // Resolver lookups de tipos y frentes en memoria para evitar N+1
        $tiposMap   = TipoEquipo::orderBy('nombre')->get()->keyBy(fn($t) => strtolower(trim($t->nombre)));
        $frentesQuery = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE');
        if ($isLocal) {
            $frentesQuery->whereIn('ID_FRENTE', $frentesPermitidos);
        }
        $frentesMap = $frentesQuery->get()->keyBy(fn($f) => strtolower(trim($f->NOMBRE_FRENTE)));

        $rows = [];

        for ($n = 2; $n <= $highestRow; $n++) {
            // Leer valores crudos
            $rawTipo      = trim((string)$sheet->getCell('A' . $n)->getValue());
            $rawCategoria = trim((string)$sheet->getCell('B' . $n)->getValue());
            $rawMarca     = trim((string)$sheet->getCell('C' . $n)->getValue());
            $rawModelo    = trim((string)$sheet->getCell('D' . $n)->getValue());
            $rawAnio      = $sheet->getCell('E' . $n)->getValue();
            $rawEtiqueta  = trim((string)$sheet->getCell('F' . $n)->getValue());
            $rawChasis    = trim((string)$sheet->getCell('G' . $n)->getValue());
            $rawMotor     = trim((string)$sheet->getCell('H' . $n)->getValue());
            $rawFrente    = trim((string)$sheet->getCell('I' . $n)->getValue());
            $rawStatus    = trim((string)$sheet->getCell('J' . $n)->getValue());

            // Ignorar filas completamente vacías
            if ($rawTipo === '' && $rawMarca === '' && $rawModelo === '' && $rawChasis === '' && $rawFrente === '') {
                continue;
            }

            // Normalizar
            $tipoUpper      = strtoupper($rawTipo);
            $categoriaUpper = strtoupper($rawCategoria);
            $marcaUpper     = strtoupper($rawMarca);
            $modeloUpper    = strtoupper($rawModelo);
            $anio           = $rawAnio !== '' && $rawAnio !== null ? (int)$rawAnio : null;
            $etiqueta       = $rawEtiqueta !== '' ? $rawEtiqueta : null;
            $chasisUpper    = strtoupper($rawChasis);
            $motorUpper     = $rawMotor !== '' ? strtoupper($rawMotor) : null;
            $frenteUpper    = strtoupper($rawFrente);
            $statusUpper    = strtoupper($rawStatus);

            $errors               = [];
            $idTipoResuelto       = null;
            $idFrenteResuelto     = null;

            // Validar requeridos
            foreach ($requiredFields as $field) {
                $val = match($field) {
                    'tipo_equipo'    => $rawTipo,
                    'categoria_flota'=> $rawCategoria,
                    'marca'          => $rawMarca,
                    'modelo'         => $rawModelo,
                    'anio'           => $rawAnio,
                    'serial_chasis'  => $rawChasis,
                    'frente_trabajo' => $rawFrente,
                    'status'         => $rawStatus,
                    default          => '',
                };
                if ($val === '' || $val === null) {
                    $errors[$field] = 'Campo requerido.';
                }
            }

            // Validar tipo_equipo (permite valores nuevos — se crearán al guardar)
            if ($rawTipo !== '') {
                $tipoKey = strtolower($rawTipo);
                if (isset($tiposMap[$tipoKey])) {
                    $idTipoResuelto = $tiposMap[$tipoKey]->id;
                }
                // Si no existe, se crea después en bulkStoreBatch — no es error
            }

            // Validar categoria_flota
            if ($rawCategoria !== '' && !in_array($categoriaUpper, $validCategorias)) {
                $errors['categoria_flota'] = 'Debe ser FLOTA LIVIANA o FLOTA PESADA.';
            }

            // Validar status
            if ($rawStatus !== '' && !in_array($statusUpper, $validStatuses)) {
                $errors['status'] = 'Valor no válido. Opciones: ' . implode(', ', $validStatuses) . '.';
            }

            // Validar frente_trabajo
            if ($rawFrente !== '') {
                $frenteKey = strtolower($rawFrente);
                if (isset($frentesMap[$frenteKey])) {
                    $idFrenteResuelto = $frentesMap[$frenteKey]->ID_FRENTE;
                } else {
                    $errors['frente_trabajo'] = 'Frente no encontrado o inactivo.';
                }
            }

            // Validar serial_chasis
            if ($chasisUpper !== '') {
                if (in_array($chasisUpper, $existingChasisBD)) {
                    $errors['serial_chasis'] = 'Ya registrado en BD.';
                } elseif (in_array($chasisUpper, $duplicateChasis)) {
                    $errors['serial_chasis'] = 'Duplicado dentro del archivo.';
                }
            }

            // Validar serial_de_motor (opcional)
            if ($motorUpper !== null) {
                if (in_array($motorUpper, $existingMotoresBD)) {
                    $errors['serial_de_motor'] = 'Ya registrado en BD.';
                } elseif (in_array($motorUpper, $duplicateMotores)) {
                    $errors['serial_de_motor'] = 'Duplicado dentro del archivo.';
                }
            }

            $rows[] = [
                'row_index' => $n,
                'data'      => [
                    'tipo_equipo'           => $tipoUpper,
                    'categoria_flota'       => $categoriaUpper,
                    'marca'                 => $marcaUpper,
                    'modelo'                => $modeloUpper,
                    'anio'                  => $anio,
                    'numero_etiqueta'       => $etiqueta,
                    'serial_chasis'         => $chasisUpper,
                    'serial_de_motor'       => $motorUpper,
                    'frente_trabajo'        => $frenteUpper,
                    'status'                => $statusUpper,
                    'id_tipo_equipo_resuelto' => $idTipoResuelto,
                    'id_frente_resuelto'    => $idFrenteResuelto,
                ],
                'errors' => $errors,
            ];
        }

        // Construir options para el frontend
        $tiposOptions = TipoEquipo::orderBy('nombre')->get(['id', 'nombre'])
            ->map(fn($t) => ['id' => $t->id, 'nombre' => $t->nombre]);

        $frentesOptionsQuery = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE');
        if ($isLocal) {
            $frentesOptionsQuery->whereIn('ID_FRENTE', $frentesPermitidos);
        }
        $frentesOptions = $frentesOptionsQuery->get(['ID_FRENTE', 'NOMBRE_FRENTE'])
            ->map(fn($f) => ['id' => $f->ID_FRENTE, 'nombre' => $f->NOMBRE_FRENTE]);

        return response()->json([
            'success' => true,
            'rows'    => $rows,
            'options' => [
                'tipos'      => $tiposOptions,
                'frentes'    => $frentesOptions,
                'statuses'   => $validStatuses,
                'categorias' => $validCategorias,
            ],
        ]);
    }

    public function bulkStoreBatch(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        $request->validate([
            'rows'   => 'required|array|min:1|max:500',
            'rows.*' => 'array',
        ]);

        $user    = auth()->user();
        $isLocal = $user && $user->NIVEL_ACCESO == 2;
        $frentesPermitidos = $isLocal ? $user->getFrentesIds() : [];

        $rows            = $request->input('rows');
        $validCategorias = ['FLOTA LIVIANA', 'FLOTA PESADA'];
        $validStatuses   = ['OPERATIVO', 'INOPERATIVO', 'MANTENIMIENTO', 'DESINCORPORADO'];
        $requiredFields  = ['tipo_equipo', 'categoria_flota', 'marca', 'modelo', 'anio', 'serial_chasis', 'frente_trabajo', 'status'];

        // Pre-cargar seriales del lote para cross-file check
        $allChasis  = [];
        $allMotores = [];
        foreach ($rows as $row) {
            $chasis = strtoupper(trim((string)($row['serial_chasis'] ?? '')));
            $motor  = strtoupper(trim((string)($row['serial_de_motor'] ?? '')));
            if ($chasis !== '') $allChasis[]  = $chasis;
            if ($motor  !== '') $allMotores[] = $motor;
        }
        $duplicateChasis  = array_keys(array_filter(array_count_values($allChasis),  fn($c) => $c > 1));
        $duplicateMotores = array_keys(array_filter(array_count_values($allMotores), fn($c) => $c > 1));

        $existingChasisBD = DB::table('equipos')
            ->whereIn(DB::raw('UPPER(SERIAL_CHASIS)'), $allChasis)
            ->pluck('SERIAL_CHASIS')
            ->map(fn($v) => strtoupper($v))
            ->toArray();
        $existingMotoresBD = !empty($allMotores)
            ? DB::table('equipos')
                ->whereIn(DB::raw('UPPER(SERIAL_DE_MOTOR)'), $allMotores)
                ->pluck('SERIAL_DE_MOTOR')
                ->map(fn($v) => strtoupper($v))
                ->toArray()
            : [];

        // Resolver lookups en memoria
        $tiposMap  = TipoEquipo::orderBy('nombre')->get()->keyBy(fn($t) => strtolower(trim($t->nombre)));
        $frentesQuery = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE');
        if ($isLocal) {
            $frentesQuery->whereIn('ID_FRENTE', $frentesPermitidos);
        }
        $frentesMap = $frentesQuery->get()->keyBy(fn($f) => strtolower(trim($f->NOMBRE_FRENTE)));

        $allErrors    = [];
        $resolvedRows = [];

        foreach ($rows as $idx => $row) {
            $errors = [];

            $rawTipo      = trim((string)($row['tipo_equipo']      ?? ''));
            $rawCategoria = trim((string)($row['categoria_flota']  ?? ''));
            $rawMarca     = trim((string)($row['marca']            ?? ''));
            $rawModelo    = trim((string)($row['modelo']           ?? ''));
            $rawAnio      = $row['anio'] ?? null;
            $rawEtiqueta  = trim((string)($row['numero_etiqueta']  ?? ''));
            $rawChasis    = trim((string)($row['serial_chasis']    ?? ''));
            $rawMotor     = trim((string)($row['serial_de_motor']  ?? ''));
            $rawFrente    = trim((string)($row['frente_trabajo']   ?? ''));
            $rawStatus    = trim((string)($row['status']           ?? ''));

            $categoriaUpper = strtoupper($rawCategoria);
            $chasisUpper    = strtoupper($rawChasis);
            $motorUpper     = $rawMotor !== '' ? strtoupper($rawMotor) : null;
            $statusUpper    = strtoupper($rawStatus);

            $idTipoResuelto   = null;
            $idFrenteResuelto = null;

            // Requeridos
            foreach ($requiredFields as $field) {
                $val = match($field) {
                    'tipo_equipo'    => $rawTipo,
                    'categoria_flota'=> $rawCategoria,
                    'marca'          => $rawMarca,
                    'modelo'         => $rawModelo,
                    'anio'           => $rawAnio,
                    'serial_chasis'  => $rawChasis,
                    'frente_trabajo' => $rawFrente,
                    'status'         => $rawStatus,
                    default          => '',
                };
                if ($val === '' || $val === null) {
                    $errors[$field] = 'Campo requerido.';
                }
            }

            // Tipo de equipo (permite valores nuevos — se crean en la transacción)
            if ($rawTipo !== '') {
                $tipoKey = strtolower($rawTipo);
                if (isset($tiposMap[$tipoKey])) {
                    $idTipoResuelto = $tiposMap[$tipoKey]->id;
                }
                // id_tipo_equipo_resuelto = null si es nuevo → firstOrCreate lo creará
            }

            // Categoria flota
            if ($rawCategoria !== '' && !in_array($categoriaUpper, $validCategorias)) {
                $errors['categoria_flota'] = 'Debe ser FLOTA LIVIANA o FLOTA PESADA.';
            }

            // Status
            if ($rawStatus !== '' && !in_array($statusUpper, $validStatuses)) {
                $errors['status'] = 'Valor no válido. Opciones: ' . implode(', ', $validStatuses) . '.';
            }

            // Frente de trabajo
            if ($rawFrente !== '') {
                $frenteKey = strtolower($rawFrente);
                if (isset($frentesMap[$frenteKey])) {
                    $idFrenteResuelto = $frentesMap[$frenteKey]->ID_FRENTE;
                } else {
                    $errors['frente_trabajo'] = 'Frente no encontrado o inactivo.';
                }
            }

            // Serial chasis
            if ($chasisUpper !== '') {
                if (in_array($chasisUpper, $existingChasisBD)) {
                    $errors['serial_chasis'] = 'Ya registrado en BD.';
                } elseif (in_array($chasisUpper, $duplicateChasis)) {
                    $errors['serial_chasis'] = 'Duplicado dentro del lote.';
                }
            }

            // Serial motor (opcional)
            if ($motorUpper !== null) {
                if (in_array($motorUpper, $existingMotoresBD)) {
                    $errors['serial_de_motor'] = 'Ya registrado en BD.';
                } elseif (in_array($motorUpper, $duplicateMotores)) {
                    $errors['serial_de_motor'] = 'Duplicado dentro del lote.';
                }
            }

            if (!empty($errors)) {
                $allErrors[$idx] = $errors;
            }

            $resolvedRows[] = [
                'tipo_equipo'             => $rawTipo,
                'categoria_flota'         => $categoriaUpper,
                'marca'                   => $rawMarca,
                'modelo'                  => $rawModelo,
                'anio'                    => $rawAnio,
                'numero_etiqueta'         => $rawEtiqueta !== '' ? $rawEtiqueta : null,
                'serial_chasis'           => $chasisUpper,
                'serial_de_motor'         => $motorUpper,
                'frente_trabajo'          => $rawFrente,
                'status'                  => $statusUpper,
                'id_tipo_equipo_resuelto' => $idTipoResuelto,
                'id_frente_resuelto'      => $idFrenteResuelto,
            ];
        }

        // Si alguna fila tiene errores → rechazar todo
        if (!empty($allErrors)) {
            return response()->json([
                'success' => false,
                'errors'  => $allErrors,
            ], 422);
        }

        // Insertar todo en transacción, row por row para disparar EquipoObserver.
        // Si el tipo no existe todavía, lo creamos dentro de la misma transacción.
        DB::transaction(function () use ($resolvedRows, $user) {
            $tipoCache = []; // cache en memoria para no crear duplicados dentro del mismo lote
            foreach ($resolvedRows as $row) {
                $idTipo = $row['id_tipo_equipo_resuelto'];
                if ($idTipo === null && !empty($row['tipo_equipo'])) {
                    $tipoNombre = strtoupper(trim($row['tipo_equipo']));
                    if (isset($tipoCache[$tipoNombre])) {
                        $idTipo = $tipoCache[$tipoNombre];
                    } else {
                        $tipo = TipoEquipo::firstOrCreate(
                            ['nombre' => $tipoNombre],
                            ['ROL_ANCLAJE' => 'NEUTRO']
                        );
                        $idTipo = $tipo->id;
                        $tipoCache[$tipoNombre] = $idTipo;
                    }
                }
                Equipo::create([
                    'id_tipo_equipo'           => $idTipo,
                    'CATEGORIA_FLOTA'          => $row['categoria_flota'],
                    'MARCA'                    => strtoupper($row['marca']),
                    'MODELO'                   => strtoupper($row['modelo']),
                    'ANIO'                     => (int)$row['anio'],
                    'NUMERO_ETIQUETA'          => $row['numero_etiqueta'],
                    'SERIAL_CHASIS'            => strtoupper($row['serial_chasis']),
                    'SERIAL_DE_MOTOR'          => $row['serial_de_motor'] ? strtoupper($row['serial_de_motor']) : null,
                    'ID_FRENTE_ACTUAL'         => $row['id_frente_resuelto'],
                    'ESTADO_OPERATIVO'         => $row['status'],
                    'CONFIRMADO_EN_SITIO'      => 0,
                    'ID_ESPEC'                 => null,
                    'ID_ANCLAJE'               => null,
                    'CODIGO_PATIO'             => null,
                    'DETALLE_UBICACION_ACTUAL' => null,
                    'FOTO_EQUIPO'              => null,
                    'LINK_GPS'                 => null,
                    'CREADO_POR'               => $user->ID_USUARIO,
                ]);
            }
        });

        $count = count($resolvedRows);

        return response()->json([
            'success'  => true,
            'message'  => $count . ' equipo' . ($count === 1 ? '' : 's') . ' creado' . ($count === 1 ? '' : 's') . ' correctamente.',
            'count'    => $count,
            'redirect' => '/admin/equipos',
        ]);
    }
}
