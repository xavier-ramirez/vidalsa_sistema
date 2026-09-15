<?php

namespace Tests\Feature;

use App\Http\Controllers\EquipoController;
use Illuminate\Support\Facades\Cache;
use Tests\MySqlTestCase;

/**
 * /admin/equipos sin filtros abre con una muestra de equipos CON FOTO y de modelos distintos, y
 * es SIEMPRE LA MISMA: la selección (solo los IDs) queda en caché y se vuelve a elegir únicamente
 * si alguno de los guardados ya no se puede mostrar. Todo corre en la transacción de la prueba
 * (la caché también vive en la BD), así que no toca la muestra real.
 */
class EquiposMuestraInicialTest extends MySqlTestCase
{
    private function idsDe($filas): array
    {
        return $filas->pluck('ID_EQUIPO')->map(fn ($id) => (int) $id)->all();
    }

    public function test_al_abrir_sale_siempre_la_misma_muestra_con_foto_y_queda_en_cache(): void
    {
        $admin = $this->superAdminGlobal();
        $clave = EquipoController::claveMuestraInicial($admin);
        Cache::forget($clave);

        $primera = $this->actingAs($admin)->get(route('equipos.index'))->assertOk()->viewData('equipos');
        $ids = $this->idsDe($primera);

        $this->assertCount(6, $ids, 'Abre con 6 equipos.');
        $this->assertSame($ids, Cache::get($clave), 'La selección queda guardada en caché.');
        $this->assertTrue($primera->every(fn ($e) => $e->fotoDriveId() !== null), 'Todos se ven con foto.');
        $this->assertCount(6, $primera->map(fn ($e) => mb_strtoupper($e->MARCA . '|' . $e->MODELO))->unique(), 'De modelos distintos.');

        // Abrir otra vez devuelve exactamente la misma muestra, en el mismo orden.
        $this->assertSame($ids, $this->idsDe($this->actingAs($admin)->get(route('equipos.index'))->viewData('equipos')));

        // Y la LEE de la caché: con otro orden válido guardado, sale ese orden.
        $otroOrden = array_reverse($ids);
        Cache::put($clave, $otroOrden, 600);
        $this->assertSame($otroOrden, $this->idsDe($this->actingAs($admin)->get(route('equipos.index'))->viewData('equipos')));
    }

    public function test_si_un_equipo_guardado_ya_no_se_puede_mostrar_se_elige_de_nuevo(): void
    {
        $admin = $this->superAdminGlobal();
        $clave = EquipoController::claveMuestraInicial($admin);
        Cache::put($clave, [999999999], 600);   // un equipo que no existe

        $filas = $this->actingAs($admin)->get(route('equipos.index'))->assertOk()->viewData('equipos');

        $this->assertCount(6, $filas, 'Se vuelve a elegir una muestra completa.');
        $this->assertNotContains(999999999, Cache::get($clave), 'Y se guarda la nueva.');
    }
}
