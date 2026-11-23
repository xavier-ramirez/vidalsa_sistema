<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\FrenteTrabajo;
use App\Models\ProductoInventario;
use App\Models\Usuario;
use App\Services\InventarioService;
use App\Services\TraspasoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * /admin/frentes con las operaciones en distinto orden: eliminar con un envío de almacén en
 * camino o con un usuario asignado, editar/abrir/eliminar un frente que otra persona ya
 * eliminó, renombrar al nombre de uno eliminado, y los avisos de validación.
 */
class FrentesBordesTest extends MySqlTestCase
{
    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->superAdminGlobal();
        $this->actingAs($this->admin);
    }

    private function crear(array $extra = [])
    {
        return $this->postJson('/admin/frentes?json=true', array_merge([
            'NOMBRE_FRENTE' => 'FRENTE BORDE ' . strtoupper(Str::random(8)), 'UBICACION' => 'EL TIGRE',
            'TIPO_FRENTE' => 'OPERACION', 'ESTATUS_FRENTE' => 'ACTIVO', 'CONTRATOS' => '',
        ], $extra));
    }

    private function nuevo(): int
    {
        return (int) $this->crear()->assertOk()->json('frente.ID_FRENTE');
    }

    private function datos(int $id, array $cambios = []): array
    {
        $f = FrenteTrabajo::withTrashed()->find($id);
        return array_merge(['NOMBRE_FRENTE' => $f->NOMBRE_FRENTE, 'UBICACION' => $f->UBICACION,
            'TIPO_FRENTE' => $f->TIPO_FRENTE, 'ESTATUS_FRENTE' => $f->ESTATUS_FRENTE], $cambios);
    }

    /**
     * El caso que dejaba material perdido: un envío ENVIADO hacia el frente, se elimina el
     * frente, y al recibir el material entraba a la bolsa del frente eliminado de un almacén
     * que sigue repartiendo por proyecto, donde nada lo puede elegir.
     */
    public function test_no_deja_eliminar_un_frente_con_un_envio_de_almacen_sin_recibir(): void
    {
        [$c, $d, $e] = [$this->nuevo(), $this->nuevo(), $this->nuevo()];
        $destino = (int) Almacen::forceCreate(['NOMBRE' => 'PRUEBA BORDE ' . uniqid(), 'TIPO' => Almacen::TIPO_PROYECTO, 'ESTATUS' => 'ACTIVO'])->ID_ALMACEN;
        DB::table('almacen_frentes')->insert(array_map(fn ($f) => ['ID_ALMACEN' => $destino, 'ID_FRENTE' => $f], [$c, $d, $e]));
        $origen = (int) Almacen::forceCreate(['NOMBRE' => 'PRUEBA BORDE ORIGEN ' . uniqid(), 'TIPO' => 'GENERAL', 'ESTATUS' => 'ACTIVO'])->ID_ALMACEN;
        [$prod, $prod2] = ProductoInventario::where('ESTATUS', 'ACTIVO')->limit(2)->pluck('ID_PRODUCTO')->map(fn ($v) => (int) $v)->all();

        $ts = app(TraspasoService::class);
        $t = $ts->crearBorrador(['id_almacen_origen' => $origen, 'id_almacen_destino' => $destino, 'id_frente_destino' => $e,
            'id_usuario' => $this->admin->ID_USUARIO], [['id_producto' => $prod, 'cantidad' => 3], ['id_producto' => $prod2, 'cantidad' => 1]]);
        $this->deleteJson("/admin/frentes/{$e}")->assertStatus(422)->assertJson(['envios_pendientes' => 1]);

        $t = $ts->enviar($t, ['id_usuario_envio' => $this->admin->ID_USUARIO, 'permitir_negativo' => true, 'numero_nota' => 'NE-T-' . $t->ID_TRASPASO]);
        $r = $this->deleteJson("/admin/frentes/{$e}")->assertStatus(422);
        $this->assertStringContainsString('1 envío(s) de almacén sin recibir', $r->json('message'));
        $this->assertNotContains($e, collect($this->getJson('/admin/frentes/sin-equipos')->json('frentes'))->pluck('id')->all(),
            'El modal «Frentes sin equipos» no debe ofrecer eliminar lo que el servidor rechaza.');

        // Recibido A MEDIAS (un ítem sí, el otro todavía no): lo que falta aún puede recibirse,
        // así que sigue bloqueado.
        $linea = fn (int $p) => $t->lineas()->where('ID_PRODUCTO', $p)->first();
        $t = $ts->recibir($t->fresh(), [['id_linea' => $linea($prod)->ID_LINEA, 'cantidad_recibida' => 3]], ['id_usuario_recepcion' => $this->admin->ID_USUARIO]);
        $this->assertSame(\App\Models\Traspaso::ESTADO_RECIBIDO_PARCIAL, $t->fresh()->ESTADO);
        $this->deleteJson("/admin/frentes/{$e}")->assertStatus(422)->assertJson(['envios_pendientes' => 1]);

        // Recibido todo, el material queda en SU bolsa: ahora lo que bloquea es ese stock.
        $ts->recibir($t->fresh(), [['id_linea' => $linea($prod2)->ID_LINEA, 'cantidad_recibida' => 1]], ['id_usuario_recepcion' => $this->admin->ID_USUARIO]);
        $this->assertEquals(3, (float) AlmacenStock::where('ID_ALMACEN', $destino)->where('ID_PRODUCTO', $prod)->where('ID_FRENTE', $e)->value('CANTIDAD'));
        $this->deleteJson("/admin/frentes/{$e}")->assertStatus(422)->assertJson(['productos_con_stock' => 2, 'envios_pendientes' => 0]);
    }

    public function test_no_deja_eliminar_un_frente_asignado_a_un_usuario_activo(): void
    {
        $f = $this->nuevo();
        $u = new Usuario();
        $u->NOMBRE_COMPLETO = 'PRUEBA BORDE FRENTE';
        $u->CORREO_ELECTRONICO = 'borde.frente.' . uniqid() . '@local.test';
        $u->PASSWORD_HASH = 'x';
        $u->ID_ROL = DB::table('roles')->value('ID_ROL');
        $u->NIVEL_ACCESO_EQUIPOS = 2; $u->NIVEL_ACCESO_ALMACEN = 2;
        $u->ID_FRENTE_ASIGNADO = $f;
        $u->ESTATUS = 'ACTIVO';
        $u->save();

        $this->deleteJson("/admin/frentes/{$f}")->assertStatus(422)->assertJson(['usuarios_asignados' => 1]);
        $this->assertNotContains($f, collect($this->getJson('/admin/frentes/sin-equipos')->json('frentes'))->pluck('id')->all());

        $u->ESTATUS = 'INACTIVO';
        $u->save();
        $this->deleteJson("/admin/frentes/{$f}")->assertOk();
    }

    /** Otra persona lo eliminó con la pantalla abierta: aviso claro, no el texto técnico en inglés. */
    public function test_frente_ya_eliminado_contesta_un_aviso_claro(): void
    {
        $f = $this->nuevo();
        $this->deleteJson("/admin/frentes/{$f}")->assertOk();

        foreach ([
            $this->deleteJson("/admin/frentes/{$f}"),
            $this->getJson("/admin/frentes/{$f}/edit?json=true"),
            $this->putJson("/admin/frentes/{$f}?json=true", $this->datos($f)),
        ] as $r) {
            $r->assertStatus(404)->assertJson(['success' => false, 'message' => 'Ese frente ya no existe: lo eliminaron. Recarga la página.']);
        }
    }

    public function test_renombrar_al_nombre_de_un_frente_eliminado_lo_explica(): void
    {
        [$a, $b] = [$this->nuevo(), $this->nuevo()];
        $nombreB = FrenteTrabajo::find($b)->NOMBRE_FRENTE;
        $this->deleteJson("/admin/frentes/{$b}")->assertOk();

        $r = $this->putJson("/admin/frentes/{$a}?json=true", $this->datos($a, ['NOMBRE_FRENTE' => strtolower($nombreB)]))->assertStatus(422);
        $this->assertSame(['Ese nombre es de un frente eliminado. Para usarlo, regístralo como frente nuevo: se recupera con su historial.'],
            $r->json('errors.NOMBRE_FRENTE'), 'Un solo aviso, el que explica qué hacer.');

        // Con un frente VIVO del mismo nombre sigue el aviso de siempre.
        $c = $this->nuevo();
        $this->putJson("/admin/frentes/{$a}?json=true", $this->datos($a, ['NOMBRE_FRENTE' => FrenteTrabajo::find($c)->NOMBRE_FRENTE]))
            ->assertStatus(422)->assertJsonPath('errors.NOMBRE_FRENTE', ['Ya existe un frente de trabajo con este nombre.']);
        // Y guardar sin cambiar el nombre sigue funcionando.
        $this->putJson("/admin/frentes/{$a}?json=true", $this->datos($a, ['UBICACION' => 'ANACO']))->assertOk();
    }

    /** Los avisos dicen el nombre del campo, no la clave deletreada («n o m b r e  f r e n t e»). */
    public function test_los_avisos_nombran_bien_cada_campo(): void
    {
        $this->crear(['NOMBRE_FRENTE' => str_repeat('A', 151)])->assertStatus(422)
            ->assertJsonPath('errors.NOMBRE_FRENTE.0', 'el nombre del frente no puede tener más de 150 caracteres.');
        $this->crear(['TIPO_FRENTE' => 'XXX'])->assertStatus(422)
            ->assertJsonPath('errors.TIPO_FRENTE.0', 'el tipo de frente seleccionado no es válido.');
        $this->crear(['RESP_3_CED' => str_repeat('9', 21)])->assertStatus(422)
            ->assertJsonPath('errors.RESP_3_CED.0', 'la cédula del responsable 3 no puede tener más de 20 caracteres.');
    }

    /** Subdivisiones enormes: aviso de validación, no un error 500 de la base. */
    public function test_subdivisiones_enormes_dan_aviso_y_no_error_500(): void
    {
        $this->crear(['SUBDIVISIONES' => str_repeat('X,', 40000)])->assertStatus(422)->assertJsonValidationErrors('SUBDIVISIONES');
        $this->crear(['SUBDIVISIONES' => 'TRAMO 1, TRAMO 2'])->assertOk();
    }

    /** Crear → eliminar → crear con el mismo nombre en minúsculas revive el MISMO frente con los datos nuevos. */
    public function test_eliminar_y_volver_a_crear_revive_el_mismo_frente(): void
    {
        $nombre = 'FRENTE BORDE ' . strtoupper(Str::random(8));
        $id = (int) $this->crear(['NOMBRE_FRENTE' => "  {$nombre}  "])->assertOk()->json('frente.ID_FRENTE');
        $this->assertSame($nombre, FrenteTrabajo::find($id)->NOMBRE_FRENTE, 'Se guarda sin espacios y en mayúsculas.');
        $this->crear(['NOMBRE_FRENTE' => strtolower($nombre)])->assertStatus(422);

        $this->deleteJson("/admin/frentes/{$id}")->assertOk();
        $r = $this->crear(['NOMBRE_FRENTE' => strtolower($nombre), 'UBICACION' => 'ANACO'])->assertOk();
        $this->assertSame($id, (int) $r->json('frente.ID_FRENTE'));
        $this->assertSame('ANACO', FrenteTrabajo::find($id)->UBICACION);
        $this->assertSame(1, FrenteTrabajo::withTrashed()->where('NOMBRE_FRENTE', $nombre)->count());
    }
}
