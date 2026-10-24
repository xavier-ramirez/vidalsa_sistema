<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Models\Usuario;
use App\Support\RegistroCambios;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Copia local del ALMACÉN (GET /sync/almacen) sobre la base real: catálogo de productos y,
 * de los almacenes que el usuario ve, los saldos y el kardex. Con ella pintan el inventario,
 * la bitácora, el historial de un producto y las Notas de Entrega.
 *
 * No escribe nada permanente: MySqlTestCase revierte la transacción al terminar.
 */
class SincronizacionAlmacenTest extends MySqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! RegistroCambios::activo()) {
            $this->markTestSkipped('Faltan los triggers del registro de cambios (php artisan migrate).');
        }
    }

    public function test_un_global_baja_todo_el_kardex_y_el_catalogo(): void
    {
        $r = $this->sync($this->usuario(1))->assertOk()->assertJson(['completo' => true]);

        $visibles = Almacen::visiblesPara($this->usuario(1))->pluck('ID_ALMACEN');
        $this->assertSame(MovimientoInventario::whereIn('ID_ALMACEN', $visibles)->count(), count($r->json('datos.movimientos')));
        $this->assertSame(DB::table('productos_inventario')->whereNull('deleted_at')->count(), count($r->json('datos.productos')));
        $this->assertSame(DB::table('almacen_stock')->whereIn('ID_ALMACEN', $visibles)->count(), count($r->json('datos.stock')));
    }

    public function test_un_local_solo_ve_los_almacenes_de_su_frente(): void
    {
        [$alm, $frente] = $this->almacenConFrente();
        $local = $this->usuario(2, (string) $frente);

        $r = $this->sync($local)->assertOk();
        $almacenesMov = array_unique(array_column($r->json('datos.movimientos'), 'alm'));
        $almacenesStock = array_unique(array_column($r->json('datos.stock'), 'alm'));
        $visibles = Almacen::visiblesPara($local)->pluck('ID_ALMACEN')->map('intval')->all();

        $this->assertContains((int) $alm, $visibles);
        $this->assertSame([], array_diff($almacenesMov, $visibles));
        $this->assertSame([], array_diff($almacenesStock, $visibles));
        $catalogo = collect($r->json('catalogos.almacenes'))->keyBy('id');
        $this->assertTrue($catalogo[$alm]['visible']);
    }

    public function test_un_movimiento_nuevo_llega_con_su_saldo(): void
    {
        $usuario = $this->usuario(1);
        $primera = $this->sync($usuario)->json();
        $stock = DB::table('almacen_stock')->first() ?? $this->markTestSkipped('No hay saldos.');

        DB::table('almacen_stock')->where('ID_STOCK', $stock->ID_STOCK)->update(['CANTIDAD' => (float) $stock->CANTIDAD + 7]);
        $idMov = DB::table('movimientos_inventario')->insertGetId([
            'ID_ALMACEN' => $stock->ID_ALMACEN, 'ID_PRODUCTO' => $stock->ID_PRODUCTO, 'TIPO' => 'ENTRADA',
            'CANTIDAD' => 7, 'CANTIDAD_ANTERIOR' => $stock->CANTIDAD, 'CANTIDAD_RESULTANTE' => (float) $stock->CANTIDAD + 7,
            'FECHA' => now()->toDateString(), 'ID_FRENTE_SALDO' => $stock->ID_FRENTE, 'REFERENCIA' => 'SYNC-ALM',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->sync($usuario, $primera)->assertJson(['completo' => false]);
        $mov = collect($r->json('datos.movimientos'))->firstWhere('id', $idMov);
        $this->assertSame('SYNC-ALM', $mov['ref']);
        $this->assertSame((float) $stock->CANTIDAD + 7, (float) collect($r->json('datos.stock'))->firstWhere('id', $stock->ID_STOCK)['cant']);
    }

    public function test_borrar_un_movimiento_llega_como_borrado(): void
    {
        $usuario = $this->usuario(1);
        $mov = MovimientoInventario::latest('ID_MOVIMIENTO')->first() ?? $this->markTestSkipped('No hay movimientos.');
        $primera = $this->sync($usuario)->json();

        DB::table('movimientos_inventario')->where('ID_MOVIMIENTO', $mov->ID_MOVIMIENTO)->delete();

        $this->assertContains((int) $mov->ID_MOVIMIENTO, $this->sync($usuario, $primera)->json('borrados.movimientos'));
    }

    public function test_un_numero_de_parte_nuevo_reenvia_el_producto(): void
    {
        $usuario = $this->usuario(1);
        $idProd = (int) DB::table('productos_inventario')->whereNull('deleted_at')->value('ID_PRODUCTO');
        $primera = $this->sync($usuario)->json();

        DB::table('producto_equivalencias')->insert(['ID_PRODUCTO' => $idProd, 'NUMERO_PARTE' => 'SYNC-PARTE-1', 'ES_PRINCIPAL' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $prod = collect($this->sync($usuario, $primera)->json('datos.productos'))->firstWhere('id', $idProd);
        $this->assertContains('SYNC-PARTE-1', $prod['partes']);
    }

    public function test_cambiar_los_frentes_de_un_almacen_recarga_la_copia_entera(): void
    {
        [$alm] = $this->almacenConFrente();
        $usuario = $this->usuario(1);
        $primera = $this->sync($usuario)->json();

        $frenteNuevo = DB::table('frentes_trabajo')->whereNotIn('ID_FRENTE', DB::table('almacen_frentes')->where('ID_ALMACEN', $alm)->pluck('ID_FRENTE'))->value('ID_FRENTE');
        DB::table('almacen_frentes')->insert(['ID_ALMACEN' => $alm, 'ID_FRENTE' => $frenteNuevo, 'created_at' => now(), 'updated_at' => now()]);

        $this->sync($usuario, $primera)->assertJson(['completo' => true]);
    }

    public function test_una_correccion_de_nota_llega(): void
    {
        $usuario = $this->usuario(1);
        $idProd = (int) DB::table('productos_inventario')->value('ID_PRODUCTO');
        $primera = $this->sync($usuario)->json();

        $id = DB::table('correcciones_nota')->insertGetId([
            'NUMERO_NOTA' => 'NE-2099-0001', 'ID_PRODUCTO' => $idProd, 'CANTIDAD_ANTES' => 2, 'CANTIDAD_DESPUES' => 1,
            'MOTIVO' => 'prueba', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $c = collect($this->sync($usuario, $primera)->json('datos.correcciones'))->firstWhere('id', $id);
        $this->assertSame(['id' => $id, 'nota' => 'NE-2099-0001', 'prod' => $idProd], $c);
    }

    public function test_un_kit_nuevo_llega_en_los_catalogos(): void
    {
        $usuario = $this->usuario(1);
        $idProd = (int) DB::table('productos_inventario')->whereNull('deleted_at')->where('ESTATUS', 'ACTIVO')->value('ID_PRODUCTO');
        $primera = $this->sync($usuario)->json();

        $id = DB::table('almacen_kits')->insertGetId(['NOMBRE' => 'KIT SYNC ' . uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('almacen_kit_items')->insert(['ID_KIT' => $id, 'ID_PRODUCTO' => $idProd, 'CANTIDAD' => 2, 'ORDEN' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $kit = collect($this->sync($usuario, $primera)->assertJson(['completo' => false])->json('catalogos.kits'))->firstWhere('id', $id);
        $this->assertSame([$idProd], array_column($kit['items'], 'id_producto'));
    }

    private function sync(Usuario $usuario, ?array $previa = null)
    {
        $q = $previa ? '?' . http_build_query(['desde' => $previa['hasta'], 'firma' => $previa['firma'], 'esquema' => $previa['esquema']]) : '';

        return $this->actingAs($usuario)->getJson('/sync/almacen' . $q);
    }

    /** [ID_ALMACEN, ID_FRENTE] de un almacén activo con algún frente. */
    private function almacenConFrente(): array
    {
        $fila = DB::table('almacen_frentes')->join('almacenes', 'almacenes.ID_ALMACEN', '=', 'almacen_frentes.ID_ALMACEN')
            ->where('almacenes.ESTATUS', 'ACTIVO')->whereNull('almacenes.deleted_at')
            ->first(['almacen_frentes.ID_ALMACEN', 'almacen_frentes.ID_FRENTE'])
            ?? $this->markTestSkipped('No hay almacenes con frentes.');

        return [(int) $fila->ID_ALMACEN, (int) $fila->ID_FRENTE];
    }

    /** Usuario de prueba creado DESDE CERO (nunca replicate) y solo dentro de la transacción. */
    private function usuario(int $nivelAlmacen, ?string $frentes = null): Usuario
    {
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO'       => 'PRUEBA SINCRONIZACION ALMACEN',
            'CORREO_ELECTRONICO'    => 'prueba.sync.alm.' . uniqid() . '@local.test',
            'PASSWORD_HASH'         => bcrypt('irrelevante'),
            'ID_ROL'                => DB::table('roles')->value('ID_ROL'),
            'NIVEL_ACCESO_EQUIPOS'  => 1,
            'NIVEL_ACCESO_ALMACEN'  => $nivelAlmacen,
            'ID_FRENTE_ASIGNADO'    => $frentes,
            'ESTATUS'               => 'ACTIVO',
            'REQUIERE_CAMBIO_CLAVE' => 0,
            'PERMISOS'              => '',
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        return Usuario::find($id);
    }
}
