<?php

namespace Tests\Feature;

use App\Support\ProyeccionCombustible;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * La regla del chuto vive en UN solo sitio y el reporte Excel y el dashboard tienen que
 * dar el MISMO numero. Estas pruebas fijan las dos mitades: el reparto en si, y que la
 * suma de la web coincida con la que arma el Excel.
 */
class ProyeccionCombustibleTest extends MySqlTestCase
{
    public function test_el_reparto_cubre_primero_los_remolques_diarios(): void
    {
        // Caso real del frente TUBERIA 12'': 5 chutos, 4 bateas, 1 lowboy.
        $this->assertSame(
            ['diario' => 4, 'lowboy' => 1, 'sueltos' => 0],
            ProyeccionCombustible::repartirChutos(5, 4, 1)
        );

        // El caso borde que pidio operaciones: 1 chuto + 1 batea + 1 lowboy cuenta como
        // batea. No puede halar los dos y la batea es el trabajo de todos los dias.
        $this->assertSame(
            ['diario' => 1, 'lowboy' => 0, 'sueltos' => 0],
            ProyeccionCombustible::repartirChutos(1, 1, 1)
        );

        // Frente de puro lowboy (EMERGENCIA LA GUAIRA: 3 chutos, 0 diarios, 3 lowboys).
        $this->assertSame(
            ['diario' => 0, 'lowboy' => 3, 'sueltos' => 0],
            ProyeccionCombustible::repartirChutos(3, 0, 3)
        );

        // Mas chutos que remolques (COMOR: 5 chutos, 0 diarios, 3 lowboys): los que
        // sobran siguen operando y cuentan a tarifa plena.
        $this->assertSame(
            ['diario' => 0, 'lowboy' => 3, 'sueltos' => 2],
            ProyeccionCombustible::repartirChutos(5, 0, 3)
        );

        // Sin remolques no hay nada que descontar.
        $this->assertSame(
            ['diario' => 0, 'lowboy' => 0, 'sueltos' => 8],
            ProyeccionCombustible::repartirChutos(8, 0, 0)
        );
    }

    public function test_sin_lowboys_no_hay_descuento(): void
    {
        // TRASEGADO DE CRUDO (24): 13 chutos y 12 vacuums, ningun lowboy.
        $this->assertSame(0.0, ProyeccionCombustible::descuentoLowboy([24]));
    }

    public function test_el_descuento_del_frente_de_agua_salada(): void
    {
        // 5 chutos a 150, de los cuales 1 va con lowboy => se descuentan 150 - 50 = 100.
        $this->assertSame(100.0, ProyeccionCombustible::descuentoLowboy([43]));
    }

    /**
     * La razon de ser de esta clase: el Excel y el dashboard tienen que coincidir.
     * Se reproduce el calculo de las dos vias y se comparan.
     */
    public function test_el_excel_y_el_dashboard_dan_el_mismo_total(): void
    {
        $frentes = [43, 72, 47, 3, 74, 10, 46, 11, 4, 57, 20, 22, 24, 73];

        // Via DASHBOARD: suma plana de las dos tablas menos el descuento por lowboy.
        $plano = (float) DB::table('equipos')->whereNull('deleted_at')
                ->whereIn('ID_FRENTE_ACTUAL', $frentes)->where('COMBUSTIBLE', 'GASOIL')
                ->sum('CONSUMO_PROMEDIO')
            + (float) DB::table('equipos_auxiliares')->whereNull('deleted_at')
                ->whereIn('ID_FRENTE_ACTUAL', $frentes)->where('COMBUSTIBLE', 'GASOIL')
                ->sum('CONSUMO_PROMEDIO');
        $dashboard = $plano - ProyeccionCombustible::descuentoLowboy($frentes);

        // Via EXCEL: fila por fila, con la fila CHUTO ya desglosada.
        $excel = 0.0;
        foreach ($frentes as $idFrente) {
            $filas = DB::table('equipos as e')
                ->join('tipo_equipos as t', 't.id', '=', 'e.id_tipo_equipo')
                ->whereNull('e.deleted_at')->where('e.ID_FRENTE_ACTUAL', $idFrente)
                ->where('e.COMBUSTIBLE', 'GASOIL')->whereNotNull('e.CONSUMO_PROMEDIO')
                ->groupBy('t.nombre', 'e.CONSUMO_PROMEDIO')
                ->select('t.nombre as tipo', 'e.CONSUMO_PROMEDIO as consumo', DB::raw('COUNT(*) as und'))
                ->get();

            foreach ($filas as $f) {
                if ($f->tipo === 'CHUTO') {
                    foreach (ProyeccionCombustible::filasChuto($idFrente, (int) $f->und, (float) $f->consumo) as [, $und, $litros]) {
                        $excel += $und * $litros;
                    }
                    continue;
                }
                $excel += $f->und * (float) $f->consumo;
            }

            $excel += (float) DB::table('equipos_auxiliares')->whereNull('deleted_at')
                ->where('ID_FRENTE_ACTUAL', $idFrente)->where('COMBUSTIBLE', 'GASOIL')
                ->sum('CONSUMO_PROMEDIO');
        }

        $this->assertEqualsWithDelta($excel, $dashboard, 0.01,
            'El Excel y el dashboard deben proyectar los mismos litros: si divergen, alguno dejó de usar ProyeccionCombustible.');
    }
}
