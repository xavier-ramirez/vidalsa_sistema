<?php

namespace Tests\Feature;

use App\Models\Consumible;
use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Lista y Gráficos de consumibles: filtros mal formados no dan 500, el Top 4 y los inoperativos
 * están al día cuando cambia el equipo (sin tocar consumibles), y la edición en la fila responde
 * con el motivo.
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

    public function test_top_e_inoperativos_al_dia_cuando_cambia_el_equipo(): void
    {
        $u = $this->superAdminGlobal();
        $frente = FrenteTrabajo::create(['NOMBRE_FRENTE' => 'PRUEBA INOPERATIVOS ' . strtoupper(Str::random(5))]);
        [$tipoA, $tipoB] = DB::table('tipo_equipos')->orderBy('id')->limit(2)->pluck('id')->all();
        $equipo = Equipo::create(['MARCA' => 'PRUEBA', 'MODELO' => 'INO', 'ANIO' => 2020,
            'SERIAL_CHASIS' => 'TSTINO' . random_int(100000, 999999), 'id_tipo_equipo' => $tipoA]);
        DB::table('equipos')->where('ID_EQUIPO', $equipo->ID_EQUIPO)
            ->update(['ID_FRENTE_ACTUAL' => $frente->ID_FRENTE, 'ESTADO_OPERATIVO' => 'OPERATIVO']);
        Consumible::create(['FECHA' => '2031-01-10', 'IDENTIFICADOR' => $equipo->SERIAL_CHASIS, 'CANTIDAD' => 50,
            'TIPO_CONSUMIBLE' => 'GASOIL', 'UNIDAD' => 'LITROS', 'ID_FRENTE' => $frente->ID_FRENTE,
            'ID_EQUIPO' => $equipo->ID_EQUIPO, 'ESTADO_EQUIPO' => 'CONFIRMADO']);
        $pedir = fn () => $this->actingAs($u)
            ->getJson(route('consumibles.graficosData', ['id_frente' => $frente->ID_FRENTE, 'desde' => '2031-01-01', 'hasta' => '2031-01-31']))
            ->assertOk()->json();
        $nombre = fn ($id) => DB::table('tipo_equipos')->where('id', $id)->value('nombre');

        $r = $pedir();
        $this->assertSame([], $r['inoperativos']);
        $this->assertSame($nombre($tipoA), $r['top_equipos'][0]['tipo']);
        // Cambia el equipo sin tocar consumibles (otro tipo, inoperativo): la siguiente carga ya lo dice.
        DB::table('equipos')->where('ID_EQUIPO', $equipo->ID_EQUIPO)->update(['ESTADO_OPERATIVO' => 'INOPERATIVO', 'id_tipo_equipo' => $tipoB]);
        $r = $pedir();
        $this->assertSame([$equipo->ID_EQUIPO], array_column($r['inoperativos'], 'ID_EQUIPO'));
        $this->assertSame($nombre($tipoB), $r['top_equipos'][0]['tipo']);
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

    public function test_cambiar_el_frente_recuerda_con_cual_se_cargo(): void
    {
        $u = $this->superAdminGlobal();
        $sufijo = strtoupper(Str::random(5));
        [$maturin, $veladero, $otro] = array_map(fn ($n) => FrenteTrabajo::create(['NOMBRE_FRENTE' => "PRUEBA $n $sufijo"]), ['MATURIN', 'VELADERO', 'OTRO']);
        $c = Consumible::create(['FECHA' => '2031-03-01', 'IDENTIFICADOR' => 'REV' . Str::random(6), 'CANTIDAD' => 30,
            'TIPO_CONSUMIBLE' => 'GASOIL', 'UNIDAD' => 'LITROS', 'ID_FRENTE' => $maturin->ID_FRENTE, 'ESTADO_EQUIPO' => 'SIN_MATCH']);
        $cambiar = fn ($f) => $this->actingAs($u)->patchJson(route('consumibles.updateFrente', $c->ID_CONSUMIBLE), ['id_frente' => $f->ID_FRENTE])->assertOk();

        $cambiar($veladero);
        $this->assertSame([$veladero->ID_FRENTE, $maturin->ID_FRENTE], [(int) $c->fresh()->ID_FRENTE, (int) $c->fresh()->ID_FRENTE_ORIGINAL]);
        // La Lista muestra el actual y, debajo, con cuál se cargó.
        $html = $this->actingAs($u)->get(route('consumibles.index', ['tipo' => 'GASOIL', 'identificador' => $c->IDENTIFICADOR]))->getContent();
        $this->assertStringContainsString("Cargado en: PRUEBA MATURIN $sufijo", $html);
        // Un segundo cambio no pisa el original.
        $cambiar($otro);
        $this->assertSame($maturin->ID_FRENTE, (int) $c->fresh()->ID_FRENTE_ORIGINAL);
        // Volver al de la carga: como si nunca se hubiera cambiado.
        $cambiar($maturin);
        $this->assertNull($c->fresh()->ID_FRENTE_ORIGINAL);
    }
}
