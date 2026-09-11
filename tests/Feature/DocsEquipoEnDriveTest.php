<?php

namespace Tests\Feature;

use App\Jobs\DeleteGoogleDriveFile;
use App\Models\Documentacion;
use App\Models\Equipo;
use App\Models\Usuario;
use App\Services\GoogleDriveService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\DriveFalso;
use Tests\MySqlTestCase;

/**
 * PDF de equipos: mismo camino que los de auxiliares (GoogleDriveService::subirPdf y
 * borrarTrasResponder). Se reemplaza SOLO la API de Google (Tests\DriveFalso): uploadFile
 * y subirPdf reales corren enteros —con la copia local que evita volver a bajar el PDF—
 * y nada sale a la red.
 */
class DocsEquipoEnDriveTest extends MySqlTestCase
{
    private DriveFalso $drive;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Bus::fake([DeleteGoogleDriveFile::class]);

        $this->drive = DriveFalso::instalar();
    }

    protected function tearDown(): void
    {
        DriveFalso::quitar();
        parent::tearDown();
    }

    /** super.admin real sin cambio de clave pendiente (si no, el middleware lo desvía). */
    private function usuario(): Usuario
    {
        $u = Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->whereNotNull('PERMISOS')->get()
            ->first(fn ($usr) => in_array('super.admin', array_map('strtolower', $usr->PERMISOS), true));
        $this->assertNotNull($u, 'hace falta un super.admin activo');

        return $u;
    }

    /** Equipo propio con un ROTC ya cargado; la transacción del caso lo revierte. */
    private function equipoConRotc(): Equipo
    {
        $equipo = Equipo::create([
            'MARCA' => 'PRUEBA', 'MODELO' => 'DOC-DRIVE', 'ANIO' => 2026,
            'SERIAL_CHASIS' => 'TEST-DOCDRIVE-' . uniqid(),
        ]);
        Documentacion::create(['ID_EQUIPO' => $equipo->ID_EQUIPO, 'LINK_ROTC' => '/storage/google/rotc-viejo?v=1']);

        return $equipo;
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('rotc.pdf', 40, 'application/pdf');
    }

    public function test_subir_desde_el_visor_deja_copia_local_y_borra_el_viejo_despues_de_responder(): void
    {
        $equipo = $this->equipoConRotc();

        $link = $this->actingAs($this->usuario())
            ->post("/admin/equipos/{$equipo->ID_EQUIPO}/upload-doc", ['doc_type' => 'rotc', 'file' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['success' => true])
            ->json('link');

        $this->assertStringStartsWith('/storage/google/falso-1?v=', $link);
        $this->assertSame($link, $equipo->documentacion()->first()->LINK_ROTC);
        // El visor lo abre en el acto: tiene que salir del disco, no volver a bajarse de Drive.
        Storage::disk('local')->assertExists(GoogleDriveService::rutaCopiaLocal('falso-1'));
        Bus::assertDispatchedAfterResponse(DeleteGoogleDriveFile::class, 1);
    }

    /** El formulario de editar equipo con un ROTC nuevo adjunto. */
    private function editarConRotc(Equipo $equipo)
    {
        return $this->actingAs($this->usuario())
            ->put(route('equipos.update', $equipo->ID_EQUIPO), [
                'TIPO_EQUIPO'      => 'CAMIONETA',
                'CATEGORIA_FLOTA'  => 'FLOTA LIVIANA',
                'MARCA'            => $equipo->MARCA,
                'MODELO'           => $equipo->MODELO,
                'ANIO'             => $equipo->ANIO,
                'SERIAL_CHASIS'    => $equipo->SERIAL_CHASIS,
                'ESTADO_OPERATIVO' => 'OPERATIVO',
                'documentacion'    => ['PLACA' => '', 'FECHA_ROTC' => now()->addYear()->toDateString()],
                'doc_rotc'         => $this->pdf(),
            ], ['Accept' => 'application/json']);
    }

    public function test_editar_equipo_reemplaza_el_pdf_y_borra_el_viejo_despues_de_guardar(): void
    {
        $equipo = $this->equipoConRotc();

        $this->editarConRotc($equipo)->assertOk();

        $this->assertStringStartsWith('/storage/google/falso-1?v=', $equipo->documentacion()->first()->LINK_ROTC);
        Storage::disk('local')->assertExists(GoogleDriveService::rutaCopiaLocal('falso-1'));
        Bus::assertDispatchedAfterResponse(DeleteGoogleDriveFile::class, 1);
    }

    public function test_editar_equipo_si_drive_falla_el_documento_viejo_sigue_vivo(): void
    {
        $this->drive->caido = true;
        $equipo = $this->equipoConRotc();

        $this->editarConRotc($equipo)->assertStatus(503);

        // Antes se borraba el viejo ANTES de subir el nuevo: con la subida caída se perdía.
        $this->assertSame('/storage/google/rotc-viejo?v=1', $equipo->documentacion()->first()->LINK_ROTC);
        Bus::assertNotDispatched(DeleteGoogleDriveFile::class);
    }
}
