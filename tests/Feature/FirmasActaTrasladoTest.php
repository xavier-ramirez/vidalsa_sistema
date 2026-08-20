<?php

namespace Tests\Feature;

use App\Http\Controllers\MovilizacionController;
use App\Models\FrenteTrabajo;
use Tests\MySqlTestCase;

/**
 * Las firmas del acta de traslado.
 *
 * Lo que fija esta prueba: que el acta PINTE todas las firmas que el controlador
 * resuelve. El bloque de firmas recortaba a dos las de cualquier frente que no fuera
 * Patio Maturín, así que un frente con tres responsables perdía el tercero sin avisar
 * y sin error: el PDF salía bien formado, solo que con una firma menos.
 *
 * Se comprueba renderizando el blade, no leyendo el PDF: el recorte ocurría al pintar,
 * y el HTML es donde se ve. extractFirmasActa ya devolvía las tres.
 */
class FirmasActaTrasladoTest extends MySqlTestCase
{
    /** Frente de mentira con tantos responsables como se pidan (no se guarda). */
    private function frenteCon(int $cuantos, string $nombre = 'PROYECTO DE PRUEBA'): FrenteTrabajo
    {
        $f = new FrenteTrabajo();
        $f->ID_FRENTE     = 9999;
        $f->NOMBRE_FRENTE = $nombre;
        $f->TIPO_FRENTE   = 'PROYECTO';
        $f->UBICACION     = 'UBICACION';

        for ($i = 1; $i <= 5; $i++) {
            $f->{"RESP_{$i}_NOM"} = $i <= $cuantos ? "RESPONSABLE NUMERO {$i}" : null;
            $f->{"RESP_{$i}_CAR"} = $i <= $cuantos ? "CARGO {$i}" : null;
            $f->{"RESP_{$i}_CED"} = $i <= $cuantos ? "1000000{$i}" : null;
            $f->{"RESP_{$i}_EQU"} = null;
        }

        return $f;
    }

    private function firmasDe(FrenteTrabajo $frente, array $equipos): array
    {
        $m = new \ReflectionMethod(new MovilizacionController(), 'extractFirmasActa');
        $m->setAccessible(true);

        return $m->invoke(new MovilizacionController(), $frente, $equipos);
    }

    private function html(FrenteTrabajo $frenteOrigen, array $firmas): string
    {
        $equipos = collect([(object) [
            'CATEGORIA_FLOTA' => 'FLOTA PESADA',
            'MARCA' => 'MARCA', 'MODELO' => 'MODELO', 'SERIAL_CHASIS' => 'SERIAL',
            'ANIO' => 2020, 'COLOR' => '', 'CODIGO_PATIO' => 'V-1',
        ]]);

        return view('admin.movilizaciones.acta_traslado_pdf', [
            'equipos'       => $equipos,
            'frenteOrigen'  => $frenteOrigen,
            'frenteDestino' => $this->frenteCon(0, 'DESTINO'),
            'fechaActa'     => '01/01/2026',
            'firmas'        => $firmas,
        ])->render();
    }

    public static function cantidades(): array
    {
        return [
            'un responsable'     => [1],
            'dos responsables'   => [2],
            'tres responsables'  => [3],   // el caso que se perdía
            'cuatro responsables' => [4],
            'cinco responsables' => [5],
        ];
    }

    /**
     * @dataProvider cantidades
     */
    public function test_el_acta_pinta_todas_las_firmas(int $cuantos): void
    {
        $frente = $this->frenteCon($cuantos);
        $firmas = $this->firmasDe($frente, [['CATEGORIA_FLOTA' => 'FLOTA PESADA']]);

        $this->assertCount($cuantos, $firmas,
            "extractFirmasActa devolvió " . count($firmas) . " firmas para $cuantos responsables.");

        $html = $this->html($frente, $firmas);

        foreach ($firmas as $f) {
            $this->assertStringContainsString(
                mb_strtoupper($f['nom']), $html,
                "Con $cuantos responsables, \"{$f['nom']}\" ({$f['label']}) no se pinta en el acta."
            );
        }
    }

    /** El bloque de destino va en blanco y aparece UNA sola vez, sea cual sea el número de firmas. */
    public function test_el_recibido_por_del_destino_sale_una_vez_y_en_blanco(): void
    {
        foreach ([0, 1, 2, 3, 4, 5] as $cuantos) {
            $frente = $this->frenteCon($cuantos);
            $html   = $this->html($frente, $this->firmasDe($frente, [['CATEGORIA_FLOTA' => 'FLOTA PESADA']]));

            $this->assertSame(1, substr_count($html, 'RECIBIDO POR (DESTINO)'),
                "Con $cuantos responsables el bloque RECIBIDO POR no sale exactamente una vez.");
            $this->assertStringContainsString('Nombre: ____________________', $html,
                'El RECIBIDO POR del destino debe ir en blanco: se firma a mano al recibir.');
        }
    }

    /**
     * La cédula sale FORMATEADA con puntos de millar. El formateador ($fmtCed) se define en
     * el acta y se consume dentro de partials/tarjeta_firma, que lo hereda del @include: si
     * alguien mueve ese cierre o renombra la variable, las cédulas desaparecen del PDF sin
     * romper nada más — el resto del acta sigue pintándose igual.
     */
    public function test_las_cedulas_salen_formateadas(): void
    {
        $frente = $this->frenteCon(0);
        $frente->RESP_3_NOM = 'RESPONSABLE CON CEDULA';
        $frente->RESP_3_CAR = 'CARGO';
        $frente->RESP_3_CED = '26605665';

        $firmas = $this->firmasDe($frente, [['CATEGORIA_FLOTA' => 'FLOTA PESADA']]);
        $html   = $this->html($frente, $firmas);

        $this->assertStringContainsString('26.605.665', $html,
            'La cédula no sale con puntos de millar: $fmtCed no llegó a la tarjeta de firma.');
        $this->assertStringNotContainsString('26605665', str_replace('26.605.665', '', $html),
            'La cédula sale también sin formatear: hay dos sitios pintándola.');
    }

    /** Un responsable sin nombre no ocupa una firma, aunque tenga cédula o cargo. */
    public function test_los_responsables_sin_nombre_no_cuentan(): void
    {
        $frente = $this->frenteCon(0);
        $frente->RESP_1_CED = '26605665';          // como el AYACUCHO real: cédula suelta
        $frente->RESP_3_NOM = 'LIMBER GARCIA';
        $frente->RESP_3_CAR = 'COORDINADOR';

        $firmas = $this->firmasDe($frente, [['CATEGORIA_FLOTA' => 'FLOTA PESADA']]);

        $this->assertCount(1, $firmas, 'La cédula suelta del responsable 1 no debe generar una firma.');
        $this->assertSame('LIMBER GARCIA', $firmas[0]['nom']);
    }
}
