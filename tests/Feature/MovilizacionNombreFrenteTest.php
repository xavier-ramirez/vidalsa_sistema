<?php

namespace Tests\Feature;

use App\Models\FrenteTrabajo;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * El frente destino de una movilización escrito con otras tildes, mayúsculas o espacios es el
 * MISMO frente: «estacion de valvulas» va al registrado «ESTACIÓN  DE VALVULAS» (dos espacios)
 * y no crea un duplicado. Un frente nuevo escrito en minúsculas con tilde queda en mayúsculas
 * de verdad («TUBERÍA…», no «TUBERíA…» como dejaba strtoupper).
 *
 * No escribe nada permanente: MySqlTestCase revierte la transacción al terminar.
 */
class MovilizacionNombreFrenteTest extends MySqlTestCase
{
    public function test_otras_tildes_y_espacios_usan_el_frente_registrado(): void
    {
        $registrado = FrenteTrabajo::create(['NOMBRE_FRENTE' => 'PRUEBA ESTACIÓN  DE VÁLVULAS CLAUDE', 'ESTATUS_FRENTE' => 'ACTIVO']);
        $equipo = $this->equipo();
        $antes = FrenteTrabajo::count();

        $this->actingAs($this->usuario())->postJson(route('equipos.bulkMobilize'), [
            'ids'         => [$equipo],
            'destination' => '  prueba estacion de valvulas   claude ',
            'generar_pdf' => false,
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame($antes, FrenteTrabajo::count(), 'No tiene que crear un frente duplicado');
        $this->assertSame((int) $registrado->ID_FRENTE, (int) DB::table('equipos')->where('ID_EQUIPO', $equipo)->value('ID_FRENTE_ACTUAL'));
    }

    public function test_frente_nuevo_en_minusculas_con_tilde_queda_en_mayusculas(): void
    {
        $equipo = $this->equipo();

        $this->actingAs($this->usuario())->postJson(route('equipos.bulkMobilize'), [
            'ids'         => [$equipo],
            'destination' => 'tubería   nueva claude',
            'generar_pdf' => false,
        ])->assertOk()->assertJson(['success' => true]);

        $frente = FrenteTrabajo::where('NOMBRE_FRENTE', 'TUBERÍA NUEVA CLAUDE')->first();
        $this->assertNotNull($frente);
        $this->assertSame('TUBERÍA NUEVA CLAUDE', $frente->NOMBRE_FRENTE);
        $this->assertSame((int) $frente->ID_FRENTE, (int) DB::table('equipos')->where('ID_EQUIPO', $equipo)->value('ID_FRENTE_ACTUAL'));
    }

    /** Equipo creado DESDE CERO dentro de la transacción, en un frente cualquiera. */
    private function equipo(): int
    {
        return DB::table('equipos')->insertGetId([
            'id_tipo_equipo'      => DB::table('tipo_equipos')->value('id'),
            'MARCA'               => 'PRUEBA', 'MODELO' => 'NOMBRE FRENTE', 'ANIO' => 2026,
            'SERIAL_CHASIS'       => 'PRUEBA-NOMBRE-FRENTE-' . uniqid(),
            'ID_FRENTE_ACTUAL'    => FrenteTrabajo::value('ID_FRENTE'),
            'CONFIRMADO_EN_SITIO' => 1, 'ESTADO_OPERATIVO' => 'OPERATIVO',
            'created_at'          => now(), 'updated_at' => now(),
        ]);
    }

    /** Usuario GLOBAL creado DESDE CERO (nunca replicate) y solo dentro de la transacción. */
    private function usuario(): Usuario
    {
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO'       => 'PRUEBA NOMBRE FRENTE',
            'CORREO_ELECTRONICO'    => 'prueba.nombre.frente@local.test',
            'PASSWORD_HASH'         => bcrypt('irrelevante'),
            'ID_ROL'                => DB::table('roles')->value('ID_ROL'),
            'NIVEL_ACCESO_EQUIPOS'  => 1,
            'NIVEL_ACCESO_ALMACEN'  => 1,
            'ESTATUS'               => 'ACTIVO',
            'REQUIERE_CAMBIO_CLAVE' => 0,
            'PERMISOS'              => 'equipos.assign',
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        return Usuario::find($id);
    }
}
