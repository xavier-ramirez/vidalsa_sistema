<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Usuario;
use App\Services\InventarioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * RECIBIDO POR de la Nota de Entrega (nombre y cargo, antes a mano) y la nota VERTICAL sin
 * "Datos del vehículo / Datos del chofer" cuando la salida es consumo del propio almacén.
 * Todo corre en la transacción de la prueba y se revierte.
 */
class NotaEntregaRecibidoTest extends MySqlTestCase
{
    private function usuario(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.movimiento') && Almacen::usuarioEsGlobal($u));
        $this->assertNotNull($u, 'No hay un usuario GLOBAL con almacen.movimiento para probar.');
        return $u;
    }

    /** Almacén con un proyecto propio y un producto con 10 en stock: [almacén, producto, frente]. */
    private function almacen(string $formato = Almacen::FORMATO_NOTA_VERTICAL): array
    {
        $sufijo = strtoupper(Str::random(6));
        $alm = Almacen::create(['NOMBRE' => "ALMACEN RECIBE {$sufijo}", 'TIPO' => Almacen::TIPO_GENERAL, 'FORMATO_NOTA' => $formato]);
        $frente = $this->frente();
        DB::table('almacen_frentes')->insert(['ID_ALMACEN' => $alm->ID_ALMACEN, 'ID_FRENTE' => $frente]);
        $p = ProductoInventario::create(['CODIGO' => 'R' . $sufijo, 'NOMBRE' => "PRODUCTO RECIBE {$sufijo}", 'UM' => 'UND']);
        app(InventarioService::class)->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 10);
        return [$alm, $p, $frente];
    }

    private function frente(): int
    {
        return DB::table('frentes_trabajo')->insertGetId([
            'NOMBRE_FRENTE' => 'FRENTE RECIBE ' . strtoupper(Str::random(6)), 'ESTATUS_FRENTE' => 'ACTIVO',
        ]);
    }

    private function salida(Almacen $alm, ProductoInventario $p, int $frente, array $extra = [], string $tipo = 'SALIDA'): MovimientoInventario
    {
        $this->actingAs($this->usuario())->postJson(route('almacen.movimientos.lote'), [
            'tipo' => 'SALIDA', 'id_almacen' => $alm->ID_ALMACEN, 'id_frente_destino' => $frente,
            'lineas' => [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1]],
        ] + $extra)->assertSuccessful();

        return MovimientoInventario::where('ID_PRODUCTO', $p->ID_PRODUCTO)->where('TIPO', $tipo)->latest('ID_MOVIMIENTO')->first();
    }

    /** El HTML de la nota emitida, con su plantilla (vertical u horizontal). */
    private function htmlDeLaNota(string $numero, string $vista = 'admin.almacen.nota_entrega_pdf'): string
    {
        $datos = null;
        View::composer($vista, function ($v) use (&$datos) { $datos = $v->getData(); });
        $this->actingAs($this->usuario())->get(route('almacen.nota-entrega', ['numero' => $numero]))->assertOk();
        $this->assertNotNull($datos, "Se pintó {$vista}.");
        return view($vista, $datos)->render();
    }

    public function test_consumo_interno_imprime_quien_recibe_y_va_sin_transporte(): void
    {
        [$alm, $p, $frente] = $this->almacen();
        $mov = $this->salida($alm, $p, $frente, ['recibido_por' => 'Pedro Pérez', 'cargo_recibe' => 'Supervisor de obra']);

        $this->assertSame(['Pedro Pérez', 'Supervisor de obra', true], [$mov->RECIBIDO_POR, $mov->CARGO_RECIBE, $mov->CONSUMO_INTERNO]);
        $html = $this->htmlDeLaNota($mov->NUMERO_NOTA);
        $this->assertStringContainsString('Pedro Pérez', $html);
        $this->assertStringContainsString('Supervisor de obra', $html);
        $this->assertStringNotContainsString('DATOS DEL VEHICULO', $html, 'Consumo del propio almacén: sin transporte.');
    }

    public function test_consumo_interno_con_transporte_escrito_lo_imprime(): void
    {
        [$alm, $p, $frente] = $this->almacen();
        $mov = $this->salida($alm, $p, $frente, ['transporte_chofer' => 'EDUARDO ORTIZ']);

        $this->assertTrue($mov->CONSUMO_INTERNO);
        $html = $this->htmlDeLaNota($mov->NUMERO_NOTA);
        $this->assertStringContainsString('DATOS DEL VEHICULO', $html);
        $this->assertStringContainsString('EDUARDO ORTIZ', $html);
    }

    public function test_a_un_proyecto_que_no_es_del_almacen_lleva_el_bloque_del_transporte(): void
    {
        [$alm, $p] = $this->almacen();
        // Proyecto sin almacén propio y ajeno a este: es consumo, pero el material viaja.
        $mov = $this->salida($alm, $p, $this->frente());

        $this->assertFalse($mov->CONSUMO_INTERNO);
        $this->assertStringContainsString('DATOS DEL VEHICULO', $this->htmlDeLaNota($mov->NUMERO_NOTA));
    }

    public function test_el_envio_a_otro_almacen_guarda_quien_recibe_y_lleva_transporte(): void
    {
        [$alm, $p] = $this->almacen();
        $destino = Almacen::create(['NOMBRE' => 'DESTINO RECIBE ' . strtoupper(Str::random(6)), 'TIPO' => Almacen::TIPO_PROYECTO]);
        $frente  = $this->frente();
        DB::table('almacen_frentes')->insert(['ID_ALMACEN' => $destino->ID_ALMACEN, 'ID_FRENTE' => $frente]);

        $mov = $this->salida($alm, $p, $frente, ['recibido_por' => 'Ana Ruiz', 'cargo_recibe' => 'Almacenista'], 'TRASPASO_SALIDA');

        $this->assertSame(['Ana Ruiz', 'Almacenista', false], [$mov->RECIBIDO_POR, $mov->CARGO_RECIBE, $mov->CONSUMO_INTERNO]);
        $html = $this->htmlDeLaNota($mov->NUMERO_NOTA);
        $this->assertStringContainsString('Ana Ruiz', $html);
        $this->assertStringContainsString('DATOS DEL VEHICULO', $html);
    }

    public function test_la_vista_previa_decide_igual_que_el_registro(): void
    {
        [$alm, $p, $frente] = $this->almacen();
        $datos = null;
        View::composer('admin.almacen.nota_entrega_pdf', function ($v) use (&$datos) { $datos = $v->getData()['datos']; });
        $pedir = fn (int $idFrente) => $this->actingAs($this->usuario())->postJson(route('almacen.salida.preview'), [
            'id_almacen' => $alm->ID_ALMACEN, 'id_frente_destino' => $idFrente,
            'lineas' => [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1]],
            'recibido_por' => 'Luis Gamboa', 'cargo_recibe' => 'Ing. Residente',
        ])->assertOk();

        $pedir($frente);
        $this->assertSame(['Luis Gamboa', 'Ing. Residente', true], [$datos['recibido_por'], $datos['cargo_recibe'], $datos['consumo_interno']]);
        $pedir($this->frente());
        $this->assertFalse($datos['consumo_interno']);
    }

    public function test_la_horizontal_pone_quien_recibe_en_su_firma(): void
    {
        [$alm, $p, $frente] = $this->almacen(Almacen::FORMATO_NOTA_HORIZONTAL);
        $mov = $this->salida($alm, $p, $frente, ['recibido_por' => 'Gabriel Lara', 'cargo_recibe' => 'Coord. Logístico']);

        $html = $this->htmlDeLaNota($mov->NUMERO_NOTA, 'admin.almacen.nota_entrega_horizontal_pdf');
        $this->assertStringContainsString('Gabriel Lara', $html);
        $this->assertStringContainsString('Coord. Logístico', $html);
    }
}
