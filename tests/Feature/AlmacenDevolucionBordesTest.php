<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\FrenteTrabajo;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Usuario;
use App\Http\Middleware\OperacionUnica;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Casos límite de la devolución y de lo que se hace sobre una Nota de Entrega (corregir,
 * eliminar, deshacer, quitar solo del historial) en un almacén PROYECTO que separa el saldo
 * por proyecto: una salida que toma material prestado de otra bolsa, notas con varios
 * productos, decimales, permisos, números de nota en minúscula.
 *
 * Tras cada paso: ningún 500, y el kardex de CADA bolsa encadena y termina en su stock.
 * Todo se revierte al terminar (MySqlTestCase).
 */
class AlmacenDevolucionBordesTest extends MySqlTestCase
{
    private Usuario $u;
    private int $alm;        // almacén PROYECTO con dos frentes (separa por proyecto)
    private int $f1;
    private int $f2;
    private int $p;
    private int $q;          // segundo producto
    private array $pasos = [];
    private array $partida = [];   // [producto][bolsa] => saldo de partida tras un «solo del historial»

    protected function setUp(): void
    {
        parent::setUp();

        $this->u = $this->superAdminGlobal();
        $this->u->forceFill(['PERMISOS' => array_values(array_unique(array_merge((array) $this->u->PERMISOS,
            ['almacen.movimiento', 'almacen.nota.corregir', 'almacen.nota.eliminar'])))])->save();

        $enProyecto = DB::table('almacen_frentes')->join('almacenes', 'almacenes.ID_ALMACEN', '=', 'almacen_frentes.ID_ALMACEN')
            ->where('almacenes.TIPO', Almacen::TIPO_PROYECTO)->pluck('almacen_frentes.ID_FRENTE')->all();
        $libres = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->whereNotIn('ID_FRENTE', $enProyecto)
            ->whereNotIn('ID_FRENTE', $this->u->getFrentesBloqueadosIds())->orderBy('ID_FRENTE')->limit(2)->pluck('ID_FRENTE')->all();
        $this->assertCount(2, $libres);
        [$this->f1, $this->f2] = array_map('intval', $libres);

        $marca = 'BORDES ' . strtoupper(Str::random(6));
        $this->alm = (int) Almacen::forceCreate(['NOMBRE' => "P {$marca}", 'TIPO' => Almacen::TIPO_PROYECTO, 'ESTATUS' => 'ACTIVO'])->ID_ALMACEN;
        DB::table('almacen_frentes')->insert([
            ['ID_ALMACEN' => $this->alm, 'ID_FRENTE' => $this->f1],
            ['ID_ALMACEN' => $this->alm, 'ID_FRENTE' => $this->f2],
        ]);
        $this->assertTrue(Almacen::find($this->alm)->separaPorProyecto());
        $this->p = (int) ProductoInventario::create(['CODIGO' => 'B' . strtoupper(Str::random(7)), 'NOMBRE' => "PERNO {$marca}", 'UM' => 'UND'])->ID_PRODUCTO;
        $this->q = (int) ProductoInventario::create(['CODIGO' => 'B' . strtoupper(Str::random(7)), 'NOMBRE' => "ARANDELA {$marca}", 'UM' => 'UND'])->ID_PRODUCTO;
    }

    // ── Operaciones ─────────────────────────────────────────────────────────────────────

    private function lote(string $tipo, array $lineas, array $extra = [])
    {
        return $this->actingAs($this->u)->postJson(route('almacen.movimientos.lote'), [
            'tipo' => $tipo, 'id_almacen' => $this->alm, 'lineas' => $lineas,
        ] + $extra, [OperacionUnica::CABECERA => (string) Str::uuid()]);
    }

    private function entrada(int $frente, float $n, ?int $prod = null)
    {
        return $this->lote('ENTRADA', [['id_producto' => $prod ?? $this->p, 'cantidad' => $n]], ['id_frente' => $frente]);
    }

    /** Salida al proyecto $frente; $lineas = [id_producto => cantidad]. */
    private function salida(int $frente, array $lineas)
    {
        $ls = [];
        foreach ($lineas as $id => $n) {
            $ls[] = ['id_producto' => $id, 'cantidad' => $n];
        }
        return $this->lote('SALIDA', $ls, ['id_frente_destino' => $frente, 'solicitante' => 'PRUEBA']);
    }

    private function devolver(string $nota, array $lineas)
    {
        $ls = [];
        foreach ($lineas as $id => $n) {
            $ls[] = ['id_producto' => $id, 'cantidad' => $n];
        }
        return $this->actingAs($this->u)->postJson(route('almacen.devolucion.store'), ['numero' => $nota, 'lineas' => $ls, 'motivo' => 'prueba']);
    }

    private function corregir(string $nota, float $n, ?int $prod = null)
    {
        return $this->actingAs($this->u)->postJson(route('almacen.correccion-nota.store'),
            ['numero' => $nota, 'id_producto' => $prod ?? $this->p, 'cantidad' => $n, 'motivo' => 'prueba']);
    }

    private function modal(string $nota, ?int $prod = null)
    {
        return $this->actingAs($this->u)->getJson(route('almacen.ajuste-nota.show', ['numero' => $nota, 'id_producto' => $prod ?? $this->p]));
    }

    // ── Estado ──────────────────────────────────────────────────────────────────────────

    private function bolsa(int $frente, ?int $prod = null): float
    {
        return round((float) AlmacenStock::where('ID_ALMACEN', $this->alm)->where('ID_PRODUCTO', $prod ?? $this->p)
            ->where('ID_FRENTE', $frente)->value('CANTIDAD'), 3);
    }

    private function paso(string $que, $resp): void
    {
        $this->pasos[] = $que . ' → ' . $resp->status();
        $this->assertLessThan(500, $resp->status(), $this->traza('error del servidor: ' . mb_substr((string) $resp->getContent(), 0, 400)));
        $this->invariantes();
    }

    private function traza(string $motivo): string
    {
        return $motivo . "\nPasos:\n  " . implode("\n  ", $this->pasos);
    }

    private function invariantes(): void
    {
        foreach ([$this->p, $this->q] as $prod) {
            $porBolsa = MovimientoInventario::where('ID_ALMACEN', $this->alm)->where('ID_PRODUCTO', $prod)
                ->orderBy('ID_MOVIMIENTO')->get()->groupBy(fn ($m) => (int) $m->ID_FRENTE_SALDO);
            foreach ($porBolsa as $bolsa => $movs) {
                $saldo = $this->partida[$prod][$bolsa] ?? 0.0;
                foreach ($movs as $m) {
                    $this->assertEqualsWithDelta($saldo, (float) $m->CANTIDAD_ANTERIOR, 0.001,
                        $this->traza("bolsa {$bolsa}: #{$m->ID_MOVIMIENTO} ({$m->TIPO}) anterior {$m->CANTIDAD_ANTERIOR}, se esperaba {$saldo}"));
                    $saldo = match (true) {
                        $m->TIPO === MovimientoInventario::TIPO_AJUSTE => (float) $m->CANTIDAD_RESULTANTE,
                        in_array($m->TIPO, MovimientoInventario::TIPOS_ENTRADA, true) => $saldo + (float) $m->CANTIDAD,
                        default => $saldo - (float) $m->CANTIDAD,
                    };
                    $this->assertEqualsWithDelta($saldo, (float) $m->CANTIDAD_RESULTANTE, 0.001,
                        $this->traza("bolsa {$bolsa}: #{$m->ID_MOVIMIENTO} resultante {$m->CANTIDAD_RESULTANTE}, se esperaba {$saldo}"));
                }
                $this->assertEqualsWithDelta($saldo, $this->bolsa($bolsa, $prod), 0.001,
                    $this->traza("bolsa {$bolsa}: stock {$this->bolsa($bolsa, $prod)} ≠ kardex {$saldo}"));
                $this->assertGreaterThanOrEqual(-0.001, $this->bolsa($bolsa, $prod), $this->traza("bolsa {$bolsa} negativa"));
            }
        }
        // Lo devuelto de cada salida, entre 0 y lo que salió.
        foreach (MovimientoInventario::where('ID_ALMACEN', $this->alm)->where('TIPO', 'SALIDA')->get() as $s) {
            $dev = (float) MovimientoInventario::where('TIPO', 'DEVOLUCION')->where('ID_MOVIMIENTO_RELACIONADO', $s->ID_MOVIMIENTO)->sum('CANTIDAD');
            $this->assertLessThanOrEqual((float) $s->CANTIDAD + 0.001, $dev, $this->traza("salida #{$s->ID_MOVIMIENTO}: devuelto {$dev} > {$s->CANTIDAD}"));
        }
    }

    // ── Casos ───────────────────────────────────────────────────────────────────────────

    /** Salida que toma prestado de otro proyecto: la devolución salda primero lo prestado. */
    public function test_devolucion_de_una_salida_con_material_prestado(): void
    {
        $this->paso('entrada 2 a f1', $this->entrada($this->f1, 2)->assertCreated());
        $this->paso('entrada 5 a f2', $this->entrada($this->f2, 5)->assertCreated());
        $this->paso('salida 6 a f1 (presta 4 de f2)', $r = $this->salida($this->f1, [$this->p => 6])->assertCreated());
        $nota = $r->json('numero_nota');
        $this->assertSame([0.0, 1.0], [$this->bolsa($this->f1), $this->bolsa($this->f2)]);

        $this->paso('devolver 3', $this->devolver($nota, [$this->p => 3])->assertCreated());
        $this->assertSame([0.0, 4.0], [$this->bolsa($this->f1), $this->bolsa($this->f2)], $this->traza('lo prestado vuelve primero'));
        $this->paso('devolver 3 más', $this->devolver($nota, [$this->p => 3])->assertCreated());
        $this->assertSame([2.0, 5.0], [$this->bolsa($this->f1), $this->bolsa($this->f2)]);
        $this->paso('devolver 0,001 de más', $this->devolver($nota, [$this->p => 0.001])->assertStatus(422));

        $m = $this->modal($nota)->assertOk()->json();
        $this->assertSame([6, 6, 0], [(int) $m['linea']['entregado'], (int) $m['linea']['devuelto'], (int) $m['linea']['pendiente']]);
        $this->assertNotNull($m['devolucion']['motivo_no']);
    }

    /** Corregir hacia abajo una salida con préstamo: lo que sobra se descuenta primero de lo prestado. */
    public function test_corregir_y_eliminar_una_nota_con_material_prestado(): void
    {
        $this->paso('entrada 2 a f1', $this->entrada($this->f1, 2));
        $this->paso('entrada 5 a f2', $this->entrada($this->f2, 5));
        $this->paso('salida 6 a f1', $r = $this->salida($this->f1, [$this->p => 6]));
        $nota = $r->json('numero_nota');
        $this->paso('devolver 1', $this->devolver($nota, [$this->p => 1]));
        $this->paso('corregir 6 → 3', $this->corregir($nota, 3)->assertCreated());
        // 7 entraron, salen 3 y vuelve 1 → 5.
        $this->assertSame(5.0, round($this->bolsa($this->f1) + $this->bolsa($this->f2), 3), $this->traza('7 − 3 + 1'));
        $this->paso('corregir por debajo de lo devuelto', $this->corregir($nota, 0.5)->assertStatus(422));
        $this->paso('corregir a más de lo que hay', $this->corregir($nota, 50)->assertStatus(422));
        $this->paso('corregir 3 → 5 (f1 en 0: sube de f2)', $this->corregir($nota, 5)->assertCreated());
        $this->assertSame(3.0, round($this->bolsa($this->f1) + $this->bolsa($this->f2), 3), $this->traza('7 − 5 + 1'));
        $this->paso('eliminar la nota', $this->actingAs($this->u)->deleteJson(route('almacen.nota-entrega.destroy', ['numero' => $nota]))->assertOk());
        $this->assertSame([2.0, 5.0], [$this->bolsa($this->f1), $this->bolsa($this->f2)], $this->traza('cada bolsa vuelve a lo de antes de la nota'));
        $this->paso('devolver sobre la nota eliminada', $this->devolver($nota, [$this->p => 1])->assertStatus(404));
        $this->paso('corregir la nota eliminada', $this->corregir($nota, 1)->assertStatus(404));
    }

    /** Dos productos en una nota: o vuelve todo lo pedido, o nada. */
    public function test_devolucion_de_varios_productos_es_todo_o_nada(): void
    {
        $this->paso('entrada p', $this->entrada($this->f1, 10));
        $this->paso('entrada q', $this->entrada($this->f1, 10, $this->q));
        $this->paso('salida p 4 + q 3', $r = $this->salida($this->f1, [$this->p => 4, $this->q => 3])->assertCreated());
        $nota = $r->json('numero_nota');

        $this->paso('devolver p 2 + q 5 (q de más)', $this->devolver($nota, [$this->p => 2, $this->q => 5])->assertStatus(422));
        $this->assertSame([6.0, 7.0], [$this->bolsa($this->f1), $this->bolsa($this->f1, $this->q)], $this->traza('no devolvió nada'));

        $this->paso('producto repetido', $this->actingAs($this->u)->postJson(route('almacen.devolucion.store'), ['numero' => $nota,
            'lineas' => [['id_producto' => $this->p, 'cantidad' => 1], ['id_producto' => $this->p, 'cantidad' => 1]]])->assertStatus(422));
        $this->paso('producto que no está en la nota', $this->devolver($nota, [999999999 => 1])->assertStatus(422));
        $this->paso('cantidad 0', $this->devolver($nota, [$this->p => 0])->assertStatus(422));
        $this->paso('cantidad negativa', $this->devolver($nota, [$this->p => -1])->assertStatus(422));
        $this->paso('cantidad que redondea a 0', $this->devolver($nota, [$this->p => 0.0004])->assertStatus(422));

        $this->paso('devolver p 2 + q 3', $this->devolver(strtolower($nota), [$this->p => 2, $this->q => 3])->assertCreated());
        $this->assertSame([8.0, 10.0], [$this->bolsa($this->f1), $this->bolsa($this->f1, $this->q)]);

        // Deshacer la salida de q: p sigue en la nota y se puede seguir devolviendo.
        $salidaQ = (int) MovimientoInventario::where('NUMERO_NOTA', $nota)->where('ID_PRODUCTO', $this->q)->value('ID_MOVIMIENTO');
        $this->paso('deshacer la salida de q', $this->actingAs($this->u)->deleteJson(route('almacen.movimientos.destroy', ['id' => $salidaQ]))->assertOk());
        $this->assertSame(10.0, $this->bolsa($this->f1, $this->q));
        $this->paso('devolver p 2', $this->devolver($nota, [$this->p => 2])->assertCreated());
        $this->assertSame(10.0, $this->bolsa($this->f1));
    }

    /** Decimales: lo devuelto suma exacto y nunca pasa de lo entregado. */
    public function test_decimales(): void
    {
        $this->paso('entrada 1,5', $this->entrada($this->f1, 1.5));
        $this->paso('salida 1,234', $r = $this->salida($this->f1, [$this->p => 1.234]));
        $nota = $r->json('numero_nota');
        $this->paso('devolver 0,617', $this->devolver($nota, [$this->p => 0.617])->assertCreated());
        $this->paso('devolver 0,617', $this->devolver($nota, [$this->p => 0.617])->assertCreated());
        $this->paso('devolver 0,001', $this->devolver($nota, [$this->p => 0.001])->assertStatus(422));
        $this->assertSame(1.5, $this->bolsa($this->f1));
        $this->paso('corregir a 0,617', $this->corregir($nota, 0.617)->assertStatus(422)); // por debajo de lo devuelto
    }

    /** Sin la clave o sin ver el almacén: rechazo limpio, nada cambia. */
    public function test_permisos(): void
    {
        $this->paso('entrada', $this->entrada($this->f1, 5));
        $this->paso('salida 3', $r = $this->salida($this->f1, [$this->p => 3]));
        $nota = $r->json('numero_nota');

        $otro = $this->superAdminGlobal();
        $otro->forceFill(['PERMISOS' => array_values(array_diff((array) $otro->PERMISOS, ['almacen.movimiento', 'almacen.nota.corregir']))])->save();
        if (!$otro->can('almacen.movimiento')) {
            $this->pasos[] = 'devolver sin clave';
            $this->actingAs($otro)->postJson(route('almacen.devolucion.store'), ['numero' => $nota, 'lineas' => [['id_producto' => $this->p, 'cantidad' => 1]]])->assertForbidden();
        }
        $this->assertSame(2.0, $this->bolsa($this->f1));
    }

    /** «Solo del historial» en una bolsa y después corregir una nota con préstamo de esa bolsa. */
    public function test_solo_historial_en_bolsa_y_despues_corregir(): void
    {
        $this->paso('entrada 2 a f1', $this->entrada($this->f1, 2));
        $this->paso('entrada 5 a f2', $this->entrada($this->f2, 5));
        $e3 = $this->entrada($this->f2, 3);
        $this->paso('entrada 3 a f2', $e3);
        $id3 = (int) MovimientoInventario::where('ID_ALMACEN', $this->alm)->where('ID_PRODUCTO', $this->p)->max('ID_MOVIMIENTO');
        $this->paso('salida 6 a f1', $r = $this->salida($this->f1, [$this->p => 6]));
        $nota = $r->json('numero_nota');
        $this->assertSame([0.0, 4.0], [$this->bolsa($this->f1), $this->bolsa($this->f2)]);

        $this->partida[$this->p][$this->f2] = 3.0;
        $this->paso('quitar la entrada de 3 solo del historial',
            $this->actingAs($this->u)->deleteJson(route('almacen.movimientos.destroyHistorial', ['id' => $id3]))->assertOk());
        $this->assertSame(4.0, $this->bolsa($this->f2));
        $this->paso('corregir 6 → 4', $this->corregir($nota, 4)->assertCreated());
        $this->assertSame([0.0, 6.0], [$this->bolsa($this->f1), $this->bolsa($this->f2)], $this->traza('f2 recupera lo prestado sin perder las 3'));
        $this->paso('devolver 4', $this->devolver($nota, [$this->p => 4])->assertCreated());
        $this->assertSame([2.0, 8.0], [$this->bolsa($this->f1), $this->bolsa($this->f2)]);
    }

    // ── Al azar ─────────────────────────────────────────────────────────────────────────

    public static function semillas(): array
    {
        return array_map(fn ($s) => [$s], [3, 17, 29, 43, 61, 79, 97, 113, 131, 151]);
    }

    /**
     * Todas las operaciones en cualquier orden. El deshacer se limita a SALIDAS: deshacer algo
     * que sumó stock ya consumido deja negativo a propósito (decisión pendiente del cliente).
     * @dataProvider semillas
     */
    public function test_al_azar_en_almacen_por_proyecto(int $semilla): void
    {
        mt_srand($semilla);
        $frentes = [$this->f1, $this->f2];
        $prods   = [$this->p, $this->q];
        $azar    = fn (array $xs) => $xs[mt_rand(0, count($xs) - 1)];
        $cant    = fn () => round(mt_rand(1, 60) / 10, 1);

        for ($i = 0; $i < 40; $i++) {
            $notas = MovimientoInventario::where('ID_ALMACEN', $this->alm)->where('TIPO', 'SALIDA')
                ->whereNotNull('NUMERO_NOTA')->distinct()->pluck('NUMERO_NOTA')->all();
            $op = mt_rand(0, 9);
            if (!$notas && $op >= 4 && $op <= 6) {
                $op = 2;   // sin notas todavía: una salida
            }
            if ($op <= 1) {
                $f = $azar($frentes); $pr = $azar($prods); $n = $cant();
                $this->paso("entrada {$n} de " . ($pr === $this->p ? 'p' : 'q') . " a f{$f}", $this->entrada($f, $n, $pr));
            } elseif ($op <= 3) {
                $f = $azar($frentes);
                $lineas = mt_rand(0, 1) ? [$azar($prods) => $cant()] : [$this->p => $cant(), $this->q => $cant()];
                $resp = $this->salida($f, $lineas);
                if ($resp->status() === 422) {
                    // Solo se rechaza si de verdad no alcanza sumando todas las bolsas.
                    $alcanza = collect($lineas)->every(fn ($n, $id) => (float) AlmacenStock::where('ID_ALMACEN', $this->alm)
                        ->where('ID_PRODUCTO', $id)->sum('CANTIDAD') + 0.001 >= $n);
                    $this->assertFalse($alcanza, $this->traza('salida rechazada habiendo stock: ' . $resp->json('message')));
                }
                $this->paso("salida a f{$f} " . json_encode($lineas), $resp);
            } elseif ($op === 4) {
                $nota = $azar($notas);
                $pr = (int) $azar(MovimientoInventario::where('NUMERO_NOTA', $nota)->distinct()->pluck('ID_PRODUCTO')->all());
                $n = $cant();
                $this->paso("devolver {$n} de {$nota}", $this->devolver($nota, [$pr => $n]));
            } elseif ($op === 5) {
                $nota = $azar($notas);
                $pr = (int) $azar(MovimientoInventario::where('NUMERO_NOTA', $nota)->distinct()->pluck('ID_PRODUCTO')->all());
                $n = $cant();
                $this->paso("corregir {$nota} a {$n}", $this->corregir($nota, $n, $pr));
            } elseif ($op === 6 && mt_rand(0, 2) === 0) {
                $nota = $azar($notas);
                $this->paso("eliminar {$nota}", $this->actingAs($this->u)->deleteJson(route('almacen.nota-entrega.destroy', ['numero' => $nota])));
            } elseif ($op === 7) {
                $ids = MovimientoInventario::where('ID_ALMACEN', $this->alm)->where('TIPO', 'SALIDA')->pluck('ID_MOVIMIENTO')->all();
                if ($ids) {
                    $id = (int) $azar($ids);
                    $this->paso("deshacer salida #{$id}", $this->actingAs($this->u)->deleteJson(route('almacen.movimientos.destroy', ['id' => $id])));
                }
            } elseif ($op === 8) {
                $f = $azar($frentes); $pr = $azar($prods); $n = $cant();
                $this->paso("auditoría f{$f}: {$n}", $this->lote('AJUSTE', [['id_producto' => $pr, 'cantidad' => $n, 'id_frente_saldo' => $f]]));
            } else {
                $todos = MovimientoInventario::where('ID_ALMACEN', $this->alm)->pluck('ID_MOVIMIENTO')->all();
                if ($todos) {
                    $mov = MovimientoInventario::find((int) $azar($todos));
                    $filas = MovimientoInventario::whereIn('ID_MOVIMIENTO', app(\App\Services\InventarioService::class)->idsMovimientoYContraparte($mov))->get();
                    $resp = $this->actingAs($this->u)->deleteJson(route('almacen.movimientos.destroyHistorial', ['id' => $mov->ID_MOVIMIENTO]));
                    if ($resp->isOk()) {
                        foreach ($filas as $fila) {
                            $b = (int) $fila->ID_FRENTE_SALDO;
                            $this->partida[(int) $fila->ID_PRODUCTO][$b] = round(($this->partida[(int) $fila->ID_PRODUCTO][$b] ?? 0.0)
                                + (float) $fila->CANTIDAD_RESULTANTE - (float) $fila->CANTIDAD_ANTERIOR, 3);
                        }
                    }
                    $this->paso("solo historial #{$mov->ID_MOVIMIENTO} ({$mov->TIPO})", $resp);
                }
            }
        }
        $this->assertNotEmpty($this->pasos);
    }
}
