<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\Cache;
use Tests\MySqlTestCase;

/**
 * El layout lleva la versión de las vistas (la modificación más reciente de resources/views)
 * y navegacion.js recarga completo al navegar si cambió: el código de cada módulo va dentro
 * de su vista y se inicia una sola vez por pestaña (ver AppServiceProvider).
 */
class VersionVistasTest extends MySqlTestCase
{
    public function test_el_layout_lleva_la_version_de_las_vistas(): void
    {
        Cache::forget('version_vistas');
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.movimiento'));
        $this->assertNotNull($u, 'Hace falta un usuario con acceso a Almacén.');

        // Antes de pedir la página: la versión que salga no puede ser anterior a esto.
        $antes = collect(['layouts/estructura_base.blade.php', 'admin/almacen/index.blade.php'])
            ->mapWithKeys(fn ($v) => [$v => filemtime(resource_path('views/' . $v))]);

        $html = $this->actingAs($u)->get(route('almacen.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<meta name="version-vistas" content="(\d+)">/', $html);
        preg_match('/<meta name="version-vistas" content="(\d+)">/', $html, $m);

        // Es la que quedó cacheada y no es anterior a las vistas de esta página. (No se compara
        // con el máximo recalculado después: si otra sesión edita una vista mientras corre la
        // prueba, ese máximo ya sería otro.)
        $this->assertSame($m[1], (string) Cache::get('version_vistas'));
        foreach ($antes as $vista => $mtime) {
            $this->assertGreaterThanOrEqual($mtime, (int) $m[1], "Más nueva o igual que {$vista}.");
        }
    }
}
