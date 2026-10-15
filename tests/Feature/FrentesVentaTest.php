<?php

namespace Tests\Feature;

use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Frentes de tipo VENTA (activos vendidos o por vender): como ESPECIAL, quedan FUERA de la
 * flota (FrenteTrabajo::TIPOS_FUERA_DE_FLOTA) y, en Equipos, se resaltan con la etiqueta
 * "EN VENTA" y un aviso al entrar al frente. Corre en la transacción de la prueba.
 */
class FrentesVentaTest extends MySqlTestCase
{
    /** Frente VENTA creado por el formulario, con un equipo real movido adentro. */
    private function frenteVentaConEquipo(): array
    {
        $nombre = 'VENTA PRUEBA ' . strtoupper(Str::random(6));
        $this->actingAs($this->superAdminGlobal())->postJson('/admin/frentes?json=true', [
            'NOMBRE_FRENTE' => $nombre, 'UBICACION' => 'EL TIGRE', 'TIPO_FRENTE' => 'VENTA', 'ESTATUS_FRENTE' => 'ACTIVO',
        ])->assertOk()->assertJson(['success' => true]);

        $frente = FrenteTrabajo::where('NOMBRE_FRENTE', $nombre)->firstOrFail();
        $equipo = Equipo::whereNotNull('ID_FRENTE_ACTUAL')->whereNotIn('ID_FRENTE_ACTUAL', FrenteTrabajo::idsFueraDeFlota())->firstOrFail();
        DB::table('equipos')->where('ID_EQUIPO', $equipo->ID_EQUIPO)->update(['ID_FRENTE_ACTUAL' => $frente->ID_FRENTE]);

        return [$frente, (int) $equipo->ID_EQUIPO];
    }

    public function test_venta_queda_fuera_de_la_flota_como_especial(): void
    {
        [$frente, $idEquipo] = $this->frenteVentaConEquipo();

        $this->assertTrue($frente->esVenta());
        $this->assertTrue(FrenteTrabajo::esFueraDeFlota($frente->ID_FRENTE), 'Cuenta como fuera de la flota.');
        $this->assertFalse(Equipo::excludeFueraDeFlota()->where('ID_EQUIPO', $idEquipo)->exists(),
            'Su equipo no entra en los listados y totales de la flota.');
        // ESPECIAL sigue igual que antes.
        $especial = FrenteTrabajo::where('TIPO_FRENTE', 'ESPECIAL')->value('ID_FRENTE');
        if ($especial) {
            $this->assertTrue(FrenteTrabajo::esFueraDeFlota($especial));
        }
    }

    public function test_al_entrar_al_frente_se_ven_sus_equipos_y_el_aviso_de_venta(): void
    {
        [$frente, $idEquipo] = $this->frenteVentaConEquipo();
        $admin = $this->superAdminGlobal();

        $json = $this->actingAs($admin)->getJson('/admin/equipos?id_frente=' . $frente->ID_FRENTE)->assertOk();
        $this->assertSame($frente->NOMBRE_FRENTE, $json->json('frenteVenta'), 'El aviso recibe el nombre del frente.');
        $this->assertStringContainsString('EN VENTA', (string) $json->json('html'), 'La fila lleva la etiqueta EN VENTA.');

        $html = (string) $this->actingAs($admin)->get('/admin/equipos?id_frente=' . $frente->ID_FRENTE)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="eqAvisoVenta" class="aviso-venta" role="status"\s*>/', $html, 'El aviso se ve (sin hidden).');
        $this->assertContains($idEquipo, collect($this->actingAs($admin)->get('/admin/equipos?id_frente=' . $frente->ID_FRENTE)->viewData('equipos'))
            ->pluck('ID_EQUIPO')->map(fn ($v) => (int) $v)->all());
    }

    public function test_en_otro_frente_el_aviso_queda_escondido(): void
    {
        $normal = FrenteTrabajo::whereNotIn('TIPO_FRENTE', FrenteTrabajo::TIPOS_FUERA_DE_FLOTA)->value('ID_FRENTE');
        $admin = $this->superAdminGlobal();

        $this->assertNull($this->actingAs($admin)->getJson('/admin/equipos?id_frente=' . $normal)->assertOk()->json('frenteVenta'));
        $html = (string) $this->actingAs($admin)->get('/admin/equipos?id_frente=' . $normal)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="eqAvisoVenta" class="aviso-venta" role="status"\s+hidden/', $html);
    }

    public function test_el_formulario_de_frentes_ofrece_venta(): void
    {
        $html = (string) $this->actingAs($this->superAdminGlobal())->get('/admin/frentes/create')->assertOk()->getContent();
        $this->assertStringContainsString("selectOption('tipoSelect', 'VENTA', 'VENTA', 'tipo')", $html);
    }
}
