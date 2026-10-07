<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Traspaso;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Recepción con DESPACHO DIRECTO (AlmacenController::registrarRecepcionConDespacho): lo que
 * llega al almacén general entra y, en la misma operación, sale a cada proyecto con su Nota
 * de Entrega. Las líneas sin destino se quedan en stock.
 */
class RecepcionDespachoDirectoTest extends MySqlTestCase
{
    private int $general;
    private int $almProyecto;
    private int $frenteConsumo;     // sin almacén propio: la salida es consumo en el general
    private int $frenteConAlmacen;  // con almacén propio: la salida va como traspaso enviado
    private int $p1;
    private int $p2;

    protected function setUp(): void
    {
        parent::setUp();

        $libres = DB::table('frentes_trabajo')
            ->where('ESTATUS_FRENTE', 'ACTIVO')
            ->whereNotIn('ID_FRENTE', DB::table('almacen_frentes')->select('ID_FRENTE'))
            ->orderBy('ID_FRENTE')->limit(2)->pluck('ID_FRENTE')->map(fn ($v) => (int) $v)->all();
        if (count($libres) < 2) {
            $this->markTestSkipped('Hacen falta dos frentes activos sin almacén asignado.');
        }
        [$this->frenteConsumo, $this->frenteConAlmacen] = $libres;

        $this->general = (int) Almacen::forceCreate([
            'NOMBRE' => 'PRUEBA GENERAL DESPACHO ' . uniqid(), 'TIPO' => Almacen::TIPO_GENERAL, 'ESTATUS' => 'ACTIVO',
        ])->ID_ALMACEN;
        $this->almProyecto = (int) Almacen::forceCreate([
            'NOMBRE' => 'PRUEBA PROYECTO DESPACHO ' . uniqid(), 'TIPO' => Almacen::TIPO_PROYECTO, 'ESTATUS' => 'ACTIVO',
        ])->ID_ALMACEN;
        DB::table('almacen_frentes')->insert(['ID_ALMACEN' => $this->almProyecto, 'ID_FRENTE' => $this->frenteConAlmacen]);

        // Dos productos activos con a lo sumo un número de parte (los de varios se despachan
        // desde Salida, donde se elige cuál se entrega).
        $ids = ProductoInventario::where('ESTATUS', 'ACTIVO')
            ->whereNotIn('ID_PRODUCTO', DB::table('producto_equivalencias')->select('ID_PRODUCTO')
                ->groupBy('ID_PRODUCTO')->havingRaw('COUNT(*) > 1'))
            ->orderBy('ID_PRODUCTO')->limit(2)->pluck('ID_PRODUCTO')->map(fn ($v) => (int) $v)->all();
        [$this->p1, $this->p2] = $ids;
    }

    private function recepcion(array $lineas)
    {
        return $this->actingAs($this->superAdminGlobal())->postJson(route('almacen.recepcion.despacho'), [
            'id_almacen' => $this->general,
            'referencia' => 'NE-PROV-123',
            'motivo'     => 'PROVEEDOR DE PRUEBA',
            'lineas'     => $lineas,
        ]);
    }

    private function saldo(int $almacen, int $producto): float
    {
        return (float) AlmacenStock::where('ID_ALMACEN', $almacen)->where('ID_PRODUCTO', $producto)->sum('CANTIDAD');
    }

    public function test_entra_todo_y_sale_una_nota_por_proyecto(): void
    {
        $res = $this->recepcion([
            ['id_producto' => $this->p1, 'cantidad' => 10],                                               // se queda
            ['id_producto' => $this->p1, 'cantidad' => 4, 'id_frente_destino' => $this->frenteConsumo],
            ['id_producto' => $this->p2, 'cantidad' => 5, 'id_frente_destino' => $this->frenteConsumo],
            ['id_producto' => $this->p2, 'cantidad' => 3, 'id_frente_destino' => $this->frenteConAlmacen],
        ])->assertCreated();

        $notas = collect($res->json('notas'));
        $this->assertCount(2, $notas, 'Una nota por proyecto destino.');

        // La entrada es de TODO lo recibido, una línea por producto.
        $entradas = MovimientoInventario::where('ID_ALMACEN', $this->general)->where('TIPO', 'ENTRADA')->get();
        $this->assertCount(2, $entradas);
        $this->assertSame(14.0, (float) $entradas->firstWhere('ID_PRODUCTO', $this->p1)->CANTIDAD);
        $this->assertSame(8.0, (float) $entradas->firstWhere('ID_PRODUCTO', $this->p2)->CANTIDAD);
        $this->assertSame('NE-PROV-123', $entradas->first()->REFERENCIA);

        // En el general solo queda lo que no tenía destino.
        $this->assertSame(10.0, $this->saldo($this->general, $this->p1));
        $this->assertSame(0.0, $this->saldo($this->general, $this->p2));

        // Proyecto sin almacén propio: SALIDA (consumo) con las dos líneas en la misma nota.
        $notaConsumo = $notas->firstWhere('numero_traspaso', null)['numero_nota'];
        $salidas = MovimientoInventario::where('NUMERO_NOTA', $notaConsumo)->get();
        $this->assertCount(2, $salidas);
        $this->assertTrue($salidas->every(fn ($m) => $m->TIPO === 'SALIDA' && (int) $m->ID_FRENTE === $this->frenteConsumo));

        // Proyecto con almacén propio: traspaso ENVIADO, pendiente de recibir allá.
        $conTraspaso = $notas->first(fn ($n) => $n['numero_traspaso'] !== null);
        $this->assertSame(Traspaso::ESTADO_ENVIADO, Traspaso::where('NUMERO', $conTraspaso['numero_traspaso'])->value('ESTADO'));
        $this->assertSame(1, MovimientoInventario::where('NUMERO_NOTA', $conTraspaso['numero_nota'])->where('TIPO', 'TRASPASO_SALIDA')->count());

        // Cada nota abre su PDF, como cualquier nota de entrega.
        $this->actingAs($this->superAdminGlobal())->get($notas->first()['nota_url'])->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_si_un_proyecto_no_se_puede_despachar_no_se_registra_nada(): void
    {
        // El frente queda en DOS almacenes: no se puede deducir a cuál va.
        $otro = (int) Almacen::forceCreate([
            'NOMBRE' => 'PRUEBA PROYECTO DESPACHO 2 ' . uniqid(), 'TIPO' => Almacen::TIPO_PROYECTO, 'ESTATUS' => 'ACTIVO',
        ])->ID_ALMACEN;
        DB::table('almacen_frentes')->insert(['ID_ALMACEN' => $otro, 'ID_FRENTE' => $this->frenteConAlmacen]);

        $this->recepcion([
            ['id_producto' => $this->p1, 'cantidad' => 2, 'id_frente_destino' => $this->frenteConsumo],
            ['id_producto' => $this->p2, 'cantidad' => 3, 'id_frente_destino' => $this->frenteConAlmacen],
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'varios almacenes'));

        $this->assertSame(0, MovimientoInventario::where('ID_ALMACEN', $this->general)->count(), 'Ni la entrada ni la otra nota.');
    }

    public function test_en_almacen_que_separa_sale_del_proyecto_que_recibio(): void
    {
        // El almacén de proyecto del test pasa a manejar DOS proyectos: separa el saldo.
        DB::table('almacen_frentes')->insert(['ID_ALMACEN' => $this->almProyecto, 'ID_FRENTE' => $this->frenteConsumo]);
        $this->assertTrue(Almacen::find($this->almProyecto)->separaPorProyecto());
        // 2 que ya tenía el otro proyecto: no se pueden tocar.
        app(\App\Services\InventarioService::class)->registrarEntrada($this->almProyecto, $this->p1, 2, ['id_frente' => $this->frenteConsumo]);

        $this->actingAs($this->superAdminGlobal())->postJson(route('almacen.recepcion.despacho'), [
            'id_almacen' => $this->almProyecto,
            'id_frente'  => $this->frenteConAlmacen,   // proyecto que recibe
            'lineas'     => [
                ['id_producto' => $this->p1, 'cantidad' => 5, 'id_frente_destino' => $this->frenteConsumo],
                ['id_producto' => $this->p1, 'cantidad' => 1],
            ],
        ])->assertCreated();

        $bolsa = fn (int $frente) => (float) AlmacenStock::where('ID_ALMACEN', $this->almProyecto)
            ->where('ID_PRODUCTO', $this->p1)->where('ID_FRENTE', $frente)->value('CANTIDAD');
        $this->assertSame(1.0, $bolsa($this->frenteConAlmacen), 'Entraron 6 y salieron 5 del proyecto que recibió.');
        $this->assertSame(2.0, $bolsa($this->frenteConsumo), 'El saldo del otro proyecto queda igual.');
    }

    public function test_entrada_sin_proyecto_en_almacen_que_separa_se_rechaza(): void
    {
        DB::table('almacen_frentes')->insert(['ID_ALMACEN' => $this->almProyecto, 'ID_FRENTE' => $this->frenteConsumo]);

        $this->actingAs($this->superAdminGlobal())->postJson(route('almacen.recepcion.despacho'), [
            'id_almacen' => $this->almProyecto,
            'lineas'     => [['id_producto' => $this->p1, 'cantidad' => 5, 'id_frente_destino' => $this->frenteConsumo]],
        ])->assertStatus(422)->assertJsonValidationErrors('id_frente');

        $this->assertSame(0, MovimientoInventario::where('ID_ALMACEN', $this->almProyecto)->count());
    }

    public function test_sin_ningun_destino_se_rechaza(): void
    {
        $this->recepcion([['id_producto' => $this->p1, 'cantidad' => 2]])->assertStatus(422);
        $this->assertSame(0, MovimientoInventario::where('ID_ALMACEN', $this->general)->count());
    }
}
