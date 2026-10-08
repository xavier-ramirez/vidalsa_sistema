<?php

namespace Tests\Unit;

use App\Models\MovimientoInventario;
use PHPUnit\Framework\TestCase;

/**
 * MovimientoInventario::expandirRangoMes: fuente única del rango Desde/Hasta del módulo de
 * inventario (Historial, Dashboard de Consumo y su Excel).
 */
class RangoFechasMovimientoTest extends TestCase
{
    public function test_por_dia_pasa_tal_cual(): void
    {
        $this->assertSame(['2026-09-15', '2026-09-15'], MovimientoInventario::expandirRangoMes('2026-09-15', '2026-09-15'));
    }

    public function test_por_mes_se_expande_al_primer_y_ultimo_dia(): void
    {
        $this->assertSame(['2026-02-01', '2026-02-28'], MovimientoInventario::expandirRangoMes('2026-02', '2026-02'));
    }

    public function test_un_extremo_solo(): void
    {
        $this->assertSame(['2026-10-01', null], MovimientoInventario::expandirRangoMes('2026-10-01', null));
        $this->assertSame([null, '2026-07-31'], MovimientoInventario::expandirRangoMes('', '2026-07-31'));
    }

    public function test_rango_al_reves_se_toma_al_derecho(): void
    {
        $this->assertSame(['2026-09-01', '2026-09-30'], MovimientoInventario::expandirRangoMes('2026-09-30', '2026-09-01'));
        // Por mes también: Desde octubre, Hasta septiembre → del 1 de septiembre al 31 de octubre.
        $this->assertSame(['2026-09-01', '2026-10-31'], MovimientoInventario::expandirRangoMes('2026-10', '2026-09'));
    }
}
