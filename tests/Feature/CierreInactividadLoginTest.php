<?php

namespace Tests\Feature;

use Tests\MySqlTestCase;

/**
 * SystemController::loginPage: salir (window.salirDelSistema: el cierre por inactividad de
 * partials/session_timeout y el botón «Salir») termina en "/?aviso=inactividad" o
 * "/?aviso=salida". Con una sesión abierta el servidor NO la cierra: puede ser la nueva, si se
 * entró desde otra pestaña mientras esta salía ("se abre el menú y se cierra"). Da el login sin
 * mandar al menú, y su JS cierra la del token que dejó salirDelSistema (POST /logout, abajo).
 */
class CierreInactividadLoginTest extends MySqlTestCase
{
    public function test_llegar_del_cierre_por_inactividad_da_el_login_sin_cerrar_la_sesion_abierta(): void
    {
        $this->actingAs($this->superAdminGlobal())
            ->get('/?aviso=inactividad', ['Sec-Fetch-Site' => 'same-origin'])
            ->assertOk()->assertViewIs('auth.inicio_sesion');
        $this->assertAuthenticated();
    }

    public function test_llegar_de_salir_tampoco_la_cierra_por_get(): void
    {
        $this->actingAs($this->superAdminGlobal())
            ->get('/?aviso=salida', ['Sec-Fetch-Site' => 'same-origin'])
            ->assertOk()->assertViewIs('auth.inicio_sesion');
        $this->assertAuthenticated();
    }

    public function test_con_cambios_pendientes_en_la_bandeja_tampoco(): void
    {
        $this->actingAs($this->superAdminGlobal())
            ->get('/?aviso=inactividad_pendientes', ['Sec-Fetch-Site' => 'same-origin'])
            ->assertOk()->assertViewIs('auth.inicio_sesion');
        $this->assertAuthenticated();
    }

    public function test_un_aviso_en_forma_de_lista_no_rompe_el_login(): void
    {
        $this->actingAs($this->superAdminGlobal())
            ->get('/?aviso[]=salida', ['Sec-Fetch-Site' => 'same-origin'])
            ->assertRedirect(route('menu'));
        $this->assertAuthenticated();
    }

    public function test_sin_el_aviso_quien_tiene_sesion_sigue_yendo_al_menu(): void
    {
        $this->actingAs($this->superAdminGlobal())->get('/')->assertRedirect(route('menu'));
        $this->assertAuthenticated();
    }

    // POST /logout con X-Solo-Sesion (cierre por inactividad y reintentos del login): solo
    // cierra la sesión de ese token. Iniciar sesión lo cambia, así que un cierre que llega
    // tarde no saca a quien ya volvió a entrar.
    public function test_el_cierre_automatico_cierra_su_propia_sesion(): void
    {
        $this->actingAs($this->superAdminGlobal())->withSession(['_token' => 'token-de-esta-sesion'])
            ->postJson('/logout', [], ['X-Solo-Sesion' => 'token-de-esta-sesion'])
            ->assertOk()->assertJson(['cerrada' => true]);
        $this->assertGuest();
    }

    public function test_un_cierre_atrasado_no_saca_la_sesion_nueva(): void
    {
        $this->actingAs($this->superAdminGlobal())->withSession(['_token' => 'token-de-la-sesion-nueva'])
            ->postJson('/logout', [], ['X-Solo-Sesion' => 'token-de-la-sesion-vieja'])
            ->assertOk()->assertJson(['cerrada' => false]);
        $this->assertAuthenticated();
    }

    public function test_salir_sin_la_cabecera_cierra_como_siempre(): void
    {
        $this->actingAs($this->superAdminGlobal())->withSession(['_token' => 'cualquiera'])
            ->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }
}
