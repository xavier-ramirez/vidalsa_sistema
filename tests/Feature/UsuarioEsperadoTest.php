<?php

namespace Tests\Feature;

use App\Http\Middleware\UsuarioEsperado;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * La pestaña de un usuario no recibe los datos de otro que entró después en el mismo equipo
 * (App\Http\Middleware\UsuarioEsperado): la copia local (/sync), la navegación SPA y la bandeja
 * mandan de quién son, y si la sesión ya es de otro el servidor contesta 401 'otro_usuario'.
 */
class UsuarioEsperadoTest extends MySqlTestCase
{
    public function test_la_copia_local_de_otro_usuario_no_recibe_los_datos_de_esta_sesion(): void
    {
        $x = $this->usuario();
        $y = $this->usuario();

        $this->actingAs($y)->getJson('/sync/llave', [UsuarioEsperado::CABECERA => (string) $x->ID_USUARIO])
            ->assertUnauthorized()
            ->assertJson(['code' => 'otro_usuario'])
            ->assertJsonMissingPath('llave');
    }

    public function test_la_misma_persona_o_sin_cabecera_sigue_igual(): void
    {
        $y = $this->usuario();

        $this->actingAs($y)->getJson('/sync/llave', [UsuarioEsperado::CABECERA => (string) $y->ID_USUARIO])->assertOk();
        $this->actingAs($y)->getJson('/sync/llave')->assertOk();
        $this->actingAs($y)->getJson('/sync/llave', [UsuarioEsperado::CABECERA => ''])->assertOk();
    }

    public function test_la_navegacion_spa_no_pinta_la_pantalla_de_otro_usuario(): void
    {
        $x = $this->usuario();
        $y = $this->usuario();
        $spa = ['X-Requested-With' => 'XMLHttpRequest', 'X-SPA-Navigate' => '1', 'Accept' => 'text/html, application/xhtml+xml'];

        $r = $this->actingAs($y)->get('/menu', $spa + [UsuarioEsperado::CABECERA => (string) $x->ID_USUARIO]);
        $r->assertUnauthorized();
        $this->assertSame('otro_usuario', $r->json('code'));
        $this->assertStringNotContainsString('PRUEBA USUARIO ESPERADO', $r->getContent());

        $this->actingAs($y)->get('/menu', $spa + [UsuarioEsperado::CABECERA => (string) $y->ID_USUARIO])->assertOk();
    }

    public function test_sin_sesion_es_el_401_de_siempre(): void
    {
        $this->getJson('/sync/llave', [UsuarioEsperado::CABECERA => '123'])
            ->assertUnauthorized()
            ->assertJsonMissing(['code' => 'otro_usuario']);
    }

    private function usuario(): Usuario
    {
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO'       => 'PRUEBA USUARIO ESPERADO',
            'CORREO_ELECTRONICO'    => 'prueba.esperado.' . uniqid() . '@local.test',
            'PASSWORD_HASH'         => bcrypt('irrelevante'),
            'ID_ROL'                => DB::table('roles')->value('ID_ROL'),
            'NIVEL_ACCESO_EQUIPOS'  => 1,
            'NIVEL_ACCESO_ALMACEN'  => 1,
            'ESTATUS'               => 'ACTIVO',
            'REQUIERE_CAMBIO_CLAVE' => 0,
            'PERMISOS'              => 'super.admin',
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        return Usuario::find($id);
    }
}
