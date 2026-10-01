<?php

namespace Tests\Feature;

use App\Http\Controllers\EquipoController;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Filtro por TIPO del listado de Equipos (/admin/equipos?id_tipo=N).
 *
 * Un tipo es un filtro concreto, como la marca o el modelo: trae TODOS los equipos de ese
 * tipo, también los de frentes ESPECIAL (asignaciones especiales). Si no, la tabla y su TOTAL
 * no cuadraban con la tarjeta "Ubicación por Frente", que sí los contaba.
 */
class EquiposFiltroTipoTest extends MySqlTestCase
{
    private function usuarioGlobal(): Usuario
    {
        $u = Usuario::where('ESTATUS', 'ACTIVO')->get()
            ->first(fn ($u) => $u->frentesVisiblesEquiposIds() === null && !$u->getFrentesBloqueadosIds()
                && $u->can('equipos.view'));
        $this->assertNotNull($u, 'Hace falta un usuario activo que vea todos los frentes y Equipos.');
        return $u;
    }

    /** IDs de los equipos que pinta la tabla (el botón de detalle de cada fila). */
    private function filas(array $q): array
    {
        $html = $this->actingAs($this->usuarioGlobal())->get('/admin/equipos?' . http_build_query($q))
            ->assertOk()->getContent();
        preg_match_all('/<button type="button"\s+data-equipo-id="(\d+)"[^>]*onclick="showDetailsImproved/s', $html, $m);
        $ids = array_map('intval', array_unique($m[1]));
        sort($ids);
        return $ids;
    }

    public function test_el_tipo_trae_tambien_los_de_frentes_especiales(): void
    {
        // Un tipo con equipos en un frente ESPECIAL y fuera de él, y de pocos equipos en total
        // (contando los que no tienen frente): la tabla pinta por tandas de 150.
        $tipo = DB::table('equipos as e')
            ->leftJoin('frentes_trabajo as f', 'f.ID_FRENTE', '=', 'e.ID_FRENTE_ACTUAL')
            ->whereNull('e.deleted_at')->whereNotNull('e.id_tipo_equipo')
            ->groupBy('e.id_tipo_equipo')
            ->havingRaw("SUM(f.TIPO_FRENTE = 'ESPECIAL') > 0 AND SUM(COALESCE(f.TIPO_FRENTE, '') <> 'ESPECIAL') > 0 AND COUNT(*) <= 100")
            ->value('e.id_tipo_equipo');
        if ($tipo === null) {
            $this->markTestSkipped('La base no tiene un tipo con equipos en un frente ESPECIAL y en otros.');
        }

        $todos = DB::table('equipos')->where('id_tipo_equipo', $tipo)->whereNull('deleted_at')
            ->pluck('ID_EQUIPO')->map(fn ($v) => (int) $v)->sort()->values()->all();
        $this->assertSame($todos, $this->filas(['id_tipo' => $tipo]), 'Filtrar por tipo debe traer TODOS los equipos de ese tipo.');
        // El desplegable combinado manda el mismo tipo como 'tipo_eq:N'.
        $this->assertSame($todos, $this->filas(['id_tipo' => "tipo_eq:$tipo"]));
    }

    public function test_que_cuenta_como_un_tipo_concreto(): void
    {
        $c = app(EquipoController::class);
        $pedido = fn (string $v) => (fn () => $this->tipoEquipoPedido(new Request(['id_tipo' => $v])))->call($c);
        $especifico = fn (string $v) => (fn () => $this->tieneFiltroEspecifico(new Request(['id_tipo' => $v])))->call($c);

        // Un tipo de equipo: filtra y cuenta como filtro concreto (no oculta los ESPECIAL).
        $this->assertSame(25, $pedido('25'));
        $this->assertSame(25, $pedido('tipo_eq:25'));
        $this->assertTrue($especifico('25'));
        // Un valor que no es un número no coincide con ningún equipo (como antes).
        $this->assertSame(0, $pedido('abc'));
        // TODOS LOS TIPOS, vacío o un tipo de AUXILIAR: no es un tipo de equipo concreto.
        foreach (['', 'all', 'tipo_aux:3'] as $v) {
            $this->assertNull($pedido($v), "'$v' no es un tipo de equipo");
            $this->assertFalse($especifico($v), "'$v' no debe mostrar los frentes ESPECIAL");
        }
    }
}
