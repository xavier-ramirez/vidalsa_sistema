<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Traspaso;
use App\Models\Usuario;
use App\Services\InventarioService;
use App\Support\NotasReservadas;
use App\Support\TandasNota;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Una Nota de Entrega lleva hasta TandasNota::RENGLONES materiales: con más, la salida se emite
 * en varias notas, cada una con su número (pedido del cliente: «por nota de entrega son 20
 * materiales»). Todo corre en la transacción de la prueba y se revierte al terminar.
 */
class TandasNotaTest extends MySqlTestCase
{
    private function usuario(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.movimiento') && $u->can('super.admin') && Almacen::usuarioEsGlobal($u));
        $this->assertNotNull($u, 'Hace falta un usuario GLOBAL con almacen.movimiento y super.admin.');
        return $u;
    }

    /** Un almacén general con $n productos de 10 unidades; devuelve [almacén, ids]. */
    private function almacenCon(int $n): array
    {
        $marca = strtoupper(Str::random(6));
        $alm = Almacen::create(['NOMBRE' => "ALMACEN TANDAS {$marca}", 'TIPO' => Almacen::TIPO_GENERAL]);
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $p = ProductoInventario::create(['CODIGO' => "T{$marca}{$i}", 'NOMBRE' => "MATERIAL TANDAS {$marca} {$i}", 'UM' => 'UND']);
            app(InventarioService::class)->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 10);
            $ids[] = $p->ID_PRODUCTO;
        }
        return [$alm, $ids];
    }

    private function lineas(array $ids): array
    {
        return array_map(fn ($id) => ['id_producto' => $id, 'cantidad' => 1], $ids);
    }

    public function test_reparte_por_renglon_sin_partir_ninguno(): void
    {
        $lineas = array_map(fn ($i) => ['id_producto' => $i, 'cantidad' => 1], range(1, 45));
        $lineas[] = ['id_producto' => 1, 'cantidad' => 2];                          // mismo renglón que el primero
        $lineas[] = ['id_producto' => 1, 'cantidad' => 1, 'numero_parte' => 'P-9']; // otro renglón (otra parte)
        $tandas = TandasNota::partir($lineas);

        $renglones = fn ($t) => count(array_unique(array_map([TandasNota::class, 'renglon'], $t)));
        $this->assertSame([20, 20, 6], array_map($renglones, $tandas));
        $this->assertContains(['id_producto' => 1, 'cantidad' => 2], $tandas[0], 'la línea repetida va con su renglón, en la primera');
        $this->assertCount(1, TandasNota::partir(array_slice($lineas, 0, 20)), 'con 20 justos, una sola nota');
    }

    public function test_la_nota_sin_internet_reparte_con_el_mismo_tope(): void
    {
        $js = file_get_contents(public_path('js/maquinaria/nota_entrega_local.js'));
        $this->assertMatchesRegularExpression('/const RENGLONES_POR_NOTA = ' . TandasNota::RENGLONES . ';/', $js);
    }

    public function test_una_salida_de_mas_de_20_sale_en_dos_notas(): void
    {
        [$alm, $ids] = $this->almacenCon(23);
        $r = $this->actingAs($this->usuario())->postJson(route('almacen.movimientos.lote'), [
            'tipo' => 'SALIDA', 'id_almacen' => $alm->ID_ALMACEN, 'lineas' => $this->lineas($ids),
        ])->assertCreated();

        $numeros = array_column($r->json('notas'), 'numero_nota');
        $this->assertCount(2, array_unique($numeros));
        $this->assertSame($numeros[0], $r->json('numero_nota'));
        $this->assertStringContainsString('en 2 Notas de Entrega', $r->json('message'));
        // Los 20 primeros (en el orden de la pantalla) en la primera; los otros 3 en la segunda.
        $this->assertEqualsCanonicalizing(array_slice($ids, 0, 20), MovimientoInventario::where('NUMERO_NOTA', $numeros[0])->pluck('ID_PRODUCTO')->all());
        $this->assertEqualsCanonicalizing(array_slice($ids, 20), MovimientoInventario::where('NUMERO_NOTA', $numeros[1])->pluck('ID_PRODUCTO')->all());
        foreach ($r->json('notas') as $nota) {
            $this->actingAs($this->usuario())->get($nota['nota_url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }
    }

    public function test_hasta_20_sigue_siendo_una_sola_nota(): void
    {
        [$alm, $ids] = $this->almacenCon(20);
        $r = $this->actingAs($this->usuario())->postJson(route('almacen.movimientos.lote'), [
            'tipo' => 'SALIDA', 'id_almacen' => $alm->ID_ALMACEN, 'lineas' => $this->lineas($ids),
        ])->assertCreated();
        $this->assertCount(1, $r->json('notas'));
        $this->assertSame(20, MovimientoInventario::where('NUMERO_NOTA', $r->json('numero_nota'))->count());
    }

    public function test_a_otro_almacen_cada_nota_es_su_traspaso(): void
    {
        [$alm, $ids] = $this->almacenCon(21);
        $destino = Almacen::create(['NOMBRE' => 'DESTINO TANDAS ' . strtoupper(Str::random(6)), 'TIPO' => Almacen::TIPO_PROYECTO]);
        $frente  = DB::table('frentes_trabajo')->insertGetId(['NOMBRE_FRENTE' => 'FRENTE TANDAS ' . strtoupper(Str::random(6))]);
        DB::table('almacen_frentes')->insert(['ID_ALMACEN' => $destino->ID_ALMACEN, 'ID_FRENTE' => $frente]);

        $r = $this->actingAs($this->usuario())->postJson(route('almacen.movimientos.lote'), [
            'tipo' => 'SALIDA', 'id_almacen' => $alm->ID_ALMACEN, 'id_frente_destino' => $frente, 'lineas' => $this->lineas($ids),
        ])->assertCreated();

        $notas = $r->json('notas');
        $this->assertCount(2, $notas);
        $this->assertNotSame($notas[0]['numero_traspaso'], $notas[1]['numero_traspaso']);
        foreach ($notas as $i => $nota) {
            $tr = Traspaso::where('NUMERO', $nota['numero_traspaso'])->firstOrFail();
            $this->assertSame($nota['numero_nota'], $tr->REFERENCIA, 'cada traspaso lleva su nota');
            $this->assertSame($i === 0 ? 20 : 1, MovimientoInventario::where('NUMERO_NOTA', $nota['numero_nota'])->where('TIPO', 'TRASPASO_SALIDA')->count());
        }
    }

    public function test_sin_internet_usa_un_numero_apartado_por_nota_y_rechaza_si_no_cuadran(): void
    {
        [$alm, $ids] = $this->almacenCon(21);
        $u = $this->usuario();
        $numeros = $this->actingAs($u)->postJson(route('almacen.notas-reservadas'), ['dispositivo' => (string) Str::uuid()])->json('numeros');
        $salida = fn (array $extra) => $this->actingAs($u)->postJson(route('almacen.movimientos.lote'), [
            'tipo' => 'SALIDA', 'id_almacen' => $alm->ID_ALMACEN, 'lineas' => $this->lineas($ids),
        ] + $extra);

        // Un solo número para dos notas: el segundo papel no tendría el suyo. Nada se registra.
        $salida(['numero_nota_reservado' => $numeros[0]])->assertStatus(422)->assertJsonFragment(['message' => 'La salida trae 1 Nota(s) apartada(s) y se emite en 2. Vuelve a hacer la vista previa y regístrala de nuevo.']);
        $this->assertSame(0, MovimientoInventario::whereIn('NUMERO_NOTA', $numeros)->count());

        $r = $salida(['numero_nota_reservado' => $numeros[0], 'numeros_nota_extra' => [$numeros[1]]])->assertCreated();
        $this->assertSame([$numeros[0], $numeros[1]], array_column($r->json('notas'), 'numero_nota'));
        $this->assertSame(20, MovimientoInventario::where('NUMERO_NOTA', $numeros[0])->count());
        $this->assertSame(1, MovimientoInventario::where('NUMERO_NOTA', $numeros[1])->count());
        $this->assertSame(2, DB::table(NotasReservadas::TABLA)->whereIn('NUMERO_NOTA', [$numeros[0], $numeros[1]])->whereNotNull('USADA_AT')->count());
    }

    public function test_la_vista_previa_muestra_cada_nota_en_su_hoja(): void
    {
        [$alm, $ids] = $this->almacenCon(21);
        $pdf = $this->actingAs($this->usuario())->post(route('almacen.salida.preview'), [
            'tipo' => 'SALIDA', 'id_almacen' => $alm->ID_ALMACEN, 'lineas' => $this->lineas($ids),
        ])->assertOk()->getContent();

        $this->assertSame(2, preg_match_all('/\/Type\s*\/Page[^s]/', $pdf));
    }

    /** Si una nota no cabe así (acostada, nombres largos), se mide y se compacta: una hoja igual. */
    public function test_la_vista_previa_compacta_la_nota_que_no_cabe(): void
    {
        [$alm, $ids] = $this->almacenCon(21);
        $alm->update(['FORMATO_NOTA' => 'HORIZONTAL']);
        ProductoInventario::whereIn('ID_PRODUCTO', $ids)->update(['NOMBRE' => DB::raw("CONCAT(NOMBRE, ' ', REPEAT('MATERIAL DE NOMBRE MUY LARGO ', 1))")]);
        $pdf = $this->actingAs($this->usuario())->post(route('almacen.salida.preview'), [
            'tipo' => 'SALIDA', 'id_almacen' => $alm->ID_ALMACEN, 'lineas' => $this->lineas($ids),
        ])->assertOk()->getContent();

        $this->assertSame(2, preg_match_all('/\/Type\s*\/Page[^s]/', $pdf));
    }
}
