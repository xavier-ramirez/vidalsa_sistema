<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Toda respuesta sale con las cabeceras de App\Http\Middleware\CabecerasSeguridad. */
class CabecerasSeguridadTest extends TestCase
{
    public function test_toda_respuesta_lleva_las_cabeceras_de_seguridad(): void
    {
        $this->get('/up')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_hsts_solo_por_https(): void
    {
        $this->get('http://localhost/up')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
