<?php

namespace Tests\Feature;

use App\Models\ProductoInventario;
use App\Models\Usuario;
use App\Services\GoogleDriveService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\MySqlTestCase;

/**
 * Subida de la foto del inventario completa (AlmacenController::subirFotoProducto) con Gemini y
 * Drive simulados: qué se sube, de qué tamaño, qué dice `fondo_blanco` y que no queden
 * temporales. Todo corre en la transacción de la prueba y se revierte al terminar.
 */
class FotoProductoSubidaTest extends MySqlTestCase
{
    /** [nombre => [ancho, mime]] de lo que llegó a "Drive". */
    private array $subidas = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('gemini:imagen:sin_cupo');
        config(['services.gemini.key' => 'clave-de-prueba', 'services.gemini.modelo_imagen' => 'modelo-imagen']);

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('uploadFile')->andReturnUsing(function ($folder, $file, $nombre, $mime) {
            $this->subidas[$nombre] = [getimagesize($file->getRealPath())[0] ?? 0, $mime];
            return (object) ['id' => 'ID' . count($this->subidas)];
        });
        $this->ponerDrive($drive);
    }

    protected function tearDown(): void
    {
        $this->ponerDrive(null);
        Cache::forget('gemini:imagen:sin_cupo');
        parent::tearDown();
    }

    private function ponerDrive($instancia): void
    {
        $p = new \ReflectionProperty(GoogleDriveService::class, 'instance');
        $p->setValue(null, $instancia);
    }

    private function usuario(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.foto'));
        $this->assertNotNull($u, 'No hay un usuario con almacen.foto para probar.');
        return $u;
    }

    private function producto(): ProductoInventario
    {
        return ProductoInventario::create(['CODIGO' => 'F' . strtoupper(Str::random(7)), 'NOMBRE' => 'PRUEBA FOTO ' . Str::random(5), 'UM' => 'UND']);
    }

    /** PNG de $ancho px, como binario. */
    private function png(int $ancho): string
    {
        $img = imagecreatetruecolor($ancho, $ancho);
        ob_start(); imagepng($img); imagedestroy($img);
        return ob_get_clean();
    }

    private function subir(ProductoInventario $p)
    {
        return $this->actingAs($this->usuario())->post(route('almacen.productos.foto.store', $p->ID_PRODUCTO), [
            'foto' => UploadedFile::fake()->image('foto.jpg', 2000, 1500),
        ], ['Accept' => 'application/json']);
    }

    private function temporalesGemini(): int
    {
        return count(glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gem_*') ?: []);
    }

    public function test_con_gemini_guarda_la_de_fondo_blanco_achicada_y_la_original_de_respaldo(): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [
            ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($this->png(1024))]],
        ]]]]])]);
        $p = $this->producto();
        $antes = $this->temporalesGemini();

        $this->subir($p)->assertOk()->assertJson(['success' => true, 'fondo_blanco' => true, 'foto' => '/storage/google/ID1']);

        $this->assertSame('/storage/google/ID1', $p->fresh()->FOTO);
        $this->assertCount(2, $this->subidas, 'La de fondo blanco y la original.');
        [$principal, $original] = array_keys($this->subidas);
        $this->assertSame([600, 'image/webp'], $this->subidas[$principal], 'La que se muestra va a 600 px en WebP.');
        $this->assertStringEndsWith('_original.webp', $original);
        $this->assertSame(1024, $this->subidas[$original][0], 'La original se guarda como se le mandó a Gemini.');
        Http::assertSent(fn ($req) => $req['contents'][0]['parts'][1]['inline_data']['mime_type'] === 'image/webp');
        $this->assertSame($antes, $this->temporalesGemini(), 'No quedan temporales de Gemini.');
    }

    public function test_sin_cupo_guarda_la_original_y_deja_de_preguntar_un_rato(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'quota']], 429)]);
        $p = $this->producto();

        $this->subir($p)->assertOk()->assertJson(['fondo_blanco' => false]);
        $this->assertCount(1, $this->subidas, 'Sin Gemini no hay copia _original: la que se ve ES la original.');
        $this->assertSame([600, 'image/webp'], array_values($this->subidas)[0]);

        // La segunda foto ya no le pregunta a Google: el 429 quedó recordado.
        $this->subir($this->producto())->assertOk()->assertJson(['fondo_blanco' => false]);
        Http::assertSentCount(1);
    }

    public function test_sin_gemini_configurado_fondo_blanco_es_null(): void
    {
        config(['services.gemini.key' => '']);
        Http::fake();

        $this->subir($this->producto())->assertOk()->assertJson(['success' => true, 'fondo_blanco' => null]);
        Http::assertNothingSent();
    }

    public function test_un_503_se_reintenta_una_vez(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['message' => 'ocupado']], 503)
            ->push(['candidates' => [['content' => ['parts' => [
                ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($this->png(800))]],
            ]]]]]);

        $this->subir($this->producto())->assertOk()->assertJson(['fondo_blanco' => true]);
        Http::assertSentCount(2);
    }
}
