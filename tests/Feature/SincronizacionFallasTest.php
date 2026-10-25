<?php

namespace Tests\Feature;

use App\Models\Equipo;
use App\Models\Falla;
use App\Models\Usuario;
use App\Support\RegistroCambios;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Copia local de los reportes de FALLAS (GET /sync/fallas) sobre la base real. La pantalla
 * /admin/fallas filtra, pagina y cuenta solo con estas filas (fallas_index.js), así que cada
 * cambio de un reporte —o de lo que copia de su activo— tiene que llegar por el registro.
 *
 * No escribe nada permanente: MySqlTestCase revierte la transacción al terminar.
 */
class SincronizacionFallasTest extends MySqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! RegistroCambios::activo()) {
            $this->markTestSkipped('Faltan los triggers del registro de cambios (php artisan migrate).');
        }
    }

    public function test_cualquier_usuario_la_tiene_y_la_pantalla_no_trae_filas(): void
    {
        $usuario = $this->usuario('');
        $r = $this->sync($usuario)->assertOk()->assertJson(['completo' => true]);
        $this->assertSame(Falla::count(), count($r->json('datos.fallas')));

        $this->actingAs($usuario)->get('/admin/fallas')->assertOk()
            ->assertSee('id="fallasTableBody"', false)
            ->assertDontSee('falla-row-card', false);
    }

    public function test_la_fila_trae_lo_que_pinta_la_tarjeta(): void
    {
        $falla = Falla::where('ACTIVO_TIPO', 'equipo')->latest('ID_FALLA')->first()
            ?? $this->markTestSkipped('No hay reportes de equipos.');

        $f = collect($this->sync($this->usuario(''))->json('datos.fallas'))->keyBy('id')[$falla->ID_FALLA];

        $this->assertSame($falla->CODIGO_REPORTE, $f['codigo']);
        $this->assertSame($falla->FECHA_EMISION->format('Y-m-d H:i:s'), $f['fecha']);
        $this->assertSame($falla->ESTADO_REPORTE, $f['estado']);
        $this->assertSame($falla->NOMBRE_REPORTA, $f['reporta']);
        $this->assertSame((int) $falla->activo()->ID_FRENTE_ACTUAL ?: null, $f['activo']['idFrente']);
        // El encabezado del modal de cierre: la misma fuente que equipos y auxiliares.
        $this->assertSame(Falla::datosActivo($falla->activo())['equipo'], $f['activo']['equipo']);
    }

    public function test_cerrar_y_cambiar_la_placa_reenvian_el_reporte(): void
    {
        $falla = Falla::where('ACTIVO_TIPO', 'equipo')->latest('ID_FALLA')->first()
            ?? $this->markTestSkipped('No hay reportes de equipos.');
        $usuario = $this->usuario('');
        $primera = $this->sync($usuario)->json();

        DB::table('fallas')->where('ID_FALLA', $falla->ID_FALLA)->update(['ESTADO_REPORTE' => 'cerrado', 'MECANICO_ASIGNADO' => 'MECANICO SYNC']);
        $r = $this->sync($usuario, $primera)->assertJson(['completo' => false]);
        $this->assertSame('MECANICO SYNC', collect($r->json('datos.fallas'))->keyBy('id')[$falla->ID_FALLA]['mecanico']);

        DB::table('documentacion')->updateOrInsert(['ID_EQUIPO' => $falla->ACTIVO_ID], ['PLACA' => 'SYNC-FL']);
        $r = $this->sync($usuario, $r->json())->assertJson(['completo' => false]);
        $this->assertSame('SYNC-FL', collect($r->json('datos.fallas'))->keyBy('id')[$falla->ID_FALLA]['activo']['placa']);
    }

    public function test_borrar_el_reporte_llega_como_borrado(): void
    {
        $falla = Falla::latest('ID_FALLA')->first() ?? $this->markTestSkipped('No hay reportes.');
        $usuario = $this->usuario('');
        $primera = $this->sync($usuario)->json();

        // Igual que FallaController::destroy (borrado duro).
        $falla->forceDelete();

        $this->assertContains((int) $falla->ID_FALLA, $this->sync($usuario, $primera)->json('borrados.fallas'));
    }

    /**
     * Fallas trabaja con Equipos: crear, cerrar y borrar un reporte cambian el estado del
     * equipo y su aviso de reporte abierto. Las DOS copias tienen que enterarse por el
     * registro, sin carga completa.
     */
    public function test_crear_cerrar_y_borrar_mueven_la_copia_de_fallas_y_la_de_equipos(): void
    {
        $admin = $this->usuario('super.admin');
        $e = Equipo::create([
            'MARCA' => 'PRUEBA', 'MODELO' => 'PRUEBA-SYNC-FL', 'ANIO' => 2026,
            'ESTADO_OPERATIVO' => 'OPERATIVO', 'SERIAL_CHASIS' => 'TEST-SYNC-FL-' . uniqid(),
        ]);
        $fl = $this->sync($admin)->json();
        $eq = $this->sync($admin, null, 'equipos')->json();
        $crear = fn () => $this->actingAs($admin)->postJson(route('fallas.store'), [
            'tipo_reporte' => 'corto', 'activo_tipo' => 'equipo', 'activo_id' => $e->ID_EQUIPO,
            'descripcion' => 'PRUEBA SYNC FALLAS',
        ])->assertOk()->json('falla.ID_FALLA');
        $paso = function () use ($admin, &$fl, &$eq, $e) {
            $fl = $this->sync($admin, $fl)->assertJson(['completo' => false])->json();
            $eq = $this->sync($admin, $eq, 'equipos')->assertJson(['completo' => false])->json();

            return [collect($fl['datos']['fallas'] ?? [])->keyBy('id'), collect($eq['datos']['equipos'] ?? [])->keyBy('id')[$e->ID_EQUIPO] ?? null];
        };

        // Crear: el reporte aparece abierto y el equipo queda INOPERATIVO con su aviso.
        $id = $crear();
        [$fallas, $equipo] = $paso();
        $this->assertSame('abierto', $fallas[$id]['estado']);
        $this->assertSame($e->SERIAL_CHASIS, $fallas[$id]['activo']['serial']);
        $this->assertSame('INOPERATIVO', $equipo['estado']);
        $this->assertSame($id, $equipo['falla']['id']);

        // Cerrar (desde /admin/fallas): cerrado, y el equipo vuelve a OPERATIVO sin aviso.
        $this->actingAs($admin)->patchJson(route('fallas.close', $id), ['observaciones_cierre' => 'OK'])->assertOk()->assertJson(['success' => true]);
        [$fallas, $equipo] = $paso();
        $this->assertSame('cerrado', $fallas[$id]['estado']);
        $this->assertSame('OPERATIVO', $equipo['estado']);
        $this->assertNull($equipo['falla']);

        // Borrar uno abierto (super.admin): se va de la copia y el equipo se cura.
        $otro = $crear();
        $paso();
        $this->actingAs($admin)->deleteJson(route('fallas.destroy', $otro))->assertOk()->assertJson(['success' => true]);
        [, $equipo] = $paso();
        $this->assertContains($otro, $fl['borrados']['fallas'] ?? []);
        $this->assertSame('OPERATIVO', $equipo['estado']);
        $this->assertNull($equipo['falla']);
    }

    private function sync(Usuario $usuario, ?array $previa = null, string $dominio = 'fallas')
    {
        $q = $previa ? '?' . http_build_query(['desde' => $previa['hasta'], 'firma' => $previa['firma'], 'esquema' => $previa['esquema']]) : '';

        return $this->actingAs($usuario)->getJson('/sync/' . $dominio . $q);
    }

    /** Usuario de prueba creado DESDE CERO (nunca replicate) y solo dentro de la transacción. */
    private function usuario(string $permisos): Usuario
    {
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO'       => 'PRUEBA SINCRONIZACION FALLAS',
            'CORREO_ELECTRONICO'    => 'prueba.sync.fallas.' . uniqid() . '@local.test',
            'PASSWORD_HASH'         => bcrypt('irrelevante'),
            'ID_ROL'                => DB::table('roles')->value('ID_ROL'),
            'NIVEL_ACCESO_EQUIPOS'  => 1,
            'NIVEL_ACCESO_ALMACEN'  => 1,
            'ESTATUS'               => 'ACTIVO',
            'REQUIERE_CAMBIO_CLAVE' => 0,
            'PERMISOS'              => $permisos,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        return Usuario::find($id);
    }
}
