<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\FrenteTrabajo;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Usuario;
use App\Services\InventarioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Devolución de material de una Nota de Entrega (App\Services\DevolucionService): salen
 * BRAGA 45, las regresan porque era la 42 y se entrega la 42 a cambio. Todo corre en la
 * transacción de la prueba y se revierte al terminar.
 */
class DevolucionMaterialTest extends MySqlTestCase
{
    private InventarioService $inv;
    private string $marca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inv   = app(InventarioService::class);
        $this->marca = 'PRUEBA DEV ' . strtoupper(Str::random(6));
    }

    /** Usuario que registra movimientos y ve todos los almacenes (así ve los de la prueba). */
    private function usuario(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.movimiento') && Almacen::usuarioEsGlobal($u));
        $this->assertNotNull($u, 'No hay un usuario GLOBAL con almacen.movimiento para probar.');
        return $u;
    }

    private function almacen(string $tipo = Almacen::TIPO_GENERAL, array $frentes = []): Almacen
    {
        $a = Almacen::create(['NOMBRE' => 'ALMACEN ' . $this->marca, 'TIPO' => $tipo]);
        if ($frentes) {
            $a->frentes()->attach($frentes);
        }
        return $a->fresh();
    }

    private function producto(string $talla): ProductoInventario
    {
        return ProductoInventario::create([
            'CODIGO' => 'T' . strtoupper(Str::random(7)),
            'NOMBRE' => "BRAGA {$this->marca} TALLA {$talla}",
            'UM'     => 'UND',
        ]);
    }

    private function frentes(int $n): array
    {
        $ids = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->limit($n)->pluck('ID_FRENTE')->all();
        $this->assertCount($n, $ids, "Hacen falta {$n} frentes activos para probar.");
        return $ids;
    }

    /** Salida con Nota de Entrega, como la registra registrarMovimientoLote. */
    private function salidaConNota(int $idAlmacen, array $lineas, ?int $idFrente): string
    {
        return DB::transaction(function () use ($idAlmacen, $lineas, $idFrente) {
            $numero = MovimientoInventario::generarNumeroNota();
            foreach ($lineas as $idProducto => $cantidad) {
                $this->inv->registrarSalida($idAlmacen, $idProducto, $cantidad, [
                    'id_frente' => $idFrente, 'numero_nota' => $numero, 'solicitante' => 'JUAN PRUEBA',
                ]);
            }
            return $numero;
        });
    }

    private function saldo(int $idAlmacen, int $idProducto, ?int $bolsa = null): float
    {
        return (float) AlmacenStock::where('ID_ALMACEN', $idAlmacen)->where('ID_PRODUCTO', $idProducto)
            ->when($bolsa !== null, fn ($q) => $q->where('ID_FRENTE', $bolsa))
            ->sum('CANTIDAD');
    }

    private function devolver(string $numero, array $lineas, array $extra = [])
    {
        return $this->actingAs($this->usuario())
            ->postJson(route('almacen.devolucion.store'), ['numero' => $numero, 'lineas' => $lineas] + $extra);
    }

    public function test_devolver_con_cambio_mueve_el_stock_deja_el_rastro_y_no_cuenta_como_consumo(): void
    {
        [$idFrente] = $this->frentes(1);
        $alm = $this->almacen();
        $p45 = $this->producto('45');
        $p42 = $this->producto('42');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p45->ID_PRODUCTO, 10);
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p42->ID_PRODUCTO, 10);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p45->ID_PRODUCTO => 5], $idFrente);

        $r = $this->devolver($nota, [[
            'id_producto' => $p45->ID_PRODUCTO, 'cantidad' => 5, 'id_producto_cambio' => $p42->ID_PRODUCTO,
        ]], ['motivo' => 'Talla equivocada'])->assertCreated();

        // Stock: la 45 vuelve entera y la 42 sale.
        $this->assertSame(10.0, $this->saldo($alm->ID_ALMACEN, $p45->ID_PRODUCTO));
        $this->assertSame(5.0, $this->saldo($alm->ID_ALMACEN, $p42->ID_PRODUCTO));

        // La devolución apunta a la salida y a su nota; la entrega a cambio tiene nota propia.
        $salida = MovimientoInventario::where('NUMERO_NOTA', $nota)->firstOrFail();
        $dev    = MovimientoInventario::where('TIPO', MovimientoInventario::TIPO_DEVOLUCION)
            ->where('ID_MOVIMIENTO_RELACIONADO', $salida->ID_MOVIMIENTO)->firstOrFail();
        $this->assertSame($nota, $dev->REFERENCIA);
        $this->assertSame((int) $idFrente, (int) $dev->ID_FRENTE);
        $this->assertSame('Talla equivocada', $dev->MOTIVO);

        $notaCambio = $r->json('numero_nota');
        $this->assertNotSame($nota, $notaCambio);
        $cambio = MovimientoInventario::where('NUMERO_NOTA', $notaCambio)->firstOrFail();
        $this->assertSame(MovimientoInventario::TIPO_SALIDA, $cambio->TIPO);
        $this->assertSame((int) $p42->ID_PRODUCTO, (int) $cambio->ID_PRODUCTO);
        $this->assertSame($nota, $cambio->REFERENCIA);
        $this->assertSame('JUAN PRUEBA', $cambio->SOLICITANTE, 'La nota del cambio lleva los datos de la original.');
        $this->assertStringContainsString("Cambio por devolución de la Nota {$nota}", $cambio->MOTIVO);

        // Consumo: la 45 devuelta no cuenta; la 42 sí.
        $dash = $this->actingAs($this->usuario())
            ->getJson(route('almacen.consumoDashboard', ['descripcion' => "BRAGA {$this->marca}"]))
            ->assertOk()->json('top_productos');
        $this->assertSame(["BRAGA {$this->marca} TALLA 42"], array_column($dash, 'nombre'));
        $this->assertEquals(5, $dash[0]['total']);
    }

    public function test_no_deja_devolver_mas_de_lo_entregado(): void
    {
        $alm = $this->almacen();
        $p = $this->producto('45');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 10);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p->ID_PRODUCTO => 5], null);

        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 3]])->assertCreated();
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 3]])
            ->assertStatus(422)->assertJsonFragment(['message' => "De «{$p->NOMBRE}» quedan 2 UND por devolver en la Nota {$nota}; no se pueden devolver 3."]);

        $this->assertSame(8.0, $this->saldo($alm->ID_ALMACEN, $p->ID_PRODUCTO));

        // El resumen de la nota dice lo mismo.
        $linea = $this->actingAs($this->usuario())->getJson(route('almacen.devolucion.show', ['numero' => strtolower($nota)]))
            ->assertOk()->json('lineas.0');
        $this->assertEquals([5, 3, 2], [$linea['entregado'], $linea['devuelto'], $linea['pendiente']]);
    }

    public function test_sin_stock_del_cambio_no_queda_la_devolucion_a_medias(): void
    {
        $alm = $this->almacen();
        $p45 = $this->producto('45');
        $p42 = $this->producto('42');   // sin stock
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p45->ID_PRODUCTO, 10);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p45->ID_PRODUCTO => 5], null);

        $this->devolver($nota, [['id_producto' => $p45->ID_PRODUCTO, 'cantidad' => 5, 'id_producto_cambio' => $p42->ID_PRODUCTO]])
            ->assertStatus(422);

        $this->assertSame(5.0, $this->saldo($alm->ID_ALMACEN, $p45->ID_PRODUCTO), 'La devolución tenía que revertirse con el cambio.');
        $this->assertSame(0, MovimientoInventario::where('TIPO', MovimientoInventario::TIPO_DEVOLUCION)
            ->where('ID_PRODUCTO', $p45->ID_PRODUCTO)->count());
    }

    public function test_en_un_almacen_por_proyecto_vuelve_a_la_bolsa_de_la_que_salio(): void
    {
        [$fA, $fB] = $this->frentes(2);
        $alm = $this->almacen(Almacen::TIPO_PROYECTO, [$fA, $fB]);
        $this->assertTrue($alm->separaPorProyecto());
        $p = $this->producto('45');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 3, ['id_frente' => $fA]);
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 4, ['id_frente' => $fB]);

        // 5 al proyecto A: 3 de su bolsa y 2 prestadas de la de B.
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p->ID_PRODUCTO => 5], $fA);
        $this->assertSame(0.0, $this->saldo($alm->ID_ALMACEN, $p->ID_PRODUCTO, $fA));
        $this->assertSame(2.0, $this->saldo($alm->ID_ALMACEN, $p->ID_PRODUCTO, $fB));

        // Lo primero que vuelve salda el préstamo...
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2]])->assertCreated();
        $this->assertSame(0.0, $this->saldo($alm->ID_ALMACEN, $p->ID_PRODUCTO, $fA));
        $this->assertSame(4.0, $this->saldo($alm->ID_ALMACEN, $p->ID_PRODUCTO, $fB));

        // ...y el kardex lo dice como reposición, no como un préstamo nuevo.
        $html = $this->actingAs($this->usuario())
            ->getJson(route('almacen.movimientos', ['id_almacen' => $alm->ID_ALMACEN, 'tipo' => 'DEVOLUCION', 'skip_consumo' => 1]))
            ->assertOk()->json('html');
        $this->assertStringContainsString('devuelto a', $html);
        $this->assertStringNotContainsString('tomado de', $html);

        // ...y el resto vuelve a la bolsa del proyecto.
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 3]])->assertCreated();
        $this->assertSame(3.0, $this->saldo($alm->ID_ALMACEN, $p->ID_PRODUCTO, $fA));
        $this->assertSame(4.0, $this->saldo($alm->ID_ALMACEN, $p->ID_PRODUCTO, $fB));
    }

    public function test_deshacer_la_devolucion_no_toca_la_salida_y_deshacer_la_salida_se_lleva_sus_devoluciones(): void
    {
        $alm = $this->almacen();
        $p = $this->producto('45');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 10);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p->ID_PRODUCTO => 5], null);
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2]])->assertCreated();
        $salida = MovimientoInventario::where('NUMERO_NOTA', $nota)->firstOrFail();
        $dev = MovimientoInventario::where('ID_MOVIMIENTO_RELACIONADO', $salida->ID_MOVIMIENTO)->firstOrFail();

        $this->assertSame(1, $this->inv->eliminarMovimientoConReverso($dev->ID_MOVIMIENTO)['eliminados']);
        $this->assertNotNull(MovimientoInventario::find($salida->ID_MOVIMIENTO), 'Deshacer la devolución borró la salida.');
        $this->assertSame(5.0, $this->saldo($alm->ID_ALMACEN, $p->ID_PRODUCTO));

        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2]])->assertCreated();
        $this->assertSame(2, $this->inv->eliminarMovimientoConReverso($salida->ID_MOVIMIENTO)['eliminados']);
        $this->assertSame(10.0, $this->saldo($alm->ID_ALMACEN, $p->ID_PRODUCTO));
    }

    public function test_eliminar_una_nota_con_devolucion_revierte_solo_lo_que_falta(): void
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.nota.eliminar') && Almacen::usuarioEsGlobal($u));
        if (!$u) {
            $this->markTestSkipped('No hay un usuario GLOBAL con almacen.nota.eliminar.');
        }
        $alm = $this->almacen();
        $p = $this->producto('45');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 10);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p->ID_PRODUCTO => 5], null);
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2]])->assertCreated();

        $this->actingAs($u)->deleteJson(route('almacen.nota-entrega.destroy', ['numero' => $nota]))->assertOk();

        $this->assertSame(10.0, $this->saldo($alm->ID_ALMACEN, $p->ID_PRODUCTO), 'Revertir la nota entera sumó dos veces lo ya devuelto.');
        $this->assertSame(5.0, (float) MovimientoInventario::where('TIPO', MovimientoInventario::TIPO_DEVOLUCION)
            ->where('ID_PRODUCTO', $p->ID_PRODUCTO)->sum('CANTIDAD'));
    }

    public function test_reglas_de_la_peticion(): void
    {
        $alm = $this->almacen();
        $p = $this->producto('45');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 10);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p->ID_PRODUCTO => 5], null);

        // Fecha anterior a la nota.
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1]], ['fecha' => now()->subYears(2)->toDateString()])
            ->assertStatus(422);
        // A cambio del mismo producto.
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1, 'id_producto_cambio' => $p->ID_PRODUCTO]])
            ->assertStatus(422);
        // Producto que no está en la nota.
        $otro = $this->producto('40');
        $this->devolver($nota, [['id_producto' => $otro->ID_PRODUCTO, 'cantidad' => 1]])->assertStatus(422);
        // Nota inexistente.
        $this->devolver('NE-1999-9999', [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1]])->assertStatus(404);

        $this->assertSame(5.0, $this->saldo($alm->ID_ALMACEN, $p->ID_PRODUCTO), 'Una petición rechazada movió stock.');

        // Sin la clave almacen.movimiento no se registra.
        $sinClave = Usuario::all()->first(fn ($u) => !$u->can('almacen.movimiento'));
        if ($sinClave) {
            $this->actingAs($sinClave)->postJson(route('almacen.devolucion.store'), [
                'numero' => $nota, 'lineas' => [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1]],
            ])->assertForbidden();
        }
    }

    public function test_un_envio_a_otro_almacen_no_se_devuelve_desde_aqui(): void
    {
        $numero = MovimientoInventario::where('TIPO', MovimientoInventario::TIPO_TRASPASO_SALIDA)
            ->whereNotNull('NUMERO_NOTA')->orderByDesc('ID_MOVIMIENTO')->value('NUMERO_NOTA');
        if (!$numero) {
            $this->markTestSkipped('No hay notas de envío entre almacenes para probar.');
        }

        $this->actingAs($this->usuario())->getJson(route('almacen.devolucion.show', ['numero' => $numero]))
            ->assertStatus(422);
    }

    public function test_la_bitacora_pinta_la_devolucion_enlazada_a_su_nota(): void
    {
        $alm = $this->almacen();
        $p = $this->producto('45');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 10);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p->ID_PRODUCTO => 5], null);
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2]])->assertCreated();

        $html = $this->actingAs($this->usuario())
            ->getJson(route('almacen.movimientos', ['id_almacen' => $alm->ID_ALMACEN, 'tipo' => 'DEVOLUCION', 'skip_consumo' => 1]))
            ->assertOk()->json('html');

        $this->assertStringContainsString('Devolución', $html);
        $this->assertStringContainsString('+2', $html, 'La devolución tiene que sumar.');
        $this->assertStringContainsString(route('almacen.nota-entrega', ['numero' => $nota]), $html, 'Tiene que enlazar a la nota de la que vuelve.');

        // El kardex de "Movimientos del producto": la devolución entra en Entradas y enlaza a su nota.
        $mini = $this->actingAs($this->usuario())
            ->getJson(route('almacen.movimientos', ['id_almacen' => $alm->ID_ALMACEN, 'id_producto' => $p->ID_PRODUCTO, 'tipo' => 'ENTRADAS', 'mini' => 1]))
            ->assertOk()->json('html');
        $this->assertStringContainsString('Devolución', $mini);
        $this->assertStringContainsString(route('almacen.nota-entrega', ['numero' => $nota]), $mini);
    }
}
