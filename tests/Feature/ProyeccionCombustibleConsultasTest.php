<?php

namespace Tests\Feature;

use App\Support\ProyeccionCombustible;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * El "Consumo total" del dashboard de flota (fleet-stats) pregunta el descuento por lowboy de
 * TODOS los frentes a la vez. Antes eran cuatro consultas por frente; ahora son dos para todos,
 * con el mismo resultado que contar frente por frente.
 */
class ProyeccionCombustibleConsultasTest extends MySqlTestCase
{
    public function test_el_descuento_de_todos_los_frentes_usa_dos_consultas_y_da_lo_mismo(): void
    {
        $frentes = DB::table('equipos')->whereNull('deleted_at')->distinct()->pluck('ID_FRENTE_ACTUAL')->all();
        $this->assertGreaterThan(5, count($frentes), 'Hacen falta varios frentes con equipos.');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $total = ProyeccionCombustible::descuentoLowboy($frentes);
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, $consultas, 'Dos consultas agrupadas para todos los frentes, no cuatro por frente.');

        $frentePorFrente = 0.0;
        foreach (array_unique($frentes, SORT_REGULAR) as $idFrente) {
            $frentePorFrente += ProyeccionCombustible::descuentoLowboy([$idFrente]);
        }
        $this->assertEqualsWithDelta($frentePorFrente, $total, 0.0001);
    }

    public function test_conteo_y_consumo_base_coinciden_con_contar_a_mano(): void
    {
        $idFrente = (int) DB::table('equipos as e')->join('tipo_equipos as t', 't.id', '=', 'e.id_tipo_equipo')
            ->whereNull('e.deleted_at')->where('t.nombre', 'CHUTO')->whereNotNull('e.ID_FRENTE_ACTUAL')
            ->groupBy('e.ID_FRENTE_ACTUAL')->orderByRaw('COUNT(*) DESC')->value('e.ID_FRENTE_ACTUAL');
        $this->assertGreaterThan(0, $idFrente, 'Hace falta un frente con chutos.');

        // La consulta de antes, una por grupo de tipos.
        $contar = fn (array $tipos) => (int) DB::table('equipos as e')
            ->join('tipo_equipos as t', 't.id', '=', 'e.id_tipo_equipo')
            ->whereNull('e.deleted_at')->whereIn('t.nombre', $tipos)
            ->where('e.ID_FRENTE_ACTUAL', $idFrente)->count();

        $this->assertSame([
            'chutos'  => $contar(['CHUTO']),
            'diarios' => $contar(ProyeccionCombustible::REMOLQUES_DIARIOS),
            'lowboys' => $contar(ProyeccionCombustible::REMOLQUES_LOWBOY),
        ], ProyeccionCombustible::conteoFrente($idFrente));

        $base = (float) (DB::table('equipos as e')->join('tipo_equipos as t', 't.id', '=', 'e.id_tipo_equipo')
            ->whereNull('e.deleted_at')->where('t.nombre', 'CHUTO')->whereNotNull('e.CONSUMO_PROMEDIO')
            ->where('e.ID_FRENTE_ACTUAL', $idFrente)->max('e.CONSUMO_PROMEDIO') ?? 0);
        $this->assertSame($base, ProyeccionCombustible::consumoBaseChuto($idFrente));

        // Un frente que no existe cuenta cero, igual que antes.
        $this->assertSame(['chutos' => 0, 'diarios' => 0, 'lowboys' => 0], ProyeccionCombustible::conteoFrente(999999999));
        $this->assertSame(0.0, ProyeccionCombustible::consumoBaseChuto(999999999));
    }
}
