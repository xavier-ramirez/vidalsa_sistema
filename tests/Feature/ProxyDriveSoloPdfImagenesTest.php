<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\DriveFalso;
use Tests\MySqlTestCase;

/**
 * /storage/google/{id} solo entrega PDF y fotos (GoogleDriveController::TIPOS_PERMITIDOS),
 * con nosniff. NUNCA toca Google Drive: la copia local va a un disco de mentira y el
 * servicio es DriveFalso.
 */
class ProxyDriveSoloPdfImagenesTest extends MySqlTestCase
{
    private DriveFalso $drive;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->drive = DriveFalso::instalar();
    }

    protected function tearDown(): void
    {
        DriveFalso::quitar();
        parent::tearDown();
    }

    public function test_un_pdf_de_la_copia_local_sale_con_nosniff(): void
    {
        $id = $this->copiaLocal("%PDF-1.4\n%prueba\n");

        $r = $this->actingAs($this->usuario())->get('/storage/google/' . $id);

        $r->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
    }

    public function test_html_o_svg_de_la_copia_local_no_se_sirven(): void
    {
        $u = $this->usuario();
        $html = $this->copiaLocal('<!doctype html><html><script>alert(1)</script></html>');
        $svg  = $this->copiaLocal('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->actingAs($u)->get('/storage/google/' . $html)->assertNotFound();
        $this->actingAs($u)->get('/storage/google/' . $svg)->assertNotFound();
    }

    public function test_lo_que_en_drive_no_es_pdf_ni_foto_ni_se_baja(): void
    {
        $this->drive->mimeDeArchivos = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        $this->actingAs($this->usuario())->get('/storage/google/PRUEBA_' . Str::random(12))->assertNotFound();

        $this->assertSame(0, $this->drive->bajadas, 'No se pide el archivo a Drive si su tipo no va.');
    }

    public function test_un_pdf_que_viene_de_drive_si_sale(): void
    {
        $r = $this->actingAs($this->usuario())->get('/storage/google/PRUEBA_' . Str::random(12));

        $r->assertOk();
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertSame(1, $this->drive->bajadas);
    }

    private function copiaLocal(string $contenido): string
    {
        $id = 'PRUEBA_' . Str::random(12);
        Storage::disk('local')->put(GoogleDriveService::rutaCopiaLocal($id), $contenido);
        return $id;
    }

    private function usuario(): Usuario
    {
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO'       => 'PRUEBA PROXY',
            'CORREO_ELECTRONICO'    => 'prueba.proxy.' . strtolower(Str::random(8)) . '@local.test',
            'PASSWORD_HASH'         => Hash::make(Str::random(16)),
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
