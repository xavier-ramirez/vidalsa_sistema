<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\ReporteDiario;
use App\Models\RegistroFalla;
use App\Models\MaterialRecomendadoFalla;
use App\Models\HistorialEstadoEquipo;
use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use App\Models\CaracteristicaModelo;
use App\Models\SolicitudMaterialesItem;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class MantenimientoController extends Controller
{
    /**
     * Vista principal del módulo de mantenimiento.
     * Pasa datos necesarios para las 3 pestañas: Mi Frente, Consolidado, Timeline.
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        // Frentes y equipos scoped por nivel de acceso
        $frentesQuery = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->orderBy('NOMBRE_FRENTE');

        if ($user->NIVEL_ACCESO == 2) {
            $frentesIds = $user->getFrentesIds();
            $frentesQuery->whereIn('ID_FRENTE', $frentesIds);
            $equipos = Equipo::whereIn('ID_FRENTE_ACTUAL', $frentesIds)
                ->with(['tipo', 'especificaciones'])
                ->orderBy('MARCA')
                ->get();
        } else {
            $equipos = Equipo::with(['tipo', 'especificaciones'])->orderBy('MARCA')->get();
        }

        $frentes = $frentesQuery->get();

        return view('admin.mantenimiento.index', compact('frentes', 'equipos'));
    }

    /**
     * AJAX: Lista de reportes diarios filtrados.
     */
    public function getReportesDiarios(Request $request): JsonResponse
    {
        $user = auth()->user();
        $query = ReporteDiario::with(['frente', 'cerradoPor'])
            ->withCount(['fallas', 'fallas as fallas_abiertas_count' => function ($q) {
                $q->where('ESTADO_FALLA', 'ABIERTA');
            }]);

        // Scoping por nivel de acceso
        if ($user->NIVEL_ACCESO == 2) {
            $query->whereIn('ID_FRENTE', $user->getFrentesIds());
        }

        if ($request->filled('frente')) {
            $query->where('ID_FRENTE', $request->frente);
        }

        if ($request->filled('fecha')) {
            $query->whereDate('FECHA_REPORTE', $request->fecha);
        }

        if ($request->filled('estado')) {
            $query->where('ESTADO_REPORTE', $request->estado);
        }

        $reportes = $query->orderByDesc('FECHA_REPORTE')->paginate(15);

        $html = view('admin.mantenimiento.partials.tabla_reportes', compact('reportes'))->render();

        return response()->json([
            'html' => $html,
            'pagination' => $reportes->links()->toHtml(),
            'total' => $reportes->total(),
        ]);
    }

    /**
     * AJAX: Detalle de un reporte con sus fallas.
     */
    public function showReporteDiario(int $id): JsonResponse
    {
        $reporte = ReporteDiario::with([
            'frente',
            'cerradoPor',
            'fallas.equipo.tipo',
            'fallas.equipo.especificaciones',
            'fallas.usuarioRegistra',
            'fallas.materiales',
        ])->findOrFail($id);

        $html = view('admin.mantenimiento.partials.tabla_fallas', [
            'fallas' => $reporte->fallas->sortByDesc('HORA_REGISTRO'),
            'reporte' => $reporte,
        ])->render();

        return response()->json([
            'html' => $html,
            'reporte' => [
                'ID_REPORTE' => $reporte->ID_REPORTE,
                'FECHA_REPORTE' => $reporte->FECHA_REPORTE->format('d/m/Y'),
                'ESTADO_REPORTE' => $reporte->ESTADO_REPORTE,
                'FRENTE' => $reporte->frente->NOMBRE_FRENTE ?? '',
                'total_fallas' => $reporte->fallas->count(),
                'abiertas' => $reporte->fallas->where('ESTADO_FALLA', 'ABIERTA')->count(),
                'resueltas' => $reporte->fallas->where('ESTADO_FALLA', 'RESUELTA')->count(),
            ],
        ]);
    }

    /**
     * POST: Obtener o crear el reporte de hoy para el frente del usuario.
     */
    public function getOrCreateReporte(Request $request): JsonResponse
    {
        $request->validate([
            'ID_FRENTE' => 'required|exists:frentes_trabajo,ID_FRENTE',
        ]);

        // Verificar acceso al frente
        $user = auth()->user();
        if ($user->NIVEL_ACCESO == 2) {
            $frentesPermitidos = $user->getFrentesIds();
            if (!in_array($request->ID_FRENTE, $frentesPermitidos)) {
                return response()->json(['error' => 'No tienes acceso a este frente de trabajo.'], 403);
            }
        }

        $reporte = ReporteDiario::firstOrCreate(
            [
                'ID_FRENTE' => $request->ID_FRENTE,
                'FECHA_REPORTE' => Carbon::today()->toDateString(),
            ],
            [
                'ESTADO_REPORTE' => 'ABIERTO',
            ]
        );

        $reporte->load('frente', 'fallas.equipo.tipo', 'fallas.usuarioRegistra');

        return response()->json([
            'success' => true,
            'reporte' => $reporte,
        ]);
    }

    /**
     * POST AJAX: Registrar nueva falla.
     */
    public function storeFalla(Request $request): JsonResponse
    {
        $request->validate([
            'ID_REPORTE' => 'required|exists:reportes_diarios,ID_REPORTE',
            'ID_EQUIPO' => 'required|exists:equipos,ID_EQUIPO',
            'TIPO_FALLA' => 'required|in:MECANICA,ELECTRICA,HIDRAULICA,NEUMATICA,ESTRUCTURAL,OTRA',
            'DESCRIPCION_FALLA' => 'required|string|max:2000',
            'PRIORIDAD' => 'required|in:BAJA,MEDIA,ALTA,CRITICA',
            'SISTEMA_AFECTADO' => 'nullable|string|max:100',
        ]);

        $reporte = ReporteDiario::findOrFail($request->ID_REPORTE);

        if ($reporte->ESTADO_REPORTE !== 'ABIERTO') {
            return response()->json(['error' => 'El reporte ya está cerrado.'], 422);
        }

        $falla = RegistroFalla::create([
            'ID_REPORTE' => $request->ID_REPORTE,
            'ID_EQUIPO' => $request->ID_EQUIPO,
            'ID_USUARIO_REGISTRA' => auth()->id(),
            'HORA_REGISTRO' => now(),
            'TIPO_FALLA' => $request->TIPO_FALLA,
            'SISTEMA_AFECTADO' => $request->SISTEMA_AFECTADO,
            'DESCRIPCION_FALLA' => $request->DESCRIPCION_FALLA,
            'PRIORIDAD' => $request->PRIORIDAD,
            'ESTADO_FALLA' => 'ABIERTA',
        ]);

        $falla->load('equipo.tipo', 'usuarioRegistra');

        return response()->json([
            'success' => true,
            'falla' => $falla,
            'message' => 'Falla registrada correctamente.',
        ]);
    }

    /**
     * PUT AJAX: Actualizar estado de falla (resolver, cambiar prioridad, etc.)
     */
    public function updateFalla(Request $request, int $id): JsonResponse
    {
        $falla = RegistroFalla::findOrFail($id);

        $request->validate([
            'ESTADO_FALLA' => 'sometimes|in:ABIERTA,EN_PROCESO,RESUELTA',
            'PRIORIDAD' => 'sometimes|in:BAJA,MEDIA,ALTA,CRITICA',
            'DESCRIPCION_RESOLUCION' => 'nullable|string|max:2000',
        ]);

        $data = $request->only(['ESTADO_FALLA', 'PRIORIDAD', 'DESCRIPCION_RESOLUCION']);

        if (isset($data['ESTADO_FALLA']) && $data['ESTADO_FALLA'] === 'RESUELTA') {
            $data['FECHA_RESOLUCION'] = now();
        }

        $falla->update($data);
        $falla->load('equipo.tipo', 'usuarioRegistra', 'materiales');

        return response()->json([
            'success' => true,
            'falla' => $falla,
            'message' => 'Falla actualizada.',
        ]);
    }

    /**
     * POST: Cerrar reporte diario.
     */
    public function cerrarReporte(Request $request, int $id): JsonResponse
    {
        $reporte = ReporteDiario::findOrFail($id);

        if ($reporte->ESTADO_REPORTE === 'CERRADO') {
            return response()->json(['error' => 'El reporte ya está cerrado.'], 422);
        }

        $reporte->update([
            'ESTADO_REPORTE' => 'CERRADO',
            'CERRADO_POR' => auth()->id(),
            'FECHA_CIERRE' => now(),
            'OBSERVACIONES' => $request->input('OBSERVACIONES'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Reporte cerrado correctamente.',
        ]);
    }

    /**
     * AJAX: Consolidado nacional de fallas para una fecha.
     */
    public function consolidadoDiario(Request $request): JsonResponse
    {
        $fecha = $request->input('fecha', Carbon::today()->toDateString());

        $user = auth()->user();
        $reportes = ReporteDiario::with([
                'frente',
                'fallas.equipo.tipo',
                'fallas.equipo.especificaciones',
                'fallas.equipo.frenteActual',
            ])
            ->whereDate('FECHA_REPORTE', $fecha)
            ->when($user->NIVEL_ACCESO == 2, fn ($q) => $q->whereIn('ID_FRENTE', $user->getFrentesIds()))
            ->get();

        // Estadísticas nacionales
        $totalFallas = $reportes->sum(fn ($r) => $r->fallas->count());
        $fallasAbiertas = $reportes->sum(fn ($r) => $r->fallas->where('ESTADO_FALLA', 'ABIERTA')->count());
        $fallasResueltas = $reportes->sum(fn ($r) => $r->fallas->where('ESTADO_FALLA', 'RESUELTA')->count());

        // Desglose por tipo de falla
        $porTipo = $reportes->flatMap->fallas->groupBy('TIPO_FALLA')->map->count();

        // Desglose por prioridad
        $porPrioridad = $reportes->flatMap->fallas->groupBy('PRIORIDAD')->map->count();

        // Desglose por frente
        $porFrente = $reportes->map(function ($r) {
            return [
                'frente' => $r->frente->NOMBRE_FRENTE ?? 'Sin Frente',
                'total' => $r->fallas->count(),
                'abiertas' => $r->fallas->where('ESTADO_FALLA', 'ABIERTA')->count(),
                'resueltas' => $r->fallas->where('ESTADO_FALLA', 'RESUELTA')->count(),
            ];
        });

        $html = view('admin.mantenimiento.partials.consolidado_panel', [
            'reportes' => $reportes,
            'fecha' => $fecha,
            'totalFallas' => $totalFallas,
            'fallasAbiertas' => $fallasAbiertas,
            'fallasResueltas' => $fallasResueltas,
            'porTipo' => $porTipo,
            'porPrioridad' => $porPrioridad,
            'porFrente' => $porFrente,
        ])->render();

        return response()->json([
            'html' => $html,
            'stats' => [
                'total' => $totalFallas,
                'abiertas' => $fallasAbiertas,
                'resueltas' => $fallasResueltas,
            ],
        ]);
    }

    /**
     * AJAX: Search equipos for timeline (general text search).
     */
    public function searchEquipos(Request $request): JsonResponse
    {
        $q = $request->input('q', '');
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $user = auth()->user();
        $query = Equipo::with('tipo', 'frenteActual')
            ->where(function ($qb) use ($q) {
                $qb->where('MARCA', 'LIKE', "%{$q}%")
                    ->orWhere('MODELO', 'LIKE', "%{$q}%")
                    ->orWhere('SERIAL_CHASIS', 'LIKE', "%{$q}%")
                    ->orWhere('CODIGO_PATIO', 'LIKE', "%{$q}%")
                    ->orWhereHas('tipo', function ($t) use ($q) {
                        $t->where('nombre', 'LIKE', "%{$q}%");
                    });
            });

        // Scope by access level
        if ($user->NIVEL_ACCESO == 2) {
            $frenteIds = $user->frentes->pluck('ID_FRENTE');
            $query->whereIn('ID_FRENTE_ACTUAL', $frenteIds);
        }

        $equipos = $query->limit(8)->get()->map(function ($eq) {
            return [
                'ID_EQUIPO' => $eq->ID_EQUIPO,
                'MARCA' => $eq->MARCA,
                'MODELO' => $eq->MODELO,
                'SERIAL_CHASIS' => $eq->SERIAL_CHASIS,
                'CODIGO_PATIO' => $eq->CODIGO_PATIO,
                'ESTADO_OPERATIVO' => $eq->ESTADO_OPERATIVO,
                'tipo' => $eq->tipo->nombre ?? 'S/T',
            ];
        });

        return response()->json($equipos);
    }

    /**
     * AJAX: Timeline de estados de un equipo específico.
     */
    public function timeline(int $equipoId): JsonResponse
    {
        $equipo = Equipo::with('tipo', 'frenteActual')->findOrFail($equipoId);

        $historial = HistorialEstadoEquipo::where('ID_EQUIPO', $equipoId)
            ->with('usuario', 'falla')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        // Fallas del equipo (últimos 30 días)
        $fallas = RegistroFalla::where('ID_EQUIPO', $equipoId)
            ->with('reporte.frente', 'equipo.especificaciones')
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->orderByDesc('HORA_REGISTRO')
            ->get();

        // Calcular días consecutivos inoperativo
        $diasInoperativo = 0;
        if ($equipo->ESTADO_OPERATIVO === 'INOPERATIVO') {
            $ultimoCambio = $historial
                ->where('ESTADO_NUEVO', 'INOPERATIVO')
                ->first();
            if ($ultimoCambio) {
                $diasInoperativo = Carbon::parse($ultimoCambio->created_at)->diffInDays(now());
            }
        }

        $html = view('admin.mantenimiento.partials.timeline_panel', [
            'equipo' => $equipo,
            'historial' => $historial,
            'fallas' => $fallas,
            'diasInoperativo' => $diasInoperativo,
        ])->render();

        return response()->json([
            'html' => $html,
            'equipo' => [
                'ID_EQUIPO' => $equipo->ID_EQUIPO,
                'MARCA' => $equipo->MARCA,
                'MODELO' => $equipo->MODELO,
                'ESTADO_OPERATIVO' => $equipo->ESTADO_OPERATIVO,
                'dias_inoperativo' => $diasInoperativo,
            ],
        ]);
    }

    /**
     * AJAX: Auto-recomendar materiales basado en CaracteristicaModelo del equipo.
     */
    public function recomendarMateriales(int $equipoId): JsonResponse
    {
        $equipo = Equipo::with('especificaciones')->findOrFail($equipoId);

        $recomendaciones = [];

        if ($equipo->especificaciones) {
            $spec = $equipo->especificaciones;
            $campos = [
                'ACEITE_MOTOR' => 'Aceite de Motor',
                'ACEITE_CAJA' => 'Aceite de Caja',
                'TIPO_BATERIA' => 'Batería',
                'REFRIGERANTE' => 'Refrigerante',
                'LIGA_FRENO' => 'Líquido de Frenos',
                'COMBUSTIBLE' => 'Combustible',
            ];

            foreach ($campos as $campo => $label) {
                $valor = $spec->$campo;
                if (!empty($valor)) {
                    $recomendaciones[] = [
                        'DESCRIPCION_MATERIAL' => $label . ': ' . $valor,
                        'ESPECIFICACION' => $valor,
                        'CAMPO_ORIGEN' => $campo,
                        'ID_ESPEC_ORIGEN' => $spec->ID_ESPEC,
                        'FUENTE' => 'AUTO_CATALOGO',
                    ];
                }
            }
        }

        // Materiales usados anteriormente en este modelo de equipo
        $materialesHistoricos = SolicitudMaterialesItem::whereHas('solicitud', function ($q) use ($equipo) {
                $q->where('ID_EQUIPO', $equipo->ID_EQUIPO);
            })
            ->select('DESCRIPCION_MATERIAL')
            ->distinct()
            ->limit(10)
            ->pluck('DESCRIPCION_MATERIAL');

        return response()->json([
            'recomendaciones' => $recomendaciones,
            'historicos' => $materialesHistoricos,
            'equipo' => [
                'MARCA' => $equipo->MARCA,
                'MODELO' => $equipo->MODELO,
                'ANIO' => $equipo->ANIO,
                'tiene_especificaciones' => !is_null($equipo->especificaciones),
            ],
        ]);
    }

    /**
     * POST AJAX: Agregar material a una falla.
     */
    public function storeMaterial(Request $request, int $id): JsonResponse
    {
        $falla = RegistroFalla::findOrFail($id);

        $request->validate([
            'DESCRIPCION_MATERIAL' => 'required|string|max:255',
            'ESPECIFICACION' => 'nullable|string|max:150',
            'CANTIDAD' => 'required|numeric|min:0.01',
            'UNIDAD' => 'required|string|max:50',
            'FUENTE' => 'sometimes|in:MANUAL,AUTO_CATALOGO',
            'ID_ESPEC_ORIGEN' => 'nullable|integer',
            'CAMPO_ORIGEN' => 'nullable|string|max:50',
        ]);

        $material = MaterialRecomendadoFalla::create([
            'ID_FALLA' => $falla->ID_FALLA,
            'DESCRIPCION_MATERIAL' => $request->DESCRIPCION_MATERIAL,
            'ESPECIFICACION' => $request->ESPECIFICACION,
            'CANTIDAD' => $request->CANTIDAD,
            'UNIDAD' => $request->UNIDAD,
            'FUENTE' => $request->input('FUENTE', 'MANUAL'),
            'ID_ESPEC_ORIGEN' => $request->ID_ESPEC_ORIGEN,
            'CAMPO_ORIGEN' => $request->CAMPO_ORIGEN,
        ]);

        return response()->json([
            'success' => true,
            'material' => $material,
            'message' => 'Material agregado.',
        ]);
    }

    /**
     * PDF: Reporte individual de una falla.
     */
    public function exportPdfIndividual(int $id)
    {
        $falla = RegistroFalla::with([
            'equipo.tipo',
            'equipo.frenteActual',
            'equipo.especificaciones',
            'reporte.frente',
            'usuarioRegistra',
            'materiales',
        ])->findOrFail($id);

        $html = view('admin.mantenimiento.reporte_fallas_pdf', [
            'fallas' => collect([$falla]),
            'titulo' => 'REPORTE DE FALLA',
            'fecha' => $falla->HORA_REGISTRO->format('d/m/Y'),
            'frente' => $falla->reporte->frente->NOMBRE_FRENTE ?? '',
        ])->render();

        return $this->generatePdf($html, 'Reporte_Falla_' . $falla->ID_FALLA . '.pdf');
    }

    /**
     * POST PDF: Reporte por lote (todas las fallas de un reporte diario).
     */
    public function exportPdfLote(Request $request, int $id)
    {
        $reporte = ReporteDiario::with([
            'frente',
            'fallas.equipo.tipo',
            'fallas.equipo.frenteActual',
            'fallas.equipo.especificaciones',
            'fallas.usuarioRegistra',
            'fallas.materiales',
        ])->findOrFail($id);

        $html = view('admin.mantenimiento.reporte_fallas_pdf', [
            'fallas' => $reporte->fallas->sortByDesc('PRIORIDAD'),
            'titulo' => 'REPORTE DIARIO DE FALLAS',
            'fecha' => $reporte->FECHA_REPORTE->format('d/m/Y'),
            'frente' => $reporte->frente->NOMBRE_FRENTE ?? '',
        ])->render();

        $filename = 'Reporte_Fallas_' . $reporte->FECHA_REPORTE->format('Y-m-d') . '.pdf';
        return $this->generatePdf($html, $filename);
    }

    /**
     * GET PDF: Consolidado nacional.
     */
    public function exportPdfConsolidado(Request $request)
    {
        $fecha = $request->input('fecha', Carbon::today()->toDateString());

        $reportes = ReporteDiario::with([
                'frente',
                'fallas.equipo.tipo',
                'fallas.equipo.frenteActual',
                'fallas.usuarioRegistra',
            ])
            ->whereDate('FECHA_REPORTE', $fecha)
            ->get();

        $todasFallas = $reportes->flatMap->fallas;

        $html = view('admin.mantenimiento.consolidado_pdf', [
            'reportes' => $reportes,
            'fallas' => $todasFallas,
            'fecha' => Carbon::parse($fecha)->format('d/m/Y'),
        ])->render();

        $filename = 'Consolidado_Nacional_' . $fecha . '.pdf';
        return $this->generatePdf($html, $filename);
    }

    /**
     * AJAX: Stats widget para el dashboard principal.
     */
    public function statsWidget(): JsonResponse
    {
        $user = auth()->user();
        $hoy = Carbon::today();

        $baseQuery = RegistroFalla::whereHas('reporte', function ($q) use ($hoy, $user) {
            $q->whereDate('FECHA_REPORTE', $hoy);
            if ($user->NIVEL_ACCESO == 2) {
                $q->whereIn('ID_FRENTE', $user->getFrentesIds());
            }
        });

        $fallasAbiertasHoy = (clone $baseQuery)
            ->whereIn('ESTADO_FALLA', ['ABIERTA', 'EN_PROCESO'])
            ->count();

        $fallasResueltasHoy = (clone $baseQuery)
            ->where('ESTADO_FALLA', 'RESUELTA')
            ->count();

        $reportesHoy = ReporteDiario::whereDate('FECHA_REPORTE', $hoy)
            ->when($user->NIVEL_ACCESO == 2, fn ($q) => $q->whereIn('ID_FRENTE', $user->getFrentesIds()))
            ->count();

        $equipoQuery = Equipo::where('ESTADO_OPERATIVO', 'INOPERATIVO');
        if ($user->NIVEL_ACCESO == 2) {
            $equipoQuery->whereIn('ID_FRENTE_ACTUAL', $user->getFrentesIds());
        }
        $totalInoperativos = $equipoQuery->count();

        return response()->json([
            'fallas_abiertas_hoy' => $fallasAbiertasHoy,
            'fallas_resueltas_hoy' => $fallasResueltasHoy,
            'reportes_hoy' => $reportesHoy,
            'equipos_inoperativos' => $totalInoperativos,
        ]);
    }

    /**
     * AJAX: Detalle completo de una falla individual.
     */
    public function showFalla(int $id): JsonResponse
    {
        $falla = RegistroFalla::with([
            'equipo.tipo',
            'equipo.frenteActual',
            'reporte.frente',
            'usuarioRegistra',
            'materiales',
        ])->findOrFail($id);

        return response()->json([
            'falla' => [
                'ID_FALLA' => $falla->ID_FALLA,
                'TIPO_FALLA' => $falla->TIPO_FALLA,
                'SISTEMA_AFECTADO' => $falla->SISTEMA_AFECTADO,
                'DESCRIPCION_FALLA' => $falla->DESCRIPCION_FALLA,
                'PRIORIDAD' => $falla->PRIORIDAD,
                'ESTADO_FALLA' => $falla->ESTADO_FALLA,
                'HORA_REGISTRO' => $falla->HORA_REGISTRO?->format('d/m/Y H:i'),
                'FECHA_RESOLUCION' => $falla->FECHA_RESOLUCION?->format('d/m/Y H:i'),
                'DESCRIPCION_RESOLUCION' => $falla->DESCRIPCION_RESOLUCION,
                'equipo' => [
                    'ID_EQUIPO' => $falla->equipo->ID_EQUIPO ?? null,
                    'tipo' => $falla->equipo->tipo->nombre ?? 'S/T',
                    'MARCA' => $falla->equipo->MARCA ?? '',
                    'MODELO' => $falla->equipo->MODELO ?? '',
                    'SERIAL_CHASIS' => $falla->equipo->SERIAL_CHASIS ?? '',
                    'CODIGO_PATIO' => $falla->equipo->CODIGO_PATIO ?? '',
                    'ESTADO_OPERATIVO' => $falla->equipo->ESTADO_OPERATIVO ?? '',
                    'frente' => $falla->equipo->frenteActual->NOMBRE_FRENTE ?? 'Sin Frente',
                ],
                'usuario' => $falla->usuarioRegistra->NOMBRE_COMPLETO ?? '',
                'frente_reporte' => $falla->reporte->frente->NOMBRE_FRENTE ?? '',
                'materiales' => $falla->materiales->map(fn ($m) => [
                    'ID_MATERIAL_REC' => $m->ID_MATERIAL_REC,
                    'DESCRIPCION_MATERIAL' => $m->DESCRIPCION_MATERIAL,
                    'ESPECIFICACION' => $m->ESPECIFICACION,
                    'CANTIDAD' => $m->CANTIDAD,
                    'UNIDAD' => $m->UNIDAD,
                    'FUENTE' => $m->FUENTE,
                ]),
            ],
        ]);
    }

    /**
     * Helper: Generar PDF con TCPDF.
     */
    private function generatePdf(string $html, string $filename)
    {
        if (!class_exists('TCPDF')) {
            $tcpdfPath = base_path('vendor/tecnickcom/tcpdf/tcpdf.php');
            if (file_exists($tcpdfPath)) {
                require_once($tcpdfPath);
            }
        }

        $pdf = new ReporteFallasPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
        $pdf->SetCreator('Sistema de Gestión - Vidalsa');
        $pdf->SetAuthor(auth()->user()->NOMBRE_COMPLETO ?? 'Sistema');
        $pdf->SetTitle($filename);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output($filename, 'D');
    }
}

/**
 * Clase PDF personalizada para Reportes de Fallas.
 * Sigue el patrón de ActaTrasladoPDF (MovilizacionController:722)
 * y ReportePDF (DashboardController:399).
 */
class ReporteFallasPDF extends \TCPDF
{
    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10,
            'CONSTRUCTORA VIDALSA 27, C.A. | Reporte generado: ' . date('d/m/Y H:i') . ' | Pág. ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(),
            0, false, 'C'
        );
    }
}
