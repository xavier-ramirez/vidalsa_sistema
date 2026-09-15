<?php

namespace Tests\Feature;

use App\Models\AlmacenStock;
use App\Models\ProductoInventario;
use Tests\MySqlTestCase;

/**
 * Panel lateral "En otros almacenes" de /admin/almacen: lo pide la tabla al tocar una fila
 * (almacen.productos.otros) y responde dónde más hay de ESE producto. Ya no existe la lista
 * de "Distribución de Inventario" por categoría.
 */
class PanelOtrosAlmacenesTest extends MySqlTestCase
{
    public function test_al_tocar_una_fila_el_panel_dice_los_otros_almacenes_sin_repetir_el_producto(): void
    {
        $admin = $this->superAdminGlobal();
        // Un producto con saldo en DOS almacenes o más: desde el primero, el otro sale en la lista.
        $fila = AlmacenStock::query()
            ->selectRaw('ID_PRODUCTO, MIN(ID_ALMACEN) as alm')
            ->where('CANTIDAD', '>', 0)
            ->groupBy('ID_PRODUCTO')->havingRaw('COUNT(DISTINCT ID_ALMACEN) > 1')
            ->first();
        $this->assertNotNull($fila, 'Hace falta un producto con saldo en dos almacenes.');
        $producto = ProductoInventario::find($fila->ID_PRODUCTO);

        $html = $this->actingAs($admin)
            ->getJson(route('almacen.productos.otros', ['id' => $producto->ID_PRODUCTO, 'id_almacen' => $fila->alm]))
            ->assertOk()->json('html');

        $this->assertStringContainsString('alm-otros-almacenes', $html);
        $this->assertStringContainsString('En otros almacenes', $html);
        $this->assertStringNotContainsString('alm-otros-prod', $html, 'La fila marcada ya dice qué producto es.');
        $this->assertStringContainsString('class="guia"', $html, 'Cada nombre va unido a su cantidad.');
        $this->assertStringNotContainsString('Distribución de Inventario', $html);
    }

    public function test_si_ningun_otro_almacen_tiene_existencias_el_panel_no_sale(): void
    {
        $fila = AlmacenStock::query()
            ->selectRaw('ID_PRODUCTO, MIN(ID_ALMACEN) as alm')
            ->where('CANTIDAD', '>', 0)
            ->groupBy('ID_PRODUCTO')->havingRaw('COUNT(DISTINCT ID_ALMACEN) = 1')
            ->first();
        $this->assertNotNull($fila, 'Hace falta un producto con saldo en un solo almacén.');

        $this->actingAs($this->superAdminGlobal())
            ->getJson(route('almacen.productos.otros', ['id' => $fila->ID_PRODUCTO, 'id_almacen' => $fila->alm]))
            ->assertOk()
            ->assertExactJson(['html' => '']);
    }

    public function test_un_producto_que_no_existe_responde_404(): void
    {
        $this->actingAs($this->superAdminGlobal())
            ->getJson(route('almacen.productos.otros', ['id' => 999999999, 'id_almacen' => 1]))
            ->assertNotFound();
    }
}
