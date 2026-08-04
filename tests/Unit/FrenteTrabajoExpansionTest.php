<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\FrenteTrabajo;
use App\Models\Equipo;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FrenteTrabajoExpansionTest extends TestCase
{
    // We won't use RefreshDatabase to avoid destroying user data if they are running in dev
    // Instead we will use a transaction or just cleanup manually

    public function test_frente_trabajo_has_new_fields()
    {
        $frente = new FrenteTrabajo();
        $fields = [
            'RESP_3_NOM',
            'RESP_3_CAR',
            'RESP_4_NOM',
            'RESP_4_CAR',
            'RESP_1_EQU',
            'RESP_2_EQU',
            'RESP_3_EQU',
            'RESP_4_EQU'
        ];

        foreach ($fields as $field) {
            $this->assertTrue(in_array($field, $frente->getFillable()), "Field $field is not in fillable");
        }
    }

    public function test_pdf_logic_scenario()
    {
        // Mocking the data that goes into the PDF view
        $frente = new FrenteTrabajo([
            'RESP_1_NOM' => 'Resp 1',
            'RESP_1_CAR' => 'Car 1',
            'RESP_1_EQU' => 'CAT1',
            'RESP_2_NOM' => 'Resp 2',
            'RESP_2_CAR' => 'Car 2',
            'RESP_2_EQU' => 'CAT2',
            'RESP_3_NOM' => 'Resp 3',
            'RESP_3_CAR' => 'Car 3',
            'RESP_3_EQU' => null,
            'RESP_4_NOM' => 'Resp 4',
            'RESP_4_CAR' => 'Car 4',
            'RESP_4_EQU' => 'CAT4',
        ]);

        $equipos = collect([
            new Equipo(['CATEGORIA_FLOTA' => 'CAT1']),
            new Equipo(['CATEGORIA_FLOTA' => 'CAT3']),
        ]);

        $usuarioEmisor = new \stdClass();
        $usuarioEmisor->frenteAsignado = $frente;

        // Manual implementation of the logic in the blade
        $categoriesInActa = $equipos->pluck('CATEGORIA_FLOTA')->unique()->filter()->values()->toArray();
        $this->assertEquals(['CAT1', 'CAT3'], $categoriesInActa);

        $responsablesToShow = [];
        for ($i = 1; $i <= 4; $i++) {
            $nomKey = "RESP_{$i}_NOM";
            $carKey = "RESP_{$i}_CAR";
            $equKey = "RESP_{$i}_EQU";

            $nom = $frente->$nomKey ?? null;
            $car = $frente->$carKey ?? 'RESPONSABLE';
            $equ = $frente->$equKey ?? null;

            if ($nom) {
                if ($equ) {
                    if (in_array($equ, $categoriesInActa)) {
                        $responsablesToShow[] = ['nom' => $nom, 'car' => $car];
                    }
                } else {
                    $responsablesToShow[] = ['nom' => $nom, 'car' => $car];
                }
            }
        }

        // Expected: 
        // Resp 1 (CAT1 in Acta) -> SHOW
        // Resp 2 (CAT2 NOT in Acta) -> HIDE
        // Resp 3 (No CAT) -> SHOW
        // Resp 4 (CAT4 NOT in Acta) -> HIDE

        $this->assertCount(2, $responsablesToShow);
        $this->assertEquals('Resp 1', $responsablesToShow[0]['nom']);
        $this->assertEquals('Resp 3', $responsablesToShow[1]['nom']);
    }
}
