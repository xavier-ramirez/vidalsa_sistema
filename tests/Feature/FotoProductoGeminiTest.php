<?php

namespace Tests\Feature;

use App\Services\FotoProductoGemini;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fondo blanco de la foto del inventario (FotoProductoGemini). Google se simula con Http::fake:
 * no se gasta cupo ni hace falta red. Lo importante es que NUNCA frene la subida: cualquier
 * cosa que no sea una imagen devuelve null y la foto se guarda tal cual.
 */
class FotoProductoGeminiTest extends TestCase
{
    private string $foto;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.key' => 'clave-de-prueba', 'services.gemini.modelo_imagen' => 'modelo-imagen']);
        $this->foto = tempnam(sys_get_temp_dir(), 'foto_');
        $img = imagecreatetruecolor(4, 4);
        imagejpeg($img, $this->foto);
        imagedestroy($img);
    }

    protected function tearDown(): void
    {
        @unlink($this->foto);
        parent::tearDown();
    }

    public function test_devuelve_la_imagen_que_hace_gemini_y_le_manda_la_foto(): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [
            ['text' => 'listo'],
            ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('PNG-BLANCO')]],
        ]]]]])]);

        $r = app(FotoProductoGemini::class)->fondoBlanco($this->foto, 'image/jpeg');

        $this->assertSame(['binario' => 'PNG-BLANCO', 'mime' => 'image/png'], $r);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'modelo-imagen:generateContent')
            && $req->header('x-goog-api-key')[0] === 'clave-de-prueba'
            && $req['contents'][0]['parts'][1]['inline_data']['data'] === base64_encode(file_get_contents($this->foto)));
    }

    public function test_sin_cupo_devuelve_null(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 429, 'message' => 'quota']], 429)]);
        $this->assertNull(app(FotoProductoGemini::class)->fondoBlanco($this->foto, 'image/jpeg'));
    }

    public function test_respuesta_sin_imagen_devuelve_null(): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'no puedo']]], 'finishReason' => 'SAFETY']]])]);
        $this->assertNull(app(FotoProductoGemini::class)->fondoBlanco($this->foto, 'image/jpeg'));
    }

    public function test_sin_clave_ni_siquiera_llama(): void
    {
        config(['services.gemini.key' => '']);
        Http::fake();
        $this->assertNull(app(FotoProductoGemini::class)->fondoBlanco($this->foto, 'image/jpeg'));
        Http::assertNothingSent();
    }
}
