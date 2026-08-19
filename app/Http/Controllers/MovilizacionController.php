<?php

namespace App\Http\Controllers;

use App\Models\Movilizacion;
use App\Models\FrenteTrabajo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MovilizacionController extends Controller
{
    // Logo corporativo de las hojas de Excel: el MISMO helper que equipos, almacen,
    // auxiliares y consumibles, para que el encabezado salga identico en todos.
    use \App\Traits\ExcelLogoCorporativo;

    public function __construct()
    {
        $this->middleware('auth')->except(['mobileIndex', 'mobileStore']);
        // Permiso para MOVER equipos (Crear movilizaciones o registrar recepcion directa sin despacho previo)
        $this->middleware('can:equipos.assign')->only(['bulkStore', 'recepcionDirecta']);
        // Borrar/deshacer movilizaciones es destructivo: solo super.admin (consistente con el modulo de equipos).
        $this->middleware('can:super.admin')->only(['bulkDestroy', 'deshacer']);
    }

    public function index(Request $request)
    {
        // Sin scope LOCAL: todos los usuarios autenticados ven todo el historial
        // de movilizaciones. La accion destructiva (borrar) ya esta gateada por
        // can:super.admin en el middleware del constructor.

        $query = Movilizacion::with([
            'equipo.tipo',
            'equipo.especificaciones:ID_ESPEC,FOTO_REFERENCIAL',
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
        // Filtros del historial (búsqueda, frente + dirección, tipo y rango de fechas).
        // Viven en aplicarFiltrosHistorial() porque la exportación a Excel usa EXACTAMENTE
        // los mismos: copiarlos haría que el archivo acabe mostrando otra cosa que la tabla.
        $this->aplicarFiltrosHistorial($query, $request);

        // Paginación: ventana deslizante de tamaño FIJO (sin puntos suspensivos);
        // la cantidad de botones no cambia al navegar (ver vendor.pagination.custom-sliding).
        $movilizaciones = $query->orderBy('movilizacion_historial.created_at', 'desc')->paginate(16);

        $totalTransito = $movilizaciones->total();

        // Mostramos TODOS los frentes en el historial (activos y finalizados)
        // porque se necesita poder buscar movilizaciones de frentes antiguos.
        $frentes = FrenteTrabajo::orderBy('NOMBRE_FRENTE')->get();
        $allTipos = \App\Models\TipoEquipo::orderBy('nombre')->get();
        $tiposAux = \App\Models\EquipoAuxiliar::whereNotNull('TIPO')->where('TIPO', '!=', '')
            ->distinct()->orderBy('TIPO')->pluck('TIPO');

        if ($request->wantsJson()) {
            $tableHtml = view('admin.movilizaciones.partials.table_rows', compact('movilizaciones'))->render();
            $paginationHtml = $movilizaciones->appends($request->all())->links('vendor.pagination.custom-sliding')->toHtml();

            return response()->json([
                'html' => $tableHtml,
                'pagination' => $paginationHtml,
                'totalTransito' => $totalTransito
            ]);
        }

        return view('admin.movilizaciones.index', compact('movilizaciones', 'totalTransito', 'frentes', 'allTipos', 'tiposAux'));
    }


    /**
     * Filtros del HISTORIAL. Punto ÚNICO: los usan la pantalla (index) y la exportación a
     * Excel, para que el archivo no pueda traer un universo distinto al que se está viendo.
     *
     * Extraído a propósito, siguiendo lo que ya se hizo en equipos (applyEquipoFilters):
     * allí el export tenía los filtros COPIADOS y se fueron separando de la pantalla sin
     * que nadie lo notara — el tipo no entendía el prefijo del desplegable y algunos
     * filtros se ignoraban. Aquí no se repiten.
     */
    private function aplicarFiltrosHistorial($query, Request $request): void
    {
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
            $tipoVal = (string) $request->id_tipo;
            if (str_starts_with($tipoVal, 'tipo_eq:')) {
                $query->whereNotNull('movilizacion_historial.ID_EQUIPO')
                      ->whereHas('equipo', fn ($q) => $q->where('id_tipo_equipo', (int) substr($tipoVal, 8)));
            } elseif (str_starts_with($tipoVal, 'tipo_aux:')) {
                $query->whereNotNull('movilizacion_historial.ID_AUXILIAR')
                      ->whereHas('auxiliar', fn ($q) => $q->where('TIPO', substr($tipoVal, 9)));
            } else {
                $query->whereHas('equipo', fn ($q) => $q->where('id_tipo_equipo', $tipoVal));
            }
        }

        // Date range filter — rangos sobre la columna cruda (NO whereDate: envolver
        // la columna en DATE() impide usar el índice de created_at). Una fecha
        // malformada en la URL se IGNORA (try/catch): Carbon::parse revienta con
        // 500 y el whereDate viejo simplemente no devolvía filas.
        if ($request->filled('fecha_desde')) {
            try {
                $query->where('movilizacion_historial.created_at', '>=', \Carbon\Carbon::parse($request->fecha_desde)->toDateString());
            } catch (\Throwable $e) { /* fecha inválida → sin filtro */ }
        }
        if ($request->filled('fecha_hasta')) {
            try {
                $query->where('movilizacion_historial.created_at', '<', \Carbon\Carbon::parse($request->fecha_hasta)->addDay()->toDateString());
            } catch (\Throwable $e) { /* fecha inválida → sin filtro */ }
        }
    }


    /**
     * Exporta a XLSX el historial TAL COMO SE ESTÁ VIENDO.
     *
     * Usa aplicarFiltrosHistorial(), los mismos filtros que la pantalla, así que el archivo
     * no puede traer un universo distinto al de la tabla. Encabezado corporativo igual al
     * del módulo de equipos (logo + título + EDICIÓN/REVISIÓN/FECHA), con el título propio
     * de este listado.
     *
     * Sin paginar a propósito: la pantalla muestra de 16 en 16, pero lo que se pide del
     * Excel es el historial filtrado ENTERO, no la página que se esté mirando.
     */
    public function export(Request $request)
    {
        $query = Movilizacion::with([
            // Se traen los TRES identificadores porque la columna SERIAL es el primero que
            // exista de ellos (chasis → placa → motor). CODIGO_PATIO y el usuario ya no se
            // cargan: sus columnas se quitaron del Excel y traerlos sería trabajo de balde.
            'equipo:ID_EQUIPO,id_tipo_equipo,MARCA,MODELO,SERIAL_CHASIS,SERIAL_DE_MOTOR',
            'equipo.tipo:id,nombre',
            'equipo.documentacion:ID_EQUIPO,PLACA',
            'auxiliar:ID_AUXILIAR,TIPO,MARCA,MODELO,SERIAL',
            'frenteOrigen',
            'frenteDestino',
        ]);

        $this->aplicarFiltrosHistorial($query, $request);

        $movs = $query->orderBy('movilizacion_historial.created_at', 'desc')->get();

        $currentDate = date('d/m/Y');
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Historial');

        $spreadsheet->getProperties()
            ->setCreator('Sistema de Gestión de Equipos Operacionales')
            ->setLastModifiedBy('Sistema de Gestión de Equipos Operacionales')
            ->setTitle('Historial de Movilizaciones')
            ->setSubject('Exportación - Sistema de Gestión de Equipos Operacionales')
            ->setDescription('Generado automáticamente por el Sistema de Gestión de Equipos Operacionales - C.VIDALSA 27, C.A.')
            ->setCompany('Constructora Vidalsa 27, C.A.');

        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        $lastCol      = 'H';   // A..H = 8 columnas de datos
        $endTitle     = 'E';   // el título ocupa C..E
        $startEdicion = 'F';   // EDICIÓN/REVISIÓN/FECHA a la derecha, angosto

        foreach ([1, 2, 3] as $fila) $sheet->getRowDimension($fila)->setRowHeight(40);

        // Logo centrado en A1:B3 (trait ExcelLogoCorporativo, el mismo de los demás export)
        $this->insertarLogoCorporativo($sheet, ['A', 'B'], [1, 2, 3]);
        $sheet->mergeCells('A1:B3');
        $sheet->getStyle('A1:B3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        // Título + de qué recorte de datos se trata, para que el archivo se explique solo
        $sheet->mergeCells('C1:' . $endTitle . '3');
        $sheet->setCellValue('C1', "HISTORIAL DE MOVILIZACIONES\n" . $this->subtituloExport($request));
        $sheet->getStyle('C1')->getAlignment()->setWrapText(true)
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('C1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $sheet->getStyle('C1:' . $endTitle . '3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        foreach ([1 => 'EDICION: 1', 2 => 'REVISION: 0', 3 => 'FECHA: ' . $currentDate] as $fila => $texto) {
            $rango = $startEdicion . $fila . ':' . $lastCol . $fila;
            $sheet->mergeCells($rango);
            $sheet->setCellValue($startEdicion . $fila, $texto);
            $sheet->getStyle($startEdicion . $fila)->getAlignment()
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $sheet->getStyle($startEdicion . $fila)->getFont()->setBold(true)->setSize(11)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
            $sheet->getStyle($rango)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        }

        $sheet->mergeCells('A4:' . $lastCol . '4');
        $sheet->setCellValue('A4', 'Exportado por: Sistema de Gestión de Equipos Operacionales');
        $sheet->getStyle('A4:' . $lastCol . '4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A4:' . $lastCol . '4')->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A4:' . $lastCol . '4')->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF333333');
        $sheet->getRowDimension(4)->setRowHeight(20);

        $bordes = ['borders' => ['allBorders' => [
            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
            'color' => ['argb' => 'FF000000'],
        ]]];
        $sheet->getStyle('A1:' . $lastCol . '4')->applyFromArray($bordes);

        // Fila 5 — encabezados de la tabla
        $columnas = ['A','B','C','D','E','F','G','H'];
        $titulos  = ['N°', 'FECHA', 'TIPO', 'MARCA', 'MODELO', 'SERIAL', 'ORIGEN', 'DESTINO'];
        foreach ($titulos as $i => $t) $sheet->setCellValue($columnas[$i] . '5', $t);

        $sheet->getStyle('A5:' . $lastCol . '5')->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $sheet->getStyle('A5:' . $lastCol . '5')->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A5:' . $lastCol . '5')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF1B365D');
        $sheet->getRowDimension(5)->setRowHeight(40);

        foreach ([8, 18, 22, 18, 22, 26, 26, 26] as $i => $ancho) {
            $sheet->getColumnDimension($columnas[$i])->setWidth($ancho);
        }

        // Datos. Cada fila del historial es de un EQUIPO o de un AUXILIAR (nunca de los
        // dos), igual que en la tabla de la pantalla: se toma el que venga poblado.
        $fila = 6;
        foreach ($movs as $i => $mv) {
            $esAux = $mv->ID_AUXILIAR !== null;
            $aux   = $mv->auxiliar;
            $eq    = $mv->equipo;

            $datos = [
                $i + 1,
                optional($mv->created_at)->format('d/m/Y H:i') ?: '—',
                $esAux ? ($aux->TIPO ?? '—')   : (optional(optional($eq)->tipo)->nombre ?? '—'),
                $esAux ? ($aux->MARCA ?? '—')  : (optional($eq)->MARCA ?? '—'),
                $esAux ? ($aux->MODELO ?? '—') : (optional($eq)->MODELO ?? '—'),
                $this->serialIdentificador($eq, $aux, $esAux),
                $mv->nombre_origen  ?: '—',
                $mv->nombre_destino ?: '—',
            ];

            foreach ($datos as $c => $valor) $sheet->setCellValue($columnas[$c] . $fila, $valor);
            $fila++;
        }

        $ultima = max($fila - 1, 6);
        $sheet->getStyle('A6:' . $lastCol . $ultima)->applyFromArray($bordes);
        $sheet->getStyle('A6:' . $lastCol . $ultima)->getAlignment()
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $sheet->getStyle('A6:A' . $ultima)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B6:B' . $ultima)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('A6');   // el encabezado queda fijo al desplazarse

        $nombre = 'Historial_Movilizaciones_' . date('Y-m-d_H-i') . '.xlsx';
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $nombre, [
            'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * UN solo identificador por fila, el primero que exista: serial de chasis, si no la
     * placa, y si no el serial de motor.
     *
     * Antes iban dos columnas (SERIAL y PLACA) y las dos salían medio vacías: cada unidad
     * tiene unos identificadores y no otros, así que la hoja quedaba llena de guiones y
     * había que mirar en dos sitios para saber de qué equipo se hablaba. Una sola columna
     * con el que exista se lee de un vistazo.
     *
     * El orden no es capricho: el chasis es único e inmutable, la placa puede cambiar de
     * dueño o no estar aún tramitada, y el de motor es el último recurso porque cambia si
     * se repotencia. Los auxiliares no tienen placa ni motor: llevan su propio SERIAL.
     */
    private function serialIdentificador($equipo, $auxiliar, bool $esAux): string
    {
        if ($esAux) {
            return trim((string) ($auxiliar->SERIAL ?? '')) ?: '—';
        }

        foreach ([
            $equipo->SERIAL_CHASIS ?? null,
            optional(optional($equipo)->documentacion)->PLACA,
            $equipo->SERIAL_DE_MOTOR ?? null,
        ] as $candidato) {
            $valor = trim((string) $candidato);
            if ($valor !== '') return $valor;
        }

        return '—';
    }

    /**
     * Segunda línea del título: qué recorte de datos lleva el archivo. Sin esto, dos
     * exportaciones con filtros distintos son indistinguibles al abrirlas.
     */
    private function subtituloExport(Request $request): string
    {
        $partes = [];

        if ($request->filled('id_frente') && $request->id_frente !== 'all') {
            $frente = FrenteTrabajo::find($request->id_frente);
            if ($frente) {
                $direccion = $request->input('direccion_frente');
                $comoEntra = $direccion === 'entrada' ? 'ENTRADAS A' : ($direccion === 'salida' ? 'SALIDAS DE' : 'FRENTE');
                $partes[] = $comoEntra . ' "' . mb_strtoupper($frente->NOMBRE_FRENTE) . '"';
            }
        }
        if ($request->filled('fecha_desde') || $request->filled('fecha_hasta')) {
            $desde = $request->input('fecha_desde') ?: 'INICIO';
            $hasta = $request->input('fecha_hasta') ?: 'HOY';
            $partes[] = 'DEL ' . $desde . ' AL ' . $hasta;
        }
        if ($request->filled('search')) {
            $partes[] = 'BUSQUEDA: "' . mb_strtoupper(trim($request->search)) . '"';
        }

        return $partes ? implode(' · ', $partes) : 'HISTORIAL COMPLETO';
    }

    public function bulkStore(Request $request)
    {
        $request->validate([
            'ids'                  => 'required|array|min:1',
            'ids.*'                => 'exists:equipos,ID_EQUIPO',
            'destination'          => 'required|string|max:255',
            'destination_ubicacion'=> 'nullable|string|max:150',
            'generar_pdf'          => 'boolean',
            // Datos del Acta editados en la vista previa (opcionales). Sólo se usan
            // para la AUDITORÍA del movimiento — el PDF se genera aparte con su
            // propio endpoint. firmas = null cuando el usuario no editó firmas.
            'origin'               => 'nullable|string|max:255',
            'origin_zona'          => 'nullable|string|max:150',
            'firmas'               => 'nullable|array',
            'firmas.*.label'       => 'nullable|string|max:60',
            'firmas.*.car'         => 'nullable|string|max:120',
            'firmas.*.nom'         => 'nullable|string|max:120',
            'firmas.*.ced'         => 'nullable|string|max:40',
        ]);

        $authUser = auth()->user();

        DB::beginTransaction();
        try {
            $destNombre    = strtoupper(trim($request->destination));
            $destUbicacion = trim((string) $request->input('destination_ubicacion', ''));
            $generarPdf    = (bool) $request->input('generar_pdf', true);

            // Buscar el frente existente (puede tener UBICACION vacía en BD).
            $frenteExistente = FrenteTrabajo::where('NOMBRE_FRENTE', $destNombre)->first();
            $frenteNecesitaUbicacion = !$frenteExistente || empty(trim((string)($frenteExistente->UBICACION ?? '')));

            // Guardia backend: la ubicación SOLO es obligatoria cuando se genera el Acta
            // PDF (la imprime en su encabezado). Sin PDF se permite crear/mover a un frente
            // sin ubicación — queda en blanco hasta que se emita un acta para él. El
            // frontend respeta la misma regla; este guard cubre llamadas directas.
            if ($generarPdf && $frenteNecesitaUbicacion && $destUbicacion === '') {
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
            // campo NIVEL_ACCESO_EQUIPOS del usuario NO limita la operacion â€” la
            // filosofia del sistema es "solo la clave PERMISOS decide"
            // (ver AppServiceProvider::boot).

            $userEmail  = $authUser->CORREO_ELECTRONICO ?? 'SISTEMA';
            $now        = now();
            // $generarPdf ya se resolvió arriba (junto a la guardia de ubicación).

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

            // Nombres de los frentes de ORIGEN — un solo query por lote (no N+1) para los
            // distintos ID_FRENTE_ACTUAL que trae $aMovilizar. El destino es uno solo
            // ($frente, ya cargado arriba). Se reutiliza más abajo TANTO para congelar el
            // snapshot del historial como para el bloque de auditoría — un solo cómputo.
            $origenIds     = $aMovilizar->pluck('ID_FRENTE_ACTUAL')->filter()->unique()->values();
            $origenNombres = FrenteTrabajo::whereIn('ID_FRENTE', $origenIds)->pluck('NOMBRE_FRENTE', 'ID_FRENTE');

            // Crear movilizaciones una por una para obtener IDs exactos
            // (sin depender de timestamp match entre Carbon Âµs y MySQL TIMESTAMP sin fracciÃ³n).
            $movilizacionIds = [];
            foreach ($aMovilizar as $equipo) {
                $mov = Movilizacion::create([
                    'CODIGO_CONTROL'    => $generarPdf ? $nextId : null,
                    'ID_EQUIPO'         => $equipo->ID_EQUIPO,
                    'ID_FRENTE_ORIGEN'  => $equipo->ID_FRENTE_ACTUAL ?? 1,
                    'ID_FRENTE_DESTINO' => $frente->ID_FRENTE,
                    // Nombre congelado al momento del movimiento — ver Movilizacion::getNombreOrigenAttribute.
                    'NOMBRE_FRENTE_ORIGEN_SNAPSHOT'  => $origenNombres[$equipo->ID_FRENTE_ACTUAL] ?? null,
                    'NOMBRE_FRENTE_DESTINO_SNAPSHOT' => $frente->NOMBRE_FRENTE,
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
            // El mass-update NO dispara EquipoObserver (ver auditoría de abajo):
            // el bump del dashboard /menu hay que hacerlo explícito aquí.
            \App\Http\Controllers\DashboardController::bumpDataVersion();

            DB::commit();

            // ── AUDITORÍA de la movilización ──────────────────────────────────
            // El cambio de frente de arriba se hace con un mass-update de query
            // builder, que NO dispara EquipoObserver → sin esto la movilización no
            // dejaría NINGÚN rastro en el módulo de auditoría. Registramos por
            // equipo: origen, destino, N° de acta y las firmas EDITADAS en la
            // vista previa (las firmas por defecto ya viven en el frente de origen).
            // $aMovilizar conserva el ID_FRENTE_ACTUAL ORIGINAL (se cargó antes del
            // update y éste fue por query aparte) → es el frente de origen real.
            try {
                $firmasAudit  = $this->normalizeFirmasOverride($request->input('firmas'));
                $overrideOrig = strtoupper(trim((string) $request->input('origin', '')));
                $overrideZona = strtoupper(trim((string) $request->input('origin_zona', '')));
                $ubicLabel    = $destUbicacion !== '' ? strtoupper($destUbicacion) : (string) ($frente->UBICACION ?? '');
                $actaNum      = $generarPdf ? str_pad((string) $nextId, 6, '0', STR_PAD_LEFT) : null;

                // $origenNombres ya se calculó arriba (para el snapshot del historial) con
                // el mismo criterio — reusarlo evita repetir la misma query dos veces.
                foreach ($aMovilizar as $equipo) {
                    $origenNom = $overrideOrig !== ''
                        ? $overrideOrig
                        : ($origenNombres[$equipo->ID_FRENTE_ACTUAL] ?? '—');

                    $cambios = [
                        'Origen'  => $origenNom . ($overrideZona !== '' ? ' — ' . $overrideZona : ''),
                        'Destino' => $destNombre . ($ubicLabel !== '' ? ' — ' . $ubicLabel : ''),
                    ];
                    if ($actaNum) $cambios['N°_de_acta'] = $actaNum;
                    if ($firmasAudit) {
                        foreach ($firmasAudit as $i => $f) {
                            $rol = rtrim($f['label'], ':');
                            $val = $rol . ' — ' . $f['nom'];
                            if ($f['car'] !== '' && $f['car'] !== 'RESPONSABLE') $val .= ' · ' . $f['car'];
                            if ($f['ced'] !== '') $val .= ' · CI ' . $f['ced'];
                            $cambios['Firma_' . ($i + 1)] = $val;
                        }
                    }
                    \App\Models\EquipoAuditLog::registrar($equipo->ID_EQUIPO, 'movilizacion', $cambios);
                }
            } catch (\Throwable $e) {
                Log::warning('Auditoría de movilización falló: ' . $e->getMessage());
            }

            return response()->json([
                'success'          => true,
                'movilizacion_ids' => $movilizacionIds,
                'count'            => count($movilizacionIds),
                'omitidos'         => $omitidos, // ya estaban en el frente destino → no se movilizaron
                'generar_pdf'      => $generarPdf,
                // Frente de destino: el front lo inyecta en el filtro/datalist sin recargar
                // cuando es_nuevo, para que un frente recién creado aparezca de inmediato
                // en las sugerencias del filtro de Frente.
                'frente'           => [
                    'id'        => $frente->ID_FRENTE,
                    'nombre'    => $frente->NOMBRE_FRENTE,
                    'ubicacion' => $frente->UBICACION,
                    'es_nuevo'  => (bool) $frente->wasRecentlyCreated,
                ],
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
        // del controller). NIVEL_ACCESO_EQUIPOS del usuario NO restringe el frente destino.

        DB::beginTransaction();
        try {
            $now = now();
            $frenteDestino = FrenteTrabajo::findOrFail($request->ID_FRENTE_DESTINO);

            // Sin `with('frenteActual')` â€” solo usamos ID_FRENTE_ACTUAL directo, no la relacion.
            $equipos = \App\Models\Equipo::whereIn('ID_EQUIPO', $request->ids)
                ->lockForUpdate()
                ->get(['ID_EQUIPO', 'ID_FRENTE_ACTUAL']);

            // Nombres de los frentes de ORIGEN a congelar en el snapshot — un solo query
            // por lote (no N+1). El destino es uno solo ($frenteDestino, ya cargado arriba).
            $origenIds     = $equipos->pluck('ID_FRENTE_ACTUAL')->filter()->unique()->values();
            $origenNombres = FrenteTrabajo::whereIn('ID_FRENTE', $origenIds)->pluck('NOMBRE_FRENTE', 'ID_FRENTE');

            $insertData = [];
            foreach ($equipos as $equipo) {
                $insertData[] = [
                    'CODIGO_CONTROL' => null, // Recepciones directas no tienen cÃ³digo de control
                    'ID_EQUIPO' => $equipo->ID_EQUIPO,
                    'ID_FRENTE_ORIGEN' => $equipo->ID_FRENTE_ACTUAL ?? $request->ID_FRENTE_DESTINO,
                    // Nombre congelado al momento del movimiento — ver Movilizacion::getNombreOrigenAttribute.
                    // Mismo fallback que ID_FRENTE_ORIGEN de arriba: sin origen real, usa el destino.
                    'NOMBRE_FRENTE_ORIGEN_SNAPSHOT'  => $origenNombres[$equipo->ID_FRENTE_ACTUAL] ?? $frenteDestino->NOMBRE_FRENTE,
                    'ID_FRENTE_DESTINO' => $request->ID_FRENTE_DESTINO,
                    'NOMBRE_FRENTE_DESTINO_SNAPSHOT' => $frenteDestino->NOMBRE_FRENTE,
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
            // Mass-update sin eventos Eloquent → bump explícito del dashboard.
            \App\Http\Controllers\DashboardController::bumpDataVersion();

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
            $user->aplicarScopeFrentesEquipos($query, 'ID_FRENTE_ACTUAL');
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $searchUpper = strtoupper(trim($search));

            if (strpos($searchUpper, '#') !== false) {
                // Mode: Tag Number Search
                $tagSearch = str_replace('#', '', $searchUpper);
                $query->where('NUMERO_ETIQUETA', 'like', "%{$tagSearch}%");

            } else {
                // Búsqueda estándar: cubre SERIAL_CHASIS, SERIAL_DE_MOTOR, CODIGO_PATIO,
                // NUMERO_ETIQUETA y PLACA. (Se quitó la rama que, si el texto tenía guion,
                // buscaba SOLO en CODIGO_PATIO — impedía encontrar seriales con guion.
                // CODIGO_PATIO ya se busca aquí, igual que en /admin/equipos.)
                // O/0 ambiguity applied ONLY to PLACA
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
            // (middleware 'auth'). NIVEL_ACCESO_EQUIPOS del usuario no restringe â€”
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

            // Nombre impreso en el acta = el que tenÃ­a el frente EN EL MOMENTO del
            // movimiento (snapshot congelado — ver Movilizacion::getNombreOrigenAttribute),
            // no el nombre actual: si el frente se renombrÃ³ despuÃ©s, un acta ya emitida
            // no debe cambiar de nombre retroactivamente al reabrirla/reimprimirla. Solo se
            // pisa el atributo NOMBRE_FRENTE en memoria (no se persiste, no hay ->save() en
            // este flujo) — ubicaciÃ³n y firmantes siguen viniendo del registro VIVO del
            // frente, igual que antes (esos sÃ­ reflejan el estado actual a propÃ³sito).
            if ($frenteOrigen) {
                $nombreOrigenSnap = optional($movilizaciones->firstWhere('ID_FRENTE_ORIGEN', $idOrigenMayoria))->nombre_origen;
                if ($nombreOrigenSnap) {
                    $frenteOrigen->NOMBRE_FRENTE = $nombreOrigenSnap;
                }
            }
            if ($movilizacion->nombre_destino) {
                $frenteDestino->NOMBRE_FRENTE = $movilizacion->nombre_destino;
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
            // Ubicacion del destino tecleada en la vista previa: se imprime tal cual (en
            // memoria, sin ->save()) para que el acta FINAL sea identica a la previa
            // aunque el frente ya tuviera otra UBICACION en BD.
            $overrideDestUbic = trim((string) $request->input('override_destino_ubicacion', ''));
            if ($overrideDestUbic !== '') {
                $frenteDestino->UBICACION = $overrideDestUbic;
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

    /**
     * Construye el PDF del Acta de Traslado (binario) a partir de datos ya resueltos.
     * Centralizado: lo usan generarActaTraslado() (descarga real con CODIGO_CONTROL)
     * y previewActaLote() (vista previa desde borrador, numero "PENDIENTE"). Asi NO
     * se duplica el armado/paginacion. Margenes 18/40/18 atados a equiposColWidths
     * (=174mm) y cabX/cabW (18/174) del Header.
     */
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
            'type'                  => 'nullable|in:equipo,auxiliar',
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
            // Para auxiliares: crea objetos sintéticos compatibles con buildActaPdfBinary,
            // igual que generarActaTraslado() hace para movilizaciones ya registradas.
            if (($data['type'] ?? 'equipo') === 'auxiliar') {
                $auxItems = \App\Models\EquipoAuxiliar::whereIn('ID_AUXILIAR', $data['ids'])->get();
                if ($auxItems->isEmpty()) {
                    return response()->json(['message' => 'No se encontraron auxiliares para previsualizar.'], 422);
                }
                $equipos = $auxItems->map(function ($a) {
                    $s = new \stdClass();
                    $s->ID_FRENTE_ACTUAL = $a->ID_FRENTE_ACTUAL;
                    $s->CODIGO_PATIO     = $a->CODIGO_INTERNO ?: $a->SERIAL;
                    $s->SERIAL_CHASIS    = $a->SERIAL ?: '—';
                    $s->SERIAL_DE_MOTOR  = '';
                    $s->MARCA            = $a->MARCA ?: '';
                    $s->MODELO           = $a->MODELO ?: '';
                    $s->ANIO             = $a->ANIO ?? '';
                    $s->NUMERO_ETIQUETA  = '';
                    $s->ESTADO_OPERATIVO = $a->ESTADO_OPERATIVO ?? 'OPERATIVO';
                    $s->FOTO_EQUIPO      = $a->FOTO ?? null;
                    $s->tipo             = (object) ['nombre' => $a->TIPO ?? 'AUXILIAR'];
                    $s->documentacion    = (object) ['PLACA' => 'S/P'];
                    $s->especificaciones = null;
                    $s->CATEGORIA_FLOTA  = 'FLOTA LIVIANA';
                    return $s;
                });
            } else {
                $equipos = \App\Models\Equipo::with(['tipo', 'documentacion', 'especificaciones'])
                    ->whereIn('ID_EQUIPO', $data['ids'])
                    ->get();
                if ($equipos->isEmpty()) {
                    return response()->json(['message' => 'No se encontraron equipos para previsualizar.'], 422);
                }
            }

            // Origen del acta: si el usuario lo editó a mano (texto libre) usamos un stub
            // con ese nombre/zona; si no, frente con MAS equipos en la seleccion (misma
            // regla por mayoria que usa el acta real).
            if (!empty(trim($data['origin'] ?? ''))) {
                $frenteOrigen = $this->stubFrenteOrigen($data['origin'], $data['origin_zona'] ?? '');
            } else {
                $idOrigen = collect($equipos)->groupBy('ID_FRENTE_ACTUAL')
                    ->map(fn ($g) => count($g))
                    ->sortDesc()
                    ->keys()
                    ->first();
                $frenteOrigen = FrenteTrabajo::find($idOrigen);
            }

            // Destino: frente existente por nombre, o un stub (frente nuevo aun no
            // creado) con lo tecleado en el modal. El blade del acta solo usa
            // NOMBRE_FRENTE / UBICACION / TIPO_FRENTE del destino (las firmas salen
            // del frente de ORIGEN).
            $destNom    = trim($data['destination']);
            $destUbicac = trim($data['destination_ubicacion'] ?? '');
            $frenteDestino = FrenteTrabajo::whereRaw('UPPER(NOMBRE_FRENTE) = ?', [mb_strtoupper($destNom)])->first();
            if (!$frenteDestino) {
                $frenteDestino = new FrenteTrabajo();
                $frenteDestino->NOMBRE_FRENTE = $destNom;
                $frenteDestino->TIPO_FRENTE   = 'OPERACION';
            }
            // La ubicacion tecleada en el formulario del acta SIEMPRE manda sobre la de
            // BD (solo en memoria, sin ->save()): el frente puede existir con UBICACION
            // vacia o desactualizada y el usuario la esta corrigiendo para ESTE acta. Sin
            // esto el campo se ignoraba y el PDF salia sin "ubicado en ...".
            if ($destUbicac !== '') {
                $frenteDestino->UBICACION = $destUbicac;
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
            'type'  => 'nullable|in:equipo,auxiliar',
        ]);

        // Los auxiliares viven en otra tabla: sin esto los ID_AUXILIAR se buscaban
        // contra `equipos` y la precarga del formulario (origen/zona/firmas) salia vacia.
        // extractFirmasActa() solo lee CATEGORIA_FLOTA de la coleccion → los auxiliares
        // no la tienen y caen a FLOTA LIVIANA, igual que en previewActaLote().
        $equipos = ($data['type'] ?? 'equipo') === 'auxiliar'
            ? \App\Models\EquipoAuxiliar::whereIn('ID_AUXILIAR', $data['ids'])->get()
            : \App\Models\Equipo::whereIn('ID_EQUIPO', $data['ids'])->get();
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
                // NOMBRE_FRENTE congelado al momento del movimiento (ver
                // Movilizacion::getNombreOrigenAttribute) — no el nombre actual del frente.
                'frente_origen'  => $m->frenteOrigen ? ['ID_FRENTE' => $m->frenteOrigen->ID_FRENTE, 'NOMBRE_FRENTE' => $m->nombre_origen] : null,
                'frente_destino' => $m->frenteDestino ? ['ID_FRENTE' => $m->frenteDestino->ID_FRENTE, 'NOMBRE_FRENTE' => $m->nombre_destino] : null,
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
            $origen = $equipo->ID_FRENTE_ACTUAL ?? 1;
            Movilizacion::create([
                'CODIGO_CONTROL'    => $nextId,
                'ID_EQUIPO'         => $request->ID_EQUIPO,
                'ID_FRENTE_ORIGEN'  => $origen,
                'ID_FRENTE_DESTINO' => $request->ID_FRENTE_DESTINO,
                // Nombre congelado al momento del movimiento — ver Movilizacion::getNombreOrigenAttribute.
                'NOMBRE_FRENTE_ORIGEN_SNAPSHOT'  => FrenteTrabajo::find($origen)?->NOMBRE_FRENTE,
                'NOMBRE_FRENTE_DESTINO_SNAPSHOT' => FrenteTrabajo::find($request->ID_FRENTE_DESTINO)?->NOMBRE_FRENTE,
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
                    // Deshacer = "como si nunca hubiera pasado": el despacho dejó el equipo en
                    // PENDIENTE (0); al volver al frente ORIGEN —donde estaba confirmado— lo
                    // restauramos a CONFIRMADO (1). (No guardamos el valor previo en historial;
                    // 1 es el caso normal: un equipo en su frente está confirmado.)
                    $equipo->CONFIRMADO_EN_SITIO = 1;
                    $equipo->save();
                }
            } elseif ($mov->ID_AUXILIAR) {
                $aux = \App\Models\EquipoAuxiliar::lockForUpdate()->find($mov->ID_AUXILIAR);
                if ($aux) {
                    if ((int) $aux->ID_FRENTE_ACTUAL !== (int) $mov->ID_FRENTE_DESTINO) {
                        DB::rollBack();
                        return response()->json(['success' => false, 'message' => 'No se puede deshacer: el auxiliar ya fue movilizado a otro frente después de esta.'], 422);
                    }
                    // Mismo criterio que equipos: al volver al frente ORIGEN se restaura
                    // CONFIRMADO_EN_SITIO=1 ("como si nunca hubiera pasado").
                    $aux->update(['ID_FRENTE_ACTUAL' => $mov->ID_FRENTE_ORIGEN, 'CONFIRMADO_EN_SITIO' => 1]);
                }
            }

            // Borrado DURO (Movilizacion no usa SoftDeletes) → no deja rastro.
            $mov->delete();

            DB::commit();

            // Un borrado duro NO se detecta por huella: si la fila borrada no era la
            // última, MAX(ID) y MAX(updated_at) siguen igual y el cliente offline se
            // quedaría con la movilización deshecha para siempre. El reseteo le pide la
            // copia completa del dominio. Va tras el commit para no resetear un rollback.
            \App\Support\OfflineVersion::resetear('equipos');


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
            // Borrado masivo por query builder: no dispara eventos de modelo y el delta
            // no puede detectar filas que desaparecen. Se pide la copia completa.
            \App\Support\OfflineVersion::resetear('equipos');
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
