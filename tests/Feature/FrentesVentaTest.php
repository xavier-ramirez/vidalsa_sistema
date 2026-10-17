<?php

namespace Tests\Feature;

use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Frentes VENDIDO y TRÁMITE DE VENTA: como ESPECIAL, quedan FUERA de la flota
 * (FrenteTrabajo::TIPOS_FUERA_DE_FLOTA) y, en Equipos, se resaltan: etiqueta junto al nombre
 * del frente (también al buscar un equipo por serial) y aviso al entrar al frente.
 * Corre en la transacción de la prueba.
 */
class FrentesVentaTest extends MySqlTestCase
{
    /** Frente del tipo dado, creado por el formulario, con un equipo real movido adentro. */
    private function frenteConEquipo(string $tipo): array
    {
        $nombre = $tipo . ' PRUEBA ' . strtoupper(Str::random(6));
        $this->actingAs($this->superAdminGlobal())->postJson('/admin/frentes?json=true', [
            'NOMBRE_FRENTE' => $nombre, 'UBICACION' => 'EL TIGRE', 'TIPO_FRENTE' => $tipo, 'ESTATUS_FRENTE' => 'ACTIVO',
        ])->assertOk()->assertJson(['success' => true]);

        $frente = FrenteTrabajo::where('NOMBRE_FRENTE', $nombre)->firstOrFail();
        $equipo = Equipo::whereNotNull('ID_FRENTE_ACTUAL')->whereNotNull('SERIAL_CHASIS')->where('SERIAL_CHASIS', '<>', '')
            ->whereNotIn('ID_FRENTE_ACTUAL', FrenteTrabajo::idsFueraDeFlota())->firstOrFail();
        DB::table('equipos')->where('ID_EQUIPO', $equipo->ID_EQUIPO)->update(['ID_FRENTE_ACTUAL' => $frente->ID_FRENTE]);

        return [$frente, $equipo];
    }

    public function test_vendido_y_tramite_quedan_fuera_de_la_flota(): void
    {
        foreach ([FrenteTrabajo::TIPO_VENDIDO, FrenteTrabajo::TIPO_TRAMITE_VENTA] as $tipo) {
            [$frente, $equipo] = $this->frenteConEquipo($tipo);
            $this->assertTrue(FrenteTrabajo::esFueraDeFlota($frente->ID_FRENTE), "$tipo cuenta como fuera de la flota.");
            $this->assertFalse(Equipo::excludeFueraDeFlota()->where('ID_EQUIPO', $equipo->ID_EQUIPO)->exists(),
                "$tipo: su equipo no entra en los listados y totales de la flota.");
        }
        $especial = FrenteTrabajo::where('TIPO_FRENTE', 'ESPECIAL')->value('ID_FRENTE');
        if ($especial) {
            $this->assertTrue(FrenteTrabajo::esFueraDeFlota($especial), 'ESPECIAL sigue igual que antes.');
        }
    }

    public function test_al_entrar_a_un_frente_vendido_se_ve_el_aviso_rojo_y_la_etiqueta(): void
    {
        [$frente, $equipo] = $this->frenteConEquipo(FrenteTrabajo::TIPO_VENDIDO);
        $admin = $this->superAdminGlobal();

        $json = $this->actingAs($admin)->getJson('/admin/equipos?id_frente=' . $frente->ID_FRENTE)->assertOk();
        $this->assertSame('vendido', $json->json('frenteVenta.clase'));
        $this->assertSame($frente->NOMBRE_FRENTE, $json->json('frenteVenta.nombre'));
        $this->assertSame('Frente de activos vendidos', $json->json('frenteVenta.titulo'));
        $this->assertStringContainsString('etiqueta-venta--vendido">VENDIDO<', (string) $json->json('html'));

        $html = (string) $this->actingAs($admin)->get('/admin/equipos?id_frente=' . $frente->ID_FRENTE)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="eqAvisoVenta" class="aviso-venta aviso-venta--vendido" role="status"\s*>/', $html, 'Aviso visible, en rojo.');
    }

    public function test_al_entrar_a_un_frente_en_tramite_se_ve_el_aviso_naranja_y_la_etiqueta(): void
    {
        [$frente] = $this->frenteConEquipo(FrenteTrabajo::TIPO_TRAMITE_VENTA);
        $admin = $this->superAdminGlobal();

        $json = $this->actingAs($admin)->getJson('/admin/equipos?id_frente=' . $frente->ID_FRENTE)->assertOk();
        $this->assertSame('tramite', $json->json('frenteVenta.clase'));
        $this->assertSame('Frente en trámite de venta', $json->json('frenteVenta.titulo'));
        $this->assertStringContainsString('etiqueta-venta--tramite">EN TRÁMITE DE VENTA<', (string) $json->json('html'));
    }

    public function test_buscar_por_serial_muestra_la_etiqueta_del_frente(): void
    {
        [, $equipo] = $this->frenteConEquipo(FrenteTrabajo::TIPO_VENDIDO);

        $json = $this->actingAs($this->superAdminGlobal())
            ->getJson('/admin/equipos?search_query=' . urlencode($equipo->SERIAL_CHASIS))->assertOk();

        $this->assertStringContainsString('etiqueta-venta--vendido">VENDIDO<', (string) $json->json('html'),
            'Buscando por serial (todos los frentes), la fila dice que el equipo está VENDIDO.');
        $this->assertNull($json->json('frenteVenta'), 'Sin frente elegido no hay aviso de frente.');
    }

    public function test_en_otro_frente_el_aviso_queda_escondido(): void
    {
        $normal = FrenteTrabajo::whereNotIn('TIPO_FRENTE', FrenteTrabajo::TIPOS_FUERA_DE_FLOTA)->value('ID_FRENTE');
        $admin = $this->superAdminGlobal();

        $this->assertNull($this->actingAs($admin)->getJson('/admin/equipos?id_frente=' . $normal)->assertOk()->json('frenteVenta'));
        $html = (string) $this->actingAs($admin)->get('/admin/equipos?id_frente=' . $normal)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="eqAvisoVenta" class="aviso-venta aviso-venta--tramite" role="status"\s+hidden/', $html);
    }

    public function test_el_formulario_ofrece_las_dos_opciones_con_su_nombre(): void
    {
        $admin = $this->superAdminGlobal();
        $html = (string) $this->actingAs($admin)->get('/admin/frentes/create')->assertOk()->getContent();
        $this->assertStringContainsString("selectOption('tipoSelect', 'VENDIDO', 'ACTIVOS VENDIDOS', 'tipo')", $html);
        $this->assertStringContainsString("selectOption('tipoSelect', 'TRAMITE_VENTA', 'TRÁMITE DE VENTA', 'tipo')", $html);

        // Un frente en trámite muestra su tipo con el nombre en pantalla, no el código guardado.
        [$frente] = $this->frenteConEquipo(FrenteTrabajo::TIPO_TRAMITE_VENTA);
        $edit = (string) $this->actingAs($admin)->get('/admin/frentes/' . $frente->ID_FRENTE . '/edit')->assertOk()->getContent();
        $this->assertStringContainsString('<span id="label_tipo">TRÁMITE DE VENTA</span>', $edit);
    }

    public function test_cambiar_el_tipo_desde_equipos(): void
    {
        [$frente] = $this->frenteConEquipo('OPERACION');
        $admin = $this->superAdminGlobal();

        $this->actingAs($admin)->patchJson(route('frentes.cambiarTipo', $frente->ID_FRENTE), ['TIPO_FRENTE' => FrenteTrabajo::TIPO_VENDIDO])
            ->assertOk()->assertJson(['success' => true, 'tipo' => 'VENDIDO'])
            ->assertJsonPath('etiqueta', '<span class="etiqueta-venta etiqueta-venta--vendido">VENDIDO</span>');
        $this->assertSame('VENDIDO', $frente->fresh()->TIPO_FRENTE);
        $this->assertTrue(FrenteTrabajo::esFueraDeFlota($frente->ID_FRENTE), 'Sale de la flota al momento (caché invalidada).');

        $this->actingAs($admin)->patchJson(route('frentes.cambiarTipo', $frente->ID_FRENTE), ['TIPO_FRENTE' => 'OPERACION'])
            ->assertOk()->assertJsonPath('etiqueta', '');
        $this->assertFalse(FrenteTrabajo::esFueraDeFlota($frente->ID_FRENTE), 'Y vuelve a la flota.');

        $this->actingAs($admin)->patchJson(route('frentes.cambiarTipo', $frente->ID_FRENTE), ['TIPO_FRENTE' => 'NO_EXISTE'])
            ->assertStatus(422)->assertJsonValidationErrors('TIPO_FRENTE');
    }

    public function test_cambiar_el_tipo_es_solo_para_super_admin(): void
    {
        $frente = FrenteTrabajo::whereNotIn('TIPO_FRENTE', FrenteTrabajo::TIPOS_FUERA_DE_FLOTA)->firstOrFail();
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO' => 'PRUEBA SIN SUPER', 'CORREO_ELECTRONICO' => 'prueba.sinsuper.' . Str::random(6) . '@local.test',
            'PASSWORD_HASH' => bcrypt('x'), 'ID_ROL' => DB::table('roles')->value('ID_ROL'), 'NIVEL_ACCESO_EQUIPOS' => 1,
            'NIVEL_ACCESO_ALMACEN' => 1, 'ESTATUS' => 'ACTIVO', 'REQUIERE_CAMBIO_CLAVE' => 0, 'PERMISOS' => 'equipos.edit',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $usuario = \App\Models\Usuario::find($id);

        $this->actingAs($usuario)->patchJson(route('frentes.cambiarTipo', $frente->ID_FRENTE), ['TIPO_FRENTE' => 'VENDIDO'])->assertForbidden();
        $this->assertNotSame('VENDIDO', $frente->fresh()->TIPO_FRENTE);
        $this->assertStringNotContainsString('id="btnTipoFrente"',
            (string) $this->actingAs($usuario)->get('/admin/equipos?id_frente=' . $frente->ID_FRENTE)->assertOk()->getContent(),
            'Sin super.admin ni siquiera ve la opción.');
    }

    public function test_la_opcion_del_menu_se_ve_siempre_con_su_modal(): void
    {
        $admin = $this->superAdminGlobal();
        $frente = FrenteTrabajo::value('ID_FRENTE');

        foreach (['/admin/equipos', '/admin/equipos?id_frente=' . $frente] as $url) {
            $html = (string) $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/id="btnTipoFrente"[^>]*>/', $html, "Se ve en $url.");
            $this->assertDoesNotMatchRegularExpression('/id="btnTipoFrente"[^>]*\s+hidden\s*>/', $html);
            $this->assertStringContainsString('id="tipoFrenteSelect"', $html, 'El modal trae su selector de frente.');
            $this->assertStringContainsString('value="TRAMITE_VENTA"', $html);
        }
    }

    public function test_un_tipo_que_no_existe_no_se_acepta(): void
    {
        $this->actingAs($this->superAdminGlobal())->postJson('/admin/frentes?json=true', [
            'NOMBRE_FRENTE' => 'X ' . Str::random(6), 'UBICACION' => 'X', 'TIPO_FRENTE' => 'VENTA', 'ESTATUS_FRENTE' => 'ACTIVO',
        ])->assertStatus(422)->assertJsonValidationErrors('TIPO_FRENTE');
    }
}
