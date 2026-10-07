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
 * "Registrar y despachar" de la recepción de materiales
 * (AlmacenController::registrarRecepcionConDespacho): lo que llega entra y, en la misma
 * operación, sale al proyecto con su Nota de Entrega — con los mismos datos de la nota que la
 * salida de /admin/almacen.
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

    private function despachar(int $almacen, int $frente, array $extra = [])
    {
        return $this->actingAs($this->superAdminGlobal())->postJson(route('almacen.recepcion.despacho'), [
            'id_almacen'        => $almacen,
            'referencia'        => 'NE-PROV-123',
            'proveedor'         => 'PROVEEDOR DE PRUEBA',
            'id_frente_destino' => $frente,
            'lineas'            => [
                ['id_producto' => $this->p1, 'cantidad' => 4],
                ['id_producto' => $this->p2, 'cantidad' => 5],
                ['id_producto' => $this->p1, 'cantidad' => 1],   // repetido: se suma
            ],
        ] + $extra);
    }

    private function saldo(int $almacen, int $producto, ?int $frente = null): float
    {
        return (float) AlmacenStock::where('ID_ALMACEN', $almacen)->where('ID_PRODUCTO', $producto)
            ->when($frente !== null, fn ($q) => $q->where('ID_FRENTE', $frente))->sum('CANTIDAD');
    }

    public function test_entra_y_sale_al_proyecto_con_su_nota_de_entrega(): void
    {
        $nota = $this->despachar($this->general, $this->frenteConsumo, [
            'numero_contrato'     => 'CTR-9',
            'numero_rq'           => 'RQ-77',
            'solicitante'         => 'ING. PEREZ',
            'departamento'        => 'MANTENIMIENTO',
            'motivo'              => 'DESPACHO DE QUINCENA',
            'transporte_chofer'   => 'JOSE PEREZ',
            'transporte_cedula'   => '12.345.678',
        ])->assertCreated()->assertJsonPath('numero_traspaso', null)->json('numero_nota');

        // La entrada lleva la nota y el proveedor de la compra, una línea por producto.
        $entradas = MovimientoInventario::where('ID_ALMACEN', $this->general)->where('TIPO', 'ENTRADA')->get();
        $this->assertCount(2, $entradas);
        $this->assertSame(5.0, (float) $entradas->firstWhere('ID_PRODUCTO', $this->p1)->CANTIDAD);
        $this->assertSame('NE-PROV-123', $entradas->first()->REFERENCIA);
        $this->assertSame('PROVEEDOR DE PRUEBA', $entradas->first()->MOTIVO);

        // La salida es la nota de entrega, con todos los datos que pide la de /admin/almacen.
        $salidas = MovimientoInventario::where('NUMERO_NOTA', $nota)->get();
        $this->assertCount(2, $salidas);
        $s = $salidas->first();
        $this->assertSame('SALIDA', $s->TIPO);
        $this->assertSame($this->frenteConsumo, (int) $s->ID_FRENTE);
        $this->assertSame(['CTR-9', 'RQ-77', 'ING. PEREZ', 'MANTENIMIENTO', 'DESPACHO DE QUINCENA', 'JOSE PEREZ'],
            [$s->NUMERO_CONTRATO, $s->NUMERO_RQ, $s->SOLICITANTE, $s->DEPARTAMENTO, $s->MOTIVO, $s->TRANSPORTE_CHOFER]);
        $this->assertSame(Almacen::find($this->general)->formatoNota(), $s->FORMATO_NOTA, 'En el formato del almacén.');

        // No queda nada en el almacén: todo salió.
        $this->assertSame(0.0, $this->saldo($this->general, $this->p1));
        $this->assertSame(0.0, $this->saldo($this->general, $this->p2));

        // La nota abre su PDF, como cualquier nota de entrega.
        $this->actingAs($this->superAdminGlobal())->get(route('almacen.nota-entrega', ['numero' => $nota]))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_a_un_proyecto_con_almacen_propio_sale_como_traspaso_enviado(): void
    {
        $res = $this->despachar($this->general, $this->frenteConAlmacen)->assertCreated();

        $this->assertSame(Traspaso::ESTADO_ENVIADO, Traspaso::where('NUMERO', $res->json('numero_traspaso'))->value('ESTADO'));
        $this->assertSame(2, MovimientoInventario::where('NUMERO_NOTA', $res->json('numero_nota'))->where('TIPO', 'TRASPASO_SALIDA')->count());
        $this->assertSame(0.0, $this->saldo($this->general, $this->p1));
    }

    public function test_en_almacen_que_separa_sale_de_la_bolsa_a_la_que_entro(): void
    {
        // El almacén de proyecto pasa a manejar DOS proyectos: separa el saldo.
        DB::table('almacen_frentes')->insert(['ID_ALMACEN' => $this->almProyecto, 'ID_FRENTE' => $this->frenteConsumo]);
        $this->assertTrue(Almacen::find($this->almProyecto)->separaPorProyecto());
        // 2 que ya tenía el otro proyecto: no se pueden tocar.
        app(\App\Services\InventarioService::class)->registrarEntrada($this->almProyecto, $this->p1, 2, ['id_frente' => $this->frenteConAlmacen]);

        $this->despachar($this->almProyecto, $this->frenteConsumo)->assertCreated();

        $this->assertSame(0.0, $this->saldo($this->almProyecto, $this->p1, $this->frenteConsumo), 'Entraron 5 al proyecto destino y salieron 5.');
        $this->assertSame(2.0, $this->saldo($this->almProyecto, $this->p1, $this->frenteConAlmacen), 'El saldo del otro proyecto queda igual.');
    }

    public function test_si_no_se_puede_despachar_no_se_registra_nada(): void
    {
        // El frente queda en DOS almacenes y no se dijo a cuál va.
        $otro = (int) Almacen::forceCreate([
            'NOMBRE' => 'PRUEBA PROYECTO DESPACHO 2 ' . uniqid(), 'TIPO' => Almacen::TIPO_PROYECTO, 'ESTATUS' => 'ACTIVO',
        ])->ID_ALMACEN;
        DB::table('almacen_frentes')->insert(['ID_ALMACEN' => $otro, 'ID_FRENTE' => $this->frenteConAlmacen]);

        $this->despachar($this->general, $this->frenteConAlmacen)->assertStatus(422)->assertJsonStructure(['almacenes_destino']);

        $this->assertSame(0, MovimientoInventario::where('ID_ALMACEN', $this->general)->count(), 'Ni la entrada.');
    }

    public function test_si_falla_despues_de_la_entrada_no_queda_nada(): void
    {
        // El envío del traspaso revienta cuando las entradas ya se escribieron: la
        // transacción tiene que deshacerlas también.
        $this->mock(\App\Services\TraspasoService::class, function ($m) {
            $m->shouldReceive('crearBorrador')->andThrow(new \RuntimeException('Falla simulada al enviar.'));
        });

        $this->despachar($this->general, $this->frenteConAlmacen)
            ->assertStatus(422)->assertJsonPath('message', 'Falla simulada al enviar.');

        $this->assertSame(0, MovimientoInventario::where('ID_ALMACEN', $this->general)->count(), 'Ni la entrada.');
        $this->assertSame(0.0, $this->saldo($this->general, $this->p1));
    }

    public function test_sin_proyecto_destino_se_rechaza(): void
    {
        $this->actingAs($this->superAdminGlobal())->postJson(route('almacen.recepcion.despacho'), [
            'id_almacen' => $this->general,
            'lineas'     => [['id_producto' => $this->p1, 'cantidad' => 2]],
        ])->assertStatus(422)->assertJsonValidationErrors('id_frente_destino');

        $this->assertSame(0, MovimientoInventario::where('ID_ALMACEN', $this->general)->count());
    }
}
