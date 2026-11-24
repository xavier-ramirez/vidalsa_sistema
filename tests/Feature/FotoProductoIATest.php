<?php

namespace Tests\Feature;

use App\Services\FotoProductoIA;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Foto del inventario tipo catálogo (FotoProductoIA): Cloudflare primero, Gemini de respaldo.
 * Los dos se simulan con Http::fake: no se gasta cupo ni hace falta red. Lo importante es que
 * NUNCA frene la subida: cualquier cosa que no sea una imagen devuelve null y la foto se guarda
 * tal cual.
 */
class FotoProductoIATest extends TestCase
{
    private string $foto;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.key' => 'clave-de-prueba', 'services.gemini.modelo_imagen' => 'modelo-imagen',
                'services.cloudflare_ai.account_id' => '', 'services.cloudflare_ai.token' => '']);
        \Illuminate\Support\Facades\Cache::forget('gemini:imagen:sin_cupo');
        \Illuminate\Support\Facades\Cache::forget('cloudflare:imagen:sin_cupo');
        $this->foto = tempnam(sys_get_temp_dir(), 'foto_');
        $img = imagecreatetruecolor(4, 4);
        imagejpeg($img, $this->foto);
        imagedestroy($img);
    }

    protected function tearDown(): void
    {
        @unlink($this->foto);
        // El 429 de la prueba deja la marca de "sin cupo": que no se quede en la caché real.
        \Illuminate\Support\Facades\Cache::forget('gemini:imagen:sin_cupo');
        \Illuminate\Support\Facades\Cache::forget('cloudflare:imagen:sin_cupo');
        parent::tearDown();
    }

    public function test_devuelve_la_imagen_que_hace_gemini_y_le_manda_la_foto(): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [
            ['text' => 'listo'],
            ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('PNG-BLANCO')]],
        ]]]]])]);

        $r = app(FotoProductoIA::class)->fondoBlanco($this->foto, 'image/jpeg');

        $this->assertSame(['binario' => 'PNG-BLANCO', 'mime' => 'image/png'], $r);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'modelo-imagen:generateContent')
            && $req->header('x-goog-api-key')[0] === 'clave-de-prueba'
            && $req['contents'][0]['parts'][1]['inline_data']['data'] === base64_encode(file_get_contents($this->foto)));
    }

    public function test_la_descripcion_del_producto_va_en_la_instruccion(): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [
            ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('PNG')]],
        ]]]]])]);

        app(FotoProductoIA::class)->fondoBlanco($this->foto, 'image/jpeg', "FILTRO DE ACEITE
 1R-0739 (FILTROS)");

        Http::assertSent(fn ($req) => str_contains($req['contents'][0]['parts'][0]['text'], '«FILTRO DE ACEITE 1R-0739 (FILTROS)»')
            && str_contains($req['contents'][0]['parts'][0]['text'], 'fondo blanco'));
    }

    public function test_sin_cupo_devuelve_null_y_no_vuelve_a_preguntar_enseguida(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 429, 'message' => 'quota']], 429)]);
        $this->assertNull(app(FotoProductoIA::class)->fondoBlanco($this->foto, 'image/jpeg'));
        $this->assertNull(app(FotoProductoIA::class)->fondoBlanco($this->foto, 'image/jpeg'));
        Http::assertSentCount(1);
    }

    public function test_respuesta_sin_imagen_devuelve_null(): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'no puedo']]], 'finishReason' => 'SAFETY']]])]);
        $this->assertNull(app(FotoProductoIA::class)->fondoBlanco($this->foto, 'image/jpeg'));
    }

    // ── Cloudflare (va primero) ─────────────────────────────────────────────

    private function conCloudflare(): void
    {
        config(['services.cloudflare_ai.account_id' => 'CUENTA', 'services.cloudflare_ai.token' => 'TOKEN-CF',
                'services.cloudflare_ai.modelo_imagen' => '@cf/black-forest-labs/flux-2-klein-4b']);
    }

    /** Foto apaisada de 1500x1000 (más grande que lo que acepta FLUX como referencia). */
    private function fotoGrande(): void
    {
        $img = imagecreatetruecolor(1500, 1000);
        imagejpeg($img, $this->foto);
        imagedestroy($img);
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(8, 8);
        ob_start(); imagepng($img); imagedestroy($img);
        return ob_get_clean();
    }

    public function test_cloudflare_rehace_la_foto_con_la_referencia_achicada_a_512(): void
    {
        $this->conCloudflare();
        $this->fotoGrande();
        $png = $this->png();
        Http::fake(['api.cloudflare.com/*' => Http::response(['result' => ['image' => base64_encode($png)], 'success' => true])]);

        $r = app(FotoProductoIA::class)->fondoBlanco($this->foto, 'image/jpeg', 'BRAGA IGNÍFUGA TALLA 32');

        $this->assertSame(['binario' => $png, 'mime' => 'image/png'], $r);
        Http::assertSentCount(1);   // Gemini ni se entera
        Http::assertSent(function ($req) {
            if (!str_contains($req->url(), 'accounts/CUENTA/ai/run/@cf/black-forest-labs/flux-2-klein-4b')) return false;
            if ($req->header('Authorization')[0] !== 'Bearer TOKEN-CF' || !$req->isMultipart()) return false;
            $partes = collect($req->data())->keyBy('name');
            [$w, $h] = getimagesizefromstring($partes['input_image_0']['contents']);
            return $w === 512 && $h === 341
                && str_contains($partes['prompt']['contents'], '"BRAGA IGNÍFUGA TALLA 32"')
                && str_contains($partes['prompt']['contents'], 'wrinkle-free')
                && $partes['width']['contents'] === '1024';
        });
    }

    public function test_cloudflare_sin_cupo_pasa_a_gemini_y_no_vuelve_a_preguntarle_enseguida(): void
    {
        $this->conCloudflare();
        Http::fake([
            'api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['message' => 'daily free allocation exceeded']]], 429),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [
                ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('DE-GEMINI')]],
            ]]]]]),
        ]);

        $ia = app(FotoProductoIA::class);
        $this->assertSame('DE-GEMINI', $ia->fondoBlanco($this->foto, 'image/jpeg')['binario']);
        $this->assertSame('DE-GEMINI', $ia->fondoBlanco($this->foto, 'image/jpeg')['binario']);

        Http::assertSentCount(3);   // Cloudflare una sola vez (la marca de sin cupo), Gemini dos
    }

    public function test_cloudflare_sin_imagen_y_sin_gemini_devuelve_null(): void
    {
        $this->conCloudflare();
        config(['services.gemini.key' => '']);
        Http::fake(['api.cloudflare.com/*' => Http::response(['result' => [], 'success' => true])]);
        $this->assertNull(app(FotoProductoIA::class)->fondoBlanco($this->foto, 'image/jpeg'));
        $this->assertTrue(app(FotoProductoIA::class)->activo());
    }

    public function test_sin_clave_ni_siquiera_llama(): void
    {
        config(['services.gemini.key' => '']);
        Http::fake();
        $this->assertNull(app(FotoProductoIA::class)->fondoBlanco($this->foto, 'image/jpeg'));
        Http::assertNothingSent();
    }
}
