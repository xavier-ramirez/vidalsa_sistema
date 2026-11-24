<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\FrenteTrabajo;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Usuario;
use App\Services\CorreccionNotaService;
use App\Services\DevolucionService;
use App\Services\InventarioService;
use App\Services\TraspasoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\AlmacenLocal;
use Tests\MySqlTestCase;

/**
 * Devolución de material de una Nota de Entrega (App\Services\DevolucionService): salen BRAGA
 * 45 con una nota y las regresan; el stock vuelve a la bolsa de la que salió y el consumo de
 * esa salida baja. Todo corre en la transacción de la prueba y se revierte al terminar.
 */
class DevolucionMaterialTest extends MySqlTestCase
{
    use AlmacenLocal;

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

    public function test_devolver_mueve_el_stock_deja_el_rastro_y_no_cuenta_como_consumo(): void
    {
        [$idFrente] = $this->frentes(1);
        $alm = $this->almacen();
        $p45 = $this->producto('45');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p45->ID_PRODUCTO, 10);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p45->ID_PRODUCTO => 5], $idFrente);

        $this->devolver($nota, [['id_producto' => $p45->ID_PRODUCTO, 'cantidad' => 5]], ['motivo' => 'Talla equivocada'])
            ->assertCreated()->assertJsonPath('message', 'Devolución registrada (1 producto)');

        // El stock vuelve entero y NO se generó ninguna nota nueva: devolver no entrega nada.
        $this->assertSame(10.0, $this->saldo($alm->ID_ALMACEN, $p45->ID_PRODUCTO));
        $this->assertSame(1, MovimientoInventario::where('NUMERO_NOTA', $nota)->where('TIPO', MovimientoInventario::TIPO_SALIDA)->count());

        // La devolución apunta a la salida y a su nota.
        $salida = MovimientoInventario::where('NUMERO_NOTA', $nota)->firstOrFail();
        $dev    = MovimientoInventario::where('TIPO', MovimientoInventario::TIPO_DEVOLUCION)
            ->where('ID_MOVIMIENTO_RELACIONADO', $salida->ID_MOVIMIENTO)->firstOrFail();
        $this->assertSame($nota, $dev->REFERENCIA);
        $this->assertSame((int) $idFrente, (int) $dev->ID_FRENTE);
        $this->assertSame('Talla equivocada', $dev->MOTIVO);

        // Consumo: lo devuelto deja de contar.
        $dash = $this->actingAs($this->usuario())
            ->getJson(route('almacen.consumoDashboard', ['descripcion' => "BRAGA {$this->marca}"]))
            ->assertOk()->json('top_productos');
        $this->assertSame([], array_column($dash, 'nombre'));
    }

    public function test_no_deja_devolver_mas_de_lo_entregado(): void
    {
        $alm = $this->almacen();
        $p = $this->producto('45');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 10);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p->ID_PRODUCTO => 5], null);

        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 3]])->assertCreated();
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 3]])
            ->assertStatus(422)->assertJsonFragment(['message' => "No puedes devolver más de lo entregado. De «{$p->NOMBRE}» quedan 2 UND por devolver en la Nota {$nota} y se intentó devolver 3."]);

        $this->assertSame(8.0, $this->saldo($alm->ID_ALMACEN, $p->ID_PRODUCTO));

        // El resumen del modal dice lo mismo.
        $linea = $this->actingAs($this->usuario())
            ->getJson(route('almacen.ajuste-nota.show', ['numero' => strtolower($nota), 'id_producto' => $p->ID_PRODUCTO]))
            ->assertOk()->json('linea');
        $this->assertEquals([5, 3, 2], [$linea['entregado'], $linea['devuelto'], $linea['pendiente']]);
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
        $html = $this->movimientosLocal($this->usuario(), ['id_almacen' => $alm->ID_ALMACEN, 'tipo' => 'DEVOLUCION'])['html'];
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

        // Producto que no está en la nota.
        $otro = $this->producto('40');
        $this->devolver($nota, [['id_producto' => $otro->ID_PRODUCTO, 'cantidad' => 1]])->assertStatus(422);
        // Nota inexistente.
        $this->devolver('NE-1999-9999', [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1]])->assertStatus(404);

        $this->assertSame(5.0, $this->saldo($alm->ID_ALMACEN, $p->ID_PRODUCTO), 'Una petición rechazada movió stock.');

        // Sin la clave almacen.movimiento no se registra.
        $sinClave = Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->get()->first(fn ($u) => !$u->can('almacen.movimiento'));
        if ($sinClave) {
            $this->actingAs($sinClave)->postJson(route('almacen.devolucion.store'), [
                'numero' => $nota, 'lineas' => [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1]],
            ])->assertForbidden();
        }
    }

    /** Envío con Nota de Entrega a otro almacén, como lo registra la salida unificada. */
    private function envioConNota(int $origen, int $destino, int $idProducto, float $cantidad): array
    {
        $svc = app(TraspasoService::class);
        $u   = $this->usuario();
        return DB::transaction(function () use ($svc, $u, $origen, $destino, $idProducto, $cantidad) {
            $numero = MovimientoInventario::generarNumeroNota();
            $envio  = $svc->crearBorrador(['id_almacen_origen' => $origen, 'id_almacen_destino' => $destino, 'id_usuario' => $u->ID_USUARIO],
                [['id_producto' => $idProducto, 'cantidad' => $cantidad]]);
            $svc->enviar($envio, ['id_usuario_envio' => $u->ID_USUARIO, 'numero_nota' => $numero]);
            return [$numero, $envio->fresh('lineas')];
        });
    }

    private function recibir($envio, float $cantidad): void
    {
        app(TraspasoService::class)->recibir($envio->fresh(), [['id_linea' => $envio->lineas->first()->ID_LINEA, 'cantidad_recibida' => $cantidad]],
            ['id_usuario_recepcion' => $this->usuario()->ID_USUARIO]);
    }

    private function destino(): Almacen
    {
        return Almacen::create(['NOMBRE' => 'DESTINO ' . $this->marca, 'TIPO' => Almacen::TIPO_GENERAL])->fresh();
    }

    public function test_un_envio_a_otro_almacen_se_devuelve_del_destino_al_origen(): void
    {
        $origen  = $this->almacen();
        $destino = $this->destino();
        $p = $this->producto('45');
        $this->inv->registrarEntrada($origen->ID_ALMACEN, $p->ID_PRODUCTO, 10);
        [$nota, $envio] = $this->envioConNota($origen->ID_ALMACEN, $destino->ID_ALMACEN, $p->ID_PRODUCTO, 5);
        $ver = fn () => $this->actingAs($this->usuario())
            ->getJson(route('almacen.ajuste-nota.show', ['numero' => $nota, 'id_producto' => $p->ID_PRODUCTO]))->assertOk();

        // Sin recibir también se devuelve (el destino puede no usar la app): vuelve al origen
        // y Recepción espera menos.
        $ver()->assertJsonPath('devolucion.motivo_no', null)->assertJsonPath('linea.pendiente', 5)
            ->assertJsonPath('correccion.motivo_no', null);
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 6]])->assertStatus(422)
            ->assertJsonPath('message', "De «{$p->NOMBRE}» solo se pueden devolver 5 UND: es lo que sigue en camino a {$destino->NOMBRE}.");
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1]])->assertCreated();
        $this->assertSame(6.0, $this->saldo($origen->ID_ALMACEN, $p->ID_PRODUCTO));
        $this->assertSame(4.0, (float) $envio->lineas->first()->fresh()->CANTIDAD_ENVIADA);
        $antes = MovimientoInventario::where('TIPO', MovimientoInventario::TIPO_DEVOLUCION)->where('REFERENCIA', $nota)->firstOrFail();
        $this->assertFalse(MovimientoInventario::where('ID_MOVIMIENTO_RELACIONADO', $antes->ID_MOVIMIENTO)->exists(), 'Sin recibir no hay retorno del destino.');

        $this->recibir($envio, 4);
        // Lo devuelto antes de recibir no salió del destino: allá quedan 4 por devolver.
        $ver()->assertJsonPath('devolucion.motivo_no', null)->assertJsonPath('linea.pendiente', 4)
            ->assertJsonPath('correccion.motivo_no', null);

        // Regresan 2: salen del destino y vuelven al origen.
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2]])->assertCreated();
        $this->assertSame(8.0, $this->saldo($origen->ID_ALMACEN, $p->ID_PRODUCTO));
        $this->assertSame(2.0, $this->saldo($destino->ID_ALMACEN, $p->ID_PRODUCTO));
        $dev = MovimientoInventario::where('TIPO', MovimientoInventario::TIPO_DEVOLUCION)->where('REFERENCIA', $nota)
            ->where('ID_MOVIMIENTO', '>', $antes->ID_MOVIMIENTO)->firstOrFail();
        $retorno = MovimientoInventario::where('ID_MOVIMIENTO_RELACIONADO', $dev->ID_MOVIMIENTO)->firstOrFail();
        $this->assertSame(MovimientoInventario::TIPO_TRASPASO_SALIDA, $retorno->TIPO);
        $this->assertSame((int) $destino->ID_ALMACEN, (int) $retorno->ID_ALMACEN);
        $this->assertSame((int) $origen->ID_ALMACEN, (int) $retorno->ID_ALMACEN_CONTRAPARTE);
        $this->assertNull($retorno->NUMERO_NOTA);

        // En pantalla: la nota de envío ofrece Modificar, y el retorno no es consumo del destino.
        $this->assertStringContainsString("almModificarNota('{$nota}', {$p->ID_PRODUCTO})",
            $this->movimientosLocal($this->usuario(), ['id_almacen' => $origen->ID_ALMACEN])['html']);
        $this->assertStringNotContainsString($p->NOMBRE,
            $this->consumoLocal($this->usuario(), ['id_almacen' => $destino->ID_ALMACEN]));
        $this->assertStringContainsString($p->NOMBRE,
            $this->consumoLocal($this->usuario(), ['id_almacen' => $origen->ID_ALMACEN]));

        // No más de lo que queda en el destino por devolver.
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 3]])->assertStatus(422)
            ->assertJsonPath('message', "De «{$p->NOMBRE}» solo se pueden devolver 2 UND: es lo que recibió {$destino->NOMBRE} y no se ha devuelto.");

        // Si el destino ya lo gastó, no puede regresarlo.
        $this->inv->registrarSalida($destino->ID_ALMACEN, $p->ID_PRODUCTO, 2);
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1]])->assertStatus(422);
        $this->assertSame(8.0, $this->saldo($origen->ID_ALMACEN, $p->ID_PRODUCTO));

        // La devolución de antes de recibir ya no se deshace: el destino recibió sin eso.
        try {
            app(TraspasoService::class)->prepararDeshacer($antes->fresh());
            $this->fail('Deshacer una devolución de antes de recibir, con el envío ya recibido, debía rechazarse.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ya lo recibió', $e->getMessage());
        }

        // Deshacer la devolución se lleva las dos patas.
        $this->assertSame(2, $this->inv->eliminarMovimientoConReverso($dev->ID_MOVIMIENTO)['eliminados']);
        $this->assertSame(6.0, $this->saldo($origen->ID_ALMACEN, $p->ID_PRODUCTO));
        $this->assertSame(2.0, $this->saldo($destino->ID_ALMACEN, $p->ID_PRODUCTO));
    }

    public function test_devolver_un_envio_sin_recibir_cuadra_con_la_correccion_la_cancelacion_y_el_deshacer(): void
    {
        $origen  = $this->almacen();
        $destino = $this->destino();
        $p = $this->producto('44');
        $this->inv->registrarEntrada($origen->ID_ALMACEN, $p->ID_PRODUCTO, 10);
        [$nota, $envio] = $this->envioConNota($origen->ID_ALMACEN, $destino->ID_ALMACEN, $p->ID_PRODUCTO, 5);
        $linea = fn () => $envio->lineas->first()->fresh();
        $tras  = app(TraspasoService::class);

        // Vuelve 1, y deshacerla lo repone en lo que Recepción espera.
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1]])->assertCreated();
        $dev = MovimientoInventario::where('TIPO', MovimientoInventario::TIPO_DEVOLUCION)->where('REFERENCIA', $nota)->firstOrFail();
        DB::transaction(function () use ($tras, $dev) {
            $prep = $tras->prepararDeshacer($dev);
            $this->inv->eliminarMovimientoConReverso($dev->ID_MOVIMIENTO, $prep['arrastrados']);
            $tras->reponerLineas($prep['reponer']);
        });
        $this->assertSame(5.0, (float) $linea()->CANTIDAD_ENVIADA);
        $this->assertSame(5.0, $this->saldo($origen->ID_ALMACEN, $p->ID_PRODUCTO));

        // Vuelve 1 otra vez y se corrige la nota a 4: en camino quedan 3 (4 − 1 devuelto).
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1]])->assertCreated();
        app(CorreccionNotaService::class)->corregir($nota, $p->ID_PRODUCTO, 4, ['motivo' => 'prueba', 'id_usuario' => $this->usuario()->ID_USUARIO]);
        $this->assertSame(3.0, (float) $linea()->CANTIDAD_ENVIADA);
        $this->assertSame(7.0, $this->saldo($origen->ID_ALMACEN, $p->ID_PRODUCTO));

        // Cancelar devuelve solo lo que seguía en camino: el origen queda entero.
        $tras->cancelar($envio->fresh(), ['id_usuario' => $this->usuario()->ID_USUARIO]);
        $this->assertSame(10.0, $this->saldo($origen->ID_ALMACEN, $p->ID_PRODUCTO));
        $this->assertSame(0.0, $this->saldo($destino->ID_ALMACEN, $p->ID_PRODUCTO));
    }

    public function test_deshacer_el_envio_se_lleva_sus_devoluciones_y_el_retorno(): void
    {
        $origen  = $this->almacen();
        $destino = $this->destino();
        $p = $this->producto('45');
        $this->inv->registrarEntrada($origen->ID_ALMACEN, $p->ID_PRODUCTO, 10);
        [$nota, $envio] = $this->envioConNota($origen->ID_ALMACEN, $destino->ID_ALMACEN, $p->ID_PRODUCTO, 5);
        $this->recibir($envio, 5);
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2]])->assertCreated();

        // El retorno solo: se va con su devolución, y el envío queda intacto.
        $retorno = MovimientoInventario::where('ID_ALMACEN', $destino->ID_ALMACEN)
            ->where('TIPO', MovimientoInventario::TIPO_TRASPASO_SALIDA)->firstOrFail();
        $this->assertSame(2, $this->inv->eliminarMovimientoConReverso($retorno->ID_MOVIMIENTO)['eliminados']);
        $this->assertSame(5.0, $this->saldo($origen->ID_ALMACEN, $p->ID_PRODUCTO));
        $this->assertSame(5.0, $this->saldo($destino->ID_ALMACEN, $p->ID_PRODUCTO));

        // El envío entero: salida, entrada, devolución y retorno.
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2]])->assertCreated();
        $salida = MovimientoInventario::where('NUMERO_NOTA', $nota)->firstOrFail();
        $this->assertSame(4, $this->inv->eliminarMovimientoConReverso($salida->ID_MOVIMIENTO)['eliminados']);
        $this->assertSame(10.0, $this->saldo($origen->ID_ALMACEN, $p->ID_PRODUCTO));
        $this->assertSame(0.0, $this->saldo($destino->ID_ALMACEN, $p->ID_PRODUCTO));
    }

    public function test_deshacer_un_tramo_del_envio_se_lleva_las_devoluciones_de_todos(): void
    {
        // Almacén por proyecto: el envío sale en DOS tramos (bolsa B y bolsa A).
        [$fA, $fB] = $this->frentes(2);
        $origen  = $this->almacen(Almacen::TIPO_PROYECTO, [$fA, $fB]);
        $destino = $this->destino();
        $p = $this->producto('45');
        $this->inv->registrarEntrada($origen->ID_ALMACEN, $p->ID_PRODUCTO, 3, ['id_frente' => $fA]);
        $this->inv->registrarEntrada($origen->ID_ALMACEN, $p->ID_PRODUCTO, 4, ['id_frente' => $fB]);
        [$nota, $envio] = $this->envioConNota($origen->ID_ALMACEN, $destino->ID_ALMACEN, $p->ID_PRODUCTO, 5);
        $tramos = MovimientoInventario::where('NUMERO_NOTA', $nota)->orderBy('ID_MOVIMIENTO')->get();
        $this->assertCount(2, $tramos);
        $this->recibir($envio, 5);

        // Vuelve todo: una devolución (y su retorno) por tramo.
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 5]])->assertCreated();
        $this->assertSame(2, MovimientoInventario::where('TIPO', MovimientoInventario::TIPO_DEVOLUCION)->where('REFERENCIA', $nota)->count());
        $this->assertSame(7.0, $this->saldo($origen->ID_ALMACEN, $p->ID_PRODUCTO));
        $this->assertSame(0.0, $this->saldo($destino->ID_ALMACEN, $p->ID_PRODUCTO));

        // El aviso dice lo mismo que va a borrar.
        $admin = Usuario::all()->first(fn ($u) => $u->can('super.admin') && Almacen::usuarioEsGlobal($u));
        $this->assertNotNull($admin, 'Hace falta un super.admin GLOBAL para el aviso de deshacer.');
        $this->actingAs($admin)->getJson(route('almacen.movimientos.impactoDeshacer', ['id' => $tramos[1]->ID_MOVIMIENTO]))
            ->assertOk()->assertJsonFragment(['También se deshacen sus 2 devoluciones.']);

        // Deshacer el tramo que NO lleva el enlace con la entrada: se va el envío entero, con
        // las devoluciones y los retornos de los dos tramos. Nada queda suelto.
        $r = $this->inv->eliminarMovimientoConReverso($tramos[1]->ID_MOVIMIENTO,
            app(TraspasoService::class)->prepararDeshacer($tramos[1])['arrastrados']);
        $this->assertSame(7, $r['eliminados']);   // 2 tramos + entrada + 2 devoluciones + 2 retornos
        $this->assertSame(0, MovimientoInventario::where('ID_PRODUCTO', $p->ID_PRODUCTO)->whereIn('TIPO', [
            MovimientoInventario::TIPO_DEVOLUCION, MovimientoInventario::TIPO_TRASPASO_SALIDA, MovimientoInventario::TIPO_TRASPASO_ENTRADA])->count());
        $this->assertSame(3.0, $this->saldo($origen->ID_ALMACEN, $p->ID_PRODUCTO, $fA));
        $this->assertSame(4.0, $this->saldo($origen->ID_ALMACEN, $p->ID_PRODUCTO, $fB));
        $this->assertSame(0.0, $this->saldo($destino->ID_ALMACEN, $p->ID_PRODUCTO));
    }

    public function test_el_aviso_de_deshacer_la_devolucion_de_un_envio_nombra_el_otro_almacen(): void
    {
        $origen  = $this->almacen();
        $destino = $this->destino();
        $p = $this->producto('45');
        $this->inv->registrarEntrada($origen->ID_ALMACEN, $p->ID_PRODUCTO, 10);
        [$nota, $envio] = $this->envioConNota($origen->ID_ALMACEN, $destino->ID_ALMACEN, $p->ID_PRODUCTO, 5);
        $this->recibir($envio, 5);
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2]])->assertCreated();
        $dev = MovimientoInventario::where('TIPO', MovimientoInventario::TIPO_DEVOLUCION)->where('REFERENCIA', $nota)->firstOrFail();
        $retorno = MovimientoInventario::where('ID_MOVIMIENTO_RELACIONADO', $dev->ID_MOVIMIENTO)->firstOrFail();

        $admin = Usuario::all()->first(fn ($u) => $u->can('super.admin') && Almacen::usuarioEsGlobal($u));
        $this->assertNotNull($admin, 'Hace falta un super.admin GLOBAL para el aviso de deshacer.');
        $avisos = fn ($id) => $this->actingAs($admin)->getJson(route('almacen.movimientos.impactoDeshacer', ['id' => $id]))->assertOk()->json('avisos');

        $this->assertContains("Es la devolución de un envío: el material vuelve a contarse en {$destino->NOMBRE}.", $avisos($dev->ID_MOVIMIENTO));
        $deRetorno = $avisos($retorno->ID_MOVIMIENTO);
        $this->assertContains("Es el retorno de una devolución: también se deshace la devolución en {$origen->NOMBRE}.", $deRetorno);
        $this->assertNotContains('También se deshace su devolución.', $deRetorno);
    }

    public function test_un_envio_con_el_producto_en_dos_lineas_no_se_devuelve_ni_se_corrige(): void
    {
        $origen  = $this->almacen();
        $destino = $this->destino();
        $p = $this->producto('45');
        $this->inv->registrarEntrada($origen->ID_ALMACEN, $p->ID_PRODUCTO, 10);
        $svc = app(TraspasoService::class);
        $u   = $this->usuario();
        [$nota, $envio] = DB::transaction(function () use ($svc, $u, $origen, $destino, $p) {
            $numero = MovimientoInventario::generarNumeroNota();
            $envio  = $svc->crearBorrador(['id_almacen_origen' => $origen->ID_ALMACEN, 'id_almacen_destino' => $destino->ID_ALMACEN, 'id_usuario' => $u->ID_USUARIO],
                [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2], ['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 3]]);
            $svc->enviar($envio, ['id_usuario_envio' => $u->ID_USUARIO, 'numero_nota' => $numero]);
            return [$numero, $envio->fresh('lineas')];
        });
        $svc->recibir($envio->fresh(), $envio->lineas->map(fn ($l) => ['id_linea' => $l->ID_LINEA, 'cantidad_recibida' => (float) $l->CANTIDAD_ENVIADA])->all(),
            ['id_usuario_recepcion' => $u->ID_USUARIO]);

        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1]])->assertStatus(422)
            ->assertJsonPath('message', '«' . $p->NOMBRE . '»: ' . DevolucionService::REPETIDO);
        $this->assertSame(DevolucionService::REPETIDO,
            app(CorreccionNotaService::class)->motivoNoCorregible(
                MovimientoInventario::where('NUMERO_NOTA', $nota)->get(),
                MovimientoInventario::where('NUMERO_NOTA', $nota)->get()));
        $this->assertSame(5.0, $this->saldo($origen->ID_ALMACEN, $p->ID_PRODUCTO));
        $this->assertSame(5.0, $this->saldo($destino->ID_ALMACEN, $p->ID_PRODUCTO));
    }

    public function test_desde_un_movimiento_se_devuelve_solo_ese_producto(): void
    {
        $alm = $this->almacen();
        $p45 = $this->producto('45');
        $p42 = $this->producto('42');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p45->ID_PRODUCTO, 10);
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p42->ID_PRODUCTO, 10);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p45->ID_PRODUCTO => 5, $p42->ID_PRODUCTO => 3], null);
        $this->devolver($nota, [['id_producto' => $p42->ID_PRODUCTO, 'cantidad' => 1]])->assertCreated();

        $ver = fn (array $q) => $this->actingAs($this->usuario())->getJson(route('almacen.ajuste-nota.show', ['numero' => $nota] + $q));

        // Solo el producto del movimiento, con SUS devoluciones (la del 42 no sale en el 45).
        $r = $ver(['id_producto' => $p45->ID_PRODUCTO])->assertOk();
        $this->assertSame($p45->ID_PRODUCTO, $r->json('linea.id_producto'));
        $this->assertSame([], $r->json('devolucion.historial'));
        $this->assertCount(1, $ver(['id_producto' => $p42->ID_PRODUCTO])->json('devolucion.historial'));

        // El modal es de UN producto: sin él no hay nada que mostrar; con uno que no está, se dice.
        $ver([])->assertStatus(422);
        $otro = $this->producto('40');
        $ver(['id_producto' => $otro->ID_PRODUCTO])->assertNotFound()
            ->assertJsonPath('message', "Ese producto no está en la Nota {$nota}.");
    }

    public function test_el_historial_ofrece_modificar_mientras_haya_algo_que_hacer(): void
    {
        $alm = $this->almacen();
        $p = $this->producto('45');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 10);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p->ID_PRODUCTO => 5], null);
        $boton = "almModificarNota('{$nota}', {$p->ID_PRODUCTO})";
        $bitacora = fn () => $this->movimientosLocal($this->usuario(), ['id_almacen' => $alm->ID_ALMACEN, 'tipo' => 'SALIDA'])['html'];

        $this->assertStringContainsString($boton, $bitacora(), 'La salida con nota tiene que ofrecer Modificar.');

        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2]])->assertCreated();
        $this->assertStringContainsString($boton, $bitacora(), 'Quedan 3 por devolver: sigue el botón.');

        // Ya volvió todo: no queda nada que devolver, así que el botón solo sigue para quien
        // todavía puede CORREGIR la nota.
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 3]])->assertCreated();
        if ($this->usuario()->can('almacen.nota.corregir')) {
            $this->assertStringContainsString($boton, $bitacora(), 'Ya volvió todo, pero se puede corregir.');
        } else {
            $this->assertStringNotContainsString($boton, $bitacora(), 'Ya volvió todo y no corrige: sin botón.');
        }
    }

    public function test_la_nota_y_la_bitacora_dicen_lo_que_se_devolvio(): void
    {
        // El papel firmado NO cambia: la nota lleva las mismas lineas. Lo devuelto se añade
        // al pie ("DEVOLUCIONES REGISTRADAS") y en la bitacora la salida lo dice en su celda.
        $alm = $this->almacen();
        $p = $this->producto('47');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 10);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p->ID_PRODUCTO => 5], null);

        // Antes de devolver nada, la nota sale limpia. (Se mira el HTML que se manda a TCPDF:
        // generar el PDF de verdad son varios segundos por llamada y no añade nada.)
        $this->assertStringNotContainsString('DEVOLUCIONES', $this->textoVista($nota), 'Sin devoluciones, nada al pie.');

        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2]], ['motivo' => 'SOBRO EN LA OBRA'])->assertCreated();

        $texto = $this->textoVista($nota);
        $this->assertStringContainsString('DEVOLUCIONES REGISTRADAS', $texto);
        $this->assertStringContainsString('SOBRO EN LA OBRA', $texto);
        $this->assertMatchesRegularExpression('/>\s*2\s*</', $texto, 'Y con la cantidad devuelta.');

        // Y en la bitácora la salida lleva la marca «Devuelto» (sin número: la cantidad va en
        // su title, pedido del cliente 23-11-2026).
        $bitacora = $this->movimientosLocal($this->usuario(), ['id_almacen' => $alm->ID_ALMACEN, 'tipo' => 'SALIDA'])['html'];
        $this->assertStringContainsString('</i>Devuelto</button>', $bitacora);
        $this->assertStringContainsString('ya volvieron 2 UND', $bitacora);
    }

    /** El HTML de la nota (lo que se manda a TCPDF), para mirar su contenido sin leer el PDF. */
    private function textoVista(string $numero): string
    {
        $movs = MovimientoInventario::with('producto')->where('NUMERO_NOTA', $numero)
            ->where('TIPO', MovimientoInventario::TIPO_SALIDA)->get();
        $devoluciones = MovimientoInventario::with('producto')
            ->where('TIPO', MovimientoInventario::TIPO_DEVOLUCION)
            ->whereIn('ID_MOVIMIENTO_RELACIONADO', $movs->pluck('ID_MOVIMIENTO'))->get();

        return view('admin.almacen.partials.nota_devoluciones', [
            'devoluciones' => $devoluciones,
            'fmt' => fn ($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',') ?: '0',
        ])->render();
    }

    public function test_la_bitacora_pinta_la_devolucion_enlazada_a_su_nota(): void
    {
        $alm = $this->almacen();
        $p = $this->producto('45');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 10);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p->ID_PRODUCTO => 5], null);
        $this->devolver($nota, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2]])->assertCreated();

        $html = $this->movimientosLocal($this->usuario(), ['id_almacen' => $alm->ID_ALMACEN, 'tipo' => 'DEVOLUCION'])['html'];

        $this->assertStringContainsString('Devolución', $html);
        $this->assertStringContainsString('+2', $html, 'La devolución tiene que sumar.');
        $this->assertStringContainsString('/admin/almacen/nota-entrega?numero=' . rawurlencode($nota), $html, 'Tiene que enlazar a la nota de la que vuelve.');

        // El kardex de "Movimientos del producto": la devolución entra en Entradas y enlaza a su nota.
        $mini = $this->movimientosLocal($this->usuario(), ['id_almacen' => $alm->ID_ALMACEN, 'id_producto' => $p->ID_PRODUCTO, 'tipo' => 'ENTRADAS'], 1, true)['html'];
        $this->assertStringContainsString('Devolución', $mini);
        $this->assertStringContainsString('/admin/almacen/nota-entrega?numero=' . rawurlencode($nota), $mini);
    }
}
