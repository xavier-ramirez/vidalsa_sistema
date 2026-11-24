<?php

namespace Tests\Feature;

use App\Models\Consumible;
use Tests\MySqlTestCase;

/**
 * Match automático de Consumibles: un PENDIENTE sin identificador no tiene con qué buscarse
 * y pasa a «Por identificar» (antes se quedaba pendiente para siempre y el contador del
 * Match no bajaba nunca). La transacción de cada caso lo revierte todo.
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

        $r = $this->actingAs($this->superAdminGlobal())->postJson(route('consumibles.matchAutomatico'))->assertOk();

        foreach ([$vacio, $blanco, $raro] as $c) {
            $this->assertSame('SIN_MATCH', $c->fresh()->ESTADO_EQUIPO);
        }
        $this->assertGreaterThanOrEqual(3, $r->json('sin_match'));
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
}
