<?php

namespace Tests\Feature;

use App\Jobs\DeleteGoogleDriveFile;
use App\Models\EquipoAuxiliar;
use App\Models\FrenteTrabajo;
use App\Models\Usuario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\DriveFalso;
use Tests\MySqlTestCase;

/**
 * Los PDF de auxiliares (propiedad y certificado) van a Google Drive por CUALQUIER camino
 * —botón de la ficha o formulario de crear/editar— y nunca al disco 'public', que nginx
 * sirve sin pedir sesión. Drive es un doble en memoria: no se sube nada a la cuenta real.
 */
class DocsAuxiliarEnDriveTest extends MySqlTestCase
{
    private DriveFalso $drive;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
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

    private function datosAuxiliar(Usuario $u): array
    {
        $frente = FrenteTrabajo::whereNotIn('ID_FRENTE', $u->getFrentesBloqueadosIds())->first();

        return [
            'TIPO'             => 'COMPRESOR',
            'MARCA'            => 'PRUEBA',
            'MODELO'           => 'DOC-DRIVE',
            'SERIAL'           => 'AUXDRIVE' . random_int(100000, 999999),
            'ESTADO_OPERATIVO' => 'OPERATIVO',
            'ID_FRENTE_ACTUAL' => $frente->ID_FRENTE,
        ];
    }

    private function pdf(string $nombre): UploadedFile
    {
        return UploadedFile::fake()->create($nombre, 40, 'application/pdf');
    }

    public function test_crear_con_pdfs_los_sube_a_drive_y_no_al_disco_publico(): void
    {
        $u     = $this->usuario();
        $datos = $this->datosAuxiliar($u);

        $this->actingAs($u)
            ->postJson(route('equipos-auxiliares.store'), $datos + [
                'doc_propiedad' => $this->pdf('propiedad.pdf'),
                'certificado'   => $this->pdf('certificado.pdf'),
            ])
            ->assertOk();

        $aux = EquipoAuxiliar::where('SERIAL', $datos['SERIAL'])->firstOrFail();
        $this->assertStringStartsWith('/storage/google/falso-', $aux->LINK_DOC_PROPIEDAD);
        $this->assertStringStartsWith('/storage/google/falso-', $aux->LINK_CERTIFICADO);
        $this->assertSame(2, $this->drive->subidos());
        $this->assertSame([], Storage::disk('public')->allFiles(), 'nada debe quedar en la carpeta pública');
    }

    public function test_editar_reemplaza_el_pdf_y_borra_el_viejo_despues_de_responder(): void
    {
        $u   = $this->usuario();
        $aux = EquipoAuxiliar::create($this->datosAuxiliar($u) + ['LINK_DOC_PROPIEDAD' => '/storage/google/viejo123?v=1']);

        $this->actingAs($u)
            ->patchJson(route('equipos-auxiliares.update', $aux->ID_AUXILIAR), $aux->only([
                'TIPO', 'MARCA', 'MODELO', 'SERIAL', 'ESTADO_OPERATIVO', 'ID_FRENTE_ACTUAL',
            ]) + ['doc_propiedad' => $this->pdf('nuevo.pdf')])
            ->assertOk();

        $this->assertStringStartsWith('/storage/google/falso-', $aux->fresh()->LINK_DOC_PROPIEDAD);
        Bus::assertDispatchedAfterResponse(DeleteGoogleDriveFile::class, 1);
    }

    public function test_guardar_sin_pdfs_no_sube_nada_a_drive(): void
    {
        $this->drive->caido = true; // si se llegara a usar, la subida fallaría
        $u     = $this->usuario();
        $datos = $this->datosAuxiliar($u);

        $this->actingAs($u)->postJson(route('equipos-auxiliares.store'), $datos)->assertOk();

        $this->assertTrue(EquipoAuxiliar::where('SERIAL', $datos['SERIAL'])->exists());
        $this->assertSame(0, $this->drive->subidos());
    }

    public function test_si_drive_falla_el_auxiliar_no_se_guarda(): void
    {
        $this->drive->caido = true;
        $u     = $this->usuario();
        $datos = $this->datosAuxiliar($u);

        $this->actingAs($u)
            ->postJson(route('equipos-auxiliares.store'), $datos + ['doc_propiedad' => $this->pdf('propiedad.pdf')])
            ->assertStatus(503)
            ->assertJsonFragment(['message' => 'No se pudo subir el PDF a Google Drive, así que el equipo auxiliar NO se guardó. Reintente en un momento.']);

        $this->assertFalse(EquipoAuxiliar::where('SERIAL', $datos['SERIAL'])->exists());
    }

    public function test_migracion_pasa_a_drive_los_que_usa_la_bd_y_retira_los_demas(): void
    {
        $u       = $this->usuario();
        $publico = Storage::disk('public');
        $publico->put('equipos_auxiliares/900/propiedad_1.pdf', 'pdf en uso');
        $publico->put('equipos_auxiliares/900/certificado_viejo.pdf', 'pdf que ya nadie usa');
        $enUso = EquipoAuxiliar::create($this->datosAuxiliar($u) + [
            'LINK_DOC_PROPIEDAD' => '/storage/equipos_auxiliares/900/propiedad_1.pdf',
        ]);

        (require database_path('migrations/2026_09_10_150000_mover_docs_auxiliares_publicos_a_drive.php'))->up();

        $this->assertStringStartsWith('/storage/google/falso-', $enUso->fresh()->LINK_DOC_PROPIEDAD);
        $this->assertSame([], $publico->allFiles(), 'la carpeta pública queda vacía');
        Storage::disk('local')->assertExists([
            'equipos_auxiliares_retirados/900/propiedad_1.pdf',
            'equipos_auxiliares_retirados/900/certificado_viejo.pdf',
        ]);
    }
}
