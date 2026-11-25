<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\Consumibles\ColumnasConIA;
use App\Services\Consumibles\LectorExcelCombustible;
use App\Services\LectorGemini;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\MySqlTestCase;

/**
 * Respaldo con IA para las columnas de una tabla de despachos (ColumnasConIA), con una IA de
 * mentira que contesta lo que se le diga: se usa su respuesta solo si cuadra con los datos, se
 * recuerda para no gastar cupo otra vez, y sin IA todo sigue como antes.
 */
class ColumnasConIATest extends MySqlTestCase
{
    /** Encabezados que las reglas no reconocen ("Día", "Lts entregados"), con columnas de más. */
    private const FILAS = [
        ['REPORTE SEMANAL DE COMBUSTIBLE'],
        ['Item', 'Día', 'Hora', 'Unidad', 'Matrícula', 'Conductor', 'Km', 'Lts entregados'],
        [1, '03/11/2026', '07:30', 'VOLQUETA JAC', 'A45AF4Y', 'PEDRO PEREZ', 15230, 120],
        [2, '04/11/2026', '09:10', 'CAMION CISTERNA', 'A12BC3D', 'LUIS DIAZ', 88000, 200],
        [3, '05/11/2026', '10:45', 'VOLQUETA JAC', 'A45AF4Y', 'PEDRO PEREZ', 15410, '95,5'],
    ];

    private function ia(?array $respuesta, int &$preguntas = 0): LectorGemini
    {
        return new class($respuesta, $preguntas) extends LectorGemini {
            public function __construct(private ?array $r, private int &$n) {}
            public function disponible(): bool { return true; }
            public function preguntarJson(string $instruccion, int $intentos = 2): ?array { $this->n++; return $this->r; }
        };
    }

    private function excel(): string
    {
        $libro = new Spreadsheet();
        foreach (self::FILAS as $r => $fila) {
            foreach ($fila as $c => $v) {
                $libro->getActiveSheet()->setCellValue([$c + 1, $r + 1], $v);
            }
        }
        $ruta = tempnam(sys_get_temp_dir(), 'colia') . '.xlsx';
        (new Xlsx($libro))->save($ruta);

        return $ruta;
    }

    public function test_el_lector_usa_las_columnas_que_dice_la_ia(): void
    {
        $preguntas = 0;
        $ia = new ColumnasConIA($this->ia(['fila_encabezado' => 2, 'columnas' => [
            'fecha' => 2, 'salida' => 8, 'equipo' => 4, 'placa' => 5, 'resp' => 6,
        ]], $preguntas));
        $ruta = $this->excel();
        try {
            $r = (new LectorExcelCombustible($ia))->leer($ruta, 'raro.xlsx');
        } finally {
            @unlink($ruta);
        }

        $this->assertCount(3, $r['filas']);
        $this->assertSame('2026-11-03', $r['filas'][0]['fecha']);
        $this->assertEquals(120, $r['filas'][0]['litros']);
        $this->assertEquals(95.5, $r['filas'][2]['litros']);
        $this->assertSame('A45AF4Y', $r['filas'][0]['placa']);
        $this->assertSame(1, $preguntas);
    }

    public function test_una_respuesta_que_no_cuadra_con_los_datos_no_se_usa(): void
    {
        // La «cantidad» que dice es la hora y la «fecha», el nombre del conductor.
        $ia = new ColumnasConIA($this->ia(['fila_encabezado' => 2, 'columnas' => ['fecha' => 6, 'salida' => 3, 'placa' => 5]]));
        $ruta = $this->excel();
        try {
            $r = (new LectorExcelCombustible($ia))->leer($ruta, 'raro.xlsx');
        } finally {
            @unlink($ruta);
        }

        $this->assertSame([], $r['filas']);
    }

    public function test_sin_ia_la_hoja_se_ignora_como_antes(): void
    {
        $ia = new ColumnasConIA($this->ia(null));
        $ruta = $this->excel();
        try {
            $r = (new LectorExcelCombustible($ia))->leer($ruta, 'raro.xlsx');
        } finally {
            @unlink($ruta);
        }

        $this->assertSame([], $r['hojas']);
    }

    public function test_el_mismo_formato_no_vuelve_a_preguntar(): void
    {
        $preguntas = 0;
        $ia = new ColumnasConIA($this->ia(['fila_encabezado' => null, 'columnas' => ['fecha' => 1, 'cantidad' => 3]], $preguntas));
        $filas = [['01/11/2026', 'A45AF4Y', '120'], ['02/11/2026', 'A12BC3D', '80']];

        $this->assertSame(['fila' => null, 'mapa' => ['fecha' => 0, 'cantidad' => 2]], $ia->reconocer($filas, ['fecha' => 'f', 'cantidad' => 'c'], 'fecha', 'cantidad'));
        $ia->reconocer($filas, ['fecha' => 'f', 'cantidad' => 'c'], 'fecha', 'cantidad');
        $this->assertSame(1, $preguntas);
    }

    public function test_un_kilometraje_no_pasa_por_fecha(): void
    {
        // La IA dice que la «fecha» es el km (números de 5 cifras, como una fecha de Excel).
        $ia = new ColumnasConIA($this->ia(['fila_encabezado' => null, 'columnas' => ['fecha' => 1, 'cantidad' => 2]]));
        $filas = [[15230, 120], [88000, 200], [15410, 95]];

        $this->assertNull($ia->reconocer($filas, ['fecha' => 'f', 'cantidad' => 'c'], 'fecha', 'cantidad'));
    }

    public function test_una_respuesta_rechazada_no_se_recuerda(): void
    {
        $preguntas = 0;
        $ia = new ColumnasConIA($this->ia(['fila_encabezado' => null, 'columnas' => ['fecha' => 2, 'cantidad' => 1]], $preguntas));
        $filas = [['01/11/2026', 'A45AF4Y', '120'], ['02/11/2026', 'A12BC3D', '80']];

        $this->assertNull($ia->reconocer($filas, ['fecha' => 'f', 'cantidad' => 'c'], 'fecha', 'cantidad'));
        $ia->reconocer($filas, ['fecha' => 'f', 'cantidad' => 'c'], 'fecha', 'cantidad');
        $this->assertSame(2, $preguntas, 'una respuesta que no cuadró se vuelve a preguntar');
    }

    public function test_una_hoja_sin_fechas_no_gasta_cupo(): void
    {
        $preguntas = 0;
        $ia = new ColumnasConIA($this->ia(null, $preguntas));
        $libro = new Spreadsheet();
        $libro->getActiveSheet()->fromArray([['ITEM', 'EQUIPO', 'SERIAL', 'PLACA'], [1, 'VOLQUETA', 'X1', 'A45AF4Y'], [2, 'CAMION', 'X2', 'A12BC3D']]);
        $ruta = tempnam(sys_get_temp_dir(), 'colia') . '.xlsx';
        (new Xlsx($libro))->save($ruta);
        try {
            (new LectorExcelCombustible($ia))->leer($ruta, 'catalogo.xlsx');
        } finally {
            @unlink($ruta);
        }

        $this->assertSame(0, $preguntas);
    }

    public function test_una_celda_que_no_es_texto_da_422(): void
    {
        $u = Usuario::query()->whereRaw("FIND_IN_SET('super.admin', PERMISOS)")->firstOrFail();

        $this->actingAs($u)->postJson(route('consumibles.importar.columnas'), ['filas' => [[['x'], '1'], ['a', 'b']]])
            ->assertStatus(422);
    }

    public function test_la_tabla_de_cargar_consumibles_pide_las_columnas_por_http(): void
    {
        $this->app->instance(LectorGemini::class, $this->ia(['fila_encabezado' => null, 'columnas' => [
            'fecha' => 2, 'id' => 4, 'nombre' => 5, 'cantidad' => 7, 'origen' => 8,
        ]]));
        $u = Usuario::query()->whereRaw("FIND_IN_SET('super.admin', PERMISOS)")->firstOrFail();

        $this->actingAs($u)->postJson(route('consumibles.importar.columnas'), ['filas' => [
            ['7', '10/11/2026', '08:15', 'A45AF4Y', 'PEDRO PEREZ', '15500', '140', 'GUIA 45'],
            ['8', '11/11/2026', '09:40', 'A12BC3D', 'LUIS DIAZ', '88200', '210', 'GUIA 46'],
        ]])->assertOk()->assertExactJson(['fila' => null, 'mapa' => [
            'fecha' => 1, 'id' => 3, 'nombre' => 4, 'cantidad' => 6, 'origen' => 7,
        ]]);
    }
}
