<?php

namespace Tests\Feature;

use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use App\Models\Usuario;
use App\Support\RegistroCambios;
use App\Support\Sincronizacion\DominioEquipos;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Copia local de EQUIPOS (GET /sync/equipos) sobre la base real: carga completa acotada a
 * los frentes del usuario, y después solo lo que anotó el registro de cambios.
 *
 * No escribe nada permanente: MySqlTestCase revierte la transacción al terminar.
 */
class SincronizacionEquiposTest extends MySqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! RegistroCambios::activo()) {
            $this->markTestSkipped('Faltan los triggers del registro de cambios (php artisan migrate).');
        }
    }

    public function test_la_carga_completa_trae_solo_los_equipos_de_sus_frentes(): void
    {
        [$usuario, $suyo, $ajeno] = $this->escenario();

        $r = $this->sync($usuario)->assertOk()->assertJson(['completo' => true, 'esquema' => DominioEquipos::ESQUEMA]);

        $ids = array_column($r->json('datos.equipos'), 'equipoId');
        $this->assertContains($suyo->ID_EQUIPO, $ids);
        $this->assertNotContains($ajeno->ID_EQUIPO, $ids, 'Un LOCAL no recibe equipos de frentes que no tiene.');
        $this->assertSame([(int) $suyo->ID_FRENTE_ACTUAL], array_column($r->json('catalogos.frentes'), 'id'));
        $this->assertMatchesRegularExpression('/^c:\d+$/', $r->json('hasta'));
    }

    public function test_despues_solo_baja_lo_que_cambio(): void
    {
        [$usuario, $suyo] = $this->escenario();
        $primera = $this->sync($usuario)->json();

        Equipo::whereKey($suyo->ID_EQUIPO)->update(['DETALLE_UBICACION_ACTUAL' => 'PATIO DE PRUEBA SYNC']);

        $r = $this->sync($usuario, $primera)->assertOk()->assertJson(['completo' => false]);
        $filas = collect($r->json('datos.equipos'))->keyBy('equipoId');
        $this->assertSame('PATIO DE PRUEBA SYNC', $filas[$suyo->ID_EQUIPO]['detalleUbicacion']);
        $this->assertLessThanOrEqual(5, $filas->count(), 'Solo el equipo tocado (y lo que se le ancla), no la copia entera.');
    }

    public function test_el_equipo_que_sale_de_su_alcance_llega_como_borrado(): void
    {
        [$usuario, $suyo, $ajeno] = $this->escenario();
        $primera = $this->sync($usuario)->json();

        Equipo::whereKey($suyo->ID_EQUIPO)->update(['ID_FRENTE_ACTUAL' => $ajeno->ID_FRENTE_ACTUAL]);

        $r = $this->sync($usuario, $primera)->assertJson(['completo' => false]);
        $this->assertContains($suyo->ID_EQUIPO, $r->json('borrados.equipos'));
        $this->assertNotContains($suyo->ID_EQUIPO, array_column($r->json('datos.equipos') ?? [], 'equipoId'));
    }

    public function test_el_equipo_eliminado_llega_como_borrado(): void
    {
        [$usuario, $suyo] = $this->escenario();
        $primera = $this->sync($usuario)->json();

        Equipo::find($suyo->ID_EQUIPO)->delete();

        $this->assertContains($suyo->ID_EQUIPO, $this->sync($usuario, $primera)->json('borrados.equipos'));
    }

    public function test_renombrar_el_frente_reenvia_sus_equipos_y_el_catalogo(): void
    {
        [$usuario, $suyo] = $this->escenario();
        $primera = $this->sync($usuario)->json();

        FrenteTrabajo::whereKey($suyo->ID_FRENTE_ACTUAL)->update(['NOMBRE_FRENTE' => 'FRENTE RENOMBRADO SYNC']);

        $r = $this->sync($usuario, $primera)->assertJson(['completo' => false]);
        $filas = collect($r->json('datos.equipos'))->keyBy('equipoId');
        $this->assertSame('FRENTE RENOMBRADO SYNC', $filas[$suyo->ID_EQUIPO]['ubicacion']);
        $this->assertSame('FRENTE RENOMBRADO SYNC', $r->json('catalogos.frentes.0.nombre'));
    }

    public function test_si_cambia_su_alcance_recarga_entero(): void
    {
        [$usuario, , $ajeno] = $this->escenario();
        $primera = $this->sync($usuario)->json();

        DB::table('usuarios')->where('ID_USUARIO', $usuario->ID_USUARIO)
            ->update(['ID_FRENTE_ASIGNADO' => $usuario->ID_FRENTE_ASIGNADO . ',' . $ajeno->ID_FRENTE_ACTUAL]);

        $r = $this->sync($usuario->fresh(), $primera)->assertJson(['completo' => true]);
        $this->assertContains($ajeno->ID_EQUIPO, array_column($r->json('datos.equipos'), 'equipoId'));
    }

    public function test_un_esquema_viejo_recarga_entero(): void
    {
        [$usuario] = $this->escenario();
        $primera = $this->sync($usuario)->json();
        $primera['esquema'] = DominioEquipos::ESQUEMA - 1;

        $this->sync($usuario, $primera)->assertJson(['completo' => true]);
    }

    public function test_el_cursor_espera_en_un_hueco_reciente_y_salta_uno_viejo(): void
    {
        $n = RegistroCambios::ultimo() + 1000;
        $ahora = DB::scalar('SELECT CURRENT_TIMESTAMP');
        DB::table(RegistroCambios::TABLA)->insert([
            ['ID_CAMBIO' => $n + 1, 'TABLA' => 'equipos', 'ID_FILA' => 1, 'created_at' => $ahora],
            ['ID_CAMBIO' => $n + 3, 'TABLA' => 'equipos', 'ID_FILA' => 2, 'created_at' => $ahora],
        ]);

        [$cursor, $ids] = RegistroCambios::desde($n);
        $this->assertSame($n + 1, $cursor, 'No salta el hueco: la transacción de N+2 puede no haber confirmado.');
        $this->assertSame([1, 2], $ids['equipos'], 'Lo de después del hueco se manda igual.');

        DB::table(RegistroCambios::TABLA)->where('ID_CAMBIO', $n + 3)
            ->update(['created_at' => date('Y-m-d H:i:s', strtotime($ahora) - 3600)]);
        $this->assertSame($n + 3, RegistroCambios::desde($n)[0], 'Un hueco viejo es una transacción revertida.');
    }

    public function test_un_cambio_fuera_de_su_alcance_no_le_manda_filas(): void
    {
        [$usuario, , $ajeno] = $this->escenario();
        $primera = $this->sync($usuario)->json();

        Equipo::whereKey($ajeno->ID_EQUIPO)->update(['DETALLE_UBICACION_ACTUAL' => 'OTRO PATIO']);

        $r = $this->sync($usuario, $primera)->assertJson(['completo' => false]);
        $this->assertEmpty($r->json('datos'));
        $this->assertEmpty($r->json('catalogos'));
    }

    public function test_un_cursor_de_antes_de_la_purga_recarga_entero(): void
    {
        [$usuario] = $this->escenario();
        $primera = $this->sync($usuario)->json();
        $cursor  = (int) substr($primera['hasta'], 2);

        DB::table(RegistroCambios::TABLA)->insert([
            'TABLA' => 'equipos', 'ID_FILA' => 1, 'created_at' => now()->subDays(40),
        ]);
        RegistroCambios::purgar();

        $this->assertFalse(RegistroCambios::cubre($cursor));
        $this->sync($usuario, $primera)->assertJson(['completo' => true]);
        $this->assertTrue(RegistroCambios::cubre(RegistroCambios::ultimo()));
    }

    private function sync(Usuario $usuario, ?array $previa = null)
    {
        $q = $previa ? http_build_query(['desde' => $previa['hasta'], 'firma' => $previa['firma'], 'esquema' => $previa['esquema']]) : '';

        return $this->actingAs($usuario)->getJson('/sync/equipos' . ($q ? '?' . $q : ''));
    }

    /**
     * Un usuario LOCAL con UN frente, un equipo de ese frente y uno de otro frente.
     *
     * @return array{0:Usuario,1:Equipo,2:Equipo}
     */
    private function escenario(): array
    {
        $suyo = Equipo::whereNotNull('ID_FRENTE_ACTUAL')->first();
        $ajeno = $suyo ? Equipo::whereNotNull('ID_FRENTE_ACTUAL')->where('ID_FRENTE_ACTUAL', '!=', $suyo->ID_FRENTE_ACTUAL)->first() : null;
        if (! $ajeno) {
            $this->markTestSkipped('Hacen falta equipos en dos frentes distintos.');
        }

        // Usuario de prueba creado DESDE CERO (nunca replicate) y solo dentro de la transacción.
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO'       => 'PRUEBA SINCRONIZACION EQUIPOS',
            'CORREO_ELECTRONICO'    => 'prueba.sync.equipos@local.test',
            'PASSWORD_HASH'         => bcrypt('irrelevante'),
            'ID_ROL'                => DB::table('roles')->value('ID_ROL'),
            'NIVEL_ACCESO_EQUIPOS'  => 2,
            'NIVEL_ACCESO_ALMACEN'  => 2,
            'ESTATUS'               => 'ACTIVO',
            'REQUIERE_CAMBIO_CLAVE' => 0,
            'PERMISOS'              => '',
            'ID_FRENTE_ASIGNADO'    => (string) $suyo->ID_FRENTE_ACTUAL,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        return [Usuario::find($id), $suyo, $ajeno];
    }
}
