<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Panel "Filtros Avanzados" de /admin/equipos:
 *  - El listado y la exportación deciden "¿hay filtro?" con la MISMA regla
 *    (EquipoController::hayFiltroAplicado): un documento cuenta solo si llega como 'true'.
 *  - Un filtro activo al cargar la página se marca con .filter-active, la misma clase que
 *    pone selectOption por AJAX (antes el Blade lo pintaba con otro estilo).
 *  - Categoría Flota no aplica a auxiliares: se oculta en modo aux como GPS y Color.
 */
class EquiposFiltrosAvanzadosTest extends MySqlTestCase
{
    public function test_exportar_sin_filtros_no_deja_bajar_la_base(): void
    {
        $this->actingAs($this->superAdminGlobal())
            ->from(route('equipos.index'))
            ->get(route('equipos.export'))
            ->assertRedirect(route('equipos.index'))
            ->assertSessionHas('error');
    }

    public function test_un_documento_en_false_no_cuenta_como_filtro_ni_en_el_listado_ni_al_exportar(): void
    {
        $user = $this->superAdminGlobal();

        $this->actingAs($user)->from(route('equipos.index'))
            ->get(route('equipos.export', ['filter_propiedad' => 'false']))
            ->assertRedirect(route('equipos.index'));

        $r = $this->actingAs($user)->get(route('equipos.index', ['filter_propiedad' => 'false']))->assertOk();
        $this->assertCount(0, $r->viewData('equipos'), 'Sin filtro real el listado abre vacío, igual que la exportación.');
        $this->assertStringNotContainsString('btn-filtro-avanzado activo', (string) $r->getContent(), 'Y el botón de filtros no se pinta rojo.');
    }

    public function test_un_documento_en_true_si_cuenta_como_filtro(): void
    {
        $r = $this->actingAs($this->superAdminGlobal())
            ->get(route('equipos.index', ['filter_propiedad' => 'true']))->assertOk();

        $this->assertGreaterThan(0, count($r->viewData('equipos')));
        $r->assertSee('btn-filtro-avanzado activo', false);
    }

    public function test_el_filtro_activo_se_marca_con_la_misma_clase_que_usa_el_js(): void
    {
        $modelo = (string) DB::table('equipos')->whereNull('deleted_at')->whereNotNull('MODELO')->where('MODELO', '<>', '')->value('MODELO');
        $this->assertNotSame('', $modelo, 'Hace falta un equipo con modelo.');

        $html = (string) $this->actingAs($this->superAdminGlobal())
            ->get(route('equipos.index', ['modelo' => $modelo]))->assertOk()->getContent();

        $panel = $this->panel($html);
        $this->assertSame(1, substr_count($panel, 'adv-trigger filter-active'), 'Solo el trigger de Modelo va activo.');
        $this->assertStringNotContainsString("background: #e1effa", $panel, 'Sin el estilo en línea viejo.');
    }

    public function test_categoria_se_oculta_en_modo_aux_y_no_quedan_restos(): void
    {
        $html = (string) $this->actingAs($this->superAdminGlobal())->get(route('equipos.index'))->assertOk()->getContent();
        $panel = $this->panel($html);

        $this->assertMatchesRegularExpression('/<div class="adv-filter-eq-only">\s*<span class="panel-filtro-avanzado-label">Categoría Flota<\/span>/', $panel);
        $this->assertStringNotContainsString('filter_display_', $panel);
        $this->assertStringNotContainsString("classList.toggle('active')", $panel);
        $this->assertSame(10, substr_count($panel, 'class="panel-filtro-avanzado-label"'));
        $this->assertSame(6, substr_count($panel, 'adv-check'));
    }

    public function test_filtro_combustible_trae_solo_ese_combustible(): void
    {
        $r = $this->actingAs($this->superAdminGlobal())
            ->get(route('equipos.index', ['combustible' => 'GASOLINA']))->assertOk();

        $equipos = collect($r->viewData('equipos'));
        $this->assertGreaterThan(0, $equipos->count(), 'Hace falta algún equipo a gasolina.');
        $this->assertTrue($equipos->every(fn ($e) => $e->COMBUSTIBLE === 'GASOLINA'));
        $r->assertSee('btn-filtro-avanzado activo', false);

        $panel = $this->panel((string) $r->getContent());
        foreach (array_diff(\App\Models\Equipo::COMBUSTIBLES, ['NO APLICA']) as $comb) {
            $this->assertStringContainsString('data-value="' . $comb . '"', $panel, "Opción $comb en el desplegable.");
        }
        $this->assertStringNotContainsString('data-value="NO APLICA"', $panel, 'NO APLICA no se ofrece como combustible.');
    }

    public function test_filtro_detalle_busca_lo_que_contenga_el_texto(): void
    {
        $id = (int) DB::table('equipos')->whereNull('deleted_at')->whereNotNull('id_tipo_equipo')->value('ID_EQUIPO');
        DB::table('equipos')->where('ID_EQUIPO', $id)->update(['DETALLE_UBICACION_ACTUAL' => 'GALPON NORTE PRUEBA-ZX9']);
        // la transacción de la prueba lo revierte

        $user = $this->superAdminGlobal();
        $ids = collect($this->actingAs($user)->get(route('equipos.index', ['detalle' => 'prueba-zx']))->assertOk()->viewData('equipos'))->pluck('ID_EQUIPO')->all();
        $this->assertSame([$id], array_map('intval', $ids), 'Encuentra por una parte del texto, sin distinguir mayúsculas.');

        $this->assertCount(0, $this->actingAs($user)->get(route('equipos.index', ['detalle' => '100%_no_existe']))->assertOk()->viewData('equipos'),
            'Los comodines % y _ se buscan como texto, no como comodines.');
    }

    public function test_combustible_y_detalle_se_ocultan_en_modo_aux(): void
    {
        $panel = $this->panel((string) $this->actingAs($this->superAdminGlobal())->get(route('equipos.index'))->assertOk()->getContent());

        $this->assertMatchesRegularExpression('/<div class="adv-filter-eq-only"[^>]*>\s*<!-- Combustible -->/', $panel);
        $this->assertStringContainsString('id="detalleTextoFilter"', $panel);
    }

    /** El HTML del panel, desde su contenedor hasta el último filtro de documentos. */
    private function panel(string $html): string
    {
        $ini = strpos($html, 'id="advancedFilterPanel"');
        $fin = strpos($html, 'id="chk_adicional_2"', (int) $ini);
        $this->assertNotFalse($ini);
        $this->assertNotFalse($fin);

        return substr($html, $ini, $fin - $ini + 400);
    }
}
