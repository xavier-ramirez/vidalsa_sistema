<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * El botón "Exportar a Excel" del menú Acciones de /admin/almacen/movimientos.
 *
 * Lo que fijan estas pruebas: que salga un XLSX de verdad, que acepte los MISMOS filtros
 * de la pantalla y que exija sesión. Pantalla y archivo comparten el armado de la consulta
 * (AlmacenController::queryMovimientosFiltrada y buildParams() en la vista), así que el
 * Excel no puede traer un universo distinto al que se está viendo.
 */
class ExportMovimientosAlmacenTest extends MySqlTestCase
{
    private const RUTA = '/admin/almacen/movimientos/export';

    private function usuarioAdmin(): Usuario
    {
        $user = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        $this->assertNotNull($user, 'Hace falta un usuario con super.admin.');

        return $user;
    }

    /**
     * Primeros bytes de la descarga. streamDownload() no expone el contenido como string,
     * así que se captura lo que el callback escribe en la salida.
     */
    private function bytes($response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    public function test_descarga_un_xlsx_de_verdad(): void
    {
        $resp = $this->actingAs($this->usuarioAdmin())->get(self::RUTA);
        $resp->assertOk();

        // Un .xlsx es un ZIP: sus dos primeros bytes son "PK". Se comprueba el CONTENIDO,
        // no el nombre ni la cabecera, que cualquiera puede poner sin que el archivo abra.
        $this->assertSame('PK', substr($this->bytes($resp->baseResponse), 0, 2),
            'Lo descargado no es un XLSX (no empieza por la firma ZIP).');

        $resp->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('movimientos_',
            $resp->headers->get('content-disposition') ?? '');
    }

    /** Los mismos parámetros que manda buildParams() desde la vista. */
    public static function filtrosDeLaPantalla(): array
    {
        return [
            'todos los almacenes' => [['id_almacen' => 'all']],
            'solo entradas'       => [['tipo' => 'ENTRADAS']],
            'solo salidas'        => [['tipo' => 'SALIDAS']],
            'nota de entrega'     => [['nota' => '001']],
            'busqueda de producto'=> [['search' => 'FILTRO']],
            'rango de fechas'     => [['desde' => '2020-01-01', 'hasta' => '2030-01-01']],
            'frente'              => [['id_frente' => '1']],
            'producto puntual'    => [['id_producto_in' => '1']],
            'combinado'           => [['id_almacen' => 'all', 'tipo' => 'SALIDAS', 'desde' => '2020-01-01']],
        ];
    }

    /**
     * @dataProvider filtrosDeLaPantalla
     */
    public function test_acepta_los_mismos_filtros_de_la_pantalla(array $filtros): void
    {
        $resp = $this->actingAs($this->usuarioAdmin())->get(self::RUTA . '?' . http_build_query($filtros));

        $resp->assertOk();
        $this->assertSame('PK', substr($this->bytes($resp->baseResponse), 0, 2),
            'Con ' . json_encode($filtros) . ' la descarga dejo de ser un XLSX.');
    }

    public function test_exige_sesion(): void
    {
        $this->get(self::RUTA)->assertRedirect();
    }

    /**
     * ABRE el XLSX y lee sus celdas. Las otras pruebas comprueban que el archivo sea un ZIP
     * valido; esta comprueba que DENTRO estan las columnas pedidas, en su orden, y que no
     * quedo ninguna de las que se quitaron. Sin esto, cambiar $cols sin tocar la fila de
     * datos (o al reves) pasaba desapercibido: el archivo abre igual, solo que con la
     * cabecera corrida respecto a los valores.
     */
    public function test_el_archivo_lleva_las_columnas_pedidas(): void
    {
        $resp = $this->actingAs($this->usuarioAdmin())->get(self::RUTA);
        $resp->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'mov') . '.xlsx';
        file_put_contents($tmp, $this->bytes($resp->baseResponse));

        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();

        // Fila 4 = encabezados (1 titulo, 2 filtros, 3 en blanco).
        $encabezados = [];
        foreach (range('A', 'K') as $col) {
            $encabezados[] = (string) $hoja->getCell($col . '4')->getValue();
        }
        // La L tiene que estar vacia: si algo escribe ahi, sobra una columna.
        $sobrante = (string) $hoja->getCell('L4')->getValue();
        @unlink($tmp);

        $this->assertSame(
            ['FECHA', 'TIPO', 'CÓDIGO', 'PRODUCTO', 'UM', 'CANTIDAD', 'ANTERIOR', 'RESULTANTE',
             'ALMACÉN', 'CONTRAPARTE', 'FRENTE'],
            $encabezados,
            'Las columnas del Excel no son las pedidas o cambiaron de orden.'
        );

        $this->assertSame('', $sobrante, "Sobra una columna despues de FRENTE: \"$sobrante\".");

        foreach (['N° NOTA', 'REFERENCIA', 'SOLICITANTE', 'MOTIVO', 'USUARIO'] as $quitada) {
            $this->assertNotContains($quitada, $encabezados, "La columna $quitada debia estar quitada.");
        }

        $this->assertSame('MOVIMIENTOS', $hoja->getTitle());
    }
}
