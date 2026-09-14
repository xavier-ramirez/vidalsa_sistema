<?php

namespace Tests\Feature;

use Tests\MySqlTestCase;

/**
 * /menu llega sin la lista de Alertas de Documentos: la pide en segundo plano, con el menú ya
 * a la vista (menu.js · cargarAlertasDashboard → /dashboard/alerts-html). Pintada en la página
 * pesaba 900 KB y 3.400 elementos que se montaban en cada visita aunque el panel estuviera
 * cerrado.
 */
class MenuAlertasSegundoPlanoTest extends MySqlTestCase
{
    public function test_el_menu_llega_sin_la_lista_y_el_panel_la_trae_con_sus_datos(): void
    {
        $admin = $this->superAdminGlobal();

        $menu = $this->actingAs($admin)->get('/menu')->assertOk();
        $html = $menu->getContent();
        $this->assertStringNotContainsString('class="alert-card"', $html, 'la lista ya no va en la página');
        $this->assertStringContainsString('js-alertas-cargando', $html);
        $clave = $menu->original->getData()['claveAlertas'];
        $this->assertStringContainsString('data-clave="' . $clave . '"', $html);

        $panel = $this->actingAs($admin)->getJson(route('dashboard.alertsHtml'))->assertOk()->json();
        $this->assertSame($clave, $panel['clave'], 'misma clave: el menú puede reutilizar la lista');
        $this->assertSame(substr_count($panel['html'], 'class="alert-card"'), $panel['totalAlerts']);
        foreach ($panel['equiposData'] as $id => $datos) {
            $this->assertSame((int) $id, (int) $datos['equipoId'], 'cada equipo con su payload de detalle');
        }

        // Con la lista ya armada, el menú pinta su total de una vez (sin esperar al panel).
        $this->assertSame($panel['totalAlerts'], $this->actingAs($admin)->get('/menu')->original->getData()['totalAlerts']);
    }

    public function test_al_renovar_la_fecha_de_un_documento_su_alerta_sale_de_la_lista(): void
    {
        $admin = $this->superAdminGlobal();
        $antes = $this->actingAs($admin)->getJson(route('dashboard.alertsHtml'))->json();
        $this->assertSame(1, preg_match('/data-doc-type="poliza"\s+data-equipo-id="(\d+)"/', $antes['html'], $m),
            'hace falta una póliza en alertas para probar');
        $id = (int) $m[1];

        // Como el panel de datos del visor de PDF: nueva fecha de vencimiento, dentro de un año.
        $nueva = now()->addYear()->format('Y-m-d');
        \App\Support\CacheVersion::olvidarBumpsDelRequest();
        $this->actingAs($admin)->postJson(route('equipos.updateMetadata', $id), ['doc_type' => 'poliza', 'fecha_vencimiento' => $nueva])
            ->assertOk()->assertJson(['success' => true]);
        $this->assertSame($nueva, substr((string) \DB::table('documentacion')->where('ID_EQUIPO', $id)->value('FECHA_VENC_POLIZA'), 0, 10),
            'la fecha quedó guardada en la base de datos');

        $despues = $this->actingAs($admin)->getJson(route('dashboard.alertsHtml'))->json();
        $this->assertNotSame($antes['clave'], $despues['clave'], 'la lista del menú no puede reutilizarse');
        $this->assertSame($antes['totalAlerts'] - 1, $despues['totalAlerts']);
        $this->assertDoesNotMatchRegularExpression('/data-doc-type="poliza"\s+data-equipo-id="' . $id . '"/', $despues['html'],
            'la póliza renovada ya no está en la lista');
    }

    public function test_un_cambio_en_la_documentacion_cambia_la_clave(): void
    {
        $admin = $this->superAdminGlobal();
        $antes = $this->actingAs($admin)->getJson(route('dashboard.alertsHtml'))->json('clave');

        $doc = \App\Models\Documentacion::query()->first();
        $this->assertNotNull($doc, 'hace falta una documentación para probar');
        \App\Support\CacheVersion::olvidarBumpsDelRequest();
        $doc->touch();

        $this->assertNotSame($antes, $this->actingAs($admin)->getJson(route('dashboard.alertsHtml'))->json('clave'),
            'la lista del menú no puede reutilizarse tras cambiar un documento');
    }
}
