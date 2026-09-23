<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\AlmacenKit;
use App\Models\AlmacenKitItem;
use App\Models\AlmacenKitModelo;
use App\Models\AlmacenStock;
use App\Models\ModeloFiltro;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Usuario;
use App\Services\InventarioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Kits del almacén (AlmacenKitController / KitAlmacenService): recetas de materiales por modelo
 * de equipo que cargan la salida de un golpe. Se prueba armarlos, lo que se ve (modelos
 * agrupados, placas, existencia), los permisos, la copia offline y el camino completo: la
 * salida de 3 kits con su Nota de Entrega en los dos formatos y su renglón en el historial.
 * Todo corre en la transacción de la prueba y se revierte al terminar.
 */
class KitsAlmacenTest extends MySqlTestCase
{
    private string $marca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marca = strtoupper(Str::random(6));
    }

    /** Usuario GLOBAL que arma kits y registra salidas. */
    private function usuario(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.productos') && $u->can('almacen.movimiento') && Almacen::usuarioEsGlobal($u));
        $this->assertNotNull($u, 'No hay un usuario GLOBAL con almacen.productos y almacen.movimiento para probar.');
        return $u;
    }

    private function producto(string $nombre): ProductoInventario
    {
        return ProductoInventario::create([
            'CODIGO' => 'K' . strtoupper(Str::random(7)), 'UM' => 'UND', 'CATEGORIA' => 'FILTROS',
            'NOMBRE' => "{$nombre} {$this->marca}",
        ]);
    }

    /**
     * Un modelo de equipo con DOS fichas (una por año, como en el catálogo real) y un equipo
     * suyo con placa. Devuelve [refs de las fichas, placa, tipo, modelo].
     */
    private function modeloConEquipo(): array
    {
        $tipo = 'TIPO KIT ' . $this->marca;
        $modelo = 'MK-' . $this->marca;
        $a = DB::table('caracteristicas_modelo')->insertGetId(['TIPO' => $tipo, 'MODELO' => $modelo, 'ANIO_ESPEC' => 2019]);
        $b = DB::table('caracteristicas_modelo')->insertGetId(['TIPO' => $tipo, 'MODELO' => $modelo, 'ANIO_ESPEC' => 2021]);
        // Un equipo por ficha, de la misma marca: así el catálogo las agrupa en un solo modelo
        // (CompatibilidadProductoService::modelosAgrupados toma la marca de sus equipos).
        $equipo = fn (int $espec, int $anio) => DB::table('equipos')->insertGetId([
            'ID_ESPEC' => $espec, 'MARCA' => 'MARCAKIT', 'MODELO' => $modelo, 'ANIO' => $anio, 'SERIAL_CHASIS' => 'SK' . strtoupper(Str::random(10)),
        ]);
        $equipo($b, 2021);
        $conPlaca = $equipo($a, 2019);
        $placa = 'K' . substr($this->marca, 0, 2) . '-' . substr($this->marca, 2, 4);
        DB::table('documentacion')->insert(['ID_EQUIPO' => $conPlaca, 'PLACA' => $placa]);
        return [[(string) $a, (string) $b], $placa, $tipo, "MARCAKIT {$modelo}"];
    }

    private function almacenCon(array $stock, string $formato = Almacen::FORMATO_NOTA_VERTICAL): Almacen
    {
        $alm = Almacen::create(['NOMBRE' => "ALMACEN KIT {$this->marca} " . Str::random(3), 'TIPO' => Almacen::TIPO_GENERAL, 'FORMATO_NOTA' => $formato]);
        foreach ($stock as $id => $cantidad) {
            app(InventarioService::class)->registrarEntrada($alm->ID_ALMACEN, $id, $cantidad);
        }
        return $alm;
    }

    private function guardarKit(array $datos, ?int $id = null)
    {
        return $id
            ? $this->actingAs($this->usuario())->putJson(route('almacen.kits.update', ['id' => $id]), $datos)
            : $this->actingAs($this->usuario())->postJson(route('almacen.kits.store'), $datos);
    }

    private function kitDe(array $kits, int $id): ?array
    {
        return collect($kits)->firstWhere('id', $id);
    }

    public function test_armar_un_kit_por_modelo_y_verlo_con_su_existencia_y_sus_placas(): void
    {
        [$refs, $placa, $tipo, $modelo] = $this->modeloConEquipo();
        $aceite = $this->producto('FILTRO ACEITE KIT');
        $aire   = $this->producto('FILTRO AIRE KIT');
        $alm = $this->almacenCon([$aceite->ID_PRODUCTO => 10, $aire->ID_PRODUCTO => 3]);

        $id = $this->guardarKit([
            'nombre' => "kit 250h {$this->marca}", 'descripcion' => 'Servicio de 250 horas',
            'modelos' => [['origen' => 'modelo', 'refs' => $refs]],
            'items' => [['id_producto' => $aceite->ID_PRODUCTO, 'cantidad' => 2], ['id_producto' => $aire->ID_PRODUCTO, 'cantidad' => 1]],
        ])->assertOk()->assertJsonPath('success', true)->json('id');

        // Las DOS fichas del modelo quedan ligadas, y el nombre se guarda en mayúsculas.
        $this->assertSame(2, AlmacenKitModelo::where('ID_KIT', $id)->count());
        $this->assertSame("KIT 250H {$this->marca}", AlmacenKit::find($id)->NOMBRE);

        $r = $this->actingAs($this->usuario())->getJson(route('almacen.kits.index', ['id_almacen' => $alm->ID_ALMACEN]))->assertOk();
        $kit = $this->kitDe($r->json('kits'), $id);
        $this->assertNotNull($kit);
        $this->assertCount(1, $kit['modelos'], 'Las fichas de un mismo modelo se ven como UN modelo.');
        $this->assertSame([$tipo, $modelo], [$kit['modelos'][0]['tipo'], $kit['modelos'][0]['modelo']]);
        $this->assertEqualsCanonicalizing($refs, $kit['modelos'][0]['refs']);
        $this->assertContains($placa, $kit['placas'], 'La placa del equipo de ese modelo viaja con el kit (buscarlo por placa).');
        $this->assertSame([2.0, 1.0], array_map('floatval', array_column($kit['items'], 'cantidad')));
        $this->assertEquals(10, $r->json("existencias.{$aceite->ID_PRODUCTO}"));
        $this->assertEquals(3, $r->json("existencias.{$aire->ID_PRODUCTO}"));
    }

    public function test_lo_que_no_se_puede_guardar_se_dice_claro(): void
    {
        $p = $this->producto('FILTRO VALIDA');
        $otro = $this->producto('FILTRO VALIDA 2');
        $base = ['nombre' => "KIT VALIDA {$this->marca}", 'items' => [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1]]];
        $this->guardarKit($base)->assertOk();

        $this->guardarKit($base)->assertStatus(422)->assertJsonPath('errors.nombre.0', 'Ya hay un kit con ese nombre.');
        $this->guardarKit(['nombre' => "KIT REPETIDO {$this->marca}", 'items' => [
            ['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1], ['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2],
        ]])->assertStatus(422);
        $this->guardarKit(['nombre' => "KIT CERO {$this->marca}", 'items' => [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 0]]])
            ->assertStatus(422);
        $this->guardarKit(['nombre' => "KIT SIN {$this->marca}", 'items' => []])->assertStatus(422);

        $otro->update(['ESTATUS' => 'INACTIVO']);
        $this->guardarKit(['nombre' => "KIT INACTIVO {$this->marca}", 'items' => [['id_producto' => $otro->ID_PRODUCTO, 'cantidad' => 1]]])
            ->assertStatus(422)->assertJsonPath('message', 'Uno de los materiales ya no está activo en el catálogo.');
        $this->guardarKit(array_merge($base, ['nombre' => "KIT MODELO {$this->marca}", 'modelos' => [['origen' => 'modelo', 'refs' => ['999999999']]]]))
            ->assertStatus(422)->assertJsonPath('message', 'Uno de los modelos de equipo ya no existe.');
    }

    public function test_editar_reemplaza_todo_y_eliminar_no_deja_restos(): void
    {
        [$refs] = $this->modeloConEquipo();
        $a = $this->producto('FILTRO EDITA A');
        $b = $this->producto('FILTRO EDITA B');
        $id = $this->guardarKit([
            'nombre' => "KIT EDITA {$this->marca}", 'modelos' => [['origen' => 'modelo', 'refs' => $refs]],
            'items' => [['id_producto' => $a->ID_PRODUCTO, 'cantidad' => 1]],
        ])->assertOk()->json('id');

        $this->guardarKit([
            'nombre' => "KIT EDITA {$this->marca}", 'descripcion' => 'Cambiado',
            'items' => [['id_producto' => $b->ID_PRODUCTO, 'cantidad' => 1.5]],
        ], $id)->assertOk();
        $this->assertSame([$b->ID_PRODUCTO], AlmacenKitItem::where('ID_KIT', $id)->pluck('ID_PRODUCTO')->all());
        $this->assertEquals(1.5, AlmacenKitItem::where('ID_KIT', $id)->value('CANTIDAD'));
        $this->assertSame(0, AlmacenKitModelo::where('ID_KIT', $id)->count(), 'Sin modelos queda de uso general.');

        $this->actingAs($this->usuario())->deleteJson(route('almacen.kits.destroy', ['id' => $id]))->assertOk();
        $this->assertNull(AlmacenKit::find($id));
        $this->assertSame(0, AlmacenKitItem::where('ID_KIT', $id)->count());
    }

    public function test_verlos_es_para_todos_pero_armarlos_exige_almacen_productos(): void
    {
        $p = $this->producto('FILTRO PERMISO');
        $sinPermiso = Usuario::all()->first(fn ($u) => ! $u->can('almacen.productos'));
        $this->assertNotNull($sinPermiso, 'Hace falta un usuario sin almacen.productos.');

        $this->actingAs($sinPermiso)->getJson(route('almacen.kits.index'))->assertOk();
        $this->actingAs($sinPermiso)->postJson(route('almacen.kits.store'), [
            'nombre' => "KIT PERMISO {$this->marca}", 'items' => [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1]],
        ])->assertForbidden();
        $this->actingAs($sinPermiso)->getJson(route('almacen.kits.modelos'))->assertForbidden();
        $this->assertFalse(AlmacenKit::where('NOMBRE', "KIT PERMISO {$this->marca}")->exists());
    }

    public function test_el_editor_sugiere_los_productos_ligados_al_modelo(): void
    {
        [$refs] = $this->modeloConEquipo();
        $p = $this->producto('FILTRO LIGADO');
        ModeloFiltro::create(['ID_ESPEC' => (int) $refs[1], 'ID_PRODUCTO' => $p->ID_PRODUCTO, 'CANTIDAD' => 2]);

        $sug = $this->actingAs($this->usuario())->getJson(route('almacen.kits.sugeridos', ['origen' => 'modelo', 'refs' => $refs]))
            ->assertOk()->json('productos');
        $this->assertSame([$p->ID_PRODUCTO], array_column($sug, 'id_producto'));
        $this->assertEquals(2, $sug[0]['cantidad'], 'Propone la cantidad por servicio de su vínculo.');

        $modelos = collect($this->actingAs($this->usuario())->getJson(route('almacen.kits.modelos'))->assertOk()->json('modelos'));
        $this->assertEqualsCanonicalizing($refs, $modelos->firstWhere('clave', 'modelo|TIPO KIT ' . $this->marca . '|MARCAKIT MK-' . $this->marca)['refs'] ?? []);
    }

    public function test_la_copia_offline_lleva_los_kits_igual_que_online(): void
    {
        $p = $this->producto('FILTRO OFFLINE');
        $id = $this->guardarKit(['nombre' => "KIT OFFLINE {$this->marca}", 'items' => [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1]]])
            ->assertOk()->json('id');

        $online  = $this->kitDe($this->actingAs($this->usuario())->getJson(route('almacen.kits.index'))->json('kits'), $id);
        $offline = $this->kitDe($this->actingAs($this->usuario())->getJson(route('offline.snapshot'))->assertOk()->json('kits'), $id);
        $this->assertNotNull($offline, 'La copia offline trae los kits.');
        $this->assertSame($online, $offline, 'Sin conexión el kit se ve igual que con conexión.');
    }

    /**
     * Lo que hace la pantalla al "Cargar a la salida" 3 kits: marca cada material con su
     * cantidad × 3 y la observación del kit, y se registra la salida de siempre. Se comprueba
     * el stock, el kardex, la Nota (vertical y horizontal) y el historial de movimientos.
     */
    public function test_cargar_3_kits_registra_la_salida_con_su_nota_y_su_historial(): void
    {
        [$refs, , $tipo, $modelo] = $this->modeloConEquipo();
        $aceite = $this->producto('FILTRO ACEITE SALIDA');
        $aire   = $this->producto('FILTRO AIRE SALIDA');

        foreach ([Almacen::FORMATO_NOTA_VERTICAL, Almacen::FORMATO_NOTA_HORIZONTAL] as $formato) {
            $alm = $this->almacenCon([$aceite->ID_PRODUCTO => 10, $aire->ID_PRODUCTO => 10], $formato);
            $nombre = "KIT SALIDA {$formato} {$this->marca}";
            $id = $this->guardarKit([
                'nombre' => $nombre, 'modelos' => [['origen' => 'modelo', 'refs' => $refs]],
                'items' => [['id_producto' => $aceite->ID_PRODUCTO, 'cantidad' => 2], ['id_producto' => $aire->ID_PRODUCTO, 'cantidad' => 1]],
            ])->assertOk()->json('id');
            $kit = $this->kitDe($this->actingAs($this->usuario())->getJson(route('almacen.kits.index', ['id_almacen' => $alm->ID_ALMACEN]))->json('kits'), $id);

            $kits = 3;
            $motivo = "{$nombre} × {$kits} · {$tipo} {$modelo}";   // la observación que arma la pantalla
            $this->actingAs($this->usuario())->postJson(route('almacen.movimientos.lote'), [
                'tipo' => 'SALIDA', 'id_almacen' => $alm->ID_ALMACEN, 'motivo' => $motivo,
                'lineas' => array_map(fn ($i) => ['id_producto' => $i['id_producto'], 'cantidad' => $i['cantidad'] * $kits], $kit['items']),
            ])->assertSuccessful();

            // Stock y kardex: 10 − 2×3 y 10 − 1×3, con la observación del kit.
            $saldo = fn ($p) => (float) AlmacenStock::where('ID_ALMACEN', $alm->ID_ALMACEN)->where('ID_PRODUCTO', $p->ID_PRODUCTO)->sum('CANTIDAD');
            $this->assertEquals(4, $saldo($aceite));
            $this->assertEquals(7, $saldo($aire));
            $movs = MovimientoInventario::where('ID_ALMACEN', $alm->ID_ALMACEN)->where('TIPO', 'SALIDA')->get();
            $this->assertCount(2, $movs);
            $this->assertSame([$motivo], $movs->pluck('MOTIVO')->unique()->values()->all());
            $numero = $movs->first()->NUMERO_NOTA;
            $this->assertNotEmpty($numero, 'La salida del kit lleva su Nota de Entrega.');

            // La Nota: una hoja, con los dos materiales y la observación del kit.
            $vista = $formato === Almacen::FORMATO_NOTA_HORIZONTAL ? 'admin.almacen.nota_entrega_horizontal_pdf' : 'admin.almacen.nota_entrega_pdf';
            $html = null;
            View::composer($vista, function ($v) use (&$html) { $html = $v; });
            $pdf = $this->actingAs($this->usuario())->get(route('almacen.nota-entrega', ['numero' => $numero]))->assertOk()->getContent();
            $this->assertSame(1, preg_match_all('#/Type\s*/Page[^s]#', $pdf), "La nota {$formato} sale en una hoja.");
            $this->assertNotNull($html, "La nota {$formato} pasó por su vista.");
            $texto = $html->render();
            foreach ([$aceite->NOMBRE, $aire->NOMBRE, e($motivo)] as $dato) {
                $this->assertStringContainsString($dato, $texto, "La nota {$formato} imprime {$dato}.");
            }

            // El historial de movimientos del almacén muestra la operación con su nota.
            $hist = $this->actingAs($this->usuario())->getJson(route('almacen.movimientos', ['id_almacen' => $alm->ID_ALMACEN]))->assertOk()->json('html');
            $this->assertStringContainsString($numero, $hist);
            $this->assertStringContainsString($aceite->NOMBRE, $hist);
        }
    }
}
