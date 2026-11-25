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
        return $this->actingAs($this->superAdminGlobal())
            ->postJson(route('consumibles.cupos.cisterna'), ['patio' => $patio, 'fecha' => $fecha, 'litros' => $litros]);
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
        $this->modal()->assertSee('100.000 L');

        // Cambiarlo en mayo rige desde mayo; marzo conserva el suyo en el historial.
        $this->cupo('TIGRE', 120000)->assertOk();
        $this->modal()->assertSee('120.000 L')->assertSee('Marzo 2031')->assertSee('Cupo 100.000 L · Surtido 30.000 L (1)');
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
            ->assertSee('Surtido 76.000 L (2)')->assertSee('24.000 L')   // El Tigre: quedan 24.000
            ->assertSee('02/03/2031')->assertSee('-500 L');              // Maturín se pasó
    }

    public function test_la_cisterna_pide_patio_y_fecha_no_futura(): void
    {
        $this->cisterna('MATURIN', '2031-03-21', 1000)->assertStatus(422)->assertJsonValidationErrors('fecha');
        $this->cisterna('OTRO', '2031-03-05', 1000)->assertStatus(422)->assertJsonValidationErrors('patio');
        $this->cisterna('MATURIN', '2031-03-05', 0)->assertStatus(422)->assertJsonValidationErrors('litros');
        $this->cisterna('MATURIN', '2031-01-05', 1000)->assertOk();   // de un mes anterior: va a su mes
        // El cupo cargado después también cuenta para ese mes (es fijo).
        $this->cupo('MATURIN', 5000)->assertOk();
        $this->modal()->assertSee('Enero 2031')->assertSee('Cupo 5.000 L · Surtido 1.000 L (1)');
    }

    public function test_quitar_una_cisterna(): void
    {
        $k = CisternaCupo::create(['PATIO' => 'TIGRE', 'FECHA' => '2031-03-03', 'LITROS' => 200]);

        $this->actingAs($this->superAdminGlobal())->deleteJson(route('consumibles.cupos.borrarCisterna', $k->ID_CISTERNA))->assertOk();
        $this->assertNull(CisternaCupo::find($k->ID_CISTERNA));
    }

    public function test_la_direccion_sola_abre_el_modal_en_cargar(): void
    {
        $this->actingAs($this->superAdminGlobal())->get(route('consumibles.cupos'))
            ->assertRedirect(route('consumibles.cargar', ['cupos' => 1]));
        $this->actingAs($this->superAdminGlobal())->get(route('consumibles.cargar', ['cupos' => 1]))
            ->assertOk()->assertSee('data-abrir="1"', false);
    }
}
