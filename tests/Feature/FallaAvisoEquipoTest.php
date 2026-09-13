<?php

namespace Tests\Feature;

use App\Models\Equipo;
use Tests\MySqlTestCase;

/**
 * El reporte de falla ABIERTO se ve sobre el estado del equipo en /admin/equipos (aviso al
 * pasar el mouse y cabecera del menú de estado, equipos_index.js): la celda del estado lleva
 * su descripción y su resumen (Falla::resumen), y la respuesta de crear trae ese resumen para
 * pintarlo sin recargar. Todo se revierte.
 */
class FallaAvisoEquipoTest extends MySqlTestCase
{
    private function equipo(): Equipo
    {
        return Equipo::create([
            'MARCA' => 'PRUEBA', 'MODELO' => 'PRUEBA-AVISO', 'ANIO' => 2026,
            'ESTADO_OPERATIVO' => 'OPERATIVO', 'SERIAL_CHASIS' => 'TEST-AVISO-' . uniqid(),
        ]);
    }

    private function filas(Equipo $e): string
    {
        return $this->actingAs($this->superAdminGlobal())
            ->getJson(route('equipos.index', ['search_query' => $e->SERIAL_CHASIS]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json('html');
    }

    public function test_el_estado_del_equipo_trae_la_descripcion_de_su_reporte_abierto(): void
    {
        $admin = $this->superAdminGlobal();
        $e = $this->equipo();
        $this->assertStringNotContainsString('data-falla-desc', $this->filas($e), 'Sin reporte no hay aviso.');

        // Como el modal de Equipos al elegir "Mantenimiento": reporte rápido.
        $falla = $this->actingAs($admin)->postJson(route('fallas.store'), [
            'tipo_reporte' => 'corto', 'activo_tipo' => 'equipo', 'activo_id' => $e->ID_EQUIPO,
            'descripcion' => 'No enciende. Revisar batería.', 'estado_destino' => 'EN MANTENIMIENTO',
        ])->assertOk()->assertJson(['success' => true])->json('falla');

        $resumen = $falla['CODIGO_REPORTE'] . ' · ' . now()->format('d/m/Y') . ' · ' . $admin->NOMBRE_COMPLETO;
        $this->assertSame($resumen, $falla['resumen'], 'La respuesta de crear trae el resumen para la fila.');
        $this->assertSame('EN MANTENIMIENTO', $e->fresh()->ESTADO_OPERATIVO);

        $html = $this->filas($e);
        $this->assertStringContainsString('data-falla-desc="No enciende. Revisar batería."', $html);
        $this->assertStringContainsString('data-falla-resumen="' . e($resumen) . '"', $html);
    }
}
