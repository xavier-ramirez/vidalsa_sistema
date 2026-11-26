<?php

namespace Tests\Feature;

use App\Models\Consumible;
use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Cargar consumibles → tabla: lo pegado puede traer varios proyectos y cada fila lleva el suyo
 * (columna FRENTE). Se reconoce con las reglas del Excel de gasoil aunque el nombre no venga
 * exacto; lo que no se reconoce no toma el frente de arriba, se elige en la fila y queda
 * aprendido. Frentes inventados; la transacción de cada caso lo revierte todo.
 */
class ConsumiblesFrentePorFilaTest extends MySqlTestCase
{
    private FrenteTrabajo $norte;
    private FrenteTrabajo $sur;
    private string $marca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marca = strtoupper(Str::random(5));
        $this->norte = FrenteTrabajo::create(['NOMBRE_FRENTE' => 'PRUEBA VIALIDAD NORTE ' . $this->marca]);
        $this->sur = FrenteTrabajo::create(['NOMBRE_FRENTE' => 'PRUEBA ACUEDUCTO SUR ' . $this->marca]);
    }

    private function fila(string $placa, ?string $frente, ?int $idFrente = null): array
    {
        return ['fecha' => '2026-08-01', 'identificador' => $placa, 'cantidad' => 50, 'frente' => $frente, 'id_frente' => $idFrente];
    }

    private function guardar(array $filas, ?int $arriba = null)
    {
        return $this->actingAs($this->superAdminGlobal())->postJson(route('consumibles.guardarLote'), [
            'tipo_consumible' => 'GASOIL', 'unidad' => 'LITROS', 'id_frente' => $arriba, 'filas' => $filas,
        ]);
    }

    private function frenteDe(string $placa): ?int
    {
        return Consumible::where('IDENTIFICADOR', $placa)->value('ID_FRENTE');
    }

    public function test_cada_fila_va_a_su_proyecto_aunque_no_venga_exacto(): void
    {
        $p = 'TST-' . $this->marca;
        $this->guardar([
            $this->fila($p . '1', 'PRUEBA VIALIDAD NORTE ' . $this->marca),
            $this->fila($p . '2', '  prueba acueducto  sur ' . strtolower($this->marca) . ' (tramo 2)'),   // minúsculas, espacios y paréntesis
            $this->fila($p . '3', null),                                                                    // sin proyecto: el de arriba
        ], $this->norte->ID_FRENTE)->assertOk()->assertJson(['insertados' => 3]);

        $this->assertSame($this->norte->ID_FRENTE, $this->frenteDe($p . '1'));
        $this->assertSame($this->sur->ID_FRENTE, $this->frenteDe($p . '2'));
        $this->assertSame($this->norte->ID_FRENTE, $this->frenteDe($p . '3'));
    }

    public function test_un_nombre_mal_escrito_se_reconoce(): void
    {
        $r = $this->actingAs($this->superAdminGlobal())->postJson(route('consumibles.frentesDeTextos'), [
            'textos' => ['PRUEBA VIALIDA NORTE ' . $this->marca, 'PRUEBA ACUEDCUTO SUR ' . $this->marca],
        ])->assertOk()->json('frentes');

        $this->assertSame($this->norte->ID_FRENTE, $r['PRUEBA VIALIDA NORTE ' . $this->marca]['id'] ?? null);
        $this->assertSame($this->sur->ID_FRENTE, $r['PRUEBA ACUEDCUTO SUR ' . $this->marca]['id'] ?? null);
    }

    public function test_el_que_no_se_reconoce_no_toma_el_de_arriba_y_no_se_guarda_nada(): void
    {
        $p = 'TST-' . $this->marca;
        $this->guardar([
            $this->fila($p . '1', 'PRUEBA VIALIDAD NORTE ' . $this->marca),
            $this->fila($p . '2', 'OBRA DESCONOCIDA ' . $this->marca),
        ], $this->norte->ID_FRENTE)->assertStatus(422)
            ->assertJsonFragment(['message' => 'No se reconoce el proyecto «OBRA DESCONOCIDA ' . $this->marca . '»: elige su frente en la fila.']);

        $this->assertSame(0, Consumible::where('IDENTIFICADOR', 'like', $p . '%')->count());
    }

    public function test_sin_proyecto_y_sin_el_de_arriba_lo_pide(): void
    {
        $this->guardar([$this->fila('TST-' . $this->marca, null)])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Hay filas sin proyecto: elige el frente de arriba o escríbelo en la fila.']);
    }

    public function test_el_frente_elegido_en_la_fila_se_guarda_y_queda_aprendido(): void
    {
        $texto = 'OBRA DESCONOCIDA ' . $this->marca;
        $u = $this->superAdminGlobal();

        $antes = $this->actingAs($u)->postJson(route('consumibles.frentesDeTextos'), ['textos' => [$texto]])->assertOk();
        $this->assertNull($antes->json('frentes')[$texto]);

        $this->guardar([$this->fila('TST-' . $this->marca, $texto, $this->sur->ID_FRENTE)])->assertOk();
        $this->assertSame($this->sur->ID_FRENTE, $this->frenteDe('TST-' . $this->marca));

        // La próxima vez (tabla o Excel) ese texto ya se reconoce solo.
        $despues = $this->actingAs($u)->postJson(route('consumibles.frentesDeTextos'), ['textos' => [$texto, '']])->assertOk();
        $this->assertSame(['id' => $this->sur->ID_FRENTE, 'nombre' => $this->sur->NOMBRE_FRENTE], $despues->json('frentes')[$texto]);
    }

    public function test_sugiere_el_frente_del_equipo_y_tomarla_no_se_aprende(): void
    {
        $serial = 'TSTSUG' . random_int(1000000, 9999999);
        $equipo = Equipo::create(['MARCA' => 'PRUEBA', 'MODELO' => 'SUGERIDO', 'ANIO' => 2020, 'SERIAL_CHASIS' => $serial,
            'id_tipo_equipo' => DB::table('tipo_equipos')->value('id')]);
        DB::table('equipos')->where('ID_EQUIPO', $equipo->ID_EQUIPO)->update(['ID_FRENTE_ACTUAL' => $this->sur->ID_FRENTE]);
        $texto = 'PATIO DESCONOCIDO ' . $this->marca;
        $u = $this->superAdminGlobal();

        $r = $this->actingAs($u)->postJson(route('consumibles.frentesDeTextos'), ['textos' => [$texto], 'equipos' => [$serial, 'NADA ' . $this->marca]])->assertOk();
        $this->assertNull($r->json('frentes')[$texto]);
        $this->assertSame($this->sur->ID_FRENTE, $r->json('sugeridos')[$serial]['id'] ?? null);
        $this->assertArrayNotHasKey('NADA ' . $this->marca, $r->json('sugeridos'));

        // Tomada la sugerencia: la fila va a ese frente, pero el texto no queda aprendido.
        $this->guardar([$this->fila($serial, $texto, $this->sur->ID_FRENTE) + ['sugerido' => '1']])->assertOk();
        $this->assertSame($this->sur->ID_FRENTE, $this->frenteDe($serial));
        $otra = $this->actingAs($u)->postJson(route('consumibles.frentesDeTextos'), ['textos' => [$texto]])->assertOk();
        $this->assertNull($otra->json('frentes')[$texto]);
    }

    public function test_un_texto_demasiado_largo_se_avisa_sin_error_de_la_base(): void
    {
        $r = $this->guardar([['fecha' => '2026-08-01', 'identificador' => str_repeat('X', 101), 'cantidad' => 50,
            'frente' => null, 'id_frente' => null]], $this->norte->ID_FRENTE)->assertStatus(422);
        $this->assertStringNotContainsString('SQLSTATE', $r->getContent());
        $this->assertArrayHasKey('filas.0.identificador', $r->json('errors'));
    }

    public function test_reconocer_textos_es_solo_para_super_admin(): void
    {
        $u = $this->superAdminGlobal();
        $u->PERMISOS = 'equipos.edit';
        $this->actingAs($u)->postJson(route('consumibles.frentesDeTextos'), ['textos' => ['X']])->assertForbidden();
    }
}
