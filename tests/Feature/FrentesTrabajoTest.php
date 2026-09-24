<?php

namespace Tests\Feature;

use App\Models\FrenteTrabajo;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Módulo "Frentes de trabajo" (/admin/frentes): alta, edición, los dos guardas que
 * protegen la integridad (no finalizar ni borrar un frente con equipos encima) y el
 * trato de la columna CONTRATOS, que es la que se perdía al editar desde el buscador.
 * Todo corre dentro de la transacción de la prueba y se revierte al terminar.
 */
class FrentesTrabajoTest extends MySqlTestCase
{
    /**
     * El helper de la clase base ya descarta a quien tiene la clave pendiente de
     * cambiar: con REQUIERE_CAMBIO_CLAVE = 1, EnsurePasswordChanged redirige TODA
     * petición a /admin y la prueba fallaría con un 302 según a qué usuario real
     * de la base le tocara salir.
     */
    private function superAdmin(): Usuario
    {
        return $this->superAdminGlobal();
    }

    /** Frente recién creado, sin equipos encima. */
    private function frente(array $extra = []): FrenteTrabajo
    {
        return FrenteTrabajo::create(array_merge([
            'NOMBRE_FRENTE'  => 'FRENTE PRUEBA ' . strtoupper(Str::random(8)),
            'UBICACION'      => 'EL TIGRE',
            'TIPO_FRENTE'    => 'OPERACION',
            'ESTATUS_FRENTE' => 'ACTIVO',
        ], $extra));
    }

    /** Campos que el formulario manda SIEMPRE, para no disparar validaciones ajenas. */
    private function payload(FrenteTrabajo $f, array $cambios = []): array
    {
        return array_merge([
            'NOMBRE_FRENTE'  => $f->NOMBRE_FRENTE,
            'UBICACION'      => $f->UBICACION,
            'ZONA'           => $f->ZONA,
            'TIPO_FRENTE'    => $f->TIPO_FRENTE,
            'ESTATUS_FRENTE' => $f->ESTATUS_FRENTE,
            'CONTRATOS'      => is_array($f->CONTRATOS) ? implode(',', $f->CONTRATOS) : '',
        ], $cambios);
    }

    private function equipoEn(int $idFrente): int
    {
        return DB::table('equipos')->insertGetId([
            'MARCA' => 'TOYOTA', 'MODELO' => 'HILUX', 'ANIO' => 2020,
            'SERIAL_CHASIS' => 'S' . Str::random(12),
            'ID_FRENTE_ACTUAL' => $idFrente,
        ]);
    }

    public function test_editar_conserva_zona_y_contratos_cuando_el_formulario_los_manda(): void
    {
        $f = $this->frente(['ZONA' => 'MATURIN', 'CONTRATOS' => ['CTR-1', 'CTR-2']]);

        $r = $this->actingAs($this->superAdmin())
            ->putJson("/admin/frentes/{$f->ID_FRENTE}?json=true", $this->payload($f, ['UBICACION' => 'ANACO']));

        $r->assertOk()->assertJson(['success' => true]);
        $f->refresh();
        $this->assertSame('ANACO', $f->UBICACION);
        $this->assertSame('MATURIN', $f->ZONA, 'La zona (sale en el Acta de Traslado) no debe perderse al editar.');
        $this->assertSame(['CTR-1', 'CTR-2'], $f->CONTRATOS);
    }

    /**
     * Si la petición NO trae la clave CONTRATOS, el request la deja fuera del merge a
     * propósito para no vaciar la columna (ver FrenteRequest::prepareForValidation).
     */
    public function test_editar_sin_mandar_contratos_no_los_borra(): void
    {
        $f = $this->frente(['CONTRATOS' => ['CTR-9']]);
        $datos = $this->payload($f);
        unset($datos['CONTRATOS']);

        $this->actingAs($this->superAdmin())
            ->putJson("/admin/frentes/{$f->ID_FRENTE}?json=true", $datos)
            ->assertOk();

        $this->assertSame(['CTR-9'], $f->refresh()->CONTRATOS);
    }

    public function test_los_contratos_se_guardan_en_mayusculas_y_sin_repetidos(): void
    {
        $f = $this->frente();

        $this->actingAs($this->superAdmin())
            ->putJson("/admin/frentes/{$f->ID_FRENTE}?json=true",
                $this->payload($f, ['CONTRATOS' => 'ctr-a, ctr-b ;CTR-A']))
            ->assertOk();

        $this->assertSame(['CTR-A', 'CTR-B'], $f->refresh()->CONTRATOS);
    }

    public function test_no_deja_finalizar_un_frente_con_equipos_asignados(): void
    {
        $f = $this->frente();
        $this->equipoEn($f->ID_FRENTE);

        $this->actingAs($this->superAdmin())
            ->putJson("/admin/frentes/{$f->ID_FRENTE}?json=true", $this->payload($f, ['ESTATUS_FRENTE' => 'FINALIZADO']))
            ->assertStatus(422)
            ->assertJson(['success' => false, 'equipos_asignados' => 1]);

        $this->assertSame('ACTIVO', $f->refresh()->ESTATUS_FRENTE);
    }

    public function test_no_deja_eliminar_un_frente_con_equipos_asignados(): void
    {
        $f = $this->frente();
        $this->equipoEn($f->ID_FRENTE);

        $this->actingAs($this->superAdmin())
            ->deleteJson("/admin/frentes/{$f->ID_FRENTE}?json=true")
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertNotNull(FrenteTrabajo::find($f->ID_FRENTE));
    }

    public function test_elimina_el_frente_vacio_y_lo_lista_antes_en_sin_equipos(): void
    {
        $f = $this->frente();
        $admin = $this->superAdmin();

        $lista = $this->actingAs($admin)->getJson('/admin/frentes/sin-equipos');
        $lista->assertOk();
        $this->assertContains($f->ID_FRENTE, array_column($lista->json('frentes'), 'id'),
            'Un frente sin equipos debe salir en la lista de candidatos a eliminar.');

        $this->actingAs($admin)
            ->deleteJson("/admin/frentes/{$f->ID_FRENTE}?json=true")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNull(FrenteTrabajo::find($f->ID_FRENTE));
    }

    public function test_no_admite_dos_frentes_con_el_mismo_nombre(): void
    {
        $f = $this->frente();

        $this->actingAs($this->superAdmin())
            ->postJson('/admin/frentes?json=true', $this->payload($f))
            ->assertStatus(422)
            ->assertJsonValidationErrors('NOMBRE_FRENTE');
    }

    public function test_la_raiz_del_modulo_lleva_al_formulario(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/admin/frentes')
            ->assertRedirect(route('frentes.create'));
    }

    /**
     * El menú apunta al formulario DIRECTO (no a /admin/frentes, que solo redirige):
     * ese salto de más cuesta un viaje entero de red en obra. Y solo se pinta con la
     * clave super.admin — ni en escritorio ni en teléfono.
     */
    public function test_el_menu_enlaza_al_formulario_y_solo_para_super_admin(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/menu')
            ->assertOk()
            ->assertSee(route('frentes.create'), false)
            ->assertDontSee('href="' . route('frentes.index') . '"', false);

        // Mismo cuidado con REQUIERE_CAMBIO_CLAVE: si sale uno con la clave pendiente,
        // /menu redirige a cambiarla y el assertOk falla sin que nada esté roto.
        $sinClave = Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->where('ESTATUS', 'ACTIVO')->get()
            ->first(fn ($u) => ! $u->can('super.admin'));
        $this->assertNotNull($sinClave, 'Hace falta un usuario activo SIN super.admin.');

        $this->actingAs($sinClave)
            ->get('/menu')
            ->assertOk()
            ->assertDontSee(route('frentes.create'), false)
            ->assertSee('No tienes permiso para acceder a Frentes de trabajo.', false);
    }
}
