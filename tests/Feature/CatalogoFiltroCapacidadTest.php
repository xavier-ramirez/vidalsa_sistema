<?php

namespace Tests\Feature;

use App\Models\EquipoAuxiliar;
use Tests\MySqlTestCase;

/**
 * Filtro Capacidad de /admin/catalogo (panel de Filtros Avanzados, al lado de Año): la tarjeta
 * sale si alguna de sus unidades tiene esa capacidad, y "60KVA" y "60 KVA" son la misma opción.
 * Corre en la transacción de la prueba.
 */
class CatalogoFiltroCapacidadTest extends MySqlTestCase
{
    private string $modelo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->modelo = 'MODELO-CAP-' . strtoupper(uniqid());
        // Dos unidades del mismo modelo y año (UNA tarjeta), escritas distinto y con otra capacidad.
        foreach (['777KVA', '777 kva', '888 KVA'] as $n => $cap) {
            EquipoAuxiliar::create([
                'TIPO' => 'PLANTA_ELECTRICA', 'MARCA' => 'PRUEBA', 'MODELO' => $this->modelo, 'ANIO' => 2001,
                'SERIAL' => 'CAP-' . uniqid() . $n, 'ESTADO_OPERATIVO' => 'OPERATIVO', 'CAPACIDAD' => $cap,
            ]);
        }
    }

    private function tarjetas(array $filtros): array
    {
        $html = $this->actingAs($this->superAdminGlobal())
            ->getJson(route('catalogo.index', $filtros + ['ajax_load' => 1]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json('html');

        return [preg_match_all('/class="cat-card[" ]/', $html), $html];
    }

    public function test_filtra_por_capacidad_sin_importar_espacios_ni_mayusculas(): void
    {
        [$n, $html] = $this->tarjetas(['capacidad' => '777KVA', 'modelo' => 'modelo_aux:' . $this->modelo]);
        $this->assertSame(1, $n);
        $this->assertStringContainsString($this->modelo, $html);

        // La otra capacidad de la MISMA tarjeta también la encuentra.
        $this->assertSame(1, $this->tarjetas(['capacidad' => '888KVA', 'modelo' => 'modelo_aux:' . $this->modelo])[0]);
        // Una capacidad que no tiene: nada.
        $this->assertSame(0, $this->tarjetas(['capacidad' => '999KVA', 'modelo' => 'modelo_aux:' . $this->modelo])[0]);
    }

    public function test_la_lista_de_capacidades_une_las_escritas_distinto(): void
    {
        $html = (string) $this->actingAs($this->superAdminGlobal())->get(route('catalogo.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-value="777KVA"'), '"777KVA" y "777 kva" son UNA opción.');
        $this->assertMatchesRegularExpression('/data-value="777KVA" data-label="777KVA" data-tipos="tipo_aux:PLANTA_ELECTRICA"/', $html);
        $this->assertStringContainsString('id="catTxtCapacidad"', $html);
        $this->assertStringContainsString('class="cat-adv-fila"', $html, 'Año y Capacidad van uno al lado del otro.');
    }

    public function test_con_capacidad_puesta_el_boton_avanzado_va_en_rojo(): void
    {
        $html = (string) $this->actingAs($this->superAdminGlobal())
            ->get(route('catalogo.index', ['capacidad' => '777KVA']))->assertOk()->getContent();
        $this->assertStringContainsString('btn-filtro-avanzado activo', $html);
        $this->assertStringContainsString('placeholder="777KVA"', $html, 'El filtro muestra la capacidad elegida.');
    }
}
