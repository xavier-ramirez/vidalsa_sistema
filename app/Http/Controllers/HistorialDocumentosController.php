<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Documentacion;
use App\Models\BloqueoIp;
use Carbon\Carbon;

class HistorialDocumentosController extends Controller
{
    /**
     * Construye el identificador de equipo para mostrar en la tabla del historial,
     * consistente entre los 3 loops (docs, equipos creados, audit logs). Prefiere
     * Placa → Serial Chasis → ID. Acepta tanto Equipo como (Equipo + placa string).
     */
    private function buildEquipoId($equipo, ?string $placa = null): string
    {
        if (!$equipo) return '#';
        if (!empty($placa)) {
            return 'Placa: ' . $placa;
        }
        // Fallback a la relacion documentacion si esta cargada.
        if (!empty(optional($equipo->documentacion ?? null)->PLACA)) {
            return 'Placa: ' . $equipo->documentacion->PLACA;
        }
        if (!empty($equipo->SERIAL_CHASIS)) {
            return 'Serial Chasis: ' . $equipo->SERIAL_CHASIS;
        }
        return 'ID: ' . $equipo->ID_EQUIPO;
    }

    /**
     * Mapeo tabla-driven de los 6 tipos de documento que la tabla `documentacion`
     * registra via flags FECHA_SUBIDA/SUBIDO_POR. Usado para evitar 6 bloques
     * foreach copiados-pegados en index(). Cada entrada describe la columna de
     * fecha, autor, link, la relacion del usuario y el doc_key/label legacy.
     */
    private const DOC_FIELD_MAP = [
        'propiedad' => [
            'fecha_col' => 'PROPIEDAD_FECHA_SUBIDA',
            'autor_col' => 'PROPIEDAD_SUBIDO_POR',
            'link_col'  => 'LINK_DOC_PROPIEDAD',
            'user_rel'  => 'usuarioPropiedad',
            'label'     => 'Título de Propiedad',
        ],
        'poliza' => [
            'fecha_col' => 'POLIZA_FECHA_SUBIDA',
            'autor_col' => 'POLIZA_SUBIDO_POR',
            'link_col'  => 'LINK_POLIZA_SEGURO',
            'user_rel'  => 'usuarioPoliza',
            'label'     => 'Póliza de Seguro',
        ],
        'rotc' => [
            'fecha_col' => 'ROTC_FECHA_SUBIDA',
            'autor_col' => 'ROTC_SUBIDO_POR',
            'link_col'  => 'LINK_ROTC',
            'user_rel'  => 'usuarioRotc',
            'label'     => 'ROTC',
        ],
        'racda' => [
            'fecha_col' => 'RACDA_FECHA_SUBIDA',
            'autor_col' => 'RACDA_SUBIDO_POR',
            'link_col'  => 'LINK_RACDA',
            'user_rel'  => 'usuarioRacda',
            'label'     => 'RACDA',
        ],
        'adicional' => [
            'fecha_col' => 'ADICIONAL_FECHA_SUBIDA',
            'autor_col' => 'ADICIONAL_SUBIDO_POR',
            'link_col'  => 'LINK_DOC_ADICIONAL',
            'user_rel'  => 'usuarioAdicional',
            'label'     => 'Certificado Asociado',
        ],
        'adicional_2' => [
            'fecha_col' => 'ADICIONAL_2_FECHA_SUBIDA',
            'autor_col' => 'ADICIONAL_2_SUBIDO_POR',
            'link_col'  => 'LINK_DOC_ADICIONAL_2',
            'user_rel'  => 'usuarioAdicional2',
            'label'     => 'Compraventa',
        ],
    ];

    public function index(Request $request)
    {
        // 1. Fetch all documentation that has at least one upload.
        // whereNotNull/orWhereNotNull agrupados en closure para que la precedencia
        // AND/OR se mantenga si en el futuro se anaden filtros previos.
        // El eager-load del equipo carga solo `tipo` (frenteActual no se usa en
        // ningun campo del evento — eliminado para no traer datos sobrantes).
        $docs = Documentacion::with([
                'equipo' => function ($q) { $q->withTrashed()->with('tipo'); },
                'usuarioPropiedad', 'usuarioPoliza', 'usuarioRotc',
                'usuarioRacda',     'usuarioAdicional', 'usuarioAdicional2',
            ])
            ->where(function ($q) {
                foreach (self::DOC_FIELD_MAP as $cfg) {
                    $q->orWhereNotNull($cfg['fecha_col']);
                }
            })
            ->get();

        // 2. Parse them into a flat array of "upload events".
        // Las 6 entradas de DOC_FIELD_MAP eliminan 6 bloques foreach copiados.
        // Las fechas vienen como Carbon gracias a los casts del modelo Documentacion
        // (PROPIEDAD_FECHA_SUBIDA, etc. = 'datetime'), no requiere Carbon::parse.
        $events = collect();

        foreach ($docs as $doc) {
            $eName = $doc->equipo
                ? ($doc->equipo->tipo->nombre ?? 'Equipo') . ' ' . $doc->equipo->MARCA . ' ' . $doc->equipo->MODELO
                : 'Equipo Eliminado';
            $eId   = $this->buildEquipoId($doc->equipo, $doc->PLACA ?? null);

            foreach (self::DOC_FIELD_MAP as $docKey => $cfg) {
                $fecha = $doc->{$cfg['fecha_col']};
                $autorId = $doc->{$cfg['autor_col']};
                if (!$fecha || !$autorId) continue;

                $autor = $doc->{$cfg['user_rel']}
                    ? $doc->{$cfg['user_rel']}->CORREO_ELECTRONICO
                    : $autorId;

                $events->push((object)[
                    'doc_key'      => $docKey,
                    'tipo'         => $cfg['label'],
                    'autor'        => $autor,
                    'fecha'        => $fecha,
                    'link'         => $doc->{$cfg['link_col']},
                    'equipo_nombre'=> $eName,
                    'equipo_id'    => $eId,
                    'equipo_db_id' => $doc->equipo ? $doc->equipo->ID_EQUIPO : null,
                ]);
            }
        }

        // Eventos de "Registro de Vehiculo" (creacion). Eager-load del creador
        // restringido a las columnas necesarias para no cargar el modelo entero.
        $equiposCreados = \App\Models\Equipo::with([
                'tipo:id,nombre',
                'creador:ID_USUARIO,CORREO_ELECTRONICO',
            ])
            ->whereNotNull('CREADO_POR')
            ->get();

        foreach ($equiposCreados as $equipo) {
            $events->push((object)[
                'doc_key'      => 'creacion',
                'tipo'         => 'Registro de Vehículo',
                'autor'        => $equipo->creador ? $equipo->creador->CORREO_ELECTRONICO : 'Usuario Desconocido',
                'fecha'        => $equipo->created_at, // Eloquent castea created_at a Carbon automaticamente
                'link'         => null,
                'equipo_nombre'=> ($equipo->tipo->nombre ?? 'Equipo') . ' ' . $equipo->MARCA . ' ' . $equipo->MODELO,
                'equipo_id'    => $this->buildEquipoId($equipo),
                'equipo_db_id' => $equipo->ID_EQUIPO,
            ]);
        }

        // Eventos de AUDITORIA de equipos (ediciones, cambios de metadata, ubicacion).
        // Se cargan desde la tabla `equipo_audit_log` con eager loading de
        // equipo + tipo + documentacion + usuario para evitar N+1 y poder mostrar
        // PLACA en el equipo_id (consistente con los otros loops).
        try {
            // withTrashed: incluye equipos soft-deleted asi los logs de
            // tipo 'delete' tambien muestran tipo/marca/modelo del equipo
            // borrado en lugar de un generico "Equipo Eliminado".
            $auditLogs = \App\Models\EquipoAuditLog::with([
                    'equipo' => function ($q) { $q->withTrashed()->with(['tipo', 'documentacion']); },
                    'usuario',
                ])
                ->orderByDesc('created_at')
                ->limit(5000)
                ->get();
            foreach ($auditLogs as $log) {
                $eq = $log->equipo;
                $eName = $eq ? (($eq->tipo->nombre ?? 'Equipo') . ' ' . $eq->MARCA . ' ' . $eq->MODELO) : 'Equipo Eliminado';
                $eId   = $this->buildEquipoId($eq);
                // Mapping cubre solo las ACCION values que el codigo realmente
                // registra (verificado por busqueda de EquipoAuditLog::registrar
                // en EquipoController + EquipoObserver). Si en el futuro se agregan
                // nuevas acciones (status_change, ubicacion individual, etc.) se
                // deben mapear aqui Y agregar como opcion al dropdown del filtro.
                $tipoLabel = [
                    'edit'                 => 'Edición de Datos',
                    'metadata_propiedad'   => 'Edición Metadata Propiedad',
                    'metadata_poliza'      => 'Edición Metadata Póliza',
                    'metadata_rotc'        => 'Edición Metadata ROTC',
                    'metadata_racda'       => 'Edición Metadata RACDA',
                    'metadata_adicional'   => 'Edición Metadata Certificado',
                    'metadata_adicional_2' => 'Edición Metadata Compraventa',
                    'upload_propiedad'     => 'Subida Propiedad',
                    'upload_poliza'        => 'Subida Póliza',
                    'upload_rotc'          => 'Subida ROTC',
                    'upload_racda'         => 'Subida RACDA',
                    'upload_adicional'     => 'Subida Certificado',
                    'upload_adicional_2'   => 'Subida Compraventa',
                    'bulk_ubicacion'       => 'Ubicación Masiva',
                    'delete'               => 'Eliminación de Equipo',
                ][$log->ACCION] ?? ucfirst(str_replace('_', ' ', $log->ACCION));

                $events->push((object)[
                    'doc_key'       => $log->ACCION,
                    'tipo'          => $tipoLabel,
                    'autor'         => $log->usuario ? $log->usuario->CORREO_ELECTRONICO : ('Usuario #' . $log->ID_USUARIO),
                    'fecha'         => $log->created_at, // EquipoAuditLog castea created_at a Carbon
                    'link'          => null,
                    'equipo_nombre' => $eName,
                    'equipo_id'     => $eId,
                    'equipo_db_id'  => $eq ? $eq->ID_EQUIPO : null,
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // Tabla no existente / driver distinto → no rompe la vista, solo skip.
            \Illuminate\Support\Facades\Log::warning('audit log read failed: ' . $e->getMessage());
        }

        // ── DEDUPLICACION legacy ↔ audit log ──────────────────────────────────
        // Cada subida de documento genera DOS eventos:
        //   1) "Título de Propiedad" desde el flag PROPIEDAD_FECHA_SUBIDA (loop docs).
        //   2) "Subida Propiedad"   desde el audit log (loop audit).
        // El audit log es la fuente autoritativa (mas detallada). Eliminamos los
        // legacy duplicados: si para el mismo (equipo, doc_key, dia) existe un
        // upload_X en el audit log, descartamos el evento legacy. Eventos legacy
        // sin equivalente en audit log (datos antiguos pre-audit) se preservan.
        $auditUploadKeys = [];
        $legacyToUpload  = [
            'propiedad'   => 'upload_propiedad',
            'poliza'      => 'upload_poliza',
            'rotc'        => 'upload_rotc',
            'racda'       => 'upload_racda',
            'adicional'   => 'upload_adicional',
            'adicional_2' => 'upload_adicional_2',
        ];
        foreach ($events as $e) {
            if (in_array($e->doc_key, $legacyToUpload, true)) {
                $auditUploadKeys[$e->equipo_db_id . '|' . $e->doc_key . '|' . $e->fecha->format('Y-m-d')] = true;
            }
        }
        $events = $events->filter(function ($e) use ($legacyToUpload, $auditUploadKeys) {
            if (isset($legacyToUpload[$e->doc_key])) {
                $key = $e->equipo_db_id . '|' . $legacyToUpload[$e->doc_key] . '|' . $e->fecha->format('Y-m-d');
                return !isset($auditUploadKeys[$key]);
            }
            return true;
        });

        // 3. Sort descending by date
        $events = $events->sortByDesc('fecha')->values();

        // 4. Apply filters (correo / equipo / tipo / rango de fechas)
        $hasFilter = $request->filled('search_correo') || $request->filled('search_equipo')
                  || $request->filled('search_tipo')
                  || $request->filled('fecha_desde') || $request->filled('fecha_hasta');
        if ($hasFilter) {
            // Normalizador con soporte de tildes: "Póliza" → "poliza", "Edición" → "edicion".
            // Sin esto, una busqueda por "poliza" (sin tilde) no encontraba "Póliza" porque
            // strtolower preserva la 'ó' acentuada y strpos compara byte-a-byte.
            $normalize = fn ($s) => mb_strtolower(\Illuminate\Support\Str::ascii((string) $s));

            $search_correo = $normalize($request->search_correo);
            $search_equipo = $normalize($request->search_equipo);
            $search_tipo   = $normalize($request->search_tipo);
            // Rango de fechas: comparamos contra event->fecha (siempre Carbon en este punto).
            $fechaDesde = $request->filled('fecha_desde') ? Carbon::parse($request->fecha_desde)->startOfDay() : null;
            $fechaHasta = $request->filled('fecha_hasta') ? Carbon::parse($request->fecha_hasta)->endOfDay() : null;

            $events = $events->filter(function ($event) use ($normalize, $search_correo, $search_equipo, $search_tipo, $fechaDesde, $fechaHasta) {
                if ($search_correo && strpos($normalize($event->autor), $search_correo) === false) {
                    return false;
                }
                if ($search_tipo && $search_tipo !== 'all' && strpos($normalize($event->tipo), $search_tipo) === false) {
                    return false;
                }
                if ($search_equipo) {
                    $equipoMatch = strpos($normalize($event->equipo_nombre), $search_equipo) !== false ||
                                   strpos($normalize($event->equipo_id), $search_equipo) !== false;
                    if (!$equipoMatch) return false;
                }
                if ($fechaDesde && $event->fecha->lt($fechaDesde)) return false;
                if ($fechaHasta && $event->fecha->gt($fechaHasta)) return false;
                return true;
            })->values();
        }

        $total = $events->count();

        // 5. Paginate manually mapping 20 by 20
        $perPage = 20;
        $page = $request->input('page', 1);
        
        $paginatedEvents = new \Illuminate\Pagination\LengthAwarePaginator(
            $events->forPage($page, $perPage)->values(), // important: values() to reset keys for blade loop
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Fetch blocked IPs (solo las EFECTIVAMENTE bloqueadas: >= 10 intentos
        // fallidos). Antes salian todas las IPs trackeadas, incluyendo las
        // que aun estaban en seguimiento sin alcanzar el umbral de bloqueo.
        $blockedIps = BloqueoIp::where('CANTIDAD_INTENTOS', '>=', 10)
            ->orderBy('ULTIMO_INTENTO', 'desc')
            ->get();

        // Usuarios con sesion activa en los ultimos 30 min (driver database).
        // Se lee directamente la tabla `sessions` (Laravel la crea cuando SESSION_DRIVER=database).
        // NOTA: el JOIN usa `sessions.user_id = usuarios.ID_USUARIO` — esto funciona porque
        // App\Models\Usuario sobrescribe $primaryKey = 'ID_USUARIO', asi Auth::id() devuelve
        // ese valor y Laravel lo persiste en sessions.user_id. La FK formal del schema
        // apunta a la tabla default `users` que NO se usa en este proyecto.
        // Ambas columnas del filtro (user_id y last_activity) estan indexadas.
        $activeUsers = collect();
        try {
            $cutoff = now()->subMinutes(30)->timestamp;

            // Subquery: la sesion MAS RECIENTE por cada user_id (evita que el mismo
            // usuario aparezca N veces si tiene varias sessions vivas: navegadores
            // distintos, sesiones de laptop + telefono, etc).
            $latestPerUser = \Illuminate\Support\Facades\DB::table('sessions')
                ->selectRaw('user_id, MAX(last_activity) as last_activity')
                ->whereNotNull('user_id')
                ->where('last_activity', '>=', $cutoff)
                ->groupBy('user_id');

            $activeUsers = \Illuminate\Support\Facades\DB::table('sessions')
                ->joinSub($latestPerUser, 'latest', function ($j) {
                    $j->on('sessions.user_id', '=', 'latest.user_id')
                      ->on('sessions.last_activity', '=', 'latest.last_activity');
                })
                ->join('usuarios', 'usuarios.ID_USUARIO', '=', 'sessions.user_id')
                ->select(
                    'usuarios.ID_USUARIO',
                    'usuarios.NOMBRE_COMPLETO',
                    'usuarios.CORREO_ELECTRONICO',
                    'sessions.ip_address',
                    'sessions.last_activity'
                )
                ->orderByDesc('sessions.last_activity')
                // Defensivo: si por carrera quedaran 2 filas con mismo last_activity,
                // elegimos solo 1 por usuario.
                ->get()
                ->unique('ID_USUARIO')
                ->values();
        } catch (\Illuminate\Database\QueryException $e) {
            // Solo atrapamos errores de query (tabla/col inexistente, driver distinto).
            // Errores de logica siguen burbujeando. Log::error con info real para observabilidad.
            \Illuminate\Support\Facades\Log::error('active users read failed', [
                'error'    => $e->getMessage(),
                'sql'      => method_exists($e, 'getRawSql') ? $e->getRawSql() : null,
                'bindings' => $e->getBindings(),
                'code'     => $e->getCode(),
            ]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'html' => view('admin.historial_documentos.partials.table_rows', ['events' => $paginatedEvents])->render(),
                'pagination' => $paginatedEvents->links('vendor.pagination.custom-sliding')->toHtml(),
                'total' => $total
            ]);
        }

        return view('admin.historial_documentos.index', [
            'events'      => $paginatedEvents,
            'total'       => $total,
            'blockedIps'  => $blockedIps,
            'activeUsers' => $activeUsers,
        ]);
    }

    /**
     * Desbloquear IP. El permiso 'super.admin' ya se valida en el middleware
     * de la ruta (routes/web.php linea 142: middleware('can:super.admin')),
     * no duplicamos el check aqui.
     */
    public function unlockIp($id)
    {
        try {
            $bloqueo = BloqueoIp::findOrFail($id);
            $ip = $bloqueo->DIRECCION_IP;
            $bloqueo->delete();

            return response()->json([
                'success' => true,
                'message' => "La IP {$ip} ha sido desbloqueada exitosamente."
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('unlockIp failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al desbloquear la IP.'
            ], 500);
        }
    }
}
