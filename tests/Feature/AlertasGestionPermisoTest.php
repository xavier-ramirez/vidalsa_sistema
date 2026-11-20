<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * El botón «Iniciar gestión» del panel de Alertas sigue al MISMO permiso que exige el
 * servidor (equipos.edit, DashboardController::iniciarGestion). Antes el navegador miraba
 * user.edit: a uno le ofrecía el botón y el 403 llegaba tras confirmar, y a otro se lo negaba
 * teniendo el permiso.
 */
class AlertasGestionPermisoTest extends MySqlTestCase
{
    private function usuario(string $permisos, int $nivelEquipos = 1, ?string $frentes = null): Usuario
    {
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO' => 'Prueba Alertas', 'CORREO_ELECTRONICO' => 'prueba.alertas.' . strtolower(Str::random(8)) . '@cvidalsa27.com',
            'PASSWORD_HASH' => bcrypt('123456'), 'ID_ROL' => Role::value('ID_ROL'), 'NIVEL_ACCESO_EQUIPOS' => $nivelEquipos,
            'NIVEL_ACCESO_ALMACEN' => 1, 'ESTATUS' => 'ACTIVO', 'REQUIERE_CAMBIO_CLAVE' => 0, 'PERMISOS' => $permisos,
            'ID_FRENTE_ASIGNADO' => $frentes,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return Usuario::find($id);
    }

    /** Un equipo con documentación y frente, y otro frente distinto al suyo. */
    private function equipoConFrente(): array
    {
        $eq = DB::table('equipos')->join('documentacion', 'documentacion.ID_EQUIPO', '=', 'equipos.ID_EQUIPO')
            ->whereNotNull('equipos.ID_FRENTE_ACTUAL')->whereNull('equipos.deleted_at')
            ->select('equipos.ID_EQUIPO', 'equipos.ID_FRENTE_ACTUAL')->first();
        $otro = DB::table('frentes_trabajo')->where('ID_FRENTE', '!=', $eq->ID_FRENTE_ACTUAL)->value('ID_FRENTE');
        return [$eq->ID_EQUIPO, $eq->ID_FRENTE_ACTUAL, $otro];
    }

    public function test_no_se_gestiona_un_equipo_de_otro_frente(): void
    {
        [$equipo, , $otroFrente] = $this->equipoConFrente();
        $local = $this->usuario('equipos.edit', 2, (string) $otroFrente);
        DB::table('documentacion')->where('ID_EQUIPO', $equipo)->update(['poliza_gestion_frente_id' => null]);

        $this->actingAs($local)->postJson(route('dashboard.iniciarGestion'), ['equipo_id' => $equipo, 'doc_type' => 'poliza'])
            ->assertForbidden();
        $this->assertNull(DB::table('documentacion')->where('ID_EQUIPO', $equipo)->value('poliza_gestion_frente_id'));
    }

    public function test_iniciar_gestion_la_toma_una_vez_y_no_se_pisa(): void
    {
        [$equipo, $frente, $otroFrente] = $this->equipoConFrente();
        DB::table('documentacion')->where('ID_EQUIPO', $equipo)->update(['poliza_gestion_frente_id' => null]);

        $primero = $this->usuario('equipos.edit', 1, (string) $frente);
        $this->actingAs($primero)->postJson(route('dashboard.iniciarGestion'), ['equipo_id' => $equipo, 'doc_type' => 'poliza'])
            ->assertOk()->assertJson(['success' => true]);
        $this->assertEquals($frente, DB::table('documentacion')->where('ID_EQUIPO', $equipo)->value('poliza_gestion_frente_id'));

        $segundo = $this->usuario('equipos.edit', 1, (string) $otroFrente);
        $this->actingAs($segundo)->postJson(route('dashboard.iniciarGestion'), ['equipo_id' => $equipo, 'doc_type' => 'poliza'])
            ->assertStatus(409);
        $this->assertEquals($frente, DB::table('documentacion')->where('ID_EQUIPO', $equipo)->value('poliza_gestion_frente_id'));
    }

    public function test_sin_equipos_edit_el_boton_sale_deshabilitado_y_el_servidor_lo_niega(): void
    {
        $sin = $this->usuario('user.edit');
        $html = $this->actingAs($sin)->getJson(route('dashboard.alertsHtml'))->assertOk()->json('html');
        $this->assertStringContainsString('class="alert-card"', $html, 'hace falta alguna alerta para probar');
        $this->assertStringNotContainsString('iniciarGestionCustom', $html);
        $this->assertStringContainsString('No tienes permiso para gestionar documentos', $html);

        $equipo = DB::table('documentacion')->value('ID_EQUIPO');
        $this->actingAs($sin)->postJson(route('dashboard.iniciarGestion'), ['equipo_id' => $equipo, 'doc_type' => 'poliza'])
            ->assertForbidden();
    }

    public function test_con_equipos_edit_el_boton_se_ofrece(): void
    {
        $con = $this->usuario('equipos.edit');
        $html = $this->actingAs($con)->getJson(route('dashboard.alertsHtml'))->assertOk()->json('html');
        $this->assertStringContainsString('iniciarGestionCustom', $html);
        $this->assertStringNotContainsString('No tienes permiso para gestionar documentos', $html);
    }
}
