<?php

namespace Tests\Feature;

use App\Http\Middleware\OperacionUnica;
use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Usuario;
use App\Services\InventarioService;
use App\Support\NotasReservadas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\AlmacenLocal;
use Tests\MySqlTestCase;

/**
 * Lo que el servidor le garantiza a la bandeja sin internet (public/js/offline/bandeja.js):
 *  - una operación con clave (X-Clave-Operacion) se aplica UNA sola vez, aunque llegue repetida;
 *  - una salida hecha sin internet sale con el número de Nota apartado para el dispositivo, y
 *    ese número no se puede usar dos veces ni por otro usuario;
 *  - una salida sin stock se rechaza igual que con internet (422): queda en rojo en la bandeja;
 *  - mientras espera, la pantalla ya cuenta con ella: el stock de la copia la descuenta.
 * Todo corre en la transacción de la prueba.
 */
class BandejaServidorTest extends MySqlTestCase
{
    use AlmacenLocal;

    private string $marca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marca = 'PRUEBA BANDEJA ' . strtoupper(Str::random(6));
    }

    private function usuario(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.movimiento') && Almacen::usuarioEsGlobal($u));
        $this->assertNotNull($u, 'Hace falta un usuario GLOBAL con almacen.movimiento.');
        return $u;
    }

    /** Un almacén general con un producto de 10 unidades. */
    private function almacenConStock(): array
    {
        $alm = Almacen::create(['NOMBRE' => 'ALMACEN ' . $this->marca, 'TIPO' => Almacen::TIPO_GENERAL]);
        $p = ProductoInventario::create(['CODIGO' => 'C' . strtoupper(Str::random(7)), 'NOMBRE' => 'TORNILLO ' . $this->marca, 'UM' => 'UND']);
        app(InventarioService::class)->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 10);
        return [$alm, $p];
    }

    private function salida(Usuario $u, Almacen $alm, ProductoInventario $p, float $cant, array $extra = [], array $cab = [])
    {
        return $this->actingAs($u)->postJson(route('almacen.movimientos.lote'), [
            'tipo' => 'SALIDA', 'id_almacen' => $alm->ID_ALMACEN,
            'lineas' => [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => $cant]],
        ] + $extra, $cab);
    }

    private function saldo(Almacen $alm, ProductoInventario $p): float
    {
        return (float) AlmacenStock::where('ID_ALMACEN', $alm->ID_ALMACEN)->where('ID_PRODUCTO', $p->ID_PRODUCTO)->sum('CANTIDAD');
    }

    public function test_la_misma_operacion_repetida_se_aplica_una_sola_vez(): void
    {
        [$alm, $p] = $this->almacenConStock();
        $u = $this->usuario();
        $cab = [OperacionUnica::CABECERA => (string) Str::uuid()];

        $primera = $this->salida($u, $alm, $p, 3, [], $cab)->assertCreated();
        $segunda = $this->salida($u, $alm, $p, 3, [], $cab)->assertCreated()->assertHeader('X-Operacion-Repetida', '1');

        $this->assertSame($primera->json('numero_nota'), $segunda->json('numero_nota'), 'Devuelve la MISMA nota.');
        $this->assertSame(7.0, $this->saldo($alm, $p), 'Descontó una sola vez.');
        $this->assertSame(1, MovimientoInventario::where('ID_ALMACEN', $alm->ID_ALMACEN)->where('TIPO', 'SALIDA')->count());
    }

    public function test_un_rechazo_tambien_se_recuerda_pero_un_error_del_servidor_no(): void
    {
        [$alm, $p] = $this->almacenConStock();
        $u = $this->usuario();
        $cab = [OperacionUnica::CABECERA => (string) Str::uuid()];

        // Sin stock: 422, y repetirla da el mismo 422 sin intentarlo otra vez.
        $this->salida($u, $alm, $p, 50, [], $cab)->assertStatus(422);
        $this->salida($u, $alm, $p, 50, [], $cab)->assertStatus(422)->assertHeader('X-Operacion-Repetida', '1');
        $this->assertSame(10.0, $this->saldo($alm, $p));

        // Una clave sin terminar (la primera aún se aplica) responde 409 "en curso".
        $clave = (string) Str::uuid();
        DB::table(OperacionUnica::TABLA)->insert(['CLAVE' => $clave, 'ID_USUARIO' => $u->ID_USUARIO, 'RUTA' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->salida($u, $alm, $p, 1, [], [OperacionUnica::CABECERA => $clave])->assertStatus(409)->assertHeader('X-Operacion-En-Curso', '1');
        $this->assertSame(10.0, $this->saldo($alm, $p));
    }

    public function test_sin_clave_todo_sigue_como_siempre(): void
    {
        [$alm, $p] = $this->almacenConStock();
        $u = $this->usuario();
        $this->salida($u, $alm, $p, 2)->assertCreated();
        $this->salida($u, $alm, $p, 2)->assertCreated();
        $this->assertSame(6.0, $this->saldo($alm, $p));
    }

    public function test_el_dispositivo_recibe_un_bloque_y_la_salida_usa_su_numero(): void
    {
        [$alm, $p] = $this->almacenConStock();
        $u = $this->usuario();
        $disp = (string) Str::uuid();

        $numeros = $this->actingAs($u)->postJson(route('almacen.notas-reservadas'), ['dispositivo' => $disp])
            ->assertOk()->json('numeros');
        $this->assertCount(NotasReservadas::BLOQUE, $numeros);
        // Pedirlo otra vez no aparta más: todavía tiene de sobra.
        $this->assertSame($numeros, $this->actingAs($u)->postJson(route('almacen.notas-reservadas'), ['dispositivo' => $disp])->json('numeros'));

        $nota = $numeros[0];
        $this->salida($u, $alm, $p, 4, ['numero_nota_reservado' => $nota])->assertCreated()->assertJsonPath('numero_nota', $nota);
        $this->assertSame(4.0, (float) MovimientoInventario::where('NUMERO_NOTA', $nota)->sum('CANTIDAD'));

        // Ya usado: otra salida con el mismo número se rechaza y no descuenta.
        $this->salida($u, $alm, $p, 1, ['numero_nota_reservado' => $nota])->assertStatus(422);
        $this->assertSame(6.0, $this->saldo($alm, $p));

        // Una nota normal (con internet) nunca choca con el bloque apartado.
        $normal = $this->salida($u, $alm, $p, 1)->assertCreated()->json('numero_nota');
        $this->assertNotContains($normal, $numeros);
    }

    public function test_un_numero_reservado_de_otro_usuario_no_vale(): void
    {
        [$alm, $p] = $this->almacenConStock();
        $u = $this->usuario();
        $otro = Usuario::where('ID_USUARIO', '!=', $u->ID_USUARIO)->value('ID_USUARIO');
        $nota = DB::transaction(fn () => NotasReservadas::paraDispositivo((int) $otro, (string) Str::uuid()))[0];

        $this->salida($u, $alm, $p, 1, ['numero_nota_reservado' => $nota])->assertStatus(422);
        $this->assertSame(10.0, $this->saldo($alm, $p));
    }

    /**
     * El alta de /admin/equipos/create sin internet sube como formulario (multipart) con su
     * clave: si la respuesta se pierde y la bandeja la manda otra vez, el equipo o el auxiliar
     * se registra UNA vez y la segunda recibe la misma respuesta.
     */
    public function test_el_alta_de_equipo_o_auxiliar_repetida_se_registra_una_sola_vez(): void
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('equipos.create') && $u->veTodosLosFrentesEquipos());
        $this->assertNotNull($u, 'Hace falta un usuario con equipos.create que vea todos los frentes.');
        $serial = 'BANDEJA-' . strtoupper(Str::random(8));
        $alta = fn (array $datos, string $clave) => $this->actingAs($u)
            ->withHeaders(['Accept' => 'application/json', OperacionUnica::CABECERA => $clave])
            ->post(route('equipos.storeUnified'), $datos);

        $equipo = [
            '__modo' => 'equipo', 'CATEGORIA_FLOTA' => 'FLOTA LIVIANA', 'TIPO_EQUIPO' => DB::table('tipo_equipos')->value('nombre'),
            'MARCA' => $this->marca, 'MODELO' => 'M1', 'ANIO' => 2020, 'SERIAL_CHASIS' => $serial, 'ESTADO_OPERATIVO' => 'OPERATIVO',
        ];
        $clave = (string) Str::uuid();
        $alta($equipo, $clave)->assertOk();
        $alta($equipo, $clave)->assertOk()->assertHeader('X-Operacion-Repetida', '1');
        $this->assertSame(1, DB::table('equipos')->where('SERIAL_CHASIS', $serial)->count());

        $auxiliar = [
            '__modo' => 'auxiliar', 'TIPO' => 'COMPRESOR', 'MARCA' => $this->marca, 'MODELO' => 'M1', 'SERIAL' => $serial,
            'ESTADO_OPERATIVO' => 'OPERATIVO',
            'ID_FRENTE_ACTUAL' => DB::table('frentes_trabajo')->whereNull('deleted_at')->where('ESTATUS_FRENTE', 'ACTIVO')->value('ID_FRENTE'),
        ];
        $clave = (string) Str::uuid();
        $alta($auxiliar, $clave)->assertOk();
        $alta($auxiliar, $clave)->assertOk()->assertHeader('X-Operacion-Repetida', '1');
        $this->assertSame(1, DB::table('equipos_auxiliares')->where('SERIAL', $serial)->count());

        // Con OTRA clave ya no es una repetición: es un serial ocupado, y se rechaza (422).
        $alta($equipo, (string) Str::uuid())->assertStatus(422)->assertJsonValidationErrors('SERIAL_CHASIS');
    }

    public function test_lo_que_espera_en_la_bandeja_ya_se_descuenta_en_pantalla(): void
    {
        [$alm, $p] = $this->almacenConStock();
        $u = $this->usuario();
        $item = fn (string $tipo, float $cant, string $estado = 'pendiente') => [
            'estado' => $estado, 'tipo' => $tipo,
            'datos' => ['id_almacen' => $alm->ID_ALMACEN, 'lineas' => [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => $cant]]],
        ];
        $total = fn (array $filas) => array_sum(array_column(array_filter($filas, fn ($s) => $s['alm'] === $alm->ID_ALMACEN && $s['prod'] === $p->ID_PRODUCTO), 'cant'));

        $this->assertEqualsWithDelta(7, $total($this->stockLocal($u, [$item('salida', 3)])), 1e-9);
        // Lo rechazado no cuenta: no se registró.
        $this->assertEqualsWithDelta(10, $total($this->stockLocal($u, [$item('salida', 3, 'rechazada')])), 1e-9);
        // La auditoría deja el total en lo contado, y lo que sale después se resta de ahí.
        $this->assertEqualsWithDelta(2, $total($this->stockLocal($u, [$item('salida', 3), $item('ajuste', 4), $item('salida', 2)])), 1e-9);
    }
}
