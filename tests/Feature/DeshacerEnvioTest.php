<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Traspaso;
use App\Models\TraspasoLinea;
use App\Models\Usuario;
use App\Services\InventarioService;
use App\Services\TraspasoService;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Deshacer (super.admin, /admin/almacen/movimientos) un ítem de un ENVÍO a otro almacén deja
 * coherentes el stock Y el envío en Recepción (TraspasoService::prepararDeshacer / quitarLineasDeshechas).
 * Antes solo tocaba el kardex: el ítem seguía en el envío y se recibía en el destino, o volvía
 * al origen al cancelar, sobre stock que ya había vuelto → material duplicado.
 *
 * Los casos "por proyecto" salen de un almacén que reparte el saldo: la salida del ítem se
 * parte en un tramo por bolsa y se pulsa el tramo que la línea NO guarda.
 */
class DeshacerEnvioTest extends MySqlTestCase
{
    private Usuario $admin;
    private int $origen;
    private int $destino;
    private int $prodA;
    private int $prodB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        $this->assertNotNull($this->admin, 'No hay ningún super.admin para probar.');

        // Dos almacenes GENERAL propios del test (la transacción los borra al terminar): no
        // reparten el saldo por proyecto, así el stock de cada producto es un solo número, y
        // arrancan vacíos, así las cuentas no dependen de lo que haya en la base.
        $nuevo = fn (string $n) => (int) Almacen::forceCreate([
            'NOMBRE' => "PRUEBA DESHACER ENVIO {$n} " . uniqid(), 'TIPO' => 'GENERAL', 'ESTATUS' => 'ACTIVO',
        ])->ID_ALMACEN;
        [$this->origen, $this->destino] = [$nuevo('ORIGEN'), $nuevo('DESTINO')];

        $productos = ProductoInventario::where('ESTATUS', 'ACTIVO')->limit(2)->pluck('ID_PRODUCTO');
        $this->assertCount(2, $productos, 'Hacen falta dos productos activos.');
        [$this->prodA, $this->prodB] = [(int) $productos[0], (int) $productos[1]];
    }

    private function traspasos(): TraspasoService
    {
        return app(TraspasoService::class);
    }

    /**
     * Almacén que REPARTE el saldo por proyecto (PROYECTO con dos frentes), con 3 und de A
     * en un proyecto y 2 en el otro: un envío de 5 sale partido en dos TRASPASO_SALIDA, uno
     * por bolsa, y la línea solo apunta al primero.
     *
     * @return array{0:int, 1:int, 2:int}  [almacén, frente con 3, frente con 2]
     */
    private function origenPorProyecto(): array
    {
        $id = (int) Almacen::forceCreate([
            'NOMBRE' => 'PRUEBA DESHACER POR PROYECTO ' . uniqid(), 'TIPO' => Almacen::TIPO_PROYECTO, 'ESTATUS' => 'ACTIVO',
        ])->ID_ALMACEN;
        [$f1, $f2] = DB::table('frentes_trabajo')->orderBy('ID_FRENTE')->limit(2)->pluck('ID_FRENTE')->map(fn ($v) => (int) $v)->all();
        DB::table('almacen_frentes')->insert([['ID_ALMACEN' => $id, 'ID_FRENTE' => $f1], ['ID_ALMACEN' => $id, 'ID_FRENTE' => $f2]]);
        $this->assertTrue(Almacen::find($id)->separaPorProyecto());

        app(InventarioService::class)->registrarEntrada($id, $this->prodA, 3, ['id_frente' => $f1]);
        app(InventarioService::class)->registrarEntrada($id, $this->prodA, 2, ['id_frente' => $f2]);

        return [$id, $f1, $f2];
    }

    /** Tramos de salida de A en el envío; el que NO es el que guarda la línea va segundo. */
    private function tramosDeA(Traspaso $t): array
    {
        $enLinea = (int) $this->linea($t, $this->prodA)->ID_MOVIMIENTO_SALIDA;
        $tramos  = MovimientoInventario::where('ID_TRASPASO', $t->ID_TRASPASO)->where('ID_PRODUCTO', $this->prodA)
            ->where('TIPO', MovimientoInventario::TIPO_TRASPASO_SALIDA)->pluck('ID_MOVIMIENTO')->map(fn ($v) => (int) $v)->all();
        $this->assertCount(2, $tramos, 'La salida de 5 tenía que partirse en dos bolsas.');

        return [$enLinea, current(array_diff($tramos, [$enLinea]))];
    }

    private function stockBolsa(int $almacen, int $producto, int $frente): float
    {
        return round((float) AlmacenStock::where('ID_ALMACEN', $almacen)->where('ID_PRODUCTO', $producto)->where('ID_FRENTE', $frente)->value('CANTIDAD'), 3);
    }

    private function sinMovimientosDeA(Traspaso $t): void
    {
        $this->assertSame(0, MovimientoInventario::where('ID_TRASPASO', $t->ID_TRASPASO)->where('ID_PRODUCTO', $this->prodA)->count(),
            'Quedaron filas del ítem deshecho (tramos, entrada o retorno).');
    }

    /** Envío ENVIADO con 3 und de A (5 si sale de un almacén por proyecto) y 2 de B si $conB. */
    private function enviar(bool $conB = true, ?int $origen = null): Traspaso
    {
        $lineas = [['id_producto' => $this->prodA, 'cantidad' => $origen ? 5 : 3]];
        if ($conB) {
            $lineas[] = ['id_producto' => $this->prodB, 'cantidad' => 2];
        }
        $t = $this->traspasos()->crearBorrador([
            'id_almacen_origen'  => $origen ?? $this->origen,
            'id_almacen_destino' => $this->destino,
            'id_usuario'         => $this->admin->ID_USUARIO,
        ], $lineas);

        return $this->traspasos()->enviar($t, [
            'id_usuario_envio'  => $this->admin->ID_USUARIO,
            'permitir_negativo' => true,               // el test no depende del stock que haya
            'numero_nota'       => 'NE-TEST-' . $t->ID_TRASPASO,
        ]);
    }

    private function linea(Traspaso $t, int $producto): TraspasoLinea
    {
        return $t->lineas()->where('ID_PRODUCTO', $producto)->firstOrFail();
    }

    private function recibir(Traspaso $t, array $productos): Traspaso
    {
        $lineas = array_map(fn ($p) => [
            'id_linea'          => $this->linea($t, $p)->ID_LINEA,
            'cantidad_recibida' => (float) $this->linea($t, $p)->CANTIDAD_ENVIADA,
        ], $productos);

        return $this->traspasos()->recibir($t, $lineas, ['id_usuario_recepcion' => $this->admin->ID_USUARIO]);
    }

    private function stock(int $almacen, int $producto): float
    {
        return round((float) AlmacenStock::where('ID_ALMACEN', $almacen)->where('ID_PRODUCTO', $producto)->sum('CANTIDAD'), 3);
    }

    private function deshacer(int $idMovimiento): void
    {
        $this->actingAs($this->admin)
            ->deleteJson(route('almacen.movimientos.destroy', ['id' => $idMovimiento]))
            ->assertOk();
    }

    public function test_sin_recibir_el_item_sale_del_envio_y_no_se_recibe_despues(): void
    {
        $antesOrigenA  = $this->stock($this->origen, $this->prodA);
        $antesDestinoA = $this->stock($this->destino, $this->prodA);
        $antesDestinoB = $this->stock($this->destino, $this->prodB);
        $t = $this->enviar();

        $this->deshacer((int) $this->linea($t, $this->prodA)->ID_MOVIMIENTO_SALIDA);

        $t = Traspaso::find($t->ID_TRASPASO);
        $this->assertSame(Traspaso::ESTADO_ENVIADO, $t->ESTADO);
        $this->assertSame([$this->prodB], $t->lineas()->pluck('ID_PRODUCTO')->map(fn ($v) => (int) $v)->all(), 'El ítem deshecho sigue en el envío.');
        $this->assertSame($antesOrigenA, $this->stock($this->origen, $this->prodA), 'El ítem no volvió al origen.');

        // Recibir lo que queda: solo entra B; A no aparece en el destino.
        $t = $this->recibir($t, [$this->prodB]);
        $this->assertSame(Traspaso::ESTADO_RECIBIDO, $t->ESTADO);
        $this->assertSame($antesDestinoA, $this->stock($this->destino, $this->prodA), 'El ítem deshecho entró igual al destino (stock duplicado).');
        $this->assertSame(round($antesDestinoB + 2, 3), $this->stock($this->destino, $this->prodB));
    }

    public function test_si_era_su_unico_item_el_envio_desaparece(): void
    {
        $antesOrigenA = $this->stock($this->origen, $this->prodA);
        $t = $this->enviar(conB: false);

        $this->deshacer((int) $this->linea($t, $this->prodA)->ID_MOVIMIENTO_SALIDA);

        $this->assertNull(Traspaso::withTrashed()->find($t->ID_TRASPASO), 'El envío vacío siguió en Recepción.');
        $this->assertSame($antesOrigenA, $this->stock($this->origen, $this->prodA));
    }

    public function test_ya_recibido_se_descuenta_tambien_del_destino(): void
    {
        $antesOrigenA  = $this->stock($this->origen, $this->prodA);
        $antesDestinoA = $this->stock($this->destino, $this->prodA);
        $t = $this->recibir($this->enviar(), [$this->prodA, $this->prodB]);

        // Se pulsa sobre la ENTRADA (en el destino): se van las dos patas.
        $this->deshacer((int) $this->linea($t, $this->prodA)->ID_MOVIMIENTO_ENTRADA);

        $t = Traspaso::find($t->ID_TRASPASO);
        $this->assertSame(Traspaso::ESTADO_RECIBIDO, $t->ESTADO);
        $this->assertSame(1, $t->lineas()->count());
        $this->assertSame($antesOrigenA, $this->stock($this->origen, $this->prodA));
        $this->assertSame($antesDestinoA, $this->stock($this->destino, $this->prodA));
    }

    public function test_recibido_parcial_queda_completo_si_lo_pendiente_era_lo_deshecho(): void
    {
        $antesOrigenB = $this->stock($this->origen, $this->prodB);
        $t = $this->recibir($this->enviar(), [$this->prodA]);
        $this->assertSame(Traspaso::ESTADO_RECIBIDO_PARCIAL, $t->ESTADO);

        $this->deshacer((int) $this->linea($t, $this->prodB)->ID_MOVIMIENTO_SALIDA);

        $t = Traspaso::find($t->ID_TRASPASO);
        $this->assertSame(Traspaso::ESTADO_RECIBIDO, $t->ESTADO, 'Quedó esperando un ítem que ya no existe.');
        $this->assertSame($antesOrigenB, $this->stock($this->origen, $this->prodB));
    }

    public function test_cancelado_no_devuelve_el_material_dos_veces(): void
    {
        $antesOrigenA = $this->stock($this->origen, $this->prodA);
        $t = $this->traspasos()->cancelar($this->enviar(), ['id_usuario' => $this->admin->ID_USUARIO]);
        $this->assertSame($antesOrigenA, $this->stock($this->origen, $this->prodA), 'La cancelación no devolvió el material.');

        $this->deshacer((int) $this->linea($t, $this->prodA)->ID_MOVIMIENTO_SALIDA);

        $this->assertSame($antesOrigenA, $this->stock($this->origen, $this->prodA), 'El origen quedó con el material de más.');
        $this->assertSame(0, MovimientoInventario::where('ID_TRASPASO', $t->ID_TRASPASO)
            ->where('ID_PRODUCTO', $this->prodA)->count(), 'Quedó el retorno de la cancelación sin su salida.');
        $this->assertSame(Traspaso::ESTADO_CANCELADO, Traspaso::find($t->ID_TRASPASO)->ESTADO);
    }

    public function test_por_proyecto_sin_recibir_un_tramo_se_lleva_el_item_entero(): void
    {
        [$origen, $f1, $f2] = $this->origenPorProyecto();
        $t = $this->enviar(origen: $origen);
        [, $segundo] = $this->tramosDeA($t);

        $this->deshacer($segundo);   // el tramo que la línea NO conoce

        $this->sinMovimientosDeA($t);
        $this->assertSame([$this->prodB], Traspaso::find($t->ID_TRASPASO)->lineas()->pluck('ID_PRODUCTO')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(3.0, $this->stockBolsa($origen, $this->prodA, $f1));
        $this->assertSame(2.0, $this->stockBolsa($origen, $this->prodA, $f2));
    }

    public function test_por_proyecto_cancelado_el_origen_no_queda_corto(): void
    {
        [$origen] = $this->origenPorProyecto();
        $t = $this->traspasos()->cancelar($this->enviar(origen: $origen), ['id_usuario' => $this->admin->ID_USUARIO]);
        $this->assertSame(5.0, $this->stock($origen, $this->prodA));
        [, $segundo] = $this->tramosDeA($t);

        $this->deshacer($segundo);

        $this->assertSame(5.0, $this->stock($origen, $this->prodA), 'El origen quedó corto (se borró el retorno entero por un tramo).');
        $this->sinMovimientosDeA($t);
    }

    public function test_por_proyecto_recibido_vuelve_todo_a_su_bolsa(): void
    {
        [$origen, $f1, $f2] = $this->origenPorProyecto();
        $antesDestinoA = $this->stock($this->destino, $this->prodA);
        $t = $this->recibir($this->enviar(origen: $origen), [$this->prodA, $this->prodB]);
        [, $segundo] = $this->tramosDeA($t);

        $this->deshacer($segundo);

        $this->sinMovimientosDeA($t);
        $this->assertSame(3.0, $this->stockBolsa($origen, $this->prodA, $f1));
        $this->assertSame(2.0, $this->stockBolsa($origen, $this->prodA, $f2));
        $this->assertSame($antesDestinoA, $this->stock($this->destino, $this->prodA));
        $this->assertSame(Traspaso::ESTADO_RECIBIDO, Traspaso::find($t->ID_TRASPASO)->ESTADO);
    }

    public function test_el_aviso_previo_cuenta_lo_que_va_a_pasar(): void
    {
        $t = $this->enviar();

        $avisos = $this->actingAs($this->admin)
            ->getJson(route('almacen.movimientos.impactoDeshacer', ['id' => $this->linea($t, $this->prodA)->ID_MOVIMIENTO_SALIDA]))
            ->assertOk()
            ->json('avisos');

        $texto = implode(' ', $avisos);
        $this->assertStringContainsString('1 de los 2 ítems de la Nota NE-TEST-' . $t->ID_TRASPASO, $texto);
        $this->assertStringContainsString("envío {$t->NUMERO}", $texto);
        $this->assertStringContainsString('todavía sin recibir', $texto);
        // Mirar no cambia nada.
        $this->assertSame(2, $t->lineas()->count());
    }
}
