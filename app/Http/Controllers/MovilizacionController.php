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
        // Permiso para MOVER equipos (Crear movilizaciones o registrar recepcion directa sin despacho previo)
        $this->middleware('can:equipos.assign')->only(['create', 'store', 'bulkStore', 'recepcionDirecta']);
        // Borrar movilizaciones es destructivo: solo super.admin (consistente con el modulo de equipos).
        $this->middleware('can:super.admin')->only(['destroy', 'bulkDestroy']);
    }

    public function index(Request $request)
    {
        // Frente filter logic: LOCAL users always see their frente (locked).
        // GLOBAL users get their frente as default ONLY on the initial page load (non-AJAX).
        // When a GLOBAL user clears the filter (AJAX request), we respect the empty value.
        $user = auth()->user();
        $isLocalUser = $user && $user->NIVEL_ACCESO == 2;
        $frentesPermitidos = $user ? $user->getFrentesIds() : [];


        $query = Movilizacion::with([
            'equipo.tipo',
            'equipo.especificaciones:ID_ESPEC,COMBUSTIBLE,CONSUMO_PROMEDIO,FOTO_REFERENCIAL',
            'equipo.documentacion',
            // Cargar tambien el aux cuando la movilizacion sea de un auxiliar
            // (ID_AUXILIAR != null). Asi el listado renderiza vehiculos y
            // auxiliares en la misma tabla, eligiendo en el partial cual
            // mostrar segun cual de los dos venga poblado.
            'auxiliar:ID_AUXILIAR,TIPO,MARCA,MODELO,SERIAL,FOTO',
            'frenteOrigen',
            'frenteDestino',
            'usuario',
        ]);

        // Eliminada la barrera de seguridad de usuario local. Todos ven todo.

        // â”€â”€â”€ BÃºsqueda de texto â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        // Usa whereHas() para evitar LEFT JOINs que generan columnas ambiguas
        // (created_at, updated_at, etc.) y filas duplicadas en la paginaciÃ³n.
        if ($request->filled('search')) {
            $search      = trim($request->search);
            $searchUpper = strtoupper($search);

            $query->where(function ($q) use ($search, $searchUpper) {

                // PatrÃ³n 1: MV-XXXXX / MVXXXXX â†’ buscar CODIGO_CONTROL
                if (preg_match('/^MV-?\d+/i', $search)) {
                    $clean = ltrim(str_replace(['MV-', 'MV'], '', $searchUpper), '0');
                    $q->where('movilizacion_historial.CODIGO_CONTROL', 'like', "%{$searchUpper}%")
                      ->orWhere('movilizacion_historial.CODIGO_CONTROL', 'like', "%{$clean}%");

                // PatrÃ³n 2: DD-MM-YYYY â†’ buscar CODIGO_PATIO
                } elseif (preg_match('/\d{2}-\d{2}-\d{4}/', $search)) {
                    $q->whereHas('equipo', fn ($qEq) =>
                        $qEq->where('CODIGO_PATIO', 'like', "%{$search}%")
                    );

                // PatrÃ³n 3: #NÃšMERO â†’ buscar NUMERO_ETIQUETA
                } elseif (strpos($search, '#') === 0) {
                    $tag = ltrim($search, '#');
                    $q->whereHas('equipo', fn ($qEq) =>
                        $qEq->where('NUMERO_ETIQUETA', 'like', "%{$tag}%")
                    );

                // PatrÃ³n por defecto: serial / placa / codigo (equipo) o
                // serial / marca / modelo (aux). El OR cruzado permite que
                // movilizaciones de auxiliares aparezcan en busquedas por
                // texto libre igual que las de equipos.
                } else {
                    $q->where(function ($qInner) use ($searchUpper) {
                        $qInner->whereHas('equipo', function ($qEq) use ($searchUpper) {
                            $qEq->where('SERIAL_CHASIS', 'like', "%{$searchUpper}%")
                                ->orWhere('CODIGO_PATIO', 'like', "%{$searchUpper}%");
                        })->orWhereHas('equipo.documentacion', function ($qDoc) use ($searchUpper) {
                            $qDoc->where('PLACA', 'like', "%{$searchUpper}%");
                        })->orWhereHas('auxiliar', function ($qAux) use ($searchUpper) {
                            $qAux->where('SERIAL', 'like', "%{$searchUpper}%")
                                 ->orWhere('MARCA', 'like', "%{$searchUpper}%")
                                 ->orWhere('MODELO', 'like', "%{$searchUpper}%");
                        });
                    });
                }
            });
        }


        // â”€â”€â”€ SHARED filter logic (applied to both main query and stats query) â”€â”€â”€â”€â”€â”€â”€
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

        // â”€â”€â”€ Apply shared filters to main query â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

        // Fetch paginated results sin puntos suspensivos (mostrando hasta 50 pÃ¡ginas continuas)
        $movilizaciones = $query->orderBy('movilizacion_historial.created_at', 'desc')->paginate(12);

        $totalTransito = $movilizaciones->total();

        // Mostramos TODOS los frentes en el historial (activos y finalizados)
        // porque se necesita poder buscar movilizaciones de frentes antiguos.
        $frentes = FrenteTrabajo::orderBy('NOMBRE_FRENTE')->get();
        $allTipos = \App\Models\TipoEquipo::orderBy('nombre')->get();

        if ($request->wantsJson()) {
            $tableHtml = view('admin.movilizaciones.partials.table_rows', compact('movilizaciones'))->render();
            $paginationHtml = $movilizaciones->appends($request->all())->links('vendor.pagination.custom-sliding')->toHtml();

            return response()->json([
                'html' => $tableHtml,
                'pagination' => $paginationHtml,
                'statsHtml' => '',
                'totalTransito' => $totalTransito
            ]);
        }

        return view('admin.movilizaciones.index', compact('movilizaciones', 'totalTransito', 'frentes', 'allTipos'));
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

    /**
     * Busca la movilizacion mas reciente asociada al CODIGO_CONTROL dado
     * y devuelve su ID para que el frontend dispare la descarga del acta PDF.
     * Soporta codigos con o sin ceros a la izquierda (ej. "125" y "000125").
     */
    public function findByCodigoControl(Request $request)
    {
        $codigo = trim((string) $request->query('codigo', ''));
        if ($codigo === '') {
            return response()->json(['success' => false, 'message' => 'Debes indicar el NÂ° de OperaciÃ³n.'], 422);
        }

        // Extraer solo la parte numérica para soportar formatos "MV-00125", "00125" o "125"
        $numericPart = preg_replace('/[^0-9]/', '', $codigo);
        if ($numericPart === '') {
            $numericPart = '0';
        }
        $numericInt = (int)$numericPart;

        // Construir variaciones posibles (antiguos string literal y nuevos int)
        $padded5 = str_pad((string)$numericInt, 5, '0', STR_PAD_LEFT);
        $padded6 = str_pad((string)$numericInt, 6, '0', STR_PAD_LEFT);
        $mvPadded5 = 'MV-' . $padded5;
        $mvPadded6 = 'MV-' . $padded6;

        // Acceso controlado por permisos (no por NIVEL_ACCESO). Cualquier
        // usuario autenticado puede buscar un acta por codigo de control.
        $query = Movilizacion::query();

        $mov = (clone $query)
            ->whereIn('CODIGO_CONTROL', [
                $codigo,
                (string)$numericInt,
                $padded5,
                $padded6,
                $mvPadded5,
                $mvPadded6
            ])
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$mov) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontrÃ³ ninguna movilizaciÃ³n con ese NÂ° de OperaciÃ³n.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'id'      => $mov->ID_MOVILIZACION,
            'codigo'  => $mov->CODIGO_CONTROL,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'ID_EQUIPO' => 'required|exists:equipos,ID_EQUIPO',
            'ID_FRENTE_DESTINO' => 'required|exists:frentes_trabajo,ID_FRENTE',
        ]);

        DB::beginTransaction();
        try {
            $equipo = \App\Models\Equipo::lockForUpdate()->findOrFail($request->ID_EQUIPO);

            $nextId = self::generateNextCodigoControl();

            $origen = $equipo->ID_FRENTE_ACTUAL ?? 1;
            $now = now();
            
            Movilizacion::create([
                'CODIGO_CONTROL' => $nextId,
                'ID_EQUIPO' => $request->ID_EQUIPO,
                'ID_FRENTE_ORIGEN' => $origen,
                'ID_FRENTE_DESTINO' => $request->ID_FRENTE_DESTINO,
                'FECHA_DESPACHO' => $now,
                'TIPO_MOVIMIENTO' => 'DESPACHO',
                'USUARIO_REGISTRO' => auth()->user()->CORREO_ELECTRONICO ?? 'SISTEMA',
            ]);

            $equipo->update([
                'ID_FRENTE_ACTUAL' => $request->ID_FRENTE_DESTINO,
                'DETALLE_UBICACION_ACTUAL' => null,
                'CONFIRMADO_EN_SITIO' => 1
            ]);

            DB::commit();
            return redirect()->route('movilizaciones.index')->with('success', 'Movilizacion registrada correctamente.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('store movilizacion error: ' . $e->getMessage());
            return back()->withErrors(['error' => 'No se pudo registrar la movilizacion. Intenta de nuevo.']);
        }
    }

    public function bulkStore(Request $request)
    {
        $request->validate([
            'ids'                  => 'required|array|min:1',
            'ids.*'                => 'exists:equipos,ID_EQUIPO',
            'destination'          => 'required|string|max:255',
            'destination_ubicacion'=> 'nullable|string|max:150',
            'generar_pdf'          => 'boolean',
        ]);

        $authUser = auth()->user();

        DB::beginTransaction();
        try {
            $destNombre    = strtoupper(trim($request->destination));
            $destUbicacion = trim((string) $request->input('destination_ubicacion', ''));

            // Buscar el frente existente (puede tener UBICACION vacía en BD).
            $frenteExistente = FrenteTrabajo::where('NOMBRE_FRENTE', $destNombre)->first();
            $frenteNecesitaUbicacion = !$frenteExistente || empty(trim((string)($frenteExistente->UBICACION ?? '')));

            // Guardia backend: si el frente no tiene ubicación (nuevo O viejo sin ella),
            // se exige que el usuario la proporcione. El frontend ya lo valida; este guard
            // previene llamadas directas al endpoint que intenten saltarse la regla.
            if ($frenteNecesitaUbicacion && $destUbicacion === '') {
                DB::rollBack();
                $msg = $frenteExistente
                    ? 'El frente "' . $destNombre . '" no tiene ubicación registrada. Debes indicar ciudad, zona, municipio y estado para el PDF.'
                    : 'El frente "' . $destNombre . '" no existe. Debes indicar su ubicación (zona, municipio o estado) para crearlo.';
                return response()->json(['success' => false, 'message' => $msg], 422);
            }

            // Crear el frente si no existe, o recuperar el existente.
            $frente = FrenteTrabajo::firstOrCreate(
                ['NOMBRE_FRENTE' => $destNombre],
                [
                    'ESTATUS_FRENTE' => 'ACTIVO',
                    'UBICACION'      => $destUbicacion !== '' ? strtoupper($destUbicacion) : null,
                ]
            );

            // ── FIX CRÍTICO: firstOrCreate SOLO aplica los valores al CREAR.
            // Si el frente ya existía pero sin UBICACION, y el usuario proporcionó una,
            // hay que guardarla explícitamente ahora. Sin este bloque la ubicacion
            // se pierde silenciosamente en frentes viejos sin UBICACION registrada.
            if (!$frente->wasRecentlyCreated && $destUbicacion !== '' && empty(trim((string)($frente->UBICACION ?? '')))) {
                $frente->UBICACION = strtoupper($destUbicacion);
                $frente->save();
            }

            // Acceso a bulkStore se controla UNICAMENTE con el permiso
            // 'equipos.assign' (middleware del controller + @can en UI). El
            // campo NIVEL_ACCESO del usuario NO limita la operacion â€” la
            // filosofia del sistema es "solo la clave PERMISOS decide"
            // (ver AppServiceProvider::boot).

            $userEmail  = $authUser->CORREO_ELECTRONICO ?? 'SISTEMA';
            $now        = now();
            $generarPdf = (bool) $request->input('generar_pdf', true);

            // Bloquear los equipos PRIMERO. Si por algun motivo (race con destroy,
            // ids fantasma) la coleccion queda vacia abortamos antes de consumir
            // un numero de la secuencia (evita huecos en CODIGO_CONTROL).
            $equipos = \App\Models\Equipo::whereIn('ID_EQUIPO', $request->ids)
                ->lockForUpdate()
                ->get(['ID_EQUIPO', 'ID_FRENTE_ACTUAL']);

            if ($equipos->isEmpty()) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'No se encontraron equipos validos para movilizar.'], 422);
            }

            $nextId = $generarPdf ? self::generateNextCodigoControl() : null;

            // Crear movilizaciones una por una (dispara MovilizacionObserver, devuelve IDs exactos
            // sin depender de timestamp match entre Carbon Âµs y MySQL TIMESTAMP sin fracciÃ³n).
            $movilizacionIds = [];
            foreach ($equipos as $equipo) {
                $mov = Movilizacion::create([
                    'CODIGO_CONTROL'    => $generarPdf ? $nextId : null,
                    'ID_EQUIPO'         => $equipo->ID_EQUIPO,
                    'ID_FRENTE_ORIGEN'  => $equipo->ID_FRENTE_ACTUAL ?? 1,
                    'ID_FRENTE_DESTINO' => $frente->ID_FRENTE,
                    'FECHA_DESPACHO'    => $generarPdf ? $now : null,
                    'TIPO_MOVIMIENTO'   => $generarPdf ? 'DESPACHO' : 'ACT.',
                    'USUARIO_REGISTRO'  => $userEmail,
                ]);
                $movilizacionIds[] = $mov->ID_MOVILIZACION;
            }

            \App\Models\Equipo::whereIn('ID_EQUIPO', $request->ids)->update([
                'ID_FRENTE_ACTUAL'         => $frente->ID_FRENTE,
                'CONFIRMADO_EN_SITIO'      => 1,
                'DETALLE_UBICACION_ACTUAL' => null,
            ]);

            DB::commit();
            // No llamamos triggerDashboardCacheRefresh aqui: Movilizacion::create()
            // dispara MovilizacionObserver::created (afterCommit=true) que ya
            // refresca los caches. recepcionDirecta SI lo necesita porque usa
            // insert() que no dispara eventos.

            return response()->json([
                'success'          => true,
                'movilizacion_ids' => $movilizacionIds,
                'count'            => count($movilizacionIds),
                'generar_pdf'      => $generarPdf,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('bulkStore movilizacion error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'No se pudo registrar la movilizacion.'], 500);
        }
    }



    /**
     * RECEPCIÃ“N DIRECTA: Registrar equipos que llegan sin movilizaciÃ³n previa
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

        // Acceso controlado UNICAMENTE por el permiso 'equipos.assign' (middleware
        // del controller). NIVEL_ACCESO del usuario NO restringe el frente destino.

        DB::beginTransaction();
        try {
            $now = now();
            $frenteDestino = FrenteTrabajo::findOrFail($request->ID_FRENTE_DESTINO);

            // Sin `with('frenteActual')` â€” solo usamos ID_FRENTE_ACTUAL directo, no la relacion.
            $equipos = \App\Models\Equipo::whereIn('ID_EQUIPO', $request->ids)
                ->lockForUpdate()
                ->get(['ID_EQUIPO', 'ID_FRENTE_ACTUAL']);

            $insertData = [];
            foreach ($equipos as $equipo) {
                $insertData[] = [
                    'CODIGO_CONTROL' => null, // Recepciones directas no tienen cÃ³digo de control
                    'ID_EQUIPO' => $equipo->ID_EQUIPO,
                    'ID_FRENTE_ORIGEN' => $equipo->ID_FRENTE_ACTUAL ?? $request->ID_FRENTE_DESTINO,
                    'ID_FRENTE_DESTINO' => $request->ID_FRENTE_DESTINO,
                    'DETALLE_UBICACION' => $request->DETALLE_UBICACION,
                    'FECHA_DESPACHO' => null, // No hubo despacho
                    'TIPO_MOVIMIENTO' => 'RECEPCION_DIRECTA',
                    'USUARIO_REGISTRO' => $usuario->CORREO_ELECTRONICO ?? 'SISTEMA',
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
            $this->triggerDashboardCacheRefresh();

            $ubicacionTexto = $frenteDestino->NOMBRE_FRENTE;
            if ($request->filled('DETALLE_UBICACION')) {
                $ubicacionTexto .= ' â†’ ' . $request->DETALLE_UBICACION;
            }

            return response()->json([
                'success' => true,
                'message' => count($request->ids) . ' equipo(s) recibido(s) directamente en ' . $ubicacionTexto,
                'count' => count($request->ids),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error en recepcionDirecta: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'No se pudo registrar la recepcion directa.'], 500);
        }
    }

    /**
     * API: Buscar equipos para recepciÃ³n directa
     */
    public function buscarEquiposParaRecepcion(Request $request)
    {
        $query = \App\Models\Equipo::with(['tipo', 'frenteActual', 'documentacion', 'especificaciones:ID_ESPEC,FOTO_REFERENCIAL']);

        // Scope LOCAL: el usuario solo ve equipos de los frentes que tiene asignados.
        // Sin este scope, un usuario local podria buscar (y ver PLACA) de cualquier
        // equipo del sistema, contradiciendo la politica aplicada en los otros flujos.
        $user    = auth()->user();
        $isLocal = $user && $user->NIVEL_ACCESO == 2;
        if ($isLocal) {
            $permitidos = $user->getFrentesIds();
            if (empty($permitidos)) {
                return response()->json([]);
            }
            $query->whereIn('ID_FRENTE_ACTUAL', $permitidos);
        }

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
                // Standard search â€” O/0 ambiguity applied ONLY to PLACA
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
        // PDFs grandes (muchos equipos) pueden tardar mÃ¡s del default de 30s
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        try {
            $baseMov = Movilizacion::findOrFail($id);

            // Acceso a la descarga del acta: controlado solo por autenticacion
            // (middleware 'auth'). NIVEL_ACCESO del usuario no restringe â€”
            // cualquier usuario autenticado puede descargar el acta PDF.

            // Para tandas sin CODIGO_CONTROL (recepciones directas / actualizaciones)
            // no tiene sentido generar acta agrupada.
            if (empty($baseMov->CODIGO_CONTROL)) {
                return back()->withErrors(['error' => 'Esta movilizaciÃ³n no tiene acta asociada (actualizaciÃ³n o recepciÃ³n directa).']);
            }

            // Agrupacion del bundle: SOLO por CODIGO_CONTROL + destino. El CODIGO_CONTROL
            // ya garantiza unicidad por bundle gracias a la secuencia con lockForUpdate.
            // Antes se filtraba tambien por UNIX_TIMESTAMP(created_at) — eso fallaba en
            // bulks lentos (>1s entre primer y ultimo create), dejando equipos fuera del
            // PDF. ID_FRENTE_DESTINO se mantiene como guardia defensiva.
            $movilizaciones = Movilizacion::with([
                'equipo.tipo',
                'equipo.documentacion',
                'equipo.especificaciones',
                'auxiliar',
                'frenteOrigen',
                'frenteDestino',
                'usuario'
            ])
                ->where('CODIGO_CONTROL', $baseMov->CODIGO_CONTROL)
                ->where('ID_FRENTE_DESTINO', $baseMov->ID_FRENTE_DESTINO)
                ->get();

            // Para movilizaciones de auxiliares (ID_EQUIPO null + ID_AUXILIAR set),
            // sintetizamos un objeto Equipo-like a partir del auxiliar para que el
            // resto del flujo (vista acta_traslado_pdf, $equipos collection, etc)
            // siga funcionando sin reescribir la plantilla. Campos no existentes
            // en aux (PLACA, ANIO etc) quedan en cadena vacia.
            foreach ($movilizaciones as $mov) {
                if (!$mov->equipo && $mov->auxiliar) {
                    $a = $mov->auxiliar;
                    $synthetic = new \stdClass();
                    $synthetic->ID_EQUIPO       = null;
                    $synthetic->CODIGO_PATIO    = $a->CODIGO_INTERNO ?: $a->SERIAL;
                    $synthetic->SERIAL_CHASIS   = $a->SERIAL ?: 'â€”';
                    $synthetic->SERIAL_DE_MOTOR = '';
                    $synthetic->MARCA           = $a->MARCA ?: '';
                    $synthetic->MODELO          = $a->MODELO ?: '';
                    $synthetic->ANIO            = $a->ANIO ?? '';
                    $synthetic->NUMERO_ETIQUETA = '';
                    $synthetic->ESTADO_OPERATIVO= $a->ESTADO_OPERATIVO ?? 'OPERATIVO';
                    $synthetic->FOTO_EQUIPO     = $a->FOTO ?? null;
                    $synthetic->tipo            = (object) ['nombre' => $a->TIPO ?? 'AUXILIAR'];
                    $synthetic->documentacion   = (object) ['PLACA' => 'S/P'];
                    $synthetic->especificaciones= null;
                    // CATEGORIA_FLOTA = 'FLOTA LIVIANA' hace que los responsables del frente
                    // configurados con RESP_N_EQU='FLOTA LIVIANA' (o vacÃ­o) pasen el filtro
                    // en acta_traslado_pdf igual que para vehiculos livianos.
                    // Antes era 'AUXILIAR' pero ningÃºn RESP_N_EQU tiene ese valor en BD,
                    // causando que RESP_1 (Coordinador Liviana) quedara fuera y RESP_2
                    // (elaborador) recibiera la etiqueta 'SOLICITADO:' incorrectamente.
                    $synthetic->CATEGORIA_FLOTA = 'FLOTA LIVIANA';
                    // Inyectamos en el modelo para que las refs ->equipo en la
                    // plantilla y el map() de abajo funcionen igual.
                    $mov->setRelation('equipo', $synthetic);
                }
            }

            if ($movilizaciones->isEmpty()) {
                return back()->withErrors(['error' => 'No se encontraron registros para esta movilizaciÃ³n.']);
            }

            $movilizacion = $movilizaciones->first();

            $frenteOrigen = FrenteTrabajo::find($movilizacion->ID_FRENTE_ORIGEN);
            $frenteDestino = FrenteTrabajo::find($movilizacion->ID_FRENTE_DESTINO);

            if (!$frenteDestino) {
                return back()->withErrors(['error' => 'No se encontrÃ³ el frente de destino']);
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

    // â”€â”€â”€ MOBILE API â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
                'TIPO_MOVIMIENTO'  => $m->TIPO_MOVIMIENTO,
                'FECHA_DESPACHO'   => $m->FECHA_DESPACHO,
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

        DB::beginTransaction();
        try {
            $equipo  = \App\Models\Equipo::lockForUpdate()->findOrFail($request->ID_EQUIPO);
            $nextId = self::generateNextCodigoControl();

            $now = now();
            Movilizacion::create([
                'CODIGO_CONTROL'    => $nextId,
                'ID_EQUIPO'         => $request->ID_EQUIPO,
                'ID_FRENTE_ORIGEN'  => $equipo->ID_FRENTE_ACTUAL ?? 1,
                'ID_FRENTE_DESTINO' => $request->ID_FRENTE_DESTINO,
                'FECHA_DESPACHO'    => $now,
                'TIPO_MOVIMIENTO'   => 'DESPACHO',
                'USUARIO_REGISTRO'  => $usuario->CORREO_ELECTRONICO ?? 'SISTEMA',
            ]);

            $equipo->update([
                'ID_FRENTE_ACTUAL'         => $request->ID_FRENTE_DESTINO,
                'DETALLE_UBICACION_ACTUAL' => null,
                'CONFIRMADO_EN_SITIO'      => 1,
            ]);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Despacho registrado correctamente.']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('mobileStore movilizacion error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'No se pudo registrar el despacho.'], 500);
        }
    }

    public function destroy($id)
    {
        // Permiso super.admin gateado en el middleware del constructor.
        try {
            $mov = Movilizacion::findOrFail($id);
            $mov->delete();
            return response()->json(['success' => true, 'message' => 'Registro de movilizacion eliminado con exito.']);
        } catch (\Exception $e) {
            Log::error('destroy movilizacion error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'No se pudo eliminar el registro.'], 500);
        }
    }

    public function bulkDestroy(Request $request)
    {
        // Permiso super.admin gateado en el middleware del constructor.
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:movilizacion_historial,ID_MOVILIZACION',
        ]);

        try {
            Movilizacion::whereIn('ID_MOVILIZACION', $request->ids)->delete();
            return response()->json(['success' => true, 'message' => count($request->ids) . ' registros eliminados con exito.']);
        } catch (\Exception $e) {
            Log::error('bulkDestroy movilizacion error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'No se pudieron eliminar los registros.'], 500);
        }
    }

    // â”€â”€â”€ HELPERS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Genera el siguiente CODIGO_CONTROL de forma atomica.
     * DEBE ser llamado dentro de una transaccion activa (DB::beginTransaction()).
     * Lanza LogicException si se llama fuera de transaccion: sin transaccion el
     * lockForUpdate() es silenciosamente ignorado y dos calls concurrentes podrian
     * producir el mismo numero.
     */
    public static function generateNextCodigoControl(): int
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException(
                'generateNextCodigoControl() debe llamarse dentro de DB::transaction() '
                . 'o DB::beginTransaction(). Sin transaccion el lock no funciona.'
            );
        }

        // ── BLOQUEO ATOMICO DE FILA ──────────────────────────────────────────
        // lockForUpdate() sobre una PRIMARY KEY especifica bloquea exactamente esa
        // fila en InnoDB. Si dos transacciones llegan al mismo tiempo, la segunda
        // espera hasta que la primera haga commit/rollback. Asi NUNCA se repite un
        // CODIGO_CONTROL, sin importar cuantos usuarios operen en simultaneo.
        $seq = DB::table('secuencias')
            ->where('nombre', 'CODIGO_CONTROL')
            ->lockForUpdate()
            ->first();

        if (!$seq) {
            // Fallback de seguridad: si la fila no existe (ej: BD nueva sin migrar)
            // Usamos CAST para evitar MAX lexicogrÃ¡fico en columna varchar.
            $maxExistente = (int) DB::selectOne("SELECT MAX(CAST(CODIGO_CONTROL AS UNSIGNED)) as m FROM movilizacion_historial")->m ?: 0;
            DB::table('secuencias')->insert([
                'nombre'     => 'CODIGO_CONTROL',
                'valor'      => $maxExistente,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $nuevoValor = $maxExistente + 1;
        } else {
            $nuevoValor = (int) $seq->valor + 1;
        }

        DB::table('secuencias')
            ->where('nombre', 'CODIGO_CONTROL')
            ->update(['valor' => $nuevoValor, 'updated_at' => now()]);

        return $nuevoValor;
    }

    /**
     * Fuerza la actualizaciÃ³n del cache (Ãºtil cuando se usa Movilizacion::insert que no dispara eventos Eloquent).
     */
    private function triggerDashboardCacheRefresh()
    {
        try {
            $observer = new \App\Observers\MovilizacionObserver();
            $observer->created(new Movilizacion());
        } catch (\Exception $e) {
            \Log::error('Error refrescando cache de dashboard en inserciones masivas: ' . $e->getMessage());
        }
    }

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

        // RIF debajo del logo en letra pequeÃ±a
        $this->SetFont('helvetica', '', 7);
        $this->writeHTMLCell(50, 0, 19, 33, '<div style="text-align:left; color:#444444; font-size:7pt;">RIF: J-29387719-3</div>', 0, 0, 0, true, 'L', true);

        $this->SetFont('helvetica', '', 8.5);
        $frente = strtoupper($this->frenteOrigen ?: 'OFICINA PRINCIPAL');
        $html = '<div style="text-align: right; line-height: 1.8;"><strong>FECHA DE EMISI&Oacute;N:</strong> ' . \Carbon\Carbon::now()->format('d/m/Y') . '<br><strong>FRENTE DE ORIGEN:</strong> ' . $frente . '<br>EMITIDO POR SISTEMA DE GESTI&Oacute;N DE FLOTA</div>';
        $this->writeHTMLCell(0, 0, 15, 20, $html, 0, 1, 0, true, 'R', true);
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        // Usar writeHTMLCell en vez de Cell(): Cell() no procesa UTF-8 con
        // helvetica, causando que 'á' (U+00E1) se muestre como 'Ã¡'.
        // La entidad HTML &aacute; es resuelta correctamente por TCPDF.
        $footerHtml = '<div style="text-align:right; font-style:italic; font-size:8pt;">'
            . 'P&aacute;gina ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages()
            . '</div>';
        $this->writeHTMLCell(0, 10, $this->getMargins()['left'], $this->GetY(), $footerHtml, 0, 0, false, true, 'R', true);
    }
}
