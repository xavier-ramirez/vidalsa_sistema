<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * Qué se ve al entrar a Recepción (TraspasoController::index) lo decide el TIPO del almacén
 * del usuario, no si el usuario es global: "Reposición del general" es la recepción de los
 * almacenes de PROYECTO, y el almacén GENERAL recibe por "Entrada por ODC".
 */
class RecepcionEntradaPorTipoTest extends MySqlTestCase
{
    /**
     * Un usuario cuyo almacén por defecto (el de su frente) es del tipo pedido. Con
     * $veProyecto, además tiene que ver algún almacén de PROYECTO (el que abre la bandeja).
     */
    private function usuarioConAlmacen(string $tipo, bool $veProyecto = false): Usuario
    {
        // Sin cambio de clave pendiente: EnsurePasswordChanged lo mandaría a cambiarla.
        $u = Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->where('ESTATUS', 'ACTIVO')->get()->first(function ($u) use ($tipo, $veProyecto) {
            $id = $u->almacenPorDefecto();
            return $id
                && Almacen::visiblesPara($u)->where('ID_ALMACEN', $id)->exists()
                && Almacen::where('ID_ALMACEN', $id)->value('TIPO') === $tipo
                && (!$veProyecto || Almacen::visiblesPara($u)->where('TIPO', Almacen::TIPO_PROYECTO)->exists());
        });
        $this->assertNotNull($u, "Hace falta un usuario con un almacén {$tipo} en su frente.");
        return $u;
    }

    public function test_con_almacen_de_proyecto_entra_directo_a_la_reposicion(): void
    {
        $u = $this->usuarioConAlmacen(Almacen::TIPO_PROYECTO);
        $this->actingAs($u)->get(route('almacen.recepcion.index'))
            ->assertOk()
            ->assertSee('Reposición del general');
    }

    public function test_con_almacen_general_entra_a_la_entrada_por_odc(): void
    {
        $u = $this->usuarioConAlmacen(Almacen::TIPO_GENERAL, true);
        $this->actingAs($u)->get(route('almacen.recepcion.index'))
            ->assertRedirect(route('almacen.recepcion.nueva'));

        // Acciones → "Reposición del general" (?force=1) muestra la bandeja, abierta en un
        // almacén de PROYECTO (al general nunca le llegan notas) y ofreciendo solo esos.
        $r = $this->actingAs($u)->get(route('almacen.recepcion.index', ['force' => 1]))->assertOk();
        $almacenes = $r->viewData('almacenes');
        $this->assertNotEmpty($almacenes);
        $this->assertSame([Almacen::TIPO_PROYECTO], $almacenes->pluck('TIPO')->unique()->values()->all());
        $this->assertContains($r->viewData('idAlmacenDestinoActivo'), $almacenes->pluck('ID_ALMACEN')->all());

        // Un enlace viejo a la bandeja del GENERAL no se aplica: abre en uno de la lista.
        $general = (int) $u->almacenPorDefecto();
        $r = $this->actingAs($u)->get(route('almacen.recepcion.index', ['force' => 1, 'id_almacen_destino' => $general]))->assertOk();
        $this->assertNotSame($general, $r->viewData('idAlmacenDestinoActivo'));
        $this->assertContains($r->viewData('idAlmacenDestinoActivo'), $almacenes->pluck('ID_ALMACEN')->all());
    }

    public function test_la_entrada_por_odc_trae_acciones_con_la_reposicion(): void
    {
        $this->actingAs($this->usuarioConAlmacen(Almacen::TIPO_GENERAL))->get(route('almacen.recepcion.nueva'))
            ->assertOk()
            ->assertSee('id="entAccionesMenu"', false)
            ->assertSee(route('almacen.recepcion.index', ['force' => 1]), false)
            ->assertDontSee('class="tr-tabs"', false);
    }
}
