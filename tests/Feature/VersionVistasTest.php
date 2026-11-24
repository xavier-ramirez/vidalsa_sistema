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

    public function test_el_endpoint_da_la_misma_version_que_el_layout(): void
    {
        Cache::forget('version_vistas');
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.movimiento'));
        $this->assertNotNull($u, 'Hace falta un usuario con acceso a Almacén.');

        $html = $this->actingAs($u)->get(route('almacen.index'))->assertOk()->getContent();
        preg_match('/<meta name="version-vistas" content="(\d+)">/', $html, $m);

        // La pestaña abierta pregunta aquí cada minuto: si devolviera otra cosa, avisaría de
        // "cambios nuevos" cada vez (o nunca). Tiene que ser la MISMA huella.
        $this->actingAs($u)->getJson(route('version.vistas'))
            ->assertOk()
            ->assertJson(['v' => $m[1]]);
    }

    /**
     * Cambiarle los permisos a alguien con la pestaña abierta: el endpoint que esa pestaña
     * pregunta cada minuto devuelve OTRA huella de acceso que la del <meta usuario-acceso> con
     * que se pintó, y navegacion.js recarga (botones, permisos del JS y pantallas guardadas).
     */
    public function test_cambiar_permisos_cambia_la_huella_que_ve_la_pestana_abierta(): void
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.movimiento'));
        $this->assertNotNull($u, 'Hace falta un usuario con acceso a Almacén.');

        $html = $this->actingAs($u)->get(route('almacen.index'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<meta name="usuario-acceso" content="([0-9a-f]{12})">/', $html, $m));
        $this->actingAs($u)->getJson(route('version.vistas'))->assertOk()->assertJson(['a' => $m[1]]);

        // Un administrador le da un permiso (en la BD, como el formulario de Usuarios).
        $nuevo = $u->PERMISOS ? $u->getRawOriginal('PERMISOS') . ',almacen.foto' : 'almacen.foto';
        \Illuminate\Support\Facades\DB::table('usuarios')->where('ID_USUARIO', $u->ID_USUARIO)->update(['PERMISOS' => $nuevo]);

        $a = $this->actingAs($u->fresh())->getJson(route('version.vistas'))->assertOk()->json('a');
        $this->assertNotSame($m[1], $a, 'La pestaña abierta tiene que enterarse del permiso nuevo.');
        $this->assertSame($u->fresh()->huellaAcceso(), $a);
    }

    public function test_el_endpoint_de_version_pide_sesion(): void
    {
        $this->getJson(route('version.vistas'))->assertUnauthorized();
    }
}
