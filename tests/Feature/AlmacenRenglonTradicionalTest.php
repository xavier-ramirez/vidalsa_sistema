<?php

namespace Tests\Feature;

use App\Models\Almacen;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Tabla de /admin/almacen en un almacén que separa por proyecto (p. ej. PATIO EL TIGRE): cada
 * producto es el renglón de siempre —número y unidad—, sin el botón "N proyectos" que desplegaba
 * el reparto debajo ni el rótulo del proyecto dueño (decisión del cliente, 15-09-2026). El
 * reparto se consulta en el detalle del producto y la salida descuenta en automático: proyecto
 * destino, saldo común y después el resto.
 */
class AlmacenRenglonTradicionalTest extends MySqlTestCase
{
    public function test_un_producto_repartido_en_varios_proyectos_sale_como_renglon_normal(): void
    {
        [$almacen, $codigo] = $this->productoConBolsas('> 1');
        $html = $this->tabla($almacen, $codigo);

        $this->assertStringContainsString('data-codigo="' . e($codigo) . '"', $html);
        $this->assertStringNotContainsString('alm-bolsa-tog', $html, 'Sin el botón "N proyectos".');
        $this->assertStringNotContainsString('alm-row-bolsas', $html, 'Sin la fila que se desplegaba debajo.');
        $this->assertStringNotContainsString('data-bolsa-sel', $html);
    }

    public function test_un_producto_de_un_solo_proyecto_no_lleva_el_rotulo_del_dueno(): void
    {
        [$almacen, $codigo] = $this->productoConBolsas('= 1');

        $this->assertStringNotContainsString('alm-bolsa-uno', $this->tabla($almacen, $codigo));
    }

    /** [almacén que separa por proyecto, código de un producto con saldo en N proyectos]. */
    private function productoConBolsas(string $cuantas): array
    {
        foreach (Almacen::with('frentes:ID_FRENTE')->get() as $almacen) {
            if (!$almacen->separaPorProyecto()) {
                continue;
            }
            $codigo = DB::table('almacen_stock as s')
                ->join('productos_inventario as p', 'p.ID_PRODUCTO', '=', 's.ID_PRODUCTO')
                ->where('s.ID_ALMACEN', $almacen->ID_ALMACEN)
                ->where('s.CANTIDAD', '>', 0)
                ->groupBy('s.ID_PRODUCTO', 'p.CODIGO')
                ->havingRaw('COUNT(*) ' . $cuantas)
                ->value('p.CODIGO');
            if ($codigo) {
                return [$almacen, $codigo];
            }
        }
        $this->markTestSkipped("No hay productos con saldo en {$cuantas} proyectos en un almacén que separa.");
    }

    private function tabla(Almacen $almacen, string $codigo): string
    {
        return (string) $this->actingAs($this->superAdminGlobal())
            ->getJson('/admin/almacen?' . http_build_query(['id_almacen' => $almacen->ID_ALMACEN, 'search' => $codigo]))
            ->assertOk()
            ->json('html');
    }
}
