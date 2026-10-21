<?php

namespace Tests\Feature;

use App\Models\Equipo;
use App\Models\EquipoAuxiliar;
use App\Models\FrenteTrabajo;
use App\Models\Movilizacion;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Equipos y auxiliares elegidos a la vez en /admin/equipos se movilizan JUNTOS: una sola
 * barra, un solo modal y un solo registro con un solo N° de acta (antes los auxiliares iban
 * por su propio endpoint y el mismo traslado quedaba en dos actas).
 *
 * No escribe nada permanente: MySqlTestCase revierte la transacción al terminar.
 */
class MovilizacionEquiposYAuxiliaresTest extends MySqlTestCase
{
    public function test_equipos_y_auxiliares_quedan_en_la_misma_acta(): void
    {
        [$usuario, $equipo, $aux, $destino] = $this->escenario();

        $r = $this->actingAs($usuario)->postJson(route('equipos.bulkMobilize'), [
            'ids'         => [$equipo->ID_EQUIPO],
            'aux_ids'     => [$aux->ID_AUXILIAR],
            'destination' => $destino->NOMBRE_FRENTE,
            'generar_pdf' => true,
            'destination_ubicacion' => 'PRUEBA',
        ])->assertOk()->assertJson(['success' => true, 'count' => 2]);

        $movs = Movilizacion::whereIn('ID_MOVILIZACION', $r->json('movilizacion_ids'))->get();
        $this->assertCount(2, $movs);
        $this->assertCount(1, $movs->pluck('CODIGO_CONTROL')->unique(), 'Un solo N° de acta para los dos.');
        $this->assertNotNull($movs->first()->CODIGO_CONTROL);
        $this->assertSame((int) $equipo->ID_FRENTE_ACTUAL, (int) $movs->firstWhere('ID_EQUIPO', $equipo->ID_EQUIPO)->ID_FRENTE_ORIGEN);
        $this->assertSame((int) $aux->ID_FRENTE_ACTUAL, (int) $movs->firstWhere('ID_AUXILIAR', $aux->ID_AUXILIAR)->ID_FRENTE_ORIGEN);

        $this->assertSame((int) $destino->ID_FRENTE, (int) Equipo::find($equipo->ID_EQUIPO)->ID_FRENTE_ACTUAL);
        $auxDespues = EquipoAuxiliar::find($aux->ID_AUXILIAR);
        $this->assertSame((int) $destino->ID_FRENTE, (int) $auxDespues->ID_FRENTE_ACTUAL);
        $this->assertSame(0, (int) $auxDespues->CONFIRMADO_EN_SITIO, 'Queda pendiente de confirmar al llegar.');
    }

    public function test_solo_auxiliares_tambien_se_movilizan(): void
    {
        [$usuario, , $aux, $destino] = $this->escenario();

        $this->actingAs($usuario)->postJson(route('equipos.bulkMobilize'), [
            'aux_ids'     => [$aux->ID_AUXILIAR],
            'destination' => $destino->NOMBRE_FRENTE,
            'generar_pdf' => false,
        ])->assertOk()->assertJson(['success' => true, 'count' => 1]);

        $this->assertSame((int) $destino->ID_FRENTE, (int) EquipoAuxiliar::find($aux->ID_AUXILIAR)->ID_FRENTE_ACTUAL);
    }

    public function test_sin_nada_elegido_no_hace_nada(): void
    {
        [$usuario, , , $destino] = $this->escenario();

        $this->actingAs($usuario)->postJson(route('equipos.bulkMobilize'), [
            'destination' => $destino->NOMBRE_FRENTE,
            'generar_pdf' => false,
        ])->assertStatus(422);
    }

    /**
     * La vista previa del acta lista a los dos, sin registrar nada.
     */
    public function test_la_vista_previa_del_acta_acepta_equipos_y_auxiliares(): void
    {
        [$usuario, $equipo, $aux, $destino] = $this->escenario();
        $antes = Movilizacion::count();

        $this->actingAs($usuario)->postJson(route('movilizaciones.previewActa'), [
            'ids'         => [$equipo->ID_EQUIPO],
            'aux_ids'     => [$aux->ID_AUXILIAR],
            'destination' => $destino->NOMBRE_FRENTE,
            'destination_ubicacion' => 'PRUEBA',
        ])->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($usuario)->postJson(route('movilizaciones.previewActaMeta'), [
            'aux_ids' => [$aux->ID_AUXILIAR],
        ])->assertOk()->assertJsonStructure(['origin', 'origin_zona', 'firmas']);

        $this->assertSame($antes, Movilizacion::count(), 'La vista previa no registra nada.');
    }

    /**
     * Un equipo y un auxiliar con frente, y un frente activo distinto de los dos como
     * destino. Usuario creado DESDE CERO dentro de la transacción del test.
     *
     * @return array{0:Usuario,1:Equipo,2:EquipoAuxiliar,3:FrenteTrabajo}
     */
    private function escenario(): array
    {
        $equipo = Equipo::whereNotNull('ID_FRENTE_ACTUAL')->where('ESTADO_OPERATIVO', '!=', 'DESINCORPORADO')->first();
        $aux    = EquipoAuxiliar::whereNotNull('ID_FRENTE_ACTUAL')->first();
        if (!$equipo || !$aux) {
            $this->markTestSkipped('Hacen falta un equipo y un auxiliar con frente.');
        }
        $destino = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->whereNotIn('ID_FRENTE', [$equipo->ID_FRENTE_ACTUAL, $aux->ID_FRENTE_ACTUAL])
            ->first();
        if (!$destino) {
            $this->markTestSkipped('Hace falta un frente activo distinto.');
        }

        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO'      => 'PRUEBA MOVILIZACION CONJUNTA',
            'CORREO_ELECTRONICO'   => 'prueba.movilizacion.conjunta@local.test',
            'PASSWORD_HASH'        => bcrypt('irrelevante'),
            'ID_ROL'               => DB::table('roles')->value('ID_ROL'),
            'NIVEL_ACCESO_EQUIPOS' => 1,
            'NIVEL_ACCESO_ALMACEN' => 1,
            'ESTATUS'              => 'ACTIVO',
            'REQUIERE_CAMBIO_CLAVE'=> 0,
            'PERMISOS'             => 'equipos.assign',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        return [Usuario::find($id), $equipo, $aux, $destino];
    }
}
