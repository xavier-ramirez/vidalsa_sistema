<?php

namespace Tests\Feature;

use App\Http\Middleware\CabecerasSeguridad;
use Tests\TestCase;

/** Toda respuesta sale con las cabeceras de App\Http\Middleware\CabecerasSeguridad. */
class CabecerasSeguridadTest extends TestCase
{
    public function test_toda_respuesta_lleva_las_cabeceras_de_seguridad(): void
    {
        $this->get('/up')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy', CabecerasSeguridad::CSP);
    }

    public function test_la_csp_va_solo_en_paginas_no_en_pdf(): void
    {
        // En un PDF, object-src 'none' haría que Chrome no lo muestre en el visor.
        \Illuminate\Support\Facades\Route::get('/__pdf_prueba', fn () => response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']));
        $this->get('/__pdf_prueba')->assertOk()->assertHeaderMissing('Content-Security-Policy')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_hsts_solo_por_https(): void
    {
        $this->get('http://localhost/up')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
