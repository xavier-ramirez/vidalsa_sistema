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
     * Aplicar deja en cada ficha SU parte con SUS fechas; volver a aplicar no sube otra, y la
     * fila pasa a "Aplicado" con la ultima.
     */
    public function test_aplicar_enlaza_a_cada_equipo_su_parte(): void
    {
        $con = $this->equipo($this->flota[0]);
        $sin = $this->equipo($this->flota[4]);
        $p = $this->soltar($this->pdf);

        $ir = fn ($e, $cerrar) => $this->post(route('historial-documentos.carga-masiva.aplicar'), [
            'id_equipo' => $e->ID_EQUIPO, 'tipo' => 'rotc', 'link' => $p['link'],
            'vence' => $p['vence'], 'emision' => $p['emision'], 'cerrar' => $cerrar,
        ], ['Accept' => 'application/json']);

        $ir($con, 0)->assertOk();
        $ir($sin, 1)->assertOk();

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

        $ir($con, 1)->assertOk()->assertJson(['message' => 'Ya estaba enlazado.']);
    }

    /** Si la ficha ya tiene un ROTC y no se confirma el reemplazo, no queda una parte suelta en Drive. */
    public function test_sin_confirmar_el_reemplazo_no_se_sube_nada(): void
    {
        $e = $this->equipo($this->flota[0], ['LINK_ROTC' => '/storage/google/rotc-viejo', 'FECHA_ROTC' => '2026-05-30']);
        $p = $this->soltar($this->pdf);
        $drive = GoogleDriveService::getInstance();
        $antes = $drive->subidos();

        $this->post(route('historial-documentos.carga-masiva.aplicar'), [
            'id_equipo' => $e->ID_EQUIPO, 'tipo' => 'rotc', 'link' => $p['link'], 'vence' => $p['vence'], 'cerrar' => 1,
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJson(['requiere_pisar' => true]);

        $this->assertSame($antes, $drive->subidos(), 'la parte se arma solo si va a entrar');
        $this->assertSame('/storage/google/rotc-viejo', Documentacion::where('ID_EQUIPO', $e->ID_EQUIPO)->value('LINK_ROTC'));
    }

    // ── Ayudas ───────────────────────────────────────────────────────────────────

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
            ->analizar(new UploadedFile($copia, 'rotc_flota.pdf', 'application/pdf', null, true));
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
     */
    private function fabricarRotc(): string
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
        foreach (array_slice($this->flota, 0, 3) as $k => $v) {
            $y = 90 + $k * 190;
            foreach (['CERTIFICADO DE CIRCULACIÓN DE VEHICULO DE CARGA', 'Razón Social RIF Nro de ROTC',
                      'CONSTRUCTORA VIDALSA 27, C.A J-29387719-9 49199', 'Placa Serial de Carrocería Marca - Modelo Año',
                      "{$v['placa']} {$v['serial']} SINOTRUK - ZZ4257V344JB1 2025", 'Fecha de Emisión Fecha de Vencimiento',
                      '03/07/2026 03/07/2027', 'CERTIFICADO PARA PRESTAR EL SERVICIO DE TRANSPORTE DE CARGA EN TODO EL TERRITORIO NACIONAL',
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
