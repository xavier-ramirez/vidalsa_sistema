<?php

namespace Tests\Feature;

use App\Models\CisternaCupo;
use App\Models\CupoCombustible;
use Tests\MySqlTestCase;

/** Cupo fijo de gasoil por patio y las cisternas surtidas que lo consumen (la transacción lo revierte todo). */
class CuposCombustibleTest extends MySqlTestCase
{
    private const MES = '2031-03';

    private function cupo(string $patio, $litros, string $mes = self::MES)
    {
        return $this->actingAs($this->superAdminGlobal())
            ->postJson(route('consumibles.cupos.guardar'), ['patio' => $patio, 'mes' => $mes, 'litros' => $litros]);
    }

    public function test_el_cupo_es_fijo_hasta_que_se_cambie(): void
    {
        $this->cupo('TIGRE', 100000)->assertOk();
        $this->cupo('MATURIN', 60000)->assertOk();

        // Meses después, sin volver a cargarlo, sigue el mismo.
        $this->actingAs($this->superAdminGlobal())->get(route('consumibles.cupos', ['mes' => '2031-07', 'parcial' => 1]))->assertOk()
            ->assertSee('100.000 L')->assertSee('60.000 L');

        // Guardar lo mismo no crea otro; cambiarlo en junio rige desde junio, y marzo queda igual.
        $this->cupo('TIGRE', 100000, '2031-05')->assertOk();
        $this->cupo('TIGRE', 120000, '2031-06')->assertOk();
        $this->assertSame(2, CupoCombustible::where('PATIO', 'TIGRE')->where('MES', '>=', '2031-01-01')->count());
        $this->actingAs($this->superAdminGlobal())->get(route('consumibles.cupos', ['mes' => '2031-07', 'parcial' => 1]))->assertSee('120.000 L');
        $this->actingAs($this->superAdminGlobal())->get(route('consumibles.cupos', ['mes' => self::MES, 'parcial' => 1]))->assertSee('100.000 L')->assertDontSee('120.000 L');
    }

    public function test_las_cisternas_consumen_el_cupo(): void
    {
        $u = $this->superAdminGlobal();
        $this->cupo('TIGRE', 100000)->assertOk();
        $this->cupo('MATURIN', 500)->assertOk();

        foreach ([['TIGRE', '2031-03-02', 38000], ['TIGRE', '2031-03-15', 38000], ['MATURIN', '2031-03-05', 1000]] as [$patio, $fecha, $litros]) {
            $this->actingAs($u)->postJson(route('consumibles.cupos.cisterna'), ['patio' => $patio, 'mes' => self::MES, 'fecha' => $fecha, 'litros' => $litros])->assertOk();
        }

        $this->actingAs($u)->get(route('consumibles.cargar', ['mes' => self::MES]))->assertOk()
            ->assertSee('Cupos de Gasoil')->assertSee('data-abrir-cupos', false)
            ->assertSee('Surtido 76.000 L · 2 cisternas')->assertSee('24.000 L')   // El Tigre: quedan 24.000
            ->assertSee('02/03/2031')->assertSee('-500 L');                            // Maturín se pasó
    }

    public function test_la_cisterna_pide_patio_y_una_fecha_del_mes(): void
    {
        $u = $this->superAdminGlobal();
        $fila = ['patio' => 'MATURIN', 'mes' => self::MES, 'fecha' => '2031-03-05', 'litros' => 1000];

        $this->actingAs($u)->postJson(route('consumibles.cupos.cisterna'), ['fecha' => '2031-04-01'] + $fila)
            ->assertStatus(422)->assertJsonValidationErrors('fecha');
        $this->actingAs($u)->postJson(route('consumibles.cupos.cisterna'), ['patio' => 'OTRO'] + $fila)
            ->assertStatus(422)->assertJsonValidationErrors('patio');
        $this->actingAs($u)->postJson(route('consumibles.cupos.cisterna'), $fila)->assertOk();
    }

    public function test_quitar_una_cisterna(): void
    {
        $k = CisternaCupo::create(['PATIO' => 'TIGRE', 'FECHA' => '2031-03-03', 'LITROS' => 200]);

        $this->actingAs($this->superAdminGlobal())->deleteJson(route('consumibles.cupos.borrarCisterna', $k->ID_CISTERNA))->assertOk();
        $this->assertNull(CisternaCupo::find($k->ID_CISTERNA));
    }

    public function test_mes_mal_escrito_muestra_el_actual(): void
    {
        $this->actingAs($this->superAdminGlobal())->get(route('consumibles.cargar', ['mes' => ['x']]))->assertOk();
        $this->actingAs($this->superAdminGlobal())->get(route('consumibles.cupos', ['mes' => '2031-13', 'parcial' => 1]))->assertOk();
        // El modal vive en Cargar consumibles: la dirección sola lleva allá con el mes.
        $this->actingAs($this->superAdminGlobal())->get(route('consumibles.cupos', ['mes' => self::MES]))
            ->assertRedirect(route('consumibles.cargar', ['mes' => self::MES]));
    }
}
