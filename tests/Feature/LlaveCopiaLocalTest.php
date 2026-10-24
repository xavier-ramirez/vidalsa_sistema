<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * GET /sync/llave: la llave con la que el navegador abre la copia local cifrada
 * (public/js/offline/llave-local.js). Solo con sesión, una por usuario, siempre la misma
 * para el mismo usuario (si cambiara, la copia del equipo quedaría ilegible) y sin caché.
 */
class LlaveCopiaLocalTest extends MySqlTestCase
{
    public function test_sin_sesion_no_entrega_llave(): void
    {
        $this->getJson('/sync/llave')->assertUnauthorized();
    }

    public function test_misma_llave_para_el_mismo_usuario_y_otra_para_otro(): void
    {
        $uno = $this->usuario();
        $otro = $this->usuario();

        $r = $this->actingAs($uno)->getJson('/sync/llave')->assertOk();
        $llave = base64_decode($r->json('llave'), true);

        $this->assertSame(32, strlen($llave));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertSame($r->json('llave'), $this->actingAs($uno)->getJson('/sync/llave')->json('llave'));
        $this->assertNotSame($r->json('llave'), $this->actingAs($otro)->getJson('/sync/llave')->json('llave'));
    }

    private function usuario(): Usuario
    {
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO'       => 'PRUEBA LLAVE COPIA LOCAL',
            'CORREO_ELECTRONICO'    => 'prueba.llave.' . uniqid() . '@local.test',
            'PASSWORD_HASH'         => bcrypt('irrelevante'),
            'ID_ROL'                => DB::table('roles')->value('ID_ROL'),
            'NIVEL_ACCESO_EQUIPOS'  => 1,
            'NIVEL_ACCESO_ALMACEN'  => 1,
            'ESTATUS'               => 'ACTIVO',
            'REQUIERE_CAMBIO_CLAVE' => 0,
            'PERMISOS'              => '',
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        return Usuario::find($id);
    }
}
