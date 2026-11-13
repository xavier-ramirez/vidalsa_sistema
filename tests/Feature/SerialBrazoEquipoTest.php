<?php

namespace Tests\Feature;

use App\Models\Equipo;
use App\Models\TipoEquipo;
use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * Serial del brazo (equipos.SERIAL_BRAZO): los equipos de izaje con brazo o grúa montada lo
 * tienen; se escribe en los dos formularios y se ve en «Información General» de la ficha.
 * Qué tipos lo llevan lo decide UNA regla, TipoEquipo::llevaBrazo().
 */
class SerialBrazoEquipoTest extends MySqlTestCase
{
    private function editor(): Usuario
    {
        $user = Usuario::all()->first(fn ($u) => $u->can('equipos.create'));
        $this->assertNotNull($user, 'No hay usuario con permiso equipos.create.');

        return $user;
    }

    /** Un equipo de un tipo que lleva brazo y otro que no. */
    private function equipoDeTipo(bool $conBrazo): Equipo
    {
        $equipo = Equipo::with('tipo')->whereNotNull('ID_FRENTE_ACTUAL')->get()
            ->first(fn ($e) => TipoEquipo::llevaBrazo($e->tipo->nombre ?? null) === $conBrazo && !$e->SERIAL_BRAZO);
        $this->assertNotNull($equipo, 'Hace falta un equipo ' . ($conBrazo ? 'con' : 'sin') . ' brazo para la prueba.');

        return $equipo;
    }

    public function test_que_tipos_llevan_brazo(): void
    {
        foreach (['CAMION CON BRAZO 4 TON', 'CHUTO CON BRAZO 16 TON', 'CAMION GRUA', 'BRAZO HIDRAULICO', 'grúa de terceros'] as $tipo) {
            $this->assertTrue(TipoEquipo::llevaBrazo($tipo), "$tipo lleva brazo");
        }
        foreach (['CHUTO', 'VOLTEO', 'CAMIONETA', '', null] as $tipo) {
            $this->assertFalse(TipoEquipo::llevaBrazo($tipo), var_export($tipo, true) . ' no lleva brazo');
        }
    }

    public function test_la_ficha_lo_trae_solo_en_los_equipos_con_brazo(): void
    {
        $this->assertSame('N/A', $this->equipoDeTipo(true)->toDetailsPayload()['serialBrazo'],
            'Con brazo y sin serial: la fila sale con N/A, para que se vea que falta.');
        $this->assertNull($this->equipoDeTipo(false)->toDetailsPayload()['serialBrazo'],
            'Sin brazo: null, la ficha esconde la fila.');

        $volteo = $this->equipoDeTipo(false);
        $volteo->SERIAL_BRAZO = 'X1';
        $this->assertSame('X1', $volteo->toDetailsPayload()['serialBrazo'],
            'Si el serial ya está puesto se muestra aunque el tipo cambie.');
    }

    public function test_editar_guarda_el_serial_en_mayusculas(): void
    {
        $equipo = $this->equipoDeTipo(true);

        $resp = $this->actingAs($this->editor())
            ->from(route('equipos.edit', $equipo->ID_EQUIPO))
            ->put(route('equipos.update', $equipo->ID_EQUIPO), [
                'TIPO_EQUIPO'      => $equipo->tipo->nombre,
                'CATEGORIA_FLOTA'  => $equipo->CATEGORIA_FLOTA ?: 'FLOTA PESADA',
                'MARCA'            => $equipo->MARCA,
                'MODELO'           => $equipo->MODELO,
                'ANIO'             => $equipo->ANIO,
                'SERIAL_CHASIS'    => $equipo->SERIAL_CHASIS,
                'ESTADO_OPERATIVO' => $equipo->ESTADO_OPERATIVO,
                'SERIAL_BRAZO'     => '  sqz132a240056 ',
            ]);

        $resp->assertSessionHasNoErrors();
        $this->assertSame('SQZ132A240056', $equipo->fresh()->SERIAL_BRAZO);
    }

    public function test_vacio_queda_null(): void
    {
        $equipo = $this->equipoDeTipo(true);
        $equipo->update(['SERIAL_BRAZO' => 'ABC']);

        $this->actingAs($this->editor())
            ->put(route('equipos.update', $equipo->ID_EQUIPO), [
                'TIPO_EQUIPO'      => $equipo->tipo->nombre,
                'CATEGORIA_FLOTA'  => $equipo->CATEGORIA_FLOTA ?: 'FLOTA PESADA',
                'MARCA'            => $equipo->MARCA,
                'MODELO'           => $equipo->MODELO,
                'ANIO'             => $equipo->ANIO,
                'SERIAL_CHASIS'    => $equipo->SERIAL_CHASIS,
                'ESTADO_OPERATIVO' => $equipo->ESTADO_OPERATIVO,
                'SERIAL_BRAZO'     => '   ',
            ])->assertSessionHasNoErrors();

        $this->assertNull($equipo->fresh()->SERIAL_BRAZO);
    }

    public function test_el_formulario_de_edicion_lo_ensena_solo_con_brazo(): void
    {
        $editor = $this->editor();

        $con = $this->actingAs($editor)->get(route('equipos.edit', $this->equipoDeTipo(true)->ID_EQUIPO));
        $con->assertOk()->assertSee('name="SERIAL_BRAZO"', false)
            ->assertSee('id="serialBrazoWrap" data-patron="' . e(TipoEquipo::PATRON_BRAZO) . '" >', false);

        // Sin brazo el campo existe (el tipo se puede cambiar en el mismo formulario) pero oculto.
        $this->actingAs($editor)->get(route('equipos.edit', $this->equipoDeTipo(false)->ID_EQUIPO))
            ->assertOk()->assertSee('name="SERIAL_BRAZO"', false)
            ->assertSee('data-patron="' . e(TipoEquipo::PATRON_BRAZO) . '"  style="display: none;"', false);
    }

    public function test_el_registro_tambien_lo_tiene(): void
    {
        $this->actingAs($this->editor())->get(route('equipos.create'))
            ->assertOk()->assertSee('name="SERIAL_BRAZO"', false);
    }
}
