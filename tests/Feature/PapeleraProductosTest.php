<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Services\InventarioService;
use Tests\MySqlTestCase;

/**
 * «Eliminar permanentemente» en la Papelera de productos: sin historial se borra de verdad;
 * con historial (kardex, Notas de Entrega) sale de la papelera para siempre y el historial
 * queda intacto.
 */
class PapeleraProductosTest extends MySqlTestCase
{
    private function productoEnPapelera(bool $conMovimiento): ProductoInventario
    {
        $p = ProductoInventario::create([
            'CODIGO' => 'PAP' . uniqid(), 'NOMBRE' => 'PRUEBA PAPELERA ' . uniqid(), 'UM' => 'UND',
            'CATEGORIA' => 'PRUEBA', 'ESTATUS' => 'ACTIVO',
        ]);
        if ($conMovimiento) {
            $almacen = (int) Almacen::where('TIPO', Almacen::TIPO_GENERAL)->value('ID_ALMACEN');
            app(InventarioService::class)->registrarEntrada($almacen, $p->ID_PRODUCTO, 5, []);
        }
        $p->delete();

        return $p;
    }

    private function enPapelera(ProductoInventario $p): bool
    {
        return collect($this->actingAs($this->superAdminGlobal())->getJson(route('almacen.productos.papelera'))->json('productos'))
            ->contains('ID_PRODUCTO', $p->ID_PRODUCTO);
    }

    public function test_con_historial_sale_de_la_papelera_y_el_historial_se_queda(): void
    {
        $p = $this->productoEnPapelera(true);
        $this->assertTrue($this->enPapelera($p));

        $this->actingAs($this->superAdminGlobal())->deleteJson(route('almacen.productos.eliminarPermanente', $p->ID_PRODUCTO))
            ->assertOk()->assertJsonPath('message', 'Producto eliminado. Su historial (kardex y Notas de Entrega) se conserva.');

        $this->assertFalse($this->enPapelera($p), 'Ya no aparece en la papelera.');
        $this->assertSame(1, MovimientoInventario::where('ID_PRODUCTO', $p->ID_PRODUCTO)->count(), 'El kardex no se toca.');
        $this->assertNotNull(ProductoInventario::onlyTrashed()->find($p->ID_PRODUCTO), 'Sigue borrado, no vuelve al catálogo.');
        $this->assertNull(ProductoInventario::find($p->ID_PRODUCTO));
        $this->actingAs($this->superAdminGlobal())->postJson(route('almacen.productos.restaurar', $p->ID_PRODUCTO))->assertNotFound();
        $this->actingAs($this->superAdminGlobal())->deleteJson(route('almacen.productos.eliminarPermanente', $p->ID_PRODUCTO))->assertNotFound();
    }

    public function test_sin_historial_se_borra_de_verdad(): void
    {
        $p = $this->productoEnPapelera(false);

        $this->actingAs($this->superAdminGlobal())->deleteJson(route('almacen.productos.eliminarPermanente', $p->ID_PRODUCTO))
            ->assertOk()->assertJsonPath('message', 'Producto eliminado permanentemente.');

        $this->assertNull(ProductoInventario::withTrashed()->find($p->ID_PRODUCTO));
    }

    public function test_restaurar_sigue_funcionando_en_la_papelera(): void
    {
        $p = $this->productoEnPapelera(true);

        $this->actingAs($this->superAdminGlobal())->postJson(route('almacen.productos.restaurar', $p->ID_PRODUCTO))->assertOk();
        $this->assertNotNull(ProductoInventario::find($p->ID_PRODUCTO));
    }
}
