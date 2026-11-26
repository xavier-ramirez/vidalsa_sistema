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

    public function test_lo_escrito_a_mano_encuentra_un_auxiliar_por_su_codigo(): void
    {
        $aux = \Illuminate\Support\Facades\DB::table('equipos_auxiliares')->whereNull('deleted_at')
            ->whereNotNull('CODIGO_INTERNO')->where('CODIGO_INTERNO', '!=', '')->first()
            ?? $this->markTestSkipped('No hay auxiliares con código.');
        $codigo = mb_strtoupper(trim($aux->CODIGO_INTERNO));
        $esDeUnEquipo = \Illuminate\Support\Facades\DB::table('documentacion')->whereRaw('UPPER(TRIM(PLACA)) = ?', [$codigo])->exists()
            || \Illuminate\Support\Facades\DB::table('equipos')->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereRaw('UPPER(TRIM(SERIAL_CHASIS)) = ?', [$codigo])->orWhereRaw('UPPER(TRIM(CODIGO_PATIO)) = ?', [$codigo]))->exists();
        if ($esDeUnEquipo) {
            $this->markTestSkipped('Ese código es también la placa o el serial de un equipo (gana el equipo).');
        }
        $c = $this->consumo($aux->CODIGO_INTERNO);

        (new MatchConsumibles())->ejecutar([$c->ID_CONSUMIBLE]);

        $c->refresh();
        $this->assertSame('CONFIRMADO', $c->ESTADO_EQUIPO);
        $this->assertEquals($aux->ID_AUXILIAR, $c->ID_AUXILIAR, 'El código exacto del auxiliar gana al parcial de un equipo.');
        $this->assertNull($c->ID_EQUIPO);
    }

    public function test_al_cambiar_una_placa_el_programador_reintenta_lo_por_identificar(): void
    {
        $doc = \App\Models\Documentacion::whereNotNull('PLACA')->where('PLACA', '!=', '')
            ->whereHas('equipo')->first()
            ?? $this->markTestSkipped('No hay placas de equipos activos.');
        \Illuminate\Support\Facades\Cache::forget(MatchConsumibles::MARCA_REINTENTO);
        $placa = 'ZZ' . random_int(10000, 99999);
        $c = $this->consumo($placa);
        $c->update(['ESTADO_EQUIPO' => 'SIN_MATCH']);

        $doc->update(['PLACA' => $placa]);   // observer → pedirReintento (no hace esperar a quien guarda)
        $this->assertSame('SIN_MATCH', $c->fresh()->ESTADO_EQUIPO);
        $this->assertTrue(MatchConsumibles::hayTrabajoDelMinuto());

        MatchConsumibles::pasadaDelMinuto();   // lo que corre el programador en el minuto

        $c->refresh();
        $this->assertSame('CONFIRMADO', $c->ESTADO_EQUIPO);
        $this->assertEquals($doc->ID_EQUIPO, $c->ID_EQUIPO);
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has(MatchConsumibles::MARCA_REINTENTO));
    }

    public function test_sin_equipo_muestra_lo_que_dice_el_excel(): void
    {
        $c = $this->consumo('ZZ-EXCEL-' . random_int(100000, 999999));
        $c->update(['ESTADO_EQUIPO' => 'SIN_MATCH', 'DESC_EQUIPO' => 'EXCAVADORA ZZMARCA']);

        $this->actingAs($this->superAdminGlobal())
            ->get(route('consumibles.index', ['identificador' => $c->IDENTIFICADOR]))
            ->assertOk()->assertSee('EXCAVADORA ZZMARCA')->assertSee('Sin asociar');
    }

    public function test_la_descripcion_del_excel_solo_se_quita_si_va_al_frente(): void
    {
        // Con descripción en el Excel: «DESC | PLACA» → la placa (la descripción va aparte).
        $this->assertSame('A12BC3D', Consumible::escritoSinDescripcion('CAMION 350 | A12BC3D', 'Camión 350'));
        // Sin descripción (DESC_EQUIPO del catálogo): la primera parte es la placa y se queda.
        $this->assertSame('A12BC3D | COD9', Consumible::escritoSinDescripcion('A12BC3D | COD9', 'EXCAVADORA X'));
        $this->assertSame('6933', Consumible::escritoSinDescripcion('6933', null));
    }

    public function test_filtros_mal_formados_no_rompen_la_lista_ni_el_export(): void
    {
        $u = $this->superAdminGlobal();
        $this->actingAs($u)->get(route('consumibles.index', ['estado' => 'FOO', 'tipo' => ['X'], 'identificador' => ['A']]))->assertOk();
        $this->actingAs($u)->get(route('consumibles.exportarCsv', ['estado' => 'FOO', 'tipo' => ['X'], 'identificador' => ['A']]))->assertOk();
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

    public function test_lo_pegado_como_viene_en_el_reporte_saca_la_placa_de_dentro(): void
    {
        // «CHUTO STK PLACA: A59BEO2R» (reporte de ventas de Patio El Tigre pegado con Ctrl+V): el
        // texto entero no es ninguna placa; las reglas de la carga de Excel sacan la de dentro.
        $placa = \Illuminate\Support\Facades\DB::table('documentacion')->where('PLACA', 'regexp', '^[A-Z][0-9]{2}[A-Z]{2}[0-9][A-Z]$')
            ->whereNotNull('ID_EQUIPO')->value('PLACA') ?? $this->markTestSkipped('No hay placas.');
        $texto = 'VEHICULO PLACA: ' . $placa;
        $esperado = (new \App\Services\Consumibles\IdentificadorConsumo())->resolverEquipo(['desc' => $texto]);
        if ($esperado['estado'] === 'SIN_MATCH') {
            $this->markTestSkipped('Esa placa no la resuelven las reglas del Excel.');
        }
        $c = $this->consumo($texto);

        (new MatchConsumibles())->ejecutar([$c->ID_CONSUMIBLE]);

        $this->assertSame('CONFIRMADO', $c->fresh()->ESTADO_EQUIPO);
        $this->assertSame($esperado['id_equipo'], $c->fresh()->ID_EQUIPO, 'El mismo equipo que si se hubiera subido el Excel.');
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
