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
 * «Un solo inventario» (almacenes.INVENTARIO_UNICO): un almacén de PROYECTO con varios frentes
 * lleva un saldo por producto, como si fuera de un solo proyecto. Al marcarlo, lo que ya estaba
 * separado se junta en la bolsa común sin cambiar el total; lo mismo si le queda un solo frente.
 */
class InventarioUnicoTest extends MySqlTestCase
{
    private int $almacen;
    private int $frenteA;
    private int $frenteB;
    private int $producto;

    protected function setUp(): void
    {
        parent::setUp();

        // Almacén propio del test con dos frentes que no tiene ningún otro almacén: la salida
        // es un consumo aquí mismo y el saldo arranca vacío.
        $frentes = DB::table('frentes_trabajo')
            ->whereNotIn('ID_FRENTE', DB::table('almacen_frentes')->select('ID_FRENTE'))
            ->orderBy('ID_FRENTE')->limit(2)->pluck('ID_FRENTE')->map(fn ($v) => (int) $v)->all();
        if (count($frentes) < 2) {
            $this->markTestSkipped('Hacen falta dos frentes sin almacén asignado.');
        }
        [$this->frenteA, $this->frenteB] = $frentes;

        $this->almacen = (int) Almacen::forceCreate([
            'NOMBRE' => 'PRUEBA INVENTARIO UNICO ' . uniqid(), 'TIPO' => Almacen::TIPO_PROYECTO, 'ESTATUS' => 'ACTIVO',
            'ALMACENISTA' => 'PRUEBA', 'CARGO_ALMACENISTA' => 'PRUEBA',
        ])->ID_ALMACEN;
        DB::table('almacen_frentes')->insert([
            ['ID_ALMACEN' => $this->almacen, 'ID_FRENTE' => $this->frenteA],
            ['ID_ALMACEN' => $this->almacen, 'ID_FRENTE' => $this->frenteB],
        ]);

        // 3 del proyecto A, 2 del B y 1 sin proyecto.
        $this->producto = (int) ProductoInventario::where('ESTATUS', 'ACTIVO')->value('ID_PRODUCTO');
        $this->entrada(3, $this->frenteA);
        $this->entrada(2, $this->frenteB);
        $this->entrada(1, null);
    }

    private function entrada(float $cantidad, ?int $frente): void
    {
        app(InventarioService::class)->registrarEntrada($this->almacen, $this->producto, $cantidad, ['id_frente' => $frente]);
    }

    /** Guarda «Editar almacén» como lo manda el modal. */
    private function guardar(bool $unico, array $frentes)
    {
        $a = Almacen::find($this->almacen);
        return $this->actingAs($this->superAdminGlobal())->patchJson(route('almacen.almacenes.update', $this->almacen), [
            'NOMBRE' => $a->NOMBRE, 'TIPO' => $a->TIPO, 'ALMACENISTA' => 'PRUEBA', 'CARGO_ALMACENISTA' => 'PRUEBA',
            'INVENTARIO_UNICO' => $unico, 'frentes' => $frentes,
        ]);
    }

    /** [bolsa => saldo] del producto, sin las filas en 0. */
    private function saldos(): array
    {
        return AlmacenStock::where('ID_ALMACEN', $this->almacen)->where('ID_PRODUCTO', $this->producto)
            ->where('CANTIDAD', '!=', 0)->orderBy('ID_FRENTE')
            ->pluck('CANTIDAD', 'ID_FRENTE')->map(fn ($v) => (float) $v)->all();
    }

    public function test_marcarlo_junta_los_saldos_sin_cambiar_el_total(): void
    {
        $this->assertSame([0 => 1.0, $this->frenteA => 3.0, $this->frenteB => 2.0], $this->saldos());

        $this->guardar(true, [$this->frenteA, $this->frenteB])->assertOk()
            ->assertJsonPath('message', 'Almacén actualizado. El saldo de 1 producto quedó en un solo inventario.');

        $this->assertSame([0 => 6.0], $this->saldos());
        $this->assertSame(1, AlmacenStock::where('ID_ALMACEN', $this->almacen)->count(), 'Una sola fila por producto.');
        $this->assertFalse(Almacen::find($this->almacen)->separaPorProyecto());
        $this->assertSame(0, MovimientoInventario::where('ID_ALMACEN', $this->almacen)->where('ID_FRENTE_SALDO', '!=', 0)->count(),
            'El kardex queda en la misma bolsa que el saldo.');
        $this->assertSame(2, MovimientoInventario::where('ID_ALMACEN', $this->almacen)->whereIn('ID_FRENTE', [$this->frenteA, $this->frenteB])->count(),
            'A quién se le entregó (ID_FRENTE) no se toca.');
    }

    public function test_guardar_otra_vez_no_cambia_nada(): void
    {
        $this->guardar(true, [$this->frenteA, $this->frenteB])->assertOk();
        $this->guardar(true, [$this->frenteA, $this->frenteB])->assertOk()->assertJsonPath('message', 'Almacén actualizado.');
        $this->assertSame([0 => 6.0], $this->saldos());
    }

    public function test_con_un_solo_inventario_no_se_elige_proyecto(): void
    {
        $this->guardar(true, [$this->frenteA, $this->frenteB])->assertOk();

        // Una entrada para un proyecto suma al mismo saldo.
        $this->entrada(4, $this->frenteB);
        $this->assertSame([0 => 10.0], $this->saldos());

        // La salida ignora el proyecto pedido: todo sale del único saldo.
        $this->actingAs($this->superAdminGlobal())->postJson(route('almacen.movimientos.lote'), [
            'tipo' => 'SALIDA', 'id_almacen' => $this->almacen, 'id_frente_destino' => $this->frenteA,
            'lineas' => [['id_producto' => $this->producto, 'cantidad' => 7, 'id_frente_saldo' => $this->frenteB]],
        ])->assertCreated();
        $this->assertSame([0 => 3.0], $this->saldos());
    }

    public function test_borrar_un_movimiento_despues_de_juntar_recalcula_bien(): void
    {
        $this->guardar(true, [$this->frenteA, $this->frenteB])->assertOk();

        // La entrada de 2 al proyecto B se borra: el único saldo baja de 6 a 4.
        $mov = MovimientoInventario::where('ID_ALMACEN', $this->almacen)->where('ID_FRENTE', $this->frenteB)->value('ID_MOVIMIENTO');
        app(InventarioService::class)->eliminarMovimientoConReverso((int) $mov);
        $this->assertSame([0 => 4.0], $this->saldos());
    }

    public function test_dejarle_un_solo_frente_tambien_junta_los_saldos(): void
    {
        $this->guardar(false, [$this->frenteA])->assertOk();
        $this->assertSame([0 => 6.0], $this->saldos());
    }

    public function test_desmarcarlo_vuelve_a_separar_lo_que_entra_despues(): void
    {
        $this->guardar(true, [$this->frenteA, $this->frenteB])->assertOk();
        $this->guardar(false, [$this->frenteA, $this->frenteB])->assertOk();
        $this->assertTrue(Almacen::find($this->almacen)->separaPorProyecto());

        $this->entrada(2, $this->frenteA);
        $this->assertSame([0 => 6.0, $this->frenteA => 2.0], $this->saldos(), 'Lo juntado sigue en la común; lo nuevo, en su proyecto.');

        // Y la común se sigue recalculando entera (su kardex quedó en la bolsa 0).
        $mov = MovimientoInventario::where('ID_ALMACEN', $this->almacen)->whereNull('ID_FRENTE')->value('ID_MOVIMIENTO');
        app(InventarioService::class)->eliminarMovimientoConReverso((int) $mov);
        $this->assertSame([0 => 5.0, $this->frenteA => 2.0], $this->saldos());
    }

    public function test_con_columnas_fijas_tambien_se_lee_la_casilla(): void
    {
        $this->guardar(true, [$this->frenteA, $this->frenteB])->assertOk();

        // Como la leen la copia offline y la Recepción: ya no separa, así que una entrada sin
        // proyecto se rotula con el primer frente, igual que en un almacén de un solo frente.
        $a = Almacen::with('frentes:ID_FRENTE')->whereKey($this->almacen)->get(['ID_ALMACEN', 'TIPO', 'INVENTARIO_UNICO'])->first();
        $this->assertFalse($a->separaPorProyecto());
        $this->assertSame($this->frenteA, $a->frenteImplicito());
    }
}
