<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * <meta name="usuario-acceso">: la huella de permisos y frentes del usuario con que
 * navegacion.js guarda las pantallas de Equipos e Historial. Si un administrador le cambia
 * algo, la huella cambia y la pantalla guardada (con sus botones y filtros) deja de valer.
 */
class HuellaAccesoUsuarioTest extends MySqlTestCase
{
    public function test_cambia_si_le_cambian_permisos_o_frentes_y_no_si_nada_cambia(): void
    {
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO'       => 'PRUEBA HUELLA ACCESO',
            'CORREO_ELECTRONICO'    => 'prueba.huella.' . uniqid() . '@local.test',
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

        $inicial = $this->huella($id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $inicial);
        $this->assertSame($inicial, $this->huella($id));

        DB::table('usuarios')->where('ID_USUARIO', $id)->update(['PERMISOS' => 'equipos.edit']);
        $conPermiso = $this->huella($id);
        $this->assertNotSame($inicial, $conPermiso);

        DB::table('usuarios')->where('ID_USUARIO', $id)->update(['NIVEL_ACCESO_EQUIPOS' => 2]);
        $this->assertNotSame($conPermiso, $this->huella($id));
    }

    private function huella(int $id): string
    {
        $html = $this->actingAs(Usuario::find($id))->get('/menu')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<meta name="usuario-acceso" content="([^"]*)">/', $html, $m));

        return $m[1];
    }
}
