<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * El inicio de sesión tiene UN solo botón: con servidor entra normal y sin él entra con lo
 * guardado en el equipo (offline-auth.js · entrarSinServidor). El segundo botón «Entrar sin
 * conexión» se quitó: el usuario no tiene que elegir, y su aviso mandaba a buscarlo.
 */
class LoginUnSoloBotonTest extends TestCase
{
    public function test_la_pantalla_trae_un_solo_boton_y_la_nota_sin_red(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="btnOnlineLogin"'));
        $this->assertStringNotContainsString('btnOfflineLogin', $html);
        $this->assertStringNotContainsString('Entrar sin conexión', $html);
        $this->assertStringContainsString('id="loginSinRedNota"', $html);
        $this->assertStringContainsString('Sin internet: entrarás con los datos guardados en este equipo.', $html);
    }

    public function test_el_js_ya_no_tiene_el_camino_del_segundo_boton(): void
    {
        $js = file_get_contents(public_path('js/offline/offline-auth.js'));

        foreach (['btnOfflineLogin', 'intentarOffline', 'sinRespuesta', '_vieneDeLocal'] as $resto) {
            $this->assertStringNotContainsString($resto, $js, "queda «{$resto}» en offline-auth.js");
        }
        foreach (['conServidor', 'entrarSinServidor', 'noContesto'] as $api) {
            $this->assertStringContainsString("window.OfflineAuth.{$api} = function", $js);
        }
    }
}
