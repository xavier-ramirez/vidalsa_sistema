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
 * Permisos (re-usa los que ya tienes; el único nuevo es `traspaso.recibir`):
 *  - almacen.movimiento : crear borrador, editar borrador, enviar, cancelar antes de enviar.
 *  - traspaso.recibir   : confirmar recepción en el almacén destino.
 *  - super.admin / almacen.view.all : ven TODOS los traspasos del sistema.
 *  - Resto de usuarios: ven solo traspasos donde origen o destino son almacenes visibles para ellos.
 */
class TraspasoController extends Controller
{
    public function __construct(private TraspasoService $traspasos)
    {
        $this->middleware('can:almacen.movimiento')->only(['store', 'update', 'enviar', 'cancelar', 'destroy']);
        $this->middleware('can:traspaso.recibir')->only(['recibir']);
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
        $user             = $request->user();
        $almacenesVisibles = Almacen::visiblesPara($user)->pluck('ID_ALMACEN');

        // SIEMPRE: estado ENVIADO en almacenes destino visibles para el usuario.
        // (la columna ID_ALMACEN_DESTINO ya cubre la visibilidad de quien debe recibir).
        $q = Traspaso::query()
            ->with(['almacenOrigen:ID_ALMACEN,NOMBRE,TIPO', 'almacenDestino:ID_ALMACEN,NOMBRE,TIPO', 'usuarioCreo:ID_USUARIO,NOMBRE_COMPLETO'])
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

        // Datos extra para el modal "Registrar entrada directa" (alimenta su <select> de productos
        // y de almacenes destino — son los mismos que el usuario puede ver/operar).
        $productosLista = ProductoInventario::activos()->orderBy('NOMBRE')->get(['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM']);

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
        return view('admin.almacen.recepcion.index', [
            'traspasos'      => $paginator,
            'almacenes'      => Almacen::visiblesPara($user)->orderBy('TIPO')->orderBy('NOMBRE')->get(['ID_ALMACEN', 'NOMBRE', 'TIPO']),
            'productosLista' => $productosLista,
            'numerosNotas'   => $numerosNotas,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  Crear / editar borrador (origen)
    //  NOTA: no hay vista standalone GET /crear — el envío se inicia desde el botón
    //  "Enviar a otro almacén" del inventario (/admin/almacen), que llama directo a
    //  store() vía AJAX con enviar_ahora=true (crea pedido + lo envía en una transacción).
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
            && $request->user()?->can('traspaso.recibir')
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
