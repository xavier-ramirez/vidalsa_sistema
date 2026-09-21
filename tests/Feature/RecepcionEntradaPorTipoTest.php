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
            ->assertViewIs('admin.almacen.recepcion.index');
    }

    public function test_con_almacen_general_entra_a_la_entrada_por_odc(): void
    {
        $u = $this->usuarioConAlmacen(Almacen::TIPO_GENERAL, true);
        $this->actingAs($u)->get(route('almacen.recepcion.index'))
            ->assertRedirect(route('almacen.recepcion.nueva'));

        // Acciones → "Despachos" del Historial (?force=1) muestra la bandeja, abierta en un
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

    public function test_la_entrada_por_odc_pide_el_documento_en_un_modal(): void
    {
        $this->actingAs($this->usuarioConAlmacen(Almacen::TIPO_GENERAL))->get(route('almacen.recepcion.nueva'))
            ->assertOk()
            // Nota de entrega, proveedor y fecha van en el modal que abre "Registrar entrada".
            ->assertSeeInOrder(['id="entDocOverlay"', 'id="entNotaEntrega"', 'id="entProveedor"', 'id="entFecha"'], false);
    }

    public function test_el_historial_ofrece_el_estado_del_despacho_en_acciones(): void
    {
        $r = $this->actingAs($this->usuarioConAlmacen(Almacen::TIPO_GENERAL, true))->get(route('almacen.movimientos'))
            ->assertOk();
        // Debajo de "Dashboard de consumo", con lo que falta por recibir en los proyectos: la
        // pill lleva solo el número y la frase entera va en el title.
        $pendientes = $r->viewData('porRecibirPry');
        $this->assertIsInt($pendientes);
        $r->assertSeeInOrder(['Dashboard de consumo', $pendientes > 0 ? "{$pendientes} notas por recibir" : 'Todo recibido',
                'Despachos', $pendientes > 0 ? (string) $pendientes : 'Al día'])
            ->assertSee(route('almacen.recepcion.index', ['force' => 1]), false);
    }
}
