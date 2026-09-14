<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Models\ProductoEquivalencia;
use App\Models\ProductoInventario;
use App\Models\Usuario;
use App\Services\InventarioService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Salida de un filtro con varias equivalencias (AlmacenController::errorNumeroParte): hay que
 * decir qué número de parte se entrega, y tiene que ser uno de los suyos. La pantalla lo pide
 * antes de dejar poner la cantidad; el servidor lo exige igual. Todo corre en la transacción
 * de la prueba y se revierte al terminar.
 */
class SalidaEquivalenciaTest extends MySqlTestCase
{
    private InventarioService $inv;
    private string $marca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inv   = app(InventarioService::class);
        $this->marca = 'PRUEBA EQUIV ' . strtoupper(Str::random(6));
    }

    private function usuario(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.movimiento') && Almacen::usuarioEsGlobal($u));
        $this->assertNotNull($u, 'No hay un usuario GLOBAL con almacen.movimiento para probar.');
        return $u;
    }

    /** Filtro con sus números de parte y 10 en stock. */
    private function filtro(array $partes): array
    {
        $alm = Almacen::create(['NOMBRE' => 'ALMACEN ' . $this->marca . ' ' . Str::random(4), 'TIPO' => Almacen::TIPO_GENERAL]);
        $p = ProductoInventario::create(['CODIGO' => 'E' . strtoupper(Str::random(7)), 'NOMBRE' => "FILTRO {$this->marca}", 'UM' => 'UND']);
        foreach ($partes as $i => $np) {
            ProductoEquivalencia::create(['ID_PRODUCTO' => $p->ID_PRODUCTO, 'NUMERO_PARTE' => $np, 'ES_PRINCIPAL' => $i === 0]);
        }
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 10);
        return [$alm, $p];
    }

    private function salida(Almacen $alm, ProductoInventario $p, ?string $parte)
    {
        return $this->actingAs($this->usuario())->postJson(route('almacen.movimientos.lote'), [
            'tipo'       => 'SALIDA',
            'id_almacen' => $alm->ID_ALMACEN,
            'lineas'     => [array_filter(['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2, 'numero_parte' => $parte])],
        ]);
    }

    public function test_con_varias_equivalencias_hay_que_elegir_una_de_las_suyas(): void
    {
        [$alm, $p] = $this->filtro(['P-111', 'P-222', 'P-333']);

        $this->salida($alm, $p, null)->assertStatus(422)
            ->assertJsonPath('message', "Elige la equivalencia (el número de parte) que se entrega de «{$p->NOMBRE}».");
        $this->salida($alm, $p, 'OTRA-999')->assertStatus(422)
            ->assertJsonPath('message', "El número de parte OTRA-999 no es una equivalencia de «{$p->NOMBRE}».");
        $this->assertSame(0, MovimientoInventario::where('ID_PRODUCTO', $p->ID_PRODUCTO)->where('TIPO', 'SALIDA')->count(),
            'Una salida rechazada no puede mover stock.');

        $this->salida($alm, $p, 'P-222')->assertSuccessful();
        $this->assertSame('P-222', MovimientoInventario::where('ID_PRODUCTO', $p->ID_PRODUCTO)->where('TIPO', 'SALIDA')->value('NUMERO_PARTE'));
    }

    public function test_con_una_sola_equivalencia_o_ninguna_no_hace_falta_elegir(): void
    {
        [$alm, $p] = $this->filtro(['P-111']);
        $this->salida($alm, $p, null)->assertSuccessful();

        [$alm2, $p2] = $this->filtro([]);
        $this->salida($alm2, $p2, null)->assertSuccessful();
    }

    public function test_la_nota_de_entrega_imprime_la_descripcion_con_la_equivalencia_elegida(): void
    {
        [$alm, $p] = $this->filtro(['P-111', 'P-222', 'P-333']);
        $this->salida($alm, $p, 'P-222')->assertSuccessful();
        $numero = MovimientoInventario::where('ID_PRODUCTO', $p->ID_PRODUCTO)->where('TIPO', 'SALIDA')->value('NUMERO_NOTA');

        // Lo que recibe la vista de la nota en la petición real; después se dibuja con esos
        // mismos datos en los dos formatos (el PDF comprime el texto y no se puede leer).
        $datos = null;
        View::composer('admin.almacen.nota_entrega_pdf', function ($v) use (&$datos) { $datos = $v->getData(); });
        $this->actingAs($this->usuario())->get(route('almacen.nota-entrega', ['numero' => $numero]))->assertOk();
        $this->assertNotNull($datos, 'La nota no pasó por su vista.');

        $esperado = "{$p->NOMBRE} &nbsp;—&nbsp; P-222";
        $this->assertStringContainsString($esperado, view('admin.almacen.nota_entrega_pdf', $datos)->render());
        $this->assertStringContainsString($esperado, view('admin.almacen.nota_entrega_horizontal_pdf',
            ['firmantes' => $alm->firmantesNota()] + $datos)->render());
    }

    public function test_la_vista_previa_de_la_nota_tambien_lo_pide(): void
    {
        [$alm, $p] = $this->filtro(['P-111', 'P-222']);

        $this->actingAs($this->usuario())->postJson(route('almacen.salida.preview'), [
            'id_almacen' => $alm->ID_ALMACEN,
            'lineas'     => [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2]],
        ])->assertStatus(422)
            ->assertJsonPath('message', "Elige la equivalencia (el número de parte) que se entrega de «{$p->NOMBRE}».");
    }
}
