<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * Crear un producto con "Cantidad inicial" registra una ENTRADA con REFERENCIA
 * MovimientoInventario::REF_STOCK_INICIAL y SIN motivo: en una ENTRADA el kardex pinta el
 * motivo como proveedor, y un stock inicial no tiene. Los kardex la reconocen con
 * esStockInicial() y la muestran en dos líneas ("STOCK INICIAL" + "Nuevo material").
 */
class StockInicialTest extends MySqlTestCase
{
    private function usuario(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.productos') && $u->can('almacen.movimiento'));
        $this->assertNotNull($u, 'No hay ningún usuario con almacen.productos y almacen.movimiento para probar.');

        return $u;
    }

    private function crear(Usuario $u, int $idAlmacen, float $cantidad): int
    {
        return $this->actingAs($u)->postJson(route('almacen.productos.store'), [
            'NOMBRE' => 'PRODUCTO DE PRUEBA STOCK INICIAL', 'UM' => 'UND',
            'id_almacen' => $idAlmacen, 'cantidad_inicial' => $cantidad,
        ])->assertCreated()->json('producto.ID_PRODUCTO');
    }

    public function test_con_cantidad_inicial_registra_la_entrada_corta_y_sin_proveedor(): void
    {
        $u = $this->usuario();
        $idAlmacen = (int) Almacen::visiblesPara($u)->value('ID_ALMACEN');
        $this->assertGreaterThan(0, $idAlmacen, 'El usuario de prueba no ve ningún almacén.');

        $movs = MovimientoInventario::where('ID_PRODUCTO', $this->crear($u, $idAlmacen, 5))->get();

        $this->assertCount(1, $movs, 'Tenía que registrarse una sola entrada.');
        $m = $movs->first();
        $this->assertSame(MovimientoInventario::TIPO_ENTRADA, $m->TIPO);
        $this->assertSame(MovimientoInventario::REF_STOCK_INICIAL, $m->REFERENCIA);
        $this->assertNull($m->MOTIVO, 'El stock inicial no lleva motivo: el kardex lo mostraría como proveedor.');
        $this->assertTrue($m->esStockInicial());
    }

    public function test_sin_cantidad_inicial_no_registra_movimiento(): void
    {
        $u = $this->usuario();
        $idAlmacen = (int) Almacen::visiblesPara($u)->value('ID_ALMACEN');

        $id = $this->crear($u, $idAlmacen, 0);

        $this->assertSame(0, MovimientoInventario::where('ID_PRODUCTO', $id)->count());
    }
}
