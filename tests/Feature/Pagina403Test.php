<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * «Acceso denegado» con sesión abierta: lleva al menú (no al login) y se recarga sola si le dan
 * el permiso con la página abierta (pregunta su huella de acceso a /version-vistas).
 */
class Pagina403Test extends MySqlTestCase
{
    public function test_con_sesion_lleva_al_menu_y_vigila_su_acceso(): void
    {
        $yo = Usuario::where('ESTATUS', 'ACTIVO')->get()->first(fn ($u) => !$u->can('equipos.create'));
        $this->assertNotNull($yo, 'Hace falta un usuario activo sin equipos.create.');

        $this->actingAs($yo)->get(route('equipos.create'))->assertForbidden()
            ->assertSee('No tienes permiso')
            ->assertSee('Volver al menú')
            ->assertSee('href="' . route('menu') . '"', false)
            ->assertSee(json_encode($yo->huellaAcceso()), false)
            ->assertDontSee('tenés');
    }

    public function test_sin_sesion_no_lleva_el_vigia(): void
    {
        $html = view('errors.403')->render();
        $this->assertStringContainsString('Ir al inicio de sesión', $html);
        $this->assertStringNotContainsString('version-vistas', $html);
    }
}
