<?php

namespace Tests\Feature;

use App\Models\FrenteTrabajo;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Historial de movilizaciones (/admin/movilizaciones), las dos operaciones de super.admin:
 *  - Deshacer: solo la ÚLTIMA de un equipo; lo devuelve a su origen y borra el registro.
 *  - Eliminar seleccionados: borra registros (no mueve el equipo). Un id que ya no existe
 *    (otro usuario lo borró) no frena el resto y el mensaje cuenta los borrados de verdad.
 *
 * No escribe nada permanente: MySqlTestCase revierte la transacción al terminar.
 */
class MovilizacionDeshacerEliminarTest extends MySqlTestCase
{
    public function test_deshacer_solo_la_ultima_y_vuelve_al_origen(): void
    {
        [$a, $b, $c] = $this->frentes();
        $equipo = $this->equipo($a);
        $admin = $this->usuario();

        $primera = $this->movilizar($admin, $equipo, $b);
        $segunda = $this->movilizar($admin, $equipo, $c);

        $this->actingAs($admin)->postJson(route('movilizaciones.deshacer', $primera))
            ->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame($c, $this->frenteDe($equipo), 'Negado: el equipo no se mueve');

        $this->actingAs($admin)->postJson(route('movilizaciones.deshacer', $segunda))
            ->assertOk()->assertJson(['success' => true]);
        $this->assertSame($b, $this->frenteDe($equipo));
        $this->assertFalse(DB::table('movilizacion_historial')->where('ID_MOVILIZACION', $segunda)->exists());

        // Ahora la primera sí es la última.
        $this->actingAs($admin)->postJson(route('movilizaciones.deshacer', $primera))->assertOk();
        $this->assertSame($a, $this->frenteDe($equipo));
    }

    public function test_eliminar_tolera_ids_que_ya_no_existen_y_no_mueve_el_equipo(): void
    {
        [$a, $b] = $this->frentes();
        $equipo = $this->equipo($a);
        $admin = $this->usuario();
        $mov = $this->movilizar($admin, $equipo, $b);
        $borrado = (int) DB::table('movilizacion_historial')->max('ID_MOVILIZACION') + 1000;

        $this->actingAs($admin)->postJson(route('movilizaciones.bulkDestroy'), ['ids' => [$mov, $borrado]])
            ->assertOk()->assertJson(['success' => true, 'message' => '1 registro eliminado.']);

        $this->assertFalse(DB::table('movilizacion_historial')->where('ID_MOVILIZACION', $mov)->exists());
        $this->assertSame($b, $this->frenteDe($equipo), 'Eliminar el registro no devuelve el equipo');
    }

    private function movilizar(Usuario $u, int $equipo, int $frente): int
    {
        $nombre = FrenteTrabajo::where('ID_FRENTE', $frente)->value('NOMBRE_FRENTE');
        $this->actingAs($u)->postJson(route('equipos.bulkMobilize'), [
            'ids' => [$equipo], 'destination' => $nombre, 'generar_pdf' => false,
        ])->assertOk()->assertJson(['success' => true]);

        return (int) DB::table('movilizacion_historial')->where('ID_EQUIPO', $equipo)->max('ID_MOVILIZACION');
    }

    private function frenteDe(int $equipo): int
    {
        return (int) DB::table('equipos')->where('ID_EQUIPO', $equipo)->value('ID_FRENTE_ACTUAL');
    }

    /** Tres frentes nuevos, solo dentro de la transacción. */
    private function frentes(): array
    {
        return array_map(fn ($n) => (int) FrenteTrabajo::create(['NOMBRE_FRENTE' => "PRUEBA DESHACER $n CLAUDE", 'ESTATUS_FRENTE' => 'ACTIVO'])->ID_FRENTE, ['A', 'B', 'C']);
    }

    private function equipo(int $frente): int
    {
        return DB::table('equipos')->insertGetId([
            'id_tipo_equipo'      => DB::table('tipo_equipos')->value('id'),
            'MARCA'               => 'PRUEBA', 'MODELO' => 'DESHACER', 'ANIO' => 2026,
            'SERIAL_CHASIS'       => 'PRUEBA-DESHACER-' . uniqid(),
            'ID_FRENTE_ACTUAL'    => $frente,
            'CONFIRMADO_EN_SITIO' => 1, 'ESTADO_OPERATIVO' => 'OPERATIVO',
            'created_at'          => now(), 'updated_at' => now(),
        ]);
    }

    /** Usuario super.admin creado DESDE CERO (nunca replicate) y solo dentro de la transacción. */
    private function usuario(): Usuario
    {
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO'       => 'PRUEBA DESHACER',
            'CORREO_ELECTRONICO'    => 'prueba.deshacer@local.test',
            'PASSWORD_HASH'         => bcrypt('irrelevante'),
            'ID_ROL'                => DB::table('roles')->value('ID_ROL'),
            'NIVEL_ACCESO_EQUIPOS'  => 1,
            'NIVEL_ACCESO_ALMACEN'  => 1,
            'ESTATUS'               => 'ACTIVO',
            'REQUIERE_CAMBIO_CLAVE' => 0,
            'PERMISOS'              => 'super.admin',
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        return Usuario::find($id);
    }
}
