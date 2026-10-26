<?php

namespace Tests\Feature;

use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use App\Models\Usuario;
use App\Support\RegistroCambios;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Dos equipos elegidos a la vez en /admin/equipos se movilizan LOS DOS, y la copia local
 * (GET /sync/equipos) trae a los dos en su frente nuevo.
 *
 * No escribe nada permanente: MySqlTestCase revierte la transacción al terminar.
 */
class MovilizarVariosEquiposTest extends MySqlTestCase
{
    public function test_dos_equipos_se_movilizan_y_la_copia_trae_los_dos(): void
    {
        if (! RegistroCambios::activo()) {
            $this->markTestSkipped('Faltan los triggers del registro de cambios (php artisan migrate).');
        }

        // Dos equipos del MISMO frente (lo normal al elegir varios en la tabla) y otro frente de destino.
        $frenteOrigen = Equipo::whereNotNull('ID_FRENTE_ACTUAL')->where('ESTADO_OPERATIVO', '!=', 'DESINCORPORADO')
            ->whereNull('ID_ANCLAJE')
            ->groupBy('ID_FRENTE_ACTUAL')->havingRaw('COUNT(*) >= 2')->value('ID_FRENTE_ACTUAL');
        $equipos = $frenteOrigen
            ? Equipo::where('ID_FRENTE_ACTUAL', $frenteOrigen)->whereNull('ID_ANCLAJE')->limit(2)->get()
            : collect();
        $destino = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->where('ID_FRENTE', '!=', $frenteOrigen)->first();
        if ($equipos->count() < 2 || ! $destino) {
            $this->markTestSkipped('Hacen falta dos equipos en un frente y otro frente activo.');
        }

        $usuario = $this->usuario();
        $antes = $this->sync($usuario)->json();

        $this->actingAs($usuario)->postJson(route('equipos.bulkMobilize'), [
            'ids'         => $equipos->pluck('ID_EQUIPO')->all(),
            'destination' => $destino->NOMBRE_FRENTE,
            'generar_pdf' => false,
        ])->assertOk()->assertJson(['success' => true, 'count' => 2, 'omitidos' => 0]);

        foreach ($equipos as $e) {
            $this->assertSame((int) $destino->ID_FRENTE, (int) Equipo::find($e->ID_EQUIPO)->ID_FRENTE_ACTUAL);
        }

        $filas = collect($this->sync($usuario, $antes)->assertJson(['completo' => false])->json('datos.equipos'))->keyBy('equipoId');
        foreach ($equipos as $e) {
            $this->assertTrue($filas->has($e->ID_EQUIPO), 'La copia local tiene que traer al equipo ' . $e->ID_EQUIPO);
            $this->assertSame($destino->NOMBRE_FRENTE, $filas[$e->ID_EQUIPO]['ubicacion']);
        }
    }

    private function sync(Usuario $usuario, ?array $previa = null)
    {
        $q = $previa ? '?' . http_build_query(['desde' => $previa['hasta'], 'firma' => $previa['firma'], 'esquema' => $previa['esquema']]) : '';

        return $this->actingAs($usuario)->getJson('/sync/equipos' . $q);
    }

    /** Usuario GLOBAL creado DESDE CERO (nunca replicate) y solo dentro de la transacción. */
    private function usuario(): Usuario
    {
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO'       => 'PRUEBA MOVILIZAR VARIOS',
            'CORREO_ELECTRONICO'    => 'prueba.movilizar.varios@local.test',
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
