<?php

namespace Tests\Unit;

use App\Support\BillOfLading;
use PHPUnit\Framework\TestCase;

/** Del buque solo el nombre: sin el viaje, sin el nro de BL pegado y sin tomar el puerto por buque. */
class BillOfLadingBuqueTest extends TestCase
{
    public function test_el_anexo_no_arrastra_el_nro_de_bl(): void
    {
        foreach (["V/V:HONCHO V2512 BL NO.:HCLKGT03", "V/V:HONCHO V2512   BL NO.:HCLKGT03", "V/V: HONCHO V2512 B/L NO. RAQDLA12\nITEM"] as $texto) {
            $this->assertSame('HONCHO', BillOfLading::leer($texto)['buque'], $texto);
        }
    }

    public function test_sin_el_viaje(): void
    {
        $this->assertSame('RUI AN YANG', BillOfLading::leer("RUI AN YANG V.2524
QINGDAO,CHINA")['buque']);
        $this->assertSame('HONCHO', BillOfLading::leer("Port of loading
HONCHO
V.2512
LONGKOU,CHINA
Port of discharge")['buque']);
    }

    /** Como lo deja el OCR de Drive en los BL reales (textos recortados de los PDF del sistema). */
    public function test_el_buque_pegado_a_su_rotulo_en_los_bl_reales(): void
    {
        $casos = [
            "Vessel Port of loading RUI AN YANG V.2524 QINGDAO,CHINA Port of discharge \nLA GUAIRA,VENEZUELA" => 'RUI AN YANG',
            "Vessel Port of loading  HONCHO V.2512 LONGKOU,CHINA  Port of discharge \nGUANTA, VENEZUELA" => 'HONCHO',
            "TAX ID:J293877199 Vessel Port of loading MV HOWES JOANNA V.2515 LONGKOU,CHINA Port of discharge GUANTA" => 'HOWES JOANNA',
            "Vessel \nRUI AN YANG V.2524 Port of discharge \nLA GUAIRA, VENEZUELA SHIPPING MARK" => 'RUI AN YANG',
            "Vessel \nMV HORIZON HOPE \nMAR26VC005 \nPort of discharge" => 'HORIZON HOPE',
            "VESSEL/ VOYAGE: MV HORIZON HOPE MAR26VC005 \nOCEAN FREIGHT" => 'HORIZON HOPE',
            "AS THE AGENT ON BEHALF MASTER OF MV : RUI AN  YANG \nBL ATTACHMENT \nV/V:HONCHO V2512 BL NO.: RAQDLA12" => 'RUI AN YANG',
            "VESSEL PORT OF LOADING HONCHO V.2512 LONGKOU,CHINA" => 'HONCHO',
            // Rotulos con dos puntos, /VOYAGE o NAME, y viajes que no son V.1234.
            "Vessel: RUI AN YANG V.2524" => 'RUI AN YANG',
            "VESSEL/VOYAGE: HONCHO V2512" => 'HONCHO',
            "VESSEL NAME HONCHO V2512" => 'HONCHO',
            "V/V:MV HORIZON HOPE MAR26VC005   BL NO.:LHYT06LG03A" => 'HORIZON HOPE',
        ];
        foreach ($casos as $texto => $buque) {
            $this->assertSame($buque, BillOfLading::leer($texto)['buque'], $texto);
        }
    }

    public function test_varios_bl_seguidos_no_dan_numero(): void
    {
        $this->assertNull(BillOfLading::leer("B/L NO.:LHYT06LG01/LHYT06LG02/LHYT06LG03A")['nro']);
        $this->assertSame('HCLKGT03', BillOfLading::leer("B/L NO. HCLKGT03\nVessel")['nro']);
        // Una barra que no es otro numero de BL no borra el numero.
        $this->assertSame('HCLKGT08', BillOfLading::leer("B/L NO. HCLKGT08\n/ PORT")['nro']);
        $this->assertSame('HCLKGT08', BillOfLading::leer("B/L NO. HCLKGT08/1")['nro']);
    }

    public function test_una_sola_linea_con_coma_es_el_puerto(): void
    {
        $bl = BillOfLading::leer("Vessel\nPort of loading\nQINGDAO,CHINA\nPort of discharge\nLA GUAIRA,VENEZUELA");
        $this->assertNull($bl['buque']);
        $this->assertSame('QINGDAO,CHINA', $bl['puerto_carga']);
    }
}
