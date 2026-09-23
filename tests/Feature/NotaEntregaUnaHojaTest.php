<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Usuario;
use App\Services\InventarioService;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Una operacion = una hoja (AlmacenController::renderNotaEntregaPdfBinary). Con el relleno
 * fijo, una salida de 20 renglones con descripciones de dos lineas empujaba el final de la
 * nota a una 2.ª hoja que solo traia el cabezote. Ahora la nota se mide y se quita relleno o
 * se compacta hasta que cabe. Todo corre en la transaccion de la prueba y se revierte.
 */
class NotaEntregaUnaHojaTest extends MySqlTestCase
{
    private function usuario(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.movimiento') && Almacen::usuarioEsGlobal($u));
        $this->assertNotNull($u, 'No hay un usuario GLOBAL con almacen.movimiento para probar.');
        return $u;
    }

    /**
     * Registra una salida de $n productos y devuelve cuantas hojas tiene su nota. Los nombres
     * miden 74 letras, el MAS LARGO del catalogo real: el peor caso que puede llegar.
     */
    private function hojasDeLaNota(string $formato, int $n): int
    {
        $marca = strtoupper(Str::random(6));
        $alm = Almacen::create(['NOMBRE' => "ALMACEN NOTA {$marca}", 'TIPO' => Almacen::TIPO_GENERAL, 'FORMATO_NOTA' => $formato]);
        $inv = app(InventarioService::class);
        $lineas = [];
        for ($i = 1; $i <= $n; $i++) {
            $p = ProductoInventario::create([
                'CODIGO' => 'N' . strtoupper(Str::random(7)), 'UM' => 'UND',
                'NOMBRE' => str_pad("REPUESTO DE PRUEBA {$marca} {$i} CON DESCRIPCION LARGA", 74, ' EN DOS LINEAS'),
            ]);
            $inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 5);
            $lineas[] = ['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1];
        }

        $this->actingAs($this->usuario())->postJson(route('almacen.movimientos.lote'), [
            'tipo' => 'SALIDA', 'id_almacen' => $alm->ID_ALMACEN, 'lineas' => $lineas,
            'motivo' => 'Prueba de una sola hoja', 'transporte_vehiculo' => 'CAMIONETA', 'transporte_chofer' => 'CHOFER',
        ])->assertSuccessful();

        $numero = MovimientoInventario::where('ID_ALMACEN', $alm->ID_ALMACEN)->where('TIPO', 'SALIDA')->value('NUMERO_NOTA');
        $pdf = $this->actingAs($this->usuario())->get(route('almacen.nota-entrega', ['numero' => $numero]))
            ->assertOk()->getContent();

        // Cada hoja es un objeto "/Type /Page" (el arbol que las agrupa es "/Type /Pages").
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    public function test_la_nota_vertical_de_20_renglones_largos_sale_en_una_hoja(): void
    {
        $this->assertSame(1, $this->hojasDeLaNota(Almacen::FORMATO_NOTA_VERTICAL, 20));
    }

    public function test_la_nota_horizontal_de_20_renglones_largos_sale_en_una_hoja(): void
    {
        $this->assertSame(1, $this->hojasDeLaNota(Almacen::FORMATO_NOTA_HORIZONTAL, 20));
    }

    public function test_con_pocos_renglones_la_nota_sigue_saliendo_en_una_hoja(): void
    {
        $this->assertSame(1, $this->hojasDeLaNota(Almacen::FORMATO_NOTA_VERTICAL, 1));
        $this->assertSame(1, $this->hojasDeLaNota(Almacen::FORMATO_NOTA_HORIZONTAL, 1));
    }
}
