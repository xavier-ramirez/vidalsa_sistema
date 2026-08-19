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

    /**
     * La columna SERIAL lleva UN identificador, el primero que exista: chasis, si no placa,
     * y si no el de motor. Se prueba el metodo directamente porque comprobarlo abriendo el
     * XLSX seria leer un ZIP para verificar una regla de tres lineas.
     */
    public static function identificadores(): array
    {
        return [
            'chasis gana a todo'        => ['CH-1', 'PL-1', 'MO-1', 'CH-1'],
            'sin chasis manda la placa' => [null,   'PL-2', 'MO-2', 'PL-2'],
            'chasis vacio no cuenta'    => ['   ',  'PL-3', 'MO-3', 'PL-3'],
            'solo queda el motor'       => [null,   null,   'MO-4', 'MO-4'],
            'placa vacia no cuenta'     => [null,   '',     'MO-5', 'MO-5'],
            'sin ninguno'               => [null,   null,   null,   '—'],
        ];
    }

    /**
     * @dataProvider identificadores
     */
    public function test_el_serial_es_el_primero_que_exista($chasis, $placa, $motor, $esperado): void
    {
        $equipo = new \App\Models\Equipo();
        $equipo->SERIAL_CHASIS   = $chasis;
        $equipo->SERIAL_DE_MOTOR = $motor;
        $equipo->setRelation('documentacion', $placa === null ? null : new \App\Models\Documentacion(['PLACA' => $placa]));

        $ctrl = new \App\Http\Controllers\MovilizacionController();
        $m = new \ReflectionMethod($ctrl, 'serialIdentificador');
        $m->setAccessible(true);

        $this->assertSame($esperado, $m->invoke($ctrl, $equipo, null, false));
    }

    /** Un auxiliar no tiene placa ni motor: lleva su propio SERIAL. */
    public function test_el_auxiliar_lleva_su_serial(): void
    {
        $aux = new \App\Models\EquipoAuxiliar();
        $aux->SERIAL = 'AUX-9';

        $ctrl = new \App\Http\Controllers\MovilizacionController();
        $m = new \ReflectionMethod($ctrl, 'serialIdentificador');
        $m->setAccessible(true);

        $this->assertSame('AUX-9', $m->invoke($ctrl, null, $aux, true));
    }

    /**
     * ABRE el XLSX descargado y lee sus celdas. Las otras pruebas comprueban que el archivo
     * sea un ZIP valido; esta comprueba que DENTRO estan las columnas pedidas, en su orden,
     * y que no quedo ninguna de las que se quitaron.
     */
    public function test_el_archivo_lleva_las_columnas_pedidas(): void
    {
        $resp = $this->actingAs($this->usuarioAdmin())->get('/admin/movilizaciones/export');
        $resp->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'hist') . '.xlsx';
        file_put_contents($tmp, $this->bytes($resp->baseResponse));

        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();

        // Fila 5 = encabezados de la tabla (1-3 logo/titulo, 4 "Exportado por")
        $encabezados = [];
        foreach (range('A', 'H') as $col) {
            $encabezados[] = (string) $hoja->getCell($col . '5')->getValue();
        }
        @unlink($tmp);

        $this->assertSame(
            ['N°', 'FECHA', 'TIPO', 'MARCA', 'MODELO', 'SERIAL', 'ORIGEN', 'DESTINO'],
            $encabezados,
            'Las columnas del Excel no son las pedidas o cambiaron de orden.'
        );

        foreach (['N° CONTROL', 'MOVIMIENTO', 'CLASE', 'PLACA', 'REGISTRADO POR'] as $quitada) {
            $this->assertNotContains($quitada, $encabezados, "La columna $quitada debia estar quitada.");
        }

        $this->assertSame('Historial', $hoja->getTitle());
    }

    public function test_exige_sesion(): void
    {
        $this->get('/admin/movilizaciones/export')->assertRedirect();
    }
}
