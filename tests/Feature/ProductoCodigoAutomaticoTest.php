<?php

namespace Tests\Feature;

use App\Models\ProductoInventario;
use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * El código de un producto lo pone SIEMPRE el sistema (AlmacenController::
 * generarCodigoProducto): al crear no se acepta uno escrito por la persona, y al editar no
 * se cambia. El modal "Nuevo producto" ya no tiene el campo; esto fija que el servidor
 * tampoco lo acepte aunque alguien lo mande.
 */
class ProductoCodigoAutomaticoTest extends MySqlTestCase
{
    private function usuario(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.productos'));
        $this->assertNotNull($u, 'No hay ningún usuario con almacen.productos para probar.');

        return $u;
    }

    public function test_al_crear_el_codigo_lo_pone_el_sistema_aunque_se_mande_uno(): void
    {
        $ocupado = (string) ProductoInventario::withTrashed()->whereNotNull('CODIGO')->value('CODIGO');

        $r = $this->actingAs($this->usuario())->postJson(route('almacen.productos.store'), [
            'CODIGO' => '999999999', 'NOMBRE' => 'PRODUCTO DE PRUEBA CODIGO AUTOMATICO', 'UM' => 'UND',
        ])->assertCreated();

        $codigo = $r->json('producto.CODIGO');
        $this->assertMatchesRegularExpression('/^\d{6,}$/', $codigo, 'El código tiene que ser el numérico del sistema.');
        $this->assertNotSame('999999999', $codigo, 'Se aceptó el código que mandó la persona.');
        $this->assertNotSame($ocupado, $codigo, 'Repitió un código que ya existe.');
    }

    public function test_al_editar_el_codigo_no_cambia(): void
    {
        $u = $this->usuario();
        $id = $this->actingAs($u)->postJson(route('almacen.productos.store'), [
            'NOMBRE' => 'PRODUCTO DE PRUEBA CODIGO FIJO', 'UM' => 'UND',
        ])->assertCreated()->json('producto.ID_PRODUCTO');
        $antes = ProductoInventario::find($id)->CODIGO;

        $this->actingAs($u)->patchJson(route('almacen.productos.update', $id), [
            'CODIGO' => '123', 'NOMBRE' => 'PRODUCTO DE PRUEBA CODIGO FIJO (EDITADO)', 'UM' => 'UND',
        ])->assertOk();

        $p = ProductoInventario::find($id);
        $this->assertSame($antes, $p->CODIGO, 'Editar cambió el código del producto.');
        $this->assertSame('PRODUCTO DE PRUEBA CODIGO FIJO (EDITADO)', $p->NOMBRE);
    }
}
