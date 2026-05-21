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
 * `NIVEL_ACCESO`). Los usuarios GLOBAL ven todos los traspasos; los LOCAL ven solo los
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
     * Bandeja de Recepción: SOLO envíos en tránsito que el usuario destino tiene
     * que confirmar (ESTADO=ENVIADO en sus almacenes visibles). El historial completo
     * de traspasos confirmados/cancelados se ve en "Historial de Movimientos" del nav
     * (TIPO=TRASPASO_ENTRADA / TRASPASO_SALIDA en el kardex).
     *
     * Filtros del UI: estado (raramente útil porque siempre será ENVIADO),
     *                 id_almacen_origen, id_almacen_destino, desde, hasta.
     *                 search          → busca por NUMERO de la nota de entrega (TR-2026-…)
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
            $nivel = (int) ($user?->NIVEL_ACCESO ?? 0);
            $msg   = $nivel === 2
                ? 'Tu frente no tiene un almacén registrado. Avisa al administrador para que asocie un almacén a tu frente.'
                : 'No hay almacenes registrados todavía. Crea uno desde el módulo de Almacén antes de usar Recepción.';
            return redirect()->route('menu')->with('flash_toast', [
                'type'    => 'error',
                'message' => $msg,
            ]);
        }

        // Enrutamiento por NIVEL_ACCESO: los GLOBAL (1) compran directo al proveedor
        // por Orden de Compra — su "Recepcion de Materiales" es el formulario de
        // entrada directa (almacen.recepcion.nueva), no la bandeja. Los LOCAL (2)
        // reciben traspasos del almacen GENERAL — su flujo es la bandeja. Si un
        // LOCAL quiere registrar una OC directa tiene el boton "Recepcion ODC" en
        // la misma bandeja.
        //
        // Solo redirigimos cuando NO es AJAX (los filtros/paginacion piden JSON a la
        // misma URL y deben quedarse aqui) y solo en la primera carga sin parametros
        // explicitos — si el GLOBAL navego a la bandeja a proposito (con filtros o
        // ?force=1) no interceptamos. El guard de arriba garantiza que llegamos aqui
        // con almacenes visibles, asi que nuevaEntrada no rebotara hacia atras.
        if (
            $user !== null
            && ! $request->wantsJson()
            && (int) ($user->NIVEL_ACCESO ?? 0) === 1
            && ! $request->boolean('force')
            && ! $request->hasAny(['search', 'estado', 'id_almacen_origen', 'id_almacen_destino', 'desde', 'hasta'])
        ) {
            return redirect()->route('almacen.recepcion.nueva');
        }

        // Default suave del filtro "Almacén destino" — TODOS los usuarios (LOCAL y GLOBAL)
        // abren con UN almacén preseleccionado. Nunca con "Todos" por default. El usuario
        // que quiere ver todos los almacenes destino lo elige explicito (X o "Todos" en el
        // dropdown del header).
        //   1) Si el cliente mando id_almacen_destino (filled), respetamos.
        //   2) Sino, intentamos el almacen ligado al frente (almacenPorDefecto).
        //   3) Fallback: el PRIMER almacen visible — cubre GLOBAL sin frente (super.admin)
        //      para que tambien arranque con UNO solo, no con "Todos".
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

        // SIEMPRE: estado ENVIADO en almacenes destino visibles para el usuario.
        // (la columna ID_ALMACEN_DESTINO ya cubre la visibilidad de quien debe recibir).
        $q = Traspaso::query()
            ->with(['almacenOrigen:ID_ALMACEN,NOMBRE,TIPO', 'almacenDestino:ID_ALMACEN,NOMBRE,TIPO'])
            ->withCount('lineas')
            ->where('ESTADO', Traspaso::ESTADO_ENVIADO)
            ->whereIn('ID_ALMACEN_DESTINO', $almacenesVisibles);

        // Filtros adicionales.
        if ($request->filled('estado') && $request->input('estado') !== 'all') {
            $q->where('ESTADO', $request->string('estado'));
        }
        if ($request->filled('id_almacen_origen') && $request->input('id_almacen_origen') !== 'all') {
            $q->where('ID_ALMACEN_ORIGEN', $request->integer('id_almacen_origen'));
        }
        if ($request->filled('id_almacen_destino') && $request->input('id_almacen_destino') !== 'all') {
            $q->where('ID_ALMACEN_DESTINO', $request->integer('id_almacen_destino'));
        }
        if ($request->filled('search')) {
            $q->where('NUMERO', 'like', '%' . trim((string) $request->input('search')) . '%');
        }
        // Filtro por descripcion/codigo de producto: busca traspasos cuyas LINEAS contengan
        // un producto que matchee. Util para que el usuario destino encuentre una nota
        // pendiente buscando "DEXTRAN", "CABLE 4AWG" o un codigo de producto, sin tener
        // que acordarse del numero TR-YYYY-NNNN. Tokenizado AND igual que /admin/almacen para
        // que "CABLE 4" matchee "CABLE 4AWG" sin importar el orden.
        if ($request->filled('search_producto')) {
            $term = trim((string) $request->input('search_producto'));
            // Tokeniza, descarta stopwords (de/la/y…) y números sueltos de 1-2
            // dígitos, AND entre tokens significativos y singular por token >3
            // letras terminado en 'S' — consistente con el filtro Descripción de
            // /admin/almacen. El fuzzy + ranking real vive en el autocomplete JS.
            $stop = ['de','del','la','el','los','las','un','una','unos','unas',
                     'y','e','o','u','a','en','con','para','por'];
            $tokens = array_values(array_filter(
                preg_split('/\s+/', mb_strtolower($term)),
                function ($t) use ($stop) {
                    return $t !== '' && !in_array($t, $stop, true) && !preg_match('/^\d{1,2}$/', $t);
                }
            ));
            if (empty($tokens)) {
                $tokens = [mb_strtolower($term)];
            }
            if (!empty($tokens)) {
                $q->whereHas('lineas.producto', function ($pq) use ($tokens) {
                    foreach ($tokens as $tok) {
                        $variantes = [$tok];
                        if (mb_strlen($tok) > 3 && mb_substr($tok, -1) === 's') {
                            $variantes[] = mb_substr($tok, 0, -1);
                        }
                        $pq->where(function ($s) use ($variantes) {
                            foreach ($variantes as $v) {
                                $s->orWhere('productos_inventario.CODIGO', 'like', "%{$v}%")
                                  ->orWhere('productos_inventario.NOMBRE', 'like', "%{$v}%");
                            }
                        });
                    }
                });
            }
        }

        if ($request->filled('desde')) {
            $q->whereDate('FECHA_ENVIO', '>=', $request->input('desde'))
              ->orWhere(function ($o) use ($request) { $o->whereNull('FECHA_ENVIO')->whereDate('created_at', '>=', $request->input('desde')); });
        }
        if ($request->filled('hasta')) {
            $q->where(function ($w) use ($request) {
                $w->whereDate('FECHA_ENVIO', '<=', $request->input('hasta'))
                  ->orWhere(function ($o) use ($request) { $o->whereNull('FECHA_ENVIO')->whereDate('created_at', '<=', $request->input('hasta')); });
            });
        }

        $paginator = $q->orderByDesc('ID_TRASPASO')->paginate(30)->withQueryString();

        if ($request->wantsJson()) {
            return response()->json([
                'html'       => view('admin.almacen.recepcion.partials.rows', ['traspasos' => $paginator])->render(),
                'pagination' => (string) $paginator->links('vendor.pagination.custom-sliding'),
                'total'      => $paginator->total(),
            ]);
        }

        // Lista de NÚMEROS de nota visibles para el usuario — alimenta el autocomplete
        // del filtro "Buscar por número de nota" del toolbar. Limitada a los 300 más
        // recientes para no inflar el HTML (lo típico será 30-50 en circulación).
        $numerosNotas = Traspaso::query()
            ->where(function ($w) use ($almacenesVisibles) {
                $w->whereIn('ID_ALMACEN_ORIGEN', $almacenesVisibles)
                  ->orWhereIn('ID_ALMACEN_DESTINO', $almacenesVisibles);
            })
            ->orderByDesc('ID_TRASPASO')
            ->take(300)
            ->pluck('NUMERO');

        // NOTA: el badge "[N] por recibir" del menú principal lo provee el View Composer
        // global registrado en AppServiceProvider (en `layouts.estructura_base`), no esta
        // vista — por eso aquí no se calcula ningún contador adicional.
        // idAlmacenDestinoActivo: pasamos el id resuelto (con default por frente aplicado) a
        // la vista para que el dropdown se preseleccione sin depender del helper global
        // request(), que puede no reflejar el merge en algunos entornos.
        $idAlmacenDestinoActivo = ($request->filled('id_almacen_destino') && $request->input('id_almacen_destino') !== 'all')
            ? (int) $request->input('id_almacen_destino')
            : null;
        // Catalogo de productos para alimentar el autocomplete del filtro
        // "Buscar producto" (busca en las lineas de los traspasos pendientes).
        // Solo CODIGO/NOMBRE — no necesitamos UM ni CATEGORIA en el dropdown.
        $productosLista = ProductoInventario::activos()
            ->orderBy('NOMBRE')
            ->get(['ID_PRODUCTO', 'CODIGO', 'NOMBRE']);

        return view('admin.almacen.recepcion.index', [
            'traspasos'              => $paginator,
            'almacenes'              => $almacenes,
            'idAlmacenDestinoActivo' => $idAlmacenDestinoActivo,
            'numerosNotas'           => $numerosNotas,
            'productosLista'         => $productosLista,
        ]);
    }

    /**
     * Pantalla "Registrar entrada directa" — reemplaza al viejo modal #entModal de
     * /admin/almacen/recepcion. Página dedicada con el mismo flujo: el usuario llena
     * la cabecera (Nº OC, proveedor, fecha) + las líneas (producto + cantidad con
     * autocomplete por código o descripción) y al submit el front POSTea a
     * almacen.movimientos.lote con tipo=ENTRADA — no hay backend nuevo aquí, solo
     * la pantalla del formulario. Gateada por can:almacen.movimiento en la ruta.
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
        $user = $request->user();

        // Permiso: registrar entradas directas exige la clave 'almacen.movimiento'
        // (la MISMA que valida el submit POST almacen.movimientos-lote). Si el usuario
        // no la tiene, lo notificamos con un toast claro y lo devolvemos al menu — antes
        // un route middleware `can:almacen.movimiento` tiraba un 403 crudo sin explicar
        // que le falta la clave. Mismo patron que la rama "sin almacen destino" de abajo.
        if (! $user?->can('almacen.movimiento')) {
            return redirect()->route('menu')->with('flash_toast', [
                'type'    => 'error',
                'message' => 'No tienes la clave de permiso «almacen.movimiento», necesaria para registrar entradas. Solicítala a un administrador.',
            ]);
        }

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

        // Productos activos con CODIGO/NOMBRE/UM: alimentan el autocomplete del
        // cliente (sin endpoint AJAX adicional — la lista cabe holgadamente en el
        // HTML inicial). El autocomplete matchea por CODIGO o NOMBRE, no necesita
        // CATEGORIA ni UBICACION.
        $productosLista = ProductoInventario::activos()->orderBy('NOMBRE')->get(['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM']);

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
            'productosLista'  => $productosLista,
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
            'almacenOrigen:ID_ALMACEN,NOMBRE,TIPO',
            'almacenDestino:ID_ALMACEN,NOMBRE,TIPO,ESTATUS',
            'frenteDestino:ID_FRENTE,NOMBRE_FRENTE',
            'usuarioCreo:ID_USUARIO,NOMBRE_COMPLETO',
            'usuarioEnvio:ID_USUARIO,NOMBRE_COMPLETO',
            'usuarioRecepcion:ID_USUARIO,NOMBRE_COMPLETO',
        ])->findOrFail($id);

        $this->assertPuedeVerTraspaso($request, $traspaso);

        $puedeRecibir = $traspaso->esEnviado()
            && $request->user()?->can('almacen.movimiento')
            && $traspaso->almacenDestino?->visiblePara($request->user());

        return view('admin.almacen.recepcion.detalle', [
            'traspaso'     => $traspaso,
            'puedeRecibir' => (bool) $puedeRecibir,
        ]);
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

    public function destroy(Request $request, int $id)
    {
        $traspaso = Traspaso::findOrFail($id);
        if (!$traspaso->esBorrador()) {
            return response()->json(['message' => 'Solo se eliminan traspasos en BORRADOR; los que ya tuvieron movimiento se cancelan.'], 422);
        }
        $this->assertPuedeOperarOrigen($request, (int) $traspaso->ID_ALMACEN_ORIGEN);
        $traspaso->delete(); // soft delete
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
        if (Almacen::usuarioEsGlobal($request->user())) {
            return;
        }
        $visibles = Almacen::visiblesPara($request->user())->pluck('ID_ALMACEN')->all();
        $ok = in_array((int) $traspaso->ID_ALMACEN_ORIGEN, $visibles, true)
            || in_array((int) $traspaso->ID_ALMACEN_DESTINO, $visibles, true);
        abort_unless($ok, 403, 'No tienes acceso a este traspaso.');
    }
}
