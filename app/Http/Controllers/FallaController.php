<?php

namespace App\Http\Controllers;

use App\Models\Falla;
use App\Models\Equipo;
use App\Models\EquipoAuxiliar;
use App\Models\FrenteTrabajo;
use App\Models\TipoEquipo;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Modulo de Reportes de Fallas (/admin/fallas).
 *
 * Permiso global: equipos.edit (gateado en routes/web.php).
 *
 * Soporta dos modalidades:
 *   - corto:   solo descripcion + estado del equipo (registro rapido).
 *   - extenso: campos del formato corporativo + genera PDF acta.
 */
class FallaController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        // Todo el modulo requiere autenticacion. Operaciones de escritura gateadas por equipos.edit. Gate::before resuelve super.admin.
        $this->middleware('can:equipos.edit')->except(['index', 'searchActivos', 'pdf']);
    }

    /**
     * Listado con filtros y paginacion server-side.
     */
    public function index(Request $request)
    {
        $query = Falla::query();

        // Estatus
        if ($request->filled('estatus')) {
            $query->where('ESTADO_REPORTE', $request->estatus);
        }

        // Fechas
        if ($request->filled('fecha_desde')) {
            $query->whereDate('FECHA_EMISION', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('FECHA_EMISION', '<=', $request->fecha_hasta);
        }

        // Responsable
        if ($request->filled('responsable')) {
            $query->where('ID_USUARIO_REPORTA', $request->responsable);
        }

        // Tipo de activo — acepta: 'equipo', 'equipo_auxiliar', 'tipo_eq:{id}', 'tipo_aux:{TIPO}'
        $ta = $request->input('tipo_activo', '');
        if ($ta !== '') {
            if ($ta === 'equipo' || $ta === 'equipo_auxiliar') {
                $query->where('ACTIVO_TIPO', $ta);
            } elseif (str_starts_with($ta, 'tipo_eq:')) {
                $tipoId = (int) substr($ta, 8);
                $query->where('ACTIVO_TIPO', 'equipo')
                      ->whereIn('ACTIVO_ID',
                          Equipo::where('id_tipo_equipo', $tipoId)->select('ID_EQUIPO'));
            } elseif (str_starts_with($ta, 'tipo_aux:')) {
                $auxTipo = substr($ta, 9);
                $query->where('ACTIVO_TIPO', 'equipo_auxiliar')
                      ->whereIn('ACTIVO_ID',
                          EquipoAuxiliar::where('TIPO', $auxTipo)->select('ID_AUXILIAR'));
            }
        }

        // Busqueda por serial / placa / codigo / etiqueta
        if ($request->filled('search')) {
            $sClean = mb_strtoupper(ltrim(trim($request->input('search')), '#'));
            $like   = "%{$sClean}%";

            $eqIds = Equipo::leftJoin('documentacion AS d', 'equipos.ID_EQUIPO', '=', 'd.ID_EQUIPO')
                ->where(function ($w) use ($like) {
                    $w->whereRaw('UPPER(equipos.SERIAL_CHASIS) like ?', [$like])
                      ->orWhereRaw('UPPER(equipos.SERIAL_DE_MOTOR) like ?', [$like])
                      ->orWhereRaw('UPPER(equipos.CODIGO_PATIO) like ?', [$like])
                      ->orWhereRaw('UPPER(d.PLACA) like ?', [$like]);
                })->pluck('equipos.ID_EQUIPO')->toArray();

            $auxIds = EquipoAuxiliar::where(function ($w) use ($like) {
                $w->whereRaw('UPPER(SERIAL) like ?', [$like])
                  ->orWhereRaw('UPPER(CODIGO_INTERNO) like ?', [$like]);
            })->pluck('ID_AUXILIAR')->toArray();

            $query->where(function ($q) use ($eqIds, $auxIds, $like) {
                $q->where(function ($i) use ($eqIds) {
                    $i->where('ACTIVO_TIPO', 'equipo')->whereIn('ACTIVO_ID', $eqIds ?: [0]);
                })->orWhere(function ($i) use ($auxIds) {
                    $i->where('ACTIVO_TIPO', 'equipo_auxiliar')->whereIn('ACTIVO_ID', $auxIds ?: [0]);
                })->orWhere('CODIGO_REPORTE', 'like', $like);
            });
        }

        // Marca
        if ($request->filled('marca')) {
            $mLike = '%' . mb_strtoupper(trim($request->marca)) . '%';
            $eqIds  = Equipo::whereRaw('UPPER(MARCA) like ?', [$mLike])->pluck('ID_EQUIPO')->toArray();
            $auxIds = EquipoAuxiliar::whereRaw('UPPER(MARCA) like ?', [$mLike])->pluck('ID_AUXILIAR')->toArray();
            $query->where(function ($q) use ($eqIds, $auxIds) {
                $q->where(fn($i) => $i->where('ACTIVO_TIPO','equipo')->whereIn('ACTIVO_ID',$eqIds ?: [0]))
                  ->orWhere(fn($i) => $i->where('ACTIVO_TIPO','equipo_auxiliar')->whereIn('ACTIVO_ID',$auxIds ?: [0]));
            });
        }

        // Modelo
        if ($request->filled('modelo')) {
            $moLike = '%' . mb_strtoupper(trim($request->modelo)) . '%';
            $eqIds  = Equipo::whereRaw('UPPER(MODELO) like ?', [$moLike])->pluck('ID_EQUIPO')->toArray();
            $auxIds = EquipoAuxiliar::whereRaw('UPPER(MODELO) like ?', [$moLike])->pluck('ID_AUXILIAR')->toArray();
            $query->where(function ($q) use ($eqIds, $auxIds) {
                $q->where(fn($i) => $i->where('ACTIVO_TIPO','equipo')->whereIn('ACTIVO_ID',$eqIds ?: [0]))
                  ->orWhere(fn($i) => $i->where('ACTIVO_TIPO','equipo_auxiliar')->whereIn('ACTIVO_ID',$auxIds ?: [0]));
            });
        }

        // Frente
        if ($request->filled('id_frente')) {
            $frId   = (int) $request->id_frente;
            $eqIds  = Equipo::where('ID_FRENTE_ACTUAL', $frId)->pluck('ID_EQUIPO')->toArray();
            $auxIds = EquipoAuxiliar::where('ID_FRENTE_ACTUAL', $frId)->pluck('ID_AUXILIAR')->toArray();
            $query->where(function ($q) use ($eqIds, $auxIds) {
                $q->where(fn($i) => $i->where('ACTIVO_TIPO','equipo')->whereIn('ACTIVO_ID',$eqIds ?: [0]))
                  ->orWhere(fn($i) => $i->where('ACTIVO_TIPO','equipo_auxiliar')->whereIn('ACTIVO_ID',$auxIds ?: [0]));
            });
        }

        $fallas = $query->orderByDesc('FECHA_EMISION')->paginate(50);

        // N+1 Optimization: Eager load custom polymorphic manually
        $equipoIds = [];
        $auxiliarIds = [];
        foreach ($fallas->items() as $falla) {
            if ($falla->ACTIVO_TIPO === 'equipo') {
                $equipoIds[] = $falla->ACTIVO_ID;
            } elseif ($falla->ACTIVO_TIPO === 'equipo_auxiliar') {
                $auxiliarIds[] = $falla->ACTIVO_ID;
            }
        }
        
        $equiposLoaded = empty($equipoIds) ? collect() : Equipo::with(['especificaciones', 'documentacion', 'tipo'])->whereIn('ID_EQUIPO', array_unique($equipoIds))->get()->keyBy('ID_EQUIPO');
        $auxiliaresLoaded = empty($auxiliarIds) ? collect() : EquipoAuxiliar::whereIn('ID_AUXILIAR', array_unique($auxiliarIds))->get()->keyBy('ID_AUXILIAR');

        // Hidrata activo + frente (un solo query previo para todos los frentes).
        $allFrentes = FrenteTrabajo::pluck('NOMBRE_FRENTE', 'ID_FRENTE')->toArray();
        $fallas->getCollection()->transform(function ($f) use ($allFrentes, $equiposLoaded, $auxiliaresLoaded) {
            if ($f->ACTIVO_TIPO === 'equipo') {
                $activo = $equiposLoaded->get($f->ACTIVO_ID);
            } elseif ($f->ACTIVO_TIPO === 'equipo_auxiliar') {
                $activo = $auxiliaresLoaded->get($f->ACTIVO_ID);
            } else {
                $activo = null;
            }

            $f->_activo      = $activo;
            $frenteId        = $activo?->ID_FRENTE_ACTUAL;
            $f->_frente_nombre = $frenteId ? ($allFrentes[$frenteId] ?? '—') : '—';
            return $f;
        });

        $stats = $this->buildStats($request);

        if ($request->wantsJson()) {
            return response()->json([
                'html'       => view('admin.fallas.partials.table_rows', compact('fallas'))->render(),
                'stats'      => $stats,
                'pagination' => $fallas->links('vendor.pagination.custom-sliding')->toHtml(),
            ]);
        }

        $responsables = Usuario::whereIn('ID_USUARIO',
                Falla::distinct()->pluck('ID_USUARIO_REPORTA')->filter()
            )->orderBy('NOMBRE_COMPLETO')->get(['ID_USUARIO', 'NOMBRE_COMPLETO']);

        $frentes = FrenteTrabajo::orderBy('NOMBRE_FRENTE')->get(['ID_FRENTE', 'NOMBRE_FRENTE']);

        // Catalogo de Marcas / Modelos para el filtro avanzado.
        // Cuando el usuario filtra por tipo_activo, solo recomendamos
        // los valores de esa familia (vehiculos o auxiliares); si no, union.
        $tipoSel = $request->input('tipo_activo');
        $eqMarcas = ($tipoSel === 'equipo_auxiliar') ? collect()
            : Equipo::whereNotNull('MARCA')->where('MARCA','!=','')->distinct()->orderBy('MARCA')->pluck('MARCA');
        $auxMarcas = ($tipoSel === 'equipo') ? collect()
            : EquipoAuxiliar::whereNotNull('MARCA')->where('MARCA','!=','')->distinct()->orderBy('MARCA')->pluck('MARCA');
        $availableMarcas = $eqMarcas->merge($auxMarcas)->unique()->sort()->values();

        $eqModelos = ($tipoSel === 'equipo_auxiliar') ? collect()
            : Equipo::whereNotNull('MODELO')->where('MODELO','!=','')->distinct()->orderBy('MODELO')->pluck('MODELO');
        $auxModelos = ($tipoSel === 'equipo') ? collect()
            : EquipoAuxiliar::whereNotNull('MODELO')->where('MODELO','!=','')->distinct()->orderBy('MODELO')->pluck('MODELO');
        $availableModelos = $eqModelos->merge($auxModelos)->unique()->sort()->values();

        // Tipos de equipo (para el filtro avanzado agrupado).
        // Sin cache: las tablas son chicas y los tipos se reflejan en tiempo real
        // cuando el admin agrega/elimina un TipoEquipo o un EquipoAuxiliar nuevo.
        $tiposEquipo = TipoEquipo::orderBy('nombre')->get(['id', 'nombre']);
        $tiposAux = EquipoAuxiliar::whereNotNull('TIPO')->where('TIPO', '!=', '')
            ->distinct()->orderBy('TIPO')->pluck('TIPO');

        return view('admin.fallas.index', compact(
            'fallas', 'stats', 'responsables', 'frentes',
            'availableMarcas', 'availableModelos', 'tiposEquipo', 'tiposAux'
        ));
    }

    /**
     * Crear reporte de falla (modalidad corto o extenso).
     * Pone el equipo en estado especificado (INOPERATIVO / EN MANTENIMIENTO).
     */
    public function store(Request $request)
    {
        $request->validate([
            'tipo_reporte'    => 'required|in:corto,extenso',
            'activo_tipo'     => 'required|in:equipo,equipo_auxiliar',
            'activo_id'       => 'required|integer',
            'descripcion'     => 'required|string|max:5000',
            // Seccion 2 — identificacion
            'kilometraje'     => 'nullable|string|max:50',
            'horas'           => 'nullable|string|max:50',
            // Seccion 3 — tipo de mantenimiento
            'tipo_mantenimiento' => 'nullable|in:PREVENTIVO,CORRECTIVO',
            // Seccion 4 — taller (opcional al crear)
            'mecanico_asignado'   => 'nullable|string|max:120',
            'fecha_recepcion'     => 'nullable|date',
            'diagnostico'         => 'nullable|string|max:5000',
            'acciones_realizadas' => 'nullable|string|max:5000',
        ]);

        // Al crear cualquier reporte de falla, el equipo SIEMPRE queda INOPERATIVO.
        $estadoAlCrear = 'INOPERATIVO';

        return DB::transaction(function () use ($request, $estadoAlCrear) {
            // Lock del activo y guarda estado previo
            $activo = $this->lockActivo($request->activo_tipo, $request->activo_id);
            if (!$activo) {
                return response()->json(['success' => false, 'message' => 'Activo no encontrado'], 404);
            }
            $estadoPrevio = $activo->ESTADO_OPERATIVO;
            if ($estadoPrevio === 'DESINCORPORADO') {
                return response()->json(['success' => false, 'message' => 'No se puede reportar falla en un equipo desincorporado.'], 422);
            }

            // Atributos comunes (compartidos con la vista previa); el código real se
            // genera CON lock aquí. ESTADO_PREVIO/AL_CREAR son propios de la creación.
            $attrs = $this->fallaAttributes($request, $activo, $this->generateCodigoReporte());
            $attrs['ESTADO_PREVIO']   = $estadoPrevio;
            $attrs['ESTADO_AL_CREAR'] = $estadoAlCrear;
            $falla = Falla::create($attrs);

            // El equipo queda INOPERATIVO (se refleja en el módulo de equipos y sus totales).
            $activo->ESTADO_OPERATIVO = $estadoAlCrear;
            $activo->save();

            $this->logAction($falla->ID_FALLA, $request->activo_tipo, $request->activo_id, 'create_falla', [
                'codigo'           => $falla->CODIGO_REPORTE,
                'tipo'             => $falla->TIPO_REPORTE,
                'estado_previo'    => $estadoPrevio,
                'estado_al_crear'  => $estadoAlCrear,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Reporte de falla creado.',
                'falla'   => $falla,
            ]);
        });
    }

    /**
     * Atributos de una Falla a partir del formulario + snapshot del frente/usuario.
     * Lo comparten store() (los guarda) y previewPdf() (los usa en memoria, sin guardar)
     * para no duplicar el mapeo. NO incluye ESTADO_PREVIO/AL_CREAR (propios del store).
     */
    private function fallaAttributes(Request $request, $activo, string $codigo): array
    {
        $user = auth()->user();
        $frente = $activo->ID_FRENTE_ACTUAL
            ? DB::table('frentes_trabajo')->where('ID_FRENTE', $activo->ID_FRENTE_ACTUAL)->value('NOMBRE_FRENTE')
            : null;

        return [
            'CODIGO_REPORTE'      => $codigo,
            'FECHA_EMISION'       => now(),
            'TIPO_REPORTE'        => $request->tipo_reporte,
            'ESTADO_REPORTE'      => 'abierto',
            'ACTIVO_TIPO'         => $request->activo_tipo,
            'ACTIVO_ID'           => $request->activo_id,
            'FRENTE_TRABAJO'      => $frente,
            'KILOMETRAJE'         => $request->kilometraje,
            'HORAS'               => $request->horas,
            'DESCRIPCION_AVERIA'  => $request->descripcion,
            'TIPO_MANTENIMIENTO'  => $request->tipo_mantenimiento,
            'MECANICO_ASIGNADO'   => $request->mecanico_asignado,
            'FECHA_RECEPCION'     => $request->fecha_recepcion,
            'DIAGNOSTICO'         => $request->diagnostico,
            'ACCIONES_REALIZADAS' => $request->acciones_realizadas,
            'ID_USUARIO_REPORTA'  => $user->ID_USUARIO,
            'NOMBRE_REPORTA'      => $user->NOMBRE_COMPLETO,
            'CARGO_REPORTA'       => optional($user->rol)->NOMBRE_ROL ?? '',
            'EMAIL_REPORTA'       => $user->CORREO_ELECTRONICO,
        ];
    }

    /**
     * Cierra un reporte de falla y opcionalmente regresa el equipo a OPERATIVO.
     */
    public function close(Request $request, $id)
    {
        $request->validate([
            'observaciones_cierre' => 'nullable|string|max:5000',
            // Campos del taller: se pueden completar/actualizar al cerrar.
            'mecanico_asignado'    => 'nullable|string|max:120',
            'fecha_recepcion'      => 'nullable|date',
            'diagnostico'          => 'nullable|string|max:5000',
            'acciones_realizadas'  => 'nullable|string|max:5000',
        ]);

        return DB::transaction(function () use ($request, $id) {
            $falla = Falla::lockForUpdate()->findOrFail($id);
            if ($falla->ESTADO_REPORTE === 'cerrado') {
                return response()->json(['success' => false, 'message' => 'El reporte ya esta cerrado'], 422);
            }

            $user = auth()->user();
            $falla->ESTADO_REPORTE       = 'cerrado';
            $falla->FECHA_CIERRE         = now();
            $falla->ID_USUARIO_CIERRA    = $user->ID_USUARIO;
            $falla->NOMBRE_CIERRA        = $user->NOMBRE_COMPLETO;
            $falla->CARGO_CIERRA         = optional($user->rol)->NOMBRE_ROL ?? '';
            $falla->OBSERVACIONES_CIERRE = $request->input('observaciones_cierre');

            // Solo sobreescribe los campos del taller si vienen con valor
            // (permite completarlos al cerrar sin borrar lo cargado al crear).
            foreach ([
                'mecanico_asignado'   => 'MECANICO_ASIGNADO',
                'fecha_recepcion'     => 'FECHA_RECEPCION',
                'diagnostico'         => 'DIAGNOSTICO',
                'acciones_realizadas' => 'ACCIONES_REALIZADAS',
            ] as $input => $col) {
                if ($request->filled($input)) {
                    $falla->{$col} = $request->input($input);
                }
            }
            $falla->save();

            // Al cerrar, el activo vuelve a OPERATIVO en el módulo de equipos
            // (columna ESTADO_OPERATIVO) SOLO si ya no le quedan otros reportes
            // ABIERTOS — un equipo puede tener varios reportes y no debe "curarse"
            // mientras siga con alguno abierto. El reporte actual ya quedó 'cerrado'
            // arriba, así que no se cuenta a sí mismo.
            $activo = $this->lockActivo($falla->ACTIVO_TIPO, $falla->ACTIVO_ID);
            if ($activo) {
                $tieneAbiertos = Falla::where('ACTIVO_TIPO', $falla->ACTIVO_TIPO)
                    ->where('ACTIVO_ID', $falla->ACTIVO_ID)
                    ->where('ESTADO_REPORTE', 'abierto')
                    ->exists();
                if (!$tieneAbiertos) {
                    $activo->ESTADO_OPERATIVO = 'OPERATIVO';
                    $activo->save();
                }
            }

            $this->logAction($falla->ID_FALLA, $falla->ACTIVO_TIPO, $falla->ACTIVO_ID, 'close_falla', []);

            return response()->json(['success' => true, 'message' => 'Reporte cerrado.']);
        });
    }

    /**
     * Borrado DURO de un reporte — EXCLUSIVO super.admin (gate en routes/web.php).
     * Irreversible y sin rastro: elimina el reporte (forceDelete) y sus entradas de
     * auditoría. Si el reporte estaba ABIERTO y dejó el activo INOPERATIVO, lo regresa
     * a OPERATIVO cuando ya no le quedan otros reportes abiertos (deshace su efecto,
     * mismo criterio que el cierre).
     */
    public function destroy($id)
    {
        return DB::transaction(function () use ($id) {
            $falla = Falla::withTrashed()->lockForUpdate()->findOrFail($id);
            $tipo  = $falla->ACTIVO_TIPO;
            $aid   = $falla->ACTIVO_ID;
            $estabaAbierto = $falla->ESTADO_REPORTE === 'abierto';

            // Sin rastro: borra la auditoría del reporte y el reporte mismo.
            DB::table('fallas_audit_log')->where('ID_FALLA', $falla->ID_FALLA)->delete();
            $falla->forceDelete();

            // Si lo dejó inoperativo y ya no quedan reportes abiertos, lo cura.
            if ($estabaAbierto) {
                $activo = $this->lockActivo($tipo, $aid);
                if ($activo) {
                    $tieneAbiertos = Falla::where('ACTIVO_TIPO', $tipo)
                        ->where('ACTIVO_ID', $aid)
                        ->where('ESTADO_REPORTE', 'abierto')
                        ->exists();
                    if (!$tieneAbiertos) {
                        $activo->ESTADO_OPERATIVO = 'OPERATIVO';
                        $activo->save();
                    }
                }
            }

            return response()->json(['success' => true, 'message' => 'Reporte eliminado.']);
        });
    }

    /**
     * Busqueda inteligente de activos por placa / serial / codigo.
     * Devuelve [{tipo, id, label, placa, serial, marca, modelo, estado, foto}, ...]
     */
    public function searchActivos(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        if (strlen($q) < 2) {
            return response()->json(['results' => []]);
        }
        $qUpper = mb_strtoupper($q);
        $like = "%{$qUpper}%";

        // Equipos: busca por serial chasis, serial motor, placa, código patio.
        // NO incluye MARCA en el search (solo en filtros avanzados).
        $eqs = Equipo::with('especificaciones')
            ->leftJoin('documentacion AS d',   'equipos.ID_EQUIPO',       '=', 'd.ID_EQUIPO')
            ->leftJoin('tipo_equipos AS te',   'equipos.id_tipo_equipo',  '=', 'te.id')
            ->leftJoin('frentes_trabajo AS ft','equipos.ID_FRENTE_ACTUAL','=', 'ft.ID_FRENTE')
            ->select(
                'equipos.ID_EQUIPO', 'equipos.CODIGO_PATIO', 'equipos.MARCA',
                'equipos.MODELO',    'equipos.SERIAL_CHASIS', 'equipos.SERIAL_DE_MOTOR',
                'equipos.ESTADO_OPERATIVO', 'equipos.FOTO_EQUIPO', 'equipos.ID_ESPEC',
                'd.PLACA', 'te.nombre AS TIPO_NOMBRE', 'ft.NOMBRE_FRENTE'
            )
            ->where('equipos.ESTADO_OPERATIVO', '!=', 'DESINCORPORADO')
            ->where(function ($w) use ($like) {
                $w->whereRaw('UPPER(equipos.CODIGO_PATIO) like ?', [$like])
                  ->orWhereRaw('UPPER(equipos.SERIAL_CHASIS) like ?', [$like])
                  ->orWhereRaw('UPPER(equipos.SERIAL_DE_MOTOR) like ?', [$like])
                  ->orWhereRaw('UPPER(d.PLACA) like ?', [$like]);
            })->limit(15)->get();

        // Auxiliares: busca por serial, código interno, modelo.
        $aux = EquipoAuxiliar::query()
            ->leftJoin('frentes_trabajo AS ft2',
                'equipos_auxiliares.ID_FRENTE_ACTUAL', '=', 'ft2.ID_FRENTE')
            ->select('equipos_auxiliares.*', 'ft2.NOMBRE_FRENTE AS AUX_FRENTE')
            ->where('equipos_auxiliares.ESTADO_OPERATIVO', '!=', 'DESINCORPORADO')
            ->where(function ($w) use ($like) {
                $w->whereRaw('UPPER(equipos_auxiliares.SERIAL) like ?', [$like])
                  ->orWhereRaw('UPPER(equipos_auxiliares.CODIGO_INTERNO) like ?', [$like])
                  ->orWhereRaw('UPPER(equipos_auxiliares.MODELO) like ?', [$like]);
            })->limit(15)->get();

        $results = [];
        foreach ($eqs as $e) {
            // Mismo orden que el resto del sistema (Equipo model accessor + EquipoController):
            // 1º FOTO_REFERENCIAL del catálogo del modelo → 2º FOTO_EQUIPO propia del equipo
            $rawFoto = ($e->especificaciones && $e->especificaciones->FOTO_REFERENCIAL)
                ? $e->especificaciones->FOTO_REFERENCIAL
                : $e->FOTO_EQUIPO;
            $foto = $rawFoto ?? '';
            if ($foto && !str_starts_with($foto, 'http') && !str_starts_with($foto, '/')) {
                $foto = '/storage/' . ltrim($foto, '/');
            }
            $results[] = [
                'tipo'         => 'equipo',
                'id'           => $e->ID_EQUIPO,
                'tipo_nombre'  => strtoupper($e->TIPO_NOMBRE ?? 'VEHÍCULO'),
                'marca'        => strtoupper($e->MARCA ?? ''),
                'label'        => trim(($e->MARCA ?? '') . ' ' . ($e->MODELO ?? '')),
                'placa'        => $e->PLACA ?? '',
                'serial'       => $e->SERIAL_CHASIS ?? '',
                'serial_motor' => $e->SERIAL_DE_MOTOR ?? '',
                'codigo'       => $e->CODIGO_PATIO ?? '',
                'estado'       => $e->ESTADO_OPERATIVO ?? '',
                'foto'         => $foto ?? '',
                'frente'       => $e->NOMBRE_FRENTE ?? '',
            ];
        }
        foreach ($aux as $a) {
            $foto = $a->FOTO ?? '';
            if ($foto && !str_starts_with($foto, 'http') && !str_starts_with($foto, '/')) {
                $foto = '/storage/' . ltrim($foto, '/');
            }
            $results[] = [
                'tipo'         => 'equipo_auxiliar',
                'id'           => $a->ID_AUXILIAR,
                'tipo_nombre'  => strtoupper($a->TIPO ?? 'AUXILIAR'),
                'marca'        => strtoupper($a->MARCA ?? ''),
                'label'        => trim(($a->MARCA ?? '') . ' ' . ($a->MODELO ?? '')),
                'placa'        => '',
                'serial'       => $a->SERIAL ?? '',
                'serial_motor' => '',
                'codigo'       => $a->CODIGO_INTERNO ?? '',
                'estado'       => $a->ESTADO_OPERATIVO ?? '',
                'foto'         => $foto,
                'frente'       => $a->AUX_FRENTE ?? '',
            ];
        }

        return response()->json(['results' => $results]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Lock pesimista del activo para evitar carreras al actualizar estado.
     * Retorna el modelo (Equipo o EquipoAuxiliar) o null si no existe.
     */
    private function lockActivo(string $tipo, int $id)
    {
        if ($tipo === 'equipo') {
            return Equipo::where('ID_EQUIPO', $id)->lockForUpdate()->first();
        }
        if ($tipo === 'equipo_auxiliar') {
            return EquipoAuxiliar::where('ID_AUXILIAR', $id)->lockForUpdate()->first();
        }
        return null;
    }

    /**
     * Genera RF-NNNNN secuencial. Numerador derivado del MAX actual + 1.
     * Llamar dentro de DB::transaction (lockForUpdate del MAX).
     */
    private function generateCodigoReporte(bool $lock = true): string
    {
        // $lock=false para la VISTA PREVIA: solo "espía" el siguiente código sin
        // bloquear ni consumir el numerador (el real se asigna al guardar).
        $q = Falla::where('CODIGO_REPORTE', 'like', 'RF-%');
        if ($lock) {
            $q->lockForUpdate();
        }
        $last = $q->orderByDesc('ID_FALLA')->value('CODIGO_REPORTE');
        $n = 0;
        if ($last && preg_match('/RF-(\d+)/', $last, $m)) {
            $n = (int) $m[1];
        }
        return 'RF-' . str_pad((string) ($n + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Inserta una entrada en fallas_audit_log. La tabla NO tiene FK
     * enforced — guarda snapshot del usuario para que el log persista
     * aunque el usuario se borre.
     */
    private function logAction(?int $idFalla, string $activoTipo, int $activoId, string $accion, array $meta = []): void
    {
        $user = auth()->user();
        DB::table('fallas_audit_log')->insert([
            'ID_FALLA'      => $idFalla,
            'ACTIVO_TIPO'   => $activoTipo,
            'ACTIVO_ID'     => $activoId,
            'ACCION'        => $accion,
            'METADATA'      => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'ID_USUARIO'    => $user->ID_USUARIO,
            'NOMBRE_USUARIO'=> $user->NOMBRE_COMPLETO,
            'EMAIL_USUARIO' => $user->CORREO_ELECTRONICO,
            'CREADO_EN'     => now(),
        ]);
    }

    /**
     * Stats del sidebar: TOTAL flota (vehiculos + aux), INOPERATIVO,
     * EN MANTENIMIENTO, REPORTES_ABIERTOS. Respeta los filtros del request
     * que aplican a equipos (id_frente, tipo_activo, marca, modelo, search)
     * para que el sidebar refleje la vista activa, igual que /admin/equipos.
     */
    private function buildStats(Request $request): array
    {
        [$eqQ, $auxQ] = $this->buildActivoQueriesForStats($request);

        // El Consolidado muestra Reportes Abiertos / Cerrados. Los $eqQ/$auxQ se usan
        // para acotar las fallas a los activos filtrados (ids de abajo).
        // Reportes: aplica los filtros equivalentes sobre la tabla fallas
        // (id_frente, tipo_activo, marca, modelo, search se filtran por activo).
        $eqIds  = (clone $eqQ)->pluck('equipos.ID_EQUIPO')->toArray();
        $auxIds = (clone $auxQ)->pluck('ID_AUXILIAR')->toArray();

        $reportesQ = Falla::query();
        if ($request->filled('fecha_desde')) {
            $reportesQ->whereDate('FECHA_EMISION', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $reportesQ->whereDate('FECHA_EMISION', '<=', $request->fecha_hasta);
        }
        if ($request->filled('responsable')) {
            $reportesQ->where('ID_USUARIO_REPORTA', $request->responsable);
        }
        // Si hay filtros equipo-level activos, restringimos a las fallas que apuntan
        // a esos activos. Sin filtros, no aplicamos restriccion (cuenta global).
        $hasEqFilter = $request->filled('id_frente') || $request->filled('tipo_activo')
                    || $request->filled('marca') || $request->filled('modelo')
                    || $request->filled('search');
        if ($hasEqFilter) {
            $reportesQ->where(function ($q) use ($eqIds, $auxIds) {
                $q->where(fn($i) => $i->where('ACTIVO_TIPO','equipo')->whereIn('ACTIVO_ID',$eqIds ?: [0]))
                  ->orWhere(fn($i) => $i->where('ACTIVO_TIPO','equipo_auxiliar')->whereIn('ACTIVO_ID',$auxIds ?: [0]));
            });
        }

        $abiertos = (clone $reportesQ)->where('ESTADO_REPORTE', 'abierto')->count();
        $cerrados = (clone $reportesQ)->where('ESTADO_REPORTE', 'cerrado')->count();
        $total    = $abiertos + $cerrados;

        // Desglose por tipo de mantenimiento para el gráfico del Consolidado.
        // "Rápidos" = el resto (reportes cortos / sin tipo de mantenimiento), de modo
        // que preventivo + correctivo + rapidos = total siempre.
        $preventivo = (clone $reportesQ)->where('TIPO_MANTENIMIENTO', 'PREVENTIVO')->count();
        $correctivo = (clone $reportesQ)->where('TIPO_MANTENIMIENTO', 'CORRECTIVO')->count();
        $rapidos    = max(0, $total - $preventivo - $correctivo);

        return [
            'reportes_abiertos' => $abiertos,
            'reportes_cerrados' => $cerrados,
            'total_reportes'    => $total,
            'preventivo'        => $preventivo,
            'correctivo'        => $correctivo,
            'rapidos'           => $rapidos,
        ];
    }

    /**
     * Construye queries de Equipo y EquipoAuxiliar aplicando los filtros del
     * request que tienen sentido a nivel de activo. Usado para los conteos
     * del sidebar.
     */
    private function buildActivoQueriesForStats(Request $request): array
    {
        $eq  = Equipo::query()->select('equipos.*');
        $aux = EquipoAuxiliar::query();

        if ($request->filled('id_frente')) {
            $frId = (int) $request->id_frente;
            $eq->where('ID_FRENTE_ACTUAL', $frId);
            $aux->where('ID_FRENTE_ACTUAL', $frId);
        }

        if ($request->filled('marca')) {
            $mLike = '%' . mb_strtoupper(trim($request->marca)) . '%';
            $eq->whereRaw('UPPER(MARCA) like ?', [$mLike]);
            $aux->whereRaw('UPPER(MARCA) like ?', [$mLike]);
        }

        if ($request->filled('modelo')) {
            $moLike = '%' . mb_strtoupper(trim($request->modelo)) . '%';
            $eq->whereRaw('UPPER(MODELO) like ?', [$moLike]);
            $aux->whereRaw('UPPER(MODELO) like ?', [$moLike]);
        }

        $ta = $request->input('tipo_activo', '');
        if ($ta === 'equipo') {
            $aux->whereRaw('1 = 0');
        } elseif ($ta === 'equipo_auxiliar') {
            $eq->whereRaw('1 = 0');
        } elseif (str_starts_with($ta, 'tipo_eq:')) {
            $tipoId = (int) substr($ta, 8);
            $eq->where('id_tipo_equipo', $tipoId);
            $aux->whereRaw('1 = 0');
        } elseif (str_starts_with($ta, 'tipo_aux:')) {
            $auxTipo = substr($ta, 9);
            $aux->where('TIPO', $auxTipo);
            $eq->whereRaw('1 = 0');
        }

        if ($request->filled('search')) {
            $sClean = mb_strtoupper(ltrim(trim($request->input('search')), '#'));
            $like = "%{$sClean}%";
            $eq->leftJoin('documentacion AS d_stats', 'equipos.ID_EQUIPO', '=', 'd_stats.ID_EQUIPO')
               ->where(function ($w) use ($like) {
                   $w->whereRaw('UPPER(equipos.SERIAL_CHASIS) like ?', [$like])
                     ->orWhereRaw('UPPER(equipos.SERIAL_DE_MOTOR) like ?', [$like])
                     ->orWhereRaw('UPPER(equipos.CODIGO_PATIO) like ?', [$like])
                     ->orWhereRaw('UPPER(d_stats.PLACA) like ?', [$like]);
               });
            $aux->where(function ($w) use ($like) {
                $w->whereRaw('UPPER(SERIAL) like ?', [$like])
                  ->orWhereRaw('UPPER(CODIGO_INTERNO) like ?', [$like]);
            });
        }

        return [$eq, $aux];
    }

    /**
     * PDF acta del reporte (TCPDF). Solo aplica a TIPO_REPORTE=extenso.
     */
    public function pdf($id)
    {
        $falla = Falla::findOrFail($id);
        if ($falla->TIPO_REPORTE !== 'extenso') {
            return back()->withErrors(['error' => 'Solo los reportes extensos generan acta PDF.']);
        }

        $pdf = $this->buildActaPdf($falla, $falla->activo());
        return $pdf->Output('Reporte_Falla_' . $falla->CODIGO_REPORTE . '.pdf', 'I');
    }

    /**
     * Vista previa del acta SIN guardar el reporte (flujo "Editar/Confirmar" del
     * modal, igual que la vista previa del acta de movilización). Construye una
     * Falla EN MEMORIA con los datos del formulario y devuelve el PDF inline.
     * Solo aplica a 'extenso' (el reporte corto no tiene acta).
     */
    public function previewPdf(Request $request)
    {
        $request->validate([
            'tipo_reporte'       => 'required|in:corto,extenso',
            'activo_tipo'        => 'required|in:equipo,equipo_auxiliar',
            'activo_id'          => 'required|integer',
            'descripcion'        => 'required|string|max:5000',
            'tipo_mantenimiento' => 'nullable|in:PREVENTIVO,CORRECTIVO',
        ]);
        if ($request->tipo_reporte !== 'extenso') {
            return response()->json(['message' => 'Solo el reporte extenso genera acta PDF.'], 422);
        }
        $activo = $request->activo_tipo === 'equipo'
            ? Equipo::find($request->activo_id)
            : EquipoAuxiliar::find($request->activo_id);
        if (!$activo) {
            return response()->json(['message' => 'Activo no encontrado.'], 404);
        }
        // Falla en memoria (NO se guarda); código de muestra sin consumir el numerador.
        $falla = new Falla($this->fallaAttributes($request, $activo, $this->generateCodigoReporte(false)));
        $pdf = $this->buildActaPdf($falla, $activo);
        return $pdf->Output('Vista_Previa_Reporte_Falla.pdf', 'I');
    }

    /**
     * Construye el TCPDF del acta (cabezote + cuerpo) a partir de una Falla —
     * GUARDADA o EN MEMORIA (vista previa). Mismo logo corporativo que el acta de
     * movilización, con header propio "REPORTE DE FALLAS". Lo comparten pdf() y previewPdf().
     */
    private function buildActaPdf(Falla $falla, $activo): ReporteFallaPDF
    {
        $pdf = new ReporteFallaPDF(
            PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false
        );
        $pdf->codigoReporte = $falla->CODIGO_REPORTE;
        $pdf->fechaEmision  = $falla->FECHA_EMISION->format('d/m/Y');
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(false);   // sin pie "EMITIDO POR..."
        // Márgenes izq/der iguales (17mm) -> tabla centrada. Aire superior/inferior iguales
        // (16mm vía SetHeaderMargin y AutoPageBreak). El margen SUPERIOR (43.16mm) está atado
        // al alto del cabezote: arranca en y=16 y mide $headerHeight=77pt (≈27.16mm), por lo
        // que el cuerpo arranca PEGADO a él, sin franja blanca ni solape. Si se cambia el alto
        // o las filas del cabezote (Header), reajustar este margen (16 + alto en mm).
        $pdf->SetMargins(17, 43.16, 17);
        $pdf->SetHeaderMargin(16);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->SetTitle('Reporte de Fallas ' . $falla->CODIGO_REPORTE);
        $pdf->SetAuthor('Constructora Vidalsa 27, C.A.');
        $pdf->SetCreator('Sistema de Gestión VIDALSA');
        $pdf->AddPage();
        // Línea fina 0.1mm: las tablas del cuerpo heredan el mismo grosor que el cabezote.
        $pdf->SetLineWidth(0.1);
        $pdf->SetFont('helvetica', '', 9);

        $html = view('admin.fallas.acta_falla_pdf', compact('falla', 'activo'))->render();
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf;
    }
}

/**
 * PDF para reportes de falla. Mismo logo corporativo (imagen_uno.jpg)
 * que el acta de movilizacion. Header con codigo + fecha de emision
 * (datos pasados via propiedades publicas para no acoplarlo al modelo).
 */
class ReporteFallaPDF extends \TCPDF
{
    public $codigoReporte = '';
    public $fechaEmision  = '';

    public function Header()
    {
        // ── Cabezote oficial (mismo patrón que el acta de movilización) ──
        //    [LOGO 20%] | REPORTE DE FALLAS (52%) | [SELLO 28%] — una sola tabla con
        //    bordes CSS finos (0.1mm); el logo se superpone con Image() sobre la 1.ª celda.
        $this->SetLineWidth(0.1);

        // Alto TOTAL del cabezote (pt). Las 5 filas de control reparten este alto
        // (rowH cada una) para que el cabezote conserve su altura aunque tenga menos filas.
        $headerHeight = 77;          // pt (≈ 27.2 mm)
        $ctrlRowH     = $headerHeight / 5;   // 5 filas: Código, Revisión, Sección, Fecha, Página
        // writeHTML aplica a las tablas del cuerpo un inset IZQUIERDO fijo de 5pt que
        // writeHTMLCell (este cabezote) NO aplica; sin compensarlo el encabezado sobresale
        // ~1.76mm por la izquierda. Lo replicamos para que cabezote y cuerpo arranquen en la
        // MISMA x y queden del mismo ancho y "pegados" (el borde derecho ya cae en el margen).
        $inset = 5 / $this->getScaleFactor();                      // 5pt -> mm (≈1.76)
        $cabX  = $this->lMargin + $inset;                          // ≈18.76mm
        $cabY  = 16;
        $cabW  = ($this->w - $this->lMargin - $this->rMargin) - $inset;  // ≈174.24mm
        $cabH  = 24;                 // alto (mm) del recuadro del LOGO, no del cabezote
        $logoCellW = $cabW * 0.20;   // alinea con la 1.ª col del cuerpo

        $img = public_path('img/imagen_uno.jpg');
        if (file_exists($img)) {
            $padding = 1;
            $this->Image($img, $cabX + $padding, $cabY + $padding,
                $logoCellW - ($padding * 2), $cabH - ($padding * 2),
                'JPG', '', '', false, 300, '', false, false, 0, 'CM', false, false);
        }

        $codigo = strtoupper($this->codigoReporte ?: '—');
        $fecha  = $this->fechaEmision ?: \Carbon\Carbon::now()->format('d/m/Y');

        // Centrado vertical del titulo dentro del rowspan: line-height ≈ alto del rowspan.
        $tituloDiv = '<div style="text-align:center;line-height:' . ($headerHeight - 6)
                   . 'pt;font-family:helvetica;font-size:13pt;font-weight:bold;">REPORTE DE FALLAS</div>';

        // Bloque de control igual al formato oficial (azul claro en el titulo).
        // Borde por CSS a 0.1mm (NO border="1", que TCPDF dibuja ~0.35mm e ignora
        // SetLineWidth) — mismo grosor fino que el acta de movilizacion del modulo
        // equipos. border-collapse evita el efecto "doble linea".
        $bs   = 'border:0.1mm solid #000;';
        $html = '<table cellpadding="2" cellspacing="0" width="100%" style="border-collapse:collapse;">'
              . '<tr>'
              .   '<td width="20%" rowspan="5" height="' . $headerHeight . '" style="' . $bs . '">&nbsp;</td>'
              .   '<td width="52%" rowspan="5" height="' . $headerHeight . '" align="center" valign="middle" bgcolor="#d6e0f2" style="' . $bs . '">' . $tituloDiv . '</td>'
              .   '<td width="28%" height="' . $ctrlRowH . '" align="center" style="' . $bs . '"><font face="helvetica" size="7"><b>Código:</b> ' . htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') . '</font></td>'
              . '</tr>'
              . '<tr><td width="28%" height="' . $ctrlRowH . '" align="center" style="' . $bs . '"><font face="helvetica" size="7"><b>Revisión:</b> 1</font></td></tr>'
              . '<tr><td width="28%" height="' . $ctrlRowH . '" align="center" style="' . $bs . '"><font face="helvetica" size="7"><b>Sección:</b> Mantenimiento</font></td></tr>'
              . '<tr><td width="28%" height="' . $ctrlRowH . '" align="center" style="' . $bs . '"><font face="helvetica" size="7"><b>Fecha de Emisión:</b> ' . $fecha . '</font></td></tr>'
              . '<tr><td width="28%" height="' . $ctrlRowH . '" align="center" style="' . $bs . '"><font face="helvetica" size="7">Página 1 de 1</font></td></tr>'
              . '</table>';

        $this->SetFont('helvetica', '', 7);
        $this->writeHTMLCell($cabW, 0, $cabX, $cabY, $html, 0, 0, 0, true, 'L', true);
    }

    // Sin Footer(): el pie corporativo se desactivó con setPrintFooter(false) en pdf().
}
