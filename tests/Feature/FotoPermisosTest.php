<?php

namespace Tests\Feature;

use App\Http\Controllers\UserController;
use App\Models\ProductoInventario;
use App\Models\Role;
use App\Models\Usuario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Permisos de foto, uno por módulo (24-11-2026): almacen.foto (subir, cambiar y eliminar la
 * foto de un producto) y catalogo.foto (subir/cambiar la foto de las fichas del Catálogo).
 * Sin la clave el servidor responde 403 aunque el usuario tenga el resto del módulo.
 */
class FotoPermisosTest extends MySqlTestCase
{
    private function usuario(string $permisos): Usuario
    {
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO' => 'Prueba Fotos', 'CORREO_ELECTRONICO' => 'prueba.fotos.' . strtolower(Str::random(8)) . '@cvidalsa27.com',
            'PASSWORD_HASH' => bcrypt('123456'), 'ID_ROL' => Role::value('ID_ROL'), 'NIVEL_ACCESO_EQUIPOS' => 1,
            'NIVEL_ACCESO_ALMACEN' => 1, 'ESTATUS' => 'ACTIVO', 'REQUIERE_CAMBIO_CLAVE' => 0, 'PERMISOS' => $permisos,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return Usuario::find($id);
    }

    private function productoConFoto(): ProductoInventario
    {
        return ProductoInventario::create(['CODIGO' => 'F' . strtoupper(Str::random(7)), 'NOMBRE' => 'PRUEBA FOTO ' . Str::random(5),
            'UM' => 'UND', 'FOTO' => '/storage/google/IDFOTOPRUEBA']);
    }

    public function test_las_dos_claves_estan_en_la_lista_de_permisos(): void
    {
        $lista = UserController::availablePermissions();
        $this->assertArrayHasKey('almacen.foto', $lista);
        $this->assertArrayHasKey('catalogo.foto', $lista);
    }

    public function test_con_productos_pero_sin_almacen_foto_no_sube_ni_elimina(): void
    {
        $u = $this->usuario('almacen.productos,almacen.movimiento');
        $p = $this->productoConFoto();

        $this->actingAs($u)->post(route('almacen.productos.foto.store', $p->ID_PRODUCTO),
            ['foto' => UploadedFile::fake()->image('foto.jpg', 300, 300)], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($u)->deleteJson(route('almacen.productos.foto.destroy', $p->ID_PRODUCTO))->assertForbidden();
        $this->assertSame('/storage/google/IDFOTOPRUEBA', $p->fresh()->FOTO);
    }

    public function test_con_almacen_foto_la_papelera_deja_el_producto_sin_foto_y_manda_el_archivo_a_la_papelera(): void
    {
        Bus::fake();
        $p = $this->productoConFoto();

        $this->actingAs($this->usuario('almacen.foto'))
            ->deleteJson(route('almacen.productos.foto.destroy', $p->ID_PRODUCTO))
            ->assertOk()->assertJson(['success' => true, 'foto' => null]);

        $this->assertNull($p->fresh()->FOTO);
        Bus::assertDispatchedAfterResponse(\App\Jobs\DeleteGoogleDriveFile::class);
    }

    public function test_sin_catalogo_foto_no_cambia_la_foto_del_catalogo(): void
    {
        $u = $this->usuario('equipos.create,equipos.edit');
        $foto = ['foto' => UploadedFile::fake()->image('foto.jpg', 300, 300)];

        $this->actingAs($u)->post(route('catalogo.uploadFoto', 1), $foto, ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($u)->post(route('equipos-auxiliares.catalogo.uploadPhoto'), $foto, ['Accept' => 'application/json'])->assertForbidden();
    }
}
