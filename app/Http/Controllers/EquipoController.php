<?php

namespace App\Http\Controllers;

use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use App\Models\CatalogoSeguro;
use App\Models\CaracteristicaModelo;
use App\Models\Documentacion;
use App\Models\Responsable;
use Illuminate\Http\Request;
use App\Models\TipoEquipo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class EquipoController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth')->except(['mobileIndex', 'mobileShow']);
        $this->middleware('can:equipos.create')->only(['store']);
        $this->middleware('can:equipos.edit')->only(['edit', 'update', 'changeStatus']);
        // uploadDoc/deleteDoc/updateMetadata: permission handled inside methods (user.edit OR equipos.edit OR super.admin)
    }

    public function index(Request $request)
    {
        $search = $request->input('search_query');
        $equipos = Equipo::query();

        $user = auth()->user();
        $isLocalUser = $user && $user->NIVEL_ACCESO == 2;
        $frentesPermitidos = $user ? $user->getFrentesIds() : [];

        // LOCAL users are scoped to their frentes, EXCEPT when doing a global text search (by serial/placa)
        if (empty($search)) {
            if ($isLocalUser && count($frentesPermitidos) > 0) {
                $equipos->whereIn('ID_FRENTE_ACTUAL', $frentesPermitidos);
            } elseif ($isLocalUser) {
                $equipos->whereRaw('1 = 0'); // Empty result if no frentes
            }
        }
        // GLOBAL users: no default filter applied — show all equipos on load

        if ($request->filled('id_frente') && trim($request->id_frente) !== '' && $request->id_frente !== 'all') {
            $equipos->where('ID_FRENTE_ACTUAL', $request->id_frente);
        }

        if ($request->filled('id_tipo') && trim($request->id_tipo) !== '' && $request->id_tipo !== 'all') {
            $equipos->where('id_tipo_equipo', $request->id_tipo);
        }

        if ($request->filled('modelo') && trim($request->modelo) !== '') {
            $equipos->where('MODELO', $request->modelo);
        }

        if ($request->filled('marca') && trim($request->marca) !== '') {
            $equipos->where('MARCA', $request->marca);
        }

        if ($request->filled('anio') && trim($request->anio) !== '') {
            $equipos->where('ANIO', $request->anio);
        }

        if ($request->filled('categoria') && trim($request->categoria) !== '') {
            $equipos->where('CATEGORIA_FLOTA', $request->categoria);
        }

        if ($request->filled('estado') && trim($request->estado) !== '') {
            $equipos->where('ESTADO_OPERATIVO', $request->estado);
        }

        if ($request->filled('gps') && trim($request->gps) !== '') {
            $val = strtoupper(trim($request->gps));
            if ($val === 'SI') {
                $equipos->whereNotNull('LINK_GPS')->where('LINK_GPS', '!=', '');
            } elseif ($val === 'NO') {
                $equipos->where(function($q) {
                    $q->whereNull('LINK_GPS')->orWhere('LINK_GPS', '=', '');
                });
            }
        }

        if ($search) {
            $searchUpper = strtoupper(trim($search));

            // Smart Search by prefix
            if (strpos($searchUpper, '#') !== false) {
                // Mode: Tag Number Search
                $tagSearch = str_replace('#', '', $searchUpper);
                $equipos->where('NUMERO_ETIQUETA', 'like', "%{$tagSearch}%");

            } elseif (strpos($searchUpper, '-') !== false) {
                // Mode: Yard Code Search
                $equipos->where('CODIGO_PATIO', 'like', "%{$searchUpper}%");

            } else {
                // Standard search — O/0 ambiguity applied ONLY to PLACA
                // (plates are the only field where O and 0 are visually confused)
                $placaVariants = collect([
                    $searchUpper,
                    str_replace('O', '0', $searchUpper),
                    str_replace('0', 'O', $searchUpper),
                    str_replace(['O', '0'], ['0', 'O'], $searchUpper),
                ])->unique()->values()->all();

                // To optimize PLACA search without full scanning documentacion table, we join it here
                $equipos->leftJoin('documentacion AS doc_search', 'equipos.ID_EQUIPO', '=', 'doc_search.ID_EQUIPO');

                $equipos->where(function ($q) use ($searchUpper, $placaVariants) {
                    // Exact search for non-plate fields
                    $q->where('equipos.SERIAL_CHASIS', 'like', "%{$searchUpper}%")
                      ->orWhere('equipos.SERIAL_DE_MOTOR', 'like', "%{$searchUpper}%")
                      ->orWhere('equipos.CODIGO_PATIO', 'like', "%{$searchUpper}%")
                      ->orWhere('equipos.NUMERO_ETIQUETA', 'like', "%{$searchUpper}%")
                      // O/0-aware search only for PLACA via Left Join
                      ->orWhere(function ($pq) use ($placaVariants) {
                          foreach ($placaVariants as $variant) {
                              $pq->orWhere('doc_search.PLACA', 'like', "%{$variant}%");
                          }
                      });
                });
            }
        }




        // --- Documentation Filters ---
        $hasDocFilter = ($request->filled('filter_propiedad') && $request->filter_propiedad === 'true') ||
                        ($request->filled('filter_poliza') && $request->filter_poliza === 'true') ||
                        ($request->filled('filter_rotc') && $request->filter_rotc === 'true') ||
                        ($request->filled('filter_racda') && $request->filter_racda === 'true');

        if ($hasDocFilter) {
            $equipos->leftJoin('documentacion AS doc_filter', 'equipos.ID_EQUIPO', '=', 'doc_filter.ID_EQUIPO')
                     ->where(function ($q) use ($request) {
                         if ($request->filled('filter_propiedad') && $request->filter_propiedad === 'true') {
                             $q->whereNotNull('doc_filter.LINK_DOC_PROPIEDAD');
                         }
                         if ($request->filled('filter_poliza') && $request->filter_poliza === 'true') {
                             $q->whereNotNull('doc_filter.LINK_POLIZA_SEGURO');
                         }
                         if ($request->filled('filter_rotc') && $request->filter_rotc === 'true') {
                             $q->whereNotNull('doc_filter.LINK_ROTC');
                         }
                         if ($request->filled('filter_racda') && $request->filter_racda === 'true') {
                             $q->whereNotNull('doc_filter.LINK_RACDA');
                         }
                     });
        }

        $equipos->select('equipos.*')
            ->leftJoin('tipo_equipos', 'equipos.id_tipo_equipo', '=', 'tipo_equipos.id')
            ->with([
                'documentacion.seguro',
                'especificaciones:ID_ESPEC,COMBUSTIBLE,CONSUMO_PROMEDIO,FOTO_REFERENCIAL',
                'tipo',
                'frenteActual',
                'ancladoA.tipo',
                'ancladoA.documentacion',
            ])
            ->withCount('subActivos')
            ->orderBy('tipo_equipos.nombre', 'asc')
            ->orderBy('equipos.CODIGO_PATIO', 'asc');

        // Check if any filter is applied (with non-empty values)
        $hasFilter = $request->filled('id_frente') || $request->filled('id_tipo') || $request->filled('search_query') || $request->filled('modelo') || $request->filled('marca') || $request->filled('anio') || $request->filled('categoria') || $request->filled('estado') || $request->filled('gps') || $request->filled('filter_propiedad') || $request->filled('filter_poliza') || $request->filled('filter_rotc') || $request->filled('filter_racda');

        if ($isLocalUser) {
            // Local users always show the table with their scoped frentes by default
            $hasFilter = true;
        }

        if ($hasFilter) {
            // Get ALL records matching filters (no pagination limit)
            $allResults = $equipos->get();

            // Wrap in Paginator to keep view compatibility, but page size is total count
            $equipos = new \Illuminate\Pagination\LengthAwarePaginator(
                $allResults,
                $allResults->count(),
                $allResults->count() > 0 ? $allResults->count() : 1, // perPage = total items
                1 // current page always 1
            );
            $equipos->withPath($request->url())->appends($request->all());

        } else {
            // Return empty paginator to open the interface without showing any records initially
            $equipos = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10);
        }

        $stats = ['total' => 0, 'activos' => 0, 'inactivos' => 0, 'mantenimiento' => 0];
        $tiposStats = collect([]);
        $frentesStats = []; // Ensure array or collection

        // Only calculate stats if filters are active and we have data
        if ($hasFilter) {
            // OPTIMIZATION: Calculate stats from the already loaded collection instead of hitting DB again
            // This reduces DB queries from ~5 to 1.

            $stats['total'] = $allResults->where('ESTADO_OPERATIVO', '!=', 'DESINCORPORADO')->count();
            $stats['activos'] = $allResults->where('ESTADO_OPERATIVO', 'OPERATIVO')->count();
            $stats['inactivos'] = $allResults->where('ESTADO_OPERATIVO', 'INOPERATIVO')->count();
            $stats['mantenimiento'] = $allResults->where('ESTADO_OPERATIVO', 'EN MANTENIMIENTO')->count();
            $stats['desincorporados'] = $allResults->where('ESTADO_OPERATIVO', 'DESINCORPORADO')->count();

            // Calculate Tipos Stats from Collection
            $tiposStats = $allResults->groupBy('id_tipo_equipo')->map(function ($group) {
                // Get the first item to access relation (assuming eager loaded)
                $first = $group->first();
                return (object) [
                    'id_tipo_equipo' => $first->id_tipo_equipo,
                    'nombre' => $first->tipo->nombre ?? 'Sin Tipo', // Access relation
                    'total' => $group->count()
                ];
            })->sortBy('nombre')->values();

            // Calculate Frentes Stats from Collection (if needed for drilldown)
            if ($request->filled('id_tipo')) {
                $frentesStats = $allResults->whereNotNull('ID_FRENTE_ACTUAL')->groupBy('ID_FRENTE_ACTUAL')->map(function ($group) {
                    $first = $group->first();
                    return (object) [
                        'ID_FRENTE_ACTUAL' => $first->ID_FRENTE_ACTUAL,
                        'NOMBRE_FRENTE' => $first->frenteActual->NOMBRE_FRENTE ?? 'Sin Frente',
                        'total' => $group->count()
                    ];
                })->sortBy('NOMBRE_FRENTE')->values();
            }
        }

        if ($request->wantsJson()) {
            // When no filter is active, we want the UI to show '--' to indicate "waiting for filter"
            // specifically for the counter cards.
            $responseStats = $stats;
            if (!$hasFilter) {
                $responseStats = [
                    'total' => '--',
                    'activos' => '--',
                    'inactivos' => '--',
                    'mantenimiento' => '--'
                ];
            }

            return response()->json([
                'html' => view('admin.equipos.partials.table_rows', compact('equipos'))->render(),
                'pagination' => '',
                'stats' => $responseStats,
                'distribution' => view('admin.equipos.partials.distribution_stats', compact('frentesStats', 'tiposStats', 'hasFilter'))->render(), // Pass hasFilter explicitly
            ]);
        }

        $frentesQuery = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE', 'asc');
        if ($isLocalUser && count($frentesPermitidos) > 0) {
            $frentesQuery->whereIn('ID_FRENTE', $frentesPermitidos);
        } elseif ($isLocalUser) {
            $frentesQuery->whereRaw('1 = 0');
        }
        $frentes = $frentesQuery->get();
        
        $allTipos = TipoEquipo::orderBy('nombre', 'asc')->get();

        // Advanced Filter Lists (Optimized with cache: Only needed for initial page load, not AJAX)
        // Cache these lists for 1 hour to avoid repeated DB queries
        $availableModelos = \Illuminate\Support\Facades\Cache::remember('equipos_modelos_dropdown', 3600, function () {
            return Equipo::distinct()
                ->whereNotNull('MODELO')
                ->where('MODELO', '!=', '')
                ->orderBy('MODELO', 'asc')
                ->pluck('MODELO');
        });

        $availableMarcas = \Illuminate\Support\Facades\Cache::remember('equipos_marcas_dropdown', 3600, function () {
            return Equipo::distinct()
                ->whereNotNull('MARCA')
                ->where('MARCA', '!=', '')
                ->orderBy('MARCA', 'asc')
                ->pluck('MARCA');
        });

        $availableAnios = \Illuminate\Support\Facades\Cache::remember('equipos_anios_dropdown', 3600, function () {
            return Equipo::distinct()->whereNotNull('ANIO')->orderBy('ANIO', 'desc')->pluck('ANIO');
        });

        return view('admin.equipos.index', compact('equipos', 'stats', 'frentes', 'allTipos', 'tiposStats', 'frentesStats', 'availableModelos', 'availableMarcas', 'availableAnios'));
    }

    public function export(Request $request)
    {
        $user = auth()->user();
        $isLocalUser = $user && $user->NIVEL_ACCESO == 2;
        $frentesPermitidos = $user ? $user->getFrentesIds() : [];

        if ($isLocalUser) {
            // Allow local user to bypass the "no filter" check because they have an implicit filter 
            $request->merge(['_local_user_forced_filter' => true]);
        }

        // CRITICAL: Prevent exporting entire database without filters.
        // 'id_frente=all' es un filtro explícito válido (el usuario seleccionó "Todos los Frentes").
        $hasFilter = $request->filled('id_frente')   // incluye 'all' como filtro válido
            || $request->filled('_local_user_forced_filter')
            || $request->filled('id_tipo')
            || $request->filled('search_query')
            || $request->filled('modelo')
            || $request->filled('marca')
            || $request->filled('anio')
            || $request->filled('categoria')
            || $request->filled('estado')
            || $request->filled('gps')
            || $request->filled('filter_propiedad') && $request->filter_propiedad === 'true'
            || $request->filled('filter_poliza') && $request->filter_poliza === 'true'
            || $request->filled('filter_rotc') && $request->filter_rotc === 'true'
            || $request->filled('filter_racda') && $request->filter_racda === 'true';

        if (!$hasFilter) {
            return redirect()->back()->with('error', 'Debe aplicar al menos un filtro antes de exportar los datos.');
        }

        $fileName = 'Listado_Maquinarias_Equipos_' . date('Y-m-d_H-i') . '.xlsx';

        $equipos = Equipo::query();

        // Apply Local User Scope EXCEPT when doing a global text search
        $search = $request->input('search_query');
        if (empty($search)) {
            if ($isLocalUser && count($frentesPermitidos) > 0) {
                $equipos->whereIn('ID_FRENTE_ACTUAL', $frentesPermitidos);
            } elseif ($isLocalUser) {
                $equipos->whereRaw('1 = 0');
            }
        }

        // Apply same filters
        if ($request->filled('id_frente') && $request->id_frente != 'all') {
            $equipos->where('ID_FRENTE_ACTUAL', $request->id_frente);
        }
        if ($request->filled('id_tipo')) {
            $equipos->where('id_tipo_equipo', $request->id_tipo);
        }
        if ($request->filled('modelo')) {
            $equipos->where('MODELO', $request->modelo);
        }
        if ($request->filled('marca')) {
            $equipos->where('MARCA', $request->marca);
        }
        if ($request->filled('anio')) {
            $equipos->where('ANIO', $request->anio);
        }
        if ($request->filled('categoria')) {
            $equipos->where('CATEGORIA_FLOTA', $request->categoria);
        }
        if ($request->filled('estado')) {
            $equipos->where('ESTADO_OPERATIVO', $request->estado);
        }
        if ($request->filled('gps') && trim($request->gps) !== '') {
            $val = strtoupper(trim($request->gps));
            if ($val === 'SI') {
                $equipos->whereNotNull('LINK_GPS')->where('LINK_GPS', '!=', '');
            } elseif ($val === 'NO') {
                $equipos->where(function($q) {
                    $q->whereNull('LINK_GPS')->orWhere('LINK_GPS', '=', '');
                });
            }
        }

        // --- Documentation Filters ---
        $hasDocFilter = ($request->filled('filter_propiedad') && $request->filter_propiedad === 'true') ||
                        ($request->filled('filter_poliza') && $request->filter_poliza === 'true') ||
                        ($request->filled('filter_rotc') && $request->filter_rotc === 'true') ||
                        ($request->filled('filter_racda') && $request->filter_racda === 'true');

        if ($hasDocFilter) {
            $equipos->leftJoin('documentacion AS doc_filter', 'equipos.ID_EQUIPO', '=', 'doc_filter.ID_EQUIPO')
                     ->where(function ($q) use ($request) {
                         if ($request->filled('filter_propiedad') && $request->filter_propiedad === 'true') {
                             $q->whereNotNull('doc_filter.LINK_DOC_PROPIEDAD');
                         }
                         if ($request->filled('filter_poliza') && $request->filter_poliza === 'true') {
                             $q->whereNotNull('doc_filter.LINK_POLIZA_SEGURO');
                         }
                         if ($request->filled('filter_rotc') && $request->filter_rotc === 'true') {
                             $q->whereNotNull('doc_filter.LINK_ROTC');
                         }
                         if ($request->filled('filter_racda') && $request->filter_racda === 'true') {
                             $q->whereNotNull('doc_filter.LINK_RACDA');
                         }
                     });
        }

        $search = $request->input('search_query');
        if ($search) {
            if (strpos($search, '#') !== false) {
                $tagSearch = str_replace('#', '', $search);
                $equipos->where('NUMERO_ETIQUETA', 'like', "%{$tagSearch}%");
            } else {
                // Optimize PLACA search with leftJoin instead of whereHas
                $equipos->leftJoin('documentacion AS doc_search', 'equipos.ID_EQUIPO', '=', 'doc_search.ID_EQUIPO');
                // Ensure we only select from equipos explicitly, to prevent joined tables overwriting keys
                $equipos->select('equipos.*');
                
                $equipos->where(function ($q) use ($search) {
                    $q->where('equipos.SERIAL_CHASIS', 'like', "%{$search}%")
                        ->orWhere('doc_search.PLACA', 'like', "%{$search}%")
                        ->orWhere('equipos.SERIAL_DE_MOTOR', 'like', "%{$search}%")
                        ->orWhere('equipos.CODIGO_PATIO', 'like', "%{$search}%")
                        ->orWhere('equipos.NUMERO_ETIQUETA', 'like', "%{$search}%");
                });
            }
        }

        $equipos->with(['frenteActual', 'tipo', 'documentacion', 'especificaciones', 'equiposAnclados.tipo', 'equiposAnclados.documentacion']);
        $equiposList = $equipos->get();

        $equiposMap = [];
        foreach($equiposList as $equipo) {
            $equiposMap[$equipo->ID_EQUIPO] = $equipo;
        }

        // Determinar nombre del frente para el encabezado
        $nombreFrente = 'TODOS LOS FRENTES';
        if ($request->filled('id_frente') && $request->id_frente !== 'all') {
            $frente = FrenteTrabajo::find($request->id_frente);
            if ($frente) $nombreFrente = mb_strtoupper($frente->NOMBRE_FRENTE);
        }

        $currentDate = date('d/m/Y');

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Equipos');

        // Document properties
        $spreadsheet->getProperties()
            ->setCreator('Sistema de Gestión de Equipos Operacionales')
            ->setLastModifiedBy('Sistema de Gestión de Equipos Operacionales')
            ->setTitle('Listado de Maquinarias y Equipos')
            ->setSubject('Exportación - Sistema de Gestión de Equipos Operacionales')
            ->setDescription('Generado automáticamente por el Sistema de Gestión de Equipos Operacionales - C.VIDALSA 27, C.A.')
            ->setCompany('Constructora Vidalsa 27, C.A.');

        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        // Logo
        $logoPath = public_path('img/imagen_uno.jpg');
        if (file_exists($logoPath)) {
            try {
                $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                $drawing->setName('Logo CVIDALSA');
                $drawing->setDescription('Logo');
                $drawing->setPath($logoPath);
                $drawing->setCoordinates('A1');
                $drawing->setOffsetX(45);
                $drawing->setOffsetY(12);
                $drawing->setHeight(135); // LOGO GIGANTE Y CENTRADO
                $drawing->setWorksheet($sheet);
            } catch (\Exception $e) {
                // Silently ignore if image failed
            }
        }

        // Fila 1 a 3 - Título Empresa
        $sheet->mergeCells('A1:B3');
        $sheet->getStyle('A1:B3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF'); // Fondo Blanco Puro

        $sheet->mergeCells('C1:E3'); // EXTENDIDO HASTA LA 'E' PARA MÁS ANCHURA (C + D + E)
        if ($nombreFrente !== 'TODOS LOS FRENTES') {
            $subTitle = 'PROYECTO: "' . mb_strtoupper($nombreFrente) . '"';
        } else {
            $subTitle = 'COPIA DE BASE DE DATOS DEL SISTEMA DE GESTION DE EQUIPOS OPERACIONALES';
        }
        $titleText = "LISTADO DE MAQUINARIAS Y EQUIPOS\n" . $subTitle;
        $sheet->setCellValue('C1', $titleText);
        $sheet->getStyle('C1')->getAlignment()->setWrapText(true);
        $sheet->getStyle('C1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('C1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('C1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $sheet->getStyle('C1:E3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF'); // Blanco

        $sheet->setCellValue('F1', 'EDICION: 1');
        $sheet->getStyle('F1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('F1')->getFont()->setBold(true)->setSize(11)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $sheet->getStyle('F1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF'); // Blanco

        $sheet->setCellValue('F2', 'REVISION: 0');
        $sheet->getStyle('F2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F2')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('F2')->getFont()->setBold(true)->setSize(11)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $sheet->getStyle('F2')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF'); // Blanco

        $sheet->setCellValue('F3', 'FECHA: ' . $currentDate);
        $sheet->getStyle('F3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F3')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('F3')->getFont()->setBold(true)->setSize(11)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $sheet->getStyle('F3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF'); // Blanco

        $sheet->getRowDimension(1)->setRowHeight(40); // 40*3 = 120 permite un logo gigante
        $sheet->getRowDimension(2)->setRowHeight(40);
        $sheet->getRowDimension(3)->setRowHeight(40);

        // Fila 4 - Texto Exportado por (movido a la parte superior)
        $sheet->mergeCells('A4:F4');
        $sheet->setCellValue('A4', 'Exportado por: Sistema de Gestión de Equipos Operacionales');
        $sheet->getStyle('A4:F4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF'); // Blanco para la descripcion tambien
        $sheet->getStyle('A4:F4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('A4:F4')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A4:F4')->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF333333');
        $sheet->getRowDimension(4)->setRowHeight(20); 

        // Bordes a toda la cuadricula de encabezado (A1 hasta F4)
        $headerBorders = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'],
                ],
            ],
        ];
        $sheet->getStyle('A1:F4')->applyFromArray($headerBorders);

        // Fila 5 - Encabezados de tabla
        $headers = ['N°', 'TIPO', 'MARCA', 'MODELO', 'SERIAL DE CHASIS', 'PLACA'];
        $colMap = ['A','B','C','D','E','F'];
        foreach($headers as $index => $hdr) {
            $sheet->setCellValue($colMap[$index] . '5', $hdr);
        }
        $sheet->getStyle('A5:F5')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A5:F5')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        // AZUL MARINO ELEGANTE PARA EL ENCABEZADO
        $sheet->getStyle('A5:F5')->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A5:F5')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF1B365D');
        $sheet->getRowDimension(5)->setRowHeight(35);

        // Anchos de columna optimizados
        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(35);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(25);
        $sheet->getColumnDimension('E')->setWidth(30);
        $sheet->getColumnDimension('F')->setWidth(20);

        // Filas de datos
        $rowNum = 6;
        $counter = 1;
        $processedIds = [];

        foreach($equiposList as $equipo) {
            if (isset($processedIds[$equipo->ID_EQUIPO])) {
                continue;
            }

            // If it's a child and its parent is also in the list, skip and let the parent print it
            if ($equipo->ID_ANCLAJE && isset($equiposMap[$equipo->ID_ANCLAJE])) {
                continue;
            }

            $tipoArr = [$equipo->tipo ? mb_strtoupper($equipo->tipo->nombre) : '—'];
            $marcaArr = [mb_strtoupper($equipo->MARCA ?? '—')];
            $modeloArr = [mb_strtoupper($equipo->MODELO ?? '—')];
            
            $chasis = trim($equipo->SERIAL_CHASIS ?? '');
            $chasisArr = [$chasis !== '' ? mb_strtoupper($chasis) : '—'];
            
            $placa = $equipo->documentacion ? trim($equipo->documentacion->PLACA ?? '') : '';
            $placaArr = [$placa !== '' ? mb_strtoupper($placa) : '—'];

            // Append children if any
            foreach($equipo->equiposAnclados as $anclado) {
                 $tipoArr[] = $anclado->tipo ? mb_strtoupper($anclado->tipo->nombre) : '—';
                 $marcaArr[] = mb_strtoupper($anclado->MARCA ?? '—');
                 $modeloArr[] = mb_strtoupper($anclado->MODELO ?? '—');
                 
                 $achasis = trim($anclado->SERIAL_CHASIS ?? '');
                 $chasisArr[] = $achasis !== '' ? mb_strtoupper($achasis) : '—';
                 
                 $aplaca = $anclado->documentacion ? trim($anclado->documentacion->PLACA ?? '') : '';
                 $placaArr[] = $aplaca !== '' ? mb_strtoupper($aplaca) : '—';

                 $processedIds[$anclado->ID_EQUIPO] = true;
            }

            $numeroItem = str_pad($counter, 2, '0', STR_PAD_LEFT);

            $sheet->setCellValue('A'.$rowNum, $numeroItem);
            $sheet->setCellValue('B'.$rowNum, implode("\n", $tipoArr));
            $sheet->setCellValue('C'.$rowNum, implode("\n", $marcaArr));
            $sheet->setCellValue('D'.$rowNum, implode("\n", $modeloArr));
            $sheet->setCellValue('E'.$rowNum, implode("\n", $chasisArr));
            $sheet->setCellValue('F'.$rowNum, implode("\n", $placaArr));
            
            // Alternancia de colores en las filas (Zebra Striping)
            if ($counter % 2 === 0) {
                $sheet->getStyle('A'.$rowNum.':F'.$rowNum)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9'); 
            } else {
                $sheet->getStyle('A'.$rowNum.':F'.$rowNum)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
            }

            $sheet->getStyle('B'.$rowNum)->getAlignment()->setWrapText(true);
            $sheet->getStyle('C'.$rowNum)->getAlignment()->setWrapText(true);
            $sheet->getStyle('D'.$rowNum)->getAlignment()->setWrapText(true);
            $sheet->getStyle('E'.$rowNum)->getAlignment()->setWrapText(true);
            $sheet->getStyle('F'.$rowNum)->getAlignment()->setWrapText(true);

            $sheet->getStyle('A'.$rowNum.':F'.$rowNum)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $sheet->getStyle('A'.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('C'.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('E'.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('F'.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            
            $numItems = count($tipoArr);
            $rowHeight = $numItems === 1 ? 30 : ($numItems * 25);
            $sheet->getRowDimension($rowNum)->setRowHeight($rowHeight);
            
            $rowNum++;
            $counter++;
        }

        // Fila Total
        $sheet->setCellValue('A'.$rowNum, 'TOTAL');
        $sheet->mergeCells('B'.$rowNum.':C'.$rowNum);
        $sheet->setCellValue('B'.$rowNum, ($counter - 1) . " EQUIPOS LISTADOS");
        $sheet->mergeCells('D'.$rowNum.':F'.$rowNum);
        
        $sheet->getStyle('A'.$rowNum.':F'.$rowNum)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A'.$rowNum.':B'.$rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A'.$rowNum.':F'.$rowNum)->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FF1E293B');
        $sheet->getStyle('A'.$rowNum.':F'.$rowNum)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');
        $sheet->getRowDimension($rowNum)->setRowHeight(28);

        // Bordes a toda la tabla de datos
        $sheet->getStyle('A5:F'.$rowNum)->applyFromArray($headerBorders);

        // Limpiar TODOS los buffers de salida activos de forma segura.
        // ob_end_clean() simple puede fallar en php-fpm de producción (nginx) si hay
        // más de un nivel de buffer activo, corrompiendo los headers HTTP del archivo.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Escribir en archivo temporal y devolver como descarga
        $writer   = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $tempFile = tempnam(sys_get_temp_dir(), 'export_cvidalsa_');
        $writer->save($tempFile);

        return response()->download($tempFile, $fileName, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ])->deleteFileAfterSend(true);
    }


    public function searchSpecs(Request $request)
    {
        $query = $request->input('query');
        if (!$query)
            return response()->json([]);

        $results = CaracteristicaModelo::select('ID_ESPEC', 'MODELO')
            ->where('MODELO', 'LIKE', "%{$query}%")
            ->orderBy('MODELO', 'asc')
            ->limit(20)
            ->get();

        return response()->json($results);
    }

    public function searchField(Request $request)
    {
        $field = $request->input('field');
        $query = $request->input('query');

        if (!$field || !$query) {
            return response()->json([]);
        }

        // Map frontend field names to database columns
        $fieldMap = [
            'MARCA' => 'MARCA',
            'MODELO' => 'MODELO'
        ];

        if (!isset($fieldMap[$field])) {
            return response()->json([]);
        }

        $column = $fieldMap[$field];

        $results = Equipo::select($column)
            ->distinct()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->where($column, 'LIKE', "%{$query}%")
            ->orderBy($column, 'asc')
            ->limit(15)
            ->pluck($column);

        return response()->json($results);
    }

    public function create()
    {
        // Cache dropdown lists for 1 hour to avoid repeated DB queries
        $frentes = \Illuminate\Support\Facades\Cache::remember('frentes_activos_form', 3600, function () {
            return FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
                ->orderBy('NOMBRE_FRENTE', 'asc')
                ->pluck('NOMBRE_FRENTE', 'ID_FRENTE');
        });

        $seguros = \Illuminate\Support\Facades\Cache::remember('seguros_list_form', 3600, function () {
            return CatalogoSeguro::orderBy('NOMBRE_ASEGURADORA', 'asc')
                ->pluck('NOMBRE_ASEGURADORA');
        });

        $tipos_equipo = \Illuminate\Support\Facades\Cache::remember('tipos_equipo_list_form', 3600, function () {
            return TipoEquipo::orderBy('nombre', 'asc')
                ->pluck('nombre');
        });

        // Performance Optimization: Don't pre-load models list
        // Models will be loaded dynamically via AJAX autocomplete (same as years)
        // This eliminates DOM bloat when there are thousands of models
        $modelosList = [];

        $aniosList = \Illuminate\Support\Facades\Cache::remember('anios_list_form_v3', 60, function () {
            return Equipo::distinct()->whereNotNull('ANIO')->orderBy('ANIO', 'desc')->pluck('ANIO');
        });

        $marcas = \Illuminate\Support\Facades\Cache::remember('marcas_list_form_v3', 60, function () {
            return Equipo::distinct()->whereNotNull('MARCA')->orderBy('MARCA', 'asc')->limit(1000)->pluck('MARCA');
        });

        $modelos = \Illuminate\Support\Facades\Cache::remember('modelos_list_form_v3', 60, function () {
            return Equipo::distinct()->whereNotNull('MODELO')->orderBy('MODELO', 'asc')->limit(1000)->pluck('MODELO');
        });

        $categorias = ['FLOTA LIVIANA', 'FLOTA PESADA'];

        $equipo = new Equipo(); // Empty instance for form partial
        return view('admin.equipos.create', compact('frentes', 'seguros', 'tipos_equipo', 'marcas', 'modelos', 'categorias', 'equipo', 'modelosList', 'aniosList'));
    }

    public function store(Request $request)
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        // Normalize inputs to uppercase before validation to avoid case-sensitivity issues with unique constraints
        $request->merge([
            'CODIGO_PATIO' => (trim($request->CODIGO_PATIO ?? '') === '') ? null : strtoupper($request->CODIGO_PATIO),
            'SERIAL_CHASIS' => strtoupper($request->SERIAL_CHASIS),
            'SERIAL_DE_MOTOR' => (trim($request->SERIAL_DE_MOTOR ?? '') === '') ? null : strtoupper(trim($request->SERIAL_DE_MOTOR)),
            'DETALLE_UBICACION_ACTUAL' => (trim($request->DETALLE_UBICACION_ACTUAL ?? '') === '') ? null : strtoupper(trim($request->DETALLE_UBICACION_ACTUAL)),
        ]);

        if ($request->has('documentacion.PLACA')) {
            $doc = $request->documentacion;
            $placa = trim($doc['PLACA'] ?? '');
            $doc['PLACA'] = ($placa === '') ? null : strtoupper($placa);
            $request->merge(['documentacion' => $doc]);
        }




        try {
            $validated = $request->validate([
                'CODIGO_PATIO' => 'nullable|unique:equipos,CODIGO_PATIO',
                'TIPO_EQUIPO' => 'required',
                'CATEGORIA_FLOTA' => 'required|in:FLOTA LIVIANA,FLOTA PESADA',
                'MARCA' => 'required',
                'MODELO' => 'required',
                'ANIO' => 'required|integer',
                'SERIAL_CHASIS' => 'required|unique:equipos,SERIAL_CHASIS',
                'SERIAL_DE_MOTOR' => 'nullable|unique:equipos,SERIAL_DE_MOTOR',
                'documentacion.PLACA' => 'nullable|unique:documentacion,PLACA',
                'ESTADO_OPERATIVO' => 'required',
                'ID_ESPEC' => 'nullable|exists:caracteristicas_modelo,ID_ESPEC', // Security: Validate catalog link exists
                'doc_propiedad' => 'nullable|file|mimes:pdf|max:5120|required_with:documentacion.NRO_DE_DOCUMENTO',
                'documentacion.NRO_DE_DOCUMENTO' => 'nullable|required_with:doc_propiedad',
                'poliza_seguro' => 'nullable|file|mimes:pdf|max:5120|required_with:documentacion.FECHA_VENC_POLIZA',
                'documentacion.FECHA_VENC_POLIZA' => 'nullable|required_with:poliza_seguro',
                'doc_rotc' => 'nullable|file|mimes:pdf|max:5120|required_with:documentacion.FECHA_ROTC',
                'documentacion.FECHA_ROTC' => 'nullable|required_with:doc_rotc',
                'doc_racda' => 'nullable|file|mimes:pdf|max:5120|required_with:documentacion.FECHA_RACDA',
                'documentacion.FECHA_RACDA' => 'nullable|required_with:doc_racda',
                'foto_equipo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
                'foto_referencial' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            ], $this->validationMessages(), $this->validationAttributes());
        } catch (\Illuminate\Validation\ValidationException $e) {

            throw $e;
        }

        // PERFORMANCE & ROBUSTNESS: Process files BEFORE opening DB transaction
        // This prevents DB locks while waiting for slow disk I/O operations
        $filesToProcess = [];

        // Handle catalog reference photo if linked
        if ($request->filled('ID_ESPEC') && $request->hasFile('foto_referencial')) {
            $file = $request->file('foto_referencial');
            $filename = 'catalog_ref_' . time() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('temp_staging', $filename, 'local');
            $filesToProcess[] = [
                'type' => 'foto_referencial',
                'path' => $path,
                'mime' => $file->getMimeType(),
                'originalName' => $filename
            ];
        }

        // Handle equipment photo
        if ($request->hasFile('foto_equipo')) {
            $file = $request->file('foto_equipo');
            $filename = 'foto_unidad_' . time() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('temp_staging', $filename, 'local');
            $filesToProcess[] = [
                'type' => 'foto_equipo',
                'path' => $path,
                'mime' => $file->getMimeType(),
                'originalName' => $filename
            ];
        }

        // Handle document files
        $docFields = [
            'doc_propiedad' => 'propiedad',
            'poliza_seguro' => 'poliza',
            'doc_rotc' => 'rotc',
            'doc_racda' => 'racda'
        ];

        foreach ($docFields as $inputName => $docType) {
            if ($request->hasFile($inputName)) {
                $file = $request->file($inputName);
                $filename = $docType . '_' . time() . '.pdf';
                $path = $file->storeAs('temp_staging', $filename, 'local');
                $filesToProcess[] = [
                    'type' => $inputName,
                    'path' => $path,
                    'mime' => 'application/pdf',
                    'originalName' => $filename
                ];
            }
        }

        // NOW start DB transaction
        DB::transaction(function () use ($request, $filesToProcess) {
            $tipoName = strtoupper($request->input('TIPO_EQUIPO'));
            $tipo = TipoEquipo::firstOrCreate(['nombre' => $tipoName]);
            $data = $request->except(['specs', 'responsable', 'documentacion', 'TIPO_EQUIPO', 'doc_propiedad', 'poliza_seguro', 'doc_rotc', 'doc_racda', 'foto_equipo', 'foto_referencial']);
            $data['id_tipo_equipo'] = $tipo->id;
            $data['TIPO_EQUIPO'] = $tipoName;
            $data['CODIGO_PATIO'] = (trim($data['CODIGO_PATIO'] ?? '') === '') ? null : strtoupper($data['CODIGO_PATIO']);
            $data['MARCA'] = strtoupper($data['MARCA'] ?? '');
            $data['MODELO'] = strtoupper($data['MODELO'] ?? '');
            $data['SERIAL_CHASIS'] = strtoupper($data['SERIAL_CHASIS'] ?? '');
            $data['SERIAL_DE_MOTOR'] = (trim($data['SERIAL_DE_MOTOR'] ?? '') === '') ? null : strtoupper(trim($data['SERIAL_DE_MOTOR']));

            $equipo = Equipo::create($data);

            // Link to catalog if specified (validation already done)
            if ($request->filled('ID_ESPEC')) {
                $equipo->ID_ESPEC = $request->input('ID_ESPEC');
                $equipo->save();
            }

            // --- DOCUMENTATION & PHOTOS UPLOAD (SYNCHRONOUS DIRECT TO DRIVE) ---
            $driveService = \App\Services\GoogleDriveService::getInstance();
            $folderId = $driveService->getRootFolderId();
            $docDataUpdates = []; // FIX: Initialize variable to avoid 500 Error if no files are uploaded

            if (count($filesToProcess) > 0) {
                // Folders Configuration (Same as Job)
                $folders = [
                    'foto_equipo' => '1Pmm9WI6YSi6Wb6-2_L0D5wk5whHs-mCf',
                    'foto_referencial' => '1KWEYWqnPjmJxz1XpR8U-Jto8KQT9RSsy',
                    'default' => $folderId
                ];

                foreach ($filesToProcess as $fileData) {
                    try {
                        $type = $fileData['type'];
                        $localPath = $fileData['path'];

                        if (!Storage::disk('local')->exists($localPath)) {
                            Log::warning("Store Upload: File missing from LOCAL storage: {$localPath}");
                            continue;
                        }
                        $fullLocalPath = Storage::disk('local')->path($localPath);
                        $targetFolderId = $folders[$type] ?? $folders['default'];

                        // Prepare Upload Object
                        $fileObject = new \Illuminate\Http\File($fullLocalPath);

                        // Upload to Drive
                        $driveFile = $driveService->uploadFile(
                            $targetFolderId,
                            $fileObject,
                            $fileData['originalName'],
                            $fileData['mime']
                        );

                        if ($driveFile && isset($driveFile->id)) {
                            // Cache Busting: Add version timestamp to URL
                            $timestamp = time();
                            $publicUrl = '/storage/google/' . $driveFile->id . '?v=' . $timestamp;

                            // Apply updates based on type
                            if ($type === 'foto_equipo') {
                                $equipo->update(['FOTO_EQUIPO' => $publicUrl]);
                            } elseif ($type === 'foto_referencial' && $equipo->ID_ESPEC) {
                                $espec = CaracteristicaModelo::find($equipo->ID_ESPEC);
                                if ($espec)
                                    $espec->update(['FOTO_REFERENCIAL' => $publicUrl]);
                            } elseif (in_array($type, ['doc_propiedad', 'poliza_seguro', 'doc_rotc', 'doc_racda'])) {
                                $colMap = [
                                    'doc_propiedad' => 'LINK_DOC_PROPIEDAD',
                                    'poliza_seguro' => 'LINK_POLIZA_SEGURO',
                                    'doc_rotc' => 'LINK_ROTC',
                                    'doc_racda' => 'LINK_RACDA'
                                ];
                                if (isset($colMap[$type])) {
                                    $docDataUpdates[$colMap[$type]] = $publicUrl;
                                }
                            }
                        }

                        // Cleanup Local
                        Storage::disk('local')->delete($localPath);

                    } catch (\Exception $e) {
                        Log::error("Store Upload Error ({$type}): " . $e->getMessage());
                        // Rethrow exception to trigger DB Loopback. 
                        // We want "All or Nothing": If file fails, don't create the Equipment.
                        throw new \Exception("Error subiendo el archivo {$type}: " . $e->getMessage());
                    }
                }

                // Save accumulated doc link updates
                if (!empty($docDataUpdates)) {
                    // We handle this below along with documentacion input data
                }
            }

            // Documentación Record
            if ($request->has('documentacion') || !empty($docDataUpdates)) {
                $reqDoc = $request->input('documentacion', []);
                $reqDoc['ID_EQUIPO'] = $equipo->ID_EQUIPO;
                $reqDoc['PLACA'] = strtoupper($reqDoc['PLACA'] ?? '');
                $reqDoc['NOMBRE_DEL_TITULAR'] = strtoupper($reqDoc['NOMBRE_DEL_TITULAR'] ?? '');
                $reqDoc['NRO_DE_DOCUMENTO'] = strtoupper($reqDoc['NRO_DE_DOCUMENTO'] ?? '');

                if (!empty($reqDoc['NOMBRE_SEGURO'])) {
                    $seguro = CatalogoSeguro::firstOrCreate(['NOMBRE_ASEGURADORA' => strtoupper($reqDoc['NOMBRE_SEGURO'])]);
                    $reqDoc['ID_SEGURO'] = $seguro->ID_SEGURO;
                }
                unset($reqDoc['NOMBRE_SEGURO']);

                // FIX: Remove ESTADO_POLIZA if present (calculated field, not in DB)
                if (isset($reqDoc['ESTADO_POLIZA'])) {
                    unset($reqDoc['ESTADO_POLIZA']);
                }

                // Merge Uploaded Links
                $reqDoc = array_merge($reqDoc, $docDataUpdates);

                $reqDoc = array_filter($reqDoc, function ($value) {
                    return !is_null($value) && $value !== '';
                });
                Documentacion::create($reqDoc);
            }

            // Responsables Record
            if ($request->has('responsable')) {
                $reqResp = $request->input('responsable');
                if (!empty($reqResp['NOMBRE_RESPONSABLE'])) {
                    $reqResp['ID_EQUIPO'] = $equipo->ID_EQUIPO;
                    $reqResp['FECHA_ASIGNACION'] = now();
                    Responsable::create($reqResp);
                }
            }
        });

        // Invalidate cached lists when new equipment is created
        \Illuminate\Support\Facades\Cache::forget('equipos_modelos_list'); // For catalog autocomplete
        \Illuminate\Support\Facades\Cache::forget('equipos_modelos_dropdown'); // For equipos index
        \Illuminate\Support\Facades\Cache::forget('equipos_marcas_dropdown');
        \Illuminate\Support\Facades\Cache::forget('equipos_anios_dropdown');

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Equipo registrado correctamente.', 'redirect' => route('equipos.index')]);
        }

        return redirect()->route('equipos.index')->with('success', 'Equipo registrado correctamente.');
    }

    public function show($id)
    {
        $equipo = Equipo::with('frenteActual', 'especificaciones', 'documentacion.seguro', 'responsables')->findOrFail($id);
        return view('admin.equipos.show', compact('equipo'));
    }

    public function edit($id)
    {
        $equipo = Equipo::with('frenteActual', 'especificaciones', 'documentacion', 'responsables')->findOrFail($id);
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE', 'asc')->pluck('NOMBRE_FRENTE', 'ID_FRENTE');
        $seguros = CatalogoSeguro::orderBy('NOMBRE_ASEGURADORA', 'asc')->pluck('NOMBRE_ASEGURADORA');
        $tipos_equipo = TipoEquipo::orderBy('nombre', 'asc')->pluck('nombre');

        // Optimización: Uso de Cache para variables globales (Solicitud Usuario)
        $marcas = \Illuminate\Support\Facades\Cache::remember('marcas_list_form_v2', 3600, function () {
            return Equipo::distinct()->whereNotNull('MARCA')->orderBy('MARCA', 'asc')->limit(1000)->pluck('MARCA');
        });

        $modelos = \Illuminate\Support\Facades\Cache::remember('modelos_list_form_v2', 3600, function () {
            return Equipo::distinct()->whereNotNull('MODELO')->orderBy('MODELO', 'asc')->limit(1000)->pluck('MODELO');
        });

        $categorias = ['FLOTA LIVIANA', 'FLOTA PESADA'];
        return view('admin.equipos.edit', compact('equipo', 'frentes', 'seguros', 'categorias', 'tipos_equipo', 'marcas', 'modelos'));
    }

    public function update(Request $request, $id)
    {
        set_time_limit(300);
        $equipo = Equipo::findOrFail($id);

        // Normalize inputs to uppercase before validation to avoid case-sensitivity issues with unique constraints
        $request->merge([
            'CODIGO_PATIO' => strtoupper($request->CODIGO_PATIO),
            'SERIAL_CHASIS' => strtoupper($request->SERIAL_CHASIS),
            'SERIAL_DE_MOTOR' => (trim($request->SERIAL_DE_MOTOR ?? '') === '') ? null : strtoupper(trim($request->SERIAL_DE_MOTOR)),
            'DETALLE_UBICACION_ACTUAL' => (trim($request->DETALLE_UBICACION_ACTUAL ?? '') === '') ? null : strtoupper(trim($request->DETALLE_UBICACION_ACTUAL)),
        ]);

        if ($request->has('documentacion.PLACA')) {
            $doc = $request->documentacion;
            $placa = trim($doc['PLACA'] ?? '');
            $doc['PLACA'] = ($placa === '') ? null : strtoupper($placa);
            $request->merge(['documentacion' => $doc]);
        }

        $validated = $request->validate([
            'CODIGO_PATIO' => 'required|unique:equipos,CODIGO_PATIO,' . $id . ',ID_EQUIPO',
            'TIPO_EQUIPO' => 'required',
            'CATEGORIA_FLOTA' => 'required|in:FLOTA LIVIANA,FLOTA PESADA',
            'MARCA' => 'required',
            'MODELO' => 'required',
            'ANIO' => 'required|integer',
            'SERIAL_CHASIS' => 'required|unique:equipos,SERIAL_CHASIS,' . $id . ',ID_EQUIPO',
            'SERIAL_DE_MOTOR' => 'nullable|unique:equipos,SERIAL_DE_MOTOR,' . $id . ',ID_EQUIPO',
            'documentacion.PLACA' => 'nullable|unique:documentacion,PLACA,' . ($equipo->documentacion ? $equipo->documentacion->ID_EQUIPO : 'NULL') . ',ID_EQUIPO',
            'ESTADO_OPERATIVO' => 'required',
            'foto_equipo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'foto_referencial' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'doc_propiedad' => 'nullable|file|mimes:pdf|max:5120',
            'poliza_seguro' => 'nullable|file|mimes:pdf|max:5120',
            'doc_rotc' => 'nullable|file|mimes:pdf|max:5120',
            'doc_racda' => 'nullable|file|mimes:pdf|max:5120',
        ], $this->validationMessages(), $this->validationAttributes());

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), []);
        // Custom validation for update: Check if file exists or is being uploaded if meta is present
        $validator->after(function ($validator) use ($request, $equipo) {
            // Propiedad
            if ($request->filled('documentacion.NRO_DE_DOCUMENTO')) {
                $hasFile = $request->hasFile('doc_propiedad');
                $hasExisting = $equipo->documentacion && $equipo->documentacion->LINK_DOC_PROPIEDAD;
                if (!$hasFile && !$hasExisting) {
                    $validator->errors()->add('doc_propiedad', 'El documento de propiedad es obligatorio si se indica el número.');
                }
            }
            if ($request->hasFile('doc_propiedad') && !$request->filled('documentacion.NRO_DE_DOCUMENTO')) {
                if (!($equipo->documentacion && $equipo->documentacion->NRO_DE_DOCUMENTO)) {
                    $validator->errors()->add('documentacion.NRO_DE_DOCUMENTO', 'El número de documento es obligatorio al cargar el archivo.');
                }
            }

            // Poliza
            if ($request->filled('documentacion.FECHA_VENC_POLIZA')) {
                $hasFile = $request->hasFile('poliza_seguro');
                $hasExisting = $equipo->documentacion && $equipo->documentacion->LINK_POLIZA_SEGURO;
                if (!$hasFile && !$hasExisting) {
                    $validator->errors()->add('poliza_seguro', 'La póliza es obligatoria si se indica el vencimiento.');
                }
            }
            if ($request->hasFile('poliza_seguro') && !$request->filled('documentacion.FECHA_VENC_POLIZA')) {
                if (!($equipo->documentacion && $equipo->documentacion->FECHA_VENC_POLIZA)) {
                    $validator->errors()->add('documentacion.FECHA_VENC_POLIZA', 'La fecha de vencimiento es obligatoria al cargar la póliza.');
                }
            }

            // ROTC
            if ($request->filled('documentacion.FECHA_ROTC')) {
                $hasFile = $request->hasFile('doc_rotc');
                $hasExisting = $equipo->documentacion && $equipo->documentacion->LINK_ROTC;
                if (!$hasFile && !$hasExisting) {
                    $validator->errors()->add('doc_rotc', 'El documento ROTC es obligatorio si se indica la fecha.');
                }
            }
            if ($request->hasFile('doc_rotc') && !$request->filled('documentacion.FECHA_ROTC')) {
                if (!($equipo->documentacion && $equipo->documentacion->FECHA_ROTC)) {
                    $validator->errors()->add('documentacion.FECHA_ROTC', 'La fecha ROTC es obligatoria al cargar el archivo.');
                }
            }

            // RACDA
            if ($request->filled('documentacion.FECHA_RACDA')) {
                $hasFile = $request->hasFile('doc_racda');
                $hasExisting = $equipo->documentacion && $equipo->documentacion->LINK_RACDA;
                if (!$hasFile && !$hasExisting) {
                    $validator->errors()->add('doc_racda', 'El documento RACDA es obligatorio si se indica la fecha.');
                }
            }
            if ($request->hasFile('doc_racda') && !$request->filled('documentacion.FECHA_RACDA')) {
                if (!($equipo->documentacion && $equipo->documentacion->FECHA_RACDA)) {
                    $validator->errors()->add('documentacion.FECHA_RACDA', 'La fecha RACDA es obligatoria al cargar el archivo.');
                }
            }
        });
        $validator->validate();

        DB::transaction(function () use ($request, $equipo) {
            $tipoName = strtoupper($request->input('TIPO_EQUIPO'));
            $tipo = TipoEquipo::firstOrCreate(['nombre' => $tipoName]);
            $data = $request->except(['specs', 'responsable', 'documentacion', 'TIPO_EQUIPO', 'doc_propiedad', 'poliza_seguro', 'doc_rotc', 'doc_racda', 'foto_equipo', 'foto_referencial']);
            $data['id_tipo_equipo'] = $tipo->id;
            $data['TIPO_EQUIPO'] = $tipoName;
            $data['CODIGO_PATIO'] = (trim($data['CODIGO_PATIO'] ?? '') === '') ? null : strtoupper($data['CODIGO_PATIO']);
            $data['MARCA'] = strtoupper(trim($data['MARCA'] ?? ''));
            $data['MODELO'] = strtoupper(trim($data['MODELO'] ?? ''));
            $data['SERIAL_CHASIS'] = strtoupper(trim($data['SERIAL_CHASIS'] ?? ''));
            $data['SERIAL_DE_MOTOR'] = (trim($data['SERIAL_DE_MOTOR'] ?? '') === '') ? null : strtoupper(trim($data['SERIAL_DE_MOTOR']));
            $equipo->update($data);

            if ($request->filled('ID_ESPEC')) {
                $equipo->ID_ESPEC = $request->input('ID_ESPEC');
                $equipo->save();
                if ($request->hasFile('foto_referencial')) {
                    $espec = CaracteristicaModelo::find($equipo->ID_ESPEC);
                    if ($espec) {
                        $catalogFolderId = config('filesystems.disks.google.catalog_folder'); // Specific folder for model photos
                        $driveService = \App\Services\GoogleDriveService::getInstance();
                        $file = $request->file('foto_referencial');
                        $filename = 'catalog_ref_' . time() . '.' . $file->getClientOriginalExtension();
                        $driveFile = $driveService->uploadFile($catalogFolderId, $file, $filename, $file->getMimeType());
                        if ($driveFile && isset($driveFile->id)) {
                            $espec->update(['FOTO_REFERENCIAL' => '/storage/google/' . $driveFile->id]);
                        }
                    }
                }
            }

            $driveService = \App\Services\GoogleDriveService::getInstance();
            $folderId = $driveService->getRootFolderId();

            if ($request->hasFile('foto_equipo')) {
                $file = $request->file('foto_equipo');
                $photoFolderId = config('filesystems.disks.google.equipment_folder'); // Specific folder for equipment photos
                $driveFile = $driveService->uploadFile($photoFolderId, $file, 'foto_unidad_' . time() . '.' . $file->getClientOriginalExtension(), $file->getMimeType());
                if ($driveFile && isset($driveFile->id)) {
                    $timestamp = time();
                    $equipo->update(['FOTO_EQUIPO' => '/storage/google/' . $driveFile->id . '?v=' . $timestamp]);
                }
            }

            if ($request->has('documentacion')) {
                $docData = $request->input('documentacion');
                if (!empty($docData['NOMBRE_SEGURO'])) {
                    $seguro = CatalogoSeguro::firstOrCreate(['NOMBRE_ASEGURADORA' => strtoupper($docData['NOMBRE_SEGURO'])]);
                    $docData['ID_SEGURO'] = $seguro->ID_SEGURO;
                }
                unset($docData['NOMBRE_SEGURO']);

                // FIX: Remove ESTADO_POLIZA if present
                if (isset($docData['ESTADO_POLIZA'])) {
                    unset($docData['ESTADO_POLIZA']);
                }
                $docData = array_filter($docData, function ($value) {
                    return !is_null($value) && $value !== '';
                });

                $docTypes = ['doc_propiedad' => 'LINK_DOC_PROPIEDAD', 'poliza_seguro' => 'LINK_POLIZA_SEGURO', 'doc_rotc' => 'LINK_ROTC', 'doc_racda' => 'LINK_RACDA'];
                foreach ($docTypes as $fileKey => $dbCol) {
                    if ($request->hasFile($fileKey)) {
                        $file = $request->file($fileKey);

                        // Check for old file and delete it (Correctly using DB relation)
                        if ($equipo->documentacion && $equipo->documentacion->$dbCol && str_starts_with($equipo->documentacion->$dbCol, '/storage/google/')) {
                            // Extract file ID (remove query params for cache busting)
                            $oldUrl = $equipo->documentacion->$dbCol;
                            $oldFileId = str_replace('/storage/google/', '', parse_url($oldUrl, PHP_URL_PATH));
                            try {
                                $driveService->deleteFile($oldFileId);
                                // Invalidate local cache
                                \Illuminate\Support\Facades\Storage::disk('local')->delete('google_cache/' . $oldFileId);
                                \Illuminate\Support\Facades\Cache::forget('gdrive_meta_' . $oldFileId);
                            } catch (\Exception $e) {
                                Log::error("Failed to delete old Drive file: $oldFileId");
                            }
                        }

                        $driveFile = $driveService->uploadFile($folderId, $file, $fileKey . '_' . time() . '.pdf', 'application/pdf');
                        if ($driveFile && isset($driveFile->id)) {
                            $timestamp = time();
                            $docData[$dbCol] = '/storage/google/' . $driveFile->id . '?v=' . $timestamp;
                        }
                    }
                }

                if (isset($docData['PLACA'])) {
                    $placaVal = trim($docData['PLACA']);
                    $docData['PLACA'] = ($placaVal === '') ? null : strtoupper($placaVal);
                }
                if (isset($docData['NOMBRE_DEL_TITULAR']))
                    $docData['NOMBRE_DEL_TITULAR'] = strtoupper($docData['NOMBRE_DEL_TITULAR']);
                if (isset($docData['NRO_DE_DOCUMENTO']))
                    $docData['NRO_DE_DOCUMENTO'] = strtoupper($docData['NRO_DE_DOCUMENTO']);

                if ($equipo->documentacion)
                    $equipo->documentacion->update($docData);
                else {
                    $docData['ID_EQUIPO'] = $equipo->ID_EQUIPO;
                    Documentacion::create($docData);
                }
            }
        });

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Equipo actualizado correctamente.']);
        }

        \Illuminate\Support\Facades\Cache::forget('dashboard_total_alerts');
        \Illuminate\Support\Facades\Cache::forget('dashboard_expired_list_v3');
        if (auth()->check()) {
            \Illuminate\Support\Facades\Cache::forget('dashboard_user_data_' . auth()->id());
        }

        return redirect()->route('equipos.index')->with('success', 'Equipo actualizado.');
    }

    public function destroy($id)
    {
        $equipo = Equipo::findOrFail($id);
        $equipo->delete();
        return redirect()->route('equipos.index')->with('success', 'Equipo eliminado.');
    }

    public function changeStatus(Request $request, $id)
    {
        $equipo = Equipo::findOrFail($id);
        $equipo->ESTADO_OPERATIVO = $request->input('status');
        $equipo->save();
        return back()->with('success', 'Estatus actualizado.');
    }


    public function uploadDoc(Request $request, $id)
    {
        if (!auth()->user()->can('user.edit') && !auth()->user()->can('equipos.edit') && !auth()->user()->can('super.admin')) {
            return response()->json(['success' => false, 'message' => 'No tiene permiso para realizar esta acción.'], 403);
        }
        set_time_limit(600);
        ini_set('memory_limit', '512M');
        $request->validate([
            'file' => 'required|file|mimes:pdf|max:51200',
            'doc_type' => 'required|in:propiedad,poliza,rotc,racda,adicional',
            'expiration_date' => 'nullable|date'
        ]);

        $equipo = Equipo::findOrFail($id);
        $type = $request->input('doc_type');
        $file = $request->file('file');

        $dbColumn = '';
        $dateColumn = '';
        $filenamePrefix = '';
        switch ($type) {
            case 'propiedad':
                $dbColumn = 'LINK_DOC_PROPIEDAD';
                $filenamePrefix = 'doc_propiedad_';
                break;
            case 'poliza':
                $dbColumn = 'LINK_POLIZA_SEGURO';
                $dateColumn = 'FECHA_VENC_POLIZA';
                $filenamePrefix = 'poliza_seguro_';
                break;
            case 'rotc':
                $dbColumn = 'LINK_ROTC';
                $dateColumn = 'FECHA_ROTC';
                $filenamePrefix = 'rotc_';
                break;
            case 'racda':
                $dbColumn = 'LINK_RACDA';
                $dateColumn = 'FECHA_RACDA';
                $filenamePrefix = 'racda_';
                break;
            case 'adicional':
                $dbColumn = 'LINK_DOC_ADICIONAL';
                $filenamePrefix = 'doc_adicional_';
                break;
        }

        try {
            $driveService = \App\Services\GoogleDriveService::getInstance();

            // 1. CAPTURE OLD FILE ID (Don't delete yet - Safety First)
            $oldFileIdToDelete = null;
            if ($equipo->documentacion && $equipo->documentacion->$dbColumn && str_starts_with($equipo->documentacion->$dbColumn, '/storage/google/')) {
                // Extract file ID (remove query params for cache busting)
                $oldUrl = $equipo->documentacion->$dbColumn;
                $oldFileIdToDelete = str_replace('/storage/google/', '', parse_url($oldUrl, PHP_URL_PATH));
            }

            // 2. UPLOAD NEW FILE
            $folderId = $driveService->getRootFolderId();
            $filename = $filenamePrefix . time() . '.pdf';
            $driveFile = $driveService->uploadFile($folderId, $file, $filename, $file->getMimeType());

            if (!$driveFile || !isset($driveFile->id))
                throw new \Exception("La subida a Google Drive no retornó un ID válido");

            // Cache Busting: Add version timestamp
            $timestamp = time();
            $fullUrl = '/storage/google/' . $driveFile->id . '?v=' . $timestamp;

            // 3. UPDATE DATABASE (Including user tracking)
            $updateData = [$dbColumn => $fullUrl];

            // Add expiration date if applicable
            if ($dateColumn && $request->filled('expiration_date')) {
                $updateData[$dateColumn] = $request->input('expiration_date');
            }

            // COMPATIBILITY FIX: Save ID (Int) to match Server DB structure
            $uploadedBy = auth()->user()->ID_USUARIO;
            $uploadedAt = now();

            switch ($type) {
                case 'propiedad':
                    $updateData['PROPIEDAD_SUBIDO_POR'] = $uploadedBy;
                    $updateData['PROPIEDAD_FECHA_SUBIDA'] = $uploadedAt;
                    break;
                case 'poliza':
                    $updateData['POLIZA_SUBIDO_POR'] = $uploadedBy;
                    $updateData['POLIZA_FECHA_SUBIDA'] = $uploadedAt;
                    break;
                case 'rotc':
                    $updateData['ROTC_SUBIDO_POR'] = $uploadedBy;
                    $updateData['ROTC_FECHA_SUBIDA'] = $uploadedAt;
                    break;
                case 'racda':
                    $updateData['RACDA_SUBIDO_POR'] = $uploadedBy;
                    $updateData['RACDA_FECHA_SUBIDA'] = $uploadedAt;
                    break;
            }

            Log::info('UploadDoc - Update Data', ['data' => $updateData]);

            if ($equipo->documentacion) {
                $equipo->documentacion->update($updateData);
                Log::info('UploadDoc - Updated existing documentacion');
            } else {
                $updateData['ID_EQUIPO'] = $equipo->ID_EQUIPO;
                Documentacion::create($updateData);
                Log::info('UploadDoc - Created new documentacion');
            }

            // 4. DELETE OLD FILE (Only after success)
            if ($oldFileIdToDelete) {
                \App\Jobs\DeleteGoogleDriveFile::dispatch($oldFileIdToDelete);
                \Illuminate\Support\Facades\Storage::disk('local')->delete('google_cache/' . $oldFileIdToDelete);
                \Illuminate\Support\Facades\Cache::forget('gdrive_meta_' . $oldFileIdToDelete);
            }

            // Clear Dashboard Caches to update alerts immediately
            \Illuminate\Support\Facades\Cache::forget('dashboard_total_alerts');
            \Illuminate\Support\Facades\Cache::forget('dashboard_expired_list_v3');
            if (auth()->check()) {
                \Illuminate\Support\Facades\Cache::forget('dashboard_user_data_' . auth()->id());
            }

            if (ob_get_length())
                ob_end_clean();

            return response()->json(['success' => true, 'link' => $fullUrl, 'message' => 'Documento actualizado correctamente']);

        } catch (\Exception $e) {
            Log::error('Error subiendo archivo a Google Drive: ' . $e->getMessage());
            if (ob_get_length())
                ob_end_clean();
            return response()->json(['success' => false, 'message' => 'Error al subir archivo: ' . $e->getMessage()], 500);
        }
    }

    private function validationMessages()
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'unique' => 'El :attribute ya ha sido registrado.',
            'integer' => 'El campo :attribute debe ser un número entero.',
            'mimes' => 'El campo :attribute debe ser un archivo de tipo: :values.',
            'max' => 'El campo :attribute no debe pesar más de :max kilobytes.',
            'image' => 'El campo :attribute debe ser una imagen.',
            'in' => 'El valor seleccionado para :attribute es inválido.',
            'required_with' => 'El campo :attribute es obligatorio cuando :values está presente.',
        ];
    }

    private function validationAttributes()
    {
        return [
            'CODIGO_PATIO' => 'Código de Patio',
            'TIPO_EQUIPO' => 'Tipo de Equipo',
            'CATEGORIA_FLOTA' => 'Categoría de Flota',
            'MARCA' => 'Marca',
            'MODELO' => 'Modelo',
            'ANIO' => 'Año',
            'SERIAL_CHASIS' => 'Serial de Chasis',
            'SERIAL_DE_MOTOR' => 'Serial de Motor',
            'documentacion.PLACA' => 'Placa',
            'ESTADO_OPERATIVO' => 'Estatus',
            'doc_propiedad' => 'Documento de Propiedad',
            'documentacion.NRO_DE_DOCUMENTO' => 'Nro. de Documento',
            'poliza_seguro' => 'Póliza de Seguro',
            'documentacion.FECHA_VENC_POLIZA' => 'Fecha de Vencimiento de Póliza',
            'doc_rotc' => 'Documento ROTC',
            'documentacion.FECHA_ROTC' => 'Fecha ROTC',
            'doc_racda' => 'Documento RACDA',
            'documentacion.FECHA_RACDA' => 'Fecha RACDA',
            'foto_equipo' => 'Foto del Equipo',
            'foto_referencial' => 'Foto Referencial',
        ];
    }
    public function checkUniqueness(Request $request)
    {
        $field = $request->input('field');
        $value = $request->input('value');
        $id = $request->input('id'); // For update exclusion

        $allowedFields = ['SERIAL_CHASIS', 'SERIAL_DE_MOTOR', 'CODIGO_PATIO', 'PLACA'];
        if (!in_array($field, $allowedFields)) {
            return response()->json(['error' => 'Invalid field'], 400);
        }

        if ($field === 'PLACA') {
            $query = Documentacion::where('PLACA', strtoupper($value));
            if ($id) {
                // If updating, we need to exclude the documentation belonging to this equipment
                $query->where('ID_EQUIPO', '!=', $id);
            }
            return response()->json(['exists' => $query->exists()]);
        }

        $query = Equipo::where($field, strtoupper($value));
        if ($id) {
            $query->where('ID_EQUIPO', '!=', $id);
        }

        return response()->json(['exists' => $query->exists()]);
    }

    /**
     * Get metadata for a specific document type
     */
    public function metadata(Request $request, $id)
    {
        $equipo = Equipo::with(['documentacion.seguro'])->findOrFail($id);

        $type = $request->input('type');
        $doc = $equipo->documentacion;
        $data = [];

        if ($doc) {
            switch ($type) {
                case 'propiedad':
                    $data = [
                        'nro_documento' => $doc->NRO_DE_DOCUMENTO ?? '',
                        'titular' => $doc->NOMBRE_DEL_TITULAR ?? '',
                        'placa' => $doc->PLACA ?? '',
                        'marca' => $equipo->MARCA ?? '',
                        'modelo' => $equipo->MODELO ?? '',
                        'serial_chasis' => $equipo->SERIAL_CHASIS ?? '',
                        'serial_motor' => $equipo->SERIAL_DE_MOTOR ?? ''
                    ];
                    break;

                case 'poliza':
                    $data = [
                        'fecha_vencimiento' => $doc->FECHA_VENC_POLIZA ?? '',
                        'id_seguro' => $doc->ID_SEGURO ?? null,
                        'insurers' => CatalogoSeguro::orderBy('NOMBRE_ASEGURADORA', 'asc')->get()
                    ];
                    break;

                case 'rotc':
                    $data = [
                        'fecha_vencimiento' => $doc->FECHA_ROTC ?? ''
                    ];
                    break;

                case 'racda':
                    $data = [
                        'fecha_vencimiento' => $doc->FECHA_RACDA ?? ''
                    ];
                    break;

                case 'adicional':
                    $data = [];
                    break;
            }
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Delete a specific document from an equipo
     */
    public function deleteDoc(Request $request, $id)
    {
        if (!auth()->user()->can('user.edit') && !auth()->user()->can('equipos.edit') && !auth()->user()->can('super.admin')) {
            return response()->json(['success' => false, 'message' => 'No tiene permiso para realizar esta acción.'], 403);
        }

        $request->validate([
            'doc_type' => 'required|in:propiedad,poliza,rotc,racda,adicional',
        ]);

        $equipo = Equipo::with('documentacion')->findOrFail($id);
        $doc = $equipo->documentacion;

        if (!$doc) {
            return response()->json(['success' => false, 'message' => 'No existe documentación para este equipo.'], 404);
        }

        $type = $request->input('doc_type');

        $fieldMap = [
            'propiedad' => 'LINK_DOC_PROPIEDAD',
            'poliza'    => 'LINK_POLIZA_SEGURO',
            'rotc'      => 'LINK_ROTC',
            'racda'     => 'LINK_RACDA',
            'adicional' => 'LINK_DOC_ADICIONAL',
        ];

        $field = $fieldMap[$type] ?? null;

        if (!$field) {
            return response()->json(['success' => false, 'message' => 'Tipo de documento no válido.'], 400);
        }

        // Clear the link field
        $doc->update([$field => null]);

        // Clear dashboard cache
        \Illuminate\Support\Facades\Cache::forget('dashboard_total_alerts');
        \Illuminate\Support\Facades\Cache::forget('dashboard_expired_list_v3');

        Log::info("Documento '{$type}' eliminado del equipo ID {$id} por usuario " . auth()->id());

        return response()->json(['success' => true, 'message' => 'Documento eliminado correctamente.']);
    }


    /**
     * Update metadata for a specific document type
     */
    public function updateMetadata(Request $request, $id)
    {
        if (!auth()->user()->can('user.edit') && !auth()->user()->can('equipos.edit') && !auth()->user()->can('super.admin')) {
            return response()->json(['success' => false, 'message' => 'No tiene permiso para realizar esta acción.'], 403);
        }
        $equipo = Equipo::with('documentacion')->findOrFail($id);
        $type = $request->input('doc_type');

        if (!$equipo->documentacion) {
            return response()->json(['success' => false, 'message' => 'No existe documentación para este equipo'], 400);
        }

        $updateData = [];

        switch ($type) {
            case 'propiedad':
                $updateData = [
                    'NRO_DE_DOCUMENTO' => strtoupper($request->input('nro_documento', '')),
                    'NOMBRE_DEL_TITULAR' => strtoupper($request->input('titular', '')),
                    'PLACA' => strtoupper($request->input('placa', '')),
                ];

                // Update Equipment basic info directly
                $equipo->update([
                    'MARCA' => strtoupper($request->input('marca', '')),
                    'MODELO' => strtoupper($request->input('modelo', '')),
                    'SERIAL_CHASIS' => strtoupper($request->input('serial_chasis', '')),
                    'SERIAL_DE_MOTOR' => (trim($request->input('serial_motor', '') ?? '') === '') ? null : strtoupper(trim($request->input('serial_motor', ''))),
                ]);
                break;

            case 'poliza':
                $updateData = [
                    'FECHA_VENC_POLIZA' => $request->input('fecha_vencimiento'),
                ];

                // Clear management if new date is in future
                if ($request->filled('fecha_vencimiento')) {
                    $newDate = \Carbon\Carbon::parse($request->input('fecha_vencimiento'));
                    if ($newDate->isFuture()) {
                        $updateData['poliza_gestion_frente_id'] = null;
                        $updateData['poliza_gestion_fecha'] = null;
                    }
                }

                // Handle insurance name (create if new)
                if ($request->filled('nombre_aseguradora')) {
                    $seguro = CatalogoSeguro::firstOrCreate([
                        'NOMBRE_ASEGURADORA' => strtoupper($request->input('nombre_aseguradora'))
                    ]);
                    $updateData['ID_SEGURO'] = $seguro->ID_SEGURO;
                }
                break;

            case 'rotc':
                $updateData = [
                    'FECHA_ROTC' => $request->input('fecha_vencimiento'),
                ];
                if ($request->filled('fecha_vencimiento')) {
                    $newDate = \Carbon\Carbon::parse($request->input('fecha_vencimiento'));
                    if ($newDate->isFuture()) {
                        $updateData['rotc_gestion_frente_id'] = null;
                        $updateData['rotc_gestion_fecha'] = null;
                    }
                }
                break;

            case 'racda':
                $updateData = [
                    'FECHA_RACDA' => $request->input('fecha_vencimiento'),
                ];
                if ($request->filled('fecha_vencimiento')) {
                    $newDate = \Carbon\Carbon::parse($request->input('fecha_vencimiento'));
                    if ($newDate->isFuture()) {
                        $updateData['racda_gestion_frente_id'] = null;
                        $updateData['racda_gestion_fecha'] = null;
                    }
                }
                break;
        }

        // Filter only empty strings (NOT nulls, because we need to save nulls to clear management)
        $updateData = array_filter($updateData, function ($value) {
            return $value !== '';
        });

        if (!empty($updateData)) {
            $equipo->documentacion->update($updateData);
        }

        // Clear Dashboard Cache to update alerts immediately
        \Illuminate\Support\Facades\Cache::forget('dashboard_total_alerts');
        \Illuminate\Support\Facades\Cache::forget('dashboard_expired_list_v3');
        if (auth()->check()) {
            \Illuminate\Support\Facades\Cache::forget('dashboard_user_data_' . auth()->id());
        }

        return response()->json(['success' => true, 'message' => 'Metadatos actualizados']);
    }

    /**
     * Search for catalog match by model and year for equipment linking widget
     */
    public function searchCatalogMatch(Request $request)
    {
        // Sanitize
        $model = strtoupper(trim($request->input('model', '')));
        $year = trim($request->input('year', ''));

        Log::info("SEARCH CATALOG MATCH: Model='$model', Year='$year'");

        if (!$model || !$year) {
            Log::info("SEARCH CATALOG: Missing params");
            return response()->json(['found' => false]);
        }

        // Use strict match but trim-safe
        // OPTIMIZED: Select only necessary columns (not SELECT *)
        $catalogEntries = CaracteristicaModelo::where('MODELO', $model)
            ->where('ANIO_ESPEC', $year)
            ->select([
                'ID_ESPEC',
                'MODELO',
                'ANIO_ESPEC',
                'MOTOR',
                'COMBUSTIBLE',
                'CONSUMO_PROMEDIO',
                'ACEITE_MOTOR',
                'ACEITE_CAJA',
                'LIGA_FRENO',
                'REFRIGERANTE',
                'TIPO_BATERIA',
                'FOTO_REFERENCIAL'
            ])
            ->get();

        if ($catalogEntries->isEmpty()) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'data' => $catalogEntries->map(function ($entry) {
                return [
                    'ID_ESPEC' => $entry->ID_ESPEC,
                    'MODELO' => $entry->MODELO,
                    'ANIO_ESPEC' => $entry->ANIO_ESPEC,
                    'MOTOR' => $entry->MOTOR,
                    'COMBUSTIBLE' => $entry->COMBUSTIBLE,
                    'CONSUMO_PROMEDIO' => $entry->CONSUMO_PROMEDIO,
                    'ACEITE_MOTOR' => $entry->ACEITE_MOTOR,
                    'ACEITE_CAJA' => $entry->ACEITE_CAJA,
                    'LIGA_FRENO' => $entry->LIGA_FRENO,
                    'REFRIGERANTE' => $entry->REFRIGERANTE,
                    'TIPO_BATERIA' => $entry->TIPO_BATERIA,
                    'FOTO_REFERENCIAL' => $entry->FOTO_REFERENCIAL ? asset($entry->FOTO_REFERENCIAL) : null,
                ];
            })->toArray()
        ]);
    }

    /**
     * Get all unique models from both equipos and catalog for autocomplete
     */
    public function getAllModels(Request $request)
    {
        $query = strtoupper(trim($request->input('query', '')));

        // Get models from equipos
        $equiposModels = \App\Models\Equipo::select('MODELO')
            ->distinct()
            ->whereNotNull('MODELO')
            ->where('MODELO', 'LIKE', "%{$query}%")
            ->pluck('MODELO');

        // Get models from catalog
        $catalogModels = CaracteristicaModelo::select('MODELO')
            ->distinct()
            ->whereNotNull('MODELO')
            ->where('MODELO', 'LIKE', "%{$query}%")
            ->pluck('MODELO');

        // Merge and get unique values
        $allModels = $equiposModels->merge($catalogModels)->unique()->sort()->values();

        return response()->json($allModels);
    }

    /**
     * Get Fleet Statistics for Dashboard (Cross-Analysis with Frente Filter)
     * OPTIMIZADO: caché por frente+usuario, queries consolidadas, bug fix acceso local
     */
    public function fleetStats(Request $request)
    {
        try {
            $user              = auth()->user();
            $isLocal           = $user && $user->NIVEL_ACCESO == 2;
            $frentesPermitidos = $user ? $user->getFrentesIds() : [];
            $requestedFrenteId = $request->input('frente_id');

            // ── Cache key única por usuario + frente solicitado ──────────────────
            $cacheKey = 'fleet_stats_u' . ($user?->id ?? 'guest')
                      . '_f' . ($requestedFrenteId ?: 'all');

            return \Illuminate\Support\Facades\Cache::remember($cacheKey, 120, function () use (
                $isLocal, $frentesPermitidos, $requestedFrenteId
            ) {
                // ── Construir la query base una sola vez ──────────────────────────
                $baseQuery = Equipo::query();

                if ($isLocal && count($frentesPermitidos) > 0) {
                    // Bug fix: NO aplicar dos where consecutivos — usar solo whereIn con un frente si está solicitado
                    if ($requestedFrenteId && $requestedFrenteId !== 'all'
                        && in_array($requestedFrenteId, $frentesPermitidos)
                    ) {
                        $baseQuery->where('ID_FRENTE_ACTUAL', $requestedFrenteId);
                    } else {
                        $baseQuery->whereIn('ID_FRENTE_ACTUAL', $frentesPermitidos);
                    }
                } elseif ($isLocal) {
                    // Usuario local sin frentes permitidos: sin datos
                    $baseQuery->whereRaw('1 = 0');
                } elseif ($requestedFrenteId && $requestedFrenteId !== 'all') {
                    $baseQuery->where('ID_FRENTE_ACTUAL', $requestedFrenteId);
                }

                // ── Stats básicas: 3 counts en una sola query usando SUM condicional ──
                // NOTA: usamos una query fresca con los mismos wheres para evitar conflicto de SELECT en MySQL
                $basicStats = (clone $baseQuery)
                    ->selectRaw('
                        SUM(CASE WHEN ESTADO_OPERATIVO != \'DESINCORPORADO\' THEN 1 ELSE 0 END) as total,
                        SUM(CASE WHEN ANIO >= 2025 AND ESTADO_OPERATIVO != \'DESINCORPORADO\' THEN 1 ELSE 0 END) as fleet_new,
                        SUM(CASE WHEN ANIO <  2025 AND ESTADO_OPERATIVO != \'DESINCORPORADO\' THEN 1 ELSE 0 END) as fleet_old
                    ')
                    ->first();

                $total    = (int) ($basicStats->total    ?? 0);
                $fleetNew = (int) ($basicStats->fleet_new ?? 0);
                $fleetOld = (int) ($basicStats->fleet_old ?? 0);

                // ── Consumo total: JOIN con especificaciones ──────────────────────
                $totalConsumption = (clone $baseQuery)
                    ->join('caracteristicas_modelo', 'equipos.ID_ESPEC', '=', 'caracteristicas_modelo.ID_ESPEC')
                    ->sum(DB::raw('CAST(caracteristicas_modelo.CONSUMO_PROMEDIO AS DECIMAL(10,2))'));

                // ── 1. Estado Operativo ───────────────────────────────────────────
                $byStatusRaw = (clone $baseQuery)
                    ->select('ESTADO_OPERATIVO', DB::raw('COUNT(*) as total'))
                    ->whereNotNull('ESTADO_OPERATIVO')
                    ->groupBy('ESTADO_OPERATIVO')
                    ->orderByDesc('total')
                    ->get();

                // ── 2, 3, 4. Queries agrupadas por tipo (un solo JOIN compartido) ─
                $byTypeRaw = (clone $baseQuery)
                    ->select(
                        'tipo_equipos.nombre as tipo_nombre',
                        DB::raw('SUM(CASE WHEN equipos.ANIO >= 2025 THEN 1 ELSE 0 END) as new_count'),
                        DB::raw('SUM(CASE WHEN equipos.ANIO <  2025 THEN 1 ELSE 0 END) as old_count'),
                        DB::raw("SUM(CASE WHEN equipos.CATEGORIA_FLOTA = 'FLOTA PESADA'  THEN 1 ELSE 0 END) as pesada_count"),
                        DB::raw("SUM(CASE WHEN equipos.CATEGORIA_FLOTA = 'FLOTA LIVIANA' THEN 1 ELSE 0 END) as liviana_count"),
                        DB::raw("SUM(CASE WHEN equipos.ESTADO_OPERATIVO = 'INOPERATIVO'      THEN 1 ELSE 0 END) as inoperativo_count"),
                        DB::raw("SUM(CASE WHEN equipos.ESTADO_OPERATIVO = 'EN MANTENIMIENTO' THEN 1 ELSE 0 END) as mantenimiento_count")
                    )
                    ->leftJoin('tipo_equipos', 'equipos.id_tipo_equipo', '=', 'tipo_equipos.id')
                    ->whereNotNull('equipos.id_tipo_equipo')
                    ->whereNotNull('tipo_equipos.nombre')
                    ->groupBy('tipo_equipos.nombre')
                    ->orderBy('tipo_equipos.nombre')
                    ->get();

                // ── 5. Equipos por Frente (siempre global, sin filtro) ────────────
                $eqByFrenteRaw = Equipo::query()
                    ->select(
                        'frentes_trabajo.NOMBRE_FRENTE as frente_nombre',
                        DB::raw('COUNT(equipos.ID_EQUIPO) as total')
                    )
                    ->leftJoin('frentes_trabajo', 'equipos.ID_FRENTE_ACTUAL', '=', 'frentes_trabajo.ID_FRENTE')
                    ->whereNotNull('equipos.ID_FRENTE_ACTUAL')
                    ->whereNotNull('frentes_trabajo.NOMBRE_FRENTE')
                    ->groupBy('frentes_trabajo.NOMBRE_FRENTE')
                    ->orderByDesc('total')
                    ->get();

                // ── Transformar byTypeRaw a las 3 secciones ───────────────────────
                // Age (flota nueva vs vieja)
                $ageLabels    = $byTypeRaw->pluck('tipo_nombre')->toArray();
                $newFleetData = $byTypeRaw->pluck('new_count')->map(fn($v) => (int)$v)->toArray();
                $oldFleetData = $byTypeRaw->pluck('old_count')->map(fn($v) => (int)$v)->toArray();

                // Category (pesada vs liviana) — filtrar tipos sin ningún dato
                $catFiltered = $byTypeRaw->filter(fn($r) => ((int)$r->pesada_count + (int)$r->liviana_count) > 0);
                $catLabels   = $catFiltered->pluck('tipo_nombre')->toArray();
                $pesadaData  = $catFiltered->pluck('pesada_count')->map(fn($v) => (int)$v)->values()->toArray();
                $livianaData = $catFiltered->pluck('liviana_count')->map(fn($v) => (int)$v)->values()->toArray();

                // Inoperative (inoperativo + mantenimiento) — filtrar tipos sin datos
                $inopFiltered    = $byTypeRaw->filter(fn($r) => ((int)$r->inoperativo_count + (int)$r->mantenimiento_count) > 0);
                $inopLabels      = $inopFiltered->pluck('tipo_nombre')->toArray();
                $inoperativoData = $inopFiltered->pluck('inoperativo_count')->map(fn($v) => (int)$v)->values()->toArray();
                $mantenimientoData = $inopFiltered->pluck('mantenimiento_count')->map(fn($v) => (int)$v)->values()->toArray();

                return response()->json([
                    'success' => true,
                    'stats' => [
                        'total'             => $total,
                        'fleet_new'         => $fleetNew,
                        'fleet_old'         => $fleetOld,
                        'total_consumption' => number_format((float)$totalConsumption, 2)
                    ],
                    'byStatus' => [
                        'labels' => $byStatusRaw->pluck('ESTADO_OPERATIVO')->toArray(),
                        'values' => $byStatusRaw->pluck('total')->map(fn($v) => (int)$v)->toArray()
                    ],
                    'ageByType' => [
                        'labels'   => $ageLabels,
                        'datasets' => [
                            ['label' => 'Flota Nueva (≥2025)', 'data' => $newFleetData],
                            ['label' => 'Flota Vieja (<2025)',  'data' => $oldFleetData]
                        ]
                    ],
                    'categoryByType' => [
                        'labels'   => $catLabels,
                        'datasets' => [
                            ['label' => 'Flota Pesada',  'data' => $pesadaData],
                            ['label' => 'Flota Liviana', 'data' => $livianaData]
                        ]
                    ],
                    'inoperativeByType' => [
                        'labels'   => $inopLabels,
                        'datasets' => [
                            ['label' => 'Inoperativo',      'data' => $inoperativoData],
                            ['label' => 'En Mantenimiento', 'data' => $mantenimientoData]
                        ]
                    ],
                    'equiposPorFrente' => $eqByFrenteRaw->map(fn($r) => [
                        'frente' => $r->frente_nombre,
                        'total'  => (int) $r->total,
                    ])->values()->toArray(),
                ]);
            });

        } catch (\Exception $e) {
            Log::error('Fleet Stats Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export Fleet Stats to CSV (Excel compatible)
     */
    public function fleetExport(Request $request)
    {
        try {
            $user = auth()->user();
            $isLocal = $user && $user->NIVEL_ACCESO == 2;
            $frentesPermitidos = $user ? $user->getFrentesIds() : [];
            $requestedFrenteId = $request->input('frente_id');

            $frenteNombre = 'TODOS LOS FRENTES';

            // Base query builder for filtering
            $baseQuery = Equipo::query();

            if ($isLocal && count($frentesPermitidos) > 0) {
                if ($requestedFrenteId && $requestedFrenteId !== 'all') {
                    $baseQuery->where('ID_FRENTE_ACTUAL', $requestedFrenteId);
                    $baseQuery->whereIn('ID_FRENTE_ACTUAL', $frentesPermitidos);
                    $frenteObj = FrenteTrabajo::find($requestedFrenteId);
                    $frenteNombre = $frenteObj ? mb_strtoupper($frenteObj->NOMBRE_FRENTE) : 'FRENTE VARIANTE';
                } else {
                    $baseQuery->whereIn('ID_FRENTE_ACTUAL', $frentesPermitidos);
                    $frenteNombre = 'MIS FRENTES ASIGNADOS';
                }
            } elseif ($isLocal) {
                $baseQuery->whereRaw('1 = 0');
            } elseif ($requestedFrenteId && $requestedFrenteId !== 'all') {
                $baseQuery->where('ID_FRENTE_ACTUAL', $requestedFrenteId);
                $frenteObj = FrenteTrabajo::find($requestedFrenteId);
                $frenteNombre = $frenteObj ? mb_strtoupper($frenteObj->NOMBRE_FRENTE) : 'FRENTE ESPECÍFICO';
            }

            // --- 1. DATA FOR "FLOTA NUEVA VS VIEJA" ---
            $ageData = (clone $baseQuery)
                ->select(
                    'id_tipo_equipo',
                    DB::raw('SUM(CASE WHEN ANIO >= 2025 THEN 1 ELSE 0 END) as new_count'),
                    DB::raw('SUM(CASE WHEN ANIO < 2025 THEN 1 ELSE 0 END) as old_count')
                )
                ->with('tipo:id,nombre')
                ->groupBy('id_tipo_equipo')
                ->get();

            // --- 2. DATA FOR "PESADA VS LIVIANA" ---
            $categoryData = (clone $baseQuery)
                ->select(
                    'id_tipo_equipo',
                    DB::raw("SUM(CASE WHEN CATEGORIA_FLOTA = 'FLOTA PESADA' THEN 1 ELSE 0 END) as pesada_count"),
                    DB::raw("SUM(CASE WHEN CATEGORIA_FLOTA = 'FLOTA LIVIANA' THEN 1 ELSE 0 END) as liviana_count"),
                    DB::raw("SUM(CASE WHEN CATEGORIA_FLOTA IS NULL OR CATEGORIA_FLOTA = '' THEN 1 ELSE 0 END) as sin_asignar_count")
                )
                ->with('tipo:id,nombre')
                ->groupBy('id_tipo_equipo')
                ->get();

            // --- 3. DATA FOR "ESTADO OPERATIVO" ---
            $statusData = (clone $baseQuery)
                ->select(
                    'ESTADO_OPERATIVO',
                    DB::raw('COUNT(*) as total_count')
                )
                ->groupBy('ESTADO_OPERATIVO')
                ->get();

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Análisis de Flota');

            // Document properties
            $spreadsheet->getProperties()
                ->setCreator('Sistema de Gestión de Equipos')
                ->setLastModifiedBy('Sistema de Gestión de Equipos')
                ->setTitle('Análisis de Flota Operacional')
                ->setSubject('Reporte de Análisis de Flota')
                ->setDescription('Generado automáticamente por el Sistema de Gestión de Equipos - C.VIDALSA 27, C.A.')
                ->setCompany('Constructora Vidalsa 27, C.A.');

            $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

            // LOGO
            $logoPath = public_path('img/imagen_uno.jpg');
            if (file_exists($logoPath)) {
                try {
                    $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                    $drawing->setName('Logo CVIDALSA');
                    $drawing->setDescription('Logo');
                    $drawing->setPath($logoPath);
                    $drawing->setCoordinates('A1');
                    $drawing->setOffsetX(5);
                    $drawing->setOffsetY(8);
                    $drawing->setHeight(90);
                    $drawing->setWorksheet($sheet);
                } catch (\Exception $e) { }
            }

            // Headers & Metadata
            $sheet->mergeCells('A1:A3');
            $sheet->getStyle('A1:A3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
            
            // Ajustar altura de cabecera para atrapar el logo
            $sheet->getRowDimension(1)->setRowHeight(35);
            $sheet->getRowDimension(2)->setRowHeight(35);
            $sheet->getRowDimension(3)->setRowHeight(35);

            $sheet->mergeCells('B1:E3');
            $titleText = "ANÁLISIS ESTADÍSTICO DE FLOTA OPERACIONAL\nPROYECTO: \"{$frenteNombre}\"";
            $sheet->setCellValue('B1', $titleText);
            $sheet->getStyle('B1')->getAlignment()->setWrapText(true);
            $sheet->getStyle('B1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('B1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(13)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
            $sheet->getStyle('B1:E3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
            
            // Borde enmarcando toda la cabecera
            $sheet->getStyle('A1:E3')->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
            ]);
            
            $sheet->mergeCells('A4:E4');
            $fechaEmision = 'Reporte emitido por el sistema en fecha: ' . date('d/m/Y h:i A');
            $sheet->setCellValue('A4', $fechaEmision);
            $sheet->getStyle('A4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle('A4')->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF555555');

            // --- ESTILOS COMPARTIDOS PARA TABLAS ---
            $headerStyle = [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF004E8A']
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
                ],
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
            ];
            $rowStyle = [
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
            ];

            $currentRow = 7;

            // --- TABLA 1: ESTADO OPERATIVO ---
            $sheet->mergeCells("A{$currentRow}:E{$currentRow}");
            $sheet->setCellValue("A{$currentRow}", 'RESUMEN: ESTADO OPERATIVO DE EQUIPOS');
            $sheet->getStyle("A{$currentRow}")->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle("A{$currentRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("A{$currentRow}:E{$currentRow}")->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
            ]);
            $currentRow++;

            $sheet->mergeCells("A{$currentRow}:D{$currentRow}");
            $sheet->setCellValue("A{$currentRow}", 'ESTADO OPERATIVO');
            $sheet->setCellValue("E{$currentRow}", 'CANTIDAD');
            $sheet->getStyle("A{$currentRow}:E{$currentRow}")->applyFromArray($headerStyle);
            $sheet->getRowDimension($currentRow)->setRowHeight(25);
            
            foreach ($statusData as $row) {
                $currentRow++;
                $estadoName = $row->ESTADO_OPERATIVO ?: 'DESCONOCIDO';
                
                $sheet->mergeCells("A{$currentRow}:D{$currentRow}");
                $sheet->setCellValue("A{$currentRow}", mb_strtoupper($estadoName));
                $sheet->setCellValue("E{$currentRow}", $row->total_count);
                
                $sheet->getStyle("A{$currentRow}:E{$currentRow}")->applyFromArray($rowStyle);
                $sheet->getStyle("E{$currentRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            }

            // --- TABLA 2: FLOTA NUEVA VS VIEJA ---
            $currentRow += 4; // Espacio entre tablas

            $sheet->mergeCells("A{$currentRow}:E{$currentRow}");
            $sheet->setCellValue("A{$currentRow}", 'RESUMEN: FLOTA NUEVA VS FLOTA VIEJA POR TIPO');
            $sheet->getStyle("A{$currentRow}")->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle("A{$currentRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("A{$currentRow}:E{$currentRow}")->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
            ]);
            $currentRow++;

            $sheet->mergeCells("A{$currentRow}:B{$currentRow}");
            $sheet->setCellValue("A{$currentRow}", 'TIPO DE EQUIPO');
            $sheet->setCellValue("C{$currentRow}", "NUEVO\n(≥ 2025)");
            $sheet->setCellValue("D{$currentRow}", "VIEJO\n(< 2025)");
            $sheet->setCellValue("E{$currentRow}", 'TOTAL');
            $sheet->getStyle("A{$currentRow}:E{$currentRow}")->applyFromArray($headerStyle);
            $sheet->getStyle("A{$currentRow}:E{$currentRow}")->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($currentRow)->setRowHeight(30);
            
            foreach ($ageData as $row) {
                $currentRow++;
                $tipoName = $row->tipo ? mb_strtoupper($row->tipo->nombre) : 'SIN TIPO';
                $total = $row->new_count + $row->old_count;
                
                $sheet->mergeCells("A{$currentRow}:B{$currentRow}");
                $sheet->setCellValue("A{$currentRow}", $tipoName);
                $sheet->setCellValue("C{$currentRow}", $row->new_count);
                $sheet->setCellValue("D{$currentRow}", $row->old_count);
                $sheet->setCellValue("E{$currentRow}", $total);
                
                $sheet->getStyle("A{$currentRow}:E{$currentRow}")->applyFromArray($rowStyle);
                $sheet->getStyle("C{$currentRow}:E{$currentRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            }

            // --- TABLA 2: PESADA VS LIVIANA ---
            $currentRow += 4; // Espacio entre tablas

            $sheet->mergeCells("A{$currentRow}:E{$currentRow}");
            $sheet->setCellValue("A{$currentRow}", 'RESUMEN: CLASIFICACIÓN POR CATEGORÍA DE FLOTA (PESADA VS LIVIANA)');
            $sheet->getStyle("A{$currentRow}")->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle("A{$currentRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("A{$currentRow}:E{$currentRow}")->applyFromArray([
                'borders' => ['outline' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]]
            ]);
            $currentRow++;

            $sheet->setCellValue("A{$currentRow}", 'TIPO DE EQUIPO');
            $sheet->setCellValue("B{$currentRow}", 'PESADA');
            $sheet->setCellValue("C{$currentRow}", 'LIVIANA');
            $sheet->setCellValue("D{$currentRow}", 'SIN ASIGNAR');
            $sheet->setCellValue("E{$currentRow}", 'TOTAL');
            $sheet->getStyle("A{$currentRow}:E{$currentRow}")->applyFromArray($headerStyle);
            $sheet->getRowDimension($currentRow)->setRowHeight(20);

            foreach ($categoryData as $row) {
                $currentRow++;
                $tipoName = $row->tipo ? mb_strtoupper($row->tipo->nombre) : 'SIN TIPO';
                $total = $row->pesada_count + $row->liviana_count + $row->sin_asignar_count;
                
                $sheet->setCellValue("A{$currentRow}", $tipoName);
                $sheet->setCellValue("B{$currentRow}", $row->pesada_count);
                $sheet->setCellValue("C{$currentRow}", $row->liviana_count);
                $sheet->setCellValue("D{$currentRow}", $row->sin_asignar_count);
                $sheet->setCellValue("E{$currentRow}", $total);
                
                $sheet->getStyle("A{$currentRow}:E{$currentRow}")->applyFromArray($rowStyle);
                $sheet->getStyle("B{$currentRow}:E{$currentRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            }

            // Auto-size columns for optimal mapping 
            $sheet->getColumnDimension('A')->setWidth(35);
            $sheet->getColumnDimension('B')->setWidth(20);
            $sheet->getColumnDimension('C')->setWidth(15);
            $sheet->getColumnDimension('D')->setWidth(15);
            $sheet->getColumnDimension('E')->setWidth(15);

            $fileName = 'analisis_flota_' . date('Y-m-d_H-i') . '.xlsx';
            $tempFile = tempnam(sys_get_temp_dir(), 'export_cvidalsa_');

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($tempFile);

            return response()->download($tempFile, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            Log::error('Fleet Export Excel Error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Ocurrió un error al generar el archivo Excel de análisis.');
        }
    }

    /**
     * Get equipos in a given frente filtered by anchor role (REMOLCADOR/REMOLCABLE)
     */
    public function getEquiposByFrente(Request $request)
    {
        $request->validate([
            'id_frente'   => 'required',
            'exclude_ids' => 'nullable|array',
        ]);

        $equipos = Equipo::where('ID_FRENTE_ACTUAL', $request->id_frente)
            ->whereHas('tipo', function ($q) use ($request) {
                if ($request->source_role === 'REMOLCADOR') {
                    $q->where('ROL_ANCLAJE', 'REMOLCABLE');
                } elseif ($request->source_role === 'REMOLCABLE') {
                    $q->where('ROL_ANCLAJE', 'REMOLCADOR');
                } else {
                    $q->where('ROL_ANCLAJE', 'NONE');
                }
            })
            ->when($request->exclude_ids, function ($q) use ($request) {
                $q->whereNotIn('ID_EQUIPO', $request->exclude_ids);
            })
            ->with(['especificaciones', 'documentacion', 'tipo'])
            ->select('ID_EQUIPO', 'CODIGO_PATIO', 'MARCA', 'MODELO', 'ID_ESPEC', 'FOTO_EQUIPO', 'SERIAL_CHASIS', 'id_tipo_equipo')
            ->orderBy('CODIGO_PATIO')
            ->get()
            ->map(function ($eq) {
                return [
                    'ID_EQUIPO'     => $eq->ID_EQUIPO,
                    'CODIGO_PATIO'  => $eq->CODIGO_PATIO,
                    'TIPO_NOMBRE'   => $eq->tipo->nombre ?? $eq->CODIGO_PATIO,
                    'SERIAL_CHASIS' => $eq->SERIAL_CHASIS,
                    'PLACA'         => $eq->documentacion->PLACA ?? null,
                    'MARCA'         => $eq->MARCA,
                    'MODELO'        => $eq->MODELO,
                    'FOTO'          => $eq->especificaciones->FOTO_REFERENCIAL ?? $eq->FOTO_EQUIPO,
                ];
            });

        return response()->json($equipos);
    }

    /**
     * Get anchored equipment pairs for a specific frente (or all if not specified)
     */
    public function getAnchoredEquipos(Request $request)
    {
        $frenteId = $request->input('frente_id');
        $query = Equipo::with(['ancladoA', 'tipo', 'especificaciones', 'documentacion'])->whereNotNull('ID_ANCLAJE');

        if ($frenteId && $frenteId !== 'all') {
            $query->where('ID_FRENTE_ACTUAL', $frenteId);
        }

        $anchored = $query->get()->map(function ($eq) {
            // Get mutual pair to avoid duplicates, we can just return all since we'll group them in JS, or we can format it here.
            // A mutual pair means Eq A is anchored to Eq B. In this system Eq A has ID_ANCLAJE = B.ID, and Eq B has ID_ANCLAJE = A.ID
            // Let's standardise so we only return one pair, where master is the one with smaller ID_EQUIPO, just for uniqueness if mutual.
            $mainImg = $eq->especificaciones->FOTO_REFERENCIAL ?? $eq->FOTO_EQUIPO;
            $anchImg = $eq->ancladoA ? ($eq->ancladoA->especificaciones->FOTO_REFERENCIAL ?? $eq->ancladoA->FOTO_EQUIPO) : null;

            return [
                'ID_A' => $eq->ID_EQUIPO,
                'ID_B' => $eq->ID_ANCLAJE,
                'eq_a' => [
                    'id' => $eq->ID_EQUIPO,
                    'codigo' => $eq->CODIGO_PATIO ?? 'N/A',
                    'etiqueta' => $eq->NUMERO_ETIQUETA ?? null,
                    'placa' => $eq->documentacion->PLACA ?? null,
                    'serial' => $eq->SERIAL_CHASIS ?? null,
                    'marca_modelo' => ($eq->MARCA ?? '') . ' ' . ($eq->MODELO ?? ''),
                    'foto' => $mainImg ? asset($mainImg) : null,
                    'tipo' => $eq->tipo->nombre ?? 'N/A',
                    'estado' => $eq->ESTADO_OPERATIVO ?? 'N/A'
                ],
                'eq_b' => $eq->ancladoA ? [
                    'id' => $eq->ancladoA->ID_EQUIPO,
                    'codigo' => $eq->ancladoA->CODIGO_PATIO ?? 'N/A',
                    'etiqueta' => $eq->ancladoA->NUMERO_ETIQUETA ?? null,
                    'placa' => $eq->ancladoA->documentacion->PLACA ?? null,
                    'serial' => $eq->ancladoA->SERIAL_CHASIS ?? null,
                    'marca_modelo' => ($eq->ancladoA->MARCA ?? '') . ' ' . ($eq->ancladoA->MODELO ?? ''),
                    'foto' => $anchImg ? asset($anchImg) : null,
                    'tipo' => $eq->ancladoA->tipo->nombre ?? 'N/A',
                    'estado' => $eq->ancladoA->ESTADO_OPERATIVO ?? 'N/A'
                ] : null
            ];
        });

        // Filter out the duplicates based on mutual anchorage (A->B and B->A)
        $uniquePairs = [];
        $seen = [];
        foreach ($anchored as $item) {
            if (!$item['eq_b']) continue;
            
            $id1 = $item['ID_A'];
            $id2 = $item['ID_B'];
            $key = $id1 < $id2 ? "{$id1}_{$id2}" : "{$id2}_{$id1}";
            
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $uniquePairs[] = $item;
            }
        }

        return response()->json($uniquePairs);
    }

    /**
     * Perform bulk anchoring of equipment (mutual link between two equipos)
     */
    public function bulkAnchor(Request $request)
    {
        $request->validate([
            'ids'       => 'required|array',
            'ids.*'     => 'exists:equipos,ID_EQUIPO',
            'master_id' => 'required|exists:equipos,ID_EQUIPO',
        ]);

        try {
            DB::beginTransaction();

            $sourceId = $request->ids[0];
            $targetId = $request->master_id;

            // Create mutual anchor link
            Equipo::where('ID_EQUIPO', $sourceId)->update(['ID_ANCLAJE' => $targetId]);
            Equipo::where('ID_EQUIPO', $targetId)->update(['ID_ANCLAJE' => $sourceId]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Equipos anclados mutuamente con éxito.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('bulkAnchor error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Clear anchor links for specified equipos
     */
    public function clearAnchor(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'exists:equipos,ID_EQUIPO',
        ]);

        try {
            DB::beginTransaction();

            // Clear anchors for provided IDs
            Equipo::whereIn('ID_EQUIPO', $request->ids)->update(['ID_ANCLAJE' => null]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Anclaje eliminado con éxito.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('clearAnchor error: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ─── QUICK EDIT: UBICACIÓN ───────────────────────────────────────────────────
    public function updateUbicacion(Request $request, $id)
    {
        // Requiere el mismo permiso que editar equipos
        if (! auth()->user()?->can('equipos.edit')) {
            return response()->json(['success' => false, 'error' => 'Sin permisos'], 403);
        }

        $equipo = Equipo::findOrFail($id);

        $request->validate([
            'DETALLE_UBICACION_ACTUAL' => 'nullable|string|max:150',
        ]);

        $valor = $request->filled('DETALLE_UBICACION_ACTUAL')
            ? strtoupper(trim($request->DETALLE_UBICACION_ACTUAL))
            : null;

        $equipo->DETALLE_UBICACION_ACTUAL = $valor;
        $equipo->save();

        return response()->json([
            'success'                 => true,
            'DETALLE_UBICACION_ACTUAL' => $valor,
        ]);
    }

    // ─── MOBILE API ───────────────────────────────────────────────────────────────
    public function mobileIndex(Request $request)
    {
        $search = $request->input('search');

        $query = Equipo::with(['tipo', 'frenteActual', 'documentacion']);

        if ($search) {
            $searchUpper = strtoupper(trim($search));
            $query->where(function ($q) use ($searchUpper) {
                $q->where('CODIGO_PATIO', 'like', "%{$searchUpper}%")
                  ->orWhere('SERIAL_CHASIS', 'like', "%{$searchUpper}%")
                  ->orWhere('MARCA', 'like', "%{$searchUpper}%")
                  ->orWhere('MODELO', 'like', "%{$searchUpper}%")
                  ->orWhere('NUMERO_ETIQUETA', 'like', "%{$searchUpper}%")
                  ->orWhereHas('documentacion', function ($d) use ($searchUpper) {
                      $d->where('PLACA', 'like', "%{$searchUpper}%");
                  })
                  ->orWhereHas('frenteActual', function ($f) use ($searchUpper) {
                      $f->where('NOMBRE_FRENTE', 'like', "%{$searchUpper}%");
                  });
            });
        }

        $equipos = $query->orderBy('CODIGO_PATIO')->get();

        return response()->json($equipos->map(function ($eq) {
            return [
                'ID_EQUIPO'       => $eq->ID_EQUIPO,
                'CODIGO_PATIO'    => $eq->CODIGO_PATIO,
                'TIPO'            => $eq->tipo->nombre ?? 'N/A',
                'MARCA'           => $eq->MARCA,
                'MODELO'          => $eq->MODELO,
                'ANIO'            => $eq->ANIO,
                'CATEGORIA_FLOTA' => $eq->CATEGORIA_FLOTA,
                'SERIAL_CHASIS'   => $eq->SERIAL_CHASIS,
                'SERIAL_MOTOR'    => $eq->SERIAL_DE_MOTOR,
                'NUMERO_ETIQUETA' => $eq->NUMERO_ETIQUETA,
                'ESTADO_OPERATIVO'=> $eq->ESTADO_OPERATIVO,
                'PLACA'           => $eq->documentacion->PLACA ?? 'S/P',
                'FRENTE_ACTUAL'   => $eq->frenteActual->NOMBRE_FRENTE ?? 'Sin Asignar',
                'DETALLE_UBICACION' => $eq->DETALLE_UBICACION_ACTUAL,
                'CONFIRMADO'      => $eq->CONFIRMADO_EN_SITIO,
            ];
        }));
    }

    public function mobileShow($id)
    {
        $eq = Equipo::with(['tipo', 'frenteActual', 'documentacion'])->findOrFail($id);
        return response()->json([
            'ID_EQUIPO'       => $eq->ID_EQUIPO,
            'CODIGO_PATIO'    => $eq->CODIGO_PATIO,
            'TIPO'            => $eq->tipo->nombre ?? 'N/A',
            'MARCA'           => $eq->MARCA,
            'MODELO'          => $eq->MODELO,
            'ANIO'            => $eq->ANIO,
            'SERIAL_CHASIS'   => $eq->SERIAL_CHASIS,
            'SERIAL_MOTOR'    => $eq->SERIAL_DE_MOTOR,
            'NUMERO_ETIQUETA' => $eq->NUMERO_ETIQUETA,
            'ESTADO_OPERATIVO'=> $eq->ESTADO_OPERATIVO,
            'PLACA'           => $eq->documentacion->PLACA ?? 'S/P',
            'FRENTE_ACTUAL'   => $eq->frenteActual->NOMBRE_FRENTE ?? 'Sin Asignar',
            'DETALLE_UBICACION' => $eq->DETALLE_UBICACION_ACTUAL,
            'NRO_DOCUMENTO'   => $eq->documentacion->NRO_DE_DOCUMENTO ?? '',
            'PROPIETARIO'     => $eq->documentacion->NOMBRE_DEL_TITULAR ?? '',
            'DOC_PROPIEDAD'   => $eq->documentacion && $eq->documentacion->LINK_DOC_PROPIEDAD ? asset('storage/' . $eq->documentacion->LINK_DOC_PROPIEDAD) : null,
            'DOC_POLIZA'      => $eq->documentacion && $eq->documentacion->LINK_POLIZA_SEGURO ? asset('storage/' . $eq->documentacion->LINK_POLIZA_SEGURO) : null,
            'DOC_ROTC'        => $eq->documentacion && $eq->documentacion->LINK_ROTC ? asset('storage/' . $eq->documentacion->LINK_ROTC) : null,
            'DOC_RACDA'       => $eq->documentacion && $eq->documentacion->LINK_RACDA ? asset('storage/' . $eq->documentacion->LINK_RACDA) : null,
        ]);
    }
    // ──────────────────────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────────────────────
    // ── GESTIÓN DE RESPONSABLES DE EQUIPOS ─────────────────────────────────────────

    public function getResponsables($id)
    {
        $responsables = \App\Models\Responsable::where('ID_EQUIPO', $id)
            ->orderBy('FECHA_ASIGNACION', 'desc')
            ->get();
        return response()->json(['success' => true, 'data' => $responsables]);
    }

    public function storeResponsable(Request $request, $id)
    {
        $request->validate([
            'CEDULA_RESPONSABLE' => 'required|string|max:20',
            'PERSONA_ASIGNADA'   => 'required|string|max:150',
        ]);

        $responsable = \App\Models\Responsable::create([
            'ID_EQUIPO'          => $id,
            'CEDULA_RESPONSABLE' => strtoupper(trim($request->CEDULA_RESPONSABLE)),
            'PERSONA_ASIGNADA'   => strtoupper(trim($request->PERSONA_ASIGNADA)),
            'FECHA_ASIGNACION'   => now(),
        ]);

        return response()->json(['success' => true, 'data' => $responsable]);
    }
}
