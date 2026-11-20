<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Usuario;
use App\Services\TraspasoService;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Cancelar un envío devuelve el material A LA MISMA BOLSA de la que salió.
 *
 * El fallo que cubre: la cancelación llamaba a registrarEntrada sin `_frente_saldo`,
 * así que InventarioService::frenteDelSaldo caía en la bolsa COMÚN. En un almacén que
 * separa por proyecto, el proyecto que despachó quedaba corto para siempre y la común
 * inflada. El total del almacén cuadraba y el desglose por proyecto no — la peor forma
 * de estar mal, porque no salta a la vista.
 *
 * NO escribe nada permanente: MySqlTestCase envuelve el test en una transacción y la
 * revierte al terminar (DatabaseTransactions, nunca RefreshDatabase).
 */
class TraspasoCancelacionBolsaTest extends MySqlTestCase
{
    public function test_cancelar_devuelve_el_material_a_la_bolsa_de_la_que_salio(): void
    {
        $origen = Almacen::with('frentes:ID_FRENTE')->get()
            ->first(fn ($a) => $a->separaPorProyecto());

        if (!$origen) {
            $this->markTestSkipped('No hay ningún almacén que separe por proyecto.');
        }

        $destino = Almacen::where('ID_ALMACEN', '!=', $origen->ID_ALMACEN)
            ->where('ESTATUS', 'ACTIVO')->first();
        if (!$destino) {
            $this->markTestSkipped('Hace falta un segundo almacén para enviar.');
        }

        // Un producto con saldo en una bolsa de PROYECTO (no la común) del origen.
        $saldo = DB::table('almacen_stock')
            ->where('ID_ALMACEN', $origen->ID_ALMACEN)
            ->where('ID_FRENTE', '>', 0)
            ->where('CANTIDAD', '>', 1)
            ->first();
        if (!$saldo) {
            $this->markTestSkipped("El almacén {$origen->NOMBRE} no tiene saldo en ninguna bolsa de proyecto.");
        }

        $idProducto = (int) $saldo->ID_PRODUCTO;
        $bolsa      = (int) $saldo->ID_FRENTE;
        $cantidad   = 1.0;

        $leer = fn (int $frente) => (float) (DB::table('almacen_stock')
            ->where('ID_ALMACEN', $origen->ID_ALMACEN)
            ->where('ID_PRODUCTO', $idProducto)
            ->where('ID_FRENTE', $frente)
            ->value('CANTIDAD') ?? 0);

        $antesBolsa = $leer($bolsa);
        $antesComun = $leer(0);

        /** @var TraspasoService $svc */
        $svc = app(TraspasoService::class);

        $usuario = Usuario::where('ESTATUS', 'ACTIVO')->first();
        $this->assertNotNull($usuario, 'Hace falta un usuario activo para firmar el envio.');

        $traspaso = $svc->crearBorrador([
            'id_almacen_origen'  => $origen->ID_ALMACEN,
            'id_almacen_destino' => $destino->ID_ALMACEN,
            'id_usuario'         => $usuario->ID_USUARIO,
            'motivo'             => 'Prueba automatica: cancelacion devuelve a su bolsa',
        ], [
            ['id_producto' => $idProducto, 'cantidad' => $cantidad],
        ]);

        // bolsa_por_producto es lo que elige el modal de proyecto de cada fila: de QUE
        // bolsa del origen sale el material.
        $svc->enviar($traspaso, [
            'id_usuario'         => $usuario->ID_USUARIO,
            'bolsa_por_producto' => [$idProducto => $bolsa],
        ]);

        $this->assertEqualsWithDelta(
            $antesBolsa - $cantidad, $leer($bolsa), 0.001,
            'El envío debe descontar de la bolsa del proyecto elegida.'
        );

        $svc->cancelar($traspaso->fresh(), ['id_usuario' => $usuario->ID_USUARIO]);

        // Lo que importa: vuelve a SU bolsa, y la común no se mueve.
        $this->assertEqualsWithDelta(
            $antesBolsa, $leer($bolsa), 0.001,
            'Cancelar debe devolver el material a la bolsa del proyecto que despachó.'
        );
        $this->assertEqualsWithDelta(
            $antesComun, $leer(0), 0.001,
            'La bolsa común no debe engordar con un retorno que no le pertenece.'
        );

        // Y el retorno queda con su frente destino, para que el kardex no muestre "—".
        $retorno = MovimientoInventario::where('ID_TRASPASO', $traspaso->ID_TRASPASO)
            ->where('TIPO', MovimientoInventario::TIPO_ENTRADA)
            ->latest('ID_MOVIMIENTO')->first();
        $this->assertNotNull($retorno, 'La cancelación debe dejar su movimiento de retorno.');
        $this->assertSame($bolsa, (int) $retorno->ID_FRENTE_SALDO,
            'El retorno debe registrar la bolsa a la que entró.');
    }

    /** Un traspaso nuevo solo va a un proyecto ACTIVO, como la salida (no a uno FINALIZADO). */
    public function test_un_traspaso_nuevo_a_un_proyecto_finalizado_se_rechaza(): void
    {
        $u = $this->superAdminGlobal();
        $origen = Almacen::create(['NOMBRE' => 'ORIGEN ' . uniqid(), 'TIPO' => Almacen::TIPO_GENERAL]);
        $id = DB::table('frentes_trabajo')->insertGetId([
            'NOMBRE_FRENTE' => 'FIN ' . uniqid(), 'UBICACION' => 'X', 'TIPO_FRENTE' => 'OPERACION',
            'ESTATUS_FRENTE' => 'FINALIZADO', 'created_at' => now(), 'updated_at' => now(),
        ], 'ID_FRENTE');

        $this->actingAs($u)->postJson(route('almacen.recepcion.store'), [
            'id_almacen_origen' => $origen->ID_ALMACEN, 'id_frente_destino' => $id,
        ])->assertStatus(422)->assertJsonValidationErrors(['id_frente_destino'])
            ->assertJsonFragment(['El proyecto destino no está activo (finalizado o borrado): elige uno activo.']);
    }

    /** Un borrador no se puede pasar a un proyecto FINALIZADO; dejarle el suyo sí vale. */
    public function test_un_borrador_no_se_cambia_a_un_proyecto_finalizado(): void
    {
        $u = $this->superAdminGlobal();
        $origen = Almacen::create(['NOMBRE' => 'ORIGEN ' . uniqid(), 'TIPO' => Almacen::TIPO_GENERAL]);
        $destino = Almacen::create(['NOMBRE' => 'DESTINO ' . uniqid(), 'TIPO' => Almacen::TIPO_GENERAL]);
        $frente = fn (string $estatus) => DB::table('frentes_trabajo')->insertGetId([
            'NOMBRE_FRENTE' => $estatus . ' ' . uniqid(), 'UBICACION' => 'X', 'TIPO_FRENTE' => 'OPERACION',
            'ESTATUS_FRENTE' => $estatus, 'created_at' => now(), 'updated_at' => now(),
        ], 'ID_FRENTE');
        $suyo = $frente('ACTIVO');
        $fin = $frente('FINALIZADO');
        $id = \App\Models\Traspaso::create([
            'NUMERO' => 'T-' . uniqid(), 'ID_ALMACEN_ORIGEN' => $origen->ID_ALMACEN, 'ID_ALMACEN_DESTINO' => $destino->ID_ALMACEN,
            'ID_FRENTE_DESTINO' => $suyo, 'ESTADO' => 'BORRADOR', 'ID_USUARIO_CREO' => $u->ID_USUARIO,
        ])->ID_TRASPASO;

        $this->actingAs($u)->patchJson(route('almacen.recepcion.update', $id), ['id_frente_destino' => $fin])
            ->assertStatus(422)->assertJsonValidationErrors(['id_frente_destino']);
        $this->assertSame($suyo, (int) \App\Models\Traspaso::whereKey($id)->value('ID_FRENTE_DESTINO'));

        // El que ya tenía, aunque lo finalicen después, sigue valiendo.
        DB::table('frentes_trabajo')->where('ID_FRENTE', $suyo)->update(['ESTATUS_FRENTE' => 'FINALIZADO']);
        $this->actingAs($u)->patchJson(route('almacen.recepcion.update', $id), [
            'id_frente_destino' => $suyo, 'notas' => 'x',
            'lineas' => [['id_producto' => ProductoInventario::create(['CODIGO' => 'T' . uniqid(), 'NOMBRE' => 'PRUEBA TRASPASO', 'UM' => 'UND'])->ID_PRODUCTO, 'cantidad' => 1]],
        ])
            ->assertSuccessful();
    }

    /** El campo "Proyecto" de la salida pone primero los del almacén: el mapa los trae todos. */
    public function test_los_proyectos_de_cada_almacen_para_la_salida(): void
    {
        $alm = Almacen::create(['NOMBRE' => 'MAPA ' . uniqid(), 'TIPO' => Almacen::TIPO_GENERAL]);
        $ids = collect(['A', 'B'])->map(fn ($n) => DB::table('frentes_trabajo')->insertGetId([
            'NOMBRE_FRENTE' => "MAPA {$n} " . uniqid(), 'UBICACION' => 'X', 'TIPO_FRENTE' => 'OPERACION',
            'ESTATUS_FRENTE' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now(),
        ], 'ID_FRENTE'));
        $ids->each(fn ($id) => DB::table('almacen_frentes')->insert(['ID_ALMACEN' => $alm->ID_ALMACEN, 'ID_FRENTE' => $id]));

        $mapa = \App\Support\DatosNotaSalida::frentesPorAlmacen();
        $this->assertEqualsCanonicalizing($ids->all(), $mapa[$alm->ID_ALMACEN]->all());
        // En el JSON de la página va como objeto { ID_ALMACEN: [ids] }.
        $this->assertStringContainsString('"' . $alm->ID_ALMACEN . '":[', json_encode($mapa));
    }
}
