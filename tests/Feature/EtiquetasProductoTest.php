<?php

namespace Tests\Feature;

use App\Models\ProductoInventario;
use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * Etiquetas QR de productos (/admin/almacen/etiquetas, AlmacenController::etiquetasPdf):
 * que las dos tiras de la etiquetadora salgan como PDF de verdad, con una página por
 * etiqueta y al tamaño del rollo. Ya no hay hoja carta: un formato desconocido cae en la
 * tira de 50×30. El diseño (QR + código, descripción ajustada, medida y ubicación) lo
 * dibuja dibujarEtiqueta; aquí se fija que ninguna medida rompa el armado.
 */
class EtiquetasProductoTest extends MySqlTestCase
{
    private function usuario(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        $this->assertNotNull($u, 'Hace falta un usuario con super.admin.');
        return $u;
    }

    public function test_las_tiras_salen_como_pdf_una_etiqueta_por_pagina(): void
    {
        $p = ProductoInventario::activos()->whereNotNull('CODIGO')->where('CODIGO', '!=', '')->first();
        $this->assertNotNull($p, 'Hace falta un producto activo con código.');

        // Página en puntos: 50×30 mm = 141,73×85,04 pt; 40×25 mm = 113,38×70,87 pt.
        foreach (['50x30' => '141.73', '40x25' => '113.38', 'carta' => '141.73'] as $formato => $ancho) {
            $resp = $this->actingAs($this->usuario())
                ->get(route('almacen.etiquetas', ['items' => $p->ID_PRODUCTO . ':2', 'formato' => $formato]));
            $resp->assertOk()->assertHeader('content-type', 'application/pdf');
            $pdf = $resp->getContent();
            $this->assertStringStartsWith('%PDF', $pdf, "El formato {$formato} no devolvió un PDF.");
            $this->assertMatchesRegularExpression('/\/Type \/Pages[^>]*\/Count 2\b/', $pdf, "Una etiqueta por página ({$formato}).");
            $this->assertStringContainsString('/MediaBox [0.000000 0.000000 ' . $ancho, $pdf, "Tamaño de la tira ({$formato}).");
        }
    }
}
