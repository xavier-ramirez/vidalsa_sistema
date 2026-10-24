<?php

namespace Tests\Feature;

use App\Models\Almacen;
use Illuminate\Support\Facades\DB;
use Tests\AlmacenLocal;
use Tests\MySqlTestCase;

/**
 * /admin/almacen: al abrir el módulo no se pinta ningún producto, solo el aviso "Usa los
 * filtros para ver el inventario" (pedido del cliente, 16-09-2026). Con una búsqueda o un
 * filtro sí salen las filas. La página llega sin filas: las pinta la copia local.
 */
class AlmacenAbreVacioTest extends MySqlTestCase
{
    use AlmacenLocal;

    private function almacenConStock(): array
    {
        $fila = DB::table('almacen_stock as s')
            ->join('productos_inventario as p', 'p.ID_PRODUCTO', '=', 's.ID_PRODUCTO')
            ->join('almacenes as a', 'a.ID_ALMACEN', '=', 's.ID_ALMACEN')
            ->where('s.CANTIDAD', '>', 0)->whereNull('a.deleted_at')->whereNull('p.deleted_at')
            ->first(['s.ID_ALMACEN', 'p.CODIGO']);
        $this->assertNotNull($fila, 'Hace falta un almacén con stock.');

        return [Almacen::find($fila->ID_ALMACEN), $fila->CODIGO];
    }

    public function test_la_pagina_llega_sin_filas_del_servidor(): void
    {
        [$almacen] = $this->almacenConStock();

        $html = $this->actingAs($this->superAdminGlobal())
            ->get('/admin/almacen?id_almacen=' . $almacen->ID_ALMACEN)
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<tbody id="almTableBody"><\/tbody>/', $html);
        $this->assertStringNotContainsString('tr class="alm-row', $html);
    }

    public function test_sin_filtros_no_se_pinta_ninguna_fila(): void
    {
        [$almacen] = $this->almacenConStock();

        $html = $this->inventarioLocal($this->superAdminGlobal(), ['id_almacen' => $almacen->ID_ALMACEN])['html'];

        $this->assertStringNotContainsString('tr class="alm-row', $html, 'Ninguna fila de producto.');
        $this->assertStringContainsString('Usa los filtros para ver el inventario', $html);
    }

    public function test_con_busqueda_si_salen_las_filas(): void
    {
        [$almacen, $codigo] = $this->almacenConStock();

        $html = $this->inventarioLocal($this->superAdminGlobal(), ['id_almacen' => $almacen->ID_ALMACEN, 'search' => $codigo])['html'];

        $this->assertStringContainsString('data-codigo="' . e($codigo) . '"', $html);
    }
}
