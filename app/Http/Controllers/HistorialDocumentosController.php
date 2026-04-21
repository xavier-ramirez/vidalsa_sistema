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
        $docs = Documentacion::with(['equipo.tipo', 'equipo.frenteActual', 'usuarioPropiedad', 'usuarioPoliza', 'usuarioRotc', 'usuarioRacda', 'usuarioAdicional'])
            ->whereNotNull('PROPIEDAD_FECHA_SUBIDA')
            ->orWhereNotNull('POLIZA_FECHA_SUBIDA')
            ->orWhereNotNull('ROTC_FECHA_SUBIDA')
            ->orWhereNotNull('RACDA_FECHA_SUBIDA')
            ->orWhereNotNull('ADICIONAL_FECHA_SUBIDA')
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
                    'tipo' => 'Doc. Adicional',
                    'autor' => $autor,
                    'fecha_raw' => $doc->ADICIONAL_FECHA_SUBIDA,
                    'fecha' => Carbon::parse($doc->ADICIONAL_FECHA_SUBIDA),
                    'link' => $doc->LINK_DOC_ADICIONAL,
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

        if ($request->wantsJson()) {
            return response()->json([
                'html' => view('admin.historial_documentos.partials.table_rows', ['events' => $paginatedEvents])->render(),
                'pagination' => $paginatedEvents->links('vendor.pagination.custom-sliding')->toHtml(),
                'total' => $total
            ]);
        }

        return view('admin.historial_documentos.index', [
            'events' => $paginatedEvents, 
            'total' => $total,
            'blockedIps' => $blockedIps
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
