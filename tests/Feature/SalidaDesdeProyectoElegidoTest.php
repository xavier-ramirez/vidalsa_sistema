<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\AlmacenStock;
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
        app(InventarioService::class)->registrarEntrada($this->almacen, $this->producto, 3, ['id_frente' => $this->frenteA]);
        app(InventarioService::class)->registrarEntrada($this->almacen, $this->producto, 2, ['id_frente' => $this->frenteB]);
    }

    /** Salida de 2 entregada al proyecto A; $linea añade (o no) el proyecto del que sale. */
    private function salida(array $linea)
    {
        return $this->actingAs($this->superAdminGlobal())->postJson(route('almacen.movimientos.lote'), [
            'tipo'              => 'SALIDA',
            'id_almacen'        => $this->almacen,
            'id_frente_destino' => $this->frenteA,
            'lineas'            => [['id_producto' => $this->producto, 'cantidad' => 2] + $linea],
        ]);
    }

    private function saldo(int $frente): float
    {
        return (float) AlmacenStock::where('ID_ALMACEN', $this->almacen)->where('ID_PRODUCTO', $this->producto)
            ->where('ID_FRENTE', $frente)->value('CANTIDAD');
    }

    public function test_sale_del_proyecto_elegido_aunque_se_entregue_a_otro(): void
    {
        $this->salida(['id_frente_saldo' => $this->frenteB])->assertCreated();

        $this->assertSame(0.0, $this->saldo($this->frenteB), 'Las 2 salen del proyecto elegido.');
        $this->assertSame(3.0, $this->saldo($this->frenteA), 'El proyecto destino no se toca.');
    }

    public function test_sin_eleccion_sale_del_proyecto_destino(): void
    {
        $this->salida([])->assertCreated();

        $this->assertSame(1.0, $this->saldo($this->frenteA));
        $this->assertSame(2.0, $this->saldo($this->frenteB));
    }

    public function test_un_proyecto_ajeno_al_almacen_se_rechaza_sin_mover_stock(): void
    {
        $ajeno = (int) DB::table('frentes_trabajo')->whereNotIn('ID_FRENTE', [$this->frenteA, $this->frenteB])->value('ID_FRENTE');

        $this->salida(['id_frente_saldo' => $ajeno])->assertStatus(422);

        $this->assertSame(3.0, $this->saldo($this->frenteA));
        $this->assertSame(2.0, $this->saldo($this->frenteB));
    }
}
