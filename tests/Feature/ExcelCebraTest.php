<?php

namespace Tests\Feature;

use App\Models\Equipo;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\MySqlTestCase;

/**
 * La cebra de los Excel (rellenarFilas en ExcelLogoCorporativo) copia el estilo ENTERO de la
 * primera celda de la fila. Si se pinta después de alinear, las filas grises pierden la
 * alineación de cada columna: en el inventario el stock salía centrado en esas filas.
 */
class ExcelCebraTest extends MySqlTestCase
{
    private function hoja($resp): Worksheet
    {
        $base = $resp->baseResponse;
        $ruta = tempnam(sys_get_temp_dir(), 'cebra') . '.xlsx';
        if (method_exists($base, 'getFile')) {
            copy($base->getFile()->getPathname(), $ruta);
        } else {
            ob_start();
            $base->sendContent();
            file_put_contents($ruta, (string) ob_get_clean());
        }
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        @unlink($ruta);
        return $hoja;
    }

    public function test_equipos_alterna_blanco_y_gris_sin_perder_la_alineacion(): void
    {
        $prefijo = 'TESTCEBRA' . strtoupper(substr(uniqid(), -6));
        $ids = [];
        foreach ([1, 2, 3] as $n) {
            $ids[] = Equipo::create(['MARCA' => 'PRUEBA', 'MODELO' => 'CEBRA', 'ANIO' => 2026, 'SERIAL_CHASIS' => $prefijo . $n])->ID_EQUIPO;
        }

        $hoja = $this->hoja($this->actingAs($this->superAdminGlobal())
            ->post(route('equipos.export'), ['ids' => implode(',', $ids)])->assertOk());

        $this->assertSame(['FFFFFFFF', 'FFF1F5F9', 'FFFFFFFF'],
            array_map(fn ($f) => $hoja->getStyle('K' . $f)->getFill()->getStartColor()->getARGB(), [6, 7, 8]));
        foreach ([6, 7, 8] as $f) {
            $this->assertSame(Alignment::HORIZONTAL_CENTER, $hoja->getStyle('A' . $f)->getAlignment()->getHorizontal(), "N° fila $f");
            $this->assertSame(Alignment::HORIZONTAL_LEFT, $hoja->getStyle('N' . $f)->getAlignment()->getHorizontal(), "TITULAR fila $f");
            $this->assertTrue($hoja->getStyle('Z' . $f)->getAlignment()->getWrapText(), "ajuste de texto fila $f");
        }
    }

    public function test_inventario_deja_el_stock_a_la_derecha_tambien_en_las_filas_pintadas(): void
    {
        $ids = DB::table('almacen_stock')->where('CANTIDAD', '>', 0)->distinct()->limit(40)->pluck('ID_PRODUCTO')->all();
        if (count($ids) < 3) {
            $this->markTestSkipped('Hacen falta productos con stock en la base.');
        }

        $hoja = $this->hoja($this->actingAs($this->superAdminGlobal())
            ->post('/admin/almacen/export', ['id_almacen' => 'all', 'ids' => implode(',', $ids)])->assertOk());

        $ultimaCol = $hoja->getHighestDataColumn();
        $pintadas  = 0;
        for ($f = 6; $f < $hoja->getHighestDataRow(); $f++) {
            if ($hoja->getStyle('A' . $f)->getFill()->getFillType() !== null
                && $hoja->getStyle('A' . $f)->getFill()->getFillType() !== 'none') {
                $pintadas++;
            }
            $this->assertSame(Alignment::HORIZONTAL_RIGHT, $hoja->getStyle($ultimaCol . $f)->getAlignment()->getHorizontal(), "stock de la fila $f");
            $this->assertNotSame(Alignment::HORIZONTAL_CENTER, $hoja->getStyle('C' . $f)->getAlignment()->getHorizontal(), "producto de la fila $f");
        }
        $this->assertGreaterThan(0, $pintadas, 'alguna fila tiene que ir pintada (cebra o bajo mínimo)');
    }
}
