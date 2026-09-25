<?php

namespace Tests\Feature;

use App\Exceptions\PdfNoValido;
use App\Services\GoogleDriveService;
use Tests\MySqlTestCase;

/**
 * Un PDF que trae cosas que un DOCUMENTO no necesita —lanzar programas, archivos escondidos
 * dentro, multimedia incrustada— no entra al sistema. La comprobación vive en
 * GoogleDriveService::comprobarPdfCompleto, que es la puerta por la que pasan TODOS los PDF:
 * la ficha del equipo, el visor, los auxiliares y la carga masiva.
 *
 * NO TOCA LA RED NI DRIVE: se llama al método estático directamente, sobre archivos de
 * laboratorio creados aquí. No se sube ni se lee ningún documento real.
 *
 * Lo que NO se bloquea es tan importante como lo que sí: el JavaScript se deja pasar porque
 * dos documentos legítimos del sistema lo traen (uno es un título de propiedad). Si alguien
 * cambia eso, esta prueba lo avisa antes de que el usuario se quede sin poder subir sus
 * papeles.
 */
class PdfConContenidoActivoTest extends MySqlTestCase
{
    /** Escribe un PDF de laboratorio con el cuerpo que se le pase y devuelve su ruta. */
    private function pdf(string $cuerpo): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'pdfact') . '.pdf';
        file_put_contents($ruta, "%PDF-1.4\n" . $cuerpo . "\ntrailer\n%%EOF\n");
        return $ruta;
    }

    private function rechaza(string $cuerpo): ?string
    {
        $ruta = $this->pdf($cuerpo);
        try {
            GoogleDriveService::comprobarPdfCompleto($ruta, 'prueba.pdf');
            return null;                       // lo aceptó
        } catch (PdfNoValido $e) {
            return $e->getMessage();           // lo rechazó, con su motivo
        } finally {
            @unlink($ruta);
        }
    }

    public function test_no_entra_un_pdf_que_lanza_programas(): void
    {
        $motivo = $this->rechaza('1 0 obj << /Type /Action /S /Launch /F (calc.exe) >> endobj');
        $this->assertNotNull($motivo, 'Un PDF con /Launch tiene que rechazarse.');
        $this->assertStringContainsString('ejecutar programas', $motivo);
    }

    public function test_no_entra_un_pdf_con_archivos_escondidos_dentro(): void
    {
        $motivo = $this->rechaza('1 0 obj << /Type /Filespec /EF << /F 2 0 R >> /EmbeddedFile >> endobj');
        $this->assertNotNull($motivo, 'Un PDF con /EmbeddedFile tiene que rechazarse.');
        $this->assertStringContainsString('escondidos', $motivo);
    }

    public function test_no_entra_un_pdf_con_multimedia_incrustada(): void
    {
        $this->assertNotNull($this->rechaza('1 0 obj << /Subtype /RichMedia >> endobj'));
        $this->assertNotNull($this->rechaza('1 0 obj << /Subtype /Flash >> endobj'));
    }

    /**
     * El archivo se lee por trozos de 1 MB. Un marcador que caiga en la costura entre dos
     * trozos tiene que verse igual: por eso hay solape.
     */
    public function test_lo_encuentra_aunque_este_lejos_y_parta_la_lectura(): void
    {
        $relleno = str_repeat("% relleno\n", 120000);   // ~1,2 MB: pasa de un trozo
        $motivo = $this->rechaza($relleno . '<< /S /Launch >>' . $relleno);
        $this->assertNotNull($motivo, 'El marcador lejos del inicio tiene que detectarse igual.');
    }

    public function test_un_documento_normal_pasa_sin_problemas(): void
    {
        $this->assertNull($this->rechaza('1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj'));
    }

    /**
     * Lo que intenta SALIR del documento: mandar datos a otra dirección, leer otro archivo
     * o traerse algo remoto. Es lo que pidió el usuario como "que no lea el servidor".
     * Ninguno aparece en los 43 documentos reales de la máquina.
     */
    public function test_no_entra_un_pdf_que_intenta_salir_a_buscar_algo(): void
    {
        $casos = [
            '/SubmitForm'  => '1 0 obj << /Type /Action /S /SubmitForm /F (http://ajeno/x) >> endobj',
            '/ImportData'  => '1 0 obj << /Type /Action /S /ImportData /F (C:/passwords.txt) >> endobj',
            '/GoToR'       => '1 0 obj << /Type /Action /S /GoToR /F (//servidor/otro.pdf) >> endobj',
            '/XFA'         => '1 0 obj << /XFA [ (preamble) 2 0 R ] >> endobj',
            '/JBIG2Decode' => '1 0 obj << /Filter /JBIG2Decode /Length 10 >> endobj',
        ];
        foreach ($casos as $marca => $cuerpo) {
            $this->assertNotNull($this->rechaza($cuerpo), "Un PDF con {$marca} tiene que rechazarse.");
        }
    }

    /** Los enlaces web SÍ pasan: 9 de los 43 documentos reales los traen. */
    public function test_un_enlace_web_normal_no_bloquea(): void
    {
        $this->assertNull(
            $this->rechaza('1 0 obj << /Type /Action /S /URI /URI (https://www.intt.gob.ve) >> endobj'),
            'Bloquear /URI dejaría fuera documentos legítimos (9 de 43 lo traen).'
        );
    }

    /**
     * El techo de tamaño vive en la comprobación central, no solo en el formulario: así no
     * se puede entrar por otra pantalla a subir un archivo enorme.
     */
    public function test_un_pdf_que_pasa_del_techo_no_entra(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'grande') . '.pdf';
        // Justo por encima del límite, con la cabecera y el cierre correctos.
        $relleno = str_repeat('A', (GoogleDriveService::MAX_PDF_KB + 50) * 1024);
        file_put_contents($ruta, "%PDF-1.4\n% " . $relleno . "\ntrailer\n%%EOF\n");

        try {
            GoogleDriveService::comprobarPdfCompleto($ruta, 'pesado.pdf');
            $this->fail('Un PDF por encima del techo tiene que rechazarse.');
        } catch (PdfNoValido $e) {
            $this->assertStringContainsString('maximo', $e->getMessage());
            $this->assertStringContainsString('3.000 KB', $e->getMessage(), 'El aviso dice el límite en KB.');
        } finally {
            @unlink($ruta);
        }
    }

    /** Y uno normal, muy por debajo, entra sin problema. */
    public function test_un_documento_del_tamano_de_siempre_entra(): void
    {
        // La mediana de los documentos reales son 303 KB.
        $this->assertNull($this->rechaza('% ' . str_repeat('A', 300 * 1024)));
    }

    /**
     * A PROPÓSITO: el JavaScript NO bloquea. Lo traen documentos legítimos (Word y algunos
     * escáneres lo meten para validar campos) y rechazarlos dejaría al usuario sin poder
     * subir sus propios títulos y pólizas. Si esta prueba empieza a fallar es que alguien
     * endureció el criterio: antes de darlo por bueno hay que volver a medirlo contra los
     * documentos reales de la flota.
     */
    public function test_el_javascript_no_bloquea_porque_lo_traen_documentos_de_verdad(): void
    {
        $this->assertNull(
            $this->rechaza('1 0 obj << /S /JavaScript /JS (app.alert\(1\)) >> endobj'),
            'Bloquear /JavaScript dejaría fuera documentos legítimos del sistema.'
        );
    }
}
