<?php

namespace Tests\Feature;

use App\Services\LectorDocumentoPdf;
use Tests\TestCase;

/**
 * Lectura de las providencias RACDA del MINEC con textos como los reales (05-10-2026): la lista
 * de placas sale SOLO del bloque de unidades autorizadas y a una providencia que amplía otra
 * (sin "validez por N años") no se le inventa el vencimiento.
 */
class LectorRacdaTest extends TestCase
{
    private function texto(string $tercero, string $pie = ''): string
    {
        return "PROVIDENCIA ADMINISTRATIVA N° 304\nCARACAS, 02 DE MARZO DE 2026.\n"
            . "SEGUNDO: Las ciento treinta y cuatro (134) unidades de transporte terrestre autorizadas para tal fin poseen las siguientes placas:\n"
            . "A05BE3R\nA15BK2R\nX-X-X-X\n"
            . $tercero . "\n" . $pie;
    }

    public function test_el_codigo_de_validacion_del_pie_no_se_toma_por_placa(): void
    {
        $datos = app(LectorDocumentoPdf::class)->extraer('racda', $this->texto(
            'TERCERO: La AUTORIZACIÓN es de carácter INTRANSFERIBLE, y tendrá validez por DOS (02) años.',
            'La presente providencia puede ser consultada mediante el código de validación N° 1AVFcGK'
        ));

        $this->assertSame(['A05BE3R', 'A15BK2R'], $datos['placas']);
        $this->assertSame('2028-03-02', $datos['vence']);
    }

    /** La lista sigue en otra pagina: el pie de la primera queda DENTRO del bloque de placas. */
    public function test_el_pie_de_una_pagina_en_medio_de_la_lista_tampoco_es_placa(): void
    {
        $texto = "PROVIDENCIA ADMINISTRATIVA N° 304
CARACAS, 02 DE MARZO DE 2026.
"
            . "SEGUNDO: Las unidades autorizadas para tal fin poseen las siguientes placas:
"
            . "A05BE3R
La presente providencia puede ser consultada mediante el código de validación N° 1AVFcGK
"
            . "Página 1 de 2
A15BK2R
"
            . "TERCERO: La AUTORIZACIÓN tendrá validez por DOS (02) años.";

        $datos = app(LectorDocumentoPdf::class)->extraer('racda', $texto);

        $this->assertSame(['A05BE3R', 'A15BK2R'], $datos['placas']);

        // Y como lo escriba el OCR: "No.", "Nro" o "Nº:".
        foreach (['No.', 'Nro', 'Nº:'] as $n) {
            $otro = str_replace('N° 1AVFcGK', "$n 1AVFcGK", $texto);
            $this->assertSame(['A05BE3R', 'A15BK2R'], app(LectorDocumentoPdf::class)->extraer('racda', $otro)['placas'], $n);
        }
    }

    public function test_una_providencia_que_amplia_otra_no_inventa_su_vencimiento(): void
    {
        $datos = app(LectorDocumentoPdf::class)->extraer('racda', $this->texto(
            'TERCERO: Reconocer la validez de la Providencia Administrativa Nº 1120 de fecha 14-07-2025, contentiva de la autorización'
        ));

        $this->assertSame('304', $datos['nro']);
        $this->assertSame('2026-03-02', $datos['emision']);
        $this->assertSame(['A05BE3R', 'A15BK2R'], $datos['placas']);
        $this->assertNull($datos['vence'], 'No dice cuánto vale: se deja vacío en vez de adivinarlo.');
    }

    public function test_sin_el_bloque_de_la_lista_se_busca_en_toda_la_hoja(): void
    {
        $datos = app(LectorDocumentoPdf::class)->extraer('racda', "PROVIDENCIA ADMINISTRATIVA N° 9\nA05BE3R A15BK2R");

        $this->assertSame(['A05BE3R', 'A15BK2R'], $datos['placas']);
    }
}
