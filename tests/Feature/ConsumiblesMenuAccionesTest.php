<?php

namespace Tests\Feature;

use Tests\MySqlTestCase;

/**
 * Menú «Acciones» de Consumibles (partials/acciones_navegacion): las otras pantallas del módulo
 * y, solo en Cargar, «Cupos de gasoil» (abre su modal ahí mismo).
 */
class ConsumiblesMenuAccionesTest extends MySqlTestCase
{
    public function test_cupos_de_gasoil_solo_en_cargar(): void
    {
        $u = $this->superAdminGlobal();

        $this->actingAs($u)->get(route('consumibles.cargar'))->assertOk()
            ->assertSee('Cupos de gasoil</span>', false)->assertSee('data-abrir-cupos', false);

        foreach (['consumibles.index', 'consumibles.graficos'] as $ruta) {
            $this->actingAs($u)->get(route($ruta))->assertOk()
                ->assertDontSee('Cupos de gasoil</span>', false)
                ->assertSee('Cargar consumibles</span>', false);
        }
    }
}
