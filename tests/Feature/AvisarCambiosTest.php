<?php

namespace Tests\Feature;

use App\Console\Commands\AvisarCambios;
use App\Events\HuboCambios;
use App\Support\RegistroCambios;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        $this->vigia->vuelta();

        // Más filas que la ventana de una vez: avisa, y la ventana se corre detrás de ellas.
        $this->anotarMuchas(AvisarCambios::VENTANA + 50);
        $this->assertTrue($this->vigia->vuelta());
        $this->assertFalse($this->vigia->vuelta(), 'correr la ventana no es un cambio');

        $this->anotar();
        $this->assertTrue($this->vigia->vuelta(), 'lo nuevo después de correrla avisa');
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

    private function anotarMuchas(int $n): void
    {
        foreach (array_chunk(array_fill(0, $n, ['TABLA' => 'equipos', 'ID_FILA' => 1]), 500) as $filas) {
            DB::table(RegistroCambios::TABLA)->insert($filas);
        }
    }
}
