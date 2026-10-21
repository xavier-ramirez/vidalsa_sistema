<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Panel «Equipos Asignados por Frente» del Dashboard de Flota: sale del MISMO filtro que el
 * Σ Equipos (sin desincorporados, sin frentes fuera de flota, con los permisos del usuario)
 * y SIEMPRE con todos los frentes, aunque en el dashboard se elija uno;
 * así que la suma del panel cuadra con la tarjeta y un usuario local solo ve sus frentes.
 */
class FleetEquiposPorFrenteTest extends MySqlTestCase
{
    private function usuario(?string $frentesAsignados = null): \App\Models\Usuario
    {
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO' => 'PRUEBA FLOTA POR FRENTE', 'CORREO_ELECTRONICO' => 'prueba.flotafrente.' . Str::random(6) . '@local.test',
            'PASSWORD_HASH' => bcrypt('x'), 'ID_ROL' => DB::table('roles')->value('ID_ROL'),
            'NIVEL_ACCESO_EQUIPOS' => $frentesAsignados ? 2 : 1, 'ID_FRENTE_ASIGNADO' => $frentesAsignados,
            'NIVEL_ACCESO_ALMACEN' => 1, 'ESTATUS' => 'ACTIVO', 'REQUIERE_CAMBIO_CLAVE' => 0, 'PERMISOS' => 'super.admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return \App\Models\Usuario::find($id);
    }

    public function test_la_suma_por_frente_cuadra_con_el_total(): void
    {
        $json = $this->actingAs($this->usuario())->getJson('/admin/equipos/fleet-stats?frente_id=all')->assertOk()->json();

        $this->assertNotEmpty($json['equiposPorFrente']);
        $this->assertSame($json['stats']['total'], array_sum(array_column($json['equiposPorFrente'], 'total')));
    }

    public function test_con_un_frente_elegido_el_panel_sigue_trayendo_todos(): void
    {
        $usuario = $this->usuario();
        $frente = (int) DB::table('equipos')->whereNull('deleted_at')->where('ESTADO_OPERATIVO', '!=', 'DESINCORPORADO')
            ->whereNotNull('ID_FRENTE_ACTUAL')->value('ID_FRENTE_ACTUAL');

        $todos = $this->actingAs($usuario)->getJson('/admin/equipos/fleet-stats?frente_id=all')->assertOk()->json('equiposPorFrente');
        $uno   = $this->actingAs($usuario)->getJson('/admin/equipos/fleet-stats?frente_id=' . $frente)->assertOk()->json('equiposPorFrente');

        $this->assertGreaterThan(1, count($todos));
        $this->assertSame($todos, $uno);
    }

    public function test_un_usuario_local_solo_ve_sus_frentes(): void
    {
        $frente = (int) DB::table('equipos')->whereNull('deleted_at')->where('ESTADO_OPERATIVO', '!=', 'DESINCORPORADO')
            ->whereNotNull('ID_FRENTE_ACTUAL')->groupBy('ID_FRENTE_ACTUAL')->orderByRaw('COUNT(*) DESC')->value('ID_FRENTE_ACTUAL');
        $nombre = DB::table('frentes_trabajo')->where('ID_FRENTE', $frente)->value('NOMBRE_FRENTE');

        $json = $this->actingAs($this->usuario((string) $frente))->getJson('/admin/equipos/fleet-stats?frente_id=all')->assertOk()->json();

        $this->assertSame([$nombre], array_column($json['equiposPorFrente'], 'frente'));
    }
}
