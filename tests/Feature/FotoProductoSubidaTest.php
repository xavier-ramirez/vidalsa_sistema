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
 * Subida de la foto del inventario completa (AlmacenController::subirFotoProducto) con la IA y
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
        Cache::forget('cloudflare:imagen:sin_cupo');
        config(['services.cloudflare_ai.account_id' => 'CUENTA', 'services.cloudflare_ai.token' => 'TOKEN',
                'services.cloudflare_ai.modelo_imagen' => '@cf/modelo']);

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
        Cache::forget('cloudflare:imagen:sin_cupo');
        parent::tearDown();
    }

    private function ponerDrive($instancia): void
    {
        $p = new \ReflectionProperty(GoogleDriveService::class, 'instance');
        $p->setValue(null, $instancia);
    }

    /** Un usuario cualquiera con la clave almacen.foto puesta (exclusiva: nadie la trae de base). */
    private function usuario(): Usuario
    {
        $u = Usuario::where('ESTATUS', 'ACTIVO')->where('REQUIERE_CAMBIO_CLAVE', 0)->firstOrFail();
        $u->PERMISOS = array_values(array_unique(array_merge((array) $u->PERMISOS, ['almacen.foto'])));
        $u->save();
        return $u->fresh();
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

    private function temporalesIA(): int
    {
        return count(glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ia_*') ?: []);
    }

    public function test_con_ia_guarda_la_de_fondo_blanco_achicada_y_la_original_de_respaldo(): void
    {
        Http::fake(['*' => Http::response(['result' => ['image' => base64_encode($this->png(1024))], 'success' => true])]);
        $p = $this->producto();
        $antes = $this->temporalesIA();

        $this->subir($p)->assertOk()->assertJson(['success' => true, 'fondo_blanco' => true, 'foto' => '/storage/google/ID1']);

        $this->assertSame('/storage/google/ID1', $p->fresh()->FOTO);
        $this->assertCount(2, $this->subidas, 'La de fondo blanco y la original.');
        [$principal, $original] = array_keys($this->subidas);
        $this->assertSame([600, 'image/webp'], $this->subidas[$principal], 'La que se muestra va a 600 px en WebP.');
        $this->assertStringEndsWith('_original.webp', $original);
        $this->assertSame(1024, $this->subidas[$original][0], 'La original se guarda como se le mandó a la IA.');
        Http::assertSent(fn ($req) => str_contains($req->url(), 'accounts/CUENTA/ai/run/@cf/modelo')
            && str_contains(collect($req->data())->firstWhere('name', 'prompt')['contents'], 'PRUEBA FOTO'));
        $this->assertSame($antes, $this->temporalesIA(), 'No quedan temporales de la IA.');
    }

    public function test_cambiar_la_foto_no_tira_la_que_sigue_usando_otro_producto(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        Http::fake(['*' => Http::response([], 429)]);
        $a = $this->producto();
        $a->update(['FOTO' => '/storage/google/COMPARTIDA']);
        $b = $this->producto();
        $b->update(['FOTO' => '/storage/google/COMPARTIDA']);

        $this->subir($a)->assertOk();

        $this->assertSame('/storage/google/ID1', $a->fresh()->FOTO);
        $this->assertSame('/storage/google/COMPARTIDA', $b->fresh()->FOTO);
        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\DeleteGoogleDriveFile::class);
    }

    public function test_cambiar_la_foto_manda_la_vieja_a_la_papelera_de_drive(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        Http::fake(['*' => Http::response([], 429)]);
        $a = $this->producto();
        $a->update(['FOTO' => '/storage/google/VIEJA_SOLA']);

        $this->subir($a)->assertOk();

        $this->assertSame('/storage/google/ID1', $a->fresh()->FOTO);
        \Illuminate\Support\Facades\Bus::assertDispatchedAfterResponse(\App\Jobs\DeleteGoogleDriveFile::class);
    }

    public function test_sin_cupo_guarda_la_original_y_deja_de_preguntar_un_rato(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'errors' => [['message' => 'quota']]], 429)]);
        $p = $this->producto();

        $this->subir($p)->assertOk()->assertJson(['fondo_blanco' => false]);
        $this->assertCount(1, $this->subidas, 'Sin IA no hay copia _original: la que se ve ES la original.');
        $this->assertSame([600, 'image/webp'], array_values($this->subidas)[0]);

        // La segunda foto ya no le pregunta a la IA: el 429 quedó recordado.
        $this->subir($this->producto())->assertOk()->assertJson(['fondo_blanco' => false]);
        Http::assertSentCount(1);
    }

    public function test_sin_ia_configurada_fondo_blanco_es_null(): void
    {
        config(['services.cloudflare_ai.token' => '']);
        Http::fake();

        $this->subir($this->producto())->assertOk()->assertJson(['success' => true, 'fondo_blanco' => null]);
        Http::assertNothingSent();
    }

    public function test_un_503_se_reintenta_una_vez(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['message' => 'ocupado']], 503)
            ->push(['result' => ['image' => base64_encode($this->png(800))], 'success' => true]);

        $this->subir($this->producto())->assertOk()->assertJson(['fondo_blanco' => true]);
        Http::assertSentCount(2);
    }
}
