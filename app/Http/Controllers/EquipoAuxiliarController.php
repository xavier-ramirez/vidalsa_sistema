<?php

namespace App\Http\Controllers;

use App\Models\Equipo;
use App\Models\EquipoAuxiliar;
use App\Models\FrenteTrabajo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EquipoAuxiliarController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    // ═══════════════════════════════════════════════════════════
    // LISTADO
    // ═══════════════════════════════════════════════════════════
    public function index(Request $request)
    {
        $applyFilters = function ($q) use ($request) {
            if ($request->filled('tipo') && $request->tipo !== 'all') {
                $q->where('TIPO', $request->tipo);
            }
            if ($request->filled('id_frente') && $request->id_frente !== 'all') {
                $q->where('ID_FRENTE_ACTUAL', $request->id_frente);
            }
            if ($request->filled('estado') && $request->estado !== 'all') {
                $q->where('ESTADO_OPERATIVO', $request->estado);
            }
            // Filtros avanzados
            if ($request->filled('marca'))     $q->where('MARCA', 'like', '%' . trim($request->marca) . '%');
            if ($request->filled('modelo'))    $q->where('MODELO', 'like', '%' . trim($request->modelo) . '%');
            if ($request->filled('capacidad')) $q->where('CAPACIDAD', 'like', '%' . trim($request->capacidad) . '%');
            if ($request->filled('search')) {
                $s = trim($request->search);
                $q->where(function ($qq) use ($s) {
                    $qq->where('SERIAL', 'like', "%{$s}%")
                      ->orWhere('CODIGO_INTERNO', 'like', "%{$s}%")
                      ->orWhere('MARCA', 'like', "%{$s}%")
                      ->orWhere('MODELO', 'like', "%{$s}%");
                });
            }
        };

        // Flag: hay al menos un filtro activo. Si no hay, la tabla se muestra
        // vacia (patron de /admin/equipos) para evitar dump masivo de registros.
        $hasFilter = $request->filled('tipo') || $request->filled('id_frente')
                  || $request->filled('estado') || $request->filled('search')
                  || $request->filled('marca') || $request->filled('modelo')
                  || $request->filled('capacidad');

        $query = EquipoAuxiliar::with(['frente', 'equipoHost.documentacion']);
        $applyFilters($query);

        if ($hasFilter) {
            $auxiliares = $query->orderByDesc('created_at')->paginate(25)->withQueryString();
        } else {
            // Paginador vacio mantiene compatibilidad con ->links() en la vista.
            $auxiliares = new \Illuminate\Pagination\LengthAwarePaginator(
                collect([]), 0, 25, 1,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        }

        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE')->get();
        // TIPOS dinamicos: base del enum + los tipos custom guardados en DB.
        $tipos = $this->getTiposDinamicos();
        $estados = EquipoAuxiliar::estadosLabel();

        // Listas para los dropdowns de filtros avanzados
        $availableMarcas = EquipoAuxiliar::select('MARCA')
            ->whereNotNull('MARCA')->where('MARCA', '!=', '')
            ->distinct()->orderBy('MARCA')->pluck('MARCA');
        $availableModelos = EquipoAuxiliar::select('MODELO')
            ->whereNotNull('MODELO')->where('MODELO', '!=', '')
            ->distinct()->orderBy('MODELO')->pluck('MODELO');
        $availableCapacidades = EquipoAuxiliar::select('CAPACIDAD')
            ->whereNotNull('CAPACIDAD')->where('CAPACIDAD', '!=', '')
            ->distinct()->orderBy('CAPACIDAD')->pluck('CAPACIDAD');

        // Catalogo implicito de FOTO por MARCA|MODEL: si un auxiliar no tiene
        // FOTO propia, el partial cae a la de otro registro con el mismo modelo
        // (evita placeholders masivos cuando se registran sin foto individual).
        $photoByModel = EquipoAuxiliar::whereNotNull('FOTO')
            ->where('FOTO', '!=', '')
            ->select('MARCA', 'MODELO', 'FOTO')
            ->orderByDesc('ID_AUXILIAR')
            ->get()
            ->reduce(function ($carry, $a) {
                $key = mb_strtoupper(trim(($a->MARCA ?? '') . '|' . ($a->MODELO ?? '')));
                if ($key !== '|' && !isset($carry[$key])) $carry[$key] = $a->FOTO;
                return $carry;
            }, []);

        // Stats: total/operativos/inoperativos/mantenimiento respetando los filtros
        // activos excepto el propio filtro de estado (para mostrar el breakdown real).
        $statsBase = EquipoAuxiliar::query();
        if ($request->filled('tipo') && $request->tipo !== 'all')         $statsBase->where('TIPO', $request->tipo);
        if ($request->filled('id_frente') && $request->id_frente !== 'all')$statsBase->where('ID_FRENTE_ACTUAL', $request->id_frente);
        if ($request->filled('marca'))     $statsBase->where('MARCA', 'like', '%' . trim($request->marca) . '%');
        if ($request->filled('modelo'))    $statsBase->where('MODELO', 'like', '%' . trim($request->modelo) . '%');
        if ($request->filled('capacidad')) $statsBase->where('CAPACIDAD', 'like', '%' . trim($request->capacidad) . '%');
        if ($request->filled('search')) {
            $s = trim($request->search);
            $statsBase->where(function ($qq) use ($s) {
                $qq->where('SERIAL', 'like', "%{$s}%")
                  ->orWhere('CODIGO_INTERNO', 'like', "%{$s}%")
                  ->orWhere('MARCA', 'like', "%{$s}%")
                  ->orWhere('MODELO', 'like', "%{$s}%");
            });
        }
        $stats = [
            'total'         => (clone $statsBase)->count(),
            'operativos'    => (clone $statsBase)->where('ESTADO_OPERATIVO', 'OPERATIVO')->count(),
            'inoperativos'  => (clone $statsBase)->where('ESTADO_OPERATIVO', 'INOPERATIVO')->count(),
            'en_almacen'    => (clone $statsBase)->where('ESTADO_OPERATIVO', 'EN_ALMACEN')->count(),
        ];

        // Distribución por tipo (para el card sidebar inferior): conteo filtrado.
        $distribucion = (clone $statsBase)
            ->selectRaw('TIPO, COUNT(*) as total')
            ->groupBy('TIPO')
            ->orderByDesc('total')
            ->get();

        if ($request->wantsJson()) {
            return response()->json([
                'html'         => view('admin.equipos_auxiliares.partials.table_rows', compact('auxiliares', 'tipos', 'photoByModel'))->render(),
                'pagination'   => $auxiliares->links('vendor.pagination.custom-sliding')->toHtml(),
                'count'        => $auxiliares->total(),
                'stats'        => $stats,
                'distribucion' => $distribucion,
                'hasFilter'    => $hasFilter,
            ]);
        }

        return view('admin.equipos_auxiliares.index', compact(
            'auxiliares', 'frentes', 'tipos', 'estados', 'stats', 'distribucion', 'hasFilter', 'photoByModel',
            'availableMarcas', 'availableModelos', 'availableCapacidades'
        ));
    }

    /**
     * Exportar listado (XLSX) respetando los filtros activos.
     * Encabezado corporativo identico al resto de exportaciones: logo VIDALSA
     * a la izquierda, titulo + filtro activo al centro, metadatos (edicion,
     * revision, fecha) a la derecha, "Exportado por" en fila 4.
     */
    public function export(Request $request)
    {
        set_time_limit(180);
        $query = EquipoAuxiliar::with('frente');

        // Capturar filtros activos para reflejarlos en el titulo
        $tipoFiltro   = ($request->filled('tipo') && $request->tipo !== 'all') ? $request->tipo : null;
        $frenteFiltro = ($request->filled('id_frente') && $request->id_frente !== 'all') ? $request->id_frente : null;
        $estadoFiltro = ($request->filled('estado') && $request->estado !== 'all') ? $request->estado : null;

        if ($tipoFiltro)   $query->where('TIPO', $tipoFiltro);
        if ($frenteFiltro) $query->where('ID_FRENTE_ACTUAL', $frenteFiltro);
        if ($estadoFiltro) $query->where('ESTADO_OPERATIVO', $estadoFiltro);
        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($qq) use ($s) {
                $qq->where('SERIAL', 'like', "%{$s}%")->orWhere('CODIGO_INTERNO', 'like', "%{$s}%")
                  ->orWhere('MARCA', 'like', "%{$s}%")->orWhere('MODELO', 'like', "%{$s}%");
            });
        }

        $tipos   = $this->getTiposDinamicos();
        $estados = EquipoAuxiliar::estadosLabel();

        // Resolver nombres legibles de los filtros activos
        $nombreTipo = $tipoFiltro ? mb_strtoupper($tipos[$tipoFiltro] ?? $tipoFiltro) : 'TODOS';
        $nombreFrente = 'TODOS LOS FRENTES';
        if ($frenteFiltro) {
            $f = \App\Models\FrenteTrabajo::find($frenteFiltro);
            if ($f) $nombreFrente = mb_strtoupper($f->NOMBRE_FRENTE);
        }
        $currentDate = now()->format('d/m/Y');

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('Sistema de Gestión de Equipos Operacionales')
            ->setLastModifiedBy('Sistema de Gestión de Equipos Operacionales')
            ->setTitle('Listado de Equipos Auxiliares')
            ->setCompany('Constructora Vidalsa 27, C.A.');
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Equipos Auxiliares');

        $lastCol = 'I'; // 9 columnas

        // Logo corporativo (A1:B3)
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
                $drawing->setHeight(135);
                $drawing->setWorksheet($sheet);
            } catch (\Exception $e) {
                // silently ignore
            }
        }
        $sheet->mergeCells('A1:B3');
        $sheet->getStyle('A1:B3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        // Titulo central con filtros aplicados (C1:E3)
        $partes = [];
        if ($frenteFiltro) $partes[] = 'FRENTE: ' . $nombreFrente;
        if ($tipoFiltro)   $partes[] = 'TIPO: '   . $nombreTipo;
        $subTitle = $partes ? implode(' — ', $partes) : 'COPIA DE BASE DE DATOS DEL SISTEMA DE GESTION DE EQUIPOS OPERACIONALES';
        $titleText = "LISTADO DE EQUIPOS AUXILIARES\n" . $subTitle;
        $sheet->mergeCells('C1:E3');
        $sheet->setCellValue('C1', $titleText);
        $sheet->getStyle('C1')->getAlignment()->setWrapText(true)->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('C1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $sheet->getStyle('C1:E3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        // Metadatos a la derecha (F1:I3)
        $meta = [
            ['F1', 'EDICION: 1'],
            ['F2', 'REVISION: 0'],
            ['F3', 'FECHA: ' . $currentDate],
        ];
        foreach ($meta as [$cell, $text]) {
            $row = substr($cell, 1);
            $sheet->mergeCells("F{$row}:{$lastCol}{$row}");
            $sheet->setCellValue($cell, $text);
            $sheet->getStyle($cell)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $sheet->getStyle($cell)->getFont()->setBold(true)->setSize(11)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
            $sheet->getStyle("F{$row}:{$lastCol}{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        }

        $sheet->getRowDimension(1)->setRowHeight(40);
        $sheet->getRowDimension(2)->setRowHeight(40);
        $sheet->getRowDimension(3)->setRowHeight(40);

        // Fila 4 - Exportado por
        $sheet->mergeCells("A4:{$lastCol}4");
        $sheet->setCellValue('A4', 'Exportado por: Sistema de Gestión de Equipos Operacionales');
        $sheet->getStyle("A4:{$lastCol}4")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A4:{$lastCol}4")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A4:{$lastCol}4")->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF333333');
        $sheet->getRowDimension(4)->setRowHeight(20);

        // Bordes al encabezado
        $sheet->getStyle("A1:{$lastCol}4")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'],
                ],
            ],
        ]);

        // Fila 5 - Headers de tabla
        $headers = ['TIPO', 'MARCA', 'MODELO', 'SERIAL', 'CÓDIGO INTERNO', 'CAPACIDAD', 'AÑO', 'FRENTE', 'ESTADO'];
        $sheet->fromArray($headers, null, 'A5');
        $sheet->getStyle("A5:{$lastCol}5")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle("A5:{$lastCol}5")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF1E293B');
        $sheet->getStyle("A5:{$lastCol}5")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension(5)->setRowHeight(22);

        // Filas de data
        $row = 6;
        $query->orderBy('TIPO')->chunk(300, function ($rows) use ($sheet, $tipos, $estados, &$row) {
            foreach ($rows as $r) {
                $sheet->setCellValue("A{$row}", mb_strtoupper($tipos[$r->TIPO] ?? $r->TIPO));
                $sheet->setCellValue("B{$row}", $r->MARCA);
                $sheet->setCellValue("C{$row}", $r->MODELO);
                $sheet->setCellValue("D{$row}", $r->SERIAL);
                $sheet->setCellValue("E{$row}", $r->CODIGO_INTERNO);
                $sheet->setCellValue("F{$row}", $r->CAPACIDAD);
                $sheet->setCellValue("G{$row}", $r->ANIO);
                $sheet->setCellValue("H{$row}", optional($r->frente)->NOMBRE_FRENTE);
                $sheet->setCellValue("I{$row}", mb_strtoupper($estados[$r->ESTADO_OPERATIVO] ?? $r->ESTADO_OPERATIVO));
                $row++;
            }
        });

        if ($row > 6) {
            $sheet->getStyle("A5:{$lastCol}" . ($row - 1))->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
                ->getColor()->setARGB('FFCBD5E0');
        }
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'Listado_Equipos_Auxiliares_' . date('Y-m-d_H-i') . '.xlsx';

        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'max-age=0');
        return $response;
    }

    public function count()
    {
        return response()->json(['total' => EquipoAuxiliar::count()]);
    }

    /**
     * Detalles completos del auxiliar (para modal de "Ver detalles").
     */
    public function details($id)
    {
        $aux = EquipoAuxiliar::with([
            'frente',
            'equipoHost.documentacion',
            'equipoHost.tipo',
            'equipoHost.especificaciones',
            'equipoHost.frenteActual',
            'creador',
        ])->findOrFail($id);
        $tiposMap = $this->getTiposDinamicos();

        // Foto del host: prioriza FOTO_REFERENCIAL del catalogo del modelo,
        // cae a FOTO_EQUIPO propia.
        $hostFoto = null;
        if ($aux->equipoHost) {
            if ($aux->equipoHost->especificaciones && $aux->equipoHost->especificaciones->FOTO_REFERENCIAL) {
                $hostFoto = asset($aux->equipoHost->especificaciones->FOTO_REFERENCIAL);
            } elseif ($aux->equipoHost->FOTO_EQUIPO) {
                $hostFoto = asset($aux->equipoHost->FOTO_EQUIPO);
            }
        }

        return response()->json([
            'id'             => $aux->ID_AUXILIAR,
            'tipo'           => $aux->TIPO,
            'tipo_label'     => $tiposMap[$aux->TIPO] ?? $aux->TIPO,
            'marca'          => $aux->MARCA,
            'modelo'         => $aux->MODELO,
            'serial'         => $aux->SERIAL,
            'codigo_interno' => $aux->CODIGO_INTERNO,
            'capacidad'      => $aux->CAPACIDAD,
            'anio'           => $aux->ANIO,
            'estado'         => $aux->ESTADO_OPERATIVO,
            'estado_label'   => EquipoAuxiliar::estadosLabel()[$aux->ESTADO_OPERATIVO] ?? $aux->ESTADO_OPERATIVO,
            'observaciones'  => $aux->OBSERVACIONES,
            'foto'           => $aux->FOTO,
            'foto_drive_id'  => $aux->FOTO ? basename(str_replace('/storage/google/', '', $aux->FOTO)) : null,
            'link_doc_propiedad'     => $aux->LINK_DOC_PROPIEDAD ?? null,
            'link_certificado'       => $aux->LINK_CERTIFICADO ?? null,
            'fecha_vencimiento_cert' => $aux->FECHA_VENCIMIENTO_CERT ?? null,
            'frente'         => optional($aux->frente)->NOMBRE_FRENTE,
            // Host (equipo vinculado)
            'host_id'            => $aux->ID_EQUIPO_HOST,
            'host_codigo'        => optional($aux->equipoHost)->CODIGO_PATIO,
            'host_placa'         => optional(optional($aux->equipoHost)->documentacion)->PLACA,
            'host_serial_chasis' => optional($aux->equipoHost)->SERIAL_CHASIS,
            'host_tipo'          => optional(optional($aux->equipoHost)->tipo)->nombre,
            'host_marca'         => optional($aux->equipoHost)->MARCA,
            'host_modelo'        => optional($aux->equipoHost)->MODELO,
            'host_foto'          => $hostFoto,
            'host_frente'        => optional(optional($aux->equipoHost)->frenteActual)->NOMBRE_FRENTE,
            // Auditoria
            'creado_por'     => optional($aux->creador)->NOMBRE_COMPLETO,
            'created_at'     => optional($aux->created_at)->format('d/m/Y H:i'),
            'edit_url'       => route('equipos-auxiliares.edit', $aux->ID_AUXILIAR),
            // Permisos del usuario actual para que el frontend renderice
            // condicionalmente los botones de accion del modal.
            'can_edit'       => auth()->user() && auth()->user()->can('equipos.edit'),
            'can_assign'     => auth()->user() && auth()->user()->can('equipos.assign'),
            // Upload/eliminacion de PDFs requiere user.edit (mismo permiso que el
            // PDF preview modal de /admin/equipos via window.CAN_UPDATE_INFO).
            'can_upload_pdf' => auth()->user() && auth()->user()->can('user.edit'),
        ]);
    }

    /**
     * Lista los equipos auxiliares anclados a un equipo host especifico.
     * Usado por el modal de detalles de /admin/equipos para mostrar los
     * auxiliares en la seccion "Sub-activos vinculados".
     */
    public function byHost($hostId)
    {
        $auxiliares = EquipoAuxiliar::where('ID_EQUIPO_HOST', $hostId)
            ->orderBy('TIPO')
            ->get()
            ->map(function ($a) {
                return [
                    'id'        => $a->ID_AUXILIAR,
                    'tipo'      => $a->TIPO,
                    'serial'    => $a->SERIAL,
                    'marca'     => $a->MARCA,
                    'modelo'    => $a->MODELO,
                    'capacidad' => $a->CAPACIDAD,
                    'anio'      => $a->ANIO,
                    'estado'    => $a->ESTADO_OPERATIVO,
                ];
            });

        return response()->json(['ok' => true, 'data' => $auxiliares]);
    }

    // ═══════════════════════════════════════════════════════════
    // CRUD
    // ═══════════════════════════════════════════════════════════
    public function create()
    {
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE')->get();
        // TIPOS dinamicos: base del enum + los tipos custom guardados en DB.
        $tipos = $this->getTiposDinamicos();
        $estados = EquipoAuxiliar::estadosLabel();
        $auxiliar = new EquipoAuxiliar();
        return view('admin.equipos_auxiliares.create', compact('auxiliar', 'frentes', 'tipos', 'estados'));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        $data['CREADO_POR'] = auth()->id();
        // Todos los campos de texto (select o input) se guardan en MAYUSCULAS
        // para consistencia (reportes, busquedas, filtros case-insensitive).
        foreach (['MARCA', 'MODELO', 'SERIAL', 'CODIGO_INTERNO', 'CAPACIDAD', 'OBSERVACIONES'] as $f) {
            if (!empty($data[$f])) $data[$f] = mb_strtoupper(trim($data[$f]));
        }

        // Remover claves de archivos del array antes de create (Eloquent no sabe manejarlos).
        unset($data['doc_propiedad'], $data['certificado']);
        // Normalizar nombre de campo fecha.
        if (!empty($data['fecha_vencimiento_cert'])) {
            $data['FECHA_VENCIMIENTO_CERT'] = $data['fecha_vencimiento_cert'];
        }
        unset($data['fecha_vencimiento_cert']);

        $auxiliar = EquipoAuxiliar::create($data);

        // Guardar archivos PDF (si vinieron) en storage/app/public/equipos_auxiliares/{id}/
        $this->storeAuxDocs($request, $auxiliar);

        if ($request->wantsJson()) {
            return response()->json([
                'success'  => true,
                'message'  => 'Equipo auxiliar registrado correctamente.',
                'redirect' => route('equipos-auxiliares.index'),
            ]);
        }
        return redirect()->route('equipos-auxiliares.index')->with('success', 'Equipo auxiliar registrado correctamente.');
    }

    public function edit($id)
    {
        $auxiliar = EquipoAuxiliar::findOrFail($id);
        $frentes  = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE')->get();
        // TIPOS dinamicos: base del enum + los tipos custom guardados en DB.
        $tipos    = $this->getTiposDinamicos();
        $estados  = EquipoAuxiliar::estadosLabel();
        return view('admin.equipos_auxiliares.edit', compact('auxiliar', 'frentes', 'tipos', 'estados'));
    }

    /**
     * Combina los TIPOS del enum (con labels bonitos) con los tipos custom
     * que el usuario haya registrado (existentes en la BD). Para los custom,
     * genera un label legible a partir del codigo (ej. "GENERADOR_DIESEL" ->
     * "Generador Diesel"). Asi el filtro y los comboboxes reflejan siempre
     * el estado real de tipos en uso, no una lista hardcoded.
     */
    private function getTiposDinamicos(): array
    {
        $tipos = EquipoAuxiliar::tiposLabel();
        $tiposEnDB = EquipoAuxiliar::select('TIPO')
            ->whereNotNull('TIPO')->where('TIPO', '!=', '')
            ->distinct()->orderBy('TIPO')->pluck('TIPO');
        foreach ($tiposEnDB as $t) {
            if (!isset($tipos[$t])) {
                $tipos[$t] = ucwords(mb_strtolower(str_replace('_', ' ', $t)));
            }
        }
        return $tipos;
    }

    public function update(Request $request, $id)
    {
        $auxiliar = EquipoAuxiliar::findOrFail($id);
        $data = $this->validateData($request, false);

        // Todos los campos de texto se normalizan a MAYUSCULAS (consistencia
        // con store y con el resto de la app).
        foreach (['MARCA', 'MODELO', 'SERIAL', 'CODIGO_INTERNO', 'CAPACIDAD', 'OBSERVACIONES'] as $f) {
            if (!empty($data[$f])) $data[$f] = mb_strtoupper(trim($data[$f]));
        }

        unset($data['doc_propiedad'], $data['certificado']);
        if (array_key_exists('fecha_vencimiento_cert', $data)) {
            $data['FECHA_VENCIMIENTO_CERT'] = $data['fecha_vencimiento_cert'] ?: null;
            unset($data['fecha_vencimiento_cert']);
        }

        $auxiliar->update($data);

        $this->storeAuxDocs($request, $auxiliar);

        if ($request->wantsJson()) {
            return response()->json([
                'success'  => true,
                'message'  => 'Equipo auxiliar actualizado correctamente.',
                'redirect' => route('equipos-auxiliares.index'),
            ]);
        }
        return redirect()->route('equipos-auxiliares.index')->with('success', 'Equipo auxiliar actualizado correctamente.');
    }

    public function destroy(Request $request, $id)
    {
        $auxiliar = EquipoAuxiliar::findOrFail($id);
        $auxiliar->delete();

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }
        return redirect()->route('equipos-auxiliares.index')->with('success', 'Equipo auxiliar eliminado.');
    }

    // ═══════════════════════════════════════════════════════════
    // ANCHOR 1:N (tope 2 auxiliares por equipo host)
    // ═══════════════════════════════════════════════════════════
    public function anchor(Request $request, $id)
    {
        $request->validate([
            'id_equipo_host' => 'required|exists:equipos,ID_EQUIPO',
        ]);

        try {
            DB::transaction(function () use ($request, $id) {
                $auxiliar = EquipoAuxiliar::lockForUpdate()->findOrFail($id);
                $hostId   = $request->id_equipo_host;

                // Verificar tope: no mas de N auxiliares por equipo host (excluyendo este mismo)
                $actuales = EquipoAuxiliar::where('ID_EQUIPO_HOST', $hostId)
                    ->where('ID_AUXILIAR', '!=', $id)
                    ->lockForUpdate()
                    ->count();

                if ($actuales >= EquipoAuxiliar::ANCHOR_MAX_PER_HOST) {
                    throw new \RuntimeException(
                        'El equipo host ya tiene el maximo permitido de ' .
                        EquipoAuxiliar::ANCHOR_MAX_PER_HOST . ' auxiliares anclados.'
                    );
                }

                $auxiliar->update(['ID_EQUIPO_HOST' => $hostId]);
            });

            return response()->json(['success' => true, 'message' => 'Equipo auxiliar anclado correctamente.']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('EquipoAuxiliar anchor falló: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al anclar el equipo.'], 500);
        }
    }

    public function unanchor($id)
    {
        $auxiliar = EquipoAuxiliar::findOrFail($id);
        $auxiliar->update(['ID_EQUIPO_HOST' => null]);
        return response()->json(['success' => true, 'message' => 'Equipo auxiliar desanclado.']);
    }

    /**
     * Movilizacion masiva: reasigna multiples auxiliares al mismo ID_FRENTE_ACTUAL.
     * Acepta array de IDs + frente destino (o creacion de frente nuevo via NOMBRE).
     */
    public function bulkMove(Request $request)
    {
        $data = $request->validate([
            'ids'            => 'required|array|min:1',
            'ids.*'          => 'integer|exists:equipos_auxiliares,ID_AUXILIAR',
            'id_frente'      => 'nullable|exists:frentes_trabajo,ID_FRENTE',
            'nombre_frente'  => 'nullable|string|max:100',
        ]);

        // Si viene nombre_frente sin id_frente, intentar match o crear nuevo.
        $frenteId = $data['id_frente'] ?? null;
        if (!$frenteId && !empty($data['nombre_frente'])) {
            $nombre = mb_strtoupper(trim($data['nombre_frente']));
            $frente = FrenteTrabajo::whereRaw('UPPER(NOMBRE_FRENTE) = ?', [$nombre])->first();
            if ($frente) {
                $frenteId = $frente->ID_FRENTE;
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'El frente destino no existe. Crealo primero en /admin/frentes.'
                ], 422);
            }
        }

        if (!$frenteId) {
            return response()->json(['success' => false, 'message' => 'Frente destino requerido.'], 422);
        }

        $affected = EquipoAuxiliar::whereIn('ID_AUXILIAR', $data['ids'])
            ->update(['ID_FRENTE_ACTUAL' => $frenteId]);

        return response()->json([
            'success'  => true,
            'message'  => "Se movilizaron {$affected} equipo(s) auxiliar(es) al frente destino.",
            'affected' => $affected,
        ]);
    }

    /**
     * Cambio rapido de estado operativo (inline desde la tabla del index).
     * Validacion minima: solo ESTADO_OPERATIVO. No toca otros campos required.
     */
    public function changeStatus(Request $request, $id)
    {
        $estados = array_keys(EquipoAuxiliar::estadosLabel());
        $request->validate([
            'ESTADO_OPERATIVO' => 'required|string|in:' . implode(',', $estados),
        ]);

        $aux = EquipoAuxiliar::findOrFail($id);
        $aux->ESTADO_OPERATIVO = $request->input('ESTADO_OPERATIVO');
        $aux->save();

        return response()->json([
            'success' => true,
            'message' => 'Estado actualizado.',
            'estado'  => $aux->ESTADO_OPERATIVO,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // SEARCH — autocomplete para anclaje
    // ═══════════════════════════════════════════════════════════
    public function search(Request $request)
    {
        $q = trim($request->get('q', ''));
        if (strlen($q) < 2) return response()->json([]);

        $tiposMap = $this->getTiposDinamicos();
        $results = EquipoAuxiliar::with('equipoHost.documentacion')
            ->where(function ($w) use ($q) {
                $w->where('SERIAL', 'like', "%{$q}%")
                  ->orWhere('CODIGO_INTERNO', 'like', "%{$q}%")
                  ->orWhere('MARCA', 'like', "%{$q}%")
                  ->orWhere('MODELO', 'like', "%{$q}%");
            })
            ->limit(20)
            ->get()
            ->map(function ($a) use ($tiposMap) {
                return [
                    'id'          => $a->ID_AUXILIAR,
                    'tipo'        => $a->TIPO,
                    'tipo_label'  => $tiposMap[$a->TIPO] ?? $a->TIPO,
                    'marca'       => $a->MARCA,
                    'modelo'      => $a->MODELO,
                    'serial'      => $a->SERIAL,
                    'host_id'     => $a->ID_EQUIPO_HOST,
                    'host_codigo' => optional($a->equipoHost)->CODIGO_PATIO,
                    'host_placa'  => optional(optional($a->equipoHost)->documentacion)->PLACA,
                ];
            });

        return response()->json($results);
    }

    public function searchHosts(Request $request)
    {
        $q = trim($request->get('q', ''));
        if (strlen($q) < 2) return response()->json([]);

        // Busqueda ampliada: serial chasis, serial motor, placa (docum.),
        // codigo patio, marca y modelo. Join con documentacion para PLACA.
        $results = Equipo::with('documentacion', 'tipo', 'equiposAuxiliares', 'especificaciones')
            ->leftJoin('documentacion as doc_host', 'equipos.ID_EQUIPO', '=', 'doc_host.ID_EQUIPO')
            ->select('equipos.*')
            ->where(function ($w) use ($q) {
                $w->where('equipos.CODIGO_PATIO', 'like', "%{$q}%")
                  ->orWhere('equipos.SERIAL_CHASIS', 'like', "%{$q}%")
                  ->orWhere('equipos.SERIAL_DE_MOTOR', 'like', "%{$q}%")
                  ->orWhere('equipos.MARCA', 'like', "%{$q}%")
                  ->orWhere('equipos.MODELO', 'like', "%{$q}%")
                  ->orWhere('doc_host.PLACA', 'like', "%{$q}%");
            })
            ->distinct()
            ->limit(20)
            ->get()
            ->map(function ($e) {
                // Foto: prioriza la del catalogo del modelo (FOTO_REFERENCIAL),
                // cae a la propia del equipo (FOTO_EQUIPO).
                $foto = null;
                if ($e->especificaciones && $e->especificaciones->FOTO_REFERENCIAL) {
                    $foto = asset($e->especificaciones->FOTO_REFERENCIAL);
                } elseif ($e->FOTO_EQUIPO) {
                    $foto = asset($e->FOTO_EQUIPO);
                }
                return [
                    'id'             => $e->ID_EQUIPO,
                    'codigo'         => $e->CODIGO_PATIO,
                    'placa'          => optional($e->documentacion)->PLACA,
                    'serial_chasis'  => $e->SERIAL_CHASIS,
                    'serial_motor'   => $e->SERIAL_DE_MOTOR,
                    'tipo'           => optional($e->tipo)->nombre,
                    'marca'          => $e->MARCA,
                    'modelo'         => $e->MODELO,
                    'marca_modelo'   => trim(($e->MARCA ?? '') . ' ' . ($e->MODELO ?? '')),
                    'foto'           => $foto,
                    'auxiliares_anclados' => $e->equiposAuxiliares->count(),
                    'disponible'     => $e->equiposAuxiliares->count() < EquipoAuxiliar::ANCHOR_MAX_PER_HOST,
                ];
            });

        return response()->json($results);
    }


    /**
     * Sube/reemplaza un PDF puntual desde el modal de detalles.
     * Endpoint: POST /admin/equipos-auxiliares/{id}/upload-doc
     * Permiso: equipos.edit (gateado en routes/web.php).
     * doc_type: propiedad | certificado
     */
    public function uploadDoc(Request $request, $id)
    {
        $request->validate([
            'file'     => 'required|file|mimes:pdf|max:51200',
            'doc_type' => 'required|in:propiedad,certificado',
            'fecha_vencimiento_cert' => 'nullable|date',
        ]);

        $aux  = EquipoAuxiliar::findOrFail($id);
        $type = $request->input('doc_type');
        $file = $request->file('file');
        $name = $type . '_' . time() . '.pdf';
        $path = $file->storeAs('equipos_auxiliares/' . $aux->ID_AUXILIAR, $name, 'public');

        if ($type === 'propiedad') {
            $aux->LINK_DOC_PROPIEDAD = '/storage/' . $path;
        } else {
            $aux->LINK_CERTIFICADO = '/storage/' . $path;
            if ($request->filled('fecha_vencimiento_cert')) {
                $aux->FECHA_VENCIMIENTO_CERT = $request->input('fecha_vencimiento_cert');
            }
        }
        $aux->save();

        return response()->json([
            'success' => true,
            'message' => 'PDF cargado correctamente.',
            'link'    => $type === 'propiedad' ? $aux->LINK_DOC_PROPIEDAD : $aux->LINK_CERTIFICADO,
            'fecha_vencimiento_cert' => $aux->FECHA_VENCIMIENTO_CERT,
        ]);
    }

    /**
     * Guarda (y reemplaza) los PDFs de documentacion del auxiliar en
     * storage/app/public/equipos_auxiliares/{id}/. Actualiza las
     * columnas LINK_DOC_PROPIEDAD / LINK_CERTIFICADO. Idempotente:
     * si no vienen archivos, no toca nada.
     */
    private function storeAuxDocs(Request $request, EquipoAuxiliar $aux): void
    {
        $updates = [];

        if ($request->hasFile('doc_propiedad') && $request->file('doc_propiedad')->isValid()) {
            $file = $request->file('doc_propiedad');
            $name = 'propiedad_' . time() . '.pdf';
            $path = $file->storeAs('equipos_auxiliares/' . $aux->ID_AUXILIAR, $name, 'public');
            $updates['LINK_DOC_PROPIEDAD'] = '/storage/' . $path;
        }

        if ($request->hasFile('certificado') && $request->file('certificado')->isValid()) {
            $file = $request->file('certificado');
            $name = 'certificado_' . time() . '.pdf';
            $path = $file->storeAs('equipos_auxiliares/' . $aux->ID_AUXILIAR, $name, 'public');
            $updates['LINK_CERTIFICADO'] = '/storage/' . $path;
        }

        if (!empty($updates)) {
            $aux->update($updates);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // VALIDATION
    // ═══════════════════════════════════════════════════════════
    private function validateData(Request $request, bool $isCreate = true): array
    {
        // ID del auxiliar actual (para excluirlo del check unique en update)
        $currentId = $request->route('id');

        // Normalizar ANTES de validar: uppercase + trim en campos donde
        // guardamos uppercase, para que el check unique compare consistente
        // (sino "ms-01" pasa unique aunque la BD tenga "MS-01" y al
        // guardar con strtoupper se crearia un duplicado logico).
        foreach (['SERIAL', 'CODIGO_INTERNO', 'MARCA', 'MODELO', 'CAPACIDAD'] as $f) {
            if ($request->filled($f)) {
                $request->merge([$f => mb_strtoupper(trim($request->input($f)))]);
            }
        }

        // TIPO: si el usuario seleccionó una etiqueta legible del datalist
        // (ej. "Maquina de Soldar"), mapearla al codigo enum correspondiente
        // (MAQUINA_SOLDAR) para preservar la consistencia con los registros
        // existentes. Comparacion case-insensitive. Si no hay match, se
        // normaliza como tipo custom (uppercase + underscores).
        if ($request->filled('TIPO')) {
            $input = trim($request->input('TIPO'));
            $labels = EquipoAuxiliar::tiposLabel(); // [code => label]
            $code = null;
            foreach ($labels as $k => $label) {
                if (mb_strtolower($label) === mb_strtolower($input)) { $code = $k; break; }
            }
            $request->merge(['TIPO' => $code ?? mb_strtoupper(preg_replace('/\s+/', '_', $input))]);
        }

        // TIPO y ESTADO_OPERATIVO son SIEMPRE requeridos, tanto en create como
        // en update: representan estado fundamental del registro. Si dejamos
        // 'sometimes|required|...' en update permitiria pasar string vacio via
        // JSON y el required no dispara. Mejor mantenerlos fuera del sometimes.
        // SERIAL: unique ignorando self en update (cuando esta presente).
        $rules = [
            'TIPO'             => 'required|string|max:30',
            'ESTADO_OPERATIVO' => 'required|string|in:' . implode(',', array_keys(EquipoAuxiliar::estadosLabel())),
            'MARCA'            => 'required|string|max:80',
            'MODELO'           => 'required|string|max:80',
            'SERIAL'           => 'required|string|max:100|unique:equipos_auxiliares,SERIAL' . ($currentId ? ',' . $currentId . ',ID_AUXILIAR' : ''),
            'CODIGO_INTERNO'   => 'nullable|string|max:50|unique:equipos_auxiliares,CODIGO_INTERNO' . ($currentId ? ',' . $currentId . ',ID_AUXILIAR' : ''),
            'CAPACIDAD'        => 'nullable|string|max:80',
            'ANIO'             => 'nullable|integer|min:1950|max:2100',
            'ID_FRENTE_ACTUAL' => 'nullable|exists:frentes_trabajo,ID_FRENTE',
            'ID_EQUIPO_HOST'   => 'nullable|exists:equipos,ID_EQUIPO',
            'OBSERVACIONES'    => 'nullable|string|max:500',
            // Documentacion (opcional). En UPDATE aceptamos fecha pasada para no
            // bloquear edicion de registros con certificados ya vencidos.
            'doc_propiedad'          => 'nullable|file|mimes:pdf|max:10240',
            'certificado'            => 'nullable|file|mimes:pdf|max:10240',
            'fecha_vencimiento_cert' => $isCreate ? 'nullable|date|after_or_equal:today' : 'nullable|date',
        ];

        // En update hacemos sometimes SOLO los nullable; required se mantiene.
        if (!$isCreate) {
            foreach ($rules as $k => $v) {
                if (strpos($v, 'nullable') !== false) {
                    $rules[$k] = 'sometimes|' . $v;
                }
            }
        }

        $validated = $request->validate($rules, [
            'SERIAL.unique'         => 'El serial ingresado ya está registrado en otro equipo auxiliar.',
            'CODIGO_INTERNO.unique' => 'El código interno ingresado ya está registrado en otro equipo auxiliar.',
        ]);

        // Normaliza TIPO: uppercase + espacios por guiones_bajos para mantener consistencia
        // con los codigos existentes (MAQUINA_SOLDAR, etc.) cuando el usuario escribe uno nuevo.
        if (isset($validated['TIPO'])) {
            $validated['TIPO'] = mb_strtoupper(preg_replace('/\s+/', '_', trim($validated['TIPO'])));
        }

        // Proteger tope ANCHOR_MAX_PER_HOST en create/update: si ID_EQUIPO_HOST
        // viene seteado y el host ya tiene N auxiliares distintos al actual,
        // rechazar. El endpoint anchor() tambien lo valida con lockForUpdate,
        // pero validar aqui tambien protege el set directo via form.
        if (!empty($validated['ID_EQUIPO_HOST'])) {
            $auxiliarId = $request->route('id');
            $existentes = EquipoAuxiliar::where('ID_EQUIPO_HOST', $validated['ID_EQUIPO_HOST'])
                ->when($auxiliarId, fn($q) => $q->where('ID_AUXILIAR', '!=', $auxiliarId))
                ->count();
            if ($existentes >= EquipoAuxiliar::ANCHOR_MAX_PER_HOST) {
                abort(422, 'El equipo host ya tiene el maximo de ' . EquipoAuxiliar::ANCHOR_MAX_PER_HOST . ' auxiliares anclados.');
            }
        }

        return $validated;
    }

    // ═══════════════════════════════════════════════════════════
    // CARGA MASIVA (Excel) — patron identico a /admin/equipos
    // ═══════════════════════════════════════════════════════════
    /**
     * Headers canonicos de la plantilla. El orden es vinculante: lo usa tanto
     * el generador (bulkTemplate) como el parser (bulkPreview) para validar.
     */
    private function bulkHeaderKeys(): array
    {
        return ['tipo', 'marca', 'modelo', 'serial', 'capacidad', 'año', 'frente de trabajo', 'estado'];
    }

    private function bulkHeaderLabels(): array
    {
        return ['Tipo', 'Marca', 'Modelo', 'Serial', 'Capacidad', 'Año', 'Frente de Trabajo', 'Estado'];
    }

    /**
     * Descarga plantilla XLSX para bulk upload. Incluye hoja oculta "_listas"
     * con data validation (dropdowns) para Tipo/Frente/Estado. Tipos custom
     * son permitidos (validation soft): el Excel muestra sugerencias pero no
     * bloquea escribir uno nuevo — se crea al guardar.
     */
    public function bulkTemplate(Request $request)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()->setCreator('Vidalsa')->setTitle('Plantilla Bulk Equipos Auxiliares');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Auxiliares');
        $sheet->fromArray([$this->bulkHeaderLabels()], null, 'A1');

        $sheet->getStyle('A1:H1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0067B1']],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FF000000']]],
        ]);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:H1');

        $colWidths = ['A' => 22, 'B' => 16, 'C' => 16, 'D' => 18, 'E' => 14, 'F' => 8, 'G' => 25, 'H' => 16];
        foreach ($colWidths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        // Hoja oculta con listas para dropdowns
        $listSheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, '_listas');
        $spreadsheet->addSheet($listSheet);

        $tiposArr   = array_values(array_map(fn($l) => mb_strtoupper($l), $this->getTiposDinamicos()));
        $frentesArr = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE')->pluck('NOMBRE_FRENTE')->toArray();
        $estadosArr = array_keys(EquipoAuxiliar::estadosLabel());

        $listSheet->fromArray([['Tipos']], null, 'A1');
        $listSheet->fromArray(array_map(fn($v) => [$v], $tiposArr), null, 'A2');
        $listSheet->fromArray([['Frentes']], null, 'B1');
        $listSheet->fromArray(array_map(fn($v) => [$v], $frentesArr), null, 'B2');
        $listSheet->fromArray([['Estados']], null, 'C1');
        $listSheet->fromArray(array_map(fn($v) => [$v], $estadosArr), null, 'C2');
        $listSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);

        $addListValidation = function (string $column, string $formula, bool $soft = false, string $prompt = '') use ($sheet) {
            $v = $sheet->getCell($column . '2')->getDataValidation();
            $v->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
            $v->setErrorStyle($soft
                ? \PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_INFORMATION
                : \PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
            $v->setAllowBlank(true);
            $v->setShowInputMessage(true);
            $v->setShowErrorMessage(!$soft);
            $v->setShowDropDown(true);
            if ($soft && $prompt) {
                $v->setPromptTitle('Tipo');
                $v->setPrompt($prompt);
            } else {
                $v->setErrorTitle('Valor no permitido');
                $v->setError('Selecciona un valor de la lista.');
            }
            $v->setFormula1($formula);
            $v->setSqref($column . '2:' . $column . '1001');
        };

        if (count($tiposArr) > 0) {
            $addListValidation('A', '_listas!$A$2:$A$' . (count($tiposArr) + 1), true, 'Selecciona de la lista o escribe uno nuevo (se creara al guardar).');
        }
        if (count($frentesArr) > 0) {
            $addListValidation('G', '_listas!$B$2:$B$' . (count($frentesArr) + 1));
        }
        $addListValidation('H', '_listas!$C$2:$C$' . (count($estadosArr) + 1));

        $spreadsheet->setActiveSheetIndex(0);
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);

        $filename = 'plantilla_equipos_auxiliares_' . now()->format('Y-m-d') . '.xlsx';
        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        return $response;
    }

    /**
     * Parsea el XLSX y devuelve JSON con filas + errores por fila. No crea
     * nada en BD. Resuelve TIPO/FRENTE a code/id para que el frontend edite
     * con selects y luego mande el batch final limpio.
     */
    public function bulkPreview(Request $request)
    {
        $request->validate([
            'archivo_excel' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        $path = $request->file('archivo_excel')->getRealPath();
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Auxiliares') ?? $spreadsheet->getActiveSheet();

        // Headers
        $expected = $this->bulkHeaderKeys();
        $actual = [];
        foreach (range('A', 'H') as $col) {
            $actual[] = mb_strtolower(trim((string) $sheet->getCell($col . '1')->getValue()));
        }
        if ($actual !== $expected) {
            return response()->json([
                'success' => false,
                'message' => 'Headers invalidos. Descarga la plantilla nuevamente.',
            ], 422);
        }

        $highestRow = $sheet->getHighestDataRow();
        if ($highestRow - 1 > 500) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo supera 500 filas de datos.',
            ], 422);
        }

        // Lookups en memoria (1 llamada c/u, no por fila)
        $tiposMap = $this->getTiposDinamicos();            // [CODE => Label]
        $tiposByCodeLower  = [];
        $tiposByLabelLower = [];
        foreach ($tiposMap as $code => $label) {
            $tiposByCodeLower[mb_strtolower($code)]   = $code;
            $tiposByLabelLower[mb_strtolower($label)] = $code;
        }
        $frentesMap = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->orderBy('NOMBRE_FRENTE')
            ->get()
            ->keyBy(fn($f) => mb_strtolower(trim($f->NOMBRE_FRENTE)));
        $validEstados = array_keys(EquipoAuxiliar::estadosLabel());

        // Pre-scan para detectar duplicados en archivo y contra BD
        $allSeriales = [];
        for ($n = 2; $n <= $highestRow; $n++) {
            $s = mb_strtoupper(trim((string) $sheet->getCell('D' . $n)->getValue()));
            if ($s !== '') $allSeriales[] = $s;
        }
        $duplicateSeriales = array_keys(array_filter(array_count_values($allSeriales), fn($c) => $c > 1));
        $existingSerialesBD = !empty($allSeriales)
            ? DB::table('equipos_auxiliares')
                ->whereIn(DB::raw('UPPER(SERIAL)'), $allSeriales)
                ->pluck('SERIAL')->map(fn($v) => mb_strtoupper($v))->toArray()
            : [];

        $rows = [];
        for ($n = 2; $n <= $highestRow; $n++) {
            $rawTipo    = trim((string) $sheet->getCell('A' . $n)->getValue());
            $rawMarca   = trim((string) $sheet->getCell('B' . $n)->getValue());
            $rawModelo  = trim((string) $sheet->getCell('C' . $n)->getValue());
            $rawSerial  = trim((string) $sheet->getCell('D' . $n)->getValue());
            $rawCap     = trim((string) $sheet->getCell('E' . $n)->getValue());
            $rawAnio    = $sheet->getCell('F' . $n)->getValue();
            $rawFrente  = trim((string) $sheet->getCell('G' . $n)->getValue());
            $rawEstado  = trim((string) $sheet->getCell('H' . $n)->getValue());

            // Skip filas vacias
            if ($rawTipo === '' && $rawMarca === '' && $rawModelo === '' && $rawSerial === '') continue;

            $errors = [];
            $serialUpper = mb_strtoupper($rawSerial);
            $estadoUpper = mb_strtoupper($rawEstado);

            // Requeridos
            foreach (['tipo' => $rawTipo, 'marca' => $rawMarca, 'modelo' => $rawModelo, 'serial' => $rawSerial, 'estado' => $rawEstado] as $field => $val) {
                if ($val === '') $errors[$field] = 'Campo requerido.';
            }

            // TIPO: resolver a code. Match por label o por code (case-insensitive).
            // Si no matchea, normalizar como custom (UPPERCASE + _).
            $tipoCodigo = null;
            if ($rawTipo !== '') {
                $key = mb_strtolower($rawTipo);
                if (isset($tiposByCodeLower[$key])) {
                    $tipoCodigo = $tiposByCodeLower[$key];
                } elseif (isset($tiposByLabelLower[$key])) {
                    $tipoCodigo = $tiposByLabelLower[$key];
                } else {
                    $tipoCodigo = mb_strtoupper(preg_replace('/\s+/', '_', $rawTipo));
                }
            }

            // ESTADO
            if ($rawEstado !== '' && !in_array($estadoUpper, $validEstados)) {
                $errors['estado'] = 'Valor no valido. Opciones: ' . implode(', ', $validEstados) . '.';
            }

            // FRENTE (opcional)
            $idFrenteResuelto = null;
            if ($rawFrente !== '') {
                $fKey = mb_strtolower(trim($rawFrente));
                if (isset($frentesMap[$fKey])) {
                    $idFrenteResuelto = $frentesMap[$fKey]->ID_FRENTE;
                } else {
                    $errors['frente_de_trabajo'] = 'Frente no encontrado o inactivo.';
                }
            }

            // SERIAL unique
            if ($serialUpper !== '') {
                if (in_array($serialUpper, $existingSerialesBD)) {
                    $errors['serial'] = 'Ya registrado en BD.';
                } elseif (in_array($serialUpper, $duplicateSeriales)) {
                    $errors['serial'] = 'Duplicado dentro del archivo.';
                }
            }

            $anio = ($rawAnio !== '' && $rawAnio !== null) ? (int) $rawAnio : null;
            if ($anio !== null && ($anio < 1950 || $anio > 2100)) {
                $errors['año'] = 'Debe estar entre 1950 y 2100.';
            }

            $rows[] = [
                'row_index' => $n,
                'data' => [
                    'tipo'               => mb_strtoupper($rawTipo),
                    'tipo_codigo'        => $tipoCodigo,
                    'marca'              => mb_strtoupper($rawMarca),
                    'modelo'             => mb_strtoupper($rawModelo),
                    'serial'             => $serialUpper,
                    'capacidad'          => mb_strtoupper($rawCap),
                    'anio'               => $anio,
                    'frente'             => mb_strtoupper($rawFrente),
                    'id_frente_resuelto' => $idFrenteResuelto,
                    'estado'             => $estadoUpper,
                ],
                'errors' => $errors,
            ];
        }

        return response()->json([
            'success' => true,
            'rows'    => $rows,
            'options' => [
                'tipos'   => array_values(array_map(fn($l) => mb_strtoupper($l), $tiposMap)),
                'frentes' => FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
                                ->orderBy('NOMBRE_FRENTE')
                                ->get(['ID_FRENTE', 'NOMBRE_FRENTE'])
                                ->map(fn($f) => ['id' => $f->ID_FRENTE, 'nombre' => $f->NOMBRE_FRENTE]),
                'estados' => $validEstados,
            ],
        ]);
    }

    /**
     * Recibe el batch ya editado en el frontend y lo inserta en transaccion.
     * Si hay errores de BD en alguna fila, hace rollback completo y reporta
     * los fallos. Tipos custom nuevos simplemente se guardan con su code
     * normalizado (no requiere tabla de catalogo).
     */
    public function bulkStoreBatch(Request $request)
    {
        set_time_limit(600);

        $validator = \Validator::make($request->all(), [
            'rows'                       => 'required|array|min:1|max:500',
            'rows.*.tipo_codigo'         => 'required|string|max:30',
            'rows.*.marca'               => 'required|string|max:80',
            'rows.*.modelo'              => 'required|string|max:80',
            'rows.*.serial'              => 'required|string|max:100',
            'rows.*.capacidad'           => 'nullable|string|max:80',
            'rows.*.anio'                => 'nullable|integer|min:1950|max:2100',
            'rows.*.id_frente_resuelto'  => 'nullable|integer|exists:frentes_trabajo,ID_FRENTE',
            'rows.*.estado'              => 'required|string|in:' . implode(',', array_keys(EquipoAuxiliar::estadosLabel())),
        ], [
            // Mensajes en español por fila (regla :input/:position se reemplaza por el numero de fila +2 = numero real en el Excel)
            'rows.*.tipo_codigo.required'        => 'Fila :position: el Tipo es obligatorio.',
            'rows.*.tipo_codigo.max'             => 'Fila :position: el Tipo no puede exceder 30 caracteres.',
            'rows.*.marca.required'              => 'Fila :position: la Marca es obligatoria.',
            'rows.*.marca.max'                   => 'Fila :position: la Marca no puede exceder 80 caracteres.',
            'rows.*.modelo.required'             => 'Fila :position: el Modelo es obligatorio.',
            'rows.*.modelo.max'                  => 'Fila :position: el Modelo no puede exceder 80 caracteres.',
            'rows.*.serial.required'             => 'Fila :position: el Serial es obligatorio.',
            'rows.*.serial.max'                  => 'Fila :position: el Serial no puede exceder 100 caracteres.',
            'rows.*.capacidad.max'               => 'Fila :position: la Capacidad no puede exceder 80 caracteres.',
            'rows.*.anio.integer'                => 'Fila :position: el Año debe ser un número entero.',
            'rows.*.anio.min'                    => 'Fila :position: el Año debe ser mayor o igual a 1950.',
            'rows.*.anio.max'                    => 'Fila :position: el Año debe ser menor o igual a 2100.',
            'rows.*.id_frente_resuelto.integer'  => 'Fila :position: el Frente seleccionado no es válido.',
            'rows.*.id_frente_resuelto.exists'   => 'Fila :position: el Frente seleccionado no existe o está inactivo.',
            'rows.*.estado.required'             => 'Fila :position: el Estado es obligatorio.',
            'rows.*.estado.in'                   => 'Fila :position: el Estado seleccionado no es válido.',
            'rows.required'                      => 'Debes incluir al menos una fila para cargar.',
            'rows.array'                         => 'El formato del lote es inválido.',
            'rows.min'                           => 'Debes incluir al menos una fila para cargar.',
            'rows.max'                           => 'Solo se pueden cargar hasta 500 filas por lote.',
        ]);

        if ($validator->fails()) {
            $errs   = $validator->errors();
            $first  = $errs->first();
            $count  = count($errs->all());
            $summary = $first;
            if ($count > 1) {
                $extra = $count - 1;
                $summary .= ' (y ' . $extra . ' error' . ($extra > 1 ? 'es' : '') . ' más)';
            }
            return response()->json([
                'success' => false,
                'message' => $summary,
                'errors'  => $errs->toArray(),
            ], 422);
        }
        $data = $validator->validated();

        // Unicidad de SERIAL cross-batch y contra BD (defensa final server-side)
        $seriales = array_map(fn($r) => mb_strtoupper(trim($r['serial'])), $data['rows']);
        $dupEnBatch = array_keys(array_filter(array_count_values($seriales), fn($c) => $c > 1));
        if (!empty($dupEnBatch)) {
            return response()->json([
                'success' => false,
                'message' => 'Hay seriales duplicados en el batch: ' . implode(', ', $dupEnBatch),
            ], 422);
        }
        $conflictsBD = DB::table('equipos_auxiliares')
            ->whereIn(DB::raw('UPPER(SERIAL)'), $seriales)
            ->pluck('SERIAL')->toArray();
        if (!empty($conflictsBD)) {
            return response()->json([
                'success' => false,
                'message' => 'Algun serial ya existe en BD: ' . implode(', ', $conflictsBD),
            ], 422);
        }

        $creadoPor = auth()->id();
        $now = now();

        DB::beginTransaction();
        try {
            $batch = [];
            foreach ($data['rows'] as $row) {
                $batch[] = [
                    'TIPO'             => mb_strtoupper(preg_replace('/\s+/', '_', $row['tipo_codigo'])),
                    'MARCA'            => mb_strtoupper(trim($row['marca'])),
                    'MODELO'           => mb_strtoupper(trim($row['modelo'])),
                    'SERIAL'           => mb_strtoupper(trim($row['serial'])),
                    'CAPACIDAD'        => !empty($row['capacidad']) ? mb_strtoupper(trim($row['capacidad'])) : null,
                    'ANIO'             => $row['anio'] ?? null,
                    'ID_FRENTE_ACTUAL' => $row['id_frente_resuelto'] ?? null,
                    'ESTADO_OPERATIVO' => $row['estado'],
                    'CREADO_POR'       => $creadoPor,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            }
            EquipoAuxiliar::insert($batch);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Se registraron ' . count($batch) . ' equipo(s) auxiliar(es).',
                'count'   => count($batch),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('bulkStoreBatch auxiliares: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al guardar el batch: ' . $e->getMessage(),
            ], 500);
        }
    }
}
