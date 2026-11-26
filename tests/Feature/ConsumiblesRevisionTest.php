<?php

namespace Tests\Feature;

use App\Models\Consumible;
use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Lista y Gráficos de consumibles: filtros mal formados no dan 500, los inoperativos del frente
 * están al día aunque los gráficos sigan en caché, y la edición en la fila responde con el motivo.
 * La transacción de cada caso lo revierte todo.
 */
class ConsumiblesRevisionTest extends MySqlTestCase
{
    public function test_graficos_rechaza_filtros_mal_formados(): void
    {
        $u = $this->superAdminGlobal();
        $this->actingAs($u)->getJson(route('consumibles.graficosData', ['desde' => 'abc']))->assertStatus(422);
        $this->actingAs($u)->getJson(route('consumibles.graficosData') . '?desde[]=x')->assertStatus(422);
        $this->actingAs($u)->getJson(route('consumibles.graficosData', ['tipo' => 'AGUA']))->assertStatus(422);
        $this->actingAs($u)->getJson(route('consumibles.graficosData', ['id_frente' => 'x']))->assertStatus(422);
    }

    public function test_inoperativos_al_dia_con_los_graficos_en_cache(): void
    {
        $u = $this->superAdminGlobal();
        $frente = FrenteTrabajo::create(['NOMBRE_FRENTE' => 'PRUEBA INOPERATIVOS ' . strtoupper(Str::random(5))]);
        $equipo = Equipo::create(['MARCA' => 'PRUEBA', 'MODELO' => 'INO', 'ANIO' => 2020,
            'SERIAL_CHASIS' => 'TSTINO' . random_int(100000, 999999), 'id_tipo_equipo' => DB::table('tipo_equipos')->value('id')]);
        DB::table('equipos')->where('ID_EQUIPO', $equipo->ID_EQUIPO)
            ->update(['ID_FRENTE_ACTUAL' => $frente->ID_FRENTE, 'ESTADO_OPERATIVO' => 'OPERATIVO']);
        $pedir = fn () => $this->actingAs($u)
            ->getJson(route('consumibles.graficosData', ['id_frente' => $frente->ID_FRENTE, 'desde' => '2031-01-01', 'hasta' => '2031-01-31']))
            ->assertOk()->json('inoperativos');

        $this->assertSame([], $pedir());
        // Pasa a inoperativo sin tocar consumibles: la versión del caché no sube y aun así sale.
        DB::table('equipos')->where('ID_EQUIPO', $equipo->ID_EQUIPO)->update(['ESTADO_OPERATIVO' => 'INOPERATIVO']);
        $this->assertSame([$equipo->ID_EQUIPO], array_column($pedir(), 'ID_EQUIPO'));
    }

    public function test_edicion_en_la_fila_responde_el_motivo_en_json(): void
    {
        $u = $this->superAdminGlobal();
        $c = Consumible::create(['FECHA' => '2031-02-01', 'IDENTIFICADOR' => 'REV' . Str::random(6), 'CANTIDAD' => 10,
            'TIPO_CONSUMIBLE' => 'GASOIL', 'UNIDAD' => 'LITROS', 'ESTADO_EQUIPO' => 'SIN_MATCH']);

        // Lo que manda la página (Accept JSON): 422 con el motivo, no una redirección.
        $this->actingAs($u)->patchJson(route('consumibles.updateIdentificador', $c->ID_CONSUMIBLE), ['identificador' => str_repeat('X', 101)])
            ->assertStatus(422)->assertJsonValidationErrors('identificador');
        $this->actingAs($u)->patchJson(route('consumibles.updateFrente', $c->ID_CONSUMIBLE), ['id_frente' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('id_frente');
        // Un id que no es número: 404, no un error del servidor.
        $this->actingAs($u)->patchJson('/admin/consumibles/abc/frente', ['id_frente' => 1])->assertNotFound();
        $this->actingAs($u)->deleteJson('/admin/consumibles/abc')->assertNotFound();
    }
}
