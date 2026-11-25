<?php

namespace Tests\Feature;

use App\Console\Commands\AvisarCambios;
use App\Events\HuboCambios;
use App\Support\RegistroCambios;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\MySqlTestCase;

/**
 * Vigía del tiempo real (php artisan cambios:avisar): en cuanto el registro de cambios crece
 * avisa UNA vez (HuboCambios) y, si no hay nada nuevo, se calla. Sobre la tabla `cambios` real;
 * MySqlTestCase revierte lo escrito.
 */
class AvisarCambiosTest extends MySqlTestCase
{
    private AvisarCambios $vigia;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([HuboCambios::class]);
        $this->vigia = new AvisarCambios();
    }

    public function test_avisa_una_vez_por_cambio_y_se_calla_sin_cambios(): void
    {
        $this->assertFalse($this->vigia->vuelta(), 'la primera mirada solo toma la huella');
        $this->assertFalse($this->vigia->vuelta());

        $this->anotar();
        $this->assertTrue($this->vigia->vuelta(), 'un cambio nuevo avisa');
        $this->assertFalse($this->vigia->vuelta(), 'el mismo cambio no avisa dos veces');

        $this->anotar();
        $this->anotar();
        $this->assertTrue($this->vigia->vuelta(), 'varios cambios entre dos miradas: un solo aviso');

        Event::assertDispatchedTimes(HuboCambios::class, 2);
        Event::assertNotDispatched(HuboCambios::class, fn (HuboCambios $e) => $e->acceso);
    }

    public function test_avisa_la_fila_con_numero_menor_que_se_confirma_despues(): void
    {
        $tope = RegistroCambios::ultimo();
        $this->vigia->vuelta();

        // Dos transacciones: la de número mayor se ve primero y la otra después. El número
        // más alto ya no cambia al llegar la segunda; avisa igual.
        $this->anotar($tope + 2);
        $this->assertTrue($this->vigia->vuelta());
        $this->anotar($tope + 1);
        $this->assertTrue($this->vigia->vuelta(), 'la fila que llenó el hueco también avisa');
    }

    public function test_al_correr_la_ventana_no_avisa_de_mas_ni_pierde_lo_nuevo(): void
    {
        // Ventana chica: correrla no exige miles de filas.
        $this->vigia = new AvisarCambios(5);
        $this->vigia->vuelta();

        // Más filas que la ventana de una vez: avisa, y la ventana se corre detrás de ellas.
        for ($i = 0; $i < 8; $i++) {
            $this->anotar();
        }
        $this->assertTrue($this->vigia->vuelta());
        $this->assertFalse($this->vigia->vuelta(), 'correr la ventana no es un cambio');

        $this->anotar();
        $this->assertTrue($this->vigia->vuelta(), 'lo nuevo después de correrla avisa');
    }

    public function test_cambiarle_el_acceso_a_un_usuario_avisa(): void
    {
        $this->vigia->vuelta();

        DB::table('usuarios')->where('ID_USUARIO', DB::table('usuarios')->min('ID_USUARIO'))
            ->increment('NIVEL_ACCESO_EQUIPOS');
        $this->assertTrue($this->vigia->vuelta(), 'otro nivel cambia lo que su copia debe tener');
        $this->assertFalse($this->vigia->vuelta());
        // El aviso lo dice: cada pestaña mira ya si le cambiaron el acceso a ella.
        Event::assertDispatched(HuboCambios::class, fn (HuboCambios $e) => $e->acceso && $e->broadcastWith() === ['acceso' => true]);
    }

    public function test_cargar_o_editar_consumibles_avisa_a_su_lista(): void
    {
        $this->vigia->vuelta();

        $c = \App\Models\Consumible::create([
            'FECHA' => '2026-08-01', 'IDENTIFICADOR' => 'ZZ-VIGIA', 'CANTIDAD' => 1,
            'TIPO_CONSUMIBLE' => 'GASOIL', 'UNIDAD' => 'LITROS', 'ESTADO_EQUIPO' => 'SIN_MATCH',
        ]);
        $this->assertTrue($this->vigia->vuelta(), 'una fila nueva avisa');
        $this->assertFalse($this->vigia->vuelta());

        DB::table('consumibles')->where('ID_CONSUMIBLE', $c->ID_CONSUMIBLE)->update(['updated_at' => now()->addMinute()]);
        $this->assertTrue($this->vigia->vuelta(), 'editarla avisa');
        // El aviso lo dice: la lista de Consumibles se repinta (tiempo-real.js → 'consumibles-cambiaron').
        Event::assertDispatched(HuboCambios::class, fn (HuboCambios $e) => $e->consumibles && $e->broadcastWith() === ['consumibles' => true]);
        Event::assertNotDispatched(HuboCambios::class, fn (HuboCambios $e) => $e->acceso);
    }

    public function test_pasar_un_frente_de_asignado_a_bloqueado_avisa(): void
    {
        $id = DB::table('usuarios')->min('ID_USUARIO');
        DB::table('usuarios')->where('ID_USUARIO', $id)->update(['ID_FRENTE_ASIGNADO' => '5', 'ID_FRENTE_BLOQUEADO' => null]);
        $this->vigia->vuelta();

        // El mismo valor en el campo de al lado: sin COALESCE, CONCAT_WS daba la misma cadena.
        DB::table('usuarios')->where('ID_USUARIO', $id)->update(['ID_FRENTE_ASIGNADO' => null, 'ID_FRENTE_BLOQUEADO' => '5']);
        $this->assertTrue($this->vigia->vuelta());
    }

    public function test_con_reverb_caido_el_latido_que_falla_se_anota_una_sola_vez(): void
    {
        $this->vigia->vuelta();
        config(['broadcasting.connections.reverb.options.port' => 1]);
        Broadcast::purge('reverb');
        Event::swap(new Dispatcher($this->app));
        Log::spy();

        foreach (range(1, 3) as $_) {
            $this->assertFalse($this->vigia->latir());
            $this->assertFalse($this->vigia->vuelta(), 'la base sigue bien y no hay cambios');
        }
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_el_latido_va_aparte_de_los_cambios(): void
    {
        $this->assertTrue($this->vigia->latir());
        Event::assertDispatched(HuboCambios::class, fn (HuboCambios $e) => $e->broadcastAs() === 'latido');
        $this->assertSame('cambios', (new HuboCambios())->broadcastAs());
    }

    public function test_si_el_aviso_falla_se_repite_en_la_vuelta_siguiente(): void
    {
        $this->vigia->vuelta();
        $this->anotar();

        // Reverb caído: el aviso sale de verdad y falla (nadie escucha en ese puerto).
        config(['broadcasting.connections.reverb.options.port' => 1]);
        Broadcast::purge('reverb');
        Event::swap(new Dispatcher($this->app));
        $this->assertNull($this->vigia->vuelta(), 'sin Reverb la vuelta falla');

        Event::fake([HuboCambios::class]);
        $this->assertTrue($this->vigia->vuelta(), 'al volver Reverb, el cambio se avisa');
        $this->assertFalse($this->vigia->vuelta());
    }

    public function test_el_aviso_va_al_canal_por_reverb_aunque_broadcast_connection_diga_otra_cosa(): void
    {
        config(['broadcasting.default' => 'log']);
        $aviso = new HuboCambios();

        $this->assertSame(['reverb'], $aviso->broadcastConnections());
        $this->assertSame(config('broadcasting.cliente.canal'), $aviso->broadcastOn()->name);
        $this->assertStringStartsWith('cambios.', $aviso->broadcastOn()->name);
        $this->assertSame([], $aviso->broadcastWith(), 'el aviso no lleva datos');
    }

    private function anotar(?int $id = null): void
    {
        DB::table(RegistroCambios::TABLA)->insert(array_filter(['ID_CAMBIO' => $id, 'TABLA' => 'equipos', 'ID_FILA' => 1]));
    }
}
