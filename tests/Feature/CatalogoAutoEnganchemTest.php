<?php

namespace Tests\Feature;

use App\Models\CaracteristicaModelo;
use App\Models\Equipo;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * El enganche automático de equipos a su ficha de catálogo.
 *
 * REGLA QUE FIJA ESTA PRUEBA: cuando un modelo+año tiene MÁS DE UNA ficha, no se engancha
 * nada. Varias fichas del mismo modelo existen para separar unidades con fotos distintas
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

    private function ficha(string $modelo, int $anio, ?string $foto = 'x.webp'): CaracteristicaModelo
    {
        return CaracteristicaModelo::create([
            'MODELO' => $modelo, 'TIPO' => 'CAMIONETA', 'ANIO_ESPEC' => $anio,
            'FOTO_REFERENCIAL' => $foto,
        ]);
    }

    /** Equipo mínimo del modelo indicado, sin ficha enganchada. */
    private function equipoSuelto(string $modelo, int $anio): Equipo
    {
        // MARCA, MODELO, ANIO y SERIAL_CHASIS son NOT NULL sin valor por defecto.
        $eq = new Equipo();
        $eq->MARCA = 'PRUEBA';
        $eq->MODELO = $modelo;
        $eq->ANIO = $anio;
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
