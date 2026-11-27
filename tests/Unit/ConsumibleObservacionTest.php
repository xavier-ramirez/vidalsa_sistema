<?php

namespace Tests\Unit;

use App\Models\Consumible;
use PHPUnit\Framework\TestCase;

/** La observación del Excel junto al vehículo se separa sin cortar nombres de equipo con guion. */
class ConsumibleObservacionTest extends TestCase
{
    public function test_separa_la_observacion(): void
    {
        $casos = [
            'MACK VACUUM -       Apoyo a  ECOINNOVA, C.A - ' => ['MACK VACUUM', 'Apoyo a ECOINNOVA, C.A'],
            'Camión Plataforma HYUNDAI con Maquina de Soldar -  Apoyo a  ECOINNOVA, C.A ' => ['Camión Plataforma HYUNDAI con Maquina de Soldar', 'Apoyo a ECOINNOVA, C.A'],
            'CAMION DYNA (APOYO)' => ['CAMION DYNA', 'APOYO'],
        ];
        foreach ($casos as $desc => [$equipo, $obs]) {
            $this->assertSame($obs, Consumible::observacionDe($desc), $desc);
            $this->assertSame($equipo, Consumible::descSinObservacion($desc), $desc);
        }
    }

    public function test_no_corta_nombres_de_equipo(): void
    {
        foreach ([
            'PAYLOADER - LW 800K N°6', 'RETROEXCAVADORA - 310J N°1', 'D6 -XCMG - N° 2', 'VOLQUETA -JAC GALLOP N°44',
            'COMPRESOR SANBLASTING (MORICHAL)', 'CAMION DE SOLDADURA (MAQUINAS DE SOLDAR Y CAMION)',
            'APOYO DE COMBUSTIBLE PARA TRANSVERSALES', '', null,
        ] as $desc) {
            $this->assertNull(Consumible::observacionDe($desc), (string) $desc);
        }
    }
}
