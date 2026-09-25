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

        $this->assertSame(LectorDocumentoPdf::ROTC,
            $s->detectarTipo('INSTITUTO NACIONAL DE TRANSPORTE TERRESTRE (INTT). Certificado de Circulación N° 4455.'));
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
    private function propuesta(string $driveId, string $estado = VerificacionDocumento::POR_ENGANCHAR): void
    {
        VerificacionDocumento::create([
            'DRIVE_ID' => $driveId, 'ORIGEN' => VerificacionDocumento::DE_CARGA_MASIVA,
            'TIPO' => 'rotc', 'ARCHIVO' => $driveId . '.pdf', 'ESTADO' => $estado,
            'A_MANO' => true, 'INTENTOS' => 0,
        ]);
    }

    /**
     * Descartar con el enlace de un documento MONTADO (una pestaña vieja, o la peticion
     * escrita a mano) mandaba a la papelera de Drive el documento bueno del equipo.
     */
    public function test_descartar_no_borra_un_pdf_que_ya_esta_en_una_ficha(): void
    {
        $equipo = $this->equipo(['LINK_ROTC' => '/storage/google/montado-cm', 'FECHA_ROTC' => now()->addYear()->toDateString()]);
        $this->propuesta('montado-cm');   // su fila aun dice "sin aplicar", como en una pestaña vieja

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
        $this->propuesta('nuevo-cm');
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
        $this->propuesta('otra-copia-cm');

        $this->actingAs($this->usuario())
            ->post(route('historial-documentos.carga-masiva.aplicar'), [
                'id_equipo' => $equipo->ID_EQUIPO, 'tipo' => 'rotc', 'link' => '/storage/google/otra-copia-cm', 'vence' => $vence,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJson(['requiere_pisar' => true]);

        $this->assertSame('/storage/google/el-montado-cm', $equipo->documentacion()->first()->LINK_ROTC);
        Bus::assertNotDispatchedAfterResponse(DeleteGoogleDriveFile::class);
    }
}
