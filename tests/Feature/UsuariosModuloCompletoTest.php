<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\MySqlTestCase;

/**
 * /admin/usuarios de punta a punta, varias vueltas seguidas sobre la base real (la
 * transacción lo revierte): listado y sus filtros, crear, editar, cambiar clave, eliminar,
 * roles sin uso, Mi Perfil, y que nadie sin super.admin entre. Más los casos que fallaban:
 * frente asignado y bloqueado a la vez, casillas repetidas, frentes cerrados que se perdían
 * al guardar y borrar a quien creó un traspaso (error 500).
 */
class UsuariosModuloCompletoTest extends MySqlTestCase
{
    private function datos(array $extra = []): array
    {
        return $extra + [
            'NOMBRE_COMPLETO'      => 'prueba modulo usuarios',
            'CORREO_ELECTRONICO'   => 'prueba.modulo.' . uniqid() . '@cvidalsa27.com',
            'password'             => 'clave123',
            'ID_ROL'               => 'ROL DE PRUEBA MODULO',
            'NIVEL_ACCESO_EQUIPOS' => 1,
            'NIVEL_ACCESO_ALMACEN' => 1,
            'ESTATUS'              => 'ACTIVO',
            'ID_FRENTE_ASIGNADO'   => [],
            'PERMISOS'             => ['equipos.edit'],
        ];
    }

    private function frentesActivos(int $n): array
    {
        return DB::table('frentes_trabajo')->where('ESTATUS_FRENTE', 'ACTIVO')->whereNull('deleted_at')
            ->orderBy('ID_FRENTE')->limit($n)->pluck('ID_FRENTE')->all();
    }

    public function test_todas_las_funciones_varias_veces_seguidas(): void
    {
        $admin = $this->superAdminGlobal();
        $this->actingAs($admin);
        [$f1, $f2] = $this->frentesActivos(2);

        for ($vuelta = 1; $vuelta <= 3; $vuelta++) {
            // Listado: página, AJAX y cada filtro.
            $this->get(route('usuarios.index'))->assertOk()->assertSee('Usuarios');
            $this->getJson(route('usuarios.index', ['search' => 'a']))->assertOk()->assertJsonStructure(['html', 'pagination', 'count']);
            $this->getJson(route('usuarios.index', ['id_frente' => $f1]))->assertOk();
            $this->getJson(route('usuarios.index', ['id_rol' => $admin->ID_ROL]))->assertOk();
            $this->getJson(route('usuarios.index', ['nivel' => 'eq-1']))->assertOk();
            $this->getJson(route('usuarios.index', ['page' => 999]))->assertOk();

            // Crear.
            $this->get(route('usuarios.create'))->assertOk();
            $datos = $this->datos(['ID_FRENTE_ASIGNADO' => [$f1, $f1], 'PERMISOS' => ['equipos.edit', 'equipos.edit']]);
            $this->postJson(route('usuarios.store'), $datos)->assertOk()->assertJsonPath('success', true);
            $u = Usuario::where('CORREO_ELECTRONICO', $datos['CORREO_ELECTRONICO'])->firstOrFail();
            $this->assertSame('Prueba Modulo Usuarios', $u->NOMBRE_COMPLETO);
            $this->assertSame((string) $f1, $u->getRawOriginal('ID_FRENTE_ASIGNADO'), 'sin frentes repetidos');
            $this->assertSame(['equipos.edit'], $u->PERMISOS, 'sin permisos repetidos');
            $this->assertSame(1, (int) $u->REQUIERE_CAMBIO_CLAVE);

            // El mismo correo otra vez, otro dominio, y un frente asignado y bloqueado a la vez.
            $this->postJson(route('usuarios.store'), $this->datos(['CORREO_ELECTRONICO' => $datos['CORREO_ELECTRONICO']]))
                ->assertStatus(422)->assertJsonValidationErrors('CORREO_ELECTRONICO');
            $this->postJson(route('usuarios.store'), $this->datos(['CORREO_ELECTRONICO' => 'x' . uniqid() . '@gmail.com']))
                ->assertStatus(422)->assertJsonValidationErrors('CORREO_ELECTRONICO');
            $this->postJson(route('usuarios.store'), $this->datos(['ID_FRENTE_ASIGNADO' => [$f1], 'ID_FRENTE_BLOQUEADO' => [$f1]]))
                ->assertStatus(422)->assertJsonValidationErrors('ID_FRENTE_BLOQUEADO.0');
            // LOCAL (Equipos o Almacén) sin ningún frente: no vería nada.
            $this->postJson(route('usuarios.store'), $this->datos(['NIVEL_ACCESO_ALMACEN' => 2]))
                ->assertStatus(422)->assertJsonValidationErrors('ID_FRENTE_ASIGNADO');
            $this->postJson(route('usuarios.store'), $this->datos(['NIVEL_ACCESO_EQUIPOS' => 2]))
                ->assertStatus(422)->assertJsonValidationErrors('ID_FRENTE_ASIGNADO');

            // Editar (con la clave): vuelve al listado con sus filtros.
            $this->get(route('usuarios.edit', [$u->ID_USUARIO, 'return' => '/admin/usuarios?search=x']))->assertOk();
            $this->putJson(route('usuarios.update', $u->ID_USUARIO), $this->datos([
                'CORREO_ELECTRONICO' => $datos['CORREO_ELECTRONICO'],
                'NOMBRE_COMPLETO'    => 'prueba editada',
                'NIVEL_ACCESO_ALMACEN' => 2,
                'ID_FRENTE_ASIGNADO' => [$f1],
                'ID_FRENTE_BLOQUEADO' => [$f2],
                'password'           => 'otraclave1',
                'return_url'         => '/admin/usuarios?search=x',
            ]))->assertOk()->assertJsonPath('redirect', '/admin/usuarios?search=x');
            $u->refresh();
            $this->assertSame('Prueba Editada', $u->NOMBRE_COMPLETO);
            $this->assertSame((string) $f2, $u->getRawOriginal('ID_FRENTE_BLOQUEADO'));
            $this->assertTrue(Hash::check('otraclave1', $u->PASSWORD_HASH));

            // Roles sin uso.
            $this->getJson(route('usuarios.unused-roles'))->assertOk();

            // Eliminar.
            $this->deleteJson(route('usuarios.destroy', $u->ID_USUARIO))->assertOk()->assertJsonPath('success', true);
            $this->assertNull(Usuario::find($u->ID_USUARIO));
        }

        $this->deleteJson(route('usuarios.delete-unused-roles'))->assertOk();
        $this->assertNull(Role::where('NOMBRE_ROL', 'ROL DE PRUEBA MODULO')->first(), 'el rol de la prueba quedó sin uso y se limpió');
    }

    public function test_rol_nuevo_que_empieza_por_numero_y_busqueda_exacta_por_correo(): void
    {
        $this->actingAs($this->superAdminGlobal());
        $datos = $this->datos(['ID_ROL' => '2do turno prueba']);
        $this->postJson(route('usuarios.store'), $datos)->assertOk();
        $u = Usuario::where('CORREO_ELECTRONICO', $datos['CORREO_ELECTRONICO'])->firstOrFail();
        $this->assertSame('2DO TURNO PRUEBA', Role::find($u->ID_ROL)->NOMBRE_ROL, 'no el rol con ID 2');

        // Otro correo que CONTIENE el primero: el correo completo trae solo el suyo.
        $parecido = $this->datos(['CORREO_ELECTRONICO' => 'x' . $datos['CORREO_ELECTRONICO']]);
        $this->postJson(route('usuarios.store'), $parecido)->assertOk();
        $this->getJson(route('usuarios.index', ['search' => $datos['CORREO_ELECTRONICO']]))->assertOk()->assertJsonPath('count', 1);
    }

    public function test_al_editar_no_se_pierden_los_frentes_cerrados(): void
    {
        $this->actingAs($this->superAdminGlobal());
        [$f1] = $this->frentesActivos(1);
        $datos = $this->datos(['ID_FRENTE_ASIGNADO' => [$f1]]);
        $this->postJson(route('usuarios.store'), $datos)->assertOk();
        $u = Usuario::where('CORREO_ELECTRONICO', $datos['CORREO_ELECTRONICO'])->firstOrFail();

        // El frente se cierra después: la ficha lo sigue ofreciendo marcado.
        DB::table('frentes_trabajo')->where('ID_FRENTE', $f1)->update(['ESTATUS_FRENTE' => 'FINALIZADO']);
        $this->get(route('usuarios.edit', $u->ID_USUARIO))->assertOk()
            ->assertSee('name="ID_FRENTE_ASIGNADO[]"', false)
            ->assertSee('value="' . $f1 . '"', false);
    }

    public function test_mi_usuario_pide_la_clave_actual(): void
    {
        $this->actingAs($this->superAdminGlobal());
        $datos = $this->datos();
        $this->postJson(route('usuarios.store'), $datos)->assertOk();
        $u = Usuario::where('CORREO_ELECTRONICO', $datos['CORREO_ELECTRONICO'])->firstOrFail();
        $u->forceFill(['REQUIERE_CAMBIO_CLAVE' => 0])->save();
        $this->actingAs($u);

        $nueva = ['password' => 'nuevaclave9', 'password_confirmation' => 'nuevaclave9'];
        $this->putJson(route('usuarios.actualizarMiClave'), $nueva)->assertStatus(422)->assertJsonValidationErrors('clave_actual');
        $this->putJson(route('usuarios.actualizarMiClave'), $nueva + ['clave_actual' => 'equivocada'])
            ->assertStatus(422)->assertJsonValidationErrors('clave_actual');
        $this->assertTrue(Hash::check('clave123', $u->fresh()->PASSWORD_HASH), 'sin la actual no cambia');

        $this->putJson(route('usuarios.actualizarMiClave'), $nueva + ['clave_actual' => 'clave123'])->assertOk();
        $this->assertTrue(Hash::check('nuevaclave9', $u->fresh()->PASSWORD_HASH));
    }

    public function test_super_admin_cambia_la_clave_de_otro_sin_saberla_pero_la_suya_pide_la_actual(): void
    {
        $admin = $this->superAdminGlobal();
        $this->actingAs($admin);
        $datos = $this->datos();
        $this->postJson(route('usuarios.store'), $datos)->assertOk();
        $otro = Usuario::where('CORREO_ELECTRONICO', $datos['CORREO_ELECTRONICO'])->firstOrFail();

        // La de otro: sin clave actual.
        $this->putJson(route('usuarios.update', $otro->ID_USUARIO), $this->datos(['CORREO_ELECTRONICO' => $datos['CORREO_ELECTRONICO'], 'password' => 'reiniciada1']))
            ->assertOk();
        $this->assertTrue(Hash::check('reiniciada1', $otro->fresh()->PASSWORD_HASH));

        // La suya desde la ficha: sin la actual, no.
        $propia = $this->datos([
            'NOMBRE_COMPLETO' => $admin->NOMBRE_COMPLETO, 'CORREO_ELECTRONICO' => $admin->CORREO_ELECTRONICO,
            'ID_ROL' => (string) $admin->ID_ROL, 'NIVEL_ACCESO_EQUIPOS' => 1, 'NIVEL_ACCESO_ALMACEN' => 1,
            'PERMISOS' => $admin->PERMISOS, 'password' => 'mipropia99',
        ]);
        $hashAntes = $admin->fresh()->PASSWORD_HASH;
        $this->putJson(route('usuarios.update', $admin->ID_USUARIO), $propia)->assertStatus(422)->assertJsonValidationErrors('clave_actual');
        $this->assertSame($hashAntes, $admin->fresh()->PASSWORD_HASH);
        $this->get(route('usuarios.edit', $admin->ID_USUARIO))->assertOk()->assertSee('name="clave_actual"', false);
        $this->get(route('usuarios.edit', $otro->ID_USUARIO))->assertOk()->assertDontSee('name="clave_actual"', false);
    }

    public function test_quien_creo_un_traspaso_no_se_elimina_y_no_da_error_500(): void
    {
        $this->actingAs($this->superAdminGlobal());
        $id = DB::table('traspasos')->value('ID_USUARIO_CREO');
        if (!$id) {
            $this->markTestSkipped('No hay traspasos en la base.');
        }
        $this->deleteJson(route('usuarios.destroy', $id))->assertStatus(422)->assertJsonPath('success', false);
        $this->assertNotNull(Usuario::find($id));
    }

    public function test_mi_perfil_y_nadie_sin_super_admin_entra(): void
    {
        $normal = Usuario::where('ESTATUS', 'ACTIVO')->where('REQUIERE_CAMBIO_CLAVE', 0)->whereNotNull('PERMISOS')->get()
            ->first(fn ($u) => !$u->can('super.admin'));
        $this->assertNotNull($normal);
        $this->actingAs($normal);

        $this->get(route('usuarios.miPerfil'))->assertOk()->assertSee('name="clave_actual"', false);
        $this->putJson(route('usuarios.actualizarMiClave'), ['password' => 'abc', 'password_confirmation' => 'abc'])
            ->assertStatus(422);

        $this->get(route('usuarios.index'))->assertForbidden();
        $this->get(route('usuarios.create'))->assertForbidden();
        $this->postJson(route('usuarios.store'), $this->datos())->assertForbidden();
        $this->get(route('usuarios.edit', $normal->ID_USUARIO))->assertForbidden();
        $this->putJson(route('usuarios.update', $normal->ID_USUARIO), $this->datos())->assertForbidden();
        $this->deleteJson(route('usuarios.destroy', $normal->ID_USUARIO))->assertForbidden();
        $this->getJson(route('usuarios.unused-roles'))->assertForbidden();
        $this->deleteJson(route('usuarios.delete-unused-roles'))->assertForbidden();
    }
}
