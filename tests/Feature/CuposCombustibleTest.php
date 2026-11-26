<?php

namespace Tests\Feature;

use App\Models\CisternaCupo;
use App\Models\CupoCombustible;
use Illuminate\Support\Carbon;
use Tests\MySqlTestCase;

/**
 * Cupo fijo de gasoil por patio y las cisternas surtidas que lo consumen, con su historial por mes.
 * El «hoy» se fija en 2031 para no mezclarse con datos reales; la transacción lo revierte todo.
 */
class CuposCombustibleTest extends MySqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2031-03-20 10:00:00');
        // Sin los cupos y cisternas reales (dentro de la transacción: se devuelven al terminar).
        CupoCombustible::query()->delete();
        CisternaCupo::query()->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cupo(string $patio, $litros)
    {
        return $this->actingAs($this->superAdminGlobal())
            ->postJson(route('consumibles.cupos.guardar'), ['patio' => $patio, 'litros' => $litros]);
    }

    private function cisterna(string $patio, string $fecha, $litros)
    {
        return $this->cisternas($patio, [[$fecha, $litros]]);
    }

    /** Varias filas de la tabla en un solo guardado: [[fecha, litros], ...]. */
    private function cisternas(string $patio, array $filas)
    {
        return $this->actingAs($this->superAdminGlobal())->postJson(route('consumibles.cupos.cisterna'), [
            'patio' => $patio,
            'cisternas' => array_map(fn ($f) => ['fecha' => $f[0], 'litros' => $f[1]], $filas),
        ]);
    }

    private function modal(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->superAdminGlobal())->get(route('consumibles.cupos', ['parcial' => 1]))->assertOk();
    }

    public function test_el_cupo_es_fijo_y_si_cambia_rige_desde_ese_mes(): void
    {
        $this->cupo('TIGRE', 100000)->assertOk();
        $this->cisterna('TIGRE', '2031-03-02', 30000)->assertOk();

        // Dos meses después, sin volver a cargarlo, sigue el mismo; guardar lo mismo no crea otro.
        Carbon::setTestNow('2031-05-10 10:00:00');
        $this->cupo('TIGRE', 100000)->assertOk();
        $this->assertSame(1, CupoCombustible::where('PATIO', 'TIGRE')->where('MES', '>=', '2031-01-01')->count());
        $this->modal()->assertSee('value="100.000"', false);   // el cupo, en su cuadro

        // Cambiarlo en mayo rige desde mayo; marzo conserva el suyo en el historial.
        $this->cupo('TIGRE', 120000)->assertOk();
        $this->modal()->assertSee('value="120.000"', false)->assertSee('Marzo 2031')->assertSee('Cupo 100.000 L · 1 cisterna · Surtido 30.000 L');   // otro cupo: se dice
    }

    public function test_las_cisternas_consumen_el_cupo_de_su_patio(): void
    {
        $this->cupo('TIGRE', 100000)->assertOk();
        $this->cupo('MATURIN', 500)->assertOk();
        $this->cisterna('TIGRE', '2031-03-02', 38000)->assertOk();
        $this->cisterna('TIGRE', '2031-03-15', 38000)->assertOk();
        $this->cisterna('MATURIN', '2031-03-05', 1000)->assertOk();

        $this->actingAs($this->superAdminGlobal())->get(route('consumibles.cargar'))->assertOk()
            ->assertSee('data-abrir-cupos', false)->assertSee('Cupos de Gasoil')
            ->assertSee('2 cisternas · Surtido 76.000 L')->assertSee('24.000 L')   // El Tigre: quedan 24.000
            ->assertSee('02/03/2031')->assertSee('-500 L');              // Maturín se pasó
    }

    public function test_la_cisterna_pide_patio_y_fecha_no_futura(): void
    {
        $this->cisterna('MATURIN', '2031-03-21', 1000)->assertStatus(422)->assertJsonValidationErrors('cisternas.0.fecha');
        $this->cisterna('OTRO', '2031-03-05', 1000)->assertStatus(422)->assertJsonValidationErrors('patio');
        $this->cisterna('MATURIN', '2031-03-05', 0)->assertStatus(422)->assertJsonValidationErrors('cisternas.0.litros');
        $this->cisternas('MATURIN', [])->assertStatus(422)->assertJsonValidationErrors('cisternas');
        $this->cisterna('MATURIN', '2031-01-05', 1000)->assertOk();   // de un mes anterior: va a su mes
        // El cupo cargado después también cuenta para ese mes (es fijo).
        $this->cupo('MATURIN', 5000)->assertOk();
        $this->modal()->assertSee('Enero 2031')->assertSee('1 cisterna · Surtido 1.000 L · Quedó <b class="">4.000 L', false);
    }

    public function test_litros_escritos_como_se_muestran(): void
    {
        $this->cupo('TIGRE', '100.000')->assertOk();                  // punto de miles: 100000, no 100
        $this->cisterna('TIGRE', '2031-03-02', '38.000')->assertOk();
        $this->cisterna('TIGRE', '2031-03-03', '1.250,5')->assertOk();
        $this->cisterna('TIGRE', '2031-03-04', '10,5')->assertOk();
        $this->assertEquals(100000, CupoCombustible::where('PATIO', 'TIGRE')->value('LITROS'));
        $this->assertEquals([38000, 1250.5, 10.5], CisternaCupo::orderBy('FECHA')->pluck('LITROS')->map(fn ($l) => (float) $l)->all());
    }

    public function test_varias_cisternas_de_una_vez_todas_o_ninguna(): void
    {
        $this->cisternas('TIGRE', [['2031-03-02', '38.000'], ['2031-03-02', '38.000'], ['2031-03-09', '20000']])
            ->assertOk()->assertJson(['guardadas' => 3]);
        $this->assertSame(3, CisternaCupo::where('PATIO', 'TIGRE')->count());

        // Una fila mala (la 2.ª, fecha futura): no se guarda ninguna y se dice cuál falló.
        $this->cisternas('TIGRE', [['2031-03-10', 1000], ['2031-04-01', 1000]])
            ->assertStatus(422)->assertJsonValidationErrors('cisternas.1.fecha')->assertJsonMissingValidationErrors('cisternas.0.fecha');
        $this->assertSame(3, CisternaCupo::where('PATIO', 'TIGRE')->count());
        $this->modal()->assertSee('3 cisternas · Surtido 96.000 L')->assertSee('data-elegir-patio', false);
    }

    public function test_cisterna_fuera_del_historial_no_se_guarda(): void
    {
        // El historial va de abril 2030 a marzo 2031: una de antes no se vería ni se podría quitar.
        $this->cisterna('TIGRE', '2030-03-31', 1000)->assertStatus(422)->assertJsonValidationErrors('cisternas.0.fecha');
        $this->cisterna('TIGRE', '2030-04-01', 1000)->assertOk();
    }

    public function test_justo_el_cupo_no_sale_pasado(): void
    {
        $this->cupo('TIGRE', '1000,3')->assertOk();
        $this->cisterna('TIGRE', '2031-03-02', '1000,1')->assertOk();
        $this->cisterna('TIGRE', '2031-03-03', '0,2')->assertOk();
        $this->modal()->assertDontSee('-0,00')->assertSee('Quedó <b class="">0 L', false);
    }

    public function test_quitar_una_cisterna(): void
    {
        $k = CisternaCupo::create(['PATIO' => 'TIGRE', 'FECHA' => '2031-03-03', 'LITROS' => 200]);

        $this->actingAs($this->superAdminGlobal())->deleteJson(route('consumibles.cupos.borrarCisterna', $k->ID_CISTERNA))->assertOk();
        $this->assertNull(CisternaCupo::find($k->ID_CISTERNA));
    }

    public function test_historial_con_botones_y_en_excel(): void
    {
        $this->cupo('TIGRE', 100000)->assertOk();
        $this->cisternas('TIGRE', [['2031-02-10', 60000], ['2031-03-02', 30000], ['2031-03-09', 80000]])->assertOk();

        // La lista empieza cerrada, con «Ver historial» y «Excel» (sin cisternas, Excel apagado).
        $html = $this->modal()->getContent();
        $this->assertStringContainsString('data-ver-historial', $html);
        $this->assertStringContainsString('data-lista-historial hidden', $html);
        $this->assertMatchesRegularExpression('/data-excel-historial="MATURIN"[^>]*disabled/', $html);

        $r = $this->get(route('consumibles.cupos.excel', ['patio' => 'TIGRE']))->assertOk();
        $this->assertStringContainsString('Historial_Cupo_Gasoil_Patio_El_Tigre_', $r->headers->get('Content-Disposition'));
        $ruta = tempnam(sys_get_temp_dir(), 'cupo') . '.xlsx';
        file_put_contents($ruta, $r->streamedContent());
        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta)->getActiveSheet();
        @unlink($ruta);

        // Marzo arriba: 30.000 (quedan 70.000), 80.000 (quedan −10.000), total; luego febrero.
        $fila = fn ($n) => array_map(fn ($c) => $hoja->getCell($c . $n)->getValue(), ['B', 'C', 'D', 'E', 'F']);
        $this->assertSame('02/03/2031', \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($hoja->getCell('A6')->getValue())->format('d/m/Y'));
        $this->assertEquals(['Marzo 2031', 100000, 30000, 30000, 70000], $fila(6));
        $this->assertEquals(['Marzo 2031', 100000, 80000, 110000, -10000], $fila(7));
        $this->assertStringStartsWith('TOTAL MARZO 2031 (2 cisternas)', $hoja->getCell('A8')->getValue());
        $this->assertEquals([100000, 110000, 110000, -10000], array_slice($fila(8), 1));
        $this->assertEquals(['Febrero 2031', 100000, 60000, 60000, 40000], $fila(9));

        $this->get(route('consumibles.cupos.excel', ['patio' => 'OTRO']))->assertStatus(302);
    }

    public function test_la_direccion_sola_abre_el_modal_en_cargar(): void
    {
        $this->actingAs($this->superAdminGlobal())->get(route('consumibles.cupos'))
            ->assertRedirect(route('consumibles.cargar', ['cupos' => 1]));
        $this->actingAs($this->superAdminGlobal())->get(route('consumibles.cargar', ['cupos' => 1]))
            ->assertOk()->assertSee('data-abrir="1"', false);
    }
}
