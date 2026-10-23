<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * El botón "Exportar historial a Excel" del menú Acciones de /admin/movilizaciones.
 *
 * Lo que fijan estas pruebas: que salga un XLSX de verdad, que lleve SOLO las filas que la
 * pantalla le pide por id (las que dejaron sus filtros, que viven en el navegador) y solo
 * las que el usuario puede ver, y que exija sesión.
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

    /** La hoja del XLSX descargado. */
    private function hoja($response): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $tmp = tempnam(sys_get_temp_dir(), 'hist') . '.xlsx';
        file_put_contents($tmp, $this->bytes($response->baseResponse));
        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);

        return $hoja;
    }

    private function exportar(Usuario $usuario, array $ids, array $filtros = [])
    {
        return $this->actingAs($usuario)->post('/admin/movilizaciones/export', $filtros + ['ids' => $ids]);
    }

    /** Ids de las últimas movilizaciones (las de la base real). */
    private function ultimas(int $n): array
    {
        return DB::table('movilizacion_historial')->orderByDesc('ID_MOVILIZACION')->limit($n)->pluck('ID_MOVILIZACION')->all();
    }

    /** Filas de datos de la hoja: las que traen FECHA (columna B) bajo el encabezado (fila 5). */
    private function filasDeDatos($hoja): int
    {
        $n = 0;
        for ($fila = 6; $fila <= $hoja->getHighestDataRow(); $fila++) {
            if ((string) $hoja->getCell('B' . $fila)->getValue() !== '') $n++;
        }

        return $n;
    }

    public function test_descarga_un_xlsx_de_verdad(): void
    {
        $resp = $this->exportar($this->usuarioAdmin(), $this->ultimas(3));
        $resp->assertOk();

        // Un .xlsx es un ZIP: sus dos primeros bytes son "PK". Se comprueba el CONTENIDO,
        // no el nombre ni la cabecera, que cualquiera puede poner sin que el archivo abra.
        $this->assertSame('PK', substr($this->bytes($resp->baseResponse), 0, 2),
            'Lo descargado no es un XLSX (no empieza por la firma ZIP).');

        $resp->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('Historial_Movilizaciones_',
            $resp->headers->get('content-disposition') ?? '');
    }

    /** Lleva exactamente las filas pedidas, ni una más: el filtro lo decidió la pantalla. */
    public function test_lleva_solo_los_ids_pedidos(): void
    {
        $ids = $this->ultimas(2);
        if (count($ids) < 2) {
            $this->markTestSkipped('Hacen falta dos movilizaciones.');
        }

        $hoja = $this->hoja($this->exportar($this->usuarioAdmin(), $ids)->assertOk());

        $this->assertSame(2, $this->filasDeDatos($hoja), 'Debe haber una fila por id pedido.');
    }

    /** Una movilización que toca un frente bloqueado para el usuario no se exporta aunque se pida. */
    public function test_no_exporta_lo_que_el_usuario_no_ve(): void
    {
        $mov = DB::table('movilizacion_historial')->whereNotNull('ID_FRENTE_DESTINO')->orderByDesc('ID_MOVILIZACION')->first();
        if (! $mov) {
            $this->markTestSkipped('No hay movilizaciones con destino.');
        }
        $admin = $this->usuarioAdmin();
        DB::table('usuarios')->where('ID_USUARIO', $admin->ID_USUARIO)->update(['ID_FRENTE_BLOQUEADO' => (string) $mov->ID_FRENTE_DESTINO]);

        $hoja = $this->hoja($this->exportar($admin->fresh(), [$mov->ID_MOVILIZACION])->assertOk());

        $this->assertSame(0, $this->filasDeDatos($hoja));
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

    #[\PHPUnit\Framework\Attributes\DataProvider('identificadores')]
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
        $hoja = $this->hoja($this->exportar($this->usuarioAdmin(), $this->ultimas(3))->assertOk());

        // Fila 5 = encabezados de la tabla (1-3 logo/titulo, 4 "Exportado por")
        $encabezados = [];
        foreach (range('A', 'H') as $col) {
            $encabezados[] = (string) $hoja->getCell($col . '5')->getValue();
        }

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
        $this->post('/admin/movilizaciones/export', ['ids' => [1]])->assertRedirect();
    }
}
