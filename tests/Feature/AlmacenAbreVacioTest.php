<?php

namespace Tests\Feature;

use App\Models\Almacen;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * /admin/almacen: al abrir el módulo no se carga ningún producto —ni en el HTML ni en la
 * respuesta AJAX sin filtros—, solo el aviso "Usa los filtros para ver el inventario"
 * (pedido del cliente, 16-09-2026). Con una búsqueda o un filtro sí llegan las filas.
 */
class AlmacenAbreVacioTest extends MySqlTestCase
{
    private function almacenConStock(): array
    {
        $fila = DB::table('almacen_stock as s')
            ->join('productos_inventario as p', 'p.ID_PRODUCTO', '=', 's.ID_PRODUCTO')
            ->join('almacenes as a', 'a.ID_ALMACEN', '=', 's.ID_ALMACEN')
            ->where('s.CANTIDAD', '>', 0)->whereNull('a.deleted_at')
            ->first(['s.ID_ALMACEN', 'p.CODIGO']);
        $this->assertNotNull($fila, 'Hace falta un almacén con stock.');

        return [Almacen::find($fila->ID_ALMACEN), $fila->CODIGO];
    }

    public function test_la_carga_html_abre_sin_productos(): void
    {
        [$almacen] = $this->almacenConStock();

        $r = $this->actingAs($this->superAdminGlobal())
            ->get('/admin/almacen?id_almacen=' . $almacen->ID_ALMACEN)
            ->assertOk();

        $this->assertCount(0, $r->viewData('productos'));
        $this->assertCount(0, $r->viewData('repartoInicial'));
        $r->assertSee('Usa los filtros para ver el inventario', false);
    }

    public function test_sin_filtros_la_respuesta_ajax_tampoco_trae_filas(): void
    {
        [$almacen] = $this->almacenConStock();

        $html = (string) $this->actingAs($this->superAdminGlobal())
            ->getJson('/admin/almacen?id_almacen=' . $almacen->ID_ALMACEN)
            ->assertOk()->json('html');

        $this->assertStringNotContainsString('tr class="alm-row', $html, 'Ninguna fila de producto.');
        $this->assertStringContainsString('Usa los filtros para ver el inventario', $html);
    }

    public function test_con_busqueda_si_llegan_las_filas(): void
    {
        [$almacen, $codigo] = $this->almacenConStock();

        $html = (string) $this->actingAs($this->superAdminGlobal())
            ->getJson('/admin/almacen?' . http_build_query(['id_almacen' => $almacen->ID_ALMACEN, 'search' => $codigo]))
            ->assertOk()->json('html');

        $this->assertStringContainsString('data-codigo="' . e($codigo) . '"', $html);
    }
}
