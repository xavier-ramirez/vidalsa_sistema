<?php

namespace Tests\Feature;

use App\Models\CisternaCupo;
use App\Models\CupoCombustible;
use Tests\MySqlTestCase;

/** Cupos de gasoil por patio y las cisternas pedidas de cada cupo (la transacción lo revierte todo). */
class CuposCombustibleTest extends MySqlTestCase
{
    private const MES = '2031-03';

    public function test_cargar_cupo_y_cisternas_muestra_lo_disponible(): void
    {
        $u = $this->superAdminGlobal();

        $this->actingAs($u)->postJson(route('consumibles.cupos.guardar'), ['patio' => 'TIGRE', 'mes' => self::MES, 'litros' => 100000])->assertOk();
        // Volver a guardar el mismo mes lo cambia (no repite el cupo).
        $this->actingAs($u)->postJson(route('consumibles.cupos.guardar'), ['patio' => 'TIGRE', 'mes' => self::MES, 'litros' => 120000])->assertOk();
        $this->assertSame(1, CupoCombustible::where('PATIO', 'TIGRE')->whereDate('MES', '2031-03-01')->count());

        foreach ([['2031-03-02', 'A12BC3D', 38000], ['2031-03-15', null, 38000]] as [$fecha, $cisterna, $litros]) {
            $this->actingAs($u)->postJson(route('consumibles.cupos.cisterna'), [
                'patio' => 'TIGRE', 'mes' => self::MES, 'fecha' => $fecha, 'cisterna' => $cisterna, 'litros' => $litros,
            ])->assertOk();
        }

        $this->actingAs($u)->get(route('consumibles.cargar', ['mes' => self::MES]))->assertOk()
            ->assertSee('Patio El Tigre')->assertSee('Patio Maturín')
            ->assertSee('120.000 L')->assertSee('76.000 L')->assertSee('44.000 L')
            ->assertSee('A12BC3D')->assertSee('02/03/2031')->assertSee('2 cisternas');
    }

    public function test_la_cisterna_pide_el_cupo_y_una_fecha_del_mes(): void
    {
        $u = $this->superAdminGlobal();
        $fila = ['patio' => 'MATURIN', 'mes' => self::MES, 'fecha' => '2031-03-05', 'litros' => 1000];

        $this->actingAs($u)->postJson(route('consumibles.cupos.cisterna'), $fila)->assertStatus(422)->assertJsonValidationErrors('litros');

        $this->actingAs($u)->postJson(route('consumibles.cupos.guardar'), ['patio' => 'MATURIN', 'mes' => self::MES, 'litros' => 500])->assertOk();
        $this->actingAs($u)->postJson(route('consumibles.cupos.cisterna'), ['fecha' => '2031-04-01'] + $fila)
            ->assertStatus(422)->assertJsonValidationErrors('fecha');
        $this->actingAs($u)->postJson(route('consumibles.cupos.cisterna'), ['patio' => 'OTRO'] + $fila)->assertStatus(422);

        // Pasarse del cupo se permite, pero se avisa en rojo.
        $this->actingAs($u)->postJson(route('consumibles.cupos.cisterna'), $fila)->assertOk();
        $this->actingAs($u)->get(route('consumibles.cupos', ['mes' => self::MES, 'parcial' => 1]))->assertOk()
            ->assertSee('Se pidió 500 L más que el cupo.');
    }

    public function test_quitar_una_cisterna(): void
    {
        $u = $this->superAdminGlobal();
        $cupo = CupoCombustible::create(['PATIO' => 'TIGRE', 'MES' => '2031-03-01', 'LITROS' => 1000]);
        $k = $cupo->cisternas()->create(['FECHA' => '2031-03-03', 'LITROS' => 200]);

        $this->actingAs($u)->deleteJson(route('consumibles.cupos.borrarCisterna', $k->ID_CISTERNA))->assertOk();
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
