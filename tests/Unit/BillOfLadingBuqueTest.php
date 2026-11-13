<?php

namespace Tests\Unit;

use App\Support\BillOfLading;
use PHPUnit\Framework\TestCase;

/** El buque sale una sola vez: sin el nro de BL pegado y sin tomar el puerto por buque. */
class BillOfLadingBuqueTest extends TestCase
{
    public function test_el_anexo_no_arrastra_el_nro_de_bl(): void
    {
        foreach (["V/V:HONCHO V2512 BL NO.:HCLKGT03", "V/V:HONCHO V2512   BL NO.:HCLKGT03", "V/V: HONCHO V2512 B/L NO. RAQDLA12\nITEM"] as $texto) {
            $this->assertSame('HONCHO V2512', BillOfLading::leer($texto)['buque'], $texto);
        }
    }

    public function test_una_sola_linea_con_coma_es_el_puerto(): void
    {
        $bl = BillOfLading::leer("Vessel\nPort of loading\nQINGDAO,CHINA\nPort of discharge\nLA GUAIRA,VENEZUELA");
        $this->assertNull($bl['buque']);
        $this->assertSame('QINGDAO,CHINA', $bl['puerto_carga']);
    }
}
