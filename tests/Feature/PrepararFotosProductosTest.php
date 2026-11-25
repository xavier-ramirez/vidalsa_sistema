<?php

namespace Tests\Feature;

use App\Console\Commands\PrepararFotosProductos;
use App\Models\ProductoInventario;
use App\Services\GoogleDriveService;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\MySqlTestCase;

/**
 * fotos:preparar deja en el disco del servidor la copia de cada foto de producto, para que
 * el proxy no tenga que ir a Drive al abrirla, y no vuelve a bajar lo que ya está.
 */
class PrepararFotosProductosTest extends MySqlTestCase
{
    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->id = 'PRUEBAFOTO' . Str::random(10);
        // Las miniaturas ya en el disco: miniatura() sale de ahí sin preguntar a Drive.
        foreach (PrepararFotosProductos::MINIATURAS as $sz) {
            Storage::disk('local')->put(GoogleDriveService::rutaCopiaLocal($this->id, $sz), $this->png());
        }
    }

    protected function tearDown(): void
    {
        GoogleDriveService::olvidarCopiaLocal($this->id);
        \Illuminate\Support\Facades\Cache::forget('gdrive_olvidado_' . $this->id);
        (new \ReflectionProperty(GoogleDriveService::class, 'instance'))->setValue(null, null);
        parent::tearDown();
    }

    /** Un Drive falso cuyo files->get() dice que el archivo pesa $bytes. */
    private function driveConTamano(int $bytes)
    {
        $files = Mockery::mock();
        $files->shouldReceive('get')->andReturn(new \Google\Service\Drive\DriveFile(['mimeType' => 'image/webp', 'size' => $bytes]));
        $api = Mockery::mock(\Google\Service\Drive::class);
        $api->files = $files;
        return $api;
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(4, 4);
        ob_start(); imagepng($img); imagedestroy($img);
        return ob_get_clean();
    }

    public function test_baja_la_foto_que_falta_una_sola_vez(): void
    {
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('getStreamById')->once()->with($this->id)->andReturn(Utils::streamFor('FOTO-ENTERA'));
        (new \ReflectionProperty(GoogleDriveService::class, 'instance'))->setValue(null, $drive);
        $drive->shouldReceive('getDrive')->andReturn($this->driveConTamano(11));

        // Solo la de la prueba (--id): sin eso recorreria las fotos reales de la base.
        $this->artisan('fotos:preparar', ['--id' => [$this->id]])->assertSuccessful();
        $this->assertSame('FOTO-ENTERA', Storage::disk('local')->get(GoogleDriveService::rutaCopiaLocal($this->id)));
        $this->assertEmpty(glob(Storage::disk('local')->path(GoogleDriveService::rutaCopiaLocal($this->id)) . '.*.parcial'),
            'No queda el temporal.');

        // Segunda noche: ya está, no se baja de nuevo (el mock solo admite una descarga).
        $this->artisan('fotos:preparar', ['--id' => [$this->id]])->assertSuccessful();
    }

    public function test_una_descarga_cortada_no_queda_como_copia(): void
    {
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('getDrive')->andReturn($this->driveConTamano(60000));
        $drive->shouldReceive('getStreamById')->andReturn(Utils::streamFor('SOLO-UN-TROZO'));
        (new \ReflectionProperty(GoogleDriveService::class, 'instance'))->setValue(null, $drive);

        $this->assertFalse(GoogleDriveService::prepararCopiaLocal($this->id));
        $this->assertFalse(Storage::disk('local')->exists(GoogleDriveService::rutaCopiaLocal($this->id)));
        $this->assertEmpty(glob(Storage::disk('local')->path(GoogleDriveService::rutaCopiaLocal($this->id)) . '.*.parcial'));
    }

    public function test_no_resucita_una_foto_borrada(): void
    {
        GoogleDriveService::olvidarCopiaLocal($this->id);
        $this->assertFalse(GoogleDriveService::prepararCopiaLocal($this->id, 'BYTES'));
        $this->assertFalse(Storage::disk('local')->exists(GoogleDriveService::rutaCopiaLocal($this->id)));
    }
}
