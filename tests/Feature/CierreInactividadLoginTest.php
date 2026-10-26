<?php

namespace Tests\Feature;

use Tests\MySqlTestCase;

/**
 * SystemController::loginPage: salir (window.salirDelSistema: el cierre por inactividad de
 * partials/session_timeout y el botón «Salir») termina en "/?aviso=inactividad" o
 * "/?aviso=salida". Si su POST /logout no llegó y la sesión sigue abierta, el login la cierra
 * en vez de devolverla al menú ("se cerró la sesión y se abrió"): tras un deploy el Service
 * Worker no tiene el login en caché y la petición llega al servidor.
 */
class CierreInactividadLoginTest extends MySqlTestCase
{
    public function test_llegar_del_cierre_por_inactividad_cierra_la_sesion_que_seguia_abierta(): void
    {
        $this->actingAs($this->superAdminGlobal())
            ->get('/?aviso=inactividad', ['Sec-Fetch-Site' => 'same-origin'])
            ->assertOk()->assertViewIs('auth.inicio_sesion');
        $this->assertGuest();
    }

    public function test_salir_sin_red_tambien_cierra_la_sesion_al_llegar_al_login(): void
    {
        $this->actingAs($this->superAdminGlobal())
            ->get('/?aviso=salida', ['Sec-Fetch-Site' => 'same-origin'])
            ->assertOk()->assertViewIs('auth.inicio_sesion');
        $this->assertGuest();
    }

    public function test_con_cambios_pendientes_en_la_bandeja_tambien_la_cierra(): void
    {
        $this->actingAs($this->superAdminGlobal())
            ->get('/?aviso=inactividad_pendientes', ['Sec-Fetch-Site' => 'same-origin'])
            ->assertOk()->assertViewIs('auth.inicio_sesion');
        $this->assertGuest();
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

    public function test_un_enlace_de_otro_sitio_no_cierra_la_sesion(): void
    {
        $this->actingAs($this->superAdminGlobal())
            ->get('/?aviso=inactividad', ['Sec-Fetch-Site' => 'cross-site'])
            ->assertRedirect(route('menu'));
        $this->assertAuthenticated();
    }
}
