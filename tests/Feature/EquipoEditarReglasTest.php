<?php

namespace Tests\Feature;

use App\Models\Equipo;
use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * Editar un equipo (EquipoController::update) guarda solo lo del formulario y con las mismas
 * reglas que el resto de la pantalla:
 *   · El ESTATUS no se cambia con un reporte de falla abierto (como el menú de estatus).
 *   · Ubicación, confirmación en sitio y foto no se escriben desde aquí (tienen su flujo).
 *   · Los topes son los de las columnas: pasarlos es un 422 en el campo, no un 500.
 * Y la comprobación en vivo de "ya existe" cuenta los de la Papelera, como el guardado.
 * Todo se revierte al terminar (MySqlTestCase).
 */
class EquipoEditarReglasTest extends MySqlTestCase
{
    private function editor(): Usuario
    {
        $user = Usuario::all()->first(fn ($u) => $u->can('equipos.edit') && $u->can('user.edit'));
        $this->assertNotNull($user, 'No hay usuario con equipos.edit y user.edit.');
        return $user;
    }

    private function datos(Equipo $e, array $cambios = []): array
    {
        return $cambios + [
            'TIPO_EQUIPO'      => $e->tipo->nombre,
            'CATEGORIA_FLOTA'  => $e->CATEGORIA_FLOTA ?: 'FLOTA PESADA',
            'MARCA'            => $e->MARCA,
            'MODELO'           => $e->MODELO,
            'ANIO'             => $e->ANIO,
            'SERIAL_CHASIS'    => $e->SERIAL_CHASIS,
            'ESTADO_OPERATIVO' => $e->ESTADO_OPERATIVO,
        ];
    }

    private function equipoNormal(): Equipo
    {
        $e = Equipo::with('tipo')->whereNotNull('id_tipo_equipo')->whereBetween('ANIO', [1900, 2100])
            ->whereDoesntHave('fallaAbierta')->first();
        $this->assertNotNull($e);
        return $e;
    }

    public function test_con_reporte_abierto_editar_no_cambia_el_estatus(): void
    {
        $e = Equipo::with('tipo')->whereHas('fallaAbierta')->whereBetween('ANIO', [1900, 2100])->first();
        if (!$e) {
            $this->markTestSkipped('No hay equipos con reporte de falla abierto en la base.');
        }
        $antes = $e->ESTADO_OPERATIVO;
        $otro  = $antes === 'OPERATIVO' ? 'INOPERATIVO' : 'OPERATIVO';

        $this->actingAs($this->editor())
            ->putJson(route('equipos.update', $e->ID_EQUIPO), $this->datos($e, ['ESTADO_OPERATIVO' => $otro]))
            ->assertStatus(422)->assertJsonValidationErrors('ESTADO_OPERATIVO');
        $this->assertSame($antes, $e->fresh()->ESTADO_OPERATIVO);
    }

    public function test_editar_no_escribe_ubicacion_ni_confirmacion_ni_foto(): void
    {
        $e = $this->equipoNormal();
        $antes = $e->only(['CONFIRMADO_EN_SITIO', 'DETALLE_UBICACION_ACTUAL', 'FOTO_EQUIPO']);

        $this->actingAs($this->editor())
            ->putJson(route('equipos.update', $e->ID_EQUIPO), $this->datos($e, [
                'CONFIRMADO_EN_SITIO' => $antes['CONFIRMADO_EN_SITIO'] ? 0 : 1,
                'DETALLE_UBICACION_ACTUAL' => 'PUESTO A MANO',
                'FOTO_EQUIPO' => '/storage/google/falso',
            ]))->assertOk();
        $this->assertSame($antes, $e->fresh()->only(array_keys($antes)));
    }

    public function test_los_topes_de_la_base_son_un_422_en_el_campo(): void
    {
        $e = $this->equipoNormal();
        $this->actingAs($this->editor())
            ->putJson(route('equipos.update', $e->ID_EQUIPO), $this->datos($e, [
                'MODELO' => str_repeat('X', 51), 'ANIO' => 99999, 'ESTADO_OPERATIVO' => 'X',
            ]))
            ->assertStatus(422)->assertJsonValidationErrors(['MODELO', 'ANIO', 'ESTADO_OPERATIVO']);
    }

    public function test_ya_existe_cuenta_los_de_la_papelera(): void
    {
        $borrado = Equipo::onlyTrashed()->whereNotNull('SERIAL_CHASIS')->first();
        if (!$borrado) {
            $borrado = $this->equipoNormal();
            $borrado->delete();
        }
        $this->actingAs($this->editor())
            ->getJson(route('equipos.checkUnique', ['field' => 'SERIAL_CHASIS', 'value' => $borrado->SERIAL_CHASIS]))
            ->assertOk()->assertJson(['exists' => true]);
    }
}
