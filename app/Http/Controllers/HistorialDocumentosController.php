<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Documentacion;
use App\Models\BloqueoIp;
use Carbon\Carbon;

class HistorialDocumentosController extends Controller
{
    public function index(Request $request)
    {
        // 1. Fetch all documentation that has at least one upload
        // whereNotNull/orWhereNotNull agrupados en closure para que la precedencia
        // AND/OR se mantenga si en el futuro se anaden filtros previos (frente,
        // rango de fechas, etc). Sin el closure, un where('algo',…) antes se
        // perderia por la "OR chain" siguiente.
        $docs = Documentacion::with(['equipo.tipo', 'equipo.frenteActual', 'usuarioPropiedad', 'usuarioPoliza', 'usuarioRotc', 'usuarioRacda', 'usuarioAdicional', 'usuarioAdicional2'])
            ->where(function ($q) {
                $q->whereNotNull('PROPIEDAD_FECHA_SUBIDA')
                  ->orWhereNotNull('POLIZA_FECHA_SUBIDA')
                  ->orWhereNotNull('ROTC_FECHA_SUBIDA')
                  ->orWhereNotNull('RACDA_FECHA_SUBIDA')
                  ->orWhereNotNull('ADICIONAL_FECHA_SUBIDA')
                  ->orWhereNotNull('ADICIONAL_2_FECHA_SUBIDA');
            })
            ->get();

        // 2. Parse them into a flat array of "upload events"
        $events = collect();

        foreach ($docs as $doc) {
            $eName = $doc->equipo ? ($doc->equipo->tipo->nombre ?? 'Equipo') . ' ' . $doc->equipo->MARCA . ' ' . $doc->equipo->MODELO : 'Equipo Eliminado';
            
            $eId = '#';
            if ($doc->equipo) {
                if (!empty($doc->PLACA)) {
                    $eId = 'Placa: ' . $doc->PLACA;
                } elseif (!empty($doc->equipo->SERIAL_CHASIS)) {
                    $eId = 'Serial Chasis: ' . $doc->equipo->SERIAL_CHASIS;
                } else {
                    $eId = 'ID: ' . $doc->equipo->ID_EQUIPO;
                }
            }

            if ($doc->PROPIEDAD_FECHA_SUBIDA && $doc->PROPIEDAD_SUBIDO_POR) {
                $autor = $doc->usuarioPropiedad ? $doc->usuarioPropiedad->CORREO_ELECTRONICO : $doc->PROPIEDAD_SUBIDO_POR;
                $events->push((object)[
                    'doc_key' => 'propiedad',
                    'tipo' => 'Título de Propiedad',
                    'autor' => $autor,
                    'fecha_raw' => $doc->PROPIEDAD_FECHA_SUBIDA,
                    'fecha' => Carbon::parse($doc->PROPIEDAD_FECHA_SUBIDA),
                    'link' => $doc->LINK_DOC_PROPIEDAD,
                    'equipo_nombre' => $eName,
                    'equipo_id' => $eId,
                    'equipo_db_id' => $doc->equipo ? $doc->equipo->ID_EQUIPO : null
                ]);
            }
            if ($doc->POLIZA_FECHA_SUBIDA && $doc->POLIZA_SUBIDO_POR) {
                $autor = $doc->usuarioPoliza ? $doc->usuarioPoliza->CORREO_ELECTRONICO : $doc->POLIZA_SUBIDO_POR;
                $events->push((object)[
                    'doc_key' => 'poliza',
                    'tipo' => 'Póliza de Seguro',
                    'autor' => $autor,
                    'fecha_raw' => $doc->POLIZA_FECHA_SUBIDA,
                    'fecha' => Carbon::parse($doc->POLIZA_FECHA_SUBIDA),
                    'link' => $doc->LINK_POLIZA_SEGURO,
                    'equipo_nombre' => $eName,
                    'equipo_id' => $eId,
                    'equipo_db_id' => $doc->equipo ? $doc->equipo->ID_EQUIPO : null
                ]);
            }
            if ($doc->ROTC_FECHA_SUBIDA && $doc->ROTC_SUBIDO_POR) {
                $autor = $doc->usuarioRotc ? $doc->usuarioRotc->CORREO_ELECTRONICO : $doc->ROTC_SUBIDO_POR;
                $events->push((object)[
                    'doc_key' => 'rotc',
                    'tipo' => 'ROTC',
                    'autor' => $autor,
                    'fecha_raw' => $doc->ROTC_FECHA_SUBIDA,
                    'fecha' => Carbon::parse($doc->ROTC_FECHA_SUBIDA),
                    'link' => $doc->LINK_ROTC,
                    'equipo_nombre' => $eName,
                    'equipo_id' => $eId,
                    'equipo_db_id' => $doc->equipo ? $doc->equipo->ID_EQUIPO : null
                ]);
            }
            if ($doc->RACDA_FECHA_SUBIDA && $doc->RACDA_SUBIDO_POR) {
                $autor = $doc->usuarioRacda ? $doc->usuarioRacda->CORREO_ELECTRONICO : $doc->RACDA_SUBIDO_POR;
                $events->push((object)[
                    'doc_key' => 'racda',
                    'tipo' => 'RACDA',
                    'autor' => $autor,
                    'fecha_raw' => $doc->RACDA_FECHA_SUBIDA,
                    'fecha' => Carbon::parse($doc->RACDA_FECHA_SUBIDA),
                    'link' => $doc->LINK_RACDA,
                    'equipo_nombre' => $eName,
                    'equipo_id' => $eId,
                    'equipo_db_id' => $doc->equipo ? $doc->equipo->ID_EQUIPO : null
                ]);
            }
            if ($doc->ADICIONAL_FECHA_SUBIDA && $doc->ADICIONAL_SUBIDO_POR) {
                $autor = $doc->usuarioAdicional ? $doc->usuarioAdicional->CORREO_ELECTRONICO : $doc->ADICIONAL_SUBIDO_POR;
                $events->push((object)[
                    'doc_key' => 'adicional',
                    'tipo' => 'Certificado Asociado',
                    'autor' => $autor,
                    'fecha_raw' => $doc->ADICIONAL_FECHA_SUBIDA,
                    'fecha' => Carbon::parse($doc->ADICIONAL_FECHA_SUBIDA),
                    'link' => $doc->LINK_DOC_ADICIONAL,
                    'equipo_nombre' => $eName,
                    'equipo_id' => $eId,
                    'equipo_db_id' => $doc->equipo ? $doc->equipo->ID_EQUIPO : null
                ]);
            }
            if ($doc->ADICIONAL_2_FECHA_SUBIDA && $doc->ADICIONAL_2_SUBIDO_POR) {
                $autor = $doc->usuarioAdicional2 ? $doc->usuarioAdicional2->CORREO_ELECTRONICO : $doc->ADICIONAL_2_SUBIDO_POR;
                $events->push((object)[
                    'doc_key' => 'adicional_2',
                    'tipo' => 'Compraventa',
                    'autor' => $autor,
                    'fecha_raw' => $doc->ADICIONAL_2_FECHA_SUBIDA,
                    'fecha' => Carbon::parse($doc->ADICIONAL_2_FECHA_SUBIDA),
                    'link' => $doc->LINK_DOC_ADICIONAL_2,
                    'equipo_nombre' => $eName,
                    'equipo_id' => $eId,
                    'equipo_db_id' => $doc->equipo ? $doc->equipo->ID_EQUIPO : null
                ]);
            }
        }

        // Add equipment creation events
        $equiposCreados = \App\Models\Equipo::with(['tipo', 'creador'])
            ->whereNotNull('CREADO_POR')
            ->get();

        foreach ($equiposCreados as $equipo) {
            $eName = ($equipo->tipo->nombre ?? 'Equipo') . ' ' . $equipo->MARCA . ' ' . $equipo->MODELO;
            
            $eId = '#';
            if (!empty($equipo->documentacion->PLACA)) {
                $eId = 'Placa: ' . $equipo->documentacion->PLACA;
            } elseif (!empty($equipo->SERIAL_CHASIS)) {
                $eId = 'Serial Chasis: ' . $equipo->SERIAL_CHASIS;
            } else {
                $eId = 'ID: ' . $equipo->ID_EQUIPO;
            }

            $autor = $equipo->creador ? $equipo->creador->CORREO_ELECTRONICO : 'Usuario Desconocido';
            
            $events->push((object)[
                'doc_key' => 'creacion',
                'tipo' => 'Registro de Vehículo',
                'autor' => $autor,
                'fecha_raw' => $equipo->created_at,
                'fecha' => Carbon::parse($equipo->created_at),
                'link' => null, // No document link for just creation
                'equipo_nombre' => $eName,
                'equipo_id' => $eId,
                'equipo_db_id' => $equipo->ID_EQUIPO
            ]);
        }

        // Eventos de AUDITORIA de equipos (ediciones, cambios de metadata, ubicacion).
        // Se cargan desde la tabla `equipo_audit_log` con eager loading de equipo+usuario
        // para evitar N+1. Limite alto para no agotar memoria en instalaciones grandes.
        try {
            $auditLogs = \App\Models\EquipoAuditLog::with(['equipo.tipo', 'usuario'])
                ->orderByDesc('created_at')
                ->limit(5000)
                ->get();
            foreach ($auditLogs as $log) {
                $eq = $log->equipo;
                $eName = $eq ? (($eq->tipo->nombre ?? 'Equipo') . ' ' . $eq->MARCA . ' ' . $eq->MODELO) : 'Equipo Eliminado';
                $eId   = '#';
                if ($eq) {
                    if (!empty($eq->SERIAL_CHASIS)) {
                        $eId = 'Serial Chasis: ' . $eq->SERIAL_CHASIS;
                    } else {
                        $eId = 'ID: ' . $eq->ID_EQUIPO;
                    }
                }
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
                    'delete_propiedad'     => 'Borrado Propiedad',
                    'delete_poliza'        => 'Borrado Póliza',
                    'delete_rotc'          => 'Borrado ROTC',
                    'delete_racda'         => 'Borrado RACDA',
                    'delete_adicional'     => 'Borrado Certificado',
                    'delete_adicional_2'   => 'Borrado Compraventa',
                    'ubicacion'            => 'Cambio de Ubicación',
                    'bulk_ubicacion'       => 'Ubicación Masiva',
                ][$log->ACCION] ?? ucfirst(str_replace('_', ' ', $log->ACCION));

                $events->push((object)[
                    'doc_key'       => $log->ACCION,
                    'tipo'          => $tipoLabel,
                    'autor'         => $log->usuario ? $log->usuario->CORREO_ELECTRONICO : ('Usuario #' . $log->ID_USUARIO),
                    'fecha_raw'     => $log->created_at,
                    'fecha'         => $log->created_at,
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

        // 3. Sort descending by date
        $events = $events->sortByDesc('fecha_raw')->values();

        // 4. Apply filters
        if ($request->filled('search_correo') || $request->filled('search_equipo') || $request->filled('search_tipo')) {
            $search_correo = strtolower($request->search_correo);
            $search_equipo = strtolower($request->search_equipo);
            $search_tipo = strtolower($request->search_tipo);

            $events = $events->filter(function ($event) use ($search_correo, $search_equipo, $search_tipo) {
                if ($search_correo && strpos(strtolower($event->autor), $search_correo) === false) {
                    return false;
                }
                if ($search_tipo && $search_tipo !== 'all' && strpos(strtolower($event->tipo), $search_tipo) === false) {
                    return false;
                }
                if ($search_equipo) {
                    $equipoMatch = strpos(strtolower($event->equipo_nombre), $search_equipo) !== false ||
                                   strpos(strtolower($event->equipo_id), $search_equipo) !== false;
                    if (!$equipoMatch) return false;
                }
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

        // Fetch blocked IPs
        $blockedIps = BloqueoIp::orderBy('ULTIMO_INTENTO', 'desc')->get();

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

    public function unlockIp($id)
    {
        if (!auth()->user()->can('super.admin')) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para realizar esta acción.'
            ], 403);
        }

        try {
            $bloqueo = BloqueoIp::findOrFail($id);
            $ip = $bloqueo->DIRECCION_IP;
            $bloqueo->delete();

            return response()->json([
                'success' => true,
                'message' => "La IP {$ip} ha sido desbloqueada exitosamente."
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al desbloquear la IP.'
            ], 500);
        }
    }
}
