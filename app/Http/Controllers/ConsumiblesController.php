<?php

namespace App\Http\Controllers;

use App\Models\Consumible;
use App\Models\FrenteTrabajo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConsumiblesController extends Controller
{
    // ══════════════════════════════════════════════════════════════
    // INDEX — Lista + pendientes
    // ══════════════════════════════════════════════════════════════
    public function index(Request $request)
    {
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->orderBy('NOMBRE_FRENTE')->get();

        $tipos_equipo = \App\Models\TipoEquipo::orderBy('nombre')->get();

        $query = Consumible::with(['equipo.frenteActual', 'equipo.tipo', 'frente', 'suministro'])
            ->orderBy('FECHA', 'desc')
            ->orderBy('ID_CONSUMIBLE', 'desc');

        // Filtros
        if ($request->filled('id_frente'))
            $query->where('ID_FRENTE', $request->id_frente);

        if ($request->filled('tipo'))
            $query->where('TIPO_CONSUMIBLE', $request->tipo);

        if ($request->filled('estado'))
            $query->where('ESTADO_EQUIPO', $request->estado);

        if ($request->filled('id_tipo_equipo')) {
            $query->whereHas('equipo', function ($q) use ($request) {
                $q->where('id_tipo_equipo', $request->id_tipo_equipo);
            });
        }

        if ($request->filled('fecha_desde'))
            $query->where('FECHA', '>=', $request->fecha_desde);

        if ($request->filled('fecha_hasta'))
            $query->where('FECHA', '<=', $request->fecha_hasta);

        if ($request->filled('identificador'))
            $query->where('IDENTIFICADOR', 'LIKE', '%' . trim($request->identificador) . '%');

        $consumibles = $query->paginate(50)->onEachSide(3)->withQueryString();

        // ── Contadores de estado — 1 sola query en lugar de 3 ──────────────────
        $conteos = DB::table('consumibles')
            ->selectRaw("
                SUM(CASE WHEN ESTADO_EQUIPO = 'PENDIENTE'  THEN 1 ELSE 0 END) as pendientes,
                SUM(CASE WHEN ESTADO_EQUIPO = 'SIN_MATCH'  THEN 1 ELSE 0 END) as sinMatch,
                SUM(CASE WHEN ESTADO_EQUIPO = 'CONFIRMADO' THEN 1 ELSE 0 END) as confirmados
            ")->first();
        $pendientes = (int) ($conteos->pendientes ?? 0);
        $sinMatch = (int) ($conteos->sinMatch ?? 0);
        $confirmados = (int) ($conteos->confirmados ?? 0);

        // ── Resumen de cantidades surtidas por frente (respeta filtros activos) ──
        $resumenFrente = DB::table('consumibles')
            ->join('frentes_trabajo', 'frentes_trabajo.ID_FRENTE', '=', 'consumibles.ID_FRENTE')
            ->when($request->filled('id_frente'), fn($q) => $q->where('consumibles.ID_FRENTE', $request->id_frente))
            ->when($request->filled('tipo'), fn($q) => $q->where('consumibles.TIPO_CONSUMIBLE', $request->tipo))
            ->when($request->filled('estado'), fn($q) => $q->where('consumibles.ESTADO_EQUIPO', $request->estado))
            ->when($request->filled('fecha_desde'), fn($q) => $q->where('consumibles.FECHA', '>=', $request->fecha_desde))
            ->when($request->filled('fecha_hasta'), fn($q) => $q->where('consumibles.FECHA', '<=', $request->fecha_hasta))
            ->when($request->filled('identificador'), fn($q) => $q->where('consumibles.IDENTIFICADOR', 'LIKE', '%' . trim($request->identificador) . '%'))
            ->when($request->filled('id_tipo_equipo'), fn($q) => $q->whereExists(function ($sub) use ($request) {
                $sub->select(DB::raw(1))
                    ->from('equipos')
                    ->whereColumn('equipos.ID_EQUIPO', 'consumibles.ID_EQUIPO')
                    ->where('equipos.id_tipo_equipo', $request->id_tipo_equipo);
            }))
            ->select(
                'frentes_trabajo.NOMBRE_FRENTE',
                DB::raw('SUM(consumibles.CANTIDAD) as total'),
                DB::raw('COUNT(*) as despachos'),
                DB::raw('MAX(consumibles.UNIDAD) as unidad')
            )
            ->groupBy('frentes_trabajo.NOMBRE_FRENTE')
            ->orderByDesc('total')
            ->get();

        return view('admin.consumibles.index', compact(
            'consumibles',
            'frentes',
            'pendientes',
            'sinMatch',
            'confirmados',
            'tipos_equipo',
            'resumenFrente'
        ));
    }

    // ══════════════════════════════════════════════════════════════
    // CARGA DE LOTE — Formulario
    // ══════════════════════════════════════════════════════════════
    public function cargar()
    {
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->orderBy('NOMBRE_FRENTE')->get();

        $tipos = Consumible::tiposLabel();
        $unidades = ['LITROS' => 'Litros', 'GALONES' => 'Galones', 'UNIDADES' => 'Unidades', 'KG' => 'Kg'];

        return view('admin.consumibles.cargar', compact('frentes', 'tipos', 'unidades'));
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
            'tipo_consumible' => 'required|in:GASOIL,GASOLINA,ACEITE,CAUCHO,REFRIGERANTE,OTRO',
            'unidad' => 'required|in:LITROS,GALONES,UNIDADES,KG',
            'id_frente' => 'required|exists:frentes_trabajo,ID_FRENTE',
            'filas' => 'required|array|min:1',
            'filas.*.fecha' => 'required|date',
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
        $frente = $request->id_frente;

        $tiposConEspec = ['ACEITE', 'CAUCHO'];
        $insertados = 0;

        DB::beginTransaction();
        try {
            foreach ($request->filas as $fila) {
                $especFila = null;
                if (in_array($tipo, $tiposConEspec)) {
                    $raw = trim($fila['especificacion'] ?? '');
                    $especFila = $raw !== '' ? $raw : null;
                }

                Consumible::create([
                    'FECHA' => $fila['fecha'],
                    'IDENTIFICADOR' => isset($fila['identificador']) ? trim($fila['identificador']) : null,
                    'RESP_NOMBRE' => isset($fila['resp_nombre']) ? trim($fila['resp_nombre']) : null,
                    'RESP_CI' => isset($fila['resp_ci']) ? trim($fila['resp_ci']) : null,
                    'CANTIDAD' => $fila['cantidad'],
                    'RAW_ORIGEN' => isset($fila['raw_origen']) ? trim($fila['raw_origen']) : null,
                    'TIPO_CONSUMIBLE' => $tipo,
                    'ESPECIFICACION' => $especFila,
                    'UNIDAD' => $unidad,
                    'ID_FRENTE' => $frente,
                    'ID_EQUIPO' => null,
                    'ID_SUMINISTRO' => null,
                    'ESTADO_EQUIPO' => 'PENDIENTE',
                ]);
                $insertados++;
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $errMsg = 'Error al guardar: ' . $e->getMessage();
            if ($request->expectsJson()) {
                return response()->json(['message' => $errMsg], 500);
            }
            return back()->withErrors(['error' => $errMsg])->withInput();
        }

        // Invalidar caché de gráficos — los datos cambiaron
        Cache::increment('consumibles_data_version');

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
    // ACTUALIZAR ESTADO individual (confirmar / marcar sin match)
    // ══════════════════════════════════════════════════════════════
    public function updateEstado(Request $request, int $id)
    {
        $consumible = Consumible::findOrFail($id);

        $request->validate([
            'estado_equipo' => 'required|in:CONFIRMADO,PENDIENTE,SIN_MATCH',
            'id_equipo' => 'nullable|exists:equipos,ID_EQUIPO',
        ]);

        $consumible->update([
            'ESTADO_EQUIPO' => $request->estado_equipo,
            'ID_EQUIPO' => $request->id_equipo,
        ]);

        // Invalidar caché de gráficos
        Cache::increment('consumibles_data_version');

        return response()->json(['ok' => true]);
    }

    // ══════════════════════════════════════════════════════════════
    // ACTUALIZAR IDENTIFICADOR — Resetea a PENDIENTE para re-match
    // ══════════════════════════════════════════════════════════════
    public function updateIdentificador(Request $request, int $id)
    {
        $consumible = Consumible::findOrFail($id);

        $request->validate([
            'identificador' => 'required|string|max:100',
        ]);

        $consumible->update([
            'IDENTIFICADOR' => strtoupper(trim($request->identificador)),
            'ESTADO_EQUIPO' => 'PENDIENTE',   // resetear para re-match
            'ID_EQUIPO' => null,
        ]);

        // Invalidar caché de gráficos
        Cache::increment('consumibles_data_version');

        return response()->json(['ok' => true, 'identificador' => $consumible->IDENTIFICADOR]);
    }

    // ══════════════════════════════════════════════════════════════
    // ACTUALIZAR FRENTE — Cambia el frente de trabajo del registro
    // ══════════════════════════════════════════════════════════════
    public function updateFrente(Request $request, int $id)
    {
        $consumible = Consumible::findOrFail($id);

        $request->validate([
            'id_frente' => 'required|exists:frentes_trabajo,ID_FRENTE',
        ]);

        $consumible->update(['ID_FRENTE' => $request->id_frente]);

        // Invalidar caché de gráficos
        Cache::increment('consumibles_data_version');

        $nombreFrente = FrenteTrabajo::find($request->id_frente)?->NOMBRE_FRENTE ?? '—';

        return response()->json(['ok' => true, 'nombre_frente' => $nombreFrente]);
    }

    // ══════════════════════════════════════════════════════════════
    // DESTROY — Eliminar un registro (AJAX → JSON, form → redirect)
    // ══════════════════════════════════════════════════════════════
    public function destroy(Request $request, int $id)
    {
        // El usuario solicitó eliminar la restricción de permiso
        Consumible::findOrFail($id)->delete();

        // Invalidar caché de gráficos
        Cache::increment('consumibles_data_version');

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Registro eliminado.');
    }

    // ══════════════════════════════════════════════════════════════
    // API — Buscar frentes (para autocomplete AJAX)
    // ══════════════════════════════════════════════════════════════
    public function buscarFrente(Request $request)
    {
        $term = $request->get('q', '');

        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->where('NOMBRE_FRENTE', 'LIKE', "%$term%")
            ->select('ID_FRENTE', 'NOMBRE_FRENTE')
            ->orderBy('NOMBRE_FRENTE')
            ->limit(20)
            ->get();

        return response()->json($frentes);
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
        $version = Cache::get('consumibles_data_version', 1);
        $cacheKey = 'graficos_v' . $version
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
                ->limit(15)
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

            // Frentes totales: sin filtrar por tipo (para coincidir con el gráfico de barras)
            $totalFrentes = DB::table('consumibles')
                ->when($desde, fn($q) => $q->where('FECHA', '>=', $desde))
                ->when($hasta, fn($q) => $q->where('FECHA', '<=', $hasta))
                ->when($idFrente, fn($q) => $q->where('ID_FRENTE', $idFrente))
                ->distinct('ID_FRENTE')
                ->count('ID_FRENTE');

            // ── 4. Todos los equipos con despachos — solo CONFIRMADOS ────────────────
            // Agrupa por equipo (no por tipo_consumible) para evitar filas duplicadas.
            // Muestra los identificadores con los que se registraron los consumos.
            $todosEquipos = DB::table('consumibles')
                ->join('equipos', 'equipos.ID_EQUIPO', '=', 'consumibles.ID_EQUIPO')
                ->leftJoin('tipo_equipos', 'tipo_equipos.id', '=', 'equipos.id_tipo_equipo')
                ->leftJoin('frentes_trabajo', 'frentes_trabajo.ID_FRENTE', '=', 'equipos.ID_FRENTE_ACTUAL')
                ->where(function ($q) use ($filtrosConfirmados) {
                    $filtrosConfirmados($q);
                })
                ->whereNotNull('consumibles.ID_EQUIPO')
                ->select(
                    'equipos.CODIGO_PATIO',
                    'equipos.MARCA',
                    'equipos.MODELO',
                    DB::raw("COALESCE(tipo_equipos.nombre, 'S/T') as tipo"),
                    DB::raw("MAX(frentes_trabajo.NOMBRE_FRENTE) as frente"),
                    DB::raw('COUNT(*) as despachos'),
                    DB::raw('SUM(consumibles.CANTIDAD) as total'),
                    DB::raw('MAX(consumibles.UNIDAD) as unidad'),
                    // Identificadores únicos usados al registrar los consumos
                    DB::raw('GROUP_CONCAT(DISTINCT consumibles.IDENTIFICADOR ORDER BY consumibles.IDENTIFICADOR SEPARATOR " · ") as identificadores')
                )
                ->groupBy(
                    'equipos.ID_EQUIPO',
                    'equipos.CODIGO_PATIO',
                    'equipos.MARCA',
                    'equipos.MODELO',
                    'tipo_equipos.nombre'
                )
                ->orderBy('despachos', 'desc')
                ->get();

            // ── 5. Consumo por tipo de equipo — solo CONFIRMADOS ────────────
            $porTipoEquipo = DB::table('consumibles')
                ->join('equipos', 'equipos.ID_EQUIPO', '=', 'consumibles.ID_EQUIPO')
                ->leftJoin('tipo_equipos', 'tipo_equipos.id', '=', 'equipos.id_tipo_equipo')
                ->where(function ($q) use ($filtrosConfirmados) {
                    $filtrosConfirmados($q);
                })
                ->whereNotNull('consumibles.ID_EQUIPO')
                ->select(
                    DB::raw("COALESCE(tipo_equipos.nombre, 'S/T') as tipo_equipo"),
                    DB::raw('SUM(consumibles.CANTIDAD) as total'),
                    DB::raw('COUNT(*) as despachos'),
                    DB::raw('MAX(consumibles.UNIDAD) as unidad')
                )
                ->groupBy('tipo_equipos.id', 'tipo_equipos.nombre')
                ->orderByDesc('total')
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

            // ── Equipos asignados por frente (desde tabla equipos, no consumibles) ──
            $equiposAsignados = DB::table('equipos')
                ->join('frentes_trabajo', 'frentes_trabajo.ID_FRENTE', '=', 'equipos.ID_FRENTE_ACTUAL')
                ->whereNotNull('equipos.ID_FRENTE_ACTUAL')
                ->select(
                    'frentes_trabajo.NOMBRE_FRENTE',
                    DB::raw('COUNT(equipos.ID_EQUIPO) as total_asignados')
                )
                ->groupBy('frentes_trabajo.NOMBRE_FRENTE')
                ->get()
                ->keyBy('NOMBRE_FRENTE');  // indexado por nombre para fácil lookup en JS

            // ── Frente virtual "AMBIENTE" = suma de DRAGADO + COMOR + TRASEGADO ──
            // Se detectan por coincidencia parcial (LIKE) para tolerar nombres como
            // "FRENTE DRAGADO", "DRAGADO 1", etc.
            $frentesAmbiente = ['DRAGADO', 'COMOR', 'TRASEGADO'];
            $totalAmbiente = 0;
            foreach ($equiposAsignados as $nombre => $row) {
                foreach ($frentesAmbiente as $kw) {
                    if (stripos($nombre, $kw) !== false) {
                        $totalAmbiente += (int) $row->total_asignados;
                        break; // no sumar el mismo frente dos veces aunque tenga dos keywords
                    }
                }
            }
            if ($totalAmbiente > 0) {
                $equiposAsignados->put('AMBIENTE', (object) [
                    'NOMBRE_FRENTE' => 'AMBIENTE',
                    'total_asignados' => $totalAmbiente,
                ]);
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
                    ->leftJoin('caracteristicas_modelo', 'caracteristicas_modelo.ID_ESPEC', '=', 'equipos.ID_ESPEC')
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
                        'equipos.FOTO_EQUIPO',
                        'caracteristicas_modelo.FOTO_REFERENCIAL',
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
                        'equipos.FOTO_EQUIPO',
                        'caracteristicas_modelo.FOTO_REFERENCIAL',
                        'frentes_trabajo.NOMBRE_FRENTE',
                        'tipo_equipos.nombre'
                    )
                    ->get();
            }

            return [
                'por_frente' => $porFrente,
                'por_tipo_equipo' => $porTipoEquipo,
                'equipos_por_frente' => $equiposPorFrente,
                'top_equipos' => $topEquipos,
                'todos_equipos' => $todosEquipos,
                'resumen' => $resumen,
                'total_frentes' => $totalFrentes,
                'tipo_activo' => $tipoEspec,
                'espec_frente' => $especFrente,
                'espec_equipo' => $especEquipo,
                'equipos_asignados' => $equiposAsignados,
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

    // ══════════════════════════════════════════════════════════════
    // EXPORTAR CSV — Descarga directa de datos confirmados
    // ══════════════════════════════════════════════════════════════
    public function exportarCsv(Request $request)
    {
        $desde = $request->get('fecha_desde') ?: $request->get('desde');
        $hasta = $request->get('fecha_hasta') ?: $request->get('hasta');
        $idFrente = $request->get('id_frente');
        $tipo = $request->get('tipo');
        $identificador = $request->get('identificador');
        $estado = $request->get('estado');

        $query = DB::table('consumibles')
            ->leftJoin('frentes_trabajo', 'frentes_trabajo.ID_FRENTE', '=', 'consumibles.ID_FRENTE')
            ->leftJoin('equipos', 'equipos.ID_EQUIPO', '=', 'consumibles.ID_EQUIPO')
            ->leftJoin('tipo_equipos', 'tipo_equipos.id', '=', 'equipos.id_tipo_equipo');

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
            $query->where(function ($q) use ($identificador) {
                $q->where('consumibles.IDENTIFICADOR', 'like', "%{$identificador}%")
                    ->orWhere('equipos.CODIGO_PATIO', 'like', "%{$identificador}%")
                    ->orWhere('equipos.SERIAL_CHASIS', 'like', "%{$identificador}%");
            });
        }

        $filas = $query->select(
            'consumibles.FECHA',
            DB::raw("COALESCE(frentes_trabajo.NOMBRE_FRENTE, 'S/F') as FRENTE"),
            'consumibles.TIPO_CONSUMIBLE',
            'consumibles.ESPECIFICACION',
            DB::raw('COALESCE(equipos.CODIGO_PATIO, consumibles.IDENTIFICADOR) as EQUIPO_PLACA'),
            DB::raw("COALESCE(equipos.MARCA, 'S/M') as MARCA"),
            DB::raw("COALESCE(equipos.MODELO, 'S/M') as MODELO"),
            DB::raw("COALESCE(tipo_equipos.nombre, 'S/T') as TIPO_EQUIPO"),
            'consumibles.CANTIDAD',
            'consumibles.UNIDAD',
            'consumibles.RESP_NOMBRE',
            'consumibles.RESP_CI',
            'consumibles.RAW_ORIGEN',
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
                $drawing->setOffsetY(10);
                $drawing->setHeight(100);
                $drawing->setWorksheet($sheet);
            } catch (\Exception $e) {
            }
        }

        $sheet->getRowDimension('1')->setRowHeight(35);
        $sheet->getRowDimension('2')->setRowHeight(35);
        $sheet->getRowDimension('3')->setRowHeight(35);

        // Fila 1 a 3 - Título Empresa
        $sheet->mergeCells('A1:B3');
        $sheet->getStyle('A1:B3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
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
            $sheet->setCellValue('L' . $rowNum, $f->ESTADO_EQUIPO);

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

    // ══════════════════════════════════════════════════════════════
    // MATCH AUTOMÁTICO — Resuelve equipos PENDIENTES
    // ══════════════════════════════════════════════════════════════
    public function matchAutomatico()
    {
        // Procesa PENDIENTE y SIN_MATCH — permite reintentar los no encontrados
        $pendientes = Consumible::whereIn('ESTADO_EQUIPO', ['PENDIENTE', 'SIN_MATCH'])
            ->whereNotNull('IDENTIFICADOR')
            ->where('IDENTIFICADOR', '!=', '')
            ->get();

        $confirmados = 0;
        $sinMatch = 0;
        $detalle = [];

        foreach ($pendientes as $c) {
            $id = strtoupper(trim($c->IDENTIFICADOR));
            $equipo = null;
            $modo = null;

            // 1️⃣ Por placa EXACTA en tabla documentacion
            $docPlaca = DB::table('documentacion')
                ->whereRaw('UPPER(TRIM(PLACA)) = ?', [$id])
                ->select('ID_EQUIPO')
                ->first();
            if ($docPlaca) {
                $equipo = DB::table('equipos')
                    ->where('ID_EQUIPO', $docPlaca->ID_EQUIPO)
                    ->select('ID_EQUIPO', 'CODIGO_PATIO', 'SERIAL_CHASIS', 'MARCA', 'MODELO')
                    ->first();
                if ($equipo)
                    $modo = 'placa';
            }

            // 2️⃣ Por CODIGO_PATIO exacto (etiqueta interna)
            if (!$equipo) {
                $equipo = DB::table('equipos')
                    ->whereRaw('UPPER(TRIM(CODIGO_PATIO)) = ?', [$id])
                    ->select('ID_EQUIPO', 'CODIGO_PATIO', 'SERIAL_CHASIS', 'MARCA', 'MODELO')
                    ->first();
                if ($equipo)
                    $modo = 'codigo_patio';
            }

            // 3️⃣ Por serial de chasis exacto
            if (!$equipo) {
                $equipo = DB::table('equipos')
                    ->whereRaw('UPPER(TRIM(SERIAL_CHASIS)) = ?', [$id])
                    ->select('ID_EQUIPO', 'CODIGO_PATIO', 'SERIAL_CHASIS', 'MARCA', 'MODELO')
                    ->first();
                if ($equipo)
                    $modo = 'serial_exacto';
            }

            // 4️⃣ Por coincidencia parcial — placa (documentacion), serial o codigo_patio
            // Mínimo 4 chars para evitar falsos positivos
            if (!$equipo && strlen($id) >= 4) {
                // Buscar por placa parcial en documentacion
                $docParcial = DB::table('documentacion')
                    ->whereRaw('UPPER(PLACA) LIKE ?', ["%{$id}%"])
                    ->select('ID_EQUIPO')
                    ->get();

                // Buscar por serial o codigo_patio parcial en equipos
                $eqParcial = DB::table('equipos')
                    ->whereRaw('UPPER(SERIAL_CHASIS) LIKE ? OR UPPER(TRIM(CODIGO_PATIO)) LIKE ?', [
                        "%{$id}%",
                        "%{$id}%",
                    ])
                    ->select('ID_EQUIPO', 'CODIGO_PATIO', 'SERIAL_CHASIS', 'MARCA', 'MODELO')
                    ->get();

                // Combinar y deduplicar IDs encontrados
                $idsDoc = $docParcial->pluck('ID_EQUIPO');
                $idsEq = $eqParcial->pluck('ID_EQUIPO');
                $todosIds = $idsDoc->merge($idsEq)->unique();

                if ($todosIds->count() === 1) {
                    $idEncontrado = $todosIds->first();
                    $equipo = DB::table('equipos')
                        ->where('ID_EQUIPO', $idEncontrado)
                        ->select('ID_EQUIPO', 'CODIGO_PATIO', 'SERIAL_CHASIS', 'MARCA', 'MODELO')
                        ->first();
                    // Determinar si vino de placa o serial
                    $modo = $idsDoc->contains($idEncontrado) ? 'placa_parcial' : 'serial_parcial';
                }
            }

            // — Actualizar el registro —
            if ($equipo) {
                $c->update([
                    'ID_EQUIPO' => $equipo->ID_EQUIPO,
                    'ESTADO_EQUIPO' => 'CONFIRMADO',
                ]);
                $confirmados++;
                $detalle[] = [
                    'identificador' => $c->IDENTIFICADOR,
                    'match' => "{$equipo->CODIGO_PATIO} · {$equipo->MARCA} {$equipo->MODELO}",
                    'modo' => $modo,
                    'estado' => 'CONFIRMADO',
                ];
            } else {
                $c->update(['ESTADO_EQUIPO' => 'SIN_MATCH']);
                $sinMatch++;
                $detalle[] = [
                    'identificador' => $c->IDENTIFICADOR,
                    'match' => null,
                    'modo' => $modo,
                    'estado' => 'SIN_MATCH',
                ];
            }
        }

        // Invalidar caché de gráficos — el match puede cambiar muchos registros
        Cache::increment('consumibles_data_version');

        return response()->json([
            'total' => $pendientes->count(),
            'confirmados' => $confirmados,
            'sin_match' => $sinMatch,
            'detalle' => $detalle,
        ]);
    }
}
