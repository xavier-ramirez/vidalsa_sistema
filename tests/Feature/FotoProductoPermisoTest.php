<?php

namespace Tests\Feature;

use App\Models\ProductoInventario;
use App\Models\Usuario;
use Illuminate\Http\UploadedFile;
use Tests\MySqlTestCase;

/**
 * La foto del producto la gobierna el permiso almacen.productos.
 *
 * El @can de la vista solo ESCONDE los botones; si la ruta no estuviera protegida,
 * cualquiera con acceso al módulo podría cambiar o borrar la foto llamándola a mano.
 * Es una clave EXCLUSIVA (Usuario::PERMISOS_EXPLICITOS): ni super.admin la hereda.
 */
class FotoProductoPermisoTest extends MySqlTestCase
{
    private function producto(): ProductoInventario
    {
        return ProductoInventario::create([
            'CODIGO' => 'FOTO' . strtoupper(uniqid()), 'NOMBRE' => 'PRODUCTO PRUEBA FOTO', 'UM' => 'UND',
        ]);
    }

    /** super.admin SIN la clave literal almacen.productos. */
    private function sinPermiso(): Usuario
    {
        $u = Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->whereNotNull('PERMISOS')->get()
            ->first(function ($usr) {
                $p = array_map('strtolower', $usr->PERMISOS);
                return in_array('super.admin', $p, true) && !in_array('almacen.productos', $p, true);
            });
        $this->assertNotNull($u, 'hace falta un super.admin SIN almacen.productos');

        return $u;
    }

    public function test_sin_el_permiso_no_se_puede_subir_la_foto(): void
    {
        $producto = $this->producto();

        $this->actingAs($this->sinPermiso())
            ->post("/admin/almacen/productos/{$producto->ID_PRODUCTO}/foto",
                   ['foto' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertNull($producto->fresh()->FOTO);
    }

    public function test_sin_el_permiso_no_se_puede_quitar_la_foto(): void
    {
        $producto = $this->producto();
        $producto->update(['FOTO' => '/storage/google/la-que-estaba']);

        $this->actingAs($this->sinPermiso())
            ->delete("/admin/almacen/productos/{$producto->ID_PRODUCTO}/foto", [], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertSame('/storage/google/la-que-estaba', $producto->fresh()->FOTO);
    }

    public function test_sin_sesion_tampoco(): void
    {
        $producto = $this->producto();

        $this->post("/admin/almacen/productos/{$producto->ID_PRODUCTO}/foto", [], ['Accept' => 'application/json'])
            ->assertStatus(401);
    }
}
