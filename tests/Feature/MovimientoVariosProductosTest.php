<?php

namespace Tests\Feature;

use App\Http\Middleware\OperacionUnica;
use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Usuario;
use App\Services\InventarioService;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Una salida o una entrada con VARIOS productos (como las manda la bandeja, con su clave de
 * operación) mueve el stock de TODOS y deja un movimiento por producto.
 *
 * No escribe nada permanente: MySqlTestCase revierte la transacción al terminar.
 */
class MovimientoVariosProductosTest extends MySqlTestCase
{
    public function test_salida_de_dos_productos_descuenta_los_dos(): void
    {
        [$u, $alm, $p1, $p2] = $this->escenario();

        $this->lote($u, 'SALIDA', $alm, [[$p1, 3], [$p2, 4]])->assertCreated();

        $this->assertSame(7.0, $this->saldo($alm, $p1));
        $this->assertSame(6.0, $this->saldo($alm, $p2));
        $this->assertSame(2, MovimientoInventario::where('ID_ALMACEN', $alm->ID_ALMACEN)->where('TIPO', 'SALIDA')->count());
    }

    public function test_entrada_de_dos_productos_suma_los_dos(): void
    {
        [$u, $alm, $p1, $p2] = $this->escenario();

        $this->lote($u, 'ENTRADA', $alm, [[$p1, 5], [$p2, 1]])->assertSuccessful();

        $this->assertSame(15.0, $this->saldo($alm, $p1));
        $this->assertSame(11.0, $this->saldo($alm, $p2));
    }

    private function lote(Usuario $u, string $tipo, Almacen $alm, array $lineas)
    {
        return $this->actingAs($u)->postJson(route('almacen.movimientos.lote'), [
            'tipo'       => $tipo,
            'id_almacen' => $alm->ID_ALMACEN,
            'lineas'     => array_map(fn ($l) => ['id_producto' => $l[0]->ID_PRODUCTO, 'cantidad' => $l[1]], $lineas),
        ], [OperacionUnica::CABECERA => (string) Str::uuid()]);
    }

    private function saldo(Almacen $alm, ProductoInventario $p): float
    {
        return (float) AlmacenStock::where('ID_ALMACEN', $alm->ID_ALMACEN)->where('ID_PRODUCTO', $p->ID_PRODUCTO)->sum('CANTIDAD');
    }

    /** Un almacén general con dos productos de 10 unidades cada uno. */
    private function escenario(): array
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.movimiento') && Almacen::usuarioEsGlobal($u));
        if (! $u) {
            $this->markTestSkipped('Hace falta un usuario GLOBAL con almacen.movimiento.');
        }
        $marca = 'PRUEBA VARIOS ' . strtoupper(Str::random(6));
        $alm = Almacen::create(['NOMBRE' => 'ALMACEN ' . $marca, 'TIPO' => Almacen::TIPO_GENERAL]);
        $productos = [];
        foreach (['TORNILLO', 'TUERCA'] as $nombre) {
            $p = ProductoInventario::create(['CODIGO' => 'C' . strtoupper(Str::random(7)), 'NOMBRE' => $nombre . ' ' . $marca, 'UM' => 'UND']);
            app(InventarioService::class)->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 10);
            $productos[] = $p;
        }

        return [$u, $alm, ...$productos];
    }
}
