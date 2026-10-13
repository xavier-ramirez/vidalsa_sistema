<?php

namespace Tests\Feature;

use App\Models\ProductoInventario;
use Tests\MySqlTestCase;

/**
 * Lo que Almacén y Movimientos ya no vuelven a bajar en cada apertura:
 *  - El catálogo del buscador (almacen.productos-autocomplete) lleva ETag: si el navegador ya
 *    tiene la misma lista, el servidor contesta 304 sin cuerpo. Se valida SIEMPRE, así que un
 *    cambio en el catálogo llega en la siguiente apertura.
 *  - El JavaScript de Movimientos vive en public/js/maquinaria/almacen_movimientos.js; en el
 *    HTML solo viajan los datos de la apertura (window.ALM_MOV_CFG).
 */
class AlmacenDescargasRepetidasTest extends MySqlTestCase
{
    private const URL_CATALOGO = '/admin/almacen/productos-autocomplete';

    public function test_el_catalogo_trae_etag_y_si_no_cambio_responde_304(): void
    {
        $user = $this->superAdminGlobal();

        $r = $this->actingAs($user)->getJson(self::URL_CATALOGO)->assertOk();
        $etag = $r->headers->get('ETag');
        $this->assertNotEmpty($etag, 'La lista debe llevar ETag.');
        $this->assertStringContainsString('no-cache', (string) $r->headers->get('Cache-Control'), 'Se revalida en cada apertura.');
        $this->assertNotEmpty($r->json(), 'Hace falta al menos un producto activo.');

        $r2 = $this->actingAs($user)->getJson(self::URL_CATALOGO, ['If-None-Match' => $etag]);
        $r2->assertStatus(304);
        $this->assertSame('', $r2->getContent(), 'El 304 no repite la lista.');

        // El servidor web reescribe el ETag al comprimir: nginx lo vuelve débil (W/"…") y Apache
        // le añade "-gzip". Con los dos tiene que seguir dando 304.
        $this->actingAs($user)->getJson(self::URL_CATALOGO, ['If-None-Match' => 'W/' . $etag])->assertStatus(304);
        $this->actingAs($user)->getJson(self::URL_CATALOGO, ['If-None-Match' => substr($etag, 0, -1) . '-gzip"'])->assertStatus(304);
    }

    public function test_si_el_catalogo_cambia_llega_la_lista_nueva(): void
    {
        $user = $this->superAdminGlobal();
        $etag = $this->actingAs($user)->getJson(self::URL_CATALOGO)->assertOk()->headers->get('ETag');

        $p = ProductoInventario::activos()->firstOrFail();
        $p->NOMBRE = $p->NOMBRE . ' (PRUEBA ETAG)';
        $p->save(); // la transacción de la prueba lo revierte

        $r = $this->actingAs($user)->getJson(self::URL_CATALOGO, ['If-None-Match' => $etag])->assertOk();
        $this->assertNotSame($etag, $r->headers->get('ETag'));
        $this->assertContains($p->NOMBRE, collect($r->json())->pluck('NOMBRE')->all());
    }

    public function test_movimientos_carga_su_js_de_archivo_y_no_en_el_html(): void
    {
        $html = (string) $this->actingAs($this->superAdminGlobal())
            ->get('/admin/almacen/movimientos')->assertOk()->getContent();

        $this->assertStringContainsString('js/maquinaria/almacen_movimientos.js', $html);
        $this->assertStringContainsString('window.ALM_MOV_CFG', $html);
        $this->assertStringContainsString('window.almMovArrancar(window.ALM_MOV_CFG)', $html);
        // El código ya no viaja en la página.
        $this->assertStringNotContainsString('window.almMovSuggestFn = function', $html);
        $this->assertStringNotContainsString('function almBorrarFilaKardex', $html);
        $this->assertFileExists(public_path('js/maquinaria/almacen_movimientos.js'));
    }
}
