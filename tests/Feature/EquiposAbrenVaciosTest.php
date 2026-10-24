<?php

namespace Tests\Feature;

use App\Models\CatalogoColor;
use Illuminate\Support\Facades\DB;
use Tests\EquiposLocal;
use Tests\MySqlTestCase;

/**
 * /admin/equipos: al abrir el módulo sin filtros no sale ningún equipo, solo el aviso "Seleccione
 * un filtro para ver los equipos" (pedido del cliente, 15-09-2026); con un filtro sí salen. El
 * color de la unidad se escribe sin la muestra redonda.
 */
class EquiposAbrenVaciosTest extends MySqlTestCase
{
    use EquiposLocal;

    public function test_al_abrir_sin_filtros_no_sale_ningun_equipo(): void
    {
        $r = $this->consultaLocal($this->superAdminGlobal());

        $this->assertSame([], $r['equiposData']);
        $this->assertStringContainsString('SELECCIONE UN FILTRO PARA VER LOS EQUIPOS', $r['html']);
    }

    public function test_con_un_filtro_si_salen_equipos(): void
    {
        $tipo = (int) DB::table('equipos')->whereNull('deleted_at')->whereNotNull('id_tipo_equipo')->value('id_tipo_equipo');

        $r = $this->consultaLocal($this->superAdminGlobal(), ['id_tipo' => (string) $tipo]);

        $this->assertNotEmpty($r['equiposData']);
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

        $html = $this->consultaLocal($this->superAdminGlobal(), ['search_query' => $e->SERIAL_CHASIS])['html'];

        $this->assertStringContainsString(e(CatalogoColor::normalizar($e->COLOR)), $html);
        $this->assertStringNotContainsString('eq-color-muestra', $html);
    }
}
