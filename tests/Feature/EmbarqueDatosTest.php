<?php

namespace Tests\Feature;

use App\Models\Embarque;
use App\Models\EquipoAuditLog;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Panel de datos del visor para el documento de EMBARQUE (BL): se ven los datos que tiene el
 * BL y se guardan con el permiso de siempre del panel (user.edit). Los del BL valen para todo
 * el embarque; el VIN, solo para el equipo.
 */
class EmbarqueDatosTest extends MySqlTestCase
{
    private Usuario $editor;
    private Embarque $embarque;
    private int $equipo;
    private int $otroEquipo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->editor = Usuario::where('ESTATUS', 'ACTIVO')->get()->first(fn ($u) => $u->can('user.edit'));
        $this->assertNotNull($this->editor, 'Hace falta un usuario con permiso user.edit.');

        [$this->equipo, $this->otroEquipo] = DB::table('equipos')->whereNull('deleted_at')
            ->whereNotIn('ID_EQUIPO', DB::table('embarque_equipo')->select('ID_EQUIPO'))
            ->limit(2)->pluck('ID_EQUIPO')->map(fn ($v) => (int) $v)->all();

        $this->embarque = Embarque::create([
            'NRO_BL' => 'RAQDLA16_PRUEBA', 'BUQUE' => 'RUI AN YANG V.2524', 'PUERTO_CARGA' => 'QINGDAO,CHINA',
            'PUERTO_DESCARGA' => 'LA GUAIRA,VENEZUELA', 'FECHA_EMBARQUE' => '2025-08-20',
            'LINK' => '/storage/google/BL_PRUEBA?v=1', 'UNIDADES' => 10,
        ]);
        foreach ([$this->equipo => 'LEZDD2CC8SF132435', $this->otroEquipo => 'LEZDD2CCXSF132436'] as $id => $vin) {
            DB::table('embarque_equipo')->insert([
                'ID_EMBARQUE' => $this->embarque->ID_EMBARQUE, 'ID_EQUIPO' => $id, 'VIN' => $vin, 'created_at' => now(),
            ]);
        }
    }

    private function guardar(array $datos, ?Usuario $quien = null)
    {
        return $this->actingAs($quien ?? $this->editor)
            ->postJson("/admin/equipos/{$this->equipo}/update-metadata", ['doc_type' => 'embarque'] + $datos);
    }

    private function completo(array $cambios = []): array
    {
        return $cambios + [
            'nro_bl' => 'RAQDLA16_PRUEBA', 'buque' => 'RUI AN YANG V.2524', 'puerto_carga' => 'QINGDAO,CHINA',
            'puerto_descarga' => 'LA GUAIRA,VENEZUELA', 'fecha_embarque' => '2025-08-20', 'unidades' => '10',
            'vin' => 'LEZDD2CC8SF132435',
        ];
    }

    public function test_el_panel_trae_los_datos_del_bl(): void
    {
        $this->actingAs($this->editor)->getJson("/admin/equipos/{$this->equipo}/metadata?type=embarque")
            ->assertOk()->assertJsonPath('data', [
                'nro_bl' => 'RAQDLA16_PRUEBA', 'buque' => 'RUI AN YANG V.2524', 'puerto_carga' => 'QINGDAO,CHINA',
                'puerto_descarga' => 'LA GUAIRA,VENEZUELA', 'fecha_embarque' => '2025-08-20', 'unidades' => 10,
                'vin' => 'LEZDD2CC8SF132435', 'equipos' => 2,
            ]);
    }

    public function test_guarda_los_datos_para_todo_el_embarque_y_el_vin_solo_del_equipo(): void
    {
        $this->guardar($this->completo(['buque' => ' rui an yang v.2525 ', 'unidades' => '12', 'vin' => 'lezdd2cc8sf132499']))
            ->assertOk()->assertJsonPath('success', true);

        $e = $this->embarque->fresh();
        $this->assertSame('RUI AN YANG V.2525', $e->BUQUE);
        $this->assertSame(12, (int) $e->UNIDADES);
        $this->assertSame('LEZDD2CC8SF132499', DB::table('embarque_equipo')->where('ID_EQUIPO', $this->equipo)->value('VIN'));
        $this->assertSame('LEZDD2CCXSF132436', DB::table('embarque_equipo')->where('ID_EQUIPO', $this->otroEquipo)->value('VIN'),
            'El VIN del otro equipo del embarque no se toca.');

        $log = EquipoAuditLog::where('ID_EQUIPO', $this->equipo)->where('ACCION', 'metadata_embarque')->latest('ID_LOG')->first();
        $this->assertNotNull($log, 'La edicion queda en el historial.');
        $this->assertSame(['BUQUE', 'UNIDADES', 'VIN'], array_keys($log->CAMBIOS));
    }

    public function test_sin_cambios_no_escribe_nada(): void
    {
        $this->guardar($this->completo())->assertOk()->assertJsonPath('message', 'Sin cambios');
        $this->assertFalse(EquipoAuditLog::where('ID_EQUIPO', $this->equipo)->where('ACCION', 'metadata_embarque')->exists());
    }

    public function test_no_admite_un_numero_de_bl_de_otro_embarque_ni_una_fecha_rota(): void
    {
        Embarque::create(['NRO_BL' => 'OTRO_BL_PRUEBA', 'LINK' => '/storage/google/OTRO?v=1']);
        $this->guardar($this->completo(['nro_bl' => 'OTRO_BL_PRUEBA']))->assertStatus(422)
            ->assertJsonPath('message', 'Ya hay otro embarque con ese número de BL.');
        $this->guardar($this->completo(['fecha_embarque' => '20/08/2025']))->assertStatus(422);
        $this->assertSame('RAQDLA16_PRUEBA', $this->embarque->fresh()->NRO_BL);
    }

    public function test_sin_permiso_de_edicion_no_se_guarda(): void
    {
        $sinPermiso = Usuario::get()->first(fn ($u) => !$u->can('user.edit'));
        if (!$sinPermiso) {
            $this->markTestSkipped('No hay ningún usuario sin permiso user.edit para probarlo.');
        }
        $this->guardar($this->completo(['buque' => 'NO DEBE QUEDAR']), $sinPermiso)->assertStatus(403);
        $this->assertSame('RUI AN YANG V.2524', $this->embarque->fresh()->BUQUE);
    }

    public function test_un_equipo_sin_embarque_responde_que_no_lo_tiene(): void
    {
        DB::table('embarque_equipo')->where('ID_EQUIPO', $this->equipo)->delete();
        $this->actingAs($this->editor)->getJson("/admin/equipos/{$this->equipo}/metadata?type=embarque")
            ->assertOk()->assertJsonPath('data', []);
        $this->guardar($this->completo())->assertStatus(404);
    }
}
