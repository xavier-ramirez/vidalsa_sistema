<?php

namespace Tests\Feature;

use App\Models\Documentacion;
use App\Models\Equipo;
use App\Models\EquipoAuxiliar;
use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * La papelera (Historial de documentos) da los documentos CARGADOS de lo borrado, para verlos
 * sin restaurarlo: [{tipo, nombre, link}], en el orden de los filtros. Lo vacio no sale.
 */
class PapeleraDocumentosTest extends MySqlTestCase
{
    /** Un usuario que puede abrir las dos papeleras (la de auxiliares exige user.delete). */
    private function usuario(): Usuario
    {
        $u = Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->whereNotNull('PERMISOS')->get()
            ->first(fn ($usr) => count(array_intersect(['super.admin', 'user.delete'], array_map('strtolower', $usr->PERMISOS))) === 2);
        $this->assertNotNull($u, 'hace falta un super.admin activo con user.delete');

        return $u;
    }

    private function fila(string $url, $id): ?array
    {
        return collect($this->actingAs($this->usuario())->getJson($url)->assertOk()->json('items'))
            ->firstWhere('id', $id);
    }

    public function test_el_vehiculo_borrado_trae_sus_documentos_cargados(): void
    {
        $con = Equipo::create(['MARCA' => 'PRUEBA', 'MODELO' => 'PAPELERA', 'ANIO' => 2026, 'SERIAL_CHASIS' => 'TEST-PAP-' . uniqid()]);
        Documentacion::create([
            'ID_EQUIPO' => $con->ID_EQUIPO, 'PLACA' => 'A00PAP1',
            'LINK_DOC_PROPIEDAD' => '/storage/google/titulo?v=1',
            'LINK_ROTC' => '/storage/google/rotc?v=1',
            'LINK_DOC_ADICIONAL_2' => '/storage/google/compraventa?v=1',
            'LINK_POLIZA_SEGURO' => '   ',   // en blanco = no cargado
        ]);
        $sin = Equipo::create(['MARCA' => 'PRUEBA', 'MODELO' => 'PAPELERA', 'ANIO' => 2026, 'SERIAL_CHASIS' => 'TEST-PAP-' . uniqid()]);
        $con->delete();
        $sin->delete();

        $fila = $this->fila('/admin/equipos/papelera', $con->ID_EQUIPO);
        $this->assertSame('A00PAP1', $fila['placa']);
        $this->assertSame([
            ['tipo' => 'propiedad',   'nombre' => 'Propiedad',   'link' => '/storage/google/titulo?v=1'],
            ['tipo' => 'rotc',        'nombre' => 'ROTC',        'link' => '/storage/google/rotc?v=1'],
            ['tipo' => 'adicional_2', 'nombre' => 'Compraventa', 'link' => '/storage/google/compraventa?v=1'],
        ], $fila['documentos']);
        $this->assertSame([], $this->fila('/admin/equipos/papelera', $sin->ID_EQUIPO)['documentos']);
    }

    public function test_el_auxiliar_borrado_trae_sus_documentos_cargados(): void
    {
        $nuevo = fn (array $campos = []) => EquipoAuxiliar::create([
            'TIPO' => 'PRUEBA', 'MARCA' => 'PRUEBA', 'MODELO' => 'PAPELERA-AUX',
            'SERIAL' => 'TESTPAP' . strtoupper(substr(uniqid(), -9)),
        ] + $campos);
        $con = $nuevo(['LINK_DOC_PROPIEDAD' => '/storage/google/titulo-aux?v=1', 'LINK_CERTIFICADO' => '/storage/google/cert-aux?v=1']);
        $sin = $nuevo();
        $con->delete();
        $sin->delete();

        $this->assertSame([
            ['tipo' => 'propiedad',   'nombre' => 'Propiedad',   'link' => '/storage/google/titulo-aux?v=1'],
            ['tipo' => 'certificado', 'nombre' => 'Certificado', 'link' => '/storage/google/cert-aux?v=1'],
        ], $this->fila('/admin/equipos-auxiliares/papelera', $con->ID_AUXILIAR)['documentos']);
        $this->assertSame([], $this->fila('/admin/equipos-auxiliares/papelera', $sin->ID_AUXILIAR)['documentos']);
    }
}
