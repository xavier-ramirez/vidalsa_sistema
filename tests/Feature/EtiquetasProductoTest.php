<?php

namespace Tests\Feature;

use App\Models\ProductoInventario;
use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * Etiquetas de productos con código de barras (/admin/almacen/etiquetas,
 * AlmacenController::etiquetas): el servidor entrega la LISTA y la pantalla las dibuja
 * (almacen_index.js), enseña la vista previa e imprime. Aquí se fija la lista: qué
 * productos, con qué datos y cuántas copias, y los topes; el dibujo se revisa en el navegador.
 */
class EtiquetasProductoTest extends MySqlTestCase
{
    private function usuario(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        $this->assertNotNull($u, 'Hace falta un usuario con super.admin.');
        return $u;
    }

    private function conCodigo(int $cuantos = 1)
    {
        $ps = ProductoInventario::activos()->whereNotNull('CODIGO')->where('CODIGO', '!=', '')->orderBy('NOMBRE')->limit($cuantos)->get();
        $this->assertCount($cuantos, $ps, 'Hacen falta productos activos con código.');
        return $ps;
    }

    public function test_cada_producto_con_sus_copias_y_lo_que_lleva_la_etiqueta(): void
    {
        [$a, $b] = $this->conCodigo(2)->all();
        $j = $this->actingAs($this->usuario())
            ->getJson(route('almacen.etiquetas', ['items' => $a->ID_PRODUCTO . ':2,' . $b->ID_PRODUCTO . ':3']))
            ->assertOk()->json();

        $this->assertSame(5, $j['total']);
        $porCodigo = collect($j['etiquetas'])->keyBy('codigo');
        $this->assertSame(2, $porCodigo[$a->CODIGO]['copias']);
        $this->assertSame(3, $porCodigo[$b->CODIGO]['copias']);
        $this->assertSame(trim($a->NOMBRE), $porCodigo[$a->CODIGO]['nombre']);
        $this->assertSame(mb_strtoupper(trim((string) $a->UM)), $porCodigo[$a->CODIGO]['um'], 'La medida va en mayúsculas.');
        $this->assertArrayHasKey('ubicacion', $porCodigo[$a->CODIGO]);
    }

    public function test_misma_cantidad_para_los_elegidos_y_topes(): void
    {
        [$a, $b] = $this->conCodigo(2)->all();
        $j = $this->actingAs($this->usuario())
            ->getJson(route('almacen.etiquetas', ['ids' => $a->ID_PRODUCTO . ',' . $b->ID_PRODUCTO, 'copias' => 4]))
            ->assertOk()->json();
        $this->assertSame(8, $j['total']);
        $this->assertSame([4, 4], array_column($j['etiquetas'], 'copias'));

        // Más de 200 por producto se recorta a 200.
        $j = $this->actingAs($this->usuario())
            ->getJson(route('almacen.etiquetas', ['items' => $a->ID_PRODUCTO . ':999']))->assertOk()->json();
        $this->assertSame(200, $j['total']);
    }

    public function test_sin_productos_validos_no_hay_etiquetas(): void
    {
        $this->actingAs($this->usuario())
            ->getJson(route('almacen.etiquetas', ['items' => 'abc']))->assertNotFound();
        $this->actingAs($this->usuario())
            ->getJson(route('almacen.etiquetas', ['ids' => '999999999']))->assertNotFound();
    }
}
