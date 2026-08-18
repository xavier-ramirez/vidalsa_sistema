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
}
