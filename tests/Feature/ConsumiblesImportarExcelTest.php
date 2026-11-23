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
 * Consumibles → Cargar desde Excel, con un reporte de gasoil armado aquí mismo en el formato
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

    public function test_subir_revisar_aplicar_no_repetir_y_deshacer(): void
    {
        [$equipo, $digitos] = $this->motoniveladora();
        $ruta = $this->excel($digitos);
        $u = $this->superAdminGlobal();

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

    public function test_ejecutar_match_resuelve_lo_cargado_con_lo_ensenado_despues(): void
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

        $this->postJson(route('consumibles.matchAutomatico'))->assertOk();
        $this->assertSame(0, (clone $porIdentificar)->count());
        $this->assertSame(5, DB::table('consumibles')->where('ID_IMPORTACION', $imp->ID_IMPORTACION)
            ->where('ID_EQUIPO', $equipo->ID_EQUIPO)->where('ESTADO_EQUIPO', 'CONFIRMADO')->count());
    }

    public function test_solo_super_admin(): void
    {
        $u = Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->whereNotNull('PERMISOS')->get()
            ->first(fn ($u) => !$u->can('super.admin'));
        $this->assertNotNull($u, 'Hace falta un usuario sin super.admin.');

        $this->actingAs($u)->get(route('consumibles.importar'))->assertForbidden();
        $this->subir($u, $this->excel('1234567'))->assertForbidden();
    }
}
