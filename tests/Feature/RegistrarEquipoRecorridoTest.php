<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\MySqlTestCase;

/**
 * Recorrido completo de /admin/equipos/create, función tras función y en el orden en que
 * se usan en la pantalla, para que un paso no deje roto al siguiente:
 *   1. abrir la pantalla;  2. verificación en vivo de seriales (equipo y auxiliar);
 *   3. registrar un equipo y un auxiliar a mano, y que el mismo serial ya no entre;
 *   4. buscar el equipo recién creado como anfitrión del auxiliar;
 *   5. carga por Excel de equipos: plantilla → previa → guardar → repetirla se rechaza;
 *   6. lo mismo con la plantilla de auxiliares.
 * Todo se revierte al terminar (MySqlTestCase).
 */
class RegistrarEquipoRecorridoTest extends MySqlTestCase
{
    /** Los .xlsx que arma la prueba; se borran al terminar. */
    private array $temporales = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->temporales, 'is_file'));
        parent::tearDown();
    }

    private function serial(): string
    {
        return 'REC-' . strtoupper(Str::random(10));
    }

    private function temporal(): string
    {
        return $this->temporales[] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'recorrido_' . Str::random(12) . '.xlsx';
    }

    /** Guarda la plantilla rellenada en un .xlsx temporal listo para subir. */
    private function subible(string $origen, string $hoja, array $fila): UploadedFile
    {
        $libro = IOFactory::load($origen);
        $sheet = $libro->getSheetByName($hoja) ?? $libro->getActiveSheet();
        $sheet->fromArray($fila, null, 'A2');
        $destino = $this->temporal();
        IOFactory::createWriter($libro, 'Xlsx')->save($destino);
        return new UploadedFile($destino, 'carga.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_todas_las_funciones_de_la_pantalla_una_tras_otra(): void
    {
        $u      = $this->superAdminGlobal();
        $frente = DB::table('frentes_trabajo')->whereNull('deleted_at')->where('ESTATUS_FRENTE', 'ACTIVO')
            ->whereNotIn('ID_FRENTE', $u->getFrentesBloqueadosIds())->first();
        $tipo   = DB::table('tipo_equipos')->value('nombre');
        $this->actingAs($u);

        // 1. La pantalla abre con el formulario, las dos cargas por Excel y sus rutas.
        $html = $this->get(route('equipos.create'))->assertOk()->getContent();
        foreach (['createUnifiedForm', 'bulkEquipoWrapper', 'bulkAuxWrapper', 'auxTipoCombo',
                  route('equipos.storeUnified'), route('equipos.checkUnique'), route('equipos-auxiliares.checkUnique'),
                  route('equipos.bulkTemplate'), route('equipos-auxiliares.bulkTemplate')] as $debe) {
            $this->assertStringContainsString($debe, $html, "falta {$debe} en la pantalla");
        }

        // 2. Verificación en vivo: libre antes de registrar; un campo inventado se rechaza.
        $chasis = $this->serial();
        $motor  = $this->serial();
        $placa  = 'R' . strtoupper(Str::random(6));
        $serAux = $this->serial();
        $this->getJson(route('equipos.checkUnique', ['field' => 'SERIAL_CHASIS', 'value' => $chasis]))->assertExactJson(['exists' => false]);
        $this->getJson(route('equipos-auxiliares.checkUnique', ['field' => 'SERIAL', 'value' => $serAux]))->assertExactJson(['exists' => false]);
        $this->getJson(route('equipos.checkUnique', ['field' => 'ID_EQUIPO', 'value' => '1']))->assertStatus(400);
        $this->getJson(route('equipos-auxiliares.checkUnique', ['field' => 'MARCA', 'value' => 'x']))->assertStatus(400);

        // 3a. Registrar un equipo a mano (con motor y placa).
        $this->postJson(route('equipos.storeUnified'), [
            '__modo' => 'equipo', 'CATEGORIA_FLOTA' => 'FLOTA LIVIANA', 'TIPO_EQUIPO' => $tipo,
            'MARCA' => 'PRUEBA', 'MODELO' => 'RECORRIDO', 'ANIO' => 2020,
            'SERIAL_CHASIS' => strtolower($chasis), 'SERIAL_DE_MOTOR' => $motor,
            'documentacion' => ['PLACA' => strtolower($placa)],
            'ESTADO_OPERATIVO' => 'OPERATIVO', 'ID_FRENTE_ACTUAL' => $frente->ID_FRENTE,
        ])->assertSuccessful();
        $equipo = DB::table('equipos')->where('SERIAL_CHASIS', $chasis)->first();
        $this->assertNotNull($equipo, 'el equipo se guarda con el serial en mayúsculas');
        $this->assertSame($motor, $equipo->SERIAL_DE_MOTOR);
        $this->assertSame($placa, DB::table('documentacion')->where('ID_EQUIPO', $equipo->ID_EQUIPO)->value('PLACA'));

        // 3b. Ahora la verificación en vivo lo ve, por chasis, motor y placa.
        foreach (['SERIAL_CHASIS' => $chasis, 'SERIAL_DE_MOTOR' => $motor, 'PLACA' => $placa] as $campo => $valor) {
            $this->getJson(route('equipos.checkUnique', ['field' => $campo, 'value' => strtolower($valor)]))
                ->assertExactJson(['exists' => true]);
        }

        // 3c. El mismo chasis o la misma placa no entran dos veces.
        $this->postJson(route('equipos.storeUnified'), [
            '__modo' => 'equipo', 'CATEGORIA_FLOTA' => 'FLOTA LIVIANA', 'TIPO_EQUIPO' => $tipo,
            'MARCA' => 'PRUEBA', 'MODELO' => 'RECORRIDO', 'ANIO' => 2020,
            'SERIAL_CHASIS' => $chasis, 'documentacion' => ['PLACA' => $placa], 'ESTADO_OPERATIVO' => 'OPERATIVO',
        ])->assertStatus(422)->assertJsonValidationErrors(['SERIAL_CHASIS', 'documentacion.PLACA']);

        // 3d. Registrar un auxiliar a mano; el mismo serial ya no entra.
        $aux = ['__modo' => 'auxiliar', 'TIPO' => 'COMPRESOR', 'MARCA' => 'PRUEBA', 'MODELO' => 'RECORRIDO',
                'SERIAL' => $serAux, 'ESTADO_OPERATIVO' => 'OPERATIVO', 'ID_FRENTE_ACTUAL' => $frente->ID_FRENTE];
        $this->postJson(route('equipos.storeUnified'), $aux)->assertOk();
        $this->assertTrue(DB::table('equipos_auxiliares')->where('SERIAL', $serAux)->exists());
        $this->getJson(route('equipos-auxiliares.checkUnique', ['field' => 'SERIAL', 'value' => strtolower($serAux)]))
            ->assertExactJson(['exists' => true]);
        $this->postJson(route('equipos.storeUnified'), $aux)->assertStatus(422)->assertJsonValidationErrors('SERIAL');

        // 4. El equipo recién registrado aparece al buscar dónde anclar un auxiliar.
        $hallados = collect($this->getJson(route('equipos-auxiliares.searchHosts', ['q' => $chasis]))->assertOk()->json());
        $this->assertTrue($hallados->contains('id', $equipo->ID_EQUIPO), 'searchHosts encuentra el equipo por su chasis');
        $this->getJson(route('equipos-auxiliares.searchHosts', ['q' => 'x']))->assertExactJson([]);

        // 5. Carga por Excel de EQUIPOS: plantilla → previa → guardar.
        $plantilla = $this->descargarExcel(fn () => $this->get(route('equipos.bulkTemplate')))->assertOk();
        $chasisXls = $this->serial();
        $archivo = $this->subible($plantilla->getFile()->getPathname(), 'Equipos',
            [$tipo, 'FLOTA PESADA', 'PRUEBA', 'EXCEL', 2021, null, $chasisXls, null, $frente->NOMBRE_FRENTE, 'OPERATIVO']);
        $previa = $this->post(route('equipos.bulkPreview'), ['archivo_excel' => $archivo], ['Accept' => 'application/json'])
            ->assertOk()->json();
        $this->assertCount(1, $previa['rows']);
        $this->assertSame([], (array) $previa['rows'][0]['errors'], 'la fila buena no trae errores en la previa');
        $this->assertSame($frente->ID_FRENTE, (int) $previa['rows'][0]['data']['id_frente_resuelto']);

        $fila = $previa['rows'][0]['data'];
        $this->postJson(route('equipos.bulkStoreBatch'), ['rows' => [$fila]])->assertOk()->assertJson(['count' => 1]);
        $this->assertTrue(DB::table('equipos')->where('SERIAL_CHASIS', $chasisXls)->exists());
        $this->postJson(route('equipos.bulkStoreBatch'), ['rows' => [$fila]])->assertStatus(422);

        // La misma plantilla, ya guardada, en la previa sale marcada como repetida.
        $otra = $this->subible($plantilla->getFile()->getPathname(), 'Equipos',
            [$tipo, 'FLOTA PESADA', 'PRUEBA', 'EXCEL', 2021, null, $chasisXls, null, $frente->NOMBRE_FRENTE, 'OPERATIVO']);
        $previa2 = $this->post(route('equipos.bulkPreview'), ['archivo_excel' => $otra], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertArrayHasKey('serial_chasis', (array) $previa2['rows'][0]['errors']);

        // 6. Carga por Excel de AUXILIARES: plantilla → previa → guardar.
        $plantillaAux = $this->descargarExcel(fn () => $this->get(route('equipos-auxiliares.bulkTemplate')))->assertOk();
        $rutaAux = $this->temporal();
        file_put_contents($rutaAux, $plantillaAux->streamedContent());
        $serAuxXls = $this->serial();
        $archivoAux = $this->subible($rutaAux, 'Auxiliares',
            ['COMPRESOR', 'PRUEBA', 'EXCEL', $serAuxXls, null, 2022, $frente->NOMBRE_FRENTE, 'OPERATIVO']);
        $previaAux = $this->post(route('equipos-auxiliares.bulkPreview'), ['archivo_excel' => $archivoAux], ['Accept' => 'application/json'])
            ->assertOk()->json();
        $this->assertSame([], (array) $previaAux['rows'][0]['errors'], 'la fila de auxiliar buena no trae errores');

        // Lo que manda la pantalla (collectRow): las celdas + el id del frente. En la previa
        // se cambió el tipo a la ETIQUETA «Contenedor»: se guarda con su código, CONTAINER.
        $d = $previaAux['rows'][0]['data'];
        $filaAux = ['id_frente_resuelto' => $d['id_frente_resuelto'], 'tipo' => 'Contenedor',
                    'marca' => $d['marca'], 'modelo' => $d['modelo'], 'serial' => $d['serial'], 'capacidad' => $d['capacidad'],
                    'anio' => $d['anio'], 'frente' => $d['frente'], 'estado' => $d['estado']];
        $this->postJson(route('equipos-auxiliares.bulkStoreBatch'), ['rows' => [$filaAux]])->assertOk();
        $this->assertSame('CONTAINER', DB::table('equipos_auxiliares')->where('SERIAL', $serAuxXls)->value('TIPO'));
        $this->postJson(route('equipos-auxiliares.bulkStoreBatch'), ['rows' => [$filaAux]])->assertStatus(422);
    }

    public function test_los_errores_del_excel_caen_en_su_celda_y_con_los_topes_del_formulario(): void
    {
        $u      = $this->superAdminGlobal();
        $frente = DB::table('frentes_trabajo')->whereNull('deleted_at')->where('ESTATUS_FRENTE', 'ACTIVO')
            ->whereNotIn('ID_FRENTE', $u->getFrentesBloqueadosIds())->first();
        $this->actingAs($u);

        // Auxiliar con año fuera de rango: el error va en la clave de la celda (anio).
        $plantillaAux = $this->descargarExcel(fn () => $this->get(route('equipos-auxiliares.bulkTemplate')))->assertOk();
        $rutaAux = $this->temporal();
        file_put_contents($rutaAux, $plantillaAux->streamedContent());
        $archivo = $this->subible($rutaAux, 'Auxiliares',
            ['COMPRESOR', 'PRUEBA', 'EXCEL', $this->serial(), null, 1900, $frente->NOMBRE_FRENTE, 'OPERATIVO']);
        $previa = $this->post(route('equipos-auxiliares.bulkPreview'), ['archivo_excel' => $archivo], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertArrayHasKey('anio', (array) $previa['rows'][0]['errors']);

        // Equipo con un tipo de 36 letras: el formulario no lo deja (máx. 35) y el Excel tampoco.
        $r = $this->postJson(route('equipos.bulkStoreBatch'), ['rows' => [[
            'tipo_equipo' => str_repeat('T', 36), 'categoria_flota' => 'FLOTA LIVIANA', 'marca' => 'PRUEBA', 'modelo' => 'M1',
            'anio' => '2020', 'serial_chasis' => $this->serial(), 'frente_trabajo' => $frente->NOMBRE_FRENTE, 'status' => 'OPERATIVO',
        ]]])->assertStatus(422);
        $this->assertArrayHasKey('tipo_equipo', $r->json('errors.0'));
    }

    public function test_el_formulario_solo_ofrece_los_frentes_donde_se_puede_registrar(): void
    {
        // Se le bloquea un frente activo (solo en memoria; nada se guarda).
        $u = $this->superAdminGlobal();
        $activos = DB::table('frentes_trabajo')->whereNull('deleted_at')->where('ESTATUS_FRENTE', 'ACTIVO')
            ->whereNotIn('ID_FRENTE', $u->getFrentesBloqueadosIds())->orderBy('ID_FRENTE')->limit(2)->get();
        [$bloqueado, $libre] = [$activos[0], $activos[1]];
        $u->forceFill(['ID_FRENTE_BLOQUEADO' => trim(implode(',', array_merge($u->getFrentesBloqueadosIds(), [$bloqueado->ID_FRENTE])), ',')]);

        $html = $this->actingAs($u)->get(route('equipos.create'))->assertOk()->getContent();
        $opcion = fn ($f) => "selectOption('frenteSelect', '" . $f->ID_FRENTE . "'";
        $this->assertStringNotContainsString($opcion($bloqueado), $html, 'el frente bloqueado no se ofrece');
        $this->assertStringContainsString($opcion($libre), $html, 'los demás sí');
    }
}
