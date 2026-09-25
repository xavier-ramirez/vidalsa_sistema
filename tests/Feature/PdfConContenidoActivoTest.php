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
