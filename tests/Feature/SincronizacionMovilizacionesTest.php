<?php

namespace Tests\Feature;

use App\Models\Movilizacion;
use App\Models\Usuario;
use App\Support\RegistroCambios;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Copia local del historial de MOVILIZACIONES (GET /sync/movilizaciones) sobre la base real.
 * Decisión del usuario: la ve quien tiene permisos de equipos, sin las movilizaciones que
 * tocan un frente bloqueado para él. Igual la pantalla /admin/movilizaciones y el modal
 * «Movilizaciones» del detalle del equipo.
 *
 * No escribe nada permanente: MySqlTestCase revierte la transacción al terminar.
 */
class SincronizacionMovilizacionesTest extends MySqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! RegistroCambios::activo()) {
            $this->markTestSkipped('Faltan los triggers del registro de cambios (php artisan migrate).');
        }
    }

    public function test_sin_permisos_de_equipos_no_hay_historial(): void
    {
        $usuario = $this->usuario('');

        $this->actingAs($usuario)->getJson('/sync/movilizaciones')->assertForbidden();
        $this->actingAs($usuario)->get('/admin/movilizaciones')->assertForbidden();
        $this->actingAs($usuario)->get('/admin/equipos')->assertOk()->assertDontSee('id="btn_ver_movilizaciones"', false);
        $this->actingAs($this->usuario('equipos.assign'))->get('/admin/equipos')->assertOk()
            ->assertSee('id="btn_ver_movilizaciones"', false);
    }

    public function test_la_carga_completa_trae_todo_menos_lo_que_toca_un_frente_bloqueado(): void
    {
        $mov = $this->movilizacionConDestino();
        $libre = $this->sync($this->usuario('equipos.assign'))->assertOk()->assertJson(['completo' => true]);
        $this->assertSame(Movilizacion::count(), count($libre->json('datos.movilizaciones')));

        $bloqueado = $this->usuario('equipos.assign', (string) $mov->ID_FRENTE_DESTINO);
        $ids = array_column($this->sync($bloqueado)->json('datos.movilizaciones'), 'id');
        $this->assertNotContains((int) $mov->ID_MOVILIZACION, $ids);
    }

    public function test_la_fila_trae_lo_que_pinta_la_tabla(): void
    {
        $mov = Movilizacion::whereNotNull('ID_EQUIPO')->whereNotNull('CODIGO_CONTROL')->latest('ID_MOVILIZACION')->first()
            ?? $this->markTestSkipped('No hay movilizaciones de equipos con acta.');

        $filas = collect($this->sync($this->usuario('equipos.assign'))->json('datos.movilizaciones'))->keyBy('id');
        $f = $filas[$mov->ID_MOVILIZACION];

        $this->assertSame($mov->nombre_destino, $f['destino']);
        $this->assertMatchesRegularExpression('/^MV-\d{5,}$/', $f['mv']);
        $this->assertSame($mov->created_at->format('Y-m-d H:i:s'), $f['fecha']);
        $this->assertArrayHasKey('placa', $f['equipo']);
        // El modal «Movilizaciones» del detalle del equipo las busca y pinta con esto.
        $this->assertSame((int) $mov->ID_EQUIPO, $f['idEquipo']);
        $this->assertSame($mov->DETALLE_UBICACION, $f['detalle']);
    }

    public function test_cambiar_la_placa_del_equipo_reenvia_sus_movilizaciones(): void
    {
        $mov = Movilizacion::whereNotNull('ID_EQUIPO')->latest('ID_MOVILIZACION')->first()
            ?? $this->markTestSkipped('No hay movilizaciones de equipos.');
        $usuario = $this->usuario('equipos.assign');
        $primera = $this->sync($usuario)->json();

        DB::table('documentacion')->updateOrInsert(['ID_EQUIPO' => $mov->ID_EQUIPO], ['PLACA' => 'SYNC-01']);

        $r = $this->sync($usuario, $primera)->assertJson(['completo' => false]);
        $filas = collect($r->json('datos.movilizaciones'))->keyBy('id');
        $this->assertSame('SYNC-01', $filas[$mov->ID_MOVILIZACION]['equipo']['placa']);
    }

    public function test_deshacer_llega_como_borrado(): void
    {
        $mov = Movilizacion::latest('ID_MOVILIZACION')->first() ?? $this->markTestSkipped('No hay movilizaciones.');
        $usuario = $this->usuario('equipos.assign');
        $primera = $this->sync($usuario)->json();

        $mov->delete();

        $this->assertContains((int) $mov->ID_MOVILIZACION, $this->sync($usuario, $primera)->json('borrados.movilizaciones'));
    }

    private function sync(Usuario $usuario, ?array $previa = null)
    {
        $q = $previa ? '?' . http_build_query(['desde' => $previa['hasta'], 'firma' => $previa['firma'], 'esquema' => $previa['esquema']]) : '';

        return $this->actingAs($usuario)->getJson('/sync/movilizaciones' . $q);
    }

    private function movilizacionConDestino(): Movilizacion
    {
        return Movilizacion::whereNotNull('ID_FRENTE_DESTINO')->latest('ID_MOVILIZACION')->first()
            ?? $this->markTestSkipped('No hay movilizaciones con destino.');
    }

    /** Usuario de prueba creado DESDE CERO (nunca replicate) y solo dentro de la transacción. */
    private function usuario(string $permisos, ?string $bloqueados = null): Usuario
    {
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO'       => 'PRUEBA SINCRONIZACION MOVILIZACIONES',
            'CORREO_ELECTRONICO'    => 'prueba.sync.mov.' . uniqid() . '@local.test',
            'PASSWORD_HASH'         => bcrypt('irrelevante'),
            'ID_ROL'                => DB::table('roles')->value('ID_ROL'),
            'NIVEL_ACCESO_EQUIPOS'  => 1,
            'NIVEL_ACCESO_ALMACEN'  => 1,
            'ESTATUS'               => 'ACTIVO',
            'REQUIERE_CAMBIO_CLAVE' => 0,
            'PERMISOS'              => $permisos,
            'ID_FRENTE_BLOQUEADO'   => $bloqueados,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        return Usuario::find($id);
    }
}
