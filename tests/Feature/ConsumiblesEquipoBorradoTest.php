<?php

namespace Tests\Feature;

use App\Models\Consumible;
use App\Models\Equipo;
use App\Models\EquipoAuxiliar;
use Tests\MySqlTestCase;

/**
 * Borrar para siempre (desde la papelera) un equipo o un auxiliar deja sus despachos sin equipo:
 * tienen que volver a «Por identificar», no quedarse «confirmados» sin equipo (contaban como
 * confirmados y no salían en ningún gráfico). La transacción de cada caso lo revierte todo.
 */
class ConsumiblesEquipoBorradoTest extends MySqlTestCase
{
    private function despacho(array $de): Consumible
    {
        return Consumible::create($de + [
            'FECHA' => '2026-11-24', 'IDENTIFICADOR' => 'PRUEBA-BORRADO', 'CANTIDAD' => 10,
            'TIPO_CONSUMIBLE' => 'GASOIL', 'UNIDAD' => 'LITROS', 'ESTADO_EQUIPO' => 'CONFIRMADO',
        ]);
    }

    public function test_equipo_borrado_para_siempre(): void
    {
        $equipo = Equipo::create(['MARCA' => 'PRUEBA', 'MODELO' => 'BORRADO', 'ANIO' => 2026,
            'SERIAL_CHASIS' => 'TESTBOR' . strtoupper(uniqid())]);
        $c = $this->despacho(['ID_EQUIPO' => $equipo->ID_EQUIPO]);
        $equipo->delete();   // a la papelera: el despacho sigue con su equipo

        $this->assertSame('CONFIRMADO', $c->fresh()->ESTADO_EQUIPO);
        $this->actingAs($this->superAdminGlobal())->deleteJson(route('equipos.forceDelete', $equipo->ID_EQUIPO))->assertOk();

        $c->refresh();
        $this->assertNull($c->ID_EQUIPO);
        $this->assertSame('SIN_MATCH', $c->ESTADO_EQUIPO);
        $this->assertSame(10.0, (float) $c->CANTIDAD);   // el despacho no se pierde
    }

    public function test_auxiliar_borrado_para_siempre(): void
    {
        $aux = EquipoAuxiliar::create(['TIPO' => 'CISTERNA', 'MARCA' => 'PRUEBA', 'MODELO' => 'BORRADO',
            'SERIAL' => 'AUXBOR' . random_int(100000, 999999)]);
        $c = $this->despacho(['ID_AUXILIAR' => $aux->ID_AUXILIAR]);
        $aux->delete();

        $this->actingAs($this->superAdminGlobal())->deleteJson(route('equipos-auxiliares.forceDelete', $aux->ID_AUXILIAR))->assertOk();

        $c->refresh();
        $this->assertNull($c->ID_AUXILIAR);
        $this->assertSame('SIN_MATCH', $c->ESTADO_EQUIPO);
    }
}
