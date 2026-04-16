<?php

namespace App\Http\Controllers;

use App\Models\Movilizacion;
use App\Models\FrenteTrabajo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MovilizacionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth')->except(['mobileIndex', 'mobileStore']);
        // Permiso para MOVER equipos (Crear movilizaciones o registrar recepción directa sin despacho previo)
        $this->middleware('can:equipos.assign')->only(['create', 'store', 'bulkStore', 'recepcionDirecta']);
    }

    public function index(Request $request)
    {
        // Frente filter logic: LOCAL users always see their frente (locked).
        // GLOBAL users get their frente as default ONLY on the initial page load (non-AJAX).
        // When a GLOBAL user clears the filter (AJAX request), we respect the empty value.
        $user = auth()->user();
        $isLocalUser = $user && $user->NIVEL_ACCESO == 2;
        $frentesPermitidos = $user ? $user->getFrentesIds() : [];

        if (!$isLocalUser && $user && count($frentesPermitidos) > 0) {
            // GLOBALES (Nivel!=2) resuelven su scope nativo en consulta,
            // no forzamos `id_frente` en request para mantener el Dropdown Visual HTML limpio.
        }

        $query = Movilizacion::with([
            'equipo.tipo',
            'equipo.especificaciones:ID_ESPEC,COMBUSTIBLE,CONSUMO_PROMEDIO,FOTO_REFERENCIAL',
            'equipo.documentacion',
            'frenteOrigen',
            'frenteDestino',
            'usuario',
        ]);

        // Eliminada la barrera de seguridad de usuario local. Todos ven todo.

        // ─── Búsqueda de texto ────────────────────────────────────────────────────
        // Usa whereHas() para evitar LEFT JOINs que generan columnas ambiguas
        // (created_at, updated_at, etc.) y filas duplicadas en la paginación.
        if ($request->filled('search')) {
            $search      = trim($request->search);
            $searchUpper = strtoupper($search);

            $query->where(function ($q) use ($search, $searchUpper) {

                // Patrón 1: MV-XXXXX / MVXXXXX → buscar CODIGO_CONTROL
                if (preg_match('/^MV-?\d+/i', $search)) {
                    $clean = ltrim(str_replace(['MV-', 'MV'], '', $searchUpper), '0');
                    $q->where('movilizacion_historial.CODIGO_CONTROL', 'like', "%{$searchUpper}%")
                      ->orWhere('movilizacion_historial.CODIGO_CONTROL', 'like', "%{$clean}%");

                // Patrón 2: DD-MM-YYYY → buscar CODIGO_PATIO
                } elseif (preg_match('/\d{2}-\d{2}-\d{4}/', $search)) {
                    $q->whereHas('equipo', fn ($qEq) =>
                        $qEq->where('CODIGO_PATIO', 'like', "%{$search}%")
                    );

                // Patrón 3: #NÚMERO → buscar NUMERO_ETIQUETA
                } elseif (strpos($search, '#') === 0) {
                    $tag = ltrim($search, '#');
                    $q->whereHas('equipo', fn ($qEq) =>
                        $qEq->where('NUMERO_ETIQUETA', 'like', "%{$tag}%")
                    );

                // Patrón por defecto: serial, placa, o código de patio
                // Todo dentro del mismo whereHas para query correcto
                } else {
                    $q->where(function ($qInner) use ($searchUpper) {
                        $qInner->whereHas('equipo', function ($qEq) use ($searchUpper) {
                            $qEq->where('SERIAL_CHASIS', 'like', "%{$searchUpper}%")
                                ->orWhere('CODIGO_PATIO', 'like', "%{$searchUpper}%");
                        })->orWhereHas('equipo.documentacion', function ($qDoc) use ($searchUpper) {
                            $qDoc->where('PLACA', 'like', "%{$searchUpper}%");
                        });
                    });
                }
            });
        }


        // ─── SHARED filter logic (applied to both main query and stats query) ───────
        // Extracted into a closure to eliminate code duplication and ensure both
        // queries always use identical filtering criteria.
        $applyFrenteFilter = function ($q) use ($request) {
            if ($request->filled('id_frente') && $request->id_frente !== 'all') {
                $direccion = $request->input('direccion_frente');
                if ($direccion === 'entrada') {
                    $q->where('ID_FRENTE_DESTINO', $request->id_frente);
                } elseif ($direccion === 'salida') {
                    $q->where('ID_FRENTE_ORIGEN', $request->id_frente);
                } else {
                    $q->where(function ($inner) use ($request) {
                        $inner->where('ID_FRENTE_DESTINO', $request->id_frente)
                              ->orWhere('ID_FRENTE_ORIGEN', $request->id_frente);
                    });
                }
            }
        };

        // ─── Apply shared filters to main query ───────────────────────────────────
        $applyFrenteFilter($query);

        if ($request->filled('id_tipo') && $request->id_tipo !== 'all') {
            $query->whereHas('equipo', function ($q) use ($request) {
                $q->where('id_tipo_equipo', $request->id_tipo);
            });
        }

        // Date range filter
        if ($request->filled('fecha_desde')) {
            $query->whereDate('movilizacion_historial.created_at', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('movilizacion_historial.created_at', '<=', $request->fecha_hasta);
        }

        // Fetch paginated results sin puntos suspensivos (mostrando hasta 50 páginas continuas)
        $movilizaciones = $query->orderBy('movilizacion_historial.created_at', 'desc')->paginate(12);

        $totalTransito     = $movilizaciones->total(); // Total real de registros con los filtros activos
        $transitoPorFrente = collect();               // Sidebar desactivado

        // Check if JSON specifically requested (for filters)
        if ($request->wantsJson()) {
            $tableHtml = view('admin.movilizaciones.partials.table_rows', compact('movilizaciones'))->render();
            $paginationHtml = $movilizaciones->appends($request->all())->links('vendor.pagination.custom-sliding')->toHtml();

            $statsHtml = '<h4 style="margin: 0 0 15px 0; font-size: 13px; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                <i class="material-icons" style="font-size: 18px; color: #8b5cf6;">local_shipping</i>
                En Tránsito por Frente
            </h4>
            <div class="custom-scrollbar-container" style="max-height: 250px; overflow-y: auto; padding-right: 5px;">
            <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 6px;">';

            if ($transitoPorFrente->isNotEmpty()) {
                foreach ($transitoPorFrente as $stat) {
                    $statsHtml .= '<li style="padding: 6px 10px; background: #f8fafc; border-radius: 8px; border: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 12px; color: #64748b; font-weight: 600;">' . $stat->NOMBRE_FRENTE . '</span>
                            <span style="background: #e0e7ff; color: #4338ca; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 700;">' . $stat->total . '</span>
                        </li>';
                }
            } else {
                $statsHtml .= '<li style="padding: 15px; text-align: center; color: #94a3b8; font-style: italic; font-size: 13px;">No hay equipos en tránsito</li>';
            }
            $statsHtml .= '</ul></div>';

            return response()->json([
                'html' => $tableHtml,
                'pagination' => $paginationHtml,
                'statsHtml' => $statsHtml,
                'totalTransito' => $movilizaciones->total()
            ]);
        }

        $frentes = FrenteTrabajo::orderBy('NOMBRE_FRENTE')->get();
        $allTipos = \App\Models\TipoEquipo::orderBy('nombre')->get();

        return view('admin.movilizaciones.index', compact('movilizaciones', 'totalTransito', 'transitoPorFrente', 'frentes', 'allTipos'));
    }

    public function create()
    {
        $equipos = \App\Models\Equipo::with(['tipo', 'frenteActual'])
            ->where('ESTADO_OPERATIVO', 'OPERATIVO')
            ->orderBy('CODIGO_PATIO')
            ->get();

        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE')->get();

        return view('admin.movilizaciones.create', compact('equipos', 'frentes'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'ID_EQUIPO' => 'required|exists:equipos,ID_EQUIPO',
            'ID_FRENTE_DESTINO' => 'required|exists:frentes_trabajo,ID_FRENTE',
        ]);

        $equipo = \App\Models\Equipo::findOrFail($request->ID_EQUIPO);

        // Lock para evitar race condition si dos usuarios despachan al mismo tiempo
        $lastLog = Movilizacion::latest('ID_MOVILIZACION')->lockForUpdate()->first();
        $nextId = $lastLog ? ($lastLog->ID_MOVILIZACION + 1) : 1;

        $origen = $equipo->ID_FRENTE_ACTUAL ?? 1;

        $now = now();
        Movilizacion::create([
            'CODIGO_CONTROL' => $nextId,
            'ID_EQUIPO' => $request->ID_EQUIPO,
            'ID_FRENTE_ORIGEN' => $origen,
            'ID_FRENTE_DESTINO' => $request->ID_FRENTE_DESTINO,
            'FECHA_DESPACHO' => $now,
            'FECHA_RECEPCION' => $now,
            'ESTADO_MVO' => 'RECIBIDO',
            'TIPO_MOVIMIENTO' => 'DESPACHO',
            'USUARIO_REGISTRO' => auth()->user()->CORREO_ELECTRONICO ?? 'SISTEMA',
            'USUARIO_RECEPCION' => auth()->user()->CORREO_ELECTRONICO ?? 'SISTEMA',
        ]);

        // Al despachar a otro frente, llega instantaneamente
        $equipo->update([
            'ID_FRENTE_ACTUAL' => $request->ID_FRENTE_DESTINO,
            'DETALLE_UBICACION_ACTUAL' => null,
            'CONFIRMADO_EN_SITIO' => 1
        ]);

        return redirect()->route('movilizaciones.index')->with('success', 'Movilización registrada correctamente.');
    }

    public function bulkStore(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:equipos,ID_EQUIPO',
            'destination' => 'required|string|max:255',
            'generar_pdf' => 'boolean'
        ]);

        try {
            DB::beginTransaction();

            $frente = FrenteTrabajo::firstOrCreate(
                ['NOMBRE_FRENTE' => strtoupper($request->destination)],
                ['ESTATUS_FRENTE' => 'ACTIVO']
            );

            $user = auth()->user()->CORREO_ELECTRONICO ?? 'SISTEMA';
            $now = now();
            $generarPdf = $request->input('generar_pdf', true);

            $lastLog = Movilizacion::latest('ID_MOVILIZACION')->lockForUpdate()->first();
            $nextId = null;
            if ($generarPdf) {
                // Generar CODIGO_CONTROL solo si se requiere acta de traslado
                $nextId = $lastLog && $lastLog->CODIGO_CONTROL ? ((int)$lastLog->CODIGO_CONTROL + 1) : ($lastLog ? ($lastLog->ID_MOVILIZACION + 1) : 1);
            }

            $equipos = \App\Models\Equipo::whereIn('ID_EQUIPO', $request->ids)->get(['ID_EQUIPO', 'ID_FRENTE_ACTUAL']);

            $insertData = [];
            foreach ($equipos as $equipo) {
                if ($generarPdf) {
                    $insertData[] = [
                        'CODIGO_CONTROL' => $nextId,
                        'ID_EQUIPO' => $equipo->ID_EQUIPO,
                        'ID_FRENTE_ORIGEN' => $equipo->ID_FRENTE_ACTUAL ?? 1,
                        'ID_FRENTE_DESTINO' => $frente->ID_FRENTE,
                        'FECHA_DESPACHO' => $now,
                        'FECHA_RECEPCION' => $now,
                        'ESTADO_MVO' => 'RECIBIDO',
                        'TIPO_MOVIMIENTO' => 'DESPACHO',
                        'USUARIO_REGISTRO' => $user,
                        'USUARIO_RECEPCION' => $user,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                } else {
                    $insertData[] = [
                        'CODIGO_CONTROL' => null,
                        'ID_EQUIPO' => $equipo->ID_EQUIPO,
                        'ID_FRENTE_ORIGEN' => $equipo->ID_FRENTE_ACTUAL ?? 1,
                        'ID_FRENTE_DESTINO' => $frente->ID_FRENTE,
                        'FECHA_DESPACHO' => null,
                        'FECHA_RECEPCION' => $now,
                        'ESTADO_MVO' => 'RECIBIDO',
                        'TIPO_MOVIMIENTO' => 'ACT.',
                        'USUARIO_REGISTRO' => $user,
                        'USUARIO_RECEPCION' => $user,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            $movilizacionIds = [];
            if (!empty($insertData)) {
                Movilizacion::insert($insertData);

                if ($nextId !== null) {
                    $movilizacionIds = Movilizacion::where('CODIGO_CONTROL', $nextId)
                        ->pluck('ID_MOVILIZACION')
                        ->toArray();
                } else {
                    // Si no hay CODIGO_CONTROL igual trataremos de retornar los ids 
                    // aunque no se va a generar acta
                    $movilizacionIds = Movilizacion::whereIn('ID_EQUIPO', $request->ids)
                        ->where('ID_FRENTE_DESTINO', $frente->ID_FRENTE)
                        ->where('ESTADO_MVO', 'RECIBIDO')
                        ->where('FECHA_RECEPCION', $now)
                        ->pluck('ID_MOVILIZACION')
                        ->toArray();
                }
            }

            \App\Models\Equipo::whereIn('ID_EQUIPO', $request->ids)->update([
                'ID_FRENTE_ACTUAL'          => $frente->ID_FRENTE,
                'CONFIRMADO_EN_SITIO'       => 1,
                'DETALLE_UBICACION_ACTUAL'  => null, // Se borra al salir del frente
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'movilizacion_ids' => $movilizacionIds,
                'count' => count($movilizacionIds),
                'generar_pdf' => $generarPdf
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Confirmar recepción de una movilización en tránsito (RECIBIR)
     */
    public function updateStatus(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $mov = Movilizacion::with('equipo', 'frenteOrigen', 'frenteDestino')->findOrFail($id);
            $usuario = auth()->user();

            $request->validate([
                'status' => 'required|in:RECIBIDO,RECHAZADO,RETORNADO',
                'DETALLE_UBICACION' => 'nullable|string|max:150'
            ]);

            // Validar autorización
            $esGlobal = ($usuario->NIVEL_ACCESO == 1);
            $frentesPermitidos = $usuario->getFrentesIds();

            if (!$esGlobal) {
                if ($request->status == 'RECIBIDO' && !in_array($mov->ID_FRENTE_DESTINO, $frentesPermitidos)) {
                    $errorMsg = 'Solo el frente destino puede confirmar la recepción';
                    if ($request->ajax()) {
                        return response()->json(['success' => false, 'error' => $errorMsg], 403);
                    }
                    abort(403, $errorMsg);
                }
            }

            // Validar que esté en tránsito
            if ($mov->ESTADO_MVO != 'TRANSITO') {
                $errorMsg = 'Esta movilización ya fue procesada';
                if ($request->ajax()) {
                    return response()->json(['success' => false, 'error' => $errorMsg], 422);
                }
                return back()->withErrors(['error' => $errorMsg]);
            }

            // 1. Actualizar movilización
            $mov->update([
                'ESTADO_MVO' => 'RECIBIDO',
                'FECHA_RECEPCION' => now(),
                'DETALLE_UBICACION' => $request->DETALLE_UBICACION,
                'USUARIO_RECEPCION' => $usuario->CORREO_ELECTRONICO ?? 'SISTEMA',
            ]);

            // 2. Actualizar equipo: confirmar en el frente destino
            if ($mov->equipo) {
                $mov->equipo->update([
                    'ID_FRENTE_ACTUAL' => $mov->ID_FRENTE_DESTINO,
                    'DETALLE_UBICACION_ACTUAL' => $request->DETALLE_UBICACION,
                    'CONFIRMADO_EN_SITIO' => 1
                ]);
            }

            DB::commit();

            $message = 'Equipo recibido exitosamente en ' . ($mov->frenteDestino->NOMBRE_FRENTE ?? 'Destino');

            if ($request->ajax()) {
                return response()->json(['success' => true, 'message' => $message]);
            }
            return back()->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error en updateStatus: ' . $e->getMessage());
            if ($request->ajax()) {
                return response()->json(['success' => false, 'error' => 'Error al actualizar: ' . $e->getMessage()], 500);
            }
            return back()->withErrors(['error' => 'Error al actualizar: ' . $e->getMessage()]);
        }
    }

    /**
     * RECEPCIÓN DIRECTA: Registrar equipos que llegan sin movilización previa
     */
    public function recepcionDirecta(Request $request)
    {
        $usuario = $request->user();
        
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:equipos,ID_EQUIPO',
            'ID_FRENTE_DESTINO' => 'required|exists:frentes_trabajo,ID_FRENTE',
            'DETALLE_UBICACION' => 'nullable|string|max:150',
        ]);

        // Asegurar que el usuario tenga permisos sobre el frente destino (si no es global)
        if ($usuario->NIVEL_ACCESO != 1) {
            $frentesPermitidos = $usuario->getFrentesIds();
            if (!in_array($request->ID_FRENTE_DESTINO, $frentesPermitidos)) {
                return response()->json(['success' => false, 'error' => 'No tiene permisos para recibir equipos en este frente.'], 403);
            }
        }

        DB::beginTransaction();
        try {
            $now = now();
            $frenteDestino = FrenteTrabajo::findOrFail($request->ID_FRENTE_DESTINO);

            $equipos = \App\Models\Equipo::with('frenteActual')
                ->whereIn('ID_EQUIPO', $request->ids)
                ->get();

            $insertData = [];
            foreach ($equipos as $equipo) {
                $insertData[] = [
                    'CODIGO_CONTROL' => null, // Recepciones directas no tienen código de control
                    'ID_EQUIPO' => $equipo->ID_EQUIPO,
                    'ID_FRENTE_ORIGEN' => $equipo->ID_FRENTE_ACTUAL ?? $request->ID_FRENTE_DESTINO,
                    'ID_FRENTE_DESTINO' => $request->ID_FRENTE_DESTINO,
                    'DETALLE_UBICACION' => $request->DETALLE_UBICACION,
                    'FECHA_DESPACHO' => null, // No hubo despacho
                    'ESTADO_MVO' => 'RECIBIDO',
                    'TIPO_MOVIMIENTO' => 'RECEPCION_DIRECTA',
                    'USUARIO_REGISTRO' => $usuario->CORREO_ELECTRONICO ?? 'SISTEMA',
                    'USUARIO_RECEPCION' => $usuario->CORREO_ELECTRONICO ?? 'SISTEMA',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (!empty($insertData)) {
                Movilizacion::insert($insertData);
            }

            // Actualizar equipos
            \App\Models\Equipo::whereIn('ID_EQUIPO', $request->ids)->update([
                'ID_FRENTE_ACTUAL' => $request->ID_FRENTE_DESTINO,
                'DETALLE_UBICACION_ACTUAL' => $request->DETALLE_UBICACION,
                'CONFIRMADO_EN_SITIO' => 1,
            ]);

            DB::commit();

            $ubicacionTexto = $frenteDestino->NOMBRE_FRENTE;
            if ($request->filled('DETALLE_UBICACION')) {
                $ubicacionTexto .= ' → ' . $request->DETALLE_UBICACION;
            }

            return response()->json([
                'success' => true,
                'message' => count($request->ids) . ' equipo(s) recibido(s) directamente en ' . $ubicacionTexto,
                'count' => count($request->ids),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error en recepcionDirecta: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * API: Buscar equipos para recepción directa
     */
    public function buscarEquiposParaRecepcion(Request $request)
    {
        $query = \App\Models\Equipo::with(['tipo', 'frenteActual', 'documentacion', 'especificaciones:ID_ESPEC,FOTO_REFERENCIAL']);

        if ($request->filled('search')) {
            $search = $request->search;
            $searchUpper = strtoupper(trim($search));

            if (strpos($searchUpper, '#') !== false) {
                // Mode: Tag Number Search
                $tagSearch = str_replace('#', '', $searchUpper);
                $query->where('NUMERO_ETIQUETA', 'like', "%{$tagSearch}%");

            } elseif (strpos($searchUpper, '-') !== false) {
                // Mode: Yard Code Search
                $query->where('CODIGO_PATIO', 'like', "%{$searchUpper}%");

            } else {
                // Standard search — O/0 ambiguity applied ONLY to PLACA
                $placaVariants = collect([
                    $searchUpper,
                    str_replace('O', '0', $searchUpper),
                    str_replace('0', 'O', $searchUpper),
                    str_replace(['O', '0'], ['0', 'O'], $searchUpper),
                ])->unique()->values()->all();

                $query->where(function ($q) use ($searchUpper, $placaVariants) {
                    $q->where('SERIAL_CHASIS', 'like', "%{$searchUpper}%")
                      ->orWhere('SERIAL_DE_MOTOR', 'like', "%{$searchUpper}%")
                      ->orWhere('CODIGO_PATIO', 'like', "%{$searchUpper}%")
                      ->orWhere('NUMERO_ETIQUETA', 'like', "%{$searchUpper}%")
                      ->orWhereHas('documentacion', function ($d) use ($placaVariants) {
                          $d->where(function ($pq) use ($placaVariants) {
                              foreach ($placaVariants as $variant) {
                                  $pq->orWhere('PLACA', 'like', "%{$variant}%");
                              }
                          });
                      });
                });
            }
        }

        $equipos = $query->orderBy('CODIGO_PATIO')->limit(20)->get();

        return response()->json($equipos->map(function ($eq) {
            // Determinar la mejor foto disponible
            $foto = null;
            if ($eq->FOTO_EQUIPO) {
                $foto = $eq->FOTO_EQUIPO;
            } elseif ($eq->especificaciones && $eq->especificaciones->FOTO_REFERENCIAL) {
                $foto = $eq->especificaciones->FOTO_REFERENCIAL;
            }

            return [
                'ID_EQUIPO' => $eq->ID_EQUIPO,
                'TIPO' => $eq->tipo->nombre ?? 'N/A',
                'CODIGO_PATIO' => $eq->CODIGO_PATIO,
                'SERIAL_CHASIS' => $eq->SERIAL_CHASIS,
                'PLACA' => $eq->documentacion->PLACA ?? 'S/P',
                'MARCA' => $eq->MARCA,
                'MODELO' => $eq->MODELO,
                'ANIO' => $eq->ANIO,
                'FRENTE_ACTUAL' => $eq->frenteActual->NOMBRE_FRENTE ?? 'Sin Asignar',
                'FRENTE_ACTUAL_ESTATUS' => $eq->frenteActual->ESTATUS_FRENTE ?? null,
                'CONFIRMADO' => $eq->CONFIRMADO_EN_SITIO,
                'DETALLE_UBICACION' => $eq->DETALLE_UBICACION_ACTUAL,
                'FOTO' => $foto, // URL de foto del equipo o referencial
            ];
        }));
    }

    /**
     * API: Obtener subdivisiones de un frente
     */
    public function getSubdivisiones($id)
    {
        $frente = FrenteTrabajo::findOrFail($id);
        $subdivisiones = [];
        if ($frente->SUBDIVISIONES && trim($frente->SUBDIVISIONES) !== '') {
            $subdivisiones = array_filter(array_map('trim', explode(',', $frente->SUBDIVISIONES)));
        }
        return response()->json([
            'nombre' => $frente->NOMBRE_FRENTE,
            'subdivisiones' => array_values($subdivisiones),
            'tiene_subdivisiones' => count($subdivisiones) > 0,
        ]);
    }

    /**
     * Generar PDF del Acta de Traslado (Agrupado por CODIGO_CONTROL)
     */
    public function generarActaTraslado($id)
    {
        try {
            $baseMov = Movilizacion::findOrFail($id);

            $movilizaciones = Movilizacion::with([
                'equipo.tipo',
                'equipo.documentacion',
                'equipo.especificaciones',
                'frenteOrigen',
                'frenteDestino',
                'usuario'
            ])
                ->where('CODIGO_CONTROL', $baseMov->CODIGO_CONTROL)
                ->get();

            if ($movilizaciones->isEmpty()) {
                return back()->withErrors(['error' => 'No se encontraron registros para esta movilización.']);
            }

            $movilizacion = $movilizaciones->first();

            $frenteOrigen = FrenteTrabajo::find($movilizacion->ID_FRENTE_ORIGEN);
            $frenteDestino = FrenteTrabajo::find($movilizacion->ID_FRENTE_DESTINO);

            if (!$frenteDestino) {
                return back()->withErrors(['error' => 'No se encontró el frente de destino']);
            }

            $pdf = new ActaTrasladoPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

            $pdf->frenteOrigen = $frenteOrigen->NOMBRE_FRENTE ?? 'OFICINA PRINCIPAL';
            $pdf->setPrintHeader(true);
            $pdf->setPrintFooter(true);
            $pdf->SetMargins(15, 42, 15);  // top=42 para dejar espacio al header nativo
            $pdf->SetHeaderMargin(8);
            $pdf->SetAutoPageBreak(true, 15);
            $pdf->AddPage();
            $pdf->SetFont('helvetica', '', 10);

            $equipos = $movilizaciones->map(function ($mov) {
                return $mov->equipo;
            });

            $html = view('admin.movilizaciones.acta_traslado_pdf', compact('movilizaciones', 'equipos', 'movilizacion', 'frenteOrigen', 'frenteDestino'))->render();

            $html = str_replace("this.closest('div[style*='position: fixed']').remove();", "", $html);

            $pdf->writeHTML($html, true, false, true, false, '');

            $filename = 'Acta_Traslado_' . $movilizacion->CODIGO_CONTROL . '.pdf';

            return $pdf->Output($filename, 'D');

        } catch (\Exception $e) {
            \Log::error('Error generando Acta de Traslado: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Error al generar el acta: ' . $e->getMessage()]);
        }
    }

    // ─── MOBILE API ────────────────────────────────────────────────────────────
    public function mobileIndex(Request $request)
    {
        $movs = Movilizacion::with(['equipo.tipo', 'equipo.documentacion', 'frenteOrigen', 'frenteDestino'])
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();

        return response()->json($movs->map(function ($m) {
            return [
                'ID_MOVILIZACION'  => $m->ID_MOVILIZACION,
                'CODIGO_CONTROL'   => $m->CODIGO_CONTROL,
                'ESTADO_MVO'       => $m->ESTADO_MVO,
                'TIPO_MOVIMIENTO'  => $m->TIPO_MOVIMIENTO,
                'FECHA_DESPACHO'   => $m->FECHA_DESPACHO,
                'FECHA_RECEPCION'  => $m->FECHA_RECEPCION,
                'equipo' => $m->equipo ? [
                    'ID_EQUIPO'     => $m->equipo->ID_EQUIPO,
                    'CODIGO_PATIO'  => $m->equipo->CODIGO_PATIO,
                    'SERIAL_CHASIS' => $m->equipo->SERIAL_CHASIS,
                    'MARCA'         => $m->equipo->MARCA,
                    'MODELO'        => $m->equipo->MODELO,
                    'TIPO'          => $m->equipo->tipo->nombre ?? 'N/A',
                    'PLACA'         => $m->equipo->documentacion->PLACA ?? 'S/P',
                ] : null,
                'frente_origen'  => $m->frenteOrigen ? ['ID_FRENTE' => $m->frenteOrigen->ID_FRENTE, 'NOMBRE_FRENTE' => $m->frenteOrigen->NOMBRE_FRENTE] : null,
                'frente_destino' => $m->frenteDestino ? ['ID_FRENTE' => $m->frenteDestino->ID_FRENTE, 'NOMBRE_FRENTE' => $m->frenteDestino->NOMBRE_FRENTE] : null,
            ];
        }));
    }

    public function mobileStore(Request $request)
    {
        $tipo = $request->input('tipo', 'despacho');
        $usuario = $request->user();

        if ($tipo === 'recepcion_directa') {
            return $this->recepcionDirecta($request);
        }

        $request->validate([
            'ID_EQUIPO'         => 'required|exists:equipos,ID_EQUIPO',
            'ID_FRENTE_DESTINO' => 'required|exists:frentes_trabajo,ID_FRENTE',
        ]);

        $equipo  = \App\Models\Equipo::findOrFail($request->ID_EQUIPO);
        $lastLog = Movilizacion::latest('ID_MOVILIZACION')->first();
        $nextId  = $lastLog ? ($lastLog->ID_MOVILIZACION + 1) : 1;

        $now = now();
        Movilizacion::create([
            'CODIGO_CONTROL'    => $nextId,
            'ID_EQUIPO'         => $request->ID_EQUIPO,
            'ID_FRENTE_ORIGEN'  => $equipo->ID_FRENTE_ACTUAL ?? 1,
            'ID_FRENTE_DESTINO' => $request->ID_FRENTE_DESTINO,
            'FECHA_DESPACHO'    => $now,
            'ESTADO_MVO'        => 'RECIBIDO',
            'TIPO_MOVIMIENTO'   => 'DESPACHO',
            'USUARIO_REGISTRO'  => $usuario->CORREO_ELECTRONICO ?? 'SISTEMA',
            'USUARIO_RECEPCION' => $usuario->CORREO_ELECTRONICO ?? 'SISTEMA',
        ]);

        // Al despachar a otro frente, llega instantaneamente
        $equipo->update([
            'ID_FRENTE_ACTUAL'         => $request->ID_FRENTE_DESTINO,
            'DETALLE_UBICACION_ACTUAL' => null,
            'CONFIRMADO_EN_SITIO'      => 1,
        ]);

        return response()->json(['success' => true, 'message' => 'Despacho registrado correctamente.']);
    }

    public function destroy($id)
    {
        if (!auth()->user()->can('super.admin')) {
            return response()->json(['success' => false, 'message' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        try {
            $mov = Movilizacion::findOrFail($id);
            $mov->delete();
            return response()->json(['success' => true, 'message' => 'Registro de movilización eliminado con éxito.']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al eliminar el registro: ' . $e->getMessage()], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────

} // END MovilizacionController


class ActaTrasladoPDF extends \TCPDF
{
    public $frenteOrigen = '';

    public function Header()
    {
        $image_file = public_path('img/imagen_uno.jpg');
        if (file_exists($image_file)) {
            $this->Image($image_file, 15, 8, 0, 25, 'JPG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }

        $this->SetFont('helvetica', '', 8.5);
        $frente = strtoupper($this->frenteOrigen ?: 'OFICINA PRINCIPAL');
        $html = '<div style="text-align: right; line-height: 1.8;"><strong>FECHA DE EMISI&Oacute;N:</strong> ' . \Carbon\Carbon::now()->format('d/m/Y') . '<br><strong>FRENTE DE ORIGEN:</strong> ' . $frente . '<br>EMITIDO POR SISTEMA DE GESTI&Oacute;N DE FLOTA</div>';
        $this->writeHTMLCell(0, 0, 15, 20, $html, 0, 1, 0, true, 'R', true);
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Página ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}
