<?php

namespace Tests\Feature;

use App\Http\Controllers\UserController;
use App\Models\Role;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Crear y editar un usuario guardan igual el rol (se crea si lo escribieron nuevo), los dos
 * niveles de acceso, el estatus, los frentes asignados y bloqueados y los permisos: lo hace
 * UserController::asignarAccesos para los dos. Corre en la transacción de la prueba.
 */
class UsuarioAccesosTest extends MySqlTestCase
{
    public function test_crear_y_editar_guardan_rol_niveles_frentes_y_permisos(): void
    {
        $admin   = $this->superAdminGlobal();
        $frentes = DB::table('frentes_trabajo')->orderBy('ID_FRENTE')->limit(3)->pluck('ID_FRENTE')->map(fn ($v) => (string) $v)->all();
        $this->assertCount(3, $frentes, 'Hacen falta tres frentes.');
        $permiso = array_key_first(UserController::availablePermissions());
        $correo  = 'prueba.accesos.' . strtolower(Str::random(8)) . '@cvidalsa27.com';
        $rolNuevo = 'ROL PRUEBA ' . strtoupper(Str::random(6));

        $this->actingAs($admin)->postJson('/admin/usuarios', [
            'NOMBRE_COMPLETO'      => 'prueba accesos',
            'CORREO_ELECTRONICO'   => $correo,
            'password'             => 'Clave#123456',
            'ID_ROL'               => strtolower($rolNuevo),
            'NIVEL_ACCESO_EQUIPOS' => 2,
            'NIVEL_ACCESO_ALMACEN' => 1,
            'ESTATUS'              => 'ACTIVO',
            'ID_FRENTE_ASIGNADO'   => [$frentes[0], $frentes[1]],
            'ID_FRENTE_BLOQUEADO'  => [$frentes[2]],
            'PERMISOS'             => [$permiso],
        ])->assertOk()->assertJson(['success' => true]);

        $u = DB::table('usuarios')->where('CORREO_ELECTRONICO', $correo)->first();
        $this->assertNotNull($u);
        $rol = Role::where('NOMBRE_ROL', $rolNuevo)->first();
        $this->assertNotNull($rol, 'Un rol escrito nuevo se crea en mayúsculas.');
        $this->assertSame((int) $rol->ID_ROL, (int) $u->ID_ROL);
        $this->assertSame(2, (int) $u->NIVEL_ACCESO_EQUIPOS);
        $this->assertSame(1, (int) $u->NIVEL_ACCESO_ALMACEN);
        $this->assertSame('ACTIVO', $u->ESTATUS);
        $this->assertSame($frentes[0] . ',' . $frentes[1], $u->ID_FRENTE_ASIGNADO);
        $this->assertSame($frentes[2], $u->ID_FRENTE_BLOQUEADO);
        $this->assertStringContainsString($permiso, (string) $u->PERMISOS);
        $this->assertSame(1, (int) $u->REQUIERE_CAMBIO_CLAVE, 'Al crear, pide cambiar la clave.');

        // Editar: rol existente por id, niveles al revés, sin frentes, sin permisos.
        $this->actingAs($admin)->putJson('/admin/usuarios/' . $u->ID_USUARIO, [
            'NOMBRE_COMPLETO'      => 'prueba accesos',
            'CORREO_ELECTRONICO'   => $correo,
            'ID_ROL'               => (string) $rol->ID_ROL,
            'NIVEL_ACCESO_EQUIPOS' => 1,
            'NIVEL_ACCESO_ALMACEN' => 2,
            'ESTATUS'              => 'INACTIVO',
        ])->assertOk()->assertJson(['success' => true]);

        $e = DB::table('usuarios')->where('ID_USUARIO', $u->ID_USUARIO)->first();
        $this->assertSame((int) $rol->ID_ROL, (int) $e->ID_ROL, 'Con el id de un rol existente no se crea otro.');
        $this->assertSame(1, (int) $e->NIVEL_ACCESO_EQUIPOS);
        $this->assertSame(2, (int) $e->NIVEL_ACCESO_ALMACEN);
        $this->assertSame('INACTIVO', $e->ESTATUS);
        $this->assertNull($e->ID_FRENTE_ASIGNADO);
        $this->assertNull($e->ID_FRENTE_BLOQUEADO);
        $this->assertSame(1, Role::where('NOMBRE_ROL', $rolNuevo)->count());
    }

    /** Si el admin le reinicia la clave a otro, al entrar con ella se le pide cambiarla, como al crearlo. */
    public function test_reiniciar_la_clave_de_otro_pide_cambiarla_al_entrar(): void
    {
        $admin  = $this->superAdminGlobal();
        $correo = 'prueba.reinicio.' . strtolower(Str::random(8)) . '@cvidalsa27.com';
        $datos  = ['NOMBRE_COMPLETO' => 'prueba reinicio', 'CORREO_ELECTRONICO' => $correo, 'ID_ROL' => 'ROL PRUEBA REINICIO',
                   'NIVEL_ACCESO_EQUIPOS' => 1, 'NIVEL_ACCESO_ALMACEN' => 1, 'ESTATUS' => 'ACTIVO'];
        $this->actingAs($admin)->postJson('/admin/usuarios', $datos + ['password' => '123456'])->assertOk();
        $id = DB::table('usuarios')->where('CORREO_ELECTRONICO', $correo)->value('ID_USUARIO');
        $requiere = fn () => (int) DB::table('usuarios')->where('ID_USUARIO', $id)->value('REQUIERE_CAMBIO_CLAVE');
        DB::table('usuarios')->where('ID_USUARIO', $id)->update(['REQUIERE_CAMBIO_CLAVE' => 0]);   // ya la cambió

        // Editar sin clave no lo toca.
        $this->actingAs($admin)->putJson('/admin/usuarios/' . $id, $datos)->assertOk();
        $this->assertSame(0, $requiere());

        // Reiniciarle la clave sí: la próxima vez que entre, cambia la clave.
        $this->actingAs($admin)->putJson('/admin/usuarios/' . $id, $datos + ['password' => '123456'])->assertOk();
        $this->assertSame(1, $requiere());
        $this->actingAs(Usuario::find($id))->get('/menu')->assertRedirect(route('password.change'));
    }

    /** Un usuario normal (sin super.admin), creado en la transacción de la prueba. */
    private function usuarioNormal(array $extra = []): Usuario
    {
        $id = DB::table('usuarios')->insertGetId($extra + [
            'NOMBRE_COMPLETO' => 'Prueba Normal', 'CORREO_ELECTRONICO' => 'prueba.normal.' . strtolower(Str::random(8)) . '@cvidalsa27.com',
            'PASSWORD_HASH' => bcrypt('123456'), 'ID_ROL' => Role::value('ID_ROL'), 'NIVEL_ACCESO_EQUIPOS' => 1,
            'NIVEL_ACCESO_ALMACEN' => 1, 'ESTATUS' => 'ACTIVO', 'REQUIERE_CAMBIO_CLAVE' => 0, 'PERMISOS' => 'equipos.edit',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return Usuario::find($id);
    }

    /** «Limpiar roles inactivos» lo ve y lo usa solo quien administra usuarios. */
    public function test_limpiar_roles_solo_lo_usa_el_super_admin(): void
    {
        $normal = $this->usuarioNormal();
        $this->actingAs($normal)->getJson('/admin/usuarios/roles/unused')->assertForbidden();
        $this->actingAs($normal)->deleteJson('/admin/usuarios/roles/unused')->assertForbidden();
        $this->actingAs($this->superAdminGlobal())->getJson('/admin/usuarios/roles/unused')->assertOk();
    }

    /** El admin no puede dejarse fuera: ni eliminarse, ni desactivarse, ni quitarse super.admin. */
    public function test_el_admin_no_puede_eliminarse_ni_dejarse_sin_acceso(): void
    {
        $admin = $this->superAdminGlobal();
        $datos = ['NOMBRE_COMPLETO' => $admin->NOMBRE_COMPLETO, 'CORREO_ELECTRONICO' => 'prueba.yo.' . strtolower(Str::random(8)) . '@cvidalsa27.com',
                  'ID_ROL' => (string) $admin->ID_ROL, 'NIVEL_ACCESO_EQUIPOS' => 1, 'NIVEL_ACCESO_ALMACEN' => 1, 'ESTATUS' => 'ACTIVO',
                  'PERMISOS' => ['super.admin']];

        $this->actingAs($admin)->deleteJson('/admin/usuarios/' . $admin->ID_USUARIO)->assertStatus(422);
        $this->actingAs($admin)->putJson('/admin/usuarios/' . $admin->ID_USUARIO, ['PERMISOS' => ['equipos.edit']] + $datos)
            ->assertStatus(422)->assertJsonValidationErrors('PERMISOS');
        $this->actingAs($admin)->putJson('/admin/usuarios/' . $admin->ID_USUARIO, ['ESTATUS' => 'INACTIVO'] + $datos)
            ->assertStatus(422)->assertJsonValidationErrors('ESTATUS');
        $this->assertNotNull(Usuario::find($admin->ID_USUARIO));
    }

    /** Cambiar la clave propia deja ESTA sesión abierta y cierra las de otros equipos. */
    public function test_cambiar_su_clave_sigue_dentro_y_saca_a_los_otros_equipos(): void
    {
        $u = $this->usuarioNormal(['SESSION_TOKEN' => 'token-viejo']);
        $this->actingAs($u)->withSession(['current_session_token' => 'token-viejo'])
            ->putJson('/admin/usuarios/mi-perfil', ['password' => 'Nueva#2026', 'password_confirmation' => 'Nueva#2026'])
            ->assertOk()->assertJson(['success' => true]);

        $nuevo = DB::table('usuarios')->where('ID_USUARIO', $u->ID_USUARIO)->value('SESSION_TOKEN');
        $this->assertNotEmpty($nuevo);
        $this->assertNotSame('token-viejo', $nuevo);
        $this->assertSame($nuevo, session('current_session_token'), 'Esta sesión toma el token nuevo.');
        $this->actingAs(Usuario::find($u->ID_USUARIO))->get('/menu')->assertOk();

        // Otro equipo, con el token de antes: fuera.
        $this->actingAs(Usuario::find($u->ID_USUARIO))->withSession(['current_session_token' => 'token-viejo'])
            ->get('/menu')->assertRedirect('/?aviso=clave_cambiada');
    }

    /** Desactivado con la sesión abierta: sale en su próxima petición y se le dice por qué. */
    public function test_usuario_desactivado_sale_en_su_proxima_peticion(): void
    {
        $u = $this->usuarioNormal(['ESTATUS' => 'INACTIVO']);
        $this->actingAs($u)->get('/menu')->assertRedirect('/?aviso=usuario_inactivo');
        $this->actingAs(Usuario::find($u->ID_USUARIO))->getJson('/menu')->assertStatus(401)->assertJson(['motivo' => 'usuario_inactivo']);
    }
}
