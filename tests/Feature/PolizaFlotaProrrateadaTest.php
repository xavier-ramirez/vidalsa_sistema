<?php

namespace Tests\Feature;

use App\Jobs\DeleteGoogleDriveFile;
use App\Models\Documentacion;
use App\Models\Equipo;
use App\Models\Usuario;
use App\Models\VerificacionDocumento;
use App\Services\CargaMasivaDocumentos;
use App\Services\CorrectorFichaDocumento;
use App\Services\LectorDocumentoPdf;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Polizas de flota PRORRATEADAS (auditoria del 07-10-2026): la de Piramide AUTF-001001-1601
 * vence para toda la flota el 09/06/2027, y cada equipo que entra se asegura desde ese dia
 * hasta esa fecha, no un año. 45 fichas tenian "emision + 1 año" y la revision nocturna no las
 * corregia: el PDF vencia mas de DIAS_ANTERIOR antes que la ficha y se tomaba por el ANTERIOR.
 *
 * La regla: un PDF que EMPIEZA cuando la ficha (o despues) no es el anterior, aunque venza
 * antes; es el vigente, mas corto. Sin la emision de la ficha o el inicio del PDF se decide
 * como siempre, solo por el vencimiento.
 */
class PolizaFlotaProrrateadaTest extends MySqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([DeleteGoogleDriveFile::class]);
    }

    /** El cuadro de Piramide tal como lo saca el OCR (rotulo y fechas en lineas distintas). */
    private function cuadroProrrateado(string $placa, string $serial, string $desde): string
    {
        return "Pirámide SEGUROS\nAUTOMÓVIL FLOTAS (INDIVIDUAL POR CERTIFICADO)\n"
            . "VIGENCIA DEL SEGURO: \n$desde al 09/06/2027 \n"
            . "PLACA: $placa \nSERIAL CARROCERIA: $serial \n"
            . "VIGENCIA DEL RECIBO: $desde al 09/12/2026 \n";
    }

    private function lectorFalso(string $texto): void
    {
        $this->app->bind(LectorDocumentoPdf::class, fn () => new class ($texto) extends LectorDocumentoPdf {
            public function __construct(private string $textoFalso) {}

            public function texto(string $driveId): string
            {
                return $this->textoFalso;
            }
        });
    }

    /** Equipo con su poliza enlazada; la transaccion del caso lo revierte. Devuelve [id, placa, serial]. */
    private function equipoConPoliza(array $ficha): array
    {
        $letra = fn () => chr(random_int(65, 90));
        $placa = $letra() . random_int(10, 99) . $letra() . $letra() . random_int(0, 9) . $letra();
        $serial = 'LA9B23GE' . random_int(1, 9) . 'H1GH' . strtoupper(substr(uniqid(), -4));
        $id = DB::table('equipos')->insertGetId(['MARCA' => 'PRUEBA', 'MODELO' => 'FLOTA', 'ANIO' => 2017, 'SERIAL_CHASIS' => $serial]);
        DB::table('documentacion')->insert($ficha + [
            'ID_EQUIPO' => $id, 'PLACA' => $placa, 'ID_SEGURO' => 3,
            'LINK_POLIZA_SEGURO' => '/storage/google/driveFLOTA' . strtoupper(uniqid()) . '?v=1',
        ]);
        return [$id, $placa, $serial];
    }

    private function venceEnFicha(int $id): string
    {
        return substr((string) DB::table('documentacion')->where('ID_EQUIPO', $id)->value('FECHA_VENC_POLIZA'), 0, 10);
    }

    public function test_la_regla_distingue_el_prorrateado_del_anterior(): void
    {
        // Empieza el mismo dia que la ficha: es el vigente aunque venza 56 dias antes.
        $this->assertNull(VerificacionDocumento::documentoAnterior('2027-08-04', '2027-06-09', '2026-08-04', '2026-08-04'));
        // Empieza DESPUES (entro a la flota mas tarde): tambien el vigente.
        $this->assertNull(VerificacionDocumento::documentoAnterior('2027-08-04', '2027-06-09', '2026-08-04 00:00:00', '2026-08-31'));
        // Empieza ANTES que la ficha: es el del periodo pasado.
        $this->assertNotNull(VerificacionDocumento::documentoAnterior('2027-08-04', '2026-08-04', '2026-08-04', '2025-08-04'));
        // Sin la emision de la ficha o sin el inicio del PDF: como siempre, por el vencimiento.
        $this->assertNotNull(VerificacionDocumento::documentoAnterior('2027-08-04', '2027-06-09', null, '2026-08-04'));
        $this->assertNotNull(VerificacionDocumento::documentoAnterior('2027-08-04', '2027-06-09', '2026-08-04', null));

        $this->assertSame('2026-08-04', VerificacionDocumento::inicioDelDocumento(['desde' => '2026-08-04', 'emision' => '2023-06-22']));
        $this->assertSame('2023-06-22', VerificacionDocumento::inicioDelDocumento(['desde' => null, 'emision' => '2023-06-22']));
        $this->assertNull(VerificacionDocumento::inicioDelDocumento(null));
    }

    public function test_la_revision_nocturna_corrige_el_vencimiento_de_la_poliza_prorrateada(): void
    {
        // Caso real: ficha 20 (A88EZ4A), emision 04/08/2026 y vence 04/08/2027; el PDF dice 09/06/2027.
        [$id, $placa, $serial] = $this->equipoConPoliza(['FECHA_EMISION_POLIZA' => '2026-08-04', 'FECHA_VENC_POLIZA' => '2027-08-04']);
        $this->lectorFalso($this->cuadroProrrateado($placa, $serial, '04/08/2026'));

        $this->artisan('docs:verificar-documentos', ['--equipo' => $id, '--tipo' => 'poliza', '--sin-ia' => true])->assertSuccessful();

        $reg = VerificacionDocumento::where('ID_EQUIPO', $id)->where('TIPO', 'poliza')->firstOrFail();
        $this->assertFalse($reg->esDocumentoAnterior(), 'Empieza cuando la ficha: no es el PDF anterior.');
        $this->assertSame('2027-06-09', $reg->LEIDO['vence']);
        $this->assertSame('2027-06-09', $this->venceEnFicha($id), 'Manda el documento: la ficha pasa al 09/06/2027.');
    }

    public function test_el_pdf_de_un_periodo_pasado_sigue_siendo_el_anterior(): void
    {
        // La ficha ya renovada (2026-2027) y enlazado el cuadro de 2025: no se toca nada.
        [$id, $placa, $serial] = $this->equipoConPoliza(['FECHA_EMISION_POLIZA' => '2026-08-04', 'FECHA_VENC_POLIZA' => '2027-08-04']);
        $this->lectorFalso("Pirámide SEGUROS\nVIGENCIA DEL SEGURO: \n04/08/2025 al 04/08/2026 \nPLACA: $placa \nSERIAL CARROCERIA: $serial \n");

        $this->artisan('docs:verificar-documentos', ['--equipo' => $id, '--tipo' => 'poliza', '--sin-ia' => true])->assertSuccessful();

        $reg = VerificacionDocumento::where('ID_EQUIPO', $id)->where('TIPO', 'poliza')->firstOrFail();
        $this->assertTrue($reg->esDocumentoAnterior());
        $this->assertSame('2027-08-04', $this->venceEnFicha($id));
    }

    public function test_una_lectura_guardada_del_prorrateado_se_aplica(): void
    {
        // Las filas que la noche marco antes de esta regla: al aplicarlas ya no se descartan.
        [$id, $placa] = $this->equipoConPoliza(['FECHA_EMISION_POLIZA' => '2026-08-04', 'FECHA_VENC_POLIZA' => '2027-08-04']);
        $reg = VerificacionDocumento::create([
            'ID_EQUIPO' => $id, 'TIPO' => VerificacionDocumento::POLIZA, 'PLACA' => $placa, 'DRIVE_ID' => 'driveFLOTA',
            'LEIDO' => ['desde' => '2026-08-04', 'emision' => '2026-08-04', 'vence' => '2027-06-09', 'placa' => $placa],
            'ESTADO' => VerificacionDocumento::DIFIERE, 'A_MANO' => false,
            'DIFERENCIAS' => ['FECHA_VENC_POLIZA' => ['etiqueta' => 'Vencimiento', 'ficha' => '2027-08-04', 'documento' => '2027-06-09']],
        ]);

        $r = app(CorrectorFichaDocumento::class)->aplicar($reg->refresh());

        $this->assertContains('Vencimiento', $r['puestos']);
        $this->assertSame('2027-06-09', $this->venceEnFicha($id));
    }

    public function test_la_carga_masiva_acepta_el_prorrateado_y_rechaza_el_anterior(): void
    {
        $u = Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->whereNotNull('PERMISOS')->get()
            ->first(fn ($usr) => in_array('super.admin', array_map('strtolower', $usr->PERMISOS), true));
        $this->assertNotNull($u, 'hace falta un super.admin activo');
        $this->actingAs($u);
        $equipo = Equipo::create(['MARCA' => 'PRUEBA', 'MODELO' => 'FLOTA', 'ANIO' => 2017, 'SERIAL_CHASIS' => 'TESTPR' . strtoupper(uniqid())]);
        Documentacion::create(['ID_EQUIPO' => $equipo->ID_EQUIPO, 'LINK_POLIZA_SEGURO' => '/storage/google/el-de-un-año',
                               'FECHA_EMISION_POLIZA' => '2026-08-04', 'FECHA_VENC_POLIZA' => '2027-08-04']);
        $servicio = app(CargaMasivaDocumentos::class);

        $viejo = $servicio->aplicar($equipo->ID_EQUIPO, 'poliza', '/storage/google/el-de-2025', '2026-08-04', '2025-08-04', true);
        $this->assertFalse($viejo['ok']);
        $this->assertStringContainsString('ANTERIOR', $viejo['mensaje']);

        $r = $servicio->aplicar($equipo->ID_EQUIPO, 'poliza', '/storage/google/el-prorrateado', '2027-06-09', '2026-08-04', true);
        $this->assertTrue($r['ok'], $r['mensaje']);
        $this->assertSame('2027-06-09', $this->venceEnFicha($equipo->ID_EQUIPO));
    }
}
