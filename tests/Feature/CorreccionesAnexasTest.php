<?php

namespace Tests\Feature;

use App\Http\Controllers\EquipoController;
use App\Models\DocumentoAnexo;
use App\Models\Documentacion;
use App\Models\Equipo;
use App\Models\Usuario;
use App\Services\GoogleDriveService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use Tests\MySqlTestCase;

/**
 * Correcciones ANEXAS a un documento del equipo, de punta a punta por HTTP.
 *
 * Lo que se protege aqui es una regla de negocio, no un detalle tecnico: una
 * poliza con una falta de ortografia en el PDF y su correccion tienen que estar
 * las DOS vigentes. Y anexar no puede borrar nada: el archivo anterior en Drive
 * es irrecuperable (files.delete no pasa por la papelera).
 *
 * NUNCA toca Google Drive. GoogleDriveService::getInstance() cachea en una
 * estatica privada y aqui se le mete un doble por reflexion, asi que el
 * controlador real corre entero sin que salga una sola llamada a la nube.
 */
class CorreccionesAnexasTest extends MySqlTestCase
{
    private object $drive;
    private Equipo $equipo;
    private Usuario $user;

    protected function setUp(): void
    {
        parent::setUp();

        // subirPdf deja la copia local de cada PDF subido: que sea en un disco de mentira,
        // no en storage/app/private/google_cache de la maquina.
        Storage::fake('local');

        // Hereda del servicio real para tener sus metodos de alto nivel (subirPdf, que usa
        // uploadDoc) sobre este uploadFile falso: nada sale a la red.
        $this->drive = new class extends GoogleDriveService {
            public array $subidos = [];
            public array $borrados = [];
            public function __construct() {}
            public function getRootFolderId() { return 'CARPETA_DE_PRUEBA'; }
            public function uploadFile($carpeta, $archivo, $nombre, $mime) {
                $this->subidos[] = $nombre;
                return (object) ['id' => 'FALSO_' . count($this->subidos) . '_' . substr(md5($nombre), 0, 8)];
            }
            public function deleteFile($id) { $this->borrados[] = $id; return true; }
        };
        $prop = (new ReflectionClass(GoogleDriveService::class))->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->drive);

        // Los jobs no se ejecutan: si algo intentara borrar, queda registrado.
        Bus::fake();

        $this->user = Usuario::get()->first(fn ($u) => $u->can('user.edit'));
        $this->assertNotNull($this->user, 'No hay usuario con permiso user.edit.');

        // Equipo propio, no uno real: asi el test no depende de que datos haya.
        $this->equipo = Equipo::create([
            'MARCA' => 'PRUEBA', 'MODELO' => 'ANEXOS', 'ANIO' => 2026,
            'SERIAL_CHASIS' => 'TEST-ANEXOS-' . uniqid(),
        ]);
    }

    protected function tearDown(): void
    {
        // La transaccion de MySqlTestCase revierte los datos; el singleton de
        // Drive es estatico y hay que soltarlo a mano o contamina el resto.
        $prop = (new ReflectionClass(GoogleDriveService::class))->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
        parent::tearDown();
    }

    private function pdf(string $nombre = 'x.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, "%PDF-1.4\n%%EOF");
    }

    private function subirPrincipal(): string
    {
        $res = $this->actingAs($this->user)->post(
            "/admin/equipos/{$this->equipo->ID_EQUIPO}/upload-doc",
            ['doc_type' => 'poliza', 'file' => $this->pdf('poliza.pdf')]
        );
        $res->assertOk()->assertJson(['success' => true]);
        return $res->json('link');
    }

    private function anexar(): array
    {
        $res = $this->actingAs($this->user)->post(
            "/admin/equipos/{$this->equipo->ID_EQUIPO}/anexar-doc",
            ['doc_type' => 'poliza', 'file' => $this->pdf('correccion.pdf')]
        );
        $res->assertOk()->assertJson(['success' => true]);
        return $res->json('anexo');
    }

    public function test_sin_documento_principal_no_se_puede_anexar_una_correccion(): void
    {
        $this->actingAs($this->user)->post(
            "/admin/equipos/{$this->equipo->ID_EQUIPO}/anexar-doc",
            ['doc_type' => 'poliza', 'file' => $this->pdf()]
        )->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_la_correccion_convive_con_el_principal_sin_reemplazarlo(): void
    {
        $principal = $this->subirPrincipal();
        $anexo = $this->anexar();

        $doc = Documentacion::where('ID_EQUIPO', $this->equipo->ID_EQUIPO)->first();
        $this->assertSame($principal, $doc->LINK_POLIZA_SEGURO, 'El principal se perdió al anexar.');
        $this->assertNotSame($principal, $anexo['link'], 'El anexo apunta al mismo archivo que el principal.');
        $this->assertTrue($anexo['vigente']);
    }

    public function test_anexar_no_borra_ningun_archivo_de_drive(): void
    {
        $this->subirPrincipal();
        $borradosAntes = count($this->drive->borrados);

        $this->anexar();

        $this->assertCount($borradosAntes, $this->drive->borrados, 'Anexar borró un archivo de Drive.');
        Bus::assertNotDispatched(\App\Jobs\DeleteGoogleDriveFile::class);
    }

    public function test_admite_varias_correcciones_y_las_numera_para_distinguirlas(): void
    {
        $this->subirPrincipal();
        $this->anexar();
        $this->anexar();

        // El rotulo lo numera anexosDoc() por POSICION, y solo si hay mas de una: el que
        // se guardo al subir quedaba desfasado al borrar una (ver anexoAArray).
        $lista = $this->actingAs($this->user)
            ->getJson("/admin/equipos/{$this->equipo->ID_EQUIPO}/anexos")
            ->assertOk()->json('anexos.poliza');
        $this->assertSame(
            [EquipoController::ROTULO_ANEXO . ' 1', EquipoController::ROTULO_ANEXO . ' 2'],
            array_column($lista, 'etiqueta')
        );
    }

    public function test_reemplazar_el_principal_conserva_las_correcciones_y_las_marca(): void
    {
        $this->subirPrincipal();
        $this->anexar();

        // Reemplazo por el camino de SIEMPRE (uploadDoc), que sí borra el anterior.
        $this->subirPrincipal();

        $lista = $this->actingAs($this->user)
            ->getJson("/admin/equipos/{$this->equipo->ID_EQUIPO}/anexos")
            ->json('anexos.poliza');

        $this->assertCount(1, $lista, 'La corrección se perdió al reemplazar el principal.');
        $this->assertFalse($lista[0]['vigente'], 'Debería quedar marcada como del documento anterior.');
        // Después de responder: el usuario no espera el viaje a Google (borrarTrasResponder).
        Bus::assertDispatchedAfterResponse(\App\Jobs\DeleteGoogleDriveFile::class);
    }

    public function test_sin_principal_ninguna_correccion_figura_como_vigente(): void
    {
        $this->subirPrincipal();
        $this->anexar();

        Documentacion::where('ID_EQUIPO', $this->equipo->ID_EQUIPO)
            ->update(['LINK_POLIZA_SEGURO' => null]);

        $lista = $this->actingAs($this->user)
            ->getJson("/admin/equipos/{$this->equipo->ID_EQUIPO}/anexos")
            ->json('anexos.poliza');

        $this->assertCount(1, $lista, 'La corrección debe seguir guardada aunque no haya principal.');
        $this->assertFalse($lista[0]['vigente']);
    }

    public function test_solo_los_tipos_con_anexos_admiten_correccion_y_los_demas_no_suben_nada(): void
    {
        $columnas = [
            'propiedad'   => 'LINK_DOC_PROPIEDAD',
            'poliza'      => 'LINK_POLIZA_SEGURO',
            'rotc'        => 'LINK_ROTC',
            'racda'       => 'LINK_RACDA',
            'adicional'   => 'LINK_DOC_ADICIONAL',
            'adicional_2' => 'LINK_DOC_ADICIONAL_2',
        ];
        Documentacion::create(['ID_EQUIPO' => $this->equipo->ID_EQUIPO]);

        foreach ($columnas as $tipo => $col) {
            Documentacion::where('ID_EQUIPO', $this->equipo->ID_EQUIPO)
                ->update([$col => '/storage/google/PPAL_' . $tipo . '?v=1']);

            $subidosAntes = count($this->drive->subidos);
            $resp = $this->actingAs($this->user)->post(
                "/admin/equipos/{$this->equipo->ID_EQUIPO}/anexar-doc",
                ['doc_type' => $tipo, 'file' => $this->pdf()]
            );

            if (in_array($tipo, EquipoController::TIPOS_CON_ANEXOS, true)) {
                $resp->assertOk()->assertJson(['success' => true]);
            } else {
                // Se rechaza ANTES de subir: antes el PDF ya estaba en Drive y quedaba huerfano.
                $resp->assertStatus(422);
                $this->assertCount($subidosAntes, $this->drive->subidos, "Se subio a Drive un {$tipo} que luego se rechazo.");
            }
        }

        $this->assertSame(count(EquipoController::TIPOS_CON_ANEXOS),
            DocumentoAnexo::where('ID_EQUIPO', $this->equipo->ID_EQUIPO)->count());
    }

    public function test_un_tipo_de_documento_inventado_se_rechaza(): void
    {
        $this->subirPrincipal();

        $this->actingAs($this->user)->postJson(
            "/admin/equipos/{$this->equipo->ID_EQUIPO}/anexar-doc",
            ['doc_type' => 'inventado', 'file' => $this->pdf()]
        )->assertStatus(422);
    }

    public function test_solo_se_aceptan_pdf(): void
    {
        $this->subirPrincipal();

        $this->actingAs($this->user)->postJson(
            "/admin/equipos/{$this->equipo->ID_EQUIPO}/anexar-doc",
            ['doc_type' => 'poliza', 'file' => UploadedFile::fake()->create('foto.jpg', 10, 'image/jpeg')]
        )->assertStatus(422);
    }

    public function test_sin_permiso_de_edicion_no_se_puede_anexar(): void
    {
        $sinPermiso = Usuario::get()->first(fn ($u) => !$u->can('user.edit'));
        if (!$sinPermiso) {
            $this->markTestSkipped('No hay ningún usuario sin permiso user.edit para probarlo.');
        }
        $this->subirPrincipal();

        $this->actingAs($sinPermiso)->postJson(
            "/admin/equipos/{$this->equipo->ID_EQUIPO}/anexar-doc",
            ['doc_type' => 'poliza', 'file' => $this->pdf()]
        )->assertStatus(403);
    }

    public function test_la_correccion_queda_registrada_en_la_auditoria(): void
    {
        $this->subirPrincipal();
        $this->anexar();

        $this->assertSame(1, \App\Models\EquipoAuditLog::where('ID_EQUIPO', $this->equipo->ID_EQUIPO)
            ->where('ACCION', 'anexo_poliza')->count());
    }
}
