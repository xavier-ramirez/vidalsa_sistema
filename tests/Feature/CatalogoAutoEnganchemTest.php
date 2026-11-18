<?php

namespace Tests\Feature;

use App\Models\CaracteristicaModelo;
use App\Models\Equipo;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * El enganche automático de equipos a su ficha de catálogo.
 *
 * Cada ficha engancha solo las unidades de SU TIPO (mismo modelo y año puede ser soldadura,
 * cuadrillera, plataforma…). REGLA QUE FIJA ESTA PRUEBA: cuando un modelo+año+tipo tiene MÁS
 * DE UNA ficha, no se engancha nada. Varias fichas del mismo modelo existen para separar unidades con fotos distintas
 * (hoy, distinto color), y cuál le toca a cada una lo decidió una persona a mano: la
 * aplicación no guarda ese dato y no puede adivinarlo.
 *
 * Sin el candado, guardar UNA ficha se llevaba todas las unidades sueltas de ese modelo y
 * las sacaba con la misma foto, borrando en silencio el trabajo de separarlas.
 *
 * Con UNA sola ficha no hay ambigüedad y el enganche automático debe seguir funcionando:
 * eso también se comprueba aquí, porque un candado que apague de más sería igual de malo.
 *
 * DatabaseTransactions revierte todo al terminar (ver MySqlTestCase): estas pruebas crean
 * fichas y equipos de mentira y no dejan rastro en la base real.
 */
class CatalogoAutoEnganchemTest extends MySqlTestCase
{
    /** Invoca el método privado: es lógica interna del controlador, no una ruta. */
    private function autoEnganchar(CaracteristicaModelo $ficha): void
    {
        $ctrl = new \App\Http\Controllers\CaracteristicaModeloController();
        $m = new \ReflectionMethod($ctrl, 'autoLinkEquiposToCatalogo');
        $m->setAccessible(true);
        $m->invoke($ctrl, $ficha, $ficha->MODELO, $ficha->ANIO_ESPEC, 'update');
    }

    private function ficha(string $modelo, int $anio, ?string $foto = 'x.webp', string $tipo = 'CAMIONETA'): CaracteristicaModelo
    {
        return CaracteristicaModelo::create([
            'MODELO' => $modelo, 'TIPO' => $tipo, 'ANIO_ESPEC' => $anio,
            'FOTO_REFERENCIAL' => $foto,
        ]);
    }

    /** Equipo mínimo del modelo indicado, sin ficha enganchada. */
    private function equipoSuelto(string $modelo, int $anio, ?string $tipo = 'CAMIONETA'): Equipo
    {
        // MARCA, MODELO, ANIO y SERIAL_CHASIS son NOT NULL sin valor por defecto.
        $eq = new Equipo();
        $eq->MARCA = 'PRUEBA';
        $eq->MODELO = $modelo;
        $eq->ANIO = $anio;
        $eq->id_tipo_equipo = $tipo !== null ? \App\Models\TipoEquipo::firstOrCreate(['nombre' => $tipo])->id : null;
        $eq->ID_ESPEC = null;
        $eq->SERIAL_CHASIS = 'PRUEBA-' . uniqid();
        $eq->save();

        return $eq;
    }

    public function test_con_UNA_ficha_el_enganche_automatico_sigue_funcionando(): void
    {
        $modelo = 'PRUEBA-UNICA-' . uniqid();
        $ficha  = $this->ficha($modelo, 2026);
        $eq     = $this->equipoSuelto($modelo, 2026);

        $this->autoEnganchar($ficha);

        $this->assertSame($ficha->ID_ESPEC, $eq->fresh()->ID_ESPEC,
            'Con una sola ficha no hay ambigüedad: el equipo suelto debía engancharse.');
    }

    public function test_con_VARIAS_fichas_no_engancha_nada(): void
    {
        $modelo = 'PRUEBA-VARIAS-' . uniqid();
        $roja   = $this->ficha($modelo, 2026);
        $negra  = $this->ficha($modelo, 2026);   // mismo modelo+año, otra foto
        $eq     = $this->equipoSuelto($modelo, 2026);

        $this->autoEnganchar($roja);
        $this->assertNull($eq->fresh()->ID_ESPEC,
            'Con dos fichas no se puede saber el color: NO debía engancharse a ninguna.');

        $this->autoEnganchar($negra);
        $this->assertNull($eq->fresh()->ID_ESPEC,
            'Guardar la otra ficha tampoco debe absorberlo.');
    }

    /**
     * Mismo modelo y año, distinto vehículo (F-350 2011: soldadura y cuadrillera): cada tipo
     * lleva su ficha y su foto. La de soldadura no se lleva a la cuadrillera, y la
     * cuadrillera puede tener la suya sin que la soldadura cambie.
     */
    public function test_cada_tipo_del_mismo_modelo_lleva_su_ficha(): void
    {
        $modelo      = 'PRUEBA-TIPOS-' . uniqid();
        $soldadura   = $this->ficha($modelo, 2011, 'soldadura.webp', 'PRUEBA SOLDADURA');
        $deSoldadura = $this->equipoSuelto($modelo, 2011, 'PRUEBA SOLDADURA');
        $cuadrillera = $this->equipoSuelto($modelo, 2011, 'PRUEBA CUADRILLERA');

        $this->autoEnganchar($soldadura);
        $this->assertSame($soldadura->ID_ESPEC, $deSoldadura->fresh()->ID_ESPEC);
        $this->assertNull($cuadrillera->fresh()->ID_ESPEC, 'La cuadrillera no toma la ficha (ni la foto) de soldadura.');

        // El modal de Equipos le ofrece crear la de SU tipo.
        $admin  = $this->superAdminGlobal();
        $crear  = $this->actingAs($admin)->getJson(route('catalogo.elegir', ['equipo' => $cuadrillera->ID_EQUIPO]))
            ->assertOk()->json('crear');
        $this->assertSame('PRUEBA CUADRILLERA', $crear['tipo'] ?? null);
        $this->assertNull($this->actingAs($admin)->getJson(route('catalogo.elegir', ['equipo' => $deSoldadura->ID_EQUIPO]))
            ->json('crear'), 'La de soldadura ya tiene la suya.');

        // Crearla engancha solo a la cuadrillera; la de soldadura y su foto quedan igual.
        $r = $this->actingAs($admin)->postJson(route('catalogo.asegurarFicha'), ['modelo' => $modelo, 'anio' => 2011, 'tipo' => 'PRUEBA CUADRILLERA'])
            ->assertOk()->assertJson(['creada' => true]);
        $this->assertNotSame($soldadura->ID_ESPEC, $r->json('id'));
        $this->assertSame([$cuadrillera->ID_EQUIPO], $r->json('enlazados'));
        $this->assertSame($r->json('id'), $cuadrillera->fresh()->ID_ESPEC);
        $this->assertSame($soldadura->ID_ESPEC, $deSoldadura->fresh()->ID_ESPEC);
        $this->assertSame('soldadura.webp', $soldadura->fresh()->FOTO_REFERENCIAL);

        // Con las dos fichas, guardar la de soldadura sigue enganchando a los suyos.
        $otroSoldadura = $this->equipoSuelto($modelo, 2011, 'PRUEBA SOLDADURA');
        $this->autoEnganchar($soldadura);
        $this->assertSame($soldadura->ID_ESPEC, $otroSoldadura->fresh()->ID_ESPEC);
    }

    /**
     * Sin tipo de un lado (la unidad o la ficha) y una sola ficha del modelo + año: no hay
     * nada que confundir y se engancha, como antes. Con varias fichas de tipos distintos, la
     * unidad sin tipo no se adivina; y la tarjeta SIN FICHA dice lo mismo que el enganche.
     */
    public function test_sin_tipo_engancha_solo_si_no_hay_nada_que_confundir(): void
    {
        $modelo = 'PRUEBA-SINTIPO-' . uniqid();
        $ficha  = $this->ficha($modelo, 2020, 'x.webp', 'PRUEBA SOLDADURA');
        $suelto = $this->equipoSuelto($modelo, 2020, null);
        $this->autoEnganchar($ficha);
        $this->assertSame($ficha->ID_ESPEC, $suelto->fresh()->ID_ESPEC, 'Una sola ficha: la unidad sin tipo va a ella.');

        $modelo2 = 'PRUEBA-SINTIPO2-' . uniqid();
        $a = $this->ficha($modelo2, 2020, 'a.webp', 'PRUEBA SOLDADURA');
        $this->ficha($modelo2, 2020, 'b.webp', 'PRUEBA CUADRILLERA');
        $otro = $this->equipoSuelto($modelo2, 2020, null);
        $this->autoEnganchar($a);
        $this->assertNull($otro->fresh()->ID_ESPEC, 'Con dos tipos no se adivina cuál es.');

        // La tarjeta SIN FICHA de esa unidad ofrece crear (no "enlazar" a la de otro tipo) y
        // crearla la engancha de verdad: el botón no se queda sin efecto.
        $admin = $this->superAdminGlobal();
        $html = $this->actingAs($admin)->getJson(route('catalogo.index', ['ajax_load' => 1, 'modelo' => 'modelo_eq:' . $modelo2]))
            ->assertOk()->json('html');
        $this->assertStringContainsString('Crear ficha', $html);
        $r = $this->actingAs($admin)->postJson(route('catalogo.asegurarFicha'), ['modelo' => $modelo2, 'anio' => 2020])
            ->assertOk()->assertJson(['creada' => true]);
        $this->assertSame($r->json('id'), $otro->fresh()->ID_ESPEC);
        $this->assertSame('a.webp', $a->fresh()->FOTO_REFERENCIAL, 'La foto de la otra ficha no se toca.');
    }

    /** El caso real: la asignación hecha a mano no se puede tocar. */
    public function test_no_reasigna_lo_que_ya_se_separo_a_mano(): void
    {
        $modelo = 'PRUEBA-MANUAL-' . uniqid();
        $roja   = $this->ficha($modelo, 2026);
        $negra  = $this->ficha($modelo, 2026);

        $eq = $this->equipoSuelto($modelo, 2026);
        $eq->ID_ESPEC = $negra->ID_ESPEC;        // alguien lo puso en la negra a mano
        $eq->save();

        $this->autoEnganchar($roja);             // se edita la ficha roja

        $this->assertSame($negra->ID_ESPEC, $eq->fresh()->ID_ESPEC,
            'Editar la ficha roja no puede llevarse un equipo que estaba en la negra.');
    }

    /** Los modelos de una sola ficha son la inmensa mayoría: el candado no debe tocarlos. */
    public function test_el_candado_solo_afecta_a_los_modelos_con_varias_fichas(): void
    {
        $conVarias = DB::table('caracteristicas_modelo')
            ->select('MODELO', 'ANIO_ESPEC')
            ->groupBy('MODELO', 'ANIO_ESPEC')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $total = DB::table('caracteristicas_modelo')
            ->select('MODELO', 'ANIO_ESPEC')
            ->groupBy('MODELO', 'ANIO_ESPEC')
            ->get()
            ->count();

        $this->assertLessThan($total, $conVarias,
            'Si TODOS los modelos tuvieran varias fichas, el candado apagaría el enganche entero.');
    }
}
