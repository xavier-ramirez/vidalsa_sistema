<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\ProductoEquivalencia;
use App\Models\ProductoInventario;
use App\Models\Usuario;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Números de parte de un producto (lista de "Editar producto", CompatibilidadProductoService::
 * sincronizarEquivalencias) y el reparto por proyecto que pide "Detalles del producto"
 * (AlmacenController::productoReparto). Todo corre en la transacción de la prueba y se
 * revierte al terminar.
 */
class EquivalenciasYRepartoProductoTest extends MySqlTestCase
{
    private function editor(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.productos'));
        $this->assertNotNull($u, 'No hay un usuario con almacen.productos para probar.');
        return $u;
    }

    private function producto(?string $categoria = 'REPUESTOS'): ProductoInventario
    {
        return ProductoInventario::create([
            'CODIGO'    => 'C' . strtoupper(Str::random(7)),
            'NOMBRE'    => 'PRODUCTO EQUIV ' . strtoupper(Str::random(6)),
            'UM'        => 'UND',
            'CATEGORIA' => $categoria,
        ]);
    }

    private function editar(ProductoInventario $p, array $extra, ?Usuario $u = null)
    {
        return $this->actingAs($u ?? $this->editor())->patchJson(route('almacen.productos.update', ['id' => $p->ID_PRODUCTO]),
            ['NOMBRE' => $p->NOMBRE, 'UM' => $p->UM, 'CATEGORIA' => $p->CATEGORIA] + $extra);
    }

    public function test_editar_producto_sincroniza_los_numeros_de_parte(): void
    {
        $p = $this->producto();
        $lista = fn () => ProductoEquivalencia::where('ID_PRODUCTO', $p->ID_PRODUCTO)->orderBy('ID_EQUIVALENCIA')->pluck('NUMERO_PARTE')->all();
        $principal = fn () => ProductoEquivalencia::where('ID_PRODUCTO', $p->ID_PRODUCTO)->where('ES_PRINCIPAL', true)->pluck('NUMERO_PARTE')->all();

        // El mismo número dos veces (otra mayúscula) queda en uno; el primero es el principal.
        $this->editar($p, ['equivalencias' => ['B00714', 'b00714', '9F569-31A']])->assertOk();
        $this->assertSame(['B00714', '9F569-31A'], $lista());
        $this->assertSame(['B00714'], $principal());

        // Escrito distinto: se corrige la escritura y conserva el principal.
        $this->editar($p, ['equivalencias' => ['b 00714', '9F569-31A']])->assertOk();
        $this->assertSame(['b 00714', '9F569-31A'], $lista());
        $this->assertSame(['b 00714'], $principal());

        // Quitar el principal asciende al que queda.
        $this->editar($p, ['equivalencias' => ['9F569-31A']])->assertOk();
        $this->assertSame(['9F569-31A'], $lista());
        $this->assertSame(['9F569-31A'], $principal());
    }

    public function test_guardar_la_ubicacion_no_borra_las_equivalencias(): void
    {
        // Lo que manda "Detalles del producto" al guardar la ubicación: sin lista de
        // equivalencias. Antes, en un producto que no es de categoría FILTRO, las borraba.
        $p = $this->producto('LUBRICANTES');
        ProductoEquivalencia::create(['ID_PRODUCTO' => $p->ID_PRODUCTO, 'NUMERO_PARTE' => '1447', 'ES_PRINCIPAL' => true]);

        $this->editar($p, ['UBICACION' => 'ESTANTE 3'])->assertOk();

        $this->assertSame(['1447'], ProductoEquivalencia::where('ID_PRODUCTO', $p->ID_PRODUCTO)->pluck('NUMERO_PARTE')->all());
    }

    public function test_sin_permiso_de_productos_no_se_cambian(): void
    {
        $p = $this->producto();
        $sinPermiso = Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->get()->first(fn ($u) => ! $u->can('almacen.productos'));
        $this->assertNotNull($sinPermiso, 'Hace falta un usuario sin almacen.productos.');

        $this->editar($p, ['equivalencias' => ['X1']], $sinPermiso)->assertForbidden();
        $this->assertSame(0, ProductoEquivalencia::where('ID_PRODUCTO', $p->ID_PRODUCTO)->count());
    }

    public function test_el_reparto_solo_trae_proyectos_y_va_vacio_si_el_almacen_no_separa(): void
    {
        $p = $this->producto();
        $admin = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        $this->assertNotNull($admin, 'Hace falta un super.admin.');
        $general = Almacen::where('TIPO', Almacen::TIPO_GENERAL)->first();
        $this->assertNotNull($general, 'Hace falta un almacén GENERAL.');

        $r = $this->actingAs($admin)->getJson(route('almacen.productos.reparto', ['id' => $p->ID_PRODUCTO, 'id_almacen' => $general->ID_ALMACEN]))
            ->assertOk();
        $this->assertSame(['proyectos' => []], $r->json());

        // Sin almacén tampoco hay reparto (y no falla).
        $this->actingAs($admin)->getJson(route('almacen.productos.reparto', ['id' => $p->ID_PRODUCTO]))
            ->assertOk()->assertExactJson(['proyectos' => []]);
    }
}
