<?php

namespace Tests\Feature;

use App\Http\Controllers\CuposCombustibleController;
use App\Models\CisternaCupo;
use App\Models\CupoCombustible;
use Illuminate\Support\Carbon;
use Tests\MySqlTestCase;

/**
 * Cupo fijo de gasoil por patio y sus cisternas: cada una empieza como solicitud (número y plan de
 * despacho) y, al llegar, lleva fecha de llegada, litros y foto; solo las que llegaron consumen el cupo.
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

    /** Una cisterna que ya llegó (fecha de llegada y litros). */
    private function cisterna(string $patio, string $llegada, $litros)
    {
        return $this->filas($patio, [['llegada' => $llegada, 'litros' => $litros]]);
    }

    /** Filas de la tabla en un solo guardado: [['id'?, 'numero'?, 'plan'?, 'llegada'?, 'litros'?], ...]. */
    private function filas(string $patio, array $filas)
    {
        return $this->actingAs($this->superAdminGlobal())->postJson(route('consumibles.cupos.cisterna'), [
            'patio' => $patio, 'cisternas' => $filas,
        ]);
    }

    private function modal(array $q = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->superAdminGlobal())->get(route('consumibles.cupos', ['parcial' => 1] + $q))->assertOk();
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

        // Cambiarlo en mayo rige desde mayo; marzo conserva el suyo (se dice al ver marzo).
        $this->cupo('TIGRE', 120000)->assertOk();
        $this->modal()->assertSee('value="120.000"', false);
        $this->modal(['patio' => 'TIGRE', 'mes' => '2031-03'])->assertSee('1 recibida · 30.000 L')->assertSee('cupo de ese mes 100.000 L');
    }

    public function test_plan_de_despacho_y_luego_la_llegada(): void
    {
        // Primero el plan (sin número: toma el que sigue); el plan puede ser futuro.
        $this->filas('TIGRE', [['plan' => '2031-03-25'], ['numero' => 40, 'plan' => '2031-04-02']])->assertOk()
            ->assertJsonPath('meses.2031-04', 'Abril 2031');
        $marzo = CisternaCupo::where('FECHA_PLAN', '2031-03-25')->first();
        $this->assertSame(41, $marzo->NUMERO);   // después del mayor de la tabla (40)
        $this->assertFalse($marzo->llego());
        $this->modal()->assertSee('0 recibidas · 0 L')->assertSee('1 programada')->assertSee('cupos-programada', false);

        // Llegó antes de lo planeado (otro día): se cambia la fecha y se ponen los litros.
        Carbon::setTestNow('2031-03-28 10:00:00');
        $this->filas('TIGRE', [['id' => $marzo->ID_CISTERNA, 'numero' => 41, 'plan' => '2031-03-25', 'llegada' => '2031-03-27', 'litros' => '38.000']])->assertOk();
        $marzo->refresh();
        $this->assertTrue($marzo->llego());
        $this->assertSame('2031-03-27', $marzo->FECHA->toDateString());
        $this->assertSame('2031-03-25', $marzo->FECHA_PLAN->toDateString());   // el plan queda
        $this->modal()->assertSee('1 recibida · 38.000 L')->assertDontSee('programada</');

        // Abril (por venir) tiene la 40 programada.
        $this->modal(['patio' => 'TIGRE', 'mes' => '2031-04'])->assertSee('value="40"', false)->assertSee('1 programada');
    }

    public function test_reglas_de_cada_fila(): void
    {
        $this->cisterna('MATURIN', '2031-03-21', 1000)->assertStatus(422)->assertJsonValidationErrors('cisternas.0.llegada');   // llegada futura
        $this->filas('MATURIN', [['plan' => '2031-08-01']])->assertStatus(422)->assertJsonValidationErrors('cisternas.0.plan');  // más de 3 meses
        $this->cisterna('OTRO', '2031-03-05', 1000)->assertStatus(422)->assertJsonValidationErrors('patio');
        $this->cisterna('MATURIN', '2031-03-05', 0)->assertStatus(422)->assertJsonValidationErrors('cisternas.0.litros');
        $this->filas('MATURIN', [['llegada' => '2031-03-05']])->assertStatus(422)->assertJsonValidationErrors('cisternas.0.litros');
        $this->filas('MATURIN', [['plan' => '2031-03-05', 'litros' => 100]])->assertStatus(422)->assertJsonValidationErrors('cisternas.0.llegada');
        $this->filas('MATURIN', [['numero' => 7]])->assertStatus(422)->assertJsonValidationErrors('cisternas.0.plan');
        $this->filas('MATURIN', [])->assertStatus(422)->assertJsonValidationErrors('cisternas');
        $this->filas('MATURIN', [['numero' => 5, 'plan' => '2031-03-05'], ['numero' => 5, 'plan' => '2031-03-06']])
            ->assertStatus(422)->assertJsonValidationErrors('cisternas.1.numero');

        // El número no se repite en el patio (en el otro sí puede).
        $this->filas('MATURIN', [['numero' => 5, 'plan' => '2031-03-05']])->assertOk();
        $this->filas('MATURIN', [['numero' => 5, 'plan' => '2031-03-09']])->assertStatus(422)->assertJsonValidationErrors('cisternas.0.numero');
        $this->filas('TIGRE', [['numero' => 5, 'plan' => '2031-03-09']])->assertOk();

        // Una de otro patio no se edita desde este.
        $otra = CisternaCupo::where('PATIO', 'TIGRE')->value('ID_CISTERNA');
        $this->filas('MATURIN', [['id' => $otra, 'plan' => '2031-03-10']])->assertStatus(422)->assertJsonValidationErrors('cisternas.0.plan');
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

    public function test_varias_filas_de_una_vez_todas_o_ninguna(): void
    {
        $this->filas('TIGRE', [['llegada' => '2031-03-02', 'litros' => '38.000'], ['llegada' => '2031-03-02', 'litros' => '38.000'],
            ['plan' => '2031-03-30']])->assertOk()->assertJson(['guardadas' => 3]);
        $this->assertSame([1, 2, 3], CisternaCupo::where('PATIO', 'TIGRE')->orderBy('NUMERO')->pluck('NUMERO')->all());

        // Una fila mala (la 2.ª, llegada futura): no se guarda ninguna y se dice cuál falló.
        $this->filas('TIGRE', [['llegada' => '2031-03-10', 'litros' => 1000], ['llegada' => '2031-04-01', 'litros' => 1000]])
            ->assertStatus(422)->assertJsonValidationErrors('cisternas.1.llegada')->assertJsonMissingValidationErrors('cisternas.0.llegada');
        $this->assertSame(3, CisternaCupo::where('PATIO', 'TIGRE')->count());
        $this->modal()->assertSee('2 recibidas · 76.000 L')->assertSee('1 programada')->assertSee('data-elegir-patio', false);
    }

    public function test_cisterna_fuera_de_los_meses_no_se_guarda(): void
    {
        // Se ven de abril 2030 a junio 2031: una de antes no se vería ni se podría quitar.
        $this->cisterna('TIGRE', '2030-03-31', 1000)->assertStatus(422)->assertJsonValidationErrors('cisternas.0.llegada');
        $this->cisterna('TIGRE', '2030-04-01', 1000)->assertOk();
        $this->modal(['patio' => 'TIGRE', 'mes' => '2029-01'])->assertSee('data-mes="2031-03"', false);   // mes fuera: el actual
    }

    public function test_guardada_con_plan_de_antes_se_puede_editar_y_no_pierde_su_numero(): void
    {
        // Plan de antes de la ventana y llegada dentro: se ve; cambiarle los litros no debe dar error por el plan.
        $k = CisternaCupo::create(['PATIO' => 'TIGRE', 'NUMERO' => 7, 'FECHA_PLAN' => '2030-03-28', 'FECHA' => '2030-04-02', 'LITROS' => 100]);
        $fila = ['id' => $k->ID_CISTERNA, 'numero' => 7, 'plan' => '2030-03-28', 'llegada' => '2030-04-02', 'litros' => 300];
        $this->filas('TIGRE', [$fila])->assertOk();
        $this->assertSame(300.0, (float) $k->fresh()->LITROS);
        // Mover el plan a otra fecha fuera de la ventana sí se rechaza.
        $this->filas('TIGRE', [['plan' => '2030-03-20'] + $fila])->assertStatus(422)->assertJsonValidationErrors('cisternas.0.plan');
        // Vaciar el número de una guardada se rechaza (no queda sin número).
        $this->filas('TIGRE', [['numero' => ''] + $fila])->assertStatus(422)->assertJsonValidationErrors('cisternas.0.numero');
        $this->assertSame(7, (int) $k->fresh()->NUMERO);
    }

    public function test_quitar_una_cisterna(): void
    {
        $k = CisternaCupo::create(['PATIO' => 'TIGRE', 'FECHA_PLAN' => '2031-03-03', 'FECHA' => '2031-03-03', 'LITROS' => 200]);

        $this->actingAs($this->superAdminGlobal())->deleteJson(route('consumibles.cupos.borrarCisterna', $k->ID_CISTERNA))->assertOk();
        $this->assertNull(CisternaCupo::find($k->ID_CISTERNA));
    }

    public function test_una_tabla_con_mes_y_excel(): void
    {
        $this->cupo('TIGRE', 100000)->assertOk();
        $this->filas('TIGRE', [['llegada' => '2031-02-10', 'litros' => 60000], ['llegada' => '2031-03-02', 'litros' => 30000],
            ['llegada' => '2031-03-09', 'litros' => 80000], ['plan' => '2031-03-28']])->assertOk();

        // Una sola tabla (sin «Registrar cisternas» ni lista aparte), con su mes.
        $html = $this->modal()->getContent();
        $this->assertStringNotContainsString('Registrar cisternas', $html);
        $this->assertStringNotContainsString('data-ver-historial', $html);
        $this->assertStringContainsString('data-elegir-mes', $html);
        $this->assertStringContainsString('data-cancelar', $html);   // Guardar o Cancelar al pie
        // El Excel se baja con el ícono de descarga del encabezado (al lado de la ✕), no con un botón en la tabla.
        $this->assertStringNotContainsString('data-excel-historial', $html);
        $this->actingAs($this->superAdminGlobal())->get(route('consumibles.cargar'))->assertOk()->assertSee('data-excel-cupos', false);
        $this->assertStringContainsString('data-cupo-de="MATURIN"', $html);

        $r = $this->get(route('consumibles.cupos.excel', ['patio' => 'TIGRE']))->assertOk();
        $this->assertStringContainsString('Cisternas_Cupo_Gasoil_Patio_El_Tigre_', $r->headers->get('Content-Disposition'));
        $ruta = tempnam(sys_get_temp_dir(), 'cupo') . '.xlsx';
        file_put_contents($ruta, $r->streamedContent());
        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta)->getActiveSheet();
        @unlink($ruta);

        // Marzo arriba: 1 (30.000, quedan 70.000), 2 (80.000, quedan −10.000), 4 programada, total; luego febrero.
        $fila = fn ($n) => array_map(fn ($c) => $hoja->getCell($c . $n)->getValue(), ['A', 'D', 'E', 'F', 'G', 'H']);
        $fecha = fn ($c) => \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($hoja->getCell($c)->getValue())->format('d/m/Y');
        $this->assertSame('02/03/2031', $fecha('C6'));
        $this->assertEquals([2, 'Recibida', 30000, 100000, 30000, 70000], $fila(6));
        $this->assertEquals([3, 'Recibida', 80000, 100000, 110000, -10000], $fila(7));
        $this->assertEquals([4, 'Programada', null, 100000, null, null], $fila(8));
        $this->assertSame('28/03/2031', $fecha('B8'));
        $this->assertStringStartsWith('TOTAL MARZO 2031 (2 recibidas)', $hoja->getCell('A9')->getValue());
        $this->assertEquals([110000, 100000, 110000, -10000], array_slice($fila(9), 2));
        $this->assertEquals([1, 'Recibida', 60000, 100000, 60000, 40000], $fila(10));

        $this->get(route('consumibles.cupos.excel', ['patio' => 'OTRO']))->assertStatus(302);
    }

    public function test_datos_para_el_powerpoint(): void
    {
        $this->cupo('TIGRE', 100000)->assertOk();
        $this->cupo('MATURIN', 50000)->assertOk();
        $this->filas('TIGRE', [['llegada' => '2031-02-10', 'litros' => 60000], ['llegada' => '2031-03-02', 'litros' => 30000],
            ['llegada' => '2031-03-09', 'litros' => 20000], ['plan' => '2031-04-03'], ['plan' => '2031-04-17']])->assertOk();
        $this->cisterna('MATURIN', '2031-03-05', 45000)->assertOk();

        $d = CuposCombustibleController::presentacion(Carbon::parse('2031-03-01'));
        $this->assertSame('MARZO 2031', $d['mes']);
        $this->assertEquals(['clave' => 'TIGRE', 'nombre' => 'Patio El Tigre', 'cupo' => 100000, 'consumido' => 50000, 'por_consumir' => 50000], $d['cupos'][0]);
        $this->assertEquals(5000, $d['cupos'][1]['por_consumir']);
        $t = $d['patios'][0];
        $this->assertCount(2, $t['recibidas']);
        $this->assertEquals(50000, $t['total_recibido']);
        $this->assertCount(8, $t['historico']);
        $this->assertEquals(['mes' => 'MARZO', 'litros' => 50000, 'cisternas' => 2], end($t['historico']));
        $this->assertEquals(['mes' => 'FEBRERO', 'litros' => 60000, 'cisternas' => 1], $t['historico'][6]);
        $this->assertSame('ABRIL', $t['cronograma']['mes']);   // las solicitudes del mes siguiente
        $this->assertSame('MARZO', $t['cronograma_mes']['mes']);   // la página: el del mes, con lo recibido
        $this->assertSame([3, 17], array_column($t['cronograma']['solicitudes']->all(), 'dia'));
        // Maturín no tiene solicitudes en abril: su cronograma es el de marzo (la que llegó).
        $this->assertSame('MARZO', $d['patios'][1]['cronograma']['mes']);
        $this->assertTrue($d['patios'][1]['cronograma']['solicitudes'][0]['llego']);
        // Sus litros van con la solicitud (la página los pone junto al camión); las que no llegaron, sin litros.
        $this->assertEquals(45000, $d['patios'][1]['cronograma']['solicitudes'][0]['litros']);
        $this->assertNull($t['cronograma']['solicitudes'][0]['litros']);
    }

    public function test_la_direccion_sola_abre_el_modal_en_cargar(): void
    {
        $this->actingAs($this->superAdminGlobal())->get(route('consumibles.cupos'))
            ->assertRedirect(route('consumibles.cargar', ['cupos' => 1]));
        $this->actingAs($this->superAdminGlobal())->get(route('consumibles.cargar', ['cupos' => 1]))
            ->assertOk()->assertSee('data-abrir="1"', false)->assertSee('data-abrir-cupos', false);
    }
}
