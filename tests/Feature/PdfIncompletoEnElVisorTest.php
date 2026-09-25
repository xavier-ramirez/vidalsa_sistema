<?php

namespace Tests\Feature;

use App\Jobs\DeleteGoogleDriveFile;
use App\Models\Equipo;
use App\Models\Usuario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\DriveFalso;
use Tests\MySqlTestCase;

/**
 * Un PDF cortado a medias, subido DESDE EL VISOR, tiene que decirle al usuario qué pasa.
 *
 * Por qué existe: la comprobación de PDF truncado (GoogleDriveService::comprobarPdfCompleto)
 * lanza PdfNoValido, pero los endpoints del visor lo cazaban en su `catch (\Exception)` y
 * respondían 500. El aviso de la pantalla solo muestra el mensaje del servidor cuando viene
 * con 422/403/409 (layout_ui.js), así que al usuario le salía "Error del servidor (500).
 * Verifique su archivo" en lugar de "el PDF está incompleto: vuelve a escanearlo" — y el
 * visor es el camino principal para reemplazar un documento.
 */
class PdfIncompletoEnElVisorTest extends MySqlTestCase
{
    /**
     * NADA sale a la red ni toca el Drive de verdad: DriveFalso cambia la API de Google por
     * un doble en memoria y Storage::fake se queda con las copias locales. Aquí el PDF se
     * rechaza ANTES de subir, pero el doble se instala igual: si mañana alguien cambia la
     * comprobación, esta prueba no puede acabar escribiendo en el Drive real.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Bus::fake([DeleteGoogleDriveFile::class]);
        DriveFalso::instalar();
    }

    protected function tearDown(): void
    {
        DriveFalso::quitar();
        parent::tearDown();
    }

    private function superAdmin(): Usuario
    {
        return $this->superAdminGlobal();
    }

    private function equipo(): Equipo
    {
        return Equipo::create([
            'MARCA' => 'TOYOTA', 'MODELO' => 'HILUX', 'ANIO' => 2020,
            'SERIAL_CHASIS' => 'S' . Str::random(12),
        ]);
    }

    /** PDF al que le falta el cierre %%EOF: el caso del ROTC que llegó truncado. */
    private function pdfCortado(): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'pdf') . '.pdf';
        file_put_contents($ruta, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n(se corta aqui");
        return new UploadedFile($ruta, 'rotc_cortado.pdf', 'application/pdf', null, true);
    }

    public function test_subir_un_pdf_cortado_desde_el_visor_explica_el_problema(): void
    {
        $equipo = $this->equipo();

        $r = $this->actingAs($this->superAdmin())->postJson(
            "/admin/equipos/{$equipo->ID_EQUIPO}/upload-doc",
            [
                'doc_type'        => 'poliza',
                'file'            => $this->pdfCortado(),
                'expiration_date' => now()->addYear()->toDateString(),
            ]
        );

        // 422 y NO 500: es el único status con el que la pantalla enseña el mensaje.
        $r->assertStatus(422)->assertJson(['success' => false]);
        $this->assertMatchesRegularExpression(
            '/incompleto|cortad|vuelve a|no es un PDF/i',
            (string) $r->json('message'),
            'El mensaje tiene que decirle al usuario qué hacer, no "Error del servidor".'
        );

        // Y el documento NO queda registrado en la ficha.
        $this->assertNull($equipo->fresh()->documentacion?->POLIZA);
    }

    public function test_lo_mismo_al_anexar_una_correccion(): void
    {
        $equipo = $this->equipo();

        $this->actingAs($this->superAdmin())->postJson(
            "/admin/equipos/{$equipo->ID_EQUIPO}/anexar-doc",
            ['doc_type' => 'poliza', 'file' => $this->pdfCortado()]
        )->assertStatus(422);
    }
}
