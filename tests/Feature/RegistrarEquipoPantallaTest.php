<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * La pantalla Registrar equipo (/admin/equipos/create) y lo que el servidor espera de ella:
 *   · Combustible y consumo se guardan también al registrar un AUXILIAR (las dos tablas
 *     tienen la columna; antes la pantalla los apagaba en ese modo).
 *   · Las reglas que la revisión sin internet (equipos_alta_sin_red.js) repite: año fuera
 *     de rango y fecha de certificado sin su PDF son un 422, igual que lo avisa la pantalla.
 *   · La carga masiva numera los errores por POSICIÓN en la lista enviada (la pantalla
 *     los pinta en la fila N de la tabla, aunque antes se hayan borrado filas).
 * Todo se revierte al terminar (MySqlTestCase).
 */
class RegistrarEquipoPantallaTest extends MySqlTestCase
{
    private function usuario(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('equipos.create') && $u->can('equipos.edit') && $u->veTodosLosFrentesEquipos());
        $this->assertNotNull($u, 'Hace falta un usuario con equipos.create/edit que vea todos los frentes.');
        return $u;
    }

    private function frente(): object
    {
        return DB::table('frentes_trabajo')->whereNull('deleted_at')->where('ESTATUS_FRENTE', 'ACTIVO')->first();
    }

    private function auxiliar(array $cambios = []): array
    {
        return $cambios + [
            '__modo' => 'auxiliar', 'TIPO' => 'COMPRESOR', 'MARCA' => 'PRUEBA', 'MODELO' => 'M1',
            'SERIAL' => 'PANT-' . strtoupper(Str::random(10)), 'ESTADO_OPERATIVO' => 'OPERATIVO',
            'ID_FRENTE_ACTUAL' => $this->frente()->ID_FRENTE,
        ];
    }

    public function test_la_pantalla_abre_con_los_topes_y_el_combustible_vaciable(): void
    {
        $html = $this->actingAs($this->usuario())->get(route('equipos.create'))->assertOk()->getContent();
        $this->assertStringContainsString('SIN ESPECIFICAR', $html);
        $this->assertMatchesRegularExpression('/id="marca"[^>]*maxlength="50"/s', $html);
        $this->assertMatchesRegularExpression('/id="modelo"[^>]*maxlength="50"/s', $html);
        $this->assertMatchesRegularExpression('/id="input_tipo_aux"[^>]*maxlength="60"/s', $html);
    }

    public function test_el_auxiliar_guarda_combustible_y_consumo(): void
    {
        $datos = $this->auxiliar(['COMBUSTIBLE' => 'GASOIL', 'CONSUMO_PROMEDIO' => '12.5']);
        $this->actingAs($this->usuario())->postJson(route('equipos.storeUnified'), $datos)->assertOk();

        $aux = DB::table('equipos_auxiliares')->where('SERIAL', $datos['SERIAL'])->first();
        $this->assertNotNull($aux);
        $this->assertSame('GASOIL', $aux->COMBUSTIBLE);
        $this->assertEquals(12.5, (float) $aux->CONSUMO_PROMEDIO);
    }

    public function test_lo_que_avisa_la_revision_sin_red_tambien_lo_rechaza_el_servidor(): void
    {
        $u = $this->usuario();
        $this->actingAs($u)->postJson(route('equipos.storeUnified'), $this->auxiliar(['ANIO' => 1949]))
            ->assertStatus(422)->assertJsonValidationErrors('ANIO');
        $this->actingAs($u)->postJson(route('equipos.storeUnified'), $this->auxiliar(['fecha_vencimiento_cert' => now()->addYear()->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors('fecha_vencimiento_cert');
        $this->actingAs($u)->postJson(route('equipos.storeUnified'), [
            '__modo' => 'equipo', 'CATEGORIA_FLOTA' => 'FLOTA LIVIANA', 'TIPO_EQUIPO' => DB::table('tipo_equipos')->value('nombre'),
            'MARCA' => 'PRUEBA', 'MODELO' => 'M1', 'ANIO' => 1899,
            'SERIAL_CHASIS' => 'PANT-' . strtoupper(Str::random(10)), 'ESTADO_OPERATIVO' => 'OPERATIVO',
        ])->assertStatus(422)->assertJsonValidationErrors('ANIO');
    }

    public function test_la_carga_masiva_numera_los_errores_por_posicion(): void
    {
        $ocupado = DB::table('equipos')->whereNotNull('SERIAL_CHASIS')->where('SERIAL_CHASIS', '!=', '')->value('SERIAL_CHASIS');
        $fila = fn (string $serial) => [
            'tipo_equipo' => DB::table('tipo_equipos')->value('nombre'), 'categoria_flota' => 'FLOTA LIVIANA',
            'marca' => 'PRUEBA', 'modelo' => 'M1', 'anio' => '2020', 'serial_chasis' => $serial,
            'frente_trabajo' => $this->frente()->NOMBRE_FRENTE, 'status' => 'OPERATIVO',
        ];

        $r = $this->actingAs($this->usuario())->postJson(route('equipos.bulkStoreBatch'), ['rows' => [
            $fila('PANT-' . strtoupper(Str::random(10))),
            $fila($ocupado),
        ]])->assertStatus(422);

        $errores = $r->json('errors');
        $this->assertArrayNotHasKey('0', $errores, 'la fila buena no lleva error');
        $this->assertArrayHasKey('serial_chasis', $errores['1'] ?? [], 'el error va en la 2.ª fila enviada');
    }
}
