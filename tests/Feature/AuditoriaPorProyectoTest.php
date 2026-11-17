<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Services\InventarioService;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Auditoría de Inventario (AJUSTE del lote) en un almacén que lleva el inventario POR
 * PROYECTO: el conteo es de una bolsa, y cada línea dice de cuál. Antes el ajuste caía en la
 * bolsa común con el total del almacén y descuadraba los proyectos.
 */
class AuditoriaPorProyectoTest extends MySqlTestCase
{
    private int $almacen;
    private int $frenteA;
    private int $frenteB;
    private int $ajeno;      // activo, pero no es de este almacén
    private int $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $libres = DB::table('frentes_trabajo')
            ->where('ESTATUS_FRENTE', 'ACTIVO')
            ->whereNotIn('ID_FRENTE', DB::table('almacen_frentes')->select('ID_FRENTE'))
            ->orderBy('ID_FRENTE')->limit(3)->pluck('ID_FRENTE')->map(fn ($v) => (int) $v)->all();
        if (count($libres) < 3) {
            $this->markTestSkipped('Hacen falta tres frentes activos sin almacén asignado.');
        }
        [$this->frenteA, $this->frenteB, $this->ajeno] = $libres;

        $this->almacen = (int) Almacen::forceCreate([
            'NOMBRE' => 'PRUEBA AUDITORIA MULTI ' . uniqid(), 'TIPO' => Almacen::TIPO_PROYECTO, 'ESTATUS' => 'ACTIVO',
        ])->ID_ALMACEN;
        DB::table('almacen_frentes')->insert([
            ['ID_ALMACEN' => $this->almacen, 'ID_FRENTE' => $this->frenteA],
            ['ID_ALMACEN' => $this->almacen, 'ID_FRENTE' => $this->frenteB],
        ]);
        $this->assertTrue(Almacen::find($this->almacen)->separaPorProyecto());

        $this->producto = (int) ProductoInventario::where('ESTATUS', 'ACTIVO')->orderBy('ID_PRODUCTO')->value('ID_PRODUCTO');

        // Saldo de partida: A=10, B=5, común=2.
        $inv = app(InventarioService::class);
        $inv->registrarEntrada($this->almacen, $this->producto, 10, ['id_frente' => $this->frenteA]);
        $inv->registrarEntrada($this->almacen, $this->producto, 5, ['id_frente' => $this->frenteB]);
        $inv->registrarEntrada($this->almacen, $this->producto, 2, []);
    }

    private function auditar(array $lineas)
    {
        return $this->actingAs($this->superAdminGlobal())->postJson(route('almacen.movimientos.lote'), [
            'id_almacen' => $this->almacen,
            'tipo'       => 'AJUSTE',
            'motivo'     => 'Auditoría de Inventario',
            'lineas'     => $lineas,
        ]);
    }

    private function saldo(int $frente): float
    {
        return (float) AlmacenStock::where('ID_ALMACEN', $this->almacen)->where('ID_PRODUCTO', $this->producto)
            ->where('ID_FRENTE', $frente)->value('CANTIDAD');
    }

    public function test_cada_conteo_ajusta_la_bolsa_de_su_proyecto(): void
    {
        $this->auditar([
            ['id_producto' => $this->producto, 'cantidad' => 7, 'id_frente_saldo' => $this->frenteA],
            ['id_producto' => $this->producto, 'cantidad' => 3, 'id_frente_saldo' => InventarioService::FRENTE_BOLSA_COMUN],
        ])->assertCreated();

        $this->assertSame(7.0, $this->saldo($this->frenteA));
        $this->assertSame(5.0, $this->saldo($this->frenteB), 'El proyecto que no se contó queda igual.');
        $this->assertSame(3.0, $this->saldo(InventarioService::FRENTE_BOLSA_COMUN));

        // El kardex nombra el proyecto auditado; la común va sin proyecto.
        $movs = MovimientoInventario::where('ID_ALMACEN', $this->almacen)->where('TIPO', 'AJUSTE')->get();
        $this->assertCount(2, $movs);
        $a = $movs->firstWhere('ID_FRENTE_SALDO', $this->frenteA);
        $this->assertSame($this->frenteA, (int) $a->ID_FRENTE);
        $this->assertSame([10.0, 7.0], [(float) $a->CANTIDAD_ANTERIOR, (float) $a->CANTIDAD_RESULTANTE]);
        $this->assertNull($movs->firstWhere('ID_FRENTE_SALDO', InventarioService::FRENTE_BOLSA_COMUN)->ID_FRENTE);
    }

    public function test_sin_proyecto_se_rechaza_y_no_cambia_nada(): void
    {
        $this->auditar([['id_producto' => $this->producto, 'cantidad' => 4]])
            ->assertStatus(422)->assertJsonValidationErrors('lineas');

        $this->assertSame(10.0, $this->saldo($this->frenteA));
        $this->assertSame(2.0, $this->saldo(InventarioService::FRENTE_BOLSA_COMUN));
        $this->assertSame(0, MovimientoInventario::where('ID_ALMACEN', $this->almacen)->where('TIPO', 'AJUSTE')->count());
    }

    public function test_un_proyecto_ajeno_al_almacen_se_rechaza(): void
    {
        $this->auditar([['id_producto' => $this->producto, 'cantidad' => 4, 'id_frente_saldo' => $this->ajeno]])
            ->assertStatus(422)->assertJsonValidationErrors('id_frente_saldo');

        $this->assertSame(0, MovimientoInventario::where('ID_ALMACEN', $this->almacen)->where('TIPO', 'AJUSTE')->count());
    }

    public function test_en_un_almacen_de_un_solo_proyecto_se_audita_el_total_como_siempre(): void
    {
        DB::table('almacen_frentes')->where('ID_ALMACEN', $this->almacen)->where('ID_FRENTE', $this->frenteB)->delete();
        $this->assertFalse(Almacen::find($this->almacen)->separaPorProyecto());

        $this->auditar([['id_producto' => $this->producto, 'cantidad' => 4]])->assertCreated();
        $this->assertSame(1, MovimientoInventario::where('ID_ALMACEN', $this->almacen)->where('TIPO', 'AJUSTE')->count());
    }
}
