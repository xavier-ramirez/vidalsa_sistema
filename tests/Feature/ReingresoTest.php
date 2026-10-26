<?php

namespace Tests\Feature;

use App\Support\PaseReingreso;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Pase de reingreso (PaseReingreso, ReingresoController): quien entró sin conexión vuelve a
 * tener sesión al volver el servidor sin pasar por el inicio de sesión. Un solo uso, y no
 * sirve con otra clave, desactivado, con cambio de clave pendiente o vencido.
 */
class ReingresoTest extends MySqlTestCase
{
    public function test_el_pase_se_pide_con_sesion(): void
    {
        $this->postJson('/sesion/pase')->assertUnauthorized();
    }

    public function test_el_pase_abre_la_sesion_y_entrega_otro(): void
    {
        $usuario = $this->superAdminGlobal();
        $pase = $this->paseDe($usuario);

        $r = $this->postJson('/sesion/reanudar', ['pase' => $pase])->assertOk()->assertJson(['ok' => true]);
        $this->assertAuthenticatedAs($usuario);
        $this->assertSame($usuario->fresh()->SESSION_TOKEN, session('current_session_token'), 'la sesión pasa ValidarSesionUnica');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $r->json('pase'));
        $this->assertNotSame($pase, $r->json('pase'));
        $this->assertNotEmpty($r->json('csrf'));
    }

    public function test_un_pase_sirve_una_sola_vez(): void
    {
        $pase = $this->paseDe($this->superAdminGlobal());
        $this->postJson('/sesion/reanudar', ['pase' => $pase])->assertOk();
        $this->salir();

        $this->postJson('/sesion/reanudar', ['pase' => $pase])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_no_sirve_si_la_clave_cambio(): void
    {
        $usuario = $this->superAdminGlobal();
        $pase = $this->paseDe($usuario);
        DB::table('usuarios')->where('ID_USUARIO', $usuario->ID_USUARIO)->update(['PASSWORD_HASH' => bcrypt('otra-clave')]);

        $this->postJson('/sesion/reanudar', ['pase' => $pase])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_no_sirve_desactivado_con_cambio_de_clave_pendiente_ni_vencido(): void
    {
        $usuario = $this->superAdminGlobal();
        $casos = [
            'desactivado'      => fn () => DB::table('usuarios')->where('ID_USUARIO', $usuario->ID_USUARIO)->update(['ESTATUS' => 'INACTIVO']),
            'cambio pendiente' => fn () => DB::table('usuarios')->where('ID_USUARIO', $usuario->ID_USUARIO)->update(['REQUIERE_CAMBIO_CLAVE' => 1]),
            'vencido'          => fn () => DB::table(PaseReingreso::TABLA)->update(['VENCE' => now()->subMinute()]),
        ];
        foreach ($casos as $caso => $aplicar) {
            $pase = $this->paseDe($usuario);
            $antes = DB::table('usuarios')->where('ID_USUARIO', $usuario->ID_USUARIO)->first(['ESTATUS', 'REQUIERE_CAMBIO_CLAVE']);
            $aplicar();
            $this->postJson('/sesion/reanudar', ['pase' => $pase])->assertStatus(422);
            $this->assertGuest();
            DB::table('usuarios')->where('ID_USUARIO', $usuario->ID_USUARIO)->update((array) $antes);
        }
    }

    public function test_un_pase_inventado_no_abre_nada(): void
    {
        $this->postJson('/sesion/reanudar', ['pase' => str_repeat('a', 64)])->assertStatus(422);
        $this->postJson('/sesion/reanudar', ['pase' => 'x'])->assertStatus(422);
        $this->postJson('/sesion/reanudar', [])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_el_anterior_se_borra_y_no_quedan_mas_de_diez_por_usuario(): void
    {
        $usuario = $this->superAdminGlobal();
        DB::table(PaseReingreso::TABLA)->where('ID_USUARIO', $usuario->ID_USUARIO)->delete();

        $viejo = PaseReingreso::emitir($usuario);
        PaseReingreso::emitir($usuario, $viejo);
        $this->assertNull(PaseReingreso::canjear($viejo), 'el anterior ya no sirve');

        foreach (range(1, PaseReingreso::MAX_POR_USUARIO + 3) as $_) {
            PaseReingreso::emitir($usuario);
        }
        $this->assertSame(PaseReingreso::MAX_POR_USUARIO, DB::table(PaseReingreso::TABLA)->where('ID_USUARIO', $usuario->ID_USUARIO)->count());
    }

    public function test_el_login_con_clave_abre_la_sesion_igual_que_el_pase(): void
    {
        $usuario = $this->superAdminGlobal();
        DB::table('usuarios')->where('ID_USUARIO', $usuario->ID_USUARIO)
            ->update(['PASSWORD_HASH' => bcrypt('clave-de-prueba'), 'REQUIERE_CAMBIO_CLAVE' => 0]);

        $this->postJson('/', ['login_identifier' => $usuario->CORREO_ELECTRONICO, 'password' => 'clave-de-prueba'])
            ->assertOk()->assertJson(['success' => true])
            ->assertCookie(\App\Http\Controllers\Auth\LoginController::COOKIE_SESION_PREVIA);
        $this->assertAuthenticatedAs($usuario);
        $this->assertSame($usuario->fresh()->SESSION_TOKEN, session('current_session_token'));
    }

    /** Un pase pedido con sesión, como lo pide reingreso.js; después se sale. */
    private function paseDe($usuario): string
    {
        $pase = $this->actingAs($usuario)->postJson('/sesion/pase')->assertOk()->json('pase');
        $this->salir();

        return $pase;
    }

    private function salir(): void
    {
        Auth::logout();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }
}
