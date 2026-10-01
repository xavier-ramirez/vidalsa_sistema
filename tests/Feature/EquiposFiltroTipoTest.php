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

        // La tarjeta de tipos y la de frentes cuentan lo mismo que la tabla.
        $vista = $this->actingAs($this->usuarioGlobal())->get('/admin/equipos?id_tipo=' . $tipo)->assertOk();
        $this->assertSame(count($todos), (int) $vista->viewData('tiposStats')->firstWhere('id_tipo_equipo', $tipo)->total);
        $sinFrente = DB::table('equipos')->where('id_tipo_equipo', $tipo)->whereNull('deleted_at')->whereNull('ID_FRENTE_ACTUAL')->count();
        $this->assertSame(count($todos) - $sinFrente, (int) $vista->viewData('frentesStats')->sum('total'));
    }

    /** Pares del modal de anclajes con los filtros del listado. */
    private function anclajes(array $q): array
    {
        return $this->actingAs($this->usuarioGlobal())
            ->getJson('/admin/equipos/get-anchors?' . http_build_query($q))->assertOk()->json();
    }

    public function test_anclajes_con_tipo_de_auxiliar_traen_solo_esos_auxiliares(): void
    {
        $aux = DB::table('equipos_auxiliares')->whereNotNull('ID_EQUIPO_HOST')->whereNull('deleted_at')->value('ID_AUXILIAR');
        if ($aux === null) {
            $this->markTestSkipped('La base no tiene auxiliares anclados.');
        }
        DB::table('equipos_auxiliares')->where('ID_AUXILIAR', $aux)->update(['TIPO' => 'PRUEBA_TIPO_AUX']);

        $r = $this->anclajes(['id_tipo' => 'tipo_aux:PRUEBA_TIPO_AUX']);
        $this->assertSame([], $r['pairs'], 'Un tipo de auxiliar no trae pares equipo-equipo.');
        $ids = collect($r['aux'])->flatMap(fn ($h) => collect($h['auxes'])->pluck('id'))->all();
        $this->assertSame([$aux], $ids);
    }

    public function test_anclajes_respetan_los_frentes_bloqueados_del_usuario(): void
    {
        $eq = DB::table('equipos')->whereNotNull('ID_ANCLAJE')->whereNotNull('ID_FRENTE_ACTUAL')
            ->whereNull('deleted_at')->first(['ID_EQUIPO', 'ID_FRENTE_ACTUAL']);
        if ($eq === null) {
            $this->markTestSkipped('La base no tiene equipos anclados con frente.');
        }
        $u = $this->usuarioGlobal();
        $ids = fn () => collect($this->actingAs($u->fresh())
                ->getJson('/admin/equipos/get-anchors?frente_id=' . $eq->ID_FRENTE_ACTUAL)->assertOk()->json('pairs'))
            ->flatMap(fn ($p) => [$p['ID_A'], $p['ID_B']])->all();
        $this->assertContains($eq->ID_EQUIPO, $ids());

        // Con ese frente bloqueado, sus anclajes no salen (como en la tabla).
        DB::table('usuarios')->where('ID_USUARIO', $u->ID_USUARIO)->update(['ID_FRENTE_BLOQUEADO' => (string) $eq->ID_FRENTE_ACTUAL]);
        $this->assertNotContains($eq->ID_EQUIPO, $ids());
    }

    public function test_anclajes_sin_asignar_traen_los_que_no_tienen_frente(): void
    {
        $eq = DB::table('equipos')->whereNotNull('ID_ANCLAJE')->whereNull('deleted_at')->first(['ID_EQUIPO', 'ID_ANCLAJE']);
        if ($eq === null) {
            $this->markTestSkipped('La base no tiene equipos anclados.');
        }
        DB::table('equipos')->whereIn('ID_EQUIPO', [$eq->ID_EQUIPO, $eq->ID_ANCLAJE])->update(['ID_FRENTE_ACTUAL' => null]);

        $pares = collect($this->anclajes(['frente_id' => 'none'])['pairs']);
        $this->assertNotEmpty($pares);
        $this->assertTrue($pares->every(fn ($p) => DB::table('equipos')->where('ID_EQUIPO', $p['ID_A'])->value('ID_FRENTE_ACTUAL') === null));
        $this->assertTrue($pares->contains(fn ($p) => in_array($eq->ID_EQUIPO, [$p['ID_A'], $p['ID_B']])));
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
