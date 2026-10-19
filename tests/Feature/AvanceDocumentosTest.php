<?php

namespace Tests\Feature;

use App\Models\Documentacion;
use App\Models\Equipo;
use App\Models\VerificacionDocumento;
use Tests\MySqlTestCase;

/**
 * El avance de la revisión ("cuántos hay cargados y cuántos faltan por leer") sale de UNA
 * consulta para los cuatro tipos (VerificacionDocumento::avances), en vez de una por tipo.
 *
 * Lo que este caso vigila es lo único que importa de ese cambio: que siga contando EXACTAMENTE
 * lo mismo. Se compara contra el camino que usa la tarea nocturna para elegir qué leer
 * (conEnlace() y pendientes()), así que la comparación es real y no una copia.
 */
class AvanceDocumentosTest extends MySqlTestCase
{
    public function test_la_cuenta_unida_da_lo_mismo_que_la_de_la_tarea_nocturna(): void
    {
        // Una póliza en Drive que nadie ha leído: así "faltan" no compara cero con cero.
        $equipo = Equipo::create(['MARCA' => 'PRUEBA', 'MODELO' => 'AVANCE', 'ANIO' => 2026,
            'SERIAL_CHASIS' => 'TESTAV' . strtoupper(uniqid())]);
        Documentacion::create(['ID_EQUIPO' => $equipo->ID_EQUIPO,
            'LINK_POLIZA_SEGURO' => '/storage/google/TESTAVANCE' . uniqid() . '?v=1']);

        $avances = VerificacionDocumento::avances();
        $this->assertSame(array_keys(VerificacionDocumento::ENLACES), array_keys($avances), 'los cuatro tipos, en su orden');

        foreach (VerificacionDocumento::ENLACES as $tipo => $columna) {
            $total  = VerificacionDocumento::conEnlace($columna)->count();
            $faltan = VerificacionDocumento::pendientes($tipo, $columna)->count();

            $this->assertSame($total,  $avances[$tipo]['total'],  "el total de {$tipo} no coincide");
            $this->assertSame($faltan, $avances[$tipo]['faltan'], "los pendientes de {$tipo} no coinciden");
            $this->assertLessThanOrEqual($total, $faltan, "no pueden faltar más de los que hay ({$tipo})");
        }
        $this->assertGreaterThanOrEqual(1, $avances[VerificacionDocumento::POLIZA]['faltan'], 'la póliza sin leer cuenta como pendiente');
    }
}
