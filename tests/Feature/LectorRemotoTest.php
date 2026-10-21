<?php

namespace Tests\Feature;

use App\Models\ProductoInventario;
use App\Models\Usuario;
use App\Services\LectorRemoto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * El teléfono como lector de la PC (App\Services\LectorRemoto, LectorRemotoController) y lo
 * que lo hace posible: entrar con la misma cuenta en varios equipos a la vez
 * (Usuario::tokenDeSesion), sin perder que un cambio de clave cierre todas las sesiones.
 * Corre en la transacción de la prueba; el usuario se crea desde cero.
 */
class LectorRemotoTest extends MySqlTestCase
{
    private const CLAVE = 'Clave#123456';

    private function crearUsuario(): Usuario
    {
        $u = new Usuario();
        $u->NOMBRE_COMPLETO = 'PRUEBA LECTOR ' . strtoupper(Str::random(6));
        $u->CORREO_ELECTRONICO = 'prueba.lector.' . strtolower(Str::random(8)) . '@cvidalsa27.com';
        $u->PASSWORD_HASH = Hash::make(self::CLAVE);
        $u->ID_ROL = DB::table('roles')->value('ID_ROL');
        $u->NIVEL_ACCESO_EQUIPOS = 2;
        $u->NIVEL_ACCESO_ALMACEN = 2;
        $u->ESTATUS = 'ACTIVO';
        $u->REQUIERE_CAMBIO_CLAVE = 0;
        $u->save();
        return $u;
    }

    private function producto(): ProductoInventario
    {
        $p = ProductoInventario::activos()->whereNotNull('CODIGO')->where('CODIGO', '!=', '')->orderBy('ID_PRODUCTO')->first();
        $this->assertNotNull($p, 'Hace falta un producto activo con código.');
        return $p;
    }

    /** Lo que pregunta la PC: sin sesión, con la llave en la cabecera. */
    private function preguntar(string $canal, ?int $desde = null)
    {
        $url = route('lector-remoto.pendientes', $desde === null ? [] : ['desde' => $desde]);
        return $this->getJson($url, ['X-Lector-Canal' => $canal]);
    }

    public function test_lo_que_lee_el_telefono_le_llega_a_la_pc_una_sola_vez(): void
    {
        $u = $this->crearUsuario();
        $p = $this->producto();
        $canal = LectorRemoto::canal($u);

        // La primera pregunta de la pantalla no trae nada: solo dice desde dónde seguir.
        $r = $this->preguntar($canal)->assertOk()->json();
        $this->assertSame([], $r['items']);
        $this->assertFalse($r['activo']);
        $desde = $r['desde'];

        $j = $this->actingAs($u)->postJson(route('almacen.lector-remoto.enviar'), ['codigo' => ' ' . $p->CODIGO . ' '])
            ->assertOk()->json();
        $this->assertTrue($j['found']);
        $this->assertSame($p->ID_PRODUCTO, $j['producto']['id']);
        $this->assertTrue($j['hay_pc'], 'La PC acaba de preguntar: el teléfono no debe avisar que no hay ninguna.');

        $r = $this->preguntar($canal, $desde)->assertOk()->json();
        $this->assertCount(1, $r['items']);
        $this->assertSame($p->CODIGO, $r['items'][0]['producto']['codigo']);
        $this->assertTrue($r['activo'], 'Enviar marca el teléfono como activo: la PC pasa a preguntar rápido.');

        // Con el desde nuevo, lo mismo no vuelve a llegar.
        $this->assertSame([], $this->preguntar($canal, $r['desde'])->json('items'));
    }

    public function test_codigo_que_no_existe_no_llega_a_la_pc(): void
    {
        $u = $this->crearUsuario();
        $canal = LectorRemoto::canal($u);
        $desde = $this->preguntar($canal)->json('desde');

        $j = $this->actingAs($u)->postJson(route('almacen.lector-remoto.enviar'), ['codigo' => 'NO-EXISTE-' . Str::random(10)])
            ->assertOk()->json();
        $this->assertFalse($j['found']);
        $this->assertStringContainsString('No existe un producto activo', $j['message']);
        $this->assertSame([], $this->preguntar($canal, $desde)->json('items'));

        $this->actingAs($u)->postJson(route('almacen.lector-remoto.enviar'), ['codigo' => '  '])->assertStatus(422);
    }

    public function test_abrir_y_cerrar_la_camara_cambia_el_ritmo_de_la_pc(): void
    {
        $u = $this->crearUsuario();
        $canal = LectorRemoto::canal($u);

        $this->actingAs($u)->postJson(route('almacen.lector-remoto.estado'), ['activo' => true])->assertOk();
        $this->assertTrue($this->preguntar($canal)->json('activo'));

        $this->actingAs($u)->postJson(route('almacen.lector-remoto.estado'), ['activo' => false])->assertOk();
        $this->assertFalse($this->preguntar($canal)->json('activo'));
    }

    public function test_cada_usuario_solo_ve_su_buzon(): void
    {
        $a = $this->crearUsuario();
        $b = $this->crearUsuario();
        $canalB = LectorRemoto::canal($b);
        $desde = $this->preguntar($canalB)->json('desde');

        $this->actingAs($a)->postJson(route('almacen.lector-remoto.enviar'), ['codigo' => $this->producto()->CODIGO])->assertOk();
        $this->assertSame([], $this->preguntar($canalB, $desde)->json('items'));
    }

    public function test_llave_falsa_vencida_o_de_clave_vieja_no_entra(): void
    {
        $u = $this->crearUsuario();
        $canal = LectorRemoto::canal($u);
        $this->preguntar($canal)->assertOk();

        $this->getJson(route('lector-remoto.pendientes'))->assertForbidden();
        $this->preguntar('basura')->assertForbidden();
        [$id, $caduca, $firma] = explode('.', $canal);
        $this->preguntar($id . '.' . ($caduca + 3600) . '.' . $firma)->assertForbidden();   // caducidad alargada a mano
        $otro = $this->crearUsuario();
        $this->preguntar($otro->ID_USUARIO . '.' . $caduca . '.' . $firma)->assertForbidden(); // llave de otro

        $this->travel(13)->hours();
        $this->preguntar($canal)->assertForbidden();
        $this->travelBack();

        $u->establecerClave('Otra#654321');
        $u->save();
        $this->preguntar($canal)->assertForbidden();
    }

    public function test_preguntar_no_abre_ni_renueva_sesion(): void
    {
        $r = $this->preguntar(LectorRemoto::canal($this->crearUsuario()))->assertOk();
        $this->assertNull($r->headers->get('Set-Cookie'), 'La pregunta de la PC no debe tocar la sesión.');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
    }

    public function test_misma_cuenta_en_dos_equipos_y_el_cambio_de_clave_cierra_las_dos(): void
    {
        $u = $this->crearUsuario();
        $entrar = fn () => $this->postJson('/', ['login_identifier' => $u->CORREO_ELECTRONICO, 'password' => self::CLAVE]);

        // PC
        $entrar()->assertOk();
        $tokenPc = session('current_session_token');
        $this->assertNotEmpty($tokenPc);

        // Teléfono: otro navegador, otra sesión
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $entrar()->assertOk();
        $tokenTel = session('current_session_token');
        $this->assertSame($tokenPc, $tokenTel, 'Entrar en otro equipo no debe cambiar el token de la cuenta.');

        // La sesión de la PC sigue sirviendo.
        $url = route('almacen.buscar-codigo', ['codigo' => $this->producto()->CODIGO]);
        $this->actingAs($u->fresh())->withSession(['current_session_token' => $tokenPc])->getJson($url)->assertOk();

        // Cambiar la clave saca a TODAS las sesiones con el motivo real.
        $u = $u->fresh();
        $u->establecerClave('Otra#654321');
        $u->save();
        $this->actingAs($u->fresh())->withSession(['current_session_token' => $tokenPc])->getJson($url)
            ->assertStatus(401)->assertJson(['motivo' => 'clave_cambiada']);

        // Y al entrar con la nueva hay un token nuevo: las sesiones viejas siguen fuera.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->postJson('/', ['login_identifier' => $u->CORREO_ELECTRONICO, 'password' => 'Otra#654321'])->assertOk();
        $this->assertNotSame($tokenPc, $u->fresh()->SESSION_TOKEN);
        $this->actingAs($u->fresh())->withSession(['current_session_token' => $tokenTel])->getJson($url)
            ->assertStatus(401)->assertJson(['motivo' => 'clave_cambiada']);
    }
}
