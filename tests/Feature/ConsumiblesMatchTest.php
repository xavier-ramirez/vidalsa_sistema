<?php

namespace Tests\Feature;

use App\Models\Consumible;
use App\Services\Consumibles\MatchConsumibles;
use Tests\MySqlTestCase;

/**
 * Match de Consumibles (lo hace el servidor, sin botón): un PENDIENTE sin identificador no
 * tiene con qué buscarse y pasa a «Por identificar». La transacción de cada caso lo revierte todo.
 */
class ConsumiblesMatchTest extends MySqlTestCase
{
    private function consumo(?string $identificador): Consumible
    {
        return Consumible::create([
            'FECHA' => '2026-11-24', 'IDENTIFICADOR' => $identificador, 'CANTIDAD' => 10,
            'TIPO_CONSUMIBLE' => 'GASOIL', 'UNIDAD' => 'LITROS', 'ESTADO_EQUIPO' => 'PENDIENTE',
        ]);
    }

    public function test_pendiente_sin_identificador_queda_por_identificar(): void
    {
        $vacio = $this->consumo(null);
        $blanco = $this->consumo('');
        $raro = $this->consumo('ZZ-NO-EXISTE-' . random_int(100000, 999999));

        $r = (new MatchConsumibles())->ejecutar();   // la pasada completa (cada hora)

        foreach ([$vacio, $blanco, $raro] as $c) {
            $this->assertSame('SIN_MATCH', $c->fresh()->ESTADO_EQUIPO);
        }
        $this->assertGreaterThanOrEqual(3, $r['sin_match']);
        $this->assertSame(0, Consumible::where('ESTADO_EQUIPO', 'PENDIENTE')
            ->where(fn ($q) => $q->whereNull('IDENTIFICADOR')->orWhere('IDENTIFICADOR', ''))->count());
    }

    public function test_la_tabla_no_repite_el_identificador_y_usa_formato_espanol(): void
    {
        $c = $this->consumo('ZZ-TABLA-' . random_int(100000, 999999));
        $c->update(['CANTIDAD' => 1250.5, 'ESTADO_EQUIPO' => 'SIN_MATCH']);

        $html = $this->actingAs($this->superAdminGlobal())
            ->get(route('consumibles.index', ['identificador' => $c->IDENTIFICADOR]))
            ->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, $c->IDENTIFICADOR . '</span>'), 'El identificador sale una sola vez en la fila.');
        $this->assertStringContainsString('1.250,5', $html);
        $this->assertStringContainsString('Sin resolver', $html);
    }

    public function test_sin_resultados_con_filtros_lo_dice(): void
    {
        $this->actingAs($this->superAdminGlobal())
            ->get(route('consumibles.index', ['identificador' => 'ZZ-NADA-' . random_int(100000, 999999)]))
            ->assertOk()->assertSee('Ningún consumible cumple con los filtros.');
    }

    public function test_pasada_la_ultima_pagina_muestra_la_ultima(): void
    {
        $marca = 'ZZ-PAG-' . random_int(100000, 999999);
        $this->consumo($marca);

        $this->actingAs($this->superAdminGlobal())
            ->get(route('consumibles.index', ['identificador' => $marca, 'page' => 5]))
            ->assertOk()->assertSee($marca)->assertDontSee('Ningún consumible cumple con los filtros.');
    }

    public function test_los_contadores_no_arrastran_la_pagina(): void
    {
        $html = $this->actingAs($this->superAdminGlobal())
            ->get(route('consumibles.index', ['tipo' => 'GASOIL', 'page' => 2]))->assertOk()->getContent();

        preg_match('/id="consCntPend"[^>]*href="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m, 'El contador de pendientes es un enlace.');
        $url = html_entity_decode($m[1]);
        $this->assertStringContainsString('tipo=GASOIL', $url);
        $this->assertStringContainsString('estado=PENDIENTE', $url);
        $this->assertStringNotContainsString('page=', $url);
    }

    public function test_ya_no_hay_boton_ni_ruta_de_match(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('consumibles.matchAutomatico'));
        $this->actingAs($this->superAdminGlobal())->get(route('consumibles.index'))
            ->assertOk()->assertDontSee('Ejecutar Match')->assertDontSee('btnMatch');
    }

    public function test_guardar_en_la_tabla_identifica_el_equipo_al_momento(): void
    {
        $placa = \Illuminate\Support\Facades\DB::table('documentacion')->where('PLACA', 'regexp', '^[A-Z0-9]{5,}$')
            ->whereNotNull('ID_EQUIPO')->value('PLACA') ?? $this->markTestSkipped('No hay placas.');
        $frente = \App\Models\FrenteTrabajo::create(['NOMBRE_FRENTE' => 'PRUEBA MATCH ' . random_int(100000, 999999)]);

        $this->actingAs($this->superAdminGlobal())->postJson(route('consumibles.guardarLote'), [
            'tipo_consumible' => 'GASOIL', 'unidad' => 'LITROS', 'id_frente' => $frente->ID_FRENTE,
            'filas' => [['fecha' => '2026-08-01', 'identificador' => $placa, 'cantidad' => 10]],
        ])->assertOk();

        $c = Consumible::where('ID_FRENTE', $frente->ID_FRENTE)->firstOrFail();
        $this->assertSame('CONFIRMADO', $c->ESTADO_EQUIPO, 'Sin pulsar nada: el servidor ya lo identificó.');
        $this->assertNotNull($c->ID_EQUIPO);
    }

    public function test_un_por_identificar_que_sigue_igual_no_se_reescribe(): void
    {
        $c = $this->consumo('ZZ-QUIETO-' . random_int(100000, 999999));
        (new MatchConsumibles())->ejecutar([$c->ID_CONSUMIBLE]);
        $antes = Consumible::whereKey($c->ID_CONSUMIBLE)->value('updated_at');
        \Illuminate\Support\Carbon::setTestNow(now()->addHour());
        (new MatchConsumibles())->ejecutar([$c->ID_CONSUMIBLE]);
        \Illuminate\Support\Carbon::setTestNow();

        $this->assertSame((string) $antes, (string) Consumible::whereKey($c->ID_CONSUMIBLE)->value('updated_at'),
            'La pasada de cada hora no toca lo que sigue igual (no avisaría a las pantallas sin motivo).');
    }

    public function test_un_identificador_de_relleno_no_se_empareja(): void
    {
        $docs = \Illuminate\Support\Facades\DB::table('documentacion');
        if (! (clone $docs)->where('PLACA', '_')->exists()) {
            (clone $docs)->whereNotNull('ID_EQUIPO')->limit(1)->update(['PLACA' => '_']);
        }
        $c = $this->consumo('_');

        (new MatchConsumibles())->ejecutar([$c->ID_CONSUMIBLE]);

        $this->assertSame('SIN_MATCH', $c->fresh()->ESTADO_EQUIPO);
        $this->assertNull($c->fresh()->ID_EQUIPO);
    }
}
