<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\FrenteTrabajo;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Traspaso;
use App\Models\Usuario;
use App\Http\Middleware\OperacionUnica;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Las operaciones de /admin/almacen/movimientos encadenadas en distinto orden sobre el MISMO
 * producto: entrada, salida con nota, envío a otro almacén (recibir total/parcial, cancelar),
 * devolución, corrección de nota, eliminar nota, deshacer, auditoría (ajuste).
 *
 * Después de CADA paso se comprueba lo que nunca puede romperse, pase lo que pase:
 *   · ninguna petición da 500 (lo inválido se rechaza con 4xx);
 *   · el kardex de cada almacén encadena (anterior = resultante de la fila previa) y su último
 *     saldo es el stock guardado;
 *   · el stock no queda negativo;
 *   · lo devuelto de una salida no pasa de lo que salió;
 *   · lo que entró al almacén destino por un envío es lo que se dio por recibido.
 * Secuencias al azar con semilla fija: si una falla, el mensaje trae los pasos para repetirla.
 * Todo se revierte al terminar (MySqlTestCase).
 */
class AlmacenOperacionesEncadenadasTest extends MySqlTestCase
{
    private Usuario $u;
    private int $a;          // almacén GENERAL de trabajo
    private int $b;          // almacén PROYECTO que recibe los envíos
    private int $fLibre;     // frente destino de salidas (sin almacén PROYECTO)
    private int $fB;         // frente del almacén B
    private int $p;          // el producto
    private array $pasos = [];
    /** Saldo de partida del kardex por almacén: 0, salvo que algo se quitó solo del historial. */
    private array $partida = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->u = $this->superAdminGlobal();
        // Las claves exclusivas que no trae super.admin (solo en esta transacción).
        $this->u->forceFill(['PERMISOS' => array_values(array_unique(array_merge((array) $this->u->PERMISOS,
            ['almacen.movimiento', 'almacen.nota.corregir', 'almacen.nota.eliminar', 'traspaso.recibir'])))])->save();

        $enProyecto = DB::table('almacen_frentes')->join('almacenes', 'almacenes.ID_ALMACEN', '=', 'almacen_frentes.ID_ALMACEN')
            ->where('almacenes.TIPO', Almacen::TIPO_PROYECTO)->pluck('almacen_frentes.ID_FRENTE')->all();
        $libres = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->whereNotIn('ID_FRENTE', $enProyecto)
            ->whereNotIn('ID_FRENTE', $this->u->getFrentesBloqueadosIds())->orderBy('ID_FRENTE')->limit(2)->pluck('ID_FRENTE')->all();
        $this->assertCount(2, $libres, 'Hacen falta dos frentes activos sin almacén PROYECTO.');
        [$this->fLibre, $this->fB] = array_map('intval', $libres);

        $marca = 'ENCADENADO ' . strtoupper(Str::random(6));
        $this->a = (int) Almacen::forceCreate(['NOMBRE' => "A {$marca}", 'TIPO' => Almacen::TIPO_GENERAL, 'ESTATUS' => 'ACTIVO'])->ID_ALMACEN;
        $this->b = (int) Almacen::forceCreate(['NOMBRE' => "B {$marca}", 'TIPO' => Almacen::TIPO_PROYECTO, 'ESTATUS' => 'ACTIVO'])->ID_ALMACEN;
        DB::table('almacen_frentes')->insert(['ID_ALMACEN' => $this->b, 'ID_FRENTE' => $this->fB]);
        $this->p = (int) ProductoInventario::create(['CODIGO' => 'E' . strtoupper(Str::random(7)), 'NOMBRE' => "TORNILLO {$marca}", 'UM' => 'UND'])->ID_PRODUCTO;
    }

    // ── Operaciones, como las manda la pantalla ─────────────────────────────────────────

    private function lote(string $tipo, int $almacen, float $cantidad, array $extra = [])
    {
        return $this->actingAs($this->u)->postJson(route('almacen.movimientos.lote'), [
            'tipo' => $tipo, 'id_almacen' => $almacen,
            'lineas' => [['id_producto' => $this->p, 'cantidad' => $cantidad]],
        ] + $extra, [OperacionUnica::CABECERA => (string) Str::uuid()]);
    }

    private function entrada(float $n) { return $this->lote('ENTRADA', $this->a, $n); }
    private function salida(float $n, ?int $alm = null) { return $this->lote('SALIDA', $alm ?? $this->a, $n, ['id_frente_destino' => $alm === $this->b ? $this->fB : $this->fLibre, 'solicitante' => 'PRUEBA']); }
    private function enviar(float $n) { return $this->lote('SALIDA', $this->a, $n, ['id_frente_destino' => $this->fB, 'id_almacen_destino' => $this->b, 'solicitante' => 'PRUEBA']); }
    private function ajuste(float $conteo) { return $this->lote('AJUSTE', $this->a, $conteo); }

    private function recibir(Traspaso $t, ?float $cantidad = null)
    {
        $lineas = $t->lineas()->get()->map(fn ($l) => [
            'id_linea' => $l->ID_LINEA, 'cantidad_recibida' => $cantidad ?? (float) $l->CANTIDAD_ENVIADA,
        ])->all();
        return $this->actingAs($this->u)->postJson(route('almacen.recepcion.recibir', $t->ID_TRASPASO), ['lineas' => $lineas]);
    }

    private function cancelar(Traspaso $t) { return $this->actingAs($this->u)->postJson(route('almacen.recepcion.cancelar', $t->ID_TRASPASO), ['notas' => 'prueba']); }
    private function devolver(string $nota, float $n) { return $this->actingAs($this->u)->postJson(route('almacen.devolucion.store'), ['numero' => $nota, 'lineas' => [['id_producto' => $this->p, 'cantidad' => $n]], 'motivo' => 'prueba']); }
    private function corregir(string $nota, float $n) { return $this->actingAs($this->u)->postJson(route('almacen.correccion-nota.store'), ['numero' => $nota, 'id_producto' => $this->p, 'cantidad' => $n, 'motivo' => 'prueba']); }
    private function eliminarNota(string $nota) { return $this->actingAs($this->u)->deleteJson(route('almacen.nota-entrega.destroy', ['numero' => $nota])); }
    private function deshacer(int $id) { return $this->actingAs($this->u)->deleteJson(route('almacen.movimientos.destroy', ['id' => $id])); }

    // ── Estado ──────────────────────────────────────────────────────────────────────────

    private function stock(int $alm): float
    {
        return round((float) AlmacenStock::where('ID_ALMACEN', $alm)->where('ID_PRODUCTO', $this->p)->sum('CANTIDAD'), 3);
    }

    private function movs(int $alm)
    {
        return MovimientoInventario::where('ID_ALMACEN', $alm)->where('ID_PRODUCTO', $this->p)->orderBy('ID_MOVIMIENTO')->get();
    }

    /** Notas de salida (no de envío) que siguen vigentes en A. */
    private function notas(): array
    {
        return MovimientoInventario::where('ID_ALMACEN', $this->a)->where('ID_PRODUCTO', $this->p)
            ->where('TIPO', MovimientoInventario::TIPO_SALIDA)->whereNotNull('NUMERO_NOTA')
            ->distinct()->pluck('NUMERO_NOTA')->all();
    }

    private function envios(string $estado): array
    {
        return Traspaso::where('ID_ALMACEN_ORIGEN', $this->a)->where('ESTADO', $estado)->get()->all();
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
        foreach (['A' => $this->a, 'B' => $this->b] as $n => $alm) {
            $saldo = $this->partida[$alm] ?? 0.0;
            foreach ($this->movs($alm) as $m) {
                $this->assertEqualsWithDelta($saldo, (float) $m->CANTIDAD_ANTERIOR, 0.001,
                    $this->traza("kardex de {$n} roto en #{$m->ID_MOVIMIENTO} ({$m->TIPO}): anterior {$m->CANTIDAD_ANTERIOR}, se esperaba {$saldo}"));
                $esperado = match (true) {
                    $m->TIPO === MovimientoInventario::TIPO_AJUSTE => (float) $m->CANTIDAD_RESULTANTE,
                    in_array($m->TIPO, MovimientoInventario::TIPOS_ENTRADA, true) => $saldo + (float) $m->CANTIDAD,
                    default => $saldo - (float) $m->CANTIDAD,
                };
                $this->assertEqualsWithDelta($esperado, (float) $m->CANTIDAD_RESULTANTE, 0.001,
                    $this->traza("kardex de {$n}: #{$m->ID_MOVIMIENTO} ({$m->TIPO} {$m->CANTIDAD}) resultante {$m->CANTIDAD_RESULTANTE}, se esperaba {$esperado}"));
                $saldo = (float) $m->CANTIDAD_RESULTANTE;
            }
            $this->assertEqualsWithDelta($saldo, $this->stock($alm), 0.001, $this->traza("stock de {$n} ({$this->stock($alm)}) ≠ último saldo del kardex ({$saldo})"));
            $this->assertGreaterThanOrEqual(-0.001, $this->stock($alm), $this->traza("stock de {$n} negativo"));
        }

        // Lo devuelto de cada salida no pasa de lo que salió.
        $salidas = MovimientoInventario::where('ID_ALMACEN', $this->a)->where('ID_PRODUCTO', $this->p)->where('TIPO', MovimientoInventario::TIPO_SALIDA)->get();
        foreach ($salidas as $s) {
            $devuelto = (float) MovimientoInventario::where('TIPO', MovimientoInventario::TIPO_DEVOLUCION)->where('ID_MOVIMIENTO_RELACIONADO', $s->ID_MOVIMIENTO)->sum('CANTIDAD');
            $this->assertLessThanOrEqual((float) $s->CANTIDAD + 0.001, $devuelto, $this->traza("salida #{$s->ID_MOVIMIENTO}: devuelto {$devuelto} > salió {$s->CANTIDAD}"));
        }
        // Ninguna devolución huérfana (su salida se borró y ella quedó sumando stock).
        $huerfanas = MovimientoInventario::where('ID_ALMACEN', $this->a)->where('ID_PRODUCTO', $this->p)->where('TIPO', MovimientoInventario::TIPO_DEVOLUCION)
            ->whereNotIn('ID_MOVIMIENTO_RELACIONADO', $salidas->pluck('ID_MOVIMIENTO'))->count();
        $this->assertSame(0, $huerfanas, $this->traza('quedó una devolución sin su salida'));

        // Lo que entró a B por cada envío = lo que se dio por recibido en sus líneas.
        foreach (Traspaso::where('ID_ALMACEN_ORIGEN', $this->a)->get() as $t) {
            $recibido = (float) $t->lineas()->sum('CANTIDAD_RECIBIDA');
            $entro    = (float) MovimientoInventario::where('ID_TRASPASO', $t->ID_TRASPASO)->where('ID_ALMACEN', $this->b)
                ->where('TIPO', MovimientoInventario::TIPO_TRASPASO_ENTRADA)->sum('CANTIDAD');
            $this->assertEqualsWithDelta($recibido, $entro, 0.001, $this->traza("envío #{$t->ID_TRASPASO} ({$t->ESTADO}): recibido {$recibido}, entró a B {$entro}"));
        }
    }

    // ── Escenarios fijos ────────────────────────────────────────────────────────────────

    public function test_salida_devolucion_correccion_y_eliminar_la_nota(): void
    {
        $this->paso('entrada 10', $this->entrada(10)->assertCreated());
        $this->paso('salida 5', $r = $this->salida(5)->assertCreated());
        $nota = $r->json('numero_nota');
        $this->assertSame(5.0, $this->stock($this->a));

        $this->paso('devolver 2', $this->devolver($nota, 2)->assertCreated());
        $this->assertSame(7.0, $this->stock($this->a));
        $this->paso('corregir a 4', $this->corregir($nota, 4));
        $this->paso('devolver 9 (de más)', $this->devolver($nota, 9)->assertStatus(422));
        $this->paso('eliminar la nota', $this->eliminarNota($nota));
        $this->assertSame(10.0, $this->stock($this->a), $this->traza('eliminar la nota tiene que dejar el stock como antes de la salida'));
    }

    public function test_deshacer_la_salida_se_lleva_sus_devoluciones(): void
    {
        $this->paso('entrada 8', $this->entrada(8));
        $this->paso('salida 6', $r = $this->salida(6));
        $nota = $r->json('numero_nota');
        $this->paso('devolver 1', $this->devolver($nota, 1));
        $this->paso('devolver 2', $this->devolver($nota, 2));
        $salida = MovimientoInventario::where('NUMERO_NOTA', $nota)->where('TIPO', 'SALIDA')->value('ID_MOVIMIENTO');
        $this->paso('deshacer la salida', $this->deshacer((int) $salida)->assertOk());
        $this->assertSame(8.0, $this->stock($this->a));
        $this->paso('eliminar la nota ya deshecha', $this->eliminarNota($nota)->assertStatus(404));
    }

    public function test_envio_recibido_a_medias_luego_deshecho_y_cancelado(): void
    {
        $this->paso('entrada 10', $this->entrada(10));
        $this->paso('enviar 6', $this->enviar(6)->assertCreated());
        [$t] = $this->envios(Traspaso::ESTADO_ENVIADO);
        $this->paso('recibir 4 de 6', $this->recibir($t, 4));
        $this->assertSame(4.0, $this->stock($this->b));
        $this->paso('cancelar el envío ya recibido', $this->cancelar($t->fresh()));
        $entrada = MovimientoInventario::where('ID_TRASPASO', $t->ID_TRASPASO)->where('TIPO', 'TRASPASO_ENTRADA')->value('ID_MOVIMIENTO');
        if ($entrada) {
            $this->paso('deshacer la entrada del envío', $this->deshacer((int) $entrada));
        }
    }

    public function test_auditoria_entre_salidas_y_deshacer_la_entrada_consumida(): void
    {
        $this->paso('entrada 5', $r = $this->entrada(5));
        $idEntrada = (int) $this->movs($this->a)->last()->ID_MOVIMIENTO;
        $this->paso('salida 3', $r1 = $this->salida(3));
        $this->paso('auditoría: cuento 7', $this->ajuste(7));
        $this->paso('salida 2', $this->salida(2));
        $this->paso('devolver 3 de la 1.ª', $this->devolver($r1->json('numero_nota'), 3));
        $this->paso('deshacer la entrada ya consumida', $this->deshacer($idEntrada));
        $this->paso('corregir la 1.ª a 1', $this->corregir($r1->json('numero_nota'), 1));
    }

    // ── Secuencias al azar ──────────────────────────────────────────────────────────────

    public static function semillas(): array
    {
        return array_map(fn ($s) => [$s], [11, 23, 37, 41, 59, 67, 73, 89]);
    }

    /** @dataProvider semillas */
    public function test_operaciones_al_azar_en_cualquier_orden(int $semilla): void
    {
        mt_srand($semilla);
        $azar = fn (array $xs) => $xs ? $xs[mt_rand(0, count($xs) - 1)] : null;
        $n    = fn (int $max) => mt_rand(1, $max);

        $this->paso('entrada 20', $this->entrada(20));
        for ($i = 0; $i < 30; $i++) {
            switch (mt_rand(0, 10)) {
                case 0: $this->paso("entrada", $this->entrada($n(8))); break;
                case 1: $this->paso("salida", $this->salida($n(8))); break;
                case 2: $this->paso("enviar a B", $this->enviar($n(5))); break;
                case 3: if ($t = $azar($this->envios(Traspaso::ESTADO_ENVIADO))) $this->paso("recibir #{$t->ID_TRASPASO} total", $this->recibir($t)); break;
                case 4: if ($t = $azar($this->envios(Traspaso::ESTADO_ENVIADO))) $this->paso("recibir #{$t->ID_TRASPASO} a medias", $this->recibir($t, 1)); break;
                case 5: if ($t = $azar(array_merge($this->envios(Traspaso::ESTADO_ENVIADO), $this->envios(Traspaso::ESTADO_RECIBIDO_PARCIAL)))) $this->paso("cancelar #{$t->ID_TRASPASO} ({$t->ESTADO})", $this->cancelar($t)); break;
                case 6: if ($nota = $azar($this->notas())) $this->paso("devolver de {$nota}", $this->devolver($nota, $n(4))); break;
                case 7: if ($nota = $azar($this->notas())) $this->paso("corregir {$nota}", $this->corregir($nota, $n(8))); break;
                case 8: if ($nota = $azar($this->notas())) $this->paso("eliminar {$nota}", $this->eliminarNota($nota)); break;
                case 9: if ($m = $azar(array_merge($this->movs($this->a)->all(), $this->movs($this->b)->all()))) $this->paso("deshacer #{$m->ID_MOVIMIENTO} {$m->TIPO}", $this->deshacer((int) $m->ID_MOVIMIENTO)); break;
                case 10: $this->paso("auditoría", $this->ajuste(mt_rand(0, 25))); break;
            }
            if ($i % 6 === 5) {
                $this->paso('salida de B', $this->salida($n(3), $this->b));
            }
        }
        $this->assertNotEmpty($this->pasos);
    }

    /** Quitar una entrada solo del historial y DESPUÉS corregir: el stock no se mueve por ese borrado. */
    public function test_solo_historial_y_luego_corregir_conserva_el_stock(): void
    {
        $this->paso('entrada 10', $this->entrada(10));
        $this->paso('entrada 5', $this->entrada(5));
        $e2 = (int) $this->movs($this->a)->last()->ID_MOVIMIENTO;
        $this->paso('salida 3', $r = $this->salida(3));
        $this->partida[$this->a] = 5.0;   // lo que hizo la entrada quitada pasa a la partida
        $this->paso('quitar la 2.ª entrada solo del historial',
            $this->actingAs($this->u)->deleteJson(route('almacen.movimientos.destroyHistorial', ['id' => $e2])));
        $this->assertSame(12.0, $this->stock($this->a));
        $this->paso('corregir la nota 3 → 2', $this->corregir($r->json('numero_nota'), 2));
        $this->assertSame(13.0, $this->stock($this->a), $this->traza('la corrección se comió lo quitado solo del historial'));
    }

    /** Una devolución de una nota ya eliminada no se deshace sola: dejaría la nota anulada contando como consumo. */
    public function test_no_se_deshace_la_devolucion_de_una_nota_eliminada(): void
    {
        $this->paso('entrada 10', $this->entrada(10));
        $this->paso('salida 5', $r = $this->salida(5));
        $nota = $r->json('numero_nota');
        $this->paso('devolver 2', $this->devolver($nota, 2));
        $this->paso('eliminar la nota', $this->eliminarNota($nota)->assertOk());
        $this->assertSame(10.0, $this->stock($this->a));
        foreach (MovimientoInventario::where('TIPO', 'DEVOLUCION')->where('ID_ALMACEN', $this->a)->pluck('ID_MOVIMIENTO') as $d) {
            $this->paso('deshacer una devolución de la nota eliminada', $this->deshacer((int) $d)->assertStatus(422));
            $aviso = $this->actingAs($this->u)->getJson(route('almacen.movimientos.impactoDeshacer', ['id' => $d]))->json('avisos.0');
            $this->assertStringContainsString('fue eliminada', (string) $aviso);
        }
        $this->assertSame(10.0, $this->stock($this->a));
        $salida = (int) MovimientoInventario::where('TIPO', 'SALIDA')->where('ID_ALMACEN', $this->a)->value('ID_MOVIMIENTO');
        $this->paso('deshacer la salida (se lleva sus devoluciones)', $this->deshacer($salida)->assertOk());
        $this->assertSame(10.0, $this->stock($this->a));
    }

    public function test_quitar_solo_del_historial_no_toca_el_stock(): void
    {
        $this->paso('entrada 9', $this->entrada(9));
        $this->paso('salida 4', $this->salida(4));
        $id = (int) $this->movs($this->a)->last()->ID_MOVIMIENTO;
        $this->actingAs($this->u)->deleteJson(route('almacen.movimientos.destroyHistorial', ['id' => $id]))->assertOk();
        $this->assertNull(MovimientoInventario::find($id));
        $this->assertSame(5.0, $this->stock($this->a), 'quitar solo del historial deja el stock como estaba');
    }
}
