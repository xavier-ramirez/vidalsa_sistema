<?php

namespace Tests\Feature;

use App\Jobs\DeleteGoogleDriveFile;
use App\Models\Documentacion;
use App\Models\Equipo;
use App\Models\EquipoAuditLog;
use App\Models\Usuario;
use App\Models\VerificacionDocumento;
use App\Services\CargaMasivaDocumentos;
use App\Services\LectorDocumentoPdf;
use Illuminate\Support\Facades\Bus;
use Tests\MySqlTestCase;

/**
 * Carga masiva de documentos.
 *
 * Lo que se prueba es lo que puede HACER DAÑO: que no se pise un documento bueno y que no
 * se retroceda un vencimiento. El OCR de Drive no se toca aquí —eso es red— sino que se
 * comprueba por separado el reconocimiento del tipo, que es texto puro.
 */
class CargaMasivaDocumentosTest extends MySqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([DeleteGoogleDriveFile::class]);
    }

    private function servicio(): CargaMasivaDocumentos
    {
        return app(CargaMasivaDocumentos::class);
    }

    /**
     * super.admin real sin cambio de clave pendiente (si no, el middleware lo desvía), CON la
     * clave de la carga masiva: es de las exclusivas (Usuario::PERMISOS_EXPLICITOS) y ni
     * super.admin la hereda. Se le añade aquí dentro; la transacción del caso lo deshace.
     */
    private function usuario(): Usuario
    {
        $u = Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->whereNotNull('PERMISOS')->get()
            ->first(fn ($usr) => in_array('super.admin', array_map('strtolower', $usr->PERMISOS), true));
        $this->assertNotNull($u, 'hace falta un super.admin activo');

        if (!in_array('docs.carga.masiva', $u->PERMISOS, true)) {
            $u->PERMISOS = array_merge($u->PERMISOS, ['docs.carga.masiva']);
            $u->save();
        }

        return $u;
    }

    /** Equipo propio de la prueba; la transacción del caso lo revierte. */
    private function equipo(array $doc = []): Equipo
    {
        $equipo = Equipo::create([
            'MARCA' => 'PRUEBA', 'MODELO' => 'CARGA-MASIVA', 'ANIO' => 2026,
            'SERIAL_CHASIS' => 'TESTCM' . strtoupper(uniqid()),
        ]);
        Documentacion::create(['ID_EQUIPO' => $equipo->ID_EQUIPO] + $doc);

        return $equipo->fresh();
    }

    /** Auxiliar propio de la prueba; la transacción del caso lo revierte. */
    private function auxiliar(array $campos = []): \App\Models\EquipoAuxiliar
    {
        return \App\Models\EquipoAuxiliar::create([
            'TIPO' => 'PRUEBA', 'MARCA' => 'PRUEBA', 'MODELO' => 'CARGA-MASIVA-AUX',
            'SERIAL' => 'TESTAUX' . strtoupper(substr(uniqid(), -9)),
        ] + $campos)->fresh();
    }

    // ── Los seis documentos del equipo y los dos del auxiliar ─────────────────

    /**
     * El certificado asociado y la compraventa van a las mismas columnas que cuando se suben
     * de uno en uno (LINK_DOC_ADICIONAL y LINK_DOC_ADICIONAL_2). El certificado VENCE; la
     * compraventa no, y por eso entra sin fecha.
     */
    public function test_el_certificado_y_la_compraventa_van_a_sus_columnas(): void
    {
        $equipo = $this->equipo();
        $vence  = now()->addYear()->toDateString();
        $this->actingAs($this->usuario());

        $cert = $this->servicio()->aplicar($equipo->ID_EQUIPO, 'adicional', '/storage/google/cert', $vence, null);
        $venta = $this->servicio()->aplicar($equipo->ID_EQUIPO, 'adicional_2', '/storage/google/venta', null, null);

        $this->assertTrue($cert['ok'], $cert['mensaje']);
        $this->assertTrue($venta['ok'], $venta['mensaje']);
        $doc = $equipo->documentacion()->first();
        $this->assertSame('/storage/google/cert', $doc->LINK_DOC_ADICIONAL);
        $this->assertSame('/storage/google/venta', $doc->LINK_DOC_ADICIONAL_2);
        $this->assertStringStartsWith($vence, (string) $doc->getRawOriginal('FECHA_ADICIONAL'));
    }

    public function test_el_certificado_no_entra_sin_su_fecha_de_vencimiento(): void
    {
        $equipo = $this->equipo();
        $this->actingAs($this->usuario());

        $r = $this->servicio()->aplicar($equipo->ID_EQUIPO, 'adicional', '/storage/google/cert', null, null);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('fecha de vencimiento', $r['mensaje']);
        $this->assertNull($equipo->documentacion()->first()->LINK_DOC_ADICIONAL);
    }

    public function test_el_documento_de_un_auxiliar_va_a_su_ficha(): void
    {
        $aux   = $this->auxiliar();
        $vence = now()->addYear()->toDateString();
        $this->actingAs($this->usuario());

        $titulo = $this->servicio()->aplicar($aux->ID_AUXILIAR, 'propiedad', '/storage/google/aux-titulo', null, null, false, false, true);
        $cert   = $this->servicio()->aplicar($aux->ID_AUXILIAR, 'adicional', '/storage/google/aux-cert', $vence, null, false, false, true);

        $this->assertTrue($titulo['ok'], $titulo['mensaje']);
        $this->assertTrue($cert['ok'], $cert['mensaje']);
        $aux->refresh();
        $this->assertSame('/storage/google/aux-titulo', $aux->LINK_DOC_PROPIEDAD);
        $this->assertSame('/storage/google/aux-cert', $aux->LINK_CERTIFICADO);
        $this->assertStringStartsWith($vence, (string) $aux->getRawOriginal('FECHA_VENCIMIENTO_CERT'));
    }

    /** Un auxiliar no tiene dónde guardar una póliza, un ROTC ni un RACDA. */
    public function test_un_auxiliar_no_admite_poliza_rotc_ni_racda(): void
    {
        $aux = $this->auxiliar();
        $this->actingAs($this->usuario());

        foreach (['poliza', 'rotc', 'racda', 'adicional_2'] as $tipo) {
            $r = $this->servicio()->aplicar($aux->ID_AUXILIAR, $tipo, '/storage/google/x', now()->addYear()->toDateString(), null, false, false, true);
            $this->assertFalse($r['ok'], "el auxiliar no debería admitir $tipo");
        }
    }

    /** Las mismas puertas que en un equipo: no se pisa ni se retrocede un vencimiento. */
    public function test_el_auxiliar_no_pisa_lo_que_ya_tiene_ni_retrocede_el_vencimiento(): void
    {
        $vigente = now()->addYear()->toDateString();
        $aux = $this->auxiliar(['LINK_CERTIFICADO' => '/storage/google/viejo', 'FECHA_VENCIMIENTO_CERT' => $vigente]);
        $this->actingAs($this->usuario());

        $sinPisar = $this->servicio()->aplicar($aux->ID_AUXILIAR, 'adicional', '/storage/google/nuevo', $vigente, null, false, false, true);
        $this->assertFalse($sinPisar['ok']);
        $this->assertTrue($sinPisar['requiere_pisar'] ?? false);

        $anterior = now()->subMonths(6)->toDateString();
        $viejo = $this->servicio()->aplicar($aux->ID_AUXILIAR, 'adicional', '/storage/google/nuevo', $anterior, null, true, false, true);
        $this->assertFalse($viejo['ok'], 'un certificado anterior no entra ni marcando reemplazar');

        $this->assertSame('/storage/google/viejo', $aux->refresh()->LINK_CERTIFICADO);
    }

    // ── Reconocer de qué documento se trata ───────────────────────────────────

    /**
     * Manda el rótulo que aparece ANTES, no el más "fuerte": un documento se anuncia en su
     * encabezado y lo de después son menciones.
     *
     * Caso real (prueba del 23-09-2026 con los títulos de los equipos 20 y 23): el título de
     * propiedad del INTT se presenta en el carácter 3 y en su letra pequeña, por el 2.879,
     * nombra el "certificado de circulación". Antes ganaba esa mención y el título se repartía
     * como ROTC: al aplicarlo, el título habría ido a la casilla del ROTC y habría tapado el
     * ROTC bueno.
     */
    public function test_una_mencion_tardia_no_le_gana_al_rotulo_del_encabezado(): void
    {
        $titulo = 'Certificado de Registro de Vehículo. Instituto Nacional de Transporte Terrestre (INTT). '
            . str_repeat('Texto legal de relleno que no dice de qué documento se trata. ', 40)
            . 'El propietario deberá portar el certificado de circulación correspondiente.';

        $this->assertStringContainsString('certificado de circulación', $titulo, 'el caso pierde sentido sin la mención');
        $this->assertSame(LectorDocumentoPdf::PROPIEDAD, $this->servicio()->detectarTipo($titulo));
    }

    /**
     * El fallo simétrico: "INTT" dice QUIÉN emite el papel, no CUÁL es. El ROTC y la providencia
     * RACDA también los emite el INTT y lo ponen en el membrete, o sea ANTES de su propio
     * rótulo. Por eso esa pista no compite por posición: solo vale si no se anunció nada.
     */
    public function test_el_membrete_del_intt_no_convierte_un_rotc_en_un_titulo(): void
    {
        $s = $this->servicio();

        // Con el rotulo que trae el ROTC de verdad: "Certificado de Circulación" a secas lo
        // lleva tambien la colilla del titulo (ver test_la_colilla_del_titulo_no_lo_convierte_en_rotc).
        $this->assertSame(LectorDocumentoPdf::ROTC,
            $s->detectarTipo('INSTITUTO NACIONAL DE TRANSPORTE TERRESTRE (INTT). Certificado de Circulación de Vehículo de Carga N° 4455.'));
        $this->assertSame(LectorDocumentoPdf::RACDA,
            $s->detectarTipo('INTT. Providencia Administrativa N° 1120 que ampara las siguientes unidades.'));
        // Y si NADA se anuncia, la pista sigue valiendo: un título con el membrete y poco más.
        $this->assertSame(LectorDocumentoPdf::PROPIEDAD, $s->detectarTipo('Documento emitido por el INTT'));
    }

    public function test_reconoce_cada_tipo_de_documento_por_lo_que_dice_de_si_mismo(): void
    {
        $s = $this->servicio();

        $this->assertSame(LectorDocumentoPdf::RACDA,     $s->detectarTipo('PROVIDENCIA ADMINISTRATIVA N° 1120'));
        $this->assertSame(LectorDocumentoPdf::ROTC,      $s->detectarTipo('CERTIFICADO ROTC N° 4455 DEL INTT'));
        $this->assertSame(LectorDocumentoPdf::POLIZA,    $s->detectarTipo('CUADRO RECIBO DE PÓLIZA DE SEGURO'));
        $this->assertSame(LectorDocumentoPdf::PROPIEDAD, $s->detectarTipo('CERTIFICADO DE REGISTRO DE VEHÍCULO'));
        $this->assertNull($s->detectarTipo('FACTURA DE REPUESTOS VARIOS'));
    }

    /**
     * El orden importa: una providencia RACDA nombra pólizas y vehículos, así que si se
     * mirara "póliza" primero se repartiría como lo que no es.
     */
    public function test_una_providencia_que_nombra_polizas_sigue_siendo_racda(): void
    {
        $texto = 'PROVIDENCIA ADMINISTRATIVA N° 1120. LAS UNIDADES DEBEN TENER PÓLIZA DE SEGURO VIGENTE.';

        $this->assertSame(LectorDocumentoPdf::RACDA, $this->servicio()->detectarTipo($texto));
    }

    // ── Aplicar: el camino bueno ──────────────────────────────────────────────

    public function test_aplicar_deja_el_enlace_la_fecha_y_el_autor_en_la_ficha(): void
    {
        $equipo = $this->equipo();
        $vence  = now()->addYear()->toDateString();
        $this->actingAs($u = $this->usuario());

        $r = $this->servicio()->aplicar($equipo->ID_EQUIPO, 'rotc', '/storage/google/nuevo-rotc', $vence, null);

        $this->assertTrue($r['ok'], $r['mensaje']);
        $doc = $equipo->documentacion()->first();
        $this->assertSame('/storage/google/nuevo-rotc', $doc->LINK_ROTC);
        $this->assertStringStartsWith($vence, (string) $doc->getRawOriginal('FECHA_ROTC'));
        $this->assertSame($u->ID_USUARIO, (int) $doc->ROTC_SUBIDO_POR);
        $this->assertNotNull($doc->ROTC_FECHA_SUBIDA);
    }

    public function test_aplicar_queda_en_el_historial_diciendo_que_vino_de_la_carga_masiva(): void
    {
        $equipo = $this->equipo();
        $vence  = now()->addYear()->toDateString();
        $this->actingAs($this->usuario());

        $this->servicio()->aplicar($equipo->ID_EQUIPO, 'rotc', '/storage/google/nuevo-rotc', $vence, null);

        $subida = EquipoAuditLog::where('ID_EQUIPO', $equipo->ID_EQUIPO)->where('ACCION', 'upload_rotc')->first();
        $this->assertNotNull($subida, 'la subida no quedó en el historial');
        $this->assertSame('carga masiva', $subida->CAMBIOS['origen']);

        $fecha = EquipoAuditLog::where('ID_EQUIPO', $equipo->ID_EQUIPO)->where('ACCION', 'metadata_rotc')->first();
        $this->assertNotNull($fecha, 'la fecha no quedó en el historial');
        $this->assertSame(['antes' => null, 'despues' => $vence], $fecha->CAMBIOS['FECHA_ROTC']);
    }

    // ── Aplicar: lo que NO debe pasar ─────────────────────────────────────────

    public function test_no_pisa_un_documento_que_la_ficha_ya_tiene(): void
    {
        $equipo = $this->equipo(['LINK_ROTC' => '/storage/google/el-bueno', 'FECHA_ROTC' => now()->addYear()->toDateString()]);
        $this->actingAs($this->usuario());

        $r = $this->servicio()->aplicar($equipo->ID_EQUIPO, 'rotc', '/storage/google/otro', now()->addYear()->toDateString(), null);

        $this->assertFalse($r['ok']);
        $this->assertTrue($r['requiere_pisar'], 'la pantalla necesita saber que basta con marcar reemplazar');
        $this->assertSame('/storage/google/el-bueno', $equipo->documentacion()->first()->LINK_ROTC);
    }

    public function test_con_reemplazar_marcado_si_lo_cambia_y_borra_el_viejo_de_drive(): void
    {
        $equipo = $this->equipo(['LINK_ROTC' => '/storage/google/el-viejo', 'FECHA_ROTC' => now()->addMonths(2)->toDateString()]);
        $this->actingAs($this->usuario());

        $r = $this->servicio()->aplicar($equipo->ID_EQUIPO, 'rotc', '/storage/google/el-nuevo', now()->addYear()->toDateString(), null, true);

        $this->assertTrue($r['ok'], $r['mensaje']);
        $this->assertSame('/storage/google/el-nuevo', $equipo->documentacion()->first()->LINK_ROTC);
        Bus::assertDispatchedAfterResponse(DeleteGoogleDriveFile::class, 1);
    }

    /**
     * La regla que de verdad protege: un PDF que vence MUCHO antes de lo que ya dice la
     * ficha es el anterior. Aplicarlo retrocedería un vencimiento bueno, así que se niega
     * incluso con "reemplazar" marcado — es la misma regla de la revisión nocturna.
     */
    public function test_un_documento_anterior_no_entra_ni_marcando_reemplazar(): void
    {
        $vigente  = now()->addYear();
        $anterior = $vigente->copy()->subDays(VerificacionDocumento::DIAS_ANTERIOR + 30)->toDateString();
        $equipo   = $this->equipo(['LINK_ROTC' => '/storage/google/el-vigente', 'FECHA_ROTC' => $vigente->toDateString()]);
        $this->actingAs($this->usuario());

        $r = $this->servicio()->aplicar($equipo->ID_EQUIPO, 'rotc', '/storage/google/el-viejo', $anterior, null, true);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('ANTERIOR', $r['mensaje']);
        $doc = $equipo->documentacion()->first();
        $this->assertSame('/storage/google/el-vigente', $doc->LINK_ROTC, 'el documento bueno sigue en su sitio');
        $this->assertStringStartsWith($vigente->toDateString(), (string) $doc->getRawOriginal('FECHA_ROTC'));
    }

    public function test_un_documento_que_vence_no_se_aplica_sin_su_fecha(): void
    {
        $equipo = $this->equipo();
        $this->actingAs($this->usuario());

        $r = $this->servicio()->aplicar($equipo->ID_EQUIPO, 'poliza', '/storage/google/sin-fecha', null, null);

        $this->assertFalse($r['ok']);
        $this->assertNull($equipo->documentacion()->first()->LINK_POLIZA_SEGURO);
    }

    /** El título de propiedad NO vence: ese sí entra sin fecha. */
    public function test_el_titulo_de_propiedad_entra_sin_fecha_de_vencimiento(): void
    {
        $equipo = $this->equipo();
        $this->actingAs($this->usuario());

        $r = $this->servicio()->aplicar($equipo->ID_EQUIPO, 'propiedad', '/storage/google/titulo', null, '2020-05-10');

        $this->assertTrue($r['ok'], $r['mensaje']);
        $doc = $equipo->documentacion()->first();
        $this->assertSame('/storage/google/titulo', $doc->LINK_DOC_PROPIEDAD);
        $this->assertStringStartsWith('2020-05-10', (string) $doc->getRawOriginal('FECHA_EMISION_PROPIEDAD'));
    }

    /**
     * En LOCAL no se borra de Drive el documento reemplazado.
     *
     * El .env de desarrollo apunta al Drive REAL (mismas credenciales y carpetas que el
     * servidor) aunque la base sea una copia. Sin este freno, probar un reemplazo desde el
     * local borraria el PDF al que apunta el enlace del SERVIDOR y ese documento se perderia
     * para todos. Se deja huerfano, que no le hace daño a nadie.
     */
    public function test_en_local_no_borra_de_drive_el_documento_reemplazado(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $equipo = $this->equipo(['LINK_ROTC' => '/storage/google/el-del-servidor', 'FECHA_ROTC' => now()->addMonths(2)->toDateString()]);
        $this->actingAs($this->usuario());

        $r = $this->servicio()->aplicar($equipo->ID_EQUIPO, 'rotc', '/storage/google/el-nuevo', now()->addYear()->toDateString(), null, true);

        $this->assertTrue($r['ok'], $r['mensaje']);
        $this->assertSame('/storage/google/el-nuevo', $equipo->documentacion()->first()->LINK_ROTC, 'la ficha local SÍ se actualiza');
        Bus::assertNotDispatchedAfterResponse(DeleteGoogleDriveFile::class);
    }

    // ── Modo ensayo: comprueba todo y no escribe ──────────────────────────────

    /**
     * Es la forma de probar la pantalla contra los datos de VERDAD sin tocarlos: pasa por
     * todas las comprobaciones, dice que si, y la ficha se queda exactamente igual.
     */
    public function test_el_modo_ensayo_no_escribe_nada_en_la_ficha(): void
    {
        $equipo = $this->equipo();
        $vence  = now()->addYear()->toDateString();
        $this->actingAs($this->usuario());

        $r = $this->servicio()->aplicar($equipo->ID_EQUIPO, 'rotc', '/storage/google/ensayo', $vence, null, false, true);

        $this->assertTrue($r['ok'], $r['mensaje']);
        $this->assertTrue($r['ensayo']);
        $this->assertStringContainsString('ENSAYO', $r['mensaje']);

        $doc = $equipo->documentacion()->first();
        $this->assertNull($doc->LINK_ROTC, 'el ensayo ESCRIBIO el enlace');
        $this->assertNull($doc->getRawOriginal('FECHA_ROTC'), 'el ensayo ESCRIBIO la fecha');
        $this->assertNull($doc->ROTC_SUBIDO_POR, 'el ensayo ESCRIBIO el autor');
        $this->assertSame(0, EquipoAuditLog::where('ID_EQUIPO', $equipo->ID_EQUIPO)->count(),
            'el ensayo dejo rastro en el historial');
    }

    /** Un ensayo sobre un documento que ya esta tampoco pasa: niega igual que de verdad. */
    public function test_el_ensayo_niega_lo_mismo_que_negaria_de_verdad(): void
    {
        $equipo = $this->equipo(['LINK_ROTC' => '/storage/google/el-bueno', 'FECHA_ROTC' => now()->addYear()->toDateString()]);
        $this->actingAs($this->usuario());

        $r = $this->servicio()->aplicar($equipo->ID_EQUIPO, 'rotc', '/storage/google/otro', now()->addYear()->toDateString(), null, false, true);

        $this->assertFalse($r['ok']);
        $this->assertTrue($r['requiere_pisar']);
        $this->assertSame('/storage/google/el-bueno', $equipo->documentacion()->first()->LINK_ROTC);
    }

    /** Y el ensayo que SI reemplazaria lo dice, pero deja el PDF viejo donde estaba. */
    public function test_el_ensayo_de_un_reemplazo_no_borra_el_pdf_viejo(): void
    {
        $equipo = $this->equipo(['LINK_ROTC' => '/storage/google/el-viejo', 'FECHA_ROTC' => now()->addMonths(2)->toDateString()]);
        $this->actingAs($this->usuario());

        $r = $this->servicio()->aplicar($equipo->ID_EQUIPO, 'rotc', '/storage/google/el-nuevo', now()->addYear()->toDateString(), null, true, true);

        $this->assertTrue($r['ok'], $r['mensaje']);
        $this->assertStringContainsString('reemplazaría', $r['mensaje']);
        $this->assertSame('/storage/google/el-viejo', $equipo->documentacion()->first()->LINK_ROTC);
        Bus::assertNotDispatchedAfterResponse(DeleteGoogleDriveFile::class);
    }

    // ── Las puertas ───────────────────────────────────────────────────────────

    /** Ya no hay "reconocerlo solo": sin decir qué documento es, no se sube nada. */
    public function test_sin_elegir_el_tipo_no_se_analiza(): void
    {
        $pdf = \Illuminate\Http\UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.5\n" . str_repeat('x', 2048) . "\n%%EOF\n");

        $this->actingAs($this->usuario())
            ->post(route('historial-documentos.carga-masiva.analizar'), ['file' => $pdf], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tipo' => 'Elige primero']);
    }

    /**
     * Se cargó como título y el texto es de una póliza: no se asocia (aunque su serial sea de
     * una ficha), queda en el historial como "Otro documento" y se puede descartar.
     */
    public function test_un_pdf_que_es_otro_documento_queda_en_el_historial_sin_asociar(): void
    {
        $this->actingAs($this->usuario());
        $e = $this->equipo();

        $p = $this->soltar("CUADRO RECIBO DE POLIZA DE SEGURO\nSerial de Carroceria: {$e->SERIAL_CHASIS}\nHasta: 10/10/2027",
            LectorDocumentoPdf::PROPIEDAD);

        $this->assertSame(CargaMasivaDocumentos::OTRO_DOCUMENTO, $p['estado']);
        $this->assertSame([], $p['equipos']);
        $this->assertFalse($p['ia'], 'lo dijo el rotulo, sin IA');
        $this->assertStringContainsString('el PDF es poliza de seguro', $p['aviso']);

        $fila = VerificacionDocumento::deCargaMasiva()
            ->where('DRIVE_ID', \App\Models\DocumentoAnexo::driveIdDeLink($p['link']))->firstOrFail();
        $this->assertSame(VerificacionDocumento::OTRO_DOCUMENTO, $fila->ESTADO);
        $this->assertContains($fila->ESTADO, VerificacionDocumento::DE_LA_CARGA, 'se puede descartar desde la tabla');
        $this->assertFalse(app(CargaMasivaDocumentos::class)->propuestaAdmite($p['link'], $e->ID_EQUIPO, false, LectorDocumentoPdf::PROPIEDAD),
            'y no se puede aplicar a ninguna ficha');
    }

    public function test_sin_sesion_no_se_puede_analizar_ni_aplicar(): void
    {
        $this->post(route('historial-documentos.carga-masiva.analizar'), [], ['Accept' => 'application/json'])
            ->assertStatus(401);
        $this->post(route('historial-documentos.carga-masiva.aplicar'), [], ['Accept' => 'application/json'])
            ->assertStatus(401);
    }

    /**
     * La carga masiva tiene SU permiso y es de los exclusivos: un super.admin sin la clave
     * marcada no entra. Es la puerta de verdad; el menú solo esconde el botón.
     */
    public function test_un_super_admin_sin_la_clave_de_carga_masiva_no_entra(): void
    {
        $u = $this->usuario();
        $u->PERMISOS = array_values(array_diff($u->PERMISOS, ['docs.carga.masiva']));
        $u->save();

        $this->actingAs($u)
            ->post(route('historial-documentos.carga-masiva.analizar'), [], ['Accept' => 'application/json'])
            ->assertStatus(403);
        $this->actingAs($u)
            ->post(route('historial-documentos.carga-masiva.aplicar'), [], ['Accept' => 'application/json'])
            ->assertStatus(403);
        $this->actingAs($u)
            ->post(route('historial-documentos.carga-masiva.descartar'), [], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    public function test_aplicar_valida_lo_que_llega(): void
    {
        $this->actingAs($this->usuario())
            ->post(route('historial-documentos.carga-masiva.aplicar'),
                   ['id_equipo' => 999999999, 'tipo' => 'inventado', 'link' => 'http://otro-sitio/x.pdf'],
                   ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id_equipo', 'tipo', 'link']);
    }

    /** Un enlace que no sea de nuestro Drive no se guarda: es la puerta de entrada. */
    public function test_el_enlace_tiene_que_ser_de_drive(): void
    {
        $equipo = $this->equipo();

        $this->actingAs($this->usuario())
            ->post(route('historial-documentos.carga-masiva.aplicar'), [
                'id_equipo' => $equipo->ID_EQUIPO, 'tipo' => 'rotc',
                'link' => 'https://ejemplo.com/rotc.pdf', 'vence' => now()->addYear()->toDateString(),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('link');

        $this->assertNull($equipo->documentacion()->first()->LINK_ROTC);
    }

    // ── Solo se enlaza o se borra lo que se solto en la carga masiva ─────────

    /** La fila que anotar() deja al soltar un PDF: sin ella el enlace no es de esta pantalla. */
    private function propuesta(string $driveId, array $fichas = [], string $estado = VerificacionDocumento::POR_ENGANCHAR, string $tipo = 'rotc'): void
    {
        VerificacionDocumento::create([
            'DRIVE_ID' => $driveId, 'ORIGEN' => VerificacionDocumento::DE_CARGA_MASIVA,
            'TIPO' => $tipo, 'ARCHIVO' => $driveId . '.pdf', 'ESTADO' => $estado,
            'A_MANO' => true, 'INTENTOS' => 0,
            'PROPUESTA' => ['tipo' => $tipo, 'link' => '/storage/google/' . $driveId,
                'equipos' => array_map(fn ($e) => ['id' => $e->getKey(), 'auxiliar' => $e instanceof \App\Models\EquipoAuxiliar], $fichas)],
        ]);
    }

    /**
     * Descartar con el enlace de un documento MONTADO (una pestaña vieja, o la peticion
     * escrita a mano) mandaba a la papelera de Drive el documento bueno del equipo.
     */
    public function test_descartar_no_borra_un_pdf_que_ya_esta_en_una_ficha(): void
    {
        $equipo = $this->equipo(['LINK_ROTC' => '/storage/google/montado-cm', 'FECHA_ROTC' => now()->addYear()->toDateString()]);
        $this->propuesta('montado-cm', [$equipo]);   // su fila aun dice "sin aplicar", como en una pestaña vieja

        $this->actingAs($this->usuario())
            ->post(route('historial-documentos.carga-masiva.descartar'), ['link' => '/storage/google/montado-cm'], ['Accept' => 'application/json'])
            ->assertStatus(422);

        Bus::assertNotDispatchedAfterResponse(DeleteGoogleDriveFile::class);
        $this->assertSame('/storage/google/montado-cm', $equipo->documentacion()->first()->LINK_ROTC);
    }

    public function test_descartar_no_borra_un_archivo_que_no_es_de_la_carga(): void
    {
        $this->actingAs($this->usuario())
            ->post(route('historial-documentos.carga-masiva.descartar'), ['link' => '/storage/google/cualquiera'], ['Accept' => 'application/json'])
            ->assertStatus(422);

        Bus::assertNotDispatchedAfterResponse(DeleteGoogleDriveFile::class);
    }

    public function test_descartar_una_propuesta_sin_aplicar_si_la_borra(): void
    {
        $this->propuesta('suelto-cm');

        $this->actingAs($this->usuario())
            ->post(route('historial-documentos.carga-masiva.descartar'), ['link' => '/storage/google/suelto-cm'], ['Accept' => 'application/json'])
            ->assertOk();

        Bus::assertDispatchedAfterResponse(DeleteGoogleDriveFile::class, 1);
        $this->assertFalse(VerificacionDocumento::where('DRIVE_ID', 'suelto-cm')->exists());
    }

    /** El enlace llega del navegador: el documento de OTRO equipo no se engancha a este. */
    public function test_aplicar_solo_acepta_un_pdf_subido_por_la_carga(): void
    {
        $vence = now()->addYear()->toDateString();
        $this->equipo(['LINK_ROTC' => '/storage/google/de-otro-cm', 'FECHA_ROTC' => $vence]);
        $equipo = $this->equipo();

        $this->actingAs($this->usuario())
            ->post(route('historial-documentos.carga-masiva.aplicar'), [
                'id_equipo' => $equipo->ID_EQUIPO, 'tipo' => 'rotc', 'link' => '/storage/google/de-otro-cm', 'vence' => $vence,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertNull($equipo->documentacion()->first()->LINK_ROTC);

        // El mismo caso con un PDF que SI se solto aqui entra (y la fila pasa a Aplicado).
        $this->propuesta('nuevo-cm', [$equipo]);
        $this->post(route('historial-documentos.carga-masiva.aplicar'), [
            'id_equipo' => $equipo->ID_EQUIPO, 'tipo' => 'rotc', 'link' => '/storage/google/nuevo-cm', 'vence' => $vence,
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('/storage/google/nuevo-cm', $equipo->documentacion()->first()->LINK_ROTC);
        $this->assertSame(VerificacionDocumento::APLICADO, VerificacionDocumento::where('DRIVE_ID', 'nuevo-cm')->value('ESTADO'));
    }

    /** Subir otra vez el MISMO documento que ya esta montado no lo cambia sin preguntar. */
    public function test_el_mismo_pdf_que_ya_esta_montado_no_entra_sin_reemplazar(): void
    {
        $vence = now()->addYear()->toDateString();
        $equipo = $this->equipo(['LINK_ROTC' => '/storage/google/el-montado-cm', 'FECHA_ROTC' => $vence]);
        $this->propuesta('otra-copia-cm', [$equipo]);

        $this->actingAs($this->usuario())
            ->post(route('historial-documentos.carga-masiva.aplicar'), [
                'id_equipo' => $equipo->ID_EQUIPO, 'tipo' => 'rotc', 'link' => '/storage/google/otra-copia-cm', 'vence' => $vence,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJson(['requiere_pisar' => true]);

        $this->assertSame('/storage/google/el-montado-cm', $equipo->documentacion()->first()->LINK_ROTC);
        Bus::assertNotDispatchedAfterResponse(DeleteGoogleDriveFile::class);
    }

    // ── Reconocer y repartir: compraventa, auxiliares, flota, RACDA a medias ─

    /** Servicio con un OCR que devuelve lo que diga la prueba (Drive falso, sin IA). */
    private function lectorCon(string $texto): CargaMasivaDocumentos
    {
        $ocr = new class($texto) extends LectorDocumentoPdf {
            public function __construct(private string $t) {}
            public function texto(string $driveId): string { return $this->t; }
        };
        return new CargaMasivaDocumentos($ocr, app(\App\Services\LectorGemini::class));
    }

    private function soltar(string $texto, ?string $tipo = null, string $contenido = 'x'): array
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        config(['services.gemini.key' => null]);
        \Tests\DriveFalso::instalar();
        try {
            $pdf = \Illuminate\Http\UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.5\n" . str_repeat($contenido, 2048) . "\n%%EOF\n");
            // Sin tipo en la prueba: el que el usuario habría elegido, el que el PDF dice ser.
            $servicio = $this->lectorCon($texto);
            return $servicio->analizar($pdf, $tipo ?? $servicio->detectarTipo($texto));
        } finally {
            \Tests\DriveFalso::quitar();
        }
    }

    /** El serial de una soldadora es corto y va tras "S/N": igual se reconoce el auxiliar. */
    public function test_un_auxiliar_se_reconoce_por_su_serial_corto(): void
    {
        $this->actingAs($this->usuario());
        $aux = $this->auxiliar(['SERIAL' => 'U11' . random_int(10000000, 99999999)]);

        $p = $this->soltar("CERTIFICADO DE CALIBRACION\nSoldadora Lincoln\nS/N {$aux->SERIAL}", CargaMasivaDocumentos::CERTIFICADO);

        $this->assertSame([['id' => $aux->ID_AUXILIAR, 'auxiliar' => true]],
            array_map(fn ($f) => ['id' => $f['id'], 'auxiliar' => $f['auxiliar']], $p['equipos']));
    }

    /** Un auxiliar no guarda compraventa: se dice eso, no "no se sabe de quien es". */
    public function test_la_compraventa_de_un_auxiliar_dice_por_que_no_entra(): void
    {
        $this->actingAs($this->usuario());
        $aux = $this->auxiliar(['SERIAL' => 'U12' . random_int(10000000, 99999999)]);

        $p = $this->soltar("DOCUMENTO DE COMPRAVENTA\nPlanta electrica serial {$aux->SERIAL}", CargaMasivaDocumentos::COMPRAVENTA);

        $this->assertSame('sin_equipo', $p['estado']);
        $this->assertStringContainsString('auxiliar', $p['aviso']);
    }

    /** Un documento que nombra dos unidades registradas no sale "listo" con una al azar. */
    public function test_un_documento_de_varias_unidades_se_marca_para_revisar(): void
    {
        $this->actingAs($this->usuario());
        $a = $this->equipo(); $b = $this->equipo();

        // Los dos seriales sueltos en la hoja, sin que ninguno vaya detras de su rotulo: si uno
        // lo fuera, ese seria el del documento (ver el ROTC de flota real, mas abajo).
        $p = $this->soltar("ROTC\nUnidades amparadas: {$a->SERIAL_CHASIS} {$b->SERIAL_CHASIS}\nFecha de Vencimiento 10/10/2027");

        $this->assertSame('revisar', $p['estado']);
        $this->assertStringContainsString('varias unidades', $p['aviso']);
        $this->assertSame(min($a->ID_EQUIPO, $b->ID_EQUIPO), $p['equipos'][0]['id'], 'siempre la misma: la de ID mas bajo');
    }

    /** Soltar dos veces el MISMO archivo se avisa en la segunda. */
    public function test_el_mismo_archivo_soltado_dos_veces_se_avisa(): void
    {
        $this->actingAs($this->usuario());
        $e = $this->equipo();
        $texto = "CERTIFICADO DE CIRCULACION ROTC\nSerial de Carroceria {$e->SERIAL_CHASIS}\nFecha de Vencimiento 10/10/2027";
        $unico = 'q' . uniqid();

        // Un solo Drive falso para las dos: cada archivo tiene que salir con su propio id,
        // como en el Drive de verdad (DriveFalso numera desde 1 cada vez que se instala).
        \Illuminate\Support\Facades\Storage::fake('local');
        config(['services.gemini.key' => null]);
        \Tests\DriveFalso::instalar();
        try {
            $pdf = fn () => \Illuminate\Http\UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.5\n" . str_repeat($unico, 200) . "\n%%EOF\n");
            $this->assertSame('listo', $this->lectorCon($texto)->analizar($pdf(), LectorDocumentoPdf::ROTC)['estado']);
            $otra = $this->lectorCon($texto)->analizar($pdf(), LectorDocumentoPdf::ROTC);
        } finally {
            \Tests\DriveFalso::quitar();
        }

        $this->assertSame('revisar', $otra['estado']);
        $this->assertStringContainsString('ya se habia soltado', $otra['aviso']);
    }

    /**
     * Un RACDA se enlaza a varias fichas de una en una. La fila pasa a "Aplicado" con la
     * ULTIMA (cerrar=1), no con la primera: si se corta a medias, el boton sigue ahi y lo que
     * ya estaba responde "Ya estaba enlazado" en vez de preguntar si reemplazarlo.
     */
    public function test_un_racda_a_varias_fichas_se_cierra_con_la_ultima(): void
    {
        $vence = now()->addYear()->toDateString();
        $a = $this->equipo(); $b = $this->equipo();
        $this->propuesta('racda-cm', [$a, $b], VerificacionDocumento::POR_ENGANCHAR, 'racda');
        $this->actingAs($this->usuario());
        $ir = fn ($e, $cerrar) => $this->post(route('historial-documentos.carga-masiva.aplicar'), [
            'id_equipo' => $e->ID_EQUIPO, 'tipo' => 'racda', 'link' => '/storage/google/racda-cm', 'vence' => $vence, 'cerrar' => $cerrar,
        ], ['Accept' => 'application/json']);
        $estado = fn () => VerificacionDocumento::where('DRIVE_ID', 'racda-cm')->value('ESTADO');

        $ir($a, 0)->assertOk();
        $this->assertSame(VerificacionDocumento::POR_ENGANCHAR, $estado(), 'tras la primera sigue por aplicar');

        $ir($a, 0)->assertOk()->assertJson(['message' => 'Ya estaba enlazado.']);
        $ir($b, 1)->assertOk();
        $this->assertSame(VerificacionDocumento::APLICADO, $estado());
        $this->assertSame('/storage/google/racda-cm', $b->documentacion()->first()->LINK_RACDA);
    }

    /** Una propuesta solo entra en las fichas que ella misma nombra, y como su tipo. */
    public function test_una_propuesta_no_se_aplica_a_otra_ficha_ni_como_otro_tipo(): void
    {
        $vence = now()->addYear()->toDateString();
        $suya = $this->equipo(); $otra = $this->equipo();
        $this->propuesta('solo-suya-cm', [$suya]);
        $this->actingAs($this->usuario());

        $this->post(route('historial-documentos.carga-masiva.aplicar'), [
            'id_equipo' => $otra->ID_EQUIPO, 'tipo' => 'rotc', 'link' => '/storage/google/solo-suya-cm', 'vence' => $vence,
        ], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post(route('historial-documentos.carga-masiva.aplicar'), [
            'id_equipo' => $suya->ID_EQUIPO, 'tipo' => 'poliza', 'link' => '/storage/google/solo-suya-cm', 'vence' => $vence,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertNull($otra->documentacion()->first()->LINK_ROTC);
        $this->assertNull($suya->documentacion()->first()->LINK_POLIZA_SEGURO);
    }

    /** "Dar por revisada" una propuesta de la carga la dejaba "Coincide" sin haberse enlazado. */
    public function test_una_propuesta_de_la_carga_no_se_da_por_revisada(): void
    {
        $e = $this->equipo();
        $this->propuesta('sin-revisar-cm', [$e]);
        $fila = VerificacionDocumento::where('DRIVE_ID', 'sin-revisar-cm')->first();

        $this->actingAs($this->usuario())
            ->post(route('compresion-pdf.documento.revisado', ['id' => $fila->ID_REGISTRO]), [], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(VerificacionDocumento::POR_ENGANCHAR, $fila->refresh()->ESTADO);
    }

    // ── Con los formatos de documentos REALES (25-09-2026) ─────────────────────

    /**
     * ROTC de flota: la hoja nombra muchas unidades y el certificado de debajo es de UNA. Se
     * propone la del certificado, sin avisar de "varias", y con el vencimiento y la emision de
     * SU fila de la hoja (renovada), no los del certificado (viejo, 30/05/2026).
     */
    public function test_rotc_de_flota_real_propone_la_unidad_del_certificado_con_la_fecha_de_la_hoja(): void
    {
        $this->actingAs($this->usuario());
        $otra = $this->equipo(['PLACA' => 'Z87BE9R']);
        $otra->update(['SERIAL_CHASIS' => 'LZZPCMSC7SJ38946Z']);
        $suya = $this->equipo(['PLACA' => 'Z88EZ7A']);
        $suya->update(['SERIAL_CHASIS' => 'LA9B23GE5H1GHY69Z']);

        $p = $this->soltar("Fecha y Hora de Emisión: 03/07/2026 12:20:15 PM Pág. 12/34\n"
            . "FLOTA VEHICULAR DE TRANSPORTE DE CARGA\nREGISTRO DE OPERADORAS DE TRANSPORTE DE CARGA (ROTC)\n"
            . "Operadora: CONSTRUCTORA VIDALSA 27, C.A (J-29387719-9) Número de ROTC: 49199 Fecha de vencimiento: 03/07/2027\n"
            . "# Placa Marca Modelo Año Tipo de Vehículo N° de Ejes Serial Carrocería Vencimiento\n"
            . "171 Z87BE9R SINOTRUK ZZ4257V324JB1 2025 CAMION TRACTOR 3 12730 Ton. LZZPCMSC7SJ38946Z 03/07/2027\n"
            . "186 Z88EZ7A JAC HFC9380TJP 2017 BATEA 3 30480 Ton. LA9B23GE5H1GHY69Z 03/07/2027\n"
            . "CERTIFICADO DE CIRCULACIÓN DE VEHICULO DE CARGA\nRazón Social RIF Nro de ROTC\n"
            . "CONTRUCTORA VIDALSA 27, C.A J-29387719-9 49199\n"
            . "Placa Serial de Carrocería Marca - Modelo Año\nZ88EZ7A LA9B23GE5H1GHY69Z JAC - HFC9380TJP 2017\n"
            . "Fecha de Emisión Fecha de Vencimiento\n30/05/2025 30/05/2026\n");

        $this->assertSame(LectorDocumentoPdf::ROTC, $p['tipo']);
        $this->assertSame('listo', $p['estado'], (string) $p['aviso']);
        $this->assertSame([$suya->ID_EQUIPO], array_column($p['equipos'], 'id'));
        $this->assertSame('2027-07-03', $p['vence']);
        $this->assertSame('2026-07-03', $p['emision']);
    }

    /**
     * Las dos polizas reales (Pirámide y Seguros Constitución) salian "SEGUROS CARACAS": las
     * dos nombran Caracas en la direccion o la sucursal.
     */
    public function test_una_ciudad_en_la_direccion_no_decide_la_aseguradora(): void
    {
        $lector = app(LectorDocumentoPdf::class);
        $catalogo = [1 => 'SEGUROS CARACAS', 2 => 'PIRÁMIDE SEGUROS', 3 => 'SEGUROS CONSTITUCION'];

        $piramide = "CUADRO Y RECIBO DE PÓLIZAS\nSucursal: CARACAS\nVigencia del Seguro: 05/03/2026 al 05/03/2027\n"
            . "Correo electrónico: defensor-asegurado@segurospiramide.com.";
        $constitucion = "SEGURO DE AUTOMOVIL INDIVIDUAL CUADRO RECIBO\nCiudad: GRAN CARACAS\n"
            . "favor emitir cheque a nombre de SEGUROS CONSTITUCIÓN, CA";
        $caracas = "SEGUROS CARACAS DE LIBERTY MUTUAL\nCUADRO POLIZA\nCaracas, Venezuela";

        $this->assertSame(2, $lector->aseguradoraEnTexto($piramide, $catalogo));
        $this->assertSame(3, $lector->aseguradoraEnTexto($constitucion, $catalogo));
        $this->assertSame(1, $lector->aseguradoraEnTexto($caracas, $catalogo), 'por su nombre entero si se reconoce');
        $this->assertNull($lector->aseguradoraEnTexto("CUADRO RECIBO\nDomicilio: CARACAS", $catalogo));
    }

    /**
     * El titulo del INTT lleva abajo la colilla "CERTIFICADO DE CIRCULACIÓN": escaneado y con el
     * encabezado mal leido, salia como ROTC. El ROTC se sigue reconociendo.
     */
    public function test_la_colilla_del_titulo_no_lo_convierte_en_rotc(): void
    {
        $titulo = "INSTITUTO NACIONAL DE TRANSPORTE TERRESTRE\nSN de Redio de Vabteulo\n...\n"
            . "CERTIFICADO DE CIRCULACIÓN\nPlaca: A83BV4G\nCERTIFICADO DE REGISTRO\nDE VEHÍCULO\nPara ser archivado en lugar seguro.";
        $this->assertSame(LectorDocumentoPdf::PROPIEDAD, $this->servicio()->detectarTipo($titulo));
        $this->assertSame(LectorDocumentoPdf::ROTC, $this->servicio()->detectarTipo(
            "FLOTA VEHICULAR DE TRANSPORTE DE CARGA\nREGISTRO DE OPERADORAS DE TRANSPORTE DE CARGA (ROTC)"));
    }

    /** Una poliza escaneada con "O" por "0" en el serial (8XVC508SODDLD... por ...S0DDLD...). */
    public function test_el_serial_leido_con_o_por_cero_encuentra_el_equipo(): void
    {
        $this->actingAs($this->usuario());
        $e = $this->equipo();
        $e->update(['SERIAL_CHASIS' => '8XVC508S0DDLD' . random_int(1000, 9999)]);
        $mal = str_replace('S0DD', 'SODD', $e->SERIAL_CHASIS);

        $p = $this->soltar("SEGURO DE AUTOMOVIL INDIVIDUAL CUADRO RECIBO\nVigencia de Póliza Desde: 21/05/2025 Hasta: 21/05/2026\n"
            . "Capacidad-Carga: 1 TM.Serial Carroceria: $mal Serial Motor: 8140");

        $this->assertSame([$e->ID_EQUIPO], array_column($p['equipos'], 'id'));
    }

    // ── Documento de embarque (BL) ─────────────────────────────────────────────

    /** Un BL como los del 2do embarque (CONGENBILL), con los VIN que diga la prueba. */
    private function textoBl(array $vins, string $nro = 'HCLKGT99'): string
    {
        return "CODE NAME: \"CONGENBILL\". EDITION 1994\nBILL  OF  LADING  B/L NO. $nro\n"
            . "Vessel\nPort of loading\nHONCHO\nV.2512\nLONGKOU,CHINA\nPort of discharge\nGUANTA, VENEZUELA\n"
            . "Place and\ndate of issue\nFreight\npayable\nat\nGUANTA, VENEZUELA\n2025-07-20\n"
            . "ATTACHMENT\nV/V:HONCHO V2512   BL NO.:$nro\nITEM MODEL VIN NO. ENGINE NO.\n"
            . implode("\n", array_map(fn ($v, $i) => ($i + 1) . "\nZZ4257V344JB1\n$v\n1424L0687" . $i, $vins, array_keys($vins)));
    }

    /** Propuesta de BL ya anotada en la tabla (como la deja analizar), para probar aplicar. */
    private function propuestaBl(string $driveId, array $equipos, ?string $nro = 'HCLKGT99'): void
    {
        VerificacionDocumento::create([
            'DRIVE_ID' => $driveId, 'ORIGEN' => VerificacionDocumento::DE_CARGA_MASIVA,
            'TIPO' => CargaMasivaDocumentos::EMBARQUE, 'ARCHIVO' => $driveId . '.pdf',
            'ESTADO' => VerificacionDocumento::POR_ENGANCHAR, 'A_MANO' => true, 'INTENTOS' => 0,
            'PROPUESTA' => ['tipo' => CargaMasivaDocumentos::EMBARQUE, 'link' => '/storage/google/' . $driveId,
                'archivo' => $driveId . '.pdf',
                'equipos' => array_map(fn ($e) => ['id' => $e->ID_EQUIPO, 'auxiliar' => false, 'serial' => $e->SERIAL_CHASIS,
                    'vin_bl' => $e->SERIAL_CHASIS], $equipos),
                'embarque' => ['nro' => $nro, 'buque' => 'HONCHO V.2512', 'puerto_carga' => 'LONGKOU,CHINA',
                    'puerto_descarga' => 'GUANTA, VENEZUELA', 'fecha' => '2025-07-20', 'unidades' => count($equipos), 'no_registrados' => []]],
        ]);
    }

    private function aplicarBl(Equipo $e, string $driveId, bool $pisar = false): array
    {
        return $this->servicio()->aplicar($e->ID_EQUIPO, CargaMasivaDocumentos::EMBARQUE, '/storage/google/' . $driveId, null, null, $pisar);
    }

    /** El lector del BL con el formulario real: buque partido en dos lineas, fecha lejos del rotulo. */
    public function test_bl_con_los_rotulos_juntos_saca_igual_el_buque_y_la_fecha(): void
    {
        // Asi lo devolvio Drive con el RAQDLA16: los rotulos seguidos y los valores mas abajo.
        $bl = \App\Support\BillOfLading::leer(
            "BILL OF LADING B/L NO. RAQDLA16\nVessel\nPort of loading\nPort of discharge\nShipping mark\n"
            . "RUI AN YANG V.2524\nQINGDAO,CHINA\nLA GUAIRA,VENEZUELA\n10UNITS\nLEZDD2CC8SF132435 E425A001785\n"
            . "Freight payable at\nPlace and date of issue\nPrinted and sold by\nFr g Knudtzons Bogtrykkeri A/S\n"
            . "DK-1253 Copenhagen K\nTelefax + 4533931184\nby authority of the Baltic\nCouncil\n(BIMCO)\nNumber of original Bs/L\n"
            . "Signature\nTHREE\nLA GUAIRA,VENEZUELA,2025-11-22\n");
        $this->assertSame('RAQDLA16', $bl['nro']);
        $this->assertSame('RUI AN YANG V.2524', $bl['buque']);
        $this->assertSame('2025-11-22', $bl['fecha']);
        $this->assertSame(['LEZDD2CC8SF132435'], $bl['vins']);
    }

    public function test_el_lector_de_bl_saca_numero_buque_puertos_fecha_y_vins(): void
    {
        $bl = \App\Support\BillOfLading::leer($this->textoBl(['LZZPCMSC9SJ380599', 'LZZPCMSC7SJ389205']) . "\nLZZWADG49ST501 039");

        $this->assertSame('HCLKGT99', $bl['nro']);
        $this->assertSame('HONCHO V.2512', $bl['buque']);
        $this->assertSame('LONGKOU,CHINA', $bl['puerto_carga']);
        $this->assertSame('GUANTA, VENEZUELA', $bl['puerto_descarga']);
        $this->assertSame('2025-07-20', $bl['fecha']);
        $this->assertSame(['LZZPCMSC9SJ380599', 'LZZPCMSC7SJ389205'], $bl['vins']);
        $this->assertSame(['LZZWADG49ST501039'], $bl['vins_partidos'], 'el VIN partido por un espacio se une');
    }

    /**
     * Se suelta un BL: propone TODOS los equipos de su anexo (por VIN) y nombra los VIN que no
     * estan en el sistema. Tambien los equipos SIN fila de documentacion (antes no se hallaban).
     */
    public function test_un_bl_propone_sus_equipos_por_vin_y_nombra_los_que_faltan(): void
    {
        $this->actingAs($this->usuario());
        $a = $this->equipo();
        $sinDoc = Equipo::create(['MARCA' => 'PRUEBA', 'MODELO' => 'SIN-DOC', 'ANIO' => 2026,
            'SERIAL_CHASIS' => 'TESTCM' . strtoupper(uniqid())]);
        $falta = 'LZZ1ELSF4SJ413132';

        $p = $this->soltar($this->textoBl([$a->SERIAL_CHASIS, $sinDoc->SERIAL_CHASIS, $falta]), CargaMasivaDocumentos::EMBARQUE);

        $this->assertSame(CargaMasivaDocumentos::EMBARQUE, $p['tipo']);
        $this->assertSame('listo', $p['estado'], (string) $p['aviso']);
        $this->assertEqualsCanonicalizing([$a->ID_EQUIPO, $sinDoc->ID_EQUIPO], array_column($p['equipos'], 'id'));
        $this->assertSame([$falta], $p['embarque']['no_registrados']);
        $this->assertSame($a->SERIAL_CHASIS, collect($p['equipos'])->firstWhere('id', $a->ID_EQUIPO)['vin_bl'], 'el VIN tal como lo imprime el BL');
        $this->assertSame(3, $p['embarque']['unidades']);
        $this->assertStringContainsString('No estan en el sistema: ' . $falta, $p['aviso']);
    }

    /** Aplicar crea el embarque una vez y enlaza cada equipo; repetir no duplica nada. */
    public function test_aplicar_un_bl_crea_el_embarque_y_enlaza_cada_equipo(): void
    {
        $this->actingAs($this->usuario());
        $a = $this->equipo(); $b = $this->equipo();
        $this->propuestaBl('bl-uno', [$a, $b]);

        $this->assertTrue($this->aplicarBl($a, 'bl-uno')['ok']);
        $this->assertTrue($this->aplicarBl($b, 'bl-uno')['ok']);
        $this->assertSame('Ya estaba enlazado.', $this->aplicarBl($a, 'bl-uno')['mensaje']);

        $emb = \App\Models\Embarque::where('NRO_BL', 'HCLKGT99')->firstOrFail();
        $this->assertSame('/storage/google/bl-uno', $emb->LINK);
        $this->assertSame('HONCHO V.2512', $emb->BUQUE);
        $this->assertSame('2025-07-20', $emb->FECHA_EMBARQUE->format('Y-m-d'));
        $this->assertEqualsCanonicalizing([$a->ID_EQUIPO, $b->ID_EQUIPO], $emb->equipos()->pluck('equipos.ID_EQUIPO')->all());
        $this->assertSame($a->SERIAL_CHASIS, $a->embarques()->first()->pivot->VIN);
        $this->assertTrue(\App\Support\EnlacesDocumentos::sigueEnUso('bl-uno'), 'Drive no puede retirar el PDF de un BL en uso');
        $this->assertSame(VerificacionDocumento::APLICADO,
            VerificacionDocumento::where('DRIVE_ID', 'bl-uno')->value('ESTADO'));
    }

    /** Un equipo llega en UN embarque: moverlo a otro pide permiso. */
    public function test_un_equipo_en_otro_bl_no_se_mueve_sin_permiso(): void
    {
        $this->actingAs($this->usuario());
        $e = $this->equipo();
        $this->propuestaBl('bl-a', [$e], 'HCLKGTA1');
        $this->propuestaBl('bl-b', [$e], 'HCLKGTB2');
        $this->assertTrue($this->aplicarBl($e, 'bl-a')['ok']);

        $r = $this->aplicarBl($e, 'bl-b');
        $this->assertFalse($r['ok']);
        $this->assertTrue($r['requiere_pisar'] ?? false);
        $this->assertStringContainsString('HCLKGTA1', $r['mensaje']);

        $this->assertTrue($this->aplicarBl($e, 'bl-b', true)['ok']);
        $this->assertSame('HCLKGTB2', $e->embarques()->first()->NRO_BL);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('embarque_equipo')->where('ID_EQUIPO', $e->ID_EQUIPO)->count());
    }

    /** El mismo BL con OTRO PDF: cambiarlo se lo cambia a todos, asi que pide permiso. */
    public function test_otro_pdf_del_mismo_bl_pide_permiso_y_retira_el_viejo(): void
    {
        $this->actingAs($this->usuario());
        $a = $this->equipo(); $b = $this->equipo();
        $this->propuestaBl('bl-viejo', [$a]);
        $this->propuestaBl('bl-nuevo', [$a, $b]);
        $this->aplicarBl($a, 'bl-viejo');

        $r = $this->aplicarBl($b, 'bl-nuevo');
        $this->assertTrue($r['requiere_pisar'] ?? false);
        $this->assertNull($b->embarques()->first(), 'sin permiso no se enlaza');

        $this->assertTrue($this->aplicarBl($b, 'bl-nuevo', true)['ok']);
        $this->assertSame('/storage/google/bl-nuevo', \App\Models\Embarque::where('NRO_BL', 'HCLKGT99')->value('LINK'));
        $this->assertSame('Ya estaba enlazado.', $this->aplicarBl($a, 'bl-nuevo')['mensaje'], 'el otro equipo ya ve el PDF nuevo');
        Bus::assertDispatchedAfterResponse(DeleteGoogleDriveFile::class);
    }

    /** Una poliza soltada como BL no se asocia; y un auxiliar no lleva BL. */
    public function test_un_bl_que_no_lo_es_no_se_asocia_y_un_auxiliar_no_lleva_bl(): void
    {
        $this->actingAs($this->usuario());
        $e = $this->equipo();

        $p = $this->soltar("CUADRO RECIBO DE POLIZA DE SEGURO\nSerial: {$e->SERIAL_CHASIS}", CargaMasivaDocumentos::EMBARQUE);
        $this->assertSame(CargaMasivaDocumentos::OTRO_DOCUMENTO, $p['estado']);

        $aux = $this->auxiliar();
        $r = $this->servicio()->aplicar($aux->ID_AUXILIAR, CargaMasivaDocumentos::EMBARQUE, '/storage/google/x', null, null, false, false, true);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('auxiliar', $r['mensaje']);
    }

    /** La ficha del equipo pide su embarque aparte (no viaja en el listado). */
    public function test_la_ficha_ensena_el_embarque_del_equipo(): void
    {
        $u = $this->usuario();
        $this->actingAs($u);
        $e = $this->equipo();
        $this->propuestaBl('bl-ficha', [$e]);
        $this->aplicarBl($e, 'bl-ficha');

        $this->actingAs($u)->getJson(route('equipos.embarqueDoc', $e->ID_EQUIPO))
            ->assertOk()
            ->assertJsonPath('embarque.nro', 'HCLKGT99')
            ->assertJsonPath('embarque.fecha', '20/07/2025')
            ->assertJsonPath('embarque.link', '/storage/google/bl-ficha');

        $otro = $this->equipo();
        $this->actingAs($u)->getJson(route('equipos.embarqueDoc', $otro->ID_EQUIPO))
            ->assertOk()->assertJsonPath('embarque', null);
    }
}
