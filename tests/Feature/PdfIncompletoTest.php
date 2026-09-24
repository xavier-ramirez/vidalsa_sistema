<?php

namespace Tests\Feature;

use App\Services\GoogleDriveService;
use Tests\MySqlTestCase;

/**
 * Un PDF que llego a medias no se sube.
 *
 * Por que existe esta prueba: el ROTC del equipo 23 estaba truncado y nadie se entero al
 * subirlo. Despues NADA pudo leerlo —el OCR de Drive saco 68 caracteres de 2.957 y Gemini lo
 * rechaza— y el documento se quedo en la ficha aparentando estar bien. La comprobacion vive en
 * GoogleDriveService::subirPdf, que es la puerta por la que entran TODOS los PDF (la ficha de
 * uno en uno, la carga masiva y los auxiliares).
 */
class PdfIncompletoTest extends MySqlTestCase
{
    /** Un PDF de mentira pero ENTERO: cabecera, algo de cuerpo y su marca de fin. */
    private function pdf(int $kb = 4, bool $completo = true): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'pdf') . '.pdf';
        file_put_contents($ruta, "%PDF-1.5\n" . str_repeat('x', $kb * 1024) . ($completo ? "\n%%EOF\n" : "\n"));

        return $ruta;
    }

    public function test_un_pdf_entero_pasa(): void
    {
        GoogleDriveService::comprobarPdfCompleto($this->pdf(), 'bueno.pdf');
        $this->assertTrue(true, 'no debería lanzar nada');
    }

    public function test_un_pdf_cortado_a_medias_se_rechaza_diciendo_por_que(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/incompleto|corto a medias/i');

        GoogleDriveService::comprobarPdfCompleto($this->pdf(4, false), 'cortado.pdf');
    }

    public function test_lo_que_no_es_un_pdf_se_rechaza(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'x') . '.pdf';
        file_put_contents($ruta, str_repeat('esto no es un pdf ', 200));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no es un PDF/i');
        GoogleDriveService::comprobarPdfCompleto($ruta, 'falso.pdf');
    }

    public function test_un_archivo_vacio_se_rechaza(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'v') . '.pdf';
        file_put_contents($ruta, '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/vacio/i');
        GoogleDriveService::comprobarPdfCompleto($ruta, 'vacio.pdf');
    }

    /**
     * El marcador de fin puede no ser el ultimo byte: hay PDF validos con un salto de linea o
     * una firma detras. Por eso se mira en los ultimos 2 KB y no al final exacto.
     */
    public function test_un_pdf_con_basura_despues_del_fin_sigue_valiendo(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'c') . '.pdf';
        file_put_contents($ruta, "%PDF-1.5\n" . str_repeat('x', 4096) . "\n%%EOF\n" . str_repeat("\n", 40));

        GoogleDriveService::comprobarPdfCompleto($ruta, 'con_cola.pdf');
        $this->assertTrue(true);
    }
}
