<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * Qué se ve al entrar a Recepción (TraspasoController::index). Un usuario LOCAL entra según el
 * TIPO de su almacén: "Reposición del general" es la recepción de los almacenes de PROYECTO, y
 * el almacén GENERAL recibe por "Entrada por ODC". El GLOBAL igual: si su almacén (el de su
 * frente) es el GENERAL entra a ODC; si no, a la bandeja con «Todos».
 */
class RecepcionEntradaPorTipoTest extends MySqlTestCase
{
    /**
     * Un usuario cuyo almacén por defecto (el de su frente) es del tipo pedido. Con
     * $veProyecto, además tiene que ver algún almacén de PROYECTO (el que abre la bandeja);
     * con $mueve, además tiene la clave almacen.movimiento (la del menú Acciones). Con $local
     * queda LOCAL (NIVEL_ACCESO_ALMACEN=2; la transacción de la prueba lo deshace) y las
     * condiciones se miran ya como local.
     */
    private function usuarioConAlmacen(string $tipo, bool $veProyecto = false, bool $mueve = false, bool $local = false): Usuario
    {
        // Sin cambio de clave pendiente: EnsurePasswordChanged lo mandaría a cambiarla.
        $u = Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->where('ESTATUS', 'ACTIVO')->get()->first(function ($u) use ($tipo, $veProyecto, $mueve, $local) {
            if ($local) {
                $u->NIVEL_ACCESO_ALMACEN = 2;
            }
            $id = $u->almacenPorDefecto();
            return $id
                && (!$mueve || $u->can('almacen.movimiento'))
                && Almacen::visiblesPara($u)->where('ID_ALMACEN', $id)->exists()
                && Almacen::where('ID_ALMACEN', $id)->value('TIPO') === $tipo
                && (!$veProyecto || Almacen::visiblesPara($u)->where('TIPO', Almacen::TIPO_PROYECTO)->exists());
        });
        $this->assertNotNull($u, "Hace falta un usuario con un almacén {$tipo} en su frente.");
        if ($local) {
            $u->save();
        }
        return $u;
    }

    /** Un global cuyo almacén por frente es del tipo pedido (null = sin almacén propio). */
    private function globalConAlmacen(?string $tipo): ?Usuario
    {
        return Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->where('ESTATUS', 'ACTIVO')->get()->first(function ($u) use ($tipo) {
            if (!Almacen::usuarioEsGlobal($u) || !$u->can('almacen.movimiento')) {
                return false;
            }
            $id = $u->almacenPorDefecto();
            return $tipo === null ? !$id : ($id && Almacen::where('ID_ALMACEN', $id)->value('TIPO') === $tipo);
        });
    }

    public function test_los_del_almacen_general_entran_a_la_entrada_por_odc_aunque_sean_globales(): void
    {
        // Los usuarios de BARCELONA son globales: «Todos» de la bandeja no puede taparles su
        // recepción, que es por orden de compra.
        $u = $this->globalConAlmacen(Almacen::TIPO_GENERAL);
        $this->assertNotNull($u, 'Hace falta un global con el almacén GENERAL en su frente.');
        $this->actingAs($u)->get(route('almacen.recepcion.index'))
            ->assertRedirect(route('almacen.recepcion.nueva'));
        // Historial → Acciones → "Despachos" (?force=1) sigue abriendo la bandeja en «Todos».
        $r = $this->actingAs($u)->get(route('almacen.recepcion.index', ['force' => 1]))->assertOk();
        $this->assertTrue($r->viewData('esGlobal'));
        $this->assertNull($r->viewData('idAlmacenDestinoActivo'));
    }

    public function test_con_almacen_de_proyecto_entra_directo_a_la_reposicion(): void
    {
        $u = $this->usuarioConAlmacen(Almacen::TIPO_PROYECTO);
        $this->actingAs($u)->get(route('almacen.recepcion.index'))
            ->assertOk()
            ->assertViewIs('admin.almacen.recepcion.index');
    }

    public function test_la_bandeja_ofrece_compra_directa_y_registrar_y_despachar_en_acciones(): void
    {
        $u = $this->usuarioConAlmacen(Almacen::TIPO_PROYECTO, false, true);
        $r = $this->actingAs($u)->get(route('almacen.recepcion.index'))->assertOk();
        // El menú Acciones con sus dos opciones, y la Nota de Entrega del despacho con el
        // formato de cada almacén de la bandeja.
        $r->assertSeeInOrder(['id="trBtnAcciones"', 'Compra directa', 'Registrar y despachar'], false)
          ->assertSee('id="almSalidaModal"', false)
          ->assertSee('id="entDespPreviewModal"', false);
        $this->assertEqualsCanonicalizing(
            $r->viewData('almacenes')->pluck('ID_ALMACEN')->all(),
            array_keys($r->viewData('notaSalida')['formatoPorAlmacen'])
        );
    }

    public function test_con_almacen_general_entra_a_la_entrada_por_odc(): void
    {
        // LOCAL: el GLOBAL abre la bandeja con «Todos» (ver test_el_global_abre_…).
        $u = $this->usuarioConAlmacen(Almacen::TIPO_GENERAL, false, false, true);
        $this->actingAs($u)->get(route('almacen.recepcion.index'))
            ->assertRedirect(route('almacen.recepcion.nueva'));

        // Acciones → "Despachos" del Historial (?force=1) muestra la bandeja. Un local con
        // almacén GENERAL no ve ninguno de PROYECTO (los vería por un frente, y entonces ese
        // sería su almacén), así que la bandeja le ofrece el suyo.
        $r = $this->actingAs($u)->get(route('almacen.recepcion.index', ['force' => 1]))->assertOk();
        $this->assertSame((int) $u->almacenPorDefecto(), $r->viewData('idAlmacenDestinoActivo'));
    }

    public function test_la_bandeja_del_global_solo_ofrece_almacenes_de_proyecto(): void
    {
        // Al general nunca le llegan notas: la bandeja solo ofrece almacenes de PROYECTO.
        $r = $this->actingAs($this->superAdminGlobal())->get(route('almacen.recepcion.index', ['force' => 1]))->assertOk();
        $this->assertSame([Almacen::TIPO_PROYECTO], $r->viewData('almacenes')->pluck('TIPO')->unique()->values()->all());

        // Un enlace viejo a la bandeja de un GENERAL no se aplica: abre en «Todos».
        $general = (int) Almacen::where('TIPO', Almacen::TIPO_GENERAL)->where('ESTATUS', 'ACTIVO')->value('ID_ALMACEN');
        $r = $this->actingAs($this->superAdminGlobal())->get(route('almacen.recepcion.index', ['force' => 1, 'id_almacen_destino' => $general]))->assertOk();
        $this->assertNull($r->viewData('idAlmacenDestinoActivo'));
    }

    public function test_el_global_abre_la_bandeja_con_todos_los_almacenes(): void
    {
        // Un global cuyo almacén no es el GENERAL (de proyecto o ninguno): bandeja en «Todos».
        $g = $this->globalConAlmacen(Almacen::TIPO_PROYECTO) ?? $this->globalConAlmacen(null);
        if (!$g) {
            $this->markTestSkipped('No hay un global sin el almacén GENERAL en su frente.');
        }
        $r = $this->actingAs($g)->get(route('almacen.recepcion.index'))->assertOk();
        $this->assertTrue($r->viewData('esGlobal'));
        $this->assertNull($r->viewData('idAlmacenDestinoActivo'), 'Sin almacén elegido: «Todos».');
        $r->assertSee('data-value="all"', false)->assertSee('value="all"', false);

        // La tabla lista notas de cualquier almacén de la bandeja, no solo de uno.
        $destinos = $r->viewData('traspasos')->getCollection()->pluck('ID_ALMACEN_DESTINO')->unique();
        $hayVarios = \App\Models\Traspaso::whereIn('ESTADO', \App\Models\Traspaso::ESTADOS_BANDEJA_DEFAULT)
            ->distinct()->count('ID_ALMACEN_DESTINO') > 1;
        if ($hayVarios) {
            $this->assertGreaterThan(1, $destinos->count(), 'Con «Todos» salen notas de varios almacenes.');
        }

        // Y puede acotarla a uno.
        $uno = (int) $r->viewData('almacenes')->first()->ID_ALMACEN;
        $r = $this->actingAs($g)->get(route('almacen.recepcion.index', ['id_almacen_destino' => $uno]))->assertOk();
        $this->assertSame($uno, $r->viewData('idAlmacenDestinoActivo'));
    }

    public function test_desde_la_bandeja_se_llega_a_la_entrada_por_odc_por_acciones(): void
    {
        // Un global que abre la bandeja (la de proyectos, o vía ?force=1) también puede ir a la
        // recepción del almacén GENERAL desde el menú Acciones.
        $u = $this->superAdminGlobal();
        $r = $this->actingAs($u)->get(route('almacen.recepcion.index', ['force' => 1]))->assertOk();
        $r->assertSeeInOrder(['id="trAccionesMenu"', 'Registrar y despachar', 'href="' . route('almacen.recepcion.nueva') . '"', 'Entrada por ODC'], false);

        // Y la pantalla abre en un almacén GENERAL, aunque su frente lo lleve a uno de proyecto.
        $r = $this->actingAs($u)->get(route('almacen.recepcion.nueva'))->assertOk();
        $this->assertSame(Almacen::TIPO_GENERAL, $r->viewData('almacenDestino')->TIPO);
    }

    public function test_sin_almacen_general_visible_no_se_ofrece_la_entrada_por_odc(): void
    {
        $u = $this->usuarioConAlmacen(Almacen::TIPO_PROYECTO, false, true, true);
        if (Almacen::visiblesPara($u)->where('TIPO', Almacen::TIPO_GENERAL)->exists()) {
            $this->markTestSkipped('El local de prueba también ve un almacén general.');
        }
        $this->actingAs($u)->get(route('almacen.recepcion.index'))->assertOk()
            ->assertDontSee('href="' . route('almacen.recepcion.nueva') . '"', false);
    }

    public function test_el_local_solo_ve_su_almacen_y_no_tiene_todos(): void
    {
        $u = $this->usuarioConAlmacen(Almacen::TIPO_PROYECTO, false, false, true);
        $visibles =Almacen::visiblesPara($u)->pluck('ID_ALMACEN')->map(fn ($v) => (int) $v)->all();

        foreach ([[], ['id_almacen_destino' => 'all']] as $params) {
            $r = $this->actingAs($u)->get(route('almacen.recepcion.index', $params))->assertOk();
            $this->assertFalse($r->viewData('esGlobal'));
            $this->assertContains($r->viewData('idAlmacenDestinoActivo'), $visibles, 'Abre en uno de sus almacenes, aunque pida «Todos».');
            $r->assertDontSee('data-value="all"', false);
            foreach ($r->viewData('traspasos') as $t) {
                $this->assertSame($r->viewData('idAlmacenDestinoActivo'), (int) $t->ID_ALMACEN_DESTINO);
            }
        }
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
