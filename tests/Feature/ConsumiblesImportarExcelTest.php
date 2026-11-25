<?php

namespace Tests\Feature;

use App\Models\ConsumibleImportacion;
use App\Models\Equipo;
use App\Models\Usuario;
use App\Services\Consumibles\IdentificadorConsumo;
use App\Services\Consumibles\LectorExcelCombustible;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\MySqlTestCase;

/**
 * Cargar consumibles → Excel de gasoil, con un reporte de gasoil armado aquí mismo en el formato
 * «control de combustible» de los proyectos: lectura (recargas fuera, año imposible reparado,
 * total que solo suma parte de las filas), identificación (PATROL = motoniveladora, por el
 * final del serial), y el flujo completo por HTTP: subir → revisar → aplicar → la misma carga
 * otra vez no repite nada → deshacer. La transacción de cada caso lo revierte todo.
 */
class ConsumiblesImportarExcelTest extends MySqlTestCase
{
    private array $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }

    /** Una motoniveladora de la prueba; devuelve [equipo, últimos 7 dígitos del serial]. */
    private function motoniveladora(): array
    {
        $digitos = (string) random_int(1000000, 9999999);
        $tipo = DB::table('tipo_equipos')->where('nombre', 'MOTONIVELADORA')->value('id');
        $this->assertNotNull($tipo, 'Hace falta el tipo MOTONIVELADORA.');
        $equipo = Equipo::create(['MARCA' => 'PRUEBA', 'MODELO' => 'IMPORTAR', 'ANIO' => 2020,
            'id_tipo_equipo' => $tipo, 'SERIAL_CHASIS' => 'TSTIMP' . $digitos]);

        return [$equipo, $digitos];
    }

    /**
     * Reporte con cabecera en la fila 3; los despachos van de la fila 4 a la 9. El total
     * =SUM(E4:E7) deja fuera las dos últimas filas, como el de Carabobo.
     */
    private function excel(string $digitos): string
    {
        $libro = new Spreadsheet();
        $h = $libro->getActiveSheet();
        $h->setTitle('AGOSTO');
        $h->fromArray(['CONTROL DE COMBUSTIBLE'], null, 'A1');
        $h->fromArray(['FECHA', 'DESCRIPCION', 'PLACA', 'ENTRADA', 'SALIDA', 'NOMBRE DEL RESPONSABLE', 'C.I.', 'PROYECTO'], null, 'A3');
        $filas = [
            ['01/08/2026', 'PATROL ' . $digitos, '',        '',   80, 'CHOFER UNO',  'V-11.111.111', 'VELADERO'],
            ['02/08/2026', 'PATROL ' . $digitos, '',        '',   60, 'CHOFER UNO',  '11111111',     'VELADERO'],
            ['03/08/2026', 'CISTERNA (RECARGA)', '',        5000, '', '',            '',             'VELADERO'],
            ['05/08/2029', 'CAMION DESCONOCIDO', 'ZZ99TST', '',   40, 'CHOFER DOS',  '22222222',     'VELADERO'],
            ['06/08/2026', 'CAMION DESCONOCIDO', 'ZZ99TST', '',   40, 'CHOFER DOS',  '22222222',     'VELADERO'],
            ['07/08/2026', 'CAMION DESCONOCIDO', 'ZZ99TST', '',   40, 'CHOFER DOS',  '22222222',     'VELADERO'],
        ];
        $h->fromArray($filas, null, 'A4');
        // Explícito: en la app el «binder» de celdas guarda como texto lo que empieza por «=».
        $h->setCellValueExplicit('E10', '=SUM(E4:E7)', DataType::TYPE_FORMULA);

        $ruta = tempnam(sys_get_temp_dir(), 'gasoil') . '.xlsx';
        (new Xlsx($libro))->save($ruta);
        $this->temporales[] = $ruta;

        return $ruta;
    }

    private function subir(Usuario $u, string $ruta)
    {
        return $this->actingAs($u)->postJson(route('consumibles.importar.subir'), [
            'archivos' => [new UploadedFile($ruta, 'REPORTE GASOIL PRUEBA.xlsx', null, null, true)],
        ]);
    }

    public function test_lee_el_reporte_sin_recargas_y_repara_el_anio_imposible(): void
    {
        $ruta = $this->excel('1234567');
        $r = (new LectorExcelCombustible())->leer($ruta, 'prueba.xlsx');

        $this->assertCount(5, $r['filas'], 'la recarga del tanque no es un consumo');
        $hoja = $r['hojas'][0];
        $this->assertSame(1, $hoja['omitidas']['recarga']);
        $this->assertEquals(5000, $hoja['omitidas']['litros_recarga']);

        $reparada = collect($r['filas'])->firstWhere('fila', 7);
        $this->assertSame('2026-08-05', $reparada['fecha'], '2029 se corrige con el año de las vecinas');
        $this->assertNotEmpty($reparada['avisos']);
        $this->assertSame('11111111', $r['filas'][0]['ci'], 'la cédula queda solo en dígitos');

        // El total cubre solo las filas 4 a 7: cuadra con ellas, no con la hoja entera.
        $this->assertEquals(180, $hoja['total']['valor']);
        $this->assertSame([4, 7], [$hoja['total']['desde'], $hoja['total']['hasta']]);
        $this->assertTrue($hoja['total']['cuadra']);
        $this->assertEquals(260, $hoja['litros']);
    }

    public function test_patrol_es_la_motoniveladora_por_el_final_de_su_serial(): void
    {
        [$equipo, $digitos] = $this->motoniveladora();
        $ident = new IdentificadorConsumo();

        $r = $ident->resolverEquipo(['desc' => 'PATROL CAT ' . $digitos]);
        $this->assertSame('CONFIRMADO', $r['estado']);
        $this->assertSame($equipo->ID_EQUIPO, $r['id_equipo']);

        // Sin tipo reconocible el mismo número no se arriesga.
        $this->assertSame('SIN_MATCH', $ident->resolverEquipo(['desc' => 'EQUIPO ' . $digitos])['estado']);

        $veladero = $ident->resolverFrente(['proyecto' => 'TUBERIA 30 VELADERO', 'frente' => ''], 'proyecto');
        $this->assertNotNull($veladero['id_frente'], 'VELADERO va a su frente por la regla del nombre');
    }

    /**
     * La identidad entra en la huella guardada de cada consumo: si cambia, la próxima carga
     * de los mismos Excel no reconoce lo ya cargado y lo duplica. Estos valores no se tocan.
     */
    public function test_la_identidad_de_la_huella_no_cambia(): void
    {
        $ident = new IdentificadorConsumo();
        $this->assertSame('A04EA6G', $ident->identidad(['desc' => 'VOLTEO JAC #28', 'placa' => 'A04EA6G']));
        $this->assertSame('V0LTE0JAC28', $ident->identidad(['desc' => 'VOLTEO JAC #28']));
        $this->assertSame('A1893AG', $ident->identidad(['desc' => 'MINIBUS A1893AG 2015']));
        $this->assertSame('C0MPRES0R750CFM', $ident->identidad(['desc' => 'COMPRESOR 750 CFM']));
    }

    public function test_subir_revisar_aplicar_no_repetir_y_deshacer(): void
    {
        [$equipo, $digitos] = $this->motoniveladora();
        $ruta = $this->excel($digitos);
        $u = $this->superAdminGlobal();

        $equipos = DB::table('equipos')->count();
        $auxiliares = DB::table('equipos_auxiliares')->count();
        $subida = $this->subir($u, $ruta)->assertOk();
        $imp = ConsumibleImportacion::latest('ID_IMPORTACION')->firstOrFail();
        $this->assertSame(ConsumibleImportacion::BORRADOR, $imp->ESTADO);

        $this->get($subida->json('url'))->assertOk()
            ->assertSee('Revisar carga de Excel')
            ->assertSee('Equipos por identificar');

        $this->postJson(route('consumibles.importar.aplicar', $imp), ['meses' => ['2026-08']])->assertOk();
        $imp->refresh();
        $this->assertSame(ConsumibleImportacion::APLICADA, $imp->ESTADO);
        $this->assertSame(5, (int) $imp->INSERTADOS);

        $filas = DB::table('consumibles')->where('ID_IMPORTACION', $imp->ID_IMPORTACION)->get();
        $this->assertCount(2, $filas->where('ID_EQUIPO', $equipo->ID_EQUIPO), 'las dos de la PATROL, con su equipo');
        $this->assertCount(3, $filas->where('ESTADO_EQUIPO', 'SIN_MATCH'), 'las del camión desconocido entran por identificar');
        $this->assertSame(1, $filas->pluck('ID_FRENTE')->unique()->count());
        $this->assertNotNull($filas->first()->ID_FRENTE);
        $this->assertSame($equipos, DB::table('equipos')->count(), 'la carga nunca registra equipos');
        $this->assertSame($auxiliares, DB::table('equipos_auxiliares')->count());

        // La misma carga otra vez: todo «ya estaba».
        $this->subir($u, $ruta)->assertOk();
        $otra = ConsumibleImportacion::latest('ID_IMPORTACION')->firstOrFail();
        $rev = app(\App\Services\Consumibles\ImportadorCombustible::class)->revisar($otra);
        $this->assertSame(0, $rev['resumen']['totales']['nuevo'][0]);
        $this->assertSame(5, $rev['resumen']['totales']['cargado'][0]);
        $this->deleteJson(route('consumibles.importar.descartar', $otra))->assertOk();
        $this->assertNull(ConsumibleImportacion::find($otra->ID_IMPORTACION));

        $this->postJson(route('consumibles.importar.deshacer', $imp))->assertOk();
        $this->assertSame(0, DB::table('consumibles')->where('ID_IMPORTACION', $imp->ID_IMPORTACION)->count());
        $this->assertSame(ConsumibleImportacion::DESHECHA, $imp->refresh()->ESTADO);
    }

    /**
     * Copiar la tabla completa del Excel y pegarla (Ctrl+V) es lo mismo que subir el archivo:
     * columnas por su encabezado, aunque vengan en otro orden y con otras de más; la que no
     * es despacho (la recarga) fuera, y la misma tabla otra vez no repite lo ya cargado.
     */
    public function test_pegar_la_tabla_completa_es_como_subir_el_archivo(): void
    {
        [$equipo, $digitos] = $this->motoniveladora();
        $u = $this->superAdminGlobal();
        $texto = implode("\n", [
            "CONTROL DE COMBUSTIBLE",
            "",
            "N°\tOBSERVACION\tFECHA\tSALIDA\tDESCRIPCION\tC.I.\tNOMBRE DEL RESPONSABLE\tENTRADA\tPROYECTO\tKILOMETRAJE",
            "1\tok\t01/08/2026\t80\tPATROL {$digitos}\tV-11.111.111\tCHOFER UNO\t\tVELADERO\t1200",
            "2\t\t02/08/2026\t60,5\tPATROL {$digitos}\t11111111\tCHOFER UNO\t\tVELADERO\t1250",
            "3\trecarga\t03/08/2026\t\tCISTERNA (RECARGA)\t\t\t5000\tVELADERO\t",
            "4\t=SUM(A1)\t06/08/2026\t40\tCAMION DESCONOCIDO ZZ99TST\t22222222\tCHOFER DOS\t\tVELADERO\t",
        ]);

        $r = $this->actingAs($u)->postJson(route('consumibles.importar.pegar'), ['texto' => $texto])->assertOk();
        $imp = ConsumibleImportacion::latest('ID_IMPORTACION')->firstOrFail();
        $this->get($r->json('url'))->assertOk()->assertSee('Revisar carga de Excel');

        $this->postJson(route('consumibles.importar.aplicar', $imp), ['meses' => ['2026-08']])->assertOk();
        $filas = DB::table('consumibles')->where('ID_IMPORTACION', $imp->ID_IMPORTACION)->orderBy('FECHA')->get();
        $this->assertCount(3, $filas, 'la recarga del tanque no es un consumo');
        $this->assertEquals([80, 60.5, 40], $filas->pluck('CANTIDAD')->map(fn ($c) => (float) $c)->all(), 'litros de SALIDA, con coma decimal');
        $this->assertCount(2, $filas->where('ID_EQUIPO', $equipo->ID_EQUIPO));
        $this->assertSame('11111111', $filas->first()->RESP_CI);

        // Pegar lo mismo otra vez: nada nuevo.
        $this->postJson(route('consumibles.importar.pegar'), ['texto' => $texto])->assertOk();
        $otra = ConsumibleImportacion::latest('ID_IMPORTACION')->firstOrFail();
        $this->assertSame(0, app(\App\Services\Consumibles\ImportadorCombustible::class)->revisar($otra)['resumen']['totales']['nuevo'][0]);

        // Sin encabezado reconocible, lo dice (la pantalla ni lo manda: lo toma la tabla de abajo).
        $this->postJson(route('consumibles.importar.pegar'), ['texto' => "01/08/2026\tABC123\t40"])->assertStatus(422);

        // Más filas de las que se leen pegadas: se avisa, no se corta en silencio.
        $this->postJson(route('consumibles.importar.pegar'), ['texto' => str_repeat("x\n", 20001)])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Se pueden pegar hasta 20.000 filas. Para más, sube el archivo de Excel.']);
    }

    /**
     * Encabezados con otros nombres (LITROS, OPERADOR, CI, EQUIPO…) se reconocen igual, y
     * «PLACA DEL VEHICULO» es la placa, no la descripción.
     */
    public function test_reconoce_encabezados_con_otros_nombres(): void
    {
        $u = $this->superAdminGlobal();
        $texto = "Fecha\tEquipo\tPlaca del vehiculo\tEntrada\tLitros\tOperador\tCI\tFrente\n"
            . "05/08/2026\tCAMION OTRO NOMBRE\tZZ96TST\t\t70\tCHOFER TRES\t33333333\tVELADERO\n"
            . "06/08/2026\tCISTERNA\t\t3000\t\t\t\tVELADERO";

        $this->actingAs($u)->postJson(route('consumibles.importar.pegar'), ['texto' => $texto])->assertOk();
        $filas = ConsumibleImportacion::latest('ID_IMPORTACION')->firstOrFail()->filas();

        $this->assertCount(1, $filas, 'la recarga (ENTRADA) no es un despacho');
        $this->assertSame('2026-08-05', $filas[0]['fecha']);
        $this->assertEquals(70, $filas[0]['litros']);
        $this->assertSame('33333333', $filas[0]['ci']);
        $this->assertSame('CAMION OTRO NOMBRE', $filas[0]['desc']);
        $this->assertSame('ZZ96TST', $filas[0]['placa']);

        // El despachador y la hora de salida no son los litros.
        $this->postJson(route('consumibles.importar.pegar'), ['texto' => "FECHA\tDESPACHADOR\tHORA DE SALIDA\tPLACA\tLITROS\n07/08/2026\tLUIS\t07:30\tZZ96TST\t55"])->assertOk();
        $filas = ConsumibleImportacion::latest('ID_IMPORTACION')->firstOrFail()->filas();
        $this->assertEquals([55], array_column($filas, 'litros'));

        // Una hoja de resumen «FECHA | LITROS» (sin equipo ni SALIDA) no es de despachos.
        $this->postJson(route('consumibles.importar.pegar'), ['texto' => "FECHA\tLITROS\n07/08/2026\t5000"])->assertStatus(422);
    }

    /**
     * Lo que Excel pone en el portapapeles: una celda con salto de línea (Alt+Enter) va entre
     * comillas y sigue siendo UNA celda; y la fecha llega como se ve (con hora, con el mes
     * escrito o año primero), no como número de serie.
     */
    public function test_pegar_celdas_con_salto_de_linea_y_fechas_como_se_ven(): void
    {
        $u = $this->superAdminGlobal();
        $texto = "FECHA\tDESCRIPCION\tSALIDA\tPROYECTO\n"
            . "01/08/2026 08:30\t\"CAMION PEGADO\nCON SALTO\"\t11\tVELADERO\n"
            . "02-ago-26\tCAMION PEGADO\t12\tVELADERO\n"
            . "3 de agosto de 2026\tCAMION PEGADO\t13\tVELADERO\n"
            . "2026-08-04\tCAMION PEGADO\t14\tVELADERO";

        $this->actingAs($u)->postJson(route('consumibles.importar.pegar'), ['texto' => $texto])->assertOk();
        $filas = ConsumibleImportacion::latest('ID_IMPORTACION')->firstOrFail()->filas();

        $this->assertSame(['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04'], array_column($filas, 'fecha'));
        $this->assertEquals([11, 12, 13, 14], array_column($filas, 'litros'), 'cuatro despachos: la celda con salto no partió la fila');
        $this->assertStringContainsString('CON SALTO', $filas[0]['desc']);
        $this->assertStringNotContainsString('"', $filas[0]['desc']);
    }

    public function test_lo_ensenado_identifica_en_la_misma_carga(): void
    {
        [$equipo, $digitos] = $this->motoniveladora();
        $u = $this->superAdminGlobal();
        $this->subir($u, $this->excel($digitos))->assertOk();
        $imp = ConsumibleImportacion::latest('ID_IMPORTACION')->firstOrFail();

        $importador = app(\App\Services\Consumibles\ImportadorCombustible::class);
        $clave = $importador->revisar($imp)['resumen']['sin_equipo'][0]['clave'];
        $this->postJson(route('consumibles.importar.ensenar', $imp), [
            'equipos' => [['clave' => $clave, 'ref' => 'E:' . $equipo->ID_EQUIPO]],
        ])->assertOk();

        $this->assertSame([], $importador->revisar($imp)['resumen']['sin_equipo']);
    }

    public function test_el_match_del_servidor_resuelve_lo_cargado_con_lo_ensenado_despues(): void
    {
        [$equipo, $digitos] = $this->motoniveladora();
        $u = $this->superAdminGlobal();
        $this->subir($u, $this->excel($digitos))->assertOk();
        $imp = ConsumibleImportacion::latest('ID_IMPORTACION')->firstOrFail();
        $this->postJson(route('consumibles.importar.aplicar', $imp), ['meses' => ['2026-08']])->assertOk();

        $porIdentificar = DB::table('consumibles')->where('ID_IMPORTACION', $imp->ID_IMPORTACION)->where('ESTADO_EQUIPO', 'SIN_MATCH');
        $clave = (clone $porIdentificar)->value('IDENTIFICADOR');
        $this->postJson(route('consumibles.importar.ensenar', $imp), [
            'equipos' => [['clave' => $clave, 'ref' => 'E:' . $equipo->ID_EQUIPO]],
        ])->assertOk();

        (new \App\Services\Consumibles\MatchConsumibles())->ejecutar();   // la pasada de cada hora
        $this->assertSame(0, (clone $porIdentificar)->count());
        $this->assertSame(5, DB::table('consumibles')->where('ID_IMPORTACION', $imp->ID_IMPORTACION)
            ->where('ID_EQUIPO', $equipo->ID_EQUIPO)->where('ESTADO_EQUIPO', 'CONFIRMADO')->count());
    }

    public function test_por_identificar_se_resuelve_escribiendo_el_serial_completo(): void
    {
        [$equipo, $digitos] = $this->motoniveladora();
        $u = $this->superAdminGlobal();
        $this->subir($u, $this->excel($digitos))->assertOk();
        $imp = ConsumibleImportacion::latest('ID_IMPORTACION')->firstOrFail();
        $this->postJson(route('consumibles.importar.aplicar', $imp), ['meses' => ['2026-08']])->assertOk();

        // En Consumibles: al «camión desconocido» se le escribe el serial que faltaba en el Excel.
        $id = DB::table('consumibles')->where('ID_IMPORTACION', $imp->ID_IMPORTACION)->where('ESTADO_EQUIPO', 'SIN_MATCH')->value('ID_CONSUMIBLE');
        // Sin botón: guardar el identificador ya lo identifica.
        $this->patchJson(route('consumibles.updateIdentificador', $id), ['identificador' => $equipo->SERIAL_CHASIS])->assertOk();

        $c = DB::table('consumibles')->where('ID_CONSUMIBLE', $id)->first();
        $this->assertSame('CONFIRMADO', $c->ESTADO_EQUIPO);
        $this->assertSame($equipo->ID_EQUIPO, (int) $c->ID_EQUIPO);
        $this->assertNull($c->ID_AUXILIAR);
    }

    /** Una sola pantalla de carga: la subida de Excel y sus cargas anteriores están en Cargar consumibles. */
    public function test_la_subida_de_excel_esta_en_cargar_consumibles(): void
    {
        $html = $this->actingAs($this->superAdminGlobal())->get(route('consumibles.cargar'))->assertOk()->getContent();
        $this->assertStringContainsString('id="impArchivos"', $html);
        // La tarjeta de cargas solo sale si hay alguna (retomar o deshacer).
        $this->assertSame(ConsumibleImportacion::exists(), str_contains($html, 'Cargas de Excel'));
        $this->assertStringContainsString('id="formLote"', $html, 'Y la tabla para pegar, debajo.');
    }

    public function test_solo_super_admin(): void
    {
        $u = Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->whereNotNull('PERMISOS')->get()
            ->first(fn ($u) => !$u->can('super.admin'));
        $this->assertNotNull($u, 'Hace falta un usuario sin super.admin.');

        // La subida vive en Cargar consumibles y no se le muestra.
        $html = $this->actingAs($u)->get(route('consumibles.cargar'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="impArchivos"', $html);
        $this->subir($u, $this->excel('1234567'))->assertForbidden();
        $this->actingAs($u)->postJson(route('consumibles.importar.pegar'), ['texto' => "FECHA\tSALIDA\n01/08/2026\t10"])->assertForbidden();
    }
}
