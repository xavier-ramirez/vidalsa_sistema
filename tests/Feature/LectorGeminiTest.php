<?php

namespace Tests\Feature;

use App\Models\Documentacion;
use App\Models\Equipo;
use App\Services\CargaMasivaDocumentos;
use App\Services\LectorDocumentoPdf;
use App\Services\LectorGemini;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\DriveFalso;
use Tests\MySqlTestCase;

/**
 * Segundo lector de documentos (Gemini).
 *
 * Lo que se prueba es lo que puede HACER DAÑO o costar dinero:
 *   · Sin clave no sale ni una consulta (el sistema trabaja como siempre).
 *   · El cupo diario se respeta: agotado, se devuelve null y no se vuelve a preguntar.
 *   · Lo que devuelve se limpia: fechas imposibles fuera, vehículos vacíos fuera.
 *   · En la carga masiva la IA solo entra cuando la lectura de siempre NO alcanzó, rellena
 *     huecos (nunca pisa lo ya leído) y la propuesta queda marcada para revisar.
 *
 * Nunca sale a la red: Http::fake para Gemini y DriveFalso para las subidas.
 */
class LectorGeminiTest extends MySqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        DriveFalso::instalar();
        Http::preventStrayRequests();
        config(['services.gemini.key' => 'clave-de-prueba', 'services.gemini.rpd' => 450]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        DriveFalso::quitar();
        parent::tearDown();
    }

    /** El lector real con la espera del ritmo anulada: la prueba no puede dormir 4 s por PDF. */
    private function lector(): LectorGemini
    {
        return new class extends LectorGemini {
            protected function esperar(int $segundos): void {}
        };
    }

    /** Una respuesta de Gemini con el JSON que el servicio espera. */
    private function respuesta(array $datos): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => json_encode($datos)]]]]]];
    }

    private function fakeGemini(array $datos): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->respuesta($datos))]);
    }

    // ── El servicio ───────────────────────────────────────────────────────────

    public function test_sin_clave_no_se_consulta_a_gemini(): void
    {
        config(['services.gemini.key' => '']);
        Http::fake();

        $this->assertFalse($this->lector()->disponible());
        $this->assertNull($this->lector()->leer('%PDF-falso'));
        Http::assertNothingSent();
    }

    public function test_limpia_lo_que_devuelve_gemini(): void
    {
        $this->fakeGemini([
            'tipo_documento' => 'poliza',
            'placa' => ' A12BC34 ',
            'serial_carroceria' => '8AJFR22G8F2517999',
            'fecha_emision' => '2026-04-08',
            'fecha_vencimiento' => '2026-02-31',      // día imposible: se descarta
            'numero_documento' => '',                  // vacío: null, no cadena vacía
            'vehiculos' => [['placa' => 'A55BB66', 'serial' => ''], ['placa' => '', 'serial' => '']],
            'seguro' => true,
        ]);

        $visto = $this->lector()->leer('%PDF-falso');

        $this->assertSame(LectorDocumentoPdf::POLIZA, $visto['tipo']);
        $this->assertSame('A12BC34', $visto['placa'], 'los espacios sobran');
        $this->assertSame('2026-04-08', $visto['emision']);
        $this->assertNull($visto['vence'], '31 de febrero no existe: no puede pasar');
        $this->assertNull($visto['nro']);
        $this->assertSame([['placa' => 'A55BB66', 'serial' => null]], $visto['vehiculos'], 'el vehículo sin nada se cae');
        $this->assertTrue($visto['seguro']);
    }

    public function test_una_respuesta_que_no_es_json_no_rompe_nada(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
            ['candidates' => [['content' => ['parts' => [['text' => 'no soy un JSON']]]]]]
        )]);

        $visto = $this->lector()->leer('%PDF-falso');

        $this->assertIsArray($visto);
        $this->assertNull($visto['placa']);
        $this->assertSame([], $visto['vehiculos']);
    }

    public function test_se_respeta_el_cupo_diario(): void
    {
        config(['services.gemini.rpd' => 1]);
        $this->fakeGemini(['tipo_documento' => 'titulo', 'placa' => 'A11BB22']);
        $lector = $this->lector();

        $this->assertNotNull($lector->leer('%PDF-uno'));
        $this->assertSame(0, $lector->restantesHoy());
        $this->assertNull($lector->leer('%PDF-dos'), 'sin cupo no se pregunta');
        Http::assertSentCount(1);
    }

    public function test_reintenta_cuando_gemini_dice_que_hay_demasiadas_consultas(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push('demasiadas consultas', 429)
            ->push($this->respuesta(['tipo_documento' => 'rotc', 'placa' => 'A99ZZ88'])),
        ]);

        $visto = $this->lector()->leer('%PDF-falso');

        $this->assertSame('A99ZZ88', $visto['placa']);
        Http::assertSentCount(2);
    }

    public function test_un_error_que_no_es_de_cupo_no_se_reintenta(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('modelo desconocido', 404)]);

        $this->assertNull($this->lector()->leer('%PDF-falso'));
        Http::assertSentCount(1);
    }

    // ── En la carga masiva ────────────────────────────────────────────────────

    /** Servicio de carga masiva con un OCR que devuelve lo que diga la prueba. */
    private function cargaMasiva(string $textoOcr): CargaMasivaDocumentos
    {
        $lectorOcr = new class($textoOcr) extends LectorDocumentoPdf {
            public function __construct(private string $texto) {}

            public function texto(string $driveId): string
            {
                return $this->texto;
            }
        };

        return new CargaMasivaDocumentos($lectorOcr, $this->lector());
    }

    private function equipo(): Equipo
    {
        $equipo = Equipo::create([
            'MARCA' => 'PRUEBA', 'MODELO' => 'LECTOR-IA', 'ANIO' => 2026,
            // 17 caracteres, como un serial de carroceria de verdad: es lo que el lector de
            // siempre reconoce dentro del texto (serialesEnTexto).
            'SERIAL_CHASIS' => 'TESTIA' . strtoupper(substr(uniqid(), -11)),
        ]);
        Documentacion::create(['ID_EQUIPO' => $equipo->ID_EQUIPO]);

        return $equipo->fresh();
    }

    /** Con contenido de verdad: un archivo vacio no se le manda a la IA (y fake()->create lo es). */
    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('documento.pdf', '%PDF-1.4 documento de prueba');
    }

    public function test_cuando_el_ocr_no_saca_nada_la_ia_engancha_el_equipo(): void
    {
        $equipo = $this->equipo();
        $this->fakeGemini([
            'tipo_documento' => 'rotc',
            'serial_carroceria' => $equipo->SERIAL_CHASIS,
            'fecha_vencimiento' => '2027-07-03',
        ]);

        // El OCR de Drive devolvió la hoja en blanco: sin la IA esto era "no se pudo leer".
        $p = $this->cargaMasiva('')->analizar($this->pdf());

        $this->assertTrue($p['ia'], 'la propuesta tiene que decir que la leyó la IA');
        $this->assertSame(LectorDocumentoPdf::ROTC, $p['tipo']);
        $this->assertSame('2027-07-03', $p['vence']);
        $this->assertSame([$equipo->ID_EQUIPO], array_column($p['equipos'], 'id'));
        $this->assertSame('revisar', $p['estado'], 'lo de la IA se mira antes de aplicarlo');
    }

    public function test_si_el_ocr_ya_lo_resolvio_no_se_gasta_una_consulta(): void
    {
        $equipo = $this->equipo();
        Http::fake();   // cualquier llamada a Gemini haría fallar la prueba

        $texto = "CERTIFICADO DE CIRCULACION ROTC\nSerial de Carroceria: {$equipo->SERIAL_CHASIS}\n"
               . "Fecha de Emisión: 11/02/2026\nFecha de Vencimiento: 11/02/2027\n";
        $p = $this->cargaMasiva($texto)->analizar($this->pdf());

        $this->assertFalse($p['ia']);
        $this->assertSame('listo', $p['estado']);
        $this->assertSame([$equipo->ID_EQUIPO], array_column($p['equipos'], 'id'));
        Http::assertNothingSent();
    }

    public function test_la_ia_rellena_huecos_pero_no_pisa_lo_que_ya_se_leyo(): void
    {
        $equipo = $this->equipo();
        // La IA se equivoca de año en el vencimiento y dice otra placa: no puede ganarle al OCR.
        $this->fakeGemini([
            'tipo_documento' => 'rotc',
            'serial_carroceria' => $equipo->SERIAL_CHASIS,
            'fecha_vencimiento' => '2099-01-01',
            'fecha_emision' => '2026-02-11',
        ]);

        // El OCR sacó el vencimiento pero no dio con el equipo (serial roto por el escaneo).
        $texto = "CERTIFICADO DE CIRCULACION ROTC\nSerial de Carroceria: XXXXXXXXXXXXXXXXX\n"
               . "Fecha de Vencimiento: 11/02/2027\n";
        $p = $this->cargaMasiva($texto)->analizar($this->pdf());

        $this->assertTrue($p['ia']);
        $this->assertSame('2027-02-11', $p['vence'], 'el vencimiento del OCR manda');
        $this->assertSame([$equipo->ID_EQUIPO], array_column($p['equipos'], 'id'));
    }

    public function test_sin_clave_la_carga_masiva_se_comporta_como_siempre(): void
    {
        config(['services.gemini.key' => '']);
        Http::fake();

        $p = $this->cargaMasiva('')->analizar($this->pdf());

        $this->assertSame('ilegible', $p['estado']);
        $this->assertStringContainsString('no tiene texto legible', $p['aviso']);
        $this->assertFalse($p['ia']);
        Http::assertNothingSent();
    }

    /**
     * Con el tipo forzado ("estos son pólizas") un PDF en blanco seguía siendo "no se pudo
     * leer": el tipo lo pone el usuario, pero del documento no se leyó nada.
     */
    public function test_un_pdf_en_blanco_con_el_tipo_forzado_sigue_siendo_ilegible(): void
    {
        config(['services.gemini.key' => '']);
        Http::fake();

        $p = $this->cargaMasiva('   ')->analizar($this->pdf(), LectorDocumentoPdf::POLIZA);

        $this->assertSame('ilegible', $p['estado'], 'no puede salir como "sin equipo": no se leyó nada');
        $this->assertStringContainsString('no tiene texto legible', $p['aviso']);
    }

    public function test_un_pdf_demasiado_grande_no_se_manda(): void
    {
        Http::fake();

        $this->assertNull($this->lector()->leer(str_repeat('x', 16 * 1024 * 1024)));
        Http::assertNothingSent();
    }

    // ── Lo soltado se ve en la tabla de Revisión de documentos ────────────────

    /**
     * El modal solo sirve para soltar archivos: el estado de cada PDF se ve en la MISMA tabla
     * que la revisión de la noche. Cada archivo analizado deja ahí su fila, sin tocar ninguna
     * ficha, hasta que alguien la aplique.
     */
    public function test_lo_soltado_deja_su_fila_en_la_tabla_de_revision(): void
    {
        $equipo = $this->equipo();
        $this->fakeGemini([
            'tipo_documento' => 'rotc',
            'serial_carroceria' => $equipo->SERIAL_CHASIS,
            'fecha_vencimiento' => '2027-07-03',
        ]);
        $this->actingAs($this->usuarioConPermiso());

        $p = $this->cargaMasiva('')->analizar($this->pdf());

        $fila = \App\Models\VerificacionDocumento::deCargaMasiva()
            ->where('DRIVE_ID', \App\Models\DocumentoAnexo::driveIdDeLink($p['link']))->first();

        $this->assertNotNull($fila, 'el PDF soltado tiene que salir en la tabla');
        $this->assertSame(\App\Models\VerificacionDocumento::POR_ENGANCHAR, $fila->ESTADO);
        $this->assertSame($equipo->ID_EQUIPO, (int) $fila->ID_EQUIPO);
        $this->assertSame('documento.pdf', $fila->ARCHIVO);
        $this->assertSame('rotc', $fila->TIPO);
        $this->assertTrue($fila->A_MANO, 'espera a que una persona lo aplique');
        // POR QUÉ es de ese equipo: el dato impreso en el PDF que cuadró exacto con la ficha.
        // Es lo que responde "¿y cómo sé que lo asoció al equipo correcto?" sin abrir el PDF.
        $this->assertStringContainsString('Reconocido por el serial ' . $equipo->SERIAL_CHASIS, (string) $fila->MOTIVO);
        // Y la ficha sigue intacta: nada se escribe hasta aplicar.
        $this->assertNull($equipo->documentacion()->first()->LINK_ROTC);
    }

    /** Un PDF que no se pudo leer también sale en la tabla: si no, desaparecería sin rastro. */
    public function test_lo_ilegible_tambien_sale_en_la_tabla(): void
    {
        config(['services.gemini.key' => '']);
        Http::fake();
        $this->actingAs($this->usuarioConPermiso());

        $p = $this->cargaMasiva('')->analizar($this->pdf());

        $fila = \App\Models\VerificacionDocumento::deCargaMasiva()
            ->where('DRIVE_ID', \App\Models\DocumentoAnexo::driveIdDeLink($p['link']))->first();

        $this->assertNotNull($fila);
        $this->assertSame(\App\Models\VerificacionDocumento::SIN_FICHA, $fila->ESTADO);
        $this->assertNull($fila->TIPO, 'no se reconoció qué documento es');
    }

    /** Al aplicarlo la fila pasa a "Aplicado": se ve qué pasó con cada archivo. */
    public function test_al_aplicar_la_fila_queda_como_aplicada(): void
    {
        $equipo = $this->equipo();
        $this->fakeGemini([
            'tipo_documento' => 'rotc',
            'serial_carroceria' => $equipo->SERIAL_CHASIS,
            'fecha_vencimiento' => '2027-07-03',
        ]);
        $u = $this->usuarioConPermiso();
        $this->actingAs($u);

        $servicio = $this->cargaMasiva('');
        $p = $servicio->analizar($this->pdf());
        $r = $servicio->aplicar($equipo->ID_EQUIPO, 'rotc', $p['link'], '2027-07-03', null);

        $this->assertTrue($r['ok'], $r['mensaje']);
        $fila = \App\Models\VerificacionDocumento::deCargaMasiva()
            ->where('DRIVE_ID', \App\Models\DocumentoAnexo::driveIdDeLink($p['link']))->first();

        $this->assertSame(\App\Models\VerificacionDocumento::APLICADO, $fila->ESTADO);
        $this->assertFalse($fila->A_MANO, 'ya no espera a nadie');
        $this->assertSame($u->ID_USUARIO, (int) $fila->APLICADO_POR);
        $this->assertSame($p['link'], $equipo->documentacion()->first()->LINK_ROTC);
    }

    /** Descartarlo lo borra de la tabla: no deja filas de PDF que ya no existen. */
    public function test_descartar_saca_la_fila_de_la_tabla(): void
    {
        config(['services.gemini.key' => '']);
        Http::fake();
        $this->actingAs($this->usuarioConPermiso());

        $servicio = $this->cargaMasiva('');
        $p = $servicio->analizar($this->pdf());
        $driveId = \App\Models\DocumentoAnexo::driveIdDeLink($p['link']);
        $this->assertNotNull(\App\Models\VerificacionDocumento::deCargaMasiva()->where('DRIVE_ID', $driveId)->first());

        $servicio->descartar($p['link']);

        $this->assertNull(\App\Models\VerificacionDocumento::deCargaMasiva()->where('DRIVE_ID', $driveId)->first());
    }

    /**
     * La tabla de Revisión de documentos es DONDE SE VE lo soltado: sale su fila, con el
     * nombre del PDF, y se encuentra buscando por ese nombre (es lo único que identifica un
     * archivo recién subido).
     */
    public function test_la_pantalla_enseña_lo_soltado_y_lo_encuentra_por_su_archivo(): void
    {
        $equipo = $this->equipo();
        $this->fakeGemini([
            'tipo_documento' => 'rotc',
            'serial_carroceria' => $equipo->SERIAL_CHASIS,
            'fecha_vencimiento' => '2027-07-03',
        ]);
        $u = $this->usuarioConPermiso();
        $this->actingAs($u);
        $this->cargaMasiva('')->analizar($this->pdf());

        // Filtrando por "Por aplicar" y buscando por el nombre del archivo.
        $html = $this->actingAs($u)
            ->get(route('historial-documentos.index', [
                'pestana' => 'documentos',
                'estado_doc' => \App\Models\VerificacionDocumento::POR_ENGANCHAR,
                'buscar' => 'documento.pdf',
            ]))->assertOk()->getContent();

        $this->assertStringContainsString('documento.pdf', $html, 'la tabla tiene que enseñar el archivo');
        $this->assertStringContainsString('Por aplicar', $html, 'con su estado');
        $this->assertStringContainsString($equipo->SERIAL_CHASIS, $html, 'y de qué ficha es');
    }

    /**
     * El "Revisado" en lote de la tabla NO toca las filas de la carga masiva: son propuestas de
     * un PDF que todavía no está en ninguna ficha. Darlas por revisadas las dejaría marcadas
     * como resueltas sin que el documento hubiera llegado a la ficha, y las que no tienen
     * equipo reconocido (ID_EQUIPO nulo) no tienen siquiera dónde escribir.
     */
    public function test_el_revisado_en_lote_no_toca_lo_de_la_carga_masiva(): void
    {
        $equipo = $this->equipo();
        $this->fakeGemini([
            'tipo_documento' => 'rotc',
            'serial_carroceria' => $equipo->SERIAL_CHASIS,
            'fecha_vencimiento' => '2027-07-03',
        ]);
        $u = $this->usuarioConPermiso();
        $this->actingAs($u);
        $p = $this->cargaMasiva('')->analizar($this->pdf());

        $fila = \App\Models\VerificacionDocumento::deCargaMasiva()
            ->where('DRIVE_ID', \App\Models\DocumentoAnexo::driveIdDeLink($p['link']))->firstOrFail();

        $r = $this->actingAs($u)->post(route('compresion-pdf.documentos.revisados'), ['ids' => [$fila->ID_REGISTRO]],
                                       ['Accept' => 'application/json']);
        $r->assertOk();
        $this->assertSame(0, $r->json('revisadas'), 'una propuesta de carga masiva no se da por revisada');

        $fila->refresh();
        $this->assertSame(\App\Models\VerificacionDocumento::POR_ENGANCHAR, $fila->ESTADO, 'sigue esperando que la apliquen');
        $this->assertNull($fila->APLICADO_POR);
        $this->assertNull($equipo->documentacion()->first()->LINK_ROTC, 'y la ficha sigue intacta');
    }

    /**
     * Un documento aplicado desde la carga masiva SIGUE pendiente para la revisión nocturna.
     *
     * Su fila ocupa la misma clave (equipo + tipo + archivo) que usaría la noche, pero solo
     * dice "alguien soltó este PDF y lo enlazó", no que se haya comparado con la ficha. Si
     * contara como leída, ese documento no se revisaría nunca y la barra de avance lo daría
     * por hecho: dos mentiras a la vez.
     */
    public function test_lo_aplicado_sigue_pendiente_para_la_revision_de_la_noche(): void
    {
        $equipo = $this->equipo();
        $this->fakeGemini([
            'tipo_documento' => 'rotc',
            'serial_carroceria' => $equipo->SERIAL_CHASIS,
            'fecha_vencimiento' => '2027-07-03',
        ]);
        $u = $this->usuarioConPermiso();
        $this->actingAs($u);

        $servicio = $this->cargaMasiva('');
        $p = $servicio->analizar($this->pdf());
        $servicio->aplicar($equipo->ID_EQUIPO, 'rotc', $p['link'], '2027-07-03', null);

        $pendiente = \App\Models\VerificacionDocumento::pendientes('rotc', 'LINK_ROTC')
            ->where('d.ID_EQUIPO', $equipo->ID_EQUIPO)->exists();

        $this->assertTrue($pendiente, 'la noche tiene que leerlo igual: nadie lo ha comparado con la ficha');
    }

    /** super.admin con la clave de la carga masiva; la transacción del caso lo revierte. */
    private function usuarioConPermiso(): \App\Models\Usuario
    {
        $u = \App\Models\Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->whereNotNull('PERMISOS')->get()
            ->first(fn ($usr) => in_array('super.admin', array_map('strtolower', $usr->PERMISOS), true));
        $this->assertNotNull($u, 'hace falta un super.admin activo');

        if (!in_array('docs.carga.masiva', $u->PERMISOS, true)) {
            $u->PERMISOS = array_merge($u->PERMISOS, ['docs.carga.masiva']);
            $u->save();
        }

        return $u;
    }

    public function test_la_clave_va_en_la_cabecera_y_no_en_la_direccion(): void
    {
        $this->fakeGemini(['tipo_documento' => 'titulo', 'placa' => 'A11BB22']);

        $this->lector()->leer('%PDF-falso');

        Http::assertSent(function ($peticion) {
            $this->assertStringNotContainsString('clave-de-prueba', $peticion->url(), 'la clave acabaría en el log');
            return $peticion->hasHeader('x-goog-api-key', 'clave-de-prueba');
        });
    }
}
