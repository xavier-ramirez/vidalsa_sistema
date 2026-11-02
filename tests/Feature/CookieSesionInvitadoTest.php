<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * App\Http\Middleware\NoReenviarCookieDeSesion: un invitado solo recibe cookie de sesión de
 * /refresh-csrf y de la precarga de la huella, y solo si aún no la tiene. Si se le mandara
 * en cualquier respuesta, una que llega TARDE (salió antes de iniciar sesión: la revalidación
 * del login en el service worker, las páginas guardadas, /sw.js) pisaba la cookie del usuario
 * recién autenticado y la petición siguiente lo sacaba con "Tu sesión expiró". Pasa al volver
 * a abrir el sistema: la cookie vence con la sesión y se llega sin ninguna.
 */
class CookieSesionInvitadoTest extends MySqlTestCase
{
    private function traeCookieDeSesion($response): bool
    {
        return collect($response->headers->getCookies())
            ->contains(fn ($c) => $c->getName() === config('session.cookie'));
    }

    public function test_sin_cookie_solo_la_entregan_las_rutas_del_inicio_de_sesion(): void
    {
        $this->assertTrue($this->traeCookieDeSesion($this->get('/refresh-csrf')));
        $this->assertTrue($this->traeCookieDeSesion(
            $this->postJson('/webauthn/login-options', ['credential_ids' => ['x']])
        ));

        foreach (['/', '/sw.js', '/menu', '/admin/equipos'] as $ruta) {
            $this->assertFalse($this->traeCookieDeSesion($this->get($ruta)), "{$ruta} dio cookie a un invitado sin cookie.");
        }
    }

    public function test_cerrar_sesion_no_toca_la_cookie(): void
    {
        $u = Usuario::query()->where('ESTATUS', 'ACTIVO')->first();
        $this->assertNotNull($u, 'No hay usuarios activos para probar.');

        $this->assertFalse($this->traeCookieDeSesion($this->actingAs($u)->postJson('/logout')),
            'Un cierre que llega tarde pisaría la sesión nueva.');
    }

    public function test_un_invitado_con_cookie_no_la_recibe_de_nuevo(): void
    {
        $id = Str::random(40);
        foreach (['/', '/refresh-csrf', '/sw.js'] as $ruta) {
            $r = $this->withCookie(config('session.cookie'), $id)->get($ruta);
            $this->assertFalse($this->traeCookieDeSesion($r), "{$ruta} reenvió la cookie de sesión a un invitado.");
            $this->assertFalse(
                collect($r->headers->getCookies())->contains(fn ($c) => $c->getName() === 'XSRF-TOKEN'),
                "{$ruta} reenvió XSRF-TOKEN a un invitado."
            );
        }
    }

    public function test_quien_tiene_sesion_si_renueva_la_cookie(): void
    {
        $u = Usuario::query()->where('ESTATUS', 'ACTIVO')->first();
        $this->assertNotNull($u, 'No hay usuarios activos para probar.');

        $this->assertTrue($this->traeCookieDeSesion($this->actingAs($u)->get('/refresh-csrf')),
            'Con sesión iniciada la cookie tiene que renovarse para no caducar mientras trabaja.');
    }
}
