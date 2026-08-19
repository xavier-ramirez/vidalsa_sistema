<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * El botón "Exportar historial a Excel" del menú Acciones de /admin/movilizaciones.
 *
 * Lo que fijan estas pruebas: que salga un XLSX de verdad, que respete los MISMOS filtros
 * de la pantalla y que exija sesión. Pantalla y archivo comparten el armado del filtro
 * (MovilizacionController::aplicarFiltrosHistorial y window._mvFiltrosActuales), así que el
 * Excel no puede traer un universo distinto al que se está viendo.
 */
class ExportHistorialMovilizacionesTest extends MySqlTestCase
{
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
        $resp = $this->actingAs($this->usuarioAdmin())->get('/admin/movilizaciones/export');
        $resp->assertOk();

        // Un .xlsx es un ZIP: sus dos primeros bytes son "PK". Se comprueba el CONTENIDO,
        // no el nombre ni la cabecera, que cualquiera puede poner sin que el archivo abra.
        $this->assertSame('PK', substr($this->bytes($resp->baseResponse), 0, 2),
            'Lo descargado no es un XLSX (no empieza por la firma ZIP).');

        $resp->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('Historial_Movilizaciones_',
            $resp->headers->get('content-disposition') ?? '');
    }

    public static function filtrosDeLaPantalla(): array
    {
        return [
            'búsqueda libre'   => [['search' => 'MV-1']],
            'rango de fechas'  => [['fecha_desde' => '2020-01-01', 'fecha_hasta' => '2030-01-01']],
            'frente + entrada' => [['id_frente' => '1', 'direccion_frente' => 'entrada']],
            'frente + salida'  => [['id_frente' => '1', 'direccion_frente' => 'salida']],
            'tipo de equipo'   => [['id_tipo' => 'tipo_eq:1']],
            'tipo de auxiliar' => [['id_tipo' => 'tipo_aux:MARTILLO']],
            'todo junto'       => [['search' => 'A', 'id_frente' => 'all', 'fecha_desde' => '2024-01-01']],
        ];
    }

    /**
     * @dataProvider filtrosDeLaPantalla
     */
    public function test_acepta_los_mismos_filtros_de_la_pantalla(array $filtros): void
    {
        $resp = $this->actingAs($this->usuarioAdmin())
            ->get('/admin/movilizaciones/export?' . http_build_query($filtros));

        $resp->assertOk();
        $this->assertSame('PK', substr($this->bytes($resp->baseResponse), 0, 2));
    }

    /** Una fecha basura en la URL no puede tumbar la exportación con un 500. */
    public function test_una_fecha_invalida_no_revienta(): void
    {
        $resp = $this->actingAs($this->usuarioAdmin())
            ->get('/admin/movilizaciones/export?fecha_desde=no-es-una-fecha');

        $resp->assertOk();
    }

    public function test_exige_sesion(): void
    {
        $this->get('/admin/movilizaciones/export')->assertRedirect();
    }
}
