<?php

namespace Tests\Feature;

use App\Jobs\DeleteGoogleDriveFile;
use App\Services\CargaMasivaDocumentos;
use App\Services\LectorDocumentoPdf;
use App\Services\LectorGemini;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\DriveFalso;
use Tests\MySqlTestCase;

/**
 * La SUBIDA de la carga masiva (CargaMasivaDocumentos::analizar), que es la parte que mete
 * el archivo en Drive y propone qué hacer con él. Hasta ahora solo estaban probadas las dos
 * de después —`aplicar` (escribe en la ficha) y `detectarTipo` (texto puro)—, así que el
 * paso de subir no tenía red: justo el que acaba de cambiar para rechazar los PDF cortados.
 *
 * NADA SALE A LA RED NI TOCA EL DRIVE DE VERDAD:
 *   · DriveFalso cambia la API de Google por un doble en memoria (el código propio de
 *     GoogleDriveService sí corre entero: subirPdf, su comprobación y su copia local);
 *   · Storage::fake('local') se queda con esa copia local, que si no iría al disco;
 *   · el lector del OCR y el de la IA se sustituyen aquí mismo, que son las otras dos
 *     puertas a la red.
 * Ningún documento real se lee, se pisa ni se borra: los equipos son de la prueba y la
 * transacción de cada caso lo revierte todo.
 */
class CargaMasivaSubidaTest extends MySqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Bus::fake([DeleteGoogleDriveFile::class]);
        DriveFalso::instalar();
    }

    protected function tearDown(): void
    {
        DriveFalso::quitar();
        parent::tearDown();
    }

    /** El servicio con el OCR y la IA sustituidos: `$texto` es lo que "leerá" del PDF. */
    private function servicio(string $texto = ''): CargaMasivaDocumentos
    {
        $lector = new class($texto) extends LectorDocumentoPdf {
            public function __construct(private string $texto) {}
            public function texto(string $driveId): string { return $this->texto; }
        };
        // La IA no se usa cuando el texto basta; se deja muda para que no salga a la red.
        $ia = new class extends LectorGemini {
            public function __construct() {}
            public function disponible(): bool { return false; }
        };
        return new CargaMasivaDocumentos($lector, $ia);
    }

    private function pdfBueno(string $nombre = 'poliza.pdf'): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'pdf') . '.pdf';
        file_put_contents($ruta, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n%%EOF\n");
        return new UploadedFile($ruta, $nombre, 'application/pdf', null, true);
    }

    /** Cortado: sin el %%EOF del final. Es el caso del ROTC que llegó truncado. */
    private function pdfCortado(string $nombre = 'rotc_cortado.pdf'): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'pdf') . '.pdf';
        file_put_contents($ruta, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n(se corta aqui");
        return new UploadedFile($ruta, $nombre, 'application/pdf', null, true);
    }

    public function test_un_pdf_bueno_se_sube_y_devuelve_su_propuesta(): void
    {
        $r = $this->servicio('POLIZA DE SEGURO ... VENCE 31/12/2027')->analizar($this->pdfBueno(), 'poliza');

        $this->assertSame('poliza.pdf', $r['archivo']);
        $this->assertNotEmpty($r['link'], 'Un PDF sano tiene que quedar subido y traer su enlace.');
        $this->assertArrayHasKey('tipo', $r);
    }

    /**
     * Lo que se arregló: antes el archivo cortado se subía igual y nadie se enteraba. Ahora
     * ni se sube, y el motivo le dice a la persona QUÉ hacer (volver a escanearlo).
     */
    public function test_un_pdf_cortado_ni_se_sube_ni_se_queda_a_medias(): void
    {
        $r = $this->servicio('lo que sea')->analizar($this->pdfCortado(), 'poliza');

        $this->assertSame('rotc_cortado.pdf', $r['archivo']);
        $this->assertEmpty($r['link'], 'Un PDF cortado NO puede quedar subido en Drive.');
        $this->assertMatchesRegularExpression(
            '/incompleto|cortad|vuelve a|no es un PDF|vacio|vacío/i',
            (string) ($r['aviso'] ?? $r['motivo'] ?? ''),
            'El motivo tiene que explicar qué pasó, no un "no se pudo subir" genérico.'
        );
    }

    /** Un archivo que ni siquiera es un PDF tampoco puede colarse. */
    public function test_algo_que_no_es_un_pdf_se_rechaza(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'x') . '.pdf';
        file_put_contents($ruta, 'esto es un txt disfrazado de pdf');
        $falso = new UploadedFile($ruta, 'disfrazado.pdf', 'application/pdf', null, true);

        $r = $this->servicio()->analizar($falso, 'poliza');

        $this->assertEmpty($r['link']);
        $this->assertNotEmpty($r['aviso'] ?? $r['motivo'] ?? '');
    }

    /** Varios archivos seguidos: cada uno con su resultado, sin que uno tumbe a los demás. */
    public function test_una_tanda_mezclada_devuelve_el_resultado_de_cada_uno(): void
    {
        $svc = $this->servicio('POLIZA DE SEGURO');
        $resultados = [
            $svc->analizar($this->pdfBueno('uno.pdf'), 'poliza'),
            $svc->analizar($this->pdfCortado('dos_cortado.pdf'), 'poliza'),
            $svc->analizar($this->pdfBueno('tres.pdf'), 'poliza'),
        ];

        $this->assertNotEmpty($resultados[0]['link'], 'el primero es bueno');
        $this->assertEmpty($resultados[1]['link'], 'el segundo está cortado');
        $this->assertNotEmpty($resultados[2]['link'], 'el tercero vuelve a ser bueno: uno malo no tumba la tanda');
        $this->assertSame(['uno.pdf', 'dos_cortado.pdf', 'tres.pdf'], array_column($resultados, 'archivo'));
    }
}
