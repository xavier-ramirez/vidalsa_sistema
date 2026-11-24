<?php

namespace Tests\Feature;

use App\Traits\ConvertsImageToWebp;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Compresión de las fotos (Catálogo, auxiliares, Inventario): todo sale WebP, achicado al
 * ancho tope, y la foto de teléfono derecha (orientación EXIF). Y que el servidor tenga con
 * qué hacerlo: gd con JPEG y WebP en el Dockerfile.
 */
class ConvertsImageToWebpTest extends TestCase
{
    private $conv;
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->conv = new class {
            use ConvertsImageToWebp;
            public function convertir($f, ?int $tope = null): array { return $this->convertToWebp($f, $tope); }
        };
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $t) @unlink($t);
        parent::tearDown();
    }

    /** Archivo de imagen de $w x $h con ruido (para que pese como una foto real). */
    private function imagen(int $w, int $h, string $tipo, ?int $orientacion = null): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        for ($i = 0; $i < 300; $i++) {
            imagefilledrectangle($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h),
                imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        ob_start();
        match ($tipo) { 'jpg' => imagejpeg($img, null, 95), 'png' => imagepng($img), 'webp' => imagewebp($img, null, 90) };
        $bin = ob_get_clean();
        imagedestroy($img);
        if ($orientacion) $bin = $this->conExif($bin, $orientacion);

        $ruta = tempnam(sys_get_temp_dir(), 'tst') . '.' . $tipo;
        file_put_contents($ruta, $bin);
        $this->temporales[] = $ruta;
        $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$tipo];
        return new UploadedFile($ruta, 'foto.' . $tipo, $mime, null, true);
    }

    /** Mete en el JPEG un bloque EXIF mínimo con la orientación (como lo hace un teléfono). */
    private function conExif(string $jpeg, int $orientacion): string
    {
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientacion) . "\x00\x00" . "\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00" . $tiff;
        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    private function convertir(UploadedFile $f, ?int $tope = null): array
    {
        $r = $this->conv->convertir($f, $tope);
        if ($r['tempPath']) $this->temporales[] = $r['tempPath'];
        $info = getimagesize($r['file']->getRealPath());
        return [$info[0], $info[1], $info['mime'], filesize($r['file']->getRealPath()), $r];
    }

    public function test_jpeg_grande_sale_webp_achicado_al_ancho_del_catalogo_y_mucho_mas_liviano(): void
    {
        $original = $this->imagen(3000, 2000, 'jpg');
        [$w, $h, $mime, $peso] = $this->convertir($original);
        $this->assertSame([945, 630, 'image/webp'], [$w, $h, $mime]);
        $this->assertLessThan($original->getSize() / 3, $peso, 'Pesa mucho menos que el original.');
    }

    public function test_inventario_usa_su_ancho_menor(): void
    {
        [$w, , $mime] = $this->convertir($this->imagen(2000, 2000, 'png'), 600);
        $this->assertSame([600, 'image/webp'], [$w, $mime]);
    }

    public function test_foto_de_telefono_acostada_sale_derecha(): void
    {
        // Sensor apaisado 2000x1000 con orientación 6: la foto real es vertical.
        [$w, $h, $mime] = $this->convertir($this->imagen(2000, 1000, 'jpg', 6));
        $this->assertSame('image/webp', $mime);
        $this->assertGreaterThan($w, $h, 'Queda vertical, como se tomó.');
        $this->assertSame(945, $w);
    }

    public function test_webp_chico_del_recorte_se_sube_tal_cual(): void
    {
        $f = $this->imagen(800, 600, 'webp');
        [$w, , $mime, , $r] = $this->convertir($f);
        $this->assertSame([800, 'image/webp'], [$w, $mime]);
        $this->assertNull($r['tempPath'], 'Sin re-encodear: no pierde calidad.');
    }

    public function test_el_servidor_compila_gd_con_jpeg_y_webp(): void
    {
        $docker = file_get_contents(base_path('Dockerfile'));
        $this->assertStringContainsString('docker-php-ext-configure gd --with-jpeg --with-webp', $docker);
        $this->assertStringContainsString('libjpeg62-turbo-dev', $docker);
        $this->assertStringContainsString('libwebp-dev', $docker);
    }
}
