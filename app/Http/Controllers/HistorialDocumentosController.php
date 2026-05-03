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
        // ── Scope LOCAL ─────────────────────────────────────────────────────
        // Usuarios NIVEL_ACCESO=2 (local) solo ven el historial de equipos en
        // los frentes que tienen asignados. Sin frentes => ven nada.
        // Los super.admin / global ven todo el historial.
        $user              = auth()->user();
        $isLocalUser       = $user && $user->NIVEL_ACCESO == 2;
        $frentesPermitidos = $user ? $user->getFrentesIds() : [];

        // Pre-filtros que se aplican en SQL (no en memoria) para escalar bien.
        // Si el dataset crece a decenas de miles, esto evita cargar todo a RAM.
        $fechaDesdeSql = $request->filled('fecha_desde')
            ? Carbon::parse($request->fecha_desde)->startOfDay()
            : null;
        $fechaHastaSql = $request->filled('fecha_hasta')
            ? Carbon::parse($request->fecha_hasta)->endOfDay()
            : null;
        $searchEquipoSql = trim((string) $request->input('search_equipo', ''));

        // Helper que aplica el scope LOCAL a un query Eloquent que tiene relacion
        // 'equipo'. Usado en Documentacion y EquipoAuditLog (que SI tienen relacion).
        $applyLocalScopeViaWhereHas = function ($query) use ($isLocalUser, $frentesPermitidos) {
            if (!$isLocalUser) return;
            if (empty($frentesPermitidos)) {
                $query->whereRaw('1 = 0'); // local sin frentes => sin resultados
                return;
            }
            $query->whereHas('equipo', function ($q) use ($frentesPermitidos) {
                $q->withTrashed()->whereIn('ID_FRENTE_ACTUAL', $frentesPermitidos);
            });
        };

        // Helper que aplica el filtro search_equipo (placa/serial/codigo) en SQL.
        $applySearchEquipoViaWhereHas = function ($query) use ($searchEquipoSql) {
            if ($searchEquipoSql === '') return;
            $like = '%' . strtoupper($searchEquipoSql) . '%';
            $query->whereHas('equipo', function ($q) use ($like) {
                $q->withTrashed()->where(function ($w) use ($like) {
                    $w->whereRaw('UPPER(SERIAL_CHASIS) like ?', [$like])
                      ->orWhereRaw('UPPER(CODIGO_PATIO) like ?', [$like])
                      ->orWhereHas('documentacion', function ($qd) use ($like) {
                          $qd->whereRaw('UPPER(PLACA) like ?', [$like]);
                      });
                });
            });
        };

        // 1. Documentacion con upload — pre-filtros + LIMIT.
        $docsQuery = Documentacion::with([
                'equipo' => function ($q) { $q->withTrashed()->with('tipo'); },
                'usuarioPropiedad', 'usuarioPoliza', 'usuarioRotc',
                'usuarioRacda',     'usuarioAdicional', 'usuarioAdicional2',
            ])
            ->where(function ($q) {
                foreach (self::DOC_FIELD_MAP as $cfg) {
                    $q->orWhereNotNull($cfg['fecha_col']);
                }
            });

        $applyLocalScopeViaWhereHas($docsQuery);
        $applySearchEquipoViaWhereHas($docsQuery);

        // Filtro fecha en SQL: si hay rango, exigimos que AL MENOS una de las 6
        // fechas de subida caiga en el rango. Acelera muchisimo en datasets grandes.
        if ($fechaDesdeSql || $fechaHastaSql) {
            $docsQuery->where(function ($q) use ($fechaDesdeSql, $fechaHastaSql) {
                foreach (self::DOC_FIELD_MAP as $cfg) {
                    $q->orWhere(function ($qq) use ($cfg, $fechaDesdeSql, $fechaHastaSql) {
                        $qq->whereNotNull($cfg['fecha_col']);
                        if ($fechaDesdeSql) $qq->where($cfg['fecha_col'], '>=', $fechaDesdeSql);
                        if ($fechaHastaSql) $qq->where($cfg['fecha_col'], '<=', $fechaHastaSql);
                    });
                }
            });
        }

        $docs = $docsQuery->limit(5000)->get();

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
        // restringido a las columnas necesarias + pre-filtros en SQL + LIMIT.
        $equiposQuery = \App\Models\Equipo::with([
                'tipo:id,nombre',
                'creador:ID_USUARIO,CORREO_ELECTRONICO',
                'documentacion:ID_EQUIPO,PLACA',
            ])
            ->whereNotNull('CREADO_POR');

        // Scope LOCAL en SQL.
        if ($isLocalUser) {
            if (empty($frentesPermitidos)) {
                $equiposQuery->whereRaw('1 = 0');
            } else {
                $equiposQuery->whereIn('ID_FRENTE_ACTUAL', $frentesPermitidos);
            }
        }

        // search_equipo en SQL (placa/serial/codigo).
        if ($searchEquipoSql !== '') {
            $like = '%' . strtoupper($searchEquipoSql) . '%';
            $equiposQuery->where(function ($q) use ($like) {
                $q->whereRaw('UPPER(SERIAL_CHASIS) like ?', [$like])
                  ->orWhereRaw('UPPER(CODIGO_PATIO) like ?', [$like])
                  ->orWhereHas('documentacion', function ($qd) use ($like) {
                      $qd->whereRaw('UPPER(PLACA) like ?', [$like]);
                  });
            });
        }

        // Filtro fecha en SQL aplicado a created_at del equipo.
        if ($fechaDesdeSql) $equiposQuery->where('created_at', '>=', $fechaDesdeSql);
        if ($fechaHastaSql) $equiposQuery->where('created_at', '<=', $fechaHastaSql);

        $equiposCreados = $equiposQuery->orderByDesc('created_at')->limit(5000)->get();

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
            $auditQuery = \App\Models\EquipoAuditLog::with([
                    'equipo' => function ($q) { $q->withTrashed()->with(['tipo', 'documentacion']); },
                    'usuario',
                ])
                ->orderByDesc('created_at');

            // Scope LOCAL + search_equipo + rango de fechas en SQL.
            $applyLocalScopeViaWhereHas($auditQuery);
            $applySearchEquipoViaWhereHas($auditQuery);
            if ($fechaDesdeSql) $auditQuery->where('created_at', '>=', $fechaDesdeSql);
            if ($fechaHastaSql) $auditQuery->where('created_at', '<=', $fechaHastaSql);

            $auditLogs = $auditQuery->limit(5000)->get();
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
        //      Tiene link al PDF y label amigable.
        //   2) "Subida Propiedad"   desde el audit log (loop audit).
        //      Sin link al PDF (audit log no guarda URL).
        // Mantener el evento LEGACY (con link) y descartar el audit log "upload_X"
        // cuando exista equivalente en el legacy. El boton "Ver PDF" funciona
        // porque conserva el link del legacy. Si solo hay audit log (caso edge sin
        // legacy), el evento se preserva pero no muestra boton PDF.
        $legacyKeys = [];
        $uploadToLegacy = [
            'upload_propiedad'   => 'propiedad',
            'upload_poliza'      => 'poliza',
            'upload_rotc'        => 'rotc',
            'upload_racda'       => 'racda',
            'upload_adicional'   => 'adicional',
            'upload_adicional_2' => 'adicional_2',
        ];
        foreach ($events as $e) {
            if (in_array($e->doc_key, $uploadToLegacy, true)) {
                $legacyKeys[$e->equipo_db_id . '|' . $e->doc_key . '|' . $e->fecha->format('Y-m-d')] = true;
            }
        }
        $events = $events->filter(function ($e) use ($uploadToLegacy, $legacyKeys) {
            if (isset($uploadToLegacy[$e->doc_key])) {
                $key = $e->equipo_db_id . '|' . $uploadToLegacy[$e->doc_key] . '|' . $e->fecha->format('Y-m-d');
                return !isset($legacyKeys[$key]);
            }
            return true;
        });

        // 3. Sort descending by date
        $events = $events->sortByDesc('fecha')->values();

        // 4. Filtros finales en memoria sobre los eventos ya construidos.
        //    - search_correo y search_tipo: NO se pueden hacer en SQL porque
        //      'autor' es un correo derivado de 6 relaciones distintas + el label
        //      'tipo' es un string construido en PHP (no existe en DB).
        //    - fecha_desde/hasta: se aplico en SQL para REDUCIR el dataset, pero
        //      hay que repetir aqui porque un Documentacion tiene 6 fechas y solo
        //      necesitamos que UNA caiga en rango para traerlo; los eventos de las
        //      OTRAS fechas del mismo doc deben filtrarse aqui.
        //    - search_equipo y scope LOCAL ya se filtraron en SQL completamente.
        $hasInMemoryFilter = $request->filled('search_correo')
                          || $request->filled('search_tipo')
                          || $fechaDesdeSql || $fechaHastaSql;
        if ($hasInMemoryFilter) {
            $normalize = fn ($s) => mb_strtolower(\Illuminate\Support\Str::ascii((string) $s));

            $search_correo = $normalize($request->search_correo);
            $search_tipo   = $normalize($request->search_tipo);

            $events = $events->filter(function ($event) use ($normalize, $search_correo, $search_tipo, $fechaDesdeSql, $fechaHastaSql) {
                if ($search_correo && strpos($normalize($event->autor), $search_correo) === false) {
                    return false;
                }
                if ($search_tipo && $search_tipo !== 'all' && strpos($normalize($event->tipo), $search_tipo) === false) {
                    return false;
                }
                if ($fechaDesdeSql && $event->fecha->lt($fechaDesdeSql)) return false;
                if ($fechaHastaSql && $event->fecha->gt($fechaHastaSql)) return false;
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
     * Desbloquear IP. El permiso 'super.admin' ya se valida en el group de rutas
     * en routes/web.php (Route::middleware('can:super.admin')->group), por eso
     * no se duplica el check aqui.
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
