<?php

namespace App\Http\Controllers;

use App\Models\ConsumibleImportacion;
use App\Services\Consumibles\ImportadorCombustible;
use App\Services\Consumibles\TextoConsumo;
use App\Support\MensajeError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Facades\DB;

/**
 * Cargar consumibles → Excel de gasoil. Sube los reportes de los proyectos tal como llegan
 * (cualquiera de los formatos), muestra la vista previa y guarda solo lo nuevo. La subida y
 * las cargas anteriores están en la pantalla de Cargar (ConsumiblesController::cargar);
 * aquí, lo demás. La lógica vive en App\Services\Consumibles; aquí solo HTTP. Todo pide
 * super.admin (las rutas), igual que guardar lo pegado a mano.
 */
class ConsumiblesImportacionController extends Controller
{
    /** Filas que se leen de una tabla pegada; más que eso, mejor subir el archivo. */
    private const MAX_FILAS_PEGADAS = 20000;

    public function subir(Request $request, ImportadorCombustible $importador): JsonResponse
    {
        $request->validate([
            'archivos'   => 'required|array|min:1|max:20',
            'archivos.*' => 'file|mimes:xlsx,xlsm,xls|max:20480',
        ], [
            'archivos.required' => 'Elige al menos un Excel.',
            'archivos.*.mimes'  => 'Solo archivos de Excel (.xlsx, .xlsm, .xls).',
            'archivos.*.max'    => 'Cada archivo puede pesar hasta 20 MB.',
        ]);

        return $this->borrador($request->file('archivos'), $importador);
    }

    /**
     * La tabla copiada del Excel y pegada (Ctrl+V) en Cargar consumibles: texto con
     * tabuladores, encabezado incluido. Se arma un libro con ella y entra por el MISMO lector
     * que un archivo subido: columnas por su encabezado (las demás se ignoran), repetidos
     * fuera, equipo y frente identificados, revisión y deshacer. Todo como texto: un «=» del
     * Excel no se vuelve fórmula. Se lee como TSV de verdad: una celda con salto de línea
     * (Alt+Enter) llega entre comillas y sigue siendo UNA celda. Ojo: lo pegado es lo que el
     * Excel MUESTRA (una columna redondeada llega redondeada); el archivo trae el valor exacto.
     */
    public function pegar(Request $request, ImportadorCombustible $importador): JsonResponse
    {
        $request->validate(['texto' => 'required|string|max:5000000'], ['texto.required' => 'No hay nada pegado.']);

        $libro = new Spreadsheet();
        $base = tempnam(sys_get_temp_dir(), 'pegado');
        $ruta = $base . '.xlsx';
        $tsv = fopen('php://temp', 'r+');
        try {
            fwrite($tsv, (string) $request->input('texto'));
            rewind($tsv);
            $hoja = $libro->getActiveSheet();
            $fila = 0;
            while (($celdas = fgetcsv($tsv, 0, "\t", '"', '')) !== false) {
                if (++$fila > self::MAX_FILAS_PEGADAS) {
                    return response()->json(['message' => 'Se pueden pegar hasta '
                        . number_format(self::MAX_FILAS_PEGADAS, 0, ',', '.') . ' filas. Para más, sube el archivo de Excel.'], 422);
                }
                // Hasta 60 columnas, como el lector con un archivo (LectorExcelCombustible::MAX_COLUMNAS).
                foreach (array_slice($celdas, 0, 60) as $c => $v) {
                    $v = trim((string) $v);
                    if ($v !== '') {
                        $hoja->setCellValueExplicit(Coordinate::stringFromColumnIndex($c + 1) . $fila, $v, DataType::TYPE_STRING);
                    }
                }
            }
            (new Xlsx($libro))->save($ruta);

            return $this->borrador([new UploadedFile($ruta, 'Tabla pegada.xlsx', null, null, true)], $importador);
        } finally {
            fclose($tsv);
            $libro->disconnectWorksheets();
            @unlink($ruta);
            @unlink($base);
        }
    }

    /**
     * Columnas de unas filas pegadas en la tabla de Cargar consumibles que las reglas no
     * reconocieron (encabezados raros o sin encabezado): se le pregunta a la IA (ColumnasConIA).
     * Responde {fila, mapa} con índices desde 0, o {fila:null, mapa:null} si no se pudo: entonces
     * la página pega por posición, como siempre.
     */
    public function columnas(Request $request, \App\Services\Consumibles\ColumnasConIA $ia): JsonResponse
    {
        $request->validate([
            'filas'   => 'required|array|min:2|max:20',
            'filas.*' => 'array|max:30',
            'espec'   => 'boolean',
        ]);
        $campos = [
            'fecha'    => 'fecha del despacho',
            'id'       => 'placa, serial o identificador del equipo (si no hay, la descripción del vehículo o equipo)',
            'nombre'   => 'nombre de quien recibe (responsable, operador, chofer)',
            'ci'       => 'cédula de quien recibe',
            'cantidad' => 'cantidad despachada (litros o unidades; la salida, no la entrada al tanque)',
            'origen'   => 'origen del suministro (cisterna, guía, proveedor)',
        ];
        if ($request->boolean('espec')) {
            $campos['espec'] = 'viscosidad del aceite o medida/modelo del caucho';
        }
        $filas = array_map(fn ($f) => array_map(fn ($v) => mb_substr((string) $v, 0, 100), array_values((array) $f)), $request->input('filas'));
        $r = $ia->reconocer($filas, $campos, 'fecha', 'cantidad');

        return response()->json($r ?? ['fila' => null, 'mapa' => null]);
    }

    /** Lee los archivos y deja la carga en borrador; responde con la URL de su revisión. */
    private function borrador(array $archivos, ImportadorCombustible $importador): JsonResponse
    {
        @set_time_limit(180);
        try {
            $imp = $importador->crearBorrador($archivos, auth()->id());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se pudieron leer los archivos: ' . MensajeError::para($e, MensajeError::REINTENTAR)], 500);
        }

        return response()->json(['url' => route('consumibles.importar.revisar', $imp)]);
    }

    public function revisar(ConsumibleImportacion $importacion, ImportadorCombustible $importador)
    {
        if ($importacion->ESTADO !== ConsumibleImportacion::BORRADOR) {
            return redirect()->route('consumibles.cargar')
                ->with('success', 'Esa carga ya fue ' . ($importacion->ESTADO === ConsumibleImportacion::APLICADA ? 'aplicada.' : 'deshecha.'));
        }
        @set_time_limit(120);
        $rev = $importador->revisar($importacion);

        return view('admin.consumibles.importar_revision', [
            'imp'     => $importacion,
            'resumen' => $rev['resumen'],
            'frentes' => $rev['frentes'],
        ]);
    }

    public function ensenar(Request $request, ConsumibleImportacion $importacion, ImportadorCombustible $importador): JsonResponse
    {
        // Pares [clave, valor] y no un objeto clave → valor: los textos del Excel pueden traer
        // puntos («ING. RESIDENTE») y la validación de Laravel los leería como anidamiento.
        $datos = $request->validate([
            'frentes'         => 'nullable|array|max:500',
            'frentes.*.clave' => 'required|string|max:191',
            'frentes.*.id'    => 'required|integer',
            'equipos'         => 'nullable|array|max:2000',
            'equipos.*.clave' => 'required|string|max:191',
            'equipos.*.ref'   => ['required', 'string', 'regex:/^(E:\d+|A:\d+|NO)$/'],
        ]);
        $n = $importador->ensenar(
            array_column($datos['frentes'] ?? [], 'id', 'clave'),
            array_column($datos['equipos'] ?? [], 'ref', 'clave'),
            auth()->id()
        );

        return response()->json(['ok' => true, 'guardados' => $n]);
    }

    public function aplicar(Request $request, ConsumibleImportacion $importacion, ImportadorCombustible $importador): JsonResponse
    {
        $datos = $request->validate([
            'meses'   => 'required|array|min:1',
            'meses.*' => ['string', 'regex:/^\d{4}-\d{2}$/'],
        ], ['meses.required' => 'Marca al menos un mes.']);

        @set_time_limit(180);
        try {
            $r = $importador->aplicar($importacion, $datos['meses']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se guardó nada: ' . MensajeError::para($e, MensajeError::REINTENTAR)], 500);
        }

        return response()->json([
            'message' => number_format($r['insertados'], 0, ',', '.') . ' consumos cargados (' . number_format($r['litros'], 0, ',', '.') . ' L).',
            'url'     => route('consumibles.index', ['tipo' => 'GASOIL']),
        ]);
    }

    public function deshacer(ConsumibleImportacion $importacion, ImportadorCombustible $importador): JsonResponse
    {
        try {
            $n = $importador->deshacer($importacion);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => number_format($n, 0, ',', '.') . ' consumos borrados.']);
    }

    public function descartar(ConsumibleImportacion $importacion): JsonResponse
    {
        if ($importacion->ESTADO !== ConsumibleImportacion::BORRADOR) {
            return response()->json(['message' => 'Solo se descarta una carga sin aplicar.'], 422);
        }
        $importacion->delete();

        return response()->json(['ok' => true]);
    }

    /** Equipos y auxiliares por placa, serial, código, marca, modelo o tipo (para enseñar). */
    public function buscarEquipo(Request $request): JsonResponse
    {
        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

        $equipos = DB::table('equipos')
            ->leftJoin('tipo_equipos', 'tipo_equipos.id', '=', 'equipos.id_tipo_equipo')
            ->leftJoin('documentacion', 'documentacion.ID_EQUIPO', '=', 'equipos.ID_EQUIPO')
            ->whereNull('equipos.deleted_at')
            ->where(fn ($w) => $w->where('documentacion.PLACA', 'like', $like)
                ->orWhere('equipos.SERIAL_CHASIS', 'like', $like)
                ->orWhere('equipos.SERIAL_DE_MOTOR', 'like', $like)
                ->orWhere('equipos.CODIGO_PATIO', 'like', $like)
                ->orWhere('equipos.NUMERO_ETIQUETA', 'like', $like)
                ->orWhere('equipos.MODELO', 'like', $like)
                ->orWhere('equipos.MARCA', 'like', $like)
                ->orWhere('tipo_equipos.nombre', 'like', $like))
            ->select('equipos.ID_EQUIPO', 'equipos.MARCA', 'equipos.MODELO', 'equipos.SERIAL_CHASIS', 'documentacion.PLACA', 'tipo_equipos.nombre as tipo')
            ->limit(15)->get()
            ->map(fn ($e) => [
                'ref'   => 'E:' . $e->ID_EQUIPO,
                'texto' => implode(' · ', array_filter([$e->tipo, trim($e->MARCA . ' ' . $e->MODELO), $e->PLACA, $e->SERIAL_CHASIS])),
            ]);

        $aux = DB::table('equipos_auxiliares')->whereNull('deleted_at')
            ->where(fn ($w) => $w->where('SERIAL', 'like', $like)->orWhere('CODIGO_INTERNO', 'like', $like)
                ->orWhere('MODELO', 'like', $like)->orWhere('MARCA', 'like', $like)
                ->orWhere('TIPO', 'like', '%' . str_replace(' ', '_', TextoConsumo::norm($q)) . '%'))
            ->select('ID_AUXILIAR', 'TIPO', 'MARCA', 'MODELO', 'SERIAL', 'CODIGO_INTERNO')
            ->limit(10)->get()
            ->map(fn ($a) => [
                'ref'   => 'A:' . $a->ID_AUXILIAR,
                'texto' => implode(' · ', array_filter([str_replace('_', ' ', $a->TIPO), trim($a->MARCA . ' ' . $a->MODELO), $a->CODIGO_INTERNO, $a->SERIAL])),
            ]);

        return response()->json($equipos->concat($aux)->values());
    }
}
