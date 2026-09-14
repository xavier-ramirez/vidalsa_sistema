<?php

namespace Tests\Feature;

use Tests\MySqlTestCase;

/**
 * SystemController::loginPage: el cierre por inactividad (partials/session_timeout) termina
 * en "/?aviso=inactividad". Si su POST /logout no llegó y la sesión sigue abierta, el login
 * la cierra en vez de devolverla al menú ("se cerró la sesión y se abrió"): tras un deploy
 * el Service Worker no tiene el login en caché y la petición llega al servidor.
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

    public function test_sin_el_aviso_quien_tiene_sesion_sigue_yendo_al_menu(): void
    {
        $this->actingAs($this->superAdminGlobal())->get('/')->assertRedirect(route('menu'));
        $this->assertAuthenticated();
    }

    public function test_un_enlace_de_otro_sitio_no_cierra_la_sesion(): void
    {
        $this->actingAs($this->superAdminGlobal())
            ->get('/?aviso=inactividad', ['Sec-Fetch-Site' => 'cross-site'])
            ->assertRedirect(route('menu'));
        $this->assertAuthenticated();
    }
}
