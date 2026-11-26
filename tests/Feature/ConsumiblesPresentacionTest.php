<?php

namespace Tests\Feature;

use App\Models\Consumible;
use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Gráficos → Exportar → PowerPoint: los datos de la presentación de gasoil salen del sistema
 * (ConsumiblesController::presentacionData). En un mes de 2031 que no tiene más datos: el total y
 * los proyectos del período, los 6 meses del proyecto elegido y sus 4 equipos de mayor consumo (solo
 * gasoil; el aceite no cuenta). La transacción de cada caso lo revierte todo.
 */
class ConsumiblesPresentacionTest extends MySqlTestCase
{
    private function consumo(string $fecha, float $litros, ?int $frente, ?int $equipo = null, string $tipo = 'GASOIL'): void
    {
        Consumible::create(['FECHA' => $fecha, 'IDENTIFICADOR' => 'PRS' . Str::random(6), 'CANTIDAD' => $litros, 'TIPO_CONSUMIBLE' => $tipo,
            'UNIDAD' => 'LITROS', 'ID_FRENTE' => $frente, 'ID_EQUIPO' => $equipo, 'ESTADO_EQUIPO' => $equipo ? 'CONFIRMADO' : 'SIN_MATCH']);
    }

    public function test_presentacion_con_los_datos_del_sistema(): void
    {
        $marca = strtoupper(Str::random(5));
        $norte = FrenteTrabajo::create(['NOMBRE_FRENTE' => 'PRUEBA PRESENTACION NORTE ' . $marca]);
        $sur = FrenteTrabajo::create(['NOMBRE_FRENTE' => 'PRUEBA PRESENTACION SUR ' . $marca]);
        $tipo = DB::table('tipo_equipos')->value('id');
        $equipos = collect(range(1, 5))->map(fn ($k) => Equipo::create(['MARCA' => 'PRUEBA', 'MODELO' => 'PRS', 'ANIO' => 2020,
            'SERIAL_CHASIS' => 'TSTPRS' . $k . random_int(100000, 999999), 'id_tipo_equipo' => $tipo]));

        foreach ([500, 400, 300, 200, 100] as $k => $l) {
            $this->consumo('2031-06-1' . $k, $l, $norte->ID_FRENTE, $equipos[$k]->ID_EQUIPO);
        }
        $this->consumo('2031-06-20', 250, $sur->ID_FRENTE);
        $this->consumo('2031-06-21', 70, null);                         // sin proyecto: cuenta en el total
        $this->consumo('2031-06-22', 999, $norte->ID_FRENTE, null, 'ACEITE');   // no es gasoil
        $this->consumo('2031-04-05', 800, $norte->ID_FRENTE);         // otro mes: solo en la serie mensual

        $r = $this->actingAs($this->superAdminGlobal())
            ->getJson(route('consumibles.presentacionData', ['desde' => '2031-06-01', 'hasta' => '2031-06-30']))
            ->assertOk()->json();

        $this->assertEquals(1820, $r['total']);
        $this->assertEquals(70, $r['sin_frente']);
        $this->assertSame([$norte->ID_FRENTE, $sur->ID_FRENTE], array_column($r['proyectos'], 'id'));
        $this->assertSame($norte->ID_FRENTE, $r['proyecto']['id']);   // por defecto, el de mayor consumo
        $this->assertSame(['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO'], array_column($r['proyecto']['meses'], 'mes'));
        $this->assertEquals([0, 0, 0, 800, 0, 1500], array_column($r['proyecto']['meses'], 'litros'));
        $this->assertEquals([500, 400, 300, 200], array_column($r['proyecto']['top'], 'litros'));
        $this->assertSame($equipos[0]->SERIAL_CHASIS, $r['proyecto']['top'][0]['id']);   // sin placa, el serial

        // Otro proyecto elegido en el diálogo.
        $r = $this->actingAs($this->superAdminGlobal())
            ->getJson(route('consumibles.presentacionData', ['desde' => '2031-06-01', 'hasta' => '2031-06-30', 'id_proyecto' => $sur->ID_FRENTE]))
            ->assertOk()->json();
        $this->assertSame($sur->ID_FRENTE, $r['proyecto']['id']);
        $this->assertSame([], $r['proyecto']['top']);                  // sus consumos no tienen equipo confirmado
    }

    public function test_pantalla_de_graficos_trae_el_boton_de_exportar(): void
    {
        $html = $this->actingAs($this->superAdminGlobal())->get(route('consumibles.graficos'))->assertOk()->getContent();
        $this->assertStringContainsString('window.consumiblesExportar(', $html);
        $this->assertStringContainsString('consumibles_presentacion.js', $html);
        // Las láminas también se ven en la página, sin descargar el PowerPoint.
        $this->assertStringContainsString('id="presLaminas"', $html);
        $this->assertStringContainsString('window.consumiblesVistaPrevia(', $html);
    }
}
