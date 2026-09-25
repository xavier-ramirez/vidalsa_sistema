<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\ProductoInventario;
use Tests\MySqlTestCase;

/**
 * Panel lateral de /admin/almacen (partial distribucion_stats, AlmacenController::panelLateral):
 *  - al tocar una fila (almacen.productos.otros) dice cuánto hay de ESE producto en CADA otro
 *    almacén visible, con 0 donde no hay;
 *  - sin producto (abrir el módulo, o filtrar sin llegar a un solo producto) reparte por
 *    categoría lo que la tabla está filtrando.
 */
class PanelOtrosAlmacenesTest extends MySqlTestCase
{
    public function test_al_tocar_una_fila_el_panel_dice_los_otros_almacenes_sin_repetir_el_producto(): void
    {
        $admin = $this->superAdminGlobal();
        // Un producto con saldo en DOS almacenes o más: desde el primero, el otro sale en la lista.
        // Si la base no trae ninguno, se le da saldo en otro almacén a uno que ya tiene (dentro
        // de la transacción del test). Fuera los de la papelera: un producto borrado conserva
        // su saldo para poder restaurarlo, pero ya no se consulta.
        $activos = ProductoInventario::query()->select('ID_PRODUCTO');
        $buscar = fn () => AlmacenStock::query()
            ->selectRaw('ID_PRODUCTO, MIN(ID_ALMACEN) as alm')
            ->where('CANTIDAD', '>', 0)
            ->whereIn('ID_PRODUCTO', $activos)
            ->groupBy('ID_PRODUCTO')->havingRaw('COUNT(DISTINCT ID_ALMACEN) > 1')
            ->first();
        if (!$fila = $buscar()) {
            $uno = AlmacenStock::where('CANTIDAD', '>', 0)->whereIn('ID_PRODUCTO', $activos)->first();
            $otro = $uno ? Almacen::query()->activos()->where('ID_ALMACEN', '!=', $uno->ID_ALMACEN)->value('ID_ALMACEN') : null;
            if ($otro) {
                \DB::table('almacen_stock')->insert(['ID_ALMACEN' => $otro, 'ID_PRODUCTO' => $uno->ID_PRODUCTO, 'ID_FRENTE' => 0, 'CANTIDAD' => 3]);
                $fila = $buscar();
            }
        }
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

    public function test_si_ningun_otro_almacen_tiene_existencias_el_panel_los_lista_con_cero(): void
    {
        $fila = AlmacenStock::query()
            ->selectRaw('ID_PRODUCTO, MIN(ID_ALMACEN) as alm')
            ->where('CANTIDAD', '>', 0)
            ->groupBy('ID_PRODUCTO')->havingRaw('COUNT(DISTINCT ID_ALMACEN) = 1')
            ->first();
        $this->assertNotNull($fila, 'Hace falta un producto con saldo en un solo almacén.');
        $otros = Almacen::query()->activos()->where('ID_ALMACEN', '!=', $fila->alm)->count();
        $this->assertGreaterThan(0, $otros, 'Hace falta otro almacén activo.');

        $html = $this->actingAs($this->superAdminGlobal())
            ->getJson(route('almacen.productos.otros', ['id' => $fila->ID_PRODUCTO, 'id_almacen' => $fila->alm]))
            ->assertOk()->json('html');

        $this->assertStringContainsString('En otros almacenes', $html);
        // Cada otro almacén visible sale como fila clicable, y la cifra es un 0 (no desaparece).
        $this->assertSame($otros, substr_count($html, 'almVerProductoEnAlmacen('), 'Un renglón por cada otro almacén.');
        $this->assertMatchesRegularExpression('/<span class="qty[^"]*">0<\/span>/', $html);
    }

    public function test_sin_producto_el_panel_reparte_por_categoria(): void
    {
        $alm = AlmacenStock::query()->where('CANTIDAD', '>', 0)->value('ID_ALMACEN');
        $this->assertNotNull($alm, 'Hace falta un almacén con saldo.');

        $html = $this->actingAs($this->superAdminGlobal())
            ->getJson(route('almacen.index', ['id_almacen' => $alm, 'ver_todo' => 1]))
            ->assertOk()->json('distribucionHtml');

        $this->assertStringContainsString('Distribución de Inventario', $html);
        $this->assertStringContainsString('alm-cat-row', $html);
        $this->assertStringNotContainsString('En otros almacenes', $html);
    }

    public function test_al_abrir_el_modulo_el_panel_trae_la_distribucion_del_almacen(): void
    {
        $alm = AlmacenStock::query()->where('CANTIDAD', '>', 0)->value('ID_ALMACEN');
        $this->assertNotNull($alm, 'Hace falta un almacén con saldo.');

        $this->actingAs($this->superAdminGlobal())
            ->get(route('almacen.index', ['id_almacen' => $alm]))
            ->assertOk()
            ->assertSee('Distribución de Inventario');
    }

    public function test_un_producto_que_no_existe_responde_404(): void
    {
        $this->actingAs($this->superAdminGlobal())
            ->getJson(route('almacen.productos.otros', ['id' => 999999999, 'id_almacen' => 1]))
            ->assertNotFound();
    }
}
