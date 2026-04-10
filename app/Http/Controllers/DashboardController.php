<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Equipo;
use App\Models\Movilizacion;
use App\Models\FrenteTrabajo;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $user      = auth()->user();
        $isGlobal  = $user && $user->NIVEL_ACCESO == 1;
        $frenteIds = $user ? $user->getFrentesIds() : [];
        $userId    = $user ? $user->ID_USUARIO : 'guest';
        
        // Ensure each user has their own cache key to avoid leaking data between users
        $cacheKey = "dashboard_user_data_{$userId}";

        // Cache the dashboard logic to improve speed
        $data = \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addMinutes(5), function () use ($isGlobal, $frenteIds) {
            // 1. Mobilizations Today
            $movilizacionesHoyQuery = Movilizacion::whereDate('created_at', Carbon::today());
            if (count($frenteIds) > 0) {
                $movilizacionesHoyQuery->where(function($q) use ($frenteIds) {
                    $q->whereIn('ID_FRENTE_ORIGEN', $frenteIds)
                      ->orWhereIn('ID_FRENTE_DESTINO', $frenteIds);
                });
            } elseif (!$isGlobal) {
                $movilizacionesHoyQuery->whereRaw('1 = 0');
            }
            $movilizacionesHoy = $movilizacionesHoyQuery->count();

            // 2. Pending Mobilizations (disabled since transit is instant)
            $pendientesQuery = Movilizacion::whereRaw('1 = 0');
            if (count($frenteIds) > 0) {
                $pendientesQuery->whereIn('ID_FRENTE_DESTINO', $frenteIds);
            } elseif (!$isGlobal) {
                $pendientesQuery->whereRaw('1 = 0');
            }
            $pendientes = $pendientesQuery->count();

            // 3. Alerts List — LOCAL users see only their frentes' equipment
            $expiredList = $this->generateAlertsList(!$isGlobal ? $frenteIds : null);
            $totalAlerts  = $expiredList->count();

            // 4. Recent Activity (list) — LOCAL users see only their frentes
            $recentActivityQuery = Movilizacion::with(['equipo.tipo', 'equipo.documentacion', 'frenteDestino'])
                ->where('ESTADO_MVO', 'RECIBIDO')
                ->orderBy('created_at', 'desc')
                ->limit(50);
            if (count($frenteIds) > 0) {
                $recentActivityQuery->whereIn('ID_FRENTE_DESTINO', $frenteIds);
            } elseif (!$isGlobal) {
                $recentActivityQuery->whereRaw('1 = 0');
            }
            $recentActivity = $recentActivityQuery->get();

            // 5. Frentes activos (necesarios para el modal de Recepción Directa)
            $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
                ->orderBy('NOMBRE_FRENTE')
                ->get();

            // 6. Equipos inoperativos (mantenimiento)
            $equiposInoperativos = Equipo::where('ESTADO_OPERATIVO', 'INOPERATIVO')->count();

            return compact('movilizacionesHoy', 'pendientes', 'totalAlerts', 'recentActivity', 'expiredList', 'frentes', 'equiposInoperativos');
        });

        return view('menu', $data);
    }

    public function resetCache()
    {
        try {
            // 1. Clear Application Cache
            \Illuminate\Support\Facades\Artisan::call('cache:clear');
            \Illuminate\Support\Facades\Artisan::call('config:clear');
            \Illuminate\Support\Facades\Artisan::call('view:clear');

            // 2. Specific Google Drive Circuit Breaker (Redundant but safe)
            \Illuminate\Support\Facades\Cache::forget('google_drive_connection_error');

            // 3. Clear Dashboard Caches
            // (alert caches are now user-scoped direct queries — no shared keys to clear)
            // Legacy keys kept for safety:
            \Illuminate\Support\Facades\Cache::forget('dashboard_total_alerts');
            \Illuminate\Support\Facades\Cache::forget('dashboard_expired_list_v3');
            \Illuminate\Support\Facades\Cache::forget('dashboard_movilizaciones_hoy');
            \Illuminate\Support\Facades\Cache::forget('dashboard_pendientes');
            \Illuminate\Support\Facades\Cache::forget('dashboard_recent_activity');

            return back()->with('success', 'Sistema reiniciado correctamente. Las conexiones han sido restablecidas.');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Reset Cache Error: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Error al reiniciar el sistema: ' . $e->getMessage()]);
        }
    }

    public function getAlertsHtml()
    {
        $user        = auth()->user();
        $isGlobal    = $user && $user->NIVEL_ACCESO == 1;
        $frenteIds   = $user ? $user->getFrentesIds() : [];

        $expiredList = $this->generateAlertsList((count($frenteIds) > 0) ? $frenteIds : (!$isGlobal ? [] : null));
        $totalAlerts = $expiredList->count();

        return response()->json([
            'html'        => view('partials.dashboard_alerts', compact('expiredList'))->render(),
            'totalAlerts' => $totalAlerts
        ]);
    }

    /**
     * AJAX: Devuelve HTML actualizado de la lista de movilizaciones pendientes + contadores
     * Mismo criterio de filtrado LOCAL/GLOBAL que index().
     */
    public function getPendingMovsHtml()
    {
        $user     = auth()->user();
        $isGlobal = $user && $user->NIVEL_ACCESO == 1;
        $frenteIds = $user ? $user->getFrentesIds() : [];

        $query = Movilizacion::with(['equipo.tipo', 'equipo.documentacion', 'frenteDestino'])
            ->where('ESTADO_MVO', 'RECIBIDO');

        if (count($frenteIds) > 0) {
            $query->whereIn('ID_FRENTE_DESTINO', $frenteIds);
        } elseif (!$isGlobal) {
            $query->whereRaw('1 = 0');
        }

        $pendientes = 0;
        $recentActivity = $query->orderBy('created_at', 'desc')->limit(50)->get();

        $hoyQuery = Movilizacion::whereDate('created_at', \Carbon\Carbon::today());
        if (count($frenteIds) > 0) {
            $hoyQuery->where(function ($q) use ($frenteIds) {
                $q->whereIn('ID_FRENTE_ORIGEN', $frenteIds)
                  ->orWhereIn('ID_FRENTE_DESTINO', $frenteIds);
            });
        } elseif (!$isGlobal) {
            $hoyQuery->whereRaw('1 = 0');
        }
        $movilizacionesHoy = $hoyQuery->count();

        return response()->json([
            'html'              => view('partials.pending_movs_list', compact('recentActivity'))->render(),
            'pendientes'        => $pendientes,
            'movilizacionesHoy' => $movilizacionesHoy,
        ]);
    }

    /**
     * Generate alerts list for expired and expiring documents.
     * Shared by index(), getAlertsHtml().
     *
     * @param array|null $frenteIds  When set, only returns alerts for equipment in those frentes (LOCAL users).
     */
    public function generateAlertsList(?array $frenteIds = null)
    {
        $now      = \Carbon\Carbon::now();
        $in30Days = $now->copy()->addDays(30);

        $query = Equipo::whereHas('documentacion', function ($q) use ($in30Days) {
            $q->where('FECHA_VENC_POLIZA', '<', $in30Days)
              ->orWhere('FECHA_ROTC', '<', $in30Days)
              ->orWhere('FECHA_RACDA', '<', $in30Days);
        })
        ->where('ESTADO_OPERATIVO', '!=', 'DESINCORPORADO')
        ->with([
            'documentacion.frenteGestionPoliza',
            'documentacion.frenteGestionRotc',
            'documentacion.frenteGestionRacda',
            'tipo',
            'frenteActual'
        ]);

        // Only see alerts for assigned frentes if frentes are explicitly provided
        // (If Global Admin with no explicit frentes, $frenteIds is null and it skips this)
        if (is_array($frenteIds)) {
            if (count($frenteIds) > 0) {
                $query->whereIn('ID_FRENTE_ACTUAL', $frenteIds);
            } else {
                // Si es un arreglo vacío, significa que el usuario (Local) no tiene frentes.
                $query->whereRaw('1 = 0');
            }
        }

        $equipos = $query->get();

        $alerts = collect();

        foreach ($equipos as $equipo) {
            $doc = $equipo->documentacion;
            
            // Poliza
            if ($doc->FECHA_VENC_POLIZA) {
                $fechaPoliza = \Carbon\Carbon::parse($doc->FECHA_VENC_POLIZA);
                
                // Determine Status
                $status = $fechaPoliza->lt($now) ? 'expired' : ($fechaPoliza->lt($in30Days) ? 'warning' : 'valid');
                
                if ($status !== 'valid') {
                    $alerts->push((object)[
                        'equipo' => $equipo,
                        'type_key' => 'poliza',
                        'label' => $status === 'expired' ? 'Póliza Vencida' : 'Póliza Por Vencer',
                        'fecha' => $doc->FECHA_VENC_POLIZA,
                        'current_link' => $doc->LINK_POLIZA_SEGURO,
                        'status' => $status,
                        'gestionado_por' => $doc->frenteGestionPoliza ? $doc->frenteGestionPoliza->NOMBRE_FRENTE : null,
                        'fecha_gestion' => $doc->poliza_gestion_fecha
                    ]);
                }
            }

            // ROTC
            if ($doc->FECHA_ROTC) {
                $fechaRotc = \Carbon\Carbon::parse($doc->FECHA_ROTC);
                $status = $fechaRotc->lt($now) ? 'expired' : ($fechaRotc->lt($in30Days) ? 'warning' : 'valid');
                
                if ($status !== 'valid') {
                    $alerts->push((object)[
                        'equipo' => $equipo,
                        'type_key' => 'rotc',
                        'label' => $status === 'expired' ? 'ROTC Vencido' : 'ROTC Por Vencer',
                        'fecha' => $doc->FECHA_ROTC,
                        'current_link' => $doc->LINK_ROTC,
                        'status' => $status,
                        'gestionado_por' => $doc->frenteGestionRotc ? $doc->frenteGestionRotc->NOMBRE_FRENTE : null,
                        'fecha_gestion' => $doc->rotc_gestion_fecha
                    ]);
                }
            }

            // RACDA
            if ($doc->FECHA_RACDA) {
                $fechaRacda = \Carbon\Carbon::parse($doc->FECHA_RACDA);
                $status = $fechaRacda->lt($now) ? 'expired' : ($fechaRacda->lt($in30Days) ? 'warning' : 'valid');
                
                if ($status !== 'valid') {
                    $alerts->push((object)[
                        'equipo' => $equipo,
                        'type_key' => 'racda',
                        'label' => $status === 'expired' ? 'RACDA Vencido' : 'RACDA Por Vencer',
                        'fecha' => $doc->FECHA_RACDA,
                        'current_link' => $doc->LINK_RACDA,
                        'status' => $status,
                        'gestionado_por' => $doc->frenteGestionRacda ? $doc->frenteGestionRacda->NOMBRE_FRENTE : null,
                        'fecha_gestion' => $doc->racda_gestion_fecha
                    ]);
                }
            }
        }

        // Separate expired from warnings
        $expired = $alerts->where('status', 'expired')->sortBy('fecha')->values();
        $warnings = $alerts->where('status', 'warning')->sortBy('fecha')->values();
        
        // Combine: warnings first (upcoming), then expired
        return $warnings->concat($expired)->values();
    }

    /**
     * Start management of a document
     */
    public function iniciarGestion(Request $request)
    {
        $request->validate([
            'equipo_id' => 'required|exists:equipos,ID_EQUIPO',
            'doc_type' => 'required|in:poliza,rotc,racda'
        ]);

        $user = auth()->user();
        
        // Solo usuarios con permiso de edición de equipos pueden iniciar gestión
        if (!$user->can('equipos.edit')) {
            return response()->json(['success' => false, 'message' => 'No tiene permisos para realizar esta acción.'], 403);
        }

        if (!$user->getFrentesIds()) {
            return response()->json(['success' => false, 'message' => 'Debe pertenecer a un frente para iniciar gestión'], 403);
        }

        $doc = \App\Models\Documentacion::where('ID_EQUIPO', $request->equipo_id)->first();
        if (!$doc) return response()->json(['success' => false, 'message' => 'Documentación no encontrada'], 404);

        $frenteField = $request->doc_type . '_gestion_frente_id';
        $fechaField = $request->doc_type . '_gestion_fecha';

        // Usar el primer frente asignado como frente de gestión
        $primerFrente = $user->getFrentesIds()[0] ?? null;
        $doc->$frenteField = $primerFrente;
        $doc->$fechaField = now();
        $doc->save();

        // Clear Cache
        \Illuminate\Support\Facades\Cache::forget('dashboard_total_alerts');
        \Illuminate\Support\Facades\Cache::forget('dashboard_expired_list_v3');

        return response()->json(['success' => true]);
    }
    
    /**
     * Generate PDF Report of Expired & Expiring Documents
     */
    public function exportDocumentsPDF()
    {
        try {
            // Get current user info
            $user = auth()->user();
            $nombreUsuario = $user->NOMBRE_USUARIO ?? 'Sistema';
            $nombreFrente = $user->frenteAsignado ? $user->frenteAsignado->NOMBRE_FRENTE : 'Sin Frente Asignado';
            $fechaEmision = \Carbon\Carbon::now()->locale('es')->isoFormat('DD [de] MMMM [de] YYYY - HH:mm');

            // Get alerts list
            $alertsList = $this->generateAlertsList();
            
            // Separate by status and Sort by Equipment Type for grouping
            $vencidos = $alertsList->filter(function($alert) {
                return $alert->status === 'expired';
            })->sortBy('equipo.tipo.nombre')->values();
            
            $proximos = $alertsList->filter(function($alert) {
                return $alert->status === 'warning';
            })->sortBy('equipo.tipo.nombre')->values();

            // Calculate totals
            $totalVencidos = $vencidos->count();
            $totalProximos = $proximos->count();
            
            // Get unique equipment count
            $totalEquipos = $alertsList->pluck('equipo.ID_EQUIPO')->unique()->count();

            // MANUAL LOADING OF TCPDF (Emergency Mode)
            // If the package is physically present but not autoloaded yet
            if (!class_exists('TCPDF')) {
                $tcpdfPath = base_path('vendor/tecnickcom/tcpdf/tcpdf.php');
                if (file_exists($tcpdfPath)) {
                    require_once($tcpdfPath);
                }
            }

            // Render View to HTML
            $html = view('reports.documentos_vencidos_pdf', compact(
                'vencidos',
                'proximos',
                'nombreUsuario',
                'nombreFrente',
                'fechaEmision',
                'totalVencidos',
                'totalProximos',
                'totalEquipos'
            ))->render();

            if (class_exists('TCPDF')) {
                $pdf = new ReportePDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
                
                // Set document information
                $pdf->SetCreator('Sistema de Gestión');
                $pdf->SetAuthor($nombreUsuario);
                $pdf->SetTitle('Reporte de Documentos Vencidos');

                // Configuración de Márgenes (2cm)
                // Configuración de Márgenes
                $pdf->setPrintHeader(true); 
                $pdf->setPrintFooter(true); 
                $pdf->SetMargins(25, 40, 25); // Margen superior 4cm para bajar el contenido y no chocar con el header grande
                $pdf->SetHeaderMargin(10); // Header a 1cm del borde
                $pdf->SetAutoPageBreak(TRUE, 25);
                
                // Add a page
                $pdf->AddPage();
                
                // Write HTML
                $pdf->writeHTML($html, true, false, true, false, '');
                
                // Download
                $filename = 'Reporte_Documentos_' . \Carbon\Carbon::now()->format('Y-m-d_His') . '.pdf';
                
                return response($pdf->Output($filename, 'S')) // S = Return as string
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            } else {
                 throw new \Exception('La librería TCPDF no se encuentra instalada correctamente.');
            }

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('PDF Export Error: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Error al generar PDF: ' . $e->getMessage()]);
        }
    }
}

// Clase para personalizar el pie de página del PDF
if (class_exists('TCPDF') && !class_exists('ReportePDF')) {
    class ReportePDF extends \TCPDF {
        public function Header() {
            // Imagen a 1cm (10mm) del borde superior y 2.5cm (25mm) del izquierdo
            $image_file = public_path('img/imagen_uno.jpg');
            // Image(file, x, y, w, h) -> h=25mm (Más grande)
            if (file_exists($image_file)) {
                $this->Image($image_file, 25, 10, 0, 25, 'JPG', '', 'T', false, 300, '', false, false, 0, false, false, false);
            }

            // Texto a la derecha, alineado con la base de la foto (Y=27 aprox para base en 35)
            $this->SetFont('helvetica', '', 8.5);
            $html = '<div style="text-align: right;"><strong>FECHA DE EMISIÓN:</strong> ' . \Carbon\Carbon::now()->format('d/m/Y') . '<br>EMITIDO POR SISTEMA DE GESTIÓN DE FLOTA</div>';
            
            // Renderizar HTML Cell
            $this->writeHTMLCell(0, 0, 25, 27, $html, 0, 1, 0, true, 'R', true);
        }

        public function Footer() {
            $this->SetY(-15);
            $this->SetFont('helvetica', '', 8.5); // Sin cursiva y tamaño 8.5
            $this->Cell(0, 10, 'Página '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, false, 'R', 0, '', 0, false, 'T', 'M');
        }
    }
}
