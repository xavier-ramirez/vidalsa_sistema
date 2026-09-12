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
        $vence  = now()->addYear()->toDateString();
        // Una gestion de renovacion abierta: con el documento nuevo y vigente, termina.
        Documentacion::where('ID_EQUIPO', $equipo->ID_EQUIPO)->update([
            'rotc_gestion_frente_id' => \App\Models\FrenteTrabajo::value('ID_FRENTE'),
            'rotc_gestion_fecha'     => now(),
        ]);

        $res = $this->actingAs($this->usuario())
            ->post("/admin/equipos/{$equipo->ID_EQUIPO}/upload-doc", ['doc_type' => 'rotc', 'file' => $this->pdf(), 'expiration_date' => $vence], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['success' => true, 'vencimiento' => $vence]);
        $link = $res->json('link');

        $doc = $equipo->documentacion()->first();
        $this->assertStringStartsWith('/storage/google/falso-1?v=', $link);
        $this->assertSame($link, $doc->LINK_ROTC);
        $this->assertStringStartsWith($vence, (string) $doc->getRawOriginal('FECHA_ROTC'), 'la fecha viaja con el PDF');
        $this->assertNull($doc->rotc_gestion_frente_id, 'la gestion termina con un documento vigente');
        // El historial guarda la fecha como una edicion del visor (antes → despues).
        $log = \App\Models\EquipoAuditLog::where('ID_EQUIPO', $equipo->ID_EQUIPO)->where('ACCION', 'metadata_rotc')->first();
        $this->assertNotNull($log, 'el cambio de fecha no quedo en el historial');
        $this->assertSame(['antes' => null, 'despues' => $vence], $log->CAMBIOS['FECHA_ROTC']);
        // El visor lo abre en el acto: tiene que salir del disco, no volver a bajarse de Drive.
        Storage::disk('local')->assertExists(GoogleDriveService::rutaCopiaLocal('falso-1'));
        Bus::assertDispatchedAfterResponse(DeleteGoogleDriveFile::class, 1);
    }

    public function test_un_documento_que_vence_no_se_sube_sin_fecha(): void
    {
        $equipo = $this->equipoConRotc();

        $this->actingAs($this->usuario())
            ->post("/admin/equipos/{$equipo->ID_EQUIPO}/upload-doc", ['doc_type' => 'rotc', 'file' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expiration_date');

        $this->assertSame('/storage/google/rotc-viejo?v=1', $equipo->documentacion()->first()->LINK_ROTC);
        $this->assertSame(0, $this->drive->subidos(), 'sin fecha no se llega a subir el archivo');
    }

    public function test_un_documento_que_no_vence_se_sube_sin_fecha(): void
    {
        $equipo = $this->equipoConRotc();

        $this->actingAs($this->usuario())
            ->post("/admin/equipos/{$equipo->ID_EQUIPO}/upload-doc", ['doc_type' => 'propiedad', 'file' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonMissingPath('vencimiento');
    }

    public function test_el_visor_no_deja_vaciar_la_fecha(): void
    {
        $equipo = $this->equipoConRotc();
        Documentacion::where('ID_EQUIPO', $equipo->ID_EQUIPO)->update(['FECHA_ROTC' => '2027-01-15']);

        $this->actingAs($this->usuario())
            ->post("/admin/equipos/{$equipo->ID_EQUIPO}/update-metadata", ['doc_type' => 'rotc', 'fecha_vencimiento' => ''])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'La fecha de vencimiento es obligatoria.']);

        $this->assertStringStartsWith('2027-01-15', (string) $equipo->documentacion()->first()->getRawOriginal('FECHA_ROTC'));
    }

    /** El formulario de editar equipo con un ROTC nuevo adjunto. */
    private function editarConRotc(Equipo $equipo, ?string $fechaRotc = null)
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
                'documentacion'    => ['PLACA' => '', 'FECHA_ROTC' => $fechaRotc ?? now()->addYear()->toDateString()],
                'doc_rotc'         => $this->pdf(),
            ], ['Accept' => 'application/json']);
    }

    public function test_editar_con_pdf_nuevo_y_la_fecha_vaciada_se_rechaza(): void
    {
        // Ya tenia fecha: era la del documento ANTERIOR, no vale para el nuevo.
        $equipo = $this->equipoConRotc();
        Documentacion::where('ID_EQUIPO', $equipo->ID_EQUIPO)->update(['FECHA_ROTC' => '2025-01-15']);

        $this->editarConRotc($equipo, '')
            ->assertStatus(422)
            ->assertJsonValidationErrors('documentacion.FECHA_ROTC');

        $this->assertSame('/storage/google/rotc-viejo?v=1', $equipo->documentacion()->first()->LINK_ROTC);
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
