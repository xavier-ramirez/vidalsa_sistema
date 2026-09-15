<?php

namespace Tests\Feature;

use App\Models\Almacen;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Tabla de /admin/almacen en un almacén que separa por proyecto (p. ej. PATIO EL TIGRE): cada
 * producto es el renglón de siempre —número y unidad—, sin botón "N proyectos" ni fila que se
 * despliegue debajo (decisión del cliente, 15-09-2026). Si el saldo está repartido en dos
 * proyectos o más, la fila lleva ese reparto en data-bolsas y al seleccionarla el modal
 * «¿De qué proyecto sale?» pregunta de cuál se descuenta.
 */
class AlmacenRenglonTradicionalTest extends MySqlTestCase
{
    public function test_un_producto_repartido_en_varios_proyectos_trae_el_reparto_para_el_modal(): void
    {
        [$almacen, $codigo, $idProducto] = $this->productoConBolsas('> 1');
        $html = $this->tabla($almacen, $codigo);

        $this->assertStringNotContainsString('alm-bolsa-tog', $html, 'Sin el botón "N proyectos".');
        $this->assertStringNotContainsString('alm-row-bolsas', $html, 'Sin la fila que se desplegaba debajo.');

        $this->assertMatchesRegularExpression('/data-codigo="' . preg_quote(e($codigo), '/') . '"[^>]*data-bolsas="([^"]*)"/', $html);
        preg_match('/data-codigo="' . preg_quote(e($codigo), '/') . '"[^>]*data-bolsas="([^"]*)"/', $html, $m);
        $bolsas = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

        $esperadas = DB::table('almacen_stock')->where('ID_ALMACEN', $almacen->ID_ALMACEN)
            ->where('ID_PRODUCTO', $idProducto)->where('CANTIDAD', '>', 0)->count();
        $this->assertCount($esperadas, $bolsas, 'Un proyecto por cada saldo del producto en el almacén.');
        foreach ($bolsas as $b) {
            $this->assertArrayHasKey('f', $b);
            $this->assertNotSame('', $b['n'], 'Cada proyecto con su nombre.');
            $this->assertGreaterThan(0, $b['q'], 'Y su cantidad.');
        }
    }

    public function test_un_producto_de_un_solo_proyecto_no_pregunta_de_cual_sale(): void
    {
        [$almacen, $codigo] = $this->productoConBolsas('= 1');
        $html = $this->tabla($almacen, $codigo);

        $this->assertMatchesRegularExpression('/<tr[^>]*data-codigo="' . preg_quote(e($codigo), '/') . '"[^>]*>/', $html);
        preg_match('/<tr[^>]*data-codigo="' . preg_quote(e($codigo), '/') . '"[^>]*>/', $html, $m);
        $this->assertStringNotContainsString('data-bolsas', $m[0]);
        $this->assertStringNotContainsString('alm-bolsa-uno', $html, 'Sin el rótulo del proyecto dueño.');
    }

    /** [almacén que separa por proyecto, código, id] de un producto con saldo en N proyectos. */
    private function productoConBolsas(string $cuantas): array
    {
        foreach (Almacen::with('frentes:ID_FRENTE')->get() as $almacen) {
            if (!$almacen->separaPorProyecto()) {
                continue;
            }
            $fila = DB::table('almacen_stock as s')
                ->join('productos_inventario as p', 'p.ID_PRODUCTO', '=', 's.ID_PRODUCTO')
                ->where('s.ID_ALMACEN', $almacen->ID_ALMACEN)
                ->where('s.CANTIDAD', '>', 0)
                ->groupBy('s.ID_PRODUCTO', 'p.CODIGO')
                ->havingRaw('COUNT(*) ' . $cuantas)
                ->select('s.ID_PRODUCTO', 'p.CODIGO')
                ->first();
            if ($fila) {
                return [$almacen, $fila->CODIGO, (int) $fila->ID_PRODUCTO];
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
