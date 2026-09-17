<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\AlmacenLogistica;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Usuario;
use App\Services\InventarioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Transporte de la Nota de Entrega (LogisticaAlmacenService): la salida guarda vehículo,
 * placa, chofer y cédula, la nota los imprime, el almacén los recuerda para la próxima y
 * sugiere su lista más la flota con placa de sus frentes. Todo corre en la transacción de la
 * prueba y se revierte al terminar.
 */
class LogisticaAlmacenTest extends MySqlTestCase
{
    private function usuario(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.movimiento') && $u->can('super.admin') && Almacen::usuarioEsGlobal($u));
        $this->assertNotNull($u, 'Hace falta un usuario GLOBAL con almacen.movimiento y super.admin.');
        return $u;
    }

    /** Almacén con un frente propio y un producto con 10 en stock. */
    private function almacen(): array
    {
        $sufijo = strtoupper(Str::random(6));
        $alm = Almacen::create(['NOMBRE' => "ALMACEN LOGISTICA {$sufijo}", 'TIPO' => Almacen::TIPO_GENERAL]);
        $frente = DB::table('frentes_trabajo')->insertGetId(['NOMBRE_FRENTE' => "FRENTE LOGISTICA {$sufijo}"]);
        DB::table('almacen_frentes')->insert(['ID_ALMACEN' => $alm->ID_ALMACEN, 'ID_FRENTE' => $frente]);
        $p = ProductoInventario::create(['CODIGO' => 'L' . $sufijo, 'NOMBRE' => "PRODUCTO LOGISTICA {$sufijo}", 'UM' => 'UND']);
        app(InventarioService::class)->registrarEntrada($alm->ID_ALMACEN, $p->ID_PRODUCTO, 10);
        return [$alm, $p, $frente];
    }

    private function vehiculoDeFlota(int $frente, string $placa, ?array $chofer = null, ?string $serial = null): void
    {
        $id = DB::table('equipos')->insertGetId([
            'MARCA' => 'TOYOTA', 'MODELO' => 'HILUX', 'ANIO' => 2020, 'SERIAL_CHASIS' => $serial ?? ('S' . Str::random(10)),
            'ID_FRENTE_ACTUAL' => $frente, 'id_tipo_equipo' => DB::table('tipo_equipos')->where('nombre', 'CAMIONETA')->value('id'),
        ]);
        DB::table('documentacion')->insert(['ID_EQUIPO' => $id, 'PLACA' => $placa]);
        if ($chofer) {
            DB::table('responsable')->insert(['ID_EQUIPO' => $id, 'PERSONA_ASIGNADA' => $chofer[0], 'CEDULA_RESPONSABLE' => $chofer[1], 'FECHA_ASIGNACION' => now()->toDateString()]);
        }
    }

    public function test_la_salida_guarda_el_transporte_lo_imprime_y_lo_recuerda(): void
    {
        [$alm, $p] = $this->almacen();

        $this->actingAs($this->usuario())->postJson(route('almacen.movimientos.lote'), [
            'tipo' => 'SALIDA', 'id_almacen' => $alm->ID_ALMACEN,
            'lineas' => [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2]],
            'transporte_vehiculo' => ' camioneta  toyota hilux ', 'transporte_placa' => 'a05 ec1g',
            'transporte_chofer' => 'Leosel Poito', 'transporte_cedula' => '17.902.185',
        ])->assertSuccessful();

        $mov = MovimientoInventario::where('ID_PRODUCTO', $p->ID_PRODUCTO)->where('TIPO', 'SALIDA')->first();
        $this->assertSame(['CAMIONETA TOYOTA HILUX', 'A05EC1G', 'LEOSEL POITO', '17.902.185'],
            [$mov->TRANSPORTE_VEHICULO, $mov->TRANSPORTE_PLACA, $mov->TRANSPORTE_CHOFER, $mov->TRANSPORTE_CEDULA]);

        // La nota los imprime en "Datos del vehículo / Datos del chofer" (los dos formatos).
        $datos = null;
        View::composer('admin.almacen.nota_entrega_pdf', function ($v) use (&$datos) { $datos = $v->getData(); });
        $this->actingAs($this->usuario())->get(route('almacen.nota-entrega', ['numero' => $mov->NUMERO_NOTA]))->assertOk();
        foreach (['admin.almacen.nota_entrega_pdf', 'admin.almacen.nota_entrega_horizontal_pdf'] as $vista) {
            $html = view($vista, ['firmantes' => $alm->firmantesNota()] + $datos)->render();
            foreach (['CAMIONETA TOYOTA HILUX', 'A05EC1G', 'LEOSEL POITO', '17.902.185'] as $dato) {
                $this->assertStringContainsString($dato, $html, "{$vista} imprime {$dato}.");
            }
        }

        // Quedó en la lista del almacén, y otra salida con la misma cédula escrita distinto no
        // lo duplica.
        $this->actingAs($this->usuario())->postJson(route('almacen.movimientos.lote'), [
            'tipo' => 'SALIDA', 'id_almacen' => $alm->ID_ALMACEN,
            'lineas' => [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 1]],
            'transporte_chofer' => 'LEOSEL POITO', 'transporte_cedula' => 'V-17902185',
        ])->assertSuccessful();
        $lista = AlmacenLogistica::where('ID_ALMACEN', $alm->ID_ALMACEN)->orderBy('TIPO')->get(['TIPO', 'NOMBRE', 'DOCUMENTO'])->toArray();
        $this->assertSame([
            ['TIPO' => 'CHOFER',   'NOMBRE' => 'LEOSEL POITO',           'DOCUMENTO' => '17.902.185'],
            ['TIPO' => 'VEHICULO', 'NOMBRE' => 'CAMIONETA TOYOTA HILUX', 'DOCUMENTO' => 'A05EC1G'],
        ], $lista);
    }

    public function test_la_salida_a_otro_almacen_tambien_lleva_el_transporte(): void
    {
        [$alm, $p] = $this->almacen();
        // Destino: un almacén de PROYECTO con su frente → la salida va por traspaso.
        $destino = Almacen::create(['NOMBRE' => 'DESTINO LOGISTICA ' . strtoupper(Str::random(6)), 'TIPO' => Almacen::TIPO_PROYECTO]);
        $frente  = DB::table('frentes_trabajo')->insertGetId(['NOMBRE_FRENTE' => 'FRENTE DESTINO ' . strtoupper(Str::random(6))]);
        DB::table('almacen_frentes')->insert(['ID_ALMACEN' => $destino->ID_ALMACEN, 'ID_FRENTE' => $frente]);

        $this->actingAs($this->usuario())->postJson(route('almacen.movimientos.lote'), [
            'tipo' => 'SALIDA', 'id_almacen' => $alm->ID_ALMACEN, 'id_frente_destino' => $frente,
            'lineas' => [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2]],
            'transporte_vehiculo' => 'CAMION FORD F-350', 'transporte_placa' => 'A11AT9F',
            'transporte_chofer' => 'EDUARDO ORTIZ', 'transporte_cedula' => '16.068.340',
        ])->assertSuccessful()->assertJsonStructure(['numero_traspaso']);

        $mov = MovimientoInventario::where('ID_PRODUCTO', $p->ID_PRODUCTO)->where('TIPO', 'TRASPASO_SALIDA')->first();
        $this->assertSame(['CAMION FORD F-350', 'A11AT9F', 'EDUARDO ORTIZ', '16.068.340'],
            [$mov->TRANSPORTE_VEHICULO, $mov->TRANSPORTE_PLACA, $mov->TRANSPORTE_CHOFER, $mov->TRANSPORTE_CEDULA]);
        $this->assertSame(2, AlmacenLogistica::where('ID_ALMACEN', $alm->ID_ALMACEN)->count(), 'Lo recuerda el almacén que despacha.');
    }

    public function test_la_vista_previa_muestra_el_transporte_sin_recordarlo(): void
    {
        [$alm, $p] = $this->almacen();
        $datos = null;
        View::composer('admin.almacen.nota_entrega_pdf', function ($v) use (&$datos) { $datos = $v->getData()['datos']; });
        $this->actingAs($this->usuario())->postJson(route('almacen.salida.preview'), [
            'id_almacen' => $alm->ID_ALMACEN,
            'lineas' => [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2]],
            'transporte_vehiculo' => 'camioneta toyota hilux', 'transporte_placa' => 'A60EO9P',
            'transporte_chofer' => 'luis gonzález', 'transporte_cedula' => '14.729.676',
        ])->assertOk();
        $this->assertSame(['CAMIONETA TOYOTA HILUX', 'A60EO9P', 'LUIS GONZÁLEZ', '14.729.676'],
            [$datos['transporte_vehiculo'], $datos['transporte_placa'], $datos['transporte_chofer'], $datos['transporte_cedula']]);
        $this->assertSame(0, AlmacenLogistica::where('ID_ALMACEN', $alm->ID_ALMACEN)->count(), 'La vista previa no registra nada.');
    }

    public function test_sin_transporte_la_nota_sale_en_blanco_y_no_se_recuerda_nada(): void
    {
        [$alm, $p] = $this->almacen();
        $this->actingAs($this->usuario())->postJson(route('almacen.movimientos.lote'), [
            'tipo' => 'SALIDA', 'id_almacen' => $alm->ID_ALMACEN,
            'lineas' => [['id_producto' => $p->ID_PRODUCTO, 'cantidad' => 2]],
            // Medio dato (chofer sin cédula) se imprime, pero no sirve para sugerirlo después.
            'transporte_chofer' => 'CHOFER SIN CEDULA',
        ])->assertSuccessful();
        $mov = MovimientoInventario::where('ID_PRODUCTO', $p->ID_PRODUCTO)->where('TIPO', 'SALIDA')->first();
        $this->assertNull($mov->TRANSPORTE_PLACA);
        $this->assertSame('CHOFER SIN CEDULA', $mov->TRANSPORTE_CHOFER);
        $this->assertSame(0, AlmacenLogistica::where('ID_ALMACEN', $alm->ID_ALMACEN)->count());
    }

    public function test_sugiere_la_lista_del_almacen_y_despues_la_flota_de_sus_frentes(): void
    {
        [$alm, , $frente] = $this->almacen();
        AlmacenLogistica::create(['ID_ALMACEN' => $alm->ID_ALMACEN, 'TIPO' => 'VEHICULO', 'NOMBRE' => 'CAMION FORD F-350', 'DOCUMENTO' => 'A11AT9F', 'CLAVE' => 'A11AT9F']);
        $relleno = fn (string $marca) => '.' . $marca . str_repeat(',', random_int(3, 9)) . $marca . '.';
        $serialA = 'SA' . Str::random(9);
        $serialB = 'SB' . Str::random(9);
        $serialSinPlaca = 'SP' . Str::random(9);
        $this->vehiculoDeFlota($frente, 'A11-AT9F', null, $serialA);                                       // ya está en la lista: no se repite, le da serial y tipo
        $this->vehiculoDeFlota($frente, 'B22XY7Z', ['JUAN PEREZ', '12.345.678'], $serialB);
        $this->vehiculoDeFlota($frente, $relleno('-'), null, $serialSinPlaca);            // sin placa de verdad: sale por su serial
        $this->vehiculoDeFlota($frente, $relleno('_'), null, '-' . Str::random(3) . '-'); // ni placa ni serial (3 caracteres): fuera

        $r = $this->actingAs($this->usuario())->getJson(route('almacen.almacenes.logistica', ['id' => $alm->ID_ALMACEN]))->assertOk();
        $this->assertSame([
            ['nombre' => 'CAMION FORD F-350', 'documento' => 'A11AT9F', 'serial' => strtoupper($serialA), 'tipo' => 'CAMIONETA', 'origen' => 'almacen'],
            // `tipo` aparte: la lista muestra placa, serial y tipo; `nombre` llena el campo Vehículo.
            ['nombre' => 'CAMIONETA TOYOTA HILUX', 'documento' => 'B22XY7Z', 'serial' => strtoupper($serialB), 'tipo' => 'CAMIONETA', 'origen' => 'flota'],
            ['nombre' => 'CAMIONETA TOYOTA HILUX', 'documento' => strtoupper($serialSinPlaca), 'serial' => strtoupper($serialSinPlaca), 'tipo' => 'CAMIONETA', 'origen' => 'flota'],
        ], $r->json('vehiculos'));
        $this->assertSame([['nombre' => 'JUAN PEREZ', 'documento' => '12.345.678', 'origen' => 'flota']], $r->json('choferes'));
    }

    public function test_editar_almacen_guarda_su_lista_de_logistica(): void
    {
        [$alm, , $frente] = $this->almacen();
        $base = ['NOMBRE' => $alm->NOMBRE, 'TIPO' => $alm->TIPO, 'ALMACENISTA' => 'ALMACENISTA', 'CARGO_ALMACENISTA' => 'CARGO', 'frentes' => [$frente]];
        $guardar = fn (array $logistica) => $this->actingAs($this->usuario())
            ->patchJson(route('almacen.almacenes.update', ['id' => $alm->ID_ALMACEN]), $base + ['logistica' => $logistica])->assertOk();
        $lista = fn () => AlmacenLogistica::where('ID_ALMACEN', $alm->ID_ALMACEN)->orderBy('TIPO')->orderBy('NOMBRE')->pluck('DOCUMENTO', 'NOMBRE')->all();

        $guardar(['choferes' => [['nombre' => 'luis gonzález', 'documento' => '14.729.676'], ['nombre' => 'REPETIDO', 'documento' => '14729676']],
                  'vehiculos' => [['nombre' => 'Camioneta Toyota Hilux', 'documento' => 'a64 bj6p']]]);
        $this->assertSame(['LUIS GONZÁLEZ' => '14.729.676', 'CAMIONETA TOYOTA HILUX' => 'A64BJ6P'], $lista());

        // Guardar sin la lista no la toca; con la lista vacía la borra.
        $this->actingAs($this->usuario())->patchJson(route('almacen.almacenes.update', ['id' => $alm->ID_ALMACEN]), $base)->assertOk();
        $this->assertCount(2, $lista());
        $guardar(['choferes' => [], 'vehiculos' => []]);
        $this->assertSame([], $lista());
    }

    public function test_sin_permiso_no_ve_la_logistica(): void
    {
        [$alm] = $this->almacen();
        $sin = Usuario::all()->first(fn ($u) => !$u->can('almacen.movimiento') && !$u->can('super.admin'));
        $this->assertNotNull($sin, 'Hace falta un usuario sin almacen.movimiento ni super.admin.');
        $this->actingAs($sin)->getJson(route('almacen.almacenes.logistica', ['id' => $alm->ID_ALMACEN]))->assertForbidden();
    }
}
