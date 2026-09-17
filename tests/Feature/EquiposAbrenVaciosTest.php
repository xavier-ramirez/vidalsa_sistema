<?php

namespace Tests\Feature;

use App\Models\CatalogoColor;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * /admin/equipos: al abrir el módulo sin filtros no sale ningún equipo, solo el aviso "Seleccione
 * un filtro para ver los equipos" (pedido del cliente, 15-09-2026); con un filtro sí salen. El
 * color de la unidad se escribe sin la muestra redonda.
 */
class EquiposAbrenVaciosTest extends MySqlTestCase
{
    public function test_al_abrir_sin_filtros_no_sale_ningun_equipo(): void
    {
        $r = $this->actingAs($this->superAdminGlobal())->get(route('equipos.index'))->assertOk();

        $this->assertCount(0, $r->viewData('equipos'));
        $r->assertSee('SELECCIONE UN FILTRO PARA VER LOS EQUIPOS');
    }

    public function test_con_un_filtro_si_salen_equipos(): void
    {
        $tipo = (int) DB::table('equipos')->whereNull('deleted_at')->whereNotNull('id_tipo_equipo')->value('id_tipo_equipo');

        $r = $this->actingAs($this->superAdminGlobal())->get(route('equipos.index', ['id_tipo' => $tipo]))->assertOk();

        $this->assertGreaterThan(0, count($r->viewData('equipos')));
    }

    public function test_el_color_sale_sin_la_muestra_redonda(): void
    {
        $e = DB::table('equipos')->whereNull('deleted_at')
            ->whereNotNull('COLOR')->where('COLOR', '!=', '')
            ->whereNotNull('SERIAL_CHASIS')->where('SERIAL_CHASIS', '!=', '')
            ->first(['SERIAL_CHASIS', 'COLOR']);
        if (!$e) {
            $this->markTestSkipped('No hay equipos con color y serial.');
        }

        $r = $this->actingAs($this->superAdminGlobal())->get(route('equipos.index', ['search_query' => $e->SERIAL_CHASIS]))->assertOk();

        $r->assertSee(CatalogoColor::normalizar($e->COLOR));
        $r->assertDontSee('eq-color-muestra');
    }
}
