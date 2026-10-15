<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * /admin/usuarios: filtros y vuelta al listado.
 *  - El filtro de Frente encuentra a los usuarios con VARIOS frentes (ID_FRENTE_ASIGNADO es CSV).
 *  - Editar vuelve al listado con los mismos filtros (?return= saneado contra open-redirect).
 *  - Eliminar por AJAX responde JSON (la tabla se recarga sola con sus filtros).
 *  - Una página que ya no existe muestra la última, no una tabla vacía.
 * Corre en la transacción de la prueba.
 */
class UsuariosListadoTest extends MySqlTestCase
{
    private function crearUsuario(?string $frentes = null): Usuario
    {
        $u = new Usuario();
        $u->NOMBRE_COMPLETO = 'PRUEBA LISTADO ' . strtoupper(Str::random(6));
        $u->CORREO_ELECTRONICO = 'prueba.listado.' . strtolower(Str::random(8)) . '@cvidalsa27.com';
        $u->PASSWORD_HASH = Hash::make('Clave#123456');
        $u->ID_ROL = DB::table('roles')->value('ID_ROL');
        $u->NIVEL_ACCESO_EQUIPOS = 2;
        $u->NIVEL_ACCESO_ALMACEN = 2;
        $u->ESTATUS = 'ACTIVO';
        $u->setAttribute('ID_FRENTE_ASIGNADO', $frentes);
        $u->save();

        return $u;
    }

    public function test_filtro_de_frente_encuentra_usuarios_con_varios_frentes(): void
    {
        $frentes = DB::table('frentes_trabajo')->where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('ID_FRENTE')->limit(2)->pluck('ID_FRENTE')->all();
        $this->assertCount(2, $frentes, 'Hacen falta dos frentes activos.');
        $u = $this->crearUsuario($frentes[0] . ',' . $frentes[1]);
        $admin = $this->superAdminGlobal();

        foreach ($frentes as $f) {
            $ids = collect($this->actingAs($admin)->getJson(route('usuarios.index', ['id_frente' => $f, 'search' => $u->CORREO_ELECTRONICO]))
                ->assertOk()->json('html'))->all();
            $this->assertStringContainsString($u->NOMBRE_COMPLETO, $ids[0], "Sale filtrando por el frente $f.");
        }
    }

    public function test_buscador_no_trata_porcentaje_ni_guion_bajo_como_comodines(): void
    {
        $r = $this->actingAs($this->superAdminGlobal())->getJson(route('usuarios.index', ['search' => '%_%']))->assertOk();
        $this->assertSame(0, $r->json('count'));
    }

    public function test_editar_vuelve_al_listado_con_sus_filtros(): void
    {
        $u = $this->crearUsuario();
        $admin = $this->superAdminGlobal();
        $lista = '/admin/usuarios?search=prueba&id_rol=3&page=2';

        $html = (string) $this->actingAs($admin)->get(route('usuarios.edit', ['usuario' => $u->ID_USUARIO, 'return' => $lista]))->assertOk()->getContent();
        $this->assertStringContainsString('name="return_url" value="' . e($lista) . '"', $html);
        $this->assertStringContainsString('href="' . e($lista) . '" class="btn-primary-maquinaria btn-secondary', $html, 'Cancelar vuelve con los filtros.');

        $this->actingAs($admin)->putJson(route('usuarios.update', $u->ID_USUARIO), [
            'NOMBRE_COMPLETO' => $u->NOMBRE_COMPLETO, 'CORREO_ELECTRONICO' => $u->CORREO_ELECTRONICO,
            'ID_ROL' => (string) $u->ID_ROL, 'NIVEL_ACCESO_EQUIPOS' => 2, 'NIVEL_ACCESO_ALMACEN' => 2, 'ESTATUS' => 'ACTIVO',
            'return_url' => $lista,
        ])->assertOk()->assertJson(['redirect' => $lista]);
    }

    public function test_el_destino_de_vuelta_no_sale_del_listado_de_usuarios(): void
    {
        $u = $this->crearUsuario();
        $admin = $this->superAdminGlobal();
        foreach (['https://malo.example/admin/usuarios', '//malo.example/admin/usuarios', '/admin/equipos?x=1', 'javascript:alert(1)'] as $malo) {
            $html = (string) $this->actingAs($admin)->get(route('usuarios.edit', ['usuario' => $u->ID_USUARIO, 'return' => $malo]))->assertOk()->getContent();
            $this->assertStringContainsString('name="return_url" value="' . e(route('usuarios.index')) . '"', $html, "Rechaza $malo.");
        }
    }

    public function test_el_lapiz_lleva_el_listado_actual_como_return(): void
    {
        $html = (string) $this->actingAs($this->superAdminGlobal())->getJson(route('usuarios.index', ['search' => 'a']))->assertOk()->json('html');
        $this->assertStringContainsString('/edit?return=' . urlencode('/admin/usuarios?search=a'), html_entity_decode($html));
    }

    public function test_eliminar_por_ajax_responde_json(): void
    {
        $u = $this->crearUsuario();
        $this->actingAs($this->superAdminGlobal())->deleteJson(route('usuarios.destroy', $u->ID_USUARIO))
            ->assertOk()->assertJson(['success' => true]);
        $this->assertNull(Usuario::find($u->ID_USUARIO));
    }

    public function test_pagina_que_ya_no_existe_muestra_la_ultima(): void
    {
        $r = $this->actingAs($this->superAdminGlobal())->getJson(route('usuarios.index', ['page' => 999]))->assertOk();
        $this->assertGreaterThan(0, $r->json('count'));
        $this->assertStringNotContainsString('No se encontraron usuarios', (string) $r->json('html'));
    }
}
