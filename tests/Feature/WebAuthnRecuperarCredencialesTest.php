<?php

namespace Tests\Feature;

use App\Models\WebAuthnCredential;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Huella: el teléfono recupera del servidor los IDs de las huellas que ya tiene el usuario
 * (GET /webauthn/mis-credenciales) en vez de volver a pedir vincularla cada vez que pierde
 * sus datos guardados. Y el login con clave sigue avisando que puede ofrecerse el registro.
 * Corre en la transacción de la prueba.
 */
class WebAuthnRecuperarCredencialesTest extends MySqlTestCase
{
    private function usuario(): \App\Models\Usuario
    {
        $u = new \App\Models\Usuario();
        $u->NOMBRE_COMPLETO = 'PRUEBA HUELLA';
        $u->CORREO_ELECTRONICO = 'prueba.huella.' . strtolower(Str::random(8)) . '@cvidalsa27.com';
        $u->PASSWORD_HASH = Hash::make('Clave#123456');
        $u->ID_ROL = \Illuminate\Support\Facades\DB::table('roles')->value('ID_ROL');
        $u->NIVEL_ACCESO_EQUIPOS = 1;
        $u->NIVEL_ACCESO_ALMACEN = 1;
        $u->ESTATUS = 'ACTIVO';
        $u->REQUIERE_CAMBIO_CLAVE = 0;
        $u->save();

        return $u;
    }

    private function credencial(int $idUsuario, string $id): void
    {
        WebAuthnCredential::create([
            'ID_USUARIO' => $idUsuario, 'credential_id' => $id,
            'public_key_pem' => 'x', 'nombre_dispositivo' => 'Android', 'counter' => 0, 'created_at' => now(),
        ]);
    }

    public function test_devuelve_solo_las_huellas_del_usuario_que_entro(): void
    {
        $yo = $this->usuario();
        $otro = $this->usuario();
        $this->credencial($yo->ID_USUARIO, 'MIA-' . Str::random(10));
        $this->credencial($otro->ID_USUARIO, 'AJENA-' . Str::random(10));

        $ids = $this->actingAs($yo)->getJson('/webauthn/mis-credenciales')->assertOk()->json('credential_ids');

        $this->assertCount(1, $ids);
        $this->assertStringStartsWith('MIA-', $ids[0]);
    }

    public function test_sin_huellas_devuelve_lista_vacia(): void
    {
        $this->actingAs($this->usuario())->getJson('/webauthn/mis-credenciales')
            ->assertOk()->assertExactJson(['credential_ids' => []]);
    }

    public function test_sin_sesion_no_entrega_nada(): void
    {
        $r = $this->getJson('/webauthn/mis-credenciales');
        $this->assertContains($r->status(), [401, 302]);
        $this->assertNull($r->json('credential_ids'));
    }

    public function test_el_login_con_clave_sigue_funcionando_y_avisa_el_registro(): void
    {
        $u = $this->usuario();

        $this->postJson(route('login.post'), ['login_identifier' => $u->CORREO_ELECTRONICO, 'password' => 'Clave#123456'])
            ->assertOk()->assertJson(['success' => true]);
        $this->assertAuthenticatedAs($u);
        $this->assertTrue((bool) session('webauthn_prompt'), 'El menú recibe la marca para decidir si ofrece la huella.');
    }
}
