<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Services\InventarioService;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Salida en un almacén que separa el saldo por proyecto: el proyecto elegido en el modal
 * «¿De qué proyecto sale?» de la tabla viaja por línea (lineas.*.id_frente_saldo) y la salida
 * se descuenta de ESE proyecto, aunque el material se entregue a otro. Sin elección empieza por
 * el proyecto destino, como siempre.
 */
class SalidaDesdeProyectoElegidoTest extends MySqlTestCase
{
    private int $almacen;
    private int $frenteA;
    private int $frenteB;
    private int $producto;

    protected function setUp(): void
    {
        parent::setUp();

        // Almacén PROYECTO propio del test con dos frentes que no tiene ningún otro almacén: así
        // la salida es un consumo aquí mismo (no un envío) y el saldo arranca vacío.
        $frentes = DB::table('frentes_trabajo')
            ->whereNotIn('ID_FRENTE', DB::table('almacen_frentes')->select('ID_FRENTE'))
            ->orderBy('ID_FRENTE')->limit(2)->pluck('ID_FRENTE')->map(fn ($v) => (int) $v)->all();
        if (count($frentes) < 2) {
            $this->markTestSkipped('Hacen falta dos frentes sin almacén asignado.');
        }
        [$this->frenteA, $this->frenteB] = $frentes;

        $this->almacen = (int) Almacen::forceCreate([
            'NOMBRE' => 'PRUEBA SALIDA POR PROYECTO ' . uniqid(), 'TIPO' => Almacen::TIPO_PROYECTO, 'ESTATUS' => 'ACTIVO',
        ])->ID_ALMACEN;
        DB::table('almacen_frentes')->insert([
            ['ID_ALMACEN' => $this->almacen, 'ID_FRENTE' => $this->frenteA],
            ['ID_ALMACEN' => $this->almacen, 'ID_FRENTE' => $this->frenteB],
        ]);
        $this->assertTrue(Almacen::find($this->almacen)->separaPorProyecto());

        // 3 del proyecto A y 2 del B.
        $this->producto = (int) ProductoInventario::where('ESTATUS', 'ACTIVO')->value('ID_PRODUCTO');
        $this->entrada($this->producto, 3, $this->frenteA);
        $this->entrada($this->producto, 2, $this->frenteB);
    }

    private function entrada(int $producto, float $cantidad, int $frente): void
    {
        app(InventarioService::class)->registrarEntrada($this->almacen, $producto, $cantidad, ['id_frente' => $frente]);
    }

    /** Salida entregada al proyecto A con las líneas dadas. */
    private function salida(array $lineas)
    {
        return $this->actingAs($this->superAdminGlobal())->postJson(route('almacen.movimientos.lote'), [
            'tipo'              => 'SALIDA',
            'id_almacen'        => $this->almacen,
            'id_frente_destino' => $this->frenteA,
            'lineas'            => $lineas,
        ]);
    }

    private function saldo(int $frente, ?int $producto = null): float
    {
        return (float) AlmacenStock::where('ID_ALMACEN', $this->almacen)->where('ID_PRODUCTO', $producto ?? $this->producto)
            ->where('ID_FRENTE', $frente)->value('CANTIDAD');
    }

    public function test_sale_del_proyecto_elegido_aunque_se_entregue_a_otro(): void
    {
        $this->salida([['id_producto' => $this->producto, 'cantidad' => 2, 'id_frente_saldo' => $this->frenteB]])->assertCreated();

        $this->assertSame(0.0, $this->saldo($this->frenteB), 'Las 2 salen del proyecto elegido.');
        $this->assertSame(3.0, $this->saldo($this->frenteA), 'El proyecto destino no se toca.');
    }

    public function test_sin_eleccion_sale_del_proyecto_destino(): void
    {
        $this->salida([['id_producto' => $this->producto, 'cantidad' => 2]])->assertCreated();

        $this->assertSame(1.0, $this->saldo($this->frenteA));
        $this->assertSame(2.0, $this->saldo($this->frenteB));
    }

    public function test_un_proyecto_ajeno_al_almacen_se_rechaza_sin_mover_stock(): void
    {
        $ajeno = (int) DB::table('frentes_trabajo')->whereNotIn('ID_FRENTE', [$this->frenteA, $this->frenteB])->value('ID_FRENTE');

        $this->salida([['id_producto' => $this->producto, 'cantidad' => 2, 'id_frente_saldo' => $ajeno]])->assertStatus(422);

        $this->assertSame(3.0, $this->saldo($this->frenteA));
        $this->assertSame(2.0, $this->saldo($this->frenteB));
    }

    public function test_una_nota_con_varios_productos_descuenta_cada_uno_de_su_proyecto(): void
    {
        // Segundo producto: 4 en cada proyecto.
        $otro = (int) ProductoInventario::where('ESTATUS', 'ACTIVO')->where('ID_PRODUCTO', '!=', $this->producto)->value('ID_PRODUCTO');
        $this->entrada($otro, 4, $this->frenteA);
        $this->entrada($otro, 4, $this->frenteB);

        $nota = $this->salida([
            ['id_producto' => $this->producto, 'cantidad' => 2, 'id_frente_saldo' => $this->frenteB],
            ['id_producto' => $otro,           'cantidad' => 3, 'id_frente_saldo' => $this->frenteA],
        ])->assertCreated()->json('numero_nota');

        $this->assertSame(0.0, $this->saldo($this->frenteB), 'El primero sale del proyecto B.');
        $this->assertSame(3.0, $this->saldo($this->frenteA));
        $this->assertSame(1.0, $this->saldo($this->frenteA, $otro), 'El segundo sale del proyecto A.');
        $this->assertSame(4.0, $this->saldo($this->frenteB, $otro));
        $this->assertSame(2, MovimientoInventario::where('NUMERO_NOTA', $nota)->count(), 'Las dos líneas en la misma Nota de Entrega.');
    }

    public function test_un_producto_sale_de_varios_proyectos_a_la_vez(): void
    {
        // Lo escrito en el modal: 2 del proyecto A y 2 del B, una línea por proyecto.
        $lineas = [
            ['id_producto' => $this->producto, 'cantidad' => 2, 'id_frente_saldo' => $this->frenteA],
            ['id_producto' => $this->producto, 'cantidad' => 2, 'id_frente_saldo' => $this->frenteB],
        ];

        $previa = $this->actingAs($this->superAdminGlobal())->post(route('almacen.salida.preview'), [
            'id_almacen' => $this->almacen, 'id_frente_destino' => $this->frenteA, 'lineas' => $lineas,
        ], ['Accept' => 'application/pdf']);
        $previa->assertOk();
        $this->assertEmpty($previa->headers->get('X-Salida-Aviso'), 'Cada parte sale de su proyecto: no hay nada prestado que avisar.');

        $nota = $this->salida($lineas)->assertCreated()->json('numero_nota');

        $this->assertSame(1.0, $this->saldo($this->frenteA), '2 de los 3 del proyecto A.');
        $this->assertSame(0.0, $this->saldo($this->frenteB), 'Los 2 del proyecto B.');
        $this->assertSame(2, MovimientoInventario::where('NUMERO_NOTA', $nota)->count(), 'Un movimiento por proyecto en la misma nota.');
    }

    public function test_hacia_un_almacen_propio_un_producto_no_sale_de_dos_proyectos(): void
    {
        // Un frente con su propio almacén: la salida hacia él viaja como traspaso.
        $frenteC = (int) DB::table('frentes_trabajo')
            ->whereNotIn('ID_FRENTE', DB::table('almacen_frentes')->select('ID_FRENTE'))
            ->value('ID_FRENTE');
        if (!$frenteC) {
            $this->markTestSkipped('Hace falta un tercer frente sin almacén asignado.');
        }
        $almacenC = (int) Almacen::forceCreate([
            'NOMBRE' => 'PRUEBA DESTINO PROPIO ' . uniqid(), 'TIPO' => Almacen::TIPO_PROYECTO, 'ESTATUS' => 'ACTIVO',
        ])->ID_ALMACEN;
        DB::table('almacen_frentes')->insert(['ID_ALMACEN' => $almacenC, 'ID_FRENTE' => $frenteC]);

        $datos = [
            'id_almacen' => $this->almacen, 'id_frente_destino' => $frenteC,
            'lineas' => [
                ['id_producto' => $this->producto, 'cantidad' => 1, 'id_frente_saldo' => $this->frenteA],
                ['id_producto' => $this->producto, 'cantidad' => 1, 'id_frente_saldo' => $this->frenteB],
            ],
        ];
        $usuario = $this->superAdminGlobal();

        $this->actingAs($usuario)->postJson(route('almacen.salida.preview'), $datos)
            ->assertStatus(422)->assertJsonFragment(['message' => $this->mensajeReparto()]);
        $this->actingAs($usuario)->postJson(route('almacen.movimientos.lote'), ['tipo' => 'SALIDA'] + $datos)
            ->assertStatus(422)->assertJsonFragment(['message' => $this->mensajeReparto()]);

        $this->assertSame(3.0, $this->saldo($this->frenteA));
        $this->assertSame(2.0, $this->saldo($this->frenteB));
    }

    private function mensajeReparto(): string
    {
        return 'El proyecto elegido recibe por su propio almacén y ahí cada producto sale de un solo proyecto. Saca '
            . ProductoInventario::find($this->producto)->NOMBRE . ' de un solo proyecto o despáchalo en dos notas.';
    }
}
