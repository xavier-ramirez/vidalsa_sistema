<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * La pantalla que llega del servidor YA viene filtrada.
 *
 * Importa porque el módulo abría con DOS viajes: el servidor pintaba la tabla y, nada más
 * montarse, `_hdInit` (historial_documentos_index.js) la volvía a pedir por AJAX en cuanto
 * la URL traía cualquier parámetro — y las pestañas siempre traen `?pestana=`. Medido en el
 * navegador, el módulo abre igual que los demás (295 ms contra 288 de Almacén), pero encima
 * se quedaba pidiendo otra vez lo mismo.
 *
 * Esa segunda petición se quitó. Estos casos son la red: si algún día el filtrado dejara de
 * hacerse en el servidor, aquí se ve — y sin ellos la pantalla se quedaría mostrando todo.
 */
class HistorialDocumentosSinRecargaTest extends MySqlTestCase
{
    private function usuario(): Usuario
    {
        $u = Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->whereNotNull('PERMISOS')->get()
            ->first(fn ($usr) => in_array('super.admin', array_map('strtolower', $usr->PERMISOS), true));
        $this->assertNotNull($u, 'hace falta un super.admin activo');

        return $u;
    }

    /** Cuántas filas de la tabla trae el HTML que responde el servidor. */
    private function filas(string $url): int
    {
        $html = $this->actingAs($this->usuario())->get($url)->assertOk()->getContent();
        $cuerpo = preg_split('/<tbody[^>]*id="historialTableBody"[^>]*>/i', $html);
        $this->assertCount(2, $cuerpo, "no se encontró el cuerpo de la tabla en {$url}");

        return preg_match_all('/<tr\b/i', explode('</tbody>', $cuerpo[1])[0]);
    }

    public function test_el_servidor_ya_devuelve_la_tabla_filtrada_por_fecha(): void
    {
        $todas = $this->filas('/admin/historial-documentos');
        $this->assertGreaterThan(0, $todas, 'sin filas no se puede comparar');

        // Una ventana imposible: el servidor tiene que devolver menos filas que sin filtro.
        $pocas = $this->filas('/admin/historial-documentos?fecha_desde=2099-01-01&fecha_hasta=2099-01-02');

        $this->assertLessThan($todas, $pocas, 'el filtro de fechas NO se aplicó en el servidor');
    }

    public function test_el_servidor_ya_devuelve_la_tabla_filtrada_por_equipo(): void
    {
        $todas = $this->filas('/admin/historial-documentos');
        $pocas = $this->filas('/admin/historial-documentos?search_equipo=ZZZNOEXISTE999');

        $this->assertLessThan($todas, $pocas, 'el filtro de equipo NO se aplicó en el servidor');
    }

    /** La pestaña también la resuelve el servidor: llega la pantalla correcta de una. */
    public function test_la_pestana_llega_resuelta_del_servidor(): void
    {
        $html = $this->actingAs($this->usuario())
            ->get('/admin/historial-documentos?pestana=documentos')->assertOk()->getContent();

        $this->assertStringContainsString('Revisión de documentos', $html);
    }
}
