<?php

namespace Tests\Feature;

use App\Services\FotoProductoIA;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Foto del inventario tipo catálogo (FotoProductoIA, Cloudflare Workers AI). Cloudflare se simula
 * con Http::fake: no se gasta cupo ni hace falta red. Lo importante es que NUNCA frene la subida:
 * cualquier cosa que no sea una imagen devuelve null y la foto se guarda tal cual.
 */
class FotoProductoIATest extends TestCase
{
    private string $foto;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cloudflare_ai.account_id' => 'CUENTA', 'services.cloudflare_ai.token' => 'TOKEN-CF',
                'services.cloudflare_ai.modelo_imagen' => '@cf/black-forest-labs/flux-2-klein-4b']);
        Cache::forget('cloudflare:imagen:sin_cupo');
        $this->foto = tempnam(sys_get_temp_dir(), 'foto_');
        $img = imagecreatetruecolor(1500, 1000);   // más grande que lo que acepta FLUX de referencia
        imagejpeg($img, $this->foto);
        imagedestroy($img);
    }

    protected function tearDown(): void
    {
        @unlink($this->foto);
        // El 429 de la prueba deja la marca de "sin cupo": que no se quede en la caché real.
        Cache::forget('cloudflare:imagen:sin_cupo');
        parent::tearDown();
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(8, 8);
        ob_start(); imagepng($img); imagedestroy($img);
        return ob_get_clean();
    }

    public function test_rehace_la_foto_con_la_referencia_achicada_a_512_y_el_nombre_del_producto(): void
    {
        $png = $this->png();
        Http::fake(['*' => Http::response(['result' => ['image' => base64_encode($png)], 'success' => true])]);

        $r = app(FotoProductoIA::class)->fondoBlanco($this->foto, "BRAGA IGNÍFUGA\n TALLA 32");

        $this->assertSame(['binario' => $png, 'mime' => 'image/png'], $r);
        Http::assertSentCount(1);
        Http::assertSent(function ($req) {
            if (!str_contains($req->url(), 'api.cloudflare.com/client/v4/accounts/CUENTA/ai/run/@cf/black-forest-labs/flux-2-klein-4b')) return false;
            if ($req->header('Authorization')[0] !== 'Bearer TOKEN-CF' || !$req->isMultipart()) return false;
            $partes = collect($req->data())->keyBy('name');
            [$w, $h] = getimagesizefromstring($partes['input_image_0']['contents']);
            return $w === 512 && $h === 341
                && str_contains($partes['prompt']['contents'], '"BRAGA IGNÍFUGA TALLA 32"')
                && str_contains($partes['prompt']['contents'], 'wrinkle-free')
                && $partes['width']['contents'] === '1024' && $partes['height']['contents'] === '1024';
        });
    }

    public function test_sin_cupo_devuelve_null_y_no_vuelve_a_preguntar_enseguida(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'errors' => [['message' => 'daily free allocation exceeded']]], 429)]);
        $this->assertNull(app(FotoProductoIA::class)->fondoBlanco($this->foto));
        $this->assertNull(app(FotoProductoIA::class)->fondoBlanco($this->foto));
        Http::assertSentCount(1);
    }

    public function test_un_503_se_reintenta_una_vez(): void
    {
        Http::fakeSequence()
            ->push(['success' => false], 503)
            ->push(['result' => ['image' => base64_encode($this->png())], 'success' => true]);
        $this->assertNotNull(app(FotoProductoIA::class)->fondoBlanco($this->foto));
        Http::assertSentCount(2);
    }

    public function test_respuesta_sin_imagen_devuelve_null(): void
    {
        Http::fake(['*' => Http::response(['result' => [], 'success' => true])]);
        $this->assertNull(app(FotoProductoIA::class)->fondoBlanco($this->foto));
    }

    public function test_archivo_que_no_es_imagen_no_llama(): void
    {
        file_put_contents($this->foto, 'no soy una foto');
        Http::fake();
        $this->assertNull(app(FotoProductoIA::class)->fondoBlanco($this->foto));
        Http::assertNothingSent();
    }

    public function test_sin_cuenta_o_sin_token_ni_siquiera_llama(): void
    {
        Http::fake();
        config(['services.cloudflare_ai.token' => '']);
        $this->assertFalse(app(FotoProductoIA::class)->activo());
        $this->assertNull(app(FotoProductoIA::class)->fondoBlanco($this->foto));
        config(['services.cloudflare_ai.token' => 'TOKEN-CF', 'services.cloudflare_ai.account_id' => '']);
        $this->assertNull(app(FotoProductoIA::class)->fondoBlanco($this->foto));
        Http::assertNothingSent();
    }

    public function test_gemini_ya_no_se_usa_para_fotos(): void
    {
        config(['services.cloudflare_ai.token' => '', 'services.gemini.key' => 'CLAVE-GEMINI']);
        Http::fake();
        $this->assertFalse(app(FotoProductoIA::class)->activo());
        $this->assertNull(app(FotoProductoIA::class)->fondoBlanco($this->foto));
        Http::assertNothingSent();
    }
}
