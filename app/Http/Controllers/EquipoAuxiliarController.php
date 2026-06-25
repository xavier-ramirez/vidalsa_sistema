<?php

namespace App\Http\Controllers;

use App\Models\Equipo;
use App\Models\EquipoAuxiliar;
use App\Models\FrenteTrabajo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class EquipoAuxiliarController extends Controller
{
    use \App\Traits\ConvertsImageToWebp, \App\Traits\ExcelLogoCorporativo;

    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Devuelve [isLocalUser, frentesPermitidos]. Aplicado en cualquier query
     * que liste auxiliares para usuarios con NIVEL_ACCESO=2 (LOCAL).
     */
    private function userScope(): array
    {
        $user = auth()->user();
        // "Restringido" = NO ve todos los frentes (criterio ÚNICO: Usuario::veTodosLosFrentes).
        $isLocalUser = $user ? !$user->veTodosLosFrentes() : false;
        $frentesPermitidos = $user ? $user->getFrentesIds() : [];
        return [$isLocalUser, $frentesPermitidos];
    }

    /**
     * Barrera COMPLETA de visibilidad por frente sobre $query/$columna: lista blanca
     * (LOCAL → solo sus frentes) + lista negra de bloqueados (whereNotIn, también GLOBAL).
     * Reemplaza el patrón whereIn/whereRaw('1=0') que estaba repetido por todo el módulo.
     */
    private function scopeFrentes($query, string $columna = 'ID_FRENTE_ACTUAL'): void
    {
        $user = auth()->user();
        if ($user) {
            $user->aplicarScopeFrentes($query, $columna);
        }
    }

    /**
     * True si $frenteId está en la lista negra del usuario actual. Se usa en las
     * acciones de ESCRITURA (crear/mover auxiliar) para que nadie —ni GLOBAL— pueda
     * dar de alta o movilizar hacia un frente bloqueado.
     */
    private function frenteEstaBloqueado($frenteId): bool
    {
        $user = auth()->user();
        if (!$user || $frenteId === null || $frenteId === '') {
            return false;
        }
        return in_array((string) $frenteId, array_map('strval', $user->getFrentesBloqueadosIds()), true);
    }

    /**
     * Aborta con 404 si el auxiliar pertenece a un frente fuera del scope
     * del usuario LOCAL. Usado para no filtrar la existencia del registro
     * via URLs directas (mismo patron que findAndAuthorizeEquipo).
     */
    private function authorizeAuxScope(EquipoAuxiliar $aux): void
    {
        $user = auth()->user();
        if (!$user) return;
        $auxFrente = $aux->ID_FRENTE_ACTUAL !== null ? (string) $aux->ID_FRENTE_ACTUAL : null;

        // Lista negra: nadie (ni GLOBAL) puede abrir un auxiliar de un frente bloqueado.
        $bloqueados = array_map('strval', $user->getFrentesBloqueadosIds());
        if ($auxFrente !== null && in_array($auxFrente, $bloqueados, true)) {
            abort(404);
        }

        // Lista blanca: el LOCAL solo ve sus frentes asignados.
        if ($user->veTodosLosFrentes()) return;
        $permitidos = array_map('strval', $user->getFrentesIds());
        if (!in_array($auxFrente, $permitidos, true)) {
            abort(404);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // LISTADO
    // ═══════════════════════════════════════════════════════════
    public function index(Request $request)
    {
        // Acceso global (NIVEL_ACCESO=1) ve todo. Local (NIVEL_ACCESO=2) queda
        // limitado a sus frentes asignados; si seleccionara un frente fuera
        // de su scope el filtro se ignora silenciosamente.
        $user = auth()->user();
        [$isLocalUser, $frentesPermitidos] = $this->userScope();

        // Buscar por serial/codigo/marca/modelo bypassa el scope LOCAL: el
        // filtro de seriales debe encontrar el equipo aunque no este asignado
        // a ninguno de los frentes del usuario (mismo patron que /admin/equipos).
        $bypassScope = trim((string) $request->input('search', '')) !== '';

        // Filtros del listado extraidos a applyAuxiliarFilters() para reutilizar
        // EXACTAMENTE el mismo filtrado desde /admin/equipos (vista embebida de
        // auxiliares por tipo, buildEmbedPayload()). El comportamiento aqui es identico.
        $applyFilters = fn ($q) => $this->applyAuxiliarFilters($q, $request, $bypassScope, $isLocalUser, $frentesPermitidos);

        // Flag: hay al menos un filtro activo. Si no hay, la tabla se muestra
        // vacia (patron de /admin/equipos) para evitar dump masivo de registros.
        $hasFilter = $request->filled('tipo') || $request->filled('id_frente')
                  || $request->filled('estado') || $request->filled('search')
                  || $request->filled('marca') || $request->filled('modelo')
                  || $request->filled('capacidad') || $request->filled('detalle_ubicacion')
                  || $request->boolean('con_propiedad')
                  || $request->boolean('con_certificado');

        // Eager-loads ampliados: incluyen TODO lo que necesita buildAuxDetailsArray
        // para que window.auxDetailsMap se construya en una sola query (no N+1).
        // Asi el modal del ojo abre instant sin fetch.
        $query = EquipoAuxiliar::with([
            'frente',
            'equipoHost.documentacion',
            'equipoHost.tipo',
            'equipoHost.especificaciones',
            'equipoHost.frenteActual',
            'creador',
        ]);
        $applyFilters($query);

        // Scroll infinito (offset += 150) con IntersectionObserver — mismo patron
        // que /admin/equipos. Reemplaza el paginate(25) clasico para listas largas.
        $PAGE_SIZE = 150;
        $offset = max(0, (int) $request->input('offset', 0));
        $totalFound = 0;
        $nextOffset = 0;
        $hasMore = false;
        $truncated = false;

        if ($hasFilter) {
            $countQuery = clone $query;
            $totalFound = $countQuery->count();
            $auxiliares = $query->orderByDesc('created_at')
                ->offset($offset)->limit($PAGE_SIZE)->get();
            $nextOffset = $offset + $auxiliares->count();
            $hasMore = $nextOffset < $totalFound;
            $truncated = $totalFound > $PAGE_SIZE;
        } else {
            $auxiliares = collect([]);
        }

        // Frentes para el dropdown: oculta los no visibles (whitelist LOCAL +
        // blacklist de bloqueados) — un frente bloqueado no aparece como filtro.
        $frentesQuery = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE');
        if ($user) {
            $user->aplicarScopeFrentes($frentesQuery, 'ID_FRENTE');
        }
        $frentes = $frentesQuery->get();
        $estados = EquipoAuxiliar::estadosLabel();

        // Listas para los dropdowns de filtros avanzados.
        // Barrera por frente: whitelist LOCAL + blacklist de bloqueados (también GLOBAL).
        $advBaseScope = function ($q) {
            $this->scopeFrentes($q, 'ID_FRENTE_ACTUAL');
        };

        // TIPOS para el filtro del listado: solo los que realmente existen en
        // la BD (scoped al alcance del usuario). Antes se mezclaba el enum
        // hardcoded de tiposLabel() y aparecian opciones que no filtraban nada
        // cuando no habia filas de ese tipo. Los labels bonitos del enum se
        // siguen aplicando si el codigo existe alli; si no, se genera uno
        // legible a partir del codigo.
        $tipos = $this->buildTiposMap();
        $availableMarcas = EquipoAuxiliar::select('MARCA')
            ->whereNotNull('MARCA')->where('MARCA', '!=', '')
            ->tap($advBaseScope)
            ->distinct()->orderBy('MARCA')->pluck('MARCA');
        $availableModelos = EquipoAuxiliar::select('MODELO')
            ->whereNotNull('MODELO')->where('MODELO', '!=', '')
            ->tap($advBaseScope)
            ->distinct()->orderBy('MODELO')->pluck('MODELO');
        $availableCapacidades = EquipoAuxiliar::select('CAPACIDAD')
            ->whereNotNull('CAPACIDAD')->where('CAPACIDAD', '!=', '')
            ->tap($advBaseScope)
            ->distinct()->orderBy('CAPACIDAD')->pluck('CAPACIDAD');

        // Catalogo implicito de FOTO por MARCA|MODEL: si un auxiliar no tiene
        // FOTO propia, el partial cae a la de otro registro con el mismo modelo
        // (evita placeholders masivos cuando se registran sin foto individual).
        $photoByModel = $this->buildPhotoByModel();

        // Stats: total/operativos/inoperativos/mantenimiento respetando los filtros
        // activos excepto el propio filtro de estado (para mostrar el breakdown real).
        $statsBase = EquipoAuxiliar::query();
        // Barrera por frente (whitelist LOCAL + blacklist bloqueados); el search la bypassa.
        if (!$bypassScope) {
            $this->scopeFrentes($statsBase, 'ID_FRENTE_ACTUAL');
        }
        if ($request->filled('tipo') && $request->tipo !== 'all')         $statsBase->where('TIPO', $request->tipo);
        if ($request->filled('id_frente') && $request->id_frente === 'none') {
            if (!$isLocalUser) {
                $statsBase->whereNull('ID_FRENTE_ACTUAL');
            }
        } elseif ($request->filled('id_frente') && $request->id_frente !== 'all') {
            if (!$isLocalUser || in_array((string) $request->id_frente, array_map('strval', $frentesPermitidos), true)) {
                $statsBase->where('ID_FRENTE_ACTUAL', $request->id_frente);
            }
        }
        if ($request->filled('marca'))     $statsBase->where('MARCA', 'like', '%' . trim($request->marca) . '%');
        if ($request->filled('modelo'))    $statsBase->where('MODELO', 'like', '%' . trim($request->modelo) . '%');
        if ($request->filled('capacidad')) $statsBase->where('CAPACIDAD', 'like', '%' . trim($request->capacidad) . '%');
        if ($request->filled('detalle_ubicacion')) $statsBase->where('DETALLE_UBICACION_ACTUAL', trim($request->detalle_ubicacion));
        // Mismo filtro doc-cargado en stats: para que los conteos de la
        // sidebar reflejen los aux con PDF Propiedad / Certificado activos.
        if ($request->boolean('con_propiedad')) {
            $statsBase->whereNotNull('LINK_DOC_PROPIEDAD')->where('LINK_DOC_PROPIEDAD', '!=', '');
        }
        if ($request->boolean('con_certificado')) {
            $statsBase->whereNotNull('LINK_CERTIFICADO')->where('LINK_CERTIFICADO', '!=', '');
        }
        if ($request->filled('search')) {
            $s = trim($request->search);
            $statsBase->where(function ($qq) use ($s) {
                $qq->where('SERIAL', 'like', "%{$s}%")
                  ->orWhere('CODIGO_INTERNO', 'like', "%{$s}%")
                  ->orWhere('MARCA', 'like', "%{$s}%")
                  ->orWhere('MODELO', 'like', "%{$s}%");
            });
        }
        $hasTipoFilter = $request->filled('tipo') && $request->tipo !== 'all';
        $hasFrenteFilter = $request->filled('id_frente') && $request->id_frente !== 'all';
        $showFrentes = $hasTipoFilter && !$hasFrenteFilter;

        $distribucion = collect();
        $distribucionFrentes = collect();

        // Las stats y la distribución NO se calculan en la carga inicial de la
        // página (HTML): así el módulo abre rápido. El sidebar se rellena luego
        // vía AJAX (cargarAuxiliares() dispara este mismo método con wantsJson).
        if ($request->wantsJson()) {
            $stats = [
                'total'         => (clone $statsBase)->count(),
                'operativos'    => (clone $statsBase)->where('ESTADO_OPERATIVO', 'OPERATIVO')->count(),
                'inoperativos'  => (clone $statsBase)->where('ESTADO_OPERATIVO', 'INOPERATIVO')->count(),
                'en_almacen'    => (clone $statsBase)->where('ESTADO_OPERATIVO', 'EN_ALMACEN')->count(),
            ];

            if ($showFrentes) {
                $distribucionFrentes = (clone $statsBase)
                    ->leftJoin('frentes_trabajo', 'equipos_auxiliares.ID_FRENTE_ACTUAL', '=', 'frentes_trabajo.ID_FRENTE')
                    ->selectRaw('equipos_auxiliares.ID_FRENTE_ACTUAL, frentes_trabajo.NOMBRE_FRENTE, COUNT(equipos_auxiliares.ID_AUXILIAR) as total')
                    ->groupBy('equipos_auxiliares.ID_FRENTE_ACTUAL', 'frentes_trabajo.NOMBRE_FRENTE')
                    ->orderByDesc('total')
                    ->get();
            } else {
                // Distribución por tipo (para el card sidebar inferior): conteo filtrado.
                $distribucion = (clone $statsBase)
                    ->selectRaw('TIPO, COUNT(*) as total')
                    ->groupBy('TIPO')
                    ->orderByDesc('total')
                    ->get();
            }
        } else {
            // Placeholders mientras el sidebar carga por AJAX.
            $stats = ['total' => null, 'operativos' => null, 'inoperativos' => null, 'en_almacen' => null];
        }

        // Mapa pre-calculado de detalles para los auxiliares visibles.
        // Se inyecta en window.auxDetailsMap para que el modal del ojo abra
        // instantaneamente (sin fetch ni spinner). Llave: ID_AUXILIAR.
        $auxDetailsMap = [];
        foreach ($auxiliares as $aux) {
            $auxDetailsMap[$aux->ID_AUXILIAR] = $this->buildAuxDetailsArray($aux, $tipos);
        }

        // ── ASIGNACIONES ESPECIALES (mismo patron que /admin/equipos) ──
        // Si el frente seleccionado es TIPO_FRENTE='ESPECIAL', exponemos a la
        // vista las ubicaciones (sub-zonas) disponibles para el filtro y el
        // listado de stats por ubicacion.
        $frenteEspecial = null;
        $availableUbicaciones = collect();
        $ubicacionesStats = collect();
        if ($request->filled('id_frente') && $request->id_frente !== 'all' && $request->id_frente !== 'none') {
            $frenteEspecial = FrenteTrabajo::where('ID_FRENTE', $request->id_frente)
                ->where('TIPO_FRENTE', 'ESPECIAL')
                ->first();
            if ($frenteEspecial) {
                // Ubicaciones distintas presentes en este frente especial
                $availableUbicaciones = EquipoAuxiliar::where('ID_FRENTE_ACTUAL', $frenteEspecial->ID_FRENTE)
                    ->whereNotNull('DETALLE_UBICACION_ACTUAL')
                    ->where('DETALLE_UBICACION_ACTUAL', '!=', '')
                    ->distinct()
                    ->orderBy('DETALLE_UBICACION_ACTUAL')
                    ->pluck('DETALLE_UBICACION_ACTUAL');

                // Stats por ubicacion: conteo de auxiliares en cada sub-zona
                $ubicacionesStats = EquipoAuxiliar::where('ID_FRENTE_ACTUAL', $frenteEspecial->ID_FRENTE)
                    ->selectRaw("COALESCE(NULLIF(TRIM(DETALLE_UBICACION_ACTUAL), ''), 'Sin Especificación') as detalle, COUNT(*) as total")
                    ->groupBy('detalle')
                    ->orderByDesc('total')
                    ->get();
            }
        }

        if ($request->wantsJson()) {
            return response()->json([
                'html'         => view('admin.equipos_auxiliares.partials.table_rows', compact('auxiliares', 'tipos', 'photoByModel'))->render(),
                // Scroll infinito: estos campos reemplazan al paginate clasico
                'pageSize'     => $PAGE_SIZE,
                'offset'       => $offset,
                'nextOffset'   => $nextOffset,
                'hasMore'      => $hasMore,
                'totalFound'   => $totalFound,
                'shownCount'   => $auxiliares->count(),
                'truncated'    => $truncated,
                'count'        => $totalFound, // back-compat: antes venía $auxiliares->total()
                'stats'        => $stats,
                'distribucion' => $distribucion,
                'distribucionFrentes' => $distribucionFrentes,
                'showFrentes'  => $showFrentes,
                'distribucionHtml' => view('admin.equipos_auxiliares.partials.distribucion_stats', compact('distribucion', 'distribucionFrentes', 'showFrentes', 'tipos'))->render(),
                'hasFilter'    => $hasFilter,
                // El frontend (cargarAuxiliares) hace Object.assign(window.auxDetailsMap, ...)
                // para que el modal del ojo siga abriendo instant tras paginacion/filtro.
                'auxDetailsMap'    => $auxDetailsMap,
                // Asignaciones especiales: solo presentes cuando el frente es ESPECIAL.
                'showUbicaciones'      => $frenteEspecial !== null,
                'frenteEspecialNombre' => $frenteEspecial ? $frenteEspecial->NOMBRE_FRENTE : null,
                'availableUbicaciones' => $availableUbicaciones->values(),
                'ubicacionesStats'     => $ubicacionesStats,
                'ubicacionesHtml'      => view('admin.equipos_auxiliares.partials.ubicaciones_stats',
                                              compact('ubicacionesStats', 'frenteEspecial'))->render(),
            ]);
        }

        return view('admin.equipos_auxiliares.index', compact(
            'auxiliares', 'frentes', 'tipos', 'estados', 'stats', 'distribucion', 'distribucionFrentes', 'showFrentes', 'hasFilter', 'photoByModel',
            'availableMarcas', 'availableModelos', 'availableCapacidades', 'auxDetailsMap',
            'frenteEspecial', 'availableUbicaciones', 'ubicacionesStats',
            'PAGE_SIZE', 'offset', 'nextOffset', 'hasMore', 'totalFound', 'truncated'
        ));
    }

    /**
     * Filtros del listado de auxiliares. Extraido de index() para reutilizar el
     * MISMO filtrado desde la vista embebida en /admin/equipos (buildEmbedPayload()).
     * El search bypassa el scope LOCAL, igual que en /admin/equipos.
     */
    private function applyAuxiliarFilters($q, Request $request, bool $bypassScope, bool $isLocalUser, array $frentesPermitidos, array $exclude = []): void
    {
        $user = auth()->user();
        if ($user && !$bypassScope) {
            $user->aplicarScopeFrentes($q, 'ID_FRENTE_ACTUAL');
        }
        // $exclude permite armar la query de Distribucion SIN el filtro de tipo
        // (para listar todos los tipos), igual que /admin/equipos con tiposStats.
        if (!in_array('tipo', $exclude) && $request->filled('tipo') && $request->tipo !== 'all') {
            $q->where('TIPO', $request->tipo);
        }
        if ($request->filled('id_frente') && $request->id_frente === 'none') {
            // Sentinel "SIN ASIGNAR": auxiliares sin ID_FRENTE_ACTUAL. Para LOCAL no aplica.
            if (!$isLocalUser) {
                $q->whereNull('ID_FRENTE_ACTUAL');
            }
        } elseif ($request->filled('id_frente') && $request->id_frente !== 'all') {
            if (!$isLocalUser || in_array((string) $request->id_frente, array_map('strval', $frentesPermitidos), true)) {
                $q->where('ID_FRENTE_ACTUAL', $request->id_frente);
            }
        }
        if ($request->filled('detalle_ubicacion')) {
            $q->where('DETALLE_UBICACION_ACTUAL', trim($request->detalle_ubicacion));
        }
        if ($request->filled('estado') && $request->estado !== 'all') {
            $q->where('ESTADO_OPERATIVO', $request->estado);
        }
        if ($request->filled('marca'))     $q->where('MARCA', 'like', '%' . trim($request->marca) . '%');
        if ($request->filled('modelo'))    $q->where('MODELO', 'like', '%' . trim($request->modelo) . '%');
        if ($request->filled('capacidad')) $q->where('CAPACIDAD', 'like', '%' . trim($request->capacidad) . '%');
        if ($request->boolean('con_propiedad')) {
            $q->whereNotNull('LINK_DOC_PROPIEDAD')->where('LINK_DOC_PROPIEDAD', '!=', '');
        }
        if ($request->boolean('con_certificado')) {
            $q->whereNotNull('LINK_CERTIFICADO')->where('LINK_CERTIFICADO', '!=', '');
        }
        // Confirmación de presencia en sitio (CONFIRMADO_EN_SITIO): SI=confirmado, NO=pendiente.
        if (!in_array('confirmado', $exclude) && $request->filled('confirmado') && trim($request->confirmado) !== '') {
            $val = strtoupper(trim($request->confirmado));
            if ($val === 'SI') {
                $q->where('CONFIRMADO_EN_SITIO', 1);
            } elseif ($val === 'NO') {
                $q->where('CONFIRMADO_EN_SITIO', 0);
            }
        }
        if ($request->filled('search')) {
            $s = trim($request->search);
            $q->where(function ($qq) use ($s) {
                $qq->where('SERIAL', 'like', "%{$s}%")
                  ->orWhere('CODIGO_INTERNO', 'like', "%{$s}%")
                  ->orWhere('MARCA', 'like', "%{$s}%")
                  ->orWhere('MODELO', 'like', "%{$s}%");
            });
        }
    }

    /**
     * Mapa TIPO => label de los tipos de auxiliar presentes en el scope del
     * usuario (whitelist LOCAL + blacklist bloqueados). Solo incluye tipos que
     * existen en BD; aplica el label bonito del enum si el codigo existe alli.
     * Usado por el filtro del listado y por el dropdown combinado de /admin/equipos.
     */
    public function buildTiposMap(): array
    {
        $tiposLabels = EquipoAuxiliar::tiposLabel();
        $tiposEnDB = EquipoAuxiliar::select('TIPO')
            ->whereNotNull('TIPO')->where('TIPO', '!=', '')
            ->tap(fn ($q) => $this->scopeFrentes($q, 'ID_FRENTE_ACTUAL'))
            ->distinct()->orderBy('TIPO')->pluck('TIPO');
        $tipos = [];
        foreach ($tiposEnDB as $t) {
            $tipos[$t] = $tiposLabels[$t] ?? ucwords(mb_strtolower(str_replace('_', ' ', $t)));
        }
        asort($tipos);
        return $tipos;
    }

    /**
     * Catalogo implicito de FOTO por MARCA|MODELO (fallback cuando un auxiliar
     * no tiene FOTO propia). Reusado por index() y por la vista embebida.
     */
    private function buildPhotoByModel(): array
    {
        return EquipoAuxiliar::whereNotNull('FOTO')
            ->where('FOTO', '!=', '')
            ->select('MARCA', 'MODELO', 'FOTO')
            ->orderByDesc('ID_AUXILIAR')
            ->get()
            ->reduce(function ($carry, $a) {
                $key = mb_strtoupper(trim(($a->MARCA ?? '') . '|' . ($a->MODELO ?? '')));
                if ($key !== '|' && !isset($carry[$key])) $carry[$key] = $a->FOTO;
                return $carry;
            }, []);
    }

    /**
     * Payload para EMBEBER el listado de auxiliares dentro de /admin/equipos
     * (modo aux del dropdown de tipos). Reusa el mismo filtrado, paginacion
     * (offset/150), labels, fotos y mapa de detalles que index(); NO calcula
     * stats/distribucion (en /admin/equipos esos paneles siguen siendo de equipos).
     *
     * El $request debe traer los nombres de parametro del modulo aux:
     * tipo, id_frente, search, offset.
     */
    /**
     * Consolidado (TOTAL / Operativos / Inoperativos) de AUXILIARES dentro del
     * scope de frentes del usuario. Ligero (solo 3 COUNT, sin cargar filas) para
     * pintar el panel "Consolidado de Equipos Auxiliares" en /admin/equipos.
     * Mismas claves que el consolidado de equipos (total/activos/inactivos).
     */
    public function consolidadoStats(Request $request): array
    {
        $base = EquipoAuxiliar::query();
        $this->scopeFrentes($base); // misma barrera de visibilidad por frente del módulo
        // Respeta el filtro de FRENTE de /admin/equipos (eje compartido): al filtrar
        // un frente, el consolidado muestra los auxiliares de ESE frente. El filtro de
        // TIPO no aplica (los tipos de equipos no son los tipos de auxiliares).
        $idFrente = $request->input('id_frente');
        if ($idFrente !== null && $idFrente !== '' && $idFrente !== 'all') {
            $base->where('ID_FRENTE_ACTUAL', $idFrente);
        }
        return [
            'total'     => (clone $base)->count(),
            'activos'   => (clone $base)->where('ESTADO_OPERATIVO', 'OPERATIVO')->count(),
            'inactivos' => (clone $base)->where('ESTADO_OPERATIVO', 'INOPERATIVO')->count(),
        ];
    }

    public function buildEmbedPayload(Request $request): array
    {
        [$isLocalUser, $frentesPermitidos] = $this->userScope();
        $bypassScope = trim((string) $request->input('search', '')) !== '';

        $query = EquipoAuxiliar::with([
            'frente',
            'equipoHost.documentacion',
            'equipoHost.tipo',
            'equipoHost.especificaciones',
            'equipoHost.frenteActual',
            'creador',
        ]);
        $this->applyAuxiliarFilters($query, $request, $bypassScope, $isLocalUser, $frentesPermitidos);

        $PAGE_SIZE = 150;
        $offset = max(0, (int) $request->input('offset', 0));
        $totalFound = (clone $query)->count();
        $auxiliares = $query->orderByDesc('created_at')->offset($offset)->limit($PAGE_SIZE)->get();
        $nextOffset = $offset + $auxiliares->count();
        $hasMore = $nextOffset < $totalFound;

        $tipos = $this->buildTiposMap();
        $photoByModel = $this->buildPhotoByModel();

        $auxDetailsMap = [];
        foreach ($auxiliares as $aux) {
            $auxDetailsMap[$aux->ID_AUXILIAR] = $this->buildAuxDetailsArray($aux, $tipos);
        }

        // ── Consolidado (TOTAL / Operativos / Inoperativos) del tipo+frente+busqueda
        // actuales. Usa las MISMAS claves que el Consolidado de equipos
        // (total/activos/inactivos) para que la vista y el JS lo pinten sin cambios. ──
        $statsBase = EquipoAuxiliar::query();
        $this->applyAuxiliarFilters($statsBase, $request, $bypassScope, $isLocalUser, $frentesPermitidos);
        $stats = [
            'total'     => (clone $statsBase)->count(),
            'activos'   => (clone $statsBase)->where('ESTADO_OPERATIVO', 'OPERATIVO')->count(),
            'inactivos' => (clone $statsBase)->where('ESTADO_OPERATIVO', 'INOPERATIVO')->count(),
        ];

        // ── Distribucion por TIPO: TODOS los tipos del frente/busqueda (SIN el filtro de
        // tipo) para navegar entre ellos, igual que tiposStats en /admin/equipos. ──
        $distBase = EquipoAuxiliar::query();
        $this->applyAuxiliarFilters($distBase, $request, $bypassScope, $isLocalUser, $frentesPermitidos, ['tipo']);
        $auxDistribucion = $distBase
            ->selectRaw('TIPO, COUNT(*) as total')
            ->whereNotNull('TIPO')->where('TIPO', '!=', '')
            ->groupBy('TIPO')->orderByDesc('total')->get()
            ->map(fn ($r) => [
                'tipo'  => $r->TIPO,
                'label' => $tipos[$r->TIPO] ?? ucwords(mb_strtolower(str_replace('_', ' ', $r->TIPO))),
                'total' => $r->total,
            ])->all();

        // ── Distribucion por FRENTE: cuando hay un TIPO de aux seleccionado y NO hay
        // frente, mostramos cuantos de ese tipo hay en CADA frente (igual que la
        // "Ubicacion por Frente" de /admin/equipos). Usa $statsBase (ya filtrado por tipo). ──
        $hasTipoFilter   = $request->filled('tipo') && $request->tipo !== 'all';
        $hasFrenteFilter = $request->filled('id_frente') && $request->id_frente !== 'all';
        $showFrentes     = $hasTipoFilter && !$hasFrenteFilter;
        $auxDistribucionFrentes = collect();
        if ($showFrentes) {
            $auxDistribucionFrentes = (clone $statsBase)
                ->leftJoin('frentes_trabajo', 'equipos_auxiliares.ID_FRENTE_ACTUAL', '=', 'frentes_trabajo.ID_FRENTE')
                ->selectRaw('equipos_auxiliares.ID_FRENTE_ACTUAL, frentes_trabajo.NOMBRE_FRENTE, COUNT(equipos_auxiliares.ID_AUXILIAR) as total')
                ->groupBy('equipos_auxiliares.ID_FRENTE_ACTUAL', 'frentes_trabajo.NOMBRE_FRENTE')
                ->orderByDesc('total')
                ->get();
        }

        return [
            'html'             => view('admin.equipos_auxiliares.partials.table_rows',
                                     compact('auxiliares', 'tipos', 'photoByModel') + ['embed' => true])->render(),
            'auxDetailsMap'    => $auxDetailsMap,
            'nextOffset'       => $nextOffset,
            'hasMore'          => $hasMore,
            'totalFound'       => $totalFound,
            'shownCount'       => $auxiliares->count(),
            // Consolidado + Distribucion de AUXILIARES (modo aux de /admin/equipos).
            'stats'            => $stats,
            'auxDistribucion'  => $auxDistribucion,
            'distribucionHtml' => view('admin.equipos.partials.aux_distribution_stats',
                                     compact('auxDistribucion', 'auxDistribucionFrentes', 'showFrentes'))->render(),
        ];
    }

    /**
     * Exportar listado (XLSX) respetando los filtros activos.
     * Encabezado corporativo identico al resto de exportaciones: logo VIDALSA
     * a la izquierda, titulo + filtro activo al centro, metadatos (edicion,
     * revision, fecha) a la derecha, "Exportado por" en fila 4.
     */
    public function export(Request $request)
    {
        set_time_limit(180);
        $query = EquipoAuxiliar::with('frente');

        // Acceso por frente: whitelist LOCAL + blacklist de bloqueados (también GLOBAL).
        $this->scopeFrentes($query, 'ID_FRENTE_ACTUAL');
        // Se reusan abajo para decidir si se ignora un filtro id_frente fuera del scope LOCAL.
        [$isLocalUser, $frentesPermitidos] = $this->userScope();

        // Capturar filtros activos para reflejarlos en el titulo
        $tipoFiltro   = ($request->filled('tipo') && $request->tipo !== 'all') ? $request->tipo : null;
        $frenteFiltro = ($request->filled('id_frente') && $request->id_frente !== 'all') ? $request->id_frente : null;
        $estadoFiltro = ($request->filled('estado') && $request->estado !== 'all') ? $request->estado : null;
        $sinAsignarFiltro = ($frenteFiltro === 'none');

        // Si LOCAL pide un frente fuera de su scope o "SIN ASIGNAR" (su scope no
        // contiene nulls), ignoramos el filtro para no devolver vacio.
        if ($isLocalUser && $frenteFiltro !== null
            && ($sinAsignarFiltro
                || !in_array((string) $frenteFiltro, array_map('strval', $frentesPermitidos), true))) {
            $frenteFiltro = null;
            $sinAsignarFiltro = false;
        }

        if ($tipoFiltro)   $query->where('TIPO', $tipoFiltro);
        if ($sinAsignarFiltro) {
            $query->whereNull('ID_FRENTE_ACTUAL');
        } elseif ($frenteFiltro) {
            $query->where('ID_FRENTE_ACTUAL', $frenteFiltro);
        }
        if ($estadoFiltro) $query->where('ESTADO_OPERATIVO', $estadoFiltro);
        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($qq) use ($s) {
                $qq->where('SERIAL', 'like', "%{$s}%")->orWhere('CODIGO_INTERNO', 'like', "%{$s}%")
                  ->orWhere('MARCA', 'like', "%{$s}%")->orWhere('MODELO', 'like', "%{$s}%");
            });
        }

        $tipos   = $this->getTiposDinamicos();
        $estados = EquipoAuxiliar::estadosLabel();

        // Resolver nombres legibles de los filtros activos
        $nombreTipo = $tipoFiltro ? mb_strtoupper($tipos[$tipoFiltro] ?? $tipoFiltro) : 'TODOS';
        $nombreFrente = 'TODOS LOS FRENTES';
        if ($sinAsignarFiltro) {
            $nombreFrente = 'SIN ASIGNAR';
        } elseif ($frenteFiltro) {
            $f = \App\Models\FrenteTrabajo::find($frenteFiltro);
            if ($f) $nombreFrente = mb_strtoupper($f->NOMBRE_FRENTE);
        }
        $currentDate = now()->format('d/m/Y');

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('Sistema de Gestión de Equipos Operacionales')
            ->setLastModifiedBy('Sistema de Gestión de Equipos Operacionales')
            ->setTitle('Listado de Equipos Auxiliares')
            ->setCompany('Constructora Vidalsa 27, C.A.');
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Equipos Auxiliares');

        $lastCol = 'I'; // 9 columnas

        // Alturas de fila del encabezado (ANTES del logo para que el centrado vertical funcione)
        $sheet->getRowDimension(1)->setRowHeight(40);
        $sheet->getRowDimension(2)->setRowHeight(40);
        $sheet->getRowDimension(3)->setRowHeight(40);

        // Logo centrado en A1:B3 (trait ExcelLogoCorporativo)
        $sheet->mergeCells('A1:B3');
        $sheet->getStyle('A1:B3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        $this->insertarLogoCorporativo($sheet, ['A','B'], [1,2,3]);

        // Titulo central con filtros aplicados (C1:E3)
        $partes = [];
        if ($frenteFiltro || $sinAsignarFiltro) $partes[] = 'FRENTE: ' . $nombreFrente;
        if ($tipoFiltro)                        $partes[] = 'TIPO: '   . $nombreTipo;
        $subTitle = $partes ? implode(' — ', $partes) : 'COPIA DE BASE DE DATOS DEL SISTEMA DE GESTION DE EQUIPOS OPERACIONALES';
        $titleText = "LISTADO DE EQUIPOS AUXILIARES\n" . $subTitle;
        $sheet->mergeCells('C1:E3');
        $sheet->setCellValue('C1', $titleText);
        $sheet->getStyle('C1')->getAlignment()->setWrapText(true)->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('C1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $sheet->getStyle('C1:E3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        // Metadatos a la derecha (F1:I3)
        $meta = [
            ['F1', 'EDICION: 1'],
            ['F2', 'REVISION: 0'],
            ['F3', 'FECHA: ' . $currentDate],
        ];
        foreach ($meta as [$cell, $text]) {
            $row = substr($cell, 1);
            $sheet->mergeCells("F{$row}:{$lastCol}{$row}");
            $sheet->setCellValue($cell, $text);
            $sheet->getStyle($cell)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $sheet->getStyle($cell)->getFont()->setBold(true)->setSize(11)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
            $sheet->getStyle("F{$row}:{$lastCol}{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        }

        // Fila 4 - Exportado por
        $sheet->mergeCells("A4:{$lastCol}4");
        $sheet->setCellValue('A4', 'Exportado por: Sistema de Gestión de Equipos Operacionales');
        $sheet->getStyle("A4:{$lastCol}4")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A4:{$lastCol}4")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A4:{$lastCol}4")->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF333333');
        $sheet->getRowDimension(4)->setRowHeight(20);

        // Bordes al encabezado
        $sheet->getStyle("A1:{$lastCol}4")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'],
                ],
            ],
        ]);

        // Fila 5 - Headers de tabla
        $headers = ['TIPO', 'MARCA', 'MODELO', 'SERIAL', 'CÓDIGO INTERNO', 'CAPACIDAD', 'AÑO', 'FRENTE', 'ESTADO'];
        $sheet->fromArray($headers, null, 'A5');
        $sheet->getStyle("A5:{$lastCol}5")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A5:{$lastCol}5")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF1E293B');
        $sheet->getStyle("A5:{$lastCol}5")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension(5)->setRowHeight(22);

        // Filas de data
        $row = 6;
        $query->orderBy('TIPO')->chunk(300, function ($rows) use ($sheet, $tipos, $estados, &$row) {
            foreach ($rows as $r) {
                $sheet->setCellValue("A{$row}", mb_strtoupper($tipos[$r->TIPO] ?? $r->TIPO));
                $sheet->setCellValue("B{$row}", $r->MARCA);
                $sheet->setCellValue("C{$row}", $r->MODELO);
                $sheet->setCellValue("D{$row}", $r->SERIAL);
                $sheet->setCellValue("E{$row}", $r->CODIGO_INTERNO);
                $sheet->setCellValue("F{$row}", $r->CAPACIDAD);
                $sheet->setCellValue("G{$row}", $r->ANIO);
                $sheet->setCellValue("H{$row}", optional($r->frente)->NOMBRE_FRENTE);
                $sheet->setCellValue("I{$row}", mb_strtoupper($estados[$r->ESTADO_OPERATIVO] ?? $r->ESTADO_OPERATIVO));
                $row++;
            }
        });

        if ($row > 6) {
            $sheet->getStyle("A5:{$lastCol}" . ($row - 1))->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
                ->getColor()->setARGB('FF000000');
            // Alinear TODA la data a la izquierda: por defecto PhpSpreadsheet alinea
            // los valores numéricos (AÑO, código, capacidad) a la derecha y el texto a
            // la izquierda, lo que dejaba columnas desparejas. Unificamos a la izquierda.
            $sheet->getStyle("A6:{$lastCol}" . ($row - 1))->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT)
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        }
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'Listado_Equipos_Auxiliares_' . date('Y-m-d_H-i') . '.xlsx';

        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'max-age=0');
        return $response;
    }

    /**
     * Lista TODOS los auxiliares anclados con info de su equipo host.
     * Usado por el modal "Anclaje de Auxiliares" del menu Acciones.
     */
    public function anchoredList(Request $request)
    {
        [$isLocalUser, $frentesPermitidos] = $this->userScope();
        $tipos = $this->getTiposDinamicos();

        $query = EquipoAuxiliar::with([
            'frente',
            'equipoHost.documentacion',
            'equipoHost.tipo',
            'equipoHost.frenteActual',
            'equipoHost.especificaciones',
        ])->whereNotNull('ID_EQUIPO_HOST');

        $this->scopeFrentes($query, 'ID_FRENTE_ACTUAL');

        // Filtros opcionales (heredados del listado principal): si el usuario
        // tiene un frente o tipo activo en la URL del index, el modal de
        // anclajes debe respetarlo para mostrar solo los anclajes en scope.
        if ($request->filled('id_frente') && $request->id_frente !== 'all' && $request->id_frente !== 'none') {
            if (!$isLocalUser || in_array((string) $request->id_frente, array_map('strval', $frentesPermitidos), true)) {
                $query->where('ID_FRENTE_ACTUAL', $request->id_frente);
            }
        }
        if ($request->filled('tipo') && $request->tipo !== 'all') {
            $query->where('TIPO', $request->tipo);
        }

        $items = $query->orderBy('TIPO')->get()->map(function ($a) use ($tipos) {
            $host = $a->equipoHost;
            $hostFoto = null;
            if ($host) {
                if ($host->especificaciones && $host->especificaciones->FOTO_REFERENCIAL) {
                    $hostFoto = asset($host->especificaciones->FOTO_REFERENCIAL);
                } elseif ($host->FOTO_EQUIPO) {
                    $hostFoto = asset($host->FOTO_EQUIPO);
                }
            }
            return [
                'id'             => $a->ID_AUXILIAR,
                'tipo'           => $a->TIPO,
                'tipo_label'     => $tipos[$a->TIPO] ?? $a->TIPO,
                'marca'          => $a->MARCA,
                'modelo'         => $a->MODELO,
                'serial'         => $a->SERIAL,
                'capacidad'      => $a->CAPACIDAD,
                'foto'           => $a->FOTO ? asset($a->FOTO) : null,
                'frente'         => optional($a->frente)->NOMBRE_FRENTE,
                'host_id'        => $host?->ID_EQUIPO,
                'host_codigo'    => $host?->CODIGO_PATIO,
                'host_placa'     => optional($host?->documentacion)->PLACA,
                'host_serial'    => $host?->SERIAL_CHASIS,
                'host_tipo'      => optional($host?->tipo)->nombre,
                'host_marca'     => $host?->MARCA,
                'host_modelo'    => $host?->MODELO,
                'host_frente'    => optional($host?->frenteActual)->NOMBRE_FRENTE,
                'host_foto'      => $hostFoto,
            ];
        });

        return response()->json(['success' => true, 'count' => $items->count(), 'items' => $items]);
    }

    /**
     * Exporta a XLSX la lista de auxiliares anclados a equipos host.
     * Encabezado corporativo identico al export() y al export anclajes de
     * /admin/equipos: logo izquierda, titulo central, edicion/revision/fecha
     * a la derecha, "Exportado por" en fila 4.
     */
    public function exportAnclajes(Request $request)
    {
        set_time_limit(180);
        [$isLocalUser, $frentesPermitidos] = $this->userScope();
        $tipos = $this->getTiposDinamicos();

        $query = EquipoAuxiliar::with(['equipoHost.documentacion', 'equipoHost.tipo', 'frente'])
            ->whereNotNull('ID_EQUIPO_HOST');

        $this->scopeFrentes($query, 'ID_FRENTE_ACTUAL');

        // Mismos filtros que anchoredList: respetar el frente/tipo del listado
        // principal cuando el usuario los tiene activos.
        if ($request->filled('id_frente') && $request->id_frente !== 'all' && $request->id_frente !== 'none') {
            if (!$isLocalUser || in_array((string) $request->id_frente, array_map('strval', $frentesPermitidos), true)) {
                $query->where('ID_FRENTE_ACTUAL', $request->id_frente);
            }
        }
        if ($request->filled('tipo') && $request->tipo !== 'all') {
            $query->where('TIPO', $request->tipo);
        }

        $currentDate = now()->format('d/m/Y');
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('Sistema de Gestión de Equipos Operacionales')
            ->setTitle('Anclajes de Auxiliares')
            ->setCompany('Constructora Vidalsa 27, C.A.');
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Anclajes Auxiliares');
        $lastCol = 'I'; // 9 columnas

        // Alturas de fila del encabezado (ANTES del logo para centrado vertical correcto)
        foreach ([1,2,3] as $r) $sheet->getRowDimension($r)->setRowHeight(40);

        // Logo centrado en A1:B3 (trait ExcelLogoCorporativo)
        $sheet->mergeCells('A1:B3');
        $sheet->getStyle('A1:B3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        $this->insertarLogoCorporativo($sheet, ['A','B'], [1,2,3]);

        // Titulo central (C1:E3)
        $sheet->mergeCells('C1:E3');
        $sheet->setCellValue('C1', "LISTADO DE ANCLAJES DE AUXILIARES\nAUXILIARES VINCULADOS A EQUIPOS OPERATIVOS");
        $sheet->getStyle('C1')->getAlignment()->setWrapText(true)
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('C1')->getFont()->setBold(true)->setSize(14)
            ->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $sheet->getStyle('C1:E3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        // Metadatos (F1:I3)
        foreach ([['F1','EDICION: 1'],['F2','REVISION: 0'],['F3','FECHA: '.$currentDate]] as [$cell, $text]) {
            $row = substr($cell, 1);
            $sheet->mergeCells("F{$row}:{$lastCol}{$row}");
            $sheet->setCellValue($cell, $text);
            $sheet->getStyle($cell)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $sheet->getStyle($cell)->getFont()->setBold(true)->setSize(11)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
            $sheet->getStyle("F{$row}:{$lastCol}{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        }

        // Fila 4 — Exportado por
        $sheet->mergeCells("A4:{$lastCol}4");
        $sheet->setCellValue('A4', 'Exportado por: Sistema de Gestión de Equipos Operacionales');
        $sheet->getStyle("A4:{$lastCol}4")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A4:{$lastCol}4")->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A4:{$lastCol}4")->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF333333');
        $sheet->getRowDimension(4)->setRowHeight(20);

        $sheet->getStyle("A1:{$lastCol}4")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FF000000']]],
        ]);

        // Fila 5 — Headers (orden: equipo primero, luego aux, para que el
        // merge vertical por equipo quede natural y el lector vea
        // "1 equipo => N aux"). Sin la palabra "HOST" — la columna ya queda
        // implicita por el orden y la separacion visual.
        $headers = ['EQUIPO', 'PLACA', 'SERIAL CHASIS', 'FRENTE', 'CÓDIGO PATIO', 'TIPO AUXILIAR', 'MARCA', 'MODELO', 'SERIAL AUX.'];
        $sheet->fromArray($headers, null, 'A5');
        $sheet->getStyle("A5:{$lastCol}5")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A5:{$lastCol}5")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF1E293B');
        $sheet->getStyle("A5:{$lastCol}5")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension(5)->setRowHeight(22);

        // Agrupar por host (carga unica, ordenada). Cargar todo en memoria es
        // razonable: una flota tiene ~cientos de auxiliares anclados maximo.
        $all = $query->orderBy('ID_EQUIPO_HOST')->orderBy('TIPO')->get();
        $groups = [];
        foreach ($all as $a) {
            $hid = $a->ID_EQUIPO_HOST;
            if (!isset($groups[$hid])) $groups[$hid] = ['host' => $a->equipoHost, 'auxes' => []];
            $groups[$hid]['auxes'][] = $a;
        }

        // Filas data: 1 host = N filas (una por aux). Merge vertical en columnas
        // del host para visualizar "1 sola tarjeta" por equipo.
        $row = 6;
        foreach ($groups as $g) {
            $h = $g['host'];
            $first = $row;
            foreach ($g['auxes'] as $a) {
                $sheet->setCellValue("A{$row}", $h ? trim((optional($h->tipo)->nombre ?? '') . ' ' . ($h->MARCA ?? '') . ' ' . ($h->MODELO ?? '')) : '');
                $sheet->setCellValue("B{$row}", optional(optional($h)->documentacion)->PLACA);
                $sheet->setCellValue("C{$row}", optional($h)->SERIAL_CHASIS);
                $sheet->setCellValue("D{$row}", optional(optional($h)->frenteActual)->NOMBRE_FRENTE);
                $sheet->setCellValue("E{$row}", optional($h)->CODIGO_PATIO);
                $sheet->setCellValue("F{$row}", mb_strtoupper($tipos[$a->TIPO] ?? $a->TIPO));
                $sheet->setCellValue("G{$row}", $a->MARCA);
                $sheet->setCellValue("H{$row}", $a->MODELO);
                $sheet->setCellValue("I{$row}", $a->SERIAL);
                $row++;
            }
            $last = $row - 1;
            if ($last > $first) {
                // Merge vertical de las columnas del host para que se vea como 1 sola tarjeta
                foreach (['A', 'B', 'C', 'D', 'E'] as $col) {
                    $sheet->mergeCells("{$col}{$first}:{$col}{$last}");
                }
            }
            // Alineacion de las celdas merged: centro vertical
            if ($last >= $first) {
                $sheet->getStyle("A{$first}:E{$last}")->getAlignment()
                    ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
                    ->setWrapText(true);
            }
        }

        if ($row > 6) {
            $sheet->getStyle("A5:{$lastCol}" . ($row - 1))->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
                ->getColor()->setARGB('FF000000');
            // Alinear toda la data a la izquierda (los códigos/placas numéricos se irían
            // a la derecha por defecto). Las columnas del host (A-E) ya están merged con
            // centro vertical; este left las completa sin romper el merge.
            $sheet->getStyle("A6:{$lastCol}" . ($row - 1))->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
        }
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'Anclajes_Auxiliares_' . date('Y-m-d_H-i') . '.xlsx';
        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'max-age=0');
        return $response;
    }

    /**
     * Catalogo agregado por TIPO+MARCA+MODELO+CAPACIDAD. Vista de solo lectura
     * que agrupa los auxiliares existentes por modelo y muestra una foto
     * representativa, total de unidades y conteo por estado. No requiere
     * tabla aparte: deriva todo de equipos_auxiliares (igual al concepto de
     * /admin/catalogo pero sin entidad maestro separada).
     */
    /**
     * El catálogo de auxiliares se FUSIONÓ con el de equipos: /admin/catalogo ahora
     * muestra VEHÍCULOS y AUXILIARES juntos (CaracteristicaModeloController::index, que
     * arma los items de ambas fuentes). Esta ruta redirige allí para que haya un único
     * catálogo. La subida de foto de auxiliares sigue viva en uploadCatalogoPhoto().
     */
    public function catalogo(Request $request)
    {
        return redirect()->route('catalogo.index');
    }

    /**
     * Sube/actualiza la foto representativa de un grupo del catalogo
     * (tipo+marca+modelo+anio). Misma logica que CaracteristicaModeloController:
     * convierte la imagen a WebP, sube a Google Drive (catalog_folder) y aplica
     * la URL resultante a TODAS las unidades de ese modelo+anio en una sola
     * UPDATE bulk. Permiso requerido: equipos.create.
     */
    public function uploadCatalogoPhoto(Request $request)
    {
        // Drive uploads pueden tardar — subir el limite por si el servidor
        // tiene una conexion lenta a la API.
        @set_time_limit(120);

        $validated = $request->validate([
            'tipo'   => 'required|string|max:60',
            'marca'  => 'required|string|max:80',
            'modelo' => 'required|string|max:80',
            'anio'   => 'nullable|integer',
            'foto'   => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        // Scope LOCAL: solo si tiene auxiliares de ese modelo+ano en su scope.
        [$isLocalUser, $frentesPermitidos] = $this->userScope();

        $tipoKey   = $validated['tipo'];
        $marcaKey  = mb_strtoupper(trim($validated['marca']));
        $modeloKey = mb_strtoupper(trim($validated['modelo']));
        $anio      = $validated['anio'] ?? null;

        // Construir el query del grupo (modelo+ano). Lo usamos primero para
        // obtener la(s) FOTO(s) anteriores y borrar el archivo viejo de Drive
        // antes de subir el nuevo (evita acumulacion de huerfanos).
        // MISMA normalización que catalogo() (COALESCE(NULLIF(TRIM(x),''),'—')) para que
        // el grupo matchee SIEMPRE — antes usaba COALESCE(MARCA,'—') que NO capturaba
        // marcas/modelos en cadena VACÍA (solo NULL) → la tarjeta los agrupaba como '—'
        // pero el update no encontraba filas → $updated=0 y la foto no se veía.
        $groupQ = EquipoAuxiliar::query()
            ->where('TIPO', $tipoKey)
            ->whereRaw("UPPER(COALESCE(NULLIF(TRIM(MARCA), ''), '—')) = ?", [$marcaKey])
            ->whereRaw("UPPER(COALESCE(NULLIF(TRIM(MODELO), ''), '—')) = ?", [$modeloKey]);
        if ($anio !== null) {
            $groupQ->where('ANIO', $anio);
        } else {
            $groupQ->whereNull('ANIO');
        }
        // LOCAL sin frentes asignados: no tiene auxiliares que agrupar (se conserva el 403 UX).
        if ($isLocalUser && count($frentesPermitidos) === 0) {
            return response()->json(['success' => false, 'message' => 'No tienes auxiliares en tu scope para este modelo.'], 403);
        }
        // Barrera por frente: whitelist LOCAL + blacklist de bloqueados (también GLOBAL).
        $this->scopeFrentes($groupQ, 'ID_FRENTE_ACTUAL');

        // Guard: si NO hay unidades en el grupo, no subimos nada (evita un huérfano en
        // Drive y un "éxito" silencioso de 0 unidades) y avisamos claro al usuario.
        if ((clone $groupQ)->count() === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontraron unidades de ese modelo (en tu alcance) para asociar la foto.',
            ], 422);
        }

        // FOTOs anteriores (drive ids unicos) — para borrar despues de exito
        $oldDriveIds = (clone $groupQ)
            ->whereNotNull('FOTO')->where('FOTO', '!=', '')
            ->pluck('FOTO')->unique()
            ->map(function ($url) { return basename(str_replace('/storage/google/', '', explode('?', $url)[0])); })
            ->filter()->values()->all();

        // Convertir a WebP en disco temporal
        $webpResult = $this->convertToWebp($request->file('foto'));
        $webpFile    = $webpResult['file'];
        $tempWebpPath = $webpResult['tempPath'];

        try {
            $driveService = \App\Services\GoogleDriveService::getInstance();
            $folderId = config('filesystems.disks.google.catalog_folder');
            $filename = 'aux_catalog_' . (int)(microtime(true) * 1000) . '_' . preg_replace('/[^A-Za-z0-9_]/', '_', $tipoKey . '_' . $marcaKey . '_' . $modeloKey . '_' . ($anio ?? 'NA')) . '.webp';
            $driveFile = $driveService->uploadFile($folderId, $webpFile, $filename, 'image/webp');

            if (!$driveFile || !isset($driveFile->id)) {
                return response()->json(['success' => false, 'message' => 'No se pudo subir la foto a Google Drive.'], 500);
            }

            // CRITICO: hacer el archivo publico para que <img src="https://drive.google.com/thumbnail?id=..."
            // funcione sin autenticacion. La carpeta puede no propagar permisos
            // a archivos nuevos en algunos casos (Shared Drives, propietario distinto).
            $driveService->makePublic($driveFile->id);

            // Cache busting con ?v= timestamp en la URL guardada — asi al
            // recargar la pagina, el browser no reusa la imagen vieja.
            $fotoUrl = '/storage/google/' . $driveFile->id . '?v=' . time();

            $updated = $groupQ->update(['FOTO' => $fotoUrl]);

            // Borrar las fotos anteriores de Drive (best-effort, no fatal si falla)
            foreach ($oldDriveIds as $oldId) {
                if ($oldId && $oldId !== $driveFile->id) {
                    try { $driveService->deleteFile($oldId); } catch (\Throwable $e) { /* silent */ }
                }
            }
        } finally {
            if ($tempWebpPath && file_exists($tempWebpPath)) @unlink($tempWebpPath);
        }

        \App\Models\CatalogoAuditLog::registrar(
            null,
            'upload_foto_aux',
            $modeloKey,
            $anio ? (int) $anio : null,
            ['tipo' => $tipoKey, 'marca' => $marcaKey, 'unidades' => $updated]
        );

        return response()->json([
            'success' => true,
            'message' => "Foto actualizada para {$updated} unidad(es).",
            'foto'    => $fotoUrl,
            'updated' => $updated,
        ]);
    }

    public function deleteCatalogoPhoto(Request $request)
    {
        $validated = $request->validate([
            'tipo'   => 'required|string|max:60',
            'marca'  => 'required|string|max:80',
            'modelo' => 'required|string|max:80',
            'anio'   => 'nullable|integer',
        ]);

        $tipoKey   = $validated['tipo'];
        $marcaKey  = mb_strtoupper(trim($validated['marca']));
        $modeloKey = mb_strtoupper(trim($validated['modelo']));
        $anio      = $validated['anio'] ?? null;

        $groupQ = EquipoAuxiliar::query()
            ->where('TIPO', $tipoKey)
            ->whereRaw("UPPER(COALESCE(NULLIF(TRIM(MARCA), ''), '—')) = ?", [$marcaKey])
            ->whereRaw("UPPER(COALESCE(NULLIF(TRIM(MODELO), ''), '—')) = ?", [$modeloKey]);
        if ($anio !== null) {
            $groupQ->where('ANIO', $anio);
        } else {
            $groupQ->whereNull('ANIO');
        }
        $this->scopeFrentes($groupQ, 'ID_FRENTE_ACTUAL');

        $oldDriveIds = (clone $groupQ)
            ->whereNotNull('FOTO')->where('FOTO', '!=', '')
            ->pluck('FOTO')->unique()
            ->map(function ($url) { return basename(str_replace('/storage/google/', '', explode('?', $url)[0])); })
            ->filter()->values()->all();

        if (empty($oldDriveIds)) {
            return response()->json(['success' => false, 'message' => 'Este modelo no tiene foto.'], 422);
        }

        $updated = $groupQ->update(['FOTO' => null]);

        \App\Models\CatalogoAuditLog::registrar(
            null,
            'delete_foto_aux',
            $modeloKey,
            $anio ? (int) $anio : null,
            ['tipo' => $tipoKey, 'marca' => $marcaKey, 'unidades' => $updated]
        );

        // Drive: diferido para que la respuesta sea inmediata.
        defer(function () use ($oldDriveIds) {
            foreach ($oldDriveIds as $oldId) {
                try {
                    \App\Services\GoogleDriveService::getInstance()->deleteFile($oldId);
                } catch (\Throwable $e) { /* silent */ }
            }
        });

        return response()->json(['success' => true, 'message' => "Foto eliminada de {$updated} unidad(es)."]);
    }

    // convertToWebp() viene del trait ConvertsImageToWebp (use al inicio de la clase).

    /**
     * Construye el payload de detalles para un auxiliar. Compartido entre
     * details() (endpoint AJAX legacy) y el seed inline de window.auxDetailsMap
     * en index() — asi el modal del ojo puede abrir INSTANT sin fetch.
     */
    private function buildAuxDetailsArray(EquipoAuxiliar $aux, array $tiposMap): array
    {
        $hostFoto = null;
        if ($aux->equipoHost) {
            if ($aux->equipoHost->especificaciones && $aux->equipoHost->especificaciones->FOTO_REFERENCIAL) {
                $hostFoto = asset($aux->equipoHost->especificaciones->FOTO_REFERENCIAL);
            } elseif ($aux->equipoHost->FOTO_EQUIPO) {
                $hostFoto = asset($aux->equipoHost->FOTO_EQUIPO);
            }
        }
        return [
            'id'             => $aux->ID_AUXILIAR,
            'tipo'           => $aux->TIPO,
            'tipo_label'     => $tiposMap[$aux->TIPO] ?? $aux->TIPO,
            'marca'          => $aux->MARCA,
            'modelo'         => $aux->MODELO,
            'serial'         => $aux->SERIAL,
            'codigo_interno' => $aux->CODIGO_INTERNO,
            'capacidad'      => $aux->CAPACIDAD,
            'anio'           => $aux->ANIO,
            'estado'         => $aux->ESTADO_OPERATIVO,
            'estado_label'   => EquipoAuxiliar::estadosLabel()[$aux->ESTADO_OPERATIVO] ?? $aux->ESTADO_OPERATIVO,
            'observaciones'  => $aux->OBSERVACIONES,
            'foto'           => $aux->FOTO,
            'foto_drive_id'  => $aux->FOTO ? basename(str_replace('/storage/google/', '', $aux->FOTO)) : null,
            'link_doc_propiedad'     => $aux->LINK_DOC_PROPIEDAD ?? null,
            'link_certificado'       => $aux->LINK_CERTIFICADO ?? null,
            'fecha_vencimiento_cert' => $aux->FECHA_VENCIMIENTO_CERT
                ? (string) $aux->FECHA_VENCIMIENTO_CERT->format('Y-m-d')
                : null,
            'frente'         => optional($aux->frente)->NOMBRE_FRENTE,
            'host_id'            => $aux->ID_EQUIPO_HOST,
            'host_codigo'        => optional($aux->equipoHost)->CODIGO_PATIO,
            'host_placa'         => optional(optional($aux->equipoHost)->documentacion)->PLACA,
            'host_serial_chasis' => optional($aux->equipoHost)->SERIAL_CHASIS,
            'host_tipo'          => optional(optional($aux->equipoHost)->tipo)->nombre,
            'host_marca'         => optional($aux->equipoHost)->MARCA,
            'host_modelo'        => optional($aux->equipoHost)->MODELO,
            'host_foto'          => $hostFoto,
            'host_frente'        => optional(optional($aux->equipoHost)->frenteActual)->NOMBRE_FRENTE,
            'creado_por'     => optional($aux->creador)->NOMBRE_COMPLETO,
            'created_at'     => optional($aux->created_at)->format('d/m/Y H:i'),
            'edit_url'       => route('equipos-auxiliares.edit', $aux->ID_AUXILIAR),
            'can_edit'       => auth()->user() && auth()->user()->can('equipos.edit'),
            'can_assign'        => auth()->user() && auth()->user()->can('equipos.assign'),
            'can_upload_pdf'    => auth()->user() && auth()->user()->can('user.edit'),
            // Ubicación específica dentro del frente (para pre-cargar el modal de asignación)
            'detalle_ubicacion' => $aux->DETALLE_UBICACION_ACTUAL ?? '',
        ];
    }

    /**
     * Detalles completos del auxiliar (para modal de "Ver detalles").
     * Endpoint legacy: el index() ahora ya pre-carga el mapa de detalles via
     * window.auxDetailsMap y el modal abre instant sin fetch. Este endpoint
     * sigue activo como fallback (cache miss / refresco manual).
     */
    public function details($id)
    {
        $aux = EquipoAuxiliar::with([
            'frente',
            'equipoHost.documentacion',
            'equipoHost.tipo',
            'equipoHost.especificaciones',
            'equipoHost.frenteActual',
            'creador',
        ])->findOrFail($id);
        $this->authorizeAuxScope($aux);
        $tiposMap = $this->getTiposDinamicos();
        return response()->json($this->buildAuxDetailsArray($aux, $tiposMap));
    }

    /**
     * Lista los equipos auxiliares anclados a un equipo host especifico.
     * Usado por el modal de detalles de /admin/equipos para mostrar los
     * auxiliares en la seccion "Sub-activos vinculados".
     */
    public function byHost($hostId)
    {
        $auxQuery = EquipoAuxiliar::where('ID_EQUIPO_HOST', $hostId);
        $this->scopeFrentes($auxQuery, 'ID_FRENTE_ACTUAL');
        $auxiliares = $auxQuery
            ->orderBy('TIPO')
            ->get()
            ->map(function ($a) {
                return [
                    'id'        => $a->ID_AUXILIAR,
                    'tipo'      => $a->TIPO,
                    'serial'    => $a->SERIAL,
                    'marca'     => $a->MARCA,
                    'modelo'    => $a->MODELO,
                    'capacidad' => $a->CAPACIDAD,
                    'anio'      => $a->ANIO,
                    'estado'    => $a->ESTADO_OPERATIVO,
                    // Foto del auxiliar para el modal de detalles del equipo host
                    // (seccion "Sub-activos vinculados"). Null si no hay foto.
                    'foto'      => $a->FOTO ? asset($a->FOTO) : null,
                ];
            });

        return response()->json(['ok' => true, 'data' => $auxiliares]);
    }

    // ═══════════════════════════════════════════════════════════
    // CRUD
    // ═══════════════════════════════════════════════════════════
    public function create()
    {
        $frentesQuery = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE');
        $this->scopeFrentes($frentesQuery, 'ID_FRENTE');
        $frentes = $frentesQuery->get();
        // TIPOS dinamicos: base del enum + los tipos custom guardados en DB.
        $tipos = $this->getTiposDinamicos();
        $estados = EquipoAuxiliar::estadosLabel();
        $auxiliar = new EquipoAuxiliar();
        return view('admin.equipos_auxiliares.create', compact('auxiliar', 'frentes', 'tipos', 'estados'));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        // LOCAL: solo puede crear auxiliares en sus frentes asignados.
        [$isLocalUser, $frentesPermitidos] = $this->userScope();
        if ($isLocalUser && !empty($data['ID_FRENTE_ACTUAL'])) {
            if (!in_array((string) $data['ID_FRENTE_ACTUAL'], array_map('strval', $frentesPermitidos), true)) {
                abort(403, 'No tiene permisos para registrar auxiliares en este frente.');
            }
        }
        // Nadie (ni GLOBAL) puede crear en un frente BLOQUEADO.
        if (!empty($data['ID_FRENTE_ACTUAL']) && $this->frenteEstaBloqueado($data['ID_FRENTE_ACTUAL'])) {
            abort(403, 'No tiene permisos para registrar auxiliares en este frente.');
        }

        $data['CREADO_POR'] = auth()->id();
        // Todos los campos de texto (select o input) se guardan en MAYUSCULAS
        // para consistencia (reportes, busquedas, filtros case-insensitive).
        foreach (['MARCA', 'MODELO', 'SERIAL', 'CODIGO_INTERNO', 'CAPACIDAD', 'OBSERVACIONES'] as $f) {
            if (!empty($data[$f])) $data[$f] = mb_strtoupper(trim($data[$f]));
        }

        // Remover claves de archivos del array antes de create (Eloquent no sabe manejarlos).
        unset($data['doc_propiedad'], $data['certificado']);
        // Normalizar nombre de campo fecha.
        if (!empty($data['fecha_vencimiento_cert'])) {
            $data['FECHA_VENCIMIENTO_CERT'] = $data['fecha_vencimiento_cert'];
        }
        unset($data['fecha_vencimiento_cert']);

        $auxiliar = EquipoAuxiliar::create($data);

        // Guardar archivos PDF (si vinieron) en storage/app/public/equipos_auxiliares/{id}/
        $this->storeAuxDocs($request, $auxiliar);

        if ($request->wantsJson()) {
            return response()->json([
                'success'  => true,
                'message'  => 'Equipo auxiliar registrado correctamente.',
                'redirect' => route('equipos-auxiliares.index'),
            ]);
        }
        return redirect()->route('equipos-auxiliares.index')->with('success', 'Equipo auxiliar registrado correctamente.');
    }

    public function edit($id)
    {
        $auxiliar = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($auxiliar);
        $frentesQuery = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE');
        $this->scopeFrentes($frentesQuery, 'ID_FRENTE');
        $frentes = $frentesQuery->get();
        // TIPOS dinamicos: base del enum + los tipos custom guardados en DB.
        $tipos    = $this->getTiposDinamicos();
        $estados  = EquipoAuxiliar::estadosLabel();
        return view('admin.equipos_auxiliares.edit', compact('auxiliar', 'frentes', 'tipos', 'estados'));
    }

    /**
     * Combina los TIPOS del enum (con labels bonitos) con los tipos custom
     * que el usuario haya registrado (existentes en la BD). Para los custom,
     * genera un label legible a partir del codigo (ej. "GENERADOR_DIESEL" ->
     * "Generador Diesel"). Asi el filtro y los comboboxes reflejan siempre
     * el estado real de tipos en uso, no una lista hardcoded.
     */
    private function getTiposDinamicos(): array
    {
        $tipos = EquipoAuxiliar::tiposLabel();
        $tiposEnDB = EquipoAuxiliar::select('TIPO')
            ->whereNotNull('TIPO')->where('TIPO', '!=', '')
            ->distinct()->orderBy('TIPO')->pluck('TIPO');
        foreach ($tiposEnDB as $t) {
            if (!isset($tipos[$t])) {
                $tipos[$t] = ucwords(mb_strtolower(str_replace('_', ' ', $t)));
            }
        }
        return $tipos;
    }

    public function update(Request $request, $id)
    {
        $auxiliar = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($auxiliar);
        $data = $this->validateData($request, false);

        // El frente NO se cambia por edición de datos: reasignar un auxiliar de frente
        // es trabajo de MOVILIZACIÓN (que además deja CONFIRMADO_EN_SITIO=0). El selector
        // de frente del formulario solo aplica al CREAR; en edición va bloqueado. Lo
        // descartamos aquí para conservar SIEMPRE el frente y la confirmación actuales,
        // incluso si alguien manipulara el form (por eso ya no hacen falta los checks de
        // reasignación por scope/bloqueo: el frente simplemente no se toca).
        unset($data['ID_FRENTE_ACTUAL']);

        // Todos los campos de texto se normalizan a MAYUSCULAS (consistencia
        // con store y con el resto de la app).
        foreach (['MARCA', 'MODELO', 'SERIAL', 'CODIGO_INTERNO', 'CAPACIDAD', 'OBSERVACIONES'] as $f) {
            if (!empty($data[$f])) $data[$f] = mb_strtoupper(trim($data[$f]));
        }

        unset($data['doc_propiedad'], $data['certificado']);
        if (array_key_exists('fecha_vencimiento_cert', $data)) {
            $data['FECHA_VENCIMIENTO_CERT'] = $data['fecha_vencimiento_cert'] ?: null;
            unset($data['fecha_vencimiento_cert']);
        }

        $auxiliar->update($data);

        $this->storeAuxDocs($request, $auxiliar);

        if ($request->wantsJson()) {
            return response()->json([
                'success'  => true,
                'message'  => 'Equipo auxiliar actualizado correctamente.',
                'redirect' => route('equipos-auxiliares.index'),
            ]);
        }
        return redirect()->route('equipos-auxiliares.index')->with('success', 'Equipo auxiliar actualizado correctamente.');
    }

    public function destroy(Request $request, $id)
    {
        $auxiliar = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($auxiliar);
        // Soft-delete con auditoria de quien borro (mismo patron que equipos).
        $auxiliar->deleted_by = auth()->id();
        $auxiliar->save();
        $auxiliar->delete();

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }
        return redirect()->route('equipos-auxiliares.index')->with('success', 'Equipo auxiliar eliminado.');
    }

    /**
     * Borrado masivo (soft-delete) por IDs. Usado desde el menu Acciones del
     * index cuando hay auxiliares seleccionados via checkbox.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|exists:equipos_auxiliares,ID_AUXILIAR',
        ]);

        $userId = auth()->id();
        $borrados = 0;
        DB::transaction(function () use ($request, $userId, &$borrados) {
            $auxiliares = EquipoAuxiliar::whereIn('ID_AUXILIAR', $request->ids)->lockForUpdate()->get();
            foreach ($auxiliares as $aux) {
                $aux->deleted_by = $userId;
                $aux->save();
                $aux->delete();
                $borrados++;
            }
        });

        return response()->json([
            'success' => true,
            'message' => "Se eliminaron {$borrados} auxiliar(es). Recuperables desde la papelera.",
            'count'   => $borrados,
        ]);
    }

    /**
     * Lista de auxiliares en papelera (soft-deleted) con metadata de quien
     * borro y cuando — para mostrar en /admin/historial-documentos. Endpoint
     * JSON simple, no pagina (la papelera no suele crecer demasiado).
     */
    public function papelera(Request $request)
    {
        $items = EquipoAuxiliar::onlyTrashed()
            ->with(['frente:ID_FRENTE,NOMBRE_FRENTE'])
            ->orderByDesc('deleted_at')
            ->get();

        $userIds = $items->pluck('deleted_by')->filter()->unique()->values()->all();
        $usuarios = !empty($userIds)
            ? \App\Models\Usuario::whereIn('ID_USUARIO', $userIds)
                ->pluck('NOMBRE_COMPLETO', 'ID_USUARIO')->toArray()
            : [];

        $tipos = $this->getTiposDinamicos();
        $rows = $items->map(function ($a) use ($usuarios, $tipos) {
            $fotoDriveId = $a->FOTO ? basename(str_replace('/storage/google/', '', explode('?', $a->FOTO)[0])) : null;
            return [
                'id'             => $a->ID_AUXILIAR,
                'tipo'           => $tipos[$a->TIPO] ?? $a->TIPO,
                'marca'          => $a->MARCA,
                'modelo'         => $a->MODELO,
                'serial'         => $a->SERIAL,
                'frente'         => optional($a->frente)->NOMBRE_FRENTE,
                'foto_drive_id'  => $fotoDriveId,
                'deleted_at'     => optional($a->deleted_at)->format('d/m/Y H:i'),
                'deleted_by'     => $a->deleted_by ? ($usuarios[$a->deleted_by] ?? '#' . $a->deleted_by) : null,
            ];
        });

        return response()->json(['success' => true, 'items' => $rows]);
    }

    /**
     * Restaura un auxiliar borrado (soft-delete -> activo). Limpia deleted_by
     * para que el audit trail quede consistente.
     */
    public function restoreAuxiliar(Request $request, $id)
    {
        $aux = EquipoAuxiliar::onlyTrashed()->where('ID_AUXILIAR', $id)->firstOrFail();
        $aux->deleted_by = null;
        $aux->save();
        $aux->restore();

        return response()->json(['success' => true, 'message' => 'Auxiliar restaurado.']);
    }

    // ═══════════════════════════════════════════════════════════
    // ANCHOR 1:N (tope 2 auxiliares por equipo host)
    // ═══════════════════════════════════════════════════════════
    public function anchor(Request $request, $id)
    {
        $request->validate([
            'id_equipo_host' => 'required|exists:equipos,ID_EQUIPO',
        ]);

        try {
            DB::transaction(function () use ($request, $id) {
                $auxiliar = EquipoAuxiliar::lockForUpdate()->findOrFail($id);
                $this->authorizeAuxScope($auxiliar);
                $hostId   = $request->id_equipo_host;

                // Verificar tope: no mas de N auxiliares por equipo host (excluyendo este mismo)
                $actuales = EquipoAuxiliar::where('ID_EQUIPO_HOST', $hostId)
                    ->where('ID_AUXILIAR', '!=', $id)
                    ->lockForUpdate()
                    ->count();

                if ($actuales >= EquipoAuxiliar::ANCHOR_MAX_PER_HOST) {
                    throw new \RuntimeException(
                        'El equipo host ya tiene el maximo permitido de ' .
                        EquipoAuxiliar::ANCHOR_MAX_PER_HOST . ' auxiliares anclados.'
                    );
                }

                $auxiliar->update(['ID_EQUIPO_HOST' => $hostId]);
            });

            return response()->json(['success' => true, 'message' => 'Equipo auxiliar anclado correctamente.']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('EquipoAuxiliar anchor falló: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al anclar el equipo.'], 500);
        }
    }

    public function unanchor($id)
    {
        $auxiliar = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($auxiliar);
        $auxiliar->update(['ID_EQUIPO_HOST' => null]);
        return response()->json(['success' => true, 'message' => 'Equipo auxiliar desanclado.']);
    }

    /**
     * Movilizacion masiva: reasigna multiples auxiliares al mismo ID_FRENTE_ACTUAL.
     * Acepta array de IDs + frente destino (o creacion de frente nuevo via NOMBRE).
     */
    public function bulkMove(Request $request)
    {
        $data = $request->validate([
            'ids'            => 'required|array|min:1',
            'ids.*'          => 'integer|exists:equipos_auxiliares,ID_AUXILIAR',
            'id_frente'      => 'nullable|exists:frentes_trabajo,ID_FRENTE',
            'destination'    => 'nullable|string|max:150',
            'nombre_frente'  => 'nullable|string|max:150', // legacy alias
            'ubicacion'      => 'nullable|string|max:150',
            'generar_pdf'    => 'nullable|boolean',
        ]);

        // Resolver el frente destino — soporta 3 modos:
        //  1) id_frente: id existente (modo viejo)
        //  2) destination: nombre del frente; si existe se usa, si no se crea
        //     (firstOrCreate). Si lo crea, requiere ubicacion.
        //  3) nombre_frente: alias historico de destination (compat)
        $frenteId = $data['id_frente'] ?? null;
        $destination = trim($data['destination'] ?? $data['nombre_frente'] ?? '');
        $ubicacion = trim($data['ubicacion'] ?? '');
        $generarPdf = (bool) ($data['generar_pdf'] ?? false);

        if (!$frenteId && $destination !== '') {
            $nombre = mb_strtoupper($destination);
            $frenteExistente = FrenteTrabajo::whereRaw('UPPER(NOMBRE_FRENTE) = ?', [$nombre])->first();
            if ($frenteExistente) {
                $frenteId = $frenteExistente->ID_FRENTE;
            } else {
                // Frente nuevo: requerimos ubicacion para que los informes
                // futuros tengan la zona/municipio del destino.
                if ($ubicacion === '') {
                    return response()->json([
                        'success' => false,
                        'message' => 'El frente "' . $nombre . '" no existe. Indica su ubicación (zona, municipio o estado) para crearlo.',
                    ], 422);
                }
                $nuevo = FrenteTrabajo::create([
                    'NOMBRE_FRENTE'  => $nombre,
                    'ESTATUS_FRENTE' => 'ACTIVO',
                    'UBICACION'      => mb_strtoupper($ubicacion),
                ]);
                $frenteId = $nuevo->ID_FRENTE;
            }
        }

        if (!$frenteId) {
            return response()->json(['success' => false, 'message' => 'Frente destino requerido.'], 422);
        }

        // LOCAL: solo puede operar sobre auxiliares de sus frentes y el destino
        // tambien debe estar en su scope.
        [$isLocalUser, $frentesPermitidos] = $this->userScope();
        $bulkQuery = EquipoAuxiliar::whereIn('ID_AUXILIAR', $data['ids']);
        if ($isLocalUser && !in_array((string) $frenteId, array_map('strval', $frentesPermitidos), true)) {
            return response()->json([
                'success' => false,
                'message' => 'No tiene permisos para movilizar a este frente.'
            ], 403);
        }
        // Nadie (ni GLOBAL) puede movilizar HACIA un frente bloqueado.
        if ($this->frenteEstaBloqueado($frenteId)) {
            return response()->json([
                'success' => false,
                'message' => 'No tiene permisos para movilizar a este frente.'
            ], 403);
        }
        // Solo se opera sobre auxiliares VISIBLES (whitelist LOCAL + blacklist bloqueados):
        // no se pueden mover en masa auxiliares de frentes fuera del scope/bloqueados.
        $this->scopeFrentes($bulkQuery, 'ID_FRENTE_ACTUAL');

        // Capturamos el frente origen ANTES del UPDATE para el historial.
        // Sin esto, despues del update todos los registros tendrian
        // ID_FRENTE_ORIGEN = ID_FRENTE_DESTINO (porque ya cambio).
        $userEmail = optional(auth()->user())->CORREO_ELECTRONICO ?? 'SISTEMA';

        // Envolvemos en transaccion porque MovilizacionController::generateNextCodigoControl() hace lockForUpdate()
        $result = DB::transaction(function () use ($bulkQuery, $frenteId, $generarPdf, $userEmail) {
            $auxParaMover = (clone $bulkQuery)->lockForUpdate()->get(['ID_AUXILIAR', 'ID_FRENTE_ACTUAL']);
            // Despacho: el auxiliar queda PENDIENTE de confirmar en el frente destino
            // (se tilda al llegar con el chip, igual que equipos). CONFIRMADO_EN_SITIO -> 0.
            $affected = $bulkQuery->update(['ID_FRENTE_ACTUAL' => $frenteId, 'CONFIRMADO_EN_SITIO' => 0]);

            $now = now();
            $codigoControl = $generarPdf ? \App\Http\Controllers\MovilizacionController::generateNextCodigoControl() : null;
            $movilizacionIds = [];

            foreach ($auxParaMover as $aux) {
                $mov = \App\Models\Movilizacion::create([
                    'CODIGO_CONTROL'    => $codigoControl,
                    'ID_EQUIPO'         => null,
                    'ID_AUXILIAR'       => $aux->ID_AUXILIAR,
                    'ID_FRENTE_ORIGEN'  => $aux->ID_FRENTE_ACTUAL ?? 1,
                    'ID_FRENTE_DESTINO' => $frenteId,
                    'FECHA_DESPACHO'    => $generarPdf ? $now : null,
                    'TIPO_MOVIMIENTO'   => $generarPdf ? 'DESPACHO' : 'ACT.',
                    'USUARIO_REGISTRO'  => $userEmail,
                ]);
                $movilizacionIds[] = $mov->ID_MOVILIZACION;
            }

            return [
                'affected' => $affected,
                'codigoControl' => $codigoControl,
                'movilizacionIds' => $movilizacionIds,
            ];
        });

        return response()->json([
            'success'           => true,
            'message'           => "Se movilizaron {$result['affected']} equipo(s) auxiliar(es) al frente destino.",
            'affected'          => $result['affected'],
            'count'             => $result['affected'],
            'codigo_control'    => $result['codigoControl'],
            'generar_pdf'       => $generarPdf,
            'movilizacion_ids'  => $result['movilizacionIds'],
        ]);
    }




    /**
     * Cambio rapido de estado operativo (inline desde la tabla del index).
     * Validacion minima: solo ESTADO_OPERATIVO. No toca otros campos required.
     */
    /**
     * Confirma (o quita) la presencia física del auxiliar en su frente actual.
     * Espeja EquipoController::confirmarSitio. Mismo ciclo: el despacho deja el
     * auxiliar en 0 (pendiente) y aquí se tilda en 1 (confirmado en sitio).
     */
    public function confirmarSitio(Request $request, $id)
    {
        $request->validate([
            'confirmado' => 'required|boolean',
        ]);
        $aux = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($aux);
        $aux->CONFIRMADO_EN_SITIO = $request->boolean('confirmado') ? 1 : 0;
        $aux->save();

        return response()->json([
            'success'    => true,
            'confirmado' => (int) $aux->CONFIRMADO_EN_SITIO,
        ]);
    }

    public function changeStatus(Request $request, $id)
    {
        $estados = array_keys(EquipoAuxiliar::estadosLabel());
        $request->validate([
            'ESTADO_OPERATIVO' => 'required|string|in:' . implode(',', $estados),
        ]);

        $aux = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($aux);

        // Si el equipo tiene un reporte de falla ABIERTO, su estado lo gobierna ese
        // reporte (quedó INOPERATIVO al crearlo): no se cambia a mano desde aquí. Para
        // cambiarlo hay que CERRAR el reporte (lo que lo devuelve a OPERATIVO).
        // Devolvemos los datos del reporte para que el front muestre el modal de cierre.
        $falla = \App\Models\Falla::where('ACTIVO_TIPO', 'equipo_auxiliar')
            ->where('ACTIVO_ID', $aux->ID_AUXILIAR)
            ->where('ESTADO_REPORTE', 'abierto')
            ->latest('FECHA_EMISION')
            ->first();

        if ($falla) {
            $ident = $aux->SERIAL ?: ($aux->CODIGO_INTERNO ?: trim(($aux->MARCA ?? '') . ' ' . ($aux->MODELO ?? '')));
            return response()->json([
                'success'       => false,
                'message'       => 'Este equipo tiene un reporte de falla abierto. Para cambiar su estado debes cerrar el reporte.',
                'falla_abierta' => [
                    'id'     => $falla->ID_FALLA,
                    'codigo' => $falla->CODIGO_REPORTE,
                    'tipo'   => $falla->TIPO_REPORTE,
                    'equipo' => $ident,
                ],
            ], 409);
        }

        $aux->ESTADO_OPERATIVO = $request->input('ESTADO_OPERATIVO');
        $aux->save();

        return response()->json([
            'success' => true,
            'message' => 'Estado actualizado.',
            'estado'  => $aux->ESTADO_OPERATIVO,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // SEARCH — autocomplete para anclaje
    // ═══════════════════════════════════════════════════════════
    public function search(Request $request)
    {
        $q = trim($request->get('q', ''));
        if (strlen($q) < 2) return response()->json([]);

        $tiposMap = $this->getTiposDinamicos();
        $searchQuery = EquipoAuxiliar::with('equipoHost.documentacion')
            ->where(function ($w) use ($q) {
                $w->where('SERIAL', 'like', "%{$q}%")
                  ->orWhere('CODIGO_INTERNO', 'like', "%{$q}%")
                  ->orWhere('MARCA', 'like', "%{$q}%")
                  ->orWhere('MODELO', 'like', "%{$q}%");
            });
        $this->scopeFrentes($searchQuery, 'ID_FRENTE_ACTUAL');
        $results = $searchQuery
            ->limit(20)
            ->get()
            ->map(function ($a) use ($tiposMap) {
                return [
                    'id'          => $a->ID_AUXILIAR,
                    'tipo'        => $a->TIPO,
                    'tipo_label'  => $tiposMap[$a->TIPO] ?? $a->TIPO,
                    'marca'       => $a->MARCA,
                    'modelo'      => $a->MODELO,
                    'serial'      => $a->SERIAL,
                    'host_id'     => $a->ID_EQUIPO_HOST,
                    'host_codigo' => optional($a->equipoHost)->CODIGO_PATIO,
                    'host_placa'  => optional(optional($a->equipoHost)->documentacion)->PLACA,
                ];
            });

        return response()->json($results);
    }

    public function searchHosts(Request $request)
    {
        $q = trim($request->get('q', ''));
        $recommend = $request->boolean('recommend');

        // Modo busqueda: requiere min 2 chars. Modo recommend: vacio OK.
        if (!$recommend && strlen($q) < 2) return response()->json([]);

        // Busqueda ampliada: serial chasis, serial motor, placa (docum.),
        // codigo patio, marca y modelo. Join con documentacion para PLACA.
        $hostQuery = Equipo::with('documentacion', 'tipo', 'equiposAuxiliares', 'especificaciones', 'frenteActual')
            ->leftJoin('documentacion as doc_host', 'equipos.ID_EQUIPO', '=', 'doc_host.ID_EQUIPO')
            ->select('equipos.*');
        $this->scopeFrentes($hostQuery, 'equipos.ID_FRENTE_ACTUAL');

        // Modo recomendacion: FLOTA LIVIANA con cupo disponible. Los auxiliares
        // (compresores, soldadoras, etc.) tipicamente se anclan a unidades de
        // flota liviana que las transportan, asi que sugerimos esas primero.
        if ($recommend) {
            $hostQuery->where('equipos.CATEGORIA_FLOTA', 'FLOTA LIVIANA');
        } elseif ($q !== '') {
            $hostQuery->where(function ($w) use ($q) {
                $w->where('equipos.CODIGO_PATIO', 'like', "%{$q}%")
                  ->orWhere('equipos.SERIAL_CHASIS', 'like', "%{$q}%")
                  ->orWhere('equipos.SERIAL_DE_MOTOR', 'like', "%{$q}%")
                  ->orWhere('equipos.MARCA', 'like', "%{$q}%")
                  ->orWhere('equipos.MODELO', 'like', "%{$q}%")
                  ->orWhere('doc_host.PLACA', 'like', "%{$q}%");
            });
        }

        $results = $hostQuery
            ->distinct()
            ->orderBy('equipos.CODIGO_PATIO')
            ->limit($recommend ? 30 : 20)
            ->get()
            ->map(function ($e) {
                // Foto: prioriza la del catalogo del modelo (FOTO_REFERENCIAL),
                // cae a la propia del equipo (FOTO_EQUIPO).
                $foto = null;
                if ($e->especificaciones && $e->especificaciones->FOTO_REFERENCIAL) {
                    $foto = asset($e->especificaciones->FOTO_REFERENCIAL);
                } elseif ($e->FOTO_EQUIPO) {
                    $foto = asset($e->FOTO_EQUIPO);
                }
                return [
                    'id'             => $e->ID_EQUIPO,
                    'codigo'         => $e->CODIGO_PATIO,
                    'placa'          => optional($e->documentacion)->PLACA,
                    'serial_chasis'  => $e->SERIAL_CHASIS,
                    'serial_motor'   => $e->SERIAL_DE_MOTOR,
                    'tipo'           => optional($e->tipo)->nombre,
                    'marca'          => $e->MARCA,
                    'modelo'         => $e->MODELO,
                    'marca_modelo'   => trim(($e->MARCA ?? '') . ' ' . ($e->MODELO ?? '')),
                    'foto'           => $foto,
                    'frente_nombre'  => optional($e->frenteActual)->NOMBRE_FRENTE,
                    'auxiliares_anclados' => $e->equiposAuxiliares->count(),
                    'disponible'     => $e->equiposAuxiliares->count() < EquipoAuxiliar::ANCHOR_MAX_PER_HOST,
                ];
            });

        return response()->json($results);
    }


    /**
     * Sube/reemplaza un PDF puntual desde el modal de detalles.
     * Endpoint: POST /admin/equipos-auxiliares/{id}/upload-doc
     * Permiso: equipos.edit (gateado en routes/web.php).
     * doc_type: propiedad | certificado
     */
    public function uploadDoc(Request $request, $id)
    {
        $request->validate([
            'file'     => 'required|file|mimes:pdf|max:51200',
            'doc_type' => 'required|in:propiedad,certificado',
            'fecha_vencimiento_cert' => 'nullable|date',
        ]);

        $aux  = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($aux);
        $type = $request->input('doc_type');
        $file = $request->file('file');
        $name = $type . '_' . time() . '.pdf';
        $path = $file->storeAs('equipos_auxiliares/' . $aux->ID_AUXILIAR, $name, 'public');

        if ($type === 'propiedad') {
            $aux->LINK_DOC_PROPIEDAD = '/storage/' . $path;
        } else {
            $aux->LINK_CERTIFICADO = '/storage/' . $path;
            if ($request->filled('fecha_vencimiento_cert')) {
                $aux->FECHA_VENCIMIENTO_CERT = $request->input('fecha_vencimiento_cert');
            }
        }
        $aux->save();

        return response()->json([
            'success' => true,
            'message' => 'PDF cargado correctamente.',
            'link'    => $type === 'propiedad' ? $aux->LINK_DOC_PROPIEDAD : $aux->LINK_CERTIFICADO,
            'fecha_vencimiento_cert' => $aux->FECHA_VENCIMIENTO_CERT,
        ]);
    }

    /**
     * Elimina un documento (propiedad/certificado) del auxiliar: borra el archivo de
     * storage/public y limpia la columna en BD (+ la fecha de venc. si es certificado).
     * Espejo de uploadDoc. Permiso: super.admin (igual que equipos.deleteDoc).
     */
    public function deleteDoc(Request $request, $id)
    {
        $request->validate([
            'doc_type' => 'required|in:propiedad,certificado',
        ]);

        $aux = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($aux);
        $type = $request->input('doc_type');
        $col  = $type === 'propiedad' ? 'LINK_DOC_PROPIEDAD' : 'LINK_CERTIFICADO';

        // Borrar el archivo físico (los docs de auxiliares viven en disco 'public',
        // a diferencia de equipos que usan Drive). A prueba de fallos: si el archivo
        // ya no existe, igual limpiamos la columna en BD.
        $link = $aux->$col;
        if ($link && str_starts_with($link, '/storage/')) {
            $rel = ltrim(substr($link, strlen('/storage/')), '/');
            \Illuminate\Support\Facades\Storage::disk('public')->delete($rel);
        }

        $aux->$col = null;
        if ($type === 'certificado') {
            $aux->FECHA_VENCIMIENTO_CERT = null; // sin certificado no tiene sentido la fecha
        }
        $aux->save();

        return response()->json([
            'success' => true,
            'message' => 'Documento eliminado correctamente.',
        ]);
    }

    /**
     * Actualiza solo la fecha de vencimiento del certificado, sin reemplazar
     * el PDF. Endpoint llamado desde el visor del PDF. Permiso: user.edit.
     */
    public function updateCertExpiry(Request $request, $id)
    {
        $request->validate([
            'fecha_vencimiento_cert' => 'nullable|date',
        ]);
        $aux = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($aux);
        $aux->FECHA_VENCIMIENTO_CERT = $request->input('fecha_vencimiento_cert') ?: null;
        $aux->save();
        return response()->json([
            'success' => true,
            'message' => 'Fecha de vencimiento actualizada.',
            'fecha_vencimiento_cert' => $aux->FECHA_VENCIMIENTO_CERT,
        ]);
    }

    /**
     * Devuelve la metadata del aux para el panel lateral del visor PDF.
     * Estructura imitando EquipoController::metadata: {success, data}.
     * - propiedad: SERIAL, MARCA, MODELO, CAPACIDAD, ANIO, TIPO (datos de la
     *   ficha del aux — no hay tabla documentacion paralela como en vehiculos).
     * - certificado: FECHA_VENCIMIENTO_CERT + datos basicos para contexto.
     */
    public function metadata(Request $request, $id)
    {
        $aux  = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($aux);
        $type = $request->input('type');
        $data = [];

        switch ($type) {
            case 'propiedad':
                $data = [
                    'serial'    => $aux->SERIAL ?? '',
                    'codigo'    => $aux->CODIGO_INTERNO ?? '',
                    'tipo'      => $aux->TIPO ?? '',
                    'marca'     => $aux->MARCA ?? '',
                    'modelo'    => $aux->MODELO ?? '',
                    'capacidad' => $aux->CAPACIDAD ?? '',
                    'anio'      => $aux->ANIO ?? '',
                ];
                break;

            case 'certificado':
                $data = [
                    'fecha_vencimiento' => $aux->FECHA_VENCIMIENTO_CERT
                        ? \Carbon\Carbon::parse($aux->FECHA_VENCIMIENTO_CERT)->format('Y-m-d')
                        : '',
                    'serial'    => $aux->SERIAL ?? '',
                    'tipo'      => $aux->TIPO ?? '',
                    'marca'     => $aux->MARCA ?? '',
                    'modelo'    => $aux->MODELO ?? '',
                    'capacidad' => $aux->CAPACIDAD ?? '',
                ];
                break;
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Guarda los datos editados desde el panel lateral del visor PDF.
     * doc_type=propiedad => actualiza SERIAL/MARCA/MODELO/CAPACIDAD/ANIO/TIPO.
     * doc_type=certificado => actualiza FECHA_VENCIMIENTO_CERT (+ datos basicos
     * por simetria con propiedad). Permiso: user.edit (gateado en routes/web.php).
     */
    public function updateMetadata(Request $request, $id)
    {
        $aux = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($aux);
        $type = $request->input('doc_type');

        $upd = [];
        if ($type === 'propiedad') {
            $upd['SERIAL']         = mb_strtoupper(trim((string) $request->input('serial', '')));
            $upd['CODIGO_INTERNO'] = mb_strtoupper(trim((string) $request->input('codigo', '')));
            $upd['TIPO']           = mb_strtoupper(trim((string) $request->input('tipo', '')));
            $upd['MARCA']          = mb_strtoupper(trim((string) $request->input('marca', '')));
            $upd['MODELO']         = mb_strtoupper(trim((string) $request->input('modelo', '')));
            $upd['CAPACIDAD']      = mb_strtoupper(trim((string) $request->input('capacidad', '')));
            $anio = trim((string) $request->input('anio', ''));
            $upd['ANIO']           = $anio === '' ? null : (int) $anio;
        } elseif ($type === 'certificado') {
            $upd['FECHA_VENCIMIENTO_CERT'] = $request->input('fecha_vencimiento') ?: null;
        } else {
            return response()->json(['success' => false, 'message' => 'Tipo no valido'], 400);
        }

        $aux->update($upd);

        return response()->json([
            'success' => true,
            'message' => 'Datos actualizados.',
            'data'    => $upd,
        ]);
    }

    /**
     * Asigna DETALLE_UBICACION_ACTUAL a un lote de auxiliares (mismo patron
     * que EquipoController::bulkUbicacion). Valida:
     *  - Permiso equipos.assign.
     *  - Que TODOS los auxiliares esten en el mismo frente (sino la
     *    "ubicacion especifica dentro del frente" pierde sentido).
     *  - Scope LOCAL del usuario (descarta IDs fuera de sus frentes).
     */
    public function bulkUbicacion(Request $request)
    {
        if (! auth()->user()?->can('equipos.assign')) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        $request->validate([
            'ids'               => 'required|array|min:1',
            'ids.*'             => 'exists:equipos_auxiliares,ID_AUXILIAR',
            // nullable: permite cadena vacía para borrar la ubicación existente.
            'detalle_ubicacion' => 'nullable|string|max:150',
        ]);

        $rawValor = $request->input('detalle_ubicacion', '');
        // Guardar NULL cuando el valor llega vacío (borra la ubicación en BD)
        $valor = ($rawValor !== null && trim($rawValor) !== '')
            ? mb_strtoupper(trim($rawValor))
            : null;

        return DB::transaction(function () use ($request, $valor) {
            $auxQuery = EquipoAuxiliar::whereIn('ID_AUXILIAR', $request->ids)
                ->lockForUpdate();
            // Solo auxiliares visibles: whitelist LOCAL + blacklist de bloqueados.
            $this->scopeFrentes($auxQuery, 'ID_FRENTE_ACTUAL');
            $auxiliares = $auxQuery->get(['ID_AUXILIAR', 'ID_FRENTE_ACTUAL']);

            if ($auxiliares->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'No se encontraron los auxiliares.'], 404);
            }

            $frentesUnicos = $auxiliares->pluck('ID_FRENTE_ACTUAL')->unique();
            if ($frentesUnicos->count() > 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Todos los auxiliares deben estar en el mismo frente.',
                ], 422);
            }
            if ($frentesUnicos->first() === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Los auxiliares seleccionados no tienen un frente asignado.',
                ], 422);
            }

            $count = EquipoAuxiliar::whereIn('ID_AUXILIAR', $auxiliares->pluck('ID_AUXILIAR'))
                ->update(['DETALLE_UBICACION_ACTUAL' => $valor]);

            return response()->json([
                'success'                  => true,
                'count'                    => $count,
                'DETALLE_UBICACION_ACTUAL' => $valor,
            ]);
        });
    }

    /**
     * Guarda (y reemplaza) los PDFs de documentacion del auxiliar en
     * storage/app/public/equipos_auxiliares/{id}/. Actualiza las
     * columnas LINK_DOC_PROPIEDAD / LINK_CERTIFICADO. Idempotente:
     * si no vienen archivos, no toca nada.
     */
    private function storeAuxDocs(Request $request, EquipoAuxiliar $aux): void
    {
        $updates = [];

        if ($request->hasFile('doc_propiedad') && $request->file('doc_propiedad')->isValid()) {
            $file = $request->file('doc_propiedad');
            $name = 'propiedad_' . time() . '.pdf';
            $path = $file->storeAs('equipos_auxiliares/' . $aux->ID_AUXILIAR, $name, 'public');
            $updates['LINK_DOC_PROPIEDAD'] = '/storage/' . $path;
        }

        if ($request->hasFile('certificado') && $request->file('certificado')->isValid()) {
            $file = $request->file('certificado');
            $name = 'certificado_' . time() . '.pdf';
            $path = $file->storeAs('equipos_auxiliares/' . $aux->ID_AUXILIAR, $name, 'public');
            $updates['LINK_CERTIFICADO'] = '/storage/' . $path;
        }

        if (!empty($updates)) {
            $aux->update($updates);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // VALIDATION
    // ═══════════════════════════════════════════════════════════
    private function validateData(Request $request, bool $isCreate = true): array
    {
        // ID del auxiliar actual (para excluirlo del check unique en update)
        $currentId = $request->route('id');

        // Normalizar ANTES de validar: uppercase + trim en campos donde
        // guardamos uppercase, para que el check unique compare consistente
        // (sino "ms-01" pasa unique aunque la BD tenga "MS-01" y al
        // guardar con strtoupper se crearia un duplicado logico).
        foreach (['SERIAL', 'CODIGO_INTERNO', 'MARCA', 'MODELO', 'CAPACIDAD'] as $f) {
            if ($request->filled($f)) {
                $request->merge([$f => mb_strtoupper(trim($request->input($f)))]);
            }
        }

        // TIPO: si el usuario seleccionó una etiqueta legible del datalist
        // (ej. "Maquina de Soldar"), mapearla al codigo enum correspondiente
        // (MAQUINA_SOLDAR) para preservar la consistencia con los registros
        // existentes. Comparacion case-insensitive. Si no hay match, se
        // normaliza como tipo custom (uppercase + underscores).
        if ($request->filled('TIPO')) {
            $input = trim($request->input('TIPO'));
            $labels = EquipoAuxiliar::tiposLabel(); // [code => label]
            $code = null;
            foreach ($labels as $k => $label) {
                if (mb_strtolower($label) === mb_strtolower($input)) { $code = $k; break; }
            }
            $request->merge(['TIPO' => $code ?? mb_strtoupper(preg_replace('/\s+/', '_', $input))]);
        }

        // TIPO y ESTADO_OPERATIVO son SIEMPRE requeridos, tanto en create como
        // en update: representan estado fundamental del registro. Si dejamos
        // 'sometimes|required|...' en update permitiria pasar string vacio via
        // JSON y el required no dispara. Mejor mantenerlos fuera del sometimes.
        // SERIAL: unique ignorando self en update (cuando esta presente).
        // max:60 en TIPO porque los codigos de tipos custom pueden superar 30 chars
        // (ej. GENERADOR_DIESEL_PORTATIL = 27 chars, pero nombres mas largos existen).
        $rules = [
            'TIPO'             => 'required|string|max:60',
            'ESTADO_OPERATIVO' => 'required|string|in:' . implode(',', array_keys(EquipoAuxiliar::estadosLabel())),
            'MARCA'            => 'required|string|max:80',
            'MODELO'           => 'required|string|max:80',
            // FIX: Use Rule::unique() instead of string rule to exclude soft-deleted records
            // from the uniqueness check. String rule (unique:table,col) queries ALL rows
            // including deleted_at IS NOT NULL, which causes a false 422 when a deleted
            // auxiliary has the same SERIAL (banner appears with no visible red fields).
            'SERIAL'         => [
                'required', 'string', 'max:100',
                Rule::unique('equipos_auxiliares', 'SERIAL')
                    ->whereNull('deleted_at')
                    ->when($currentId, fn ($r) => $r->ignore($currentId, 'ID_AUXILIAR')),
            ],
            'CODIGO_INTERNO' => [
                'nullable', 'string', 'max:80',
                Rule::unique('equipos_auxiliares', 'CODIGO_INTERNO')
                    ->whereNull('deleted_at')
                    ->when($currentId, fn ($r) => $r->ignore($currentId, 'ID_AUXILIAR')),
            ],
            'CAPACIDAD'        => 'nullable|string|max:80',
            'ANIO'             => 'nullable|integer|min:1950|max:2100',
            // FIX: exists:frentes_trabajo,ID_FRENTE without ESTATUS_FRENTE=ACTIVO filter:
            // a record may be assigned to a frente that was deactivated later. The FK
            // just needs to exist in the table — active-only filtering belongs in the UI,
            // not in the save validation (otherwise editing becomes impossible).
            'ID_FRENTE_ACTUAL' => 'required|exists:frentes_trabajo,ID_FRENTE',
            'ID_EQUIPO_HOST'   => 'nullable|exists:equipos,ID_EQUIPO',
            'OBSERVACIONES'    => 'nullable|string|max:500',
            // Documentacion (opcional). En UPDATE aceptamos fecha pasada para no
            // bloquear edicion de registros con certificados ya vencidos.
            'doc_propiedad'          => 'nullable|file|mimes:pdf|max:10240',
            'certificado'            => 'nullable|file|mimes:pdf|max:10240',
            'fecha_vencimiento_cert' => $isCreate ? 'nullable|date|after_or_equal:today' : 'nullable|date',
        ];

        // En update hacemos sometimes SOLO los nullable; required se mantiene.
        // FIX: handle both string rules and array rules (SERIAL/CODIGO_INTERNO use arrays).
        if (!$isCreate) {
            foreach ($rules as $k => $v) {
                $isNullable = is_array($v)
                    ? in_array('nullable', $v, true)
                    : (is_string($v) && strpos($v, 'nullable') !== false);
                if ($isNullable) {
                    $rules[$k] = is_array($v)
                        ? array_merge(['sometimes'], $v)
                        : 'sometimes|' . $v;
                }
            }
            // El frente NO se edita por datos (va por Movilización; update() lo descarta).
            // Opcional en update para no exigirlo y poder editar auxiliares SIN ASIGNAR.
            $rules['ID_FRENTE_ACTUAL'] = 'nullable|exists:frentes_trabajo,ID_FRENTE';
        }

        $validated = $request->validate($rules, [
            'SERIAL.unique'         => 'El serial ingresado ya está registrado en otro equipo auxiliar.',
            'CODIGO_INTERNO.unique' => 'El código interno ingresado ya está registrado en otro equipo auxiliar.',
        ]);

        // Normaliza TIPO: uppercase + espacios por guiones_bajos para mantener consistencia
        // con los codigos existentes (MAQUINA_SOLDAR, etc.) cuando el usuario escribe uno nuevo.
        if (isset($validated['TIPO'])) {
            $validated['TIPO'] = mb_strtoupper(preg_replace('/\s+/', '_', trim($validated['TIPO'])));
        }

        // Proteger tope ANCHOR_MAX_PER_HOST en create/update: si ID_EQUIPO_HOST
        // viene seteado y el host ya tiene N auxiliares distintos al actual,
        // rechazar. El endpoint anchor() tambien lo valida con lockForUpdate,
        // pero validar aqui tambien protege el set directo via form.
        if (!empty($validated['ID_EQUIPO_HOST'])) {
            $auxiliarId = $request->route('id');
            $existentes = EquipoAuxiliar::where('ID_EQUIPO_HOST', $validated['ID_EQUIPO_HOST'])
                ->when($auxiliarId, fn($q) => $q->where('ID_AUXILIAR', '!=', $auxiliarId))
                ->count();
            if ($existentes >= EquipoAuxiliar::ANCHOR_MAX_PER_HOST) {
                abort(422, 'El equipo host ya tiene el maximo de ' . EquipoAuxiliar::ANCHOR_MAX_PER_HOST . ' auxiliares anclados.');
            }
        }

        return $validated;
    }

    // ═══════════════════════════════════════════════════════════
    // CARGA MASIVA (Excel) — patron identico a /admin/equipos
    // ═══════════════════════════════════════════════════════════
    /**
     * Headers canonicos de la plantilla. El orden es vinculante: lo usa tanto
     * el generador (bulkTemplate) como el parser (bulkPreview) para validar.
     */
    private function bulkHeaderKeys(): array
    {
        return ['tipo', 'marca', 'modelo', 'serial', 'capacidad', 'año', 'frente de trabajo', 'estado'];
    }

    private function bulkHeaderLabels(): array
    {
        return ['Tipo', 'Marca', 'Modelo', 'Serial', 'Capacidad', 'Año', 'Frente de Trabajo', 'Estado'];
    }

    /**
     * Descarga plantilla XLSX para bulk upload. Incluye hoja oculta "_listas"
     * con data validation (dropdowns) para Tipo/Frente/Estado. Tipos custom
     * son permitidos (validation soft): el Excel muestra sugerencias pero no
     * bloquea escribir uno nuevo — se crea al guardar.
     */
    public function bulkTemplate(Request $request)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()->setCreator('Vidalsa')->setTitle('Plantilla Bulk Equipos Auxiliares');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Auxiliares');
        $sheet->fromArray([$this->bulkHeaderLabels()], null, 'A1');

        $sheet->getStyle('A1:H1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0067B1']],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FF000000']]],
        ]);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:H1');

        $colWidths = ['A' => 22, 'B' => 16, 'C' => 16, 'D' => 18, 'E' => 14, 'F' => 8, 'G' => 25, 'H' => 16];
        foreach ($colWidths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        // Hoja oculta con listas para dropdowns
        $listSheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, '_listas');
        $spreadsheet->addSheet($listSheet);

        $tiposArr   = array_values(array_map(fn($l) => mb_strtoupper($l), $this->getTiposDinamicos()));
        $frentesArr = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE')->pluck('NOMBRE_FRENTE')->toArray();
        $estadosArr = array_keys(EquipoAuxiliar::estadosLabel());

        $listSheet->fromArray([['Tipos']], null, 'A1');
        $listSheet->fromArray(array_map(fn($v) => [$v], $tiposArr), null, 'A2');
        $listSheet->fromArray([['Frentes']], null, 'B1');
        $listSheet->fromArray(array_map(fn($v) => [$v], $frentesArr), null, 'B2');
        $listSheet->fromArray([['Estados']], null, 'C1');
        $listSheet->fromArray(array_map(fn($v) => [$v], $estadosArr), null, 'C2');
        $listSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);

        $addListValidation = function (string $column, string $formula, bool $soft = false, string $prompt = '') use ($sheet) {
            $v = $sheet->getCell($column . '2')->getDataValidation();
            $v->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
            $v->setErrorStyle($soft
                ? \PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_INFORMATION
                : \PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
            $v->setAllowBlank(true);
            $v->setShowInputMessage(true);
            $v->setShowErrorMessage(!$soft);
            $v->setShowDropDown(true);
            if ($soft && $prompt) {
                $v->setPromptTitle('Tipo');
                $v->setPrompt($prompt);
            } else {
                $v->setErrorTitle('Valor no permitido');
                $v->setError('Selecciona un valor de la lista.');
            }
            $v->setFormula1($formula);
            $v->setSqref($column . '2:' . $column . '1001');
        };

        if (count($tiposArr) > 0) {
            $addListValidation('A', '_listas!$A$2:$A$' . (count($tiposArr) + 1), true, 'Selecciona de la lista o escribe uno nuevo (se creara al guardar).');
        }
        if (count($frentesArr) > 0) {
            $addListValidation('G', '_listas!$B$2:$B$' . (count($frentesArr) + 1));
        }
        $addListValidation('H', '_listas!$C$2:$C$' . (count($estadosArr) + 1));

        $spreadsheet->setActiveSheetIndex(0);
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);

        $filename = 'plantilla_equipos_auxiliares_' . now()->format('Y-m-d') . '.xlsx';
        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        return $response;
    }

    /**
     * Parsea el XLSX y devuelve JSON con filas + errores por fila. No crea
     * nada en BD. Resuelve TIPO/FRENTE a code/id para que el frontend edite
     * con selects y luego mande el batch final limpio.
     */
    public function bulkPreview(Request $request)
    {
        $request->validate([
            'archivo_excel' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        $path = $request->file('archivo_excel')->getRealPath();
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Auxiliares') ?? $spreadsheet->getActiveSheet();

        // Headers
        $expected = $this->bulkHeaderKeys();
        $actual = [];
        foreach (range('A', 'H') as $col) {
            $actual[] = mb_strtolower(trim((string) $sheet->getCell($col . '1')->getValue()));
        }
        if ($actual !== $expected) {
            return response()->json([
                'success' => false,
                'message' => 'Headers invalidos. Descarga la plantilla nuevamente.',
            ], 422);
        }

        $highestRow = $sheet->getHighestDataRow();
        if ($highestRow - 1 > 500) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo supera 500 filas de datos.',
            ], 422);
        }

        // Lookups en memoria (1 llamada c/u, no por fila)
        $tiposMap = $this->getTiposDinamicos();            // [CODE => Label]
        $tiposByCodeLower  = [];
        $tiposByLabelLower = [];
        foreach ($tiposMap as $code => $label) {
            $tiposByCodeLower[mb_strtolower($code)]   = $code;
            $tiposByLabelLower[mb_strtolower($label)] = $code;
        }
        $frentesMap = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->orderBy('NOMBRE_FRENTE')
            ->get()
            ->keyBy(fn($f) => mb_strtolower(trim($f->NOMBRE_FRENTE)));
        $validEstados = array_keys(EquipoAuxiliar::estadosLabel());

        // Pre-scan para detectar duplicados en archivo y contra BD
        $allSeriales = [];
        for ($n = 2; $n <= $highestRow; $n++) {
            $s = mb_strtoupper(trim((string) $sheet->getCell('D' . $n)->getValue()));
            if ($s !== '') $allSeriales[] = $s;
        }
        $duplicateSeriales = array_keys(array_filter(array_count_values($allSeriales), fn($c) => $c > 1));
        $existingSerialesBD = !empty($allSeriales)
            ? DB::table('equipos_auxiliares')
                // FIX: exclude soft-deleted records from the unique check.
                // Deleted records in the 'papelera' have the same SERIAL but are no
                // longer active — a new import should be allowed to reuse that serial.
                ->whereNull('deleted_at')
                ->whereIn(DB::raw('UPPER(SERIAL)'), $allSeriales)
                ->pluck('SERIAL')->map(fn($v) => mb_strtoupper($v))->toArray()
            : [];

        $rows = [];
        for ($n = 2; $n <= $highestRow; $n++) {
            $rawTipo    = trim((string) $sheet->getCell('A' . $n)->getValue());
            $rawMarca   = trim((string) $sheet->getCell('B' . $n)->getValue());
            $rawModelo  = trim((string) $sheet->getCell('C' . $n)->getValue());
            $rawSerial  = trim((string) $sheet->getCell('D' . $n)->getValue());
            $rawCap     = trim((string) $sheet->getCell('E' . $n)->getValue());
            $rawAnio    = $sheet->getCell('F' . $n)->getValue();
            $rawFrente  = trim((string) $sheet->getCell('G' . $n)->getValue());
            $rawEstado  = trim((string) $sheet->getCell('H' . $n)->getValue());

            // Skip filas vacias
            if ($rawTipo === '' && $rawMarca === '' && $rawModelo === '' && $rawSerial === '') continue;

            $errors = [];
            $serialUpper = mb_strtoupper($rawSerial);
            $estadoUpper = mb_strtoupper($rawEstado);

            // Requeridos
            foreach (['tipo' => $rawTipo, 'marca' => $rawMarca, 'modelo' => $rawModelo, 'serial' => $rawSerial, 'estado' => $rawEstado] as $field => $val) {
                if ($val === '') $errors[$field] = 'Campo requerido.';
            }

            // TIPO: resolver a code. Match por label o por code (case-insensitive).
            // Si no matchea, normalizar como custom (UPPERCASE + _).
            $tipoCodigo = null;
            if ($rawTipo !== '') {
                $key = mb_strtolower($rawTipo);
                if (isset($tiposByCodeLower[$key])) {
                    $tipoCodigo = $tiposByCodeLower[$key];
                } elseif (isset($tiposByLabelLower[$key])) {
                    $tipoCodigo = $tiposByLabelLower[$key];
                } else {
                    $tipoCodigo = mb_strtoupper(preg_replace('/\s+/', '_', $rawTipo));
                }
            }

            // ESTADO
            if ($rawEstado !== '' && !in_array($estadoUpper, $validEstados)) {
                $errors['estado'] = 'Valor no valido. Opciones: ' . implode(', ', $validEstados) . '.';
            }

            // FRENTE (opcional)
            $idFrenteResuelto = null;
            if ($rawFrente !== '') {
                $fKey = mb_strtolower(trim($rawFrente));
                if (isset($frentesMap[$fKey])) {
                    $idFrenteResuelto = $frentesMap[$fKey]->ID_FRENTE;
                } else {
                    $errors['frente_de_trabajo'] = 'Frente no encontrado o inactivo.';
                }
            }

            // SERIAL unique
            if ($serialUpper !== '') {
                if (in_array($serialUpper, $existingSerialesBD)) {
                    $errors['serial'] = 'Ya registrado en BD.';
                } elseif (in_array($serialUpper, $duplicateSeriales)) {
                    $errors['serial'] = 'Duplicado dentro del archivo.';
                }
            }

            $anio = ($rawAnio !== '' && $rawAnio !== null) ? (int) $rawAnio : null;
            if ($anio !== null && ($anio < 1950 || $anio > 2100)) {
                $errors['año'] = 'Debe estar entre 1950 y 2100.';
            }

            $rows[] = [
                'row_index' => $n,
                'data' => [
                    'tipo'               => mb_strtoupper($rawTipo),
                    'tipo_codigo'        => $tipoCodigo,
                    'marca'              => mb_strtoupper($rawMarca),
                    'modelo'             => mb_strtoupper($rawModelo),
                    'serial'             => $serialUpper,
                    'capacidad'          => mb_strtoupper($rawCap),
                    'anio'               => $anio,
                    'frente'             => mb_strtoupper($rawFrente),
                    'id_frente_resuelto' => $idFrenteResuelto,
                    'estado'             => $estadoUpper,
                ],
                'errors' => $errors,
            ];
        }

        return response()->json([
            'success' => true,
            'rows'    => $rows,
            'options' => [
                'tipos'   => array_values(array_map(fn($l) => mb_strtoupper($l), $tiposMap)),
                'frentes' => FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
                                ->orderBy('NOMBRE_FRENTE')
                                ->get(['ID_FRENTE', 'NOMBRE_FRENTE'])
                                ->map(fn($f) => ['id' => $f->ID_FRENTE, 'nombre' => $f->NOMBRE_FRENTE]),
                'estados' => $validEstados,
            ],
        ]);
    }

    /**
     * Recibe el batch ya editado en el frontend y lo inserta en transaccion.
     * Si hay errores de BD en alguna fila, hace rollback completo y reporta
     * los fallos. Tipos custom nuevos simplemente se guardan con su code
     * normalizado (no requiere tabla de catalogo).
     */
    public function bulkStoreBatch(Request $request)
    {
        set_time_limit(600);

        $validator = \Validator::make($request->all(), [
            'rows'                       => 'required|array|min:1|max:500',
            'rows.*.tipo_codigo'         => 'required|string|max:60',
            'rows.*.marca'               => 'required|string|max:80',
            'rows.*.modelo'              => 'required|string|max:80',
            'rows.*.serial'              => 'required|string|max:100',
            'rows.*.capacidad'           => 'nullable|string|max:80',
            'rows.*.anio'                => 'nullable|integer|min:1950|max:2100',
            'rows.*.id_frente_resuelto'  => 'nullable|integer|exists:frentes_trabajo,ID_FRENTE',
            'rows.*.estado'              => 'required|string|in:' . implode(',', array_keys(EquipoAuxiliar::estadosLabel())),
        ], [
            // Mensajes en español por fila (regla :input/:position se reemplaza por el numero de fila +2 = numero real en el Excel)
            'rows.*.tipo_codigo.required'        => 'Fila :position: el Tipo es obligatorio.',
            'rows.*.tipo_codigo.max'             => 'Fila :position: el Tipo no puede exceder 60 caracteres.',
            'rows.*.marca.required'              => 'Fila :position: la Marca es obligatoria.',
            'rows.*.marca.max'                   => 'Fila :position: la Marca no puede exceder 80 caracteres.',
            'rows.*.modelo.required'             => 'Fila :position: el Modelo es obligatorio.',
            'rows.*.modelo.max'                  => 'Fila :position: el Modelo no puede exceder 80 caracteres.',
            'rows.*.serial.required'             => 'Fila :position: el Serial es obligatorio.',
            'rows.*.serial.max'                  => 'Fila :position: el Serial no puede exceder 100 caracteres.',
            'rows.*.capacidad.max'               => 'Fila :position: la Capacidad no puede exceder 80 caracteres.',
            'rows.*.anio.integer'                => 'Fila :position: el Año debe ser un número entero.',
            'rows.*.anio.min'                    => 'Fila :position: el Año debe ser mayor o igual a 1950.',
            'rows.*.anio.max'                    => 'Fila :position: el Año debe ser menor o igual a 2100.',
            'rows.*.id_frente_resuelto.integer'  => 'Fila :position: el Frente seleccionado no es válido.',
            'rows.*.id_frente_resuelto.exists'   => 'Fila :position: el Frente seleccionado no existe o está inactivo.',
            'rows.*.estado.required'             => 'Fila :position: el Estado es obligatorio.',
            'rows.*.estado.in'                   => 'Fila :position: el Estado seleccionado no es válido.',
            'rows.required'                      => 'Debes incluir al menos una fila para cargar.',
            'rows.array'                         => 'El formato del lote es inválido.',
            'rows.min'                           => 'Debes incluir al menos una fila para cargar.',
            'rows.max'                           => 'Solo se pueden cargar hasta 500 filas por lote.',
        ]);

        if ($validator->fails()) {
            $errs   = $validator->errors();
            $first  = $errs->first();
            $count  = count($errs->all());
            $summary = $first;
            if ($count > 1) {
                $extra = $count - 1;
                $summary .= ' (y ' . $extra . ' error' . ($extra > 1 ? 'es' : '') . ' más)';
            }
            return response()->json([
                'success' => false,
                'message' => $summary,
                'errors'  => $errs->toArray(),
            ], 422);
        }
        $data = $validator->validated();

        // Unicidad de SERIAL cross-batch y contra BD (defensa final server-side)
        $seriales = array_map(fn($r) => mb_strtoupper(trim($r['serial'])), $data['rows']);
        $dupEnBatch = array_keys(array_filter(array_count_values($seriales), fn($c) => $c > 1));
        if (!empty($dupEnBatch)) {
            return response()->json([
                'success' => false,
                'message' => 'Hay seriales duplicados en el batch: ' . implode(', ', $dupEnBatch),
            ], 422);
        }
        $conflictsBD = DB::table('equipos_auxiliares')
            // FIX: exclude soft-deleted records — same fix as bulkPreview.
            // A serial from the 'papelera' should not block a fresh import.
            ->whereNull('deleted_at')
            ->whereIn(DB::raw('UPPER(SERIAL)'), $seriales)
            ->pluck('SERIAL')->toArray();
        if (!empty($conflictsBD)) {
            return response()->json([
                'success' => false,
                'message' => 'Algun serial ya existe en BD: ' . implode(', ', $conflictsBD),
            ], 422);
        }

        $creadoPor = auth()->id();
        $now = now();

        DB::beginTransaction();
        try {
            $batch = [];
            foreach ($data['rows'] as $row) {
                $batch[] = [
                    'TIPO'             => mb_strtoupper(preg_replace('/\s+/', '_', $row['tipo_codigo'])),
                    'MARCA'            => mb_strtoupper(trim($row['marca'])),
                    'MODELO'           => mb_strtoupper(trim($row['modelo'])),
                    'SERIAL'           => mb_strtoupper(trim($row['serial'])),
                    'CAPACIDAD'        => !empty($row['capacidad']) ? mb_strtoupper(trim($row['capacidad'])) : null,
                    'ANIO'             => $row['anio'] ?? null,
                    'ID_FRENTE_ACTUAL' => $row['id_frente_resuelto'] ?? null,
                    'ESTADO_OPERATIVO' => $row['estado'],
                    'CREADO_POR'       => $creadoPor,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            }
            EquipoAuxiliar::insert($batch);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Se registraron ' . count($batch) . ' equipo(s) auxiliar(es).',
                'count'   => count($batch),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('bulkStoreBatch auxiliares: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al guardar el batch: ' . $e->getMessage(),
            ], 500);
        }
    }
}
