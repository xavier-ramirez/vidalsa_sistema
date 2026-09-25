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
 * frentes en TODA la aplicación, pero la movilización de equipos no la miraba. Se podía meter material en un
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

        $r = $this->actingAs($usuario)->postJson(route('equipos.bulkMobilize'), [
            'ids'         => [$equipo->ID_EQUIPO],
            'destination' => $bloqueado->NOMBRE_FRENTE,
            'generar_pdf' => false,
        ]);

        $r->assertStatus(403);
        $this->assertSame(
            (int) $equipo->ID_FRENTE_ACTUAL,
            (int) Equipo::find($equipo->ID_EQUIPO)->ID_FRENTE_ACTUAL,
            'El equipo no se debe haber movido.'
        );
    }

    /**
     * La recepcion directa se retiro. La APK (api/mobile/movilizaciones) todavia puede
     * mandarla: se rechaza, y NO cae en el despacho (que registraria un movimiento).
     */
    public function test_la_apk_ya_no_registra_recepciones_directas(): void
    {
        [$usuario, , $equipo, $destinoOk] = $this->escenario();
        DB::table('usuarios')->where('ID_USUARIO', $usuario->ID_USUARIO)->update(['PERMISOS' => 'equipos.assign,equipos.create']);
        $antes = DB::table('movilizacion_historial')->count();

        \Laravel\Sanctum\Sanctum::actingAs($usuario->fresh(), ['*']);
        $this->postJson('/api/mobile/movilizaciones', [
            'tipo' => 'recepcion_directa', 'ids' => [$equipo->ID_EQUIPO],
            'ID_EQUIPO' => $equipo->ID_EQUIPO, 'ID_FRENTE_DESTINO' => $destinoOk->ID_FRENTE,
        ])->assertStatus(422)->assertJson(['success' => false]);

        $this->assertSame($antes, DB::table('movilizacion_historial')->count(), 'No se registra ningun movimiento.');
        $this->assertSame((int) $equipo->ID_FRENTE_ACTUAL, (int) Equipo::find($equipo->ID_EQUIPO)->ID_FRENTE_ACTUAL);
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
