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
    private function usuario(string $permisos): Usuario
    {
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO' => 'Prueba Alertas', 'CORREO_ELECTRONICO' => 'prueba.alertas.' . strtolower(Str::random(8)) . '@cvidalsa27.com',
            'PASSWORD_HASH' => bcrypt('123456'), 'ID_ROL' => Role::value('ID_ROL'), 'NIVEL_ACCESO_EQUIPOS' => 1,
            'NIVEL_ACCESO_ALMACEN' => 1, 'ESTATUS' => 'ACTIVO', 'REQUIERE_CAMBIO_CLAVE' => 0, 'PERMISOS' => $permisos,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return Usuario::find($id);
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
