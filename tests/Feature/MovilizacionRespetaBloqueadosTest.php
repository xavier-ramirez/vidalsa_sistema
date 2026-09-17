<?php

namespace Tests\Feature;

use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Movilizar equipos respeta la LISTA NEGRA de frentes del usuario.
 *
 * El hueco que cubre: la lista negra (ID_FRENTE_BLOQUEADO) tapa los equipos de esos
 * frentes en TODA la aplicación, pero los dos endpoints de movilización de equipos no
 * la miraban — ni para el destino ni para el origen. Se podía meter material en un
 * frente que el usuario no puede ni ver, y sacar equipos de uno bloqueado mandando su
 * ID por el cuerpo de la petición. El equivalente de auxiliares (bulkMove) sí cortaba,
 * con un comentario que dice "nadie —ni GLOBAL— puede movilizar HACIA un frente
 * bloqueado": aquí solo faltaba aplicarlo.
 *
 * No escribe nada permanente: MySqlTestCase revierte la transacción al terminar.
 */
class MovilizacionRespetaBloqueadosTest extends MySqlTestCase
{
    public function test_no_deja_movilizar_hacia_un_frente_bloqueado(): void
    {
        [$usuario, $bloqueado, $equipo] = $this->escenario();

        $r = $this->actingAs($usuario)->postJson(route('movilizaciones.recepcionDirecta'), [
            'ids'               => [$equipo->ID_EQUIPO],
            'ID_FRENTE_DESTINO' => $bloqueado->ID_FRENTE,
        ]);

        $r->assertStatus(403);
        $this->assertSame(
            (int) $equipo->ID_FRENTE_ACTUAL,
            (int) Equipo::find($equipo->ID_EQUIPO)->ID_FRENTE_ACTUAL,
            'El equipo no se debe haber movido.'
        );
    }

    public function test_no_deja_sacar_un_equipo_de_un_frente_bloqueado(): void
    {
        [$usuario, $bloqueado, , $destinoOk, $equipoEnBloqueado] = $this->escenario();

        if (!$equipoEnBloqueado) {
            $this->markTestSkipped('No hay ningún equipo en el frente que se bloqueó.');
        }

        $r = $this->actingAs($usuario)->postJson(route('movilizaciones.recepcionDirecta'), [
            'ids'               => [$equipoEnBloqueado->ID_EQUIPO],
            'ID_FRENTE_DESTINO' => $destinoOk->ID_FRENTE,
        ]);

        // El equipo no es visible para el usuario, asi que la operacion no encuentra
        // nada que mover (422) y, sobre todo, el equipo se queda donde estaba.
        $this->assertContains($r->status(), [403, 422], 'No debe poder moverlo.');
        $this->assertSame(
            (int) $bloqueado->ID_FRENTE,
            (int) Equipo::find($equipoEnBloqueado->ID_EQUIPO)->ID_FRENTE_ACTUAL,
            'El equipo del frente bloqueado no se debe haber movido.'
        );
    }

    /**
     * Monta el escenario sobre datos reales: un usuario con permiso de movilizar, un
     * frente que se le bloquea y un equipo suyo. Todo dentro de la transacción del test.
     *
     * @return array{0:Usuario,1:FrenteTrabajo,2:Equipo,3:FrenteTrabajo,4:?Equipo}
     */
    private function escenario(): array
    {
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->take(2)->get();
        if ($frentes->count() < 2) {
            $this->markTestSkipped('Hacen falta dos frentes activos.');
        }
        [$bloqueado, $destinoOk] = [$frentes[0], $frentes[1]];

        $equipo = Equipo::whereNotNull('ID_FRENTE_ACTUAL')
            ->where('ID_FRENTE_ACTUAL', '!=', $bloqueado->ID_FRENTE)
            ->first();
        if (!$equipo) {
            $this->markTestSkipped('No hay equipos asignados a un frente distinto del bloqueado.');
        }

        $equipoEnBloqueado = Equipo::where('ID_FRENTE_ACTUAL', $bloqueado->ID_FRENTE)->first();

        // Usuario de prueba creado DESDE CERO (nunca replicate) y solo dentro de la
        // transaccion: al revertir desaparece.
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO'      => 'PRUEBA MOVILIZACION BLOQUEADOS',
            'CORREO_ELECTRONICO'   => 'prueba.movilizacion.bloqueados@local.test',
            'PASSWORD_HASH'        => bcrypt('irrelevante'),
            'ID_ROL'               => DB::table('roles')->value('ID_ROL'),
            'NIVEL_ACCESO_EQUIPOS' => 1,
            'NIVEL_ACCESO_ALMACEN' => 1,
            'ESTATUS'              => 'ACTIVO',
            'REQUIERE_CAMBIO_CLAVE'=> 0,
            'PERMISOS'             => 'equipos.assign',
            'ID_FRENTE_BLOQUEADO'  => (string) $bloqueado->ID_FRENTE,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        return [Usuario::find($id), $bloqueado, $equipo, $destinoOk, $equipoEnBloqueado];
    }
}
