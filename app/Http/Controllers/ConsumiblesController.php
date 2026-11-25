<?php

namespace App\Http\Controllers;

use App\Models\Consumible;
use App\Models\ConsumibleImportacion;
use App\Models\FrenteTrabajo;
use App\Services\Consumibles\IdentificadorConsumo;
use App\Services\Consumibles\ImportadorCombustible;
use App\Services\Consumibles\MatchConsumibles;
use Illuminate\Http\Request;
use App\Support\CacheVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Support\MensajeError;

class ConsumiblesController extends Controller
{
    /**
     * Versión de los datos de consumibles. Viaja DENTRO de la clave del caché de
     * gráficos, así que subirla invalida de golpe todas las combinaciones de filtros.
     *
     * Va por CacheVersion y no por Cache::increment() suelto: increment() sobre una
     * clave que NO existe devuelve false y no la crea (comprobado con el driver
     * `database` de este proyecto). Como nadie creaba esta clave, la versión se quedaba
     * en el valor por defecto PARA SIEMPRE y los gráficos seguían mostrando datos viejos
     * hasta que vencía su TTL de 30 minutos — justo lo contrario de lo que decía el
     * comentario que había aquí.
     */
    public const DATA_VER_KEY = 'consumibles_data_version';

    use \App\Traits\ExcelLogoCorporativo;

    // ══════════════════════════════════════════════════════════════
    // INDEX — Lista + pendientes
    // ══════════════════════════════════════════════════════════════
    public function index(Request $request)
    {
        // La lista abre en Gasoil y siempre muestra un tipo (no hay «Todos los tipos»).
        $tipo = $request->input('tipo');
        if (!is_string($tipo) || !array_key_exists($tipo, Consumible::tiposLabel())) {
            $request->merge(['tipo' => 'GASOIL']);
        }
        // Filtros de la URL mal formados (?estado=FOO, ?identificador[]=x) se ignoran: la vista
        // los pinta tal cual y con un arreglo o un estado desconocido daba error 500.
        foreach (['id_frente', 'identificador', 'fecha_desde', 'fecha_hasta'] as $campo) {
            if ($request->has($campo) && !is_string($request->input($campo))) {
                $request->merge([$campo => null]);
            }
        }
        if ($request->has('estado') && !self::estadoValido($request->input('estado'))) {
            $request->merge(['estado' => null]);
        }

        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->orderBy('NOMBRE_FRENTE')->get();

        // Sin 'suministro': la tabla suministros_origen esta VACIA y ningun consumible
        // tiene ID_SUMINISTRO (el registro de consumible lo escribe siempre null), asi que
        // era una consulta mas en cada carga para traer nada. Ninguna vista la lee.
        $query = Consumible::with(['equipo.frenteActual', 'equipo.tipo', 'equipo.documentacion', 'auxiliar', 'frente'])
            ->orderBy('FECHA', 'desc')
            ->orderBy('ID_CONSUMIBLE', 'desc');

        // Los mismos filtros para la lista y los contadores (estos sin el de estado: cada uno
        // cuenta el suyo, y al pulsarlo la lista muestra justo esos).
        $filtrar = function ($q, bool $conEstado) use ($request) {
            $q->where('consumibles.TIPO_CONSUMIBLE', $request->tipo);
            if ($request->filled('id_frente')) {
                $q->where('consumibles.ID_FRENTE', $request->id_frente);
            }
            if ($conEstado && self::estadoValido($request->input('estado'))) {
                $q->where('consumibles.ESTADO_EQUIPO', $request->input('estado'));
            }
            if ($request->filled('fecha_desde')) {
                $q->where('consumibles.FECHA', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $q->where('consumibles.FECHA', '<=', $request->fecha_hasta);
            }
            $texto = $request->input('identificador');
            if (is_string($texto) && trim($texto) !== '') {
                self::buscarIdentificador($q, $texto);
            }
        };
        $filtrar($query, true);

        $consumibles = (clone $query)->paginate(50)->onEachSide(3)->withQueryString();
        // Pasada la última página (se borró lo último que había en ella): la última que queda.
        if ($consumibles->isEmpty() && $consumibles->currentPage() > 1) {
            $consumibles = $query->paginate(50, ['*'], 'page', $consumibles->lastPage())->onEachSide(3)->withQueryString();
        }

        // ── Contadores de estado — 1 sola query en lugar de 3 ──────────────────
        $conteos = tap(DB::table('consumibles'), fn ($q) => $filtrar($q, false))
            ->selectRaw("
                SUM(CASE WHEN ESTADO_EQUIPO = 'PENDIENTE'  THEN 1 ELSE 0 END) as pendientes,
                SUM(CASE WHEN ESTADO_EQUIPO = 'SIN_MATCH'  THEN 1 ELSE 0 END) as sinMatch,
                SUM(CASE WHEN ESTADO_EQUIPO = 'CONFIRMADO' THEN 1 ELSE 0 END) as confirmados
            ")->first();
        $pendientes = (int) ($conteos->pendientes ?? 0);
        $sinMatch = (int) ($conteos->sinMatch ?? 0);
        $confirmados = (int) ($conteos->confirmados ?? 0);

        return view('admin.consumibles.index', compact(
            'consumibles',
            'frentes',
            'pendientes',
            'sinMatch',
            'confirmados'
        ));
    }

    // ══════════════════════════════════════════════════════════════
    // CARGAR CONSUMIBLES — la pantalla (Excel de gasoil arriba, tabla a mano debajo)
    // ══════════════════════════════════════════════════════════════
    public function cargar()
    {
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->orderBy('NOMBRE_FRENTE')->get();

        $tipos = Consumible::tiposLabel();
        $unidades = ['LITROS' => 'Litros', 'GALONES' => 'Galones', 'UNIDADES' => 'Unidades', 'KG' => 'Kg'];
        // Las cargas de Excel de gasoil: solo las ve (y las usa) super.admin.
        $cargas = auth()->user()->can('super.admin')
            ? ConsumibleImportacion::with('usuario:ID_USUARIO,NOMBRE_COMPLETO')
                ->orderByDesc('ID_IMPORTACION')->limit(30)->get()
            : collect();

        return view('admin.consumibles.cargar', compact('frentes', 'tipos', 'unidades', 'cargas'));
    }

    /**
     * POST consumibles/frentes-de-textos — el frente de cada texto de proyecto de la tabla de
     * Cargar consumibles (lo pegado trae uno por fila), con el reconocedor del Excel de gasoil
     * (IdentificadorConsumo::resolverFrente). {texto: {id, nombre} | null}.
     */
    public function frentesDeTextos(Request $request)
    {
        $request->validate(['textos' => 'required|array|max:500', 'textos.*' => 'nullable|string|max:191']);
        $ident = new IdentificadorConsumo();
        $r = [];
        foreach (array_unique(array_filter(array_map('trim', $request->input('textos')))) as $t) {
            $id = $ident->resolverFrente(['proyecto' => $t])['id_frente'];
            $r[$t] = $id !== null ? ['id' => $id, 'nombre' => $ident->nombreFrente($id)] : null;
        }

        return response()->json(['frentes' => (object) $r]);
    }

    // ══════════════════════════════════════════════════════════════
    // GUARDAR LOTE
    // ══════════════════════════════════════════════════════════════
    public function guardarLote(Request $request)
    {
        // Permiso 'super.admin' validado en el middleware de la ruta
        // (routes/web.php: middleware('can:super.admin') en consumibles.guardarLote).

        // ── 1. Filtrar filas vacías ANTES de validar ──────────────
        $filasFiltradas = collect($request->input('filas', []))
            ->filter(fn($f) => !empty($f['fecha']) && !empty($f['cantidad']))
            ->values()
            ->all();

        if (empty($filasFiltradas)) {
            $msg = 'No se enviaron filas con fecha y cantidad válidas.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $msg, 'errors' => ['error' => [$msg]]], 422);
            }
            return back()->withErrors(['error' => $msg])->withInput();
        }

        $request->merge(['filas' => $filasFiltradas]);

        // ── 2. Validar solo filas con datos ──────────────────────
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'tipo_consumible' => 'required|in:GASOIL,GASOLINA,ACEITE,CAUCHO',
            'unidad' => 'required|in:LITROS,GALONES,UNIDADES,KG',
            // El de arriba vale para las filas sin proyecto propio; cada fila puede traer el suyo.
            'id_frente' => 'nullable|exists:frentes_trabajo,ID_FRENTE,deleted_at,NULL',
            'filas' => 'required|array|min:1',
            'filas.*.fecha' => 'required|date',
            'filas.*.frente' => 'nullable|string|max:191',
            'filas.*.id_frente' => 'nullable|integer|exists:frentes_trabajo,ID_FRENTE,deleted_at,NULL',
            'filas.*.cantidad' => 'required|numeric|min:0.01',
            'filas.*.especificacion' => 'nullable|string|max:30',
        ]);

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Error de validación.',
                    'errors' => $validator->errors(),
                ], 422);
            }
            return back()->withErrors($validator)->withInput();
        }

        $tipo = $request->tipo_consumible;
        $unidad = $request->unidad;

        // ── 3. Frente de cada fila ───────────────────────────────
        // El elegido en la fila; si no, el que se reconoce de su texto de proyecto (las mismas
        // reglas que el Excel de gasoil: nombre mal escrito, reglas fijas, lo ya enseñado); sin
        // texto, el de arriba. Un texto que no se reconoce y no tiene frente elegido NO toma el
        // de arriba: sería cargar ese consumo en otro proyecto.
        $ident = null;
        $frentes = $sinFrente = $ensenar = [];
        foreach ($request->filas as $i => $fila) {
            $texto = trim((string) ($fila['frente'] ?? ''));
            $elegido = !empty($fila['id_frente']) ? (int) $fila['id_frente'] : null;
            $reconocido = ['id_frente' => null, 'clave' => ''];
            if ($texto !== '') {
                $ident ??= new IdentificadorConsumo();
                $reconocido = $ident->resolverFrente(['proyecto' => $texto]);
            }
            $frentes[$i] = $elegido ?? $reconocido['id_frente'] ?? ($texto === '' && $request->id_frente ? (int) $request->id_frente : null);
            if ($frentes[$i] === null) {
                $sinFrente[$texto] = true;
            }
            // Elegido a mano para un texto que no se reconocía (o se reconocía como otro): queda
            // aprendido para la tabla y el Excel, como «Textos sin proyecto» en la revisión.
            if ($elegido !== null && $texto !== '' && $reconocido['id_frente'] !== $elegido) {
                $ensenar[$reconocido['clave'] !== '' ? $reconocido['clave'] : $texto] = $elegido;
            }
        }
        if ($sinFrente) {
            $sinTexto = isset($sinFrente['']);
            unset($sinFrente['']);
            $partes = [];
            if ($sinFrente) {
                $partes[] = 'No se reconoce el proyecto ' . implode(', ', array_map(fn ($t) => '«' . $t . '»', array_keys($sinFrente))) . ': elige su frente en la fila.';
            }
            if ($sinTexto) {
                $partes[] = 'Hay filas sin proyecto: elige el frente de arriba o escríbelo en la fila.';
            }
            $msg = implode(' ', $partes);
            if ($request->expectsJson()) {
                return response()->json(['message' => $msg, 'errors' => ['id_frente' => [$msg]]], 422);
            }
            return back()->withErrors(['id_frente' => $msg])->withInput();
        }

        $tiposConEspec = ['ACEITE', 'CAUCHO'];
        $nuevos = [];

        DB::beginTransaction();
        try {
            foreach ($request->filas as $i => $fila) {
                $especFila = null;
                if (in_array($tipo, $tiposConEspec)) {
                    $raw = trim($fila['especificacion'] ?? '');
                    $especFila = $raw !== '' ? $raw : null;
                }

                $nuevos[] = Consumible::create([
                    'FECHA' => $fila['fecha'],
                    'IDENTIFICADOR' => isset($fila['identificador']) ? trim($fila['identificador']) : null,
                    'RESP_NOMBRE' => isset($fila['resp_nombre']) ? trim($fila['resp_nombre']) : null,
                    'RESP_CI' => isset($fila['resp_ci']) ? trim($fila['resp_ci']) : null,
                    'CANTIDAD' => $fila['cantidad'],
                    'TIPO_CONSUMIBLE' => $tipo,
                    'ESPECIFICACION' => $especFila,
                    'UNIDAD' => $unidad,
                    'ID_FRENTE' => $frentes[$i],
                    'ID_EQUIPO' => null,
                    'ID_SUMINISTRO' => null,
                    'ESTADO_EQUIPO' => 'PENDIENTE',
                ])->ID_CONSUMIBLE;
            }
            if ($ensenar) {
                (new ImportadorCombustible())->ensenar($ensenar, [], auth()->id());
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $errMsg = 'Error al guardar: ' . MensajeError::para($e, MensajeError::REINTENTAR);
            if ($request->expectsJson()) {
                return response()->json(['message' => $errMsg], 500);
            }
            return back()->withErrors(['error' => $errMsg])->withInput();
        }

        // El equipo de cada fila lo identifica el servidor ya, sin botón.
        (new MatchConsumibles())->ejecutar($nuevos);
        CacheVersion::bump(self::DATA_VER_KEY);

        $insertados = count($nuevos);
        $mensaje = "$insertados registros cargados exitosamente.";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $mensaje,
                'insertados' => $insertados,
            ]);
        }

        return redirect()->route('consumibles.index')->with('success', $mensaje);
    }


    // ══════════════════════════════════════════════════════════════
    // ACTUALIZAR IDENTIFICADOR — y se vuelve a identificar el equipo
    // ══════════════════════════════════════════════════════════════
    public function updateIdentificador(Request $request, int $id)
    {
        $consumible = Consumible::findOrFail($id);

        $request->validate([
            'identificador' => 'required|string|max:100',
        ]);

        $consumible->update([
            'IDENTIFICADOR' => strtoupper(trim($request->identificador)),
            'ESTADO_EQUIPO' => 'PENDIENTE',   // se vuelve a identificar ya, con el texto nuevo
            'ID_EQUIPO' => null,
            'ID_AUXILIAR' => null,
        ]);
        // ejecutar() ya invalida la caché de la lista y los gráficos.
        (new MatchConsumibles())->ejecutar([$consumible->ID_CONSUMIBLE]);

        return response()->json(['ok' => true, 'identificador' => $consumible->IDENTIFICADOR]);
    }

    // ══════════════════════════════════════════════════════════════
    // ACTUALIZAR FRENTE — Cambia el frente de trabajo del registro
    // ══════════════════════════════════════════════════════════════
    public function updateFrente(Request $request, int $id)
    {
        $consumible = Consumible::findOrFail($id);

        $request->validate([
            'id_frente' => 'required|exists:frentes_trabajo,ID_FRENTE,deleted_at,NULL',
        ]);

        $consumible->update(['ID_FRENTE' => $request->id_frente]);

        // Invalidar caché de gráficos
        CacheVersion::bump(self::DATA_VER_KEY);

        $nombreFrente = FrenteTrabajo::find($request->id_frente)?->NOMBRE_FRENTE ?? '—';

        return response()->json(['ok' => true, 'nombre_frente' => $nombreFrente]);
    }

    // ══════════════════════════════════════════════════════════════
    // DESTROY — Eliminar un registro (AJAX → JSON, form → redirect)
    // ══════════════════════════════════════════════════════════════
    public function destroy(Request $request, int $id)
    {
        // Pide equipos.edit, como editar (la ruta); ya no super.admin, a pedido del usuario.
        Consumible::findOrFail($id)->delete();

        // Invalidar caché de gráficos
        CacheVersion::bump(self::DATA_VER_KEY);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Registro eliminado.');
    }

    // ══════════════════════════════════════════════════════════════
    // GRÁFICOS — Vista
    // ══════════════════════════════════════════════════════════════
    public function graficos()
    {
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->orderBy('NOMBRE_FRENTE')->get();

        return view('admin.consumibles.graficos', compact('frentes'));
    }

    // ══════════════════════════════════════════════════════════════
    // API — Datos para gráficos (JSON)
    // ══════════════════════════════════════════════════════════════
    public function graficosData(Request $request)
    {
        set_time_limit(120); // queries pesadas — evita timeout de 60s

        $desde = $request->get('desde');
        $hasta = $request->get('hasta');
        $idFrente = $request->get('id_frente');
        $tipo = $request->get('tipo');

        // ── Clave de caché única por combinación de filtros + versión de datos ──
        // La versión sube cada vez que se escriben datos → invalida todo el caché anterior.
        $version = CacheVersion::current(self::DATA_VER_KEY);
        // "f2": la forma de la respuesta cambió (los inoperativos traen FOTO ya resuelta);
        // un valor guardado con la forma vieja no debe servirse.
        $cacheKey = 'graficos_f2_v' . $version
            . '_d' . ($desde ?? 'null')
            . '_h' . ($hasta ?? 'null')
            . '_f' . ($idFrente ?? 'all')
            . '_t' . ($tipo ?? 'all');

        $resultado = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($desde, $hasta, $idFrente, $tipo) {

            // ── Filtros base — SIN filtrar por estado (incluye SIN_MATCH y PENDIENTE) ──
            $filtrosTodos = function ($q) use ($desde, $hasta, $idFrente, $tipo) {
                if ($desde)
                    $q->where('consumibles.FECHA', '>=', $desde);
                if ($hasta)
                    $q->where('consumibles.FECHA', '<=', $hasta);
                if ($idFrente)
                    $q->where('consumibles.ID_FRENTE', $idFrente);
                if ($tipo)
                    $q->where('consumibles.TIPO_CONSUMIBLE', $tipo);
            };

            // ── Filtros para queries que requieren equipo identificado ────────────────
            $filtrosConfirmados = function ($q) use ($filtrosTodos) {
                $filtrosTodos($q);
                $q->where('consumibles.ESTADO_EQUIPO', 'CONFIRMADO');
            };

            // ── 1. Total por frente — TODOS los registros ─────────────────────
            $porFrente = DB::table('consumibles')
                ->join('frentes_trabajo', 'frentes_trabajo.ID_FRENTE', '=', 'consumibles.ID_FRENTE')
                ->where(function ($q) use ($filtrosTodos) {
                    $filtrosTodos($q);
                })
                ->select(
                    'frentes_trabajo.NOMBRE_FRENTE',
                    DB::raw('SUM(consumibles.CANTIDAD) as total'),
                    DB::raw('COUNT(*) as despachos'),
                    DB::raw('MAX(consumibles.UNIDAD) as unidad'),
                    DB::raw('COUNT(DISTINCT consumibles.ID_EQUIPO) as equipos_distintos')
                )
                ->groupBy('frentes_trabajo.NOMBRE_FRENTE')
                ->orderByDesc('total')
                ->get();

            // ── 2. Top 15 equipos mayor consumo — solo CONFIRMADOS ───────────────────
            $topEquipos = DB::table('consumibles')
                ->join('equipos', 'equipos.ID_EQUIPO', '=', 'consumibles.ID_EQUIPO')
                ->leftJoin('tipo_equipos', 'tipo_equipos.id', '=', 'equipos.id_tipo_equipo')
                ->leftJoin('documentacion', 'documentacion.ID_EQUIPO', '=', 'equipos.ID_EQUIPO')
                ->leftJoin('frentes_trabajo', 'frentes_trabajo.ID_FRENTE', '=', 'equipos.ID_FRENTE_ACTUAL')
                ->where(function ($q) use ($filtrosConfirmados) {
                    $filtrosConfirmados($q);
                })
                ->whereNotNull('consumibles.ID_EQUIPO')
                ->select(
                    'equipos.ID_EQUIPO',
                    'equipos.CODIGO_PATIO',
                    'equipos.MARCA',
                    'equipos.MODELO',
                    'equipos.SERIAL_CHASIS',
                    DB::raw("MAX(documentacion.PLACA) as PLACA"),
                    DB::raw("COALESCE(tipo_equipos.nombre, 'S/T') as tipo"),
                    DB::raw("MAX(frentes_trabajo.NOMBRE_FRENTE) as frente"),
                    'consumibles.TIPO_CONSUMIBLE',
                    DB::raw('SUM(consumibles.CANTIDAD) as total'),
                    DB::raw('COUNT(*) as despachos'),
                    DB::raw('MAX(consumibles.UNIDAD) as unidad')
                )
                ->groupBy(
                    'equipos.ID_EQUIPO',
                    'equipos.CODIGO_PATIO',
                    'equipos.MARCA',
                    'equipos.MODELO',
                    'equipos.SERIAL_CHASIS',
                    'tipo_equipos.nombre',
                    'consumibles.TIPO_CONSUMIBLE'
                )
                ->orderBy('total', 'desc')
                ->limit(20)
                ->get();

            // ── 3. Resumen general (tarjetas) — TODOS los registros ──────────────────
            $resumen = DB::table('consumibles')
                ->where(function ($q) use ($filtrosTodos) {
                    $filtrosTodos($q);
                })
                ->select(
                    'TIPO_CONSUMIBLE',
                    DB::raw('SUM(CANTIDAD) as total'),
                    DB::raw('COUNT(*) as registros'),
                    DB::raw('COUNT(DISTINCT ID_EQUIPO) as equipos_distintos'),
                    DB::raw('COUNT(DISTINCT ID_FRENTE) as frentes_tipo'), // frentes con ESTE tipo
                    DB::raw('MAX(UNIDAD) as unidad')
                )
                ->groupBy('TIPO_CONSUMIBLE')
                ->get();

            // ── 6. Equipos individuales × frente — solo CONFIRMADOS ──────────────────
            // Muestra qué equipos específicos (modelo + código) surtieron en cada frente.
            $equiposPorFrente = DB::table('consumibles')
                ->join('frentes_trabajo', 'frentes_trabajo.ID_FRENTE', '=', 'consumibles.ID_FRENTE')
                ->join('equipos', 'equipos.ID_EQUIPO', '=', 'consumibles.ID_EQUIPO')
                ->leftJoin('tipo_equipos', 'tipo_equipos.id', '=', 'equipos.id_tipo_equipo')
                ->where(function ($q) use ($filtrosConfirmados) {
                    $filtrosConfirmados($q);
                })
                ->whereNotNull('consumibles.ID_EQUIPO')
                ->select(
                    'equipos.ID_EQUIPO',
                    'frentes_trabajo.NOMBRE_FRENTE',
                    'equipos.MODELO',
                    'equipos.CODIGO_PATIO',
                    'equipos.SERIAL_CHASIS',
                    DB::raw("COALESCE(tipo_equipos.nombre, 'S/T') as tipo_equipo"),
                    DB::raw('SUM(consumibles.CANTIDAD) as total'),
                    DB::raw('COUNT(*) as despachos'),
                    DB::raw('MAX(consumibles.UNIDAD) as unidad')
                )
                ->groupBy(
                    'equipos.ID_EQUIPO',
                    'frentes_trabajo.NOMBRE_FRENTE',
                    'equipos.MODELO',
                    'equipos.CODIGO_PATIO',
                    'equipos.SERIAL_CHASIS',
                    'tipo_equipos.nombre'
                )
                ->orderBy('frentes_trabajo.NOMBRE_FRENTE')
                ->orderBy('total', 'desc')
                ->get();

            // ── 7. Por Especificación — solo cuando el tipo activo es ACEITE o CAUCHO ───────
            // Si el tipo no requiere especificación → vacío → secciones ocultas.
            $tiposConEspec = ['ACEITE', 'CAUCHO'];
            $tipoEspec = in_array($tipo, $tiposConEspec) ? $tipo : null;

            if ($tipoEspec) {
                // Por frente × especificacion
                $especFrente = DB::table('consumibles')
                    ->join('frentes_trabajo', 'frentes_trabajo.ID_FRENTE', '=', 'consumibles.ID_FRENTE')
                    ->where('consumibles.TIPO_CONSUMIBLE', $tipoEspec)
                    ->whereNotNull('consumibles.ESPECIFICACION')
                    ->where('consumibles.ESPECIFICACION', '<>', '')
                    ->when($desde, fn($q) => $q->where('consumibles.FECHA', '>=', $desde))
                    ->when($hasta, fn($q) => $q->where('consumibles.FECHA', '<=', $hasta))
                    ->when($idFrente, fn($q) => $q->where('consumibles.ID_FRENTE', $idFrente))
                    ->select(
                        'frentes_trabajo.NOMBRE_FRENTE',
                        'consumibles.ESPECIFICACION',
                        DB::raw('SUM(consumibles.CANTIDAD) as total'),
                        DB::raw('COUNT(*) as despachos')
                    )
                    ->groupBy('frentes_trabajo.NOMBRE_FRENTE', 'consumibles.ESPECIFICACION')
                    ->orderBy('frentes_trabajo.NOMBRE_FRENTE')
                    ->orderBy('total', 'desc')
                    ->get();

                // Por equipo × especificacion — solo CONFIRMADOS
                // Usa $filtrosConfirmados como base para respetar fecha, frente y tipo igual que el resto
                $especEquipo = DB::table('consumibles')
                    ->join('equipos', 'equipos.ID_EQUIPO', '=', 'consumibles.ID_EQUIPO')
                    ->leftJoin('tipo_equipos', 'tipo_equipos.id', '=', 'equipos.id_tipo_equipo')
                    ->leftJoin('documentacion', 'documentacion.ID_EQUIPO', '=', 'equipos.ID_EQUIPO')
                    ->where(function ($q) use ($filtrosConfirmados) {
                        $filtrosConfirmados($q);
                    })
                    ->where('consumibles.TIPO_CONSUMIBLE', $tipoEspec)   // siempre ACEITE o CAUCHO
                    ->whereNotNull('consumibles.ESPECIFICACION')
                    ->where('consumibles.ESPECIFICACION', '<>', '')
                    ->whereNotNull('consumibles.ID_EQUIPO')
                    ->select(
                        'equipos.ID_EQUIPO',
                        'equipos.CODIGO_PATIO',
                        'equipos.MODELO',
                        'equipos.SERIAL_CHASIS',
                        DB::raw("MAX(documentacion.PLACA) as PLACA"),
                        DB::raw("MAX(consumibles.IDENTIFICADOR) as identificador"),
                        DB::raw("COALESCE(tipo_equipos.nombre, 'S/T') as tipo_equipo"),
                        'consumibles.ESPECIFICACION',
                        DB::raw('SUM(consumibles.CANTIDAD) as total'),
                        DB::raw('COUNT(*) as despachos')
                    )
                    ->groupBy(
                        'equipos.ID_EQUIPO',
                        'equipos.CODIGO_PATIO',
                        'equipos.MODELO',
                        'equipos.SERIAL_CHASIS',
                        'tipo_equipos.nombre',
                        'consumibles.ESPECIFICACION'
                    )
                    ->orderBy('total', 'desc')
                    ->get();

            } else {
                $especFrente = collect();
                $especEquipo = collect();
            }

            // ── 8. Equipos Inoperativos en el frente seleccionado ────────────
            // (Bloque "Cauchos por Tipo de Equipo y Medida" removido a pedido —
            // el panel se elimino del modulo de graficos.)
            $inoperativos = [];
            if ($idFrente) {
                $inoperativos = DB::table('equipos')
                    ->where('ESTADO_OPERATIVO', 'INOPERATIVO')
                    ->where('ID_FRENTE_ACTUAL', $idFrente)
                    ->leftJoin('tipo_equipos', 'tipo_equipos.id', '=', 'equipos.id_tipo_equipo')
                    ->leftJoin('documentacion', 'documentacion.ID_EQUIPO', '=', 'equipos.ID_EQUIPO')
                    ->leftJoin('frentes_trabajo', 'frentes_trabajo.ID_FRENTE', '=', 'equipos.ID_FRENTE_ACTUAL')
                    ->leftJoin('consumibles', function ($join) use ($desde, $hasta, $tipo) {
                        $join->on('consumibles.ID_EQUIPO', '=', 'equipos.ID_EQUIPO');
                        if ($desde)
                            $join->where('consumibles.FECHA', '>=', $desde);
                        if ($hasta)
                            $join->where('consumibles.FECHA', '<=', $hasta);
                        if ($tipo)
                            $join->where('consumibles.TIPO_CONSUMIBLE', $tipo);
                        $join->where('consumibles.ESTADO_EQUIPO', 'CONFIRMADO');
                    })
                    ->select(
                        'equipos.ID_EQUIPO',
                        'equipos.SERIAL_CHASIS',
                        'frentes_trabajo.NOMBRE_FRENTE as frente_nombre',
                        DB::raw("MAX(documentacion.PLACA) as PLACA"),
                        DB::raw("COALESCE(tipo_equipos.nombre, 'S/T') as tipo"),
                        DB::raw('SUM(consumibles.CANTIDAD) as total'),
                        DB::raw('COUNT(consumibles.ID_CONSUMIBLE) as despachos'),
                        DB::raw('MAX(consumibles.UNIDAD) as unidad')
                    )
                    ->groupBy(
                        'equipos.ID_EQUIPO',
                        'equipos.SERIAL_CHASIS',
                        'frentes_trabajo.NOMBRE_FRENTE',
                        'tipo_equipos.nombre'
                    )
                    ->get();

                // FOTO: la de toda la app (Equipo::fotoParaMostrar: color → modelo → propia),
                // resuelta aquí y no en el navegador, que antes repetía la regla por su cuenta.
                $fotos = \App\Models\Equipo::with(\App\Models\Equipo::conFoto())
                    ->whereIn('ID_EQUIPO', $inoperativos->pluck('ID_EQUIPO'))
                    ->get(['ID_EQUIPO', 'ID_ESPEC', 'COLOR', 'FOTO_EQUIPO'])
                    ->mapWithKeys(fn ($e) => [$e->ID_EQUIPO => $e->fotoParaMostrar()]);
                foreach ($inoperativos as $fila) {
                    $fila->FOTO = $fotos[$fila->ID_EQUIPO] ?? null;
                }
            }

            return [
                'por_frente' => $porFrente,
                'equipos_por_frente' => $equiposPorFrente,
                'top_equipos' => $topEquipos,
                'resumen' => $resumen,
                'tipo_activo' => $tipoEspec,
                'espec_frente' => $especFrente,
                'espec_equipo' => $especEquipo,
                'inoperativos' => $inoperativos,
            ];
        }); // fin Cache::remember


        return response()->json($resultado);
    }


    // auditoriaCatalogoData() removido: el panel "Auditoría de Catálogo"
    // se elimino del modulo de graficos. La auditoria de cambios sigue
    // accesible directamente desde /admin/catalogo (cada modelo expone
    // su propio historial). Si en el futuro vuelve, restaurar este metodo
    // junto a la ruta consumibles.auditoriaCatalogo y al panel Blade.

    private static function estadoValido($estado): bool
    {
        return is_string($estado) && in_array($estado, ['PENDIENTE', 'CONFIRMADO', 'SIN_MATCH'], true);
    }

    /**
     * «Buscar placa/serial» de la lista y del export: lo escrito en el consumo o la placa, el
     * serial o el código del equipo (o auxiliar) emparejado, que es lo que muestra la tabla.
     */
    private static function buscarIdentificador($query, string $texto): void
    {
        // «_» y «%» son letras para quien busca (hay placas con «_»), no comodines de LIKE.
        $t = '%' . addcslashes(trim($texto), '\\%_') . '%';
        $query->where(function ($w) use ($t) {
            $w->where('consumibles.IDENTIFICADOR', 'like', $t)
                ->orWhereIn('consumibles.ID_EQUIPO', fn($s) => $s->select('ID_EQUIPO')->from('equipos')
                    ->where('SERIAL_CHASIS', 'like', $t)->orWhere('CODIGO_PATIO', 'like', $t))
                ->orWhereIn('consumibles.ID_EQUIPO', fn($s) => $s->select('ID_EQUIPO')->from('documentacion')
                    ->where('PLACA', 'like', $t))
                ->orWhereIn('consumibles.ID_AUXILIAR', fn($s) => $s->select('ID_AUXILIAR')->from('equipos_auxiliares')
                    ->where('CODIGO_INTERNO', 'like', $t)->orWhere('SERIAL', 'like', $t));
        });
    }

    // ══════════════════════════════════════════════════════════════
    // EXPORTAR CSV — Descarga directa de datos confirmados
    // ══════════════════════════════════════════════════════════════
    public function exportarCsv(Request $request)
    {
        $desde = $request->get('fecha_desde') ?: $request->get('desde');
        $hasta = $request->get('fecha_hasta') ?: $request->get('hasta');
        $idFrente = $request->get('id_frente');
        $tipo = $request->get('tipo');
        $identificador = is_string($request->get('identificador')) ? trim($request->get('identificador')) : null;
        $estado = self::estadoValido($request->get('estado')) ? $request->get('estado') : null;

        $query = DB::table('consumibles')
            ->leftJoin('frentes_trabajo', 'frentes_trabajo.ID_FRENTE', '=', 'consumibles.ID_FRENTE')
            ->leftJoin('equipos', 'equipos.ID_EQUIPO', '=', 'consumibles.ID_EQUIPO')
            ->leftJoin('tipo_equipos', 'tipo_equipos.id', '=', 'equipos.id_tipo_equipo')
            // Los consumos de un auxiliar (máquina de soldar, planta…) llevan ID_AUXILIAR, no ID_EQUIPO.
            ->leftJoin('equipos_auxiliares', 'equipos_auxiliares.ID_AUXILIAR', '=', 'consumibles.ID_AUXILIAR')
            // La placa del equipo (una por equipo), para el mismo identificador que muestra la tabla.
            ->leftJoinSub(
                DB::table('documentacion')->select('ID_EQUIPO', DB::raw('MAX(PLACA) AS PLACA'))->groupBy('ID_EQUIPO'),
                'doc', 'doc.ID_EQUIPO', '=', 'consumibles.ID_EQUIPO'
            );

        if ($desde) {
            $query->where('consumibles.FECHA', '>=', $desde);
        }
        if ($hasta) {
            $query->where('consumibles.FECHA', '<=', $hasta);
        }
        if ($idFrente && $idFrente !== 'all') {
            $query->where('consumibles.ID_FRENTE', $idFrente);
        }
        if ($tipo) {
            $query->where('consumibles.TIPO_CONSUMIBLE', $tipo);
        }
        if ($estado) {
            $query->where('consumibles.ESTADO_EQUIPO', $estado);
        }
        if ($identificador) {
            self::buscarIdentificador($query, $identificador);
        }

        $filas = $query->select(
            'consumibles.FECHA',
            DB::raw("COALESCE(frentes_trabajo.NOMBRE_FRENTE, 'S/F') as FRENTE"),
            'consumibles.TIPO_CONSUMIBLE',
            'consumibles.ESPECIFICACION',
            // Como la tabla: placa, serial o código del equipo (o del auxiliar); sin equipo, lo escrito.
            // Las de relleno («,,,», «_», «....») no cuentan: no tienen ni una letra ni un número.
            DB::raw("COALESCE(" . implode(', ', array_map(
                fn ($col) => "CASE WHEN {$col} REGEXP '[[:alnum:]]' THEN {$col} END",
                ['doc.PLACA', 'equipos.SERIAL_CHASIS', 'equipos.CODIGO_PATIO', 'equipos_auxiliares.CODIGO_INTERNO', 'equipos_auxiliares.SERIAL']
            )) . ", CASE WHEN consumibles.DESC_EQUIPO IS NOT NULL AND consumibles.IDENTIFICADOR LIKE '% | %'
                THEN SUBSTRING(consumibles.IDENTIFICADOR, LOCATE(' | ', consumibles.IDENTIFICADOR) + 3)
                ELSE consumibles.IDENTIFICADOR END) as EQUIPO_PLACA"),
            DB::raw("COALESCE(equipos.MODELO, equipos_auxiliares.MODELO, 'S/M') as MODELO"),
            // Sin equipo asociado: lo que dice el Excel del vehículo.
            DB::raw("COALESCE(tipo_equipos.nombre, REPLACE(equipos_auxiliares.TIPO, '_', ' '), consumibles.DESC_EQUIPO, 'S/T') as TIPO_EQUIPO"),
            'consumibles.CANTIDAD',
            'consumibles.UNIDAD',
            'consumibles.RESP_NOMBRE',
            'consumibles.RESP_CI',
            'consumibles.ESTADO_EQUIPO'
        )
            ->orderBy('consumibles.FECHA', 'desc')
            ->get();

        $nombreFrente = 'TODOS LOS FRENTES';
        if ($idFrente && $idFrente !== 'all') {
            $frenteData = DB::table('frentes_trabajo')->where('ID_FRENTE', $idFrente)->first();
            if ($frenteData) {
                $nombreFrente = mb_strtoupper($frenteData->NOMBRE_FRENTE);
            }
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Consumibles');

        $spreadsheet->getProperties()
            ->setCreator('Sistema de Gestión de Consumibles')
            ->setLastModifiedBy('Administrador')
            ->setTitle('Reporte de Consumibles')
            ->setSubject('Reporte Exportado')
            ->setDescription('Generado automáticamente por el Sistema - C.VIDALSA 27, C.A.');

        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        $sheet->getRowDimension('1')->setRowHeight(35);
        $sheet->getRowDimension('2')->setRowHeight(35);
        $sheet->getRowDimension('3')->setRowHeight(35);

        // Fila 1 a 3 - Título Empresa
        $sheet->mergeCells('A1:B3');
        $sheet->getStyle('A1:B3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        // Logo centrado en A1:B3 (trait ExcelLogoCorporativo)
        $this->insertarLogoCorporativo($sheet, ['A','B'], [1,2,3]);
        $sheet->mergeCells('C1:E3');

        $subTitle = $nombreFrente !== 'TODOS LOS FRENTES' ? 'PROYECTO: "' . $nombreFrente . '"' : 'REPORTE GENERAL';
        $tituloBase = "CONTROL DE CONSUMO GENERAL";
        if ($tipo && $tipo !== 'all' && $tipo !== 'TODOS') {
            $tituloBase = "CONTROL DE CONSUMO DE " . mb_strtoupper($tipo);
        }
        $titleText = $tituloBase . "\n" . $subTitle;

        $sheet->setCellValue('C1', $titleText);
        $sheet->getStyle('C1')->getAlignment()->setWrapText(true);
        $sheet->getStyle('C1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('C1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('C1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $sheet->getStyle('C1:E3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        $sheet->mergeCells('F1:L1');
        $sheet->setCellValue('F1', 'EDICIÓN: 1');
        $sheet->getStyle('F1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('F1')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('F1:L1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        $sheet->mergeCells('F2:L2');
        $sheet->setCellValue('F2', 'REVISIÓN: 0');
        $sheet->getStyle('F2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F2')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('F2')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('F2:L2')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        $sheet->mergeCells('F3:L3');
        $sheet->setCellValue('F3', 'FECHA DE IMPRESIÓN: ' . date('d/m/Y'));
        $sheet->getStyle('F3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F3')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('F3')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('F3:L3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        // Bordes de la cabecera
        $sheet->getStyle('A1:L3')->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        $rowNum = 6;

        $sheet->setCellValue('A' . ($rowNum - 1), 'EMITIDO POR EL SISTEMA DE GESTIÓN DE EQUIPOS OPERACIONALES | Total de registros: ' . count($filas));
        $sheet->getStyle('A' . ($rowNum - 1))->getFont()->setBold(true)->getColor()->setARGB('FF475569');

        $headers = [
            'A' => 'FECHA',
            'B' => 'FRENTE',
            'C' => 'TIPO CONSUMIBLE',
            'D' => 'ESPECIFICACIÓN',
            'E' => 'IDENTIFICADOR/PLACA',
            'F' => 'MODELO',
            'G' => 'TIPO EQUIPO',
            'H' => 'CANTIDAD',
            'I' => 'UNIDAD',
            'J' => 'RESPONSABLE (NOMBRE)',
            'K' => 'RESPONSABLE (C.I.)',
            'L' => 'ESTADO MATCH'
        ];

        foreach ($headers as $col => $title) {
            $sheet->setCellValue($col . $rowNum, $title);
        }

        $sheet->getStyle('A' . $rowNum . ':L' . $rowNum)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A' . $rowNum . ':L' . $rowNum)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF1E293B');
        $sheet->getStyle('A' . $rowNum . ':L' . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $rowNum++;
        $startDataRow = $rowNum;

        foreach ($filas as $f) {
            $sheet->setCellValue('A' . $rowNum, \Carbon\Carbon::parse($f->FECHA)->format('d/m/Y'));
            $sheet->setCellValue('B' . $rowNum, $f->FRENTE);
            $sheet->setCellValue('C' . $rowNum, $f->TIPO_CONSUMIBLE);
            $sheet->setCellValue('D' . $rowNum, $f->ESPECIFICACION);
            $sheet->setCellValue('E' . $rowNum, $f->EQUIPO_PLACA);
            $sheet->setCellValue('F' . $rowNum, $f->MODELO);
            $sheet->setCellValue('G' . $rowNum, $f->TIPO_EQUIPO);
            $sheet->setCellValue('H' . $rowNum, $f->CANTIDAD);
            $sheet->setCellValue('I' . $rowNum, $f->UNIDAD);
            $sheet->setCellValue('J' . $rowNum, $f->RESP_NOMBRE);
            $sheet->setCellValue('K' . $rowNum, $f->RESP_CI);
            $sheet->setCellValue('L' . $rowNum, $f->ESTADO_EQUIPO === 'SIN_MATCH' ? 'POR IDENTIFICAR' : $f->ESTADO_EQUIPO);

            $sheet->getStyle('H' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');

            if ($f->ESTADO_EQUIPO == 'CONFIRMADO') {
                $sheet->getStyle('L' . $rowNum)->getFont()->getColor()->setARGB('FF059669');
            } else {
                $sheet->getStyle('L' . $rowNum)->getFont()->getColor()->setARGB('FFDC2626');
            }

            $rowNum++;
        }

        if ($startDataRow < $rowNum) {
            $sheet->getStyle('A' . $startDataRow . ':L' . ($rowNum - 1))->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        }

        foreach (range('A', 'L') as $columnID) {
            if ($columnID === 'A') {
                $sheet->getColumnDimension('A')->setWidth(14);
            } else {
                $sheet->getColumnDimension($columnID)->setAutoSize(true);
            }
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'export_consumibles_');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($tempFile);

        $name = 'Exportacion_Consumibles_' . date('Y-m-d_H-i') . '.xlsx';

        return response()->download($tempFile, $name)->deleteFileAfterSend(true);
    }
}
