<?php

namespace App\Http\Controllers;

use App\Models\ConsumibleImportacion;
use App\Services\Consumibles\ColumnasConIA;
use App\Services\Consumibles\ImportadorCombustible;
use App\Support\MensajeError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Cargar consumibles → Excel de gasoil. Sube los reportes de los proyectos tal como llegan
 * (cualquiera de los formatos) y devuelve sus despachos para la tabla de Cargar consumibles,
 * donde se ven y se corrigen como lo pegado; al guardar la tabla (ConsumiblesController::
 * guardarLote) entran solo los que no estaban. Aquí, además, las columnas de lo pegado y
 * deshacer una carga. La lógica vive en App\Services\Consumibles; aquí solo HTTP. Todo pide
 * super.admin (las rutas), igual que guardar lo pegado a mano.
 */
class ConsumiblesImportacionController extends Controller
{
    /** Filas que se leen de un reporte pegado; más que eso, mejor subir el archivo. */
    private const MAX_FILAS_PEGADAS = 20000;

    /**
     * Lee los Excel y deja la carga en borrador (sus filas con las huellas de repetidos);
     * responde con las filas para la tabla y cuántas ya estaban cargadas (se saltan al guardar).
     */
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

        return $this->aLaTabla($request->file('archivos'), $importador);
    }

    /**
     * El reporte de gasoil copiado del Excel y pegado (Ctrl+V) en la tabla, con sus encabezados:
     * texto con tabuladores. Se arma un libro con él y entra por el MISMO lector que un archivo
     * subido, así lleva las huellas de repetidos: al guardar se saltan los despachos ya cargados
     * (los reportes son acumulados: el de septiembre repite abril–agosto). Todo como texto: un
     * «=» no se vuelve fórmula; una celda con salto de línea llega entre comillas y es UNA celda.
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

            return $this->aLaTabla([new UploadedFile($ruta, 'Tabla pegada.xlsx', null, null, true)], $importador);
        } finally {
            fclose($tsv);
            $libro->disconnectWorksheets();
            @unlink($ruta);
            @unlink($base);
        }
    }

    /** Lee los archivos, deja la carga en borrador y responde con sus filas para la tabla. */
    private function aLaTabla(array $archivos, ImportadorCombustible $importador): JsonResponse
    {
        @set_time_limit(180);
        try {
            $imp = $importador->crearBorrador($archivos, auth()->id());
            $tabla = $importador->paraTabla($imp);
        } catch (\App\Services\Consumibles\CargaRechazada $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se pudieron leer los archivos: ' . MensajeError::para($e, MensajeError::REINTENTAR)], 500);
        }

        return response()->json($tabla + [
            'importacion' => $imp->ID_IMPORTACION,
            'avisos'      => $imp->ARCHIVOS['errores'] ?? [],
        ]);
    }

    /**
     * Columnas de unas filas pegadas en la tabla de Cargar consumibles que las reglas no
     * reconocieron (encabezados raros o sin encabezado): se le pregunta a la IA (ColumnasConIA).
     * Responde {fila, mapa} con índices desde 0, o {fila:null, mapa:null} si no se pudo: entonces
     * la página pega por posición, como siempre.
     */
    public function columnas(Request $request, ColumnasConIA $ia): JsonResponse
    {
        $request->validate([
            'filas'   => 'required|array|min:2|max:20',
            'filas.*' => 'array|max:30',
            'filas.*.*' => 'nullable|string|max:500',
            'espec'   => 'boolean',
        ]);
        $campos = [
            'fecha'    => 'fecha del despacho',
            'id'       => 'placa, serial o identificador del equipo (si no hay, la descripción del vehículo o equipo)',
            'nombre'   => 'nombre de quien recibe (responsable, operador, chofer)',
            'ci'       => 'cédula de quien recibe',
            'cantidad' => 'cantidad despachada (litros o unidades; la salida, no la entrada al tanque)',
            'frente'   => 'proyecto, obra o frente de trabajo donde se usó',
        ];
        if ($request->boolean('espec')) {
            $campos['espec'] = 'viscosidad del aceite o medida/modelo del caucho';
        }
        $filas = array_map(fn ($f) => array_map(fn ($v) => mb_substr((string) $v, 0, 100), array_values((array) $f)), $request->input('filas'));
        $r = $ia->reconocer($filas, $campos, 'fecha', 'cantidad');

        return response()->json($r ?? ['fila' => null, 'mapa' => null]);
    }

    public function deshacer(ConsumibleImportacion $importacion, ImportadorCombustible $importador): JsonResponse
    {
        try {
            $n = $importador->deshacer($importacion);
        } catch (\App\Services\Consumibles\CargaRechazada $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => number_format($n, 0, ',', '.') . ' consumos borrados.']);
    }
}
