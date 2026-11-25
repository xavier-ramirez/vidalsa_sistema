<?php

namespace Tests\Feature;

use App\Models\CisternaCupo;
use App\Models\CupoCombustible;
use Tests\MySqlTestCase;

/** Cupos de gasoil por patio y las cisternas pedidas de cada cupo (la transacción lo revierte todo). */
class CuposCombustibleTest extends MySqlTestCase
{
    private const MES = '2031-03';

    public function test_el_cupo_sigue_rigiendo_hasta_que_se_cambie(): void
    {
        $u = $this->superAdminGlobal();
        $this->actingAs($u)->postJson(route('consumibles.cupos.guardar'), ['mes' => self::MES, 'cupos' => ['TIGRE' => 100000, 'MATURIN' => 60000]])->assertOk();

        // Meses después, sin volver a cargarlo, sigue el mismo.
        $this->actingAs($u)->get(route('consumibles.cupos', ['mes' => '2031-07', 'parcial' => 1]))->assertOk()
            ->assertSee('100.000 L')->assertSee('60.000 L')->assertSee('vigente desde Marzo 2031');

        // Guardar lo mismo no crea otro; cambiarlo en junio rige desde junio, y marzo queda igual.
        $this->actingAs($u)->postJson(route('consumibles.cupos.guardar'), ['mes' => '2031-05', 'cupos' => ['TIGRE' => 100000, 'MATURIN' => null]])->assertOk();
        $this->actingAs($u)->postJson(route('consumibles.cupos.guardar'), ['mes' => '2031-06', 'cupos' => ['TIGRE' => 120000]])->assertOk();
        $this->assertSame(2, CupoCombustible::where('PATIO', 'TIGRE')->where('MES', '>=', '2031-01-01')->count());
        $this->actingAs($u)->get(route('consumibles.cupos', ['mes' => '2031-07', 'parcial' => 1]))->assertSee('120.000 L');
        $this->actingAs($u)->get(route('consumibles.cupos', ['mes' => self::MES, 'parcial' => 1]))->assertSee('100.000 L')->assertDontSee('120.000 L');
    }

    public function test_cisternas_del_mes_y_lo_disponible(): void
    {
        $u = $this->superAdminGlobal();
        $this->actingAs($u)->postJson(route('consumibles.cupos.guardar'), ['mes' => self::MES, 'cupos' => ['TIGRE' => 100000, 'MATURIN' => 500]])->assertOk();

        foreach ([['TIGRE', '2031-03-02', 'a12bc3d', 38000], ['TIGRE', '2031-03-15', null, 38000], ['MATURIN', '2031-03-05', null, 1000]] as [$patio, $fecha, $cisterna, $litros]) {
            $this->actingAs($u)->postJson(route('consumibles.cupos.cisterna'), [
                'patio' => $patio, 'mes' => self::MES, 'fecha' => $fecha, 'cisterna' => $cisterna, 'litros' => $litros,
            ])->assertOk();
        }

        $this->actingAs($u)->get(route('consumibles.cargar', ['mes' => self::MES]))->assertOk()
            ->assertSee('Cupos de Gasoil')->assertSee('76.000 L')->assertSee('24.000 L')
            ->assertSee('A12BC3D')->assertSee('02/03/2031')->assertSee('-500 L');   // Maturín se pasó
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
        // La sección vive en Cargar consumibles: la dirección sola lleva allá con el mes.
        $this->actingAs($this->superAdminGlobal())->get(route('consumibles.cupos', ['mes' => self::MES]))
            ->assertRedirect(route('consumibles.cargar', ['mes' => self::MES]));
    }
}
