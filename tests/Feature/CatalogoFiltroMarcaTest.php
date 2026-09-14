<?php

namespace Tests\Feature;

use App\Models\Equipo;
use App\Models\TipoEquipo;
use Tests\MySqlTestCase;

/**
 * Filtros de /admin/catalogo: la Marca filtra por la marca de la tarjeta, y las listas de
 * Modelo, Marca y Año llevan los tipos en que aparece cada opción (data-tipos), para que al
 * elegir un Tipo solo se ofrezca lo de ese tipo (catSyncTipo en la vista).
 */
class CatalogoFiltroMarcaTest extends MySqlTestCase
{
    private TipoEquipo $tipo;
    private string $marca;
    private string $modelo;

    protected function setUp(): void
    {
        parent::setUp();
        $sufijo = strtoupper(uniqid());
        $this->tipo   = TipoEquipo::create(['nombre' => 'TIPO PRUEBA ' . $sufijo]);
        $this->marca  = 'MARCA-PRUEBA-' . $sufijo;
        $this->modelo = 'MODELO-PRUEBA-' . $sufijo;
        // Sin ficha: sale sola en el catálogo, con la marca y el tipo de su unidad.
        Equipo::create([
            'MARCA' => $this->marca, 'MODELO' => $this->modelo, 'ANIO' => 1999, 'ESTADO_OPERATIVO' => 'OPERATIVO',
            'SERIAL_CHASIS' => 'TEST-MARCA-' . $sufijo, 'id_tipo_equipo' => $this->tipo->id,
        ]);
    }

    private function pagina(array $filtros): array
    {
        return $this->actingAs($this->superAdminGlobal())
            ->getJson(route('catalogo.index', $filtros + ['ajax_load' => 1]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json();
    }

    public function test_el_filtro_marca_trae_solo_las_tarjetas_de_esa_marca(): void
    {
        $html = $this->pagina(['marca' => strtolower($this->marca)])['html'];
        $this->assertSame(1, preg_match_all('/class="cat-card[" ]/', $html), 'Mayúsculas o minúsculas da igual.');
        $this->assertStringContainsString($this->modelo, $html);

        $this->assertSame(0, preg_match_all('/class="cat-card[" ]/', $this->pagina(['marca' => $this->marca . '-NO'])['html']));
    }

    public function test_las_opciones_de_los_filtros_llevan_su_tipo(): void
    {
        $html = $this->actingAs($this->superAdminGlobal())->get(route('catalogo.index'))->assertOk()->getContent();
        $tipo = 'tipo_eq:' . $this->tipo->id;

        $this->assertMatchesRegularExpression('/data-value="modelo_eq:' . preg_quote($this->modelo, '/') . '"[^>]*data-tipos="' . $tipo . '"/', $html);
        $this->assertMatchesRegularExpression('/data-value="' . preg_quote($this->marca, '/') . '"[^>]*data-tipos="' . $tipo . '"/', $html);
        $this->assertMatchesRegularExpression('/data-value="1999"[^>]*data-tipos="[^"]*' . $tipo . '[^"]*"/', $html);
    }
}
