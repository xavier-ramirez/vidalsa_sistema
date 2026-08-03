<?php

namespace App\Http\Controllers;

use App\Models\Almacen;
use App\Models\ProductoInventario;
use App\Models\Traspaso;
use App\Models\TraspasoLinea;
use App\Services\TraspasoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Pedido de Traspaso (envío entre almacenes con recepción confirmada).
 *
 * Flujo:
 *   ORIGEN  : create → store → (update*) → enviar
 *   DESTINO : show (bandeja "por recibir") → recibir
 *   AMBOS   : cancelar (origen antes de enviar, admin después)
 *
 * Permisos:
 *  - almacen.movimiento : crear borrador, editar borrador, enviar, cancelar antes de enviar
 *                          y confirmar recepción en el almacén destino.
 *
 * Visibilidad de traspasos: depende SOLO de `Almacen::visiblesPara($user)` (es decir, de
 * `NIVEL_ACCESO_ALMACEN`). Los usuarios GLOBAL ven todos los traspasos; los LOCAL ven solo los
 * traspasos donde origen o destino son almacenes ligados a sus frentes.
 */
class TraspasoController extends Controller
{
    public function __construct(private TraspasoService $traspasos)
    {
        // store/update/enviar/cancelar/destroy + recibir → todos bajo almacen.movimiento.
        $this->middleware('can:almacen.movimiento')->only(['store', 'update', 'enviar', 'cancelar', 'destroy', 'recibir']);
    }

    // ─────────────────────────────────────────────────────────────
    //  Listado / bandeja
    // ─────────────────────────────────────────────────────────────

    /**
     * Bandeja de Recepción: las notas que el almacén destino todavía tiene que atender, en
     * sus almacenes visibles — por defecto Traspaso::ESTADOS_BANDEJA_DEFAULT (En tránsito +
     * Confirmada parcial). El historial completo se ve en "Historial de Movimientos" del nav
     * (TIPO=TRASPASO_ENTRADA / TRASPASO_SALIDA en el kardex).
     *
     * Filtros del UI: estado → sin valor = el default de arriba; un ESTADO concreto; o un
     *                           pseudo-estado de Traspaso::FILTROS_META (`con_faltantes` =
     *                           confirmadas con material faltante; `all` = sin filtro de
     *                           estado, aceptado por URL pero NO ofrecido en el dropdown).
     *                 id_almacen_origen, id_almacen_destino, desde, hasta.
     *                 search      → busca por NUMERO / REFERENCIA de la nota (NE-2026-…)
     *                 id_producto → solo las notas que contienen ese producto.
     *                 kpi         → atajo de las 3 métricas del panel; siempre pendientes.
     */
    public function index(Request $request)
    {
        $user      = $request->user();

        // Una sola consulta: la usamos para (a) limitar el WHERE de la query, (b) llenar el
        // dropdown del header, (c) validar el default-por-frente y (d) el guard anti-loop
        // de mas abajo (movido ARRIBA del redirect a nuevaEntrada — sino GLOBAL sin
        // almacenes caia en loop infinito entre `recepcion.index` y `recepcion.nueva`).
        $almacenes         = Almacen::visiblesPara($user)->orderBy('TIPO')->orderBy('NOMBRE')->get(['ID_ALMACEN', 'NOMBRE', 'TIPO']);
        $almacenesVisibles = $almacenes->pluck('ID_ALMACEN');

        // Guard: usuario sin almacenes visibles → menu con notificacion. Cubre 2 casos:
        //   - LOCAL (NIVEL 2) cuyo frente no tiene almacen asociado.
        //   - GLOBAL (NIVEL 1) en BD recien migrada / sin almacenes registrados todavia.
        // Sin este guard, el GLOBAL hacia loop: `index()` lo redirige a `nuevaEntrada`,
        // `nuevaEntrada` no encuentra almacenDestino y redirige de vuelta a `index()`,
        // y asi sin parar (ERR_TOO_MANY_REDIRECTS / "error de conexion" en produccion).
        if (!$request->wantsJson() && $almacenes->isEmpty()) {
            // GLOBAL ve "todos" (criterio ÚNICO Almacen::usuarioEsGlobal): si está vacío es
            // BD sin almacenes → invitar a crear. LOCAL/restringido: su frente no tiene almacén.
            $msg   = Almacen::usuarioEsGlobal($user)
                ? 'No hay almacenes registrados todavía. Crea uno desde el módulo de Almacén antes de usar Recepción.'
                : 'Tu frente no tiene un almacén registrado. Avisa al administrador para que asocie un almacén a tu frente.';
            return redirect()->route('menu')->with('flash_toast', [
                'type'    => 'error',
                'message' => $msg,
            ]);
        }

        // Destino por defecto según tipo de usuario:
        //   GLOBAL → Recepción ODC (formulario de entrada directa por orden de compra).
        //            El almacén general no recibe por bandeja de notas de entrega.
        //   LOCAL  → Bandeja de notas de entrega (su módulo principal de recepción).
        //            Confirman lo que el almacén general les envió.
        //
        // ?force=1 o filtros explícitos → siempre muestran la bandeja (ambos tipos).
        // AJAX (paginación/filtros) → se queda aquí.
        if (
            $user !== null
            && ! $request->wantsJson()
            && ! $request->boolean('force')
            && ! $request->hasAny(['search', 'estado', 'id_almacen_origen', 'id_almacen_destino', 'id_producto', 'desde', 'hasta', 'kpi'])
            && Almacen::usuarioEsGlobal($user)
        ) {
            return redirect()->route('almacen.recepcion.nueva');
        }

        // Default suave del filtro "Almacén destino" — TODOS los usuarios (LOCAL y GLOBAL)
        // abren con UN almacén preseleccionado. La bandeja es SIEMPRE de un solo almacén:
        // el dropdown del header ya no ofrece "Todos" ni la X para quitar la selección
        // (pedido del cliente, ver el comentario del dropdown en recepcion/index).
        //   1) Si el cliente mando id_almacen_destino (filled), respetamos.
        //   2) Sino, intentamos el almacen ligado al frente (almacenPorDefecto).
        //   3) Fallback: el PRIMER almacen visible — cubre al usuario GLOBAL
        //      (NIVEL_ACCESO_ALMACEN=1) sin frente, para que tambien arranque con UNO
        //      solo, no con "Todos".
        // Validamos visibilidad para evitar un filtro fantasma.
        if (!$request->filled('id_almacen_destino')) {
            $idDef = $user?->almacenPorDefecto();
            if (!$idDef && $almacenesVisibles->isNotEmpty()) {
                $idDef = (int) $almacenesVisibles->first();
            }
            if ($idDef && $almacenesVisibles->contains((int) $idDef)) {
                $request->merge(['id_almacen_destino' => $idDef]);
            }
        }

        // Bandeja = inbox de recepción. La columna ID_ALMACEN_DESTINO ya cubre la
        // visibilidad de quién debe recibir.
        $q = Traspaso::query()
            ->with([
                'almacenOrigen:ID_ALMACEN,NOMBRE,TIPO',
                'almacenDestino:ID_ALMACEN,NOMBRE,TIPO',
                // La columna Destino muestra el FRENTE (ver Traspaso::getNombreDestinoAttribute).
                // Sin este eager-load serían N+1 consultas, una por fila de la bandeja.
                'frenteDestino:ID_FRENTE,NOMBRE_FRENTE',
                // El detalle de materiales NO se lista en la bandeja (se ve al abrir la
                // nota), así que no se hace eager-load de lineas.producto aquí.
            ]);
        // La visibilidad y el resto de filtros se aplican con $filtrosBandeja (más abajo),
        // que es la MISMA fuente para la tabla y para las sugerencias del buscador.

        // ── Filtro de estado ─────────────────────────────────────────────────────────
        // UN solo punto que decide el WHERE ESTADO. Antes el WHERE base fijaba ENVIADO y
        // este filtro se aplicaba encima → ESTADO=ENVIADO AND ESTADO=X = 0 resultados para
        // cualquier estado distinto de "En tránsito"; por eso se resuelve todo aquí.
        //
        // Los 3 KPIs del panel "Resumen de la bandeja" cuentan SOLO pendientes (ENVIADO),
        // así que los 3 fuerzan ese estado y pisan cualquier 'estado' pedido (incluido all).
        // 'por_revisar' también: desde que el default de la bandeja incluye las parciales,
        // dejarlo caer en el default listaba notas que ese número no cuenta.
        $kpi          = $request->input('kpi');
        $kpiPendiente = in_array($kpi, ['por_revisar', 'recientes', 'urgentes'], true);
        // Estado EFECTIVO, resuelto una sola vez:
        //   · KPI activo                          → ENVIADO (ver arriba).
        //   · estado conocido (real o pseudo)     → ese.
        //   · sin parámetro o basura en la URL    → null = default de la bandeja
        //     (Traspaso::ESTADOS_BANDEJA_DEFAULT: En tránsito + Confirmada parcial).
        // Lo de la basura importa: antes entraba tal cual al WHERE y devolvía la bandeja
        // vacía sin explicación. El criterio de cada pseudo-estado (all, con_faltantes)
        // está documentado en el modelo, junto a la constante — aquí solo se traduce a SQL.
        $estadoPedido = (string) $request->input('estado', '');
        $estadoValido = array_key_exists($estadoPedido, Traspaso::ESTADOS_META)
                     || array_key_exists($estadoPedido, Traspaso::FILTROS_META);
        $estadoActivo = $kpiPendiente ? Traspaso::ESTADO_ENVIADO : ($estadoValido ? $estadoPedido : null);

        // TODOS los filtros que definen "lo que la bandeja está mostrando" viven en ESTE
        // closure, porque se aplican a DOS consultas: la tabla y las sugerencias del buscador
        // por N° de nota. Si divergen, el autocomplete ofrece notas que la tabla no muestra y
        // al elegirlas la bandeja sale vacía (ya pasó con el estado, que estaba fijo en
        // ENVIADO del lado de las sugerencias).
        // NO entran aquí, a propósito: `search` (es justo lo que el usuario está tecleando en
        // ese buscador) y la ventana temporal del KPI ni el almacén ORIGEN, que solo acotan la
        // tabla.
        $filtrosBandeja = function ($query) use ($request, $estadoActivo, $almacenesVisibles) {
            // Visibilidad: la bandeja es un inbox, importa el DESTINO (quién debe recibir).
            $query->whereIn('ID_ALMACEN_DESTINO', $almacenesVisibles);

            // Estado (incluye los pseudo-estados; ver Traspaso::FILTRO_*).
            if ($estadoActivo === null) {                              // sin filtro: los dos estados accionables
                $query->whereIn('ESTADO', Traspaso::ESTADOS_BANDEJA_DEFAULT);
            } elseif ($estadoActivo === Traspaso::FILTRO_CON_FALTANTES) {
                $query->whereIn('ESTADO', Traspaso::ESTADOS_RECIBIDOS)
                    ->whereHas('lineas', fn ($l) => $l->where('ESTADO_LINEA', TraspasoLinea::ESTADO_FALTANTE));
            } elseif ($estadoActivo !== Traspaso::FILTRO_TODAS) {       // FILTRO_TODAS = sin WHERE de estado
                $query->where('ESTADO', $estadoActivo);
            }

            if ($request->filled('id_almacen_destino') && $request->input('id_almacen_destino') !== 'all') {
                $query->where('ID_ALMACEN_DESTINO', $request->integer('id_almacen_destino'));
            }
            // Filtro por PRODUCTO: solo las notas que CONTIENEN ese producto. El front sugiere
            // productos (con equivalencias, igual que inventario) y manda su id_producto.
            if ($request->filled('id_producto')) {
                $query->whereHas('lineas', fn ($l) => $l->where('ID_PRODUCTO', $request->integer('id_producto')));
            }
            // Fechas: el orWhere DEBE ir agrupado en un where(function) — suelto, el OR sube al
            // nivel superior y se escapa de los AND de estado/visibilidad (mostraría notas de
            // otro estado o de almacenes que el usuario no ve).
            foreach ([['desde', '>='], ['hasta', '<=']] as [$campo, $op]) {
                if (!$request->filled($campo)) continue;
                $valor = $request->input($campo);
                $query->where(function ($w) use ($valor, $op) {
                    $w->whereDate('FECHA_ENVIO', $op, $valor)
                      ->orWhere(fn ($o) => $o->whereNull('FECHA_ENVIO')->whereDate('created_at', $op, $valor));
                });
            }
            return $query;
        };
        $filtrosBandeja($q);

        // ── Filtros que acotan SOLO la tabla ──
        // Ventana temporal del KPI: MISMO criterio (datetime) que usa $bandejaStats, así al
        // tocar la métrica se ven EXACTAMENTE esas notas. Solo la tienen estas dos:
        // 'por_revisar' son TODAS las pendientes, sin recorte por fecha.
        if ($kpi === 'recientes' || $kpi === 'urgentes') {
            $q->whereRaw(
                'COALESCE(FECHA_ENVIO, created_at) ' . ($kpi === 'recientes' ? '>=' : '<=') . ' ?',
                [$kpi === 'recientes' ? now()->subDay() : now()->subDays(3)]
            );
        }
        if ($request->filled('id_almacen_origen') && $request->input('id_almacen_origen') !== 'all') {
            $q->where('ID_ALMACEN_ORIGEN', $request->integer('id_almacen_origen'));
        }
        if ($request->filled('search')) {
            $s = '%' . trim((string) $request->input('search')) . '%';
            $q->where(fn ($w) => $w->where('NUMERO', 'like', $s)->orWhere('REFERENCIA', 'like', $s));
        }

        // Orden: de la MÁS RECIENTE a la más vieja por la fecha que la tabla muestra en la
        // columna "Enviado" (COALESCE con created_at para los borradores, que aún no tienen
        // FECHA_ENVIO). ID_TRASPASO desempata: sin él, dos notas del mismo instante saldrían
        // en orden indefinido y la lista bailaría entre recargas.
        // Antes se ordenaba SOLO por ID_TRASPASO: coincide casi siempre con la antigüedad,
        // pero no cuando se envía un borrador viejo o se elige una fecha de envío retroactiva
        // — ahí la fila aparecía arriba con una fecha antigua.
        $paginator = $q->orderByDesc(DB::raw('COALESCE(FECHA_ENVIO, created_at)'))
            ->orderByDesc('ID_TRASPASO')
            ->paginate(30)
            ->withQueryString();

        // Lista de NÚMEROS de nota que alimenta el autocomplete del filtro "Buscar por
        // número de nota" del toolbar. Sugiere EXACTAMENTE lo que la bandeja está mostrando:
        // mismos filtros que la tabla ($filtrosBandeja — estado, visibilidad, almacén destino,
        // producto y fechas). Antes fijaba ENVIADO a mano e ignoraba producto/fechas, así que
        // al filtrar por otro estado o por un producto seguía ofreciendo notas fuera de la
        // lista y elegirlas devolvía 0 resultados. Limitada a 300 para no inflar el HTML.
        //
        // Se calcula ANTES del wantsJson y se devuelve también en la respuesta AJAX: al
        // cambiar el "Almacén destino" (que recarga la tabla por AJAX) las sugerencias se
        // refrescan al nuevo almacén. Antes solo se generaba en el render inicial → quedaban
        // pegadas las notas del almacén anterior y sugería notas que no van a ese almacén.
        $numerosNotas = $filtrosBandeja(Traspaso::query())
            ->orderByDesc('ID_TRASPASO')
            ->take(300)
            ->get(['NUMERO', 'REFERENCIA'])
            // UN solo identificador por nota — el MISMO que muestra la tabla (neNumero =
            // REFERENCIA ?: NUMERO). Antes se devolvían los dos (NE-… y TR-…) con flatMap,
            // así que cada nota aparecía DUPLICADA en las sugerencias y parecía que listaba
            // "todas". El buscador del backend igual matchea NUMERO o REFERENCIA.
            ->map(fn ($t) => $t->REFERENCIA ?: $t->NUMERO)
            ->unique()
            ->values();

        // KPIs del panel lateral: resumen de notas POR REVISAR (ENVIADO) en los almacenes
        // visibles del usuario. Es un resumen ESTABLE de "tu bandeja" — NO depende de los
        // filtros de la tabla (estado/fechas/búsqueda), por eso se calcula aparte.
        //   · por_revisar = total pendientes de confirmar.
        //   · urgentes    = esperando > 3 días (mismo umbral del punto rojo de la tabla).
        //   · recientes   = llegadas en las últimas 24 h (punto verde).
        // COALESCE(FECHA_ENVIO, created_at): si no hubo envío explícito usamos la creación.
        // Las 3 claves DEBEN existir: la vista (panel "Resumen de la bandeja") las pinta como
        // héroe (por_revisar) + 2 sub-métricas (recientes / urgentes).
        // Se calcula ANTES del wantsJson (igual que $numerosNotas): sin esto, al confirmar
        // una recepción por AJAX (trModalConfirmar → trLoad) el panel y el badge del menú se
        // quedaban con el conteo de la carga inicial de la página — la nota ya no aparecía
        // en la tabla pero el "Por revisar" seguía contándola hasta recargar la página entera.
        $pendBase = Traspaso::where('ESTADO', Traspaso::ESTADO_ENVIADO)
            ->whereIn('ID_ALMACEN_DESTINO', $almacenesVisibles);
        $bandejaStats = [
            'por_revisar' => (clone $pendBase)->count(),
            'urgentes'    => (clone $pendBase)->whereRaw('COALESCE(FECHA_ENVIO, created_at) <= ?', [now()->subDays(3)])->count(),
            'recientes'   => (clone $pendBase)->whereRaw('COALESCE(FECHA_ENVIO, created_at) >= ?', [now()->subDay()])->count(),
        ];

        // Badge "[N] por recibir" del menú principal: MISMO criterio que el View Composer
        // global de AppServiceProvider (Almacen::asociadosIdsDe, no visiblesPara — la
        // recepción es personal). Se recalcula aquí y se manda en la respuesta AJAX para
        // que el menú se refresque sin recargar la página al confirmar/enviar/cancelar.
        $almacenesAsociados = Almacen::asociadosIdsDe($user);
        $traspasosPorRecibir = $almacenesAsociados->isNotEmpty()
            ? Traspaso::where('ESTADO', Traspaso::ESTADO_ENVIADO)
                ->whereIn('ID_ALMACEN_DESTINO', $almacenesAsociados)
                ->count()
            : 0;

        if ($request->wantsJson()) {
            return response()->json([
                'html'                => view('admin.almacen.recepcion.partials.rows', ['traspasos' => $paginator])->render(),
                'pagination'          => (string) $paginator->links('vendor.pagination.custom-sliding'),
                'total'               => $paginator->total(),
                'numerosNotas'        => $numerosNotas,
                'bandejaStats'        => $bandejaStats,
                'traspasosPorRecibir' => $traspasosPorRecibir,
            ]);
        }

        // idAlmacenDestinoActivo: pasamos el id resuelto (con default por frente aplicado) a
        // la vista para que el dropdown se preseleccione sin depender del helper global
        // request(), que puede no reflejar el merge en algunos entornos.
        $idAlmacenDestinoActivo = ($request->filled('id_almacen_destino') && $request->input('id_almacen_destino') !== 'all')
            ? (int) $request->input('id_almacen_destino')
            : null;

        return view('admin.almacen.recepcion.index', [
            'traspasos'              => $paginator,
            'almacenes'              => $almacenes,
            'idAlmacenDestinoActivo' => $idAlmacenDestinoActivo,
            'numerosNotas'           => $numerosNotas,
            'bandejaStats'           => $bandejaStats,
            // Catálogo para el buscador por PRODUCTO (con equivalencias), igual que inventario.
            'productosLista'         => ProductoInventario::listaAutocomplete(),
        ]);
    }

    /**
     * Pantalla "Registrar entrada directa" — reemplaza al viejo modal #entModal de
     * /admin/almacen/recepcion. Página dedicada con el mismo flujo: el usuario llena
     * la cabecera (Nº OC, proveedor, fecha) + las líneas (producto + cantidad con
     * autocomplete por código o descripción) y al submit el front POSTea a
     * almacen.movimientos.lote con tipo=ENTRADA — no hay backend nuevo aquí, solo
     * la pantalla del formulario. La pantalla es accesible sin permiso especial; el
     * gate almacen.movimiento se aplica al EJECUTAR el submit (registrarMovimientoLote).
     *
     * El "almacén destino" YA NO se elige: se deriva del frente asignado al usuario
     * via Usuario::almacenPorDefecto() — convención del módulo (mismo helper que usa
     * AlmacenController para el default-merge del filtro de almacén). Si el usuario
     * no tiene un almacén natural (caso GLOBAL sin frente, o frente sin almacén
     * PROYECTO), cae al primer almacén visible. Si NO hay ningún almacén visible se
     * redirige a la bandeja con un mensaje — esa situación bloquea la operación.
     */
    public function nuevaEntrada(Request $request)
    {
        // La pantalla es accesible sin permiso especial — abrir el formulario no
        // expulsa a nadie. El gate 'almacen.movimiento' se aplica al EJECUTAR la
        // operacion: el submit POST (registrarMovimientoLote) responde con un toast
        // claro que nombra la clave si el usuario no la tiene.
        $user      = $request->user();
        $almacenes = Almacen::visiblesPara($user)->orderBy('TIPO')->orderBy('NOMBRE')->get(['ID_ALMACEN', 'NOMBRE', 'TIPO']);

        // 1) Almacén-por-frente del usuario (helper canónico del módulo).
        // 2) Fallback: primer almacén visible si el helper devolvió null pero hay almacenes.
        // 3) Si no hay ninguno → redirigir al MENU (no a recepcion.index): el index()
        //    de la bandeja redirige a GLOBAL devuelta hacia nuevaEntrada, lo que
        //    formaba un loop infinito (`recepcion.index` ↔ `recepcion.nueva`) cuando
        //    la BD esta vacia o el usuario no ve ningun almacen. El menu rompe el
        //    ciclo y muestra el toast con la causa real.
        $idDest = $user?->almacenPorDefecto();
        $almacenDestino = $idDest ? $almacenes->firstWhere('ID_ALMACEN', (int) $idDest) : null;
        if (!$almacenDestino) {
            $almacenDestino = $almacenes->first();
        }
        if (!$almacenDestino) {
            return redirect()->route('menu')->with('flash_toast', [
                'type'    => 'error',
                'message' => 'No tienes un almacén destino asignado para registrar entradas. Avisa al administrador.',
            ]);
        }

        // Productos activos para el autocomplete del buscador (CODIGO/NOMBRE/UM + números de
        // parte de los FILTROS). Fuente ÚNICA compartida con /admin/almacen para que recepción
        // encuentre los filtros por su nº de parte igual que el inventario. CATEGORIA viaja para
        // que una presentacion nueva (cambiar la UM) herede la del original — ver entDoCreateProducto.
        // El catálogo (PRODUCTOS del buscador) ya NO se embebe aquí: la vista lo pide por AJAX
        // al endpoint compartido almacen.productos-autocomplete tras renderizar, para que la
        // recepción abra rápido (antes embebía los 1155 productos inline).

        // Unidades de medida DISTINTAS ya registradas en el catalogo — alimentan el
        // autocomplete del campo UM (mismo patron que el modal "Nuevo producto" de
        // /admin/almacen). Si el usuario quiere una UM nueva la escribe libremente.
        $unidadesMedida = ProductoInventario::activos()
            ->select('UM')->distinct()->orderBy('UM')->pluck('UM')->filter()->values();

        // Frente implicito del almacen destino: el PRIMER frente asociado (si tiene
        // alguno) — se manda en el payload de la entrada para que el kardex muestre
        // "Destino" con el nombre del frente en vez de "—". A diferencia del helper
        // `frenteImplicitoDelAlmacen` de AlmacenController (que solo retorna frente
        // si el almacen es PROYECTO con UN solo frente), aca somos permisivos: para
        // STOCK INICIAL / ENTRADA via Recepcion ODC siempre queremos atribuir destino.
        $almForFrente   = \App\Models\Almacen::with('frentes:ID_FRENTE')->find($almacenDestino->ID_ALMACEN);
        $idFrenteDestino = optional($almForFrente?->frentes->first())->ID_FRENTE;

        return view('admin.almacen.recepcion.nueva', [
            'almacenDestino'  => $almacenDestino,
            'unidadesMedida'  => $unidadesMedida,
            'idFrenteDestino' => $idFrenteDestino,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  Crear / editar borrador (origen)
    //  NOTA: no hay vista standalone GET /crear, y el frontend interno YA NO usa este
    //  endpoint. El botón único "Salida" de /admin/almacen envía siempre a
    //  AlmacenController::registrarMovimientoLote, que detecta cuando el frente destino
    //  tiene otro almacén y delega a TraspasoService directamente. `store()` queda como
    //  API pública (clientes externos / integraciones) y soporta `enviar_ahora=true`.
    // ─────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $data = $this->validarCabecera($request);

        // Resolución del almacén destino:
        //  - Si el cliente lo manda explícito (id_almacen_destino), se respeta.
        //  - Si solo se manda id_frente_destino (flujo nuevo desde /admin/almacen),
        //    se deduce el almacén PROYECTO asociado al frente vía pivot almacen_frentes.
        //    El usuario solo elige el frente y el sistema sabe a qué almacén llega.
        if (empty($data['id_almacen_destino'])) {
            $data['id_almacen_destino'] = $this->resolverAlmacenDestinoPorFrente(
                (int) $data['id_frente_destino'],
                (int) $data['id_almacen_origen'],
            );
        }

        $this->assertPuedeOperarOrigen($request, (int) $data['id_almacen_origen']);
        $this->assertPuedeOperarDestino($request, (int) $data['id_almacen_destino']);

        $lineas = $this->validarLineas($request);

        try {
            $traspaso = $this->traspasos->crearBorrador(
                $data + ['id_usuario' => $request->user()->ID_USUARIO],
                $lineas,
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Si el usuario hizo clic en "Enviar ahora" en lugar de "Guardar borrador", lo enviamos seguido.
        if ($request->boolean('enviar_ahora')) {
            try {
                $traspaso = $this->traspasos->enviar($traspaso, [
                    'id_usuario_envio' => $request->user()->ID_USUARIO,
                    'fecha_envio'      => now(),
                ]);
            } catch (Throwable $e) {
                return response()->json(['message' => 'Borrador guardado, pero falló al enviar: ' . $e->getMessage(), 'traspaso' => $traspaso], 422);
            }
        }

        return response()->json([
            'message'  => $traspaso->esEnviado() ? "Traspaso {$traspaso->NUMERO} enviado." : "Borrador {$traspaso->NUMERO} guardado.",
            'traspaso' => $traspaso->load('lineas.producto:ID_PRODUCTO,CODIGO,NOMBRE,UM'),
        ], 201);
    }

    /**
     * Vista de detalle. Sirve tanto para "ver" como para la pantalla de recepción
     * (la vista decide en función del estado y el rol del usuario).
     */
    public function show(Request $request, int $id)
    {
        // ESTATUS se incluye en el select de almacenDestino porque Almacen::visiblePara()
        // lo necesita; evita una segunda query al evaluar $puedeRecibir más abajo.
        $traspaso = Traspaso::with([
            'lineas.producto:ID_PRODUCTO,CODIGO,NOMBRE,UM',
            // Nºs de parte: alimentan el buscador de materiales del modal (data-buscar).
            // Sin este eager-load sería una query por línea.
            'lineas.producto.equivalencias:ID_PRODUCTO,NUMERO_PARTE',
            // ESTATUS también en el ORIGEN: lo necesita visiblePara() al calcular
            // $puedeCancelar (cancelar opera sobre el almacén que despachó).
            'almacenOrigen:ID_ALMACEN,NOMBRE,TIPO,ESTATUS',
            'almacenDestino:ID_ALMACEN,NOMBRE,TIPO,ESTATUS',
            'frenteDestino:ID_FRENTE,NOMBRE_FRENTE',
            'usuarioCreo:ID_USUARIO,NOMBRE_COMPLETO',
            'usuarioEnvio:ID_USUARIO,NOMBRE_COMPLETO',
            'usuarioRecepcion:ID_USUARIO,NOMBRE_COMPLETO',
        ])->findOrFail($id);

        $this->assertPuedeVerTraspaso($request, $traspaso);

        $user = $request->user();

        // Las 3 acciones del pie se deciden AQUÍ y se pasan a las dos vistas (el modal de la
        // bandeja y la página de detalle). Antes $puedeEnviar/$puedeCancelar se calculaban
        // dentro de cada Blade —la misma regla escrita dos veces— y encima $puedeCancelar no
        // coincidía con lo que exige cancelar(): el botón "Cancelar" se le pintaba a
        // cualquier receptor con almacen.movimiento y el POST le devolvía 403.
        $puedeRecibir = $traspaso->esEnviado()
            && $user?->can('almacen.movimiento')
            && $traspaso->almacenDestino?->visiblePara($user);

        $puedeEnviar = $traspaso->esBorrador() && $user?->can('almacen.movimiento');

        // MISMAS condiciones que cancelar(): nada terminal, permiso de movimiento, el almacén
        // ORIGEN visible (assertPuedeOperarOrigen) y, si la nota ya salió, super.admin.
        $puedeCancelar = !$traspaso->esFinal()
            && $user?->can('almacen.movimiento')
            && $traspaso->almacenOrigen?->visiblePara($user)
            && (!$traspaso->esEnviado() || $user->can('super.admin'));

        $datos = [
            'traspaso'      => $traspaso,
            'puedeRecibir'  => (bool) $puedeRecibir,
            'puedeEnviar'   => (bool) $puedeEnviar,
            'puedeCancelar' => (bool) $puedeCancelar,
        ];

        if ($request->wantsJson()) {
            return response()->json([
                'html' => view('admin.almacen.recepcion.partials.detalle_modal', $datos)->render(),
                'id'   => $traspaso->ID_TRASPASO,
            ]);
        }

        return view('admin.almacen.recepcion.detalle', $datos);
    }

    public function update(Request $request, int $id)
    {
        $traspaso = Traspaso::findOrFail($id);
        if (!$traspaso->esBorrador()) {
            return response()->json(['message' => 'Solo se pueden editar traspasos en BORRADOR.'], 422);
        }
        $this->assertPuedeOperarOrigen($request, (int) $traspaso->ID_ALMACEN_ORIGEN);

        $data = $this->validarCabecera($request, parcial: true);
        $lineas = $this->validarLineas($request);

        // El destino resultante debe seguir siendo válido contra el origen (que no cambia en
        // un PATCH). La regla `different:id_almacen_origen` de store() no sirve aquí porque el
        // origen no viaja en el payload parcial; sin esto se guardaba un borrador con
        // origen == destino que solo fallaba al enviarlo.
        $destinoResuelto = (int) ($data['id_almacen_destino'] ?? $traspaso->ID_ALMACEN_DESTINO);
        try {
            $this->traspasos->validarOrigenDestino((int) $traspaso->ID_ALMACEN_ORIGEN, $destinoResuelto);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        try {
            DB::transaction(function () use ($traspaso, $data, $lineas) {
                $traspaso->fill([
                    'ID_ALMACEN_DESTINO' => $data['id_almacen_destino'] ?? $traspaso->ID_ALMACEN_DESTINO,
                    'ID_FRENTE_DESTINO'  => $data['id_frente_destino']  ?? $traspaso->ID_FRENTE_DESTINO,
                    'REFERENCIA'         => $data['referencia']         ?? $traspaso->REFERENCIA,
                    'MOTIVO'             => $data['motivo']             ?? $traspaso->MOTIVO,
                    'NOTAS'              => $data['notas']              ?? $traspaso->NOTAS,
                ]);
                $traspaso->save();
                $this->traspasos->reemplazarLineas($traspaso, $lineas);
            });
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'  => "Borrador {$traspaso->NUMERO} actualizado.",
            'traspaso' => $traspaso->fresh('lineas.producto:ID_PRODUCTO,CODIGO,NOMBRE,UM'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  Transiciones de estado
    // ─────────────────────────────────────────────────────────────

    public function enviar(Request $request, int $id)
    {
        $traspaso = Traspaso::with('lineas')->findOrFail($id);
        $this->assertPuedeOperarOrigen($request, (int) $traspaso->ID_ALMACEN_ORIGEN);

        // Misma regla que `fecha_recepcion` en recibir(): ambas estampan un DATETIME vía
        // TraspasoService::resolverFechaHora. Sin validar, una fecha basura llegaba a
        // Carbon::parse y salía como un 422 con el mensaje crudo de la excepción.
        $request->validate(['fecha_envio' => 'nullable|date']);

        try {
            $traspaso = $this->traspasos->enviar($traspaso, [
                'id_usuario_envio'  => $request->user()->ID_USUARIO,
                'fecha_envio'       => $request->input('fecha_envio') ?: now(),
                'permitir_negativo' => $request->boolean('permitir_negativo') && $request->user()->can('super.admin'),
            ]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => "Traspaso {$traspaso->NUMERO} enviado.", 'traspaso' => $traspaso]);
    }

    public function recibir(Request $request, int $id)
    {
        $traspaso = Traspaso::with('lineas')->findOrFail($id);
        $this->assertPuedeOperarDestino($request, (int) $traspaso->ID_ALMACEN_DESTINO);

        $payload = $request->validate([
            'lineas'                      => 'required|array|min:1',
            'lineas.*.id_linea'           => 'required|integer',
            'lineas.*.cantidad_recibida'  => 'required|numeric|min:0',
            'lineas.*.estado'             => ['nullable', Rule::in([TraspasoLinea::ESTADO_DANADO])],
            'lineas.*.notas'              => 'nullable|string|max:1000',
            'fecha_recepcion'             => 'nullable|date',
        ]);

        try {
            $traspaso = $this->traspasos->recibir($traspaso, $payload['lineas'], [
                'id_usuario_recepcion' => $request->user()->ID_USUARIO,
                'fecha_recepcion'      => $payload['fecha_recepcion'] ?? now(),
            ]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $mensaje = $traspaso->ESTADO === Traspaso::ESTADO_RECIBIDO
            ? "Recepción confirmada — {$traspaso->NUMERO} llegó completo."
            : "Recepción confirmada — {$traspaso->NUMERO} tuvo diferencias (ver detalle).";
        return response()->json(['message' => $mensaje, 'traspaso' => $traspaso]);
    }

    public function cancelar(Request $request, int $id)
    {
        $traspaso = Traspaso::findOrFail($id);
        // Para cancelar un ya-enviado se requiere super.admin (puede revertir stock).
        if ($traspaso->esEnviado() && !$request->user()->can('super.admin')) {
            return response()->json(['message' => 'Solo un super-admin puede cancelar un traspaso ya enviado.'], 403);
        }
        $this->assertPuedeOperarOrigen($request, (int) $traspaso->ID_ALMACEN_ORIGEN);

        try {
            $traspaso = $this->traspasos->cancelar($traspaso, [
                'id_usuario' => $request->user()->ID_USUARIO,
                'notas'      => $request->input('notas'),
            ]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => "Traspaso {$traspaso->NUMERO} cancelado.", 'traspaso' => $traspaso]);
    }

    /**
     * Elimina un BORRADOR. Borrado DURO (forceDelete), igual que el resto de los "eliminar"
     * de la app (productos, equipos, auxiliares, fallas): lo que se borra en el módulo deja
     * de existir, no queda en una papelera invisible. Antes era `delete()` a secas — el
     * único soft delete de todo el flujo — así que el borrador desaparecía de la pantalla
     * pero seguía en la tabla ocupando su folio para siempre.
     *
     * Es seguro porque solo se llega aquí en BORRADOR: todavía no movió stock ni creó
     * movimientos de kardex (eso pasa al ENVIAR), así que no hay nada que auditar. Sus
     * líneas se van con él por la FK traspaso_lineas.ID_TRASPASO ON DELETE CASCADE.
     * Lo que YA movió mercancía no se borra: se cancela (ver cancelar(), que reversa el
     * stock y deja la nota como CANCELADA).
     */
    public function destroy(Request $request, int $id)
    {
        $traspaso = Traspaso::findOrFail($id);
        if (!$traspaso->esBorrador()) {
            return response()->json(['message' => 'Solo se eliminan traspasos en BORRADOR; los que ya tuvieron movimiento se cancelan.'], 422);
        }
        $this->assertPuedeOperarOrigen($request, (int) $traspaso->ID_ALMACEN_ORIGEN);
        $traspaso->forceDelete();
        return response()->json(['message' => 'Borrador eliminado.']);
    }

    // ─────────────────────────────────────────────────────────────
    //  Validación
    // ─────────────────────────────────────────────────────────────

    private function validarCabecera(Request $request, bool $parcial = false): array
    {
        // id_almacen_destino e id_frente_destino son nullable individualmente, pero al menos
        // UNO de los dos debe venir (required_without). El flujo nuevo desde el inventario
        // solo manda el frente y el controller deduce el almacén; el flujo legacy/admin
        // puede seguir mandando ambos explícitos.
        $reglas = [
            'id_almacen_origen'  => ['required','integer','exists:almacenes,ID_ALMACEN'],
            'id_almacen_destino' => ['nullable','required_without:id_frente_destino','integer','exists:almacenes,ID_ALMACEN','different:id_almacen_origen'],
            'id_frente_destino'  => ['nullable','required_without:id_almacen_destino','integer','exists:frentes_trabajo,ID_FRENTE'],
            'referencia'         => ['nullable','string','max:100'],
            'motivo'             => ['nullable','string','max:200'],
            'notas'              => ['nullable','string'],
        ];
        if ($parcial) {
            // En update, la cabecera (origen) no puede cambiar — solo destino/frente/notas.
            $reglas['id_almacen_origen']  = 'sometimes';
            $reglas['id_almacen_destino'] = ['sometimes','nullable','integer','exists:almacenes,ID_ALMACEN'];
            $reglas['id_frente_destino']  = ['sometimes','nullable','integer','exists:frentes_trabajo,ID_FRENTE'];
        }
        return $request->validate($reglas);
    }

    /**
     * Dado un frente destino, devuelve el ID del almacén que recibe la mercancía.
     *
     * Reglas:
     *  - Solo se consideran almacenes PROYECTO ACTIVOS asociados al frente vía
     *    el pivote `almacen_frentes`. Los almacenes GENERAL son surtidores, no
     *    receptores naturales de envíos a un frente.
     *  - El almacén origen se excluye (no tiene sentido enviarse a sí mismo).
     *  - Debe quedar exactamente UN candidato. Cero → el frente no tiene almacén
     *    asignado; más de uno → ambigüedad. Ambos casos lanzan 422 con mensaje claro.
     */
    private function resolverAlmacenDestinoPorFrente(int $idFrente, int $idAlmacenOrigen): int
    {
        $candidatos = Almacen::query()
            ->where('TIPO', Almacen::TIPO_PROYECTO)
            ->where('ESTATUS', 'ACTIVO')
            ->where('ID_ALMACEN', '!=', $idAlmacenOrigen)
            ->whereHas('frentes', fn ($q) => $q->where('frentes_trabajo.ID_FRENTE', $idFrente))
            ->pluck('ID_ALMACEN');

        abort_if($candidatos->isEmpty(), 422, 'El frente seleccionado no tiene un almacén asignado distinto del almacén de origen.');
        abort_if($candidatos->count() > 1, 422, 'El frente seleccionado tiene varios almacenes asignados; no se puede deducir el destino. Pide a un administrador que deje un único almacén PROYECTO por frente.');

        return (int) $candidatos->first();
    }

    private function validarLineas(Request $request): array
    {
        $request->validate([
            'lineas'               => 'required|array|min:1',
            'lineas.*.id_producto' => 'required|integer|exists:productos_inventario,ID_PRODUCTO',
            'lineas.*.cantidad'    => 'required|numeric|gt:0',
        ]);
        return collect($request->input('lineas'))
            ->map(fn ($l) => ['id_producto' => (int) $l['id_producto'], 'cantidad' => (float) $l['cantidad']])
            ->all();
    }

    private function assertPuedeOperarOrigen(Request $request, int $idAlmacen): void
    {
        Almacen::assertVisibleOrFail($request->user(), $idAlmacen, 'origen');
    }

    private function assertPuedeOperarDestino(Request $request, int $idAlmacen): void
    {
        Almacen::assertVisibleOrFail($request->user(), $idAlmacen, 'destino');
    }

    private function assertPuedeVerTraspaso(Request $request, Traspaso $traspaso): void
    {
        $user = $request->user();
        // GLOBAL sin frentes bloqueados ve todo (fast path). GLOBAL con bloqueos o LOCAL
        // pasan por visiblesPara, que ya excluye almacenes ligados solo a frentes bloqueados.
        if (Almacen::usuarioEsGlobal($user) && empty(Almacen::frentesBloqueadosDe($user))) {
            return;
        }
        $visibles = Almacen::visiblesPara($user)->pluck('ID_ALMACEN')->all();
        $ok = in_array((int) $traspaso->ID_ALMACEN_ORIGEN, $visibles, true)
            || in_array((int) $traspaso->ID_ALMACEN_DESTINO, $visibles, true);
        abort_unless($ok, 403, 'No tienes acceso a este traspaso.');
    }
}
