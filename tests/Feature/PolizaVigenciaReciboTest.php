<?php

namespace Tests\Feature;

use App\Services\LectorDocumentoPdf;
use Tests\TestCase;

/**
 * Póliza con pago semestral (Pirámide): el cuadro trae la vigencia del SEGURO y la del RECIBO.
 * El OCR deja el rótulo «VIGENCIA DEL SEGURO:» lejos de sus fechas y solo la del recibo pegada al
 * suyo: se leía el vencimiento del recibo (70 pólizas vencían el 09/12/2026 en vez del
 * 09/06/2027, 24-11-2026). Texto inventado con la misma forma que el OCR real.
 */
class PolizaVigenciaReciboTest extends TestCase
{
    private function leer(string $texto): array
    {
        return app(LectorDocumentoPdf::class)->extraer(LectorDocumentoPdf::POLIZA, $texto);
    }

    public function test_cuadro_desarmado_toma_la_vigencia_del_seguro_y_no_la_del_recibo(): void
    {
        $texto = "CUADRO Y RECIBO DE POLIZA\nRECIBO: 0000001 DIRECCIÓN DE COBRO:\nHORA DE VIGENCIA: 12:00 M\n"
            . "FRECUENCIA DE PAGO:\n9999999 VIGENCIA DEL SEGURO:\nCODIGO INTERMEDIARIOS % 1 PERSONA DE PRUEBA 100.00\n"
            . "MARCA: MODELO:\nUSO:\nSEMESTRAL 21/10/2026 al\n09/06/2027 SUCURSAL: CARACAS\nPLACA: AB123CD\n"
            . "TOTAL PRIMA 10,00 USD\nVIGENCIA DEL RECIBO: 21/10/2026 al 09/12/2026 ANEXO LIBRE Fecha de Cobro: ____\n";

        $d = $this->leer($texto);

        $this->assertSame('2026-10-21', $d['desde']);
        $this->assertSame('2027-06-09', $d['vence'], 'Vence la póliza, no el recibo.');
        $this->assertSame('2026-10-21', $d['emision'], 'El inicio de la póliza sirve de emisión.');
    }

    public function test_con_rotulo_pegado_tambien_gana_la_del_seguro_aunque_el_recibo_vaya_antes(): void
    {
        $d = $this->leer("VIGENCIA DEL RECIBO: 01/02/2026 al 01/08/2026\nVIGENCIA DEL SEGURO: 01/02/2026 al 01/02/2027\n");
        $this->assertSame('2027-02-01', $d['vence']);
    }

    public function test_si_solo_hay_la_del_recibo_queda_esa_y_no_se_usa_de_emision(): void
    {
        $d = $this->leer("CUADRO RECIBO\nVIGENCIA DEL RECIBO: 01/02/2026 al 01/08/2026\n");
        $this->assertSame('2026-08-01', $d['vence']);
        $this->assertNull($d['emision']);
    }

    public function test_desde_hasta_de_mampreca_sigue_igual(): void
    {
        $d = $this->leer("Vigencia\nDesde 16/01/2026 Hasta 16/01/2027\nFecha de Emisión: 15/01/2026\n");
        $this->assertSame(['2026-01-16', '2027-01-16', '2026-01-15'], [$d['desde'], $d['vence'], $d['emision']]);
    }
}
