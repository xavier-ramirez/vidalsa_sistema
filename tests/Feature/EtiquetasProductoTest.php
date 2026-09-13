<?php

namespace Tests\Feature;

use App\Models\ProductoInventario;
use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * Etiquetas QR de productos (/admin/almacen/etiquetas, AlmacenController::etiquetasPdf):
 * que los tres formatos salgan como PDF de verdad y con una página por etiqueta en los
 * rollos. El diseño (QR + código, descripción ajustada, medida y ubicación) lo dibuja
 * dibujarEtiqueta; aquí se fija que ninguna medida rompa el armado.
 */
class EtiquetasProductoTest extends MySqlTestCase
{
    private function usuario(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        $this->assertNotNull($u, 'Hace falta un usuario con super.admin.');
        return $u;
    }

    public function test_los_tres_formatos_salen_como_pdf(): void
    {
        $p = ProductoInventario::activos()->whereNotNull('CODIGO')->where('CODIGO', '!=', '')->first();
        $this->assertNotNull($p, 'Hace falta un producto activo con código.');

        foreach (['carta' => 1, '50x30' => 2, '40x25' => 2] as $formato => $paginas) {
            $resp = $this->actingAs($this->usuario())
                ->get(route('almacen.etiquetas', ['items' => $p->ID_PRODUCTO . ':2', 'formato' => $formato]));
            $resp->assertOk()->assertHeader('content-type', 'application/pdf');
            $pdf = $resp->getContent();
            $this->assertStringStartsWith('%PDF', $pdf, "El formato {$formato} no devolvió un PDF.");
            // Hoja: las 2 copias en la misma página; rollo: una etiqueta por página.
            $this->assertMatchesRegularExpression('/\/Type \/Pages[^>]*\/Count ' . $paginas . '\b/', $pdf, "Páginas del formato {$formato}.");
        }
    }
}
