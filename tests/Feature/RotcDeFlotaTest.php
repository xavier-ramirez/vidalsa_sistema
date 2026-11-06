<?php

namespace Tests\Feature;

use App\Jobs\DeleteGoogleDriveFile;
use App\Models\Documentacion;
use App\Models\Equipo;
use App\Models\Usuario;
use App\Models\VerificacionDocumento;
use App\Services\CargaMasivaDocumentos;
use App\Services\GoogleDriveService;
use App\Services\LectorDocumentoPdf;
use App\Services\RotcDeFlota;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\DriveFalso;
use Tests\MySqlTestCase;

/**
 * El ROTC de flota ENTERO partido por equipo: a cada uno la portada, la hoja de la tabla con
 * su fila y SU certificado recortado (ver RotcDeFlota).
 *
 * El PDF se fabrica aqui con TCPDF, con la misma disposicion que el del INTT (portada sin
 * texto, tabla apaisada con la cabecera de la flota y una fila por vehiculo, tres
 * certificados por pagina), y no con el real: ese trae los datos de toda la flota. Necesita
 * Ghostscript, como la compresion; sin el, se salta.
 */
class RotcDeFlotaTest extends MySqlTestCase
{
    private string $pdf;

    /** Tres vehiculos con certificado (hoja de certificados) y dos sin el. */
    private array $flota = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!app(RotcDeFlota::class)->disponible()) {
            $this->markTestSkipped('Hace falta Ghostscript.');
        }
        Storage::fake('local');
        Bus::fake([DeleteGoogleDriveFile::class]);
        config(['services.gemini.key' => null]);
        DriveFalso::instalar();

        $sufijo = strtoupper(substr(uniqid(), -4));
        foreach (range(1, 5) as $i) {
            $this->flota[] = ['placa' => 'Z' . $i . $sufijo . 'Q', 'serial' => 'LZZTEST' . $sufijo . str_pad((string) $i, 6, '0', STR_PAD_LEFT)];
        }
        $this->pdf = $this->fabricarRotc();
    }

    protected function tearDown(): void
    {
        DriveFalso::quitar();
        @unlink($this->pdf ?? '');
        parent::tearDown();
    }

    // ── Leer y partir ────────────────────────────────────────────────────────────

    public function test_lee_la_portada_las_filas_y_donde_esta_cada_certificado(): void
    {
        $f = app(RotcDeFlota::class)->leer($this->pdf);

        $this->assertSame([1], $f['portada']);
        $this->assertSame('2026-07-03', $f['emision']);
        $this->assertSame('2027-07-03', $f['vence']);
        $this->assertCount(5, $f['filas']);
        $this->assertSame([2, 2, 2, 3, 3], array_column($f['filas'], 'pagina'));
        $this->assertSame(array_column(array_slice($this->flota, 0, 3), 'placa'), array_column($f['certificados'], 'placa'));
        foreach ($f['certificados'] as $c) {
            $this->assertSame(4, $c['pagina']);
            $this->assertNotNull($c['recorte'], 'se mide donde esta cada certificado');
        }
    }

    /** La parte de un equipo trae SOLO su certificado: ni se ven ni se leen los de al lado. */
    public function test_la_parte_de_un_equipo_trae_solo_su_certificado(): void
    {
        $r = app(RotcDeFlota::class);
        $f = $r->leer($this->pdf);
        $segundo = $f['certificados'][1];
        $destino = tempnam(sys_get_temp_dir(), 'parte');

        $r->parte($this->pdf, $f, 2, $segundo, $destino);

        $paginas = $this->textoPorPagina($destino);
        @unlink($destino);
        $this->assertCount(3, $paginas, 'portada, hoja de la tabla y certificado');
        $this->assertStringContainsString($this->flota[1]['placa'], $paginas[1], 'la hoja trae su fila');
        $this->assertSame(1, substr_count($paginas[2], 'CERTIFICADO DE CIRCULACI'));
        $this->assertStringContainsString($this->flota[1]['placa'], $paginas[2]);
        $this->assertStringNotContainsString($this->flota[0]['placa'], $paginas[2]);
        $this->assertStringNotContainsString($this->flota[2]['placa'], $paginas[2]);
    }

    /** Una PARTE ya separada (una hoja y un certificado) no se vuelve a repartir. */
    public function test_una_parte_ya_separada_no_es_una_flota(): void
    {
        $r = app(RotcDeFlota::class);
        $f = $r->leer($this->pdf);
        $parte = tempnam(sys_get_temp_dir(), 'parte');
        $r->parte($this->pdf, $f, 2, $f['certificados'][0], $parte);

        $p = $this->soltar($parte);
        @unlink($parte);

        $this->assertArrayNotHasKey('flota_rotc', $p);
    }

    // ── En la carga masiva ───────────────────────────────────────────────────────

    public function test_la_carga_masiva_propone_cada_equipo_registrado_con_su_parte(): void
    {
        $con = $this->equipo($this->flota[0]);
        $sin = $this->equipo($this->flota[4]);

        $p = $this->soltar($this->pdf);

        $this->assertSame(LectorDocumentoPdf::ROTC, $p['tipo']);
        $this->assertSame('listo', $p['estado'], (string) $p['aviso']);
        $this->assertSame([$con->ID_EQUIPO, $sin->ID_EQUIPO], array_column($p['equipos'], 'id'));
        [$a, $b] = $p['equipos'];
        $this->assertSame([2, 4], [$a['rotc']['tabla'], $a['rotc']['cert']['pagina']]);
        $this->assertSame(3, $b['rotc']['tabla']);
        $this->assertNull($b['rotc']['cert'], 'no trae certificado en este documento');
        $this->assertStringContainsString('5 vehiculos en la tabla, 2 registrados', $p['aviso']);
    }

    /**
     * La emision va con el vencimiento de la FILA (el de la renovacion). Un certificado viejo
     * (de una renovacion anterior, vence otro dia) no le presta su emision: la ficha quedaria
     * con la emision de un documento y el vencimiento de otro. Uno del mismo periodo si.
     */
    public function test_la_emision_es_la_de_la_renovacion_si_el_certificado_es_viejo(): void
    {
        @unlink($this->pdf);
        $this->pdf = $this->fabricarRotc([0 => '02/07/2026 03/07/2027', 1 => '30/05/2025 30/05/2026']);
        $mismoPeriodo = $this->equipo($this->flota[0]);
        $viejo = $this->equipo($this->flota[1]);

        $p = $this->soltar($this->pdf);
        $porId = array_combine(array_column($p['equipos'], 'id'), array_column($p['equipos'], 'rotc'));

        $this->assertSame(['2027-07-03', '2026-07-02'], [$porId[$mismoPeriodo->ID_EQUIPO]['vence'], $porId[$mismoPeriodo->ID_EQUIPO]['emision']]);
        $this->assertSame(['2027-07-03', '2026-07-03'], [$porId[$viejo->ID_EQUIPO]['vence'], $porId[$viejo->ID_EQUIPO]['emision']],
            'certificado de mayo 2025: la emision es la de la tabla renovada');
    }

    /**
     * Se ata SOLO por el serial de chasis. Un equipo con la placa de una fila pero OTRO serial
     * (la placa paso a otro vehiculo) no se lleva la parte de ese camion.
     */
    public function test_la_placa_sola_no_ata_un_equipo_a_la_flota(): void
    {
        $bueno = $this->equipo($this->flota[0]);
        $this->equipo(['placa' => $this->flota[1]['placa'], 'serial' => 'LZZOTRO' . strtoupper(substr(uniqid(), -10))]);

        $p = $this->soltar($this->pdf);

        $this->assertSame([$bueno->ID_EQUIPO], array_column($p['equipos'], 'id'));
        $this->assertStringContainsString('1 registrados', $p['aviso']);
    }

    /**
     * Al leerlo, cada ficha recibe SOLA su parte con SUS fechas, y la fila pasa a "Aplicado";
     * volver a aplicar no sube otra.
     */
    public function test_aplicar_enlaza_a_cada_equipo_su_parte(): void
    {
        $con = $this->equipo($this->flota[0]);
        $sin = $this->equipo($this->flota[4]);
        $p = $this->soltar($this->pdf);

        foreach ([[$con, 3], [$sin, 2]] as [$e, $paginas]) {
            $doc = Documentacion::where('ID_EQUIPO', $e->ID_EQUIPO)->first();
            $this->assertNotSame($p['link'], $doc->LINK_ROTC, 'no el ROTC entero: su parte');
            $this->assertSame('2027-07-03', substr((string) $doc->getRawOriginal('FECHA_ROTC'), 0, 10));
            $this->assertSame('2026-07-03', substr((string) $doc->getRawOriginal('FECHA_EMISION_ROTC'), 0, 10));
            $copia = Storage::disk('local')->path(GoogleDriveService::rutaCopiaLocal(\App\Models\DocumentoAnexo::driveIdDeLink($doc->LINK_ROTC)));
            $this->assertCount($paginas, $this->textoPorPagina($copia));
        }
        $this->assertSame(VerificacionDocumento::APLICADO,
            VerificacionDocumento::where('DRIVE_ID', \App\Models\DocumentoAnexo::driveIdDeLink($p['link']))->value('ESTADO'));

        $r = app(CargaMasivaDocumentos::class)->aplicar($con->ID_EQUIPO, 'rotc', $p['link'], $p['vence'], $p['emision']);
        $this->assertSame('Ya estaba enlazado.', $r['mensaje']);
    }

    /**
     * Si la ficha ya tiene un ROTC que vence DESPUES, se queda el suyo y no queda una parte suelta
     * en Drive. No queda "Sin enlazar": no hay nada que enlazarle (antes se reintentaba cada hora).
     */
    public function test_sin_reemplazo_no_se_sube_nada(): void
    {
        $e = $this->equipo($this->flota[0], ['LINK_ROTC' => '/storage/google/rotc-viejo', 'FECHA_ROTC' => '2028-01-01']);
        $p = $this->soltar($this->pdf);

        $this->assertSame(1, GoogleDriveService::getInstance()->subidos(), 'solo el PDF soltado: la parte se arma solo si va a entrar');
        $this->assertSame('/storage/google/rotc-viejo', Documentacion::where('ID_EQUIPO', $e->ID_EQUIPO)->value('LINK_ROTC'));
        $fila = VerificacionDocumento::where('DRIVE_ID', \App\Models\DocumentoAnexo::driveIdDeLink($p['link']))->first();
        $this->assertSame(VerificacionDocumento::APLICADO, $fila->ESTADO);
        $this->assertSame('ROTC de 5 unidades: ninguna enlazada. 1 ya tenia el suyo.', $fila->MOTIVO);
    }

    /**
     * La lista renovada trae a TODA la flota, pero a los que ya estaban les anexa su certificado
     * viejo. El que tiene su ROTC vigente se queda con el suyo aunque la lista venza despues; el
     * que entra en esta renovacion (certificado nuevo) si se cambia. (05-11-2026: la carga le
     * cambio el ROTC bueno a 276 vehiculos.)
     */
    public function test_rotc_vigente_y_certificado_viejo_se_queda_el_suyo(): void
    {
        @unlink($this->pdf);
        $this->pdf = $this->fabricarRotc([1 => '30/05/2025 30/05/2026']);
        $nuevo = $this->equipo($this->flota[0], ['LINK_ROTC' => '/storage/google/rotc-de-antes-0', 'FECHA_ROTC' => '2027-02-11']);
        $viejo = $this->equipo($this->flota[1], ['LINK_ROTC' => '/storage/google/rotc-de-antes-1', 'FECHA_ROTC' => '2027-02-11']);
        $sinCert = $this->equipo($this->flota[4], ['LINK_ROTC' => '/storage/google/rotc-de-antes-4', 'FECHA_ROTC' => '2027-02-11']);

        $p = $this->soltar($this->pdf);

        $this->assertNotSame('/storage/google/rotc-de-antes-0', Documentacion::where('ID_EQUIPO', $nuevo->ID_EQUIPO)->value('LINK_ROTC'),
            'certificado de esta renovacion: se cambia');
        $this->assertSame('/storage/google/rotc-de-antes-1', Documentacion::where('ID_EQUIPO', $viejo->ID_EQUIPO)->value('LINK_ROTC'),
            'certificado viejo: se queda el suyo');
        $this->assertSame('/storage/google/rotc-de-antes-4', Documentacion::where('ID_EQUIPO', $sinCert->ID_EQUIPO)->value('LINK_ROTC'),
            'sin certificado en este documento: se queda el suyo');
        $this->assertSame('2027-02-11', substr((string) Documentacion::where('ID_EQUIPO', $viejo->ID_EQUIPO)->value('FECHA_ROTC'), 0, 10));
        $this->assertSame(2, GoogleDriveService::getInstance()->subidos(), 'el PDF soltado y UNA parte: no se arman las que no entran');
        $this->assertSame('ROTC de 5 unidades: 1 enlazada, 2 por enlazar. 2 ya tenian el suyo.',
            VerificacionDocumento::where('DRIVE_ID', \App\Models\DocumentoAnexo::driveIdDeLink($p['link']))->value('MOTIVO'),
            'los que se quedan con el suyo no quedan por enlazar: solo los 2 que no estan en el sistema');
    }

    /** Con el ROTC VENCIDO, la lista renovada si entra aunque traiga el certificado viejo. */
    public function test_rotc_vencido_si_se_renueva_con_la_lista(): void
    {
        $e = $this->equipo($this->flota[4], ['LINK_ROTC' => '/storage/google/rotc-vencido', 'FECHA_ROTC' => '2026-05-30']);

        $this->soltar($this->pdf);

        $doc = Documentacion::where('ID_EQUIPO', $e->ID_EQUIPO)->first();
        $this->assertNotSame('/storage/google/rotc-vencido', $doc->LINK_ROTC);
        $this->assertSame('2027-07-03', substr((string) $doc->getRawOriginal('FECHA_ROTC'), 0, 10));
    }

    /**
     * La misma flota soltada en varios PDF (uno por placa, cada uno con algunos certificados):
     * el primero le pone a un vehiculo su parte SIN certificado; el que si lo trae la cambia.
     */
    public function test_la_parte_sin_certificado_se_cambia_por_la_que_lo_trae(): void
    {
        $e = $this->equipo($this->flota[3]);

        $this->soltar($this->pdf);
        $sinCert = Documentacion::where('ID_EQUIPO', $e->ID_EQUIPO)->value('LINK_ROTC');
        $this->assertCount(2, $this->paginasDe($sinCert), 'portada y tabla: este PDF no trae su certificado');

        $otro = $this->fabricarRotc([], [3, 4]);
        try {
            $this->soltar($otro);
        } finally {
            @unlink($otro);
        }

        $conCert = Documentacion::where('ID_EQUIPO', $e->ID_EQUIPO)->value('LINK_ROTC');
        $this->assertNotSame($sinCert, $conCert);
        $this->assertCount(3, $this->paginasDe($conCert), 'portada, tabla y SU certificado');

        // Y uno sin su certificado ya no le quita el que lo trae.
        $this->soltar($this->fabricarRotc([], [0]));
        $this->assertSame($conCert, Documentacion::where('ID_EQUIPO', $e->ID_EQUIPO)->value('LINK_ROTC'));
    }

    /**
     * Lo que manda es el PDF que tiene la ficha, no lo que dijo la carga que lo armo: una parte
     * con el certificado de OTRO camion se cambia; la que trae el suyo se queda. (05-11-2026: un
     * PDF mal hecho le dio a la ficha 546 el certificado de la 757.)
     */
    public function test_se_lee_el_certificado_que_tiene_la_ficha(): void
    {
        $flota = app(RotcDeFlota::class);
        $leido = $flota->leer($this->pdf);
        $cert = fn (int $i) => collect($leido['certificados'])->firstWhere('serial', $this->flota[$i]['serial']);
        $guardar = function (string $id, int $i) use ($flota, $leido, $cert) {
            $ruta = Storage::disk('local')->path(GoogleDriveService::rutaCopiaLocal($id));
            @mkdir(dirname($ruta), 0777, true);
            $flota->parte($this->pdf, ['portada' => $leido['portada']], 2, $cert($i), $ruta);
            return '/storage/google/' . $id;
        };
        $ajeno = $this->equipo($this->flota[1], ['LINK_ROTC' => $guardar('parte-con-cert-ajeno', 0), 'FECHA_ROTC' => '2027-07-03']);
        $suyo = $this->equipo($this->flota[2], ['LINK_ROTC' => $guardar('parte-con-su-cert', 2), 'FECHA_ROTC' => '2027-07-03']);

        $this->soltar($this->pdf);

        $this->assertNotSame('/storage/google/parte-con-cert-ajeno', Documentacion::where('ID_EQUIPO', $ajeno->ID_EQUIPO)->value('LINK_ROTC'));
        $this->assertSame('/storage/google/parte-con-su-cert', Documentacion::where('ID_EQUIPO', $suyo->ID_EQUIPO)->value('LINK_ROTC'));
    }

    /**
     * Si el ROTC que ya tiene la ficha no se puede leer (Drive caido, PDF roto) no se decide nada:
     * ni se cambia ni se da por bueno. Queda por enlazar y lo retoma el reintento de cada hora.
     * (05-11-2026: en un ensayo, 24 fallas de red habrian dejado 24 unidades sin su certificado.)
     */
    public function test_si_no_se_lee_el_suyo_queda_por_enlazar(): void
    {
        $e = $this->equipo($this->flota[1], ['LINK_ROTC' => '/storage/google/rotc-que-no-se-lee', 'FECHA_ROTC' => '2027-07-03']);

        $p = $this->soltar($this->pdf);

        $this->assertSame('/storage/google/rotc-que-no-se-lee', Documentacion::where('ID_EQUIPO', $e->ID_EQUIPO)->value('LINK_ROTC'));
        $this->assertSame(VerificacionDocumento::POR_ENGANCHAR,
            VerificacionDocumento::where('DRIVE_ID', \App\Models\DocumentoAnexo::driveIdDeLink($p['link']))->value('ESTADO'));
    }

    // ── Ayudas ───────────────────────────────────────────────────────────────────

    private function paginasDe(string $link): array
    {
        $id = \App\Models\DocumentoAnexo::driveIdDeLink($link);
        return $this->textoPorPagina(Storage::disk('local')->path(GoogleDriveService::rutaCopiaLocal($id)));
    }

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

    private function equipo(array $v, array $doc = []): Equipo
    {
        $e = Equipo::create(['MARCA' => 'SINOTRUK', 'MODELO' => 'PRUEBA-FLOTA', 'ANIO' => 2025, 'SERIAL_CHASIS' => $v['serial']]);
        Documentacion::create(['ID_EQUIPO' => $e->ID_EQUIPO, 'PLACA' => $v['placa']] + $doc);
        return $e->fresh();
    }

    /** Suelta el PDF en la carga masiva; el "OCR de Drive" dice lo que dice la hoja de la tabla. */
    private function soltar(string $pdf): array
    {
        $this->actingAs($this->usuario());
        $ocr = new class extends LectorDocumentoPdf {
            public function texto(string $driveId): string
            {
                return "FLOTA VEHICULAR DE TRANSPORTE DE CARGA\nREGISTRO DE OPERADORAS DE TRANSPORTE DE CARGA (ROTC)";
            }
        };
        $copia = tempnam(sys_get_temp_dir(), 'up');
        copy($pdf, $copia);
        return (new CargaMasivaDocumentos($ocr, app(\App\Services\LectorGemini::class)))
            ->analizar(new UploadedFile($copia, 'rotc_flota.pdf', 'application/pdf', null, true), LectorDocumentoPdf::ROTC);
    }

    /** El texto de cada pagina de un PDF (Ghostscript), para mirar que hay en cada una. */
    private function textoPorPagina(string $pdf): array
    {
        $gs = (string) config('services.compresion_pdf.ghostscript', 'gs');
        $p = new Process([$gs, '-q', '-dNODISPLAY', '-dSAFER', '-dNOPAUSE', '-dBATCH', '--permit-file-read=' . $pdf, '-dPDFINFO', $pdf]);
        $p->run();
        preg_match('/File has (\d+) pages?/', $p->getOutput() . $p->getErrorOutput(), $m);
        $paginas = [];
        foreach (range(1, (int) ($m[1] ?? 0)) as $n) {
            $t = new Process([$gs, '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '--permit-file-read=' . $pdf,
                '-sDEVICE=txtwrite', '-sPageList=' . $n, '-sOutputFile=-', $pdf]);
            $t->run();
            $paginas[] = $t->getOutput();
        }
        return $paginas;
    }

    /**
     * El ROTC de mentira: portada (un recuadro, sin texto), dos hojas de la tabla apaisadas
     * (3 y 2 filas) y una hoja con los certificados de los tres primeros, a 190 puntos uno de
     * otro y separados por una linea de guiones, como el del INTT.
     *
     * $fechasCert cambia la linea de fechas del certificado de un vehiculo ([indice => 'emision
     * vence']): el INTT renueva la lista pero deja el certificado viejo de los que ya estaban.
     * $conCert dice de que vehiculos trae certificado (por defecto, los tres primeros).
     */
    private function fabricarRotc(array $fechasCert = [], array $conCert = [0, 1, 2]): string
    {
        $pdf = new \TCPDF('P', 'pt', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetFont('helvetica', '', 8);

        $pdf->AddPage('P');
        $pdf->Rect(100, 200, 395, 300, 'F', [], [200, 200, 200]);

        foreach ([[0, 3, 2], [3, 5, 3]] as [$desde, $hasta, $pag]) {
            $pdf->AddPage('L');
            $y = 40;
            foreach (["Fecha y Hora de Emisión: 03/07/2026 12:20:15 PM Pág. $pag/4", 'FLOTA VEHICULAR DE TRANSPORTE DE CARGA',
                      'REGISTRO DE OPERADORAS DE TRANSPORTE DE CARGA (ROTC)',
                      'Operadora: CONSTRUCTORA VIDALSA 27, C.A (J-29387719-9) Número de ROTC: 49199 Fecha de vencimiento: 03/07/2027',
                      '# Placa Marca Modelo Año Tipo de Vehículo N° de Ejes Capacidad de Carga Serial Carrocería Vencimiento'] as $l) {
                $pdf->Text(40, $y, $l);
                $y += 16;
            }
            for ($i = $desde; $i < $hasta; $i++) {
                $v = $this->flota[$i];
                $pdf->Text(40, $y, ($i + 1) . " {$v['placa']} SINOTRUK ZZ4257V344JB1 2025 CAMION TRACTOR 3 17100 Ton. {$v['serial']} 03/07/2027");
                $y += 16;
            }
        }

        $pdf->AddPage('P');
        foreach (array_values($conCert) as $n => $k) {
            $v = $this->flota[$k];
            $y = 90 + $n * 190;
            foreach (['CERTIFICADO DE CIRCULACIÓN DE VEHICULO DE CARGA', 'Razón Social RIF Nro de ROTC',
                      'CONSTRUCTORA VIDALSA 27, C.A J-29387719-9 49199', 'Placa Serial de Carrocería Marca - Modelo Año',
                      "{$v['placa']} {$v['serial']} SINOTRUK - ZZ4257V344JB1 2025", 'Fecha de Emisión Fecha de Vencimiento',
                      $fechasCert[$k] ?? '03/07/2026 03/07/2027', 'CERTIFICADO PARA PRESTAR EL SERVICIO DE TRANSPORTE DE CARGA EN TODO EL TERRITORIO NACIONAL',
                      'VIGENTES (NORMAS, LEYES Y SUS REGLAMENTOS)'] as $j => $l) {
                $pdf->Text(60, $y + $j * 18, $l);
            }
            $pdf->Text(20, $y + 175, str_repeat('- ', 90));
        }

        $ruta = tempnam(sys_get_temp_dir(), 'rotc_flota_') . '.pdf';
        $pdf->Output($ruta, 'F');
        return $ruta;
    }
}
