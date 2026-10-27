<?php

namespace Tests\Feature;

use App\Models\Documentacion;
use App\Models\Equipo;
use Tests\EquiposLocal;
use Tests\MySqlTestCase;

/**
 * Excel del listado de /admin/equipos: las columnas de documentos llevan el número del
 * título y la fecha de EMISIÓN de cada documento junto a su vencimiento, cada valor bajo
 * su encabezado (las letras se calculan; si se corrieran, el archivo abriría igual). La pantalla
 * manda los ids que filtró (equipos_local.js) y el servidor arma el libro.
 */
class ExportEquiposDocumentosTest extends MySqlTestCase
{
    use EquiposLocal;

    public function test_el_excel_trae_numero_del_titulo_y_las_emisiones(): void
    {
        $serial = 'TESTXLS' . strtoupper(substr(uniqid(), -9));
        $equipo = Equipo::create(['MARCA' => 'PRUEBA', 'MODELO' => 'EXCEL', 'ANIO' => 2026, 'SERIAL_CHASIS' => $serial]);
        Documentacion::create([
            'ID_EQUIPO' => $equipo->ID_EQUIPO,
            'NRO_DE_DOCUMENTO' => '260110691985', 'NOMBRE_DEL_TITULAR' => 'Constructora Vidalsa 27, C.A',
            'LINK_DOC_PROPIEDAD' => '/storage/google/titulo', 'FECHA_EMISION_PROPIEDAD' => '2025-03-04',
            'LINK_POLIZA_SEGURO' => '/storage/google/poliza', 'FECHA_EMISION_POLIZA' => '2026-06-09', 'FECHA_VENC_POLIZA' => '2027-06-09',
            'LINK_RACDA' => '/storage/google/racda', 'FECHA_EMISION_RACDA' => '2025-07-14', 'FECHA_RACDA' => '2027-07-14',
        ]);

        $admin = $this->superAdminGlobal();
        $ids = $this->idsExportarLocal($admin, ['search_query' => $serial]);
        $this->assertSame([$equipo->ID_EQUIPO], $ids['equipos']);

        $resp = $this->actingAs($admin)->post(route('equipos.export'), ['ids' => implode(',', $ids['equipos'])]);
        $resp->assertOk();
        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($resp->baseResponse->getFile()->getPathname())->getSheetByName('Equipos');

        // Sin frente elegido sale la columna FRENTE: los documentos empiezan en la L (12).
        $fila = [];
        foreach (range(12, 27) as $i) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $fila[str_replace("\n", ' ', (string) $hoja->getCell($col . '5')->getValue())] = (string) $hoja->getCell($col . '6')->getValue();
        }

        $this->assertSame([
            'TÍTULO DE PROPIEDAD' => 'SÍ', 'N° DEL TÍTULO' => '260110691985',
            'TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A', 'EMISIÓN TÍTULO' => '04/03/2025',
            'PÓLIZA' => 'SÍ', 'EMISIÓN PÓLIZA' => '09/06/2026', 'VENC. PÓLIZA' => '09/06/2027',
            'RACDA' => 'SÍ', 'EMISIÓN RACDA' => '14/07/2025', 'VENC. RACDA' => '14/07/2027',
            'ROTC' => 'NO', 'EMISIÓN ROTC' => '—', 'VENC. ROTC' => '—',
            'CERTIFICADO' => 'NO', 'VENC. CERTIF.' => '—',
            '' => '',   // la columna 27 tiene que estar vacía: no sobra ninguna
        ], $fila);
        $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $hoja->getCell('M6')->getDataType(),
            'el número del título va como texto, no como 2,6E+11');
        $this->assertSame('FECHA: ' . date('d/m/Y'), (string) $hoja->getCell('U3')->getValue(), 'EDICIÓN/REVISIÓN/FECHA siguen en las 6 últimas columnas (U..Z)');
    }
}
