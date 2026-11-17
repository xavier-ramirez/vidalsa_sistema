<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\FrenteTrabajo;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Usuario;
use App\Services\InventarioService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Nota HORIZONTAL: cada renglón lleva su DESTINO (equipo, frente o sitio), escrito en el modal
 * de salida. Se guarda en movimientos_inventario.DESTINO_ITEM —tanto en la salida del mismo
 * almacén como en la que va por traspaso— y lo imprime la columna DESTINO. Todo corre en la
 * transacción de la prueba y se revierte al terminar.
 */
class SalidaDestinoPorRenglonTest extends MySqlTestCase
{
    private InventarioService $inv;
    private string $marca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inv   = app(InventarioService::class);
        $this->marca = 'PRUEBA DEST ' . strtoupper(Str::random(6));
    }

    private function usuario(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.movimiento') && Almacen::usuarioEsGlobal($u));
        $this->assertNotNull($u, 'No hay un usuario GLOBAL con almacen.movimiento para probar.');
        return $u;
    }

    private function almacen(string $tipo, array $frentes = []): Almacen
    {
        $a = Almacen::create([
            'NOMBRE' => 'ALMACEN ' . $this->marca . ' ' . Str::random(4), 'TIPO' => $tipo,
            'FORMATO_NOTA' => Almacen::FORMATO_NOTA_HORIZONTAL,
        ]);
        if ($frentes) $a->frentes()->attach($frentes);
        return $a->fresh();
    }

    private function producto(string $nombre, Almacen $alm): ProductoInventario
    {
        $p = ProductoInventario::create(['CODIGO' => 'D' . strtoupper(Str::random(7)), 'NOMBRE' => "{$nombre} {$this->marca}", 'UM' => 'UND']);
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 10);
        return $p;
    }

    private function salida(Almacen $alm, int $idFrente, array $lineas, array $extra = [])
    {
        return $this->actingAs($this->usuario())->postJson(route('almacen.movimientos.lote'), [
            'tipo' => 'SALIDA', 'id_almacen' => $alm->ID_ALMACEN, 'id_frente_destino' => $idFrente, 'lineas' => $lineas,
        ] + $extra);
    }

    private function destinoDe(int $idProducto): ?string
    {
        return MovimientoInventario::where('ID_PRODUCTO', $idProducto)
            ->whereIn('TIPO', ['SALIDA', 'TRASPASO_SALIDA'])->value('DESTINO_ITEM');
    }

    public function test_la_salida_guarda_e_imprime_el_destino_de_cada_renglon(): void
    {
        $frente = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->value('ID_FRENTE');
        $alm    = $this->almacen(Almacen::TIPO_GENERAL);
        $aceite = $this->producto('ACEITE', $alm);
        $filtro = $this->producto('FILTRO', $alm);

        // Mismo almacén: que el frente no tenga OTRO almacén PROYECTO (si no, iría por traspaso).
        $this->salida($alm, (int) $frente, [
            ['id_producto' => $aceite->ID_PRODUCTO, 'cantidad' => 2, 'destino' => '  chuto   a60eo9p '],
            ['id_producto' => $filtro->ID_PRODUCTO, 'cantidad' => 1, 'destino' => ''],
        ], ($otro = Almacen::where('TIPO', Almacen::TIPO_PROYECTO)->where('ESTATUS', 'ACTIVO')
                ->whereHas('frentes', fn ($q) => $q->where('frentes_trabajo.ID_FRENTE', $frente))->value('ID_ALMACEN'))
            ? ['id_almacen_destino' => $otro] : [])->assertSuccessful();

        $this->assertSame('CHUTO A60EO9P', $this->destinoDe($aceite->ID_PRODUCTO), 'Se guarda limpio y en mayúsculas.');
        $this->assertNull($this->destinoDe($filtro->ID_PRODUCTO), 'Vacío = sin destino.');

        $numero = MovimientoInventario::where('ID_PRODUCTO', $aceite->ID_PRODUCTO)
            ->whereIn('TIPO', ['SALIDA', 'TRASPASO_SALIDA'])->value('NUMERO_NOTA');
        $datos = null;
        View::composer('admin.almacen.nota_entrega_horizontal_pdf', function ($v) use (&$datos) { $datos = $v->getData(); });
        $this->actingAs($this->usuario())->get(route('almacen.nota-entrega', ['numero' => $numero]))->assertOk();
        $this->assertNotNull($datos, 'La nota horizontal no pasó por su vista.');
        $this->assertStringContainsString('CHUTO A60EO9P', view('admin.almacen.nota_entrega_horizontal_pdf', $datos)->render());
    }

    public function test_por_traspaso_tambien_se_guarda(): void
    {
        $frente  = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->value('ID_FRENTE');
        $origen  = $this->almacen(Almacen::TIPO_GENERAL);
        $destino = $this->almacen(Almacen::TIPO_PROYECTO, [$frente]);
        $p       = $this->producto('DISCO', $origen);

        $this->salida($origen, (int) $frente, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 3, 'destino' => 'Excavadora 12']],
            ['id_almacen_destino' => $destino->ID_ALMACEN])->assertSuccessful();

        $this->assertSame('EXCAVADORA 12', $this->destinoDe($p->ID_PRODUCTO));
    }

    public function test_un_destino_que_crece_al_pasar_a_mayusculas_no_rompe_la_columna(): void
    {
        $frente = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->value('ID_FRENTE');
        $origen  = $this->almacen(Almacen::TIPO_GENERAL);
        $destino = $this->almacen(Almacen::TIPO_PROYECTO, [$frente]);
        $p       = $this->producto('CABLE', $origen);

        // 150 caracteres que en mayúsculas pasan a 300 (ß → SS).
        $this->salida($origen, (int) $frente, [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1, 'destino' => str_repeat('ß', 150)]],
            ['id_almacen_destino' => $destino->ID_ALMACEN])->assertSuccessful();

        $this->assertSame(150, mb_strlen($this->destinoDe($p->ID_PRODUCTO)));
    }

    public function test_la_vista_previa_acepta_el_destino(): void
    {
        $alm = $this->almacen(Almacen::TIPO_GENERAL);
        $p   = $this->producto('GRASA', $alm);

        // El PDF va comprimido: se mira lo que recibe la vista y se dibuja con eso.
        $datos = null;
        View::composer('admin.almacen.nota_entrega_horizontal_pdf', function ($v) use (&$datos) { $datos = $v->getData(); });
        $this->actingAs($this->usuario())->post(route('almacen.salida.preview'), [
            'id_almacen' => $alm->ID_ALMACEN,
            'lineas'     => [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1, 'destino' => 'Patio  norte']],
        ])->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertNotNull($datos, 'La vista previa no pasó por la nota horizontal.');
        $this->assertStringContainsString('PATIO NORTE', view('admin.almacen.nota_entrega_horizontal_pdf', $datos)->render());
    }
}
