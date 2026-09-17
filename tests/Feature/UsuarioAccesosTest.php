<?php

namespace Tests\Feature;

use App\Http\Controllers\UserController;
use App\Models\Role;
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
}
