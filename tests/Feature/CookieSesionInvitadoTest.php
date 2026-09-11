<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * App\Http\Middleware\NoReenviarCookieDeSesion: a un invitado que ya trae su cookie de
 * sesión no se le vuelve a mandar. Si se le mandara, una respuesta que llega TARDE (salió
 * antes de iniciar sesión: la revalidación del login en el service worker, /refresh-csrf,
 * la precarga de la huella, /sw.js) pisaba la cookie del usuario recién autenticado y la
 * pantalla siguiente lo sacaba con "Tu sesión expiró".
 */
class CookieSesionInvitadoTest extends MySqlTestCase
{
    private function traeCookieDeSesion($response): bool
    {
        return collect($response->headers->getCookies())
            ->contains(fn ($c) => $c->getName() === config('session.cookie'));
    }

    public function test_primera_visita_sin_cookie_recibe_una(): void
    {
        $this->assertTrue($this->traeCookieDeSesion($this->get('/')));
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
