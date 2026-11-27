<?php

namespace Tests\Feature;

use App\Models\ConsumibleImportacion;
use App\Models\Equipo;
use App\Models\Usuario;
use App\Services\Consumibles\IdentificadorConsumo;
use App\Services\Consumibles\LectorExcelCombustible;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\MySqlTestCase;

/**
 * Cargar consumibles → Excel de gasoil, con un reporte de gasoil armado aquí mismo en el formato
 * «control de combustible» de los proyectos: lectura (recargas fuera, año imposible reparado,
 * total que solo suma parte de las filas), identificación (PATROL = motoniveladora, por el
 * final del serial), y el flujo completo por HTTP: subir (las filas van a la tabla de Cargar) →
 * guardar la tabla → la misma carga otra vez se salta al guardar → deshacer. La transacción
 * de cada caso lo revierte todo.
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

    /** La tabla (texto con tabuladores) en un Excel con todas las celdas como texto. */
    private function tabla(string $texto): string
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $tsv = fopen('php://temp', 'r+');
        fwrite($tsv, $texto);
        rewind($tsv);
        for ($f = 1; ($celdas = fgetcsv($tsv, 0, "\t", '"', '')) !== false; $f++) {
            foreach ($celdas as $c => $v) {
                if (trim((string) $v) !== '') {
                    $hoja->setCellValueExplicit(Coordinate::stringFromColumnIndex($c + 1) . $f, trim($v), DataType::TYPE_STRING);
                }
            }
        }
        fclose($tsv);
        $ruta = tempnam(sys_get_temp_dir(), 'tabla') . '.xlsx';
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

    /**
     * Guarda la tabla de Cargar consumibles con las filas de una subida, como la página: cada una
     * con su carga y su lugar (imp/ref) y el frente ya reconocido. $cambiar retoca una fila
     * (por su posición) antes de enviarla; $mas, filas escritas a mano en la misma tabla.
     */
    private function guardarTabla($subida, ?callable $cambiar = null, array $mas = [], string $tipo = 'GASOIL')
    {
        $filas = [];
        foreach ($subida->json('filas') as $i => $f) {
            $fila = [
                'fecha'         => $f['fecha'],
                'identificador' => $f['id'],
                'resp_nombre'   => $f['nombre'],
                'resp_ci'       => $f['ci'],
                'cantidad'      => (string) $f['cantidad'],
                'frente'        => $f['frente'],
                'id_frente'     => $f['frente'] !== '' && $f['id_frente'] ? (string) $f['id_frente'] : '',
                'imp'           => (string) $subida->json('importacion'),
                'ref'           => (string) $f['ref'],
            ];
            $filas[] = $cambiar ? $cambiar($fila, $i) : $fila;
        }

        return $this->postJson(route('consumibles.guardarLote'), [
            'tipo_consumible' => $tipo,
            'unidad'          => 'LITROS',
            'id_frente'       => '',
            'filas'           => array_values(array_filter(array_merge($filas, $mas))),
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

    public function test_subir_a_la_tabla_guardar_saltar_repetidos_y_deshacer(): void
    {
        [$equipo, $digitos] = $this->motoniveladora();
        $ruta = $this->excel($digitos);
        $u = $this->superAdminGlobal();

        $equipos = DB::table('equipos')->count();
        $auxiliares = DB::table('equipos_auxiliares')->count();
        $subida = $this->subir($u, $ruta)->assertOk();
        $this->assertCount(5, $subida->json('filas'), 'los despachos van a la tabla, sin la recarga');
        $this->assertSame(0, $subida->json('cargados'));
        $this->assertNotNull($subida->json('filas.0.id_frente'), 'VELADERO ya viene reconocido');
        $imp = ConsumibleImportacion::findOrFail($subida->json('importacion'));
        $this->assertSame(ConsumibleImportacion::BORRADOR, $imp->ESTADO);

        // Una fila escrita a mano en la misma tabla entra también.
        $aMano = ['fecha' => '2026-08-09', 'identificador' => 'ZZ98TST', 'resp_nombre' => 'A MANO', 'resp_ci' => '',
            'cantidad' => '25', 'frente' => '', 'id_frente' => (string) $subida->json('filas.0.id_frente')];
        $this->guardarTabla($subida, null, [$aMano])->assertOk()->assertJson(['insertados' => 6, 'saltados' => 0]);
        $imp->refresh();
        $this->assertSame(ConsumibleImportacion::APLICADA, $imp->ESTADO);
        $this->assertSame(5, (int) $imp->INSERTADOS);
        $this->assertSame('', (string) $imp->FILAS, 'guardada, la carga ya no guarda sus filas');

        $filas = DB::table('consumibles')->where('ID_IMPORTACION', $imp->ID_IMPORTACION)->get();
        $this->assertCount(2, $filas->where('ID_EQUIPO', $equipo->ID_EQUIPO), 'las dos de la PATROL, con su equipo');
        $this->assertCount(3, $filas->where('ESTADO_EQUIPO', 'SIN_MATCH'), 'las del camión desconocido entran por identificar');
        $this->assertSame(1, $filas->pluck('ID_FRENTE')->unique()->count());
        $this->assertNotNull($filas->first()->ID_FRENTE);
        $this->assertNotNull($filas->first()->HUELLA_EQ, 'con su huella, para no repetirse después');
        $this->assertStringContainsString('fila 4', $filas->sortBy('ID_CONSUMIBLE')->first()->ORIGEN);
        $this->assertSame($equipos, DB::table('equipos')->count(), 'la carga nunca registra equipos');
        $this->assertSame($auxiliares, DB::table('equipos_auxiliares')->count());

        // El mismo Excel otra vez: la tabla los muestra, pero al guardar se saltan todos.
        $otra = $this->subir($u, $ruta)->assertOk();
        $this->assertSame(5, $otra->json('cargados'));
        // Cada fila dice si se salta: el resumen de la tabla no la suma.
        $this->assertSame([true], array_values(array_unique(array_column($otra->json('filas'), 'saltar'))));
        $this->assertSame([false], array_values(array_unique(array_column($subida->json('filas'), 'saltar'))));
        $antes = DB::table('consumibles')->count();
        $this->guardarTabla($otra)->assertOk()
            ->assertJson(['insertados' => 0, 'saltados' => 5])
            ->assertJsonFragment(['message' => '0 registros cargados exitosamente. 5 se saltaron porque ya estaban cargados o repetidos en otro archivo.']);
        $this->assertSame($antes, DB::table('consumibles')->count());
        $this->assertNull(ConsumibleImportacion::find($otra->json('importacion')), 'sin nada nuevo no queda carga que deshacer');

        // Ya guardada, la misma lectura no se guarda dos veces (otra pestaña, doble clic).
        $this->guardarTabla($subida)->assertStatus(422);

        $this->postJson(route('consumibles.importar.deshacer', $imp))->assertOk();
        $this->assertSame(0, DB::table('consumibles')->where('ID_IMPORTACION', $imp->ID_IMPORTACION)->count());
        $this->assertSame(ConsumibleImportacion::DESHECHA, $imp->refresh()->ESTADO);
    }

    /**
     * El reporte pegado con encabezados (Ctrl+V) pasa por el mismo lector: va a la tabla con sus
     * huellas, así un reporte acumulado pegado otra vez (o subido después) no duplica nada.
     */
    public function test_el_reporte_pegado_salta_lo_ya_cargado(): void
    {
        [, $digitos] = $this->motoniveladora();
        $u = $this->superAdminGlobal();
        $texto = implode("
", array_map(fn ($f) => implode("	", $f), [
            ['FECHA', 'DESCRIPCION', 'PLACA', 'SALIDA', 'NOMBRE DEL RESPONSABLE', 'C.I.', 'PROYECTO'],
            ['01/08/2026', 'PATROL ' . $digitos, '', '80', 'CHOFER UNO', '11111111', 'VELADERO'],
            ['02/08/2026', 'PATROL ' . $digitos, '', '60', 'CHOFER UNO', '11111111', 'VELADERO'],
            ['TOTAL', '', '', '140', '', '', ''],
        ]));

        $pegado = $this->actingAs($u)->postJson(route('consumibles.importar.pegar'), ['texto' => $texto])->assertOk();
        $this->assertCount(2, $pegado->json('filas'), 'sin la fila de total');
        $this->guardarTabla($pegado)->assertOk()->assertJson(['insertados' => 2, 'saltados' => 0]);

        // El mismo reporte pegado de nuevo: a la tabla, pero al guardar se saltan.
        $otra = $this->actingAs($u)->postJson(route('consumibles.importar.pegar'), ['texto' => $texto])->assertOk();
        $this->assertSame(2, $otra->json('cargados'));
        $this->guardarTabla($otra)->assertOk()->assertJson(['insertados' => 0, 'saltados' => 2]);

        $this->actingAs($u)->postJson(route('consumibles.importar.pegar'), ['texto' => ''])->assertStatus(422);
    }

    /**
     * Fecha corregida en la tabla antes de guardar (31/08 → 01/09): un reporte posterior que solo
     * trae agosto reconoce ese despacho como ya cargado (antes no lo veía y chocaba con la huella
     * guardada: error 500 y no se guardaba nada).
     */
    public function test_fecha_corregida_en_la_tabla_sigue_contando_como_cargada(): void
    {
        [, $digitos] = $this->motoniveladora();
        $u = $this->superAdminGlobal();
        $enc = ['FECHA', 'DESCRIPCION', 'PLACA', 'SALIDA', 'NOMBRE DEL RESPONSABLE', 'C.I.', 'PROYECTO'];
        $fila = ['31/08/2026', 'PATROL ' . $digitos, '', '77', 'CHOFER UNO', '11111111', 'VELADERO'];
        $texto = fn (array ...$f) => implode("\n", array_map(fn ($x) => implode("\t", $x), [$enc, ...$f]));

        $pegado = $this->actingAs($u)->postJson(route('consumibles.importar.pegar'), ['texto' => $texto($fila)])->assertOk();
        $this->guardarTabla($pegado, function ($f) {
            $f['fecha'] = '2026-09-01';

            return $f;
        })->assertOk()->assertJson(['insertados' => 1]);

        $otra = $this->actingAs($u)->postJson(route('consumibles.importar.pegar'), ['texto' => $texto($fila, ['30/08/2026', 'PATROL ' . $digitos, '', '20', 'CHOFER UNO', '11111111', 'VELADERO'])])->assertOk();
        $this->assertSame(1, $otra->json('cargados'));
        $this->guardarTabla($otra)->assertOk()->assertJson(['insertados' => 1, 'saltados' => 1]);
    }

    /**
     * La fila del Excel ya cargada se salta al guardar: no pide frente. La nueva sí, y el aviso
     * nombra su proyecto (sin guardar nada); con su frente elegido entra solo ella.
     */
    public function test_la_fila_que_se_salta_no_pide_frente(): void
    {
        [, $digitos] = $this->motoniveladora();
        $u = $this->superAdminGlobal();
        $enc = ['FECHA', 'DESCRIPCION', 'PLACA', 'SALIDA', 'NOMBRE DEL RESPONSABLE', 'C.I.', 'PROYECTO'];
        $vieja = ['31/08/2026', 'PATROL ' . $digitos, '', '77', 'CHOFER UNO', '11111111', 'VELADERO'];
        $nueva = ['01/09/2026', 'PATROL ' . $digitos, '', '40', 'CHOFER UNO', '11111111', 'ZZ PROYECTO INEXISTENTE'];
        $texto = fn (array ...$f) => implode("\n", array_map(fn ($x) => implode("\t", $x), [$enc, ...$f]));

        $primera = $this->actingAs($u)->postJson(route('consumibles.importar.pegar'), ['texto' => $texto($vieja)])->assertOk();
        $idVeladero = (string) $primera->json('filas.0.id_frente');
        $this->guardarTabla($primera)->assertOk()->assertJson(['insertados' => 1]);

        $otra = $this->actingAs($u)->postJson(route('consumibles.importar.pegar'), ['texto' => $texto($vieja, $nueva)])->assertOk();
        $this->assertSame([true, false], array_column($otra->json('filas'), 'saltar'));
        $sinFrente = function ($f) { $f['frente'] = 'OTRO TEXTO SIN FRENTE'; $f['id_frente'] = ''; return $f; };
        $antes = DB::table('consumibles')->count();

        $r = $this->guardarTabla($otra, fn ($f, $i) => $i === 0 ? $sinFrente($f) : $f)->assertStatus(422);
        $this->assertStringContainsString('ZZ PROYECTO INEXISTENTE', $r->json('message'));
        $this->assertStringNotContainsString('OTRO TEXTO SIN FRENTE', $r->json('message'));
        $this->assertSame($antes, DB::table('consumibles')->count());

        $this->guardarTabla($otra, function ($f, $i) use ($sinFrente, $idVeladero) {
            if ($i === 0) return $sinFrente($f);
            $f['id_frente'] = $idVeladero;
            return $f;
        })->assertOk()->assertJson(['insertados' => 1, 'saltados' => 1]);
    }

    /** Gráficos abre en el último mes con gasoil cargado, sin contar fechas futuras. */
    public function test_graficos_abre_en_el_ultimo_mes_cargado(): void
    {
        $u = $this->superAdminGlobal();
        $hoy = now();
        DB::table('consumibles')->insert([
            ['FECHA' => $hoy->toDateString(), 'TIPO_CONSUMIBLE' => 'GASOIL', 'UNIDAD' => 'LITROS', 'CANTIDAD' => 10, 'ESTADO_EQUIPO' => 'SIN_MATCH', 'created_at' => $hoy, 'updated_at' => $hoy],
            ['FECHA' => $hoy->copy()->addYear()->toDateString(), 'TIPO_CONSUMIBLE' => 'GASOIL', 'UNIDAD' => 'LITROS', 'CANTIDAD' => 10, 'ESTADO_EQUIPO' => 'SIN_MATCH', 'created_at' => $hoy, 'updated_at' => $hoy],
        ]);
        $this->actingAs($u)->getJson(route('consumibles.graficosUltimoMes'))->assertOk()->assertJson(['mes' => $hoy->format('Y-m')]);
    }

    /** Lo pegado de un Excel en inglés («1,234.50») y en español («1.000») da los litros bien. */
    public function test_el_reporte_pegado_lee_los_numeros_de_los_dos_formatos(): void
    {
        $texto = "FECHA	PLACA	SALIDA	PROYECTO
01/08/2026	ZZ97TST	1,234.50	VELADERO
02/08/2026	ZZ97TST	1.000	VELADERO";
        $r = $this->actingAs($this->superAdminGlobal())->postJson(route('consumibles.importar.pegar'), ['texto' => $texto])->assertOk();
        $this->assertEquals([1234.5, 1000.0], array_map('floatval', array_column($r->json('filas'), 'cantidad')));
    }

    /** Una celda larga del Excel (dos cédulas en una) no tumba el guardado: se recorta. */
    public function test_una_celda_larga_del_excel_se_recorta_y_se_guarda(): void
    {
        [, $digitos] = $this->motoniveladora();
        $subida = $this->subir($this->superAdminGlobal(), $this->excel($digitos))->assertOk();
        $this->guardarTabla($subida, function ($f, $i) {
            if ($i === 0) {
                $f['resp_ci'] = 'V-12.345.678 / V-9.876.543';
                $f['identificador'] = str_repeat('PATROL ', 20);
            }
            return $f;
        })->assertOk()->assertJson(['insertados' => 5]);
        $this->assertSame(20, mb_strlen((string) DB::table('consumibles')->where('ID_IMPORTACION', $subida->json('importacion'))->orderBy('ID_CONSUMIBLE')->value('RESP_CI')));
    }

    /**
     * Lo que se cambia en la tabla es lo que se guarda (litros, cédula); una fila borrada ahí no
     * entra ni hace «repetida» a su copia; y el Excel solo se guarda como gasoil.
     */
    public function test_se_guarda_lo_escrito_en_la_tabla(): void
    {
        [, $digitos] = $this->motoniveladora();
        $u = $this->superAdminGlobal();
        $subida = $this->subir($u, $this->excel($digitos))->assertOk();

        $this->guardarTabla($subida, null, [], 'ACEITE')->assertStatus(422)->assertJsonValidationErrors('tipo_consumible');

        $this->guardarTabla($subida, function ($f, $i) {
            if ($i === 0) {
                return null;                                   // borrada en la tabla
            }
            if ($i === 1) {
                $f['cantidad'] = '61.5';
                $f['resp_ci'] = '99999999';
            }

            return $f;
        })->assertOk()->assertJson(['insertados' => 4]);

        $filas = DB::table('consumibles')->where('ID_IMPORTACION', $subida->json('importacion'))->orderBy('ID_CONSUMIBLE')->get();
        $this->assertCount(4, $filas);
        $this->assertEquals(61.5, (float) $filas->first()->CANTIDAD);
        $this->assertSame('99999999', $filas->first()->RESP_CI);
    }

    /**
     * Un reporte con las columnas en otro orden y con otras de más: cada una por su encabezado;
     * la que no es despacho (la recarga) fuera, y el mismo archivo otra vez no repite lo ya cargado.
     * (Lo pegado con Ctrl+V ya no va al lector: va a la tabla de Cargar, 26-11.)
     */
    public function test_columnas_en_otro_orden_y_de_mas(): void
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

        $r = $this->subir($u, $this->tabla($texto))->assertOk();
        $this->guardarTabla($r)->assertOk();
        $filas = DB::table('consumibles')->where('ID_IMPORTACION', $r->json('importacion'))->orderBy('FECHA')->get();
        $this->assertCount(3, $filas, 'la recarga del tanque no es un consumo');
        $this->assertEquals([80, 60.5, 40], $filas->pluck('CANTIDAD')->map(fn ($c) => (float) $c)->all(), 'litros de SALIDA, con coma decimal');
        $this->assertCount(2, $filas->where('ID_EQUIPO', $equipo->ID_EQUIPO));
        $this->assertSame('11111111', $filas->first()->RESP_CI);

        // El mismo archivo otra vez: todo ya estaba.
        $this->assertSame(3, $this->subir($u, $this->tabla($texto))->assertOk()->json('cargados'));

        // Sin encabezado reconocible, lo dice.
        $this->subir($u, $this->tabla("01/08/2026\tABC123\t40"))->assertStatus(422);
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

        $this->subir($u, $this->tabla($texto))->assertOk();
        $filas = ConsumibleImportacion::latest('ID_IMPORTACION')->firstOrFail()->filas();

        $this->assertCount(1, $filas, 'la recarga (ENTRADA) no es un despacho');
        $this->assertSame('2026-08-05', $filas[0]['fecha']);
        $this->assertEquals(70, $filas[0]['litros']);
        $this->assertSame('33333333', $filas[0]['ci']);
        $this->assertSame('CAMION OTRO NOMBRE', $filas[0]['desc']);
        $this->assertSame('ZZ96TST', $filas[0]['placa']);

        // El despachador y la hora de salida no son los litros.
        $this->subir($u, $this->tabla("FECHA\tDESPACHADOR\tHORA DE SALIDA\tPLACA\tLITROS\n07/08/2026\tLUIS\t07:30\tZZ96TST\t55"))->assertOk();
        $filas = ConsumibleImportacion::latest('ID_IMPORTACION')->firstOrFail()->filas();
        $this->assertEquals([55], array_column($filas, 'litros'));

        // Una hoja de resumen «FECHA | LITROS» (sin equipo ni SALIDA) no es de despachos.
        $this->subir($u, $this->tabla("FECHA\tLITROS\n07/08/2026\t5000"))->assertStatus(422);
    }

    /**
     * Sin columna de responsable, el CHOFER es quien recibe; con las dos (reporte de Ambiente) vale
     * la del responsable. Los litros escritos con su unidad no pierden los miles.
     */
    public function test_chofer_como_responsable_y_litros_con_unidad(): void
    {
        $u = $this->superAdminGlobal();
        $this->subir($u, $this->tabla("FECHA\tPLACA\tCHOFER\tC.I.\tLITROS\n"
            . "05/08/2026\tZZ96TST\tJUAN CHOFER\t111\t1.200 LTS\n06/08/2026\tZZ96TST\tJUAN CHOFER\t111\t40,5 L"))->assertOk();
        $filas = ConsumibleImportacion::latest('ID_IMPORTACION')->firstOrFail()->filas();
        $this->assertSame(['JUAN CHOFER', 'JUAN CHOFER'], array_column($filas, 'resp'));
        $this->assertEquals([1200, 40.5], array_column($filas, 'litros'));

        $this->subir($u, $this->tabla("FECHA\tCHOFER\tPLACA\tNOMBRE DEL RESPONSABLE\tLITROS\n"
            . "07/08/2026\tEL DE LA CISTERNA\tZZ96TST\tQUIEN RECIBE\t55"))->assertOk();
        $filas = ConsumibleImportacion::latest('ID_IMPORTACION')->firstOrFail()->filas();
        $this->assertSame(['QUIEN RECIBE'], array_column($filas, 'resp'));
    }

    /**
     * Una celda con salto de línea (Alt+Enter) sigue siendo UNA celda, y la fecha escrita como
     * texto (con hora, con el mes escrito o año primero) se lee igual.
     */
    public function test_celdas_con_salto_de_linea_y_fechas_escritas(): void
    {
        $u = $this->superAdminGlobal();
        $texto = "FECHA\tDESCRIPCION\tSALIDA\tPROYECTO\n"
            . "01/08/2026 08:30\t\"CAMION PEGADO\nCON SALTO\"\t11\tVELADERO\n"
            . "02-ago-26\tCAMION PEGADO\t12\tVELADERO\n"
            . "3 de agosto de 2026\tCAMION PEGADO\t13\tVELADERO\n"
            . "2026-08-04\tCAMION PEGADO\t14\tVELADERO";

        $this->subir($u, $this->tabla($texto))->assertOk();
        $filas = ConsumibleImportacion::latest('ID_IMPORTACION')->firstOrFail()->filas();

        $this->assertSame(['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04'], array_column($filas, 'fecha'));
        $this->assertEquals([11, 12, 13, 14], array_column($filas, 'litros'), 'cuatro despachos: la celda con salto no partió la fila');
        $this->assertStringContainsString('CON SALTO', $filas[0]['desc']);
        $this->assertStringNotContainsString('"', $filas[0]['desc']);
    }

    /** El identificador cambiado en la tabla lo identifica el servidor con lo escrito. */
    public function test_el_identificador_corregido_en_la_tabla_identifica_el_equipo(): void
    {
        [$equipo, $digitos] = $this->motoniveladora();
        $u = $this->superAdminGlobal();
        $subida = $this->subir($u, $this->excel($digitos))->assertOk();
        $this->guardarTabla($subida, function ($f, $i) use ($equipo) {
            if ($i === 4) {
                $f['identificador'] = $equipo->SERIAL_CHASIS;   // el camión desconocido, con su serial
            }

            return $f;
        })->assertOk();

        $c = DB::table('consumibles')->where('ID_IMPORTACION', $subida->json('importacion'))->orderByDesc('ID_CONSUMIBLE')->first();
        $this->assertSame('CONFIRMADO', $c->ESTADO_EQUIPO);
        $this->assertSame($equipo->ID_EQUIPO, (int) $c->ID_EQUIPO);
    }

    public function test_por_identificar_se_resuelve_escribiendo_el_serial_completo(): void
    {
        [$equipo, $digitos] = $this->motoniveladora();
        $u = $this->superAdminGlobal();
        $subida = $this->subir($u, $this->excel($digitos))->assertOk();
        $this->guardarTabla($subida)->assertOk();
        $imp = ConsumibleImportacion::findOrFail($subida->json('importacion'));

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
        // Los Excel guardados no van en la pantalla: los muestra el modal «Excel cargados» de Acciones.
        $this->assertStringNotContainsString('Cargas de Excel', $html);
        $this->assertStringContainsString('id="excelCargadosModal"', $html);
        $this->assertStringContainsString('data-abrir-excel-cargados', $html);
        $this->assertStringNotContainsString('importar_revision', $html);
        $this->assertStringContainsString('id="formLote"', $html, 'Y la tabla para pegar, debajo.');
    }

    public function test_el_modal_lista_solo_los_excel_cargados(): void
    {
        $u = $this->superAdminGlobal();
        $viva = ConsumibleImportacion::create(['ESTADO' => ConsumibleImportacion::APLICADA, 'ARCHIVOS' => ['hojas' => [['archivo' => 'VIVO.xlsx']]],
            'FILAS' => '[]', 'INSERTADOS' => 3, 'LITROS' => 120, 'ID_USUARIO' => $u->ID_USUARIO]);
        $borrada = ConsumibleImportacion::create(['ESTADO' => ConsumibleImportacion::DESHECHA, 'ARCHIVOS' => ['hojas' => [['archivo' => 'BORRADO.xlsx']]],
            'FILAS' => '[]', 'INSERTADOS' => 0, 'LITROS' => 0, 'ID_USUARIO' => $u->ID_USUARIO]);
        $r = $this->actingAs($u)->getJson(route('consumibles.importar.cargas'))->assertOk();
        $archivos = collect($r->json('cargas'))->pluck('archivos');
        $this->assertContains('VIVO.xlsx', $archivos);
        $this->assertNotContains('BORRADO.xlsx', $archivos, 'Uno ya borrado no se lista.');
        $this->assertSame(route('consumibles.importar.deshacer', $viva), collect($r->json('cargas'))->firstWhere('archivos', 'VIVO.xlsx')['borrar']);
        $viva->delete(); $borrada->delete();
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
    }
}
