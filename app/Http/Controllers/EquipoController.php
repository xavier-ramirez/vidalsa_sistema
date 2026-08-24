<?php

namespace App\Http\Controllers;

use App\Models\Equipo;
use App\Models\EquipoAuxiliar;
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
    use \App\Traits\ExcelLogoCorporativo;

    /**
     * Filtros de documento → columna LINK_* en `documentacion`.
     * Fuente única para: (a) detectar qué filtros de doc están activos y
     * (b) construir el desglose "Con / Sin documento" del Consolidado.
     */
    private const DOC_FILTER_COLS = [
        'filter_propiedad'   => 'LINK_DOC_PROPIEDAD',
        'filter_poliza'      => 'LINK_POLIZA_SEGURO',
        'filter_rotc'        => 'LINK_ROTC',
        'filter_racda'       => 'LINK_RACDA',
        'filter_adicional'   => 'LINK_DOC_ADICIONAL',
        'filter_adicional_2' => 'LINK_DOC_ADICIONAL_2',
    ];

    /** Etiqueta corta por filtro de doc (para el label del Consolidado). */
    private const DOC_FILTER_LABELS = [
        'filter_propiedad'   => 'Propiedad',
        'filter_poliza'      => 'Póliza',
        'filter_rotc'        => 'ROTC',
        'filter_racda'       => 'RACDA',
        'filter_adicional'   => 'Certificado',
        'filter_adicional_2' => 'Compraventa',
    ];

    public function __construct()
    {
        // mobileIndex y mobileChangeStatus se invocan via routes/api.php con
        // guard sanctum — no pasan por el middleware 'auth' web del constructor.
        $this->middleware('auth')->except(['mobileIndex', 'mobileChangeStatus']);
        // Registro uno a uno + carga masiva via Excel: requieren 'equipos.create'.
        // Gate::before resuelve super.admin. 'create' (el GET del formulario) va aqui
        // tambien: sin el, un usuario sin permiso abria y llenaba el form para recibir
        // un 403 al enviarlo.
        $this->middleware('can:equipos.create')->only(['create', 'store', 'bulkTemplate', 'bulkPreview', 'bulkStoreBatch']);
        // edit/update: permiso 'user.edit' (boton lapiz del modal detalles
        // + formulario de edicion de ficha). changeStatus: 'equipos.edit'
        // (cambio de estatus inline, desacoplado de la edicion general).
        $this->middleware('can:user.edit')->only(['edit', 'update']);
        $this->middleware('can:equipos.edit')->only(['changeStatus', 'confirmarSitio']);
        // Borrar un equipo es destructivo irreversible: solo super.admin.
        $this->middleware('can:super.admin')->only(['destroy']);
        // uploadDoc/updateMetadata: permission 'user.edit' (chequeo dentro de cada metodo).
        // deleteDoc (borrado destructivo de PDF + Drive): solo super.admin, gateado en routes/web.php.
    }

    /**
     * Centralized lookup. NO aplica barrera por NIVEL_ACCESO_EQUIPOS / jurisdiccion:
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
        foreach (['modelo', 'marca', 'detalle_ubicacion', 'anio', 'categoria', 'estado', 'color'] as $p) {
            if ($request->filled($p)) {
                return true;
            }
        }
        if (in_array(strtoupper(trim((string) $request->input('gps', ''))), ['SI', 'NO'], true)) {
            return true;
        }
        if (in_array(strtoupper(trim((string) $request->input('confirmado', ''))), ['SI', 'NO'], true)) {
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
        // trim: una búsqueda de solo espacios cuenta como VACÍA → se aplica el scope
        // por frente (sin esto, "  " saltaría la barrera de visibilidad del usuario LOCAL).
        $search = trim((string) $request->input('search_query', ''));

        // Barrera de acceso por jurisdicción: lista blanca (LOCAL → solo sus frentes)
        // + lista negra de bloqueados (aplica TAMBIÉN a GLOBAL). aplicarScopeFrentes
        // combina ambas; closure reutilizable entre "solo seleccionados" y filtrado normal.
        $aplicarAccesoLocal = function ($q) use ($user) {
            if ($user) {
                $user->aplicarScopeFrentesEquipos($q, 'ID_FRENTE_ACTUAL');
            }
        };

        // ── "Solo seleccionados" (whitelist por IDs) ────────────────────────────
        // Cuando llega ids_in (lo manda el contador de la barra de selección al
        // togglear "ver solo seleccionados"), mostramos EXACTAMENTE esos equipos
        // ignorando los demás filtros de contenido y la búsqueda — mismo patrón
        // que el "solo seleccionados" del módulo Almacén. Se MANTIENE la barrera
        // de acceso por frente para usuarios locales. Se hace short-circuit:
        // la whitelist es la única condición de contenido.
        $idsIn = $request->input('ids_in');
        if (!in_array('ids_in', $exclude) && is_string($idsIn) && trim($idsIn) !== '') {
            $ids = collect(explode(',', $idsIn))
                ->map(fn ($v) => (int) trim($v))
                ->filter()
                ->take(2000)
                ->values()
                ->all();
            $aplicarAccesoLocal($query);
            $query->whereIn('equipos.ID_EQUIPO', $ids ?: [0]);
            return;
        }

        if (empty($search)) {
            $aplicarAccesoLocal($query);
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
            $tipoVal = (string) $request->id_tipo;
            // Dropdown combinado (patron /admin/movilizaciones): el valor puede venir
            // prefijado. 'tipo_aux:X' es un tipo de AUXILIAR -> NO aplica a la tabla
            // equipos (esos se listan aparte en index() via buildEmbedPayload), se
            // ignora aqui. 'tipo_eq:N' y el valor numerico pelado filtran por equipo.
            if (str_starts_with($tipoVal, 'tipo_aux:')) {
                // no-op: el filtro de tipo de auxiliar no aplica a equipos
            } else {
                $tipoId = str_starts_with($tipoVal, 'tipo_eq:') ? (int) substr($tipoVal, 8) : $tipoVal;
                $query->where('id_tipo_equipo', $tipoId);
            }
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

        if (!in_array('categoria', $exclude) && $request->filled('categoria') && trim($request->categoria) !== '' && strtoupper(trim($request->categoria)) !== 'AUXILIARES') {
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

        if (!in_array('color', $exclude) && $request->filled('color') && trim($request->color) !== '') {
            $query->where('COLOR', $request->color);
        }

        // Confirmación de presencia en sitio (CONFIRMADO_EN_SITIO): SI=confirmado, NO=pendiente.
        if (!in_array('confirmado', $exclude) && $request->filled('confirmado') && trim($request->confirmado) !== '') {
            $val = strtoupper(trim($request->confirmado));
            if ($val === 'SI') {
                $query->where('CONFIRMADO_EN_SITIO', 1);
            } elseif ($val === 'NO') {
                $query->where('CONFIRMADO_EN_SITIO', 0);
            }
        }

        // Filtros de documentacion (Propiedad/Poliza/ROTC/RACDA/Certificado/
        // Compraventa): RESTRINGEN el listado (y los stats que comparten esta
        // base). Varios activos = AND (el equipo debe tener TODOS). El parámetro
        // $exclude permite omitirlos para calcular el universo "Con / Sin
        // documento" del Consolidado (ver index()). La presencia real del PDF
        // (!=null Y !='') la resuelve applyDocPresence().
        $docFilters = array_diff_key($this->activeDocFilters($request), array_flip($exclude));
        // La dirección con/sin/all la fija el usuario clicando los bloques del
        // Consolidado (param doc_presence). El desglose doc_con/doc_sin se calcula
        // aparte en index() siempre con 'con', así que no se ve afectado.
        $this->applyDocPresence($query, $docFilters, $this->docPresenceMode($request));
    }

    /**
     * Filtros de documento activos en el request (param => columna LINK_*).
     * Soporta varios activos a la vez.
     */
    private function activeDocFilters(Request $request): array
    {
        $active = [];
        foreach (self::DOC_FILTER_COLS as $param => $col) {
            if ($request->input($param) === 'true') {
                $active[$param] = $col;
            }
        }
        return $active;
    }

    /**
     * Restringe $query según la PRESENCIA de los documentos de $docFilters
     * (param => columna LINK_*), según $mode:
     *   - 'con' (default): equipos que TIENEN cargados TODOS los docs (AND).
     *   - 'sin'          : equipos a los que les FALTA cada doc (AND de ausencias).
     *   - 'all'          : sin recorte de presencia (universo Con+Sin).
     * !=null Y !='': el LINK_* puede quedar como string vacío tras un borrado, por
     * eso "tener" = whereNotNull Y !='' y "faltar" = su negación (whereDoesntHave).
     */
    private function applyDocPresence($query, array $docFilters, string $mode = 'con'): void
    {
        if ($mode === 'all') {
            return; // el universo completo: no se restringe por presencia
        }
        foreach ($docFilters as $col) {
            if ($mode === 'sin') {
                // Le FALTA el doc: col null/'' o sin fila documentacion.
                $query->whereDoesntHave('documentacion', function ($q) use ($col) {
                    $q->whereNotNull($col)->where($col, '!=', '');
                });
            } else { // 'con': lo TIENE cargado
                $query->whereHas('documentacion', function ($q) use ($col) {
                    $q->whereNotNull($col)->where($col, '!=', '');
                });
            }
        }
    }

    /**
     * Dirección del filtro de documento en la LISTA (controlada por los bloques
     * clicables del Consolidado): 'con' | 'sin' | 'all'. Default 'con' (histórico:
     * tildar un doc muestra los que lo tienen). El desglose del Consolidado
     * (doc_con/doc_sin) NO depende de esto — siempre muestra ambos lados.
     */
    private function docPresenceMode(Request $request): string
    {
        $m = (string) $request->input('doc_presence', 'con');
        return in_array($m, ['con', 'sin', 'all'], true) ? $m : 'con';
    }

    /** Etiqueta del desglose: "Propiedad" si hay uno solo, "Documentos" si varios. */
    private function docFilterLabel(array $docFilters): string
    {
        if (count($docFilters) === 1) {
            return self::DOC_FILTER_LABELS[array_key_first($docFilters)] ?? 'Documento';
        }
        return 'Documentos';
    }

    /**
     * Modo AUXILIAR de /admin/equipos: la tabla es 100% auxiliares. Lo activa elegir un
     * tipo auxiliar en el dropdown (id_tipo='tipo_aux:X') o categoria=AUXILIARES.
     */
    private function esModoAux(Request $request): bool
    {
        return str_starts_with((string) $request->input('id_tipo', ''), 'tipo_aux:')
            || strtoupper(trim((string) $request->input('categoria', ''))) === 'AUXILIARES';
    }

    /**
     * True si los filtros apuntan SOLO a equipos → NO se muestran/exportan auxiliares ni su
     * card. El FRENTE no cuenta (agrupa equipos Y auxiliares). Dos motivos de enfoque:
     *  (a) atributo de DISPOSITIVO: tipo, modelo, marca, color, año, GPS o categoría de flota.
     *  (b) documento que SOLO existe en equipos (póliza/ROTC/RACDA/compraventa): un auxiliar
     *      no puede cumplirlo. Se deriva de DOC_FILTER_COLS menos los DOCS COMPARTIDOS
     *      (filter_propiedad y filter_adicional/"Certificado", que los auxiliares también
     *      tienen) para no desincronizarse. Ver self::SHARED_DOC_FILTERS.
     * Fuente ÚNICA: la usan el merge y la card en index() y la hoja aux de export().
     */
    private function esFocoSoloEquipos(Request $request): bool
    {
        $categoriaSel = strtoupper(trim((string) $request->input('categoria', '')));
        // NOTA: 'anio' NO entra aquí — es un EJE COMPARTIDO (los auxiliares también tienen ANIO),
        // así que filtrar por año muestra equipos Y auxiliares de ese año (ver auxFiltroCompartidoActivo
        // y auxSharedRequest). marca/modelo/color/gps/categoría/tipo siguen siendo solo-equipos.
        $equipoDeviceFilter =
               ($request->filled('id_tipo') && $request->input('id_tipo') !== 'all')
            || $request->filled('modelo')
            || $request->filled('marca')
            || $request->filled('color')
            || $request->filled('gps')
            || ($categoriaSel !== '' && $categoriaSel !== 'AUXILIARES');
        // Documentos solo-equipos activos = los doc filters activos (detector canónico
        // activeDocFilters, criterio === 'true') MENOS los DOCS COMPARTIDOS (propiedad y
        // certificado, que los auxiliares también tienen). Reutiliza la fuente única para no
        // divergir del filtrado real.
        $equipoOnlyDocFilter = !empty(array_diff(
            array_keys($this->activeDocFilters($request)),
            self::SHARED_DOC_FILTERS
        ));
        return $equipoDeviceFilter || $equipoOnlyDocFilter;
    }

    /**
     * Doc filters COMPARTIDOS equipo↔auxiliar: el auxiliar tiene la misma clase de documento,
     * así que tildarlos NO enfoca solo-equipos y se reenvían al módulo aux (con_propiedad /
     * con_certificado). El resto de docs (póliza/ROTC/RACDA/compraventa) son solo-equipos.
     *   filter_propiedad → LINK_DOC_PROPIEDAD (equipo) / LINK_DOC_PROPIEDAD (aux)
     *   filter_adicional ("Certificado") → LINK_DOC_ADICIONAL (equipo) / LINK_CERTIFICADO (aux)
     */
    private const SHARED_DOC_FILTERS = ['filter_propiedad', 'filter_adicional'];

    /**
     * ¿Hay algún EJE COMPARTIDO activo (frente, búsqueda o doc compartido) que los auxiliares
     * también puedan satisfacer? Gobierna por igual el merge de filas aux y la card en index(),
     * y la hoja aux de export(), para que la tabla, la card y el Excel coincidan EXACTAMENTE.
     */
    private function auxFiltroCompartidoActivo(Request $request): bool
    {
        if ($request->filled('id_frente')) return true;
        if (trim((string) $request->input('search_query', '')) !== '') return true;
        if ($request->filled('anio')) return true; // los auxiliares también tienen ANIO
        foreach (self::SHARED_DOC_FILTERS as $param) {
            if ($request->input($param) === 'true') return true;
        }
        return false;
    }

    /**
     * Request con los ejes COMPARTIDOS (aplican a equipos Y auxiliares) mapeados a los
     * nombres del módulo aux. Lo consumen el merge de la tabla, el consolidado y la hoja de
     * exportación, así los tres filtran idéntico. Los atributos de vehículo no se reenvían.
     */
    private function auxSharedRequest(Request $request): Request
    {
        return new Request([
            'id_frente'         => $request->input('id_frente'),
            'estado'            => $request->input('estado'),
            'search'            => $request->input('search_query'),
            'detalle_ubicacion' => $request->input('detalle_ubicacion'),
            'confirmado'        => $request->input('confirmado'),
            'anio'              => $request->input('anio'), // eje compartido: aux también tienen ANIO
            // Doc filters COMPARTIDOS: criterio === 'true' (igual que activeDocFilters y el blade).
            // "Certificado" del panel (filter_adicional) → con_certificado del aux (LINK_CERTIFICADO).
            'con_propiedad'     => $request->input('filter_propiedad') === 'true' ? '1' : null,
            'con_certificado'   => $request->input('filter_adicional') === 'true' ? '1' : null,
            // Dirección de presencia del doc (Con/Sin/Todos) para que el merge y el conteo del
            // card aux filtren igual que la lista de equipos al clicar los bloques del Consolidado.
            'doc_presence'      => $request->input('doc_presence'),
        ]);
    }

    /**
     * Request del modo AUXILIAR (se eligió un tipo aux en el dropdown) mapeado al módulo aux.
     * Lo consumen la tabla aux embebida (index) y la hoja de exportación.
     */
    private function auxModeRequest(Request $request): Request
    {
        return new Request([
            'tipo'              => substr((string) $request->input('id_tipo', ''), 9),
            'id_frente'         => $request->input('id_frente'),
            'search'            => $request->input('search_query'),
            'detalle_ubicacion' => $request->input('detalle_ubicacion'),
            'confirmado'        => $request->input('confirmado'),
            'marca'             => $request->input('marca'),
            'modelo'            => $request->input('modelo'),
            'estado'            => $request->input('estado'),
            'anio'              => $request->input('anio'),
            // Doc filters COMPARTIDOS: criterio === 'true' (igual que activeDocFilters y el blade).
            // "Certificado" del panel (filter_adicional) → con_certificado del aux (LINK_CERTIFICADO).
            'con_propiedad'     => $request->input('filter_propiedad') === 'true' ? '1' : null,
            'con_certificado'   => $request->input('filter_adicional') === 'true' ? '1' : null,
            'offset'            => $request->input('offset', 0),
        ]);
    }

    /**
     * Aplica la búsqueda de texto (serial chasis/motor, código de patio, etiqueta y placa)
     * a $query. FUENTE ÚNICA: la usan TANTO la tabla como los stats/Distribución para que el
     * resumen lateral coincida con lo que se ve. Antes la búsqueda solo afectaba a la tabla y
     * el Consolidado/Distribución seguían mostrando la flota completa (tabla=1, lado=1091).
     * Usa whereHas para la PLACA (no leftJoin) → seguro para COUNT/GROUP BY (no duplica filas).
     * La ambigüedad O↔0 se aplica SOLO a la placa.
     */
    private function applyBusquedaTexto($query, ?string $search): void
    {
        $searchUpper = strtoupper(trim((string) $search));
        if ($searchUpper === '') return;

        // Etiqueta: #NÚMERO → NUMERO_ETIQUETA
        if (strpos($searchUpper, '#') !== false) {
            $tag = str_replace('#', '', $searchUpper);
            $query->where('equipos.NUMERO_ETIQUETA', 'like', "%{$tag}%");
            return;
        }

        $placaVariants = collect([
            $searchUpper,
            str_replace('O', '0', $searchUpper),
            str_replace('0', 'O', $searchUpper),
            str_replace(['O', '0'], ['0', 'O'], $searchUpper),
        ])->unique()->values()->all();

        $query->where(function ($q) use ($searchUpper, $placaVariants) {
            $q->where('equipos.SERIAL_CHASIS', 'like', "%{$searchUpper}%")
              ->orWhere('equipos.SERIAL_DE_MOTOR', 'like', "%{$searchUpper}%")
              ->orWhere('equipos.CODIGO_PATIO', 'like', "%{$searchUpper}%")
              ->orWhere('equipos.NUMERO_ETIQUETA', 'like', "%{$searchUpper}%")
              ->orWhereHas('documentacion', function ($d) use ($placaVariants) {
                  $d->where(function ($pq) use ($placaVariants) {
                      foreach ($placaVariants as $v) {
                          $pq->orWhere('PLACA', 'like', "%{$v}%");
                      }
                  });
              });
        });
    }

    public function index(Request $request)
    {
        $search = $request->input('search_query');
        $equipos = Equipo::query();

        $user = auth()->user();

        // Dropdown combinado de Tipo (VEHICULOS / AUXILIARES), patron /admin/movilizaciones.
        // Si el tipo elegido es de auxiliar ('tipo_aux:X'), la TABLA, el Consolidado y la
        // Distribucion pasan a reflejar AUXILIARES, todo desde un solo payload
        // (EquipoAuxiliarController::buildEmbedPayload): mas abajo $stats y la distribucion
        // se reemplazan por los del payload aux. Por eso el trabajo propio de EQUIPOS
        // (paginacion de la tabla, jsonPayload del modal, frentesStats) se omite en modo aux.
        // $auxModeByTipo: activado SOLO por el dropdown de tipo (tipo_aux:X).
        // Controla el panel de filtros (eq-aux-mode en body vía JS/blade).
        // $auxMode: incluye también categoria=AUXILIARES. Controla qué tabla se muestra.
        $auxModeByTipo = str_starts_with((string) $request->input('id_tipo', ''), 'tipo_aux:');
        $auxMode = $this->esModoAux($request);

        // Filtros principales (todos los ejes activos)
        $this->applyEquipoFilters($equipos, $request);

        // En modo "solo seleccionados" (ids_in) la whitelist es la única condición:
        // applyEquipoFilters ya hizo short-circuit, así que ignoramos la búsqueda.
        // trim()!=='' (no truthy): un serial/código exacto '0' es falsy en PHP y se perdería.
        // Búsqueda de texto (serial/motor/código/etiqueta/placa) — FUENTE ÚNICA reusada por
        // la tabla Y los stats/Distribución (applyBusquedaTexto), para que el resumen lateral
        // coincida con la tabla. En modo "ver solo seleccionados" (ids_in) la búsqueda se omite.
        if (!$request->filled('ids_in')) {
            $this->applyBusquedaTexto($equipos, $search);
        }




        // Filtros de documentacion (filter_propiedad/poliza/rotc/racda) ya
        // estan unificados dentro de applyEquipoFilters() — se aplican via
        // whereHas a $equipos Y a todos los stats queries automaticamente.

        $equipos->select('equipos.*')
            ->leftJoin('tipo_equipos', 'equipos.id_tipo_equipo', '=', 'tipo_equipos.id')
            ->with([
                'documentacion.seguro',
                'especificaciones:ID_ESPEC,FOTO_REFERENCIAL',
                'tipo',
                'frenteActual',
                // Reporte abierto: permite abrir el modal de cierre al instante al
                // pasar un INOPERATIVO a OPERATIVO (sin round-trip al 409).
                'fallaAbierta:ID_FALLA,ACTIVO_ID,ACTIVO_TIPO,ESTADO_REPORTE,CODIGO_REPORTE,TIPO_REPORTE,FECHA_EMISION',
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
        $hasFilter = $request->filled('id_frente') || $request->filled('id_tipo') || $request->filled('search_query') || $request->filled('modelo') || $request->filled('marca') || $request->filled('color') || $request->filled('confirmado') || $request->filled('detalle_ubicacion') || $request->filled('anio') || $request->filled('categoria') || $request->filled('estado') || $request->filled('gps') || $request->filled('filter_propiedad') || $request->filled('filter_poliza') || $request->filled('filter_rotc') || $request->filled('filter_racda') || $request->filled('filter_adicional') || $request->filled('filter_adicional_2');

        // Paginación server-side con cap por página.
        // La tabla carga 150 filas por request; al final el frontend pide el siguiente lote
        // (offset += 150) con IntersectionObserver para scroll infinito.
        $PAGE_SIZE = 150;
        $offset    = max(0, (int) $request->input('offset', 0));
        $totalFound = 0;
        $truncated  = false;
        $nextOffset = 0;
        $hasMore    = false;
        // En modo aux la tabla NO sale de la query de equipos (sale del payload aux),
        // asi que no paginamos equipos aqui; las stats de abajo SI corren (sobre equipos).
        if ($hasFilter && !$auxMode) {
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
        // En modo aux se omiten: el Consolidado y la Distribucion los aporta el payload aux
        // (mas abajo $stats se reemplaza por $auxEmbed['stats']), asi no se malgastan COUNTs.
        if ($hasFilter && !$auxMode) {
            // Stats: UN solo agregado SUM(CASE) con los mismos filtros, sin offset/limit
            // (antes 5 COUNTs secuenciales; en enlaces lentos la latencia por round-trip
            // domina). Mismo patrón que fleetStats y AlmacenController::statsInventario.
            $statsBase = Equipo::query();
            $this->applyEquipoFilters($statsBase, $request);
            $this->applyBusquedaTexto($statsBase, $search); // Consolidado refleja la búsqueda
            $agg = $statsBase->selectRaw("
                COUNT(*) as total_all,
                SUM(CASE WHEN ESTADO_OPERATIVO != 'DESINCORPORADO' THEN 1 ELSE 0 END) as total_activa,
                SUM(CASE WHEN ESTADO_OPERATIVO = 'OPERATIVO' THEN 1 ELSE 0 END) as activos,
                SUM(CASE WHEN ESTADO_OPERATIVO = 'INOPERATIVO' THEN 1 ELSE 0 END) as inactivos,
                SUM(CASE WHEN ESTADO_OPERATIVO = 'EN MANTENIMIENTO' THEN 1 ELSE 0 END) as mantenimiento,
                SUM(CASE WHEN ESTADO_OPERATIVO = 'DESINCORPORADO' THEN 1 ELSE 0 END) as desincorporados
            ")->first();
            // El "total" excluye DESINCORPORADO por defecto, PERO si el usuario filtra
            // explícitamente por estado=DESINCORPORADO el total refleja esos equipos.
            $filtroEstado = strtoupper(trim((string) $request->input('estado', '')));
            $stats['total']           = (int) ($filtroEstado === 'DESINCORPORADO' ? $agg->total_all : $agg->total_activa);
            $stats['activos']         = (int) $agg->activos;
            $stats['inactivos']       = (int) $agg->inactivos;
            $stats['mantenimiento']   = (int) $agg->mantenimiento;
            $stats['desincorporados'] = (int) $agg->desincorporados;

            // Desglose "Con / Sin documento" para el Consolidado.
            // La LISTA sigue filtrada por documento (no se toca). Aquí solo
            // calculamos, sobre el universo del frente/estado IGNORANDO el filtro
            // de documento (docFreeBase), cuántos equipos tienen el/los doc(s)
            // (= lo que muestra la lista) y cuántos no. doc_con + doc_sin = doc_total.
            $docFilters = $this->activeDocFilters($request);
            if (!empty($docFilters)) {
                $docFreeBase = Equipo::query();
                $this->applyEquipoFilters($docFreeBase, $request, array_keys(self::DOC_FILTER_COLS));
                $this->applyBusquedaTexto($docFreeBase, $search); // "con/sin doc" refleja la búsqueda
                if ($filtroEstado !== 'DESINCORPORADO') {
                    $docFreeBase->where('ESTADO_OPERATIVO', '!=', 'DESINCORPORADO');
                }
                $conDoc = (clone $docFreeBase);
                $this->applyDocPresence($conDoc, $docFilters);

                $stats['doc_mode']  = true;
                $stats['doc_label'] = $this->docFilterLabel($docFilters);
                $stats['doc_total'] = (clone $docFreeBase)->count();
                $stats['doc_con']   = $conDoc->count();
                $stats['doc_sin']   = max(0, $stats['doc_total'] - $stats['doc_con']);
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
                    $this->applyBusquedaTexto($ubicQuery, $search); // ubicaciones reflejan la búsqueda
                    $rawUbicaciones = $ubicQuery
                        ->select(
                            DB::raw("COALESCE(NULLIF(TRIM(DETALLE_UBICACION_ACTUAL), ''), '__SIN_ASIGNAR__') as ubi_key"),
                            DB::raw('COUNT(*) as total')
                        )
                        ->groupBy('ubi_key')
                        ->orderBy('total', 'desc')
                        ->get();
                    $ubicacionesStats = $rawUbicaciones->map(fn($r) => (object) [
                        'detalle' => $r->ubi_key === '__SIN_ASIGNAR__' ? 'Sin Especificación' : $r->ubi_key,
                        'total'   => $r->total,
                    ]);
                }
            }
        }
        // else: $stats queda en ceros => la vista muestra '--' (comportamiento original)

        // ── Distribución (card lateral / Dashboard en teléfono) ─────────────────────────
        // Solo con FILTRO activo, igual que $stats. Estuvo un tiempo calculandose siempre,
        // para que la card sirviera de punto de partida (tocar un tipo aplica su filtro), y
        // el comentario de entonces daba por hecho que "el coste es bajo". Medido, no lo es:
        // el GROUP BY por tipo tarda ~259 ms y su HTML pesa ~60 KB con 49 tipos. Eso se
        // pagaba en CADA apertura de /admin/equipos, y encima con la tabla vacia porque sin
        // filtro no se vuelca ninguna fila: se calculaba y se enviaba un bloque que el
        // usuario no habia pedido, antes de que pidiera nada.
        // Decision del cliente: al abrir, la card no muestra nada; aparece al filtrar.
        // Sigue omitiendose en modo aux: alli la Distribucion la aporta el payload auxiliar.
        if ($hasFilter && !$auxMode) {
            // Tipos Stats — siempre muestra todos los tipos (sin filtro por id_tipo) para no autolimitarse
            $tiposQuery = Equipo::query()->leftJoin('tipo_equipos', 'equipos.id_tipo_equipo', '=', 'tipo_equipos.id');
            $this->applyEquipoFilters($tiposQuery, $request, ['id_tipo']);
            $this->applyBusquedaTexto($tiposQuery, $search); // Distribución por tipo refleja la búsqueda
            $tiposStats = $tiposQuery
                ->select('equipos.id_tipo_equipo', 'tipo_equipos.nombre', DB::raw('COUNT(*) as total'))
                ->groupBy('equipos.id_tipo_equipo', 'tipo_equipos.nombre')
                ->orderBy('tipo_equipos.nombre', 'asc')
                ->get();

            // Frentes Stats — se muestra cuando hay un tipo filtrado; listamos TODOS los frentes que coinciden (sin filtro id_frente)
            if ($request->filled('id_tipo')) {
                $frentesQuery = Equipo::query()->leftJoin('frentes_trabajo', 'equipos.ID_FRENTE_ACTUAL', '=', 'frentes_trabajo.ID_FRENTE');
                $this->applyEquipoFilters($frentesQuery, $request, ['id_frente']);
                $this->applyBusquedaTexto($frentesQuery, $search); // frentes stats reflejan la búsqueda
                $frentesStats = $frentesQuery
                    ->whereNotNull('equipos.ID_FRENTE_ACTUAL')
                    ->select('equipos.ID_FRENTE_ACTUAL', 'frentes_trabajo.NOMBRE_FRENTE', DB::raw('COUNT(*) as total'))
                    ->groupBy('equipos.ID_FRENTE_ACTUAL', 'frentes_trabajo.NOMBRE_FRENTE')
                    ->orderBy('frentes_trabajo.NOMBRE_FRENTE', 'asc')
                    ->get();
            }
        }

        // Build JSON payload (needed for AJAX response AND initial page load script tag)
        // En modo aux la tabla es de auxiliares: el payload de modal de equipos no aplica.
        $jsonPayload = [];
        if ($hasFilter && !$auxMode) {
            // El mapeo del payload vive en UN solo lugar: Equipo::toDetailsPayload()
            // (reusado por el panel de Alertas en /menu para que el modal sea idéntico).
            foreach ($equipos as $eq) {
                $jsonPayload[$eq->ID_EQUIPO] = $eq->toDetailsPayload();
            }
        }

        // Modo aux: la TABLA y su modal de detalles vienen del modulo de auxiliares,
        // filtrados con los params mapeados al módulo aux
        // (tipo, id_frente, search, detalle_ubicacion, confirmado, marca, modelo, estado, anio, con_propiedad, con_certificado, offset).
        $auxEmbed = null;
        if ($auxMode) {
            $auxReq = $this->auxModeRequest($request);
            $auxEmbed = app(\App\Http\Controllers\EquipoAuxiliarController::class)->buildEmbedPayload($auxReq);
            // El Consolidado en modo aux refleja AUXILIARES (no equipos): se reemplazan
            // las stats que pintan la vista y el JS (mismas claves total/activos/inactivos).
            $stats = $auxEmbed['stats'];
        }

        // ¿El filtro apunta SOLO a equipos? (atributo de vehículo o documento solo-equipos).
        // Gobierna por igual el merge de filas y la visibilidad de la card (ver esFocoSoloEquipos).
        $focusEquiposOnly = $this->esFocoSoloEquipos($request);

        // Request con los ejes COMPARTIDOS mapeados al módulo aux: lo consumen TANTO las filas
        // aux anexadas a la tabla (merge) COMO el consolidado, así filtran EXACTAMENTE igual que
        // la tabla de equipos (buscar un serial/placa, subzona, estatus, confirmación o "con
        // propiedad" filtra los aux como si fueran la misma tabla). Ver auxSharedRequest.
        $auxSharedReq = $this->auxSharedRequest($request);

        // ── MERGE de auxiliares en la tabla de equipos ──────────────────────────
        // Cuando hay un EJE COMPARTIDO activo (frente, búsqueda de serial/placa/código, o un doc
        // compartido propiedad/certificado) — sin enfoque solo-equipos y fuera del modo aux-only —
        // la tabla lista los equipos y, al final del ÚLTIMO lote del scroll infinito, anexa las
        // filas de AUXILIARES que coinciden con los MISMOS filtros (filas funcionales: reutilizan
        // el partial y la maquinaria aux ya cargada). Así un serial buscado o un auxiliar con
        // certificado/propiedad sale DIRECTO en la tabla, no en un banner aparte. Reutiliza
        // buildEmbedPayload (sin duplicar el filtrado/cap) y SOLO se agrega cuando !$hasMore para
        // que los auxiliares aparezcan UNA sola vez, al final. auxFiltroCompartidoActivo es la
        // MISMA condición que usa la hoja aux de export() → tabla, card y Excel coinciden.
        $mergeAux = $hasFilter && !$auxMode
            && !$focusEquiposOnly
            && $this->auxFiltroCompartidoActivo($request);
        $mergeAuxHtml = '';
        $mergeAuxData = [];
        if ($mergeAux && !$hasMore) {
            $auxMergePayload = app(\App\Http\Controllers\EquipoAuxiliarController::class)->buildEmbedPayload($auxSharedReq);
            if (($auxMergePayload['totalFound'] ?? 0) > 0) {
                // Sin separador: los auxiliares se anexan directamente a la tabla de
                // equipos (el cliente no quiere la fila divisoria "Equipos Auxiliares (n)").
                $mergeAuxHtml = $auxMergePayload['html'];
                $mergeAuxData = $auxMergePayload['auxDetailsMap'];
            }
        }
        // Mapa de detalles aux para el render INICIAL (lo consume el partial _machinery):
        // en modo aux-only sale del payload aux; en merge, de los auxiliares anexados.
        $auxInitDetailsMap = $auxMode ? ($auxEmbed['auxDetailsMap'] ?? []) : $mergeAuxData;

        // Consolidado de AUXILIARES (TOTAL/Operativos/Inoperativos) del frente filtrado,
        // para el panel que va DEBAJO del consolidado de equipos. Se calcula SIEMPRE y
        // ANTES del return JSON (el filtrado AJAX retorna aquí). Visibilidad de la card:
        //  - sin filtro o filtro por FRENTE         → las DOS cards (equipos + auxiliares)
        //  - enfoque solo-equipos ($focusEquiposOnly) → solo equipos (se oculta esta card):
        //    atributo de vehículo (tipo, modelo, marca, color, año, GPS, categoría flota) o
        //    documento solo-equipos (póliza, ROTC, RACDA, certificado, compraventa)
        //  - modo aux (tipo/categoría auxiliar)     → la card principal de arriba ya es de
        //    auxiliares, así que esta se oculta para no duplicar el conteo.
        // Mismo $auxSharedReq que el merge → el conteo de la card coincide EXACTAMENTE con
        // los auxiliares mostrados en la tabla.
        $auxConsolidado = app(\App\Http\Controllers\EquipoAuxiliarController::class)->consolidadoStats($auxSharedReq);
        // $focusEquiposOnly ya se calculó arriba (gobierna también el merge de la tabla).
        $showAuxConsolidado = !$auxMode && !$focusEquiposOnly;

        // Distribución de AUXILIARES para la sección "Auxiliares" de la card de Distribución
        // (lista unificada). Requiere $hasFilter, IGUAL que la distribución de equipos (que oculta
        // sus filas sin filtro): así al abrir el módulo SIN filtrar la card no muestra datos de
        // auxiliares (antes se veían todos al no tener este gate). Con filtro sí se calcula. Vacío
        // en cualquier otro caso → el front no agrega la sección. Mismos ejes compartidos
        // ($auxSharedReq) que la tabla y el consolidado.
        $auxDistributionHtml = ($showAuxConsolidado && $hasFilter)
            ? app(\App\Http\Controllers\EquipoAuxiliarController::class)->distribucionHtml($auxSharedReq)
            : '';

        if ($request->wantsJson()) {
            return response()->json([
                'mode'         => $auxMode ? 'aux' : 'equipos',
                // En merge, anexamos las filas de auxiliares al HTML de equipos. Si el frente
                // no tiene equipos pero sí auxiliares, omitimos el empty-state de equipos para
                // no mostrar "no hay equipos" encima de la sección de auxiliares.
                'html'         => $auxMode
                    ? $auxEmbed['html']
                    : (($equipos->isEmpty() && $mergeAuxHtml !== '')
                        ? $mergeAuxHtml
                        : view('admin.equipos.partials.table_rows', compact('equipos'))->render() . $mergeAuxHtml),
                'equiposData'  => $jsonPayload,
                // Mapa de detalles de auxiliares (modal del ojo): en aux-only del payload aux;
                // en merge, de los auxiliares anexados (vacío si no hay merge en este lote).
                'auxData'      => $auxMode ? $auxEmbed['auxDetailsMap'] : $mergeAuxData,
                'pagination'   => '',
                'stats'        => $stats,
                'auxConsolidado'     => $auxConsolidado,
                'showAuxConsolidado' => $showAuxConsolidado,
                'truncated'         => $truncated,
                'totalFound'        => $auxMode ? $auxEmbed['totalFound'] : $totalFound,
                'shownCount'        => $auxMode ? $auxEmbed['shownCount'] : $allResults->count(),
                'hardCap'           => $PAGE_SIZE,
                'pageSize'          => $PAGE_SIZE,
                'offset'            => $offset,
                'nextOffset'        => $auxMode ? $auxEmbed['nextOffset'] : $nextOffset,
                'hasMore'           => $auxMode ? $auxEmbed['hasMore'] : $hasMore,
                'distribution'      => $auxMode
                    ? $auxEmbed['distribucionHtml']
                    : view('admin.equipos.partials.distribution_stats', [
                        'frentesStats' => $frentesStats,
                        'tiposStats'   => $tiposStats,
                        'hasFilter'    => $hasFilter,
                        'showFrentes'  => ($request->filled('id_tipo') && $request->id_tipo !== 'all')
                                          && !($request->filled('id_frente') && $request->id_frente !== 'all'),
                    ])->render(),
                'ubicaciones'       => view('admin.equipos.partials.ubicaciones_stats', compact('ubicacionesStats', 'hasFilter', 'frenteEspecial'))->render(),
                'showUbicaciones'   => !$auxMode && $frenteEspecial !== null,
                // HTML de la distribución de auxiliares para el toggle de la card (vacío si la card
                // aux no aplica → el front desactiva el toggle).
                'auxDistribution'   => $auxDistributionHtml,
            ])->withHeaders([
                // Evita que el browser sirva respuestas JSON cacheadas con stats obsoletas
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma'        => 'no-cache',
                'Expires'       => '0',
            ]);
        }

        // El dropdown de frentes oculta los que el usuario no puede ver (lista blanca
        // LOCAL + lista negra de bloqueados): un frente bloqueado no aparece como filtro.
        $frentesQuery = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE', 'asc');
        if ($user) {
            $user->aplicarScopeFrentesEquipos($frentesQuery, 'ID_FRENTE');
        }
        $frentes = $frentesQuery->get();

        // Base de "equipos visibles" para el usuario (whitelist LOCAL + blacklist de
        // bloqueados). De aquí salen los tipos del filtro, para que un LOCAL NO vea tipos
        // que no existen en sus frentes (antes traía TODOS los TipoEquipo del sistema).
        $tiposVisiblesBase = Equipo::query()->whereNotNull('id_tipo_equipo');
        if ($user) {
            $user->aplicarScopeFrentesEquipos($tiposVisiblesBase, 'ID_FRENTE_ACTUAL');
        }

        $tipoIdsVisibles = (clone $tiposVisiblesBase)
            ->distinct()->pluck('id_tipo_equipo')->map(fn ($v) => (int) $v)->all();
        $allTipos = TipoEquipo::whereIn('id', $tipoIdsVisibles)
            ->orderBy('nombre', 'asc')->get();

        // Tipos de AUXILIAR (codigo => label) en el scope del usuario, para la seccion
        // AUXILIARES del dropdown combinado de Tipo. Reusa el builder del modulo aux.
        $tiposAux = app(\App\Http\Controllers\EquipoAuxiliarController::class)->buildTiposMap();

        // Mapa { ID_FRENTE_ACTUAL : [ID_TIPO, ...] } con los tipos presentes en cada frente
        // VISIBLE. Lo usa el filtro "Tipo" para mostrar SOLO los tipos del frente seleccionado
        // (filtro dependiente). Clave 'none' = equipos sin frente. distinct (frente, tipo) → barato.
        $tiposPorFrente = (clone $tiposVisiblesBase)
            ->select('ID_FRENTE_ACTUAL', 'id_tipo_equipo')
            ->distinct()
            ->get()
            ->groupBy(fn ($e) => $e->ID_FRENTE_ACTUAL ?: 'none')
            ->map(fn ($g) => $g->pluck('id_tipo_equipo')->map(fn ($v) => (int) $v)->values())
            ->toArray();

        // Mapas { id_tipo : [MARCA...] } y { id_tipo : [MODELO...] } para que los filtros
        // avanzados Marca y Modelo sugieran SOLO lo que existe en el tipo elegido, igual que
        // el filtro Tipo depende del Frente. Salen de $tiposVisiblesBase, asi que respetan el
        // scope del usuario (las listas $availableMarcas/$availableModelos de abajo son un
        // cache GLOBAL y no lo hacen). distinct (tipo, marca) → una fila por combinacion.
        $porTipo = function (string $col) use ($tiposVisiblesBase) {
            return (clone $tiposVisiblesBase)
                ->whereNotNull($col)->where($col, '!=', '')
                ->select('id_tipo_equipo', $col)
                ->distinct()
                ->get()
                ->groupBy('id_tipo_equipo')
                ->map(fn ($g) => $g->pluck($col)->map(fn ($v) => trim((string) $v))->unique()->values())
                ->toArray();
        };
        $marcasPorTipo  = $porTipo('MARCA');
        $modelosPorTipo = $porTipo('MODELO');

        // Advanced Filter Lists (Optimized with cache: Only needed for initial page load, not AJAX)
        $availableModelos = \Illuminate\Support\Facades\Cache::remember('equipos_modelos_dropdown', 1200, function () {
            return Equipo::distinct()->whereNotNull('MODELO')->where('MODELO', '!=', '')->orderBy('MODELO', 'asc')->pluck('MODELO');
        });

        $availableMarcas = \Illuminate\Support\Facades\Cache::remember('equipos_marcas_dropdown', 1200, function () {
            return Equipo::distinct()->whereNotNull('MARCA')->where('MARCA', '!=', '')->orderBy('MARCA', 'asc')->pluck('MARCA');
        });

        $availableAnios = \Illuminate\Support\Facades\Cache::remember('equipos_anios_dropdown', 1200, function () {
            return Equipo::distinct()->whereNotNull('ANIO')->orderBy('ANIO', 'desc')->pluck('ANIO');
        });

        $availableColores = \Illuminate\Support\Facades\Cache::remember('equipos_colores_dropdown', 1200, function () {
            return Equipo::distinct()->whereNotNull('COLOR')->where('COLOR', '!=', '')->orderBy('COLOR', 'asc')->pluck('COLOR');
        });

        $auxMarcas = \Illuminate\Support\Facades\Cache::remember('aux_marcas_dropdown', 1200, function () {
            return \App\Models\EquipoAuxiliar::distinct()->whereNotNull('MARCA')->where('MARCA', '!=', '')->orderBy('MARCA', 'asc')->pluck('MARCA');
        });
        $auxModelos = \Illuminate\Support\Facades\Cache::remember('aux_modelos_dropdown', 1200, function () {
            return \App\Models\EquipoAuxiliar::distinct()->whereNotNull('MODELO')->where('MODELO', '!=', '')->orderBy('MODELO', 'asc')->pluck('MODELO');
        });
        $auxAnios = \Illuminate\Support\Facades\Cache::remember('aux_anios_dropdown', 1200, function () {
            return \App\Models\EquipoAuxiliar::distinct()->whereNotNull('ANIO')->orderBy('ANIO', 'desc')->pluck('ANIO');
        });

        $showFrentes = ($request->filled('id_tipo') && $request->id_tipo !== 'all' && !$auxMode)
                       && !($request->filled('id_frente') && $request->id_frente !== 'all');

        // $auxConsolidado y $showAuxConsolidado ya se calcularon arriba (antes del
        // return JSON), así están disponibles tanto para el AJAX como para esta vista.

        return view('admin.equipos.index', compact('equipos', 'stats', 'frentes', 'allTipos', 'tiposPorFrente', 'marcasPorTipo', 'modelosPorTipo', 'tiposStats', 'frentesStats', 'ubicacionesStats', 'frenteEspecial', 'availableModelos', 'availableMarcas', 'availableAnios', 'availableColores', 'auxMarcas', 'auxModelos', 'auxAnios', 'jsonPayload', 'showFrentes', 'auxMode', 'auxModeByTipo', 'auxEmbed', 'tiposAux', 'auxConsolidado', 'showAuxConsolidado', 'auxDistributionHtml', 'mergeAuxHtml', 'auxInitDetailsMap', 'hasFilter'));
    }

    public function export(Request $request)
    {
        // CRITICAL: Prevent exporting entire database without filters.
        // 'id_frente=all' es un filtro explícito válido (el usuario seleccionó "Todos los Frentes").
        $hasFilter = $request->filled('id_frente')   // incluye 'all' como filtro válido
            || $request->filled('id_tipo')
            || $request->filled('search_query')
            || $request->filled('modelo')
            || $request->filled('marca')
            || $request->filled('color')
            || $request->filled('confirmado')
            || $request->filled('detalle_ubicacion')
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

        // $search normalizado (uppercase+trim) SOLO para el bloque de búsqueda de
        // abajo. La barrera de visibilidad por frente (scope LOCAL + bloqueados) la
        // aplica applyEquipoFilters() como FUENTE ÚNICA — antes este método la repetía
        // por separado (duplicidad). Ya cubre el caso de búsqueda vacía/solo-espacios.
        $search = strtoupper(trim((string) $request->input('search_query', '')));

        // Mismos filtros que el listado (frente/tipo/atributos/gps/color/confirmado/
        // documentación) reutilizando applyEquipoFilters() en vez de reimplementarlos.
        // Antes estaban DUPLICADOS aquí, y este bloque además tenía un bug: el id_tipo
        // no manejaba el prefijo 'tipo_eq:'/'tipo_aux:' del dropdown, y los doc filters
        // ignoraban doc_presence (sin/all). Ahora el export coincide 1:1 con la tabla.
        $this->applyEquipoFilters($equipos, $request);

        // En modo AUXILIAR (tipo aux elegido o categoria=AUXILIARES) la pantalla muestra SOLO
        // auxiliares, así que la hoja "Equipos" sale vacía (espejo de index(), que deja la
        // lista de equipos vacía en modo aux). El export = lo que se ve en pantalla.
        if ($this->esModoAux($request)) {
            $equipos->whereRaw('1 = 0');
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
            'documentacion:ID_EQUIPO,PLACA,LINK_DOC_PROPIEDAD,NOMBRE_DEL_TITULAR,LINK_POLIZA_SEGURO,FECHA_VENC_POLIZA,LINK_RACDA,FECHA_RACDA,LINK_ROTC,FECHA_ROTC,LINK_DOC_ADICIONAL,FECHA_ADICIONAL',
            'equiposAnclados:ID_EQUIPO,id_tipo_equipo,ID_FRENTE_ACTUAL,MARCA,MODELO,SERIAL_CHASIS,SERIAL_DE_MOTOR,ANIO,ESTADO_OPERATIVO,CATEGORIA_FLOTA,ID_ANCLAJE',
            'equiposAnclados.tipo:id,nombre',
            'equiposAnclados.documentacion:ID_EQUIPO,PLACA,LINK_DOC_PROPIEDAD,NOMBRE_DEL_TITULAR,LINK_POLIZA_SEGURO,FECHA_VENC_POLIZA,LINK_RACDA,FECHA_RACDA,LINK_ROTC,FECHA_ROTC,LINK_DOC_ADICIONAL,FECHA_ADICIONAL',
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

        $sheet->getRowDimension(1)->setRowHeight(40);
        $sheet->getRowDimension(2)->setRowHeight(40);
        $sheet->getRowDimension(3)->setRowHeight(40);

        // Logo centrado en A1:B3 (trait ExcelLogoCorporativo)
        $this->insertarLogoCorporativo($sheet, ['A','B'], [1,2,3]);

        $showFrenteCol = ($nombreFrente === 'TODOS LOS FRENTES');
        // +10 columnas de documentación: SÍ/NO + dato por cada uno (Tít.Prop+Titular /
        // Póliza+Venc / RACDA+Venc / ROTC+Venc / Certificado+Venc):
        // con FRENTE → A..U ; sin FRENTE → A..T
        $lastCol      = $showFrenteCol ? 'U' : 'T';
        $endTitle     = $showFrenteCol ? 'O' : 'N'; // título C:endTitle (encabezado ancho)
        $startEdicion = $showFrenteCol ? 'P' : 'O'; // EDICION/REV/FECHA: startEdicion..lastCol (4 cols, angosto)

        // Fila 1 a 3 - Título Empresa
        $sheet->mergeCells('A1:B3');
        $sheet->getStyle('A1:B3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF'); // Fondo Blanco Puro

        // Título: C..$endTitle (más ancho — ocupa la mayor parte del header)
        $sheet->mergeCells('C1:'.$endTitle.'3');
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
        $sheet->getStyle('C1:'.$endTitle.'3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF'); // Blanco

        // Bloque EDICION / REVISION / FECHA: a la derecha, ocupando sólo $startEdicion..$lastCol (angosto).
        $sheet->mergeCells($startEdicion.'1:'.$lastCol.'1');
        $sheet->setCellValue($startEdicion.'1', 'EDICION: 1');
        $sheet->getStyle($startEdicion.'1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($startEdicion.'1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle($startEdicion.'1')->getFont()->setBold(true)->setSize(11)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $sheet->getStyle($startEdicion.'1:'.$lastCol.'1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        $sheet->mergeCells($startEdicion.'2:'.$lastCol.'2');
        $sheet->setCellValue($startEdicion.'2', 'REVISION: 0');
        $sheet->getStyle($startEdicion.'2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($startEdicion.'2')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle($startEdicion.'2')->getFont()->setBold(true)->setSize(11)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $sheet->getStyle($startEdicion.'2:'.$lastCol.'2')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        $sheet->mergeCells($startEdicion.'3:'.$lastCol.'3');
        $sheet->setCellValue($startEdicion.'3', 'FECHA: ' . $currentDate);
        $sheet->getStyle($startEdicion.'3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($startEdicion.'3')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle($startEdicion.'3')->getFont()->setBold(true)->setSize(11)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $sheet->getStyle($startEdicion.'3:'.$lastCol.'3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

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

        // Fila 5 - Encabezados de tabla. Cada documento usa 2 columnas: SÍ/NO + dato (titular o fecha de vencimiento).
        $docHeaders = [
            "TÍTULO DE\nPROPIEDAD", 'TITULAR',
            'PÓLIZA',                "VENC.\nPÓLIZA",
            'RACDA',                 "VENC.\nRACDA",
            'ROTC',                  "VENC.\nROTC",
            'CERTIFICADO',           "VENC.\nCERTIF.",
        ];
        if ($showFrenteCol) {
            $headers = array_merge(['N°', 'FRENTE', 'TIPO', 'MARCA', 'MODELO', 'CATEGORÍA DE FLOTA', 'SERIAL DE CHASIS', 'SERIAL DE MOTOR', 'PLACA', 'AÑO', 'ESTADO'], $docHeaders);
            $colMap  = ['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U'];
        } else {
            $headers = array_merge(['N°', 'TIPO', 'MARCA', 'MODELO', 'CATEGORÍA DE FLOTA', 'SERIAL DE CHASIS', 'SERIAL DE MOTOR', 'PLACA', 'AÑO', 'ESTADO'], $docHeaders);
            $colMap  = ['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T'];
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
            $sheet->getColumnDimension('L')->setWidth(9);  // Título de propiedad (SÍ/NO)
            $sheet->getColumnDimension('M')->setWidth(30); // Titular
            $sheet->getColumnDimension('N')->setWidth(9);  // Póliza (SÍ/NO)
            $sheet->getColumnDimension('O')->setWidth(13); // Venc. Póliza
            $sheet->getColumnDimension('P')->setWidth(9);  // RACDA (SÍ/NO)
            $sheet->getColumnDimension('Q')->setWidth(13); // Venc. RACDA
            $sheet->getColumnDimension('R')->setWidth(9);  // ROTC (SÍ/NO)
            $sheet->getColumnDimension('S')->setWidth(13); // Venc. ROTC
            $sheet->getColumnDimension('T')->setWidth(14); // Certificado (SÍ/NO) — ancho p/ que el header "CERTIFICADO" no se corte
            $sheet->getColumnDimension('U')->setWidth(13); // Venc. Certificado
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
            $sheet->getColumnDimension('K')->setWidth(9);  // Título de propiedad (SÍ/NO)
            $sheet->getColumnDimension('L')->setWidth(30); // Titular
            $sheet->getColumnDimension('M')->setWidth(9);  // Póliza (SÍ/NO)
            $sheet->getColumnDimension('N')->setWidth(13); // Venc. Póliza
            $sheet->getColumnDimension('O')->setWidth(9);  // RACDA (SÍ/NO)
            $sheet->getColumnDimension('P')->setWidth(13); // Venc. RACDA
            $sheet->getColumnDimension('Q')->setWidth(9);  // ROTC (SÍ/NO)
            $sheet->getColumnDimension('R')->setWidth(13); // Venc. ROTC
            $sheet->getColumnDimension('S')->setWidth(14); // Certificado (SÍ/NO) — ancho p/ que el header "CERTIFICADO" no se corte
            $sheet->getColumnDimension('T')->setWidth(13); // Venc. Certificado
        }

        $printedIds  = [];
        $ancladoRows = []; // trackeamos filas de anclados para aplicar italic en batch al final
        $rowNum  = 6;
        $counter = 1;

        // $colMap / $lastCol NO se capturan en el use del closure: antes los usaba el
        // styling per-cell que vivia adentro; ahora ese styling vive en el bloque batch
        // post-foreach (que SI los usa en el scope externo).
        $printEquipoRow = function($equipo, $isAnclado = false) use (&$sheet, &$rowNum, &$counter, &$printedIds, &$ancladoRows, $showFrenteCol, &$printEquipoRow) {
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

            // Documentación → 8 columnas pareadas: por cada doc, una columna SÍ/NO y otra con el dato
            // (Titular para Título de propiedad, fecha de vencimiento para Póliza/RACDA/ROTC).
            // Si el doc no está cargado → "NO" en la SÍ/NO y "—" en la del dato.
            // Si está cargado pero falta el dato → "SÍ" en la primera y "—" en el dato.
            $cargado = fn ($link) => trim((string) $link) !== '';
            $fmtFecha = function ($fecha) {
                if (empty($fecha)) return '—';
                try {
                    return \Carbon\Carbon::parse($fecha)->format('d/m/Y');
                } catch (\Exception $e) {
                    return '—';
                }
            };
            $doc     = $equipo->documentacion;
            $docCols = $showFrenteCol
                ? ['L','M','N','O','P','Q','R','S','T','U']  // Prop|Tit | Pol|Venc | Racda|Venc | Rotc|Venc | Cert|Venc
                : ['K','L','M','N','O','P','Q','R','S','T'];

            $tieneProp   = $doc && $cargado($doc->LINK_DOC_PROPIEDAD);
            $tienePoliza = $doc && $cargado($doc->LINK_POLIZA_SEGURO);
            $tieneRacda  = $doc && $cargado($doc->LINK_RACDA);
            $tieneRotc   = $doc && $cargado($doc->LINK_ROTC);
            $tieneCert   = $doc && $cargado($doc->LINK_DOC_ADICIONAL); // Certificado = doc. adicional

            $titular = ($doc && $tieneProp) ? trim((string) $doc->NOMBRE_DEL_TITULAR) : '';

            $sheet->setCellValue($docCols[0].$rowNum, $tieneProp   ? 'SÍ' : 'NO'); // Título de propiedad (SÍ/NO)
            $sheet->setCellValue($docCols[1].$rowNum, $titular !== '' ? mb_strtoupper($titular) : '—'); // Titular
            $sheet->setCellValue($docCols[2].$rowNum, $tienePoliza ? 'SÍ' : 'NO'); // Póliza (SÍ/NO)
            $sheet->setCellValue($docCols[3].$rowNum, $tienePoliza ? $fmtFecha($doc->FECHA_VENC_POLIZA) : '—'); // Venc. Póliza
            $sheet->setCellValue($docCols[4].$rowNum, $tieneRacda  ? 'SÍ' : 'NO'); // RACDA (SÍ/NO)
            $sheet->setCellValue($docCols[5].$rowNum, $tieneRacda  ? $fmtFecha($doc->FECHA_RACDA) : '—'); // Venc. RACDA
            $sheet->setCellValue($docCols[6].$rowNum, $tieneRotc   ? 'SÍ' : 'NO'); // ROTC (SÍ/NO)
            $sheet->setCellValue($docCols[7].$rowNum, $tieneRotc   ? $fmtFecha($doc->FECHA_ROTC) : '—'); // Venc. ROTC
            $sheet->setCellValue($docCols[8].$rowNum, $tieneCert   ? 'SÍ' : 'NO'); // Certificado (SÍ/NO)
            $sheet->setCellValue($docCols[9].$rowNum, $tieneCert   ? $fmtFecha($doc->FECHA_ADICIONAL) : '—'); // Venc. Certificado

            // Estilos por celda removidos: se aplican en LOTE despues del foreach al
            // rango completo de datos (ver bloque "Estilos de filas de datos en lote").
            // Antes: ~25 ops de estilo por equipo -> para 200 equipos = ~5000 operaciones
            // individuales en PhpSpreadsheet (notoriamente lento). Ahora: 1 op por rango.
            // Solo trackeamos las filas que son anclados para el italic batch posterior.
            if ($isAnclado) {
                $ancladoRows[] = $rowNum;
            }

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

        // ── Estilos de filas de datos EN LOTE (optimizacion) ─────────────────
        // Aplicamos UNA SOLA llamada por rango en vez de celda-por-celda dentro del
        // loop. Resultado visual identico; tiempo de generacion ~5-10x mas rapido en
        // datasets de 100+ equipos.
        $lastDataRow  = $rowNum - 1;
        $firstDataRow = 6;
        if ($lastDataRow >= $firstDataRow) {
            $dataRange = "A{$firstDataRow}:{$lastCol}{$lastDataRow}";

            // 1) WrapText + alineacion vertical para TODAS las celdas de datos
            $sheet->getStyle($dataRange)->getAlignment()
                ->setWrapText(true)
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            // 2) Columna N° (A): centrada
            $sheet->getStyle("A{$firstDataRow}:A{$lastDataRow}")->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            // 3) Columnas principales centradas (segun layout con o sin FRENTE)
            $centerCols = $showFrenteCol
                ? ['B', 'D', 'F', 'G', 'H', 'I']   // FRENTE, MARCA, CATEG, CHASIS, MOTOR, PLACA
                : ['C', 'E', 'F', 'G', 'H'];        // MARCA, CATEG, CHASIS, MOTOR, PLACA
            foreach ($centerCols as $c) {
                $sheet->getStyle("{$c}{$firstDataRow}:{$c}{$lastDataRow}")->getAlignment()
                    ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            }

            // 4) AÑO y ESTADO siempre centradas
            $colAnio   = $showFrenteCol ? 'J' : 'I';
            $colEstado = $showFrenteCol ? 'K' : 'J';
            $sheet->getStyle("{$colAnio}{$firstDataRow}:{$colAnio}{$lastDataRow}")->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("{$colEstado}{$firstDataRow}:{$colEstado}{$lastDataRow}")->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            // 5) Columnas de documentos: centradas EXCEPTO la del TITULAR (idx 1 -> left)
            $docColsBatch = $showFrenteCol
                ? ['L','M','N','O','P','Q','R','S','T','U']
                : ['K','L','M','N','O','P','Q','R','S','T'];
            foreach ($docColsBatch as $idx => $dc) {
                $align = $idx === 1
                    ? \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT
                    : \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER;
                $sheet->getStyle("{$dc}{$firstDataRow}:{$dc}{$lastDataRow}")->getAlignment()
                    ->setHorizontal($align);
            }

            // 6) Alto de fila + zebra striping (1 op por fila, no era el cuello de botella)
            for ($r = $firstDataRow; $r <= $lastDataRow; $r++) {
                $sheet->getRowDimension($r)->setRowHeight(30);
                $argb = ((($r - $firstDataRow + 1) % 2) === 0) ? 'FFF1F5F9' : 'FFFFFFFF';
                $sheet->getStyle("A{$r}:{$lastCol}{$r}")
                    ->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB($argb);
            }

            // 7) Italic + color tenue en columna TIPO de equipos anclados
            if (!empty($ancladoRows)) {
                $tipoCol = $showFrenteCol ? 'C' : 'B';
                foreach ($ancladoRows as $r) {
                    $sheet->getStyle("{$tipoCol}{$r}")->getFont()
                        ->setItalic(true)
                        ->getColor()->setARGB('FF475569');
                }
            }
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

        // Hoja 2: Equipos Auxiliares — MISMA regla que la pantalla (index): se incluyen los
        // auxiliares cuando estamos en modo aux, o cuando NO hay enfoque solo-equipos y hay un eje
        // compartido activo (frente, búsqueda o doc compartido) — auxFiltroCompartidoActivo, el
        // MISMO gate del merge. La hoja se agrega si hay auxiliares que exportar; y TAMBIÉN en modo
        // aux aunque esté vacía, porque ahí la hoja aux es la PRINCIPAL (espejo de la pantalla, que
        // muestra la tabla de auxiliares aunque salga vacía) y debe quedar al menos una hoja con la
        // etiqueta correcta. Fuera de modo aux, una hoja aux sin filas NO se crea. El filtrado se
        // delega a EquipoAuxiliarController::exportQuery (reutiliza applyAuxiliarFilters), sin duplicar.
        $auxMode    = $this->esModoAux($request);
        $incluirAux = $auxMode
            || (!$this->esFocoSoloEquipos($request) && $this->auxFiltroCompartidoActivo($request));
        if ($incluirAux) {
            $auxReqExport = $auxMode ? $this->auxModeRequest($request) : $this->auxSharedRequest($request);
            $auxQuery = app(\App\Http\Controllers\EquipoAuxiliarController::class)
                ->exportQuery($auxReqExport)
                ->with('frente:ID_FRENTE,NOMBRE_FRENTE');
            if ($auxMode || (clone $auxQuery)->exists()) {
                $this->appendAuxSheet($spreadsheet, $auxQuery, $nombreFrente);
            }
        }

        // Si el listado de equipos quedó vacío (p.ej. modo auxiliar: la pantalla solo muestra
        // auxiliares) NO dejamos una hoja "Equipos" vacía — se elimina, siempre que exista otra
        // hoja para no producir un libro sin hojas. En modo aux la hoja aux siempre se agregó
        // arriba, así que el libro queda solo con "Equipos Auxiliares". Espejo de la regla de la
        // hoja aux: cada hoja aparece solo si corresponde a lo que se ve en pantalla.
        if ($equiposList->isEmpty() && $spreadsheet->getSheetCount() > 1) {
            $equiposSheet = $spreadsheet->getSheetByName('Equipos');
            if ($equiposSheet !== null) {
                $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($equiposSheet));
            }
        }

        // El libro debe ABRIRSE en la PRIMERA hoja. No es cosmetico: PhpSpreadsheet marca
        // como activa la hoja a la que se le pide getStyle(), asi que al pintar la hoja de
        // auxiliares (lo ultimo que se hace) el libro se guardaba con activeTab="1" y Excel
        // abria en "Equipos Auxiliares". Se fija aqui, DESPUES de todo el pintado y de la
        // posible eliminacion de hojas, y justo antes de escribir.
        // Ademas se deja A1 seleccionada en cada hoja: getStyle() tambien mueve la seleccion,
        // asi que si no, el archivo abre con un rango cualquiera marcado y desplazado.
        foreach ($spreadsheet->getAllSheets() as $hoja) {
            $hoja->setSelectedCell('A1');
        }
        $spreadsheet->setActiveSheetIndex(0);

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

    /**
     * Invalida las listas cacheadas de marca/modelo/año/color que alimentan los
     * autocompletes del formulario (_v3, TTL 60s) y los filtros del indice (_dropdown).
     * Punto UNICO: la lista estaba copiada en store() y update() y ya divergia
     * (update no limpiaba 'equipos_modelos_list'), ademas de faltar por completo en
     * bulkStoreBatch() — tras importar por Excel, marcas y modelos nuevos no aparecian.
     */
    private function olvidarCachesListasEquipos(): void
    {
        foreach ([
            'equipos_modelos_list',     // autocomplete del catálogo
            'equipos_modelos_dropdown', // filtros del índice
            'equipos_marcas_dropdown',
            'equipos_anios_dropdown',
            'equipos_colores_dropdown',
            'marcas_list_form_v3',      // formulario create/edit
            'modelos_list_form_v3',
            'anios_list_form_v3',
        ] as $key) {
            \Illuminate\Support\Facades\Cache::forget($key);
        }
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

        // tipo_equipos no distingue Liviana/Pesada (catálogo global, ver migración de
        // creación): se infiere de qué CATEGORIA_FLOTA se usó realmente con cada tipo en
        // equipos ya registrados, para recomendar/avisar en el selector de "Tipo" del
        // formulario (JS: __tipoCategoriaMap). Un tipo sin historial no aparece en el mapa
        // (aún no está "casado" con ninguna categoría) y no dispara aviso.
        $tipoCategoriaMap = \Illuminate\Support\Facades\Cache::remember('tipo_categoria_map_form', 3600, function () {
            return Equipo::query()
                ->join('tipo_equipos', 'tipo_equipos.id', '=', 'equipos.id_tipo_equipo')
                ->whereNotNull('equipos.CATEGORIA_FLOTA')
                ->select('tipo_equipos.nombre', 'equipos.CATEGORIA_FLOTA')
                ->distinct()
                ->get()
                ->groupBy('nombre')
                ->map(function ($rows) { return $rows->pluck('CATEGORIA_FLOTA')->unique()->values(); });
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

        // Marcas y modelos de los AUXILIARES. El formulario unificado sugiere Tipo desde
        // `equipos_auxiliares` ($tiposAux) pero Marca y Modelo salían solo de `equipos`, así
        // que en modo auxiliar el desplegable ofrecía marcas de camiones. Se cargan aparte y
        // la vista muestra el juego que corresponde al modo. TTL 60s como el resto de listas
        // del formulario: un auxiliar nuevo se guarda por EquipoAuxiliarController (otro
        // controlador), así que no pasa por olvidarCachesListasEquipos() y se refresca solo.
        $marcasAux = \Illuminate\Support\Facades\Cache::remember('marcas_aux_list_form_v3', 60, function () {
            return \App\Models\EquipoAuxiliar::distinct()->whereNotNull('MARCA')->where('MARCA', '!=', '')
                ->orderBy('MARCA', 'asc')->limit(1000)->pluck('MARCA');
        });

        $modelosAux = \Illuminate\Support\Facades\Cache::remember('modelos_aux_list_form_v3', 60, function () {
            return \App\Models\EquipoAuxiliar::distinct()->whereNotNull('MODELO')->where('MODELO', '!=', '')
                ->orderBy('MODELO', 'asc')->limit(1000)->pluck('MODELO');
        });

        // Un solo listado por campo: [['v' => valor, 's' => ámbito]] con ámbito 'equipo',
        // 'aux' o 'both'. La vista pinta cada opción con su data-scope y el CSS oculta las
        // que no aplican al modo activo — así un valor común (CATERPILLAR) no sale duplicado.
        //
        // El valor NO se usa como clave del arreglo de salida a propósito: hay modelos que
        // son solo dígitos (333, 631, 740) y PHP convertiría esas claves a entero, de modo
        // que un futuro "007" se mostraría como "7". El mapa $vistos sí indexa por valor,
        // pero solo para deduplicar; lo que se pinta sale siempre de 'v', que es string.
        $ambito = function ($deEquipo, $deAux) {
            $lista = [];
            $vistos = [];
            $agregar = function ($v, $scope) use (&$lista, &$vistos) {
                $v = trim((string) $v);
                if ($v === '') return;
                if (isset($vistos[$v])) {
                    if ($lista[$vistos[$v]]['s'] !== $scope) $lista[$vistos[$v]]['s'] = 'both';
                    return;
                }
                $lista[] = ['v' => $v, 's' => $scope];
                $vistos[$v] = array_key_last($lista);
            };
            foreach ($deEquipo as $v) { $agregar($v, 'equipo'); }
            foreach ($deAux as $v)    { $agregar($v, 'aux'); }
            usort($lista, fn ($a, $b) => strnatcasecmp($a['v'], $b['v']));
            return $lista;
        };
        $marcasScope  = $ambito($marcas, $marcasAux);
        $modelosScope = $ambito($modelos, $modelosAux);

        $categorias = ['FLOTA LIVIANA', 'FLOTA PESADA'];

        $equipo = new Equipo(); // Empty instance for form partial

        // buildTiposMap() (NO tiposLabel()): misma fuente que el indice y los filtros de
        // auxiliares — devuelve los tipos REALMENTE usados, en MAYUSCULAS. Con tiposLabel()
        // el desplegable salia en minusculas ("Máquina de Soldar"), ofrecia 2 tipos sin
        // registros y ocultaba los 6 que si existen (MONTACARGA, MANLIFT, HIDROJET...).
        // El input sigue siendo texto libre: un tipo nuevo se escribe y validateData() lo
        // normaliza a MAYUSCULAS_CON_GUION_BAJO.
        $tiposAux = app(\App\Http\Controllers\EquipoAuxiliarController::class)->buildTiposMap();
        $estadosAux = \App\Models\EquipoAuxiliar::estadosLabel();
        $auxiliar = new \App\Models\EquipoAuxiliar();
        $frentesAux = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE', 'asc')->get();

        // 'marcas' y 'modelos' ya no viajan a la vista: se consumen aquí para armar
        // marcasScope / modelosScope, que es lo único que pinta el formulario.
        return view('admin.equipos.create', compact(
            'frentes', 'seguros', 'tipos_equipo', 'categorias', 'equipo', 'modelosList', 'aniosList',
            'tiposAux', 'estadosAux', 'auxiliar', 'frentesAux', 'tipoCategoriaMap',
            'marcasScope', 'modelosScope'
        ));
    }

    public function storeUnified(Request $request)
    {
        $modo = $request->input('__modo', 'equipo');

        if ($modo === 'auxiliar') {
            $request->merge(['__unified_redirect' => route('equipos.index')]);
            return app(EquipoAuxiliarController::class)->store($request);
        }

        return $this->store($request);
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
            'COMBUSTIBLE' => (trim($request->COMBUSTIBLE ?? '') === '') ? null : mb_strtoupper(trim($request->COMBUSTIBLE)),
        ]);

        if ($request->has('documentacion.PLACA')) {
            $doc = $request->documentacion;
            $placa = trim($doc['PLACA'] ?? '');
            $doc['PLACA'] = ($placa === '') ? null : strtoupper($placa);
            $request->merge(['documentacion' => $doc]);
        }




        try {
            $request->validate([
                'CODIGO_PATIO' => 'nullable|unique:equipos,CODIGO_PATIO',
                'TIPO_EQUIPO' => 'required|max:35',
                'CATEGORIA_FLOTA' => 'required|in:FLOTA LIVIANA,FLOTA PESADA',
                'MARCA' => 'required',
                'MODELO' => 'required',
                'ANIO' => 'required|integer',
                'COLOR' => 'nullable|string|max:50',
                'CAPACIDAD' => 'nullable|string|max:80',
                'COMBUSTIBLE' => 'nullable|in:' . implode(',', \App\Models\Equipo::COMBUSTIBLES),
                'CONSUMO_PROMEDIO' => 'nullable|numeric|min:0|max:99999',
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
        $registrar = function () use ($request, $filesToProcess) {
            $tipoName = strtoupper($request->input('TIPO_EQUIPO'));
            $tipo = TipoEquipo::firstOrCreate(['nombre' => $tipoName]);
            $data = $request->except(['specs', 'responsable', 'documentacion', 'TIPO_EQUIPO', 'doc_propiedad', 'poliza_seguro', 'doc_rotc', 'doc_racda', 'foto_equipo', 'foto_referencial']);
            $data['id_tipo_equipo'] = $tipo->id;
            // NO se asigna $data['TIPO_EQUIPO']: esa columna se eliminó de `equipos` (la
            // reemplazó id_tipo_equipo, migración 2026_01_12_044330) y no está en $fillable,
            // así que Eloquent la descartaba en silencio. El tipo vive en la relación tipo().
            $data['CODIGO_PATIO'] = (trim($data['CODIGO_PATIO'] ?? '') === '') ? null : strtoupper($data['CODIGO_PATIO']);
            $data['MARCA'] = strtoupper($data['MARCA'] ?? '');
            $data['MODELO'] = strtoupper($data['MODELO'] ?? '');
            $data['CAPACIDAD'] = (trim($data['CAPACIDAD'] ?? '') === '') ? null : strtoupper(trim($data['CAPACIDAD']));
            $data['SERIAL_CHASIS'] = strtoupper($data['SERIAL_CHASIS'] ?? '');
            $data['SERIAL_DE_MOTOR'] = (trim($data['SERIAL_DE_MOTOR'] ?? '') === '') ? null : strtoupper(trim($data['SERIAL_DE_MOTOR']));
            
            $data['CREADO_POR'] = auth()->id();

            $equipo = Equipo::create($data);

            // Auditoría de REGISTRO: deja rastro de la creación manual (quién y qué) en
            // el mismo audit log que ediciones/borrados, para que aparezca en el módulo
            // de historial. Se hace en store() (no en el observer 'created') para auditar
            // solo el alta manual y no inundar el log si hubiera importación masiva.
            \App\Models\EquipoAuditLog::registrar($equipo->ID_EQUIPO, 'create', [
                'TIPO'   => $tipoName, // NO $equipo->TIPO_EQUIPO: no es fillable → siempre null
                'MARCA'  => $equipo->MARCA,
                'MODELO' => $equipo->MODELO,
                'SERIAL_CHASIS' => $equipo->SERIAL_CHASIS,
            ]);

            // Frente al CREAR: ID_FRENTE_ACTUAL no es fillable (se controla aparte para
            // que la edición no lo toque y solo Movilización lo mueva). Pero al REGISTRAR
            // un equipo nuevo sí permitimos asignar el frente elegido en el form, vía
            // property+save y con las mismas guardas de scope/bloqueo. El equipo nace
            // PENDIENTE (CONFIRMADO_EN_SITIO=0 por default) hasta confirmarse en sitio.
            $frenteNuevo = trim((string) $request->input('ID_FRENTE_ACTUAL', ''));
            if ($frenteNuevo !== '' && \App\Models\FrenteTrabajo::where('ID_FRENTE', $frenteNuevo)->exists()) {
                $u = auth()->user();
                $esLocal    = $u ? !$u->veTodosLosFrentesEquipos() : false;
                $permitidos = $u ? array_map('strval', $u->getFrentesIds()) : [];
                $bloqueados = $u ? array_map('strval', $u->getFrentesBloqueadosIds()) : [];
                if (in_array($frenteNuevo, $bloqueados, true) || ($esLocal && !in_array($frenteNuevo, $permitidos, true))) {
                    abort(403, 'No tiene permisos para registrar equipos en este frente.');
                }
                $equipo->ID_FRENTE_ACTUAL = $frenteNuevo;
                $equipo->save();
            }

            // Link to catalog if specified (validation already done)
            if ($request->filled('ID_ESPEC')) {
                $equipo->ID_ESPEC = $request->input('ID_ESPEC');
                $equipo->save();
            }

            // Un equipo recién creado NO debe tener documentación. Si ya existe una fila con
            // este ID_EQUIPO (PK de `documentacion`), es HUÉRFANA: de un equipo borrado cuyo ID
            // se reutilizó (el AUTO_INCREMENT de `equipos` quedó por debajo del máximo de
            // `documentacion`, p.ej. tras un restore de BD). La eliminamos SIEMPRE aquí —pase o
            // no documentación en el request— para no chocar con la PK al crearla ni heredar
            // datos viejos. `documentacion` no usa SoftDeletes y nada depende de ella → seguro.
            Documentacion::where('ID_EQUIPO', $equipo->ID_EQUIPO)->delete();

            // --- DOCUMENTATION & PHOTOS UPLOAD (SYNCHRONOUS DIRECT TO DRIVE) ---
            $docDataUpdates = []; // FIX: Initialize variable to avoid 500 Error if no files are uploaded

            if (count($filesToProcess) > 0) {
                // Drive SOLO si hay algo que subir. getInstance() abre conexion a Google para
                // refrescar el token: si la red esta lenta lanza (cURL 28: SSL timeout) y tumba
                // la transaccion entera. Estaba FUERA de este if, asi que un equipo sin fotos ni
                // documentos tambien fallaba por una caida de internet, sin necesitar Drive.
                try {
                    $driveService = \App\Services\GoogleDriveService::getInstance();
                } catch (\Throwable $e) {
                    Log::error('Registro de equipo: sin conexion con Google Drive: ' . $e->getMessage());
                    // 503 con mensaje: los HttpException SI exponen su texto al cliente aunque
                    // APP_DEBUG este en false (un Exception normal sale como "Server Error").
                    abort(503, 'No hay conexión con Google Drive: los archivos no se pudieron subir y el equipo NO se registró. Reintente en un momento, o regístrelo sin archivos y súbalos luego desde Editar.');
                }
                $folderId = $driveService->getRootFolderId();

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
                        abort(503, "No se pudo subir «{$type}» a Google Drive, así que el equipo NO se registró. Reintente en un momento.");
                    }
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
        };

        try {
            DB::transaction($registrar);
        } catch (\Throwable $e) {
            // El rollback deshace el equipo, pero NO los archivos que se dejaron en
            // temp_staging antes de abrir la transaccion (solo se borran uno a uno tras
            // subirlos con exito). Sin esto, cada intento fallido dejaba basura en storage.
            foreach ($filesToProcess as $fileData) {
                Storage::disk('local')->delete($fileData['path']);
            }
            throw $e;
        }

        $this->olvidarCachesListasEquipos();

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

    /**
     * URL segura para volver al listado de equipos tras Cancelar/Guardar en el editor.
     *
     * El editor se abre desde el modal de detalles (editEquipoFromDetails, en el partial
     * equipment_details_modal.blade.php), que pasa `?return=<url del listado con sus filtros>`
     * para no perder el frente/búsqueda
     * activos — sin esto, Cancelar/Guardar caían a `/admin/equipos` pelado y la tabla salía
     * vacía (el módulo se ve filtrado por frente).
     *
     * Solo se acepta si es una ruta RELATIVA (sin host) que apunta EXACTAMENTE al índice de
     * equipos — así se evita un open-redirect. Cualquier otra cosa cae al index sin filtros.
     */
    private function equiposReturnUrl(?string $candidate): string
    {
        $fallback = route('equipos.index');
        if (! $candidate) {
            return $fallback;
        }
        // Rechazar URLs absolutas (con host): solo permitimos rutas internas.
        if (parse_url($candidate, PHP_URL_HOST) !== null) {
            return $fallback;
        }
        $path = parse_url($candidate, PHP_URL_PATH) ?: '';
        if ($path !== '/admin/equipos') {
            return $fallback;
        }
        $query = parse_url($candidate, PHP_URL_QUERY);
        return $query ? $path . '?' . $query : $path;
    }

    public function edit(Request $request, $id)
    {
        $equipo = $this->findAndAuthorizeEquipo($id, ['frenteActual', 'especificaciones', 'documentacion', 'responsables', 'tipo']);

        // Listado al que volver (con sus filtros) al Cancelar/Guardar. Lo manda el lápiz
        // del modal de detalles como ?return=…; se sanea para evitar open-redirect.
        $returnUrl = $this->equiposReturnUrl($request->query('return'));

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
        return view('admin.equipos.edit', compact('equipo', 'frentes', 'seguros', 'categorias', 'tipos_equipo', 'marcas', 'modelos', 'aniosList', 'modelosList', 'returnUrl'));
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
            'COMBUSTIBLE' => (trim($request->COMBUSTIBLE ?? '') === '') ? null : mb_strtoupper(trim($request->COMBUSTIBLE)),
        ]);

        if ($request->has('documentacion.PLACA')) {
            $doc = $request->documentacion;
            $placa = trim($doc['PLACA'] ?? '');
            $doc['PLACA'] = ($placa === '') ? null : strtoupper($placa);
            $request->merge(['documentacion' => $doc]);
        }

        $request->validate([
            'CODIGO_PATIO' => 'nullable|unique:equipos,CODIGO_PATIO,' . $id . ',ID_EQUIPO',
            'TIPO_EQUIPO' => 'required|max:35',
            'CATEGORIA_FLOTA' => 'required|in:FLOTA LIVIANA,FLOTA PESADA',
            'MARCA' => 'required',
            'MODELO' => 'required',
            'ANIO' => 'required|integer',
            'COLOR' => 'nullable|string|max:50',
            'CAPACIDAD' => 'nullable|string|max:80',
            'COMBUSTIBLE' => 'nullable|in:' . implode(',', \App\Models\Equipo::COMBUSTIBLES),
            'CONSUMO_PROMEDIO' => 'nullable|numeric|min:0|max:99999',
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
            // Un dato de documentacion (nro/fecha) exige su archivo solo cuando el usuario lo
            // ESCRIBE O LO CAMBIA en esta edicion. Muchas fichas vienen de la carga inicial con
            // el dato pero sin PDF: exigirlo siempre dejaba esos equipos imposibles de editar
            // —ni el color se podia guardar— pidiendo un archivo que nadie tiene. El dato ya
            // guardado se respeta tal cual; en cuanto se toca, vuelve a pedir su archivo.
            // Se compara en MAYUSCULAS porque asi normaliza el propio update() al guardar
            // (NRO_DE_DOCUMENTO): reescribir el mismo texto en minusculas no es un cambio.
            $sinCambios = function (string $campo) use ($request, $equipo) {
                $enviado = mb_strtoupper(trim((string) $request->input('documentacion.' . $campo, '')));
                $actual  = mb_strtoupper(trim((string) ($equipo->documentacion->$campo ?? '')));
                return $enviado !== '' && $enviado === $actual;
            };

            // Propiedad
            if ($request->filled('documentacion.NRO_DE_DOCUMENTO') && !$sinCambios('NRO_DE_DOCUMENTO')) {
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
            if ($request->filled('documentacion.FECHA_VENC_POLIZA') && !$sinCambios('FECHA_VENC_POLIZA')) {
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
            if ($request->filled('documentacion.FECHA_ROTC') && !$sinCambios('FECHA_ROTC')) {
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
            if ($request->filled('documentacion.FECHA_RACDA') && !$sinCambios('FECHA_RACDA')) {
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
            // NO se asigna $data['TIPO_EQUIPO']: esa columna se eliminó de `equipos` (la
            // reemplazó id_tipo_equipo, migración 2026_01_12_044330) y no está en $fillable,
            // así que Eloquent la descartaba en silencio. El tipo vive en la relación tipo().
            $data['CODIGO_PATIO'] = (trim($data['CODIGO_PATIO'] ?? '') === '') ? null : strtoupper($data['CODIGO_PATIO']);
            $data['MARCA'] = strtoupper(trim($data['MARCA'] ?? ''));
            $data['MODELO'] = strtoupper(trim($data['MODELO'] ?? ''));
            $data['CAPACIDAD'] = (trim($data['CAPACIDAD'] ?? '') === '') ? null : strtoupper(trim($data['CAPACIDAD']));
            $data['SERIAL_CHASIS'] = strtoupper(trim($data['SERIAL_CHASIS'] ?? ''));
            $data['SERIAL_DE_MOTOR'] = (trim($data['SERIAL_DE_MOTOR'] ?? '') === '') ? null : strtoupper(trim($data['SERIAL_DE_MOTOR']));
            // El frente NO se cambia por edición de datos: reasignar es trabajo de
            // MOVILIZACIÓN (que deja CONFIRMADO_EN_SITIO=0). El selector va bloqueado en
            // edición; lo descartamos aquí para conservar SIEMPRE el frente y su confirmación.
            unset($data['ID_FRENTE_ACTUAL']);
            $equipo->update($data);

            // Drive PEREZOSO: getInstance() conecta con Google para refrescar el token y, si la
            // red esta lenta, lanza (cURL 28: SSL timeout) y hace rollback de TODA la edicion.
            // Al instanciarlo aqui de entrada, guardar cambios de texto —sin tocar una sola foto
            // ni PDF— tambien fallaba por internet. Ahora solo conecta cuando hay archivo.
            $driveService = null;
            $drive = function () use (&$driveService) {
                if ($driveService === null) {
                    try {
                        $driveService = \App\Services\GoogleDriveService::getInstance();
                    } catch (\Throwable $e) {
                        Log::error('Edicion de equipo: sin conexion con Google Drive: ' . $e->getMessage());
                        abort(503, 'No hay conexión con Google Drive: los archivos no se pudieron subir y los cambios NO se guardaron. Reintente en un momento.');
                    }
                }
                return $driveService;
            };

            if ($request->filled('ID_ESPEC')) {
                $equipo->ID_ESPEC = $request->input('ID_ESPEC');
                $equipo->save();
                if ($request->hasFile('foto_referencial')) {
                    $espec = CaracteristicaModelo::find($equipo->ID_ESPEC);
                    if ($espec) {
                        $catalogFolderId = config('filesystems.disks.google.catalog_folder'); // Specific folder for model photos
                        $file = $request->file('foto_referencial');
                        $filename = 'catalog_ref_' . time() . '.' . $file->getClientOriginalExtension();
                        $driveFile = $drive()->uploadFile($catalogFolderId, $file, $filename, $file->getMimeType());
                        if ($driveFile && isset($driveFile->id)) {
                            $espec->update(['FOTO_REFERENCIAL' => '/storage/google/' . $driveFile->id]);
                        }
                    }
                }
            }

            if ($request->hasFile('foto_equipo')) {
                $file = $request->file('foto_equipo');
                $photoFolderId = config('filesystems.disks.google.equipment_folder'); // Specific folder for equipment photos
                $driveFile = $drive()->uploadFile($photoFolderId, $file, 'foto_unidad_' . time() . '.' . $file->getClientOriginalExtension(), $file->getMimeType());
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
                        // Se resuelve UNA vez aqui: si se pidiera dentro del try de abajo, el
                        // catch se tragaria el abort(503) de "sin conexion" y lo registraria
                        // como un fallo de borrado, ademas de reintentar la conexion despues.
                        $svc = $drive();

                        // Check for old file and delete it (Correctly using DB relation)
                        if ($equipo->documentacion && $equipo->documentacion->$dbCol && str_starts_with($equipo->documentacion->$dbCol, '/storage/google/')) {
                            // Extract file ID (remove query params for cache busting)
                            $oldUrl = $equipo->documentacion->$dbCol;
                            $oldFileId = str_replace('/storage/google/', '', parse_url($oldUrl, PHP_URL_PATH));
                            try {
                                $svc->deleteFile($oldFileId);
                                // Invalidate local cache
                                \Illuminate\Support\Facades\Storage::disk('local')->delete('google_cache/' . $oldFileId);
                                \Illuminate\Support\Facades\Cache::forget('gdrive_meta_' . $oldFileId);
                            } catch (\Exception $e) {
                                Log::error("Failed to delete old Drive file: $oldFileId");
                            }
                        }

                        $driveFile = $svc->uploadFile($svc->getRootFolderId(), $file, $fileKey . '_' . time() . '.pdf', 'application/pdf');
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

        // Invalidar caché SIEMPRE (antes del return, aplique a JSON o redirect).
        // El dashboard de /menu se invalida solo: los observers de Equipo y
        // Documentacion hacen bumpDataVersion() en cada escritura.
        $this->olvidarCachesListasEquipos();

        // NOTA: NO registramos audit 'edit' aqui. El EquipoObserver::updated
        // (AppServiceProvider::boot) ya lo hace automaticamente cuando el
        // modelo detecta cambios (getChanges), y ademas guarda el diff
        // antes/despues — mas rico que un registro plano. Agregar un
        // registrar() aqui generaba duplicados en equipo_audit_log.

        // Volver al listado con los filtros que tenía el usuario (frente/búsqueda). El
        // editor lleva el destino en un hidden `return_url` (saneado igual que en edit()).
        $backUrl = $this->equiposReturnUrl($request->input('return_url'));

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Equipo actualizado correctamente.', 'redirect' => $backUrl]);
        }

        return redirect()->to($backUrl)->with('success', 'Equipo actualizado.');
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
                'serial_motor'   => $e->SERIAL_DE_MOTOR, // para el buscador de la papelera
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

    /**
     * Borra PERMANENTEMENTE un equipo de la papelera (forceDelete) — irreversible.
     * Exclusivo super.admin. FK en cascada eliminan documentacion/responsable/despacho;
     * consumibles y auxiliares-host quedan con null. Se captura cualquier FK restante.
     */
    public function forceDeleteEquipo(int $id)
    {
        // Cargamos documentacion para poder borrar sus archivos de Drive ANTES del
        // forceDelete (la fila documentacion cae por FK CASCADE al borrar el equipo).
        $equipo = Equipo::onlyTrashed()->with('documentacion')->where('ID_EQUIPO', $id)->first();
        if (!$equipo) {
            return response()->json(['success' => false, 'message' => 'Equipo no encontrado en la papelera.'], 404);
        }

        // ── 1. Recolectar los file IDs de Google Drive ANTES de tocar la BD ──
        // Documentos del equipo (los 6 LINK_* de documentacion) + la foto PROPIA de
        // la unidad (FOTO_EQUIPO). NO se toca FOTO_REFERENCIAL: esa es la foto del
        // catalogo del modelo y la comparten otras unidades.
        $driveFileIds = [];
        if ($equipo->documentacion) {
            foreach (self::DOC_FILTER_COLS as $col) {
                $fid = $this->extraerDriveFileId($equipo->documentacion->{$col} ?? null);
                if ($fid) $driveFileIds[] = $fid;
            }
        }
        $fotoId = $this->extraerDriveFileId($equipo->FOTO_EQUIPO);
        if ($fotoId) $driveFileIds[] = $fotoId;

        // ── 2. Borrado en BD dentro de una transaccion ──
        // Limpiamos primero los registros que NO tienen FK hacia equipos (quedarian
        // huerfanos) y desanclamos los equipos que lo referencian. El resto cae solo:
        //   · documentacion / responsable / despacho_combustible → FK ON DELETE CASCADE
        //   · consumibles.ID_EQUIPO / equipos_auxiliares.ID_EQUIPO_HOST → FK SET NULL
        try {
            DB::transaction(function () use ($equipo, $id) {
                // Reportes de falla del equipo (withTrashed + forceDelete: la tabla usa
                // SoftDeletes, asi tambien se llevan los reportes ya archivados).
                \App\Models\Falla::withTrashed()->where('ACTIVO_TIPO', 'equipo')->where('ACTIVO_ID', $id)->forceDelete();
                // Bitacora de auditoria de ediciones (sin FK → borrado manual).
                \App\Models\EquipoAuditLog::where('ID_EQUIPO', $id)->delete();
                // Historial de movilizaciones de este equipo (sin FK → borrado manual).
                \App\Models\Movilizacion::where('ID_EQUIPO', $id)->delete();
                // Equipos anclados a este via ID_ANCLAJE: la columna no tiene FK, asi
                // que los desanclamos a mano (incluye los que esten en la papelera)
                // para no dejar referencias colgando.
                Equipo::withTrashed()->where('ID_ANCLAJE', $id)->update(['ID_ANCLAJE' => null]);

                $equipo->forceDelete();
            });

            // Borrado DURO: al desaparecer las filas, MAX(updated_at) puede quedar IGUAL
            // (o incluso bajar), así que la huella no distingue "se borró un equipo" de
            // "no pasó nada" y el cliente offline conservaría el equipo fantasma. Las
            // movilizaciones de arriba se borran por query builder, que ni siquiera emite
            // eventos de modelo. El reseteo fuerza la recarga completa del dominio.
            \App\Support\OfflineVersion::resetear('equipos');
        } catch (\Throwable $e) {
            Log::error("forceDeleteEquipo: fallo al borrar equipo {$id}: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'No se pudo eliminar permanentemente el equipo. Intenta de nuevo o avisa al administrador.',
            ], 422);
        }

        // ── 3. Borrar los archivos de Google Drive (fuera de la transaccion) ──
        // El equipo ya no existe en la BD: un fallo del Drive NO debe revertir eso,
        // solo se registra para revision. Mismo patron de limpieza de cache local +
        // metadata que deleteDoc().
        if ($driveFileIds) {
            try {
                $driveService = \App\Services\GoogleDriveService::getInstance();
                foreach ($driveFileIds as $fid) {
                    $driveService->deleteFile($fid);
                    Storage::disk('local')->delete('google_cache/' . $fid);
                    \Illuminate\Support\Facades\Cache::forget('gdrive_meta_' . $fid);
                }
            } catch (\Throwable $e) {
                Log::warning("forceDeleteEquipo: equipo {$id} borrado de la BD pero fallo limpiando Drive: " . $e->getMessage());
            }
        }

        return response()->json(['success' => true, 'message' => 'Equipo eliminado permanentemente.']);
    }

    /**
     * Devuelve el file ID de Google Drive de una URL guardada con el formato
     * `/storage/google/<id>?v=<ts>`, o null si la URL no apunta a Drive. Mismo
     * criterio que deleteDoc()/uploadDoc(): parse_url descarta el `?v=...`.
     */
    private function extraerDriveFileId(?string $url): ?string
    {
        if (!$url || !str_starts_with($url, '/storage/google/')) return null;
        $fid = str_replace('/storage/google/', '', parse_url($url, PHP_URL_PATH) ?: '');
        return $fid !== '' ? $fid : null;
    }

    public function changeStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:OPERATIVO,INOPERATIVO,EN MANTENIMIENTO,DESINCORPORADO',
        ]);
        $equipo = $this->findAndAuthorizeEquipo($id);

        // Si el equipo tiene un reporte de falla ABIERTO, su estado lo gobierna ese
        // reporte (quedó INOPERATIVO al crearlo): no se cambia a mano desde aquí. Para
        // cambiarlo hay que CERRAR el reporte (lo que lo devuelve a OPERATIVO).
        // Devolvemos los datos del reporte para que el front muestre el modal de cierre.
        // Fuente ÚNICA del "reporte abierto": la relación Equipo::fallaAbierta (misma que
        // eager-loadea el listado para abrir el modal al instante).
        $falla = $equipo->fallaAbierta;
        if ($falla) {
            $ident = optional($equipo->documentacion)->PLACA
                  ?: ($equipo->SERIAL_CHASIS ?: ($equipo->CODIGO_PATIO ?: trim(($equipo->MARCA ?? '') . ' ' . ($equipo->MODELO ?? ''))));
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

        $equipo->ESTADO_OPERATIVO = $request->input('status');
        $equipo->save();

        // NOTA: El EquipoObserver::updated registra automaticamente un audit
        // 'edit' con el diff de ESTADO_OPERATIVO (antes/despues). Agregar un
        // registrar() manual aqui generaba eventos duplicados en el historial.

        return response()->json(['success' => true, 'message' => 'Estatus actualizado.']);
    }

    /**
     * Confirmar / quitar la presencia FÍSICA del equipo en su frente (CONFIRMADO_EN_SITIO).
     * El usuario está en el frente y va tildando los que verificó "que está ahí". Lo usan
     * el chip de la celda del frente (lista) y el botón del modal de detalles. Mismo permiso
     * que changeStatus (equipos.edit, gateado en la ruta) y mismo scope vía findAndAuthorizeEquipo.
     */
    public function confirmarSitio(Request $request, $id)
    {
        $request->validate([
            'confirmado' => 'required|boolean',
        ]);
        $equipo = $this->findAndAuthorizeEquipo($id);
        $equipo->CONFIRMADO_EN_SITIO = $request->boolean('confirmado') ? 1 : 0;
        $equipo->save();

        return response()->json([
            'success'    => true,
            'confirmado' => (int) $equipo->CONFIRMADO_EN_SITIO,
        ]);
    }

    /**
     * Cambio de estado operativo desde la APK móvil (Sanctum).
     *
     * Mismo flujo que changeStatus pero accesible desde routes/api.php sin pasar
     * por el middleware 'auth' web del constructor (la APK autentica con token
     * Bearer Sanctum). El permiso 'equipos.edit' lo aplica la ruta misma.
     *
     * Disparado por el sincronizador del outbox SQLite cuando el usuario tocó
     * el chip de estado de un equipo en modo offline.
     */
    public function mobileChangeStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:OPERATIVO,INOPERATIVO,EN MANTENIMIENTO,DESINCORPORADO',
        ]);
        $equipo = \App\Models\Equipo::findOrFail($id);

        // MISMA regla que la web (changeStatus): si el equipo tiene un reporte de falla
        // ABIERTO, su estado lo gobierna ese reporte y NO se cambia a mano — hay que cerrar
        // el reporte primero. Se responde 409 con los datos del reporte para que la APK
        // muestre el aviso ("cierra el reporte primero") y NO reintente el outbox.
        // Fuente ÚNICA del "reporte abierto": la relación Equipo::fallaAbierta.
        $falla = $equipo->fallaAbierta;
        if ($falla) {
            return response()->json([
                'success'       => false,
                'message'       => 'Este equipo tiene un reporte de falla abierto. Para cambiar su estado debes cerrar el reporte.',
                'falla_abierta' => [
                    'id'     => $falla->ID_FALLA,
                    'codigo' => $falla->CODIGO_REPORTE,
                    'tipo'   => $falla->TIPO_REPORTE,
                ],
            ], 409);
        }

        $equipo->ESTADO_OPERATIVO = $request->input('status');
        $equipo->save();

        return response()->json(['success' => true, 'estado' => $equipo->ESTADO_OPERATIVO]);
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
        ], [
            'file.required' => 'Debe seleccionar un archivo.',
            'file.file'     => 'El documento no es válido.',
            'file.mimes'    => 'Solo se aceptan archivos en formato PDF.',
            'file.max'      => 'El archivo supera el tamaño máximo permitido (50 MB).',
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

            // El dashboard de /menu se invalida solo (bumpDataVersion en los
            // observers de Documentacion/Equipo al guardar).

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
            'COMBUSTIBLE' => 'Combustible',
            'CONSUMO_PROMEDIO' => 'Consumo (L/día)',
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
                    // FECHA_ADICIONAL está casteado a 'date' (Carbon), a diferencia de
                    // FECHA_VENC_POLIZA/ROTC/RACDA que son string crudo. Si se devuelve el
                    // Carbon tal cual, el JSON lo serializa con hora (ISO) y el <input type="date">
                    // del panel del preview no lo entiende → casilla vacía. Lo formateamos a
                    // 'Y-m-d' para que la fecha registrada se muestre, igual que en póliza.
                    $data = [
                        'fecha_vencimiento' => $doc->FECHA_ADICIONAL ? $doc->FECHA_ADICIONAL->format('Y-m-d') : '',
                    ];
                    break;

                case 'adicional_2':
                    // Compraventa: NO tiene fecha de vencimiento (panel sin campos editables).
                    $data = [];
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
        // Mismo patron que uploadDoc cuando reemplaza un archivo existente.
        // Errores del Drive NO bloquean el borrado en BD — los
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

        // ── 3. Audit log ───────────────────────────────────────────────────
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

        // Mismos 6 tipos que deleteDoc/uploadDoc. Sin este check, un doc_type
        // desconocido caía por el switch sin actualizar nada y respondía éxito.
        if (!in_array($type, ['propiedad', 'poliza', 'rotc', 'racda', 'adicional', 'adicional_2'], true)) {
            return response()->json(['success' => false, 'message' => 'Tipo de documento no válido.'], 400);
        }

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
                // Captura el diff del equipo ANTES de saveQuietly en formato {antes,despues}
                // (mismo esquema que EquipoObserver) para que el historial muestre ambos valores.
                // Variable separada de $updateData para no contaminar la update de Documentacion.
                $equipoDiff = [];
                foreach ($equipo->getDirty() as $_f => $_new) {
                    $_old = $equipo->getOriginal($_f);
                    if ((string) $_old !== (string) $_new) {
                        $equipoDiff[$_f] = ['antes' => $_old, 'despues' => $_new];
                    }
                }
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
                // Coherente con poliza/rotc/racda: si la nueva fecha es futura, la gestión
                // (frente que la tramita + fecha de gestión) ya no aplica → se limpia.
                if ($request->filled('fecha_vencimiento')) {
                    $newDate = \Carbon\Carbon::parse($request->input('fecha_vencimiento'));
                    if ($newDate->isFuture()) {
                        $updateData['adicional_gestion_frente_id'] = null;
                        $updateData['adicional_gestion_fecha'] = null;
                    }
                }
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

        // Diff {antes,despues} de la Documentacion ANTES de updateQuietly (mismo esquema
        // que EquipoObserver) para que el historial muestre valor viejo y nuevo. Los campos
        // cuyo valor no cambió se omiten del log (p.ej. gestion_* que ya estaban en null).
        $docDiff = [];
        $doc = $equipo->documentacion;
        foreach ($updateData as $field => $newValue) {
            $oldValue = $doc->getRawOriginal($field);
            // Los datetime salen de BD como "Y-m-d 00:00:00" pero el input llega "Y-m-d":
            // se normalizan para comparar y mostrar en el mismo formato.
            $oldCmp = is_string($oldValue) ? preg_replace('/ 00:00:00$/', '', $oldValue) : $oldValue;
            $newCmp = is_string($newValue) ? preg_replace('/ 00:00:00$/', '', $newValue) : $newValue;
            if ((string) $oldCmp === (string) $newCmp) continue;
            $docDiff[$field] = ['antes' => $oldCmp, 'despues' => $newCmp];
        }

        if (!empty($updateData)) {
            // updateQuietly: NO disparar DocumentacionObserver (que registraria un
            // 'edit' de PLACA/NRO/TITULAR). Esta edicion ya se audita explicitamente
            // abajo como 'metadata_<tipo>' con el diff completo; sin el quiet, una sola
            // edicion por el panel del visor generaria DOS eventos en el historial.
            $equipo->documentacion->updateQuietly($updateData);
        }

        // Auditoria: registra la edicion de metadata por tipo de documento.
        // Ambos diffs vienen en formato {antes,despues}. En el caso 'propiedad'
        // tambien se modifican campos del Equipo (MARCA, MODELO, SERIAL_*);
        // $equipoDiff fue capturado antes del saveQuietly(). Se mergean para que
        // el log refleje el cambio completo (Documentacion + Equipo).
        $logPayload = $docDiff;
        if (!empty($equipoDiff ?? [])) {
            $logPayload = array_merge($logPayload, $equipoDiff);
        }
        if (!empty($logPayload)) {
            \App\Models\EquipoAuditLog::registrar(
                $equipo->ID_EQUIPO,
                'metadata_' . $type,
                $logPayload
            );
        }

        // OJO: este camino escribe con saveQuietly()/updateQuietly() (para no
        // duplicar auditoría), así que los observers NO disparan — el bump del
        // dashboard debe ser explícito. Aquí se editan justamente las fechas de
        // vencimiento que alimentan las alertas de /menu.
        \App\Http\Controllers\DashboardController::bumpDataVersion();

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
        $tipo = strtoupper(trim($request->input('tipo', '')));

        if (!$model || !$year) {
            return response()->json(['found' => false]);
        }

        // Match base por MODELO + AÑO. Si llega el TIPO, coincidencia por TIPO + MODELO + AÑO:
        // se incluyen también los catálogos SIN TIPO (legacy, columna NULL) como respaldo para
        // no perder sugerencias válidas, pero los del TIPO exacto se muestran primero.
        // OPTIMIZED: Select only necessary columns (not SELECT *)
        $catalogQuery = CaracteristicaModelo::where('MODELO', $model)
            ->where('ANIO_ESPEC', $year);

        if ($tipo !== '') {
            $catalogQuery->where(function ($w) use ($tipo) {
                    $w->whereRaw('UPPER(TIPO) = ?', [$tipo])->orWhereNull('TIPO');
                })
                ->orderByRaw('CASE WHEN UPPER(TIPO) = ? THEN 0 ELSE 1 END', [$tipo]);
        }

        $catalogEntries = $catalogQuery
            ->select([
                'ID_ESPEC',
                'MODELO',
                'TIPO',
                'ANIO_ESPEC',
                'MOTOR',
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
                    'TIPO' => $entry->TIPO,
                    'ANIO_ESPEC' => $entry->ANIO_ESPEC,
                    'MOTOR' => $entry->MOTOR,
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
            $isLocal           = ($user ? !$user->veTodosLosFrentesEquipos() : false);
            $frentesPermitidos = $user ? $user->getFrentesIds() : [];
            $frentesBloqueados = $user ? $user->getFrentesBloqueadosIds() : [];
            $requestedFrenteId = $request->input('frente_id');

            // No excluir ESPECIAL si el usuario está filtrando explícitamente por uno (drill-down).
            $applyEspecialExclusion = !FrenteTrabajo::isEspecialId($requestedFrenteId);

            // Cache key — v10: el payload trae dos campos nuevos, equipos_gasoil y
            // equipos_gasolina, para las tarjetas de conteo por combustible.
            // v9: el consumo total descuenta los chutos que andan con lowboy
            // (ProyeccionCombustible), para dar el MISMO numero que el reporte Excel.
            // v8: el consumo total pasó a sumar equipos + auxiliares y solo
            // cuenta los de GASOIL (antes era un JOIN al catalogo que se comia a los
            // equipos sin ficha). Cambia el NUMERO, no la forma: igual hay que subir la
            // version o durante 2 minutos se sigue sirviendo el total viejo.
            // v7: cambió la estructura del payload (los auxiliares se agrupan
            // por EDAD nueva/antigua, no por operativo/no operativo, y traen sus totales).
            // v6 fue quitar los gráficos Estado Operativo e Inoperatividad y filtrar los
            // auxiliares por frente. Subir la versión es OBLIGATORIO al cambiar la forma del
            // payload: si no, durante 2 minutos el front recibe el JSON viejo y pinta series
            // que ya no existen.
            // OJO: el PK de Usuario es ID_USUARIO (no existe columna `id`), así que hay que
            // usar auth()->id()/ID_USUARIO — con $user->id todos caían en 'guest' y compartían
            // caché entre usuarios de distinto alcance (fuga de datos). Se hashea también el
            // scope (isLocal + frentes permitidos) por robustez si cambian los permisos.
            $cacheKey = 'fleet_stats_v10_u' . ($user?->ID_USUARIO ?? 'guest')
                      . '_f' . ($requestedFrenteId ?: 'all')
                      . '_s' . md5(($isLocal ? 'L' : 'G') . '|' . implode(',', $frentesPermitidos))
                      . '_b' . md5(implode(',', $frentesBloqueados));

            $payload = \Illuminate\Support\Facades\Cache::remember($cacheKey, 120, function () use (
                $isLocal, $frentesPermitidos, $frentesBloqueados, $requestedFrenteId, $applyEspecialExclusion
            ) {
                // ── Scope de frente reutilizable ──────────────────────────────────
                // Aplica el MISMO filtro (usuario local, frente solicitado en el
                // dashboard y lista negra) a cualquier query cuya columna de frente sea
                // ID_FRENTE_ACTUAL. Lo usan los equipos Y los auxiliares para que el
                // dashboard filtre AMBOS por el frente seleccionado, sin duplicar lógica.
                $scopeFrente = function ($q) use ($isLocal, $frentesPermitidos, $frentesBloqueados, $requestedFrenteId) {
                    if ($isLocal && count($frentesPermitidos) > 0) {
                        // NO aplicar dos where consecutivos — solo whereIn, o where con el frente solicitado.
                        if ($requestedFrenteId && $requestedFrenteId !== 'all'
                            && in_array($requestedFrenteId, $frentesPermitidos)
                        ) {
                            $q->where('ID_FRENTE_ACTUAL', $requestedFrenteId);
                        } else {
                            $q->whereIn('ID_FRENTE_ACTUAL', $frentesPermitidos);
                        }
                    } elseif ($isLocal) {
                        // Usuario local sin frentes permitidos: sin datos
                        $q->whereRaw('1 = 0');
                    } elseif ($requestedFrenteId && $requestedFrenteId !== 'all') {
                        $q->where('ID_FRENTE_ACTUAL', $requestedFrenteId);
                    }
                    // Lista negra: ocultar frentes bloqueados (aplica también a GLOBAL).
                    \App\Models\Usuario::aplicarBloqueoIds($q, $frentesBloqueados, 'ID_FRENTE_ACTUAL');
                };

                // ── Construir la query base de equipos ────────────────────────────
                $baseQuery = Equipo::query();
                $scopeFrente($baseQuery);

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

                // ── Query agrupada por tipo (flota nueva vs vieja) ──────────────────
                // El WHERE de DESINCORPORADO no es opcional: los totales de la cabecera
                // ($basicStats) SI los excluyen, asi que sin este filtro las barras sumaban
                // mas que sus propias claves — 1014 en las barras contra "584 + 421 = 1005"
                // escrito encima. Un equipo desincorporado ya no es flota.
                $byTypeRaw = (clone $baseQuery)
                    ->where('equipos.ESTADO_OPERATIVO', '!=', 'DESINCORPORADO')
                    ->select(
                        'tipo_equipos.nombre as tipo_nombre',
                        DB::raw('SUM(CASE WHEN equipos.ANIO >= 2025 THEN 1 ELSE 0 END) as new_count'),
                        DB::raw('SUM(CASE WHEN equipos.ANIO <  2025 THEN 1 ELSE 0 END) as old_count')
                    )
                    ->leftJoin('tipo_equipos', 'equipos.id_tipo_equipo', '=', 'tipo_equipos.id')
                    ->whereNotNull('equipos.id_tipo_equipo')
                    ->whereNotNull('tipo_equipos.nombre')
                    ->groupBy('tipo_equipos.nombre')
                    ->orderBy('tipo_equipos.nombre')
                    ->get();

                // ── 4. Equipos por Frente (siempre global, sin filtro, sin ESPECIAL) ──
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

                // ── Transformar byTypeRaw a la sección de edad (flota nueva vs vieja) ──
                $ageLabels    = $byTypeRaw->pluck('tipo_nombre')->toArray();
                $newFleetData = $byTypeRaw->pluck('new_count')->map(fn($v) => (int)$v)->toArray();
                $oldFleetData = $byTypeRaw->pluck('old_count')->map(fn($v) => (int)$v)->toArray();

                // ── Auxiliares por Tipo — MISMO filtro de frente que los equipos ─────
                // (antes era global; ahora respeta el frente seleccionado en el dashboard).
                $auxBase = EquipoAuxiliar::query();
                $scopeFrente($auxBase);
                if ($applyEspecialExclusion) {
                    $especialIds = FrenteTrabajo::especialIds();
                    if (!empty($especialIds)) {
                        $auxBase->where(function ($q) use ($especialIds) {
                            $q->whereNull('ID_FRENTE_ACTUAL')->orWhereNotIn('ID_FRENTE_ACTUAL', $especialIds);
                        });
                    }
                }
                // ── Consumo total de gasoil: LAS DOS TABLAS ──────────────────────
                // Un frente quema lo de sus equipos MAS lo de sus auxiliares: en la
                // proyeccion del frente TUBERIA 12'' las 20 maquinas de soldar y los 2
                // compresores son 1.000 L/dia, el 15% del total. Sumar solo `equipos`
                // dejaba fuera ese 15% sin avisar.
                //
                // Va aqui y no arriba porque necesita $auxBase ya construido y con el
                // MISMO scope de frente aplicado — si no, los auxiliares ignorarian el
                // frente seleccionado y el total saldria inflado.
                //
                // Se filtra por GASOIL a proposito: la proyeccion es de gasoil. Los
                // equipos a gasolina y los remolques ('NO APLICA') no suman litros aqui.
                $consumoEquipos = (clone $baseQuery)
                    ->where('equipos.COMBUSTIBLE', 'GASOIL')
                    ->sum('equipos.CONSUMO_PROMEDIO');
                $consumoAuxiliares = (clone $auxBase)
                    ->where('COMBUSTIBLE', 'GASOIL')
                    ->sum('CONSUMO_PROMEDIO');

                // Un chuto con lowboy no gasta lo mismo que uno con batea: trabaja por
                // tandas. La suma plana los cuenta a todos como si halaran batea, asi que
                // se descuenta la diferencia. La regla vive en UN solo sitio —
                // ProyeccionCombustible— y es la MISMA que usa el reporte Excel; si cada
                // uno la implementara aparte, los dos numeros no coincidirian.
                $frentesEnScope = (clone $baseQuery)->distinct()->pluck('ID_FRENTE_ACTUAL')->all();
                $descuentoLowboy = \App\Support\ProyeccionCombustible::descuentoLowboy($frentesEnScope);

                $totalConsumption = (float) $consumoEquipos + (float) $consumoAuxiliares - $descuentoLowboy;

                // Desglose de la flota por combustible, para las dos tarjetas de conteo.
                // Solo la tabla `equipos`: los auxiliares tienen su propia tarjeta, y meterlos
                // aqui haria que estas dos cifras no cuadraran nunca con el "Σ Equipos" de al
                // lado. Por eso NO comparten scope con $totalConsumption, que si suma las dos
                // tablas porque mide litros, no unidades.
                $equiposGasoil   = (clone $baseQuery)->where('equipos.COMBUSTIBLE', 'GASOIL')->count();
                $equiposGasolina = (clone $baseQuery)->where('equipos.COMBUSTIBLE', 'GASOLINA')->count();

                // Mismo corte de edad que los equipos (>= 2025 = nueva) para que los dos
                // gráficos del dashboard se lean igual. Antes este se agrupaba por
                // operativo/no operativo: dos gráficos con la misma pinta pero midiendo
                // cosas distintas.
                //
                // El tercer grupo NO es un capricho: hoy 30 de 160 auxiliares no tienen ANIO
                // cargado. Con solo dos series (>= 2025 y < 2025) esos 30 no caen en ninguna
                // y desaparecían del gráfico sin avisar — las barras no sumarían el total real
                // del tipo y nadie sabría por qué. ANIO = 0 cuenta como sin cargar.
                $auxByTypeRaw = $auxBase
                    ->select(
                        'TIPO',
                        DB::raw('COUNT(*) as total'),
                        DB::raw('SUM(CASE WHEN ANIO >= 2025 THEN 1 ELSE 0 END) as new_count'),
                        DB::raw('SUM(CASE WHEN ANIO > 0 AND ANIO < 2025 THEN 1 ELSE 0 END) as old_count'),
                        DB::raw('SUM(CASE WHEN ANIO IS NULL OR ANIO = 0 THEN 1 ELSE 0 END) as sin_anio_count')
                    )
                    ->groupBy('TIPO')
                    ->orderByDesc('total')
                    ->get();

                $auxLabels      = $auxByTypeRaw->map(fn($r) => ucwords(str_replace('_', ' ', strtolower($r->TIPO))))->toArray();
                $auxNewData     = $auxByTypeRaw->pluck('new_count')->map(fn($v) => (int)$v)->toArray();
                $auxOldData     = $auxByTypeRaw->pluck('old_count')->map(fn($v) => (int)$v)->toArray();
                $auxSinAnioData = $auxByTypeRaw->pluck('sin_anio_count')->map(fn($v) => (int)$v)->toArray();

                // La serie "Sin año" solo viaja si hay alguno: con el año cargado en todos,
                // el gráfico queda idéntico al de equipos (dos series y ya).
                $auxDatasets = [
                    ['label' => 'Nueva (≥2025)',   'data' => $auxNewData],
                    ['label' => 'Antigua (<2025)', 'data' => $auxOldData],
                ];
                if (array_sum($auxSinAnioData) > 0) {
                    $auxDatasets[] = ['label' => 'Sin año', 'data' => $auxSinAnioData];
                }

                return [
                    'success' => true,
                    'stats' => [
                        'total'             => $total,
                        'fleet_new'         => $fleetNew,
                        'fleet_old'         => $fleetOld,
                        'total_consumption' => number_format((float)$totalConsumption, 2),
                        'equipos_gasoil'    => $equiposGasoil,
                        'equipos_gasolina'  => $equiposGasolina,
                        // Totales de auxiliares: alimentan las claves de la cabecera de su
                        // panel, igual que fleet_new/fleet_old alimentan las del de equipos.
                        'aux_new'           => array_sum($auxNewData),
                        'aux_old'           => array_sum($auxOldData),
                        'aux_sin_anio'      => array_sum($auxSinAnioData),
                    ],
                    'ageByType' => [
                        'labels'   => $ageLabels,
                        'datasets' => [
                            ['label' => 'Flota Nueva (≥2025)', 'data' => $newFleetData],
                            ['label' => 'Flota Vieja (<2025)',  'data' => $oldFleetData]
                        ]
                    ],
                    'equiposPorFrente' => $eqByFrenteRaw->map(fn($r) => [
                        'frente' => $r->frente_nombre,
                        'total'  => (int) $r->total,
                    ])->values()->toArray(),
                    'auxByType' => [
                        'labels'   => $auxLabels,
                        'datasets' => $auxDatasets,
                    ],
                ];
            });

            return response()->json($payload);

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
            $isLocal = ($user ? !$user->veTodosLosFrentesEquipos() : false);
            $frentesPermitidos = $user ? $user->getFrentesIds() : [];
            $frentesBloqueados = $user ? $user->getFrentesBloqueadosIds() : [];
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

            // Lista negra: ocultar frentes bloqueados (aplica también a GLOBAL).
            \App\Models\Usuario::aplicarBloqueoIds($baseQuery, $frentesBloqueados, 'ID_FRENTE_ACTUAL');

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

            // Alturas ANTES del logo para centrado correcto
            $sheet->getRowDimension(1)->setRowHeight(35);
            $sheet->getRowDimension(2)->setRowHeight(35);
            $sheet->getRowDimension(3)->setRowHeight(35);

            // Logo centrado en A1:A3 (trait ExcelLogoCorporativo)
            $sheet->mergeCells('A1:A3');
            $sheet->getStyle('A1:A3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
            $this->insertarLogoCorporativo($sheet, ['A'], [1,2,3], 90);

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

            // --- TABLA 1: FLOTA NUEVA VS VIEJA ---
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
                // Los valores en 0 se dejan VACÍOS en el Excel (a pedido del cliente).
                $sheet->setCellValue("C{$currentRow}", $row->new_count ?: '');
                $sheet->setCellValue("D{$currentRow}", $row->old_count ?: '');
                $sheet->setCellValue("E{$currentRow}", $total ?: '');
                
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
                // Los valores en 0 se dejan VACÍOS en el Excel (a pedido del cliente).
                $sheet->setCellValue("B{$currentRow}", $row->pesada_count ?: '');
                $sheet->setCellValue("C{$currentRow}", $row->liviana_count ?: '');
                $sheet->setCellValue("D{$currentRow}", $row->sin_asignar_count ?: '');
                $sheet->setCellValue("E{$currentRow}", $total ?: '');
                
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
            // Columnas acotadas en cada relación: solo se usan FOTO_REFERENCIAL, PLACA,
            // nombre y NOMBRE_FRENTE. Cargar la fila completa de especificaciones/
            // documentacion (tablas anchas) hidrataba decenas de columnas inútiles por
            // equipo. Se incluyen las llaves (ID_ESPEC / ID_EQUIPO / id / ID_FRENTE) para
            // que Eloquent pueda emparejar cada relación.
            ->with([
                'especificaciones:ID_ESPEC,FOTO_REFERENCIAL',
                'documentacion:ID_EQUIPO,PLACA',
                'tipo:id,nombre',
                'frenteActual:ID_FRENTE,NOMBRE_FRENTE',
            ])
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
     * Bulk lookup masivo: recibe una lista de terminos y devuelve, por termino,
     * si fue encontrado y en que frente esta el equipo actualmente.
     * Busca en placa, serial chasis, serial motor, N° etiqueta y codigo patio.
     *
     * Parametro opcional `frente_id`: si esta presente, cada resultado incluye
     * un flag `in_selected_frente` para que el frontend resalte en amarillo
     * los equipos que existen pero estan en un frente DIFERENTE al seleccionado.
     */
    /**
     * Filas de EQUIPOS para la busqueda masiva. Entre la tanda exacta y la parcial lo
     * unico que cambia es la CONDICION, asi que la pone quien llama y aqui viven los
     * joins y las columnas que el resultado necesita.
     *
     * Estaban escritas dos veces. Hoy coinciden, pero solo por suerte: agregar una
     * columna a una sola habria dejado a la otra devolviendo equipos incompletos, y sin
     * sintoma hasta que a alguien le faltara el dato en pantalla.
     */
    private function bulkFilasEquipos(callable $condicion)
    {
        return DB::table('equipos as e')
            ->leftJoin('documentacion as d',   'd.ID_EQUIPO', '=', 'e.ID_EQUIPO')
            ->leftJoin('frentes_trabajo as f', 'f.ID_FRENTE', '=', 'e.ID_FRENTE_ACTUAL')
            ->leftJoin('tipo_equipos as t',    't.id',        '=', 'e.id_tipo_equipo')
            ->whereNull('e.deleted_at')
            ->where($condicion)
            ->select([
                'e.ID_EQUIPO',
                'e.CODIGO_PATIO',
                'e.NUMERO_ETIQUETA',
                'e.MARCA',
                'e.SERIAL_CHASIS',
                'e.SERIAL_DE_MOTOR',
                'e.ID_FRENTE_ACTUAL',
                'e.ID_ANCLAJE',
                'e.ESTADO_OPERATIVO',
                'd.PLACA',
                'f.NOMBRE_FRENTE',
                't.nombre as TIPO_NOMBRE',
                't.rol_anclaje as ROL_ANCLAJE',
            ])
            ->get();
    }

    /**
     * Igual que bulkFilasEquipos pero para AUXILIARES, que se buscan aparte: sus columnas
     * identificadoras son SERIAL y CODIGO_INTERNO, y no tienen placa ni seriales de
     * motor/chasis.
     */
    private function bulkFilasAuxiliares(callable $condicion)
    {
        return DB::table('equipos_auxiliares as a')
            ->leftJoin('frentes_trabajo as f', 'f.ID_FRENTE', '=', 'a.ID_FRENTE_ACTUAL')
            ->whereNull('a.deleted_at')
            ->where($condicion)
            ->select([
                'a.ID_AUXILIAR', 'a.TIPO', 'a.MARCA', 'a.MODELO', 'a.SERIAL',
                'a.CODIGO_INTERNO', 'a.ID_FRENTE_ACTUAL', 'a.ESTADO_OPERATIVO', 'f.NOMBRE_FRENTE',
            ])
            ->get();
    }
    /**
     * Minimo de caracteres para intentar la coincidencia PARCIAL en la busqueda masiva.
     * Por debajo de 4, un fragmento casa con demasiadas filas y devolveria cualquier cosa
     * con aire de acierto — peor que decir "no encontrado".
     */
    private const BULK_PARCIAL_MIN = 4;

    /**
     * Cuantos terminos como maximo se rescatan por coincidencia parcial en una tanda.
     * El endpoint acepta hasta 2000: cruzarlos todos contra las ~1350 filas de la flota
     * son millones de comparaciones y una consulta con 10.000 clausulas OR — tumbaria la
     * peticion entera por un rescate que es, por definicion, para lo que se tecleo a mano.
     * Quien pega 2000 lineas desde una hoja de calculo trae los valores completos.
     * Lo que pase de aqui se informa en la respuesta ('parcial_omitidos'), no se calla.
     */
    private const BULK_PARCIAL_MAX = 100;

    public function bulkLookup(Request $request)
    {
        $request->validate([
            'terms'     => 'required|array|min:1|max:2000',
            'terms.*'   => 'nullable|string|max:100',
            'frente_id' => 'nullable|integer|exists:frentes_trabajo,ID_FRENTE',
        ]);

        $frenteIdFiltro = $request->input('frente_id'); // null = sin filtro

        // Trim + uppercase, descartar vacios, eliminar duplicados conservando orden.
        $terms = collect($request->input('terms', []))
            ->map(fn($t) => mb_strtoupper(trim((string) $t)))
            ->filter(fn($t) => $t !== '')
            ->unique()
            ->values();

        if ($terms->isEmpty()) {
            // Mismas claves que la respuesta normal de mas abajo: si aqui faltan, el modal
            // lee undefined en las que no estan y cada consumidor tiene que adivinar el
            // default por su cuenta.
            return response()->json([
                'total'            => 0,
                'found'            => 0,
                'missing'          => 0,
                'in_other_frente'  => 0,
                'confirmed'        => 0,
                'confirm_denied'   => false,
                'parcial_omitidos' => 0,
                'results'          => [],
            ]);
        }

        // Tolerancia a la confusión CERO ↔ letra O al transcribir placas y seriales: es el
        // error de tipeo más común de este dato. Se compara por el valor NORMALIZADO (la O
        // pasa a 0) en los dos lados —término y columna—, así "ABCO12" encuentra "ABC012" y
        // al revés. Es un ENSANCHE, no un reemplazo: la coincidencia exacta se sigue
        // resolviendo primero en PHP (ver $indexByField vs $indexNorm más abajo).
        // Medido sobre los datos reales: 99 de 692 placas llevan letra O y NINGÚN par de
        // valores distintos colisiona al normalizar, así que no se inventan coincidencias.
        $normPhp = fn ($v) => str_replace('O', '0', mb_strtoupper((string) $v));
        $normSql = fn (string $col) => DB::raw("REPLACE(UPPER({$col}), 'O', '0')");
        $termsNorm = $terms->map($normPhp)->unique()->values()->all();

        $rows = $this->bulkFilasEquipos(function ($q) use ($termsNorm, $normSql) {
            $q->whereIn($normSql('e.SERIAL_CHASIS'), $termsNorm)
              ->orWhereIn($normSql('e.SERIAL_DE_MOTOR'), $termsNorm)
              ->orWhereIn($normSql('e.NUMERO_ETIQUETA'), $termsNorm)
              ->orWhereIn($normSql('e.CODIGO_PATIO'), $termsNorm)
              ->orWhereIn($normSql('d.PLACA'), $termsNorm);
        });

        // Los AUXILIARES se buscan también: antes solo se miraba `equipos` y un serial de
        // auxiliar salía como "no encontrado". (Qué columnas los identifican: ver
        // bulkFilasAuxiliares.)
        $auxRows = $this->bulkFilasAuxiliares(function ($q) use ($termsNorm, $normSql) {
            $q->whereIn($normSql('a.SERIAL'), $termsNorm)
              ->orWhereIn($normSql('a.CODIGO_INTERNO'), $termsNorm);
        });

        // Indice por columna (clave en mayusculas) para resolver cada termino en O(1).
        $indexByField = [
            'chasis'     => [],
            'motor'      => [],
            'etiqueta'   => [],
            'patio'      => [],
            'placa'      => [],
            'aux_serial' => [],
            'aux_codigo' => [],
        ];
        // $indexNorm: MISMA estructura pero con la clave normalizada (O→0). Solo se consulta
        // si la búsqueda exacta no encontró nada, así una coincidencia literal nunca la pisa
        // una tolerante. Se llena junto al índice exacto para no recorrer las filas dos veces.
        $indexNorm = $indexByField;

        foreach ($auxRows as $a) {
            if ($a->SERIAL)         { $indexByField['aux_serial'][mb_strtoupper($a->SERIAL)]         = $a;
                                      $indexNorm['aux_serial'][$normPhp($a->SERIAL)]                 = $a; }
            if ($a->CODIGO_INTERNO) { $indexByField['aux_codigo'][mb_strtoupper($a->CODIGO_INTERNO)] = $a;
                                      $indexNorm['aux_codigo'][$normPhp($a->CODIGO_INTERNO)]         = $a; }
        }
        foreach ($rows as $r) {
            if ($r->SERIAL_CHASIS)    { $indexByField['chasis'][mb_strtoupper($r->SERIAL_CHASIS)]    = $r;
                                        $indexNorm['chasis'][$normPhp($r->SERIAL_CHASIS)]            = $r; }
            if ($r->SERIAL_DE_MOTOR)  { $indexByField['motor'][mb_strtoupper($r->SERIAL_DE_MOTOR)]   = $r;
                                        $indexNorm['motor'][$normPhp($r->SERIAL_DE_MOTOR)]           = $r; }
            if ($r->NUMERO_ETIQUETA)  { $indexByField['etiqueta'][mb_strtoupper($r->NUMERO_ETIQUETA)] = $r;
                                        $indexNorm['etiqueta'][$normPhp($r->NUMERO_ETIQUETA)]         = $r; }
            if ($r->CODIGO_PATIO)     { $indexByField['patio'][mb_strtoupper($r->CODIGO_PATIO)]      = $r;
                                        $indexNorm['patio'][$normPhp($r->CODIGO_PATIO)]              = $r; }
            if ($r->PLACA)            { $indexByField['placa'][mb_strtoupper($r->PLACA)]             = $r;
                                        $indexNorm['placa'][$normPhp($r->PLACA)]                     = $r; }
        }

        // Los campos de AUXILIAR van al final: si un mismo valor existe como serial de equipo
        // y como serial de auxiliar, gana el equipo (es el catálogo principal).
        $priority    = ['placa', 'chasis', 'motor', 'etiqueta', 'patio', 'aux_serial', 'aux_codigo'];
        $camposAux   = ['aux_serial', 'aux_codigo'];

        // ── Coincidencia PARCIAL: el tercer intento, solo para lo que no aparecio ─────
        // Un serial tecleado a medias no lo encontraba NADIE. La consulta de arriba es
        // exacta (whereIn), asi que la fila del equipo ni siquiera llegaba a traerse — y el
        // caso es de todos los dias: copiar los ultimos digitos de una placa, o leer un
        // serial borroso de una chapa.
        //
        // Se resuelve en dos consultas mas (equipos y auxiliares) y SOLO con los terminos
        // que quedaron sin dueno. Importa que sea asi: LIKE '%x%' no puede usar indice, y
        // la busqueda exacta —que acierta en casi todos los casos— no tiene por que pagarlo.
        //
        // Se acepta la parcial SOLO si un unico equipo la contiene. Con dos o mas no hay
        // forma de saber cual queria el usuario, y elegir uno seria peor que avisar: esos
        // quedan como no encontrados, pero contando CUANTOS son (ver $ambiguos), que es
        // la pista para que agregue caracteres.
        $sinDueno = $terms
            ->reject(function ($t) use ($indexByField, $indexNorm, $normPhp, $priority) {
                foreach ([[$indexByField, $t], [$indexNorm, $normPhp($t)]] as [$idx, $k]) {
                    foreach ($priority as $campo) {
                        if (isset($idx[$campo][$k])) return true;
                    }
                }
                return false;
            })
            ->filter(fn ($t) => mb_strlen($t) >= self::BULK_PARCIAL_MIN)
            ->values();

        $parcialOmitidos = max(0, $sinDueno->count() - self::BULK_PARCIAL_MAX);
        $sinDueno        = $sinDueno->take(self::BULK_PARCIAL_MAX);

        // Misma forma de dos niveles que los otros indices (campo => clave => fila), para
        // que la resolucion de mas abajo lo recorra con el MISMO codigo y no haya una
        // segunda copia armando el resultado.
        $indexParcial = array_fill_keys(array_keys($indexByField), []);
        // Fragmentos que si existen, pero en MAS de un equipo: termino => cuantos. Sin esto
        // saldrian como "no encontrado", que es mentira y deja al usuario probando otra vez
        // lo mismo. Sabiendo que son 9 entiende de una que le faltan digitos.
        $ambiguos = [];
        // Valor COMPLETO que caso con cada fragmento aceptado: termino => serial/placa real.
        // El usuario tiene que poder comprobar que el equipo que le devolvimos es el suyo;
        // un acierto parcial sin ensenar contra que caso es un acto de fe.
        $parcialValor = [];

        if ($sinDueno->isNotEmpty()) {
            $comoLike = fn ($t) => '%' . $normPhp($t) . '%';

            $eqParcial = $this->bulkFilasEquipos(function ($q) use ($sinDueno, $normSql, $comoLike) {
                foreach ($sinDueno as $t) {
                    $like = $comoLike($t);
                    $q->orWhere($normSql('e.SERIAL_CHASIS'), 'like', $like)
                      ->orWhere($normSql('e.SERIAL_DE_MOTOR'), 'like', $like)
                      ->orWhere($normSql('e.NUMERO_ETIQUETA'), 'like', $like)
                      ->orWhere($normSql('e.CODIGO_PATIO'), 'like', $like)
                      ->orWhere($normSql('d.PLACA'), 'like', $like);
                }
            });

            $auxParcial = $this->bulkFilasAuxiliares(function ($q) use ($sinDueno, $normSql, $comoLike) {
                foreach ($sinDueno as $t) {
                    $like = $comoLike($t);
                    $q->orWhere($normSql('a.SERIAL'), 'like', $like)
                      ->orWhere($normSql('a.CODIGO_INTERNO'), 'like', $like);
                }
            });

            // Que columna de cada tabla alimenta cada campo del indice.
            $columnas = [
                'placa' => 'PLACA', 'chasis' => 'SERIAL_CHASIS', 'motor' => 'SERIAL_DE_MOTOR',
                'etiqueta' => 'NUMERO_ETIQUETA', 'patio' => 'CODIGO_PATIO',
                'aux_serial' => 'SERIAL', 'aux_codigo' => 'CODIGO_INTERNO',
            ];

            // Se aplana a una sola lista [campo, clave-de-fila, fila, valor original, valor
            // normalizado] ANTES de mirar los terminos. Normalizar dentro del bucle de
            // terminos repetia el mismo mb_strtoupper sobre las mismas ~1350 filas una vez
            // por termino; asi se hace una sola vez y el bucle de abajo solo compara.
            // El orden lo marca $priority, y de ahi que mas abajo gane el PRIMER acierto.
            $candidatos = [];
            foreach ($priority as $campo) {
                $col    = $columnas[$campo];
                $esAux  = in_array($campo, $camposAux, true);
                foreach ($esAux ? $auxParcial : $eqParcial as $r) {
                    $valor = $r->$col ?? '';
                    if ($valor === '') continue;
                    // La clave evita contar dos veces el MISMO equipo por dos columnas.
                    $clave = $esAux ? 'a' . $r->ID_AUXILIAR : 'e' . $r->ID_EQUIPO;
                    $candidatos[] = [$campo, $clave, $r, $valor, $normPhp($valor)];
                }
            }

            foreach ($sinDueno as $t) {
                $frag = $normPhp($t);
                $aciertos = [];
                foreach ($candidatos as [$campo, $clave, $r, $valor, $valorNorm]) {
                    // Primer acierto manda: $candidatos ya viene en orden de $priority, asi
                    // que un mismo equipo que case por placa Y por chasis se reporta por la
                    // placa. Sobrescribir habria invertido esa preferencia sin querer.
                    if (!isset($aciertos[$clave]) && str_contains($valorNorm, $frag)) {
                        $aciertos[$clave] = [$campo, $r, $valor];
                    }
                }
                // A DIFERENCIA de la pasada exacta, aqui NO se aplica el desempate de
                // $priority (equipo por encima de auxiliar). Alli desempata porque es el
                // MISMO valor escrito en dos fichas; aqui son valores DISTINTOS que apenas
                // comparten un trozo, y quedarse con uno seria inventar. Registros distintos
                // = ambiguo, sin excepciones.
                if (count($aciertos) === 1) {
                    [$campo, $fila, $valorReal] = reset($aciertos);
                    $indexParcial[$campo][$t] = $fila;
                    $parcialValor[$t] = $valorReal;
                } elseif (count($aciertos) > 1) {
                    $ambiguos[$t] = count($aciertos);
                }
            }
        }

        $found = 0;
        $inOtherFrente = 0;
        $results = $terms->map(function ($term) use ($indexByField, $indexNorm, $indexParcial, $ambiguos, $parcialValor, $normPhp, $priority, $camposAux, $frenteIdFiltro, &$found, &$inOtherFrente) {
            // TRES pasadas, de mas a menos confianza; la primera que acierte se lo lleva:
            //   1. LITERAL en todos los campos.
            //   2. NORMALIZADA (O→0), por si el valor se tecleo con la letra en vez del cero.
            //   3. PARCIAL, ya resuelta a un unico equipo por el bloque de arriba.
            // El orden no es casual: un valor que existe tal cual nunca se lo puede llevar
            // una coincidencia tolerante —ni mucho menos un fragmento— de OTRO registro.
            $busquedas = [
                [$indexByField, $term,            false],
                [$indexNorm,    $normPhp($term), false],
                [$indexParcial, $term,            true],
            ];
            foreach ($busquedas as [$indice, $clave, $esParcial]) {
            foreach ($priority as $field) {
                if (isset($indice[$field][$clave])) {
                    $r = $indice[$field][$clave];
                    $found++;
                    $idFrenteActual = $r->ID_FRENTE_ACTUAL ? (int) $r->ID_FRENTE_ACTUAL : null;
                    // in_selected_frente: true si no hay filtro o si el equipo
                    // ESTA en el frente seleccionado. false → renderiza amarillo.
                    $inFrente = ($frenteIdFiltro === null) || ($idFrenteActual === (int) $frenteIdFiltro);
                    if (!$inFrente) $inOtherFrente++;

                    if (in_array($field, $camposAux, true)) {
                        return [
                            'term'               => $term,
                            'found'              => true,
                            'parcial'            => $esParcial ? ($parcialValor[$term] ?? null) : null,
                            // id en NULL a propósito: el front arma con él la selección para
                            // MOVILIZAR equipos (_bulkLookupFound filtra por r.id) y un auxiliar
                            // no se moviliza por esa vía. Se encuentra y se muestra, pero no
                            // entra en esa acción.
                            'id'                 => null,
                            'es_auxiliar'        => true,
                            'id_auxiliar'        => (int) $r->ID_AUXILIAR,
                            'codigo'             => $r->CODIGO_INTERNO,
                            'placa'              => null,
                            'chasis'             => $r->SERIAL,
                            'tipo_nombre'        => $r->TIPO,
                            'marca'              => trim(($r->MARCA ?? '') . ' ' . ($r->MODELO ?? '')) ?: null,
                            'frente_nombre'      => $r->NOMBRE_FRENTE ?: 'SIN ASIGNAR',
                            'id_frente_actual'   => $idFrenteActual,
                            'estado'             => $r->ESTADO_OPERATIVO ?: 'N/A',
                            'rol_anclaje'        => null,
                            'anchor_id'          => null,
                            'in_selected_frente' => $inFrente,
                        ];
                    }

                    return [
                        'term'                => $term,
                        'found'               => true,
                        // Valor real contra el que caso, o null si la coincidencia fue exacta.
                        // El front lo muestra para que se pueda verificar el acierto.
                        'parcial'             => $esParcial ? ($parcialValor[$term] ?? null) : null,
                        'id'                  => (int) $r->ID_EQUIPO,        // para movilizar los encontrados
                        'codigo'              => $r->CODIGO_PATIO,
                        'placa'               => $r->PLACA,
                        'chasis'              => $r->SERIAL_CHASIS,
                        'tipo_nombre'         => $r->TIPO_NOMBRE,
                        'marca'               => $r->MARCA,
                        'frente_nombre'       => $r->NOMBRE_FRENTE ?: 'SIN ASIGNAR',
                        'id_frente_actual'    => $idFrenteActual,
                        'estado'              => $r->ESTADO_OPERATIVO ?: 'N/A',
                        'rol_anclaje'         => $r->ROL_ANCLAJE,
                        'anchor_id'           => $r->ID_ANCLAJE,
                        'in_selected_frente'  => $inFrente,
                    ];
                }
            }   // fin del recorrido de campos por prioridad
            }   // fin de las TRES pasadas (exacta, normalizada y parcial)
            return [
                'term'   => $term,
                'found'  => false,
                // >0 cuando el fragmento SI aparece, pero en varios equipos: el front lo
                // dice en vez del generico "no existe".
                'ambiguo' => $ambiguos[$term] ?? 0,
            ];
        })->values();

        // Confirmar en sitio los equipos que coinciden con el frente seleccionado.
        // Confirmar en sitio es una ESCRITURA y exige 'equipos.edit' — el mismo permiso que
        // confirmarSitio() (ver constructor). La ruta bulk-lookup solo pide 'auth' porque la
        // BUSQUEDA es de consulta; sin este guard cualquier usuario autenticado confirmaba
        // equipos con solo elegir un frente en el modal.
        $puedeConfirmar = auth()->user()?->can('equipos.edit') ?? false;

        $confirmed = 0;
        if ($frenteIdFiltro !== null && $puedeConfirmar) {
            $idsToConfirm = $results
                ->filter(fn($r) => ($r['found'] ?? false) && ($r['in_selected_frente'] ?? false))
                ->pluck('id')
                ->filter()
                ->values()
                ->all();
            if (!empty($idsToConfirm)) {
                $confirmed = Equipo::whereIn('ID_EQUIPO', $idsToConfirm)
                    ->where('CONFIRMADO_EN_SITIO', 0)
                    ->update(['CONFIRMADO_EN_SITIO' => 1]);
            }
        }
        // Se eligió un frente (= se pidió confirmar) pero falta el permiso. El modal lo
        // avisa en el resumen; sin esto la búsqueda salía bien y el usuario creía haber
        // confirmado. Distinto de confirmed=0, que significa "no había nada que confirmar".
        $confirmDenied = ($frenteIdFiltro !== null && !$puedeConfirmar);

        return response()->json([
            'total'            => $results->count(),
            'found'            => $found,
            'missing'          => $results->count() - $found,
            'in_other_frente'  => $inOtherFrente,
            'confirmed'        => $confirmed,
            'confirm_denied'   => $confirmDenied,
            // Terminos que ni se intentaron rescatar por coincidencia parcial al pasar del
            // tope (ver BULK_PARCIAL_MAX). Se informa para que el modal no de a entender
            // que todos los 'no encontrado' se buscaron por igual.
            'parcial_omitidos' => $parcialOmitidos,
            'results'          => $results,
        ]);
    }

    /**
     * Get anchored equipment pairs for a specific frente (or all if not specified)
     */
    public function getAnchoredEquipos(Request $request)
    {
        $frenteId = $request->input('frente_id');
        $tipoId   = $request->input('id_tipo');
        // Se cargan también las relaciones ANIDADAS de ancladoA (especificaciones,
        // documentacion, tipo) porque el map de abajo las accede; sin esto cada par
        // anclado dispara ~3 queries lazy (N+1). Espeja lo que hace exportAnclajes.
        $query = Equipo::with([
            'ancladoA', 'ancladoA.especificaciones', 'ancladoA.documentacion', 'ancladoA.tipo',
            'tipo', 'especificaciones', 'documentacion',
        ])->whereNotNull('ID_ANCLAJE');

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

        $sheet->getRowDimension('1')->setRowHeight(35);
        $sheet->getRowDimension('2')->setRowHeight(35);
        $sheet->getRowDimension('3')->setRowHeight(35);

        $sheet->mergeCells('A1:B3');
        $sheet->getStyle('A1:B3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        // Logo centrado en A1:B3 (trait ExcelLogoCorporativo)
        $this->insertarLogoCorporativo($sheet, ['A','B'], [1,2,3]);

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

            // Soltar anclajes PREVIOS de source/target antes de crear el nuevo. La
            // lista de destinos (getEquiposByFrente) no excluye equipos ya anclados,
            // así que source o target pueden traer pareja. Sin esto quedaría un
            // huérfano unidireccional: si B estaba con X (B→X, X→B) y se ancla A→B,
            // X seguiría apuntando a B. Espeja la limpieza bidireccional de clearAnchor.
            $previos = Equipo::whereIn('ID_EQUIPO', [$sourceId, $targetId])
                             ->pluck('ID_ANCLAJE')->filter()->all();
            $aSoltar = array_unique(array_merge([$sourceId, $targetId], $previos));
            Equipo::whereIn('ID_EQUIPO', $aSoltar)->update(['ID_ANCLAJE' => null]);
            Equipo::whereIn('ID_ANCLAJE', $aSoltar)->update(['ID_ANCLAJE' => null]);

            // Create mutual anchor link
            Equipo::where('ID_EQUIPO', $sourceId)->update(['ID_ANCLAJE' => $targetId]);
            Equipo::where('ID_EQUIPO', $targetId)->update(['ID_ANCLAJE' => $sourceId]);
            // Mass-update sin eventos Eloquent (el anclaje alimenta el modal de
            // alertas de /menu) → bump explícito del dashboard.
            \App\Http\Controllers\DashboardController::bumpDataVersion();

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
            // Mass-update sin eventos Eloquent (el anclaje alimenta el modal de
            // alertas de /menu) → bump explícito del dashboard.
            \App\Http\Controllers\DashboardController::bumpDataVersion();

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
    // decide — NIVEL_ACCESO_EQUIPOS del usuario no restringe la operacion.
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
            ? mb_strtoupper(trim($request->DETALLE_UBICACION_ACTUAL))
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
     * NIVEL_ACCESO_EQUIPOS del usuario NO restringe la operacion — filosofia del
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
        // Guardar NULL cuando el valor llega vacío (borra la ubicación en BD).
        // mb_strtoupper (no strtoupper) para que la ñ/acentos suban a mayúscula
        // correctamente (strtoupper es byte a byte y dejaba "SEñOR").
        $valor = ($rawValor !== null && trim($rawValor) !== '')
            ? mb_strtoupper(trim($rawValor))
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

        $query = Equipo::with(['tipo', 'frenteActual', 'documentacion', 'especificaciones'])
            ->excludeEspecial();

        if ($search) {
            $searchUpper = strtoupper(trim($search));
            $query->where(function ($q) use ($searchUpper) {
                $q->where('CODIGO_PATIO', 'like', "%{$searchUpper}%")
                  ->orWhere('SERIAL_CHASIS', 'like', "%{$searchUpper}%")
                  // SERIAL_DE_MOTOR: la búsqueda web (index/export) sí lo incluye; el móvil
                  // lo omitía → buscar por nº de motor no encontraba el equipo en la APK.
                  ->orWhere('SERIAL_DE_MOTOR', 'like', "%{$searchUpper}%")
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
            // Foto: prioriza FOTO_REFERENCIAL del catalogo (Google Drive ID),
            // cae a FOTO_EQUIPO (URL directa). Misma logica que el listado web
            // y la papelera (EquipoController::papelera). Si es un drive ID se
            // convierte al thumbnail publico para que el <Image> de RN lo
            // descargue directo sin proxy.
            $foto = null;
            $raw = ($eq->especificaciones && $eq->especificaciones->FOTO_REFERENCIAL)
                ? $eq->especificaciones->FOTO_REFERENCIAL
                : $eq->FOTO_EQUIPO;
            if ($raw) {
                // Extraer drive id si viene como URL completa
                if (preg_match('#(?:/d/|id=)([\w-]{20,})#', $raw, $m)) {
                    $foto = 'https://drive.google.com/thumbnail?id=' . $m[1] . '&sz=w400';
                } else {
                    $foto = $raw; // URL directa
                }
            }

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
                'FOTO'            => $foto,
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

    /**
     * Tope de movilizaciones que devuelve el modal "Movilizaciones" del detalle.
     * No es un limite de negocio (el historial completo vive en /admin/movilizaciones):
     * es para que un equipo con cientos de movimientos no dispare una respuesta enorme
     * ni un modal imposible de recorrer. Se pide UNA fila de mas que este tope para
     * saber si hubo recorte, sin pagar un COUNT(*) aparte.
     */
    private const MOVILIZACIONES_MODAL_MAX = 100;

    /**
     * Movilizaciones de UN equipo, para el modal del detalle en /admin/equipos.
     *
     * QUE FECHA SE USA. `created_at`, no `FECHA_DESPACHO`. Esta ultima solo se rellena
     * cuando el movimiento genera acta: la fila nace como `$generarPdf ? 'DESPACHO' :
     * 'ACT.'` (MovilizacionController y EquipoAuxiliarController) y las ACT. se guardan
     * sin fecha — 786 de 1.265 filas, el 62%. Ordenar por ahi tenia dos efectos: se leia
     * "Sin fecha" en la mayoria de las filas, y como MySQL manda los NULL AL FINAL en un
     * DESC, la movilizacion MAS RECIENTE aparecia la ULTIMA, debajo de otras dos meses
     * mas viejas. `created_at` no tiene ni un nulo y es la que ya usa el listado de
     * /admin/movilizaciones (partials/table_rows.blade.php), asi que el modal y la
     * pantalla grande cuentan lo mismo.
     *
     * Rendimiento (lo caro aqui es la tabla, no el PHP):
     *  - Se apoya en el indice `idx_mov_hist_equipo_creado (ID_EQUIPO, created_at)`.
     *    Sin un indice que empiece por ID_EQUIPO, MySQL escanea la tabla entera y encima
     *    ordena a mano (type: ALL, rows: 1265, Using filesort).
     *  - SELECT explicito: sin `select *` no se arrastran columnas que el modal no pinta
     *    (client_uuid, ID_FRENTE_RECEPCION, FECHA_DESPACHO...).
     *  - Los nombres de frente salen del SNAPSHOT congelado en la propia fila, asi que el
     *    caso normal NO toca `frentes_trabajo`. Solo las filas viejas anteriores a esa
     *    columna necesitan el nombre en vivo, y se resuelven con UN load() para toda la
     *    coleccion — nunca una consulta por fila (N+1).
     *
     * Desempate por ID_MOVILIZACION DESC: created_at es un timestamp y un lote entero se
     * guarda en el mismo segundo; sin desempate MySQL no garantiza un orden estable y la
     * lista se barajaba entre una carga y otra.
     */
    public function getMovilizaciones($id)
    {
        $movs = \App\Models\Movilizacion::query()
            ->where('ID_EQUIPO', $id)
            ->select([
                'ID_MOVILIZACION',
                'CODIGO_CONTROL',
                // No se pinta, pero lo necesita el accesor formatted_codigo_control para
                // saber si una fila sin codigo es una recepcion directa ("R.D.") o un
                // movimiento sin acta (sin rotulo). Sin esta columna ni las recepciones
                // directas de verdad saldrian marcadas.
                'TIPO_MOVIMIENTO',
                'created_at',
                'DETALLE_UBICACION',
                'USUARIO_REGISTRO',
                // Las dos FK hacen falta aunque casi nunca se usen: son las que permiten
                // el load() de abajo cuando una fila vieja no trae snapshot.
                'ID_FRENTE_ORIGEN',
                'ID_FRENTE_DESTINO',
                'NOMBRE_FRENTE_ORIGEN_SNAPSHOT',
                'NOMBRE_FRENTE_DESTINO_SNAPSHOT',
            ])
            // El nombre de quien registro: la relacion enlaza por CORREO_ELECTRONICO
            // (USUARIO_REGISTRO guarda el correo, no el id). Se carga en bloque para no
            // disparar una consulta por fila.
            ->with('usuario:ID_USUARIO,CORREO_ELECTRONICO,NOMBRE_COMPLETO')
            ->orderByDesc('created_at')
            ->orderByDesc('ID_MOVILIZACION')
            ->limit(self::MOVILIZACIONES_MODAL_MAX + 1)
            ->get();

        $hayMas = $movs->count() > self::MOVILIZACIONES_MODAL_MAX;
        $movs   = $movs->take(self::MOVILIZACIONES_MODAL_MAX);

        // Filas anteriores a las columnas de snapshot: se les completa el nombre en vivo.
        // Un load() por relacion y solo si hace falta => 0 consultas extra en el caso normal.
        if ($movs->contains(fn ($m) => $m->NOMBRE_FRENTE_ORIGEN_SNAPSHOT === null)) {
            $movs->load('frenteOrigen:ID_FRENTE,NOMBRE_FRENTE');
        }
        if ($movs->contains(fn ($m) => $m->NOMBRE_FRENTE_DESTINO_SNAPSHOT === null)) {
            $movs->load('frenteDestino:ID_FRENTE,NOMBRE_FRENTE');
        }

        return response()->json([
            'success' => true,
            'hay_mas' => $hayMas,
            'maximo'  => self::MOVILIZACIONES_MODAL_MAX,
            // El payload se arma aqui y no en el front: los accesores del modelo
            // (nombre_origen / formatted_codigo_control) son la fuente unica de esos
            // valores y ya los usa el resto del modulo.
            'data'    => $movs->map(fn ($m) => [
                'id'      => $m->ID_MOVILIZACION,
                // MV-000NN si hay acta, "R.D." si es una recepcion directa, null en lo
                // demas. Ese reparto lo decide el accesor y aqui no se repite: durante un
                // tiempo esta linea llevaba su propio `$m->CODIGO_CONTROL ? ... : null`
                // para esquivar el "R.D." que el accesor devolvia a todo el que no tuviera
                // codigo — ya corregido en el modelo, asi que el parche sobra.
                'codigo'  => $m->formatted_codigo_control,
                'fecha'   => optional($m->created_at)->format('d/m/Y'),
                'origen'  => $m->nombre_origen,
                'destino' => $m->nombre_destino,
                'detalle' => $m->DETALLE_UBICACION,
                // NOMBRE del que registro, no su correo: en el modal se lee de un
                // vistazo y no obliga a traducir mentalmente una direccion. Si esa
                // cuenta ya no existe queda el correo, que es lo unico que hay.
                'usuario' => optional($m->usuario)->NOMBRE_COMPLETO ?: $m->USUARIO_REGISTRO,
            ])->values(),
        ]);
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
        $isLocal = ($user ? !$user->veTodosLosFrentesEquipos() : false);

        // Cache el binario XLSX en disco por usuario-scope (solo cambia si agregan frentes/tipos).
        // Se invalida automaticamente al guardar/borrar TipoEquipo o FrenteTrabajo: el
        // counter `bulk_template_gen` incrementa y los archivos viejos quedan obsoletos.
        // Usamos disco en vez de Cache::remember porque algunos drivers (database) no manejan binario.
        // El sufijo _b<hash> incluye los frentes bloqueados: la lista de frentes de la
        // plantilla los excluye (también para GLOBAL), así que cambiarlos genera otra clave.
        $scopeKey = ($isLocal
            ? 'local_' . md5(implode(',', $user->getFrentesIds()))
            : 'global')
            . '_b' . md5(implode(',', $user ? $user->getFrentesBloqueadosIds() : []));
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
            $binary = $this->buildBulkTemplateBinary($user);
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
    private function buildBulkTemplateBinary($user): string
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
        // Lista de frentes de la plantilla: oculta los no visibles (whitelist LOCAL +
        // blacklist de bloqueados) para que no se pueda dar de alta en un frente prohibido.
        $frentesQuery = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE');
        if ($user) {
            $user->aplicarScopeFrentesEquipos($frentesQuery, 'ID_FRENTE');
        }
        $frentes    = $frentesQuery->pluck('NOMBRE_FRENTE')->toArray();
        $categorias = ['FLOTA LIVIANA', 'FLOTA PESADA'];
        $statuses   = ['OPERATIVO', 'INOPERATIVO', 'EN MANTENIMIENTO', 'DESINCORPORADO'];

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
        $validStatuses   = ['OPERATIVO', 'INOPERATIVO', 'EN MANTENIMIENTO', 'DESINCORPORADO'];
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

        // Resolver lookups de tipos y frentes en memoria para evitar N+1.
        // El mapa de frentes válidos oculta los no visibles (whitelist LOCAL + blacklist
        // de bloqueados): una fila que apunte a un frente prohibido se marca inválida.
        $tiposMap   = TipoEquipo::orderBy('nombre')->get()->keyBy(fn($t) => strtolower(trim($t->nombre)));
        $frentesQuery = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE');
        if ($user) {
            $user->aplicarScopeFrentesEquipos($frentesQuery, 'ID_FRENTE');
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
            // El template viejo ofrecía 'MANTENIMIENTO'; el resto del sistema (filtros,
            // KPIs, changeStatus) usa 'EN MANTENIMIENTO'. Normalizamos el valor legacy
            // para no rechazar archivos antiguos y guardar siempre el canónico.
            if ($statusUpper === 'MANTENIMIENTO') { $statusUpper = 'EN MANTENIMIENTO'; }

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
        if ($user) {
            $user->aplicarScopeFrentesEquipos($frentesOptionsQuery, 'ID_FRENTE');
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

        $rows            = $request->input('rows');
        $validCategorias = ['FLOTA LIVIANA', 'FLOTA PESADA'];
        $validStatuses   = ['OPERATIVO', 'INOPERATIVO', 'EN MANTENIMIENTO', 'DESINCORPORADO'];
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

        // Resolver lookups en memoria. El mapa de frentes válidos oculta los no visibles
        // (whitelist LOCAL + blacklist de bloqueados): no se puede dar de alta en un frente prohibido.
        $tiposMap  = TipoEquipo::orderBy('nombre')->get()->keyBy(fn($t) => strtolower(trim($t->nombre)));
        $frentesQuery = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE');
        if ($user) {
            $user->aplicarScopeFrentesEquipos($frentesQuery, 'ID_FRENTE');
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
            // El template viejo ofrecía 'MANTENIMIENTO'; el resto del sistema (filtros,
            // KPIs, changeStatus) usa 'EN MANTENIMIENTO'. Normalizamos el valor legacy
            // para no rechazar archivos antiguos y guardar siempre el canónico.
            if ($statusUpper === 'MANTENIMIENTO') { $statusUpper = 'EN MANTENIMIENTO'; }

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
        // try/catch: entre el chequeo de unicidad de arriba y estos INSERT hay una ventana
        // en la que otra petición puede insertar el mismo serial. El índice único de BD lo
        // impide (no hay duplicado), pero sin capturar la QueryException el usuario recibía
        // un 500 crudo en vez de un error entendible. Mismo trato que el bulk de auxiliares.
        try {
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
                    $nuevoEquipo = Equipo::create([
                        'id_tipo_equipo'           => $idTipo,
                        'CATEGORIA_FLOTA'          => $row['categoria_flota'],
                        'MARCA'                    => strtoupper($row['marca']),
                        'MODELO'                   => strtoupper($row['modelo']),
                        'ANIO'                     => (int)$row['anio'],
                        'NUMERO_ETIQUETA'          => $row['numero_etiqueta'],
                        'SERIAL_CHASIS'            => strtoupper($row['serial_chasis']),
                        'SERIAL_DE_MOTOR'          => $row['serial_de_motor'] ? strtoupper($row['serial_de_motor']) : null,
                        'ESTADO_OPERATIVO'         => $row['status'],
                        'CONFIRMADO_EN_SITIO'      => 0,
                        'ID_ESPEC'                 => null,
                        'CODIGO_PATIO'             => null,
                        'DETALLE_UBICACION_ACTUAL' => null,
                        'FOTO_EQUIPO'              => null,
                        'LINK_GPS'                 => null,
                        'CREADO_POR'               => $user->ID_USUARIO,
                    ]);
                    // ID_FRENTE_ACTUAL NO es fillable (ver Equipo::$fillable) → se asigna por
                    // propiedad tras crear. Con create([...]) se descartaba en silencio y el
                    // equipo importado quedaba SIN frente asignado. (ID_ANCLAJE nace null por defecto.)
                    $nuevoEquipo->ID_FRENTE_ACTUAL = $row['id_frente_resuelto'];
                    $nuevoEquipo->save();
                }
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // 1062 = Duplicate entry (índice único: SERIAL_CHASIS / SERIAL_DE_MOTOR).
            if (($e->errorInfo[1] ?? null) === 1062) {
                return response()->json([
                    'success' => false,
                    'message' => 'Otro usuario acaba de registrar uno de estos seriales. No se guardó nada; vuelve a previsualizar el archivo.',
                ], 422);
            }
            throw $e;
        }

        // Marcas/modelos/años nuevos del Excel: sin esto no salían en los autocompletes
        // del formulario ni en los filtros del índice hasta que expiraba la caché.
        $this->olvidarCachesListasEquipos();

        $count = count($resolvedRows);

        return response()->json([
            'success'  => true,
            'message'  => $count . ' equipo' . ($count === 1 ? '' : 's') . ' creado' . ($count === 1 ? '' : 's') . ' correctamente.',
            'count'    => $count,
            'redirect' => '/admin/equipos',
        ]);
    }

    /**
     * Agrega una segunda hoja "Equipos Auxiliares" al spreadsheet de exportación. Recibe la
     * query YA filtrada y con scope aplicado (la arma export() vía exportQuery, con la misma
     * regla que la pantalla), por lo que aquí no se reimplementa el filtrado: solo se pinta.
     * $auxQuery debe traer la relación 'frente' eager-loaded.
     */
    private function appendAuxSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, $auxQuery, string $nombreFrente): void
    {
        $auxSheet = $spreadsheet->createSheet();
        $auxSheet->setTitle('Equipos Auxiliares');

        $currentDate = date('d/m/Y');
        $lastCol = 'J'; // N°, Frente, Tipo, Marca, Modelo, Serial, Código, Capacidad, Año, Estado

        foreach ([1, 2, 3] as $r) {
            $auxSheet->getRowDimension($r)->setRowHeight(40);
        }

        // Logo A1:B3
        $auxSheet->mergeCells('A1:B3');
        $auxSheet->getStyle('A1:B3')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        $this->insertarLogoCorporativo($auxSheet, ['A', 'B'], [1, 2, 3]);

        // Título central C1:F3
        $auxSheet->mergeCells('C1:F3');
        $subTitle = $nombreFrente !== 'TODOS LOS FRENTES'
            ? 'PROYECTO: "' . mb_strtoupper($nombreFrente) . '"'
            : 'COPIA DE BASE DE DATOS DEL SISTEMA DE GESTION DE EQUIPOS OPERACIONALES';
        $auxSheet->setCellValue('C1', "LISTADO DE EQUIPOS AUXILIARES\n" . $subTitle);
        $auxSheet->getStyle('C1')->getAlignment()->setWrapText(true)
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $auxSheet->getStyle('C1')->getFont()->setBold(true)->setSize(14)
            ->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $auxSheet->getStyle('C1:F3')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        // Metadatos G1:J3
        foreach ([['G1', 'EDICION: 1'], ['G2', 'REVISION: 0'], ['G3', 'FECHA: ' . $currentDate]] as [$cell, $text]) {
            $r = substr($cell, 1);
            $auxSheet->mergeCells("G{$r}:{$lastCol}{$r}");
            $auxSheet->setCellValue($cell, $text);
            $auxSheet->getStyle($cell)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $auxSheet->getStyle($cell)->getFont()->setBold(true)->setSize(11)
                ->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
            $auxSheet->getStyle("G{$r}:{$lastCol}{$r}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        }

        // Fila 4 — Exportado por
        $auxSheet->mergeCells("A4:{$lastCol}4");
        $auxSheet->setCellValue('A4', 'Exportado por: Sistema de Gestión de Equipos Operacionales');
        $auxSheet->getStyle("A4:{$lastCol}4")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        $auxSheet->getStyle("A4:{$lastCol}4")->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $auxSheet->getStyle("A4:{$lastCol}4")->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF333333');
        $auxSheet->getRowDimension(4)->setRowHeight(20);

        $borderArray = [
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FF000000']]],
        ];
        $auxSheet->getStyle("A1:{$lastCol}4")->applyFromArray($borderArray);

        // Fila 5 — Headers
        $headers = ['N°', 'FRENTE', 'TIPO', 'MARCA', 'MODELO', 'SERIAL', 'CÓDIGO INTERNO', 'CAPACIDAD', 'AÑO', 'ESTADO'];
        $auxSheet->fromArray($headers, null, 'A5');
        $auxSheet->getStyle("A5:{$lastCol}5")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $auxSheet->getStyle("A5:{$lastCol}5")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF1B365D');
        $auxSheet->getStyle("A5:{$lastCol}5")->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $auxSheet->getRowDimension(5)->setRowHeight(30);
        // Bordes de la fila de encabezados siempre, aunque no haya filas de datos.
        $auxSheet->getStyle("A5:{$lastCol}5")->applyFromArray($borderArray);

        // Anchos de columna
        $auxSheet->getColumnDimension('A')->setWidth(6);
        $auxSheet->getColumnDimension('B')->setWidth(25);
        $auxSheet->getColumnDimension('C')->setWidth(22);
        $auxSheet->getColumnDimension('D')->setWidth(18);
        $auxSheet->getColumnDimension('E')->setWidth(20);
        $auxSheet->getColumnDimension('F')->setWidth(22);
        $auxSheet->getColumnDimension('G')->setWidth(18);
        $auxSheet->getColumnDimension('H')->setWidth(14);
        $auxSheet->getColumnDimension('I')->setWidth(8);
        $auxSheet->getColumnDimension('J')->setWidth(20);

        // La query de auxiliares ($auxQuery) llega YA filtrada y con scope desde export().

        // Lookup dinámico: incluye tipos hardcodeados + cualquier TIPO en BD no catalogado.
        $staticLabels  = \App\Models\EquipoAuxiliar::tiposLabel();
        $tiposEnDB     = \App\Models\EquipoAuxiliar::select('TIPO')->whereNotNull('TIPO')->where('TIPO', '!=', '')->distinct()->pluck('TIPO');
        $tiposLabels   = [];
        foreach ($tiposEnDB as $t) {
            $tiposLabels[$t] = $staticLabels[$t] ?? ucwords(mb_strtolower(str_replace('_', ' ', $t)));
        }
        $estadosLabels = \App\Models\EquipoAuxiliar::estadosLabel();

        $row     = 6;
        $counter = 1;
        $auxQuery->orderBy('TIPO')->chunk(300, function ($rows) use ($auxSheet, $tiposLabels, $estadosLabels, $lastCol, &$row, &$counter) {
            foreach ($rows as $r) {
                $auxSheet->setCellValue("A{$row}", str_pad($counter, 2, '0', STR_PAD_LEFT));
                $auxSheet->setCellValue("B{$row}", optional($r->frente)->NOMBRE_FRENTE ?? 'S/A');
                $auxSheet->setCellValue("C{$row}", mb_strtoupper($tiposLabels[$r->TIPO] ?? $r->TIPO ?? '—'));
                $auxSheet->setCellValue("D{$row}", mb_strtoupper($r->MARCA ?? '—'));
                $auxSheet->setCellValue("E{$row}", mb_strtoupper($r->MODELO ?? '—'));
                $auxSheet->setCellValue("F{$row}", $r->SERIAL ?? '—');
                $auxSheet->setCellValue("G{$row}", $r->CODIGO_INTERNO ?? '—');
                $auxSheet->setCellValue("H{$row}", $r->CAPACIDAD ?? '—');
                $auxSheet->setCellValue("I{$row}", $r->ANIO ?? '—');
                $auxSheet->setCellValue("J{$row}", mb_strtoupper($estadosLabels[$r->ESTADO_OPERATIVO] ?? $r->ESTADO_OPERATIVO ?? '—'));

                $argb = ($counter % 2 === 0) ? 'FFF1F5F9' : 'FFFFFFFF';
                $auxSheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB($argb);
                $auxSheet->getRowDimension($row)->setRowHeight(25);

                $row++;
                $counter++;
            }
        });

        $lastDataRow = $row - 1;
        if ($lastDataRow >= 6) {
            // Alineación de columnas de datos (en lote, fuera del loop — mismo patrón que la hoja de equipos)
            $auxSheet->getStyle("A6:{$lastCol}{$lastDataRow}")->getAlignment()
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $auxSheet->getStyle("A6:A{$lastDataRow}")->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $auxSheet->getStyle("I6:I{$lastDataRow}")->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $auxSheet->getStyle("J6:J{$lastDataRow}")->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            // Fila total
            $auxSheet->setCellValue("A{$row}", 'TOTAL');
            $auxSheet->mergeCells("B{$row}:{$lastCol}{$row}");
            $auxSheet->setCellValue("B{$row}", ($counter - 1) . ' AUXILIARES LISTADOS');
            $auxSheet->getStyle("A{$row}:{$lastCol}{$row}")->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FF1E293B');
            $auxSheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');
            $auxSheet->getStyle("A{$row}:{$lastCol}{$row}")->getAlignment()
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $auxSheet->getStyle("A{$row}:B{$row}")->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $auxSheet->getRowDimension($row)->setRowHeight(28);

            $auxSheet->getStyle("A5:{$lastCol}{$row}")->applyFromArray($borderArray);
        }
    }
}
