<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\CorreccionNota;
use App\Models\FrenteTrabajo;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Usuario;
use App\Services\InventarioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Corrección de la cantidad de un producto en una Nota de Entrega mal cargada
 * (App\Services\CorreccionNotaService): salieron 100 electrodos y se tecleó 180. La salida pasa
 * a decir 100, el stock y el kardex quedan como si siempre hubiera sido así, los demás
 * productos de la nota no se tocan y queda el rastro. Todo corre en la transacción de la
 * prueba y se revierte al terminar.
 */
class CorreccionNotaTest extends MySqlTestCase
{
    private InventarioService $inv;
    private string $marca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inv   = app(InventarioService::class);
        $this->marca = 'PRUEBA COR ' . strtoupper(Str::random(6));
    }

    /** Quien corrige: la clave literal y todos los almacenes a la vista. */
    private function usuario(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.nota.corregir') && Almacen::usuarioEsGlobal($u));
        $this->assertNotNull($u, 'No hay un usuario GLOBAL con almacen.nota.corregir para probar.');
        return $u;
    }

    private function almacen(string $tipo = Almacen::TIPO_GENERAL, array $frentes = []): Almacen
    {
        $a = Almacen::create(['NOMBRE' => 'ALMACEN ' . $this->marca, 'TIPO' => $tipo]);
        if ($frentes) {
            $a->frentes()->attach($frentes);
        }
        return $a->fresh();
    }

    private function producto(string $nombre): ProductoInventario
    {
        return ProductoInventario::create([
            'CODIGO' => 'C' . strtoupper(Str::random(7)),
            'NOMBRE' => "{$nombre} {$this->marca}",
            'UM'     => 'UND',
        ]);
    }

    private function frentes(int $n): array
    {
        $ids = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->limit($n)->pluck('ID_FRENTE')->all();
        $this->assertCount($n, $ids, "Hacen falta {$n} frentes activos para probar.");
        return $ids;
    }

    /** Salida con Nota de Entrega, como la registra registrarMovimientoLote. */
    private function salidaConNota(int $idAlmacen, array $lineas, ?int $idFrente): string
    {
        return DB::transaction(function () use ($idAlmacen, $lineas, $idFrente) {
            $numero = MovimientoInventario::generarNumeroNota();
            foreach ($lineas as $idProducto => $cantidad) {
                $this->inv->registrarSalida($idAlmacen, $idProducto, $cantidad, [
                    'id_frente' => $idFrente, 'numero_nota' => $numero,
                ]);
            }
            return $numero;
        });
    }

    private function saldo(int $idAlmacen, int $idProducto, ?int $bolsa = null): float
    {
        return (float) AlmacenStock::where('ID_ALMACEN', $idAlmacen)->where('ID_PRODUCTO', $idProducto)
            ->when($bolsa !== null, fn ($q) => $q->where('ID_FRENTE', $bolsa))
            ->sum('CANTIDAD');
    }

    private function enNota(string $numero, int $idProducto): float
    {
        return (float) MovimientoInventario::where('NUMERO_NOTA', $numero)->where('ID_PRODUCTO', $idProducto)->sum('CANTIDAD');
    }

    private function corregir(string $numero, int $idProducto, float $cantidad, string $motivo = 'Se cargó mal')
    {
        return $this->actingAs($this->usuario())->postJson(route('almacen.correccion-nota.store'), [
            'numero' => $numero, 'id_producto' => $idProducto, 'cantidad' => $cantidad, 'motivo' => $motivo,
        ]);
    }

    public function test_bajar_la_cantidad_devuelve_el_stock_y_no_toca_los_demas_productos(): void
    {
        $alm = $this->almacen();
        $electrodo = $this->producto('ELECTRODO');
        $disco     = $this->producto('DISCO');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $electrodo->ID_PRODUCTO, 200);
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $disco->ID_PRODUCTO, 20);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$electrodo->ID_PRODUCTO => 180, $disco->ID_PRODUCTO => 5], null);
        // Un movimiento DESPUÉS de la nota: su saldo corrido también tiene que quedar bien.
        $despues = $this->inv->registrarEntrada($alm->ID_ALMACEN, $electrodo->ID_PRODUCTO, 10);

        $this->corregir($nota, $electrodo->ID_PRODUCTO, 100, 'Salieron 100')
            ->assertCreated()
            ->assertJsonPath('numero', $nota);

        $this->assertSame(100.0, $this->enNota($nota, $electrodo->ID_PRODUCTO));
        $this->assertSame(110.0, $this->saldo($alm->ID_ALMACEN, $electrodo->ID_PRODUCTO), '200 − 100 + 10');
        $despues->refresh();
        $this->assertSame(100.0, (float) $despues->CANTIDAD_ANTERIOR);
        $this->assertSame(110.0, (float) $despues->CANTIDAD_RESULTANTE);

        // El otro producto de la nota, intacto.
        $this->assertSame(5.0, $this->enNota($nota, $disco->ID_PRODUCTO));
        $this->assertSame(15.0, $this->saldo($alm->ID_ALMACEN, $disco->ID_PRODUCTO));

        // El rastro.
        $c = CorreccionNota::where('NUMERO_NOTA', $nota)->sole();
        $this->assertSame((int) $electrodo->ID_PRODUCTO, (int) $c->ID_PRODUCTO);
        $this->assertSame(180.0, $c->CANTIDAD_ANTES);
        $this->assertSame(100.0, $c->CANTIDAD_DESPUES);
        $this->assertSame('Salieron 100', $c->MOTIVO);
        $this->assertSame([(int) $electrodo->ID_PRODUCTO => 180.0], CorreccionNota::originales(CorreccionNota::where('NUMERO_NOTA', $nota)->get()));
    }

    public function test_subir_la_cantidad_saca_mas_y_sin_stock_no_cambia_nada(): void
    {
        $alm = $this->almacen();
        $p = $this->producto('ELECTRODO');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 120);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p->ID_PRODUCTO => 100], null);

        $this->corregir($nota, $p->ID_PRODUCTO, 110)->assertCreated();
        $this->assertSame(110.0, $this->enNota($nota, $p->ID_PRODUCTO));
        $this->assertSame(10.0, $this->saldo($alm->ID_ALMACEN, $p->ID_PRODUCTO));

        // Quedan 10: subir a 130 pide 20 que no hay.
        $this->corregir($nota, $p->ID_PRODUCTO, 130)->assertStatus(422)
            ->assertJsonPath('message', 'No hay stock suficiente para subir la cantidad: faltan 10 UND.');
        $this->assertSame(110.0, $this->enNota($nota, $p->ID_PRODUCTO));
        $this->assertSame(10.0, $this->saldo($alm->ID_ALMACEN, $p->ID_PRODUCTO));
        $this->assertSame(1, CorreccionNota::where('NUMERO_NOTA', $nota)->count(), 'La que falló no deja rastro.');
    }

    public function test_subir_una_nota_vieja_no_deja_negativo_un_dia_intermedio(): void
    {
        // Día 1: salen 100 de 100 (queda 0). Después entran 50, salen 50 (0) y entran 40.
        $alm = $this->almacen();
        $p = $this->producto('ELECTRODO');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 100);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p->ID_PRODUCTO => 100], null);
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 50);
        $this->salidaConNota($alm->ID_ALMACEN, [$p->ID_PRODUCTO => 50], null);
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 40);

        // Subir la del día 1 a 130: al final quedarían 10, pero ese día no había 130 — el
        // kardex pasaría por -30. Se rechaza y nada cambia.
        $this->corregir($nota, $p->ID_PRODUCTO, 130)->assertStatus(422)
            ->assertJsonPath('message', 'No hay stock suficiente para subir la cantidad: faltan 30 UND.');
        $this->assertSame(100.0, $this->enNota($nota, $p->ID_PRODUCTO));
        $this->assertSame(40.0, $this->saldo($alm->ID_ALMACEN, $p->ID_PRODUCTO));
        $this->assertGreaterThanOrEqual(0.0, (float) MovimientoInventario::where('ID_ALMACEN', $alm->ID_ALMACEN)
            ->where('ID_PRODUCTO', $p->ID_PRODUCTO)->min('CANTIDAD_RESULTANTE'));
    }

    public function test_en_un_almacen_por_proyecto_bajar_devuelve_primero_lo_prestado(): void
    {
        [$fA, $fB] = $this->frentes(2);
        $alm = $this->almacen(Almacen::TIPO_PROYECTO, [$fA, $fB]);
        $p = $this->producto('ELECTRODO');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 3, ['id_frente' => $fA]);
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 4, ['id_frente' => $fB]);

        // 5 al proyecto A: 3 de su bolsa y 2 prestadas de la de B → dos filas.
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p->ID_PRODUCTO => 5], $fA);
        $this->assertSame(2, MovimientoInventario::where('NUMERO_NOTA', $nota)->count());

        // Eran 3: lo prestado vuelve entero a B y su fila desaparece (una salida de 0 no es salida).
        $this->corregir($nota, $p->ID_PRODUCTO, 3)->assertCreated();
        $this->assertSame(1, MovimientoInventario::where('NUMERO_NOTA', $nota)->count());
        $this->assertSame(3.0, $this->enNota($nota, $p->ID_PRODUCTO));
        $this->assertSame(0.0, $this->saldo($alm->ID_ALMACEN, $p->ID_PRODUCTO, $fA));
        $this->assertSame(4.0, $this->saldo($alm->ID_ALMACEN, $p->ID_PRODUCTO, $fB));
    }

    public function test_no_baja_de_lo_ya_devuelto(): void
    {
        $alm = $this->almacen();
        $p = $this->producto('ELECTRODO');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 200);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p->ID_PRODUCTO => 180], null);
        $this->actingAs($this->usuario())->postJson(route('almacen.devolucion.store'), [
            'numero' => $nota, 'lineas' => [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 50]],
        ]);
        $devueltas = MovimientoInventario::where('TIPO', MovimientoInventario::TIPO_DEVOLUCION)->where('REFERENCIA', $nota)->count();

        $this->corregir($nota, $p->ID_PRODUCTO, 40)->assertStatus(422);
        $this->assertSame(180.0, $this->enNota($nota, $p->ID_PRODUCTO));

        // Con la clave de movimientos la devolución entró; sin ella, solo se prueba la regla.
        if ($devueltas) {
            $this->corregir($nota, $p->ID_PRODUCTO, 60)->assertCreated();
            $this->assertSame(60.0, $this->enNota($nota, $p->ID_PRODUCTO));
            $this->assertSame(190.0, $this->saldo($alm->ID_ALMACEN, $p->ID_PRODUCTO), '200 − 60 + 50 devueltas');
        }
    }

    public function test_reglas_y_permisos(): void
    {
        $alm = $this->almacen();
        $p = $this->producto('ELECTRODO');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 200);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p->ID_PRODUCTO => 180], null);

        $this->corregir($nota, $p->ID_PRODUCTO, 180)->assertStatus(422)
            ->assertJsonPath('message', 'Es la misma cantidad que ya tiene la nota.');
        $this->corregir($nota, $p->ID_PRODUCTO, 0)->assertStatus(422);
        $this->corregir($nota, $p->ID_PRODUCTO, 100, '')->assertStatus(422)->assertJsonValidationErrors('motivo');

        // Sin la clave literal no se corrige (super.admin no la hereda). Si tiene la de
        // movimientos el modal abre igual, con la corrección apagada y su porqué; sin ninguna
        // de las dos, ni abre.
        $ver = fn ($u) => $this->actingAs($u)->getJson(route('almacen.ajuste-nota.show', ['numero' => $nota, 'id_producto' => $p->ID_PRODUCTO]));
        $sin = Usuario::all()->first(fn ($u) => !$u->can('almacen.nota.corregir'));
        if ($sin) {
            $this->actingAs($sin)->postJson(route('almacen.correccion-nota.store'), [
                'numero' => $nota, 'id_producto' => $p->ID_PRODUCTO, 'cantidad' => 100, 'motivo' => 'x',
            ])->assertForbidden();
        }
        $soloDevuelve = Usuario::all()->first(fn ($u) => !$u->can('almacen.nota.corregir') && $u->can('almacen.movimiento') && Almacen::usuarioEsGlobal($u));
        if ($soloDevuelve) {
            $ver($soloDevuelve)->assertOk()
                ->assertJsonPath('correccion.motivo_no', 'Corregir una nota pide la clave «almacen.nota.corregir».')
                ->assertJsonPath('devolucion.motivo_no', null);
        }
        $ninguna = Usuario::all()->first(fn ($u) => !$u->can('almacen.nota.corregir') && !$u->can('almacen.movimiento'));
        if ($ninguna) {
            $ver($ninguna)->assertForbidden();
        }
        $this->assertSame(180.0, $this->enNota($nota, $p->ID_PRODUCTO));

        $this->actingAs($this->usuario())
            ->getJson(route('almacen.ajuste-nota.show', ['numero' => $nota, 'id_producto' => $p->ID_PRODUCTO]))
            ->assertOk()
            ->assertJsonPath('linea.entregado', 180)
            ->assertJsonPath('linea.devuelto', 0)
            ->assertJsonPath('correccion.motivo_no', null);
    }

    public function test_un_envio_a_otro_almacen_no_se_corrige_desde_aqui(): void
    {
        // Una nota de envío real (montar un traspaso entero aquí sería probar TraspasoService).
        $envio = MovimientoInventario::where('TIPO', MovimientoInventario::TIPO_TRASPASO_SALIDA)
            ->whereNotNull('NUMERO_NOTA')->orderByDesc('ID_MOVIMIENTO')->first();
        if (!$envio) {
            $this->markTestSkipped('No hay notas de envío entre almacenes para probar.');
        }
        $antes = $this->enNota($envio->NUMERO_NOTA, $envio->ID_PRODUCTO);

        $this->corregir($envio->NUMERO_NOTA, $envio->ID_PRODUCTO, $antes + 1)->assertStatus(422)
            ->assertJsonPath('message', 'Esta nota es un envío a otro almacén: su cantidad se corrige desde Recepción, no aquí.');
        $this->assertSame($antes, $this->enNota($envio->NUMERO_NOTA, $envio->ID_PRODUCTO));
    }

    public function test_el_historial_ofrece_corregir_y_marca_lo_corregido(): void
    {
        $alm = $this->almacen();
        $p = $this->producto('ELECTRODO');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 200);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p->ID_PRODUCTO => 180], null);
        $bitacora = fn () => $this->actingAs($this->usuario())
            ->getJson(route('almacen.movimientos', ['id_almacen' => $alm->ID_ALMACEN, 'tipo' => 'SALIDA', 'skip_consumo' => 1]))
            ->assertOk()->json('html');

        $html = $bitacora();
        $this->assertStringContainsString("almModificarNota('{$nota}', {$p->ID_PRODUCTO})", $html);
        $this->assertStringNotContainsString("almVerCorreccion('{$nota}')", $html, 'Sin corregir: sin marca.');

        $this->corregir($nota, $p->ID_PRODUCTO, 100)->assertCreated();
        $this->assertStringContainsString("almVerCorreccion('{$nota}')", $bitacora());

        // La página entera trae el formulario (quien tiene la clave) y la comparación.
        $this->actingAs($this->usuario())
            ->get(route('almacen.movimientos', ['id_almacen' => $alm->ID_ALMACEN]))
            ->assertOk()
            ->assertSee('id="ajNotaModal"', false)
            ->assertSee('window.almVerCorreccion', false)
            ->assertSee('js/maquinaria/ajuste_nota.js', false);
    }

    public function test_la_nota_original_pinta_la_correccion_en_rojo_y_la_nueva_sale_limpia(): void
    {
        $alm = $this->almacen();
        $p = $this->producto('ELECTRODO');
        $this->inv->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 200);
        $nota = $this->salidaConNota($alm->ID_ALMACEN, [$p->ID_PRODUCTO => 180], null);
        $this->corregir($nota, $p->ID_PRODUCTO, 100, 'SALIERON 100')->assertCreated();

        // Los dos PDF responden, y la original se descarga con su propio nombre.
        $this->actingAs($this->usuario())
            ->get(route('almacen.nota-entrega', ['numero' => $nota, 'version' => 'original', 'descargar' => 1]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="Nota_Entrega_' . $nota . '_ORIGINAL.pdf"');
        $this->actingAs($this->usuario())
            ->get(route('almacen.nota-entrega', ['numero' => $nota]))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename="Nota_Entrega_' . $nota . '.pdf"');

        // Lo que se manda a TCPDF (generar y leer el PDF no añade nada): la celda tachada en
        // rojo y el bloque del pie.
        $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',') ?: '0';
        $fila = (object) ['CANTIDAD' => 180, 'CANTIDAD_CORREGIDA' => 100];
        $celda = view('admin.almacen.partials.nota_cantidad', ['m' => $fila, 'fmt' => $fmt, 'fi' => 8])->render();
        $this->assertMatchesRegularExpression('/color="#b91c1c"><del>180<\/del> <b>100<\/b>/', $celda);
        $limpia = view('admin.almacen.partials.nota_cantidad', ['m' => (object) ['CANTIDAD' => 100], 'fmt' => $fmt, 'fi' => 8])->render();
        $this->assertStringNotContainsString('b91c1c', $limpia);

        $pie = view('admin.almacen.partials.nota_correcciones', [
            'correcciones' => CorreccionNota::with(['producto', 'usuario'])->where('NUMERO_NOTA', $nota)->get(),
            'fmt' => $fmt,
        ])->render();
        $this->assertStringContainsString('ESTA ES LA VERSIÓN ORIGINAL', $pie);
        $this->assertStringContainsString('SALIERON 100', $pie);
        $this->assertStringContainsString('de 180 a 100', $pie);
    }
}
