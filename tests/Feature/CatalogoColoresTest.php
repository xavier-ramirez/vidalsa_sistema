<?php

namespace Tests\Feature;

use App\Models\CaracteristicaModelo;
use App\Models\CatalogoColor;
use App\Models\Equipo;
use App\Models\Usuario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\DriveFalso;
use Tests\MySqlTestCase;

/**
 * Catálogo por color: una ficha por modelo+año, el color en la unidad (equipos.COLOR) y la
 * foto de cada color en catalogo_colores. Equipo::fotoParaMostrar decide la foto en toda la
 * app. Drive es el doble en memoria (DriveFalso): nada sale a la red. Todo se revierte.
 */
class CatalogoColoresTest extends MySqlTestCase
{
    private string $modelo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        DriveFalso::instalar();
        $this->modelo = 'PRUEBA-COLOR-' . strtoupper(Str::random(6));
    }

    protected function tearDown(): void
    {
        DriveFalso::quitar();
        parent::tearDown();
    }

    /** super.admin (tiene equipos.create) sin cambio de clave pendiente. */
    private function admin(): Usuario
    {
        $u = Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->whereNotNull('PERMISOS')->get()
            ->first(fn ($u) => $u->can('super.admin') && $u->can('equipos.create') && \App\Models\Almacen::usuarioEsGlobal($u));
        $this->assertNotNull($u, 'Hace falta un super.admin global para probar.');
        return $u;
    }

    private function ficha(?string $foto = '/storage/google/modelo'): CaracteristicaModelo
    {
        return CaracteristicaModelo::create(['MODELO' => $this->modelo, 'TIPO' => 'CAMIONETA', 'ANIO_ESPEC' => 2026, 'FOTO_REFERENCIAL' => $foto]);
    }

    private function equipo(?int $idEspec, ?string $color, ?string $fotoPropia = null): Equipo
    {
        return Equipo::create([
            'MARCA' => 'PRUEBA', 'MODELO' => $this->modelo, 'ANIO' => 2026, 'COLOR' => $color,
            'ID_ESPEC' => $idEspec, 'FOTO_EQUIPO' => $fotoPropia,
            'SERIAL_CHASIS' => 'TEST-COLOR-' . uniqid(),
        ]);
    }

    private function recargar(Equipo $e): Equipo
    {
        return Equipo::with(Equipo::conFoto())->find($e->ID_EQUIPO);
    }

    public function test_la_foto_es_la_del_color_luego_la_del_modelo_luego_la_propia(): void
    {
        $f = $this->ficha();
        $f->colores()->create(['COLOR' => 'ROJO', 'FOTO' => '/storage/google/rojo']);

        $this->assertSame('/storage/google/rojo', $this->recargar($this->equipo($f->ID_ESPEC, 'roja'))->fotoParaMostrar(),
            'ROJA y ROJO son el mismo color (CatalogoColor::normalizar).');
        $this->assertSame('/storage/google/modelo', $this->recargar($this->equipo($f->ID_ESPEC, 'AMARILLO'))->fotoParaMostrar(),
            'Un color sin foto propia usa la del modelo.');
        $this->assertSame('/storage/google/modelo', $this->recargar($this->equipo($f->ID_ESPEC, null))->fotoParaMostrar());
        $this->assertSame('/storage/google/propia', $this->recargar($this->equipo(null, 'ROJO', '/storage/google/propia'))->fotoParaMostrar(),
            'Sin ficha, la foto propia de la unidad.');
        $this->assertSame('modelo', $this->recargar($this->equipo($f->ID_ESPEC, 'VERDE'))->fotoDriveId());
    }

    public function test_subir_foto_de_un_color_no_toca_la_del_modelo_y_borrarla_si_la_quita(): void
    {
        $f = $this->ficha();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('catalogo.uploadFoto', $f->ID_ESPEC), [
            'foto' => UploadedFile::fake()->image('amarilla.png', 60, 40), 'color' => 'amarilla',
        ], ['Accept' => 'application/json'])->assertOk()->assertJson(['success' => true, 'color' => 'AMARILLO']);

        $f->refresh();
        $this->assertSame('/storage/google/modelo', $f->FOTO_REFERENCIAL, 'La foto de un color no cambia la del modelo.');
        $this->assertStringStartsWith('/storage/google/falso-', $f->colores()->where('COLOR', 'AMARILLO')->value('FOTO'));
        $this->assertStringStartsWith('/storage/google/falso-', $this->recargar($this->equipo($f->ID_ESPEC, 'AMARILLO'))->fotoParaMostrar());

        $this->actingAs($admin)->deleteJson(route('catalogo.deleteFoto', $f->ID_ESPEC) . '?color=AMARILLO')->assertOk();
        $this->assertFalse($f->colores()->where('COLOR', 'AMARILLO')->exists());
        $this->assertSame('/storage/google/modelo', $f->fresh()->FOTO_REFERENCIAL);
    }

    public function test_una_foto_compartida_por_el_modelo_y_un_color_no_se_borra_de_drive(): void
    {
        // Así quedó la pick-up al unir las fichas: la foto gris es la del modelo Y la del GRIS.
        $f = $this->ficha('/storage/google/compartida');
        $f->colores()->create(['COLOR' => 'GRIS', 'FOTO' => '/storage/google/compartida']);

        $this->assertTrue(CaracteristicaModelo::fotoSigueEnUso('compartida'));
        $this->actingAs($this->admin())->deleteJson(route('catalogo.deleteFoto', $f->ID_ESPEC) . '?color=GRIS')->assertOk();
        $this->assertTrue(CaracteristicaModelo::fotoSigueEnUso('compartida'), 'La del modelo sigue usándola: no se puede borrar de Drive.');
        $this->assertSame('/storage/google/compartida', $f->fresh()->FOTO_REFERENCIAL);
    }

    public function test_no_se_puede_crear_otra_ficha_del_mismo_modelo_y_anio(): void
    {
        $this->ficha();
        $this->actingAs($this->admin())->postJson(route('catalogo.store'), [
            'MODELO' => strtolower($this->modelo), 'ANIO_ESPEC' => 2026, 'TIPO' => 'CAMIONETA',
        ])->assertStatus(422)->assertJsonFragment(['success' => false]);
        $this->assertSame(1, CaracteristicaModelo::where('MODELO', $this->modelo)->count());
    }

    public function test_los_modelos_sin_ficha_salen_solos_y_asegurar_ficha_los_enlaza(): void
    {
        $a = $this->equipo(null, 'ROJO');
        $b = $this->equipo(null, 'GRIS');
        $admin = $this->admin();

        $html = $this->actingAs($admin)->getJson(route('catalogo.index', ['ajax_load' => 1, 'modelo' => 'modelo_eq:' . $this->modelo]))
            ->assertOk()->json('html');
        $this->assertStringContainsString('SIN FICHA', $html);
        $this->assertStringContainsString('Crear ficha', $html);
        $this->assertStringContainsString('data-color="ROJO"', $html);

        $r = $this->actingAs($admin)->postJson(route('catalogo.asegurarFicha'), ['modelo' => $this->modelo, 'anio' => 2026, 'tipo' => 'camioneta'])
            ->assertOk()->assertJson(['success' => true, 'creada' => true]);
        $id = $r->json('id');
        $this->assertSame($id, $a->fresh()->ID_ESPEC);
        $this->assertSame($id, $b->fresh()->ID_ESPEC);

        // La segunda vez encuentra la misma: no se duplica.
        $this->actingAs($admin)->postJson(route('catalogo.asegurarFicha'), ['modelo' => $this->modelo, 'anio' => 2026])
            ->assertOk()->assertJson(['id' => $id, 'creada' => false]);

        $html = $this->actingAs($admin)->getJson(route('catalogo.index', ['ajax_load' => 1, 'modelo' => 'modelo_eq:' . $this->modelo]))
            ->assertOk()->json('html');
        $this->assertStringNotContainsString('SIN FICHA', $html);
        $this->assertStringContainsString('photo</i>Modelo', $html, 'Con ficha aparece el chip "Modelo" junto a los colores.');
    }

    public function test_la_tabla_de_equipos_muestra_el_color_y_la_foto_de_su_color(): void
    {
        $f = $this->ficha();
        $f->colores()->create(['COLOR' => 'DORADO', 'FOTO' => '/storage/google/dorado-foto']);
        $e = $this->equipo($f->ID_ESPEC, 'DORADA');

        $html = $this->actingAs($this->admin())
            ->getJson(route('equipos.index', ['search_query' => $e->SERIAL_CHASIS]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json('html');
        $this->assertStringContainsString('DORADO', $html);
        $this->assertStringContainsString('dorado-foto', $html);
    }

    public function test_el_color_se_guarda_normalizado_y_el_filtro_color_lo_encuentra(): void
    {
        $e = $this->equipo(null, '  roja ');
        $this->assertSame('ROJO', $e->fresh()->COLOR, 'Equipo::setCOLORAttribute normaliza al guardar.');

        $html = $this->actingAs($this->admin())
            ->getJson(route('equipos.index', ['color' => 'ROJO', 'search_query' => $e->SERIAL_CHASIS]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json('html');
        $this->assertStringContainsString($e->SERIAL_CHASIS, $html);
    }

    public function test_vincular_un_equipo_a_una_ficha_desde_su_foto(): void
    {
        $f = $this->ficha();
        $f->colores()->create(['COLOR' => 'ROJO', 'FOTO' => '/storage/google/rojo-vinculo']);
        $e = $this->equipo(null, 'ROJO');
        $admin = $this->admin();

        // La tabla solo le da el doble clic a super.admin.
        $html = $this->actingAs($admin)
            ->getJson(route('equipos.index', ['search_query' => $e->SERIAL_CHASIS]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json('html');
        $this->assertStringContainsString('data-vincular="' . $e->ID_EQUIPO . '"', $html);

        // El modal busca por lo que contiene el modelo y trae los colores de la ficha.
        $items = $this->actingAs($admin)->getJson(route('catalogo.elegir', ['q' => strtolower(substr($this->modelo, 7))]))
            ->assertOk()->json('items');
        $item = collect($items)->firstWhere('id', $f->ID_ESPEC);
        $this->assertNotNull($item, 'La búsqueda por parte del modelo encuentra la ficha.');
        $this->assertSame('ROJO', $item['colores'][0]['color']);

        $this->actingAs($admin)->postJson(route('equipos.vincularFicha', $e->ID_EQUIPO), ['ID_ESPEC' => $f->ID_ESPEC])
            ->assertOk()->assertJson(['success' => true, 'id_espec' => $f->ID_ESPEC])
            ->assertJsonFragment(['foto' => url('/storage/google/rojo-vinculo?sz=w300')]);
        $this->assertSame($f->ID_ESPEC, $e->fresh()->ID_ESPEC);

        $this->actingAs($admin)->postJson(route('equipos.vincularFicha', $e->ID_EQUIPO), ['ID_ESPEC' => 999999999])
            ->assertStatus(422);
    }

    public function test_vincular_y_buscar_fichas_es_solo_para_super_admin(): void
    {
        $f = $this->ficha();
        $e = $this->equipo(null, 'ROJO');
        $u = new Usuario();
        $u->NOMBRE_COMPLETO = 'PRUEBA SIN SUPER ADMIN';
        $u->CORREO_ELECTRONICO = 'prueba.vincular.' . uniqid() . '@local.test';
        $u->PASSWORD_HASH = bcrypt(Str::random(16));
        $u->PERMISOS = 'equipos.create,equipos.edit';
        $u->NIVEL_ACCESO_EQUIPOS = 1;
        $u->NIVEL_ACCESO_ALMACEN = 1;
        $u->ESTATUS = 'ACTIVO';
        $u->REQUIERE_CAMBIO_CLAVE = 0;
        $u->save();

        $this->actingAs($u)->getJson(route('catalogo.elegir'))->assertForbidden();
        $this->actingAs($u)->postJson(route('equipos.vincularFicha', $e->ID_EQUIPO), ['ID_ESPEC' => $f->ID_ESPEC])->assertForbidden();
        $this->assertNull($e->fresh()->ID_ESPEC);

        $html = $this->actingAs($u)
            ->getJson(route('equipos.index', ['search_query' => $e->SERIAL_CHASIS]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json('html');
        $this->assertStringNotContainsString('data-vincular=', $html);
    }

    public function test_la_sugerencia_de_ficha_del_formulario_trae_la_foto_de_su_color(): void
    {
        $f = $this->ficha();
        $f->colores()->create(['COLOR' => 'ROJO', 'FOTO' => '/storage/google/rojo-form']);
        $url = fn (string $color) => route('equipos.searchCatalog', ['model' => $this->modelo, 'year' => 2026, 'color' => $color]);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson($url('roja'))->assertOk()->assertJsonPath('data.0.FOTO', url('/storage/google/rojo-form?sz=w300'));
        $this->actingAs($admin)->getJson($url('VERDE'))->assertOk()->assertJsonPath('data.0.FOTO', url('/storage/google/modelo?sz=w300'));
    }

    public function test_normalizar(): void
    {
        $this->assertSame('ROJO', CatalogoColor::normalizar('  roja '));
        $this->assertSame('GRIS OSCURO', CatalogoColor::normalizar('gris   oscuro'));
        $this->assertNull(CatalogoColor::normalizar('   '));
    }
}
