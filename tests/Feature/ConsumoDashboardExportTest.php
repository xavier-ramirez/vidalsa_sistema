<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * El botón de descarga del Dashboard de Consumo baja un XLSX con los DATOS filtrados
 * (antes bajaba una foto PNG de los gráficos).
 *
 * Lo que fijan estas pruebas: que el archivo salga como XLSX de verdad y que respete los
 * MISMOS filtros del modal. Backend y front comparten el armado del filtro
 * (AlmacenController::consumoDashboardQuery y window._cdashParams), asi que el Excel no
 * puede traer un universo distinto al de la pantalla.
 */
class ConsumoDashboardExportTest extends MySqlTestCase
{
    private function usuarioConAlmacen(): Usuario
    {
        $user = Usuario::all()->first(
            fn ($u) => \App\Models\Almacen::visiblesPara($u)->exists()
        );
        $this->assertNotNull($user, 'No hay usuario con almacenes visibles.');

        return $user;
    }

    /**
     * Primeros bytes del archivo descargado. response()->download() devuelve un
     * BinaryFileResponse (no streamed), asi que el contenido se lee del fichero temporal
     * y no con streamedContent().
     */
    private function firmaDelArchivo($resp): string
    {
        $file = $resp->baseResponse->getFile();
        $this->assertNotNull($file, 'La respuesta no trae un archivo adjunto.');

        return (string) file_get_contents($file->getPathname(), false, null, 0, 2);
    }

    public function test_descarga_un_xlsx_de_verdad(): void
    {
        $resp = $this->actingAs($this->usuarioConAlmacen())
            ->get(route('almacen.consumoDashboardExport'));

        $resp->assertOk();
        $resp->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $nombre = $resp->headers->get('content-disposition');
        $this->assertStringContainsString('.xlsx', (string) $nombre);
        $this->assertStringContainsString('Dashboard_Consumo_', (string) $nombre);

        // "PK" es la firma de un ZIP, que es lo que a fin de cuentas es un .xlsx. Sin esto
        // una respuesta HTML de error pasaria como archivo valido.
        $this->assertSame('PK', $this->firmaDelArchivo($resp));
    }

    /** @dataProvider filtrosDelModal */
    public function test_acepta_los_mismos_filtros_del_modal(array $filtros): void
    {
        $resp = $this->actingAs($this->usuarioConAlmacen())
            ->get(route('almacen.consumoDashboardExport', $filtros));

        $resp->assertOk();
        $this->assertSame('PK', $this->firmaDelArchivo($resp));
    }

    public static function filtrosDelModal(): array
    {
        return [
            'rango de meses'  => [['desde' => '2026-01-01', 'hasta' => '2026-12-31']],
            'categoria'       => [['categoria' => 'FILTROS']],
            'descripcion'     => [['descripcion' => 'ACEITE']],
            'frente'          => [['frente' => '43']],
            'todos juntos'    => [['desde' => '2026-01-01', 'hasta' => '2026-12-31',
                                   'categoria' => 'FILTROS', 'descripcion' => 'A', 'frente' => '43']],
            'sin resultados'  => [['descripcion' => 'ZZZZ_NO_EXISTE_ZZZZ']],
        ];
    }

    public function test_exige_sesion(): void
    {
        $this->get(route('almacen.consumoDashboardExport'))->assertRedirect();
    }

    /** Carga el libro descargado por la ruta del export, con los filtros que se le pasen. */
    private function libroDescargado(array $filtros = []): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $resp = $this->actingAs($this->usuarioConAlmacen())
            ->get(route('almacen.consumoDashboardExport', $filtros));
        $resp->assertOk();

        // Se lee el XLSX DE VERDAD con PhpSpreadsheet, no se buscan cadenas en el binario:
        // el texto de un xlsx va comprimido dentro de un ZIP, asi que un
        // assertStringContainsString sobre el fichero pasaria SIEMPRE y no probaria nada.
        return \PhpOffice\PhpSpreadsheet\IOFactory::load($resp->baseResponse->getFile()->getPathname());
    }

    /** Todos los textos no vacios de una hoja, para poder afirmar sobre su contenido. */
    private function textosDe(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $hoja): array
    {
        $out = [];
        foreach ($hoja->toArray(null, true, false, false) as $fila) {
            foreach ($fila as $v) {
                if ($v !== null && trim((string) $v) !== '') $out[] = trim((string) $v);
            }
        }

        return $out;
    }

    /**
     * La primera hoja es el CONSUMO GENERAL, con el encabezado corporativo de la app.
     *
     * Hasta aqui las pruebas solo miraban que bajara un XLSX de verdad, asi que la FORMA
     * del libro no la sujetaba nadie: se le podia cambiar la estructura entera sin romper
     * un solo test. Esto fija lo que el cliente abre.
     */
    public function test_la_primera_hoja_es_el_consumo_general_con_encabezado(): void
    {
        $libro = $this->libroDescargado();
        $hoja  = $libro->getSheet(0);

        $this->assertSame('GENERAL', $hoja->getTitle());

        $textos = $this->textosDe($hoja);
        $this->assertContains('PRODUCTO', $textos);
        $this->assertContains('UM', $textos);
        $this->assertContains('CANTIDAD', $textos);

        // Encabezado corporativo: titulo, bloque EDICION/REVISION/FECHA y el LOGO.
        $this->assertStringContainsString('DASHBOARD DE CONSUMO', $hoja->getCell('B1')->getValue());
        $this->assertStringContainsString('CONSUMO GENERAL', $hoja->getCell('B1')->getValue());
        $this->assertSame('EDICION: 1', $hoja->getCell('D1')->getValue());
        $this->assertSame('REVISION: 0', $hoja->getCell('D2')->getValue());
        $this->assertCount(1, $hoja->getDrawingCollection(), 'La hoja debe llevar el logo corporativo.');

        // La estructura vieja (dos bloques lado a lado, con sus totales) ya no existe.
        $this->assertNotContains('CONSUMO POR MES', $textos);
        $this->assertNotContains('CONSUMO POR PRODUCTO', $textos);
        $this->assertNotContains('CONSUMO POR ALMACÉN', $textos);
        $this->assertNotContains('TOTAL', $textos);
    }

    /**
     * Con un rango de VARIOS meses, detras de la general va una hoja por mes; filtrando UN
     * solo mes no se añade ninguna, porque seria una copia exacta de la general.
     */
    public function test_una_hoja_por_mes_solo_si_el_rango_abarca_varios(): void
    {
        // Rango amplio: la general + las hojas mensuales que haya con consumo.
        $libro  = $this->libroDescargado(['desde' => '2026-01', 'hasta' => '2026-12']);
        $hojas  = $libro->getSheetNames();
        $this->assertSame('GENERAL', $hojas[0]);

        $mensuales = array_slice($hojas, 1);
        foreach ($mensuales as $h) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $h, 'Las hojas de mes se llaman YYYY-MM.');
        }
        if ($mensuales) {
            // Van en orden y cada una repite la misma cabecera de tabla.
            $ordenadas = $mensuales;
            sort($ordenadas);
            $this->assertSame($ordenadas, $mensuales, 'Los meses van en orden ascendente.');

            $primera = $libro->getSheetByName($mensuales[0]);
            $this->assertContains('PRODUCTO', $this->textosDe($primera));
            $this->assertCount(1, $primera->getDrawingCollection(), 'Cada hoja mensual lleva el logo.');
        }

        // Un solo mes → una sola hoja.
        $unMes = $this->libroDescargado(['desde' => '2026-05', 'hasta' => '2026-05']);
        $this->assertSame(['GENERAL'], $unMes->getSheetNames(),
            'Con el rango acotado a un mes la hoja mensual seria un duplicado de la general.');
    }
}
