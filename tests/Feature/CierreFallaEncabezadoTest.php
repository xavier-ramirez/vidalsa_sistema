<?php

namespace Tests\Feature;

use App\Models\Documentacion;
use App\Models\Equipo;
use App\Models\Falla;
use App\Models\TipoEquipo;
use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * El modal de cierre dice QUÉ equipo se está cerrando en su encabezado
 * ("TIPO · MARCA MODELO · IDENTIFICADOR"). Los datos salen de Falla::datosActivo, la
 * misma fuente para la respuesta 409 de equipos/auxiliares y para las filas.
 */
class CierreFallaEncabezadoTest extends MySqlTestCase
{
    public function test_cambiar_estado_con_reporte_abierto_devuelve_los_datos_del_encabezado(): void
    {
        $u = Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->whereNotNull('PERMISOS')->get()
            ->first(fn ($usr) => in_array('super.admin', array_map('strtolower', $usr->PERMISOS), true));
        $this->assertNotNull($u, 'hace falta un super.admin activo');

        $tipo = TipoEquipo::firstOrCreate(['nombre' => 'BATEA']);
        $equipo = Equipo::create([
            'MARCA' => 'JAC', 'MODELO' => 'HFC9380TJP', 'ANIO' => 2026,
            'SERIAL_CHASIS' => 'TEST-CIERRE-' . uniqid(), 'id_tipo_equipo' => $tipo->id,
            'ESTADO_OPERATIVO' => 'INOPERATIVO',
        ]);
        Documentacion::create(['ID_EQUIPO' => $equipo->ID_EQUIPO, 'PLACA' => 'ZZ99PRB']);
        $falla = Falla::create([
            'CODIGO_REPORTE' => 'TEST-' . uniqid(), 'ID_USUARIO_REPORTA' => $u->ID_USUARIO,
            'ACTIVO_TIPO' => 'equipo', 'ACTIVO_ID' => $equipo->ID_EQUIPO,
            'TIPO_REPORTE' => 'corto', 'ESTADO_REPORTE' => 'abierto', 'FECHA_EMISION' => now(),
        ]);

        $this->actingAs($u)
            ->patchJson(route('equipos.changeStatus', $equipo->ID_EQUIPO), ['status' => 'OPERATIVO'])
            ->assertStatus(409)
            ->assertJson(['falla_abierta' => [
                'id'      => $falla->ID_FALLA,
                'equipo'  => 'ZZ99PRB',                 // la placa manda sobre el serial
                'detalle' => 'BATEA · JAC HFC9380TJP',
            ]]);
    }
}
