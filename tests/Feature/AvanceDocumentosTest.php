<?php

namespace Tests\Feature;

use App\Models\VerificacionDocumento;
use Tests\MySqlTestCase;

/**
 * El avance de la revisión ("cuántos hay cargados y cuántos faltan por leer") se saca de UNA
 * consulta por tipo en vez de dos, porque las dos repetían el mismo join y eran ocho para
 * pintar cuatro barras (~58 ms medidos).
 *
 * Lo que este caso vigila es lo único que importa de ese cambio: que siga contando EXACTAMENTE
 * lo mismo. Se compara contra el camino viejo, que sigue existiendo (pendientes() lo usa la
 * tarea nocturna para elegir qué leer), así que la comparación es real y no una copia.
 */
class AvanceDocumentosTest extends MySqlTestCase
{
    public function test_la_cuenta_unida_da_lo_mismo_que_las_dos_por_separado(): void
    {
        $comprobados = 0;

        foreach (VerificacionDocumento::ENLACES as $tipo => $columna) {
            $total  = VerificacionDocumento::conEnlace($columna)->count();
            $faltan = VerificacionDocumento::pendientes($tipo, $columna)->count();

            $unida = VerificacionDocumento::avanceDe($tipo, $columna);

            $this->assertSame($total,  $unida['total'],  "el total de {$tipo} no coincide");
            $this->assertSame($faltan, $unida['faltan'], "los pendientes de {$tipo} no coinciden");
            $this->assertLessThanOrEqual($total, $faltan, "no pueden faltar más de los que hay ({$tipo})");
            $comprobados++;
        }

        $this->assertSame(4, $comprobados, 'se esperan los cuatro tipos de documento');
    }
}
