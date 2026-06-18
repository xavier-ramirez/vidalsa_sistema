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
        // Borrar/deshacer movilizaciones es destructivo: solo super.admin (consistente con el modulo de equipos).
        $this->middleware('can:super.admin')->only(['destroy', 'bulkDestroy', 'deshacer']);
    }

    public function index(Request $request)
    {
        // Sin scope LOCAL: todos los usuarios autenticados ven todo el historial
        // de movilizaciones. La accion destructiva (borrar) ya esta gateada por
        // can:super.admin en el middleware del constructor.

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
        $movilizaciones = $query->orderBy('movilizacion_historial.created_at', 'desc')->paginate(16);

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

            // ID_FRENTE_ACTUAL NO es fillable (ver Equipo::$fillable): asignación por
            // propiedad + save(), NO update([...]) que lo descartaría en silencio y el
            // equipo no se movería al frente destino.
            $equipo->ID_FRENTE_ACTUAL         = $request->ID_FRENTE_DESTINO;
            $equipo->DETALLE_UBICACION_ACTUAL = null;
            // Despacho → pendiente de confirmar en el frente destino (se tilda al llegar).
            $equipo->CONFIRMADO_EN_SITIO      = 0;
            $equipo->save();

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

            // Omitir los equipos que YA están en el frente destino: no se movilizan (no se
            // les crea movilización ni se actualizan) — ya están ahí. La operación continúa
            // solo con los que cambian de frente. Si TODOS ya estaban, no hay nada que hacer
            // (rollback antes de consumir un CODIGO_CONTROL).
            $aMovilizar = $equipos->filter(
                fn ($e) => (int) $e->ID_FRENTE_ACTUAL !== (int) $frente->ID_FRENTE
            )->values();
            $omitidos = $equipos->count() - $aMovilizar->count();

            if ($aMovilizar->isEmpty()) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Todos los equipos seleccionados ya están en el frente «' . $destNombre . '». No hay nada que movilizar.',
                ], 422);
            }

            $nextId = $generarPdf ? self::generateNextCodigoControl() : null;

            // Crear movilizaciones una por una para obtener IDs exactos
            // (sin depender de timestamp match entre Carbon Âµs y MySQL TIMESTAMP sin fracciÃ³n).
            $movilizacionIds = [];
            foreach ($aMovilizar as $equipo) {
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

            \App\Models\Equipo::whereIn('ID_EQUIPO', $aMovilizar->pluck('ID_EQUIPO'))->update([
                'ID_FRENTE_ACTUAL'         => $frente->ID_FRENTE,
                // Despacho → queda PENDIENTE de confirmar en el frente destino (el usuario
                // lo tilda físicamente al llegar). Antes se ponía 1 (auto-confirmado).
                'CONFIRMADO_EN_SITIO'      => 0,
                'DETALLE_UBICACION_ACTUAL' => null,
            ]);

            DB::commit();

            return response()->json([
                'success'          => true,
                'movilizacion_ids' => $movilizacionIds,
                'count'            => count($movilizacionIds),
                'omitidos'         => $omitidos, // ya estaban en el frente destino → no se movilizaron
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

        // Scope LOCAL: el usuario solo ve equipos de los frentes asignados (barrera
        // centralizada en Usuario::aplicarScopeFrentes — global ve todo; local sin
        // frentes no ve nada → query vacío → []). Sin esto, un local podría buscar y
        // ver PLACA de cualquier equipo, contradiciendo el resto de los flujos.
        $user = auth()->user();
        if ($user) {
            $user->aplicarScopeFrentes($query, 'ID_FRENTE_ACTUAL');
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
    public function generarActaTraslado(Request $request, $id)
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

            // ── Frente de origen del acta: POR MAYORÍA ──────────────────────────
            // Cada fila de movilizaciones guarda el ID_FRENTE_ORIGEN real del equipo,
            // pero cuando un lote mezcla equipos con distintos orígenes lo más probable
            // es que físicamente estén en el mismo sitio y la BD esté desactualizada
            // para algunos. Tomamos el frente con MÁS equipos en el lote como origen
            // del acta (firmantes, encabezado, etc). Empate → gana el frente del
            // equipo registrado primero en el lote (orden estable del groupBy).
            $idOrigenMayoria = $movilizaciones->groupBy('ID_FRENTE_ORIGEN')
                ->map(fn ($grupo) => $grupo->count())
                ->sortDesc()
                ->keys()
                ->first();
            $frenteOrigen  = FrenteTrabajo::find($idOrigenMayoria ?? $movilizacion->ID_FRENTE_ORIGEN);
            $frenteDestino = FrenteTrabajo::find($movilizacion->ID_FRENTE_DESTINO);

            if (!$frenteDestino) {
                return back()->withErrors(['error' => 'No se encontrÃ³ el frente de destino']);
            }

            // N° de operacion (6 digitos) y fecha del renglon "lugar, fecha".
            $numeroOperacion = str_pad($movilizacion->CODIGO_CONTROL ?? 0, 6, '0', STR_PAD_LEFT);
            $equipos = $movilizaciones->map(function ($mov) {
                return $mov->equipo;
            });
            $fechaActa = optional($movilizacion->FECHA_DESPACHO)->format('d/m/Y')
                ?? \Carbon\Carbon::now()->format('d/m/Y');

            // Ediciones manuales del acta (vista previa → "Editar"): SOLO ajustan lo que
            // se IMPRIME (frente de origen mostrado + firmas), no la BD ni el frente. El
            // destino real y los equipos ya reflejan la edición porque la movilización se
            // registró con ellos (destino re-ruteado / equipos quitados). Las firmas y el
            // origen son cosméticos del documento, por eso van como override aquí.
            $overrideOrigin = trim((string) $request->input('override_origin', ''));
            if ($overrideOrigin !== '') {
                $frenteOrigen = $this->stubFrenteOrigen($overrideOrigin, (string) $request->input('override_origin_zona', ''));
            }
            $firmasOverride = $this->normalizeFirmasOverride($request->input('override_firmas'));

            // Armado del PDF centralizado en buildActaPdfBinary() — lo comparten la
            // descarga real (aqui) y la vista previa desde borrador (previewActaLote).
            $binary = $this->buildActaPdfBinary($equipos, $frenteOrigen, $frenteDestino, $numeroOperacion, $fechaActa, $firmasOverride);

            $filename = 'Acta_Traslado_' . $movilizacion->CODIGO_CONTROL . '.pdf';
            // 'S' => binario inline (Content-Disposition: inline) para que el visor
            // modal lo renderice en iframe; el boton "Descargar" sigue disponible.
            return response($binary, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $filename . '"',
            ]);

        } catch (\Exception $e) {
            Log::error('Error generando Acta de Traslado: ' . $e->getMessage());
            return back()->withErrors(['error' => 'No se pudo generar el acta. Intenta de nuevo.']);
        }
    }

    /**
     * Construye el PDF del Acta de Traslado (binario) a partir de datos ya resueltos.
     * Centralizado: lo usan generarActaTraslado() (descarga real con CODIGO_CONTROL)
     * y previewActaLote() (vista previa desde borrador, numero "PENDIENTE"). Asi NO
     * se duplica el armado/paginacion. Margenes 18/40/18 atados a equiposColWidths
     * (=174mm) y cabX/cabW (18/174) del Header.
     */
    /**
     * Resuelve la lista de firmantes del acta a partir de los responsables (RESP_1..5)
     * del frente de ORIGEN, filtrando por la categoria de flota de los equipos del acta.
     * FUENTE UNICA DE VERDAD: antes esta logica vivia inline en el blade; se centralizo
     * aqui para reutilizarla en el armado del PDF y en la metadata de edicion manual
     * (previewActaMeta) sin duplicarla. Devuelve un array plano de
     * ['label','car','nom','ced'] en el orden de aparicion tras el filtro.
     */
    private function extractFirmasActa($frenteOrigen, $equipos): array
    {
        if (!$frenteOrigen) return [];

        // Categorias de flota presentes en el acta (auxiliares sin categoria → LIVIANA).
        $categoriesInActa = collect($equipos)->pluck('CATEGORIA_FLOTA')
            ->map(fn ($cat) => $cat ?: 'FLOTA LIVIANA')
            ->unique()->filter()->values()->toArray();
        if (empty($categoriesInActa)) {
            $categoriesInActa = ['FLOTA LIVIANA', 'FLOTA PESADA'];
        }

        $labelsByResp = [
            1 => 'SOLICITADO:',
            2 => 'SOLICITADO:',
            3 => 'ELABORADO:',
            4 => 'REVISADO:',
            5 => 'APROBADO:',
        ];

        $firmas = [];
        for ($i = 1; $i <= 5; $i++) {
            $nom = trim($frenteOrigen->{"RESP_{$i}_NOM"} ?? '');
            $car = trim($frenteOrigen->{"RESP_{$i}_CAR"} ?? 'RESPONSABLE');
            $equ = trim($frenteOrigen->{"RESP_{$i}_EQU"} ?? '');
            $ced = trim($frenteOrigen->{"RESP_{$i}_CED"} ?? '');

            $isPlaceholder = empty($nom)
                || strtolower($nom) === 'nombre y apellido'
                || strtolower($nom) === 'por definir'
                || strtolower($nom) === 'n/a';
            if ($isPlaceholder) continue;

            if ($equ === '' || in_array($equ, $categoriesInActa)) {
                $firmas[] = ['nom' => $nom, 'car' => $car, 'ced' => $ced, 'label' => $labelsByResp[$i]];
            }
        }
        return $firmas;
    }

    /**
     * Stub (no persistido) de FrenteTrabajo para el ORIGEN editado a mano en la vista
     * previa (texto libre). Sólo lleva los campos que consume el blade del acta.
     */
    private function stubFrenteOrigen(string $nombre, string $zona = ''): FrenteTrabajo
    {
        $f = new FrenteTrabajo();
        $f->NOMBRE_FRENTE = trim($nombre);
        $f->ZONA          = trim($zona);
        $f->TIPO_FRENTE   = 'OPERACION';
        return $f;
    }

    /**
     * Normaliza el override manual de firmas que llega del cliente. Devuelve null si
     * no se editaron firmas (→ se usan las del frente). Si se enviaron, descarta las
     * filas sin nombre y deja cada una como ['label','car','nom','ced'].
     */
    private function normalizeFirmasOverride(?array $firmas): ?array
    {
        if ($firmas === null) return null;
        $out = [];
        foreach ($firmas as $f) {
            $nom = trim($f['nom'] ?? '');
            if ($nom === '') continue; // sin nombre no es una firma válida
            $car = trim($f['car'] ?? '');
            $out[] = [
                'label' => trim($f['label'] ?? '') ?: 'SOLICITADO:',
                'car'   => $car !== '' ? $car : 'RESPONSABLE',
                'nom'   => $nom,
                'ced'   => trim($f['ced'] ?? ''),
            ];
        }
        return $out;
    }

    private function buildActaPdfBinary($equipos, $frenteOrigen, $frenteDestino, string $numeroOperacion, string $fechaActa, ?array $firmasOverride = null): string
    {
        $pdf = new ActaTrasladoPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->numeroOperacion = $numeroOperacion;
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);
        // El cabezote arranca en y=16 (16mm de aire desde el borde superior del papel)
        // y mide 24mm (=68pt) -> bottom = 40mm. top=40 = bottom del cabezote: la primera
        // tabla del body arranca PEGADA al cabezote, sin franja blanca entre ambos.
        $pdf->SetMargins(18, 40, 18);
        $pdf->SetHeaderMargin(16);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 10);

        // Firmas: las edita el usuario en la vista previa ($firmasOverride) o, por
        // defecto, salen de los responsables del frente de origen. Se calculan aqui
        // (no en el blade) para tener una sola fuente de verdad.
        $firmas = $firmasOverride ?? $this->extractFirmasActa($frenteOrigen, $equipos);

        // El blade consume equipos + frenteOrigen + frenteDestino + fechaActa + firmas;
        // el N° de operacion vive en el cabezote (ActaTrasladoPDF::Header).
        $html = view('admin.movilizaciones.acta_traslado_pdf', compact('equipos', 'frenteOrigen', 'frenteDestino', 'fechaActa', 'firmas'))->render();
        $html = str_replace("this.closest('div[style*='position: fixed']').remove();", "", $html);

        // intro (parrafo) arriba de la tabla, firmas debajo; la tabla nativa va en medio.
        $parts     = explode('<!--EQUIPOS_TABLE_PLACEHOLDER-->', $html, 2);
        $introHtml = $parts[0];
        $sigHtml   = $parts[1] ?? '';

        // Medimos en seco (startTransaction/rollback) la altura del parrafo y de las
        // firmas para paginar por "acta-copia" completa: cada hoja lleva su parrafo +
        // tabla + firmas, asi cada hoja es autocontenida y firmable.
        $measureH = function ($contentHtml) use (&$pdf) {
            if ($contentHtml === '') return 0.0;
            $y0 = $pdf->GetY();
            $pdf->startTransaction();
            $pdf->writeHTML($contentHtml, true, false, true, false, '');
            $h  = $pdf->GetY() - $y0;
            $pdf = $pdf->rollbackTransaction(true);
            return $h > 0 ? $h : ($pdf->getPageHeight() - $pdf->getBreakMargin() - 44);
        };

        $introH = $measureH($introHtml);
        $sigH   = $measureH($sigHtml);

        $bottomLimit  = $pdf->getPageHeight() - $pdf->getBreakMargin();
        $topY         = 40;  // = SetMargins top
        $tableHeaderH = 8;
        $rowH         = 7;
        $safetyGap    = 10;
        $availForRows = $bottomLimit - $topY - $introH - $tableHeaderH - $sigH - $safetyGap;
        $rowsPerSheet = max(1, (int) floor($availForRows / $rowH));

        // chunk() preserva claves 0..n → el N° de la 1ra columna queda correlativo.
        $chunks = $equipos->chunk($rowsPerSheet);
        if ($chunks->isEmpty()) {
            $chunks = collect([collect()]);
        }
        $pdf->totalSheets = $chunks->count();

        // La pagina inicial fue solo para medir (su cabezote salio con totalSheets=0);
        // la descartamos y reconstruimos todas con el total correcto ("Pagina X de Y").
        $pdf->deletePage(1);

        foreach ($chunks as $chunk) {
            $pdf->AddPage();
            $pdf->writeHTML($introHtml, true, false, true, false, '');
            $pdf->SetY($pdf->GetY() - 3); // compensa el espacio extra que deja el HTML
            $pdf->renderEquiposTable($chunk);
            if ($sigHtml !== '') {
                $pdf->writeHTML($sigHtml, true, false, true, false, '');
            }
        }

        return $pdf->Output('Acta_Traslado.pdf', 'S');
    }

    /**
     * Vista previa del Acta de Traslado desde el BORRADOR del modal de movilizacion
     * (IDs de equipos + frente destino) SIN crear nada ni consumir CODIGO_CONTROL.
     * El N° de operacion sale "PENDIENTE" — el real se asigna al confirmar
     * (bulk-mobilize). Devuelve el PDF inline para el visor modal.
     */
    public function previewActaLote(Request $request)
    {
        @set_time_limit(120);
        @ini_set('memory_limit', '512M');

        $data = $request->validate([
            'ids'                   => 'required|array|min:1',
            'ids.*'                 => 'integer',
            'destination'           => 'required|string|max:150',
            'destination_ubicacion' => 'nullable|string|max:150',
            // Ediciones manuales OPCIONALES desde el botón "Editar" de la vista previa
            // (solo afectan ESTE acta, no tocan el frente). Texto libre.
            'origin'                => 'nullable|string|max:150',
            'origin_zona'           => 'nullable|string|max:150',
            'firmas'                => 'nullable|array|max:10',
            'firmas.*.label'        => 'nullable|string|max:40',
            'firmas.*.car'          => 'nullable|string|max:80',
            'firmas.*.nom'          => 'nullable|string|max:80',
            'firmas.*.ced'          => 'nullable|string|max:30',
        ]);

        try {
            $equipos = \App\Models\Equipo::with(['tipo', 'documentacion', 'especificaciones'])
                ->whereIn('ID_EQUIPO', $data['ids'])
                ->get();

            if ($equipos->isEmpty()) {
                return response()->json(['message' => 'No se encontraron equipos para previsualizar.'], 422);
            }

            // Origen del acta: si el usuario lo editó a mano (texto libre) usamos un stub
            // con ese nombre/zona; si no, frente con MAS equipos en la seleccion (misma
            // regla por mayoria que usa el acta real).
            if (!empty(trim($data['origin'] ?? ''))) {
                $frenteOrigen = $this->stubFrenteOrigen($data['origin'], $data['origin_zona'] ?? '');
            } else {
                $idOrigen = $equipos->groupBy('ID_FRENTE_ACTUAL')
                    ->map(fn ($g) => $g->count())
                    ->sortDesc()
                    ->keys()
                    ->first();
                $frenteOrigen = FrenteTrabajo::find($idOrigen);
            }

            // Destino: frente existente por nombre, o un stub (frente nuevo aun no
            // creado) con lo tecleado en el modal. El blade del acta solo usa
            // NOMBRE_FRENTE / UBICACION / TIPO_FRENTE del destino (las firmas salen
            // del frente de ORIGEN).
            $destNom = trim($data['destination']);
            $frenteDestino = FrenteTrabajo::whereRaw('UPPER(NOMBRE_FRENTE) = ?', [mb_strtoupper($destNom)])->first();
            if (!$frenteDestino) {
                $frenteDestino = new FrenteTrabajo();
                $frenteDestino->NOMBRE_FRENTE = $destNom;
                $frenteDestino->UBICACION     = trim($data['destination_ubicacion'] ?? '');
                $frenteDestino->TIPO_FRENTE   = 'OPERACION';
            }

            // Firmas EFECTIVAS del acta: el override manual si lo hay, si no las del
            // frente de origen. Si quedan vacías → el frente no tiene responsables
            // (nadie que elabore/apruebe). Lo informamos al front por header para que
            // pida esos datos en el formulario, sin un round-trip extra.
            $firmasEfectivas = $this->normalizeFirmasOverride($data['firmas'] ?? null)
                ?? $this->extractFirmasActa($frenteOrigen, $equipos);

            $binary = $this->buildActaPdfBinary(
                $equipos,
                $frenteOrigen,
                $frenteDestino,
                'PENDIENTE',
                \Carbon\Carbon::now()->format('d/m/Y'),
                $firmasEfectivas
            );

            return response($binary, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="Vista_Previa_Acta.pdf"',
                'X-Acta-Firmas'       => (string) count($firmasEfectivas),
            ]);
        } catch (\Throwable $e) {
            Log::error('Error generando vista previa de acta: ' . $e->getMessage());
            return response()->json(['message' => 'No se pudo generar la vista previa.'], 500);
        }
    }

    /**
     * Metadata para PRECARGAR el formulario "Editar" de la vista previa: nombre/zona del
     * frente de origen detectado y la lista de firmas que saldrían por defecto. NO crea
     * nada. Reusa extractFirmasActa() (misma fuente de verdad que el PDF).
     */
    public function previewActaMeta(Request $request)
    {
        $data = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $equipos = \App\Models\Equipo::whereIn('ID_EQUIPO', $data['ids'])->get();
        if ($equipos->isEmpty()) {
            return response()->json(['message' => 'No se encontraron equipos.'], 422);
        }

        $idOrigen = $equipos->groupBy('ID_FRENTE_ACTUAL')
            ->map(fn ($g) => $g->count())
            ->sortDesc()
            ->keys()
            ->first();
        $frenteOrigen = FrenteTrabajo::find($idOrigen);

        return response()->json([
            'origin'      => $frenteOrigen->NOMBRE_FRENTE ?? '',
            'origin_zona' => $frenteOrigen ? trim($frenteOrigen->ZONA ?? '') : '',
            'firmas'      => $this->extractFirmasActa($frenteOrigen, $equipos),
        ]);
    }

    // â”€â”€â”€ MOBILE API â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    public function mobileIndex(Request $request)
    {
        $query = Movilizacion::with(['equipo.tipo', 'equipo.documentacion', 'frenteOrigen', 'frenteDestino']);

        // Filtros opcionales
        if ($request->filled('id_equipo')) {
            $query->where('ID_EQUIPO', $request->id_equipo);
        }
        if ($request->filled('id_frente_origen')) {
            $query->where('ID_FRENTE_ORIGEN', $request->id_frente_origen);
        }
        if ($request->filled('id_frente_destino')) {
            $query->where('ID_FRENTE_DESTINO', $request->id_frente_destino);
        }
        if ($request->filled('tipo_movimiento')) {
            $query->where('TIPO_MOVIMIENTO', $request->tipo_movimiento);
        }
        if ($request->filled('codigo')) {
            $query->where('CODIGO_CONTROL', 'like', '%' . trim($request->codigo) . '%');
        }
        if ($request->filled('fecha_desde')) {
            $query->whereDate('FECHA_DESPACHO', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('FECHA_DESPACHO', '<=', $request->fecha_hasta);
        }

        // Paginación con scroll infinito (offset/limit)
        $PAGE_SIZE = 50;
        $offset = max(0, (int) $request->input('offset', 0));
        $totalFound = (clone $query)->count();
        $movs = $query->orderBy('FECHA_DESPACHO', 'desc')
            ->offset($offset)->limit($PAGE_SIZE)->get();
        $nextOffset = $offset + $movs->count();
        $hasMore = $nextOffset < $totalFound;

        $items = $movs->map(function ($m) {
            return [
                'ID_MOVILIZACION'  => $m->ID_MOVILIZACION,
                'CODIGO_CONTROL'   => $m->formatted_codigo_control,
                'TIPO_MOVIMIENTO'  => $m->TIPO_MOVIMIENTO,
                'FECHA_DESPACHO'   => optional($m->FECHA_DESPACHO)->toIso8601String(),
                'DETALLE_UBICACION'=> $m->DETALLE_UBICACION,
                'USUARIO_REGISTRO' => $m->USUARIO_REGISTRO,
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
        });

        return response()->json([
            'items'      => $items,
            'totalFound' => $totalFound,
            'shownCount' => $movs->count(),
            'offset'     => $offset,
            'nextOffset' => $nextOffset,
            'hasMore'    => $hasMore,
            'pageSize'   => $PAGE_SIZE,
        ]);
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

            // ID_FRENTE_ACTUAL NO es fillable (ver Equipo::$fillable): asignación por
            // propiedad + save(), NO update([...]) (mass-assign lo descartaría en silencio).
            $equipo->ID_FRENTE_ACTUAL         = $request->ID_FRENTE_DESTINO;
            $equipo->DETALLE_UBICACION_ACTUAL = null;
            // Despacho → pendiente de confirmar en el frente destino (se tilda al llegar).
            $equipo->CONFIRMADO_EN_SITIO      = 0;
            $equipo->save();

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

    /**
     * Deshacer una movilización: devuelve el equipo (o auxiliar) a su frente de ORIGEN y borra
     * el registro — "como si nunca hubiera ocurrido". super.admin (gateado en __construct).
     *
     * Guarda de seguridad: solo se permite si el equipo SIGUE en el frente DESTINO de ESTA
     * movilización. Si ya fue movilizado de nuevo después, deshacer esta (vieja) lo dejaría en
     * un frente equivocado → se rechaza y se pide deshacer primero la más reciente.
     */
    public function deshacer($id)
    {
        DB::beginTransaction();
        try {
            $mov = Movilizacion::lockForUpdate()->findOrFail($id);

            if ($mov->ID_EQUIPO) {
                $equipo = \App\Models\Equipo::lockForUpdate()->find($mov->ID_EQUIPO);
                if ($equipo) {
                    if ((int) $equipo->ID_FRENTE_ACTUAL !== (int) $mov->ID_FRENTE_DESTINO) {
                        DB::rollBack();
                        return response()->json(['success' => false, 'message' => 'No se puede deshacer: el equipo ya fue movilizado a otro frente después de esta. Deshaz primero la movilización más reciente.'], 422);
                    }
                    // ID_FRENTE_ACTUAL NO es fillable (ver Equipo::$fillable): se asigna por
                    // propiedad + save(), NO con update([...]) (mass-assign lo descartaría en
                    // silencio y el equipo NO volvería al origen, solo se borraría el registro).
                    $equipo->ID_FRENTE_ACTUAL = $mov->ID_FRENTE_ORIGEN;
                    $equipo->save();
                }
            } elseif ($mov->ID_AUXILIAR) {
                $aux = \App\Models\EquipoAuxiliar::lockForUpdate()->find($mov->ID_AUXILIAR);
                if ($aux) {
                    if ((int) $aux->ID_FRENTE_ACTUAL !== (int) $mov->ID_FRENTE_DESTINO) {
                        DB::rollBack();
                        return response()->json(['success' => false, 'message' => 'No se puede deshacer: el auxiliar ya fue movilizado a otro frente después de esta.'], 422);
                    }
                    $aux->update(['ID_FRENTE_ACTUAL' => $mov->ID_FRENTE_ORIGEN]);
                }
            }

            // Borrado DURO (Movilizacion no usa SoftDeletes) → no deja rastro.
            $mov->delete();

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Movilización deshecha: el equipo volvió a su frente de origen.']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('deshacer movilizacion error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'No se pudo deshacer la movilización.'], 500);
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

} // END MovilizacionController


class ActaTrasladoPDF extends \TCPDF
{
    /** N° de operacion (CODIGO_CONTROL, 6 digitos) — lo inyecta el controller
     *  antes de AddPage() para que el Header() lo pinte en el casillero "Codigo:". */
    public string $numeroOperacion = '';

    /** Total de hojas del acta (= nº de "actas-copia" completas que se generan
     *  al paginar los equipos). Lo inyecta el controller ANTES de AddPage() para
     *  que el Header() pinte un "Pagina X de Y" exacto. Si queda en 0, el Header
     *  cae al getNumPages() de TCPDF. */
    public int $totalSheets = 0;

    /**
     * Anchos de columna en mm. Total = 174mm (= 210mm A4 portrait - 18+18 margenes).
     * El orden corresponde a: N°, Descripcion, Marca, Serial.
     */
    private $equiposColWidths = [9, 84, 36, 45];
    private $equiposHeaders   = ['N°', 'DESCRIPCIÓN / EQUIPO', 'MARCA', 'SERIAL / PLACA'];
    private $equiposHeaderH   = 8;  // mm
    private $equiposRowH      = 7;  // mm

    /**
     * Renderiza la tabla de equipos del acta usando la API nativa de TCPDF (Cell).
     * Maneja saltos de pagina manualmente, redibujando el header en cada pagina nueva.
     * Esto evita el bug del renderizador HTML de TCPDF que recalcula los anchos
     * del thead repetido al cambiar de pagina, desalineandolo con el tbody.
     */
    public function renderEquiposTable($equipos)
    {
        $this->renderEquiposHeader();

        $this->SetFont('helvetica', '', 8.5);
        $this->SetFillColor(255, 255, 255);
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.1);

        foreach ($equipos as $i => $item) {
            // Salvaguarda de salto de pagina. NOTA: con el modelo actual el
            // controller ya parte los equipos en hojas dimensionadas para que
            // sus filas + el bloque de firmas quepan completas, por lo que en
            // uso normal esto NO se dispara (cada $equipos que llega aqui es un
            // chunk que cabe). Se conserva como red de seguridad: si alguna fila
            // no cupiera, abrimos pagina y RE-dibujamos el header — sin esto el
            // auto-break de TCPDF partiria la tabla nativa dejando filas huerfanas
            // sin cabecera (el bug que motivo renderizar la tabla con Cell()).
            $bottomLimit = $this->getPageHeight() - $this->getBreakMargin();
            if ($this->GetY() + $this->equiposRowH > $bottomLimit) {
                $this->AddPage();
                $this->renderEquiposHeader();
                $this->SetFont('helvetica', '', 8.5);
                $this->SetFillColor(255, 255, 255);
                $this->SetTextColor(0, 0, 0);
            }

            $tipoNombre = strtoupper($item->tipo->nombre ?? 'SIN TIPO');
            $marca      = strtoupper($item->MARCA ?? '---');
            $serial     = strtoupper($item->SERIAL_CHASIS ?? '---');

            $this->Cell($this->equiposColWidths[0], $this->equiposRowH, ($i + 1),    1, 0, 'C', false);
            $this->Cell($this->equiposColWidths[1], $this->equiposRowH, $tipoNombre, 1, 0, 'C', false);
            $this->Cell($this->equiposColWidths[2], $this->equiposRowH, $marca,      1, 0, 'C', false);
            $this->Cell($this->equiposColWidths[3], $this->equiposRowH, $serial,     1, 1, 'C', false);
        }

        // Pequeño espaciado despues de la tabla antes del bloque de firmas.
        $this->Ln(4);
    }

    /**
     * Dibuja el encabezado de la tabla de equipos (fondo azul claro + texto bold).
     */
    private function renderEquiposHeader()
    {
        $this->SetFont('helvetica', 'B', 8.5);
        $this->SetFillColor(230, 242, 255); // #e6f2ff
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.1);

        $count = count($this->equiposHeaders);
        foreach ($this->equiposHeaders as $i => $text) {
            $ln = ($i === $count - 1) ? 1 : 0;
            $this->Cell($this->equiposColWidths[$i], $this->equiposHeaderH, $text, 1, $ln, 'C', true);
        }
    }

    public function Header()
    {
        // ── Cabezote estilo formato VIDALSA — mismo grid que la Nota de Entrega
        //    del Almacen (VID-FO-GEN-019). UNA tabla HTML con bordes:
        //    [LOGO 20%]  |  ACTA DE TRASLADO DE EQUIPOS (52%)  |  [SELLO 28% × 5 filas]
        //
        //    El logo es una imagen — TCPDF no la mete bien via HTML, asi que la
        //    celda 20% va vacia con rowspan=5 y la Image() se superpone con
        //    fitbox=CM (Center-Middle) para que quede centrada vertical/horizontal.
        //
        //    Las 5 filas del sello (Seccion eliminada por pedido del cliente):
        //      Codigo (= N° de operacion) / Revision: 1 / Proc.de Refe: /
        //      Fecha de Emision (hoy) / Pag. X de Y
        //    Proc.de Refe queda vacio — se rellenara cuando administracion
        //    defina el codigo oficial del formato.

        // Grosor de linea por defecto para cualquier trazo nativo del Header.
        // NOTA: el borde del cabezote (tabla HTML) NO depende de esto — TCPDF
        // ignora SetLineWidth para los bordes HTML; su grosor se fija por CSS
        // (border:0.1mm) mas abajo, para igualar a la tabla nativa de equipos.
        $this->SetLineWidth(0.1);

        // Geometria del cabezote (atada a SetMargins del controller):
        //   x = 18 mm  (coincide con SetMargins left=18)   width = 174 mm
        //   y = 16 mm  (16mm de aire desde el borde sup.)  height = 24 mm (= 68 pt)
        //   bottom = 16 + 24 = 40 mm  ← coincide con SetMargins top=40, de modo
        //                                que el body arranca PEGADO al cabezote.
        $cabX = 18;
        $cabY = 16;
        $cabW = 174;
        $cabH = 24;
        $logoCellW = $cabW * 0.20;  // 34.8 mm

        $img = public_path('img/imagen_uno.jpg');
        if (file_exists($img)) {
            // Logo centrado dentro de la celda 20% × 24mm con padding 1mm.
            // fitbox='CM' = Center+Middle; preserva aspect ratio.
            $padding = 1;
            $bx = $cabX + $padding;                 // 19
            $by = $cabY + $padding;                 // 17
            $bw = $logoCellW - ($padding * 2);      // 32.8
            $bh = $cabH - ($padding * 2);           // 22
            $this->Image($img, $bx, $by, $bw, $bh, 'JPG', '', '', false, 300, '', false, false, 0, 'CM', false, false);
        }

        // Pagina N de M — numeros reales en vez de alias {:pnb:}/{:ptp:} porque
        // los alias rompen el centrado horizontal de TCPDF (mismo truco que
        // usa NotaEntregaPDF::Header). "de" en minuscula por feedback del cliente.
        // M = totalSheets (lo fija el controller antes de generar las hojas);
        // si por algun motivo viniera en 0, caemos al getNumPages() de TCPDF.
        $page = $this->PageNo() . ' de ' . max(1, $this->totalSheets ?: $this->getNumPages());

        // Geometria vertical en puntos:
        //   - rowspan total (logo + titulo): headerHeight = 68pt (= 24mm = $cabH)
        //   - las 5 filas del sello NO llevan height propio: TCPDF reparte el
        //     rowspan entre ellas (≈13.6pt c/u), igualando ambos lados sin el
        //     "minigap" de ~1pt. Mismo patron que el cabezote de la Nota de
        //     Entrega (VID-FO-GEN-019), del cual hereda esta geometria.
        // Font size 8 en las celdas — entra holgado en ~13.6pt.
        $headerHeight = 68;                      // pt (= 24 mm = $cabH)

        // Line-height del titulo = altura del rowspan menos un padding visual.
        // Truco de centrado vertical en celda con rowspan — TCPDF no respeta
        // valign con rowspan, pero un line-height ≈ alto del rowspan deja el
        // texto exactamente en el centro.
        $tituloDiv = '<div style="text-align:center;line-height:' . ($headerHeight - 4) . 'pt;font-family:helvetica;font-size:12pt;font-weight:bold;">ACTA DE TRASLADO DE EQUIPOS</div>';

        $fechaHoy = \Carbon\Carbon::now()->format('d/m/Y');
        $codigoOp = htmlspecialchars($this->numeroOperacion, ENT_QUOTES, 'UTF-8');
        // Grosor del borde del cabezote por CSS a 0.1mm = MISMO grosor que la tabla
        // nativa de equipos (SetLineWidth 0.1). OJO: el atributo HTML border="1" de
        // TCPDF NO respeta SetLineWidth — dibuja 1px (~0.35mm), por eso el cabezote
        // se veia ~3.5x mas grueso que la tabla de equipos. Definimos el borde por
        // CSS en cada celda; border-collapse:collapse evita el efecto "doble linea".
        $bs = 'border:0.1mm solid #000;';
        $html = '<table cellpadding="2" cellspacing="0" width="100%" style="border-collapse:collapse;">'
              . '<tr>'
              .   '<td width="20%" rowspan="5" height="' . $headerHeight . '" style="' . $bs . '">&nbsp;</td>'
              .   '<td width="52%" rowspan="5" height="' . $headerHeight . '" align="center" valign="middle" style="' . $bs . '">' . $tituloDiv . '</td>'
              .   '<td width="28%" align="center" valign="middle" style="' . $bs . '"><font face="helvetica" size="8"><b>C&oacute;digo:</b> ' . $codigoOp . '</font></td>'
              . '</tr>'
              . '<tr><td width="28%" align="center" valign="middle" style="' . $bs . '"><font face="helvetica" size="8"><b>Revisi&oacute;n:</b> 1</font></td></tr>'
              . '<tr><td width="28%" align="center" valign="middle" style="' . $bs . '"><font face="helvetica" size="8"><b>Proc.de Refe:</b></font></td></tr>'
              . '<tr><td width="28%" align="center" valign="middle" style="' . $bs . '"><font face="helvetica" size="8"><b>Fecha de Emisi&oacute;n:</b> ' . $fechaHoy . '</font></td></tr>'
              . '<tr><td width="28%" align="center" valign="middle" style="' . $bs . '"><font face="helvetica" size="8">P&aacute;gina ' . $page . '</font></td></tr>'
              . '</table>';

        // SetFont base = mismo tamano que las celdas HTML (size=8). TCPDF lo
        // toma como fallback si algun <font> se cayera y tambien lo usa para
        // medir el ancho/alto del contenido en HTML mode.
        $this->SetFont('helvetica', '', 8);
        $this->writeHTMLCell($cabW, 0, $cabX, $cabY, $html, 0, 0, 0, true, 'L', true);
    }

    public function Footer()
    {
        // Pie de pagina vacio por pedido del cliente: se elimino la marca
        // "EMITIDO POR SISTEMA DE GESTION DE FLOTA". El numero de pagina ya
        // vive en la celda derecha del cabezote (Pagina X de Y), asi que el
        // footer no necesita pintar nada.
    }
}
